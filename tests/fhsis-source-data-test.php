<?php
require dirname(__DIR__).'/includes/fhsis-template-report.php';
function adminExtendedTableExists(PDO $pdo,string $table): bool { return isset($pdo->fixtures[$table]); }
function portalSettings(PDO $pdo): array { return ['rhu_name'=>'Fixture RHU','rhu_municipality'=>'Fixture municipality','rhu_province'=>'Fixture province']; }
class FhsisFixtureStatement extends PDOStatement
{
    public array $rows=[];
    public function __construct(private FhsisFixtureDatabase $db,private string $sql) {}
    public function execute(?array $params=null): bool {
        if (str_starts_with($this->sql,'SHOW COLUMNS')) {
            preg_match('/FROM `?([a-z_]+)`?/',$this->sql,$m);
            $this->rows=array_map(static fn($key)=>['Field'=>$key],array_keys($this->db->fixtures[$m[1]][0])); return true;
        }
        if (str_contains($this->sql,'FROM disease_cases dc')) { $table='disease_cases'; $date='case_date'; }
        else {
            if (!preg_match('/FROM `([^`]+)` t.*WHERE t\.`([^`]+)`/s',$this->sql,$m)) throw new RuntimeException('Unexpected fixture query');
            [$unused,$table,$date]=$m;
        }
        $this->db->ranges[$table]=$params;
        $this->rows=array_values(array_filter($this->db->fixtures[$table],static fn($r)=>$r[$date]>=$params[0] && $r[$date]<$params[1]));
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $mode===PDO::FETCH_COLUMN?array_map(static fn($r)=>array_values($r)[0],$this->rows):$this->rows; }
}
class FhsisFixtureDatabase extends PDO
{
    public array $ranges=[];
    public function __construct(public array $fixtures) {}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false { $s=new FhsisFixtureStatement($this,$query); $s->execute(); return $s; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new FhsisFixtureStatement($this,$query); }
}
function sourceCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$person=['resident_id'=>1,'report_birthdate'=>'1990-01-01','report_sex'=>'Female','resident_name'=>'Private fixture','report_barangay'=>'Fixture'];
$fp=$person+['id'=>1,'consultation_date'=>'2025-12-15','consultation_status'=>'Completed','diagnosis'=>'Family Planning Counseling','remarks'=>'FHSIS_FP_V1:{"event":"new","method":"Condom"}'];
$dx=array_merge($fp,['id'=>2,'consultation_date'=>'2026-01-10','diagnosis'=>'Hypertension','icd_code'=>'I10','remarks'=>'']);
$case=$person+['id'=>5,'consultation_id'=>2,'disease_type_id'=>1,'case_date'=>'2026-01-10','case_status'=>'Confirmed','report_disease_name'=>'Fixture disease','report_icd_code'=>'A00'];
// A processed booking stores its actual service date and the event derived from the clinical selection.
$processed = array_merge($fp, ['id'=>8,'resident_id'=>2,'consultation_date'=>'2026-01-20',
    'remarks'=>'FHSIS_FP_V1:'.json_encode(['event'=>fhsisFpServiceEvent('New Acceptor'),'method'=>'Condom','service_date'=>'2026-01-20'])]);
$fixture=new FhsisFixtureDatabase([
    'consultations'=>[$fp,$processed,$processed,$dx,array_merge($dx,['id'=>3,'is_confidential'=>1])],
    'family_planning_records'=>[$person+['id'=>1,'last_supply_date'=>'2026-01-03','created_at'=>'2026-02-01','contraceptive_method'=>'Condom']],
    'dental_records'=>[$person+['id'=>1,'record_date'=>'2026-01-03','record_type'=>'examination','status'=>'Completed']],
    'vital_statistics'=>[$person+['id'=>1,'event_date'=>'2026-01-04','event_type'=>'Death','status'=>'Recorded']],
    'disease_cases'=>[$case,array_merge($case,['id'=>6]),array_merge($case,['id'=>7,'consultation_id'=>3,'case_status'=>'Ruled out'])],
    'disease_types'=>[['id'=>1,'name'=>'Fixture disease','icd_code'=>'A00']],
]);
$data=fhsisTemplateReportData($fixture,2026,1);
sourceCheck($fixture->ranges['consultations']===['2025-12-01','2026-02-01'],'January FP carry-in month not queried');
sourceCheck($fixture->ranges['family_planning_records']===['2025-12-01','2026-02-01'],'FP service date period incorrect');
sourceCheck(count($data['source_records']['family_planning_records'])===1,'Actual FP supply date not selected');
sourceCheck($data['indicator_cells'][1]['M16']===1,'Previous December FP acceptance missing');
sourceCheck($data['indicator_cells'][1]['AC16']===1 && $data['indicator_cells'][1]['AD32']===1, 'Processed service did not reach current-month FP counts exactly once');
sourceCheck($data['indicator_cells'][5]['N18']===1 && $data['indicator_cells'][5]['N17']===0,'Confidential record leaked or prior-year NCD included');
sourceCheck(count($data['cases'])===1,'Duplicate linked or ruled-out morbidity counted');
sourceCheck($data['indicator_cells'][11]['AO38']===0,'M2 infant mortality not reconciled to vital source');
sourceCheck(!$data['include_source_records'] && !$data['submission_ready'],'Draft/privacy defaults incorrect');
sourceCheck(count($data['validation'])>250 && count($data['issues'])>0,'Review inventory or source issues absent');
// Native FP services count even if the mirrored consultation is unavailable or retains an older booking date.
$nativeFp = array_merge($person,['id'=>9,'status'=>'Active','last_supply_date'=>'2026-01-20','clinical_notes'=>'FHSIS_FP_V1:{"event":"new","method":"Condom"}']);
$nativeOnly=new FhsisFixtureDatabase(['family_planning_records'=>[$nativeFp]]);
$nativeData=fhsisTemplateReportData($nativeOnly,2026,1);
sourceCheck($nativeData['indicator_cells'][1]['AC16']===1, 'Actual native FP service was ignored without a consultation');
$duplicateFp=new FhsisFixtureDatabase(['consultations'=>[array_merge($processed,['resident_id'=>1])],'family_planning_records'=>[$nativeFp]]);
$duplicateData=fhsisTemplateReportData($duplicateFp,2026,1);
sourceCheck($duplicateData['indicator_cells'][1]['AC16']===1, 'Mirrored FP service counted twice');
$missing=new FhsisFixtureDatabase([]); $empty=fhsisTemplateReportData($missing,2026,1);
sourceCheck($empty['indicator_cells']===[],'Unavailable source tables falsely reported as zero');
echo "Passed: database-source integration, January carry-in, actual FP service date, confidential exclusion, morbidity deduplication, mortality reconciliation and unavailable-source blanks.\n";
