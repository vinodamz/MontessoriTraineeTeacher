<?php
/**
 * daycare/index.php — the whole daycare attendance module: one screen.
 *
 * Built for a "track" user who holds only the `daycare` module. They land here
 * straight from login (index.php sends single-module users into their module),
 * see every daycare child and every staff member, and tap Check in / Check out.
 * Times are stamped by the server — the page never sends a time.
 *
 *   GET  ?date=YYYY-MM-DD   → that day's sheet (defaults to today)
 *   POST op=mark            → stamp check-in / check-out
 *   POST op=undo            → clear a mistaken stamp (today only)
 *   POST op=comment         → save the optional comment
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/daycare.php';

$user  = require_module('daycare');
$today = date('Y-m-d');

// Date is validated and clamped to today — an attendance sheet for a future date
// would only ever collect wrong data.
$date = (string)($_GET['date'] ?? $_POST['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) $date = $today;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op    = $_POST['op'] ?? '';
    $kind  = ($_POST['kind'] ?? '') === 'staff' ? 'staff' : 'child';
    $id    = (int)($_POST['id'] ?? 0);
    $which = ($_POST['which'] ?? '') === 'out' ? 'out' : 'in';
    $back  = '/daycare/index.php?date=' . urlencode($date);

    if ($id <= 0) { flash_set('error', 'Nothing selected.'); redirect($back); }

    try {
        if ($op === 'mark') {
            $t = $kind === 'staff'
                ? daycare_mark_staff($id, $date, $which, (int)$user['id'])
                : daycare_mark_child($id, $date, $which, (int)$user['id']);
            $msg = ($which === 'in' ? 'Checked in at ' : 'Checked out at ') . daycare_time($t) . '.';
            // Say so when the check-in landed past the person's shift start —
            // the status changes silently otherwise.
            if ($kind === 'staff' && $which === 'in') {
                $start = staff_shift($id)['start'];
                if (staff_arrival_status($start, $t) === 'late') {
                    $msg .= ' Marked late (due in at ' . staff_time_label($start) . ').';
                }
            }
            flash_set('ok', $msg);
        } elseif ($op === 'undo') {
            if (daycare_undo($kind, $id, $date, $which)) {
                flash_set('ok', 'Time cleared.');
            } else {
                flash_set('error', "Only today's times can be cleared.");
            }
        } elseif ($op === 'comment') {
            daycare_save_comment($kind, $id, $date, (string)($_POST['comment'] ?? ''), (int)$user['id']);
            flash_set('ok', 'Comment saved.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Could not save: ' . $e->getMessage());
    }
    redirect($back);
}

$children    = daycare_children();
$staff       = daycare_staff();
$shiftMap    = staff_shift_map();
$childMarks  = daycare_child_attendance($date);
$staffMarks  = daycare_staff_attendance($date);
$childTally  = daycare_tally($children, $childMarks, 'id');
$staffTally  = daycare_tally($staff, $staffMarks, 'id');
$isToday     = $date === $today;

/**
 * One attendance row: name, in, out, comment, actions.
 * $kind is 'child' or 'staff'; $mark is that row's stored attendance or null.
 * $late marks the check-in time as late (staff only — see staff_arrival_status).
 */
function daycare_row(string $kind, int $id, string $name, ?array $mark, string $date, bool $isToday, string $sub = '', bool $late = false): void
{
    $in   = daycare_time($mark['check_in']  ?? null);
    $out  = daycare_time($mark['check_out'] ?? null);
    $note = (string)($mark['notes'] ?? '');
    $fid  = $kind . $id;
    ?>
    <tr class="<?= $in !== '' && $out === '' ? 'dc-present' : ($out !== '' ? 'dc-left' : '') ?>">
        <td class="dc-name">
            <?= e($name) ?>
            <?php if ($sub !== ''): ?><span class="muted small"> · <?= e($sub) ?></span><?php endif; ?>
        </td>

        <td class="dc-cell">
            <?php if ($in === ''): ?>
                <form method="post" class="dc-act">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="date" value="<?= e($date) ?>">
                    <input type="hidden" name="op" value="mark">
                    <input type="hidden" name="kind" value="<?= e($kind) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="which" value="in">
                    <button class="btn btn-primary dc-btn" type="submit">Check in</button>
                </form>
            <?php else: ?>
                <span class="dc-time"><?= e($in) ?></span>
                <?php if ($late): ?><span class="pill dc-late">Late</span><?php endif; ?>
                <?php if ($isToday): ?>
                    <form method="post" class="dc-act" onsubmit="return confirm('Clear the check-in time for <?= e(addslashes($name)) ?>?');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="date" value="<?= e($date) ?>">
                        <input type="hidden" name="op" value="undo">
                        <input type="hidden" name="kind" value="<?= e($kind) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="which" value="in">
                        <button class="link-btn" title="Clear this time">×</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </td>

        <td class="dc-cell">
            <?php if ($out === ''): ?>
                <?php if ($in !== ''): ?>
                    <form method="post" class="dc-act">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="date" value="<?= e($date) ?>">
                        <input type="hidden" name="op" value="mark">
                        <input type="hidden" name="kind" value="<?= e($kind) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="which" value="out">
                        <button class="btn dc-btn" type="submit">Check out</button>
                    </form>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="dc-time"><?= e($out) ?></span>
                <?php if ($isToday): ?>
                    <form method="post" class="dc-act" onsubmit="return confirm('Clear the check-out time for <?= e(addslashes($name)) ?>?');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="date" value="<?= e($date) ?>">
                        <input type="hidden" name="op" value="undo">
                        <input type="hidden" name="kind" value="<?= e($kind) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="which" value="out">
                        <button class="link-btn" title="Clear this time">×</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </td>

        <td class="dc-comment">
            <form method="post" class="dc-note">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <input type="hidden" name="op" value="comment">
                <input type="hidden" name="kind" value="<?= e($kind) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input name="comment" maxlength="255" value="<?= e($note) ?>"
                       placeholder="Optional" aria-label="Comment for <?= e($name) ?>">
                <button class="btn btn-ghost dc-save" type="submit">Save</button>
            </form>
        </td>
    </tr>
    <?php
}

