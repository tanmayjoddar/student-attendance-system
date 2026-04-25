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
                        <button type="button"
                                class="btn btn-danger"
                                style="padding:3px 10px; font-size:12px;"
                                onclick="showDeleteModal('{{ $student->full_name }}', '{{ $student->student_id }}', '{{ route('admin.students.destroy', $student) }}')">
                            Delete
                        </button>
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

{{-- Delete Confirmation Modal --}}
<div id="deleteModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; border-radius:12px; padding:32px; max-width:500px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="text-align:center; margin-bottom:24px;">
            <div style="width:64px; height:64px; background:#ffebe9; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                <svg width="32" height="32" viewBox="0 0 16 16" fill="#cf222e">
                    <path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM4.5 7.5a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1h-7Z"/>
                </svg>
            </div>
            <h3 style="margin:0 0 8px 0; font-size:20px; color:#1f2328;">Confirm Student Deletion</h3>
            <p style="margin:0; color:#656d76; font-size:14px;">This action cannot be undone</p>
        </div>

        <div style="background:#fff8f7; border:1px solid #ffc1c0; border-radius:8px; padding:16px; margin-bottom:24px;">
            <div style="font-weight:600; color:#1f2328; margin-bottom:8px;">Student Details:</div>
            <div style="color:#656d76; font-size:14px; line-height:1.6;">
                <div><strong>Name:</strong> <span id="modalStudentName"></span></div>
                <div><strong>Student ID:</strong> <span id="modalStudentId"></span></div>
            </div>
        </div>

        <div style="background:#ffebe9; border-left:4px solid #cf222e; padding:12px 16px; border-radius:4px; margin-bottom:24px;">
            <div style="color:#a40e26; font-size:13px; line-height:1.5;">
                <strong> Warning:</strong> This will permanently delete:
                <ul style="margin:8px 0 0 0; padding-left:20px;">
                    <li>All attendance records (check-ins/check-outs)</li>
                    <li>Face recognition data and embeddings</li>
                    <li>Student photo from storage</li>
                    <li>User account and login credentials</li>
                    <li>Student profile record</li>
                </ul>
            </div>
        </div>

        <div style="display:flex; gap:12px;">
            <button onclick="closeDeleteModal()"
                    style="flex:1; padding:10px 20px; border:1px solid #d0d7de; background:white; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; color:#24292f;">
                Cancel
            </button>
            <form id="deleteForm" method="POST" style="flex:1;">
                @csrf
                @method('DELETE')
                <button type="submit"
                        style="width:100%; padding:10px 20px; border:none; background:#cf222e; color:white; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500;">
                    Yes, Delete Permanently
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function showDeleteModal(name, studentId, actionUrl) {
    document.getElementById('modalStudentName').textContent = name;
    document.getElementById('modalStudentId').textContent = studentId;
    document.getElementById('deleteForm').action = actionUrl;
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
    }
});
</script>
@endsection
