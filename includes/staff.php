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
    // The role/module test MUST stay parenthesised. Without the brackets,
    // appending "AND active = 1" binds only to the FIND_IN_SET branch —
    // SQL's AND binds tighter than OR — so the query read
    //     role IN (...) OR (module AND active)
    // and returned every inactive admin, teacher and non-teaching user even
    // when $activeOnly was set. That leaked deactivated people into the staff
    // attendance roster, the leave picker, the daycare sheet and payroll.
    $sql = "
        SELECT id, name, role, active, modules
        FROM users
        WHERE (role IN ('admin','teacher','non_teaching')
               OR FIND_IN_SET('staff', modules) > 0)
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

/** Accept YYYY-MM-DD or fall back. */
function staff_ymd(?string $s, string $fallback): string
{
    $s = (string)$s;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : $fallback;
}

/**
 * Clamp a custom from/to range. Swaps inverted dates. Caps at 366 days so a
 * mistyped year cannot dump every historic row onto one page.
 */
function staff_timesheet_range(?string $from, ?string $to): array
{
    $monthStart = date('Y-m-01');
    $monthEnd   = date('Y-m-t');
    $from = staff_ymd($from, $monthStart);
    $to   = staff_ymd($to, $monthEnd);
    if ($to < $from) {
        $tmp = $from; $from = $to; $to = $tmp;
    }
    $start = new DateTimeImmutable($from);
    $end   = new DateTimeImmutable($to);
    if ($start->diff($end)->days > 366) {
        $to = $start->modify('+366 days')->format('Y-m-d');
    }
    return [$from, $to];
}

/**
 * Hours between two clock times on the same day. Null if either is missing
 * or check-out is before check-in (overnight shifts are not modelled).
 */
function staff_day_hours(?string $checkIn, ?string $checkOut): ?float
{
    $in  = staff_time_norm($checkIn);
    $out = staff_time_norm($checkOut);
    if ($in === null || $out === null) return null;
    $a = strtotime('2000-01-01 ' . $in);
    $b = strtotime('2000-01-01 ' . $out);
    if ($a === false || $b === false || $b < $a) return null;
    return round(($b - $a) / 3600, 2);
}

/**
 * Check-in / check-out register for a date range, grouped by staff.
 *
 * Each person has a `days` list (attendance rows plus approved-leave days
 * that have no attendance row) and `totals`. Leave is overlaid from
 * staff_leave_requests so a day is called out even when attendance was never
 * stamped. Pending leave is noted but does not count as on leave.
 *
 * @return list<array{user_id:int,name:string,role:string,days:list<array>,totals:array}>
 */
