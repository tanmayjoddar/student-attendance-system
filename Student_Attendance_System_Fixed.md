# Student Attendance Management System - Implementation Plan

## LOCAL SETUP INSTRUCTIONS

Run these commands in order:

```powershell
# 1. Create Laravel project
composer create-project laravel/laravel attendance-system

# 2. Navigate to project
cd attendance-system

# 3. Install laravel/ui for authentication (no npm required)
composer require laravel/ui
php artisan ui bootstrap --auth

# 4. Create database "attendance_db" manually in PgAdmin 4 first

# 5. Configure .env (see Step 1 below)

# 6. Run migrations with seeders
php artisan migrate --seed

# 7. Link storage for photo uploads
php artisan storage:link

# 8. Start development server
php artisan serve
```

**IMPORTANT:** Do NOT use Laravel Breeze. We use laravel/ui which generates auth scaffolding with Bootstrap CDN — no npm, no Vite, no build step required.

**CRITICAL:** The correct command is `php artisan ui bootstrap --auth` — NOT `php artisan ui:auth`. The old `ui:auth` alias may not work in newer versions.

---

## STEP 1: Environment Configuration

**File:** `.env`

Set PostgreSQL connection:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=attendance_db
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

---

## STEP 2: Configuration File

**Create:** `config/attendance.php`

```php
return [
    'check_in_start' => '07:00',
    'check_in_cutoff' => '11:00',
    'backdate_limit_minutes' => 10,
    'flag_threshold_minutes' => 5,
    'suspicious_ip_threshold' => 5,
];
```

---

## STEP 3: Database Migrations

### 3.1 Modify Users Table Migration

**Edit:** `database/migrations/0001_01_01_000000_create_users_table.php`

Add to schema:

- `role` enum: 'super_admin', 'student' (default 'student')
- `student_id` bigint nullable (NO foreign key constraint here — will be added in separate migration)

**IMPORTANT:** On PostgreSQL, Laravel's `enum()` creates a `varchar(255)` column with a CHECK constraint, NOT a native PostgreSQL ENUM type. This works fine but will look different in PgAdmin. If you ever need to add enum values later, you must use `DB::statement('ALTER TABLE ...')` — Laravel's `->change()` won't work for enums on PostgreSQL.

### 3.2 Create Students Migration

**Create:** `database/migrations/2025_04_21_000001_create_students_table.php`

Columns:

- id (bigint PK)
- name (string)
- parent_name (string)
- address (text)
- photo_path (string, nullable)
- student_code (string, unique)
- is_active (boolean, default true)
- timestamps

### 3.3 Create Attendance Logs Migration

**Create:** `database/migrations/2025_04_21_000002_create_attendance_logs_table.php`

Columns:

- id (bigint PK)
- student_id (FK → students.id, cascade delete)
- date (date)
- type (enum: 'in', 'out')
- recorded_time (timestamp)
- stated_time (timestamp, nullable)
- ip_address (string)
- is_flagged (boolean, default false)
- submitted_by (FK → users.id)
- UNIQUE(student_id, date, type)
- timestamps

### 3.4 Create Audit Trail Migration

**Create:** `database/migrations/2025_04_21_000003_create_audit_trail_table.php`

Columns:

- id (bigint PK)
- admin_id (FK → users.id)
- student_id (FK → students.id)
- attendance_log_id (FK → attendance_logs.id, nullable)
- action (string)
- old_value (jsonb, nullable)
- new_value (jsonb, nullable)
- reason (text)
- ip_address (string)
- timestamps

### 3.5 Add Foreign Key for users.student_id

**Create:** `database/migrations/2025_04_21_000004_add_student_fk_to_users.php`

