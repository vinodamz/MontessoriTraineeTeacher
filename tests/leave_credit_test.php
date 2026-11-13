<?php
/**
 * Monthly leave credit: one day on the 1st for active staff, carry forward,
 * and loss of pay for days the balance does not cover.
 *
 * Run: php tests/leave_credit_test.php
 *
 * The arithmetic tests always run. The database tests run when MySQL is
 * reachable as mtt/mtt@127.0.0.1/mtt_leave_test (or LEAVE_TEST_DSN).
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../includes/staff.php';

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

function line(float $days, bool $draws, int $id = 1): array
{
    return ['id' => $id, 'type' => $draws ? 'casual' : 'unpaid', 'from' => '2026-11-03', 'to' => '2026-11-05', 'days' => $days, 'draws' => $draws];
}

// Which 1sts are credited.
expect_same(
    staff_leave_credit_months('2026-11-01', '2026-10-31', '2026-11-13'),
    ['2026-11-01'],
    'this month is credited when today is after the 1st'
);
expect_same(
    staff_leave_credit_months('2026-11-01', '2026-10-31', '2027-01-15'),
    ['2026-11-01', '2026-12-01', '2027-01-01'],
    'missed months are all still due'
);
expect_same(
    staff_leave_credit_months('2026-11-01', '2026-11-01', '2026-12-20'),
    ['2026-12-01'],
    'an opening on the 1st already includes that month'
);
expect_same(
    staff_leave_credit_months('2026-11-01', '2026-11-14', '2026-11-13'),
    [],
    'someone who joins after the 1st waits until next month'
);
expect_same(
    staff_leave_credit_months('2026-11-01', '2026-10-31', '2026-12-02'),
    ['2026-11-01', '2026-12-01'],
    'a joiner whose first day is the 1st is credited that month'
);
expect_same(
    staff_leave_credit_months('2026-12-01', '2026-10-31', '2026-11-13'),
    [],
    'nothing is credited before the rule starts'
);

// Paid days versus loss of pay. The owner's examples:
// no carry, 3 taken -> 1 paid, 2 loss of pay.
// 1 carried + this month's 1, 3 taken -> 2 paid, 1 loss of pay.
$noCarry = staff_leave_allocate(1.0, [line(3.0, true)]);
expect_same($noCarry['paid_days'], 1.0, 'no carry: 3 leave days use the 1 monthly credit');
expect_same($noCarry['lop_days'], 2.0, 'no carry: the other 2 days are loss of pay');
expect_same($noCarry['closing_balance'], 0.0, 'no carry: the paid day uses up the balance');

$carried = staff_leave_allocate(2.0, [line(3.0, true)]);
expect_same($carried['paid_days'], 2.0, '1 carried + 1 new: 2 of 3 days are paid');
expect_same($carried['lop_days'], 1.0, '1 carried + 1 new: 1 day is loss of pay');
expect_same($carried['closing_balance'], 0.0, 'carry forward is used before loss of pay');

$short = staff_leave_allocate(0.0, [line(3.0, true)]);
expect_same([$short['paid_days'], $short['lop_days']], [0.0, 3.0], 'a zero balance pays nothing');

$hole = staff_leave_allocate(-1.0 + 1.0, [line(3.0, true)]);
expect_same([$hole['paid_days'], $hole['lop_days']], [0.0, 3.0], 'a shortfall eats this month credit before any day is paid');

$unpaid = staff_leave_allocate(2.0, [line(3.0, false)]);
expect_same($unpaid['paid_days'], 0.0, 'unpaid leave is never paid');
expect_same($unpaid['lop_days'], 3.0, 'unpaid leave is all loss of pay');
expect_same($unpaid['closing_balance'], 2.0, 'unpaid leave does not reduce the balance');

$ordered = staff_leave_allocate(3.0, [line(2.0, true, 1), line(2.0, true, 2)]);
expect_same($ordered['lines'][0]['paid'], 2.0, 'the earlier request is covered first');
expect_same($ordered['lines'][1]['paid'], 1.0, 'the later request gets what is left');
expect_same($ordered['lines'][1]['lop'], 1.0, 'only the uncovered part of the later request is loss of pay');

$half = staff_leave_allocate(1.0, [line(0.5, true)]);
expect_same([$half['paid_days'], $half['closing_balance']], [0.5, 0.5], 'a half day uses half a day of balance');

echo "\n$passed passed, $failed failed (rules)\n";

$dsn = getenv('LEAVE_TEST_DSN');
if ($dsn === false || $dsn === '') {
    $dsn = 'mysql:host=127.0.0.1;dbname=mtt_leave_test;charset=utf8mb4';
}
$user = getenv('LEAVE_TEST_USER');
$pass = getenv('LEAVE_TEST_PASSWORD');
if ($user === false || $user === '') $user = 'mtt';
if ($pass === false) $pass = 'mtt';

try {
    $probe = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbName = (string)$probe->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName !== 'mtt_leave_test') {
        echo "skip database tests (connected to '$dbName', not mtt_leave_test)\n";
        exit($failed > 0 ? 1 : 0);
    }
} catch (Throwable $e) {
    echo "skip database tests (" . $e->getMessage() . ")\n";
    exit($failed > 0 ? 1 : 0);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'staff_payslips', 'staff_pay', 'staff_attendance', 'staff_leave_credits',
    'staff_leave_requests', 'staff_leave_ledger', 'app_settings', 'users',
] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pdo->exec("
    CREATE TABLE users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        pin_hash VARCHAR(255) NOT NULL DEFAULT '',
        role VARCHAR(32) NOT NULL,
        modules VARCHAR(255) NOT NULL DEFAULT '',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_name (name)
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TABLE app_settings (
        setting_key VARCHAR(60) NOT NULL,
        setting_value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TABLE staff_leave_ledger (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        entry_date DATE NOT NULL,
        kind ENUM('opening','adjust') NOT NULL DEFAULT 'adjust',
        days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        note VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_sll_user (user_id, entry_date)
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TABLE staff_leave_requests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        leave_type ENUM('casual','sick','earned','unpaid','other') NOT NULL DEFAULT 'casual',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        half_day ENUM('','first','second') NOT NULL DEFAULT '',
        days_count DECIMAL(5,1) NOT NULL,
        lop_days DECIMAL(5,1) NOT NULL DEFAULT 0.0,
        reason TEXT NULL,
        status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
        decided_by INT UNSIGNED NULL,
        decided_at DATETIME NULL,
        decision_note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TABLE staff_leave_credits (
        user_id INT UNSIGNED NOT NULL,
        credit_month DATE NOT NULL,
        days DECIMAL(6,2) NOT NULL DEFAULT 1.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, credit_month)
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TABLE staff_attendance (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        att_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL,
        check_in TIME NULL,
        check_out TIME NULL,
        notes VARCHAR(255) NULL,
        marked_by INT UNSIGNED NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TABLE staff_pay (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        effective_from DATE NOT NULL,
        basic DECIMAL(10,2) NOT NULL DEFAULT 0,
        hra DECIMAL(10,2) NOT NULL DEFAULT 0,
        conveyance DECIMAL(10,2) NOT NULL DEFAULT 0,
        special_allowance DECIMAL(10,2) NOT NULL DEFAULT 0,
        other_earning DECIMAL(10,2) NOT NULL DEFAULT 0,
        pf DECIMAL(10,2) NOT NULL DEFAULT 0,
        esi DECIMAL(10,2) NOT NULL DEFAULT 0,
        professional_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
        tds DECIMAL(10,2) NOT NULL DEFAULT 0,
        other_deduction DECIMAL(10,2) NOT NULL DEFAULT 0,
        payable_days_basis TINYINT UNSIGNED NOT NULL DEFAULT 30,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB
");

function add_user(string $name, string $role, int $active, string $created): int
{
    $s = db()->prepare(
        "INSERT INTO users (name, role, modules, active, created_at) VALUES (:n, :r, 'staff', :a, :c)"
    );
    $s->execute([':n' => $name, ':r' => $role, ':a' => $active, ':c' => $created . ' 09:00:00']);
    return (int)db()->lastInsertId();
}

staff_leave_setting_put('staff_leave_accrual_per_month', '1');
staff_leave_setting_put('staff_leave_credit_start', '2026-11-01');
app_setting_clear_cache();
staff_leave_sync_reset();

$activeId = add_user('Asha Active', 'teacher', 1, '2026-01-15');
$inactiveId = add_user('Bina Inactive', 'teacher', 0, '2026-01-15');
$midMonthId = add_user('Chaya Midmonth', 'teacher', 1, '2026-11-10');
$firstId = add_user('Diya First', 'teacher', 1, '2026-11-01');

$added = staff_leave_sync_credits();
expect_same($added >= 1, true, 'catch-up writes the November credit for active staff');

$asha = staff_leave_balance_at($activeId, '2026-11-13');
expect_same($asha['opening_seed'], true, 'existing staff keep a starting balance from the day before the rule');
expect_same($asha['opening'], 9.0, 'starting balance is the old completed-month figure through 31 Oct');
expect_same($asha['this_month_credit'], 1.0, 'active staff receive November credit on top of that');
expect_same($asha['balance'], 10.0, 'existing balance plus this month credit');

$bina = staff_leave_balance_at($inactiveId, '2026-11-13');
expect_same($bina['opening'], 9.0, 'inactive staff keep what they had already earned');
expect_same($bina['accrued'], 0.0, 'inactive staff receive no monthly credit');
expect_same($bina['balance'], 9.0, 'inactive balance does not grow');

$chaya = staff_leave_balance_at($midMonthId, '2026-11-13');
expect_same($chaya['from_open'], false, 'someone who joins after the change is not given a made-up history');
expect_same($chaya['balance'], 0.0, 'a mid-month joiner waits until the next 1st');

$diya = staff_leave_balance_at($firstId, '2026-11-13');
expect_same($diya['balance'], 1.0, 'joining on the 1st earns that month credit');

staff_leave_sync_reset();
$again = staff_leave_sync_credits();
expect_same($again, 0, 'running the catch-up again writes nothing new');
$creditCount = (int)db()->query(
    "SELECT COUNT(*) FROM staff_leave_credits WHERE user_id = $activeId"
)->fetchColumn();
expect_same($creditCount, 1, 'November is still credited exactly once');

// Missed month: drop the row, catch up, it comes back. Drop it while inactive, it stays missing.
db()->prepare('DELETE FROM staff_leave_credits WHERE user_id = :u')->execute([':u' => $activeId]);
db()->prepare('UPDATE users SET active = 0 WHERE id = :u')->execute([':u' => $activeId]);
staff_leave_sync_reset();
staff_leave_sync_credits();
$whileInactive = (int)db()->query(
    "SELECT COUNT(*) FROM staff_leave_credits WHERE user_id = $activeId"
)->fetchColumn();
expect_same($whileInactive, 0, 'a missed month is not credited after the person has become inactive');

db()->prepare('UPDATE users SET active = 1 WHERE id = :u')->execute([':u' => $activeId]);
staff_leave_sync_reset();
staff_leave_sync_credits();
$caughtUp = (int)db()->query(
    "SELECT COUNT(*) FROM staff_leave_credits WHERE user_id = $activeId"
)->fetchColumn();
expect_same($caughtUp, 1, 'the same missed month is filled once they are active again');

// Owner's payslip examples, with an explicit carried balance of 0 and of 1.
$withCarry = add_user('Meera Iyer', 'teacher', 1, '2026-06-01');
$noCarryUser = add_user('Nila Rao', 'teacher', 1, '2026-06-01');
staff_leave_ledger_add($withCarry, '2026-10-31', 'opening', 1, 'Unused leave carried forward', null);
staff_leave_ledger_add($noCarryUser, '2026-10-31', 'opening', 0, 'No leave carried forward', null);
staff_leave_sync_reset();
staff_leave_sync_credits();

db()->prepare(
    "INSERT INTO staff_leave_requests
        (user_id, leave_type, start_date, end_date, days_count, status)
     VALUES (:u, 'casual', '2026-11-04', '2026-11-06', 3, 'approved')"
)->execute([':u' => $withCarry]);
db()->prepare(
    "INSERT INTO staff_leave_requests
        (user_id, leave_type, start_date, end_date, days_count, status)
     VALUES (:u, 'casual', '2026-11-04', '2026-11-06', 3, 'approved')"
)->execute([':u' => $noCarryUser]);

$splitCarry = staff_leave_month_split($withCarry, 2026, 11);
expect_same($splitCarry['opening_balance'], 1.0, 'payslip carry is last month unused leave');
expect_same($splitCarry['credit_days'], 1.0, 'payslip includes the day added on the 1st');
expect_same($splitCarry['paid_days'], 2.0, '1 carried and 1 new cover 2 of 3 leave days');
expect_same($splitCarry['lop_days'], 1.0, 'the third leave day is loss of pay');

// A fresh page view rewrites the paid / loss-of-pay split, then the balance
// counts only the paid days.
staff_leave_sync_reset();
$afterLeave = staff_leave_balance_at($withCarry, '2026-11-13');
expect_same($afterLeave['taken'], 2.0, 'only the paid days reduce the balance');
expect_same($afterLeave['balance'], 0.0, '1 carried + 1 new − 2 paid = 0 left');

$splitNone = staff_leave_month_split($noCarryUser, 2026, 11);
expect_same([$splitNone['paid_days'], $splitNone['lop_days']], [1.0, 2.0], 'no carry: 1 paid and 2 loss of pay');

db()->prepare(
    "INSERT INTO staff_pay (user_id, effective_from, basic, payable_days_basis)
     VALUES (:u, '2026-04-01', 30000, 30)"
)->execute([':u' => $withCarry]);
$draft = staff_payslip_draft($withCarry, 2026, 11);
expect_same($draft['paid_leave_days'], 2.0, 'payslip draft shows 2 paid leave days');
expect_same($draft['lop_leave_days'], 1.0, 'payslip draft shows 1 loss-of-pay leave day');
expect_same($draft['leave_carry_days'], 1.0, 'payslip draft shows the day carried in');
expect_same($draft['leave_credit_days'], 1.0, 'payslip draft shows the day added on the 1st');
expect_same($draft['lop_amount'], 1000.0, 'one loss-of-pay day deducts one day of a 30,000 salary');
expect_same($draft['net_pay'], 29000.0, 'net pay is salary minus that deduction');

$storedLop = (float)db()->query(
    "SELECT lop_days FROM staff_leave_requests WHERE user_id = $withCarry"
)->fetchColumn();
expect_same($storedLop, 1.0, 'the leave request records the one loss-of-pay day');

echo "\n$passed passed, $failed failed (total)\n";
exit($failed > 0 ? 1 : 0);
