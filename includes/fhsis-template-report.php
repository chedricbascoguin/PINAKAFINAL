<?php
require_once dirname(__DIR__) . '/includes/record-access.php';
require_once dirname(__DIR__) . '/includes/fhsis-quality.php';
require_once dirname(__DIR__) . '/includes/fhsis-services.php';

/** Build only indicators whose source fields establish the requested meaning. */
function fhsisTemplateReportData(PDO $pdo, int $year, int $month): array
{
    if ($year < 1900 || $year > 9998 || $month < 1 || $month > 12) throw new InvalidArgumentException('Select a valid reporting month and year.');
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $period = [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
    $settings = function_exists('portalSettings') ? portalSettings($pdo) : [];
    $issues = [];
    $data = ['month' => $start->format('F'), 'year' => $year,
        'facility' => trim($settings['rhu_name'] ?? '') ?: 'Not configured',
        'municipality' => trim($settings['rhu_municipality'] ?? '') ?: 'Not configured',
        'province' => trim($settings['rhu_province'] ?? '') ?: 'Not configured',
        'scope' => 'All barangays (RHU aggregate)', 'cases' => [], 'notes' => [
            'Scope: all barangays served by the RHU; this is a consolidated report.',
            'Report count cells display 0 when no calculated value is available. Review sheets distinguish missing evidence from calculated zero.',
            'M2 morbidity counts use disease case records dated within the selected month, not appointments or repeated consultation text.',
            'Age is calculated on the case date. Records without a valid age or sex remain in disease totals and are noted below.',
        ]];
    foreach (['facility','municipality','province'] as $field) if ($data[$field]==='Not configured') $issues[]='Facility metadata: '.$field.' is not configured; set the actual reporting unit before submission.';
    if (adminExtendedTableExists($pdo, 'disease_cases') && adminExtendedTableExists($pdo, 'disease_types')) {
        $columns = $pdo->query('SHOW COLUMNS FROM disease_types')->fetchAll(PDO::FETCH_COLUMN);
        $code = in_array('icd_code', $columns, true) ? 'dt.icd_code' : "''";
        $stmt = $pdo->prepare("SELECT dc.*, dt.name AS report_disease_name, {$code} AS report_icd_code,
            r.birthdate AS report_birthdate, r.sex AS report_sex
            FROM disease_cases dc LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
            LEFT JOIN residents r ON r.id = dc.resident_id
            WHERE dc.case_date >= ? AND dc.case_date < ? ORDER BY dt.name, dc.id");
        $stmt->execute($period);
        // Confidential records are never included in this general admin export.
        $seenCases = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (rhuRecordIsConfidential($row)) continue;
            if (in_array(strtolower(trim((string)($row['case_status']??''))), ['cancelled','canceled','void','deleted','ruled out','ruled-out'],true)) continue;
            if (!empty($row['consultation_id'])) {
                $key = $row['consultation_id'].':'.$row['disease_type_id'].':'.substr($row['case_date'],0,10);
                if (isset($seenCases[$key])) { $issues[]='M2: duplicate records for the same consultation, disease and case date were counted once.'; continue; }
                $seenCases[$key]=true;
            }
            if (!fhsisAge($row,'case_date') || !in_array(strtolower(trim((string)($row['report_sex']??''))),['m','male','f','female'],true)) $issues[]='M2: case demographics are incomplete or invalid; disease totals include unclassified records and require reconciliation before submission.';
            $data['cases'][] = ['disease' => $row['report_disease_name'] ?: 'Unclassified disease',
                'icd' => $row['report_icd_code'] ?? '', 'date' => $row['case_date'],
                'birthdate' => $row['report_birthdate'], 'sex' => $row['report_sex']];
        }
    } else {
        $data['notes'][] = 'Morbidity source tables are unavailable.';
    }
    $data['source_records'] = [];
    $raw = []; $available = []; $sourceColumns = [];
    $data['include_source_records'] = false;
    $data['indicator_cells'] = [];
    $sources = [
        'consultations' => ['consultation_date'], 'vaccination_records' => ['vaccination_date'],
        'pregnancy_records' => ['created_at'], 'prenatal_visits' => ['visit_date', 'consultation_date', 'created_at'],
        'prenatal_records' => ['visit_date', 'record_date', 'created_at'],
        'family_planning_records' => ['last_supply_date', 'visit_date', 'record_date', 'created_at'],
        'dental_records' => ['record_date', 'consultation_date', 'created_at'],
        'vital_statistics' => ['event_date'], 'health_records' => ['last_checkup_date', 'created_at'],
        'sanitary_records' => ['record_date'],
    ];
    foreach ($sources as $table => $dateCandidates) {
        if (!adminExtendedTableExists($pdo, $table)) continue;
        $columns = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        $sourceColumns[$table]=$columns;
        $dateColumn = null;
        foreach ($dateCandidates as $candidate) if (in_array($candidate, $columns, true)) { $dateColumn = $candidate; break; }
        if (!$dateColumn) { $data['notes'][] = $table . ': no reporting date field is available.'; continue; }
        $join = in_array('resident_id', $columns, true) ? ' LEFT JOIN residents r ON r.id=t.resident_id LEFT JOIN barangays b ON b.id=r.barangay_id' : '';
        $select = $join ? ", CONCAT_WS(' ',r.first_name,r.middle_name,r.last_name) AS resident_name, r.birthdate AS report_birthdate, r.sex AS report_sex, b.name AS report_barangay" : '';
        // Include January-to-previous-month consultations for the cumulative NCD rows.
        $from = in_array($table, ['consultations', 'dental_records'], true) ? sprintf('%04d-01-01', $year) : $period[0];
        if (in_array($table, ['consultations', 'family_planning_records'], true)) $from = min($from, $start->modify('-1 month')->format('Y-m-d'));
        $stmt = $pdo->prepare("SELECT t.* {$select} FROM `{$table}` t {$join} WHERE t.`{$dateColumn}` >= ? AND t.`{$dateColumn}` < ? ORDER BY t.`{$dateColumn}`, t.id");
        $stmt->execute([$from, $period[1]]);
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn(array $r): bool => !rhuRecordIsConfidential($r)));
        $available[$table] = true; $raw[$table] = $rows;
        if ($dateColumn === 'created_at') $issues[] = $table . ': creation date is used only for the internal record listing; it cannot establish clinical service timing.';
        if ($table === 'consultations') {
            $ncdRows = array_values(array_filter($rows, static fn(array $r): bool => substr($r['consultation_date'],0,10) >= sprintf('%04d-01-01',$year)));
            $data['indicator_cells'][5] = fhsisNcdIndicatorCells($ncdRows, $period[0]);
        }
        if ($table === 'vaccination_records') $data['indicator_cells'][3] = fhsisVaccineIndicatorCells($rows, $year);
        if ($table === 'dental_records') $data['indicator_cells'][4] = fhsisDentalIndicatorCells($rows, $period[0], $issues);
        if ($table === 'vital_statistics') $data['indicator_cells'][10] = fhsisVitalIndicatorCells($rows, $issues);
        $rows = array_values(array_filter($rows, static fn(array $r): bool => substr((string)$r[$dateColumn],0,10) >= $period[0]));
        $safeFields = array_flip(['resident_name', 'report_barangay', 'report_sex', 'consultation_date', 'consultation_time', 'chief_complaint', 'diagnosis', 'icd_code', 'treatment', 'medications_prescribed', 'consultation_status', 'vaccination_date', 'vaccine_name', 'dose_number', 'next_dose_date', 'pregnancy_status', 'last_menstrual_period', 'expected_delivery_date', 'prenatal_visits_count', 'risk_level', 'visit_date', 'record_date', 'method', 'status', 'record_type', 'title', 'findings', 'event_date', 'event_type', 'birth_status', 'last_checkup_date', 'height_cm', 'weight_kg', 'blood_pressure']);
        $data['source_records'][$table] = array_map(static fn(array $r): array => array_intersect_key($r, $safeFields), $rows);
        $data['notes'][] = $table . ': ' . count($rows) . ' non-confidential source record(s) for the selected month; filtered by ' . $dateColumn . '.';
    }
    if (isset($available['consultations']) || isset($available['family_planning_records'])) {
        $data['indicator_cells'][1] = fhsisFpIndicatorCells(fhsisFpSourceRows($raw), $period[0], $issues);
    }
    if (isset($data['indicator_cells'][10]['N33'])) {
        $data['indicator_cells'][11] = ['AG38'=>$data['indicator_cells'][10]['L33'],'AK38'=>$data['indicator_cells'][10]['M33'],'AO38'=>$data['indicator_cells'][10]['N33']];
    }
    if (isset($available['vaccination_records'])) {
        foreach ([6=>'PPV',8=>'INFLUENZA'] as $r=>$vaccine) {
            $qualifying = static function(array $row) use($vaccine): bool {
                $name = strtoupper(trim((string)($row['vaccine_name']??'')));
                $match = $vaccine==='PPV' ? (bool)preg_match('/^(?:PPV23|PPSV23|PNEUMOCOCCAL POLYSACCHARIDE)(?:\b|$)/',$name) : (bool)preg_match('/^(?:INFLUENZA|FLU)(?:\b|$)/',$name);
                $age=fhsisAge($row,'vaccination_date');
                return $match && (!$age || $age->y>=60);
            };
            $data['indicator_cells'][6] = ($data['indicator_cells'][6]??[]) + fhsisSexCells($raw['vaccination_records'],'vaccination_date',$qualifying,'L'.$r,'M'.$r,'N'.$r,$issues,'Senior vaccination');
        }
    }
    foreach ($raw as $table=>$rows) {
        $missing=0; $unserved=0;
        foreach ($rows as $row) {
            if (!($table === 'sanitary_records' && empty($row['resident_id'])) && array_key_exists('resident_id',$row) && (empty($row['resident_id']) || !fhsisDate($row['report_birthdate']??null) || !in_array(strtolower(trim((string)($row['report_sex']??''))),['m','male','f','female'],true))) $missing++;
            if (!fhsisCompletedSource($table,$row)) $unserved++;
        }
        if ($missing) $issues[]=$table.': '.$missing.' record(s) lack a linked resident, valid birthdate or recognized sex. Affected indicators need review.';
        if ($unserved) $issues[]=$table.': '.$unserved.' record(s) do not have a reportable completed/recorded status and cannot establish delivered services.';
    }
    $data['notes'][] = 'NCD prior-month rows are the sum of distinct resident counts in each earlier month of the reporting year (not a unique year-to-date population). Current-month counts deduplicate each resident per age group and diagnosis.';
    $data['notes'][] = 'The normal download excludes identifiable System Records. Missing indicator values display 0; missing clinical evidence remains identified in the review sheets.';
    {
        foreach (fhsisReadDocumentedCells($pdo,$period[0],$issues,fhsisAutomaticFacts($raw,$period[0]),$sourceColumns) as $sheet=>$cells) {
            // Union underlying identities; a chart annotation is not an additional patient.
            $data['indicator_cells'][$sheet]=array_replace($data['indicator_cells'][$sheet]??[],$cells);
        }
        $data['notes'][]='Additional indicators use clinician-verified service documentation linked to unchanged, reportable resident records. Duplicate residents and implied parent indicators are counted as sets, not added together.';
    }
    if (adminExtendedTableExists($pdo,'fhsis_reports')) {
        $data['indicator_cells'][10]=($data['indicator_cells'][10]??[])+fhsisReadScreeningCells($pdo,$period[0]);
        $data['notes'][]='Maternal infection screening uses MedTech-approved, de-identified reporting contributions only. The report does not query or decrypt confidential laboratory records.';
    }
    $data['validation'] = fhsisCoverageRows($data);
    $data['issues'] = array_values(array_unique($issues));
    $data['submission_ready'] = false;
    array_unshift($data['notes'], 'DRAFT - NOT VALIDATED FOR DOH SUBMISSION. Resolve unavailable indicators, verify register completeness and reconcile with the RHU FHSIS coordinator and applicable DOH template.');
    $data['notes'] = array_merge($data['notes'], $data['issues']);
    $data['notes'][] = 'Generated at '.(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s P').'.';
    $data['notes'][] = 'DOH CY 2026 guidance reference: https://sites.google.com/view/doh-hfdb/2025-q4-updates/dc-2025-0525 . Template and indicator definitions require local verification.';
    return $data;
}

