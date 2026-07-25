<?php
/**
 * staff/apply.php — PUBLIC, NO LOGIN.
 *
 * Token-gated application form for one staff member. An admin generates the
 * link on /staff/view.php ("Application form link" panel) and shares it. The
 * token in the URL is the sole auth.
 *
 *   GET  ?token=…  → render the form pre-filled with current details
 *   POST           → save details + optional photo / ID / certificate /
 *                    experience-letter uploads. Token required in the body.
 *
 * Invalid / revoked tokens land on a generic "link not active" page — no
 * disclosure about whether a token ever existed.
 *
 * Reuses the same storage as the logged-in module: staff_profile_save() and
 * staff_save_uploaded_document(). Uploads are attributed to the staff member
 * themselves (uploaded_by = their own user id).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';       // db + app_config, no require_login here
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/staff_form.php';

$token = (string)($_REQUEST['token'] ?? '');
$ctx   = staff_by_form_token($token);

if (!$ctx) {
    http_response_code(404);
    staff_apply_render_shell('Link not active', function () {
        ?>
        <div class="card" style="margin-top:1.2rem;">
            <h2 style="margin-top:0;">This link isn't active.</h2>
            <p>The form link you opened has been revoked or has never existed. Please
               contact the school to request a fresh link.</p>
        </div>
        <?php
    });
    exit;
}

$userId  = (int)$ctx['user_id'];
$tokenId = (int)$ctx['token_id'];
$flash   = ['ok' => '', 'err' => ''];

/** Reshape a `name="x[]" multiple` file input into a list of single-file arrays. */
function staff_apply_multi(string $field): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) return [];
    $out = [];
    $n = count($_FILES[$field]['name']);
    for ($i = 0; $i < $n; $i++) {
        if ((int)($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name'     => $_FILES[$field]['name'][$i],
            'type'     => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error'    => $_FILES[$field]['error'][$i],
            'size'     => $_FILES[$field]['size'][$i],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// POST: save.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Body exceeded PHP's post_max_size and was dropped — guard against saving
    // a blank form over good data.
    $flash['err'] = 'The files were too large to upload in one go. Please upload fewer / smaller files at a time.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        staff_profile_save($userId, $_POST);
    } catch (Throwable $e) {
        $flash['err'] = 'Could not save your details. Please try again.';
    }

    if ($flash['err'] === '') {
        // Uploads — each attributed to the staff member; one bad file doesn't
        // abort the others. Photo + experience letter are single; ID proofs +
        // certificates accept multiple.
        $errors = [];
        $count  = 0;

        $single = [
            'photo'             => 'photo',
            'experience_letter' => 'experience',
        ];
        foreach ($single as $field => $kind) {
            if (!empty($_FILES[$field]['name'] ?? '')
                && (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                try { staff_save_uploaded_document($userId, $_FILES[$field], $userId, $kind); $count++; }
                catch (Throwable $e) { $errors[] = staff_doc_kinds()[$kind] . ': ' . $e->getMessage(); }
            }
        }

        $multi = [
            'id_proofs'    => 'id_proof',
            'certificates' => 'certification',
        ];
        foreach ($multi as $field => $kind) {
            foreach (staff_apply_multi($field) as $file) {
                try { staff_save_uploaded_document($userId, $file, $userId, $kind); $count++; }
                catch (Throwable $e) { $errors[] = ($file['name'] ?? 'file') . ': ' . $e->getMessage(); }
            }
        }

        staff_bump_form_token_saved($tokenId);
        $flash['ok'] = 'Thank you — your details were saved'
                     . ($count > 0 ? " ($count file" . ($count === 1 ? '' : 's') . ' uploaded)' : '') . '.';
        if ($errors) {
            $flash['err'] = 'Some files were not accepted (PDF / DOC / JPG / PNG, 8 MB max): '
                          . implode('; ', array_slice($errors, 0, 5));
        }
    }
}

// Fresh read after any save.
$p        = staff_profile($userId);
$onFile   = staff_apply_docs_on_file($userId);
$photo    = staff_latest_photo($userId);

// ---------------------------------------------------------------------------
// Render.
// ---------------------------------------------------------------------------
staff_apply_render_shell('Staff application form', function () use ($ctx, $p, $token, $flash, $onFile, $photo) {
    ?>
    <?php if ($flash['ok']): ?><div class="flash-ok"><?= e($flash['ok']) ?></div><?php endif; ?>
    <?php if ($flash['err']): ?><div class="flash-err"><?= e($flash['err']) ?></div><?php endif; ?>

    <div class="card">
        <p style="margin:0;"><strong>Hello <?= e((string)$ctx['staff_name']) ?></strong> — please fill in your details below and upload your documents. You can come back to this link anytime to update or add more.</p>
    </div>

    <form method="post" action="/staff/apply.php" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="card">
            <h2>Profile</h2>
            <div class="row2">
                <div class="field">
                    <label>Date of birth</label>
                    <input type="date" name="date_of_birth" value="<?= e((string)$p['date_of_birth']) ?>" max="<?= e(date('Y-m-d')) ?>">
                </div>
                <div class="field">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">—</option>
                        <?php foreach (staff_genders() as $c => $l): ?>
                            <option value="<?= e($c) ?>" <?= $p['gender'] === $c ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label>Blood group</label>
                    <select name="blood_group">
                        <option value="">—</option>
                        <?php foreach (staff_blood_groups() as $c => $l): ?>
                            <option value="<?= e($c) ?>" <?= $p['blood_group'] === $c ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Passport photo</label>
                    <?php if ($photo): ?>
                        <span class="photo-thumb" style="background-image:url('/staff/download.php?id=<?= (int)$photo['id'] ?>');"></span>
                        <span class="small">On file — upload again to replace.</span><br>
                    <?php endif; ?>
                    <input type="file" name="photo" data-shrink accept=".jpg,.jpeg,.png,image/*">
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Address &amp; emergency</h2>
            <div class="field">
                <label>Home address</label>
                <textarea name="home_address"><?= e((string)$p['home_address']) ?></textarea>
            </div>
            <div class="row2">
                <div class="field">
                    <label>Emergency contact (name)</label>
                    <input type="text" name="emergency_contact_name" maxlength="120" value="<?= e((string)$p['emergency_contact_name']) ?>">
                </div>
                <div class="field">
                    <label>Emergency phone</label>
                    <input type="tel" name="emergency_phone" maxlength="30" value="<?= e((string)$p['emergency_phone']) ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Father / Spouse details</h2>
            <div class="row2">
                <div class="field">
                    <label>Relation</label>
                    <select name="relative_relation">
                        <option value="">—</option>
                        <?php foreach (staff_relations() as $c => $l): ?>
                            <option value="<?= e($c) ?>" <?= $p['relative_relation'] === $c ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Name</label>
                    <input type="text" name="relative_name" maxlength="120" value="<?= e((string)$p['relative_name']) ?>">
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label>Email id</label>
                    <input type="email" name="relative_email" maxlength="190" value="<?= e((string)$p['relative_email']) ?>">
                </div>
                <div class="field">
                    <label>Phone no.</label>
                    <input type="tel" name="relative_phone" maxlength="30" value="<?= e((string)$p['relative_phone']) ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Education</h2>
            <div class="field">
                <label>Highest qualification</label>
                <input type="text" name="highest_qualification" maxlength="150" value="<?= e((string)$p['highest_qualification']) ?>"
                       placeholder="e.g. B.Ed, M.A. English, Montessori Diploma">
            </div>
        </div>

        <div class="card">
            <h2>Experience</h2>
            <p class="small" style="margin-top:0;">Enter <strong>NA</strong> if not applicable.</p>
            <div class="field">
                <label>Previous employer details</label>
                <textarea name="previous_employer" placeholder="Employer, role, dates — or NA"><?= e((string)$p['previous_employer']) ?></textarea>
            </div>
            <div class="field">
                <label>Experience letter <?php if (!empty($onFile['experience'])): ?><span class="pill"><?= (int)$onFile['experience'] ?> on file</span><?php endif; ?></label>
                <input type="file" name="experience_letter" data-shrink accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/*">
            </div>
        </div>

        <div class="card">
            <h2>Documents</h2>
            <p class="small" style="margin-top:0;">PDF, DOC/DOCX, JPG or PNG · 8&nbsp;MB each. You can pick more than one file.</p>
            <div class="field">
                <label>ID proofs <?php if (!empty($onFile['id_proof'])): ?><span class="pill"><?= (int)$onFile['id_proof'] ?> on file</span><?php endif; ?></label>
                <input type="file" name="id_proofs[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/*">
            </div>
            <div class="field">
                <label>Certificates <?php if (!empty($onFile['certification'])): ?><span class="pill"><?= (int)$onFile['certification'] ?> on file</span><?php endif; ?></label>
                <input type="file" name="certificates[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/*">
            </div>
        </div>

        <div class="submit-row">
            <button class="btn btn-primary" type="submit">Save my details</button>
        </div>
    </form>
    <?php
});


/** Count of documents already on file for a user, keyed by kind. */
function staff_apply_docs_on_file(int $userId): array
{
    $out = [];
    try {
        $s = db()->prepare("SELECT kind, COUNT(*) AS n FROM staff_documents WHERE user_id = :u GROUP BY kind");
        $s->execute([':u' => $userId]);
        foreach ($s->fetchAll() as $r) $out[$r['kind']] = (int)$r['n'];
    } catch (Throwable $e) { /* pre-migration */ }
    return $out;
}


/** Self-contained public page chrome (no logged-in header/nav). */
function staff_apply_render_shell(string $title, callable $body): void
{
    $appName = function_exists('app_name') ? app_name() : 'Little Graduates';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e($appName) ?></title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: #fff5fa; color: #2b2b2b; }
  header.pf-top { background: #fff; border-bottom: 3px solid #e91e63; padding: 1rem 1.2rem;
                  display: grid; grid-template-columns: 48px 1fr; gap: .8rem; align-items: center; }
  header.pf-top img.pf-logo { width: 48px; height: auto; }
  header.pf-top h1 { margin: 0; font-size: 1.05rem; color: #ad1457; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; }
  header.pf-top p  { margin: .15rem 0 0; font-size: .8rem; color: #66bb6a; font-weight: 600; }
  main { max-width: 760px; margin: 0 auto; padding: 1.2rem; }
  .card { background: #fff; border: 1px solid #e8d8c1; border-radius: 10px; padding: 1.1rem 1.2rem; margin-bottom: 1rem; }
  .card h2 { margin: 0 0 .8rem; font-size: 1.05rem; color: #ad1457; border-bottom: 1px dashed #f3c1d5; padding-bottom: .4rem; }
  .field { margin-bottom: .9rem; }
  .field label { display: block; font-size: .85rem; color: #5a4a35; margin-bottom: .3rem; font-weight: 600; }
  .field input[type="text"], .field input[type="email"], .field input[type="tel"],
  .field input[type="date"], .field select, .field textarea {
    width: 100%; padding: .55rem .65rem; border: 1px solid #d4c2a8; border-radius: 6px;
    font-size: .95rem; background: #fff; color: #2b2b2b; }
  .field input[type="file"] { font-size: .9rem; }
  .field textarea { min-height: 70px; resize: vertical; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
  @media (max-width: 540px) { .row2 { grid-template-columns: 1fr; } }
  .pill { display: inline-block; padding: .15rem .55rem; border-radius: 999px; background: #eaf7ea; color: #2c7a2c; font-size: .72rem; font-weight: 600; }
  .btn { display: inline-block; padding: .6rem 1.1rem; border: 0; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
  .btn-primary { background: #e91e63; color: #fff; }
  .btn-primary:hover { background: #ad1457; }
  .submit-row { position: sticky; bottom: 0; background: linear-gradient(to top, #fff5fa 70%, transparent);
                padding: 1rem 0 1.5rem; text-align: center; }
  .flash-ok  { background: #d8efd8; color: #2c5f2c; border: 1px solid #9ccf9c; padding: .7rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
  .flash-err { background: #ffe4e1; color: #8b2c2c; border: 1px solid #d4a5a5; padding: .7rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
  .photo-thumb { display: inline-block; width: 56px; height: 56px; border-radius: 6px;
                 background: #f4ece0 center/cover no-repeat; vertical-align: middle; margin-right: .6rem; }
  .small { font-size: .82rem; color: #7a6a55; }
</style>
</head>
<body>
<header class="pf-top">
    <img class="pf-logo" src="/assets/img/logo.png" alt="">
    <div>
        <h1><?= e($appName) ?></h1>
        <p>Staff application form</p>
    </div>
</header>
<main>
<?php $body(); ?>
</main>
<script src="/assets/js/image-shrink.js"></script>
</body>
</html>
    <?php
}
