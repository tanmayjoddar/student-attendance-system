# Student Attendance Management System — Implementation Plan

> **Stack:** Laravel 11 · PostgreSQL · Bootstrap 5 CDN · Vanilla CSS
> **Roles:** Super Admin · Student
> **Run locally** with PgAdmin 4 — no Docker, no npm, no Vite

---

## Quick Reference

| Phase | What you build | Key deliverable |
|-------|---------------|-----------------|
| 1 | Project skeleton + database | Migrations run, server starts |
| 2 | Models + AttendanceService | All anti-cheat logic works |
| 3 | Middleware + Routes + Controllers | All endpoints respond |
| 4 | Layouts + CSS framework | GitHub-style UI shell |
| 5 | All Blade views + Heatmap | Full UI working |
| 6 | Seeders + Final testing | App fully usable |

**Login credentials (after Phase 6):**
- Admin → `admin@school.com` / `Admin@123`
- Student → `student1@school.com` / `Student@123`

---

## Phase 1 — Foundation & Database Setup

**Objective:** Laravel project created, database migrated, server running.

### 1.1 Project Initialization

```powershell
composer create-project laravel/laravel attendance-system
cd attendance-system
composer require laravel/ui
php artisan ui bootstrap --auth
```

**CRITICAL — Do these immediately after the command above:**

1. Open `resources/views/layouts/app.blade.php`
2. **Delete** the entire `@vite([...])` line
3. In `<head>`, add:
```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
```
4. Just before `</body>`, add:
```html
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
```
5. **Delete** the entire `resources/sass/` folder
6. **Delete** `app/Http/Controllers/HomeController.php`
7. Open `routes/web.php` — remove the `/home` route line if it exists

---

### 1.2 Environment Configuration

Open PgAdmin 4 → right-click Databases → Create → name it `attendance_db` → Save.

Edit `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=attendance_db
DB_USERNAME=postgres
DB_PASSWORD=your_actual_password
```

---

### 1.3 Configuration File

Create `config/attendance.php`:
```php
<?php

return [
    'check_in_start'          => '07:00',
    'check_in_cutoff'         => '11:00',
    'backdate_limit_minutes'  => 10,
    'flag_threshold_minutes'  => 5,
    'suspicious_ip_threshold' => 5,
];
```

---

### 1.4 Migrations

**Edit** `database/migrations/0001_01_01_000000_create_users_table.php`

Inside `Schema::create('users', ...)`, add these two columns before `timestamps()`:
```php
$table->enum('role', ['super_admin', 'student'])->default('student');
$table->unsignedBigInteger('student_id')->nullable();
// No FK here — added after students table exists (migration 000004)
```

---

**Create** `database/migrations/2025_04_21_000001_create_students_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('parent_name');
            $table->text('address');
            $table->string('photo_path')->nullable();
            $table->string('student_code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
```

---

**Create** `database/migrations/2025_04_21_000002_create_attendance_logs_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['in', 'out']);
            $table->timestamp('recorded_time');
            $table->timestamp('stated_time')->nullable();
            $table->string('ip_address');
            $table->boolean('is_flagged')->default(false);
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamps();

            $table->unique(['student_id', 'date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
```

---

**Create** `database/migrations/2025_04_21_000003_create_audit_trail_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_trail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('attendance_log_id')
                  ->nullable()
                  ->constrained('attendance_logs')
                  ->nullOnDelete();
            $table->string('action');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->text('reason');
            $table->string('ip_address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_trail');
    }
};
```

---

**Create** `database/migrations/2025_04_21_000004_add_student_fk_to_users.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('student_id')
                  ->references('id')
                  ->on('students')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['student_id']);
        });
    }
};
```

---

### 1.5 Run Migrations

```powershell
php artisan migrate
```

Every table should show green "DONE". Verify in PgAdmin 4 → `attendance_db` → Tables.

### 1.6 Verify Server

```powershell
php artisan serve
```

Open `http://127.0.0.1:8000` — Laravel welcome page with working CSS (Bootstrap from CDN).
If CSS is missing → the `@vite()` line in step 1.1 was not fully removed.

### Phase 1 Checklist
- [ ] Project created, auth scaffolded
- [ ] `@vite()` deleted, CDN added, `sass/` deleted
- [ ] `HomeController` deleted, `/home` route removed
- [ ] `.env` configured with PostgreSQL
- [ ] `config/attendance.php` created
- [ ] All 5 migrations created and run successfully
- [ ] Server starts, page loads with CSS

---

## Phase 2 — Models, Exception & Business Logic

**Objective:** All Eloquent models, relationships, and the AttendanceService with complete anti-cheat logic.

### 2.1 Models

**Edit** `app/Models/User.php` — add inside the class:
```php
protected $fillable = ['name', 'email', 'password', 'role', 'student_id'];

protected $casts = [
    'email_verified_at' => 'datetime',
    'password'          => 'hashed',
    'role'              => 'string',
];

public function student(): BelongsTo
{
    return $this->belongsTo(Student::class);
}

public function auditTrails(): HasMany
{
    return $this->hasMany(AuditTrail::class, 'admin_id');
}
```

---

**Create** `app/Models/Student.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    protected $fillable = [
        'name', 'parent_name', 'address',
        'photo_path', 'student_code', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Student $student) {
            $next = (Student::max('id') ?? 0) + 1;
            $student->student_code = 'STU-' . str_pad($next, 4, '0', STR_PAD_LEFT);
        });
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
```

---

**Create** `app/Models/AttendanceLog.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class AttendanceLog extends Model
{
    protected $fillable = [
        'student_id', 'date', 'type', 'recorded_time',
        'stated_time', 'ip_address', 'is_flagged', 'submitted_by',
    ];

    protected $casts = [
        'date'          => 'date',
        'recorded_time' => 'datetime',
        'stated_time'   => 'datetime',
        'is_flagged'    => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('date', Carbon::today());
    }

    public function scopeForStudent(Builder $query, int $id): Builder
    {
        return $query->where('student_id', $id);
    }

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('is_flagged', true);
    }
}
```

---

**Create** `app/Models/AuditTrail.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditTrail extends Model
{
    protected $table = 'audit_trail';

    protected $fillable = [
        'admin_id', 'student_id', 'attendance_log_id',
        'action', 'old_value', 'new_value', 'reason', 'ip_address',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }
}
```

---

### 2.2 Custom Exception

**Create** `app/Exceptions/DuplicateAttendanceException.php`:
```php
<?php

namespace App\Exceptions;

use Exception;

class DuplicateAttendanceException extends Exception
{
    public function __construct(string $message = 'Attendance already recorded for this session today.')
    {
        parent::__construct($message);
    }
}
```

---

### 2.3 AttendanceService

