<?php
/**
 * crm/stages.php — admin: manage admissions pipeline stages.
 *
 * Each row is one column on the kanban board. Stages are ordered by
 * display_order; "open" means the stage counts as still-in-funnel
 * (excludes Enrolled / Lost). "Active" hides a stage from the board
 * and pickers without losing historical attribution.
 *
 * The `code` is the key used everywhere — by the importer's stage map,
 * by inquiry_families.status, by pill CSS class names — so it's
 * read-only once a stage exists. Delete is allowed only when no
 * families currently sit on the stage.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';
    $pdo = db();

    if ($op === 'create') {
        $code  = strtolower(trim((string)($_POST['code'] ?? '')));
        $label = trim((string)($_POST['label'] ?? ''));
        $prob  = max(0, min(100, (int)($_POST['probability'] ?? 0)));
        $open  = !empty($_POST['is_open']) ? 1 : 0;

        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
            flash_set('error', 'Stage code must start with a letter and use only lowercase letters, digits, and underscores (max 40 chars).');
            redirect('/crm/stages.php');
        }
        if ($label === '') {
            flash_set('error', 'Stage label is required.');
            redirect('/crm/stages.php');
        }
        $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0) + 10 FROM crm_stages")->fetchColumn();
        try {
            $pdo->prepare("
                INSERT INTO crm_stages (code, label, display_order, probability, is_open, is_active)
                VALUES (:c, :l, :d, :p, :o, 1)
            ")->execute([':c' => $code, ':l' => $label, ':d' => $nextOrder, ':p' => $prob, ':o' => $open]);
            flash_set('ok', "Stage \"$label\" added.");
        } catch (PDOException $e) {
            flash_set('error', 'A stage with that code already exists.');
        }
        redirect('/crm/stages.php');
    }

    if ($op === 'update') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        $prob  = max(0, min(100, (int)($_POST['probability'] ?? 0)));
        $open  = !empty($_POST['is_open'])   ? 1 : 0;
        $act   = !empty($_POST['is_active']) ? 1 : 0;
        // WhatsApp messaging config (migrate_027). Stored NULL when blank so an
        // unconfigured stage cleanly reports "nothing to send".
        $waText = trim((string)($_POST['wa_text'] ?? ''));
        $waTpl  = trim((string)($_POST['wa_template'] ?? ''));
        $waLang = trim((string)($_POST['wa_template_lang'] ?? ''));
        $intro  = trim((string)($_POST['intro_text'] ?? ''));
        if ($label === '' || $id <= 0) {
            flash_set('error', 'Stage label is required.');
            redirect('/crm/stages.php');
        }
        if (!$act) {
            // Cards in a deactivated stage render in no kanban column and
            // drop out of every follow-up list — it looks like data loss.
            $chk = $pdo->prepare("
                SELECT s.code, COUNT(f.id) AS n
                FROM crm_stages s
                LEFT JOIN inquiry_families f ON f.status = s.code
                WHERE s.id = :id
                GROUP BY s.code
            ");
            $chk->execute([':id' => $id]);
            $row = $chk->fetch();
            if ($row && (int)$row['n'] > 0) {
                flash_set('error', 'Cannot deactivate "' . $label . '" — ' . (int)$row['n'] . ' famil' . ((int)$row['n'] === 1 ? 'y is' : 'ies are') . ' still in this stage. Move them first.');
                redirect('/crm/stages.php');
            }
        }
        $pdo->prepare("
            UPDATE crm_stages
            SET label=:l, probability=:p, is_open=:o, is_active=:a,
                wa_text=:wt, wa_template=:wtpl, wa_template_lang=:wlang,
                intro_text=:intro
            WHERE id=:id
        ")->execute([
            ':l' => $label, ':p' => $prob, ':o' => $open, ':a' => $act,
            ':wt'    => $waText === '' ? null : $waText,
            ':wtpl'  => $waTpl  === '' ? null : $waTpl,
            ':wlang' => $waLang === '' ? 'en_US' : $waLang,
            ':intro' => $intro === '' ? null : $intro,
            ':id' => $id,
        ]);
        flash_set('ok', 'Stage updated.');
        redirect('/crm/stages.php');
    }

    if ($op === 'move') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $row = $pdo->prepare("SELECT id, display_order FROM crm_stages WHERE id=:id");
        $row->execute([':id' => $id]);
        $self = $row->fetch();
        if ($self) {
            $cmp  = $dir === 'up' ? '<' : '>';
            $ord  = $dir === 'up' ? 'DESC' : 'ASC';
            $nbr  = $pdo->prepare("
                SELECT id, display_order FROM crm_stages
                WHERE display_order $cmp :o ORDER BY display_order $ord LIMIT 1
            ");
            $nbr->execute([':o' => (int)$self['display_order']]);
            if ($n = $nbr->fetch()) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE crm_stages SET display_order=:o WHERE id=:id")
                    ->execute([':o' => (int)$n['display_order'], ':id' => (int)$self['id']]);
                $pdo->prepare("UPDATE crm_stages SET display_order=:o WHERE id=:id")
                    ->execute([':o' => (int)$self['display_order'], ':id' => (int)$n['id']]);
                $pdo->commit();
            }
        }
        redirect('/crm/stages.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT s.code, (SELECT COUNT(*) FROM inquiry_families f WHERE f.status = s.code) AS used
            FROM crm_stages s WHERE s.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash_set('error', 'Stage not found.');
        } elseif ((int)$row['used'] > 0) {
            flash_set('error', 'Cannot delete: ' . (int)$row['used'] . ' inquir' . ((int)$row['used'] === 1 ? 'y' : 'ies') . ' still sit on this stage. Move them off, or deactivate the stage instead.');
        } else {
            $pdo->prepare("DELETE FROM crm_stages WHERE id=:id")->execute([':id' => $id]);
            flash_set('ok', "Stage \"{$row['code']}\" deleted.");
        }
        redirect('/crm/stages.php');
    }
}

$rows = db()->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM inquiry_families f WHERE f.status = s.code) AS family_count
    FROM crm_stages s
    ORDER BY s.display_order, s.id
")->fetchAll();

$pageTitle = 'Pipeline stages';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Pipeline stages</h1>
        <p class="muted">Columns on the admissions kanban. Reorder with the arrows; deactivate to hide without losing history.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">← Pipeline</a>
    </div>
</div>

<?php
// Forms are declared outside the table — table cells reference them via
// the HTML5 `form="..."` attribute, otherwise the browser hoists forms
// out of <tr>/<td> and breaks submission.
foreach ($rows as $r):
    $rid = (int)$r['id'];
?>
    <form id="stage-edit-<?= $rid ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="update">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <form id="stage-del-<?= $rid ?>" method="post" style="display:none;"
          onsubmit="return confirm('Delete stage &quot;<?= e($r['code']) ?>&quot;? This is permanent.');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="delete">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php foreach (['up','down'] as $dir): ?>
    <form id="stage-move-<?= $rid ?>-<?= $dir ?>" method="post" style="display:none;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op"    value="move">
        <input type="hidden" name="dir"   value="<?= $dir ?>">
        <input type="hidden" name="id"    value="<?= $rid ?>">
    </form>
    <?php endforeach; ?>
<?php endforeach; ?>

<section class="card">
    <h3>Existing stages</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:1%;">Order</th>
                <th>Code</th>
                <th>Label</th>
                <th>Prob.</th>
                <th>Open?</th>
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
                        <button class="link-btn" type="submit" form="stage-move-<?= $rid ?>-up"   title="Move up">↑</button>
                    <?php endif; ?>
                    <?php if ($i < count($rows) - 1): ?>
                        <button class="link-btn" type="submit" form="stage-move-<?= $rid ?>-down" title="Move down">↓</button>
                    <?php endif; ?>
                </td>
                <td><code><?= e($r['code']) ?></code></td>
                <td><input form="stage-edit-<?= $rid ?>" type="text"     name="label"       value="<?= e($r['label']) ?>" required maxlength="60"></td>
                <td style="width:5rem;"><input form="stage-edit-<?= $rid ?>" type="number" name="probability" value="<?= (int)$r['probability'] ?>" min="0" max="100"></td>
                <td><input form="stage-edit-<?= $rid ?>" type="checkbox" name="is_open"   value="1" <?= $r['is_open']   ? 'checked' : '' ?>></td>
                <td><input form="stage-edit-<?= $rid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></td>
                <td><?= (int)$r['family_count'] ?></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-small"            type="submit" form="stage-edit-<?= $rid ?>">Save</button>
                    <button class="btn btn-small btn-danger" type="submit" form="stage-del-<?= $rid ?>"
                        <?= (int)$r['family_count'] > 0 ? 'disabled title="Has inquiries — deactivate instead"' : '' ?>>Delete</button>
                </td>
            </tr>
            <tr class="stage-wa-row">
                <td></td>
                <td colspan="7">
                    <details<?= (trim((string)($r['wa_text'] ?? '')) !== '' || trim((string)($r['wa_template'] ?? '')) !== '') ? ' open' : '' ?>>
                        <summary class="muted" style="cursor:pointer;">WhatsApp message for this stage</summary>
                        <div style="margin-top:.5rem; display:grid; gap:.4rem;">
                            <label class="muted" style="font-size:.85em;">
                                In-window text <small>(sent when the parent messaged within 24h — supports
                                <code>{parent_name}</code> <code>{child_name}</code> <code>{school_name}</code> <code>{stage}</code>)</small>
                                <textarea form="stage-edit-<?= $rid ?>" name="wa_text" rows="2"
                                    style="width:100%;" placeholder="Hi {parent_name}, …"><?= e((string)($r['wa_text'] ?? '')) ?></textarea>
                            </label>
                            <div style="display:flex; gap:.6rem; flex-wrap:wrap;">
                                <label class="muted" style="font-size:.85em; flex:2 1 12rem;">
                                    Out-of-window template <small>(Meta-approved name)</small>
                                    <input form="stage-edit-<?= $rid ?>" type="text" name="wa_template" style="width:100%;"
                                        value="<?= e((string)($r['wa_template'] ?? '')) ?>" placeholder="e.g. admissions_followup">
                                </label>
                                <label class="muted" style="font-size:.85em; flex:1 1 6rem;">
                                    Template language
                                    <input form="stage-edit-<?= $rid ?>" type="text" name="wa_template_lang" style="width:100%;"
                                        value="<?= e((string)($r['wa_template_lang'] ?? 'en_US')) ?>" placeholder="en_US">
                                </label>
                            </div>
                            <label class="muted" style="font-size:.85em;">
                                Intro message (optional) <small>(sent once before the template the FIRST time this stage messages the family — Meta templates don't say "Little Graduates", this fills the gap. Same <code>{parent_name}</code> / <code>{child_name}</code> / <code>{school_name}</code> / <code>{stage}</code> tokens.)</small>
                                <textarea form="stage-edit-<?= $rid ?>" name="intro_text" rows="2"
                                    style="width:100%;" placeholder="Hi {parent_name}, this is Little Graduates Admissions — you'll see a quick update from us right after this."><?= e((string)($r['intro_text'] ?? '')) ?></textarea>
                            </label>
                        </div>
                    </details>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h3>Add stage</h3>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <label>
            <span>Code <small class="muted">(letters/digits/underscore — used as the DB key; can't change later)</small></span>
            <input type="text" name="code" required pattern="[a-z][a-z0-9_]{1,39}" placeholder="e.g. follow_up">
        </label>
        <label>
            <span>Label</span>
            <input type="text" name="label" required maxlength="60" placeholder="e.g. Follow-up needed">
        </label>
        <label>
            <span>Default probability (0-100)</span>
            <input type="number" name="probability" value="25" min="0" max="100">
        </label>
        <label class="inline-check">
            <input type="checkbox" name="is_open" value="1" checked>
            <span>Counts as open funnel (in revenue projection)</span>
        </label>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Add stage</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
