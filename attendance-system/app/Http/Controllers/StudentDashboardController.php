<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;

class StudentDashboardController extends Controller
{
    protected AttendanceService $attendanceService;

    public function __construct(AttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
        $this->middleware('auth')->except([
            'kiosk',
            'checkInPublic',
            'checkOutPublic',
            'registerFace',
            'serverTime',
        ]);
    }

    public function kiosk()
    {
        $students = Student::query()
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return view('attendance.kiosk', compact('students'));
    }

    public function index()
    {
        $student = auth()->user()->student;

        if (!$student) {
            abort(403, 'No student profile found');
        }

        // Get today's status
        $todayStatus = $this->attendanceService->getTodayStatus($student);

        // Get heatmap data for current year
        $currentYear = Carbon::now()->year;
        $heatmapData = $this->attendanceService->getHeatmapData($student, $currentYear);

        // Get recent attendance
        $recentAttendance = $student->attendanceLogs()
            ->with('submittedBy')
            ->orderBy('recorded_time', 'desc')
            ->take(10)
            ->get();

        return view('student.dashboard', compact(
            'student',
            'todayStatus',
            'heatmapData',
            'recentAttendance',
            'currentYear'
        ));
    }

    public function checkIn(Request $request)
    {
        $student = auth()->user()->student;

        if (!$student) {
            abort(403, 'No student profile found');
        }

        try {
            $verification = $request->validate([
                'face_verified' => 'required|boolean',
                'liveness_score' => 'required|numeric|min:0|max:100',
                'match_score' => 'nullable|numeric|min:0|max:100',
                'blink_count' => 'nullable|integer|min:0',
                'yaw_variance' => 'nullable|numeric|min:0',
            ]);

            $log = $this->attendanceService->checkIn(
                $student,
                $request->input('stated_time'),
                $verification
            );

            return redirect()
                ->route('student.dashboard')
                ->with('success', 'Check-in recorded successfully at ' . $log->recorded_time->format('h:i A'));
        } catch (\Exception $e) {
            return back()
                ->with('error', $e->getMessage());
        }
    }

    public function checkOut(Request $request)
    {
        $student = auth()->user()->student;

        if (!$student) {
            abort(403, 'No student profile found');
        }

        try {
            $verification = $request->validate([
                'face_verified' => 'required|boolean',
                'liveness_score' => 'required|numeric|min:0|max:100',
                'match_score' => 'nullable|numeric|min:0|max:100',
                'blink_count' => 'nullable|integer|min:0',
                'yaw_variance' => 'nullable|numeric|min:0',
            ]);

            $log = $this->attendanceService->checkOut($student, $verification);

            return redirect()
                ->route('student.dashboard')
                ->with('success', 'Check-out recorded successfully at ' . $log->recorded_time->format('h:i A'));
        } catch (\Exception $e) {
            return back()
                ->with('error', $e->getMessage());
        }
    }

    public function checkInPublic(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|string|exists:students,student_id',
            'stated_time' => 'nullable|date',
            'face_verified' => 'required|boolean',
            'liveness_score' => 'required|numeric|min:0|max:100',
            'match_score' => 'nullable|numeric|min:0|max:100',
            'blink_count' => 'nullable|integer|min:0',
            'yaw_variance' => 'nullable|numeric|min:0',
        ]);

        $student = Student::where('student_id', $validated['student_id'])->firstOrFail();

        try {
            $this->attendanceService->checkIn(
                $student,
                $validated['stated_time'] ?? null,
                [
                    'face_verified' => (bool) $validated['face_verified'],
                    'liveness_score' => (float) $validated['liveness_score'],
                    'match_score' => $validated['match_score'] ?? null,
                    'blink_count' => $validated['blink_count'] ?? null,
                    'yaw_variance' => $validated['yaw_variance'] ?? null,
                ]
            );

            return redirect()->route('attendance.kiosk')->with('success', 'Check-in recorded successfully.');
        } catch (\Exception $e) {
            return redirect()->route('attendance.kiosk')->with('error', $e->getMessage())->withInput();
        }
    }

    public function checkOutPublic(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|string|exists:students,student_id',
            'face_verified' => 'required|boolean',
            'liveness_score' => 'required|numeric|min:0|max:100',
            'match_score' => 'nullable|numeric|min:0|max:100',
            'blink_count' => 'nullable|integer|min:0',
            'yaw_variance' => 'nullable|numeric|min:0',
        ]);

        $student = Student::where('student_id', $validated['student_id'])->firstOrFail();

        try {
            $this->attendanceService->checkOut(
                $student,
                [
                    'face_verified' => (bool) $validated['face_verified'],
                    'liveness_score' => (float) $validated['liveness_score'],
                    'match_score' => $validated['match_score'] ?? null,
                    'blink_count' => $validated['blink_count'] ?? null,
                    'yaw_variance' => $validated['yaw_variance'] ?? null,
                ]
            );

            return redirect()->route('attendance.kiosk')->with('success', 'Check-out recorded successfully.');
        } catch (\Exception $e) {
            return redirect()->route('attendance.kiosk')->with('error', $e->getMessage())->withInput();
        }
    }

    public function registerFace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'signature' => 'required|array|min:10',
            'signature.*' => 'numeric',
            'student_id' => 'nullable|string|exists:students,student_id',
        ]);

        $student = null;

        if (auth()->check() && auth()->user()->student) {
            $student = auth()->user()->student;
        } elseif (!empty($validated['student_id'])) {
            $student = Student::where('student_id', $validated['student_id'])->first();
        }

        if (!$student) {
            return response()->json([
                'ok' => false,
                'message' => 'Student not found for face registration.',
            ], 422);
        }

        $student->update([
            'face_signature' => array_values($validated['signature']),
            'face_registered_at' => Carbon::now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Face profile registered successfully.',
        ]);
    }

    public function serverTime(): JsonResponse
    {
        return response()->json([
            'time' => now()->format('H:i:s'),
            'iso' => now()->toIso8601String(),
        ]);
    }
}