**Create** `app/Services/AttendanceService.php`:
```php
<?php

namespace App\Services;

use App\Exceptions\DuplicateAttendanceException;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceService
{
    /**
     * Record a student check-in.
     * ANTI-CHEAT: recorded_time is always Carbon::now() (server time).
     * stated_time is what the student claims — stored only as reference.
     */
    public function recordCheckIn(Student $student, Request $request): array
    {
        $recordedTime = Carbon::now();
        $statedTime   = null;
        $isFlagged    = false;

        // Validate stated_time if provided
        if ($request->filled('stated_time')) {
            $statedTime = Carbon::parse($request->stated_time);
            $validation = $this->validateStatedTime($statedTime, $recordedTime);
            if ($validation !== true) {
                return ['success' => false, 'message' => $validation];
            }
            $diffMinutes = abs($recordedTime->diffInMinutes($statedTime));
            $isFlagged   = $diffMinutes > config('attendance.flag_threshold_minutes');
        }

        // Check for duplicate check-in today
        $exists = AttendanceLog::where('student_id', $student->id)
            ->whereDate('date', $recordedTime->toDateString())
            ->where('type', 'in')
            ->exists();

        if ($exists) {
            throw new DuplicateAttendanceException('You have already checked in today.');
        }

        $log = AttendanceLog::create([
            'student_id'    => $student->id,
            'date'          => $recordedTime->toDateString(),
            'type'          => 'in',
            'recorded_time' => $recordedTime,
            'stated_time'   => $statedTime,
            'ip_address'    => $request->ip(),
            'is_flagged'    => $isFlagged,
            'submitted_by'  => auth()->id(),
        ]);

        return [
            'success'       => true,
            'recorded_time' => $recordedTime->format('H:i:s'),
            'flagged'       => $isFlagged,
        ];
    }

    /**
     * Record a student check-out.
     * ANTI-CHEAT: check-out recorded_time must be after check-in recorded_time.
     */
    public function recordCheckOut(Student $student, Request $request): array
    {
        $recordedTime = Carbon::now();
        $statedTime   = null;
        $isFlagged    = false;

        // Validate stated_time if provided
        if ($request->filled('stated_time')) {
            $statedTime = Carbon::parse($request->stated_time);
            $validation = $this->validateStatedTime($statedTime, $recordedTime);
            if ($validation !== true) {
                return ['success' => false, 'message' => $validation];
            }
            $diffMinutes = abs($recordedTime->diffInMinutes($statedTime));
            $isFlagged   = $diffMinutes > config('attendance.flag_threshold_minutes');
        }

        // Must have checked in today first
        $checkIn = AttendanceLog::where('student_id', $student->id)
            ->whereDate('date', $recordedTime->toDateString())
            ->where('type', 'in')
            ->first();

        if (!$checkIn) {
            return ['success' => false, 'message' => 'You must check in before checking out.'];
        }

        // Check-out must be after check-in
        if ($recordedTime->lessThanOrEqualTo($checkIn->recorded_time)) {
            return ['success' => false, 'message' => 'Check-out time must be after your check-in time.'];
        }

        // Duplicate check-out guard
        $exists = AttendanceLog::where('student_id', $student->id)
            ->whereDate('date', $recordedTime->toDateString())
            ->where('type', 'out')
            ->exists();

        if ($exists) {
            throw new DuplicateAttendanceException('You have already checked out today.');
        }

        AttendanceLog::create([
            'student_id'    => $student->id,
            'date'          => $recordedTime->toDateString(),
            'type'          => 'out',
            'recorded_time' => $recordedTime,
            'stated_time'   => $statedTime,
            'ip_address'    => $request->ip(),
            'is_flagged'    => $isFlagged,
            'submitted_by'  => auth()->id(),
        ]);

        return [
            'success'       => true,
            'recorded_time' => $recordedTime->format('H:i:s'),
            'flagged'       => $isFlagged,
        ];
    }

    /**
     * Build heatmap data for a student for a given year.
     * Returns array keyed by date string: ['2025-03-01' => [...], ...]
     */
    public function getHeatmapData(Student $student, int $year): array
    {
        $logs = AttendanceLog::where('student_id', $student->id)
            ->whereYear('date', $year)
            ->get()
            ->groupBy(fn($log) => $log->date->toDateString());

        $editedDates = AuditTrail::where('student_id', $student->id)
            ->whereNotNull('attendance_log_id')
            ->with('attendanceLog')
            ->get()
            ->pluck('attendanceLog.date')
            ->filter()
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->toArray();

        $cutoff  = config('attendance.check_in_cutoff', '09:00');
        $start   = Carbon::create($year, 1, 1);
        $end     = Carbon::create($year, 12, 31);
        $heatmap = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->toDateString();

            // Skip weekends
            if ($day->isWeekend()) {
                continue;
            }

            $dayLogs = $logs->get($dateStr, collect());
            $in      = $dayLogs->firstWhere('type', 'in');
            $out     = $dayLogs->firstWhere('type', 'out');

            if (!$in) {
                $status = 'absent';
            } elseif (!$out) {
                $status = 'half_day';
            } else {
                $checkInTime = $in->recorded_time->format('H:i');
                $status      = $checkInTime <= $cutoff ? 'present' : 'late';

                // Distinguish very late (> 30 min past cutoff)
                if ($status === 'late') {
                    $cutoffCarbon = Carbon::parse($dateStr . ' ' . $cutoff);
                    if ($in->recorded_time->diffInMinutes($cutoffCarbon) > 30) {
                        $status = 'very_late';
                    }
                }
            }

            $heatmap[$dateStr] = [
                'status'  => $status,
                'in'      => $in  ? $in->recorded_time->format('H:i')  : null,
                'out'     => $out ? $out->recorded_time->format('H:i') : null,
                'edited'  => in_array($dateStr, $editedDates),
                'flagged' => ($in && $in->is_flagged) || ($out && $out->is_flagged),
            ];
        }

        return $heatmap;
    }

    /**
     * Detect suspicious IPs: same IP used for > threshold check-ins within 2 minutes.
     */
    public function detectSuspiciousIPs(): array
    {
        $threshold = config('attendance.suspicious_ip_threshold', 5);
        $logs      = AttendanceLog::where('type', 'in')
            ->orderBy('ip_address')
            ->orderBy('recorded_time')
            ->get();

        $suspicious = [];

        foreach ($logs->groupBy('ip_address') as $ip => $ipLogs) {
            foreach ($ipLogs as $i => $log) {
                $window = $ipLogs->filter(function ($other) use ($log) {
                    return abs($other->recorded_time->diffInSeconds($log->recorded_time)) <= 120;
                });

                if ($window->count() >= $threshold) {
                    $suspicious[$ip] = $window->pluck('id')->toArray();
                    break;
                }
            }
        }

        return $suspicious;
    }

    /**
     * Validate a stated_time against server time.
     * Returns true if valid, or an error string if invalid.
     */
    private function validateStatedTime(Carbon $statedTime, Carbon $now): bool|string
    {
        if ($statedTime->greaterThan($now)) {
            return 'You cannot enter a future time.';
        }

        $limit = config('attendance.backdate_limit_minutes', 10);

        if ($now->diffInMinutes($statedTime) > $limit) {
            return "You cannot backdate attendance by more than {$limit} minutes.";
        }

        return true;
    }
}
```

### Phase 2 Checklist
- [ ] `User.php` updated with role cast + relationships
- [ ] `Student.php` created with auto student_code boot
- [ ] `AttendanceLog.php` created with scopes
- [ ] `AuditTrail.php` created
- [ ] `DuplicateAttendanceException.php` created
- [ ] `AttendanceService.php` created with all 4 methods

---

## Phase 3 — Middleware, Routes & Controllers

**Objective:** Role middleware, rate limiting, all routes with correct naming, and all controllers.

### 3.1 Role Middleware

**Create** `app/Http/Middleware/RoleMiddleware.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (!auth()->check() || auth()->user()->role !== $role) {
            return redirect()->route('login')->with('error', 'Unauthorized access.');
        }

        return $next($request);
    }
}
```

**Edit** `bootstrap/app.php` — add inside `->withMiddleware(...)`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\RoleMiddleware::class,
    ]);
})
```

---

### 3.2 Rate Limiting

**Edit** `app/Providers/AppServiceProvider.php`:
```php
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('attendance', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });
    }
}
```

---

### 3.3 Routes

**Edit** `routes/web.php` — replace entire contents:
```php
<?php

use App\Http\Controllers\Admin\AdminAttendanceController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminStudentController;
use App\Http\Controllers\Api\ServerTimeController;
use App\Http\Controllers\Student\StudentDashboardController;
use Illuminate\Support\Facades\Route;

