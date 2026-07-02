<?php
/**
 * custom_indicators.php — per-student custom indicators.
 *
 * Teachers can manage custom indicators for their own students;
 * admins can manage them for any student.
 *
 * GET ?student_id=N → list + add/edit form.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_module('montessori');

$studentId = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;

$stmt = db()->prepare("SELECT id, first_name, last_name, grade, teacher_id FROM students WHERE id = :id");
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}
if ($user['role'] !== 'admin' && (int)$student['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'You can only manage indicators for your own students.';
    exit;
}

// ---------- POST handlers --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'create': {
                $category = trim($_POST['category'] ?? '');
                $text     = trim($_POST['indicator_text'] ?? '');
                $order    = (int)($_POST['display_order'] ?? 0);
                if ($category === '' || $text === '') {
                    throw new RuntimeException('Category and indicator text are required.');
                }
                if ($order === 0) {
                    $q = db()->prepare("SELECT COALESCE(MAX(display_order),0) + 1 FROM student_custom_indicators WHERE student_id = :s AND category = :c");
                    $q->execute([':s' => $studentId, ':c' => $category]);
                    $order = (int)$q->fetchColumn();
                }
                $stmt = db()->prepare("INSERT INTO student_custom_indicators (student_id, teacher_id, category, indicator_text, display_order, is_active) VALUES (:s,:t,:c,:tx,:o,1)");
                $stmt->execute([':s'=>$studentId, ':t'=>(int)$user['id'], ':c'=>$category, ':tx'=>$text, ':o'=>$order]);
                flash_set('ok', 'Custom indicator added.');
                redirect("custom_indicators.php?student_id=$studentId");
            }

            case 'update': {
                $id       = (int)($_POST['id'] ?? 0);
                $category = trim($_POST['category'] ?? '');
                $text     = trim($_POST['indicator_text'] ?? '');
                $order    = (int)($_POST['display_order'] ?? 0);
                $active   = !empty($_POST['is_active']) ? 1 : 0;
                if ($id <= 0 || $category === '' || $text === '') throw new RuntimeException('Bad input.');
                $stmt = db()->prepare("UPDATE student_custom_indicators SET category=:c, indicator_text=:t, display_order=:o, is_active=:a WHERE id=:id AND student_id=:s");
                $stmt->execute([':c'=>$category, ':t'=>$text, ':o'=>$order, ':a'=>$active, ':id'=>$id, ':s'=>$studentId]);
                flash_set('ok', 'Indicator updated.');
                redirect("custom_indicators.php?student_id=$studentId");
            }

            case 'toggle': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Bad input.');
                $stmt = db()->prepare("UPDATE student_custom_indicators SET is_active = 1 - is_active WHERE id=:id AND student_id=:s");
                $stmt->execute([':id'=>$id, ':s'=>$studentId]);
                flash_set('ok', 'Indicator toggled.');
                redirect("custom_indicators.php?student_id=$studentId");
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Bad input.');
                $q = db()->prepare("SELECT COUNT(*) FROM evaluation_cards WHERE indicator_id = :i AND is_custom_indicator = 1");
                $q->execute([':i' => $id]);
                if ((int)$q->fetchColumn() > 0) {
                    throw new RuntimeException('This custom indicator has past assessment data. Deactivate it instead.');
                }
                $stmt = db()->prepare("DELETE FROM student_custom_indicators WHERE id=:id AND student_id=:s");
                $stmt->execute([':id'=>$id, ':s'=>$studentId]);
                flash_set('ok', 'Indicator deleted.');
                redirect("custom_indicators.php?student_id=$studentId");
            }

            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        flash_set('error', $ex->getMessage());
        redirect("custom_indicators.php?student_id=$studentId");
    }
}

// ---------- Data loads -----------------------------------------------------
$stmt = db()->prepare("
    SELECT id, category, indicator_text, display_order, is_active, created_at
    FROM student_custom_indicators
    WHERE student_id = :s
    ORDER BY category, display_order, id
");
$stmt->execute([':s' => $studentId]);
$rows = $stmt->fetchAll();

// Existing categories for this student's grade (helps the autocomplete).
$stmt = db()->prepare("SELECT DISTINCT category FROM skill_indicators WHERE grade = :g ORDER BY category");
$stmt->execute([':g' => $student['grade']]);
$gradeCats = array_column($stmt->fetchAll(), 'category');

$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM student_custom_indicators WHERE id = :id AND student_id = :s");
    $stmt->execute([':id' => (int)$_GET['edit'], ':s' => $studentId]);
    $edit = $stmt->fetch() ?: null;
}

$fullName  = trim($student['first_name'] . ' ' . $student['last_name']);
$pageTitle = "Custom indicators · $fullName";
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Custom indicators for <?= e($fullName) ?></h1>
        <p class="muted"><?= e($student['grade']) ?> · indicators unique to this student (shown in every monthly assessment)</p>
    </div>
    <div class="head-actions">
        <a class="btn" href="progress.php?student_id=<?= $studentId ?>">Progress</a>
        <a class="btn btn-ghost" href="index.php">Back</a>
    </div>
</div>

<section class="card">
    <h2><?= $edit ? 'Edit custom indicator' : 'Add custom indicator' ?></h2>
    <form method="post" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($edit): ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="action" value="create">
        <?php endif; ?>
        <label>Category
            <input list="catList" name="category" required maxlength="60" value="<?= e($edit['category'] ?? '') ?>" placeholder="e.g. MATHEMATICS or a fresh name">
            <datalist id="catList">
                <?php foreach ($gradeCats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
            </datalist>
        </label>
        <label>Display order <span class="muted small">(0 = auto)</span>
            <input type="number" name="display_order" min="0" value="<?= (int)($edit['display_order'] ?? 0) ?>">
        </label>
        <label class="full">Indicator text
            <input name="indicator_text" required maxlength="500" value="<?= e($edit['indicator_text'] ?? '') ?>">
        </label>
        <?php if ($edit): ?>
            <label class="check">
                <input type="checkbox" name="is_active" value="1" <?= !empty($edit['is_active']) ? 'checked' : '' ?>>
                Active
            </label>
        <?php endif; ?>
        <div class="form-actions">
            <?php if ($edit): ?><a class="btn btn-ghost" href="custom_indicators.php?student_id=<?= $studentId ?>">Cancel</a><?php endif; ?>
            <button class="btn btn-primary"><?= $edit ? 'Save' : 'Add indicator' ?></button>
        </div>
    </form>
</section>

<section class="card">
    <h2>Existing custom indicators</h2>
    <?php if (!$rows): ?>
        <p class="muted">No custom indicators for <?= e($fullName) ?> yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Category</th><th>Indicator</th><th>Order</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= empty($r['is_active']) ? 'is-inactive' : '' ?>">
                <td><?= e($r['category']) ?></td>
                <td><?= e($r['indicator_text']) ?></td>
                <td class="muted"><?= (int)$r['display_order'] ?></td>
                <td><?= !empty($r['is_active']) ? '✓' : '—' ?></td>
                <td class="row-actions">
                    <a class="btn btn-ghost small" href="custom_indicators.php?student_id=<?= $studentId ?>&edit=<?= (int)$r['id'] ?>">Edit</a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-ghost small"><?= !empty($r['is_active']) ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this custom indicator? Blocked if past assessments reference it.');" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-ghost small danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
