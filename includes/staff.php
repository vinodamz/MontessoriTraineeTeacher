<?php
/**
 * staff.php — Staff-management domain helpers.
 *
 * Pure domain code: label maps, leave-balance computation, attendance summary
 * roll-ups, and the document-upload helper. No HTML, no routing — every
 * /staff/*.php page requires this.
 *
 * Schema lives in sql/migrate_012_staff.sql.
 */

/**
 * Roster — every user who can appear in the staff module. Admins, teaching
 * and non-teaching staff (by role) plus anyone with the 'staff' module
 * enabled. Inactive users are still listed so historic records remain
 * attributable.
 */
function staff_roster(bool $activeOnly = false): array
{
    $sql = "
        SELECT id, name, role, active, modules
        FROM users
        WHERE role IN ('admin','teacher','non_teaching')
           OR FIND_IN_SET('staff', modules) > 0
    ";
    if ($activeOnly) $sql .= " AND active = 1";
    $sql .= " ORDER BY active DESC, name";
    return db()->query($sql)->fetchAll();
}

/** Look up one staff record by user id (returns false if not in roster). */
function staff_member(int $userId)
{
    $stmt = db()->prepare("
        SELECT id, name, role, active, modules
        FROM users
        WHERE id = :id
          AND (role IN ('admin','teacher','non_teaching') OR FIND_IN_SET('staff', modules) > 0)
    ");
    $stmt->execute([':id' => $userId]);
    return $stmt->fetch();
}

// ---- Attendance ---------------------------------------------------------

function staff_attendance_statuses(): array
{
    return [
        'present'  => 'Present',
        'late'     => 'Late',
        'absent'   => 'Absent',
        'leave'    => 'On leave',
        'wfh'      => 'WFH',
        'holiday'  => 'Holiday',
    ];
}

/** Monthly attendance summary for a single staff member. Keys are status codes. */
function staff_attendance_summary(int $userId, int $year, int $month): array
{
    $stmt = db()->prepare("
        SELECT status, COUNT(*) AS n
        FROM staff_attendance
        WHERE user_id = :u
          AND att_date BETWEEN :s AND :e
        GROUP BY status
    ");
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));
    $stmt->execute([':u' => $userId, ':s' => $start, ':e' => $end]);
    $out = array_fill_keys(array_keys(staff_attendance_statuses()), 0);
    foreach ($stmt->fetchAll() as $r) $out[$r['status']] = (int)$r['n'];
    return $out;
}

// ---- Leave --------------------------------------------------------------

function staff_leave_types(): array
{
    return [
        'casual'  => 'Casual',
        'sick'    => 'Sick',
        'earned'  => 'Earned',
        'unpaid'  => 'Unpaid',
        'other'   => 'Other',
    ];
}

