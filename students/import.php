<?php
/**
 * students/import.php — CSV bulk import of students.
 *
 *   GET                     → upload form + template link.
 *   POST step=preview       → upload CSV, show parsed rows + per-row validation,
 *                             carry the file content forward in the session.
 *   POST step=commit        → actually insert valid rows.
 *
 * Required CSV headers (case-insensitive, any order):
 *   first_name, last_name, grade, teacher
 *
 * Optional headers (any of):
 *   admission_number, gender, dob, joining_date, blood_group,
 *   allergies, medical_notes, home_address, pickup_person, pickup_phone,
 *   emergency_contact_name, emergency_contact_phone, notes
 *
 * `teacher` matches against users.name (case-insensitive, exact). It also
 * accepts a numeric user_id. Missing → row rejected.
 *
 * Admins only — bulk inserts can blast data across all teachers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_admin();

const CSV_MAX_BYTES = 2 * 1024 * 1024;   // 2 MB
const CSV_MAX_ROWS  = 1000;              // sanity ceiling

$VALID_GRADES  = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$VALID_GENDERS = ['Male', 'Female', 'Other'];

$ALLOWED_HEADERS = [
    'first_name', 'last_name', 'grade', 'teacher',
    'admission_number', 'gender', 'dob', 'joining_date', 'blood_group',
    'allergies', 'medical_notes', 'home_address',
    'pickup_person', 'pickup_phone',
    'emergency_contact_name', 'emergency_contact_phone',
    'notes',
];
$REQUIRED_HEADERS = ['first_name', 'grade', 'teacher'];

/** Resolve a teacher reference (numeric id or exact name) to a users.id. */
function resolve_teacher(string $ref, array $idByName, array $idsById): ?int
{
    $ref = trim($ref);
    if ($ref === '') return null;
    if (ctype_digit($ref) && isset($idsById[(int)$ref])) return (int)$ref;
    $lower = mb_strtolower($ref);
    return $idByName[$lower] ?? null;
}

/** Normalise common date inputs (YYYY-MM-DD / DD/MM/YYYY / D-M-YYYY) → YYYY-MM-DD or null. */
function parse_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d && $d->format($fmt) === $s) return $d->format('Y-m-d');
    }
    // Last-ditch: strtotime fallback.
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}

// Teacher index for resolution.
$teachers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1
      AND (role = 'admin' OR FIND_IN_SET('montessori', modules) > 0)
")->fetchAll();
$idByName = [];   // lower-cased name → id
$idsById  = [];
foreach ($teachers as $t) {
    $idByName[mb_strtolower($t['name'])] = (int)$t['id'];
    $idsById[(int)$t['id']] = true;
}

start_session_once();

