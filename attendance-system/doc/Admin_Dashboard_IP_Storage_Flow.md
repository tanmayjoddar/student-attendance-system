# Admin Panel Dashboard And IP Storage Flow

This document explains only admin-side flow and exactly how IP address is stored and shown.

## 1) Admin dashboard purpose

Admin dashboard gives quick view of today activity:

- Total active students
- Present today
- Absent today
- Recent attendance records
- Flagged records

Main files:

- app/Http/Controllers/AdminDashboardController.php
- resources/views/admin/dashboard.blade.php

## 2) How dashboard data is loaded

Controller method:

- AdminDashboardController@index

It runs queries:

1. Count active students from students table.
2. Count today check-ins from attendance_logs where type = in.
3. Compute absent = total - present.
4. Load recent attendance rows (with student and submittedBy relation).
5. Load flagged records (is_flagged = true).

Then it returns view admin.dashboard with all calculated data.

## 3) How IP is stored in database

IP is captured at service level from request:

- request()->ip()

Where it is written:

1. Check-in insert in AttendanceService::checkIn()
    - Writes to attendance_logs.ip_address
2. Check-out insert in AttendanceService::checkOut()
    - Writes to attendance_logs.ip_address
3. Audit entry in AttendanceService::createAuditTrail()
    - Writes to audit_trail.ip_address

Main file:

- app/Services/AttendanceService.php

## 4) Exact storage tables for IP

### attendance_logs table

Used for check-in/check-out records.

IP column:

- ip_address

Model:

- app/Models/AttendanceLog.php

### audit_trail table

Used for activity log entries (create/override etc.).

IP column:

- ip_address

Model:

- app/Models/AuditTrail.php

## 5) Where admin sees IP in UI

### Dashboard page

File:

- resources/views/admin/dashboard.blade.php

In "Recent Attendance" table, an IP column shows:

- log.ip_address

### Audit log page

File:

- resources/views/admin/attendance/audit-log.blade.php

In "Audit Trail" table, an IP column shows:

- entry.ip_address

## 6) Related admin routes

Defined in routes/web.php under admin group:

- GET /admin/dashboard
- GET /admin/attendance
- GET /admin/attendance/{studentId}
- POST /admin/attendance/{logId}/override
- GET /admin/audit-log

Middleware protection:

- auth
- role:super_admin

So only super admin can access these pages.

## 7) Why IP is useful

1. Detect suspicious repeated attendance from same network.
2. Support investigation when attendance is disputed.
3. Keep audit visibility for admin overrides and actions.

## 8) Important note for localhost demo

If testing on same machine, many entries may show:

- 127.0.0.1

That is expected in local development.

## 9) Simple admin-side sequence

1. Student checks in/out from kiosk.
2. Service stores attendance with IP.
3. Service also writes audit row with IP.
4. Admin opens dashboard to see recent logs + IP.
5. Admin opens audit log to see action history + IP.
