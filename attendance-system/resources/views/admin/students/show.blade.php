@extends('layouts.admin')

@section('admin-content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <h2 style="margin:0; font-size: 24px;">{{ $student->full_name }}</h2>
    <div class="d-flex" style="gap:8px;">
        <a href="{{ route('admin.students.edit', $student) }}" class="btn">Edit</a>
        <a href="{{ route('admin.students.index') }}" class="btn">Back</a>
    </div>
</div>

<div class="Box mb-4">
    <div class="Box-body">
        <div class="d-flex" style="gap:24px; flex-wrap:wrap;">
            <div><span class="text-muted">Student ID</span><br><span class="text-bold">{{ $student->student_id }}</span></div>
            <div><span class="text-muted">Email</span><br><span class="text-bold">{{ $student->email }}</span></div>
            <div><span class="text-muted">Department</span><br><span class="text-bold">{{ $student->department ?? 'N/A' }}</span></div>
            <div><span class="text-muted">Parent Name</span><br><span class="text-bold">{{ $student->parent_name ?? 'N/A' }}</span></div>
            <div><span class="text-muted">Father Name</span><br><span class="text-bold">{{ $student->father_name ?? 'N/A' }}</span></div>
            <div><span class="text-muted">Mother Name</span><br><span class="text-bold">{{ $student->mother_name ?? 'N/A' }}</span></div>
            <div style="max-width:420px;"><span class="text-muted">Address</span><br><span class="text-bold">{{ $student->address ?? 'N/A' }}</span></div>
            <div><span class="text-muted">Face Profile</span><br>
                @if($student->face_registered_at)
                    <span class="State State--green">Registered {{ $student->face_registered_at->diffForHumans() }}</span>
                @else
                    <span class="State State--red">Not Registered</span>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <h3 style="font-size:18px;">Attendance Heatmap ({{ now()->year }})</h3>
    <x-attendance-heatmap :heatmapData="app(\App\Services\AttendanceService::class)->getHeatmapData($student, now()->year)" :year="now()->year" />
</div>

<div class="Box">
    <div class="Box-header"><h2>Recent Logs</h2></div>
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Date</th><th>Type</th><th>Recorded</th><th>Stated</th><th>Face</th><th>Liveness</th></tr></thead>
            <tbody>
            @forelse($student->attendanceLogs as $log)
                <tr>
                    <td>{{ $log->date->format('d M Y') }}</td>
                    <td>{{ strtoupper($log->type) }}</td>
                    <td>{{ $log->recorded_time->format('H:i:s') }}</td>
                    <td>{{ $log->stated_time ? $log->stated_time->format('H:i:s') : 'N/A' }}</td>
                    <td>{{ $log->face_verified ? 'Verified' : 'N/A' }}</td>
                    <td>{{ $log->liveness_score ?? 'N/A' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No attendance logs.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
