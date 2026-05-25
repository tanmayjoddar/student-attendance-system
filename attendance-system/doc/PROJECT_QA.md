# Attendance System — Professional Q&A & Technical Defense Guide

**Purpose:** This document provides an exhaustive, presentation-ready Q&A covering the system's architecture, biometric logic, security protocols, and operational workflows. It is designed to demonstrate deep technical ownership during a viva or project demonstration.

---

## 1. High-Level Architecture & Philosophy

**Q: Can you provide a technical overview of the system architecture?**
**A:** The system follows a decoupled, service-oriented architecture with three tiers:
- **Laravel Backend (PHP 8.2)** — handles business logic, authentication, persistence via Eloquent ORM, and admin reporting. Uses PostgreSQL, database-driven sessions/cache/queue, and runs on `127.0.0.1:8000`.
- **Frontend (Blade + Vanilla JS)** — utilizes **MediaPipe FaceMesh (WASM)** running client-side in the browser for real-time 468-landmark facial tracking. No face data leaves the browser until liveness is confirmed.
- **External ML Microservice (FastAPI/Python on :8001)** — receives captured frames, extracts deep-learning face embeddings (128-d or 512-d vectors), and compares against a `face_encodings` store using cosine similarity.
- **Geolocation Pipeline** — multi-provider fallback chain from browser GPS → Nominatim reverse geocode → IP geolocation (ipwho.is, ipapi.co client-side; ip-api.com, ipapi.co server-side).

Data integrity is maintained through **database transactions** (attendance log + audit trail in one atomic write) and a comprehensive **audit trail** capturing old/new values, user_id, and IP for every mutation.

**Q: Why was a "Kiosk-First" approach chosen over traditional student logins?**
**A:** Two reasons — **Efficiency** and **Integrity**.
- **Efficiency:** A shared kiosk eliminates the need for every student to remember credentials or carry ID cards. The entire process — walk up, look at camera, get identified, attendance recorded — takes under 15 seconds.
- **Integrity:** Traditional username/password login can be shared or stolen ("buddy punching"). Biometric liveness verification ensures the actual person is physically present. The randomized challenge system (2 of 6 possible movements) makes pre-recorded video playback attacks ineffective.

**Q: Why is the ML service kept external to the Laravel application?**
**A:** Separation of concerns and resource isolation. Face recognition involves:
- **Heavy computation:** Tensor operations, CNN inference, high memory usage (Python with InsightFace/Dlib).
- **Different scaling needs:** You might want to run the ML service on a GPU instance while keeping the web server on cheap CPU hardware.
- **Technology mismatch:** Python's ML ecosystem (NumPy, OpenCV, PyTorch/TensorFlow) is superior for this task. Trying to do face recognition in PHP would be inefficient.
- **Independent failure domain:** If the ML service goes down, the web app is still up for admin tasks; the kiosk simply shows "identification unavailable."

The ML service runs on `127.0.0.1:8001` by default. Laravel proxies certain endpoints through `FaceVerificationController` (register, delete), while the kiosk JS calls `/identify/` directly for lower latency.

**Q: What is the technology stack, and why were these choices made?**
**A:**
| Layer | Technology | Reason |
|-------|-----------|--------|
| Backend | Laravel 12 + PHP 8.2 | Rapid development, Eloquent ORM, built-in auth, rate limiting, CSRF protection |
| Database | PostgreSQL | Robust JSON support (for face_signature, verification_meta), concurrent transaction safety, CHECK constraints for enum emulation |
| Frontend | Blade + Vanilla JS + Bootstrap 5 CDN | Zero build-step for CSS (CDN), MediaPipe FaceMesh for WASM-accelerated face tracking |
| ML | FastAPI (Python) | High-performance async Python framework, natural fit for ML inference pipelines |
| Face Tracking | MediaPipe FaceMesh (WASM) | 468 3D landmarks in real-time at 30+ FPS, runs entirely in browser, no server load |
| PDF Export | Dompdf | PHP-native HTML-to-PDF, no external dependencies, renders Blade templates directly |
| Tunneling | ngrok | HTTPS tunnel for local development (Camera + Geolocation APIs require secure context) |

---

## 2. Biometric Liveness & Identification Logic

**Q: What exactly is MediaPipe FaceMesh, and how does it work in this project?**
**A:** MediaPipe FaceMesh is a Google-developed machine learning model that runs as WebAssembly (WASM) in the browser. It takes a video frame as input and outputs **468 3D facial landmark coordinates** in real-time. Each landmark (index 0–467) corresponds to a specific point on the face — eye corners, nose tip, lips, eyebrows, face contour, etc.

We load it from CDN (`@mediapipe/face_mesh`) and configure it with:
- `maxNumFaces: 1` — only track one face at a time for security
- `refineLandmarks: true` — enables iris landmarks for better eye tracking
- `minDetectionConfidence: 0.6` — minimum confidence to accept a face
- `minTrackingConfidence: 0.6` — minimum confidence to maintain tracking

Each frame from the webcam is sent to `faceMesh.send({ image: videoElement })`, and the `onResults` callback receives the landmark array. We then use these landmarks to:
1. Detect liveness through active challenges
2. Extract a normalized face signature (80 points × 2 coordinates = 160-value vector)

**Q: The doc mentions 2 liveness challenges, but are 6 challenges actually implemented? Explain each one's algorithm.**
**A:** Yes, there are **6 possible challenges** in the code (`kiosk.blade.js:126-207`), and **2 are randomly selected** per session via `pickChallenges()`:

1. **Blink (EAR-based):** Uses Eye Aspect Ratio. We compute `EAR = vertical_dist / horizontal_dist` for both eyes using landmarks [159,145,33,133] (left) and [386,374,362,263] (right). When EAR drops below 0.20 (eye closes) then rises above 0.22 (eye reopens), a blink is counted. We require the "reopened" state to persist for 8 frames to avoid noise.

2. **Turn Left:** Calculates yaw by comparing nose tip (landmark 1) x-coordinate against the midpoint of left/right eye landmarks [33,263]. If `nose.x - midpoint.x < -0.04`, the head is turned left.

3. **Turn Right:** Same formula, reversed condition: `nose.x - midpoint.x > 0.04`.

4. **Open Mouth:** Measures vertical distance between upper lip (landmark 13) and lower lip (landmark 14). When `|y13 - y14| > 0.04`, mouth is open.

