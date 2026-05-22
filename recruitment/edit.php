<?php
/**
 * recruitment/edit.php — create or edit a candidate.
 *
 *   GET  /recruitment/edit.php           → blank form
 *   GET  /recruitment/edit.php?id=N      → edit existing candidate
 *   POST /recruitment/edit.php           → upsert + redirect to view.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recruitment.php';

$user = require_module('recruitment');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);

    $firstName = trim($_POST['first_name'] ?? '');
    if ($firstName === '') {
        flash_set('error', 'First name is required.');
        redirect('/recruitment/edit.php' . ($id ? "?id=$id" : ''));
    }

    $position = $_POST['position_applied'] ?? 'assistant_teacher';
    if (!array_key_exists($position, recruit_positions())) $position = 'assistant_teacher';

    $status = $_POST['status'] ?? 'resume_received';
    if (!array_key_exists($status, recruit_statuses())) $status = 'resume_received';
    // Hire transition has its own endpoint (api.php op=hire) — disallow
    // setting status=hired directly from the edit form.
    if ($status === 'hired') $status = 'offered';

    $priority = $_POST['priority'] ?? 'normal';
    if (!array_key_exists($priority, recruit_priorities())) $priority = 'normal';

    $params = [
        ':fn'     => $firstName,
        ':ln'     => trim($_POST['last_name'] ?? '') ?: null,
        ':ph'     => trim($_POST['phone'] ?? '')     ?: null,
        ':em'     => trim($_POST['email'] ?? '')     ?: null,
        ':pos'    => $position,
        ':src'    => trim($_POST['source'] ?? '')    ?: null,
        ':yrs'    => ($_POST['years_experience'] ?? '') !== '' ? (int)$_POST['years_experience'] : null,
        ':cert'   => trim($_POST['certifications'] ?? '') ?: null,
        ':st'     => $status,
        ':prio'   => $priority,
        ':sal'    => ($_POST['expected_salary'] ?? '') !== '' ? (float)$_POST['expected_salary'] : null,
        ':avail'  => ($_POST['available_from'] ?? '') !== '' ? $_POST['available_from'] : null,
        ':notes'  => trim($_POST['notes'] ?? '') ?: null,
        ':owner'  => (int)($_POST['owner_id'] ?? 0) ?: ($id ? null : (int)$user['id']),
    ];

    try {
        if ($id > 0) {
            $params[':id'] = $id;
            db()->prepare("
                UPDATE recruit_candidates SET
                    first_name=:fn, last_name=:ln, phone=:ph, email=:em,
                    position_applied=:pos, source=:src, years_experience=:yrs,
                    certifications=:cert, status=:st, priority=:prio,
                    expected_salary=:sal, available_from=:avail,
                    notes=:notes, owner_id=:owner
                WHERE id=:id
            ")->execute($params);
        } else {
            db()->prepare("
                INSERT INTO recruit_candidates
                    (first_name, last_name, phone, email, position_applied,
                     source, years_experience, certifications, status, priority,
                     expected_salary, available_from, notes, owner_id)
                VALUES (:fn, :ln, :ph, :em, :pos, :src, :yrs, :cert, :st, :prio,
                        :sal, :avail, :notes, :owner)
            ")->execute($params);
            $id = (int)db()->lastInsertId();
        }
    } catch (Throwable $e) {
        flash_set('error', 'Save failed: ' . $e->getMessage());
        redirect('/recruitment/edit.php' . ($id ? "?id=$id" : ''));
    }

    // Optional resume upload bundled with the form. If it fails, keep the
    // candidate (already saved) but surface the error so the user can retry
    // from the view page.
    if (!empty($_FILES['resume']['name']) && ($_FILES['resume']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            recruit_save_uploaded_attachment($id, $_FILES['resume'], (int)$user['id'], 'resume');
            flash_set('ok', 'Candidate saved with resume attached.');
        } catch (Throwable $e) {
            flash_set('error', 'Candidate saved, but resume upload failed: ' . $e->getMessage());
            redirect('/recruitment/view.php?id=' . $id . '#attachments');
        }
    } else {
        flash_set('ok', 'Candidate saved.');
    }
    redirect('/recruitment/view.php?id=' . $id);
}

// ---- GET ------------------------------------------------------------------
$cand = null;
if ($id > 0) {
    $stmt = db()->prepare("SELECT * FROM recruit_candidates WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $cand = $stmt->fetch();
    if (!$cand) { http_response_code(404); echo 'Candidate not found.'; exit; }
}

$owners = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('recruitment', modules) > 0)
    ORDER BY name
")->fetchAll();

$pageTitle = $id ? 'Edit candidate' : 'New candidate';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $id ? 'Edit candidate' : 'New candidate' ?></h1>
        <p class="muted"><a href="/recruitment/index.php">← Back to pipeline</a></p>
    </div>
</div>

<form method="post" class="card" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id"    value="<?= (int)$id ?>">

    <h3>Contact</h3>
    <div class="row">
        <div class="field">
            <label>First name *</label>
            <input name="first_name" required maxlength="120"
                   value="<?= e($cand['first_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Last name</label>
            <input name="last_name" maxlength="120"
                   value="<?= e($cand['last_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Phone</label>
            <input name="phone" maxlength="40"
                   value="<?= e($cand['phone'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Email</label>
            <input name="email" type="email" maxlength="160"
                   value="<?= e($cand['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Source</label>
            <input name="source" maxlength="60"
                   placeholder="Referral / Indeed / Walk-in"
                   value="<?= e($cand['source'] ?? '') ?>">
        </div>
    </div>

    <h3 class="section-h-spaced">Role &amp; experience</h3>
    <div class="row">
        <div class="field">
            <label>Position</label>
            <select name="position_applied">
                <?php foreach (recruit_positions() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= ($cand['position_applied'] ?? '') === $code ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Years of experience</label>
            <input name="years_experience" type="number" min="0" max="60" step="1"
                   value="<?= e((string)($cand['years_experience'] ?? '')) ?>">
        </div>
        <div class="field" style="flex: 2 1 280px;">
            <label>Certifications</label>
            <input name="certifications" maxlength="500"
                   placeholder="Montessori diploma, ECCE, NTT…"
                   value="<?= e($cand['certifications'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Expected salary (₹)</label>
            <input name="expected_salary" type="number" min="0" step="500"
                   value="<?= e((string)($cand['expected_salary'] ?? '')) ?>">
        </div>
        <div class="field">
            <label>Available from</label>
            <input name="available_from" type="date"
                   value="<?= e((string)($cand['available_from'] ?? '')) ?>">
        </div>
    </div>

    <h3 class="section-h-spaced">Pipeline</h3>
    <div class="row">
        <div class="field">
            <label>Status</label>
            <select name="status">
                <?php foreach (recruit_statuses() as $code => $meta): ?>
                    <?php if ($code === 'hired') continue; // hire via api.php only ?>
                    <option value="<?= e($code) ?>"
                        <?= ($cand['status'] ?? 'resume_received') === $code ? 'selected' : '' ?>>
                        <?= e($meta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Priority</label>
            <select name="priority">
                <?php foreach (recruit_priorities() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= ($cand['priority'] ?? 'normal') === $code ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Owner (recruiter)</label>
            <select name="owner_id">
                <option value="0">— Unassigned —</option>
                <?php foreach ($owners as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"
                        <?= (int)($cand['owner_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>>
                        <?= e($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 class="section-h-spaced">Resume <span class="muted small">(optional)</span></h3>
    <div class="field">
        <label>Attach resume / CV</label>
        <input type="file" name="resume"
               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/*">
        <p class="muted small">PDF / DOC / DOCX / JPG / PNG, up to 8 MB.
            <?= $id ? 'Adds a new attachment — existing files are kept.' : '' ?></p>
    </div>

    <h3 class="section-h-spaced">Notes</h3>
    <div class="field">
        <textarea name="notes" rows="3"
                  placeholder="Anything else worth remembering about this candidate…"><?= e((string)($cand['notes'] ?? '')) ?></textarea>
    </div>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit"><?= $id ? 'Save changes' : 'Create candidate' ?></button>
        <a class="btn btn-ghost" href="<?= $id ? '/recruitment/view.php?id=' . $id : '/recruitment/index.php' ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