function staff_timesheet(string $from, string $to, ?int $userId = null): array
{
    [$from, $to] = staff_timesheet_range($from, $to);

    $params = [':s' => $from, ':e' => $to];
    $userSql = '';
    if ($userId !== null && $userId > 0) {
        $userSql = ' AND a.user_id = :u';
        $params[':u'] = $userId;
    }

    $attStmt = db()->prepare("
        SELECT a.user_id, a.att_date, a.status, a.check_in, a.check_out, a.notes,
               u.name, u.role
          FROM staff_attendance a
          JOIN users u ON u.id = a.user_id
         WHERE a.att_date BETWEEN :s AND :e
               $userSql
         ORDER BY u.name, a.att_date
    ");
    $attStmt->execute($params);

    $groups = [];
    foreach ($attStmt->fetchAll() as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($groups[$uid])) {
            $groups[$uid] = [
                'user_id' => $uid,
                'name'    => (string)$r['name'],
                'role'    => (string)$r['role'],
                'days'    => [],
            ];
        }
        $date = (string)$r['att_date'];
        $hours = staff_day_hours($r['check_in'] ?? null, $r['check_out'] ?? null);
        $groups[$uid]['days'][$date] = [
            'date'           => $date,
            'status'         => (string)($r['status'] ?? ''),
            'check_in'       => $r['check_in'] ?? null,
            'check_out'      => $r['check_out'] ?? null,
            'hours'          => $hours,
            'notes'          => $r['notes'] ?? null,
            'on_leave'       => ($r['status'] ?? '') === 'leave',
            'leave_type'     => null,
            'leave_half'     => '',
            'leave_pending'  => false,
        ];
    }

    $leaveParams = [':s' => $from, ':e' => $to];
    $leaveUserSql = '';
    if ($userId !== null && $userId > 0) {
        $leaveUserSql = ' AND l.user_id = :u';
        $leaveParams[':u'] = $userId;
    }
    $leaveStmt = db()->prepare("
        SELECT l.user_id, l.leave_type, l.start_date, l.end_date, l.half_day, l.status,
               u.name, u.role
          FROM staff_leave_requests l
          JOIN users u ON u.id = l.user_id
         WHERE l.status IN ('approved','pending')
           AND l.start_date <= :e AND l.end_date >= :s
               $leaveUserSql
         ORDER BY u.name, l.start_date
    ");
    $leaveStmt->execute($leaveParams);

    $rangeStart = new DateTimeImmutable($from);
    $rangeEnd   = new DateTimeImmutable($to);
    foreach ($leaveStmt->fetchAll() as $lr) {
        $uid = (int)$lr['user_id'];
        if (!isset($groups[$uid])) {
            $groups[$uid] = [
                'user_id' => $uid,
                'name'    => (string)$lr['name'],
                'role'    => (string)$lr['role'],
                'days'    => [],
            ];
        }
        $cur = new DateTimeImmutable((string)$lr['start_date']);
        $end = new DateTimeImmutable((string)$lr['end_date']);
        if ($cur < $rangeStart) $cur = $rangeStart;
        if ($end > $rangeEnd) $end = $rangeEnd;
        $approved = ($lr['status'] ?? '') === 'approved';
        $type     = (string)($lr['leave_type'] ?? '');
        $half     = (string)($lr['half_day'] ?? '');
        while ($cur <= $end) {
            $date = $cur->format('Y-m-d');
            if (!isset($groups[$uid]['days'][$date])) {
                $groups[$uid]['days'][$date] = [
                    'date'          => $date,
                    'status'        => $approved ? 'leave' : '',
                    'check_in'      => null,
                    'check_out'     => null,
                    'hours'         => null,
                    'notes'         => null,
                    'on_leave'      => $approved,
                    'leave_type'    => $approved ? $type : null,
                    'leave_half'    => $approved ? $half : '',
                    'leave_pending' => !$approved,
                ];
            } else {
                $day = &$groups[$uid]['days'][$date];
                if ($approved) {
                    $day['on_leave'] = true;
                    if ($day['status'] === '' || $day['status'] === 'absent') {
                        $day['status'] = 'leave';
                    }
                    $day['leave_type'] = $type;
                    $day['leave_half'] = $half;
                    $day['leave_pending'] = false;
                } elseif (empty($day['on_leave'])) {
                    $day['leave_pending'] = true;
                    if ($day['leave_type'] === null) $day['leave_type'] = $type;
                }
                unset($day);
            }
            $cur = $cur->modify('+1 day');
        }
    }

    $statusKeys = array_keys(staff_attendance_statuses());
    $out = [];
    foreach ($groups as $g) {
        ksort($g['days']);
        $days = array_values($g['days']);
        $totals = array_fill_keys($statusKeys, 0);
        $totals['hours'] = 0.0;
        $totals['clocked_days'] = 0;
        $totals['leave_days'] = 0;
        $totals['rows'] = count($days);
        foreach ($days as $d) {
            $st = (string)($d['status'] ?? '');
            if ($st !== '' && isset($totals[$st])) $totals[$st]++;
            if (!empty($d['on_leave'])) $totals['leave_days']++;
            if ($d['hours'] !== null) {
                $totals['hours'] += (float)$d['hours'];
                $totals['clocked_days']++;
            }
        }
        $totals['hours'] = round($totals['hours'], 2);
        $g['days'] = $days;
        $g['totals'] = $totals;
        $out[] = $g;
    }
    usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
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

// ---- Leave balance: accrual model ---------------------------------------
//
// Leave accrues — one day per completed month by default — carries forward,
// and is drawn down by approved paid leave. Nothing about the balance is
// stored except the events an admin creates:
//
//     balance(as_of) = opening + accrual + adjustments − paid leave taken
//
// Accrual is computed, never written. A monthly row would need a scheduler
// this host cannot be relied on to run, and a month it missed would quietly
// underpay somebody. Counting completed months at read time cannot drift.
//
// The "opening" entry is the important one: it lets an admin say "as of
// today, this person has 7 days" without reconstructing history. The latest
// opening on or before the date being asked about wins, and everything
// before it is ignored.

const STAFF_LEAVE_ACCRUAL_DEFAULT = 1.0;

/** Days earned per completed month. Clamped to something sane. */
function staff_leave_accrual_rate(): float
{
    $v = (float)app_setting('staff_leave_accrual_per_month', (string)STAFF_LEAVE_ACCRUAL_DEFAULT);
    if ($v < 0) $v = 0.0;
    if ($v > 31) $v = 31.0;
    return $v;
}

/** Leave types that draw on the balance. 'unpaid' deliberately does not. */
function staff_leave_paid_types(): array
{
    return ['casual', 'sick', 'earned', 'other'];
}

/** Record an opening balance or an adjustment. */
function staff_leave_ledger_add(int $userId, string $date, string $kind,
                                float $days, string $note, ?int $by): void
{
    if (!in_array($kind, ['opening', 'adjust'], true)) $kind = 'adjust';
    db()->prepare(
        "INSERT INTO staff_leave_ledger (user_id, entry_date, kind, days, note, created_by)
         VALUES (:u, :d, :k, :n, :note, :by)"
    )->execute([
        ':u' => $userId, ':d' => $date, ':k' => $kind,
        ':n' => round($days, 2), ':note' => mb_substr(trim($note), 0, 255) ?: null, ':by' => $by,
    ]);
}

/** The opening balance in force for a date, or null if none has been set. */
function staff_leave_opening(int $userId, string $asOf): ?array
{
    $s = db()->prepare(
        "SELECT * FROM staff_leave_ledger
          WHERE user_id = :u AND kind = 'opening' AND entry_date <= :d
          ORDER BY entry_date DESC, id DESC LIMIT 1"
    );
    $s->execute([':u' => $userId, ':d' => $asOf]);
    return $s->fetch() ?: null;
}

/**
 * Completed calendar months between two dates.
 *
 * "Completed" means the day-of-month has come round again: 15 Jan → 14 Feb is
 * zero months, 15 Jan → 15 Feb is one. Accrual is credited at the end of a
 * month of service, not the start, so nobody is paid for time not yet served.
 */
function staff_leave_months_completed(string $from, string $to): int
{
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    if ($b <= $a) return 0;
    return (int)$a->diff($b)->m + ((int)$a->diff($b)->y * 12);
}

/** Approved paid leave taken in a window (excludes unpaid, which never draws). */
function staff_leave_taken(int $userId, string $from, string $to): float
{
    $types = staff_leave_paid_types();
    $in    = implode(',', array_map(fn($i) => ':t' . $i, array_keys($types)));
    $p     = [':u' => $userId, ':f' => $from, ':e' => $to];
    foreach ($types as $i => $t) $p[':t' . $i] = $t;

    $s = db()->prepare(
        "SELECT COALESCE(SUM(days_count - lop_days), 0)
           FROM staff_leave_requests
          WHERE user_id = :u AND status = 'approved'
            AND leave_type IN ($in)
            AND start_date >= :f AND start_date <= :e"
    );
    $s->execute($p);
    return (float)$s->fetchColumn();
}

/** Manual adjustments in a window. */
function staff_leave_adjustments(int $userId, string $from, string $to): float
{
    $s = db()->prepare(
        "SELECT COALESCE(SUM(days), 0) FROM staff_leave_ledger
          WHERE user_id = :u AND kind = 'adjust' AND entry_date >= :f AND entry_date <= :e"
    );
    $s->execute([':u' => $userId, ':f' => $from, ':e' => $to]);
    return (float)$s->fetchColumn();
}

/**
 * The balance as at a date, with the working shown — the page needs to be
 * able to explain the number, not just print it.
 */
function staff_leave_balance_at(int $userId, ?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');
    $open = staff_leave_opening($userId, $asOf);

    // With no opening entry, accrual has to start somewhere. The schema has
    // no staff joining date — only students have one — so the account's
    // creation date is the closest thing we know, falling back to the start
    // of the current year. Never the epoch, which would hand somebody a
    // decade of leave on their first day.
    //
    // This is a guess, and the page says so. Setting an opening balance
    // replaces it with a real figure, which is the intended path.
    if ($open) {
        $since   = (string)$open['entry_date'];
        $opening = (float)$open['days'];
    } else {
        $s = db()->prepare("SELECT DATE(created_at) AS d FROM users WHERE id = :i");
        $s->execute([':i' => $userId]);
        $created = (string)($s->fetchColumn() ?: '');
        $since   = ($created !== '' && $created > '2000-01-01') ? $created : date('Y-01-01');
        $opening = 0.0;
    }
    if ($since > $asOf) { $since = $asOf; }

    $rate    = staff_leave_accrual_rate();
    $months  = staff_leave_months_completed($since, $asOf);
    $accrued = round($months * $rate, 2);
    $adjust  = staff_leave_adjustments($userId, $since, $asOf);
    $taken   = staff_leave_taken($userId, $since, $asOf);

    return [
        'as_of'     => $asOf,
        'since'     => $since,
        'from_open' => $open !== null,
        'opening'   => round($opening, 2),
        'rate'      => $rate,
        'months'    => $months,
        'accrued'   => $accrued,
        'adjust'    => round($adjust, 2),
        'taken'     => round($taken, 2),
        'balance'   => round($opening + $accrued + $adjust - $taken, 2),
    ];
}

/**
 * Split a month's approved leave into paid days and LOP days.
 *
 * Requests are walked oldest-first and drawn against the balance as it stood
 * at the start of the month; once it runs out the rest is LOP. Chronological
 * order matters — whoever booked first should be the one who is covered.
 *
 * Accrual for the month itself is not credited mid-month, so a person cannot
 * spend a day they have not finished earning.
 */
function staff_leave_month_split(int $userId, int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));
    $prev  = date('Y-m-d', strtotime($start . ' -1 day'));

    $bal   = staff_leave_balance_at($userId, $prev);
    $avail = max(0.0, (float)$bal['balance']);

    $s = db()->prepare(
        "SELECT * FROM staff_leave_requests
          WHERE user_id = :u AND status = 'approved'
            AND start_date >= :s AND start_date <= :e
          ORDER BY start_date, id"
    );
    $s->execute([':u' => $userId, ':s' => $start, ':e' => $end]);

    $paid = 0.0; $lop = 0.0; $unpaid = 0.0; $lines = [];
    foreach ($s->fetchAll() as $r) {
        $days = (float)$r['days_count'];
        if (!in_array($r['leave_type'], staff_leave_paid_types(), true)) {
            $unpaid += $days;                      // unpaid leave is LOP by definition
            $lop    += $days;
            $thisPaid = 0.0; $thisLop = $days;
        } else {
            $thisPaid = min($days, $avail);
            $thisLop  = round($days - $thisPaid, 2);
            $avail    = round($avail - $thisPaid, 2);
            $paid    += $thisPaid;
            $lop     += $thisLop;
        }
        $lines[] = [
            'id'    => (int)$r['id'],
            'type'  => (string)$r['leave_type'],
            'from'  => (string)$r['start_date'],
            'to'    => (string)$r['end_date'],
            'days'  => $days,
            'paid'  => round($thisPaid, 2),
            'lop'   => round($thisLop, 2),
        ];
    }

    return [
        'opening_balance' => round((float)$bal['balance'], 2),
        'paid_days'       => round($paid, 2),
        'lop_days'        => round($lop, 2),
        'unpaid_days'     => round($unpaid, 2),
        'closing_balance' => round($avail, 2),
        'lines'           => $lines,
    ];
}

