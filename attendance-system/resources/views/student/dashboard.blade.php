@extends('layouts.app')

@section('content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <div>
        <h2 style="margin:0; font-size: 24px;">{{ $student->full_name }}</h2>
        <div class="text-muted">{{ $student->student_id }}</div>
    </div>
    <div class="Box" style="padding:10px 14px;">
        <div class="text-small text-muted">Server Time</div>
        <div id="server-time" style="font-size:22px; font-weight:700;">{{ now()->format('H:i:s') }}</div>
    </div>
</div>

<div class="Box mb-4">
    <div class="Box-header"><h2>Face Verification (MediaPipe)</h2></div>
    <div class="Box-body">
        <div class="d-flex" style="gap:16px; align-items:flex-start; flex-wrap: wrap;">
            <video id="cam" autoplay playsinline style="width:320px; border:1px solid var(--color-border-default); border-radius:6px;"></video>
            <canvas id="overlay" width="320" height="240" style="display:none;"></canvas>
            <div style="min-width: 260px;">
                <div class="mb-4">
                    <div style="font-size: 12px; color: #656d76; margin-bottom: 4px;">Liveness Status</div>
                    <div id="liveness-status" style="font-weight: 600; font-size: 14px; color: #cf222e;">Not verified</div>
                    <div id="liveness-hint" style="font-size: 12px; color: #656d76; margin-top: 4px;">Blink once and turn your head left/right</div>
                </div>
                <div class="mb-4">
                    <div style="font-size: 12px; color: #656d76; margin-bottom: 4px;">Liveness Score</div>
                    <div id="match-score" style="font-weight: 600; font-size: 14px; color: #1f2328;">0%</div>
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

<div class="Box mb-4">
    <div class="Box-header"><h2>Today's Attendance</h2></div>
    <div class="Box-body">
        @if($todayStatus['checked_in'] && $todayStatus['checked_out'])
            <div class="State State--green">Attendance complete for today.</div>
        @elseif($todayStatus['checked_in'])
            <div class="mb-4">Checked in at <span class="text-bold">{{ $todayStatus['check_in_time']->format('H:i:s') }}</span></div>
            <form method="POST" action="{{ route('student.check-out') }}" id="checkout-form">
                @csrf
                <input type="hidden" name="face_verified" value="0">
                <input type="hidden" name="liveness_score" value="0">
                <input type="hidden" name="match_score" value="0">
                <input type="hidden" name="blink_count" value="0">
                <input type="hidden" name="yaw_variance" value="0">
                <button type="submit" class="btn btn-success">Check Out</button>
            </form>
        @else
            <form method="POST" action="{{ route('student.check-in') }}" id="checkin-form">
                @csrf
                <div class="form-group" style="max-width: 320px;">
                    <label class="form-label">Stated Time (optional)</label>
                    <input type="datetime-local" name="stated_time" class="form-control" max="{{ now()->format('Y-m-d\\TH:i') }}">
                </div>
                <input type="hidden" name="face_verified" value="0">
                <input type="hidden" name="liveness_score" value="0">
                <input type="hidden" name="match_score" value="0">
                <input type="hidden" name="blink_count" value="0">
                <input type="hidden" name="yaw_variance" value="0">
                <button type="submit" class="btn btn-success">Check In</button>
            </form>
        @endif
    </div>
</div>

<div class="Box mb-4">
    <div class="Box-header"><h2>Attendance Heatmap ({{ $currentYear }})</h2></div>
    <div class="Box-body">
        <x-attendance-heatmap :heatmapData="$heatmapData" :year="$currentYear" />
    </div>
</div>

<div class="Box">
    <div class="Box-header"><h2>Recent Activity</h2></div>
    <div class="Box-body" style="padding:0;">
        <table class="Table">
            <thead><tr><th>Date</th><th>Type</th><th>Recorded</th><th>Face</th><th>Liveness</th></tr></thead>
            <tbody>
            @forelse($recentAttendance as $log)
                <tr>
                    <td>{{ $log->date->format('d M Y') }}</td>
                    <td>{{ strtoupper($log->type) }}</td>
                    <td>{{ $log->recorded_time->format('H:i:s') }}</td>
                    <td>{{ $log->face_verified ? 'Verified' : 'N/A' }}</td>
                    <td>{{ $log->liveness_score ?? 'N/A' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">No recent records.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/drawing_utils/drawing_utils.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js"></script>
<script>
(() => {
    const studentSignature = @json($student->face_signature ?? []);
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const camEl = document.getElementById('cam');
    const canvas = document.getElementById('overlay');
    const ctx = canvas.getContext('2d');

    const state = {
        running: false,
        landmarks: null,
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

    let camera;

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
        const cosine = dot / denom;
        return Math.max(0, Math.min(100, ((cosine + 1) / 2) * 100));
    }

    function updateFormVerification() {
        const forms = ['checkin-form', 'checkout-form']
            .map(id => document.getElementById(id))
            .filter(Boolean);

        forms.forEach(form => {
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
        const forms = ['checkin-form', 'checkout-form']
            .map(id => document.getElementById(id))
            .filter(Boolean);

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
        const el = document.getElementById('liveness-status');
        el.textContent = message;
        el.style.color = ok ? '#1a7f37' : '#cf222e';
        el.style.fontSize = '14px';
        el.style.fontWeight = '600';
    }

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
            updateFormVerification();
            return;
        }

        const landmarks = results.multiFaceLandmarks[0];
        state.landmarks = landmarks;
        state.liveFrames += 1;
        state.lastDetectionAt = Date.now();

        const leftEAR = eyeAspectRatio(landmarks, 159, 145, 33, 133);
        const rightEAR = eyeAspectRatio(landmarks, 386, 374, 362, 263);
        const ear = (leftEAR + rightEAR) / 2;

        if (ear < 0.18 && !state.wasBlinkLow) {
            state.wasBlinkLow = true;
        }
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
        
        document.getElementById('match-score').textContent = state.livenessScore.toFixed(1) + '%';
        document.getElementById('match-score').style.color = state.livenessScore >= 70 ? '#1a7f37' : '#1f2328';
        document.getElementById('verify-message').textContent = `Blinks: ${state.blinkCount} | Head movement: ${yawVariance.toFixed(4)}`;
        
        if (state.verified) {
            setStatus('✓ Verified! You can submit attendance.', true);
        } else if (state.livenessScore >= 40) {
            setStatus('Keep blinking and turning head...', false);
        } else {
            setStatus('Verification incomplete - blink & turn head', false);
        }

        updateFormVerification();

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawConnectors(ctx, landmarks, FACEMESH_TESSELATION, { color: '#90a4ae', lineWidth: 1 });
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
        updateFormVerification();
        setStatus('Camera started. Perform liveness challenge.');
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
        updateFormVerification();
        setStatus('Camera stopped. Start camera to continue.', false);
    }

    async function registerFace() {
        if (!state.landmarks) {
            setStatus('No face detected for registration.');
            return;
        }

        const signature = extractSignature(state.landmarks);

        const resp = await fetch("{{ route('student.face-register') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ signature })
        });

        if (resp.ok) {
            document.getElementById('verify-message').textContent = 'Face profile registered. Refresh page to use latest profile.';
            setStatus('Face profile registered.', true);
        } else {
            setStatus('Could not register face profile.');
        }
    }

    document.getElementById('start-camera').addEventListener('click', startCamera);
    document.getElementById('stop-camera').addEventListener('click', stopCamera);
    document.getElementById('register-face').addEventListener('click', registerFace);
    document.getElementById('run-verify').addEventListener('click', () => {
        if (!state.running) {
            setStatus('Start camera first.');
            return;
        }
        setStatus(state.verified ? 'Verified. You can submit attendance.' : 'Keep blinking and moving your head for liveness.');
    });

    setInterval(() => {
        fetch("{{ route('api.server-time') }}")
            .then(r => r.json())
            .then(data => { document.getElementById('server-time').textContent = data.time; })
            .catch(() => {});
    }, 30000);

    document.querySelectorAll('#checkin-form, #checkout-form').forEach(form => {
        form.addEventListener('submit', (event) => {
            const liveSignalOk = state.running && (Date.now() - state.lastDetectionAt) <= 1500 && state.liveFrames >= 15;
            const verifyFresh = (Date.now() - state.verificationAt) <= 60000;
            if (!state.verified || !liveSignalOk || !verifyFresh) {
                event.preventDefault();
                setStatus('Attendance blocked: complete live MediaPipe verification first.');
            }
        });
    });

    updateSubmitButtons();
})();
</script>
@endpush
