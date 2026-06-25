<?php
/**
 * tasks/my.php — "my subtasks" cross-task filter.
 *
 *   GET                      → my open subtasks (default: current user)
 *   GET ?assignee=N          → pick someone else
 *   POST op=subtask_toggle   → check / uncheck (same handler as tasks.php)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tasks.php';

$user = require_module('tasks');

$assigneeIn = isset($_GET['assignee']) ? (int)$_GET['assignee'] : (int)$user['id'];

$users = db()->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll();

$st = db()->prepare("
    SELECT ts.*, t.title AS task_title, t.id AS task_id, u.name AS assignee_name
    FROM   task_subtasks ts
    JOIN   tasks t ON t.id = ts.task_id
    LEFT JOIN users u ON u.id = ts.assignee_user_id
    WHERE  ts.assignee_user_id = :uid
      AND  t.deleted_at IS NULL
    ORDER  BY ts.done, t.title, ts.order_idx
");
$st->execute([':uid' => $assigneeIn]);
$rows = $st->fetchAll();

$openN = 0; $doneN = 0;
foreach ($rows as $r) { (int)$r['done'] === 1 ? $doneN++ : $openN++; }

$pageTitle = 'My subtasks';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Subtasks · <span class="muted"><?= e($users ? (array_values(array_filter($users, fn($u) => (int)$u['id'] === $assigneeIn))[0]['name'] ?? 'Unknown') : 'Unknown') ?></span></h1>
        <p class="muted"><strong><?= $openN ?></strong> open · <?= $doneN ?> done</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/tasks/dashboard.php">← Dashboard</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label>Show for</label>
        <select name="assignee" onchange="this.form.submit()">
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $assigneeIn === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="empty"><p>No subtasks are assigned to this person yet.</p></div>
<?php else: ?>
<div class="card">
    <ul class="subtask-list" style="list-style:none; padding:0; margin:0;">
        <?php foreach ($rows as $r):
            $done = (int)$r['done'] === 1; ?>
            <li style="display:flex; gap:.6rem; align-items:center; padding:.45rem 0; border-bottom:1px solid var(--line-soft);">
                <form method="post" action="/tasks/tasks.php" class="subtask-toggle-form" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="subtask_toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="task_id" value="<?= (int)$r['task_id'] ?>">
                    <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
                    <button type="submit" style="background:transparent; border:0; cursor:pointer; font-size:1.1rem;">
                        <?= $done ? '☑' : '☐' ?>
                    </button>
                </form>
                <div style="flex:1; <?= $done ? 'text-decoration:line-through; color:var(--muted);' : '' ?>">
                    <?= e($r['title']) ?>
                    <div class="muted small">on <a href="/tasks/tasks.php?edit=1&id=<?= (int)$r['task_id'] ?>"><?= e($r['task_title']) ?></a></div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
