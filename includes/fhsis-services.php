<?php
require_once dirname(__DIR__).'/includes/fhsis-quality.php';
require_once dirname(__DIR__).'/includes/staff-record-departments.php';

function fhsisCompletedSource(string $table, array $row): bool
{
    if (rhuRecordIsConfidential($row)) return false;
    if ($table === 'sanitary_records' && in_array(strtolower(trim($row['status'] ?? '')), ['compliant','conditional','non-compliant','passed','failed','safe','unsafe','satisfactory','unsatisfactory'], true)) return true;
    if ($table === 'health_records' && preg_match('/^Status:\s*(.+)$/mi', (string)($row['remarks'] ?? ''), $match)) return fhsisRecorded(array_merge($row, ['status'=>trim($match[1])]));
    return fhsisRecorded($row);
}

/** Additional documented outcomes travel with the existing clinical form and source record. */
function fhsisSaveInlineServices(PDO $pdo, string $table, int $sourceId, int $residentId, array $post, array $session): void
{
    $entries = $post['fhsis_inline'] ?? [];
    if (!$entries) return;
    if (!is_array($entries) || count($entries) > 30) throw new RuntimeException('Choose at most 30 documented services per visit.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
    foreach ($entries as $entry) {
        if (!is_array($entry) || empty($entry['indicator_key'])) continue;
        // Source identity and clinical role come from the save handler, never hidden form fields.
        $entry['source_table'] = $table;
        $entry['source_id'] = $sourceId;
        $entry['resident_id'] = $residentId;
        if (trim((string)($entry['evidence_reference'] ?? '')) === '') $entry['evidence_reference'] = 'Clinical record: ' . $table . ' #' . $sourceId;
        fhsisSaveService($pdo, $entry, $session);
    }
    if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function fhsisInlineActionSources(string $department): array
{
    return match ($department) {
        'NURSE' => ['answer_consultation'=>'consultations','save_immunization'=>'vaccination_records','save_patient_record'=>'health_records'],
        'MIDWIFE' => ['answer_consultation'=>'consultations','save_maternal'=>'health_records','save_birth_record'=>'health_records','save_family_planning'=>'consultations'],
        'DENTAL' => ['save_examination'=>'dental_records','save_extraction'=>'dental_records','save_procedure'=>'dental_records'],
        'MEDTECH' => ['answer_consultation'=>'consultations','save_test'=>'consultations'],
        'SANITARY_INSPECTOR' => ['answer_consultation'=>'consultations','save_sanitary_inspection'=>'sanitary_records','save_water_sample'=>'sanitary_records','save_food_inspection'=>'sanitary_records','save_waste_record'=>'sanitary_records','save_vector_activity'=>'sanitary_records'],
        'PHYSICIAN' => ['update_consultation'=>'consultations'],
        default => [],
    };
}

// Older deployed dashboards may still call this during a rolling file upload.
// Keep the entry point compatible while rendering no additional service form.
function fhsisRenderInlineServices(string $department): void
{
}

function fhsisIndicators(): array
{
    static $items;
    if ($items===null) {
        $catalog=fhsisEmbeddedIndicatorCatalog();
        $template=dirname(__DIR__).'/assets/templates/fhsis-m1-m2.xlsx';
        if (!is_readable($template)) throw new RuntimeException('The FHSIS workbook template is missing. Upload assets/templates/fhsis-m1-m2.xlsx.');
        if (!hash_equals((string)($catalog['template_sha256']??''),hash_file('sha256',$template))) throw new RuntimeException('The FHSIS indicator definitions do not match the installed template. Upload the matching template and definitions together.');
        $items=$catalog['indicators'];
    }
    return $items;
}

function fhsisLoadIndicatorCatalog(string $phpPath,string $jsonPath): array
{
    if (is_readable($phpPath)) {
        try { $catalog=require $phpPath; }
        catch (Throwable $error) { $catalog=null; }
        if (is_array($catalog) && !empty($catalog['indicators']) && is_array($catalog['indicators'])) return $catalog;
    }
    if (is_readable($jsonPath)) {
        $json=file_get_contents($jsonPath);
        $catalog=is_string($json)?json_decode($json,true):null;
        if (is_array($catalog) && !empty($catalog['indicators']) && is_array($catalog['indicators'])) return $catalog;
    }
    return fhsisEmbeddedIndicatorCatalog();
}

function fhsisServicesEnsure(PDO $pdo): void
{
    // Compatibility entry point: schema installation is an explicit ALTER-only SQL import.
    // Runtime reporting never creates tables or changes the schema.
    foreach (['fhsis_reports'=>['report_type','report_data'],'portal_settings'=>['setting_key','setting_value']] as $table=>$columns)
        foreach ($columns as $column) if (!fhsisColumnExists($pdo,$table,$column)) throw new RuntimeException('The existing '.$table.' table is missing required fields.');
}

function fhsisStorageFields(): array
{
    return ['consultations'=>'fhsis_details_json','health_records'=>'fhsis_details_json','pregnancy_records'=>'fhsis_details_json',
        'vaccination_records'=>'fhsis_details_json','vital_statistics'=>'fhsis_details_json','dental_records'=>'meta_json','sanitary_records'=>'details_json'];
}

function fhsisColumnExists(PDO $pdo,string $table,string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE name=?'); $stmt->execute([$table,$column]);
    } else {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'); $stmt->execute([$table,$column]);
    }
    return (bool)$stmt->fetchColumn();
}

function fhsisStorageField(PDO $pdo,string $table): string
{
    $field=fhsisStorageFields()[$table]??null;
    if (!$field) throw new RuntimeException('Choose a consultation or supported clinical service record for FHSIS documentation.');
    foreach ([$field,'fhsis_verified_by_id','fhsis_verified_at'] as $column) if (!fhsisColumnExists($pdo,$table,$column)) throw new RuntimeException('Import the ALTER-only FHSIS schema first; '.$table.'.'.$column.' is missing.');
    return $field;
}

function fhsisSourcePayload(array $source,string $field): array
{
    $text=trim((string)($source[$field]??'')); if ($text==='') return [];
    $payload=json_decode($text,true);
    if (!is_array($payload)) throw new RuntimeException('The existing clinical metadata is invalid. Correct it before adding reporting documentation.');
    return $payload;
}

function fhsisServiceHistory(array $source,string $table): array
{
    $field=fhsisStorageFields()[$table]??null;
    return $field?array_values(fhsisSourcePayload($source,$field)['_fhsis_services']??[]):[];
}

function fhsisLockSuffix(PDO $pdo): string { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'; }

function fhsisIndicatorAllowed(array $metric,string $department): bool
{
    // Administrative access to patient records does not confer authority to attest clinical findings.
    return empty($metric['restricted']) && empty($metric['derived_only']) && in_array($department,$metric['roles'],true);
}

function fhsisSource(PDO $pdo,string $table,int $id,int $residentId,string $department): array
{
    if (!array_key_exists($table,rhuDepartmentRecordSources($department))) throw new RuntimeException('This record is outside your department.');
    $stmt=$pdo->prepare('SELECT * FROM `'.$table.'` WHERE id=? AND COALESCE(resident_id,0)=(? + 0)');
    $stmt->execute([$id,$residentId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !rhuDepartmentCanReadRecord($department,$table,$row)) throw new RuntimeException('The supporting record is unavailable to your department.');
    if (!fhsisCompletedSource($table,$row)) throw new RuntimeException('A pending, scheduled or cancelled record cannot establish a completed service.');
    return $row;
}

function fhsisFingerprint(array $row): string
{
    unset($row['updated_at']);
    foreach (array_keys($row) as $key) if (str_starts_with($key,'fhsis_')) unset($row[$key]);
    foreach (['meta_json','details_json'] as $field) if (array_key_exists($field,$row)) {
        $payload=json_decode((string)$row[$field],true);
        if (is_array($payload)) { unset($payload['_fhsis_services']); $row[$field]=$payload?json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null; }
    }
    ksort($row); return hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function fhsisMetricEligible(array $metric,array $resident,string $date,array $details): bool
{
    if ($metric['unit']==='household') return fhsisDate($date)!==null && trim((string)($details['household_key']??''))!=='';
    $age=fhsisAge(['report_birthdate'=>$resident['birthdate']??null,'date'=>$date],'date');
    if (!$age) return false;
    $sex=strtolower(trim((string)($resident['sex']??'')));
    if (!in_array($sex,['male','female','m','f'],true) || !empty($metric['female']) && !in_array($sex,['female','f'],true)) return false;
    $number=$metric['age_unit']==='months'?$age->y*12+$age->m:$age->y;
    if ($number<$metric['age_min'] || $number>$metric['age_max']) return false;
    if (isset($metric['max_days']) && $age->days>$metric['max_days']) return false;
    if (isset($metric['event_month']) && (int)substr($date,5,2)!==(int)$metric['event_month']) return false;
    if (!empty($metric['derived_only'])) return false;
    if (!empty($metric['pregnancy']) && empty($details['pregnancy_verified'])) return false;
    if (!empty($metric['maternal_age'])) {
        $mother=fhsisAge(['report_birthdate'=>$details['mother_birthdate']??null,'date'=>$date],'date');
        if (!$mother) return false;
        $limits=match($metric['key']) {
            's10.Q27'=>[0,9], 's10.Q28','s10.Q31'=>[10,14], default=>[15,19]
        };
        if ($mother->y<$limits[0] || $mother->y>$limits[1]) return false;
    }
    if (!empty($metric['mother_link']) && !fhsisDate($details['mother_birthdate']??null)) return false;
    if (!empty($metric['event_key']) && trim((string)($details['event_key']??''))==='') return false;
    if (!empty($metric['subject_sex']) && !in_array($details['subject_sex']??'',['male','female','unknown'],true)) return false;
    if (!empty($metric['minimum_visits'])) {
        $visits=array_values(array_unique($details['visit_dates']??[]));
        if (count($visits)<$metric['minimum_visits']) return false;
        foreach ($visits as $visit) if (!fhsisDate($visit) || $visit>$date || !empty($details['pregnancy_start_date']) && $visit<$details['pregnancy_start_date']) return false;
    }
    if (!empty($metric['scheduled']) && empty($details['schedule_verified'])) return false;
    if (($metric['observation']??'')==='opening' && substr($date,8,2)!=='01') return false;
    if (($metric['observation']??'')==='closing' && fhsisDate($date)->format('t')!==substr($date,8,2)) return false;
    return true;
}

function fhsisSaveService(PDO $pdo,array $post,array $session): int
{
    $department=rhuStaffRecordDepartment($session);
    $staffId=(int)($session['rhu_staff_login']['staff_id']??0);
    $metric=fhsisIndicators()[(string)($post['indicator_key']??'')]??null;
    if (!$metric || !$staffId || !fhsisIndicatorAllowed($metric,$department)) throw new RuntimeException('Your clinical role cannot document this indicator.');
    $date=(string)($post['service_date']??''); $day=fhsisDate($date);
    if (!$day || $day->format('Y-m-d')!==$date || $date>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d')) throw new RuntimeException('Enter the actual service date, no later than today.');
    if (empty($post['criteria_confirmed']) || trim((string)($post['evidence_reference']??''))==='') throw new RuntimeException('Confirm the qualifying criteria and identify the supporting clinical documentation.');
    $residentId=(int)($post['resident_id']??0); $sourceId=(int)($post['source_id']??0); $sourceTable=(string)($post['source_table']??'');
    $source=fhsisSource($pdo,$sourceTable,$sourceId,$residentId,$department);
    $stmt=$pdo->prepare('SELECT birthdate,sex FROM residents WHERE id=?'); $stmt->execute([$residentId]); $resident=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$resident && ($metric['unit']??'') !== 'household') throw new RuntimeException('Resident not found.');
    $resident = $resident ?: [];
    $details=['evidence_reference'=>trim((string)$post['evidence_reference']), 'household_key'=>trim((string)($post['household_key']??'')),
        'visit_dates'=>preg_split('/[\s,;]+/',trim((string)($post['visit_dates']??'')),-1,PREG_SPLIT_NO_EMPTY),
        'schedule_verified'=>!empty($post['schedule_verified']), 'event_key'=>trim((string)($post['event_key']??'')), 'subject_sex'=>(string)($post['subject_sex']??'')];
    $pregnancyId=(int)($post['pregnancy_id']??0);
    if (!empty($metric['pregnancy'])) {
        $stmt=$pdo->prepare('SELECT * FROM pregnancy_records WHERE id=? AND COALESCE(resident_id,0)=(? + 0)'); $stmt->execute([$pregnancyId,$residentId]); $pregnancy=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pregnancy || empty($post['pregnancy_verified'])) throw new RuntimeException('Link the pregnancy episode and confirm eligibility at the service date.');
        if (!empty($pregnancy['last_menstrual_period']) && substr($pregnancy['last_menstrual_period'],0,10)>$date) throw new RuntimeException('Service date precedes the linked pregnancy.');
        $details['pregnancy_verified']=true;
        $details['pregnancy_start_date']=substr((string)($pregnancy['last_menstrual_period']??''),0,10);
    }
    if (!empty($metric['mother_link'])) {
        $motherId=(int)($post['mother_resident_id']??0);
        $stmt=$pdo->prepare("SELECT r.birthdate FROM residents r JOIN audit_logs a ON a.record_id=r.id AND a.entity_id=? WHERE r.id=? AND a.action='Resident Dependent' AND a.module_name='Resident Portal' AND LOWER(r.sex) IN ('female','f')");
        $stmt->execute([$residentId,$motherId]); $motherBirth=$stmt->fetchColumn();
        if (!$motherBirth) throw new RuntimeException('A documented mother-child relationship is required.');
        $details['mother_birthdate']=$motherBirth; $details['mother_resident_id']=$motherId;
    }
    if (!fhsisMetricEligible($metric,$resident,$date,$details)) throw new RuntimeException('The resident age, sex, episode, visit dates or register observation does not meet the selected indicator.');
    $field=fhsisStorageField($pdo,$sourceTable);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $locked=$pdo->prepare('SELECT * FROM `'.$sourceTable.'` WHERE id=? AND COALESCE(resident_id,0)=(? + 0)'.fhsisLockSuffix($pdo)); $locked->execute([$sourceId,$residentId]); $current=$locked->fetch(PDO::FETCH_ASSOC);
        if (!$current || !fhsisCompletedSource($sourceTable,$current) || !rhuDepartmentCanReadRecord($department,$sourceTable,$current)) throw new RuntimeException('The supporting clinical record is no longer reportable.');
        $payload=fhsisSourcePayload($current,$field); $key=$metric['key'].'|'.$date;
        $payload['_fhsis_services'][$key]=['id'=>$key,'resident_id'=>$residentId,'indicator_key'=>$metric['key'],'service_date'=>$date,
            'source_table'=>$sourceTable,'source_id'=>$sourceId,'source_fingerprint'=>fhsisFingerprint($current),'pregnancy_id'=>$pregnancyId?:null,
            'details_json'=>$details,'recorded_by_staff_id'=>$staffId,'department'=>$department,'status'=>'verified'];
        $pdo->prepare('UPDATE `'.$sourceTable.'` SET `'.$field.'`=?,fhsis_verified_by_id=?,fhsis_verified_at=NOW() WHERE id=? AND COALESCE(resident_id,0)=(? + 0)')
            ->execute([json_encode($payload,JSON_THROW_ON_ERROR),$staffId,$sourceId,$residentId]);
        $id=$sourceId;
        $pdo->prepare("INSERT INTO audit_logs (action,module_name,record_id,entity_type,entity_id,description,created_at) VALUES ('FHSIS service verified','FHSIS',?,'resident',?,?,NOW())")
            ->execute([$id,$residentId,json_encode(['indicator'=>$metric['key'],'staff_id'=>$staffId,'source_table'=>$sourceTable,'source_id'=>$sourceId])]);
        if ($ownsTransaction) $pdo->commit();
        return $id;
    } catch (Throwable $e) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Pure aggregation: union identities for each metric and implied parent, never sum duplicates. */
function fhsisDocumentedCells(array $rows,string $monthStart,array &$issues): array
{
    $catalog=fhsisIndicators(); $counts=[]; $seen=[]; $present=[];
    foreach ($rows as $row) {
        if (($row['status']??'')!=='verified' || !empty($row['is_confidential'])) continue;
        $metric=$catalog[$row['indicator_key']??'']??null;
        if (!$metric || !empty($metric['restricted'])) continue;
        $date=substr((string)($row['service_date']??''),0,10);
        $prior=($metric['period']??'')==='year_to_previous_month';
        $from=$prior?substr($monthStart,0,4).'-01-01':$monthStart;
        $until=$prior?$monthStart:fhsisDate($monthStart)->modify('+1 month')->format('Y-m-d');
        if ($date<$from || $date>=$until) continue;
        $resident=['birthdate'=>$row['report_birthdate']??null,'sex'=>$row['report_sex']??''];
        $details=is_array($row['details_json']??null)?$row['details_json']:(json_decode((string)($row['details_json']??''),true)?:[]);
        if (!fhsisMetricEligible($metric,$resident,$date,$details)) { $issues[]='Documented services: an ineligible '.$metric['section'].' observation was excluded.'; continue; }
        $queue=[$metric['key']]; $visited=[];
        while ($queue) {
            $key=array_pop($queue); if (isset($visited[$key])) continue; $visited[$key]=true;
            $m=$catalog[$key]??null;
            if (!$m || !fhsisMetricEligible($m,$resident,$date,$details)) continue;
            $present[$key]=true;
            $identity=match($metric['unit']) { 'household'=>'household:'.strtoupper(trim(preg_replace('/\s+/u',' ',$details['household_key']))), 'fetal_event'=>'fetal:'.strtoupper(trim($details['event_key'])), default=>'resident:'.$row['resident_id'] };
            if (($m['period']??'')==='year_to_previous_month') $identity.=':'.substr($date,0,7);
            if (isset($seen[$key][$identity])) continue;
            $seen[$key][$identity]=true;
            $age=$m['unit']==='household'?0:fhsisAge(['report_birthdate'=>$resident['birthdate'],'date'=>$date],'date')->y;
            if (!empty($m['maternal_age_groups'])) $age=fhsisAge(['report_birthdate'=>$details['mother_birthdate'],'date'=>$date],'date')->y;
            $bucket=$m['unit']==='household'?0:(count($m['columns'])===4?($age<15?0:($age<20?1:2)):(in_array(strtolower($resident['sex']),['male','m'],true)?0:1));
            if (!empty($m['subject_sex'])) $bucket=match($details['subject_sex']) { 'male'=>0,'female'=>1, default=>2 };
            $counts[$key][$bucket]=($counts[$key][$bucket]??0)+1;
            foreach ($m['parents'] as $parent) $queue[]=$parent;
        }
    }
    $cells=[];
    foreach ($present as $key=>$unused) {
        $m=$catalog[$key]; $values=$counts[$key]??[]; $columns=$m['columns']; $total=array_sum($values);
        if ($m['unit']==='household') { $cells[$m['sheet']][end($columns).$m['row']]=$total; continue; }
        foreach ($columns as $i=>$column) $cells[$m['sheet']][$column.$m['row']]=$i===count($columns)-1?$total:($values[$i]??0);
        if (!empty($m['subject_sex']) && !empty($values[2])) {
            unset($cells[$m['sheet']][$columns[0].$m['row']],$cells[$m['sheet']][$columns[1].$m['row']]);
            $issues[]='Fetal deaths: a documented event has unknown sex; its total is retained and sex cells require review.';
        }
        // Demand satisfied total is WRA 15-49; its 10-14 column remains separate.
        if ($key==='s1.A9') $cells[1]['V9']=($values[1]??0)+($values[2]??0);
    }
    return $cells;
}

function fhsisReadDocumentedCells(PDO $pdo,string $monthStart,array &$issues,array $automaticFacts=[],?array $knownColumns=null): array
{
    $rows=[]; $sources=[]; $valid=[]; $installedSources=0;
    foreach (fhsisStorageFields() as $table=>$field) {
        $installed=$knownColumns!==null?count(array_intersect([$field,'fhsis_verified_by_id','fhsis_verified_at'],$knownColumns[$table]??[]))===3:
            fhsisColumnExists($pdo,$table,$field) && fhsisColumnExists($pdo,$table,'fhsis_verified_by_id') && fhsisColumnExists($pdo,$table,'fhsis_verified_at');
        if (!$installed) continue;
        $installedSources++;
        $query=$pdo->prepare('SELECT t.*,r.birthdate AS _report_birthdate,r.sex AS _report_sex FROM `'.$table.'` t LEFT JOIN residents r ON r.id=t.resident_id WHERE t.`'.$field.'` LIKE ?');
        $query->execute(['%"_fhsis_services"%']);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $birth=$source['_report_birthdate']; $sex=$source['_report_sex']; unset($source['_report_birthdate'],$source['_report_sex']);
            if (empty($source['fhsis_verified_at']) || empty($source['fhsis_verified_by_id'])) continue;
            $sources[$table][(int)$source['id']]=$source;
            try { $history=fhsisServiceHistory($source,$table); }
            catch (RuntimeException $e) { $issues[]='Documented services: invalid metadata on a '.$table.' record was excluded.'; continue; }
            foreach ($history as $entry) {
                if (!is_array($entry)) continue;
                $metric=fhsisIndicators()[$entry['indicator_key']??'']??null;
                if (!$metric || ($entry['status']??'')!=='verified') continue;
                $date=substr((string)($entry['service_date']??''),0,10);
                $prior=($metric['period']??'')==='year_to_previous_month';
                if ($date<($prior?substr($monthStart,0,4).'-01-01':$monthStart) || $date>=($prior?$monthStart:fhsisDate($monthStart)->modify('+1 month')->format('Y-m-d'))) continue;
                if (!isset($entry['source_fingerprint'],$entry['department']) || !is_string($entry['source_fingerprint'])) { $issues[]='Documented services: incomplete verification metadata was excluded.'; continue; }
                if (($entry['source_table']??'')!==$table || (int)($entry['source_id']??0)!==(int)$source['id'] || (int)($entry['resident_id']??0)!==(int)$source['resident_id']) continue;
                $entry['report_birthdate']=$birth; $entry['report_sex']=$sex; $rows[]=$entry;
            }
        }
    }
    $pregnancyCache=[]; $motherCache=[];
    foreach ($rows as $row) {
        $source=$sources[$row['source_table']][(int)$row['source_id']]??null;
        if (!$source || (int)$source['resident_id']!==(int)$row['resident_id'] || !rhuDepartmentCanReadRecord($row['department'],$row['source_table'],$source) || !fhsisCompletedSource($row['source_table'],$source)) { $issues[]='Documented services: an unavailable or withdrawn supporting record was excluded.'; continue; }
        if (!hash_equals($row['source_fingerprint'],fhsisFingerprint($source))) { $issues[]='Documented services: a supporting record changed; the service must be reviewed again before it can count.'; continue; }
        $metric=fhsisIndicators()[$row['indicator_key']]??null;
        if (!$metric || !fhsisIndicatorAllowed($metric,$row['department']) || empty($row['recorded_by_staff_id'])) continue;
        $details=is_array($row['details_json']??null)?$row['details_json']:(json_decode((string)($row['details_json']??''),true)?:[]);
        if (!empty($metric['pregnancy'])) {
            $episodeKey=$row['pregnancy_id'].':'.$row['resident_id'];
            if (!array_key_exists($episodeKey,$pregnancyCache)) {
                $episode=$pdo->prepare('SELECT last_menstrual_period FROM pregnancy_records WHERE id=? AND resident_id=?'); $episode->execute([$row['pregnancy_id'],$row['resident_id']]); $pregnancyCache[$episodeKey]=$episode->fetch(PDO::FETCH_ASSOC);
            }
            $pregnancy=$pregnancyCache[$episodeKey];
            if (!$pregnancy) { $issues[]='Documented services: a linked pregnancy episode is no longer available.'; continue; }
            $details['pregnancy_start_date']=substr((string)($pregnancy['last_menstrual_period']??''),0,10);
            if ($details['pregnancy_start_date']>substr($row['service_date'],0,10)) { $issues[]='Documented services: the service date precedes its corrected pregnancy episode.'; continue; }
        }
        if (!empty($metric['mother_link'])) {
            $motherId=(int)($details['mother_resident_id']??0);
            if (!array_key_exists($motherId,$motherCache)) {
                $mother=$pdo->prepare("SELECT birthdate FROM residents WHERE id=? AND LOWER(sex) IN ('female','f')"); $mother->execute([$motherId]); $motherCache[$motherId]=$mother->fetchColumn();
            }
            $birth=$motherCache[$motherId];
            if (!$birth) { $issues[]='Documented services: the linked mother record is unavailable.'; continue; }
            $details['mother_birthdate']=$birth;
        }
        $row['details_json']=$details;
        $valid[]=$row;
    }
    if (!$installedSources) $issues[]='The existing-table FHSIS column update was not found. Import the matching ALTER-only schema; existing automatic clinical-source counts remain available.';
    elseif (!$valid) $issues[]='No verified source-linked FHSIS documentation was found. Adding columns alone does not record an acceptance, screening, treatment or completed service.';
    return fhsisDocumentedCells(array_merge($automaticFacts,$valid),$monthStart,$issues);
}

function fhsisWithdrawService(PDO $pdo,string $table,int $sourceId,int $residentId,string $key,array $staff): void
{
    $department=rhuStaffRecordDepartment(['rhu_staff_login'=>$staff]); $source=fhsisSource($pdo,$table,$sourceId,$residentId,$department); $field=fhsisStorageField($pdo,$table);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM `'.$table.'` WHERE id=? AND resident_id=?'.fhsisLockSuffix($pdo)); $stmt->execute([$sourceId,$residentId]); $source=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$source) throw new RuntimeException('The supporting record was removed.');
        $payload=fhsisSourcePayload($source,$field); $entry=$payload['_fhsis_services'][$key]??null;
        if (!$entry || (int)$entry['recorded_by_staff_id']!==(int)($staff['staff_id']??0)) throw new RuntimeException('Only the recording clinician can withdraw this documentation.');
        $payload['_fhsis_services'][$key]['status']='void';
        $pdo->prepare('UPDATE `'.$table.'` SET `'.$field.'`=?,fhsis_verified_by_id=?,fhsis_verified_at=NOW() WHERE id=? AND resident_id=?')
            ->execute([json_encode($payload,JSON_THROW_ON_ERROR),(int)$staff['staff_id'],$sourceId,$residentId]);
        $pdo->prepare("INSERT INTO audit_logs (action,module_name,record_id,entity_type,entity_id,description,created_at) VALUES ('FHSIS service withdrawn','FHSIS',?,'resident',?,?,NOW())")
            ->execute([$sourceId,$residentId,json_encode(['entry'=>$key,'source_table'=>$table,'staff_id'=>(int)$staff['staff_id']])]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Convert existing automatic sources to the same identities as documentation so overlap is a union. */
function fhsisAutomaticFacts(array $sources,string $monthStart): array
{
    $facts=[]; $year=(int)substr($monthStart,0,4);
    $add=static function(array $row,string $date,string $key) use (&$facts): void {
        if (!isset(fhsisIndicators()[$key])) return;
        $facts[]=['indicator_key'=>$key,'resident_id'=>$row['resident_id'],'report_birthdate'=>$row['report_birthdate']??null,'report_sex'=>$row['report_sex']??'',
            'service_date'=>substr($date,0,10),'status'=>'verified','details_json'=>[]];
    };
    foreach ($sources['consultations']??[] as $row) foreach (fhsisNcdIndicatorCells([$row],$monthStart) as $cell=>$value) {
        if ($value>0 && preg_match('/^(N|AD)(\d+)$/',$cell,$match)) $add($row,$row['consultation_date'],'s5.'.($match[1]==='N'?'A':'Q').$match[2]);
    }
    foreach ($sources['vaccination_records']??[] as $row) {
        foreach (fhsisVaccineIndicatorCells([$row],$year) as $cell=>$value) if ($value>0 && preg_match('/^(N|AD)(\d+)$/',$cell,$match)) $add($row,$row['vaccination_date'],'s3.'.($match[1]==='N'?'A':'Q').$match[2]);
        $age=fhsisAge($row,'vaccination_date'); $name=strtoupper(trim((string)($row['vaccine_name']??'')));
        if (!fhsisRecorded($row) || !$age || $age->y<60) continue;
        if (preg_match('/^(?:PPV23|PPSV23|PNEUMOCOCCAL POLYSACCHARIDE)(?:\b|$)/',$name)) $add($row,$row['vaccination_date'],'s6.A6');
        if (preg_match('/^(?:INFLUENZA|FLU)(?:\b|$)/',$name)) $add($row,$row['vaccination_date'],'s6.A8');
    }
    foreach ($sources['vital_statistics']??[] as $row) {
        $issues=[];
        foreach (fhsisVitalIndicatorCells([$row],$issues) as $cell=>$value) if ($value>0 && preg_match('/^N(\d+)$/',$cell,$match)) $add($row,$row['event_date'],'s10.A'.$match[1]);
    }
    $visits=[];
    foreach ($sources['dental_records']??[] as $row) if (fhsisRecorded($row) && ($row['record_type']??'')==='examination' && !empty($row['resident_id']) && fhsisAge($row,'record_date')) $visits[$row['resident_id']][substr($row['record_date'],0,10)]=$row;
    foreach ($visits as $dates) {
        ksort($dates); $number=0;
        foreach ($dates as $date=>$row) {
            $number++; if ($number>2 || $date<$monthStart) continue;
            $age=fhsisAge($row,'record_date')->y;
            $r=$age>=60?26:($age>=20?23:($age>=10?20:($age>=5?17:($age>=1?14:null))));
            if ($r) $add($row,$date,'s4.'.($number===1?'A':'Q').$r);
        }
    }
    return $facts;
}

/** Aggregate access only; never query or decrypt the private laboratory records here. */
function fhsisReadScreeningCells(PDO $pdo,string $monthStart): array
{
    $stmt=$pdo->prepare("SELECT report_data FROM fhsis_reports WHERE report_type='FHSIS_SCREENING_AGGREGATE' AND reporting_period=?");
    $stmt->execute([substr($monthStart,0,7)]); $seen=[]; $groups=[];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $body=json_decode((string)$json,true);
        if (!is_array($body) || ($body['format']??'')!=='fhsis_screening_v1' || !is_array($body['contributions']??null)) throw new RuntimeException('The saved maternal-screening aggregate is invalid and needs review.');
        foreach ($body['contributions']??[] as $source=>$metrics) foreach ($metrics as $metric=>$entry) {
            $age=(int)($entry['age_group']??-1);
            if (!in_array($age,[0,1,2],true) || ($entry['status']??'')!=='verified' || empty($entry['subject_token'])) continue;
            $groups[$metric][$age]=true;
            if (!empty($entry['qualifies'])) $seen[$metric][$age][$entry['subject_token']]=true;
        }
    }
    $aggregates=[];
    foreach ($groups as $metric=>$ages) foreach ($ages as $age=>$unused) $aggregates[]=['metric'=>$metric,'age_group'=>$age,'total'=>count($seen[$metric][$age]??[])];
    return fhsisScreeningCells($aggregates);
}

function fhsisScreeningCells(array $aggregates): array
{
    $map=['syphilis_screened'=>['L','M','N',5,6], 'syphilis_reactive'=>['L','M','N',9,10], 'syphilis_treated'=>['L','M','N',13,14],
        'hiv_screened'=>['L','M','N',17,18], 'hiv_negative'=>['AB','AC','AD',4,5], 'hepb_screened'=>['AB','AC','AD',8,9], 'hepb_reactive'=>['AB','AC','AD',12,13]];
    $totals=[]; $cells=[];
    foreach ($aggregates as $r) {
        if (!isset($map[$r['metric']]) || !in_array((int)$r['age_group'],[0,1,2],true)) continue;
        $totals[$r['metric']][(int)$r['age_group']]=(int)$r['total'];
    }
    foreach ($totals as $metric=>$groups) {
        [$male,$female,$total,$totalRow,$ageRow]=$map[$metric];
        foreach (range(0,2) as $g) {
            $cells[$male.($ageRow+$g)]=0; $cells[$female.($ageRow+$g)]=$groups[$g]??0; $cells[$total.($ageRow+$g)]=$groups[$g]??0;
        }
        $cells[$male.$totalRow]=0; $cells[$female.$totalRow]=array_sum($groups); $cells[$total.$totalRow]=array_sum($groups);
    }
    return $cells;
}

/** Called only by the authorized private-laboratory save, within its transaction. */
function fhsisReportingKey(PDO $pdo): string
{
    $configured=function_exists('rhuEnv')?(string)rhuEnv('RHU_FHSIS_REPORTING_KEY',''):'';
    // Independent random pseudonymization key; does not rotate or reuse private-result encryption keys.
    $candidate=$configured!==''?hash('sha256',$configured):bin2hex(random_bytes(32));
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
    $stmt=$pdo->prepare($sql." INTO portal_settings (setting_key,setting_value) VALUES ('_private_fhsis_reporting_key',?)"); $stmt->execute([$candidate]);
    $stored=(string)$pdo->query("SELECT setting_value FROM portal_settings WHERE setting_key='_private_fhsis_reporting_key'")->fetchColumn();
    if ($configured!=='' && !hash_equals($stored,$candidate)) throw new RuntimeException('The configured reporting key differs from the installed key. Restore the existing key before approving contributions.');
    if (strlen($stored)!==64 || !ctype_xdigit($stored)) throw new RuntimeException('The installed reporting key is invalid. Restore it from the database backup.');
    return hex2bin($stored);
}

function fhsisApproveScreening(PDO $pdo,int $recordId,array $post,array $staff,string $secret,?callable $encrypt=null): void
{
    $staffId=(int)($staff['staff_id']??0);
    if (!$staffId || !rhuRecordStaffIsMedtech($staff)) throw new RuntimeException('Only the recording MedTech can approve private laboratory reporting.');
    $stmt=$pdo->prepare('SELECT t.*,r.birthdate,r.sex FROM confidential_lab_tests t JOIN residents r ON r.id=t.resident_id WHERE t.id=? AND t.created_by_staff_id=?');
    $stmt->execute([$recordId,$staffId]); $source=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) throw new RuntimeException('Private laboratory record is unavailable to this MedTech.');
    if (!$encrypt) throw new RuntimeException('Private reporting requires the authorized encryption workflow.');
    foreach (['fhsis_reporting_encrypted','fhsis_aggregate_approved','fhsis_approved_by_id','fhsis_approved_at'] as $column) if (!fhsisColumnExists($pdo,'confidential_lab_tests',$column)) throw new RuntimeException('Import the ALTER-only FHSIS schema before approving private reporting.');
    $test=['HIV Screening'=>'hiv','Syphilis Screening'=>'syphilis','Hepatitis B Screening'=>'hepb'][$source['test_type']]??null;
    if (!$test) throw new RuntimeException('Choose the exact HIV, syphilis or hepatitis B screening test for maternal reporting.');
    $result=(string)($post['fhsis_result_category']??'');
    if (!in_array($result,['completed','negative','reactive'],true) || empty($post['fhsis_screening_complete'])) throw new RuntimeException('Confirm the screening was actually completed and select its structured result category.');
    $date=substr((string)$source['specimen_date'],0,10); $day=fhsisDate($date);
    if (!$day || $date>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d')) throw new RuntimeException('A completed screening needs a valid actual specimen/test date.');
    $age=fhsisAge(['report_birthdate'=>$source['birthdate'],'date'=>$date],'date');
    if (!$age || $age->y<10 || $age->y>49 || !in_array(strtolower($source['sex']),['female','f'],true)) throw new RuntimeException('Maternal screening reporting requires a female resident aged 10–49 at screening.');
    $pregnancyId=(int)($post['fhsis_pregnancy_id']??0);
    $stmt=$pdo->prepare('SELECT * FROM pregnancy_records WHERE id=? AND resident_id=?'); $stmt->execute([$pregnancyId,$source['resident_id']]); $pregnancy=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pregnancy || empty($post['fhsis_pregnancy_confirmed'])) throw new RuntimeException('Link the pregnancy episode and confirm the resident was pregnant when screened.');
    if (!empty($pregnancy['last_menstrual_period']) && substr($pregnancy['last_menstrual_period'],0,10)>$date) throw new RuntimeException('Screening date precedes the pregnancy episode.');
    $sourceToken=hash_hmac('sha256','private-lab-source:'.$recordId,$secret);
    $subjectToken=hash_hmac('sha256','fhsis-maternal-subject:'.$source['resident_id'],$secret);
    $metrics=[$test.'_screened'=>[$date,1]];
    if ($test==='hiv' && $result!=='completed') $metrics['hiv_negative']=[$date,$result==='negative'?1:0];
    if ($test!=='hiv' && $result!=='completed') $metrics[$test.'_reactive']=[$date,$result==='reactive'?1:0];
    $treatment=trim((string)($post['fhsis_treatment_date']??''));
    if ($treatment!=='') {
        if ($test!=='syphilis' || $result!=='reactive' || !fhsisDate($treatment) || $treatment<$date || $treatment>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d') || trim((string)($post['fhsis_treatment_reference']??''))==='') throw new RuntimeException('Syphilis treatment requires a reactive screening, a valid actual treatment date and its clinical completion reference.');
        $metrics['syphilis_treated']=[$treatment,1];
    }
    $contributions=[];
    foreach ($metrics as $metric=>[$eventDate,$qualifies]) {
        $eventAge=fhsisAge(['report_birthdate'=>$source['birthdate'],'date'=>$eventDate],'date')->y;
        if ($eventAge>49) throw new RuntimeException('Treatment age is outside the maternal reporting age groups.');
        $contributions[substr($eventDate,0,7)][$metric]=['subject_token'=>$subjectToken,'qualifies'=>$qualifies,'age_group'=>$eventAge<15?0:($eventAge<20?1:2),'status'=>'verified'];
    }
    fhsisStoreScreeningContribution($pdo,$sourceToken,$contributions);
    $privatePayload=$encrypt(json_encode(['documentation'=>fhsisScreeningDocumentation($post),'contributions'=>$contributions],JSON_THROW_ON_ERROR));
    $pdo->prepare('UPDATE confidential_lab_tests SET fhsis_reporting_encrypted=?,fhsis_aggregate_approved=1,fhsis_approved_by_id=?,fhsis_approved_at=NOW() WHERE id=? AND created_by_staff_id=?')
        ->execute([$privatePayload,$staffId,$recordId,$staffId]);
    // Log private approval without copying patient details or results to ordinary audit logs.
    $pdo->prepare("INSERT INTO confidential_access_logs (staff_id,resident_id,record_id,action,accessed_at) VALUES (?,?,?,'Approved FHSIS aggregate contribution',NOW())")
        ->execute([$staffId,$source['resident_id'],$recordId]);
}

/** Serialize updates to monthly aggregate reports using the existing settings row. No new tables. */
function fhsisStoreScreeningContribution(PDO $pdo,string $sourceToken,array $contributions): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Aggregate approval must be saved with its private source in one transaction.');
    $lock=$pdo->query("SELECT setting_value FROM portal_settings WHERE setting_key='_private_fhsis_reporting_key'".fhsisLockSuffix($pdo));
    if (!$lock->fetchColumn()) throw new RuntimeException('Initialize the private reporting key before approving aggregates.');
    $stored=$pdo->query("SELECT id,reporting_period,report_data FROM fhsis_reports WHERE report_type='FHSIS_SCREENING_AGGREGATE'".fhsisLockSuffix($pdo))->fetchAll(PDO::FETCH_ASSOC);
    $months=[]; $ids=[];
    foreach ($stored as $report) {
        $month=$report['reporting_period']; $body=json_decode((string)$report['report_data'],true);
        if (!is_array($body) || ($body['format']??'')!=='fhsis_screening_v1') throw new RuntimeException('The saved screening aggregate needs administrator review.');
        if (isset($ids[$month])) throw new RuntimeException('Duplicate monthly screening aggregates need administrator review.');
        $ids[$month]=(int)$report['id']; $months[$month]=$body;
    }
    foreach ($months as &$body) unset($body['contributions'][$sourceToken]); unset($body);
    foreach ($contributions as $month=>$metrics) {
        if (!isset($months[$month])) $months[$month]=['format'=>'fhsis_screening_v1','contributions'=>[]];
        $months[$month]['contributions'][$sourceToken]=$metrics;
    }
    foreach ($months as $month=>$body) {
        $json=json_encode($body,JSON_THROW_ON_ERROR);
        if (isset($ids[$month])) $pdo->prepare('UPDATE fhsis_reports SET report_data=? WHERE id=? AND report_type=\'FHSIS_SCREENING_AGGREGATE\'')->execute([$json,$ids[$month]]);
        else $pdo->prepare("INSERT INTO fhsis_reports (report_type,reporting_period,report_month,report_year,report_data) VALUES ('FHSIS_SCREENING_AGGREGATE',?,?,?,?)")
            ->execute([$month,(int)substr($month,5,2),(int)substr($month,0,4),$json]);
    }
}

function fhsisWithdrawScreening(PDO $pdo,int $recordId,array $staff,string $secret): void
{
    $staffId=(int)($staff['staff_id']??0);
    if (!$staffId || !rhuRecordStaffIsMedtech($staff)) throw new RuntimeException('Only the recording MedTech can withdraw approval.');
    $stmt=$pdo->prepare('SELECT id FROM confidential_lab_tests WHERE id=? AND created_by_staff_id=?'); $stmt->execute([$recordId,$staffId]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Only the recording MedTech can withdraw this approval.');
    fhsisStoreScreeningContribution($pdo,hash_hmac('sha256','private-lab-source:'.$recordId,$secret),[]);
    $pdo->prepare('UPDATE confidential_lab_tests SET fhsis_aggregate_approved=0,fhsis_approved_by_id=?,fhsis_approved_at=NOW() WHERE id=? AND created_by_staff_id=?')->execute([$staffId,$recordId,$staffId]);
}

function fhsisScreeningDocumentation(array $post): string
{
    return 'Pregnancy record #'.(int)($post['fhsis_pregnancy_id']??0).'; completed screening finding: '.(string)($post['fhsis_result_category']??'not documented').
        '; pregnancy at screening verified: '.(!empty($post['fhsis_pregnancy_confirmed'])?'yes':'no').
        (!empty($post['fhsis_treatment_date'])?'; actual syphilis treatment completion: '.$post['fhsis_treatment_date'].'; clinical reference: '.(string)($post['fhsis_treatment_reference']??''):'');
}

function fhsisScreeningFormFields(): void
{
    echo '<details class="lg:col-span-2 rounded-xl border border-rose-200 bg-white p-4"><summary class="font-bold cursor-pointer">Maternal FHSIS reporting documentation</summary><p class="mt-2 text-xs text-slate-600">Optional, authorized aggregate reporting. Private names, source IDs, result text and notes are excluded from the report. Complete these fields only for actual screening during a documented pregnancy.</p>';
    echo '<label class="mt-3 block">Pregnancy record ID<input type="number" min="1" name="fhsis_pregnancy_id" class="w-full rounded border p-2"></label>';
    echo '<label class="mt-3 block">Structured screening finding<select name="fhsis_result_category" class="w-full rounded border p-2"><option value="">Not documented</option><option value="completed">Screening completed; finding not classified for reporting</option><option value="negative">Negative / non-reactive</option><option value="reactive">Reactive</option></select></label>';
    echo '<label class="mt-3 block"><input type="checkbox" name="fhsis_screening_complete" value="1"> Screening was actually performed and completed, not just requested or referred.</label><label class="mt-3 block"><input type="checkbox" name="fhsis_pregnancy_confirmed" value="1"> The linked episode confirms pregnancy on the screening date.</label>';
    echo '<label class="mt-3 block">Syphilis treatment completion date, if documented<input type="date" name="fhsis_treatment_date" class="w-full rounded border p-2"></label><label class="mt-3 block">Clinical treatment completion reference<input maxlength="500" name="fhsis_treatment_reference" class="w-full rounded border p-2" placeholder="Actual treatment record, not a prescription or referral"></label>';
    echo '<label class="mt-4 block font-bold"><input type="checkbox" name="fhsis_aggregate_approved" value="1"> I verified these facts and authorize their inclusion in FHSIS aggregate counts.</label></details>';
}

// Bundled definitions keep report generation independent of optional catalog-file uploads.
function fhsisEmbeddedIndicatorCatalog(): array
{
    return array (
  'version' => 1,
  'template_sha256' => '7ce4ced5c4be0fbce22687c5511691b60bea0f5017fcfcac9594b2fa8936e973',
  'indicators' => 
  array (
    's2.A6' => 
    array (
      'key' => 's2.A6',
      'sheet' => 2,
      'row' => 6,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'a. No. of women who delivered and completed at least 8ANC',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'minimum_visits' => 8,
    ),
    's2.A7' => 
    array (
      'key' => 's2.A7',
      'sheet' => 2,
      'row' => 7,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'a1. No. of women who delivered and provided 1st to 8th ANC on schedule (Resident)',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A6',
      ),
      'scheduled' => true,
      'minimum_visits' => 8,
    ),
    's2.A8' => 
    array (
      'key' => 's2.A8',
      'sheet' => 2,
      'row' => 8,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'a2. No. of women who delivered and completed at least 8ANC TRANS-IN from other LGUs',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A6',
      ),
      'minimum_visits' => 8,
    ),
    's2.A9' => 
    array (
      'key' => 's2.A9',
      'sheet' => 2,
      'row' => 9,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'b. No. of women who delivered and who were tracked during pregnancy',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A10' => 
    array (
      'key' => 's2.A10',
      'sheet' => 2,
      'row' => 10,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'b1. No. of women who delivered and who were tracked during pregnancy (Resident)',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A9',
      ),
    ),
    's2.A11' => 
    array (
      'key' => 's2.A11',
      'sheet' => 2,
      'row' => 11,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'b2. No. of TRANS-IN from other LGUs',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A9',
      ),
    ),
    's2.A12' => 
    array (
      'key' => 's2.A12',
      'sheet' => 2,
      'row' => 12,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'b3. No. of TRANS-OUT (with MOV) before completing 8ANC',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'minimum_visits' => 8,
    ),
    's2.A13' => 
    array (
      'key' => 's2.A13',
      'sheet' => 2,
      'row' => 13,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2. No. of pregnant women assessed for nutritional status during the first trimester',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A14' => 
    array (
      'key' => 's2.A14',
      'sheet' => 2,
      'row' => 14,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2a. Normal BMI',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A15' => 
    array (
      'key' => 's2.A15',
      'sheet' => 2,
      'row' => 15,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2b. Low BMI',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A16' => 
    array (
      'key' => 's2.A16',
      'sheet' => 2,
      'row' => 16,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2c. High BMI',
      'context' => '1. Antenatal Care',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A18' => 
    array (
      'key' => 's2.A18',
      'sheet' => 2,
      'row' => 18,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '3a. Number of women pregnant for the first time given at least 2 doses of Td vaccination',
      'context' => '3. Tetanus diphtheria (Td) Containing Vaccination Status',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A19' => 
    array (
      'key' => 's2.A19',
      'sheet' => 2,
      'row' => 19,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '3b. Number of pregnant women for the 2nd or more times given at least 3 doses of Td vaccination (Td2 Plus)',
      'context' => '3. Tetanus diphtheria (Td) Containing Vaccination Status',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A21' => 
    array (
      'key' => 's2.A21',
      'sheet' => 2,
      'row' => 21,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '1. Total Deliveries',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A22' => 
    array (
      'key' => 's2.A22',
      'sheet' => 2,
      'row' => 22,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2. No. of deliveries attended by Skilled Health Professionals (SHP)',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's11.A31',
      ),
    ),
    's2.A23' => 
    array (
      'key' => 's2.A23',
      'sheet' => 2,
      'row' => 23,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2a. Physicians',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A22',
      ),
    ),
    's2.A24' => 
    array (
      'key' => 's2.A24',
      'sheet' => 2,
      'row' => 24,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2b. Nurses',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A22',
      ),
    ),
    's2.A25' => 
    array (
      'key' => 's2.A25',
      'sheet' => 2,
      'row' => 25,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '2c. Midwives',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A22',
      ),
    ),
    's2.A26' => 
    array (
      'key' => 's2.A26',
      'sheet' => 2,
      'row' => 26,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '3. No. of Facility Based Deliveries (FBD)',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's11.A33',
      ),
    ),
    's2.A27' => 
    array (
      'key' => 's2.A27',
      'sheet' => 2,
      'row' => 27,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '3a. Public facility',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A26',
      ),
    ),
    's2.A28' => 
    array (
      'key' => 's2.A28',
      'sheet' => 2,
      'row' => 28,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '3b. Private facility',
      'context' => 'INTRAPARTUM AND NEWBORN CARE',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A26',
      ),
    ),
    's2.A30' => 
    array (
      'key' => 's2.A30',
      'sheet' => 2,
      'row' => 30,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '4a. No. of vaginal deliveries',
      'context' => '4. Delivery by Type',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A31' => 
    array (
      'key' => 's2.A31',
      'sheet' => 2,
      'row' => 31,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '4b. No. of Caesarian Section',
      'context' => '4. Delivery by Type',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A32' => 
    array (
      'key' => 's2.A32',
      'sheet' => 2,
      'row' => 32,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '4c. No. of Combined Vaginal-Caesarian deliveries',
      'context' => '4. Delivery by Type',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A35' => 
    array (
      'key' => 's2.A35',
      'sheet' => 2,
      'row' => 35,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '1a. Total No. of women who delivered and completed at least 4PNC',
      'context' => '1. PNC',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'minimum_visits' => 4,
    ),
    's2.A36' => 
    array (
      'key' => 's2.A36',
      'sheet' => 2,
      'row' => 36,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'a1. No. of women who delivered and provided 1st to 4th PNC on schedule (Resident)',
      'context' => '1. PNC',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A35',
      ),
      'scheduled' => true,
      'minimum_visits' => 4,
    ),
    's2.A37' => 
    array (
      'key' => 's2.A37',
      'sheet' => 2,
      'row' => 37,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'a2. No. of women delivered and completed at least 4PNC TRANS-IN from other LGUs',
      'context' => '1. PNC',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A35',
      ),
      'minimum_visits' => 4,
    ),
    's2.A38' => 
    array (
      'key' => 's2.A38',
      'sheet' => 2,
      'row' => 38,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => '1b. Total No. of women due for PNC (Birthers)',
      'context' => '1. PNC',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.A39' => 
    array (
      'key' => 's2.A39',
      'sheet' => 2,
      'row' => 39,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'b1. No. of women due for PNC (Resident)',
      'context' => '1. PNC',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A38',
      ),
    ),
    's2.A40' => 
    array (
      'key' => 's2.A40',
      'sheet' => 2,
      'row' => 40,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'b2. No. of TRANS-IN from other LGUs for PNC',
      'context' => '1. PNC',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's2.A38',
      ),
    ),
    's2.A41' => 
    array (
      'key' => 's2.A41',
      'sheet' => 2,
      'row' => 41,
      'side' => 'A',
      'section' => 'Maternal care',
      'label' => 'b3. No. of TRANS-OUT (with MOV) before completing 4PNC',
      'context' => '1. PNC',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
        3 => 'O',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'minimum_visits' => 4,
    ),
    's2.Q5' => 
    array (
      'key' => 's2.Q5',
      'sheet' => 2,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '4a. Number of pregnant women who completed the dose of Iron with Folic Acid supplementation',
      'context' => '4. Prenatal Supplementation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q6' => 
    array (
      'key' => 's2.Q6',
      'sheet' => 2,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '4b. No. of pregnant women who completed the dose of Multiple Micronutrient Supplementation',
      'context' => '4. Prenatal Supplementation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q7' => 
    array (
      'key' => 's2.Q7',
      'sheet' => 2,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '4c. No. of pregnant women who completed the dose of Calcium carbonate',
      'context' => '4. Prenatal Supplementation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q9' => 
    array (
      'key' => 's2.Q9',
      'sheet' => 2,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '5a. No. of pregnant women screened for Anemia',
      'context' => '5. Anemia Screening',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q10' => 
    array (
      'key' => 's2.Q10',
      'sheet' => 2,
      'row' => 10,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '5b. No. of pregnant women diagnosed with Anemia',
      'context' => '5. Anemia Screening',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q12' => 
    array (
      'key' => 's2.Q12',
      'sheet' => 2,
      'row' => 12,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '6a. No. of pregnant women screened for Gestational Diabetes Mellitus',
      'context' => '6. Gestational Diabetes Screening',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q13' => 
    array (
      'key' => 's2.Q13',
      'sheet' => 2,
      'row' => 13,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '6b. No. of pregnant women tested positive for Gestational Diabetes Mellitus',
      'context' => '6. Gestational Diabetes Screening',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q15' => 
    array (
      'key' => 's2.Q15',
      'sheet' => 2,
      'row' => 15,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '7a. No. of pregnant women given one dose of deworming tablet',
      'context' => '7. Deworming',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q17' => 
    array (
      'key' => 's2.Q17',
      'sheet' => 2,
      'row' => 17,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '8a. No. of pregnant women who had their BP measured during each of their antenatal care visit',
      'context' => '8. BP measurement',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q18' => 
    array (
      'key' => 's2.Q18',
      'sheet' => 2,
      'row' => 18,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '8b1. No. of pregnant women identified with high BP or danger signs',
      'context' => '8. BP measurement',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q19' => 
    array (
      'key' => 's2.Q19',
      'sheet' => 2,
      'row' => 19,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '8b2. No. of pregnant women with high BP or danger signs who were referred to a higher-level facility',
      'context' => '8. BP measurement',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q22' => 
    array (
      'key' => 's2.Q22',
      'sheet' => 2,
      'row' => 22,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '5a. No. of Full-Term deliveries',
      'context' => '5. Delivery by Outcome',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q23' => 
    array (
      'key' => 's2.Q23',
      'sheet' => 2,
      'row' => 23,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '5b. No. of Pre-Term deliveries',
      'context' => '5. Delivery by Outcome',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q24' => 
    array (
      'key' => 's2.Q24',
      'sheet' => 2,
      'row' => 24,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '5c. No. of Fetal deaths',
      'context' => '5. Delivery by Outcome',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'fetal_event',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_key' => true,
      'subject_sex' => false,
    ),
    's2.Q25' => 
    array (
      'key' => 's2.Q25',
      'sheet' => 2,
      'row' => 25,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '5d. No. of abortions/miscarriage (count only)',
      'context' => '5. Delivery by Outcome',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q28' => 
    array (
      'key' => 's2.Q28',
      'sheet' => 2,
      'row' => 28,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '6b. Normal birth weight',
      'context' => '6. No. of livebirths by birth weight',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'mother_link' => true,
      'maternal_age_groups' => true,
    ),
    's2.Q29' => 
    array (
      'key' => 's2.Q29',
      'sheet' => 2,
      'row' => 29,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '6c. Low birth weight',
      'context' => '6. No. of livebirths by birth weight',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'mother_link' => true,
      'maternal_age_groups' => true,
    ),
    's2.Q30' => 
    array (
      'key' => 's2.Q30',
      'sheet' => 2,
      'row' => 30,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '6d. Unknown birth weight',
      'context' => '6. No. of livebirths by birth weight',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'mother_link' => true,
      'maternal_age_groups' => true,
    ),
    's2.Q36' => 
    array (
      'key' => 's2.Q36',
      'sheet' => 2,
      'row' => 36,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '2a. Number of postpartum women who completed the dose of Iron with Folic Acid supplementation',
      'context' => '2. Postpartum Supplementation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q37' => 
    array (
      'key' => 's2.Q37',
      'sheet' => 2,
      'row' => 37,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '2b. Number of postpartum women who completed the dose of Vitamin A supplementation',
      'context' => '2. Postpartum Supplementation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q39' => 
    array (
      'key' => 's2.Q39',
      'sheet' => 2,
      'row' => 39,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '8a. No. of postpartum women who had their BP measured during each of their postnatal care visit',
      'context' => '2. BP measurement',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's2.Q40' => 
    array (
      'key' => 's2.Q40',
      'sheet' => 2,
      'row' => 40,
      'side' => 'Q',
      'section' => 'Maternal care',
      'label' => '8b. No. of postpartum women with high BP or danger signs who were referred to a higher-level facility',
      'context' => '2. BP measurement',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
        3 => 'AE',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A6' => 
    array (
      'key' => 's3.A6',
      'sheet' => 3,
      'row' => 6,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '1. Children protected at birth (CPAB)',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A7' => 
    array (
      'key' => 's3.A7',
      'sheet' => 3,
      'row' => 7,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '2. BCG (within 24 hours)',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A8' => 
    array (
      'key' => 's3.A8',
      'sheet' => 3,
      'row' => 8,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '3. BCG (24 hours to 11 months and 29 days)',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A9' => 
    array (
      'key' => 's3.A9',
      'sheet' => 3,
      'row' => 9,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '4. Hepa B given within 24 hrs after birth',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A10' => 
    array (
      'key' => 's3.A10',
      'sheet' => 3,
      'row' => 10,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '5. Hepa B given more than 24 hrs up to 14 days',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A11' => 
    array (
      'key' => 's3.A11',
      'sheet' => 3,
      'row' => 11,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '6. DPT-HiB-HepB 1',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A12' => 
    array (
      'key' => 's3.A12',
      'sheet' => 3,
      'row' => 12,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '7. DPT-HiB-HepB 2',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A13' => 
    array (
      'key' => 's3.A13',
      'sheet' => 3,
      'row' => 13,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '8. DPT-HiB-HepB 3',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A14' => 
    array (
      'key' => 's3.A14',
      'sheet' => 3,
      'row' => 14,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '9. OPV 1',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A15' => 
    array (
      'key' => 's3.A15',
      'sheet' => 3,
      'row' => 15,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '10. OPV 2',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A16' => 
    array (
      'key' => 's3.A16',
      'sheet' => 3,
      'row' => 16,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '11. OPV 3',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A17' => 
    array (
      'key' => 's3.A17',
      'sheet' => 3,
      'row' => 17,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '12. IPV 1',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A18' => 
    array (
      'key' => 's3.A18',
      'sheet' => 3,
      'row' => 18,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '13. IPV 2',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A19' => 
    array (
      'key' => 's3.A19',
      'sheet' => 3,
      'row' => 19,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '14. PCV 1',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A20' => 
    array (
      'key' => 's3.A20',
      'sheet' => 3,
      'row' => 20,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '15. PCV 2',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A21' => 
    array (
      'key' => 's3.A21',
      'sheet' => 3,
      'row' => 21,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '16. PCV 3',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A22' => 
    array (
      'key' => 's3.A22',
      'sheet' => 3,
      'row' => 22,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '17. MMR 1',
      'context' => 'A1. Immunization Services (0-11 months old of current year)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A24' => 
    array (
      'key' => 's3.A24',
      'sheet' => 3,
      'row' => 24,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '1. Grade 1 learners given Td',
      'context' => 'A3. School and Community-Based Immunization',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A25' => 
    array (
      'key' => 's3.A25',
      'sheet' => 3,
      'row' => 25,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '2. Grade 1 learners given MR',
      'context' => 'A3. School and Community-Based Immunization',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A26' => 
    array (
      'key' => 's3.A26',
      'sheet' => 3,
      'row' => 26,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '3. Grade 7 learners given Td',
      'context' => 'A3. School and Community-Based Immunization',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A27' => 
    array (
      'key' => 's3.A27',
      'sheet' => 3,
      'row' => 27,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '4. Grade 7 learners given MR',
      'context' => 'A3. School and Community-Based Immunization',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A29' => 
    array (
      'key' => 's3.A29',
      'sheet' => 3,
      'row' => 29,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '1. Newborns who were initiated on breastfeeding within 1 hour after birth',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A30' => 
    array (
      'key' => 's3.A30',
      'sheet' => 3,
      'row' => 30,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '2. Infants born with low birth weight (LBW) given complete iron supplements',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A31' => 
    array (
      'key' => 's3.A31',
      'sheet' => 3,
      'row' => 31,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '3a. Infants aged 6-11 months old who received 1 dose of Vitamin A supplementation',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 6,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A32' => 
    array (
      'key' => 's3.A32',
      'sheet' => 3,
      'row' => 32,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '3b. Children aged 12-59 months old who completed 2 doses of Vitamin A supplementation',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 12,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A33' => 
    array (
      'key' => 's3.A33',
      'sheet' => 3,
      'row' => 33,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '6. Children 0-59 months old SEEN during the reporting period at health facilities',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A34' => 
    array (
      'key' => 's3.A34',
      'sheet' => 3,
      'row' => 34,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '6a. Identified MAM',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A35' => 
    array (
      'key' => 's3.A35',
      'sheet' => 3,
      'row' => 35,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '6b. Identified SAM',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A36' => 
    array (
      'key' => 's3.A36',
      'sheet' => 3,
      'row' => 36,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '7. MAM enrolled to SFP',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A37' => 
    array (
      'key' => 's3.A37',
      'sheet' => 3,
      'row' => 37,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '7a. Cured',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A38' => 
    array (
      'key' => 's3.A38',
      'sheet' => 3,
      'row' => 38,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '7b. Non-cured',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.A39' => 
    array (
      'key' => 's3.A39',
      'sheet' => 3,
      'row' => 39,
      'side' => 'A',
      'section' => 'Child care',
      'label' => '7c. Defaulted',
      'context' => 'NUTRITION',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q5' => 
    array (
      'key' => 's3.Q5',
      'sheet' => 3,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '1. DPT-HiB-HepB 1',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q6' => 
    array (
      'key' => 's3.Q6',
      'sheet' => 3,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '2. DPT-HiB-HepB 2',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q7' => 
    array (
      'key' => 's3.Q7',
      'sheet' => 3,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '3. DPT-HiB-HepB 3',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q8' => 
    array (
      'key' => 's3.Q8',
      'sheet' => 3,
      'row' => 8,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '4. OPV 1',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q9' => 
    array (
      'key' => 's3.Q9',
      'sheet' => 3,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '5. OPV 2',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q10' => 
    array (
      'key' => 's3.Q10',
      'sheet' => 3,
      'row' => 10,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '6. OPV 3',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q11' => 
    array (
      'key' => 's3.Q11',
      'sheet' => 3,
      'row' => 11,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '7. IPV 1',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q12' => 
    array (
      'key' => 's3.Q12',
      'sheet' => 3,
      'row' => 12,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '8. IPV 2',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q13' => 
    array (
      'key' => 's3.Q13',
      'sheet' => 3,
      'row' => 13,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '9. PCV 1',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q14' => 
    array (
      'key' => 's3.Q14',
      'sheet' => 3,
      'row' => 14,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '10. PCV 2',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q15' => 
    array (
      'key' => 's3.Q15',
      'sheet' => 3,
      'row' => 15,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '11. PCV 3',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q16' => 
    array (
      'key' => 's3.Q16',
      'sheet' => 3,
      'row' => 16,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '12. MMR 1',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q17' => 
    array (
      'key' => 's3.Q17',
      'sheet' => 3,
      'row' => 17,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '13. MMR 2',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q18' => 
    array (
      'key' => 's3.Q18',
      'sheet' => 3,
      'row' => 18,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '14. FIC',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q19' => 
    array (
      'key' => 's3.Q19',
      'sheet' => 3,
      'row' => 19,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '15. CIC',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q22' => 
    array (
      'key' => 's3.Q22',
      'sheet' => 3,
      'row' => 22,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '5. HPV 1 (SB)',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q23' => 
    array (
      'key' => 's3.Q23',
      'sheet' => 3,
      'row' => 23,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '6. HPV 1 (CB)',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q24' => 
    array (
      'key' => 's3.Q24',
      'sheet' => 3,
      'row' => 24,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '7. HPV 2 (CB)',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q29' => 
    array (
      'key' => 's3.Q29',
      'sheet' => 3,
      'row' => 29,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '4a. Infants aged 6-11 months old who completed routine MNP supplementation',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 6,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q30' => 
    array (
      'key' => 's3.Q30',
      'sheet' => 3,
      'row' => 30,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '4b. Children aged 12-23 months old who completed routine MNP supplementation',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 12,
      'age_max' => 23,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q31' => 
    array (
      'key' => 's3.Q31',
      'sheet' => 3,
      'row' => 31,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '5a. Infants aged 6-11 months old who completed routine LNS-SQ supplementation',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 6,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q32' => 
    array (
      'key' => 's3.Q32',
      'sheet' => 3,
      'row' => 32,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '5b. Children aged 12-23 months old who completed routine LNS-SQ supplementation',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 12,
      'age_max' => 23,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q33' => 
    array (
      'key' => 's3.Q33',
      'sheet' => 3,
      'row' => 33,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '7d. Died',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q34' => 
    array (
      'key' => 's3.Q34',
      'sheet' => 3,
      'row' => 34,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '8. SAM without complication admitted to OTC',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q35' => 
    array (
      'key' => 's3.Q35',
      'sheet' => 3,
      'row' => 35,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '8a. Cured',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q36' => 
    array (
      'key' => 's3.Q36',
      'sheet' => 3,
      'row' => 36,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '8b. Non-cured',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q37' => 
    array (
      'key' => 's3.Q37',
      'sheet' => 3,
      'row' => 37,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '8c. Defaulted',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's3.Q38' => 
    array (
      'key' => 's3.Q38',
      'sheet' => 3,
      'row' => 38,
      'side' => 'Q',
      'section' => 'Child care',
      'label' => '8d. Died',
      'context' => 'A2. Immunization Services (0-11 months of previous year)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A5' => 
    array (
      'key' => 's4.A5',
      'sheet' => 4,
      'row' => 5,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '1a. Sick infants aged 6-11 months old seen',
      'context' => 'MANAGEMENT OF SICK',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 6,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A6' => 
    array (
      'key' => 's4.A6',
      'sheet' => 4,
      'row' => 6,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '1a1. Sick infants aged 6-11 months old who received Vitamin A capsule aside from routine supplementation',
      'context' => 'MANAGEMENT OF SICK',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 6,
      'age_max' => 11,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A7' => 
    array (
      'key' => 's4.A7',
      'sheet' => 4,
      'row' => 7,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '2. Sick children aged 12-59 months old seen',
      'context' => 'MANAGEMENT OF SICK',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 12,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A8' => 
    array (
      'key' => 's4.A8',
      'sheet' => 4,
      'row' => 8,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '2a. Sick children aged 12-59 months old who received Vitamin A capsule aside from routine supplementation',
      'context' => 'MANAGEMENT OF SICK',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 12,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A9' => 
    array (
      'key' => 's4.A9',
      'sheet' => 4,
      'row' => 9,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '3. Total diarrhea cases 0-59 months old seen',
      'context' => 'MANAGEMENT OF SICK',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A10' => 
    array (
      'key' => 's4.A10',
      'sheet' => 4,
      'row' => 10,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '3a. 0-59 months old with acute diarrhea who received ORS only',
      'context' => 'MANAGEMENT OF SICK',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A9',
      ),
    ),
    's4.A11' => 
    array (
      'key' => 's4.A11',
      'sheet' => 4,
      'row' => 11,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '3b. 0-59 months old with acute diarrhea who received ORS and Zinc drops/syrup',
      'context' => 'MANAGEMENT OF SICK',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A9',
      ),
    ),
    's4.A14' => 
    array (
      'key' => 's4.A14',
      'sheet' => 4,
      'row' => 14,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '1. Children 1-4 years old who had their 1st visit to an oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A15' => 
    array (
      'key' => 's4.A15',
      'sheet' => 4,
      'row' => 15,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '1a. Children 1-4 years old who had their 1st visit to a facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A14',
      ),
    ),
    's4.A16' => 
    array (
      'key' => 's4.A16',
      'sheet' => 4,
      'row' => 16,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '1b. Children 1-4 years old who had their 1st visit to a non-facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A14',
      ),
    ),
    's4.A17' => 
    array (
      'key' => 's4.A17',
      'sheet' => 4,
      'row' => 17,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '2. Children 5-9 years old who had their 1st visit to an oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 5,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A18' => 
    array (
      'key' => 's4.A18',
      'sheet' => 4,
      'row' => 18,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '2a. Children 5-9 years old who had their 1st visit to a facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 5,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A17',
      ),
    ),
    's4.A19' => 
    array (
      'key' => 's4.A19',
      'sheet' => 4,
      'row' => 19,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '2b. Children 5-9 years old who had their 1st visit to a non-facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 5,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A17',
      ),
    ),
    's4.A20' => 
    array (
      'key' => 's4.A20',
      'sheet' => 4,
      'row' => 20,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '3. Adolescents 10-19 years old who had their 1st visit to an oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A21' => 
    array (
      'key' => 's4.A21',
      'sheet' => 4,
      'row' => 21,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '3a. Adolescents 10-19 years old who had their 1st visit to a facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A20',
      ),
    ),
    's4.A22' => 
    array (
      'key' => 's4.A22',
      'sheet' => 4,
      'row' => 22,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '3b. Adolescents 10-19 years old who had their 1st visit to a non-facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A20',
      ),
    ),
    's4.A23' => 
    array (
      'key' => 's4.A23',
      'sheet' => 4,
      'row' => 23,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '4. Adults 20-59 years old who had their 1st visit to an oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A24' => 
    array (
      'key' => 's4.A24',
      'sheet' => 4,
      'row' => 24,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '4a. Adults 20-59 years old who had their 1st visit to a facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A23',
      ),
    ),
    's4.A25' => 
    array (
      'key' => 's4.A25',
      'sheet' => 4,
      'row' => 25,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '4b. Adults 20-59 years old who had their 1st visit to a non-facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A23',
      ),
    ),
    's4.A26' => 
    array (
      'key' => 's4.A26',
      'sheet' => 4,
      'row' => 26,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '5. Senior Citizens 60 years old and above who had their 1st visit to an oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A27' => 
    array (
      'key' => 's4.A27',
      'sheet' => 4,
      'row' => 27,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '5a. Senior Citizens 60 years old and above who had their 1st visit to a facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A26',
      ),
    ),
    's4.A28' => 
    array (
      'key' => 's4.A28',
      'sheet' => 4,
      'row' => 28,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '5b. Senior Citizens 60 years old and above who had their 1st visit to a non-facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A26',
      ),
    ),
    's4.A29' => 
    array (
      'key' => 's4.A29',
      'sheet' => 4,
      'row' => 29,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '6. Pregnant Women who had their 1st visit to an oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.A30' => 
    array (
      'key' => 's4.A30',
      'sheet' => 4,
      'row' => 30,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '6a. Pregnant Women who had their 1st visit to a facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A29',
      ),
    ),
    's4.A31' => 
    array (
      'key' => 's4.A31',
      'sheet' => 4,
      'row' => 31,
      'side' => 'A',
      'section' => 'Sick child and oral health',
      'label' => '6b. Pregnant Women who had their 1st visit to a non-facility-based oral health care professional within a year',
      'context' => 'FIRST VISIT TO ORAL HEALTH CARE PROFESSIONAL',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.A29',
      ),
    ),
    's4.Q4' => 
    array (
      'key' => 's4.Q4',
      'sheet' => 4,
      'row' => 4,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '4. Pneumonia cases 0-59 months old seen',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.Q5' => 
    array (
      'key' => 's4.Q5',
      'sheet' => 4,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '4a. 0-59 months old with pneumonia who received antibiotic treatment',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q4',
      ),
    ),
    's4.Q6' => 
    array (
      'key' => 's4.Q6',
      'sheet' => 4,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => 'a. Amoxicillin drops suspension',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q5',
      ),
    ),
    's4.Q7' => 
    array (
      'key' => 's4.Q7',
      'sheet' => 4,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => 'b. Amoxicillin tablet suspension',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q5',
      ),
    ),
    's4.Q8' => 
    array (
      'key' => 's4.Q8',
      'sheet' => 4,
      'row' => 8,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => 'c. Cotrimoxazole suspension',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q5',
      ),
    ),
    's4.Q9' => 
    array (
      'key' => 's4.Q9',
      'sheet' => 4,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => 'd. Other antibiotics',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 59,
      'age_unit' => 'months',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q5',
      ),
    ),
    's4.Q14' => 
    array (
      'key' => 's4.Q14',
      'sheet' => 4,
      'row' => 14,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '1. Children 1-4 years old who completed 2 visits to an oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.Q15' => 
    array (
      'key' => 's4.Q15',
      'sheet' => 4,
      'row' => 15,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '1a. Children 1-4 years old who completed 2 visits to a facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q14',
      ),
    ),
    's4.Q16' => 
    array (
      'key' => 's4.Q16',
      'sheet' => 4,
      'row' => 16,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '1b. Children 1-4 years old who completed 2 visits to a non-facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q14',
      ),
    ),
    's4.Q17' => 
    array (
      'key' => 's4.Q17',
      'sheet' => 4,
      'row' => 17,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '2. Children 5-9 years old who completed 2 visits to an oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 5,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.Q18' => 
    array (
      'key' => 's4.Q18',
      'sheet' => 4,
      'row' => 18,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '2a. Children 5-9 years old who completed 2 visits to a facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 5,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q17',
      ),
    ),
    's4.Q19' => 
    array (
      'key' => 's4.Q19',
      'sheet' => 4,
      'row' => 19,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '2b. Children 5-9 years old who completed 2 visits to a non-facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 5,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q17',
      ),
    ),
    's4.Q20' => 
    array (
      'key' => 's4.Q20',
      'sheet' => 4,
      'row' => 20,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '3. Adolescents 10-19 years old who completed 2 visits to an oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.Q21' => 
    array (
      'key' => 's4.Q21',
      'sheet' => 4,
      'row' => 21,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '3a. Adolescents 10-19 years old who completed 2 visits to a facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q20',
      ),
    ),
    's4.Q22' => 
    array (
      'key' => 's4.Q22',
      'sheet' => 4,
      'row' => 22,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '3b. Adolescents 10-19 years old who completed 2 visits to a non-facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q20',
      ),
    ),
    's4.Q23' => 
    array (
      'key' => 's4.Q23',
      'sheet' => 4,
      'row' => 23,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '4. Adults 20-59 years old who completed 2 visits to an oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.Q24' => 
    array (
      'key' => 's4.Q24',
      'sheet' => 4,
      'row' => 24,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '4a. Adults 20-59 years old who completed 2 visits to a facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q23',
      ),
    ),
    's4.Q25' => 
    array (
      'key' => 's4.Q25',
      'sheet' => 4,
      'row' => 25,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '4b. Adults 20-59 years old who completed 2 visits to a non-facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q23',
      ),
    ),
    's4.Q26' => 
    array (
      'key' => 's4.Q26',
      'sheet' => 4,
      'row' => 26,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '5. Senior Citizens 60 years old and above who completed 2 visits to an oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.Q27' => 
    array (
      'key' => 's4.Q27',
      'sheet' => 4,
      'row' => 27,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '5a. Senior Citizens 60 years old and above who completed 2 visits to a facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q26',
      ),
    ),
    's4.Q28' => 
    array (
      'key' => 's4.Q28',
      'sheet' => 4,
      'row' => 28,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '5b. Senior Citizens 60 years old and above who completed 2 visits to a non-facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q26',
      ),
    ),
    's4.Q29' => 
    array (
      'key' => 's4.Q29',
      'sheet' => 4,
      'row' => 29,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '6. Pregnant Women who completed 2 visits to an oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's4.Q30' => 
    array (
      'key' => 's4.Q30',
      'sheet' => 4,
      'row' => 30,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '6a. Pregnant Women who completed 2 visits to a facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q29',
      ),
    ),
    's4.Q31' => 
    array (
      'key' => 's4.Q31',
      'sheet' => 4,
      'row' => 31,
      'side' => 'Q',
      'section' => 'Sick child and oral health',
      'label' => '6b. Pregnant Women who completed 2 visits to a non-facility-based oral health care professional within a year',
      'context' => 'SECTION D. ORAL HEALTH CARE SERVICES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'DENTAL',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's4.Q29',
      ),
    ),
    's5.A5' => 
    array (
      'key' => 's5.A5',
      'sheet' => 5,
      'row' => 5,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '1. Adults 20-59 years old who were risk assessed using the PhilPEN protocol',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A6' => 
    array (
      'key' => 's5.A6',
      'sheet' => 5,
      'row' => 6,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Current Smokers',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A7' => 
    array (
      'key' => 's5.A7',
      'sheet' => 5,
      'row' => 7,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Tobacco Products',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A8' => 
    array (
      'key' => 's5.A8',
      'sheet' => 5,
      'row' => 8,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Vaporized Nicotine Products',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A9' => 
    array (
      'key' => 's5.A9',
      'sheet' => 5,
      'row' => 9,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Both',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A10' => 
    array (
      'key' => 's5.A10',
      'sheet' => 5,
      'row' => 10,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Provided Brief Tobacco Intervention',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A11' => 
    array (
      'key' => 's5.A11',
      'sheet' => 5,
      'row' => 11,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Binge Drinker',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A12' => 
    array (
      'key' => 's5.A12',
      'sheet' => 5,
      'row' => 12,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'd. Insufficient physical activities',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A13' => 
    array (
      'key' => 's5.A13',
      'sheet' => 5,
      'row' => 13,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'e. Consumed unhealthy diet',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A14' => 
    array (
      'key' => 's5.A14',
      'sheet' => 5,
      'row' => 14,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'f. Overweight',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A15' => 
    array (
      'key' => 's5.A15',
      'sheet' => 5,
      'row' => 15,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'g. Obese',
      'context' => 'E1. Lifestyle Related',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A17' => 
    array (
      'key' => 's5.A17',
      'sheet' => 5,
      'row' => 17,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old with hypertension (Sum of January to Previous Month)',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'period' => 'year_to_previous_month',
    ),
    's5.A18' => 
    array (
      'key' => 's5.A18',
      'sheet' => 5,
      'row' => 18,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old with hypertension in the current month',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A19' => 
    array (
      'key' => 's5.A19',
      'sheet' => 5,
      'row' => 19,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old identified with hypertension using the PhilPEN protocol',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A20' => 
    array (
      'key' => 's5.A20',
      'sheet' => 5,
      'row' => 20,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old provided with antihypertensive medications',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A21' => 
    array (
      'key' => 's5.A21',
      'sheet' => 5,
      'row' => 21,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Provided by facility (100%)',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A22' => 
    array (
      'key' => 's5.A22',
      'sheet' => 5,
      'row' => 22,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Out of pocket',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A23' => 
    array (
      'key' => 's5.A23',
      'sheet' => 5,
      'row' => 23,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Both',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A24' => 
    array (
      'key' => 's5.A24',
      'sheet' => 5,
      'row' => 24,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above with hypertension (Sum of January to Previous Month)',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'period' => 'year_to_previous_month',
    ),
    's5.A25' => 
    array (
      'key' => 's5.A25',
      'sheet' => 5,
      'row' => 25,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above with hypertension in the current month',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A26' => 
    array (
      'key' => 's5.A26',
      'sheet' => 5,
      'row' => 26,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above identified with hypertension using the PhilPEN protocol',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A27' => 
    array (
      'key' => 's5.A27',
      'sheet' => 5,
      'row' => 27,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above provided with antihypertensive medications',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A28' => 
    array (
      'key' => 's5.A28',
      'sheet' => 5,
      'row' => 28,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Provided by facility (100%)',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A29' => 
    array (
      'key' => 's5.A29',
      'sheet' => 5,
      'row' => 29,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Out of pocket',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A30' => 
    array (
      'key' => 's5.A30',
      'sheet' => 5,
      'row' => 30,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Both',
      'context' => 'E2. Cardiovascular Disease Prevention and Control',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A32' => 
    array (
      'key' => 's5.A32',
      'sheet' => 5,
      'row' => 32,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '1. Screened for eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A33' => 
    array (
      'key' => 's5.A33',
      'sheet' => 5,
      'row' => 33,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '1a. 0-9 years old screened for eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A34' => 
    array (
      'key' => 's5.A34',
      'sheet' => 5,
      'row' => 34,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '1b. 10-19 years old screened for eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A35' => 
    array (
      'key' => 's5.A35',
      'sheet' => 5,
      'row' => 35,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '1c. 20-59 years old screened for eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A36' => 
    array (
      'key' => 's5.A36',
      'sheet' => 5,
      'row' => 36,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '1d. 60 years old and above screened for eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A37' => 
    array (
      'key' => 's5.A37',
      'sheet' => 5,
      'row' => 37,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2. Screened and identified with eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A38' => 
    array (
      'key' => 's5.A38',
      'sheet' => 5,
      'row' => 38,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2a. 0-9 years old screened and identified with at least one eye ailment',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A39' => 
    array (
      'key' => 's5.A39',
      'sheet' => 5,
      'row' => 39,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2a1. Changes in vision',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A40' => 
    array (
      'key' => 's5.A40',
      'sheet' => 5,
      'row' => 40,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2a2. Changes in appearance',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A41' => 
    array (
      'key' => 's5.A41',
      'sheet' => 5,
      'row' => 41,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2a3. Eye and orbital injury',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A42' => 
    array (
      'key' => 's5.A42',
      'sheet' => 5,
      'row' => 42,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2a4. Routine eye exams',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A43' => 
    array (
      'key' => 's5.A43',
      'sheet' => 5,
      'row' => 43,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2b. 10-19 years old screened and identified with at least one eye ailment',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A44' => 
    array (
      'key' => 's5.A44',
      'sheet' => 5,
      'row' => 44,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2b1. Changes in vision',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A45' => 
    array (
      'key' => 's5.A45',
      'sheet' => 5,
      'row' => 45,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2b2. Changes in appearance',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A46' => 
    array (
      'key' => 's5.A46',
      'sheet' => 5,
      'row' => 46,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2b3. Eye and orbital injury',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.A47' => 
    array (
      'key' => 's5.A47',
      'sheet' => 5,
      'row' => 47,
      'side' => 'A',
      'section' => 'Noncommunicable diseases',
      'label' => '2b4. Routine eye exams',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q4' => 
    array (
      'key' => 's5.Q4',
      'sheet' => 5,
      'row' => 4,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2. Senior Citizens 60 years old and above who were risk assessed using the PhilPEN protocol',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q5' => 
    array (
      'key' => 's5.Q5',
      'sheet' => 5,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Current Smokers',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q6' => 
    array (
      'key' => 's5.Q6',
      'sheet' => 5,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Tobacco Products',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q7' => 
    array (
      'key' => 's5.Q7',
      'sheet' => 5,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Vaporized Nicotine Products',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q8' => 
    array (
      'key' => 's5.Q8',
      'sheet' => 5,
      'row' => 8,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Both',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q9' => 
    array (
      'key' => 's5.Q9',
      'sheet' => 5,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Provided Brief Tobacco Intervention',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q10' => 
    array (
      'key' => 's5.Q10',
      'sheet' => 5,
      'row' => 10,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Binge Drinker',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q11' => 
    array (
      'key' => 's5.Q11',
      'sheet' => 5,
      'row' => 11,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'd. Insufficient physical activities',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q12' => 
    array (
      'key' => 's5.Q12',
      'sheet' => 5,
      'row' => 12,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'e. Consumed unhealthy diet',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q13' => 
    array (
      'key' => 's5.Q13',
      'sheet' => 5,
      'row' => 13,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'f. Overweight',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q14' => 
    array (
      'key' => 's5.Q14',
      'sheet' => 5,
      'row' => 14,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'g. Obese',
      'context' => 'SECTION E. NON-COMMUNICABLE DISEASES',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q16' => 
    array (
      'key' => 's5.Q16',
      'sheet' => 5,
      'row' => 16,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old with Type II Diabetes (Sum of January to Previous Month)',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'period' => 'year_to_previous_month',
    ),
    's5.Q17' => 
    array (
      'key' => 's5.Q17',
      'sheet' => 5,
      'row' => 17,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old with Type II Diabetes in the current month',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q18' => 
    array (
      'key' => 's5.Q18',
      'sheet' => 5,
      'row' => 18,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old identified with Type II Diabetes using the PhilPEN protocol',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q19' => 
    array (
      'key' => 's5.Q19',
      'sheet' => 5,
      'row' => 19,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Adults 20-59 years old provided with antidiabetic medications',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q20' => 
    array (
      'key' => 's5.Q20',
      'sheet' => 5,
      'row' => 20,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Provided by facility (100%)',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q21' => 
    array (
      'key' => 's5.Q21',
      'sheet' => 5,
      'row' => 21,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Out of pocket',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q22' => 
    array (
      'key' => 's5.Q22',
      'sheet' => 5,
      'row' => 22,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Both',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q23' => 
    array (
      'key' => 's5.Q23',
      'sheet' => 5,
      'row' => 23,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above with Type II Diabetes (Sum of January to Previous Month)',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'period' => 'year_to_previous_month',
    ),
    's5.Q24' => 
    array (
      'key' => 's5.Q24',
      'sheet' => 5,
      'row' => 24,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above with Type II Diabetes in the current month',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q25' => 
    array (
      'key' => 's5.Q25',
      'sheet' => 5,
      'row' => 25,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above identified with Type II Diabetes using the PhilPEN protocol',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q26' => 
    array (
      'key' => 's5.Q26',
      'sheet' => 5,
      'row' => 26,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'Senior Citizens 60 years old and above provided with antidiabetic medications',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q27' => 
    array (
      'key' => 's5.Q27',
      'sheet' => 5,
      'row' => 27,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'a. Provided by facility (100%)',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q28' => 
    array (
      'key' => 's5.Q28',
      'sheet' => 5,
      'row' => 28,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'b. Out of pocket',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q29' => 
    array (
      'key' => 's5.Q29',
      'sheet' => 5,
      'row' => 29,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => 'c. Both',
      'context' => 'E3. Diabetes Mellitus Prevention and Control',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q32' => 
    array (
      'key' => 's5.Q32',
      'sheet' => 5,
      'row' => 32,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2c. 20-59 years old screened and identified with eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q33' => 
    array (
      'key' => 's5.Q33',
      'sheet' => 5,
      'row' => 33,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2c1. Changes in vision',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q34' => 
    array (
      'key' => 's5.Q34',
      'sheet' => 5,
      'row' => 34,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2c2. Changes in appearance',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q35' => 
    array (
      'key' => 's5.Q35',
      'sheet' => 5,
      'row' => 35,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2c3. Eye and orbital injury',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q36' => 
    array (
      'key' => 's5.Q36',
      'sheet' => 5,
      'row' => 36,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2c4. Routine eye exams',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q37' => 
    array (
      'key' => 's5.Q37',
      'sheet' => 5,
      'row' => 37,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2d. 60 years old and above screened and identified with eye diseases',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q38' => 
    array (
      'key' => 's5.Q38',
      'sheet' => 5,
      'row' => 38,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2d1. Changes in vision',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q39' => 
    array (
      'key' => 's5.Q39',
      'sheet' => 5,
      'row' => 39,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2d2. Changes in appearance',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q40' => 
    array (
      'key' => 's5.Q40',
      'sheet' => 5,
      'row' => 40,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2d3. Eye and orbital injury',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q41' => 
    array (
      'key' => 's5.Q41',
      'sheet' => 5,
      'row' => 41,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '2d4. Routine eye exams',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q42' => 
    array (
      'key' => 's5.Q42',
      'sheet' => 5,
      'row' => 42,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '3. Identified with eye diseases and referred to an eye health professional',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q43' => 
    array (
      'key' => 's5.Q43',
      'sheet' => 5,
      'row' => 43,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '3a. 0-9 years old',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 9,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q44' => 
    array (
      'key' => 's5.Q44',
      'sheet' => 5,
      'row' => 44,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '3b. 10-19 years old',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q45' => 
    array (
      'key' => 's5.Q45',
      'sheet' => 5,
      'row' => 45,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '3c. 20-59 years old',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's5.Q46' => 
    array (
      'key' => 's5.Q46',
      'sheet' => 5,
      'row' => 46,
      'side' => 'Q',
      'section' => 'Noncommunicable diseases',
      'label' => '3d. Senior Citizens',
      'context' => 'E4. Blindness Prevention Program',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A5' => 
    array (
      'key' => 's6.A5',
      'sheet' => 6,
      'row' => 5,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => '1. Senior Citizens Seen who had not previously received PPV upon reaching 60 years old',
      'context' => 'E5. Immunization for Senior Citizens',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A6' => 
    array (
      'key' => 's6.A6',
      'sheet' => 6,
      'row' => 6,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => '2. Senior Citizens aged 60 years old and above who received one (1) dose of Pneumococcal Polysaccharide Vaccine',
      'context' => 'E5. Immunization for Senior Citizens',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A7' => 
    array (
      'key' => 's6.A7',
      'sheet' => 6,
      'row' => 7,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => '3. Senior Citizens Seen',
      'context' => 'E5. Immunization for Senior Citizens',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A8' => 
    array (
      'key' => 's6.A8',
      'sheet' => 6,
      'row' => 8,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => '4. Senior Citizens aged 60 years old and above who received one (1) dose of Influenza Vaccine',
      'context' => 'E5. Immunization for Senior Citizens',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A10' => 
    array (
      'key' => 's6.A10',
      'sheet' => 6,
      'row' => 10,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'a. Senior Citizens screened using the geriatric screening tool',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A11' => 
    array (
      'key' => 's6.A11',
      'sheet' => 6,
      'row' => 11,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b. Senior Citizens with positive geriatric screening result',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A12' => 
    array (
      'key' => 's6.A12',
      'sheet' => 6,
      'row' => 12,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b1. Memory',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A13' => 
    array (
      'key' => 's6.A13',
      'sheet' => 6,
      'row' => 13,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b2. Depression',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A14' => 
    array (
      'key' => 's6.A14',
      'sheet' => 6,
      'row' => 14,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b3. Polypharmacy',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A15' => 
    array (
      'key' => 's6.A15',
      'sheet' => 6,
      'row' => 15,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b4. Urinary Incontinence',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A16' => 
    array (
      'key' => 's6.A16',
      'sheet' => 6,
      'row' => 16,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b5. Functional Capacity',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A17' => 
    array (
      'key' => 's6.A17',
      'sheet' => 6,
      'row' => 17,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b6. Fall History and Screening Test',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A18' => 
    array (
      'key' => 's6.A18',
      'sheet' => 6,
      'row' => 18,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b7. Malnutrition',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A19' => 
    array (
      'key' => 's6.A19',
      'sheet' => 6,
      'row' => 19,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b8. Hearing',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A20' => 
    array (
      'key' => 's6.A20',
      'sheet' => 6,
      'row' => 20,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'b9. Vision',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A21' => 
    array (
      'key' => 's6.A21',
      'sheet' => 6,
      'row' => 21,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => 'c. Referred to Appropriate Specialist or Service Providers',
      'context' => 'E6. Geriatric Screening',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.A23' => 
    array (
      'key' => 's6.A23',
      'sheet' => 6,
      'row' => 23,
      'side' => 'A',
      'section' => 'Older adults, mental health and cancer',
      'label' => '1. Individuals with mental health concern screened using the Mental Health Gap Action Programme (mhGAP)',
      'context' => 'E7. Mental Health',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q5' => 
    array (
      'key' => 's6.Q5',
      'sheet' => 6,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '1. Women aged 30-65 years old screened or assessed for cervical cancer',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q6' => 
    array (
      'key' => 's6.Q6',
      'sheet' => 6,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '1a. VIA',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q7' => 
    array (
      'key' => 's6.Q7',
      'sheet' => 6,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '1b. Papsmear',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q8' => 
    array (
      'key' => 's6.Q8',
      'sheet' => 6,
      'row' => 8,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '1c. HPV DNA',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q9' => 
    array (
      'key' => 's6.Q9',
      'sheet' => 6,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '2. Referred (Only)',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q10' => 
    array (
      'key' => 's6.Q10',
      'sheet' => 6,
      'row' => 10,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '3. Women aged 30-65 years old found suspicious for cervical cancer and linked to care',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q11' => 
    array (
      'key' => 's6.Q11',
      'sheet' => 6,
      'row' => 11,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '3a. Treated',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q12' => 
    array (
      'key' => 's6.Q12',
      'sheet' => 6,
      'row' => 12,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '3b. Referred',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q13' => 
    array (
      'key' => 's6.Q13',
      'sheet' => 6,
      'row' => 13,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '4. Women aged 30-65 years old found positive for precancerous lesions',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q14' => 
    array (
      'key' => 's6.Q14',
      'sheet' => 6,
      'row' => 14,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '5. Women aged 30-65 years old found positive for precancerous lesions and linked to care',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q15' => 
    array (
      'key' => 's6.Q15',
      'sheet' => 6,
      'row' => 15,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '5a. Treated',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q16' => 
    array (
      'key' => 's6.Q16',
      'sheet' => 6,
      'row' => 16,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '5b. Referred',
      'context' => 'E8. Cervical Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 65,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q18' => 
    array (
      'key' => 's6.Q18',
      'sheet' => 6,
      'row' => 18,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '1. Number of 30-69 years old women seen',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q19' => 
    array (
      'key' => 's6.Q19',
      'sheet' => 6,
      'row' => 19,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '2. Number of high-risk or symptomatic women',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q20' => 
    array (
      'key' => 's6.Q20',
      'sheet' => 6,
      'row' => 20,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '3. High-risk or symptomatic women aged 30-69 years old provided with Breast Cancer Early Detection Services',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q21' => 
    array (
      'key' => 's6.Q21',
      'sheet' => 6,
      'row' => 21,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '3a. Clinical Breast Examination',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q22' => 
    array (
      'key' => 's6.Q22',
      'sheet' => 6,
      'row' => 22,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '3b. Mammogram',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q23' => 
    array (
      'key' => 's6.Q23',
      'sheet' => 6,
      'row' => 23,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '4. High-risk or symptomatic women aged 30-69 years old found with normal/benign or equivocal results',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q24' => 
    array (
      'key' => 's6.Q24',
      'sheet' => 6,
      'row' => 24,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '4a. Clinical Breast Examination',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q25' => 
    array (
      'key' => 's6.Q25',
      'sheet' => 6,
      'row' => 25,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '4b. Mammogram',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q26' => 
    array (
      'key' => 's6.Q26',
      'sheet' => 6,
      'row' => 26,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '5. High-risk or symptomatic women aged 30-69 years old found with remarkable results and linked to care',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q27' => 
    array (
      'key' => 's6.Q27',
      'sheet' => 6,
      'row' => 27,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '5a. Clinical Breast Examination',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q28' => 
    array (
      'key' => 's6.Q28',
      'sheet' => 6,
      'row' => 28,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '5b. Mammogram',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q29' => 
    array (
      'key' => 's6.Q29',
      'sheet' => 6,
      'row' => 29,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '6. Asymptomatic women aged 50-69 years old screened for breast cancer',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 50,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q30' => 
    array (
      'key' => 's6.Q30',
      'sheet' => 6,
      'row' => 30,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '6a. Clinical Breast Examination',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q31' => 
    array (
      'key' => 's6.Q31',
      'sheet' => 6,
      'row' => 31,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '6b. Mammogram',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q32' => 
    array (
      'key' => 's6.Q32',
      'sheet' => 6,
      'row' => 32,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '7. Asymptomatic women aged 50-69 years old screened for breast cancer and found with remarkable or abnormal results',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 50,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q33' => 
    array (
      'key' => 's6.Q33',
      'sheet' => 6,
      'row' => 33,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '7a. Clinical Breast Examination',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's6.Q34' => 
    array (
      'key' => 's6.Q34',
      'sheet' => 6,
      'row' => 34,
      'side' => 'Q',
      'section' => 'Older adults, mental health and cancer',
      'label' => '7b. Mammogram',
      'context' => 'E9. Breast Cancer Prevention and Control Services',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 30,
      'age_max' => 69,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A6' => 
    array (
      'key' => 's7.A6',
      'sheet' => 7,
      'row' => 6,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1. HH with access to improved water supply - Total',
      'context' => 'F. Households (HHs)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A7' => 
    array (
      'key' => 's7.A7',
      'sheet' => 7,
      'row' => 7,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1a. HH with Level I',
      'context' => 'F. Households (HHs)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A6',
      ),
    ),
    's7.A8' => 
    array (
      'key' => 's7.A8',
      'sheet' => 7,
      'row' => 8,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1b. HH with Level II',
      'context' => 'F. Households (HHs)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A6',
      ),
    ),
    's7.A9' => 
    array (
      'key' => 's7.A9',
      'sheet' => 7,
      'row' => 9,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1c. HH with Level III',
      'context' => 'F. Households (HHs)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A6',
      ),
    ),
    's7.A10' => 
    array (
      'key' => 's7.A10',
      'sheet' => 7,
      'row' => 10,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '2. HH using safely managed drinking water services',
      'context' => 'F. Households (HHs)',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A14' => 
    array (
      'key' => 's7.A14',
      'sheet' => 7,
      'row' => 14,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1. No. of individuals examined for lymphatic filariasis',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A15' => 
    array (
      'key' => 's7.A15',
      'sheet' => 7,
      'row' => 15,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1a. Nocturnal Blood Examination (NBE)',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A14',
      ),
    ),
    's7.A16' => 
    array (
      'key' => 's7.A16',
      'sheet' => 7,
      'row' => 16,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1b. Rapid Diagnostic Test (RDT)',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A14',
      ),
    ),
    's7.A17' => 
    array (
      'key' => 's7.A17',
      'sheet' => 7,
      'row' => 17,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1c. Total no. of individuals examined for lymphoedema through NBE and RDT',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A18' => 
    array (
      'key' => 's7.A18',
      'sheet' => 7,
      'row' => 18,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '2. No. of individuals found positive for lymphatic filariasis',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A19' => 
    array (
      'key' => 's7.A19',
      'sheet' => 7,
      'row' => 19,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '2a. Nocturnal Blood Examination (NBE)',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A18',
      ),
    ),
    's7.A20' => 
    array (
      'key' => 's7.A20',
      'sheet' => 7,
      'row' => 20,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '2b. Rapid Diagnostic Test (RDT)',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A18',
      ),
    ),
    's7.A21' => 
    array (
      'key' => 's7.A21',
      'sheet' => 7,
      'row' => 21,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '2c. Total no. of individuals found positive for lymphoedema through NBE and RDT',
      'context' => '1. Filariasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A23' => 
    array (
      'key' => 's7.A23',
      'sheet' => 7,
      'row' => 23,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '3a. 1-4 years old',
      'context' => '3. Lymphoedema',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A26',
      ),
    ),
    's7.A24' => 
    array (
      'key' => 's7.A24',
      'sheet' => 7,
      'row' => 24,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '3b. 5-14 years old',
      'context' => '3. Lymphoedema',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A26',
      ),
    ),
    's7.A25' => 
    array (
      'key' => 's7.A25',
      'sheet' => 7,
      'row' => 25,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '3c. 15 years old and above',
      'context' => '3. Lymphoedema',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A26',
      ),
    ),
    's7.A26' => 
    array (
      'key' => 's7.A26',
      'sheet' => 7,
      'row' => 26,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '3d. Total no. of individuals aged 2 yrs old and above examined for the 1st time with lymphoedema',
      'context' => '3. Lymphoedema',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 2,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A28' => 
    array (
      'key' => 's7.A28',
      'sheet' => 7,
      'row' => 28,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '4a. 1-4 years old',
      'context' => '4. Elephantiasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A31',
      ),
    ),
    's7.A29' => 
    array (
      'key' => 's7.A29',
      'sheet' => 7,
      'row' => 29,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '4b. 5-14 years old',
      'context' => '4. Elephantiasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A31',
      ),
    ),
    's7.A30' => 
    array (
      'key' => 's7.A30',
      'sheet' => 7,
      'row' => 30,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '4c. 15 years old and above',
      'context' => '4. Elephantiasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.A31',
      ),
    ),
    's7.A31' => 
    array (
      'key' => 's7.A31',
      'sheet' => 7,
      'row' => 31,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '4d. Total no. of individuals aged 2 yrs old and above examined for the 1st time with Elephantiasis',
      'context' => '4. Elephantiasis',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 2,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A34' => 
    array (
      'key' => 's7.A34',
      'sheet' => 7,
      'row' => 34,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1a. No. of Category I Rabies exposure',
      'context' => '1. Category I Rabies-exposure',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A36' => 
    array (
      'key' => 's7.A36',
      'sheet' => 7,
      'row' => 36,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '2a. No. of Category II Rabies exposure eligible for Anti-Rabies Vaccine (ARV)',
      'context' => '2. Category II Rabies-exposure',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A37' => 
    array (
      'key' => 's7.A37',
      'sheet' => 7,
      'row' => 37,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '2b. No. of Category II Rabies exposure who received complete dose of Anti-Rabies Vaccine (ARV)',
      'context' => '2. Category II Rabies-exposure',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.A39' => 
    array (
      'key' => 's7.A39',
      'sheet' => 7,
      'row' => 39,
      'side' => 'A',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '3a. No. of Category III Rabies exposure',
      'context' => '3. Category III Rabies-exposure',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q5' => 
    array (
      'key' => 's7.Q5',
      'sheet' => 7,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1. HH with basic sanitation facility - Total',
      'context' => 'G1. Sanitation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q6' => 
    array (
      'key' => 's7.Q6',
      'sheet' => 7,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1a. HH with pour/flush toilet connected to septic tank',
      'context' => 'G1. Sanitation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q5',
      ),
    ),
    's7.Q7' => 
    array (
      'key' => 's7.Q7',
      'sheet' => 7,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1b. HH with pour/flush toilet connected to community sewer/sewerage system or any other',
      'context' => 'G1. Sanitation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q5',
      ),
    ),
    's7.Q8' => 
    array (
      'key' => 's7.Q8',
      'sheet' => 7,
      'row' => 8,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1c. HH with Ventilated Improved Pit (VIP) Latrine',
      'context' => 'G1. Sanitation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q5',
      ),
    ),
    's7.Q9' => 
    array (
      'key' => 's7.Q9',
      'sheet' => 7,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '1d. HH using safely managed sanitation service',
      'context' => 'G1. Sanitation',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'household',
      'roles' => 
      array (
        0 => 'SANITARY_INSPECTOR',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q13' => 
    array (
      'key' => 's7.Q13',
      'sheet' => 7,
      'row' => 13,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '5a. 2-4 years old',
      'context' => '5. Hydrocoele',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 2,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q16',
      ),
    ),
    's7.Q14' => 
    array (
      'key' => 's7.Q14',
      'sheet' => 7,
      'row' => 14,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '5b. 5-14 years old',
      'context' => '5. Hydrocoele',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q16',
      ),
    ),
    's7.Q15' => 
    array (
      'key' => 's7.Q15',
      'sheet' => 7,
      'row' => 15,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '5c. 15 years old and above',
      'context' => '5. Hydrocoele',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q16',
      ),
    ),
    's7.Q16' => 
    array (
      'key' => 's7.Q16',
      'sheet' => 7,
      'row' => 16,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '5d. Total no. of individuals aged 2 yrs old and above examined for the 1st time with Hydrocoele',
      'context' => '5. Hydrocoele',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 2,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q18' => 
    array (
      'key' => 's7.Q18',
      'sheet' => 7,
      'row' => 18,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6a. 2-4 years old',
      'context' => '6. Number of individuals who received MDA Administration',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 2,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q21',
      ),
    ),
    's7.Q19' => 
    array (
      'key' => 's7.Q19',
      'sheet' => 7,
      'row' => 19,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6b. 5-14 years old',
      'context' => '6. Number of individuals who received MDA Administration',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q21',
      ),
    ),
    's7.Q20' => 
    array (
      'key' => 's7.Q20',
      'sheet' => 7,
      'row' => 20,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6c. 15 years old and above',
      'context' => '6. Number of individuals who received MDA Administration',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's7.Q21',
      ),
    ),
    's7.Q21' => 
    array (
      'key' => 's7.Q21',
      'sheet' => 7,
      'row' => 21,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6d. Total no. of individuals aged 2 yrs old and above who received MDA',
      'context' => '6. Number of individuals who received MDA Administration',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 2,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q33' => 
    array (
      'key' => 's7.Q33',
      'sheet' => 7,
      'row' => 33,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6a. No. of Category III Rabies exposure who received complete dose of ARV and RIG',
      'context' => '6. Category III Rabies-exposure who received complete dose of Anti-Rabies Vaccine (ARV) and Single Dose of Rabies Immunoglobulin (RIG)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q34' => 
    array (
      'key' => 's7.Q34',
      'sheet' => 7,
      'row' => 34,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6b. No. of Category III Rabies exposure who received complete dose of anti-rabies vaccine (Booster)',
      'context' => '6. Category III Rabies-exposure who received complete dose of Anti-Rabies Vaccine (ARV) and Single Dose of Rabies Immunoglobulin (RIG)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q35' => 
    array (
      'key' => 's7.Q35',
      'sheet' => 7,
      'row' => 35,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6c. No. of Category III rabies exposure WITHOUT history of complete anti-rabies vaccine',
      'context' => '6. Category III Rabies-exposure who received complete dose of Anti-Rabies Vaccine (ARV) and Single Dose of Rabies Immunoglobulin (RIG)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q36' => 
    array (
      'key' => 's7.Q36',
      'sheet' => 7,
      'row' => 36,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '6d. No. of Category III rabies exposure WITH history of complete anti-rabies vaccine',
      'context' => '6. Category III Rabies-exposure who received complete dose of Anti-Rabies Vaccine (ARV) and Single Dose of Rabies Immunoglobulin (RIG)',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q38' => 
    array (
      'key' => 's7.Q38',
      'sheet' => 7,
      'row' => 38,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '7a. Dog',
      'context' => '7. Dog-mediated and non-dog-mediated Rabies exposures',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q39' => 
    array (
      'key' => 's7.Q39',
      'sheet' => 7,
      'row' => 39,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '7b. Cat',
      'context' => '7. Dog-mediated and non-dog-mediated Rabies exposures',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's7.Q40' => 
    array (
      'key' => 's7.Q40',
      'sheet' => 7,
      'row' => 40,
      'side' => 'Q',
      'section' => 'Sanitation, filariasis and rabies',
      'label' => '7c. Others',
      'context' => '7. Dog-mediated and non-dog-mediated Rabies exposures',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A6' => 
    array (
      'key' => 's8.A6',
      'sheet' => 8,
      'row' => 6,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '1. Schistosomiasis cases seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A7' => 
    array (
      'key' => 's8.A7',
      'sheet' => 8,
      'row' => 7,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '1. Schistosomiasis cases seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A8' => 
    array (
      'key' => 's8.A8',
      'sheet' => 8,
      'row' => 8,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '1. Schistosomiasis cases seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A9' => 
    array (
      'key' => 's8.A9',
      'sheet' => 8,
      'row' => 9,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '1. Schistosomiasis cases seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A10' => 
    array (
      'key' => 's8.A10',
      'sheet' => 8,
      'row' => 10,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '1. Schistosomiasis cases seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A12' => 
    array (
      'key' => 's8.A12',
      'sheet' => 8,
      'row' => 12,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '2. Suspected Schistosomiasis Cases Seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A13' => 
    array (
      'key' => 's8.A13',
      'sheet' => 8,
      'row' => 13,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '2. Suspected Schistosomiasis Cases Seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A14' => 
    array (
      'key' => 's8.A14',
      'sheet' => 8,
      'row' => 14,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '2. Suspected Schistosomiasis Cases Seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A15' => 
    array (
      'key' => 's8.A15',
      'sheet' => 8,
      'row' => 15,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '2. Suspected Schistosomiasis Cases Seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A16' => 
    array (
      'key' => 's8.A16',
      'sheet' => 8,
      'row' => 16,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '2. Suspected Schistosomiasis Cases Seen',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A18' => 
    array (
      'key' => 's8.A18',
      'sheet' => 8,
      'row' => 18,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '3. Confirmed Schistosomiasis Cases Treated by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A19' => 
    array (
      'key' => 's8.A19',
      'sheet' => 8,
      'row' => 19,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '3. Confirmed Schistosomiasis Cases Treated by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A20' => 
    array (
      'key' => 's8.A20',
      'sheet' => 8,
      'row' => 20,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '3. Confirmed Schistosomiasis Cases Treated by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A21' => 
    array (
      'key' => 's8.A21',
      'sheet' => 8,
      'row' => 21,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '3. Confirmed Schistosomiasis Cases Treated by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A22' => 
    array (
      'key' => 's8.A22',
      'sheet' => 8,
      'row' => 22,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '3. Confirmed Schistosomiasis Cases Treated by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A24' => 
    array (
      'key' => 's8.A24',
      'sheet' => 8,
      'row' => 24,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4a. Praziquantel',
      'context' => '4. Confirmed Schistosomiasis Cases Treated by treatment name',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A25' => 
    array (
      'key' => 's8.A25',
      'sheet' => 8,
      'row' => 25,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4b. Tuberculostatic',
      'context' => '4. Confirmed Schistosomiasis Cases Treated by treatment name',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A27' => 
    array (
      'key' => 's8.A27',
      'sheet' => 8,
      'row' => 27,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '5. Confirmed Schistosomiasis Cases Cured',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A28' => 
    array (
      'key' => 's8.A28',
      'sheet' => 8,
      'row' => 28,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '5. Confirmed Schistosomiasis Cases Cured',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A29' => 
    array (
      'key' => 's8.A29',
      'sheet' => 8,
      'row' => 29,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '5. Confirmed Schistosomiasis Cases Cured',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A30' => 
    array (
      'key' => 's8.A30',
      'sheet' => 8,
      'row' => 30,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '5. Confirmed Schistosomiasis Cases Cured',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A31' => 
    array (
      'key' => 's8.A31',
      'sheet' => 8,
      'row' => 31,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '5. Confirmed Schistosomiasis Cases Cured',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A33' => 
    array (
      'key' => 's8.A33',
      'sheet' => 8,
      'row' => 33,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '6. Confirmed COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A34' => 
    array (
      'key' => 's8.A34',
      'sheet' => 8,
      'row' => 34,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '6. Confirmed COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A35' => 
    array (
      'key' => 's8.A35',
      'sheet' => 8,
      'row' => 35,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '6. Confirmed COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A36' => 
    array (
      'key' => 's8.A36',
      'sheet' => 8,
      'row' => 36,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '6. Confirmed COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A37' => 
    array (
      'key' => 's8.A37',
      'sheet' => 8,
      'row' => 37,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '6. Confirmed COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A39' => 
    array (
      'key' => 's8.A39',
      'sheet' => 8,
      'row' => 39,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '7. Confirmed NON-COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A40' => 
    array (
      'key' => 's8.A40',
      'sheet' => 8,
      'row' => 40,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '7. Confirmed NON-COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A41' => 
    array (
      'key' => 's8.A41',
      'sheet' => 8,
      'row' => 41,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '7. Confirmed NON-COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A42' => 
    array (
      'key' => 's8.A42',
      'sheet' => 8,
      'row' => 42,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '7. Confirmed NON-COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A43' => 
    array (
      'key' => 's8.A43',
      'sheet' => 8,
      'row' => 43,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '7. Confirmed NON-COMPLICATED Schistosomiasis Cases by age group',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A46' => 
    array (
      'key' => 's8.A46',
      'sheet' => 8,
      'row' => 46,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '1. Suspected for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A47' => 
    array (
      'key' => 's8.A47',
      'sheet' => 8,
      'row' => 47,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '1. Suspected for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A48' => 
    array (
      'key' => 's8.A48',
      'sheet' => 8,
      'row' => 48,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '1. Suspected for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A49' => 
    array (
      'key' => 's8.A49',
      'sheet' => 8,
      'row' => 49,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '1. Suspected for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.A50' => 
    array (
      'key' => 's8.A50',
      'sheet' => 8,
      'row' => 50,
      'side' => 'A',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '1. Suspected for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q5' => 
    array (
      'key' => 's8.Q5',
      'sheet' => 8,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '8. Confirmed COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q6' => 
    array (
      'key' => 's8.Q6',
      'sheet' => 8,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '8. Confirmed COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q7' => 
    array (
      'key' => 's8.Q7',
      'sheet' => 8,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '8. Confirmed COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q8' => 
    array (
      'key' => 's8.Q8',
      'sheet' => 8,
      'row' => 8,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '8. Confirmed COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q9' => 
    array (
      'key' => 's8.Q9',
      'sheet' => 8,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '8. Confirmed COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q11' => 
    array (
      'key' => 's8.Q11',
      'sheet' => 8,
      'row' => 11,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '9. Confirmed NON-COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q12' => 
    array (
      'key' => 's8.Q12',
      'sheet' => 8,
      'row' => 12,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '9. Confirmed NON-COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q13' => 
    array (
      'key' => 's8.Q13',
      'sheet' => 8,
      'row' => 13,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '9. Confirmed NON-COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q14' => 
    array (
      'key' => 's8.Q14',
      'sheet' => 8,
      'row' => 14,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '9. Confirmed NON-COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q15' => 
    array (
      'key' => 's8.Q15',
      'sheet' => 8,
      'row' => 15,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '9. Confirmed NON-COMPLICATED Schistosomiasis Cases TREATED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q17' => 
    array (
      'key' => 's8.Q17',
      'sheet' => 8,
      'row' => 17,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '10a. Praziquantel',
      'context' => '10. Confirmed Schistosomiasis Cases Treated by treatment',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q18' => 
    array (
      'key' => 's8.Q18',
      'sheet' => 8,
      'row' => 18,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '10b. Tuberculostatic',
      'context' => '10. Confirmed Schistosomiasis Cases Treated by treatment',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q20' => 
    array (
      'key' => 's8.Q20',
      'sheet' => 8,
      'row' => 20,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '11. Confirmed COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q21' => 
    array (
      'key' => 's8.Q21',
      'sheet' => 8,
      'row' => 21,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '11. Confirmed COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q22' => 
    array (
      'key' => 's8.Q22',
      'sheet' => 8,
      'row' => 22,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '11. Confirmed COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q23' => 
    array (
      'key' => 's8.Q23',
      'sheet' => 8,
      'row' => 23,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '11. Confirmed COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q24' => 
    array (
      'key' => 's8.Q24',
      'sheet' => 8,
      'row' => 24,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '11. Confirmed COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q26' => 
    array (
      'key' => 's8.Q26',
      'sheet' => 8,
      'row' => 26,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '12. Confirmed NON-COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q27' => 
    array (
      'key' => 's8.Q27',
      'sheet' => 8,
      'row' => 27,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '12. Confirmed NON-COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q28' => 
    array (
      'key' => 's8.Q28',
      'sheet' => 8,
      'row' => 28,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '12. Confirmed NON-COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q29' => 
    array (
      'key' => 's8.Q29',
      'sheet' => 8,
      'row' => 29,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '12. Confirmed NON-COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q30' => 
    array (
      'key' => 's8.Q30',
      'sheet' => 8,
      'row' => 30,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '12. Confirmed NON-COMPLICATED Schistosomiasis Cases CURED',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q32' => 
    array (
      'key' => 's8.Q32',
      'sheet' => 8,
      'row' => 32,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '13a. 1-4 years old',
      'context' => '13. Confirmed Schistosomiasis Cases Referred to Hospital Facility',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q33' => 
    array (
      'key' => 's8.Q33',
      'sheet' => 8,
      'row' => 33,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '13b. 5-14 years old',
      'context' => '13. Confirmed Schistosomiasis Cases Referred to Hospital Facility',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q34' => 
    array (
      'key' => 's8.Q34',
      'sheet' => 8,
      'row' => 34,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '13c. 15-19 years old',
      'context' => '13. Confirmed Schistosomiasis Cases Referred to Hospital Facility',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q35' => 
    array (
      'key' => 's8.Q35',
      'sheet' => 8,
      'row' => 35,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '13d. 20-59 years old',
      'context' => '13. Confirmed Schistosomiasis Cases Referred to Hospital Facility',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q36' => 
    array (
      'key' => 's8.Q36',
      'sheet' => 8,
      'row' => 36,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '13e. 60 years old and above',
      'context' => '13. Confirmed Schistosomiasis Cases Referred to Hospital Facility',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q38' => 
    array (
      'key' => 's8.Q38',
      'sheet' => 8,
      'row' => 38,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '14. No. of individuals diagnosed with ova of Schistosoma during MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q39' => 
    array (
      'key' => 's8.Q39',
      'sheet' => 8,
      'row' => 39,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '14. No. of individuals diagnosed with ova of Schistosoma during MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q40' => 
    array (
      'key' => 's8.Q40',
      'sheet' => 8,
      'row' => 40,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '14. No. of individuals diagnosed with ova of Schistosoma during MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q41' => 
    array (
      'key' => 's8.Q41',
      'sheet' => 8,
      'row' => 41,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '14. No. of individuals diagnosed with ova of Schistosoma during MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q42' => 
    array (
      'key' => 's8.Q42',
      'sheet' => 8,
      'row' => 42,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '14. No. of individuals diagnosed with ova of Schistosoma during MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q44' => 
    array (
      'key' => 's8.Q44',
      'sheet' => 8,
      'row' => 44,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '1. 1-4 years old',
      'context' => '4. Confirmed STH Cases Treated',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q45' => 
    array (
      'key' => 's8.Q45',
      'sheet' => 8,
      'row' => 45,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '2. 5-14 years old',
      'context' => '4. Confirmed STH Cases Treated',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q46' => 
    array (
      'key' => 's8.Q46',
      'sheet' => 8,
      'row' => 46,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '3. 15-19 years old',
      'context' => '4. Confirmed STH Cases Treated',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q47' => 
    array (
      'key' => 's8.Q47',
      'sheet' => 8,
      'row' => 47,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '4. 20-59 years old',
      'context' => '4. Confirmed STH Cases Treated',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's8.Q48' => 
    array (
      'key' => 's8.Q48',
      'sheet' => 8,
      'row' => 48,
      'side' => 'Q',
      'section' => 'Schistosomiasis and STH',
      'label' => '5. 60 years old and above',
      'context' => '4. Confirmed STH Cases Treated',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A5' => 
    array (
      'key' => 's9.A5',
      'sheet' => 9,
      'row' => 5,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '1. 1-4 years old',
      'context' => '2. Suspected STH cases treated for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A6' => 
    array (
      'key' => 's9.A6',
      'sheet' => 9,
      'row' => 6,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '2. 5-14 years old',
      'context' => '2. Suspected STH cases treated for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A7' => 
    array (
      'key' => 's9.A7',
      'sheet' => 9,
      'row' => 7,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '3. 15-19 years old',
      'context' => '2. Suspected STH cases treated for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A8' => 
    array (
      'key' => 's9.A8',
      'sheet' => 9,
      'row' => 8,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '4. 20-59 years old',
      'context' => '2. Suspected STH cases treated for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A9' => 
    array (
      'key' => 's9.A9',
      'sheet' => 9,
      'row' => 9,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '5. 60 years old and above',
      'context' => '2. Suspected STH cases treated for STH',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A11' => 
    array (
      'key' => 's9.A11',
      'sheet' => 9,
      'row' => 11,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '1. 1-4 years old',
      'context' => '3. Confirmed STH Cases',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A12' => 
    array (
      'key' => 's9.A12',
      'sheet' => 9,
      'row' => 12,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '2. 5-14 years old',
      'context' => '3. Confirmed STH Cases',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A13' => 
    array (
      'key' => 's9.A13',
      'sheet' => 9,
      'row' => 13,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '3. 15-19 years old',
      'context' => '3. Confirmed STH Cases',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A14' => 
    array (
      'key' => 's9.A14',
      'sheet' => 9,
      'row' => 14,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '4. 20-59 years old',
      'context' => '3. Confirmed STH Cases',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A15' => 
    array (
      'key' => 's9.A15',
      'sheet' => 9,
      'row' => 15,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '5. 60 years old and above',
      'context' => '3. Confirmed STH Cases',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A18' => 
    array (
      'key' => 's9.A18',
      'sheet' => 9,
      'row' => 18,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '2. 1-4 years old',
      'context' => '1. Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A19' => 
    array (
      'key' => 's9.A19',
      'sheet' => 9,
      'row' => 19,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '3. 5-14 years old',
      'context' => '1. Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A20' => 
    array (
      'key' => 's9.A20',
      'sheet' => 9,
      'row' => 20,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '4. 15-19 years old',
      'context' => '1. Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A21' => 
    array (
      'key' => 's9.A21',
      'sheet' => 9,
      'row' => 21,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '5. 20-59 years old',
      'context' => '1. Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A22' => 
    array (
      'key' => 's9.A22',
      'sheet' => 9,
      'row' => 22,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '6. 60 years old and above',
      'context' => '1. Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A24' => 
    array (
      'key' => 's9.A24',
      'sheet' => 9,
      'row' => 24,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '8. 1-4 years old',
      'context' => '7. Non-Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A25' => 
    array (
      'key' => 's9.A25',
      'sheet' => 9,
      'row' => 25,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '9. 5-14 years old',
      'context' => '7. Non-Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A26' => 
    array (
      'key' => 's9.A26',
      'sheet' => 9,
      'row' => 26,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '10. 15-19 years old',
      'context' => '7. Non-Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A27' => 
    array (
      'key' => 's9.A27',
      'sheet' => 9,
      'row' => 27,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '11. 20-59 years old',
      'context' => '7. Non-Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 20,
      'age_max' => 59,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A28' => 
    array (
      'key' => 's9.A28',
      'sheet' => 9,
      'row' => 28,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '12. 60 years old and above',
      'context' => '7. Non-Resident',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 60,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A30' => 
    array (
      'key' => 's9.A30',
      'sheet' => 9,
      'row' => 30,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '1. No. of registered cases on treatment',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A31' => 
    array (
      'key' => 's9.A31',
      'sheet' => 9,
      'row' => 31,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '1a. 1-14 years old',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A32' => 
    array (
      'key' => 's9.A32',
      'sheet' => 9,
      'row' => 32,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '1b. 15-19 years old',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A33' => 
    array (
      'key' => 's9.A33',
      'sheet' => 9,
      'row' => 33,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '1c. 19 years old and above',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A34' => 
    array (
      'key' => 's9.A34',
      'sheet' => 9,
      'row' => 34,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '2. No. of newly detected cases',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A35' => 
    array (
      'key' => 's9.A35',
      'sheet' => 9,
      'row' => 35,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '2a. 1-14 years old',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A36' => 
    array (
      'key' => 's9.A36',
      'sheet' => 9,
      'row' => 36,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '2b. 15-19 years old',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A37' => 
    array (
      'key' => 's9.A37',
      'sheet' => 9,
      'row' => 37,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '2c. 19 years old and above',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A38' => 
    array (
      'key' => 's9.A38',
      'sheet' => 9,
      'row' => 38,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '3. Confirmed Multi Bacillary Cases',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A39' => 
    array (
      'key' => 's9.A39',
      'sheet' => 9,
      'row' => 39,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '3a. 1-14 years old',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.A40' => 
    array (
      'key' => 's9.A40',
      'sheet' => 9,
      'row' => 40,
      'side' => 'A',
      'section' => 'STH and leprosy',
      'label' => '3b. 15-19 years old',
      'context' => 'E. Leprosy',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q11' => 
    array (
      'key' => 's9.Q11',
      'sheet' => 9,
      'row' => 11,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '5a. School-based deworming services',
      'context' => '5. 1-4 years old who were dewormed during January MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 1,
    ),
    's9.Q12' => 
    array (
      'key' => 's9.Q12',
      'sheet' => 9,
      'row' => 12,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '5b. Community Based services',
      'context' => '5. 1-4 years old who were dewormed during January MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 1,
    ),
    's9.Q14' => 
    array (
      'key' => 's9.Q14',
      'sheet' => 9,
      'row' => 14,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '6a. School-based deworming services',
      'context' => '6. 5-14 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 7,
    ),
    's9.Q15' => 
    array (
      'key' => 's9.Q15',
      'sheet' => 9,
      'row' => 15,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '6b. Community Based services',
      'context' => '6. 5-14 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 7,
    ),
    's9.Q17' => 
    array (
      'key' => 's9.Q17',
      'sheet' => 9,
      'row' => 17,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '7a. School-based deworming services',
      'context' => '7. 1-4 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 7,
    ),
    's9.Q18' => 
    array (
      'key' => 's9.Q18',
      'sheet' => 9,
      'row' => 18,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '7b. Community Based services',
      'context' => '7. 1-4 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 1,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 7,
    ),
    's9.Q20' => 
    array (
      'key' => 's9.Q20',
      'sheet' => 9,
      'row' => 20,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '8a. School-based deworming services',
      'context' => '8. 5-14 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 7,
    ),
    's9.Q21' => 
    array (
      'key' => 's9.Q21',
      'sheet' => 9,
      'row' => 21,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '8b. Community Based services',
      'context' => '8. 5-14 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 5,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 7,
    ),
    's9.Q23' => 
    array (
      'key' => 's9.Q23',
      'sheet' => 9,
      'row' => 23,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '9a. No. of adolescents (15-19 years old) who were dewormed during January MDA',
      'context' => '9. 15-19 years old who were dewormed during January MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 1,
    ),
    's9.Q25' => 
    array (
      'key' => 's9.Q25',
      'sheet' => 9,
      'row' => 25,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '10a. No. of adolescents (15-19 years old) who were dewormed during July MDA',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_month' => 7,
    ),
    's9.Q30' => 
    array (
      'key' => 's9.Q30',
      'sheet' => 9,
      'row' => 30,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '4. Completed Fixed Duration Multi-Drug Therapy (MDT)',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q31' => 
    array (
      'key' => 's9.Q31',
      'sheet' => 9,
      'row' => 31,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '4a. 0-14 years old',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q32' => 
    array (
      'key' => 's9.Q32',
      'sheet' => 9,
      'row' => 32,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '4b. 15-19 years old',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q33' => 
    array (
      'key' => 's9.Q33',
      'sheet' => 9,
      'row' => 33,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '4c. 19 years old and above',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q34' => 
    array (
      'key' => 's9.Q34',
      'sheet' => 9,
      'row' => 34,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '5. No. of confirmed Leprosy cases cured',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q35' => 
    array (
      'key' => 's9.Q35',
      'sheet' => 9,
      'row' => 35,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '5a. 0-14 years old',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q36' => 
    array (
      'key' => 's9.Q36',
      'sheet' => 9,
      'row' => 36,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '5b. 15-19 years old',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q37' => 
    array (
      'key' => 's9.Q37',
      'sheet' => 9,
      'row' => 37,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '5c. 19 years old and above',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q38' => 
    array (
      'key' => 's9.Q38',
      'sheet' => 9,
      'row' => 38,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '6. Newly Detected Cases with Grade 2 Disabilities',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q39' => 
    array (
      'key' => 's9.Q39',
      'sheet' => 9,
      'row' => 39,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '6a. 0-14 years old',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q40' => 
    array (
      'key' => 's9.Q40',
      'sheet' => 9,
      'row' => 40,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '6b. 15-19 years old',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's9.Q41' => 
    array (
      'key' => 's9.Q41',
      'sheet' => 9,
      'row' => 41,
      'side' => 'Q',
      'section' => 'STH and leprosy',
      'label' => '6c. 19 years old and above',
      'context' => '10. 15-19 years old who were dewormed during July MDA',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's10.A5' => 
    array (
      'key' => 's10.A5',
      'sheet' => 10,
      'row' => 5,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1. Pregnant women screened for syphilis - Total',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
      ),
    ),
    's10.A6' => 
    array (
      'key' => 's10.A6',
      'sheet' => 10,
      'row' => 6,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1a. 10-14 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A5',
      ),
    ),
    's10.A7' => 
    array (
      'key' => 's10.A7',
      'sheet' => 10,
      'row' => 7,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1b. 15-19 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A5',
      ),
    ),
    's10.A8' => 
    array (
      'key' => 's10.A8',
      'sheet' => 10,
      'row' => 8,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1c. 20-49 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 20,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A5',
      ),
    ),
    's10.A9' => 
    array (
      'key' => 's10.A9',
      'sheet' => 10,
      'row' => 9,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2. Pregnant women screened reactive for syphilis - Total',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
      ),
    ),
    's10.A10' => 
    array (
      'key' => 's10.A10',
      'sheet' => 10,
      'row' => 10,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2a. 10-14 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A9',
      ),
    ),
    's10.A11' => 
    array (
      'key' => 's10.A11',
      'sheet' => 10,
      'row' => 11,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2b. 15-19 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A9',
      ),
    ),
    's10.A12' => 
    array (
      'key' => 's10.A12',
      'sheet' => 10,
      'row' => 12,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2c. 20-49 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 20,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A9',
      ),
    ),
    's10.A13' => 
    array (
      'key' => 's10.A13',
      'sheet' => 10,
      'row' => 13,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '3. Pregnant women treated for syphilis - Total',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
      ),
    ),
    's10.A14' => 
    array (
      'key' => 's10.A14',
      'sheet' => 10,
      'row' => 14,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '3a. 10-14 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A13',
      ),
    ),
    's10.A15' => 
    array (
      'key' => 's10.A15',
      'sheet' => 10,
      'row' => 15,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '3b. 15-19 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A13',
      ),
    ),
    's10.A16' => 
    array (
      'key' => 's10.A16',
      'sheet' => 10,
      'row' => 16,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '3c. 20-49 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 20,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A13',
      ),
    ),
    's10.A17' => 
    array (
      'key' => 's10.A17',
      'sheet' => 10,
      'row' => 17,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '4. Pregnant women screened for HIV - Total',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
      ),
    ),
    's10.A18' => 
    array (
      'key' => 's10.A18',
      'sheet' => 10,
      'row' => 18,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '4a. 10-14 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A17',
      ),
    ),
    's10.A19' => 
    array (
      'key' => 's10.A19',
      'sheet' => 10,
      'row' => 19,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '4b. 15-19 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A17',
      ),
    ),
    's10.A20' => 
    array (
      'key' => 's10.A20',
      'sheet' => 10,
      'row' => 20,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '4c. 20-49 years old',
      'context' => 'F. HIV AIDS',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 20,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.A17',
      ),
    ),
    's10.A23' => 
    array (
      'key' => 's10.A23',
      'sheet' => 10,
      'row' => 23,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1. Maternal Mortality - Total',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's11.A37',
      ),
    ),
    's10.A24' => 
    array (
      'key' => 's10.A24',
      'sheet' => 10,
      'row' => 24,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => 'a. Direct',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.A23',
      ),
    ),
    's10.A25' => 
    array (
      'key' => 's10.A25',
      'sheet' => 10,
      'row' => 25,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => 'a1. Resident',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.A24',
      ),
    ),
    's10.A26' => 
    array (
      'key' => 's10.A26',
      'sheet' => 10,
      'row' => 26,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => 'a2. Non-Resident',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.A24',
      ),
    ),
    's10.A27' => 
    array (
      'key' => 's10.A27',
      'sheet' => 10,
      'row' => 27,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => 'b. Indirect',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.A23',
      ),
    ),
    's10.A28' => 
    array (
      'key' => 's10.A28',
      'sheet' => 10,
      'row' => 28,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => 'b1. Resident',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.A27',
      ),
    ),
    's10.A29' => 
    array (
      'key' => 's10.A29',
      'sheet' => 10,
      'row' => 29,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => 'b2. Non-Resident',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.A27',
      ),
    ),
    's10.A30' => 
    array (
      'key' => 's10.A30',
      'sheet' => 10,
      'row' => 30,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2. Total Deaths',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's10.A31' => 
    array (
      'key' => 's10.A31',
      'sheet' => 10,
      'row' => 31,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '3. Underfive Deaths',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 4,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's10.A32' => 
    array (
      'key' => 's10.A32',
      'sheet' => 10,
      'row' => 32,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '4. Underone Deaths',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's10.A33' => 
    array (
      'key' => 's10.A33',
      'sheet' => 10,
      'row' => 33,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '5. Infant Deaths',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's11.A38',
      ),
    ),
    's10.A34' => 
    array (
      'key' => 's10.A34',
      'sheet' => 10,
      'row' => 34,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '6. Neonatal Deaths',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'max_days' => 27,
    ),
    's10.A35' => 
    array (
      'key' => 's10.A35',
      'sheet' => 10,
      'row' => 35,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '7. Perinatal Deaths - Total',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'derived_only' => true,
    ),
    's10.A36' => 
    array (
      'key' => 's10.A36',
      'sheet' => 10,
      'row' => 36,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '7a. Fetal Deaths',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'fetal_event',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'event_key' => true,
      'subject_sex' => true,
    ),
    's10.A37' => 
    array (
      'key' => 's10.A37',
      'sheet' => 10,
      'row' => 37,
      'side' => 'A',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '7b. Early Neonatal Deaths',
      'context' => 'Part I. Mortality',
      'columns' => 
      array (
        0 => 'L',
        1 => 'M',
        2 => 'N',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'PHYSICIAN',
        1 => 'NURSE',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.A35',
      ),
      'max_days' => 6,
    ),
    's10.Q4' => 
    array (
      'key' => 's10.Q4',
      'sheet' => 10,
      'row' => 4,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '5. Pregnant women screened negative for HIV - Total',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
      ),
    ),
    's10.Q5' => 
    array (
      'key' => 's10.Q5',
      'sheet' => 10,
      'row' => 5,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '5a. 10-14 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q4',
      ),
    ),
    's10.Q6' => 
    array (
      'key' => 's10.Q6',
      'sheet' => 10,
      'row' => 6,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '5b. 15-19 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q4',
      ),
    ),
    's10.Q7' => 
    array (
      'key' => 's10.Q7',
      'sheet' => 10,
      'row' => 7,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '5c. 20-49 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 20,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q4',
      ),
    ),
    's10.Q8' => 
    array (
      'key' => 's10.Q8',
      'sheet' => 10,
      'row' => 8,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '6. Pregnant women screened for Hepatitis B - Total',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
      ),
    ),
    's10.Q9' => 
    array (
      'key' => 's10.Q9',
      'sheet' => 10,
      'row' => 9,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '6a. 10-14 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q8',
      ),
    ),
    's10.Q10' => 
    array (
      'key' => 's10.Q10',
      'sheet' => 10,
      'row' => 10,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '6b. 15-19 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q8',
      ),
    ),
    's10.Q11' => 
    array (
      'key' => 's10.Q11',
      'sheet' => 10,
      'row' => 11,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '6c. 20-49 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 20,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q8',
      ),
    ),
    's10.Q12' => 
    array (
      'key' => 's10.Q12',
      'sheet' => 10,
      'row' => 12,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '7. Pregnant women screened reactive for Hepatitis B - Total',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
      ),
    ),
    's10.Q13' => 
    array (
      'key' => 's10.Q13',
      'sheet' => 10,
      'row' => 13,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '7a. 10-14 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 10,
      'age_max' => 14,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q12',
      ),
    ),
    's10.Q14' => 
    array (
      'key' => 's10.Q14',
      'sheet' => 10,
      'row' => 14,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '7b. 15-19 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 15,
      'age_max' => 19,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q12',
      ),
    ),
    's10.Q15' => 
    array (
      'key' => 's10.Q15',
      'sheet' => 10,
      'row' => 15,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '7c. 20-49 years old',
      'context' => '',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MEDTECH',
      ),
      'age_min' => 20,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => true,
      'parents' => 
      array (
        0 => 's10.Q12',
      ),
    ),
    's10.Q23' => 
    array (
      'key' => 's10.Q23',
      'sheet' => 10,
      'row' => 23,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1. Live births (Total)',
      'context' => 'Part II. Natality',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's10.Q24' => 
    array (
      'key' => 's10.Q24',
      'sheet' => 10,
      'row' => 24,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1a. Resident',
      'context' => 'Part II. Natality',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.Q23',
      ),
    ),
    's10.Q25' => 
    array (
      'key' => 's10.Q25',
      'sheet' => 10,
      'row' => 25,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '1b. Non-Resident',
      'context' => 'Part II. Natality',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's10.Q23',
      ),
    ),
    's10.Q27' => 
    array (
      'key' => 's10.Q27',
      'sheet' => 10,
      'row' => 27,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2a. <10 years old',
      'context' => '2. Adolescent Birth',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'maternal_age' => true,
      'mother_link' => true,
    ),
    's10.Q28' => 
    array (
      'key' => 's10.Q28',
      'sheet' => 10,
      'row' => 28,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2b. 10-14 years old',
      'context' => '2. Adolescent Birth',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'maternal_age' => true,
      'mother_link' => true,
    ),
    's10.Q29' => 
    array (
      'key' => 's10.Q29',
      'sheet' => 10,
      'row' => 29,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '2c. 15-19 years old',
      'context' => '2. Adolescent Birth',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's11.A29',
      ),
      'maternal_age' => true,
      'mother_link' => true,
    ),
    's10.Q31' => 
    array (
      'key' => 's10.Q31',
      'sheet' => 10,
      'row' => 31,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '3a. 10-14 years old',
      'context' => '3. Repeat Adolescent Birth',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'maternal_age' => true,
      'mother_link' => true,
    ),
    's10.Q32' => 
    array (
      'key' => 's10.Q32',
      'sheet' => 10,
      'row' => 32,
      'side' => 'Q',
      'section' => 'Maternal infection screening and vital statistics',
      'label' => '3b. 15-19 years old',
      'context' => '3. Repeat Adolescent Birth',
      'columns' => 
      array (
        0 => 'AB',
        1 => 'AC',
        2 => 'AD',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'NURSE',
        2 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'maternal_age' => true,
      'mother_link' => true,
    ),
    's11.A27' => 
    array (
      'key' => 's11.A27',
      'sheet' => 11,
      'row' => 27,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '1. No. of WRA (15-49 y/o) using modern FP methods - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 15,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's11.A28' => 
    array (
      'key' => 's11.A28',
      'sheet' => 11,
      'row' => 28,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '2. No. of pregnant women with at least 4 prenatal check-ups - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => true,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'minimum_visits' => 4,
    ),
    's11.A29' => 
    array (
      'key' => 's11.A29',
      'sheet' => 11,
      'row' => 29,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '3. No. of livebirths among 15-19 y/o women - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'maternal_age' => true,
      'mother_link' => true,
    ),
    's11.A30' => 
    array (
      'key' => 's11.A30',
      'sheet' => 11,
      'row' => 30,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '4. No. of livebirths with low birth weight - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'birth',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's11.A31' => 
    array (
      'key' => 's11.A31',
      'sheet' => 11,
      'row' => 31,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '5. No. of deliveries attended by skilled health professionals - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's11.A32' => 
    array (
      'key' => 's11.A32',
      'sheet' => 11,
      'row' => 32,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '6. No. of postpartum women together with their newborn who completed at least 2 postpartum check-ups - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'minimum_visits' => 2,
    ),
    's11.A33' => 
    array (
      'key' => 's11.A33',
      'sheet' => 11,
      'row' => 33,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '7. No. of health-facility based deliveries - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's11.A34' => 
    array (
      'key' => 's11.A34',
      'sheet' => 11,
      'row' => 34,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '8. No. of fully immunized children - Total',
      'context' => 'Section B: Program F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's11.A37' => 
    array (
      'key' => 's11.A37',
      'sheet' => 11,
      'row' => 37,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '1. Maternal Deaths - Total',
      'context' => 'Section C: Mortality F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 130,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's11.A38' => 
    array (
      'key' => 's11.A38',
      'sheet' => 11,
      'row' => 38,
      'side' => 'A',
      'section' => 'M2 F1 Plus',
      'label' => '2. Infant Deaths - Total',
      'context' => 'Section C: Mortality F1 PLUS Indicators',
      'columns' => 
      array (
        0 => 'AG',
        1 => 'AK',
        2 => 'AO',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'NURSE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 0,
      'age_max' => 0,
      'age_unit' => 'years',
      'female' => false,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
    's1.A14.opening' => 
    array (
      'key' => 's1.A14.opening',
      'sheet' => 1,
      'row' => 14,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '1. BTL - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A14.closing' => 
    array (
      'key' => 's1.A14.closing',
      'sheet' => 1,
      'row' => 14,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '1. BTL - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A15.opening' => 
    array (
      'key' => 's1.A15.opening',
      'sheet' => 1,
      'row' => 15,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '2. NSV - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A15.closing' => 
    array (
      'key' => 's1.A15.closing',
      'sheet' => 1,
      'row' => 15,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '2. NSV - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A16.opening' => 
    array (
      'key' => 's1.A16.opening',
      'sheet' => 1,
      'row' => 16,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '3. Condom - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A16.closing' => 
    array (
      'key' => 's1.A16.closing',
      'sheet' => 1,
      'row' => 16,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '3. Condom - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A17.opening' => 
    array (
      'key' => 's1.A17.opening',
      'sheet' => 1,
      'row' => 17,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '4. Pill - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A17.closing' => 
    array (
      'key' => 's1.A17.closing',
      'sheet' => 1,
      'row' => 17,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '4. Pill - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A18.opening' => 
    array (
      'key' => 's1.A18.opening',
      'sheet' => 1,
      'row' => 18,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'a. Pills-POP - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A17.opening',
      ),
      'observation' => 'opening',
    ),
    's1.A18.closing' => 
    array (
      'key' => 's1.A18.closing',
      'sheet' => 1,
      'row' => 18,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'a. Pills-POP - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A17.closing',
      ),
      'observation' => 'closing',
    ),
    's1.A19.opening' => 
    array (
      'key' => 's1.A19.opening',
      'sheet' => 1,
      'row' => 19,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'b. Pills-COC - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A17.opening',
      ),
      'observation' => 'opening',
    ),
    's1.A19.closing' => 
    array (
      'key' => 's1.A19.closing',
      'sheet' => 1,
      'row' => 19,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'b. Pills-COC - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A17.closing',
      ),
      'observation' => 'closing',
    ),
    's1.A20.opening' => 
    array (
      'key' => 's1.A20.opening',
      'sheet' => 1,
      'row' => 20,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '5. Injectables (DMPA) - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A20.closing' => 
    array (
      'key' => 's1.A20.closing',
      'sheet' => 1,
      'row' => 20,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '5. Injectables (DMPA) - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A21.opening' => 
    array (
      'key' => 's1.A21.opening',
      'sheet' => 1,
      'row' => 21,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '6. Implant - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A21.closing' => 
    array (
      'key' => 's1.A21.closing',
      'sheet' => 1,
      'row' => 21,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '6. Implant - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A22.opening' => 
    array (
      'key' => 's1.A22.opening',
      'sheet' => 1,
      'row' => 22,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'a. Implants-Interval - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A21.opening',
      ),
      'observation' => 'opening',
    ),
    's1.A22.closing' => 
    array (
      'key' => 's1.A22.closing',
      'sheet' => 1,
      'row' => 22,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'a. Implants-Interval - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A21.closing',
      ),
      'observation' => 'closing',
    ),
    's1.A23.opening' => 
    array (
      'key' => 's1.A23.opening',
      'sheet' => 1,
      'row' => 23,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'b. Implants-PP - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A21.opening',
      ),
      'observation' => 'opening',
    ),
    's1.A23.closing' => 
    array (
      'key' => 's1.A23.closing',
      'sheet' => 1,
      'row' => 23,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'b. Implants-PP - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A21.closing',
      ),
      'observation' => 'closing',
    ),
    's1.A24.opening' => 
    array (
      'key' => 's1.A24.opening',
      'sheet' => 1,
      'row' => 24,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '7. IUD - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A24.closing' => 
    array (
      'key' => 's1.A24.closing',
      'sheet' => 1,
      'row' => 24,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '7. IUD - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A25.opening' => 
    array (
      'key' => 's1.A25.opening',
      'sheet' => 1,
      'row' => 25,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'a. IUD-Interval - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A24.opening',
      ),
      'observation' => 'opening',
    ),
    's1.A25.closing' => 
    array (
      'key' => 's1.A25.closing',
      'sheet' => 1,
      'row' => 25,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'a. IUD-Interval - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A24.closing',
      ),
      'observation' => 'closing',
    ),
    's1.A26.opening' => 
    array (
      'key' => 's1.A26.opening',
      'sheet' => 1,
      'row' => 26,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'b. IUD-PP - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A24.opening',
      ),
      'observation' => 'opening',
    ),
    's1.A26.closing' => 
    array (
      'key' => 's1.A26.closing',
      'sheet' => 1,
      'row' => 26,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => 'b. IUD-PP - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
        0 => 's1.A24.closing',
      ),
      'observation' => 'closing',
    ),
    's1.A27.opening' => 
    array (
      'key' => 's1.A27.opening',
      'sheet' => 1,
      'row' => 27,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '8. NFP-LAM - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A27.closing' => 
    array (
      'key' => 's1.A27.closing',
      'sheet' => 1,
      'row' => 27,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '8. NFP-LAM - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A28.opening' => 
    array (
      'key' => 's1.A28.opening',
      'sheet' => 1,
      'row' => 28,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '9. NFP-BBT - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A28.closing' => 
    array (
      'key' => 's1.A28.closing',
      'sheet' => 1,
      'row' => 28,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '9. NFP-BBT - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A29.opening' => 
    array (
      'key' => 's1.A29.opening',
      'sheet' => 1,
      'row' => 29,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '10. NFP-CMM - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A29.closing' => 
    array (
      'key' => 's1.A29.closing',
      'sheet' => 1,
      'row' => 29,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '10. NFP-CMM - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A30.opening' => 
    array (
      'key' => 's1.A30.opening',
      'sheet' => 1,
      'row' => 30,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '11. NFP-STM - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A30.closing' => 
    array (
      'key' => 's1.A30.closing',
      'sheet' => 1,
      'row' => 30,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '11. NFP-STM - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A31.opening' => 
    array (
      'key' => 's1.A31.opening',
      'sheet' => 1,
      'row' => 31,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '12. NFP-SDM - opening current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'G',
        1 => 'H',
        2 => 'I',
        3 => 'J',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'opening',
    ),
    's1.A31.closing' => 
    array (
      'key' => 's1.A31.closing',
      'sheet' => 1,
      'row' => 31,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '12. NFP-SDM - closing current-user register',
      'context' => 'Verified register observation; no balance inferred from counseling',
      'columns' => 
      array (
        0 => 'W',
        1 => 'X',
        2 => 'Y',
        3 => 'Z',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
      'observation' => 'closing',
    ),
    's1.A9' => 
    array (
      'key' => 's1.A9',
      'sheet' => 1,
      'row' => 9,
      'side' => 'A',
      'section' => 'Family planning',
      'label' => '1. No. of women of reproductive age (WRA) 15-49 years old who have demand for Family Planning (FP) and currently using, or whose partner is currently using, any modern FP methods',
      'context' => 'Demand satisfied; explicit demand and current modern-method use documented',
      'columns' => 
      array (
        0 => 'J',
        1 => 'N',
        2 => 'R',
        3 => 'V',
      ),
      'unit' => 'resident',
      'roles' => 
      array (
        0 => 'MIDWIFE',
        1 => 'PHYSICIAN',
      ),
      'age_min' => 10,
      'age_max' => 49,
      'age_unit' => 'years',
      'female' => true,
      'pregnancy' => false,
      'restricted' => false,
      'parents' => 
      array (
      ),
    ),
  ),
);
}
