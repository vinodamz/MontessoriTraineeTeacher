<?php
/**
 * transport/trip.php — the driver / operator screen for one trip. Start,
 * mark each child, and tap to send WhatsApp alerts from your own phone.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

$user = transport_require();
$tripId = (int)($_GET['id'] ?? $_POST['trip_id'] ?? 0);
$trip = transport_trip_get($tripId);
if (!$trip) {
    http_response_code(404);
    exit('Trip not found.');
}
$self = '/transport/trip.php?id=' . $tripId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    try {
        if ($op === 'start') {
            transport_trip_start($tripId, (int)$user['id']);
        } elseif ($op === 'finish') {
            transport_trip_finish($tripId);
            flash_set('ok', 'Trip completed.');
        } elseif ($op === 'cancel') {
            transport_trip_cancel($tripId);
            flash_set('ok', 'Trip cancelled.');
        } elseif (in_array($op, ['reached', 'done', 'absent', 'undo'], true)) {
            $stopId = (int)($_POST['stop_id'] ?? 0);
            $stop = transport_trip_stop_get($stopId);
            if (!$stop || (int)$stop['trip_id'] !== $tripId) throw new InvalidArgumentException('Stop not found.');
            transport_mark_stop($stopId, $op, (int)$user['id']);
            redirect($self . '#stop-' . $stopId);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($self);
}

$stops = transport_trip_stops($tripId);
$etas = transport_trip_etas($trip, $stops);
$due = transport_due_eta_alerts($stops, $etas);
$running = $trip['status'] === 'running';
$dir = (string)$trip['direction'];
$csrf = csrf_token();
$doneVerb = $dir === 'drop' ? 'Dropped' : 'Picked up';
$pending = count(array_filter($stops, static fn($s) => $s['status'] === 'pending'));
$parentsByStudent = [];
foreach ($stops as $s) $parentsByStudent[(int)$s['student_id']] = transport_parents_with_phone((int)$s['student_id']);
$dueToSend = count(array_filter($stops, static fn($s) => in_array((int)$s['id'], $due, true) && $parentsByStudent[(int)$s['student_id']]));

function trip_alert_href(int $stopId, string $kind, int $parentId, string $csrf): string
{
    return '/transport/alert.php?' . http_build_query(['stop' => $stopId, 'kind' => $kind, 'parent' => $parentId, 'k' => $csrf]);
}

$pageTitle = $trip['route_name'] . ' · ' . transport_direction_label($dir);
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e((string)$trip['route_name']) ?></h1>
        <p class="muted">
            <?= e(transport_direction_label($dir)) ?> · <?= e(date('D j M', strtotime((string)$trip['trip_date']))) ?>
            <?php if ($trip['cab_name']): ?> · <?= e((string)$trip['cab_name']) ?><?php endif; ?>
            <?php if ($trip['vehicle_no']): ?> (<?= e((string)$trip['vehicle_no']) ?>)<?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <span class="pill <?= $running ? 'pill-warn' : ($trip['status'] === 'completed' ? 'pill-ok' : '') ?>"><?= e(transport_trip_status_label((string)$trip['status'])) ?></span>
        <a class="btn btn-ghost" href="/transport/index.php">← Today</a>
    </div>
</div>

<?php if ($trip['status'] === 'scheduled'): ?>
    <div class="card">
        <p><?= count($stops) ?> children, in this order. Start when the cab leaves<?= $dir === 'drop' ? ' school' : '' ?>.</p>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="trip_id" value="<?= $tripId ?>">
            <button class="btn btn-primary btn-big" name="op" value="start" <?= $stops ? '' : 'disabled' ?>>Start trip</button>
        </form>
    </div>
<?php elseif ($dueToSend): ?>
    <div class="flash flash-warn transport-due">
        <?= $dueToSend === 1 ? 'A family is' : $dueToSend . ' families are' ?> about <?= TRANSPORT_ETA_ALERT_MINUTES ?> minutes away — tap the highlighted WhatsApp button to let them know.
    </div>
<?php endif; ?>

<?php foreach ($stops as $s):
    $sid = (int)$s['id'];
    $e = $etas[$sid] ?? null;
    $isDue = in_array($sid, $due, true);
    $parents = $parentsByStudent[(int)$s['student_id']];
    $reached = !empty($s['reached_at']);
?>
    <div class="card transport-trip-stop<?= $isDue ? ' is-due' : '' ?><?= $s['status'] !== 'pending' ? ' is-finished' : '' ?>" id="stop-<?= $sid ?>">
        <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start">
            <div>
                <strong><?= (int)$s['stop_order'] ?>. <?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></strong>
                <span class="muted small"> · <?= e((string)$s['grade']) ?></span>
                <?php if ($s['stop_note']): ?><div class="muted small"><?= e((string)$s['stop_note']) ?></div><?php endif; ?>
            </div>
            <div style="text-align:right">
                <?php if ($s['status'] === 'done'): ?>
                    <span class="pill pill-ok"><?= $doneVerb ?> <?= e(date('g:i a', strtotime((string)$s['done_at']))) ?></span>
                <?php elseif ($s['status'] === 'absent'): ?>
                    <span class="pill">Absent</span>
                <?php elseif ($e && $e['reached']): ?>
                    <span class="pill pill-warn">At stop</span>
                <?php elseif ($e): ?>
                    <span class="pill">~<?= (int)$e['minutes'] ?> min</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($running && $s['status'] === 'pending'): ?>
            <form method="post" class="transport-actions">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                <input type="hidden" name="stop_id" value="<?= $sid ?>">
                <?php if (!$reached): ?>
                    <button class="btn btn-ghost" name="op" value="reached">Reached stop</button>
                <?php endif; ?>
                <button class="btn btn-primary" name="op" value="done"><?= $doneVerb ?></button>
                <button class="btn btn-ghost" name="op" value="absent">Absent</button>
            </form>

            <?php if ($parents): ?>
                <div class="transport-actions">
                    <?php foreach ($parents as $p):
                        $who = (string)($p['relation'] ?: $p['name']);
                    ?>
                        <?php if (!$reached): ?>
                            <a class="btn btn-small <?= $isDue ? 'btn-primary' : 'btn-ghost' ?>" target="_blank" rel="noopener"
                               href="<?= e(trip_alert_href($sid, 'eta', (int)$p['id'], $csrf)) ?>">WhatsApp <?= e($who) ?>: <?= TRANSPORT_ETA_ALERT_MINUTES ?> min</a>
                        <?php endif; ?>
                        <a class="btn btn-small <?= $reached && empty($s['reached_alert_at']) ? 'btn-primary' : 'btn-ghost' ?>" target="_blank" rel="noopener"
                           href="<?= e(trip_alert_href($sid, 'reached', (int)$p['id'], $csrf)) ?>">WhatsApp <?= e($who) ?>: cab reached</a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted small">No parent phone on file.</p>
            <?php endif; ?>
            <p class="muted small" style="margin:.35rem 0 0">
                <?php if ($s['eta_alert_at']): ?>5-min alert opened <?= e(date('g:i a', strtotime((string)$s['eta_alert_at']))) ?>. <?php endif; ?>
                <?php if ($s['reached_alert_at']): ?>Reached alert opened <?= e(date('g:i a', strtotime((string)$s['reached_alert_at']))) ?>.<?php endif; ?>
            </p>
        <?php elseif ($running): ?>
            <form method="post" style="margin-top:.4rem">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                <input type="hidden" name="stop_id" value="<?= $sid ?>">
                <button class="btn btn-ghost btn-small" name="op" value="undo">Undo</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if (in_array($trip['status'], ['scheduled', 'running'], true)): ?>
    <div class="card">
        <form method="post" class="actionbar">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="trip_id" value="<?= $tripId ?>">
            <?php if ($running): ?>
                <button class="btn btn-primary" name="op" value="finish"
                    <?= $pending ? 'onclick="return confirm(\'' . $pending . ' children are not marked yet. Finish anyway?\')"' : '' ?>>Finish trip</button>
            <?php endif; ?>
            <button class="btn btn-ghost" name="op" value="cancel" onclick="return confirm('Cancel this trip for today?')">Cancel trip</button>
        </form>
        <p class="muted small">Alerts open WhatsApp on this phone with the message ready — tap send there.</p>
    </div>
<?php endif; ?>

<?php if ($running): ?>
<script>
(function () {
    var url = '/transport/poll.php?id=<?= $tripId ?>';
    var sig = <?= json_encode(transport_trip_signature($trip, $stops, $due)) ?>;
    function check() {
        fetch(url, {credentials: 'same-origin', cache: 'no-store'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (d && d.sig !== sig) location.reload();
            })
            .catch(function () {});
    }
    setInterval(check, 30000);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