This migration runs AFTER students table is created to avoid circular dependency:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->foreign('student_id')->references('id')->on('students')->onDelete('set null');
    });
}
```

---

## STEP 4: Models

### 4.1 User Model

**Edit:** `app/Models/User.php`

Add:

- `casts`: role to string
- Relationship: `belongsTo Student` (if role = student)
- Relationship: `hasMany AuditTrail` (as admin)

### 4.2 Student Model

**Create:** `app/Models/Student.php`

Add:

- `fillable`: name, parent_name, address, photo_path, student_code, is_active
- `casts`: is_active to boolean
- Boot method: auto-generate student_code on creating
- Relationships: `hasMany AttendanceLogs`, `hasOne User`

### 4.3 AttendanceLog Model

**Create:** `app/Models/AttendanceLog.php`

Add:

- `fillable`: student_id, date, type, recorded_time, stated_time, ip_address, is_flagged, submitted_by
- `casts`: date to date, recorded_time/stated_time to datetime, is_flagged to boolean
- Relationships: `belongsTo Student`, `belongsTo User (submitted_by)`, `hasMany AuditTrail`
- Scopes: `scopeToday()`, `scopeForStudent($id)`, `scopeFlagged()`

### 4.4 AuditTrail Model

**Create:** `app/Models/AuditTrail.php`

Add:

- `fillable`: admin_id, student_id, attendance_log_id, action, old_value, new_value, reason, ip_address
- `casts`: old_value/new_value to array
- Relationships: `belongsTo User (admin)`, `belongsTo Student`, `belongsTo AttendanceLog`

---

## STEP 5: Custom Exception

**Create:** `app/Exceptions/DuplicateAttendanceException.php`

Extends Exception, used when duplicate check-in/out detected.

---

## STEP 6: AttendanceService

**Create:** `app/Services/AttendanceService.php`

### Methods:

**recordCheckIn(Student $student, Request $request): array**

- Get server time: `Carbon::now()`
- Validate stated_time if provided:
  - Reject if future time
  - Reject if > 10 minutes in past (use config('attendance.backdate_limit_minutes'))
- Check existing check-in today (student_id, date, type='in')
  - Throw DuplicateAttendanceException if exists
- Calculate is_flagged: abs(stated_time - recorded_time) > 5 minutes
- Save to attendance_logs
- Return: `['success' => true, 'recorded_time' => ..., 'flagged' => ...]`

**recordCheckOut(Student $student, Request $request): array**

- Get server time: `Carbon::now()`
- Validate stated_time (same rules as check-in)
- Query today's check-in for student
  - Reject if no check-in exists
- Validate check-out server time > check-in recorded_time
- Check existing check-out today
  - Throw DuplicateAttendanceException if exists
- Calculate is_flagged
- Save to attendance_logs
- Return success array

**getHeatmapData(Student $student, int $year): array**

- Query all attendance logs for student in year
- Build array keyed by date string ('2025-01-15')
- For each school day (skip weekends):
  - 'present': has both in and out, on time (before 9:00 AM)
  - 'late': check-in after 9:00 AM (use config('attendance.check_in_cutoff'))
  - 'half_day': check-in exists but no check-out
  - 'absent': no records
- Include: in time, out time, edited status (check audit_trail)
- Return structured array

**detectSuspiciousIPs(): array**

- Query attendance_logs grouped by IP address
- Find IPs with > 5 check-ins within 2-minute windows
- Return list of suspicious IPs with associated records

---

## STEP 7: Middleware

### 7.1 RoleMiddleware

**Create:** `app/Http/Middleware/RoleMiddleware.php`

```php
public function handle(Request $request, Closure $next, string $role)
{
    if (!auth()->check() || auth()->user()->role !== $role) {
        return redirect()->route('login')->with('error', 'Unauthorized access.');
    }
    return $next($request);
}
```

**Register in:** `bootstrap/app.php`

Use Laravel 11 syntax (NO Kernel.php exists in Laravel 11):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\RoleMiddleware::class,
    ]);
})
```

### 7.2 Rate Limiting

**Edit:** `app/Providers/AppServiceProvider.php`

