<?php
/**
 * includes/feedback.php — Parent-feedback domain helpers.
 *
 * Admin-only section. Pure domain code: status labels, fetch/save, and the
 * append-only discussion-log writer. No HTML, no routing — every
 * /feedback/*.php page requires this. Schema: sql/migrate_048_parent_feedback.
 */
declare(strict_types=1);

/** Feedback lifecycle statuses → labels (display order). */
function feedback_statuses(): array
{
    return [
        'open'        => 'Open',
        'in_progress' => 'In discussion',
        'resolved'    => 'Resolved',
        'closed'      => 'Closed',
    ];
}

/** Log entry types → labels for the history timeline. */
function feedback_log_types(): array
{
    return [
        'created' => 'Logged',
        'note'    => 'Discussion note',
        'status'  => 'Status change',
        'edit'    => 'Edited',
    ];
}

/** One feedback row joined with student + creator names, or null. */
function feedback_get(int $id): ?array
{
    $s = db()->prepare("
        SELECT f.*, u.name AS created_by_name,
               s.first_name AS s_first, s.last_name AS s_last
        FROM parent_feedback f
        LEFT JOIN users u    ON u.id = f.created_by
        LEFT JOIN students s ON s.id = f.student_id
        WHERE f.id = :id
    ");
    $s->execute([':id' => $id]);
    $row = $s->fetch();
    return $row ?: null;
}

/** Human label for the family a feedback entry is about. */
function feedback_about(array $row): string
{
    $student = trim((string)($row['s_first'] ?? '') . ' ' . (string)($row['s_last'] ?? ''));
    $parent  = trim((string)($row['parent_name'] ?? ''));
    if ($student !== '' && $parent !== '') return "$parent (parent of $student)";
    if ($student !== '') return "Parent of $student";
    if ($parent !== '')  return $parent;
    return 'Unspecified parent';
}

/** Append an entry to a feedback item's history log. */
function feedback_log_add(int $feedbackId, string $note, int $byUserId, string $type = 'note'): void
{
    if (!array_key_exists($type, feedback_log_types())) $type = 'note';
    $note = trim($note);
    if ($note === '') return;
    db()->prepare("
        INSERT INTO parent_feedback_log (feedback_id, entry_type, note, logged_by)
        VALUES (:f, :t, :n, :by)
    ")->execute([':f' => $feedbackId, ':t' => $type, ':n' => $note, ':by' => $byUserId]);
}

/** Full history for a feedback item, oldest first (a timeline). */
function feedback_log(int $feedbackId): array
{
    $s = db()->prepare("
        SELECT l.*, u.name AS by_name
        FROM parent_feedback_log l
        LEFT JOIN users u ON u.id = l.logged_by
        WHERE l.feedback_id = :f
        ORDER BY l.created_at ASC, l.id ASC
    ");
    $s->execute([':f' => $feedbackId]);
    return $s->fetchAll();
}

/** Active students for the entry form's optional "which child" dropdown. */
function feedback_student_options(): array
{
    try {
        return db()->query("
            SELECT id, first_name, last_name
            FROM students
            WHERE COALESCE(is_active, 1) = 1
            ORDER BY first_name, last_name
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
