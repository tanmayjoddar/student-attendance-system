@extends('layouts.admin')

@section('admin-content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <h2 style="margin:0; font-size: 24px;">{{ $student->full_name }} - Attendance</h2>
    <a href="{{ route('admin.attendance') }}?date={{ $date }}" class="btn">Back</a>
</div>

<div class="Box">
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Type</th><th>Recorded</th><th>Stated</th><th>Face</th><th>Liveness</th><th>Override</th></tr></thead>
            <tbody>
            @forelse($attendanceLogs as $log)
                <tr>
                    <td>{{ strtoupper($log->type) }}</td>
                    <td>{{ $log->recorded_time->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $log->stated_time ? $log->stated_time->format('Y-m-d H:i:s') : 'N/A' }}</td>
                    <td>{{ $log->face_verified ? 'Verified' : 'N/A' }}</td>
                    <td>{{ $log->liveness_score ?? 'N/A' }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.attendance.override', $log->id) }}" class="d-flex" style="gap:8px; align-items:center;">
                            @csrf
                            <input class="form-control" type="datetime-local" name="new_time" required>
                            <input class="form-control" type="text" name="reason" minlength="10" placeholder="Reason (min 10 chars)" required>
                            <button class="btn btn-danger" type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No logs on this day.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