Add to the `boot()` method with proper imports:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('attendance', function (Request $request) {
        return Limit::perMinute(3)->by($request->ip());
    });
}
```

**IMPORTANT:** Do NOT put this in routes/web.php. It must be in AppServiceProvider's boot() method.

---

## STEP 8: Controllers

### 8.1 Admin Controllers

**Create:** `app/Http/Controllers/Admin/AdminDashboardController.php`

- `index()` — Admin dashboard showing today's attendance overview
  - Total students, present today, absent today
  - Recent attendance activity

**Create:** `app/Http/Controllers/Admin/AdminStudentController.php`

- `index()` — List all students, support search by name/student_code
- `create()` — Show registration form
- `store(Request $request)` — Validate and create student with photo upload
  - Validate: name (required), parent_name (required), address (required), photo (optional, image, max 2MB)
  - Store photo in `storage/app/public/students/`
- `show($id)` — Student profile with heatmap + attendance history
- `edit($id)` — Edit form
- `update(Request $request, $id)` — Update student info
- `destroy($id)` — Soft delete (set is_active = false)

**Create:** `app/Http/Controllers/Admin/AdminAttendanceController.php`

- `index()` — Today's attendance board
  - Query all students with today's attendance status
  - Call AttendanceService::detectSuspiciousIPs()
  - Flag records with shared IPs
- `show($studentId)` — Full attendance history for one student
- `override(Request $request, $logId)` — Edit attendance record
  - Validate: reason (required, min 10 chars)
  - Update attendance_log
  - Write to audit_trail with old_value, new_value, reason, IP
- `auditLog()` — List all audit trail entries with pagination

### 8.2 Student Controllers

**Create:** `app/Http/Controllers/Student/StudentDashboardController.php`

- `index()` — Student's own dashboard
  - Get heatmap data for current year
  - Get today's attendance status
  - Pass to view
- `checkIn(Request $request)` — POST endpoint
  - Apply rate limiter 'attendance'
  - Call AttendanceService::recordCheckIn()
  - Return redirect with success/error flash message
- `checkOut(Request $request)` — POST endpoint
  - Apply rate limiter 'attendance'
  - Call AttendanceService::recordCheckOut()
  - Return redirect with success/error flash message

### 8.3 API Controller

**Create:** `app/Http/Controllers/Api/ServerTimeController.php`

- `index()` — Return `['time' => Carbon::now()->toDateTimeString()]`

---

## STEP 9: Routes

**Edit:** `routes/web.php`

Add `Auth::routes(['register' => false])` at the top (generated by laravel/ui), then:

```php
use App\Http\Controllers\Admin\AdminStudentController;
use App\Http\Controllers\Admin\AdminAttendanceController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Student\StudentDashboardController;
use App\Http\Controllers\Api\ServerTimeController;

// Disable public registration — admin creates students manually
Auth::routes(['register' => false]);

// API route (no auth)
Route::get('/api/server-time', [ServerTimeController::class, 'index']);

// Admin routes
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

// Student routes
Route::prefix('student')
    ->name('student.')
    ->middleware(['auth', 'role:student'])
    ->group(function () {
        Route::get('/dashboard', [StudentDashboardController::class, 'index'])->name('dashboard');
        Route::post('/check-in', [StudentDashboardController::class, 'checkIn'])->name('check-in')->middleware('throttle:attendance');
        Route::post('/check-out', [StudentDashboardController::class, 'checkOut'])->name('check-out')->middleware('throttle:attendance');
    });
