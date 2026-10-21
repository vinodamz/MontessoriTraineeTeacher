<?php
/**
 * POST multipart — attach one photo, video or voice memo to today's check.
 *
 * Fields: material_id, date, client_key, and the file as "media".
 * The same client_key is safe to send again: the first copy wins.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_materials.php';

api_boot();
api_require_method('POST');
$user = api_materials_user();

try {
    $clientKey = api_materials_client_key((string)($_POST['client_key'] ?? ''));
    $date = api_materials_date((string)($_POST['date'] ?? ''));
    $materialId = (int)($_POST['material_id'] ?? 0);
    if ($materialId <= 0) throw new InvalidArgumentException('Missing material.');
    $checkId = api_materials_check_id($materialId, $date);
    if ($checkId <= 0) {
        api_error('Mark a condition first, then attach the photo.', 409, 'condition_required');
    }
    $stored = api_materials_store_media($_FILES['media'] ?? [], $checkId, (int)$user['id'], $clientKey);
    api_json(['ok' => true, 'client_key' => $clientKey] + $stored);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException) api_error($e->getMessage(), 400, 'bad_request');
    if ($e instanceof RuntimeException) {
        $migration = str_contains($e->getMessage(), 'migration 076');
        api_error($e->getMessage(), $migration ? 500 : 400, $migration ? 'server_error' : 'bad_request');
    }
    api_error('Could not save the photo.', 500, 'server_error');
}
