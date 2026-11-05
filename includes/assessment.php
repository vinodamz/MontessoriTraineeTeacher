<?php
/**
 * Monthly assessment ratings and the "start from last month" prefill.
 *
 * Rating codes come from rating_config (Admin → Rating scheme). The save
 * path must not record a blank when a teacher picked a real code, and a new
 * month's form copies the previous month's comments the same way it copies
 * ratings: onto the form only, nothing is written until Save.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Split a posted rating list into codes that can be stored and codes that
 * are not in the rating scheme.
 *
 * Blank values are dropped. They are not an error and they are not inserted:
 * an unrated skill simply has no row. A non-blank code that the scheme does
 * not know is returned in `invalid` so the save can stop instead of quietly
 * losing it.
 *
 * @param array<mixed,mixed> $posted
 * @param array<string,mixed> $knownCodes code => anything, active and retired
 * @return array{ok: array<string,string>, invalid: list<string>}
 */
function assessment_ratings_for_save(array $posted, array $knownCodes): array
{
    $ok = [];
    $invalid = [];
    foreach ($posted as $key => $val) {
        if (!is_string($key) && !is_int($key)) {
            continue;
        }
        if (!preg_match('/^(std|cust):(\d+)$/', (string)$key)) {
            continue;
        }
        $val = trim((string)$val);
        if ($val === '') {
            continue;
        }
        if (!isset($knownCodes[$val])) {
            $invalid[] = $val;
            continue;
        }
        $ok[(string)$key] = $val;
    }
    return ['ok' => $ok, 'invalid' => $invalid];
}

/**
 * Insert one rating and refuse to leave a blank behind.
 *
 * In non-strict sql_mode an ENUM that does not list the code stores '' and
 * reports success. The read-back turns that into an exception so the caller
 * can roll the whole month back.
 */