// ---------- POST: preview ------------------------------------------------
$preview = null;       // ['rows' => [...], 'errors' => [...], 'headers' => [...]]
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'preview') {
    csrf_check();
    try {
        if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed. Pick a CSV under ' . format_bytes(CSV_MAX_BYTES) . '.');
        }
        if (($_FILES['csv']['size'] ?? 0) > CSV_MAX_BYTES) {
            throw new RuntimeException('CSV too large. Max ' . format_bytes(CSV_MAX_BYTES) . '.');
        }
        $h = fopen($_FILES['csv']['tmp_name'], 'rb');
        if (!$h) throw new RuntimeException('Could not read uploaded file.');
        $headerRow = fgetcsv($h);
        if (!$headerRow) throw new RuntimeException('CSV is empty.');
        $headers = array_map(fn($s) => mb_strtolower(trim((string)$s)), $headerRow);

        $missing = array_diff($REQUIRED_HEADERS, $headers);
        if ($missing) {
            throw new RuntimeException('Missing required columns: ' . implode(', ', $missing));
        }
        $unknown = array_diff($headers, $ALLOWED_HEADERS);
        if ($unknown) {
            // Just a warning — extra columns are ignored, not fatal.
        }
        $colIdx = array_flip($headers);

        $rows = []; $errors = []; $rowNum = 1; // header is row 1
        while (($r = fgetcsv($h)) !== false) {
            $rowNum++;
            if (count($rows) >= CSV_MAX_ROWS) {
                $errors[] = "Stopped at $rowNum: too many rows (max " . CSV_MAX_ROWS . ").";
                break;
            }
            // Pad short rows so isset() works.
            $r = array_pad($r, count($headers), '');
            $get = fn(string $h) => isset($colIdx[$h]) ? trim((string)$r[$colIdx[$h]]) : '';

            $rec = [
                'row_num'                 => $rowNum,
                'first_name'              => $get('first_name'),
                'last_name'               => $get('last_name'),
                'grade'                   => $get('grade'),
                'teacher_ref'             => $get('teacher'),
                'admission_number'        => $get('admission_number'),
                'gender'                  => $get('gender'),
                'dob'                     => $get('dob'),
                'joining_date'            => $get('joining_date'),
                'blood_group'             => $get('blood_group'),
                'allergies'               => $get('allergies'),
                'medical_notes'           => $get('medical_notes'),
                'home_address'            => $get('home_address'),
                'pickup_person'           => $get('pickup_person'),
                'pickup_phone'            => $get('pickup_phone'),
                'emergency_contact_name'  => $get('emergency_contact_name'),
                'emergency_contact_phone' => $get('emergency_contact_phone'),
                'notes'                   => $get('notes'),
            ];

            // Validate.
            $rec['errors'] = [];
            if ($rec['first_name'] === '')                                    $rec['errors'][] = 'first_name missing';
            if (!in_array($rec['grade'], $VALID_GRADES, true))                $rec['errors'][] = 'grade invalid';
            $tid = resolve_teacher($rec['teacher_ref'], $idByName, $idsById);
            if ($tid === null)                                                $rec['errors'][] = 'teacher "' . $rec['teacher_ref'] . '" not found';
            $rec['teacher_id'] = $tid;
            if ($rec['gender'] !== '' && !in_array($rec['gender'], $VALID_GENDERS, true)) {
                $rec['errors'][] = 'gender invalid';
            }
            $rec['dob_parsed']     = $rec['dob'] === ''     ? null : parse_date($rec['dob']);
            if ($rec['dob'] !== '' && $rec['dob_parsed'] === null)            $rec['errors'][] = 'dob unparseable';
            $rec['joining_parsed'] = $rec['joining_date'] === '' ? null : parse_date($rec['joining_date']);
            if ($rec['joining_date'] !== '' && $rec['joining_parsed'] === null) $rec['errors'][] = 'joining_date unparseable';

            $rec['ok'] = empty($rec['errors']);
            $rows[] = $rec;
        }
        fclose($h);

        // Stash for the commit step.
        $_SESSION['_csv_import'] = ['rows' => $rows, 'headers' => $headers];

        $preview = ['rows' => $rows, 'errors' => $errors, 'headers' => $headers];
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
}

// ---------- POST: commit ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'commit') {
    csrf_check();
    $stash = $_SESSION['_csv_import'] ?? null;
    if (!$stash) {
        flash_set('error', 'No preview in session. Re-upload the CSV.');
        redirect('/students/import.php');
    }
    $inserted = 0; $skipped = 0;
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $ins = $pdo->prepare("
            INSERT INTO students
                (admission_number, first_name, last_name, grade, teacher_id,
                 gender, dob, joining_date, blood_group, allergies, medical_notes,
                 home_address, pickup_person, pickup_phone,
                 emergency_contact_name, emergency_contact_phone, notes, is_active)
            VALUES
                (:adm, :f, :l, :g, :tid,
                 :gender, :dob, :join, :blood, :allg, :med,
                 :addr, :pickN, :pickP, :emN, :emP, :notes, 1)
        ");
        foreach ($stash['rows'] as $rec) {
            if (!$rec['ok']) { $skipped++; continue; }
            $ins->execute([
                ':adm'    => $rec['admission_number'] !== '' ? $rec['admission_number'] : null,
                ':f'      => $rec['first_name'],
                ':l'      => $rec['last_name'],
                ':g'      => $rec['grade'],
                ':tid'    => $rec['teacher_id'],
                ':gender' => $rec['gender']      !== '' ? $rec['gender'] : null,
                ':dob'    => $rec['dob_parsed'],
                ':join'   => $rec['joining_parsed'],
                ':blood'  => $rec['blood_group']  !== '' ? $rec['blood_group']  : null,
                ':allg'   => $rec['allergies']    !== '' ? $rec['allergies']    : null,
                ':med'    => $rec['medical_notes']!== '' ? $rec['medical_notes']: null,
                ':addr'   => $rec['home_address'] !== '' ? $rec['home_address'] : null,
                ':pickN'  => $rec['pickup_person']!== '' ? $rec['pickup_person']: null,
                ':pickP'  => $rec['pickup_phone'] !== '' ? $rec['pickup_phone'] : null,
                ':emN'    => $rec['emergency_contact_name']  !== '' ? $rec['emergency_contact_name']  : null,
                ':emP'    => $rec['emergency_contact_phone'] !== '' ? $rec['emergency_contact_phone'] : null,
                ':notes'  => $rec['notes']        !== '' ? $rec['notes']        : null,
            ]);
            $inserted++;
        }
        $pdo->commit();
        unset($_SESSION['_csv_import']);
        flash_set('ok', "Imported $inserted student" . ($inserted === 1 ? '' : 's') .
                       ($skipped ? " · skipped $skipped row" . ($skipped === 1 ? '' : 's') . ' with errors' : ''));
        redirect('/students/index.php');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Commit failed: ' . $e->getMessage() . ' (no rows were inserted).');
        redirect('/students/import.php');
    }
}

