<?php
/**
 * materials/check.php — one material's condition detail for a month.
 *
 * Full condition form (condition, needs-replacement + qty, notes), the
 * photo/video uploader, the media gallery, and the audit history of who
 * marked this material when across every month.
 *
 *   GET  ?id=N&period=YYYY-MM
 *   POST op=save            save the condition mark (+ optional media in the
 *                           same submit)
 *   POST op=media_delete    remove one attachment
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$period = mm_current_period();

$mstmt = db()->prepare("SELECT * FROM mm_materials WHERE id = :id");
$mstmt->execute([':id' => $id]);
$material = $mstmt->fetch();
if (!$material) { http_response_code(404); echo 'Material not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'save') {
        $cond = (string)($_POST['condition_code'] ?? '');
        if (!isset(mm_conditions()[$cond])) {
            flash_set('error', 'Pick a condition.');
            redirect("check.php?id=$id&period=" . urlencode($period));
        }
        try {
            $checkId = mm_save_check(
                $id, $period, $cond,
                !empty($_POST['needs_replacement']),
                (int)($_POST['replace_qty'] ?? 0),
                trim((string)($_POST['notes'] ?? '')),
                (int)$user['id']
            );
            // Optional media in the same submit.
            if (!empty($_FILES['media']['name'][0] ?? $_FILES['media']['name'] ?? '')) {
                // Support one-or-many file inputs.
                if (is_array($_FILES['media']['name'])) {
                    $n = count($_FILES['media']['name']);
                    for ($i = 0; $i < $n; $i++) {
                        $one = [
                            'name'     => $_FILES['media']['name'][$i],
                            'type'     => $_FILES['media']['type'][$i],
                            'tmp_name' => $_FILES['media']['tmp_name'][$i],
                            'error'    => $_FILES['media']['error'][$i],
                            'size'     => $_FILES['media']['size'][$i],
                        ];
                        mm_media_store($one, $checkId, (int)$user['id']);
                    }
                } else {
                    mm_media_store($_FILES['media'], $checkId, (int)$user['id']);
                }
            }
            flash_set('ok', 'Condition saved.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect("check.php?id=$id&period=" . urlencode($period));
    }

    if ($op === 'media_delete') {
        mm_media_delete((int)($_POST['media_id'] ?? 0));
        flash_set('ok', 'Attachment removed.');
        redirect("check.php?id=$id&period=" . urlencode($period));
    }
}

// Current month's check (if any).
$cstmt = db()->prepare("
    SELECT c.*, u.name AS checked_by
    FROM mm_condition_checks c
    LEFT JOIN users u ON u.id = c.checked_by_user_id
    WHERE c.material_id = :id AND c.period = :p
");
$cstmt->execute([':id' => $id, ':p' => $period]);
$check = $cstmt->fetch();

$media = [];
if ($check) {
    $mm = db()->prepare("SELECT * FROM mm_condition_media WHERE check_id = :c ORDER BY uploaded_at");
    $mm->execute([':c' => $check['id']]);
    $media = $mm->fetchAll();
}

// History across all months (who marked, when, what).
$hist = db()->prepare("
    SELECT c.period, c.condition_code, c.needs_replacement, c.replace_qty, c.checked_at, u.name AS checked_by
    FROM mm_condition_checks c
    LEFT JOIN users u ON u.id = c.checked_by_user_id
    WHERE c.material_id = :id
    ORDER BY c.period DESC
");
$hist->execute([':id' => $id]);
$history = $hist->fetchAll();

$pageTitle = $material['name'] . ' — condition';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($material['name']) ?></h1>
        <p class="muted"><?= e($material['location']) ?> · condition for <strong><?= e(mm_period_label($period)) ?></strong></p>
    </div>
    <div class="head-actions"><a class="btn btn-ghost" href="index.php?period=<?= e($period) ?>">← Back</a></div>
</div>

<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Mark condition</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="period" value="<?= e($period) ?>">

        <div class="field">
            <label>Condition</label>
            <select name="condition_code" required>
                <option value="">— pick —</option>
                <?php foreach (mm_conditions() as $code => $meta): ?>
                    <option value="<?= e($code) ?>" <?= ($check['condition_code'] ?? '') === $code ? 'selected' : '' ?>
                            data-suggests="<?= $meta['suggests_replace'] ? '1' : '0' ?>">
                        <?= e($meta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:end;">
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="needs_replacement" id="needs" value="1" <?= !empty($check['needs_replacement']) ? 'checked' : '' ?>>
                    <span>Needs replacement (goes on the Kreedo list)</span>
                </label>
            </div>
            <div class="field" style="max-width:120px;">
                <label>Qty to replace</label>
                <input type="number" name="replace_qty" min="0" value="<?= (int)($check['replace_qty'] ?? 0) ?>">
            </div>
        </div>

        <div class="field">
            <label>Notes <span class="muted small">(optional — e.g. where the mould is, which part peeled)</span></label>
            <textarea name="notes" rows="2"><?= e($check['notes'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label>Add photo / video of the condition <span class="muted small">(optional; max <?= format_bytes(MM_MEDIA_MAX_BYTES) ?>)</span></label>
            <input type="file" name="media[]" accept="image/*,video/*" capture="environment" multiple>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save condition</button>
        </div>
    </form>

    <?php if ($check): ?>
        <p class="muted small" style="margin-top:.6rem;">
            Last marked by <strong><?= e($check['checked_by'] ?? 'Unknown') ?></strong>
            on <?= e(date('j M Y, g:ia', strtotime($check['checked_at']))) ?>.
        </p>
    <?php endif; ?>
</section>

<?php if ($media): ?>
<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Photos / videos this month</h2>
    <div style="display:flex; gap:.8rem; flex-wrap:wrap;">
        <?php foreach ($media as $md): ?>
            <div style="width:160px;">
                <?php if ($md['kind'] === 'video'): ?>
                    <video src="/materials/media.php?id=<?= (int)$md['id'] ?>" controls style="width:100%; border-radius:8px; background:#000;"></video>
                <?php else: ?>
                    <a href="/materials/media.php?id=<?= (int)$md['id'] ?>" target="_blank">
                        <img src="/materials/media.php?id=<?= (int)$md['id'] ?>" alt="condition" style="width:100%; border-radius:8px;">
                    </a>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Remove this attachment?')" style="margin-top:.25rem;">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="media_delete">
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="period" value="<?= e($period) ?>">
                    <input type="hidden" name="media_id" value="<?= (int)$md['id'] ?>">
                    <button class="link-btn danger small">remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <h2 style="margin-top:0;">History</h2>
    <?php if (!$history): ?>
        <p class="muted">No condition marks yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Month</th><th>Condition</th><th>Replace</th><th>Marked by</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= e(mm_period_label($h['period'])) ?></td>
                <td><?= e(mm_condition_label($h['condition_code'])) ?></td>
                <td><?= $h['needs_replacement'] ? 'Yes' . ((int)$h['replace_qty'] > 0 ? ' ×' . (int)$h['replace_qty'] : '') : '—' ?></td>
                <td><?= e($h['checked_by'] ?? 'Unknown') ?></td>
                <td class="muted small"><?= e(date('j M Y, g:ia', strtotime($h['checked_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<script>
// Pre-tick "needs replacement" when a damage condition is picked (teacher can untick).
(function () {
    var sel = document.querySelector('select[name="condition_code"]');
    var chk = document.getElementById('needs');
    if (!sel || !chk) return;
    sel.addEventListener('change', function () {
        var opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.suggests === '1') chk.checked = true;
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