5. **Nod Down:** Uses pitch estimation from nose bridge. Compares current `(nose.y - chin.y)` against a baseline built over the first frame. When pitch increases by more than 0.03, head is nodded down.

6. **Raise Eyebrows:** Measures distance between left eye top (landmark 159) and left eyebrow (landmark 70). Builds an **adaptive baseline** over the first 20 frames (running average) to handle people who start with raised brows. When distance increases by >0.018 above baseline, brows are raised.

Each successful challenge contributes **50 points** to the liveness score. Once both are done (score = 100) and at least 15 live frames have been processed, identification triggers.

**Q: Why randomize the challenges each session?**
**A:** To prevent replay attacks. If the challenges were always the same (e.g., always "blink + turn left"), an attacker could record a video of the student performing those exact movements. With random selection of 2 from 6 possibilities (15 combinations), a pre-recorded video is unlikely to match the required challenge pair. Additionally, the order is randomized.

**Q: What happens after the liveness challenges are passed?**
**A:** Step-by-step:
1. Liveness confirmed (both challenges done + 15+ live frames).
2. A **5-second countdown overlay** appears ("Look straight at camera and stay still") — this ensures a neutral, forward-facing frame for optimal identification accuracy.
3. After countdown, a frame is captured using `canvas.toBlob()` at 92% JPEG quality.
4. The blob is POSTed via `FormData` to the ML service at `http://127.0.0.1:8001/identify/`.
5. The ML service extracts a deep-learning face embedding and compares it against the stored `face_encodings` database using cosine similarity.
6. Returns `{ok: true, matched: true, user_id: "STU-...", confidence: 87.5}` or `{ok: true, matched: false, message: "..."}`.
7. If matched, the kiosk proceeds to auto check-in/out. If not, shows "Not Recognized" and resets after 5 seconds.

**Q: What is the significance of the `match_score` threshold?**
**A:** The threshold (default 60%, configurable via `ATTENDANCE_FACE_MIN_MATCH` env or `config/attendance.php`) controls the False Acceptance Rate (FAR) vs False Rejection Rate (FRR) trade-off:
- **Higher threshold (e.g., 80%):** More secure — fewer false matches. But students might need better lighting, closer alignment with camera, or may be rejected if appearance changed (glasses, haircut).
- **Lower threshold (e.g., 50%):** More convenient — fewer false rejections. But increases the risk of misidentifying one student as another.

Additionally, the **liveness_score threshold** (default 75) is enforced separately in `AttendanceService::validateFaceVerification()`. Both must pass: `liveness >= 75 AND match >= 60`.

---

## 3. Face Registration & Signature System

**Q: How does a new student self-register without admin involvement?**
**A:** The self-registration flow (`/student-register`) allows any person to register as a student without needing an admin account. The flow:
1. Student fills in personal details: first_name, last_name, father_name, mother_name, address, email, phone, department.
2. Student opens camera and captures **two photos** — Photo 1 (front-facing) and Photo 2 (slightly turned head, left or right).
3. Photo 1 is processed client-side through MediaPipe FaceMesh to extract a **face signature** — 80 selected landmark indices normalized into a 160-value float vector.
4. Both photos (as base64 JPEG) and the signature vector are submitted to `StudentSelfRegistrationController@store`.
5. Backend validates email uniqueness, checks for duplicate first+last name (case-insensitive), generates a unique student ID (`STU-YYMMDD-RANDOM4`), saves Photo 1 to `storage/app/public/students/`, stores the face signature as JSON in the `students.face_signature` column.
6. A background attempt is made to register both photos with the ML service at `http://127.0.0.1:8001/register/` with a 1-second timeout (non-blocking — if ML is down, registration still completes and ML can be updated later).

**Q: Why two photos instead of one for registration?**
**A:** Two photos from slightly different angles (front + slightly turned) provide the ML service with multiple perspectives of the face. This significantly improves the quality of the generated face encoding because:
- The ML model can learn a more robust representation by seeing the face from slightly different angles.
- Lighting variations between the two captures reduce sensitivity to lighting conditions during identification.
- If one photo is blurred or has poor quality, the second serves as a backup.

The user is prompted to "SLIGHTLY turn your head left or right" between captures, not a full profile view — just enough angle variation for better encoding.

**Q: What is the "face signature" and how is it extracted?**
**A:** The face signature is a **client-side geometric fingerprint** — not the same as the ML deep-learning embedding. It's extracted using this algorithm in `register-student.blade.js:181-205`:

1. Select 80 specific landmark indices (key facial features: eye corners, nose bridge, lips, eyebrows, chin, face contour).
2. Compute face center as the midpoint of the two eye outer corners: `cx = (le.x + re.x) / 2, cy = (le.y + re.y) / 2`.
3. Compute normalization factors: `eyeDist` (distance between eyes) and `faceH` (chin-to-forehead distance).
4. For each of the 80 selected landmarks, normalize coordinates: `(landmark.x - cx) / eyeDist` and `(landmark.y - cy) / faceH`.
5. This produces 80 × 2 = **160 float values**, stored as a JSON array in the database.

This signature is scale-invariant and rotation-normalized. It's used as a secondary biometric reference stored locally in the Laravel DB, separate from the ML service's face encodings.

**Q: What's the difference between the client-side face_signature and the ML service's face encoding?**
**A:**
| Aspect | Client-side face_signature | ML service face_encoding |
|--------|---------------------------|-------------------------|
| **Algorithm** | Geometric normalization of 80 MediaPipe landmarks | Deep convolutional neural network (e.g., InsightFace or FaceNet) |
| **Vector size** | 160 floats (80 × 2 coordinates) | Typically 128 or 512 floats |
| **Computation** | Arithmetic only — runs in milliseconds in JS | Heavy tensor ops — runs in Python with GPU support |
| **Storage** | In Laravel's `students.face_signature` (JSON column) | In external ML microservice `face_encodings` database |
| **Purpose** | Secondary verification, fallback reference | Primary identification — used for cosine similarity matching |
| **Robustness** | Sensitive to extreme angles, lighting | More robust — trained on millions of faces |

The client-side signature is a "quick fingerprint" while the ML encoding is the primary identification mechanism.

