# pipeline/calibration — playtest ingest stub (WS-05)

Interface stub for the playtest calibration loop. v1 ships **no live
recalibration** (WS-05 non-goal; ADR-0010): solve telemetry lands here as
CSV exports, `ingest.py` summarizes per-band completion rates against the
product §7 targets (Mon ≥ 75%, Sat ~45% by design), and a human retunes the
band thresholds in `burnfront_pipeline/grader.py` + `GRADING.md`.

Rules (brief, critique #16):

- Published dates are immutable from T-48h. The pipeline cannot enforce
  this itself (it never reads the clock — determinism); the publish step
  (WS-16) refuses to overwrite any date within 48 hours.
- Re-sorting after calibration ships as a **new content_version** and may
  only change future dates.

Input format (one row per finished daily): see `ingest.py`.