function fhsisVaccineIndicatorCells(array $rows, int $year): array
{
    foreach ($rows as $r) {
        if (!fhsisRecorded($r)) continue;
        if (empty($r['resident_id']) || !fhsisAge($r,'vaccination_date') || !in_array(strtolower(trim((string)($r['report_sex']??''))),['m','male','f','female'],true)) return [];
    }
    $counts = []; $seen = [];
    $leftRows = ['DPT1' => 11, 'DPT2' => 12, 'DPT3' => 13, 'OPV1' => 14, 'OPV2' => 15, 'OPV3' => 16, 'IPV1' => 17, 'IPV2' => 18, 'PCV1' => 19, 'PCV2' => 20, 'PCV3' => 21, 'MMR1' => 22];
    $rightRows = ['DPT1' => 5, 'DPT2' => 6, 'DPT3' => 7, 'OPV1' => 8, 'OPV2' => 9, 'OPV3' => 10, 'IPV1' => 11, 'IPV2' => 12, 'PCV1' => 13, 'PCV2' => 14, 'PCV3' => 15, 'MMR1' => 16, 'MMR2' => 17];
    foreach ($rows as $r) {
        if (!fhsisRecorded($r) || empty($r['resident_id'])) continue;
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($r['report_birthdate'] ?? ''), 0, 10));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($r['vaccination_date'] ?? ''), 0, 10));
        if (!$birth || !$date || !fhsisDate($r['vaccination_date']??null) || $birth > $date || $birth->format('Y-m-d') !== substr((string)$r['report_birthdate'], 0, 10) || $birth->diff($date)->y !== 0) continue;
        $cohort = (int)$birth->format('Y') === $year ? 'current' : ((int)$birth->format('Y') === $year - 1 ? 'previous' : null);
        if (!$cohort) continue;
        $name = strtoupper(trim((string)($r['vaccine_name'] ?? '')));
        $vaccine = preg_match('/^(?:DPT[- ]?HIB[- ]?HEP[- ]?B|PENTAVALENT|PENTA)(?:\s|$)/', $name) ? 'DPT' : null;
        foreach (['OPV', 'IPV', 'PCV', 'MMR'] as $candidate) if (preg_match('/^' . $candidate . '(?:\s|$)/', $name)) $vaccine = $candidate;
        $doseText = trim((string)($r['dose_number'] ?? ''));
        $dose = preg_match('/^(?:dose\s*)?([123])(?:st|nd|rd)?(?:\s*dose)?$/i', $doseText, $m) ? $m[1] : null;
        $key = $vaccine . $dose; $map = $cohort === 'current' ? $leftRows : $rightRows;
        if (!$vaccine || !$dose || !isset($map[$key])) continue;
        $row = $map[$key]; $bucket = "$cohort:$row";
        if (isset($seen[$bucket][$r['resident_id']])) continue;
        $seen[$bucket][$r['resident_id']] = true;
        $sex = strtolower(trim((string)($r['report_sex'] ?? '')));
        $sex = in_array($sex, ['male', 'm'], true) ? 'male' : (in_array($sex, ['female', 'f'], true) ? 'female' : 'unknown');
        $counts[$bucket][$sex] = ($counts[$bucket][$sex] ?? 0) + 1;
    }
    $cells = [];
    foreach (['current' => $leftRows, 'previous' => $rightRows] as $cohort => $map) foreach ($map as $row) {
        $c = $counts["$cohort:$row"] ?? []; $right = $cohort === 'previous';
        if (!empty($c['unknown'])) continue;
        $cells[($right ? 'AB' : 'L') . $row] = $c['male'] ?? 0;
        $cells[($right ? 'AC' : 'M') . $row] = $c['female'] ?? 0;
        $cells[($right ? 'AD' : 'N') . $row] = array_sum($c);
    }
    return $cells;
}

