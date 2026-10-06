<?php
/**
 * Transport pure helpers — ETA maths, drop-order legs, WhatsApp links and
 * parent-facing wording.
 *
 * Run: php tests/transport_test.php
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

$failed = 0;
$passed = 0;

function expect_same($got, $want, string $name): void
{
    global $failed, $passed;
    if ($got === $want) {
        $passed++;
        echo "ok  $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name\n";
    echo "     got  " . var_export($got, true) . "\n";
    echo "     want " . var_export($want, true) . "\n";
}

// Phone digits + wa.me
expect_same(transport_phone_digits('98450 12345'), '919845012345', '10-digit number gets 91');
expect_same(transport_phone_digits('09845012345'), '919845012345', 'leading 0 becomes 91');
expect_same(transport_phone_digits('+91 98450-12345'), '919845012345', 'already international');
expect_same(transport_phone_digits('12345'), '', 'too short is unusable');
expect_same(transport_wa_link('', 'x'), '', 'no link without a phone');
expect_same(transport_wa_link('9845012345', 'Hi & bye'), 'https://wa.me/919845012345?text=Hi%20%26%20bye', 'text is url-encoded');

// Alert wording
expect_same(
    transport_alert_text('eta', 'pickup', 'Asha', 'Riya', ''),
    "Hi Asha, the school cab will reach Riya's pickup point in about 5 minutes.",
    'pickup eta text'
);
expect_same(
    transport_alert_text('reached', 'drop', '', 'Riya', 'https://x/t'),
    'Hi, the school cab has reached your stop with Riya. Track: https://x/t',
    'drop reached text with link'
);

// Parent wording rounds up and never gives an exact minute count
expect_same(transport_eta_phrase(0), 'arriving any moment', 'eta 0');
expect_same(transport_eta_phrase(4), 'about 5 minutes away', 'eta 4');
expect_same(transport_eta_phrase(11), 'about 15 minutes away', 'eta 11 rounds up to 15');

// Typical leg from history
expect_same(transport_typical_leg_minutes([[0, 300]]), null, 'too few gaps → null');
expect_same(transport_typical_leg_minutes([[0, 240, 480, 900]]), 4.0, 'median of 4,4,7 min');
expect_same(transport_typical_leg_minutes([[0, 6000, 6060, 6120, 6180]]), 1.0, 'outlier ignored, clamped to ≥1');
expect_same(transport_leg_minutes_from_distance(0), 1, 'a zero-length leg stays at 1 minute');
expect_same(transport_leg_minutes_from_distance(1000), 5, '1 km of town driving is about 5 minutes');

// ETA: trip started 3 min ago, default 5 min/stop
$now = strtotime('2026-10-01 08:00:00');
$started = '2026-10-01 07:57:00';
$stops = [
    ['id' => 1, 'status' => 'pending', 'done_at' => null, 'reached_at' => null, 'leg' => null],
    ['id' => 2, 'status' => 'pending', 'done_at' => null, 'reached_at' => null, 'leg' => 8],
    ['id' => 3, 'status' => 'pending', 'done_at' => null, 'reached_at' => null, 'leg' => null],
];
$etas = transport_compute_etas($stops, $started, $now, 5.0);
expect_same($etas[1], ['minutes' => 2, 'stops_ahead' => 0, 'reached' => false], 'first stop: 5 − 3 elapsed');
expect_same($etas[2]['minutes'], 10, 'second stop adds its Maps leg (8)');
expect_same($etas[3], ['minutes' => 15, 'stops_ahead' => 2, 'reached' => false], 'third stop adds default leg');
expect_same(transport_due_eta_alerts([
    ['id' => 1, 'eta_alert_at' => null], ['id' => 2, 'eta_alert_at' => null], ['id' => 3, 'eta_alert_at' => null],
], $etas), [1], 'only stops ≤5 min are due');
expect_same(transport_due_eta_alerts([['id' => 1, 'eta_alert_at' => '2026-10-01 07:59:00']], $etas), [], 'already alerted is not due');

// Progress measured from the last finished stop
$stops[0] = ['id' => 1, 'status' => 'done', 'done_at' => '2026-10-01 07:59:00', 'reached_at' => '2026-10-01 07:58:00', 'leg' => null];
$etas = transport_compute_etas($stops, $started, $now, 5.0);
expect_same(isset($etas[1]), false, 'done stops have no ETA');
expect_same($etas[2], ['minutes' => 7, 'stops_ahead' => 0, 'reached' => false], 'next stop: 8 − 1 since last done');

// Reached stop shows 0 and reached
$stops[1]['reached_at'] = '2026-10-01 07:59:30';
$etas = transport_compute_etas($stops, $started, $now, 5.0);
expect_same($etas[2], ['minutes' => 0, 'stops_ahead' => 0, 'reached' => true], 'cab at stop');
expect_same($etas[3]['minutes'], 5, 'following stop counts from reached stop');

expect_same(transport_compute_etas($stops, null, $now, 5.0), [], 'not started → no ETAs');

// Drop runs the route in reverse; legs shift accordingly
$route = [
    ['student_id' => 10, 'stop_order' => 1, 'leg_minutes' => null],
    ['student_id' => 11, 'stop_order' => 2, 'leg_minutes' => 6],
    ['student_id' => 12, 'stop_order' => 3, 'leg_minutes' => 9],
];
expect_same(transport_trip_legs($route, 'pickup'), [10 => null, 11 => 6.0, 12 => 9.0], 'pickup legs as stored');
expect_same(transport_trip_legs($route, 'drop'), [10 => 6.0, 11 => 9.0, 12 => null], 'drop legs come from the next pickup stop');

// Live location
expect_same((int)round(transport_distance_m(12.9716, 77.5946, 12.9816, 77.5946)), 1112, '0.01° latitude ≈ 1.11 km');
expect_same(round(transport_live_minutes(1000), 2), 4.67, '1 km straight line ≈ 4.7 min by road');
$etas = transport_compute_etas([
    ['id' => 1, 'status' => 'pending', 'done_at' => null, 'reached_at' => null, 'leg' => null],
    ['id' => 2, 'status' => 'pending', 'done_at' => null, 'reached_at' => null, 'leg' => null],
], '2026-10-01 07:00:00', strtotime('2026-10-01 08:00:00'), 5.0, 2.2);
expect_same([$etas[1]['minutes'], $etas[2]['minutes']], [3, 8], 'live first leg replaces the elapsed-time guess');

expect_same(transport_valid_date('2026-10-05'), '2026-10-05', 'iso date passes');
$badDate = false;
try { transport_valid_date('2026-02-31'); } catch (InvalidArgumentException $e) { $badDate = true; }
expect_same($badDate, true, '31 Feb is rejected');
$folded = transport_calendar_fold([
    ['trip_date' => '2026-10-05', 'status' => 'running', 'n' => 1],
    ['trip_date' => '2026-10-05', 'status' => 'completed', 'n' => 2],
    ['trip_date' => '2026-10-02', 'status' => 'cancelled', 'n' => 1],
]);
expect_same($folded[0]['date'], '2026-10-02', 'calendar days are sorted');
expect_same($folded[0]['cancelled'], 1, 'cancelled count is kept');
expect_same([$folded[1]['running'], $folded[1]['completed']], [1, 2], 'same day counts fold together');

expect_same(transport_trip_rank('running'), 3, 'a running trip outranks a finished one');
expect_same(transport_trip_rank('scheduled') > transport_trip_rank('completed'), true, 'a new run replaces a completed one on the board');

expect_same(transport_speed_kmh(10.0), 36, '10 m/s is 36 km/h');
expect_same(transport_speed_kmh(null), null, 'no speed stays empty');
expect_same(transport_started_on_time('2026-10-05 07:32:00', '07:30', 15), true, '2 minutes late is still on time');
expect_same(transport_started_on_time('2026-10-05 08:00:00', '07:30', 15), false, '30 minutes late is not on time');
expect_same(transport_is_late_start('2026-10-05', '07:30', 'scheduled', strtotime('2026-10-05 07:45:00')), true, '15 minutes past the clock is late');
expect_same(transport_is_late_start('2026-10-05', '07:30', 'running', strtotime('2026-10-05 08:00:00')), false, 'a running trip is not a late start');
expect_same(transport_week_bounds('2026-10-05'), ['2026-10-05', '2026-10-11'], 'Monday starts the week');
expect_same(transport_week_bounds('2026-10-01'), ['2026-09-28', '2026-10-04'], 'Thursday belongs to the previous Monday');
expect_same(transport_absence_directions('both'), ['pickup', 'drop'], 'both covers pickup and drop');
expect_same(transport_absence_label('morning'), 'Not riding this morning', 'morning notice label');
expect_same(transport_child_desk_status('running', 'pending', false), 'en_route', 'pending on a live trip is on the way');
expect_same(transport_child_desk_status('scheduled', 'pending', true), 'absent', 'a family notice wins over waiting');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
