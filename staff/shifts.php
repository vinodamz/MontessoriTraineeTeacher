<?php
/**
 * staff/shifts.php — admin-only: each staff member's working hours.
 *
 * The school staffs by shift, so lateness can't be one clock time for everyone.
 * Set a start and end per person here and every check-in in the app measures
 * against *that* person's start plus the grace period — a 09:00 start begins
 * counting late at 09:05.
 *
 * One form for the whole roster: shifts are set in a batch (a new term, a
 * rota change), not one person at a time, and this is the only screen where
 * they can be edited at all. Staff cannot set their own — /staff/profile.php
 * and the public /staff/apply.php form write staff_profile_save(), which
 * deliberately leaves these two columns alone.
 *
 *   GET               → grid of every active staff member (?all=1 for inactive too)
 *   POST op=save      → save all shifts + the grace setting
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');
if (!staff_is_admin($user)) {
    http_response_code(403);
    echo 'Forbidden — only admins can set working hours.';
    exit;
}

$showAll = !empty($_GET['all']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $back = '/staff/shifts.php' . ($showAll ? '?all=1' : '');

    if (($_POST['op'] ?? '') === 'save') {
        $starts = (array)($_POST['work_start'] ?? []);
        $ends   = (array)($_POST['work_end'] ?? []);
        $saved  = 0;
        $bad    = [];

        // Only ids that were actually rendered on the form are accepted, so a
        // hand-crafted POST can't reach a user who isn't in the staff roster.
        $allowed = [];
        foreach (staff_roster() as $s) $allowed[(int)$s['id']] = (string)$s['name'];

        try {
            foreach ($starts as $uid => $rawStart) {
                $uid = (int)$uid;
                if (!isset($allowed[$uid])) continue;

                $start = staff_time_norm($rawStart);
                $end   = staff_time_norm($ends[$uid] ?? '');

                // An end before the start is either a typo or an overnight
                // shift the rest of the app can't represent; refuse it rather
                // than store hours that read as negative.
                if ($start !== null && $end !== null && $end <= $start) {
                    $bad[] = $allowed[$uid];
                    continue;
                }
                staff_shift_save($uid, $start, $end);
                $saved++;
            }

            $grace = trim((string)($_POST['grace'] ?? ''));
            if (preg_match('/^\d+$/', $grace)) {
                $g = max(0, min(120, (int)$grace));
                db()->prepare("
                    INSERT INTO app_settings (setting_key, setting_value)
                    VALUES ('staff_late_grace_minutes', :v)
                    ON DUPLICATE KEY UPDATE setting_value = :v2
                ")->execute([':v' => (string)$g, ':v2' => (string)$g]);
                app_setting_clear_cache();
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not save working hours: ' . $e->getMessage());
            redirect($back);
        }

        if ($bad) {
            flash_set('error', 'Saved ' . $saved . ', but skipped ' . implode(', ', $bad)
                             . ' — the end time must be after the start time.');
        } else {
            flash_set('ok', 'Working hours saved.');
        }
        redirect($back);
    }
    redirect($back);
}

$roster  = staff_roster(!$showAll);
$shifts  = staff_shift_map();
$grace   = staff_late_grace_minutes();
$withSet = 0;
foreach ($roster as $s) {
    if (!empty($shifts[(int)$s['id']]['start'])) $withSet++;
}

$pageTitle  = 'Working hours';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Working hours</h1>
        <p class="muted">
            <a href="/staff/index.php">← Staff</a>
            · <?= $withSet ?> of <?= count($roster) ?> have a shift set
            · grace <?= (int)$grace ?> min
        </p>
    </div>
    <div class="actionbar">
        <?php if ($showAll): ?>
            <a class="btn btn-ghost" href="/staff/shifts.php">Active only</a>
        <?php else: ?>
            <a class="btn btn-ghost" href="/staff/shifts.php?all=1">Show inactive too</a>
        <?php endif; ?>
        <a class="btn" href="/staff/attendance.php">Today's attendance</a>
    </div>
</div>

<form method="post" action="/staff/shifts.php<?= $showAll ? '?all=1' : '' ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="save">

    <div class="card">
        <h3>Late after</h3>
        <p class="muted small" style="margin-top:0;">
            Someone counts as late once they check in later than their own start time
            plus this many minutes. Leave a person's start time blank and they're never
            marked late — a blank shift shouldn't create a late record for anyone.
        </p>
        <label for="grace">Grace period (minutes)</label>
        <input id="grace" type="number" name="grace" min="0" max="120" step="1"
               value="<?= (int)$grace ?>" style="max-width:8rem;">
    </div>

    <div class="card">
        <h3>Shifts <span class="muted small">· <?= count($roster) ?></span></h3>
        <?php if (!$roster): ?>
            <p class="muted">No staff on the roster yet.</p>
        <?php else: ?>
            <div class="dc-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Starts</th>
                            <th>Ends</th>
                            <th>Late from</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roster as $s):
                            $uid    = (int)$s['id'];
                            $sh     = $shifts[$uid] ?? ['start' => null, 'end' => null];
                            $cutoff = staff_late_cutoff($sh['start'], $grace);
                        ?>
                            <tr>
                                <td>
                                    <a href="/staff/view.php?id=<?= $uid ?>"><?= e((string)$s['name']) ?></a>
                                    <?php if ((int)$s['active'] !== 1): ?>
                                        <span class="pill">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted"><?= e(role_label((string)$s['role'])) ?></td>
                                <td>
                                    <input type="time" name="work_start[<?= $uid ?>]"
                                           value="<?= e($sh['start'] !== null ? substr($sh['start'], 0, 5) : '') ?>"
                                           aria-label="Start time for <?= e((string)$s['name']) ?>">
                                </td>
                                <td>
                                    <input type="time" name="work_end[<?= $uid ?>]"
                                           value="<?= e($sh['end'] !== null ? substr($sh['end'], 0, 5) : '') ?>"
                                           aria-label="End time for <?= e((string)$s['name']) ?>">
                                </td>
                                <td>
                                    <?php if ($cutoff !== null): ?>
                                        <strong><?= e(staff_time_label($cutoff)) ?></strong>
                                    <?php else: ?>
                                        <span class="muted">not tracked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top:1rem;">
                <button class="btn btn-primary" type="submit">Save working hours</button>
            </p>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <h3>How this is used</h3>
    <ul class="muted small" style="margin:0; padding-left:1.1rem;">
        <li>Checking in on the <a href="/daycare/index.php">daycare sheet</a> or with
            <em>Check in now</em> on the attendance page records <strong>present</strong>
            up to the grace cutoff and <strong>late</strong> after it.</li>
        <li>Clearing both times removes the shift, and that person stops being
            measured for lateness at all.</li>
        <li>Existing attendance is not recalculated — a change here applies from the
            next check-in onwards. An admin can still override any day's status on the
            attendance page.</li>
        <li>Staff can't change these times themselves; the personal-details form and
            the shared application link don't touch them.</li>
    </ul>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
