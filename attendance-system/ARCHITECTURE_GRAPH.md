# Attendance System — Component Graph (compact)

Goal: minimal token summary mapping major components and dataflows for AI ingestion.

- Web Client (Blade UI: resources/views/attendance/kiosk.blade.php)
  -> Camera + MediaPipe WASM (client-side)
  -> navigator.geolocation(), reverse-geocode (client)
  -> POST /attendance/auto-checkin, /attendance/auto-checkout (fetch)

- Laravel App (d:/reg/attendance-system)
    - Routes: routes/web.php (kiosk + admin) -> Controllers
    - Controllers
        - AttendanceController / Kiosk endpoints: validate input, call AttendanceService
        - AdminStudentController: exports (CSV, PDF)
    - Services
        - `app/Services/AttendanceService.php` (core logic)
            - normalizeGeoData() -> uses ipGeolocation() server-side fallback
            - checkIn/checkOut/autoCheckIn/autoCheckOut -> create AttendanceLog, call createAuditTrail
    - Models / DB
        - `app/Models/AttendanceLog.php` (stores geo fields: geo_address, geo_latitude, geo_longitude, geo_accuracy)
        - `app/Models/AuditTrail.php` (polymorphic audit entries)
        - `app/Models/Student.php`, `User.php`
    - Migrations / Seeders
        - database/migrations/\* (including geo fields migration)
        - DatabaseSeeder seeds super admin (nic@admin:bgh123)
    - Exports
        - AdminStudentController -> streams CSV or renders PDF via dompdf

- ML Microservice (external)
    - Face vector DB/table (`face_encodings`) — separate repo and DB
    - FastAPI / SQLAlchemy service used by Laravel for identification (HTTP API)

- External APIs
    - Reverse geocode: Nominatim (https://nominatim.openstreetmap.org)
    - IP geolocation: ip-api.com (http / pro-only https) and ipapi.co (https) — used server-side

Key Dataflows

- Kiosk client -> (face vector + liveness) -> Identification API (ML service) -> Laravel receives match/liveness -> AttendanceService.autoCheckIn/Out -> DB (attendance_logs) + AuditTrail
- Kiosk client -> navigator.geolocation() -> reverse-geocode (client) -> included in POST payload; if missing, AttendanceService.ipGeolocation() server-side fallback
- Admin UI -> Controller -> CSV/PDF exports (DB reads)

Notes / Recommendations (short)

- Avoid client calls to HTTP resources from HTTPS page: use relative URLs (e.g. `/api/server-time`) or ensure provider supports HTTPS.
- ip-api free plan blocks HTTPS; prefer server-side ip lookups or use `ipapi.co` (HTTPS) from browser.
- ML service DB/table (`face_encodings`) is external — run its migrations separately.

End of graph.
