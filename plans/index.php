<?php
/**
 * plans/index.php — teacher: my weeks; admin: all teachers for a week.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plans.php';

$user = plan_require();

if (!plan_tables_ready()) {
    $pageTitle = 'Weekly Plans';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card"><p>Run migrations — weekly_plans table is missing.</p></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$isAdmin = ($user['role'] ?? '') === 'admin';
$week = (string)($_GET['week'] ?? plan_current_week_key());
try {
    $week = plan_parse_week_key($week);
} catch (InvalidArgumentException $e) {
    $week = plan_current_week_key();
}

$pageTitle = 'Weekly Plans';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Weekly Plans</h1>
        <p class="muted">Plan Mon–Sat ahead, note materials, and send a message to the Principal.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-primary" href="/plans/edit.php?week=<?= e(rawurlencode(plan_current_week_key())) ?>">This week’s plan</a>
    </div>
</div>

<?php if ($isAdmin): ?>
    <?php
        $prev = plan_shift_week($week, -1);
        $next = plan_shift_week($week, 1);
        $board = plan_admin_week_board($week);
        $counts = ['submitted' => 0, 'approved' => 0, 'changes_requested' => 0, 'draft' => 0, 'missing' => 0];
        foreach ($board as $row) {
            if ($row['plan'] === null) { $counts['missing']++; continue; }
            $st = (string)$row['plan']['status'];
            if (isset($counts[$st])) $counts[$st]++;
            else $counts['draft']++;
        }
    ?>
    <div class="card">
        <div class="actionbar" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem">
            <a class="btn btn-ghost" href="?week=<?= e(urlencode($prev)) ?>">← <?= e($prev) ?></a>
            <strong><?= e(plan_week_label($week)) ?></strong>
            <a class="btn btn-ghost" href="?week=<?= e(urlencode($next)) ?>"><?= e($next) ?> →</a>
        </div>
        <p class="muted small" style="margin-top:.75rem">
            Submitted <?= (int)$counts['submitted'] ?>
            · Approved <?= (int)$counts['approved'] ?>
            · Changes <?= (int)$counts['changes_requested'] ?>
            · Draft <?= (int)$counts['draft'] ?>
            · Not started <?= (int)$counts['missing'] ?>
        </p>
    </div>

    <div class="card" style="padding:0;overflow:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Class / grade</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($board as $row):
                $p = $row['plan'];
            ?>
                <tr>
                    <td><?= e($row['teacher_name']) ?></td>
                    <td class="muted"><?= $p ? e((string)$p['class_grade']) : '—' ?></td>
                    <td>
                        <?php if (!$p): ?>
                            <span class="pill">Not started</span>
                        <?php else: ?>
                            <span class="pill"><?= e(plan_status_label((string)$p['status'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        <?php if ($p): ?>
                            <a class="btn btn-ghost small" href="/plans/view.php?id=<?= (int)$p['id'] ?>">View</a>
                            <?php if (plan_is_editable($p)): ?>
                                <a class="btn btn-ghost small" href="/plans/edit.php?week=<?= e(urlencode($week)) ?>&teacher=<?= (int)$row['teacher_id'] ?>">Edit</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="btn btn-ghost small" href="/plans/edit.php?week=<?= e(urlencode($week)) ?>&teacher=<?= (int)$row['teacher_id'] ?>">Start</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <?php
        $mine = plan_list_for_teacher((int)$user['id']);
        $current = plan_get_by_teacher_week((int)$user['id'], plan_current_week_key());
    ?>
    <div class="card">
        <h2 style="margin-top:0">This week</h2>
        <p><?= e(plan_week_label(plan_current_week_key())) ?></p>
        <?php if ($current): ?>
            <p><span class="pill"><?= e(plan_status_label((string)$current['status'])) ?></span></p>
            <div class="actionbar">
                <?php if (plan_is_editable($current)): ?>
                    <a class="btn btn-primary" href="/plans/edit.php?week=<?= e(urlencode((string)$current['week_key'])) ?>">Continue editing</a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="/plans/view.php?id=<?= (int)$current['id'] ?>">View</a>
            </div>
        <?php else: ?>
            <p class="muted">No plan started yet.</p>
            <a class="btn btn-primary" href="/plans/edit.php?week=<?= e(urlencode(plan_current_week_key())) ?>">Start this week</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Earlier weeks</h2>
        <?php if (!$mine): ?>
            <p class="muted">No weekly plans yet.</p>
        <?php else: ?>
            <ul style="list-style:none;padding:0;margin:0">
                <?php foreach ($mine as $p): ?>
                    <li style="display:flex;justify-content:space-between;gap:1rem;padding:.4rem 0;border-bottom:1px solid var(--line,#eee)">
                        <div>
                            <a href="/plans/view.php?id=<?= (int)$p['id'] ?>"><?= e(plan_week_label((string)$p['week_key'])) ?></a>
                            <?php if ((string)$p['class_grade'] !== ''): ?>
                                <span class="muted small"> · <?= e((string)$p['class_grade']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="pill"><?= e(plan_status_label((string)$p['status'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
