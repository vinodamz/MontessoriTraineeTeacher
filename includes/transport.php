<?php
/**
 * includes/transport.php — daily cab: cabs, routes, ordered stops, trips,
 * approximate ETAs, one-tap WhatsApp alerts, and private parent links.
 *
 * Confidentiality rules the public tracking page relies on:
 *   - parents see their own child's first name, a status, an approximate
 *     time and a count of stops ahead — never other children, stop notes,
 *     coordinates, or the cab's position;
 *   - coordinates and the Maps key are only ever used server-side.
 *
 * Alerts are prepared here and sent by the operator: the app opens the
 * operator's own WhatsApp with the parent's number and text pre-filled.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const TRANSPORT_DIRECTIONS = ['pickup', 'drop'];
const TRANSPORT_ROUTE_DIRECTIONS = ['pickup', 'drop', 'both'];
const TRANSPORT_ETA_ALERT_MINUTES = 5;

function transport_tables_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM transport_trips LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function transport_require(): array
{
    return require_module('transport');
}

function transport_direction_label(string $dir): string
{
    return ['pickup' => 'Morning pickup', 'drop' => 'Afternoon drop', 'both' => 'Pickup + drop'][$dir] ?? $dir;
}

function transport_trip_status_label(string $status): string
{
    return [
        'scheduled' => 'Not started',
        'running'   => 'On the road',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$status] ?? $status;
}

/** Directions a route runs: 'both' → pickup then drop. */
function transport_route_directions(array $route): array
{
    $d = (string)($route['direction'] ?? 'both');
    return $d === 'both' ? ['pickup', 'drop'] : [$d];
}

// ---------- Pure helpers (unit-tested in tests/transport_test.php) ----------

/** Digits for wa.me, India default for 10-digit numbers. '' if unusable. */
function transport_phone_digits(?string $phone): string
{
    $digits = (string)preg_replace('/\D+/', '', (string)$phone);
    if (strlen($digits) < 10) return '';
    if (strlen($digits) === 10) return '91' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '0') return '91' . substr($digits, 1);
    return $digits;
}

/** https://wa.me/<digits>?text=… or '' when the phone is unusable. */
function transport_wa_link(?string $phone, string $text): string
{
    $digits = transport_phone_digits($phone);
    if ($digits === '') return '';
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
}

/**
 * Message for a parent alert. $kind is 'eta' (about 5 minutes away) or
 * 'reached'. $link is the private tracking link ('' to omit).
 */
function transport_alert_text(string $kind, string $direction, string $parentName, string $childFirst, string $link = ''): string
{
    $hi = trim($parentName) !== '' ? 'Hi ' . trim($parentName) . ', ' : 'Hi, ';
    $child = trim($childFirst) !== '' ? trim($childFirst) : 'your child';
    if ($kind === 'reached') {
        $msg = $direction === 'drop'
            ? $hi . 'the school cab has reached your stop with ' . $child . '.'
            : $hi . 'the school cab has reached ' . $child . "'s pickup point.";
    } else {
        $msg = $direction === 'drop'
            ? $hi . 'the school cab will drop ' . $child . ' in about ' . TRANSPORT_ETA_ALERT_MINUTES . ' minutes.'
            : $hi . 'the school cab will reach ' . $child . "'s pickup point in about " . TRANSPORT_ETA_ALERT_MINUTES . ' minutes.';
    }
    if ($link !== '') $msg .= ' Track: ' . $link;
    return $msg;
}

/**
 * Typical minutes between stops from past trips. Each element of $trips is a
 * list of unix timestamps in visit order (trip start first, then each stop's
 * done time). Gaps over 60 minutes are ignored as outliers. Returns null until
 * there are at least 3 usable gaps.
 */
function transport_typical_leg_minutes(array $trips): ?float
{
    $gaps = [];
    foreach ($trips as $times) {
        $times = array_values(array_map('intval', array_filter((array)$times, static fn($t) => $t !== false && $t !== null)));
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $g = ($times[$i] - $times[$i - 1]) / 60;
            if ($g > 0 && $g <= 60) $gaps[] = $g;
        }
    }
    if (count($gaps) < 3) return null;
    sort($gaps);
    $mid = intdiv(count($gaps), 2);
    $median = count($gaps) % 2 ? $gaps[$mid] : ($gaps[$mid - 1] + $gaps[$mid]) / 2;
    return max(1.0, min(30.0, $median));
}

