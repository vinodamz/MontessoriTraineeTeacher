<?php
/**
 * includes/cofee_api.php — thin wrapper for the CoFee REST API.
 *
 * Reads credentials from app_settings (cofee_token, cofee_org_id,
 * cofee_branch_id). All methods return ['ok' => bool, 'data' => …,
 * 'error' => '…'] so callers can show clean feedback.
 */
declare(strict_types=1);

function cofee_config(): array
{
    return [
        'token'     => (string)app_setting('cofee_token', ''),
        'org_id'    => (string)app_setting('cofee_org_id', ''),
        'branch_id' => (string)app_setting('cofee_branch_id', ''),
        'base_url'  => 'https://api.cofee.life',
    ];
}

function cofee_is_configured(): bool
{
    $c = cofee_config();
    return $c['token'] !== '' && $c['org_id'] !== '' && $c['branch_id'] !== '';
}

function cofee_branch_path(): string
{
    $c = cofee_config();
    return '/v1/organisation/' . $c['org_id'] . '/branch/' . $c['branch_id'];
}

/**
 * Make an HTTP request to the CoFee API.
 * Returns ['ok' => bool, 'status' => int, 'data' => array|null, 'error' => string].
 */
function cofee_request(string $method, string $path, ?array $body = null): array
{
    $c = cofee_config();
    if ($c['token'] === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'CoFee token not configured. Go to Fees → Configure → CoFee API section.'];
    }

    $url = $c['base_url'] . $path;
    $headers = [
        'Authorization: Bearer ' . $c['token'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ]);
    if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Network error: ' . $err];
    }
    if ($httpCode === 401) {
        return ['ok' => false, 'status' => 401, 'data' => null, 'error' => 'CoFee token expired or invalid. Get a fresh one from DevTools → Local Storage → token.'];
    }

    $data = json_decode((string)$response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'status' => $httpCode, 'data' => $data, 'error' => ''];
    }

    $msg = $data['message'] ?? $data['error'] ?? ('HTTP ' . $httpCode);
    return ['ok' => false, 'status' => $httpCode, 'data' => $data, 'error' => (string)$msg];
}

/** Verify the token is still valid. */
function cofee_check_token(): array
{
    return cofee_request('GET', '/v1/config');
}

/** Search for a member by name. */
function cofee_search_member(string $name): array
{
    return cofee_request('GET', cofee_branch_path() . '/members?search=' . urlencode($name) . '&page=0&limit=5');
}

/** Create a member in the branch roster. */
function cofee_create_member(array $data): array
{
    return cofee_request('POST', cofee_branch_path() . '/member', $data);
}

/** Get the list of groups in the branch. */
function cofee_list_groups(): array
{
    return cofee_request('GET', cofee_branch_path() . '/groups');
}

/** CoFee web app URL for a specific group (for manual enrollment). */
function cofee_group_url(string $groupId): string
{
    $c = cofee_config();
    return 'https://web.cofee.life/group/' . urlencode($groupId);
}
