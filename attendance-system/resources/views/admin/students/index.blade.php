@extends('layouts.admin')

@section('admin-content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <h2 style="margin:0; font-size: 24px;">Students</h2>
    <a href="{{ route('admin.students.create') }}" class="btn btn-primary">Add Student</a>
</div>

<div class="Box">
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Student ID</th><th>Name</th><th>Email</th><th>Department</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($students as $student)
                <tr>
                    <td>{{ $student->student_id }}</td>
                    <td>{{ $student->full_name }}</td>
                    <td>{{ $student->email }}</td>
                    <td>{{ $student->department ?? 'N/A' }}</td>
                    <td>
                        <span class="State {{ $student->is_active ? 'State--green' : 'State--red' }}">{{ $student->is_active ? 'Active' : 'Inactive' }}</span>
                    </td>
                    <td class="d-flex" style="gap:8px;">
                        <a href="{{ route('admin.students.show', $student) }}" class="btn">View</a>
                        <a href="{{ route('admin.students.edit', $student) }}" class="btn">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No students found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $students->links() }}</div>
@endsection
