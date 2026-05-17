<?php
// View + domain helpers.

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

function asset_version(): string
{
    static $v = null;
    if ($v === null) {
        $css = __DIR__ . '/../assets/css/style.css';
        $v = is_readable($css) ? (string) filemtime($css) : '1';
    }
    return $v;
}

// ---------- Domain helpers --------------------------------------------------

/** Academic-year months in display order (Jun → Mar). */
function academic_months(?int $year = null): array
{
    $now = new DateTime('now');
    $year ??= (int)$now->format('Y');
    // Academic year starts in June. If we're Jan–May, anchor to last June.
    $month = (int)$now->format('n');
    $startYear = ($month >= 6) ? $year : $year - 1;

    $months = [];
    for ($i = 0; $i < 10; $i++) {
        $d = (new DateTime("$startYear-06-01"))->modify("+$i months");
        $months[] = $d->format('M-y');     // "Jun-25"
    }
    return $months;
}

/** "Jun-25" for the current calendar month. */
function current_month_year(): string
{
    return (new DateTime('now'))->format('M-y');
}

function grade_badge_class(string $grade): string
{
    return 'grade grade-' . strtolower(str_replace(' ', '-', $grade));
}

/** Pretty month-year for headers: "Jun-25" → "Jun 2025". */
function month_year_label(string $my): string
{
    $d = DateTime::createFromFormat('M-y', $my);
    return $d ? $d->format('M Y') : $my;
}

/** Sort academic month_year strings chronologically. */
function compare_month_year(string $a, string $b): int
{
    $da = DateTime::createFromFormat('M-y', $a);
    $db = DateTime::createFromFormat('M-y', $b);
    if (!$da || !$db) return strcmp($a, $b);
    return $da <=> $db;
}

/** Rating config keyed by code, with numeric_value and color. */
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

/** Ordered rating codes for button rendering (D, P, N). */
function rating_codes(): array
{
    $map = rating_config_map();
    $codes = array_keys($map);
    usort($codes, fn($a, $b) => $map[$b]['numeric_value'] <=> $map[$a]['numeric_value']);
    return $codes;
}
