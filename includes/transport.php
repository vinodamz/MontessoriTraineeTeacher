<?php
/**
 * includes/transport.php — daily cab: cabs, routes, ordered stops, trips,
 * approximate ETAs, one-tap WhatsApp alerts, and private parent links.
 *
 * Confidentiality rules the public tracking page relies on:
 *   - parents see their own child's first name, a status, an approximate
 *     time and a count of stops ahead — never other children or stop notes;
 *   - the cab's live position is shown only while their child's trip is
 *     running and their child is still waiting, plus their own stop;
 *   - the Maps key is only ever used server-side.
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

/** Which trip to show when a route has more than one run today. */
function transport_trip_rank(string $status): int
{
    return ['running' => 3, 'scheduled' => 2, 'completed' => 1, 'cancelled' => 0][$status] ?? 0;
}

/** A real calendar day, or an error. Empty means today when $blankToday is set. */
function transport_valid_date(?string $date, bool $blankToday = false): string
{
    $date = trim((string)$date);
    if ($date === '') {
        if ($blankToday) return date('Y-m-d');
        throw new InvalidArgumentException('Pick a date.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('Bad date.');
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    if (!checkdate($m, $d, $y)) throw new InvalidArgumentException('Bad date.');
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/** Dates a driver may create or open a trip for: the past year through the next few months. */
function transport_writable_date(?string $date): string
{
    $date = transport_valid_date($date, true);
    $ts = strtotime($date);
    $today = strtotime(date('Y-m-d'));
    if ($ts < $today - 366 * 86400 || $ts > $today + 120 * 86400) {
        throw new InvalidArgumentException('Pick a date within the last year or the next few months.');
    }
    return $date;
}

/**
 * Collapse trip rows (trip_date, status, n) into one entry per day.
 * @param array<int, array{trip_date: string, status: string, n: int|string}> $rows
 */
function transport_calendar_fold(array $rows): array
{
    $days = [];
    foreach ($rows as $row) {
        $date = (string)$row['trip_date'];
        $days[$date] ??= ['date' => $date, 'running' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
        $status = (string)$row['status'];
        if (isset($days[$date][$status])) $days[$date][$status] += (int)$row['n'];
    }
    ksort($days);
    return array_values($days);
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
function transport_compute_etas(array $stops, ?string $startedAt, int $now, float $defaultLeg, ?float $liveFirstLeg = null): array
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
            $cumulative = $reached ? 0.0 : ($liveFirstLeg ?? max(0.0, $leg - $elapsed));
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

/** Great-circle distance in metres. */
function transport_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/**
 * Minutes to cover a straight-line distance by road in town: roads run ~1.4×
 * the straight line, and a school cab averages ~18 km/h with stops.
 */
function transport_live_minutes(float $straightLineM): float
{
    return ($straightLineM * 1.4) / (18000 / 60);
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

/**
 * Minutes for a town leg from a straight-line distance. Same pace the live
 * ETA uses: roads about 1.4× the straight line, cab about 18 km/h.
 */
function transport_leg_minutes_from_distance(float $meters): int
{
    return max(1, min(180, (int)ceil(transport_live_minutes(max(0.0, $meters)))));
}

/**
 * Rewrite each stop's leg from the previous stop once both have coordinates.
 * A Google Maps key, when one is saved, replaces those estimates with road times.
 * Returns how many legs were written.
 */
function transport_recalculate_route_legs(int $routeId): int
{
    $stops = transport_route_stops($routeId);
    $upd = db()->prepare('UPDATE transport_stops SET leg_minutes = :m WHERE id = :id');
    $written = 0;
    for ($i = 1, $n = count($stops); $i < $n; $i++) {
        $a = $stops[$i - 1];
        $b = $stops[$i];
        if ($a['lat'] === null || $b['lat'] === null) continue;
        $mins = transport_leg_minutes_from_distance(
            transport_distance_m((float)$a['lat'], (float)$a['lng'], (float)$b['lat'], (float)$b['lng'])
        );
        $upd->execute([':m' => $mins, ':id' => (int)$b['id']]);
        $written++;
    }
    if (transport_maps_key() !== '') {
        $google = transport_maps_refresh_route($routeId);
        if (($google['updated'] ?? 0) > 0) $written = (int)$google['updated'];
    }
    return $written;
}

/**
 * Save the cab's position as a pickup that had no coordinates, then rebuild
 * the route's leg times. Only after the driver has marked the stop reached.
 */
function transport_save_reached_location(int $tripStopId, float $lat, float $lng): void
{
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
        throw new InvalidArgumentException('That location is not usable.');
    }
    $stop = transport_trip_stop_get($tripStopId);
    if (!$stop) throw new InvalidArgumentException('Stop not found.');
    $trip = transport_trip_get((int)$stop['trip_id']);
    if (!$trip || $trip['status'] !== 'running') throw new InvalidArgumentException('Start the trip first.');
    if (empty($stop['reached_at'])) {
        throw new InvalidArgumentException('Mark the stop reached before saving its location.');
    }
    $rs = db()->prepare('SELECT id, lat FROM transport_stops WHERE route_id = :r AND student_id = :s');
    $rs->execute([':r' => (int)$trip['route_id'], ':s' => (int)$stop['student_id']]);
    $row = $rs->fetch();
    if (!$row) throw new InvalidArgumentException('This child is not on the route.');
    if ($row['lat'] !== null) throw new InvalidArgumentException('This stop already has a location.');
    db()->prepare('UPDATE transport_stops SET lat = :la, lng = :lo WHERE id = :id AND lat IS NULL')
        ->execute([':la' => round($lat, 7), ':lo' => round($lng, 7), ':id' => (int)$row['id']]);
    transport_recalculate_route_legs((int)$trip['route_id']);
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
    transport_after_snapshot($tripId);
}

function transport_trip_slot_check(int $routeId, string $date, string $direction): array
{
    if (!in_array($direction, TRANSPORT_DIRECTIONS, true)) throw new InvalidArgumentException('Unknown direction.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('Bad date.');
    $route = transport_route_get($routeId);
    if (!$route || !(int)$route['is_active']) throw new InvalidArgumentException('Route is not active.');
    if (!in_array($direction, transport_route_directions($route), true)) {
        throw new InvalidArgumentException('This route does not run that direction.');
    }
    return $route;
}

/** Open (scheduled or running) trip for a route/day/direction, if any. */
function transport_trip_open_id(int $routeId, string $date, string $direction): int
{
    $st = db()->prepare("SELECT id FROM transport_trips
                          WHERE route_id = :r AND trip_date = :d AND direction = :dir
                            AND status IN ('scheduled','running')
                          ORDER BY run_no DESC, id DESC LIMIT 1");
    $st->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction]);
    return (int)$st->fetchColumn();
}

/** Find or create the first trip for a route/day/direction. Does not start another run. */
function transport_trip_ensure(int $routeId, string $date, string $direction): array
{
    transport_trip_slot_check($routeId, $date, $direction);
    $open = transport_trip_open_id($routeId, $date, $direction);
    if ($open > 0) {
        $trip = transport_trip_get($open);
        if ($trip && $trip['status'] === 'scheduled') transport_trip_snapshot($open);
        return transport_trip_get($open);
    }
    $st = db()->prepare('SELECT id FROM transport_trips WHERE route_id = :r AND trip_date = :d AND direction = :dir
                          ORDER BY run_no DESC, id DESC LIMIT 1');
    $st->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction]);
    $existing = (int)$st->fetchColumn();
    if ($existing > 0) return transport_trip_get($existing);
    db()->prepare('INSERT IGNORE INTO transport_trips (route_id, trip_date, direction, run_no) VALUES (:r, :d, :dir, 1)')
        ->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction]);
    $st->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction]);
    $id = (int)$st->fetchColumn();
    transport_trip_snapshot($id);
    return transport_trip_get($id);
}