/** Use recorded diagnoses, never infer a disease from symptoms or measurements. */
function fhsisNcdIndicatorCells(array $rows, string $monthStart): array
{
    foreach ($rows as $r) {
        if (!fhsisRecorded($r)) continue;
        $dx=trim((string)($r['diagnosis']??'')); $icd=strtoupper(trim((string)($r['icd_code']??'')));
        $candidate=preg_match('/^(?:I1[0-5]|E11)(?:\.|$)/',$icd) || preg_match('/hypertension|diabetes/i',$dx);
        if ($candidate && (empty($r['resident_id']) || !fhsisAge($r,'consultation_date') || !in_array(strtolower(trim((string)($r['report_sex']??''))),['m','male','f','female'],true))) return [];
    }
    $counts = []; $seen = [];
    foreach ($rows as $r) {
        if (rhuRecordIsConfidential($r)) continue;
        if (!in_array(strtolower(trim((string)($r['consultation_status'] ?? ''))), ['completed', 'done', 'served', 'recorded'], true)) continue;
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($r['report_birthdate'] ?? ''), 0, 10));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($r['consultation_date'] ?? ''), 0, 10));
        if (!$birth || !$date || !fhsisDate($r['consultation_date']??null) || $birth > $date || $birth->format('Y-m-d') !== substr((string)$r['report_birthdate'], 0, 10)) continue;
        $age = $birth->diff($date)->y;
        $group = $age >= 60 ? 'senior' : ($age >= 20 && $age <= 59 ? 'adult' : null);
        if (!$group || empty($r['resident_id'])) continue;
        $sex = strtolower(trim((string)($r['report_sex'] ?? '')));
        $sex = in_array($sex, ['male', 'm'], true) ? 'male' : (in_array($sex, ['female', 'f'], true) ? 'female' : 'unknown');
        $dx = trim((string)($r['diagnosis'] ?? '')); $icd = strtoupper(trim((string)($r['icd_code'] ?? '')));
        $diseases = [];
        if (preg_match('/^I1[0-5](?:\.|$)/', $icd) || preg_match('/^(?:essential(?: \(primary\))? |primary )?hypertension(?:\b|$)/i', $dx)) $diseases[] = 'hypertension';
        if (preg_match('/^E11(?:\.|$)/', $icd) || preg_match('/^(?:type\s*(?:2|ii)\s+diabetes(?: mellitus)?|diabetes mellitus\s*type\s*(?:2|ii))\b/i', $dx)) $diseases[] = 'diabetes';
        $period = $date->format('Y-m-d') < $monthStart ? 'prior' : 'current';
        foreach ($diseases as $disease) {
            $key = "$disease:$group:$period";
            $identity = $r['resident_id'].':'.$date->format('Y-m');
            if (isset($seen[$key][$identity])) continue;
            $seen[$key][$identity] = true;
            $counts[$key][$sex] = ($counts[$key][$sex] ?? 0) + 1;
        }
    }
    $cells = [];
    foreach (['hypertension:adult:prior' => [17, false], 'hypertension:adult:current' => [18, false], 'hypertension:senior:prior' => [24, false], 'hypertension:senior:current' => [25, false], 'diabetes:adult:prior' => [16, true], 'diabetes:adult:current' => [17, true], 'diabetes:senior:prior' => [23, true], 'diabetes:senior:current' => [24, true]] as $key => [$row, $right]) {
        $count = $counts[$key] ?? [];
        if (!empty($count['unknown'])) continue;
        $cells[($right ? 'AB' : 'L') . $row] = $count['male'] ?? 0;
        $cells[($right ? 'AC' : 'M') . $row] = $count['female'] ?? 0;
        $cells[($right ? 'AD' : 'N') . $row] = array_sum($count);
    }
    return $cells;
}

