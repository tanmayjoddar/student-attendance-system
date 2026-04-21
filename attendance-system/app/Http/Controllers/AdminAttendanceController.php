<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminAttendanceController extends Controller
{
    protected AttendanceService $attendanceService;

    public function __construct(AttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
    }

    public function index(Request $request)
    {
        $date = $request->input('date', Carbon::today()->format('Y-m-d'));

        $attendance = AttendanceLog::with(['student', 'submittedBy'])
            ->where('date', $date)
            ->orderBy('recorded_time', 'asc')
            ->get()
            ->groupBy('student_id');

        $students = Student::where('is_active', true)
            ->orderBy('first_name')
            ->get();

        return view('admin.attendance.index', compact('attendance', 'students', 'date'));
    }

    public function show($studentId, Request $request)
    {
        $student = Student::findOrFail($studentId);

        $date = $request->input('date', Carbon::today()->format('Y-m-d'));

        $attendanceLogs = $student->attendanceLogs()
            ->with('submittedBy')
            ->where('date', $date)
            ->orderBy('recorded_time', 'asc')
            ->get();

        return view('admin.attendance.show', compact('student', 'attendanceLogs', 'date'));
    }

    public function override(Request $request, $logId)
    {
        $validated = $request->validate([
            'new_time' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $log = $this->attendanceService->adminOverride(
                $logId,
                $validated['new_time'],
                $validated['reason'] ?? null
            );

            return back()
                ->with('success', 'Attendance record overridden successfully');
        } catch (\Exception $e) {
            return back()
                ->with('error', $e->getMessage());
        }
    }

    public function auditLog(Request $request)
    {
        $auditLogs = \App\Models\AuditTrail::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return view('admin.attendance.audit-log', compact('auditLogs'));
    }
}
