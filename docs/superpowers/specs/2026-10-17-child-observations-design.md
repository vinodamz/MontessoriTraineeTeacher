# Child observation history

Approved direction: teachers record a child's observed skills whenever needed, and reports retain progress history. Existing baseline and monthly records remain intact.

Add an append-only dated observation form per child, allowing one or more actually observed skills, a configured rating per skill, evidence notes and next steps. Do not preselect old ratings. Use existing assigned-teacher/admin authorization and CSRF protection. Store skill/category/rating labels and numeric values as snapshots to preserve history if configuration changes. Reject future/invalid dates, foreign indicators, invalid ratings and empty submissions. Use an idempotency token to prevent retry duplicates.

Make Record observation the primary roster action. Keep explicit Monthly assessment and Progress/history links. Monthly assessment links choose current month. Add a shared history section to progress and detailed reports including printable/PDF/shared report paths through the existing report renderer. Show dated observations, authors, evidence and next steps and per-skill earlier/latest recorded levels. Monthly entries must be labelled monthly (no invented observation dates), and historical averages kept separate from observation records. Do not infer progress from an ordinal comparison across changed rating scales or grade changes.

Verify meaningful validation/history tests, PHP lint, authorized access and rendering using local fixtures. Do not create production child observations during verification.
