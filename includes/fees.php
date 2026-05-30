<?php
/**
 * includes/fees.php — shared fee-structure helper.
 *
 * Reads fee amounts from app_settings (admin-editable via /fees/config.php).
 * Falls back to the 2026-27 Parent Fee Guide defaults when settings aren't
 * present. Used by both the public fee calculator and the parent fee guide.
 */
declare(strict_types=1);

function fee_setting(string $key, string $default): string
{
    return (string)app_setting('fee_' . $key, $default);
}

function fee_int(string $key, int $default): int
{
    return (int)fee_setting($key, (string)$default);
}

function fee_structure(): array
{
    $admission = [
        ['name' => 'Registration / Admission Fee', 'amount' => fee_int('admission_fee', 7500)],
        ['name' => 'Yearly Starter Kit',           'amount' => fee_int('starter_kit', 6500)],
        ['name' => 'Annual Resource Renewal',       'amount' => fee_int('resource_renewal', 5000)],
    ];

    $schoolFeeMonthly = fee_int('school_fee_monthly', 7900);
    $monthlyBilling   = fee_int('monthly_billing', 300);
    $weeklyRate       = fee_int('weekly_rate', 1975);
    $quarterlyRate    = fee_int('quarterly_rate', 22800);
    $ukgReadiness     = fee_int('ukg_readiness', 1500);
    $lateFee          = fee_int('late_fee', 500);
    $graceDays        = fee_int('grace_days', 7);

    $carePlans = [
        'none'       => ['label' => 'No extra care (half day)',  'monthly' => 0],
        'rest'       => ['label' => 'Rest Care (stay for nap)',  'monthly' => fee_int('care_rest', 1500)],
        'enrichment' => ['label' => 'Enrichment (till 3:30 PM)', 'monthly' => fee_int('care_enrichment', 3800)],
        'fullday'    => ['label' => 'Full Day (till 5:00 PM)',   'monthly' => fee_int('care_fullday', 5500)],
    ];

    $grades = [
        'playgroup' => ['label' => 'Playgroup (1.5-2.5 yrs)', 'ukg' => false],
        'nursery'   => ['label' => 'Nursery (2.5-3.5 yrs)',   'ukg' => false],
        'lkg'       => ['label' => 'LKG (3.5-4.5 yrs)',       'ukg' => false],
        'ukg'       => ['label' => 'UKG (4.5-5.5 yrs)',       'ukg' => true],
    ];

    $paymentDueDay = fee_int('payment_due_day', 5);
    $academicStart = fee_setting('academic_start_month', '6'); // June
    $academicMonths = fee_int('academic_months', 12);

    return compact(
        'admission', 'schoolFeeMonthly', 'monthlyBilling', 'weeklyRate',
        'quarterlyRate', 'ukgReadiness', 'lateFee', 'graceDays',
        'carePlans', 'grades', 'paymentDueDay', 'academicStart', 'academicMonths'
    );
}

/**
 * Active students for the picker dropdown in /fees/guide.php and
 * /fees/cofee_enroll.php. Returns rows with the data needed to
 * auto-fill the form when one is selected (name, grade, joining date,
 * primary parent name + phone + email).
 */