// ---- Leave notifications -------------------------------------------------
//
// A leave request is a conversation between two people who are rarely at a
// screen at the same time. Without this, an admin has to remember to look at
// the page, and a member of staff has to keep asking whether they can book
// the train.

/** "2 days" / "half a day" / "1 day" — for a sentence, not a table cell. */
function staff_leave_days_phrase(float $days): string
{
    if (abs($days - 0.5) < 0.001) return 'half a day';
    $n = rtrim(rtrim(number_format($days, 1), '0'), '.');
    return $n . ' day' . (abs($days - 1.0) < 0.001 ? '' : 's');
}

/** Tell the admins somebody is waiting on them. */
function staff_leave_notify_applied(array $req, string $applicantName): void
{
    if (!function_exists('notify_admins')) return;
    $type = staff_leave_types()[$req['leave_type']] ?? (string)$req['leave_type'];
    $when = (string)$req['start_date'] === (string)$req['end_date']
          ? date('j M', strtotime((string)$req['start_date']))
          : date('j M', strtotime((string)$req['start_date'])) . ' – '
            . date('j M', strtotime((string)$req['end_date']));

    notify_admins(
        'staff',
        'leave.applied',
        $applicantName . ' applied for leave',
        $type . ' leave, ' . staff_leave_days_phrase((float)$req['days_count'])
            . ', ' . $when . '.'
            . (trim((string)($req['reason'] ?? '')) !== ''
               ? ' Reason: ' . trim((string)$req['reason']) : ''),
        '/staff/leave.php?user_id=' . (int)$req['user_id']
    );
}

