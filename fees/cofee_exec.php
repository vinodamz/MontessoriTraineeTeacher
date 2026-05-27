<?php
/**
 * fees/cofee_exec.php — execute CoFee API actions from the enrollment wizard.
 *
 * AJAX endpoint. Accepts POST with op=create_member or op=check_token.
 * Returns JSON.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fees.php';
require_once __DIR__ . '/../includes/cofee_api.php';

$user = require_module('fees');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

csrf_check();

$op = $_POST['op'] ?? '';

if ($op === 'check_token') {
    $result = cofee_check_token();
    echo json_encode($result);
    exit;
}

if ($op === 'search_member') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['ok' => false, 'error' => 'Name is required']);
        exit;
    }
    $result = cofee_search_member($name);
    echo json_encode($result);
    exit;
}

if ($op === 'create_member') {
    $memberData = [
        'name'              => trim((string)($_POST['member_name'] ?? '')),
        'mobile'            => preg_replace('/\D+/', '', (string)($_POST['phone'] ?? '')),
        'country_code'      => '91',
        'email'             => trim((string)($_POST['email'] ?? '')),
        'guardian_name'     => trim((string)($_POST['guardian_name'] ?? '')),
        'admission_date'    => trim((string)($_POST['admission_date'] ?? '')) . 'T00:00:00Z',
    ];

    // Strip leading 91 from mobile if present (CoFee stores without country code).
    if (strlen($memberData['mobile']) > 10 && str_starts_with($memberData['mobile'], '91')) {
        $memberData['mobile'] = substr($memberData['mobile'], 2);
    }

    if ($memberData['name'] === '') {
        echo json_encode(['ok' => false, 'error' => 'Member name is required']);
        exit;
    }

    // Check if member already exists.
    $search = cofee_search_member($memberData['name']);
    if ($search['ok'] && isset($search['data']['data']) && count($search['data']['data']) > 0) {
        $existing = $search['data']['data'][0];
        echo json_encode([
            'ok' => true,
            'already_exists' => true,
            'member_id' => $existing['id'],
            'member_name' => $existing['name'],
            'message' => 'Member "' . $existing['name'] . '" already exists (ID: ' . $existing['id'] . '). Skipping creation — proceed to add them to groups.',
        ]);
        exit;
    }

    $result = cofee_create_member($memberData);
    if ($result['ok']) {
        $memberId = $result['data']['id'] ?? $result['data']['member_id'] ?? '(check CoFee)';
        echo json_encode([
            'ok' => true,
            'already_exists' => false,
            'member_id' => $memberId,
            'member_name' => $memberData['name'],
            'message' => 'Member created in CoFee! ID: ' . $memberId,
        ]);
    } else {
        echo json_encode($result);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown operation: ' . $op]);
