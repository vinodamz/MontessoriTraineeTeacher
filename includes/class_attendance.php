<?php
/**
 * includes/class_attendance.php — "my class" attendance, shared by My Day
 * (today.php) and the app's Today screen (api/v1/today.php,
 * api/v1/class/attendance.php), so the website and the app always agree.
 *
 * "My class" = active, enrolled students whose teacher_id is the user.
 * Writes follow the same rule as students/attendance.php: admins and Students
 * module users may mark any active child; everyone else only their own class.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

const CLASS_ATTENDANCE_STATUSES = ['present', 'absent', 'late', 'excused', 'holiday'];

/** Can this user open the attendance page at all (My Day / students/attendance.php)? */
function class_attendance_can_open(array $user): bool
{
    return ($user['role'] ?? '') === 'admin'
        || user_has_module($user, 'students')
        || user_has_module($user, 'montessori');
}

/** May this user mark children outside their own class (e.g. a subteacher covering)? */
function class_attendance_can_mark_any(array $user): bool
{
    return ($user['role'] ?? '') === 'admin' || user_has_module($user, 'students');
}

/** Students assigned to the user, with the day's mark (status null = not saved yet). */
function class_attendance_my_class(int $userId, string $date): array
{
    $stmt = db()->prepare("
        SELECT s.id, s.first_name, s.last_name, s.grade, s.dob,
               a.status, a.notes
        FROM students s
        LEFT JOIN attendance a ON a.student_id = s.id AND a.attendance_date = :d
        WHERE COALESCE(s.is_active, 1) = 1
          AND COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'
          AND s.teacher_id = :uid
        ORDER BY " . grade_sql_order('s.grade') . ", s.first_name, s.last_name
    ");
    $stmt->execute([':d' => $date, ':uid' => $userId]);
    return $stmt->fetchAll();
}

/** Counts for a class list from class_attendance_my_class(). */
function class_attendance_counts(array $rows): array
{
    $c = ['total' => count($rows), 'unsaved' => 0, 'present' => 0, 'absent' => 0, 'other' => 0];
    foreach ($rows as $r) {
        $st = $r['status'] ?? null;
        if ($st === null || $st === '') $c['unsaved']++;
        elseif ($st === 'present' || $st === 'late') $c['present']++;
        elseif ($st === 'absent') $c['absent']++;
        else $c['other']++;
    }
    return $c;
}

/** Children in the user's class not yet assessed this month (montessori module). */
function class_attendance_pending_assessments(array $user): int
{
    if (!user_has_module($user, 'montessori')) return 0;
    try {
        $stmt = db()->prepare("
            SELECT COUNT(*) FROM students s
            WHERE s.teacher_id = :u
              AND COALESCE(s.is_active, 1) = 1
              AND COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'
              AND NOT EXISTS (
                  SELECT 1 FROM evaluation_cards e
                  WHERE e.student_id = s.id AND e.month_year = :m AND e.teacher_id = :u2
              )
        ");
        $stmt->execute([':u' => (int)$user['id'], ':m' => current_month_year(), ':u2' => (int)$user['id']]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Save marks. $marks = [student_id => ['status' => ..., 'notes' => ?string]].
 * A notes key that is absent keeps the saved note. Validates the whole batch
 * first, so a request that includes a child the user may not mark saves nothing.
 *
 * @throws InvalidArgumentException on a bad status or a child outside the user's reach.
 */
function class_attendance_save(array $user, string $date, array $marks): int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new InvalidArgumentException('Invalid date.');
    $pdo = db();
    $any = class_attendance_can_mark_any($user);
    $allowed = $pdo->prepare('SELECT id FROM students WHERE id = :sid AND COALESCE(is_active, 1) = 1'
        . ($any ? '' : ' AND teacher_id = :uid'));
    $clean = [];
    foreach ($marks as $sid => $m) {
        $sid = (int)$sid;
        $status = is_array($m) ? (string)($m['status'] ?? '') : '';
        if ($sid <= 0 || !in_array($status, CLASS_ATTENDANCE_STATUSES, true)) {
            throw new InvalidArgumentException('Pick present, absent, late, excused or holiday.');
        }
        $params = [':sid' => $sid];
        if (!$any) $params[':uid'] = (int)$user['id'];
        $allowed->execute($params);
        if (!$allowed->fetchColumn()) {
            throw new InvalidArgumentException('Attendance can only be marked for children in your class.');
        }
        $notes = array_key_exists('notes', $m) ? trim((string)$m['notes']) : null;
        $clean[$sid] = ['status' => $status, 'notes' => $notes, 'keep_notes' => !array_key_exists('notes', $m)];
    }
    if (!$clean) return 0;
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO attendance (student_id, attendance_date, status, notes, marked_by_user_id)
            VALUES (:sid, :d, :s, :n, :u)
            ON DUPLICATE KEY UPDATE
                status            = VALUES(status),
                notes             = IF(:keep = 1, notes, VALUES(notes)),
                marked_by_user_id = VALUES(marked_by_user_id)
        ");
        foreach ($clean as $sid => $m) {
            $ins->execute([
                ':sid' => $sid, ':d' => $date, ':s' => $m['status'],
                ':n' => ($m['notes'] === null || $m['notes'] === '') ? null : mb_substr($m['notes'], 0, 255),
                ':u' => (int)$user['id'], ':keep' => $m['keep_notes'] ? 1 : 0,
            ]);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
    return count($clean);
}

/** Mark every child in my class who has no mark yet as present. Existing marks are left alone. */
function class_attendance_rest_present(array $user, string $date): int
{
    $marks = [];
    foreach (class_attendance_my_class((int)$user['id'], $date) as $r) {
        if (($r['status'] ?? null) === null || $r['status'] === '') {
            $marks[(int)$r['id']] = ['status' => 'present'];
        }
    }
    return class_attendance_save($user, $date, $marks);
}
