<?php
/** GET — the signed-in user and the modules their home screen shows. */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_boot();
api_require_method('GET');
$user = api_require_user();
api_json(['ok' => true, 'school' => app_name(), 'base_url' => app_base_url(),
          'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role'], 'modules' => $user['modules']],
          'modules' => api_app_modules($user)]);
