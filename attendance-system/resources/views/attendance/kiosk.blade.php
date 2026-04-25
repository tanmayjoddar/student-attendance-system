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