/**
 * Tell the applicant what was decided.
 *
 * Sent as 'system', not 'staff': _notify_category_enabled() always allows
 * system, and this one must not be silenceable. A rejection changes whether
 * somebody is expected at work and whether they are paid for the day.
 */
function staff_leave_notify_decided(array $req, string $decision, ?string $note, float $lopDays): void
{
    if (!function_exists('notify')) return;
    $type = staff_leave_types()[$req['leave_type']] ?? (string)$req['leave_type'];
    $when = (string)$req['start_date'] === (string)$req['end_date']
          ? date('j M', strtotime((string)$req['start_date']))
          : date('j M', strtotime((string)$req['start_date'])) . ' – '
            . date('j M', strtotime((string)$req['end_date']));

    $body = $type . ' leave, ' . staff_leave_days_phrase((float)$req['days_count']) . ', ' . $when . '.';
    if ($decision === 'approved' && $lopDays > 0) {
        // Say it here rather than let it turn up as a smaller number on the
        // payslip three weeks later.
        $body .= ' ' . staff_leave_days_phrase($lopDays)
               . ' of this is loss of pay — your balance did not cover it.';
    }
    if (trim((string)$note) !== '') $body .= ' Note: ' . trim((string)$note);

    notify(
        (int)$req['user_id'],
        'system',
        'leave.' . $decision,
        'Your leave was ' . $decision,
        $body,
        '/staff/leave.php'
    );
}

