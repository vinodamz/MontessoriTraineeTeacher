<?php
/**
 * Transport desk: overview, roster, messages, reports, settings, SOS,
 * handover PINs and a parent's "not riding" notice.
 *
 * Parent-facing data stays in transport_parent_status(). This file only adds
 * fields that are already about that one child (PIN, speed, seat, their notice).
 */
declare(strict_types=1);

require_once __DIR__ . '/transport.php';

function transport_speed_kmh(?float $mps): ?int
{
    if ($mps === null || $mps < 0) return null;
    return (int)round($mps * 3.6);
}

/** True when the cab left no later than $graceMin after the scheduled clock. */
function transport_started_on_time(string $startedAt, string $scheduledHm, int $graceMin = 15): bool
{
    $start = strtotime($startedAt);
    if ($start === false || !preg_match('/^(\d{2}):(\d{2})/', $scheduledHm, $m)) return true;
    $due = strtotime(date('Y-m-d', $start) . ' ' . $m[1] . ':' . $m[2] . ':00');
    return $due !== false && $start <= $due + $graceMin * 60;
}

/** A scheduled run whose clock time passed $graceMin ago. */
function transport_is_late_start(string $date, ?string $hm, string $status, int $now, int $graceMin = 10): bool
{
    if ($status !== 'scheduled' || $hm === null || $hm === '') return false;
    if (!preg_match('/^(\d{2}):(\d{2})/', $hm, $m)) return false;
    $due = strtotime($date . ' ' . $m[1] . ':' . $m[2] . ':00');
    return $due !== false && $now > $due + $graceMin * 60;
}

/** Monday–Sunday bounds for a YYYY-MM-DD. */
function transport_week_bounds(string $date): array
{
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) throw new InvalidArgumentException('Bad date.');
    $n = (int)date('N', $ts);
    return [
        date('Y-m-d', strtotime('-' . ($n - 1) . ' days', $ts)),
        date('Y-m-d', strtotime('+' . (7 - $n) . ' days', $ts)),
    ];
}

/** Directions a "not riding" notice covers. */
function transport_absence_directions(string $scope): array
{
    return match ($scope) {
        'morning'   => ['pickup'],
        'afternoon' => ['drop'],
        'both'      => ['pickup', 'drop'],
        default     => throw new InvalidArgumentException('Pick morning, afternoon, or both.'),
    };
}

function transport_absence_label(string $scope): string
{
    return match ($scope) {
        'morning'   => 'Not riding this morning',
        'afternoon' => 'Not riding this afternoon',
        'both'      => 'Not riding today',
        default     => 'Not riding',
    };
}

function transport_parents_notified(): bool
{
    return app_setting('transport_notify_pickup', '1') !== '0';
}

function transport_speed_limit_kmh(): int
{
    return app_setting('transport_speed_alerts', '1') === '0' ? 0 : 50;
}

function transport_contact_phone(): string
{
    return trim((string)app_setting('transport_contact', ''));
}

function transport_save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([':k' => $key, ':v' => $value]);
    app_setting_clear_cache();
}

/** @return array{school:string,contact:string,notify_pickup:bool,speed_alerts:bool,delete_coordinates:bool} */
function transport_desk_settings(): array
{
    return [
        'school'              => app_name(),
        'contact'             => transport_contact_phone(),
        'notify_pickup'       => transport_parents_notified(),
        'speed_alerts'        => transport_speed_limit_kmh() > 0,
        'delete_coordinates'  => app_setting('transport_delete_coordinates', '1') !== '0',
    ];
}

/** @param array{contact?:string,notify_pickup?:bool,speed_alerts?:bool,delete_coordinates?:bool} $in */
function transport_desk_settings_save(array $in): array
{
    if (array_key_exists('contact', $in)) {
        $phone = trim((string)$in['contact']);
        if (strlen($phone) > 40) throw new InvalidArgumentException('That phone number is too long.');
        transport_save_setting('transport_contact', $phone);
    }
    if (array_key_exists('notify_pickup', $in)) {
        transport_save_setting('transport_notify_pickup', !empty($in['notify_pickup']) ? '1' : '0');
    }
    if (array_key_exists('speed_alerts', $in)) {
        transport_save_setting('transport_speed_alerts', !empty($in['speed_alerts']) ? '1' : '0');
    }
    if (array_key_exists('delete_coordinates', $in)) {
        transport_save_setting('transport_delete_coordinates', !empty($in['delete_coordinates']) ? '1' : '0');
    }
    return transport_desk_settings();
}

