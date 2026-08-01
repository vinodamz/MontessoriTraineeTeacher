<?php
/**
 * feedback/index.php — Parent feedback & discussion log (admin-only).
 *
 * Lists feedback entries with a status filter and free-text search. Each row
 * links to the detail page where the discussion history lives.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/feedback.php';

$user = require_admin();
$pdo  = db();

$fStatus = $_GET['status'] ?? '';
$q       = trim((string)($_GET['q'] ?? ''));
if (!array_key_exists($fStatus, feedback_statuses())) $fStatus = '';

$where = ['1=1'];
$params = [];
if ($fStatus !== '') { $where[] = 'f.status = :st'; $params[':st'] = $fStatus; }
if ($q !== '') {
    // A named placeholder may appear only once per statement: db() runs with
    // EMULATE_PREPARES off, and a native prepare rejects a repeat.
    $where[] = '(f.parent_comments LIKE :q1 OR f.parent_name LIKE :q2
                 OR s.first_name LIKE :q3 OR s.last_name LIKE :q4)';
    $like = '%' . $q . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}

$rows = [];
try {
    $sql = "
        SELECT f.*, s.first_name AS s_first, s.last_name AS s_last,
               (SELECT COUNT(*) FROM parent_feedback_log l WHERE l.feedback_id = f.id) AS log_n
        FROM parent_feedback f
        LEFT JOIN students s ON s.id = f.student_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY f.entry_date DESC, f.id DESC
        LIMIT 500
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    // Table not migrated yet — show an empty state rather than a 500.
}

// Status counts for the filter chips.
$counts = [];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM parent_feedback GROUP BY status") as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }
} catch (Throwable $e) {}

$pageTitle = 'Parent feedback';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Parent feedback &amp; discussion</h1>
        <p class="muted">A log of parent feedback, the discussions it prompted, and how each was resolved.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-primary" href="/feedback/edit.php">+ New feedback</a>
    </div>
</div>

<div class="card">
    <form method="get" class="row" style="align-items:flex-end;">
        <div class="field">
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (feedback_statuses() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $fStatus === $code ? 'selected' : '' ?>>
                        <?= e($label) ?><?= isset($counts[$code]) ? ' (' . (int)$counts[$code] . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex:2 1 260px;">
            <label>Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Parent name, child, or words in the comment">
        </div>
        <div class="actions">
            <button class="btn" type="submit">Filter</button>
            <?php if ($fStatus !== '' || $q !== ''): ?><a class="btn btn-ghost" href="/feedback/index.php">Clear</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>About</th>
                <th>Feedback</th>
                <th>Status</th>
                <th>History</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="muted">No feedback logged yet. <a href="/feedback/edit.php">Add the first entry →</a></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $about = feedback_about($r);
                $snippet = trim((string)$r['parent_comments']);
                if (mb_strlen($snippet) > 90) $snippet = mb_substr($snippet, 0, 90) . '…';
            ?>
                <tr>
                    <td><?= e(date('j M Y', strtotime((string)$r['entry_date']))) ?></td>
                    <td><?= e($about) ?></td>
                    <td class="muted"><?= e($snippet) ?></td>
                    <td><span class="pill"><?= e(feedback_statuses()[$r['status']] ?? $r['status']) ?></span></td>
                    <td><?= (int)$r['log_n'] ?> entr<?= (int)$r['log_n'] === 1 ? 'y' : 'ies' ?></td>
                    <td><a class="btn btn-ghost" href="/feedback/view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
