<?php
/**
 * Week-key helpers for Weekly Plans — teachers must be able to open
 * this week AND next week (admins already navigate any week).
 *
 * Run: php tests/plan_weeks_test.php
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/duties.php';

$failed = 0;
$passed = 0;

function expect_same($got, $want, string $name): void
{
    global $failed, $passed;
    if ($got === $want) {
        $passed++;
        echo "ok  $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name\n";
    echo "     got  " . var_export($got, true) . "\n";
    echo "     want " . var_export($want, true) . "\n";
}

function expect_true(bool $ok, string $name): void
{
    expect_same($ok, true, $name);
}

// Saturday 19 Sep 2026 is ISO week 2026-W38; next week is 2026-W39.
$sat = new DateTimeImmutable('2026-09-19', new DateTimeZone('Asia/Kolkata'));
$mon = new DateTimeImmutable('2026-09-14', new DateTimeZone('Asia/Kolkata'));
$sun = new DateTimeImmutable('2026-09-20', new DateTimeZone('Asia/Kolkata'));

expect_same(plan_current_week_key($sat), '2026-W38', 'current week on Saturday is W38');
expect_same(plan_next_week_key($sat), '2026-W39', 'next week on Saturday is W39');
expect_same(plan_next_week_key($mon), '2026-W39', 'next week on Monday is W39');
expect_same(plan_next_week_key($sun), '2026-W39', 'next week on Sunday of W38 is W39');

$open = plan_teacher_open_weeks($sat);
expect_same(count($open), 2, 'teachers get two open weeks');
expect_same($open[0]['week_key'] ?? null, '2026-W38', 'first open week is this week');
expect_same($open[0]['heading'] ?? null, 'This week', 'first heading is This week');
expect_same($open[1]['week_key'] ?? null, '2026-W39', 'second open week is next week');
expect_same($open[1]['heading'] ?? null, 'Next week', 'second heading is Next week');

$keys = plan_teacher_open_week_keys($sat);
expect_same($keys, ['2026-W38', '2026-W39'], 'open week keys are this + next');

// Year boundary: Thu 31 Dec 2026 is ISO 2026-W53; next is 2027-W01.
$nye = new DateTimeImmutable('2026-12-31', new DateTimeZone('Asia/Kolkata'));
expect_same(plan_current_week_key($nye), '2026-W53', 'NYE 2026 is W53');
expect_same(plan_next_week_key($nye), '2027-W01', 'week after W53 is 2027-W01');

// Duty link without a user should still name a week teachers can start.
$href = duty_action_href('weekly_plan');
expect_true(str_contains($href, 'week='), 'duty weekly_plan href includes a week');
expect_true(
    str_contains($href, rawurlencode(plan_current_week_key()))
        || str_contains($href, rawurlencode(plan_next_week_key())),
    'duty weekly_plan href targets this or next week'
);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
