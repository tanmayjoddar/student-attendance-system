@extends('layouts.admin')

@section('admin-content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <h2 style="margin:0; font-size: 24px;">Daily Attendance</h2>
    <form method="GET" class="d-flex" style="gap:8px; align-items:center;">
        <input class="form-control" type="date" name="date" value="{{ $date }}">
        <button class="btn" type="submit">Filter</button>
    </form>
</div>

@if(!empty($suspiciousIPs))
<div class="flash-error mb-4">
    Suspicious IP activity detected: {{ implode(', ', array_keys($suspiciousIPs)) }}
</div>
@endif

<div class="Box">
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Student</th><th>Date</th><th>In</th><th>Out</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($students as $student)
                @php
                    $logs = $attendance[$student->id] ?? collect();
                    $in = $logs->firstWhere('type', 'in');
                    $out = $logs->firstWhere('type', 'out');
                @endphp
                <tr>
                    <td>{{ $student->full_name }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</td>
                    <td>{{ $in ? $in->recorded_time->format('H:i:s') : 'N/A' }}</td>
                    <td>{{ $out ? $out->recorded_time->format('H:i:s') : 'N/A' }}</td>
                    <td>
                        @if($in && $out)
                            <span class="State State--green">Complete</span>
                        @elseif($in)
                            <span class="State State--yellow">Half-day</span>
                        @else
                            <span class="State State--red">Absent</span>
                        @endif
                    </td>
                    <td><a href="{{ route('admin.attendance.show', $student->id) }}?date={{ $date }}" class="btn">Details</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No students found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