/** Tell the admins a pending request has gone away, so they stop looking. */
function staff_leave_notify_cancelled(array $req, string $applicantName): void
{
    if (!function_exists('notify_admins')) return;
    notify_admins(
        'staff',
        'leave.cancelled',
        $applicantName . ' cancelled a leave request',
        staff_leave_days_phrase((float)$req['days_count']) . ' from '
            . date('j M', strtotime((string)$req['start_date'])) . ' — no longer needs approving.',
        '/staff/leave.php'
    );
}

/**
 * Recompute lop_days on every approved request in a month and write it back.
 *
 * A single request's LOP cannot be decided on its own: it depends on what
 * else was approved that month and in what order. Deciding it at approval
 * time from the balance on that day gives a different answer from the one
 * payroll reaches — the admin would be shown one figure and the payslip would
 * use another.
 *
 * So approval stores an estimate, and this immediately corrects every request
 * in the month against the same allocation payroll uses. Call it after any
 * change to a month's approved leave.
 */
function staff_leave_resync_month(int $userId, int $year, int $month): void
{
    $split = staff_leave_month_split($userId, $year, $month);
    if (!$split['lines']) return;
    $upd = db()->prepare("UPDATE staff_leave_requests SET lop_days = :l WHERE id = :i");
    foreach ($split['lines'] as $line) {
        $upd->execute([':l' => $line['lop'], ':i' => $line['id']]);
    }
}

/**
 * Write 'leave' attendance rows across an approved request's dates.
 *
 * Days already marked are left alone: somebody who actually came in and was
 * marked present should stay present, and overwriting a real record with an
 * inferred one is how attendance data stops being trustworthy. Returns the
 * number of days newly marked.
 */
function staff_leave_mark_attendance(array $req, ?int $markedBy): int
{
    $cur = new DateTimeImmutable((string)$req['start_date']);
    $end = new DateTimeImmutable((string)$req['end_date']);
    $n   = 0;
    $ins = db()->prepare(
        "INSERT IGNORE INTO staff_attendance (user_id, att_date, status, notes, marked_by)
         VALUES (:u, :d, 'leave', :n, :by)"
    );
    while ($cur <= $end) {
        $ins->execute([
            ':u'  => (int)$req['user_id'],
            ':d'  => $cur->format('Y-m-d'),
            ':n'  => 'Approved ' . (staff_leave_types()[$req['leave_type']] ?? 'leave') . ' leave',
            ':by' => $markedBy,
        ]);
        $n += $ins->rowCount();
        $cur = $cur->modify('+1 day');
    }
    return $n;
}

