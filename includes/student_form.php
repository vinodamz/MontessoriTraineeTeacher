<?php
/**
 * includes/student_form.php — helpers for the public per-student
 * "parent form" link (/students/parent_form.php).
 *
 * Issuing model: admin clicks "Generate link" on /students/view.php →
 * insert a fresh row in student_form_tokens with a 32-byte random hex
 * token (64 chars). The URL `/students/parent_form.php?token=…` is the
 * sole credential — there's no school login on the parent side. Token
 * stays valid until an admin clicks Revoke.
 *
 * Database table: see sql/migrate_028_student_form_tokens.sql.
 */
declare(strict_types=1);

/**
 * Mint a new token for a student. Caller can safely call this even when
 * an active token already exists — both will work until one is revoked.
 * If you want exclusive issuance, call revoke_active_form_tokens() first.
 */
function generate_form_token(int $student_id, int $by_user_id): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare("
        INSERT INTO student_form_tokens (student_id, token, created_by_user_id)
        VALUES (:sid, :t, :uid)
    ");
    $stmt->execute([':sid' => $student_id, ':t' => $token, ':uid' => $by_user_id]);
    return $token;
}

/** Mark a specific token row as revoked. No-op if already revoked. */
function revoke_form_token(int $token_id): void
{
    $stmt = db()->prepare("
        UPDATE student_form_tokens
        SET    revoked_at = NOW()
        WHERE  id = :id AND revoked_at IS NULL
    ");
    $stmt->execute([':id' => $token_id]);
}

/** Revoke every still-active token for a student (used before issuing a fresh one). */
function revoke_active_form_tokens(int $student_id): void
{
    $stmt = db()->prepare("
        UPDATE student_form_tokens
        SET    revoked_at = NOW()
        WHERE  student_id = :sid AND revoked_at IS NULL
    ");
    $stmt->execute([':sid' => $student_id]);
}

/** Return the most-recently created active token row for a student, or null. */
function active_form_token_for_student(int $student_id): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM   student_form_tokens
        WHERE  student_id = :sid AND revoked_at IS NULL
        ORDER  BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([':sid' => $student_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Public-side lookup. Returns the student row + token row, or null.
 *   - Constant-time token compare via hash_equals (avoids timing leaks).
 *   - Updates last_accessed_at on a hit.
 *   - Returns null when token is blank, malformed, unknown, or revoked.
 */
function student_by_form_token(string $token): ?array
{
    $token = trim($token);
    // 32-byte hex = 64 chars. Anything else can't possibly match — short-
    // circuit before touching the DB.
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return null;

    $stmt = db()->prepare("
        SELECT sft.id AS token_id, sft.token, sft.student_id, sft.created_at,
               sft.revoked_at, sft.last_accessed_at, sft.last_saved_at,
               s.*
        FROM   student_form_tokens sft
        JOIN   students s ON s.id = sft.student_id
        WHERE  sft.token = :t
        LIMIT 1
    ");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($row['revoked_at'] !== null) return null;
    if (!hash_equals((string)$row['token'], $token)) return null;

    // Bump last_accessed_at (fire-and-forget; failure shouldn't block the user).
    try {
        db()->prepare("UPDATE student_form_tokens SET last_accessed_at = NOW() WHERE id = :id")
            ->execute([':id' => (int)$row['token_id']]);
    } catch (Throwable $e) { /* swallow */ }

    return $row;
}

/** Stamp last_saved_at on a token after a successful save. */
function bump_form_token_saved(int $token_id): void
{
    try {
        db()->prepare("UPDATE student_form_tokens SET last_saved_at = NOW() WHERE id = :id")
            ->execute([':id' => $token_id]);
    } catch (Throwable $e) { /* swallow */ }
}

/** Absolute public URL for a parent-form token (scheme + host + path). */
function parent_form_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . '/students/parent_form.php?token=' . $token;
}