// Auth routes — registration disabled, admin creates students manually
Auth::routes(['register' => false]);

// Server time API (no auth — used by student dashboard JS)
Route::get('/api/server-time', [ServerTimeController::class, 'index']);

// ── Admin routes ──────────────────────────────────────────────────────────────
// ->name('admin.') makes Route::resource generate admin.students.index etc.
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:super_admin'])
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])
             ->name('dashboard');                              // admin.dashboard

        Route::resource('students', AdminStudentController::class);
        // Generates: admin.students.index / create / store / show / edit / update / destroy

        Route::get('/attendance', [AdminAttendanceController::class, 'index'])
             ->name('attendance');                            // admin.attendance

        Route::get('/attendance/{studentId}', [AdminAttendanceController::class, 'show'])
             ->name('attendance.show');                       // admin.attendance.show

        Route::post('/attendance/{logId}/override', [AdminAttendanceController::class, 'override'])
             ->name('attendance.override');                   // admin.attendance.override

        Route::get('/audit-log', [AdminAttendanceController::class, 'auditLog'])
             ->name('audit-log');                             // admin.audit-log
    });

// ── Student routes ─────────────────────────────────────────────────────────────
Route::prefix('student')
    ->name('student.')
    ->middleware(['auth', 'role:student'])
    ->group(function () {
        Route::get('/dashboard', [StudentDashboardController::class, 'index'])
             ->name('dashboard');                             // student.dashboard

        Route::post('/check-in', [StudentDashboardController::class, 'checkIn'])
             ->name('check-in')
             ->middleware('throttle:attendance');             // student.check-in

        Route::post('/check-out', [StudentDashboardController::class, 'checkOut'])
             ->name('check-out')
             ->middleware('throttle:attendance');             // student.check-out
    });
```

---

### 3.4 Admin Controllers

**Create** `app/Http/Controllers/Admin/AdminDashboardController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Student;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $totalStudents = Student::where('is_active', true)->count();
        $presentToday  = AttendanceLog::today()->where('type', 'in')
                            ->distinct('student_id')->count('student_id');
        $absentToday   = $totalStudents - $presentToday;

        $todayLogs = AttendanceLog::today()
            ->with('student')
            ->orderBy('recorded_time')
            ->get()
            ->groupBy('student_id');

        return view('admin.dashboard', compact(
            'totalStudents', 'presentToday', 'absentToday', 'todayLogs'
        ));
    }
}
```

---

**Create** `app/Http/Controllers/Admin/AdminStudentController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AdminStudentController extends Controller
{
    public function __construct(private AttendanceService $attendanceService) {}

    public function index(Request $request)
    {
        $students = Student::query()
            ->when($request->search, fn($q) =>
                $q->where('name', 'ilike', '%' . $request->search . '%')
                  ->orWhere('student_code', 'ilike', '%' . $request->search . '%')
            )
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.students.index', compact('students'));
    }

    public function create()
    {
        return view('admin.students.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'parent_name' => 'required|string|max:255',
            'address'     => 'required|string',
            'photo'       => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')
                ->store('students', 'public');
        }

        unset($data['photo']);
        Student::create($data);

        return redirect()->route('admin.students.index')
            ->with('success', 'Student registered successfully.');
    }

    public function show(Student $student)
    {
        $heatmapData = $this->attendanceService
            ->getHeatmapData($student, now()->year);

        $logs = $student->attendanceLogs()
            ->orderByDesc('date')
            ->orderByDesc('recorded_time')
            ->paginate(30);

        return view('admin.students.show', compact('student', 'heatmapData', 'logs'));
    }

    public function edit(Student $student)
    {
        return view('admin.students.edit', compact('student'));
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'parent_name' => 'required|string|max:255',
            'address'     => 'required|string',
            'photo'       => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')
                ->store('students', 'public');
        }

        unset($data['photo']);
        $student->update($data);

        return redirect()->route('admin.students.show', $student)
            ->with('success', 'Student updated successfully.');
    }

    public function destroy(Student $student)
    {
        $student->update(['is_active' => false]);

        return redirect()->route('admin.students.index')
            ->with('success', 'Student deactivated.');
    }
}
```

---

**Create** `app/Http/Controllers/Admin/AdminAttendanceController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AdminAttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendanceService) {}

    public function index(Request $request)
    {
        $date = $request->date ?? now()->toDateString();

        $students = Student::where('is_active', true)
            ->with(['attendanceLogs' => fn($q) => $q->whereDate('date', $date)])
            ->orderBy('name')
            ->get();

        $suspiciousIPs = $this->attendanceService->detectSuspiciousIPs();

        return view('admin.attendance.index', compact('students', 'date', 'suspiciousIPs'));
    }

    public function show(int $studentId)
    {
        $student = Student::findOrFail($studentId);

        $logs = AttendanceLog::forStudent($studentId)
            ->orderByDesc('date')
            ->orderByDesc('recorded_time')
            ->paginate(30);

        return view('admin.attendance.show', compact('student', 'logs'));
    }

    public function override(Request $request, int $logId)
    {
        $request->validate([
            'reason'        => 'required|string|min:10',
            'recorded_time' => 'required|date',
        ]);

        $log      = AttendanceLog::findOrFail($logId);
        $oldValue = $log->only(['recorded_time', 'stated_time', 'type']);

        $log->update([
            'recorded_time' => $request->recorded_time,
        ]);

        AuditTrail::create([
            'admin_id'           => auth()->id(),
            'student_id'         => $log->student_id,
            'attendance_log_id'  => $log->id,
            'action'             => 'override',
            'old_value'          => $oldValue,
            'new_value'          => $log->fresh()->only(['recorded_time', 'stated_time', 'type']),
            'reason'             => $request->reason,
            'ip_address'         => $request->ip(),
        ]);

        return redirect()->route('admin.attendance.show', $log->student_id)
            ->with('success', 'Attendance record overridden. Audit trail created.');
    }

    public function auditLog()
    {
        $entries = AuditTrail::with(['admin', 'student', 'attendanceLog'])
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('admin.audit-log', compact('entries'));
    }
}
```

---

### 3.5 Student & API Controllers

**Create** `app/Http/Controllers/Student/StudentDashboardController.php`:
```php
<?php

namespace App\Http\Controllers\Student;

