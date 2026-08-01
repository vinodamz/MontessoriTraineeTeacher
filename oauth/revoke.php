<?php
/**
 * oauth/revoke.php — token revocation (RFC 7009).
 *
 * A client that is being disconnected should be able to hand its tokens back
 * rather than leaving them live until they expire.
 *
 * The spec requires 200 for an unknown token as well as a revoked one: a
 * different answer for "that token does not exist" would turn this endpoint
 * into an oracle for guessing valid tokens.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/oauth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'POST only.']);
    exit;
}

$in = $_POST;
if ($in === []) {
    $raw = (string)file_get_contents('php://input');
    if ($raw === '' && PHP_SAPI === 'cli') $raw = (string)file_get_contents('php://stdin');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) $in = $j; else parse_str($raw, $in);
    }
}

$token = (string)($in['token'] ?? '');
if ($token !== '') {
    try {
        oauth_token_revoke($token);
    } catch (Throwable $e) {
        error_log('oauth revoke failed: ' . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
