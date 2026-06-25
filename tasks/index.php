<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = current_user();
if (!$user) {
    redirect('/login.php');
}

// Materialise today's recurring instances on every dashboard load.
materialize_recurrences();

$pageTitle = 'Dashboard — LG Task Manager';

$cols = task_columns();
$hasKanban = $cols !== [];

// Stats per column (when kanban is set up), otherwise per status (legacy).
$colStats = [];   // [['name' => 'To do', 'color' => '#...', 'is_done' => 0, 'n' => 3], ...]
if ($hasKanban) {
    $counts = db()->query("SELECT column_id, COUNT(*) AS n FROM tasks WHERE column_id IS NOT NULL AND deleted_at IS NULL GROUP BY column_id")->fetchAll();
    $byCol = [];
    foreach ($counts as $r) $byCol[(int)$r['column_id']] = (int)$r['n'];
    foreach ($cols as $col) {
        $colStats[] = [
            'name'    => $col['name'],
            'color'   => $col['color'],
            'is_done' => (int)$col['is_done'],
            'n'       => $byCol[(int)$col['id']] ?? 0,
        ];
    }
}

// My open tasks (anything not in a "done" column, or not 'done' if no kanban).
if ($hasKanban) {
    $mineStmt = db()->prepare("
        SELECT t.*, u.name AS assignee_name, col.name AS column_name, col.color AS column_color
        FROM tasks t
        LEFT JOIN users u          ON u.id   = t.assigned_to_user_id
        LEFT JOIN task_columns col ON col.id = t.column_id
        WHERE t.assigned_to_user_id = :uid AND t.deleted_at IS NULL AND (col.is_done IS NULL OR col.is_done = 0)
        ORDER BY (t.due_date IS NULL), t.due_date ASC, FIELD(t.priority,'high','normal','low')
        LIMIT 10
    ");
} else {
    $mineStmt = db()->prepare("
        SELECT t.*, u.name AS assignee_name, NULL AS column_name, NULL AS column_color
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_to_user_id
        WHERE t.assigned_to_user_id = :uid AND t.deleted_at IS NULL AND t.status <> 'done'
        ORDER BY (t.due_date IS NULL), t.due_date ASC, FIELD(t.priority,'high','normal','low')
        LIMIT 10
    ");
}
$mineStmt->execute([':uid' => $user['id']]);
$mine = $mineStmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<section class="hero">
    <div>
        <p class="hero-eyebrow"><?= e(date('l, j M Y')) ?></p>
        <h1 class="hero-title">Hello, <?= e(first_name($user['name'])) ?>.</h1>
        <p class="hero-sub">Here&rsquo;s what&rsquo;s on your plate today.</p>
    </div>
    <div class="hero-stats">
        <?php if ($hasKanban): ?>
            <?php foreach ($colStats as $cs): ?>
                <div class="stat" style="background: <?= e($cs['color']) ?>22;">
                    <span class="n"><?= (int)$cs['n'] ?></span>
                    <span class="l"><?= e($cs['name']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="stat stat-p"><span class="n">—</span><span class="l">Run /migrate.php</span></div>
        <?php endif; ?>
    </div>
</section>

<div class="actionbar">
    <h2 class="section-h">Your open tasks</h2>
    <a class="btn" href="/tasks/dashboard.php">Dashboard</a>
    <a class="btn" href="/tasks/my.php">My subtasks</a>
    <a class="btn btn-ghost" href="/tasks/trash.php">Trash</a>
    <a class="btn btn-primary" href="/tasks/tasks.php"><span class="plus">+</span> All tasks</a>
</div>

<?php if (!$mine): ?>
    <div class="empty">
        Nothing assigned to you. <a href="tasks.php">Browse all tasks →</a>
    </div>
<?php else: ?>
    <ul class="task-list">
        <?php foreach ($mine as $t): $colColor = $t['column_color'] ?? '#EC407A'; ?>
            <li class="task" style="border-left-color: <?= e($colColor) ?>;">
                <div class="task-head">
                    <span class="task-status-pill" style="background: <?= e($colColor) ?>22; color: <?= e($colColor) ?>;">
                        <?= e($t['column_name'] ?? status_label($t['status'])) ?>
                    </span>
                    <span class="task-priority <?= e(priority_class($t['priority'])) ?>">
                        <?= e($t['priority']) ?>
                    </span>
                    <?php if (!empty($t['due_date'])): ?>
                        <span class="task-due">Due <?= e($t['due_date']) ?></span>
                    <?php endif; ?>
                </div>
                <h3 class="task-title">
                    <a href="tasks.php?edit=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
                </h3>
                <?php if (!empty($t['description'])): ?>
                    <p class="task-desc"><?= nl2br(e($t['description'])) ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