/**
 * Approximate arrival for each pending stop of a running trip.
 *
 * $stops: ordered rows with keys id, status ('pending'|'done'|'absent'),
 *         done_at, reached_at (datetime strings or null) and leg (minutes to
 *         reach this stop from the previous one, or null).
 * Returns [id => ['minutes' => int, 'stops_ahead' => int, 'reached' => bool]]
 * for pending stops only. The cab's progress is measured from the later of
 * the trip start and the last stop it finished.
 */
function transport_compute_etas(array $stops, ?string $startedAt, int $now, float $defaultLeg): array
{
    if ($startedAt === null || $startedAt === '') return [];
    $last = strtotime($startedAt) ?: $now;
    foreach ($stops as $s) {
        if (in_array((string)$s['status'], ['done', 'absent'], true) && !empty($s['done_at'])) {
            $t = strtotime((string)$s['done_at']);
            if ($t !== false && $t > $last) $last = $t;
        }
    }
    $elapsed = max(0, ($now - $last) / 60);

    $out = [];
    $ahead = 0;
    $cumulative = 0.0;
    foreach ($stops as $s) {
        if ((string)$s['status'] !== 'pending') continue;
        $leg = isset($s['leg']) && $s['leg'] !== null && (float)$s['leg'] > 0 ? (float)$s['leg'] : $defaultLeg;
        $reached = !empty($s['reached_at']);
        if ($ahead === 0) {
            $cumulative = $reached ? 0.0 : max(0.0, $leg - $elapsed);
        } else {
            $cumulative += $leg;
        }
        $out[(int)$s['id']] = [
            'minutes'     => (int)ceil($cumulative),
            'stops_ahead' => $ahead,
            'reached'     => $reached && $ahead === 0,
        ];
        $ahead++;
    }
    return $out;
}

/** Parent-facing approximate time, rounded up to 5-minute steps. */
function transport_eta_phrase(int $minutes): string
{
    if ($minutes <= 1) return 'arriving any moment';
    if ($minutes <= 5) return 'about 5 minutes away';
    return 'about ' . ((int)ceil($minutes / 5) * 5) . ' minutes away';
}

/**
 * Leg minutes per trip stop. Route legs are stored in pickup order as "time
 * from the previous stop"; a drop trip runs the list in reverse, so each
 * stop's leg is the stored leg of the stop that followed it in pickup order.
 *
 * $routeStops: rows with student_id, stop_order, leg_minutes.
 * Returns [student_id => ?float].
 */
function transport_trip_legs(array $routeStops, string $direction): array
{
    usort($routeStops, static fn($a, $b) => (int)$a['stop_order'] <=> (int)$b['stop_order']);
    $out = [];
    $n = count($routeStops);
    for ($i = 0; $i < $n; $i++) {
        $sid = (int)$routeStops[$i]['student_id'];
        if ($direction === 'drop') {
            $next = $routeStops[$i + 1]['leg_minutes'] ?? null;
            $out[$sid] = $next !== null ? (float)$next : null;
        } else {
            $leg = $routeStops[$i]['leg_minutes'] ?? null;
            $out[$sid] = $leg !== null ? (float)$leg : null;
        }
    }
    return $out;
}

// ---------- Cabs ------------------------------------------------------------

function transport_cabs(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM transport_cabs' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, name';
    return db()->query($sql)->fetchAll();
}

function transport_cab_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM transport_cabs WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

