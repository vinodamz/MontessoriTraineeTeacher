<?php
/**
 * admin.php — admin console.
 *
 * Tabs (?tab=…):
 *   teachers (default) — list / add / edit / activate / deactivate
 *   students            — list / add / edit / delete (and reassign teacher)
 *   indicators          — read-only listing of skill_indicators (admin in DB for now)
 *
 * All POST actions are CSRF-protected and admin-gated.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_admin();

$tab = $_GET['tab'] ?? 'teachers';
if (!in_array($tab, ['teachers', 'students', 'indicators'], true)) $tab = 'teachers';

// ---------- POST handlers --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'teacher_create': {
                $name = trim($_POST['name'] ?? '');
                $pin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');
                $role = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
                if ($name === '' || strlen($pin) < 4 || strlen($pin) > 6) {
                    throw new RuntimeException('Name and a 4–6 digit PIN are required.');
                }
                $stmt = db()->prepare("INSERT INTO teachers (name, pin_hash, role, active) VALUES (:n, :h, :r, 1)");
                $stmt->execute([':n' => $name, ':h' => password_hash($pin, PASSWORD_DEFAULT), ':r' => $role]);
                flash_set('ok', "Teacher \"$name\" added.");
                redirect('admin.php?tab=teachers');
            }

            case 'teacher_update': {
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $role = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
                $pin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');
                $active = !empty($_POST['active']) ? 1 : 0;
                if ($id <= 0 || $name === '') throw new RuntimeException('Bad input.');
                if ($pin !== '') {
                    if (strlen($pin) < 4 || strlen($pin) > 6) throw new RuntimeException('PIN must be 4–6 digits.');
                    $stmt = db()->prepare("UPDATE teachers SET name=:n, role=:r, active=:a, pin_hash=:h WHERE id=:id");
                    $stmt->execute([':n'=>$name,':r'=>$role,':a'=>$active,':h'=>password_hash($pin, PASSWORD_DEFAULT),':id'=>$id]);
                } else {
                    $stmt = db()->prepare("UPDATE teachers SET name=:n, role=:r, active=:a WHERE id=:id");
                    $stmt->execute([':n'=>$name,':r'=>$role,':a'=>$active,':id'=>$id]);
                }
                flash_set('ok', "Teacher updated.");
                redirect('admin.php?tab=teachers');
            }

            case 'student_create': {
                $first = trim($_POST['first_name'] ?? '');
                $last  = trim($_POST['last_name'] ?? '');
                $grade = $_POST['grade'] ?? '';
                $tid   = (int)($_POST['teacher_id'] ?? 0);
                if ($first === '' || !in_array($grade, ['Playgroup','Nursery','LKG','UKG'], true) || $tid <= 0) {
                    throw new RuntimeException('First name, grade and teacher are required.');
                }
                $stmt = db()->prepare("INSERT INTO students (first_name, last_name, grade, teacher_id) VALUES (:f,:l,:g,:t)");
                $stmt->execute([':f'=>$first, ':l'=>$last, ':g'=>$grade, ':t'=>$tid]);
                flash_set('ok', "Student \"$first $last\" added.");
                redirect('admin.php?tab=students');
            }

            case 'student_update': {
                $id    = (int)($_POST['id'] ?? 0);
                $first = trim($_POST['first_name'] ?? '');
                $last  = trim($_POST['last_name'] ?? '');
                $grade = $_POST['grade'] ?? '';
                $tid   = (int)($_POST['teacher_id'] ?? 0);
                if ($id <= 0 || $first === '' || !in_array($grade, ['Playgroup','Nursery','LKG','UKG'], true) || $tid <= 0) {
                    throw new RuntimeException('Bad input.');
                }
                $stmt = db()->prepare("UPDATE students SET first_name=:f, last_name=:l, grade=:g, teacher_id=:t WHERE id=:id");
                $stmt->execute([':f'=>$first, ':l'=>$last, ':g'=>$grade, ':t'=>$tid, ':id'=>$id]);
                flash_set('ok', "Student updated.");
                redirect('admin.php?tab=students');
            }

            case 'student_delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Bad input.');
                $stmt = db()->prepare("DELETE FROM students WHERE id = :id");
                $stmt->execute([':id' => $id]);
                flash_set('ok', "Student deleted (cards and baseline removed).");
                redirect('admin.php?tab=students');
            }

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect("admin.php?tab=$tab");
    }
}

// ---------- Data loads -----------------------------------------------------
$teachers = db()->query("SELECT id, name, role, active, created_at FROM teachers ORDER BY (role='admin') DESC, name")->fetchAll();

$students = [];
if ($tab === 'students') {
    $students = db()->query("
        SELECT s.*, t.name AS teacher_name
        FROM students s
        JOIN teachers t ON t.id = s.teacher_id
        ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name
    ")->fetchAll();
}

$indicators = [];
if ($tab === 'indicators') {
    $indicators = db()->query("
        SELECT id, grade, category, indicator_text, display_order, is_active
        FROM skill_indicators
        ORDER BY FIELD(grade,'Playgroup','Nursery','LKG','UKG'), category, display_order
    ")->fetchAll();
}

$editTeacher = null;
if ($tab === 'teachers' && !empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT id, name, role, active FROM teachers WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editTeacher = $stmt->fetch() ?: null;
}
$editStudent = null;
if ($tab === 'students' && !empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM students WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editStudent = $stmt->fetch() ?: null;
}

$pageTitle = 'Admin';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>Admin</h1>
    <nav class="tabs">
        <a href="admin.php?tab=teachers"   class="<?= $tab==='teachers'   ? 'on' : '' ?>">Teachers</a>
        <a href="admin.php?tab=students"   class="<?= $tab==='students'   ? 'on' : '' ?>">Students</a>
        <a href="admin.php?tab=indicators" class="<?= $tab==='indicators' ? 'on' : '' ?>">Indicators</a>
    </nav>
</div>

<?php if ($tab === 'teachers'): ?>
<section class="card">
    <h2><?= $editTeacher ? 'Edit teacher' : 'Add teacher' ?></h2>
    <form method="post" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($editTeacher): ?>
            <input type="hidden" name="action" value="teacher_update">
            <input type="hidden" name="id" value="<?= (int)$editTeacher['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="action" value="teacher_create">
        <?php endif; ?>
        <label>Name
            <input name="name" required maxlength="120" value="<?= e($editTeacher['name'] ?? '') ?>">
        </label>
        <label>Role
            <select name="role">
                <option value="teacher" <?= ($editTeacher['role'] ?? '') === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                <option value="admin"   <?= ($editTeacher['role'] ?? '') === 'admin'   ? 'selected' : '' ?>>Admin</option>
            </select>
        </label>
        <label>PIN <span class="muted small">(<?= $editTeacher ? 'leave blank to keep current' : '4–6 digits' ?>)</span>
            <input name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" <?= $editTeacher ? '' : 'required' ?>>
        </label>
        <?php if ($editTeacher): ?>
            <label class="check">
                <input type="checkbox" name="active" value="1" <?= !empty($editTeacher['active']) ? 'checked' : '' ?>>
                Active
            </label>
        <?php endif; ?>
        <div class="form-actions">
            <?php if ($editTeacher): ?><a class="btn btn-ghost" href="admin.php?tab=teachers">Cancel</a><?php endif; ?>
            <button class="btn btn-primary"><?= $editTeacher ? 'Save' : 'Add teacher' ?></button>
        </div>
    </form>
</section>

<section class="card">
    <h2>All teachers</h2>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($teachers as $t): ?>
            <tr>
                <td><?= e($t['name']) ?></td>
                <td><span class="pill"><?= e($t['role']) ?></span></td>
                <td><?= !empty($t['active']) ? '<span class="status-dot status-ok"></span>Active' : '<span class="status-dot status-none"></span>Inactive' ?></td>
                <td class="muted small"><?= e(date('j M Y', strtotime($t['created_at']))) ?></td>
                <td><a class="btn btn-ghost small" href="admin.php?tab=teachers&edit=<?= (int)$t['id'] ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<?php if ($tab === 'students'): ?>
<section class="card">
    <h2><?= $editStudent ? 'Edit student' : 'Add student' ?></h2>
    <form method="post" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($editStudent): ?>
            <input type="hidden" name="action" value="student_update">
            <input type="hidden" name="id" value="<?= (int)$editStudent['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="action" value="student_create">
        <?php endif; ?>
        <label>First name
            <input name="first_name" required maxlength="80" value="<?= e($editStudent['first_name'] ?? '') ?>">
        </label>
        <label>Last name
            <input name="last_name" maxlength="80" value="<?= e($editStudent['last_name'] ?? '') ?>">
        </label>
        <label>Grade
            <select name="grade" required>
                <?php foreach (['Playgroup','Nursery','LKG','UKG'] as $g): ?>
                    <option value="<?= e($g) ?>" <?= ($editStudent['grade'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Teacher
            <select name="teacher_id" required>
                <?php foreach ($teachers as $t): if (empty($t['active'])) continue; ?>
                    <option value="<?= (int)$t['id'] ?>" <?= (int)($editStudent['teacher_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= e($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions">
            <?php if ($editStudent): ?><a class="btn btn-ghost" href="admin.php?tab=students">Cancel</a><?php endif; ?>
            <button class="btn btn-primary"><?= $editStudent ? 'Save' : 'Add student' ?></button>
        </div>
    </form>
</section>

<section class="card">
    <h2>All students</h2>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Name</th><th>Grade</th><th>Teacher</th><th>Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($students as $s): ?>
            <tr>
                <td><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></td>
                <td><span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span></td>
                <td><?= e($s['teacher_name']) ?></td>
                <td class="muted small"><?= e(date('j M Y', strtotime($s['created_at']))) ?></td>
                <td class="row-actions">
                    <a class="btn btn-ghost small" href="admin.php?tab=students&edit=<?= (int)$s['id'] ?>">Edit</a>
                    <form method="post" onsubmit="return confirm('Delete <?= e(addslashes($s['first_name'].' '.$s['last_name'])) ?>? This also removes their cards and baseline.');" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="student_delete">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-ghost small danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<?php if ($tab === 'indicators'): ?>
<section class="card">
    <h2>Curriculum indicators</h2>
    <p class="muted">Read-only listing. Edit directly in MySQL for now — a full UI is on the roadmap.</p>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Grade</th><th>Category</th><th>Indicator</th><th>Order</th><th>Active</th></tr></thead>
        <tbody>
        <?php foreach ($indicators as $i): ?>
            <tr>
                <td><span class="<?= e(grade_badge_class($i['grade'])) ?>"><?= e($i['grade']) ?></span></td>
                <td><?= e($i['category']) ?></td>
                <td><?= e($i['indicator_text']) ?></td>
                <td class="muted"><?= (int)$i['display_order'] ?></td>
                <td><?= !empty($i['is_active']) ? '✓' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
