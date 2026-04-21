<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminStudentController;
use App\Http\Controllers\AdminAttendanceController;
use App\Http\Controllers\StudentDashboardController;

Route::get('/', function () {
    return redirect()->route('login');
});

// Disable public registration — admin creates students manually
Auth::routes(['register' => false]);

// Admin routes
Route::prefix('admin')
    ->name('admin.')  // Generates: admin.dashboard, admin.students.index, etc.
    ->middleware(['auth', 'role:super_admin'])
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::resource('students', AdminStudentController::class);
        Route::get('/attendance', [AdminAttendanceController::class, 'index'])->name('attendance');
        Route::get('/attendance/{studentId}', [AdminAttendanceController::class, 'show'])->name('attendance.show');
        Route::post('/attendance/{logId}/override', [AdminAttendanceController::class, 'override'])->name('attendance.override');
        Route::get('/audit-log', [AdminAttendanceController::class, 'auditLog'])->name('audit-log');
    });

// Student routes
Route::prefix('student')
    ->name('student.')  // Generates: student.dashboard, student.check-in, etc.
    ->middleware(['auth', 'role:student'])
    ->group(function () {
        Route::get('/dashboard', [StudentDashboardController::class, 'index'])->name('dashboard');
        Route::post('/check-in', [StudentDashboardController::class, 'checkIn'])
            ->name('check-in')
            ->middleware('throttle:attendance');
        Route::post('/check-out', [StudentDashboardController::class, 'checkOut'])
            ->name('check-out')
            ->middleware('throttle:attendance');
    });
