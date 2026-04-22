<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminAttendanceController extends Controller
{
	public function __construct(protected AttendanceService $attendanceService)
	{
	}

	public function index(Request $request): View
	{
		$date = $request->query('date', now()->toDateString());

		$students = Student::query()
			->where('is_active', true)
			->orderBy('first_name')
			->orderBy('last_name')
			->get();

		$attendanceLogs = AttendanceLog::query()
			->whereDate('date', $date)
			->whereIn('type', ['in', 'out'])
			->get()
			->groupBy('student_id');

		$suspiciousIPs = $this->attendanceService->detectSuspiciousIPs();

		return view('admin.attendance.index', [
			'date' => $date,
			'students' => $students,
			'attendance' => $attendanceLogs,
			'suspiciousIPs' => $suspiciousIPs,
		]);
	}

	public function show(Request $request, int $studentId): View
	{
		$date = $request->query('date', now()->toDateString());
		$student = Student::query()->findOrFail($studentId);

		$attendanceLogs = AttendanceLog::query()
			->where('student_id', $student->id)
			->whereDate('date', $date)
			->orderBy('recorded_time')
			->get();

		return view('admin.attendance.show', [
			'student' => $student,
			'date' => Carbon::parse($date)->toDateString(),
			'attendanceLogs' => $attendanceLogs,
		]);
	}

	public function override(Request $request, int $logId): RedirectResponse
	{
		$validated = $request->validate([
			'new_time' => 'required|date',
			'reason' => 'required|string|min:10|max:500',
		]);

		try {
			$this->attendanceService->adminOverride(
				$logId,
				$validated['new_time'],
				$validated['reason']
			);

			return back()->with('success', 'Attendance override saved successfully.');
		} catch (\Throwable $e) {
			return back()->with('error', $e->getMessage());
		}
	}

	public function auditLog(): View
	{
		$auditLogs = AuditTrail::query()
			->with('user')
			->orderByDesc('created_at')
			->paginate(30);

		return view('admin.attendance.audit-log', [
			'auditLogs' => $auditLogs,
		]);
	}
}
