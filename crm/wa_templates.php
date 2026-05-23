<?php
/**
 * crm/wa_templates.php — admin: manage WhatsApp message templates.
 *
 * The kanban / leads list / detail page's WhatsApp pill shows these to
 * the user. Selecting one substitutes the placeholders below and opens
 * wa.me with the message pre-filled — the admin still tweaks before
 * tapping send, so templates are starting points, not auto-sends.
 *
 * Supported placeholders (case-sensitive):
 *   {parent_name}   {child_name}   {school_name}   {stage}   {date}
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['op'] ?? '';
    $pdo = db();

    if ($op === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $open = !empty($_POST['is_active']) ? 1 : 0;
        if ($name === '' || $body === '') {
            flash_set('error', 'Template name and body are required.');
            redirect('/crm/wa_templates.php');
        }
        $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0) + 10 FROM crm_wa_templates")->fetchColumn();
        $pdo->prepare("
            INSERT INTO crm_wa_templates (name, body, display_order, is_active)
            VALUES (:n, :b, :d, :a)
        ")->execute([':n' => $name, ':b' => $body, ':d' => $nextOrder, ':a' => $open]);
        flash_set('ok', "Template \"$name\" added.");
        redirect('/crm/wa_templates.php');
    }

    if ($op === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $act  = !empty($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || $name === '' || $body === '') {
            flash_set('error', 'Template name and body are required.');
            redirect('/crm/wa_templates.php');
        }
        $pdo->prepare("
            UPDATE crm_wa_templates
            SET name = :n, body = :b, is_active = :a
            WHERE id = :id
        ")->execute([':n' => $name, ':b' => $body, ':a' => $act, ':id' => $id]);
        flash_set('ok', 'Template updated.');
        redirect('/crm/wa_templates.php');
    }

    if ($op === 'move') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $row = $pdo->prepare("SELECT id, display_order FROM crm_wa_templates WHERE id = :id");
        $row->execute([':id' => $id]);
        $self = $row->fetch();
        if ($self) {
            $cmp = $dir === 'up' ? '<' : '>';
            $ord = $dir === 'up' ? 'DESC' : 'ASC';
            $nbr = $pdo->prepare("
                SELECT id, display_order FROM crm_wa_templates
                WHERE display_order $cmp :o ORDER BY display_order $ord LIMIT 1
            ");
            $nbr->execute([':o' => (int)$self['display_order']]);
            if ($n = $nbr->fetch()) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE crm_wa_templates SET display_order = :o WHERE id = :id")
                    ->execute([':o' => (int)$n['display_order'], ':id' => (int)$self['id']]);
                $pdo->prepare("UPDATE crm_wa_templates SET display_order = :o WHERE id = :id")
                    ->execute([':o' => (int)$self['display_order'], ':id' => (int)$n['id']]);
                $pdo->commit();
            }
        }
        redirect('/crm/wa_templates.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_wa_templates WHERE id = :id")->execute([':id' => $id]);
        flash_set('ok', 'Template deleted.');
        redirect('/crm/wa_templates.php');
    }
}

$rows = db()->query("SELECT * FROM crm_wa_templates ORDER BY display_order, id")->fetchAll();

$pageTitle = 'WhatsApp templates';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>WhatsApp templates</h1>
        <p class="muted">
            Pre-written messages for the WhatsApp pill on every inquiry.
            Use <code>{parent_name}</code>, <code>{child_name}</code>,
            <code>{school_name}</code>, <code>{stage}</code> as placeholders —
            they're substituted from each inquiry when the user picks the template.
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">← Pipeline</a>
    </div>
</div>

<?php
// Forms outside the table so HTML5 form="" association works.
foreach ($rows as $r):
    $rid = (int)$r['id'];
?>
    <form id="wat-edit-<?= $rid ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="update">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <form id="wat-del-<?= $rid ?>" method="post" style="display:none;"
          onsubmit="return confirm('Delete template &quot;<?= e($r['name']) ?>&quot;?');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="delete">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php foreach (['up','down'] as $dir): ?>
    <form id="wat-move-<?= $rid ?>-<?= $dir ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="move">
        <input type="hidden" name="dir"   value="<?= $dir ?>">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php endforeach; ?>
<?php endforeach; ?>

<section class="card">
    <h3>Existing templates</h3>
    <?php if (!$rows): ?>
        <p class="muted">No templates yet. Add one below.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:1%;">Order</th>
                    <th style="width:14rem;">Name</th>
                    <th>Body</th>
                    <th style="width:5rem;">Active?</th>
                    <th style="width:1%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r): $rid = (int)$r['id']; ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <?php if ($i > 0): ?>
                            <button class="link-btn" type="submit" form="wat-move-<?= $rid ?>-up"   title="Move up">↑</button>
                        <?php endif; ?>
                        <?php if ($i < count($rows) - 1): ?>
                            <button class="link-btn" type="submit" form="wat-move-<?= $rid ?>-down" title="Move down">↓</button>
                        <?php endif; ?>
                    </td>
                    <td><input form="wat-edit-<?= $rid ?>" type="text" name="name" value="<?= e($r['name']) ?>" required maxlength="80"></td>
                    <td><textarea form="wat-edit-<?= $rid ?>" name="body" rows="3" required style="width:100%; font-family: inherit;"><?= e($r['body']) ?></textarea></td>
                    <td><input form="wat-edit-<?= $rid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-small"            type="submit" form="wat-edit-<?= $rid ?>">Save</button>
                        <button class="btn btn-small btn-danger" type="submit" form="wat-del-<?= $rid ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Add template</h3>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <label>
            <span>Name</span>
            <input type="text" name="name" required maxlength="80" placeholder="e.g. Visit invite">
        </label>
        <label style="grid-column: 1 / -1;">
            <span>Message body</span>
            <textarea name="body" rows="4" required placeholder="Hi {parent_name}, we'd love to have you and {child_name} visit our school…"></textarea>
        </label>
        <label class="inline-check">
            <input type="checkbox" name="is_active" value="1" checked>
            <span>Active (shown in the picker)</span>
        </label>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Add template</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
