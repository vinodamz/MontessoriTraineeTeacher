<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/class_attendance.php';
function check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "ok $name\n"; }

$teacher = ['id' => 2, 'role' => 'teacher', 'modules' => ['montessori']];
$sub     = ['id' => 3, 'role' => 'teacher', 'modules' => ['students', 'montessori']];
$aya     = ['id' => 4, 'role' => 'non_teaching', 'modules' => ['staff', 'daycare']];
$admin   = ['id' => 1, 'role' => 'admin', 'modules' => []];
check(class_attendance_can_open($teacher) && class_attendance_can_open($sub) && class_attendance_can_open($admin), 'class roles can open attendance');
check(!class_attendance_can_open($aya), 'support staff cannot open class attendance');
check(!class_attendance_can_mark_any($teacher), 'assessment-only teacher limited to own class');
check(class_attendance_can_mark_any($sub) && class_attendance_can_mark_any($admin), 'subteacher and admin can cover any class');
$c = class_attendance_counts([['status' => null], ['status' => ''], ['status' => 'present'], ['status' => 'late'], ['status' => 'absent'], ['status' => 'holiday']]);
check($c === ['total' => 6, 'unsaved' => 2, 'present' => 2, 'absent' => 1, 'other' => 1], 'counts group late with present');
echo "Class attendance unit tests passed\n";

if (!in_array('--local-db', $argv, true)) exit;
// Needs a dev DB with the seeded teacher class used by the Cloud Agent env.
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$pdo->beginTransaction();
try {
    $date = '2000-01-03';
    $kid = (int)$pdo->query("SELECT id FROM students WHERE teacher_id IS NOT NULL AND COALESCE(is_active,1)=1 LIMIT 1")->fetchColumn();
    $owner = ['id' => (int)$pdo->query("SELECT teacher_id FROM students WHERE id = $kid")->fetchColumn(), 'role' => 'teacher', 'modules' => ['montessori']];
    $stranger = ['id' => 999999, 'role' => 'teacher', 'modules' => ['montessori']];
    try { class_attendance_save($stranger, $date, [$kid => ['status' => 'present']]); throw new RuntimeException('stranger saved'); }
    catch (InvalidArgumentException $e) { echo "ok other class rejected\n"; }
    class_attendance_save($owner, $date, [$kid => ['status' => 'absent', 'notes' => 'Fever']]);
    class_attendance_rest_present($owner, $date);
    $rows = class_attendance_my_class($owner['id'], $date);
    $byId = array_column($rows, null, 'id');
    check($byId[$kid]['status'] === 'absent' && $byId[$kid]['notes'] === 'Fever', 'rest present keeps an existing mark');
    check(class_attendance_counts($rows)['unsaved'] === 0, 'rest present fills every unsaved child');
    class_attendance_save($owner, $date, [$kid => ['status' => 'present']]);
    $byId = array_column(class_attendance_my_class($owner['id'], $date), null, 'id');
    check($byId[$kid]['status'] === 'present' && $byId[$kid]['notes'] === 'Fever', 'status change without notes keeps the note');
    echo "Class attendance DB tests passed\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
