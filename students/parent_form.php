<?php
/**
 * students/parent_form.php — PUBLIC, NO LOGIN.
 *
 * Token-gated admission form for an individual child. The school admin
 * generates a link via /students/view.php (the "Parent form link" panel)
 * and shares it with the family. The token in the URL is the sole auth.
 *
 * GET ?token=…   → render the form pre-filled with current data
 * POST           → save (text fields + optional photo / document uploads)
 *                  Token still required in the form body for the save.
 *
 * Invalid / revoked tokens land on a generic "link not active" page —
 * no info leakage about whether a token ever existed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_form.php';

// ---------------------------------------------------------------------------
// Token resolution. Both GET and POST require ?token (POST takes it from the
// form body OR the query string).
// ---------------------------------------------------------------------------
$token = (string)($_REQUEST['token'] ?? '');
$ctx   = student_by_form_token($token);

if (!$ctx) {
    // Generic dead-end page. Don't disclose anything.
    http_response_code(404);
    parent_form_render_shell('Link not active', function () {
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

$studentId = (int)$ctx['student_id'];
$tokenId   = (int)$ctx['token_id'];

// Load parents into a relation-keyed map ('father','mother','guardian').
$pstmt = db()->prepare("SELECT * FROM student_parents WHERE student_id = :sid ORDER BY id");
$pstmt->execute([':sid' => $studentId]);
$parents = [];
foreach ($pstmt->fetchAll() as $row) {
    // First occurrence per relation wins (subsequent duplicates are ignored
    // for the form — admin can clean up via the edit page).
    if (!isset($parents[$row['relation']])) {
        $parents[$row['relation']] = $row;
    }
}

$flash = ['ok' => '', 'err' => ''];

// ---------------------------------------------------------------------------
// POST: save.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db();
        $pdo->beginTransaction();

        // Trim every text field once, up-front.
        $t = fn(string $k) => trim((string)($_POST[$k] ?? ''));

        // ----- students row -----
        $sets   = [];
        $params = [':id' => $studentId];

        $textFields = [
            'first_name', 'last_name',
            'gender', 'place_of_birth', 'nationality', 'mother_tongue',
            'blood_group', 'home_address',
            'emergency_contact_name', 'emergency_contact_relation',
            'emergency_contact_phone', 'emergency_contact_address',
            'sibling_details',
        ];
        foreach ($textFields as $f) {
            // Empty string allowed → store NULL so the column doesn't keep
            // stale data when the parent cleared a field.
            $val = $t($f);
            $sets[] = "$f = :$f";
            $params[":$f"] = $val === '' ? null : $val;
        }

        // Gender — validate against the allowed enum.
        if ($params[':gender'] !== null && !in_array($params[':gender'], ['Male','Female','Other'], true)) {
            $params[':gender'] = null;
        }

        // Dob — accept YYYY-MM-DD or DD/MM/YYYY etc; null when blank/invalid.
        $dobRaw = $t('dob');
        $sets[] = 'dob = :dob';
        $params[':dob'] = parent_form_parse_date($dobRaw);

        // Build the UPDATE.
        $sql = "UPDATE students SET " . implode(', ', $sets) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);

        // ----- parents (father / mother / guardian) -----
        foreach (['father', 'mother', 'guardian'] as $rel) {
            $name = trim((string)($_POST["{$rel}_name"] ?? ''));
            if ($name === '') {
                // Parent left this block blank. Don't delete an existing
                // record — the admin manages cleanup. Just skip.
                continue;
            }
            $phone   = trim((string)($_POST["{$rel}_phone"]      ?? ''));
            $email   = trim((string)($_POST["{$rel}_email"]      ?? ''));
            $occ     = trim((string)($_POST["{$rel}_occupation"] ?? ''));
            $work    = trim((string)($_POST["{$rel}_workplace"]  ?? ''));

            $existingId = $parents[$rel]['id'] ?? null;
            if ($existingId) {
                $pdo->prepare("
                    UPDATE student_parents
                    SET    name       = :n,
                           phone      = :p,
                           email      = :e,
                           occupation = :o,
                           workplace  = :w
                    WHERE  id = :id
                ")->execute([
                    ':n' => $name,
                    ':p' => $phone !== '' ? $phone : null,
                    ':e' => $email !== '' ? $email : null,
                    ':o' => $occ   !== '' ? $occ   : null,
                    ':w' => $work  !== '' ? $work  : null,
                    ':id' => (int)$existingId,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO student_parents
                        (student_id, relation, name, phone, email, occupation, workplace, is_primary)
                    VALUES (:sid, :rel, :n, :p, :e, :o, :w, 0)
                ")->execute([
                    ':sid' => $studentId, ':rel' => $rel,
                    ':n' => $name,
                    ':p' => $phone !== '' ? $phone : null,
                    ':e' => $email !== '' ? $email : null,
                    ':o' => $occ   !== '' ? $occ   : null,
                    ':w' => $work  !== '' ? $work  : null,
                ]);
            }
        }

        $pdo->commit();

        // ----- File uploads (outside the transaction; failures don't roll back text edits) -----

        // Child photo → students.photo_path
        if (!empty($_FILES['child_photo']['name'])) {
            try {
                $stored = student_photo_store($_FILES['child_photo'], 'child' . $studentId);
                if ($stored) {
                    // Delete old photo if any.
                    $oldRow = db()->prepare("SELECT photo_path FROM students WHERE id = :id");
                    $oldRow->execute([':id' => $studentId]);
                    $old = (string)($oldRow->fetchColumn() ?: '');
                    db()->prepare("UPDATE students SET photo_path = :p WHERE id = :id")
                        ->execute([':p' => $stored, ':id' => $studentId]);
                    if ($old !== '') student_photo_delete($old);
                }
            } catch (Throwable $e) {
                $flash['err'] .= 'Child photo: ' . $e->getMessage() . ' ';
            }
        }

        // Father / Mother photo → student_parents.photo_path (must have an existing row)
        foreach (['father', 'mother'] as $rel) {
            $field = "{$rel}_photo";
            if (empty($_FILES[$field]['name'])) continue;
            try {
                // Re-fetch the parent row (we may have just created it above).
                $pr = db()->prepare("SELECT id, photo_path FROM student_parents WHERE student_id = :sid AND relation = :rel LIMIT 1");
                $pr->execute([':sid' => $studentId, ':rel' => $rel]);
                $row = $pr->fetch();
                if (!$row) {
                    // No name was provided for this parent → can't attach a photo to nothing.
                    $flash['err'] .= ucfirst($rel) . ' photo: please fill the name first. ';
                    continue;
                }
                $stored = student_photo_store($_FILES[$field], $rel . $studentId);
                if ($stored) {
                    db()->prepare("UPDATE student_parents SET photo_path = :p WHERE id = :id")
                        ->execute([':p' => $stored, ':id' => (int)$row['id']]);
                    if (!empty($row['photo_path'])) student_photo_delete((string)$row['photo_path']);
                }
            } catch (Throwable $e) {
                $flash['err'] .= ucfirst($rel) . ' photo: ' . $e->getMessage() . ' ';
            }
        }

        // Birth certificate → student_documents (category=birth_certificate)
        $docCategories = [
            'birth_certificate' => 'Birth certificate',
            'id_proof'          => 'Aadhar card',
        ];
        foreach ($docCategories as $cat => $defaultTitle) {
            $field = $cat === 'birth_certificate' ? 'birth_certificate' : 'aadhar';
            if (empty($_FILES[$field]['name'])) continue;
            try {
                parent_form_store_document($_FILES[$field], $studentId, $cat, $defaultTitle);
            } catch (Throwable $e) {
                $flash['err'] .= "$defaultTitle: " . $e->getMessage() . ' ';
            }
        }

        bump_form_token_saved($tokenId);
        $flash['ok'] = 'Saved. Thank you!';

        // Reload fresh data for re-render.
        $ctx = student_by_form_token($token) ?: $ctx;
        $pstmt->execute([':sid' => $studentId]);
        $parents = [];
        foreach ($pstmt->fetchAll() as $row) {
            if (!isset($parents[$row['relation']])) $parents[$row['relation']] = $row;
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $flash['err'] = 'Save failed: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Helpers used by the page above.
// ---------------------------------------------------------------------------
function parent_form_parse_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d && $d->format($fmt) === $s) return $d->format('Y-m-d');
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}

function parent_form_store_document(array $file, int $studentId, string $category, string $defaultTitle): void
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0)                       throw new RuntimeException('Empty file.');
    if ($size > STUDENT_DOC_MAX_BYTES)    throw new RuntimeException('File over ' . format_bytes(STUDENT_DOC_MAX_BYTES) . '.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!array_key_exists($mime, STUDENT_DOC_MIME_ALLOW)) {
        throw new RuntimeException('Type "' . $mime . '" not allowed (PDF or image).');
    }
    $ext  = STUDENT_DOC_MIME_ALLOW[$mime];
    $orig = (string)($file['name'] ?? "upload.$ext");
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = student_docs_dir() . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save file.');
    }
    @chmod($dest, 0644);

    // The schema requires uploaded_by_user_id. Use the school user who issued
    // the token so the audit trail points back at them rather than 0/NULL.
    db()->prepare("
        INSERT INTO student_documents
            (student_id, category, title, original_filename, stored_filename,
             mime_type, size_bytes, uploaded_by_user_id)
        VALUES (:sid, :cat, :t, :orig, :stored, :mime, :sz,
                (SELECT created_by_user_id FROM student_form_tokens WHERE student_id = :sid2 ORDER BY created_at DESC LIMIT 1))
    ")->execute([
        ':sid'  => $studentId, ':sid2' => $studentId,
        ':cat'  => $category,
        ':t'    => $defaultTitle,
        ':orig' => $orig, ':stored' => $stored,
        ':mime' => $mime, ':sz' => $size,
    ]);
}

/**
 * Minimal HTML chrome — no school staff nav, no login links. Just enough
 * to look like a school form on a phone.
 */
