<?php
/**
 * crm/lead_lookup.php — read-only lead lookup by phone (last-10-digit match).
 * Diagnostic + dedup helper for the WhatsApp automation.
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 * Query: ?phone=<any format>
 * Reply: { ok, last10, count, leads:[{id,name,phone,status,created_at,last_inbound_at,visited_at}] }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

header('Content-Type: application/json');

$secret   = (string) app_setting('wacrm_sso_secret', '');
$provided = (string) ($_SERVER['HTTP_X_LEAD_SECRET'] ?? '');
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$phone  = (string) ($_GET['phone'] ?? '');
$last10 = substr(preg_replace('/\D/', '', $phone), -10);
if ($last10 === '') {
    http_response_code(400);
    echo json_encode(['error' => 'phone required']);
    exit;
}

try {
    $st = db()->prepare("
        SELECT id, primary_name, primary_phone, status, created_at, last_inbound_at, visited_at
        FROM inquiry_families
        WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d
        ORDER BY created_at ASC");
    $st->execute([':d' => $last10]);
    $leads = [];
    foreach ($st as $r) {
        $leads[] = [
            'id'              => (int) $r['id'],
            'name'            => (string) $r['primary_name'],
            'phone'           => (string) $r['primary_phone'],
            'status'          => (string) $r['status'],
            'status_label'    => crm_status_label((string) $r['status']),
            'created_at'      => (string) $r['created_at'],
            'last_inbound_at' => (string) ($r['last_inbound_at'] ?? ''),
            'visited_at'      => (string) ($r['visited_at'] ?? ''),
        ];
    }
    echo json_encode(['ok' => true, 'last10' => $last10, 'count' => count($leads), 'leads' => $leads], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'lookup_failed']);
}
