<?php
/** GET — today's material sheet (conditions, marks, photos). ?date=YYYY-MM-DD for a recent day. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_materials.php';

api_boot();
api_require_method('GET');
$user = api_materials_user();

try {
    $date = api_materials_date((string)($_GET['date'] ?? ''));
    api_json(['ok' => true, 'day' => api_materials_day($user, $date)]);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException) api_error($e->getMessage(), 400, 'bad_request');
    api_error('Materials are not available right now.', 500, 'server_error');
}
