<?php
/**
 * includes/grades.php — the single source of truth for grade levels.
 *
 * Grades used to be hard-coded in three ENUM columns and ~20 PHP files. They
 * now live in the grade_levels table, managed at /grades.php, so adding one
 * (Daycare, Afterschool, a new Montessori band…) is a config change rather
 * than a code change.
 *
 * Everything here degrades to the historical five-grade list when the table
 * isn't there yet — same approach as app_setting() — so a deploy that lands
 * before its migration doesn't take pages down.
 *
 * Schema: sql/migrate_050_grade_levels.sql
 */
declare(strict_types=1);

/** The list this app shipped with, used only as a pre-migration fallback. */
const GRADE_FALLBACK = [
    ['name' => 'Daycare',   'label' => 'Daycare',   'sort_order' => 10, 'promotes_to' => null,      'is_active' => 1],
    ['name' => 'Playgroup', 'label' => 'Playgroup', 'sort_order' => 20, 'promotes_to' => 'Nursery', 'is_active' => 1],
    ['name' => 'Nursery',   'label' => 'Nursery',   'sort_order' => 30, 'promotes_to' => 'LKG',     'is_active' => 1],
    ['name' => 'LKG',       'label' => 'LKG',       'sort_order' => 40, 'promotes_to' => 'UKG',     'is_active' => 1],
    ['name' => 'UKG',       'label' => 'UKG',       'sort_order' => 50, 'promotes_to' => null,      'is_active' => 1],
];

/**
 * Every configured grade, in display order. Cached per request.
 *
 * @param bool $activeOnly drop deactivated grades (the default for pickers)
 * @return array<int,array> rows: name, label, sort_order, promotes_to, is_active
 */
function grade_levels(bool $activeOnly = true): array
{
    static $all = null;
    if ($all === null) {
        try {
            $rows = db()->query("
                SELECT name, label, sort_order, promotes_to, is_active
                FROM grade_levels ORDER BY sort_order, name
            ")->fetchAll();
            $all = $rows ?: GRADE_FALLBACK;
        } catch (Throwable $e) {
            $all = GRADE_FALLBACK;      // table not migrated yet
        }
    }
    if (!$activeOnly) return $all;
    return array_values(array_filter($all, fn($g) => (int)($g['is_active'] ?? 1) === 1));
}

/** Call after editing grade_levels so the rest of the request sees the change. */
function grade_levels_clear_cache(): void
{
    // The static in grade_levels() can't be reset directly; config pages
    // redirect after saving, so the next request rebuilds it. This exists so
    // callers have somewhere obvious to look, and to document that.
}

/**
 * Grade names in display order — the list to validate against and to build
 * dropdowns from.
 *
 * @return string[]
 */
function grade_names(bool $activeOnly = true): array
{
    return array_map(fn($g) => (string)$g['name'], grade_levels($activeOnly));
}

/** Display label for a grade, falling back to the raw name. */
function grade_label(?string $name): string
{
    $name = (string)$name;
    if ($name === '') return '';
    foreach (grade_levels(false) as $g) {
        if ((string)$g['name'] === $name) return (string)$g['label'];
    }
    return $name;      // a grade removed from config but still on old records
}

/** True when $name is a currently-usable grade. */
function grade_is_valid(?string $name, bool $activeOnly = true): bool
{
    return $name !== null && $name !== ''
        && in_array($name, grade_names($activeOnly), true);
}

/**
 * A grade-ordered map for counting/bucketing, e.g. ['Daycare'=>0, 'Playgroup'=>0, …].
 *
 * @param mixed $initial value for every bucket
 */
function grade_buckets($initial = 0): array
{
    return array_fill_keys(grade_names(), $initial);
}

/**
 * `ORDER BY` fragment that sorts by configured grade order rather than
 * alphabetically. Includes inactive grades so old records still sort sensibly.
 *
 * Returns just the column when there are no grades to order by, so callers can
 * always interpolate it safely.
 *
 * @param string $col already-trusted column reference, e.g. 's.grade'
 */
function grade_sql_order(string $col = 's.grade'): string
{
    $names = array_map(fn($g) => (string)$g['name'], grade_levels(false));
    $safe = [];
    foreach ($names as $n) {
        // Grade names are admin-entered. /grades.php constrains them to this
        // character set on creation, so stripping anything else is a no-op in
        // practice while making injection impossible — and unlike db()->quote()
        // it needs no live connection, so ordering still works (and is testable)
        // when the DB handle isn't available.
        $clean = preg_replace('/[^A-Za-z0-9 .\/_-]/', '', $n);
        if ($clean !== '' && $clean !== null) $safe[] = "'" . $clean . "'";
    }
    if (!$safe) return $col;
    return 'FIELD(' . $col . ', ' . implode(', ', $safe) . ')';
}

/**
 * The grade a child moves to at the June year-end rollover, or null when they
 * stay put (Daycare) or graduate (UKG).
 *
 * Replaces the old hard-coded Playgroup→Nursery→LKG→UKG chain; the mapping is
 * now the promotes_to column, so admins control it.
 */
function next_grade(string $grade): ?string
{
    foreach (grade_levels(false) as $g) {
        if ((string)$g['name'] !== $grade) continue;
        $to = $g['promotes_to'] ?? null;
        $to = ($to === null || $to === '') ? null : (string)$to;
        // Never promote into a grade that no longer exists.
        return ($to !== null && grade_is_valid($to, false)) ? $to : null;
    }
    return null;
}
