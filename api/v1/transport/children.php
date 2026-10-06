<?php
/** GET ?q= — enrolled children, for adding to a trip. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');

$q = (string)($_GET['q'] ?? '');
$children = [];
foreach (transport_student_choices($q) as $s) {
    $children[] = [
        'id'    => (int)$s['id'],
        'name'  => trim($s['first_name'] . ' ' . $s['last_name']),
        'grade' => (string)$s['grade'],
    ];
}
api_json(['ok' => true, 'children' => $children]);
