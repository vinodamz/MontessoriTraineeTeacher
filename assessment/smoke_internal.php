<?php
/**
 * assessment/smoke_internal.php — master-spec assertions for the Montessori
 * assessment module. Same pattern as tasks/smoke_internal.php: IP-gated over
 * HTTP (cPanel deploy curls it from 127.0.0.1) and also runnable from CLI as
 * the cPanel deploy account.
 *
 * Asserts:
 *   - Schema: evaluation_cards (uq_ec) + assessments (uq_a) +
 *     assessment_comments + student_baselines + rating_config +
 *     skill_indicators all present with the expected fields.
 *   - Save flow: eval cards + per-category assessment averages written the
 *     way assess.php's POST writes them re-read by (student_id, month_year)
 *     WITHOUT teacher_id — the month is the unit of record.
 *   - Month-scoped overwrite: a second save by a different teacher replaces
 *     (never duplicates) the month's (student, month, category) rows.
 *   - Retired indicator survival: deactivating an indicator keeps the rated
 *     row, and the POST-side lookup (grade-scoped, no is_active filter)
 *     still resolves it so a re-save can't silently drop the rating.
 *   - Rating clamp: every rating_config.numeric_value sits inside 1..5.
 *   - Month normalization: 'jun-25' parses and re-formats to 'Jun-25', so
 *     one calendar month can't exist under two different string keys.
 *   - Active-list filter: the dashboard WHERE from assessment/index.php
 *     excludes withdrawn/inactive students and keeps enrolled ones.
 *
 * Test rows all use 'SMOKE-' prefix in their name/text fields and are
 * hard-deleted in the finally block (smoke artifacts, never real data;
 * prefix guard makes the DELETEs incapable of touching real rows —
 * deleting the students cascades their cards/assessments/comments).
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Override the global friendly-error handler so CLI / loopback runs surface
// fatals as FAIL lines instead of exiting silently (errors.php's handler
// returns early in CLI mode, which earlier deploys swallowed mid-smoke).
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e): void {
    echo "FAIL — uncaught " . get_class($e) . "\n";
    echo "  - " . $e->getMessage() . "\n";
    echo "  - " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null
        && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "FAIL — fatal\n";
        echo "  - " . $err['message'] . "\n";
        echo "  - " . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?') . "\n";
    }
});

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
echo "BEGIN assessment smoke (sapi=" . PHP_SAPI . ")\n";

$failures            = [];
$createdUserIds      = [];
$createdStudentIds   = [];
$createdIndicatorIds = [];

/**
 * Replay assess.php's POST body exactly: month-scoped delete of the prior
 * cards/assessments/comments, then fresh inserts + per-category averages.
 * $ratings has the form ['std:NN' => 'M', ...] like the form posts it.
 */