**Q: How is the student ID generated?**
**A:** In `StudentSelfRegistrationController::generateStudentId()`, the format is: `STU-YYMMDD-RANDOM4`.
- `STU` = literal prefix for "Student"
- `YYMMDD` = current date (e.g., `250525` for May 25, 2025)
- `RANDOM4` = 4 uppercase alphanumeric characters via `Str::random(4)`
- A uniqueness check loop ensures no collision with existing IDs.

Example: `STU-250525-XK7M`

---

## 4. Kiosk Automatic Attendance Flow

**Q: How does the kiosk decide whether to check-in or check-out a student automatically?**
**A:** The logic is in `handleMatched()` in `kiosk.blade.js:544-630`:
1. After ML identification returns a `student_id`, the kiosk **first tries auto check-in** via `POST /attendance/auto-checkin`.
2. If check-in succeeds (`checkInResult.ok === true`), it shows "Check-In Successful" and resets.
3. If check-in returns `type: 'already_done'` (HTTP 409 — meaning student already checked in today), the kiosk **automatically tries auto check-out** via `POST /attendance/auto-checkout`.
4. If check-out succeeds → shows "Check-Out Successful".
5. If check-out also returns `already_done` → shows "Attendance Already Recorded" (both in and out done for the day).

This means the student just looks at the camera once, and the system handles the correct action automatically. No button pressing, no student selection.

**Q: What data is sent in the auto check-in/out request?**
**A:** The POST to `/attendance/auto-checkin` or `/attendance/auto-checkout` sends JSON:
```json
{
  "student_id": "STU-250525-XK7M",
  "liveness_score": 100,
  "match_score": 87.5,
  "geo_address": "123 Main St, City, Country",
  "geo_latitude": 28.6139391,
  "geo_longitude": 77.2090212,
  "geo_accuracy": 12.5
}
```

The backend `AttendanceService::autoCheckIn()` validates:
- Time window (configurable, default 00:00–23:59)
- No duplicate check-in for today (throws `DuplicateAttendanceException` with HTTP 409)
- Liveness score and match score are stored in `verification_meta` JSON

**Q: What is the 5-second countdown before capture? Why not capture immediately?**
**A:** After liveness challenges are completed, we show a 5-second countdown overlay with the message "Look straight at camera and stay still". This serves two purposes:
1. **Quality capture:** During the challenges, the student was moving (turning head, blinking, nodding). The countdown allows them to return to a neutral, forward-facing position, resulting in a higher-quality frame for identification.
2. **Anti-spoof:** The gap between challenge completion and capture makes it harder to swap a photo or device during the flow.

The countdown is rendered in the `triggerIdentification()` function with `setInterval` decrementing from 5 to 0.

**Q: What happens after a successful attendance recording? How does the kiosk reset?**
**A:** After success, the kiosk:
1. **Shows a success overlay** for 8 seconds with student name, date, and time (green for check-in, blue for check-out).
2. After 8 seconds, `scheduleReset()` is called which: hides the result box, resets all challenge state variables, sets status to "Ready — complete the challenges to record attendance."
3. The camera keeps running — the next student can simply step up and begin.

On error or "already done", the reset happens after 5–6 seconds. On "Not Recognized" (face not matched), reset after 5 seconds.

---

## 5. Anti-Cheat & Security Mechanisms

**Q: What are all the anti-cheat measures implemented in this system?**
**A:** There are **10 layers**:

1. **Liveness Challenges** — Random 2 of 6 active challenges (blink, head turn, mouth open, nod, eyebrow raise). Prevents photo/video replay attacks.

2. **ML Face Identification** — Deep-learning embedding comparison with configurable threshold. Prevents a different person from using the system.

3. **Server Time Enforcement** — `recorded_time` is always `Carbon::now()` server time, never trusted from browser. `stated_time` (student-claimed time) is validated against server time with a strict grace period.

4. **Duplicate Prevention** — Database UNIQUE constraint on `(student_id, date, type)` + application-level check in `AttendanceService`. Throws `DuplicateAttendanceException` (HTTP 409).

5. **Check-in Before Check-out** — `autoCheckOut()` queries for a check-in today; throws error if none found.

6. **Time Window** — configurable `check_in_window.start` / `check_in_window.end` (default 00:00–23:59). Outside these hours, check-in is rejected.

7. **Grace Period Validation** — `stated_time` cannot be in the future, and cannot backdate more than `grace_period_minutes` (default 15). This prevents students from claiming they arrived much earlier than they did.

8. **Rate Limiting** — `throttle:attendance` middleware limits to 5 requests per minute per IP address. Configured in `AppServiceProvider` and `config/attendance.php`.

9. **IP Logging & Suspicious Detection** — Every attendance record stores `ip_address`. `detectSuspiciousIPs()` finds IPs with >5 check-ins within a 2-minute sliding window — indicating possible automated abuse.

10. **Audit Trail** — Every creation and admin override is logged with old/new values, user_id, and IP address. Admins must provide a reason for overrides.

**Q: How exactly does the rate limiter work?**
**A:** The rate limiter is registered in `AppServiceProvider::boot()` using Laravel's `RateLimiter` facade:
```php
RateLimiter::for('attendance', function (Request $request) {
    return Limit::perMinute((int) config('attendance.rate_limit.max_attempts', 5))
        ->by($request->ip());
});
```
- **Key:** IP address of the requester
- **Limit:** 5 attempts per minute (configurable via `config/attendance.php.rate_limit.max_attempts`)
- **Applied to:** All attendance POST routes via `->middleware('throttle:attendance')` in `routes/web.php`
- **When exceeded:** Laravel automatically returns HTTP 429 Too Many Requests

The middleware alias `'attendance' => 'throttle:5,1'` in `bootstrap/app.php` is a fallback alias that maps to the named limiter.

**Q: How does the suspicious IP detection algorithm work?**
**A:** `AttendanceService::detectSuspiciousIPs()` implements a **sliding window** algorithm:
1. Fetch all check-in records ordered by IP then time.
2. Group records by IP address.
3. For each IP's records, use a nested loop: for each record, count how many other records from the same IP fall within a **2-minute (±120 second) window**.
4. If any 2-minute window contains `>= threshold` records (default 5 from config), that IP is flagged as suspicious.
5. Returns an associative array: `{ "192.168.1.1": [log_id_1, log_id_2, ...] }`

This catches scenarios where a single computer is scripting automated check-ins for multiple students.

