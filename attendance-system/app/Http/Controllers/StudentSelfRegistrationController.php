<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StudentSelfRegistrationController extends Controller
{
    public function create()
    {
        return view('attendance.register-student');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'     => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'parent_name'    => 'nullable|string|max:255',
            'father_name'    => 'required|string|max:255',
            'mother_name'    => 'required|string|max:255',
            'address'        => 'required|string|max:1000',
            'email'          => 'required|email|unique:students,email',
            'phone'          => 'nullable|string|max:20',
            'department'     => 'nullable|string|max:255',
            'face_photo_data'=> 'required|string',   // base64 JPEG from webcam
            'face_signature' => 'required|string',
        ]);

        if ($this->fullNameExists($validated['first_name'], $validated['last_name'])) {
            return back()->withInput()->withErrors([
                'first_name' => 'A student with the same first and last name is already registered.',
            ]);
        }

        // Decode base64 photo and save it
        $photoData = $validated['face_photo_data'];
        // Strip data:image/jpeg;base64, prefix if present
        if (str_contains($photoData, ',')) {
            $photoData = explode(',', $photoData)[1];
        }
        $photoBytes = base64_decode($photoData);
        if (!$photoBytes) {
            return back()->withInput()->withErrors([
                'face_photo_data' => 'Invalid photo data. Please retake your photo.',
            ]);
        }

        $studentId = $this->generateStudentId();
        $filename  = 'students/' . $studentId . '.jpg';
        \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $photoBytes);
        $photoPath = $filename;

        // Parse face signature
        $faceSignature = $this->parseFaceSignature($validated['face_signature']);
        if (empty($faceSignature)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($photoPath);
            return back()->withInput()->withErrors([
                'face_photo_data' => 'Could not process face signature. Please retake your photo.',
            ]);
        }

        $student = Student::create([
            'student_id'       => $studentId,
            'first_name'       => $validated['first_name'],
            'last_name'        => $validated['last_name'],
            'parent_name'      => $validated['parent_name'] ?? null,
            'father_name'      => $validated['father_name'],
            'mother_name'      => $validated['mother_name'],
            'address'          => $validated['address'],
            'email'            => $validated['email'],
            'phone'            => $validated['phone'] ?? null,
            'department'       => $validated['department'] ?? null,
            'photo_path'       => $photoPath,
            'is_active'        => true,
            'face_signature'   => $faceSignature,
            'face_registered_at' => Carbon::now(),
        ]);

        // Register face in FastAPI ML service
        try {
            $photoFullPath = storage_path('app/public/' . $photoPath);
            \Illuminate\Support\Facades\Http::timeout(30)
                ->attach('image1', file_get_contents($photoFullPath), 'photo.jpg')
                ->attach('image2', file_get_contents($photoFullPath), 'photo.jpg')
                ->post('http://127.0.0.1:8001/register/', [
                    'user_id' => $studentId,
                ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('ML face registration failed for ' . $studentId . ': ' . $e->getMessage());
        }

        return redirect()->route('attendance.kiosk')
            ->with('success', 'Registration complete. Your student ID is ' . $studentId . '. You can now use the attendance kiosk.');
    }

    protected function generateStudentId(): string
    {
        do {
            $candidate = 'STU-' . now()->format('ymd') . '-' . Str::upper(Str::random(4));
        } while (Student::where('student_id', $candidate)->exists());

        return $candidate;
    }

    protected function fullNameExists(string $firstName, string $lastName): bool
    {
        return Student::query()
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower(trim($firstName))])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower(trim($lastName))])
            ->exists();
    }

    protected function parseFaceSignature(string $payload): array
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded) || count($decoded) < 140) {
            return [];
        }

        $normalized = array_map(static function ($item): float|null {
            if (!is_numeric($item)) {
                return null;
            }

            return (float) $item;
        }, $decoded);

        if (in_array(null, $normalized, true)) {
            return [];
        }

        return array_values($normalized);
    }
}
