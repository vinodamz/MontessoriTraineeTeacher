<?php
/**
 * includes/api_materials.php — JSON for the app's daily material check.
 *
 * Same sheet as materials/daily.php: one condition per material per calendar
 * day, a note, and photos or videos on that day's row. Yesterday stays in
 * history because the unique key is (material_id, check_date).
 */
declare(strict_types=1);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/materials.php';

/** Blank or "today" is the school day. A late upload may name a recent day. */
function api_materials_date(string $raw, ?DateTimeInterface $today = null): string
{
    $now = $today !== null
        ? DateTimeImmutable::createFromInterface($today)->setTime(0, 0)
        : new DateTimeImmutable('today');
    $raw = trim($raw);
    if ($raw === '' || $raw === 'today') return $now->format('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        throw new InvalidArgumentException('Use a date like 2026-10-21.');
    }
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$day || ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        throw new InvalidArgumentException('That date is not valid.');
    }
    if ($day > $now) throw new InvalidArgumentException('That date is in the future.');
    $oldest = $now->modify('-14 days');
    if ($day < $oldest) throw new InvalidArgumentException('That check is too old to save from the app.');
    return $raw;
}

/** Idempotency id from the phone. Empty means the caller did not send one. */
function api_materials_client_key(string $key): string
{
    $key = trim($key);
    if ($key === '') return '';
    if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $key)) {
        throw new InvalidArgumentException('Invalid upload id.');
    }
    return $key;
}

function api_materials_conditions_payload(): array
{
    $out = [];
    foreach (mm_conditions() as $code => $meta) {
        $out[] = [
            'code'  => $code,
            'label' => $meta['label'],
            'tone'  => $meta['tone'],
        ];
    }
    return $out;
}

function api_materials_user(): array
{
    $user = api_require_user();
    if (!mm_can_daily_check($user)) {
        api_error('You do not have access to materials.', 403, 'forbidden');
    }
    return $user;
}

function api_materials_file_url(int $id, bool $thumb): string
{
    $url = app_base_url() . '/api/v1/materials/file.php?id=' . $id;
    if ($thumb) $url .= '&thumb=1';
    return $url;
}

/** Today's sheet, or a recent day's sheet when the phone is catching up. */
function api_materials_day(array $user, string $date): array
{
    $rows = db()->prepare("
        SELECT m.id, m.name, m.location, m.sort_order,
               c.id AS check_id, c.condition_code, c.notes, c.checked_at, u.name AS checked_by
        FROM mm_materials m
        LEFT JOIN mm_daily_checks c ON c.material_id = m.id AND c.check_date = :d
        LEFT JOIN users u ON u.id = c.checked_by_user_id
        WHERE m.is_active = 1
        ORDER BY m.location, m.sort_order, m.name
    ");
    $rows->execute([':d' => $date]);
    $materials = $rows->fetchAll();

    $mediaByMaterial = [];
    try {
        $media = db()->prepare("
            SELECT c.material_id, md.id, md.kind, md.original_filename
            FROM mm_daily_media md
            JOIN mm_daily_checks c ON c.id = md.check_id
            WHERE c.check_date = :d
            ORDER BY md.id
        ");
        $media->execute([':d' => $date]);
        foreach ($media as $r) {
            $mediaByMaterial[(int)$r['material_id']][] = $r;
        }
    } catch (Throwable $e) {
        $mediaByMaterial = [];
    }

    $locations = [];
    $items = [];
    foreach ($materials as $m) {
        $loc = (string)$m['location'];
        if (!in_array($loc, $locations, true)) $locations[] = $loc;
        $mid = (int)$m['id'];
        $files = [];
        foreach ($mediaByMaterial[$mid] ?? [] as $file) {
            $id = (int)$file['id'];
            $kind = (string)$file['kind'];
            $entry = [
                'id'   => $id,
                'kind' => $kind,
                'name' => (string)$file['original_filename'],
                'url'  => api_materials_file_url($id, false),
            ];
            if ($kind === 'photo') $entry['thumb_url'] = api_materials_file_url($id, true);
            $files[] = $entry;
        }
        $check = null;
        if ($m['check_id'] !== null) {
            $code = (string)$m['condition_code'];
            $check = [
                'id'              => (int)$m['check_id'],
                'condition_code'  => $code,
                'label'           => mm_condition_label($code),
                'tone'            => mm_condition_tone($code),
                'notes'           => (string)($m['notes'] ?? ''),
                'by'              => (string)($m['checked_by'] ?? ''),
                'at'              => $m['checked_at'] ? date('g:ia', strtotime((string)$m['checked_at'])) : '',
            ];
        }
        $items[] = [
            'id'         => $mid,
            'name'       => (string)$m['name'],
            'location'   => $loc,
            'sort_order' => (int)$m['sort_order'],
            'check'      => $check,
            'media'      => $files,
        ];
    }

    $sum = mm_daily_summary($date);
    $base = app_base_url();
    return [
        'date'         => $date,
        'date_label'   => mm_daily_date_label($date),
        'upload_limit' => mm_effective_upload_limit(),
        'is_admin'     => ($user['role'] ?? '') === 'admin',
        'can_monthly'  => user_has_module($user, 'materials'),
        'summary'      => [
            'checked'   => (int)$sum['checked'],
            'pending'   => (int)$sum['pending'],
            'total'     => (int)$sum['total_active'],
            'good'      => (int)($sum['by_tone']['ok'] ?? 0),
            'attention' => (int)($sum['by_tone']['warn'] ?? 0),
            'damaged'   => (int)($sum['by_tone']['bad'] ?? 0),
            'notes'     => (int)$sum['notes_count'],
        ],
        'conditions' => api_materials_conditions_payload(),
        'locations'  => $locations,
        'materials'  => $items,
        'links'      => [
            'history' => $base . '/materials/daily_history.php',
            'trends'  => $base . '/materials/daily_trends.php',
            'monthly' => $base . '/materials/index.php',
            'duties'  => $base . '/duties/index.php',
            'assign'  => $base . '/duties/admin.php?view=edit&preset=materials_check',
        ],
    ];
}

function api_materials_mark(array $user, int $materialId, string $date, string $condition, string $notes): array
{
    if ($materialId <= 0 || !isset(mm_conditions()[$condition])) {
        throw new InvalidArgumentException('Pick a condition first.');
    }
    $notes = trim($notes);
    if (mb_strlen($notes) > 500) {
        throw new InvalidArgumentException('Keep the note under 500 characters.');
    }
    $exists = db()->prepare('SELECT id FROM mm_materials WHERE id = :id AND is_active = 1');
    $exists->execute([':id' => $materialId]);
    if (!(int)$exists->fetchColumn()) {
        throw new InvalidArgumentException('That material is not on today\'s list.');
    }
    $checkId = mm_daily_save_check($materialId, $date, $condition, $notes, (int)$user['id']);
    mm_daily_sync_duties((int)$user['id'], $date);
    $sum = mm_daily_summary($date);
    return [
        'check_id'       => $checkId,
        'material_id'    => $materialId,
        'condition_code' => $condition,
        'label'          => mm_condition_label($condition),
        'tone'           => mm_condition_tone($condition),
        'notes'          => $notes,
        'by'             => (string)$user['name'],
        'at'             => date('g:ia'),
        'pending'        => (int)$sum['pending'],
        'checked'        => (int)$sum['checked'],
        'total'          => (int)$sum['total_active'],
    ];
}

/** Mark every still-blank material Good. Same rule as the website button. */
function api_materials_bulk_good(array $user, string $date, string $scope): array
{
    $scope = trim($scope);
    $where = 'm.is_active = 1 AND NOT EXISTS (
        SELECT 1 FROM mm_daily_checks c WHERE c.material_id = m.id AND c.check_date = :d
    )';
    $params = [':d' => $date];
    if ($scope !== '') {
        $where .= ' AND m.location = :loc';
        $params[':loc'] = $scope;
    }
    $st = db()->prepare("SELECT m.id FROM mm_materials m WHERE $where");
    $st->execute($params);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $mid) {
        mm_daily_save_check((int)$mid, $date, 'good', null, (int)$user['id']);
    }
    mm_daily_sync_duties((int)$user['id'], $date);
    $sum = mm_daily_summary($date);
    return [
        'saved'   => count($ids),
        'scope'   => $scope,
        'pending' => (int)$sum['pending'],
        'checked' => (int)$sum['checked'],
        'total'   => (int)$sum['total_active'],
    ];
}

