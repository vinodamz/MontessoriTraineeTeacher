<?php
/**
 * assessment/report_share.php — PUBLIC, NO LOGIN.
 *
 * Token-gated, read-only view of one child's detailed progress report. A
 * teacher or admin generates the link on assessment/report.php and shares it
 * with the family. The token in the URL is the sole credential.
 *
 * Read-only by design: there is no POST handler here at all, so the link can
 * never be used to change anything.
 *
 * Invalid / revoked tokens land on a generic "link not active" page — no
 * disclosure about whether a token ever existed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';       // db + app_config; no require_login
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/child_report.php';

$token = (string)($_GET['token'] ?? '');
$row   = child_report_by_token($token);
$d     = $row ? child_report_data((int)$row['student_id']) : null;

$appName = function_exists('app_name') ? app_name() : 'Little Graduates';

if (!$d) {
    http_response_code(404);
    $title = 'Link not active';
} else {
    $title = trim((string)$d['student']['first_name'] . ' ' . (string)$d['student']['last_name']) . ' · Progress report';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e($appName) ?></title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: #fff5fa; color: #2b2b2b; }
  header.cr-top { background: #fff; border-bottom: 3px solid #e91e63; padding: 1rem 1.2rem;
                  display: grid; grid-template-columns: 48px 1fr auto; gap: .8rem; align-items: center; }
  header.cr-top img.cr-logo { width: 48px; height: auto; }
  header.cr-top h1 { margin: 0; font-size: 1.05rem; color: #ad1457; font-weight: 800;
                     text-transform: uppercase; letter-spacing: .5px; }
  header.cr-top p { margin: .15rem 0 0; font-size: .8rem; color: #66bb6a; font-weight: 600; }
  main { max-width: 880px; margin: 0 auto; padding: 1.2rem; }
  .cr-print-btn { padding: .5rem .9rem; border: 0; border-radius: 6px; background: #e91e63;
                  color: #fff; font-size: .9rem; font-weight: 600; cursor: pointer; }
  .cr-dead { background: #fff; border: 1px solid #e3d9c8; border-radius: 10px; padding: 1.2rem; }
<?= child_report_styles() ?>
  @media print { header.cr-top { border-bottom: 1px solid #999; } .cr-print-btn { display: none; } body { background: #fff; } }
</style>
</head>
<body>
<header class="cr-top">
    <img class="cr-logo" src="/assets/img/logo.png" alt="">
    <div>
        <h1><?= e($appName) ?></h1>
        <p>Progress report</p>
    </div>
    <?php if ($d): ?>
        <button type="button" class="cr-print-btn" onclick="window.print()">Print / Save PDF</button>
    <?php endif; ?>
</header>
<main>
<?php if (!$d): ?>
    <div class="cr-dead">
        <h2 style="margin-top:0;">This link isn't active.</h2>
        <p>The report link you opened has been revoked or has never existed.
           Please contact the school to request a fresh link.</p>
    </div>
<?php else: ?>
    <?php child_report_render($d); ?>
<?php endif; ?>
</main>
</body>
</html>
