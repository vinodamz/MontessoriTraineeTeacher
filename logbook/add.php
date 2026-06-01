<?php
/**
 * logbook/add.php — create a log entry. The form renders type-specific
 * fields data-driven from logbook_types(). Any module user can add.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logbook.php';

$user = require_module('logbook');
$pdo  = db();

function logbook_uploads_dir(): string
{
    $dir = realpath(__DIR__ . '/..') . '/uploads/logbook';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

$type = $_GET['type'] ?? ($_POST['log_type'] ?? '');
if (!array_key_exists($type, logbook_types())) $type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = $_POST['log_type'] ?? '';
    $def  = logbook_type($type);
    if (!$def) {
        flash_set('error', 'Unknown log type.');
        redirect('/logbook/index.php');
    }

    $occurred = trim($_POST['occurred_at'] ?? '');
    $occurred = $occurred !== '' ? str_replace('T', ' ', $occurred) . ':00' : date('Y-m-d H:i:s');

    $studentId = (int)($_POST['student_id'] ?? 0) ?: null;
    if ($def['student'] === 'required' && !$studentId) {
        flash_set('error', 'This log type needs a student selected.');
        redirect('/logbook/add.php?type=' . $type);
    }

    // Collect type-specific fields into meta.
    $meta = [];
    foreach ($def['fields'] as $key => $f) {
        $val = trim((string)($_POST['f_' . $key] ?? ''));
        if ($val !== '') $meta[$key] = $val;
    }

    $title          = trim((string)($_POST['title'] ?? '')) ?: null;
    $details        = trim((string)($_POST['details'] ?? '')) ?: null;
    $parentNotified = !empty($_POST['parent_notified']) ? 1 : 0;
    $notifiedAt     = $parentNotified ? ($occurred) : null;

    // Optional photo.
    $photoPath = null;
    if ($def['photo'] && !empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['photo'];
        if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= 8 * 1024 * 1024) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                $stored = 'log_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], logbook_uploads_dir() . '/' . $stored)) {
                    $photoPath = $stored;
                }
            }
        }
    }

    $pdo->prepare("
        INSERT INTO logbook_entries
            (log_type, occurred_at, student_id, title, details, meta_json,
             parent_notified, notified_at, photo_path, logged_by)
        VALUES (:t, :o, :sid, :ti, :d, :m, :pn, :na, :ph, :by)
    ")->execute([
        ':t'  => $type, ':o' => $occurred, ':sid' => $studentId,
        ':ti' => $title, ':d' => $details,
        ':m'  => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ':pn' => $parentNotified, ':na' => $notifiedAt,
        ':ph' => $photoPath, ':by' => $user['id'],
    ]);

    flash_set('ok', logbook_type_label($type) . ' entry logged.');
    redirect('/logbook/index.php?type=' . $type);
}

$students = [];
try {
    $students = $pdo->query("SELECT id, first_name, last_name, grade FROM students WHERE COALESCE(is_active,1)=1 ORDER BY first_name, last_name")->fetchAll();
} catch (Throwable $e) {}

$pageTitle = 'New log entry';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div><h1>New log entry</h1></div>
    <div class="actionbar"><a class="btn" href="/logbook/index.php">← Logbook</a></div>
</div>

<?php if ($type === ''): ?>
    <div class="card">
        <h3>Choose a log type</h3>
        <div class="log-type-chips">
            <?php foreach (logbook_types() as $code => $t): ?>
                <a class="log-chip" href="/logbook/add.php?type=<?= e($code) ?>">
                    <span class="log-chip-icon"><?= $t['icon'] ?></span>
                    <span><?= e($t['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php else:
    $def = logbook_type($type);
?>
    <form method="post" class="card" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="log_type" value="<?= e($type) ?>">

        <h3><?= $def['icon'] ?> <?= e($def['label']) ?></h3>

        <div class="row">
            <div class="field">
                <label for="occurred_at">When</label>
                <input id="occurred_at" name="occurred_at" type="datetime-local" value="<?= e(date('Y-m-d\TH:i')) ?>">
            </div>
            <?php if ($def['student'] !== 'none'): ?>
                <div class="field">
                    <label for="student_id">Student <?= $def['student'] === 'required' ? '*' : '(optional)' ?></label>
                    <select id="student_id" name="student_id" <?= $def['student'] === 'required' ? 'required' : '' ?>>
                        <option value="">— select —</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int)$s['id'] ?>">
                                <?= e(trim($s['first_name'] . ' ' . ($s['last_name'] ?? ''))) ?><?= $s['grade'] ? ' · ' . e($s['grade']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // Render type-specific fields, two per row.
        $fieldKeys = array_keys($def['fields']);
        foreach (array_chunk($fieldKeys, 2) as $pair): ?>
            <div class="row">
                <?php foreach ($pair as $key):
                    $f = $def['fields'][$key];
                    $id = 'f_' . $key;
                ?>
                    <div class="field">
                        <label for="<?= e($id) ?>"><?= e($f['label']) ?></label>
                        <?php if ($f['type'] === 'textarea'): ?>
                            <textarea id="<?= e($id) ?>" name="<?= e($id) ?>" rows="2"></textarea>
                        <?php elseif ($f['type'] === 'select'): ?>
                            <select id="<?= e($id) ?>" name="<?= e($id) ?>">
                                <?php foreach ($f['options'] as $opt): ?>
                                    <option value="<?= e($opt) ?>"><?= $opt === '' ? '— select —' : e($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input id="<?= e($id) ?>" name="<?= e($id) ?>" type="<?= e($f['type']) ?>"
                                   <?= $f['type'] === 'number' ? 'step="any"' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="field">
            <label for="details">Notes / details</label>
            <textarea id="details" name="details" rows="3" placeholder="Anything else worth recording…"></textarea>
        </div>

        <?php if ($def['notify']): ?>
            <label class="inline-check">
                <input type="checkbox" name="parent_notified" value="1">
                <span>Parent / guardian has been notified</span>
            </label>
        <?php endif; ?>

        <?php if ($def['photo']): ?>
            <div class="field">
                <label for="photo">Photo / attachment (optional)</label>
                <input id="photo" name="photo" type="file" accept="image/*,application/pdf" capture="environment">
            </div>
        <?php endif; ?>

        <div class="actions" style="margin-top:.8rem;">
            <button class="btn btn-primary" type="submit">Save entry</button>
            <a class="btn btn-ghost" href="/logbook/index.php">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
