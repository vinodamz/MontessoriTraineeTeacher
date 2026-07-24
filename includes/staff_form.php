<?php
/**
 * includes/staff_form.php — helpers for the public per-staff "application
 * form" link (/staff/apply.php).
 *
 * Issuing model: admin clicks "Generate link" on /staff/view.php → insert a
 * fresh row in staff_form_tokens with a 32-byte random hex token (64 chars).
 * The URL `/staff/apply.php?token=…` is the sole credential — there's no
 * school login on the staff-form side. Token stays valid until revoked.
 *
 * Mirrors includes/student_form.php. Table: sql/migrate_047_staff_form_tokens.
 */
declare(strict_types=1);

/** Mint a new application-form token for a staff member. */
function staff_generate_form_token(int $user_id, int $by_user_id): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare("
        INSERT INTO staff_form_tokens (user_id, token, created_by_user_id)
        VALUES (:uid, :t, :by)
    ")->execute([':uid' => $user_id, ':t' => $token, ':by' => $by_user_id]);
    return $token;
}

/** Mark a specific token row as revoked. No-op if already revoked. */
function staff_revoke_form_token(int $token_id): void
{
    db()->prepare("
        UPDATE staff_form_tokens SET revoked_at = NOW()
        WHERE id = :id AND revoked_at IS NULL
    ")->execute([':id' => $token_id]);
}

/** Revoke every still-active token for a staff member (before issuing a fresh one). */
function staff_revoke_active_form_tokens(int $user_id): void
{
    db()->prepare("
        UPDATE staff_form_tokens SET revoked_at = NOW()
        WHERE user_id = :uid AND revoked_at IS NULL
    ")->execute([':uid' => $user_id]);
}

/** The most-recently created active token row for a staff member, or null. */
function staff_active_form_token(int $user_id): ?array
{
    try {
        $stmt = db()->prepare("
            SELECT * FROM staff_form_tokens
            WHERE user_id = :uid AND revoked_at IS NULL
            ORDER BY created_at DESC, id DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $user_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // pre-migration DB
    }
}

/**
 * Public-side lookup. Returns the token row + the staff member (id / name /
 * role / active), or null.
 *   - Constant-time compare via hash_equals.
 *   - Bumps last_accessed_at on a hit.
 *   - Null when token is blank, malformed, unknown, revoked, or the user is
 *     inactive.
 */
function staff_by_form_token(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return null;

    $stmt = db()->prepare("
        SELECT t.id AS token_id, t.token, t.user_id, t.created_at,
               t.revoked_at, t.last_accessed_at, t.last_saved_at,
               u.name AS staff_name, u.role AS staff_role, u.active AS staff_active
        FROM staff_form_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token = :t LIMIT 1
    ");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($row['revoked_at'] !== null) return null;
    if (!(int)$row['staff_active']) return null;
    if (!hash_equals((string)$row['token'], $token)) return null;

    try {
        db()->prepare("UPDATE staff_form_tokens SET last_accessed_at = NOW() WHERE id = :id")
            ->execute([':id' => (int)$row['token_id']]);
    } catch (Throwable $e) { /* swallow */ }

    return $row;
}

/** Stamp last_saved_at on a token after a successful save. */
function staff_bump_form_token_saved(int $token_id): void
{
    try {
        db()->prepare("UPDATE staff_form_tokens SET last_saved_at = NOW() WHERE id = :id")
            ->execute([':id' => $token_id]);
    } catch (Throwable $e) { /* swallow */ }
}

/** Absolute public URL for a staff application-form token. */
function staff_form_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . '/staff/apply.php?token=' . $token;
}
