<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $table = 'attendance_logs';

    protected $fillable = [
        'student_id',
        'date',
        'type',
        'recorded_time',
        'stated_time',
        'ip_address',
        'is_flagged',
        'submitted_by',
    ];

    protected $casts = [
        'date' => 'date',
        'recorded_time' => 'datetime',
        'stated_time' => 'datetime',
        'is_flagged' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isCheckIn(): bool
    {
        return $this->type === 'in';
    }

    public function isCheckOut(): bool
    {
        return $this->type === 'out';
    }
}