/** Return the 0-based M2 age band. Missing or impossible dates are not guessed. */
function fhsisMorbidityAgeBand(?string $birthdate, string $caseDate): ?int
{
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)$birthdate, 0, 10));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($caseDate, 0, 10));
    if (!$birth || !$date || $birth->format('Y-m-d') !== substr((string)$birthdate, 0, 10)
        || $date->format('Y-m-d') !== substr($caseDate, 0, 10) || $birth > $date) return null;
    $age = $birth->diff($date);
    if ($age->y === 0) return $age->days <= 6 ? 0 : ($age->days <= 28 ? 1 : 2);
    if ($age->y < 5) return 3;
    if ($age->y < 10) return 4;
    if ($age->y < 15) return 5;
    if ($age->y < 20) return 6;
    return min(17, 7 + intdiv($age->y - 20, 5));
}

function fhsisMorbidityGroups(array $cases): array
{
    $groups = [];
    foreach ($cases as $case) {
        $name = trim((string)($case['disease'] ?? '')) ?: 'Unclassified disease';
        $code = trim((string)($case['icd'] ?? ''));
        $key = $name . "\0" . $code;
        if (!isset($groups[$key])) $groups[$key] = ['disease' => $name, 'icd' => $code,
            'bands' => array_fill(0, 36, 0), 'male' => 0, 'female' => 0, 'total' => 0, 'unclassified' => 0];
        $g = &$groups[$key];
        $g['total']++;
        $sex = strtolower(trim((string)($case['sex'] ?? '')));
        $sex = in_array($sex, ['male', 'm'], true) ? 0 : (in_array($sex, ['female', 'f'], true) ? 1 : null);
        if ($sex !== null) $g[$sex === 0 ? 'male' : 'female']++;
        $band = fhsisMorbidityAgeBand($case['birthdate'] ?? null, (string)$case['date']);
        if ($band !== null && $sex !== null) $g['bands'][$band * 2 + $sex]++;
        else $g['unclassified']++;
        unset($g);
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($groups);
}

function fhsisColumnName(int $n): string
{
    $name = '';
    while ($n > 0) { $n--; $name = chr(65 + $n % 26) . $name; $n = intdiv($n, 26); }
    return $name;
}

/** Hosts commonly enable ZipArchive while disabling PharData. Prefer native ZIP. */
class FhsisReportArchive implements ArrayAccess
{
    private $archive;
    private bool $nativeZip;
    // libzip may not expose a replaced/new entry through getFromName until close.
    private array $pendingEntries = [];
    public function __construct(string $path)
    {
        $this->nativeZip = class_exists('ZipArchive');
        if ($this->nativeZip) {
            $this->archive = new ZipArchive();
            if ($this->archive->open($path) !== true) throw new RuntimeException('Cannot open FHSIS template archive.');
        } elseif (class_exists('PharData')) {
            $this->archive = new PharData($path);
        } else {
            throw new RuntimeException('This server needs the PHP ZIP extension to generate Excel reports.');
        }
    }
    public function read(string $name): string
    {
        if (array_key_exists($name, $this->pendingEntries)) return $this->pendingEntries[$name];
        $value = $this->nativeZip ? $this->archive->getFromName($name) : $this->archive[$name]->getContent();
        if ($value === false) throw new RuntimeException('The FHSIS template is incomplete: missing or unreadable archive entry ' . $name . '.');
        return $value;
    }
    public function offsetExists($offset): bool { return array_key_exists((string)$offset,$this->pendingEntries) || ($this->nativeZip ? $this->archive->locateName($offset) !== false : isset($this->archive[$offset])); }
    public function offsetGet($offset): mixed { return $this->read((string)$offset); }
    public function offsetSet($offset, $value): void
    {
        if ($this->nativeZip) {
            if (!$this->archive->addFromString((string)$offset, (string)$value)) throw new RuntimeException('Cannot write FHSIS report worksheet.');
            $this->pendingEntries[(string)$offset] = (string)$value;
        } else $this->archive[$offset] = $value;
    }
    public function offsetUnset($offset): void { if ($this->nativeZip) $this->archive->deleteName($offset); else unset($this->archive[$offset]); unset($this->pendingEntries[(string)$offset]); }
    public function close(): void
    {
        if ($this->archive !== null && $this->nativeZip && !$this->archive->close()) throw new RuntimeException('Cannot finish FHSIS report archive.');
        $this->archive = null;
        $this->pendingEntries = [];
    }
}

/** Modify cell contents only: preserve the template's styles, merges and printing. */
function fhsisTemplateSetCell(DOMDocument $doc, string $address, $value): void
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xp = new DOMXPath($doc); $xp->registerNamespace('s', $ns);
    $cell = $xp->query('//s:sheetData/s:row/s:c[@r="' . $address . '"]')->item(0);
    if (!$cell) {
        preg_match('/^([A-Z]+)([0-9]+)$/', $address, $match);
        $row = $xp->query('//s:sheetData/s:row[@r="' . $match[2] . '"]')->item(0);
        if (!$row) throw new RuntimeException('Template row is missing: ' . $address);
        $cell = $doc->createElementNS($ns, 'c'); $cell->setAttribute('r', $address);
        $before = null;
        foreach ($row->childNodes as $existing) {
            if ($existing instanceof DOMElement && strlen($existing->getAttribute('r')) > 0) {
                preg_match('/^[A-Z]+/', $existing->getAttribute('r'), $other);
                if (strlen($other[0]) > strlen($match[1]) || (strlen($other[0]) === strlen($match[1]) && strcmp($other[0], $match[1]) > 0)) { $before = $existing; break; }
            }
        }
        $row->insertBefore($cell, $before);
    }
    while ($cell->firstChild) $cell->removeChild($cell->firstChild);
    if (is_int($value)) {
        $cell->removeAttribute('t'); $v = $doc->createElementNS($ns, 'v'); $v->appendChild($doc->createTextNode((string)$value)); $cell->appendChild($v);
    } else {
        $cell->setAttribute('t', 'inlineStr'); $is = $doc->createElementNS($ns, 'is'); $t = $doc->createElementNS($ns, 't');
        $t->appendChild($doc->createTextNode(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value)));
        $is->appendChild($t); $cell->appendChild($is);
    }
}

