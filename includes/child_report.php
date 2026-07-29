<?php
/**
 * includes/child_report.php — the detailed per-child progress report.
 *
 * Two jobs:
 *   1. child_report_data()   — gather every scrap of assessment data we hold
 *                              for one child into a single structure.
 *   2. child_report_render() — render the report body. Shared by the
 *                              logged-in page (assessment/report.php) and the
 *                              public parent link (assessment/report_share.php)
 *                              so the two can never drift apart.
 *
 * Plus the share-token helpers (table: sql/migrate_049_student_report_tokens).
 *
 * RENDERING RULE: a section with no data is omitted entirely — no "none
 * recorded" placeholders. Every section below is wrapped in a has-data guard.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------

/**
 * Everything we know about one child's progress, or null when the child
 * doesn't exist. Safe to call with a pre-migration DB — missing tables degrade
 * to empty sections rather than fataling.
 */
function child_report_data(int $studentId): ?array
{
    $stmt = db()->prepare("
        SELECT s.*, t.name AS teacher_name
        FROM students s
        LEFT JOIN users t ON t.id = s.teacher_id
        WHERE s.id = :id
    ");
    $stmt->execute([':id' => $studentId]);
    $student = $stmt->fetch();
    if (!$student) return null;

    $d = [
        'student'    => $student,
        'baseline'   => null,
        'months'     => [],
        'categories' => [],
        'catAvg'     => [],   // [month][category] => float
        'monthAvg'   => [],   // [month] => float (across categories)
        'indicators' => [],   // [category][] => ['text'=>, 'ratings'=>[month=>code]]
        'comments'   => [],   // [month][category|''] => [text, …]
        'attendance' => [],
        'ratings'    => [],
    ];

    // Rating scheme (include retired codes so old data still renders).
    try { $d['ratings'] = rating_config_map_all(); } catch (Throwable $e) { $d['ratings'] = []; }

    // Entry baseline.
    try {
        $s = db()->prepare("SELECT * FROM student_baselines WHERE student_id = :s");
        $s->execute([':s' => $studentId]);
        $d['baseline'] = $s->fetch() ?: null;
    } catch (Throwable $e) {}

    // Per-category monthly averages.
    try {
        $s = db()->prepare("
            SELECT month_year, category, category_avg
            FROM assessments WHERE student_id = :s
        ");
        $s->execute([':s' => $studentId]);
        $months = $cats = [];
        foreach ($s->fetchAll() as $r) {
            $d['catAvg'][$r['month_year']][$r['category']] = (float)$r['category_avg'];
            $months[$r['month_year']] = true;
            $cats[$r['category']]     = true;
        }
        $d['months']     = array_keys($months);
        $d['categories'] = array_keys($cats);
        usort($d['months'], 'compare_month_year');
        sort($d['categories']);
    } catch (Throwable $e) {}

    // Overall average per month, across that month's categories.
    foreach ($d['catAvg'] as $m => $byCat) {
        if ($byCat) $d['monthAvg'][$m] = round(array_sum($byCat) / count($byCat), 2);
    }

    // Per-indicator ratings — the granular detail. Standard + custom in one go.
    try {
        $s = db()->prepare("
            SELECT ec.month_year, ec.rating, ec.indicator_id, ec.is_custom_indicator,
                   si.category, si.indicator_text, si.display_order
            FROM evaluation_cards ec
            JOIN skill_indicators si ON si.id = ec.indicator_id
            WHERE ec.student_id = :s AND ec.is_custom_indicator = 0
            UNION ALL
            SELECT ec.month_year, ec.rating, ec.indicator_id, ec.is_custom_indicator,
                   sci.category, sci.indicator_text, sci.display_order
            FROM evaluation_cards ec
            JOIN student_custom_indicators sci ON sci.id = ec.indicator_id
            WHERE ec.student_id = :s2 AND ec.is_custom_indicator = 1
        ");
        $s->execute([':s' => $studentId, ':s2' => $studentId]);

        $byKey = [];
        foreach ($s->fetchAll() as $r) {
            $key = ($r['is_custom_indicator'] ? 'c' : 's') . ':' . $r['indicator_id'];
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'category' => (string)$r['category'],
                    'text'     => (string)$r['indicator_text'],
                    'order'    => (int)$r['display_order'],
                    'custom'   => (bool)$r['is_custom_indicator'],
                    'ratings'  => [],
                ];
            }
            $byKey[$key]['ratings'][$r['month_year']] = (string)$r['rating'];
        }
        foreach ($byKey as $row) $d['indicators'][$row['category']][] = $row;
        foreach ($d['indicators'] as $cat => $list) {
            usort($list, fn($a, $b) => [$a['order'], $a['text']] <=> [$b['order'], $b['text']]);
            $d['indicators'][$cat] = $list;
        }
        ksort($d['indicators']);
    } catch (Throwable $e) {}

    // Teacher comments, per month → per category ('' = overall).
    try {
        $s = db()->prepare("
            SELECT month_year, category, comment
            FROM assessment_comments WHERE student_id = :s
            ORDER BY category IS NULL DESC, category, id
        ");
        $s->execute([':s' => $studentId]);
        foreach ($s->fetchAll() as $r) {
            $d['comments'][$r['month_year']][(string)($r['category'] ?? '')][] = (string)$r['comment'];
        }
        uksort($d['comments'], fn($a, $b) => compare_month_year($b, $a));
    } catch (Throwable $e) {}

    // Attendance tally.
    try {
        $s = db()->prepare("
            SELECT status, COUNT(*) n FROM attendance
            WHERE student_id = :s GROUP BY status
        ");
        $s->execute([':s' => $studentId]);
        foreach ($s->fetchAll() as $r) $d['attendance'][(string)$r['status']] = (int)$r['n'];
    } catch (Throwable $e) {}

    return $d;
}

/** Age in years+months on a given date, or '' when dob is unknown. */
function child_report_age(?string $dob): string
{
    if (!$dob) return '';
    try {
        $diff = (new DateTime('today'))->diff(new DateTime($dob));
        $y = (int)$diff->y; $m = (int)$diff->m;
        if ($y <= 0 && $m <= 0) return '';
        return trim(($y > 0 ? "$y yr" . ($y === 1 ? '' : 's') : '') . ' ' . ($m > 0 ? "$m mo" : ''));
    } catch (Throwable $e) { return ''; }
}

// ---------------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------------

/**
 * The rating actually awarded most often across a list of codes.
 *
 * Reporting the modal awarded rating keeps the summary in the school's own
 * vocabulary and never invents a level: rounding an average would have to
 * pick between codes that share a numeric value (this school has both
 * D "Developed" and M "Mastered" at 5). Ties break toward the higher
 * numeric value, then alphabetically, so the result is deterministic.
 */
function child_report_mode(array $codes, array $ratings): ?string
{
    $codes = array_filter($codes, fn($c) => (string)$c !== '');
    if (!$codes) return null;

    $counts = array_count_values(array_map('strval', $codes));
    $best = null; $bestCount = -1; $bestVal = -1;
    foreach ($counts as $code => $n) {
        $val = (int)($ratings[$code]['numeric_value'] ?? 0);
        if ($n > $bestCount
            || ($n === $bestCount && $val > $bestVal)
            || ($n === $bestCount && $val === $bestVal && strcmp((string)$code, (string)$best) < 0)) {
            $best = (string)$code; $bestCount = $n; $bestVal = $val;
        }
    }
    return $best;
}

/** Nearest rating code to a numeric average — the fallback when no per-indicator data exists. */
function child_report_code_for_value(float $value, array $ratings): ?string
{
    $best = null; $bestDiff = null;
    foreach ($ratings as $code => $cfg) {
        $diff = abs((float)$cfg['numeric_value'] - $value);
        if ($bestDiff === null || $diff < $bestDiff) { $bestDiff = $diff; $best = (string)$code; }
    }
    return $best;
}

/** Every rating code a child was given in one area in one month. */
function child_report_codes_for(array $d, string $category, string $month): array
{
    $out = [];
    foreach ($d['indicators'][$category] ?? [] as $ind) {
        if (isset($ind['ratings'][$month])) $out[] = (string)$ind['ratings'][$month];
    }
    return $out;
}

/**
 * The level to show for one area in one month: the modal awarded rating, or
 * the nearest code to the stored category average when the per-indicator rows
 * aren't there. Returns null when that cell has no data at all.
 */
function child_report_level(array $d, string $category, string $month): ?string
{
    $code = child_report_mode(child_report_codes_for($d, $category, $month), $d['ratings']);
    if ($code !== null) return $code;
    if (isset($d['catAvg'][$month][$category]) && $d['ratings']) {
        return child_report_code_for_value((float)$d['catAvg'][$month][$category], $d['ratings']);
    }
    return null;
}

/** Chip + label, as shown in the summary table. */
function child_report_level_cell(?string $code, array $ratings): string
{
    if ($code === null || $code === '') return '';
    $label = $ratings[$code]['label'] ?? $code;
    return child_report_chip($code, $ratings)
         . '<span class="cr-lvl">' . e((string)$label) . '</span>';
}

/** A coloured rating chip for one code. */
function child_report_chip(string $code, array $ratings): string
{
    if ($code === '') return '';
    $cfg   = $ratings[$code] ?? null;
    $color = $cfg['color'] ?? '#888';
    $label = $cfg['label'] ?? $code;
    return '<span class="cr-chip" title="' . e($label) . '" style="background:' . e($color) . ';">'
         . e($code) . '</span>';
}

/**
 * Inline SVG multi-line chart of category averages over months. Returns '' if
 * there's nothing to plot (so the caller can omit the whole section).
 */
function child_report_chart(array $d): string
{
    $months = $d['months'];
    $cats   = $d['categories'];
    if (count($months) < 2 || !$cats) return '';   // a single point isn't a trend

    $w = 720; $h = 260; $padL = 34; $padR = 12; $padT = 14; $padB = 42;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;
    $n = count($months);
    $x = fn(int $i) => $padL + ($n === 1 ? $plotW / 2 : $i * ($plotW / ($n - 1)));
    $y = fn(float $v) => $padT + $plotH - (max(1.0, min(5.0, $v)) - 1) / 4 * $plotH;

    $palette = ['#2D6BA0', '#5BA547', '#EC407A', '#F5B342', '#7E57C2', '#5DA8A2', '#E07A5F', '#A05C7B'];
    $svg  = '<svg class="cr-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Category averages over time">';

    // Gridlines, labelled with the school's rating codes rather than 1–5.
    $axis = [];
    foreach (($d['ratings'] ?? []) as $code => $cfg) {
        $v = (int)$cfg['numeric_value'];
        if ($v >= 1 && $v <= 5 && !isset($axis[$v])) $axis[$v] = (string)$code;
    }
    for ($v = 1; $v <= 5; $v++) {
        $gy = round($y((float)$v), 1);
        $svg .= '<line x1="' . $padL . '" y1="' . $gy . '" x2="' . ($w - $padR) . '" y2="' . $gy . '" stroke="#e3e3e3" stroke-width="1"/>';
        $svg .= '<text x="' . ($padL - 8) . '" y="' . ($gy + 4) . '" font-size="11" fill="#888" text-anchor="end">'
              . e($axis[$v] ?? '') . '</text>';
    }
    // Month labels.
    foreach ($months as $i => $m) {
        $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($h - $padB + 18) . '" font-size="11" fill="#666" text-anchor="middle" transform="rotate(-30 ' . round($x($i), 1) . ' ' . ($h - $padB + 18) . ')">'
              . e(month_year_label($m)) . '</text>';
    }
    // One polyline per category.
    foreach ($cats as $ci => $cat) {
        $color = $palette[$ci % count($palette)];
        $pts = [];
        foreach ($months as $i => $m) {
            if (!isset($d['catAvg'][$m][$cat])) continue;
            $pts[] = round($x($i), 1) . ',' . round($y((float)$d['catAvg'][$m][$cat]), 1);
        }
        if (count($pts) < 2) continue;
        $svg .= '<polyline fill="none" stroke="' . e($color) . '" stroke-width="2.5" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/>';
        foreach ($pts as $p) {
            [$px, $py] = explode(',', $p);
            $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="3.2" fill="' . e($color) . '"/>';
        }
    }
    $svg .= '</svg>';

    // Legend.
    $legend = '<div class="cr-legend">';
    foreach ($cats as $ci => $cat) {
        $legend .= '<span class="cr-legend-item"><i style="background:' . e($palette[$ci % count($palette)]) . '"></i>' . e($cat) . '</span>';
    }
    $legend .= '</div>';

    return $svg . $legend;
}

// ---------------------------------------------------------------------------
// The report body
// ---------------------------------------------------------------------------

/**
 * Render the full report. Every section is guarded — nothing with empty data
 * is printed at all.
 */
function child_report_render(array $d): void
{
    $s        = $d['student'];
    $ratings  = $d['ratings'];
    $fullName = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
    $months   = $d['months'];
    $age      = child_report_age($s['dob'] ?? null);

    // ---- Identity header -------------------------------------------------
    ?>
    <section class="cr-head">
        <div class="cr-head-main">
            <h1><?= e($fullName) ?></h1>
            <p class="cr-sub">
                <?php
                $bits = [];
                if (!empty($s['grade']))        $bits[] = e((string)$s['grade']);
                if ($age !== '')                $bits[] = e($age);
                if (!empty($s['teacher_name'])) $bits[] = 'Teacher: ' . e((string)$s['teacher_name']);
                echo implode(' &nbsp;·&nbsp; ', $bits);
                ?>
            </p>
            <p class="cr-sub cr-muted">
                Detailed progress report
                <?php if ($months): ?>
                    · <?= e(month_year_label($months[0])) ?> – <?= e(month_year_label($months[count($months) - 1])) ?>
                    · <?= count($months) ?> month<?= count($months) === 1 ? '' : 's' ?> assessed
                <?php endif; ?>
                · Generated <?= e(date('j M Y')) ?>
            </p>
        </div>
    </section>

    <?php
    // ---- Child details (only the fields that are filled) -----------------
    $facts = [];
    if (!empty($s['admission_number'])) $facts['Admission no.'] = (string)$s['admission_number'];
    if (!empty($s['dob']))              $facts['Date of birth'] = date('j M Y', strtotime((string)$s['dob']));
    if (!empty($s['gender']))           $facts['Gender']        = (string)$s['gender'];
    if (!empty($s['joining_date']))     $facts['Joined']        = date('j M Y', strtotime((string)$s['joining_date']));
    if (!empty($s['mother_tongue']))    $facts['Mother tongue'] = (string)$s['mother_tongue'];
    if ($facts): ?>
        <section class="cr-card">
            <h2>Child details</h2>
            <dl class="cr-facts">
                <?php foreach ($facts as $k => $v): ?>
                    <dt><?= e($k) ?></dt><dd><?= e($v) ?></dd>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endif; ?>

    <?php
    // ---- Attendance ------------------------------------------------------
    $att      = array_filter($d['attendance'], fn($n) => $n > 0);
    $attTotal = array_sum($att);
    if ($attTotal > 0):
        $present = (int)($att['present'] ?? 0) + (int)($att['late'] ?? 0);
        $marked  = $attTotal - (int)($att['holiday'] ?? 0);
        $pct     = $marked > 0 ? round($present / $marked * 100) : null;
    ?>
        <section class="cr-card">
            <h2>Attendance</h2>
            <dl class="cr-facts">
                <?php foreach ($att as $status => $n): ?>
                    <dt><?= e(ucfirst($status)) ?></dt><dd><?= (int)$n ?> day<?= $n === 1 ? '' : 's' ?></dd>
                <?php endforeach; ?>
                <?php if ($pct !== null): ?>
                    <dt>Attendance rate</dt><dd><strong><?= (int)$pct ?>%</strong></dd>
                <?php endif; ?>
            </dl>
        </section>
    <?php endif; ?>

    <?php
    // ---- Entry baseline --------------------------------------------------
    $bl = $d['baseline'];
    if ($bl):
        $blFields = [
            'gross_motor'   => 'Gross motor',
            'fine_motor'    => 'Fine motor',
            'literacy'      => 'Literacy',
            'numeracy'      => 'Numeracy',
            'social_skills' => 'Social skills',
            'communication' => 'Communication',
        ];
        $blRows = [];
        foreach ($blFields as $k => $label) {
            if (trim((string)($bl[$k] ?? '')) !== '') $blRows[$label] = (string)$bl[$k];
        }
        $blNotes = trim((string)($bl['overall_notes'] ?? ''));
        if ($blRows || $blNotes !== ''):
    ?>
        <section class="cr-card">
            <h2>Where <?= e((string)$s['first_name']) ?> started
                <?php if (!empty($bl['recorded_at'])): ?>
                    <span class="cr-muted cr-small">· baseline recorded <?= e(date('j M Y', strtotime((string)$bl['recorded_at']))) ?></span>
                <?php endif; ?>
            </h2>
            <?php if ($blRows): ?>
                <dl class="cr-facts cr-facts-wide">
                    <?php foreach ($blRows as $label => $text): ?>
                        <dt><?= e($label) ?></dt><dd><?= e($text) ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if ($blNotes !== ''): ?>
                <p class="cr-note"><?= e($blNotes) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; endif; ?>

    <?php
    // ---- Summary of category averages ------------------------------------
    if ($d['categories'] && $months):
        $lastMonth  = $months[count($months) - 1];
        $firstMonth = $months[0];
    ?>
        <section class="cr-card">
            <h2>Summary by area</h2>
            <div class="cr-scroll">
                <table class="cr-table">
                    <thead>
                        <tr>
                            <th>Area</th>
                            <?php foreach ($months as $m): ?><th><?= e(month_year_label($m)) ?></th><?php endforeach; ?>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($d['categories'] as $cat):
                            // Level per month, then compare the first and last month
                            // that this area actually has data for.
                            $levels = [];
                            foreach ($months as $m) $levels[$m] = child_report_level($d, $cat, $m);
                            $seen = array_values(array_filter($levels, fn($c) => $c !== null));
                            $firstCode = $seen ? $seen[0] : null;
                            $lastCode  = $seen ? $seen[count($seen) - 1] : null;
                            $moved = null;
                            if (count($seen) > 1) {
                                $moved = (int)($d['ratings'][$lastCode]['numeric_value'] ?? 0)
                                       <=> (int)($d['ratings'][$firstCode]['numeric_value'] ?? 0);
                            }
                        ?>
                            <tr>
                                <td class="cr-rowhead"><?= e($cat) ?></td>
                                <?php foreach ($months as $m): ?>
                                    <td><?= child_report_level_cell($levels[$m], $d['ratings']) ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if ($moved === 1): ?>
                                        <span class="cr-delta up">▲ Moved up</span>
                                    <?php elseif ($moved === -1): ?>
                                        <span class="cr-delta down">▼ Slipped</span>
                                    <?php elseif ($moved === 0): ?>
                                        <span class="cr-muted">Steady</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php
                        // Overall = the modal rating across every area that month.
                        $overallLevels = [];
                        foreach ($months as $m) {
                            $all = [];
                            foreach ($d['categories'] as $cat) {
                                $all = array_merge($all, child_report_codes_for($d, $cat, $m));
                            }
                            $overallLevels[$m] = child_report_mode($all, $d['ratings']);
                        }
                        if (array_filter($overallLevels, fn($c) => $c !== null)): ?>
                            <tr class="cr-total">
                                <td class="cr-rowhead">Overall</td>
                                <?php foreach ($months as $m): ?>
                                    <td><?= child_report_level_cell($overallLevels[$m], $d['ratings']) ?></td>
                                <?php endforeach; ?>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="cr-muted cr-small">Each area shows the level <?= e((string)$s['first_name']) ?> worked at most often that month — see the key at the end.</p>
        </section>
    <?php endif; ?>

    <?php
    // ---- Trend chart ------------------------------------------------------
    $chart = child_report_chart($d);
    if ($chart !== ''): ?>
        <section class="cr-card cr-break">
            <h2>Progress over time</h2>
            <?= $chart ?>
        </section>
    <?php endif; ?>

    <?php
    // ---- Per-indicator detail, grouped by area ---------------------------
    if ($d['indicators']): ?>
        <section class="cr-card cr-break">
            <h2>Skill-by-skill detail</h2>
            <p class="cr-muted cr-small">Every indicator assessed, month by month.</p>
        </section>
        <?php foreach ($d['indicators'] as $cat => $list):
            // Only the months in which this area was actually assessed.
            $catMonths = [];
            foreach ($months as $m) {
                foreach ($list as $ind) { if (isset($ind['ratings'][$m])) { $catMonths[] = $m; break; } }
            }
            if (!$catMonths) continue;
        ?>
            <section class="cr-card">
                <h3 class="cr-cat"><?= e($cat) ?></h3>
                <div class="cr-scroll">
                    <table class="cr-table cr-table-ind">
                        <thead>
                            <tr>
                                <th>Skill</th>
                                <?php foreach ($catMonths as $m): ?><th><?= e(month_year_label($m)) ?></th><?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($list as $ind): ?>
                                <tr>
                                    <td class="cr-rowhead">
                                        <?= e($ind['text']) ?>
                                        <?php if (!empty($ind['custom'])): ?><span class="cr-tag">custom</span><?php endif; ?>
                                    </td>
                                    <?php foreach ($catMonths as $m): ?>
                                        <td class="cr-cell"><?= child_report_chip((string)($ind['ratings'][$m] ?? ''), $ratings) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                // Teacher comments recorded against this area.
                $catComments = [];
                foreach ($d['comments'] as $m => $byCat) {
                    foreach (($byCat[$cat] ?? []) as $c) {
                        if (trim($c) !== '') $catComments[] = [$m, $c];
                    }
                }
                if ($catComments): ?>
                    <div class="cr-comments">
                        <?php foreach ($catComments as [$m, $c]): ?>
                            <p class="cr-comment"><span class="cr-when"><?= e(month_year_label($m)) ?></span><?= e($c) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    // ---- Overall teacher comments per month ------------------------------
    $overall = [];
    foreach ($d['comments'] as $m => $byCat) {
        foreach (($byCat[''] ?? []) as $c) if (trim($c) !== '') $overall[] = [$m, $c];
    }
    if ($overall): ?>
        <section class="cr-card cr-break">
            <h2>Teacher's remarks</h2>
            <?php foreach ($overall as [$m, $c]): ?>
                <p class="cr-comment"><span class="cr-when"><?= e(month_year_label($m)) ?></span><?= e($c) ?></p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php
    // ---- Rating key -------------------------------------------------------
    if ($ratings):
        $ordered = $ratings;
        uasort($ordered, fn($a, $b) => (int)$b['numeric_value'] <=> (int)$a['numeric_value']);
    ?>
        <section class="cr-card">
            <h2>How to read this report</h2>
            <ul class="cr-key">
                <?php foreach ($ordered as $code => $cfg): ?>
                    <li>
                        <span class="cr-chip" style="background:<?= e((string)$cfg['color']) ?>;"><?= e((string)$code) ?></span>
                        <strong><?= e((string)$cfg['label']) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif;
}

/** The stylesheet the report body needs. Inlined so the public page is self-contained. */
function child_report_styles(): string
{
    return <<<'CSS'
.cr-head { margin-bottom: 1rem; }
.cr-head h1 { margin: 0 0 .2rem; font-size: 1.7rem; }
.cr-sub { margin: .1rem 0; font-size: .95rem; }
.cr-muted { color: #6b6b6b; }
.cr-small { font-size: .82rem; }
.cr-card { background: #fff; border: 1px solid #e3d9c8; border-radius: 10px;
           padding: 1rem 1.15rem; margin-bottom: 1rem; }
.cr-card h2 { margin: 0 0 .7rem; font-size: 1.12rem; color: #ad1457;
              border-bottom: 1px dashed #f0cddc; padding-bottom: .35rem; }
.cr-cat { margin: 0 0 .6rem; font-size: 1rem; color: #2d6ba0; text-transform: uppercase; letter-spacing: .04em; }
.cr-facts { display: grid; grid-template-columns: auto 1fr; gap: .35rem 1rem; margin: 0; }
.cr-facts dt { color: #6b6b6b; font-size: .86rem; }
.cr-facts dd { margin: 0; font-size: .93rem; }
.cr-facts-wide dd { white-space: pre-wrap; }
.cr-note { white-space: pre-wrap; margin: .6rem 0 0; padding-top: .6rem; border-top: 1px solid #f0e6d6; }
.cr-scroll { overflow-x: auto; }
.cr-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.cr-table th, .cr-table td { padding: .4rem .5rem; text-align: center; border-bottom: 1px solid #efe6d8; }
.cr-table thead th { background: #faf4ea; font-size: .78rem; text-transform: uppercase;
                     letter-spacing: .04em; color: #7a6a55; white-space: nowrap; }
.cr-table .cr-rowhead { text-align: left; font-weight: 500; }
.cr-table-ind .cr-rowhead { min-width: 220px; }
.cr-total td { background: #faf4ea; }
.cr-cell { white-space: nowrap; }
.cr-chip { display: inline-block; min-width: 1.5rem; padding: .1rem .4rem; border-radius: 999px;
           color: #fff; font-size: .75rem; font-weight: 700; text-align: center; }
.cr-tag { display: inline-block; margin-left: .35rem; padding: 0 .35rem; border-radius: 4px;
          background: #eee; color: #666; font-size: .68rem; text-transform: uppercase; }
.cr-lvl { display: inline-block; margin-left: .35rem; font-size: .84rem; white-space: nowrap; }
.cr-delta.up { color: #2c7a2c; font-weight: 600; }
.cr-delta.down { color: #b3402c; font-weight: 600; }
.cr-chart { width: 100%; height: auto; }
.cr-legend { display: flex; flex-wrap: wrap; gap: .35rem .9rem; margin-top: .5rem; font-size: .8rem; color: #555; }
.cr-legend-item { display: inline-flex; align-items: center; gap: .3rem; }
.cr-legend-item i { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }
.cr-comments { margin-top: .8rem; padding-top: .6rem; border-top: 1px solid #f0e6d6; }
.cr-comment { margin: .3rem 0; white-space: pre-wrap; font-size: .92rem; }
.cr-when { display: inline-block; min-width: 5.2rem; color: #ad1457; font-weight: 600; font-size: .82rem; }
.cr-key { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem 1.4rem; }
.cr-key li { display: inline-flex; align-items: center; gap: .4rem; font-size: .88rem; }
@media print {
  .cr-card { break-inside: avoid; border-color: #ccc; }
  .cr-break { break-before: page; }
  .cr-scroll { overflow-x: visible; }
}
CSS;
}

// ---------------------------------------------------------------------------
// Share tokens (public parent link)
// ---------------------------------------------------------------------------

function child_report_generate_token(int $studentId, int $byUserId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare("
        INSERT INTO student_report_tokens (student_id, token, created_by_user_id)
        VALUES (:s, :t, :by)
    ")->execute([':s' => $studentId, ':t' => $token, ':by' => $byUserId]);
    return $token;
}

function child_report_revoke_token(int $tokenId): void
{
    db()->prepare("UPDATE student_report_tokens SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL")
        ->execute([':id' => $tokenId]);
}

function child_report_revoke_active(int $studentId): void
{
    db()->prepare("UPDATE student_report_tokens SET revoked_at = NOW() WHERE student_id = :s AND revoked_at IS NULL")
        ->execute([':s' => $studentId]);
}

function child_report_active_token(int $studentId): ?array
{
    try {
        $s = db()->prepare("
            SELECT * FROM student_report_tokens
            WHERE student_id = :s AND revoked_at IS NULL
            ORDER BY created_at DESC, id DESC LIMIT 1
        ");
        $s->execute([':s' => $studentId]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) {
        return null;   // pre-migration DB
    }
}

/** Public-side lookup. Returns the token row (incl. student_id) or null. */
function child_report_by_token(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return null;
    try {
        $s = db()->prepare("SELECT * FROM student_report_tokens WHERE token = :t LIMIT 1");
        $s->execute([':t' => $token]);
        $row = $s->fetch();
        if (!$row) return null;
        if ($row['revoked_at'] !== null) return null;
        if (!hash_equals((string)$row['token'], $token)) return null;
        db()->prepare("
            UPDATE student_report_tokens
            SET last_accessed_at = NOW(), view_count = view_count + 1
            WHERE id = :id
        ")->execute([':id' => (int)$row['id']]);
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/** Absolute public URL for a report token. */
function child_report_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . '/assessment/report_share.php?token=' . $token;
}
