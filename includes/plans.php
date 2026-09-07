<?php
/**
 * includes/plans.php — Weekly Plans helpers.
 *
 * One plan per teacher per ISO week (Mon–Sat, Asia/Kolkata). Teachers fill
 * per-day activities + materials, a principal note (with photo/voice), and
 * submit for admin review. Completing submit ticks the matching weekly_plan duty.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const PLAN_STATUSES = ['draft', 'submitted', 'approved', 'changes_requested'];
const PLAN_EDITABLE_STATUSES = ['draft', 'changes_requested'];
const PLAN_REVIEW_ACTIONS = ['comment', 'approved', 'changes_requested'];
const PLAN_DUTY_ACTION = 'weekly_plan';

const PLAN_MEDIA_MAX_BYTES = 40 * 1024 * 1024;
const PLAN_MEDIA_MIME_ALLOW = [
    'image/jpeg' => ['photo', 'jpg'],
    'image/png'  => ['photo', 'png'],
    'image/webp' => ['photo', 'webp'],
    'image/gif'  => ['photo', 'gif'],
    'image/heic' => ['photo', 'heic'],
    'audio/webm' => ['audio', 'weba'],
    'audio/ogg'  => ['audio', 'ogg'],
    'audio/mp4'  => ['audio', 'm4a'],
    'audio/mpeg' => ['audio', 'mp3'],
    'audio/wav'  => ['audio', 'wav'],
    'audio/x-m4a'=> ['audio', 'm4a'],
    'audio/x-wav'=> ['audio', 'wav'],
];

/** True if this login may use Weekly Plans (module grant or weekly_plan duty). */
function plan_can_access(array $user): bool
{
    if (user_has_module($user, 'plans')) return true;
    require_once __DIR__ . '/duties.php';
    return duty_user_has_action((int)($user['id'] ?? 0), PLAN_DUTY_ACTION);
}

function plan_require(): array
{
    $u = require_login();
    if (plan_can_access($u)) return $u;
    http_response_code(403);
    echo 'Forbidden — Weekly Plans is not assigned to you.';
    exit;
}

function plan_tables_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM weekly_plans LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function plan_status_label(string $status): string
{
    return [
        'draft'             => 'Draft',
        'submitted'         => 'Submitted',
        'approved'          => 'Approved',
        'changes_requested' => 'Changes requested',
    ][$status] ?? $status;
}

/** Current ISO week key, e.g. 2026-W37. */
function plan_current_week_key(?DateTimeInterface $when = null): string
{
    $d = $when ? DateTimeImmutable::createFromInterface($when) : new DateTimeImmutable('now');
    return $d->format('o-\WW');
}

/** Validate and normalise a week key; throws InvalidArgumentException. */
function plan_parse_week_key(string $weekKey): string
{
    $weekKey = trim($weekKey);
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m)) {
        throw new InvalidArgumentException('Week must look like 2026-W36.');
    }
    $year = (int)$m[1];
    $week = (int)$m[2];
    if ($week < 1 || $week > 53) {
        throw new InvalidArgumentException('Invalid ISO week number.');
    }
    $mon = (new DateTimeImmutable('now'))->setISODate($year, $week, 1);
    if ((int)$mon->format('o') !== $year || (int)$mon->format('W') !== $week) {
        throw new InvalidArgumentException('Invalid ISO week.');
    }
    return sprintf('%04d-W%02d', $year, $week);
}

/**
 * Mon–Sat dates for an ISO week key.
 * @return list<array{date:string,label:string,weekday:string}>
 */
function plan_week_days(string $weekKey): array
{
    $weekKey = plan_parse_week_key($weekKey);
    preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m);
    $mon = (new DateTimeImmutable('now'))->setISODate((int)$m[1], (int)$m[2], 1);
    $out = [];
    for ($i = 0; $i < 6; $i++) {
        $d = $mon->modify('+' . $i . ' day');
        $out[] = [
            'date'    => $d->format('Y-m-d'),
            'label'   => $d->format('l, j M'),
            'weekday' => $d->format('D'),
        ];
    }
    return $out;
}

