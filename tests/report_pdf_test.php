<?php
/**
 * The downloaded assessment report keeps every month, the full skill name,
 * and the rating word. The on-screen report already did; the PDF used to
 * drop months after eight and cut skill names with an ellipsis.
 *
 * Run: php tests/report_pdf_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/child_report.php';
require_once __DIR__ . '/../includes/child_report_pdf.php';

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

function expect_contains(string $haystack, string $needle, string $name): void
{
    expect_same(str_contains($haystack, $needle), true, $name);
}

function pdf_plain(string $bytes): string
{
    $out = '';
    if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $m)) return $bytes;
    foreach ($m[1] as $stream) {
        $plain = @gzuncompress($stream);
        $out .= $plain !== false ? $plain : $stream;
    }
    return $out;
}

expect_same(pdf_fit_columns(182.0, 62.0, 32.0, 10), 3, 'summary columns stop before the rating word is squeezed out');
expect_same(pdf_fit_columns(182.0, 96.0, 20.0, 2), 2, 'two months stay on one skill table');
expect_same(pdf_fit_columns(100.0, 90.0, 20.0, 5), 1, 'a month is never dropped when only one column fits');
expect_same(count(pdf_month_groups(['a', 'b', 'c', 'd', 'e'], 2)), 3, 'months are split into groups, not discarded');

$months = ['Jun-25', 'Jul-25', 'Aug-25', 'Sep-25', 'Oct-25', 'Nov-25', 'Dec-25', 'Jan-26', 'Feb-26', 'Mar-26'];
$skill = 'Naming and writing all letter sounds and names a-z in upper case/smaller case';
$d = [
    'student' => [
        'first_name' => 'Asha',
        'last_name' => 'Nair',
        'grade' => 'Nursery',
        'teacher_name' => 'Meera',
        'dob' => '',
        'admission_number' => '',
        'gender' => '',
        'joining_date' => '',
        'mother_tongue' => '',
    ],
    'observations' => [],
    'baseline' => null,
    'months' => $months,
    'categories' => ['ENGLISH'],
    'catAvg' => [],
    'indicators' => [
        'ENGLISH' => [[
            'text' => $skill,
            'custom' => false,
            'order' => 1,
            'ratings' => ['Jun-25' => 'N', 'Mar-26' => 'D'],
        ]],
    ],
    'comments' => [
        'Mar-26' => ['' => ['Reads the whole sentence aloud and asks for the next book.']],
    ],
    'ratings' => [
        'N' => ['label' => 'Needs Attention', 'color' => '#ef4444', 'numeric_value' => 1],
        'E' => ['label' => 'Emerging', 'color' => '#6366f1', 'numeric_value' => 2],
        'P' => ['label' => 'Progressing', 'color' => '#f59e0b', 'numeric_value' => 3],
        'I' => ['label' => 'Independent', 'color' => '#14b8a6', 'numeric_value' => 4],
        'D' => ['label' => 'Developed', 'color' => '#22c55e', 'numeric_value' => 5],
        'M' => ['label' => 'Mastered', 'color' => '#10b981', 'numeric_value' => 5],
    ],
    'attendance' => [],
];

$bytes = child_report_pdf_bytes($d, 'Little Graduates');
expect_same(str_starts_with($bytes, '%PDF-'), true, 'download is a PDF');
$text = pdf_plain($bytes);

expect_contains($text, 'smaller case', 'full skill name is in the download');
expect_contains($text, 'Needs Attention', 'summary prints the rating word, not only the letter');
expect_contains($text, 'Developed', 'later month keeps its rating word');
expect_contains($text, 'Jun 2025', 'first assessed month is included');
expect_contains($text, 'Mar 2026', 'tenth month is included, not cut after eight');
expect_contains($text, 'Reads the whole sentence aloud', 'teacher remark is included');
expect_same(str_contains($text, 'not shown in the month-by-month'), false, 'no months are omitted from the grids');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
