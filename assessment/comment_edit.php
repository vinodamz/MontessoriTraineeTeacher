<?php
/**
 * assessment/comment_edit.php — admin edits to a teacher's report notes.
 *
 * assess.php saves a whole month at once (it deletes and re-inserts that
 * month's cards, averages and comments), so it's the wrong place to correct a
 * single sentence. This targets one assessment_comments row at a time and
 * never touches ratings.
 *
 *   POST op=update {id, comment}                  → edit a note in place
 *   POST op=delete {id}                           → remove a note
 *   POST op=add    {student_id, month, category, comment}
 *
 * Admin-only: the teacher who wrote the note edits it through assess.php as
 * part of that month's assessment; this is the correction path for admins.
 * The original author (teacher_id) is preserved on edit — an admin fixing a
 * typo shouldn't silently reassign authorship.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST only.'); }
csrf_check();

$op        = $_POST['op'] ?? '';
$studentId = (int)($_POST['student_id'] ?? 0);

// The delete button lives inside the edit form, so it announces itself with
// its own field rather than a duplicate "op".
if (!empty($_POST['do_delete'])) $op = 'delete';
$back      = '/assessment/report.php?student_id=' . $studentId . '#notes';

/** Resolve the student a note belongs to, so we can always return somewhere sane. */
$studentOf = function (int $id): int {
    $s = db()->prepare("SELECT student_id FROM assessment_comments WHERE id = :id");
    $s->execute([':id' => $id]);
    return (int)($s->fetchColumn() ?: 0);
};

if ($op === 'update') {
    $id   = (int)($_POST['id'] ?? 0);
    $body = trim((string)($_POST['comment'] ?? ''));
    $owner = $id > 0 ? $studentOf($id) : 0;
    if ($owner <= 0) { flash_set('error', 'That note no longer exists.'); redirect($back); }
    if ($body === '') {
        // Emptying a note means removing it — keeping a blank row would render
        // as an empty bullet on the report.
        db()->prepare("DELETE FROM assessment_comments WHERE id = :id")->execute([':id' => $id]);
        flash_set('ok', 'Note removed.');
    } else {
        db()->prepare("UPDATE assessment_comments SET comment = :c WHERE id = :id")
            ->execute([':c' => $body, ':id' => $id]);
        flash_set('ok', 'Note updated.');
    }
    redirect('/assessment/report.php?student_id=' . $owner . '#notes');
}

if ($op === 'delete') {
    $id    = (int)($_POST['id'] ?? 0);
    $owner = $id > 0 ? $studentOf($id) : 0;
    if ($owner <= 0) { flash_set('error', 'That note no longer exists.'); redirect($back); }
    db()->prepare("DELETE FROM assessment_comments WHERE id = :id")->execute([':id' => $id]);
    flash_set('ok', 'Note removed.');
    redirect('/assessment/report.php?student_id=' . $owner . '#notes');
}

if ($op === 'add') {
    $month    = trim((string)($_POST['month'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $body     = trim((string)($_POST['comment'] ?? ''));

    if ($studentId <= 0 || $body === '') {
        flash_set('error', 'Pick a month and write the note before saving.');
        redirect($back);
    }
    // Month must be a real 'M-y' key, otherwise the note would never surface
    // (the report groups strictly by month_year).
    $month = normalize_month_year($month) ?? '';
    if ($month === '') {
        flash_set('error', 'That month looks wrong — pick one from the list.');
        redirect($back);
    }
    db()->prepare("
        INSERT INTO assessment_comments (student_id, teacher_id, month_year, category, comment)
        VALUES (:s, :t, :m, :c, :body)
    ")->execute([
        ':s' => $studentId,
        ':t' => (int)$user['id'],       // the admin is the author of a note they add
        ':m' => $month,
        ':c' => $category === '' ? null : $category,
        ':body' => $body,
    ]);
    flash_set('ok', 'Note added.');
    redirect($back);
}

flash_set('error', 'Unknown action.');
redirect($back);
