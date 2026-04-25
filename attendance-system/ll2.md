The file looks correct structurally. The problems are:

1. **Green border appears mid-movement** — because `spoofPassed` triggers instantly when both challenges complete, no waiting for person to re-center
2. **Eyebrow detection too sensitive** — triggers from normal expression changes
3. **No visible countdown on camera screen** — person doesn't know when snap happens

Here are all the fixes:

---

**First — add a countdown overlay div in the HTML section.** Find the video tag and wrap it:

```html
{{-- FIND THIS: --}}
<video id="cam" autoplay playsinline style="width:320px; border:1px solid var(--color-border-default); border-radius:6px;"></video>
<canvas id="overlay" width="320" height="240" style="display:none;"></canvas>

{{-- CHANGE TO: --}}
<div id="cam-wrapper" style="position:relative; width:320px; display:inline-block;">
    <video id="cam" autoplay playsinline style="width:320px; display:block; border:1px solid var(--color-border-default); border-radius:6px;"></video>
    <canvas id="overlay" width="320" height="240" style="display:none;"></canvas>
    <div id="cam-overlay-text" style="
        display:none;
        position:absolute;
        top:0; left:0;
        width:100%; height:100%;
        background:rgba(0,0,0,0.55);
        border-radius:6px;
        color:white;
        font-size:18px;
        font-weight:700;
        text-align:center;
        justify-content:center;
        align-items:center;
        flex-direction:column;
        gap:10px;
        z-index:20;
    "></div>
</div>
```

---

**Second — replace entire `faceMesh.onResults` challenge + capture logic:**