**Q: How is CSRF protection implemented?**
**A:** Laravel's built-in CSRF protection is active on all POST/PUT/DELETE routes:
- A `<meta name="csrf-token" content="{{ csrf_token() }}">` is in the layout's head.
- AJAX requests from the kiosk read this token and send it as `X-CSRF-TOKEN` header.
- All Blade forms include `@csrf`.
- Laravel automatically validates the token on every state-changing request.

**Q: How does the `submitted_by` field get resolved when a student uses the kiosk without logging in?**
**A:** `AttendanceService::resolveSubmittedByUserId()` handles this:
1. If a user is authenticated (e.g., admin or student logged in), their ID is used.
2. If not authenticated (kiosk mode — no login), it falls back to the first `super_admin` user in the database.
3. If no super admin exists, it throws `RuntimeException('No super admin user found. Please seed admin first.')`.

This ensures the foreign key constraint is always satisfied while allowing unauthenticated kiosk check-ins.

---

## 6. Geolocation System

**Q: Explain the full geolocation pipeline in detail.**
**A:** The system has a **multi-layered fallback chain** with 5 providers spanning client and server:

**Client-side (in kiosk.blade.js `collectGeoData()`):**
1. **Primary — Browser GPS:** Uses `navigator.geolocation.getCurrentPosition()` with `enableHighAccuracy: true`, 15s timeout, 5-min cache. Returns lat/lng/accuracy.
2. **Reverse Geocode:** On GPS success, calls `https://nominatim.openstreetmap.org/reverse` to convert coordinates to a human-readable address.
3. **Fallback — ipwho.is:** If GPS fails/denied, fetches `https://ipwho.is/?fields=latitude,longitude,city,region,country`.
4. **Fallback — ipapi.co:** If ipwho.is fails, fetches `https://ipapi.co/json/`.

**Server-side (in AttendanceService::ipGeolocation()):**
5. If client-side geo completely fails (all 4 methods return null), the Laravel backend tries IP-based fallback:
   - For localhost (`127.0.0.1`, `::1`): queries external providers without IP to get the server's public IP location.
   - For real IPs: queries `http://ip-api.com/json/{ip}` and `https://ipapi.co/{ip}/json/`.

All results (or nulls) are stored in `attendance_logs.geo_address`, `.geo_latitude`, `.geo_longitude`, `.geo_accuracy`.

**Q: Why is HTTPS required for geolocation and camera?**
**A:** Both the **Geolocation API** (`navigator.geolocation`) and the **MediaDevices API** (`navigator.mediaDevices.getUserMedia` for camera) are considered "powerful features" by the W3C and browser vendors. Modern browsers (Chrome 50+, Firefox 55+, Safari 12+) **require a secure context (HTTPS or localhost)** to use these APIs. Over plain HTTP:
- `navigator.geolocation` returns `PositionError` with code 1 (PERMISSION_DENIED)
- `navigator.mediaDevices.getUserMedia` throws `NotAllowedError`

This is why the project includes `ngrok.exe` — it creates an HTTPS tunnel to the local development server so these APIs work during testing.

---

## 7. Security & Infrastructure

**Q: What is the middleware stack protecting different routes?**
**A:**
| Route Group | Middleware | Purpose |
|-------------|-----------|---------|
| Kiosk `/attendance/*` | `throttle:attendance` | Rate limit check-in/out to 5/min/IP |
| Face API `/api/*` | None (public) | ML service proxy needs to be accessible from kiosk JS |
| Self-register `/student-register` | `throttle:attendance` | Prevent registration spam |
| Admin `/admin/*` | `auth`, `role:super_admin` | Only logged-in admins can access |
| Student `/student/*` | `auth`, `role:student` | Only logged-in students can access |
| Server time `/api/server-time` | None | Public endpoint for clock sync |

The `role` middleware is defined in `app/Http/Middleware/RoleMiddleware.php` and registered in `bootstrap/app.php` via `$middleware->alias(['role' => ...])`.

**Q: How does `ngrok` fit into the project, and is it required?**
**A:** `ngrok` is a tunneling tool that creates a public HTTPS URL forwarding to your local server. It's included as a convenience (`ngrok.exe` in the project root) because:
1. Camera and Geolocation APIs require HTTPS (as explained above).
2. During local development (`php artisan serve` on `http://127.0.0.1:8000`), these APIs won't work due to insecure context.
3. Running `ngrok http 8000` gives a `https://xxxx.ngrok.io` URL that securely tunnels to your local server.

It's **not required** if you deploy to a production server with real HTTPS, or if you test in an environment that already has HTTPS. But for local demo, it's essential.

**Q: How is sensitive biometric data (face signatures, photos) protected?**
**A:** Multiple layers:
1. **Access Control:** `RoleMiddleware` ensures only `super_admin` users can access admin routes. Student routes require `role:student`.
2. **Storage:** Photos are stored in `storage/app/public/students/` — accessible only via Laravel's storage link, not directly exposed.
3. **No Raw Images in DB:** Face signatures are stored as normalized float vectors (160 numbers) in a JSON column, not raw images. Even if the DB is compromised, reconstructing a face from these normalized vectors is extremely difficult.
4. **Client-Side Processing:** MediaPipe FaceMesh processes frames entirely in the browser's WASM runtime. Raw video frames are never sent to any server — only the final cropped frame (for ML identification) and the geometric signature.

**Q: What database is used and why PostgreSQL over SQLite or MySQL?**
**A:** PostgreSQL 16 via `pgsql` driver. Reasons:
- **JSON support:** PostgreSQL has mature, performant JSON/JSONB operators for querying the `face_signature` and `verification_meta` JSON columns.
- **CHECK constraints:** Laravel's `enum()` on PostgreSQL creates VARCHAR + CHECK constraint, which is more flexible than MySQL's native ENUM.
- **Concurrency:** Better handling of concurrent transactions with MVCC (Multiversion Concurrency Control).
- **Real-world relevance:** PostgreSQL is the industry standard for production Laravel applications.

**Q: How are the queue, cache, and session drivers configured?**
**A:** All are database-driven:
- `QUEUE_CONNECTION=database` — uses the `jobs` table for async queue processing
- `CACHE_STORE=database` — uses the `cache` table for cache storage
- `SESSION_DRIVER=database` — uses the `sessions` table for session storage

This keeps infrastructure minimal — no Redis or Memcached needed. The database serves as the single state store, which is appropriate for the project's scale. The `config/cache.php`, `config/queue.php`, and `config/session.php` files are standard Laravel defaults.

