@extends('layouts.admin')

@section('admin-content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <h2 style="margin:0; font-size: 24px;">Audit Trail</h2>
</div>

<div class="Box">
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Model</th><th>IP</th></tr></thead>
            <tbody>
            @forelse($auditLogs as $entry)
                <tr>
                    <td>{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $entry->user->name ?? 'System' }}</td>
                    <td>{{ strtoupper($entry->action) }}</td>
                    <td>{{ class_basename($entry->model_type) }} #{{ $entry->model_id }}</td>
                    <td>{{ $entry->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">No audit entries found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $auditLogs->links() }}</div>
@endsection
