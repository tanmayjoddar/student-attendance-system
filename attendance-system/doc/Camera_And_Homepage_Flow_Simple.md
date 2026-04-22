# Camera And Homepage Flow (Detailed + Simple)

This file explains the full kiosk flow in simple words but with enough depth for presentation.

## 1) What is kiosk in this project?

Kiosk means: a public attendance terminal page where students do not log in.

- One shared computer opens the attendance page.
- Student only selects their ID, verifies face liveness, and taps check-in/check-out.
- Admin login is separate and used only for monitoring and management.

Kiosk entry route:

- GET /attendance
- Route name: attendance.kiosk
- Defined in routes/web.php

## 2) Homepage to kiosk connection

1. User opens root URL /.
2. Root route redirects to attendance.kiosk.
3. Controller StudentDashboardController@kiosk loads active students.
4. Kiosk Blade page renders student dropdown + camera tools + forms.

Files involved:

- routes/web.php
- app/Http/Controllers/StudentDashboardController.php
- resources/views/attendance/kiosk.blade.php
- resources/views/layouts/app.blade.php

## 3) Camera opening flow

When user taps Start Camera:

1. JavaScript creates MediaPipe Camera object.
2. Browser asks webcam permission.
3. On allowed permission, live video starts in video element.
4. Each frame is sent into MediaPipe FaceMesh.

Key implementation location:

- resources/views/attendance/kiosk.blade.php

Main objects used:

- new FaceMesh(...)
- new Camera(...)
- faceMesh.onResults(...)

## 4) MediaPipe liveness flow (no photo cheating)

System uses liveness behavior, not static image matching.

Per frame logic:

1. FaceMesh returns facial landmarks.
2. Eye aspect ratio is calculated for blink detection.
3. Head yaw movement is calculated for left/right turn.
4. Liveness score is computed from blink + yaw movement.
5. If score reaches threshold, state becomes verified.

Why this helps:

- A printed photo usually cannot blink and perform natural head movement together.
- So fake attendance chance is reduced.

## 5) Check-in and check-out button enable rules

Current UX rule:

1. Student must be selected.
2. Liveness must be verified.
3. Verification must be fresh (time window).

Then action rules:

- Check In enabled only if student has not checked in today.
- Check Out enabled only if student has checked in today and not checked out today.

This is handled in kiosk JavaScript using per-student today state from backend.

## 6) Form submit data sent from kiosk

On submit, hidden fields carry verification info:

- student_id
- face_verified
- liveness_score
- match_score (kept for compatibility)
- blink_count
- yaw_variance

Routes:

- POST /attendance/check-in
- POST /attendance/check-out

Controller methods:

- StudentDashboardController@checkInPublic
- StudentDashboardController@checkOutPublic

## 7) Backend attendance business rules

Service file: app/Services/AttendanceService.php

Check In flow:

1. Validate liveness verification.
2. Prevent duplicate check-in for same day.
3. Enforce check-in time window from config/attendance.php.
4. Create attendance_logs row with IP and verification metadata.
5. Create audit_trail entry.

Check Out flow:

1. Validate liveness verification.
2. Ensure same-day check-in exists.
3. Prevent duplicate check-out for same day.
4. Create attendance_logs row with IP and verification metadata.
5. Create audit_trail entry.

Note:

- "Too soon after check-in" restriction has been removed.
- So checkout can be recorded immediately after check-in (if rules above pass).

## 8) Database write path

Main table for attendance:

- attendance_logs

Important columns:

- student_id
- date
- type (in/out)
- recorded_time
- ip_address
- face_verified
- liveness_score
- verification_meta (JSON)
- submitted_by

Model file:

- app/Models/AttendanceLog.php

## 9) Where admin sees this data

Admin pages:

- resources/views/admin/dashboard.blade.php (recent attendance)
- resources/views/admin/attendance/index.blade.php (daily summary)
- resources/views/admin/attendance/show.blade.php (student day detail)
- resources/views/admin/attendance/audit-log.blade.php (audit trail list)

Controllers:

- app/Http/Controllers/AdminDashboardController.php
- app/Http/Controllers/AdminAttendanceController.php

## 10) End-to-end sequence (easy to present)

1. Open /attendance.
2. Select student.
3. Start camera.
4. MediaPipe detects face, blink, head movement.
5. Liveness verified.
6. Check In enabled and submitted.
7. Record saved in attendance_logs.
8. Later Check Out enabled and submitted.
9. Admin panel reads same records and shows status/live tables.

## 11) One-line presentation summary

The kiosk page (camera + MediaPipe + buttons) collects live verification, then controller and service apply attendance rules, store records with IP/metadata, and admin dashboard shows the results in real time.
