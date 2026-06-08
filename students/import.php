<?php
/**
 * students/import.php — bulk import / update students from .xlsx or .csv.
 *
 *   GET                     → upload form + template link.
 *   POST step=preview       → upload file, show parsed rows + per-row validation,
 *                             carry the parsed rows forward in the session.
 *   POST step=commit        → run the upserts.
 *   GET  template=xlsx|csv  → empty template download (headers only).
 *
 * Required headers (case-insensitive, any order):
 *   first_name, grade, teacher
 *
 * Optional headers (any of):
 *   admission_number, last_name, gender, dob, joining_date, blood_group,
 *   allergies, medical_notes, home_address, permanent_address,
 *   pickup_person, pickup_phone, emergency_contact_name, emergency_contact_phone,
 *   notes, academic_year, enrollment_status, is_active,
 *   withdrawal_date, withdrawal_reason, withdrawal_notes,
 *   father_name,   father_phone,   father_email,   father_occupation,
 *   mother_name,   mother_phone,   mother_email,   mother_occupation,
 *   guardian_name, guardian_phone, guardian_email, guardian_occupation
 *
 * Upsert rule: when admission_number is present and matches an existing
 * student row, this is treated as an UPDATE — blank cells in the file
 * leave the existing column alone (so a teacher can fix a single field
 * without re-typing everything). With no admission_number or no match,
 * the row is INSERTed.
 *
 * `teacher` matches against users.name (case-insensitive, exact). It also
 * accepts a numeric user_id. Missing → row rejected.
 *
 * Parent groups: any of {father, mother, guardian}_name with a value
 * upserts a matching student_parents row for that relation. Blank
 * parent groups are ignored (existing parent rows are left alone).
 *
 * Admins only — bulk inserts/updates can blast data across all teachers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/xlsx.php';

$user = require_admin();

const IMPORT_MAX_BYTES = 4 * 1024 * 1024;   // 4 MB
const IMPORT_MAX_ROWS  = 2000;              // sanity ceiling

$VALID_GRADES   = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$VALID_GENDERS  = ['Male', 'Female', 'Other'];
$VALID_STATUSES = ['enrolled', 'promoted', 'withdrawn', 'graduated', 'on_break'];

$STUDENT_FIELDS = [
    'admission_number', 'first_name', 'last_name', 'grade', 'teacher',
    'gender', 'dob', 'joining_date', 'blood_group',
    'allergies', 'medical_notes', 'home_address', 'permanent_address',
    'pickup_person', 'pickup_phone',
    'emergency_contact_name', 'emergency_contact_phone',
    'notes', 'academic_year', 'enrollment_status', 'is_active',
    'withdrawal_date', 'withdrawal_reason', 'withdrawal_notes',
];
$PARENT_RELATIONS = ['father', 'mother', 'guardian'];
$PARENT_FIELDS    = ['name', 'phone', 'email', 'occupation'];

$ALLOWED_HEADERS = $STUDENT_FIELDS;
foreach ($PARENT_RELATIONS as $r) {
    foreach ($PARENT_FIELDS as $f) $ALLOWED_HEADERS[] = "{$r}_{$f}";
}
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

/**
 * YYYY-MM-DD / DD-MM-YYYY / DD/MM/YYYY → 'YYYY-MM-DD' or null.
 * Also handles Excel date serials (a bare number Excel writes when the
 * user types a date into a fresh sheet without forcing text format).
 */
function parse_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;

    // Excel serial: integer-ish in the 1900-2100 range.
    if (preg_match('/^\d{1,5}(\.\d+)?$/', $s)) {
        $n = (float)$s;
        if ($n >= 1 && $n < 80000) {
            // Excel epoch hack: 1899-12-30 + N days (handles the bogus 1900 leap).
            try {
                $d = new DateTime('1899-12-30');
                $d->modify('+' . (int)$n . ' days');
                return $d->format('Y-m-d');
            } catch (Throwable) { /* fall through */ }
        }
    }

    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d && $d->format($fmt) === $s) return $d->format('Y-m-d');
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}

/** Truthy text (1/yes/true/y) → 1; falsy (0/no/false/n) → 0; blank → null. */
function parse_bool(string $s): ?int
{
    $s = strtolower(trim($s));
    if ($s === '') return null;
    if (in_array($s, ['1', 'yes', 'y', 'true', 't', 'active'], true))   return 1;
    if (in_array($s, ['0', 'no',  'n', 'false', 'f', 'inactive'], true)) return 0;
    return null;
}

