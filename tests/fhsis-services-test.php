<?php
require dirname(__DIR__).'/includes/fhsis-template-report.php';
function servicesCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function serviceFact(string $metric,int $id,string $birth='1990-01-01',string $sex='Female',array $details=[]): array {
    return ['indicator_key'=>$metric,'resident_id'=>$id,'report_birthdate'=>$birth,'report_sex'=>$sex,'service_date'=>'2026-10-01','status'=>'verified','details_json'=>$details];
}
$catalog=fhsisIndicators(); servicesCheck(count($catalog)>500,'Catalog does not cover report sections');
foreach ($catalog as $key=>$metric) {
    servicesCheck($key===$metric['key'] && count($metric['columns'])>=3,'Invalid indicator schema');
    foreach ($metric['parents'] as $parent) servicesCheck(isset($catalog[$parent]),'Broken parent relation');
}
servicesCheck(!fhsisIndicatorAllowed($catalog['s10.A5'],'ADMIN') && !fhsisIndicatorAllowed($catalog['s10.A5'],'MEDTECH'),'Private indicators exposed through ordinary chart documentation');
servicesCheck(fhsisIndicatorAllowed($catalog['s2.A6'],'MIDWIFE') && !fhsisIndicatorAllowed($catalog['s2.A6'],'DENTAL'),'Clinical department restriction failed');
$issues=[];
$eight=['pregnancy_verified'=>true,'visit_dates'=>['2026-01-01','2026-02-01','2026-03-01','2026-04-01','2026-05-01','2026-06-01','2026-07-01','2026-08-01'],'schedule_verified'=>true];
$anc=serviceFact('s2.A7',1,'1990-01-01','Female',$eight);
$c=fhsisDocumentedCells([$anc,$anc], '2026-10-01',$issues);
servicesCheck($c[2]['N7']===1 && $c[2]['O6']===1,'Eight-ANC completion or parent set deduplication failed');
$seven=$eight; array_pop($seven['visit_dates']);
servicesCheck(fhsisDocumentedCells([serviceFact('s2.A7',1,'1990-01-01','Female',$seven)],'2026-10-01',$issues)===[],'Incomplete ANC series counted');
$hh1=serviceFact('s7.A7',1,'','',['household_key'=>'BRGY-A/HH-1']); $hh2=serviceFact('s7.A7',2,'','',['household_key'=>'BRGY-A/HH-1']);
$c=fhsisDocumentedCells([$hh1,$hh2], '2026-10-01',$issues);
servicesCheck($c[7]['N7']===1 && $c[7]['N6']===1 && !isset($c[7]['L7']),'Household identity, household parent or inappropriate sex disaggregation');
$birth=serviceFact('s2.Q29',3,'2026-09-25','Male',['mother_birthdate'=>'2008-01-01']);
$c=fhsisDocumentedCells([$birth], '2026-10-01',$issues);
servicesCheck($c[2]['AC29']===1 && $c[2]['AE29']===1,'Newborn birthweight used infant age instead of maternal age');
servicesCheck(!fhsisMetricEligible($catalog['s10.A34'],['birthdate'=>'2026-09-01','sex'=>'Male'],'2026-10-01',[]),'30-day infant classified as neonatal');
$fetal=serviceFact('s10.A36',4,'1990-01-01','Female',['pregnancy_verified'=>true,'event_key'=>'FETAL-001','subject_sex'=>'unknown']);
$c=fhsisDocumentedCells([$fetal,$fetal], '2026-10-01',$issues);
servicesCheck($c[10]['N36']===1 && !isset($c[10]['M36']),'Fetal death duplicated or maternal sex used as fetal sex');
servicesCheck(fhsisDocumentedCells([serviceFact('s10.A5',5,'1990-01-01','Female',['pregnancy_verified'=>true])], '2026-10-01',$issues)===[],'Private screening bypassed approved aggregate workflow');
$clinical=serviceFact('s6.Q6',1,'1990-01-01','Female'); $c=fhsisDocumentedCells([$clinical], '2026-10-01',$issues);
servicesCheck($c[6]['AC6']===1,'Cervical screening mapping missing');
servicesCheck(fhsisDocumentedCells([serviceFact('s6.Q6',1,'2008-01-01','Female')], '2026-10-01',$issues)===[],'Ineligible cancer screening age accepted');
$dx=['resident_id'=>1,'report_birthdate'=>'1990-01-01','report_sex'=>'Female','consultation_date'=>'2026-10-01','consultation_status'=>'Completed','diagnosis'=>'Hypertension','icd_code'=>'I10'];
$auto=fhsisAutomaticFacts(['consultations'=>[$dx,array_merge($dx,['consultation_date'=>'2026-08-01']),array_merge($dx,['consultation_date'=>'2026-09-01'])]],'2026-10-01');
$extra=serviceFact('s5.A18',2); $duplicate=serviceFact('s5.A18',1);
$c=fhsisDocumentedCells(array_merge($auto,[$extra,$duplicate]),'2026-10-01',$issues);
servicesCheck($c[5]['N18']===2 && $c[5]['N17']===2,'Automatic and documented sources not unioned or prior-month identity lost');

