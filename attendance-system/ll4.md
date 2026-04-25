This is a complete architectural redesign. Let me plan every single file change before writing any code.

---

## Full Change Plan

```
CURRENT FLOW:
  Select student → Camera → Liveness → Verify button → Check In button

NEW FLOW:
  Registration: Webcam capture instead of photo upload
  Kiosk: Camera starts → Liveness → Auto-identify WHO → Auto check-in

FILES CHANGING:
  1. face_rec.py                          ← NEW /identify/ endpoint (1:N matching)
  2. FaceVerificationController.php       ← NEW identify() method
  3. routes/web.php                       ← NEW auto-checkin route
  4. AttendanceService.php                ← NEW autoCheckIn() method
  5. StudentDashboardController.php       ← NEW autoCheckIn() action
  6. kiosk.blade.php                      ← Complete rewrite
  7. register-student.blade.php           ← Webcam capture + Register Face button
  8. StudentSelfRegistrationController.php ← Handle webcam blob instead of file upload
```

---

## File 1 — `face_rec.py` (add new endpoint)

Add this after the existing `/verify/` endpoint:

```python
@app.post("/identify/")
async def identify(image: UploadFile = File(...)):
    """
    1:N identification — compare face against ALL stored embeddings.
    Returns best matching student_id if distance < threshold.
    """
    db = SessionLocal()
    try:
        records = db.query(FaceEncoding).all()
    finally:
        db.close()

    if not records:
        return {
            "ok":       True,
            "matched":  False,
            "user_id":  None,
            "distance": float(1.0),
            "confidence": float(0.0),
            "message":  "No faces registered in system",
        }

    path = save_upload(image)
    try:
        live_emb = get_embedding(path)
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Face not detected in image: {str(e)}")
    finally:
        os.unlink(path)

    best_user_id  = None
    best_distance = float("inf")

    for record in records:
        stored_emb = json.loads(record.encoding)
        dist = cosine_distance(live_emb, stored_emb)
        if dist < best_distance:
            best_distance = dist
            best_user_id  = record.user_id

    verified   = best_distance < THRESHOLD
    confidence = round((1.0 - best_distance) * 100.0, 2)

    return {
        "ok":         True,
        "matched":    bool(verified),
        "user_id":    best_user_id if verified else None,
        "distance":   float(round(best_distance, 4)),
        "confidence": float(confidence),
        "threshold":  float(THRESHOLD),
        "message":    f"Matched {best_user_id}" if verified else "No match found",
    }
```

---

## File 2 — `FaceVerificationController.php`

Add `identify()` method to existing controller:

```php
public function identify(Request $request): JsonResponse
{
    $request->validate([
        'image' => 'required|file|mimes:jpeg,jpg,png|max:2048',
    ]);

    try {
        $response = Http::timeout(30)
            ->attach('image', file_get_contents($request->file('image')->path()), 'frame.jpg')
            ->post("{$this->mlServiceUrl}/identify/");

        if ($response->successful()) {
            $data = $response->json();
            return response()->json([
                'ok'         => true,
                'matched'    => (bool) $data['matched'],
                'user_id'    => $data['user_id'] ?? null,
                'distance'   => $data['distance'],
                'confidence' => $data['confidence'],
                'message'    => $data['message'],
            ]);
        }

        return response()->json([
            'ok'      => false,
            'matched' => false,
            'message' => 'ML service error: ' . $response->body(),
        ], 500);

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('ML identify failed: ' . $e->getMessage());
        return response()->json([
            'ok'      => false,
            'matched' => false,
            'message' => 'ML service unreachable: ' . $e->getMessage(),
        ], 503);
    }
}
```

---

## File 3 — `routes/web.php`

Add these routes:

```php
// Auto check-in after face identification (no student selection needed)
Route::post('/api/identify-face',    [\App\Http\Controllers\FaceVerificationController::class, 'identify']);
Route::post('/attendance/auto-checkin', [\App\Http\Controllers\StudentDashboardController::class, 'autoCheckIn'])
    ->name('attendance.auto-checkin')
    ->middleware('throttle:attendance');
Route::post('/attendance/auto-checkout', [\App\Http\Controllers\StudentDashboardController::class, 'autoCheckOut'])
    ->name('attendance.auto-checkout')
    ->middleware('throttle:attendance');
```

---

## File 4 — `AttendanceService.php`

Add `autoCheckIn()` and `autoCheckOut()` methods. These bypass the face verification check since identification already happened:

