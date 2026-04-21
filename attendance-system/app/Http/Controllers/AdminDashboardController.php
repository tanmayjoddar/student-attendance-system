<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\AttendanceLog;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    protected AttendanceService $attendanceService;

    public function __construct(AttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
    }

    public function index()
    {
        $today = Carbon::today();

        // Stats
        $totalStudents = Student::where('is_active', true)->count();
        $presentToday = AttendanceLog::where('date', $today)
            ->where('type', 'in')
            ->count();
        $absentToday = $totalStudents - $presentToday;

        // Recent attendance
        $recentAttendance = AttendanceLog::with(['student', 'submittedBy'])
            ->where('date', $today)
            ->orderBy('recorded_time', 'desc')
            ->take(10)
            ->get();

        // Flagged records
        $flaggedRecords = AttendanceLog::where('is_flagged', true)
            ->with('student')
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();

        return view('admin.dashboard', compact(
            'totalStudents',
            'presentToday',
            'absentToday',
            'recentAttendance',
            'flaggedRecords'
        ));
    }
}