// Real SQL integration for private reporting; no access to a hosted database or real patients.
$db=new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
$db->exec('CREATE TABLE residents(id INTEGER PRIMARY KEY,birthdate TEXT,sex TEXT); CREATE TABLE pregnancy_records(id INTEGER PRIMARY KEY,resident_id INTEGER,last_menstrual_period TEXT); CREATE TABLE confidential_lab_tests(id INTEGER PRIMARY KEY,resident_id INTEGER,created_by_staff_id INTEGER,test_type TEXT,specimen_date TEXT,fhsis_reporting_encrypted TEXT,fhsis_aggregate_approved INTEGER DEFAULT 0,fhsis_approved_by_id INTEGER,fhsis_approved_at TEXT); CREATE TABLE confidential_access_logs(staff_id INTEGER,resident_id INTEGER,record_id INTEGER,action TEXT,accessed_at TEXT); CREATE TABLE portal_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT); CREATE TABLE fhsis_reports(id INTEGER PRIMARY KEY AUTOINCREMENT,report_type TEXT,reporting_period TEXT,report_month INTEGER,report_year INTEGER,report_data TEXT)');
$db->exec("INSERT INTO residents VALUES(1,'1990-01-01','Female'); INSERT INTO pregnancy_records VALUES(10,1,'2026-03-01'); INSERT INTO confidential_lab_tests(id,resident_id,created_by_staff_id,test_type,specimen_date) VALUES(20,1,7,'Syphilis Screening','2026-09-10'),(21,1,7,'Syphilis Screening','2026-09-11')");
$reportingKey=fhsisReportingKey($db);
$fixtureEncrypt=static fn($text)=>'fixture-encrypted:'.hash_hmac('sha256',$text,'test-only-key');
$tablesBefore=$db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$post=['fhsis_result_category'=>'negative','fhsis_screening_complete'=>1,'fhsis_pregnancy_id'=>10,'fhsis_pregnancy_confirmed'=>1]; $staff=['staff_id'=>7,'staff_type'=>'MEDTECH'];
$db->beginTransaction(); fhsisApproveScreening($db,20,$post,$staff,$reportingKey,$fixtureEncrypt); $db->commit();
$c=fhsisReadScreeningCells($db,'2026-09-01'); servicesCheck($c['N5']===1 && $c['N9']===0,'Screening or explicit non-reactive zero incorrect');
$post['fhsis_result_category']='reactive'; $post['fhsis_treatment_date']='2026-10-20'; $post['fhsis_treatment_reference']='Signed treatment course record';
$db->beginTransaction(); fhsisApproveScreening($db,21,$post,$staff,$reportingKey,$fixtureEncrypt); fhsisApproveScreening($db,21,$post,$staff,$reportingKey,$fixtureEncrypt); $db->commit();
$c=fhsisReadScreeningCells($db,'2026-09-01'); servicesCheck($c['N5']===1 && $c['N9']===1,'Repeat tests or repeat approval inflated women screened/reactive');
$c=fhsisReadScreeningCells($db,'2026-10-01'); servicesCheck($c['N13']===1 && !isset($c['N5']),'Actual treatment month mixed with screening month');
foreach ($db->query('SELECT report_data FROM fhsis_reports')->fetchAll(PDO::FETCH_COLUMN) as $json) {
    $body=json_decode($json,true);
    foreach ($body['contributions'] as $source=>$metrics) foreach ($metrics as $entry) servicesCheck(!array_intersect(array_keys($entry),['resident_id','source_id','result_text','birthdate']),'Private identity/result copied into reporting aggregate');
}
servicesCheck($db->query('SELECT fhsis_reporting_encrypted FROM confidential_lab_tests WHERE id=20')->fetchColumn()!=='','Approved facts were not saved in encrypted source storage');
servicesCheck($tablesBefore===$db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),'Runtime reporting created a table');
try { fhsisApproveScreening($db,20,$post,['staff_id'=>8,'staff_type'=>'MEDTECH'],$reportingKey,$fixtureEncrypt); throw new LogicException('Other MedTech source approval allowed'); } catch (RuntimeException $e) { servicesCheck(!($e instanceof LogicException),'Other MedTech source approval allowed'); }
$db->beginTransaction(); fhsisWithdrawScreening($db,21,$staff,$reportingKey); $db->commit();
$c=fhsisReadScreeningCells($db,'2026-09-01'); servicesCheck($c['N9']===0,'Withdrawn private approval still counted');
ob_start(); fhsisScreeningFormFields(); $html=ob_get_clean(); $document=new DOMDocument(); @$document->loadHTML($html); $xp=new DOMXPath($document);
foreach (['fhsis_pregnancy_id','fhsis_result_category','fhsis_screening_complete','fhsis_pregnancy_confirmed','fhsis_aggregate_approved','fhsis_treatment_date','fhsis_treatment_reference'] as $field) servicesCheck($xp->query('//*[@name="'.$field.'"]')->length===1,'Private reporting form field missing or duplicated');
$db->exec('CREATE TABLE consultations(id INTEGER PRIMARY KEY,resident_id INTEGER,consultation_date TEXT,chief_complaint TEXT,diagnosis TEXT,consultation_status TEXT,fhsis_details_json TEXT,fhsis_verified_by_id INTEGER,fhsis_verified_at TEXT); CREATE TABLE audit_logs(action TEXT,module_name TEXT,record_id INTEGER,entity_type TEXT,entity_id INTEGER,description TEXT,created_at TEXT)');
$db->exec("INSERT INTO consultations(id,resident_id,consultation_date,chief_complaint,diagnosis,consultation_status) VALUES(30,1,'2026-10-01','Medical consultation','Hypertension','Completed')");
$session=['rhu_staff_login'=>['staff_id'=>7,'staff_type'=>'NURSE']];
$doc=['resident_id'=>1,'source_table'=>'consultations','source_id'=>30,'indicator_key'=>'s5.A18','service_date'=>'2026-10-01','criteria_confirmed'=>1,'evidence_reference'=>'Fixture consultation record'];
fhsisSaveService($db,$doc,$session); fhsisSaveService($db,$doc,$session);
$source=$db->query('SELECT * FROM consultations WHERE id=30')->fetch(PDO::FETCH_ASSOC);
servicesCheck(count(fhsisServiceHistory($source,'consultations'))===1,'Repeated documentation duplicated a source observation');
$c=fhsisReadDocumentedCells($db,'2026-10-01',$issues); servicesCheck($c[5]['N18']===1,'Existing-table documentation was not read');
fhsisWithdrawService($db,'consultations',30,1,'s5.A18|2026-10-01',$session['rhu_staff_login']);
servicesCheck(fhsisReadDocumentedCells($db,'2026-10-01',$issues)===[],'Withdrawn source annotation still counted');
fhsisSaveService($db,$doc,$session);
$db->exec("UPDATE consultations SET diagnosis='Corrected diagnosis' WHERE id=30");
servicesCheck(fhsisReadDocumentedCells($db,'2026-10-01',$issues)===[],'Changed clinical source still counted without re-verification');
$db->exec("INSERT INTO consultations(id,resident_id,consultation_date,chief_complaint,diagnosis,consultation_status) VALUES(31,1,'2026-10-01','Family planning consultation','Family planning review','Completed')");
$fpSession=['rhu_staff_login'=>['staff_id'=>9,'staff_type'=>'MIDWIFE']];
$fpDoc=['resident_id'=>1,'source_table'=>'consultations','source_id'=>31,'indicator_key'=>'s1.A16.opening','service_date'=>'2026-10-01','criteria_confirmed'=>1,'evidence_reference'=>'Verified opening register observation'];
fhsisSaveService($db,$fpDoc,$fpSession);
$fpDoc['indicator_key']='s1.A9'; $fpDoc['evidence_reference']='Documented demand satisfied and current modern-method use'; fhsisSaveService($db,$fpDoc,$fpSession);
$c=fhsisReadDocumentedCells($db,'2026-10-01',$issues);
servicesCheck($c[1]['I16']===1 && $c[1]['J16']===1 && $c[1]['V9']===1,'FP existing-table fields not mapped into the report');
$temp=tempnam(sys_get_temp_dir(),'fhsis-existing-'); $archivePath=$temp.'.zip';
try {
    fhsisWriteTemplateReport(['month'=>'October','year'=>2026,'facility'=>'Fixture facility','municipality'=>'Fixture','province'=>'Fixture','scope'=>'Fixture','cases'=>[],'indicator_cells'=>$c],$temp);
    copy($temp,$archivePath); $zip=new FhsisReportArchive($archivePath);
    $sheet=new DOMDocument(); $sheet->loadXML($zip->read('xl/worksheets/sheet1.xml')); $xp=new DOMXPath($sheet);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    servicesCheck($xp->evaluate('string(//s:c[@r="J16"]/s:v)')==='1','Verified FP source did not reach the Excel cell');
    $zip->close();
} finally { unset($zip); if (is_file($temp)) unlink($temp); if (is_file($archivePath)) unlink($archivePath); }
// Same-form documentation uses the actual saved source, preserves the caller's transaction,
// and rolls back the whole annotation batch if any selected outcome is invalid.
$inline = ['fhsis_inline'=>[array_merge($doc,['source_id'=>999,'resident_id'=>999])]];
$db->beginTransaction();
fhsisSaveInlineServices($db,'consultations',30,1,$inline,$session);
servicesCheck($db->inTransaction(), 'Inline reporting committed the clinical transaction');
$stored = $db->query('SELECT * FROM consultations WHERE id=30')->fetch(PDO::FETCH_ASSOC);
$entry = fhsisServiceHistory($stored,'consultations')[0];
servicesCheck($entry['source_id']===30 && $entry['resident_id']===1, 'Posted source identity overrode clinical owner');
$db->rollBack();
$before = $db->query('SELECT fhsis_details_json FROM consultations WHERE id=30')->fetchColumn();
try {
    fhsisSaveInlineServices($db,'consultations',30,1,['fhsis_inline'=>[$doc,array_merge($doc,['indicator_key'=>'invalid'])]],$session);
    throw new LogicException('Invalid inline service accepted');
} catch (RuntimeException $expected) { servicesCheck(!($expected instanceof LogicException), 'Invalid inline service accepted'); }
servicesCheck(!$db->inTransaction() && $before===$db->query('SELECT fhsis_details_json FROM consultations WHERE id=30')->fetchColumn(), 'Invalid annotation batch partially saved');
servicesCheck(!fhsisCompletedSource('health_records',['remarks'=>"Status: Scheduled"]), 'Pending medical profile counted as a completed service');

