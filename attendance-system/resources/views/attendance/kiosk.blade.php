@extends('layouts.app')

@section('content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <div>
        <h2 style="margin:0; font-size: 24px;">Internship Attendance Kiosk</h2>
        <div class="text-muted">Walk-in attendance with MediaPipe face + liveness verification</div>
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
    <div class="Box-header"><h2>Select Student</h2></div>
    <div class="Box-body">
        <div class="form-group" style="max-width: 520px;">
            <label class="form-label" for="student-id-select">Internship Student</label>
            <select id="student-id-select" class="form-control">
                <option value="">Choose student...</option>
                @foreach($students as $student)
                    <option value="{{ $student->student_id }}"
                            data-face="{{ $student->face_registered_at ? '1' : '0' }}"
                            data-signature='@json($student->face_signature ?? [])'>
                        {{ $student->student_id }} - {{ $student->full_name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div id="student-face-status" class="text-small text-muted">Select a student to continue.</div>
    </div>
</div>

<div class="Box mb-4">
    <div class="Box-header"><h2>Face Verification (MediaPipe)</h2></div>
    <div class="Box-body">
        <div class="d-flex" style="gap:16px; align-items:flex-start; flex-wrap: wrap;">
            <video id="cam" autoplay playsinline style="width:320px; border:1px solid var(--color-border-default); border-radius:6px;"></video>
            <canvas id="overlay" width="320" height="240" style="display:none;"></canvas>
            <div style="min-width: 280px;">
                <div class="mb-4">
                    <div style="font-size: 12px; color: #656d76; margin-bottom: 4px;">Liveness Status</div>
                    <div id="verify-status" style="font-weight: 600; font-size: 14px; color: #cf222e;">Not verified</div>
                    <div style="font-size: 12px; color: #656d76; margin-top: 4px;">Blink once and turn your head left/right</div>
                </div>
                <div class="mb-4">
                    <div style="font-size: 12px; color: #656d76; margin-bottom: 4px;">Liveness Score</div>
                    <div id="verify-scores" style="font-weight: 600; font-size: 14px; color: #1f2328;">0%</div>
                </div>
                <div class="d-flex" style="gap:8px; flex-wrap:wrap;">
                    <button type="button" id="start-camera" class="btn">Start Camera</button>
                    <button type="button" id="stop-camera" class="btn btn-danger">Stop Camera</button>
                    <button type="button" id="register-face" class="btn btn-primary">Register Face</button>
                    <button type="button" id="run-verify" class="btn btn-success">Verify</button>
                </div>
                <div id="verify-message" class="mt-4 text-small text-muted"></div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex" style="gap:16px; flex-wrap: wrap;">
    <div class="Box" style="flex:1; min-width: 300px;">
        <div class="Box-header"><h2>Check In</h2></div>
        <div class="Box-body">
            <form method="POST" action="{{ route('attendance.check-in') }}" id="checkin-form">
                @csrf
                <input type="hidden" name="student_id" value="">
                <div class="form-group">
                    <label class="form-label">Stated Time (optional)</label>
                    <input type="datetime-local" name="stated_time" class="form-control" max="{{ now()->format('Y-m-d\\TH:i') }}">
                </div>
                <input type="hidden" name="face_verified" value="0">
                <input type="hidden" name="liveness_score" value="0">
                <input type="hidden" name="match_score" value="0">
                <input type="hidden" name="blink_count" value="0">
                <input type="hidden" name="yaw_variance" value="0">
                <button type="submit" class="btn btn-success">Submit Check In</button>
            </form>
        </div>
    </div>

    <div class="Box" style="flex:1; min-width: 300px;">
        <div class="Box-header"><h2>Check Out</h2></div>
        <div class="Box-body">
            <form method="POST" action="{{ route('attendance.check-out') }}" id="checkout-form">
                @csrf
                <input type="hidden" name="student_id" value="">
                <input type="hidden" name="face_verified" value="0">
                <input type="hidden" name="liveness_score" value="0">
                <input type="hidden" name="match_score" value="0">
                <input type="hidden" name="blink_count" value="0">
                <input type="hidden" name="yaw_variance" value="0">
                <button type="submit" class="btn btn-primary">Submit Check Out</button>
            </form>
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
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const select = document.getElementById('student-id-select');
    const faceStatus = document.getElementById('student-face-status');
    const statusEl = document.getElementById('verify-status');
    const scoresEl = document.getElementById('verify-scores');
    const msgEl = document.getElementById('verify-message');
    const camEl = document.getElementById('cam');

    const forms = [document.getElementById('checkin-form'), document.getElementById('checkout-form')].filter(Boolean);

    const state = {
        running: false,
        landmarks: null,
        signature: null,
        blinkCount: 0,
        wasBlinkLow: false,
        yawMin: null,
        yawMax: null,
        livenessScore: 0,
        matchScore: 0,
        verified: false,
        liveFrames: 0,
        lastDetectionAt: 0,
        verificationAt: 0,
    };

    function distance(a, b) {
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        return Math.sqrt(dx * dx + dy * dy);
    }

    function eyeAspectRatio(landmarks, topIdx, bottomIdx, leftIdx, rightIdx) {
        const vertical = distance(landmarks[topIdx], landmarks[bottomIdx]);
        const horizontal = distance(landmarks[leftIdx], landmarks[rightIdx]);
        return horizontal ? vertical / horizontal : 0;
    }

    function extractSignature(landmarks) {
        const idx = [1, 33, 263, 61, 291, 199, 152, 10, 234, 454];
        const leftEye = landmarks[33];
        const rightEye = landmarks[263];
        const eyeDist = distance(leftEye, rightEye) || 1;
        const centerX = (leftEye.x + rightEye.x) / 2;
        const centerY = (leftEye.y + rightEye.y) / 2;

        const vector = [];
        idx.forEach(i => {
            vector.push((landmarks[i].x - centerX) / eyeDist);
            vector.push((landmarks[i].y - centerY) / eyeDist);
        });

        return vector;
    }

    function similarityScore(a, b) {
        if (!Array.isArray(a) || !Array.isArray(b) || !a.length || a.length !== b.length) {
            return 0;
        }

        let dot = 0;
        let magA = 0;
        let magB = 0;

        for (let i = 0; i < a.length; i++) {
            dot += a[i] * b[i];
            magA += a[i] * a[i];
            magB += b[i] * b[i];
        }

        const denom = Math.sqrt(magA) * Math.sqrt(magB) || 1;
        return Math.max(0, Math.min(100, ((dot / denom) + 1) * 50));
    }

    function syncForms() {
        forms.forEach(form => {
            form.querySelector('[name="student_id"]').value = select.value || '';
            form.querySelector('[name="face_verified"]').value = state.verified ? '1' : '0';
            form.querySelector('[name="liveness_score"]').value = state.livenessScore.toFixed(2);
            form.querySelector('[name="match_score"]').value = state.matchScore.toFixed(2);
            form.querySelector('[name="blink_count"]').value = String(state.blinkCount);
            const yawVariance = (state.yawMax !== null && state.yawMin !== null) ? (state.yawMax - state.yawMin) : 0;
            form.querySelector('[name="yaw_variance"]').value = yawVariance.toFixed(4);
        });

        updateSubmitButtons();
    }

    function updateSubmitButtons() {
        const liveSignalOk = state.running && (Date.now() - state.lastDetectionAt) <= 1500 && state.liveFrames >= 15;
        const verifyFresh = (Date.now() - state.verificationAt) <= 60000;
        const allowSubmit = state.verified && liveSignalOk && verifyFresh;

        forms.forEach(form => {
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = !allowSubmit;
                submitButton.style.opacity = allowSubmit ? '1' : '0.55';
                submitButton.style.cursor = allowSubmit ? 'pointer' : 'not-allowed';
            }
        });
    }

    function setStatus(message, ok = false) {
        statusEl.textContent = message;
        statusEl.style.color = ok ? '#1a7f37' : '#cf222e';
        statusEl.style.fontSize = '14px';
        statusEl.style.fontWeight = '600';
    }

    async function fetchSignature(studentId) {
        const option = select.options[select.selectedIndex];
        const hasFace = option && option.dataset.face === '1';
        if (!studentId) {
            state.signature = null;
            faceStatus.textContent = 'Select a student to continue.';
            return;
        }

        if (!hasFace) {
            state.signature = null;
            faceStatus.textContent = 'No face profile registered. Use Register Face first.';
            return;
        }

        const signaturePayload = option.dataset.signature || '[]';
        try {
            const parsed = JSON.parse(signaturePayload);
            state.signature = Array.isArray(parsed) ? parsed : null;
        } catch (_) {
            state.signature = null;
        }

        if (!state.signature || !state.signature.length) {
            faceStatus.textContent = 'Face profile is marked but signature is missing. Re-register face.';
            return;
        }

        faceStatus.textContent = 'Face profile is available for this student.';
    }

    let camera;
    const faceMesh = new FaceMesh({ locateFile: (file) => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}` });
    faceMesh.setOptions({
        maxNumFaces: 1,
        refineLandmarks: true,
        minDetectionConfidence: 0.6,
        minTrackingConfidence: 0.6,
    });

    faceMesh.onResults((results) => {
        if (!results.multiFaceLandmarks || !results.multiFaceLandmarks.length) {
            state.landmarks = null;
            state.verified = false;
            syncForms();
            return;
        }

        const landmarks = results.multiFaceLandmarks[0];
        state.landmarks = landmarks;
        state.liveFrames += 1;
        state.lastDetectionAt = Date.now();

        const leftEAR = eyeAspectRatio(landmarks, 159, 145, 33, 133);
        const rightEAR = eyeAspectRatio(landmarks, 386, 374, 362, 263);
        const ear = (leftEAR + rightEAR) / 2;

        if (ear < 0.18 && !state.wasBlinkLow) state.wasBlinkLow = true;
        if (ear >= 0.2 && state.wasBlinkLow) {
            state.wasBlinkLow = false;
            state.blinkCount += 1;
        }

        const eyeMidX = (landmarks[33].x + landmarks[263].x) / 2;
        const yaw = landmarks[1].x - eyeMidX;
        state.yawMin = state.yawMin === null ? yaw : Math.min(state.yawMin, yaw);
        state.yawMax = state.yawMax === null ? yaw : Math.max(state.yawMax, yaw);
        const yawVariance = (state.yawMax - state.yawMin);

        state.livenessScore = Math.min(100, (state.blinkCount >= 1 ? 45 : 0) + Math.min(55, yawVariance * 1800));

        // LIVENESS ONLY - no face matching required!
        const liveSignalOk = state.running && state.liveFrames >= 15;
        state.verified = state.livenessScore >= 70 && liveSignalOk;

        if (state.verified) {
            state.verificationAt = Date.now();
        }

        scoresEl.textContent = state.livenessScore.toFixed(1) + '%';
        scoresEl.style.color = state.livenessScore >= 70 ? '#1a7f37' : '#1f2328';

        if (state.verified) {
            setStatus('✓ Verified!', true);
        } else if (state.livenessScore >= 40) {
            setStatus('Keep blinking and turning head...', false);
        } else {
            setStatus('Verification incomplete', false);
        }

        syncForms();
    });

    async function startCamera() {
        if (state.running) return;

        camera = new Camera(camEl, {
            onFrame: async () => {
                await faceMesh.send({ image: camEl });
            },
            width: 320,
            height: 240,
        });

        await camera.start();
        state.running = true;
        state.liveFrames = 0;
        state.lastDetectionAt = 0;
        state.verificationAt = 0;
        state.verified = false;
        syncForms();
        setStatus('Camera started. Complete liveness challenge.');
    }

    function stopCamera() {
        if (camera && typeof camera.stop === 'function') {
            camera.stop();
        }

        if (camEl.srcObject) {
            camEl.srcObject.getTracks().forEach(track => track.stop());
            camEl.srcObject = null;
        }

        state.running = false;
        state.landmarks = null;
        state.verified = false;
        state.liveFrames = 0;
        state.lastDetectionAt = 0;
        state.verificationAt = 0;
        syncForms();
        setStatus('Camera stopped. Start camera to continue.', false);
    }

    async function registerFace() {
        if (!select.value) {
            setStatus('Select student first.');
            return;
        }

        if (!state.landmarks) {
            setStatus('No face detected for registration.');
            return;
        }

        const signature = extractSignature(state.landmarks);
        const resp = await fetch("{{ route('attendance.face-register') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                signature,
                student_id: select.value,
            })
        });

        if (resp.ok) {
            state.signature = signature;
            msgEl.textContent = 'Face profile registered for selected student.';
            setStatus('Face profile registered', true);
            const selected = select.options[select.selectedIndex];
            if (selected) selected.dataset.face = '1';
            faceStatus.textContent = 'Face profile is available for this student.';
        } else {
            setStatus('Registration failed.');
        }
    }

    select.addEventListener('change', async () => {
        await fetchSignature(select.value);
        state.verified = false;
        state.matchScore = 0;
        state.livenessScore = 0;
        state.blinkCount = 0;
        state.yawMin = null;
        state.yawMax = null;
        state.liveFrames = 0;
        state.verificationAt = 0;
        syncForms();
    });

    document.getElementById('start-camera').addEventListener('click', startCamera);
    document.getElementById('stop-camera').addEventListener('click', stopCamera);
    document.getElementById('register-face').addEventListener('click', registerFace);
    document.getElementById('run-verify').addEventListener('click', () => {
        if (!state.running) {
            setStatus('Start camera first.');
            return;
        }

        if (!state.signature) {
            setStatus('Register face profile for selected student first.');
            return;
        }

        setStatus(state.verified ? 'Verified' : 'Verification incomplete', state.verified);
    });

    forms.forEach(form => {
        form.addEventListener('submit', (event) => {
            const liveSignalOk = state.running && (Date.now() - state.lastDetectionAt) <= 1500 && state.liveFrames >= 15;
            const verifyFresh = (Date.now() - state.verificationAt) <= 60000;

            if (!select.value) {
                event.preventDefault();
                setStatus('Please select a student first.');
                return;
            }

            if (!state.verified || !liveSignalOk || !verifyFresh) {
                event.preventDefault();
                setStatus('Attendance blocked: complete live MediaPipe verification first.');
            }
        });
    });

    setInterval(() => {
        fetch("{{ route('api.server-time') }}")
            .then(r => r.json())
            .then(data => {
                document.getElementById('server-time').textContent = data.time;
            })
            .catch(() => {});
    }, 30000);

    syncForms();
    updateSubmitButtons();
})();
</script>
@endpush
