<?php
/**
 * today.php — the teacher's "My Day" page (Phase 1 of the UX roadmap).
 *
 * One mobile-first screen covering a teacher's daily loop:
 *   1. Self check-in / check-out   (staff_attendance, same as the home card)
 *   2. My class attendance         (inline marking — posts to
 *                                   students/attendance.php with return_to)
 *   3. Pending assessments         (montessori module, current month)
 *   4. Quick logbook entry         (observation / incident shortcuts)
 *   5. Today's birthdays           (school-wide — small school, big joy)
 *
 * "My class" means students assigned to ME (teacher_id = current user),
 * regardless of role — an admin who also teaches sees their own class here.
 * Admins without an assigned class get a pointer to the full attendance page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin'
    && !user_has_module($user, 'students')
    && !user_has_module($user, 'montessori')) {
    http_response_code(403);
    echo 'Forbidden — My Day needs the students or assessment module.';
    exit;
}

$VALID_STATUSES = ['present', 'absent', 'late', 'excused', 'holiday'];
$today    = (new DateTime('today'))->format('Y-m-d');
$niceDate = (new DateTime('today'))->format('l, j M');

// ---- 1. Self check-in state ------------------------------------------------
$todayAttendance = null;
try {
    $stmt = db()->prepare("SELECT * FROM staff_attendance WHERE user_id = :u AND att_date = :d");
    $stmt->execute([':u' => (int)$user['id'], ':d' => $today]);
    $todayAttendance = $stmt->fetch() ?: null;
} catch (Throwable $e) { /* staff module may not be provisioned */ }

