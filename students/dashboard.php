<?php
/**
 * students/dashboard.php — interactive students dashboard.
 *
 * Charts (Chart.js via jsdelivr — same CDN pattern as the admissions
 * dashboard):
 *   - Children per class (click a bar → filtered roster)
 *   - Attendance rate per school day, last 30 days
 *   - New joiners per month (selectable 3/6/12-month window)
 *   - Why children leave (withdrawal reasons doughnut)
 * KPI tiles: enrolled now, attendance today, waiting-for-parent intakes,
 * birthdays this month.
 *
 * Read-only; everything deep-links into the roster / attendance pages.
 * Access: admins + students-module holders (montessori users get the
 * roster pages; this rollup is whole-school so it follows the roster gate).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    http_response_code(403);
    echo 'Forbidden — the students dashboard needs the students module.';
    exit;
}

$GRADES = ['Playgroup', 'Nursery', 'LKG', 'UKG'];

// Month window for the joiners chart: 3 / 6 / 12 (default 6).
$months = (int)($_GET['months'] ?? 6);
if (!in_array($months, [3, 6, 12], true)) $months = 6;
$windowStart = (new DateTime('first day of this month'))
    ->modify('-' . ($months - 1) . ' months')->format('Y-m-d');

$today = (new DateTime('today'))->format('Y-m-d');

// ---- KPI tiles ---------------------------------------------------------------
$enrolledNow = 0; $gradeCounts = array_fill_keys($GRADES, 0);
try {
    $rows = db()->query("
        SELECT grade, COUNT(*) AS n
        FROM students
        WHERE COALESCE(is_active,1) = 1 AND COALESCE(enrollment_status,'enrolled') = 'enrolled'
        GROUP BY grade
    ")->fetchAll();
    foreach ($rows as $r) {
        if (isset($gradeCounts[$r['grade']])) $gradeCounts[$r['grade']] = (int)$r['n'];
        $enrolledNow += (int)$r['n'];
    }
} catch (Throwable $e) {}

$presentToday = 0; $markedToday = 0;
try {
    $st = db()->prepare("
        SELECT
          SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS n_present,
          SUM(CASE WHEN a.status <> 'holiday' THEN 1 ELSE 0 END)          AS n_marked
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        WHERE a.attendance_date = :d
          AND COALESCE(s.is_active,1) = 1
          AND COALESCE(s.enrollment_status,'enrolled') = 'enrolled'
    ");
    $st->execute([':d' => $today]);
    $r = $st->fetch();
    $presentToday = (int)($r['n_present'] ?? 0);
    $markedToday  = (int)($r['n_marked'] ?? 0);
} catch (Throwable $e) {}

$waitingForParent = 0;
try {
    $waitingForParent = (int)db()->query("
        SELECT COUNT(*) FROM students WHERE enrollment_status = 'intake_pending'
    ")->fetchColumn();
} catch (Throwable $e) {}

$birthdaysThisMonth = [];
try {
    $birthdaysThisMonth = db()->query("
        SELECT id, first_name, last_name, grade, DAY(dob) AS d
        FROM students
        WHERE dob IS NOT NULL AND MONTH(dob) = MONTH(CURDATE())
          AND COALESCE(is_active,1) = 1
          AND COALESCE(enrollment_status,'enrolled') = 'enrolled'
        ORDER BY DAY(dob)
    ")->fetchAll();
} catch (Throwable $e) {}

// ---- Attendance rate per school day, last 30 days ----------------------------
$attLabels = []; $attRates = [];
try {
    $rows = db()->query("
        SELECT a.attendance_date AS d,
               SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS n_present,
               SUM(CASE WHEN a.status <> 'holiday' THEN 1 ELSE 0 END)          AS n_marked
        FROM attendance a
        WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY a.attendance_date
        HAVING n_marked > 0
        ORDER BY a.attendance_date
    ")->fetchAll();
    foreach ($rows as $r) {
        $attLabels[] = (new DateTime($r['d']))->format('j M');
        $attRates[]  = round(100 * (int)$r['n_present'] / max(1, (int)$r['n_marked']));
    }
} catch (Throwable $e) {}

// ---- New joiners per month -----------------------------------------------------
$joinLabels = []; $joinCounts = [];
try {
    $byYm = [];
    $st = db()->prepare("
        SELECT DATE_FORMAT(COALESCE(joining_date, DATE(created_at)), '%Y-%m') AS ym, COUNT(*) AS n
        FROM students
        WHERE COALESCE(joining_date, DATE(created_at)) >= :ws
          AND COALESCE(enrollment_status,'enrolled') <> 'intake_pending'
        GROUP BY ym
    ");
    $st->execute([':ws' => $windowStart]);
    foreach ($st as $r) $byYm[$r['ym']] = (int)$r['n'];

    $cursor = new DateTime($windowStart);
    for ($i = 0; $i < $months; $i++) {
        $joinLabels[] = $cursor->format('M y');
        $joinCounts[] = $byYm[$cursor->format('Y-m')] ?? 0;
        $cursor->modify('+1 month');
    }
} catch (Throwable $e) {}

// ---- Withdrawal reasons --------------------------------------------------------
$wdLabels = []; $wdCounts = [];
try {
    $rows = db()->query("
        SELECT COALESCE(NULLIF(withdrawal_reason, ''), 'unspecified') AS reason, COUNT(*) AS n
        FROM students WHERE enrollment_status = 'withdrawn'
        GROUP BY reason ORDER BY n DESC LIMIT 8
    ")->fetchAll();
    foreach ($rows as $r) {
        $label = $r['reason'];
        if (function_exists('withdrawal_reason_label') && $label !== 'unspecified') {
            $label = withdrawal_reason_label($label);
        }
        $wdLabels[] = $label === 'unspecified' ? 'Unspecified' : $label;
        $wdCounts[] = (int)$r['n'];
    }
} catch (Throwable $e) {}

$attTodayPct = $markedToday > 0 ? round(100 * $presentToday / $markedToday) : null;

$pageTitle = 'Students dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Students dashboard</h1>
        <p class="muted">The school at a glance — every number links to where you act on it.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/students/index.php">← Roster</a>
        <a class="btn" href="/students/attendance.php">Mark attendance</a>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
    <li><div class="admin-tile tile-ok">
        <span class="tile-label">Enrolled now</span>
        <span class="tile-value"><?= $enrolledNow ?></span>
        <span class="tile-sub"><?= implode(' · ', array_map(fn($g) => "$g {$gradeCounts[$g]}", $GRADES)) ?></span>
    </div></li>
    <li><div class="admin-tile <?= ($attTodayPct ?? 100) < 80 ? 'tile-warn' : '' ?>">
        <span class="tile-label">Attendance today</span>
        <span class="tile-value"><?= $attTodayPct === null ? '—' : $attTodayPct . '%' ?></span>
        <span class="tile-sub"><?= $markedToday > 0 ? "$presentToday of $markedToday marked present" : 'not marked yet' ?></span>
    </div></li>
    <li><div class="admin-tile <?= $waitingForParent > 0 ? 'tile-warn' : '' ?>">
        <span class="tile-label">Waiting for parent</span>
        <span class="tile-value"><?= $waitingForParent ?></span>
        <span class="tile-sub"><a href="/students/index.php?status=intake_pending">open intakes →</a></span>
    </div></li>
    <li><div class="admin-tile tile-nav">
        <span class="tile-label">Birthdays this month</span>
        <span class="tile-value"><?= count($birthdaysThisMonth) ?></span>
        <span class="tile-sub"><?= date('F') ?></span>
    </div></li>
</ul>

<div class="row" style="align-items: stretch;">
    <div class="card" style="flex: 2 1 360px;">
        <h3 style="margin-top:0;">Children per class <span class="muted small">· click a bar to open that class</span></h3>
        <canvas id="chart-grades" height="140"></canvas>
    </div>
    <div class="card" style="flex: 1 1 280px;">
        <h3 style="margin-top:0;">Why children leave</h3>
        <?php if ($wdCounts): ?>
            <canvas id="chart-withdrawals" height="200"></canvas>
        <?php else: ?>
            <p class="muted">No withdrawals recorded. 🎉</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">Attendance rate — last 30 school days</h3>
    <?php if ($attRates): ?>
        <canvas id="chart-attendance" height="100"></canvas>
    <?php else: ?>
        <p class="muted">No attendance marked in the last 30 days.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h3 style="margin-top:0; display:flex; align-items:center; gap:.6rem; flex-wrap:wrap;">
        New joiners per month
        <span style="margin-left:auto; display:flex; gap:.3rem;">
            <?php foreach ([3, 6, 12] as $m): ?>
                <a class="btn <?= $months === $m ? 'btn-primary' : 'btn-ghost' ?>" style="padding:.25rem .7rem; font-size:.85rem;"
                   href="/students/dashboard.php?months=<?= $m ?>"><?= $m ?>m</a>
            <?php endforeach; ?>
        </span>
    </h3>
    <canvas id="chart-joiners" height="100"></canvas>
</div>

<?php if ($birthdaysThisMonth): ?>
<div class="card" style="border-left: 4px solid #f5b342;">
    <h3 style="margin-top:0;">🎂 Birthdays in <?= e(date('F')) ?></h3>
    <p style="margin:.2rem 0;">
        <?php foreach ($birthdaysThisMonth as $i => $b):
            $full = trim($b['first_name'] . ' ' . $b['last_name']); ?>
            <?= $i ? ' · ' : '' ?><strong><?= (int)$b['d'] ?></strong> <a href="/students/view.php?id=<?= (int)$b['id'] ?>"><?= e($full) ?></a> <span class="muted small">(<?= e($b['grade']) ?>)</span>
        <?php endforeach; ?>
    </p>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
    const PINK = '#e91e63', GREEN = '#66bb6a',
          PALETTE = ['#e91e63','#66bb6a','#42a5f5','#f5b342','#ab47bc','#26a69a','#ef5350','#8d6e63'];
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

    // Children per class — click-through to the filtered roster.
    const grades = <?= json_encode($GRADES) ?>;
    const elGrades = document.getElementById('chart-grades');
    if (elGrades) {
        new Chart(elGrades, {
            type: 'bar',
            data: {
                labels: grades,
                datasets: [{ data: <?= json_encode(array_values($gradeCounts)) ?>,
                             backgroundColor: ['#f5b342','#66bb6a','#42a5f5','#e91e63'], borderRadius: 6 }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                onClick: (evt, els) => {
                    if (els.length) location.href = '/students/index.php?grade=' + encodeURIComponent(grades[els[0].index]);
                },
                onHover: (evt, els) => { evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; }
            }
        });
    }

    // Attendance % line.
    const elAtt = document.getElementById('chart-attendance');
    if (elAtt) {
        new Chart(elAtt, {
            type: 'line',
            data: {
                labels: <?= json_encode($attLabels) ?>,
                datasets: [{ label: '% present', data: <?= json_encode($attRates) ?>,
                             borderColor: GREEN, backgroundColor: 'rgba(102,187,106,.15)',
                             fill: true, tension: .3, pointRadius: 3 }]
            },
            options: {
                plugins: { legend: { display: false },
                           tooltip: { callbacks: { label: c => c.parsed.y + '% present' } } },
                scales: { y: { min: 0, max: 100, ticks: { callback: v => v + '%' } } }
            }
        });
    }

    // Joiners bars.
    const elJoin = document.getElementById('chart-joiners');
    if (elJoin) {
        new Chart(elJoin, {
            type: 'bar',
            data: {
                labels: <?= json_encode($joinLabels) ?>,
                datasets: [{ label: 'New joiners', data: <?= json_encode($joinCounts) ?>,
                             backgroundColor: 'rgba(233,30,99,.18)', borderColor: PINK,
                             borderWidth: 1.5, borderRadius: 5 }]
            },
            options: { plugins: { legend: { display: false } },
                       scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    // Withdrawal reasons doughnut.
    const elWd = document.getElementById('chart-withdrawals');
    if (elWd) {
        new Chart(elWd, {
            type: 'doughnut',
            data: { labels: <?= json_encode($wdLabels) ?>,
                    datasets: [{ data: <?= json_encode($wdCounts) ?>, backgroundColor: PALETTE }] },
            options: { plugins: { legend: { position: 'right' } }, maintainAspectRatio: false }
        });
    }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
