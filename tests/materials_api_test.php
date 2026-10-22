<?php
/**
 * Pure helpers for the materials app API — dates and upload ids.
 *
 * Run: php tests/materials_api_test.php
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/../includes/api_materials.php';

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
    echo '     got  ' . var_export($got, true) . "\n";
    echo '     want ' . var_export($want, true) . "\n";
}

function expect_throw(callable $fn, string $name): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        expect_same(true, true, $name);
        return;
    }
    expect_same(false, true, $name);
}

$today = new DateTimeImmutable('2026-10-21');
expect_same(api_materials_date('', $today), '2026-10-21', 'blank date is today');
expect_same(api_materials_date('today', $today), '2026-10-21', 'today keyword');
expect_same(api_materials_date('2026-10-20', $today), '2026-10-20', 'yesterday is allowed');
expect_same(api_materials_date('2026-10-07', $today), '2026-10-07', '14 days back is allowed');
expect_throw(fn () => api_materials_date('2026-10-06', $today), '15 days back is rejected');
expect_throw(fn () => api_materials_date('2026-10-22', $today), 'future date is rejected');
expect_throw(fn () => api_materials_date('2026-02-31', $today), 'impossible date is rejected');
expect_throw(fn () => api_materials_date('21-10-2026', $today), 'wrong shape is rejected');

expect_same(api_materials_client_key(''), '', 'empty upload id is allowed');
expect_same(
    api_materials_client_key('123e4567-e89b-12d3-a456-426614174000'),
    '123e4567-e89b-12d3-a456-426614174000',
    'uuid upload id is kept'
);
expect_throw(fn () => api_materials_client_key('short'), 'short upload id is rejected');
expect_throw(fn () => api_materials_client_key('has space in it!!'), 'upload id must be plain');

$codes = array_column(api_materials_conditions_payload(), 'code');
expect_same($codes[0], 'good', 'first condition is Good');
expect_same(in_array('broken', $codes, true), true, 'broken is in the vocabulary');
expect_same(count($codes), count(mm_conditions()), 'app vocabulary matches the website');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
