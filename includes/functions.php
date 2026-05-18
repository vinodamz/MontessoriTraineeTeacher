<?php
// View + domain helpers — shared across modules.

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

function flash_set(string $type, string $msg): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function user_color(int $id): string
{
    static $palette = ['#EC407A', '#5BA547', '#F5B342', '#2D6BA0', '#A05C7B', '#5DA8A2', '#E07A5F', '#7E57C2'];
    return $palette[$id % count($palette)];
}

function user_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    if (count($parts) === 1) {
        return mb_strtoupper(mb_substr($parts[0], 0, 1));
    }
    return mb_strtoupper(
        mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1)
    );
}

function first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    return $parts[0] ?? $name;
}

/**
 * Cache-busting version string for assets. Uses the mtime of style.css —
 * any deploy that updates it bumps the query string and forces browsers
 * to fetch the new file.
 */
function asset_version(): string
{
    static $v = null;
    if ($v === null) {
        $css = __DIR__ . '/../assets/css/style.css';
        $v = is_readable($css) ? (string) filemtime($css) : '1';
    }
    return $v;
}

// ---------- Tasks-module helpers --------------------------------------------

function status_label(string $s): string
{
    return [
        'todo'        => 'To do',
        'in_progress' => 'In progress',
        'done'        => 'Done',
    ][$s] ?? $s;
}

function priority_class(string $p): string
{
    return "priority-$p";
}

/**
 * All task columns ordered for board rendering. Returns [] if the kanban
 * migration hasn't run yet.
 */
function task_columns(): array
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = db()->query("
                SELECT id, name, position, color, is_done
                FROM task_columns
                ORDER BY position ASC, id ASC
            ")->fetchAll();
        } catch (Throwable $e) {
            $cols = [];
        }
    }
    return $cols;
}

function kanban_available(): bool
{
    return task_columns() !== [];
}

function recurrence_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT 1 FROM task_recurrences LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Bitmask helpers — bit 0 = Sunday … bit 6 = Saturday.
 */
const DAYS_WEEKDAYS = 62;   // Mon–Fri
const DAYS_WEEKENDS = 65;   // Sun + Sat
const DAYS_ALL      = 127;

function days_mask_label(int $mask): string
{
    if ($mask === DAYS_ALL)      return 'Every day';
    if ($mask === DAYS_WEEKDAYS) return 'Weekdays';
    if ($mask === DAYS_WEEKENDS) return 'Weekends';
    $names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    $on = [];
    for ($i = 0; $i < 7; $i++) {
        if ($mask & (1 << $i)) $on[] = $names[$i];
    }
    return $on ? implode(', ', $on) : 'Never';
}

/**
 * Materialise today's instance for every active recurrence whose rule
 * matches today and which doesn't already have an instance for today.
 * Idempotent — safe to call on every page load.
 */
function materialize_recurrences(): void
{
    try {
        _materialize_recurrences_inner();
    } catch (Throwable $e) {
        error_log('[lg] materialize_recurrences failed: ' . $e->getMessage());
    }
}