/**
 * Start another run when today's trip for this route is finished or cancelled.
 * If one is already scheduled or on the road, that trip is returned.
 */
function transport_trip_begin(int $routeId, string $date, string $direction): array
{
    transport_trip_slot_check($routeId, $date, $direction);
    $open = transport_trip_open_id($routeId, $date, $direction);
    if ($open > 0) {
        if ((transport_trip_get($open)['status'] ?? '') === 'scheduled') transport_trip_snapshot($open);
        return transport_trip_get($open);
    }
    $st = db()->prepare('SELECT COALESCE(MAX(run_no), 0) + 1 FROM transport_trips
                          WHERE route_id = :r AND trip_date = :d AND direction = :dir');
    $st->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction]);
    $run = (int)$st->fetchColumn();
    try {
        db()->prepare('INSERT INTO transport_trips (route_id, trip_date, direction, run_no) VALUES (:r, :d, :dir, :n)')
            ->execute([':r' => $routeId, ':d' => $date, ':dir' => $direction, ':n' => $run]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;
        $again = transport_trip_open_id($routeId, $date, $direction);
        if ($again > 0) return transport_trip_get($again);
        throw $e;
    }
    $id = (int)db()->lastInsertId();
    transport_trip_snapshot($id);
    return transport_trip_get($id);
}