---

## 8. Database Schema Deep Dive

**Q: Why separate `students` from `users`?**
**A:** **Separation of Concerns.** The `users` table handles authentication concerns (email, password, role, remember_token). The `students` table handles domain-specific profile data (department, semester, guardian fields, face signature). This means:
- An admin user doesn't have student fields (no null clutter).
- A student can have their user account disabled without losing their attendance history.
- If authentication requirements change (e.g., adding OAuth), the students table is unaffected.

**Q: What are all the columns in the `attendance_logs` table?**
**A:** Created across multiple migrations, the complete schema:
| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint PK | Auto-increment ID |
| `student_id` | FK → students.id | Which student |
| `date` | date | Attendance date (indexed) |
| `type` | varchar(255) CHECK | 'in' or 'out' |
| `recorded_time` | timestamp | Server time when recorded (source of truth) |
| `stated_time` | timestamp nullable | Student-claimed time (for anti-cheat) |
| `ip_address` | varchar(45) | Client IP address |
| `geo_address` | text nullable | Human-readable address from GPS/IP |
| `geo_latitude` | decimal(10,7) nullable | GPS latitude |
| `geo_longitude` | decimal(10,7) nullable | GPS longitude |
| `geo_accuracy` | decimal(8,2) nullable | GPS accuracy in meters |
| `is_flagged` | boolean | Whether record is suspicious |
| `face_verified` | boolean | Whether face verification passed |
| `liveness_score` | decimal(5,2) | Biometric liveness score (0–100) |
| `verification_meta` | json nullable | Detailed verification data |
| `submitted_by` | FK → users.id | Who created the record |
| `created_at` / `updated_at` | timestamp | Laravel timestamps |

**Unique constraint:** `(student_id, date, type)` — prevents duplicate check-in/out per day.

**Q: What does the `verification_meta` JSON contain?**
**A:** For manual check-in (`checkIn`/`checkOut` methods):
```json
{
  "match_score": 85.3,
  "spoof_passed": true,
  "blink_count": 3,
  "yaw_variance": 0.12,
  "verified_at": "2026-05-25T10:30:00+00:00"
}
```

For auto check-in/out (`autoCheckIn`/`autoCheckOut` methods):
```json
{
  "match_score": 91.2,
  "spoof_passed": true,
  "auto_identified": true,
  "verified_at": "2026-05-25T10:30:00+00:00"
}
```

The `auto_identified: true` flag distinguishes ML-driven automatic attendance from manual face verification.

**Q: What is the `audit_trail` table schema?**
**A:**
| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint PK | Auto-increment |
| `action` | varchar(255) | 'create', 'override', etc. |
| `model_type` | varchar(255) | `App\Models\AttendanceLog` |
| `model_id` | unsignedBigInt | ID of the affected record |
| `old_values` | json nullable | Previous state (before change) |
| `new_values` | json nullable | New state (after change) |
| `user_id` | FK → users.id nullable | Who performed the action |
| `ip_address` | varchar(45) nullable | IP of the actor |
| `created_at` / `updated_at` | timestamp | Laravel timestamps |

**Index:** `(model_type, model_id)` for efficient lookups.

---

## 9. Admin Features

**Q: What does the admin dashboard show?**
**A:** `AdminDashboardController@index` provides:
1. **Stat cards:** Total active students, Present today, Absent today (calculated as `total - present`).
2. **Recent Attendance table:** Last 10 records with columns: Student Name, Type (in/out with color badge), Time, IP Address, Location (geo_address, truncated to 42 chars), Flags (is_flagged badge, face_verified badge).
3. **Flagged Records table:** Records marked as suspicious (is_flagged = true) with student name, date, type, and reason.

**Q: How do CSV and PDF exports work?**
**A:** Both are accessed via admin routes `admin.students.export.csv` and `admin.students.export.pdf`.
- **CSV:** Uses PHP's `StreamedResponse` with `fputcsv()`. Streams output directly to the browser without loading all data into memory — suitable for large datasets. Headers include Content-Type `text/csv` and Content-Disposition for download.
- **PDF:** Uses `Dompdf\Dompdf` library. Renders a Blade view (`admin.students._export_table`) into HTML, then converts to PDF. Falls back to HTML download if Dompdf is not installed.

Both require a date range filter (`from_date`, `to_date`). Date validation checks that start ≤ end. The filename format is `students_export_YYYYMMDD_to_YYYYMMDD.{ext}`.

**Q: How does the admin override feature work?**
**A:** When an admin needs to correct an attendance record:
1. Admin navigates to student's attendance detail page.
2. Clicks "Override" and provides a new time and required reason (min 10 chars).
3. `AdminAttendanceController@override()` calls `AttendanceService::adminOverride()`:
   - Updates `stated_time` to the new time.
   - Sets `is_flagged = true` to mark the record as modified.
   - Creates an audit trail entry with `action = 'override'`, `old_values` (previous record state), `new_values` (updated state), and the admin's `reason`.
4. The original `recorded_time` is preserved (immutable server timestamp). Only `stated_time` is modified.

This ensures full forensic transparency — the original server timestamp is never lost.

**Q: How does student deletion cascade?**
**A:** `AdminStudentController@destroy()` implements a **5-step cascade**:
1. **ML Service:** Sends `DELETE /delete/{studentId}` to FastAPI to remove face encoding.
2. **Photo File:** Deletes the student's photo from `storage/app/public/students/`.
3. **Attendance Logs:** Deletes all attendance records for this student.
4. **User Account:** Deletes the associated user login account.
5. **Student Record:** Deletes the student record.

Steps 3–5 are not wrapped in a DB transaction (individual deletes), and if the ML service is unreachable, it's logged as a warning — the local deletion proceeds.

---

## 10. Student Dashboard

**Q: What features does the student dashboard provide?**
**A:** The authenticated student dashboard (`/student/dashboard`) shows:
1. **Today's Status:** Whether the student has checked in/out today with timestamps.
2. **Check-in/Check-out buttons** (if student is logged in).
3. **Heatmap:** Full-year color-coded attendance calendar (GitHub contribution graph style).
4. **Recent Attendance:** Last 10 attendance records.
5. **Server Time:** Updated via AJAX every 30 seconds from `/api/server-time`.

