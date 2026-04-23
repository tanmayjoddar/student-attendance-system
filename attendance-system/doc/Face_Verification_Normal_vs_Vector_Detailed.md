# Face Verification: Normal vs Vector Calculative (Detailed)

This document explains two concepts clearly:

1. Normal face verification (liveness based)
2. Vector calculative face verification (face signature matching)

It also explains what is currently active in this project.

## 1) Simple meaning

### A) Normal face verification (in this project)

Normal here means: the system verifies that a real live person is in front of camera.

It checks behavior signals like:

- Blink detection
- Head movement (left/right yaw)

If these are strong enough, user is marked verified.

### B) Vector calculative face verification

This means: the system converts face geometry into numeric vector and compares with stored vector.

- Current frame face -> vector A
- Registered face -> vector B
- Similarity score is calculated mathematically

If similarity is high, identity match is likely.

## 2) Current project status (important for presentation)

Current attendance submit path is strict spoof-first + vector-match enforcement.

- Active required checks:
    - face_verified must be true
    - spoof_passed must be true
    - liveness_score must be >= configured minimum
    - match_score must be >= configured minimum
    - registered face vector must exist for student

So your present flow is:

- Real person challenge first (blink + head movement)
- Then vector similarity matching
- Attendance is blocked if either spoof or vector check fails

## 3) Where this is implemented

Core files:

- routes/web.php
- resources/views/attendance/kiosk.blade.php
- app/Http/Controllers/StudentDashboardController.php
- app/Services/AttendanceService.php
- config/attendance.php

## 4) Normal (liveness) verification technical flow

### Step 1: Camera frames

In kiosk page JS, MediaPipe FaceMesh receives webcam frames continuously.

### Step 2: Landmark extraction

FaceMesh returns landmarks for one face.

### Step 3: Blink score

Eye Aspect Ratio style check is used (top-bottom eye distance over left-right eye distance).

- Eye close/open transitions are counted as blink events.

### Step 4: Head movement score

Yaw movement is tracked using nose and eye midpoint relationship.

- System tracks min yaw and max yaw.
- Yaw variance indicates left-right movement.

### Step 5: Liveness score

Score combines blink + yaw variance.

Implementation concept in kiosk JS:

- blink part contributes fixed weight when blink is detected
- yaw part contributes variable weight from motion range

Then verified flag is turned true when score crosses threshold and enough live frames are observed.

### Step 6: Backend enforcement

At submit, backend validates:

- face_verified exists and true
- liveness_score >= min liveness threshold from config

This enforcement lives in AttendanceService validateFaceVerification method.

## 5) Vector calculative verification technical flow

Vector mode is present as reusable logic in kiosk JS.

### A) Vector generation (signature)

Function extractSignature uses selected landmark indices and normalizes geometry by eye distance so scale changes are reduced.

Process:

1. Pick landmark index list (for key face points)
2. Compute face center using left and right eye
3. Normalize each selected point:

$$
\hat{x}_i = \frac{x_i - c_x}{d_{eye}}, \quad \hat{y}_i = \frac{y_i - c_y}{d_{eye}}
$$

4. Append all normalized pairs into one vector

This gives a compact numerical face signature.

### B) Similarity computation

Function similarityScore uses cosine-style similarity:

$$
\cos(\theta) = \frac{A \cdot B}{\|A\|\|B\|}
$$

Then maps from [-1, 1] to [0, 100]:

$$
\text{score} = \max\left(0, \min\left(100, \left(\cos(\theta)+1\right) \times 50\right)\right)
$$

Higher score means higher geometric similarity.

### C) Registration storage

When Register Face is used:

- signature vector is posted to /attendance/face-register
- backend stores it in student face_signature
- face_registered_at timestamp is updated

## 6) Difference in one table

| Item                        | Normal (Liveness)         | Vector Calculative                 |
| --------------------------- | ------------------------- | ---------------------------------- |
| Main question               | Is user live now?         | Is this same person as registered? |
| Input type                  | Motion behavior over time | Numeric geometry signature         |
| Blocks photo spoof          | Stronger                  | Weak alone without liveness        |
| Blocks wrong person         | Limited                   | Better when threshold tuned        |
| Current project enforcement | Yes (active)              | Not mandatory in submit path       |

## 7) Why project uses spoof-first + vector

Reasonable practical benefits:

- Students do not need login flow at kiosk
- Real-time anti-photo behavior is prioritized
- Identity consistency is verified through vector matching
- False positives are reduced by strict threshold + multi-frame averaging

## 8) Current decision rule

Current rule is hybrid and enforced:

$$
\text{allow} = (\text{liveness} \ge L_{min}) \land (\text{match} \ge M_{min})
$$

Current production defaults:

- liveness minimum: 75
- match minimum: 90

## 9) Exact current final statement for sir

Current system performs strict spoof-first verification with MediaPipe liveness signals (blink + head movement), then enforces vector similarity threshold against registered face signature before check-in/check-out is accepted.
