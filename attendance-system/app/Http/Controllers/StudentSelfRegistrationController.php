<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
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
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'parent_name' => 'required|string|max:255',
            'father_name' => 'required|string|max:255',
            'mother_name' => 'required|string|max:255',
            'address' => 'required|string|max:1000',
            'email' => 'required|email|unique:students,email',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:255',
            'photo' => 'required|image|max:2048',
        ]);

        if ($this->fullNameExists($validated['first_name'], $validated['last_name'])) {
            return back()
                ->withInput()
                ->withErrors([
                    'first_name' => 'A student with the same first and last name is already registered.',
                ]);
        }

        $studentId = $this->generateStudentId();

        $photoPath = $request->file('photo')->store('students', 'public');

        Student::create([
            'student_id' => $studentId,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'parent_name' => $validated['parent_name'],
            'father_name' => $validated['father_name'],
            'mother_name' => $validated['mother_name'],
            'address' => $validated['address'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'department' => $validated['department'] ?? null,
            'photo_path' => $photoPath,
            'is_active' => true,
        ]);

        return redirect()->route('attendance.kiosk')
            ->with('success', 'Registration complete. Your student ID is ' . $studentId . '. You can now use attendance kiosk.');
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
}