use App\Exceptions\DuplicateAttendanceException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function __construct(private AttendanceService $attendanceService) {}

    public function index()
    {
        $student     = auth()->user()->student;
        $heatmapData = $this->attendanceService->getHeatmapData($student, now()->year);

        $todayIn  = AttendanceLog::forStudent($student->id)->today()->where('type', 'in')->first();
        $todayOut = AttendanceLog::forStudent($student->id)->today()->where('type', 'out')->first();

        return view('student.dashboard', compact('student', 'heatmapData', 'todayIn', 'todayOut'));
    }

    public function checkIn(Request $request)
    {
        try {
            $result = $this->attendanceService->recordCheckIn(
                auth()->user()->student, $request
            );

            if (!$result['success']) {
                return back()->with('error', $result['message']);
            }

            return back()->with('success', 'Checked in at ' . $result['recorded_time']);
        } catch (DuplicateAttendanceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkOut(Request $request)
    {
        try {
            $result = $this->attendanceService->recordCheckOut(
                auth()->user()->student, $request
            );

            if (!$result['success']) {
                return back()->with('error', $result['message']);
            }

            return back()->with('success', 'Checked out at ' . $result['recorded_time']);
        } catch (DuplicateAttendanceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
```

---

**Create** `app/Http/Controllers/Api/ServerTimeController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;

class ServerTimeController extends Controller
{
    public function index()
    {
        return response()->json([
            'time' => Carbon::now()->format('H:i:s'),
            'date' => Carbon::now()->toDateString(),
        ]);
    }
}
```

---

### 3.6 Auth Controller Modifications

**Edit** `app/Http/Controllers/Auth/LoginController.php` — add method inside class:
```php
// MUST be a method, not a property — method overrides the $redirectTo property
protected function redirectTo()
{
    return auth()->user()->role === 'super_admin'
        ? route('admin.dashboard')
        : route('student.dashboard');
}
```

**Edit** `app/Http/Middleware/RedirectIfAuthenticated.php` — replace `handle()`:
```php
public function handle(Request $request, Closure $next, ...$guards)
{
    foreach ($guards as $guard) {
        if (Auth::guard($guard)->check()) {
            $user = Auth::guard($guard)->user();
            return redirect(
                $user->role === 'super_admin'
                    ? route('admin.dashboard')
                    : route('student.dashboard')
            );
        }
    }

    return $next($request);
}
```

**Edit** `app/Http/Controllers/Auth/RegisterController.php` — in the `create()` method, add `'role' => 'student'` to the `User::create([...])` call.

### Phase 3 Checklist
- [ ] `RoleMiddleware.php` created
- [ ] Registered in `bootstrap/app.php`
- [ ] Rate limiter in `AppServiceProvider::boot()`
- [ ] `routes/web.php` replaced with correct routes
- [ ] Both groups have `->name('admin.')` and `->name('student.')`
- [ ] All 6 controllers created
- [ ] `LoginController::redirectTo()` added as a method
- [ ] `RedirectIfAuthenticated` updated
- [ ] `RegisterController::create()` sets `role = 'student'`

---

## Phase 4 — Layouts & CSS Framework

**Objective:** Base layout, admin sidebar layout, GitHub-style CSS. No Bootstrap visual classes.

### 4.1 Main Layout

**Create/Replace** `resources/views/layouts/app.blade.php`:
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Attendance System') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #24292f;
            background: #f6f8fa;
            margin: 0;
        }

        a { color: #0969da; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Top Navigation ── */
        .top-nav {
            background: #24292f;
            padding: 0 16px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .top-nav .nav-brand {
            color: #ffffff;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
        }
        .top-nav .nav-links { display: flex; align-items: center; gap: 8px; }
        .top-nav .nav-links a {
            color: #cdd9e5;
            font-size: 13px;
            text-decoration: none;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .top-nav .nav-links a:hover { background: #444d56; color: #fff; }

        /* ── Flash Messages ── */
        .flash {
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 16px;
            border: 1px solid;
        }
        .flash-success { background: #dafbe1; color: #116329; border-color: #aceebb; }
        .flash-error   { background: #ffebe9; color: #cf222e; border-color: #ffcecb; }
        .flash-warning { background: #fff8c5; color: #7d4e00; border-color: #e3b341; }

        /* ── Content Wrapper ── */
        .page-content {
            max-width: 1012px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        /* ── Tables ── */
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th {
            background: #f6f8fa;
            font-weight: 600;
            padding: 8px 16px;
            border-bottom: 1px solid #d0d7de;
            text-align: left;
            color: #24292f;
        }
        td { padding: 8px 16px; border-bottom: 1px solid #d0d7de; color: #24292f; }
        tr:last-child td { border-bottom: none; }
        .table-wrap {
            border: 1px solid #d0d7de;
            border-radius: 6px;
            overflow: hidden;
        }

        /* ── Buttons ── */
        .btn {
            display: inline-block;
            padding: 5px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid;
            text-decoration: none;
            line-height: 20px;
        }
        .btn:hover { text-decoration: none; }
        .btn-green  { background: #1f883d; color: #fff; border-color: rgba(31,35,40,.15); }
        .btn-white  { background: #f6f8fa; color: #24292f; border-color: #d0d7de; }
        .btn-red    { background: #cf222e; color: #fff; border-color: rgba(31,35,40,.15); }
        .btn-green:hover { background: #1a7f37; color: #fff; }
        .btn-white:hover { background: #eaeef2; }
        .btn-red:hover   { background: #a40e26; color: #fff; }
        .btn-sm { padding: 3px 10px; font-size: 12px; }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid;
        }
        .badge-present  { background: #dafbe1; color: #116329; border-color: #aceebb; }
        .badge-late     { background: #fff8c5; color: #7d4e00; border-color: #e3b341; }
        .badge-absent   { background: #f6f8fa; color: #57606a; border-color: #d0d7de; }
        .badge-flagged  { background: #ffebe9; color: #cf222e; border-color: #ffcecb; }
        .badge-halfday  { background: #fff1e5; color: #953800; border-color: #ffa657; }
        .badge-edited   { background: #ddf4ff; color: #0550ae; border-color: #54aeff; }

        /* ── Forms ── */
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 4px; color: #24292f; }
        input[type="text"], input[type="email"], input[type="password"],
        input[type="datetime-local"], input[type="date"], input[type="file"],
        select, textarea {
            width: 100%;
            padding: 5px 12px;
            border: 1px solid #d0d7de;
            border-radius: 6px;
            font-size: 14px;
            color: #24292f;
            background: #ffffff;
            outline: none;
            font-family: inherit;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #0969da;
            box-shadow: 0 0 0 3px rgba(9,105,218,.15);
        }
        .field-error { color: #cf222e; font-size: 12px; margin-top: 4px; }

        /* ── Section Header ── */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 8px;
            border-bottom: 1px solid #d0d7de;
            margin-bottom: 16px;
        }
        .section-header h2 { font-size: 20px; font-weight: 600; margin: 0; }

        /* ── Stat Cards ── */
        .stat-grid { display: flex; gap: 16px; margin-bottom: 24px; }
        .stat-card {
            flex: 1;
            background: #fff;
            border: 1px solid #d0d7de;
            border-radius: 6px;
            padding: 16px;
        }
        .stat-card .stat-label { font-size: 12px; color: #57606a; margin-bottom: 4px; }
        .stat-card .stat-value { font-size: 24px; font-weight: 600; color: #24292f; }
    </style>
    @stack('styles')
</head>
<body>

<nav class="top-nav">
    <a class="nav-brand" href="{{ auth()->check() && auth()->user()->role === 'super_admin' ? route('admin.dashboard') : (auth()->check() ? route('student.dashboard') : '/') }}">
        Attendance System
    </a>
    <div class="nav-links">
        @auth
            <span style="color:#cdd9e5; font-size:13px;">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="btn btn-white btn-sm">Logout</button>
            </form>
        @endauth
    </div>
</nav>

<main>
    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
```

---

### 4.2 Admin Layout

**Create** `resources/views/layouts/admin.blade.php`:
```html
@extends('layouts.app')

@section('content')
<div style="display:flex; min-height: calc(100vh - 48px);">

    {{-- Sidebar --}}
    <aside style="
        width: 240px;
        min-height: 100%;
        background: #fff;
        border-right: 1px solid #d0d7de;
        padding: 16px 0;
        flex-shrink: 0;
    ">
        <div style="padding: 8px 16px 12px; font-size:11px; font-weight:600;
                    color:#57606a; text-transform:uppercase; letter-spacing:.06em;">
            Admin Panel
        </div>

        @php $current = request()->route()->getName(); @endphp

        <a href="{{ route('admin.dashboard') }}"
           style="display:block; padding:6px 16px; font-size:14px; color:#24292f;
                  text-decoration:none; border-radius:6px; margin:2px 8px;
                  {{ str_starts_with($current, 'admin.dashboard') ? 'background:#eaeef2; font-weight:600;' : '' }}">
            Dashboard
        </a>
        <a href="{{ route('admin.students.index') }}"
           style="display:block; padding:6px 16px; font-size:14px; color:#24292f;
                  text-decoration:none; border-radius:6px; margin:2px 8px;
                  {{ str_starts_with($current, 'admin.students') ? 'background:#eaeef2; font-weight:600;' : '' }}">
            Students
        </a>
        <a href="{{ route('admin.attendance') }}"
           style="display:block; padding:6px 16px; font-size:14px; color:#24292f;
                  text-decoration:none; border-radius:6px; margin:2px 8px;
                  {{ str_starts_with($current, 'admin.attendance') ? 'background:#eaeef2; font-weight:600;' : '' }}">
            Attendance
        </a>
        <a href="{{ route('admin.audit-log') }}"
           style="display:block; padding:6px 16px; font-size:14px; color:#24292f;
                  text-decoration:none; border-radius:6px; margin:2px 8px;
                  {{ $current === 'admin.audit-log' ? 'background:#eaeef2; font-weight:600;' : '' }}">
            Audit Log
        </a>
    </aside>

    {{-- Main content --}}
    <div style="flex:1; overflow:auto;">
        <div class="page-content">

            {{-- Flash messages --}}
            @if(session('success'))
                <div class="flash flash-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="flash flash-error">{{ session('error') }}</div>
            @endif
            @if(session('warning'))
                <div class="flash flash-warning">{{ session('warning') }}</div>
            @endif

            @yield('admin-content')
        </div>
    </div>

</div>
@endsection
```

### Phase 4 Checklist
- [ ] `layouts/app.blade.php` created with CDN + full CSS
- [ ] `layouts/admin.blade.php` created with sidebar
- [ ] No `@vite()` anywhere
- [ ] No Bootstrap visual utility classes
- [ ] Flash messages display in both layouts

---

## Phase 5 — All Blade Views

**Objective:** Every view file. Admin views, student dashboard, and the heatmap component.

### 5.1 Admin Dashboard

**Create** `resources/views/admin/dashboard.blade.php`:
```html
@extends('layouts.admin')

@section('admin-content')
<div class="section-header">
    <h2>Today's Attendance</h2>
    <span style="color:#57606a; font-size:13px;">{{ now()->format('l, d F Y') }}</span>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Students</div>
        <div class="stat-value">{{ $totalStudents }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Present Today</div>
        <div class="stat-value" style="color:#1f883d;">{{ $presentToday }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Absent Today</div>
        <div class="stat-value" style="color:#cf222e;">{{ $absentToday }}</div>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Student</th>
                <th>Code</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Status</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            @forelse($todayLogs as $studentId => $logs)
                @php
                    $in  = $logs->firstWhere('type', 'in');
                    $out = $logs->firstWhere('type', 'out');
                @endphp
                <tr>
                    <td>{{ $in->student->name ?? '—' }}</td>
                    <td style="color:#57606a;">{{ $in->student->student_code ?? '—' }}</td>
                    <td>{{ $in ? $in->recorded_time->format('H:i:s') : '—' }}</td>
                    <td>{{ $out ? $out->recorded_time->format('H:i:s') : '—' }}</td>
                    <td>
                        @if(!$in)
                            <span class="badge badge-absent">Absent</span>
                        @elseif(!$out)
                            <span class="badge badge-halfday">Half-day</span>
                        @else
                            <span class="badge badge-present">Present</span>
                        @endif
                        @if($in && $in->is_flagged)
                            <span class="badge badge-flagged">Flagged</span>
                        @endif
                    </td>
                    <td style="color:#57606a; font-size:12px;">{{ $in->ip_address ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="color:#57606a; text-align:center; padding:24px;">
                        No attendance recorded today yet.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
```

---

### 5.2 Students Index

**Create** `resources/views/admin/students/index.blade.php`:
```html
@extends('layouts.admin')

@section('admin-content')
<div class="section-header">
    <h2>Students</h2>
    <a href="{{ route('admin.students.create') }}" class="btn btn-green">+ Add Student</a>
</div>

<form method="GET" style="margin-bottom:16px; display:flex; gap:8px;">
    <input type="text" name="search" value="{{ request('search') }}"
           placeholder="Search by name or code…" style="max-width:300px;">
    <button type="submit" class="btn btn-white">Search</button>
    @if(request('search'))
        <a href="{{ route('admin.students.index') }}" class="btn btn-white">Clear</a>
    @endif
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Parent</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $student)
                <tr>
                    <td style="color:#57606a; font-size:12px;">{{ $student->student_code }}</td>
                    <td><a href="{{ route('admin.students.show', $student) }}">{{ $student->name }}</a></td>
                    <td style="color:#57606a;">{{ $student->parent_name }}</td>
                    <td>
                        @if($student->is_active)
                            <span class="badge badge-present">Active</span>
                        @else
                            <span class="badge badge-absent">Inactive</span>
                        @endif
                    </td>
                    <td style="display:flex; gap:8px;">
                        <a href="{{ route('admin.students.show', $student) }}" class="btn btn-white btn-sm">View</a>
                        <a href="{{ route('admin.students.edit', $student) }}" class="btn btn-white btn-sm">Edit</a>
                        @if($student->is_active)
                            <form method="POST" action="{{ route('admin.students.destroy', $student) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-red btn-sm"
                                        onclick="return confirm('Deactivate this student?')">
                                    Deactivate
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="color:#57606a; text-align:center; padding:24px;">
                        No students found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">{{ $students->links() }}</div>
@endsection
```

---

### 5.3 Create Student

**Create** `resources/views/admin/students/create.blade.php`:
```html
@extends('layouts.admin')

@section('admin-content')
<div class="section-header">
    <h2>Register New Student</h2>
    <a href="{{ route('admin.students.index') }}" class="btn btn-white">← Back</a>
</div>

<div style="max-width:560px;">
    <form method="POST" action="{{ route('admin.students.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="form-group">
            <label for="name">Full Name *</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required>
            @error('name') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="parent_name">Parent / Guardian Name *</label>
            <input type="text" id="parent_name" name="parent_name" value="{{ old('parent_name') }}" required>
            @error('parent_name') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="address">Address *</label>
            <textarea id="address" name="address" rows="3">{{ old('address') }}</textarea>
            @error('address') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="photo">Photo (optional, max 2MB)</label>
            <input type="file" id="photo" name="photo" accept="image/*">
            @error('photo') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-green">Register Student</button>
    </form>
</div>
@endsection
```

---

### 5.4 Student Profile (show)

**Create** `resources/views/admin/students/show.blade.php`:
```html
@extends('layouts.admin')

@section('admin-content')
<div class="section-header">
    <h2>{{ $student->name }}</h2>
    <div style="display:flex; gap:8px;">
        <a href="{{ route('admin.students.edit', $student) }}" class="btn btn-white">Edit</a>
        <a href="{{ route('admin.students.index') }}" class="btn btn-white">← Back</a>
    </div>
</div>

{{-- Student info card --}}
<div style="display:flex; gap:24px; margin-bottom:24px; padding:16px;
            border:1px solid #d0d7de; border-radius:6px; background:#fff;">
    @if($student->photo_path)
        <img src="{{ Storage::url($student->photo_path) }}"
             style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:1px solid #d0d7de;">
    @else
        <div style="width:80px; height:80px; border-radius:50%; background:#f6f8fa;
                    border:1px solid #d0d7de; display:flex; align-items:center;
                    justify-content:center; color:#57606a; font-size:24px; font-weight:600;">
            {{ strtoupper(substr($student->name, 0, 1)) }}
        </div>
    @endif
    <div>
        <div style="font-weight:600; font-size:16px;">{{ $student->name }}</div>
        <div style="color:#57606a; margin-top:4px;">{{ $student->student_code }}</div>
        <div style="margin-top:8px; font-size:13px;">
            <span style="color:#57606a;">Parent:</span> {{ $student->parent_name }}
        </div>
        <div style="font-size:13px;">
            <span style="color:#57606a;">Address:</span> {{ $student->address }}
        </div>
        <div style="margin-top:8px;">
            @if($student->is_active)
                <span class="badge badge-present">Active</span>
            @else
                <span class="badge badge-absent">Inactive</span>
            @endif
        </div>
    </div>
</div>

{{-- Heatmap --}}
<div style="margin-bottom:24px;">
    <h3 style="font-size:16px; font-weight:600; margin-bottom:12px;">Attendance Heatmap {{ now()->year }}</h3>
    <x-attendance-heatmap :heatmapData="$heatmapData" />
</div>

{{-- Attendance log table --}}
<div class="section-header" style="margin-top:24px;">
    <h3 style="font-size:16px; font-weight:600; margin:0;">Attendance History</h3>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Recorded Time</th>
                <th>Stated Time</th>
                <th>Flags</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log->date->format('d M Y') }}</td>
                    <td>
                        <span class="badge {{ $log->type === 'in' ? 'badge-present' : 'badge-absent' }}">
                            {{ strtoupper($log->type) }}
                        </span>
                    </td>
                    <td>{{ $log->recorded_time->format('H:i:s') }}</td>
                    <td style="color:#57606a;">
                        {{ $log->stated_time ? $log->stated_time->format('H:i:s') : '—' }}
                    </td>
                    <td>
                        @if($log->is_flagged)
                            <span class="badge badge-flagged">Flagged</span>
                        @endif
                        @if($log->auditTrails->count())
                            <span class="badge badge-edited">Edited</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('admin.attendance.override', $log->id) }}"
                           class="btn btn-white btn-sm">Override</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="color:#57606a; text-align:center; padding:24px;">
                        No attendance records found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">{{ $logs->links() }}</div>
@endsection
```

---

### 5.5 Attendance Override Form

**Create** `resources/views/admin/attendance/override.blade.php`:
```html
@extends('layouts.admin')

@section('admin-content')
<div class="section-header">
    <h2>Override Attendance Record</h2>
    <a href="javascript:history.back()" class="btn btn-white">← Back</a>
</div>

<div style="max-width:560px;">
    {{-- Current values --}}
    <div style="background:#f6f8fa; border:1px solid #d0d7de; border-radius:6px;
                padding:16px; margin-bottom:24px;">
        <div style="font-weight:600; margin-bottom:8px; color:#57606a; font-size:12px;
                    text-transform:uppercase; letter-spacing:.06em;">Current Record</div>
        <div style="font-size:14px;">
            <span style="color:#57606a;">Type:</span>
            <strong>{{ strtoupper($log->type) }}</strong>
        </div>
        <div style="font-size:14px; margin-top:4px;">
            <span style="color:#57606a;">Recorded:</span>
            <strong>{{ $log->recorded_time->format('d M Y H:i:s') }}</strong>
        </div>
        <div style="font-size:14px; margin-top:4px;">
            <span style="color:#57606a;">Student:</span>
            {{ $log->student->name }} ({{ $log->student->student_code }})
        </div>
    </div>

    <form method="POST" action="{{ route('admin.attendance.override', $log->id) }}">
        @csrf

        <div class="form-group">
            <label for="recorded_time">New Recorded Time *</label>
            <input type="datetime-local" id="recorded_time" name="recorded_time"
                   value="{{ old('recorded_time', $log->recorded_time->format('Y-m-d\TH:i')) }}" required>
            @error('recorded_time') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="reason">Reason for Override * (min 10 characters)</label>
            <textarea id="reason" name="reason" rows="4" required minlength="10">{{ old('reason') }}</textarea>
            @error('reason') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div style="background:#fff8c5; border:1px solid #e3b341; border-radius:6px;
                    padding:12px 16px; margin-bottom:16px; font-size:13px; color:#7d4e00;">
            This override will be permanently recorded in the audit trail with your name, IP address, reason, and timestamp.
        </div>

        <button type="submit" class="btn btn-red">Save Override</button>
    </form>
</div>
@endsection
```

---

### 5.6 Audit Log

**Create** `resources/views/admin/audit-log.blade.php`:
```html
@extends('layouts.admin')

@section('admin-content')
<div class="section-header">
    <h2>Audit Log</h2>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Admin</th>
                <th>Student</th>
                <th>Action</th>
                <th>Reason</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td style="color:#57606a; font-size:12px;">
                        {{ $entry->created_at->format('d M Y H:i:s') }}
                    </td>
                    <td>{{ $entry->admin->name ?? '—' }}</td>
                    <td>{{ $entry->student->name ?? '—' }}</td>
                    <td><span class="badge badge-edited">{{ $entry->action }}</span></td>
                    <td style="max-width:300px;">{{ $entry->reason }}</td>
                    <td style="color:#57606a; font-size:12px;">{{ $entry->ip_address }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="color:#57606a; text-align:center; padding:24px;">
                        No audit entries yet.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">{{ $entries->links() }}</div>
@endsection
```

---

### 5.7 Student Dashboard

**Create** `resources/views/student/dashboard.blade.php`:
```html
@extends('layouts.app')

@section('content')
<div class="page-content">

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="flash flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash flash-error">{{ session('error') }}</div>
    @endif

    <div class="section-header">
        <h2>{{ $student->name }}</h2>
        <span style="color:#57606a;">{{ $student->student_code }}</span>
    </div>

    {{-- Server time display --}}
    <div style="background:#f6f8fa; border:1px solid #d0d7de; border-radius:6px;
                padding:12px 16px; margin-bottom:24px; display:flex; align-items:center; gap:16px;">
        <div>
            <div style="font-size:12px; color:#57606a;">Server Time</div>
            <div id="server-time" style="font-size:22px; font-weight:600; font-variant-numeric:tabular-nums;">
                {{ now()->format('H:i:s') }}
            </div>
        </div>
        <div>
            <div style="font-size:12px; color:#57606a;">Date</div>
            <div style="font-size:14px;">{{ now()->format('l, d F Y') }}</div>
        </div>
    </div>

    {{-- Check-in / Check-out section --}}
    <div style="background:#fff; border:1px solid #d0d7de; border-radius:6px;
                padding:24px; margin-bottom:32px;">
        <h3 style="font-size:16px; font-weight:600; margin:0 0 16px;">Today's Attendance</h3>

        @if($todayIn && $todayOut)
            {{-- Both done --}}
            <div style="display:flex; gap:24px;">
                <div>
                    <div style="font-size:12px; color:#57606a;">Checked in</div>
                    <div style="font-size:18px; font-weight:600; color:#1f883d;">
                        {{ $todayIn->recorded_time->format('H:i:s') }}
                    </div>
                </div>
                <div>
                    <div style="font-size:12px; color:#57606a;">Checked out</div>
                    <div style="font-size:18px; font-weight:600; color:#0969da;">
                        {{ $todayOut->recorded_time->format('H:i:s') }}
                    </div>
                </div>
            </div>
            <div style="margin-top:12px;">
                <span class="badge badge-present">Attendance Complete</span>
            </div>

        @elseif($todayIn && !$todayOut)
            {{-- Checked in, needs checkout --}}
            <div style="margin-bottom:16px;">
                <div style="font-size:12px; color:#57606a;">Checked in at</div>
                <div style="font-size:18px; font-weight:600; color:#1f883d;">
                    {{ $todayIn->recorded_time->format('H:i:s') }}
                </div>
            </div>
            <form method="POST" action="{{ route('student.check-out') }}">
                @csrf
                <div class="form-group" style="max-width:280px;">
                    <label for="stated_time_out">Your actual check-out time (optional)</label>
                    <input type="datetime-local" name="stated_time" id="stated_time_out"
                           max="{{ now()->format('Y-m-d\TH:i') }}">
                    <div style="font-size:12px; color:#57606a; margin-top:4px;">
                        Can only be within {{ config('attendance.backdate_limit_minutes') }} minutes of now.
                        Server records actual submission time.
                    </div>
                </div>
                <button type="submit" class="btn btn-green">Check Out Now</button>
            </form>

        @else
            {{-- Not checked in yet --}}
            <form method="POST" action="{{ route('student.check-in') }}">
                @csrf
                <div class="form-group" style="max-width:280px;">
                    <label for="stated_time_in">Your actual arrival time (optional)</label>
                    <input type="datetime-local" name="stated_time" id="stated_time_in"
                           max="{{ now()->format('Y-m-d\TH:i') }}">
                    <div style="font-size:12px; color:#57606a; margin-top:4px;">
                        Can only be within {{ config('attendance.backdate_limit_minutes') }} minutes of now.
                        Server records actual submission time.
                    </div>
                </div>
                <button type="submit" class="btn btn-green">Check In Now</button>
            </form>
        @endif
    </div>

    {{-- Heatmap --}}
    <h3 style="font-size:16px; font-weight:600; margin-bottom:12px;">
        My Attendance — {{ now()->year }}
    </h3>
    <x-attendance-heatmap :heatmapData="$heatmapData" />

</div>
@endsection

@push('scripts')
<script>
    // Update server time every 30 seconds
    function updateTime() {
        fetch('/api/server-time')
            .then(r => r.json())
            .then(data => {
                document.getElementById('server-time').textContent = data.time;
            })
            .catch(() => {}); // silently ignore network errors
    }
    setInterval(updateTime, 30000);
</script>
@endpush
```

---

### 5.8 Heatmap Component

**Create** `resources/views/components/attendance-heatmap.blade.php`:
```html
@props(['heatmapData' => []])

@php
    $year      = now()->year;
    $startDate = \Carbon\Carbon::create($year, 1, 1)->startOfWeek(\Carbon\Carbon::SUNDAY);
    $endDate   = \Carbon\Carbon::create($year, 12, 31)->endOfWeek(\Carbon\Carbon::SATURDAY);

    $colorMap = [
        'present'   => '#216e39',
        'late'      => '#40c463',
        'very_late' => '#9be9a8',
        'half_day'  => '#f0a500',
        'absent'    => '#ebedf0',
    ];
@endphp

<style>
.heatmap-wrap { overflow-x: auto; padding-bottom: 8px; }
.heatmap-grid { display: flex; gap: 3px; align-items: flex-start; }
.heatmap-week { display: flex; flex-direction: column; gap: 3px; }
.heatmap-day  {
    width: 12px; height: 12px;
    border-radius: 2px;
    position: relative;
    cursor: default;
    flex-shrink: 0;
}
.heatmap-day .tip {
    display: none;
    position: absolute;
    bottom: 18px;
    left: 50%;
    transform: translateX(-50%);
    background: #24292f;
    color: #fff;
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 6px;
    white-space: nowrap;
    z-index: 10;
    pointer-events: none;
    line-height: 1.5;
}
.heatmap-day:hover .tip { display: block; }
.heatmap-day .edited-dot {
    position: absolute;
    top: 1px; right: 1px;
    width: 4px; height: 4px;
    background: #cf222e;
    border-radius: 50%;
}
.heatmap-months {
    display: flex;
    gap: 3px;
    margin-bottom: 4px;
    font-size: 11px;
    color: #57606a;
    padding-left: 0;
}
.heatmap-legend {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 12px;
    font-size: 12px;
    color: #57606a;
    flex-wrap: wrap;
}
.legend-item { display: flex; align-items: center; gap: 4px; }
.legend-box  { width: 12px; height: 12px; border-radius: 2px; }
</style>

<div class="heatmap-wrap">
    <div class="heatmap-grid">
        @php $current = $startDate->copy(); @endphp

        @while($current->lte($endDate))
            <div class="heatmap-week">
                @for($dow = 0; $dow < 7; $dow++)
                    @php
                        $dateStr = $current->toDateString();
                        $inYear  = $current->year === $year;
                        $data    = $heatmapData[$dateStr] ?? null;
                        $status  = $data['status'] ?? ($current->isWeekend() ? 'weekend' : 'absent');
                        $color   = $colorMap[$status] ?? '#ebedf0';
                        $isWeekend = $current->isWeekend();
                    @endphp

                    <div class="heatmap-day"
                         style="background: {{ $inYear && !$isWeekend ? $color : '#ebedf0' }}; opacity: {{ $inYear ? '1' : '0' }};">

                        @if($data && $data['edited'])
                            <div class="edited-dot"></div>
                        @endif

                        @if($inYear && !$isWeekend && $data)
                            <div class="tip">
                                {{ $current->format('d M Y') }}<br>
                                In: {{ $data['in'] ?? '—' }} &nbsp; Out: {{ $data['out'] ?? '—' }}<br>
                                Status: {{ ucfirst(str_replace('_', ' ', $status)) }}
                                @if($data['edited']) · Edited by admin @endif
                                @if($data['flagged']) · Flagged @endif
                            </div>
                        @elseif($inYear)
                            <div class="tip">{{ $current->format('d M Y') }}</div>
                        @endif
                    </div>

                    @php $current->addDay(); @endphp
                @endfor
            </div>
        @endwhile
    </div>

    <div class="heatmap-legend">
        <div class="legend-item">
            <div class="legend-box" style="background:#216e39;"></div> On time
        </div>
        <div class="legend-item">
            <div class="legend-box" style="background:#40c463;"></div> Slightly late
        </div>
        <div class="legend-item">
            <div class="legend-box" style="background:#9be9a8;"></div> Very late
        </div>
        <div class="legend-item">
            <div class="legend-box" style="background:#f0a500;"></div> Half-day
        </div>
        <div class="legend-item">
            <div class="legend-box" style="background:#ebedf0;"></div> Absent / Weekend
        </div>
        <div class="legend-item">
            <div style="width:12px; height:12px; border-radius:2px; background:#216e39;
                        position:relative; display:inline-block;">
                <div style="position:absolute; top:1px; right:1px; width:4px; height:4px;
                            background:#cf222e; border-radius:50%;"></div>
            </div>
            &nbsp;Admin-edited
        </div>
    </div>
</div>
```

### Phase 5 Checklist
- [ ] `admin/dashboard.blade.php` created
- [ ] `admin/students/index.blade.php` created
- [ ] `admin/students/create.blade.php` created
- [ ] `admin/students/show.blade.php` created
- [ ] `admin/attendance/override.blade.php` created
- [ ] `admin/audit-log.blade.php` created
- [ ] `student/dashboard.blade.php` created
- [ ] `components/attendance-heatmap.blade.php` created

> **Note:** `admin/attendance/index.blade.php` and `admin/students/edit.blade.php` follow the same patterns as `dashboard.blade.php` and `create.blade.php` respectively — build them using the same table and form structure shown above.

---

## Phase 6 — Seeders, Testing & Go-Live

**Objective:** Seed the database with test data, verify all 32 tests pass, system is usable.

### 6.1 Database Seeder

**Edit** `database/seeders/DatabaseSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Super admin
        $admin = User::create([
            'name'     => 'Super Admin',
            'email'    => 'admin@school.com',
            'password' => Hash::make('Admin@123'),
            'role'     => 'super_admin',
        ]);

        // 2. Five students + their user accounts
        $studentNames = ['Student One', 'Student Two', 'Student Three', 'Student Four', 'Student Five'];

        foreach ($studentNames as $i => $name) {
            $student = Student::create([
                'name'        => $name,
                'parent_name' => 'Parent of ' . $name,
                'address'     => ($i + 1) . ' School Street, Kolkata',
                'is_active'   => true,
            ]);

            $user = User::create([
                'name'       => $name,
                'email'      => 'student' . ($i + 1) . '@school.com',
                'password'   => Hash::make('Student@123'),
                'role'       => 'student',
                'student_id' => $student->id,
            ]);

            // 3. 90 days of attendance logs
            for ($day = 90; $day >= 0; $day--) {
                $date = Carbon::now()->subDays($day)->toDateString();

                // Skip weekends
                if (Carbon::parse($date)->isWeekend()) continue;

                // ~80% attendance rate
                if (rand(1, 10) <= 2) continue;

                // Random check-in between 7:30 and 9:30
                $inHour   = rand(7, 9);
                $inMinute = rand(0, 59);
                if ($inHour === 7) $inMinute = rand(30, 59);

                $checkInTime = Carbon::parse($date . ' ' . sprintf('%02d:%02d:00', $inHour, $inMinute));

                // Occasionally flag — stated time differs from recorded time
                $statedTime = null;
                $isFlagged  = false;
                if (rand(1, 10) === 1) {
                    $statedTime = $checkInTime->copy()->subMinutes(rand(6, 15));
                    $isFlagged  = true;
                }

                AttendanceLog::create([
                    'student_id'    => $student->id,
                    'date'          => $date,
                    'type'          => 'in',
                    'recorded_time' => $checkInTime,
                    'stated_time'   => $statedTime,
                    'ip_address'    => '127.0.0.' . rand(1, 5),
                    'is_flagged'    => $isFlagged,
                    'submitted_by'  => $user->id,
                ]);

                // Random check-out between 3:00 and 5:00 PM
                $outHour     = rand(15, 17);
                $outMinute   = rand(0, 59);
                $checkOutTime = Carbon::parse($date . ' ' . sprintf('%02d:%02d:00', $outHour, $outMinute));

                AttendanceLog::create([
                    'student_id'    => $student->id,
                    'date'          => $date,
                    'type'          => 'out',
                    'recorded_time' => $checkOutTime,
                    'stated_time'   => null,
                    'ip_address'    => '127.0.0.' . rand(1, 5),
                    'is_flagged'    => false,
                    'submitted_by'  => $user->id,
                ]);
            }
        }
    }
}
```

---

### 6.2 Final Commands

```powershell
php artisan storage:link
php artisan migrate:fresh --seed
php artisan serve
```

Open `http://127.0.0.1:8000/login`

---

### 6.3 Testing Checklist (32 Tests)

**Authentication (5)**
- [ ] `admin@school.com` / `Admin@123` → lands on `/admin/dashboard`
- [ ] `student1@school.com` / `Student@123` → lands on `/student/dashboard`
- [ ] Student cannot access any `/admin/*` URL
- [ ] Admin cannot access any `/student/*` URL
- [ ] Unauthenticated user redirected to `/login`

**Anti-Cheat (9)**
- [ ] Student can check in (button works, recorded_time is server time)
- [ ] Student can check in with valid stated_time (≤ 10 min ago)
- [ ] **REJECT** — future stated_time blocked
- [ ] **REJECT** — stated_time > 10 min in past blocked
- [ ] **REJECT** — second check-in on same day blocked
- [ ] Student can check out after check-in
- [ ] **REJECT** — check-out without prior check-in blocked
- [ ] **REJECT** — second check-out on same day blocked
- [ ] Rate limiter fires after 3 rapid submissions (429 response)

**Admin Features (9)**
- [ ] Today's attendance board shows all students
- [ ] Suspicious IP badge appears when threshold met
- [ ] Create new student with name/parent/address
- [ ] Photo upload works (stored in `storage/public/students/`)
- [ ] Edit student info saves correctly
- [ ] Deactivate student sets `is_active = false`
- [ ] Override form requires reason (min 10 chars)
- [ ] Override creates entry in `audit_trail` table
- [ ] Audit log page shows entries with pagination

**UI (5)**
- [ ] Heatmap renders with correct colors for present/late/absent/half-day
- [ ] Heatmap tooltip shows date, in/out times on hover
- [ ] Red dot appears on edited records in heatmap
- [ ] Flash messages show in green/red/yellow correctly
- [ ] Server time on student dashboard updates every 30 seconds

**Security (4)**
- [ ] All POST forms have `@csrf` token
- [ ] `/register` returns 404 (disabled)
- [ ] No `@vite()` in any view file (`grep -r "@vite" resources/`)
- [ ] All routes protected by auth + role middleware

---

### 6.4 File Structure (Final)

```
attendance-system/
├── app/
│   ├── Exceptions/
│   │   └── DuplicateAttendanceException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── AdminDashboardController.php
│   │   │   │   ├── AdminStudentController.php
│   │   │   │   └── AdminAttendanceController.php
│   │   │   ├── Student/
│   │   │   │   └── StudentDashboardController.php
│   │   │   └── Api/
│   │   │       └── ServerTimeController.php
│   │   └── Middleware/
│   │       └── RoleMiddleware.php
│   ├── Models/
│   │   ├── User.php          ← edited
│   │   ├── Student.php
│   │   ├── AttendanceLog.php
│   │   └── AuditTrail.php
│   ├── Providers/
│   │   └── AppServiceProvider.php  ← edited (rate limiter)
│   └── Services/
│       └── AttendanceService.php
├── bootstrap/
│   └── app.php               ← edited (role middleware alias)
├── config/
│   └── attendance.php
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php   ← edited
│   │   ├── 2025_04_21_000001_create_students_table.php
│   │   ├── 2025_04_21_000002_create_attendance_logs_table.php
│   │   ├── 2025_04_21_000003_create_audit_trail_table.php
│   │   └── 2025_04_21_000004_add_student_fk_to_users.php
│   └── seeders/
│       └── DatabaseSeeder.php
├── resources/views/
│   ├── layouts/
│   │   ├── app.blade.php
│   │   └── admin.blade.php
│   ├── components/
│   │   └── attendance-heatmap.blade.php
│   ├── admin/
│   │   ├── dashboard.blade.php
│   │   ├── audit-log.blade.php
│   │   ├── students/
│   │   │   ├── index.blade.php
│   │   │   ├── create.blade.php
│   │   │   ├── edit.blade.php
│   │   │   └── show.blade.php
│   │   └── attendance/
│   │       ├── index.blade.php
│   │       └── override.blade.php
│   └── student/
│       └── dashboard.blade.php
└── routes/
    └── web.php               ← edited
```

---

## Anti-Cheat Summary

| Rule | Where implemented |
|------|------------------|
| Server time only — `Carbon::now()`, never browser time | Phase 2 — `AttendanceService` |
| No future backdating | Phase 2 — `validateStatedTime()` |
| Max 10-min backdate on stated_time | Phase 2 — `validateStatedTime()` |
| One check-in per day — DB UNIQUE constraint | Phase 1 — migration |
| One check-in per day — code guard | Phase 2 — `recordCheckIn()` |
| Check-out must follow check-in | Phase 2 — `recordCheckOut()` |
| IP address on every record | Phase 2 — all `AttendanceLog::create()` calls |
| Suspicious IP detection | Phase 2 — `detectSuspiciousIPs()` |
| Audit trail with required reason | Phase 3 — `AdminAttendanceController::override()` |
| Rate limit 3/min on check-in/out | Phase 3 — `AppServiceProvider` + route middleware |
| CSRF on all forms | Phase 5 — every Blade form has `@csrf` |
| No public registration | Phase 3 — `Auth::routes(['register' => false])` |