function fee_student_options(): array
{
    try {
        return db()->query("
            SELECT s.id, s.first_name, s.last_name, s.grade, s.joining_date,
                   s.academic_year,
                   (SELECT p.name  FROM student_parents p WHERE p.student_id = s.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS parent_name,
                   (SELECT p.phone FROM student_parents p WHERE p.student_id = s.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS parent_phone,
                   (SELECT p.email FROM student_parents p WHERE p.student_id = s.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS parent_email
            FROM students s
            WHERE COALESCE(s.is_active, 1) = 1
              AND COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'
            ORDER BY s.first_name, s.last_name
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Load a single student with their primary parent, used to auto-fill
 * the fee guide / CoFee wizard when ?student_id=X is passed.
 */
function fee_student_lookup(int $studentId): ?array
{
    if ($studentId <= 0) return null;
    try {
        $stmt = db()->prepare("
            SELECT s.id, s.first_name, s.last_name, s.grade, s.joining_date,
                   (SELECT p.name  FROM student_parents p WHERE p.student_id = s.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS parent_name,
                   (SELECT p.phone FROM student_parents p WHERE p.student_id = s.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS parent_phone,
                   (SELECT p.email FROM student_parents p WHERE p.student_id = s.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS parent_email
            FROM students s
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Map student grade (Playgroup / Nursery / LKG / UKG) to fees grade code. */
function fee_grade_from_student(string $grade): string
{
    $g = strtolower(trim($grade));
    if (in_array($g, ['playgroup', 'nursery', 'lkg', 'ukg'], true)) return $g;
    return '';
}

function fee_inr(int $v): string
{
    return "\u{20B9}" . number_format($v);
}

/**
 * Build a full payment schedule from joining date to end of academic year.
 * Returns an array of rows, each: [due_date, description, amount, is_first].
 */
function fee_payment_schedule(array $fs, string $joinDate, string $frequency, string $care, bool $isUkg): array
{
    $monthlyTotal = $fs['schoolFeeMonthly'] + $fs['monthlyBilling'];
    $admTotal     = array_sum(array_column($fs['admission'], 'amount'));
    $careMonthly  = $fs['carePlans'][$care]['monthly'] ?? 0;
    if ($careMonthly > 0) $careMonthly += $fs['monthlyBilling'];
    $ukgMonthly   = $isUkg ? $fs['ukgReadiness'] : 0;
    $dueDay       = $fs['paymentDueDay'];

    try {
        $joinDt = new DateTime($joinDate);
    } catch (Throwable $e) {
        return [];
    }

    $joinYear  = (int)$joinDt->format('Y');
    $joinMonth = (int)$joinDt->format('n');
    $joinDay   = (int)$joinDt->format('j');

    // Academic year: from join month to 12 months later.
    $endDt = (clone $joinDt)->modify('+12 months');
    $endDt->modify('last day of previous month');

    $schedule = [];
    $runningTotal = 0;

    // ---- Row 1: At joining — admission + first month (possibly pro-rated) ----
    $prorate = fee_prorate($monthlyTotal, $joinDate);
    $firstSchoolFee = $prorate['is_partial'] ? $prorate['prorated'] : $monthlyTotal;
    $firstCare = 0;
    $firstUkg  = 0;
    if ($careMonthly > 0) {
        $firstCare = $prorate['is_partial']
            ? (int)round($careMonthly * $prorate['days_remaining'] / $prorate['days_in_month'])
            : $careMonthly;
    }
    if ($ukgMonthly > 0) {
        $firstUkg = $prorate['is_partial']
            ? (int)round($ukgMonthly * $prorate['days_remaining'] / $prorate['days_in_month'])
            : $ukgMonthly;
    }

    $firstTotal = $admTotal + $firstSchoolFee + $firstCare + $firstUkg;
    $runningTotal += $firstTotal;

    $firstDesc = "Admission fees (" . fee_inr($admTotal) . ")";
    if ($prorate['is_partial']) {
        $firstDesc .= "\n+ School fee pro-rated " . $prorate['days_remaining'] . "/" . $prorate['days_in_month'] . " days (" . fee_inr($firstSchoolFee) . ")";
    } else {
        $firstDesc .= "\n+ School fee — " . $joinDt->format('F') . " (" . fee_inr($firstSchoolFee) . ")";
    }
    if ($firstCare > 0) {
        $firstDesc .= "\n+ Care plan" . ($prorate['is_partial'] ? ' (pro-rated)' : '') . " (" . fee_inr($firstCare) . ")";
    }
    if ($firstUkg > 0) {
        $firstDesc .= "\n+ UKG Readiness" . ($prorate['is_partial'] ? ' (pro-rated)' : '') . " (" . fee_inr($firstUkg) . ")";
    }

    $schedule[] = [
        'due_date'    => $joinDt->format('Y-m-d'),
        'due_label'   => 'At joining (' . $joinDt->format('j M Y') . ')',
        'description' => $firstDesc,
        'amount'      => $firstTotal,
        'running'     => $runningTotal,
        'is_first'    => true,
    ];

    // ---- Subsequent months: from the month AFTER joining ----
    if ($frequency === 'monthly' || $frequency === 'quarterly') {
        $intervalMonths = ($frequency === 'quarterly') ? 3 : 1;
        $cur = (clone $joinDt)->modify('first day of next month');

        while ($cur <= $endDt) {
            $dueDateStr = $cur->format('Y') . '-' . $cur->format('m') . '-' . str_pad((string)$dueDay, 2, '0', STR_PAD_LEFT);
            $monthLabel = $cur->format('F Y');

            if ($frequency === 'quarterly') {
                $qEnd = (clone $cur)->modify('+2 months');
                $monthLabel = $cur->format('M') . ' – ' . $qEnd->format('M Y');
                $amt = $fs['quarterlyRate'];
                $desc = "School fee — $monthLabel";
                if ($careMonthly > 0) {
                    $careQ = $careMonthly * 3;
                    $amt += $careQ;
                    $desc .= "\n+ Care plan 3 months (" . fee_inr($careQ) . ")";
                }
                if ($ukgMonthly > 0) {
                    $ukgQ = $ukgMonthly * 3;
                    $amt += $ukgQ;
                    $desc .= "\n+ UKG Readiness 3 months (" . fee_inr($ukgQ) . ")";
                }
            } else {
                $amt = $monthlyTotal;
                $desc = "School fee — $monthLabel";
                if ($careMonthly > 0) {
                    $amt += $careMonthly;
                    $desc .= "\n+ Care plan (" . fee_inr($careMonthly) . ")";
                }
                if ($ukgMonthly > 0) {
                    $amt += $ukgMonthly;
                    $desc .= "\n+ UKG Readiness (" . fee_inr($ukgMonthly) . ")";
                }
            }

            $runningTotal += $amt;
            $schedule[] = [
                'due_date'    => $dueDateStr,
                'due_label'   => 'Before ' . date('j M Y', strtotime($dueDateStr)),
                'description' => $desc,
                'amount'      => $amt,
                'running'     => $runningTotal,
                'is_first'    => false,
            ];

            $cur->modify("+{$intervalMonths} months");
        }
    } elseif ($frequency === 'weekly') {
        $cur = (clone $joinDt)->modify('next monday');
        $weekNum = 1;
        while ($cur <= $endDt && $weekNum <= 52) {
            $amt = $fs['weeklyRate'];
            $desc = "School fee — Week $weekNum (" . $cur->format('j M') . ")";
            // Care + UKG are monthly; show them on the first week of each month.
            $isFirstWeekOfMonth = ((int)$cur->format('j') <= 7);
            if ($isFirstWeekOfMonth && $careMonthly > 0) {
                $amt += $careMonthly;
                $desc .= "\n+ Care plan — " . $cur->format('F') . " (" . fee_inr($careMonthly) . ")";
            }
            if ($isFirstWeekOfMonth && $ukgMonthly > 0) {
                $amt += $ukgMonthly;
                $desc .= "\n+ UKG Readiness — " . $cur->format('F') . " (" . fee_inr($ukgMonthly) . ")";
            }

            $runningTotal += $amt;
            $schedule[] = [
                'due_date'    => $cur->format('Y-m-d'),
                'due_label'   => $cur->format('l, j M Y'),
                'description' => $desc,
                'amount'      => $amt,
                'running'     => $runningTotal,
                'is_first'    => false,
            ];
            $cur->modify('+1 week');
            $weekNum++;
        }
    }

    return $schedule;
}

/**
 * Calculate pro-rated first-month fee based on joining date.
 * Uses calendar-day basis: fee * days_remaining / days_in_month.
 */
function fee_prorate(int $monthlyFee, string $joinDate): array
{
    try {
        $dt = new DateTime($joinDate);
    } catch (Throwable $e) {
        return ['full' => $monthlyFee, 'prorated' => $monthlyFee, 'days_remaining' => 0, 'days_in_month' => 0, 'is_partial' => false];
    }
    $dayOfMonth  = (int)$dt->format('j');
    $daysInMonth = (int)$dt->format('t');
    $daysRemaining = $daysInMonth - $dayOfMonth + 1;
    $isPartial = ($dayOfMonth > 1);
    $prorated = $isPartial ? (int)round($monthlyFee * $daysRemaining / $daysInMonth) : $monthlyFee;
    return [
        'full'           => $monthlyFee,
        'prorated'       => $prorated,
        'days_remaining' => $daysRemaining,
        'days_in_month'  => $daysInMonth,
        'is_partial'     => $isPartial,
    ];
}
