<?php
require_once dirname(__DIR__) . '/includes/record-access.php';

function fhsisMonthlyWorksheets(PDO $pdo, int $year, int $month): array
{
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $params = [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
    $sheets = ['Report Summary' => [['report_period' => $start->format('F Y'), 'generated_at' => date('Y-m-d H:i:s')]]];
    $sources = [
        'consultations' => ['OPD and Appointments', ['consultation_date']],
        'appointments' => ['Completed Appointments', ['appointment_date', 'preferred_date', 'scheduled_date']],
        'disease_cases' => ['Disease Cases', ['case_date']],
        'pregnancy_records' => ['Maternity', ['created_at', 'last_menstrual_period']],
        'prenatal_visits' => ['Prenatal Visits', ['visit_date', 'consultation_date', 'created_at']],
        'prenatal_records' => ['Prenatal Records', ['visit_date', 'record_date', 'created_at']],
        'vaccination_records' => ['Immunizations', ['vaccination_date']],
        'dental_records' => ['Dental Records', ['consultation_date', 'visit_date', 'record_date', 'created_at']],
        'family_planning_records' => ['Family Planning', ['visit_date', 'record_date', 'created_at']],
        'vital_statistics' => ['Vital Statistics', ['event_date']],
        'health_records' => ['Health Records', ['record_date', 'consultation_date', 'created_at']],
    ];
    $staff = $_SESSION['rhu_staff_login'] ?? [];
    $canReadPrivate = (int)($staff['staff_id'] ?? 0) > 0 && rhuRecordStaffIsMedtech($staff);
    if ($canReadPrivate) $sources['confidential_lab_tests'] = ['Confidential Records', ['specimen_date', 'created_at']];
    foreach ($sources as $table => [$title, $dateCandidates]) {
        if (!adminExtendedTableExists($pdo, $table)) continue;
        $columns = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        $dateColumn = null;
        foreach ($dateCandidates as $candidate) {
            if (in_array($candidate, $columns, true)) { $dateColumn = $candidate; break; }
        }
        if ($dateColumn === null) throw new RuntimeException('No reporting date is available for ' . $table . '.');
        $select = 't.*';
        $join = '';
        if (in_array('resident_id', $columns, true)) {
            $select .= ", CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) AS resident_name, r.sex AS resident_sex, r.birthdate AS resident_birthdate, COALESCE(b.name, 'Unassigned') AS barangay";
            $join = ' LEFT JOIN residents r ON r.id=t.resident_id LEFT JOIN barangays b ON b.id=r.barangay_id';
        }
        if ($table === 'disease_cases' && in_array('disease_type_id', $columns, true)) {
            $select .= ', dt.name AS disease_name';
            $join .= ' LEFT JOIN disease_types dt ON dt.id=t.disease_type_id';
        }
        $where = 't.`' . $dateColumn . '` >= ? AND t.`' . $dateColumn . '` < ?';
        if ($table === 'appointments') {
            $statusColumn = in_array('appointment_status', $columns, true) ? 'appointment_status' : (in_array('status', $columns, true) ? 'status' : null);
            if (!$statusColumn) throw new RuntimeException('Appointment completion status is unavailable.');
            $where .= " AND LOWER(t.`{$statusColumn}`) IN ('completed','done','served')";
        }
        $queryParams = $params;
        if ($table === 'confidential_lab_tests') {
            $where .= ' AND t.created_by_staff_id = ?';
            $queryParams[] = (int)$staff['staff_id'];
        }
        $stmt = $pdo->prepare('SELECT ' . $select . ' FROM `' . $table . '` t' . $join . ' WHERE ' . $where . ' ORDER BY t.`' . $dateColumn . '`, t.id');
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($table === 'confidential_lab_tests') {
                foreach (['result_encrypted' => 'result', 'notes_encrypted' => 'confidential_notes'] as $encrypted => $plain) {
                    $payload = base64_decode((string)($row[$encrypted] ?? ''), true);
                    $row[$plain] = '';
                    if ($payload !== false && strlen($payload) >= 29) {
                        $material = (string)rhuEnv('RHU_CONFIDENTIAL_KEY', '') ?: (dirname(__DIR__) . '|rhu-confidential-testing-local-key');
                        $row[$plain] = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', hash('sha256', $material, true), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16)) ?: '';
                    }
                    unset($row[$encrypted]);
                }
            }
            if ($table !== 'confidential_lab_tests' && (!empty($row['is_confidential']) || strtolower((string)($row['confidentiality'] ?? '')) === 'confidential')) {
                if (rhuCanReadConfidentialRecord($staff, $row)) $sheets['Confidential Records'][] = ['record_source' => $table] + $row;
                $row = null;
            }
        }
        unset($row);
        $rows = array_values(array_filter($rows, static fn($row) => $row !== null));
        $sheets[$title] = array_merge($sheets[$title] ?? [], $rows);
        $sheets['Report Summary'][] = ['record_type' => $title, 'records' => count($rows)];
    }
    return fhsisOrganizeResidentWorksheets($sheets);
}

