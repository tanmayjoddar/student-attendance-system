<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminStudentController extends Controller
{
    public function index()
    {
        $students = Student::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.students.index', compact('students'));
    }

    public function create()
    {
        return view('admin.students.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|unique:students,student_id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:255',
            'semester' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($validated) {
            // Create student
            $student = Student::create([
                'student_id' => $validated['student_id'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'department' => $validated['department'] ?? null,
                'semester' => $validated['semester'] ?? null,
            ]);

            // Create user account
            User::create([
                'name' => $student->full_name,
                'email' => $validated['email'],
                'password' => Hash::make('student123'), // Default password
                'role' => 'student',
                'student_id' => $student->id,
            ]);
        });

        return redirect()
            ->route('admin.students.index')
            ->with('success', 'Student created successfully. Default password: student123');
    }

    public function show(Student $student)
    {
        $student->load(['user', 'attendanceLogs' => function ($query) {
            $query->orderBy('recorded_time', 'desc')->take(20);
        }]);

        return view('admin.students.show', compact('student'));
    }

    public function edit(Student $student)
    {
        return view('admin.students.edit', compact('student'));
    }

    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:255',
            'semester' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $student->update($validated);

        // Update user name if changed
        if ($student->user) {
            $student->user->update([
                'name' => $student->full_name,
            ]);
        }

        return redirect()
            ->route('admin.students.index')
            ->with('success', 'Student updated successfully');
    }

    public function destroy(Student $student): \Illuminate\Http\RedirectResponse
    {
        $studentId = $student->student_id;

        // 1. Delete face embedding from FastAPI
        try {
            \Illuminate\Support\Facades\Http::timeout(10)
                ->delete("http://127.0.0.1:8001/delete/{$studentId}");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('ML delete failed for ' . $studentId . ': ' . $e->getMessage());
        }

        // 2. Delete photo file from storage
        if ($student->photo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($student->photo_path);
        }

        // 3. Delete attendance logs
        $student->attendanceLogs()->delete();

        // 4. Delete associated user account
        if ($student->user) {
            $student->user->delete();
        }

        // 5. Delete student record
        $student->delete();

        return redirect()->route('admin.students.index')
            ->with('success', "Student {$studentId} and all related data deleted successfully.");
    }
}
