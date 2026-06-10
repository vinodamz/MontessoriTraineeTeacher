<?php
/**
 * students/attendance.php — daily attendance marker.
 *
 *   GET ?date=YYYY-MM-DD&grade=XYZ  → grid of active students for that grade
 *                                     pre-filled with today's marks (if any).
 *   POST                            → bulk upsert per-student status + notes.
 *
 * Default: today's date, all grades. Teachers see only their own students;
 * admins/students-module users see everyone.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students') && !user_has_module($user, 'montessori')) {
    http_response_code(403);
    echo 'Forbidden — no access to attendance.';
    exit;
}

$VALID_GRADES   = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$VALID_STATUSES = ['present', 'absent', 'late', 'excused', 'holiday'];

// ---------- POST: save day's marks ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $date  = $_POST['date']  ?? date('Y-m-d');
    $grade = $_POST['grade'] ?? '';

    $rows = $_POST['mark'] ?? [];
    if (!is_array($rows)) $rows = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO attendance (student_id, attendance_date, status, notes, marked_by_user_id)
            VALUES (:sid, :d, :s, :n, :u)
            ON DUPLICATE KEY UPDATE
                status            = VALUES(status),
                notes             = VALUES(notes),
                marked_by_user_id = VALUES(marked_by_user_id)
        ");
        foreach ($rows as $sid => $r) {
            $sid = (int)$sid;
            if ($sid <= 0) continue;
            $status = $r['status'] ?? 'present';
            if (!in_array($status, $VALID_STATUSES, true)) continue;
            $notes  = trim($r['notes'] ?? '');
            if ($notes === '') $notes = null;
            $ins->execute([
                ':sid' => $sid, ':d' => $date, ':s' => $status,
                ':n'   => $notes, ':u' => $user['id'],
            ]);
        }
        $pdo->commit();
        flash_set('ok', 'Attendance saved for ' . htmlspecialchars($date) . '.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Save failed: ' . $e->getMessage());
    }
    $qs = http_build_query(array_filter(['date' => $date, 'grade' => $grade]));
    // Pages that embed this form (e.g. /today.php) pass return_to so the
    // teacher lands back where they were. Same-site paths only.
    $returnTo = (string)($_POST['return_to'] ?? '');
    if ($returnTo !== '' && $returnTo[0] === '/' && !str_starts_with($returnTo, '//')) {
        redirect($returnTo);
    }
    redirect('/students/attendance.php' . ($qs ? '?' . $qs : ''));
}

// ---------- GET: render grid ---------------------------------------------
$today = (new DateTime('today'))->format('Y-m-d');
$date  = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $today;

$grade = $_GET['grade'] ?? '';
if (!in_array($grade, $VALID_GRADES, true)) $grade = '';

// Load active students, scoped to teacher if they're not admin/students-module.
$canSeeAll = $user['role'] === 'admin' || user_has_module($user, 'students');
$where  = ['COALESCE(s.is_active, 1) = 1'];
$params = [];
if ($grade !== '') {
    $where[] = 's.grade = :grade';
    $params[':grade'] = $grade;
}
if (!$canSeeAll) {
    $where[] = 's.teacher_id = :uid';
    $params[':uid'] = $user['id'];
}

$stmt = db()->prepare("
    SELECT s.id, s.first_name, s.last_name, s.grade,
           u.name AS teacher_name,
           a.status, a.notes,
           a.marked_by_user_id, a.marked_at
    FROM students s
    LEFT JOIN users u      ON u.id = s.teacher_id
    LEFT JOIN attendance a ON a.student_id = s.id AND a.attendance_date = :d
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name, s.last_name
");
$params[':d'] = $date;
$stmt->execute($params);
$students = $stmt->fetchAll();

// Quick day-summary counts.
$counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'holiday' => 0, 'unmarked' => 0];
foreach ($students as $row) {
    $s = $row['status'] ?? null;
    if ($s && isset($counts[$s])) $counts[$s]++;
    else                          $counts['unmarked']++;
}

// Previous / next day links.
$d  = new DateTime($date);
$prevDate = (clone $d)->modify('-1 day')->format('Y-m-d');
$nextDate = (clone $d)->modify('+1 day')->format('Y-m-d');
$niceDate = $d->format('l, j M Y');

$pageTitle = 'Attendance — ' . $niceDate;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p class="muted"><strong><?= e($niceDate) ?></strong>
            · <?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?> shown
            <?php foreach ($counts as $key => $n): if ($n): ?>
                · <span class="pill att-pill att-<?= e($key) ?>"><?= e(ucfirst($key)) ?> <?= $n ?></span>
            <?php endif; endforeach; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="date">Date</label>
        <input id="date" type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>">
    </div>
    <div class="field">
        <label for="grade">Grade</label>
        <select id="grade" name="grade">
            <option value="">All grades</option>
            <?php foreach ($VALID_GRADES as $g): ?>
                <option value="<?= e($g) ?>" <?= $grade === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Show</button>
        <a class="btn btn-ghost" href="/students/attendance.php?date=<?= e($prevDate) ?><?= $grade !== '' ? '&grade=' . e($grade) : '' ?>">‹ Prev day</a>
        <a class="btn btn-ghost" href="/students/attendance.php?date=<?= e($nextDate) ?><?= $grade !== '' ? '&grade=' . e($grade) : '' ?>">Next day ›</a>
        <a class="btn btn-ghost" href="/students/attendance.php<?= $grade !== '' ? '?grade=' . e($grade) : '' ?>">Today</a>
    </div>
</form>

<?php if (!$students): ?>
    <div class="empty"><p>No active students matching this filter.</p></div>
<?php else: ?>
<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="date"  value="<?= e($date) ?>">
    <input type="hidden" name="grade" value="<?= e($grade) ?>">

    <div class="att-actionbar">
        <button class="btn" type="button" data-att-all="present">All present</button>
        <button class="btn" type="button" data-att-all="holiday">All holiday</button>
        <span class="muted small">Pick a status per student. "Unmarked" rows are saved as the picked status — leave them as Present if everyone showed up.</span>
    </div>

    <ul class="att-grid">
        <?php foreach ($students as $s):
            $sid    = (int)$s['id'];
            $status = $s['status'] ?? 'present';
            $full   = trim($s['first_name'] . ' ' . $s['last_name']);
        ?>
            <li class="att-row" style="--card: <?= e(user_color($sid)) ?>;">
                <div class="att-who">
                    <span class="student-avatar"><?= e(user_initials($full)) ?></span>
                    <div>
                        <div class="att-name"><a href="/students/view.php?id=<?= $sid ?>"><?= e($full) ?></a></div>
                        <div class="muted small">
                            <span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span>
                            <?php if (!empty($s['teacher_name'])): ?> · <?= e($s['teacher_name']) ?><?php endif; ?>
                            <?php if (!empty($s['marked_at'])): ?> · last saved <?= e(substr((string)$s['marked_at'], 0, 16)) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="att-status">
                    <?php foreach ($VALID_STATUSES as $st): ?>
                        <label class="att-opt att-<?= e($st) ?> <?= $status === $st ? 'is-on' : '' ?>">
                            <input type="radio" name="mark[<?= $sid ?>][status]" value="<?= e($st) ?>" <?= $status === $st ? 'checked' : '' ?>>
                            <?= e(ucfirst($st)) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="att-notes">
                    <input type="text" name="mark[<?= $sid ?>][notes]" maxlength="255" placeholder="Notes (optional)" value="<?= e($s['notes'] ?? '') ?>">
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit">Save attendance</button>
    </div>
</form>

<script>
(() => {
    // Highlight the picked status, and let "All present" / "All holiday"
    // bulk-select everyone in one click.
    document.querySelectorAll('.att-row').forEach(row => {
        row.addEventListener('change', e => {
            if (e.target.matches('input[type=radio]')) {
                row.querySelectorAll('.att-opt').forEach(l => l.classList.toggle('is-on', l.querySelector('input').checked));
            }
        });
    });
    document.querySelectorAll('[data-att-all]').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.attAll;
            document.querySelectorAll('.att-row').forEach(row => {
                const radio = row.querySelector(`input[type=radio][value="${target}"]`);
                if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
