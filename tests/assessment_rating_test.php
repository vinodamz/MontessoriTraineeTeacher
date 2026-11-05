<?php
/**
 * Assessment ratings must survive a save, and a new month must open with
 * the previous month's comments already filled in.
 *
 * The live column was ENUM('D','P','N') while Admin → Rating scheme has
 * D, P, N, M, E and I. In non-strict sql_mode the extra codes are stored
 * as ''. This test reproduces that, applies migrate_078, and checks the
 * round-trip. It also checks comment carry-forward.
 *
 * Run: php tests/assessment_rating_test.php
 * Database round-trip (optional): set ASSESSMENT_TEST_DSN, and
 * ASSESSMENT_TEST_USER / ASSESSMENT_TEST_PASSWORD when they are not root / test.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/assessment.php';

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

$known = ['D' => 1, 'P' => 1, 'N' => 1, 'M' => 1, 'E' => 1, 'I' => 1];

$split = assessment_ratings_for_save([
    'std:1' => 'M',
    'std:2' => 'E',
    'std:3' => 'I',
    'std:4' => 'P',
    'std:5' => '',
    'std:6' => '   ',
    'std:7' => 'Z',
    'nope' => 'M',
    'cust:9' => 'D',
], $known);
expect_same($split['ok'], [
    'std:1' => 'M',
    'std:2' => 'E',
    'std:3' => 'I',
    'std:4' => 'P',
    'cust:9' => 'D',
], 'configured ratings are kept, including M, E and I');
expect_same($split['invalid'], ['Z'], 'a code outside the rating scheme is rejected');
expect_same(isset($split['ok']['std:5']) || isset($split['ok']['std:6']), false, 'blank ratings are not written');

expect_same(assessment_previous_month(['Jun-25', 'Mar-26'], 'Oct-26'), 'Mar-26', 'previous month is chronological, not alphabetical');
expect_same(assessment_previous_month(['Oct-26'], 'Mar-26'), null, 'a later month is not carried backwards');
expect_same(assessment_previous_month([], 'Nov-26'), null, 'no history means nothing to carry');

$carried = assessment_carry_comments(
    '',
    ['MOTOR' => ''],
    'Settled well and asks to help.',
    ['MOTOR' => 'Holds the pencil', 'RETIRED' => 'Do not show this'],
    ['MOTOR', 'LANGUAGE']
);
expect_same($carried['overall'], 'Settled well and asks to help.', 'empty overall comment is filled from the previous month');
expect_same($carried['carried_overall'], true, 'carried overall comment is marked carried');
expect_same($carried['categories']['MOTOR'], 'Holds the pencil', 'empty category comment is filled from the previous month');
expect_same($carried['carried_categories'], ['MOTOR' => true], 'carried category comment is marked, and a category not on the form is not');
expect_same(isset($carried['categories']['RETIRED']), false, 'a comment for a category that is not on the form is not copied');

$kept = assessment_carry_comments(
    'November note already written.',
    ['MOTOR' => 'Already reviewed.'],
    'October note',
    ['MOTOR' => 'October motor', 'LANGUAGE' => '   '],
    ['MOTOR', 'LANGUAGE']
);
expect_same($kept['overall'], 'November note already written.', 'an overall comment already on this month is left alone');
expect_same($kept['carried_overall'], false, 'an existing overall comment is not marked carried');
expect_same($kept['categories']['MOTOR'], 'Already reviewed.', 'an existing category comment is left alone');
expect_same($kept['carried_categories'], [], 'blank previous comments are not marked carried');

$truncation = new PDOException('SQLSTATE[22001]: Data truncated for column \'rating\' at row 1', 22001);
expect_same(
    assessment_save_failure_message($truncation),
    'A rating could not be stored, so nothing was saved.',
    'a rating the column refuses is reported instead of saved as blank'
);

echo "\n$passed passed, $failed failed (unit)\n";

$dsn = getenv('ASSESSMENT_TEST_DSN');
if ($dsn === false || $dsn === '') {
    echo "skip database round-trip (ASSESSMENT_TEST_DSN is not set)\n";
    exit($failed > 0 ? 1 : 0);
}

$user = getenv('ASSESSMENT_TEST_USER');
$pass = getenv('ASSESSMENT_TEST_PASSWORD');
$pdo = new PDO($dsn, $user !== false && $user !== '' ? $user : 'root', $pass !== false ? $pass : 'test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'mtt_test') {
    fwrite(STDERR, "Refusing to run destructive rating tests against database '$dbName'.\n");
    exit(1);
}
$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

foreach (['evaluation_cards', 'assessment_comments', 'rating_config'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec("
    CREATE TABLE rating_config (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code CHAR(1) NOT NULL,
        label VARCHAR(60) NOT NULL,
        color VARCHAR(20) NOT NULL,
        numeric_value INT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    )
");
$pdo->exec("
    CREATE TABLE evaluation_cards (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        month_year VARCHAR(8) NOT NULL,
        indicator_id INT UNSIGNED NOT NULL,
        rating ENUM('D','P','N') NOT NULL,
        is_custom_indicator TINYINT(1) NOT NULL DEFAULT 0
    )
");
$pdo->exec("
    CREATE TABLE assessment_comments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        month_year VARCHAR(8) NOT NULL,
        category VARCHAR(60) NULL,
        comment TEXT NOT NULL
    )
");

// Live Admin → Rating scheme order: D, P, N, then M, E, I. All active.
$insCode = $pdo->prepare('INSERT INTO rating_config (code, label, color, numeric_value, is_active) VALUES (?, ?, ?, ?, 1)');
foreach ([['D', 'Developed', 5], ['P', 'Progressing', 3], ['N', 'Needs Attention', 1], ['M', 'Mastered', 5], ['E', 'Emerging', 2], ['I', 'Independent', 4]] as $row) {
    $insCode->execute([$row[0], $row[1], '#000000', $row[2]]);
}
// A value that is not a rating code must not become part of the column.
$pdo->exec("INSERT INTO rating_config (code, label, color, numeric_value, is_active) VALUES ('''', 'bad', '#000', 1, 0)");

$insCard = $pdo->prepare('INSERT INTO evaluation_cards (student_id, teacher_id, month_year, indicator_id, rating, is_custom_indicator) VALUES (1, 1, ?, ?, ?, 0)');
$insCard->execute(['Oct-26', 1, 'P']);
$insCard->execute(['Oct-26', 2, 'M']);
$insCard->execute(['Mar-26', 1, 'D']);
$blankId = (int)$pdo->query("SELECT id FROM evaluation_cards WHERE month_year = 'Oct-26' AND indicator_id = 2")->fetchColumn();
expect_same(
    (string)$pdo->query("SELECT rating FROM evaluation_cards WHERE id = $blankId")->fetchColumn(),
    '',
    'before the migration, saving M into ENUM(D,P,N) stores a blank'
);

$pdo->prepare('INSERT INTO assessment_comments (student_id, teacher_id, month_year, category, comment) VALUES (1, 1, ?, ?, ?)')
    ->execute(['Oct-26', null, 'Settled well and asks to help.']);
$pdo->prepare('INSERT INTO assessment_comments (student_id, teacher_id, month_year, category, comment) VALUES (1, 1, ?, ?, ?)')
    ->execute(['Oct-26', 'MOTOR', 'Holds the pencil']);
$pdo->prepare('INSERT INTO assessment_comments (student_id, teacher_id, month_year, category, comment) VALUES (1, 1, ?, ?, ?)')
    ->execute(['Nov-26', null, 'November note already written.']);

$prefill = assessment_prefill_from_previous(
    $pdo,
    1,
    'Nov-26',
    false,
    false,
    'November note already written.',
    [],
    ['MOTOR', 'LANGUAGE'],
    ['D' => 1, 'P' => 1, 'N' => 1, 'M' => 1, 'E' => 1, 'I' => 1],
    ['std:1' => true, 'std:2' => true]
);
expect_same($prefill['from'], 'Oct-26', 'a new month carries from the latest earlier month');
expect_same($prefill['ratings'], ['std:1' => 'P'], 'a real rating is carried and a blank one is not');
expect_same($prefill['overall'], 'November note already written.', 'a comment already saved on the new month is not replaced');
expect_same($prefill['carried_overall'], false, 'an existing new-month comment is not marked carried');
expect_same($prefill['categories']['MOTOR'], 'Holds the pencil', 'the new month form shows the previous category comment');
expect_same($prefill['carried_categories']['MOTOR'] ?? false, true, 'the prefilled category comment is marked carried');
expect_same((int)$pdo->query("SELECT COUNT(*) FROM evaluation_cards WHERE month_year = 'Nov-26'")->fetchColumn(), 0, 'carrying ratings does not save them');
expect_same((int)$pdo->query("SELECT COUNT(*) FROM assessment_comments WHERE month_year = 'Nov-26' AND category = 'MOTOR'")->fetchColumn(), 0, 'carrying a category comment does not save it');

$fresh = assessment_prefill_from_previous(
    $pdo,
    1,
    'Nov-26',
    false,
    false,
    '',
    [],
    ['MOTOR'],
    ['D' => 1, 'P' => 1, 'N' => 1, 'M' => 1, 'E' => 1, 'I' => 1],
    ['std:1' => true, 'std:2' => true]
);
expect_same($fresh['overall'], 'Settled well and asks to help.', 'a blank new month shows the previous overall comment');
expect_same($fresh['carried_overall'], true, 'the prefilled overall comment is marked carried');

$optOut = assessment_prefill_from_previous(
    $pdo,
    1,
    'Nov-26',
    true,
    false,
    '',
    [],
    ['MOTOR'],
    ['P' => 1],
    ['std:1' => true]
);
expect_same($optOut['from'], null, 'blank=1 starts from an empty form');
expect_same($optOut['overall'], '', 'blank=1 does not prefill the overall comment');

// A month whose ratings were all stored as blank still has its comment.
$pdo->prepare('INSERT INTO evaluation_cards (student_id, teacher_id, month_year, indicator_id, rating, is_custom_indicator) VALUES (2, 1, ?, ?, ?, 0)')
    ->execute(['Oct-26', 8, 'E']);
$pdo->prepare('INSERT INTO assessment_comments (student_id, teacher_id, month_year, category, comment) VALUES (2, 1, ?, ?, ?)')
    ->execute(['Oct-26', null, 'Keeps trying the pink tower.']);
$wiped = assessment_prefill_from_previous(
    $pdo,
    2,
    'Nov-26',
    false,
    false,
    '',
    [],
    ['MOTOR'],
    $known,
    ['std:8' => true]
);
expect_same($wiped['ratings'], [], 'ratings stored as blank are not carried forward');
expect_same($wiped['overall'], 'Keeps trying the pink tower.', 'the overall comment is still carried when every rating was stored blank');
expect_same($wiped['carried_overall'], true, 'that overall comment is marked carried');
expect_same($wiped['from'], 'Oct-26', 'the comment is carried from the month that has the blank ratings');

$pdo->beginTransaction();
$rejected = false;
try {
    assessment_store_rating($pdo, 1, 1, 'Nov-26', 3, 'E', 0);
} catch (RuntimeException $e) {
    $rejected = str_contains($e->getMessage(), 'E');
}
$pdo->rollBack();
expect_same($rejected, true, 'saving E into the old ENUM fails instead of keeping a blank');
expect_same((int)$pdo->query("SELECT COUNT(*) FROM evaluation_cards WHERE month_year = 'Nov-26'")->fetchColumn(), 0, 'the rejected save leaves no blank row behind');

$before = $pdo->query('SELECT id, indicator_id, month_year, rating FROM evaluation_cards ORDER BY id')->fetchAll();
run_migration($pdo, __DIR__ . '/../sql/migrate_078_evaluation_rating_codes.sql');
$after = $pdo->query('SELECT id, indicator_id, month_year, rating FROM evaluation_cards ORDER BY id')->fetchAll();
expect_same($after, $before, 'the migration does not invent ratings for rows that are already blank');

$type = (string)$pdo->query("
    SELECT COLUMN_TYPE FROM information_schema.columns
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluation_cards' AND COLUMN_NAME = 'rating'
")->fetchColumn();
expect_same($type, "enum('D','P','N','M','E','I')", 'the column lists exactly the configured codes, with the original members first');
expect_same(str_contains($type, "''"), false, 'blank is not added as a rating code');

$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
assessment_store_rating($pdo, 1, 1, 'Nov-26', 4, 'M', 0);
assessment_store_rating($pdo, 1, 1, 'Nov-26', 5, 'E', 0);
assessment_store_rating($pdo, 1, 1, 'Nov-26', 6, 'I', 0);
$stored = $pdo->query("
    SELECT rating FROM evaluation_cards
    WHERE student_id = 1 AND month_year = 'Nov-26' AND indicator_id IN (4, 5, 6)
    ORDER BY indicator_id
")->fetchAll(PDO::FETCH_COLUMN);
expect_same($stored, ['M', 'E', 'I'], 'saving M, E and I in non-strict mode reads back M, E and I');

$blankAfter = (string)$pdo->query("SELECT rating FROM evaluation_cards WHERE id = $blankId")->fetchColumn();
expect_same($blankAfter, '', 'the historical blank row is still blank after a successful M/E/I save');

run_migration($pdo, __DIR__ . '/../sql/migrate_078_evaluation_rating_codes.sql');
$typeAgain = (string)$pdo->query("
    SELECT COLUMN_TYPE FROM information_schema.columns
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluation_cards' AND COLUMN_NAME = 'rating'
")->fetchColumn();
expect_same($typeAgain, $type, 'running the migration twice does not reorder or widen the column again');

// A CHAR column (what schema.sql creates) becomes an ENUM of the configured
// codes plus any non-blank code already stored that the scheme no longer lists.
$pdo->exec('DROP TABLE evaluation_cards');
$pdo->exec('DROP TABLE rating_config');
$pdo->exec("
    CREATE TABLE rating_config (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code CHAR(1) NOT NULL,
        label VARCHAR(60) NOT NULL,
        color VARCHAR(20) NOT NULL,
        numeric_value INT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    )
");
$pdo->exec("
    CREATE TABLE evaluation_cards (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        month_year VARCHAR(8) NOT NULL,
        indicator_id INT UNSIGNED NOT NULL,
        rating CHAR(1) NOT NULL,
        is_custom_indicator TINYINT(1) NOT NULL DEFAULT 0
    )
");
$insCode = $pdo->prepare('INSERT INTO rating_config (code, label, color, numeric_value, is_active) VALUES (?, ?, ?, ?, 1)');
foreach ([['D', 'Developed', 5], ['P', 'Progressing', 3], ['N', 'Needs Attention', 1], ['M', 'Mastered', 5], ['E', 'Emerging', 2], ['I', 'Independent', 4]] as $row) {
    $insCode->execute([$row[0], $row[1], '#000000', $row[2]]);
}
$pdo->exec("INSERT INTO evaluation_cards (student_id, teacher_id, month_year, indicator_id, rating, is_custom_indicator) VALUES (1, 1, 'Mar-26', 1, 'X', 0), (1, 1, 'Mar-26', 2, '', 0)");
run_migration($pdo, __DIR__ . '/../sql/migrate_078_evaluation_rating_codes.sql');
$charType = (string)$pdo->query("
    SELECT COLUMN_TYPE FROM information_schema.columns
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluation_cards' AND COLUMN_NAME = 'rating'
")->fetchColumn();
expect_same($charType, "enum('D','P','N','M','E','I','X')", 'a retired code still stored on an old assessment stays storable');
expect_same(
    $pdo->query("SELECT rating FROM evaluation_cards ORDER BY indicator_id")->fetchAll(PDO::FETCH_COLUMN),
    ['X', ''],
    'converting the column keeps the retired code and the blank'
);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);

/** Apply one migrate_*.sql file the same way migrate.php does. */
function run_migration(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read $path");
    }
    $delim = ';';
    $buf = '';
    foreach (preg_split("/\r\n|\n|\r/", $sql) as $line) {
        $trim = trim($line);
        if (stripos($trim, 'DELIMITER ') === 0) {
            if (trim($buf) !== '') {
                $pdo->exec($buf);
                $buf = '';
            }
            $delim = trim(substr($trim, 10));
            continue;
        }
        $buf .= $line . "\n";
        $ended = $delim !== ';'
            ? str_ends_with(rtrim($line), $delim)
            : str_ends_with(rtrim($line), ';');
        if (!$ended) {
            continue;
        }
        $body = rtrim($buf);
        if ($delim !== ';') {
            $body = substr($body, 0, strlen($body) - strlen($delim));
        }
        if (trim($body) !== '') {
            $pdo->exec($body);
        }
        $buf = '';
    }
    if (trim($buf) !== '') {
        $pdo->exec($buf);
    }
}
