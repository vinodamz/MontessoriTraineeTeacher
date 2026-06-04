<?php
// View + domain helpers — shared across modules.

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Read a value from the `app_settings` key-value table, falling back to the
 * supplied default (typically the value from includes/config.php) if the
 * table doesn't exist yet or the key is missing.
 *
 * Cached for the duration of the request.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null || !empty($GLOBALS['_app_setting_dirty'])) {
        $cache = [];
        unset($GLOBALS['_app_setting_dirty']);
        try {
            $rows = db()->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
            foreach ($rows as $r) $cache[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e) {
            // app_settings table doesn't exist yet (pre-migration) — fall through.
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null
        ? $cache[$key]
        : $default;
}

function app_setting_clear_cache(): void
{
    $GLOBALS['_app_setting_dirty'] = true;
}

/** App display name (DB-backed, falls back to config.php's `app.name`). */
function app_name(): string
{
    $cfg = app_config();
    return (string)app_setting('app_name', $cfg['app']['name'] ?? 'Little Graduates');
}

/** App short name (DB-backed, falls back to config.php's `app.short_name`). */
function app_short_name(): string
{
    $cfg = app_config();
    return (string)app_setting('app_short_name', $cfg['app']['short_name'] ?? 'LG');
}

/**
 * Central registry of external apps the school plugs into MTT. One entry
 * per integration — the same shape drives the home-dashboard tile, the
 * nav link, and the admin module checkbox.
 *
 * Add a new external app by appending an entry here and inserting its
 * `<key>_url` row in app_settings (see migrate_026).
 */
function external_apps_registry(): array
{
    return [
        'wacrm' => [
            'name'        => 'WACRM',
            'subtitle'    => 'WhatsApp CRM workspace',
            'settings_key'=> 'wacrm_url',
            'route'       => '/wacrm/index.php',
            'tile_gradient' => 'linear-gradient(135deg, #25d366 0%, #128c7e 100%)',
            'svg'         => '<path d="M20 12a8 8 0 1 1-3.5-6.6L20 4l-1.4 3.5A8 8 0 0 1 20 12Z"/><path d="M8 11.5c0 2.5 2 4.5 4.5 4.5l1.5-1.5-2-1-1 1c-1-.5-1.5-1-2-2l1-1-1-2L7.5 10c0 .5.5 1 .5 1.5Z"/>',
        ],
        'n8n' => [
            'name'        => 'n8n',
            'subtitle'    => 'Workflow automation',
            'settings_key'=> 'n8n_url',
            'route'       => '/n8n/index.php',
            'tile_gradient' => 'linear-gradient(135deg, #ea4b71 0%, #b3164b 100%)',
            'svg'         => '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="18" r="2.5"/><circle cx="12" cy="12" r="2.5"/><path d="M8.5 6h7M8.5 18h7M6 8.5v7M18 8.5v7M8.3 7.7l2.4 2.4M15.7 7.7l-2.4 2.4M8.3 16.3l2.4-2.4M15.7 16.3l-2.4-2.4"/>',
        ],
    ];
}

/** URL configured for an external app via app_settings. '' if unset. */
function external_app_url(string $key): string
{
    $reg = external_apps_registry();
    if (!isset($reg[$key])) return '';
    return (string)app_setting($reg[$key]['settings_key'], '');
}

/* ------------------------------------------------------------------ *
 * WACRM single sign-on.
 *
 * MTT is the identity provider: we mint a short-lived HMAC-signed token
 * for the logged-in user and hand it to WACRM's /api/sso/mtt endpoint,
 * which verifies it, syncs the user into the CRM account, and logs them
 * in — so the embedded/opened CRM is already authenticated.
 *
 * The shared secret lives in app_settings('wacrm_sso_secret') and must
 * match WACRM's MTT_SSO_SECRET env var. With no secret set, we fall back
 * to the bare URL (the CRM shows its own login).
 * ------------------------------------------------------------------ */
function mtt_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Signed SSO token for the current session user. '' if no secret set. */
function mtt_sso_token(): string
{
    $secret = (string)app_setting('wacrm_sso_secret', '');
    if ($secret === '') return '';
    $payload = json_encode([
        'uid'  => (int)($_SESSION['user_id'] ?? 0),
        'name' => (string)($_SESSION['user_name'] ?? ''),
        'role' => (string)($_SESSION['user_role'] ?? 'teacher'),
        'exp'  => time() + 300,
    ], JSON_UNESCAPED_UNICODE);
    $p   = mtt_b64url((string)$payload);
    $sig = mtt_b64url(hash_hmac('sha256', $p, $secret, true));
    return $p . '.' . $sig;
}

/** WACRM launch URL — SSO entry with a fresh token, or the bare URL. */
function wacrm_launch_url(): string
{
    $url = external_app_url('wacrm');
    if ($url === '') return '';
    $tok = mtt_sso_token();
    if ($tok === '') return $url;
    return rtrim($url, '/') . '/api/sso/mtt?token=' . urlencode($tok);
}

/**
 * Send a WhatsApp message to a lead through WACRM's hybrid endpoint
 * (/api/whatsapp/send-to-lead). WACRM decides text-vs-template by the 24-hour
 * session window: free text if the parent messaged within 24h, otherwise the
 * Meta-approved template. Server-to-server, authed with the shared
 * wacrm_sso_secret (== WACRM's MTT_SSO_SECRET env var).
 *
 * @param string   $phone    recipient (any format; WACRM normalises)
 * @param string   $text     free-text body (used inside the 24h window)
 * @param string   $template Meta-approved template name (used outside it)
 * @param string   $lang     template language code
 * @param string[] $params   ordered template body variables ({{1}}, {{2}}, …)
 * @return array{ok:bool,status:int,sent:?string,error:?string}
 */
function wacrm_send_to_lead(
    string $phone,
    string $text = '',
    string $template = '',
    string $lang = 'en_US',
    array $params = []
): array {
    $base   = external_app_url('wacrm');
    $secret = (string)app_setting('wacrm_sso_secret', '');
    if ($base === '' || $secret === '') {
        return ['ok' => false, 'status' => 0, 'sent' => null,
                'error' => 'WhatsApp CRM not configured — set its URL and SSO secret in Admin.'];
    }
    if (trim($phone) === '') {
        return ['ok' => false, 'status' => 0, 'sent' => null, 'error' => 'No phone number on this lead.'];
    }
    if ($text === '' && $template === '') {
        return ['ok' => false, 'status' => 0, 'sent' => null,
                'error' => 'This stage has no WhatsApp text or template configured.'];
    }

    $body = ['phone' => $phone];
    if ($text !== '') {
        $body['text'] = $text;
    }
    if ($template !== '') {
        $body['template_name']     = $template;
        $body['template_language'] = $lang !== '' ? $lang : 'en_US';
        $body['template_params']   = array_values($params);
    }

    $ch = curl_init(rtrim($base, '/') . '/api/whatsapp/send-to-lead');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Lead-Secret: ' . $secret],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'sent' => null, 'error' => 'Network error: ' . $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($status >= 200 && $status < 300 && !empty($data['success'])) {
        return ['ok' => true, 'status' => $status, 'sent' => (string)($data['sent'] ?? ''), 'error' => null];
    }
    $msg = is_array($data) && isset($data['error']) ? (string)$data['error'] : ('HTTP ' . $status);
    return ['ok' => false, 'status' => $status, 'sent' => null, 'error' => $msg];
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

/**
 * Current academic year as "YYYY-YY".
 * The Indian academic year runs Jun → Mar; June onwards starts a new year.
 *   e.g. anytime in 2026-06 → 2027-05 returns "2026-27".
 */
function current_academic_year(?DateTime $now = null): string
{
    $now ??= new DateTime('today');
    $year  = (int)$now->format('Y');
    $month = (int)$now->format('n');
    $startYear = ($month >= 6) ? $year : $year - 1;
    return $startYear . '-' . substr((string)($startYear + 1), -2);
}

/**
 * Academic year that a student who *starts on $date* belongs to. If the
 * date is null or unparseable, falls back to the latest year in use
 * (prefers the upcoming year during the admissions cycle, so a child
 * being admitted in May for a June start lands in the next year by default).
 */
function academic_year_for_start_date(?string $date): string
{
    if ($date) {
        try {
            return current_academic_year(new DateTime($date));
        } catch (Throwable $e) {
            // fall through to default
        }
    }
    $years = academic_years_in_use();
    return $years[0] ?? current_academic_year();
}

/** Next academic year after the given one. "2025-26" → "2026-27". */
function next_academic_year(string $year): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $year, $m)) return current_academic_year();
    $start = (int)$m[1] + 1;
    return $start . '-' . substr((string)($start + 1), -2);
}

