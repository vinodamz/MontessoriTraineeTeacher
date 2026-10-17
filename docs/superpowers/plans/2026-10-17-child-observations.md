# Child Observations Implementation Plan

Goal: quick assessment anytime plus a faithful child learning history.
Architecture: additive SQL migration, shared observation service and report partial, existing PHP auth and layout.
Global constraints: preserve monthly and baseline data; append-only observations; assigned teachers/admin only; CSRF; historical snapshots; no production test records.

- [ ] Add idempotent migration and observation helpers with strict validation and tests for invalid dates, skill ownership, ratings and chronological history.
- [ ] Add observation form with child/date/skill rating/evidence/next steps; blank initial ratings; transactional append and duplicate submit protection.
- [ ] Integrate roster primary action, explicit current-month assessment and dated skill history into progress and report renderer including print/share/PDF.
- [ ] Run PHP tests/lint, review authorization and legacy-only/observation-only report behavior, locally verify UI; commit implementation for review.
