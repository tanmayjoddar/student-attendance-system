The blink has two bugs and eyebrow has one. Let me fix all three precisely.

---

**Bug 1 — Blink debug code overwrites `scoresEl` every frame**

The debug version you pasted still has `scoresEl.textContent = ...` inside the blink check function. This overwrites the task progress display every frame and also interferes with the blink state.

**Bug 2 — Blink uses `challengeHoldFrames >= 5` but blink is a one-shot event**

When eyes reopen, check() returns `true` for exactly ONE frame, then `challengeBlinkLow` resets to `false` so next frame returns `false` again. `challengeHoldFrames` never reaches 5. Blink can never complete.

**Bug 3 — Eyebrow baseline set on first call**

If the person is already slightly raising eyebrows when camera starts, baseline is set too high. Need to average first 20 frames instead.

---

**Fix — replace the entire CHALLENGES array:**

```javascript
const CHALLENGES = [
    {
        id: 'blink',
        instruction: 'Blink your eyes',
        check: (lm, s) => {
            const leftEAR  = eyeAspectRatio(lm, 159, 145, 33, 133);
            const rightEAR = eyeAspectRatio(lm, 386, 374, 362, 263);
            const ear = (leftEAR + rightEAR) / 2;

            // Eye closing
            if (ear < 0.20 && !s.challengeBlinkLow) {
                s.challengeBlinkLow = true;
            }

            // Eye reopened after closing = blink complete
            // Set blinkDoneFrames so holdFrames can count up
            if (ear >= 0.22 && s.challengeBlinkLow) {
                s.challengeBlinkLow  = false;
                s.blinkDoneFrames    = (s.blinkDoneFrames || 0) + 8;
            }

            if ((s.blinkDoneFrames || 0) > 0) {
                s.blinkDoneFrames--;
                return true;  // stays true for 8 frames so holdFrames can count
            }
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
            // lm[159] = left eye top, lm[70] = left eyebrow
            // When eyebrows raise, lm[70].y decreases so distance increases
            const browDist = lm[159].y - lm[70].y;

            // Build baseline over first 20 calls instead of just first call
            // This avoids wrong baseline if person is already raising brows
            if (!s.browBaselineFrames) s.browBaselineFrames = 0;
            if (s.browBaselineFrames < 20) {
                s.browBaseline = s.browBaseline
                    ? (s.browBaseline * s.browBaselineFrames + browDist) / (s.browBaselineFrames + 1)
                    : browDist;
                s.browBaselineFrames++;
                return false;
            }

            // Need raise of 0.018 above baseline (lowered from 0.025)
            return (browDist - s.browBaseline) > 0.018;
        }
    },
];
```

---

**Also add `blinkDoneFrames` to the challenge reset in `resetChallengeState()`:**

```javascript
// FIND in resetChallengeState():
state.challengeBlinkLow   = false;

// CHANGE TO:
state.challengeBlinkLow   = false;
state.blinkDoneFrames     = 0;
```

And same in `resetVerificationState` and wherever challenges are reset after failure:

```javascript
// In the failure timeout setTimeout blocks, add:
state.blinkDoneFrames = 0;
```

---

**Also reset `browBaselineFrames` and `blinkDoneFrames` when moving to next challenge:**

```javascript
// FIND this block inside onResults:
if (state.currentChallengeIdx < 1) {
    state.currentChallengeIdx++;
    state.pitchBaseline  = null;
    state.browBaseline   = null;
    state.challengeBlinkLow = false;
}

// CHANGE TO:
if (state.currentChallengeIdx < 1) {
    state.currentChallengeIdx++;
    state.pitchBaseline       = null;
    state.browBaseline        = null;
    state.browBaselineFrames  = 0;
    state.challengeBlinkLow   = false;
    state.blinkDoneFrames     = 0;
}
```

---

**Add to state object:**

```javascript
// ADD these two fields to state:
blinkDoneFrames:    0,
browBaselineFrames: 0,
```

---

**Also add to `resetChallengeState()`:**

```javascript
state.browBaselineFrames  = 0;
state.blinkDoneFrames     = 0;
```

---

Then run:
```bash
php artisan view:clear
```

**What changed and why:**

| Problem | Old Behavior | New Behavior |
|---|---|---|
| Blink holdFrames | Returns true 1 frame, needs 5 → never completes | Sets `blinkDoneFrames = 8` so stays true for 8 frames → holdFrames accumulates |
| Blink threshold | `< 0.30` same as open — never stable | Close `< 0.20`, open `>= 0.22` with hysteresis gap |
| Eyebrow baseline | Set on frame 1 — may be wrong | Averaged over 20 frames — stable neutral baseline |
| Eyebrow threshold | `0.025` — too strict | `0.018` — easier to trigger |
| Debug scoresEl overwrite | Inside blink check — breaks display | Removed entirely |
