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
                <label class="form-label">Photo (required)</label>
                <input id="photo-input" class="form-control" type="file" name="photo" accept="image/*" required>
                <input type="hidden" id="face-signature" name="face_signature" value="{{ old('face_signature') }}">
                <div class="text-small text-muted" style="margin-top: 6px;">Max 2MB image file.</div>
                <div id="photo-vector-status" class="text-small text-muted" style="margin-top: 6px;">Upload clear front-face photo to generate secure face vector.</div>
                <img id="photo-preview" alt="Photo preview" style="margin-top:10px; max-width: 220px; border-radius: 6px; border: 1px solid var(--color-border-default); display:none;">
                @error('photo')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
                @error('face_signature')<div class="text-small" style="color: var(--color-danger-fg); margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <button id="register-submit" type="submit" class="btn btn-primary">Register Myself</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js"></script>
<script>
(() => {
    const SIGNATURE_POINT_INDEXES = [
        1, 33, 263, 61, 291, 199, 152, 10, 234, 454,
        70, 63, 105, 66, 300, 293, 334, 296,
        159, 145, 386, 374,
        157, 144, 160, 153,
        384, 381, 387, 380,
        6, 197, 195, 5, 4, 98, 327, 94,
        13, 14, 78, 308, 82, 312, 87, 317,
        172, 397, 136, 365, 150, 379,
        162, 389, 103, 332,
        116, 345, 123, 352,
        54, 284, 21, 251,
        175, 171, 396, 176,
        48, 278, 115, 344,
        164, 393, 167, 394,
    ];
    const EXPECTED_SIGNATURE_LENGTH = SIGNATURE_POINT_INDEXES.length * 2;

    const form = document.getElementById('self-register-form');
    const photoInput = document.getElementById('photo-input');
    const signatureInput = document.getElementById('face-signature');
    const statusEl = document.getElementById('photo-vector-status');
    const previewEl = document.getElementById('photo-preview');
    const submitBtn = document.getElementById('register-submit');

    let faceMesh;

    function setStatus(message, ok = false) {
        statusEl.textContent = message;
        statusEl.style.color = ok ? '#1a7f37' : '#cf222e';
    }

    function toggleSubmit(enabled) {
        submitBtn.disabled = !enabled;
        submitBtn.style.opacity = enabled ? '1' : '0.55';
        submitBtn.style.cursor = enabled ? 'pointer' : 'not-allowed';
    }

    function distance(a, b) {
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        return Math.sqrt((dx * dx) + (dy * dy));
    }

function extractSignature(landmarks) {
    const leftEye  = landmarks[33];
    const rightEye = landmarks[263];
    const chin     = landmarks[152];
    const forehead = landmarks[10];

    const eyeDist  = distance(leftEye, rightEye) || 1;
    const faceH    = distance(chin, forehead)    || 1;
    const centerX  = (leftEye.x + rightEye.x) / 2;
    const centerY  = (leftEye.y + rightEye.y) / 2;

    const vector = [];
    SIGNATURE_POINT_INDEXES.forEach(i => {
        const point = landmarks[i];
        if (!point) {
            return;
        }

        const nx = (point.x - centerX) / eyeDist;
        const ny = (point.y - centerY) / faceH;

        if (!Number.isFinite(nx) || !Number.isFinite(ny)) {
            return;
        }

        vector.push(nx);
        vector.push(ny);
    });

    return vector.map(v => Number(v.toFixed(6)));
}

    function fileToImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => {
                const image = new Image();
                image.onload = () => resolve(image);
                image.onerror = () => reject(new Error('Failed to decode uploaded image.'));
                image.src = reader.result;
            };
            reader.onerror = () => reject(new Error('Could not read uploaded image.'));
            reader.readAsDataURL(file);
        });
    }

    async function getFaceMesh() {
        if (!faceMesh) {
            faceMesh = new FaceMesh({ locateFile: (file) => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}` });
            faceMesh.setOptions({
                maxNumFaces: 1,
                refineLandmarks: true,
                minDetectionConfidence: 0.5,
                minTrackingConfidence: 0.5,
            });
        }

        return faceMesh;
    }

    async function buildVectorFromPhoto(file) {
        const image = await fileToImage(file);
        const mesh = await getFaceMesh();

        const analyzeOnce = () => new Promise(async (resolve, reject) => {
            let settled = false;
            const finish = (callback) => (payload) => {
                if (settled) return;
                settled = true;
                callback(payload);
            };

            const timeoutId = setTimeout(finish(() => reject(new Error('Face analysis timed out. Please try another photo.'))), 12000);

            mesh.onResults(finish((results) => {
                clearTimeout(timeoutId);

                if (!results.multiFaceLandmarks || results.multiFaceLandmarks.length !== 1) {
                    reject(new Error('Photo must contain exactly one clear face.'));
                    return;
                }

                const signature = extractSignature(results.multiFaceLandmarks[0]);

                if (!Array.isArray(signature) || signature.length !== EXPECTED_SIGNATURE_LENGTH) {
                    reject(new Error('Unable to generate valid face vector from photo.'));
                    return;
                }

                resolve(signature);
            }));

            try {
                await mesh.send({ image });
            } catch (error) {
                clearTimeout(timeoutId);
                reject(new Error('MediaPipe processing failed for uploaded photo.'));
            }
        });

        try {
            return await analyzeOnce();
        } catch (_) {
            // Retry once to handle occasional first-pass landmark instability.
            return await analyzeOnce();
        }
    }

    photoInput.addEventListener('change', async () => {
        signatureInput.value = '';
        toggleSubmit(true);

        const file = photoInput.files && photoInput.files[0];
        if (!file) {
            previewEl.style.display = 'none';
            setStatus('Upload clear front-face photo to generate secure face vector.', false);
            return;
        }

        const objectUrl = URL.createObjectURL(file);
        previewEl.src = objectUrl;
        previewEl.style.display = 'block';

        setStatus('Analyzing photo and generating secure face vector...', false);
        toggleSubmit(false);

        try {
            const signature = await buildVectorFromPhoto(file);
            signatureInput.value = JSON.stringify(signature);
            setStatus('Face vector generated successfully from uploaded photo.', true);
            toggleSubmit(true);
        } catch (error) {
            setStatus(error.message || 'Could not generate face vector. Please upload a clearer front-face photo.', false);
            // Keep submit enabled so user can retry or attempt submit-triggered generation.
            toggleSubmit(true);
        }
    });

    form.addEventListener('submit', async (event) => {
        if (signatureInput.value) {
            return;
        }

        event.preventDefault();

        const file = photoInput.files && photoInput.files[0];
        if (!file) {
            setStatus('Face vector missing. Please upload a clear face photo first.', false);
            return;
        }

        setStatus('Generating face vector from your uploaded photo...', false);
        toggleSubmit(false);

        try {
            const signature = await buildVectorFromPhoto(file);
            signatureInput.value = JSON.stringify(signature);
            toggleSubmit(true);
            setStatus('Face vector generated. Submitting registration...', true);
            form.submit();
        } catch (error) {
            setStatus(error.message || 'Could not generate face vector. Please upload a clearer front-face photo.', false);
            toggleSubmit(true);
        }
    });

    // Keep submit usable; vector can be auto-generated on submit if missing.
    toggleSubmit(true);
})();
</script>
@endpush
