<?php

namespace App\Services;

use App\Exceptions\DuplicateAttendanceException;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /**
     * Process check-in for a student
     */
    public function checkIn(Student $student, ?string $statedTime = null): AttendanceLog
    {
        $today = Carbon::today();
        $now = Carbon::now();

        // Check if already checked in today
        $existingCheckIn = AttendanceLog::where('student_id', $student->id)
            ->where('date', $today)
            ->where('type', 'in')
            ->first();

        if ($existingCheckIn) {
            throw new DuplicateAttendanceException('Already checked in today');
        }

        // Validate time window
        $windowStart = Carbon::parse(config('attendance.check_in_window.start'));
        $windowEnd = Carbon::parse(config('attendance.check_in_window.end'));

        if ($now->lt($windowStart) || $now->gt($windowEnd)) {
            throw new \InvalidArgumentException('Outside allowed check-in hours');
        }

        // Process stated time (anti-cheat)
        $parsedStated = $this->validateStatedTime($statedTime, $now);

        // Create attendance log
        return DB::transaction(function () use ($student, $today, $now, $parsedStated) {
            $log = AttendanceLog::create([
                'student_id'    => $student->id,
                'date'          => $today,
                'type'          => 'in',
                'recorded_time' => $now,
                'stated_time'   => $parsedStated,
                'ip_address'    => request()->ip(),
                'is_flagged'    => false,
                'submitted_by'  => auth()->id(),
            ]);

            $this->createAuditTrail('create', $log);

            return $log;
        });
    }

    /**
     * Process check-out for a student
     */
    public function checkOut(Student $student): AttendanceLog
    {
        $today = Carbon::today();
        $now = Carbon::now();

        // Check if checked in today
        $checkIn = AttendanceLog::where('student_id', $student->id)
            ->where('date', $today)
            ->where('type', 'in')
            ->first();

        if (!$checkIn) {
            throw new \InvalidArgumentException('No check-in found for today');
        }

        // Check if already checked out
        $existingCheckOut = AttendanceLog::where('student_id', $student->id)
            ->where('date', $today)
            ->where('type', 'out')
            ->first();

        if ($existingCheckOut) {
            throw new DuplicateAttendanceException('Already checked out today');
        }

        // Minimum duration check (anti-cheat)
        $duration = $now->diffInSeconds($checkIn->recorded_time);
        if ($duration < config('attendance.min_checkin_duration')) {
            throw new \InvalidArgumentException('Check-out too soon after check-in');
        }

        return DB::transaction(function () use ($student, $today, $now) {
            $log = AttendanceLog::create([
                'student_id'    => $student->id,
                'date'          => $today,
                'type'          => 'out',
                'recorded_time' => $now,
                'stated_time'   => null,
                'ip_address'    => request()->ip(),
                'is_flagged'    => false,
                'submitted_by'  => auth()->id(),
            ]);

            $this->createAuditTrail('create', $log);

            return $log;
        });
    }

    /**
     * Admin override - allow manual correction of attendance
     */
    public function adminOverride(int $logId, string $newTime, ?string $reason = null): AttendanceLog
    {
        $log = AttendanceLog::findOrFail($logId);
        $oldValues = $log->toArray();

        $log->update([
            'stated_time' => Carbon::parse($newTime),
            'is_flagged' => true,
        ]);

        $this->createAuditTrail('override', $log, $oldValues, ['reason' => $reason]);

        return $log;
    }

    /**
     * Get heatmap data for a student (for calendar view)
     */
    public function getHeatmapData(Student $student, int $year): array
    {
        $logs = AttendanceLog::where('student_id', $student->id)
            ->whereYear('date', $year)
            ->where('type', 'in')
            ->get()
            ->keyBy(fn($log) => $log->date->format('Y-m-d'));

        $heatmap = [];

        $startOfYear = Carbon::createFromDate($year, 1, 1);
        $endOfYear = Carbon::createFromDate($year, 12, 31);

        for ($date = $startOfYear; $date->lte($endOfYear); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $log = $logs->get($key);

            $status = $log ? ($log->isCheckIn() ? 'present' : 'absent') : 'absent';

            $heatmap[] = [
                'date'   => $key,
                'status' => $status,
                'log_id' => $log?->id,
            ];
        }

        return $heatmap;
    }

    /**
     * Get today's attendance status for a student
     */
    public function getTodayStatus(Student $student): array
    {
        $today = Carbon::today();

        $checkIn = AttendanceLog::where('student_id', $student->id)
            ->where('date', $today)
            ->where('type', 'in')
            ->first();

        $checkOut = AttendanceLog::where('student_id', $student->id)
            ->where('date', $today)
            ->where('type', 'out')
            ->first();

        return [
            'checked_in'  => (bool) $checkIn,
            'checked_out' => (bool) $checkOut,
            'check_in_time' => $checkIn?->recorded_time,
            'check_out_time' => $checkOut?->recorded_time,
        ];
    }

    /**
     * Validate stated time for anti-cheat
     */
    protected function validateStatedTime(?string $statedTime, Carbon $now): ?Carbon
    {
        if (!$statedTime) {
            return null;
        }

        $parsed = Carbon::parse($statedTime);
        $gracePeriod = config('attendance.grace_period_minutes');

        // Prevent future backdating beyond grace period
        if ($parsed->gt($now->addMinutes($gracePeriod))) {
            throw new \InvalidArgumentException('Stated time cannot be in the future');
        }

        return $parsed;
    }

    /**
     * Create audit trail entry
     */
    protected function createAuditTrail(
        string $action,
        AttendanceLog $log,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        AuditTrail::create([
            'action'     => $action,
            'model_type' => AttendanceLog::class,
            'model_id'   => $log->id,
            'old_values' => $oldValues,
            'new_values' => $newValues ?? $log->toArray(),
            'user_id'    => auth()->id(),
            'ip_address' => request()->ip(),
        ]);
    }
}
