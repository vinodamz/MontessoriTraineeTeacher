<?php
/**
 * students/withdrawals.php — drop-out analytics.
 *
 * Counts students who left (withdrawn / graduated / on_break) grouped by
 * reason × grade × academic_year, with a horizontal bar chart in pure CSS
 * (no JS lib). The point: spot patterns. If 5 of 10 leavers cite "distance"
 * you have a concrete problem to fix.
 *
 *   GET ?year=YYYY-YY  → restrict to one academic year (defaults to all).
 *   GET ?reason=XYZ    → drill into one reason → list the actual students.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    http_response_code(403);
    echo 'Forbidden — withdrawal analytics require the students module.';
    exit;
}

$VALID_GRADES = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$STATUSES_LEFT = ['withdrawn', 'graduated', 'on_break'];

$availableYears = academic_years_in_use();
$year = $_GET['year'] ?? '';
if ($year !== '' && !in_array($year, $availableYears, true)) $year = '';

$reasonDrill = $_GET['reason'] ?? '';
if ($reasonDrill !== '' && !array_key_exists($reasonDrill, WITHDRAWAL_REASONS)) $reasonDrill = '';

// Counts by reason × grade × year.
$where  = ['s.enrollment_status IN (\'withdrawn\',\'graduated\',\'on_break\')'];
$params = [];
if ($year !== '') {
    $where[] = "s.academic_year = :year";
    $params[':year'] = $year;
}

$sql = "
    SELECT
        s.enrollment_status        AS status,
        COALESCE(s.withdrawal_reason, 'other') AS reason,
        s.grade                    AS grade,
        s.academic_year            AS year,
        COUNT(*)                   AS n
    FROM students s
    WHERE " . implode(' AND ', $where) . "
    GROUP BY status, reason, grade, year
    ORDER BY n DESC
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Aggregate by reason, by grade, by status, by year.
$byReason = []; $byGrade = []; $byStatus = []; $byYear = [];
$totalLeft = 0;
foreach ($rows as $r) {
    $n      = (int)$r['n'];
    $totalLeft += $n;
    $byReason[$r['reason']] = ($byReason[$r['reason']] ?? 0) + $n;
    $byGrade[$r['grade']]   = ($byGrade[$r['grade']]   ?? 0) + $n;
    $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + $n;
    $byYear[$r['year']]     = ($byYear[$r['year']]     ?? 0) + $n;
}
arsort($byReason);
arsort($byYear);

// If drill-down requested, fetch the matching students.
$drillStudents = [];
if ($reasonDrill !== '') {
    $w2  = ['s.enrollment_status IN (\'withdrawn\',\'graduated\',\'on_break\')', 's.withdrawal_reason = :r'];
    $p2  = [':r' => $reasonDrill];
    if ($year !== '') { $w2[] = 's.academic_year = :year'; $p2[':year'] = $year; }
    $d = db()->prepare("
        SELECT s.id, s.first_name, s.last_name, s.grade, s.academic_year,
               s.enrollment_status, s.withdrawal_date, s.withdrawal_notes,
               u.name AS teacher_name
        FROM students s
        LEFT JOIN users u ON u.id = s.teacher_id
        WHERE " . implode(' AND ', $w2) . "
        ORDER BY s.withdrawal_date DESC, s.first_name
    ");
    $d->execute($p2);
    $drillStudents = $d->fetchAll();
}

function pct(int $n, int $total): int
{
    return $total > 0 ? (int)round(($n / $total) * 100) : 0;
}

$pageTitle = 'Withdrawals · why students left';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Withdrawal analytics</h1>
        <p class="muted">
            <?= (int)$totalLeft ?> student<?= $totalLeft === 1 ? '' : 's' ?> left
            <?php if ($year !== ''): ?> in <strong><?= e($year) ?></strong><?php else: ?> (all years)<?php endif; ?>
            <?php foreach ($byStatus as $st => $n): ?>
                · <span class="pill enr-<?= e($st) ?>"><?= e(enrollment_status_label($st)) ?>: <?= (int)$n ?></span>
            <?php endforeach; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="year">Academic year</label>
        <select id="year" name="year">
            <option value="">All years</option>
            <?php foreach ($availableYears as $y): ?>
                <option value="<?= e($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= e($y) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-ghost" href="/students/withdrawals.php">Reset</a>
    </div>
</form>

<?php if (!$totalLeft): ?>
    <div class="empty"><p>No withdrawals on record yet.</p></div>
<?php else: ?>
    <section class="card">
        <h2>By reason</h2>
        <?php $maxReason = max($byReason); foreach ($byReason as $reason => $n): ?>
            <div class="bar-row">
                <a href="?year=<?= e($year) ?>&reason=<?= e($reason) ?>" class="bar-label">
                    <?= e(withdrawal_reason_label($reason)) ?>
                </a>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?= pct($n, $maxReason) ?>%;"></div>
                </div>
                <span class="bar-count"><?= (int)$n ?> · <?= pct($n, $totalLeft) ?>%</span>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="ye-grid-2">
        <section class="card">
            <h2>By grade</h2>
            <?php $maxG = $byGrade ? max($byGrade) : 0; foreach ($VALID_GRADES as $g): if (empty($byGrade[$g])) continue; $n = $byGrade[$g]; ?>
                <div class="bar-row">
                    <span class="bar-label"><span class="<?= e(grade_badge_class($g)) ?>"><?= e($g) ?></span></span>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= pct($n, $maxG) ?>%;"></div>
                    </div>
                    <span class="bar-count"><?= (int)$n ?></span>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="card">
            <h2>By year</h2>
            <?php $maxY = $byYear ? max($byYear) : 0; foreach ($byYear as $y => $n): ?>
                <div class="bar-row">
                    <a href="?year=<?= e($y) ?>" class="bar-label"><?= e($y) ?></a>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= pct($n, $maxY) ?>%;"></div>
                    </div>
                    <span class="bar-count"><?= (int)$n ?></span>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <?php if ($reasonDrill !== ''): ?>
        <section class="card section-h-spaced">
            <div class="page-head" style="margin: 0 0 .5rem;">
                <h2 style="margin:0;">Students who left — <?= e(withdrawal_reason_label($reasonDrill)) ?></h2>
                <a class="btn btn-ghost" href="?year=<?= e($year) ?>">Clear drill-down</a>
            </div>
            <?php if (!$drillStudents): ?>
                <p class="muted">No matches.</p>
            <?php else: ?>
                <table class="att-summary">
                    <thead><tr><th>Name</th><th>Year</th><th>Grade</th><th>Status</th><th>Date</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($drillStudents as $s):
                            $full = trim($s['first_name'] . ' ' . $s['last_name']);
                        ?>
                            <tr>
                                <td><a href="/students/view.php?id=<?= (int)$s['id'] ?>"><?= e($full) ?></a></td>
                                <td><?= e($s['academic_year'] ?? '') ?></td>
                                <td><span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span></td>
                                <td><span class="pill enr-<?= e($s['enrollment_status']) ?>"><?= e(enrollment_status_label($s['enrollment_status'])) ?></span></td>
                                <td><?= e($s['withdrawal_date'] ?? '') ?></td>
                                <td class="muted small"><?= e($s['withdrawal_notes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
