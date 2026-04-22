# Student Attendance System - Phase Report (Presentation Version)

Updated: April 22, 2026

This report gives a brief file description and the connectivity flow for each phase so the project can be presented clearly.

## Phase 1 - Foundation and Database

### Key Files

- `config/attendance.php`: Global attendance rules (time window, anti-cheat thresholds, face/liveness thresholds).
- `database/migrations/0001_01_01_000000_create_users_table.php`: Base authentication table.
- `database/migrations/0001_01_01_000001_create_cache_table.php`: Laravel cache tables.
- `database/migrations/2025_04_21_000001_create_students_table.php`: Core student table.
- `database/migrations/2025_04_21_000002_create_attendance_logs_table.php`: Attendance check-in/check-out table.
- `database/migrations/2025_04_21_000004_add_student_fk_to_users.php`: Adds `role` and `student_id` to users.
- `database/migrations/2026_04_22_000005_create_audit_trail_table.php`: Audit trail table for admin actions.
- `database/migrations/2026_04_22_000006_add_face_fields_to_students_and_attendance_logs.php`: Face signature + liveness metadata columns.
- `database/migrations/2026_04_22_000008_add_photo_path_to_students_table.php`: Student photo path.
- `database/migrations/2026_04_22_000009_add_unique_name_index_to_students_table.php`: Prevents duplicate same first+last name.
- `database/migrations/2026_04_22_000010_add_guardian_fields_to_students_table.php`: Adds `parent_name`, `father_name`, `mother_name`, `address`.

### Connectivity

- Migrations define schema used by models.
- `config/attendance.php` is consumed by `AttendanceService` to enforce logic consistently.

## Phase 2 - Models and Business Logic

### Key Files

- `app/Models/User.php`: User role helpers and student link.
- `app/Models/Student.php`: Student profile fields + relationships.
- `app/Models/AttendanceLog.php`: Attendance record model with biometric metadata.
- `app/Models/AuditTrail.php`: Change history model.
- `app/Exceptions/DuplicateAttendanceException.php`: Duplicate attendance guard.
- `app/Services/AttendanceService.php`: Core business rules (time validation, duplicate prevention, liveness + face match validation, suspicious IP logic, audit writes).

### Connectivity

- Controllers call `AttendanceService` for check-in/check-out.
- `AttendanceService` writes `AttendanceLog` and `AuditTrail` and reads `config/attendance.php`.
- Model relationships power admin/student dashboards.

## Phase 3 - Middleware, Routes, Controllers

### Key Files

- `app/Http/Middleware/RoleMiddleware.php`: Access control by role.
- `bootstrap/app.php`: Middleware alias registration.
- `app/Providers/AppServiceProvider.php`: Named rate limiter for attendance endpoints.
- `routes/web.php`: Public kiosk/self-registration routes + protected admin/student routes.
- `app/Http/Controllers/StudentDashboardController.php`: Kiosk attendance, face registration, server time API, authenticated student dashboard.
- `app/Http/Controllers/StudentSelfRegistrationController.php`: Public registration with image upload and duplicate-name checks.
- `app/Http/Controllers/AdminDashboardController.php`: Admin summary metrics.
- `app/Http/Controllers/AdminStudentController.php`: Admin student CRUD with duplicate-name prevention.
- `app/Http/Controllers/AdminAttendanceController.php`: Admin attendance pages, override, audit log.
- `app/Http/Controllers/Auth/LoginController.php`: Admin/student role-based login redirection.

### Connectivity

- Browser -> `routes/web.php` -> controller action.
- Controller validates input and delegates attendance rules to `AttendanceService`.
- Middleware + rate limiter protect admin endpoints and attendance actions.

## Phase 4 - Layout and Design System

### Key Files

- `resources/views/layouts/app.blade.php`: Shared dark modern theme, typography, colors, reusable utility styles.
- `resources/views/layouts/admin.blade.php`: Admin shell + sidebar navigation.
- `resources/views/components/attendance-heatmap.blade.php`: Reusable attendance visualization component.

### Connectivity

- All pages extend base layout.
- Admin pages extend admin layout.
- Dashboards inject heatmap via shared component.

## Phase 5 - Feature Views

### Public / Student Flow

- `resources/views/attendance/kiosk.blade.php`: Open kiosk, student dropdown, MediaPipe start/stop camera, liveness + face match, check-in/check-out forms.
- `resources/views/attendance/register-student.blade.php`: Public self-registration with first/last, parent/father/mother, address, email, phone, department, photo.
- `resources/views/student/dashboard.blade.php`: Logged-in student view with own attendance and MediaPipe verification.

### Admin Flow

- `resources/views/admin/dashboard.blade.php`: Daily summary cards and quick overview.
- `resources/views/admin/students/index.blade.php`: Internship student list.
- `resources/views/admin/students/create.blade.php`: Admin create student form.
- `resources/views/admin/students/edit.blade.php`: Admin update student profile/status.
- `resources/views/admin/students/show.blade.php`: Full student profile + attendance and biometric status.
- `resources/views/admin/attendance/index.blade.php`: Daily attendance board.
- `resources/views/admin/attendance/show.blade.php`: Student attendance detail + override.
- `resources/views/admin/attendance/audit-log.blade.php`: Audit entries.

### Connectivity

- View forms submit to controller routes.
- Controller returns success/error messages rendered by shared flash UI in layout.
- Kiosk and dashboard JS push verification payload into hidden form fields for server-side validation.

## Phase 6 - Seed, Test, Validate

### Key Files

- `database/seeders/DatabaseSeeder.php`: Seeds admin login account.
- `tests/Feature/ExampleTest.php`: Basic route behavior checks.
- `tests/Unit/ExampleTest.php`: Unit scaffold.

### Connectivity

- Seeder creates deterministic login data used in admin login demo.
- Tests verify app is reachable and route behavior is stable.

## End-to-End Connectivity Summary (One Line)

`UI Form (Blade + MediaPipe JS) -> Route -> Controller Validation -> AttendanceService Rules -> Model/DB Write -> Flash Response -> UI`

## Files Removed as Unnecessary

- `resources/views/welcome.blade.php`: Not used (root redirects to kiosk).
- `resources/views/auth/register.blade.php`: Not used (default public register disabled).
- `app/Http/Controllers/Auth/RegisterController.php`: Not used (registration routes disabled).
