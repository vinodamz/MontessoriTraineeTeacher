<?php
/**
 * admin.php — unified user management.
 *
 * Cross-module admin lives here. Per-module admin (students, indicators,
 * task columns, recurrences) stays in the module's own admin page.
 *
 * Actions: create / update / activate / deactivate / delete users,
 *          assign modules + role.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

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

function modules_from_post(array $post): string
{
    $picked = $post['modules'] ?? [];
    if (!is_array($picked)) $picked = [];
    $valid = array_intersect($picked, ['tasks', 'montessori', 'students']);
    return implode(',', $valid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        $name    = trim($_POST['name'] ?? '');
        $pin     = preg_replace('/\D/', '', $_POST['pin'] ?? '');
        $role    = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
        $modules = modules_from_post($_POST);

        if ($name === '' || strlen($pin) < 4 || strlen($pin) > 6) {
            flash_set('error', 'Name and a 4–6 digit PIN are required.');
            redirect('/admin.php');
        }
        if (pin_is_in_use($pin)) {
            flash_set('error', 'That PIN is already in use. Pick another.');
            redirect('/admin.php');
        }

        $stmt = db()->prepare("INSERT INTO users (name, pin_hash, role, modules, active) VALUES (:n, :h, :r, :m, 1)");
        $stmt->execute([
            ':n' => $name,
            ':h' => password_hash($pin, PASSWORD_DEFAULT),
            ':r' => $role,
            ':m' => $modules,
        ]);
        flash_set('ok', "User added. Their PIN is $pin — share it with them privately.");
        redirect('/admin.php');
    }

    if ($op === 'update') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $role    = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
        $active  = !empty($_POST['active']) ? 1 : 0;
        $modules = modules_from_post($_POST);
        $newPin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');

        if ($id === $me['id'] && $role !== 'admin') {
            flash_set('error', "You can't demote yourself.");
            redirect('/admin.php');
        }
        if ($id === $me['id'] && !$active) {
            flash_set('error', "You can't deactivate yourself.");
            redirect('/admin.php');
        }
        if ($name === '' || $id <= 0) {
            flash_set('error', 'Bad input.');
            redirect('/admin.php');
        }

        if ($newPin !== '') {
            if (strlen($newPin) < 4 || strlen($newPin) > 6) {
                flash_set('error', 'PIN must be 4–6 digits.');
                redirect('/admin.php');
            }
            if (pin_is_in_use($newPin, $id)) {
                flash_set('error', 'That PIN is already in use.');
                redirect('/admin.php');
            }
            $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a, modules=:m, pin_hash=:h WHERE id=:id");
            $stmt->execute([
                ':n' => $name, ':r' => $role, ':a' => $active, ':m' => $modules,
                ':h' => password_hash($newPin, PASSWORD_DEFAULT), ':id' => $id,
            ]);
        } else {
            $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a, modules=:m WHERE id=:id");
            $stmt->execute([':n'=>$name, ':r'=>$role, ':a'=>$active, ':m'=>$modules, ':id'=>$id]);
        }
        flash_set('ok', 'User updated.');
        redirect('/admin.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $me['id']) {
            flash_set('error', "You can't delete yourself.");
            redirect('/admin.php');
        }
        try {
            $stmt = db()->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            flash_set('ok', 'User deleted.');
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete: this user has rows that reference them (students, assessments, or tasks). Deactivate instead.');
        }
        redirect('/admin.php');
    }
}

$users = db()->query("
    SELECT id, name, role, modules, active, created_at
    FROM users
    ORDER BY (role='admin') DESC, name
")->fetchAll();

$pageTitle = 'Admin — Users';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>Users &amp; access</h1>
    <p class="muted">Manage who can sign in and which modules they see.
        Module-specific admin lives inside each module
        (<a href="/assessment/admin.php">Assessment admin</a> ·
         <a href="/tasks/admin.php">Tasks admin</a>).</p>
</div>

<details class="card card-form" open>
    <summary>Add a user</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <div class="row">
            <div class="field">
                <label>Name</label>
                <input name="name" required maxlength="120" placeholder="e.g. Priya Sharma">
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
            <div class="field">
                <label>Modules</label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="montessori" checked><span>Assessment</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="tasks"><span>Tasks</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="students"><span>Students</span></label>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary">Add user</button>
        </div>
    </form>
</details>

<h2 class="section-h-spaced">Active &amp; inactive</h2>

<?php foreach ($users as $u): ?>
    <form id="u-edit-<?= (int)$u['id'] ?>" method="post" hidden>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="update">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    </form>
    <form id="u-del-<?= (int)$u['id'] ?>" method="post" hidden
          onsubmit="return confirm('Delete this user? Anything they created or are assigned to will block deletion — deactivate instead if so.')">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="delete">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    </form>
<?php endforeach; ?>

<ul class="team-list">
    <?php foreach ($users as $u):
        $fid     = 'u-edit-' . (int)$u['id'];
        $mods    = user_modules_from_row($u);
        $hasA    = in_array('montessori', $mods, true);
        $hasT    = in_array('tasks', $mods, true);
        $hasS    = in_array('students', $mods, true);
    ?>
        <li class="team-row" style="--card: <?= e(user_color((int)$u['id'])) ?>;">
            <div class="team-dot"><?= e(user_initials($u['name'])) ?></div>
            <div>
                <div class="team-name"><?= e($u['name']) ?></div>
                <div class="team-meta">
                    <?= e($u['role']) ?>
                    · <?= $u['active'] ? 'active' : 'inactive' ?>
                    <?php if ($u['role'] !== 'admin'): ?>
                        · modules: <?= $mods ? e(implode(', ', $mods)) : '<em>none</em>' ?>
                    <?php else: ?>
                        · <em>all modules (admin)</em>
                    <?php endif; ?>
                    · since <?= e(substr((string)$u['created_at'], 0, 10)) ?>
                </div>
            </div>
            <div class="team-edit">
                <input form="<?= $fid ?>" name="name" value="<?= e($u['name']) ?>" maxlength="120" aria-label="Name">
                <select form="<?= $fid ?>" name="role" aria-label="Role">
                    <option value="teacher" <?= $u['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                    <option value="admin"   <?= $u['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                </select>
                <label class="checkbox" title="Assessment module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="montessori" <?= $hasA ? 'checked' : '' ?>>
                    <span>Assess</span>
                </label>
                <label class="checkbox" title="Tasks module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="tasks" <?= $hasT ? 'checked' : '' ?>>
                    <span>Tasks</span>
                </label>
                <label class="checkbox" title="Students module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="students" <?= $hasS ? 'checked' : '' ?>>
                    <span>Students</span>
                </label>
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

<?php require __DIR__ . '/includes/footer.php'; ?>