```

**IMPORTANT: Resource Route Names**

`Route::resource('students', ...)` inside the admin prefix group with `->name('admin.')` generates these named routes:

- `admin.students.index`, `admin.students.create`, `admin.students.store`
- `admin.students.show`, `admin.students.edit`, `admin.students.update`, `admin.students.destroy`

The `->name('admin.')` prefix on the group is what creates the `admin.` prefix for all route names inside the group. Without it, routes would be named `students.index`, `students.show`, etc. — which would cause `RouteNotFoundException` errors.

Similarly, student routes use `->name('student.')` to generate `student.dashboard`, `student.check-in`, `student.check-out`.

Use these exact names in all Blade `route()` calls. For example: `route('admin.students.show', $student->id)`

---

## STEP 10: Blade Views

### 10.1 Layouts

**Create:** `resources/views/layouts/app.blade.php`

- HTML5 boilerplate with meta tags
- Load Bootstrap 5.3.3 from CDN (NO @vite(), NO npm):
  ```html
  <link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
  />
  ```
  At bottom of body:
  ```html
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  ```
- Navigation bar with auth links
- Flash message display (success, error, warning)
- `@yield('content')`
- **NO @vite() directive anywhere**

**Create:** `resources/views/layouts/admin.blade.php`

- Extends app.blade.php
- Sidebar with admin navigation links
- Main content area

### 10.2 Admin Views

**Create:** `resources/views/admin/dashboard.blade.php`

- Today's attendance table
- Columns: Student Name, Student Code, Check-in Time, Check-out Time, Status, IP Address
- Yellow badge "Suspicious: shared IP" for flagged records
- Search/filter options

**Create:** `resources/views/admin/students/index.blade.php`

- Search box (form GET request)
- Table: Student Code, Name, Parent Name, Status (Active/Inactive), Actions
- Pagination
- "Add New Student" button

**Create:** `resources/views/admin/students/create.blade.php`

- Form: name, parent_name, address, photo upload
- `@csrf` token
- `@error` directives for inline validation
- Submit button

**Create:** `resources/views/admin/students/show.blade.php`

- Student info card (photo, name, code, parent, address)
- Attendance heatmap component
- Attendance history table below
- Edit/Delete buttons

**Create:** `resources/views/admin/attendance/index.blade.php`

- Daily attendance board
- Same as admin dashboard
- Filter by date

**Create:** `resources/views/admin/attendance/override.blade.php`

- Form to override attendance record
- Show current values (read-only)
- Input fields for new recorded_time, stated_time
- Required textarea for reason
- `@csrf` token
- Validation errors

**Create:** `resources/views/admin/audit-log.blade.php`

- Table: Date, Admin, Student, Action, Reason, IP Address
- Pagination
- Filter by student/admin

### 10.3 Student Views

**Create:** `resources/views/student/dashboard.blade.php`

- Welcome message with student name
- Current server time display (updated via AJAX every 30 seconds from `/api/server-time`)
- Today's status section:
  - If not checked in: Show "Check In" button with optional stated_time input
  - If checked in but not out: Show "Checked in at HH:MM" (disabled), show "Check Out" button
  - If both done: Show both as disabled with times
- Attendance heatmap component below
- Flash messages for success/errors

### 10.4 Heatmap Component

**Create:** `resources/views/components/attendance-heatmap.blade.php`

- Accepts `$heatmapData` array
- 52-week grid (12×12px squares, 3px gap)
- Color coding:
  - Dark green (#216e39): present, on time
  - Medium green (#40c463): present, slightly late (< 30 min)
  - Light green (#9be9a8): present, very late (> 30 min)
  - Gray (#ebedf0): absent or weekend
  - Orange (#f0a500): half-day
  - Red dot overlay: admin-edited record
- CSS tooltips on hover showing: date, in time, out time, status, "edited by admin" if applicable
- Legend below grid
- Pure CSS/Blade, no JavaScript libraries

---

## STEP 11: Seeders

**Edit:** `database/seeders/DatabaseSeeder.php`

Create:

1. Super admin user:
   - email: admin@school.com
   - password: Admin@123 (Hash::make)
   - role: super_admin

2. Five sample students:
   - Names: Student One through Five
   - Auto-generated codes: STU-0001 to STU-0005
   - is_active: true

3. Five student users:
   - emails: student1@school.com to student5@school.com
   - password: Student@123
   - role: student
   - student_id: linked to corresponding student

4. 90 days of sample attendance logs:
   - For each student, loop through last 90 days
   - ~80% attendance rate (skip ~20% randomly)
   - Randomize check-in times between 7:30 AM and 9:30 AM
   - Randomize check-out times between 3:00 PM and 5:00 PM
   - Some records with stated_time differing from recorded_time (flagged)
   - Use Carbon::now()->subDays($i) for dates

---

## STEP 12: Additional Setup

### 12.1 Storage Link

Run: `php artisan storage:link`

### 12.2 Auth Controllers Generated by laravel/ui — CRITICAL CDN FIX

**CRITICAL:** After running `php artisan ui bootstrap --auth`, the generated `resources/views/layouts/app.blade.php` will contain a `@vite([...])` directive that requires npm/Vite. You MUST fix this immediately:

1. Open `resources/views/layouts/app.blade.php` and **delete the entire `@vite([...])` line**.
2. Replace it with CDN links:
   - In the `<head>` section:
     ```html
     <link
       rel="stylesheet"
       href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
     />
     ```
   - Before the closing `</body>` tag:
     ```html
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
     ```
3. **Delete the `resources/sass/` folder entirely** — it will never be used.
4. **Verify ALL auth views** (`resources/views/auth/login.blade.php`, `register.blade.php`, `verify.blade.php`, etc.) extend `layouts.app` and that the layout has NO `@vite()` directive. If even one view loads the layout with `@vite()`, the page will render with no CSS.

**Keep these files** (generated by `php artisan ui bootstrap --auth`):

- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Controllers/Auth/RegisterController.php`
- `resources/views/auth/login.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/layouts/app.blade.php` (must be fixed with CDN as described above)
- Routes in `routes/web.php` via `Auth::routes()`

