<?php
/**
 * data_check.php — assessment data health, admin-only.
 *
 * Read-only diagnostic for "I can't see my old assessments": shows exactly
 * what's in the DB per month, which rating codes the historical cards use
 * (vs what's configured/active), and which students the dashboard's default
 * filter hides. Nothing here mutates data.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_admin();

// Per-month totals across all students.
$byMonth = db()->query("
    SELECT month_year,
           COUNT(*)                    AS cards,
           COUNT(DISTINCT student_id)  AS students,
           COUNT(DISTINCT teacher_id)  AS teachers
    FROM evaluation_cards
    GROUP BY month_year
")->fetchAll();
usort($byMonth, fn($a, $b) => compare_month_year($a['month_year'], $b['month_year']));

$avgRows = [];
foreach (db()->query("SELECT month_year, COUNT(*) AS n FROM assessments GROUP BY month_year") as $r) {
    $avgRows[$r['month_year']] = (int)$r['n'];
}
$comRows = [];
foreach (db()->query("SELECT month_year, COUNT(*) AS n FROM assessment_comments GROUP BY month_year") as $r) {
    $comRows[$r['month_year']] = (int)$r['n'];
}

// Rating codes actually used on cards vs the configured scheme.
$usedCodes = db()->query("
    SELECT rating, COUNT(*) AS n FROM evaluation_cards GROUP BY rating ORDER BY n DESC
")->fetchAll();
$configured = rating_config_map_all();

// Students hidden by the dashboard's default active filter.
$hidden = db()->query("
    SELECT s.id, s.first_name, s.last_name, s.grade, s.is_active, s.enrollment_status,
           (SELECT COUNT(DISTINCT ec.month_year) FROM evaluation_cards ec WHERE ec.student_id = s.id) AS months
    FROM students s
    WHERE NOT (s.is_active = 1 AND s.enrollment_status IN ('enrolled','promoted'))
    ORDER BY s.first_name
")->fetchAll();

$totalCards    = array_sum(array_column($byMonth, 'cards'));
$totalStudents = (int)db()->query("SELECT COUNT(*) FROM students")->fetchColumn();

$pageTitle = 'Assessment data health';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Assessment data health</h1>
        <p class="muted">Read-only. <?= $totalCards ?> ratings on file across <?= count($byMonth) ?> month<?= count($byMonth) === 1 ? '' : 's' ?> · <?= $totalStudents ?> students in the system.</p>
    </div>
    <div class="head-actions"><a class="btn btn-ghost" href="index.php">Back</a></div>
</div>

<section class="card">
    <h2>Ratings on file, per month</h2>
    <p class="muted small">Every month listed here is reachable: open the student → Assess → pick the month tile, or Progress for the read-only view. If a month you remember assessing is missing or its numbers look far too small, tell me — that means rows are gone from the database (restore via the host's backup), not hidden.</p>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Month</th><th>Ratings</th><th>Students</th><th>Category averages</th><th>Comments</th><th>Entered by (distinct)</th></tr></thead>
        <tbody>
        <?php if (!$byMonth): ?>
            <tr><td colspan="6"><strong>No assessment rows exist in the database at all.</strong></td></tr>
        <?php endif; ?>
        <?php foreach ($byMonth as $m): ?>
            <tr>
                <td><strong><?= e(month_year_label($m['month_year'])) ?></strong> <span class="muted small">(<?= e($m['month_year']) ?>)</span></td>
                <td><?= (int)$m['cards'] ?></td>
                <td><?= (int)$m['students'] ?></td>
                <td><?= $avgRows[$m['month_year']] ?? 0 ?></td>
                <td><?= $comRows[$m['month_year']] ?? 0 ?></td>
                <td><?= (int)$m['teachers'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2>Rating codes</h2>
    <p class="muted small">Codes on historical cards must exist in the scheme (Admin → Rating). A code that is missing or inactive still displays — it renders as a grey "(legacy)" pill on the assess form and keeps its value on save.</p>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Code</th><th>Used on cards</th><th>Configured?</th><th>Active?</th><th>Label</th><th>Numeric</th></tr></thead>
        <tbody>
        <?php foreach ($usedCodes as $c): $cfg = $configured[$c['rating']] ?? null; ?>
            <tr>
                <td><strong><?= e($c['rating']) ?></strong></td>
                <td><?= (int)$c['n'] ?></td>
                <td><?= $cfg ? 'yes' : '<strong style="color:#b3261e">NO — add it in Admin → Rating</strong>' ?></td>
                <td><?= $cfg ? (!empty($cfg['is_active']) ? 'yes' : '<strong style="color:#8a6d00">inactive</strong>') : '—' ?></td>
                <td><?= $cfg ? e($cfg['label']) : '—' ?></td>
                <td><?= $cfg ? (int)$cfg['numeric_value'] : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($configured as $code => $cfg): ?>
            <?php if (!in_array($code, array_column($usedCodes, 'rating'), true)): ?>
            <tr class="muted">
                <td><?= e($code) ?></td><td>0</td><td>yes</td>
                <td><?= !empty($cfg['is_active']) ? 'yes' : 'inactive' ?></td>
                <td><?= e($cfg['label']) ?></td><td><?= (int)$cfg['numeric_value'] ?></td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2>Students hidden from the default dashboard (<?= count($hidden) ?>)</h2>
    <p class="muted small">Withdrawn/graduated/inactive children don't show on the dashboard by default — use the <a href="index.php?show=all">Former students</a> toggle. Their assessment history is intact and fully browsable via Progress.</p>
    <?php if (!$hidden): ?>
        <p class="muted">None — every student is visible on the dashboard.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Name</th><th>Grade</th><th>Status</th><th>Assessed months</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($hidden as $h): ?>
            <tr>
                <td><?= e(trim($h['first_name'] . ' ' . $h['last_name'])) ?></td>
                <td><?= e($h['grade']) ?></td>
                <td><?= e($h['enrollment_status']) ?><?= $h['is_active'] ? '' : ' · inactive' ?></td>
                <td><?= (int)$h['months'] ?></td>
                <td><a class="btn btn-ghost small" href="progress.php?student_id=<?= (int)$h['id'] ?>">Progress</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
