# UX Simplification Roadmap — Little Graduates

> Status: **Phase 0 in progress** · Last updated: 2026-06-10
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

- [ ] **Group the home screen.** Replace the 12-tile wall with 4 sections:
      **Children** (Students, Assessment, Logbook), **Admissions** (CRM),
      **Money** (Fees, Expenses), **School Ops** (Staff, Tasks, Inventory,
      Recruitment, WACRM, n8n). Same tiles, grouped + collapsible.
- [ ] **Role-based landing.** Teachers land on Students/attendance (later: My
      Day), not the module picker. Admin keeps the grouped picker.
- [ ] **Trim the Students toolbar 9 → 4.** Keep: `Mark attendance`,
      `+ Add child` (single button), `Search`. Move Grid editor / Import /
      Export / Year-end / Withdrawals / Fees report under one `⋯ More` menu.
- [ ] **Merge the two "add a child" doors.** `+ New student` and `+ New
      admission (parent form)` become one `+ Add child` page with two clearly
      explained choices: "Office fills the details" / "Send form to parent".
- [ ] **Rename jargon across nav/buttons.** Grid editor → Edit all ·
      Year-end → Promote classes · Intake pending → Waiting for parent ·
      Baseline → First assessment.
- [ ] **Module usage audit (decision, not code).** Owner confirms which of
      Recruitment / Inventory / Tasks / WACRM / n8n are actively used.
      Unused → hidden from non-admins (module flags already support this —
      it's configuration, not code).

## Phase 1 — Teacher "My Day" (1 PR)

One page that covers 90% of a teacher's interactions.

- [ ] New `/today.php` (teacher landing): self check-in card (exists),
      my-class attendance marking inline (reuse `students/attendance.php`
      logic), pending-assessment count for the month, quick logbook entry
      (incident/observation), today's birthdays.
- [ ] Teachers' nav reduces to: **My Day · My Class · Menu**.
- [ ] Mobile-first layout — this page will live on phones.

## Phase 2 — One child, one record (1–2 PRs)

- [ ] **Tabbed student profile.** `students/view.php` becomes the hub:
      Profile / Family / Documents / Attendance / Fees / Learning.
      The tabs reuse the existing pages' queries — this is reorganization,
      not a rewrite.
- [ ] **Learning tab absorbs Assessment** for per-child views (progress,
      baseline, monthly cards). The Assessment module stays for bulk teacher
      workflows; per-child entry points move to the profile.
- [ ] **Fees tab unifies** `students/fees.php` + relevant Fees-module data
      for that child. One place to answer "has this family paid?"
- [ ] Kill orphan entry points after the move (redirects, not deletions).

## Phase 3 — One admissions funnel (1–2 PRs)

- [ ] **Single flow:** Inquiry (CRM card) → Tour → `Send admission form`
      (button on the CRM family view that calls the existing intake +
      token machinery) → Parent fills → `Approve` → Enrolled with
      added-date. The intake dropdown (PR #69) was the bridge; this makes
      it the only path and retires CRM's separate promote-to-student form.
- [ ] **CRM page diet: 26 → ~8 core.** Keep pipeline, family view, leads,
      calendar/today, WA templates, stages admin. Fold funnel/attention/
      audit into tabs or admin-only links. Nothing deleted — de-emphasized.
- [ ] Pipeline board: mobile stage-picker already exists; verify the whole
      funnel works one-handed on a phone.

## Phase 4 — Money in one place (1 PR)

- [ ] **"Money" home** combining Fees (in) and Expenses (out): this month's
      collections, dues list, pending expense approvals.
- [ ] **Parent-facing receipt** via the token-link pattern (like the
      admission PDF): payment confirmation the office can WhatsApp.
- [ ] Retire `fees_calculator.php` at root if redundant with the Fees module
      (redirect).

## Phase 5 — Guardrails & polish (1 PR + ongoing)

- [ ] **Auto-migrate on deploy.** `.cpanel.yml` runs the idempotent
      migrations after rsync — no more manual `/migrate.php` step (we hit
      two production 500s in one week from this).
- [ ] **Friendly error page.** Catch fatals → branded "Something went wrong,
      the school has been notified" instead of raw HTTP 500, especially on
      parent-facing pages.
- [ ] **Mobile audit** of the surviving staff pages.
- [ ] **Per-deploy smoke test** extended to hit the parent form + admission
      PDF with a known test token.

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
