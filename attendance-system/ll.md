Great question! Let me answer both parts:

---

## What MediaPipe CAN Detect

```
MediaPipe has 3 separate models:

┌─────────────────────────────────────────────────────┐
│ 1. FACE MESH (already using)                        │
│    468 landmarks on face                            │
│    Can detect:                                      │
│    ✓ Blink (EAR formula)                           │
│    ✓ Head turn left/right (Yaw)                    │
│    ✓ Head nod up/down (Pitch)                      │
│    ✓ Mouth open (lip distance)                     │
│    ✓ Eyebrow raise (eyebrow Y position)            │
│    ✓ Smile (mouth corner X distance)               │
├─────────────────────────────────────────────────────┤
│ 2. HANDS (not loaded yet — needs extra script)      │
│    21 landmarks per hand                            │
│    Can detect:                                      │
│    ✓ Raised hand                                   │
│    ✓ Number of fingers up (1,2,3,4,5)              │
│    ✓ Thumbs up                                     │
│    ✓ Peace sign ✌                                  │
│    ✓ Specific finger raised                        │
├─────────────────────────────────────────────────────┤
│ 3. POSE (full body — overkill for kiosk)           │
│    33 body landmarks                               │
│    Can detect arms raised, shoulders etc.          │
└─────────────────────────────────────────────────────┘
```

---

## Sir's Suggestion — Randomized Challenge System

```
OLD (predictable — recordable):
  Always: blink + turn head
  Attacker records once → replays forever ✗

NEW (randomized — not recordable):
  Every session picks 2 random challenges:
  Round 1: "Open your mouth"
  Round 2: "Raise your left eyebrow"

  Attacker can't pre-record because
  they don't know what challenge comes next ✓
```

---

## Implementation Plan

I'll use **Face Mesh only** (already loaded) for now — no new libraries needed. These challenges are all detectable with existing 468 landmarks:

**Challenge Pool (Face Mesh only):**
```
1. BLINK          → EAR < 0.18
2. TURN HEAD LEFT → yaw < -0.04
3. TURN HEAD RIGHT → yaw > +0.04
4. OPEN MOUTH     → lip distance > threshold
5. NOD HEAD DOWN  → pitch increases
6. RAISE EYEBROWS → eyebrow Y moves up
```

---

## The Code Change in `kiosk.blade.php`

Replace the entire liveness section. Find and replace inside `faceMesh.onResults`:

```javascript
// ============================================
// RANDOMIZED CHALLENGE LIVENESS SYSTEM
// ============================================

// ADD THIS at the top of your JS (after state object):
const CHALLENGES = [
    {
        id: 'blink',
        instruction: '👁 Blink your eyes',
        check: (landmarks, state) => {
            const leftEAR = eyeAspectRatio(landmarks, 159, 145, 33, 133);
            const rightEAR = eyeAspectRatio(landmarks, 386, 374, 362, 263);
            const ear = (leftEAR + rightEAR) / 2;
            if (ear < 0.18 && !state.challengeBlinkLow) state.challengeBlinkLow = true;
            if (ear >= 0.21 && state.challengeBlinkLow) {
                state.challengeBlinkLow = false;
                return true; // completed
            }
            return false;
        }
    },
    {
        id: 'turn_left',
        instruction: '⬅ Turn your head LEFT',
        check: (landmarks, state) => {
            const eyeMidX = (landmarks[33].x + landmarks[263].x) / 2;
            const yaw = landmarks[1].x - eyeMidX;
            return yaw < -0.04;
        }
    },
    {
        id: 'turn_right',
        instruction: '➡ Turn your head RIGHT',
        check: (landmarks, state) => {
            const eyeMidX = (landmarks[33].x + landmarks[263].x) / 2;
            const yaw = landmarks[1].x - eyeMidX;
            return yaw > 0.04;
        }
    },
    {
        id: 'open_mouth',
        instruction: '😮 Open your mouth wide',
        check: (landmarks, state) => {
            // Upper lip = 13, Lower lip = 14
            const upperLip = landmarks[13];
            const lowerLip = landmarks[14];
            const mouthOpen = Math.abs(upperLip.y - lowerLip.y);
            return mouthOpen > 0.04;
        }
    },
    {
        id: 'nod',
        instruction: '⬇ Nod your head DOWN',
        check: (landmarks, state) => {
            // Nose tip Y vs forehead Y — if nose drops relative to forehead
            const noseTip = landmarks[1];
            const forehead = landmarks[10];
            const pitch = noseTip.y - forehead.y;
            if (!state.pitchBaseline) state.pitchBaseline = pitch;
            return (pitch - state.pitchBaseline) > 0.03;
        }
    },
    {
        id: 'raise_eyebrows',
        instruction: '🤨 Raise your eyebrows UP',
        check: (landmarks, state) => {
            // Eyebrow landmark 70 (left) and 300 (right)
            // Eye landmark 159 (left top)
            const leftBrow = landmarks[70];
            const leftEyeTop = landmarks[159];
            const browEyeDist = leftEyeTop.y - leftBrow.y;
            if (!state.browBaseline) state.browBaseline = browEyeDist;
            return (browEyeDist - state.browBaseline) > 0.015;
        }
    },
];

// PICK 2 RANDOM CHALLENGES (different each time)
function pickChallenges() {
    const shuffled = [...CHALLENGES].sort(() => Math.random() - 0.5);
    return shuffled.slice(0, 2);
}
```

