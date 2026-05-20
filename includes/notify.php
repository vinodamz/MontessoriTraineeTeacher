<?php
/**
 * includes/notify.php — notification helpers.
 *
 * One public entry point:
 *   notify(int|array $recipients, string $category, string $eventType,
 *          string $title, string $body = '', ?string $link = null,
 *          bool $sendEmailNow = true): void
 *
 * Behaviour:
 * - Inserts one row per recipient into `notifications`.
 * - Respects per-user preferences from `notification_preferences`:
 *     - category disabled  → not inserted at all (so the bell stays quiet).
 *     - email_enabled = 0  → row inserted but email_status set to 'skipped'.
 * - When sendEmailNow is true AND the recipient has email_enabled, sends
 *   one HTML email per row immediately via PHP mail(). Failure to send is
 *   recorded as email_status='failed' — never crashes the calling page.
 *
 * The "to all admins" broadcast helper is provided as
 *   notify_admins(string $category, $eventType, $title, $body, $link)
 * so feature code doesn't have to look up the admin user list itself.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

/** Resolve recipient list — either an int, array of ints, or 'admins' / 'all'. */
function _notify_resolve_recipients($recipients): array
{
    if (is_int($recipients))   return [$recipients];
    if (is_array($recipients)) return array_values(array_unique(array_map('intval', $recipients)));
    return [];
}