/** Display defaults only; keep missing-evidence validation separate from measured counts. */
function fhsisCountDisplayCells(array $indicatorCells): array
{
    $cells = [];
    foreach (fhsisIndicators() as $metric) foreach ($metric['columns'] as $column) {
        $cells[$metric['sheet']][$column.$metric['row']] = 0;
    }
    foreach (range(14,32) as $row) foreach (range(7,30) as $column) $cells[1][fhsisColumnName($column).$row] = 0;
    foreach ($indicatorCells as $sheet=>$values) foreach ($values as $address=>$value) {
        if ($value !== null && $value !== '') $cells[$sheet][$address] = $value;
    }
    return $cells;
}

/** PharData permits ZIP exports on XAMPP installations with ZipArchive disabled. */
function fhsisWriteTemplateReport(array $data, string $path): void
{
    $template = dirname(__DIR__) . '/assets/templates/fhsis-m1-m2.xlsx';
    if (!is_readable($template)) throw new RuntimeException('FHSIS template is missing. Upload assets/templates/fhsis-m1-m2.xlsx to the hosted site.');
    $temporary = tempnam(sys_get_temp_dir(), 'fhsis-template-');
    if ($temporary === false) throw new RuntimeException('Cannot create report temporary file.');
    $archivePath = $temporary . '.zip';
    try {
        if (!copy($template, $archivePath)) throw new RuntimeException('Cannot copy FHSIS template.');
        $archive = new FhsisReportArchive($archivePath);
        $read = static fn(string $name): string => $archive->read($name);
        $groups = fhsisMorbidityGroups($data['cases'] ?? []);
        $notes = $data['notes'] ?? [];
        $notes[] = 'Display convention: report count cells without an available calculated value display 0. These display defaults do not confirm absence of cases or services; consult Indicator Review and Data Quality for missing evidence.';
        $displayCells = fhsisCountDisplayCells($data['indicator_cells'] ?? []);
        // Extra morbidity pages keep every disease rather than truncating after 14 rows.
        $chunks = array_chunk($groups, 14) ?: [[]];
        $workbook = new DOMDocument(); $workbook->loadXML($read('xl/workbook.xml'), LIBXML_NONET);
        $rels = new DOMDocument(); $rels->loadXML($read('xl/_rels/workbook.xml.rels'), LIBXML_NONET);
        $types = new DOMDocument(); $types->loadXML($read('[Content_Types].xml'), LIBXML_NONET);
        $sheetNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $baseM2 = $read('xl/worksheets/sheet11.xml');
        $metadata = [1 => 'FHSIS REPORT for the Month ' . $data['month'] . '   Year ' . $data['year'],
            2 => 'Name of Barangay: ' . $data['scope'], 3 => 'Name of BHS: ' . $data['facility'],
            4 => 'Name of Municipality/City: ' . $data['municipality'], 5 => 'Name of Province: ' . $data['province']];
        foreach ([1, 11] as $i) {
            $doc = new DOMDocument(); $doc->loadXML($read("xl/worksheets/sheet{$i}.xml"), LIBXML_NONET);
            foreach ($metadata as $r => $value) fhsisTemplateSetCell($doc, 'F' . $r, $value);
            $archive["xl/worksheets/sheet{$i}.xml"] = $doc->saveXML();
        }
        foreach ($displayCells as $sheet => $cells) {
            $doc = new DOMDocument(); $doc->loadXML($read("xl/worksheets/sheet{$sheet}.xml"), LIBXML_NONET);
            foreach ($cells as $address => $value) fhsisTemplateSetCell($doc, $address, $value);
            foreach ($doc->getElementsByTagName('sheetView') as $view) $view->setAttribute('showZeros', '1');
            $archive["xl/worksheets/sheet{$sheet}.xml"] = $doc->saveXML();
        }
        foreach ($chunks as $page => $chunk) {
            $id = 11 + $page;
            $doc = new DOMDocument(); $doc->loadXML($baseM2, LIBXML_NONET);
            foreach ($metadata as $r => $value) fhsisTemplateSetCell($doc, 'F' . $r, $value);
            foreach ($displayCells[11] ?? [] as $address=>$value) fhsisTemplateSetCell($doc, $address, $value);
            foreach (range(11,24) as $row) foreach (range(9,47) as $column) fhsisTemplateSetCell($doc, fhsisColumnName($column).$row, 0);
            foreach ($doc->getElementsByTagName('sheetView') as $view) $view->setAttribute('showZeros', '1');
            foreach ($chunk as $index => $g) {
                $r = $index + 11;
                fhsisTemplateSetCell($doc, 'A' . $r, $g['disease']);
                fhsisTemplateSetCell($doc, 'G' . $r, $g['icd']);
                foreach ($g['bands'] as $b => $count) fhsisTemplateSetCell($doc, fhsisColumnName(9 + $b) . $r, $count);
                foreach (['AS' => 'male', 'AT' => 'female', 'AU' => 'total'] as $c => $key) fhsisTemplateSetCell($doc, $c . $r, $g[$key]);
                if ($g['unclassified']) $notes[] = $g['disease'] . ': ' . $g['unclassified'] . ' case(s) lack a valid age or sex; included in the total.';
            }
            $archive["xl/worksheets/sheet{$id}.xml"] = $doc->saveXML();
            if ($page > 0) {
                $s = $workbook->createElementNS($sheetNs, 'sheet'); $s->setAttribute('name', 'M2 Morbidity ' . ($page + 1)); $s->setAttribute('sheetId', (string)$id);
                $s->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', 'fhsis' . $id);
                $workbook->getElementsByTagName('sheets')->item(0)->appendChild($s);
                $rel = $rels->createElementNS($rels->documentElement->namespaceURI, 'Relationship');
                foreach (['Id' => 'fhsis' . $id, 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'Target' => "worksheets/sheet{$id}.xml"] as $k => $v) $rel->setAttribute($k, $v);
                $rels->documentElement->appendChild($rel);
                $type = $types->createElementNS($types->documentElement->namespaceURI, 'Override'); $type->setAttribute('PartName', "/xl/worksheets/sheet{$id}.xml"); $type->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'); $types->documentElement->appendChild($type);
                $area = $workbook->createElementNS($sheetNs, 'definedName'); $area->setAttribute('name', '_xlnm.Print_Area'); $area->setAttribute('localSheetId', (string)($id - 1)); $area->appendChild($workbook->createTextNode("'M2 Morbidity " . ($page + 1) . "'!\$A\$1:\$AW\$38")); $workbook->getElementsByTagName('definedNames')->item(0)->appendChild($area);
            }
        }
        $notesId = 11 + count($chunks);
        if (!empty($data['source_records']) && ($data['include_source_records'] ?? true)) {
            $records = [];
            foreach ($data['source_records'] as $source => $rows) foreach ($rows as $record) $records[] = ['record_source' => $source] + $record;
            $headers = ['record_source'];
            foreach ($records as $record) $headers = array_values(array_unique(array_merge($headers, array_keys($record))));
            $sourceDoc = new DOMDocument();
            $sourceDoc->loadXML('<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="' . count($headers) . '" width="24" customWidth="1"/></cols><sheetData/></worksheet>');
            foreach (array_merge([array_combine($headers, $headers)], $records) as $index => $record) {
                $row = $sourceDoc->createElementNS($sheetNs, 'row'); $row->setAttribute('r', (string)($index + 1));
                $sourceDoc->getElementsByTagName('sheetData')->item(0)->appendChild($row);
                foreach ($headers as $c => $header) fhsisTemplateSetCell($sourceDoc, fhsisColumnName($c + 1) . ($index + 1), $record[$header] ?? '');
            }
            $archive["xl/worksheets/sheet{$notesId}.xml"] = $sourceDoc->saveXML();
            $s = $workbook->createElementNS($sheetNs, 'sheet'); $s->setAttribute('name', 'System Records'); $s->setAttribute('sheetId', (string)$notesId);
            $s->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', 'fhsisSources');
            $workbook->getElementsByTagName('sheets')->item(0)->appendChild($s);
            $rel = $rels->createElementNS($rels->documentElement->namespaceURI, 'Relationship');
            foreach (['Id' => 'fhsisSources', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'Target' => "worksheets/sheet{$notesId}.xml"] as $k => $v) $rel->setAttribute($k, $v);
            $rels->documentElement->appendChild($rel);
            $type = $types->createElementNS($types->documentElement->namespaceURI, 'Override'); $type->setAttribute('PartName', "/xl/worksheets/sheet{$notesId}.xml"); $type->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'); $types->documentElement->appendChild($type);
            $notesId++;
        }
        if (!empty($data['validation'])) {
            fhsisAppendReviewSheet($archive,$workbook,$rels,$types,$notesId++,'Indicator Review',$data['validation']);
            $review = [['check'=>'Submission status','result'=>'DRAFT - NOT VALIDATED FOR DOH SUBMISSION'],
                ['check'=>'Scope','result'=>$data['scope']],
                ['check'=>'Report period','result'=>$data['month'].' '.$data['year']],
                ['check'=>'Completeness','result'=>'Calculated zeros mean no qualifying records found in the queried source, not confirmation of a complete facility register.'],
                ['check'=>'Required review','result'=>'Verify official template, source registers, missing indicators, opening balances and coordinator sign-off.']];
            foreach ($data['issues']??[] as $issue) $review[]=['check'=>'Source data issue','result'=>$issue];
            fhsisAppendReviewSheet($archive,$workbook,$rels,$types,$notesId++,'Data Quality',$review);
        }
        $notesDoc = new DOMDocument();
        $notesDoc->loadXML('<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="120" customWidth="1"/></cols><sheetData/></worksheet>');
        $reportNotes = array_merge(['FHSIS report notes', $data['month'] . ' ' . $data['year'], 'The M1/M2 layout is recreated from the supplied photographs; it is not an original DOH workbook.'], $notes);
        foreach ($reportNotes as $index => $note) {
            $row = $notesDoc->createElementNS($sheetNs, 'row'); $row->setAttribute('r', (string)($index + 1));
            $notesDoc->getElementsByTagName('sheetData')->item(0)->appendChild($row);
            fhsisTemplateSetCell($notesDoc, 'A' . ($index + 1), $note);
        }
        $archive["xl/worksheets/sheet{$notesId}.xml"] = $notesDoc->saveXML();
        $s = $workbook->createElementNS($sheetNs, 'sheet'); $s->setAttribute('name', 'Report Notes'); $s->setAttribute('sheetId', (string)$notesId);
        $s->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', 'fhsisNotes');
        $workbook->getElementsByTagName('sheets')->item(0)->appendChild($s);
        $rel = $rels->createElementNS($rels->documentElement->namespaceURI, 'Relationship');
        foreach (['Id' => 'fhsisNotes', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'Target' => "worksheets/sheet{$notesId}.xml"] as $k => $v) $rel->setAttribute($k, $v);
        $rels->documentElement->appendChild($rel);
        $type = $types->createElementNS($types->documentElement->namespaceURI, 'Override'); $type->setAttribute('PartName', "/xl/worksheets/sheet{$notesId}.xml"); $type->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'); $types->documentElement->appendChild($type);
        $archive['xl/workbook.xml'] = $workbook->saveXML();
        $archive['xl/_rels/workbook.xml.rels'] = $rels->saveXML();
        $archive['[Content_Types].xml'] = $types->saveXML();
        if (array_key_exists('submission_ready',$data) && !$data['submission_ready']) {
            foreach (range(1,10+count($chunks)) as $sheet) {
                $doc=new DOMDocument(); $doc->loadXML($read('xl/worksheets/sheet'.$sheet.'.xml'),LIBXML_NONET);
                $footer=$doc->getElementsByTagName('headerFooter')->item(0);
                if (!$footer) { $footer=$doc->createElementNS($sheetNs,'headerFooter'); $doc->documentElement->appendChild($footer); }
                $odd=$doc->getElementsByTagName('oddFooter')->item(0);
                if (!$odd) { $odd=$doc->createElementNS($sheetNs,'oddFooter'); $footer->appendChild($odd); }
                $odd->textContent='&LDRAFT - NOT VALIDATED FOR DOH SUBMISSION&RPage &P of &N';
                $archive['xl/worksheets/sheet'.$sheet.'.xml']=$doc->saveXML();
            }
        }
        // Keep methodology in the existing workbook description, outside printed forms.
        $props = new DOMDocument();
        $props->loadXML('<?xml version="1.0"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"/>');
        $description = $props->createElementNS('http://purl.org/dc/elements/1.1/', 'dc:description'); $description->appendChild($props->createTextNode(implode("\n", $notes))); $props->documentElement->appendChild($description);
        // Existing artifact export already declares core.xml and its relationship.
        $archive['docProps/core.xml'] = $props->saveXML();
        $archive->close();
        unset($archive);
        if (!copy($archivePath, $path)) throw new RuntimeException('Cannot save generated FHSIS report.');
    } finally {
        unset($archive);
        if (is_file($archivePath)) unlink($archivePath);
        if (is_file($temporary)) unlink($temporary);
    }
}

