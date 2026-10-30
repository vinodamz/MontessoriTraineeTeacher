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
        ['text'=>'Old skill','ratings'=>['Dec-25'=>'N']]]],
    'baseline'=>['gross_motor'=>'Baseline detail','recorded_at'=>'2025-06-01'],
    'comments'=>['Dec-25'=>[''=>['Old note']], 'Jan-26'=>[''=>['Latest <note>']]],
    'observations'=>[['evidence'=>'Private observation']]
];
$s = parent_summary_data($d);
verify_summary($s['month']==='Jan-26' && count($s['skills']['Motor'])===1,'latest assessed month and only its skills');
verify_summary($s['notes_month']==='Jan-26' && $s['notes'][''][0]==='Latest <note>','latest notes selected');
ob_start(); parent_summary_render($d); $html=ob_get_clean();
verify_summary(str_contains($html,'Baseline detail') && str_contains($html,'Latest &lt;note&gt;'),'baseline and escaped latest note appear');
verify_summary(!str_contains($html,'Old note') && !str_contains($html,'Old skill') && !str_contains($html,'Private observation'),'prior history and observations excluded');
verify_summary(!str_contains($html,' average') && str_contains($html,'Progressing'),'development labels replace numerical averages');
verify_summary(str_contains($html,'parent-report-logo.png'),'school logo included');
$pdf=parent_summary_pdf_bytes($d,'Test school');
verify_summary(str_starts_with($pdf,'%PDF-'),'parent summary PDF generated');
$d['months']=[]; $d['comments']=[]; $d['baseline']=null;
$s=parent_summary_data($d);
verify_summary($s['month']===null && $s['notes']===[] && $s['skills']===[],'student without assessments handled');
