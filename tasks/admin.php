<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$me = require_admin();

function pin_is_in_use(string $pin, ?int $excludeUserId = null): bool
{
    $stmt = db()->query("SELECT id, pin_hash FROM users");
    foreach ($stmt as $row) {
        if ($excludeUserId !== null && (int)$row['id'] === $excludeUserId) continue;
        if (password_verify($pin, $row['pin_hash'])) return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    // -------- Column management --------
    if ($op === 'col_create') {
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#EC407A';
        $isDone = !empty($_POST['is_done']) ? 1 : 0;
        if ($name === '') {
            flash_set('error', 'Column name required.');
            redirect('admin.php');
        }
        $maxPos = (int) db()->query("SELECT COALESCE(MAX(position),0)+1 FROM task_columns")->fetchColumn();
        $stmt = db()->prepare("INSERT INTO task_columns (name, position, color, is_done) VALUES (:n, :p, :c, :d)");
        try {
            $stmt->execute([':n' => $name, ':p' => $maxPos, ':c' => $color, ':d' => $isDone]);
            flash_set('ok', 'Column added.');
        } catch (PDOException $e) {
            flash_set('error', 'A column with that name already exists.');
        }
        redirect('admin.php');
    }

    if ($op === 'col_update') {
        $cols = $_POST['col'] ?? [];
        if (is_array($cols)) {
            $upd = db()->prepare("UPDATE task_columns SET name=:n, position=:p, color=:c, is_done=:d WHERE id=:id");
            foreach ($cols as $id => $row) {
                $name = trim($row['name'] ?? '');
                if ($name === '') continue;
                try {
                    $upd->execute([
                        ':n'  => $name,
                        ':p'  => (int)($row['position'] ?? 0),
                        ':c'  => $row['color'] ?? '#EC407A',
                        ':d'  => !empty($row['is_done']) ? 1 : 0,
                        ':id' => (int)$id,
                    ]);
                } catch (PDOException $e) { /* keep going */ }
            }
            flash_set('ok', 'Columns updated.');
        }
        redirect('admin.php');
    }

    if ($op === 'col_delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $del = db()->prepare("DELETE FROM task_columns WHERE id = :id");
            $del->execute([':id' => $id]);
            flash_set('ok', 'Column deleted.');
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete — column still has tasks. Move them first.');
        }
        redirect('admin.php');
    }

    // -------- Recurring task management --------
    if ($op === 'rec_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("UPDATE task_recurrences SET is_active = 1 - is_active WHERE id = :id");
        $stmt->execute([':id' => $id]);
        flash_set('ok', 'Recurrence toggled.');
        redirect('admin.php');
    }
    if ($op === 'rec_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM task_recurrences WHERE id = :id");
        $stmt->execute([':id' => $id]);
        flash_set('ok', 'Recurrence deleted (existing task instances kept).');
        redirect('admin.php');
    }

    if ($op === 'create') {
        $name = trim($_POST['name'] ?? '');
        $pin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');
        $role = $_POST['role'] ?? 'teacher';
        if ($name === '' || strlen($pin) < 4 || strlen($pin) > 6) {
            flash_set('error', 'Name and a 4–6 digit PIN are required.');
            redirect('admin.php');
        }
        if (!in_array($role, ['teacher','admin'], true)) $role = 'teacher';
        if (pin_is_in_use($pin)) {
            flash_set('error', 'That PIN is already in use. Pick another.');
            redirect('admin.php');
        }

        $stmt = db()->prepare("INSERT INTO users (name, pin_hash, role, modules) VALUES (:n, :h, :r, 'tasks')");
        $stmt->execute([':n' => $name, ':h' => password_hash($pin, PASSWORD_DEFAULT), ':r' => $role]);
        flash_set('ok', "User added. Their PIN is $pin — share it with them privately.");
        redirect('admin.php');
    }

    if ($op === 'update') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $role   = $_POST['role'] ?? 'teacher';
        $active = !empty($_POST['active']) ? 1 : 0;
        $newPin = preg_replace('/\D/', '', $_POST['pin'] ?? '');

        if (!in_array($role, ['teacher','admin'], true)) $role = 'teacher';
        if ($id === $me['id'] && $role !== 'admin') {
            flash_set('error', "You can't demote yourself.");
            redirect('admin.php');
        }
        if ($id === $me['id'] && !$active) {
            flash_set('error', "You can't deactivate yourself.");
            redirect('admin.php');
        }

        if ($newPin !== '') {
            if (strlen($newPin) < 4 || strlen($newPin) > 6) {
                flash_set('error', 'PIN must be 4–6 digits.');
                redirect('admin.php');
            }
            if (pin_is_in_use($newPin, $id)) {
                flash_set('error', 'That PIN is already in use.');
                redirect('admin.php');
            }
            $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a, pin_hash=:h WHERE id=:id");
            $stmt->execute([
                ':n' => $name, ':r' => $role, ':a' => $active,
                ':h' => password_hash($newPin, PASSWORD_DEFAULT), ':id' => $id,
            ]);
        } else {
            $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a WHERE id=:id");
            $stmt->execute([':n' => $name, ':r' => $role, ':a' => $active, ':id' => $id]);
        }
        flash_set('ok', 'User updated.');
        redirect('admin.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $me['id']) {
            flash_set('error', "You can't delete yourself.");
            redirect('admin.php');
        }
        $stmt = db()->prepare("DELETE FROM users WHERE id = :id");
        try {
            $stmt->execute([':id' => $id]);
            flash_set('ok', 'User deleted.');
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete: user has tasks they created. Deactivate instead.');
        }
        redirect('admin.php');
    }
}