function plan_week_label(string $weekKey): string
{
    $days = plan_week_days($weekKey);
    $first = $days[0]['date'] ?? '';
    $last  = $days[5]['date'] ?? '';
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $first);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $last);
    if (!$a || !$b) return $weekKey;
    return $a->format('j M') . ' – ' . $b->format('j M Y') . ' (' . $weekKey . ')';
}

function plan_shift_week(string $weekKey, int $deltaWeeks): string
{
    $weekKey = plan_parse_week_key($weekKey);
    preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m);
    $mon = (new DateTimeImmutable('now'))->setISODate((int)$m[1], (int)$m[2], 1);
    $shifted = $mon->modify(($deltaWeeks >= 0 ? '+' : '') . $deltaWeeks . ' weeks');
    return $shifted->format('o-\WW');
}

/** Distinct grades for this teacher's active enrolled students. */
function plan_default_class_grade(int $teacherId): string
{
    if ($teacherId <= 0) return '';
    try {
        $st = db()->prepare("
            SELECT DISTINCT grade FROM students
             WHERE teacher_id = :t
               AND COALESCE(is_active, 1) = 1
               AND COALESCE(enrollment_status, 'enrolled') IN ('enrolled','promoted')
               AND grade IS NOT NULL AND grade <> ''
             ORDER BY grade
        ");
        $st->execute([':t' => $teacherId]);
        $grades = [];
        foreach ($st as $r) $grades[] = (string)$r['grade'];
        return implode(', ', $grades);
    } catch (Throwable $e) {
        return '';
    }
}

/** Active admin users for the “addressed to” picker. */
function plan_admin_options(): array
{
    $st = db()->query("
        SELECT id, name FROM users
         WHERE role = 'admin' AND active = 1
         ORDER BY name
    ");
    return $st->fetchAll();
}

function plan_get(int $id): ?array
{
    if ($id <= 0) return null;
    $st = db()->prepare("
        SELECT p.*,
               t.name AS teacher_name,
               a.name AS addressed_to_name,
               r.name AS reviewed_by_name
          FROM weekly_plans p
          JOIN users t ON t.id = p.teacher_id
          LEFT JOIN users a ON a.id = p.addressed_to_user_id
          LEFT JOIN users r ON r.id = p.reviewed_by_user_id
         WHERE p.id = :id
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

function plan_get_by_teacher_week(int $teacherId, string $weekKey): ?array
{
    $weekKey = plan_parse_week_key($weekKey);
    $st = db()->prepare("
        SELECT p.*,
               t.name AS teacher_name,
               a.name AS addressed_to_name,
               r.name AS reviewed_by_name
          FROM weekly_plans p
          JOIN users t ON t.id = p.teacher_id
          LEFT JOIN users a ON a.id = p.addressed_to_user_id
          LEFT JOIN users r ON r.id = p.reviewed_by_user_id
         WHERE p.teacher_id = :t AND p.week_key = :w
    ");
    $st->execute([':t' => $teacherId, ':w' => $weekKey]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Fetch or create the teacher's plan for $weekKey (with empty Mon–Sat day rows).
 */
function plan_ensure(int $teacherId, string $weekKey): array
{
    $weekKey = plan_parse_week_key($weekKey);
    $existing = plan_get_by_teacher_week($teacherId, $weekKey);
    if ($existing) {
        plan_ensure_days((int)$existing['id'], $weekKey);
        return plan_get((int)$existing['id']) ?? $existing;
    }
    $grade = plan_default_class_grade($teacherId);
    db()->prepare("
        INSERT INTO weekly_plans (teacher_id, week_key, class_grade, status)
        VALUES (:t, :w, :g, 'draft')
    ")->execute([':t' => $teacherId, ':w' => $weekKey, ':g' => $grade]);
    $id = (int)db()->lastInsertId();
    plan_ensure_days($id, $weekKey);
    $plan = plan_get($id);
    if (!$plan) throw new RuntimeException('Could not create weekly plan.');
    return $plan;
}

function plan_ensure_days(int $planId, string $weekKey): void
{
    $ins = db()->prepare("
        INSERT IGNORE INTO plan_days (plan_id, day_date, activities, materials)
        VALUES (:p, :d, '', '')
    ");
    foreach (plan_week_days($weekKey) as $day) {
        $ins->execute([':p' => $planId, ':d' => $day['date']]);
    }
}

/** @return list<array> day rows ordered by date */
function plan_days(int $planId): array
{
    $st = db()->prepare("SELECT * FROM plan_days WHERE plan_id = :p ORDER BY day_date");
    $st->execute([':p' => $planId]);
    return $st->fetchAll();
}

function plan_media_list(int $planId): array
{
    $st = db()->prepare("SELECT * FROM plan_media WHERE plan_id = :p ORDER BY uploaded_at, id");
    $st->execute([':p' => $planId]);
    return $st->fetchAll();
}

function plan_reviews(int $planId): array
{
    $st = db()->prepare("
        SELECT r.*, u.name AS reviewer_name
          FROM plan_reviews r
          LEFT JOIN users u ON u.id = r.reviewer_user_id
         WHERE r.plan_id = :p
         ORDER BY r.created_at, r.id
    ");
    $st->execute([':p' => $planId]);
    return $st->fetchAll();
}

function plan_is_editable(array $plan): bool
{
    return in_array((string)$plan['status'], PLAN_EDITABLE_STATUSES, true);
}

function plan_can_view(array $user, array $plan): bool
{
    if (($user['role'] ?? '') === 'admin') return true;
    return (int)$plan['teacher_id'] === (int)$user['id'];
}

function plan_can_edit(array $user, array $plan): bool
{
    if (!plan_is_editable($plan)) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    return (int)$plan['teacher_id'] === (int)$user['id'];
}

function plan_can_review(array $user, array $plan): bool
{
    if (($user['role'] ?? '') !== 'admin') return false;
    return in_array((string)$plan['status'], ['submitted', 'approved', 'changes_requested'], true);
}

function plan_log_review(int $planId, int $userId, string $action, string $body = ''): void
{
    if (!in_array($action, PLAN_REVIEW_ACTIONS, true)) {
        throw new InvalidArgumentException('Unknown review action.');
    }
    db()->prepare("
        INSERT INTO plan_reviews (plan_id, reviewer_user_id, action, body)
        VALUES (:p, :u, :a, :b)
    ")->execute([
        ':p' => $planId,
        ':u' => $userId > 0 ? $userId : null,
        ':a' => $action,
        ':b' => $body !== '' ? $body : null,
    ]);
}

/**
 * Save draft fields. $days is [day_date => ['activities'=>…,'materials'=>…]].
 * Throws if not editable.
 */
function plan_save_draft(int $planId, array $user, array $fields, array $days): void
{
    $plan = plan_get($planId);
    if (!$plan) throw new InvalidArgumentException('Plan not found.');
    if (!plan_can_edit($user, $plan)) {
        throw new InvalidArgumentException('This plan is locked and cannot be edited.');
    }

    $note = trim((string)($fields['note_to_principal'] ?? $plan['note_to_principal'] ?? ''));
    $weeklyMats = trim((string)($fields['weekly_materials'] ?? $plan['weekly_materials'] ?? ''));
    $classGrade = trim((string)($fields['class_grade'] ?? $plan['class_grade'] ?? ''));
    $addressed = (int)($fields['addressed_to_user_id'] ?? $plan['addressed_to_user_id'] ?? 0);
    if ($addressed > 0) {
        $chk = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'admin' AND active = 1");
        $chk->execute([':id' => $addressed]);
        if (!$chk->fetchColumn()) {
            throw new InvalidArgumentException('Choose a valid admin to address the note to.');
        }
    } else {
        $addressed = 0;
    }

    $allowedDates = [];
    foreach (plan_week_days((string)$plan['week_key']) as $d) {
        $allowedDates[$d['date']] = true;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE weekly_plans
               SET class_grade = :g,
                   note_to_principal = :n,
                   weekly_materials = :wm,
                   addressed_to_user_id = :a
             WHERE id = :id
        ")->execute([
            ':g'  => mb_substr($classGrade, 0, 120),
            ':n'  => $note !== '' ? $note : null,
            ':wm' => $weeklyMats !== '' ? $weeklyMats : null,
            ':a'  => $addressed > 0 ? $addressed : null,
            ':id' => $planId,
        ]);

        $upd = $pdo->prepare("
            UPDATE plan_days SET activities = :act, materials = :mat
             WHERE plan_id = :p AND day_date = :d
        ");
        foreach ($days as $date => $row) {
            $date = (string)$date;
            if (!isset($allowedDates[$date])) continue;
            $upd->execute([
                ':act' => trim((string)($row['activities'] ?? '')),
                ':mat' => trim((string)($row['materials'] ?? '')),
                ':p'   => $planId,
                ':d'   => $date,
            ]);
        }

        // Auto-aggregate day materials into weekly summary when teacher left it blank
        // but days have materials — keep any manual weekly text as authoritative.
        if ($weeklyMats === '') {
            $agg = plan_aggregate_day_materials($planId);
            if ($agg !== '') {
                $pdo->prepare("UPDATE weekly_plans SET weekly_materials = :wm WHERE id = :id")
                    ->execute([':wm' => $agg, ':id' => $planId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function plan_aggregate_day_materials(int $planId): string
{
    $lines = [];
    foreach (plan_days($planId) as $day) {
        $mat = trim((string)($day['materials'] ?? ''));
        if ($mat === '') continue;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$day['day_date']);
        $label = $d ? $d->format('D') : (string)$day['day_date'];
        $lines[] = $label . ': ' . $mat;
    }
    return implode("\n", $lines);
}

/** Submit for review; locks teacher edits and marks the weekly_plan duty done. */
function plan_submit(int $planId, array $user): void
{
    $plan = plan_get($planId);
    if (!$plan) throw new InvalidArgumentException('Plan not found.');
    if (!plan_can_edit($user, $plan)) {
        throw new InvalidArgumentException('Only a draft or changes-requested plan can be submitted.');
    }
    if ((int)$plan['teacher_id'] !== (int)$user['id'] && ($user['role'] ?? '') !== 'admin') {
        throw new InvalidArgumentException('You can only submit your own plan.');
    }

    // Require at least some day content or a principal note.
    $hasContent = trim((string)($plan['note_to_principal'] ?? '')) !== '';
    if (!$hasContent) {
        foreach (plan_days($planId) as $day) {
            if (trim((string)$day['activities']) !== '' || trim((string)$day['materials']) !== '') {
                $hasContent = true;
                break;
            }
        }
    }
    if (!$hasContent) {
        throw new InvalidArgumentException('Add at least one day’s activities or a note before submitting.');
    }

    // Refresh weekly materials aggregate if empty.
    if (trim((string)($plan['weekly_materials'] ?? '')) === '') {
        $agg = plan_aggregate_day_materials($planId);
        if ($agg !== '') {
            db()->prepare("UPDATE weekly_plans SET weekly_materials = :wm WHERE id = :id")
                ->execute([':wm' => $agg, ':id' => $planId]);
        }
    }

    db()->prepare("
        UPDATE weekly_plans
           SET status = 'submitted',
               submitted_at = NOW(),
               reviewed_by_user_id = NULL,
               reviewed_at = NULL
         WHERE id = :id
    ")->execute([':id' => $planId]);

    plan_log_review($planId, (int)$user['id'], 'comment', 'Submitted for review.');
    plan_sync_duties((int)$plan['teacher_id'], (string)$plan['week_key']);

    // Notify addressed admin (or all admins).
    try {
        require_once __DIR__ . '/notify.php';
        $link = '/plans/view.php?id=' . $planId;
        $title = 'Weekly plan submitted';
        $body  = ((string)($plan['teacher_name'] ?? 'A teacher')) . ' submitted their plan for '
               . plan_week_label((string)$plan['week_key']) . '.';
        $to = (int)($plan['addressed_to_user_id'] ?? 0);
        if ($to > 0) {
            notify($to, 'staff', 'weekly_plan_submitted', $title, $body, $link, false);
        } else {
            $admins = plan_admin_options();
            $ids = array_map(static fn($a) => (int)$a['id'], $admins);
            if ($ids) notify($ids, 'staff', 'weekly_plan_submitted', $title, $body, $link, false);
        }
    } catch (Throwable $e) { /* best-effort */ }
}

function plan_review(int $planId, array $admin, string $action, string $body = ''): void
{
    if (($admin['role'] ?? '') !== 'admin') {
        throw new InvalidArgumentException('Only admins can review plans.');
    }
    if (!in_array($action, PLAN_REVIEW_ACTIONS, true)) {
        throw new InvalidArgumentException('Unknown review action.');
    }
    $plan = plan_get($planId);
    if (!$plan) throw new InvalidArgumentException('Plan not found.');

    $status = (string)$plan['status'];
    if ($action === 'comment') {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Comment cannot be empty.');
        }
        plan_log_review($planId, (int)$admin['id'], 'comment', trim($body));
        return;
    }

    if ($status !== 'submitted') {
        throw new InvalidArgumentException('Plan must be submitted before approve / request changes.');
    }

    if ($action === 'approved') {
        db()->prepare("
            UPDATE weekly_plans
               SET status = 'approved',
                   reviewed_by_user_id = :u,
                   reviewed_at = NOW()
             WHERE id = :id
        ")->execute([':u' => (int)$admin['id'], ':id' => $planId]);
        plan_log_review($planId, (int)$admin['id'], 'approved', trim($body));
    } elseif ($action === 'changes_requested') {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Please explain what needs changing.');
        }
        db()->prepare("
            UPDATE weekly_plans
               SET status = 'changes_requested',
                   reviewed_by_user_id = :u,
                   reviewed_at = NOW()
             WHERE id = :id
        ")->execute([':u' => (int)$admin['id'], ':id' => $planId]);
        plan_log_review($planId, (int)$admin['id'], 'changes_requested', trim($body));
    }

    try {
        require_once __DIR__ . '/notify.php';
        $link = '/plans/view.php?id=' . $planId;
        if ($action === 'approved') {
            notify((int)$plan['teacher_id'], 'staff', 'weekly_plan_approved',
                'Weekly plan approved',
                'Your plan for ' . plan_week_label((string)$plan['week_key']) . ' was approved.',
                $link, false);
        } elseif ($action === 'changes_requested') {
            notify((int)$plan['teacher_id'], 'staff', 'weekly_plan_changes',
                'Weekly plan needs changes',
                trim($body),
                '/plans/edit.php?week=' . rawurlencode((string)$plan['week_key']),
                false);
        }
    } catch (Throwable $e) { /* best-effort */ }
}

/** Mark matching weekly_plan duty items done for this teacher/week. */
function plan_sync_duties(int $userId, string $weekKey): void
{
    require_once __DIR__ . '/duties.php';
    if (!duty_tables_ready()) return;
    try {
        $weekKey = plan_parse_week_key($weekKey);
        $st = db()->prepare("
            UPDATE staff_duty_items i
            JOIN staff_duty_templates t ON t.id = i.template_id
               SET i.status = 'done', i.completed_at = COALESCE(i.completed_at, NOW())
             WHERE i.user_id = :u
               AND i.status = 'pending'
               AND t.action_key = :a
               AND i.frequency = 'weekly'
               AND i.period_key = :w
        ");
        $st->execute([
            ':u' => $userId,
            ':a' => PLAN_DUTY_ACTION,
            ':w' => $weekKey,
        ]);
    } catch (Throwable $e) { /* action_key / tables may lag */ }
}

// ---------- Media -----------------------------------------------------------

function plan_media_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/plan_media';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function plan_media_accept(array $file, int $planId): ?array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > PLAN_MEDIA_MAX_BYTES) {
        throw new RuntimeException('File too large — max ' . format_bytes(PLAN_MEDIA_MAX_BYTES) . '.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!isset(PLAN_MEDIA_MIME_ALLOW[$mime])) {
        throw new RuntimeException('Only photos (JPG/PNG/WebP/HEIC) or voice memos (WebM/M4A/MP3/WAV) are allowed.');
    }
    [$kind, $ext] = PLAN_MEDIA_MIME_ALLOW[$mime];
    $stored = 'plan_' . $planId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest   = plan_media_dir() . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not move the uploaded file.');
    }
    @chmod($dest, 0644);
    return [
        'kind'   => $kind,
        'orig'   => mb_substr((string)$file['name'], 0, 255),
        'stored' => $stored,
        'mime'   => $mime,
        'size'   => (int)$file['size'],
    ];
}

function plan_media_store(array $file, int $planId, int $userId): ?int
{
    $acc = plan_media_accept($file, $planId);
    if ($acc === null) return null;
    db()->prepare("
        INSERT INTO plan_media
            (plan_id, kind, original_filename, stored_filename, mime_type, size_bytes, uploaded_by_user_id)
        VALUES (:p, :k, :o, :s, :m, :sz, :u)
    ")->execute([
        ':p' => $planId, ':k' => $acc['kind'],
        ':o' => $acc['orig'], ':s' => $acc['stored'], ':m' => $acc['mime'],
        ':sz' => $acc['size'], ':u' => $userId,
    ]);
    return (int)db()->lastInsertId();
}

function plan_media_get(int $mediaId): ?array
{
    if ($mediaId <= 0) return null;
    $st = db()->prepare("SELECT * FROM plan_media WHERE id = :id");
    $st->execute([':id' => $mediaId]);
    $row = $st->fetch();
    return $row ?: null;
}

function plan_media_delete(int $mediaId, array $user): void
{
    $row = plan_media_get($mediaId);
    if (!$row) throw new InvalidArgumentException('Attachment not found.');
    $plan = plan_get((int)$row['plan_id']);
    if (!$plan || !plan_can_edit($user, $plan)) {
        throw new InvalidArgumentException('Cannot remove this attachment.');
    }
    $path = plan_media_dir() . '/' . basename((string)$row['stored_filename']);
    db()->prepare("DELETE FROM plan_media WHERE id = :id")->execute([':id' => $mediaId]);
    if (is_file($path)) @unlink($path);
}

/**
 * Teacher: their recent plans. Admin: all teachers for a week (+ who hasn't submitted).
 * @return list<array>
 */
function plan_list_for_teacher(int $teacherId, int $limit = 16): array
{
    $st = db()->prepare("
        SELECT p.*, t.name AS teacher_name
          FROM weekly_plans p
          JOIN users t ON t.id = p.teacher_id
         WHERE p.teacher_id = :t
         ORDER BY p.week_key DESC
         LIMIT " . (int)$limit
    );
    $st->execute([':t' => $teacherId]);
    return $st->fetchAll();
}

/**
 * Admin week board: every active teacher with their plan (or null) for $weekKey.
 * @return list<array{teacher_id:int,teacher_name:string,plan:?array}>
 */
function plan_admin_week_board(string $weekKey): array
{
    $weekKey = plan_parse_week_key($weekKey);
    $teachers = db()->query("
        SELECT id, name FROM users
         WHERE role = 'teacher' AND active = 1
         ORDER BY name
    ")->fetchAll();

    $st = db()->prepare("SELECT * FROM weekly_plans WHERE week_key = :w");
    $st->execute([':w' => $weekKey]);
    $byTeacher = [];
    foreach ($st as $r) $byTeacher[(int)$r['teacher_id']] = $r;

    $out = [];
    foreach ($teachers as $t) {
        $tid = (int)$t['id'];
        $out[] = [
            'teacher_id'   => $tid,
            'teacher_name' => (string)$t['name'],
            'plan'         => $byTeacher[$tid] ?? null,
        ];
    }
    return $out;
}
