<?php
/**
 * students/admission_pdf.php — printable admission form mirroring the
 * school's paper form. Pre-filled with whatever's on the student row +
 * parent rows. Browser's "Save as PDF" turns this into a downloadable
 * PDF — no server-side PDF library required.
 *
 * Dual auth:
 *   ?id=N           → admin / staff with student access (uses session)
 *   ?token=TOKEN    → parent (no login — token from parent_form.php)
 *
 * Photos are inlined as base64 data URIs so a token-holding parent
 * doesn't need to authenticate against /students/photo.php to see
 * the images in their downloaded copy.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_form.php';

// ---------- Auth -------------------------------------------------------------
$token = (string)($_GET['token'] ?? '');
$studentId = 0;

if ($token !== '') {
    $ctx = student_by_form_token($token);
    if (!$ctx) {
        http_response_code(404);
        exit('Link not active.');
    }
    $studentId = (int)$ctx['student_id'];
} else {
    $user = require_login();
    $studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($studentId <= 0) {
        http_response_code(400);
        exit('Missing student id.');
    }
    // Reuse the same gate as view.php: admin OR has students/montessori module.
    if (!user_has_module($user, 'students') && !user_has_module($user, 'montessori')) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

// ---------- Load data --------------------------------------------------------
$stmt = db()->prepare("SELECT * FROM students WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $studentId]);
$s = $stmt->fetch();
if (!$s) { http_response_code(404); exit('Student not found.'); }

// For staff path: enforce per-teacher visibility the same way view.php does.
if ($token === '' && isset($user)
    && $user['role'] !== 'admin'
    && !user_has_module($user, 'students')
    && (int)$s['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    exit('Forbidden — this student is not assigned to you.');
}

$pstmt = db()->prepare("SELECT * FROM student_parents WHERE student_id = :id ORDER BY is_primary DESC, relation, id");
$pstmt->execute([':id' => $studentId]);
$parents = [];
foreach ($pstmt->fetchAll() as $p) {
    if (!isset($parents[$p['relation']])) $parents[$p['relation']] = $p;
}
$father = $parents['father'] ?? null;
$mother = $parents['mother'] ?? null;

// ---------- Helpers ----------------------------------------------------------
function pdf_data_uri(?string $stored): string
{
    if (!$stored) return '';
    $path = student_photos_dir() . '/' . basename($stored);
    if (!is_file($path)) return '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($path) ?: 'image/jpeg';
    $b64   = base64_encode((string)file_get_contents($path));
    return "data:$mime;base64,$b64";
}

function pdf_fmt_date(?string $iso): string
{
    if (!$iso || $iso === '0000-00-00') return '';
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('d / m / Y') : (string)$iso;
}

function pdf_age(?string $iso): string
{
    if (!$iso || $iso === '0000-00-00') return '';
    try {
        $a = (new DateTime($iso))->diff(new DateTime('today'));
        return $a->y . ' yrs ' . $a->m . ' mos';
    } catch (Throwable $e) { return ''; }
}

/** Pretty inline value with a thin underline so it reads like a filled form. */
function pdf_v(?string $val): string
{
    $t = trim((string)$val);
    return $t === '' ? '<span class="blank">&nbsp;</span>' : e($t);
}

/** Tick for the selected grade, hollow otherwise. */
function pdf_check(bool $on): string
{
    return $on ? '☑' : '☐';
}

$full         = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
$childPhoto   = pdf_data_uri($s['photo_path'] ?? null);
$fatherPhoto  = $father ? pdf_data_uri($father['photo_path'] ?? null) : '';
$motherPhoto  = $mother ? pdf_data_uri($mother['photo_path'] ?? null) : '';
$grade        = (string)$s['grade'];
$appName      = function_exists('app_name') ? app_name() : 'The Little Graduates';