function api_materials_media_key_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT client_key FROM mm_daily_media LIMIT 0');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function api_materials_find_media_key(string $key): ?array
{
    if ($key === '') return null;
    $st = db()->prepare('SELECT id, kind FROM mm_daily_media WHERE client_key = :k');
    $st->execute([':k' => $key]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Store one photo, video or voice memo. A repeated client_key returns the
 * row already stored, so a retry after the app was killed does not duplicate.
 *
 * @return array{media_id:int,kind:string,duplicate:bool}
 */
function api_materials_store_media(array $file, int $checkId, int $userId, string $clientKey): array
{
    if (!api_materials_media_key_ready()) {
        throw new RuntimeException('Run migration 076 before uploading from the app.');
    }
    $existing = api_materials_find_media_key($clientKey);
    if ($existing) {
        return [
            'media_id'  => (int)$existing['id'],
            'kind'      => (string)$existing['kind'],
            'duplicate' => true,
        ];
    }
    $acc = mm_media_accept($file, 'mmd_', $checkId);
    if ($acc === null) throw new InvalidArgumentException('No file received.');
    try {
        db()->prepare("
            INSERT INTO mm_daily_media
                (check_id, kind, original_filename, stored_filename, mime_type, size_bytes, uploaded_by_user_id, client_key)
            VALUES (:c, :k, :o, :s, :m, :sz, :u, :key)
        ")->execute([
            ':c'   => $checkId,
            ':k'   => $acc['kind'],
            ':o'   => $acc['orig'],
            ':s'   => $acc['stored'],
            ':m'   => $acc['mime'],
            ':sz'  => $acc['size'],
            ':u'   => $userId,
            ':key' => $clientKey !== '' ? $clientKey : null,
        ]);
    } catch (Throwable $e) {
        @unlink(mm_media_dir() . '/' . $acc['stored']);
        if ($clientKey !== '') {
            $again = api_materials_find_media_key($clientKey);
            if ($again) {
                return [
                    'media_id'  => (int)$again['id'],
                    'kind'      => (string)$again['kind'],
                    'duplicate' => true,
                ];
            }
        }
        throw $e;
    }
    return [
        'media_id'  => (int)db()->lastInsertId(),
        'kind'      => $acc['kind'],
        'duplicate' => false,
    ];
}

function api_materials_check_id(int $materialId, string $date): int
{
    $st = db()->prepare('SELECT id FROM mm_daily_checks WHERE material_id = :m AND check_date = :d');
    $st->execute([':m' => $materialId, ':d' => $date]);
    return (int)$st->fetchColumn();
}