$pageTitle = 'Bulk import students';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Bulk import students</h1>
        <p class="muted">Upload a CSV with one student per row. Preview first, then commit.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<details class="card card-form" <?= $preview ? '' : 'open' ?>>
    <summary>Upload CSV</summary>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step"  value="preview">
        <div class="row">
            <div class="field" style="flex: 1 1 100%;">
                <label>CSV file <span class="muted small">(up to <?= format_bytes(CSV_MAX_BYTES) ?>, max <?= CSV_MAX_ROWS ?> rows)</span></label>
                <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Preview</button>
        </div>
    </form>
    <p class="muted small">
        <strong>Required columns:</strong> first_name, grade, teacher.<br>
        <strong>Optional columns:</strong> last_name, admission_number, gender (Male/Female/Other), dob,
        joining_date, blood_group, allergies, medical_notes, home_address, pickup_person, pickup_phone,
        emergency_contact_name, emergency_contact_phone, notes.<br>
        <strong>grade</strong> must be Playgroup / Nursery / LKG / UKG.
        <strong>teacher</strong> can be the teacher's name (case-insensitive, exact) or their user id.
        Dates accept YYYY-MM-DD, DD/MM/YYYY, or D-M-YYYY.
    </p>
</details>

<?php if ($preview): ?>
    <?php
        $rows   = $preview['rows'];
        $ok     = array_filter($rows, fn($r) => $r['ok']);
        $bad    = array_filter($rows, fn($r) => !$r['ok']);
    ?>
    <h2 class="section-h-spaced">Preview</h2>
    <p class="muted">
        <span class="pill"><?= count($ok) ?> ready to import</span>
        <?php if ($bad): ?><span class="pill pill-warn"><?= count($bad) ?> with errors (will be skipped)</span><?php endif; ?>
    </p>

    <table class="att-summary">
        <thead>
            <tr>
                <th>Row</th>
                <th>Name</th>
                <th>Grade</th>
                <th>Teacher</th>
                <th>DOB</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $full = trim($r['first_name'] . ' ' . $r['last_name']);
            ?>
                <tr>
                    <td><?= (int)$r['row_num'] ?></td>
                    <td><?= e($full) ?></td>
                    <td><?= e($r['grade']) ?></td>
                    <td><?= e($r['teacher_ref']) ?><?php if ($r['teacher_id']): ?> <span class="muted small">→ #<?= (int)$r['teacher_id'] ?></span><?php endif; ?></td>
                    <td><?= e($r['dob_parsed'] ?? $r['dob']) ?></td>
                    <td>
                        <?php if ($r['ok']): ?>
                            <span class="pill att-pill att-present">OK</span>
                        <?php else: ?>
                            <span class="pill att-pill att-absent"><?= e(implode(', ', $r['errors'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($ok): ?>
        <form method="post" class="actions section-h-spaced">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="step"  value="commit">
            <button class="btn btn-primary" type="submit"
                    onclick="return confirm('Insert <?= count($ok) ?> student rows? Bad rows will be skipped.')">
                Commit <?= count($ok) ?> row<?= count($ok) === 1 ? '' : 's' ?>
            </button>
            <a class="btn btn-ghost" href="/students/import.php">Cancel</a>
        </form>
    <?php else: ?>
        <div class="empty"><p>No valid rows to import. Fix the errors and re-upload.</p></div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
