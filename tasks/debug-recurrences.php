<?php
/**
 * /debug-recurrences.php — admin-only diagnostic. Shows the current state
 * of recurring task templates and which days they'd fire on.
 *
 * Delete after you've used it (or just leave — it's harmless and
 * inaccessible without an admin session).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: text/plain; charset=utf-8');

if (!recurrence_available()) {
    echo "task_recurrences table does NOT exist on this database.\n";
    echo "→ Run /migrate.php once to apply migration 002_recurring.\n";
    exit;
}

$today = (new DateTime('today'))->format('Y-m-d');
$dow   = (int) date('w');
$dom   = (int) date('j');
$daynames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

echo "Today: $today ({$daynames[$dow]}, day-of-month = $dom)\n";
echo str_repeat('=', 70) . "\n\n";

// All recurrences
$rows = db()->query("
    SELECT r.*, c.name AS column_name, u.name AS assignee_name
    FROM task_recurrences r
    LEFT JOIN task_columns c ON c.id = r.column_id
    LEFT JOIN users u        ON u.id = r.assigned_to_user_id
    ORDER BY r.id ASC
")->fetchAll();

if (!$rows) {
    echo "No recurrences in the database yet.\n";
    echo "→ Open /tasks.php → '+ New task' → tick 'Repeat this task' to create one.\n";
    exit;
}

foreach ($rows as $r) {
    echo "id={$r['id']}  \"{$r['title']}\"\n";
    echo "  active        : " . ($r['is_active'] ? 'YES' : 'NO (paused)') . "\n";
    echo "  frequency     : {$r['frequency']}\n";
    echo "  days_mask     : {$r['days_mask']} = " . days_mask_label((int)$r['days_mask']) . "\n";
    echo "  day_of_month  : " . ($r['day_of_month'] ?? '—') . "\n";
    echo "  due_offset    : " . (int)($r['due_offset_days'] ?? 0) . " days\n";
    echo "  start_date    : {$r['start_date']}\n";
    echo "  end_date      : " . ($r['end_date'] ?? '—') . "\n";
    echo "  column        : " . ($r['column_name'] ?? '— (no column!)') . "\n";
    echo "  assignee      : " . ($r['assignee_name'] ?? '—') . "\n";

    // Does it match today?
    $matches = false;
    $why = '';
    if (!$r['is_active'])                       $why = 'paused';
    elseif ($r['start_date'] > $today)          $why = 'start_date is in the future (' . $r['start_date'] . ')';
    elseif ($r['end_date'] && $r['end_date'] < $today) $why = 'end_date has passed (' . $r['end_date'] . ')';
    elseif ($r['frequency'] === 'daily')        $matches = true;
    elseif ($r['frequency'] === 'weekly') {
        $matches = ((int)$r['days_mask'] & (1 << $dow)) > 0;
        if (!$matches) $why = "today's day ({$daynames[$dow]}) isn't in the schedule";
    } elseif ($r['frequency'] === 'monthly') {
        $matches = (int)$r['day_of_month'] === $dom;
        if (!$matches) $why = "today's day-of-month ($dom) ≠ {$r['day_of_month']}";
    }

    echo "  fires today?  : " . ($matches ? 'YES' : "NO — $why") . "\n";

    // Existing instances?
    $st = db()->prepare("SELECT id, instance_date, column_id FROM tasks WHERE recurrence_id = :r ORDER BY instance_date DESC LIMIT 5");
    $st->execute([':r' => (int)$r['id']]);
    $inst = $st->fetchAll();
    if ($inst) {
        echo "  recent instances:\n";
        foreach ($inst as $i) {
            echo "    - task#{$i['id']}  date={$i['instance_date']}  col={$i['column_id']}\n";
        }
    } else {
        echo "  recent instances: (none yet)\n";
    }
    echo "\n";
}

// Try to materialize now and report
echo "Calling materialize_recurrences() …\n";
materialize_recurrences();
echo "Done.\n\n";

// Show any instances created today
$st = db()->query("SELECT id, title, column_id, recurrence_id, instance_date FROM tasks WHERE instance_date = CURDATE() ORDER BY id DESC");
$todayRows = $st->fetchAll();
echo "Tasks with instance_date = today (" . count($todayRows) . "):\n";
foreach ($todayRows as $t) {
    echo "  task#{$t['id']}  \"{$t['title']}\"  col={$t['column_id']}  recurrence_id={$t['recurrence_id']}\n";
}
