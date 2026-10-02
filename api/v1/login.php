<?php
/** POST {user_id, pin, device_name} — returns a device token. */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_boot();
api_require_method('POST');
$in = api_input();
try {
    [$token, $user] = api_login((int)($in['user_id'] ?? 0), (string)($in['pin'] ?? ''), (string)($in['device_name'] ?? ''));
} catch (InvalidArgumentException | RuntimeException $e) {
    api_error($e->getMessage(), $e instanceof InvalidArgumentException ? 401 : 429, $e instanceof InvalidArgumentException ? 'wrong_pin' : 'locked');
}
api_json(['ok' => true, 'token' => $token, 'user' => $user, 'modules' => api_app_modules($user)]);