// School logo embedded as a data URI so the downloaded PDF is self-contained.
$logoUri = '';
$logoPath = realpath(__DIR__ . '/../assets/img/logo.png');
if ($logoPath && is_file($logoPath)) {
    $logoUri = 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admission Form — <?= e($full) ?></title>
<style>
  /* Theme: Little Graduates pink + green from the school logo. */
  :root {
    --tlg-pink: #e91e63;
    --tlg-pink-dark: #ad1457;
    --tlg-pink-wash: #fce4ec;
    --tlg-green: #66bb6a;
    color-scheme: light;
  }
  * { box-sizing: border-box; }
  html, body { background: #f4eef1; margin: 0; padding: 0;
               font: 11pt/1.4 "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif;
               color: #1a1a1a; }
  .sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 1.5rem auto;
           padding: 14mm 14mm 18mm; box-shadow: 0 2px 12px rgba(0,0,0,.12); position: relative;
           border-top: 6px solid var(--tlg-pink); }
  .toolbar { position: sticky; top: 0; z-index: 5;
             background: #fff; border-bottom: 1px solid #e9c2d3; padding: .6rem 1rem;
             display: flex; gap: .5rem; align-items: center; justify-content: flex-end; }
  .btn { padding: .45rem .9rem; background: var(--tlg-pink); color: #fff; border: 0; border-radius: 5px;
         font: 500 .9rem/1 inherit; cursor: pointer; text-decoration: none; }
  .btn:hover { background: var(--tlg-pink-dark); }
  .btn-ghost { background: transparent; color: var(--tlg-pink); border: 1px solid var(--tlg-pink); }

  /* Branded header with logo + school name. */
  .brand { display: grid; grid-template-columns: 28mm 1fr auto; gap: 1rem; align-items: center;
           padding-bottom: .8rem; border-bottom: 2px solid var(--tlg-pink); margin-bottom: 1rem; }
  .brand img { width: 28mm; height: auto; }
  .brand .school-name { font-size: 19pt; color: var(--tlg-pink); font-weight: 800; line-height: 1.05;
                        letter-spacing: .5px; margin: 0; text-transform: uppercase; }
  .brand .school-tag  { font-size: 9pt; color: var(--tlg-green); font-weight: 600; margin: .15rem 0 0; letter-spacing: .3px; }
  .brand .form-label  { text-align: right; font-size: 11pt; color: var(--tlg-pink-dark); font-weight: 700;
                        letter-spacing: 1px; text-transform: uppercase; border-left: 2px dashed #f3c1d5;
                        padding-left: .8rem; }
  .brand .form-label small { display: block; font-size: 8pt; font-weight: 500; color: #888; margin-top: .15rem; text-transform: none; letter-spacing: 0; }

  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem 1.4rem; margin-bottom: .5rem; }
  .grid.three { grid-template-columns: 1fr 1fr 1fr; }
  .label { font-weight: 600; color: #444; font-size: 9.5pt; display: block; margin-bottom: .15rem; }
  .v { border-bottom: 1px dotted #c98cab; padding: .15rem .2rem; min-height: 1.3rem; color: #1a1a1a; }
  .blank { color: #d4b5c5; }

  .section { border-top: 1px solid #f3c1d5; padding-top: .55rem; margin-top: .75rem; }
  .section .num { font-weight: 700; color: var(--tlg-pink); margin-right: .3rem; }
  .section-title { font-weight: 700; font-size: 10.5pt; margin: 0 0 .4rem; color: #4a2138; }

  .photo-box { width: 28mm; height: 36mm; border: 1px solid #c98cab; border-radius: 2px;
               display: flex; align-items: center; justify-content: center;
               background: #fff7fb center/cover no-repeat; font-size: 8pt; color: #b08099; text-align: center; }
  .row-with-photo { display: grid; grid-template-columns: 1fr 32mm; gap: 1.2rem; align-items: start; }

  .checkbox-row { display: flex; gap: 1.2rem; font-size: 10.5pt; margin: .3rem 0 .6rem; color: #4a2138; }
  .checkbox-row label { display: inline-flex; gap: .25rem; align-items: center; }

  table { border-collapse: collapse; width: 100%; font-size: 9.5pt; margin-top: .35rem; }
  th, td { border: 1px solid #d8a3bf; padding: .25rem .4rem; vertical-align: top; }
  th { background: var(--tlg-pink-wash); color: var(--tlg-pink-dark); font-weight: 600; text-align: left; }

  .checklist { display: flex; gap: 1.5rem; padding-top: .4rem; font-size: 10pt; }
  .footer-sign { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1.5rem;
                 padding-top: .8rem; border-top: 2px solid var(--tlg-pink); font-size: 10pt; }

  /* Print: hide tools + chrome, page-fit to A4. Preserve the brand colors. */
  @page { size: A4; margin: 12mm; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; border-top-width: 4px; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<div class="toolbar">
    <span style="margin-right:auto; color:#555; font-size:.85rem;">
        Use your browser's Print dialog → <strong>Save as PDF</strong> to download.
    </span>
    <button class="btn" onclick="window.print();" type="button">Print / Save as PDF</button>
</div>

<div class="sheet">
    <div class="brand">
        <?php if ($logoUri): ?>
            <img src="<?= e($logoUri) ?>" alt="">
        <?php else: ?>
            <div></div>
        <?php endif; ?>
        <div>
            <p class="school-name"><?= e($appName) ?></p>
            <p class="school-tag">Early Learning Centre</p>
        </div>
        <div class="form-label">
            Admission Form
            <small>Form date: <?= e(date('d M Y')) ?></small>
        </div>
    </div>

    <div class="section">
        <p class="section-title"><span class="num">1.</span> Admission For</p>
        <div class="checkbox-row">
            <label><?= pdf_check($grade === 'Playgroup') ?> Playgroup</label>
            <label><?= pdf_check($grade === 'Nursery')   ?> Nursery</label>
            <label><?= pdf_check($grade === 'LKG')       ?> Jr. KG (LKG)</label>
            <label><?= pdf_check($grade === 'UKG')       ?> Sr. KG (UKG)</label>
        </div>
    </div>

    <div class="row-with-photo">
        <div>
            <div class="section">
                <p class="section-title"><span class="num">2.</span> Name of the Child</p>
                <div class="v"><?= pdf_v($full) ?></div>
            </div>
            <div class="grid">
                <div>
                    <span class="label"><span class="num">3.</span> Date of Birth</span>
                    <div class="v"><?= pdf_v(pdf_fmt_date($s['dob'] ?? null)) ?></div>
                </div>
                <div>
                    <span class="label"><span class="num">4.</span> Age</span>
                    <div class="v"><?= pdf_v(pdf_age($s['dob'] ?? null)) ?></div>
                </div>
                <div>
                    <span class="label"><span class="num">5.</span> Gender</span>
                    <div class="v"><?= pdf_v($s['gender'] ?? null) ?></div>
                </div>
                <div>
                    <span class="label"><span class="num">6.</span> Place of Birth</span>
                    <div class="v"><?= pdf_v($s['place_of_birth'] ?? null) ?></div>
                </div>
                <div>
                    <span class="label"><span class="num">7.</span> Nationality</span>
                    <div class="v"><?= pdf_v($s['nationality'] ?? null) ?></div>
                </div>
                <div>
                    <span class="label"><span class="num">8.</span> Mother Tongue</span>
                    <div class="v"><?= pdf_v($s['mother_tongue'] ?? null) ?></div>
                </div>
                <div>
                    <span class="label">Blood Group</span>
                    <div class="v"><?= pdf_v($s['blood_group'] ?? null) ?></div>
                </div>
                <div>
                    <span class="label">Section · Admission #</span>
                    <div class="v"><?= pdf_v(trim(((string)($s['section'] ?? '')) . ' · ' . ((string)($s['admission_number'] ?? '')), ' ·')) ?></div>
                </div>
            </div>
        </div>
        <div>
            <div class="photo-box" <?= $childPhoto ? 'style="background-image:url(\'' . e($childPhoto) . '\');"' : '' ?>>
                <?= $childPhoto ? '' : 'Photo of the Child' ?>
            </div>
        </div>
    </div>

    <?php foreach ([
        ['9.',  "Father's details",  $father, $fatherPhoto, 'Photo of the Father'],
        ['10.', "Mother's details",  $mother, $motherPhoto, 'Photo of the Mother'],
    ] as [$num, $heading, $p, $photo, $photoCaption]): ?>
        <div class="section">
            <p class="section-title"><span class="num"><?= e($num) ?></span> <?= e($heading) ?></p>
            <div class="row-with-photo">
                <div>
                    <div class="grid">
                        <div>
                            <span class="label">a. Name</span>
                            <div class="v"><?= pdf_v($p['name'] ?? null) ?></div>
                        </div>
                        <div>
                            <span class="label">b. Occupation</span>
                            <div class="v"><?= pdf_v($p['occupation'] ?? null) ?></div>
                        </div>
                        <div>
                            <span class="label">c. Place of work</span>
                            <div class="v"><?= pdf_v($p['workplace'] ?? null) ?></div>
                        </div>
                        <div>
                            <span class="label">d. Contact no.</span>
                            <div class="v"><?= pdf_v($p['phone'] ?? null) ?></div>
                        </div>
                        <div style="grid-column: 1 / span 2;">
                            <span class="label">Email</span>
                            <div class="v"><?= pdf_v($p['email'] ?? null) ?></div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="photo-box" <?= $photo ? 'style="background-image:url(\'' . e($photo) . '\');"' : '' ?>>
                        <?= $photo ? '' : e($photoCaption) ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="section">
        <p class="section-title"><span class="num">11.</span> Sibling details</p>
        <?php
        $rawSib = trim((string)($s['sibling_details'] ?? ''));
        $rows = $rawSib === '' ? [] : preg_split('/\r\n|\n|\r/', $rawSib);
        ?>
        <table>
            <thead>
                <tr>
                    <th style="width:34%;">Name of the Child</th>
                    <th style="width:12%;">Gender</th>
                    <th style="width:12%;">Age</th>
                    <th style="width:16%;">Class</th>
                    <th>School</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r):
                    $parts = array_map('trim', explode('|', $r));
                    [$n, $g, $a, $c, $sch] = array_pad($parts, 5, '');
                ?>
                    <tr>
                        <td><?= pdf_v($n) ?></td>
                        <td><?= pdf_v($g) ?></td>
                        <td><?= pdf_v($a) ?></td>
                        <td><?= pdf_v($c) ?></td>
                        <td><?= pdf_v($sch) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5">&nbsp;</td></tr>
                    <tr><td colspan="5">&nbsp;</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="font-size:8.5pt; color:#888; margin:.25rem 0 0;">Format on the parent form: one per line, Name | Gender | Age | Class | School.</p>
    </div>

    <div class="section">
        <p class="section-title"><span class="num">12.</span> Residential address</p>
        <div class="v" style="min-height:2.6rem;"><?= pdf_v($s['home_address'] ?? null) ?></div>
    </div>

    <div class="section">
        <p class="section-title"><span class="num">13.</span> Emergency Contact Details</p>
        <p style="font-size:9pt; color:#666; margin:0 0 .4rem;">Used during emergency when both parents are not available.</p>
        <div class="grid">
            <div>
                <span class="label">a. Name</span>
                <div class="v"><?= pdf_v($s['emergency_contact_name'] ?? null) ?></div>
            </div>
            <div>
                <span class="label">b. Relationship with the child</span>
                <div class="v"><?= pdf_v($s['emergency_contact_relation'] ?? null) ?></div>
            </div>
            <div>
                <span class="label">c. Contact no.</span>
                <div class="v"><?= pdf_v($s['emergency_contact_phone'] ?? null) ?></div>
            </div>
            <div>
                <span class="label">d. Address</span>
                <div class="v"><?= pdf_v($s['emergency_contact_address'] ?? null) ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <p class="section-title">Checklist</p>
        <div class="checklist">
            <label>☐ (a) Birth Certificate *</label>
            <label>☐ (b) Aadhar Card *</label>
            <span style="margin-left:auto; font-size:8.5pt; color:#888;">* Submit photocopy</span>
        </div>
    </div>

    <div class="section">
        <p class="section-title">For Office Use</p>
        <div class="grid">
            <div>
                <span class="label">Admission No.</span>
                <div class="v"><?= pdf_v($s['admission_number'] ?? null) ?></div>
            </div>
            <div>
                <span class="label">Receipt No.</span>
                <div class="v">&nbsp;</div>
            </div>
            <div>
                <span class="label">Date</span>
                <div class="v"><?= pdf_v(pdf_fmt_date($s['intake_approved_at'] ? substr((string)$s['intake_approved_at'], 0, 10) : ($s['joining_date'] ?? null))) ?></div>
            </div>
            <div>
                <span class="label">Place</span>
                <div class="v">&nbsp;</div>
            </div>
        </div>
        <div class="grid" style="margin-top:.4rem;">
            <div>
                <span class="label">Admission Fees</span>
                <div class="v">&nbsp;</div>
            </div>
            <div>
                <span class="label">Tuition Fees</span>
                <div class="v">&nbsp;</div>
            </div>
            <div style="grid-column: 1 / span 2;">
                <span class="label">Maintenance &amp; Miscellaneous Fees</span>
                <div class="v">&nbsp;</div>
            </div>
        </div>
    </div>

    <div class="footer-sign">
        <div>
            <span class="label">Date</span>
            <div class="v"><?= pdf_v(date('d / m / Y')) ?></div>
        </div>
        <div>
            <span class="label">Signature of School Representative</span>
            <div class="v">&nbsp;</div>
        </div>
    </div>
</div>

</body>
</html>
