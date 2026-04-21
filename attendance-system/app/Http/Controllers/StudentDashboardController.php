<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StudentDashboardController extends Controller
{
    protected AttendanceService $attendanceService;

    public function __construct(AttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
        $this->middleware('auth');
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
            $log = $this->attendanceService->checkIn(
                $student,
                $request->input('stated_time')
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
            $log = $this->attendanceService->checkOut($student);

            return redirect()
                ->route('student.dashboard')
                ->with('success', 'Check-out recorded successfully at ' . $log->recorded_time->format('h:i A'));
        } catch (\Exception $e) {
            return back()
                ->with('error', $e->getMessage());
        }
    }
}
