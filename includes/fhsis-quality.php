<?php
require_once dirname(__DIR__) . '/includes/record-access.php';

function fhsisDate($value): ?DateTimeImmutable
{
    $text = substr((string)$value, 0, 10);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    return $date && $date->format('Y-m-d') === $text ? $date : null;
}

function fhsisRecorded(array $row): bool
{
    if (rhuRecordIsConfidential($row)) return false;
    $status = strtolower(trim((string)($row['consultation_status'] ?? $row['status'] ?? 'recorded')));
    return in_array($status, ['completed', 'done', 'served', 'recorded', 'active', 'confirmed', 'suspected', 'recovered', 'died'], true);
}

function fhsisAge(array $row, string $dateField): ?DateInterval
{
    $birth = fhsisDate($row['report_birthdate'] ?? null);
    $date = fhsisDate($row[$dateField] ?? null);
    return $birth && $date && $birth <= $date ? $birth->diff($date) : null;
}

/** Count distinct residents, with disaggregation that always reconciles. */
function fhsisSexCells(array $rows, string $dateField, callable $eligible, string $male, string $female, string $total, array &$issues, string $label): array
{
    $counts = ['male'=>0, 'female'=>0]; $seen = [];
    foreach ($rows as $row) {
        if (!fhsisRecorded($row) || !$eligible($row)) continue;
        $id = (int)($row['resident_id'] ?? 0);
        $sex = strtolower(trim((string)($row['report_sex'] ?? '')));
        $sex = in_array($sex, ['m', 'male'], true) ? 'male' : (in_array($sex, ['f', 'female'], true) ? 'female' : null);
        if (!$id || !$sex || !fhsisAge($row, $dateField)) {
            $issues[] = $label . ': a qualifying record has missing/invalid resident demographics; cells withheld.';
            return [];
        }
        if (isset($seen[$id])) continue;
        $seen[$id] = true; $counts[$sex]++;
    }
    return [$male=>$counts['male'], $female=>$counts['female'], $total=>array_sum($counts)];
}

/** Convert the clinician's service selection into the report event; never infer from appointment text. */
function fhsisFpServiceEvent(string $acceptorType): string
{
    return match ($acceptorType) {
        'New Acceptor' => 'new',
        'Changing Method', 'Changing Clinic', 'Restarting User' => 'other',
        'Dropout' => 'dropout',
        'Continuing User', 'Counseling Only', 'Male Client', 'Couple / Partner',
        'Premarital Counseling', 'Postpartum or 3+ Pregnancies' => 'counseling',
        default => throw new InvalidArgumentException('Select the actual family planning service / client type.'),
    };
}

/** The marker is written only by the explicit service-event fields on the FP form. */
function fhsisFpEvent(array $row): ?array
{
    if (!preg_match('/^FHSIS_FP_V1:(\{[^\r\n]*\})$/m', (string)($row['remarks'] ?? ''), $match)) return null;
    $event = json_decode($match[1], true);
    if (!is_array($event) || !in_array($event['event'] ?? '', ['new','other','dropout'], true)) return null;
    if (!in_array($event['method'] ?? '', array_keys(fhsisFpMethods()), true)) return null;
    return $event;
}

function fhsisFpMethods(): array
{
    return ['BTL'=>[14], 'NSV'=>[15], 'Condom'=>[16], 'Pills-POP'=>[17,18], 'Pills-COC'=>[17,19],
        'DMPA'=>[20], 'Implant-Interval'=>[21,22], 'Implant-PP'=>[21,23], 'IUD-Interval'=>[24,25],
        'IUD-PP'=>[24,26], 'NFP-LAM'=>[27], 'NFP-BBT'=>[28], 'NFP-CMM'=>[29], 'NFP-STM'=>[30], 'NFP-SDM'=>[31]];
}

