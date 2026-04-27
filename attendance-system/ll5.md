Got it — your plan is:

1. Switch detector from `retinaface` to `opencv` for 3-5x speed
2. Add CORS to `face_rec.py` so browser calls Python directly
3. Change kiosk JS to call `http://127.0.0.1:8001/identify/` directly (no Laravel proxy)
4. Re-register all students (embeddings incompatible between detectors)
5. Attendance still goes through Laravel

---

## I'll write the exact code changes now. First let me confirm:

---

### `face_rec.py` Changes

```python
# Add import at top:
from fastapi.middleware.cors import CORSMiddleware

# Change model config:
MODEL_NAME   = "ArcFace"
DETECTOR     = "opencv"        # was retinaface
THRESHOLD    = 0.55

# Add CORS middleware after app = FastAPI():
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://127.0.0.1:8000", "http://localhost:8000"],
    allow_methods=["POST", "GET", "DELETE"],
    allow_headers=["*"],
)
```

---

### `kiosk.blade.php` Change

In `captureAndIdentify()`, one line change:

```javascript
// OLD
const resp = await fetch('/api/identify-face', {
    method:  'POST',
    headers: { 'X-CSRF-TOKEN': csrf },
    body:    formData,
});

// NEW
const resp = await fetch('http://127.0.0.1:8001/identify/', {
    method: 'POST',
    body:   formData,
});
```

The rest of `handleMatched()` stays identical — attendance calls still go to Laravel with CSRF.

---

### Then:

```bash
# 1. Restart Python (auto-reloads if using uvicorn)
# 2. Re-register all students (embeddings changed)
# 3. Clear Laravel cache
php artisan optimize:clear
```