// Tasks team view = anyone with tasks-module access OR admin role.
$users = db()->query("
    SELECT id, name, role, active, created_at
    FROM users
    WHERE role = 'admin' OR FIND_IN_SET('tasks', modules) > 0
    ORDER BY name
")->fetchAll();

$pageTitle = 'Team — LG Task Manager';
include __DIR__ . '/../includes/header.php';
?>

<div class="actionbar">
    <h1>Team</h1>
</div>

<details class="card card-form" open>
    <summary>Add a teammate</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <div class="row">
            <div class="field">
                <label>Name</label>
                <input name="name" required maxlength="100" placeholder="e.g. Priya Sharma">
            </div>
            <div class="field">
                <label>PIN (4–6 digits)</label>
                <input name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required placeholder="e.g. 1234">
            </div>
            <div class="field">
                <label>Role</label>
                <select name="role">
                    <option value="teacher">Teacher</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary">Add teammate</button>
        </div>
    </form>
</details>

<h2 class="section-h-spaced">Active &amp; inactive</h2>

<!-- Edit + delete forms declared outside the row, referenced by `form` attribute. -->
<?php foreach ($users as $u): ?>
    <form id="u-edit-<?= (int)$u['id'] ?>" method="post" hidden>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="update">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    </form>
    <form id="u-del-<?= (int)$u['id'] ?>" method="post" hidden
          onsubmit="return confirm('Delete this user? Their assigned tasks will be unassigned.')">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="delete">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    </form>
<?php endforeach; ?>

<?php
// =========================================================================
// Column management section
// =========================================================================
$cols = task_columns();
?>

<?php if ($cols): ?>
<h2 class="section-h-spaced">Board columns</h2>

<details class="card card-form">
    <summary>Add a column</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="col_create">
        <div class="row">
            <div class="field">
                <label>Name</label>
                <input name="name" required maxlength="50" placeholder="e.g. Blocked">
            </div>
            <div class="field">
                <label>Color</label>
                <input type="color" name="color" value="#EC407A">
            </div>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="is_done" value="1">
                    <span>Counts as "done"</span>
                </label>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary">Add column</button>
        </div>
    </form>
</details>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="col_update">
    <ul class="col-manager">
        <?php foreach ($cols as $col): ?>
            <li style="--col: <?= e($col['color']) ?>;">
                <span class="col-dot"></span>
                <input type="text" name="col[<?= (int)$col['id'] ?>][name]" value="<?= e($col['name']) ?>" maxlength="50">
                <input type="color" name="col[<?= (int)$col['id'] ?>][color]" value="<?= e($col['color']) ?>" title="Colour">
                <input type="number" name="col[<?= (int)$col['id'] ?>][position]" value="<?= (int)$col['position'] ?>" min="0" title="Order">
                <label class="checkbox" title="Counts as done">
                    <input type="checkbox" name="col[<?= (int)$col['id'] ?>][is_done]" value="1" <?= $col['is_done'] ? 'checked' : '' ?>>
                    <span>Done</span>
                </label>
                <button type="submit" form="col-del-<?= (int)$col['id'] ?>" class="link-btn"
                        onclick="return confirm('Delete the &quot;<?= e($col['name']) ?>&quot; column? It must be empty first.')">Delete</button>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="actions">
        <button class="btn btn-primary">Save columns</button>
    </div>
</form>

<?php foreach ($cols as $col): ?>
    <form id="col-del-<?= (int)$col['id'] ?>" method="post" hidden>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="col_delete">
        <input type="hidden" name="id" value="<?= (int)$col['id'] ?>">
    </form>
<?php endforeach; ?>

<h2 class="section-h-spaced">Team</h2>
<?php endif; ?>

<?php if (recurrence_available()): ?>
<?php
$recurrences = db()->query("
    SELECT r.*, c.name AS column_name, u.name AS assignee_name
    FROM task_recurrences r
    LEFT JOIN task_columns c ON c.id = r.column_id
    LEFT JOIN users u        ON u.id = r.assigned_to_user_id
    ORDER BY r.is_active DESC, r.title ASC
")->fetchAll();
?>
<h2 class="section-h-spaced">Recurring tasks</h2>
<p class="muted">Create recurring task templates from the <a href="tasks.php">Tasks page</a> — tick "Repeat this task" when filling in the new-task form.</p>

<?php if ($recurrences): ?>
<ul class="recur-list">
    <?php foreach ($recurrences as $r):
        $when = match ($r['frequency']) {
            'daily'   => 'Every day',
            'weekly'  => 'Weekly · ' . days_mask_label((int)$r['days_mask']),
            'monthly' => 'Monthly · day ' . (int)$r['day_of_month'],
            default   => $r['frequency'],
        };
        $offset = (int)($r['due_offset_days'] ?? 0);
        $dueRule = $offset === 0 ? 'due same day' : "due +{$offset}d";
    ?>
        <li class="<?= $r['is_active'] ? '' : 'recur-inactive' ?>">
            <div>
                <div class="recur-title"><?= e($r['title']) ?></div>
                <div class="recur-meta">
                    <span><?= e($when) ?></span>
                    <span><?= e($dueRule) ?></span>
                    <?php if (!empty($r['column_name'])): ?><span>→ <?= e($r['column_name']) ?></span><?php endif; ?>
                    <?php if (!empty($r['assignee_name'])): ?><span>👤 <?= e($r['assignee_name']) ?></span><?php endif; ?>
                    <?php if (!empty($r['end_date'])): ?><span>ends <?= e($r['end_date']) ?></span><?php endif; ?>
                    <span><?= $r['is_active'] ? 'active' : 'paused' ?></span>
                </div>
            </div>
            <div class="recur-actions">
                <form method="post" class="inline">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="rec_toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn"><?= $r['is_active'] ? 'Pause' : 'Resume' ?></button>
                </form>
                <form method="post" class="inline" onsubmit="return confirm('Delete this recurring task? Existing instances remain on the board.')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="rec_delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="link-btn">Delete</button>
                </form>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<?php else: ?>
    <div class="empty">No recurring tasks yet. Open the <a href="tasks.php">Tasks page</a> → New task → tick "Repeat this task".</div>
<?php endif; ?>
<?php endif; ?>

<ul class="team-list">
    <?php foreach ($users as $u): $fid = 'u-edit-' . (int)$u['id']; ?>
        <li class="team-row" style="--card: <?= e(user_color((int)$u['id'])) ?>;">
            <div class="team-dot"><?= e(user_initials($u['name'])) ?></div>
            <div>
                <div class="team-name"><?= e($u['name']) ?></div>
                <div class="team-meta">
                    <?= e($u['role']) ?>
                    · <?= $u['active'] ? 'active' : 'inactive' ?>
                    · since <?= e(substr((string)$u['created_at'], 0, 10)) ?>
                </div>
            </div>
            <div class="team-edit">
                <input form="<?= $fid ?>" name="name" value="<?= e($u['name']) ?>" maxlength="100" aria-label="Name">
                <select form="<?= $fid ?>" name="role" aria-label="Role">
                    <option value="teacher" <?= $u['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
                <label class="checkbox" title="Active">
                    <input form="<?= $fid ?>" type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>>
                    <span>Active</span>
                </label>
                <input form="<?= $fid ?>" name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6"
                       placeholder="New PIN" aria-label="New PIN">
                <button class="btn" form="<?= $fid ?>">Save</button>
                <button class="link-btn" form="u-del-<?= (int)$u['id'] ?>">Delete</button>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<?php include __DIR__ . '/../includes/footer.php'; ?>