### 12.3 Redirect After Login — DELETE HomeController

**CRITICAL:** `laravel/ui bootstrap --auth` generates a `HomeController` and adds `Route::get('/home', ...)` to `web.php`. This WILL break your app:

1. **Delete `app/Http/Controllers/HomeController.php` entirely** after scaffolding.
2. **Remove the `/home` route** from `routes/web.php` if it was auto-added.
3. The generated `HomeController` has no role-based middleware and no matching view — if a user lands on `/home`, they will see an error.

**Edit:** `app/Http/Controllers/Auth/LoginController.php`

Add the `redirectTo()` method (as a **method**, NOT a property — the method overrides the default `$redirectTo` property):

```php
protected function redirectTo()
{
    return auth()->user()->role === 'super_admin'
        ? route('admin.dashboard')
        : route('student.dashboard');
}
```

**Edit:** `app/Http/Middleware/RedirectIfAuthenticated.php`

Update the `handle()` method to redirect authenticated users based on role:

```php
public function handle(Request $request, Closure $next, ...$guards)
{
    foreach ($guards as $guard) {
        if (Auth::guard($guard)->check()) {
            $user = Auth::guard($guard)->user();
            return redirect($user->role === 'super_admin'
                ? route('admin.dashboard')
                : route('student.dashboard'));
        }
    }
    return $next($request);
}
```

### 12.4 Update RegisterController

**Edit:** `app/Http/Controllers/Auth/RegisterController.php`

Modify the `create()` method to set default role = 'student' on registration.

---

## STEP 13: UI/UX Design Specification

**CRITICAL INSTRUCTION FOR IMPLEMENTATION:** Do NOT use Bootstrap component classes for visual design. Bootstrap is ONLY used for the grid system (`container`, `row`, `col-*`). All visual design is custom CSS written in a `<style>` block inside `layouts/app.blade.php`.

**The aesthetic target is GitHub's UI circa 2023** — the classic GitHub: high information density, monochrome, functional, no decorative elements anywhere. This is NOT the current rounded-everything redesign.

### Typography

```css
font-family:
  -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
font-size: 14px;
line-height: 1.5;
color: #24292f;
background: #ffffff;
```

- No Google Fonts
- No custom typefaces
- System font stack only — exactly what GitHub uses

### Color Palette — Exactly 6 Values, Nothing Else