function transport_cab_save(array $in): int
{
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Cab name is required.');
    $phone = trim((string)($in['driver_phone'] ?? ''));
    if ($phone !== '' && transport_phone_digits($phone) === '') {
        throw new InvalidArgumentException('Driver phone does not look like a phone number.');
    }
    $params = [
        ':n'  => mb_substr($name, 0, 80),
        ':v'  => mb_substr(trim((string)($in['vehicle_no'] ?? '')), 0, 40),
        ':dn' => mb_substr(trim((string)($in['driver_name'] ?? '')), 0, 120),
        ':dp' => mb_substr($phone, 0, 40),
        ':c'  => max(0, min(200, (int)($in['capacity'] ?? 0))),
        ':a'  => !empty($in['is_active']) ? 1 : 0,
    ];
    $id = (int)($in['id'] ?? 0);
    if ($id > 0) {
        $params[':id'] = $id;
        db()->prepare('UPDATE transport_cabs SET name=:n, vehicle_no=:v, driver_name=:dn, driver_phone=:dp,
                       capacity=:c, is_active=:a WHERE id=:id')->execute($params);
        return $id;
    }
    db()->prepare('INSERT INTO transport_cabs (name, vehicle_no, driver_name, driver_phone, capacity, is_active)
                   VALUES (:n, :v, :dn, :dp, :c, :a)')->execute($params);
    return (int)db()->lastInsertId();
}

// ---------- Routes + stops --------------------------------------------------

function transport_routes(bool $activeOnly = false): array
{
    $sql = 'SELECT r.*, c.name AS cab_name, c.vehicle_no, c.driver_name, c.driver_phone,
                   (SELECT COUNT(*) FROM transport_stops s WHERE s.route_id = r.id) AS stop_count
              FROM transport_routes r
              LEFT JOIN transport_cabs c ON c.id = r.cab_id'
         . ($activeOnly ? ' WHERE r.is_active = 1' : '')
         . ' ORDER BY r.is_active DESC, r.name';
    return db()->query($sql)->fetchAll();
}

function transport_route_get(int $id): ?array
{
    $st = db()->prepare('SELECT r.*, c.name AS cab_name, c.vehicle_no, c.driver_name, c.driver_phone
                           FROM transport_routes r
                           LEFT JOIN transport_cabs c ON c.id = r.cab_id
                          WHERE r.id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

function transport_time_or_null(string $t): ?string
{
    $t = trim($t);
    if ($t === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
        throw new InvalidArgumentException('Times must look like 07:45.');
    }
    return $t . ':00';
}

function transport_route_save(array $in): int
{
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Route name is required.');
    $dir = (string)($in['direction'] ?? 'both');
    if (!in_array($dir, TRANSPORT_ROUTE_DIRECTIONS, true)) throw new InvalidArgumentException('Pick a direction.');
    $cabId = (int)($in['cab_id'] ?? 0);
    if ($cabId > 0 && !transport_cab_get($cabId)) throw new InvalidArgumentException('Unknown cab.');
    $params = [
        ':n'  => mb_substr($name, 0, 120),
        ':c'  => $cabId > 0 ? $cabId : null,
        ':d'  => $dir,
        ':pt' => transport_time_or_null((string)($in['pickup_time'] ?? '')),
        ':dt' => transport_time_or_null((string)($in['drop_time'] ?? '')),
        ':a'  => !empty($in['is_active']) ? 1 : 0,
    ];
    $id = (int)($in['id'] ?? 0);
    if ($id > 0) {
        $params[':id'] = $id;
        db()->prepare('UPDATE transport_routes SET name=:n, cab_id=:c, direction=:d, pickup_time=:pt,
                       drop_time=:dt, is_active=:a WHERE id=:id')->execute($params);
        return $id;
    }
    db()->prepare('INSERT INTO transport_routes (name, cab_id, direction, pickup_time, drop_time, is_active)
                   VALUES (:n, :c, :d, :pt, :dt, :a)')->execute($params);
    return (int)db()->lastInsertId();
}

/** Stops in pickup order with child name and grade (driver/admin only). */
function transport_route_stops(int $routeId): array
{
    $st = db()->prepare("
        SELECT s.*, st.first_name, st.last_name, st.grade, st.transport AS student_transport
          FROM transport_stops s
          JOIN students st ON st.id = s.student_id
         WHERE s.route_id = :r
         ORDER BY s.stop_order, s.id
    ");
    $st->execute([':r' => $routeId]);
    return $st->fetchAll();
}

/** Active enrolled children not yet on this route; cab users first. */
function transport_route_candidates(int $routeId): array
{
    $st = db()->prepare("
        SELECT st.id, st.first_name, st.last_name, st.grade, st.transport
          FROM students st
         WHERE COALESCE(st.is_active, 1) = 1
           AND COALESCE(st.enrollment_status, 'enrolled') IN ('enrolled','promoted')
           AND NOT EXISTS (SELECT 1 FROM transport_stops s WHERE s.route_id = :r AND s.student_id = st.id)
         ORDER BY (st.transport = 'cab') DESC, st.first_name, st.last_name
    ");
    $st->execute([':r' => $routeId]);
    return $st->fetchAll();
}

function transport_stop_add(int $routeId, int $studentId, string $note = ''): void
{
    if (!transport_route_get($routeId)) throw new InvalidArgumentException('Unknown route.');
    $chk = db()->prepare('SELECT id FROM students WHERE id = :id');
    $chk->execute([':id' => $studentId]);
    if (!$chk->fetchColumn()) throw new InvalidArgumentException('Pick a child to add.');
    $next = db()->prepare('SELECT COALESCE(MAX(stop_order), 0) + 1 FROM transport_stops WHERE route_id = :r');
    $next->execute([':r' => $routeId]);
    try {
        db()->prepare('INSERT INTO transport_stops (route_id, student_id, stop_order, stop_note)
                       VALUES (:r, :s, :o, :n)')
            ->execute([':r' => $routeId, ':s' => $studentId, ':o' => (int)$next->fetchColumn(),
                       ':n' => mb_substr(trim($note), 0, 255)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new InvalidArgumentException('That child is already on this route.');
        throw $e;
    }
}

function transport_stop_update(int $stopId, string $note, string $lat, string $lng): void
{
    $lat = trim($lat);
    $lng = trim($lng);
    $latV = $lat === '' ? null : (float)$lat;
    $lngV = $lng === '' ? null : (float)$lng;
    if (($latV === null) !== ($lngV === null)) {
        throw new InvalidArgumentException('Enter both latitude and longitude, or neither.');
    }
    if ($latV !== null && ($latV < -90 || $latV > 90 || $lngV < -180 || $lngV > 180)) {
        throw new InvalidArgumentException('Coordinates are out of range.');
    }
    db()->prepare('UPDATE transport_stops SET stop_note = :n, lat = :la, lng = :lo,
                   leg_minutes = CASE WHEN lat <=> :la2 AND lng <=> :lo2 THEN leg_minutes ELSE NULL END
                   WHERE id = :id')
        ->execute([':n' => mb_substr(trim($note), 0, 255), ':la' => $latV, ':lo' => $lngV,
                   ':la2' => $latV, ':lo2' => $lngV, ':id' => $stopId]);
}

function transport_stop_remove(int $stopId): void
{
    db()->prepare('DELETE FROM transport_stops WHERE id = :id')->execute([':id' => $stopId]);
}

/** Move a stop one place up or down in pickup order. Clears cached legs on the route. */
function transport_stop_move(int $stopId, string $dir): void
{
    $st = db()->prepare('SELECT route_id FROM transport_stops WHERE id = :id');
    $st->execute([':id' => $stopId]);
    $routeId = (int)$st->fetchColumn();
    if ($routeId <= 0) throw new InvalidArgumentException('Unknown stop.');
    $ids = array_map(static fn($r) => (int)$r['id'], transport_route_stops($routeId));
    $pos = array_search($stopId, $ids, true);
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($pos === false || $swap < 0 || $swap >= count($ids)) return;
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $upd = db()->prepare('UPDATE transport_stops SET stop_order = :o, leg_minutes = NULL WHERE id = :id');
    foreach ($ids as $i => $id) $upd->execute([':o' => $i + 1, ':id' => $id]);
}

// ---------- Parents + private links ----------------------------------------

/** Parents with a usable phone, primary first. */
function transport_parents_with_phone(int $studentId): array
{
    $st = db()->prepare("SELECT id, name, relation, phone, is_primary FROM student_parents
                          WHERE student_id = :s AND phone IS NOT NULL AND phone <> ''
                          ORDER BY is_primary DESC, id");
    $st->execute([':s' => $studentId]);
    return array_values(array_filter($st->fetchAll(), static fn($p) => transport_phone_digits($p['phone']) !== ''));
}

function transport_parent_link_active(int $studentId): ?array
{
    $st = db()->prepare('SELECT * FROM transport_parent_links WHERE student_id = :s AND revoked_at IS NULL
                         ORDER BY id DESC LIMIT 1');
    $st->execute([':s' => $studentId]);
    return $st->fetch() ?: null;
}

function transport_parent_link_ensure(int $studentId, ?int $userId): array
{
    $existing = transport_parent_link_active($studentId);
    if ($existing) return $existing;
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO transport_parent_links (student_id, token, created_by_user_id) VALUES (:s, :t, :u)')
        ->execute([':s' => $studentId, ':t' => $token, ':u' => $userId]);
    return transport_parent_link_active($studentId) ?? ['token' => $token, 'student_id' => $studentId];
}

function transport_parent_link_revoke(int $studentId): void
{
    db()->prepare('UPDATE transport_parent_links SET revoked_at = NOW() WHERE student_id = :s AND revoked_at IS NULL')
        ->execute([':s' => $studentId]);
}

function transport_track_url(string $token): string
{
    return app_base_url() . '/transport/track.php?t=' . rawurlencode($token);
}

/** Student id for a live (unrevoked) token, or null. Bumps last_accessed_at. */
function transport_student_for_token(string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = db()->prepare('SELECT id, student_id, token FROM transport_parent_links WHERE token = :t AND revoked_at IS NULL');
    $st->execute([':t' => $token]);
    $row = $st->fetch();
    if (!$row || !hash_equals((string)$row['token'], $token)) return null;
    try {
        db()->prepare('UPDATE transport_parent_links SET last_accessed_at = NOW() WHERE id = :id')
            ->execute([':id' => (int)$row['id']]);
    } catch (Throwable $e) { /* best-effort */ }
    return (int)$row['student_id'];
}

// ---------- Trips -----------------------------------------------------------

function transport_trip_get(int $id): ?array
{
    $st = db()->prepare('SELECT t.*, r.name AS route_name, r.cab_id, c.name AS cab_name, c.vehicle_no,
                                c.driver_name, c.driver_phone
                           FROM transport_trips t
                           JOIN transport_routes r ON r.id = t.route_id
                           LEFT JOIN transport_cabs c ON c.id = r.cab_id
                          WHERE t.id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

/** Copy the route's stops onto a not-yet-started trip (drop runs in reverse). */
function transport_trip_snapshot(int $tripId): void
{
    $trip = transport_trip_get($tripId);
    if (!$trip || $trip['status'] !== 'scheduled') return;
    $stops = transport_route_stops((int)$trip['route_id']);
    if ($trip['direction'] === 'drop') $stops = array_reverse($stops);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM transport_trip_stops WHERE trip_id = :t')->execute([':t' => $tripId]);
        $ins = $pdo->prepare('INSERT INTO transport_trip_stops (trip_id, student_id, stop_order) VALUES (:t, :s, :o)');
        foreach ($stops as $i => $s) {
            $ins->execute([':t' => $tripId, ':s' => (int)$s['student_id'], ':o' => $i + 1]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Find or create the trip for a route/day/direction. */
function transport_trip_ensure(int $routeId, string $date, string $direction): array
{
    if (!in_array($direction, TRANSPORT_DIRECTIONS, true)) throw new InvalidArgumentException('Unknown direction.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('Bad date.');
    $route = transport_route_get($routeId);
    if (!$route || !(int)$route['is_active']) throw new InvalidArgumentException('Route is not active.');
    if (!in_array($direction, transport_route_directions($route), true)) {
        throw new InvalidArgumentException('This route does not run that direction.');
    }
    db()->prepare('INSERT IGNORE INTO transport_trips (route_id, trip_date, direction) VALUES (:r, :d, :dir)')
        ->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction]);
    $st = db()->prepare('SELECT id FROM transport_trips WHERE route_id = :r AND trip_date = :d AND direction = :dir');
    $st->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction]);
    $id = (int)$st->fetchColumn();
    transport_trip_snapshot($id);
    return transport_trip_get($id);
}

function transport_trip_start(int $tripId, int $userId): void
{
    $trip = transport_trip_get($tripId);
    if (!$trip) throw new InvalidArgumentException('Trip not found.');
    if ($trip['status'] !== 'scheduled') throw new InvalidArgumentException('This trip has already started.');
    transport_trip_snapshot($tripId);
    $n = db()->prepare('SELECT COUNT(*) FROM transport_trip_stops WHERE trip_id = :t');
    $n->execute([':t' => $tripId]);
    if ((int)$n->fetchColumn() === 0) throw new InvalidArgumentException('Add children to this route before starting.');
    db()->prepare("UPDATE transport_trips SET status = 'running', started_at = NOW(), started_by_user_id = :u
                   WHERE id = :id AND status = 'scheduled'")->execute([':u' => $userId, ':id' => $tripId]);
}

function transport_trip_finish(int $tripId): void
{
    db()->prepare("UPDATE transport_trips SET status = 'completed', completed_at = COALESCE(completed_at, NOW())
                   WHERE id = :id AND status = 'running'")->execute([':id' => $tripId]);
}

function transport_trip_cancel(int $tripId): void
{
    db()->prepare("UPDATE transport_trips SET status = 'cancelled' WHERE id = :id AND status IN ('scheduled','running')")
        ->execute([':id' => $tripId]);
}

/** Trip stops in visit order, with child name (operator/admin only). */
function transport_trip_stops(int $tripId): array
{
    $st = db()->prepare("
        SELECT ts.*, st.first_name, st.last_name, st.grade, s.stop_note
          FROM transport_trip_stops ts
          JOIN transport_trips t ON t.id = ts.trip_id
          JOIN students st ON st.id = ts.student_id
          LEFT JOIN transport_stops s ON s.route_id = t.route_id AND s.student_id = ts.student_id
         WHERE ts.trip_id = :t
         ORDER BY ts.stop_order, ts.id
    ");
    $st->execute([':t' => $tripId]);
    return $st->fetchAll();
}

function transport_trip_stop_get(int $tripStopId): ?array
{
    $st = db()->prepare('SELECT ts.*, st.first_name, st.last_name FROM transport_trip_stops ts
                           JOIN students st ON st.id = ts.student_id WHERE ts.id = :id');
    $st->execute([':id' => $tripStopId]);
    return $st->fetch() ?: null;
}

/**
 * Operator action on one stop: reached | done | absent | undo.
 * Finishing the last pending stop completes the trip.
 */
function transport_mark_stop(int $tripStopId, string $action, int $userId): void
{
    $stop = transport_trip_stop_get($tripStopId);
    if (!$stop) throw new InvalidArgumentException('Stop not found.');
    $trip = transport_trip_get((int)$stop['trip_id']);
    if (!$trip || $trip['status'] !== 'running') throw new InvalidArgumentException('Start the trip first.');

    $sql = [
        'reached' => "UPDATE transport_trip_stops SET reached_at = COALESCE(reached_at, NOW()), marked_by_user_id = :u
                      WHERE id = :id AND status = 'pending'",
        'done'    => "UPDATE transport_trip_stops SET status = 'done', reached_at = COALESCE(reached_at, NOW()),
                      done_at = NOW(), marked_by_user_id = :u WHERE id = :id",
        'absent'  => "UPDATE transport_trip_stops SET status = 'absent', done_at = NOW(), marked_by_user_id = :u
                      WHERE id = :id",
        'undo'    => "UPDATE transport_trip_stops SET status = 'pending', done_at = NULL, reached_at = NULL,
                      marked_by_user_id = :u WHERE id = :id",
    ][$action] ?? null;
    if ($sql === null) throw new InvalidArgumentException('Unknown action.');
    db()->prepare($sql)->execute([':u' => $userId, ':id' => $tripStopId]);

    if ($action === 'done' || $action === 'absent') {
        $left = db()->prepare("SELECT COUNT(*) FROM transport_trip_stops WHERE trip_id = :t AND status = 'pending'");
        $left->execute([':t' => (int)$stop['trip_id']]);
        if ((int)$left->fetchColumn() === 0) transport_trip_finish((int)$stop['trip_id']);
    }
}

/** Log that the operator opened a WhatsApp alert ('eta' | 'reached'). */
function transport_record_alert(int $tripStopId, string $kind): void
{
    $col = ['eta' => 'eta_alert_at', 'reached' => 'reached_alert_at'][$kind] ?? null;
    if ($col === null) throw new InvalidArgumentException('Unknown alert.');
    db()->prepare("UPDATE transport_trip_stops SET $col = COALESCE($col, NOW()) WHERE id = :id")
        ->execute([':id' => $tripStopId]);
}

// ---------- ETA -------------------------------------------------------------

function transport_default_leg_minutes(): float
{
    $v = (float)app_setting('transport_default_stop_minutes', '5');
    return $v >= 1 && $v <= 30 ? $v : 5.0;
}

/** Typical minutes between stops for a route+direction from its last 10 finished trips. */
function transport_route_leg_minutes(int $routeId, string $direction): float
{
    $st = db()->prepare("SELECT id, started_at FROM transport_trips
                          WHERE route_id = :r AND direction = :d AND status = 'completed' AND started_at IS NOT NULL
                          ORDER BY trip_date DESC LIMIT 10");
    $st->execute([':r' => $routeId, ':d' => $direction]);
    $trips = [];
    $done = db()->prepare("SELECT done_at FROM transport_trip_stops WHERE trip_id = :t AND status = 'done'
                            AND done_at IS NOT NULL ORDER BY done_at");
    foreach ($st->fetchAll() as $t) {
        $times = [strtotime((string)$t['started_at'])];
        $done->execute([':t' => (int)$t['id']]);
        foreach ($done->fetchAll() as $d) $times[] = strtotime((string)$d['done_at']);
        $trips[] = $times;
    }
    return transport_typical_leg_minutes($trips) ?? transport_default_leg_minutes();
}

/** ETAs keyed by trip_stop id for a running trip (empty otherwise). */
function transport_trip_etas(array $trip, ?array $tripStops = null): array
{
    if (($trip['status'] ?? '') !== 'running') return [];
    $tripStops ??= transport_trip_stops((int)$trip['id']);
    $legs = transport_trip_legs(transport_route_stops((int)$trip['route_id']), (string)$trip['direction']);
    $rows = [];
    foreach ($tripStops as $s) {
        $rows[] = [
            'id'         => (int)$s['id'],
            'status'     => (string)$s['status'],
            'done_at'    => $s['done_at'],
            'reached_at' => $s['reached_at'],
            'leg'        => $legs[(int)$s['student_id']] ?? null,
        ];
    }
    return transport_compute_etas(
        $rows,
        (string)$trip['started_at'],
        time(),
        transport_route_leg_minutes((int)$trip['route_id'], (string)$trip['direction'])
    );
}

/** Pending stops whose 5-minute alert is due and not yet opened. */
function transport_due_eta_alerts(array $tripStops, array $etas): array
{
    $due = [];
    foreach ($tripStops as $s) {
        $e = $etas[(int)$s['id']] ?? null;
        if ($e === null || $e['reached'] || !empty($s['eta_alert_at'])) continue;
        if ($e['minutes'] <= TRANSPORT_ETA_ALERT_MINUTES) $due[] = (int)$s['id'];
    }
    return $due;
}

/** Changes whenever the trip screen would render differently (status, marks, alerts due). */
function transport_trip_signature(array $trip, array $tripStops, array $due): string
{
    $parts = [(string)$trip['status']];
    foreach ($tripStops as $s) {
        $parts[] = $s['id'] . ':' . $s['status'] . ':' . ($s['reached_at'] ? 1 : 0)
                 . ':' . ($s['eta_alert_at'] ? 1 : 0) . ':' . ($s['reached_alert_at'] ? 1 : 0);
    }
    $parts[] = implode(',', $due);
    return substr(sha1(implode('|', $parts)), 0, 16);
}

// ---------- Google Maps (optional) -----------------------------------------

function transport_maps_key(): string
{
    return trim((string)app_setting('transport_maps_api_key', ''));
}

/**
 * Fill leg_minutes for each stop (travel time from the previous stop) using
 * the Google Distance Matrix API. Needs a key and coordinates on consecutive
 * stops. $fetch(url): ?string is injectable for tests.
 * Returns ['updated' => int, 'skipped' => int, 'error' => ?string].
 */
function transport_maps_refresh_route(int $routeId, ?callable $fetch = null): array
{
    $key = transport_maps_key();
    if ($key === '') return ['updated' => 0, 'skipped' => 0, 'error' => 'Add a Google Maps API key first.'];
    $fetch ??= static function (string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r === false ? null : (string)$r;
    };
    $stops = transport_route_stops($routeId);
    $updated = 0;
    $skipped = 0;
    $error = null;
    $upd = db()->prepare('UPDATE transport_stops SET leg_minutes = :m WHERE id = :id');
    for ($i = 1, $n = count($stops); $i < $n; $i++) {
        $a = $stops[$i - 1];
        $b = $stops[$i];
        if ($a['lat'] === null || $b['lat'] === null) { $skipped++; continue; }
        $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?'
             . http_build_query([
                 'origins'        => $a['lat'] . ',' . $a['lng'],
                 'destinations'   => $b['lat'] . ',' . $b['lng'],
                 'departure_time' => 'now',
                 'key'            => $key,
             ]);
        $data = json_decode((string)$fetch($url), true);
        $el = $data['rows'][0]['elements'][0] ?? null;
        if (($data['status'] ?? '') !== 'OK' || ($el['status'] ?? '') !== 'OK') {
            $error = 'Google Maps: ' . (string)($data['error_message'] ?? $el['status'] ?? $data['status'] ?? 'no response');
            $skipped++;
            continue;
        }
        $secs = (int)($el['duration_in_traffic']['value'] ?? $el['duration']['value'] ?? 0);
        $upd->execute([':m' => max(1, (int)ceil($secs / 60)), ':id' => (int)$b['id']]);
        $updated++;
    }
    return ['updated' => $updated, 'skipped' => $skipped, 'error' => $error];
}

// ---------- Today's board + parent status ----------------------------------

/** Every active route × direction for $date, with its trip (or null) and progress. */
function transport_board(string $date): array
{
    $st = db()->prepare("SELECT t.*,
                                (SELECT COUNT(*) FROM transport_trip_stops x WHERE x.trip_id = t.id) AS total,
                                (SELECT COUNT(*) FROM transport_trip_stops x WHERE x.trip_id = t.id AND x.status <> 'pending') AS finished
                           FROM transport_trips t WHERE t.trip_date = :d");
    $st->execute([':d' => $date]);
    $trips = [];
    foreach ($st as $t) $trips[(int)$t['route_id'] . '|' . $t['direction']] = $t;

    $out = [];
    foreach (transport_routes(true) as $r) {
        foreach (transport_route_directions($r) as $dir) {
            $out[] = ['route' => $r, 'direction' => $dir, 'trip' => $trips[(int)$r['id'] . '|' . $dir] ?? null];
        }
    }
    return $out;
}

/**
 * What a parent may see for their child today. Contains only this child's
 * first name, a state, approximate minutes, stops ahead, and times.
 */
function transport_parent_status(int $studentId, ?string $date = null): array
{
    $date ??= date('Y-m-d');
    $st = db()->prepare('SELECT first_name FROM students WHERE id = :id');
    $st->execute([':id' => $studentId]);
    $first = (string)$st->fetchColumn();

    $q = db()->prepare("
        SELECT t.*, ts.id AS trip_stop_id, ts.status AS stop_status, ts.reached_at, ts.done_at
          FROM transport_trip_stops ts
          JOIN transport_trips t ON t.id = ts.trip_id
         WHERE ts.student_id = :s AND t.trip_date = :d AND t.status <> 'cancelled'
         ORDER BY FIELD(t.status, 'running', 'scheduled', 'completed'), t.direction = 'drop' DESC
    ");
    $q->execute([':s' => $studentId, ':d' => $date]);
    $rows = $q->fetchAll();
    // Prefer the trip that is on the road; otherwise the latest relevant one.
    $row = $rows[0] ?? null;

    $status = ['child' => $first, 'state' => 'no_trip', 'direction' => null,
               'minutes' => null, 'stops_ahead' => null, 'time' => null, 'phrase' => ''];
    if (!$row) {
        $status['phrase'] = 'No cab trip for today yet.';
        return $status;
    }
    $dir = (string)$row['direction'];
    $status['direction'] = $dir;
    $verbDone = $dir === 'drop' ? 'Dropped' : 'Picked up';

    if ($row['stop_status'] === 'done') {
        $status['state'] = 'done';
        $status['time'] = date('g:i a', strtotime((string)$row['done_at']));
        $status['phrase'] = $verbDone . ' at ' . $status['time'] . '.';
    } elseif ($row['stop_status'] === 'absent') {
        $status['state'] = 'absent';
        $status['phrase'] = 'Marked absent for this trip.';
    } elseif ($row['status'] === 'scheduled') {
        $status['state'] = 'scheduled';
        $status['phrase'] = 'The cab has not started yet.';
    } elseif ($row['status'] === 'completed') {
        $status['state'] = 'completed';
        $status['phrase'] = 'This trip has finished.';
    } else {
        $etas = transport_trip_etas($row);
        $e = $etas[(int)$row['trip_stop_id']] ?? null;
        if ($e && $e['reached']) {
            $status['state'] = 'reached';
            $status['phrase'] = 'The cab has reached your stop.';
        } elseif ($e) {
            $status['state'] = 'on_the_way';
            $status['minutes'] = $e['minutes'];
            $status['stops_ahead'] = $e['stops_ahead'];
            $ahead = $e['stops_ahead'] === 0 ? 'you are the next stop'
                : ($e['stops_ahead'] === 1 ? '1 stop before you' : $e['stops_ahead'] . ' stops before you');
            $status['phrase'] = 'On the way — ' . transport_eta_phrase($e['minutes']) . ', ' . $ahead . '.';
        } else {
            $status['state'] = 'on_the_way';
            $status['phrase'] = 'The cab is on the way.';
        }
    }
    return $status;
}
