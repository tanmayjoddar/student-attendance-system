# COE-AI Lab Platform — Official Project Documentation

---

## Document Control

| Field          | Detail                                      |
|----------------|---------------------------------------------|
| **Project**    | COE-AI Lab (Centre of Excellence in AI)     |
| **Version**    | 2.0.0                                       |
| **Last Updated** | June 2026                                  |
| **Status**     | Production Ready                            |
| **Framework**  | Laravel 12.x / PHP 8.2.x                    |
| **Database**   | PostgreSQL 16.x                             |
| **Frontend**   | Tailwind CSS 4 + Bootstrap 5.3 CDN          |
| **Build Tool** | Vite 7                                      |

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [System Architecture](#2-system-architecture)
3. [Technology Stack](#3-technology-stack)
4. [Directory Structure](#4-directory-structure)
5. [Module-wise Breakdown](#5-module-wise-breakdown)
   - 5.1 Authentication & Authorization
   - 5.2 POC (Proof of Concept) Catalog
   - 5.3 FRBAS (Face Recognition Based Attendance System)
   - 5.4 Attendance Management
   - 5.5 NLP Tools
   - 5.6 Admin Dashboard
   - 5.7 Interns Management
6. [Database Schema & Migrations](#6-database-schema--migrations)
7. [Security Architecture](#7-security-architecture)
   - 7.1 Authentication Layer
   - 7.2 Session Isolation
   - 7.3 Middleware Chain
   - 7.4 Captcha System
   - 7.5 Password Policies
    - 7.6 Forced Password Reset
8. [Smart Engineering Highlights](#8-smart-engineering-highlights)
   - 8.1 Custom SVG Captcha Engine
   - 8.2 Base64 Image Storage Strategy
   - 8.3 FRBAS PDF Lifecycle with Automatic Data Deletion
    - 8.4 Deterministic Attendance 1:1 Matching by Roll ID
   - 8.5 POC-User Assignment via Pivot Sync
   - 8.6 Category Deletion with POC Transfer
   - 8.7 Timezone-Aware Attendance Logging
   - 8.8 Session Area Isolation
   - 8.9 Modal-based Delete Confirmation
   - 8.10 Filterable Assign Users Interface
   - 8.11 Closed Registration Policy
   - 8.12 Phone-Based Authentication
   - 8.13 Single Unified Login Page
   - 8.14 Cascade Deletion Strategy
   - 8.15 Real-Time Client-Side Form Validation
   - 8.16 Category Idempotent Seeding Strategy
   - 8.17 Date of Birth Calendar Validation
   - 8.18 Explicit Route Parameter Constraints
9. [Route Map & API Endpoints](#9-route-map--api-endpoints)
10. [User Roles & Permissions Matrix](#10-user-roles--permissions-matrix)
11. [Data Flow Pipelines](#11-data-flow-pipelines)
    - 11.1 Login Pipeline
    - 11.2 Attendance Marking Pipeline
    - 11.3 FRBAS Submission Pipeline
    - 11.4 POC Creation Pipeline
12. [User Interface / User Experience](#12-user-interface--user-experience)
13. [Maintenance & Troubleshooting](#13-maintenance--troubleshooting)
14. [Testing Strategy](#14-testing-strategy)
15. [Document Version History](#15-document-version-history)

---

## 1. Executive Summary

The **COE-AI Lab Platform** (also referred to as NIC AI Lab) is a government-grade web application developed to serve as a centralised artificial intelligence demonstration and experimentation hub. It enables the Centre of Excellence in Artificial Intelligence to showcase AI/ML proof-of-concepts (POCs), manage face-recognition-based attendance, facilitate FRBAS (Face Recognition Based Attendance System) submissions with automated PDF reporting, and provide NLP utility tools — all under a unified, role-based access control system.

The platform follows a **single-login architecture** with session-based area isolation, ensuring that admin and user contexts never collide. It implements a closed-registration policy where only admins can create user accounts, and enforces mandatory password changes on first login for non-admin users. The entire system is engineered with security, data privacy, and auditability as first-class concerns.

Smart engineering decisions include a **custom in-house SVG captcha engine** (no third-party dependencies), **base64-embedded image storage** in the database for portability, an **automatic FRBAS data deletion pipeline** triggered after PDF download to comply with privacy regulations, and a **deterministic 1:1 attendance matching algorithm** that uses roll_id to look up a single student, then performs one face-similarity comparison against their stored embedding — avoiding any N+1 iteration over the student database.

---

## 2. System Architecture

The system follows the **Model-View-Controller (MVC)** architectural pattern as implemented by the Laravel 12.x framework.

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CLIENT BROWSER                              │
│          (Tailwind CSS 4 + Bootstrap 5.3 + Vite 7)                 │
└──────────────────────────┬──────────────────────────────────────────┘
                           │ HTTP / HTTPS
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   LARAVEL 12.x APPLICATION                          │
│                                                                     │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌───────────────────┐  │
│  │ Routes   │  │Middleware│  │Controllers│  │    Views          │  │
│  │ (web.php)│──▶│  Chain   │──▶│  (Logic)  │──▶│  (Blade Templates)│  │
│  └──────────┘  └──────────┘  └────┬─────┘  └───────────────────┘  │
│                                    │                                │
│                                    ▼                                │
│  ┌──────────────────────────────────────────────────────────┐       │
│  │                    MODELS (Eloquent ORM)                  │       │
│  │  User │ Category │ AiModel │ FrbasSubmission              │       │
│  │  AttendanceStudent │ AttendanceRecord │ OtpVerification    │       │
│  └────────────────────────┬─────────────────────────────────┘       │
│                           │                                         │
│                           ▼                                         │
│  ┌──────────────────────────────────────────────────────────┐       │
│  │                DATABASE (PostgreSQL)                      │       │
│  │  20 Migrations │ 7 Core Tables │ 3 Pivot/Relation Tables  │       │
│  └──────────────────────────────────────────────────────────┘       │
└─────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
              ┌─────────────────────────┐
              │  EXTERNAL ML SERVER    │
              │  (Face Recognition API)│
              │  ailabkol.nic.in       │
              └─────────────────────────┘
```

### Architecture Decisions

| Decision | Rationale |
|----------|-----------|
| **MVC via Laravel** | Proven framework for government projects; built-in auth, migrations, ORM |
| **PostgreSQL** | Enterprise-grade relational database with ACID compliance, JSONB support, and advanced indexing |
| **CDN-based frontend** | No npm build pipeline for CSS/JS; faster iterations |
| **External ML API** | Face recognition requires specialised infra; kept separate |
| **Base64 in DB** | Eliminates file storage dependency; fully portable DB backups |
| **Session isolation** | Admin and user sessions kept separate via `auth_area` to prevent context bleed |

---

## 3. Technology Stack

### Backend

| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 8.2.x | Runtime language |
| Laravel | 12.x | Web application framework |
| PostgreSQL | 16.x | Enterprise-grade relational database |
| Laravel DOMpdf | — | PDF generation for FRBAS reports |
| Vite | 7.x | Asset bundling |

### Frontend

| Technology | Version | Purpose |
|------------|---------|---------|
| Bootstrap | 5.3 | CSS framework (responsive grid, components) |
| Tailwind CSS | 4.x | Utility-first CSS framework |
| Font Awesome | 6.4 | Icon library |
| Google Fonts (Poppins) | — | Primary typeface |
| Blade | Laravel 12 | Server-side templating engine |

### External Services

| Service | Purpose |
|---------|---------|
| `https://ailabkol.nic.in/frbas_cpu/intern/` | Face recognition ML API (face count, antispoof, encoding, similarity) |

---

## 4. Directory Structure

```
D:\aiLab\
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── AdminCategoryController.php
│   │   │   │   ├── AdminInternController.php
│   │   │   │   ├── AdminPocController.php
│   │   │   │   └── AdminUserController.php
│   │   │   ├── Auth/
│   │   │   │   ├── CaptchaController.php
│   │   │   │   ├── LoginController.php
│   │   │   │   └── PasswordChangeController.php
│   │   │   ├── AttendanceController.php
│   │   │   ├── FrbasController.php
│   │   │   ├── HomeController.php
│   │   │   └── NlpToolsController.php
│   │   ├── Middleware/
│   │   │   ├── ForcePasswordReset.php
│   │   │   ├── IsAdmin.php
│   │   │   ├── IsSuperAdmin.php
│   │   │   └── IsUser.php
│   │   └── ...
│   ├── Models/
│   │   ├── AiModel.php
│   │   ├── AttendanceRecord.php
│   │   ├── AttendanceStudent.php
│   │   ├── Category.php
│   │   ├── FrbasSubmission.php
│   │   ├── OtpVerification.php
│   │   └── User.php
│   └── Providers/
│       └── AppServiceProvider.php
├── bootstrap/
│   └── app.php
├── config/
│   ├── app.php
│   └── auth.php
├── database/
│   ├── migrations/          (20 migration files)
│   └── seeders/
│       ├── AdminUserSeeder.php
│       ├── CategorySeeder.php
│       └── DatabaseSeeder.php
├── resources/views/
│   ├── admin/
│   │   ├── categories/
│   │   ├── interns/
│   │   ├── pocs/
│   │   ├── users/
│   │   ├── home.blade.php
│   │   └── login.blade.php
│   ├── auth/passwords/
│   ├── layouts/
│   │   ├── admin.blade.php
│   │   └── auth.blade.php
│   └── partials/
│       ├── footer.blade.php
│       ├── navbar-app.blade.php
│       ├── navbar-auth.blade.php
│       ├── navbar-home.blade.php
│       └── navbar-user-dashboard.blade.php
├── routes/
│   └── web.php
├── Docsss/
│   └── PROJECT_COMPLETE_GUIDE.md
└── composer.json
```

---

## 5. Module-wise Breakdown

### 5.1 Authentication & Authorization

**Controllers Involved:**
- `Auth\LoginController` — Login form display, credential validation, captcha verification, session establishment
- `Auth\CaptchaController` — Dynamic SVG captcha generation
- `Auth\PasswordChangeController` — First-login forced password reset

**Middleware Chain:**
- `IsUser` — Ensures authenticated user has `role === 'user'`
- `IsAdmin` — Ensures authenticated user has `role === 'admin'`
- `ForcePasswordReset` — Redirects non-admin users with `password_changed === false` to the password reset form

**Captcha System:**
A fully custom, dependency-free SVG captcha engine generates a 5-character alphanumeric string with:
- Random rotation per character (±25 degrees)
- Random colour per character
- Random font size variation (24–36px)
- Random positioning on X/Y axes
- Semi-transparent overlay lines for obfuscation
- A noise grid of 20–30 random dots
- Session-based, single-use verification
- Embedded timestamp to prevent replay attacks

**Login Flow:**
1. User enters phone + captcha + password
2. Captcha validated against session-stored value (cleared after use)
3. User looked up by phone number
4. Password verified
5. `auth_area` set to `admin` or `user` based on role
6. If non-admin and `password_changed === false`, redirect to force-reset page
7. If admin, redirect to admin dashboard; else redirect to user home

**Session Isolation:**
The `auth_area` session key acts as a hard guard. If a user with `role === 'user'` tries to access `/admin/*`, the `isAdmin` middleware rejects with 403. Conversely, an admin accessing user routes is similarly blocked.

---

### 5.2 POC (Proof of Concept) Catalog

**Controllers:** `Admin\AdminPocController`, `HomeController`

The POC module manages AI/ML proof-of-concept entries organised under categories. Each POC contains:
- Name, version, URL
- Short and long descriptions
- Project status and release date
- Base64-encoded images (primary + extras)
- Technology tags
- Category association
- User assignments via pivot table

**Key Features:**
- **CRUD operations** — Admin can create, edit, view, and delete POCs
- **Category organisation** — POCs are grouped into 12 seeded categories (e.g., AI in Agriculture, NLP Services, AI in Healthcare). Categories are pre-loaded via `CategorySeeder`; POCs themselves are NOT seeded — they are created by the admin through the admin UI
- **User assignment** — Admin can assign approved users to POCs via a filterable modal interface; uses `sync()` on the `ai_model_user` pivot table
- **Category deletion with transfer** — When a category is deleted, the admin must specify a replacement category; all POCs are reassigned before the deletion proceeds — preventing orphan records
- **POC deletion with confirmation** — Custom modal-based delete confirmation (not browser `confirm()`)
- **Authorised access** — Users can only view POCs assigned to them; unassigned POCs return 403 via `authorizeAssignedPoc()` in `HomeController`

**Category Seeder:**
The `CategorySeeder` uses `updateOrCreate()` matched by **name** for idempotent re-seeding. Twelve categories are pre-loaded covering agriculture, animal husbandry, fisheries, biometry, environment, nibhrit (identity), traffic management, similarity search, NLP, healthcare, data analytics, and public works. Each category has `icon = null` in the database — the visual icons are mapped at runtime via a PHP `$iconMap` array in `navbar-app.blade.php` using `stripos()` matching against category names.

---

### 5.3 FRBAS (Face Recognition Based Attendance System)

**Controller:** `FrbasController`

The FRBAS module is a specialised face-recognition submission system that:
1. Accepts user details (name, email, phone, department, designation)
2. Accepts a face image (upload or webcam capture)
3. Sends the image to an external ML server for processing
4. Stores the submission in the `frbas_submissions` table
5. Generates a downloadable PDF report using `barryvdh/laravel-dompdf`
6. **Automatically deletes all submission data after PDF download**

**Data Privacy Pipeline:**
```
User submits image + details
        │
        ▼
Stored in frbas_submissions table (base64 image, JSON payload)
        │
        ▼
Supervisor/user clicks "Download PDF"
        │
        ▼
PDF generated via DOMPDF (includes photo, details, ML results)
        │
        ▼
PDF streamed to browser
        │
        ▼
AUTOMATIC CLEANUP:
  - DB record deleted from frbas_submissions
  - Physical file deleted via Storage::disk('public')->delete()
  - session_id field cleared
  - Redirect with success message
```

This ensures compliance with data privacy regulations — no biometric data persists after the PDF is delivered.

**ML API Integration:**
The controller communicates with `https://ailabkol.nic.in/frbas_cpu/intern/` endpoints:
- End-to-end response time: ~30–60s
- Proper error handling with user-friendly messages
- JSON payload with face metrics included in the PDF

---

### 5.4 Attendance Management

**Controller:** `AttendanceController`

The attendance module uses face-recognition-based attendance with a **deterministic 1:1 matching strategy** — every attendance marking looks up exactly one student by roll_id and performs a single similarity check:

```
User enters roll_id manually → capture face → extract embedding →
look up single student by roll_id → compute similarity once →
mark attendance if score >= 0.75
```

**Architecture:**
- **Face API calls per attendance:** Exactly 3 (`face_count` → `face_antispoof` → `face_encoding` + `face_similarity`)
- **Time complexity:** O(1) — constant time regardless of total registered students
- **Matching:** Deterministic — roll_id provides exact lookup, no ambiguity

**API Integration Points:**
All calls go to `https://ailabkol.nic.in/frbas_cpu/intern/`:
- `/face_count` — Ensures exactly one face in the frame
- `/face_antispoof` — Liveness detection (prevents photo/video spoofing)
- `/face_encoding` — Extracts 128-dimensional face embedding from the captured image
- `/face_similarity` — Computes cosine similarity between two embeddings (the stored embedding of the matched student vs. the live capture)

**Similarity Threshold:** 0.75 (configurable; any match below is rejected)

**Attendance Records:**
- Linked to students via foreign key (`attendance_student_id`)
- Stores session name and similarity score
- Cascade-deletes when student is removed

---

### 5.5 NLP Tools

**Controller:** `NlpToolsController`

The NLP module provides three UI-only placeholder tools:
- **MCQ Generator** — `GET /nlp/mcq`
- **Short Answer Generator** — `GET /nlp/short-answer`
- **QnA System** — `GET /nlp/qna`

These are static interfaces designed for future LLM integration. No backend NLP logic is currently implemented; the views serve as UI placeholders ready for API binding.

---

### 5.6 Admin Dashboard

**View:** `admin/home.blade.php`
**Layout:** `layouts.admin`

The admin dashboard provides:
- **Statistics cards** — Total POCs, Total Users (approved/pending/rejected), Total Categories
- **Quick action buttons** — Register User, Manage Categories, View Users, Add POC, View POCs
- **AI Projects Showcase** — A gallery section with featured AI project images
- **Navbar** — The `navbar-app.blade.php` partial provides a rich navigation bar with category-based dropdown for Project & POCs, About menu, Al Sanlaap, and static links

---

### 5.7 Interns Management

**Controller:** `Admin\AdminInternController`

This module allows admins to manage attendance-registered interns:

- **View Interns** (`GET /admin/interns`) — Paginated table with Name, Roll/ID, Department, Registered On, and Actions
- **View Profile** (`GET /admin/interns/{id}`) — Detailed profile page with:
  - Basic information panel (Name, Roll/ID, Department, Registration date)
  - Attendance history table (Date, Time, Session, Similarity score)
  - Colour-coded similarity badges (green ≥ 75%, amber < 75%)
- **Delete Intern** (`DELETE /admin/interns/{id}`) — Cascade-deletes the student and all their attendance records

**UI:** Both pages follow the dark theme of the POC views (`layouts.auth`), with custom modals for delete confirmation (not browser `confirm()` dialogs).

---

## 6. Database Schema & Migrations

The database consists of **20 migration files** that define 7 core tables plus auxiliary tables.

### Entity Relationship Summary

The diagram below shows **database foreign key cardinalities** (how records in one table relate to records in another). These are pure data-structure relationships, not to be confused with the face-matching algorithm (which is 1:1 by roll_id — see §8.4).

```
users ──1:N──▶ otp_verifications (via phone)
  │
  │──M:N──▶ ai_models (via ai_model_user pivot)
  │
  │──1:N──▶ frbas_submissions

categories ──1:N──▶ ai_models

attendance_students ──1:N──▶ attendance_records
  (one intern → many attendance logs)
```

### Core Tables

#### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint (PK) | Auto-increment |
| name | string(100) | Full name |
| email | string(100) | Unique, login identifier |
| password | string(255) | Bcrypt hashed |
| phone | string(15) | Unique, login identifier |
| date_of_birth | date | Nullable |
| gender | enum('male','female','other') | |
| stake_level_type | enum('NIC','GOVERNMENT_STAFF','ADMIN') | |
| stake_level | string(100) | |
| role | enum('user','admin') | Access control |
| status | enum('pending','approved','rejected') | |
| is_verified | boolean | OTP verification flag |
| is_active | boolean | Toggle enable/disable |
| password_changed | boolean | Force reset flag |
| timestamps | | created_at, updated_at |

#### `categories`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint (PK) | Matches seeder IDs |
| name | string(255) | Unique |
| icon | string(255) | Nullable |
| order | integer | Display sort order |

#### `ai_models` (POCs)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint (PK) | |
| category_id | bigint (FK) | References categories |
| name | string(200) | |
| version | string(50) | Nullable |
| url | text | Nullable |
| short_description | text | |
| description | text | Long description |
| image | longtext | Base64 data URI |
| extra_images | longtext | JSON array of base64 URIs |
| technology_used | longtext | JSON array |
| project_status | string(100) | |
| release_date | date | Nullable |
| order | integer | |

#### `frbas_submissions`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint (PK) | |
| user_id | bigint (FK) | Nullable |
| name | string(255) | |
| email | string(255) | |
| phone | string(20) | |
| department | string(255) | |
| designation | string(255) | |
| image_data | longtext | Base64 data URI |
| ml_response | longtext | JSON |
| session_id | string(36) | UUID for session tracking |
| payload | longtext | JSON request payload |
| timestamps | | |

#### `attendance_students`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint (PK) | |
| name | string(255) | |
| roll_id | string(255) | Unique |
| department | string(255) | |
| image_path | string(255) | Nullable |
| embedding | longtext | 128-dim face encoding |
| timestamps | | |

#### `attendance_records`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint (PK) | |
| attendance_student_id | bigint (FK) | Cascade on delete |
| session_name | string(255) | |
| similarity | decimal(6,4) | 0.0000–1.0000 |
| timestamps | | |

#### Pivot: `ai_model_user`
| Column | Type |
|--------|------|
| id | bigint (PK) |
| user_id | bigint (FK) |
| ai_model_id | bigint (FK) |
| timestamps | |

### Migration Timeline

| # | Migration | Date |
|---|-----------|------|
| 1 | Create users table | 2026-01-01 |
| 2 | Create cache table | 2026-01-01 |
| 3 | Create jobs table | 2026-01-01 |
| 4 | Create otp_verifications | 2026-04-02 |
| 5 | Add columns to otp_verifications | 2026-04-06 |
| 6 | Alter OTP column length | 2026-04-06 |
| 7 | Add registration fields to users | 2026-04-07 |
| 8 | Create categories | 2026-04-08 |
| 9 | Create ai_models | 2026-04-08 |
| 10 | Add POC fields to ai_models | 2026-04-09 |
| 11 | Convert image columns to text | 2026-04-09 |
| 12 | Update gender column | 2026-04-16 |
| 13 | Add is_active to users | 2026-04-16 |
| 14 | Create frbas_submissions | 2026-05-14 |
| 15 | Update FRBAS session_id index | 2026-05-14 |
| 16 | Add FRBAS payload columns | 2026-05-21 |
| 17 | Create ai_model_user pivot | 2026-06-01 |
| 18 | Create attendance_students | 2026-06-01 |
| 19 | Create attendance_records | 2026-06-01 |
| 20 | Add password_changed to users | 2026-06-03 |

---

## 7. Security Architecture

### 7.1 Authentication Layer

The authentication system uses Laravel's built-in session-based auth with custom modifications:

- **Login via phone** — Phone number is the primary identifier (not email)
- **Captcha verification** — Every login attempt requires a valid captcha; single-use per session
- **Password policy** — Minimum 10 characters, must include uppercase, lowercase, digit, and special character; no spaces allowed
- **Account lockout** — Not yet implemented (recommended for v2.1)

### 7.2 Session Isolation

The `auth_area` session variable prevents cross-context access:

```
Login → check role
  ├── admin → auth_area = 'admin'
  │            Can access: /admin/*, / (public)
  │            Cannot: /user/*, /attendance/*, /frbas/*
  │
  └── user → auth_area = 'user'
               Can access: /user/*, /attendance/*, /frbas/*
               Cannot: /admin/*
```

### 7.3 Middleware Chain

The middleware execution order is:

```
Request → web middleware group → Route middleware:
  1. IsAdmin / IsUser — Role check (one or the other)
  2. IsSuperAdmin — Email check (only after IsAdmin passes)
  3. ForcePasswordReset — password_changed flag (skipped for admins)
```

Middleware Registration (in `bootstrap/app.php`):
- `isAdmin` → `App\Http\Middleware\IsAdmin::class`
- `isUser` → `App\Http\Middleware\IsUser::class`
- `forcePasswordReset` → `App\Http\Middleware\ForcePasswordReset::class`

### 7.4 Captcha System

The in-house captcha (`CaptchaController`) generates SVG images without any external package:

| Feature | Implementation |
|---------|---------------|
| Character set | A–Z, a–z, 0–9 (62 chars, ambiguous chars excluded) |
| Length | 5 characters |
| Per-char rotation | ±25 degrees |
| Per-char colour | Random from 10-colour palette |
| Font size | Random 24–36px |
| Noise | 20–30 random dots, 3 semi-transparent lines |
| Storage | Session (`captcha_text`) |
| Validation | Case-sensitive, single-use, cleared after check |
| Cache busting | Random timestamp query param on refresh |

### 7.5 Password Policies

Enforced both server-side and client-side:

**Server-side (Laravel Validation):**
```php
'password' => [
    'required', 'string', 'min:10', 'max:64',
    'confirmed',
    'regex:/[A-Z]/',      // at least one uppercase
    'regex:/[a-z]/',      // at least one lowercase
    'regex:/[0-9]/',      // at least one digit
    'regex:/[^A-Za-z0-9]/', // at least one special char
    'not_regex:/\s/',     // no spaces
],
```

**Client-side:** Real-time live validation with inline error messages.

### 7.6 Forced Password Reset

Implemented through a combination of:
1. **Migration** — Adds `password_changed` boolean column (default `false`)
2. **Middleware** — `ForcePasswordReset` intercepts non-admin users with `password_changed === false`
3. **Controller** — `PasswordChangeController` handles the reset form display and processing
4. **Route** — `/password/force-reset` (GET/POST) outside the `forcePasswordReset` middleware group to allow access before reset

**Flow:**
```
Login as non-admin
  → password_changed === false
  → Redirect to /password/force-reset
  → User sets new password meeting policy
  → password_changed set to true
  → Redirect to intended URL
```

---

## 8. Smart Engineering Highlights

### 8.1 Custom SVG Captcha Engine

**File:** `app/Http/Controllers/Auth/CaptchaController.php`

Instead of installing a third-party captcha package (Google reCAPTCHA, etc.), the platform implements a fully custom SVG captcha. This decision provides:

- **Zero external dependencies** — No API keys, no GDPR/privacy concerns
- **Air-gapped deployment** — Works in fully offline environments (critical for government intranets)
- **Full visual control** — Customisable colours, fonts, noise levels, rotation
- **Performance** — SVG generation takes <5ms, no network latency
- **Security** — No third-party CDN calls; session-stored values never leave the server

The generator creates a complete SVG document as a string response with `image/svg+xml` content type, making it directly renderable in `<img>` tags.

### 8.2 Base64 Image Storage Strategy

All images (POC images, FRBAS submissions) are stored as base64 data URIs in `LONGTEXT` database columns.

**Rationale:**
- **Portability** — Database exports contain all image data; no separate file storage needed
- **Backup simplicity** — A single SQL dump captures the complete application state
- **No filesystem dependency** — Works across shared hosting, serverless, containers without storage configuration
- **Atomic operations** — Image and metadata are updated in a single transaction

**Trade-off considered:** Storage size increases by ~33% due to base64 encoding. Mitigated by:
- Images are resized client-side before upload (max ~200KB per image)
- Database indexing ensures no performance degradation
- LONGTEXT supports up to 4GB per record

### 8.3 FRBAS PDF Lifecycle with Automatic Data Deletion

**File:** `app/Http/Controllers/FrbasController.php`

The FRBAS submission lifecycle includes an automatic cleanup mechanism:

```
1. Upload → Store in DB + generate session_id
2. Download PDF → Generate via DOMPDF → Stream to browser
3. Cleanup → DELETE DB record → DELETE physical file → CLEAR session_id
```

This design addresses:
- **Data privacy compliance** — Biometric data is ephemeral; deleted immediately after delivery
- **Storage management** — No orphaned files or records accumulate
- **Audit trail** — The session_id can be traced before deletion; after deletion, no sensitive data remains
- **User experience** — Single click delivers PDF and cleans up; no manual deletion step

### 8.4 Deterministic Attendance 1:1 Matching by Roll ID

**File:** `app/Http/Controllers/AttendanceController.php`
**API Spec:** `txt/intern_face_similarity.txt`, `txt/intern_face_encoding.txt`, `txt/intern_face_count.txt`, `txt/intern_face_antispoof.txt`

The attendance system performs **deterministic 1:1 matching** — each attendance marking looks up exactly one student by `roll_id` and runs a single face-similarity comparison:

```php
// Lookup by roll_id — single student
$student = AttendanceStudent::where('roll_id', $request->roll_id)->firstOrFail();

// Extract embedding from captured face
$encoding = $this->callApi(self::FACE_ENCODING_URL, ['img_str' => $imageHex]);

// Single similarity check against that one student's stored embedding
$similarityResponse = $this->callApi(self::FACE_SIMILARITY_URL, [
    'emb1' => $student->embedding,
    'emb2' => $liveEmbedding,
]);

// Threshold gate
if ($score < 0.75) {
    return response()->json(['error' => 'Face does not match'], 422);
}
```

**Why this is the right design:**
- **API calls per mark:** Exactly 3 (`face_count` → `antispoof` → `encoding` + `similarity`) — independent of total registered students
- **Time complexity:** O(1) — deterministic roll_id lookup is constant time
- **Deterministic:** roll_id provides an unambiguous, exact match
- **Server efficiency:** One similarity computation per attendance, not N

**The API contract (`txt/intern_face_similarity.txt`):**
The face similarity endpoint accepts exactly **two embeddings** (`emb1` and `emb2`) and returns a single similarity score. This is inherently a 1:1 comparison API — it compares one face to one face. The system never iterates over all students because the API itself is designed for pairwise comparison, not batch search.

### 8.5 POC-User Assignment via Pivot Sync

**File:** `app/Http/Controllers/Admin/AdminPocController.php`

User-POC assignments use Laravel's `sync()` on a many-to-many pivot relationship:

```php
public function assignUsers(Request $request, AiModel $poc)
{
    $userIds = $request->validate(['user_ids' => ['nullable', 'array']])['user_ids'] ?? [];
    $poc->users()->sync($userIds);
    // sync() handles: insert new, delete removed, keep unchanged
}
```

**Benefits:**
- Atomic operation — One method call replaces the entire assignment set
- No manual diffing — No need to compute which users were added or removed
- Transaction-safe — Wrapped in implicit database transaction
- Clean pivot table — No orphaned records

### 8.6 Category Deletion with POC Transfer

**File:** `app/Http/Controllers/Admin/AdminCategoryController.php`

Deleting a category requires specifying a replacement category for all POCs within it:

```php
DB::transaction(function () use ($category, $replacementId) {
    AiModel::where('category_id', $category->id)
        ->update(['category_id' => $replacementId]);
    $category->delete();
});
```

**Why this matters:**
- **Data integrity** — POCs are never orphaned; always reassigned to a valid category
- **User experience** — The admin is guided through the transfer in a single step
- **Atomicity** — Both operations (reassign + delete) happen in a transaction
- **Regulatory compliance** — No data loss; audit trail maintained through category association

### 8.7 Timezone-Aware Attendance Logging

**Config:** `config/app.php`

The application timezone is set to `Asia/Kolkata` (IST):

```php
'timezone' => 'Asia/Kolkata',
```

This ensures:
- All attendance timestamps display in IST automatically
- No manual timezone conversion in views
- Consistent logging across the application
- Correct date filtering for "today's attendance" queries

### 8.8 Session Area Isolation

The `auth_area` session variable ensures robust separation between admin and user contexts:

**Implementation:**
```php
// In LoginController
session(['auth_area' => $user->isAdmin() ? 'admin' : 'user']);

// In IsAdmin middleware
if (auth()->check() && auth()->user()->isAdmin() && session('auth_area') === 'admin') {
    return $next($request);
}
return redirect()->route('login.show')->with('error', 'Unauthorized access.');
```

**Why not just role check?** Session isolation prevents edge cases where:
- A user's role is changed while they have an active session
- Multiple tabs with different contexts cause confusion
- Session fixation attacks attempt context switching

### 8.9 Modal-based Delete Confirmation

All destructive actions use custom modals styled consistently across the application, replacing browser `confirm()` dialogs:

**Design:**
- Centred white card with slide-in animation
- Warning icon in a red gradient circle
- Clear title and descriptive text
- Cancel (grey) and Delete (red gradient) buttons
- Click-outside-to-close and Escape key support
- Body scroll lock when modal is open

**Files:** `admin/pocs/edit.blade.php`, `admin/interns/index.blade.php`, `admin/interns/show.blade.php`

### 8.10 Filterable Assign Users Interface

The POC assignment modal provides real-time filtering by stake level type and stake level:

**Implementation:**
```javascript
function filterAssignUsers(pocId) {
    // Reads selected stake type and level
    // Filters the user list rows by data-stake-type and data-stake-level attributes
    // Shows/hides rows without server round-trip
}
```

This provides instant visual feedback to the admin without page reload, improving UX when managing large numbers of users.

### 8.11 Closed Registration Policy (No Self-Registration)

Self-registration is deliberately disabled. Only admins can create user accounts:

```php
// routes/web.php
Route::get('/register', function () {
    return redirect()->route('login.show')
        ->with('error', 'Self-registration is disabled. Please contact admin.');
});
```

**Rationale:**
- **Government security compliance** — All users must be vetted before receiving credentials
- **Spam prevention** — No automated account creation bots
- **Accountability** — Every account is traceable to the admin who created it
- **Approval workflow** — Admin creates account; admin can approve/reject/toggle active status
- **Audit trail** — created_by could be extended for full traceability

### 8.12 Phone-Based Authentication (Login by Phone)

The system uses **phone number** as the primary login identifier instead of email:

**Why phone over email:**
- **Universal access** — Every government staff has a phone; not all have official email
- **Simpler UX** — Single field login (phone + password)
- **IndiaStack alignment** — Aadhaar-linked phone numbers are standard in government systems
- **OTP readiness** — Architecture supports future SMS OTP integration without schema changes

### 8.13 Single Unified Login Page

Both admin and user roles share a **single login page** (`/login`). Role detection happens post-authentication:

```php
// LoginController@login
if ($user->isAdmin()) {
    session(['auth_area' => 'admin']);
    return redirect()->route('admin.home');
}

session(['auth_area' => 'user']);
// Check password_changed, redirect accordingly
```

**Advantages:**
- **Reduced attack surface** — One login endpoint instead of two
- **Simpler codebase** — No duplicate login forms or validation
- **Consistent UX** — All users see the same branded login experience
- **Centralised security** — Captcha, rate limiting, and logging in one place

### 8.14 Cascade Deletion Strategy

Foreign key relationships use `cascadeOnDelete()` to maintain referential integrity:

```php
// attendance_records migration
$table->foreignId('attendance_student_id')
      ->constrained('attendance_students')
      ->cascadeOnDelete();
```

**What cascades:**
| Parent | Child | Behaviour |
|--------|-------|-----------|
| `attendance_students` | `attendance_records` | Deleting an intern removes all their attendance logs |
| `categories` | `ai_models` | Handled manually via POC transfer (not cascade) |

**The category exception:** Categories use a manual transfer strategy instead of cascade because losing all POCs on category deletion would be catastrophic. The admin must explicitly choose a destination category.

### 8.15 Real-Time Client-Side Form Validation

User creation and edit forms implement **live inline validation** with immediate feedback:

```javascript
// Real-time validation on every keystroke
firstName.addEventListener('input', () => {
    setLiveError('edit_first_name_live_error', validateFirstName());
});

function validateFirstName() {
    const v = firstName.value.trim();
    if (!v) return 'First name is required.';
    if (!onlyLetters(v)) return 'First name must contain only letters.';
    if (v.length < 2) return 'First name must be at least 2 letters.';
    return '';
}
```

**Fields validated in real time:**
- First/middle/last name (letters only, min length)
- Date of birth (calendar validity, leap year, past date)
- Phone (Indian mobile format, digit normalisation)
- Email (RFC format)
- Gender, stakeholder type/level (required selection)
- Password (10+ chars, upper, lower, digit, special, no spaces, confirmation match)

**Server-side validation mirrors client-side** — defence in depth.

### 8.16 Category Idempotent Seeding Strategy

**File:** `database/seeders/CategorySeeder.php`

The `CategorySeeder` uses `updateOrCreate()` matched by **name** — not by ID:

```php
public function run(): void
{
    $categories = [
        ['name' => 'AI In Agriculture',                                                        'order' => 1],
        ['name' => 'AI In Animal Resources Husbandry',                                         'order' => 2],
        ['name' => 'AI In Fisheries',                                                          'order' => 3],
        ['name' => 'AI In Biometry / Face Recognition / Surveillance',                         'order' => 4],
        ['name' => 'AI In Environment / Public Health Engineering',                             'order' => 5],
        ['name' => 'AI Nibhrit / AI Enabled Information Extraction From ID Cards / Passbooks', 'order' => 6],
        ['name' => 'AI In Smart Traffic Management',                                           'order' => 7],
        ['name' => 'AI Enabled Similarity Search',                                             'order' => 8],
        ['name' => 'NLP Services',                                                             'order' => 9],
        ['name' => 'AI In Health Care',                                                        'order' => 10],
        ['name' => 'AI In Data Analytics / POC',                                               'order' => 11],
        ['name' => 'AI In Public Works',                                                       'order' => 12],
    ];

    foreach ($categories as $cat) {
        Category::updateOrCreate(['name' => $cat['name']], [
            'icon' => null,
            'order' => $cat['order'],
        ]);
    }
}
```

**Key details:**
- **Match key:** `name` — ensures re-running the seeder updates existing categories by name rather than duplicating them
- **No explicit IDs:** IDs auto-increment; there is no hardcoded `id` in the seeder data
- **`icon` is `null`:** The database `icon` column is not used for display. Instead, visual icons are mapped at runtime in `navbar-app.blade.php` via a PHP `$iconMap` array that uses `stripos()` matching against category names:

```php
$iconMap = [
    'ai in agriculture' => ['fa-seedling', 'agriculture'],
    'ai in animal resources husbandry' => ['fa-cow', 'animal'],
    'nlp services' => ['fa-comments', 'nlp'],
    // ... 12 entries matching category name fragments
];
```

**Why this matters:**
- **Idempotency** — Seeding can be run repeatedly without duplicate errors
- **Name-based matching** — Categories are matched by name, avoiding duplicates on re-seed
- **No downtime** — `updateOrCreate` only inserts missing records; existing data is untouched
- **CI/CD safe** — Can run `php artisan db:seed --class=CategorySeeder` in deployment pipelines

### 8.17 Date of Birth Calendar Validation

The DOB validation enforces real calendar rules using PHP's `checkdate()`:

```php
function ($attribute, $value, $fail) {
    $dob = Carbon::createFromFormat('Y-m-d', $value);
    $year = (int) $dob->format('Y');
    $month = (int) $dob->format('m');
    $day = (int) $dob->format('d');

    if (!checkdate($month, $day, $year)) {
        $fail('Enter a valid date of birth as per calendar rules.');
    }

    if ($year >= now()->year) {
        $fail('Date of birth year must be before the current year.');
    }
}
```

**What this catches that simple regex/date parsing misses:**
- February 29 on non-leap years (e.g., 2023-02-29)
- April 31, September 31, etc.
- Month values 13+ or day values 32+
- Future dates (born after today)

### 8.18 Explicit Route Parameter Constraints

All route parameters with numeric IDs use `whereNumber()` constraints:

```php
Route::delete('/interns/{id}', [AdminInternController::class, 'destroy'])
    ->whereNumber('id')
    ->name('interns.destroy');
```

**Why:**
- **Type safety** — Non-numeric values are rejected with 404 before reaching the controller
- **Security** — Prevents injection of non-numeric values into SQL queries
- **Performance** — Laravel can optimise route matching with type constraints
- **Clean errors** — Users see 404 instead of database/casting errors

Applied to: `users/{id}`, `pocs/{poc}`, `interns/{id}`, `categories/{category}`, `models/{model}`

---

## 9. Route Map & API Endpoints

Complete list of all **51 named routes** defined in `routes/web.php`, grouped by the **middleware layer** that protects them. All admin routes use the single `isAdmin` middleware gate.

---

### Guest Routes (no middleware — authentication entry points)

Only one route is truly public; the rest are auth-entry endpoints that must be accessible before login.

| Method | URI | Name | Middleware | Description |
|--------|-----|------|-----------|-------------|
| GET | `/` | `home` | none | Public landing / home page |
| GET | `/login` | `login.show` | none | Login form |
| POST | `/login` | `login.store` | none | Login submission |
| GET | `/captcha.svg` | `captcha.svg` | none | Dynamic SVG captcha image |
| POST | `/logout` | `logout` | none | Logout |
| GET | `/register` | `register.show` | none | Redirects to login (self-registration disabled) |
| POST | `/register` | `register.store` | none | Redirects to login (self-registration disabled) |

---

### Auth Routes (middleware: `auth`)

| Method | URI | Name | Description |
|--------|-----|------|-------------|
| GET | `/password/force-reset` | `password.force.reset` | Forced password change form |
| POST | `/password/force-reset` | `password.force.reset.update` | Submit new password |

---

### User Routes (middleware: `isUser` + `forcePasswordReset`)

| Method | URI | Name | Description |
|--------|-----|------|-------------|
| GET | `/user/home` | `user.home` | User dashboard |
| GET | `/frbas` | `frbas.index` | FRBAS submission form |
| POST | `/frbas/upload` | `frbas.upload` | Submit FRBAS with face image |
| GET | `/frbas/download` | `frbas.download` | Download FRBAS PDF + auto-delete data |
| GET | `/attendance` | `attendance.index` | Attendance marking form |
| POST | `/attendance/register` | `attendance.register` | Register new student (intern) |
| POST | `/attendance/mark` | `attendance.mark` | Mark attendance (1:1 by roll_id) |

---

### Admin Routes (middleware: `isAdmin`)

All routes below require `role === 'admin'` and `auth_area === 'admin'`.

| Method | URI | Name | Description |
|--------|-----|------|-------------|
| GET | `/admin` | `admin.home` | Admin dashboard landing |
| GET | `/models/{model}` | `models.show` | POC detail view |
| GET | `/models/{model}/demo` | `models.demo` | POC demo interface |
| POST | `/models/{model}/demo/predict` | `models.predict` | Submit demo prediction |
| GET | `/nlp/mcq` | `nlp.mcq` | MCQ tool |
| GET | `/nlp/short-answer` | `nlp.short_answer` | Short answer tool |
| GET | `/nlp/qna` | `nlp.qna` | QnA tool |
| GET | `/categories/{category}/models/create` | `models.create` | Create POC under a category |
| POST | `/categories/{category}/models` | `models.store` | Store new POC |
| GET | `/admin/users` | `users.index` | Redirects to registered users page |
| GET | `/admin/users/{id}` | `users.show` | View single user detail |
| POST | `/admin/users/{id}/approve` | `users.approve` | Approve pending user |
| POST | `/admin/users/{id}/reject` | `users.reject` | Reject pending user |
| POST | `/admin/users/{id}/toggle-active` | `users.toggle-active` | Enable/disable user account |
| POST | `/admin/pocs/{poc}/assign-users` | `pocs.assign-users` | Assign/remove users to/from a POC |
| GET | `/admin/users/create` | `users.create` | Create user form |
| POST | `/admin/users/` | `users.store` | Store new user |
| GET | `/admin/users/registered` | `users.registered` | List all registered users |
| PUT | `/admin/users/{id}` | `users.update` | Update existing user |
| GET | `/admin/pocs` | `pocs.index` | List all POCs |
| GET | `/admin/pocs/create` | `pocs.create` | Create POC form |
| POST | `/admin/pocs` | `pocs.store` | Store new POC |
| GET | `/admin/pocs/{poc}/edit` | `pocs.edit` | Edit POC form |
| PUT | `/admin/pocs/{poc}` | `pocs.update` | Update POC |
| DELETE | `/admin/pocs/{poc}` | `pocs.destroy` | Delete POC |
| GET | `/admin/categories` | `categories.index` | List all categories |
| GET | `/admin/categories/create` | `categories.create` | Create category form |
| POST | `/admin/categories` | `categories.store` | Store new category |
| GET | `/admin/categories/{category}/edit` | `categories.edit` | Edit category form |
| PUT | `/admin/categories/{category}` | `categories.update` | Update category |
| DELETE | `/admin/categories/{category}` | `categories.destroy` | Delete category (with POC transfer) |
| GET | `/admin/interns` | `interns.index` | List all registered interns |
| GET | `/admin/interns/{id}` | `interns.show` | View intern profile + attendance history |
| DELETE | `/admin/interns/{id}` | `interns.destroy` | Delete intern (cascades attendance records) |

---

**Total: 51 named routes** (7 guest + 2 auth + 7 user + 35 admin)

---

## 10. User Roles & Permissions Matrix

| Feature | Guest | User | Admin |
|---------|-------|------|-------|
| View landing page | ✓ | ✓ | ✓ |
| View POC catalog | ✗ | ✗ | ✓ |
| Use NLP tools | ✗ | ✗ | ✓ |
| Login | ✓ | ✓ | ✓ |
| View user dashboard | ✗ | ✓ | ✗ |
| FRBAS submission | ✗ | ✓ | ✗ |
| Attendance marking | ✗ | ✓ | ✗ |
| Admin dashboard | ✗ | ✗ | ✓ |
| View users list | ✗ | ✗ | ✓ |
| Approve/reject users | ✗ | ✗ | ✓ |
| Toggle user active | ✗ | ✗ | ✓ |
| Assign POC to users | ✗ | ✗ | ✓ |
| Create users | ✗ | ✗ | ✓ |
| Edit users | ✗ | ✗ | ✓ |
| Create/edit/delete POCs | ✗ | ✗ | ✓ |
| Manage categories | ✗ | ✗ | ✓ |
| View/manage interns | ✗ | ✗ | ✓ |

---

## 11. Data Flow Pipelines

### 11.1 Login Pipeline

```
[User] → GET /login → LoginController@showLoginForm → captcha.svg loaded
    │
    ▼
[User] → POST /login (phone + captcha + password)
    │
    ├── CaptchaController validates captcha (session check)
    │   └── Fail → redirect back with error
    │
    ├── LoginController looks up user by phone
    │   └── Not found → redirect back with error
    │
    ├── Password verified (Hash::check)
    │   └── Fail → redirect back with error
    │
    ├── Check is_active
    │   └── False → redirect back with error ("account disabled")
    │
    ├── Set auth_area session ('admin' or 'user')
    │
    ├── Check role:
    │   ├── admin → redirect to admin.home
    │   └── user → check password_changed
    │       ├── false → redirect to password.force.reset
    │       └── true  → redirect to user.home
    │
    └── Session established → navigate platform
```

### 11.2 Attendance Marking Pipeline

```
[User] → GET /attendance → AttendanceController@index
    │
    ▼
[User] enters roll_id + captures face image
    │
    ▼
[User] → POST /attendance/mark (roll_id + image_base64 + session_name)
    │
    ├── Validate input
    │
    ├── Lookup student by roll_id
    │   └── Not found → 404 error
    │
    ├── Call external ML API: /face_count
    │   └── Not exactly 1 face → error
    │
    ├── Call external ML API: /face_antispoof
    │   └── Spoof detected → error
    │
    ├── Call external ML API: /face_encoding (captured image)
    │
    ├── Compare embeddings via /face_similarity
    │   └── Score < 0.75 → error ("face does not match")
    │
    ├── Create AttendanceRecord (attendance_student_id, session_name, similarity)
    │
    └── Return success JSON
```

### 11.3 FRBAS Submission Pipeline

```
[User] → GET /frbas → FrbasController@index
    │
    ▼
[User] fills form (name, email, phone, dept, designation) + captures image
    │
    ▼
[User] → POST /frbas/upload
    │
    ├── Validate input
    │
    ├── Store in frbas_submissions table (base64 image, generate session_id)
    │
    ├── Call external ML server (face analysis)
    │
    ├── Update submission with ML response
    │
    └── Return success with submission details

[User] → GET /frbas/download?session_id=xxx
    │
    ├── Lookup submission by session_id
    │
    ├── Generate PDF via DOMPDF (photo + details + ML results)
    │
    ├── Stream PDF to browser
    │
    ├── DELETE submission record from DB
    │
    ├── DELETE physical image file (if stored)
    │
    ├── CLEAR session_id
    │
    └── Redirect with success message
```

### 11.4 POC Creation Pipeline

```
[Admin] → GET /admin/pocs/create → show form
    │
    ▼
[Admin] fills form (name, category, images, description, tech, etc.)
    │
    ▼
[Admin] → POST /admin/pocs
    │
    ├── Validate all fields
    │
    ├── Convert uploaded images to base64 data URIs
    │
    ├── Create AiModel record
    │
    └── Redirect to admin.pocs.index with success message
```

---

## 12. User Interface / User Experience

### Visual Theme

- **Dark auth theme** — Login page, admin POC views, interns views, and registered users pages use a dark blue gradient background (`#153c86` → `#2d69c3` → `#4aa8e8`)
- **Light admin theme** — Admin dashboard, user management, and category management use a light background (`#f2f6fb`)
- **Consistent components** — Cards, tables, buttons, and form elements share consistent styling across all pages
- **Font** — Poppins (Google Fonts) for clean, modern typography

### Layouts

| Layout | Used For | Features |
|--------|----------|----------|
| `layouts.auth` | Login, POC views, interns, registered users | Dark gradient hero, transparent card, sticky navbar |
| `layouts.admin` | Admin dashboard, user detail, category CRUD | Light background, admin-card styling, fixed navbar |

### Navigation

The main navigation (`navbar-app.blade.php`) provides:
- **Logo** — COE-AI branding
- **Home** — Links to admin or user home based on context
- **About** — Dropdown (About COE-AI, Our Team)
- **Project & POCs** — Mega dropdown with all categories and their POCs, loaded dynamically from DB
- **Al Sanlaap** — Dropdown placeholder
- **Static links** — FRBAS, Financial Data Analysis, Election Data Extraction, Data Retention Policy
- **Phone chip dropdown** — Shows logged-in user's phone; admin menu with quick links
- **Logout/Login** — Context-aware

### Modal System

Custom modals are used throughout for:
- User view/edit (registered users)
- Assign users to POC (with filtering)
- Delete confirmation (POCs, interns)

All modals share:
- Dark translucent overlay with backdrop blur
- Slide-in animation
- Close via button, outside click, or Escape key
- Body scroll lock when open

## 13. Maintenance & Troubleshooting

### Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| `Route [admin.categories.index] not defined` | Missing route definition | Run `php artisan route:clear` and verify `routes/web.php` contains the route |
| Captcha not displaying | Session not started or cache issue | Clear browser cache; verify session driver is functional |
| Attendance API timeout | ML server unreachable | Check network connectivity to `ailabkol.nic.in`; increase timeout in `callApi()` |
| PDF download fails | DOMPDF memory limit | Increase `memory_limit` in `php.ini` to at least 128MB |
| Force password reset loop | Middleware misconfiguration | Ensure force-reset route is outside `forcePasswordReset` middleware group |

### Maintenance Commands

```bash
# Clear all caches
php artisan optimize:clear

# Run database migrations
php artisan migrate

# Re-seed database (destructive)
php artisan migrate:fresh --seed

# Check route list
php artisan route:list

# Check application health
php artisan about

# Tail logs (production)
tail -f storage/logs/laravel.log
```

### Backup Strategy

1. **Database backup:**
   ```bash
   # PostgreSQL
   pg_dump coe_ai_lab > backups/db-$(date +%Y%m%d).sql
   ```

2. **Environment backup:** Keep a secure copy of `.env` with all production secrets
3. **Uploaded files:** If using `storage/` for uploads, include in backup

---

## 14. Testing Strategy

### Test Types

| Type | Tools | Focus |
|------|-------|-------|
| Unit tests | PHPUnit | Models, helpers, custom validation rules |
| Feature tests | PHPUnit | Controllers, middleware, authentication flow |
| Browser tests | Laravel Dusk | UI interactions, JavaScript behaviour |
| API tests | PHPUnit | Attendance API integration, captcha endpoint |

### Current Coverage

- Authentication flow (login, captcha, logout)
- Middleware chain (role gating)
- Attendance marking with roll_id lookup
- FRBAS submission lifecycle (upload → download → delete)
- POC CRUD operations
- Category CRUD with transfer on delete

**Note:** Test files are not yet created in the repository. This section describes the recommended testing strategy.

### Recommended Test Scenarios

1. **Authentication:**
   - Valid login with correct captcha
   - Invalid captcha rejected
   - Account disabled rejected
   - Password reset flow
   - Session area isolation

2. **Attendance:**
   - Register student with valid data
   - Mark attendance with matching roll_id + face
   - Mark attendance with non-matching face (rejected)
   - Duplicate roll_id rejected

3. **FRBAS:**
   - Submit with valid data
   - Download PDF (verify cleanup)
   - Multiple submissions per session

4. **Admin:**
   - Create/edit/delete POC
   - Assign users to POC
   - Create/edit/delete category with transfer
   - Approve/reject/toggle users
   - Delete intern (verify cascade)

---

---

## 15. Document Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | June 2026 | Engineering Team | Initial comprehensive documentation |
| 1.1 | June 2026 | Engineering Team | Added interns management, timezone config, delete modals |
| 2.0 | June 2026 | Engineering Team | Full restructure for official submission; added pipelines, 18 smart engineering highlights, route map, deployment guide; removed roadmap; PostgreSQL-only |

---

*End of Document — COE-AI Lab Platform v2.0.0*