function fhsisAppendReviewSheet(FhsisReportArchive $archive, DOMDocument $workbook, DOMDocument $rels, DOMDocument $types, int $id, string $name, array $records): void
{
    $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $headers=array_keys($records[0]);
    $doc=new DOMDocument();
    $doc->loadXML('<?xml version="1.0"?><worksheet xmlns="'.$ns.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.count($headers).'" width="46" customWidth="1"/></cols><sheetData/></worksheet>');
    foreach (array_merge([array_combine($headers,$headers)],$records) as $index=>$record) {
        $row=$doc->createElementNS($ns,'row'); $row->setAttribute('r',(string)($index+1));
        $lines=1;
        foreach ($record as $value) $lines=max($lines,(int)ceil(strlen((string)$value)/42));
        $row->setAttribute('ht',(string)min(400,18*$lines+8)); $row->setAttribute('customHeight','1');
        $doc->getElementsByTagName('sheetData')->item(0)->appendChild($row);
        foreach ($headers as $column=>$header) fhsisTemplateSetCell($doc,fhsisColumnName($column+1).($index+1),$record[$header]??'');
        foreach ($row->childNodes as $cell) if ($cell instanceof DOMElement) $cell->setAttribute('s',$index===0?'7':'3');
    }
    $filter=$doc->createElementNS($ns,'autoFilter'); $filter->setAttribute('ref','A1:'.fhsisColumnName(count($headers)).(count($records)+1)); $doc->documentElement->appendChild($filter);
    $archive['xl/worksheets/sheet'.$id.'.xml']=$doc->saveXML();
    $sheet=$workbook->createElementNS($ns,'sheet'); $sheet->setAttribute('name',$name); $sheet->setAttribute('sheetId',(string)$id);
    $sheet->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','r:id','review'.$id);
    $workbook->getElementsByTagName('sheets')->item(0)->appendChild($sheet);
    $rel=$rels->createElementNS($rels->documentElement->namespaceURI,'Relationship');
    foreach (['Id'=>'review'.$id,'Type'=>'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet','Target'=>'worksheets/sheet'.$id.'.xml'] as $k=>$v) $rel->setAttribute($k,$v);
    $rels->documentElement->appendChild($rel);
    $type=$types->createElementNS($types->documentElement->namespaceURI,'Override');
    $type->setAttribute('PartName','/xl/worksheets/sheet'.$id.'.xml'); $type->setAttribute('ContentType','application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'); $types->documentElement->appendChild($type);
}
