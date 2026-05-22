<?php
/**
 * crm/edit.php — create or edit an inquiry family (with children + parents).
 *
 * GET  /crm/edit.php           → blank form
 * GET  /crm/edit.php?id=N      → edit existing family
 * POST /crm/edit.php           → upsert + redirect to view.php
 *
 * Children and parents are managed inline as repeating row groups. Server-side
 * we wipe-and-reinsert both sets on save — small data, simple semantics, no
 * stale row drift.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);

    $primaryName = trim($_POST['primary_name'] ?? '');
    if ($primaryName === '') {
        flash_set('error', 'Primary contact name is required.');
        redirect('/crm/edit.php' . ($id ? "?id=$id" : ''));
    }

    $status = $_POST['status'] ?? 'new';
    if (!array_key_exists($status, crm_statuses())) $status = 'new';

    $probability = max(0, min(100, (int)($_POST['probability'] ?? crm_default_probability($status))));
    $fee         = $_POST['expected_fee'] !== '' ? (float)$_POST['expected_fee'] : null;
    $start       = $_POST['expected_start'] !== '' ? $_POST['expected_start'] : null;
    $priority    = $_POST['priority'] ?? 'normal';
    if (!array_key_exists($priority, crm_priorities())) $priority = 'normal';
    $campaignId  = (int)($_POST['campaign_id'] ?? 0) ?: null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $pdo->prepare("
                UPDATE inquiry_families
                SET primary_name=:n, primary_phone=:p, primary_email=:e, source=:s,
                    status=:st, probability=:pr, priority=:prio, campaign_id=:cid,
                    expected_fee=:f, expected_start=:start,
                    notes=:notes, owner_id=:o
                WHERE id=:id
            ")->execute([
                ':n' => $primaryName,
                ':p' => trim($_POST['primary_phone'] ?? '') ?: null,
                ':e' => trim($_POST['primary_email'] ?? '') ?: null,
                ':s' => trim($_POST['source'] ?? '') ?: null,
                ':st'=> $status, ':pr' => $probability,
                ':prio' => $priority, ':cid' => $campaignId,
                ':f' => $fee, ':start' => $start,
                ':notes' => trim($_POST['notes'] ?? '') ?: null,
                ':o' => (int)($_POST['owner_id'] ?? 0) ?: null,
                ':id'=> $id,
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO inquiry_families
                    (primary_name, primary_phone, primary_email, source,
                     status, probability, priority, campaign_id,
                     expected_fee, expected_start, notes, owner_id)
                VALUES (:n, :p, :e, :s, :st, :pr, :prio, :cid, :f, :start, :notes, :o)
            ")->execute([
                ':n' => $primaryName,
                ':p' => trim($_POST['primary_phone'] ?? '') ?: null,
                ':e' => trim($_POST['primary_email'] ?? '') ?: null,
                ':s' => trim($_POST['source'] ?? '') ?: null,
                ':st'=> $status, ':pr' => $probability,
                ':prio' => $priority, ':cid' => $campaignId,
                ':f' => $fee, ':start' => $start,
                ':notes' => trim($_POST['notes'] ?? '') ?: null,
                ':o' => (int)($_POST['owner_id'] ?? 0) ?: $user['id'],
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        // Replace children. Keep rows with promoted_student_id to preserve
        // the link back from enrolled students; only delete unpromoted ones.
        $pdo->prepare("DELETE FROM inquiry_children
                       WHERE family_id = :f AND promoted_student_id IS NULL")
            ->execute([':f' => $id]);

        $kidNames  = $_POST['kid_name']  ?? [];
        $kidLast   = $_POST['kid_last']  ?? [];
        $kidDobs   = $_POST['kid_dob']   ?? [];
        $kidGender = $_POST['kid_gender']?? [];
        $kidGrade  = $_POST['kid_grade'] ?? [];
        $kidNotes  = $_POST['kid_notes'] ?? [];
        $insKid = $pdo->prepare("
            INSERT INTO inquiry_children
                (family_id, first_name, last_name, dob, gender, target_grade, notes)
            VALUES (:f, :fn, :ln, :dob, :g, :grade, :n)
        ");
        foreach ($kidNames as $i => $fn) {
            $fn = trim((string)$fn);
            if ($fn === '') continue;
            $grade = $kidGrade[$i] ?? '';
            if (!in_array($grade, ['Playgroup','Nursery','LKG','UKG'], true)) $grade = null;
            $gender = $kidGender[$i] ?? '';
            if (!in_array($gender, ['Male','Female','Other'], true)) $gender = null;
            $insKid->execute([
                ':f'    => $id,
                ':fn'   => $fn,
                ':ln'   => trim((string)($kidLast[$i] ?? '')) ?: null,
                ':dob'  => trim((string)($kidDobs[$i] ?? '')) ?: null,
                ':g'    => $gender,
                ':grade'=> $grade,
                ':n'    => trim((string)($kidNotes[$i] ?? '')) ?: null,
            ]);
        }

        // Replace parents wholesale.
        $pdo->prepare("DELETE FROM inquiry_parents WHERE family_id = :f")
            ->execute([':f' => $id]);

        $pNames = $_POST['p_name']     ?? [];
        $pRel   = $_POST['p_relation'] ?? [];
        $pPhone = $_POST['p_phone']    ?? [];
        $pEmail = $_POST['p_email']    ?? [];
        $pOcc   = $_POST['p_occ']      ?? [];
        $pPri   = $_POST['p_primary']  ?? '';
        $insP = $pdo->prepare("
            INSERT INTO inquiry_parents
                (family_id, relation, name, phone, email, occupation, is_primary)
            VALUES (:f, :rel, :n, :ph, :em, :oc, :pri)
        ");
        foreach ($pNames as $i => $n) {
            $n = trim((string)$n);
            if ($n === '') continue;
            $rel = $pRel[$i] ?? 'guardian';
            if (!in_array($rel, ['father','mother','guardian','other'], true)) $rel = 'guardian';
            $insP->execute([
                ':f'   => $id,
                ':rel' => $rel,
                ':n'   => $n,
                ':ph'  => trim((string)($pPhone[$i] ?? '')) ?: null,
                ':em'  => trim((string)($pEmail[$i] ?? '')) ?: null,
                ':oc'  => trim((string)($pOcc[$i]   ?? '')) ?: null,
                ':pri' => ((string)$pPri === (string)$i) ? 1 : 0,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Save failed: ' . $e->getMessage());
        redirect('/crm/edit.php' . ($id ? "?id=$id" : ''));
    }

    flash_set('ok', 'Inquiry saved.');
    redirect('/crm/view.php?id=' . $id);
}

// ---- GET: load existing or blank form ------------------------------------
$family = null; $children = []; $parents = [];
if ($id > 0) {
    $stmt = db()->prepare("SELECT * FROM inquiry_families WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $family = $stmt->fetch();
    if (!$family) { http_response_code(404); echo 'Inquiry not found.'; exit; }

    $stmt = db()->prepare("SELECT * FROM inquiry_children WHERE family_id = :id ORDER BY id");
    $stmt->execute([':id' => $id]);
    $children = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT * FROM inquiry_parents WHERE family_id = :id ORDER BY id");
    $stmt->execute([':id' => $id]);
    $parents = $stmt->fetchAll();
}

// Pad to at least one empty row each so the form is usable for new entries.
while (count($children) < 1) $children[] = ['first_name' => '', 'last_name' => '', 'dob' => '', 'gender' => '', 'target_grade' => '', 'notes' => ''];
while (count($parents)  < 1) $parents[]  = ['name' => '', 'relation' => 'guardian', 'phone' => '', 'email' => '', 'occupation' => '', 'is_primary' => 1];

$campaigns = crm_active_campaigns();
$owners = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('crm', modules) > 0)
    ORDER BY name
")->fetchAll();

$pageTitle = $id ? 'Edit inquiry' : 'New inquiry';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $id ? 'Edit inquiry' : 'New inquiry' ?></h1>
        <p class="muted">
            <a href="/crm/index.php">← Back to pipeline</a>
        </p>
    </div>
</div>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id"    value="<?= (int)$id ?>">

    <h3>Primary contact</h3>
    <div class="row">
        <div class="field" style="flex: 2 1 280px;">
            <label>Name *</label>
            <input name="primary_name" required maxlength="160"
                   value="<?= e($family['primary_name'] ?? '') ?>"
                   placeholder="e.g. Anita Sharma">
        </div>
        <div class="field">
            <label>Phone</label>
            <input name="primary_phone" maxlength="40"
                   value="<?= e($family['primary_phone'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Email</label>
            <input name="primary_email" type="email" maxlength="160"
                   value="<?= e($family['primary_email'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Campaign</label>
            <select name="campaign_id">
                <option value="0">— None —</option>
                <?php foreach ($campaigns as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= (int)($family['campaign_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Source <span class="muted small">(free text)</span></label>
            <input name="source" list="crm-sources" maxlength="60"
                   value="<?= e($family['source'] ?? '') ?>">
            <datalist id="crm-sources">
                <?php foreach (crm_source_options() as $s): ?>
                    <option value="<?= e($s) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
    </div>

    <h3 class="section-h-spaced">Pipeline</h3>
    <div class="row">
        <div class="field">
            <label>Status</label>
            <select name="status">
                <?php foreach (crm_statuses() as $code => $meta): ?>
                    <option value="<?= e($code) ?>"
                        <?= ($family['status'] ?? 'new') === $code ? 'selected' : '' ?>>
                        <?= e($meta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Win probability %</label>
            <input name="probability" type="number" min="0" max="100" step="5"
                   value="<?= (int)($family['probability'] ?? 20) ?>">
        </div>
        <div class="field">
            <label>Priority</label>
            <select name="priority">
                <?php foreach (crm_priorities() as $code => $meta): ?>
                    <option value="<?= e($code) ?>" <?= ($family['priority'] ?? 'normal') === $code ? 'selected' : '' ?>>
                        <?= e($meta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Expected monthly fee (₹)</label>
            <input name="expected_fee" type="number" min="0" step="100"
                   value="<?= e((string)($family['expected_fee'] ?? '')) ?>">
        </div>
        <div class="field">
            <label>Expected start date</label>
            <input name="expected_start" type="date"
                   value="<?= e((string)($family['expected_start'] ?? '')) ?>">
        </div>
        <div class="field">
            <label>Owner</label>
            <select name="owner_id">
                <option value="0">— Unassigned —</option>
                <?php foreach ($owners as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"
                        <?= (int)($family['owner_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>>
                        <?= e($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 class="section-h-spaced">Children</h3>
    <p class="muted small">One row per child. Empty rows are dropped on save.</p>
    <div class="crm-rows" id="kid-rows">
        <?php foreach ($children as $i => $k): ?>
            <div class="crm-row">
                <div class="field"><label>First name</label>
                    <input name="kid_name[]" value="<?= e($k['first_name']) ?>" maxlength="120"></div>
                <div class="field"><label>Last name</label>
                    <input name="kid_last[]" value="<?= e($k['last_name']) ?>" maxlength="120"></div>
                <div class="field"><label>DOB</label>
                    <input name="kid_dob[]" type="date" value="<?= e((string)$k['dob']) ?>"></div>
                <div class="field"><label>Gender</label>
                    <select name="kid_gender[]">
                        <option value="">—</option>
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($k['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Target grade</label>
                    <select name="kid_grade[]">
                        <option value="">—</option>
                        <?php foreach (['Playgroup','Nursery','LKG','UKG'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($k['target_grade'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="flex: 2 1 220px;"><label>Notes</label>
                    <input name="kid_notes[]" value="<?= e((string)($k['notes'] ?? '')) ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-ghost" onclick="cloneRow('kid-rows')">+ Add child</button>
    </div>

    <h3 class="section-h-spaced">Parents / guardians</h3>
    <div class="crm-rows" id="p-rows">
        <?php foreach ($parents as $i => $p): ?>
            <div class="crm-row">
                <div class="field"><label>Name</label>
                    <input name="p_name[]" value="<?= e($p['name']) ?>" maxlength="160"></div>
                <div class="field"><label>Relation</label>
                    <select name="p_relation[]">
                        <?php foreach (['father','mother','guardian','other'] as $r): ?>
                            <option value="<?= $r ?>" <?= ($p['relation'] ?? '') === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Phone</label>
                    <input name="p_phone[]" value="<?= e((string)$p['phone']) ?>" maxlength="40"></div>
                <div class="field"><label>Email</label>
                    <input name="p_email[]" type="email" value="<?= e((string)$p['email']) ?>" maxlength="160"></div>
                <div class="field"><label>Occupation</label>
                    <input name="p_occ[]" value="<?= e((string)($p['occupation'] ?? '')) ?>" maxlength="120"></div>
                <div class="field" style="flex: 0 0 110px;"><label>Primary?</label>
                    <label class="checkbox">
                        <input type="radio" name="p_primary" value="<?= $i ?>"
                            <?= !empty($p['is_primary']) ? 'checked' : '' ?>>
                        <span>Yes</span>
                    </label>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-ghost" onclick="cloneRow('p-rows')">+ Add parent</button>
    </div>

    <h3 class="section-h-spaced">Notes</h3>
    <div class="field">
        <textarea name="notes" rows="3" placeholder="Anything else worth remembering about this family…"><?= e((string)($family['notes'] ?? '')) ?></textarea>
    </div>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit"><?= $id ? 'Save changes' : 'Create inquiry' ?></button>
        <a class="btn btn-ghost" href="<?= $id ? '/crm/view.php?id=' . $id : '/crm/index.php' ?>">Cancel</a>
    </div>
</form>

<script>
function cloneRow(containerId) {
    const c = document.getElementById(containerId);
    const last = c.lastElementChild;
    const copy = last.cloneNode(true);
    copy.querySelectorAll('input, select, textarea').forEach(el => {
        if (el.type === 'radio') { el.checked = false; return; }
        if (el.tagName === 'SELECT') { el.selectedIndex = 0; return; }
        el.value = '';
    });
    c.appendChild(copy);
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
