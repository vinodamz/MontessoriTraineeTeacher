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
    $areas = [];
    foreach (array_unique(array_merge(array_keys($d['catAvg'][$month ?? ''] ?? []), array_keys($skills))) as $area) {
        $code = child_report_level($d, $area, $month);
        if ($code !== null) $areas[$area] = $d['ratings'][$code]['label'] ?? $code;
    }
    return ['student' => $d['student'], 'month' => $month, 'skills' => $skills,
        'baseline' => $d['baseline'] ?? null, 'areas' => $areas, 'notes_month' => $notesMonth,
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
    <style>
.parent-summary{background:#fff;border:1px solid #dfe8d4;border-top:6px solid #bc2070;border-radius:18px;padding:32px;color:#263c2c;max-width:850px;margin:24px auto;font:15px/1.65 Arial,sans-serif}
.parent-summary .report-brand{display:flex;align-items:center;gap:24px;border-bottom:2px solid #b4d553;padding-bottom:20px;margin-bottom:24px}
.parent-summary .report-brand img{width:112px;height:auto}.parent-summary .report-brand strong{font-size:23px;color:#315b36;display:block}.parent-summary .report-brand span{color:#a51b62;letter-spacing:1.3px;font-size:12px;text-transform:uppercase}
.parent-summary h1{font-size:30px;color:#315b36;margin:0}.parent-summary h2{font-size:19px;color:#a51b62;border-bottom:1px solid #e5ebdf;padding-bottom:8px;margin-top:28px}.parent-summary h3{font-size:15px;color:#315b36;margin-bottom:8px}
.parent-summary section{margin:24px 0}.parent-summary ul{list-style:none;padding:0}.parent-summary li{padding:10px 12px;border-bottom:1px solid #edf0e9}.parent-summary li:nth-child(odd){background:#f5f8ef}.parent-summary .area-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.parent-summary .area{padding:16px;background:#f2f7e7;border-radius:10px}.parent-summary .area strong{display:block;font-size:12px;letter-spacing:.6px}.parent-summary .area span{font-size:18px;color:#315b36}.parent-summary .report-foot{border-top:2px solid #b4d553;padding-top:14px;font-size:12px;color:#61715e}
.parent-summary .report-brand{background:#f2f7e7;border:0;border-radius:18px;padding:24px;margin:-8px -8px 28px}.parent-summary .report-brand p{font-family:Georgia,serif;font-size:23px;line-height:1.25;margin-bottom:0}.parent-summary .child-banner{padding:22px 26px;border-left:5px solid #bc2070;background:#fff5fa;border-radius:0 14px 14px 0}.parent-summary .child-banner p{margin-bottom:0}.parent-summary section{padding:20px;border:1px solid #e4eadd;border-radius:14px}.parent-summary section h2{margin-top:0}.parent-summary .area{border-left:4px solid #9fbd48;padding:20px}.parent-summary .area:nth-child(3n+2){background:#fff0f6;border-left-color:#bc2070}.parent-summary .area:nth-child(3n){background:#edf6fa;border-left-color:#5396b5}.parent-summary .area span{display:inline-block;background:#fff;border-radius:20px;padding:4px 12px;margin-top:10px;font-size:15px;font-weight:bold}.parent-summary .skill-line{display:flex;justify-content:space-between;gap:20px;align-items:start}.parent-summary .skill-level{white-space:normal;min-width:105px;font-weight:bold;font-size:12px;background:#e8f0d9;padding:3px 10px;border-radius:14px;text-align:center}.parent-summary .notes-panel{background:#fffbef;border-color:#efe1b6}.parent-summary .section-caption{font-size:13px;color:#61715e}
@media(max-width:550px){.parent-summary{padding:18px}.parent-summary .report-brand{gap:12px}.parent-summary .report-brand img{width:80px}.parent-summary .report-brand strong{font-size:18px}.parent-summary .area-grid{grid-template-columns:1fr}}
@media print{.parent-summary{border:0;padding:0;margin:0;max-width:none}.parent-summary .area,.parent-summary li,.parent-summary .report-brand{break-inside:avoid}.parent-summary h2,.parent-summary h3{break-after:avoid}}
</style>
<article class="parent-summary">
    <div class="report-brand"><img src="/assets/img/parent-report-logo.png" alt="The Little Graduates"><div><strong>The Little Graduates</strong><span>Early Learning Centre</span><p>Learning &amp; development summary</p></div></div>
    <div class="child-banner"><h1><?= e($name) ?></h1>
    <p><?= e((string)($child['grade'] ?? '')) ?><?php if ($s['month']): ?> · Assessment: <?= e(month_year_label($s['month'])) ?><?php endif; ?></p></div>
    <?php if (!$s['month']): ?><p>No monthly assessment recorded yet.</p><?php endif; ?>
    <?php if ($s['baseline']): $fields = parent_summary_baseline_fields(); $baseline = $s['baseline']; ?>
    <section><h2>First assessment</h2>
    <?php if (!empty($baseline['recorded_at'])): ?><p>Recorded <?= e(date('j M Y', strtotime((string)$baseline['recorded_at']))) ?></p><?php endif; ?>
    <?php foreach ($fields as $key => $label): if (trim((string)($baseline[$key] ?? '')) === '') continue; ?>
    <p><strong><?= e($label) ?>:</strong> <?= nl2br(e((string)$baseline[$key])) ?></p>
    <?php endforeach; ?></section><?php endif; ?>
    <?php if ($s['areas']): ?><section><h2>Learning areas</h2><div class="area-grid"><?php foreach ($s['areas'] as $area => $label): ?><div class="area"><strong><?= e((string)$area) ?></strong><span><?= e((string)$label) ?></span></div><?php endforeach; ?></div></section><?php endif; ?>
    <?php if ($s['skills']): ?><section><h2>Latest assessment · <?= e(month_year_label($s['month'])) ?></h2>
    <?php foreach ($s['skills'] as $category => $items): ?><h3><?= e((string)$category) ?></h3><ul>
    <?php foreach ($items as $item): ?><li class="skill-line"><span><?= e((string)$item['text']) ?></span><span class="skill-level"><?= e((string)$item['label']) ?></span></li><?php endforeach; ?>
    </ul><?php endforeach; ?></section><?php endif; ?>
    <?php if ($s['notes']): ?><section class="notes-panel"><h2>Latest teacher notes · <?= e(month_year_label($s['notes_month'])) ?></h2>
    <?php foreach ($s['notes'] as $category => $texts): foreach ($texts as $text): if (trim((string)$text) === '') continue; ?>
    <p><strong><?= e($category === '' ? 'Overall' : (string)$category) ?>:</strong> <?= nl2br(e((string)$text)) ?></p>
    <?php endforeach; endforeach; ?></section><?php endif; ?>
    <p class="report-foot">The Little Graduates · Growing with curiosity, learning with care.</p></article><?php
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
    $coverY = $pdf->GetY();
    $pdf->SetFillColor(242,247,231); $pdf->Rect(14,$coverY,182,39,'F');
    $pdf->SetY($coverY+4);
    $pdf->Image(__DIR__.'/../assets/img/parent-report-logo.png',14,$pdf->GetY(),30);
    $pdf->SetX(50); $pdf->SetFont('Helvetica','B',19); $pdf->SetTextColor(49,91,54);
    $pdf->Cell(0,9,'The Little Graduates',0,1);
    $pdf->SetX(50); $pdf->SetFont('Helvetica','',11); $pdf->Cell(0,7,'Learning & development summary',0,1);
    $pdf->SetY($coverY+44);
    $pdf->SetFillColor(255,240,246); $pdf->Rect(14,$pdf->GetY(),182,23,'F');
    $pdf->SetFillColor(188,32,112); $pdf->Rect(14,$pdf->GetY(),2,23,'F');
    $pdf->SetXY(19,$pdf->GetY()+2);
    $pdf->SetFont('Helvetica','B',18); $pdf->Cell(0,9,pdf_txt($name),0,1);
    $pdf->para((string)($child['grade'] ?? '').($s['month'] ? ' | Assessment: '.month_year_label($s['month']) : ' | No monthly assessment recorded yet.'), 10);
    $pdf->SetY(max($pdf->GetY()+4, $coverY+71));
    if ($s['baseline']) {
        $rows = []; foreach (parent_summary_baseline_fields() as $key=>$label) {
            $value = trim((string)($s['baseline'][$key] ?? '')); if ($value !== '') $rows[$label] = $value;
        }
        if ($rows) { $pdf->sectionTitle('First assessment');
            if (!empty($s['baseline']['recorded_at'])) $pdf->muted('Recorded '.date('j M Y', strtotime((string)$s['baseline']['recorded_at'])));
            $pdf->facts($rows); }
    }
    if ($s['areas']) { $pdf->sectionTitle('Learning at a glance');
        $i=0;
        foreach ($s['areas'] as $area => $label) {
            $pdf->need(22); $y=$pdf->GetY();
            $palette=[[242,247,231],[255,240,246],[237,246,250]];
            $pdf->SetFillColor(...$palette[$i++ % 3]); $pdf->Rect(14,$y,182,19,'F');
            $pdf->SetXY(19,$y+2); $pdf->SetFont('Helvetica','B',10); $pdf->SetTextColor(49,91,54);
            $pdf->MultiCell(172,5,pdf_txt($area.'  |  '.$label),0,'L');
            $pdf->SetY(max($y+21,$pdf->GetY()+3));
        }
        $pdf->SetTextColor(43,43,43);
    }
    if ($s['skills']) {
        $pdf->sectionTitle('Latest assessment - '.month_year_label($s['month']));
        foreach ($s['skills'] as $category=>$items) { $pdf->subTitle((string)$category);
            $rows=[];
            foreach ($items as $item) $rows[]=[['t'=>$item['text']],['t'=>$item['label']]];
            $pdf->table(['Observed skill','Development level'],$rows,[125,57]); }
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
