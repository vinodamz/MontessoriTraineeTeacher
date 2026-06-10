<?php
/**
 * students/learning.php — the "Learning" tab of the child record
 * (Phase 2 of the UX roadmap).
 *
 * Per-child learning summary in one place, so nobody has to know the
 * assessment module owns this data:
 *   - First assessment (baseline): on file or not, with the open/add link.
 *   - This month: assessed yet? with the assess link.
 *   - Recent monthly scores: compact month × category table.
 *   - Deep link to the full progress page (chart + comments + print).
 *
 * The assessment module keeps its bulk teacher workflow (assess every
 * child this month); this page is the per-child view of the same data.
 *
 * Auth mirrors students/view.php: admins and montessori-module holders;
 * plain assessment teachers see only their own students.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_tabs.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'montessori')) {
    http_response_code(403);
    echo 'Forbidden — the Learning tab needs the assessment module.';
    exit;
}

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($studentId <= 0) { redirect('/students/index.php'); }

$stmt = db()->prepare("SELECT s.*, u.name AS teacher_name FROM students s LEFT JOIN users u ON u.id = s.teacher_id WHERE s.id = :id");
$stmt->execute([':id' => $studentId]);
$s = $stmt->fetch();
if (!$s) {
    http_response_code(404);
    flash_set('error', 'Student not found.');
    redirect('/students/index.php');
}
$canSeeAll = $user['role'] === 'admin' || user_has_module($user, 'students');
if (!$canSeeAll && (int)$s['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'Forbidden — this student is not assigned to you.';
    exit;
}

$full = trim($s['first_name'] . ' ' . $s['last_name']);

// First assessment (baseline) — one row per student.
$bstmt = db()->prepare("SELECT recorded_at, recorded_by FROM student_baselines WHERE student_id = :s");
$bstmt->execute([':s' => $studentId]);
$baseline = $bstmt->fetch() ?: null;

// Monthly category scores, pivoted month × category (same shape as
// assessment/progress.php — that page keeps the chart + comments).
$astmt = db()->prepare("
    SELECT month_year, category, category_avg
    FROM assessments
    WHERE student_id = :s
    ORDER BY category, month_year
");
$astmt->execute([':s' => $studentId]);
$pivot = []; $months = []; $categories = [];
foreach ($astmt->fetchAll() as $r) {
    $pivot[$r['month_year']][$r['category']] = (float)$r['category_avg'];
    $months[$r['month_year']]   = true;
    $categories[$r['category']] = true;
}
$months = array_keys($months);
if (function_exists('compare_month_year')) {
    usort($months, 'compare_month_year');
}
$months = array_slice(array_reverse($months), 0, 6);   // latest 6, newest first
$categories = array_keys($categories);
sort($categories);

// Assessed this month?
$thisMonth = current_month_year();
$estmt = db()->prepare("SELECT COUNT(*) FROM evaluation_cards WHERE student_id = :s AND month_year = :m");
$estmt->execute([':s' => $studentId, ':m' => $thisMonth]);
$assessedThisMonth = (int)$estmt->fetchColumn() > 0;

$pageTitle = "Learning · $full";
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Learning</h1>
        <p class="muted">
            <a href="/students/view.php?id=<?= $studentId ?>"><?= e($full) ?></a>
            · <span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span>
            <?php if (!empty($s['teacher_name'])): ?> · <?= e($s['teacher_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/assessment/progress.php?student_id=<?= $studentId ?>">Full progress &amp; chart</a>
        <a class="btn btn-primary" href="/assessment/assess.php?student_id=<?= $studentId ?>">Assess <?= e(month_year_label($thisMonth)) ?></a>
    </div>
</div>

<?php student_tab_strip($studentId, 'learning', $user); ?>

<div class="card">
    <h2 style="margin-top:0;">Status</h2>
    <p style="margin:.3rem 0;">
        <?php if ($baseline): ?>
            <span class="pill att-pill att-present">First assessment ✓</span>
            <span class="muted small">
                recorded <?= e($baseline['recorded_at'] ?: '—') ?><?= $baseline['recorded_by'] ? ' by ' . e($baseline['recorded_by']) : '' ?>
                · <a href="/assessment/baseline.php?student_id=<?= $studentId ?>">view / edit</a>
            </span>
        <?php else: ?>
            <span class="pill pill-warn">No first assessment yet</span>
            <a class="btn btn-ghost" href="/assessment/baseline.php?student_id=<?= $studentId ?>" style="margin-left:.5rem;">Add it</a>
        <?php endif; ?>
    </p>
    <p style="margin:.3rem 0;">
        <?php if ($assessedThisMonth): ?>
            <span class="pill att-pill att-present"><?= e(month_year_label($thisMonth)) ?> assessed ✓</span>
        <?php else: ?>
            <span class="pill pill-warn"><?= e(month_year_label($thisMonth)) ?> not assessed yet</span>
            <a class="btn btn-ghost" href="/assessment/assess.php?student_id=<?= $studentId ?>" style="margin-left:.5rem;">Assess now</a>
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <h2 style="margin-top:0;">Recent monthly scores</h2>
    <?php if (!$months): ?>
        <p class="muted">No monthly assessments recorded yet. They'll appear here once the teacher assesses a month.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="att-summary">
            <thead>
                <tr>
                    <th>Month</th>
                    <?php foreach ($categories as $cat): ?>
                        <th><?= e($cat) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($months as $m): ?>
                    <tr>
                        <td><strong><?= e(month_year_label($m)) ?></strong></td>
                        <?php foreach ($categories as $cat):
                            $v = $pivot[$m][$cat] ?? null; ?>
                            <td><?= $v === null ? '<span class="muted">—</span>' : e(number_format($v, 1)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="muted small" style="margin:.5rem 0 0;">Category averages out of the rating scale. The <a href="/assessment/progress.php?student_id=<?= $studentId ?>">full progress page</a> has the trend chart, per-indicator detail, and teacher comments.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