/** Lookup per-user preferences with sensible defaults if no row exists. */
function _notify_get_prefs(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    try {
        $stmt = db()->prepare("SELECT * FROM notification_preferences WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = false;
    }
    $cache[$userId] = $row ?: [
        'email_enabled'      => 1,
        'tasks_enabled'      => 1,
        'attendance_enabled' => 1,
        'fees_enabled'       => 1,
        'students_enabled'   => 1,
    ];
    return $cache[$userId];
}

/** Map category → preference key. 'system' is always on. */
function _notify_category_enabled(string $category, array $prefs): bool
{
    if ($category === 'system') return true;
    $key = $category . '_enabled';
    return !empty($prefs[$key]);
}

/** Look up the recipient's email by joining users (we don't store email there yet, so this is a placeholder hook). */
function _notify_user_email(int $userId): ?string
{
    // The users table doesn't carry email yet. For Phase 1 we only support email
    // recipients who explicitly set `email_address` in app_settings under the key
    // `email_for_user_{id}`. This keeps the schema simple — extending users.email
    // can come as a follow-up if needed.
    return app_setting('email_for_user_' . $userId, null);
}

/**
 * Main API. Insert one row per recipient. Optionally fires emails inline.
 */
function notify($recipients, string $category, string $eventType,
                string $title, string $body = '', ?string $link = null,
                bool $sendEmailNow = true): void
{
    $userIds = _notify_resolve_recipients($recipients);
    if (!$userIds) return;

    try {
        $pdo = db();
        $ins = $pdo->prepare("
            INSERT INTO notifications (user_id, category, event_type, title, body, link, email_status)
            VALUES (:uid, :cat, :et, :t, :b, :l, :es)
        ");
        foreach ($userIds as $uid) {
            $prefs = _notify_get_prefs($uid);
            if (!_notify_category_enabled($category, $prefs)) continue;

            $emailWanted = !empty($prefs['email_enabled']) && _notify_user_email($uid) !== null;
            $emailStatus = $emailWanted ? 'pending' : 'skipped';

            $ins->execute([
                ':uid' => $uid, ':cat' => $category, ':et' => $eventType,
                ':t'   => mb_substr($title, 0, 200),
                ':b'   => $body !== '' ? $body : null,
                ':l'   => $link,
                ':es'  => $emailStatus,
            ]);

            if ($emailWanted && $sendEmailNow) {
                $notifId = (int)$pdo->lastInsertId();
                _notify_send_email_now($notifId, $uid, $title, $body, $link);
            }
        }
    } catch (Throwable $e) {
        // Never let a notification failure take down the calling page. Just log.
        error_log('[notify] insert failed: ' . $e->getMessage());
    }
}

/** Broadcast to every active admin user. */
function notify_admins(string $category, string $eventType,
                       string $title, string $body = '', ?string $link = null,
                       bool $sendEmailNow = true): void
{
    try {
        $ids = db()->query("SELECT id FROM users WHERE active = 1 AND role = 'admin'")
                   ->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) notify($ids, $category, $eventType, $title, $body, $link, $sendEmailNow);
    } catch (Throwable $e) {
        error_log('[notify_admins] failed: ' . $e->getMessage());
    }
}

/** Mark all of a user's unread notifications as read. */
function mark_all_read(int $userId): int
{
    try {
        $stmt = db()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :uid AND read_at IS NULL");
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

function mark_read(int $userId, int $notifId): void
{
    try {
        $stmt = db()->prepare("UPDATE notifications SET read_at = NOW() WHERE id = :id AND user_id = :uid AND read_at IS NULL");
        $stmt->execute([':id' => $notifId, ':uid' => $userId]);
    } catch (Throwable $e) { /* swallow */ }
}

function unread_count(int $userId): int
{
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function recent_notifications(int $userId, int $limit = 12): array
{
    try {
        $stmt = db()->prepare("
            SELECT * FROM notifications
            WHERE user_id = :uid
            ORDER BY created_at DESC, id DESC
            LIMIT $limit
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// ---------- Email channel -------------------------------------------------

function _notify_send_email_now(int $notifId, int $userId, string $title, string $body, ?string $link): void
{
    $to = _notify_user_email($userId);
    if (!$to) {
        _notify_mark_email($notifId, 'skipped');
        return;
    }

    $fromName    = app_setting('email_from_name', app_name());
    $fromAddress = app_setting('email_from_address', 'no-reply@thelittlegraduates.in');
    $appName     = app_name();

    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'mtt.thelittlegraduates.in';
    $linkAbs = $link ? ($link[0] === '/' ? $scheme . '://' . $host . $link : $link) : null;

    $subject = sprintf('[%s] %s', $appName, $title);
    $html    = _notify_email_html($appName, $title, $body, $linkAbs);

    $headers  = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = sprintf('From: %s <%s>', _notify_header_safe($fromName), _notify_header_safe($fromAddress));
    $headers[] = sprintf('Reply-To: %s', _notify_header_safe($fromAddress));
    $headers[] = 'X-Mailer: ' . $appName . ' notifications';

    try {
        $ok = @mail($to, _notify_header_safe($subject), $html, implode("\r\n", $headers));
        _notify_mark_email($notifId, $ok ? 'sent' : 'failed');
    } catch (Throwable $e) {
        _notify_mark_email($notifId, 'failed');
        error_log('[notify] mail() threw: ' . $e->getMessage());
    }
}

function _notify_mark_email(int $notifId, string $status): void
{
    try {
        $stmt = db()->prepare("
            UPDATE notifications
            SET email_status = :s, email_sent_at = CASE WHEN :s2 = 'sent' THEN NOW() ELSE email_sent_at END
            WHERE id = :id
        ");
        $stmt->execute([':s' => $status, ':s2' => $status, ':id' => $notifId]);
    } catch (Throwable $e) { /* swallow */ }
}

function _notify_header_safe(string $s): string
{
    // Strip CR/LF to prevent header injection.
    return preg_replace('/[\r\n]+/', ' ', $s);
}

function _notify_email_html(string $appName, string $title, string $body, ?string $link): string
{
    $titleE = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $bodyE  = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $appE   = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $btn    = '';
    if ($link) {
        $linkE = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $btn = '<p style="margin: 20px 0;"><a href="' . $linkE . '" style="display:inline-block;background:#EC407A;color:#fff;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:600;">Open in ' . $appE . '</a></p>';
    }
    return '
<!doctype html>
<html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px;background:#FFF8F0;font-family:-apple-system,Segoe UI,Arial,sans-serif;color:#2A2316;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #E9DDC9;border-radius:14px;">
<tr><td style="padding:24px;">
<p style="margin:0 0 4px;font-size:13px;color:#5A4F40;letter-spacing:.06em;text-transform:uppercase;">' . $appE . '</p>
<h1 style="margin:0 0 12px;font-size:20px;color:#2A2316;">' . $titleE . '</h1>
<div style="color:#3a352d;font-size:15px;line-height:1.5;">' . $bodyE . '</div>
' . $btn . '
<hr style="border:0;border-top:1px solid #E9DDC9;margin:20px 0;">
<p style="margin:0;font-size:12px;color:#8b8478;">You\'re receiving this because email notifications are turned on for your account.
Manage your preferences in the app at <strong>Admin → Notification preferences</strong>.</p>
</td></tr>
</table>
</body></html>';
}
