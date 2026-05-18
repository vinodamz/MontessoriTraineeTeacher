<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
materialize_recurrences();

// ---------- Parse view + anchor date -----------------------------------------
$view = $_GET['view'] ?? 'day';
if (!in_array($view, ['day', 'week', 'month'], true)) $view = 'day';

$date = $_GET['date'] ?? date('Y-m-d');
$anchor = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime('today');
$today  = new DateTime('today');

// ---------- Date range for the selected view --------------------------------
$rangeStart = clone $anchor;
$rangeEnd   = clone $anchor;
$gridStart  = null; // for month view: includes leading days of prev month
$gridEnd    = null;

if ($view === 'day') {
    $rangeStart = clone $anchor;
    $rangeEnd   = clone $anchor;
} elseif ($view === 'week') {
    $dow = (int) $anchor->format('N');           // 1 = Mon … 7 = Sun
    $rangeStart = (clone $anchor)->modify('-' . ($dow - 1) . ' days');
    $rangeEnd   = (clone $rangeStart)->modify('+6 days');
} elseif ($view === 'month') {
    $rangeStart = new DateTime($anchor->format('Y-m-01'));
    $rangeEnd   = new DateTime($anchor->format('Y-m-t'));
    // Grid range — start on Monday of first week, end on Sunday of last week
    $dow = (int) $rangeStart->format('N');
    $gridStart = (clone $rangeStart)->modify('-' . ($dow - 1) . ' days');
    $dowEnd  = (int) $rangeEnd->format('N');
    $gridEnd = (clone $rangeEnd)->modify('+' . (7 - $dowEnd) . ' days');
}

$queryStart = ($gridStart ?? $rangeStart)->format('Y-m-d');
$queryEnd   = ($gridEnd   ?? $rangeEnd)->format('Y-m-d');

// ---------- Fetch tasks ------------------------------------------------------
$sql = "
    SELECT t.*,
           u.name AS assignee_name,
           col.name AS column_name, col.color AS column_color, col.is_done AS column_done,
           COALESCE(t.due_date, t.instance_date) AS card_date
    FROM tasks t
    LEFT JOIN users u          ON u.id   = t.assigned_to_user_id
    LEFT JOIN task_columns col ON col.id = t.column_id
    WHERE COALESCE(t.due_date, t.instance_date) BETWEEN :s AND :e
    ORDER BY col.is_done ASC, FIELD(t.priority,'high','normal','low'), t.board_position ASC, t.id DESC
";
$stmt = db()->prepare($sql);
$stmt->execute([':s' => $queryStart, ':e' => $queryEnd]);
$tasks = $stmt->fetchAll();

// Group by ISO date string
$byDate = [];
foreach ($tasks as $t) {
    $d = $t['card_date'];
    if (!$d) continue;
    $byDate[$d][] = $t;
}

// ---------- Prev / next navigation -------------------------------------------
$prevDate = (clone $anchor);
$nextDate = (clone $anchor);
if      ($view === 'day')   { $prevDate->modify('-1 day');   $nextDate->modify('+1 day'); }
elseif  ($view === 'week')  { $prevDate->modify('-7 days');  $nextDate->modify('+7 days'); }
elseif  ($view === 'month') { $prevDate->modify('first day of -1 month'); $nextDate->modify('first day of +1 month'); }

function cal_link(string $view, DateTime $d): string {
    return 'calendar.php?' . http_build_query(['view' => $view, 'date' => $d->format('Y-m-d')]);
}

$titleStr = match ($view) {
    'day'   => $anchor->format('l, j F Y'),
    'week'  => 'Week of ' . $rangeStart->format('j M') . ' – ' . $rangeEnd->format('j M Y'),
    'month' => $anchor->format('F Y'),
};

$pageTitle = "Calendar — LG Task Manager";
$wideLayout = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="actionbar">
    <h1><?= e($titleStr) ?></h1>
    <div class="view-toggle">
        <a class="toggle <?= $view === 'day'   ? 'active' : '' ?>" href="<?= e(cal_link('day',   $anchor)) ?>">Day</a>
        <a class="toggle <?= $view === 'week'  ? 'active' : '' ?>" href="<?= e(cal_link('week',  $anchor)) ?>">Week</a>
        <a class="toggle <?= $view === 'month' ? 'active' : '' ?>" href="<?= e(cal_link('month', $anchor)) ?>">Month</a>
    </div>
</div>

<div class="cal-nav">
    <a class="btn btn-ghost" href="<?= e(cal_link($view, $prevDate)) ?>">‹ Prev</a>
    <a class="btn" href="<?= e(cal_link($view, $today)) ?>">Today</a>
    <a class="btn btn-ghost" href="<?= e(cal_link($view, $nextDate)) ?>">Next ›</a>
