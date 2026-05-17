# Montessori Trainee Teacher Assessment

Curriculum-indicator assessment portal for Little Graduates trainee teachers.
Teachers rate students against grade-scoped curriculum indicators each month;
admins manage teachers, students, and indicators.

Originally generated with [bolt.new](https://bolt.new) as a Vite/React/Supabase
SPA — converted to PHP+MySQL to match the LGTaskManager hosting model. The
original React source is preserved on the [`react-legacy`](https://github.com/vinodamz/MontessoriTraineeTeacher/tree/react-legacy)
branch.

Live: [mtt.thelittlegraduates.in](https://mtt.thelittlegraduates.in/).

## Tech

| | |
|---|---|
| Language | PHP 7.4+ / 8.x                                                       |
| Database | MySQL (InnoDB, utf8mb4)                                              |
| Frontend | Server-rendered HTML + a single `style.css` + minimal JS (no build) |
| Hosting  | Hostgator cPanel shared hosting                                      |
| CI/CD    | GitHub Actions → cPanel UAPI git-pull → `.cpanel.yml` rsync         |

## Features

- **PIN-only login** — 4–6 digit numeric PIN, bcrypt-hashed, rate-limited.
- **Profile-card landing** with numpad PIN modal.
- **Per-student dashboard** with last-assessment status and baseline pill.
- **Monthly assessment** form: D/P/N rating per indicator, per-category & overall comments. Pre-fills if a prior assessment exists.
- **Student progress** page: baseline, monthly summary table, SVG trend chart, all teacher notes. Print-friendly.
- **Entry baseline** form (one per student).
- **Admin console**: CRUD for teachers, students, curriculum indicators (with safe-delete that refuses if past assessments reference the indicator), and the D/P/N rating scheme.
- **Per-student custom indicators** — teachers add ad-hoc indicators that appear only in that student's monthly assessments.
- Pre-seeded curriculum indicators for Playgroup / Nursery / LKG / UKG.

## File layout

```
MontessoriTraineeTeacher/
├── index.php           # Teacher dashboard (student grid)
├── login.php           # PIN landing + AJAX endpoint
├── logout.php
├── assess.php          # Monthly assessment entry
├── progress.php        # Historical view + chart + print
├── baseline.php        # Entry baseline editor
├── admin.php           # Admin console (?tab=teachers|students|indicators|rating)
├── custom_indicators.php # Per-student custom indicators (teacher- or admin-managed)
├── install.php         # One-time first-admin bootstrap (delete after)
├── .htaccess           # Protects /includes, /sql; security headers
├── .cpanel.yml         # rsync recipe used by cPanel deploy
├── includes/
│   ├── config.example.php  # Copy to config.php and edit
│   ├── db.php              # PDO wrapper
│   ├── auth.php            # Session + PIN auth helpers
│   ├── functions.php       # View + domain helpers
│   ├── header.php
│   └── footer.php
├── assets/
│   ├── css/style.css
│   ├── js/{login.js,assess.js}
│   └── img/logo.png
└── sql/
    ├── schema.sql      # Tables (DROPs first; destructive on existing data)
    └── seeds.sql       # rating_config + PG/Nur/LKG/UKG indicators
```

## Local development

Requires PHP 7.4+ and MySQL.

```bash
# 1. Create DB and apply schema
mysql -u root -p -e "CREATE DATABASE mtt_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p mtt_dev < sql/schema.sql
mysql -u root -p mtt_dev < sql/seeds.sql

# 2. Local config
cp includes/config.example.php includes/config.php
# edit the db credentials inside includes/config.php

# 3. Built-in PHP server
php -S 127.0.0.1:8000

# 4. http://127.0.0.1:8000/install.php → create first admin → delete install.php
```

## Deploying

See [CICD.md](CICD.md) for one-time cPanel + GitHub setup and the deploy flow.

## Auth model

- All staff are rows in `teachers`. `role` is either `teacher` or `admin`.
- PINs are bcrypt-hashed in `pin_hash`. The plaintext PIN never persists.
- Sessions store `teacher_id` / `teacher_name` / `teacher_role`.
- `current_user()` / `require_login()` / `require_admin()` enforce access.

## Roadmap

- CSV / PDF report export from the Progress page (currently uses browser print).
- Bulk import of students from CSV.
- Admin-level cross-cutting views of comments and baselines.