```php
/**
 * Auto check-in after successful face identification.
 * No face_verified param needed — identification IS the verification.
 */
public function autoCheckIn(Student $student, float $livenessScore, float $matchScore): AttendanceLog
{
    $today = Carbon::today();
    $now   = Carbon::now();

    // Check time window
    $windowStart = Carbon::parse(config('attendance.check_in_window.start'));
    $windowEnd   = Carbon::parse(config('attendance.check_in_window.end'));

    if ($now->lt($windowStart) || $now->gt($windowEnd)) {
        throw new \InvalidArgumentException(
            'Outside allowed check-in hours (' .
            config('attendance.check_in_window.start') . ' - ' .
            config('attendance.check_in_window.end') . ')'
        );
    }

    // Already checked in?
    $existing = AttendanceLog::where('student_id', $student->id)
        ->where('date', $today)
        ->where('type', 'in')
        ->first();

    if ($existing) {
        throw new DuplicateAttendanceException('Already checked in today at ' . $existing->recorded_time->format('h:i A'));
    }

    return DB::transaction(function () use ($student, $today, $now, $livenessScore, $matchScore) {
        $log = AttendanceLog::create([
            'student_id'        => $student->id,
            'date'              => $today,
            'type'              => 'in',
            'recorded_time'     => $now,
            'stated_time'       => null,
            'ip_address'        => request()->ip(),
            'is_flagged'        => false,
            'face_verified'     => true,
            'liveness_score'    => $livenessScore,
            'verification_meta' => [
                'match_score'    => $matchScore,
                'spoof_passed'   => true,
                'auto_identified'=> true,
                'verified_at'    => $now->toIso8601String(),
            ],
            'submitted_by' => $this->resolveSubmittedByUserId(),
        ]);

        $this->createAuditTrail('create', $log);
        return $log;
    });
}

/**
 * Auto check-out after successful face identification.
 */
public function autoCheckOut(Student $student, float $livenessScore, float $matchScore): AttendanceLog
{
    $today = Carbon::today();
    $now   = Carbon::now();

    $checkIn = AttendanceLog::where('student_id', $student->id)
        ->where('date', $today)
        ->where('type', 'in')
        ->first();

    if (!$checkIn) {
        throw new \InvalidArgumentException('No check-in found for today. Check in first.');
    }

    $existingOut = AttendanceLog::where('student_id', $student->id)
        ->where('date', $today)
        ->where('type', 'out')
        ->first();

    if ($existingOut) {
        throw new DuplicateAttendanceException('Already checked out today at ' . $existingOut->recorded_time->format('h:i A'));
    }

    return DB::transaction(function () use ($student, $today, $now, $livenessScore, $matchScore) {
        $log = AttendanceLog::create([
            'student_id'        => $student->id,
            'date'              => $today,
            'type'              => 'out',
            'recorded_time'     => $now,
            'stated_time'       => null,
            'ip_address'        => request()->ip(),
            'is_flagged'        => false,
            'face_verified'     => true,
            'liveness_score'    => $livenessScore,
            'verification_meta' => [
                'match_score'    => $matchScore,
                'spoof_passed'   => true,
                'auto_identified'=> true,
                'verified_at'    => $now->toIso8601String(),
            ],
            'submitted_by' => $this->resolveSubmittedByUserId(),
        ]);

        $this->createAuditTrail('create', $log);
        return $log;
    });
}
```

---

## File 5 — `StudentDashboardController.php`

Add these two methods:

```php
public function autoCheckIn(Request $request): \Illuminate\Http\JsonResponse
{
    $validated = $request->validate([
        'student_id'    => 'required|string|exists:students,student_id',
        'liveness_score'=> 'required|numeric|min:0|max:100',
        'match_score'   => 'required|numeric|min:0|max:100',
    ]);

    $student = Student::where('student_id', $validated['student_id'])->firstOrFail();

    try {
        $log = $this->attendanceService->autoCheckIn(
            $student,
            (float) $validated['liveness_score'],
            (float) $validated['match_score']
        );

        return response()->json([
            'ok'      => true,
            'type'    => 'check_in',
            'message' => 'Check-in recorded at ' . $log->recorded_time->format('h:i A'),
            'time'    => $log->recorded_time->format('h:i A'),
            'date'    => $log->date->format('d M Y'),
            'student' => $student->full_name,
        ]);
    } catch (DuplicateAttendanceException $e) {
        return response()->json([
            'ok'      => false,
            'type'    => 'already_done',
            'message' => $e->getMessage(),
        ], 409);
    } catch (\Exception $e) {
        return response()->json([
            'ok'      => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}

public function autoCheckOut(Request $request): \Illuminate\Http\JsonResponse
{
    $validated = $request->validate([
        'student_id'    => 'required|string|exists:students,student_id',
        'liveness_score'=> 'required|numeric|min:0|max:100',
        'match_score'   => 'required|numeric|min:0|max:100',
    ]);

    $student = Student::where('student_id', $validated['student_id'])->firstOrFail();

    try {
        $log = $this->attendanceService->autoCheckOut(
            $student,
            (float) $validated['liveness_score'],
            (float) $validated['match_score']
        );

        return response()->json([
            'ok'      => true,
            'type'    => 'check_out',
            'message' => 'Check-out recorded at ' . $log->recorded_time->format('h:i A'),
            'time'    => $log->recorded_time->format('h:i A'),
            'date'    => $log->date->format('d M Y'),
            'student' => $student->full_name,
        ]);
    } catch (DuplicateAttendanceException $e) {
        return response()->json([
            'ok'      => false,
            'type'    => 'already_done',
            'message' => $e->getMessage(),
        ], 409);
    } catch (\Exception $e) {
        return response()->json([
            'ok'      => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}
```

---

## File 6 — `kiosk.blade.php` (complete rewrite)

