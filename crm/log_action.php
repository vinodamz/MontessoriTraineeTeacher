<?php
/**
 * crm/log_action.php — receive client-side audit events.
 *
 * Called via navigator.sendBeacon() from assets/js/crm-phone-log.js when
 * a user clicks the Call or WhatsApp pill. The browser fires this just
 * before navigating to the tel: / wa.me URI, so the request must be
 * fast and not block. We respond 204 No Content regardless of outcome.
 *
 * Actions accepted (anything else is rejected to keep the audit feed
 * trustworthy):
 *   phone_call_initiated  whatsapp_initiated
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

// 204-only endpoint — no body, no redirect, no flash. sendBeacon ignores
// the response anyway.
http_response_code(204);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

// CSRF: sendBeacon can send a token in the POST body. Verify it matches.
// If the body wasn't form-encoded (e.g. sendBeacon with a Blob), $_POST
// will be empty — accept that case only when the session is valid AND
// the action is in the allowlist below.
try {
    if (!empty($_POST['_csrf'])) {
        csrf_check();
    }
} catch (Throwable $e) {
    exit;
}

$action    = (string)($_POST['action'] ?? '');
$familyId  = (int)($_POST['family_id'] ?? 0) ?: null;
$rawMeta   = (string)($_POST['meta']      ?? '');

$allowed = ['phone_call_initiated', 'whatsapp_initiated'];
if (!in_array($action, $allowed, true)) exit;

$meta = null;
if ($rawMeta !== '') {
    $decoded = json_decode($rawMeta, true);
    if (is_array($decoded)) {
        // Cap each value so a hostile client can't bloat the audit row.
        $clean = [];
        foreach ($decoded as $k => $v) {
            if (!is_string($k)) continue;
            if (is_scalar($v)) $clean[substr($k, 0, 30)] = substr((string)$v, 0, 200);
        }
        if ($clean) $meta = $clean;
    }
}

crm_audit_log($action, $familyId, $meta);
