<?php
/**
 * crm/audit.php — admin-only global audit log for the admissions module.
 *
 * Filterable by user, action, and date range. Most recent first.
 * Per-family audit lives on /crm/view.php's "Activity log" card.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

require_admin();

$filters = [
    'user_id' => isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0,
    'action'  => trim((string)($_GET['action'] ?? '')),
    'from'    => trim((string)($_GET['from']   ?? '')),
    'to'      => trim((string)($_GET['to']     ?? '')),
];

$where  = ['1=1'];
$params = [];
if ($filters['user_id'] > 0) { $where[] = 'a.user_id = :uid';   $params[':uid'] = $filters['user_id']; }
if ($filters['action']  !== '' && array_key_exists($filters['action'], crm_audit_actions())) {
    $where[] = 'a.action = :act';
    $params[':act'] = $filters['action'];
}
if ($filters['from'] !== '') { $where[] = 'a.created_at >= :from'; $params[':from'] = $filters['from'] . ' 00:00:00'; }
if ($filters['to']   !== '') { $where[] = 'a.created_at <= :to';   $params[':to']   = $filters['to']   . ' 23:59:59'; }

$sql = "
    SELECT a.*, u.name AS by_name, f.primary_name AS family_name
    FROM inquiry_audit a
    LEFT JOIN users u            ON u.id = a.user_id
    LEFT JOIN inquiry_families f ON f.id = a.family_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 500
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$users = db()->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll();

$pageTitle = 'Admissions audit log';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Admissions audit log</h1>
        <p class="muted">Admin-only · last 500 events matching the filter.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">← Pipeline</a>
    </div>
</div>

<section class="card">
    <form method="get" class="row" style="align-items: end;">
        <div class="field">
            <label>User</label>
            <select name="user_id">
                <option value="0">— anyone —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $filters['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Action</label>
            <select name="action">
                <option value="">— any —</option>
                <?php foreach (crm_audit_actions() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $filters['action'] === $code ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>From</label>
            <input name="from" type="date" value="<?= e($filters['from']) ?>">
        </div>
        <div class="field">
            <label>To</label>
            <input name="to"   type="date" value="<?= e($filters['to'])   ?>">
        </div>
        <div class="actions">
            <button class="btn btn-primary">Filter</button>
            <a class="link-btn" href="/crm/audit.php">Reset</a>
        </div>
    </form>
</section>

<section class="card">
    <?php if (!$rows): ?>
        <p class="muted">No audit entries match.</p>
    <?php else: ?>
        <table class="data-table audit-table">
            <thead>
                <tr>
                    <th style="width:9.5rem;">When</th>
                    <th>Action</th>
                    <th>By</th>
                    <th>Family</th>
                    <th>Details</th>
                    <th class="muted">IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $a):
                $meta = $a['meta_json'] ? json_decode($a['meta_json'], true) : null;
                $metaText = '';
                if (is_array($meta)) {
                    $parts = [];
                    foreach ($meta as $k => $v) {
                        if (is_scalar($v)) $parts[] = e($k) . '=' . e((string)$v);
                    }
                    $metaText = implode(' · ', $parts);
                }
            ?>
                <tr>
                    <td>
                        <strong><?= e(date('j M', strtotime($a['created_at']))) ?></strong>
                        <span class="muted small"><?= e(date('H:i', strtotime($a['created_at']))) ?></span>
                        <div class="muted small"><?= e(date('Y', strtotime($a['created_at']))) ?></div>
                    </td>
                    <td><span class="pill"><?= e(crm_audit_action_label($a['action'])) ?></span></td>
                    <td><?= e($a['by_name'] ?: '—') ?></td>
                    <td>
                        <?php if ($a['family_id']): ?>
                            <a href="/crm/view.php?id=<?= (int)$a['family_id'] ?>"><?= e($a['family_name'] ?: '#'.(int)$a['family_id']) ?></a>
                        <?php elseif (is_array($meta) && !empty($meta['primary_name'])): ?>
                            <span class="muted"><?= e($meta['primary_name']) ?> <small>(deleted)</small></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="muted small"><?= $metaText ?: '—' ?></td>
                    <td class="muted small"><?= e((string)$a['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
