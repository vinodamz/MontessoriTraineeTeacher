<?php
/**
 * crm/lead_submit.php — PUBLIC lead capture endpoint (no auth).
 *
 * Embed it as a standalone page or as an <iframe>. Optional ?c=<campaign>
 * preselects a campaign (matched by name, case-insensitive).
 *
 * Anti-abuse:
 *   • Honeypot field `website_url` — silently dropped if filled.
 *   • Per-IP rate limit: 5 submissions per hour (sha256(IP) → ip_hash).
 *   • Required fields validated server-side.
 *
 * Successful submissions land in inquiry_families with status='lead',
 * showing up immediately in /crm/leads.php and the pipeline board.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

// NO require_login — this is public.

const LEAD_PUBLIC_HOURLY_CAP = 5;

$campaignParam = trim($_GET['c'] ?? $_POST['c'] ?? '');
$campaign = null;
if ($campaignParam !== '') {
    $stmt = db()->prepare("SELECT id, name FROM crm_campaigns WHERE LOWER(name) = LOWER(:n) AND active = 1");
    $stmt->execute([':n' => $campaignParam]);
    $campaign = $stmt->fetch() ?: null;
}

$submitted = false;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot — bots fill hidden fields, humans don't. Pretend success.
    if (trim($_POST['website_url'] ?? '') !== '') {
        $submitted = true;
    } else {
        $name  = trim($_POST['name']  ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $msg   = trim($_POST['message'] ?? '');

        if ($name === '')                       $errors[] = 'Please tell us your name.';
        if ($phone === '' && $email === '')     $errors[] = 'Add a phone or email so we can reach you.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email looks off.';

        $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$errors) {
            $recent = db()->prepare("
                SELECT COUNT(*) FROM inquiry_families
                WHERE ip_hash = :h AND created_at > NOW() - INTERVAL 1 HOUR
            ");
            $recent->execute([':h' => $ipHash]);
            if ((int)$recent->fetchColumn() >= LEAD_PUBLIC_HOURLY_CAP) {
                $errors[] = 'Too many submissions from your network. Try again later or call us directly.';
            }
        }

        if (!$errors) {
            // Upsert: a returning parent (same phone, any format) updates their
            // existing lead instead of creating a duplicate.
            $existing = $phone !== '' ? crm_find_lead_by_phone($phone) : null;
            if ($existing) {
                db()->prepare("
                    UPDATE inquiry_families
                    SET notes = CONCAT(COALESCE(notes, ''), '\n', :m),
                        primary_email = COALESCE(primary_email, :e)
                    WHERE id = :id
                ")->execute([
                    ':m'  => '[' . date('Y-m-d H:i') . '] Enquiry form: ' . ($msg !== '' ? $msg : 're-submitted'),
                    ':e'  => $email ?: null,
                    ':id' => $existing,
                ]);
            } else {
                db()->prepare("
                    INSERT INTO inquiry_families
                        (primary_name, primary_phone, primary_email,
                         status, priority, probability, campaign_id, source, notes, ip_hash)
                    VALUES (:n, :p, :e, 'lead', 'normal', :prob, :c, :s, :msg, :ip)
                ")->execute([
                    ':n'    => $name,
                    ':p'    => $phone ?: null,
                    ':e'    => $email ?: null,
                    ':prob' => crm_default_probability('lead'),
                    ':c'    => $campaign['id'] ?? null,
                    ':s'    => $campaign ? null : 'public_form',
                    ':msg'  => $msg ?: null,
                    ':ip'   => $ipHash,
                ]);
            }
            $submitted = true;
        }
    }
}

$pageTitle = 'Enquire — ' . app_name();
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Tell us about your child</h1>
        <p class="muted">Drop your details and we'll be in touch within a working day.
            <?php if ($campaign): ?>
                <br><span class="small">Campaign: <strong><?= e($campaign['name']) ?></strong></span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($submitted): ?>
    <div class="card">
        <h3>Thanks — we've got your message.</h3>
        <p>We'll reach out shortly. If it's urgent, you can also call us directly.</p>
    </div>
<?php else: ?>
    <?php if ($errors): ?>
        <div class="flash flash-error">
            <ul style="margin:0; padding-left: 1.1rem;">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="card">
        <?php if ($campaign): ?><input type="hidden" name="c" value="<?= e($campaign['name']) ?>"><?php endif; ?>
        <input type="text"  name="website_url" tabindex="-1" autocomplete="off"
               style="position:absolute; left:-10000px; width:1px; height:1px; opacity:0;"
               aria-hidden="true">

        <div class="row">
            <div class="field" style="flex: 2 1 280px;">
                <label>Your name *</label>
                <input name="name" required maxlength="160" value="<?= e($_POST['name'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Phone</label>
                <input name="phone" maxlength="40" value="<?= e($_POST['phone'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Email</label>
                <input name="email" type="email" maxlength="160" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
        </div>
        <div class="field">
            <label>Anything you'd like us to know? (child's age, when to call back, etc.)</label>
            <textarea name="message" rows="3"><?= e($_POST['message'] ?? '') ?></textarea>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Send enquiry</button>
        </div>
        <p class="muted small">By submitting you agree to be contacted about admissions.</p>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
