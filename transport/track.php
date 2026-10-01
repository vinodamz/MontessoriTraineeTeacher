<?php
/**
 * transport/track.php — PUBLIC, NO LOGIN. A parent's private link.
 *
 * Shows only this child's first name and an approximate cab status for
 * today: never a map, the cab's position, other children, or stop details.
 * Invalid or turned-off links get the same generic page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

// No session for parents, so set the school's timezone before the first DB
// connection (db() aligns MySQL's time_zone with PHP's at connect time).
date_default_timezone_set(app_config()['app']['timezone'] ?? 'UTC');

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$studentId = transport_tables_ready() ? transport_student_for_token((string)($_GET['t'] ?? '')) : null;
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
<meta name="referrer" content="no-referrer">
<?php if ($live): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
<title>School cab · <?= e($appName) ?></title>
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
            <p class="state"><span class="dot <?= e($status['state']) ?>"></span><?= e($status['phrase']) ?></p>
            <?php if ($status['state'] === 'on_the_way'): ?>
                <p class="muted">Times are approximate and depend on traffic.</p>
            <?php endif; ?>
            <p class="muted">Updated <?= e(date('g:i a')) ?><?= $live ? ' · refreshes every minute' : '' ?>. This link is private to your family — please don’t share it.</p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
