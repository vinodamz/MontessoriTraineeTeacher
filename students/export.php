<?php
/**
 * students/export.php — admins download the students roster as an .xlsx.
 *
 * Honours the same query-string filters as students/index.php
 * (q, grade, teacher_id, year, status, active) so admins can export
 * a filtered slice instead of always pulling everything.
 *
 * Column set matches the front-office admission spreadsheet:
 *   first/last name, DOB, place of birth, joining date, admission type,
 *   gender, blood group, father (name/email/phone), mother (name/phone),
 *   grade, section, emergency contact, consent, address, document
 *   Y/N flags, transport, notes.
 *
 * `admission_number` is included as the first column so the file
 * round-trips cleanly through students/import.php — that's the upsert key.
 *
 * Document Y/N flags (application_form_received / photo_received /
 * id_card_received) are EXPORT-ONLY. They reflect whether at least
 * one matching record exists; the import flow ignores them so nobody
 * accidentally types "Y" without an actual file on disk.
 *
 * Admins only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/xlsx.php';

require_admin();

const EXPORT_HEADERS = [
    // Upsert key.
    'admission_number',
    // Child basics.
    'first_name', 'last_name',
    'dob', 'place_of_birth',
    'joining_date', 'admission_type',
    'gender', 'blood_group',
    // Parents.
    'father_name', 'father_email', 'father_phone',
    'mother_name', 'mother_phone',
    // Classroom.
    'grade', 'section',
    // Emergency + consent.
    'emergency_contact_phone', 'emergency_contact_name',
    'consent_given', 'consent_date',
    // Address + docs + transport + notes.
    'home_address',
    'application_form_received', 'photo_received', 'id_card_received',
    'transport',
    'notes',
    // Less-used round-trip extras (kept at the end so non-tech users
    // see the columns they asked for first).
    'permanent_address',
    'allergies', 'medical_notes',
    'pickup_person', 'pickup_phone',
    'mother_email', 'father_occupation', 'mother_occupation',
    'guardian_name', 'guardian_phone', 'guardian_email', 'guardian_occupation',
    'teacher', 'academic_year', 'enrollment_status', 'is_active', 'added_on',
    'withdrawal_date', 'withdrawal_reason', 'withdrawal_notes',
];

// ---------- Mirror students/index.php filters ---------------------------------
$q         = trim($_GET['q'] ?? '');
$gradeIn   = $_GET['grade'] ?? '';
$teacherIn = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$activeIn  = $_GET['active'] ?? 'all';
$yearIn    = $_GET['year']   ?? 'all';
$statusIn  = $_GET['status'] ?? 'all';

$validGrades = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$gradeFilter = in_array($gradeIn, $validGrades, true) ? $gradeIn : '';

$where  = [];
$params = [];

if ($q !== '') {
    $where[] = "(s.first_name LIKE :q OR s.last_name LIKE :q OR s.admission_number LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($gradeFilter !== '') {
    $where[] = "s.grade = :g";
    $params[':g'] = $gradeFilter;
}
if ($teacherIn > 0) {
    $where[] = "s.teacher_id = :tid";
    $params[':tid'] = $teacherIn;
}
if ($activeIn === 'active') {
    $where[] = "COALESCE(s.is_active, 1) = 1";
} elseif ($activeIn === 'inactive') {
    $where[] = "COALESCE(s.is_active, 1) = 0";
}
if ($yearIn !== '' && $yearIn !== 'all') {
    $where[] = "s.academic_year = :ay";
    $params[':ay'] = $yearIn;
}
if ($statusIn === 'enrolled') {
    $where[] = "COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'";
} elseif ($statusIn === 'left') {
    $where[] = "s.enrollment_status IN ('withdrawn','graduated','on_break')";
} elseif (defined('ENROLLMENT_STATUSES') && array_key_exists($statusIn, ENROLLMENT_STATUSES)) {
    $where[] = "s.enrollment_status = :es";
    $params[':es'] = $statusIn;
}

$sql = "
    SELECT s.id, s.admission_number, s.first_name, s.last_name,
           s.grade, s.section, u.name AS teacher_name, s.academic_year,
           s.enrollment_status, s.is_active, s.intake_approved_at,
           s.gender, s.dob, s.place_of_birth,
           s.joining_date, s.admission_type,
           s.blood_group, s.allergies, s.medical_notes,
           s.home_address, s.permanent_address,
           s.pickup_person, s.pickup_phone,
           s.emergency_contact_name, s.emergency_contact_phone,
           s.notes, s.consent_given, s.consent_date, s.transport,
           s.withdrawal_date, s.withdrawal_reason, s.withdrawal_notes,
           s.photo_path
    FROM   students s
    JOIN   users u ON u.id = s.teacher_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER  BY s.grade, COALESCE(s.section, ''), s.first_name, s.last_name, s.id
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Bulk-load parent rows for these students — one query, one trip.
$parentsByStudent = [];
// Bulk-load document categories per student so we can derive the Y/N flags
// without N+1 queries.
$docCatsByStudent = [];
if ($students) {
    $ids   = array_column($students, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));

    $pstmt = db()->prepare(
        "SELECT student_id, relation, name, phone, email, occupation
         FROM   student_parents
         WHERE  student_id IN ($place)
         ORDER  BY is_primary DESC, id"
    );
    $pstmt->execute($ids);
    foreach ($pstmt->fetchAll() as $p) {
        $sid = (int)$p['student_id'];
        $rel = $p['relation'];
        if (!isset($parentsByStudent[$sid][$rel])) {
            $parentsByStudent[$sid][$rel] = $p;
        }
    }

    $dstmt = db()->prepare(
        "SELECT DISTINCT student_id, category
         FROM   student_documents
         WHERE  student_id IN ($place)"
    );
    $dstmt->execute($ids);
    foreach ($dstmt->fetchAll() as $d) {
        $docCatsByStudent[(int)$d['student_id']][$d['category']] = true;
    }
}

$rows = [];
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $cats = $docCatsByStudent[$sid] ?? [];

    $row = [
        'admission_number'         => (string)($s['admission_number'] ?? ''),
        'first_name'               => (string)$s['first_name'],
        'last_name'                => (string)$s['last_name'],
        'dob'                      => (string)($s['dob'] ?? ''),
        'place_of_birth'           => (string)($s['place_of_birth'] ?? ''),
        'joining_date'             => (string)($s['joining_date'] ?? ''),
        'admission_type'           => (string)($s['admission_type'] ?? ''),
        'gender'                   => (string)($s['gender'] ?? ''),
        'blood_group'              => (string)($s['blood_group'] ?? ''),
        'grade'                    => (string)$s['grade'],
        'section'                  => (string)($s['section'] ?? ''),
        'emergency_contact_phone'  => (string)($s['emergency_contact_phone'] ?? ''),
        'emergency_contact_name'   => (string)($s['emergency_contact_name'] ?? ''),
        // Consent rendered as Yes/No/blank for non-tech-user readability;
        // the importer accepts the same vocabulary.
        'consent_given'            => $s['consent_given'] === null ? '' : ((int)$s['consent_given'] === 1 ? 'Yes' : 'No'),
        'consent_date'             => (string)($s['consent_date'] ?? ''),
        'home_address'             => (string)($s['home_address'] ?? ''),
        // Doc Y/N flags — derived. application_form lives under category
        // 'school'; ID cards under 'id_proof'; child photo is on the
        // students row itself (not in student_documents).
        'application_form_received' => isset($cats['school'])    ? 'Yes' : 'No',
        'photo_received'            => !empty($s['photo_path'])  ? 'Yes' : 'No',
        'id_card_received'          => isset($cats['id_proof'])  ? 'Yes' : 'No',
        'transport'                 => (string)($s['transport'] ?? ''),
        'notes'                     => (string)($s['notes'] ?? ''),
        'permanent_address'         => (string)($s['permanent_address'] ?? ''),
        'allergies'                 => (string)($s['allergies'] ?? ''),
        'medical_notes'             => (string)($s['medical_notes'] ?? ''),
        'pickup_person'             => (string)($s['pickup_person'] ?? ''),
        'pickup_phone'              => (string)($s['pickup_phone'] ?? ''),
        'teacher'                   => (string)$s['teacher_name'],
        'academic_year'             => (string)($s['academic_year'] ?? ''),
        'enrollment_status'         => (string)($s['enrollment_status'] ?? ''),
        'added_on'                  => !empty($s['intake_approved_at']) ? substr((string)$s['intake_approved_at'], 0, 10) : '',
        'is_active'                 => ((int)$s['is_active'] === 1) ? '1' : '0',
        'withdrawal_date'           => (string)($s['withdrawal_date'] ?? ''),
        'withdrawal_reason'         => (string)($s['withdrawal_reason'] ?? ''),
        'withdrawal_notes'          => (string)($s['withdrawal_notes'] ?? ''),
    ];
    foreach (['father', 'mother', 'guardian'] as $rel) {
        $p = $parentsByStudent[$sid][$rel] ?? null;
        $row["{$rel}_name"]       = $p ? (string)$p['name']       : '';
        $row["{$rel}_phone"]      = $p ? (string)($p['phone']      ?? '') : '';
        $row["{$rel}_email"]      = $p ? (string)($p['email']      ?? '') : '';
        $row["{$rel}_occupation"] = $p ? (string)($p['occupation'] ?? '') : '';
    }
    $rows[] = $row;
}

$tmp = tempnam(sys_get_temp_dir(), 'students_export_');
try {
    xlsx_write($tmp, EXPORT_HEADERS, $rows);

    $filename = 'students_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($tmp);
} finally {
    @unlink($tmp);
}
