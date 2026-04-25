<?php

use App\Http\Controllers\AdminAttendanceController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminStudentController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentSelfRegistrationController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('attendance.kiosk');
});

Route::get('/attendance', [StudentDashboardController::class, 'kiosk'])->name('attendance.kiosk');
Route::post('/attendance/check-in', [StudentDashboardController::class, 'checkInPublic'])
    ->name('attendance.check-in')
    ->middleware('throttle:attendance');
Route::post('/attendance/check-out', [StudentDashboardController::class, 'checkOutPublic'])
    ->name('attendance.check-out')
    ->middleware('throttle:attendance');
Route::get('/attendance/check-in', function () {
    return redirect()->route('attendance.kiosk')->with('error', 'Use the kiosk form to submit check-in.');
});
Route::get('/attendance/check-out', function () {
    return redirect()->route('attendance.kiosk')->with('error', 'Use the kiosk form to submit check-out.');
});
Route::post('/attendance/face-register', [StudentDashboardController::class, 'registerFace'])
    ->name('attendance.face-register')
    ->middleware('throttle:attendance');
Route::get('/student-register', [StudentSelfRegistrationController::class, 'create'])
    ->name('student.register');
Route::post('/student-register', [StudentSelfRegistrationController::class, 'store'])
    ->name('student.register.store')
    ->middleware('throttle:attendance');

// Disable public registration — admin creates students manually
Auth::routes(['register' => false]);

Route::get('/api/server-time', [StudentDashboardController::class, 'serverTime'])->name('api.server-time');

// ML Face Verification Routes
Route::post('/api/verify-face-ml', [\App\Http\Controllers\FaceVerificationController::class, 'verify']);
Route::post('/api/register-face-ml', [\App\Http\Controllers\FaceVerificationController::class, 'register']);
Route::post('/api/identify-face', [\App\Http\Controllers\FaceVerificationController::class, 'identify']);
Route::delete('/api/delete-face-ml/{studentId}', [\App\Http\Controllers\FaceVerificationController::class, 'deleteFromMl']);

// Auto check-in/out after face identification (no student selection needed)
Route::post('/attendance/auto-checkin', [StudentDashboardController::class, 'autoCheckIn'])
    ->name('attendance.auto-checkin')
    ->middleware('throttle:attendance');
Route::post('/attendance/auto-checkout', [StudentDashboardController::class, 'autoCheckOut'])
    ->name('attendance.auto-checkout')
    ->middleware('throttle:attendance');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:super_admin'])
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::resource('students', AdminStudentController::class);
        Route::get('/attendance', [AdminAttendanceController::class, 'index'])->name('attendance');
        Route::get('/attendance/{studentId}', [AdminAttendanceController::class, 'show'])->name('attendance.show');
        Route::post('/attendance/{logId}/override', [AdminAttendanceController::class, 'override'])->name('attendance.override');
        Route::get('/audit-log', [AdminAttendanceController::class, 'auditLog'])->name('audit-log');
    });

Route::prefix('student')
    ->name('student.')
    ->middleware(['auth', 'role:student'])
    ->group(function () {
        Route::get('/dashboard', [StudentDashboardController::class, 'index'])->name('dashboard');
        Route::post('/check-in', [StudentDashboardController::class, 'checkIn'])
            ->name('check-in')
            ->middleware('throttle:attendance');
        Route::post('/check-out', [StudentDashboardController::class, 'checkOut'])
            ->name('check-out')
            ->middleware('throttle:attendance');
        Route::post('/face-register', [StudentDashboardController::class, 'registerFace'])
            ->name('face-register')
            ->middleware('throttle:attendance');
    });
