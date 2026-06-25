<?php
/**
 * tasks/trash.php — deletion log + restore.
 *
 * Lists every soft-deleted task (task_deletions audit table). Each entry
 * shows who deleted it, when, and a one-click Restore that brings the
 * row back to the active list. Restored entries stay visible (struck out)
 * for the audit trail.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tasks.php';

$user = require_module('tasks');

$rows = db()->query("
    SELECT td.*, du.name AS deleted_by_name, ru.name AS restored_by_name,
           t.title       AS current_title,
           t.deleted_at  AS task_deleted_at
    FROM   task_deletions td
    LEFT JOIN users du ON du.id = td.deleted_by_user_id
    LEFT JOIN users ru ON ru.id = td.restored_by_user_id
    LEFT JOIN tasks t  ON t.id  = td.task_id
    ORDER  BY td.deleted_at DESC
    LIMIT 200
")->fetchAll();

$pageTitle = 'Trash';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Trash</h1>
        <p class="muted">Every deleted task is preserved here. Restore brings it back to the active list.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/tasks/dashboard.php">← Dashboard</a>
        <a class="btn btn-ghost" href="/tasks/tasks.php">Task list</a>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="empty"><p>Nothing has been deleted yet.</p></div>
<?php else: ?>
<div class="card" style="padding: 0; overflow-x: auto;">
<table class="data-table" style="margin:0;">
    <thead>
        <tr>
            <th>Task</th>
            <th>Deleted</th>
            <th>By</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r):
            $snap = json_decode((string)$r['snapshot_json'], true) ?: [];
            $tsk  = $snap['task'] ?? [];
            $title = (string)($r['current_title'] ?? $tsk['title'] ?? 'untitled');
            $isRestored = (int)$r['restored'] === 1;
        ?>
        <tr style="<?= $isRestored ? 'opacity:.65;' : '' ?>">
            <td>
                <strong<?= $isRestored ? ' style="text-decoration:line-through;"' : '' ?>><?= e($title) ?></strong>
                <div class="muted small">#<?= (int)$r['task_id'] ?></div>
            </td>
            <td><?= e(date('j M Y · H:i', strtotime((string)$r['deleted_at']))) ?></td>
            <td><?= e($r['deleted_by_name'] ?? '—') ?></td>
            <td>
                <?php if ($isRestored): ?>
                    <span class="pill pill-ok">Restored</span>
                    <?php if (!empty($r['restored_by_name'])): ?>
                        <div class="muted small">by <?= e($r['restored_by_name']) ?> · <?= e(date('j M', strtotime((string)$r['restored_at']))) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="pill pill-warn">Deleted</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$isRestored): ?>
                    <form method="post" action="/tasks/tasks.php" onsubmit="return confirm('Restore this task to the active list?');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="restore">
                        <input type="hidden" name="deletion_id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-primary" type="submit">Restore</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
