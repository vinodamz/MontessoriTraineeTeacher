<?php
/**
 * student_tabs.php — the "one child, one record" tab strip (Phase 2 of
 * the UX roadmap).
 *
 * Every per-child page renders this strip right under its page-head, so
 * Profile / Documents / Attendance / Fees / Learning read as tabs of a
 * single record instead of five destinations the user must know about.
 *
 * Tabs appear only when the viewer can use them:
 *   - Documents + Fees   → admins / students-module holders
 *   - Learning           → admins / montessori-module holders
 *   - Profile + Attendance → anyone who can see the child at all
 */
declare(strict_types=1);

function student_tab_strip(int $studentId, string $active, array $user): void
{
    $canEdit  = ($user['role'] ?? '') === 'admin' || user_has_module($user, 'students');
    $canLearn = ($user['role'] ?? '') === 'admin' || user_has_module($user, 'montessori');

    $tabs = [
        ['profile', 'Profile', '/students/view.php?id=' . $studentId],
    ];
    if ($canEdit) {
        $tabs[] = ['documents', 'Documents', '/students/documents.php?student_id=' . $studentId];
    }
    $tabs[] = ['attendance', 'Attendance', '/students/attendance_history.php?student_id=' . $studentId];
    if ($canEdit) {
        $tabs[] = ['fees', 'Fees', '/students/fees.php?student_id=' . $studentId];
    }
    if ($canLearn) {
        $tabs[] = ['learning', 'Learning', '/students/learning.php?student_id=' . $studentId];
    }

    echo '<nav class="student-tabs" aria-label="Child record sections">';
    foreach ($tabs as [$key, $label, $href]) {
        $cls = $key === $active ? ' class="is-active" aria-current="page"' : '';
        echo '<a' . $cls . ' href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</nav>';
}