/** Both native FP records and consultations store the same clinician-recorded event. */
function fhsisFpSourceRows(array $sources): array
{
    $rows = $sources['consultations'] ?? [];
    foreach ($sources['family_planning_records'] ?? [] as $row) {
        // The native FP record holds the actual supply/service date, not the original booking date.
        $date = $row['last_supply_date'] ?? $row['visit_date'] ?? $row['record_date'] ?? '';
        if (!fhsisDate($date)) continue;
        $row['consultation_date'] = substr($date, 0, 10);
        $row['remarks'] = (string)($row['clinical_notes'] ?? $row['remarks'] ?? '');
        $rows[] = $row;
    }
    return $rows;
}

function fhsisFpIndicatorCells(array $rows, string $monthStart, array &$issues): array
{
    $start = fhsisDate($monthStart); $previous = $start->modify('-1 month')->format('Y-m-d');
    $end = $start->modify('+1 month')->format('Y-m-d');
    $counts=[]; $seen=[]; $found=false; $bad=false;
    foreach ($rows as $row) {
        if (!fhsisRecorded($row) || !($event=fhsisFpEvent($row))) continue;
        $date = substr((string)($row['consultation_date'] ?? ''), 0, 10);
        if ($date < $previous || $date >= $end) continue;
        $found=true; $age=fhsisAge($row,'consultation_date');
        if (in_array(strtolower(trim((string)($row['report_sex']??''))),['male','m'],true)) {
            $issues[]='Family planning: male client service excluded from female WRA counts; a verified partner linkage is required.'; continue;
        }
        if (!$age || empty($row['resident_id']) || !in_array(strtolower(trim((string)($row['report_sex'] ?? ''))), ['female','f'],true)) {
            $issues[]='Family planning: service event lacks a valid female WRA resident/age; FP counts withheld.'; $bad=true; continue;
        }
        if ($age->y < 10 || $age->y > 49) continue;
        $group=$age->y < 15 ? 0 : ($age->y < 20 ? 1 : 2);
        $bucket=$date < $monthStart ? ($event['event']==='new' ? 'previous' : null) : $event['event'];
        if (!$bucket) continue;
        // One resident per method, event category and reporting month; resupplies cannot inflate acceptors.
        $key=$bucket.':'.$event['method'].':'.$row['resident_id'];
        if (isset($seen[$key])) continue;
        $seen[$key]=true;
        foreach (fhsisFpMethods()[$event['method']] as $r) $counts[$bucket][$r][$group]=($counts[$bucket][$r][$group]??0)+1;
    }
    if (!$found) { $issues[]='Family planning: no explicit reportable acceptor/dropout events were found for '.$monthStart.' and the previous month. Resident profiles and counseling alone do not establish these counts. Verify actual service dates, method/subtype and event category.'; return []; }
    if ($bad) return [];
    $cells=[];
    foreach (['previous'=>['K','L','M','N'], 'other'=>['O','P','Q','R'], 'dropout'=>['S','T','U','V'], 'new'=>['AA','AB','AC','AD']] as $bucket=>$columns) {
        foreach (range(14,31) as $r) {
            $c=$counts[$bucket][$r]??[];
            for ($i=0;$i<3;$i++) $cells[$columns[$i].$r]=$c[$i]??0;
            $cells[$columns[3].$r]=array_sum($c);
        }
        // Exclude component rows when summing the method totals.
        foreach ($columns as $column) $cells[$column.'32']=array_sum(array_map(static fn($r)=>$cells[$column.$r], [14,15,16,17,20,21,24,27,28,29,30,31]));
    }
    $issues[]='Family planning: acceptors/dropouts count explicitly recorded service events only. Beginning/end current users and demand satisfied require a verified opening register, complete transfers/dropouts and partner linkage; unavailable calculated values display 0 in the workbook and remain flagged for review.';
    return $cells;
}