$pageTitle  = 'Daycare attendance';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Daycare attendance</h1>
        <p class="muted">
            <?= e(date('l, j M Y', strtotime($date))) ?>
            <?php if (!$isToday): ?> · <strong>not today</strong><?php endif; ?>
            · Children in: <?= (int)$childTally['present'] ?>/<?= count($children) ?>
            · Staff in: <?= (int)$staffTally['present'] ?>/<?= count($staff) ?>
        </p>
    </div>
    <div class="actionbar">
        <form method="get" class="dc-datepick">
            <label for="d" class="muted small">Date</label>
            <input id="d" type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>"
                   onchange="this.form.submit()">
        </form>
        <?php if (!$isToday): ?>
            <a class="btn btn-ghost" href="/daycare/index.php">Back to today</a>
        <?php endif; ?>
        <a class="btn" href="/daycare/summary.php">Summary</a>
    </div>
</div>

<?php if (!$isToday): ?>
    <div class="flash flash-info">
        You're looking at <?= e(date('j M Y', strtotime($date))) ?>. Times can still be
        recorded, but only today's times can be cleared.
    </div>
<?php endif; ?>

<div class="card">
    <h3>Daycare children <span class="muted small">· <?= count($children) ?></span></h3>
    <?php if (!$children): ?>
        <p class="muted">
            No children are in the <strong>Daycare</strong> grade yet. A child appears here
            once their grade is set to Daycare on their student record.
        </p>
    <?php else: ?>
        <div class="dc-scroll">
            <table class="admin-table dc-table">
                <thead>
                    <tr><th>Child</th><th>In</th><th>Out</th><th>Comment</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($children as $c):
                        $cid  = (int)$c['id'];
                        $name = trim((string)$c['first_name'] . ' ' . (string)$c['last_name']);
                        daycare_row('child', $cid, $name, $childMarks[$cid] ?? null, $date, $isToday,
                                    (string)($c['section'] ?? '') !== '' ? 'Sec ' . $c['section'] : '');
                    endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Staff <span class="muted small">· <?= count($staff) ?></span></h3>
    <?php if (!$staff): ?>
        <p class="muted">No active staff found.</p>
    <?php else: ?>
        <div class="dc-scroll">
            <table class="admin-table dc-table">
                <thead>
                    <tr><th>Staff</th><th>In</th><th>Out</th><th>Comment</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $st):
                        $uid   = (int)$st['id'];
                        $mark  = $staffMarks[$uid] ?? null;
                        $shift = $shiftMap[$uid] ?? ['start' => null, 'end' => null];
                        // The shift sits under the name so whoever is marking
                        // can see at a glance who was due in when.
                        $sub   = function_exists('role_label') ? role_label((string)($st['role'] ?? '')) : '';
                        $label = staff_shift_label($shift);
                        if ($label !== '') $sub = trim($sub . ' · ' . $label, ' ·');
                        daycare_row('staff', $uid, (string)$st['name'], $mark, $date, $isToday, $sub,
                                    ($mark['status'] ?? '') === 'late');
                    endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($shiftMap): ?>
            <p class="muted small">
                A check-in later than the staff member's own start time — plus
                <?= (int)staff_late_grace_minutes() ?> minutes' grace — is recorded as
                <strong>late</strong>. Staff with no shift set are never marked late.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
