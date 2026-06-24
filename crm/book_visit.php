<?php
/**
 * crm/book_visit.php — PUBLIC school-visit booking page (no auth).
 *
 * The WhatsApp bot's "Book a visit" link points here. A parent picks a date
 * and slot; we find-or-create their lead (format-proof phone match), move it
 * forward to Tour scheduled, record a crm_appointments row + a tour
 * touchpoint, and (best-effort) send the WhatsApp confirmation via WACRM.
 *
 * Anti-abuse mirrors lead_submit.php: honeypot + per-IP hourly cap.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

// NO require_login — this is public.

const VISIT_PUBLIC_HOURLY_CAP = 5;

/** Bookable slots — school is open 9:30 AM to 6:00 PM. */
function visit_slots(): array
{
    return ['10:00', '10:30', '11:00', '11:30', '12:00', '15:30', '16:00', '16:30', '17:00'];
}

/** JSON mode — used by the marketing-site proxy (server-to-server). */
function visit_wants_json(): bool
{
    return (($_POST['format'] ?? '') === 'json')
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
}

/** Emit a JSON response and stop. */
function visit_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$submitted = false;
$errors    = [];
$when      = null;

// Post/Redirect/Get: success renders from ?booked=<ts> so a refresh can never
// re-submit the form (that's how duplicate appointments were born).
if (isset($_GET['booked'])) {
    $t = strtotime((string)$_GET['booked']);
    $submitted = true;
    $when = $t ? date('Y-m-d H:i:s', $t) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot — bots fill hidden fields, humans don't. Pretend success.
    if (trim($_POST['website_url'] ?? '') !== '') {
        if (visit_wants_json()) visit_json(['ok' => true]);
        $submitted = true;
    } else {
        $name  = trim($_POST['name']  ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $child = trim($_POST['child_name'] ?? '');
        $prog  = trim($_POST['programme'] ?? '');
        $date  = trim($_POST['visit_date'] ?? '');
        $slot  = trim($_POST['visit_slot'] ?? '');

        if ($name === '')  $errors[] = 'Please tell us your name.';
        if (strlen(preg_replace('/\D/', '', $phone)) < 10) $errors[] = 'Add a phone number so we can confirm.';
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
            $errors[] = 'Pick a date from today onwards.';
        }
        if (!in_array($slot, visit_slots(), true)) $errors[] = 'Pick a time slot.';

        // A trusted proxy (the marketing site) forwards the real client IP plus
        // the shared secret, so per-parent rate limiting still holds for proxied
        // bookings instead of collapsing onto the proxy server's single IP.
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $secret   = (string) app_setting('wacrm_sso_secret', '');
        $provided = (string) ($_SERVER['HTTP_X_LEAD_SECRET'] ?? '');
        if ($secret !== '' && hash_equals($secret, $provided)) {
            $xff = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
            if ($xff !== '') $clientIp = $xff;
        }
        $ipHash = hash('sha256', $clientIp);
        if (!$errors) {
            $recent = db()->prepare("
                SELECT COUNT(*) FROM crm_appointments
                WHERE source = CONCAT('web:', :h) AND created_at > NOW() - INTERVAL 1 HOUR");
            $recent->execute([':h' => substr($ipHash, 0, 16)]);
            if ((int)$recent->fetchColumn() >= VISIT_PUBLIC_HOURLY_CAP) {
                $errors[] = 'Too many bookings from your network. Please call us on +91 956244 0111.';
            }
        }

        if (!$errors) {
            $pdo  = db();
            $when = $date . ' ' . $slot . ':00';

            // Find-or-create the lead (never duplicate).
            $leadId = crm_find_lead_by_phone($phone);
            if (!$leadId) {
                $pdo->prepare("INSERT INTO inquiry_families
                        (primary_name, primary_phone, status, priority, probability, source, last_inbound_at)
                     VALUES (:n, :p, 'tour_scheduled', 'normal', :prob, 'visit_booking', NOW())")
                    ->execute([':n' => substr($name, 0, 160), ':p' => $phone,
                               ':prob' => crm_default_probability('tour_scheduled')]);
                $leadId = (int)$pdo->lastInsertId();
                crm_audit_log('visit_lead_created', $leadId, ['via' => 'book_visit']);
            } else {
                // Forward-only move to Tour scheduled.
                $cur = $pdo->prepare("SELECT status FROM inquiry_families WHERE id = :id");
                $cur->execute([':id' => $leadId]);
                $st = (string)$cur->fetchColumn();
                if (!in_array($st, ['enrolled', 'lost'], true)
                    && crm_stage_rank('tour_scheduled') > crm_stage_rank($st)) {
                    $pdo->prepare("UPDATE inquiry_families SET status='tour_scheduled', probability=:p WHERE id=:id")
                        ->execute([':p' => crm_default_probability('tour_scheduled'), ':id' => $leadId]);
                    crm_audit_log('status_changed', $leadId,
                        ['from' => $st, 'to' => 'tour_scheduled', 'via' => 'book_visit']);
                }
                $pdo->prepare("UPDATE inquiry_families SET last_inbound_at = NOW(), quiet_reminded_at = NULL WHERE id = :id")
                    ->execute([':id' => $leadId]);
            }

            // One upcoming visit per family: a second booking RESCHEDULES the
            // existing one instead of stacking a new row. Re-submitting the
            // same slot is a clean no-op (idempotent against double-taps).
            $ex = $pdo->prepare("SELECT id, scheduled_at FROM crm_appointments
                                 WHERE family_id = :f AND status = 'booked' AND scheduled_at >= NOW()
                                 ORDER BY scheduled_at ASC LIMIT 1");
            $ex->execute([':f' => $leadId]);
            $existing  = $ex->fetch();
            $sameSlot  = $existing && (string)$existing['scheduled_at'] === $when;

            if ($sameSlot) {
                // Nothing to do — already booked for exactly this time.
            } elseif ($existing) {
                $pdo->prepare("UPDATE crm_appointments
                               SET scheduled_at = :w,
                                   child_name = COALESCE(NULLIF(:c, ''), child_name),
                                   programme  = COALESCE(NULLIF(:pr, ''), programme)
                               WHERE id = :id")
                    ->execute([':w' => $when, ':c' => substr($child, 0, 120),
                               ':pr' => substr($prog, 0, 60), ':id' => (int)$existing['id']]);
                $pdo->prepare("INSERT INTO inquiry_touchpoints (family_id, kind, occurred_at, body, created_by)
                               VALUES (:f, 'tour', NOW(), :b, NULL)")
                    ->execute([':f' => $leadId,
                               ':b' => 'Visit rescheduled online to ' . date('D, M j \a\t g:i a', strtotime($when))]);
            } else {
                $pdo->prepare("INSERT INTO crm_appointments
                        (family_id, scheduled_at, child_name, programme, status, source)
                     VALUES (:f, :w, :c, :pr, 'booked', CONCAT('web:', :ip))")
                    ->execute([':f' => $leadId, ':w' => $when,
                               ':c' => $child !== '' ? substr($child, 0, 120) : null,
                               ':pr' => $prog !== '' ? substr($prog, 0, 60) : null,
                               ':ip' => substr($ipHash, 0, 16)]);
                $pdo->prepare("INSERT INTO inquiry_touchpoints (family_id, kind, occurred_at, body, created_by)
                               VALUES (:f, 'tour', NOW(), :b, NULL)")
                    ->execute([':f' => $leadId,
                               ':b' => 'Visit booked online for ' . date('D, M j \a\t g:i a', strtotime($when))
                                     . ($prog !== '' ? " · programme: $prog" : '')]);
            }

            // Best-effort WhatsApp confirmation (in-window text or template).
            try {
                if ($sameSlot) throw new RuntimeException('already-confirmed');
                $vars = crm_wa_vars_for_families([$leadId])[$leadId] ?? [];
                if (($vars['parent_name'] ?? '') === '') $vars['parent_name'] = $name;
                if ($child !== '') $vars['child_name'] = $child;
                $vars = crm_wa_defaults($vars);
                $wa = crm_stage_wa('tour_scheduled');
                $text = 'Lovely, ' . $vars['parent_name'] . '! Your visit to The Little Graduates is booked for '
                      . date('l, M j \a\t g:i a', strtotime($when))
                      . '. We\'re near Little Flower Church, Pottakuzhi, Kaloor: https://maps.app.goo.gl/EQ45HcH7gcGzt2Ny9 — see you soon 😊';
                wacrm_send_to_lead($phone, $text, $wa['wa_template'], $wa['wa_template_lang'], $vars);
            } catch (Throwable $e) {
                // Booking still stands even if the confirmation can't send.
            }

            // JSON for the proxy; PRG redirect for the standalone HTML page.
            if (visit_wants_json()) visit_json(['ok' => true, 'booked_at' => $when]);
            redirect('/crm/book_visit.php?booked=' . urlencode($when));
        }
    }
}

// JSON callers (the marketing-site proxy) get validation/rate-limit failures as
// JSON, never the HTML page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors && visit_wants_json()) {
    visit_json(['ok' => false, 'error' => implode(' ', $errors)], 422);
}

$pageTitle = 'Book a school visit — ' . app_name();
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Book a school visit 🌱</h1>
        <p class="muted">Come see a Montessori morning in action — it explains us better than any message.
            We're open 9:30 AM to 6:00 PM.</p>
    </div>
</div>

<?php if ($submitted): ?>
    <div class="card" style="border-left:4px solid #25d366; max-width:34rem;">
        <h3 style="margin-top:0;">You're booked! 🎉</h3>
        <p>We've saved your visit<?= $when ? ' for <strong>' . e(date('l, F j \a\t g:i a', strtotime($when))) . '</strong>' : '' ?>.
           Our team will confirm on WhatsApp shortly.</p>
        <p class="muted">Find us near Little Flower Church, Pottakuzhi, Kaloor —
            <a href="https://maps.app.goo.gl/EQ45HcH7gcGzt2Ny9">open in Maps</a>.
            Anything urgent: +91 956244 0111.</p>
    </div>
<?php else: ?>
    <?php foreach ($errors as $err): ?>
        <div class="flash flash-error"><?= e($err) ?></div>
    <?php endforeach; ?>
    <div class="card" style="max-width:34rem;">
        <form method="post" class="form-grid">
            <!-- Honeypot: humans never see or fill this. -->
            <div style="position:absolute; left:-5000px;" aria-hidden="true">
                <input type="text" name="website_url" tabindex="-1" autocomplete="off">
            </div>
            <label><span>Your name</span>
                <input type="text" name="name" required maxlength="120" value="<?= e($_POST['name'] ?? '') ?>"></label>
            <label><span>WhatsApp number</span>
                <input type="tel" name="phone" required value="<?= e($_POST['phone'] ?? '+91 ') ?>" placeholder="+91 98765 43210"></label>
            <label><span>Child's name <small class="muted">(optional)</small></span>
                <input type="text" name="child_name" maxlength="120" value="<?= e($_POST['child_name'] ?? '') ?>"></label>
            <label><span>Programme</span>
                <select name="programme">
                    <option value="">Not sure yet</option>
                    <?php foreach (['Playgroup (1.5+ yrs)','Nursery / Mont 1 (3+ yrs)','LKG / Mont 2 (4+ yrs)','UKG / Mont 3 (5+ yrs)','Daycare','Afterschool'] as $p): ?>
                        <option value="<?= e($p) ?>" <?= ($_POST['programme'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label><span>Date</span>
                <input type="date" name="visit_date" required min="<?= e(date('Y-m-d')) ?>" value="<?= e($_POST['visit_date'] ?? '') ?>"></label>
            <label><span>Time</span>
                <select name="visit_slot" required>
                    <option value="">Pick a slot…</option>
                    <?php foreach (visit_slots() as $s): ?>
                        <option value="<?= e($s) ?>" <?= ($_POST['visit_slot'] ?? '') === $s ? 'selected' : '' ?>><?= e(date('g:i a', strtotime("2000-01-01 $s"))) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <div class="actions">
                <button class="btn btn-primary">Book my visit</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