/**
 * Undo the above when a request stops being approved. Only rows still marked
 * 'leave' are removed — if somebody has since been marked present, that is a
 * real observation and stays.
 */
function staff_leave_unmark_attendance(array $req): int
{
    $s = db()->prepare(
        "DELETE FROM staff_attendance
          WHERE user_id = :u AND status = 'leave'
            AND att_date BETWEEN :a AND :b"
    );
    $s->execute([
        ':u' => (int)$req['user_id'],
        ':a' => (string)$req['start_date'],
        ':b' => (string)$req['end_date'],
    ]);
    return $s->rowCount();
}

/**
 * LEGACY — the fixed per-year, per-type allowance the accrual model replaces.
 * Kept so historical allowance rows can still be displayed; not used to
 * decide anything. staff_leave_balance_at() is the live balance.
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

/**
 * Days between two dates inclusive.
 *
 * A half day is only meaningful on a single date — "half a day off" across a
 * week is not a thing anyone means — so $half is ignored unless start and end
 * are the same day.
 */
function staff_leave_days(string $startDate, string $endDate, string $half = ''): float
{
    $a = new DateTimeImmutable($startDate);
    $b = new DateTimeImmutable($endDate);
    if ($b < $a) return 0.0;
    if ($half !== '' && $startDate === $endDate) return 0.5;
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
        // Admin-only (see staff_shift_save) — read here, never written by
        // staff_profile_save.
        'work_start' => '', 'work_end' => '',
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

// ---- Working hours & lateness -------------------------------------------
//
// The school runs shifts, so "late" is meaningless as a single clock time:
// 09:10 is on time for a 09:30 start and 40 minutes late for an 08:30 one.
// Each staff member gets their own work_start / work_end (staff_profiles,
// migrate_053) and lateness is measured against their own start plus a grace
// period — 5 minutes by default, i.e. a 09:00 start starts counting late at
// 09:05.
//
// Deliberate rule: a staff member with no shift on file is NEVER marked late.
// A blank field must not invent a disciplinary record for someone.

/** Default grace, used when the setting row is missing or nonsense. */
const STAFF_LATE_GRACE_DEFAULT = 5;

/** Grace period in minutes, from app_settings. Clamped to 0–120. */
function staff_late_grace_minutes(): int
{
    $raw = function_exists('app_setting')
        ? app_setting('staff_late_grace_minutes', (string)STAFF_LATE_GRACE_DEFAULT)
        : (string)STAFF_LATE_GRACE_DEFAULT;
    if ($raw === null || !preg_match('/^\d+$/', trim((string)$raw))) {
        return STAFF_LATE_GRACE_DEFAULT;
    }
    return max(0, min(120, (int)$raw));
}

/**
 * Normalise a time from a form or the database to 'HH:MM:SS'.
 * Accepts 'HH:MM' and 'HH:MM:SS'; returns null for anything else (including
 * '' and null), so callers can treat "no shift" and "junk input" the same.
 */
function staff_time_norm($t): ?string
{
    $t = trim((string)$t);
    if ($t === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $t, $m)) return null;
    return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
}

/** 'HH:MM:SS' → '9:00 AM'. '' when unset. */
function staff_time_label(?string $t): string
{
    $t = staff_time_norm($t);
    return $t === null ? '' : date('g:i A', strtotime('2000-01-01 ' . $t));
}

/**
 * The clock time from which arrivals count as late, for a given start.
 * Returns null when there's no start time.
 *
 * A start late enough that start+grace would cross midnight is clamped to
 * 23:59:59 rather than wrapping — a wrapped cutoff would compare as *earlier*
 * than every check-in and mark a whole night shift late.
 */
function staff_late_cutoff(?string $workStart, ?int $graceMinutes = null): ?string
{
    $start = staff_time_norm($workStart);
    if ($start === null) return null;
    $grace = $graceMinutes ?? staff_late_grace_minutes();
    $secs  = (int)substr($start, 0, 2) * 3600
           + (int)substr($start, 3, 2) * 60
           + (int)substr($start, 6, 2)
           + max(0, $grace) * 60;
    if ($secs > 86399) $secs = 86399;
    return sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
}

/**
 * The attendance status an arrival at $checkIn earns: 'late' or 'present'.
 * 'present' whenever no shift is on file, or the time is unreadable.
 */