**Q: How is the heatmap rendered?**
**A:** The heatmap (GitHub contribution graph style):
- **Data:** `AttendanceService::getHeatmapData()` generates an array of 365 entries (one per day), each with `{date, status, log_id}`.
- **Statuses:** Each day is classified as:
  - **Present (dark green #216e39):** Checked in on time (before cutoff)
  - **Late (medium green #40c463):** Checked in after cutoff but within 30 min
  - **Very Late (light green #9be9a8):** Checked in more than 30 min after cutoff
  - **Half Day (orange #f0a500):** Checked in but no check-out
  - **Absent (gray #ebedf0):** No record
  - **Weekend:** Gray (skipped in loop)
- **Rendering:** CSS grid of 10×10px squares with 3px gap, 52 columns (weeks), with month labels and day-of-week labels. Pure Blade/CSS — no JavaScript charting libraries.
- **Tooltip:** CSS `::after` pseudo-element on hover shows date, in/out times, status.

---

## 11. ML Microservice Details

**Q: What are all the ML service endpoints and their purposes?**
**A:** The FastAPI microservice (running at `http://127.0.0.1:8001`) exposes:

| Endpoint | Method | Called From | Purpose |
|----------|--------|-------------|---------|
| `/register/` | POST | `StudentSelfRegistrationController`, `FaceVerificationController` | Register 2 face images for a user_id, generate and store face encoding |
| `/identify/` | POST | Kiosk JS (direct, no proxy) | Upload a face frame, compare against stored encodings, return best match user_id + confidence |
| `/delete/{user_id}` | DELETE | `FaceVerificationController`, `AdminStudentController` | Remove a user's face encoding from the store |

**Important:** The `/identify/` endpoint is called **directly** from browser JavaScript (not proxied through Laravel) to minimize latency. The register and delete endpoints are proxied through Laravel because they're triggered from server-side code (controllers).

**Q: What happens if the ML service is unreachable?**
**A:** Three layers of error handling:
1. **Kiosk JS `captureAndIdentify()`:** The `fetch()` to `http://127.0.0.1:8001/identify/` is wrapped in try-catch. On network error, it calls `handleError('Network error. Check ML service is running.')`, shows an error overlay, and resets after 5 seconds.
2. **FaceVerificationController:** Catches `Exception` from `Http::timeout(30)->post(...)` and returns HTTP 503 with `"ML service unreachable: {message}"`.
3. **StudentSelfRegistrationController:** The ML registration call has a 1-second timeout and is wrapped in try-catch with `Log::warning()`. Even if ML fails, the student's local registration (DB entry + photo + face_signature) succeeds.

---

## 12. Setup & Configuration

**Q: What are the default admin credentials?**
**A:** Seeded in `DatabaseSeeder.php`:
- **Email:** `nic@admin`
- **Password:** `bgh123`
- **Role:** `super_admin`

**Q: What configuration does `config/attendance.php` expose?**
**A:**
```php
'check_in_window' => [
    'start' => '00:00',   // Earliest allowed check-in time
    'end'   => '23:59',   // Latest allowed check-in time
],
'grace_period_minutes' => env('ATTENDANCE_GRACE_PERIOD', 15), // Max backdate allowed
'min_checkin_duration' => env('ATTENDANCE_MIN_DURATION', 30), // Min hours (not actively enforced)
'rate_limit' => [
    'max_attempts' => 5,  // Max check-in/out attempts per minute
    'decay_minutes' => 1,
],
'face' => [
    'min_match_score'    => env('ATTENDANCE_FACE_MIN_MATCH', 60),    // Min ML similarity (0-100)
    'min_liveness_score' => env('ATTENDANCE_FACE_MIN_LIVENESS', 75), // Min liveness score (0-100)
],
```

All values are configurable via `.env` variables at runtime.

**Q: What are the `.env` database and service configurations?**
**A:**
- **Database:** PostgreSQL on `127.0.0.1:5432`, database `nic_reg`, user `postgres`, password `nic`
- **Mail:** SMTP via Gmail (`smtp.gmail.com:587`) with TLS
- **Session/Cache/Queue:** All stored in database tables
- **Vite:** `VITE_APP_NAME` for the frontend build

**Q: How do you run the project?**
**A:** The development command from `composer.json`:
```bash
composer run dev
```
This runs 4 processes concurrently via `concurrently`:
1. `php artisan serve` (Laravel dev server)
2. `php artisan queue:listen --tries=1 --timeout=0` (Queue worker)
3. `php artisan pail --timeout=0` (Log viewer)
4. `npm run dev` (Vite dev server for JS bundling)

**Q: What is Vite used for in this project?**
**A:** Vite is used **only for JavaScript bundling**, not for CSS. The project:
- Uses Bootstrap 5 from CDN (no npm build needed for CSS).
- Uses Vite to bundle `resources/js/app.js` (which imports `bootstrap` JS and `axios`).
- Has NO `@vite()` directive in Blade layouts (CSS is loaded via CDN `<link>` tag).
- The Vite dev server provides HMR for JavaScript during development.

---

## 13. Troubleshooting & Resilience

**Q: What happens if the ML Service is offline?**
**A:** The kiosk frontend handles this gracefully:
- `captureAndIdentify()` catches the network error from `fetch()` to `http://127.0.0.1:8001/identify/`.
- Shows "Network error. Check ML service is running." in a red error overlay.
- Calls `scheduleReset(5000)` — the kiosk resets and retries after 5 seconds.
- No attendance is recorded until ML service is back online, because identification is required before attendance.

In the admin panel, the `FaceVerificationController` returns HTTP 503 with error message for register/delete operations.

**Q: How does the system handle "Localhost" geolocation?**
**A:** When running on localhost (`127.0.0.1`, `::1`), IP geolocation would return no meaningful data (it's a loopback address). The `AttendanceService::ipGeolocation()` method handles this:
1. Detects local IPs via `in_array($ip, ['127.0.0.1', '::1', 'localhost'])`.
2. Makes external requests to `ip-api.com` and `ipapi.co` **without sending an IP** — these services return the **server's public IP** location.
3. Returns the public IP's geographic data as fallback geo coordinates.

This ensures that even during local development with no GPS (no HTTPS), the attendance records have meaningful location context.

**Q: What happens if the database unique constraint is violated?**
**A:** The database has a `UNIQUE(student_id, date, type)` constraint on `attendance_logs`. If an attempt is made to insert a duplicate (same student, same day, same in/out type):
1. The application-level check in `AttendanceService` should catch it first (throws `DuplicateAttendanceException` for HTTP 409).
2. If the application check is bypassed (e.g., race condition), the database constraint acts as a **second line of defense**, throwing `QueryException` which Laravel converts to a 500 error.

The combination of application logic + database constraint ensures no duplicates, even under concurrent requests.

**Q: How does the kiosk handle session expiration?**
**A:** In `handleMatched()` (kiosk.blade.js:568-571), if the server returns HTTP 419 (CSRF token mismatch / session expired):
```javascript
if (checkInResp.status === 419) {
    setStatus('Session expired — reloading...', '#cf222e');
    setTimeout(() => location.reload(), 1000);
}
```
The page auto-reloads after 1 second, which refreshes the CSRF token (via the `<meta>` tag) and re-establishes the session.

---

## 14. Complete End-to-End Flow Summary

**Q: Walk me through the complete kiosk flow from a student walking up to attendance recorded.**
**A:**
1. **Page Load:** Student visits `/attendance` (or `/` which redirects). The kiosk page shows a camera area, status panel, and "Start Camera" button. Server time is displayed.

2. **Camera Init:** Student clicks "Start Camera". JavaScript creates a MediaPipe `Camera` object that requests webcam permissions. Once granted, video starts and frames are sent to `FaceMesh`.

3. **Face Detection:** MediaPipe detects the face and begins returning 468 landmarks. Status shows "Look at the camera and follow the instructions."

4. **Challenge Phase:** Two random liveness challenges are selected (e.g., "Blink your eyes" + "Turn your head LEFT"). The student performs these movements. Each completion adds 50 to liveness score. UI shows "Task 1: Done | Task 2: Pending".

5. **Countdown:** Both challenges complete + 15 live frames processed → 5-second countdown overlay ("Look straight at camera and stay still"). This ensures a quality capture.

6. **Capture & Identify:** A frame is captured as JPEG blob, POSTed to ML service at `http://127.0.0.1:8001/identify/`. The ML service returns `{user_id: "STU-...", confidence: 87.5}`.

7. **Auto Attendance:** Kiosk POSTs to `/attendance/auto-checkin`. If already checked in, falls back to `/attendance/auto-checkout`. Success response includes student name, date, time.

8. **Success Display:** Green/blue overlay shows "Check-In Successful" (or "Check-Out") with student name, date, and time. After 8 seconds, resets for next student.

9. **Geo Capture:** In parallel (started when camera opens), `collectGeoData()` runs — tries GPS → Nominatim → IP fallbacks. Geo data is attached to the attendance request.

**Total time:** ~10-15 seconds from camera start to attendance recorded.

**Q: What files are involved end-to-end?**
**A:**
- UI: `resources/views/attendance/kiosk.blade.php` (775 lines)
- Routes: `routes/web.php` (lines 15-54)
- Controller: `app/Http/Controllers/StudentDashboardController.php` (391 lines)
- Service: `app/Services/AttendanceService.php` (528 lines)
- Models: `app/Models/AttendanceLog.php`, `app/Models/AuditTrail.php`
- Config: `config/attendance.php`
- ML: External FastAPI at `127.0.0.1:8001`

---

## 15. Edge Cases & Limitations

**Q: What if two students are in the camera frame?**
**A:** MediaPipe is configured with `maxNumFaces: 1`. It will only track one face. The `onResults` callback checks `if (!results.multiFaceLandmarks?.length)` and shows "No face detected" if zero faces, but takes the **first** face if multiple are detected. This is intentional — the kiosk is designed for single-user use. In practice, the closest/largest face is tracked.

**Q: Could someone use a high-resolution photo or video to spoof the system?**
**A:** The combination of challenges makes this very difficult:
1. **Static photo:** Fails all movement-based challenges (blink, head turn, mouth open, nod, eyebrow raise).
2. **Pre-recorded video:** The challenge set is **random** (2 of 6), so the attacker would need a video of the student performing the exact two random challenges in the correct order.
3. **Deepfake/realtime face swap:** Extremely unlikely in a school setting. The liveness + ML identification + IP logging + rate limiting provide defense in depth.
4. **EAR (blink) detection** specifically checks for eye close → eye reopen sequence. A static photo has a single eye state and won't trigger the transition.

**Q: What are the known limitations of the system?**
**A:**
1. **Lighting Sensitivity:** MediaPipe landmark accuracy degrades in very low light or extreme backlight. Best results require even, front-facing lighting.
2. **ML Latency:** The `/identify/` call to FastAPI adds ~200-500ms latency. Very slow on CPU-only machines without GPU.
3. **Single Face Track:** Only one student at a time — no batch attendance.
4. **Localhost Geo Accuracy:** On localhost, IP geolocation gives network-level (ISP) location, not precise GPS coordinates.
5. **No Docker:** The setup requires manual PostgreSQL installation and configuration.
6. **No Push Notifications:** Students are not notified of attendance success/failure except via the browser UI.
7. **Browser Dependent:** Requires modern browser with WebGL support (for MediaPipe WASM) and camera access.

---

## 16. Testing & Deployment

**Q: How is the project tested?**
**A:** The project includes PHPUnit test scaffolding:
- `tests/Feature/ExampleTest.php` — Basic route behavior checks
- `tests/Unit/ExampleTest.php` — Unit test scaffold
- Run via `php artisan test` (aliased as `composer run test` which first clears config)

**Q: Does this project use Docker or containerization?**
**A:** No. The project is designed for **direct local setup**:
- PostgreSQL installed natively (via PgAdmin 4)
- PHP + Composer for Laravel
- Python + FastAPI for ML service
- All services run on `localhost` with specific ports
- `composer run dev` starts Laravel, queue, logs, and Vite concurrently

This was a deliberate choice for simplicity — no Docker knowledge required to run the project.

---

## 17. Comparison & Rationale

**Q: What is the difference between `checkIn`/`checkOut` and `autoCheckIn`/`autoCheckOut`?**
**A:**
| Aspect | checkIn/checkOut | autoCheckIn/autoCheckOut |
|--------|------------------|-------------------------|
| **Used by** | Authenticated students (logged in) | Kiosk (no login required) |
| **Student identification** | Via auth → user → student relationship | Via ML service face match → student_id |
| **Face verification** | Requires `face_verified`, `spoof_passed`, `liveness_score`, `match_score` all validated | `auto_identified: true` in meta — identification IS the verification |
| **stated_time** | Supported (anti-cheat via grace period) | Not supported (always null) |
| **Route** | `POST /student/check-in` (auth) | `POST /attendance/auto-checkin` (public + throttle) |
| **Controller method** | `StudentDashboardController@checkIn` | `StudentDashboardController@autoCheckIn` |

**Q: Why are there both `FaceVerificationController` proxy routes AND direct kiosk-to-ML calls?**
**A:** Two separate flows:
1. **Direct calls (kiosk JS → ML):** The `/identify/` endpoint is called directly from the browser because it's a real-time operation that needs minimal latency. Going through Laravel would add an unnecessary HTTP hop.
2. **Proxied calls (Laravel → ML):** The `/register/` and `/delete/` endpoints are proxied through `FaceVerificationController` because they're triggered from server-side code — during student registration (`StudentSelfRegistrationController@store`) and admin deletion (`AdminStudentController@destroy`). These operations are not time-sensitive.

**Q: Why was the EAR (Eye Aspect Ratio) threshold set to 0.20?**
**A:** The EAR threshold of 0.20 was chosen empirically:
- A normal open eye has EAR ≈ 0.25–0.35 (varies by person and face size).
- A closed eye has EAR ≈ 0.10–0.15.
- The threshold of 0.20 provides a safe margin: not so tight that natural squinting triggers a blink, not so loose that it's easily triggered.
- After blink detection (EAR < 0.20), we require reopening (EAR >= 0.22) to confirm the full blink cycle.
- The 8-frame hold adds temporal consistency — a single noisy frame won't falsely register a blink.

---

## 18. Additional Important Details

**Q: How does the `stated_time` validation algorithm work exactly?**
**A:** `AttendanceService::validateStatedTime()`:
1. If `stated_time` is null, skip validation (return null — no stated time provided).
2. Parse `stated_time` to Carbon.
3. Check if `stated_time > now + grace_period_minutes`. If yes → "Stated time cannot be in the future". This allows a small grace (default 15 min) for clock skew.
4. Check if `now - stated_time > grace_period_minutes`. If yes → student is trying to backdate too far. This prevents claiming arrival much earlier than actual.
5. If both pass, return the parsed time for storage.

**Q: What triggers the `is_flagged` field?**
**A:** `is_flagged` is set to `true` when:
1. An **admin override** is performed (`adminOverride()` sets `is_flagged = true`).
2. In the original manual check-in, if `stated_time` differs from `recorded_time` beyond the `flag_threshold_minutes` threshold.

In the auto check-in/out flow (ML identification), `is_flagged` is always `false` because there's no `stated_time` for comparison.

**Q: How is the server time refreshed on the kiosk UI?**
**A:** The kiosk polls `/api/server-time` every 30 seconds:
```javascript
setInterval(() => {
    fetch('/api/server-time')
        .then(r => r.json())
        .then(d => { document.getElementById('server-time').textContent = d.time; })
        .catch(() => {});
}, 30000);
```
The API endpoint (`StudentDashboardController@serverTime`) returns `{time: "14:30:00", iso: "2026-05-25T14:30:00+00:00"}`. The time is displayed in the top-right corner of the kiosk header.

**Q: What Bootstrap features are actually used vs custom CSS?**
**A:** The project intentionally minimizes Bootstrap usage:
- **Used:** Grid layout utilities (`d-flex`, `d-none`), CDN-loaded CSS reset, Bootstrap JS bundle for navbar collapse/modal functionality.
- **NOT used:** Bootstrap color utilities (`bg-primary`, `text-success`), card component, form validation styles, button classes (we use custom `.btn`, `.btn-primary`, `.btn-success` etc.).
- **Custom CSS:** GitHub-inspired design system defined in `layouts/app.blade.php` <style> block — variables for colors, custom Box component, custom tables, custom badges, custom stat cards.

The visual aesthetic targets GitHub's classic utilitarian style: high density, monochrome, functional, no decorative elements.

**Q: What auth controllers were kept and which were removed?**
**A:**
- **Kept:** `LoginController` (with role-based redirect), `ForgotPasswordController`, `ResetPasswordController`, `ConfirmPasswordController`, `VerificationController` — all generated by `laravel/ui --auth`.
- **Removed:** `RegisterController` (disabled via `Auth::routes(['register' => false])`). `HomeController` (deleted — home route removed). The `resources/views/auth/register.blade.php` exists but is unreachable.
- **Preference:** Admin creates students manually through the admin panel, or students self-register through the `/student-register` public route.

**Q: How are the camera fallbacks handled?**
**A:** `startCamera()` in `kiosk.blade.js:713-760`:
1. **Primary:** Uses `new Camera(camEl, ...)` from MediaPipe's `camera_utils.js`. This is the preferred method because it integrates directly with MediaPipe's frame loop.
2. **Fallback:** If MediaPipe Camera initialization fails (old browser, CDN failure), it falls back to native `navigator.mediaDevices.getUserMedia()` and runs the frame loop with `requestAnimationFrame`.
3. **On complete failure:** If both fail (permission denied, no camera), shows "Camera failed. Allow camera permission and try again." and re-enables the "Start Camera" button.

**Q: How are the two photos stored during self-registration?**
**A:** In `StudentSelfRegistrationController@store`:
1. **Photo 1 (front-facing):** Decoded from base64, saved to `storage/app/public/students/{studentId}.jpg` via `Storage::disk('public')->put()`. This serves as the student's profile photo.
2. **Photo 2 (turned):** Decoded from base64, written to a temp file (`sys_get_temp_dir()/{studentId}_photo2.jpg`), sent to ML service via HTTP multipart, then deleted.
3. Only Photo 1 is permanently stored locally. Photo 2 is used only for ML registration.

The photos are decoded from base64 by stripping the `data:image/jpeg;base64,` prefix and calling `base64_decode()`.

**Q: What does `AppServiceProvider` register?**
**A:** Only one thing — the named rate limiter:
```php
RateLimiter::for('attendance', function (Request $request) {
    return Limit::perMinute((int) config('attendance.rate_limit.max_attempts', 5))
        ->by($request->ip());
});
```
The `register()` method is empty. All other service providers are Laravel defaults.

