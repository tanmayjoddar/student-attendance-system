<?php

namespace App\Services;

use App\Exceptions\DuplicateAttendanceException;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /**
     * Process check-in for a student
     */
    public function checkIn(Student $student, ?string $statedTime = null, ?array $verification = null): AttendanceLog
    {
        $today = Carbon::today();
        $now = Carbon::now();

        $this->validateFaceVerification($student, $verification);

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
        return DB::transaction(function () use ($student, $today, $now, $parsedStated, $verification) {
            $log = AttendanceLog::create([
                'student_id'    => $student->id,
                'date'          => $today,
                'type'          => 'in',
                'recorded_time' => $now,
                'stated_time'   => $parsedStated,
                'ip_address'    => request()->ip(),
                'is_flagged'    => false,
                'face_verified' => true,
                'liveness_score' => isset($verification['liveness_score']) ? (float) $verification['liveness_score'] : null,
                'verification_meta' => [
                    'match_score' => $verification['match_score'] ?? null,
                    'spoof_passed' => (bool) ($verification['spoof_passed'] ?? false),
                    'blink_count' => $verification['blink_count'] ?? null,
                    'yaw_variance' => $verification['yaw_variance'] ?? null,
                    'verified_at' => Carbon::now()->toIso8601String(),
                ],
                'submitted_by'  => $this->resolveSubmittedByUserId(),
            ]);

            $this->createAuditTrail('create', $log);

            return $log;
        });
    }

    /**
     * Process check-out for a student
     */
    public function checkOut(Student $student, ?array $verification = null): AttendanceLog
    {
        $today = Carbon::today();
        $now = Carbon::now();

        $this->validateFaceVerification($student, $verification);

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

        return DB::transaction(function () use ($student, $today, $now, $verification) {
            $log = AttendanceLog::create([
                'student_id'    => $student->id,
                'date'          => $today,
                'type'          => 'out',
                'recorded_time' => $now,
                'stated_time'   => null,
                'ip_address'    => request()->ip(),
                'is_flagged'    => false,
                'face_verified' => true,
                'liveness_score' => isset($verification['liveness_score']) ? (float) $verification['liveness_score'] : null,
                'verification_meta' => [
                    'match_score' => $verification['match_score'] ?? null,
                    'spoof_passed' => (bool) ($verification['spoof_passed'] ?? false),
                    'blink_count' => $verification['blink_count'] ?? null,
                    'yaw_variance' => $verification['yaw_variance'] ?? null,
                    'verified_at' => Carbon::now()->toIso8601String(),
                ],
                'submitted_by'  => $this->resolveSubmittedByUserId(),
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
     * Identify IPs with unusually high check-ins in a short time window.
     */
    public function detectSuspiciousIPs(): array
    {
        $threshold = (int) config('attendance.rate_limit.max_attempts', 5);

        $logs = AttendanceLog::where('type', 'in')
            ->orderBy('ip_address')
            ->orderBy('recorded_time')
            ->get();

        $result = [];

        foreach ($logs->groupBy('ip_address') as $ip => $ipLogs) {
            foreach ($ipLogs as $log) {
                $window = $ipLogs->filter(function ($candidate) use ($log) {
                    return abs($candidate->recorded_time->diffInSeconds($log->recorded_time)) <= 120;
                });

                if ($window->count() >= $threshold) {
                    $result[$ip] = $window->pluck('id')->values()->all();
                    break;
                }
            }
        }

        return $result;
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
        if ($parsed->gt($now->copy()->addMinutes($gracePeriod))) {
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

    /**
     * Resolve submitter user ID for kiosk mode where students do not authenticate.
     */
    protected function resolveSubmittedByUserId(): int
    {
        $authId = auth()->id();
        if ($authId) {
            return (int) $authId;
        }

        $adminId = User::where('role', 'super_admin')->value('id');
        if ($adminId) {
            return (int) $adminId;
        }

        throw new \RuntimeException('No super admin user found. Please seed admin first.');
    }

    /**
     * Enforce biometric verification and liveness signals before attendance actions.
     */
    protected function validateFaceVerification(Student $student, ?array $verification): void
    {
        if (!$verification || !($verification['face_verified'] ?? false)) {
            throw new \InvalidArgumentException('Face verification is required before attendance.');
        }

        if (!($verification['spoof_passed'] ?? false)) {
            throw new \InvalidArgumentException('Spoof check failed. Please complete live blink and head movement challenge.');
        }

        // 80 points are stored as x,y pairs => 160 numeric values.
        if (!is_array($student->face_signature) || count($student->face_signature) < 140) {
            throw new \InvalidArgumentException('No registered face vector found for this student. Please re-register using a clear face photo.');
        }

        $minLiveness = (float) config('attendance.face.min_liveness_score', 70);
        $minMatch = (float) config('attendance.face.min_match_score', 82);

        $livenessScore = isset($verification['liveness_score']) ? (float) $verification['liveness_score'] : 0;
        $matchScore = isset($verification['match_score']) ? (float) $verification['match_score'] : 0;

        if ($livenessScore < $minLiveness) {
            throw new \InvalidArgumentException('Liveness check failed. Please blink and turn your head slightly, then retry.');
        }

        if ($matchScore < $minMatch) {
            throw new \InvalidArgumentException('Face match failed. Please align with camera and retry verification.');
        }
    }
}