---

Now replace the **state object** with these new fields:

```javascript
const state = {
    running: false,
    landmarks: null,
    signature: null,
    livenessScore: 0,
    matchScore: 0,
    verified: false,
    liveFrames: 0,
    lastDetectionAt: 0,
    verificationAt: 0,
    hasCheckInToday: false,
    hasCheckOutToday: false,
    spoofPassed: false,
    mlVerifying: false,
    matchSamples: [],
    blinkCount: 0,
    wasBlinkLow: false,
    yawMin: null,
    yawMax: null,

    // NEW CHALLENGE FIELDS:
    challenges: [],           // 2 random challenges picked
    currentChallengeIdx: 0,   // which challenge we're on (0 or 1)
    challengeCompleted: [],   // [false, false] → [true, true]
    challengeBlinkLow: false, // for blink challenge
    pitchBaseline: null,      // for nod challenge
    browBaseline: null,       // for eyebrow challenge
    challengeHoldFrames: 0,   // must hold pose for N frames
};
```

---

Now replace the **liveness logic inside `faceMesh.onResults`**:

```javascript
// REMOVE all the old EAR/yaw liveness code
// REPLACE WITH:

// First frame — pick challenges
if (state.liveFrames === 1) {
    state.challenges = pickChallenges();
    state.challengeCompleted = [false, false];
    state.currentChallengeIdx = 0;
    state.pitchBaseline = null;
    state.browBaseline = null;
}

const currentChallenge = state.challenges[state.currentChallengeIdx];

if (currentChallenge && !state.challengeCompleted[state.currentChallengeIdx]) {
    const done = currentChallenge.check(landmarks, state);

    if (done) {
        state.challengeHoldFrames++;
        // Must hold for 3 frames to avoid false positive
        if (state.challengeHoldFrames >= 3) {
            state.challengeCompleted[state.currentChallengeIdx] = true;
            state.challengeHoldFrames = 0;

            if (state.currentChallengeIdx < 1) {
                state.currentChallengeIdx++;
                // Reset baselines for next challenge
                state.pitchBaseline = null;
                state.browBaseline = null;
            }
        }
    } else {
        state.challengeHoldFrames = 0;
    }
}

// Both challenges done = liveness passed
const allDone = state.challengeCompleted[0] && state.challengeCompleted[1];
const liveSignalOk = state.running && state.liveFrames >= 15;
state.spoofPassed = allDone && liveSignalOk;

// Progress display
const c1done = state.challengeCompleted[0];
const c2done = state.challengeCompleted[1];
const ch1 = state.challenges[0]?.instruction || '';
const ch2 = state.challenges[1]?.instruction || '';

state.livenessScore = (c1done ? 50 : 0) + (c2done ? 50 : 0);

scoresEl.textContent = state.verified
    ? `Liveness 100% | AI Match ${state.matchScore.toFixed(1)}%`
    : `Task 1: ${c1done ? '✅' : '⏳'} ${ch1} | Task 2: ${c2done ? '✅' : '⏳'} ${ch2}`;

// Status message
if (state.verified) {
    setStatus(`✅ Identity verified! Confidence: ${state.matchScore.toFixed(1)}%`, true);
} else if (!liveSignalOk) {
    setStatus('Position your face in frame...', false);
} else if (!c1done) {
    setStatus(ch1, false);  // Show current task
} else if (!c2done) {
    setStatus(ch2, false);  // Show next task
} else if (state.mlVerifying) {
    setStatus('Liveness passed. Verifying identity with AI...', false);
}
```

---

Also update **`resetVerificationState()`**:

```javascript
const resetVerificationState = () => {
    state.running = true;
    state.liveFrames = 0;
    state.lastDetectionAt = 0;
    state.verificationAt = 0;
    state.verified = false;
    state.spoofPassed = false;
    state.matchScore = 0;
    state.matchSamples = [];
    state.mlVerifying = false;
    state.blinkCount = 0;
    state.yawMin = null;
    state.yawMax = null;
    // NEW:
    state.challenges = [];
    state.currentChallengeIdx = 0;
    state.challengeCompleted = [false, false];
    state.challengeBlinkLow = false;
    state.pitchBaseline = null;
    state.browBaseline = null;
    state.challengeHoldFrames = 0;
    syncForms();
};
```

Same resets in `stopCamera()` and `select.addEventListener('change')`.

---

## What Sir Will See

```
Session 1:
  Camera shows: "👁 Blink your eyes"
  Student blinks ✅
  Camera shows: "😮 Open your mouth wide"
  Student opens mouth ✅
  → Liveness passed → AI verification starts

Session 2 (different challenges):
  Camera shows: "➡ Turn your head RIGHT"
  Student turns ✅
  Camera shows: "🤨 Raise your eyebrows UP"
  Student raises brows ✅
  → Liveness passed

Session 3 (different again):
  Camera shows: "⬅ Turn your head LEFT"
  Camera shows: "⬇ Nod your head DOWN"
```

A recorded video of Session 1 will FAIL Session 2 and 3 because different challenges. ✅

Apply the changes, run `php artisan view:clear`, and test. Tell me which challenges are appearing and if they're being detected correctly.