```blade
@extends('layouts.app')
@section('content')

<div class="d-flex flex-justify-between flex-items-center mb-4">
    <div>
        <h2 style="margin:0; font-size: 24px;">Internship Attendance Kiosk</h2>
        <div class="text-muted">Automatic face identification — no manual selection required</div>
    </div>
    <div class="d-flex" style="gap: 10px; align-items: stretch;">
        <a href="{{ route('student.register') }}" class="btn">New Student? Register</a>
        <div class="Box" style="padding:10px 14px;">
            <div class="text-small text-muted">Server Time</div>
            <div id="server-time" style="font-size:22px; font-weight:700;">{{ now()->format('H:i:s') }}</div>
        </div>
    </div>
</div>

<div class="Box mb-4">
    <div class="Box-header"><h2>Face Identification</h2></div>
    <div class="Box-body">
        <div class="d-flex" style="gap:24px; align-items:flex-start; flex-wrap:wrap;">

            {{-- Camera --}}
            <div id="cam-wrapper" style="position:relative; width:360px; display:inline-block;">
                <video id="cam" autoplay playsinline
                    style="width:360px; display:block; border:1px solid var(--color-border-default); border-radius:8px;">
                </video>
                <div id="cam-overlay" style="
                    display:none;
                    position:absolute; top:0; left:0;
                    width:100%; height:100%;
                    background:rgba(0,0,0,0.6);
                    border-radius:8px;
                    color:white;
                    font-weight:700;
                    text-align:center;
                    justify-content:center;
                    align-items:center;
                    flex-direction:column;
                    gap:12px;
                    z-index:20;
                "></div>
            </div>

            {{-- Status Panel --}}
            <div style="min-width:300px; flex:1;">

                <div class="Box mb-3" style="padding:20px;">
                    <div style="font-size:12px; color:#656d76; margin-bottom:6px;">Status</div>
                    <div id="verify-status"
                        style="font-weight:700; font-size:18px; color:#cf222e; line-height:1.3;">
                        Press Start Camera to begin
                    </div>
                </div>

                <div class="Box mb-3" style="padding:16px;">
                    <div style="font-size:12px; color:#656d76; margin-bottom:6px;">Challenge Progress</div>
                    <div id="verify-scores"
                        style="font-weight:600; font-size:14px; color:#1f2328;">
                        Task 1: waiting | Task 2: waiting
                    </div>
                </div>

                <div class="mb-3">
                    <button type="button" id="start-camera"
                        class="btn btn-primary"
                        style="padding:10px 24px; font-size:16px; width:100%;">
                        Start Camera
                    </button>
                </div>

                <div id="result-box" style="display:none;" class="Box">
                    <div class="Box-body" id="result-content"></div>
                </div>

            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/drawing_utils/drawing_utils.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js"></script>
<script>
(() => {
    const csrf    = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const camEl   = document.getElementById('cam');
    const statusEl  = document.getElementById('verify-status');
    const scoresEl  = document.getElementById('verify-scores');
    const resultBox = document.getElementById('result-box');
    const resultContent = document.getElementById('result-content');
    const overlayEl = document.getElementById('cam-overlay');

    // ─── State ───────────────────────────────────────────────────────────────
    const state = {
        running:            false,
        liveFrames:         0,
        mlVerifying:        false,
        verified:           false,
        livenessScore:      0,
        challenges:         [],
        currentChallengeIdx:0,
        challengeCompleted: [false, false],
        challengeHoldFrames:0,
        challengeBlinkLow:  false,
        pitchBaseline:      null,
        browBaseline:       null,
        cooldown:           false,   // prevents rapid re-triggering
    };

    // ─── Challenge Definitions ────────────────────────────────────────────────
    const CHALLENGES = [
        {
            id: 'blink',
            instruction: 'Blink your eyes',
            check: (lm, s) => {
                const ear = (eyeAspectRatio(lm,159,145,33,133) + eyeAspectRatio(lm,386,374,362,263)) / 2;
                if (ear < 0.18 && !s.challengeBlinkLow) s.challengeBlinkLow = true;
                if (ear >= 0.21 && s.challengeBlinkLow) { s.challengeBlinkLow = false; return true; }
                return false;
            }
        },
        {
            id: 'turn_left',
            instruction: 'Turn your head LEFT',
            check: (lm) => {
                const mid = (lm[33].x + lm[263].x) / 2;
                return (lm[1].x - mid) < -0.04;
            }
        },
        {
            id: 'turn_right',
            instruction: 'Turn your head RIGHT',
            check: (lm) => {
                const mid = (lm[33].x + lm[263].x) / 2;
                return (lm[1].x - mid) > 0.04;
            }
        },
        {
            id: 'open_mouth',
            instruction: 'Open your mouth wide',
            check: (lm) => Math.abs(lm[13].y - lm[14].y) > 0.04
        },
        {
            id: 'nod',
            instruction: 'Nod your head DOWN',
            check: (lm, s) => {
                const pitch = lm[1].y - lm[10].y;
                if (!s.pitchBaseline) { s.pitchBaseline = pitch; return false; }
                return (pitch - s.pitchBaseline) > 0.03;
            }
        },
        {
            id: 'raise_eyebrows',
            instruction: 'Raise your eyebrows UP',
            check: (lm, s) => {
                const dist = lm[159].y - lm[70].y;
                if (!s.browBaseline) { s.browBaseline = dist; return false; }
                return (dist - s.browBaseline) > 0.025;
            }
        },
    ];

    function pickChallenges() {
        return [...CHALLENGES].sort(() => Math.random() - 0.5).slice(0, 2);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────
    function dist(a, b) {
        return Math.sqrt((a.x-b.x)**2 + (a.y-b.y)**2);
    }

    function eyeAspectRatio(lm, top, bot, left, right) {
        const v = dist(lm[top], lm[bot]);
        const h = dist(lm[left], lm[right]);
        return h ? v/h : 0;
    }

    function setStatus(msg, color = '#cf222e') {
        statusEl.textContent = msg;
        statusEl.style.color = color;
    }

    function showResult(html) {
        resultBox.style.display = 'block';
        resultContent.innerHTML = html;
    }

    function hideResult() {
        resultBox.style.display = 'none';
        resultContent.innerHTML = '';
    }

    function resetChallengeState() {
        state.liveFrames          = 0;
        state.mlVerifying         = false;
        state.verified            = false;
        state.livenessScore       = 0;
        state.challenges          = [];
        state.currentChallengeIdx = 0;
        state.challengeCompleted  = [false, false];
        state.challengeHoldFrames = 0;
        state.challengeBlinkLow   = false;
        state.pitchBaseline       = null;
        state.browBaseline        = null;
        state.cooldown            = false;
        document.querySelectorAll('#cam-wrapper canvas').forEach(c => c.remove());
        overlayEl.style.display = 'none';
        overlayEl.innerHTML     = '';
    }

    // ─── MediaPipe ────────────────────────────────────────────────────────────
    const faceMesh = new FaceMesh({
        locateFile: f => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${f}`
    });
    faceMesh.setOptions({
        maxNumFaces:          1,
        refineLandmarks:      true,
        minDetectionConfidence: 0.6,
        minTrackingConfidence:  0.6,
    });

    faceMesh.onResults(results => {
        if (!results.multiFaceLandmarks?.length) {
            if (!state.mlVerifying && !state.cooldown) {
                setStatus('No face detected. Position yourself in frame.');
                scoresEl.textContent = 'Task 1: waiting | Task 2: waiting';
            }
            return;
        }

        const lm = results.multiFaceLandmarks[0];
        state.liveFrames++;

        // Pick challenges on first good frame
        if (state.liveFrames === 1 && state.challenges.length === 0) {
            state.challenges = pickChallenges();
            state.challengeCompleted = [false, false];
        }

        if (state.mlVerifying || state.verified || state.cooldown) return;

        const liveSignalOk = state.liveFrames >= 15;

        // Process current challenge
        const cur = state.challenges[state.currentChallengeIdx];
        if (cur && !state.challengeCompleted[state.currentChallengeIdx]) {
            const done = cur.check(lm, state);
            if (done) {
                state.challengeHoldFrames++;
                if (state.challengeHoldFrames >= 5) {
                    state.challengeCompleted[state.currentChallengeIdx] = true;
                    state.challengeHoldFrames = 0;
                    if (state.currentChallengeIdx < 1) {
                        state.currentChallengeIdx++;
                        state.pitchBaseline  = null;
                        state.browBaseline   = null;
                        state.challengeBlinkLow = false;
                    }
                }
            } else {
                state.challengeHoldFrames = 0;
            }
        }

        const c1 = state.challengeCompleted[0];
        const c2 = state.challengeCompleted[1];
        const allDone = c1 && c2 && liveSignalOk;

        state.livenessScore = (c1 ? 50 : 0) + (c2 ? 50 : 0);

        // Update scores display
        const ch1 = state.challenges[0]?.instruction || '';
        const ch2 = state.challenges[1]?.instruction || '';
        scoresEl.textContent = `Task 1: ${c1 ? 'Done' : 'Pending'} | Task 2: ${c2 ? 'Done' : 'Pending'}`;

        // Update status
        if (!liveSignalOk) {
            setStatus('Hold still — detecting face...');
        } else if (!c1) {
            setStatus(ch1);
        } else if (!c2) {
            setStatus(ch2);
        }

        // All done — trigger identification
        if (allDone) {
            triggerIdentification();
        }
    });

    // ─── Identification Flow ──────────────────────────────────────────────────
    function triggerIdentification() {
        if (state.mlVerifying || state.cooldown) return;
        state.mlVerifying = true;
        state.cooldown    = true;

        // Show countdown overlay
        overlayEl.style.display = 'flex';
        overlayEl.innerHTML = `
            <div style="font-size:15px; padding:0 16px; text-align:center;">
                Liveness confirmed<br>
                <span style="font-size:13px; font-weight:400;">Look straight at camera and stay still</span>
            </div>
            <div id="snap-countdown" style="font-size:72px; font-weight:900; line-height:1;">5</div>
            <div style="font-size:12px; font-weight:400;">Capturing in...</div>
        `;
        setStatus('Liveness confirmed — look straight, stay still.', '#1a7f37');

        let countdown = 5;
        const countdownInterval = setInterval(() => {
            countdown--;
            const el = document.getElementById('snap-countdown');
            if (el) el.textContent = countdown;

            if (countdown <= 0) {
                clearInterval(countdownInterval);
                captureAndIdentify();
            }
        }, 1000);
    }

    async function captureAndIdentify() {
        // Show capturing state
        overlayEl.innerHTML = `<div style="font-size:18px;">Capturing face...</div>`;

        // Capture frame
        const canvas  = document.createElement('canvas');
        canvas.width  = camEl.videoWidth  || 360;
        canvas.height = camEl.videoHeight || 270;
        canvas.getContext('2d').drawImage(camEl, 0, 0);

        // Freeze effect — green border
        canvas.style.cssText = `
            position:absolute; top:0; left:0;
            width:360px; height:100%;
            border:4px solid #1a7f37;
            border-radius:8px;
            box-shadow:0 0 24px rgba(26,127,55,0.7);
            z-index:10;
        `;
        document.getElementById('cam-wrapper').appendChild(canvas);
        overlayEl.style.display = 'none';
        setStatus('Identifying person...', '#656d76');

        canvas.toBlob(async blob => {
            const formData = new FormData();
            formData.append('image', blob, 'frame.jpg');

            try {
                const resp = await fetch('/api/identify-face', {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': csrf },
                    body:    formData,
                });

                const identification = await resp.json();
                canvas.remove();

                if (!identification.ok || !identification.matched) {
                    handleNotMatched(identification.message || 'No match found.');
                    return;
                }

                // Matched — now auto check-in or check-out
                await handleMatched(identification);

            } catch (e) {
                canvas.remove();
                handleError('Network error. Check ML service is running.');
            }
        }, 'image/jpeg', 0.92);
    }

    async function handleMatched(identification) {
        const studentId  = identification.user_id;
        const confidence = identification.confidence;

        setStatus(`Identified: ${studentId} (${confidence.toFixed(1)}% confidence)`, '#1a7f37');

        // First try check-in, then check-out
        const checkInResp = await fetch('/attendance/auto-checkin', {
            method:  'POST',
            headers: {
                'X-CSRF-TOKEN':  csrf,
                'Content-Type':  'application/json',
                'Accept':        'application/json',
            },
            body: JSON.stringify({
                student_id:    studentId,
                liveness_score: state.livenessScore,
                match_score:   confidence,
            }),
        });

        const checkInResult = await checkInResp.json();

        if (checkInResult.ok) {
            // Check-in succeeded
            showSuccessResult(checkInResult, 'Check-In');
            setStatus(`Welcome, ${checkInResult.student}`, '#1a7f37');
            scheduleReset(8000);
            return;
        }

        if (checkInResult.type === 'already_done') {
            // Already checked in — try check-out
            const checkOutResp = await fetch('/attendance/auto-checkout', {
                method:  'POST',
                headers: {
                    'X-CSRF-TOKEN':  csrf,
                    'Content-Type':  'application/json',
                    'Accept':        'application/json',
                },
                body: JSON.stringify({
                    student_id:    studentId,
                    liveness_score: state.livenessScore,
                    match_score:   confidence,
                }),
            });

            const checkOutResult = await checkOutResp.json();

            if (checkOutResult.ok) {
                showSuccessResult(checkOutResult, 'Check-Out');
                setStatus(`Goodbye, ${checkOutResult.student}`, '#1a7f37');
                scheduleReset(8000);
                return;
            }

            if (checkOutResult.type === 'already_done') {
                showAlreadyDone(checkOutResult.message, studentId);
                scheduleReset(6000);
                return;
            }

            showError(checkOutResult.message || 'Check-out failed.');
            scheduleReset(5000);
            return;
        }

        showError(checkInResult.message || 'Check-in failed.');
        scheduleReset(5000);
    }

    function handleNotMatched(message) {
        setStatus('Face not recognized. Please re-register or try again.', '#cf222e');
        showResult(`
            <div style="text-align:center; padding:12px;">
                <div style="font-size:16px; font-weight:700; color:#cf222e; margin-bottom:8px;">
                    Not Recognized
                </div>
                <div style="font-size:13px; color:#656d76;">${message}</div>
                <div style="font-size:12px; color:#656d76; margin-top:8px;">
                    Retrying in 5 seconds...
                </div>
            </div>
        `);
        scheduleReset(5000);
    }

    function handleError(message) {
        setStatus('Error: ' + message, '#cf222e');
        showResult(`
            <div style="text-align:center; padding:12px;">
                <div style="font-size:14px; font-weight:700; color:#cf222e;">${message}</div>
                <div style="font-size:12px; color:#656d76; margin-top:8px;">Retrying in 5 seconds...</div>
            </div>
        `);
        scheduleReset(5000);
    }

    function showSuccessResult(result, type) {
        const color = type === 'Check-In' ? '#1a7f37' : '#0969da';
        showResult(`
            <div style="text-align:center; padding:16px;">
                <div style="font-size:20px; font-weight:900; color:${color}; margin-bottom:8px;">
                    ${type} Successful
                </div>
                <div style="font-size:16px; font-weight:700; margin-bottom:4px;">${result.student}</div>
                <div style="font-size:14px; color:#656d76;">${result.date}</div>
                <div style="font-size:28px; font-weight:900; color:${color}; margin-top:8px;">
                    ${result.time}
                </div>
                <div style="font-size:12px; color:#656d76; margin-top:12px;">
                    Resetting in 8 seconds...
                </div>
            </div>
        `);
    }

    function showAlreadyDone(message, studentId) {
        showResult(`
            <div style="text-align:center; padding:12px;">
                <div style="font-size:16px; font-weight:700; color:#9a6700; margin-bottom:8px;">
                    Attendance Already Recorded
                </div>
                <div style="font-size:13px; color:#656d76;">${message}</div>
                <div style="font-size:12px; color:#656d76; margin-top:8px;">Resetting in 6 seconds...</div>
            </div>
        `);
    }

    function showError(message) {
        showResult(`
            <div style="text-align:center; padding:12px;">
                <div style="font-size:14px; font-weight:700; color:#cf222e;">${message}</div>
                <div style="font-size:12px; color:#656d76; margin-top:8px;">Resetting in 5 seconds...</div>
            </div>
        `);
    }

    function scheduleReset(ms) {
        setTimeout(() => {
            hideResult();
            resetChallengeState();
            setStatus('Ready — complete the challenges to record attendance.');
            scoresEl.textContent = 'Task 1: waiting | Task 2: waiting';
        }, ms);
    }

    // ─── Camera Start ─────────────────────────────────────────────────────────
    let camera = null;
    let nativeStream = null;
    let frameLoopHandle = null;

    async function startCamera() {
        if (state.running) return;
        document.getElementById('start-camera').disabled = true;
        document.getElementById('start-camera').textContent = 'Starting...';

        const runFrameLoop = async () => {
            if (!state.running) return;
            try { await faceMesh.send({ image: camEl }); } catch (_) {}
            frameLoopHandle = requestAnimationFrame(runFrameLoop);
        };

        try {
            camera = new Camera(camEl, {
                onFrame: async () => { await faceMesh.send({ image: camEl }); },
                width: 360, height: 270,
            });
            await camera.start();
            state.running = true;
            resetChallengeState();
            setStatus('Look at the camera and follow the instructions.');
            document.getElementById('start-camera').style.display = 'none';
            return;
        } catch (_) {
            camera = null;
        }

        // Fallback
        try {
            nativeStream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 360 }, height: { ideal: 270 }, facingMode: 'user' },
                audio: false,
            });
            camEl.srcObject = nativeStream;
            await camEl.play();
            state.running = true;
            resetChallengeState();
            frameLoopHandle = requestAnimationFrame(runFrameLoop);
            setStatus('Look at the camera and follow the instructions.');
            document.getElementById('start-camera').style.display = 'none';
        } catch (err) {
            state.running = false;
            setStatus('Camera failed. Allow camera permission and try again.', '#cf222e');
            document.getElementById('start-camera').disabled = false;
            document.getElementById('start-camera').textContent = 'Start Camera';
        }
    }

    document.getElementById('start-camera').addEventListener('click', startCamera);

    // Server time
    setInterval(() => {
        fetch("{{ route('api.server-time') }}")
            .then(r => r.json())
            .then(d => { document.getElementById('server-time').textContent = d.time; })
            .catch(() => {});
    }, 30000);

})();
</script>
@endpush
```

---

## File 7 — `register-student.blade.php`

Replace photo upload section with webcam capture. Find the photo form-group and replace:

```blade
{{-- REMOVE the entire photo form-group div --}}
{{-- REPLACE WITH: --}}

