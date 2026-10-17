<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/observations.php';
function check(bool $ok,string $name): void { if (!$ok) throw new RuntimeException($name); echo "ok $name\n"; }
$ind=['std:1'=>['category'=>'Motor','indicator_text'=>'Pour water']];
$ratings=['D'=>['label'=>'Developed','numeric_value'=>3]];
$valid=['observed_on'=>'2026-10-01','submission_token'=>str_repeat('a',64),'rating'=>['std:1'=>'D']];
check(count(observation_validate($valid,$ind,$ratings,'2026-10-17')['skills'])===1,'one observed skill');
foreach ([['observed_on'=>'2026-02-30'],['observed_on'=>'2026-10-18'],['rating'=>[]],['rating'=>['std:1'=>'']],['rating'=>['std:2'=>'D']],['rating'=>['cust:1'=>'D']],['rating'=>['std:1'=>'X']],['submission_token'=>'bad'],['evidence'=>['std:1'=>[]]]] as $bad) {
    try { observation_validate(array_replace($valid,$bad),$ind,$ratings,'2026-10-17'); throw new RuntimeException('Accepted invalid input'); }
    catch (InvalidArgumentException $e) { echo "ok rejected invalid input\n"; }
}
check(!observation_can_access(['id'=>2,'role'=>'teacher'],['teacher_id'=>3]),'foreign child forbidden');
check(observation_can_access(['id'=>2,'role'=>'admin'],['teacher_id'=>3]),'admin authorized');
$r=['observation_id'=>2,'observed_on'=>'2026-10-02','skill_key'=>'std:1','grade'=>'Nursery','scale_snapshot'=>'a','skill_text'=>'Pour','category'=>'Motor','rating_label'=>'Developed','rating_code'=>'D','author_name'=>'<Teacher>','evidence'=>'<script>','next_steps'=>'Practice'];
$earlier=array_replace($r,['observation_id'=>3,'observed_on'=>'2026-09-01']);
$same=array_replace($r,['observation_id'=>4]);
$changed=array_replace($r,['scale_snapshot'=>'b']);
$groups=observation_skill_history([$same,$r,$earlier,$changed]);
check(count($groups)===2,'changed scale kept separate');
check($groups[0]['first']['observed_on']==='2026-09-01' && $groups[0]['latest']['observation_id']===4,'backdated chronology and same-day ordering');
ob_start(); observation_history_render([$r]); $html=ob_get_clean();
check(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>'),'history escapes evidence');
echo "Validation/history tests passed\n";

if (!in_array('--local-db',$argv,true)) exit;
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/child_report.php';
require_once __DIR__.'/../includes/child_report_pdf.php';
$pdo=db();
check($pdo->query('SELECT DATABASE()')->fetchColumn()==='mtt_local','local database safety gate');
$pdo->exec(file_get_contents(__DIR__.'/../sql/migrate_075_child_observations.sql'));
$pdo->exec(file_get_contents(__DIR__.'/../sql/migrate_075_child_observations.sql'));
$user=$pdo->query("SELECT id,name,role FROM users WHERE role='admin' LIMIT 1")->fetch();
$si=$pdo->query('SELECT id,grade FROM skill_indicators WHERE is_active=1 LIMIT 1')->fetch();
$code=$pdo->query('SELECT code FROM rating_config WHERE is_active=1 LIMIT 1')->fetchColumn();
$child=0;
try {
    $s=$pdo->prepare("INSERT INTO students (first_name,grade,teacher_id) VALUES ('Observation test fixture',?,?)"); $s->execute([$si['grade'],$user['id']]); $child=(int)$pdo->lastInsertId();
    $student=['id'=>$child,'grade'=>$si['grade'],'teacher_id'=>$user['id']];
    $input=['observed_on'=>date('Y-m-d'),'submission_token'=>bin2hex(random_bytes(32)),'rating'=>['std:'.$si['id']=>$code],'evidence'=>['std:'.$si['id']=>'Local evidence fixture']];
    $id=observation_save($pdo,$user,$student,$input);
    check(observation_save($pdo,$user,$student,$input)===$id,'retry is idempotent');
    $rows=observation_history($pdo,$child);
    check(count($rows)===1,'single observation after retry');
    check($rows[0]['skill_text']!=='' && $rows[0]['scale_snapshot']!=='' && $rows[0]['author_name']===$user['name'],'history snapshots saved');
    $d=child_report_data($child);
    check(!$d['months'] && count($d['observations'])===1,'observation-only data');
    ob_start(); child_report_render($d); $html=ob_get_clean();
    check(str_contains($html,'Local evidence fixture'),'observation-only detailed HTML report');
    $bytes=child_report_pdf_bytes($d,'Fixture school');
    check(str_starts_with($bytes,'%PDF-'),'observation-only PDF generated');
    $d['observations']=[];
    ob_start(); child_report_render($d); $html=ob_get_clean();
    check(!str_contains($html,'Dated observations'),'empty history omitted');
} finally {
    if ($child) {
        $pdo->prepare('DELETE s FROM child_observation_skills s JOIN child_observations o ON o.id=s.observation_id WHERE o.student_id=?')->execute([$child]);
        $pdo->prepare('DELETE FROM child_observations WHERE student_id=?')->execute([$child]);
        $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$child]);
    }
}
echo "Local database/render tests passed; fixtures removed\n";