/**
 * Read a CSV or XLSX upload into a unified shape:
 *   ['headers' => string[],         // lowercased + trimmed
 *    'rows'    => array<int, array<int, string>>]  // each row is positional
 */
function read_upload(array $upload): array
{
    $ext = strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return xlsx_read($upload['tmp_name']);
    }
    // CSV path.
    $headers = [];
    $rows    = [];
    $h = fopen($upload['tmp_name'], 'rb');
    if (!$h) throw new RuntimeException('Could not read uploaded file.');
    $headerRow = fgetcsv($h);
    if (!$headerRow) {
        fclose($h);
        throw new RuntimeException('CSV is empty.');
    }
    foreach ($headerRow as $v) $headers[] = mb_strtolower(trim((string)$v));
    while (($r = fgetcsv($h)) !== false) {
        $hasAny = false;
        foreach ($r as $v) if (trim((string)$v) !== '') { $hasAny = true; break; }
        if (!$hasAny) continue;
        $rows[] = array_map(fn($v) => (string)$v, $r);
    }
    fclose($h);
    return ['headers' => $headers, 'rows' => $rows];
}

// ---------- GET: empty-template download --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['template'])) {
    $fmt     = $_GET['template'] === 'csv' ? 'csv' : 'xlsx';
    $headers = $ALLOWED_HEADERS;
    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="students_import_template.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, $headers);
        fclose($out);
        exit;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'students_tmpl_');
    try {
        xlsx_write($tmp, $headers, []);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="students_import_template.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
    } finally {
        @unlink($tmp);
    }
    exit;
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

// Existing admission_number → student.id lookup, for upserts.
$existingByAdm = [];
foreach (db()->query("SELECT id, admission_number FROM students WHERE admission_number IS NOT NULL AND admission_number <> ''") as $r) {
    $existingByAdm[strtolower(trim($r['admission_number']))] = (int)$r['id'];
}

start_session_once();

// ---------- POST: preview ----------------------------------------------------
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'preview') {
    csrf_check();
    try {
        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed. Pick a file under ' . format_bytes(IMPORT_MAX_BYTES) . '.');
        }
        if (($_FILES['file']['size'] ?? 0) > IMPORT_MAX_BYTES) {
            throw new RuntimeException('File too large. Max ' . format_bytes(IMPORT_MAX_BYTES) . '.');
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('Upload an .xlsx or .csv file.');
        }

        $parsed = read_upload($_FILES['file']);
        $headers = $parsed['headers'];
        $missing = array_diff($REQUIRED_HEADERS, $headers);
        if ($missing) {
            throw new RuntimeException('Missing required columns: ' . implode(', ', $missing));
        }
        $colIdx = array_flip($headers);

        $rows = []; $errors = []; $rowNum = 1; // header is row 1
        foreach ($parsed['rows'] as $r) {
            $rowNum++;
            if (count($rows) >= IMPORT_MAX_ROWS) {
                $errors[] = "Stopped at $rowNum: too many rows (max " . IMPORT_MAX_ROWS . ").";
                break;
            }
            $get = function (string $h) use ($r, $colIdx): string {
                if (!isset($colIdx[$h])) return '';
                $v = $r[$colIdx[$h]] ?? '';
                return trim((string)$v);
            };

            $rec = ['row_num' => $rowNum, 'errors' => []];
            foreach ($ALLOWED_HEADERS as $h) $rec[$h] = $get($h);

            // Resolve match by admission_number for upsert.
            $admKey = strtolower($rec['admission_number']);
            $rec['existing_id'] = ($admKey !== '' && isset($existingByAdm[$admKey])) ? $existingByAdm[$admKey] : null;
            $rec['is_update']   = $rec['existing_id'] !== null;

            // Validation. Updates can leave required fields blank — we keep
            // the existing value. Inserts must satisfy all required fields.
            if (!$rec['is_update']) {
                if ($rec['first_name'] === '') $rec['errors'][] = 'first_name missing';
                if ($rec['grade']      === '') $rec['errors'][] = 'grade missing';
                if ($rec['teacher']    === '') $rec['errors'][] = 'teacher missing';
            }
            if ($rec['grade'] !== '' && !in_array($rec['grade'], $VALID_GRADES, true)) {
                $rec['errors'][] = 'grade invalid (Playgroup/Nursery/LKG/UKG)';
            }
            if ($rec['teacher'] !== '') {
                $tid = resolve_teacher($rec['teacher'], $idByName, $idsById);
                if ($tid === null) $rec['errors'][] = 'teacher "' . $rec['teacher'] . '" not found';
                $rec['teacher_id'] = $tid;
            } else {
                $rec['teacher_id'] = null;
            }
            if ($rec['gender'] !== '' && !in_array($rec['gender'], $VALID_GENDERS, true)) {
                $rec['errors'][] = 'gender invalid';
            }
            if ($rec['enrollment_status'] !== '' && !in_array($rec['enrollment_status'], $VALID_STATUSES, true)) {
                $rec['errors'][] = 'enrollment_status invalid';
            }
            foreach (['dob', 'joining_date', 'withdrawal_date'] as $df) {
                if ($rec[$df] === '') { $rec[$df . '_parsed'] = null; continue; }
                $p = parse_date($rec[$df]);
                if ($p === null) $rec['errors'][] = "$df unparseable: " . $rec[$df];
                $rec[$df . '_parsed'] = $p;
            }
            $rec['is_active_parsed'] = parse_bool($rec['is_active']);

            $rec['ok'] = empty($rec['errors']);
            $rows[] = $rec;
        }

        $_SESSION['_students_import'] = ['rows' => $rows];
        $preview = ['rows' => $rows, 'errors' => $errors, 'headers' => $headers];
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
}

