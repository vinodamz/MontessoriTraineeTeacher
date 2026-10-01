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

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
