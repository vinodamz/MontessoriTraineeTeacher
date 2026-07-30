<?php
/**
 * includes/daycare.php — daycare attendance domain helpers.
 *
 * The daycare module is one screen: every daycare child and every staff member,
 * each with a check-in / check-out button and an optional comment. It's operated
 * by a "track" user who holds only the `daycare` module, so it has to be
 * self-contained and hard to get wrong.
 *
 * Storage is deliberately the existing tables rather than a daycare-specific
 * one, so a daycare child's day shows up in the normal student attendance views
 * and reports:
 *   children → attendance        (check_in / check_out added by migrate_051)
 *   staff    → staff_attendance  (already had check_in / check_out)
 *
 * Times are stamped server-side; the UI never sends a time.
 */
declare(strict_types=1);

/** The grade name treated as daycare. Matches the seeded grade_levels row. */
const DAYCARE_GRADE = 'Daycare';

/** Children currently in the daycare grade. */
function daycare_children(): array
{
    try {
        $s = db()->prepare("
            SELECT id, first_name, last_name, grade, section
            FROM students
            WHERE grade = :g
              AND COALESCE(is_active, 1) = 1
              AND COALESCE(enrollment_status, 'enrolled') IN ('enrolled','promoted')
            ORDER BY first_name, last_name
        ");
        $s->execute([':g' => DAYCARE_GRADE]);
        return $s->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Active staff, for the staff half of the screen. */
function daycare_staff(): array
{
    try {
        if (function_exists('staff_roster')) return staff_roster(true);
        return db()->query("
            SELECT id, name, role, active FROM users
            WHERE active = 1 ORDER BY name
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Today's (or $date's) child rows keyed by student_id. */
function daycare_child_attendance(string $date): array
{
    $out = [];
    try {
        $s = db()->prepare("
            SELECT student_id, status, check_in, check_out, notes
            FROM attendance WHERE attendance_date = :d
        ");
        $s->execute([':d' => $date]);
        foreach ($s->fetchAll() as $r) $out[(int)$r['student_id']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** Staff rows for $date keyed by user_id. */
function daycare_staff_attendance(string $date): array
{
    $out = [];
    try {
        $s = db()->prepare("
            SELECT user_id, status, check_in, check_out, notes
            FROM staff_attendance WHERE att_date = :d
        ");
        $s->execute([':d' => $date]);
        foreach ($s->fetchAll() as $r) $out[(int)$r['user_id']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Stamp a child's check-in or check-out for $date.
 *
 * Uses INSERT … ON DUPLICATE KEY UPDATE against the (student_id, attendance_date)
 * unique key, so two taps can't create two rows. Only the field being set is
 * written — checking out never disturbs the check-in time.
 *
 * $which: 'in' | 'out'. Returns the stamped 'H:i:s'.
 */
function daycare_mark_child(int $studentId, string $date, string $which, int $byUserId): string
{
    $now = date('H:i:s');
    if ($which === 'in') {
        db()->prepare("
            INSERT INTO attendance
                (student_id, attendance_date, status, check_in, marked_by_user_id)
            VALUES (:s, :d, 'present', :t, :by)
            ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), status = 'present'
        ")->execute([':s' => $studentId, ':d' => $date, ':t' => $now, ':by' => $byUserId]);
    } else {
        // Checking out for a child with no row yet still records the day as
        // present — a missed check-in shouldn't lose the fact they were here.
        db()->prepare("
            INSERT INTO attendance
                (student_id, attendance_date, status, check_out, marked_by_user_id)
            VALUES (:s, :d, 'present', :t, :by)
            ON DUPLICATE KEY UPDATE check_out = VALUES(check_out)
        ")->execute([':s' => $studentId, ':d' => $date, ':t' => $now, ':by' => $byUserId]);
    }
    return $now;
}

/** Same for a staff member, against staff_attendance. */
function daycare_mark_staff(int $userId, string $date, string $which, int $byUserId): string
{
    $now = date('H:i:s');
    if ($which === 'in') {
        db()->prepare("
            INSERT INTO staff_attendance
                (user_id, att_date, status, check_in, marked_by)
            VALUES (:u, :d, 'present', :t, :by)
            ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), status = 'present'
        ")->execute([':u' => $userId, ':d' => $date, ':t' => $now, ':by' => $byUserId]);
    } else {
        db()->prepare("
            INSERT INTO staff_attendance
                (user_id, att_date, status, check_out, marked_by)
            VALUES (:u, :d, 'present', :t, :by)
            ON DUPLICATE KEY UPDATE check_out = VALUES(check_out)
        ")->execute([':u' => $userId, ':d' => $date, ':t' => $now, ':by' => $byUserId]);
    }
    return $now;
}

/**
 * Clear a stamped time. A tap on the wrong row is the likeliest mistake on this
 * screen and the track user can't reach any other page to fix it, so undo has
 * to exist — but only for the current day, so history can't be quietly rewritten.
 */
function daycare_undo(string $kind, int $id, string $date, string $which): bool
{
    if ($date !== date('Y-m-d')) return false;
    $col = $which === 'in' ? 'check_in' : 'check_out';
    try {
        if ($kind === 'child') {
            db()->prepare("UPDATE attendance SET $col = NULL WHERE student_id = :i AND attendance_date = :d")
                ->execute([':i' => $id, ':d' => $date]);
        } else {
            db()->prepare("UPDATE staff_attendance SET $col = NULL WHERE user_id = :i AND att_date = :d")
                ->execute([':i' => $id, ':d' => $date]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Save (or clear) the optional comment on a row. */
function daycare_save_comment(string $kind, int $id, string $date, string $comment, int $byUserId): void
{
    $comment = trim($comment);
    $val = $comment === '' ? null : mb_substr($comment, 0, 255);
    if ($kind === 'child') {
        db()->prepare("
            INSERT INTO attendance (student_id, attendance_date, status, notes, marked_by_user_id)
            VALUES (:i, :d, 'present', :n, :by)
            ON DUPLICATE KEY UPDATE notes = VALUES(notes)
        ")->execute([':i' => $id, ':d' => $date, ':n' => $val, ':by' => $byUserId]);
    } else {
        db()->prepare("
            INSERT INTO staff_attendance (user_id, att_date, status, notes, marked_by)
            VALUES (:i, :d, 'present', :n, :by)
            ON DUPLICATE KEY UPDATE notes = VALUES(notes)
        ")->execute([':i' => $id, ':d' => $date, ':n' => $val, ':by' => $byUserId]);
    }
}

/** 'HH:MM:SS' → 'HH:MM', or '' when unset. */
function daycare_time(?string $t): string
{
    $t = (string)$t;
    return $t === '' ? '' : substr($t, 0, 5);
}

// ---------------------------------------------------------------------------
// Summary over a date range
// ---------------------------------------------------------------------------
//
// The two tables don't use the same status set:
//   students → present, absent, late, excused, holiday
//   staff    → present, absent, late, leave,   holiday, wfh
//
// So the "Leave" column reads `excused` for children and `leave` for staff —
// they're the same idea under two names. Anything else a row can hold (holiday,
// and WFH for staff) is counted as "Other" rather than silently folded into one
// of the four, so a row's counts always reconcile against the days recorded.

/** First and last day of a 'YYYY-MM' month, clamped so the end is never future. */
function daycare_month_bounds(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $today = date('Y-m-d');
    if ($to > $today) $to = $today;      // don't count days that haven't happened
    if ($from > $today) { $from = date('Y-m-01'); $to = $today; }
    return [$from, $to];
}

/**
 * How many distinct days in the range have any attendance recorded at all.
 * Used as the denominator for "not marked" — counting calendar days would
 * charge people for weekends and holidays the school was closed.
 */
function daycare_recorded_days(string $table, string $dateCol, string $from, string $to): int
{
    try {
        $s = db()->prepare("SELECT COUNT(DISTINCT $dateCol) FROM $table WHERE $dateCol BETWEEN :f AND :t");
        $s->execute([':f' => $from, ':t' => $to]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Per-child attendance totals for the range. */
function daycare_child_summary(string $from, string $to): array
{
    try {
        $s = db()->prepare("
            SELECT s.id, s.first_name, s.last_name,
                   COALESCE(SUM(a.status = 'present'), 0) AS present,
                   COALESCE(SUM(a.status = 'late'),    0) AS late,
                   COALESCE(SUM(a.status = 'absent'),  0) AS absent,
                   COALESCE(SUM(a.status = 'excused'), 0) AS on_leave,
                   COALESCE(SUM(a.status = 'holiday'), 0) AS other,
                   COUNT(a.id)                            AS marked,
                   COALESCE(SUM(
                       CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL
                                 AND a.check_out >= a.check_in
                            THEN TIME_TO_SEC(TIMEDIFF(a.check_out, a.check_in)) ELSE 0 END
                   ), 0) AS secs
            FROM students s
            LEFT JOIN attendance a
                   ON a.student_id = s.id AND a.attendance_date BETWEEN :f AND :t
            WHERE s.grade = :g
              AND COALESCE(s.is_active, 1) = 1
              AND COALESCE(s.enrollment_status, 'enrolled') IN ('enrolled','promoted')
            GROUP BY s.id, s.first_name, s.last_name
            ORDER BY s.first_name, s.last_name
        ");
        $s->execute([':f' => $from, ':t' => $to, ':g' => DAYCARE_GRADE]);
        return array_map(function (array $r): array {
            $r['name'] = trim((string)$r['first_name'] . ' ' . (string)$r['last_name']);
            return $r;
        }, $s->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

/** Per-staff attendance totals for the range. */
function daycare_staff_summary(string $from, string $to): array
{
    try {
        $s = db()->prepare("
            SELECT u.id, u.name, u.role,
                   COALESCE(SUM(sa.status = 'present'), 0) AS present,
                   COALESCE(SUM(sa.status = 'late'),    0) AS late,
                   COALESCE(SUM(sa.status = 'absent'),  0) AS absent,
                   COALESCE(SUM(sa.status = 'leave'),   0) AS on_leave,
                   COALESCE(SUM(sa.status IN ('holiday','wfh')), 0) AS other,
                   COUNT(sa.id)                            AS marked,
                   COALESCE(SUM(
                       CASE WHEN sa.check_in IS NOT NULL AND sa.check_out IS NOT NULL
                                 AND sa.check_out >= sa.check_in
                            THEN TIME_TO_SEC(TIMEDIFF(sa.check_out, sa.check_in)) ELSE 0 END
                   ), 0) AS secs
            FROM users u
            LEFT JOIN staff_attendance sa
                   ON sa.user_id = u.id AND sa.att_date BETWEEN :f AND :t
            WHERE u.active = 1
              AND (u.role IN ('admin','teacher','non_teaching') OR FIND_IN_SET('staff', u.modules) > 0)
            GROUP BY u.id, u.name, u.role
            ORDER BY u.name
        ");
        $s->execute([':f' => $from, ':t' => $to]);
        return $s->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Seconds → '7h 30m', or '—' when nothing was clocked. */
function daycare_hours(int $secs): string
{
    if ($secs <= 0) return '—';
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
}

/** Counts for the header line: how many are in, and how many have left. */
function daycare_tally(array $rows, array $marks, string $idKey): array
{
    $in = $out = 0;
    foreach ($rows as $r) {
        $m = $marks[(int)$r[$idKey]] ?? null;
        if (!$m) continue;
        if (!empty($m['check_in']))  $in++;
        if (!empty($m['check_out'])) $out++;
    }
    return ['in' => $in, 'out' => $out, 'present' => max(0, $in - $out)];
}
