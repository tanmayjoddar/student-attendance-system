@extends('layouts.admin')

@section('admin-content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <h2 style="margin:0; font-size: 24px;">Edit Student</h2>
    <a href="{{ route('admin.students.index') }}" class="btn">Back</a>
</div>

<div class="Box" style="max-width: 720px;">
    <div class="Box-body">
        <form method="POST" action="{{ route('admin.students.update', $student) }}">
            @csrf
            @method('PUT')
            <div class="d-flex" style="gap:16px;">
                <div class="form-group" style="flex:1;"><label class="form-label">First Name</label><input class="form-control" name="first_name" value="{{ old('first_name', $student->first_name) }}" required></div>
                <div class="form-group" style="flex:1;"><label class="form-label">Last Name</label><input class="form-control" name="last_name" value="{{ old('last_name', $student->last_name) }}" required></div>
            </div>
            <div class="d-flex" style="gap:16px;">
                <div class="form-group" style="flex:1;"><label class="form-label">Parent Name</label><input class="form-control" name="parent_name" value="{{ old('parent_name', $student->parent_name) }}" required></div>
                <div class="form-group" style="flex:1;"><label class="form-label">Father Name</label><input class="form-control" name="father_name" value="{{ old('father_name', $student->father_name) }}" required></div>
            </div>
            <div class="form-group"><label class="form-label">Mother Name</label><input class="form-control" name="mother_name" value="{{ old('mother_name', $student->mother_name) }}" required></div>
            <div class="form-group"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="3" required>{{ old('address', $student->address) }}</textarea></div>
            <div class="d-flex" style="gap:16px;">
                <div class="form-group" style="flex:1;"><label class="form-label">Phone</label><input class="form-control" name="phone" value="{{ old('phone', $student->phone) }}"></div>
                <div class="form-group" style="flex:1;"><label class="form-label">Department</label><input class="form-control" name="department" value="{{ old('department', $student->department) }}"></div>
            </div>
                        <div class="form-group"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="{{ old('email', $student->email) }}" required></div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select class="form-control" name="is_active">
                    <option value="1" {{ old('is_active', (int) $student->is_active) === 1 ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ old('is_active', (int) $student->is_active) === 0 ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit">Save</button>
        </form>
    </div>
</div>
@endsection
