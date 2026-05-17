# Montessori Trainee Teacher

Curriculum-indicator assessment app for Little Graduates trainee teachers.
Teachers rate students against indicator skills per term; admins manage
indicators, evaluation cards, comments, and student baselines.

Originally generated with [bolt.new](https://bolt.new); now hosted at
[mtt.thelittlegraduates.in](https://mtt.thelittlegraduates.in/).

## Tech

| | |
|---|---|
| Build       | Vite 5 + TypeScript + React 18                                         |
| Styling     | Tailwind CSS, Lucide icons                                             |
| Backend     | Supabase (Postgres + Row-Level-Security + JWT auth via PIN)            |
| PDF export  | jsPDF + html2canvas (per-student progress reports)                     |
| Hosting     | Hostgator cPanel shared hosting (static SPA, no server runtime)        |
| CI/CD       | GitHub Actions → cPanel UAPI git-pull → rsync into subdomain docroot   |

## Local development

```bash
npm install
npm run dev          # http://localhost:5173
npm run lint
npm run typecheck
npm run build        # outputs dist/
npm run preview      # serves dist/ locally on http://localhost:4173
```

`.env` is committed (anon-key/URL are publishable by design — see
[CICD.md](CICD.md)).

## Database

Supabase migrations live under `supabase/migrations/`. To apply them to a fresh
Supabase project:

```bash
npx supabase link --project-ref <ref>
npx supabase db push
```

Schema summary:

- `teachers` (PIN-authenticated, `role` ∈ {`teacher`, `admin`})
- `students` (`grade` ∈ {Playgroup, Nursery, LKG, UKG}, owned by a teacher)
- `skills` + `skill_indicators` — curriculum hierarchy, grade-scoped
- `evaluation_cards` — term assessments per student per indicator
- `assessment_comments` — narrative comments per card
- `student_baselines` — initial term snapshot per student
- Pre-seeded indicators for Playgroup / Nursery / LKG / UKG curricula

## Pages

| File                                        | Purpose                                        |
|---------------------------------------------|------------------------------------------------|
| `src/pages/LoginPage.tsx`                   | PIN login (teacher or admin)                   |
| `src/pages/TeacherDashboard.tsx`            | Teacher's own students + assessments           |
| `src/pages/StudentProgress.tsx`             | Per-student progress + PDF export              |
| `src/pages/AdminDashboard.tsx`              | Admin shell with tabs:                         |
|   ↳ `components/admin/AssessmentsAdmin.tsx` | All assessments across teachers                |
|   ↳ `components/admin/EvaluationCardsAdmin` | Manage card templates                          |
|   ↳ `components/admin/CommentsAdmin.tsx`    | Manage narrative comments                      |
|   ↳ `components/admin/BaselineAdmin.tsx`    | Student baseline records                       |

## Deploying

See [CICD.md](CICD.md) for one-time cPanel + GitHub setup and the deploy flow.