function smoke_save_assessment(int $studentId, string $grade, int $teacherId, string $month, array $ratings): void
{
    $rmap = rating_config_map();

    // Group ratings by category (we need to know each indicator's category).
    $stdIds = [];
    foreach ($ratings as $key => $val) {
        if (!preg_match('/^(std|cust):(\d+)$/', $key, $m)) continue;
        if (!isset($rmap[$val])) continue;
        if ($m[1] === 'std') $stdIds[] = (int)$m[2];
    }

    $indicatorCat = [];
    if ($stdIds) {
        // The exact POST-side lookup from assess.php: grade-restricted,
        // deliberately NOT restricted to is_active.
        $place = implode(',', array_fill(0, count($stdIds), '?'));
        $rows  = db()->prepare("SELECT id, category FROM skill_indicators WHERE id IN ($place) AND grade = ?");
        $params = $stdIds;
        $params[] = $grade;
        $rows->execute($params);
        foreach ($rows as $r) $indicatorCat['std:' . $r['id']] = $r['category'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Month-scoped (not teacher-scoped): the previous assessor's rows are
        // replaced, not duplicated under a second teacher_id.
        $del = $pdo->prepare("DELETE FROM evaluation_cards   WHERE student_id=:s AND month_year=:m");
        $del->execute([':s' => $studentId, ':m' => $month]);
        $del = $pdo->prepare("DELETE FROM assessments        WHERE student_id=:s AND month_year=:m");
        $del->execute([':s' => $studentId, ':m' => $month]);
        $del = $pdo->prepare("DELETE FROM assessment_comments WHERE student_id=:s AND month_year=:m");
        $del->execute([':s' => $studentId, ':m' => $month]);

        $ins = $pdo->prepare("
            INSERT INTO evaluation_cards
                (student_id, teacher_id, month_year, indicator_id, rating, is_custom_indicator)
            VALUES (:s, :t, :m, :i, :r, :c)
        ");
        $catTotals = []; // [cat => [sum, count]]
        foreach ($ratings as $key => $val) {
            if (!isset($indicatorCat[$key])) continue;
            if (!isset($rmap[$val]))         continue;
            [, $idStr] = explode(':', $key, 2);
            $ins->execute([
                ':s' => $studentId,
                ':t' => $teacherId,
                ':m' => $month,
                ':i' => (int)$idStr,
                ':r' => $val,
                ':c' => 0,
            ]);
            $cat = $indicatorCat[$key];
            $catTotals[$cat] ??= [0, 0];
            $catTotals[$cat][0] += (int)$rmap[$val]['numeric_value'];
            $catTotals[$cat][1] += 1;
        }

        $insA = $pdo->prepare("
            INSERT INTO assessments
                (student_id, teacher_id, month_year, category, score, category_avg)
            VALUES (:s, :t, :m, :c, :sc, :av)
        ");
        foreach ($catTotals as $cat => [$sum, $n]) {
            if ($n === 0) continue;
            $avg = $sum / $n;
            $insA->execute([
                ':s'  => $studentId,
                ':t'  => $teacherId,
                ':m'  => $month,
                ':c'  => $cat,
                ':sc' => (int)round($avg),
                ':av' => number_format($avg, 2, '.', ''),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

try {
    // ---- 1. Schema -------------------------------------------------------
    foreach ([
        'evaluation_cards'    => ['student_id', 'teacher_id', 'month_year', 'indicator_id', 'rating', 'is_custom_indicator'],
        'assessments'         => ['student_id', 'teacher_id', 'month_year', 'category', 'score', 'category_avg'],
        'assessment_comments' => ['student_id', 'teacher_id', 'month_year', 'category', 'comment'],
        'student_baselines'   => ['student_id', 'teacher_id', 'recorded_by', 'recorded_at'],
        'rating_config'       => ['code', 'label', 'color', 'numeric_value', 'is_active'],
        'skill_indicators'    => ['grade', 'category', 'indicator_text', 'display_order', 'is_active'],
    ] as $table => $required) {
        $cols = [];
        try {
            foreach (db()->query("SHOW COLUMNS FROM `$table`") as $r) $cols[] = $r['Field'];
        } catch (Throwable $e) { $failures[] = "schema missing table $table"; continue; }
        foreach ($required as $c) {
            if (!in_array($c, $cols, true)) $failures[] = "schema missing column $table.$c";
        }
    }
    foreach (['evaluation_cards' => 'uq_ec', 'assessments' => 'uq_a'] as $table => $key) {
        $names = [];
        try {
            foreach (db()->query("SHOW INDEX FROM `$table`") as $r) $names[] = $r['Key_name'];
        } catch (Throwable $e) { $names = []; }
        if (!in_array($key, $names, true)) $failures[] = "schema missing unique key $table.$key";
    }

    // ---- 2. Fixtures: SMOKE- teachers, students, indicators ---------------
    $ts    = time() . '-' . bin2hex(random_bytes(2));
    $grade = 'Nursery';
    $month = 'Jun-25';

    $insU = db()->prepare("INSERT INTO users (name, pin_hash, role, modules, active) VALUES (:n, :p, 'teacher', 'montessori', 1)");
    $insU->execute([':n' => "SMOKE-T1-$ts", ':p' => password_hash("smoke-$ts-1", PASSWORD_DEFAULT)]);
    $teacher1 = (int)db()->lastInsertId();
    $createdUserIds[] = $teacher1;
    $insU->execute([':n' => "SMOKE-T2-$ts", ':p' => password_hash("smoke-$ts-2", PASSWORD_DEFAULT)]);
    $teacher2 = (int)db()->lastInsertId();
    $createdUserIds[] = $teacher2;

    $insS = db()->prepare("
        INSERT INTO students (first_name, last_name, grade, teacher_id, is_active, enrollment_status)
        VALUES (:f, 'Smoke', :g, :t, :a, :e)
    ");
    $insS->execute([':f' => "SMOKE-S1-$ts", ':g' => $grade, ':t' => $teacher1, ':a' => 1, ':e' => 'enrolled']);
    $studentId = (int)db()->lastInsertId();
    $createdStudentIds[] = $studentId;
    $insS->execute([':f' => "SMOKE-S2-$ts", ':g' => $grade, ':t' => $teacher1, ':a' => 0, ':e' => 'withdrawn']);
    $withdrawnId = (int)db()->lastInsertId();
    $createdStudentIds[] = $withdrawnId;

    $insI = db()->prepare("
        INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active)
        VALUES (:g, :c, :t, :o, 1)
    ");
    $insI->execute([':g' => $grade, ':c' => "SMOKE-CAT-$ts", ':t' => "SMOKE-IND1-$ts", ':o' => 1]);
    $ind1 = (int)db()->lastInsertId();
    $createdIndicatorIds[] = $ind1;
    $insI->execute([':g' => $grade, ':c' => "SMOKE-CAT-$ts", ':t' => "SMOKE-IND2-$ts", ':o' => 2]);
    $ind2 = (int)db()->lastInsertId();
    $createdIndicatorIds[] = $ind2;

    // ---- 3. Save flow: write like assess.php, re-read by (student, month) -
    $rmap  = rating_config_map();
    $codes = rating_codes();                 // highest numeric_value first
    if (count($codes) < 2) $failures[] = "rating_config has fewer than 2 active codes";
    $hi = $codes[0];
    $lo = $codes[count($codes) - 1];
    smoke_save_assessment($studentId, $grade, $teacher1, $month, [
        "std:$ind1" => $hi,
        "std:$ind2" => $lo,
    ]);

    // Re-read the way assess.php's pre-fill does: (student_id, month_year),
    // no teacher_id — the month is the unit of record.
    $q = db()->prepare("SELECT indicator_id, rating, teacher_id FROM evaluation_cards WHERE student_id = :s AND month_year = :m");
    $q->execute([':s' => $studentId, ':m' => $month]);
    $cards = [];
    foreach ($q as $r) $cards[(int)$r['indicator_id']] = $r;
    if (count($cards) !== 2)                        $failures[] = "save flow: expected 2 eval cards for (student,month), got " . count($cards);
    if (($cards[$ind1]['rating'] ?? '') !== $hi)    $failures[] = "save flow: indicator 1 rating not re-read as '$hi'";
    if (($cards[$ind2]['rating'] ?? '') !== $lo)    $failures[] = "save flow: indicator 2 rating not re-read as '$lo'";

    $q = db()->prepare("SELECT category, score, category_avg, teacher_id FROM assessments WHERE student_id = :s AND month_year = :m");
    $q->execute([':s' => $studentId, ':m' => $month]);
    $arows = $q->fetchAll();
    $expectedAvg = ((int)$rmap[$hi]['numeric_value'] + (int)$rmap[$lo]['numeric_value']) / 2;
    if (count($arows) !== 1) {
        $failures[] = "save flow: expected 1 assessments row (one category), got " . count($arows);
    } else {
        if ($arows[0]['category'] !== "SMOKE-CAT-$ts")                       $failures[] = "save flow: assessments row has wrong category";
        if (abs((float)$arows[0]['category_avg'] - $expectedAvg) > 0.001)    $failures[] = "save flow: category_avg wrong: got " . $arows[0]['category_avg'] . ", want $expectedAvg";
        if ((int)$arows[0]['score'] !== (int)round($expectedAvg))            $failures[] = "save flow: score wrong: got " . $arows[0]['score'];
        if ((int)$arows[0]['teacher_id'] !== $teacher1)                      $failures[] = "save flow: assessments row not attributed to teacher 1";
    }

    // ---- 4. Month-scoped overwrite by a DIFFERENT teacher ------------------
    smoke_save_assessment($studentId, $grade, $teacher2, $month, [
        "std:$ind1" => $lo,
        "std:$ind2" => $lo,
    ]);
    $q = db()->prepare("
        SELECT month_year, category, COUNT(*) AS n
        FROM assessments
        WHERE student_id = :s
        GROUP BY month_year, category
        HAVING n > 1
    ");
    $q->execute([':s' => $studentId]);
    if ($q->fetchAll()) $failures[] = "overwrite: duplicate (student,month,category) assessments rows after second-teacher save";
    $q = db()->prepare("SELECT DISTINCT teacher_id FROM assessments WHERE student_id = :s AND month_year = :m");
    $q->execute([':s' => $studentId, ':m' => $month]);
    $tids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($tids) !== 1 || (int)$tids[0] !== $teacher2) $failures[] = "overwrite: month's assessments not owned by the second teacher after re-save";
    $q = db()->prepare("SELECT COUNT(*) FROM evaluation_cards WHERE student_id = :s AND month_year = :m");
    $q->execute([':s' => $studentId, ':m' => $month]);
    if ((int)$q->fetchColumn() !== 2) $failures[] = "overwrite: eval cards duplicated or lost after second-teacher save";

    // ---- 5. Retired indicator survives -------------------------------------
    db()->prepare("UPDATE skill_indicators SET is_active = 0 WHERE id = :id AND indicator_text LIKE 'SMOKE-%'")
        ->execute([':id' => $ind1]);
    $q = db()->prepare("SELECT COUNT(*) FROM evaluation_cards WHERE student_id = :s AND month_year = :m AND indicator_id = :i AND is_custom_indicator = 0");
    $q->execute([':s' => $studentId, ':m' => $month, ':i' => $ind1]);
    if ((int)$q->fetchColumn() !== 1) $failures[] = "retired: rated row vanished after indicator deactivation";
    // The POST-side lookup used in assess.php (grade-scoped, deliberately
    // NOT filtered on is_active) must still resolve the retired indicator.
    $q = db()->prepare("SELECT id, category FROM skill_indicators WHERE id IN (?) AND grade = ?");
    $q->execute([$ind1, $grade]);
    $row = $q->fetch();
    if (!$row || (int)$row['id'] !== $ind1) $failures[] = "retired: POST-side indicator lookup dropped the deactivated indicator";
    // ...while the GET-side form query (is_active = 1) hides it — that's the
    // pairing the retired-indicator render path in assess.php exists for.
    $q = db()->prepare("SELECT COUNT(*) FROM skill_indicators WHERE id = :id AND grade = :g AND is_active = 1");
    $q->execute([':id' => $ind1, ':g' => $grade]);
    if ((int)$q->fetchColumn() !== 0) $failures[] = "retired: is_active flag did not stick";

    // ---- 6. Rating clamp ----------------------------------------------------
    $bad = (int)db()->query("SELECT COUNT(*) FROM rating_config WHERE numeric_value < 1 OR numeric_value > 5")->fetchColumn();
    if ($bad !== 0) $failures[] = "rating clamp: $bad rating_config row(s) with numeric_value outside 1..5";
    foreach ($rmap as $code => $r) {
        $v = (int)$r['numeric_value'];
        if ($v < 1 || $v > 5) $failures[] = "rating clamp: active code '$code' has numeric_value $v outside 1..5";
    }

    // ---- 7. Month normalization ---------------------------------------------
    $dt = DateTime::createFromFormat('M-y', 'jun-25');
    if ($dt === false || $dt->format('M-y') !== 'Jun-25') {
        $failures[] = "month normalization: 'jun-25' did not normalise to 'Jun-25'";
    }
    if (!in_array('Jun-25', academic_months(2025), true)) {
        $failures[] = "month normalization: 'Jun-25' missing from academic_months(2025)";
    }

    // ---- 8. Active-list filter (dashboard WHERE from assessment/index.php) --
    $activeWhere = "s.is_active = 1 AND s.enrollment_status IN ('enrolled','promoted')";
    $q = db()->prepare("
        SELECT s.id, s.first_name, s.last_name, s.grade, s.teacher_id
        FROM students s
        WHERE s.teacher_id = :tid AND $activeWhere
        ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name
    ");
    $q->execute([':tid' => $teacher1]);
    $ids = array_map('intval', array_column($q->fetchAll(), 'id'));
    if (!in_array($studentId, $ids, true))   $failures[] = "active filter: enrolled active student missing from dashboard list";
    if (in_array($withdrawnId, $ids, true))  $failures[] = "active filter: withdrawn inactive student leaked into dashboard list";

} finally {
    // Hard-delete every SMOKE-* row we created. Students go first (their
    // cards/assessments/comments cascade), then indicators, then the users
    // the students referenced. Prefix guard makes this safe.
    foreach ($createdStudentIds as $sid) {
        try {
            db()->prepare("DELETE FROM students WHERE id = :id AND first_name LIKE 'SMOKE-%'")
                ->execute([':id' => $sid]);
        } catch (Throwable $e) { /* leave residue; admin can clean up */ }
    }
    foreach ($createdIndicatorIds as $iid) {
        try {
            db()->prepare("DELETE FROM skill_indicators WHERE id = :id AND indicator_text LIKE 'SMOKE-%'")
                ->execute([':id' => $iid]);
        } catch (Throwable $e) { /* leave residue; admin can clean up */ }
    }
    foreach ($createdUserIds as $uid) {
        try {
            db()->prepare("DELETE FROM users WHERE id = :id AND name LIKE 'SMOKE-%'")
                ->execute([':id' => $uid]);
        } catch (Throwable $e) { /* leave residue; admin can clean up */ }
    }
}

if ($failures) {
    http_response_code(500);
    echo "FAIL — " . count($failures) . " assertion(s) failed\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}

$mode = $isCli ? 'CLI (data layer)' : 'HTTP loopback (data layer)';
echo "PASS — assessment module criteria verified on the live app ($mode)\n";
echo "  - schema has evaluation_cards (uq_ec) + assessments (uq_a) + comments + baselines + rating_config + skill_indicators\n";
echo "  - save writes eval cards + category averages readable by (student, month) without teacher_id\n";
echo "  - a different teacher's re-save replaces the month — no duplicate (student,month,category) rows\n";
echo "  - retiring an indicator keeps the rated row and the POST-side lookup still resolves it\n";
echo "  - every rating_config.numeric_value is within 1..5\n";
echo "  - 'jun-25' normalises to 'Jun-25' and sits in the academic-months list\n";
echo "  - dashboard active-list WHERE keeps enrolled students and drops withdrawn ones\n";