function staff_leave_statuses(): array
{
    return [
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
}

/**
 * Compute leave balance for a user for a given year. For each type returns
 * total / used / remaining. "Used" counts approved requests only.
 */
function staff_leave_balance(int $userId, int $year): array
{
    $stmt = db()->prepare("
        SELECT leave_type, days_total FROM staff_leave_allowances
        WHERE user_id = :u AND year = :y
    ");
    $stmt->execute([':u' => $userId, ':y' => $year]);
    $totals = [];
    foreach ($stmt->fetchAll() as $r) $totals[$r['leave_type']] = (float)$r['days_total'];

    $usedStmt = db()->prepare("
        SELECT leave_type, SUM(days_count) AS used
        FROM staff_leave_requests
        WHERE user_id = :u
          AND status  = 'approved'
          AND YEAR(start_date) = :y
        GROUP BY leave_type
    ");
    $usedStmt->execute([':u' => $userId, ':y' => $year]);
    $used = [];
    foreach ($usedStmt->fetchAll() as $r) $used[$r['leave_type']] = (float)$r['used'];

    $out = [];
    foreach (staff_leave_types() as $code => $label) {
        $t = $totals[$code] ?? 0.0;
        $u = $used[$code] ?? 0.0;
        $out[$code] = [
            'label'     => $label,
            'total'     => $t,
            'used'      => $u,
            'remaining' => max(0.0, $t - $u),
        ];
    }
    return $out;
}

/** Compute days between two dates inclusive. Returns float (whole days only for now). */
function staff_leave_days(string $startDate, string $endDate): float
{
    $a = new DateTimeImmutable($startDate);
    $b = new DateTimeImmutable($endDate);
    return (float)($a->diff($b)->days + 1);
}

// ---- Issues -------------------------------------------------------------

function staff_issue_kinds(): array
{
    return [
        'one_on_one'  => '1:1',
        'performance' => 'Performance',
        'incident'    => 'Incident',
        'kudos'       => 'Kudos',
        'other'       => 'Other',
    ];
}

// ---- Documents ----------------------------------------------------------

function staff_doc_kinds(): array
{
    return [
        'id_proof'      => 'ID proof',
        'contract'      => 'Contract / offer',
        'certification' => 'Certificate',
        'experience'    => 'Previous experience certificate',
        'medical'       => 'Medical',
        'reference'     => 'Reference',
        'photo'         => 'Photo',
        'other'         => 'Other',
    ];
}

/**
 * The most recent 'photo' document for a staff member, or null. Used to render
 * their profile avatar. Returns the row (id / stored_name / mime_type) so the
 * caller can link it through /staff/download.php (auth-gated).
 */
function staff_latest_photo(int $userId): ?array
{
    try {
        $s = db()->prepare("
            SELECT id, stored_name, mime_type
            FROM staff_documents
            WHERE user_id = :u AND kind = 'photo'
            ORDER BY id DESC LIMIT 1
        ");
        $s->execute([':u' => $userId]);
        $r = $s->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        // Pre-migration DB where 'photo' isn't a valid enum value yet.
        return null;
    }
}

/**
 * Map of user_id → newest photo document id, for the whole roster in one query.
 * `MAX(id)` is the newest because id is a monotonic AUTO_INCREMENT.
 */
function staff_photo_id_map(): array
{
    $map = [];
    try {
        foreach (db()->query("
            SELECT user_id, MAX(id) AS id
            FROM staff_documents WHERE kind = 'photo' GROUP BY user_id
        ") as $r) {
            $map[(int)$r['user_id']] = (int)$r['id'];
        }
    } catch (Throwable $e) {
        // Pre-migration DB — no photos yet.
    }
    return $map;
}

// ---- Personal-details profile (the "joining application") ---------------

/** Gender options → labels. */
function staff_genders(): array
{
    return [
        'female'      => 'Female',
        'male'        => 'Male',
        'other'       => 'Other',
        'prefer_not'  => 'Prefer not to say',
    ];
}

/** Blood-group options (value === label). */
function staff_blood_groups(): array
{
    $g = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
    return array_combine($g, $g);
}

/** Relation options for the father / spouse block. */
function staff_relations(): array
{
    return [
        'father'   => 'Father',
        'spouse'   => 'Spouse',
        'mother'   => 'Mother',
        'guardian' => 'Guardian',
        'other'    => 'Other',
    ];
}

/**
 * The personal-details row for a user. Always returns an array keyed by every
 * column (empty strings when unset / pre-migration) so callers/templates can
 * read fields without isset() noise. `_exists` flags whether a row is on file.
 */
function staff_profile(int $userId): array
{
    $blank = [
        'date_of_birth' => '', 'gender' => '', 'blood_group' => '',
        'home_address' => '', 'emergency_contact_name' => '', 'emergency_phone' => '',
        'relative_relation' => '', 'relative_name' => '', 'relative_email' => '', 'relative_phone' => '',
        'highest_qualification' => '', 'previous_employer' => '',
        '_exists' => false,
    ];
    try {
        $s = db()->prepare("SELECT * FROM staff_profiles WHERE user_id = :u");
        $s->execute([':u' => $userId]);
        $row = $s->fetch();
    } catch (Throwable $e) {
        return $blank;   // pre-migration DB
    }
    if (!$row) return $blank;
    // Normalise NULLs to '' for the templates.
    foreach ($blank as $k => $_) {
        if ($k === '_exists') continue;
        $row[$k] = $row[$k] ?? '';
    }
    $row['_exists'] = true;
    return $row;
}

/**
 * Upsert a staff member's personal details. Only the known columns are written;
 * values are validated/normalised against the option maps above. Empty strings
 * are stored as NULL.
 */
function staff_profile_save(int $userId, array $in): void
{
    $nn = static fn($v) => ($v === '' || $v === null) ? null : $v;

    $gender = in_array($in['gender'] ?? '', array_keys(staff_genders()), true) ? $in['gender'] : null;
    $blood  = in_array($in['blood_group'] ?? '', array_keys(staff_blood_groups()), true) ? $in['blood_group'] : null;
    $rel    = in_array($in['relative_relation'] ?? '', array_keys(staff_relations()), true) ? $in['relative_relation'] : null;

    $dob = null;
    if (!empty($in['date_of_birth']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date_of_birth'])) {
        $dob = $in['date_of_birth'];
    }

    $params = [
        ':u'   => $userId,
        ':dob' => $dob,
        ':gen' => $gender,
        ':bg'  => $blood,
        ':addr'=> $nn(trim((string)($in['home_address'] ?? ''))),
        ':ecn' => $nn(trim((string)($in['emergency_contact_name'] ?? ''))),
        ':ep'  => $nn(trim((string)($in['emergency_phone'] ?? ''))),
        ':rr'  => $rel,
        ':rn'  => $nn(trim((string)($in['relative_name'] ?? ''))),
        ':re'  => $nn(trim((string)($in['relative_email'] ?? ''))),
        ':rp'  => $nn(trim((string)($in['relative_phone'] ?? ''))),
        ':hq'  => $nn(trim((string)($in['highest_qualification'] ?? ''))),
        ':pe'  => $nn(trim((string)($in['previous_employer'] ?? ''))),
    ];

    db()->prepare("
        INSERT INTO staff_profiles
            (user_id, date_of_birth, gender, blood_group, home_address,
             emergency_contact_name, emergency_phone, relative_relation,
             relative_name, relative_email, relative_phone,
             highest_qualification, previous_employer)
        VALUES
            (:u, :dob, :gen, :bg, :addr, :ecn, :ep, :rr, :rn, :re, :rp, :hq, :pe)
        ON DUPLICATE KEY UPDATE
            date_of_birth = VALUES(date_of_birth),
            gender = VALUES(gender),
            blood_group = VALUES(blood_group),
            home_address = VALUES(home_address),
            emergency_contact_name = VALUES(emergency_contact_name),
            emergency_phone = VALUES(emergency_phone),
            relative_relation = VALUES(relative_relation),
            relative_name = VALUES(relative_name),
            relative_email = VALUES(relative_email),
            relative_phone = VALUES(relative_phone),
            highest_qualification = VALUES(highest_qualification),
            previous_employer = VALUES(previous_employer)
    ")->execute($params);
}

const STAFF_DOC_MAX_BYTES  = 8 * 1024 * 1024; // 8 MB
const STAFF_DOC_MIME_ALLOW = [
    'application/pdf'                                                            => 'pdf',
    'application/msword'                                                         => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
    'image/jpeg'                                                                 => 'jpg',
    'image/png'                                                                  => 'png',
];

function staff_docs_dir(int $userId): string
{
    $base = realpath(__DIR__ . '/..') . '/uploads/staff_docs';
    if (!is_dir($base)) @mkdir($base, 0755, true);
    $dir = "$base/$userId";
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Mirrors recruit_save_uploaded_attachment — see includes/recruitment.php. */
function staff_save_uploaded_document(int $userId, array $file, int $byUserId, string $kind = 'other'): int
{
    if (!array_key_exists($kind, staff_doc_kinds())) $kind = 'other';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('upload error ' . ($file['error'] ?? '?'));
    }
    if ((int)($file['size'] ?? 0) > STAFF_DOC_MAX_BYTES) {
        throw new RuntimeException('file too large (8 MB max)');
    }
    $mime = sniff_mime_type($file['tmp_name']);
    if ($mime === null || !isset(STAFF_DOC_MIME_ALLOW[$mime])) {
        throw new RuntimeException('file type not allowed');
    }
    $ext    = STAFF_DOC_MIME_ALLOW[$mime];
    $dir    = staff_docs_dir($userId);
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], "$dir/$stored")) {
        throw new RuntimeException('failed to move uploaded file');
    }
    $stmt = db()->prepare("
        INSERT INTO staff_documents
            (user_id, kind, original_name, stored_name, mime_type, size_bytes, uploaded_by)
        VALUES (:u, :k, :o, :s, :m, :z, :b)
    ");
    $stmt->execute([
        ':u' => $userId,
        ':k' => $kind,
        ':o' => substr((string)($file['name'] ?? 'file'), 0, 255),
        ':s' => $stored,
        ':m' => $mime,
        ':z' => (int)($file['size'] ?? 0),
        ':b' => $byUserId,
    ]);
    return (int)db()->lastInsertId();
}

// ---- Messages -----------------------------------------------------------

function staff_message_categories(): array
{
    return [
        'suggestion'   => 'Suggestion',
        'concern'      => 'Concern',
        'request'      => 'Request',
        'appreciation' => 'Appreciation',
        'other'        => 'Other',
    ];
}

function staff_message_statuses(): array
{
    return [
        'open'         => 'Open',
        'acknowledged' => 'Acknowledged',
        'resolved'     => 'Resolved',
        'archived'     => 'Archived',
    ];
}

// ---- Access guards ------------------------------------------------------

/**
 * True if $user can act on records for $targetUserId. Admin → always.
 * Otherwise: only when looking at their own record.
 */
function staff_can_view(array $user, int $targetUserId): bool
{
    if (($user['role'] ?? '') === 'admin') return true;
    return (int)$user['id'] === $targetUserId;
}

function staff_is_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

// ---- Payroll ------------------------------------------------------------

/** Earnings component keys → labels (order = payslip display order). */
function staff_pay_earnings(): array
{
    return [
        'basic'             => 'Basic',
        'hra'               => 'HRA',
        'conveyance'        => 'Conveyance',
        'special_allowance' => 'Special allowance',
        'other_earning'     => 'Other earning',
    ];
}

/** Deduction component keys → labels. */
function staff_pay_deductions(): array
{
    return [
        'pf'               => 'Provident Fund (PF)',
        'esi'              => 'ESI',
        'professional_tax' => 'Professional tax',
        'tds'              => 'TDS',
        'other_deduction'  => 'Other deduction',
    ];
}

/**
 * The pay structure in effect for $userId on $onDate (Y-m-d). Returns the
 * row with the latest effective_from on/before that date, or null if none.
 */
function staff_current_pay(int $userId, string $onDate): ?array
{
    $stmt = db()->prepare("
        SELECT * FROM staff_pay
        WHERE user_id = :u AND effective_from <= :d
        ORDER BY effective_from DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([':u' => $userId, ':d' => $onDate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Full pay history for a staff member, newest first. */
function staff_pay_history(int $userId): array
{
    $stmt = db()->prepare("SELECT * FROM staff_pay WHERE user_id = :u ORDER BY effective_from DESC, id DESC");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}

/** Gross of a pay row (sum of earnings). */
function staff_pay_gross(array $pay): float
{
    $g = 0.0;
    foreach (staff_pay_earnings() as $k => $_) $g += (float)($pay[$k] ?? 0);
    return $g;
}

/** Total fixed deductions of a pay row. */
function staff_pay_total_deductions(array $pay): float
{
    $d = 0.0;
    foreach (staff_pay_deductions() as $k => $_) $d += (float)($pay[$k] ?? 0);
    return $d;
}

/**
 * Hours worked in a month, summed from check_in/check_out on staff_attendance.
 * Rows missing either clock time contribute 0. Returns ['hours'=>float,'days'=>int].
 */
function staff_hours_summary(int $userId, int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));
    $stmt  = db()->prepare("
        SELECT
            COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(check_out, check_in))), 0) AS secs,
            SUM(check_in IS NOT NULL AND check_out IS NOT NULL)          AS days
        FROM staff_attendance
        WHERE user_id = :u AND att_date BETWEEN :s AND :e
          AND check_in IS NOT NULL AND check_out IS NOT NULL
          AND check_out >= check_in
    ");
    $stmt->execute([':u' => $userId, ':s' => $start, ':e' => $end]);
    $r = $stmt->fetch();
    return [
        'hours' => round(((int)($r['secs'] ?? 0)) / 3600, 2),
        'days'  => (int)($r['days'] ?? 0),
    ];
}

/**
 * Compute a draft payslip for (user, year, month) from the pay structure +
 * attendance. Returns the full computed structure WITHOUT saving. Admin can
 * tweak working_days / lop_days before issuing.
 */
function staff_payslip_draft(int $userId, int $year, int $month): array
{
    $periodEnd = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $pay       = staff_current_pay($userId, $periodEnd);
    $att       = staff_attendance_summary($userId, $year, $month);
    $hours     = staff_hours_summary($userId, $year, $month);

    $daysInMonth = (int)date('t', strtotime($periodEnd));
    $basis       = $pay ? (int)$pay['payable_days_basis'] : 30;
    if ($basis <= 0) $basis = $daysInMonth;

    // Paid = present + late + wfh + paid leave + holidays. LOP = absent.
    $paidLeave = (int)($att['leave'] ?? 0);
    $present   = (int)($att['present'] ?? 0) + (int)($att['late'] ?? 0) + (int)($att['wfh'] ?? 0);
    $lopDays   = (int)($att['absent'] ?? 0);

    $earnings = [];
    foreach (staff_pay_earnings() as $k => $_) $earnings[$k] = $pay ? (float)$pay[$k] : 0.0;
    $deductions = [];
    foreach (staff_pay_deductions() as $k => $_) $deductions[$k] = $pay ? (float)$pay[$k] : 0.0;

    $gross   = array_sum($earnings);
    $perDay  = $basis > 0 ? $gross / $basis : 0.0;
    $lopAmt  = round($perDay * $lopDays, 2);
    $totDed  = array_sum($deductions);
    $net     = round($gross - $lopAmt - $totDed, 2);

    return [
        'has_pay'          => $pay !== null,
        'pay'              => $pay,
        'working_days'     => $basis,
        'present_days'     => $present,
        'paid_leave_days'  => $paidLeave,
        'lop_days'         => $lopDays,
        'hours_worked'     => $hours['hours'],
        'earnings'         => $earnings,
        'deductions'       => $deductions,
        'gross_earnings'   => round($gross, 2),
        'lop_amount'       => $lopAmt,
        'total_deductions' => round($totDed, 2),
        'net_pay'          => $net,
    ];
}

/** An already-issued payslip for (user, year, month), or null. */
function staff_payslip(int $userId, int $year, int $month): ?array
{
    $stmt = db()->prepare("
        SELECT * FROM staff_payslips
        WHERE user_id = :u AND period_year = :y AND period_month = :m
    ");
    $stmt->execute([':u' => $userId, ':y' => $year, ':m' => $month]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function staff_money(float $v): string
{
    return "\u{20B9}" . number_format($v, 2);
}
