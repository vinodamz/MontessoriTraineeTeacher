<?php
/**
 * crm/probability_rules.php — admin: manage probability rules.
 *
 * Each rule maps a set of required tags to a target probability. When
 * tags change on an inquiry the rule engine (crm_evaluate_probability_rules)
 * walks the rules in display_order; the first rule whose required tags are
 * ALL present on the inquiry fires and sets the inquiry's probability to
 * the rule's target_probability.
 *
 * required_tag_ids is stored as a comma-separated string of tag IDs.
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
        $name  = trim((string)($_POST['name'] ?? ''));
        $prob  = max(0, min(100, (int)($_POST['target_probability'] ?? 50)));
        $act   = !empty($_POST['is_active']) ? 1 : 0;
        $tagIds = array_filter(array_map('intval', (array)($_POST['tag_ids'] ?? [])));

        if ($name === '') {
            flash_set('error', 'Rule name is required.');
            redirect('/crm/probability_rules.php');
        }
        if (empty($tagIds)) {
            flash_set('error', 'At least one required tag must be selected.');
            redirect('/crm/probability_rules.php');
        }

        $tagStr    = implode(',', $tagIds);
        $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0) + 10 FROM crm_probability_rules")->fetchColumn();

        $pdo->prepare("
            INSERT INTO crm_probability_rules (name, required_tag_ids, target_probability, display_order, is_active)
            VALUES (:n, :t, :p, :d, :a)
        ")->execute([':n' => $name, ':t' => $tagStr, ':p' => $prob, ':d' => $nextOrder, ':a' => $act]);
        flash_set('ok', "Rule \"$name\" added.");
        redirect('/crm/probability_rules.php');
    }

    if ($op === 'update') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $prob  = max(0, min(100, (int)($_POST['target_probability'] ?? 50)));
        $act   = !empty($_POST['is_active']) ? 1 : 0;
        $tagIds = array_filter(array_map('intval', (array)($_POST['tag_ids'] ?? [])));

        if ($name === '' || $id <= 0) {
            flash_set('error', 'Rule name is required.');
            redirect('/crm/probability_rules.php');
        }
        if (empty($tagIds)) {
            flash_set('error', 'At least one required tag must be selected.');
            redirect('/crm/probability_rules.php');
        }

        $tagStr = implode(',', $tagIds);
        $pdo->prepare("
            UPDATE crm_probability_rules
            SET name=:n, required_tag_ids=:t, target_probability=:p, is_active=:a
            WHERE id=:id
        ")->execute([':n' => $name, ':t' => $tagStr, ':p' => $prob, ':a' => $act, ':id' => $id]);
        flash_set('ok', 'Rule updated.');
        redirect('/crm/probability_rules.php');
    }

    if ($op === 'move') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $row = $pdo->prepare("SELECT id, display_order FROM crm_probability_rules WHERE id=:id");
        $row->execute([':id' => $id]);
        $self = $row->fetch();
        if ($self) {
            $cmp = $dir === 'up' ? '<' : '>';
            $ord = $dir === 'up' ? 'DESC' : 'ASC';
            $nbr = $pdo->prepare("
                SELECT id, display_order FROM crm_probability_rules
                WHERE display_order $cmp :o ORDER BY display_order $ord LIMIT 1
            ");
            $nbr->execute([':o' => (int)$self['display_order']]);
            if ($n = $nbr->fetch()) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE crm_probability_rules SET display_order=:o WHERE id=:id")
                    ->execute([':o' => (int)$n['display_order'], ':id' => (int)$self['id']]);
                $pdo->prepare("UPDATE crm_probability_rules SET display_order=:o WHERE id=:id")
                    ->execute([':o' => (int)$self['display_order'], ':id' => (int)$n['id']]);
                $pdo->commit();
            }
        }
        redirect('/crm/probability_rules.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name FROM crm_probability_rules WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash_set('error', 'Rule not found.');
        } else {
            $pdo->prepare("DELETE FROM crm_probability_rules WHERE id=:id")->execute([':id' => $id]);
            flash_set('ok', "Rule \"{$row['name']}\" deleted.");
        }
        redirect('/crm/probability_rules.php');
    }
}

// Load all rules + all active tags for the forms
$rules = db()->query("
    SELECT * FROM crm_probability_rules
    ORDER BY display_order, id
")->fetchAll();

$allTags = crm_tags_active();

// Build a tag lookup by ID for rendering pills
$tagLookup = [];
foreach ($allTags as $t) {
    $tagLookup[(int)$t['id']] = $t;
}

$pageTitle = 'Probability rules';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Probability rules</h1>
        <p class="muted">Auto-set inquiry probability when all required tags are present. Rules are evaluated in order; the first match wins.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">&larr; Pipeline</a>
    </div>
</div>

<?php
// Forms declared outside the table — cells reference via HTML5 form="" attribute
foreach ($rules as $r):
    $rid = (int)$r['id'];
    $ruleTagIds = array_filter(array_map('intval', explode(',', (string)$r['required_tag_ids'])));
    $ruleTagSet = array_flip($ruleTagIds);
?>
    <form id="rule-edit-<?= $rid ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="update">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <form id="rule-del-<?= $rid ?>" method="post" style="display:none;"
          onsubmit="return confirm('Delete rule &quot;<?= e($r['name']) ?>&quot;? This is permanent.');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="delete">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php foreach (['up','down'] as $dir): ?>
    <form id="rule-move-<?= $rid ?>-<?= $dir ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="move">
        <input type="hidden" name="dir"   value="<?= $dir ?>">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php endforeach; ?>
<?php endforeach; ?>

<section class="card">
    <h3>Existing rules</h3>
    <?php if (empty($rules)): ?>
        <p class="muted">No rules yet. Add one below.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:1%;">Order</th>
                <th>Name</th>
                <th>Required tags</th>
                <th>Probability</th>
                <th>Active?</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rules as $i => $r):
                $rid = (int)$r['id'];
                $ruleTagIds = array_filter(array_map('intval', explode(',', (string)$r['required_tag_ids'])));
                $ruleTagSet = array_flip($ruleTagIds);
            ?>
            <tr>
                <td style="white-space:nowrap;">
                    <?php if ($i > 0): ?>
                        <button class="link-btn" type="submit" form="rule-move-<?= $rid ?>-up" title="Move up">&uarr;</button>
                    <?php endif; ?>
                    <?php if ($i < count($rules) - 1): ?>
                        <button class="link-btn" type="submit" form="rule-move-<?= $rid ?>-down" title="Move down">&darr;</button>
                    <?php endif; ?>
                </td>
                <td><input form="rule-edit-<?= $rid ?>" type="text" name="name" value="<?= e($r['name']) ?>" required maxlength="80"></td>
                <td>
                    <?php
                    // Show current tags as colored pills
                    foreach ($ruleTagIds as $tid):
                        if (isset($tagLookup[$tid])):
                            $t = $tagLookup[$tid];
                    ?>
                        <span class="crm-tag-pill" style="background:<?= e($t['color']) ?>;"><?= e($t['name']) ?></span>
                    <?php
                        endif;
                    endforeach;
                    ?>
                    <details style="margin-top:4px;">
                        <summary class="muted" style="cursor:pointer;font-size:.85em;">Edit tags</summary>
                        <div style="margin-top:4px;">
                        <?php foreach ($allTags as $t): ?>
                            <label class="inline-check" style="display:block;">
                                <input form="rule-edit-<?= $rid ?>" type="checkbox" name="tag_ids[]" value="<?= (int)$t['id'] ?>"
                                    <?= isset($ruleTagSet[(int)$t['id']]) ? 'checked' : '' ?>>
                                <span class="crm-tag-pill" style="background:<?= e($t['color']) ?>;"><?= e($t['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </details>
                </td>
                <td style="width:5rem;"><input form="rule-edit-<?= $rid ?>" type="number" name="target_probability" value="<?= (int)$r['target_probability'] ?>" min="0" max="100">%</td>
                <td><input form="rule-edit-<?= $rid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-small" type="submit" form="rule-edit-<?= $rid ?>">Save</button>
                    <button class="btn btn-small btn-danger" type="submit" form="rule-del-<?= $rid ?>">Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Add rule</h3>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <label>
            <span>Name</span>
            <input type="text" name="name" required maxlength="80" placeholder="e.g. Nearby + Fee agreed + Visit confirmed">
        </label>
        <fieldset style="border:1px solid #ddd;padding:8px;border-radius:4px;">
            <legend>Required tags <small class="muted">(all must be present for the rule to fire)</small></legend>
            <?php if (empty($allTags)): ?>
                <p class="muted">No active tags. <a href="/crm/tags.php">Create tags first.</a></p>
            <?php else: ?>
                <?php foreach ($allTags as $t): ?>
                <label class="inline-check" style="display:block;">
                    <input type="checkbox" name="tag_ids[]" value="<?= (int)$t['id'] ?>">
                    <span class="crm-tag-pill" style="background:<?= e($t['color']) ?>;"><?= e($t['name']) ?></span>
                </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </fieldset>
        <label>
            <span>Target probability (0-100)</span>
            <input type="number" name="target_probability" value="50" min="0" max="100">
        </label>
        <label class="inline-check">
            <input type="checkbox" name="is_active" value="1" checked>
            <span>Active</span>
        </label>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Add rule</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
