<?php
/** Parent-facing assessment summary shared by the page, link and PDF. */
declare(strict_types=1);
require_once __DIR__ . '/child_report.php';

function parent_summary_data(array $d): array
{
    $months = $d['months'] ?? [];
    usort($months, 'compare_month_year');
    $month = $months ? end($months) : null;
    $skills = [];
    if ($month !== null) {
        foreach ($d['indicators'] ?? [] as $category => $items) {
            foreach ($items as $item) {
                $code = $item['ratings'][$month] ?? null;
                if ($code === null || $code === '') continue;
                $skills[$category][] = ['text' => $item['text'], 'code' => $code,
                    'label' => $d['ratings'][$code]['label'] ?? $code];
            }
        }
    }
    $comments = array_filter($d['comments'] ?? [], function ($byCategory) {
        foreach ($byCategory as $texts) foreach ($texts as $text) if (trim((string)$text) !== '') return true;
        return false;
    });
    uksort($comments, fn($a, $b) => compare_month_year($b, $a));
    $notesMonth = $comments ? array_key_first($comments) : null;
    return ['student' => $d['student'], 'month' => $month, 'skills' => $skills,
        'baseline' => $d['baseline'] ?? null, 'areas' => $month !== null ? ($d['catAvg'][$month] ?? []) : [], 'notes_month' => $notesMonth,
        'notes' => $notesMonth !== null ? $comments[$notesMonth] : []];
}

function parent_summary_baseline_fields(): array
{
    return ['gross_motor'=>'Gross motor', 'fine_motor'=>'Fine motor', 'literacy'=>'Literacy',
        'numeracy'=>'Numeracy', 'social_skills'=>'Social skills', 'communication'=>'Communication',
        'overall_notes'=>'Overall notes'];
}

function parent_summary_render(array $d): void
{
    $s = parent_summary_data($d); $child = $s['student'];
    $name = trim((string)$child['first_name'].' '.(string)$child['last_name']);
    ?>
    <article class="card parent-summary">
    <h1><?= e($name) ?></h1>
    <p><?= e((string)($child['grade'] ?? '')) ?><?php if ($s['month']): ?> · Assessment: <?= e(month_year_label($s['month'])) ?><?php endif; ?></p>
    <?php if (!$s['month']): ?><p>No monthly assessment recorded yet.</p><?php endif; ?>
    <?php if ($s['baseline']): $fields = parent_summary_baseline_fields(); $baseline = $s['baseline']; ?>
    <section><h2>First assessment</h2>
    <?php if (!empty($baseline['recorded_at'])): ?><p>Recorded <?= e(date('j M Y', strtotime((string)$baseline['recorded_at']))) ?></p><?php endif; ?>
    <?php foreach ($fields as $key => $label): if (trim((string)($baseline[$key] ?? '')) === '') continue; ?>
    <p><strong><?= e($label) ?>:</strong> <?= nl2br(e((string)$baseline[$key])) ?></p>
    <?php endforeach; ?></section><?php endif; ?>
    <?php if ($s['areas']): ?><section><h2>Assessment areas</h2><ul><?php foreach ($s['areas'] as $area => $average): ?><li><?= e((string)$area) ?>: <?= e(number_format((float)$average, 1)) ?> average</li><?php endforeach; ?></ul></section><?php endif; ?>
    <?php if ($s['skills']): ?><section><h2>Latest assessment · <?= e(month_year_label($s['month'])) ?></h2>
    <?php foreach ($s['skills'] as $category => $items): ?><h3><?= e((string)$category) ?></h3><ul>
    <?php foreach ($items as $item): ?><li><?= e((string)$item['text']) ?> — <?= e((string)$item['label']) ?> (<?= e((string)$item['code']) ?>)</li><?php endforeach; ?>
    </ul><?php endforeach; ?></section><?php endif; ?>
    <?php if ($s['notes']): ?><section><h2>Latest teacher notes · <?= e(month_year_label($s['notes_month'])) ?></h2>
    <?php foreach ($s['notes'] as $category => $texts): foreach ($texts as $text): if (trim((string)$text) === '') continue; ?>
    <p><strong><?= e($category === '' ? 'Overall' : (string)$category) ?>:</strong> <?= nl2br(e((string)$text)) ?></p>
    <?php endforeach; endforeach; ?></section><?php endif; ?>
    </article><?php
}

function parent_summary_pdf_bytes(array $d, string $schoolName = ''): string
{
    require_once __DIR__ . '/child_report_pdf.php';
    $s = parent_summary_data($d); $child = $s['student'];
    $name = trim((string)$child['first_name'].' '.(string)$child['last_name']);
    $pdf = new ChildReportPdf('P','mm','A4');
    $pdf->schoolName = $schoolName !== '' ? $schoolName : 'Progress report';
    $pdf->childName = $name; $pdf->SetTitle($name.' - parent summary');
    $pdf->SetMargins(14,12,14); $pdf->SetAutoPageBreak(true,18); $pdf->AliasNbPages(); $pdf->AddPage();
    $pdf->SetFont('Helvetica','B',18); $pdf->Cell(0,9,pdf_txt($name),0,1);
    $pdf->para((string)($child['grade'] ?? '').($s['month'] ? ' | Assessment: '.month_year_label($s['month']) : ' | No monthly assessment recorded yet.'), 10);
    if ($s['baseline']) {
        $rows = []; foreach (parent_summary_baseline_fields() as $key=>$label) {
            $value = trim((string)($s['baseline'][$key] ?? '')); if ($value !== '') $rows[$label] = $value;
        }
        if ($rows) { $pdf->sectionTitle('First assessment');
            if (!empty($s['baseline']['recorded_at'])) $pdf->muted('Recorded '.date('j M Y', strtotime((string)$s['baseline']['recorded_at'])));
            $pdf->facts($rows); }
    }
    if ($s['areas']) { $pdf->sectionTitle('Assessment areas');
        foreach ($s['areas'] as $area => $average) $pdf->para($area.': '.number_format((float)$average, 1).' average', 9); }
    if ($s['skills']) {
        $pdf->sectionTitle('Latest assessment - '.month_year_label($s['month']));
        foreach ($s['skills'] as $category=>$items) { $pdf->subTitle((string)$category);
            foreach ($items as $item) $pdf->para($item['text'].' - '.$item['label'].' ('.$item['code'].')', 9); }
    }
    if ($s['notes']) {
        $pdf->sectionTitle('Latest teacher notes - '.month_year_label($s['notes_month']));
        foreach ($s['notes'] as $category=>$texts) foreach ($texts as $text) {
            if (trim((string)$text) !== '') $pdf->para(($category === '' ? 'Overall' : $category).': '.$text);
        }
    }
    return (string)$pdf->Output('S');
}

function parent_summary_pdf_stream(array $d, string $schoolName = ''): void
{
    $bytes = parent_summary_pdf_bytes($d, $schoolName);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Length: '.strlen($bytes));
    header('Content-Disposition: attachment; filename="'.child_report_pdf_filename($d).'"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $bytes; exit;
}