/** First/second annual examination is reached in this month, never count prescriptions as visits. */
function fhsisDentalIndicatorCells(array $rows, string $monthStart, array &$issues): array
{
    $visits=[]; $bad=false;
    foreach ($rows as $row) {
        if (!fhsisRecorded($row) || strtolower((string)($row['record_type']??''))!=='examination') continue;
        $age=fhsisAge($row,'record_date');
        if (!$age || empty($row['resident_id']) || !in_array(strtolower(trim((string)($row['report_sex']??''))), ['male','female','m','f'],true)) { $bad=true; continue; }
        $date=substr($row['record_date'],0,10);
        $visits[$row['resident_id']][$date]=$row; // Same-day duplicate chart entries are one visit.
    }
    if ($bad) { $issues[]='Oral health: examinations with missing resident demographics were found; annual visit counts withheld.'; return []; }
    $buckets=[];
    foreach ($visits as $dates) {
        ksort($dates); $index=0;
        foreach ($dates as $date=>$row) {
            $index++; if ($index>2 || $date<$monthStart) continue;
            $age=fhsisAge($row,'record_date')->y;
            $base=$age>=60?26:($age>=20?23:($age>=10?20:($age>=5?17:($age>=1?14:null))));
            if ($base) $buckets[$index][$base][]=$row;
        }
    }
    $cells=[];
    foreach ([1=>['L','M','N'],2=>['AB','AC','AD']] as $n=>$cols) foreach ([14,17,20,23,26] as $r) {
        $c=fhsisSexCells($buckets[$n][$r]??[], 'record_date', static fn($r)=>true, $cols[0].$r,$cols[1].$r,$cols[2].$r,$issues,'Oral health');
        $cells+=$c;
    }
    return $cells;
}

/** Only linked, explicitly recorded deaths; a birth record's resident may be its mother. */
function fhsisVitalIndicatorCells(array $rows, array &$issues): array
{
    $deaths=[]; $seen=[]; $ambiguous=false;
    foreach ($rows as $row) {
        if (!fhsisRecorded($row) || strtolower(trim((string)($row['event_type']??'')))!=='death') continue;
        if (empty($row['resident_id']) || !fhsisAge($row,'event_date')) { $ambiguous=true; continue; }
        $key=(int)$row['resident_id'];
        if (isset($seen[$key]) && $seen[$key]!==substr($row['event_date'],0,10)) { $ambiguous=true; continue; }
        $seen[$key]=substr($row['event_date'],0,10); $deaths[$key]=$row;
    }
    if ($ambiguous) { $issues[]='Mortality: unlinked deaths, invalid demographics or conflicting dates found; mortality cells withheld.'; return []; }
    $rules=[30=>static fn($r)=>true,31=>static fn($r)=>fhsisAge($r,'event_date')->y<5,
        32=>static fn($r)=>fhsisAge($r,'event_date')->y<1,33=>static fn($r)=>fhsisAge($r,'event_date')->y<1,
        34=>static fn($r)=>fhsisAge($r,'event_date')->days<28,37=>static fn($r)=>fhsisAge($r,'event_date')->days<7];
    $cells=[];
    foreach ($rules as $r=>$rule) $cells+=fhsisSexCells($deaths,'event_date',$rule,'L'.$r,'M'.$r,'N'.$r,$issues,'Mortality');
    return $cells;
}

