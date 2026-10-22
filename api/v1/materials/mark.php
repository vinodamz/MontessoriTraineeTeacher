<?php
/** POST {material_id, condition_code, notes, date?, client_key?} — save one material for a day. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_materials.php';

api_boot();
api_require_method('POST');
$user = api_materials_user();
$in = api_input();

try {
    api_materials_client_key((string)($in['client_key'] ?? ''));
    $saved = api_materials_mark(
        $user,
        (int)($in['material_id'] ?? 0),
        api_materials_date((string)($in['date'] ?? '')),
        (string)($in['condition_code'] ?? ''),
        (string)($in['notes'] ?? ''),
    );
    $saved['client_key'] = (string)($in['client_key'] ?? '');
    api_json(['ok' => true] + $saved);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
        api_error($e->getMessage(), 400, 'bad_request');
    }
    api_error('Could not save the material check.', 500, 'server_error');
}
