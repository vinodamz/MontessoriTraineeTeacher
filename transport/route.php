<?php
/**
 * transport/route.php — admin: a route's children in pickup order, stop
 * notes and locations, private parent tracking links, Maps travel times.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

$user = require_admin();
$routeId = (int)($_GET['id'] ?? $_POST['route_id'] ?? 0);
$route = transport_route_get($routeId);
if (!$route) {
    http_response_code(404);
    exit('Route not found.');
}
$self = '/transport/route.php?id=' . $routeId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    $stopId = (int)($_POST['stop_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    try {
        if ($op === 'add') {
            transport_stop_add($routeId, $studentId, (string)($_POST['stop_note'] ?? ''));
            flash_set('ok', 'Child added to the route.');
        } elseif ($op === 'update') {
            transport_stop_update($stopId, (string)($_POST['stop_note'] ?? ''), (string)($_POST['lat'] ?? ''), (string)($_POST['lng'] ?? ''));
            flash_set('ok', 'Stop saved.');
        } elseif ($op === 'up' || $op === 'down') {
            transport_stop_move($stopId, $op);
        } elseif ($op === 'remove') {
            transport_stop_remove($stopId);
            flash_set('ok', 'Child removed from the route.');
        } elseif ($op === 'link') {
            transport_parent_link_ensure($studentId, (int)$user['id']);
            flash_set('ok', 'Tracking link ready.');
        } elseif ($op === 'revoke') {
            transport_parent_link_revoke($studentId);
            flash_set('ok', 'Tracking link turned off. Share a new one if needed.');
        } elseif ($op === 'maps') {
            $res = transport_maps_refresh_route($routeId);
            if ($res['error'] !== null && $res['updated'] === 0) {
                flash_set('error', $res['error']);
            } else {
                flash_set('ok', 'Travel times updated for ' . $res['updated'] . ' stops'
                    . ($res['skipped'] ? ' (' . $res['skipped'] . ' skipped — add locations to both ends).' : '.'));
            }
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($self . '#stops');
}

$stops = transport_route_stops($routeId);
$candidates = transport_route_candidates($routeId);
$hasKey = transport_maps_key() !== '';
$csrf = csrf_token();

$pageTitle = 'Route · ' . $route['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e((string)$route['name']) ?></h1>
        <p class="muted">
            <?= e(transport_direction_label((string)$route['direction'])) ?>
            · <?= $route['cab_name'] ? e((string)$route['cab_name']) : 'No cab assigned' ?>
            · Drop trips run this list in reverse.
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/transport/setup.php?route=<?= $routeId ?>#route-form">Edit route</a>
        <a class="btn btn-ghost" href="/transport/setup.php">← Setup</a>
    </div>
</div>

<div class="card" id="stops">
    <h2 style="margin-top:0">Pickup order</h2>
    <?php if (!$stops): ?><p class="muted">No children on this route yet.</p><?php endif; ?>
    <?php foreach ($stops as $i => $s):
        $name = trim($s['first_name'] . ' ' . $s['last_name']);
        $link = transport_parent_link_active((int)$s['student_id']);
        $url = $link ? transport_track_url((string)$link['token']) : '';
        $parents = transport_parents_with_phone((int)$s['student_id']);
    ?>
        <div class="transport-stop" style="padding:.75rem 0;border-bottom:1px solid var(--line,#eee)">
            <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;align-items:flex-start">
                <div>
                    <strong><?= $i + 1 ?>. <?= e($name) ?></strong>
                    <span class="muted small"> · <?= e((string)$s['grade']) ?></span>
                    <?php if ($s['student_transport'] !== null && $s['student_transport'] !== 'cab'): ?>
                        <span class="pill pill-warn">Profile says <?= e((string)$s['student_transport']) ?></span>
                    <?php endif; ?>
                    <div class="muted small">
                        <?= $s['stop_note'] !== null && $s['stop_note'] !== '' ? e((string)$s['stop_note']) : 'No stop note' ?>
                        <?php if ($s['lat'] !== null): ?> · location set<?php endif; ?>
                        <?php if ($s['leg_minutes'] !== null): ?> · ~<?= (int)$s['leg_minutes'] ?> min from previous<?php endif; ?>
                    </div>
                </div>
                <div class="row-actions">
                    <?php foreach (['up' => '↑', 'down' => '↓'] as $op => $label): ?>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="route_id" value="<?= $routeId ?>">
                            <input type="hidden" name="stop_id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-ghost btn-small" name="op" value="<?= $op ?>" title="Move <?= $op ?>"
                                <?= ($op === 'up' && $i === 0) || ($op === 'down' && $i === count($stops) - 1) ? 'disabled' : '' ?>><?= $label ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>

            <details style="margin-top:.5rem">
                <summary class="small">Stop details &amp; parent link</summary>
                <form method="post" style="margin-top:.5rem">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="route_id" value="<?= $routeId ?>">
                    <input type="hidden" name="stop_id" value="<?= (int)$s['id'] ?>">
                    <label>Stop note (only staff see this)
                        <input type="text" name="stop_note" maxlength="255" value="<?= e((string)$s['stop_note']) ?>" placeholder="e.g. Gate 2, Sobha apartments">
                    </label>
                    <label>Latitude <input type="text" name="lat" inputmode="decimal" value="<?= e((string)$s['lat']) ?>"></label>
                    <label>Longitude <input type="text" name="lng" inputmode="decimal" value="<?= e((string)$s['lng']) ?>"></label>
                    <div class="actionbar">
                        <button class="btn btn-primary btn-small" name="op" value="update">Save stop</button>
                        <button class="btn btn-ghost btn-small" name="op" value="remove"
                                onclick="return confirm('Remove this child from the route?')">Remove from route</button>
                    </div>
                </form>

                <div style="margin-top:.75rem">
                    <strong class="small">Parent tracking link</strong>
                    <?php if ($url === ''): ?>
                        <form method="post" style="margin:.25rem 0 0">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="route_id" value="<?= $routeId ?>">
                            <input type="hidden" name="student_id" value="<?= (int)$s['student_id'] ?>">
                            <button class="btn btn-ghost btn-small" name="op" value="link">Create link</button>
                        </form>
                    <?php else: ?>
                        <p class="small" style="word-break:break-all;margin:.25rem 0"><?= e($url) ?></p>
                        <div class="actionbar">
                            <button type="button" class="btn btn-ghost btn-small" data-copy="<?= e($url) ?>">Copy link</button>
                            <?php foreach ($parents as $p):
                                $text = 'Hi ' . $p['name'] . ', here is your private link to see when the school cab will reach '
                                      . $s['first_name'] . ': ' . $url . ' Please do not share it.';
                            ?>
                                <a class="btn btn-ghost btn-small" target="_blank" rel="noopener"
                                   href="<?= e(transport_wa_link((string)$p['phone'], $text)) ?>">WhatsApp <?= e((string)$p['relation'] ?: (string)$p['name']) ?></a>
                            <?php endforeach; ?>
                            <form method="post" style="margin:0">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="route_id" value="<?= $routeId ?>">
                                <input type="hidden" name="student_id" value="<?= (int)$s['student_id'] ?>">
                                <button class="btn btn-ghost btn-small" name="op" value="revoke"
                                        onclick="return confirm('Turn off this link? The parent will need a new one.')">Turn off link</button>
                            </form>
                        </div>
                        <?php if (!$parents): ?><p class="muted small">No parent phone on file — add one on the student profile.</p><?php endif; ?>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    <?php endforeach; ?>

    <form method="post" style="margin-top:1rem">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="route_id" value="<?= $routeId ?>">
        <input type="hidden" name="op" value="add">
        <h3>Add a child</h3>
        <?php if (!$candidates): ?>
            <p class="muted">Every active child is already on this route.</p>
        <?php else: ?>
            <label>Child
                <select name="student_id" required>
                    <option value="">— pick —</option>
                    <?php foreach ($candidates as $c): ?>
                        <option value="<?= (int)$c['id'] ?>">
                            <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?> · <?= e((string)$c['grade']) ?><?= $c['transport'] === 'cab' ? ' · cab' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Stop note <input type="text" name="stop_note" maxlength="255" placeholder="Optional"></label>
            <div class="actionbar form-actions"><button class="btn btn-primary" type="submit">Add to end of route</button></div>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0">Travel times</h2>
    <?php if (!$hasKey): ?>
        <p class="muted">Times between stops are estimated from past trips (or the default minutes per stop).
            Add a Google Maps key in <a href="/transport/setup.php">setup</a> to use road travel times.</p>
    <?php else: ?>
        <p class="muted">Uses Google Maps for the time between consecutive stops that both have a location. Re-run after changing the order.</p>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="route_id" value="<?= $routeId ?>">
            <button class="btn btn-primary" name="op" value="maps">Refresh travel times</button>
        </form>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard.writeText(b.dataset.copy).then(function () { b.textContent = 'Copied'; });
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