function transport_latest_speed_kmh(int $tripId): ?int
{
    $st = db()->prepare('SELECT speed_mps FROM transport_locations
                          WHERE trip_id = :t AND speed_mps IS NOT NULL
                          ORDER BY recorded_at DESC, id DESC LIMIT 1');
    $st->execute([':t' => $tripId]);
    $mps = $st->fetchColumn();
    return $mps === false ? null : transport_speed_kmh((float)$mps);
}

function transport_purge_old_coordinates(): void
{
    if (app_setting('transport_delete_coordinates', '1') === '0') return;
    db()->exec('DELETE FROM transport_locations WHERE recorded_at < NOW() - INTERVAL 30 DAY');
}

/**
 * Called after a GPS batch is stored. Drops coordinates older than 30 days
 * when that setting is on, and tells the desk if the cab is over 50 km/h.
 * $last is one cleaned point: lat, lng, spd.
 */
function transport_after_locations(int $tripId, array $last): void
{
    try {
        transport_purge_old_coordinates();
    } catch (Throwable $e) { /* location table is older than this setting */ }
    $limit = transport_speed_limit_kmh();
    if ($limit <= 0 || !isset($last['spd']) || $last['spd'] === null) return;
    $kmh = transport_speed_kmh((float)$last['spd']);
    if ($kmh === null || $kmh <= $limit) return;
    try {
        $dupe = db()->prepare("SELECT id FROM transport_messages
                                WHERE trip_id = :t AND kind = 'alert' AND created_at > NOW() - INTERVAL 10 MINUTE LIMIT 1");
        $dupe->execute([':t' => $tripId]);
        if ($dupe->fetchColumn()) return;
        $trip = transport_trip_get($tripId);
        $who = trim((string)($trip['cab_name'] ?? '')) ?: 'The cab';
        $where = trim((string)($trip['route_name'] ?? ''));
        $body = $who . ' is at ' . $kmh . ' km/h' . ($where !== '' ? ' on ' . $where : '') . '.';
        transport_message_insert(null, 'alert', $body, $tripId);
    } catch (Throwable $e) { /* messages table not migrated yet */ }
}

function transport_message_insert(?int $userId, string $kind, string $body, ?int $tripId): array
{
    if (!in_array($kind, ['message', 'broadcast', 'sos', 'alert'], true)) {
        throw new InvalidArgumentException('Unknown message.');
    }
    $body = trim($body);
    if ($body === '') throw new InvalidArgumentException('Write a message first.');
    if (strlen($body) > 500) $body = substr($body, 0, 500);
    db()->prepare('INSERT INTO transport_messages (user_id, trip_id, kind, body) VALUES (:u, :t, :k, :b)')
        ->execute([':u' => $userId, ':t' => $tripId, ':k' => $kind, ':b' => $body]);
    $id = (int)db()->lastInsertId();
    return transport_message_get($id);
}

function transport_message_get(int $id): array
{
    $st = db()->prepare('SELECT m.*, u.name AS user_name FROM transport_messages m
                          LEFT JOIN users u ON u.id = m.user_id WHERE m.id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) throw new InvalidArgumentException('Message not found.');
    return transport_message_payload($row);
}

function transport_message_payload(array $row): array
{
    $kind = (string)$row['kind'];
    return [
        'id'    => (int)$row['id'],
        'name'  => $row['user_name'] ? (string)$row['user_name'] : 'School cab',
        'kind'  => $kind,
        'label' => match ($kind) {
            'broadcast' => 'Broadcast',
            'sos'       => 'SOS',
            'alert'     => 'Alert',
            default     => 'Message',
        },
        'body'  => (string)$row['body'],
        'at'    => date('g:i a', strtotime((string)$row['created_at'])),
    ];
}

function transport_messages(int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $st = db()->query('SELECT m.*, u.name AS user_name FROM transport_messages m
                        LEFT JOIN users u ON u.id = m.user_id
                        ORDER BY m.id DESC LIMIT ' . $limit);
    $rows = array_reverse($st->fetchAll());
    return array_map('transport_message_payload', $rows);
}

function transport_fill_handover_pins(int $tripId): void
{
    $trip = transport_trip_get($tripId);
    if (!$trip) return;
    $stops = db()->prepare('SELECT id, student_id, handover_pin FROM transport_trip_stops WHERE trip_id = :t');
    $stops->execute([':t' => $tripId]);
    $find = db()->prepare('SELECT ts.handover_pin FROM transport_trip_stops ts
                            JOIN transport_trips t ON t.id = ts.trip_id
                            WHERE ts.student_id = :s AND t.trip_date = :d
                              AND ts.handover_pin IS NOT NULL AND ts.handover_pin <> ""
                            LIMIT 1');
    $set = db()->prepare('UPDATE transport_trip_stops SET handover_pin = :p WHERE id = :id');
    foreach ($stops->fetchAll() as $s) {
        if (preg_match('/^\d{4}$/', (string)($s['handover_pin'] ?? ''))) continue;
        $find->execute([':s' => (int)$s['student_id'], ':d' => $trip['trip_date']]);
        $pin = (string)$find->fetchColumn();
        if (!preg_match('/^\d{4}$/', $pin)) $pin = (string)random_int(1000, 9999);
        $set->execute([':p' => $pin, ':id' => (int)$s['id']]);
    }
}

function transport_apply_saved_absences(int $tripId): void
{
    $trip = transport_trip_get($tripId);
    if (!$trip) return;
    $scopes = $trip['direction'] === 'drop' ? ['afternoon', 'both'] : ['morning', 'both'];
    $marks = implode(',', array_fill(0, count($scopes), '?'));
    $st = db()->prepare("SELECT ts.id FROM transport_trip_stops ts
                          JOIN transport_absences a ON a.student_id = ts.student_id AND a.absence_date = ?
                          WHERE ts.trip_id = ? AND ts.status = 'pending' AND a.scope IN ($marks)");
    $st->execute(array_merge([$trip['trip_date'], $tripId], $scopes));
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $upd = db()->prepare("UPDATE transport_trip_stops SET status = 'absent', done_at = COALESCE(done_at, NOW())
                           WHERE id = :id AND status = 'pending'");
    foreach ($ids as $id) $upd->execute([':id' => (int)$id]);
}

/** After a scheduled trip's stops are copied from the route. */
function transport_after_snapshot(int $tripId): void
{
    transport_apply_saved_absences($tripId);
    transport_fill_handover_pins($tripId);
}

function transport_mark_absent_today(int $studentId, string $date, string $scope): void
{
    $dirs = transport_absence_directions($scope);
    $in = implode(',', array_fill(0, count($dirs), '?'));
    $st = db()->prepare("SELECT ts.id, t.id AS trip_id, t.status FROM transport_trip_stops ts
                          JOIN transport_trips t ON t.id = ts.trip_id
                          WHERE ts.student_id = ? AND t.trip_date = ? AND ts.status = 'pending'
                            AND t.status IN ('scheduled','running') AND t.direction IN ($in)");
    $st->execute(array_merge([$studentId, $date], $dirs));
    $upd = db()->prepare("UPDATE transport_trip_stops SET status = 'absent', done_at = COALESCE(done_at, NOW())
                           WHERE id = :id AND status = 'pending'");
    $open = [];
    foreach ($st->fetchAll() as $row) {
        $upd->execute([':id' => (int)$row['id']]);
        if ($row['status'] === 'running') $open[(int)$row['trip_id']] = true;
    }
    $left = db()->prepare("SELECT COUNT(*) FROM transport_trip_stops WHERE trip_id = :t AND status = 'pending'");
    foreach (array_keys($open) as $tripId) {
        $left->execute([':t' => $tripId]);
        if ((int)$left->fetchColumn() === 0) transport_trip_finish($tripId);
    }
}

function transport_parent_set_absence(int $studentId, string $scope, string $reason, ?string $date = null): void
{
    $date ??= date('Y-m-d');
    transport_absence_directions($scope);
    $reason = trim($reason);
    if (strlen($reason) > 200) $reason = substr($reason, 0, 200);
    db()->prepare('INSERT INTO transport_absences (student_id, absence_date, scope, reason)
                   VALUES (:s, :d, :c, :r)
                   ON DUPLICATE KEY UPDATE scope = VALUES(scope), reason = VALUES(reason)')
        ->execute([':s' => $studentId, ':d' => $date, ':c' => $scope, ':r' => $reason]);
    transport_mark_absent_today($studentId, $date, $scope);

    $name = db()->prepare('SELECT first_name FROM students WHERE id = :id');
    $name->execute([':id' => $studentId]);
    $first = trim((string)$name->fetchColumn()) ?: 'A child';
    $body = $first . ': ' . transport_absence_label($scope) . ($reason !== '' ? '. ' . $reason : '.');
    try {
        transport_message_insert(null, 'alert', $body, null);
    } catch (Throwable $e) { /* the notice itself is already saved */ }
}

/** Extra parent-page fields. Never another child's name or a stop note. */
function transport_parent_extras(int $studentId, string $date, ?array $row): array
{
    $extra = [
        'pin'       => null,
        'speed_kmh' => null,
        'seat'      => 'Waiting',
        'absence'   => null,
        'contact'   => transport_contact_phone(),
    ];
    try {
        $ab = db()->prepare('SELECT scope, reason FROM transport_absences WHERE student_id = :s AND absence_date = :d');
        $ab->execute([':s' => $studentId, ':d' => $date]);
        if ($a = $ab->fetch()) {
            $extra['absence'] = [
                'scope'  => (string)$a['scope'],
                'reason' => (string)$a['reason'],
                'label'  => transport_absence_label((string)$a['scope']),
            ];
        }
    } catch (Throwable $e) { /* table not migrated yet */ }

    if (!$row) return $extra;
    if (($row['stop_status'] ?? '') === 'absent' || $extra['absence']) $extra['seat'] = 'Not riding';
    elseif (($row['stop_status'] ?? '') === 'done' && ($row['direction'] ?? '') === 'pickup') $extra['seat'] = 'Confirmed';

    try {
        transport_fill_handover_pins((int)$row['id']);
        $p = db()->prepare('SELECT handover_pin FROM transport_trip_stops WHERE id = :id');
        $p->execute([':id' => (int)$row['trip_stop_id']]);
        $pin = (string)$p->fetchColumn();
        if (preg_match('/^\d{4}$/', $pin)) $extra['pin'] = $pin;
        if (transport_trip_live_position($row) !== null) {
            $extra['speed_kmh'] = transport_latest_speed_kmh((int)$row['id']);
        }
    } catch (Throwable $e) { /* pin column not migrated yet */ }
    return $extra;
}

function transport_child_desk_status(?string $tripStatus, ?string $stopStatus, bool $notRiding): string
{
    if ($notRiding || $stopStatus === 'absent') return 'absent';
    if ($stopStatus === 'done') return 'arrived';
    if ($tripStatus === 'running') return 'en_route';
    return 'waiting';
}

function transport_child_status_label(string $status): string
{
    return match ($status) {
        'arrived'  => 'At school',
        'en_route' => 'On the way',
        'absent'   => 'Not riding',
        default    => 'Waiting',
    };
}

/** Students on active routes, with this morning's pickup status. Staff only. */
function transport_roster(string $date, string $query = ''): array
{
    try {
        $open = db()->prepare("SELECT DISTINCT t.id FROM transport_trips t
                                JOIN transport_trip_stops ts ON ts.trip_id = t.id
                                WHERE t.trip_date = :d AND (ts.handover_pin IS NULL OR ts.handover_pin = '')");
        $open->execute([':d' => $date]);
        foreach ($open->fetchAll(PDO::FETCH_COLUMN) as $id) transport_fill_handover_pins((int)$id);
    } catch (Throwable $e) { /* pin column not migrated yet */ }

    $st = db()->prepare("SELECT s.id AS student_id, s.first_name, s.last_name, s.grade,
                                r.name AS route_name, t.id AS trip_id, t.status AS trip_status,
                                ts.status AS stop_status, ts.handover_pin
                           FROM transport_stops rs
                           JOIN transport_routes r ON r.id = rs.route_id AND r.is_active = 1
                           JOIN students s ON s.id = rs.student_id
                           LEFT JOIN transport_trips t
                                  ON t.route_id = r.id AND t.trip_date = :d AND t.direction = 'pickup'
                                     AND t.status <> 'cancelled'
                           LEFT JOIN transport_trip_stops ts ON ts.trip_id = t.id AND ts.student_id = s.id
                          ORDER BY r.name, rs.stop_order, s.first_name");
    $st->execute([':d' => $date]);
    $best = [];
    foreach ($st->fetchAll() as $row) {
        $id = (int)$row['student_id'];
        $prev = $best[$id] ?? null;
        $rank = transport_trip_rank((string)($row['trip_status'] ?? ''));
        $prevRank = $prev ? transport_trip_rank((string)$prev['trip_status']) : -1;
        if ($prev === null || $rank > $prevRank || ($rank === $prevRank && (int)$row['trip_id'] > (int)$prev['trip_id'])) {
            $best[$id] = $row;
        }
    }

    $absences = [];
    try {
        $ab = db()->prepare("SELECT student_id, scope FROM transport_absences
                              WHERE absence_date = :d AND scope IN ('morning','both')");
        $ab->execute([':d' => $date]);
        foreach ($ab->fetchAll() as $a) $absences[(int)$a['student_id']] = true;
    } catch (Throwable $e) { /* table not migrated yet */ }

    $q = mb_strtolower(trim($query));
    $children = [];
    $counts = ['waiting' => 0, 'en_route' => 0, 'arrived' => 0, 'absent' => 0];
    foreach ($best as $id => $row) {
        $name = trim($row['first_name'] . ' ' . $row['last_name']);
        if ($q !== '' && !str_contains(mb_strtolower($name), $q) && !str_contains(mb_strtolower((string)$row['route_name']), $q)) {
            continue;
        }
        $status = transport_child_desk_status(
            $row['trip_status'] !== null ? (string)$row['trip_status'] : null,
            $row['stop_status'] !== null ? (string)$row['stop_status'] : null,
            !empty($absences[$id])
        );
        $counts[$status] = ($counts[$status] ?? 0) + 1;
        $guardian = '';
        $parents = transport_parents_with_phone($id);
        if ($parents) $guardian = (string)$parents[0]['name'];
        $link = transport_parent_link_ensure($id, null);
        $pin = (string)($row['handover_pin'] ?? '');
        $children[] = [
            'id'           => $id,
            'name'         => $name,
            'grade'        => (string)$row['grade'],
            'route_name'   => (string)$row['route_name'],
            'status'       => $status,
            'status_label' => transport_child_status_label($status),
            'guardian'     => $guardian,
            'pin'          => preg_match('/^\d{4}$/', $pin) ? $pin : null,
            'track_url'    => transport_track_url((string)$link['token']),
        ];
    }
    return ['children' => $children, 'counts' => $counts];
}

function transport_overview(string $date): array
{
    $runs = transport_today_runs($date);
    $now = time();
    $cabs = (int)db()->query('SELECT COUNT(*) FROM transport_cabs WHERE is_active = 1')->fetchColumn();
    $activeCabs = [];
    $aboard = [];
    $considered = 0;
    $onTime = 0;
    $alerts = [];
    $routes = [];
    $live = [];

    foreach ($runs as $row) {
        $t = $row['trip'];
        $r = $row['route'];
        $status = $t ? (string)$t['status'] : 'scheduled';
        $time = $row['direction'] === 'drop' ? ($r['drop_time'] ?? null) : ($r['pickup_time'] ?? null);
        $hm = $time ? substr((string)$time, 0, 5) : null;
        if ($t && in_array($status, ['running', 'completed'], true) && $hm && !empty($t['started_at'])) {
            $considered++;
            if (transport_started_on_time((string)$t['started_at'], $hm)) $onTime++;
        } elseif (transport_is_late_start($date, $hm, $status, $now)) {
            $considered++;
            $alerts[] = [
                'title'  => $r['name'] . ' has not started',
                'detail' => transport_direction_label($row['direction']) . ($hm ? ' was ' . $hm . '.' : '.'),
                'tone'   => 'warn',
            ];
        }
        if ($status === 'running' && $t) {
            $cabId = (int)($t['cab_id'] ?? 0);
            $activeCabs[$cabId > 0 ? 'c' . $cabId : 't' . (int)$t['id']] = true;
            if (empty($t['last_location_at']) || transport_trip_live_position($t) === null) {
                $alerts[] = [
                    'title'  => $r['name'] . ' has no live location',
                    'detail' => 'The driver\'s phone has not sent a position in the last 3 minutes.',
                    'tone'   => 'warn',
                ];
            }
            $stops = transport_trip_stops((int)$t['id']);
            $etas = transport_trip_etas($t, $stops);
            $next = null;
            $coords = [];
            foreach (transport_route_stops((int)$t['route_id']) as $rs) {
                if ($rs['lat'] !== null) $coords[(int)$rs['student_id']] = ['lat' => (float)$rs['lat'], 'lng' => (float)$rs['lng']];
            }
            $missing = [];
            foreach ($stops as $s) {
                $sid = (int)$s['student_id'];
                if (!isset($coords[$sid])) $missing[$sid] = true;
            }
            if ($missing) {
                $ids = array_keys($missing);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $fill = db()->prepare("SELECT student_id, lat, lng FROM transport_stops
                                        WHERE lat IS NOT NULL AND student_id IN ($in)
                                        ORDER BY id DESC");
                $fill->execute($ids);
                foreach ($fill as $saved) {
                    $sid = (int)$saved['student_id'];
                    if (!isset($coords[$sid])) $coords[$sid] = ['lat' => (float)$saved['lat'], 'lng' => (float)$saved['lng']];
                }
            }
            $mapStops = [];
            foreach ($stops as $s) {
                $sid = (int)$s['student_id'];
                if ($row['direction'] === 'pickup' && $s['status'] === 'done') $aboard[$sid] = true;
                if ($row['direction'] === 'drop' && $s['status'] === 'pending') $aboard[$sid] = true;
                if ($next === null && $s['status'] === 'pending') {
                    $e = $etas[(int)$s['id']] ?? null;
                    $next = ['name' => trim($s['first_name'] . ' ' . $s['last_name']), 'eta' => $e['minutes'] ?? null];
                }
                $c = $coords[$sid] ?? null;
                $mapStops[] = [
                    'name'   => trim($s['first_name']),
                    'lat'    => $c !== null ? $c['lat'] : null,
                    'lng'    => $c !== null ? $c['lng'] : null,
                    'status' => (string)$s['status'],
                    'order'  => (int)$s['stop_order'],
                ];
            }
            $pos = transport_trip_live_position($t);
            $live[] = [
                'trip_id'         => (int)$t['id'],
                'route_name'      => (string)$r['name'],
                'direction_label' => transport_direction_label($row['direction']),
                'cab_name'        => (string)($t['cab_name'] ?? $r['cab_name'] ?? ''),
                'vehicle_no'      => (string)($t['vehicle_no'] ?? ''),
                'driver_name'     => (string)($t['driver_name'] ?? ''),
                'lat'             => $pos['lat'] ?? null,
                'lng'             => $pos['lng'] ?? null,
                'speed_kmh'       => $pos ? transport_latest_speed_kmh((int)$t['id']) : null,
                'stops'           => $mapStops,
            ];
        }
        $finished = $t ? (int)$t['finished'] : 0;
        $total = $t ? (int)$t['total'] : (int)$r['stop_count'];
        $routes[] = [
            'trip_id'         => $t ? (int)$t['id'] : null,
            'route_id'        => (int)$r['id'],
            'route_name'      => (string)$r['name'],
            'direction'       => $row['direction'],
            'direction_label' => transport_direction_label($row['direction']),
            'run_no'          => $t ? (int)($t['run_no'] ?? 1) : 1,
            'status'          => $status,
            'status_label'    => transport_trip_status_label($status),
            'cab_name'        => (string)($r['cab_name'] ?? ''),
            'vehicle_no'      => (string)($t['vehicle_no'] ?? ''),
            'driver_name'     => (string)($t['driver_name'] ?? ''),
            'finished'        => $finished,
            'total'           => $total,
            'next_stop'       => $next['name'] ?? null,
            'next_eta'        => $next['eta'] ?? null,
        ];
    }

    try {
        $ab = db()->prepare('SELECT COUNT(*) FROM transport_absences WHERE absence_date = :d');
        $ab->execute([':d' => $date]);
        $n = (int)$ab->fetchColumn();
        if ($n > 0) {
            $alerts[] = [
                'title'  => $n === 1 ? '1 child is not riding' : $n . ' children are not riding',
                'detail' => 'A family sent a notice for today.',
                'tone'   => 'info',
            ];
        }
        $sos = db()->query("SELECT COUNT(*) FROM transport_messages WHERE kind = 'sos' AND created_at > NOW() - INTERVAL 12 HOUR")->fetchColumn();
        if ((int)$sos > 0) {
            $alerts[] = [
                'title'  => 'SOS from a driver',
                'detail' => 'Open Messages for the latest location.',
                'tone'   => 'danger',
            ];
        }
    } catch (Throwable $e) { /* desk tables not migrated yet */ }

    return [
        'date'    => $date,
        'school'  => app_name(),
        'contact' => transport_contact_phone(),
        'kpis'    => [
            'vehicles_active' => count($activeCabs),
            'vehicles_total'  => max($cabs, count($activeCabs)),
            'aboard'          => count($aboard),
            'on_time_pct'     => $considered > 0 ? (int)round(100 * $onTime / $considered) : 100,
            'alerts'          => count($alerts),
        ],
        'alerts'  => $alerts,
        'routes'  => $routes,
        'live'    => $live,
    ];
}

function transport_reports(string $date): array
{
    [$start, $end] = transport_week_bounds($date);
    $st = db()->prepare("SELECT r.name, t.status,
                                (SELECT COUNT(*) FROM transport_trip_stops x WHERE x.trip_id = t.id AND x.status = 'done') AS done_n,
                                (SELECT COUNT(*) FROM transport_trip_stops x WHERE x.trip_id = t.id AND x.status = 'absent') AS absent_n
                           FROM transport_trips t
                           JOIN transport_routes r ON r.id = t.route_id
                          WHERE t.trip_date BETWEEN :a AND :b AND t.status <> 'cancelled'");
    $st->execute([':a' => $start, ':b' => $end]);
    $trips = 0;
    $completed = 0;
    $done = 0;
    $absent = 0;
    $by = [];
    foreach ($st->fetchAll() as $row) {
        $trips++;
        if ($row['status'] === 'completed') $completed++;
        $done += (int)$row['done_n'];
        $absent += (int)$row['absent_n'];
        $name = (string)$row['name'];
        if (!isset($by[$name])) $by[$name] = ['name' => $name, 'trips' => 0, 'done' => 0, 'absent' => 0];
        $by[$name]['trips']++;
        $by[$name]['done'] += (int)$row['done_n'];
        $by[$name]['absent'] += (int)$row['absent_n'];
    }
    $sos = 0;
    try {
        $q = db()->prepare("SELECT COUNT(*) FROM transport_messages WHERE kind = 'sos' AND created_at BETWEEN :a AND :b");
        $q->execute([':a' => $start . ' 00:00:00', ':b' => $end . ' 23:59:59']);
        $sos = (int)$q->fetchColumn();
    } catch (Throwable $e) { /* table not migrated yet */ }
    return [
        'from'         => $start,
        'to'           => $end,
        'trips'        => $trips,
        'completed'    => $completed,
        'stops_done'   => $done,
        'stops_absent' => $absent,
        'sos'          => $sos,
        'routes'       => array_values($by),
    ];
}

function transport_send_sos(int $userId, ?int $tripId, string $note): array
{
    $note = trim($note);
    $where = '';
    if ($tripId) {
        $trip = transport_trip_get($tripId);
        if (!$trip) throw new InvalidArgumentException('Trip not found.');
        $pos = transport_trip_live_position($trip);
        $place = trim((string)$trip['route_name']);
        $where = $place !== '' ? ' on ' . $place : '';
        if ($pos) {
            $where .= '. Map: https://www.openstreetmap.org/?mlat=' . round($pos['lat'], 5)
                    . '&mlon=' . round($pos['lng'], 5) . '#map=16/' . round($pos['lat'], 5) . '/' . round($pos['lng'], 5);
        }
    }
    $body = 'SOS — needs help now' . $where . ($note !== '' ? '. ' . $note : '.');
    return transport_message_insert($userId, 'sos', $body, $tripId);
}
