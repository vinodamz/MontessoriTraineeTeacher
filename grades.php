<?php
/**
 * grades.php — configure grade levels (admin-only).
 *
 * The list every module validates against, builds dropdowns from and sorts by.
 * Adding a grade here makes it available everywhere immediately: no code change
 * and no migration, which is the whole point of this page.
 *
 * Fields:
 *   name        stored on students.grade — changing it would orphan existing
 *               records, so it's fixed once created.
 *   label       what people see; safe to change any time.
 *   order       display + sort position across the app.
 *   promotes to grade applied by the June year-end rollover; blank = stays put.
 *   active      inactive grades disappear from pickers but keep sorting old
 *               records correctly.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    try {
        if ($op === 'create') {
            $name  = trim((string)($_POST['name'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $order = (int)($_POST['sort_order'] ?? 0);
            $to    = trim((string)($_POST['promotes_to'] ?? ''));

            // The name becomes the stored value on every student record, so keep
            // it to something predictable rather than free text.
            if (!preg_match('/^[A-Za-z0-9 .\/-]{1,40}$/', $name)) {
                flash_set('error', 'Name must be 1–40 characters: letters, numbers, spaces, dot, slash or hyphen.');
                redirect('/grades.php');
            }
            if ($label === '') $label = $name;
            if (in_array($name, grade_names(false), true)) {
                flash_set('error', "“$name” already exists.");
                redirect('/grades.php');
            }
            db()->prepare("
                INSERT INTO grade_levels (name, label, sort_order, promotes_to, is_active)
                VALUES (:n, :l, :o, :t, 1)
            ")->execute([
                ':n' => $name, ':l' => $label, ':o' => $order,
                ':t' => $to === '' ? null : $to,
            ]);
            flash_set('ok', "Grade “$label” added — it's now available across every module.");
            redirect('/grades.php');
        }

        if ($op === 'update') {
            $id     = (int)($_POST['id'] ?? 0);
            $label  = trim((string)($_POST['label'] ?? ''));
            $order  = (int)($_POST['sort_order'] ?? 0);
            $to     = trim((string)($_POST['promotes_to'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id <= 0 || $label === '') {
                flash_set('error', 'A label is required.');
                redirect('/grades.php');
            }
            db()->prepare("
                UPDATE grade_levels
                SET label = :l, sort_order = :o, promotes_to = :t, is_active = :a
                WHERE id = :id
            ")->execute([
                ':l' => $label, ':o' => $order,
                ':t' => $to === '' ? null : $to,
                ':a' => $active, ':id' => $id,
            ]);
            flash_set('ok', 'Grade updated.');
            redirect('/grades.php');
        }

        if ($op === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare("SELECT name, label FROM grade_levels WHERE id = :id");
            $st->execute([':id' => $id]);
            $row = $st->fetch();
            if (!$row) { flash_set('error', 'That grade no longer exists.'); redirect('/grades.php'); }

            // Deleting a grade that children are still in would leave records
            // pointing at nothing. Deactivating keeps history readable.
            $used = 0;
            try {
                $c = db()->prepare("SELECT COUNT(*) FROM students WHERE grade = :g");
                $c->execute([':g' => $row['name']]);
                $used = (int)$c->fetchColumn();
            } catch (Throwable $e) {}
            if ($used > 0) {
                flash_set('error', "“{$row['label']}” still has $used student"
                    . ($used === 1 ? '' : 's') . " — deactivate it instead of deleting.");
                redirect('/grades.php');
            }
            db()->prepare("DELETE FROM grade_levels WHERE id = :id")->execute([':id' => $id]);
            flash_set('ok', "Grade “{$row['label']}” removed.");
            redirect('/grades.php');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Could not save: ' . $e->getMessage());
        redirect('/grades.php');
    }

    flash_set('error', 'Unknown action.');
    redirect('/grades.php');
}

// ---- Render --------------------------------------------------------------
$rows = [];
$tableMissing = false;
try {
    $rows = db()->query("
        SELECT id, name, label, sort_order, promotes_to, is_active
        FROM grade_levels ORDER BY sort_order, name
    ")->fetchAll();
} catch (Throwable $e) {
    $tableMissing = true;
}

// How many children sit in each grade — the number that makes deactivating or
// deleting a considered decision rather than a guess.
$counts = [];
try {
    foreach (db()->query("
        SELECT grade, COUNT(*) n FROM students
        WHERE COALESCE(is_active,1) = 1 GROUP BY grade
    ") as $r) $counts[(string)$r['grade']] = (int)$r['n'];
} catch (Throwable $e) {}

$allNames  = grade_names(false);
$pageTitle = 'Grades';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Grades</h1>
        <p class="muted">
            One list, used by every module — students, assessment, admissions, fees,
            attendance and reports all read from here.
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/admin.php">Users &amp; access</a>
    </div>
</div>

<?php if ($tableMissing): ?>
    <div class="flash flash-error">
        The <code>grade_levels</code> table isn't there yet — the app is running on its
        built-in list until <code>sql/migrate_050_grade_levels.sql</code> has been applied.
        Deploys run migrations automatically; check <code>/last-migrate.log</code>.
    </div>
<?php endif; ?>

<?php
// The per-row edit forms live out here as hidden siblings: a <form> as a direct
// child of <tr> is invalid and browsers hoist it out of the table, losing the
// fields. Inputs bind to these by id via the form= attribute (same pattern as
// admin.php).
foreach ($rows as $g): ?>
    <form id="g<?= (int)$g['id'] ?>" method="post" hidden>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="update">
        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
    </form>
<?php endforeach; ?>

<div class="card">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Name <span class="muted small">(stored)</span></th>
                <th>Label <span class="muted small">(shown)</span></th>
                <th>Promotes to</th>
                <th>Active</th>
                <th>Students</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted">No grades configured.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $g): $fid = 'g' . (int)$g['id']; $n = $counts[$g['name']] ?? 0; ?>
                <tr>
                    <td><input form="<?= $fid ?>" name="sort_order" type="number" value="<?= (int)$g['sort_order'] ?>" style="width:5rem;" aria-label="Sort order"></td>
                    <td><code><?= e($g['name']) ?></code></td>
                    <td><input form="<?= $fid ?>" name="label" value="<?= e($g['label']) ?>" maxlength="80" aria-label="Label"></td>
                    <td>
                        <select form="<?= $fid ?>" name="promotes_to" aria-label="Promotes to">
                            <option value="">— stays put —</option>
                            <?php foreach ($allNames as $nm): if ($nm === $g['name']) continue; ?>
                                <option value="<?= e($nm) ?>" <?= (string)$g['promotes_to'] === $nm ? 'selected' : '' ?>><?= e(grade_label($nm)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><label class="checkbox"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= (int)$g['is_active'] === 1 ? 'checked' : '' ?>><span>Active</span></label></td>
                    <td><?= $n > 0 ? (int)$n : '<span class="muted">—</span>' ?></td>
                    <td style="white-space:nowrap;">
                        <button form="<?= $fid ?>" class="btn btn-ghost" type="submit">Save</button>
                        <?php if ($n === 0): ?>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Remove <?= e(addslashes($g['label'])) ?>?');">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                <button class="link-btn" title="Remove">×</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted small section-h-spaced">
        <strong>Name</strong> is the value written to each student's record, so it can't be
        changed once created — edit the <strong>label</strong> instead. A grade with students
        in it can only be deactivated, never deleted, so no record is left pointing at a
        grade that doesn't exist. <strong>Promotes to</strong> is what the June year-end
        rollover applies; leave it blank for grades children stay in.
    </p>
</div>

<div class="card">
    <h3>Add a grade</h3>
    <form method="post" class="row" style="align-items:flex-end;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <div class="field">
            <label for="n">Name <span class="muted small">(stored)</span></label>
            <input id="n" name="name" required maxlength="40" placeholder="e.g. Afterschool">
        </div>
        <div class="field">
            <label for="l">Label <span class="muted small">(optional)</span></label>
            <input id="l" name="label" maxlength="80" placeholder="defaults to the name">
        </div>
        <div class="field">
            <label for="o">Order</label>
            <input id="o" name="sort_order" type="number" value="<?= (int)((count($rows) + 1) * 10) ?>" style="width:6rem;">
        </div>
        <div class="field">
            <label for="t">Promotes to</label>
            <select id="t" name="promotes_to">
                <option value="">— stays put —</option>
                <?php foreach ($allNames as $nm): ?>
                    <option value="<?= e($nm) ?>"><?= e(grade_label($nm)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions"><button class="btn btn-primary" type="submit">Add grade</button></div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
