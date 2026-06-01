<?php
/**
 * logbook/index.php — list of all log entries with type + date filters.
 *
 * All users with the logbook module can view every entry (per the
 * school's transparency choice). Filter by type, student, and date range.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logbook.php';

$user = require_module('logbook');
$pdo  = db();

// ---- Filters -------------------------------------------------------------
$fType    = $_GET['type']  ?? '';
$fFrom    = $_GET['from']  ?? '';
$fTo      = $_GET['to']    ?? '';
$fStudent = (int)($_GET['student'] ?? 0);

if (!array_key_exists($fType, logbook_types())) $fType = '';

$where = ['1=1'];
$params = [];
if ($fType !== '')  { $where[] = 'e.log_type = :t';     $params[':t'] = $fType; }
if ($fStudent > 0)  { $where[] = 'e.student_id = :sid';  $params[':sid'] = $fStudent; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $where[] = 'e.occurred_at >= :from'; $params[':from'] = $fFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   { $where[] = 'e.occurred_at <= :to';   $params[':to']   = $fTo   . ' 23:59:59'; }

$sql = "
    SELECT e.*, s.first_name, s.last_name, u.name AS by_name
    FROM logbook_entries e
    LEFT JOIN students s ON s.id = e.student_id
    LEFT JOIN users u    ON u.id = e.logged_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.occurred_at DESC, e.id DESC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Today's count per type for the quick chips.
$todayCounts = [];
try {
    foreach ($pdo->query("
        SELECT log_type, COUNT(*) n FROM logbook_entries
        WHERE occurred_at >= CURDATE() GROUP BY log_type
    ") as $r) $todayCounts[$r['log_type']] = (int)$r['n'];
} catch (Throwable $e) {}

$students = [];
try {
    $students = $pdo->query("SELECT id, first_name, last_name FROM students WHERE COALESCE(is_active,1)=1 ORDER BY first_name, last_name")->fetchAll();
} catch (Throwable $e) {}

$pageTitle = 'Logbook';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Logbook</h1>
        <p class="muted"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?><?= $fType ? ' · ' . e(logbook_type_label($fType)) : '' ?></p>
    </div>
    <div class="actionbar">
        <a class="btn btn-primary" href="/logbook/add.php<?= $fType ? '?type=' . e($fType) : '' ?>">+ New entry</a>
    </div>
</div>

<!-- Quick add chips per type -->
<div class="log-type-chips">
    <?php foreach (logbook_types() as $code => $t): ?>
        <a class="log-chip" href="/logbook/add.php?type=<?= e($code) ?>">
            <span class="log-chip-icon"><?= $t['icon'] ?></span>
            <span><?= e($t['label']) ?></span>
            <?php if (!empty($todayCounts[$code])): ?><span class="pill"><?= (int)$todayCounts[$code] ?> today</span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="type">Type</label>
        <select id="type" name="type">
            <option value="">All types</option>
            <?php foreach (logbook_types() as $code => $t): ?>
                <option value="<?= e($code) ?>" <?= $fType === $code ? 'selected' : '' ?>><?= $t['icon'] ?> <?= e($t['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="student">Student</label>
        <select id="student" name="student">
            <option value="0">Any / none</option>
            <?php foreach ($students as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $fStudent === (int)$s['id'] ? 'selected' : '' ?>>
                    <?= e(trim($s['first_name'] . ' ' . ($s['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($fFrom) ?>"></div>
    <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($fTo) ?>"></div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-ghost" href="/logbook/index.php">Reset</a>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="empty"><p>No log entries match. <a href="/logbook/add.php">Add the first one</a>.</p></div>
<?php else: ?>
    <ul class="log-list" role="list">
        <?php foreach ($rows as $r):
            $meta = logbook_meta($r['meta_json']);
            $studentName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        ?>
            <li class="log-item">
                <a class="log-item-link" href="/logbook/view.php?id=<?= (int)$r['id'] ?>">
                    <span class="log-item-icon"><?= logbook_type_icon($r['log_type']) ?></span>
                    <div class="log-item-body">
                        <div class="log-item-head">
                            <span class="pill"><?= e(logbook_type_label($r['log_type'])) ?></span>
                            <?php if ($studentName !== ''): ?><strong><?= e($studentName) ?></strong><?php endif; ?>
                            <?php if ($r['title']): ?><span><?= e($r['title']) ?></span><?php endif; ?>
                            <?php if ($r['parent_notified']): ?><span class="pill pill-ok">parent notified</span><?php endif; ?>
                        </div>
                        <?php if ($r['details']): ?>
                            <div class="muted small"><?= e(mb_strimwidth((string)$r['details'], 0, 140, '…')) ?></div>
                        <?php endif; ?>
                        <div class="muted small log-item-meta">
                            <?= e(date('j M Y · H:i', strtotime($r['occurred_at']))) ?>
                            <?php if ($r['by_name']): ?> · <?= e($r['by_name']) ?><?php endif; ?>
                        </div>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
