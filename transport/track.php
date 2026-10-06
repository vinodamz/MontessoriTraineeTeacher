<?php
/**
 * transport/track.php — PUBLIC, NO LOGIN. A parent's private link.
 *
 * Shows this child's first name, an approximate cab status for today and,
 * while their child's trip is running and the child is still waiting, the
 * cab's live position on a map with their own stop. Never other children or
 * stop details. Invalid or turned-off links get the same generic page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

// No session for parents, so set the school's timezone before the first DB
// connection (db() aligns MySQL's time_zone with PHP's at connect time).
date_default_timezone_set(app_config()['app']['timezone'] ?? 'UTC');

header('Cache-Control: no-store, private');
// Map tiles need a Referer; strict-origin sends only the host, never the token.
header('Referrer-Policy: strict-origin');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$token = (string)($_GET['t'] ?? '');
$studentId = transport_tables_ready() ? transport_student_for_token($token) : null;
$status = $studentId !== null ? transport_parent_status($studentId) : null;
$live = $status !== null && in_array($status['state'], ['scheduled', 'on_the_way', 'reached'], true);
if ($status === null) http_response_code(404);
$appName = function_exists('app_name') ? app_name() : 'Little Graduates';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="strict-origin">
<?php if ($live): ?><noscript><meta http-equiv="refresh" content="60"></noscript><?php endif; ?>
<title>School cab · <?= e($appName) ?></title>
<?php if ($live): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<?php endif; ?>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: #FFF8F0; color: #2b2b2b; }
  main { max-width: 480px; margin: 0 auto; padding: 1.5rem 1.2rem; }
  .card { background: #fff; border: 1px solid #eadfcf; border-radius: 14px; padding: 1.4rem 1.3rem; }
  h1 { font-size: 1rem; margin: 0 0 1rem; color: #7a5c3a; font-weight: 700; }
  .state { font-size: 1.35rem; line-height: 1.35; margin: 0 0 .5rem; font-weight: 600; }
  .muted { color: #8a7f72; font-size: .85rem; margin: .75rem 0 0; }
  .dot { display: inline-block; width: .7rem; height: .7rem; border-radius: 50%; margin-right: .45rem; background: #c9bfb2; }
  .dot.on_the_way { background: #f0a020; } .dot.reached { background: #2e9d5b; } .dot.done { background: #2e9d5b; }
  .stats { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-top: 1rem; }
  .stat { background: #FFF8F0; border-radius: 12px; padding: .7rem .8rem; }
  .stat b { display: block; font-size: 1.05rem; }
  .stat span { color: #8a7f72; font-size: .75rem; }
  .pin { margin-top: 1rem; background: #E5F6EA; border-radius: 12px; padding: .9rem 1rem; }
  .pin span { display: block; color: #3d6b4f; font-size: .8rem; }
  .pin b { display: block; font-size: 1.8rem; letter-spacing: .25rem; margin-top: .15rem; }
  form { margin-top: 1rem; display: grid; gap: .5rem; }
  form select, form input, form button { font: inherit; padding: .7rem .8rem; border-radius: 10px; border: 1px solid #eadfcf; }
  form button { background: #C2185B; color: #fff; border: 0; font-weight: 700; }
  #map { height: 320px; border-radius: 12px; margin-top: 1rem; display: none; }
  .cab-pin { background: #e65100; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px #e65100; }
  .home-pin { background: #2e7d32; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px #2e7d32; }
</style>
</head>
<body>
<main>
    <div class="card">
        <h1><?= e($appName) ?> · School cab</h1>
        <?php if ($status === null): ?>
            <p class="state">This link isn’t active.</p>
            <p class="muted">Please ask the school for a new link.</p>
        <?php else: ?>
            <p class="muted" style="margin:0 0 .5rem"><?= e($status['child']) ?> · <?= $status['direction'] ? e(transport_direction_label((string)$status['direction'])) : 'Today' ?></p>
            <p class="state"><span class="dot <?= e($status['state']) ?>" id="dot"></span><span id="phrase"><?= e($status['phrase']) ?></span></p>
            <div class="stats">
                <div class="stat"><b id="eta"><?= $status['minutes'] !== null ? e(transport_eta_phrase((int)$status['minutes'])) : '—' ?></b><span>School ETA</span></div>
                <div class="stat"><b id="ahead"><?= $status['stops_ahead'] !== null ? e((string)$status['stops_ahead']) : '—' ?></b><span>Stops before you</span></div>
                <div class="stat"><b id="speed"><?= isset($status['speed_kmh']) && $status['speed_kmh'] !== null ? e((string)$status['speed_kmh'] . ' km/h') : '—' ?></b><span>Speed</span></div>
                <div class="stat"><b id="seat"><?= e((string)($status['seat'] ?? 'Waiting')) ?></b><span>Seat</span></div>
            </div>
            <?php if (!empty($status['pin'])): ?>
                <div class="pin"><span>Handover PIN</span><b id="pin"><?= e((string)$status['pin']) ?></b><p class="muted" style="margin:.3rem 0 0">Show this to the teacher when <?= e($status['child']) ?> arrives.</p></div>
            <?php endif; ?>
            <?php if (!empty($status['absence'])): ?>
                <p class="muted" id="absence"><?= e((string)$status['absence']['label']) ?><?= $status['absence']['reason'] !== '' ? ' · ' . e((string)$status['absence']['reason']) : '' ?></p>
            <?php endif; ?>
            <div id="map" aria-label="Where the cab is now"></div>
            <form method="post" action="/transport/track_absence.php">
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <select name="scope" required>
                    <option value="morning">Not riding this morning</option>
                    <option value="afternoon">Not riding this afternoon</option>
                    <option value="both">Not riding today</option>
                </select>
                <input name="reason" maxlength="200" placeholder="Reason (optional)">
                <button type="submit">Tell the school</button>
            </form>
            <?php if ($status['state'] === 'on_the_way'): ?>
                <p class="muted">Times are approximate and depend on traffic.</p>
            <?php endif; ?>
            <?php if (!empty($status['contact'])): ?><p class="muted">Transport desk <?= e((string)$status['contact']) ?></p><?php endif; ?>
            <p class="muted">Updated <span id="updated"><?= e(date('g:i a')) ?></span><?= $live ? ' · updates live' : '' ?>. This link is private to your family — please don’t share it.</p>
        <?php endif; ?>
    </div>
</main>
<?php if ($live): ?>
<script>
(function () {
    var initial = <?= json_encode([
        'state' => $status['state'],
        'phrase' => $status['phrase'],
        'eta' => $status['minutes'] !== null ? transport_eta_phrase((int)$status['minutes']) : null,
        'stops_ahead' => $status['stops_ahead'],
        'speed_kmh' => $status['speed_kmh'] ?? null,
        'seat' => $status['seat'] ?? null,
        'cab' => $status['cab'],
        'home' => $status['home'],
    ], JSON_UNESCAPED_UNICODE) ?>;
    var url = '/transport/track_status.php?t=' + encodeURIComponent(<?= json_encode($token) ?>);
    var map = null, cab = null, home = null, fitted = false;
    var el = document.getElementById('map');

    function pin(cls) { return L.divIcon({className: cls, iconSize: [18, 18]}); }

    function render(d) {
        document.getElementById('phrase').textContent = d.phrase;
        document.getElementById('dot').className = 'dot ' + d.state;
        var eta = document.getElementById('eta');
        var ahead = document.getElementById('ahead');
        var speed = document.getElementById('speed');
        var seat = document.getElementById('seat');
        if (eta) eta.textContent = d.eta || '—';
        if (ahead) ahead.textContent = d.stops_ahead === null || d.stops_ahead === undefined ? '—' : String(d.stops_ahead);
        if (speed) speed.textContent = d.speed_kmh === null || d.speed_kmh === undefined ? '—' : (d.speed_kmh + ' km/h');
        if (seat && d.seat) seat.textContent = d.seat;
        if (d.updated) document.getElementById('updated').textContent = d.updated;
        if (!d.cab || typeof L === 'undefined') {
            el.style.display = 'none';
            return;
        }
        el.style.display = 'block';
        if (!map) {
            map = L.map(el, {zoomControl: true, attributionControl: true});
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);
        }
        var c = [d.cab.lat, d.cab.lng];
        if (!cab) cab = L.marker(c, {icon: pin('cab-pin'), title: 'School cab'}).addTo(map);
        else cab.setLatLng(c);
        if (d.home && !home) home = L.marker([d.home.lat, d.home.lng], {icon: pin('home-pin'), title: 'Your stop'}).addTo(map);
        if (!fitted) {
            if (home) map.fitBounds(L.latLngBounds([c, home.getLatLng()]).pad(0.3));
            else map.setView(c, 15);
            fitted = true;
        }
        setTimeout(function () { map.invalidateSize(); }, 0);
    }

    render(initial);
    setInterval(function () {
        fetch(url, {cache: 'no-store'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) { location.reload(); return; }
                render(d);
            })
            .catch(function () {});
    }, 15000);
})();
</script>
<?php endif; ?>
</body>
</html>
