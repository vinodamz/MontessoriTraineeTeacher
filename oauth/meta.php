<?php
/**
 * oauth/meta.php — the two discovery documents, served by rewrite from
 *
 *   /.well-known/oauth-authorization-server   (RFC 8414)
 *   /.well-known/oauth-protected-resource     (RFC 9728)
 *
 * A client's very first move is to fetch one of these, unauthenticated, to
 * learn where to register and where to send the user. They are deliberately
 * public: they describe endpoints, not secrets.
 *
 * ?doc=as|rs picks the document. The rewrite in .htaccess supplies it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/oauth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
// A client may fetch this from a page origin; the spec expects it to be
// readable cross-origin, and there is nothing here worth protecting.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$doc = (string)($_GET['doc'] ?? 'as');
echo json_encode($doc === 'rs' ? oauth_rs_metadata() : oauth_as_metadata(),
                 JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
