<?php
/**
 * crm/stages_api.php — list / bulk-configure pipeline stages (secret-authed).
 *
 * Lets the automation read every stage and set its WhatsApp message fields,
 * so all stages can be configured in one pass (mirrors what crm/stages.php
 * does in the admin UI).
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 *
 * GET  → { ok, stages:[ {id,code,label,display_order,is_open,is_active,
 *                        wa_text,wa_template,wa_template_lang} ] }
 * POST   JSON { stages:[ {code, wa_text?, wa_template?, wa_template_lang?} ] }
 *        → sets the given fields per code (only keys present are touched).
 *        Reply { ok, updated:[codes] }
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

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string) file_get_contents('php://input'), true);
        $stages = is_array($in['stages'] ?? null) ? $in['stages'] : [];
        $updated = [];
        foreach ($stages as $s) {
            $code = trim((string) ($s['code'] ?? ''));
            if ($code === '') continue;
            $sets = [];
            $args = [':c' => $code];
            foreach (['wa_text', 'wa_template', 'wa_template_lang', 'wa_docs'] as $f) {
                if (array_key_exists($f, $s)) {
                    $v = trim((string) $s[$f]);
                    $sets[] = "$f = :$f";
                    $args[":$f"] = ($v === '' ? null : $v);
                }
            }
            if (!$sets) continue;
            $st = $pdo->prepare("UPDATE crm_stages SET " . implode(', ', $sets) . " WHERE code = :c");
            $st->execute($args);
            if ($st->rowCount() >= 0) $updated[] = $code;
        }
        echo json_encode(['ok' => true, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = $pdo->query("
        SELECT id, code, label, display_order, is_open, is_active,
               wa_text, wa_template, wa_template_lang, wa_docs
        FROM crm_stages ORDER BY display_order, id")->fetchAll();
    $stages = [];
    foreach ($rows as $r) {
        $stages[] = [
            'id'               => (int) $r['id'],
            'code'             => (string) $r['code'],
            'label'            => (string) $r['label'],
            'display_order'    => (int) $r['display_order'],
            'is_open'          => (bool) $r['is_open'],
            'is_active'        => (bool) $r['is_active'],
            'wa_text'          => (string) ($r['wa_text'] ?? ''),
            'wa_template'      => (string) ($r['wa_template'] ?? ''),
            'wa_template_lang' => (string) ($r['wa_template_lang'] ?? ''),
            'wa_docs'          => (string) ($r['wa_docs'] ?? ''),
        ];
    }
    echo json_encode(['ok' => true, 'count' => count($stages), 'stages' => $stages], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'stages_api_failed']);
}
