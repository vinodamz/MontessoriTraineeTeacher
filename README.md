# Little Graduates — unified school app

One PHP app with three modules under a single login:

- **Assessment** (`/assessment/...`) — trainee teacher curriculum-indicator assessment.
  Monthly D/P/N ratings, per-student baselines, progress charts, print-friendly reports.
- **Tasks** (`/tasks/...`) — team task board with kanban columns, recurring routines, and a calendar view.
- **Students** (`/students/...`) — student management: profile + parents/guardians + address/emergency
  contacts, with attendance/fees/document upload arriving in follow-on PRs.

One `users` table, one PIN login, one admin console. Each user has a role (`teacher` / `admin`) plus a per-module access set (`tasks`, `montessori`, `students`, or any combination).

Live: [mtt.thelittlegraduates.in](https://mtt.thelittlegraduates.in/).

Historical: this repo originally hosted only the Montessori assessment side (imported from a bolt.new Vite/React/Supabase scaffold, then rewritten to PHP+MySQL). The React source is preserved on the [`react-legacy`](https://github.com/vinodamz/MontessoriTraineeTeacher/tree/react-legacy) branch. The LGTaskManager codebase was merged in on 2026-05-18.

## Tech

| | |
|---|---|
| Language | PHP 8.1+                                                              |
| Database | MySQL (InnoDB, utf8mb4)                                               |
| Frontend | Server-rendered HTML + two stylesheets + minimal JS (no build)        |
| Hosting  | Hostgator cPanel shared hosting                                       |
| CI/CD    | GitHub Actions → cPanel UAPI git-pull → `.cpanel.yml` rsync          |

## File layout

```
.
├── index.php             # Unified home / module picker
├── login.php             # PIN landing + AJAX endpoint
├── logout.php
├── admin.php             # Cross-module user management
├── install.php           # First-admin bootstrap (delete after use)
├── migrate.php           # Apply sql/migrate_*.sql files (admin-only)
├── includes/
│   ├── config.example.php
│   ├── db.php, auth.php, functions.php
│   └── header.php, footer.php
├── assessment/           # Montessori assessment module
│   ├── index.php, assess.php, progress.php, baseline.php
│   ├── custom_indicators.php, admin.php
├── tasks/                # Task manager module
│   ├── index.php, tasks.php, calendar.php
│   ├── admin.php, debug-recurrences.php, reset-opcache.php
├── students/             # Student management module
│   ├── index.php (list + search), view.php (profile)
│   ├── edit.php (add/edit + parents inline)
│   ├── yearend.php (June-rollover transition tool)
│   ├── withdrawals.php (drop-out analytics)
├── assets/
│   ├── css/{style.css, tasks.css}
│   ├── js/{login.js, assess.js, kanban.js}
│   └── img/logo.png
└── sql/
    ├── schema.sql                       # Fresh-DB unified schema
    ├── migrate_001_unify_users.sql      # In-place migration for the existing MTT DB
    ├── migrate_002_student_module.sql   # Extends students + adds student_parents
    ├── migrate_006_academic_year.sql    # Academic-year + enrollment_status + withdrawal_reason
    └── seeds.sql                        # rating_config + curriculum indicators
```

## Local development

Requires PHP 8.1+ and MySQL.

```bash
# 1. Create DB and apply schema
mysql -u root -p -e "CREATE DATABASE lg_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p lg_dev < sql/schema.sql
mysql -u root -p lg_dev < sql/seeds.sql       # optional — for montessori curriculum indicators

# 2. Local config
cp includes/config.example.php includes/config.php
# edit the db credentials inside includes/config.php

# 3. Built-in PHP server (serve from the repo root)
php -S 127.0.0.1:8000

# 4. http://127.0.0.1:8000/install.php  → create first admin → delete install.php
# 5. http://127.0.0.1:8000/login.php    → sign in
```

## Migrating an existing MTT-only database

If you already have a database populated by the previous MTT-only app (which only has the `teachers` table, no `users`), don't run `schema.sql`. Instead, deploy this branch and open `/migrate.php` while signed in as an admin. The script runs `sql/migrate_001_unify_users.sql`, which is fully idempotent:

1. Creates the `users` table.
2. Copies every row from `teachers` into `users` (preserving IDs).
3. Tags admin rows with `modules='tasks,montessori'`, other rows with `modules='montessori'`.
4. Drops + re-adds every FK that pointed at `teachers(id)`, repointing them at `users(id)`.
5. Drops the legacy `teachers` table.
6. Creates the `tasks`, `task_columns`, `task_recurrences` tables for the Tasks module.

After it finishes, every existing MTT teacher can sign in with their old PIN and they automatically see the Assessment module. To give someone access to Tasks too, edit them at `/admin.php`.

## Deploying

See [CICD.md](CICD.md) for the one-time cPanel + GitHub setup. After it's wired up, pushing to `main` deploys to `mtt.thelittlegraduates.in/` automatically (lint → cPanel git-pull → `.cpanel.yml` rsync → live URL self-test).

## Auth model

- All sign-ins are PIN-based (4–6 digit numeric, bcrypt-hashed).
- The unified login page lists every active user; tap a card, enter your PIN.
- Sessions store `user_id` / `user_name` / `user_role` / `user_modules`.
- `current_user()` / `require_login()` / `require_admin()` / `require_module($name)` enforce access.
- Admins implicitly have access to every module regardless of their `modules` set.

## Roadmap

- CSV / PDF report export from the Assessment Progress page.
- Bulk import of students from CSV.
- Admin-level cross-cutting views of comments and baselines.
- Per-module landing tiles on the unified home page (currently just counts).