function staff_arrival_status(?string $workStart, ?string $checkIn, ?int $graceMinutes = null): string
{
    $cutoff = staff_late_cutoff($workStart, $graceMinutes);
    $at     = staff_time_norm($checkIn);
    if ($cutoff === null || $at === null) return 'present';
    return $at > $cutoff ? 'late' : 'present';
}

/** Shift for one user: ['start' => 'HH:MM:SS'|null, 'end' => 'HH:MM:SS'|null]. */
function staff_shift(int $userId): array
{
    try {
        $s = db()->prepare("SELECT work_start, work_end FROM staff_profiles WHERE user_id = :u");
        $s->execute([':u' => $userId]);
        $row = $s->fetch();
    } catch (Throwable $e) {
        return ['start' => null, 'end' => null];   // pre-migration DB
    }
    if (!$row) return ['start' => null, 'end' => null];
    return [
        'start' => staff_time_norm($row['work_start'] ?? null),
        'end'   => staff_time_norm($row['work_end'] ?? null),
    ];
}

/** Every defined shift, keyed by user_id. One query for roster-wide screens. */
function staff_shift_map(): array
{
    $out = [];
    try {
        $rows = db()->query("
            SELECT user_id, work_start, work_end FROM staff_profiles
            WHERE work_start IS NOT NULL OR work_end IS NOT NULL
        ")->fetchAll();
    } catch (Throwable $e) {
        return $out;
    }
    foreach ($rows as $r) {
        $out[(int)$r['user_id']] = [
            'start' => staff_time_norm($r['work_start'] ?? null),
            'end'   => staff_time_norm($r['work_end'] ?? null),
        ];
    }
    return $out;
}

/**
 * Set (or clear) one staff member's shift. Admin-only by convention — this is
 * kept out of staff_profile_save() so the self-service profile form and the
 * public application form can never touch it.
 */
function staff_shift_save(int $userId, $start, $end): void
{
    db()->prepare("
        INSERT INTO staff_profiles (user_id, work_start, work_end)
        VALUES (:u, :ws, :we)
        ON DUPLICATE KEY UPDATE work_start = VALUES(work_start), work_end = VALUES(work_end)
    ")->execute([
        ':u'  => $userId,
        ':ws' => staff_time_norm($start),
        ':we' => staff_time_norm($end),
    ]);
}

/** '9:00 AM – 5:00 PM', or '' when no shift is defined. */
function staff_shift_label(array $shift): string
{
    $s = staff_time_label($shift['start'] ?? null);
    $e = staff_time_label($shift['end'] ?? null);
    if ($s === '' && $e === '') return '';
    if ($s === '') return 'until ' . $e;
    if ($e === '') return 'from ' . $s;
    return $s . ' – ' . $e;
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

/**
 * Anyone who belongs on the staff attendance sheet: admin / teacher /
 * non-teaching roles, or a user who was given the Staff module in Admin.
 */
function staff_is_on_roster(array $user): bool
{
    $role = (string)($user['role'] ?? '');
    if (in_array($role, ['admin', 'teacher', 'non_teaching'], true)) return true;
    $mods = $user['modules'] ?? [];
    if (is_string($mods)) $mods = array_filter(explode(',', $mods));
    return in_array('staff', $mods, true);
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

    // LOP has two sources and they must not double-count.
    //
    //   · Leave — split into paid and LOP by the balance, from the leave
    //     requests themselves rather than from attendance. Attendance counts
    //     whole days; a half-day would be lost, and unpaid leave marked
    //     'leave' would otherwise be paid.
    //   · Absence — days marked absent with no approved leave behind them.
    //
    // Approving leave marks those days 'leave', not 'absent', so the two
    // sources cannot overlap.
    $split     = staff_leave_month_split($userId, $year, $month);
    $paidLeave = (float)$split['paid_days'];
    $lopLeave  = (float)$split['lop_days'];
    $lopAbsent = (float)($att['absent'] ?? 0);

    $present   = (int)($att['present'] ?? 0) + (int)($att['late'] ?? 0) + (int)($att['wfh'] ?? 0);
    $lopDays   = round($lopLeave + $lopAbsent, 2);

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
        'lop_leave_days'   => round($lopLeave, 2),
        'lop_absent_days'  => round($lopAbsent, 2),
        'leave_split'      => $split,
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