</div>

<?php // ============================================================
      // DAY VIEW
      // ============================================================
      if ($view === 'day'):
    $iso  = $anchor->format('Y-m-d');
    $list = $byDate[$iso] ?? [];
?>
    <?php if (!$list): ?>
        <div class="empty">Nothing scheduled for <?= e($anchor->format('l, j F')) ?>.</div>
    <?php else: ?>
        <ul class="task-list">
            <?php foreach ($list as $t): $cc = $t['column_color'] ?? '#EC407A'; ?>
                <li class="task" style="border-left-color: <?= e($cc) ?>;">
                    <div class="task-head">
                        <?php if (!empty($t['column_name'])): ?>
                            <span class="task-status-pill" style="background: <?= e($cc) ?>22; color: <?= e($cc) ?>;"><?= e($t['column_name']) ?></span>
                        <?php endif; ?>
                        <span class="task-priority <?= e(priority_class($t['priority'])) ?>"><?= e($t['priority']) ?></span>
                        <?php if (!empty($t['recurrence_id'])): ?><span class="recurring-pill" title="Recurring">↻</span><?php endif; ?>
                    </div>
                    <h3 class="task-title">
                        <a href="tasks.php?edit=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
                    </h3>
                    <?php if (!empty($t['description'])): ?>
                        <p class="task-desc"><?= nl2br(e($t['description'])) ?></p>
                    <?php endif; ?>
                    <p class="task-by"><?= e($t['assignee_name'] ?: 'Unassigned') ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

<?php // ============================================================
      // WEEK VIEW — 7-column strip Mon–Sun
      // ============================================================
elseif ($view === 'week'): ?>
    <div class="cal-week">
        <?php $d = clone $rangeStart; for ($i = 0; $i < 7; $i++):
            $iso  = $d->format('Y-m-d');
            $list = $byDate[$iso] ?? [];
            $isToday = $iso === $today->format('Y-m-d');
        ?>
            <section class="cal-day <?= $isToday ? 'is-today' : '' ?>">
                <header class="cal-day-head">
                    <a href="<?= e(cal_link('day', $d)) ?>">
                        <span class="cal-day-name"><?= e($d->format('D')) ?></span>
                        <span class="cal-day-num"><?= e($d->format('j')) ?></span>
                    </a>
                </header>
                <?php if (!$list): ?>
                    <div class="cal-day-empty">—</div>
                <?php else: ?>
                    <ul class="cal-day-list">
                        <?php foreach ($list as $t): $cc = $t['column_color'] ?? '#EC407A'; ?>
                            <li class="cal-mini" style="border-left-color: <?= e($cc) ?>;">
                                <a href="tasks.php?edit=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
                                <?php if (!empty($t['assignee_name'])): ?>
                                    <small><?= e($t['assignee_name']) ?></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php $d->modify('+1 day'); endfor; ?>
    </div>

<?php // ============================================================
      // MONTH VIEW — 6-row × 7-col grid
      // ============================================================
elseif ($view === 'month'): ?>
    <div class="cal-month">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $n): ?>
            <div class="cal-month-head"><?= e($n) ?></div>
        <?php endforeach; ?>
        <?php
        $d = clone $gridStart;
        while ($d <= $gridEnd):
            $iso        = $d->format('Y-m-d');
            $list       = $byDate[$iso] ?? [];
            $inMonth    = $d->format('Y-m') === $anchor->format('Y-m');
            $isToday    = $iso === $today->format('Y-m-d');
            $classes    = trim(($inMonth ? '' : 'cal-month-out') . ($isToday ? ' is-today' : ''));
        ?>
            <div class="cal-month-cell <?= e($classes) ?>">
                <a href="<?= e(cal_link('day', $d)) ?>" class="cal-month-date">
                    <?= e($d->format('j')) ?>
                </a>
                <?php $shown = 0; foreach ($list as $t):
                    if ($shown >= 3) break;
                    $cc = $t['column_color'] ?? '#EC407A';
                ?>
                    <a class="cal-month-task" style="background: <?= e($cc) ?>22; color: <?= e($cc) ?>;"
                       href="tasks.php?edit=<?= (int)$t['id'] ?>"
                       title="<?= e($t['title']) ?>">
                        <?= e(mb_strimwidth($t['title'], 0, 20, '…')) ?>
                    </a>
                <?php $shown++; endforeach; ?>
                <?php if (count($list) > $shown): ?>
                    <a class="cal-month-more" href="<?= e(cal_link('day', $d)) ?>">+<?= count($list) - $shown ?> more</a>
                <?php endif; ?>
            </div>
        <?php $d->modify('+1 day'); endwhile; ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