function parent_form_render_shell(string $title, callable $body): void
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
         background: #fff8f0; color: #2b2b2b; }
  header.pf-top { background: #fff; border-bottom: 1px solid #e8d8c1;
                  padding: 1rem 1.2rem; }
  header.pf-top h1 { margin: 0; font-size: 1.15rem; color: #6b4226; }
  header.pf-top p  { margin: .25rem 0 0; font-size: .85rem; color: #7a6a55; }
  main { max-width: 760px; margin: 0 auto; padding: 1.2rem; }
  .card { background: #fff; border: 1px solid #e8d8c1; border-radius: 10px;
          padding: 1.1rem 1.2rem; margin-bottom: 1rem; }
  .card h2 { margin: 0 0 .8rem; font-size: 1.05rem; color: #6b4226; border-bottom: 1px dashed #e8d8c1; padding-bottom: .4rem; }
  .field { margin-bottom: .9rem; }
  .field label { display: block; font-size: .85rem; color: #5a4a35; margin-bottom: .3rem; font-weight: 600; }
  .field input[type="text"], .field input[type="email"], .field input[type="tel"],
  .field input[type="date"], .field select, .field textarea {
    width: 100%; padding: .55rem .65rem; border: 1px solid #d4c2a8;
    border-radius: 6px; font-size: .95rem; background: #fff; color: #2b2b2b;
  }
  .field input[type="file"] { font-size: .9rem; }
  .field textarea { min-height: 70px; resize: vertical; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
  @media (max-width: 540px) { .row2 { grid-template-columns: 1fr; } }
  .readonly { background: #f4ece0; color: #6b5a45; }
  .pill-row { display: flex; flex-wrap: wrap; gap: .4rem; font-size: .8rem; color: #7a6a55; }
  .pill { display: inline-block; padding: .15rem .55rem; border-radius: 999px; background: #f4ece0; }
  .btn { display: inline-block; padding: .6rem 1.1rem; border: 0; border-radius: 6px;
         font-size: 1rem; font-weight: 600; cursor: pointer; }
  .btn-primary { background: #6b4226; color: #fff; }
  .btn-primary:hover { background: #532f17; }
  .submit-row { position: sticky; bottom: 0; background: linear-gradient(to top, #fff8f0 70%, transparent);
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
    <h1><?= e($appName) ?></h1>
    <p>Admission form</p>
</header>
<main>
<?php $body(); ?>
</main>
</body>
</html>
    <?php
}

// ---------------------------------------------------------------------------
// Render the form.
// ---------------------------------------------------------------------------
$s        = $ctx;          // student row (merged with token row by the lookup)
$full     = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
$father   = $parents['father']   ?? null;
$mother   = $parents['mother']   ?? null;

parent_form_render_shell('Admission form for ' . $full, function () use ($s, $father, $mother, $token, $flash, $full) {
    ?>
    <?php if ($flash['ok']): ?>
        <div class="flash-ok"><?= e($flash['ok']) ?></div>
    <?php endif; ?>
    <?php if ($flash['err']): ?>
        <div class="flash-err"><?= e($flash['err']) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Child</h2>
        <div class="pill-row" style="margin-bottom:.7rem;">
            <span class="pill">Grade · <?= e((string)$s['grade']) ?></span>
            <?php if (!empty($s['section'])): ?><span class="pill">Section · <?= e((string)$s['section']) ?></span><?php endif; ?>
            <?php if (!empty($s['admission_number'])): ?><span class="pill">Admission # <?= e((string)$s['admission_number']) ?></span><?php endif; ?>
        </div>
        <span class="small">Grade and section are set by the school. Please fill in the rest below.</span>
    </div>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="card">
            <h2>Child details</h2>
            <div class="row2">
                <div class="field">
                    <label>First name</label>
                    <input type="text" name="first_name" value="<?= e((string)$s['first_name']) ?>" required>
                </div>
                <div class="field">
                    <label>Last name</label>
                    <input type="text" name="last_name" value="<?= e((string)$s['last_name']) ?>">
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label>Date of birth</label>
                    <input type="date" name="dob" value="<?= e((string)($s['dob'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Gender</label>
                    <select name="gender">
                        <option value=""></option>
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                            <option value="<?= e($g) ?>" <?= ($s['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label>Place of birth</label>
                    <input type="text" name="place_of_birth" value="<?= e((string)($s['place_of_birth'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Nationality</label>
                    <input type="text" name="nationality" value="<?= e((string)($s['nationality'] ?? '')) ?>">
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label>Mother tongue</label>
                    <input type="text" name="mother_tongue" value="<?= e((string)($s['mother_tongue'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Blood group</label>
                    <input type="text" name="blood_group" value="<?= e((string)($s['blood_group'] ?? '')) ?>" maxlength="5" placeholder="e.g. O+">
                </div>
            </div>
            <div class="field">
                <label>Child photo</label>
                <?php if (!empty($s['photo_path'])): ?>
                    <span class="photo-thumb" style="background-image:url('<?= e(student_photo_url((string)$s['photo_path'])) ?>');"></span>
                    <span class="small">A photo is on file. Upload a new one to replace it.</span>
                <?php endif; ?>
                <input type="file" name="child_photo" accept="image/jpeg,image/png,image/webp">
            </div>
        </div>

        <?php foreach ([
            ['father', "Father's details", $father],
            ['mother', "Mother's details", $mother],
        ] as [$rel, $heading, $p]): ?>
            <div class="card">
                <h2><?= e($heading) ?></h2>
                <div class="field">
                    <label>Name</label>
                    <input type="text" name="<?= e($rel) ?>_name" value="<?= e((string)($p['name'] ?? '')) ?>">
                </div>
                <div class="row2">
                    <div class="field">
                        <label>Occupation</label>
                        <input type="text" name="<?= e($rel) ?>_occupation" value="<?= e((string)($p['occupation'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label>Place of work</label>
                        <input type="text" name="<?= e($rel) ?>_workplace" value="<?= e((string)($p['workplace'] ?? '')) ?>">
                    </div>
                </div>
                <div class="row2">
                    <div class="field">
                        <label>Contact number</label>
                        <input type="tel" name="<?= e($rel) ?>_phone" value="<?= e((string)($p['phone'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label>Email</label>
                        <input type="email" name="<?= e($rel) ?>_email" value="<?= e((string)($p['email'] ?? '')) ?>">
                    </div>
                </div>
                <div class="field">
                    <label><?= e(ucfirst($rel)) ?>'s photo</label>
                    <?php if (!empty($p['photo_path'])): ?>
                        <span class="photo-thumb" style="background-image:url('<?= e(student_photo_url((string)$p['photo_path'])) ?>');"></span>
                        <span class="small">A photo is on file. Upload a new one to replace it.</span>
                    <?php endif; ?>
                    <input type="file" name="<?= e($rel) ?>_photo" accept="image/jpeg,image/png,image/webp">
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card">
            <h2>Siblings</h2>
            <div class="field">
                <label>Sibling details</label>
                <textarea name="sibling_details" placeholder="One per line: Name | Gender | Age | Class | School"><?= e((string)($s['sibling_details'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="card">
            <h2>Address</h2>
            <div class="field">
                <label>Residential address</label>
                <textarea name="home_address"><?= e((string)($s['home_address'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="card">
            <h2>Emergency contact</h2>
            <span class="small">Used when both parents are unreachable.</span>
            <div class="row2" style="margin-top:.6rem;">
                <div class="field">
                    <label>Name</label>
                    <input type="text" name="emergency_contact_name" value="<?= e((string)($s['emergency_contact_name'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Relationship with the child</label>
                    <input type="text" name="emergency_contact_relation" value="<?= e((string)($s['emergency_contact_relation'] ?? '')) ?>">
                </div>
            </div>
            <div class="field">
                <label>Contact number</label>
                <input type="tel" name="emergency_contact_phone" value="<?= e((string)($s['emergency_contact_phone'] ?? '')) ?>">
            </div>
            <div class="field">
                <label>Address</label>
                <textarea name="emergency_contact_address"><?= e((string)($s['emergency_contact_address'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="card">
            <h2>Documents</h2>
            <span class="small">Upload a clear photo or PDF scan. Up to <?= e(format_bytes(STUDENT_DOC_MAX_BYTES)) ?> each.</span>
            <div class="field" style="margin-top:.6rem;">
                <label>Birth certificate</label>
                <input type="file" name="birth_certificate" accept="application/pdf,image/jpeg,image/png">
            </div>
            <div class="field">
                <label>Aadhar card</label>
                <input type="file" name="aadhar" accept="application/pdf,image/jpeg,image/png">
            </div>
        </div>

        <div class="submit-row">
            <button class="btn btn-primary" type="submit">Save</button>
        </div>
    </form>
    <?php
});
