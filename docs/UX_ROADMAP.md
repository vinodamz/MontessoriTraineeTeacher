# UX Simplification Roadmap — Little Graduates

> Status: **All phases done** (open: module-usage decision + two manual mobile passes) · Last updated: 2026-06-10
>
> The app has grown to ~103 pages across 12 modules. For a playschool with a
> handful of staff, that is ERP-scale surface area. This roadmap reorganizes
> the experience around the three people who actually use it — **teacher,
> admin, parent** — without throwing away working features.
>
> Each phase is one PR-sized chunk. Check items off as they merge.

---

## Guiding principles

1. **Role-first, not module-first.** A teacher's day is: check in → mark
   class attendance → observe children → log incidents. That should be one
   screen, not four modules.
2. **One child = one record.** The student profile is the hub. Attendance,
   fees, documents, learning progress are tabs on it — never separate
   destinations the user must know about.
3. **One door per job.** One way to add a child. One admissions funnel.
   One fees view. Where two paths exist today, keep the better one and
   redirect the other.
4. **Plain words.** "Grid editor" → "Edit all". "Year-end" → "Promote
   classes". "Intake pending" → "Waiting for parent". Jargon costs a
   substitute teacher real minutes.
5. **Parents stay link-only.** No parent accounts, no passwords. The token
   link pattern (form → PDF) is the model for anything parent-facing.

---

## Phase 0 — Quick wins (no schema changes, ~1 PR)

Low-risk cleanups that cut visual noise immediately.

- [x] **Group the home screen.** Replace the 12-tile wall with 4 sections:
      **Children** (Students, Assessment, Logbook), **Admissions** (CRM),
      **Money** (Fees, Expenses), **School Ops** (Staff, Tasks, Inventory,
      Recruitment, WACRM, n8n). Same tiles, grouped + collapsible.
- [x] **Role-based landing.** Teachers land on Students/attendance (later: My
      Day), not the module picker. Admin keeps the grouped picker.
      (`/index.php?all=1` shows the full picker for anyone.)
- [x] **Trim the Students toolbar 9 → 4.** Keep: `Mark attendance`,
      `+ Add child` (single button), `Search`. Move Grid editor / Import /
      Export / Year-end / Withdrawals / Fees report under one `More ▾` menu.
- [x] **Merge the two "add a child" doors.** `+ New student` and `+ New
      admission (parent form)` become one `+ Add child` page
      (`students/add.php`) with two clearly explained choices:
      "Office fills the details" / "Parent fills the form".
- [x] **Rename jargon across nav/buttons.** Grid editor → Edit all ·
      Year-end → Promote classes · Intake pending → Waiting for parent ·
      Baseline → First assessment.
- [ ] **Module usage audit (decision, not code).** Owner confirms which of
      Recruitment / Inventory / Tasks / WACRM / n8n are actively used.
      Unused → hidden from non-admins (module flags already support this —
      it's configuration, not code).

## Phase 1 — Teacher "My Day" (1 PR)

One page that covers 90% of a teacher's interactions.

- [x] New `/today.php` (teacher landing): self check-in card (exists),
      my-class attendance marking inline (posts to `students/attendance.php`
      with `return_to`), pending-assessment count for the month, quick
      logbook shortcuts (observation / incident), today's birthdays.
- [x] Teachers' nav reduces to: **My Day · My Class · More ▾** (with an
      "All apps" escape hatch to `/index.php?all=1`).
- [x] Mobile-first layout — reuses the responsive attendance grid + cards.

## Phase 2 — One child, one record (1–2 PRs)

- [x] **Tabbed student profile.** A shared tab strip
      (`includes/student_tabs.php`) renders on every per-child page, so
      Profile / Documents / Attendance / Fees / Learning read as tabs of
      one record. The pages keep their own URLs + queries — reorganization,
      not a rewrite. (Family stayed on the Profile tab — view.php already
      shows parents inline, a separate tab would have been a click tax.)
