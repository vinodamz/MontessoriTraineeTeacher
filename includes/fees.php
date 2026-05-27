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

    return compact(
        'admission', 'schoolFeeMonthly', 'monthlyBilling', 'weeklyRate',
        'quarterlyRate', 'ukgReadiness', 'lateFee', 'graceDays',
        'carePlans', 'grades'
    );
}

function fee_inr(int $v): string
{
    return "\u{20B9}" . number_format($v);
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
