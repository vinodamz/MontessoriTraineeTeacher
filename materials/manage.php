<?php
/**
 * materials/manage.php — catalogue management for the material audit.
 *
 * Three jobs, all plain POSTs:
 *   - Add a material (to an existing shelf/group or a brand-new one).
 *   - Rename a shelf/group (updates every material in it).
 *   - Per material: rename, move to another group, retire/restore.
 *
 * Retired (is_active=0) materials drop off the audit board and the Kreedo
 * list but keep their history; they can be restored here any time.
 *
 *   POST op=add_material    name + location (existing or new)
 *   POST op=rename_group    old location → new label
 *   POST op=update_material rename / move one material
 *   POST op=toggle_material retire ↔ restore
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user = require_module('materials');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'add_material') {
        $name   = trim((string)($_POST['name'] ?? ''));
        $locSel = trim((string)($_POST['location'] ?? ''));
        $locNew = trim((string)($_POST['location_new'] ?? ''));
        $locFinal = $locNew !== '' ? $locNew : $locSel;
        if ($name === '' || $locFinal === '') {
            flash_set('error', 'Material name and a shelf/group are required.');
            redirect('/materials/manage.php');
        }
        $next = db()->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM mm_materials WHERE location = :l");
        $next->execute([':l' => $locFinal]);
        db()->prepare("INSERT INTO mm_materials (name, location, sort_order) VALUES (:n, :l, :o)")
            ->execute([':n' => mb_substr($name, 0, 200), ':l' => mb_substr($locFinal, 0, 120), ':o' => (int)$next->fetchColumn()]);
        flash_set('ok', "Added \"$name\" to $locFinal.");
        redirect('/materials/manage.php?loc=' . urlencode($locFinal));
    }

    if ($op === 'rename_group') {
        $old = trim((string)($_POST['old_location'] ?? ''));
        $new = trim((string)($_POST['new_location'] ?? ''));
        if ($old === '' || $new === '' || $old === $new) {
            flash_set('error', 'Pick a group and give it a different new name.');
            redirect('/materials/manage.php');
        }
        $st = db()->prepare("UPDATE mm_materials SET location = :new WHERE location = :old");
        $st->execute([':new' => mb_substr($new, 0, 120), ':old' => $old]);
        flash_set('ok', 'Renamed "' . $old . '" → "' . $new . '" (' . $st->rowCount() . ' materials moved with it).');
        redirect('/materials/manage.php?loc=' . urlencode($new));
    }

    if ($op === 'update_material') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $locSel = trim((string)($_POST['location'] ?? ''));
        $locNew = trim((string)($_POST['location_new'] ?? ''));
        $locFinal = $locNew !== '' ? $locNew : $locSel;
        if ($id <= 0 || $name === '' || $locFinal === '') {
            flash_set('error', 'Name and group are required.');
            redirect('/materials/manage.php');
        }
        db()->prepare("UPDATE mm_materials SET name = :n, location = :l WHERE id = :id")
            ->execute([':n' => mb_substr($name, 0, 200), ':l' => mb_substr($locFinal, 0, 120), ':id' => $id]);
        flash_set('ok', 'Material updated.');
        redirect('/materials/manage.php?loc=' . urlencode($locFinal));
    }

    if ($op === 'toggle_material') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE mm_materials SET is_active = 1 - is_active WHERE id = :id")->execute([':id' => $id]);
            $now = db()->query("SELECT is_active FROM mm_materials WHERE id = $id")->fetchColumn();
            flash_set('ok', $now ? 'Material restored — back on the audit board.' : 'Material retired — history kept, hidden from the board and Kreedo list.');
        }
        redirect('/materials/manage.php' . (!empty($_POST['back_loc']) ? '?loc=' . urlencode((string)$_POST['back_loc']) : ''));
    }
}

$locFilter = trim((string)($_GET['loc'] ?? ''));

$locations = db()->query("SELECT location, COUNT(*) AS n, SUM(is_active = 0) AS retired
                          FROM mm_materials GROUP BY location ORDER BY MIN(sort_order)")->fetchAll();
$locNames  = array_column($locations, 'location');

$where  = '1=1';
$params = [];
if ($locFilter !== '') { $where = 'location = :loc'; $params[':loc'] = $locFilter; }
$st = db()->prepare("SELECT * FROM mm_materials WHERE $where ORDER BY location, sort_order, name");
$st->execute($params);
$materials = $st->fetchAll();

$pageTitle = 'Manage materials';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Manage materials</h1>
        <p class="muted">Add new materials, rename shelves/groups, retire what's gone.</p>
    </div>
    <div class="head-actions"><a class="btn btn-ghost" href="index.php">← Audit board</a></div>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1rem; margin-bottom:1rem;">
<section class="card">
    <h2 style="margin-top:0;">Add a material</h2>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="add_material">
        <div class="field">
            <label>Material name</label>
            <input name="name" maxlength="200" required placeholder="e.g. Knobbed Cylinders Block 2">
        </div>
        <div class="field">
            <label>Shelf / group</label>
            <select name="location">
                <?php foreach ($locNames as $l): ?>
                    <option value="<?= e($l) ?>" <?= $l === $locFilter ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>…or a new group <span class="muted small">(leave blank to use the pick above)</span></label>
            <input name="location_new" maxlength="120" placeholder="e.g. Outdoor materials">
        </div>
        <div class="form-actions"><button class="btn btn-primary">Add material</button></div>
    </form>
</section>

<section class="card">
    <h2 style="margin-top:0;">Rename a shelf / group</h2>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="rename_group">
        <div class="field">
            <label>Group</label>
            <select name="old_location">
                <?php foreach ($locations as $l): ?>
                    <option value="<?= e($l['location']) ?>" <?= $l['location'] === $locFilter ? 'selected' : '' ?>>
                        <?= e($l['location']) ?> (<?= (int)$l['n'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>New name</label>
            <input name="new_location" maxlength="120" required placeholder="e.g. Practical Life shelf">
        </div>
        <div class="form-actions"><button class="btn btn-primary">Rename group</button></div>
        <p class="muted small">Every material in the group moves with it — history and this month's marks included.</p>
    </form>
</section>
</div>

<section class="card">
    <div class="card-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem;">
        <h2 style="margin:0;">All materials <span class="muted small">· <?= count($materials) ?></span></h2>
        <form method="get" style="display:flex; gap:.4rem; align-items:center;">
            <select name="loc" onchange="this.form.submit()">
                <option value="">All groups</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= e($l['location']) ?>" <?= $l['location'] === $locFilter ? 'selected' : '' ?>>
                        <?= e($l['location']) ?> (<?= (int)$l['n'] ?><?= (int)$l['retired'] > 0 ? ', ' . (int)$l['retired'] . ' retired' : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn small">Go</button></noscript>
        </form>
    </div>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th style="width:38%">Name</th><th>Group</th><th></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($materials as $m): $fid = 'mf' . (int)$m['id']; ?>
            <tr style="<?= $m['is_active'] ? '' : 'opacity:.55;' ?>">
                <td>
                    <!-- A form can't span <td>s — inputs reference it by id
                         instead (same pattern as admin.php module grants). -->
                    <form method="post" id="<?= $fid ?>">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="update_material">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    </form>
                    <input form="<?= $fid ?>" name="name" value="<?= e($m['name']) ?>" maxlength="200" style="width:100%;">
                </td>
                <td>
                    <select form="<?= $fid ?>" name="location">
                        <?php foreach ($locNames as $l): ?>
                            <option value="<?= e($l) ?>" <?= $l === $m['location'] ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><button form="<?= $fid ?>" class="btn btn-ghost small">Save</button></td>
                <td>
                    <form method="post" class="inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="toggle_material">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="back_loc" value="<?= e($locFilter) ?>">
                        <button class="link-btn <?= $m['is_active'] ? 'danger' : '' ?>"
                            <?= $m['is_active'] ? 'onclick="return confirm(\'Retire this material? History is kept; it disappears from the audit board.\')"' : '' ?>>
                            <?= $m['is_active'] ? 'retire' : 'restore' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