function fhsisOrganizeResidentWorksheets(array $sheets): array
{
    $records = [];
    foreach ($sheets as $service => $rows) {
        if (in_array($service, ['Report Summary', 'Confidential Records'], true)) continue;
        foreach ($rows as $row) {
            $pick = static function (array $keys) use ($row): string {
                foreach ($keys as $key) {
                    if (isset($row[$key]) && trim((string)$row[$key]) !== '') return (string)$row[$key];
                }
                return '';
            };
            $date = $pick(['consultation_date', 'appointment_date', 'preferred_date', 'scheduled_date', 'case_date', 'vaccination_date', 'visit_date', 'record_date', 'event_date', 'specimen_date', 'created_at', 'last_menstrual_period']);
            $time = $pick(['consultation_time', 'appointment_time', 'preferred_time', 'visit_time', 'event_time']);
            if ($time === '' && strlen($date) > 10) $time = substr($date, 11);
            $records[] = [
                'resident_name' => $pick(['resident_name', 'patient_name', 'full_name', 'mother_name']),
                'barangay' => $pick(['barangay']),
                'date' => substr($date, 0, 10),
                'time' => $time,
                'service' => $service,
                'consultation' => $pick(['chief_complaint', 'reason', 'complaint']),
                'diagnosis_or_case' => $pick(['diagnosis', 'disease_name', 'condition', 'event_type']),
                'treatment_or_service' => $pick(['treatment', 'procedure', 'vaccine_name', 'method']),
                'status' => $pick(['consultation_status', 'appointment_status', 'case_status', 'pregnancy_status', 'status']),
            ];
            $importantServiceFields = [
                'Maternity' => ['last_menstrual_period', 'expected_delivery_date', 'risk_level'],
                'Prenatal Visits' => ['gestational_age', 'blood_pressure', 'risk_level', 'next_visit_date'],
                'Prenatal Records' => ['gestational_age', 'blood_pressure', 'risk_level', 'next_visit_date'],
                'Immunizations' => ['dose_number', 'next_dose_date'],
                'Disease Cases' => ['outcome'],
                'Family Planning' => ['next_visit_date'],
            ];
            $recordIndex = array_key_last($records);
            foreach ($importantServiceFields[$service] ?? [] as $field) {
                if (array_key_exists($field, $row)) $records[$recordIndex][$field] = $row[$field];
            }
        }
    }
    usort($records, static fn(array $a, array $b): int => [$a['date'], $a['time'], $a['resident_name'], $a['service']] <=> [$b['date'], $b['time'], $b['resident_name'], $b['service']]);
    foreach ($sheets as $service => &$rows) {
        if ($service === 'Report Summary') continue;
        if ($service === 'Confidential Records') {
            $allowed = array_flip(['resident_name', 'barangay', 'specimen_date', 'release_date', 'test_type', 'result_status', 'result', 'confidential_notes']);
            $rows = array_map(static fn(array $row): array => array_intersect_key($row, $allowed), $rows);
        } else {
            $rows = array_values(array_filter($records, static fn(array $row): bool => $row['service'] === $service));
        }
    }
    unset($rows);
    $summary = $sheets['Report Summary'] ?? [];
    unset($sheets['Report Summary']);
    return ['Monthly Resident Records' => $records] + $sheets + ['Report Summary' => $summary];
}

function fhsisWriteWorkbook(array $sheets, string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create Excel report.');
    $escape = static fn($value) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $types = ''; $workbook = ''; $rels = ''; $index = 0;
    foreach ($sheets as $name => $rows) {
        $index++;
        $headers = [];
        foreach ($rows as $row) $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        if (!$headers) $headers = ['No records for this month'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="' . count($headers) . '" width="24" customWidth="1"/></cols><sheetData>';
        $allRows = [array_combine($headers, array_map(static fn($h) => ucwords(str_replace('_', ' ', $h)), $headers)), ...$rows];
        foreach ($allRows as $n => $row) {
            $xml .= '<row r="' . ($n + 1) . '">';
            foreach ($headers as $header) $xml .= '<c t="inlineStr"><is><t xml:space="preserve">' . $escape($row[$header] ?? '') . '</t></is></c>';
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $xml);
        $types .= '<Override PartName="/xl/worksheets/sheet' . $index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbook .= '<sheet name="' . $escape($name) . '" sheetId="' . $index . '" r:id="rId' . $index . '"/>';
        $rels .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $types . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbook . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->close();
}
