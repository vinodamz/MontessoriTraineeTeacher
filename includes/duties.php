<?php
/**
 * includes/duties.php — daily / weekly / monthly staff duty checklists.
 *
 * Templates are configured by admins (or MCP). Each assigned person gets one
 * item per period. Teachers tick done / not done (reason required if not
 * done), leave a comment, and can add their own extra tasks (admins are told).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/notify.php';

const DUTY_FREQUENCIES = ['daily', 'weekly', 'monthly', 'adhoc'];
const DUTY_REPEAT_AS   = ['once', 'daily', 'weekly', 'monthly'];
const DUTY_AUDIENCES   = ['all_teachers', 'all_non_teaching', 'all_staff', 'users'];
const DUTY_STATUSES    = ['pending', 'done', 'not_done'];
const DUTY_WEEKDAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const DUTY_ACTIONS = [
    ''                 => ['label' => 'Plain tick',            'href' => ''],
    'materials_check'  => ['label' => 'Materials check sheet', 'href' => '/materials/daily.php'],
];

function duty_action_label(string $key): string
{
    return DUTY_ACTIONS[$key]['label'] ?? $key;
}

function duty_action_href(string $key): string
{
    return DUTY_ACTIONS[$key]['href'] ?? '';
}

function duty_normalize_action(string $key): string
{
    $key = trim($key);
    if ($key === '' || isset(DUTY_ACTIONS[$key])) return $key;
    throw new InvalidArgumentException('Unknown duty action.');
}

/** True if this person is on an active duty template with this action_key. */
function duty_user_has_action(int $userId, string $action): bool
{
    if ($userId <= 0 || $action === '' || !duty_tables_ready()) return false;
    try {
        foreach (duty_templates(true) as $tpl) {
            if ((string)($tpl['action_key'] ?? '') !== $action) continue;
            if (in_array($userId, duty_assignee_ids($tpl), true)) return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function duty_freq_label(string $freq): string
{
    return [
        'daily'   => 'Daily',
        'weekly'  => 'Weekly',
        'monthly' => 'Monthly',
        'adhoc'   => 'Dated',
    ][$freq] ?? $freq;
}

/** Teacher-facing group name — when to do it, not how it was configured. */
function duty_now_label(string $freq): string
{
    return [
        'daily'   => 'Today',
        'weekly'  => 'This week',
        'monthly' => 'This month',
        'adhoc'   => 'Dated',
    ][$freq] ?? $freq;
}

function duty_repeat_label(string $repeat): string
{
    return [
        'once'    => 'Once in this window',
        'daily'   => 'Each selected day',
        'weekly'  => 'Each week',
        'monthly' => 'Each month',
    ][$repeat] ?? $repeat;
}

function duty_audience_label(string $audience): string
{
    return [
        'all_teachers'      => 'All teachers',
        'all_non_teaching'  => 'All non-teaching',
        'all_staff'         => 'All staff',
        'users'             => 'Named people',
    ][$audience] ?? $audience;
}

function duty_status_label(string $status): string
{
    return ['pending' => 'Not yet', 'done' => 'Done', 'not_done' => 'Not done'][$status] ?? $status;
}

/** Period key for a frequency as of $when (default now, Asia/Kolkata via PHP tz). */
function duty_period_key(string $freq, ?DateTimeInterface $when = null): string
{
    $d = $when ? DateTimeImmutable::createFromInterface($when) : new DateTimeImmutable('now');
    if ($freq === 'weekly')  return $d->format('o-\WW');
    if ($freq === 'monthly') return $d->format('Y-m');
    return $d->format('Y-m-d');
}

function duty_ymd(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

function duty_days_mask_from($in): int
{
    if (is_int($in) || (is_string($in) && ctype_digit($in))) {
        return max(0, min(127, (int)$in));
    }
    if (!is_array($in)) return DAYS_ALL;
    $mask = 0;
    foreach ($in as $v) {
        if (is_numeric($v)) {
            $i = (int)$v;
            if ($i >= 0 && $i <= 6) $mask |= (1 << $i);
            continue;
        }
        $name = strtolower(substr((string)$v, 0, 3));
        $map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        if (isset($map[$name])) $mask |= (1 << $map[$name]);
    }
    return $mask === 0 ? DAYS_ALL : $mask;
}

/** Which teacher tab an item for this template belongs on. */
function duty_item_slot(array $tpl): string
{
    $freq = (string)($tpl['frequency'] ?? 'daily');
    if ($freq !== 'adhoc') return $freq;
    $repeat = (string)($tpl['repeat_as'] ?? 'once');
    if ($repeat === 'once' || !in_array($repeat, DUTY_REPEAT_AS, true)) return 'adhoc';
    return $repeat;
}

function duty_schedule_label(array $tpl): string
{
    $freq = (string)($tpl['frequency'] ?? 'daily');
    $bits = [duty_freq_label($freq)];
    if ($freq === 'adhoc') {
        $bits[] = duty_repeat_label((string)($tpl['repeat_as'] ?? 'once'));
    }
    $start = duty_ymd($tpl['starts_on'] ?? null);
    $end   = duty_ymd($tpl['ends_on'] ?? null);
    if ($start && $end && $start === $end) $bits[] = $start;
    elseif ($start && $end) $bits[] = $start . ' → ' . $end;
    elseif ($start) $bits[] = 'from ' . $start;
    elseif ($end) $bits[] = 'until ' . $end;
    $mask = (int)($tpl['days_mask'] ?? DAYS_ALL);
    $slot = duty_item_slot($tpl);
    if ($slot === 'daily' || $slot === 'adhoc') {
        if ($mask !== DAYS_ALL) $bits[] = days_mask_label($mask);
    }
    return implode(' · ', $bits);
}

/** True if this template should appear for the given moment. */
function duty_applies_on(array $tpl, ?DateTimeInterface $when = null): bool
{
    $d = $when ? DateTimeImmutable::createFromInterface($when) : new DateTimeImmutable('today');
    $day = $d->format('Y-m-d');
    $start = duty_ymd($tpl['starts_on'] ?? null);
    $end   = duty_ymd($tpl['ends_on'] ?? null);
    $slot  = duty_item_slot($tpl);

    if ($slot === 'weekly') {
        $weekStart = $d->setISODate((int)$d->format('o'), (int)$d->format('W'))->format('Y-m-d');
        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
        if ($start && $start > $weekEnd) return false;
        if ($end && $end < $weekStart) return false;
        return true;
    }
    if ($slot === 'monthly') {
        $monthStart = $d->format('Y-m-01');
        $monthEnd = $d->format('Y-m-t');
        if ($start && $start > $monthEnd) return false;
        if ($end && $end < $monthStart) return false;
        return true;
    }

    if ($start && $day < $start) return false;
    if ($end && $day > $end) return false;
    $mask = (int)($tpl['days_mask'] ?? DAYS_ALL);
    if ($mask !== DAYS_ALL && $mask > 0) {
        $dow = (int)$d->format('w');
        if (($mask & (1 << $dow)) === 0) return false;
    }
    return true;
}

function duty_period_label(string $freq, string $key): string
{
    if ($freq === 'daily' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $key);
        return $d ? $d->format('l, j M Y') : $key;
    }
    if ($freq === 'weekly' && preg_match('/^(\d{4})-W(\d{2})$/', $key, $m)) {
        $d = new DateTimeImmutable('now');
        $d = $d->setISODate((int)$m[1], (int)$m[2]);
        $end = $d->modify('+6 days');
        return 'Week ' . $m[2] . ' · ' . $d->format('j M') . ' – ' . $end->format('j M Y');
    }
    if ($freq === 'monthly' && preg_match('/^(\d{4})-(\d{2})$/', $key, $m)) {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $m[1] . '-' . $m[2] . '-01');
        return $d ? $d->format('F Y') : $key;
    }
    return $key;
}

function duty_tables_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query("SELECT 1 FROM staff_duty_templates LIMIT 1");
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function duty_has_action_key(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!duty_tables_ready()) { $ok = false; return $ok; }
    try {
        db()->query("SELECT action_key FROM staff_duty_templates LIMIT 1");
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Active staff who can be assigned a duty. */
function duty_people(): array
{
    return staff_roster(true);
}

function duty_user_ids_for_audience(string $audience, array $userIds = []): array
{
    if ($audience === 'all_teachers') {
        $rows = db()->query("SELECT id FROM users WHERE active = 1 AND role = 'teacher' ORDER BY name")->fetchAll();
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }
    if ($audience === 'all_non_teaching') {
        $rows = db()->query("SELECT id FROM users WHERE active = 1 AND role = 'non_teaching' ORDER BY name")->fetchAll();
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }
    if ($audience === 'all_staff') {
        return array_map(static fn($r) => (int)$r['id'], duty_people());
    }
    $want = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($want === []) return [];
    $ok = [];
    foreach (duty_people() as $p) {
        if (in_array((int)$p['id'], $want, true)) $ok[] = (int)$p['id'];
    }
    return $ok;
}

function duty_template_user_ids(int $templateId): array
{
    $st = db()->prepare("SELECT user_id FROM staff_duty_template_users WHERE template_id = :id");
    $st->execute([':id' => $templateId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function duty_assignee_ids(array $tpl): array
{
    $audience = (string)($tpl['audience'] ?? 'users');
    $ids = $audience === 'users' ? duty_template_user_ids((int)$tpl['id']) : [];
    return duty_user_ids_for_audience($audience, $ids);
}

function duty_templates(bool $activeOnly = false): array
{
    $sql = "SELECT * FROM staff_duty_templates";
    if ($activeOnly) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY frequency, sort_order, title";
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['user_ids'] = duty_template_user_ids((int)$r['id']);
        $r['assignee_count'] = count(duty_assignee_ids($r));
    }
    unset($r);
    return $rows;
}

function duty_template(int $id): ?array
{
    $st = db()->prepare("SELECT * FROM staff_duty_templates WHERE id = :id");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['user_ids'] = duty_template_user_ids($id);
    return $row;
}

/**
 * Create or update a template. $in keys: id?, title, notes, frequency,
 * audience, user_ids[], is_active, sort_order.
 */
function duty_template_upsert(array $in, ?int $byUserId): array
{
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('Title is required.');
    $title = mb_substr($title, 0, 200);
    $notes = trim((string)($in['notes'] ?? ''));
    $notes = $notes === '' ? null : mb_substr($notes, 0, 500);
    $freq = (string)($in['frequency'] ?? 'daily');
    if (!in_array($freq, DUTY_FREQUENCIES, true)) {
        throw new InvalidArgumentException('frequency must be daily, weekly, monthly or adhoc.');
    }
    $audience = (string)($in['audience'] ?? 'all_teachers');
    if (!in_array($audience, DUTY_AUDIENCES, true)) {
        throw new InvalidArgumentException('audience must be all_teachers, all_non_teaching, all_staff or users.');
    }
    $userIds = array_values(array_unique(array_filter(array_map('intval', (array)($in['user_ids'] ?? [])))));
    if ($audience === 'users' && $userIds === []) {
        throw new InvalidArgumentException('Pick at least one person when audience is users.');
    }
    $active = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
    $sort = (int)($in['sort_order'] ?? 0);
    $start = duty_ymd($in['starts_on'] ?? null);
    $end   = duty_ymd($in['ends_on'] ?? null);
    if ($start && $end && $end < $start) {
        throw new InvalidArgumentException('End date cannot be before the start date.');
    }
    $repeat = (string)($in['repeat_as'] ?? 'once');
    if (!in_array($repeat, DUTY_REPEAT_AS, true)) $repeat = 'once';
    if ($freq !== 'adhoc') $repeat = 'once';
    if ($freq === 'adhoc' && !$start) {
        throw new InvalidArgumentException('Adhoc tasks need a start date.');
    }
    if ($freq === 'adhoc' && !$end) $end = $start;
    $mask = duty_days_mask_from($in['days_mask'] ?? ($in['weekdays'] ?? DAYS_ALL));
    $action = duty_normalize_action((string)($in['action_key'] ?? ''));
    if ($action === 'materials_check' && $notes === null) {
        $notes = 'Walk every shelf. Mark each material. Add a photo, video or note when something is wrong. The sheet is blank again tomorrow.';
    }

    if ($id > 0) {
        $exist = duty_template($id);
        if (!$exist) throw new InvalidArgumentException("No template #$id.");
        if (duty_has_action_key()) {
            db()->prepare("
                UPDATE staff_duty_templates
                   SET title = :t, notes = :n, action_key = :ak, frequency = :f, audience = :a,
                       starts_on = :start, ends_on = :end, days_mask = :m, repeat_as = :r,
                       is_active = :on, sort_order = :s
                 WHERE id = :id
            ")->execute([
                ':t' => $title, ':n' => $notes, ':ak' => $action, ':f' => $freq, ':a' => $audience,
                ':start' => $start, ':end' => $end, ':m' => $mask, ':r' => $repeat,
                ':on' => $active, ':s' => $sort, ':id' => $id,
            ]);
        } else {
            db()->prepare("
                UPDATE staff_duty_templates
                   SET title = :t, notes = :n, frequency = :f, audience = :a,
                       starts_on = :start, ends_on = :end, days_mask = :m, repeat_as = :r,
                       is_active = :on, sort_order = :s
                 WHERE id = :id
            ")->execute([
                ':t' => $title, ':n' => $notes, ':f' => $freq, ':a' => $audience,
                ':start' => $start, ':end' => $end, ':m' => $mask, ':r' => $repeat,
                ':on' => $active, ':s' => $sort, ':id' => $id,
            ]);
        }
    } else {
        if (duty_has_action_key()) {
            db()->prepare("
                INSERT INTO staff_duty_templates
                    (title, notes, action_key, frequency, audience, starts_on, ends_on, days_mask, repeat_as,
                     is_active, sort_order, created_by)
                VALUES (:t, :n, :ak, :f, :a, :start, :end, :m, :r, :on, :s, :u)
            ")->execute([
                ':t' => $title, ':n' => $notes, ':ak' => $action, ':f' => $freq, ':a' => $audience,
                ':start' => $start, ':end' => $end, ':m' => $mask, ':r' => $repeat,
                ':on' => $active, ':s' => $sort, ':u' => $byUserId,
            ]);
        } else {
            db()->prepare("
                INSERT INTO staff_duty_templates
                    (title, notes, frequency, audience, starts_on, ends_on, days_mask, repeat_as,
                     is_active, sort_order, created_by)
                VALUES (:t, :n, :f, :a, :start, :end, :m, :r, :on, :s, :u)
            ")->execute([
                ':t' => $title, ':n' => $notes, ':f' => $freq, ':a' => $audience,
                ':start' => $start, ':end' => $end, ':m' => $mask, ':r' => $repeat,
                ':on' => $active, ':s' => $sort, ':u' => $byUserId,
            ]);
        }
        $id = (int)db()->lastInsertId();
    }

    db()->prepare("DELETE FROM staff_duty_template_users WHERE template_id = :id")
       ->execute([':id' => $id]);
    if ($audience === 'users') {
        $ins = db()->prepare("INSERT INTO staff_duty_template_users (template_id, user_id) VALUES (:t, :u)");
        foreach ($userIds as $uid) {
            $ins->execute([':t' => $id, ':u' => $uid]);
        }
    }

    $tpl = duty_template($id);
    if ($tpl && (int)$tpl['is_active'] === 1) {
        duty_materialize_template($tpl);
    }
    return $tpl ?: ['id' => $id];
}

function duty_template_delete(int $id): void
{
    db()->prepare("DELETE FROM staff_duty_templates WHERE id = :id")->execute([':id' => $id]);
}

function duty_materialize_template(array $tpl, ?DateTimeInterface $when = null, ?string $periodKey = null): int
{
    $when = $when ?: new DateTimeImmutable('today');
    if (!duty_applies_on($tpl, $when)) return 0;
    $ids = duty_assignee_ids($tpl);
    $slot = duty_item_slot($tpl);
    if ($slot === 'adhoc') {
        $key = duty_ymd($tpl['starts_on'] ?? null) ?: duty_period_key('daily', $when);
    } else {
        $key = $periodKey ?: duty_period_key($slot, $when);
    }
    $n = 0;
    $sql = "INSERT IGNORE INTO staff_duty_items
                (template_id, user_id, frequency, period_key, title, notes, source, status)
            VALUES (:tpl, :u, :f, :k, :t, :n, 'template', 'pending')";
    $st = db()->prepare($sql);
    foreach ($ids as $uid) {
        $st->execute([
            ':tpl' => (int)$tpl['id'],
            ':u'   => $uid,
            ':f'   => $slot,
            ':k'   => $key,
            ':t'   => (string)$tpl['title'],
            ':n'   => $tpl['notes'] !== null && $tpl['notes'] !== '' ? (string)$tpl['notes'] : null,
        ]);
        $n += $st->rowCount();
    }
    db()->prepare("
        UPDATE staff_duty_items
           SET title = :t, notes = :n
         WHERE template_id = :id AND status = 'pending'
    ")->execute([
        ':t' => (string)$tpl['title'],
        ':n' => $tpl['notes'] !== null && $tpl['notes'] !== '' ? (string)$tpl['notes'] : null,
        ':id' => (int)$tpl['id'],
    ]);
    return $n;
}

