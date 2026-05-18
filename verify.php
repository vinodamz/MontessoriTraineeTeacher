<?php
/**
 * verify.php — read-only post-migration diagnostic.
 * DELETE this file after we've fixed any data issues.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_admin();

$counts = [];
foreach (['teachers','students','skill_indicators','student_custom_indicators','evaluation_cards','assessments','assessment_comments','student_baselines','rating_config'] as $t) {
    $counts[$t] = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

// Top 10 students by evaluation_card count
$topStudents = db()->query("
    SELECT s.id, s.first_name, s.last_name, s.grade,
           t.name AS teacher,
           COUNT(DISTINCT ec.month_year) AS months_assessed,
           COUNT(ec.id) AS total_cards,
           (SELECT COUNT(*) FROM assessments  a  WHERE a.student_id  = s.id) AS assessment_rows,
           (SELECT COUNT(*) FROM assessment_comments ac WHERE ac.student_id = s.id) AS comments
    FROM students s
    JOIN teachers t ON t.id = s.teacher_id
    LEFT JOIN evaluation_cards ec ON ec.student_id = s.id
    GROUP BY s.id, s.first_name, s.last_name, s.grade, t.name
    ORDER BY total_cards DESC, s.first_name
    LIMIT 15
")->fetchAll();

// Look for orphaned cards (indicator_id missing)
$orphans = (int)db()->query("
    SELECT COUNT(*)
    FROM evaluation_cards ec
    WHERE ec.is_custom_indicator = 0
      AND NOT EXISTS (SELECT 1 FROM skill_indicators si WHERE si.id = ec.indicator_id)
")->fetchColumn();

$customOrphans = (int)db()->query("
    SELECT COUNT(*)
    FROM evaluation_cards ec
    WHERE ec.is_custom_indicator = 1
      AND NOT EXISTS (SELECT 1 FROM student_custom_indicators sci WHERE sci.id = ec.indicator_id)
")->fetchColumn();

// Distinct month_years present
$monthsPresent = db()->query("SELECT DISTINCT month_year FROM evaluation_cards ORDER BY month_year")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Verify migration';
require __DIR__ . '/includes/header.php';
?>
<h1>Migration verification</h1>

<section class="card">
    <h2>Row counts (MySQL)</h2>
    <table class="admin-table">
        <thead><tr><th>Table</th><th>Rows</th><th>Supabase had</th></tr></thead>
        <tbody>
            <?php
            $supa = [
                'teachers' => 5, 'students' => 41, 'skill_indicators' => 119,
                'student_custom_indicators' => 0, 'evaluation_cards' => 1036,
                'assessments' => 132, 'assessment_comments' => 37,
                'student_baselines' => 40, 'rating_config' => 5,
            ];
            foreach ($counts as $t => $n): ?>
                <tr<?= $n < $supa[$t] ? ' style="background:#fde9e8"' : '' ?>>
                    <td><code><?= e($t) ?></code></td>
                    <td><strong><?= $n ?></strong></td>
                    <td class="muted"><?= $supa[$t] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2>Orphan checks</h2>
    <p>Standard-indicator orphans: <strong><?= $orphans ?></strong> (rows in <code>evaluation_cards</code> whose <code>indicator_id</code> doesn't match a <code>skill_indicators</code> row).</p>
    <p>Custom-indicator orphans: <strong><?= $customOrphans ?></strong> (same check against <code>student_custom_indicators</code>).</p>
</section>

<section class="card">
    <h2>Months with evaluation_cards</h2>
    <?php if (!$monthsPresent): ?>
        <p class="muted">None.</p>
    <?php else: ?>
        <p><?= e(implode(', ', $monthsPresent)) ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Top 15 students by card count (MySQL)</h2>
    <table class="admin-table">
        <thead><tr><th>Student</th><th>Grade</th><th>Teacher</th><th>Months</th><th>Cards</th><th>Assessments</th><th>Comments</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($topStudents as $r): ?>
                <tr>
                    <td><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                    <td><?= e($r['grade']) ?></td>
                    <td><?= e($r['teacher']) ?></td>
                    <td><?= (int)$r['months_assessed'] ?></td>
                    <td><strong><?= (int)$r['total_cards'] ?></strong></td>
                    <td><?= (int)$r['assessment_rows'] ?></td>
                    <td><?= (int)$r['comments'] ?></td>
                    <td><a href="progress.php?student_id=<?= (int)$r['id'] ?>" class="btn btn-ghost small">Open progress →</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<p class="muted small">Delete this file once we've finished diagnosing.</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
