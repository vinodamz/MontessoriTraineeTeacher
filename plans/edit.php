<?php
/**
 * plans/edit.php — fill / edit a weekly plan (draft or changes_requested).
 *
 *   GET  ?week=YYYY-Www [&teacher=ID for admin]
 *   POST op=save|submit|upload_media|delete_media
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plans.php';

$user = plan_require();

if (!plan_tables_ready()) {
    flash_set('error', 'Run migrations — weekly plans tables are missing.');
    redirect('/index.php');
}

$week = (string)($_GET['week'] ?? $_POST['week'] ?? plan_current_week_key());
try {
    $week = plan_parse_week_key($week);
} catch (InvalidArgumentException $e) {
    flash_set('error', $e->getMessage());
    redirect('/plans/index.php');
}

$teacherId = (int)$user['id'];
if (($user['role'] ?? '') === 'admin') {
    $reqTeacher = (int)($_GET['teacher'] ?? $_POST['teacher_id'] ?? 0);
    if ($reqTeacher > 0) $teacherId = $reqTeacher;
}

$plan = plan_ensure($teacherId, $week);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    try {
        if ($op === 'save' || $op === 'submit') {
            $daysIn = [];
            foreach ((array)($_POST['day'] ?? []) as $date => $row) {
                if (!is_array($row)) continue;
                $daysIn[(string)$date] = [
                    'activities' => (string)($row['activities'] ?? ''),
                    'materials'  => (string)($row['materials'] ?? ''),
                ];
            }
            plan_save_draft((int)$plan['id'], $user, [
                'class_grade'          => (string)($_POST['class_grade'] ?? ''),
                'note_to_principal'    => (string)($_POST['note_to_principal'] ?? ''),
                'weekly_materials'     => (string)($_POST['weekly_materials'] ?? ''),
                'addressed_to_user_id' => (int)($_POST['addressed_to_user_id'] ?? 0),
            ], $daysIn);

            // Media uploads on same save form
            if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'] ?? null)) {
                $n = count($_FILES['photos']['name']);
                for ($i = 0; $i < $n; $i++) {
                    if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                    $file = [
                        'name'     => $_FILES['photos']['name'][$i],
                        'type'     => $_FILES['photos']['type'][$i],
                        'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                        'error'    => $_FILES['photos']['error'][$i],
                        'size'     => $_FILES['photos']['size'][$i],
                    ];
                    plan_media_store($file, (int)$plan['id'], (int)$user['id']);
                }
            }
            if (!empty($_FILES['voice']) && ($_FILES['voice']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                plan_media_store($_FILES['voice'], (int)$plan['id'], (int)$user['id']);
            }

            if ($op === 'submit') {
                // Reload after save so submit validates fresh content
                $plan = plan_get((int)$plan['id']) ?? $plan;
                plan_submit((int)$plan['id'], $user);
                flash_set('ok', 'Weekly plan submitted.');
                redirect('/plans/view.php?id=' . (int)$plan['id']);
            }
            flash_set('ok', 'Draft saved.');
            $redir = '/plans/edit.php?week=' . rawurlencode($week);
            if (($user['role'] ?? '') === 'admin' && $teacherId !== (int)$user['id']) {
                $redir .= '&teacher=' . $teacherId;
            }
            redirect($redir);
        }

        if ($op === 'delete_media') {
            plan_media_delete((int)($_POST['media_id'] ?? 0), $user);
            flash_set('ok', 'Attachment removed.');
            $redir = '/plans/edit.php?week=' . rawurlencode($week);
            if (($user['role'] ?? '') === 'admin' && $teacherId !== (int)$user['id']) {
                $redir .= '&teacher=' . $teacherId;
            }
            redirect($redir);
        }

        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash_set('error', $e->getMessage());
        $redir = '/plans/edit.php?week=' . rawurlencode($week);
        if (($user['role'] ?? '') === 'admin' && $teacherId !== (int)$user['id']) {
            $redir .= '&teacher=' . $teacherId;
        }
        redirect($redir);
    }
}

$plan = plan_get((int)$plan['id']) ?? $plan;
if (!plan_can_edit($user, $plan)) {
    flash_set('error', 'This plan is locked. Open the read-only view instead.');
    redirect('/plans/view.php?id=' . (int)$plan['id']);
}

$daysMeta = plan_week_days($week);
$dayRows  = [];
foreach (plan_days((int)$plan['id']) as $r) {
    $dayRows[(string)$r['day_date']] = $r;
}
$media   = plan_media_list((int)$plan['id']);
$admins  = plan_admin_options();
$reviews = plan_reviews((int)$plan['id']);

$pageTitle = 'Edit weekly plan';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Weekly plan</h1>
        <p class="muted">
            <?= e(plan_week_label($week)) ?>
            <?php if (!empty($plan['teacher_name'])): ?>
                · <?= e((string)$plan['teacher_name']) ?>
            <?php endif; ?>
            · <span class="pill"><?= e(plan_status_label((string)$plan['status'])) ?></span>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/plans/index.php">All plans</a>
        <a class="btn btn-ghost" href="/plans/view.php?id=<?= (int)$plan['id'] ?>">Preview</a>
    </div>
</div>

<form method="post" enctype="multipart/form-data" class="plan-edit-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="week" value="<?= e($week) ?>">
    <input type="hidden" name="teacher_id" value="<?= (int)$teacherId ?>">

    <div class="card">
        <label>Class / grade
            <input type="text" name="class_grade" maxlength="120"
                   value="<?= e((string)$plan['class_grade']) ?>"
                   placeholder="e.g. Casa, Primary">
        </label>
        <p class="muted small">Shown as context for the Principal. Pre-filled from your students.</p>
    </div>

    <?php foreach ($daysMeta as $meta):
        $date = $meta['date'];
        $row  = $dayRows[$date] ?? ['activities' => '', 'materials' => ''];
    ?>
        <div class="card plan-day">
            <h2 style="margin-top:0"><?= e($meta['label']) ?></h2>
            <label>Activities / lesson detail
                <textarea name="day[<?= e($date) ?>][activities]" rows="4"
                          placeholder="What will you do this day?"><?= e((string)$row['activities']) ?></textarea>
            </label>
            <label>Materials needed
                <textarea name="day[<?= e($date) ?>][materials]" rows="2"
                          placeholder="Materials for this day"><?= e((string)$row['materials']) ?></textarea>
            </label>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <h2 style="margin-top:0">Weekly materials summary</h2>
        <p class="muted small">The Principal sees this. Leave blank to auto-fill from each day’s materials list.</p>
        <label>
            <textarea name="weekly_materials" rows="4"
                      placeholder="Combined materials list for the week"><?= e((string)($plan['weekly_materials'] ?? '')) ?></textarea>
        </label>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Note to Principal / head</h2>
        <label>Addressed to
            <select name="addressed_to_user_id">
                <option value="0">Any admin</option>
                <?php foreach ($admins as $a): ?>
                    <option value="<?= (int)$a['id'] ?>"
                        <?= (int)($plan['addressed_to_user_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= e((string)$a['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Note
            <textarea name="note_to_principal" rows="4"
                      placeholder="Anything the Principal should know this week"><?= e((string)($plan['note_to_principal'] ?? '')) ?></textarea>
        </label>

        <?php if ($media): ?>
            <div class="plan-media-list" style="margin:1rem 0;display:grid;gap:.75rem">
                <?php foreach ($media as $m): ?>
                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                        <?php if ($m['kind'] === 'photo'): ?>
                            <a href="/plans/media.php?id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
                                <img src="/plans/media.php?id=<?= (int)$m['id'] ?>" alt=""
                                     style="max-width:120px;max-height:90px;border-radius:8px;object-fit:cover">
                            </a>
                        <?php else: ?>
                            <audio controls src="/plans/media.php?id=<?= (int)$m['id'] ?>" style="max-width:260px"></audio>
                        <?php endif; ?>
                        <span class="muted small"><?= e((string)$m['original_filename']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <label>Attach photos
            <input type="file" name="photos[]" accept="image/*" multiple>
        </label>
        <label>Voice message
            <input type="file" name="voice" accept="audio/*,.m4a,.mp3,.wav,.webm,.ogg">
        </label>
        <div class="plan-voice-rec" style="margin-top:.75rem">
            <button type="button" class="btn btn-ghost" id="plan-rec-btn" hidden>Record voice note</button>
            <span class="muted small" id="plan-rec-status"></span>
        </div>
    </div>

    <div class="actionbar sticky-actions" style="position:sticky;bottom:0;background:var(--bg,#fff);padding:.75rem 0;gap:.5rem;flex-wrap:wrap">
        <button class="btn" type="submit" name="op" value="save">Save draft</button>
        <button class="btn btn-primary" type="submit" name="op" value="submit"
                onclick="return confirm('Submit this week’s plan for Principal review?')">Submit</button>
    </div>
</form>

<?php if ($media): ?>
    <div class="card">
        <h2 style="margin-top:0">Remove attachments</h2>
        <?php foreach ($media as $m): ?>
            <form method="post" style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;flex-wrap:wrap">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="delete_media">
                <input type="hidden" name="week" value="<?= e($week) ?>">
                <input type="hidden" name="teacher_id" value="<?= (int)$teacherId ?>">
                <input type="hidden" name="media_id" value="<?= (int)$m['id'] ?>">
                <span class="muted small"><?= e((string)$m['original_filename']) ?> (<?= e((string)$m['kind']) ?>)</span>
                <button type="submit" class="btn btn-ghost small danger">Remove</button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($reviews): ?>
    <div class="card">
        <h2 style="margin-top:0">Review history</h2>
        <ul style="list-style:none;padding:0;margin:0">
            <?php foreach ($reviews as $r): ?>
                <li style="padding:.4rem 0;border-bottom:1px solid var(--line,#eee)">
                    <strong><?= e(ucfirst(str_replace('_', ' ', (string)$r['action']))) ?></strong>
                    <span class="muted small">
                        · <?= e((string)($r['reviewer_name'] ?? 'Someone')) ?>
                        · <?= e(substr((string)$r['created_at'], 0, 16)) ?>
                    </span>
                    <?php if (trim((string)($r['body'] ?? '')) !== ''): ?>
                        <div><?= nl2br(e((string)$r['body'])) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<script>
(function () {
    var btn = document.getElementById('plan-rec-btn');
    var status = document.getElementById('plan-rec-status');
    var voiceInput = document.querySelector('input[name="voice"]');
    if (!btn || !voiceInput) return;
    var can = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    if (!can) return;
    btn.hidden = false;
    var rec = null, chunks = [], stream = null;
    btn.addEventListener('click', async function () {
        if (rec && rec.state === 'recording') {
            rec.stop();
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            var mime = '';
            ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].some(function (t) {
                if (window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) { mime = t; return true; }
                return false;
            });
            rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
            chunks = [];
            rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
            rec.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                var type = (rec.mimeType || 'audio/webm').split(';')[0];
                var ext = type.indexOf('mp4') >= 0 ? 'm4a' : (type.indexOf('ogg') >= 0 ? 'ogg' : 'webm');
                var blob = new Blob(chunks, { type: type });
                var file;
                try { file = new File([blob], 'voice-note.' + ext, { type: type }); }
                catch (e) { file = blob; }
                var dt = new DataTransfer();
                dt.items.add(file);
                voiceInput.files = dt.files;
                status.textContent = 'Voice note attached — save or submit to upload.';
                btn.textContent = 'Record voice note';
            };
            rec.start();
            btn.textContent = 'Stop recording';
            status.textContent = 'Recording…';
        } catch (e) {
            status.textContent = 'Microphone unavailable.';
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
