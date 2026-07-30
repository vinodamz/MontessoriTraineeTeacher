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
