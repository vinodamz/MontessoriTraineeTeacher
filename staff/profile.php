<?php
/**
 * staff/profile.php — the staff "joining application": personal details,
 * emergency contact, father/spouse, education and experience.
 *
 * Access mirrors the rest of the module: an admin edits anyone's; a staff
 * member edits only their own (staff_can_view). The Experience Letter is an
 * optional file that lands in staff_documents with kind='experience', reusing
 * the same auth-gated storage as every other staff document.
 *
 *   GET  ?id=N        → render the form (defaults to the signed-in user).
 *   POST (multipart)  → save + optional experience-letter upload → view.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');

$id = (int)($_POST['id'] ?? $_GET['id'] ?? $user['id']);
if (!staff_can_view($user, $id)) {
    http_response_code(403); echo 'Forbidden.'; exit;
}
$staff = staff_member($id);
if (!$staff) { http_response_code(404); echo 'Staff member not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        staff_profile_save($id, $_POST);
    } catch (Throwable $e) {
        flash_set('error', 'Could not save details: ' . $e->getMessage());
        redirect('/staff/profile.php?id=' . $id);
    }

    // Optional experience letter — same store/rules as the documents card. A
    // problem here doesn't undo the saved details; report it as a warning.
    if (!empty($_FILES['experience_letter']['name'] ?? '')
        && (int)($_FILES['experience_letter']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        try {
            staff_save_uploaded_document($id, $_FILES['experience_letter'], (int)$user['id'], 'experience');
        } catch (Throwable $e) {
            flash_set('error', 'Details saved, but the experience letter could not be uploaded: ' . $e->getMessage());
            redirect('/staff/view.php?id=' . $id . '#personal');
        }
    }

    flash_set('ok', 'Details saved.');
    redirect('/staff/view.php?id=' . $id . '#personal');
}

$p = staff_profile($id);

$pageTitle = 'Details — ' . $staff['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Details</h1>
        <p class="muted">
            <a href="/staff/view.php?id=<?= $id ?>">← <?= e($staff['name']) ?></a>
            · <?= e(role_label((string)$staff['role'])) ?>
        </p>
    </div>
</div>

<form method="post" action="/staff/profile.php" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="card">
        <h3>Profile</h3>
        <div class="row">
            <div class="field">
                <label for="dob">Date of birth</label>
                <input type="date" id="dob" name="date_of_birth" value="<?= e((string)$p['date_of_birth']) ?>" max="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="field">
                <label for="gender">Gender</label>
                <select id="gender" name="gender">
                    <option value="">—</option>
                    <?php foreach (staff_genders() as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $p['gender'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="blood">Blood group</label>
                <select id="blood" name="blood_group">
                    <option value="">—</option>
                    <?php foreach (staff_blood_groups() as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $p['blood_group'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Address &amp; emergency</h3>
        <div class="field">
            <label for="addr">Home address</label>
            <textarea id="addr" name="home_address" rows="2"><?= e((string)$p['home_address']) ?></textarea>
        </div>
        <div class="row">
            <div class="field">
                <label for="ecn">Emergency contact (name)</label>
                <input type="text" id="ecn" name="emergency_contact_name" maxlength="120" value="<?= e((string)$p['emergency_contact_name']) ?>">
            </div>
            <div class="field">
                <label for="ep">Emergency phone</label>
                <input type="tel" id="ep" name="emergency_phone" maxlength="30" value="<?= e((string)$p['emergency_phone']) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Father / Spouse details</h3>
        <div class="row">
            <div class="field">
                <label for="rr">Relation</label>
                <select id="rr" name="relative_relation">
                    <option value="">—</option>
                    <?php foreach (staff_relations() as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $p['relative_relation'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="rn">Name</label>
                <input type="text" id="rn" name="relative_name" maxlength="120" value="<?= e((string)$p['relative_name']) ?>">
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label for="re">Email id</label>
                <input type="email" id="re" name="relative_email" maxlength="190" value="<?= e((string)$p['relative_email']) ?>">
            </div>
            <div class="field">
                <label for="rp">Phone no.</label>
                <input type="tel" id="rp" name="relative_phone" maxlength="30" value="<?= e((string)$p['relative_phone']) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Education</h3>
        <div class="field">
            <label for="hq">Highest qualification</label>
            <input type="text" id="hq" name="highest_qualification" maxlength="150" value="<?= e((string)$p['highest_qualification']) ?>"
                   placeholder="e.g. B.Ed, M.A. English, Montessori Diploma">
        </div>
    </div>

    <div class="card">
        <h3>Experience</h3>
        <p class="muted small">Enter <strong>NA</strong> if not applicable.</p>
        <div class="field">
            <label for="pe">Previous employer details</label>
            <textarea id="pe" name="previous_employer" rows="3"
                      placeholder="Employer, role, dates — or NA"><?= e((string)$p['previous_employer']) ?></textarea>
        </div>
        <div class="field">
            <label for="expletter">Experience letter</label>
            <input type="file" id="expletter" name="experience_letter"
                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/*">
            <p class="muted small">PDF, DOC/DOCX, JPG or PNG · 8&nbsp;MB max. Existing letters are listed under Documents on the profile.</p>
        </div>
    </div>

    <div class="actions">
        <button class="btn btn-primary" type="submit">Save details</button>
        <a class="btn btn-ghost" href="/staff/view.php?id=<?= $id ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
