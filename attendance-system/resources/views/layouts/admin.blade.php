@extends('layouts.app')

@section('content')
<div class="d-flex" style="gap: 24px; align-items: flex-start;">
    <aside class="Box" style="width: 250px; position: sticky; top: 16px; overflow: hidden;">
        <div class="Box-header"><h2>Admin Panel</h2></div>
        <div class="Box-body" style="padding: 10px;">
            @php $routeName = request()->route()->getName(); @endphp
            <a class="UnderlineNav-item {{ $routeName === 'admin.dashboard' ? 'active' : '' }}" style="display:block; border-radius: 8px; margin-bottom: 6px;" href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a class="UnderlineNav-item {{ str_starts_with($routeName, 'admin.students') ? 'active' : '' }}" style="display:block; border-radius: 8px; margin-bottom: 6px;" href="{{ route('admin.students.index') }}">Internship Students</a>
            <a class="UnderlineNav-item {{ str_starts_with($routeName, 'admin.attendance') ? 'active' : '' }}" style="display:block; border-radius: 8px; margin-bottom: 6px;" href="{{ route('admin.attendance') }}">Attendance</a>
            <a class="UnderlineNav-item {{ $routeName === 'admin.audit-log' ? 'active' : '' }}" style="display:block; border-radius: 8px;" href="{{ route('admin.audit-log') }}">Audit Log</a>
        </div>
    </aside>

    <section style="flex: 1; min-width: 0;">
        @yield('admin-content')
    </section>
</div>
@endsection
