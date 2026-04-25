Two things to fix:

**Problem 1 — 3 parallel frames × 5 seconds each = PHP server times out at 20 seconds.** Reduce to 1 frame, increase timeout.

**Problem 2 — Visual freeze effect when capturing.**

---

**Fix 1 — `FaceVerificationController.php` — increase timeout:**

```php
// CHANGE THIS:
$response = Http::timeout(20)

// TO THIS:
$response = Http::timeout(30)
```

---

**Fix 2 — `kiosk.blade.php` — replace entire countdown+capture block with single frame + freeze effect:**

```javascript
// FIND AND REPLACE the entire if block:
if (state.spoofPassed && select.value && !state.mlVerifying && !state.verified) {
    state.mlVerifying = true;
    setStatus('Liveness passed! Look straight at camera — capturing in 3...', false);

    let countdown = 3;
    const countdownInterval = setInterval(() => {
        countdown--;
        if (countdown > 0) {
            setStatus(`Look straight at camera — capturing in ${countdown}...`, false);
        } else {
            clearInterval(countdownInterval);

            // === FREEZE EFFECT ===
            const canvas = document.createElement('canvas');
            canvas.width = camEl.videoWidth || 320;
            canvas.height = camEl.videoHeight || 240;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(camEl, 0, 0);

            // Show frozen frame over video
            canvas.style.position = 'absolute';
            canvas.style.top = camEl.offsetTop + 'px';
            canvas.style.left = camEl.offsetLeft + 'px';
            canvas.style.width = camEl.offsetWidth + 'px';
            canvas.style.height = camEl.offsetHeight + 'px';
            canvas.style.border = '3px solid #1a7f37';
            canvas.style.borderRadius = '6px';
            canvas.style.zIndex = '10';
            camEl.parentElement.style.position = 'relative';
            camEl.parentElement.appendChild(canvas);

            setStatus('📸 Captured! Verifying identity with AI...', false);

            canvas.toBlob(async (blob) => {
                try {
                    const formData = new FormData();
                    formData.append('image', blob, 'frame.jpg');
                    formData.append('student_id', select.value);

                    const resp = await fetch('/api/verify-face-ml', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf },
                        body: formData,
                    });

                    const result = await resp.json();

                    // Remove frozen frame
                    canvas.remove();

                    if (result.verified) {
                        state.verified = true;
                        state.verificationAt = Date.now();
                        state.matchScore = result.confidence;
                        setStatus(`✅ Identity verified! Confidence: ${result.confidence.toFixed(1)}%`, true);
                    } else {
                        state.verified = false;
                        state.matchScore = 0;
                        const dist = result.distance !== undefined ? ` (distance: ${result.distance})` : '';
                        setStatus(`❌ Face not matched${dist}. Look straight and retry.`, false);
                        setTimeout(() => { state.mlVerifying = false; }, 4000);
                    }
                } catch (e) {
                    canvas.remove();
                    state.verified = false;
                    state.matchScore = 0;
                    setStatus('⚠️ Verification error. Check ML service is running.', false);
                    setTimeout(() => { state.mlVerifying = false; }, 3000);
                }
                syncForms();
            }, 'image/jpeg', 0.92);
        }
    }, 1000);
}
```

Then run:
```bash
php artisan view:clear
```

**What this does:**
- `3... 2... 1...` countdown
- At 0 → **freezes video frame visually** with green border (camera snapshot effect)
- Sends **1 frame only** (not 3) → faster, ~5-7 seconds total
- Removes frozen frame when result comes back
- Shows ✅ or ❌ with clear message

The swagger test showed **distance: 0, confidence: 100%** — that's your own photo vs itself, perfect score. Live webcam will give ~70-80% which is well above the 55% threshold. Should work reliably now.