```
#24292f  → primary text
#57606a  → secondary text / muted labels
#d0d7de  → borders
#f6f8fa  → page background / sidebar background
#0969da  → links and primary action buttons only
#cf222e  → destructive actions / flagged/suspicious badges only
```

- No purple
- No gradients
- No shadows with blur
- Box shadows only as `box-shadow: 0 1px 0 rgba(31,35,40,.04)` — 1px, flat, barely visible

### Layout

- Fixed left sidebar `240px` wide for admin, full-width for student
- Content area has `max-width: 1012px` centered with `margin: 0 auto; padding: 24px 16px`
- All spacing is multiples of 8px: `8, 16, 24, 32`
- No cards with thick shadows — tables and sections separated by `1px solid #d0d7de` borders only

### Tables — High Density GitHub Style

```css
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
th {
  background: #f6f8fa;
  font-weight: 600;
  padding: 8px 16px;
  border-bottom: 1px solid #d0d7de;
  text-align: left;
}
td {
  padding: 8px 16px;
  border-bottom: 1px solid #d0d7de;
}
tr:last-child td {
  border-bottom: none;
}
```

- No alternating row colors
- No striping
- Just clean borders

### Buttons

```css
/* Primary — green for create/save actions */
.btn-primary {
  background: #1f883d;
  color: #fff;
  border: 1px solid rgba(31, 35, 40, 0.15);
  padding: 5px 16px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
}

/* Secondary */
.btn-secondary {
  background: #f6f8fa;
  color: #24292f;
  border: 1px solid #d0d7de;
  padding: 5px 16px;
  border-radius: 6px;
  font-size: 14px;
}

/* Danger */
.btn-danger {
  background: #cf222e;
  color: #fff;
  border: 1px solid rgba(31, 35, 40, 0.15);
  padding: 5px 16px;
  border-radius: 6px;
}
```

