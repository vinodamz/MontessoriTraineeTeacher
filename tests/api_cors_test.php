<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_cors.php';
foreach ([
    'https://app.thelittlegraduates.in' => true,
    'https://mtt.thelittlegraduates.in' => true,
    'http://app.thelittlegraduates.in' => false,
    'https://app.thelittlegraduates.in.attacker.test' => false,
    'https://attacker.test' => false,
    'null' => false,
    '' => false,
] as $origin => $expected) {
    if (api_allowed_browser_origin($origin) !== $expected) throw new RuntimeException('Unexpected origin policy');
}
echo "CORS exact-origin tests passed\n";
