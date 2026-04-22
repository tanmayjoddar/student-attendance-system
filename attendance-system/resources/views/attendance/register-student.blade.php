@extends('layouts.app')

@section('content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <div>
        <h2 style="margin:0; font-size: 24px;">Student Self Registration</h2>
        <div class="text-muted">Open page for new students to register with photo</div>
    </div>
    <a href="{{ route('attendance.kiosk') }}" class="btn">Back to Attendance</a>
</div>

<div class="Box" style="max-width: 760px; margin: 0 auto;">
    <div class="Box-header"><h2>Registration Form</h2></div>
    <div class="Box-body">
        <form method="POST" action="{{ route('student.register.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="d-flex" style="gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">First Name</label>
                    <input class="form-control" type="text" name="first_name" value="{{ old('first_name') }}" required>
                    @error('first_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Last Name</label>
                    <input class="form-control" type="text" name="last_name" value="{{ old('last_name') }}" required>
                    @error('last_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" value="{{ old('email') }}" required>
                @error('email')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex" style="gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Parent Name</label>
                    <input class="form-control" type="text" name="parent_name" value="{{ old('parent_name') }}" required>
                    @error('parent_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Father Name</label>
                    <input class="form-control" type="text" name="father_name" value="{{ old('father_name') }}" required>
                    @error('father_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex" style="gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Mother Name</label>
                    <input class="form-control" type="text" name="mother_name" value="{{ old('mother_name') }}" required>
                    @error('mother_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="3" required>{{ old('address') }}</textarea>
                @error('address')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex" style="gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Phone</label>
                    <input class="form-control" type="text" name="phone" value="{{ old('phone') }}">
                    @error('phone')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Department</label>
                    <input class="form-control" type="text" name="department" value="{{ old('department') }}">
                    @error('department')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Photo (required)</label>
                <input class="form-control" type="file" name="photo" accept="image/*" required>
                <div class="text-small text-muted" style="margin-top: 6px;">Max 2MB image file.</div>
                @error('photo')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-primary">Register Myself</button>
        </form>
    </div>
</div>
@endsection