- [x] **Learning tab absorbs Assessment** for per-child views: new
      `students/learning.php` shows first-assessment status, this-month
      assessed-or-not, and a recent month × category score table, with
      deep links into assess / baseline / full progress. The Assessment
      module keeps the bulk teacher workflow.
- [x] **Fees tab** = `students/fees.php` (invoices + payments + dues per
      child — already the unified per-child money view; the Fees module
      holds the fee *structure*, not per-child ledgers).
- [x] Orphan entry points removed: view.php's six-button row (Documents /
      Attendance / Fees / Progress / Baseline) replaced by the tab strip.

## Phase 3 — One admissions funnel (1–2 PRs)

- [x] **Single flow:** the CRM family view's "Enroll children" card became
      an **Admission** card with a per-child `Send admission form` button
      that deep-links into `students/intake_new.php?inquiry_child_id=N`
      (pre-selected) — draft student + parents copied + token issued in
      one step, approve from the child's profile. Direct enroll survives
      as a collapsed "office types everything" fallback inside the same
      card, no longer the headline path.
- [x] **CRM toolbar diet.** The pipeline header drops from up to 12
      buttons to **Leads · Today · More ▾ · + New inquiry**; Calendar /
      Funnel / Campaigns / Tags / Stages / Rules / WA templates / Audit /
      Import Odoo all fold into the More menu. Nothing deleted.
- [ ] Pipeline board: mobile stage-picker already exists; verify the whole
      funnel works one-handed on a phone. *(manual pass — open)*

## Phase 4 — Money in one place (1 PR)

- [x] **"Money" home** — new `/money.php` (admin / fees / expenses holders):
      this month's collections, outstanding dues per family with deep links
      into each child's Fees tab, this month's spend + pending expense
      approvals. New "Money overview" tile leads the Money group on home.
- [x] **Parent-facing receipt** — migration 030 adds
      `fee_payments.receipt_token` (backfilled); `/receipt.php?t=…` renders
      a branded, printable payment receipt with invoice balance, no login.
      The office copies the "Receipt ↗" link from the child's Fees tab and
      WhatsApps it.
- [x] ~~Retire `fees_calculator.php`~~ — **kept deliberately**: it's the
      public, no-login calculator the admissions team shares with parents
      (the Fees module is the staff side). Not redundant.

## Phase 5 — Guardrails & polish (1 PR + ongoing)

- [x] **Auto-migrate on deploy.** `.cpanel.yml` runs `php migrate.php`
      (CLI mode, idempotent) after rsync — no more manual `/migrate.php`
      step. Output is published at `/last-migrate.log` next to
      `/last-deploy.log`.
- [x] **Friendly error page.** `includes/errors.php` (loaded by auth.php,
      so every entry point gets it) catches uncaught exceptions + fatals →
      branded "Something went wrong" page; full error still goes to
      error_log.
- [ ] **Mobile audit** of the surviving staff pages. *(manual pass — open)*
- [x] **Per-deploy smoke test** extended: a malformed parent-form token must
      return the 404 "Link not active" page — a 500 fails the deploy.

---

## Sequence & effort

| Phase | Risk | Effort | Visible payoff |
|---|---|---|---|
| 0 — Quick wins | Low | ~1 day | Home + Students stop feeling overwhelming |
| 1 — My Day | Low | 1–2 days | Teachers stop touching admin features |
| 2 — One child record | Medium | 2–3 days | No more module-hopping per child |
| 3 — One funnel | Medium | 2–3 days | Admissions becomes a single story |
| 4 — Money | Low | 1–2 days | One answer to "who owes what" |
| 5 — Guardrails | Low | 1 day | No more manual migrate / raw 500s |

Recommended order: **0 → 1 → 5 → 2 → 3 → 4**. (Pull 5 forward because the
manual-migrate risk bites every deploy until fixed.)
