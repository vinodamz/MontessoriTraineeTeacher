<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/parent_summary.php';
function verify_summary(bool $value, string $name): void {
    if (!$value) throw new RuntimeException($name);
    echo "ok $name\n";
}
$d = [
    'student'=>['first_name'=>'Test <child>','last_name'=>'Example','grade'=>'Nursery'],
    'months'=>['Dec-25','Jan-26','Nov-25'],
    'catAvg'=>['Dec-25'=>['Motor'=>2], 'Jan-26'=>['Motor'=>3]],
    'ratings'=>['P'=>['label'=>'Progressing','numeric_value'=>3]],
    'indicators'=>['Motor'=>[['text'=>'Pour water','ratings'=>['Dec-25'=>'N','Jan-26'=>'P']],
        ['text'=>'Old skill','ratings'=>['Dec-25'=>'N']],
        ['text'=>'Leaves the beads','ratings'=>['Jan-26'=>null]],
        ['text'=>'Lost code','ratings'=>['Jan-26'=>'']]],
        'Language'=>[['text'=>'Names the letters','ratings'=>['Jan-26'=>null]]]],
    'baseline'=>['gross_motor'=>'Baseline detail','recorded_at'=>'2025-06-01'],
    'comments'=>['Dec-25'=>[''=>['Old note']], 'Jan-26'=>[''=>['Latest <note>']]],
    'observations'=>[['evidence'=>'Private observation']]
];
$s = parent_summary_data($d);
verify_summary($s['month']==='Jan-26' && count($s['skills']['Motor'])===2 && count($s['skills']['Language'])===1,'latest month keeps selected and unselected indicators');
$byText = [];
foreach ($s['skills'] as $items) foreach ($items as $item) $byText[$item['text']] = $item;
verify_summary($byText['Pour water']['code']==='P' && $byText['Pour water']['label']==='Progressing','selected developmental level is unchanged');
verify_summary($byText['Leaves the beads']['code']==='' && $byText['Leaves the beads']['label']==='Yet to start','unselected developmental level is Yet to start');
verify_summary($byText['Names the letters']['label']==='Yet to start','an area with only unselected indicators is still listed');
verify_summary(!isset($byText['Old skill']) && !isset($byText['Lost code']),'an earlier skill and a historical blank stay off this report');
verify_summary(($s['areas']['Motor'] ?? '')==='Progressing' && !isset($s['areas']['Language']),'area summary still uses selected levels only');
verify_summary($s['notes_month']==='Jan-26' && $s['notes'][''][0]==='Latest <note>','latest notes selected');
ob_start(); parent_summary_render($d); $html=ob_get_clean();
verify_summary(str_contains($html,'Latest &lt;note&gt;'),'escaped latest note appears');
verify_summary(!str_contains($html,'First assessment') && !str_contains($html,'Learning areas') && !str_contains($html,'Baseline detail'),'first assessment and learning areas are not on the page');
verify_summary(!str_contains($html,'Old note') && !str_contains($html,'Old skill') && !str_contains($html,'Lost code') && !str_contains($html,'Private observation'),'prior history, historical blanks and observations excluded');
verify_summary(str_contains($html,'<span>Pour water</span><span class="skill-level">Progressing</span>'),'selected indicator still shows its level');
verify_summary(str_contains($html,'<span>Leaves the beads</span><span class="skill-level">Yet to start</span>'),'unselected indicator is on the page as Yet to start');
verify_summary(str_contains($html,'<span>Names the letters</span><span class="skill-level">Yet to start</span>'),'unselected indicator in its own area is on the page');
verify_summary(!str_contains($html,' average') && str_contains($html,'Progressing'),'development labels replace numerical averages');
verify_summary(str_contains($html,'parent-report-logo.png'),'school logo included');
$pdf=parent_summary_pdf_bytes($d,'Test school');
verify_summary(str_starts_with($pdf,'%PDF-'),'parent summary PDF generated');
$pdfText = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $pdfStreams)) {
    foreach ($pdfStreams[1] as $stream) {
        $plain = @gzuncompress($stream);
        $pdfText .= $plain !== false ? $plain : $stream;
    }
}
verify_summary(str_contains($pdfText,'Pour water') && str_contains($pdfText,'Progressing'),'downloaded report still prints the selected level');
verify_summary(!str_contains($pdfText,'First assessment') && !str_contains($pdfText,'Learning at a glance') && !str_contains($pdfText,'Baseline detail'),'first assessment and learning at a glance are not in the download');
verify_summary(str_contains($pdfText,'Leaves the beads') && str_contains($pdfText,'Names the letters') && str_contains($pdfText,'Yet to start'),'downloaded report shows Yet to start for indicators with no level');
verify_summary(!str_contains($pdfText,'Lost code') && !str_contains($pdfText,'Old skill'),'historical blanks and earlier-only skills stay out of the download');
$beadsAt = strpos($pdfText, 'Leaves the beads');
$lettersAt = strpos($pdfText, 'Names the letters');
verify_summary($beadsAt !== false && strpos($pdfText, 'Yet to start', $beadsAt) !== false,'Yet to start is printed for an unselected indicator');
verify_summary($beadsAt !== false && strpos($pdfText, 'Progressing', $beadsAt) === false,'an unselected indicator does not take on another level');
verify_summary($lettersAt !== false && strpos($pdfText, 'Progressing', $lettersAt) === false,'an unselected area does not borrow another level');
$d['months']=[]; $d['comments']=[]; $d['baseline']=null;
$s=parent_summary_data($d);
verify_summary($s['month']===null && $s['notes']===[] && $s['skills']===[],'student without assessments handled');