- Green primary (GitHub's "create/save" green), NOT blue
- Blue is only for links

### Badges / Status Indicators

```css
.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
}
.badge-present {
  background: #dafbe1;
  color: #116329;
  border: 1px solid #aceebb;
}
.badge-late {
  background: #fff8c5;
  color: #7d4e00;
  border: 1px solid #e3b341;
}
.badge-absent {
  background: #f6f8fa;
  color: #57606a;
  border: 1px solid #d0d7de;
}
.badge-flagged {
  background: #ffebe9;
  color: #cf222e;
  border: 1px solid #ffcecb;
}
.badge-halfday {
  background: #fff1e5;
  color: #953800;
  border: 1px solid #ffa657;
}
```

### Forms

```css
input[type="text"],
input[type="email"],
input[type="password"],
input[type="datetime-local"],
select,
textarea {
  width: 100%;
  padding: 5px 12px;
  border: 1px solid #d0d7de;
  border-radius: 6px;
  font-size: 14px;
  color: #24292f;
  background: #ffffff;
  outline: none;
}
input:focus {
  border-color: #0969da;
  box-shadow: 0 0 0 3px rgba(9, 105, 218, 0.15);
}
label {
  display: block;
  font-weight: 600;
  font-size: 14px;
  margin-bottom: 4px;
}
```

### Heatmap — GitHub Contribution Graph Style

Render it exactly like GitHub's contribution graph:

- Squares are `10px × 10px` with `3px` gap
- Wrapped in a `<div style="display:flex; gap:3px">` per week column
- Months labeled above in `#57606a` 12px text
- Day labels (Mon, Wed, Fri) on the left in `#57606a` 11px
- Tooltip: pure CSS using `::after` pseudo-element, black background `#24292f`, white text, `border-radius: 6px`, shows on `:hover`
- Color coding:
  - Dark green (#216e39): present, on time
  - Medium green (#40c463): present, slightly late (< 30 min)
  - Light green (#9be9a8): present, very late (> 30 min)
  - Gray (#ebedf0): absent or weekend
  - Orange (#f0a500): half-day (check-in but no check-out)
  - Red dot overlay: admin-edited record (small dot in corner)

### Navigation Sidebar (Admin)

```css
.sidebar {
  width: 240px;
  min-height: 100vh;
  background: #f6f8fa;
  border-right: 1px solid #d0d7de;
  padding: 16px 0;
}
.sidebar a {
  display: block;
  padding: 6px 16px;
  font-size: 14px;
  color: #24292f;
  text-decoration: none;
  border-radius: 6px;
  margin: 2px 8px;
}
.sidebar a:hover {
  background: #eaeef2;
}
.sidebar a.active {
  font-weight: 600;
  background: #eaeef2;
}
```

### What to Explicitly NOT Do

- ❌ No `card` class with `shadow` or `shadow-lg`
- ❌ No `rounded-pill` or `rounded-3` anywhere
- ❌ No `bg-primary`, `bg-success`, `bg-danger` Bootstrap color utilities
- ❌ No gradient backgrounds on any element
- ❌ No hero sections, no banner images
- ❌ No icons from Font Awesome or Bootstrap Icons
- ❌ No animation or transition on page load
- ❌ No Google Fonts or custom typefaces

### The Result

The UI will look like a tool built by engineers for engineers — dense, fast, readable, and completely unlike anything generated by AI using default Bootstrap settings.

---

## ANTI-CHEAT IMPLEMENTATION SUMMARY

All 8 rules implemented:

1. **Server Time Only**: Controllers call `Carbon::now()`, never trust browser time
2. **No Future Backdating**: AttendanceService validates stated_time (not future, not > 10 min past)
3. **One Check-in Per Day**: UNIQUE constraint + controller check before insert
4. **Check-out After Check-in**: Service validates check-in exists and check-out time > check-in time
5. **IP Address Logging**: Stored on every record, suspicious IP detection in admin view
6. **Audit Trail**: All admin edits logged with required reason field
7. **Rate Limiting**: 3 attempts per minute per IP on check-in/out routes
8. **CSRF on All Forms**: `@csrf` on every POST form

---

## FILE STRUCTURE SUMMARY

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
│   │   ├── User.php (edited)
│   │   ├── Student.php
│   │   ├── AttendanceLog.php
│   │   └── AuditTrail.php
│   └── Services/
│       └── AttendanceService.php
├── config/
│   └── attendance.php
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php (edited)
│   │   ├── 2025_04_21_000001_create_students_table.php
│   │   ├── 2025_04_21_000002_create_attendance_logs_table.php
│   │   ├── 2025_04_21_000003_create_audit_trail_table.php
│   │   └── 2025_04_21_000004_add_student_fk_to_users.php
│   └── seeders/
│       └── DatabaseSeeder.php
├── resources/
│   └── views/
│       ├── layouts/
│       │   ├── app.blade.php
│       │   └── admin.blade.php
│       ├── components/
│       │   └── attendance-heatmap.blade.php
│       ├── admin/
│       │   ├── dashboard.blade.php
│       │   ├── students/
│       │   │   ├── index.blade.php
│       │   │   ├── create.blade.php
│       │   │   └── show.blade.php
│       │   ├── attendance/
│       │   │   ├── index.blade.php
│       │   │   └── override.blade.php
│       │   └── audit-log.blade.php
│       └── student/
│           └── dashboard.blade.php
└── routes/
    └── web.php (edited)
```

---

## TESTING CHECKLIST

After implementation:

1. Create database in PgAdmin 4
2. Run migrations: `php artisan migrate --seed`
3. Start server: `php artisan serve`
4. Login as admin@school.com / Admin@123 → verify admin dashboard
5. Login as student1@school.com / Student@123 → verify student dashboard
6. Test check-in/check-out flow
7. Test anti-cheat rules (backdating, duplicate, future time)
8. Test admin override with reason
9. Verify audit trail entries
10. Verify heatmap displays correctly
11. Test rate limiting (rapid submissions)
