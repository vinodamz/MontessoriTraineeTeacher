<?php
/**
 * students/export.php — admins download the students roster as an .xlsx.
 *
 * Honours the same query-string filters as students/index.php
 * (q, grade, teacher_id, year, status, active) so admins can export
 * a filtered slice instead of always pulling everything.
 *
 * Columns: every editable field on students + flattened father/mother/
 * guardian (name/phone/email/occupation). The file round-trips through
 * students/import.php — open it in Excel, edit, save, re-upload, done.
 *
 * Two button on /students/index.php points here. Admins only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/xlsx.php';

require_admin();

const EXPORT_HEADERS = [
    'admission_number', 'first_name', 'last_name',
    'grade', 'teacher', 'academic_year', 'enrollment_status', 'is_active',
    'gender', 'dob', 'joining_date',
    'blood_group', 'allergies', 'medical_notes',
    'home_address', 'permanent_address',
    'pickup_person', 'pickup_phone',
    'emergency_contact_name', 'emergency_contact_phone',
    'notes',
    'withdrawal_date', 'withdrawal_reason', 'withdrawal_notes',
    'father_name',   'father_phone',   'father_email',   'father_occupation',
    'mother_name',   'mother_phone',   'mother_email',   'mother_occupation',
    'guardian_name', 'guardian_phone', 'guardian_email', 'guardian_occupation',
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
           s.grade, u.name AS teacher_name, s.academic_year,
           s.enrollment_status, s.is_active,
           s.gender, s.dob, s.joining_date,
           s.blood_group, s.allergies, s.medical_notes,
           s.home_address, s.permanent_address,
           s.pickup_person, s.pickup_phone,
           s.emergency_contact_name, s.emergency_contact_phone,
           s.notes,
           s.withdrawal_date, s.withdrawal_reason, s.withdrawal_notes
    FROM   students s
    JOIN   users u ON u.id = s.teacher_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER  BY s.grade, s.first_name, s.last_name, s.id
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Bulk-load parent rows for these students — one query, one trip.
$parentsByStudent = [];
if ($students) {
    $ids = array_column($students, 'id');
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
        // First occurrence of each relation per student wins (is_primary first).
        if (!isset($parentsByStudent[$sid][$rel])) {
            $parentsByStudent[$sid][$rel] = $p;
        }
    }
}

$rows = [];
foreach ($students as $s) {
    $row = [
        'admission_number'        => (string)($s['admission_number'] ?? ''),
        'first_name'              => (string)$s['first_name'],
        'last_name'               => (string)$s['last_name'],
        'grade'                   => (string)$s['grade'],
        'teacher'                 => (string)$s['teacher_name'],
        'academic_year'           => (string)($s['academic_year'] ?? ''),
        'enrollment_status'       => (string)($s['enrollment_status'] ?? ''),
        'is_active'               => ((int)$s['is_active'] === 1) ? '1' : '0',
        'gender'                  => (string)($s['gender'] ?? ''),
        'dob'                     => (string)($s['dob'] ?? ''),
        'joining_date'            => (string)($s['joining_date'] ?? ''),
        'blood_group'             => (string)($s['blood_group'] ?? ''),
        'allergies'               => (string)($s['allergies'] ?? ''),
        'medical_notes'           => (string)($s['medical_notes'] ?? ''),
        'home_address'            => (string)($s['home_address'] ?? ''),
        'permanent_address'       => (string)($s['permanent_address'] ?? ''),
        'pickup_person'           => (string)($s['pickup_person'] ?? ''),
        'pickup_phone'            => (string)($s['pickup_phone'] ?? ''),
        'emergency_contact_name'  => (string)($s['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => (string)($s['emergency_contact_phone'] ?? ''),
        'notes'                   => (string)($s['notes'] ?? ''),
        'withdrawal_date'         => (string)($s['withdrawal_date'] ?? ''),
        'withdrawal_reason'       => (string)($s['withdrawal_reason'] ?? ''),
        'withdrawal_notes'        => (string)($s['withdrawal_notes'] ?? ''),
    ];
    foreach (['father', 'mother', 'guardian'] as $rel) {
        $p = $parentsByStudent[(int)$s['id']][$rel] ?? null;
        $row["{$rel}_name"]       = $p ? (string)$p['name']       : '';
        $row["{$rel}_phone"]      = $p ? (string)($p['phone']      ?? '') : '';
        $row["{$rel}_email"]      = $p ? (string)($p['email']      ?? '') : '';
        $row["{$rel}_occupation"] = $p ? (string)($p['occupation'] ?? '') : '';
    }
    $rows[] = $row;
}

// Write to a tmp file, then stream to the browser.
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
