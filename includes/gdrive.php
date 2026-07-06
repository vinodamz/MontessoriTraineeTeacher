<?php
/**
 * gdrive.php — minimal Google Drive v3 client (service account, no SDK).
 *
 * Setup (one time, done by the school admin — instructions shown in-app):
 *   1. console.cloud.google.com → create a project → enable "Google Drive API".
 *   2. IAM & Admin → Service Accounts → create one → Keys → add a JSON key.
 *   3. In Google Drive, create a folder (e.g. "LG Material Audits") and SHARE
 *      it with the service account's email (Editor).
 *   4. Paste the JSON key + the folder's ID into /admin.php → Google Drive.
 *
 * The client mints a JWT (RS256 via openssl), exchanges it for an access
 * token (cached in app_settings until expiry), and uploads files with the
 * RESUMABLE protocol in 8 MB chunks so multi-hundred-MB audit zips survive
 * shared-hosting execution limits.
 */

const GDRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

function gdrive_config(): array
{
    $json   = trim((string)app_setting('gdrive_service_json', ''));
    $folder = trim((string)app_setting('gdrive_folder_id', ''));
    $creds  = $json !== '' ? json_decode($json, true) : null;
    return [
        'creds'  => is_array($creds) && !empty($creds['client_email']) && !empty($creds['private_key']) ? $creds : null,
        'folder' => $folder,
    ];
}

function gdrive_configured(): bool
{
    $c = gdrive_config();
    return $c['creds'] !== null && $c['folder'] !== '';
}

function gdrive_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Access token via service-account JWT, cached in app_settings. */
function gdrive_access_token(): string
{
    $cached = (string)app_setting('gdrive_token_cache', '');
    if ($cached !== '') {
        $c = json_decode($cached, true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 60 && !empty($c['token'])) {
            return (string)$c['token'];
        }
    }

    $cfg = gdrive_config();
    if ($cfg['creds'] === null) {
        throw new RuntimeException('Google Drive is not configured — add the service account key in Admin.');
    }
    $now    = time();
    $header = gdrive_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = gdrive_b64url(json_encode([
        'iss'   => $cfg['creds']['client_email'],
        'scope' => GDRIVE_SCOPE,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $sig = '';
    if (!openssl_sign($header . '.' . $claims, $sig, $cfg['creds']['private_key'], 'sha256WithRSAEncryption')) {
        throw new RuntimeException('Could not sign the Google token request — is the service-account key intact?');
    }
    $jwt = $header . '.' . $claims . '.' . gdrive_b64url($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Google token request failed: ' . $err);
    $data = json_decode((string)$resp, true);
    if (empty($data['access_token'])) {
        throw new RuntimeException('Google rejected the credentials: ' . mb_substr((string)$resp, 0, 300));
    }

    db()->prepare("
        INSERT INTO app_settings (setting_key, setting_value) VALUES ('gdrive_token_cache', :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ")->execute([':v' => json_encode(['token' => $data['access_token'], 'exp' => $now + (int)($data['expires_in'] ?? 3600)])]);
    app_setting_clear_cache();

    return (string)$data['access_token'];
}

/**
 * Upload a local file into the configured Drive folder (resumable, 8 MB
 * chunks). Returns ['id' => …, 'link' => …]. Throws on failure.
 */
function gdrive_upload(string $path, string $name, string $mime): array
{
    if (!is_file($path)) throw new RuntimeException('File to upload is missing.');
    $cfg   = gdrive_config();
    if ($cfg['folder'] === '') throw new RuntimeException('No Drive folder ID configured.');
    $token = gdrive_access_token();
    $size  = filesize($path);

    // 1. Open a resumable session.
    $meta = json_encode(['name' => $name, 'parents' => [$cfg['folder']]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $meta,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mime,
            'X-Upload-Content-Length: ' . $size,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        throw new RuntimeException('Could not start the Drive upload (HTTP ' . $code . '). Is the folder shared with the service account?');
    }
    if (!preg_match('/^location:\s*(\S+)/mi', (string)$resp, $m)) {
        throw new RuntimeException('Drive did not return an upload session.');
    }
    $sessionUri = trim($m[1]);

    // 2. PUT the file in chunks (multiples of 256 KB; 8 MB keeps each request
    //    well inside shared-hosting time limits).
    $chunk = 8 * 1024 * 1024;
    $fh    = fopen($path, 'rb');
    $sent  = 0;
    $final = null;
    while ($sent < $size) {
        @set_time_limit(120);
        $bytes = fread($fh, $chunk);
        $len   = strlen($bytes);
        $ch = curl_init($sessionUri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $bytes,
            CURLOPT_HTTPHEADER => [
                'Content-Length: ' . $len,
                'Content-Range: bytes ' . $sent . '-' . ($sent + $len - 1) . '/' . $size,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 110,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false || ($code !== 308 && $code !== 200 && $code !== 201)) {
            fclose($fh);
            throw new RuntimeException('Drive upload failed at ' . round($sent * 100 / max(1, $size)) . '% (HTTP ' . $code . ').');
        }
        $sent += $len;
        if ($code === 200 || $code === 201) $final = json_decode((string)$resp, true);
    }
    fclose($fh);

    if (!is_array($final) || empty($final['id'])) {
        throw new RuntimeException('Drive upload finished but no file id came back.');
    }
    return [
        'id'   => (string)$final['id'],
        'link' => (string)($final['webViewLink'] ?? ('https://drive.google.com/file/d/' . $final['id'] . '/view')),
    ];
}
