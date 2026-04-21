<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'AttendanceHub') }} - @yield('title', 'Dashboard')</title>

    <!-- Bootstrap CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
            --color-canvas-default: #ffffff;
            --color-canvas-subtle: #f6f8fa;
            --color-border-default: #d0d7de;
            --color-border-muted: #d8dee4;
            --color-accent-fg: #0969da;
            --color-accent-emphasis: #0969da;
            --color-success-fg: #1a7f37;
            --color-attention-fg: #9a6700;
            --color-danger-fg: #cf222e;
            --color-fg-default: #1f2328;
            --color-fg-muted: #656d76;
            --color-fg-subtle: #6e7781;
            --color-btn-text: #24292f;
            --color-btn-bg: #f6f8fa;
            --color-btn-border: rgba(27, 31, 36, 0.15);
            --color-btn-hover-bg: #f3f4f6;
            --color-header-bg: #24292f;
            --color-header-text: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--color-fg-default);
            background-color: var(--color-canvas-default);
            margin: 0;
        }

        /* Header */
        .header {
            background-color: var(--color-header-bg);
            padding: 16px 32px;
            color: var(--color-header-text);
        }

        .header h1 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .header a {
            color: var(--color-header-text);
            text-decoration: none;
        }

        .header a:hover {
            opacity: 0.7;
        }

        /* Container */
        .container-lg {
            max-width: 1280px;
            margin: 0 auto;
            padding: 24px 32px;
        }

        /* Cards */
        .Box {
            background-color: var(--color-canvas-default);
            border: 1px solid var(--color-border-default);
            border-radius: 6px;
        }

        .Box-header {
            background-color: var(--color-canvas-subtle);
            border-bottom: 1px solid var(--color-border-default);
            padding: 16px;
            border-radius: 6px 6px 0 0;
        }

        .Box-header h2 {
            font-size: 14px;
            font-weight: 600;
            margin: 0;
        }

        .Box-body {
            padding: 16px;
        }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 5px 16px;
            font-size: 14px;
            font-weight: 500;
            line-height: 20px;
            white-space: nowrap;
            vertical-align: middle;
            cursor: pointer;
            user-select: none;
            border: 1px solid var(--color-btn-border);
            border-radius: 6px;
            appearance: none;
            background-color: var(--color-btn-bg);
            color: var(--color-btn-text);
            transition: 0.2s ease;
        }

        .btn:hover {
            background-color: var(--color-btn-hover-bg);
        }

        .btn-primary {
            background-color: var(--color-accent-fg);
            color: #ffffff;
            border-color: rgba(27, 31, 36, 0.15);
        }

        .btn-primary:hover {
            background-color: #0550ae;
        }

        .btn-success {
            background-color: var(--color-success-fg);
            color: #ffffff;
        }

        .btn-danger {
            color: var(--color-danger-fg);
        }

        /* Tables */
        .Table {
            width: 100%;
            border-collapse: collapse;
        }

        .Table th,
        .Table td {
            padding: 8px 16px;
            text-align: left;
            border-bottom: 1px solid var(--color-border-muted);
        }

        .Table th {
            font-weight: 600;
            background-color: var(--color-canvas-subtle);
        }

        .Table tr:last-child td {
            border-bottom: 0;
        }

        /* Stats */
        .counter {
            display: inline-block;
            padding: 0 8px;
            font-size: 12px;
            font-weight: 600;
            line-height: 18px;
            background-color: var(--color-canvas-subtle);
            border: 1px solid var(--color-border-default);
            border-radius: 2em;
            color: var(--color-fg-default);
        }

        /* States */
        .State {
            display: inline-block;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 2em;
        }

        .State--green {
            color: var(--color-success-fg);
            background-color: #dafbe1;
        }

        .State--red {
            color: var(--color-danger-fg);
            background-color: #ffebe9;
        }

        .State--yellow {
            color: var(--color-attention-fg);
            background-color: #fff8c5;
        }

        /* Flash messages */
        .flash-success {
            padding: 16px;
            margin-bottom: 16px;
            background-color: #dafbe1;
            border: 1px solid var(--color-success-fg);
            border-radius: 6px;
            color: var(--color-success-fg);
        }

        .flash-error {
            padding: 16px;
            margin-bottom: 16px;
            background-color: #ffebe9;
            border: 1px solid var(--color-danger-fg);
            border-radius: 6px;
            color: var(--color-danger-fg);
        }

        /* Navigation */
        .UnderlineNav {
            border-bottom: 1px solid var(--color-border-default);
            margin-bottom: 16px;
        }

        .UnderlineNav-item {
            display: inline-block;
            padding: 8px 16px;
            margin-bottom: -1px;
            color: var(--color-fg-default);
            text-decoration: none;
            border-bottom: 2px solid transparent;
        }

        .UnderlineNav-item:hover {
            border-bottom-color: var(--color-fg-muted);
        }

        .UnderlineNav-item.active {
            font-weight: 600;
            border-bottom-color: var(--color-danger-fg);
        }

        /* Form controls */
        .form-control {
            padding: 5px 12px;
            font-size: 14px;
            line-height: 20px;
            color: var(--color-fg-default);
            background-color: var(--color-canvas-default);
            border: 1px solid var(--color-border-default);
            border-radius: 6px;
            outline: none;
            transition: 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--color-accent-fg);
            box-shadow: 0 0 0 3px rgba(9, 105, 218, 0.3);
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 16px;
        }

        /* Text utilities */
        .text-bold { font-weight: 600; }
        .text-small { font-size: 12px; }
        .text-muted { color: var(--color-fg-muted); }
        .mt-4 { margin-top: 16px; }
        .mb-4 { margin-bottom: 16px; }
        .d-flex { display: flex; }
        .flex-justify-between { justify-content: space-between; }
        .flex-items-center { align-items: center; }
    </style>

    @stack('styles')
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="d-flex flex-items-center" style="max-width: 1280px; margin: 0 auto;">
            <h1><a href="{{ auth()->user()->role === 'super_admin' ? route('admin.dashboard') : route('student.dashboard') }}">{{ config('app.name', 'AttendanceHub') }}</a></h1>
            
            @auth
            <div style="margin-left: auto;">
                <span class="text-small" style="margin-right: 16px;">{{ auth()->user()->name }}</span>
                <a href="{{ route('logout') }}" 
                   onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                   class="btn" style="padding: 3px 12px; font-size: 12px; background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white;">
                    Logout
                </a>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                    @csrf
                </form>
            </div>
            @endauth
        </div>
    </header>

    <!-- Main Content -->
    <main class="container-lg">
        @if (session('success'))
            <div class="flash-success">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="flash-error">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>

    <!-- Bootstrap JS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