<div class="form-group">
    <label class="form-label">Face Photo (required)</label>

    {{-- Hidden inputs --}}
    <input type="hidden" id="face-photo-data" name="face_photo_data" value="">
    <input type="hidden" id="face-signature" name="face_signature" value="">

    {{-- Camera wrapper --}}
    <div id="reg-cam-wrapper" style="position:relative; width:320px; display:inline-block; margin-bottom:12px;">
        <video id="reg-cam" autoplay playsinline
            style="width:320px; display:none; border:1px solid var(--color-border-default); border-radius:6px;">
        </video>
        <canvas id="reg-capture-preview" width="320" height="240"
            style="display:none; border:2px solid #1a7f37; border-radius:6px;">
        </canvas>
        <div id="reg-overlay" style="
            display:none; position:absolute; top:0; left:0;
            width:100%; height:100%;
            background:rgba(0,0,0,0.55);
            border-radius:6px; color:white;
            font-weight:700; text-align:center;
            justify-content:center; align-items:center;
            flex-direction:column; gap:8px; z-index:10;
        "></div>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
        <button type="button" id="reg-open-camera" class="btn">Open Camera</button>
        <button type="button" id="reg-capture-btn" class="btn btn-success" style="display:none;">
            Capture Photo
        </button>
        <button type="button" id="reg-retake-btn" class="btn" style="display:none;">
            Retake
        </button>
    </div>

    <div id="reg-status" class="text-small text-muted">
        Open camera to take your photo for face registration.
    </div>

    @error('face_photo_data')
        <div class="text-small" style="color:var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>
    @enderror
    @error('face_signature')
        <div class="text-small" style="color:var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>
    @enderror
