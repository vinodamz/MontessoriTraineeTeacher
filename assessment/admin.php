<?php
/**
 * admin.php — admin console.
 *
 * Tabs (?tab=…):
 *   teachers (default) — list / add / edit / activate / deactivate
 *   students            — list / add / edit / delete (and reassign teacher)
 *   indicators          — full CRUD on the curriculum indicators
 *   rating              — edit the D/P/N rating scheme
 *
 * All POST actions are CSRF-protected and admin-gated.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_admin();

$VALID_TABS = ['teachers', 'students', 'indicators', 'rating'];
$tab = $_GET['tab'] ?? 'teachers';
if (!in_array($tab, $VALID_TABS, true)) $tab = 'teachers';

$GRADES = ['Playgroup', 'Nursery', 'LKG', 'UKG'];

// ---------- POST handlers --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ----- Teachers ---------------------------------------------
            case 'teacher_create': {
                $name = trim($_POST['name'] ?? '');
                $pin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');
                $role = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
                if ($name === '' || strlen($pin) < 4 || strlen($pin) > 6) {
                    throw new RuntimeException('Name and a 4–6 digit PIN are required.');
                }
                $stmt = db()->prepare("INSERT INTO users (name, pin_hash, role, modules, active) VALUES (:n, :h, :r, 'montessori', 1)");
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
                    $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a, pin_hash=:h WHERE id=:id");
                    $stmt->execute([':n'=>$name,':r'=>$role,':a'=>$active,':h'=>password_hash($pin, PASSWORD_DEFAULT),':id'=>$id]);
                } else {
                    $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a WHERE id=:id");
                    $stmt->execute([':n'=>$name,':r'=>$role,':a'=>$active,':id'=>$id]);
                }
                flash_set('ok', "Teacher updated.");
                redirect('admin.php?tab=teachers');
            }

            // ----- Students ---------------------------------------------
            case 'student_create': {
                $first = trim($_POST['first_name'] ?? '');
                $last  = trim($_POST['last_name'] ?? '');
                $grade = $_POST['grade'] ?? '';
                $tid   = (int)($_POST['teacher_id'] ?? 0);
                if ($first === '' || !in_array($grade, $GRADES, true) || $tid <= 0) {
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
                if ($id <= 0 || $first === '' || !in_array($grade, $GRADES, true) || $tid <= 0) {
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

            // ----- Indicators -------------------------------------------
            case 'indicator_create': {
                $grade    = $_POST['grade'] ?? '';
                $category = trim($_POST['category'] ?? '');
                $text     = trim($_POST['indicator_text'] ?? '');
                $order    = (int)($_POST['display_order'] ?? 0);
                if (!in_array($grade, $GRADES, true) || $category === '' || $text === '') {
                    throw new RuntimeException('Grade, category and indicator text are required.');
                }
                if ($order === 0) {
                    $q = db()->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM skill_indicators WHERE grade = :g AND category = :c");
                    $q->execute([':g' => $grade, ':c' => $category]);
                    $order = (int)$q->fetchColumn();
                }
                $stmt = db()->prepare("INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES (:g,:c,:t,:o,1)");
                $stmt->execute([':g'=>$grade, ':c'=>$category, ':t'=>$text, ':o'=>$order]);
                flash_set('ok', "Indicator added to $grade · $category.");
                redirect('admin.php?tab=indicators');
            }

            case 'indicator_update': {
                $id       = (int)($_POST['id'] ?? 0);
                $grade    = $_POST['grade'] ?? '';
                $category = trim($_POST['category'] ?? '');
                $text     = trim($_POST['indicator_text'] ?? '');
                $order    = (int)($_POST['display_order'] ?? 0);
                $active   = !empty($_POST['is_active']) ? 1 : 0;
                if ($id <= 0 || !in_array($grade, $GRADES, true) || $category === '' || $text === '') {
                    throw new RuntimeException('Bad input.');
                }
                $stmt = db()->prepare("UPDATE skill_indicators SET grade=:g, category=:c, indicator_text=:t, display_order=:o, is_active=:a WHERE id=:id");
                $stmt->execute([':g'=>$grade, ':c'=>$category, ':t'=>$text, ':o'=>$order, ':a'=>$active, ':id'=>$id]);
                flash_set('ok', "Indicator updated.");
                redirect('admin.php?tab=indicators');
            }

            case 'indicator_toggle': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Bad input.');
                $stmt = db()->prepare("UPDATE skill_indicators SET is_active = 1 - is_active WHERE id = :id");
                $stmt->execute([':id' => $id]);
                flash_set('ok', "Indicator toggled.");
                redirect('admin.php?tab=indicators');
            }

            case 'indicator_delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Bad input.');
                // Soft check: refuse to delete if any evaluation_cards reference it.
                $q = db()->prepare("SELECT COUNT(*) FROM evaluation_cards WHERE indicator_id = :i AND is_custom_indicator = 0");
                $q->execute([':i' => $id]);
                if ((int)$q->fetchColumn() > 0) {
                    throw new RuntimeException('Indicator is used in past assessments. Deactivate instead of deleting to keep historical scores intact.');
                }
                $stmt = db()->prepare("DELETE FROM skill_indicators WHERE id = :id");
                $stmt->execute([':id' => $id]);
                flash_set('ok', "Indicator deleted.");
                redirect('admin.php?tab=indicators');
            }

            // ----- Rating config ----------------------------------------
            case 'rating_update': {
                $rows = $_POST['r'] ?? [];
                if (!is_array($rows)) throw new RuntimeException('Bad input.');
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $upd = $pdo->prepare("UPDATE rating_config SET label=:l, color=:c, numeric_value=:n, is_active=:a WHERE id=:id");
                    foreach ($rows as $id => $f) {
                        $id   = (int)$id;
                        $lbl  = trim($f['label'] ?? '');
                        $col  = trim($f['color'] ?? '');
                        $num  = (int)($f['numeric_value'] ?? 0);
                        $act  = !empty($f['is_active']) ? 1 : 0;
                        if ($id <= 0 || $lbl === '' || $col === '') continue;
                        $upd->execute([':l'=>$lbl, ':c'=>$col, ':n'=>$num, ':a'=>$act, ':id'=>$id]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                flash_set('ok', 'Rating scheme updated.');
                redirect('admin.php?tab=rating');
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
// Users in the assessment "teachers" tab = anyone with montessori module access OR admin role.
$teachers = db()->query("
    SELECT id, name, role, active, created_at
    FROM users
    WHERE role = 'admin' OR FIND_IN_SET('montessori', modules) > 0
    ORDER BY (role='admin') DESC, name
")->fetchAll();

$students = [];
if ($tab === 'students') {
    $students = db()->query("
        SELECT s.*, t.name AS teacher_name
        FROM students s
        JOIN users t ON t.id = s.teacher_id
        ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name
    ")->fetchAll();
}

$indicators       = [];
$gradeCategories  = [];   // for the indicator-add form's category suggestions
$filterGrade      = null;
if ($tab === 'indicators') {
    $filterGrade = $_GET['grade'] ?? null;
    if (!in_array($filterGrade, $GRADES, true)) $filterGrade = null;

    $sql = "
        SELECT id, grade, category, indicator_text, display_order, is_active
        FROM skill_indicators
        " . ($filterGrade ? "WHERE grade = :g " : "") . "
        ORDER BY FIELD(grade,'Playgroup','Nursery','LKG','UKG'), category, display_order, id
    ";
    $stmt = db()->prepare($sql);
    if ($filterGrade) $stmt->bindValue(':g', $filterGrade);
    $stmt->execute();
    $indicators = $stmt->fetchAll();

    // Build a per-grade category list for the picker.
    $rows = db()->query("SELECT DISTINCT grade, category FROM skill_indicators ORDER BY grade, category")->fetchAll();
    foreach ($rows as $r) $gradeCategories[$r['grade']][] = $r['category'];
}

$ratings = [];
if ($tab === 'rating') {
    $ratings = db()->query("SELECT * FROM rating_config ORDER BY numeric_value DESC, id")->fetchAll();
}

$editTeacher = null;
if ($tab === 'teachers' && !empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT id, name, role, active FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editTeacher = $stmt->fetch() ?: null;
}
$editStudent = null;
if ($tab === 'students' && !empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM students WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editStudent = $stmt->fetch() ?: null;
}
$editIndicator = null;
if ($tab === 'indicators' && !empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM skill_indicators WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editIndicator = $stmt->fetch() ?: null;
}

$pageTitle = 'Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <h1>Admin</h1>
    <nav class="tabs">
        <a href="admin.php?tab=teachers"   class="<?= $tab==='teachers'   ? 'on' : '' ?>">Teachers</a>
        <a href="admin.php?tab=students"   class="<?= $tab==='students'   ? 'on' : '' ?>">Students</a>
        <a href="admin.php?tab=indicators" class="<?= $tab==='indicators' ? 'on' : '' ?>">Indicators</a>
        <a href="admin.php?tab=rating"     class="<?= $tab==='rating'     ? 'on' : '' ?>">Rating scheme</a>
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
                <?php foreach ($GRADES as $g): ?>
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
                    <a class="btn btn-ghost small" href="custom_indicators.php?student_id=<?= (int)$s['id'] ?>">Custom indicators</a>
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
    <h2><?= $editIndicator ? 'Edit indicator' : 'Add indicator' ?></h2>
    <form method="post" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($editIndicator): ?>
            <input type="hidden" name="action" value="indicator_update">
            <input type="hidden" name="id" value="<?= (int)$editIndicator['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="action" value="indicator_create">
        <?php endif; ?>
        <label>Grade
            <select name="grade" required id="indGrade">
                <?php foreach ($GRADES as $g): ?>
                    <option value="<?= e($g) ?>" <?= ($editIndicator['grade'] ?? $filterGrade ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Category
            <input list="categoryList" name="category" required maxlength="60"
                   value="<?= e($editIndicator['category'] ?? '') ?>"
                   placeholder="e.g. MATHEMATICS">
            <datalist id="categoryList">
                <?php foreach ($gradeCategories as $g => $cats): foreach (array_unique($cats) as $c): ?>
                    <option value="<?= e($c) ?>"><?= e("$g · $c") ?></option>
                <?php endforeach; endforeach; ?>
            </datalist>
        </label>
        <label class="full">Indicator text
            <input name="indicator_text" required maxlength="500" value="<?= e($editIndicator['indicator_text'] ?? '') ?>">
        </label>
        <label>Display order <span class="muted small">(0 = auto)</span>
            <input type="number" name="display_order" min="0" value="<?= (int)($editIndicator['display_order'] ?? 0) ?>">
        </label>
        <?php if ($editIndicator): ?>
            <label class="check">
                <input type="checkbox" name="is_active" value="1" <?= !empty($editIndicator['is_active']) ? 'checked' : '' ?>>
                Active
            </label>
        <?php endif; ?>
        <div class="form-actions">
            <?php if ($editIndicator): ?><a class="btn btn-ghost" href="admin.php?tab=indicators">Cancel</a><?php endif; ?>
            <button class="btn btn-primary"><?= $editIndicator ? 'Save' : 'Add indicator' ?></button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>All indicators<?= $filterGrade ? ' — ' . e($filterGrade) : '' ?></h2>
        <nav class="tabs subtle">
            <a class="<?= !$filterGrade ? 'on' : '' ?>" href="admin.php?tab=indicators">All</a>
            <?php foreach ($GRADES as $g): ?>
                <a class="<?= $filterGrade === $g ? 'on' : '' ?>" href="admin.php?tab=indicators&grade=<?= e($g) ?>"><?= e($g) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <p class="muted">Deactivate to hide an indicator from new assessments without losing past scores. Delete is only allowed if no past assessments reference it.</p>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Grade</th><th>Category</th><th>Indicator</th><th>Order</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($indicators as $i): ?>
            <tr class="<?= empty($i['is_active']) ? 'is-inactive' : '' ?>">
                <td><span class="<?= e(grade_badge_class($i['grade'])) ?>"><?= e($i['grade']) ?></span></td>
                <td><?= e($i['category']) ?></td>
                <td><?= e($i['indicator_text']) ?></td>
                <td class="muted"><?= (int)$i['display_order'] ?></td>
                <td><?= !empty($i['is_active']) ? '✓' : '—' ?></td>
                <td class="row-actions">
                    <a class="btn btn-ghost small" href="admin.php?tab=indicators&edit=<?= (int)$i['id'] ?>">Edit</a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="indicator_toggle">
                        <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                        <button class="btn btn-ghost small"><?= !empty($i['is_active']) ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this indicator? This is blocked if past assessments reference it.');" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="indicator_delete">
                        <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                        <button class="btn btn-ghost small danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$indicators): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:1.5rem">No indicators<?= $filterGrade ? ' for ' . e($filterGrade) : '' ?>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<?php if ($tab === 'rating'): ?>
<section class="card">
    <h2>Rating scheme</h2>
    <p class="muted">These are the rating codes used across every assessment. <code>numeric_value</code> drives the per-category averages on the Progress page.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="rating_update">
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th>Code</th><th>Label</th><th>Color</th><th>Numeric value</th><th>Active</th></tr></thead>
            <tbody>
            <?php foreach ($ratings as $r): ?>
                <tr>
                    <td><strong style="color:<?= e($r['color']) ?>"><?= e($r['code']) ?></strong></td>
                    <td><input name="r[<?= (int)$r['id'] ?>][label]" value="<?= e($r['label']) ?>" maxlength="60" required></td>
                    <td><input name="r[<?= (int)$r['id'] ?>][color]" value="<?= e($r['color']) ?>" maxlength="20" required></td>
                    <td><input type="number" name="r[<?= (int)$r['id'] ?>][numeric_value]" value="<?= (int)$r['numeric_value'] ?>" min="0" max="100" required></td>
                    <td><input type="checkbox" name="r[<?= (int)$r['id'] ?>][is_active]" value="1" <?= !empty($r['is_active']) ? 'checked' : '' ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">Save rating scheme</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