/** A new route plus today's trip, for a run that was not set up ahead of time. */
function transport_trip_custom(string $name, string $direction, array $studentIds, ?string $date = null): array
{
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('Give this trip a name.');
    if (!in_array($direction, TRANSPORT_DIRECTIONS, true)) throw new InvalidArgumentException('Pick pickup or drop.');
    $ids = [];
    foreach ($studentIds as $id) {
        $id = (int)$id;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    if (!$ids) throw new InvalidArgumentException('Add at least one child.');
    if (count($ids) > 40) throw new InvalidArgumentException('A trip can have at most 40 children.');
    $chk = db()->prepare('SELECT id FROM students WHERE id = :id');
    foreach ($ids as $id) {
        $chk->execute([':id' => $id]);
        if (!$chk->fetchColumn()) throw new InvalidArgumentException('One of the children was not found.');
    }
    $routeId = transport_route_save([
        'name' => $name,
        'direction' => $direction,
        'is_active' => 1,
    ]);
    // Drop trips visit the route in reverse, so store a drop in reverse of the order the driver picked.
    if ($direction === 'drop') $ids = array_reverse($ids);
    foreach ($ids as $id) transport_stop_add($routeId, $id);
    return transport_trip_begin($routeId, transport_writable_date($date), $direction);
}

function transport_trip_require_editable(array $trip): void
{
    if (!in_array($trip['status'] ?? '', ['scheduled', 'running'], true)) {
        throw new InvalidArgumentException('This trip can no longer be changed.');
    }
}

function transport_renumber_trip_stops(int $tripId): void
{
    $ids = array_map(static fn($s) => (int)$s['id'], transport_trip_stops($tripId));
    $upd = db()->prepare('UPDATE transport_trip_stops SET stop_order = :o WHERE id = :id AND trip_id = :t');
    foreach ($ids as $i => $id) $upd->execute([':o' => $i + 1, ':id' => $id, ':t' => $tripId]);
}

/** Point this trip at another route. Children already marked stay; waiting children come from the new route. */
function transport_trip_set_route(int $tripId, int $routeId): void
{
    $trip = transport_trip_get($tripId);
    if (!$trip) throw new InvalidArgumentException('Trip not found.');
    transport_trip_require_editable($trip);
    $route = transport_route_get($routeId);
    if (!$route || !(int)$route['is_active']) throw new InvalidArgumentException('Route is not active.');
    if (!in_array($trip['direction'], transport_route_directions($route), true)) {
        throw new InvalidArgumentException('That route does not run this direction.');
    }
    if ($trip['status'] === 'scheduled') {
        db()->prepare('UPDATE transport_trips SET route_id = :r WHERE id = :id')
            ->execute([':r' => $routeId, ':id' => $tripId]);
        transport_trip_snapshot($tripId);
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE transport_trips SET route_id = :r WHERE id = :id')
            ->execute([':r' => $routeId, ':id' => $tripId]);
        $pdo->prepare("DELETE FROM transport_trip_stops WHERE trip_id = :t AND status = 'pending'")
            ->execute([':t' => $tripId]);
        $have = $pdo->prepare('SELECT student_id FROM transport_trip_stops WHERE trip_id = :t');
        $have->execute([':t' => $tripId]);
        $already = array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN));
        $stops = transport_route_stops($routeId);
        if ($trip['direction'] === 'drop') $stops = array_reverse($stops);
        $max = $pdo->prepare('SELECT COALESCE(MAX(stop_order), 0) FROM transport_trip_stops WHERE trip_id = :t');
        $max->execute([':t' => $tripId]);
        $order = (int)$max->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO transport_trip_stops (trip_id, student_id, stop_order) VALUES (:t, :s, :o)');
        foreach ($stops as $s) {
            $sid = (int)$s['student_id'];
            if (in_array($sid, $already, true)) continue;
            $order++;
            $ins->execute([':t' => $tripId, ':s' => $sid, ':o' => $order]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    transport_renumber_trip_stops($tripId);
}

/** Move a stop one place. On a running trip, only children still waiting can move. */
function transport_trip_move_stop(int $tripStopId, string $dir): void
{
    if (!in_array($dir, ['up', 'down'], true)) throw new InvalidArgumentException('Unknown direction.');
    $stop = transport_trip_stop_get($tripStopId);
    if (!$stop) throw new InvalidArgumentException('Stop not found.');
    $trip = transport_trip_get((int)$stop['trip_id']);
    if (!$trip) throw new InvalidArgumentException('Trip not found.');
    transport_trip_require_editable($trip);
    if ($trip['status'] === 'running' && $stop['status'] !== 'pending') {
        throw new InvalidArgumentException('Only children still waiting can be moved.');
    }
    $stops = transport_trip_stops((int)$trip['id']);
    $ids = array_map(static fn($s) => (int)$s['id'], $stops);
    $pos = array_search($tripStopId, $ids, true);
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($pos === false || $swap < 0 || $swap >= count($ids)) return;
    if ($trip['status'] === 'running' && $stops[$swap]['status'] !== 'pending') return;
    $upd = db()->prepare('UPDATE transport_trip_stops SET stop_order = :o WHERE id = :id');
    $upd->execute([':o' => (int)$stops[$swap]['stop_order'], ':id' => $tripStopId]);
    $upd->execute([':o' => (int)$stops[$pos]['stop_order'], ':id' => (int)$stops[$swap]['id']]);
    transport_renumber_trip_stops((int)$trip['id']);
}

function transport_trip_add_student(int $tripId, int $studentId): void
{
    $trip = transport_trip_get($tripId);
    if (!$trip) throw new InvalidArgumentException('Trip not found.');
    transport_trip_require_editable($trip);
    $chk = db()->prepare('SELECT id FROM students WHERE id = :id');
    $chk->execute([':id' => $studentId]);
    if (!$chk->fetchColumn()) throw new InvalidArgumentException('Pick a child to add.');
    $next = db()->prepare('SELECT COALESCE(MAX(stop_order), 0) + 1 FROM transport_trip_stops WHERE trip_id = :t');
    $next->execute([':t' => $tripId]);
    try {
        db()->prepare('INSERT INTO transport_trip_stops (trip_id, student_id, stop_order) VALUES (:t, :s, :o)')
            ->execute([':t' => $tripId, ':s' => $studentId, ':o' => (int)$next->fetchColumn()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new InvalidArgumentException('That child is already on this trip.');
        throw $e;
    }
}

function transport_trip_remove_stop(int $tripStopId): void
{
    $stop = transport_trip_stop_get($tripStopId);
    if (!$stop) throw new InvalidArgumentException('Stop not found.');
    $trip = transport_trip_get((int)$stop['trip_id']);
    if (!$trip) throw new InvalidArgumentException('Trip not found.');
    transport_trip_require_editable($trip);
    if ($stop['status'] !== 'pending') throw new InvalidArgumentException('Only a child still waiting can be taken off the trip.');
    db()->prepare('DELETE FROM transport_trip_stops WHERE id = :id')->execute([':id' => $tripStopId]);
    transport_renumber_trip_stops((int)$trip['id']);
    if ($trip['status'] === 'running') {
        $left = db()->prepare("SELECT COUNT(*) FROM transport_trip_stops WHERE trip_id = :t AND status = 'pending'");
        $left->execute([':t' => (int)$trip['id']]);
        if ((int)$left->fetchColumn() === 0) transport_trip_finish((int)$trip['id']);
    }
}

/** Enrolled children for the "add a child" picker. */
function transport_student_choices(string $q, int $limit = 40): array
{
    $limit = max(1, min(50, $limit));
    $sql = "SELECT id, first_name, last_name, grade FROM students
            WHERE COALESCE(is_active, 1) = 1
              AND COALESCE(enrollment_status, 'enrolled') IN ('enrolled','promoted')";
    $params = [];
    $q = trim($q);
    if ($q !== '') {
        $sql .= " AND CONCAT(first_name, ' ', last_name) LIKE :q";
        $params[':q'] = '%' . str_replace(['%', '_'], '', $q) . '%';
    }
    $sql .= " ORDER BY (transport = 'cab') DESC, first_name, last_name LIMIT $limit";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
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
    $routeStops = transport_route_stops((int)$trip['route_id']);
    $legs = transport_trip_legs($routeStops, (string)$trip['direction']);

    $liveFirstLeg = null;
    $cab = transport_trip_live_position($trip);
    if ($cab !== null) {
        $coords = [];
        foreach ($routeStops as $rs) {
            if ($rs['lat'] !== null) $coords[(int)$rs['student_id']] = [(float)$rs['lat'], (float)$rs['lng']];
        }
        foreach ($tripStops as $s) {
            if ($s['status'] !== 'pending') continue;
            $c = $coords[(int)$s['student_id']] ?? null;
            if ($c !== null) $liveFirstLeg = transport_live_minutes(transport_distance_m($cab['lat'], $cab['lng'], $c[0], $c[1]));
            break;
        }
    }

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
        transport_route_leg_minutes((int)$trip['route_id'], (string)$trip['direction']),
        $liveFirstLeg
    );
}

// ---------- Live location (driver app) --------------------------------------

const TRANSPORT_LIVE_FRESH_SECONDS = 180;

/** Latest cab position if the phone reported within the last 3 minutes. */
function transport_trip_live_position(array $trip): ?array
{
    if (($trip['status'] ?? '') !== 'running' || empty($trip['last_location_at']) || $trip['last_lat'] === null) return null;
    $at = strtotime((string)$trip['last_location_at']);
    if ($at === false || time() - $at > TRANSPORT_LIVE_FRESH_SECONDS) return null;
    return ['lat' => (float)$trip['last_lat'], 'lng' => (float)$trip['last_lng'], 'at' => $at];
}

function transport_reached_radius_m(): float
{
    $v = (float)app_setting('transport_reached_radius_m', '80');
    return $v >= 20 && $v <= 500 ? $v : 80.0;
}

/**
 * Store GPS points from the driver's phone for a running trip. Points are
 * [lat, lng, accuracy?, speed?, heading?, t (unix ms)]. Updates the trip's
 * latest position and marks pending stops within the reached radius.
 * Returns ['accepted' => int, 'reached' => int[] trip_stop ids, 'status' => trip status].
 */
function transport_record_locations(int $tripId, array $points): array
{
    $trip = transport_trip_get($tripId);
    if (!$trip) throw new InvalidArgumentException('Trip not found.');
    if ($trip['status'] !== 'running') return ['accepted' => 0, 'reached' => [], 'status' => $trip['status']];

    $now = time();
    $clean = [];
    foreach (array_slice($points, 0, 500) as $p) {
        if (!is_array($p) || !isset($p['lat'], $p['lng'])) continue;
        $lat = (float)$p['lat'];
        $lng = (float)$p['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) continue;
        $t = isset($p['t']) ? (int)floor((float)$p['t'] / 1000) : $now;
        if ($t > $now + 60 || $t < $now - 6 * 3600) $t = $now;
        $acc = isset($p['accuracy']) ? (int)round((float)$p['accuracy']) : null;
        if ($acc !== null && $acc > 500) continue;
        $clean[] = [
            'lat' => $lat, 'lng' => $lng, 't' => $t,
            'acc' => $acc !== null ? max(0, min(65535, $acc)) : null,
            'spd' => isset($p['speed']) && (float)$p['speed'] >= 0 ? min(999.99, (float)$p['speed']) : null,
            'hdg' => isset($p['heading']) && (float)$p['heading'] >= 0 ? ((int)round((float)$p['heading'])) % 360 : null,
        ];
    }
    if (!$clean) return ['accepted' => 0, 'reached' => [], 'status' => 'running'];
    usort($clean, static fn($a, $b) => $a['t'] <=> $b['t']);

    $ins = db()->prepare('INSERT INTO transport_locations (trip_id, lat, lng, accuracy_m, speed_mps, heading, recorded_at)
                          VALUES (:t, :la, :lo, :a, :s, :h, :r)');
    foreach ($clean as $c) {
        $ins->execute([':t' => $tripId, ':la' => $c['lat'], ':lo' => $c['lng'], ':a' => $c['acc'],
                       ':s' => $c['spd'], ':h' => $c['hdg'], ':r' => date('Y-m-d H:i:s', $c['t'])]);
    }
    $last = end($clean);
    transport_after_locations($tripId, $last);
    db()->prepare('UPDATE transport_trips SET last_lat = :la, last_lng = :lo, last_location_at = :r
                   WHERE id = :id AND (last_location_at IS NULL OR last_location_at <= :r2)')
        ->execute([':la' => $last['lat'], ':lo' => $last['lng'], ':r' => date('Y-m-d H:i:s', $last['t']),
                   ':r2' => date('Y-m-d H:i:s', $last['t']), ':id' => $tripId]);

    $reached = [];
    $radius = transport_reached_radius_m();
    $coords = [];
    foreach (transport_route_stops((int)$trip['route_id']) as $rs) {
        if ($rs['lat'] !== null) $coords[(int)$rs['student_id']] = [(float)$rs['lat'], (float)$rs['lng']];
    }
    $mark = db()->prepare("UPDATE transport_trip_stops SET reached_at = :r WHERE id = :id AND status = 'pending' AND reached_at IS NULL");
    foreach (transport_trip_stops($tripId) as $s) {
        if ($s['status'] !== 'pending' || !empty($s['reached_at'])) continue;
        $c = $coords[(int)$s['student_id']] ?? null;
        if ($c === null) continue;
        foreach ($clean as $p) {
            if (transport_distance_m($p['lat'], $p['lng'], $c[0], $c[1]) <= $radius) {
                $mark->execute([':r' => date('Y-m-d H:i:s', $p['t']), ':id' => (int)$s['id']]);
                $reached[] = (int)$s['id'];
                break;
            }
        }
    }
    return ['accepted' => count($clean), 'reached' => $reached, 'status' => 'running'];
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
    $parts = [(string)$trip['status'], (string)($trip['route_id'] ?? '')];
    foreach ($tripStops as $s) {
        $parts[] = $s['id'] . ':' . $s['stop_order'] . ':' . $s['status'] . ':' . ($s['reached_at'] ? 1 : 0)
                 . ':' . ($s['eta_alert_at'] ? 1 : 0) . ':' . ($s['reached_alert_at'] ? 1 : 0);
    }
    $parts[] = implode(',', $due);
    if (!empty($trip['route_id'])) {
        $pins = [];
        foreach (transport_route_stops((int)$trip['route_id']) as $rs) {
            if ($rs['lat'] !== null) $pins[] = (int)$rs['student_id'] . ':' . $rs['lat'] . ',' . $rs['lng'];
        }
        $parts[] = implode(';', $pins);
    }
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
    foreach ($st as $t) {
        $key = (int)$t['route_id'] . '|' . $t['direction'];
        $prev = $trips[$key] ?? null;
        if ($prev === null
            || transport_trip_rank((string)$t['status']) > transport_trip_rank((string)$prev['status'])
            || (transport_trip_rank((string)$t['status']) === transport_trip_rank((string)$prev['status'])
                && (int)$t['id'] > (int)$prev['id'])) {
            $trips[$key] = $t;
        }
    }

    $out = [];
    foreach (transport_routes(true) as $r) {
        foreach (transport_route_directions($r) as $dir) {
            $out[] = ['route' => $r, 'direction' => $dir, 'trip' => $trips[(int)$r['id'] . '|' . $dir] ?? null];
        }
    }
    return $out;
}

/**
 * Every trip already created today, plus a row for each active route that
 * has no trip yet. can_start_another is true when that route has no run
 * still scheduled or on the road.
 */
function transport_today_runs(string $date): array
{
    $st = db()->prepare("SELECT t.*, r.name AS route_name, r.pickup_time, r.drop_time, r.cab_id,
                                c.name AS cab_name, c.vehicle_no, c.driver_name,
                                (SELECT COUNT(*) FROM transport_stops s WHERE s.route_id = r.id) AS stop_count,
                                (SELECT COUNT(*) FROM transport_trip_stops x WHERE x.trip_id = t.id) AS total,
                                (SELECT COUNT(*) FROM transport_trip_stops x WHERE x.trip_id = t.id AND x.status <> 'pending') AS finished
                           FROM transport_trips t
                           JOIN transport_routes r ON r.id = t.route_id
                           LEFT JOIN transport_cabs c ON c.id = r.cab_id
                          WHERE t.trip_date = :d
                          ORDER BY r.name, t.direction, t.run_no, t.id");
    $st->execute([':d' => $date]);
    $trips = $st->fetchAll();
    $open = [];
    $seen = [];
    foreach ($trips as $t) {
        $key = (int)$t['route_id'] . '|' . $t['direction'];
        $seen[$key] = true;
        if (in_array($t['status'], ['scheduled', 'running'], true)) $open[$key] = true;
    }
    $out = [];
    foreach ($trips as $t) {
        $key = (int)$t['route_id'] . '|' . $t['direction'];
        $out[] = [
            'route' => [
                'id' => (int)$t['route_id'],
                'name' => (string)$t['route_name'],
                'pickup_time' => $t['pickup_time'],
                'drop_time' => $t['drop_time'],
                'cab_name' => (string)($t['cab_name'] ?? ''),
                'stop_count' => (int)$t['stop_count'],
            ],
            'direction' => (string)$t['direction'],
            'trip' => $t,
            'can_start_another' => empty($open[$key]),
        ];
    }
    foreach (transport_routes(true) as $r) {
        foreach (transport_route_directions($r) as $dir) {
            $key = (int)$r['id'] . '|' . $dir;
            if (isset($seen[$key])) continue;
            $out[] = ['route' => $r, 'direction' => $dir, 'trip' => null, 'can_start_another' => true];
        }
    }
    return $out;
}

/** Trip counts for each day in a YYYY-MM month that already has a trip. */
function transport_calendar_month(string $month): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new InvalidArgumentException('Bad month.');
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $st = db()->prepare('SELECT trip_date, status, COUNT(*) AS n FROM transport_trips
                          WHERE trip_date BETWEEN :a AND :b GROUP BY trip_date, status');
    $st->execute([':a' => $start, ':b' => $end]);
    return ['month' => $month, 'start' => $start, 'end' => $end, 'days' => transport_calendar_fold($st->fetchAll())];
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
               'minutes' => null, 'stops_ahead' => null, 'time' => null, 'phrase' => '',
               'cab' => null, 'home' => null];
    if (!$row) {
        $status['phrase'] = 'No cab trip for today yet.';
        return transport_parent_finish($studentId, $date, null, $status);
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
        $cab = transport_trip_live_position($row);
        if ($cab !== null) {
            $status['cab'] = ['lat' => round($cab['lat'], 5), 'lng' => round($cab['lng'], 5), 'at' => date('c', $cab['at'])];
            $hs = db()->prepare('SELECT lat, lng FROM transport_stops WHERE route_id = :r AND student_id = :s AND lat IS NOT NULL');
            $hs->execute([':r' => (int)$row['route_id'], ':s' => $studentId]);
            if ($h = $hs->fetch()) $status['home'] = ['lat' => (float)$h['lat'], 'lng' => (float)$h['lng']];
        }
    }
    return transport_parent_finish($studentId, $date, $row, $status);
}

function transport_parent_finish(int $studentId, string $date, ?array $row, array $status): array
{
    return array_merge($status, transport_parent_extras($studentId, $date, $row));
}

require_once __DIR__ . '/transport_ops.php';
