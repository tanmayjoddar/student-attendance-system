# Attendance System — Official Documentation

## Version 1.0.0

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture Overview](#2-architecture-overview)
3. [Technology Stack](#3-technology-stack)
4. [Directory Structure](#4-directory-structure)
5. [Setup & Installation](#5-setup--installation)
6. [Configuration Reference](#6-configuration-reference)
7. [Database Schema](#7-database-schema)
8. [Route Map](#8-route-map)
9. [Authentication & Authorization](#9-authentication--authorization)
10. [Face Verification Pipeline](#10-face-verification-pipeline)
11. [User Flows](#11-user-flows)
12. [API Reference](#12-api-reference)
13. [User Interface / User Experience](#13-user-interface--user-experience)
14. [Maintenance & Troubleshooting](#14-maintenance--troubleshooting)
15. [Testing Strategy](#15-testing-strategy)
16. [Document Version History](#16-document-version-history)

---

## 1. Project Overview

The **Attendance System** is a Laravel 12-based biometric attendance tracking application that combines **client-side liveness detection** (MediaPipe FaceMesh via CDN WebAssembly) with an **external ML microservice** (FastAPI, port 8001) for deep-learning-based face identification. The system uses a **kiosk-first** approach: students register once (self-service or admin-created), then check in and out daily by looking at a kiosk camera. Face identification is automatic — no manual student selection required.

### Core Tenets

- **Kiosk-first**: No student login at the point of entry. Students simply look at the camera; the system identifies them and records attendance automatically.
- **Dual verification**: A two-layer approach — (1) client-side geometric landmark signatures (via MediaPipe, 80 landmarks → 160 normalized values) and (2) ML microservice deep-learning embeddings for cross-validation.
- **Liveness challenge**: 6 randomized challenges (blink, turn left, turn right, open mouth, nod, raise eyebrows). On each kiosk session, 2 challenges are selected at random to prevent replay attacks.
- **Full audit trail**: Every attendance mutation (create, override) is logged with old/new values, user ID, and IP address.
- **Geo-location tracking**: Multi-provider fallback chain — browser GPS → Nominatim reverse geocode → ipwho.is → ipapi.co → server-side IP geolocation.
- **GitHub-style UI**: Primitive Design System using Primer CSS patterns with CDN Bootstrap 5.3 for layout.

### Key Numbers

| Metric | Value |
|--------|-------|
| Face signature landmarks | 80 points normalized → 160 float values |
| Minimum signature length for registration | 140 values |
| Face match threshold | 60% (configurable via `ATTENDANCE_FACE_MIN_MATCH`) |
| Liveness score threshold | 75% (configurable via `ATTENDANCE_FACE_MIN_LIVENESS`) |
| Check-in window | 00:00 – 23:59 daily |
| Grace period for stated time | 15 minutes (configurable via `ATTENDANCE_GRACE_PERIOD`) |
| Rate limit | 5 attempts per minute per IP |
| Challenges per session | 2 (randomly selected from 6) |
| Challenge hold frames | 5 consecutive frames |
| Live signal minimum frames | 15 frames |

---

## 2. Architecture Overview

```
+-------------------------------------------------------------------+
|                        BROWSER (Kiosk)                            |
|                                                                   |
|  +-------------------+    +-------------------------------+       |
|  |  MediaPipe WASM   |    |  JavaScript Liveness Engine   |       |
|  |  FaceMesh         |--->|  - 6 challenge types          |       |
|  |  (CDN)            |    |  - 2 random per session       |       |
|  |                   |    |  - EAR calculation for blinks |       |
|  |  80 landmarks     |    |  - Yaw detection for turns   |       |
|  |  extracted per    |    |  - Mouth AR for open mouth   |       |
|  |  frame            |    |  - Pitch baseline for nods   |       |
|  +-------------------+    |  - Brow distance for eyebrows |       |
|                           +-------------------------------+       |
|                                     |                             |
|                                     v                             |
|                          +------------------------+               |
|                          |  Capture & Identify    |              |
|                          |  (canvas -> blob ->    |              |
|                          |   POST /identify/)     |              |
|                          +-----------+-----------+               |
+--------------------------------------+---------------------------+
                                       | HTTP :8001
                                       v
+-------------------------------------------------------------------+
|                  ML MICROSERVICE (FastAPI)                        |
|                                                                   |
|  +------------------------------------------------------------+   |
|  |  Endpoints:                                                |   |
|  |  POST /register/   -- Register face with 2 photos         |   |
|  |  POST /identify/   -- Identify student from live frame    |   |
|  |  DELETE /delete/   -- Remove face embeddings              |   |
|  +------------------------------------------------------------+   |
+-------------------------------------------------------------------+
                                       |
                                       v
+-------------------------------------------------------------------+
|                    LARAVEL APPLICATION                            |
|                                                                   |
|  +----------+  +-----------+  +------------+  +-----------+       |
|  |Kiosk     |  |Admin      |  |Student     |  |API Proxy  |       |
|  |Routes    |  |Routes     |  |Routes      |  |Routes     |       |
|  |(public)  |  |(auth+role)|  |(auth+role) |  |(throttled)|       |
|  +----+-----+  +-----+-----+  +-----+------+  +-----+-----+       |
|       |              |              |               |             |
|       v              v              v               v             |
|  +------------------------------------------------------------+   |
|  |              AttendanceService (528 lines)                  |   |
|  |  checkIn / checkOut / autoCheckIn / autoCheckOut /         |   |
|  |  adminOverride / getHeatmapData / getTodayStatus /         |   |
|  |  detectSuspiciousIPs / validateStatedTime /                |   |
|  |  validateFaceVerification / normalizeGeoData /             |   |
|  |  ipGeolocation / createAuditTrail / resolveSubmittedBy    |   |
|  +------------------------------------------------------------+   |
|                          |                                       |
|                          v                                       |
|  +------------------------------------------------------------+   |
|  |              MODELS                                         |   |
|  |  Student (51 lines)     face_signature as array cast        |   |
|  |  AttendanceLog (72 l.)  verification_meta as array cast     |   |
|  |  AuditTrail (34 l.)     old/new_values as array cast        |   |
|  |  User (66 lines)        isAdmin() / isStudent()             |   |
|  +------------------------------------------------------------+   |
|                          |                                       |
|                          v                                       |
|  +------------------------------------------------------------+   |
|  |              12 MIGRATIONS -> SQL Schema                    |   |
|  |  students, attendance_logs, audit_trail, users w/ FK        |   |
|  +------------------------------------------------------------+   |
+-------------------------------------------------------------------+
```

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| Client-side liveness (MediaPipe WASM) | Zero server load per frame; liveness checks happen entirely in-browser; network requests only for final identification |
| External ML microservice | Keeps Laravel lightweight; allows independent GPU scaling for deep learning inference |
| 6 randomized challenges, 2 per session | Balance between security and UX — too many challenges degrade throughput |
| Database-driven sessions | Required for API-based face identification flow (kiosk doesn't maintain session state across requests) |
| Multi-provider geo fallback | Maximizes reliability across network conditions; Nominatim for GPS→address, IP providers for non-GPS environments |
| Database transactions for attendance | Ensures attendance_log + audit_trail are created atomically — no partial writes |

---

## 3. Technology Stack

### Backend

| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 8.2.x | Runtime |
| Laravel | 12.x | Application framework |
| SQLite (dev) / PostgreSQL (prod) | — | Database driver |
| Composer | — | Dependency management |

### Frontend

| Technology | Version | Purpose |
|------------|---------|---------|
| Bootstrap | 5.3.3 (CDN) | Layout grid, responsive utilities, card components |
| @mediapipe/face_mesh | CDN | 468-landmark face mesh detection via WebAssembly |
| @mediapipe/camera_utils | CDN | Camera stream abstraction |
| @mediapipe/drawing_utils | CDN | Face mesh overlay drawing |
| Vite | Laravel bundle | JS asset compilation (minimal usage) |
| Primer CSS patterns | Custom (no CDN) | GitHub-style CSS variables, Box, Table, State, UnderlineNav, btn |

### ML Microservice (External)

| Technology | Purpose |
|------------|---------|
| FastAPI (Python) | Face identification API server on port 8001 |
| POST /register/ | Register face embeddings from 2 photos (multipart) |
| POST /identify/ | Identify student from a single live frame (multipart) |
| DELETE /delete/{user_id} | Remove face embeddings for a student |

---

## 4. Directory Structure

```
attendance-system/
├── app/
│   ├── Exceptions/
│   │   └── DuplicateAttendanceException.php    # HTTP 409 for duplicate check-in/out
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   └── LoginController.php         # AuthenticatesUsers trait, role-based redirect
│   │   │   ├── AdminDashboardController.php    # Stats: total, present, absent, flagged
│   │   │   ├── AdminAttendanceController.php   # Daily view, student detail, override, audit log
│   │   │   ├── AdminStudentController.php      # CRUD + CSV/PDF export + delete cascade
│   │   │   ├── StudentDashboardController.php  # Kiosk, check-in/out, auto-checkin/out, face register
│   │   │   ├── StudentSelfRegistrationController.php  # 2-photo self-registration flow
│   │   │   └── FaceVerificationController.php  # Proxy to ML service (register/delete)
│   │   └── Middleware/
│   │       └── RoleMiddleware.php             # Single-role gate check
│   ├── Models/
│   │   ├── Student.php                        # HasMany attendanceLogs, HasOne user
│   │   ├── AttendanceLog.php                  # BelongsTo student + submittedBy
│   │   ├── AuditTrail.php                     # BelongsTo user, polymorphic model_id
│   │   └── User.php                           # role field, isAdmin()/isStudent()
│   ├── Providers/
│   │   └── AppServiceProvider.php             # RateLimiter config (5/min per IP)
│   └── Services/
│       └── AttendanceService.php              # 11 methods, ~528 lines, business logic
├── bootstrap/
│   └── app.php                                # Middleware aliases, throttle config
├── config/
│   ├── app.php
│   ├── attendance.php                         # Check-in window, thresholds, rate limits
│   ├── auth.php
│   ├── database.php
│   └── session.php
├── database/
│   └── migrations/
│       ├── 0001_01_01_000000_create_users_table.php
│       ├── 0001_01_01_000001_create_cache_table.php
│       ├── 2025_04_21_000001_create_students_table.php
│       ├── 2025_04_21_000002_create_attendance_logs_table.php
│       ├── 2025_04_21_000004_add_student_fk_to_users.php
│       ├── 2026_04_22_000005_create_audit_trail_table.php
│       ├── 2026_04_22_000006_add_face_fields_to_students_and_attendance_logs.php
│       ├── 2026_04_22_000007_add_semester_to_students_table.php
│       ├── 2026_04_22_000008_add_photo_path_to_students_table.php
│       ├── 2026_04_22_000009_add_unique_name_index_to_students_table.php
│       ├── 2026_04_22_000010_add_guardian_fields_to_students_table.php
│       └── 2026_05_08_000011_add_geo_fields_to_attendance_logs_table.php
├── doc/
│   ├── OFFICIAL_DOCUMENTATION.md               # This file
│   ├── architecture.md
│   ├── attendance-camera-flow.md
│   ├── briliance.md
│   ├── face-verification.md
│   ├── fixed-implementation-plan.md
│   ├── phase-report.md
│   ├── planning.md
│   ├── QA.md
│   └── resume-metrics.md
├── resources/
│   └── views/
│       ├── layouts/
│       │   ├── app.blade.php                  # Main layout: header, flash, container
│       │   └── admin.blade.php                # Admin sidebar + main content
│       ├── auth/
│       │   └── login.blade.php                # Standard Bootstrap login form
│       ├── admin/
│       │   ├── dashboard.blade.php            # Stats, recent attendance, flagged records
│       │   ├── attendance/
│       │   │   ├── index.blade.php            # Day-at-a-glance table with in/out/status
│       │   │   ├── show.blade.php             # Student detail + override form
│       │   │   └── audit-log.blade.php        # Paginated audit trail table
│       │   └── students/
│       │       ├── index.blade.php            # Student list + export + delete modal
│       │       ├── create.blade.php
│       │       ├── edit.blade.php
│       │       ├── show.blade.php
│       │       └── _export_table.blade.php
│       ├── attendance/
│       │   ├── kiosk.blade.php                # 775-line kiosk with MediaPipe JS
│       │   └── register-student.blade.php     # Self-registration with 2-photo capture
│       ├── student/
│       │   └── dashboard.blade.php            # Student dashboard with verification, heatmap
│       └── components/
│           └── attendance-heatmap.blade.php   # GitHub-style contribution graph
├── routes/
│   ├── console.php
│   └── web.php                                # All 84 lines of route definitions
└── tests/                                     # (not yet created)
```

---

## 5. Setup & Installation

### Prerequisites

- PHP 8.2+
- Composer
- Node.js & npm (for Vite asset compilation)
- SQLite (dev) or PostgreSQL (production)
- Python 3.10+ with FastAPI (for ML microservice)

### Installation Steps

```bash
# 1. Clone the repository
git clone <repository-url> attendance-system
cd attendance-system

# 2. Install PHP dependencies
composer install

# 3. Install Node dependencies (for Vite)
npm install

# 4. Environment configuration
cp .env.example .env
# Edit .env: set DB_CONNECTION, APP_URL, etc.

# 5. Generate application key
php artisan key:generate

# 6. Run migrations with seed
php artisan migrate --seed

# 7. Build frontend assets
npm run build

# 8. Start development server
php artisan serve

# 9. (Optional) Start ML microservice
# cd ../ml-service && uvicorn main:app --reload --port 8001
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `AttendanceHub` | Application display name |
| `DB_CONNECTION` | `sqlite` | Database driver |
| `ATTENDANCE_FACE_MIN_MATCH` | `60` | Minimum face match score (0–100) |
| `ATTENDANCE_FACE_MIN_LIVENESS` | `75` | Minimum liveness score (0–100) |
| `ATTENDANCE_GRACE_PERIOD` | `15` | Grace period in minutes for stated time |
| `ATTENDANCE_MIN_DURATION` | `30` | Minimum check-in duration in minutes |
| `ML_SERVICE_URL` | `http://127.0.0.1:8001` | FastAPI ML microservice base URL |

### Seeded Admin Account

| Field | Value |
|-------|-------|
| Email | `nic@admin` |
| Password | `bgh123` |
| Role | `super_admin` |

---

## 6. Configuration Reference

### `config/attendance.php`

```php
return [
    'check_in_window' => [
        'start' => env('ATTENDANCE_WINDOW_START', '00:00'),
        'end'   => env('ATTENDANCE_WINDOW_END', '23:59'),
    ],
    'grace_period_minutes' => env('ATTENDANCE_GRACE_PERIOD', 15),
    'min_checkin_duration' => env('ATTENDANCE_MIN_DURATION', 30),
    'rate_limit' => [
        'max_attempts' => env('ATTENDANCE_RATE_LIMIT', 5),
        'decay_minutes' => 1,
    ],
    'face' => [
        'min_match_score'    => env('ATTENDANCE_FACE_MIN_MATCH', 60),
        'min_liveness_score' => env('ATTENDANCE_FACE_MIN_LIVENESS', 75),
    ],
];
```

### Rate Limiter Configuration

The `attendance` rate limiter is registered in two places:

**`app/Providers/AppServiceProvider.php`** (line 25–28):
```php
RateLimiter::for('attendance', function (Request $request) {
    return Limit::perMinute((int) config('attendance.rate_limit.max_attempts', 5))
        ->by($request->ip());
});
```

**`bootstrap/app.php`** (line 18):
```php
$middleware->alias([
    'role' => \App\Http\Middleware\RoleMiddleware::class,
    'attendance' => 'throttle:5,1', // 5 attempts per minute for attendance
]);
```

The middleware alias `attendance` is applied to:
- `POST /attendance/check-in`
- `POST /attendance/check-out`
- `POST /attendance/face-register`
- `POST /student-register`
- `POST /attendance/auto-checkin`
- `POST /attendance/auto-checkout`
- `POST /student/check-in`
- `POST /student/check-out`
- `POST /student/face-register`

### Middleware Aliases

| Alias | Actual | Definition Location |
|-------|--------|---------------------|
| `role` | `App\Http\Middleware\RoleMiddleware` | `bootstrap/app.php:17` |
| `attendance` | `throttle:5,1` | `bootstrap/app.php:18` |

---

## 7. Database Schema

### Entity-Relationship Summary

```
users ---1:1--- students ---1:N--- attendance_logs
  |                                      |
  +---------- AuditTrail (user_id) ------+
```

### Table: `students`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `bigint unsigned` | PK, auto-increment | |
| `student_id` | `string` | UNIQUE, NOT NULL | Format: `STU-YYMMDD-XXXX` |
| `first_name` | `string(255)` | NOT NULL | |
| `last_name` | `string(255)` | NOT NULL | |
| `parent_name` | `string(255)` | NULLABLE | Added in migration 000010 |
| `father_name` | `string(255)` | NULLABLE | Added in migration 000010 |
| `mother_name` | `string(255)` | NULLABLE | Added in migration 000010 |
| `address` | `text` | NULLABLE | Added in migration 000010 |
| `email` | `string(255)` | UNIQUE, NOT NULL | |
| `phone` | `string(20)` | NULLABLE | |
| `department` | `string(255)` | NULLABLE | |
| `semester` | `string(255)` | NULLABLE | Added in migration 000007 |
| `photo_path` | `string(255)` | NULLABLE | Storage path for student photo; added in migration 000008 |
| `is_active` | `boolean` | DEFAULT `true` | |
| `face_signature` | `json` | NULLABLE | Array of 160 normalized float values (80 landmarks × 2); added in migration 000006 |
| `face_registered_at` | `timestamp` | NULLABLE | Added in migration 000006 |
| `created_at` | `timestamp` | | |
| `updated_at` | `timestamp` | | |

**Unique indexes:**
- `students_student_id_unique` on `student_id`
- `students_email_unique` on `email`
- `students_first_last_unique` on `(first_name, last_name)` — Added in migration 000009

### Table: `attendance_logs`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `bigint unsigned` | PK, auto-increment | |
| `student_id` | `bigint unsigned` | FK → students.id, CASCADE ON DELETE | |
| `date` | `date` | NOT NULL | |
| `type` | `enum('in', 'out')` | NOT NULL | |
| `recorded_time` | `timestamp` | NOT NULL | Server time when record was created |
| `stated_time` | `timestamp` | NULLABLE | User-declared time (anti-cheat) |
| `ip_address` | `string` (default: 255) | NOT NULL | Client IP; no length constraint in migration |
| `geo_address` | `text` | NULLABLE | Human-readable address; added in migration 000011 |
| `geo_latitude` | `decimal(10,7)` | NULLABLE | Added in migration 000011 |
| `geo_longitude` | `decimal(10,7)` | NULLABLE | Added in migration 000011 |
| `geo_accuracy` | `decimal(8,2)` | NULLABLE | GPS accuracy in meters; added in migration 000011 |
| `is_flagged` | `boolean` | DEFAULT `false` | Set to `true` on admin override |
| `face_verified` | `boolean` | DEFAULT `false` | Added in migration 000006 |
| `liveness_score` | `decimal(5,2)` | NULLABLE | 0.00–100.00; added in migration 000006 |
| `verification_meta` | `json` | NULLABLE | Match score, spoof check, blink count, yaw variance, timestamp; added in migration 000006 |
| `submitted_by` | `bigint unsigned` | FK → users.id | Resolved via `resolveSubmittedByUserId()` |
| `created_at` | `timestamp` | | |
| `updated_at` | `timestamp` | | |

**Unique index:**
- `attendance_logs_student_id_date_type_unique` on `(student_id, date, type)` — Prevents duplicate check-in or check-out per student per day

### Table: `audit_trail`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `bigint unsigned` | PK, auto-increment | |
| `action` | `string(255)` | NOT NULL | Values: `create`, `override` |
| `model_type` | `string(255)` | NOT NULL | Fully qualified class name |
| `model_id` | `bigint unsigned` | NOT NULL | ID of the affected record |
| `old_values` | `json` | NULLABLE | Snapshot before mutation |
| `new_values` | `json` | NULLABLE | Snapshot after mutation |
| `user_id` | `bigint unsigned` | NULLABLE, FK → users.id, NULL ON DELETE | |
| `ip_address` | `string(45)` | NULLABLE | |
| `created_at` | `timestamp` | | |
| `updated_at` | `timestamp` | | |

**Index:** `(model_type, model_id)`

### Table: `users` (additional columns beyond Laravel default)

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `role` | `string(255)` | DEFAULT `'student'` | Values: `super_admin`, `student`; added in migration 000004 |
| `student_id` | `bigint unsigned` | NULLABLE, FK → students.id, NULL ON DELETE | Added in migration 000004 |

**Cast:** `password` → `hashed`

### Table: `cache`

Standard Laravel cache table (migration 000001).

---

## 8. Route Map

### Public Kiosk Routes (Unauthenticated)

| Method | URI | Name | Controller Method | Middleware |
|--------|-----|------|-------------------|-----------|
| `GET` | `/` | — | Redirect → `attendance.kiosk` | None |
| `GET` | `/attendance` | `attendance.kiosk` | `StudentDashboardController@kiosk` | None |
| `POST` | `/attendance/check-in` | `attendance.check-in` | `StudentDashboardController@checkInPublic` | `throttle:attendance` |
| `POST` | `/attendance/check-out` | `attendance.check-out` | `StudentDashboardController@checkOutPublic` | `throttle:attendance` |
| `GET` | `/attendance/check-in` | — | Closure: redirect with error to kiosk | None |
| `GET` | `/attendance/check-out` | — | Closure: redirect with error to kiosk | None |
| `POST` | `/attendance/face-register` | `attendance.face-register` | `StudentDashboardController@registerFace` | `throttle:attendance` |
| `GET` | `/student-register` | `student.register` | `StudentSelfRegistrationController@create` | None |
| `POST` | `/student-register` | `student.register.store` | `StudentSelfRegistrationController@store` | `throttle:attendance` |

### Auto Identification Routes

| Method | URI | Name | Controller Method | Middleware |
|--------|-----|------|-------------------|-----------|
| `POST` | `/attendance/auto-checkin` | `attendance.auto-checkin` | `StudentDashboardController@autoCheckIn` | `throttle:attendance` |
| `POST` | `/attendance/auto-checkout` | `attendance.auto-checkout` | `StudentDashboardController@autoCheckOut` | `throttle:attendance` |

### API Utility Routes

| Method | URI | Name | Controller Method | Middleware |
|--------|-----|------|-------------------|-----------|
| `GET` | `/api/server-time` | `api.server-time` | `StudentDashboardController@serverTime` | None |

### ML Face Verification Proxy Routes

| Method | URI | Name | Controller Method | Middleware | Status |
|--------|-----|------|-------------------|-----------|--------|
| `POST` | `/api/verify-face-ml` | — | `FaceVerificationController@verify` | None | Defined in routes, method NOT YET IMPLEMENTED in controller |
| `POST` | `/api/register-face-ml` | — | `FaceVerificationController@register` (exists) | None | Implemented |
| `POST` | `/api/identify-face` | — | `FaceVerificationController@identify` | None | Defined in routes, method NOT YET IMPLEMENTED in controller |
| `DELETE` | `/api/delete-face-ml/{studentId}` | — | `FaceVerificationController@deleteFromMl` (exists) | None | Implemented |

> **Note:** The kiosk JavaScript calls `http://127.0.0.1:8001/identify/` directly from the browser (not through the Laravel proxy). The `/api/identify-face` and `/api/verify-face-ml` routes are defined in `routes/web.php` but their corresponding controller methods have not yet been implemented.

### Authentication Routes (Laravel Auth Scaffold)

| Method | URI | Name | Controller | Middleware |
|--------|-----|------|------------|-----------|
| `GET` | `/login` | `login` | `LoginController@showLoginForm` | `guest` |
| `POST` | `/login` | — | `LoginController@login` | `guest` |
| `POST` | `/logout` | `logout` | `LoginController@logout` | `auth` |

> Registration is disabled: `Auth::routes(['register' => false])`

### Admin Routes (Middleware: `auth` + `role:super_admin`)

| Method | URI | Name | Controller Method |
|--------|-----|------|-------------------|
| `GET` | `/admin/dashboard` | `admin.dashboard` | `AdminDashboardController@index` |
| `GET` | `/admin/students` | `admin.students.index` | `AdminStudentController@index` |
| `GET` | `/admin/students/create` | `admin.students.create` | `AdminStudentController@create` |
| `POST` | `/admin/students` | `admin.students.store` | `AdminStudentController@store` |
| `GET` | `/admin/students/{student}` | `admin.students.show` | `AdminStudentController@show` |
| `GET` | `/admin/students/{student}/edit` | `admin.students.edit` | `AdminStudentController@edit` |
| `PUT/PATCH` | `/admin/students/{student}` | `admin.students.update` | `AdminStudentController@update` |
| `DELETE` | `/admin/students/{student}` | `admin.students.destroy` | `AdminStudentController@destroy` |
| `GET` | `/admin/students-export/csv` | `admin.students.export.csv` | `AdminStudentController@exportCsv` |
| `GET` | `/admin/students-export/pdf` | `admin.students.export.pdf` | `AdminStudentController@exportPdf` |
| `GET` | `/admin/attendance` | `admin.attendance` | `AdminAttendanceController@index` |
| `GET` | `/admin/attendance/{studentId}` | `admin.attendance.show` | `AdminAttendanceController@show` |
| `POST` | `/admin/attendance/{logId}/override` | `admin.attendance.override` | `AdminAttendanceController@override` |
| `GET` | `/admin/audit-log` | `admin.audit-log` | `AdminAttendanceController@auditLog` |

### Student Routes (Middleware: `auth` + `role:student`)

| Method | URI | Name | Controller Method | Extra Middleware |
|--------|-----|------|-------------------|-----------------|
| `GET` | `/student/dashboard` | `student.dashboard` | `StudentDashboardController@index` | None |
| `POST` | `/student/check-in` | `student.check-in` | `StudentDashboardController@checkIn` | `throttle:attendance` |
| `POST` | `/student/check-out` | `student.check-out` | `StudentDashboardController@checkOut` | `throttle:attendance` |
| `POST` | `/student/face-register` | `student.face-register` | `StudentDashboardController@registerFace` | `throttle:attendance` |

### Role-Based Redirect After Login

Defined in `LoginController@redirectTo()` (line 28–35):

```
super_admin → route('admin.dashboard')
student     → route('student.dashboard')
```

---

## 9. Authentication & Authorization

### Login Flow

1. User visits `/login` (guest middleware).
2. POST credentials to `/login`.
3. `LoginController` uses `AuthenticatesUsers` trait (Laravel default).
4. After successful authentication, `redirectTo()` checks `auth()->user()->role`:
   - `super_admin` → `/admin/dashboard`
   - `student` → `/student/dashboard`
5. Logout is the only auth-protected operation on the login controller.

### Role Middleware

`RoleMiddleware` at `app/Http/Middleware/RoleMiddleware.php`:

```php
public function handle(Request $request, Closure $next, string $role): Response
{
    if (!auth()->check()) {
        return redirect()->route('login');
    }
    if (auth()->user()->role !== $role) {
        abort(403, 'Unauthorized action.');
    }
    return $next($request);
}
```

- Registered as the `role` alias in `bootstrap/app.php`.
- Takes a single parameter: the required role string.
- Unauthenticated users are redirected to login.
- Authenticated users with wrong role receive HTTP 403.

### Role Guarded Route Groups

```
/admin/*       → middleware: ['auth', 'role:super_admin']
/student/*     → middleware: ['auth', 'role:student']
/attendance/*  → public (no auth required)
```

### User Roles

| Role | Capabilities |
|------|-------------|
| `super_admin` | Dashboard, student CRUD, attendance view/override, audit log, CSV/PDF export, delete students |
| `student` | Personal dashboard, check-in/out with face verification, face registration, heatmap view |

---

## 10. Face Verification Pipeline

### 10.1 Architecture Overview

The face verification system uses a **dual-layer approach**:

```
+-------------------------------------------------------------------+
|  LAYER 1: Client-Side (MediaPipe FaceMesh WASM)                   |
|                                                                    |
|  1. 468 facial landmarks detected per frame                        |
|  2. 80 specific landmarks extracted for signature                  |
|  3. Normalized to 160 float values (x,y pairs / eyeDist, faceH)   |
|  4. Liveness challenges executed locally (no server round-trip)    |
|  5. Geometric signature stored in students.face_signature (JSON)   |
+-------------------------------------------------------------------+
                              |
                              v
+-------------------------------------------------------------------+
|  LAYER 2: ML Microservice (FastAPI on :8001)                      |
|                                                                    |
|  1. Two photos uploaded during registration                       |
|  2. Deep learning embeddings generated and stored                  |
|  3. Live frame sent to POST /identify/ for identification          |
|  4. Returns user_id + confidence score                            |
|  5. DELETE /delete/{user_id} removes embeddings on student delete  |
+-------------------------------------------------------------------+
```

### 10.2 Challenge System (Kiosk)

**6 challenge types** defined in `resources/views/attendance/kiosk.blade.php`:

| # | Challenge ID | Instruction | Detection Logic |
|---|-------------|-------------|-----------------|
| 1 | `blink` | "Blink your eyes" | Eye Aspect Ratio < 0.20 indicates closure; EAR ≥ 0.22 after closure = blink complete; requires 8 hold frames |
| 2 | `turn_left` | "Turn your head LEFT" | Nose tip (lm[1].x) — eye midpoint (lm[33].x + lm[263].x / 2) < -0.04 |
| 3 | `turn_right` | "Turn your head RIGHT" | Nose tip (lm[1].x) — eye midpoint > 0.04 |
| 4 | `open_mouth` | "Open your mouth wide" | Vertical distance between lip landmarks 13 and 14 > 0.04 |
| 5 | `nod` | "Nod your head DOWN" | Pitch (lm[1].y — lm[10].y) — baseline > 0.03 |
| 6 | `raise_eyebrows` | "Raise your eyebrows UP" | Brow distance (lm[159].y — lm[70].y) — baseline (averaged over 20 frames) > 0.018 |

**Selection algorithm** (`pickChallenges()`):
```javascript
function pickChallenges() {
    return [...CHALLENGES].sort(() => Math.random() - 0.5).slice(0, 2);
}
```
2 challenges are selected uniformly at random. Both must be completed before identification proceeds.

**Completion criteria**:
- Each challenge must maintain completion state for 5 consecutive frames (`challengeHoldFrames >= 5`).
- A minimum of 15 live frames (`liveFrames >= 15`) must have been processed before identification triggers.
- Liveness score = (challenge 1 done ? 50 : 0) + (challenge 2 done ? 50 : 0).

### 10.3 Identification Flow (Kiosk)

```
1. Both challenges completed + 15 live frames
2. 5-second countdown overlay displayed
3. Canvas capture of current frame (JPEG, 0.92 quality)
4. POST blob to http://127.0.0.1:8001/identify/
5. If matched:
   a. POST /attendance/auto-checkin with {student_id, liveness_score, match_score, geo}
   b. If already checked in → POST /attendance/auto-checkout
6. If not matched → 5-second retry timer
7. 8-second success display → auto-reset for next student
```

### 10.4 Face Signature Extraction (Registration)

Used in `register-student.blade.php` `extractSignature()` function (line 181–205):

```javascript
function extractSignature(landmarks) {
    // 80 landmark indices selected from 468
    const idx = [1,33,263,61,291,199,152,10,234,454, ...]; // 80 indices
    const le = landmarks[33], re = landmarks[263];
    const ch = landmarks[152], fh = landmarks[10];
    const eyeDist = dist(le, re) || 1;
    const faceH   = dist(ch, fh) || 1;
    const cx = (le.x + re.x) / 2;
    const cy = (le.y + re.y) / 2;

    const v = [];
    idx.forEach(i => {
        v.push((landmarks[i].x - cx) / eyeDist);
        v.push((landmarks[i].y - cy) / faceH);
    });
    return v.map(n => Number(n.toFixed(6)));
}
```

The resulting array contains 160 float values (80 landmarks × 2 coordinates) normalized by eye distance for x and face height for y, centered around the eye midpoint. This is stored as JSON in `students.face_signature`.

### 10.5 Face Match Score (Student Dashboard)

The student dashboard uses **cosine similarity** between the live extracted signature and the stored signature:

```javascript
function similarityScore(a, b) {
    let dot = 0, magA = 0, magB = 0;
    for (let i = 0; i < a.length; i++) {
        dot += a[i] * b[i];
        magA += a[i] * a[i];
        magB += b[i] * b[i];
    }
    const denom = Math.sqrt(magA) * Math.sqrt(magB) || 1;
    const cosine = dot / denom;
    return Math.max(0, Math.min(100, ((cosine + 1) / 2) * 100));
}
```

This maps cosine similarity (range -1 to 1) to a 0–100 percentage.

### 10.6 Verification Validation (Server-Side)

`AttendanceService@validateFaceVerification()` (lines 504–527) enforces:

1. `face_verified` must be `true`.
2. `spoof_passed` must be `true`.
3. `liveness_score` >= `config('attendance.face.min_liveness_score')` (default: 75).
4. `match_score` >= `config('attendance.face.min_match_score')` (default: 60).

If any check fails, an `InvalidArgumentException` is thrown. The auto-checkin/auto-checkout methods (`autoCheckIn`, `autoCheckOut`) skip this validation since the ML microservice identification IS the verification.

### 10.7 Student Dashboard Liveness (Student-Logged-In Flow)

The student dashboard (`student/dashboard.blade.php`) uses a different, simpler liveness model:

| Metric | Threshold | Weight |
|--------|-----------|--------|
| Blink count | ≥ 1 blink | 45 points |
| Yaw variance | Scaled by 1800 | Up to 55 points |
| Live frames | ≥ 15 | Gate |
| Overall threshold | ≥ 70 | Pass |

```
livenessScore = min(100, (blinkCount >= 1 ? 45 : 0) + min(55, yawVariance * 1800))
```

Verification expires after 60 seconds (`verificationAt` timestamp check). The submission buttons are disabled unless verification is fresh and active.

---

## 11. User Flows

### 11.1 Kiosk Auto Identification Flow

```
[Student approaches kiosk]
    |
    v
[Page loads with camera off]
    |
    v
[Student presses "Start Camera"]
    |
    +-- MediaPipe FaceMesh initializes (CDN WASM)
    +-- GPS location request sent (async)
    |   +-- GPS success -> Nominatim reverse geocode
    |   +-- GPS fail -> ipwho.is -> ipapi.co
    |   +-- All fail -> geo_address = null
    |
    v
[Face detected in frame]
    |
    +-- 2 random challenges selected
    |
    v
[Challenge 1 displayed on screen]
    |
    |   +-----------------------+
    |   | e.g., "Turn your head |
    |   |        LEFT"          |
    |   +-----------------------+
    |
    v
[Student performs challenge]
    |
    +-- Challenge check runs per frame
    +-- 5 consecutive frames of success required
    |
    v
[Challenge 1 complete -> Challenge 2 displayed]
    |
    |   +-----------------------+
    |   | e.g., "Blink your eyes"|
    |   +-----------------------+
    |
    v
[Both challenges complete + 15 live frames]
    |
    +-- Liveness score = 100 (50+50)
    |
    v
[5-second countdown overlay]
    |
    v
[Canvas capture (JPEG)]
    |
    v
[POST /identify/ to ML service :8001]
    |
    +-- Matched? --yes--> POST /attendance/auto-checkin
    |                           |
    |                           +-- Success -> "Check-In Successful"
    |                           |
    |                           +-- Already checked in?
    |                                   |
    |                                   v
    |                           POST /attendance/auto-checkout
    |                                   |
    |                                   +-- Success -> "Check-Out Successful"
    |                                   +-- Already out -> "Already Recorded"
    |
    +-- Not matched? --> "Not Recognized" -> reset after 5 seconds
```

### 11.2 Admin Manual Attendance Override Flow

```
[Admin views attendance for a date]
    |
    v
[Admin clicks "Details" on a student row]
    |
    v
[Student attendance detail page shows all logs for that date]
    |
    v
[Admin fills override form: new_time + reason (min 10 chars)]
    |
    v
[POST /admin/attendance/{logId}/override]
    |
    +-- Validate input (new_time: required|date, reason: required|string|min:10|max:500)
    |
    +-- AttendanceService@adminOverride() called
    |   +-- Find log by ID
    |   +-- Snapshot old values
    |   +-- Update: stated_time = new_time, is_flagged = true
    |   +-- Create audit trail entry with action = 'override'
    |   |   +-- old_values = snapshot before change
    |   |   +-- new_values = snapshot after change
    |   |   +-- reason included in new_values
    |   +-- Return updated log
    |
    +-- Redirect back with success message
```

### 11.3 Student Self-Registration Flow

```
[New student visits /student-register]
    |
    v
[Student fills: first_name, last_name, father_name, mother_name,
 address, email, phone, department]
    |
    v
[Student clicks "Open Camera"]
    |
    +-- getUserMedia({video: 320x240, facingMode: 'user'})
    |
    v
[Student clicks "Capture Photo 1"]
    |
    +-- Canvas drawImage from live video
    +-- Photo 1 base64 stored in hidden input
    +-- Preview shown with green border
    +-- Overlay: "Now SLIGHTLY turn your head"
    |
    v
[Student clicks "Capture Photo 2"]
    |
    +-- Canvas drawImage (slight head turn)
    +-- Photo 2 base64 stored in hidden input
    +-- Camera stream stopped
    +-- Face signature extracted from Photo 1 via MediaPipe
    |
    v
[MediaPipe processes Photo 1]
    |
    +-- 80 landmark coordinates -> 160 normalized values
    +-- Serialized as JSON in hidden input
    |
    v
[Student clicks "Register Myself"]
    |
    +-- POST /student-register
    |
    +-- Server-side validation:
    |   +-- All required fields present
    |   +-- Email unique check
    |   +-- Full name uniqueness check (case-insensitive)
    |   +-- Base64 photo decoding and validation
    |   +-- Face signature: JSON decode, array length >= 140, all numeric
    |
    +-- Student ID generated: STU-YYMMDD-XXXX (e.g., STU-260604-A7K2)
    |
    +-- Photo 1 saved to storage/app/public/students/{studentId}.jpg
    |
    +-- Student record created with all fields + face_signature + face_registered_at
    |
    +-- ML microservice registration (timeout=1s, fire-and-forget):
    |   +-- Temp files created for both photos
    |   +-- POST http://127.0.0.1:8001/register/ (multipart)
    |   |   +-- image1, image2 as file attachments
    |   |   +-- user_id = studentId
    |   +-- Temp files cleaned up on completion
    |   +-- Failure logged as warning, does not block registration
    |
    +-- Redirect to kiosk with success message + student ID
```

### 11.4 Student Deletion Flow (Admin)

```
[Admin clicks "Delete" on a student row]
    |
    v
[Confirmation modal appears with student details + warning]
    |
    +-- Lists what will be permanently deleted:
    |   +-- All attendance records
    |   +-- Face recognition data and embeddings
    |   +-- Student photo from storage
    |   +-- User account and login credentials
    |   +-- Student profile record
    |
    v
[Admin confirms -> DELETE /admin/students/{student}]
    |
    +-- AdminStudentController@destroy() executes:
    |
    |   1. DELETE http://127.0.0.1:8001/delete/{studentId} (ML microservice)
    |      +-- Failure is logged as warning, does NOT block deletion
    |
    |   2. Storage::disk('public')->delete($student->photo_path)
    |
    |   3. $student->attendanceLogs()->delete()
    |
    |   4. $student->user->delete()
    |
    |   5. $student->delete()
    |
    +-- Redirect to student list with success message
```

### 11.5 Suspicious IP Detection Flow

The `detectSuspiciousIPs()` method in `AttendanceService` (lines 416–441):

1. Queries all check-in logs ordered by IP and recorded time.
2. Groups logs by IP address.
3. For each IP, checks if any 120-second sliding window contains ≥ 5 check-ins.
4. Returns `[ip_address => [log_id, ...]]` for matching IPs.

This is displayed as a flash warning on the admin attendance page:

```
Suspicious IP activity detected: 192.168.x.x
```

---

## 12. API Reference

### 12.1 Attendance Endpoints

#### POST /attendance/check-in (Public Kiosk)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `student_id` | `string` | Yes | Student ID (exists in students table) |
| `stated_time` | `date` | No | User-declared time (anti-cheat) |
| `face_verified` | `boolean` | Yes | Must be `true` |
| `spoof_passed` | `boolean` | Yes | Must be `true` |
| `liveness_score` | `numeric` | Yes | 0–100 |
| `match_score` | `numeric` | No | 0–100 |
| `blink_count` | `integer` | No | ≥ 0 |
| `yaw_variance` | `numeric` | No | ≥ 0 |
| `geo_address` | `string` | No | Max 1000 chars |
| `geo_latitude` | `numeric` | No | -90 to 90 |
| `geo_longitude` | `numeric` | No | -180 to 180 |
| `geo_accuracy` | `numeric` | No | 0–100000 |

**Responses:**
- `302` Redirect with success message
- `302` Redirect with error message (exception)

**Validation:**
- Outside check-in hours → `InvalidArgumentException`
- Already checked in today → `DuplicateAttendanceException` (HTTP 409)
- Face verification failed → `InvalidArgumentException`

#### POST /attendance/auto-checkin (Kiosk API)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `student_id` | `string` | Yes | Student ID |
| `liveness_score` | `numeric` | Yes | 0–100 |
| `match_score` | `numeric` | Yes | 0–100 |
| `geo_address` | `string` | No | |
| `geo_latitude` | `numeric` | No | |
| `geo_longitude` | `numeric` | No | |
| `geo_accuracy` | `numeric` | No | |

**Success Response (200):**
```json
{
  "ok": true,
  "type": "check_in",
  "message": "Check-in recorded at 10:30 AM",
  "time": "10:30 AM",
  "date": "04 Jun 2026",
  "student": "John Doe"
}
```

**Already Done Response (409):**
```json
{
  "ok": false,
  "type": "already_done",
  "message": "Already checked in today at 10:30 AM"
}
```

**Validation Error Response (422):**
```json
{
  "ok": false,
  "message": "Outside allowed check-in hours (00:00 - 23:59)"
}
```

#### POST /attendance/check-out / POST /attendance/auto-checkout

Same parameter structure as check-in equivalents. Difference: requires an existing check-in for today; errors with `InvalidArgumentException` if no check-in exists (`"No check-in found for today"` or `"No check-in found for today. Check in first."`).

### 12.2 Registration Endpoints

#### POST /student-register

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `first_name` | `string` | Yes | Max 255 |
| `last_name` | `string` | Yes | Max 255 |
| `father_name` | `string` | Yes | Max 255 |
| `mother_name` | `string` | Yes | Max 255 |
| `address` | `string` | Yes | Max 1000 |
| `email` | `email` | Yes | Unique in students table |
| `phone` | `string` | No | Max 20 |
| `department` | `string` | No | Max 255 |
| `face_photo_data_1` | `string` | Yes | Base64 JPEG (may include data URI prefix) |
| `face_photo_data_2` | `string` | Yes | Base64 JPEG with slight head turn |
| `face_signature` | `string` | Yes | JSON array of 160+ float values |

**Validation Rules:**
- Full name (first + last) must be unique (case-insensitive comparison via `WHERE LOWER(first_name) = ? AND LOWER(last_name) = ?`).
- Face signature: JSON-decodable array, minimum 140 elements, all numeric.
- Both photos: valid base64, decodes to non-false value.

**Success:** Redirect to kiosk with `"Registration complete. Your student ID is STU-YYMMDD-XXXX. You can now use the attendance kiosk."`

### 12.3 Face Registration Endpoints

#### POST /attendance/face-register

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `signature` | `array` | Yes | Min 160 numeric values |
| `student_id` | `string` | No | Required if not authenticated |

**Logic:**
1. If user is authenticated and has a student profile → use that student.
2. Else if `student_id` provided → look up by student_id.
3. Otherwise → 422 "Student not found for face registration."

**Success Response (200):**
```json
{
  "ok": true,
  "message": "Face profile registered successfully."
}
```

#### POST /api/register-face-ml (Multipart)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `student_id` | `string` | Yes | Exists in students table |
| `image1` | `file` | Yes | JPEG/PNG, max 2MB |
| `image2` | `file` | Yes | JPEG/PNG, max 2MB |

**Success (200):**
```json
{
  "ok": true,
  "message": "Face registered in ML service."
}
```

**Failure (503):** `"ML service unreachable: ..."`

#### DELETE /api/delete-face-ml/{studentId}

**Response:**
```json
{
  "ok": true,
  "message": "..."
}
```

### 12.4 Admin Override Endpoint

#### POST /admin/attendance/{logId}/override

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `new_time` | `date` | Yes | New timestamp for the log |
| `reason` | `string` | Yes | 10–500 characters |

**Effect:**
- Updates `stated_time` to the new time.
- Sets `is_flagged = true`.
- Creates audit trail entry with action `"override"`, old_values, and new_values.

### 12.5 Utility Endpoints

#### GET /api/server-time

**Response (200):**
```json
{
  "time": "14:30:00",
  "iso": "2026-06-04T14:30:00+05:30"
}
```

---

## 13. User Interface / User Experience

### Visual Theme

- **GitHub Primer-inspired** — Custom CSS variables define the full Primer color system: `--color-canvas-default`, `--color-border-default`, `--color-accent-fg`, `--color-success-fg`, `--color-danger-fg`, etc.
- **Light theme only** — All pages use a white/light-gray background.
- **Dark header** — Top navigation bar uses `#24292f` (GitHub dark) with white text and accent buttons.
- **Typography** — System font stack: `-apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif`.

### Layouts

| Layout | Used For | Features |
|--------|----------|----------|
| `layouts.app` | Kiosk, student dashboard, login, self-registration | Dark header, centered container (max 1280px), flash messages |
| `layouts.admin` | Admin dashboard, student management, attendance, audit log | Sticky left sidebar (250px) with nav links, right content area |

### Navigation

**Kiosk page header:**
- App name (links to home)
- Guest: "Admin Login" button (green)
- Authenticated admin: "Admin Panel" button (blue)
- Authenticated student: "Dashboard" button

**Internal pages (non-kiosk):**
- App name (links to role-appropriate home)
- User name display
- Logout button with CSRF-protected POST form

**Admin sidebar:**
- Dashboard
- Internship Students
- Attendance
- Audit Log

### Components

| Component | Description |
|-----------|-------------|
| `.Box` | Card container with header and body; 1px border, 6px radius |
| `.Box-header` | Subtle gray background, bottom border |
| `.Table` | Full-width table with border-collapse; header cells in subtle gray |
| `.State` | Pill-shaped status badge; green/red/yellow variants |
| `.btn` | GitHub-style button; primary/success/danger variants |
| `.UnderlineNav` | Horizontal navigation with active bottom border |
| `.flash-success` / `.flash-error` | Green/red alert banners for session flash messages |
| `.form-control` | Input with focus ring (blue `box-shadow`) |
| `attendance-heatmap` | GitHub-style contribution grid component (11×7 pixel blocks, 860px min-width) |

### Heatmap Component

The heatmap (`components/attendance-heatmap.blade.php`) renders a full-year calendar grid:

- Rows: Sunday–Saturday (7 rows)
- Columns: 52+ weeks (varies by year start)
- Colors: `present` = green `#1f883d`, `absent` = light gray `#ebedf0`
- Each cell: 11px × 11px, 2px border radius
- Hover tooltip: date + status
- Renders from first Sunday of the year to last Saturday

### Delete Confirmation Modal

Used on the admin students index page:

- Dark overlay with blur background
- White card with 12px border radius, 32px padding
- Warning icon (red SVG circle)
- Student details (name + ID) in light red box
- Warning list of 5 deletion consequences
- Cancel + "Yes, Delete Permanently" buttons
- Close on: button click, outside click, or Escape key
- Body scroll lock when open

---

## 14. Maintenance & Troubleshooting

### Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| `Face not recognized` on kiosk | ML microservice not running or wrong port | Start FastAPI server on port 8001; check `http://127.0.0.1:8001/identify/` |
| `Check-in/out blocked` on student dashboard | MediaPipe verification expired (60s timeout) or camera not active | Re-start camera and complete liveness challenge |
| `Outside allowed check-in hours` | Request time outside 00:00–23:59 | Verify server time is correct; adjust `check_in_window` in config |
| `Liveness check failed` | Score below 75% threshold | Perform clearer blink + head movement; ensure good lighting |
| `Spoof check failed` | `spoof_passed` not `true` in request | Ensure client-side liveness challenges completed |
| No camera display in kiosk | HTTPS required for getUserMedia in most browsers | Serve over HTTPS or localhost; check browser permissions |
| `Route [admin.categories.index] not defined` | Not applicable (no categories in this project) | N/A |
| Session expired / 419 | CSRF token mismatch or session timeout | Reload page (kiosk auto-handles this) |
| Location not available | GPS denied or IP providers unreachable | Click "Retry Location"; acceptable fallback to `null` geo |

### Maintenance Commands

```bash
# Clear all caches
php artisan optimize:clear

# Run database migrations
php artisan migrate

# Re-seed database (destructive - resets all data)
php artisan migrate:fresh --seed

# Check route list
php artisan route:list

# Check application health
php artisan about

# Tail logs
type storage\logs\laravel.log | more
```

### Backup Strategy

1. **Database backup:**
   ```bash
   # SQLite
   copy database\database.sqlite backups\db-%date%.sqlite

   # PostgreSQL
   pg_dump attendance_system > backups/db-$(date +%Y%m%d).sql
   ```

2. **Environment backup:** Keep a secure copy of `.env` with all production secrets.

3. **Uploaded files:** Back up `storage/app/public/students/` directory containing student photos.

### ML Microservice Health

```bash
# Test ML service connectivity
curl -X POST http://127.0.0.1:8001/identify/ -F "image=@test.jpg"

# Expected response on success: { "ok": true, "matched": false/true, ... }
# Expected response on error: Connection refused (service not running)
```

---

## 15. Testing Strategy

### Test Types

| Type | Tools | Focus |
|------|-------|-------|
| Unit tests | PHPUnit | Models, services, custom validation rules, helper methods |
| Feature tests | PHPUnit | Controllers, middleware, authentication flow, role gating |
| Browser tests | Laravel Dusk | UI interactions, JavaScript behaviour (MediaPipe, liveness) |
| API tests | PHPUnit | Attendance endpoints, ML proxy, rate limiting |

### Current Coverage

No test files are currently created in the repository. Below is the recommended testing strategy.

### Recommended Test Scenarios

1. **Authentication:**
   - Valid `super_admin` login → redirect to admin dashboard
   - Valid `student` login → redirect to student dashboard
   - Invalid credentials → redirect back with errors
   - Logout → session cleared, redirect to login
   - Registration disabled → `/register` returns 404

2. **Role Middleware:**
   - Guest accessing `/admin/*` → redirect to login
   - Student accessing `/admin/*` → HTTP 403
   - Super admin accessing `/student/*` → HTTP 403

3. **Attendance Service:**
   - `checkIn()` with valid verification → creates log + audit trail
   - `checkIn()` duplicate → throws `DuplicateAttendanceException`
   - `checkIn()` outside window → throws `InvalidArgumentException`
   - `checkIn()` without face verification → throws `InvalidArgumentException`
   - `checkOut()` without prior check-in → throws `InvalidArgumentException`
   - `autoCheckIn()` with valid input → creates log with `verification_meta.auto_identified = true`
   - `getHeatmapData()` → returns 365 entries with correct dates/statuses
   - `getTodayStatus()` → reflects current check-in/out state
   - `detectSuspiciousIPs()` → returns IPs with ≥5 check-ins in 120s window
   - `adminOverride()` → updates stated_time, sets is_flagged, creates audit trail
   - `validateStatedTime()` with future time beyond grace period → throws exception
   - `normalizeGeoData()` with null input → attempts IP fallback
   - `resolveSubmittedByUserId()` without auth → returns super_admin ID

4. **Rate Limiting:**
   - 6th request within 1 minute → HTTP 429
   - Reset after 1 minute → requests succeed again

5. **Student Self-Registration:**
   - Valid registration → creates student + photo + ML registration
   - Duplicate email → validation error
   - Duplicate first+last name → validation error
   - Invalid face signature (< 140 values) → validation error
   - Missing photo data → validation error

6. **Admin Student CRUD:**
   - Create student → creates Student + User records
   - Update student → updates student + user name sync
   - Delete student → cascade: ML delete, photo delete, logs delete, user delete, student delete

7. **Kiosk Flow:**
   - Kiosk page loads → displays Start Camera button + status
   - Auto check-in/out → correct JSON response

8. **Audit Trail:**
   - Attendance creation → audit_trail row created with action `'create'`
   - Attendance override → audit_trail row created with action `'override'` + old/new values

---

## 16. Document Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | June 2026 | Engineering Team | Initial comprehensive documentation — complete architecture, schema, route map, API reference, all user flows, face verification pipeline, UI/UX documentation |