// ---------- POST: commit ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'commit') {
    csrf_check();
    $stash = $_SESSION['_students_import'] ?? null;
    if (!$stash) {
        flash_set('error', 'No preview in session. Re-upload the file.');
        redirect('/students/import.php');
    }
    $inserted = 0; $updated = 0; $skipped = 0; $parentsWritten = 0;
    try {
        $pdo = db();
        $pdo->beginTransaction();

        $insStmt = $pdo->prepare("
            INSERT INTO students
                (admission_number, first_name, last_name, grade, teacher_id,
                 gender, dob, joining_date, blood_group,
                 allergies, medical_notes, home_address, permanent_address,
                 pickup_person, pickup_phone,
                 emergency_contact_name, emergency_contact_phone,
                 notes, is_active, academic_year, enrollment_status,
                 withdrawal_date, withdrawal_reason, withdrawal_notes)
            VALUES
                (:adm, :f, :l, :g, :tid,
                 :gender, :dob, :join, :blood,
                 :allg, :med, :addr, :paddr,
                 :pickN, :pickP, :emN, :emP,
                 :notes, :active, :ay, :es,
                 :wd, :wr, :wn)
        ");

        foreach ($stash['rows'] as $rec) {
            if (!$rec['ok']) { $skipped++; continue; }

            if ($rec['is_update']) {
                $sid = (int)$rec['existing_id'];
                // Build an UPDATE that only touches fields with a non-empty cell.
                // Empty cell = leave the existing value alone.
                $sets   = [];
                $params = [':id' => $sid];
                $map = [
                    'admission_number'        => 'admission_number',
                    'first_name'              => 'first_name',
                    'last_name'               => 'last_name',
                    'grade'                   => 'grade',
                    'gender'                  => 'gender',
                    'blood_group'             => 'blood_group',
                    'allergies'               => 'allergies',
                    'medical_notes'           => 'medical_notes',
                    'home_address'            => 'home_address',
                    'permanent_address'       => 'permanent_address',
                    'pickup_person'           => 'pickup_person',
                    'pickup_phone'            => 'pickup_phone',
                    'emergency_contact_name'  => 'emergency_contact_name',
                    'emergency_contact_phone' => 'emergency_contact_phone',
                    'notes'                   => 'notes',
                    'academic_year'           => 'academic_year',
                    'enrollment_status'       => 'enrollment_status',
                    'withdrawal_reason'       => 'withdrawal_reason',
                    'withdrawal_notes'        => 'withdrawal_notes',
                ];
                foreach ($map as $col => $field) {
                    if ($rec[$field] !== '') {
                        $sets[] = "$col = :$col";
                        $params[":$col"] = $rec[$field];
                    }
                }
                foreach (['dob', 'joining_date', 'withdrawal_date'] as $df) {
                    if ($rec[$df] !== '') {
                        $sets[] = "$df = :$df";
                        $params[":$df"] = $rec[$df . '_parsed'];
                    }
                }
                if ($rec['teacher_id'] !== null && $rec['teacher'] !== '') {
                    $sets[] = "teacher_id = :tid";
                    $params[':tid'] = $rec['teacher_id'];
                }
                if ($rec['is_active_parsed'] !== null) {
                    $sets[] = "is_active = :active";
                    $params[':active'] = $rec['is_active_parsed'];
                }
                if ($sets) {
                    $sql = "UPDATE students SET " . implode(', ', $sets) . " WHERE id = :id";
                    $pdo->prepare($sql)->execute($params);
                }
                $updated++;
            } else {
                $insStmt->execute([
                    ':adm'    => $rec['admission_number'] !== '' ? $rec['admission_number'] : null,
                    ':f'      => $rec['first_name'],
                    ':l'      => $rec['last_name'],
                    ':g'      => $rec['grade'],
                    ':tid'    => $rec['teacher_id'],
                    ':gender' => $rec['gender']      !== '' ? $rec['gender'] : null,
                    ':dob'    => $rec['dob_parsed'],
                    ':join'   => $rec['joining_date_parsed'],
                    ':blood'  => $rec['blood_group']  !== '' ? $rec['blood_group']  : null,
                    ':allg'   => $rec['allergies']    !== '' ? $rec['allergies']    : null,
                    ':med'    => $rec['medical_notes']!== '' ? $rec['medical_notes']: null,
                    ':addr'   => $rec['home_address'] !== '' ? $rec['home_address'] : null,
                    ':paddr'  => $rec['permanent_address'] !== '' ? $rec['permanent_address'] : null,
                    ':pickN'  => $rec['pickup_person']!== '' ? $rec['pickup_person']: null,
                    ':pickP'  => $rec['pickup_phone'] !== '' ? $rec['pickup_phone'] : null,
                    ':emN'    => $rec['emergency_contact_name']  !== '' ? $rec['emergency_contact_name']  : null,
                    ':emP'    => $rec['emergency_contact_phone'] !== '' ? $rec['emergency_contact_phone'] : null,
                    ':notes'  => $rec['notes']        !== '' ? $rec['notes']        : null,
                    ':active' => $rec['is_active_parsed'] ?? 1,
                    ':ay'     => $rec['academic_year'] !== '' ? $rec['academic_year'] : null,
                    ':es'     => $rec['enrollment_status'] !== '' ? $rec['enrollment_status'] : 'enrolled',
                    ':wd'     => $rec['withdrawal_date_parsed'],
                    ':wr'     => $rec['withdrawal_reason'] !== '' ? $rec['withdrawal_reason'] : null,
                    ':wn'     => $rec['withdrawal_notes']  !== '' ? $rec['withdrawal_notes']  : null,
                ]);
                $sid = (int)$pdo->lastInsertId();
                $inserted++;
            }

            // Parent upserts: any relation with a non-empty name goes in.
            foreach (['father', 'mother', 'guardian'] as $rel) {
                $name = $rec["{$rel}_name"];
                if ($name === '') continue;
                $find = $pdo->prepare("SELECT id FROM student_parents WHERE student_id = :sid AND relation = :rel LIMIT 1");
                $find->execute([':sid' => $sid, ':rel' => $rel]);
                $pid = $find->fetchColumn();

                $cols = [
                    'name'       => $name,
                    'phone'      => $rec["{$rel}_phone"]      !== '' ? $rec["{$rel}_phone"]      : null,
                    'email'      => $rec["{$rel}_email"]      !== '' ? $rec["{$rel}_email"]      : null,
                    'occupation' => $rec["{$rel}_occupation"] !== '' ? $rec["{$rel}_occupation"] : null,
                ];
                if ($pid) {
                    // Update existing — but keep current values when the import cell is empty,
                    // matching the student-row UPSERT semantics.
                    $sets = ['name = :name'];
                    $pp   = [':id' => (int)$pid, ':name' => $name];
                    foreach (['phone', 'email', 'occupation'] as $f) {
                        if ($rec["{$rel}_$f"] !== '') {
                            $sets[] = "$f = :$f";
                            $pp[":$f"] = $rec["{$rel}_$f"];
                        }
                    }
                    $pdo->prepare("UPDATE student_parents SET " . implode(', ', $sets) . " WHERE id = :id")->execute($pp);
                } else {
                    $pdo->prepare("
                        INSERT INTO student_parents (student_id, relation, name, phone, email, occupation, is_primary)
                        VALUES (:sid, :rel, :name, :phone, :email, :occ, 0)
                    ")->execute([
                        ':sid'   => $sid,
                        ':rel'   => $rel,
                        ':name'  => $cols['name'],
                        ':phone' => $cols['phone'],
                        ':email' => $cols['email'],
                        ':occ'   => $cols['occupation'],
                    ]);
                }
                $parentsWritten++;
            }
        }
        $pdo->commit();
        unset($_SESSION['_students_import']);

        $msg = "Imported $inserted new" . ($inserted === 1 ? '' : '') .
               " · updated $updated" .
               ($parentsWritten ? " · $parentsWritten parent row" . ($parentsWritten === 1 ? '' : 's') : '') .
               ($skipped ? " · skipped $skipped row" . ($skipped === 1 ? '' : 's') . ' with errors' : '');
        flash_set('ok', $msg);
        redirect('/students/index.php');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Commit failed: ' . $e->getMessage() . ' (no rows were changed).');
        redirect('/students/import.php');
    }
}