// Old dashboards remain loadable when the shared file is uploaded before dashboard files.
ob_start(); fhsisRenderInlineServices('NURSE'); $removedSection=ob_get_clean();
servicesCheck($removedSection === '', 'Removed reporting section was rendered');

// Clinical forms no longer inject a separate reporting-service section.
foreach (['NurseDashboard.php','MidwifeDashboard.php','DentalDashboard.php','MedTechDashboard.php','SanitaryDashboard.php','RHUAdminDashboard.php'] as $dashboard) {
    $markup=file_get_contents(dirname(__DIR__).'/'.$dashboard);
    servicesCheck(!str_contains($markup,'fhsisRenderInlineServices('), 'Extra reporting section remains in '.$dashboard);
}
servicesCheck(fhsisInlineActionSources('ADMIN')===[], 'Administrative role can attest clinical services');

// Same-save SQL integration for each ordinary clinical role; laboratory private screening is tested above.
$roleSources = ['NURSE'=>['Medical consultation','s3.A24'],'MIDWIFE'=>['Family planning consultation','s1.A14.opening'],'DENTAL'=>['Dental consultation','s4.A23'],'PHYSICIAN'=>['Medical consultation','s3.A24']];
$db->exec('CREATE TABLE dental_records(id INTEGER PRIMARY KEY,resident_id INTEGER,record_type TEXT,status TEXT,record_date TEXT,meta_json TEXT,fhsis_verified_by_id INTEGER,fhsis_verified_at TEXT)');
$sourceId = 50;
foreach ($roleSources as $role=>[$complaint,$key]) {
    $insert=$db->prepare("INSERT INTO consultations(id,resident_id,consultation_date,chief_complaint,diagnosis,consultation_status) VALUES(?,1,'2026-10-01',?,'Documented completed outcome','Completed')");
    $table = $role === 'DENTAL' ? 'dental_records' : 'consultations';
    if ($role === 'DENTAL') $db->exec("INSERT INTO dental_records(id,resident_id,record_type,status,record_date,meta_json) VALUES($sourceId,1,'examination','Completed','2026-10-01','{}')");
    else $insert->execute([$sourceId,$complaint]);
    fhsisSaveInlineServices($db,$table,$sourceId,1,['fhsis_inline'=>[['indicator_key'=>$key,'service_date'=>'2026-10-01','criteria_confirmed'=>1]]],['rhu_staff_login'=>['staff_id'=>7,'staff_type'=>$role]]);
    $source=$db->query('SELECT * FROM '.$table.' WHERE id='.$sourceId)->fetch(PDO::FETCH_ASSOC);
    $entries=fhsisServiceHistory($source,$table);
    servicesCheck(count($entries)===1 && $entries[0]['department']===$role, 'Same-save service missing for '.$role);
    servicesCheck($entries[0]['details_json']['evidence_reference']==='Clinical record: '.$table.' #'.$sourceId, 'Supporting source was not linked automatically');
    $sourceId++;
}
// Household-level sanitary evidence can be linked to the existing inspection without inventing a patient.
$db->exec('CREATE TABLE sanitary_records(id INTEGER PRIMARY KEY,resident_id INTEGER,record_type TEXT,status TEXT,record_date TEXT,details_json TEXT,fhsis_verified_by_id INTEGER,fhsis_verified_at TEXT)');
$db->exec("INSERT INTO sanitary_records(id,record_type,status,record_date,details_json) VALUES(40,'sanitary_inspection','Compliant','2026-10-01','{}')");
$household = ['indicator_key'=>'s7.A7','service_date'=>'2026-10-01','criteria_confirmed'=>1,'evidence_reference'=>'Verified household inspection','household_key'=>'BRGY-A/HH-1'];
fhsisSaveInlineServices($db,'sanitary_records',40,0,['fhsis_inline'=>[$household]],['rhu_staff_login'=>['staff_id'=>8,'staff_type'=>'SANITARY_INSPECTOR']]);
$c=fhsisReadDocumentedCells($db,'2026-10-01',$issues);
$metric=$catalog['s7.A7']; $column=end($metric['columns']);
servicesCheck(($c[$metric['sheet']][$column.$metric['row']]??0)===1, 'Resident-less household inspection did not reach the report');

echo "Passed: cross-program documentation, clinician permissions, pregnancy/visit evidence, parent unions, household deduplication, maternal/newborn subjects, neonatal bounds, confidential-source ownership, aggregate privacy, repeat-test deduplication, actual treatment month, withdrawal, same-form clinical saves, transaction ownership and resident-less household records.\n";