```javascript
faceMesh.onResults((results) => {
    if (!results.multiFaceLandmarks || !results.multiFaceLandmarks.length) {
        state.landmarks = null;
        state.verified = false;
        state.spoofPassed = false;
        state.matchScore = 0;
        state.matchSamples = [];
        syncForms();
        return;
    }

    const landmarks = results.multiFaceLandmarks[0];
    state.landmarks = landmarks;
    state.liveFrames += 1;
    state.lastDetectionAt = Date.now();

    // Pick challenges on first frame
    if (state.liveFrames === 1) {
        state.challenges = pickChallenges();
        state.challengeCompleted = [false, false];
        state.currentChallengeIdx = 0;
        state.pitchBaseline = null;
        state.browBaseline = null;
        state.challengeHoldFrames = 0;
    }

    const liveSignalOk = state.running && state.liveFrames >= 15;

    // Only process challenges if not already verifying
    if (!state.mlVerifying && !state.verified) {
        const currentChallenge = state.challenges[state.currentChallengeIdx];

        if (currentChallenge && !state.challengeCompleted[state.currentChallengeIdx]) {
            const done = currentChallenge.check(landmarks, state);

            if (done) {
                state.challengeHoldFrames++;
                if (state.challengeHoldFrames >= 5) { // 5 frames = more reliable
                    state.challengeCompleted[state.currentChallengeIdx] = true;
                    state.challengeHoldFrames = 0;

                    if (state.currentChallengeIdx < 1) {
                        state.currentChallengeIdx++;
                        state.pitchBaseline = null;
                        state.browBaseline = null;
                        state.challengeBlinkLow = false;
                    }
                }
            } else {
                state.challengeHoldFrames = 0;
            }
        }
    }

    const allDone = state.challengeCompleted[0] && state.challengeCompleted[1];
    state.spoofPassed = allDone && liveSignalOk;
    state.livenessScore = (state.challengeCompleted[0] ? 50 : 0) + (state.challengeCompleted[1] ? 50 : 0);

    const c1done = state.challengeCompleted[0];
    const c2done = state.challengeCompleted[1];
    const ch1 = state.challenges[0]?.instruction || '';
    const ch2 = state.challenges[1]?.instruction || '';

    // Trigger ML verification with countdown
    if (state.spoofPassed && select.value && !state.mlVerifying && !state.verified) {
        state.mlVerifying = true;

        const overlayEl = document.getElementById('cam-overlay-text');

        // Show overlay with instructions
        overlayEl.style.display = 'flex';
        overlayEl.innerHTML = `
            <div style="font-size:15px; padding: 0 12px; text-align:center;">
                ✅ Liveness passed!<br>
                <span style="font-size:13px; font-weight:400;">Look straight at camera & stay still</span>
            </div>
            <div id="snap-countdown" style="font-size:64px; font-weight:900; line-height:1;">5</div>
            <div style="font-size:12px; font-weight:400;">📸 Capturing in...</div>
        `;
        setStatus('✅ Both tasks done! Look straight — capturing soon...', true);

        let countdown = 5;
        const countdownEl = () => document.getElementById('snap-countdown');

        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdownEl()) countdownEl().textContent = countdown;

            if (countdown <= 0) {
                clearInterval(countdownInterval);

                // Update overlay to "capturing"
                overlayEl.innerHTML = `
                    <div style="font-size:18px;">📸 Capturing...</div>
                `;

                // Capture frame
                const canvas = document.createElement('canvas');
                canvas.width = camEl.videoWidth || 320;
                canvas.height = camEl.videoHeight || 240;
                canvas.getContext('2d').drawImage(camEl, 0, 0);

                // Show green border freeze effect
                canvas.style.position = 'absolute';
                canvas.style.top = '0';
                canvas.style.left = '0';
                canvas.style.width = '320px';
                canvas.style.height = '240px';
                canvas.style.border = '4px solid #1a7f37';
                canvas.style.borderRadius = '6px';
                canvas.style.zIndex = '10';
                canvas.style.boxShadow = '0 0 20px rgba(26, 127, 55, 0.6)';
                document.getElementById('cam-wrapper').appendChild(canvas);

                // Hide overlay text (canvas is showing frozen frame now)
                overlayEl.style.display = 'none';
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
                        canvas.remove();
                        overlayEl.style.display = 'none';

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
                            setTimeout(() => {
                                state.mlVerifying = false;
                                // Reset challenges so user must redo them
                                state.challengeCompleted = [false, false];
                                state.currentChallengeIdx = 0;
                                state.liveFrames = 0;
                                state.pitchBaseline = null;
                                state.browBaseline = null;
                                state.challengeBlinkLow = false;
                                state.challenges = pickChallenges(); // new random challenges
                            }, 4000);
                        }
                    } catch (e) {
                        canvas.remove();
                        overlayEl.style.display = 'none';
                        state.verified = false;
                        state.matchScore = 0;
                        setStatus('⚠️ Verification error. Check ML service is running.', false);
                        setTimeout(() => {
                            state.mlVerifying = false;
                            state.challengeCompleted = [false, false];
                            state.currentChallengeIdx = 0;
                            state.liveFrames = 0;
                            state.challenges = pickChallenges();
                        }, 3000);
                    }
                    syncForms();
                }, 'image/jpeg', 0.92);
            }
        }, 1000);
    }

    if (state.verified) {
        state.verificationAt = Date.now();
    }

    // Scores display
    scoresEl.textContent = state.verified
        ? `Liveness 100% | AI Match ${state.matchScore.toFixed(1)}%`
        : `Task 1: ${c1done ? '✅' : '⏳'} ${ch1} | Task 2: ${c2done ? '✅' : '⏳'} ${ch2}`;
    scoresEl.style.color = state.verified ? '#1a7f37' : '#1f2328';

    // Status message
    if (state.verified) {
        setStatus(`✅ Identity verified! Confidence: ${state.matchScore.toFixed(1)}%`, true);
    } else if (state.mlVerifying) {
        // Don't overwrite countdown messages
    } else if (!liveSignalOk) {
        setStatus('Position your face in frame...', false);
    } else if (!c1done) {
        setStatus(ch1, false);
    } else if (!c2done) {
        setStatus(ch2, false);
    }

    syncForms();
});
```

---

**Third — fix the eyebrow challenge threshold** (more strict, needs bigger movement):

```javascript
{
    id: 'raise_eyebrows',
    instruction: '🤨 Raise your eyebrows UP',
    check: (landmarks, state) => {
        const leftBrow = landmarks[70];
        const leftEyeTop = landmarks[159];
        const browEyeDist = leftEyeTop.y - leftBrow.y;

        // Set baseline on first call
        if (!state.browBaseline) {
            state.browBaseline = browEyeDist;
            return false;
        }

        // Need significant raise — 0.025 is more strict than 0.015
        return (browEyeDist - state.browBaseline) > 0.025;
    }
},
```

---

**Also update `stopCamera()` to hide the overlay:**

```javascript
// ADD this line in stopCamera() after the other state resets:
const overlayEl = document.getElementById('cam-overlay-text');
if (overlayEl) overlayEl.style.display = 'none';
```

---

Then run:
```bash
php artisan view:clear
```

**What happens now:**
```
Task 1 appears → user does it → ✅
Task 2 appears → user does it → ✅
Both done →
  Camera screen dims with overlay:
  "✅ Liveness passed! Look straight & stay still"
  Big number: 5... 4... 3... 2... 1...
  "📸 Capturing in..."
  →
  Countdown hits 0 → green border freeze → AI verifies
  →
  ✅ Identity verified! or ❌ retry with NEW random challenges
```