$pageTitle = 'Bulk import students';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Import students</h1>
        <p class="muted">Upload an Excel (.xlsx) or CSV file. Matching admission numbers update; new ones insert. Preview first.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<details class="card card-form" <?= $preview ? '' : 'open' ?>>
    <summary>Upload file</summary>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step"  value="preview">
        <div class="row">
            <div class="field" style="flex: 1 1 100%;">
                <label>Excel or CSV <span class="muted small">(up to <?= format_bytes(IMPORT_MAX_BYTES) ?>, max <?= IMPORT_MAX_ROWS ?> rows)</span></label>
                <input type="file" name="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Preview</button>
            <a class="btn btn-ghost" href="/students/import.php?template=xlsx">Download empty template (.xlsx)</a>
            <a class="btn btn-ghost" href="/students/export.php">Export current data (.xlsx)</a>
        </div>
    </form>
    <p class="muted small">
        <strong>Required:</strong> first_name, grade, teacher.
        <strong>Update vs insert:</strong> if admission_number matches an existing student, that row is updated and blank cells are left untouched. Otherwise a new student is inserted.<br>
        <strong>Grade</strong> must be Playgroup / Nursery / LKG / UKG.
        <strong>Teacher</strong> is the user's name (case-insensitive) or numeric id.
        <strong>Dates</strong> accept YYYY-MM-DD, DD/MM/YYYY, D-M-YYYY, or an Excel-style date cell.<br>
        <strong>Parents:</strong> fill any of father_name / mother_name / guardian_name (plus optional _phone / _email / _occupation) and a matching student_parents row will be created or updated. Empty parent groups are ignored.
    </p>
