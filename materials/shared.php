<?php
/**
 * materials/shared.php — PUBLIC read-only view of one month's material audit,
 * reached via a share-link token (for Kreedo). No login.
 *
 * First visit asks the viewer's name (kept in a cookie for a year); every
 * page view is logged (name, IP, user agent, time) so the school can see
 * who opened the link. Content is strictly the shared month: summary,
 * replacement list grouped by shelf, notes and media thumbnails.
 *
 *   GET  ?t=<token>
 *   POST ?t=<token> (viewer_name) → set cookie, log, show page
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$token = (string)($_GET['t'] ?? '');
$share = mm_share_by_token($token);
if (!$share) { http_response_code(404); exit('This link is not active. Please ask the school for a new one.'); }

$period  = (string)$share['period'];
$shareId = (int)$share['id'];

// ---- Viewer identity (asked once, cookie for a year) -------------------------
$cookieName = 'mm_share_viewer';
$viewer = trim((string)($_COOKIE[$cookieName] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $viewer = trim((string)($_POST['viewer_name'] ?? ''));
    if ($viewer === '') { $viewer = 'Unnamed viewer'; }
    setcookie($cookieName, $viewer, [
        'expires' => time() + 86400 * 365, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    header('Location: /materials/shared.php?t=' . urlencode($token));
    exit;
}

$e = fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ---- Name gate ---------------------------------------------------------------
if ($viewer === '') {
    http_response_code(200);
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Material condition report</title>
    <style>body{font-family:system-ui,sans-serif;background:#f6f5f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
    .card{background:#fff;border-radius:14px;padding:1.6rem;max-width:24rem;width:100%;box-shadow:0 8px 30px rgba(0,0,0,.08)}
    input{width:100%;padding:.7rem;font-size:1rem;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;margin:.6rem 0 1rem}
    button{width:100%;padding:.8rem;font-size:1rem;background:#2d6ba0;color:#fff;border:0;border-radius:8px;cursor:pointer}</style></head>
    <body><form class="card" method="post" action="/materials/shared.php?t=<?= $e(urlencode($token)) ?>">
        <h2 style="margin:0 0 .3rem;">Material condition report</h2>
        <p style="color:#666;margin:0 0 .6rem;">The Little Graduates Playschool has shared this with you. Please tell us your name so the school knows who viewed it.</p>
        <input name="viewer_name" maxlength="120" required placeholder="Your name / company" autofocus>
        <button>View the report</button>
    </form></body></html><?php
    exit;
}

// Every view is logged — that's the point of the link.
mm_share_log_view($shareId, $viewer);

// ---- Data (read-only, shared month only) --------------------------------------
$stats = db()->prepare("
    SELECT COUNT(*) AS checked,
           SUM(needs_replacement = 1) AS flagged
    FROM mm_condition_checks WHERE period = :p
");
$stats->execute([':p' => $period]);
$stats = $stats->fetch();

$rows = db()->prepare("
    SELECT m.name, m.location, c.id AS check_id, c.condition_code, c.needs_replacement,
           c.replace_qty, c.notes, c.checked_at
    FROM mm_condition_checks c
    JOIN mm_materials m ON m.id = c.material_id
    WHERE c.period = :p AND c.needs_replacement = 1
    ORDER BY m.location, m.sort_order, m.name
");
$rows->execute([':p' => $period]);
$list = $rows->fetchAll();

$mediaByCheck = [];
if ($list) {
    $ids = array_column($list, 'check_id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $ms = db()->prepare("SELECT id, check_id, kind FROM mm_condition_media WHERE check_id IN ($place) ORDER BY uploaded_at");
    $ms->execute($ids);
    foreach ($ms as $r) $mediaByCheck[(int)$r['check_id']][] = $r;
}

$byLoc = [];
foreach ($list as $r) $byLoc[$r['location']][] = $r;

$TONE_BG = ['ok' => '#dff1d3;color:#2d6526', 'warn' => '#fcebc6;color:#6c4612', 'bad' => '#fbdcd8;color:#8b1c14'];
$periodLabel = mm_period_label($period);
$tq = '&t=' . urlencode($token);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Replacement list — <?= $e($periodLabel) ?></title>
<style>
body { font-family: system-ui, sans-serif; background: #f6f5f1; margin: 0; padding: 1rem; color: #222; }
.wrap { max-width: 60rem; margin: 0 auto; }
.card { background: #fff; border-radius: 12px; padding: 1rem 1.2rem; margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
.pill { display: inline-block; border-radius: 999px; padding: .15rem .6rem; font-size: .8rem; }
.muted { color: #777; } .small { font-size: .85rem; }
.gal { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .6rem; margin-top: .5rem; }
.gal img, .gal video { width: 100%; height: 110px; object-fit: cover; border-radius: 8px; display: block; background: #000; }
h1 { font-size: 1.3rem; } h2 { font-size: 1.05rem; border-bottom: 2px solid #eee; padding-bottom: .25rem; }
.item { padding: .6rem 0; border-bottom: 1px solid #f0f0ec; } .item:last-child { border-bottom: 0; }
@media print { .gal img, .gal video { height: 90px; } }
</style></head><body><div class="wrap">

<div class="card">
    <h1 style="margin:.1rem 0;">Material replacement list — <?= $e($periodLabel) ?></h1>
    <p class="muted" style="margin:.2rem 0;">The Little Graduates Playschool, Kaloor, Kochi · shared report<?= $share['label'] !== '' ? ' · ' . $e($share['label']) : '' ?></p>
    <p style="margin:.4rem 0 0;">
        <strong style="color:#b3261e;"><?= (int)$stats['flagged'] ?></strong> material<?= (int)$stats['flagged'] === 1 ? '' : 's' ?> need replacement
        <span class="muted small">· <?= (int)$stats['checked'] ?> checked this month · viewing as <?= $e($viewer) ?></span>
    </p>
</div>

<?php if (!$list): ?>
    <div class="card"><p class="muted">Nothing is flagged for replacement in <?= $e($periodLabel) ?> yet.</p></div>
<?php endif; ?>

<?php foreach ($byLoc as $location => $items): ?>
<div class="card">
    <h2><?= $e($location) ?> <span class="muted small">· <?= count($items) ?></span></h2>
    <?php foreach ($items as $r): ?>
        <div class="item">
            <strong><?= $e($r['name']) ?></strong>
            <span class="pill" style="background:<?= $TONE_BG[mm_condition_tone($r['condition_code'])] ?? '#eee' ?>"><?= $e(mm_condition_label($r['condition_code'])) ?></span>
            <span class="pill" style="background:#fbdcd8;color:#8b1c14;">qty <?= max(1, (int)$r['replace_qty']) ?></span>
            <?php if (trim((string)$r['notes']) !== ''): ?>
                <div class="small" style="margin-top:.3rem; background:#fffbe7; border:1px solid #ead9a0; border-radius:8px; padding:.4rem .6rem;">📝 <?= $e($r['notes']) ?></div>
            <?php endif; ?>
            <?php $mm = $mediaByCheck[(int)$r['check_id']] ?? []; ?>
            <?php if ($mm): ?>
                <div class="gal">
                <?php foreach ($mm as $md): $url = '/materials/media.php?id=' . (int)$md['id'] . $tq; ?>
                    <?php if ($md['kind'] === 'video'): ?>
                        <video src="<?= $e($url) ?>" controls preload="none"></video>
                    <?php elseif ($md['kind'] === 'audio'): ?>
                        <audio src="<?= $e($url) ?>" controls preload="none" style="width:100%;"></audio>
                    <?php else: ?>
                        <a href="<?= $e($url) ?>" target="_blank"><img src="<?= $e($url) ?>&thumb=1" alt="" loading="lazy"></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<p class="muted small" style="text-align:center; margin:1.5rem 0;">Generated by The Little Graduates school app · read-only report</p>
</div></body></html>
