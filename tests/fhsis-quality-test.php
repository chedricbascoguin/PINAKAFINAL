<?php
require dirname(__DIR__).'/includes/fhsis-template-report.php';
function qualityCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$issues=[];
qualityCheck(fhsisDate('2026-02-30')===null,'Impossible service date accepted');
foreach (['New Acceptor'=>'new','Changing Method'=>'other','Changing Clinic'=>'other','Restarting User'=>'other','Dropout'=>'dropout','Continuing User'=>'counseling','Counseling Only'=>'counseling','Male Client'=>'counseling'] as $selection=>$event) {
    qualityCheck(fhsisFpServiceEvent($selection)===$event, 'Incorrect service-to-FHSIS mapping: '.$selection);
}
$rejected = false;
try { fhsisFpServiceEvent(''); } catch (InvalidArgumentException $expected) { $rejected = true; }
qualityCheck($rejected, 'Missing actual service silently became an acceptor');
$base=['resident_id'=>1,'report_birthdate'=>'1996-01-01','report_sex'=>'Female','consultation_date'=>'2026-10-01','consultation_status'=>'Completed'];
$new=$base+['remarks'=>'FHSIS_FP_V1:{"event":"new","method":"Pills-POP"}'];
$other=array_merge($new,['resident_id'=>2,'remarks'=>'FHSIS_FP_V1:{"event":"other","method":"Condom"}']);
$prior=array_merge($new,['resident_id'=>3,'consultation_date'=>'2026-09-30']);
$c=fhsisFpIndicatorCells([$new,$new,$other,$prior,$base+['remarks'=>'Family Planning New Acceptor counseling']], '2026-10-01',$issues);
qualityCheck($c['AC18']===1 && $c['AD17']===1 && $c['AD32']===1,'FP deduplication or component rollup failed');
qualityCheck($c['M18']===1 && $c['N32']===1 && $c['Q16']===1,'Previous/new/other FP events mixed');
qualityCheck(!isset($c['W18']) && !isset($c['G18']),'Unverified opening register inferred');
qualityCheck(fhsisFpIndicatorCells([$base+['remarks'=>'New Acceptor']], '2026-10-01',$issues)===[],'Counseling counted as an acceptor');
qualityCheck(fhsisFpIndicatorCells([array_merge($new,['consultation_status'=>'Scheduled'])], '2026-10-01',$issues)===[],'Scheduled service counted');
$dental=['resident_id'=>1,'report_birthdate'=>'2020-01-01','report_sex'=>'Male','record_date'=>'2026-09-01','record_type'=>'examination','status'=>'Completed'];
$d=fhsisDentalIndicatorCells([$dental,array_merge($dental,['record_date'=>'2026-10-01']),array_merge($dental,['record_date'=>'2026-10-01']),array_merge($dental,['resident_id'=>2,'record_date'=>'2026-10-02','record_type'=>'prescription'])], '2026-10-01',$issues);
qualityCheck($d['L17']===0 && $d['AB17']===1 && $d['AD17']===1,'Annual first/second visit, same-day deduplication or prescription exclusion failed');
$death=['resident_id'=>4,'report_birthdate'=>'2026-10-01','report_sex'=>'Female','event_date'=>'2026-10-07','event_type'=>'Death','status'=>'Recorded'];
$v=fhsisVitalIndicatorCells([$death,$death],$issues);
qualityCheck($v['N30']===1 && $v['N31']===1 && $v['N34']===1 && $v['N37']===1,'Duplicate death or neonatal boundary failed');
$v=fhsisVitalIndicatorCells([array_merge($death,['event_date'=>'2026-10-29'])],$issues);
qualityCheck($v['N34']===0 && $v['N33']===1,'28-day neonatal boundary failed');
qualityCheck(fhsisVitalIndicatorCells([array_merge($death,['resident_id'=>null])],$issues)===[],'Unlinked death inferred');
$ncd=$base+['diagnosis'=>'Hypertension','icd_code'=>'I10'];
$n=fhsisNcdIndicatorCells([array_merge($ncd,['consultation_date'=>'2026-08-01']),array_merge($ncd,['consultation_date'=>'2026-09-01']),$ncd,$ncd],'2026-10-01');
qualityCheck($n['N17']===2 && $n['N18']===1,'Prior month sum is not the sum of monthly unique residents');
qualityCheck(fhsisNcdIndicatorCells([array_merge($ncd,['report_birthdate'=>'2026-02-30'])],'2026-10-01')===[],'Incomplete diagnosis counted as zero');
$data=['month'=>'October','year'=>2026,'facility'=>'Test RHU','municipality'=>'Test','province'=>'Test','scope'=>'All barangays (RHU aggregate)','cases'=>[],
    'indicator_cells'=>[1=>$c,4=>$d,10=>$v],'source_records'=>['consultations'=>[['resident_name'=>'PRIVATE FIXTURE NAME','diagnosis'=>'PRIVATE FIXTURE DIAGNOSIS']]],'include_source_records'=>false,'submission_ready'=>false,'issues'=>$issues];
$data['validation']=fhsisCoverageRows($data);
qualityCheck(count(array_unique(array_column($data['validation'],'section')))===11,'Not all FHSIS sheets reviewed');
qualityCheck(count($data['validation'])>250,'Indicator inventory incomplete');
$file=tempnam(sys_get_temp_dir(),'fhsis-quality-'); $zip=$file.'.zip';
try {
    fhsisWriteTemplateReport($data,$file); copy($file,$zip); $a=new FhsisReportArchive($zip);
    $wb=$a->read('xl/workbook.xml');
    qualityCheck(str_contains($wb,'Indicator Review') && str_contains($wb,'Data Quality') && !str_contains($wb,'System Records'),'Review tabs missing or private source tab included');
    for ($s=1;$s<=14;$s++) if (isset($a['xl/worksheets/sheet'.$s.'.xml'])) {
        $xml=$a->read('xl/worksheets/sheet'.$s.'.xml');
        qualityCheck(!str_contains($xml,'PRIVATE FIXTURE'),'Identifiable resident details leaked');
        qualityCheck(simplexml_load_string($xml)!==false,'Invalid worksheet XML');
        if ($s<=11) qualityCheck(str_contains($xml,'DRAFT - NOT VALIDATED FOR DOH SUBMISSION'),'Printable draft status absent');
    }
    $a->close();
} finally { unset($a); if (is_file($file)) unlink($file); if (is_file($zip)) unlink($zip); }
echo "Passed: all-section indicator review, FP evidence, annual dental milestones, mortality boundaries, monthly NCD sums, invalid demographics, draft review tabs and identifiable-record exclusion.\n";