/** Ensure this person has this period's items from every active template they are on. */
function duty_materialize_for_user(int $userId, ?DateTimeInterface $when = null): void
{
    if (!duty_tables_ready()) return;
    $when = $when ?: new DateTimeImmutable('today');
    foreach (duty_templates(true) as $tpl) {
        if (!in_array($userId, duty_assignee_ids($tpl), true)) continue;
        if (!duty_applies_on($tpl, $when)) continue;
        $slot = duty_item_slot($tpl);
        $key = $slot === 'adhoc'
            ? (duty_ymd($tpl['starts_on'] ?? null) ?: duty_period_key('daily', $when))
            : duty_period_key($slot, $when);
        db()->prepare("
            INSERT IGNORE INTO staff_duty_items
                (template_id, user_id, frequency, period_key, title, notes, source, status)
            VALUES (:tpl, :u, :f, :k, :t, :n, 'template', 'pending')
        ")->execute([
            ':tpl' => (int)$tpl['id'],
            ':u'   => $userId,
            ':f'   => $slot,
            ':k'   => $key,
            ':t'   => (string)$tpl['title'],
            ':n'   => $tpl['notes'] !== null && $tpl['notes'] !== '' ? (string)$tpl['notes'] : null,
        ]);
    }
}

function duty_items_for_user(int $userId, string $freq, string $periodKey): array
{
    $akSel = duty_has_action_key() ? ', t.action_key' : '';
    if ($freq === 'adhoc') {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $st = db()->prepare("
            SELECT i.*, t.starts_on AS window_start, t.ends_on AS window_end $akSel
              FROM staff_duty_items i
            LEFT JOIN staff_duty_templates t ON t.id = i.template_id
             WHERE i.user_id = :u AND i.frequency = 'adhoc'
               AND (
                    (i.source = 'self' AND i.period_key = :today)
                 OR (
                        i.source = 'template'
                    AND (t.starts_on IS NULL OR t.starts_on <= :d1)
                    AND (t.ends_on IS NULL OR t.ends_on >= :d2)
                    )
               )
             ORDER BY i.source ASC, i.id ASC
        ");
        $st->execute([':u' => $userId, ':today' => $today, ':d1' => $today, ':d2' => $today]);
        return $st->fetchAll();
    }
    $akSel = duty_has_action_key() ? ', t.action_key' : '';
    $st = db()->prepare("
        SELECT i.* $akSel
          FROM staff_duty_items i
          LEFT JOIN staff_duty_templates t ON t.id = i.template_id
         WHERE i.user_id = :u AND i.frequency = :f AND i.period_key = :k
         ORDER BY i.source ASC, i.id ASC
    ");
    $st->execute([':u' => $userId, ':f' => $freq, ':k' => $periodKey]);
    return $st->fetchAll();
}

function duty_item(int $id): ?array
{
    $st = db()->prepare("SELECT * FROM staff_duty_items WHERE id = :id");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

function duty_set_status(int $itemId, int $userId, string $status, string $reason = '', bool $adminOverride = false): void
{
    if (!in_array($status, DUTY_STATUSES, true)) {
        throw new InvalidArgumentException('status must be pending, done or not_done.');
    }
    $item = duty_item($itemId);
    if (!$item) throw new InvalidArgumentException('Task not found.');
    if (!$adminOverride && (int)$item['user_id'] !== $userId) {
        throw new InvalidArgumentException('That task is not yours.');
    }
    $reason = trim($reason);
    if ($status === 'not_done' && $reason === '') {
        throw new InvalidArgumentException('Please say why it was not done.');
    }
    if ($status !== 'not_done') $reason = $reason === '' ? null : $reason;
    $doneAt = $status === 'pending' ? null : date('Y-m-d H:i:s');
    db()->prepare("
        UPDATE staff_duty_items
           SET status = :s, reason = :r, completed_at = :c
         WHERE id = :id
    ")->execute([':s' => $status, ':r' => $reason, ':c' => $doneAt, ':id' => $itemId]);
}

function duty_save_item_notes(int $itemId, int $userId, string $comment, string $extra, bool $adminOverride = false): void
{
    $item = duty_item($itemId);
    if (!$item) throw new InvalidArgumentException('Task not found.');
    if (!$adminOverride && (int)$item['user_id'] !== $userId) {
        throw new InvalidArgumentException('That task is not yours.');
    }
    $comment = trim($comment);
    $extra = trim($extra);
    db()->prepare("
        UPDATE staff_duty_items SET comment = :c, extra_work = :e WHERE id = :id
    ")->execute([
        ':c' => $comment === '' ? null : mb_substr($comment, 0, 4000),
        ':e' => $extra === '' ? null : mb_substr($extra, 0, 4000),
        ':id' => $itemId,
    ]);
}

function duty_period_note(int $userId, string $freq, string $periodKey): array
{
    $st = db()->prepare("
        SELECT comment, extra_work FROM staff_duty_period_notes
         WHERE user_id = :u AND frequency = :f AND period_key = :k
    ");
    $st->execute([':u' => $userId, ':f' => $freq, ':k' => $periodKey]);
    $row = $st->fetch();
    return [
        'comment'    => (string)($row['comment'] ?? ''),
        'extra_work' => (string)($row['extra_work'] ?? ''),
    ];
}

function duty_save_period_note(int $userId, string $freq, string $periodKey, string $comment, string $extra): void
{
    if (!in_array($freq, DUTY_FREQUENCIES, true)) {
        throw new InvalidArgumentException('Bad frequency.');
    }
    $comment = trim($comment);
    $extra = trim($extra);
    db()->prepare("
        INSERT INTO staff_duty_period_notes (user_id, frequency, period_key, comment, extra_work)
        VALUES (:u, :f, :k, :c, :e)
        ON DUPLICATE KEY UPDATE comment = VALUES(comment), extra_work = VALUES(extra_work)
    ")->execute([
        ':u' => $userId,
        ':f' => $freq,
        ':k' => $periodKey,
        ':c' => $comment === '' ? null : mb_substr($comment, 0, 4000),
        ':e' => $extra === '' ? null : mb_substr($extra, 0, 4000),
    ]);
}

function duty_add_self(int $userId, string $freq, string $title, ?string $periodKey = null, string $whoName = ''): int
{
    if (!in_array($freq, DUTY_FREQUENCIES, true)) {
        throw new InvalidArgumentException('frequency must be daily, weekly, monthly or adhoc.');
    }
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('Give the extra task a name.');
    $title = mb_substr($title, 0, 200);
    $key = $periodKey ?: duty_period_key($freq);
    db()->prepare("
        INSERT INTO staff_duty_items
            (template_id, user_id, frequency, period_key, title, source, status)
        VALUES (NULL, :u, :f, :k, :t, 'self', 'pending')
    ")->execute([':u' => $userId, ':f' => $freq, ':k' => $key, ':t' => $title]);
    $id = (int)db()->lastInsertId();
    $who = $whoName !== '' ? $whoName : 'A colleague';
    notify_admins(
        'staff',
        'duty.self_added',
        $who . ' added their own duty: ' . $title,
        duty_freq_label($freq) . ' · ' . duty_period_label($freq, $key),
        '/duties/admin.php?view=review&freq=' . urlencode($freq) . '&period=' . urlencode($key),
        false
    );
    return $id;
}

function duty_pending_count(int $userId, ?DateTimeInterface $when = null): int
{
    if (!duty_tables_ready()) return 0;
    try {
        duty_materialize_for_user($userId, $when);
        $n = 0;
        foreach (DUTY_FREQUENCIES as $freq) {
            $key = duty_period_key($freq, $when);
            $st = db()->prepare("
                SELECT COUNT(*) FROM staff_duty_items
                 WHERE user_id = :u AND frequency = :f AND period_key = :k AND status = 'pending'
            ");
            $st->execute([':u' => $userId, ':f' => $freq, ':k' => $key]);
            $n += (int)$st->fetchColumn();
        }
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

function duty_review(string $freq, string $periodKey): array
{
    if (!in_array($freq, DUTY_FREQUENCIES, true)) return [];
    $when = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodKey)) {
        $when = DateTimeImmutable::createFromFormat('Y-m-d', $periodKey) ?: null;
    }
    foreach (duty_templates(true) as $tpl) {
        if (duty_item_slot($tpl) !== $freq) continue;
        duty_materialize_template($tpl, $when, $periodKey);
    }
    if ($freq === 'adhoc') {
        $day = $when ? $when->format('Y-m-d') : (new DateTimeImmutable('today'))->format('Y-m-d');
        $st = db()->prepare("
            SELECT i.*, u.name AS user_name, u.role AS user_role
              FROM staff_duty_items i
              JOIN users u ON u.id = i.user_id
              LEFT JOIN staff_duty_templates t ON t.id = i.template_id
             WHERE i.frequency = 'adhoc'
               AND (
                    i.source = 'self'
                 OR (
                        (t.starts_on IS NULL OR t.starts_on <= :d1)
                    AND (t.ends_on IS NULL OR t.ends_on >= :d2)
                    )
               )
             ORDER BY u.name, i.source, i.id
        ");
        $st->execute([':d1' => $day, ':d2' => $day]);
        return $st->fetchAll();
    }
    $st = db()->prepare("
        SELECT i.*, u.name AS user_name, u.role AS user_role
          FROM staff_duty_items i
          JOIN users u ON u.id = i.user_id
         WHERE i.frequency = :f AND i.period_key = :k
         ORDER BY u.name, i.source, i.id
    ");
    $st->execute([':f' => $freq, ':k' => $periodKey]);
    return $st->fetchAll();
}
