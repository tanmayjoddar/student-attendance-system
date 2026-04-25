@extends('layouts.app')

@section('content')
<div class="d-flex flex-justify-between flex-items-center mb-4">
    <div>
        <h2 style="margin:0; font-size: 24px;">Student Self Registration</h2>
        <div class="text-muted">Open page for new students to register with photo</div>
    </div>
    <a href="{{ route('attendance.kiosk') }}" class="btn">Back to Attendance</a>
</div>

<div class="Box" style="max-width: 760px; margin: 0 auto;">
    <div class="Box-header"><h2>Registration Form</h2></div>
    <div class="Box-body">
        <form id="self-register-form" method="POST" action="{{ route('student.register.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="d-flex" style="gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">First Name</label>
                    <input class="form-control" type="text" name="first_name" value="{{ old('first_name') }}" required>
                    @error('first_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Last Name</label>
                    <input class="form-control" type="text" name="last_name" value="{{ old('last_name') }}" required>
                    @error('last_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" value="{{ old('email') }}" required>
                @error('email')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
            </div>
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Father Name</label>
                    <input class="form-control" type="text" name="father_name" value="{{ old('father_name') }}" required>
                    @error('father_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex" style="gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Mother Name</label>
                    <input class="form-control" type="text" name="mother_name" value="{{ old('mother_name') }}" required>
                    @error('mother_name')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="3" required>{{ old('address') }}</textarea>
                @error('address')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex" style="gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Phone</label>
                    <input class="form-control" type="text" name="phone" value="{{ old('phone') }}">
                    @error('phone')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="flex:1; min-width: 220px;">
                    <label class="form-label">Department</label>
                    <input class="form-control" type="text" name="department" value="{{ old('department') }}">
                    @error('department')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

      <div class="form-group">
    <label class="form-label">Face Photo (required)</label>

    {{-- Hidden inputs --}}
    <input type="hidden" id="face-photo-data" name="face_photo_data" value="{{ old('face_photo_data') }}">
    <input type="hidden" id="face-signature" name="face_signature" value="{{ old('face_signature') }}">

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

            <button id="register-submit" type="submit" class="btn btn-primary">Register Myself</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js"></script>
<script>
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
</script>
@endpush
