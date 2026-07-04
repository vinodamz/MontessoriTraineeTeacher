<?php
/**
 * materials/share.php — manage Kreedo share links + see who viewed them.
 *
 *   GET  ?period=YYYY-MM      list links for the period + their view logs
 *   POST op=create            issue a new link (label optional)
 *   POST op=toggle            revoke / re-enable a link
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$period = mm_current_period();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        $token = bin2hex(random_bytes(32));
        db()->prepare("
            INSERT INTO mm_share_links (token, period, label, created_by_user_id)
            VALUES (:t, :p, :l, :u)
        ")->execute([
            ':t' => $token, ':p' => $period,
            ':l' => mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120),
            ':u' => (int)$user['id'],
        ]);
        flash_set('ok', 'Share link created — copy it below and send it to Kreedo.');
        redirect('/materials/share.php?period=' . urlencode($period));
    }

    if ($op === 'toggle') {
        db()->prepare("UPDATE mm_share_links SET is_active = 1 - is_active WHERE id = :id")
            ->execute([':id' => (int)($_POST['id'] ?? 0)]);
        flash_set('ok', 'Link updated.');
        redirect('/materials/share.php?period=' . urlencode($period));
    }
}

$links = db()->prepare("
    SELECT l.*, u.name AS created_by,
           (SELECT COUNT(*) FROM mm_share_views v WHERE v.share_id = l.id) AS views
    FROM mm_share_links l
    LEFT JOIN users u ON u.id = l.created_by_user_id
    WHERE l.period = :p
    ORDER BY l.created_at DESC
");
$links->execute([':p' => $period]);
$links = $links->fetchAll();

$viewsByLink = [];
if ($links) {
    $ids = array_column($links, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $vs = db()->prepare("SELECT * FROM mm_share_views WHERE share_id IN ($place) ORDER BY viewed_at DESC LIMIT 300");
    $vs->execute($ids);
    foreach ($vs as $v) $viewsByLink[(int)$v['share_id']][] = $v;
}

$base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mtt.thelittlegraduates.in');
$periods = mm_period_options();

$pageTitle = 'Share with Kreedo — ' . mm_period_label($period);
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Share with Kreedo</h1>
        <p class="muted"><strong><?= e(mm_period_label($period)) ?></strong> · read-only replacement report with photos; every open is logged with the viewer's name</p>
    </div>
    <div class="head-actions"><a class="btn btn-ghost" href="dashboard.php?period=<?= e($period) ?>">← Dashboard</a></div>
</div>

<form class="filters no-print" method="get" style="margin-bottom:1rem;">
    <label>Month
        <select name="period" onchange="this.form.submit()">
            <?php foreach ($periods as $p): ?>
                <option value="<?= e($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= e(mm_period_label($p)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <noscript><button class="btn">Go</button></noscript>
</form>

<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Create a link</h2>
    <form method="post" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:end;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <div class="field" style="flex:1 1 16rem;">
            <label>Label <span class="muted small">(who it's for — e.g. "Kreedo — Anil")</span></label>
            <input name="label" maxlength="120" placeholder="Kreedo">
        </div>
        <button class="btn btn-primary">Create share link</button>
    </form>
    <p class="muted small" style="margin:.5rem 0 0;">The link shows only <?= e(mm_period_label($period)) ?>'s replacement list + photos. First-time viewers are asked their name; every open lands in the log below. Revoke any time.</p>
</section>

<?php foreach ($links as $l): $url = $base . '/materials/shared.php?t=' . $l['token']; ?>
<section class="card" style="margin-bottom:1rem; <?= $l['is_active'] ? '' : 'opacity:.6;' ?>">
    <div style="display:flex; gap:.6rem; align-items:center; flex-wrap:wrap;">
        <strong><?= e($l['label'] !== '' ? $l['label'] : 'Share link') ?></strong>
        <span class="pill small" style="background:<?= $l['is_active'] ? '#dff1d3;color:#2d6526' : '#fbdcd8;color:#8b1c14' ?>"><?= $l['is_active'] ? 'active' : 'revoked' ?></span>
        <span class="muted small">created <?= e(date('j M, g:ia', strtotime($l['created_at']))) ?> by <?= e($l['created_by'] ?? '?') ?> · <?= (int)$l['views'] ?> view<?= (int)$l['views'] === 1 ? '' : 's' ?></span>
        <form method="post" class="inline" style="margin-left:auto;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="toggle">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-ghost small"><?= $l['is_active'] ? 'Revoke' : 'Re-enable' ?></button>
        </form>
    </div>
    <?php if ($l['is_active']): ?>
    <div style="display:flex; gap:.4rem; align-items:center; margin:.5rem 0;">
        <input type="text" readonly value="<?= e($url) ?>" id="lnk<?= (int)$l['id'] ?>"
               style="flex:1; font-size:.85rem; padding:.45rem;" onclick="this.select()">
        <button type="button" class="btn small"
                onclick="navigator.clipboard ? navigator.clipboard.writeText(document.getElementById('lnk<?= (int)$l['id'] ?>').value).then(() => this.textContent='✓ copied') : document.getElementById('lnk<?= (int)$l['id'] ?>').select()">Copy</button>
        <a class="btn btn-ghost small" href="https://wa.me/?text=<?= e(urlencode('Material replacement report — ' . mm_period_label($period) . ': ' . $url)) ?>" target="_blank" rel="noopener">WhatsApp</a>
    </div>
    <?php endif; ?>
    <?php $vs = $viewsByLink[(int)$l['id']] ?? []; ?>
    <?php if ($vs): ?>
        <details <?= count($vs) <= 6 ? 'open' : '' ?>>
            <summary class="small" style="cursor:pointer;">Who viewed (<?= (int)$l['views'] ?>)</summary>
            <div class="table-scroll">
            <table class="admin-table">
                <thead><tr><th>Name</th><th>When</th><th>From</th></tr></thead>
                <tbody>
                <?php foreach ($vs as $v): ?>
                    <tr>
                        <td><?= e($v['viewer_name']) ?></td>
                        <td class="muted small"><?= e(date('j M Y, g:ia', strtotime($v['viewed_at']))) ?></td>
                        <td class="muted small"><?= e($v['ip']) ?> · <?= e(mb_substr($v['user_agent'], 0, 60)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
    <?php else: ?>
        <p class="muted small" style="margin:.3rem 0 0;">Nobody has opened this link yet.</p>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
