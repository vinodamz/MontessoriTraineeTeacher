<?php
/**
 * Credit one paid leave day to every active staff member for each month
 * that is due, through today.
 *
 * Safe to run every day, including more than once on the 1st: a month that
 * already has a credit is left alone, and a month the 1st-of-the-month run
 * missed is filled in. Inactive staff are skipped.
 *
 * The same catch-up also runs when someone opens leave, My Day, or a
 * payslip, so a month is still credited if this script is not on a schedule.
 *
 * cPanel cron, daily, is enough:
 *   php /home/USER/thelittlegraduates.in/mtt/scripts/leave_monthly_credit.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$added = staff_leave_sync_credits();
echo "Monthly leave credit catch-up finished. New credits written: {$added}\n";
