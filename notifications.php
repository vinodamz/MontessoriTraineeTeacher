<?php
/**
 * notifications.php — your inbox.
 *
 *   GET             → list everything (most recent first).
 *   GET ?filter=unread  → only unread.
 *   POST op=read    → mark one as read.
 *   POST op=read_all → mark all as read.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

$user = require_login();
$me   = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';
    if ($op === 'read') {
        mark_read($me, (int)($_POST['id'] ?? 0));
    } elseif ($op === 'read_all') {
        $n = mark_all_read($me);
        flash_set('ok', $n > 0 ? "Marked $n as read." : 'No unread notifications.');
    }
    $back = $_POST['return'] ?? '/notifications.php';
    redirect($back);
}

$filter = $_GET['filter'] ?? 'all';
$onlyUnread = $filter === 'unread';

$where  = ['user_id = :uid'];
$params = [':uid' => $me];
if ($onlyUnread) $where[] = 'read_at IS NULL';

$stmt = db()->prepare("
    SELECT * FROM notifications
    WHERE " . implode(' AND ', $where) . "
    ORDER BY created_at DESC, id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalUnread = unread_count($me);

function cat_pill_class(string $cat): string
{
    return 'pill cat-' . $cat;
}

function nice_time(string $iso): string
{
    try {
        $d   = new DateTime($iso);
        $now = new DateTime('now');
        $diff = $now->getTimestamp() - $d->getTimestamp();
        if ($diff < 60)       return 'just now';
        if ($diff < 3600)     return floor($diff / 60) . 'm ago';
        if ($diff < 86400)    return floor($diff / 3600) . 'h ago';
        if ($diff < 7 * 86400) return floor($diff / 86400) . 'd ago';
        return $d->format('j M Y · H:i');
    } catch (Throwable $e) { return $iso; }
}

$pageTitle = 'Notifications';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Notifications</h1>
        <p class="muted">
            <?= count($rows) ?> shown
            <?php if ($totalUnread > 0): ?> · <span class="pill pill-warn"><?= $totalUnread ?> unread</span><?php endif; ?>
            · <a href="/notification_preferences.php">Preferences</a>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn <?= $filter === 'all'    ? 'btn-primary' : '' ?>" href="/notifications.php">All</a>
        <a class="btn <?= $filter === 'unread' ? 'btn-primary' : '' ?>" href="/notifications.php?filter=unread">Unread</a>
        <?php if ($totalUnread > 0): ?>
            <form method="post" class="inline">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="read_all">
                <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                <button class="btn" type="submit">Mark all read</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="empty">
        <p>
            <?php if ($onlyUnread): ?>
                Nothing unread. <a href="/notifications.php">See all</a>.
            <?php else: ?>
                You don't have any notifications yet. They'll appear here when tasks are assigned, students are enrolled or withdrawn, fees are added, etc.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <ul class="notif-list">
        <?php foreach ($rows as $n): ?>
            <li class="notif-row <?= $n['read_at'] ? '' : 'is-unread' ?>">
                <div class="notif-body">
                    <div class="notif-title">
                        <?php if (!empty($n['link'])): ?>
                            <a href="<?= e($n['link']) ?>"
                               onclick="markReadThenGo(event, <?= (int)$n['id'] ?>, '<?= e($n['link']) ?>')"><?= e($n['title']) ?></a>
                        <?php else: ?>
                            <?= e($n['title']) ?>
                        <?php endif; ?>
                        <span class="<?= cat_pill_class($n['category']) ?>"><?= e(ucfirst($n['category'])) ?></span>
                        <?php if (!$n['read_at']): ?><span class="pill pill-warn">new</span><?php endif; ?>
                    </div>
                    <?php if (!empty($n['body'])): ?>
                        <div class="notif-text"><?= nl2br(e($n['body'])) ?></div>
                    <?php endif; ?>
                    <div class="notif-meta muted small">
                        <?= e(nice_time($n['created_at'])) ?>
                        <?php if ($n['email_status'] === 'sent'): ?> · ✉ emailed<?php endif; ?>
                    </div>
                </div>
                <div class="notif-actions">
                    <?php if (!$n['read_at']): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="op" value="read">
                            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                            <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                            <button class="link-btn" type="submit" title="Mark as read">✓</button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<script>
function markReadThenGo(e, id, link) {
    // Fire and forget — don't block navigation.
    const fd = new FormData();
    fd.set('_csrf', <?= json_encode(csrf_token()) ?>);
    fd.set('op', 'read');
    fd.set('id', id);
    fd.set('return', '/notifications.php');
    navigator.sendBeacon
        ? navigator.sendBeacon('/notifications.php', fd)
        : fetch('/notifications.php', { method: 'POST', body: fd, keepalive: true });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
