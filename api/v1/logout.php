<?php
/** POST — sign this device out. */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_boot();
api_require_method('POST');
api_logout(api_require_user());
api_json(['ok' => true]);