</details>

<?php if ($preview): ?>
    <?php
        $rows    = $preview['rows'];
        $okRows  = array_filter($rows, fn($r) => $r['ok']);
        $bad     = array_filter($rows, fn($r) => !$r['ok']);
        $updates = array_filter($okRows, fn($r) => $r['is_update']);
        $news    = array_filter($okRows, fn($r) => !$r['is_update']);
    ?>
    <h2 class="section-h-spaced">Preview</h2>
    <p class="muted">
        <span class="pill"><?= count($news) ?> new</span>
        <span class="pill"><?= count($updates) ?> update<?= count($updates) === 1 ? '' : 's' ?></span>
        <?php if ($bad): ?><span class="pill pill-warn"><?= count($bad) ?> with errors (will be skipped)</span><?php endif; ?>
    </p>

    <table class="att-summary">
        <thead>
            <tr>
                <th>Row</th>
                <th>Adm. no.</th>
                <th>Name</th>
                <th>Grade</th>
                <th>Teacher</th>
                <th>DOB</th>
                <th>Action</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $full = trim($r['first_name'] . ' ' . $r['last_name']);
                $action = $r['is_update'] ? "Update #{$r['existing_id']}" : 'Insert';
            ?>
                <tr>
                    <td><?= (int)$r['row_num'] ?></td>
                    <td><?= e($r['admission_number']) ?></td>
                    <td><?= e($full) ?></td>
                    <td><?= e($r['grade']) ?></td>
                    <td><?= e($r['teacher']) ?><?php if ($r['teacher_id']): ?> <span class="muted small">→ #<?= (int)$r['teacher_id'] ?></span><?php endif; ?></td>
                    <td><?= e($r['dob_parsed'] ?? $r['dob']) ?></td>
                    <td><?= e($action) ?></td>
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

    <?php if ($okRows): ?>
        <form method="post" class="actions section-h-spaced">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="step"  value="commit">
            <button class="btn btn-primary" type="submit"
                    onclick="return confirm('Apply <?= count($news) ?> insert<?= count($news) === 1 ? '' : 's' ?> and <?= count($updates) ?> update<?= count($updates) === 1 ? '' : 's' ?>?')">
                Commit <?= count($okRows) ?> row<?= count($okRows) === 1 ? '' : 's' ?>
            </button>
            <a class="btn btn-ghost" href="/students/import.php">Cancel</a>
        </form>
    <?php else: ?>
        <div class="empty"><p>No valid rows to import. Fix the errors and re-upload.</p></div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
