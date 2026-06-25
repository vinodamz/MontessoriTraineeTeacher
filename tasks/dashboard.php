<?php
/**
 * tasks/dashboard.php — one-screen rollup of the task tracker.
 *
 *   Tiles: Assigned · Completed · Pending · Missed
 *   Each tile is a real count from live data and links into the task list
 *   pre-filtered to that bucket.
 *
 *   Optional ?assignee=N  → numbers narrow to one person.
 *   Per-assignee table breaks the same counts down by user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tasks.php';

$user = require_module('tasks');

$assigneeIn = isset($_GET['assignee']) ? (int)$_GET['assignee'] : 0;
$assigneeId = $assigneeIn > 0 ? $assigneeIn : null;

$counts = task_dashboard_counts($assigneeId);
$perUser = task_dashboard_per_user();

$users = db()->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll();

$assigneeQs = $assigneeId !== null ? '&assigned_to_user_id=' . (int)$assigneeId : '';

$pageTitle = 'Task dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Task dashboard</h1>
        <p class="muted">Live counts. Click any number to open the matching list.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/tasks/tasks.php">Task list</a>
        <a class="btn" href="/tasks/index.php">Board</a>
        <a class="btn" href="/tasks/my.php">My subtasks</a>
        <a class="btn btn-ghost" href="/tasks/trash.php">Trash</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label>Show for</label>
        <select name="assignee" onchange="this.form.submit()">
            <option value="0">Everyone</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $assigneeId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <noscript><div class="actions"><button class="btn btn-primary">Apply</button></div></noscript>
</form>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
    <li><a class="admin-tile" href="/tasks/tasks.php?bucket=assigned<?= e($assigneeQs) ?>" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Assigned</span>
        <span class="tile-value"><?= (int)$counts['assigned'] ?></span>
        <span class="tile-sub">open + has assignee</span>
    </a></li>
    <li><a class="admin-tile tile-ok" href="/tasks/tasks.php?bucket=completed<?= e($assigneeQs) ?>" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Completed</span>
        <span class="tile-value"><?= (int)$counts['completed'] ?></span>
        <span class="tile-sub">status = done</span>
    </a></li>
    <li><a class="admin-tile" href="/tasks/tasks.php?bucket=pending<?= e($assigneeQs) ?>" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Pending</span>
        <span class="tile-value"><?= (int)$counts['pending'] ?></span>
        <span class="tile-sub">open, due today or later</span>
    </a></li>
    <li><a class="admin-tile <?= $counts['missed'] ? 'tile-warn' : '' ?>" href="/tasks/tasks.php?bucket=missed<?= e($assigneeQs) ?>" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Missed</span>
        <span class="tile-value"><?= (int)$counts['missed'] ?></span>
        <span class="tile-sub">past due, not done</span>
    </a></li>
</ul>

<div class="card">
    <h2 style="margin-top:0;">By person</h2>
    <?php if (!$perUser): ?>
        <p class="muted">Nobody has tasks yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Person</th><th style="text-align:right;">Assigned</th><th style="text-align:right;">Completed</th><th style="text-align:right;">Pending</th><th style="text-align:right;">Missed</th></tr></thead>
            <tbody>
                <?php foreach ($perUser as $r):
                    $uid = (int)$r['id']; ?>
                    <tr>
                        <td><a href="/tasks/dashboard.php?assignee=<?= $uid ?>"><?= e($r['name']) ?></a></td>
                        <td style="text-align:right;"><a href="/tasks/tasks.php?bucket=assigned&assigned_to_user_id=<?= $uid ?>"><?= (int)$r['assigned'] ?></a></td>
                        <td style="text-align:right;"><a href="/tasks/tasks.php?bucket=completed&assigned_to_user_id=<?= $uid ?>"><?= (int)$r['completed'] ?></a></td>
                        <td style="text-align:right;"><a href="/tasks/tasks.php?bucket=pending&assigned_to_user_id=<?= $uid ?>"><?= (int)$r['pending'] ?></a></td>
                        <td style="text-align:right;<?= (int)$r['missed'] > 0 ? ' color:#b03030; font-weight:600;' : '' ?>"><a href="/tasks/tasks.php?bucket=missed&assigned_to_user_id=<?= $uid ?>" style="color:inherit;"><?= (int)$r['missed'] ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
