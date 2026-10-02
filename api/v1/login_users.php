<?php
/** GET — names on the sign-in screen (the same list /login.php shows). */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_boot();
api_require_method('GET');
$rows = db()->query("SELECT id, name, role FROM users WHERE active = 1 ORDER BY name")->fetchAll();
api_json(['ok' => true, 'school' => app_name(), 'users' => array_map(static fn($r) => [
    'id' => (int)$r['id'], 'name' => (string)$r['name'], 'role' => (string)$r['role'],
], $rows)]);
