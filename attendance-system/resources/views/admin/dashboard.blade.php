@extends('layouts.admin')

@section('admin-content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <h2 style="margin:0; font-size: 24px;">Dashboard</h2>
    <span class="text-muted">{{ now()->format('l, d M Y') }}</span>
</div>

<div class="d-flex" style="gap:16px; margin-bottom: 24px;">
    <div class="Box" style="flex:1;"><div class="Box-body"><div class="text-small text-muted">Total Students</div><div style="font-size:28px; font-weight:700;">{{ $totalStudents }}</div></div></div>
    <div class="Box" style="flex:1;"><div class="Box-body"><div class="text-small text-muted">Present Today</div><div style="font-size:28px; font-weight:700; color: var(--color-success-fg);">{{ $presentToday }}</div></div></div>
    <div class="Box" style="flex:1;"><div class="Box-body"><div class="text-small text-muted">Absent Today</div><div style="font-size:28px; font-weight:700; color: var(--color-danger-fg);">{{ $absentToday }}</div></div></div>
</div>

<div class="Box mb-4">
    <div class="Box-header"><h2>Recent Attendance</h2></div>
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Student</th><th>Type</th><th>Time</th><th>IP</th><th>Flags</th></tr></thead>
            <tbody>
            @forelse($recentAttendance as $log)
                <tr>
                    <td>{{ $log->student->full_name ?? 'N/A' }}</td>
                    <td><span class="State {{ $log->type === 'in' ? 'State--green' : 'State--yellow' }}">{{ strtoupper($log->type) }}</span></td>
                    <td>{{ $log->recorded_time->format('H:i:s') }}</td>
                    <td class="text-small text-muted">{{ $log->ip_address }}</td>
                    <td>
                        @if($log->is_flagged)<span class="State State--red">Flagged</span>@endif
                        @if($log->face_verified)<span class="State State--green">Face OK</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">No records today.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="Box">
    <div class="Box-header"><h2>Flagged Records</h2></div>
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Student</th><th>Date</th><th>Type</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse($flaggedRecords as $log)
                <tr>
                    <td>{{ $log->student->full_name ?? 'N/A' }}</td>
                    <td>{{ $log->date->format('d M Y') }}</td>
                    <td>{{ strtoupper($log->type) }}</td>
                    <td class="text-small text-muted">Stated time mismatch / admin adjustment</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">No flagged records.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
