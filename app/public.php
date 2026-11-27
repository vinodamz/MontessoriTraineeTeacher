<?php
/** Authenticated entry for public pages that do not implement the workspace bridge. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/app_workspace.php';
$user = require_login();
$raw = $_GET['path'] ?? null;
$path = is_string($raw) ? app_workspace_path($raw) : null;
if ($path === null || !app_workspace_public_path($path)) {
    app_workspace_error('This public school link is not supported.', 400);
}
$pageTitle = parse_url($path, PHP_URL_PATH) === '/receipt.php' ? 'Payment receipt' : 'Fee calculator';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1><?= e($pageTitle) ?></h1></div>
<p>This public school page opens in your browser. Your school tool will stay here.</p>
<a class="btn btn-primary" href="<?= e($path) ?>" target="_blank" rel="noopener noreferrer">Open <?= e(strtolower($pageTitle)) ?> in browser</a>
<?php require __DIR__ . '/../includes/footer.php'; ?>