// ---- 2. My class + today's marks --------------------------------------------
$stmt = db()->prepare("
    SELECT s.id, s.first_name, s.last_name, s.grade, s.dob,
           a.status, a.notes
    FROM students s
    LEFT JOIN attendance a ON a.student_id = s.id AND a.attendance_date = :d
    WHERE COALESCE(s.is_active, 1) = 1
      AND COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'
      AND s.teacher_id = :uid
    ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name, s.last_name
");
$stmt->execute([':d' => $today, ':uid' => (int)$user['id']]);
$myClass = $stmt->fetchAll();

$unmarked = 0;
foreach ($myClass as $row) {
    if (empty($row['status'])) $unmarked++;
}

// ---- 3. Pending assessments this month --------------------------------------
$pendingAssess = 0;
$hasMontess = user_has_module($user, 'montessori');
if ($hasMontess && $myClass) {
    try {
        $month = current_month_year();
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
        $stmt->execute([':u' => $user['id'], ':m' => $month, ':u2' => $user['id']]);
        $pendingAssess = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
}

// ---- 5. Today's birthdays (school-wide) --------------------------------------
$birthdays = [];
try {
    $stmt = db()->prepare("
        SELECT id, first_name, last_name, grade,
               TIMESTAMPDIFF(YEAR, dob, CURDATE()) AS turns
        FROM students
        WHERE dob IS NOT NULL
          AND MONTH(dob) = MONTH(CURDATE()) AND DAY(dob) = DAY(CURDATE())
          AND COALESCE(is_active, 1) = 1
          AND COALESCE(enrollment_status, 'enrolled') = 'enrolled'
        ORDER BY first_name
    ");
    $stmt->execute();
    $birthdays = $stmt->fetchAll();
} catch (Throwable $e) {}

$hasLogbook = user_has_module($user, 'logbook');

$pageTitle = 'My Day';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Day</h1>
        <p class="muted"><strong><?= e($niceDate) ?></strong>
            <?php if ($myClass): ?> · <?= count($myClass) ?> in my class<?php endif; ?>
            <?php if ($unmarked > 0): ?> · <span class="pill pill-warn"><?= $unmarked ?> unmarked</span>
            <?php elseif ($myClass): ?> · <span class="pill">attendance done ✓</span><?php endif; ?>
        </p>
    </div>
</div>

<?php /* ---- Check-in card (same flow as the home card) ---- */ ?>
<?php
$in  = $todayAttendance['check_in']  ?? null;
$out = $todayAttendance['check_out'] ?? null;
?>
<div class="card checkin-card">
    <div class="checkin-body">
        <div>
            <div class="checkin-label">My attendance</div>
            <?php if (!$in): ?>
                <div class="checkin-status muted">Not checked in yet — <?= e(date('H:i')) ?> now</div>
            <?php elseif (!$out): ?>
                <div class="checkin-status">Checked in at <strong><?= e(substr($in, 0, 5)) ?></strong></div>
            <?php else: ?>
                <div class="checkin-status">
                    In <strong><?= e(substr($in, 0, 5)) ?></strong>
                    · Out <strong><?= e(substr($out, 0, 5)) ?></strong>
                    <span class="pill pill-ok">Done for today</span>
                </div>
            <?php endif; ?>
        </div>
        <form method="post" action="/staff/attendance.php" class="checkin-action">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="return_to" value="/today.php">
            <?php if (!$in): ?>
                <input type="hidden" name="op" value="self_in">
                <button class="btn btn-primary btn-big">Check in now</button>
            <?php elseif (!$out): ?>
                <input type="hidden" name="op" value="self_out">
                <button class="btn btn-big">Check out</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php /* ---- Shortcuts: assessments + quick logbook ---- */ ?>
<?php if ($pendingAssess > 0 || $hasLogbook): ?>
<div class="actionbar" style="margin: 0 0 1rem; flex-wrap: wrap;">
    <?php if ($pendingAssess > 0): ?>
        <a class="btn" href="/assessment/index.php"><?= $pendingAssess ?> assessment<?= $pendingAssess === 1 ? '' : 's' ?> pending this month</a>
    <?php endif; ?>
    <?php if ($hasLogbook): ?>
        <a class="btn" href="/logbook/add.php?type=observation">🔭 Note an observation</a>
        <a class="btn" href="/logbook/add.php?type=incident">🩹 Report an incident</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ---- Birthdays ---- */ ?>
<?php if ($birthdays): ?>
<div class="card" style="border-left: 4px solid #f5b342;">
    <strong>🎂 Birthdays today:</strong>
    <?php foreach ($birthdays as $i => $b):
        $bFull = trim($b['first_name'] . ' ' . $b['last_name']); ?>
        <?= $i ? ' · ' : ' ' ?><a href="/students/view.php?id=<?= (int)$b['id'] ?>"><?= e($bFull) ?></a>
        <span class="muted small">(<?= e($b['grade']) ?>, turns <?= (int)$b['turns'] ?>)</span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* ---- My class attendance, inline ---- */ ?>
<?php if (!$myClass): ?>
    <div class="empty">
        <p>No class is assigned to you yet.
        <?php if ($user['role'] === 'admin'): ?>
            Mark attendance for the whole school on the <a href="/students/attendance.php">attendance page</a>.
        <?php else: ?>
            Ask an admin to assign students to you.
        <?php endif; ?>
        </p>
    </div>
<?php else: ?>
<form method="post" action="/students/attendance.php" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="date"  value="<?= e($today) ?>">
    <input type="hidden" name="grade" value="">
    <input type="hidden" name="return_to" value="/today.php">

    <div class="att-actionbar">
        <strong style="margin-right:auto;">My class — today's attendance</strong>
        <button class="btn" type="button" data-att-all="present">All present</button>
        <a class="btn btn-ghost" href="/students/attendance.php">Full page</a>
    </div>

    <ul class="att-grid">
        <?php foreach ($myClass as $s):
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
                            <?php if (empty($s['status'])): ?> · <span class="pill pill-warn">unmarked</span><?php endif; ?>
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
        <button class="btn btn-primary btn-big" type="submit">Save attendance</button>
    </div>
</form>

<script>
(() => {
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

<?php require __DIR__ . '/includes/footer.php'; ?>
