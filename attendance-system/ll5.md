The Idea
text
Current:  [Open Camera] → [Capture] → Done (1 photo)
New:      [Open Camera] → [Capture Photo 1] → [Capture Photo 2] → Done (2 photos)
Student takes two slightly different photos (turn head slightly, or just capture twice). Python averages them = more robust embedding.

Changes Needed (Minimal)
Only register-student.blade.php Changes
Add a second capture step. After first capture, instead of enabling submit, show "Capture Photo 2" button.

The Flow
text
1. [Open Camera] → Camera starts
2. [Capture Photo 1] → Freeze frame, show preview
3. [Capture Photo 2] → Live camera again, capture second frame
4. Both previews shown side by side
5. [Register Myself] → Submit both as base64
