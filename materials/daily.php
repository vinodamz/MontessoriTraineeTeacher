<?php
/**
 * materials/daily.php — today's material walk (daily, not monthly).
 *
 * Assigned as a duty (action_key=materials_check): the person opens this
 * page from My duties. UNIQUE (material_id, check_date) means a new day
 * has zero rows — a clean sheet — while yesterday stays in history.
 *
 *   GET                 today's list
 *   POST op=bulk_mark   no-JS save
 *   POST op=bulk_good   mark remaining Good
 *   POST op=ajax_mark   one-row autosave
 *   POST op=ajax_media  photo / video / voice memo
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';
require_once __DIR__ . '/../includes/duties.php';

$user  = mm_require_daily_check();
$today = date('Y-m-d');
$canMonthly = user_has_module($user, 'materials');
$isAdmin = ($user['role'] ?? '') === 'admin';

function mm_daily_json_fail(int $code, string $error): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'file too large for the server (limit ' . ini_get('post_max_size') . ') — record a shorter video']);
        exit;
    }
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'ajax_mark') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $mid  = (int)($_POST['material_id'] ?? 0);
            $code = (string)($_POST['condition_code'] ?? '');
            if ($mid <= 0 || !isset(mm_conditions()[$code])) {
                mm_daily_json_fail(400, 'pick a condition first');
            }
            $notes = array_key_exists('notes', $_POST) ? trim((string)$_POST['notes']) : '';
            $checkId = mm_daily_save_check($mid, $today, $code, $notes, (int)$user['id']);
            mm_daily_sync_duties((int)$user['id'], $today);
            $mediaN = 0;
            try {
                $mediaN = (int)db()->query("SELECT COUNT(*) FROM mm_daily_media WHERE check_id = " . (int)$checkId)->fetchColumn();
            } catch (Throwable $e) {}
            $sum = mm_daily_summary($today);
            echo json_encode([
                'ok'      => true,
                'check_id'=> $checkId,
                'label'   => mm_condition_label($code),
                'tone'    => mm_condition_tone($code),
                'media'   => $mediaN,
                'by'      => (string)$user['name'],
                'at'      => date('g:ia'),
                'pending' => (int)$sum['pending'],
                'checked' => (int)$sum['checked'],
                'total'   => (int)$sum['total_active'],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            mm_daily_json_fail(500, $e->getMessage());
        }
        exit;
    }

    if ($op === 'ajax_media') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $mid = (int)($_POST['material_id'] ?? 0);
            $chk = db()->prepare("SELECT id FROM mm_daily_checks WHERE material_id = :m AND check_date = :d");
            $chk->execute([':m' => $mid, ':d' => $today]);
            $checkId = (int)$chk->fetchColumn();
            if ($checkId <= 0) {
                mm_daily_json_fail(400, 'mark a condition first, then attach the photo');
            }
            $id = mm_daily_media_store($_FILES['media'] ?? [], $checkId, (int)$user['id']);
            if ($id === null) mm_daily_json_fail(400, 'no file received');
            $kind = db()->query("SELECT kind FROM mm_daily_media WHERE id = " . (int)$id)->fetchColumn();
            echo json_encode(['ok' => true, 'media_id' => $id, 'kind' => $kind, 'url' => '/materials/media.php?id=' . $id . '&daily=1']);
        } catch (Throwable $e) {
            mm_daily_json_fail(400, $e->getMessage());
        }
        exit;
    }

    if ($op === 'bulk_mark') {
        $existing = mm_daily_existing($today);
        $conds  = $_POST['cond']  ?? [];
        $notes  = $_POST['notes'] ?? [];
        $saved  = 0;
        foreach ($conds as $mid => $code) {
            $mid  = (int)$mid;
            $code = trim((string)$code);
            if ($mid <= 0 || $code === '' || !isset(mm_conditions()[$code])) continue;
            $note = trim((string)($notes[$mid] ?? ''));
            $prev = $existing[$mid] ?? null;
            if ($prev !== null && $prev['condition_code'] === $code && (string)$prev['notes'] === $note) {
                continue;
            }
            mm_daily_save_check($mid, $today, $code, $note, (int)$user['id']);
            $saved++;
        }
        mm_daily_sync_duties((int)$user['id'], $today);
        flash_set('ok', $saved > 0 ? "Saved $saved material" . ($saved === 1 ? '' : 's') . " for today." : 'Nothing changed.');
        redirect('/materials/daily.php');
    }

    if ($op === 'bulk_good') {
        $scope  = trim((string)($_POST['scope'] ?? ''));
        $where  = "m.is_active = 1 AND NOT EXISTS (SELECT 1 FROM mm_daily_checks c WHERE c.material_id = m.id AND c.check_date = :d)";
        $params = [':d' => $today];
        if ($scope !== '') { $where .= ' AND m.location = :loc'; $params[':loc'] = $scope; }
        $st = db()->prepare("SELECT m.id FROM mm_materials m WHERE $where");
        $st->execute($params);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $mid) {
            mm_daily_save_check((int)$mid, $today, 'good', null, (int)$user['id']);
        }
        mm_daily_sync_duties((int)$user['id'], $today);
        $label = $scope !== '' ? $scope : 'all shelves';
        flash_set('ok', count($ids) . ' unmarked material' . (count($ids) === 1 ? '' : 's') . " in $label marked Good.");
        redirect('/materials/daily.php');
    }
}

$q   = trim((string)($_GET['q'] ?? ''));
$loc = trim((string)($_GET['loc'] ?? ''));

$where  = ['m.is_active = 1'];
$params = [':d' => $today];
if ($q !== '')   { $where[] = 'm.name LIKE :q';    $params[':q']   = '%' . $q . '%'; }
if ($loc !== '') { $where[] = 'm.location = :loc'; $params[':loc'] = $loc; }
$whereSql = implode(' AND ', $where);

$rows = db()->prepare("
    SELECT m.id, m.name, m.location, m.sort_order,
           c.id AS check_id, c.condition_code, c.notes, c.checked_at, u.name AS checked_by
    FROM mm_materials m
    LEFT JOIN mm_daily_checks c ON c.material_id = m.id AND c.check_date = :d
    LEFT JOIN users u ON u.id = c.checked_by_user_id
    WHERE $whereSql
    ORDER BY m.location, m.sort_order, m.name
");
$rows->execute($params);
$materials = $rows->fetchAll();

$byLoc = [];
foreach ($materials as $m) $byLoc[$m['location']][] = $m;

$locations = db()->query("SELECT location FROM mm_materials WHERE is_active = 1 GROUP BY location ORDER BY MIN(sort_order)")->fetchAll(PDO::FETCH_COLUMN);
$summary   = mm_daily_summary($today);
$mediaN    = mm_daily_media_counts($today);
$latest    = mm_daily_latest_media($today);
$TONE_BG   = ['ok' => '#dff1d3;color:#2d6526', 'warn' => '#fcebc6;color:#6c4612', 'bad' => '#fbdcd8;color:#8b1c14'];

$assigned = [];
if (duty_tables_ready()) {
    foreach (duty_templates(true) as $tpl) {
        if ((string)($tpl['action_key'] ?? '') !== MM_DAILY_DUTY_ACTION) continue;
        $names = [];
        $nameById = [];
        foreach (duty_people() as $p) $nameById[(int)$p['id']] = (string)$p['name'];
        foreach (duty_assignee_ids($tpl) as $uid) {
            if (isset($nameById[$uid])) $names[] = $nameById[$uid];
        }
        $assigned[] = [
            'id'    => (int)$tpl['id'],
            'title' => (string)$tpl['title'],
            'when'  => duty_schedule_label($tpl),
            'who'   => $names ? implode(', ', $names) : duty_audience_label((string)$tpl['audience']),
        ];
    }
}

$pageTitle = 'Daily material check — ' . mm_daily_date_label($today);
require __DIR__ . '/../includes/header.php';
?>

<style>
.mmd2-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: .6rem; margin-bottom: 1rem; }
.mmd2-tile  { background: #fff; border: 1px solid #eee; border-radius: 12px; padding: .6rem .8rem; }
.mmd2-tile .v { font-size: 1.4rem; font-weight: 700; display: block; }
.mmd2-tile .l { color: #777; font-size: .78rem; }
.mm-list  { display: flex; flex-direction: column; }
.mm-item  { padding: .6rem .2rem; border-bottom: 1px solid #eee; }
.mm-item:last-child { border-bottom: 0; }
.mm-top   { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; margin-bottom: .35rem; }
.mm-thumb { flex: 0 0 auto; width: 44px; height: 44px; border-radius: 8px; overflow: hidden;
            border: 1px solid #ddd; display: flex; align-items: center; justify-content: center;
            background: #f4f4f0; text-decoration: none; }
.mm-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mm-name  { flex: 1 1 12rem; }
.mm-status { display: inline-flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
.mm-controls { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }
.mm-controls .mm-cond { flex: 1 1 11rem; max-width: 16rem; }
.mm-controls .mm-note { flex: 2 1 14rem; }
.mm-upmsg:empty { display: none; }
.mm-upbar { display: block; max-width: 20rem; height: 8px; border-radius: 5px; background: #eee; overflow: hidden; margin-top: .25rem; }
.mm-upbar i { display: block; height: 100%; background: #2d6ba0; }
.mm-pending { position: relative; width: 44px; height: 44px; border-radius: 8px; overflow: hidden;
              border: 2px solid #e0a020; display: inline-flex; align-items: center; justify-content: center;
              background: #fff8e6; font-size: 1.1rem; }
.mm-pending img { width: 100%; height: 100%; object-fit: cover; display: block; opacity: .85; }
@media (pointer: coarse), (max-width: 640px) {
    .mm-controls .mm-cond, .mm-controls .mm-note { min-height: 44px; font-size: 1rem; }
    .mm-snap { min-height: 44px; min-width: 48px; }
    .mm-item { padding: .75rem .1rem; }
}
</style>

<div class="page-head">
    <div>
        <h1>Daily material check</h1>
        <p class="muted">
            <strong><?= e(mm_daily_date_label($today)) ?></strong> ·
            <span id="mmProgress"><?= (int)$summary['checked'] ?>/<?= (int)$summary['total_active'] ?> checked today</span>
            · blank again tomorrow
        </p>
    </div>
    <div class="head-actions">
        <?php if ($isAdmin): ?>
            <a class="btn btn-primary" href="/duties/admin.php?view=edit&amp;preset=materials_check">Assign someone</a>
        <?php endif; ?>
        <?php if ($canMonthly): ?>
            <a class="btn btn-ghost" href="daily_history.php">Audit history</a>
            <a class="btn btn-ghost" href="daily_trends.php">Trends</a>
            <a class="btn btn-ghost" href="index.php">Monthly board</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="/duties/index.php">My duties</a>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="card" style="margin-bottom:1rem;">
    <?php if ($assigned): ?>
        <p style="margin:0 0 .4rem;"><strong>Who walks the shelves</strong></p>
        <ul style="margin:0;">
            <?php foreach ($assigned as $a): ?>
                <li>
                    <a href="/duties/admin.php?view=edit&amp;id=<?= (int)$a['id'] ?>"><?= e($a['title']) ?></a>
                    <span class="muted small"> · <?= e($a['when']) ?> · <?= e($a['who']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="muted small" style="margin:.5rem 0 0;">Daily = new blank sheet every calendar day. Weekly = they see the task once a week, still opening today’s blank sheet.</p>
    <?php else: ?>
        <p style="margin:0;">Nobody is assigned yet. <a href="/duties/admin.php?view=edit&amp;preset=materials_check">Create a daily or weekly materials-check task</a> and pick the person. They do not need the Materials module — the task on My duties is enough.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="mmd2-tiles">
    <div class="mmd2-tile"><span class="v" id="mmChecked"><?= (int)$summary['checked'] ?></span><span class="l">Checked today</span></div>
    <div class="mmd2-tile"><span class="v" id="mmPending"><?= (int)$summary['pending'] ?></span><span class="l">Not yet checked</span></div>
    <div class="mmd2-tile"><span class="v" style="color:#2d6526"><?= (int)($summary['by_tone']['ok'] ?? 0) ?></span><span class="l">Good condition</span></div>
    <div class="mmd2-tile"><span class="v" style="color:#6c4612"><?= (int)($summary['by_tone']['warn'] ?? 0) ?></span><span class="l">Needs attention</span></div>
    <div class="mmd2-tile"><span class="v" style="color:#8b1c14"><?= (int)($summary['by_tone']['bad'] ?? 0) ?></span><span class="l">Damaged / missing</span></div>
    <div class="mmd2-tile"><span class="v"><?= (int)$summary['notes_count'] ?></span><span class="l">Notes logged</span></div>
</div>

<form class="filters no-print" method="get" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <input type="search" name="q" placeholder="Search material…" value="<?= e($q) ?>">
    <select name="loc">
        <option value="">All shelves</option>
        <?php foreach ($locations as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $loc ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn">Filter</button>
    <a class="btn btn-ghost" href="daily.php">Reset</a>
</form>

<?php if (!$materials): ?>
    <div class="empty"><p>No materials match. <a href="daily.php">Clear filters</a>.</p></div>
<?php else: ?>

<form method="post" id="bulkForm" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="bulk_mark">

    <?php foreach ($byLoc as $location => $items):
        $shelfMarked = 0;
        foreach ($items as $it) if ($it['condition_code'] !== null) $shelfMarked++;
    ?>
    <section class="card" style="margin-bottom:1rem;">
        <div class="card-head" style="display:flex; justify-content:space-between; align-items:center; gap:.6rem; flex-wrap:wrap;">
            <h2 style="margin:0;"><?= e($location) ?>
                <span class="muted small">· <?= $shelfMarked ?>/<?= count($items) ?> checked</span></h2>
            <?php if ($shelfMarked < count($items)): ?>
                <button class="btn btn-ghost small" type="submit" form="goodForm"
                        name="scope" value="<?= e($location) ?>"
                        title="Every material on this shelf with no mark today becomes Good."
                        onclick="return confirm('Mark all still-unchecked materials in <?= e(addslashes($location)) ?> as Good?')">
                    rest are Good ✓
                </button>
            <?php endif; ?>
        </div>
        <div class="mm-list">
            <?php foreach ($items as $m): $marked = $m['condition_code'] !== null;
                $mid = (int)$m['id'];
                $nMedia = (int)($mediaN[$mid] ?? 0);
                $photo = $latest[$mid]['photo'] ?? null;
                $video = $latest[$mid]['video'] ?? null;
            ?>
            <div class="mm-item" id="m<?= $mid ?>" data-id="<?= $mid ?>" data-saved="<?= e((string)$m['condition_code']) ?>">
                <div class="mm-top">
                    <?php if ($photo): ?>
                        <a class="mm-thumb" href="/materials/media.php?id=<?= (int)$photo['id'] ?>&amp;daily=1" target="_blank" rel="noopener">
                            <img src="/materials/media.php?id=<?= (int)$photo['id'] ?>&amp;daily=1&amp;thumb=1" alt="" loading="lazy">
                        </a>
                    <?php elseif ($video): ?>
                        <span class="mm-thumb" title="Video attached">🎥</span>
                    <?php endif; ?>
                    <strong class="mm-name"><?= e($m['name']) ?></strong>
                    <span class="pill small mm-media-pill" <?= $nMedia > 0 ? '' : 'hidden' ?>>📎 <span class="mm-media-n"><?= $nMedia ?></span></span>
                    <span class="mm-status muted small">
                        <?php if ($marked): ?>
                            <span class="pill small" style="background:<?= $TONE_BG[mm_condition_tone($m['condition_code'])] ?? '#eee' ?>"><?= e(mm_condition_label($m['condition_code'])) ?></span>
                            ✓ <?= e($m['checked_by'] ?? 'Unknown') ?> · <?= e(date('g:ia', strtotime((string)$m['checked_at']))) ?>
                        <?php else: ?>
                            <span style="color:#b3261e">not checked today</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mm-controls">
                    <select name="cond[<?= $mid ?>]" class="mm-cond">
                        <option value="">— condition —</option>
                        <?php foreach (mm_conditions() as $code => $meta): ?>
                            <option value="<?= e($code) ?>" <?= $m['condition_code'] === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="mm-note" name="notes[<?= $mid ?>]" maxlength="500"
                           placeholder="Comment (optional)" value="<?= e((string)($m['notes'] ?? '')) ?>">
                    <button type="button" class="btn btn-ghost mm-snap" data-kind="photo" title="Take a photo">📷</button>
                    <button type="button" class="btn btn-ghost mm-snap" data-kind="video" title="Record a video">🎥</button>
                    <input type="file" class="mm-cam mm-cam-photo" accept="image/*" capture="environment" hidden>
                    <input type="file" class="mm-cam mm-cam-video" accept="video/*" capture="environment" hidden>
                    <input type="file" class="mm-file" accept="image/*,video/*" hidden>
                    <button type="button" class="btn btn-ghost mm-gallery" title="Upload from gallery">🖼</button>
                </div>
                <div class="mm-upmsg muted small"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="no-print" style="position:sticky; bottom:0; background:var(--card-bg, #fff); border-top:2px solid #ddd; padding:.6rem .8rem; display:flex; gap:.7rem; align-items:center; z-index:50;">
        <button class="btn btn-primary" type="submit">Save today's check</button>
        <span id="pendingCount" class="muted small">Changes save as you mark them.</span>
        <span id="uploadCount" class="small" style="color:#2d6ba0;"></span>
    </div>
</form>

<form method="post" id="goodForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="bulk_good">
</form>

<script>window.MM_DAILY = { uploadLimit: <?= mm_effective_upload_limit() ?> };</script>
<script src="/assets/js/materials-daily.js?v=<?= e(asset_version()) ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
