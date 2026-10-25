<?php
declare(strict_types=1);

/** Exact browser origins; mobile bearer clients do not send Origin. */
function api_allowed_browser_origin(string $origin): bool
{
    return in_array($origin, [
        'https://app.thelittlegraduates.in',
        'https://mtt.thelittlegraduates.in',
    ], true);
}

function api_handle_cors(): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return;
    header('Vary: Origin', false);
    if (!api_allowed_browser_origin($origin)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'This browser origin is not allowed.', 'code' => 'origin_denied']);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'OPTIONS') return;
    $method = strtoupper((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? ''));
    $headers = array_filter(array_map('trim', explode(',', strtolower((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '')))));
    if (!in_array($method, ['GET', 'POST'], true) || array_diff($headers, ['authorization', 'content-type', 'accept'])) {
        http_response_code(403);
        exit;
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}