function fhsisSectionRequirements(): array
{
    return [
        1=>['Family planning','Explicit acceptance/dropout events, exact method/subtype, female WRA age, verified opening register, transfers and partner linkage. Counseling alone is not acceptance.'],
        2=>['Maternal care','Pregnancy episode, actual ANC/PNC and delivery dates, scheduled visit completion, transfer status, supplementation completion, test results, attendant, delivery outcome and newborn weight. Pregnancy creation date is not a service date.'],
        3=>['Child care','Vaccination administration date, dose, resident birthdate/sex; birth time for 24-hour indicators; complete vaccine history for FIC/CIC; recorded nutrition services and treatment outcomes.'],
        4=>['Sick child and oral health','Completed encounter and explicit diagnosis/treatment for sick-child care; year-to-date examination history for first/second oral visits; setting and pregnancy-at-visit for further disaggregation.'],
        5=>['Noncommunicable diseases','Completed consultation with explicit diagnosis/ICD; PhilPEN assessment, medication actually supplied and funding source, eye screening findings/referrals must be captured separately.'],
        6=>['Older adults, mental health and cancer','Vaccine administration and age; explicit geriatric/mhGAP/cancer screening tool, eligibility, findings, treatment and linkage to care. A diagnosis or appointment does not prove screening.'],
        7=>['Sanitation, filariasis and rabies','Unique household with water/toilet classification and assessment date; explicit diagnostic test, first examination, MDA or rabies exposure category and full prophylaxis completion. Inspection counts are not household coverage.'],
        8=>['Schistosomiasis and STH','Explicit suspected/confirmed classification, complication status, treatment, cure/referral dates, age and MDA diagnosis evidence.'],
        9=>['STH and leprosy','Residence classification, MDA month/setting, leprosy registration/new detection, disability grade, MDT completion and confirmed cure dates.'],
        10=>['Maternal infection screening and vital statistics','MedTech-approved aggregate screening contributions linked to pregnancy; verified death/birth event, correct subject (mother versus infant), residence, maternal cause, gestational age and birth weight. Private laboratory records are not queried or decrypted by this export.'],
        11=>['M2 morbidity and F1 Plus','Disease case date, resident birthdate/sex and ICD; duplicate linked consultations reconciled. Program and mortality F1 Plus indicators need their exact completion/outcome definitions.'],
    ];
}

/** Inventory every indicator, including ones that cannot be derived from the current schema. */
function fhsisCoverageRows(array $data): array
{
    $archive=new FhsisReportArchive(dirname(__DIR__).'/assets/templates/fhsis-m1-m2.xlsx'); $result=[];
    try {
        foreach (fhsisSectionRequirements() as $sheet=>[$section,$requirements]) {
            $doc=new DOMDocument(); $doc->loadXML($archive->read('xl/worksheets/sheet'.$sheet.'.xml'), LIBXML_NONET);
            $xp=new DOMXPath($doc); $xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($xp->query('//s:sheetData/s:row') as $row) {
                $r=(int)$row->getAttribute('r');
                foreach (($sheet===1?['A']:($sheet===11?['A']:['A','Q'])) as $labelCol) {
                    $node=$xp->query('s:c[@r="'.$labelCol.$r.'"]',$row)->item(0);
                    $label=$node?trim(preg_replace('/\s+/u',' ',$node->textContent)):'';
                    if (!$label || !preg_match('/^(?:\d+[a-z]?\d?\.|[a-z]\d?\.|Adults |Senior Citizens |Total Current Users)/u',$label)) continue;
                    if ($sheet===11 && ($r<27 || $r>38 || in_array($r,[35,36],true))) continue;
                    $columns=$sheet===1?['G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','AA','AB','AC','AD']:
                        ($sheet===11?['AG','AK','AO']:($sheet===2?($labelCol==='A'?['L','M','N','O']:['AB','AC','AD','AE']):($labelCol==='A'?['L','M','N']:['AB','AC','AD'])));
                    $values=[]; $missing=[];
                    foreach ($columns as $column) {
                        $address=$column.$r;
                        if (array_key_exists($address,$data['indicator_cells'][$sheet]??[])) $values[]=$address.'='.$data['indicator_cells'][$sheet][$address];
                        else $missing[]=$address;
                    }
                    $result[]=['section'=>$section,'indicator'=>$label,'template_location'=>'Sheet '.$sheet.' '.$labelCol.$r,
                        'status'=>!$missing?'Calculated - verify source completeness':($values?'Partial - review required':'Not calculated - required evidence missing'),
                        'calculated_cells'=>implode('; ',$values),'unavailable_cells'=>implode(', ',$missing),'required_evidence'=>$requirements];
                }
            }
        }
    } finally { $archive->close(); }
    return $result;
}