</div>
```

Now add the JS for registration camera at the bottom of the `@push('scripts')` section, after the existing MediaPipe script:

```javascript
// ─── Registration Camera ──────────────────────────────────────────────────
(() => {
    const regCam          = document.getElementById('reg-cam');
    const regPreview      = document.getElementById('reg-capture-preview');
    const regOverlay      = document.getElementById('reg-overlay');
    const regStatus       = document.getElementById('reg-status');
    const regOpenBtn      = document.getElementById('reg-open-camera');
    const regCaptureBtn   = document.getElementById('reg-capture-btn');
    const regRetakeBtn    = document.getElementById('reg-retake-btn');
    const photoDataInput  = document.getElementById('face-photo-data');
    const signatureInput  = document.getElementById('face-signature');
    const submitBtn       = document.getElementById('register-submit');

    let regStream    = null;
    let regFaceMesh  = null;
    let regFrameLoop = null;
    let captureReady = false;

    function setRegStatus(msg, ok = false) {
        regStatus.textContent = msg;
        regStatus.style.color = ok ? '#1a7f37' : '#656d76';
    }

    function toggleSubmit(enabled) {
        submitBtn.disabled = !enabled;
        submitBtn.style.opacity  = enabled ? '1' : '0.55';
        submitBtn.style.cursor   = enabled ? 'pointer' : 'not-allowed';
    }

    toggleSubmit(false);

    // Distance helper (same as kiosk)
    function dist(a, b) {
        return Math.sqrt((a.x-b.x)**2 + (a.y-b.y)**2);
    }

    // Extract face signature from landmarks
    function extractSignature(landmarks) {
        const idx = [
            1,33,263,61,291,199,152,10,234,454,
            70,63,105,66,300,293,334,296,
            159,145,386,374,157,144,160,153,
            384,381,387,380,6,197,195,5,4,98,327,94,
            13,14,78,308,82,312,87,317,
            172,397,136,365,150,379,
            162,389,103,332,116,345,123,352,
            54,284,21,251,175,171,396,176,
            48,278,115,344,164,393,167,394,
        ];
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

    // Open camera
    regOpenBtn.addEventListener('click', async () => {
        try {
            regStream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 320 }, height: { ideal: 240 }, facingMode: 'user' },
                audio: false,
            });
            regCam.srcObject = regStream;
            await regCam.play();
            regCam.style.display = 'block';
            regOpenBtn.style.display   = 'none';
            regCaptureBtn.style.display = 'inline-block';
            setRegStatus('Camera ready. Position your face clearly, then capture.');
            captureReady = true;
        } catch (e) {
            setRegStatus('Camera failed: ' + (e.message || 'Permission denied'));
        }
    });

    // Capture button
    regCaptureBtn.addEventListener('click', async () => {
        if (!captureReady) return;

        regCaptureBtn.disabled = true;
        setRegStatus('Capturing and processing face...');

        // Draw frame to canvas
        const ctx = regPreview.getContext('2d');
        regPreview.width  = regCam.videoWidth  || 320;
        regPreview.height = regCam.videoHeight || 240;
        ctx.drawImage(regCam, 0, 0);

        // Show preview, hide live camera
        regCam.style.display     = 'none';
        regPreview.style.display = 'block';

        // Stop camera stream
        if (regStream) regStream.getTracks().forEach(t => t.stop());

        regCaptureBtn.style.display = 'none';
        regRetakeBtn.style.display  = 'inline-block';

        // Get base64 photo data
        const photoDataUrl = regPreview.toDataURL('image/jpeg', 0.92);
        photoDataInput.value = photoDataUrl;

        // Extract face signature using MediaPipe
        setRegStatus('Extracting face signature...');

        try {
            if (!regFaceMesh) {
                regFaceMesh = new FaceMesh({
                    locateFile: f => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${f}`
                });
                regFaceMesh.setOptions({
                    maxNumFaces: 1,
                    refineLandmarks: true,
                    minDetectionConfidence: 0.5,
                    minTrackingConfidence: 0.5,
                });
            }

            const signature = await new Promise((resolve, reject) => {
                let settled = false;
                const timeout = setTimeout(() => {
                    if (!settled) { settled = true; reject(new Error('Face detection timed out. Please retake.')); }
                }, 12000);

                regFaceMesh.onResults(results => {
                    if (settled) return;
                    clearTimeout(timeout);
                    settled = true;

                    if (!results.multiFaceLandmarks?.length) {
                        reject(new Error('No face detected in photo. Please retake.'));
                        return;
                    }
                    const sig = extractSignature(results.multiFaceLandmarks[0]);
                    if (sig.length < 140) {
                        reject(new Error('Could not extract face data. Please retake.'));
                        return;
                    }
                    resolve(sig);
                });

                // Send captured frame to MediaPipe
                const img = new Image();
                img.onload = async () => {
                    try { await regFaceMesh.send({ image: img }); }
                    catch (e) { if (!settled) { settled = true; clearTimeout(timeout); reject(e); } }
                };
                img.src = photoDataUrl;
            });

            signatureInput.value = JSON.stringify(signature);
            setRegStatus('Face captured successfully. Fill in your details and submit.', true);
            toggleSubmit(true);

        } catch (e) {
            setRegStatus(e.message || 'Face processing failed. Please retake.');
            photoDataInput.value  = '';
            signatureInput.value  = '';
            toggleSubmit(false);
        }

        regCaptureBtn.disabled = false;
    });

    // Retake
    regRetakeBtn.addEventListener('click', async () => {
        photoDataInput.value  = '';
        signatureInput.value  = '';
        toggleSubmit(false);
        regPreview.style.display = 'none';
        regRetakeBtn.style.display  = 'none';
        regCaptureBtn.style.display = 'none';
        regOpenBtn.style.display    = 'inline-block';
        setRegStatus('Open camera to take your photo again.');
    });

})();
```

---

## File 8 — `StudentSelfRegistrationController.php`

Replace the `store()` method to handle base64 photo data instead of file upload:

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'first_name'     => 'required|string|max:255',
        'last_name'      => 'required|string|max:255',
        'parent_name'    => 'nullable|string|max:255',
        'father_name'    => 'required|string|max:255',
        'mother_name'    => 'required|string|max:255',
        'address'        => 'required|string|max:1000',
        'email'          => 'required|email|unique:students,email',
        'phone'          => 'nullable|string|max:20',
        'department'     => 'nullable|string|max:255',
        'face_photo_data'=> 'required|string',   // base64 JPEG from webcam
        'face_signature' => 'required|string',
    ]);

    if ($this->fullNameExists($validated['first_name'], $validated['last_name'])) {
        return back()->withInput()->withErrors([
            'first_name' => 'A student with the same first and last name is already registered.',
        ]);
    }

    // Decode base64 photo and save it
    $photoData = $validated['face_photo_data'];
    // Strip data:image/jpeg;base64, prefix if present
    if (str_contains($photoData, ',')) {
        $photoData = explode(',', $photoData)[1];
    }
    $photoBytes = base64_decode($photoData);
    if (!$photoBytes) {
        return back()->withInput()->withErrors([
            'face_photo_data' => 'Invalid photo data. Please retake your photo.',
        ]);
    }

    $studentId = $this->generateStudentId();
    $filename  = 'students/' . $studentId . '.jpg';
    \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $photoBytes);
    $photoPath = $filename;

    // Parse face signature
    $faceSignature = $this->parseFaceSignature($validated['face_signature']);
    if (empty($faceSignature)) {
        Storage::disk('public')->delete($photoPath);
        return back()->withInput()->withErrors([
            'face_photo_data' => 'Could not process face signature. Please retake your photo.',
        ]);
    }

    $student = Student::create([
        'student_id'       => $studentId,
        'first_name'       => $validated['first_name'],
        'last_name'        => $validated['last_name'],
        'parent_name'      => $validated['parent_name'] ?? null,
        'father_name'      => $validated['father_name'],
        'mother_name'      => $validated['mother_name'],
        'address'          => $validated['address'],
        'email'            => $validated['email'],
        'phone'            => $validated['phone'] ?? null,
        'department'       => $validated['department'] ?? null,
        'photo_path'       => $photoPath,
        'is_active'        => true,
        'face_signature'   => $faceSignature,
        'face_registered_at' => Carbon::now(),
    ]);

    // Register face in FastAPI ML service
    try {
        $photoFullPath = storage_path('app/public/' . $photoPath);
        \Illuminate\Support\Facades\Http::timeout(30)
            ->attach('image1', file_get_contents($photoFullPath), 'photo.jpg')
            ->attach('image2', file_get_contents($photoFullPath), 'photo.jpg')
            ->post('http://127.0.0.1:8001/register/', [
                'user_id' => $studentId,
            ]);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning('ML face registration failed for ' . $studentId . ': ' . $e->getMessage());
    }

    return redirect()->route('attendance.kiosk')
        ->with('success', 'Registration complete. Your student ID is ' . $studentId . '. You can now use the attendance kiosk.');
}
```

---

## Summary of All Changes

```
face_rec.py
  + /identify/ endpoint (1:N matching against all stored embeddings)

FaceVerificationController.php
  + identify() method → proxies to /identify/

routes/web.php
  + POST /api/identify-face
  + POST /attendance/auto-checkin
  + POST /attendance/auto-checkout

AttendanceService.php
  + autoCheckIn()  — no face verification params, identification already done
  + autoCheckOut() — same

StudentDashboardController.php
  + autoCheckIn()  — returns JSON, no redirect
  + autoCheckOut() — returns JSON, no redirect

kiosk.blade.php
  REMOVED: student dropdown, stop camera, verify button, register face button
  REMOVED: check-in form, check-out form, manual time entry
  ADDED:   single Start Camera button
  ADDED:   auto-identify flow → auto check-in/check-out → result display → auto reset

register-student.blade.php
  REMOVED: file upload input
  ADDED:   Open Camera button, Capture button, Retake button
  ADDED:   webcam preview, capture preview canvas
  ADDED:   base64 photo data hidden input
  ADDED:   registration camera JS

StudentSelfRegistrationController.php
  CHANGED: handles face_photo_data (base64) instead of photo file upload
  CHANGED: saves decoded bytes to storage instead of moving uploaded file
```

After all changes:
```bash
php artisan config:clear
php artisan view:clear
php artisan route:clear
```
