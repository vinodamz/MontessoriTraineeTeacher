<?php
/**
 * wacrm/index.php — landing page for the WACRM external integration.
 *
 * Embeds the URL stored in app_settings.wacrm_url. Access gated by the
 * `wacrm` module — admin assigns it on /admin.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_module('wacrm');

// ?launch=1 → mint a fresh SSO token and redirect straight into the CRM
// (used by the "Open in new tab" button so the token is never stale).
if (($_GET['launch'] ?? '') === '1') {
    $u = wacrm_launch_url();
    redirect($u !== '' ? $u : '/index.php');
}

$appKey = 'wacrm';
require __DIR__ . '/../includes/external_app_view.php';