function _materialize_recurrences_inner(): void
{
    if (!recurrence_available()) return;

    $today  = (new DateTime('today'))->format('Y-m-d');
    $dow    = (int) date('w');
    $dom    = (int) date('j');

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT r.*
        FROM task_recurrences r
        WHERE r.is_active = 1
          AND r.start_date <= :today_a
          AND (r.end_date IS NULL OR r.end_date >= :today_b)
          AND NOT EXISTS (
              SELECT 1 FROM tasks t
              WHERE t.recurrence_id = r.id AND t.instance_date = :today_c
          )
          AND (
              r.frequency = 'daily'
              OR (r.frequency = 'weekly'  AND (r.days_mask & :dow_bit) > 0)
              OR (r.frequency = 'monthly' AND r.day_of_month = :dom)
          )
    ");
    $stmt->execute([
        ':today_a' => $today,
        ':today_b' => $today,
        ':today_c' => $today,
        ':dow_bit' => 1 << $dow,
        ':dom'     => $dom,
    ]);
    $due = $stmt->fetchAll();
    if (!$due) return;

    $posQ = $pdo->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE column_id = :c");
    $ins  = $pdo->prepare("
        INSERT IGNORE INTO tasks
            (title, description, status, column_id, board_position, priority,
             due_date, assigned_to_user_id, created_by_user_id,
             recurrence_id, instance_date)
        VALUES (:t, :d, 'todo', :col, :pos, :p, :due, :a, :c, :r, :date)
    ");

    foreach ($due as $r) {
        $posQ->execute([':c' => $r['column_id']]);
        $pos = (int) $posQ->fetchColumn();

        $offset  = isset($r['due_offset_days']) ? (int)$r['due_offset_days'] : 0;
        $dueDate = $offset === 0
            ? $today
            : (new DateTime($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

        $ins->execute([
            ':t'    => $r['title'],
            ':d'    => $r['description'],
            ':col'  => $r['column_id'],
            ':pos'  => $pos,
            ':p'    => $r['priority'],
            ':due'  => $dueDate,
            ':a'    => $r['assigned_to_user_id'],
            ':c'    => $r['created_by_user_id'],
            ':r'    => $r['id'],
            ':date' => $today,
        ]);
    }
}

function date_bucket(?string $isoDate): string
{
    if (!$isoDate) return 'no-date';
    $today    = new DateTime('today');
    $taskDate = DateTime::createFromFormat('Y-m-d', $isoDate);
    if (!$taskDate) return 'no-date';
    $diff = (int) $today->diff($taskDate)->format('%r%a');
    if ($diff < 0)  return 'overdue';
    if ($diff === 0) return 'today';
    if ($diff === 1) return 'tomorrow';
    if ($diff <= 7)  return 'this-week';
    return 'later';
}

function date_label(?string $isoDate): string
{
    $bucket = date_bucket($isoDate);
    if ($bucket === 'no-date') return '';
    $taskDate = DateTime::createFromFormat('Y-m-d', $isoDate);
    return match ($bucket) {
        'today'    => 'Today',
        'tomorrow' => 'Tomorrow',
        'overdue'  => 'Overdue · ' . $taskDate->format('D j M'),
        default    => $taskDate->format('D j M'),
    };
}

// ---------- Assessment-module helpers ---------------------------------------

/** Academic-year months in display order (Jun → Mar). */
function academic_months(?int $year = null): array
{
    $now = new DateTime('now');
    $year ??= (int)$now->format('Y');
    $month = (int)$now->format('n');
    $startYear = ($month >= 6) ? $year : $year - 1;

    $months = [];
    for ($i = 0; $i < 10; $i++) {
        $d = (new DateTime("$startYear-06-01"))->modify("+$i months");
        $months[] = $d->format('M-y');
    }
    return $months;
}

function current_month_year(): string
{
    return (new DateTime('now'))->format('M-y');
}

function grade_badge_class(string $grade): string
{
    return 'grade grade-' . strtolower(str_replace(' ', '-', $grade));
}

function month_year_label(string $my): string
{
    $d = DateTime::createFromFormat('M-y', $my);
    return $d ? $d->format('M Y') : $my;
}

function compare_month_year(string $a, string $b): int
{
    $da = DateTime::createFromFormat('M-y', $a);
    $db = DateTime::createFromFormat('M-y', $b);
    if (!$da || !$db) return strcmp($a, $b);
    return $da <=> $db;
}

function rating_config_map(): array
{
    static $map = null;
    if ($map === null) {
        $rows = db()->query("SELECT code, label, color, numeric_value FROM rating_config WHERE is_active = 1")->fetchAll();
        $map = [];
        foreach ($rows as $r) $map[$r['code']] = $r;
    }
    return $map;
}

function rating_codes(): array
{
    $map = rating_config_map();
    $codes = array_keys($map);
    usort($codes, fn($a, $b) => $map[$b]['numeric_value'] <=> $map[$a]['numeric_value']);
    return $codes;
}
