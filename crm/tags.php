<?php
/**
 * crm/tags.php — admin: manage inquiry tags.
 *
 * Tags are short colored labels that the team attaches to inquiries.
 * They appear as pills on kanban cards, are filterable on the leads list,
 * and feed the probability-rule engine. Managed here: create, rename,
 * recolor, activate/deactivate, reorder, delete (blocked when in use).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

// Tag management is open to every CRM user — adding/renaming a tag is part
// of day-to-day pipeline work. Deletion still cascades to inquiries, so it's
// confirmed inline; admins can still hit /crm/audit.php to see what changed.
require_module('crm');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['op'] ?? '';
    $pdo = db();

    if ($op === 'create') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $color = trim((string)($_POST['color'] ?? '#6b7280'));

        if ($name === '') {
            flash_set('error', 'Tag name is required.');
            redirect('/crm/tags.php');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#6b7280';
        }

        $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0) + 10 FROM crm_tags")->fetchColumn();
        try {
            $pdo->prepare("
                INSERT INTO crm_tags (name, color, display_order, is_active)
                VALUES (:n, :c, :d, 1)
            ")->execute([':n' => $name, ':c' => $color, ':d' => $nextOrder]);
            flash_set('ok', "Tag \"$name\" added.");
        } catch (PDOException $e) {
            flash_set('error', 'A tag with that name already exists.');
        }
        redirect('/crm/tags.php');
    }

    if ($op === 'update') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $color = trim((string)($_POST['color'] ?? '#6b7280'));
        $act   = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $id <= 0) {
            flash_set('error', 'Tag name is required.');
            redirect('/crm/tags.php');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#6b7280';
        }

        try {
            $pdo->prepare("
                UPDATE crm_tags
                SET name=:n, color=:c, is_active=:a
                WHERE id=:id
            ")->execute([':n' => $name, ':c' => $color, ':a' => $act, ':id' => $id]);
            flash_set('ok', 'Tag updated.');
        } catch (PDOException $e) {
            flash_set('error', 'A tag with that name already exists.');
        }
        redirect('/crm/tags.php');
    }

    if ($op === 'move') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $row = $pdo->prepare("SELECT id, display_order FROM crm_tags WHERE id=:id");
        $row->execute([':id' => $id]);
        $self = $row->fetch();
        if ($self) {
            $cmp = $dir === 'up' ? '<' : '>';
            $ord = $dir === 'up' ? 'DESC' : 'ASC';
            $nbr = $pdo->prepare("
                SELECT id, display_order FROM crm_tags
                WHERE display_order $cmp :o ORDER BY display_order $ord LIMIT 1
            ");
            $nbr->execute([':o' => (int)$self['display_order']]);
            if ($n = $nbr->fetch()) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE crm_tags SET display_order=:o WHERE id=:id")
                    ->execute([':o' => (int)$n['display_order'], ':id' => (int)$self['id']]);
                $pdo->prepare("UPDATE crm_tags SET display_order=:o WHERE id=:id")
                    ->execute([':o' => (int)$self['display_order'], ':id' => (int)$n['id']]);
                $pdo->commit();
            }
        }
        redirect('/crm/tags.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT t.name,
                   (SELECT COUNT(*) FROM inquiry_family_tags ft WHERE ft.tag_id = t.id) AS used
            FROM crm_tags t WHERE t.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash_set('error', 'Tag not found.');
        } elseif ((int)$row['used'] > 0) {
            flash_set('error', 'Cannot delete: ' . (int)$row['used'] . ' inquir' . ((int)$row['used'] === 1 ? 'y' : 'ies') . ' currently use this tag. Remove the tag from those inquiries first, or deactivate it instead.');
        } else {
            $pdo->prepare("DELETE FROM crm_tags WHERE id=:id")->execute([':id' => $id]);
            flash_set('ok', "Tag \"{$row['name']}\" deleted.");
        }
        redirect('/crm/tags.php');
    }
}

$rows = db()->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM inquiry_family_tags ft WHERE ft.tag_id = t.id) AS inquiry_count
    FROM crm_tags t
    ORDER BY t.display_order, t.id
")->fetchAll();

$pageTitle = 'Manage tags';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Manage tags</h1>
        <p class="muted">Colored labels attached to inquiries. Reorder with the arrows; deactivate to hide without losing history.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">&larr; Pipeline</a>
    </div>
</div>

<?php
// Forms declared outside the table — cells reference via HTML5 form="" attribute
foreach ($rows as $r):
    $rid = (int)$r['id'];
?>
    <form id="tag-edit-<?= $rid ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="update">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <form id="tag-del-<?= $rid ?>" method="post" style="display:none;"
          onsubmit="return confirm('Delete tag &quot;<?= e($r['name']) ?>&quot;? This is permanent.');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="delete">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php foreach (['up','down'] as $dir): ?>
    <form id="tag-move-<?= $rid ?>-<?= $dir ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="move">
        <input type="hidden" name="dir"   value="<?= $dir ?>">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php endforeach; ?>
<?php endforeach; ?>

<section class="card">
    <h3>Existing tags</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:1%;">Order</th>
                <th>Name</th>
                <th>Color</th>
                <th>Active?</th>
                <th>Inquiries</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $i => $r): $rid = (int)$r['id']; ?>
            <tr>
                <td style="white-space:nowrap;">
                    <?php if ($i > 0): ?>
                        <button class="link-btn" type="submit" form="tag-move-<?= $rid ?>-up" title="Move up">&uarr;</button>
                    <?php endif; ?>
                    <?php if ($i < count($rows) - 1): ?>
                        <button class="link-btn" type="submit" form="tag-move-<?= $rid ?>-down" title="Move down">&darr;</button>
                    <?php endif; ?>
                </td>
                <td><input form="tag-edit-<?= $rid ?>" type="text" name="name" value="<?= e($r['name']) ?>" required maxlength="40"></td>
                <td><input form="tag-edit-<?= $rid ?>" type="color" name="color" value="<?= e($r['color']) ?>"></td>
                <td><input form="tag-edit-<?= $rid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></td>
                <td>Used by <?= (int)$r['inquiry_count'] ?> inquir<?= (int)$r['inquiry_count'] === 1 ? 'y' : 'ies' ?></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-small" type="submit" form="tag-edit-<?= $rid ?>">Save</button>
                    <button class="btn btn-small btn-danger" type="submit" form="tag-del-<?= $rid ?>"
                        <?= (int)$r['inquiry_count'] > 0 ? 'disabled title="Has inquiries — deactivate instead"' : '' ?>>Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h3>Add tag</h3>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <label>
            <span>Name</span>
            <input type="text" name="name" required maxlength="40" placeholder="e.g. Sibling">
        </label>
        <label>
            <span>Color</span>
            <input type="color" name="color" value="#6b7280">
        </label>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Add tag</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