/**
 * All academic years that show up in selectors. Position 0 is the default
 * pick — it must be the **current** academic year so anything that grabs
 * `academic_years_in_use()[0]` lands today's students in today's year, not
 * next year. The next-year option is still offered (so the office can
 * pre-enroll for the upcoming session), but appended at the end so it
 * never becomes the default.
 */
function academic_years_in_use(): array
{
    static $years = null;
    if ($years === null) {
        try {
            $rows = db()->query("
                SELECT DISTINCT academic_year FROM students
                WHERE academic_year IS NOT NULL AND academic_year <> ''
                ORDER BY academic_year DESC
            ")->fetchAll(PDO::FETCH_COLUMN);
            $years = $rows;
        } catch (Throwable $e) {
            $years = [];
        }
        $cur  = current_academic_year();
        $next = next_academic_year($cur);

        // Strip current/next so we can re-place them deterministically.
        $years = array_values(array_filter($years, fn($y) => $y !== $cur && $y !== $next));

        // Current year first → becomes the default for any `[0]` picker.
        array_unshift($years, $cur);
        // Next year still selectable, at the bottom of the dropdown.
        $years[] = $next;
    }
    return $years;
}

/** Promote helper — Playgroup→Nursery→LKG→UKG. UKG returns null (graduates). */
function next_grade(string $grade): ?string
{
    return [
        'Playgroup' => 'Nursery',
        'Nursery'   => 'LKG',
        'LKG'       => 'UKG',
        'UKG'       => null,
    ][$grade] ?? null;
}

/**
 * Enrollment-status display + colour helpers.
 */
const ENROLLMENT_STATUSES = [
    'enrolled'   => 'Enrolled',
    'promoted'   => 'Promoted',
    'withdrawn'  => 'Withdrawn',
    'graduated'  => 'Graduated',
    'on_break'   => 'On break',
];

function enrollment_status_label(string $s): string
{
    return ENROLLMENT_STATUSES[$s] ?? $s;
}

/**
 * Withdrawal reasons. App-side enum — keep the codes short + stable so the
 * analytics page can group on them. Edit this list and the new option
 * appears in the dropdown immediately.
 */
const WITHDRAWAL_REASONS = [
    'relocated'    => 'Family relocated',
    'financial'    => 'Financial difficulty',
    'distance'     => 'Distance / commute',
    'dissatisfied' => 'Unhappy with school',
    'medical'      => 'Medical / health',
    'switched'     => 'Switched to another school',
    'homeschool'   => 'Homeschooling',
    'behavioral'   => 'Behavioural concerns',
    'completed'    => 'Completed UKG / graduated',
    'other'        => 'Other (see notes)',
];

function withdrawal_reason_label(string $code): string
{
    return WITHDRAWAL_REASONS[$code] ?? $code;
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

// ---------- Student-document upload helpers ---------------------------------

/** Absolute filesystem path to the student-docs directory. Created on demand. */
function student_docs_dir(): string
{
    $dir = realpath(__DIR__ . '/..') . '/uploads/student_docs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Limits + whitelist. Keep these tight — this is an internal school app, not Dropbox. */
const STUDENT_DOC_MAX_BYTES   = 10 * 1024 * 1024; // 10 MB
const STUDENT_DOC_MIME_ALLOW  = [
    'application/pdf'                                                            => 'pdf',
    'image/jpeg'                                                                 => 'jpg',
    'image/png'                                                                  => 'png',
    'image/gif'                                                                  => 'gif',
    'image/webp'                                                                 => 'webp',
    'application/msword'                                                         => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
    'application/vnd.ms-excel'                                                   => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'          => 'xlsx',
    'text/plain'                                                                 => 'txt',
];
const STUDENT_DOC_CATEGORIES = [
    'birth_certificate' => 'Birth certificate',
    'vaccination'       => 'Vaccination record',
    'id_proof'          => 'ID proof',
    'medical'           => 'Medical',
    'school'            => 'School / academic',
    'other'             => 'Other',
];

function format_bytes(int $b): string
{
    if ($b < 1024)               return $b . ' B';
    if ($b < 1024 * 1024)        return number_format($b / 1024, 1) . ' KB';
    return number_format($b / (1024 * 1024), 1) . ' MB';
}

function student_doc_category_label(string $code): string
{
    return STUDENT_DOC_CATEGORIES[$code] ?? $code;
}

// ---------- Student photo upload helpers ------------------------------------

/**
 * Photos for the child, father, mother. Stored under /uploads/student_photos/
 * (gitignored). Served via /students/photo.php with an auth check — keep
 * this directory outside the web root if your host supports it.
 */
function student_photos_dir(): string
{
    $dir = realpath(__DIR__ . '/..') . '/uploads/student_photos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

const STUDENT_PHOTO_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const STUDENT_PHOTO_MIME_ALLOW = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/**
 * Move an uploaded $_FILES entry into student_photos_dir() under a unique
 * filename and return the basename (suitable for storing in
 * students.photo_path / student_parents.photo_path). Returns null when no
 * file was uploaded. Throws RuntimeException on validation failure.
 */
function student_photo_store(array $file, string $prefix = 'photo'): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > STUDENT_PHOTO_MAX_BYTES) {
        throw new RuntimeException('Image too large — max ' . format_bytes(STUDENT_PHOTO_MAX_BYTES) . '.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!isset(STUDENT_PHOTO_MIME_ALLOW[$mime])) {
        throw new RuntimeException('Only JPEG / PNG / WebP / GIF images are allowed.');
    }
    $ext   = STUDENT_PHOTO_MIME_ALLOW[$mime];
    $name  = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest  = student_photos_dir() . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not move uploaded file.');
    }
    @chmod($dest, 0644);
    return $name;
}

/** Best-effort unlink for a stored photo. Silent if missing. */
function student_photo_delete(?string $stored): void
{
    if ($stored === null || $stored === '') return;
    $p = student_photos_dir() . '/' . basename($stored);
    if (is_file($p)) @unlink($p);
}

/** URL for an uploaded photo via the auth-checked serving script. */
function student_photo_url(string $stored): string
{
    return '/students/photo.php?f=' . rawurlencode(basename($stored));
}

// ---------- Expense receipt upload helpers ----------------------------------

/** Absolute filesystem path to the receipts directory. Created on demand. */
function receipts_dir(): string
{
    $dir = realpath(__DIR__ . '/..') . '/uploads/receipts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

const RECEIPT_MAX_BYTES  = 8 * 1024 * 1024; // 8 MB
const RECEIPT_MIME_ALLOW = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
];

const EXPENSE_STATUSES = [
    'submitted'  => 'Submitted',
    'approved'   => 'Approved',
    'rejected'   => 'Rejected',
    'reimbursed' => 'Reimbursed',
];

const EXPENSE_PAYMENT_METHODS = [
    'cash'          => 'Cash',
    'card'          => 'Card',
    'upi'           => 'UPI',
    'bank_transfer' => 'Bank transfer',
    'cheque'        => 'Cheque',
    'other'         => 'Other',
];

function expense_status_label(string $s): string
{
    return EXPENSE_STATUSES[$s] ?? $s;
}

function expense_status_class(string $s): string
{
    return 'exp-status exp-status-' . $s;
}

function expense_payment_label(string $m): string
{
    return EXPENSE_PAYMENT_METHODS[$m] ?? $m;
}

function expense_categories_active(): array
{
    static $rows = null;
    if ($rows === null) {
        try {
            $rows = db()->query("
                SELECT id, name FROM expense_categories
                WHERE is_active = 1
                ORDER BY display_order, name
            ")->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    return $rows;
}

/**
 * Detect the MIME of an uploaded file by reading its contents, NOT by
 * trusting the browser-supplied `type`. Returns null if we can't decide.
 */
function sniff_mime_type(string $tmpPath): ?string
{
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $m = finfo_file($f, $tmpPath);
        finfo_close($f);
        return $m ?: null;
    }
    if (function_exists('mime_content_type')) {
        return mime_content_type($tmpPath) ?: null;
    }
    return null;
}
