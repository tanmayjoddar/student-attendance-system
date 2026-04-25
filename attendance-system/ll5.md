Clean plan. Three things needed:

---

## 1. Add Delete to `AdminStudentController.php`

Currently your routes have `.except(['destroy'])` — remove that first.

**`routes/web.php`:**
```php
// FIND:
Route::resource('students', AdminStudentController::class)->except(['destroy']);

// CHANGE TO:
Route::resource('students', AdminStudentController::class);
```

**Add `destroy()` method to `AdminStudentController.php`:**

```php
public function destroy(Student $student): \Illuminate\Http\RedirectResponse
{
    $studentId = $student->student_id;

    // 1. Delete face embedding from FastAPI
    try {
        \Illuminate\Support\Facades\Http::timeout(10)
            ->delete("http://127.0.0.1:8001/delete/{$studentId}");
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning('ML delete failed for ' . $studentId . ': ' . $e->getMessage());
    }

    // 2. Delete photo file from storage
    if ($student->photo_path) {
        \Illuminate\Support\Facades\Storage::disk('public')->delete($student->photo_path);
    }

    // 3. Delete attendance logs
    $student->attendanceLogs()->delete();

    // 4. Delete student record
    $student->delete();

    return redirect()->route('admin.students.index')
        ->with('success', "Student {$studentId} and all related data deleted successfully.");
}
```

---

## 2. Add `/delete/` Endpoint to `face_rec.py`

```python
@app.delete("/delete/{user_id}")
def delete_face(user_id: str):
    """
    Delete a student's face embedding from the ML database.
    Called when admin deletes a student from Laravel.
    """
    db = SessionLocal()
    try:
        record = db.query(FaceEncoding).filter_by(user_id=user_id).first()
        if not record:
            return {
                "ok":      True,
                "deleted": False,
                "message": f"No embedding found for {user_id}",
            }
        db.delete(record)
        db.commit()
        return {
            "ok":      True,
            "deleted": True,
            "message": f"Embedding deleted for {user_id}",
        }
    except Exception as e:
        db.rollback()
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        db.close()
```

Uvicorn auto-reloads. Verify at `http://127.0.0.1:8001/docs` — you will see DELETE `/delete/{user_id}` appear.

---

## 3. Add Delete Button to Admin Students View

In `resources/views/admin/students/index.blade.php` (or wherever your student list is), add a delete button/form next to each student:

```blade
<form method="POST"
      action="{{ route('admin.students.destroy', $student) }}"
      onsubmit="return confirm('Delete {{ $student->full_name }} ({{ $student->student_id }})? This removes ALL attendance records and face data permanently.')">
    @csrf
    @method('DELETE')
    <button type="submit"
            class="btn btn-danger"
            style="padding:3px 10px; font-size:12px;">
        Delete
    </button>
</form>
```

---

## What Gets Deleted When Admin Clicks Delete

```
Admin clicks Delete Student
         │
         ▼
AdminStudentController::destroy()
         │
         ├── FastAPI DELETE /delete/{student_id}
         │   └── face_encodings row deleted
         │
         ├── Storage::delete(photo file)
         │   └── students/STU-xxx.jpg removed from disk
         │
         ├── attendanceLogs()->delete()
         │   └── all check-in/check-out records gone
         │
         └── student->delete()
             └── students table row deleted
                 (cascade also removes any FK relations)

Student can now fresh register with clean slate
```

---

## Also Add `FaceVerificationController::deleteFromMl()`

So you can also trigger ML deletion independently if needed:

```php
public function deleteFromMl(Request $request): JsonResponse
{
    $request->validate([
        'student_id' => 'required|string',
    ]);

    try {
        $response = Http::timeout(10)
            ->delete("{$this->mlServiceUrl}/delete/{$request->student_id}");

        return response()->json([
            'ok'      => $response->successful(),
            'message' => $response->json('message') ?? $response->body(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'ok'      => false,
            'message' => $e->getMessage(),
        ], 503);
    }
}
```

Add route:
```php
Route::delete('/api/delete-face-ml/{studentId}', [\App\Http\Controllers\FaceVerificationController::class, 'deleteFromMl']);
```

---

After all changes:
```bash
php artisan route:clear
php artisan config:clear
```

Now the flow for fresh registration is:
```
Admin deletes student
→ face_encodings row deleted
→ photo deleted
→ attendance logs deleted
→ student row deleted

Student comes back
→ registers fresh via register form
→ new student_id generated
→ new photo taken
→ new face embedding stored in FastAPI
→ clean start
```