function assessment_store_rating(PDO $pdo, int $studentId, int $teacherId, string $month, int $indicatorId, string $rating, int $isCustom): void
{
    if (!preg_match('/^[A-Za-z0-9]$/', $rating)) {
        throw new RuntimeException('A rating on this form is not one of the configured codes, so nothing was saved.');
    }
    $ins = $pdo->prepare("
        INSERT INTO evaluation_cards
            (student_id, teacher_id, month_year, indicator_id, rating, is_custom_indicator)
        VALUES (:s, :t, :m, :i, :r, :c)
    ");
    $ins->execute([
        ':s' => $studentId,
        ':t' => $teacherId,
        ':m' => $month,
        ':i' => $indicatorId,
        ':r' => $rating,
        ':c' => $isCustom,
    ]);
    $check = $pdo->prepare("
        SELECT rating FROM evaluation_cards
        WHERE student_id = :s AND teacher_id = :t AND month_year = :m
          AND indicator_id = :i AND is_custom_indicator = :c
        ORDER BY id DESC
        LIMIT 1
    ");
    $check->execute([
        ':s' => $studentId,
        ':t' => $teacherId,
        ':m' => $month,
        ':i' => $indicatorId,
        ':c' => $isCustom,
    ]);
    $stored = $check->fetchColumn();
    if ($stored !== $rating) {
        throw new RuntimeException('The rating ' . $rating . ' could not be stored, so nothing was saved.');
    }
}

/** Teacher-facing save error. A truncated rating must not look like a success. */
function assessment_save_failure_message(Throwable $e): string
{
    if ($e instanceof PDOException && $e->getCode() == 23000) {
        return 'Someone else saved this month at the same moment — reload the page and check their entries before saving again.';
    }
    $detail = $e->getMessage();
    if ($e instanceof PDOException && (str_contains($detail, 'Data truncated') || str_contains($detail, '1265'))) {
        return 'A rating could not be stored, so nothing was saved.';
    }
    if ($e instanceof RuntimeException) {
        return $detail;
    }
    return 'Save failed: ' . $detail;
}

function assessment_restore_sql_mode(PDO $pdo, ?string $previousMode): void
{
    if ($previousMode === null) {
        return;
    }
    try {
        $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($previousMode));
    } catch (Throwable $e) {
        // The save already finished or rolled back. Losing the mode restore
        // must not hide that result.
    }
}

/**
 * Latest month strictly before $current. month_year is 'M-y' text, so this
 * uses the calendar comparison rather than string order.
 *
 * @param list<string> $months
 */
function assessment_previous_month(array $months, string $current): ?string
{
    $prior = [];
    foreach ($months as $month) {
        if (compare_month_year((string)$month, $current) < 0) {
            $prior[] = (string)$month;
        }
    }
    if ($prior === []) {
        return null;
    }
    usort($prior, 'compare_month_year');
    return $prior[array_key_last($prior)];
}

/**
 * @return array{overall: string, categories: array<string,string>}
 */
function assessment_load_month_comments(PDO $pdo, int $studentId, string $month): array
{
    $overall = '';
    $categories = [];
    $stmt = $pdo->prepare('
        SELECT category, comment
        FROM assessment_comments
        WHERE student_id = :s AND month_year = :m
    ');
    $stmt->execute([':s' => $studentId, ':m' => $month]);
    foreach ($stmt as $row) {
        if ($row['category'] === null) {
            $overall = (string)$row['comment'];
        } else {
            $categories[(string)$row['category']] = (string)$row['comment'];
        }
    }
    return ['overall' => $overall, 'categories' => $categories];
}

/**
 * Fill empty comment boxes from the previous month. A comment already typed
 * for this month wins. Categories that are not on this form are ignored, so
 * a retired area is not silently saved back.
 *
 * @param array<string,string> $currentCategories
 * @param array<string,string> $priorCategories
 * @param list<string> $visibleCategories
 * @return array{overall: string, categories: array<string,string>, carried_overall: bool, carried_categories: array<string,bool>}
 */
function assessment_carry_comments(
    string $currentOverall,
    array $currentCategories,
    string $priorOverall,
    array $priorCategories,
    array $visibleCategories
): array {
    $overall = $currentOverall;
    $carriedOverall = false;
    if (trim($currentOverall) === '' && trim($priorOverall) !== '') {
        $overall = $priorOverall;
        $carriedOverall = true;
    }

    $categories = $currentCategories;
    $carriedCategories = [];
    foreach ($visibleCategories as $cat) {
        $cat = (string)$cat;
        if (trim((string)($categories[$cat] ?? '')) !== '') {
            continue;
        }
        $prior = (string)($priorCategories[$cat] ?? '');
        if (trim($prior) === '') {
            continue;
        }
        $categories[$cat] = $prior;
        $carriedCategories[$cat] = true;
    }

    return [
        'overall' => $overall,
        'categories' => $categories,
        'carried_overall' => $carriedOverall,
        'carried_categories' => $carriedCategories,
    ];
}

/**
 * Ratings and comments to show on a month that has not been saved yet.
 * Nothing is written. `blank` and a month that already has ratings both
 * return the current comments unchanged.
 *
 * @param array<string,string> $currentCategories
 * @param list<string> $visibleCategories
 * @param array<string,mixed> $activeCodes
 * @param array<string,mixed> $activeIndicatorKeys
 * @return array{
 *   ratings: array<string,string>,
 *   from: ?string,
 *   overall: string,
 *   categories: array<string,string>,
 *   carried_overall: bool,
 *   carried_categories: array<string,bool>
 * }
 */
function assessment_prefill_from_previous(
    PDO $pdo,
    int $studentId,
    string $month,
    bool $blank,
    bool $monthHasRatings,
    string $currentOverall,
    array $currentCategories,
    array $visibleCategories,
    array $activeCodes,
    array $activeIndicatorKeys
): array {
    $empty = [
        'ratings' => [],
        'from' => null,
        'overall' => $currentOverall,
        'categories' => $currentCategories,
        'carried_overall' => false,
        'carried_categories' => [],
    ];
    if ($blank || $monthHasRatings) {
        return $empty;
    }

    $stmt = $pdo->prepare('SELECT DISTINCT month_year FROM evaluation_cards WHERE student_id = :s');
    $stmt->execute([':s' => $studentId]);
    $months = [];
    foreach ($stmt as $row) {
        $months[] = (string)$row['month_year'];
    }
    $from = assessment_previous_month($months, $month);
    if ($from === null) {
        return $empty;
    }

    $stmt = $pdo->prepare('
        SELECT indicator_id, rating, is_custom_indicator
        FROM evaluation_cards
        WHERE student_id = :s AND month_year = :m
    ');
    $stmt->execute([':s' => $studentId, ':m' => $from]);
    $ratings = [];
    foreach ($stmt as $row) {
        $key = ((int)$row['is_custom_indicator'] === 1 ? 'cust' : 'std') . ':' . $row['indicator_id'];
        if (!isset($activeIndicatorKeys[$key])) {
            continue;
        }
        if (!isset($activeCodes[$row['rating']])) {
            continue;
        }
        $ratings[$key] = (string)$row['rating'];
    }

    $prior = assessment_load_month_comments($pdo, $studentId, $from);
    $comments = assessment_carry_comments(
        $currentOverall,
        $currentCategories,
        $prior['overall'],
        $prior['categories'],
        $visibleCategories
    );
    $anyComment = $comments['carried_overall'] || $comments['carried_categories'] !== [];
    if ($ratings === [] && !$anyComment) {
        return $empty;
    }

    return [
        'ratings' => $ratings,
        'from' => $from,
        'overall' => $comments['overall'],
        'categories' => $comments['categories'],
        'carried_overall' => $comments['carried_overall'],
        'carried_categories' => $comments['carried_categories'],
    ];
}
