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
            <div id="map" aria-label="Where the cab is now"></div>
            <?php if ($status['state'] === 'on_the_way'): ?>
                <p class="muted">Times are approximate and depend on traffic.</p>
            <?php endif; ?>
            <p class="muted">Updated <span id="updated"><?= e(date('g:i a')) ?></span><?= $live ? ' · updates live' : '' ?>. This link is private to your family — please don’t share it.</p>
        <?php endif; ?>
    </div>
</main>
<?php if ($live): ?>
<script>
(function () {
    var initial = <?= json_encode(['state' => $status['state'], 'phrase' => $status['phrase'], 'cab' => $status['cab'], 'home' => $status['home']], JSON_UNESCAPED_UNICODE) ?>;
    var url = '/transport/track_status.php?t=' + encodeURIComponent(<?= json_encode($token) ?>);
    var map = null, cab = null, home = null, fitted = false;
    var el = document.getElementById('map');

    function pin(cls) { return L.divIcon({className: cls, iconSize: [18, 18]}); }

    function render(d) {
        document.getElementById('phrase').textContent = d.phrase;
        document.getElementById('dot').className = 'dot ' + d.state;
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
