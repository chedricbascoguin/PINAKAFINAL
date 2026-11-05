<?php
require_once __DIR__ . '/includes/appointment-hours.php';
/**
 * DentalDashboard.php — RHU Dental Health Portal
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth: Dental / Dentist staff only
$stType = strtoupper((string) (
    $_SESSION['rhu_staff_login']['staff_type']
    ?? $_SESSION['rhu_staff_login']['position_title']
    ?? $_SESSION['rhu_staff_login']['role']
    ?? ''
));

$allowed = (
    $stType === 'DENTAL'
    || $stType === 'DENTIST'
    || str_contains($stType, 'DENTAL')
    || str_contains($stType, 'DENTIST')
);

if (empty($_SESSION['rhu_staff_login']) || !$allowed) {
    header('Location: RHULogin.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/rhu-patient-intake.php';
if ($pdo instanceof PDO) rhuIntakeEnsure($pdo);
require_once __DIR__ . '/includes/inventory-control.php';
require_once __DIR__ . '/includes/dental-exam-suggestions.php';
require_once __DIR__ . '/includes/dental-prescription-validation.php';
require_once __DIR__ . '/includes/dental-prescription-sheet.php';
require_once __DIR__ . '/includes/dental-chart.php';
require_once __DIR__ . '/includes/dental-record-history.php';
if (is_file(__DIR__ . '/includes/portal.php')) {
    require_once __DIR__ . '/includes/portal.php';
    if (function_exists('portalHandleNotificationApi')) {
        portalHandleNotificationApi($pdo);
    }
}
// Authorized dentists can load the selected resident's most recent chart.
if (($_GET['api'] ?? '') === 'latest_odontogram') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Database connection unavailable.');
        }
        $residentId = (int)($_GET['resident_id'] ?? 0);
        $examDate = (string)($_GET['exam_date'] ?? date('Y-m-d'));
        if ($residentId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) throw new InvalidArgumentException('Select a patient and valid examination date.');
        $statement = $pdo->prepare("SELECT * FROM dental_records WHERE resident_id = :resident AND record_type = 'examination' AND record_date <= :exam_date ORDER BY record_date ASC, id ASC");
        $statement->execute(['resident'=>$residentId, 'exam_date'=>$examDate]);
        $history = dentalCumulativeChart($statement->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['success'=>true, 'marks'=>(object)$history['marks'], 'record_date'=>$history['record_date'], 'sources'=>(object)$history['sources'], 'record_count'=>$history['record_count'], 'warnings'=>$history['warnings']], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'error'=>'Unable to load the previous chart. Please retry.']);
    }
    exit;
}

require_once __DIR__ . '/includes/appointment-email-reminders.php';
if ($pdo instanceof PDO && function_exists('rhuSendUpcomingAppointmentEmailReminders')) {
    rhuSendUpcomingAppointmentEmailReminders($pdo);
}

function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function dentalPrescriptionQuantityNumber(string $quantity): int
{
    if (preg_match('/\d+/', $quantity, $match)) {
        return max(0, (int) $match[0]);
    }
    return 0;
}

function dentalDeductPrescriptionInventory(PDO $pdo, int $inventoryId, int $quantity, string $medicineName, int $staffId, int $residentId = 0): array
{
    if ($inventoryId <= 0) {
        return [];
    }
    if ($quantity <= 0) {
        throw new InvalidArgumentException('Enter a numeric quantity to deduct from inventory.');
    }

    $dispensedAt = str_replace('T', ' ', (string)($_POST['dispensed_at'] ?? ''));
    if (strlen($dispensedAt) === 16) $dispensedAt .= ':00';
    $item = inventoryChange($pdo, $inventoryId, -$quantity, [
        'service'=>'Dental','location'=>$_POST['dispense_location'] ?? '', 'purpose'=>'Dental prescription dispensed',
        'resident_id'=>$residentId, 'used_at'=>$dispensedAt]);

    return [
        'inventory_id' => $inventoryId,
        'inventory_item_name' => (string) ($item['item_name'] ?? $medicineName),
        'deducted_quantity' => $quantity,
        'unit' => trim((string) ($item['unit'] ?? '')),
    ];
}

function tabUrl(string $tab, array $extra = []): string
{
    return '?' . http_build_query(array_merge(['tab' => $tab], $extra));
}

function ensureDentalTables(PDO $pdo): void
{
    // ONE table for all dental process records (exam, extraction, procedure, Rx, queue, referral)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dental_records (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        record_type VARCHAR(40) NOT NULL,
        resident_id BIGINT UNSIGNED NULL,
        walkin_name VARCHAR(150) NULL,
        record_date DATE NOT NULL,
        title VARCHAR(200) NULL,
        tooth_numbers VARCHAR(120) NULL,
        findings TEXT NULL,
        notes TEXT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'Completed',
        priority VARCHAR(30) NULL,
        meta_json LONGTEXT NULL,
        performed_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (record_type),
        INDEX idx_resident (resident_id),
        INDEX idx_date (record_date),
        INDEX idx_status (status),
        INDEX idx_type_date (record_type, record_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!function_exists('dentalRecordInsert')) {
        function dentalRecordInsert(PDO $pdo, array $row): int
        {
            $recordId = dentalInsertHistoricalRecord($pdo, $row);
            if (!empty($row['resident_id']) && !empty($row['performed_by'])
                && in_array($row['record_type'] ?? '', ['examination', 'extraction', 'procedure', 'prescription', 'referral'], true)) {
                $columns = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $providerColumns = array_values(array_intersect(['health_worker_id', 'physician_id'], $columns));
                if ($providerColumns) {
                    $providerSql = count($providerColumns) > 1 ? 'COALESCE(' . implode(', ', $providerColumns) . ')' : $providerColumns[0];
                    $match = $pdo->prepare("SELECT id FROM consultations WHERE resident_id = :resident
                        AND DATE(consultation_date) = :date AND {$providerSql} = :provider
                        AND LOWER(COALESCE(chief_complaint, '')) REGEXP 'dental|dentist|tooth|oral'
                        ORDER BY id LIMIT 2");
                    $match->execute(['resident' => (int)$row['resident_id'], 'date' => $row['record_date'] ?? date('Y-m-d'), 'provider' => (int)$row['performed_by']]);
                    $matches = $match->fetchAll(PDO::FETCH_COLUMN);
                    // Only synchronize an unambiguous visit for this patient and dentist.
                    if (count($matches) === 1) {
                        $updates = [];
                        $params = ['id' => (int)$matches[0]];
                        $summary = trim(implode("\n", array_filter([
                            $row['title'] ?? '',
                            !empty($row['tooth_numbers']) ? 'Teeth: ' . $row['tooth_numbers'] : '',
                            $row['findings'] ?? '', $row['notes'] ?? '',
                        ])));
                        if (in_array('consultation_status', $columns, true)) {
                            $updates[] = 'consultation_status = :status';
                            $params['status'] = ($row['record_type'] === 'referral') ? 'Referred' : 'Completed';
                        }
                        if (!empty($row['findings']) && in_array('diagnosis', $columns, true)) {
                            $updates[] = 'diagnosis = :diagnosis';
                            $params['diagnosis'] = $row['findings'];
                        }
                        foreach (['remarks', 'consultation_notes'] as $column) {
                            if (!in_array($column, $columns, true)) continue;
                            $updates[] = "{$column} = CONCAT_WS(CHAR(10), NULLIF({$column}, ''), :{$column})";
                            $params[$column] = $summary;
                        }
                        if ($row['record_type'] === 'prescription' && in_array('medications_prescribed', $columns, true)) {
                            $updates[] = "medications_prescribed = CONCAT_WS(', ', NULLIF(medications_prescribed, ''), :medicine)";
                            $params['medicine'] = trim(implode(' ', array_filter([
                                $row['title'] ?? '', $row['meta']['dosage'] ?? '',
                                $row['meta']['frequency'] ?? '', $row['meta']['duration'] ?? '',
                            ])));
                        }
                        if (in_array('updated_at', $columns, true)) $updates[] = 'updated_at = NOW()';
                        if ($updates) {
                            $sync = $pdo->prepare('UPDATE consultations SET ' . implode(', ', $updates) . ' WHERE id = :id');
                            $sync->execute($params);
                        }
                    }
                }
            }
            if (in_array($_POST['action'] ?? '', ['save_extraction','save_procedure'], true)) fhsisSaveInlineServices($pdo, 'dental_records', $recordId, (int)($row['resident_id'] ?? 0), $_POST, $_SESSION);
            return $recordId;
        }
    }

    if (!function_exists('dentalRecordsByType')) {
        function dentalRecordsByType(PDO $pdo, string $type, ?int $limit = 50, ?string $extraWhere = null, string $order = 'record_date DESC, id DESC'): array
        {
            $livingResidentWhere = function_exists('rhuLivingResidentWhereSql')
                ? rhuLivingResidentWhereSql('r')
                : "COALESCE(r.is_active, 1) = 1 AND LOWER(COALESCE(r.status, 'Active')) NOT IN ('deceased', 'dead', 'inactive')";
            $sql = "SELECT dr.*, CONCAT(COALESCE(r.first_name,''), ' ', COALESCE(r.last_name,'')) AS resident_name
                    FROM dental_records dr
                    LEFT JOIN residents r ON r.id = dr.resident_id
                    WHERE dr.record_type = :type
                      AND (dr.resident_id IS NULL OR ({$livingResidentWhere}))";
            global $dentalSearch;
            $params = ['type' => $type];
            if ($dentalSearch !== '') {
                $sql .= " AND LOCATE(LOWER(:search), LOWER(CONCAT_WS(' ', r.first_name, r.last_name,
                    dr.resident_id, dr.walkin_name, dr.record_date, dr.title, dr.tooth_numbers,
                    dr.findings, dr.notes, dr.status, dr.priority, dr.meta_json))) > 0";
                $params['search'] = $dentalSearch;
            }
            if ($extraWhere) {
                $sql .= ' AND (' . $extraWhere . ')';
            }
            $sql .= ' ORDER BY ' . $order;
            if ($limit !== null) {
                $sql .= ' LIMIT ' . max(1, min(500, $limit));
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $meta = [];
                if (!empty($row['meta_json'])) {
                    $decoded = json_decode((string) $row['meta_json'], true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                $row['meta'] = $meta;
            }
            unset($row);
            return $rows;
        }
    }

    if (!function_exists('dentalCreateWalkInResident')) {
        function dentalCreateWalkInResident(PDO $pdo, array $post): int
        {
            $first = trim((string) ($post['walkin_first_name'] ?? ''));
            $last = trim((string) ($post['walkin_last_name'] ?? ''));
            $middle = trim((string) ($post['walkin_middle_name'] ?? ''));
            $sex = trim((string) ($post['walkin_sex'] ?? 'Female'));
            if ($sex === '') {
                $sex = 'Female';
            }
            $birth = trim((string) ($post['walkin_birthdate'] ?? ''));
            $hasValidBirthdate = $birth !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) === 1;
            if (!$hasValidBirthdate) {
                $birth = '2000-01-01';
            }
            $contact = trim((string) ($post['walkin_contact'] ?? ''));
            $barangayName = trim((string) ($post['walkin_barangay'] ?? 'Poblacion'));
            if ($barangayName === '') {
                $barangayName = 'Poblacion';
            }
            $address = trim((string) ($post['walkin_address'] ?? ''));
            if ($first === '' || $last === '') {
                throw new InvalidArgumentException('Walk-in patient requires first name and last name.');
            }
            if ($address === '') {
                $address = $barangayName . ', Nasugbu, Batangas';
            }

            $barangayId = 1;
            try {
                $st = $pdo->prepare('SELECT id FROM barangays WHERE name LIKE :n ORDER BY id ASC LIMIT 1');
                $st->execute(['n' => $barangayName]);
                $found = (int) ($st->fetchColumn() ?: 0);
                if ($found > 0) {
                    $barangayId = $found;
                } else {
                    // partial match e.g. "Poblacion"
                    $st = $pdo->prepare('SELECT id FROM barangays WHERE name LIKE :n ORDER BY id ASC LIMIT 1');
                    $st->execute(['n' => '%' . $barangayName . '%']);
                    $found = (int) ($st->fetchColumn() ?: 0);
                    if ($found > 0) {
                        $barangayId = $found;
                    } else {
                        $barangayId = (int) ($pdo->query('SELECT id FROM barangays ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
                    }
                }
            } catch (Throwable $ex) {
                $barangayId = 1;
            }

            // A legacy residents schema can have `id` as a primary key without
            // AUTO_INCREMENT. In that case MySQL supplies 0 for every insert and
            // the second walk-in fails with "Duplicate entry '0' for key PRIMARY".
            $idColumn = $pdo->query("SHOW COLUMNS FROM residents LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
            if (!$idColumn) {
                throw new RuntimeException('The residents table has no id column.');
            }
            if (stripos((string) ($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
                $idType = (string) ($idColumn['Type'] ?? '');
                if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\\(\\d+\\))?( unsigned)?$/i', $idType)) {
                    throw new RuntimeException('The residents.id column must be an integer auto-increment primary key.');
                }
                try {
                    $pdo->exec('ALTER TABLE residents MODIFY COLUMN `id` ' . $idType . ' NOT NULL AUTO_INCREMENT');
                } catch (Throwable $ex) {
                    throw new RuntimeException('Unable to enable automatic resident IDs: ' . $ex->getMessage(), 0, $ex);
                }
            }

            // Do not make another resident merely because staff selected Walk-in.
            // Reuse an exact identity match; for a name-only match, stop and ask
            // staff to select the registered patient so two different people with
            // the same name are not incorrectly merged.
            $residentColumns = $pdo->query('SHOW COLUMNS FROM residents')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $residentColumnSet = array_fill_keys($residentColumns, true);
            $identitySql = 'SELECT id FROM residents WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(:first_name)) '
                . 'AND LOWER(TRIM(last_name)) = LOWER(TRIM(:last_name))';
            $identityParams = ['first_name' => $first, 'last_name' => $last];
            $exactIdentity = false;
            $dateColumns = [];
            foreach (['birthdate', 'date_of_birth'] as $dateColumn) {
                if (isset($residentColumnSet[$dateColumn])) {
                    $dateColumns[] = '`' . $dateColumn . '`';
                }
            }
            if ($hasValidBirthdate && $dateColumns) {
                $identitySql .= ' AND COALESCE(' . implode(', ', $dateColumns) . ') = :birthdate';
                $identityParams['birthdate'] = $birth;
                $exactIdentity = true;
            } elseif ($contact !== '' && isset($residentColumnSet['contact_number'])) {
                $identitySql .= ' AND TRIM(contact_number) = TRIM(:contact_number)';
                $identityParams['contact_number'] = $contact;
                $exactIdentity = true;
            }
            $identitySql .= ' ORDER BY id ASC LIMIT 2';
            $existingStatement = $pdo->prepare($identitySql);
            $existingStatement->execute($identityParams);
            $existingIds = array_map('intval', $existingStatement->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if (count($existingIds) === 1) {
                if ($exactIdentity) {
                    return $existingIds[0];
                }
                throw new InvalidArgumentException('A registered patient with this name already exists. Select the patient under Existing instead of creating a walk-in record.');
            }
            if (count($existingIds) > 1) {
                throw new InvalidArgumentException('Multiple registered patients have this name. Select the correct patient under Existing instead of creating a walk-in record.');
            }

            $params = [
                'fn' => $first,
                'mn' => $middle !== '' ? $middle : null,
                'ln' => $last,
                'bd' => $birth,
                'dob' => $birth,
                'sex' => $sex,
                'bid' => $barangayId,
                'addr' => $address,
                'cn' => $contact !== '' ? $contact : null,
                'bname' => $barangayName,
            ];

            $errors = [];
            $sqlAttempts = [
                // Full schema aligned with if0_42839743_rhu.residents
                "INSERT INTO residents
            (first_name, middle_name, last_name, birthdate, date_of_birth, sex, barangay_id, barangay, address, contact_number, status, is_active, created_at)
         VALUES
            (:fn, :mn, :ln, :bd, :dob, :sex, :bid, :bname, :addr, :cn, 'Active', 1, NOW())",
                // Without date_of_birth / barangay text / is_active
                "INSERT INTO residents
            (first_name, middle_name, last_name, birthdate, sex, barangay_id, address, contact_number, status, created_at)
         VALUES
            (:fn, :mn, :ln, :bd, :sex, :bid, :addr, :cn, 'Active', NOW())",
                // Minimal required columns
                "INSERT INTO residents
            (first_name, last_name, birthdate, sex, barangay_id, address, created_at)
         VALUES
            (:fn, :ln, :bd, :sex, :bid, :addr, NOW())",
            ];

            foreach ($sqlAttempts as $sql) {
                try {
                    $ins = $pdo->prepare($sql);
                    // Bind only placeholders present in this SQL
                    $bind = [];
                    foreach ($params as $k => $v) {
                        if (strpos($sql, ':' . $k) !== false) {
                            $bind[$k] = $v;
                        }
                    }
                    $ins->execute($bind);
                    $newId = (int) $pdo->lastInsertId();
                    if ($newId <= 0) {
                        $newId = (int) ($pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn() ?: 0);
                    }
                    if ($newId <= 0) {
                        // Some hosts return 0 from lastInsertId — look up the row we just inserted
                        $q = $pdo->prepare("SELECT id FROM residents WHERE first_name = :fn AND last_name = :ln ORDER BY id DESC LIMIT 1");
                        $q->execute(['fn' => $first, 'ln' => $last]);
                        $newId = (int) ($q->fetchColumn() ?: 0);
                    }
                    if ($newId > 0) {
                        rhuIntakeSave($pdo, $newId, $post);
                        return $newId;
                    }
                    // A retry here would create a duplicate resident after a successful insert.
                    throw new RuntimeException('Insert reported success but no new resident id was returned.');
                } catch (RuntimeException $ex) {
                    throw $ex;
                } catch (Throwable $ex) {
                    $errors[] = $ex->getMessage();
                }
            }

            throw new RuntimeException(
                'Failed to register walk-in resident. '
                . implode(' | ', array_slice($errors, 0, 3))
            );
        }
    }

    if (!function_exists('dentalResolveResidentId')) {
        function dentalResolveResidentId(PDO $pdo, array $post): int
        {
            $mode = trim((string) ($post['patient_mode'] ?? 'existing'));
            if ($mode === 'walkin') {
                return dentalCreateWalkInResident($pdo, $post);
            }
            $ridRaw = $post['resident_id'] ?? $post['resident_id_hidden'] ?? '';
            if (is_array($ridRaw)) {
                $ridRaw = reset($ridRaw);
            }
            $rid = (int) preg_replace('/\D+/', '', (string) $ridRaw);
            if ($rid <= 0) {
                throw new InvalidArgumentException(
                    'Please select an existing resident, or switch to Walk-in and enter the patient name.'
                );
            }
            return $rid;
        }
    }
}

$tabs = [
    'overview' => ['Overview', 'dashboard', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'queue' => ['Queue & Appointments', 'workflow', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14h6M9 10h6M9 18h4"/></svg>'],
    'examinations' => ['Mouth Examination', 'clinical', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>'],
    'procedures' => ['Dental Procedures', 'clinical', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
    'extractions' => ['Tooth Extraction', 'clinical', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M12 2v6"/><path d="M8 8h8"/><path d="M9 14c0 2 1.5 4 3 5s3 3 3 5"/><path d="M15 14c0 2-1.5 4-3 5"/></svg>'],
    'prescriptions' => ['E-Prescriptions', 'pharmacy', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>'],
    'referrals' => ['Referrals & Follow-up', 'workflow', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'],
    'patients' => ['Dental Patients', 'services', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
];

$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}
$modal = $_GET['modal'] ?? '';
$dentalSearch = $tab === 'overview' ? '' : trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');

// Session may store health_worker fields from RHULogin
$login = $_SESSION['rhu_staff_login'] ?? [];
$staffName = trim((string) (
    ($login['first_name'] ?? '') . ' ' . ($login['last_name'] ?? '')
));
if ($staffName === '') {
    $staffName = trim((string) ($login['name'] ?? $login['username'] ?? 'Dental Officer'));
}
$staffId = (int) (
    $login['staff_id']
    ?? $login['health_worker_id']
    ?? $login['id']
    ?? 0
);
$staffRoleLabel = strtoupper((string) (
    $login['staff_type']
    ?? $login['position_title']
    ?? $login['role']
    ?? 'DENTAL'
));
$staffInitial = strtoupper(substr(preg_replace('/\s+/', '', $staffName) ?: 'D', 0, 1));

if (empty($_SESSION['dental_exam_csrf'])) $_SESSION['dental_exam_csrf'] = bin2hex(random_bytes(32));
$flashSuccess = $_SESSION['dental_flash_success'] ?? '';
$flashError = $_SESSION['dental_flash_error'] ?? '';
unset($_SESSION['dental_flash_success'], $_SESSION['dental_flash_error']);

$residents = [];
$dentalDuplicateGroups = [];
$exams = [];
$extractions = [];
$queueToday = [];
$procedures = [];
$prescriptions = [];
$referrals = [];
$selectedReferral = null;
$dentalAppointments = [];
$selectedDentalAppointment = null;
$queueDisplayRows = [];
$appointmentGroups = [];
$selectedAppointmentGroup = [];
$selectedResidentDentalRecords = [];
$selectedResidentDentalPatient = null;
$medicineOptions = [];
$kpi = [
    'exams_today' => 0,
    'extractions_month' => 0,
    'patients' => 0,
    'appointments' => 0,
    'queue_waiting' => 0,
    'procedures_month' => 0,
    'referrals_pending' => 0,
];

// Only synchronize an unambiguous dental visit for this resident, date and dentist.
function dentalSyncProcessedAppointment(PDO $pdo, int $residentId, string $date, int $staffId, string $status, int $appointmentId = 0): void
{
    if ($appointmentId > 0) {
        residentQrLoadAppointment($pdo, 'DENTAL', $appointmentId, $residentId);
        $update = $pdo->prepare("UPDATE consultations SET consultation_status = ?, updated_at = NOW() WHERE id = ? AND resident_id = ?");
        $update->execute([$status, $appointmentId, $residentId]);
        return;
    }
    if ($residentId <= 0 || $staffId <= 0) return;
    $date = substr($date, 0, 10);
    $columns = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('consultation_status', $columns, true)) return;
    $providers = [];
    $params = ['resident' => $residentId, 'visit_date' => $date];
    foreach (['health_worker_id', 'physician_id'] as $column) {
        if (in_array($column, $columns, true)) {
            $providers[] = "{$column} = :{$column}";
            $params[$column] = $staffId;
        }
    }
    if (!$providers) return;
    $active = "LOWER(COALESCE(consultation_status, 'Scheduled')) IN ('scheduled','pending','in progress','in_progress')";
    $query = $pdo->prepare("SELECT id FROM consultations WHERE resident_id = :resident AND DATE(consultation_date) = :visit_date AND (" . implode(' OR ', $providers) . ") AND {$active} LIMIT 2");
    $query->execute($params);
    $ids = $query->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        $update = $pdo->prepare("UPDATE consultations SET consultation_status = :status" . (in_array('updated_at', $columns, true) ? ', updated_at = NOW()' : '') . " WHERE id = :id AND {$active}");
        $update->execute(['status' => $status, 'id' => $ids[0]]);
    }
    $queue = $pdo->prepare("SELECT id FROM dental_records WHERE resident_id = :resident AND record_type = 'queue' AND record_date = :visit_date AND status IN ('Waiting','Called','In Chair') LIMIT 2");
    $queue->execute(['resident' => $residentId, 'visit_date' => $date]);
    $queueIds = $queue->fetchAll(PDO::FETCH_COLUMN);
    if (count($queueIds) === 1) {
        $update = $pdo->prepare("UPDATE dental_records SET status = :status WHERE id = :id AND status IN ('Waiting','Called','In Chair')");
        $update->execute(['status' => $status, 'id' => $queueIds[0]]);
    }
}

require_once __DIR__ . '/includes/resident-qr.php';
require_once __DIR__ . '/includes/fhsis-services.php';

if (!empty($pdo)) {
    try {
        ensureDentalTables($pdo);
    } catch (Throwable $ex) {
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_examination') {
                if (!hash_equals($_SESSION['dental_exam_csrf'], (string)($_POST['exam_csrf'] ?? ''))) throw new InvalidArgumentException('Reload the examination form and try again.');
                if (!in_array((string)($_POST['gum_condition'] ?? ''), dentalGumOptions(), true)) throw new InvalidArgumentException('Select a valid gum condition.');
                $approval = dentalValidateExamApproval($_POST, $staffId);
                $careTreatments = dentalValidateCareTreatments($_POST);
                $rid = dentalResolveResidentId($pdo, $_POST);
                $qrDentalAppointmentId = max(0, (int)($_POST['consultation_id'] ?? 0));
                if ($qrDentalAppointmentId) residentQrLoadAppointment($pdo, 'DENTAL', $qrDentalAppointmentId, $rid);
                $date = trim((string) ($_POST['exam_date'] ?? date('Y-m-d')));
                $dateParts = explode('-', $date);
                if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) throw new InvalidArgumentException('Enter a valid visit date.');
                $findings = trim((string) ($_POST['findings'] ?? ''));
                $hygiene = trim((string) ($_POST['oral_hygiene'] ?? 'Fair'));
                $caries = 0;
                $gum = trim((string) ($_POST['gum_condition'] ?? 'Normal'));
                $rec = trim((string) ($_POST['recommendation'] ?? ''));
                if ($findings === '') {
                    throw new InvalidArgumentException('Findings are required (describe oral examination results).');
                }
                ensureDentalTables($pdo);
                $odontogramJson = trim((string) ($_POST['odontogram_json'] ?? ''));
                $odontogram = [];
                if ($odontogramJson !== '') {
                    $decoded = json_decode($odontogramJson, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $tooth => $conditions) {
                            if (!preg_match('/^[1-4][1-8]$/', (string) $tooth)) continue;
                            $conditions = is_array($conditions) ? $conditions : [$conditions];
                            $valid = array_values(array_filter($conditions, static fn($condition) =>
                                in_array($condition, ['caries', 'filling', 'missing', 'to_extract', 'crown'], true)));
                            if ($valid) $odontogram[$tooth] = [$valid[0]];
                        }
                        $odontogramJson = json_encode((object) $odontogram, JSON_UNESCAPED_UNICODE);
                    }
                }
                // Count affected FDI teeth once, regardless of other conditions on the tooth.
                foreach ($odontogram as $tooth => $conditions) {
                    if (!preg_match('/^[1-4][1-8]$/', (string) $tooth)) {
                        continue;
                    }
                    $conditions = is_array($conditions) ? $conditions : [$conditions];
                    if (in_array('caries', $conditions, true)) {
                        $caries++;
                    }
                }
                $examConsistency = dentalRecordViewData(['findings'=>$findings, 'meta'=>['odontogram'=>$odontogram, 'caries_count'=>$caries]]);
                if ($examConsistency['warnings']) throw new InvalidArgumentException('The findings and selected teeth do not agree. Review the dental chart before saving.');
                foreach ($careTreatments as $treatment) {
                    if ($treatment['record_type'] === 'procedure' && str_contains($treatment['title'], 'Filling')) {
                        if ($treatment['tooth_numbers'] === 'ALL') throw new InvalidArgumentException('Specify individual tooth numbers for a filling.');
                        foreach (explode(', ', $treatment['tooth_numbers']) as $tooth) {
                            if (($odontogram[$tooth][0] ?? '') !== 'filling') throw new InvalidArgumentException('Mark filled tooth #' . $tooth . ' as Filled / Pasta on the chart before saving.');
                        }
                    }
                    if ($treatment['record_type'] !== 'extraction') continue;
                    foreach (explode(', ', $treatment['tooth_numbers']) as $tooth) {
                        if (($odontogram[$tooth][0] ?? '') !== 'missing') throw new InvalidArgumentException('Mark extracted tooth #' . $tooth . ' as Extracted / missing on the chart before saving.');
                    }
                }
                $visitId = bin2hex(random_bytes(16));
                $pdo->beginTransaction();
                $fhsisExamId = dentalRecordInsert($pdo, [
                    'record_type' => 'examination',
                    'resident_id' => $rid,
                    'record_date' => $date !== '' ? $date : date('Y-m-d'),
                    'title' => 'Mouth examination',
                    'findings' => $findings,
                    'notes' => $rec,
                    'status' => 'Completed',
                    'performed_by' => $staffId > 0 ? $staffId : null,
                    'meta' => [
                        'oral_hygiene' => $hygiene !== '' ? $hygiene : 'Fair',
                        'caries_count' => $caries,
                        'gum_condition' => $gum !== '' ? $gum : 'Normal',
                        'recommendation' => $rec,
                        'assessment_review' => $approval,
                        'care_visit_id' => $visitId,
                        'odontogram' => $odontogram,
                        'odontogram_cleared' => array_values(array_filter(array_map('strval', (array)json_decode((string)($_POST['odontogram_cleared'] ?? '[]'), true)), static fn($tooth) => preg_match('/^[1-4][1-8]$/', $tooth) && !isset($odontogram[$tooth]))),
                    ],
                ]);
                foreach ($careTreatments as $treatment) {
                    $treatment['resident_id'] = $rid;
                    $treatment['record_date'] = $date !== '' ? $date : date('Y-m-d');
                    $treatment['performed_by'] = $staffId > 0 ? $staffId : null;
                    $treatment['status'] = 'Completed';
                    $treatment['meta']['care_visit_id'] = $visitId;
                    dentalRecordInsert($pdo, $treatment);
                }
                if (function_exists('portalSaveHealthRecordEntry')) {
                    portalSaveHealthRecordEntry($pdo, $rid, [
                        'record_type' => 'Dental Mouth Examination',
                        'chief_complaint' => 'Mouth examination',
                        'diagnosis' => $findings,
                        'treatment' => $rec,
                        'notes' => trim("Oral hygiene: {$hygiene}\nCaries count: {$caries}\nGum condition: {$gum}"),
                        'status' => 'Completed',
                        'last_checkup_date' => $date !== '' ? $date : date('Y-m-d'),
                        'recorded_by_id' => $staffId > 0 ? $staffId : null,
                    ]);
                }
                if (function_exists('portalNotifyResident')) {
                    portalNotifyResident($pdo, $rid, 'Your dental mouth examination was recorded at RHU Dental Clinic.', 'ResidentDashboard.php?tab=records');
                }
                fhsisSaveInlineServices($pdo, 'dental_records', $fhsisExamId, $rid, $_POST, $_SESSION);
                dentalSyncProcessedAppointment($pdo, $rid, $date !== '' ? $date : date('Y-m-d'), $staffId, 'Completed', $qrDentalAppointmentId);
                $pdo->commit();
                $_SESSION['dental_flash_success'] = 'Dental care visit saved: examination' . ($careTreatments ? ' and ' . count($careTreatments) . ' treatment record(s).' : '.');
                header('Location: ' . tabUrl('examinations'));
                exit;
            }
            if ($action === 'save_extraction') {
                $rid = dentalResolveResidentId($pdo, $_POST);
                $date = trim((string) ($_POST['extraction_date'] ?? date('Y-m-d')));
                $tooth = trim((string) ($_POST['tooth_number'] ?? ''));
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $anes = trim((string) ($_POST['anesthesia'] ?? 'Local'));
                $comp = trim((string) ($_POST['complications'] ?? 'None'));
                $notes = trim((string) ($_POST['notes'] ?? ''));
                if ($tooth === '') {
                    throw new InvalidArgumentException('Tooth number is required (e.g. 36 or FDI notation).');
                }
                ensureDentalTables($pdo);
                dentalRecordInsert($pdo, [
                    'record_type' => 'extraction',
                    'resident_id' => $rid,
                    'record_date' => $date !== '' ? $date : date('Y-m-d'),
                    'title' => 'Tooth extraction',
                    'tooth_numbers' => $tooth,
                    'findings' => $reason,
                    'notes' => $notes,
                    'status' => 'Completed',
                    'performed_by' => $staffId > 0 ? $staffId : null,
                    'meta' => [
                        'anesthesia' => $anes !== '' ? $anes : 'Local',
                        'complications' => $comp,
                    ],
                ]);
                if (function_exists('portalNotifyResident')) {
                    portalNotifyResident($pdo, $rid, "Tooth extraction (Tooth #{$tooth}) was recorded at RHU Dental Clinic.", 'ResidentDashboard.php?tab=records');
                }
                dentalSyncProcessedAppointment($pdo, $rid, $date !== '' ? $date : date('Y-m-d'), $staffId, 'Completed');
                $_SESSION['dental_flash_success'] = 'Tooth extraction record saved.';
                header('Location: ' . tabUrl('extractions'));
                exit;
            }
            if ($action === 'merge_dental_residents') {
                $primaryId = (int) ($_POST['primary_resident_id'] ?? 0);
                $duplicateId = (int) ($_POST['duplicate_resident_id'] ?? 0);
                if ($primaryId <= 0 || $duplicateId <= 0 || $primaryId === $duplicateId) {
                    throw new InvalidArgumentException('Select two different residents to merge.');
                }

                $check = $pdo->prepare('SELECT id FROM residents WHERE id IN (:primary, :duplicate)');
                $check->execute(['primary' => $primaryId, 'duplicate' => $duplicateId]);
                if (count($check->fetchAll(PDO::FETCH_COLUMN) ?: []) !== 2) {
                    throw new InvalidArgumentException('One or both selected residents could not be found.');
                }

                $residentColumns = $pdo->query('SHOW COLUMNS FROM residents')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $canArchive = in_array('status', $residentColumns, true) || in_array('is_active', $residentColumns, true);
                if (!$canArchive) {
                    throw new RuntimeException('Cannot safely merge residents because this database has no resident status field.');
                }

                $pdo->beginTransaction();
                try {
                    foreach ([
                        'dental_examinations',
                        'dental_extractions',
                        'dental_tooth_findings',
                        'dental_procedures',
                        'dental_prescriptions',
                        'dental_referrals',
                        'dental_queue',
                    ] as $dentalTable) {
                        $exists = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1');
                        $exists->execute(['table_name' => $dentalTable]);
                        if (!$exists->fetchColumn()) {
                            continue;
                        }
                        $move = $pdo->prepare('UPDATE `' . $dentalTable . '` SET resident_id = :primary WHERE resident_id = :duplicate');
                        $move->execute(['primary' => $primaryId, 'duplicate' => $duplicateId]);
                    }
                    $archiveParts = [];
                    if (in_array('status', $residentColumns, true)) {
                        $archiveParts[] = "status = 'Merged'";
                    }
                    if (in_array('is_active', $residentColumns, true)) {
                        $archiveParts[] = 'is_active = 0';
                    }
                    if (in_array('updated_at', $residentColumns, true)) {
                        $archiveParts[] = 'updated_at = NOW()';
                    }
                    $archive = $pdo->prepare('UPDATE residents SET ' . implode(', ', $archiveParts) . ' WHERE id = :duplicate');
                    $archive->execute(['duplicate' => $duplicateId]);
                    $pdo->commit();
                } catch (Throwable $mergeError) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $mergeError;
                }

                $_SESSION['dental_flash_success'] = "Dental records were moved to resident #{$primaryId}. Resident #{$duplicateId} is now marked as merged.";
                header('Location: ' . tabUrl('patients'));
                exit;
            }

            if ($action === 'add_queue') {
                $requestedAppointmentTime = trim((string)($_POST['appointment_time'] ?? ''));
                if ($requestedAppointmentTime !== '') rhuValidateAppointmentTime($requestedAppointmentTime);
                $rid = 0;
                $walkinName = '';
                $mode = trim((string) ($_POST['patient_mode'] ?? 'existing'));
                if ($mode === 'walkin') {
                    $fn = trim((string) ($_POST['walkin_first_name'] ?? ''));
                    $ln = trim((string) ($_POST['walkin_last_name'] ?? ''));
                    $walkinName = trim($fn . ' ' . $ln);
                    if ($fn !== '' && $ln !== '') {
                        try {
                            $rid = dentalResolveResidentId($pdo, $_POST);
                            $walkinName = '';
                        } catch (Throwable $ex) {
                            if ($walkinName === '') {
                                throw $ex;
                            }
                        }
                    } elseif ($walkinName === '') {
                        throw new InvalidArgumentException('Enter walk-in patient name or select a registered resident.');
                    }
                } else {
                    $rid = dentalResolveResidentId($pdo, $_POST);
                }
                $qdate = trim((string) ($_POST['queue_date'] ?? date('Y-m-d')));
                $priority = trim((string) ($_POST['priority'] ?? 'Routine'));
                if (!in_array($priority, ['Emergency', 'Urgent', 'Routine'], true)) {
                    $priority = 'Routine';
                }
                $complaint = trim((string) ($_POST['chief_complaint'] ?? ''));
                $appt = trim((string) ($_POST['appointment_time'] ?? ''));
                $notes = trim((string) ($_POST['queue_notes'] ?? ''));
                ensureDentalTables($pdo);
                $maxQ = (int) $pdo->query(
                    "SELECT COALESCE(MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.queue_number')) AS UNSIGNED)), 0)
                     FROM dental_records
                     WHERE record_type = 'queue' AND record_date = " . $pdo->quote($qdate !== '' ? $qdate : date('Y-m-d'))
                )->fetchColumn();
                // Fallback if JSON functions unavailable
                if ($maxQ <= 0) {
                    $maxQ = (int) $pdo->query(
                        "SELECT COUNT(*) FROM dental_records WHERE record_type = 'queue' AND record_date = "
                        . $pdo->quote($qdate !== '' ? $qdate : date('Y-m-d'))
                    )->fetchColumn();
                }
                $qnum = $maxQ + 1;
                dentalRecordInsert($pdo, [
                    'record_type' => 'queue',
                    'resident_id' => $rid > 0 ? $rid : null,
                    'walkin_name' => $walkinName !== '' ? $walkinName : null,
                    'record_date' => $qdate !== '' ? $qdate : date('Y-m-d'),
                    'title' => 'Queue #' . $qnum,
                    'findings' => $complaint !== '' ? $complaint : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'status' => 'Waiting',
                    'priority' => $priority,
                    'performed_by' => $staffId > 0 ? $staffId : null,
                    'meta' => [
                        'queue_number' => $qnum,
                        'appointment_time' => $appt !== '' ? $appt : null,
                        'chief_complaint' => $complaint,
                    ],
                ]);
                rhuVisitSave($pdo, $rid, $_POST, 'Dental', (int)$staffId);
                $_SESSION['dental_flash_success'] = "Added to queue as #{$qnum} ({$priority}).";
                header('Location: ' . tabUrl('queue'));
                exit;
            }
            if ($action === 'update_queue_status') {
                $qid = (int) ($_POST['queue_id'] ?? 0);
                $status = trim((string) ($_POST['status'] ?? ''));
                if ($qid <= 0 || !in_array($status, ['Waiting', 'Called', 'In Chair', 'Completed', 'No-show', 'Cancelled'], true)) {
                    throw new InvalidArgumentException('Invalid queue update.');
                }
                ensureDentalTables($pdo);
                $st = $pdo->prepare(
                    "UPDATE dental_records SET status = :s, updated_at = NOW()
                     WHERE id = :id AND record_type = 'queue'"
                );
                $st->execute(['s' => $status, 'id' => $qid]);
                $_SESSION['dental_flash_success'] = "Queue status updated to {$status}.";
                header('Location: ' . tabUrl('queue'));
                exit;
            }
            if ($action === 'update_resident_appointment') {
                $consultationId = (int) ($_POST['consultation_id'] ?? 0);
                $residentId = (int) ($_POST['resident_id'] ?? 0);
                $postedStatus = $_POST['consultation_status'] ?? 'In Progress';
                $status = is_string($postedStatus) ? trim($postedStatus) : 'In Progress';
                $diagnosis = trim((string) ($_POST['diagnosis'] ?? ''));
                $notes = trim((string) ($_POST['consultation_notes'] ?? ''));
                $statusKey = strtolower(str_replace(['_', '-'], ' ', $status));
                $statusMap = [
                    'scheduled' => 'Scheduled',
                    'pending' => 'Scheduled',
                    'in progress' => 'In Progress',
                    'completed' => 'Completed',
                    'no show' => 'No-show',
                    'cancelled' => 'Cancelled',
                    'canceled' => 'Cancelled',
                    'referred' => 'Referred',
                ];
                $status = $statusMap[$statusKey] ?? '';
                if ($consultationId <= 0 || $residentId <= 0) {
                    throw new InvalidArgumentException('The resident appointment record could not be found.');
                }
                if ($status === '') {
                    throw new InvalidArgumentException('Invalid resident appointment update.');
                }
                $consultationColumns = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $updateColumns = [];
                $params = [];
                if (in_array('consultation_status', $consultationColumns, true)) {
                    $updateColumns[] = 'consultation_status = :status';
                    $params['status'] = $status;
                }
                if (in_array('diagnosis', $consultationColumns, true)) {
                    $updateColumns[] = 'diagnosis = :diagnosis';
                    $params['diagnosis'] = $diagnosis;
                }
                if (in_array('remarks', $consultationColumns, true)) {
                    $updateColumns[] = 'remarks = :remarks';
                    $params['remarks'] = $notes;
                }
                if (in_array('consultation_notes', $consultationColumns, true)) {
                    $updateColumns[] = 'consultation_notes = :consultation_notes';
                    $params['consultation_notes'] = $notes;
                }
                if (in_array('updated_at', $consultationColumns, true)) {
                    $updateColumns[] = 'updated_at = NOW()';
                }
                if (!$updateColumns) {
                    throw new RuntimeException('The consultations table has no editable fields.');
                }
                $appointmentRecord = $pdo->prepare('SELECT resident_id FROM consultations WHERE id = :id AND resident_id = :resident_id LIMIT 1');
                $appointmentRecord->execute(['id' => $consultationId, 'resident_id' => $residentId]);
                if ($appointmentRecord->fetchColumn() === false) {
                    throw new InvalidArgumentException('The resident appointment record could not be found.');
                }
                $st = $pdo->prepare('UPDATE consultations SET ' . implode(', ', $updateColumns) . ' WHERE id = :id AND resident_id = :resident_id');
                $st->execute(array_merge($params, ['id' => $consultationId, 'resident_id' => $residentId]));
                if ($residentId > 0 && function_exists('portalNotifyResident')) {
                    rhuNotifySavedResidentAppointment($pdo, $consultationId, 'Dental appointment');
                    portalNotifyResident($pdo, $residentId, "Your dental appointment was updated by the Dental Clinic. Status: {$status}.", 'ResidentDashboard.php?tab=records');
                }
                $_SESSION['dental_flash_success'] = "Resident appointment updated to {$status}.";
                header('Location: ' . tabUrl('queue'));
                exit;
            }
            if ($action === 'save_procedure') {
                $rid = dentalResolveResidentId($pdo, $_POST);
                $ptype = trim((string) ($_POST['procedure_type'] ?? ''));
                $allowedTypes = ['Oral Prophylaxis', 'Dental Filling', 'Temporary Filling', 'Permanent Filling', 'Topical Fluoride', 'Other'];
                if (!in_array($ptype, $allowedTypes, true)) {
                    throw new InvalidArgumentException('Select a valid procedure type.');
                }
                $pdate = trim((string) ($_POST['procedure_date'] ?? date('Y-m-d')));
                $teeth = trim((string) ($_POST['tooth_numbers'] ?? ''));
                $materials = trim((string) ($_POST['materials'] ?? ''));
                $notes = trim((string) ($_POST['procedure_notes'] ?? ''));
                ensureDentalTables($pdo);
                dentalRecordInsert($pdo, [
                    'record_type' => 'procedure',
                    'resident_id' => $rid,
                    'record_date' => $pdate !== '' ? $pdate : date('Y-m-d'),
                    'title' => $ptype,
                    'tooth_numbers' => $teeth !== '' ? $teeth : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'status' => 'Completed',
                    'performed_by' => $staffId > 0 ? $staffId : null,
                    'meta' => ['materials' => $materials, 'procedure_type' => $ptype],
                ]);
                dentalSyncProcessedAppointment($pdo, $rid, $pdate !== '' ? $pdate : date('Y-m-d'), $staffId, 'Completed');
                $_SESSION['dental_flash_success'] = "{$ptype} recorded successfully.";
                header('Location: ' . tabUrl('procedures'));
                exit;
            }
            if ($action === 'save_prescription') {
                if (!portalVerifyCsrf()) throw new InvalidArgumentException('Form expired. Refresh and try again.');
                dentalValidatePrescription($_POST);
                $rid = dentalResolveResidentId($pdo, $_POST);
                $med = trim((string) ($_POST['medicine_name'] ?? ''));
                if ($med === '') {
                    throw new InvalidArgumentException('Medicine name is required.');
                }
                $pdate = trim((string) ($_POST['prescription_date'] ?? date('Y-m-d')));
                $dosage = trim((string) ($_POST['dosage'] ?? ''));
                $freq = trim((string) ($_POST['frequency'] ?? ''));
                $duration = trim((string) ($_POST['duration'] ?? ''));
                $qty = trim((string) ($_POST['quantity'] ?? ''));
                $instr = trim((string) ($_POST['instructions'] ?? ''));
                $invId = (int) ($_POST['inventory_id'] ?? 0);
                ensureDentalTables($pdo);
                $prescriptionSummary = trim(implode(' ', array_filter([$med, $dosage, $freq, $duration])));
                $dispensed = [];
                inventoryEnsureHistory($pdo);

                $pdo->beginTransaction();
                try {
                    if ($invId > 0) {
                        $dispensed = dentalDeductPrescriptionInventory(
                            $pdo,
                            $invId,
                            dentalPrescriptionQuantityNumber($qty),
                            $med,
                            $staffId,
                            $rid
                        );
                    }

                    dentalRecordInsert($pdo, [
                        'record_type' => 'prescription',
                        'resident_id' => $rid,
                        'record_date' => $pdate !== '' ? $pdate : date('Y-m-d'),
                        'title' => $med,
                        'notes' => $instr !== '' ? $instr : null,
                        'status' => 'Issued',
                        'performed_by' => $staffId > 0 ? $staffId : null,
                        'meta' => [
                            'medicine_name' => $med,
                            'dosage' => $dosage,
                            'frequency' => $freq,
                            'duration' => $duration,
                            'quantity' => $qty,
                            'inventory_id' => $invId > 0 ? $invId : null,
                            'inventory_deducted_quantity' => $dispensed['deducted_quantity'] ?? null,
                            'inventory_unit' => $dispensed['unit'] ?? null,
                            'inventory_item_name' => $dispensed['inventory_item_name'] ?? null,
                            'dispense_location' => $invId > 0 ? trim((string)($_POST['dispense_location'] ?? '')) : null,
                            'dispensed_at' => $invId > 0 ? (string)($_POST['dispensed_at'] ?? '') : null,
                        ],
                    ]);

                    if (function_exists('portalSaveHealthRecordEntry')) {
                        portalSaveHealthRecordEntry($pdo, $rid, [
                            'record_type' => 'Dental E-Prescription',
                            'chief_complaint' => 'Dental prescription issued',
                            'treatment' => $prescriptionSummary !== '' ? $prescriptionSummary : $med,
                            'current_medications' => $prescriptionSummary !== '' ? $prescriptionSummary : $med,
                            'notes' => trim(implode("\n", array_filter([
                                $qty !== '' ? "Quantity: {$qty}" : '',
                                $instr !== '' ? "Instructions: {$instr}" : '',
                                $dispensed ? 'Inventory deducted: ' . $dispensed['deducted_quantity'] . ' ' . ($dispensed['unit'] ?: 'unit(s)') : '',
                            ]))),
                            'status' => 'Issued',
                            'last_checkup_date' => $pdate !== '' ? $pdate : date('Y-m-d'),
                            'recorded_by_id' => $staffId > 0 ? $staffId : null,
                        ]);
                    }
                    if (function_exists('portalNotifyResident')) {
                        portalNotifyResident($pdo, $rid, "Your dental e-prescription for {$med} was issued at RHU Dental Clinic.", 'ResidentDashboard.php?tab=records');
                    }
                    $pdo->commit();
                } catch (Throwable $rxError) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $rxError;
                }
                dentalSyncProcessedAppointment($pdo, $rid, $pdate !== '' ? $pdate : date('Y-m-d'), $staffId, 'Completed');
                $_SESSION['dental_flash_success'] = "Prescription for {$med} issued." . ($dispensed ? ' Inventory deducted.' : '');
                header('Location: ' . tabUrl('prescriptions'));
                exit;
            }
            if ($action === 'save_referral') {
                $rid = dentalResolveResidentId($pdo, $_POST);
                $rdate = trim((string) ($_POST['referral_date'] ?? date('Y-m-d')));
                $rtype = trim((string) ($_POST['referral_type'] ?? 'External'));
                if (!in_array($rtype, ['Internal', 'External'], true)) {
                    $rtype = 'External';
                }
                $facility = trim((string) ($_POST['facility_name'] ?? ''));
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($facility === '' || $reason === '') {
                    throw new InvalidArgumentException('Facility and reason are required for referral.');
                }
                $urgency = trim((string) ($_POST['urgency'] ?? 'Routine'));
                if (!in_array($urgency, ['Emergency', 'Urgent', 'Routine'], true)) {
                    $urgency = 'Routine';
                }
                $fup = trim((string) ($_POST['follow_up_date'] ?? ''));
                $notes = trim((string) ($_POST['referral_notes'] ?? ''));
                ensureDentalTables($pdo);
                dentalRecordInsert($pdo, [
                    'record_type' => 'referral',
                    'resident_id' => $rid,
                    'record_date' => $rdate !== '' ? $rdate : date('Y-m-d'),
                    'title' => $facility,
                    'findings' => $reason,
                    'notes' => $notes !== '' ? $notes : null,
                    'status' => 'Pending',
                    'priority' => $urgency,
                    'performed_by' => $staffId > 0 ? $staffId : null,
                    'meta' => [
                        'referral_type' => $rtype,
                        'facility_name' => $facility,
                        'follow_up_date' => $fup !== '' ? $fup : null,
                    ],
                ]);
                if (function_exists('portalNotifyResident')) {
                    rhuNotifyResidentSchedule($pdo, $rid, 'Dental follow-up', $fup);
                    portalNotifyResident($pdo, $rid, "You have a dental referral to {$facility}." . ($fup !== '' ? " Follow-up check-up: {$fup}." : ''), 'ResidentDashboard.php?tab=records');
                }
                dentalSyncProcessedAppointment($pdo, $rid, $rdate !== '' ? $rdate : date('Y-m-d'), $staffId, 'Referred');
                $_SESSION['dental_flash_success'] = "Referral to {$facility} recorded.";
                header('Location: ' . tabUrl('referrals'));
                exit;
            }
            if ($action === 'edit_referral') {
                $refId = (int)($_POST['referral_id'] ?? 0);
                $referralDate = trim((string)($_POST['referral_date'] ?? ''));
                $followUpDate = trim((string)($_POST['follow_up_date'] ?? ''));
                $referralType = trim((string)($_POST['referral_type'] ?? ''));
                $urgency = trim((string)($_POST['urgency'] ?? ''));
                $status = trim((string)($_POST['status'] ?? ''));
                $facility = trim((string)($_POST['facility_name'] ?? ''));
                $reason = trim((string)($_POST['reason'] ?? ''));
                $notes = trim((string)($_POST['referral_notes'] ?? ''));
                $validDate = static function (string $value): bool {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    return $date !== false && $date->format('Y-m-d') === $value;
                };
                if ($refId <= 0 || !$validDate($referralDate)
                    || ($followUpDate !== '' && !$validDate($followUpDate))
                    || !in_array($referralType, ['Internal', 'External'], true)
                    || !in_array($urgency, ['Emergency', 'Urgent', 'Routine'], true)
                    || !in_array($status, ['Pending', 'Completed', 'Cancelled'], true)
                    || $facility === '' || $reason === '') {
                    throw new InvalidArgumentException('Please complete the referral details with valid dates.');
                }
                $referralStmt = $pdo->prepare("SELECT meta_json, resident_id FROM dental_records WHERE id = :id AND record_type = 'referral' LIMIT 1");
                $referralStmt->execute(['id' => $refId]);
                $referralOwner = $referralStmt->fetch(PDO::FETCH_ASSOC);
                $existingMetaJson = $referralOwner ? $referralOwner['meta_json'] : false;
                if ($existingMetaJson === false) {
                    throw new InvalidArgumentException('Referral not found.');
                }
                $meta = json_decode((string)$existingMetaJson, true);
                if (!is_array($meta)) $meta = [];
                $previousFollowUp = (string)($meta['follow_up_date'] ?? '');
                $meta['referral_type'] = $referralType;
                $meta['facility_name'] = $facility;
                $meta['follow_up_date'] = $followUpDate !== '' ? $followUpDate : null;
                $updateReferral = $pdo->prepare("UPDATE dental_records SET record_date = :record_date,
                    title = :facility, findings = :reason, notes = :notes, priority = :urgency,
                    status = :status, meta_json = :meta, updated_at = NOW()
                    WHERE id = :id AND record_type = 'referral'");
                $updateReferral->execute([
                    'record_date' => $referralDate,
                    'facility' => $facility,
                    'reason' => $reason,
                    'notes' => $notes !== '' ? $notes : null,
                    'urgency' => $urgency,
                    'status' => $status,
                    'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                    'id' => $refId,
                ]);
                if ($status !== 'Cancelled') rhuNotifyResidentSchedule($pdo, (int)$referralOwner['resident_id'], 'Dental follow-up', $followUpDate, '', $previousFollowUp);
                $_SESSION['dental_flash_success'] = 'Referral updated.';
                header('Location: ' . tabUrl('referrals'));
                exit;
            }
            if ($action === 'update_referral_status') {
                $refId = (int) ($_POST['referral_id'] ?? 0);
                $status = trim((string) ($_POST['status'] ?? ''));
                if ($refId <= 0 || !in_array($status, ['Pending', 'Completed', 'Cancelled'], true)) {
                    throw new InvalidArgumentException('Invalid referral status.');
                }
                ensureDentalTables($pdo);
                $st = $pdo->prepare(
                    "UPDATE dental_records SET status = :s, updated_at = NOW()
                     WHERE id = :id AND record_type = 'referral'"
                );
                $st->execute(['s' => $status, 'id' => $refId]);
                $_SESSION['dental_flash_success'] = "Referral marked as {$status}.";
                header('Location: ' . tabUrl('referrals'));
                exit;
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['dental_flash_error'] = $ex->getMessage();
            header('Location: ' . tabUrl($tab));
            exit;
        }
    }

    try {
        $residentFilter = '';
        $residentColumns = $pdo->query('SHOW COLUMNS FROM residents')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (function_exists('rhuLivingResidentWhereSql')) {
            $residentFilter = ' WHERE ' . rhuLivingResidentWhereSql('r') . " AND LOWER(COALESCE(r.status, 'Active')) <> 'merged'";
        } elseif (in_array('status', $residentColumns, true) && in_array('is_active', $residentColumns, true)) {
            $residentFilter = " WHERE COALESCE(r.is_active, 1) = 1 AND LOWER(COALESCE(r.status, 'Active')) NOT IN ('deceased', 'dead', 'inactive', 'merged')";
        } elseif (in_array('status', $residentColumns, true)) {
            $residentFilter = " WHERE LOWER(COALESCE(r.status, 'Active')) NOT IN ('deceased', 'dead', 'inactive', 'merged')";
        } elseif (in_array('is_active', $residentColumns, true)) {
            $residentFilter = ' WHERE COALESCE(r.is_active, 1) = 1';
        }
        $stmtRes = $pdo->query("
            SELECT id,
                   TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) AS name
            FROM residents r
            {$residentFilter}
            ORDER BY first_name, last_name
        ");
        $residents = $stmtRes ? ($stmtRes->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $ex) {
        $residents = [];
    }
    // Normalize to plain arrays with valid ids (handles odd PDO fetch modes / column casing)
    $normalized = [];
    foreach ($residents as $__r) {
        if (is_object($__r)) {
            $__r = (array) $__r;
        }
        if (!is_array($__r)) {
            continue;
        }
        // column casing variants
        $id = (int) ($__r['id'] ?? $__r['ID'] ?? $__r['Id'] ?? 0);
        $name = trim((string) ($__r['name'] ?? $__r['NAME'] ?? ''));
        if ($id <= 0) {
            continue;
        }
        if ($name === '') {
            $name = 'Resident #' . $id;
        }
        $normalized[] = ['id' => $id, 'name' => $name];
    }
    $residents = $normalized;

    // Potential duplicates are intentionally name-based for review. Staff choose
    // the primary record; the merge action does not guess which person is correct.
    $dentalDuplicateGroups = [];
    foreach ($residents as $resident) {
        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $resident['name'] ?? '')));
        if ($key !== '') {
            $dentalDuplicateGroups[$key][] = $resident;
        }
    }
    $dentalDuplicateGroups = array_values(array_filter($dentalDuplicateGroups, static function (array $group): bool {
        return count($group) > 1;
    }));

    $limit = defined('DENTAL_LIST_LIMIT') ? DENTAL_LIST_LIMIT : 50;
    $exams = dentalRecordsByType($pdo, 'examination', $limit);
    $extractions = dentalRecordsByType($pdo, 'extraction', $limit);
    $procedures = dentalRecordsByType($pdo, 'procedure', $limit);
    $prescriptions = dentalRecordsByType($pdo, 'prescription', $limit);
    $referrals = dentalRecordsByType($pdo, 'referral', null);
    $queueToday = dentalRecordsByType(
        $pdo,
        'queue',
        $limit,
        "dr.record_date = CURDATE() AND dr.status IN ('Waiting','Called','In Chair')",
        "FIELD(dr.priority,'Emergency','Urgent','Routine'), dr.id ASC"
    );

    $selectedResidentRecordsId = (int) ($_GET['resident_records'] ?? 0);
    if ($selectedResidentRecordsId > 0) {
        foreach ($residents as $residentOption) {
            if ((int) ($residentOption['id'] ?? 0) === $selectedResidentRecordsId) {
                $selectedResidentDentalPatient = $residentOption;
                break;
            }
        }
        try {
            if ($selectedResidentDentalPatient && in_array('address', $residentColumns, true)) {
                $addressStatement = $pdo->prepare('SELECT address FROM residents WHERE id = :id');
                $addressStatement->execute(['id' => $selectedResidentRecordsId]);
                $selectedResidentDentalPatient['address'] = (string) $addressStatement->fetchColumn();
            }
            $recordStatement = $pdo->prepare(
                "SELECT dr.*, CONCAT_WS(' ', hw.first_name, hw.last_name) AS provider_name
                 FROM dental_records dr
                 LEFT JOIN health_workers hw ON hw.id = dr.performed_by
                 WHERE dr.resident_id = :resident_id
                 ORDER BY dr.record_date DESC, dr.id DESC"
            );
            $recordStatement->execute(['resident_id' => $selectedResidentRecordsId]);
            $selectedResidentDentalRecords = $recordStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($selectedResidentDentalRecords as &$selectedResidentDentalRecord) {
                $meta = [];
                if (!empty($selectedResidentDentalRecord['meta_json'])) {
                    $decoded = json_decode((string) $selectedResidentDentalRecord['meta_json'], true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                $selectedResidentDentalRecord['meta'] = $meta;
            }
            unset($selectedResidentDentalRecord);
        } catch (Throwable $ex) {
            $selectedResidentDentalRecords = [];
        }
    }

    // Resident portal appointments are stored in consultations, separately
    // from staff-created dental queue records.
    try {
        $consultationColumns = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $consultationStatus = in_array('consultation_status', $consultationColumns, true)
            ? "COALESCE(c.consultation_status, 'Scheduled')"
            : "'Scheduled'";
        $consultationTime = in_array('consultation_time', $consultationColumns, true)
            ? 'c.consultation_time'
            : 'NULL';
        $providerMatches = [];
        if (in_array('health_worker_id', $consultationColumns, true)) {
            $providerMatches[] = 'c.health_worker_id = hw.id';
        }
        if (in_array('physician_id', $consultationColumns, true)) {
            $providerMatches[] = 'c.physician_id = hw.id';
        }
        $providerJoin = $providerMatches
            ? 'LEFT JOIN health_workers hw ON (' . implode(' OR ', $providerMatches) . ')'
            : '';
        $providerDentalMatch = $providerMatches
            ? " OR LOWER(CONCAT(COALESCE(hw.position_title, ''), ' ', COALESCE(hw.role, ''))) REGEXP 'dental|dentist'"
            : '';
        $livingResidentWhere = function_exists('rhuLivingResidentWhereSql')
            ? rhuLivingResidentWhereSql('r')
            : "COALESCE(r.is_active, 1) = 1 AND LOWER(COALESCE(r.status, 'Active')) NOT IN ('deceased', 'dead', 'inactive')";
        $appointmentSearchWhere = $dentalSearch !== ''
            ? " AND LOCATE(LOWER(:search), LOWER(CONCAT_WS(' ', r.first_name, r.last_name, c.resident_id,
                c.consultation_date, {$consultationTime}, c.chief_complaint, c.diagnosis, c.remarks, {$consultationStatus}))) > 0"
            : '';
        $appointmentStatement = $pdo->prepare(
            "SELECT c.id, c.resident_id, c.consultation_date, {$consultationTime} AS consultation_time,
                    c.chief_complaint, c.diagnosis, c.remarks, {$consultationStatus} AS consultation_status,
                    TRIM(CONCAT(COALESCE(r.first_name, ''), ' ', COALESCE(r.last_name, ''))) AS resident_name
             FROM consultations c
             LEFT JOIN residents r ON r.id = c.resident_id
             {$providerJoin}
             WHERE {$livingResidentWhere}
               AND (LOWER(COALESCE(c.chief_complaint, '')) REGEXP 'dental|dentist|tooth|oral'{$providerDentalMatch})
               AND LOWER({$consultationStatus}) NOT IN ('completed', 'cancelled', 'canceled', 'no-show', 'rejected')
               {$appointmentSearchWhere}
             ORDER BY (c.id = :qr_priority) DESC,
               (c.consultation_date < CURDATE()) ASC,
               CASE WHEN c.consultation_date >= CURDATE() THEN c.consultation_date END ASC,
               CASE WHEN c.consultation_date < CURDATE() THEN c.consultation_date END DESC,
               {$consultationTime} ASC, c.id ASC"
        );
        $qrAppointmentId = max(0, (int)($_GET['qr_appointment'] ?? 0));
        $appointmentStatement->execute(array_merge($dentalSearch !== '' ? ['search' => $dentalSearch] : [], ['qr_priority'=>$qrAppointmentId]));
        foreach ($appointmentStatement->fetchAll(PDO::FETCH_ASSOC) as $appointment) {
            if (rhuAppointmentExpired($appointment)) continue;
            $dentalAppointments[] = [
                'id' => (int) ($appointment['id'] ?? 0),
                'resident_id' => (int) ($appointment['resident_id'] ?? 0),
                'resident_name' => $appointment['resident_name'] ?? '',
                'record_date' => $appointment['consultation_date'] ?? '',
                'appointment_date' => $appointment['consultation_date'] ?? '',
                'appointment_time' => $appointment['consultation_time'] ?? null,
                'chief_complaint' => $appointment['chief_complaint'] ?? '',
                'diagnosis' => $appointment['diagnosis'] ?? '',
                'remarks' => $appointment['remarks'] ?? '',
                'status' => $appointment['consultation_status'] ?? 'Scheduled',
                'priority' => 'Routine',
                'is_resident_appointment' => true,
            ];
            if ($modal === 'edit_appointment' && (int)$appointment['id'] === (int)($_GET['appointment_id'] ?? 0)) {
                $selectedDentalAppointment = end($dentalAppointments);
            }
        }
    } catch (Throwable $ex) {
        $dentalAppointments = [];
    }
    $queueToday = array_merge($queueToday, $dentalAppointments);
    $kpi['appointments'] = count($dentalAppointments);

    // Map queue meta for UI
    foreach ($queueToday as &$q) {
        if (!($q['is_resident_appointment'] ?? false)) {
            $q['queue_number'] = (int) ($q['meta']['queue_number'] ?? $q['id']);
            $q['chief_complaint'] = $q['findings'] ?? ($q['meta']['chief_complaint'] ?? '');
            $q['appointment_time'] = $q['meta']['appointment_time'] ?? null;
        }
    }
    unset($q);
    $queueDisplayRows = array_values(array_filter($queueToday, static fn(array $row): bool => empty($row['is_resident_appointment'])));
    foreach ($dentalAppointments as $appointment) {
        $groupKey = (int)$appointment['resident_id'] > 0 ? (int)$appointment['resident_id'] : -(int)$appointment['id'];
        $appointmentGroups[$groupKey][] = $appointment;
    }
    foreach ($appointmentGroups as $groupKey => $appointmentsForPatient) {
        $displayAppointment = $appointmentsForPatient[0];
        $displayAppointment['appointment_count'] = count($appointmentsForPatient);
        $displayAppointment['appointment_group_key'] = $groupKey;
        $queueDisplayRows[] = $displayAppointment;
    }
    if ($modal === 'patient_appointments') {
        $selectedAppointmentGroup = $appointmentGroups[(int)($_GET['resident_id'] ?? 0)] ?? [];
    }
    foreach ($extractions as &$x) {
        $x['tooth_number'] = $x['tooth_numbers'] ?? '';
        $x['reason'] = $x['findings'] ?? '';
        $x['anesthesia'] = $x['meta']['anesthesia'] ?? '';
        $x['extraction_date'] = $x['record_date'] ?? '';
    }
    unset($x);
    foreach ($exams as &$e) {
        $e['exam_date'] = $e['record_date'] ?? '';
        $e['oral_hygiene'] = $e['meta']['oral_hygiene'] ?? '';
        $e['caries_count'] = dentalRecordViewData($e)['caries_count'] ?? 0;
        $e['gum_condition'] = $e['meta']['gum_condition'] ?? '';
        $e['recommendation'] = $e['meta']['recommendation'] ?? ($e['notes'] ?? '');
    }
    unset($e);
    foreach ($procedures as &$pr) {
        $pr['procedure_date'] = $pr['record_date'] ?? '';
        $pr['procedure_type'] = $pr['title'] ?? ($pr['meta']['procedure_type'] ?? '');
        $pr['materials'] = $pr['meta']['materials'] ?? '';
    }
    unset($pr);
    foreach ($prescriptions as &$rxrow) {
        $rxrow['prescription_date'] = $rxrow['record_date'] ?? '';
        $rxrow['medicine_name'] = $rxrow['title'] ?? ($rxrow['meta']['medicine_name'] ?? '');
        $rxrow['dosage'] = $rxrow['meta']['dosage'] ?? '';
        $rxrow['frequency'] = $rxrow['meta']['frequency'] ?? '';
        $rxrow['duration'] = $rxrow['meta']['duration'] ?? '';
        $rxrow['quantity'] = $rxrow['meta']['quantity'] ?? '';
        $rxrow['instructions'] = $rxrow['notes'] ?? '';
    }
    unset($rxrow);
    foreach ($referrals as &$rf) {
        $rf['referral_date'] = $rf['record_date'] ?? '';
        $rf['facility_name'] = $rf['title'] ?? ($rf['meta']['facility_name'] ?? '');
        $rf['referral_type'] = $rf['meta']['referral_type'] ?? '';
        $rf['urgency'] = $rf['priority'] ?? '';
        $rf['follow_up_date'] = $rf['meta']['follow_up_date'] ?? null;
        $rf['reason'] = $rf['findings'] ?? '';
        if ($modal === 'edit_referral' && (int)$rf['id'] === (int)($_GET['referral_id'] ?? 0)) {
            $selectedReferral = $rf;
        }
    }
    unset($rf);

    try {
        $kpi['exams_today'] = (int) $pdo->query("SELECT COUNT(*) FROM dental_records WHERE record_type='examination' AND record_date=CURDATE()")->fetchColumn();
        $kpi['extractions_month'] = (int) $pdo->query("SELECT COUNT(*) FROM dental_records WHERE record_type='extraction' AND YEAR(record_date)=YEAR(CURDATE()) AND MONTH(record_date)=MONTH(CURDATE())")->fetchColumn();
        $kpi['queue_waiting'] = (int) $pdo->query("SELECT COUNT(*) FROM dental_records WHERE record_type='queue' AND record_date=CURDATE() AND status IN ('Waiting','Called','In Chair')")->fetchColumn();
        $kpi['procedures_month'] = (int) $pdo->query("SELECT COUNT(*) FROM dental_records WHERE record_type='procedure' AND YEAR(record_date)=YEAR(CURDATE()) AND MONTH(record_date)=MONTH(CURDATE())")->fetchColumn();
        $kpi['referrals_pending'] = (int) $pdo->query("SELECT COUNT(*) FROM dental_records WHERE record_type='referral' AND status='Pending'")->fetchColumn();
        $kpi['patients'] = (int) $pdo->query("SELECT COUNT(DISTINCT resident_id) FROM dental_records WHERE resident_id IS NOT NULL")->fetchColumn();
    } catch (Throwable $ex) {
    }

    try {
        $medicineOptions = $pdo->query("SELECT id, item_name FROM medicine_inventory ORDER BY item_name ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ex) {
        $medicineOptions = [];
    }

}

$preselectResident = (int) ($_GET['resident_id'] ?? 0);
$displayResidents = array_values(array_filter($residents, static function (array $resident) use ($dentalSearch): bool {
    return $dentalSearch === '' || stripos(($resident['name'] ?? '') . ' ' . ($resident['id'] ?? ''), $dentalSearch) !== false;
}));

$residentOptionsHtml = '';
foreach ($residents as $r) {
    $ridOpt = (int) ($r['id'] ?? 0);
    if ($ridOpt <= 0) {
        continue;
    }
    $sel = $preselectResident === $ridOpt ? ' selected' : '';
    $residentOptionsHtml .= '<option value="' . $ridOpt . '"' . $sel . '>' . e('Patient #' . $ridOpt . ' - ' . ($r['name'] ?? 'Resident')) . '</option>';
}


$walkinFieldsHtml = <<<'HTML'
<div class="dental-walkin-fields hidden space-y-3 rounded-2xl border border-amber-200 bg-amber-50/80 p-3">
  <p class="text-[11px] font-extrabold uppercase tracking-wide text-amber-800">Walk-in patient (not yet in system)</p>

</div>
HTML;
$dentalIntakeIdentity = <<<'HTML'
  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">First name *</label>
      <input name="walkin_first_name" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white" placeholder="First name">
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Middle name (optional)</label>
      <input name="rhu_intake[middle_name]" maxlength="150" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Last name *</label>
      <input name="walkin_last_name" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white" placeholder="Last name">
    </div>
  </div>
  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Sex</label>
      <select name="walkin_sex" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
        <option>Female</option>
        <option>Male</option>
        <option>Other</option>
      </select>
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Birthdate</label>
      <input type="date" name="walkin_birthdate" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
    </div>
  </div>
  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Contact (optional)</label>
      <input name="walkin_contact" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white" placeholder="09xxxxxxxxx">
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-700 mb-1">Barangay</label>
      <input name="walkin_barangay" value="Poblacion" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
    </div>
  </div>
  <div>
    <label class="block text-xs font-bold text-slate-700 mb-1">Complete address (optional)</label>
    <input name="walkin_address" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white" placeholder="Optional street / sitio">
  </div>
HTML;
$walkinFieldsHtml = substr_replace($walkinFieldsHtml, rhuIntakeFields(['middle_name'], $dentalIntakeIdentity) . '</div>', strrpos($walkinFieldsHtml, '</div>'));

if (!isset($medicineOptions)) {
    $medicineOptions = [];
}
$medicineOptionsHtml = '';
foreach ($medicineOptions as $m) {
    $mid = (int) ($m['id'] ?? 0);
    $mname = e($m['item_name'] ?? 'Item');
    if ($mid <= 0)
        continue;
    $medicineOptionsHtml .= '<option value="' . $mid . '" data-name="' . $mname . '">' . $mname . '</option>';
}

$patientModeBlock = function (string $prefix) use ($preselectResident, $residentOptionsHtml, $walkinFieldsHtml): string {
    $pre = $preselectResident > 0 ? (int) $preselectResident : '';
    return <<<HTML
<div class="space-y-2">
  <p class="text-xs font-bold text-slate-700">Patient *</p>
  <div class="flex gap-2">
    <label class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
      <input type="radio" name="patient_mode" value="existing" class="mr-1 dental-mode-radio" checked> Existing
    </label>
    <label class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
      <input type="radio" name="patient_mode" value="walkin" class="mr-1 dental-mode-radio"> Walk-in (new)
    </label>
  </div>
  <div class="dental-existing-fields">
    <input type="hidden" name="resident_id_hidden" id="{$prefix}_resident_id_hidden" value="{$pre}">
    <select name="resident_id" id="{$prefix}_resident_id" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white"
      onchange="var h=document.getElementById('{$prefix}_resident_id_hidden'); if(h) h.value=this.value;">
      <option value="">— Select registered patient —</option>{$residentOptionsHtml}
    </select>
  </div>
  {$walkinFieldsHtml}
</div>
HTML;
};

$navGroups = [
    'DASHBOARD' => ['overview'],
    'WORKFLOW' => ['queue', 'referrals'],
    'CLINICAL CARE' => ['examinations'],
    'PHARMACY' => ['prescriptions'],
    'SERVICES' => ['patients'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <style>
        /* Paint the dashboard background before external scripts finish loading. */
        html, body { background: #f3f6f4; margin: 0; }

        /* Keep long lists inside their cards, with headings visible while scrolling. */
        .dental-table-scroll,.exam-table-scroll.dental-table-scroll{max-height:min(420px,60vh);overflow:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#94b9ab #f3f8f5;scrollbar-gutter:stable}
        .dental-table-scroll:focus-visible{outline:2px solid #059669;outline-offset:-2px}
        .dental-table-scroll>table{width:100%;min-width:720px;border-collapse:separate;border-spacing:0}
        .dental-table-scroll>table>thead{position:sticky;top:0;z-index:2;background:#f7f9fb}
        .dental-table-scroll>table>thead th{background:inherit;box-shadow:0 1px 0 #e2e8f0}
        .dental-table-scroll::-webkit-scrollbar{width:8px;height:8px}
        .dental-table-scroll::-webkit-scrollbar-track{background:#f3f8f5}
        .dental-table-scroll::-webkit-scrollbar-thumb{background:#94b9ab;border:2px solid #f3f8f5;border-radius:8px}
        @media print{.dental-table-scroll,.exam-table-scroll.dental-table-scroll{max-height:none;overflow:visible}.dental-table-scroll>table>thead{position:static}}
    </style>
    <script>
        (() => {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', <?= json_encode($tab, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                window.history.replaceState(window.history.state, '', url.toString());
            } catch (error) {}
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dental Dashboard RHU</title>
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <style>
        .dental-prescription-sheet { border: 1px solid #e4c8b8; border-radius: 16px; overflow: hidden; background: #fffcf7; box-shadow: 0 3px 12px #5737210a; }
        .dental-rx-medicine { display: flex; align-items: center; gap: 18px; }
        .dental-rx-symbol { font: italic 36px Georgia, serif; color: #a65e42; }
        .dental-rx-name { font-size: 19px !important; font-weight: 800; }
        .dental-rx-directions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 24px; margin-top: 18px; }
        .dental-rx-directions dt { color: #a65e42; font-size: 10px; text-transform: uppercase; font-weight: 800; }
        .dental-rx-directions dd { margin: 4px 0 0; color: #334155; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; white-space: pre-line; }
        @media (max-width: 420px) { .dental-rx-directions { grid-template-columns: 1fr; } }
        .dental-exam-sheet { display: grid; grid-template-columns: minmax(270px, .85fr) minmax(0, 1.15fr); border: 1px solid #e4c8b8; background: #fffcf7; border-radius: 16px; overflow: hidden; box-shadow: 0 3px 12px #5737210a; }
        .dental-sheet-chart { padding: 22px 18px; border-right: 4px double #c47b5e; }
        .dental-sheet-chart svg { width: 100%; height: auto; display: block; max-width: 400px; margin: auto; }
        .dental-sheet-label, .record-quadrant { color: #a65e42; fill: #a65e42; font: bold 10px sans-serif; letter-spacing: .7px; text-transform: uppercase; }
        .dental-sheet-label { text-align: center; margin-bottom: 12px; }
        .record-tooth-number { fill: #704c3c; font: bold 12px sans-serif; }
        .dental-sheet-details { padding: 24px; min-width: 0; }
        .dental-sheet-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 18px; }
        .dental-sheet-heading h3 { color: #a65e42; font-size: 17px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; }
        .dental-sheet-patient > div { display: flex; align-items: baseline; gap: 12px; border-bottom: 1px solid #ddb6a1; padding: 7px 0; }
        .dental-sheet-patient dt { width: 58px; flex-shrink: 0; color: #a65e42; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .dental-sheet-patient dd { font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
        .dental-sheet-section { margin-top: 22px; }
        .dental-sheet-section h4 { font-size: 10px; color: #a65e42; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 8px; }
        .dental-sheet-section p { font-size: 13px; line-height: 28px; min-height: 84px; white-space: pre-line; overflow-wrap: anywhere; background: repeating-linear-gradient(to bottom, transparent 0, transparent 27px, #e8d4c8 27px, #e8d4c8 28px); }
        .dental-sheet-assessment { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 22px; }
        .dental-sheet-assessment > div { border: 1px solid #ead8cc; border-radius: 8px; padding: 8px 10px; flex: 1; min-width: 110px; }
        .dental-sheet-assessment span { display: block; font-size: 10px; color: #93624d; }
        .dental-sheet-assessment strong { display: block; margin-top: 3px; font-size: 13px; }
        .dental-sheet-footer { margin-top: 20px; font-size: 9px; color: #a65e42; text-transform: uppercase; letter-spacing: .5px; }
        @media (max-width: 700px) { .dental-exam-sheet { grid-template-columns: 1fr; } .dental-sheet-chart { border-right: 0; border-bottom: 4px double #c47b5e; } .dental-sheet-details { padding: 20px; } }

        .dental-prescription-sheet { width: 100%; max-width: 640px; margin-inline: auto; border-radius: 12px; }
        .dental-prescription-sheet .dental-sheet-details { padding: 16px 18px; }
        .dental-prescription-sheet .dental-sheet-label { margin-bottom: 6px; font-size: 9px; }
        .dental-prescription-sheet .dental-sheet-heading { margin-bottom: 10px; }
        .dental-prescription-sheet .dental-sheet-heading h3 { font-size: 14px; letter-spacing: 1px; }
        .dental-prescription-sheet .dental-sheet-patient { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 18px; }
        .dental-prescription-sheet .dental-sheet-patient > div { padding: 5px 0; gap: 8px; }
        .dental-prescription-sheet .dental-sheet-patient dt { width: 60px; font-size: 9px; }
        .dental-prescription-sheet .dental-sheet-patient dd { font-size: 12px; }
        .dental-prescription-sheet .dental-sheet-section { margin-top: 12px; }
        .dental-prescription-sheet .dental-sheet-section h4 { margin-bottom: 3px; font-size: 9px; }
        .dental-prescription-sheet .dental-sheet-section p { min-height: 0; background: none; line-height: 1.5; font-size: 12px; }
        .dental-prescription-sheet .dental-rx-medicine { gap: 10px; }
        .dental-prescription-sheet .dental-rx-symbol { font-size: 26px; }
        .dental-prescription-sheet .dental-rx-name { font-size: 16px !important; }
        .dental-prescription-sheet .dental-rx-directions { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px 14px; margin-top: 12px; }
        .dental-prescription-sheet .dental-rx-directions dt { font-size: 9px; }
        .dental-prescription-sheet .dental-rx-directions dd { margin-top: 2px; font-size: 12px; }
        .dental-prescription-sheet .dental-sheet-footer { margin-top: 12px; font-size: 8px; }
        @media (max-width: 600px) {
            .dental-prescription-sheet .dental-sheet-patient { grid-template-columns: 1fr; }
            .dental-prescription-sheet .dental-rx-directions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        .dental-sheet-chart .dental-prescription-embedded { border: 0; border-top: 1px solid #e4c8b8; border-radius: 0; margin-top: 18px; padding-top: 12px; background: transparent; box-shadow: none; }
        .dental-prescription-embedded .dental-sheet-details { padding: 0; }
        .dental-prescription-embedded .dental-sheet-heading { margin-bottom: 4px; }
        .dental-prescription-embedded .dental-sheet-heading h3 { font-size: 12px; }
        .dental-prescription-embedded .dental-rx-record-info { font-size: 10px; color: #64748b; }
        .dental-prescription-embedded .dental-rx-directions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dental-prescription-embedded .dental-rx-name { font-size: 14px !important; }
        .odontogram-board { max-width: 440px; margin: 0 auto; }
        .odontogram-board svg { display: block; width: 100%; height: auto; }
        .odo-quadrant { font: 12px sans-serif; fill: #64748b; }
        .odo-center { font: bold 17px sans-serif; fill: #334155; letter-spacing: 1px; }
        .odo-tooth { cursor: pointer; outline: none; }
        .odo-surface { fill: white; stroke: #475569; stroke-width: 1.5; }
        .odo-detail { fill: none; stroke: #475569; stroke-width: 1; pointer-events: none; }
        .odo-number { font: bold 12px sans-serif; fill: #334155; }
        .odo-tooth:hover .odo-surface, .odo-tooth:focus .odo-surface,
        .odo-tooth[aria-pressed="true"] .odo-surface { stroke: #047857; stroke-width: 3; }
        .odo-picker { border: 0; padding: 16px; border-radius: 16px; background: white; width: min(570px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); overflow-y: auto; margin: auto; color: #0f172a; box-shadow: 0 24px 70px #0f172a40; }
        .odo-picker::backdrop { background: #0f172a66; }
        .odo-picker fieldset { border: 1px solid #cbd5e1; border-radius: 14px; padding: 12px 14px; min-width: 0; }
        .odo-picker legend { padding: 0 4px; }
        .odo-picker .odo-options { gap: 16px 12px; margin-top: 12px; }
        .odo-picker .odo-options label { font-size: 14px; }
        .odo-picker input[type="radio"] { width: 15px; height: 15px; accent-color: #047857; }
        .odo-picker button { font-size: 14px; padding: 8px 14px; }
        @media (max-width: 420px) { .odo-picker .odo-options { grid-template-columns: 1fr; } }
        .odo-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .odo-options label { display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; }
        .odo-swatch { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .odo-color-key { display: flex; flex-wrap: wrap; gap: 8px 12px; margin-top: 10px; font-size: 10px; color: #475569; }
        .odo-color-key span::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--key); margin-right: 4px; }

        html {
            scroll-behavior: auto;
        }

        body.rhu-dental-ui {
            overflow: hidden;
            background: #f3f6f4;
            color: #0f172a;
            height: 100vh;
            height: 100dvh;
        }

        .dental-sidebar {
            width: 14rem;
            background: #fff;
            border-right: 1px solid #e5ebe7;
            display: flex;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            position: sticky;
            top: 0;
            z-index: 30;
            flex-shrink: 0;
            overflow: hidden;
        }

        .dental-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.25rem;
            padding: 0.9rem 1rem;
            flex-shrink: 0;
        }

        .dental-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(0.52);
        }

        .dental-sidebar-brand .brand-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg, rgba(13, 53, 24, .92) 0%, rgba(23, 63, 45, .82) 55%, rgba(47, 111, 73, .7) 100%);
        }

        .admin-shell-header {
            background: #0b3c35;
            border-bottom: 1px solid rgba(10, 51, 43, .9);
            box-shadow: 0 10px 28px rgba(2, 28, 23, .18);
            min-height: 4.5rem;
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #94a3b8;
            padding: .85rem 1.1rem .35rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: .1rem .65rem;
            padding: .65rem .85rem;
            border-radius: .85rem;
            font-size: .8125rem;
            font-weight: 600;
            color: #475569;
            transition: background .15s ease, color .15s ease;
        }

        .nav-item:hover {
            background: #f0fdf6;
            color: #0f766e;
        }

        .nav-item.is-active {
            background: #e8f8ef;
            color: #0f766e;
            box-shadow: inset 0 0 0 1px #ccefdd;
        }

        .nav-item.is-active .nav-arrow {
            opacity: 1;
        }

        .nav-arrow {
            margin-left: auto;
            opacity: 0;
            color: #0f766e;
        }

        .kpi-card,
        .panel-card {
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .kpi-card:hover,
        .panel-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .07);
        }

        @media (max-width: 1023px) {
            .dental-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                transform: translateX(-105%);
                transition: transform .25s ease;
                box-shadow: 8px 0 30px rgba(15, 23, 42, .12);
            }

            .dental-sidebar.is-open {
                transform: translateX(0);
            }
        }

        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .4);
            z-index: 50;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease;
        }

        .sidebar-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }

        .dental-walkin-fields.hidden {
            display: none !important;
        }

        .dental-existing-fields.hidden {
            display: none !important;
        }

        @media (prefers-reduced-motion: reduce) {

            .kpi-card:hover,
            .panel-card:hover {
                transform: none;
            }
        }

        .exam-table-scroll{max-height:560px;overflow:auto;scrollbar-width:thin;scrollbar-color:#a3c7ba #f4f8f6}
        .exam-table-scroll:focus-visible{outline:2px solid #059669;outline-offset:-2px}
        .exam-record-table{width:100%;min-width:860px;table-layout:fixed;border-collapse:separate;border-spacing:0}
        .exam-record-table thead{position:sticky;top:0;z-index:1;background:#f3f8f6}
        .exam-record-table th{font-size:10px;letter-spacing:.7px;color:#60756d;border-bottom:1px solid #dfeae5;padding:14px 18px}
        .exam-record-table th:nth-child(1){width:13%}.exam-record-table th:nth-child(2){width:21%}.exam-record-table th:nth-child(3){width:11%}.exam-record-table th:nth-child(4){width:8%}.exam-record-table th:nth-child(5){width:15%}.exam-record-table th:nth-child(6){width:32%}
        .exam-record-table td{padding:16px 18px;vertical-align:top;border-bottom:1px solid #edf2ef;font-size:12px;line-height:1.6;overflow-wrap:anywhere}
        .exam-record-table .exam-findings{white-space:normal;font-size:12px;color:#52645e}
        .exam-record-table td:nth-child(2)>span{margin-top:4px;font-size:10px;font-weight:500}
        .exam-observation{display:inline-block;padding:3px 8px;border-radius:6px;background:#f1f5f4;color:#486458;font-size:11px}
        .exam-caries{display:inline-grid;place-items:center;min-width:28px;height:26px;border-radius:7px;background:#fff5e9;color:#965b18;font-weight:750}
        .exam-history{margin:-6px 0 -6px;border-radius:8px;background:#f7faf8;padding:8px 12px}
        .exam-history summary{font-size:11px;list-style-position:inside;color:#08765b}
        .exam-history[open] summary{padding-bottom:4px}
        .exam-history p{font-size:12px;line-height:1.7}

        /* Let each list use the remaining screen space rather than a short fixed box. */
        main.dental-list-page{display:flex;flex-direction:column;min-height:0}
        main.dental-list-page>div{flex-shrink:0}
        main.dental-list-page>div:has(>.dental-table-scroll){display:flex;flex-direction:column;flex:1 1 0;min-height:180px}
        main.dental-list-page .dental-table-scroll{flex:1 1 0;min-height:0;height:100%;max-height:none}
        main.dental-list-page .dental-table-scroll>table{height:auto}
        .dental-queue-table td{vertical-align:top}
        main.dental-list-page .dental-table-scroll>table>thead{height:48px}
        @media(min-width:768px){main.dental-queue-page{overflow-y:hidden}}
        @media print{main.dental-list-page{display:block}main.dental-list-page .dental-table-scroll,main.dental-list-page .dental-table-scroll>table{height:auto;overflow:visible}}

        .care-visit-section{padding:16px 0;border-top:1px solid #e2e8f0;display:grid;gap:16px}
        .care-clinical-fields{display:grid;gap:14px}
        .care-visit-section textarea{font-size:12px;line-height:1.65;background:#f8fafc;border-color:#dce5e9;resize:vertical}
        .care-treatment-fields{border-top:1px solid #e2e8f0;padding-top:14px;display:grid;gap:10px}
        .care-treatment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;min-width:0}
        .care-treatment-grid:disabled{opacity:.6;background:#f8fafc}
        .care-treatment-grid label{min-width:0}.care-treatment-grid input,.care-treatment-grid select{font-size:12px;background:white;min-width:0}
        .care-treatment-grid label:has(textarea){grid-column:1/-1}
        .care-treatment-grid>p{grid-column:1/-1}
        @media(max-width:400px){.care-treatment-grid{grid-template-columns:1fr}}
    </style>
<script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(__DIR__ . "/staff-session-lock.js") ?>"></script>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
<style>
    /* Navigation snapshots of main can paint above the referral editor. */
    @view-transition { navigation: none; }
    main { view-transition-name: none; }
    .dental-editor-overlay { position: fixed; inset: 0; z-index: 9999; }
</style>
<script>
    // A refresh should paint immediately instead of replaying a page fade.
    if (performance.getEntriesByType('navigation')[0]?.type === 'reload') {
        const refreshStyle = document.createElement('style');
        refreshStyle.textContent = '@view-transition{navigation:none} main{view-transition-name:none} [data-tab-panel]{animation:none!important}';
        document.head.appendChild(refreshStyle);
    }
</script>
</head>

<body class="rhu-dental-ui antialiased">
    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>

        <!-- Light sidebar (Nurse style) -->
        <aside data-feature-drawer class="dental-sidebar" aria-label="Dental navigation">
            <div class="dental-sidebar-brand">
                <div class="brand-bg" aria-hidden="true"></div>
                <div class="brand-overlay" aria-hidden="true"></div>
                <div class="relative z-10 flex items-center gap-2.5 text-white">
                    <img src="assets/images/nasugbu_seal.png" alt="" class="h-9 w-9 rounded-full bg-white object-cover shadow"
                        onerror="this.style.display='none'">
                    <div class="min-w-0 leading-tight">
                        <p class="text-[11px] font-extrabold tracking-wide">RURAL HEALTH</p>
                        <p class="text-[11px] font-extrabold tracking-wide">UNIT</p>
                        <p class="text-[10px] text-emerald-100/90 mt-0.5 truncate"><?= e($tabs[$tab][0]) ?></p>
                    </div>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto py-2">
                <?php foreach ($navGroups as $group => $ids): ?>
                    <p class="nav-section-label"><?= e($group) ?></p>
                    <?php foreach ($ids as $id):
                        [$label, , $icon] = $tabs[$id];
                        $active = $tab === $id || ($id === 'examinations' && in_array($tab, ['procedures','extractions'], true));
                        if ($id === 'examinations') $label = 'Dental Care';
                        ?>
                        <a href="<?= e(tabUrl($id)) ?>" class="nav-item <?= $active ? 'is-active' : '' ?>">
                            <span class="opacity-90"><?= $icon ?></span>
                            <span class="truncate"><?= e($label) ?></span>
                            <?php if ($active): ?>
                                <svg class="nav-arrow w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    stroke-width="2">
                                    <path d="M9 18l6-6-6-6" />
                                </svg>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>

            <div class="p-3 border-t border-slate-100">
                <a href="RHULogin.php?logout=1" class="nav-item justify-start text-slate-500 hover:text-rose-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                        <path d="M16 17l5-5-5-5" />
                        <path d="M21 12H9" />
                        <path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7" />
                    </svg>
                    <span>Log Out</span>
                </a>
            </div>
        </aside>

        <!-- Main column -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <!-- Dark green header -->
            <header class="admin-shell-header text-white px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" data-drawer-open
                        class="lg:hidden h-10 w-10 rounded-xl border border-white/20 bg-white/5 flex items-center justify-center"
                        aria-label="Open menu">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span
                            class="hidden sm:flex h-9 w-9 rounded-full bg-white/10 border border-white/15 items-center justify-center">
                            <svg class="w-5 h-5 text-emerald-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                stroke-width="1.8">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-200/90">Dental
                                Panel</p>
                            <p class="text-sm sm:text-base font-extrabold truncate"><?= e($tabs[$tab][0]) ?></p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                    <?php if (function_exists('portalRenderNotificationButton'))
                        echo portalRenderNotificationButton(); ?>
                    <div
                        class="flex items-center gap-2 rounded-full bg-white/10 border border-white/15 pl-1 pr-2.5 py-1">
                        <span
                            class="h-8 w-8 rounded-full bg-emerald-400/30 text-white flex items-center justify-center text-sm font-black"><?= e($staffInitial) ?></span>
                        <div class="hidden sm:block leading-tight pr-1">
                            <p class="text-xs font-bold truncate max-w-[9rem]"><?= e($staffName) ?></p>
                            <p class="text-[10px] text-emerald-100/80 font-semibold"><?= e($staffRoleLabel) ?></p>
                        </div>
                    </div>
                    <a href="RHULogin.php?logout=1"
                        class="hidden sm:inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs font-bold">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M16 17l5-5-5-5" />
                            <path d="M21 12H9" />
                        </svg>
                        Log out
                    </a>
                </div>
            </header>
            <div style="display:flex;justify-content:flex-end;padding:10px 24px"><a href="StaffResidentAppointments.php" data-staff-qr-modal style="display:inline-flex;align-items:center;gap:8px;border:1px solid #b8d8ce;border-radius:10px;background:#fff;color:#0f766e;padding:10px 16px;font-size:13px;font-weight:700;text-decoration:none">&#9638; Scan Resident ID</a></div>

            <main class="flex-1 overflow-y-auto p-4 sm:p-6 <?= in_array($tab, ['queue', 'procedures', 'prescriptions', 'referrals', 'examinations', 'extractions', 'patients'], true) ? 'dental-list-page' : '' ?> <?= $tab === 'queue' ? 'dental-queue-page' : '' ?>">
                <?php if ($flashSuccess): ?>
                    <div
                        class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                        <?= e($flashSuccess) ?>
                    </div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div
                        class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
                        <?= e($flashError) ?>
                    </div>
                <?php endif; ?>

                <?php if (in_array($tab, ['examinations','procedures','extractions'], true)): ?>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h1 class="text-lg font-extrabold text-slate-900">Dental Care</h1><p class="text-xs text-slate-500">One patient visit for examination, procedures, and extraction.</p></div>
                    <nav class="flex flex-wrap gap-2" aria-label="Dental care records">
                        <?php foreach (['examinations'=>'Examination records','procedures'=>'Procedure records','extractions'=>'Extraction records'] as $careTab=>$careLabel): ?>
                        <a href="<?= e(tabUrl($careTab)) ?>" <?= $tab === $careTab ? 'aria-current="page"' : '' ?> class="rounded-xl border px-3 py-2 text-xs font-bold <?= $tab === $careTab ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-600' ?>"><?= e($careLabel) ?></a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                <?php endif; ?>
                <?php if ($tab !== 'overview'): ?>
                <form method="get" class="mb-4 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm" role="search">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <label for="dental_search" class="mb-1.5 block text-xs font-bold text-slate-700">Search <?= e($tabs[$tab][0]) ?></label>
                    <div class="flex flex-wrap gap-2">
                        <input type="search" id="dental_search" name="q" value="<?= e($dentalSearch) ?>"
                            placeholder="Search patient, date, tooth, condition, medicine, or status..."
                            class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <button type="submit" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800">Search</button>
                        <?php if ($dentalSearch !== ''): ?>
                            <a href="<?= e(tabUrl($tab)) ?>" class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700">Clear</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($dentalSearch !== ''): ?>
                        <p class="mt-2 text-xs text-slate-500">Showing matches for &ldquo;<?= e($dentalSearch) ?>&rdquo;.</p>
                    <?php endif; ?>
                </form>
                <?php endif; ?>

                <?php if ($tab === 'overview'): ?>
                    <!-- Info banner -->
                    <div
                        class="mb-5 rounded-2xl border border-emerald-100 bg-white px-4 py-3.5 flex flex-col sm:flex-row sm:items-center gap-3 justify-between shadow-sm">
                        <div class="flex gap-3">
                            <span
                                class="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    stroke-width="1.8">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                </svg>
                            </span>
                            <div>
                                <p class="text-sm font-extrabold text-slate-900">RHU Dental Health Services</p>
                                <p class="text-xs text-slate-500 mt-0.5">Mouth examination, tooth extraction, dental
                                    procedures, e-prescriptions, and referrals.</p>
                            </div>
                        </div>
                    </div>

                    <!-- KPI cards -->
                    <div class="grid grid-cols-2 xl:grid-cols-5 gap-3 sm:gap-4 mb-6">
                        <?php
                        $kpiCards = [
                            [
                                'Exams today',
                                $kpi['exams_today'],
                                'Active',
                                'bg-teal-50 text-teal-700',
                                'border-teal-100',
                                '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>'
                            ],
                            [
                                'Extractions (month)',
                                $kpi['extractions_month'],
                                'Clinic',
                                'bg-amber-50 text-amber-700',
                                'border-amber-100',
                                '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M12 2v6"/><path d="M8 8h8"/></svg>'
                            ],
                            [
                                'Dental patients',
                                $kpi['patients'],
                                'Registry',
                                'bg-emerald-50 text-emerald-700',
                                'border-emerald-100',
                                '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>'
                            ],
                            [
                                'Resident appointments',
                                $kpi['appointments'],
                                'Scheduled',
                                'bg-sky-50 text-sky-700',
                                'border-sky-100',
                                '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h3M8 17h5"/></svg>'
                            ],
                        ];
                        foreach ($kpiCards as [$label, $val, $badge, $iconTone, $border, $icon]): ?>
                            <div class="kpi-card rounded-2xl border <?= e($border) ?> bg-white p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-2">
                                    <span
                                        class="h-10 w-10 rounded-xl <?= e($iconTone) ?> flex items-center justify-center"><?= $icon ?></span>
                                    <span
                                        class="text-[10px] font-bold rounded-full border border-slate-200 bg-slate-50 text-slate-600 px-2 py-0.5"><?= e($badge) ?></span>
                                </div>
                                <p class="mt-3 text-2xl font-black text-slate-900"><?= (int) $val ?></p>
                                <p class="text-xs font-semibold text-slate-500 mt-0.5"><?= e($label) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid lg:grid-cols-2 gap-4">
                        <section class="panel-card rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h2 class="font-extrabold text-slate-900">Recent mouth examinations</h2>
                                    <p class="text-xs text-slate-500">Latest examination per patient</p>
                                </div>
                                <a href="<?= e(tabUrl('examinations')) ?>" class="text-xs font-bold text-emerald-700">View
                                    all</a>
                            </div>
                            <?php if (!$exams): ?>
                                <div
                                    class="rounded-xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-10 text-center">
                                    <p class="font-bold text-slate-700">No examinations yet</p>
                                    <p class="text-xs text-slate-500 mt-1">New mouth exams will appear here.</p>
                                </div>
                            <?php else: ?>
                                <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-2">
                                    <?php foreach (array_slice(dentalGroupExaminations($exams), 0, 5) as $patientExams): $row = $patientExams[0]; ?>
                                        <div
                                            class="rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2.5 flex items-center justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold text-slate-900 truncate">
                                                    <?= e($row['resident_name'] ?? 'Patient') ?>
                                                </p>
                                                <p class="text-[11px] text-slate-500"><?= e($row['exam_date'] ?? '') ?> ·
                                                    <?= e($row['oral_hygiene'] ?? '—') ?>
                                                </p>
                                            </div>
                                            <span
                                                class="text-[11px] font-bold text-slate-500 shrink-0"><?= (int) ($row['caries_count'] ?? 0) ?>
                                                caries</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="panel-card rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="font-extrabold text-slate-900 mb-1">Quick actions</h2>
                            <p class="text-xs text-slate-500 mb-4">Start a common dental clinic task</p>
                            <div class="grid sm:grid-cols-2 gap-2.5">
                                <a href="<?= e(tabUrl('examinations', ['modal' => 'new_exam'])) ?>"
                                    class="rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-center text-sm font-bold py-3">New
                                    mouth exam</a>
                                <a href="<?= e(tabUrl('extractions', ['modal' => 'new_exam', 'care_type' => 'extraction'])) ?>"
                                    class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-center text-sm font-bold py-3 text-slate-800">Record
                                    extraction</a>
                                <a href="<?= e(tabUrl('patients')) ?>"
                                    class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-center text-sm font-bold py-3 text-slate-800">Patient
                                    list</a>
                            </div>
                        </section>
                    </div>

                    <section class="panel-card mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <h2 class="font-extrabold text-slate-900">Resident appointments</h2>
                                <p class="text-xs text-slate-500">Upcoming dental requests from the resident portal</p>
                            </div>
                            <a href="<?= e(tabUrl('queue')) ?>" class="text-xs font-bold text-emerald-700">Open queue</a>
                        </div>
                        <?php if (!$dentalAppointments): ?>
                            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-6 text-center">
                                <p class="font-bold text-slate-700">No resident appointments found</p>
                                <p class="mt-1 text-xs text-slate-500">New dental requests will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-2">
                                <?php foreach (array_slice($dentalAppointments, 0, 5) as $appointment): ?>
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2.5">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-900"><?= e($appointment['resident_name'] ?: 'Resident') ?></p>
                                            <p class="text-[11px] text-slate-500"><?= e($appointment['appointment_date']) ?><?= ($appointment['appointment_time'] ?? '') !== '' ? ' · ' . e($appointment['appointment_time']) : '' ?></p>
                                        </div>
                                        <span class="rounded-full border border-sky-100 bg-sky-50 px-2 py-0.5 text-[11px] font-bold text-sky-700"><?= e($appointment['status']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                <?php elseif ($tab === 'queue'): ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-extrabold text-slate-900">Queue & Appointments</h2>
                            <p class="text-xs text-slate-500">Triage for walk-ins and <?= count($appointmentGroups) ?> patients with active dental appointments. Select a patient to view their appointments.</p>
                        </div>
                        <a href="<?= e(tabUrl('queue', ['modal' => 'add_queue'])) ?>"
                            class="inline-flex rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">+
                            Add to queue</a>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-3 mb-4">
                        <?php
                        $w = $c = $chair = 0;
                        $waitingAppointmentPatients = [];
                        foreach ($queueToday as $q) {
                            $s = $q['status'] ?? '';
                            if (($q['is_resident_appointment'] ?? false)
                                && ($q['appointment_date'] ?? '') <= date('Y-m-d')
                                && in_array($s, ['Scheduled', 'In Progress'], true)) {
                                $patientKey = (int)($q['resident_id'] ?? 0) ?: -(int)$q['id'];
                                if (!isset($waitingAppointmentPatients[$patientKey])) {
                                    $waitingAppointmentPatients[$patientKey] = true;
                                    $w++;
                                }
                            } elseif ($s === 'Waiting')
                                $w++;
                            elseif ($s === 'Called')
                                $c++;
                            elseif ($s === 'In Chair')
                                $chair++;
                        }
                        ?>
                        <div class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm">
                            <p class="text-2xl font-black text-amber-700"><?= $w ?></p>
                            <p class="text-xs font-semibold text-slate-500">Waiting</p>
                        </div>
                        <div class="rounded-2xl border border-sky-100 bg-white p-4 shadow-sm">
                            <p class="text-2xl font-black text-sky-700"><?= $c ?></p>
                            <p class="text-xs font-semibold text-slate-500">Called</p>
                        </div>
                        <div class="rounded-2xl border border-emerald-100 bg-white p-4 shadow-sm">
                            <p class="text-2xl font-black text-emerald-700"><?= $chair ?></p>
                            <p class="text-xs font-semibold text-slate-500">In chair</p>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="dental-table-scroll" data-no-record-scroll role="region" aria-label="Queue and appointments" tabindex="0">
                            <table class="dental-queue-table min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">#</th>
                                        <th class="px-4 py-3 font-bold">Patient</th>
                                        <th class="px-4 py-3 font-bold">Priority</th>
                                        <th class="px-4 py-3 font-bold">Complaint</th>
                                        <th class="px-4 py-3 font-bold">Appt</th>
                                        <th class="px-4 py-3 font-bold">Status</th>
                                        <th class="px-4 py-3 font-bold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!$queueDisplayRows): ?>
                                        <tr>
                                            <td colspan="7" class="px-4 py-12 text-center text-slate-500">No patients in today's
                                                dental queue.</td>
                                        </tr>
                                    <?php else:
                                        foreach ($queueDisplayRows as $q):
                                            $pname = trim((string) ($q['resident_name'] ?? '')) ?: trim((string) ($q['walkin_name'] ?? 'Walk-in'));
                                            $pr = $q['priority'] ?? 'Routine';
                                            $prClass = $pr === 'Emergency' ? 'bg-rose-50 text-rose-700 border-rose-100' : ($pr === 'Urgent' ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-slate-50 text-slate-600 border-slate-200');
                                            $appointmentDestination = !empty($q['is_resident_appointment'])
                                                ? ((int)($q['appointment_count'] ?? 1) > 1
                                                    ? tabUrl('queue', ['modal' => 'patient_appointments', 'resident_id' => (int)$q['appointment_group_key']])
                                                    : tabUrl('queue', ['modal' => 'edit_appointment', 'appointment_id' => (int)$q['id']]))
                                                : '';
                                            ?>
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3 font-black text-emerald-800">
                                                    <?= ($q['is_resident_appointment'] ?? false) ? 'Appt' : (int) ($q['queue_number'] ?? 0) ?>
                                                </td>
                                                <td class="px-4 py-3 font-bold text-slate-900">
                                                    <?php if ($q['is_resident_appointment'] ?? false): ?>
                                                        <a href="<?= e($appointmentDestination) ?>" class="font-bold text-emerald-800 underline-offset-2 hover:underline focus-visible:underline" aria-label="View appointments for <?= e($pname) ?>"><?= e($pname) ?></a>
                                                        <?php if (($q['appointment_count'] ?? 1) > 1): ?><span class="block text-[11px] font-semibold text-slate-500"><?= (int)$q['appointment_count'] ?> appointments</span><?php endif; ?>
                                                    <?php else: ?><?= e($pname) ?><?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3"><span
                                                        class="rounded-full border px-2 py-0.5 text-[11px] font-bold <?= e($prClass) ?>"><?= e($pr) ?></span>
                                                </td>
                                                <td class="px-4 py-3 text-slate-600 max-w-xs break-words">
                                                    <?= e($q['chief_complaint'] ?? '—') ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-500">
                                                    <?= e(($q['appointment_date'] ?? '') . (($q['appointment_time'] ?? '') !== '' ? ' ' . $q['appointment_time'] : '')) ?>
                                                </td>
                                                <td class="px-4 py-3 font-semibold"><?= e($q['status'] ?? '') ?></td>
                                                <td class="px-4 py-3">
                                                    <?php if ($q['is_resident_appointment'] ?? false): ?>
                                                        <a href="<?= e($appointmentDestination) ?>" class="inline-flex rounded-lg bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-100"><?= ($q['appointment_count'] ?? 1) > 1 ? 'View appointments' : 'Edit appointment' ?></a>
                                                    <?php else: ?>
                                                    <form method="post" action="<?= e(tabUrl('queue')) ?>"
                                                        class="flex flex-wrap gap-1">
                                                        <input type="hidden" name="action" value="update_queue_status">
                                                        <input type="hidden" name="queue_id" value="<?= (int) $q['id'] ?>">
                                                        <?php
                                                        $queueActions = match ($q['status'] ?? 'Waiting') {
                                                            'Waiting' => ['Called', 'No-show'],
                                                            'Called' => ['In Chair', 'No-show'],
                                                            'In Chair' => ['Completed'],
                                                            default => [],
                                                        };
                                                        foreach ($queueActions as $st):
                                                            $confirmation = $st === 'Completed'
                                                                ? "return confirm('Mark this patient as completed? This removes them from today’s active queue.');"
                                                                : ($st === 'No-show'
                                                                    ? "return confirm('Mark this patient as no-show? This removes them from today’s active queue.');"
                                                                    : '');
                                                            ?>
                                                            <button name="status" value="<?= e($st) ?>" <?= $confirmation !== '' ? 'onclick="' . e($confirmation) . '"' : '' ?>
                                                                class="text-[10px] font-bold rounded-lg border border-slate-200 px-2 py-1 hover:bg-slate-50"><?= e($st) ?></button>
                                                        <?php endforeach; ?>
                                                    </form>
                                                    <?php endif; ?>
                                                    <?php if (($q['status'] ?? '') === 'In Chair' && (int) ($q['resident_id'] ?? 0) > 0): ?>
                                                        <div class="mt-1 flex flex-wrap gap-2 text-[10px] font-bold">
                                                            <a class="text-emerald-700 hover:underline"
                                                                href="<?= e(tabUrl('examinations', ['modal' => 'new_exam', 'resident_id' => (int) $q['resident_id']])) ?>">Exam</a>
                                                            <a class="text-sky-700 hover:underline"
                                                                href="<?= e(tabUrl('procedures', ['modal' => 'new_exam', 'care_type' => 'procedure', 'resident_id' => (int) $q['resident_id']])) ?>">Procedure</a>
                                                            <a class="text-amber-700 hover:underline"
                                                                href="<?= e(tabUrl('extractions', ['modal' => 'new_exam', 'care_type' => 'extraction', 'resident_id' => (int) $q['resident_id']])) ?>">Extraction</a>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($tab === 'procedures'): ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-extrabold text-slate-900">Dental Procedures</h2>
                            <p class="text-xs text-slate-500">Prophylaxis, filling, fluoride, and other RHU
                                dental services</p>
                        </div>
                        <a href="<?= e(tabUrl('procedures', ['modal' => 'new_exam', 'care_type' => 'procedure'])) ?>"
                            class="inline-flex rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">+
                            New dental care visit</a>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="dental-table-scroll" role="region" aria-label="Dental procedures" tabindex="0">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Date</th>
                                        <th class="px-4 py-3 font-bold">Patient</th>
                                        <th class="px-4 py-3 font-bold">Procedure</th>
                                        <th class="px-4 py-3 font-bold">Tooth #</th>
                                        <th class="px-4 py-3 font-bold">Materials</th>
                                        <th class="px-4 py-3 font-bold">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!$procedures): ?>
                                        <tr>
                                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">No procedures recorded
                                                yet.</td>
                                        </tr>
                                    <?php else:
                                        foreach ($procedures as $row): ?>
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3 font-semibold whitespace-nowrap">
                                                    <?= e($row['procedure_date'] ?? '') ?>
                                                </td>
                                                <td class="px-4 py-3 font-bold"><?= e($row['resident_name'] ?? '') ?><?php if ((int)($row['resident_id'] ?? 0) > 0): ?><span class="block text-[11px] font-semibold text-slate-500">Patient #<?= (int)$row['resident_id'] ?></span><?php endif; ?></td>
                                                <td class="px-4 py-3"><span
                                                        class="rounded-full bg-emerald-50 text-emerald-800 border border-emerald-100 px-2 py-0.5 text-[11px] font-bold"><?= e($row['procedure_type'] ?? '') ?></span>
                                                </td>
                                                <td class="px-4 py-3 font-mono"><?= e($row['tooth_numbers'] ?? '—') ?></td>
                                                <td class="px-4 py-3 text-slate-600 max-w-xs truncate">
                                                    <?= e($row['materials'] ?? '—') ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-600 max-w-xs truncate"><?= e($row['notes'] ?? '') ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($tab === 'prescriptions'): ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-extrabold text-slate-900">E-Prescriptions</h2>
                            <p class="text-xs text-slate-500">Antibiotics, pain relievers, and anesthesia-related meds after
                                dental care</p>
                        </div>
                        <a href="<?= e(tabUrl('prescriptions', ['modal' => 'new_rx'])) ?>"
                            class="inline-flex rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">+
                            New prescription</a>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="dental-table-scroll" role="region" aria-label="E-prescriptions" tabindex="0">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Date</th>
                                        <th class="px-4 py-3 font-bold">Patient</th>
                                        <th class="px-4 py-3 font-bold">Medicine</th>
                                        <th class="px-4 py-3 font-bold">Dosage</th>
                                        <th class="px-4 py-3 font-bold">Frequency</th>
                                        <th class="px-4 py-3 font-bold">Duration</th>
                                        <th class="px-4 py-3 font-bold">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!$prescriptions): ?>
                                        <tr>
                                            <td colspan="7" class="px-4 py-12 text-center text-slate-500">No prescriptions
                                                issued yet.</td>
                                        </tr>
                                    <?php else:
                                        foreach ($prescriptions as $row): ?>
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3 font-semibold"><?= e($row['prescription_date'] ?? '') ?></td>
                                                <td class="px-4 py-3 font-bold"><?= e($row['resident_name'] ?? '') ?><?php if ((int)($row['resident_id'] ?? 0) > 0): ?><span class="block text-[11px] font-semibold text-slate-500">Patient #<?= (int)$row['resident_id'] ?></span><?php endif; ?></td>
                                                <td class="px-4 py-3 font-bold text-slate-900"><?= e($row['medicine_name'] ?? '') ?>
                                                </td>
                                                <td class="px-4 py-3"><?= e($row['dosage'] ?? '—') ?></td>
                                                <td class="px-4 py-3"><?= e($row['frequency'] ?? '—') ?></td>
                                                <td class="px-4 py-3"><?= e($row['duration'] ?? '—') ?></td>
                                                <td class="px-4 py-3 text-xs font-bold text-emerald-700">
                                                    <?= e($row['status'] ?? '') ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($tab === 'referrals'): ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-extrabold text-slate-900">Referrals & Follow-up</h2>
                            <p class="text-xs text-slate-500">Endorse complex cases (e.g. impacted wisdom tooth) and
                                schedule return visits. <?= count($referrals) ?> referrals shown; select a patient to edit.</p>
                        </div>
                        <a href="<?= e(tabUrl('referrals', ['modal' => 'new_referral'])) ?>"
                            class="inline-flex rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">+
                            New referral</a>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="dental-table-scroll" role="region" aria-label="Referrals" tabindex="0">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Date</th>
                                        <th class="px-4 py-3 font-bold">Patient</th>
                                        <th class="px-4 py-3 font-bold">Type</th>
                                        <th class="px-4 py-3 font-bold">Facility</th>
                                        <th class="px-4 py-3 font-bold">Urgency</th>
                                        <th class="px-4 py-3 font-bold">Follow-up</th>
                                        <th class="px-4 py-3 font-bold">Status</th>
                                        <th class="px-4 py-3 font-bold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!$referrals): ?>
                                        <tr>
                                            <td colspan="8" class="px-4 py-12 text-center text-slate-500">No referrals yet.</td>
                                        </tr>
                                    <?php else:
                                        foreach ($referrals as $row):
                                            $referralPatientName = trim((string)($row['resident_name'] ?? '')) ?: trim((string)($row['walkin_name'] ?? '')) ?: 'Patient #' . (int)($row['resident_id'] ?? 0);
                                            $referralEditUrl = tabUrl('referrals', ['modal' => 'edit_referral', 'referral_id' => (int)$row['id']]);
                                        ?>
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3 font-semibold"><?= e($row['referral_date'] ?? '') ?></td>
                                                <td class="px-4 py-3 font-bold"><a href="<?= e($referralEditUrl) ?>" class="text-emerald-800 underline-offset-2 hover:underline focus-visible:underline" aria-label="Edit referral for <?= e($referralPatientName) ?>"><?= e($referralPatientName) ?></a><?php if ((int)($row['resident_id'] ?? 0) > 0): ?><span class="block text-[11px] font-semibold text-slate-500">Patient #<?= (int)$row['resident_id'] ?></span><?php endif; ?></td>
                                                <td class="px-4 py-3"><?= e($row['referral_type'] ?? '') ?></td>
                                                <td class="px-4 py-3"><?= e($row['facility_name'] ?? '') ?></td>
                                                <td class="px-4 py-3"><?= e($row['urgency'] ?? '') ?></td>
                                                <td class="px-4 py-3"><?= e($row['follow_up_date'] ?? '—') ?></td>
                                                <td class="px-4 py-3 font-bold text-xs"><?= e($row['status'] ?? '') ?></td>
                                                <td class="px-4 py-3">
                                                    <a href="<?= e($referralEditUrl) ?>" class="text-xs font-bold text-emerald-700 hover:underline">Edit referral</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>


                <?php elseif ($tab === 'examinations'): ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-extrabold text-slate-900">Mouth Examination</h2>
                            <p class="text-xs text-slate-500">Latest examination per patient. Expand previous examinations to view history.</p>
                        </div>
                        <a href="<?= e(tabUrl('examinations', ['modal' => 'new_exam'])) ?>"
                            class="inline-flex rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">+
                            New dental care visit</a>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="exam-table-scroll dental-table-scroll" role="region" aria-label="Mouth examinations" tabindex="0">
                            <table class="exam-record-table min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Date</th>
                                        <th class="px-4 py-3 font-bold">Patient</th>
                                        <th class="px-4 py-3 font-bold">Hygiene</th>
                                        <th class="px-4 py-3 font-bold">Caries</th>
                                        <th class="px-4 py-3 font-bold">Gums</th>
                                        <th class="px-4 py-3 font-bold">Findings</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!$exams): ?>
                                        <tr>
                                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">No examinations
                                                recorded yet.</td>
                                        </tr>
                                    <?php else:
                                        foreach (dentalGroupExaminations($exams) as $patientExams):
                                            $row = $patientExams[0]; ?>
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3 whitespace-nowrap font-semibold">
                                                    <?= e($row['exam_date'] ?? '') ?>
                                                </td>
                                                <td class="px-4 py-3 font-bold text-slate-900"><?= e(trim((string)($row['resident_name'] ?? '')) ?: 'Name not recorded') ?><?php if ((int)($row['resident_id'] ?? 0) > 0): ?><span class="block text-[11px] font-semibold text-slate-500">Patient #<?= (int)$row['resident_id'] ?></span><?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3"><?= e($row['oral_hygiene'] ?? '—') ?></td>
                                                <td class="px-4 py-3"><span class="exam-caries"><?= (int) ($row['caries_count'] ?? 0) ?></span></td>
                                                <td class="px-4 py-3"><?= e($row['gum_condition'] ?? '—') ?></td>
                                                <td class="px-4 py-3 text-slate-600 exam-findings">
                                                    <?= e(dentalRecordViewData($row)['findings'] ?: 'No findings recorded') ?><?php if (dentalRecordViewData($row)['warnings']): ?><p class="text-amber-800 text-xs"><?= e(implode(' ', dentalRecordViewData($row)['warnings'])) ?></p><?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if (count($patientExams) > 1): ?>
                                            <tr><td colspan="6" class="px-4 pb-3">
                                                <details class="exam-history text-sm text-slate-600">
                                                    <summary class="cursor-pointer font-semibold text-emerald-700">Previous examinations (<?= count($patientExams) - 1 ?>)</summary>
                                                    <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3 pt-3">
                                                        <?php foreach (array_slice($patientExams, 1) as $previous): ?>
                                                        <div class="rounded-xl bg-slate-50 p-3">
                                                            <p class="font-semibold"><?= e($previous['exam_date'] ?? '') ?></p>
                                                            <p>Hygiene: <?= e($previous['oral_hygiene'] ?? '') ?> · Caries: <?= (int)(dentalRecordViewData($previous)['caries_count'] ?? 0) ?> · Gums: <?= e($previous['gum_condition'] ?? '') ?></p>
                                                            <p class="whitespace-pre-wrap"><?= e(dentalRecordViewData($previous)['findings'] ?: 'No findings recorded') ?></p>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            </td></tr>
                                            <?php endif; ?>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($tab === 'extractions'): ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-extrabold text-slate-900">Tooth Extraction</h2>
                            <p class="text-xs text-slate-500">Procedure records at the RHU dental clinic</p>
                        </div>
                        <a href="<?= e(tabUrl('extractions', ['modal' => 'new_exam', 'care_type' => 'extraction'])) ?>"
                            class="inline-flex rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">+
                            New dental care visit</a>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="dental-table-scroll" role="region" aria-label="Tooth extractions" tabindex="0">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Date</th>
                                        <th class="px-4 py-3 font-bold">Patient</th>
                                        <th class="px-4 py-3 font-bold">Tooth #</th>
                                        <th class="px-4 py-3 font-bold">Anesthesia</th>
                                        <th class="px-4 py-3 font-bold">Reason</th>
                                        <th class="px-4 py-3 font-bold">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!$extractions): ?>
                                        <tr>
                                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">No extraction records
                                                yet.</td>
                                        </tr>
                                    <?php else:
                                        foreach ($extractions as $row): ?>
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3 whitespace-nowrap font-semibold">
                                                    <?= e($row['extraction_date'] ?? '') ?>
                                                </td>
                                                <td class="px-4 py-3 font-bold"><?= e($row['resident_name'] ?? '') ?><?php if ((int)($row['resident_id'] ?? 0) > 0): ?><span class="block text-[11px] font-semibold text-slate-500">Patient #<?= (int)$row['resident_id'] ?></span><?php endif; ?></td>
                                                <td class="px-4 py-3 font-mono font-bold text-emerald-800">
                                                    <?= e($row['tooth_number'] ?? '') ?>
                                                </td>
                                                <td class="px-4 py-3"><?= e($row['anesthesia'] ?? '—') ?></td>
                                                <td class="px-4 py-3 text-slate-600 max-w-xs truncate">
                                                    <?= e($row['reason'] ?? '') ?>
                                                </td>
                                                <td class="px-4 py-3"><span
                                                        class="rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 px-2 py-0.5 text-[11px] font-bold"><?= e($row['status'] ?? 'Completed') ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="mb-4">
                        <h2 class="font-extrabold text-slate-900">Dental Patients</h2>
                        <p class="text-xs text-slate-500">Residents available for dental services</p>
                    </div>
                    <?php if ($dentalDuplicateGroups): ?>
                        <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                            <h3 class="font-extrabold text-amber-900">Possible duplicate residents</h3>
                            <p class="mt-1 text-xs text-amber-800">Review each match before merging. The duplicate is archived,
                                while its dental records are moved to the primary resident.</p>
                            <div class="mt-3 space-y-3">
                                <?php foreach ($dentalDuplicateGroups as $group): ?>
                                    <form method="post" action="<?= e(tabUrl('patients')) ?>"
                                        class="grid gap-2 rounded-xl border border-amber-200 bg-white p-3 text-sm md:grid-cols-[1fr_1fr_auto] md:items-end">
                                        <input type="hidden" name="action" value="merge_dental_residents">
                                        <label class="block">
                                            <span class="mb-1 block text-xs font-bold text-slate-700">Keep as primary</span>
                                            <select name="primary_resident_id" class="w-full rounded-lg border border-slate-300 p-2"
                                                required>
                                                <?php foreach ($group as $candidate): ?>
                                                    <option value="<?= (int) $candidate['id'] ?>"><?= e($candidate['name']) ?>
                                                        (#<?= (int) $candidate['id'] ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="mb-1 block text-xs font-bold text-slate-700">Archive as duplicate</span>
                                            <select name="duplicate_resident_id"
                                                class="w-full rounded-lg border border-slate-300 p-2" required>
                                                <?php foreach ($group as $candidate): ?>
                                                    <option value="<?= (int) $candidate['id'] ?>" <?= $candidate === $group[0] ? '' : ' selected' ?>><?= e($candidate['name']) ?> (#<?= (int) $candidate['id'] ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <button type="submit"
                                            onclick="return confirm('Move this duplicate resident\'s dental records to the selected primary resident and mark the duplicate as merged?');"
                                            class="rounded-lg bg-amber-600 px-3 py-2 font-bold text-white hover:bg-amber-700">Merge
                                            records</button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="dental-table-scroll" role="region" aria-label="Dental patients" tabindex="0">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Patient ID</th>
                                        <th class="px-4 py-3 font-bold">Resident</th>
                                        <th class="px-4 py-3 font-bold">Records</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!$displayResidents): ?>
                                        <tr>
                                            <td colspan="3" class="px-4 py-12 text-center text-slate-500">No matching residents found.
                                            </td>
                                        </tr>
                                    <?php else:
                                        foreach ($displayResidents as $r): ?>
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3 font-mono text-xs font-bold text-emerald-800">#<?= (int)$r['id'] ?></td>
                                                <td class="px-4 py-3">
                                                    <a class="inline-flex items-center gap-2 font-bold text-slate-900 hover:text-emerald-700"
                                                        href="<?= e(tabUrl('patients', ['resident_records' => (int) $r['id']])) ?>">
                                                        <?= e($r['name'] ?? '') ?>
                                                        <span class="text-[11px] font-extrabold uppercase tracking-wide text-emerald-700">View</span>
                                                    </a>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <a class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-800 hover:bg-emerald-100"
                                                        href="<?= e(tabUrl('patients', ['resident_records' => (int) $r['id']])) ?>">Open records</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($selectedResidentDentalPatient): ?>
        <div class="fixed inset-0 z-[80] flex items-end justify-center bg-slate-900/50 p-3 sm:items-center sm:p-4">
            <div class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b bg-slate-50 px-5 py-4">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-wide text-emerald-700">Dental records</p>
                        <h2 class="text-lg font-extrabold text-slate-900"><?= e($selectedResidentDentalPatient['name'] ?? 'Resident') ?></h2>
                        <p class="mt-1 text-xs font-bold text-emerald-700">Patient ID: #<?= (int)$selectedResidentDentalPatient['id'] ?></p>
                        <p class="mt-1 text-xs text-slate-500"><?= e(trim($selectedResidentDentalPatient['address'] ?? '') ?: 'Address not recorded') ?> / <?= count($selectedResidentDentalRecords) ?> saved dental records</p>
                        <a href="StaffResidentRecords.php?resident_id=<?= (int)$selectedResidentDentalPatient['id'] ?>" class="mt-1 inline-block text-xs font-bold text-emerald-700">View full dental history</a>
                    </div>
                    <a href="<?= e(tabUrl('patients')) ?>" class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white font-bold text-slate-600">×</a>
                </div>
                <div data-staff-record-scroll="Resident dental history" class="overflow-y-auto p-5">
                    <?php if (!$selectedResidentDentalRecords): ?>
                        <div class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-500">
                            <p class="text-sm font-semibold">No dental records found for this resident yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php $prescriptionPlacements = dentalPrescriptionPlacements($selectedResidentDentalRecords); ?>
                            <?php foreach ($selectedResidentDentalRecords as $record):
                                if (($record['record_type'] ?? '') === 'prescription' && isset($prescriptionPlacements['embedded'][(int)$record['id']])) continue;
                                $recordType = ucwords(str_replace('_', ' ', (string) ($record['record_type'] ?? 'Dental record')));
                                $recordTitle = trim((string) ($record['title'] ?? '')) ?: $recordType;
                                $recordMeta = is_array($record['meta'] ?? null) ? $record['meta'] : [];
                                $viewData = dentalRecordViewData($record);
                                if ($viewData['caries_count'] !== null) $recordMeta['caries_count'] = $viewData['caries_count'];
                                $extraDetails = [];
                                foreach (['dosage', 'frequency', 'duration', 'quantity', 'oral_hygiene', 'gum_condition', 'procedure_type', 'facility_name', 'follow_up_date'] as $metaKey) {
                                    if (isset($recordMeta[$metaKey]) && trim((string) $recordMeta[$metaKey]) !== '') {
                                        $extraDetails[] = ucwords(str_replace('_', ' ', $metaKey)) . ': ' . (string) $recordMeta[$metaKey];
                                    }
                                }
                                if (isset($recordMeta['caries_count'])) {
                                    $extraDetails[] = 'Caries count: ' . (string) $recordMeta['caries_count'];
                                }
                            ?>
                                <?php if (($record['record_type'] ?? '') === 'examination'): ?>
                                <article class="dental-exam-sheet">
                                    <div class="dental-sheet-chart">
                                        <p class="dental-sheet-label">Dental chart</p>
                                        <?= dentalRecordChart($viewData['marks']) ?>
                                        <div class="odo-color-key">
                                            <span style="--key:#ef4444">Caries</span><span style="--key:#3b82f6">Pasta</span><span style="--key:#94a3b8">Extracted (X)</span><span style="--key:#f59e0b">For extraction</span><span style="--key:#8b5cf6">Crown</span>
                                        </div>
                                        <?php if (!$viewData['marks']): ?><p class="mt-3 text-xs text-slate-500">No tooth conditions recorded.</p><?php endif; ?>
                                        <?php foreach ($prescriptionPlacements['by_exam'][(int)$record['id']] ?? [] as $sheetPrescription): ?>
                                            <?= dentalPrescriptionSheet($sheetPrescription, $selectedResidentDentalPatient, true) ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="dental-sheet-details">
                                        <div class="dental-sheet-heading">
                                            <h3>Examination</h3>
                                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800"><?= e($record['status'] ?? 'Completed') ?></span>
                                        </div>
                                        <dl class="dental-sheet-patient">
                                            <div><dt>Date</dt><dd><?= e($record['record_date'] ?? '-') ?></dd></div>
                                            <div><dt>Dentist</dt><dd><?= e(trim((string) ($record['provider_name'] ?? '')) ?: 'RHU Dental Clinic') ?></dd></div>
                                        </dl>
                                        <div class="dental-sheet-section">
                                            <h4>Findings / remarks</h4>
                                            <p><?= e($viewData['findings'] ?: 'None recorded') ?></p>
                                            <?php if ($viewData['chart_summary'] !== ''): ?><h4>Recorded chart findings</h4><p><?= e($viewData['chart_summary']) ?></p><?php endif; ?>
                                            <?php if ($viewData['warnings']): ?><p class="text-amber-800" role="note"><?= e(implode(' ', $viewData['warnings'])) ?></p><?php endif; ?>
                                            <p>Unmarked teeth have no condition recorded in this examination; this does not confirm recovery.</p>
                                        </div>
                                        <div class="dental-sheet-section">
                                            <h4>Recommendation / notes</h4>
                                            <p><?= e(trim((string) ($record['notes'] ?? '')) ?: 'None recorded') ?></p>
                                        </div>
                                        <div class="dental-sheet-assessment">
                                            <?php foreach (['oral_hygiene' => 'Oral hygiene', 'gum_condition' => 'Gum condition', 'caries_count' => 'Caries count'] as $key => $label): ?>
                                                <div><span><?= e($label) ?></span><strong><?= e($recordMeta[$key] ?? 'Not recorded') ?></strong></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (!empty($recordMeta['assessment_review']['approved_by'])): ?>
                                            <p class="mt-3 text-xs text-slate-600">Assessment approved by staff #<?= (int)$recordMeta['assessment_review']['approved_by'] ?> on <?= e($recordMeta['assessment_review']['approved_at'] ?? '') ?><?= ($recordMeta['assessment_review']['source'] ?? '') === 'automatic_recommendation' ? ' (reviewed automatic recommendation)' : '' ?>.</p>
                                        <?php endif; ?>
                                        <p class="dental-sheet-footer">RHU Dental Clinic ? Permanent teeth ? FDI notation</p>
                                    </div>
                                </article>
                                <?php elseif (($record['record_type'] ?? '') === 'prescription'): ?>
                                    <?= dentalPrescriptionSheet($record, $selectedResidentDentalPatient, false, false) ?>
                                <?php else: ?>
                                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-emerald-800"><?= e($recordType) ?></span>
                                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black text-slate-700"><?= e($record['status'] ?? 'Completed') ?></span>
                                            </div>
                                            <h3 class="mt-2 text-base font-black text-slate-950"><?= e($recordTitle) ?></h3>
                                            <p class="mt-1 text-xs font-semibold text-slate-500">Provider: <?= e(trim((string) ($record['provider_name'] ?? '')) ?: 'RHU Dental Clinic') ?></p>
                                        </div>
                                        <span class="w-fit rounded-lg bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"><?= e($record['record_date'] ?? '-') ?></span>
                                    </div>
                                    <div class="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                                        <div>
                                            <p class="font-black uppercase tracking-wide text-slate-400">Findings</p>
                                            <p class="mt-1 whitespace-pre-line font-semibold text-slate-800"><?= e($viewData['findings'] ?: 'None recorded') ?></p>
                                        </div>
                                        <div>
                                            <p class="font-black uppercase tracking-wide text-slate-400">Notes</p>
                                            <p class="mt-1 whitespace-pre-line font-semibold text-slate-800"><?= e(trim((string) ($record['tooth_numbers'] ?? '') . "\n" . (string) ($record['notes'] ?? '') . "\n" . implode("\n", $extraDetails)) ?: 'None recorded') ?></p>
                                        </div>
                                    </div>
                                </article>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    $qrDentalBooking = null;
    if ($modal === 'new_exam' && (int)($_GET['qr_appointment'] ?? 0) > 0) {
        try {
            if (!($pdo instanceof PDO)) {
                throw new RuntimeException('Database connection unavailable.');
            }
            $qrDentalBooking = residentQrLoadAppointment($pdo, 'DENTAL', (int)$_GET['qr_appointment'], (int)($_GET['resident_id'] ?? 0));
        }
        catch (Throwable $error) { $modal = ''; echo '<p role="alert">' . e($error->getMessage()) . '</p>'; }
    }
    ?>
    <?php if ($modal === 'new_exam'): ?>
        <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-slate-900/50 p-3 sm:p-4">
            <div
                class="w-full sm:max-w-lg rounded-3xl bg-white shadow-2xl border border-slate-100 overflow-hidden max-h-[92vh] flex flex-col">
                <div class="px-5 py-4 border-b flex items-center justify-between bg-slate-50">
                    <div>
                        <h2 class="font-extrabold text-slate-900">New dental care visit</h2>
                        <p class="text-xs text-slate-500">Examination, optional procedure, and extraction in one save.</p>
                    </div>
                    <a href="<?= e(tabUrl('examinations')) ?>"
                        class="h-8 w-8 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 font-bold">×</a>
                </div>
                <form method="post" action="<?= e(tabUrl('examinations')) ?>" class="p-5 space-y-3 overflow-y-auto text-sm">
                    <input type="hidden" name="action" value="save_examination">
                    <?php if ($qrDentalBooking): ?><input type="hidden" name="consultation_id" value="<?= (int)$qrDentalBooking['id'] ?>"><p class="text-sm font-bold"><?= e($qrDentalBooking['chief_complaint']) ?> ? Appointment #<?= (int)$qrDentalBooking['id'] ?></p><?php endif; ?>
                    <input type="hidden" name="exam_csrf" value="<?= e($_SESSION['dental_exam_csrf']) ?>">
                    <input type="hidden" name="recommendation_mode" value="automatic">
                    <?php if (empty($residents)): ?>
                        <div
                            class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                            No residents found in the database. Register a resident first, then return here to record the
                            examination.
                        </div>
                    <?php endif; ?>
                    <div class="space-y-2">
                        <p class="text-xs font-bold text-slate-700">Patient *</p>
                        <div class="flex gap-2">
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                <input type="radio" name="patient_mode" value="existing" class="mr-1 dental-mode-radio"
                                    checked> Existing
                            </label>
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
                                <input type="radio" name="patient_mode" value="walkin" class="mr-1 dental-mode-radio">
                                Walk-in (new)
                            </label>
                        </div>
                        <div class="dental-existing-fields">
                            <input type="hidden" name="resident_id_hidden" id="exam_resident_id_hidden"
                                value="<?= $preselectResident > 0 ? (int) $preselectResident : '' ?>">
                            <select name="resident_id" id="exam_resident_id"
                                class="w-full rounded-xl border border-slate-300 p-2.5 bg-white"
                                onchange="document.getElementById('exam_resident_id_hidden').value=this.value;">
                                <option value="">— Select registered patient —</option><?= $residentOptionsHtml ?>
                            </select>
                        </div>
                        <?= $walkinFieldsHtml ?>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Exam date</label>
                            <input type="date" name="exam_date" value="<?= e(date('Y-m-d')) ?>"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Oral hygiene</label>
                            <select name="oral_hygiene" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option>Good</option>
                                <option selected>Fair</option>
                                <option>Poor</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Caries count</label>
                            <input type="number" min="0" name="caries_count" value="0" readonly aria-describedby="caries_count_help"
                                class="w-full rounded-xl border border-slate-300 bg-slate-50 p-2.5">
                            <p id="caries_count_help" class="mt-1 text-[10px] text-slate-500">Automatically counts teeth marked Caries / decay.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Gum condition</label>
                            <select name="gum_condition" required class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <?php foreach (dentalGumOptions() as $gumOption): ?><option value="<?= e($gumOption) ?>"><?= e($gumOption) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <div>
                                <p class="text-xs font-extrabold text-slate-800">Odontogram (FDI)</p>
                                <p class="text-[10px] text-slate-500">Click a tooth, then choose condition</p>
                            </div>
                        </div>
                        <p id="odontogram_history_status" role="status" class="mb-2 text-xs text-emerald-800"></p>
                        <button type="button" id="odontogram_history_retry" class="hidden text-xs font-bold text-emerald-700 underline">Retry loading previous chart</button>
                        <input type="hidden" name="odontogram_json" id="odontogram_json" value="{}">
                        <input type="hidden" name="odontogram_cleared" id="odontogram_cleared" value="[]">
                        <div id="odontogram_board" class="odontogram-board">
                            <svg viewBox="0 0 440 510" role="group" aria-label="Permanent teeth in FDI numbering">
                                <text x="12" y="40" class="odo-quadrant">Upper right</text>
                                <text x="428" y="40" text-anchor="end" class="odo-quadrant">Upper left</text>
                                <text x="12" y="482" class="odo-quadrant">Lower right</text>
                                <text x="428" y="482" text-anchor="end" class="odo-quadrant">Lower left</text>
                                <text x="220" y="253" text-anchor="middle" class="odo-center">YOUR TEETH</text>
                                <text x="220" y="275" text-anchor="middle" class="odo-quadrant">FDI numbering</text>
                                <?php
                                foreach (['upper' => [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28], 'lower' => [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38]] as $arch => $teeth) {
                                    foreach ($teeth as $i => $tn) {
                                        [$x, $y, $lx, $ly, $rotation] = dentalToothPlacement($arch, $i);
                                        $geometry = dentalToothGeometry((int) $tn);
                                                                    echo '<g class="odo-tooth" role="button" tabindex="0" aria-label="Tooth ' . $tn . ', sound" aria-pressed="false" data-tooth="' . $tn . '">';
                                        echo '<g transform="translate(' . $x . ' ' . $y . ') rotate(' . $rotation . ')"><path class="odo-surface" d="' . $geometry['outline'] . '"/><path class="odo-detail" d="' . $geometry['detail'] . '"/></g>';
                                        $markY = $y;
                                        echo '<path class="odo-extracted-mark" transform="translate(' . $x . ' ' . $markY . ')" d="M-11,-11 L11,11 M11,-11 L-11,11" fill="none" stroke="#7f1d1d" stroke-width="3.5" stroke-linecap="round" pointer-events="none" style="display:none"/>';
                                        echo '<text x="' . $lx . '" y="' . $ly . '" text-anchor="middle" dominant-baseline="middle" class="odo-number">' . $tn . '</text></g>';
                                    }
                                }
                                ?>
                            </svg>
                        </div>
                        <dialog id="odontogram_picker" class="odo-picker" aria-labelledby="odontogram_selected">
                        <fieldset>
                            <legend id="odontogram_selected" class="text-xs font-bold text-slate-800"></legend>
                            <p class="text-xs text-slate-500 mb-2">Select one condition for this tooth.</p>
                            <div class="odo-options">
                                <?php foreach (['caries' => ['Caries / decay', '#ef4444'], 'filling' => ['Filled / Pasta', '#3b82f6'], 'missing' => ['Extracted / missing', '#94a3b8'], 'to_extract' => ['For extraction', '#f59e0b'], 'crown' => ['Crown / prosthesis', '#8b5cf6']] as $code => [$label, $color]): ?>
                                    <label><input type="radio" name="odontogram_condition" value="<?= e($code) ?>"><span class="odo-swatch" style="background:<?= e($color) ?>"></span><?= e($label) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex gap-2 mt-3">
                                <button type="button" id="odontogram_clear" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs">Clear / sound</button>
                                <button type="button" id="odontogram_done" class="rounded-lg bg-emerald-700 text-white px-3 py-1.5 text-xs">Done</button>
                            </div>
                        </fieldset>
                        </dialog>
                        <div class="odo-color-key" aria-label="Condition colors">
                            <span style="--key:#ef4444">Caries</span><span style="--key:#3b82f6">Pasta</span><span style="--key:#94a3b8">Extracted (X)</span><span style="--key:#f59e0b">For extraction</span><span style="--key:#8b5cf6">Crown</span>
                        </div>
                        <p class="mt-2 text-[10px] text-slate-500" id="odontogram_legend">No marks yet.</p>
                    </div>
                    <section class="care-visit-section">
                    <div id="care_treatment_details" class="care-treatment-fields">
                        <h3 class="text-sm font-bold text-slate-900">Treatment this visit</h3><p id="care_record_destinations" class="text-xs text-slate-600" role="status"></p><p id="care_detection_status" role="status" class="text-xs font-semibold text-emerald-800"></p>
                        <p class="text-xs text-slate-500">Treatment and notes are auto-filled from chart changes and history. All enabled fields remain editable. ?Not documented? means the detail is unknown?replace it with the actual information.</p>
                        <label class="flex gap-2 items-center text-xs font-bold"><input type="checkbox" name="care_procedure" value="1" data-care-toggle="procedure" <?= ($_GET['care_type'] ?? '') === 'procedure' ? 'checked' : '' ?>> Dental procedure performed</label>
                        <fieldset data-care-fields="procedure" disabled class="care-treatment-grid">
                            <label class="block text-xs font-bold">Procedure<select name="care_procedure_type" required class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"><option value="">Select procedure</option><?php foreach (['Oral Prophylaxis','Dental Filling','Temporary Filling','Permanent Filling','Topical Fluoride','Other'] as $option): ?><option><?= e($option) ?></option><?php endforeach; ?></select></label>
                            <label class="block text-xs font-bold">Tooth numbers / ALL<input name="care_procedure_teeth" required placeholder="36, 37 or ALL" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></label>
                            <label class="block text-xs font-bold">Materials<input name="care_materials" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></label>
                            <label class="block text-xs font-bold">Procedure notes<textarea name="care_procedure_notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></textarea></label>
                        </fieldset>
                        <label class="flex gap-2 items-center text-xs font-bold"><input type="checkbox" name="care_extraction" value="1" data-care-toggle="extraction" <?= ($_GET['care_type'] ?? '') === 'extraction' ? 'checked' : '' ?>> Tooth extraction performed</label>
                        <fieldset data-care-fields="extraction" disabled class="care-treatment-grid">
                            <label class="block text-xs font-bold">Extracted tooth numbers<input name="care_extraction_teeth" required placeholder="36, 37" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></label>
                            <label class="block text-xs font-bold">Reason for extraction<input name="care_extraction_reason" required class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></label>
                            <label class="block text-xs font-bold">Extraction notes<textarea name="care_extraction_notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></textarea></label>
                            <p class="text-xs text-slate-500">Mark extracted teeth as Extracted / missing on the odontogram before saving.</p>
                        </fieldset>
                    </div>

                    </section>
                    <section class="care-visit-section" aria-labelledby="visit-summary-title">
                        <div><h3 id="visit-summary-title" class="text-sm font-bold text-slate-900">Assessment &amp; treatment</h3><p class="mt-1 text-xs text-slate-500">Auto-filled from the chart. Review and edit below.</p></div>
                        <div class="care-clinical-fields">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Findings *</label>
                        <textarea name="findings" required rows="3" class="w-full rounded-xl border border-slate-300 p-2.5"
                            placeholder="Tooth and gum findings appear automatically. Add or edit examination notes."></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Recommendation</label>
                        <textarea name="recommendation" rows="4" aria-describedby="dental_recommendation_help"
                            class="w-full rounded-xl border border-slate-300 p-2.5" placeholder="Automatically fills based on the findings. You can edit this recommendation."></textarea>
                        <p id="dental_recommendation_help" class="mt-1 text-xs text-slate-500" role="status">Automatically based on findings. You can edit this text.</p>
                        <button type="button" id="restore_dental_recommendation" hidden class="mt-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700">Restore automatic recommendation</button>
                    </div>
                        </div>
                    </section>
                    <label class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-slate-800">
                        <input type="checkbox" name="exam_approved" value="1" required class="mt-0.5">
                        <span>I reviewed the chart, findings, and recommendation. Every selected treatment was actually performed during this visit, and its details are correct.</span>
                    </label>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('examinations')) ?>"
                            class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Save dental care visit</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'new_extraction'): ?>
        <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-slate-900/50 p-3 sm:p-4">
            <div
                class="w-full sm:max-w-lg rounded-3xl bg-white shadow-2xl border border-slate-100 overflow-hidden max-h-[92vh] flex flex-col">
                <div class="px-5 py-4 border-b flex items-center justify-between bg-slate-50">
                    <div>
                        <h2 class="font-extrabold text-slate-900">Record tooth extraction</h2>
                        <p class="text-xs text-slate-500">Procedure details</p>
                    </div>
                    <a href="<?= e(tabUrl('extractions')) ?>"
                        class="h-8 w-8 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 font-bold">×</a>
                </div>
                <form method="post" action="<?= e(tabUrl('extractions')) ?>" class="p-5 space-y-3 overflow-y-auto text-sm">
                    <input type="hidden" name="action" value="save_extraction">
                    <?php if (empty($residents)): ?>
                        <div
                            class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                            No residents found in the database. Register a resident first, then return here to record the
                            extraction.
                        </div>
                    <?php endif; ?>
                    <div class="space-y-2">
                        <p class="text-xs font-bold text-slate-700">Patient *</p>
                        <div class="flex gap-2">
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                <input type="radio" name="patient_mode" value="existing" class="mr-1 dental-mode-radio"
                                    checked> Existing
                            </label>
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
                                <input type="radio" name="patient_mode" value="walkin" class="mr-1 dental-mode-radio">
                                Walk-in (new)
                            </label>
                        </div>
                        <div class="dental-existing-fields">
                            <input type="hidden" name="resident_id_hidden" id="ext_resident_id_hidden"
                                value="<?= $preselectResident > 0 ? (int) $preselectResident : '' ?>">
                            <select name="resident_id" id="ext_resident_id"
                                class="w-full rounded-xl border border-slate-300 p-2.5 bg-white"
                                onchange="document.getElementById('ext_resident_id_hidden').value=this.value;">
                                <option value="">— Select registered patient —</option><?= $residentOptionsHtml ?>
                            </select>
                        </div>
                        <?= $walkinFieldsHtml ?>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Date</label>
                            <input type="date" name="extraction_date" value="<?= e(date('Y-m-d')) ?>"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Tooth number *</label>
                            <input name="tooth_number" required placeholder="e.g. 36"
                                class="w-full rounded-xl border border-slate-300 p-2.5 font-mono">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Anesthesia</label>
                        <select name="anesthesia" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                            <option>Local</option>
                            <option>None</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Reason</label>
                        <textarea name="reason" rows="2" class="w-full rounded-xl border border-slate-300 p-2.5"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Complications</label>
                        <input name="complications" value="None" class="w-full rounded-xl border border-slate-300 p-2.5">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full rounded-xl border border-slate-300 p-2.5"></textarea>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('extractions')) ?>"
                            class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Save
                            extraction</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>




    <?php if ($modal === 'add_queue'): ?>
        <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-slate-900/50 p-3 sm:p-4">
            <div
                class="w-full sm:max-w-lg rounded-3xl bg-white shadow-2xl border border-slate-100 overflow-hidden max-h-[92vh] flex flex-col">
                <div class="px-5 py-4 border-b flex items-center justify-between bg-slate-50">
                    <div>
                        <h2 class="font-extrabold text-slate-900">Add to dental queue</h2>
                        <p class="text-xs text-slate-500">Walk-in or appointment triage</p>
                    </div>
                    <a href="<?= e(tabUrl('queue')) ?>"
                        class="h-8 w-8 rounded-full bg-white border border-slate-200 flex items-center justify-center font-bold text-slate-600">×</a>
                </div>
                <form method="post" action="<?= e(tabUrl('queue')) ?>" class="p-5 space-y-3 overflow-y-auto text-sm">
                    <input type="hidden" name="action" value="add_queue">
                    <div class="space-y-2">
                        <p class="text-xs font-bold text-slate-700">Patient *</p>
                        <div class="flex gap-2">
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                <input type="radio" name="patient_mode" value="existing" class="mr-1 dental-mode-radio"
                                    checked> Existing
                            </label>
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
                                <input type="radio" name="patient_mode" value="walkin" class="mr-1 dental-mode-radio">
                                Walk-in (new)
                            </label>
                        </div>
                        <div class="dental-existing-fields">
                            <select name="resident_id" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option value="">— Select registered patient —</option><?= $residentOptionsHtml ?>
                            </select>
                        </div>
                        <?= $walkinFieldsHtml ?>
                        <?= rhuVisitFields() ?>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Date</label>
                            <input type="date" name="queue_date" value="<?= e(date('Y-m-d')) ?>"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Priority</label>
                            <select name="priority" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option>Routine</option>
                                <option>Urgent</option>
                                <option>Emergency</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Appointment time (optional)</label>
                        <input type="time" name="appointment_time" min="06:00" max="18:00" aria-describedby="dental-appointment-hours" class="w-full rounded-xl border border-slate-300 p-2.5">
                        <p id="dental-appointment-hours" class="mt-1 text-xs text-slate-500">RHU clinic hours: 6:00 AM to 6:00 PM.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Chief complaint</label>
                        <textarea name="chief_complaint" rows="2" class="w-full rounded-xl border border-slate-300 p-2.5"
                            placeholder="e.g. Severe toothache lower left"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Notes</label>
                        <input name="queue_notes" class="w-full rounded-xl border border-slate-300 p-2.5">
                    </div>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('queue')) ?>"
                            class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Add to
                            queue</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'new_procedure'): ?>
        <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-slate-900/50 p-3 sm:p-4">
            <div
                class="w-full sm:max-w-lg rounded-3xl bg-white shadow-2xl border border-slate-100 overflow-hidden max-h-[92vh] flex flex-col">
                <div class="px-5 py-4 border-b flex items-center justify-between bg-slate-50">
                    <div>
                        <h2 class="font-extrabold text-slate-900">Record dental procedure</h2>
                        <p class="text-xs text-slate-500">Cleaning, filling, fluoride, extraction…</p>
                    </div>
                    <a href="<?= e(tabUrl('procedures')) ?>"
                        class="h-8 w-8 rounded-full bg-white border border-slate-200 flex items-center justify-center font-bold text-slate-600">×</a>
                </div>
                <form method="post" action="<?= e(tabUrl('procedures')) ?>" class="p-5 space-y-3 overflow-y-auto text-sm">
                    <input type="hidden" name="action" value="save_procedure">
                    <div class="space-y-2">
                        <p class="text-xs font-bold text-slate-700">Patient *</p>
                        <div class="flex gap-2">
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                <input type="radio" name="patient_mode" value="existing" class="mr-1 dental-mode-radio"
                                    checked> Existing
                            </label>
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
                                <input type="radio" name="patient_mode" value="walkin" class="mr-1 dental-mode-radio">
                                Walk-in (new)
                            </label>
                        </div>
                        <div class="dental-existing-fields">
                            <select name="resident_id" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option value="">— Select registered patient —</option><?= $residentOptionsHtml ?>
                            </select>
                        </div>
                        <?= $walkinFieldsHtml ?>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Procedure type *</label>
                        <select name="procedure_type" required
                            class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                            <option value="Oral Prophylaxis">Oral Prophylaxis (Cleaning)</option>
                            <option value="Dental Filling">Dental Filling (Pasta)</option>
                            <option value="Temporary Filling">Temporary Filling</option>
                            <option value="Permanent Filling">Permanent Filling</option>
                            <option value="Topical Fluoride">Topical Fluoride Application</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Date</label>
                            <input type="date" name="procedure_date" value="<?= e(date('Y-m-d')) ?>"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Tooth number(s)</label>
                            <input name="tooth_numbers" placeholder="e.g. 36, 37"
                                class="w-full rounded-xl border border-slate-300 p-2.5 font-mono">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Materials / anesthesia</label>
                        <input name="materials" placeholder="e.g. Local anesthesia, GIC"
                            class="w-full rounded-xl border border-slate-300 p-2.5">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Notes</label>
                        <textarea name="procedure_notes" rows="2"
                            class="w-full rounded-xl border border-slate-300 p-2.5"></textarea>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('procedures')) ?>"
                            class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Save
                            procedure</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'new_rx'): ?>
        <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-slate-900/50 p-3 sm:p-4">
            <div
                class="w-full sm:max-w-lg rounded-3xl bg-white shadow-2xl border border-slate-100 overflow-hidden max-h-[92vh] flex flex-col">
                <div class="px-5 py-4 border-b flex items-center justify-between bg-slate-50">
                    <div>
                        <h2 class="font-extrabold text-slate-900">New e-prescription</h2>
                        <p class="text-xs text-slate-500">Post-procedure meds from RHU pharmacy list</p>
                    </div>
                    <a href="<?= e(tabUrl('prescriptions')) ?>"
                        class="h-8 w-8 rounded-full bg-white border border-slate-200 flex items-center justify-center font-bold text-slate-600">×</a>
                </div>
                <form method="post" action="<?= e(tabUrl('prescriptions')) ?>"
                    class="p-5 space-y-3 overflow-y-auto text-sm">
                    <input type="hidden" name="action" value="save_prescription">
                    <input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">
                    <div class="space-y-2">
                        <p class="text-xs font-bold text-slate-700">Patient *</p>
                        <div class="flex gap-2">
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                <input type="radio" name="patient_mode" value="existing" class="mr-1 dental-mode-radio"
                                    checked> Existing
                            </label>
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
                                <input type="radio" name="patient_mode" value="walkin" class="mr-1 dental-mode-radio">
                                Walk-in (new)
                            </label>
                        </div>
                        <div class="dental-existing-fields">
                            <select name="resident_id" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option value="">— Select registered patient —</option><?= $residentOptionsHtml ?>
                            </select>
                        </div>
                        <?= $walkinFieldsHtml ?>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">From inventory (optional)</label>
                        <select id="rx_inventory" name="inventory_id"
                            class="w-full rounded-xl border border-slate-300 p-2.5 bg-white"
                            onchange="var o=this.options[this.selectedIndex]; var n=document.getElementById('rx_med_name'); if(o&&o.dataset.name&&n) n.value=o.dataset.name;">
                            <option value="">— Select stock item —</option><?= $medicineOptionsHtml ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-xs font-bold text-slate-700">Dispensing location<input name="dispense_location" value="RHU Dental Clinic" maxlength="200" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></label>
                        <label class="text-xs font-bold text-slate-700">Dispensed on<input type="datetime-local" name="dispensed_at" value="<?= date('Y-m-d\TH:i') ?>" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></label>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Medicine name *</label>
                        <input name="medicine_name" id="rx_med_name" required list="rx_common"
                            class="w-full rounded-xl border border-slate-300 p-2.5" placeholder="e.g. Amoxicillin 500mg">
                        <datalist id="rx_common">
                            <option value="Amoxicillin 500mg">
                            <option value="Mefenamic Acid 500mg">
                            <option value="Paracetamol 500mg">
                            <option value="Ibuprofen 400mg">
                            <option value="Lidocaine (local anesthesia)">
                        </datalist>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Dosage *</label>
                            <input name="dosage" required placeholder="1 cap"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Frequency *</label>
                            <input name="frequency" required placeholder="3x a day"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Duration *</label>
                            <input name="duration" required placeholder="7 days"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Quantity *</label>
                            <input name="quantity" required placeholder="21 caps"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Date *</label>
                        <input type="date" name="prescription_date" required max="<?= date('Y-m-d') ?>" value="<?= e(date('Y-m-d')) ?>"
                            class="w-full rounded-xl border border-slate-300 p-2.5">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Instructions *</label>
                        <textarea name="instructions" required rows="2" class="w-full rounded-xl border border-slate-300 p-2.5"
                            placeholder="Take after meals"></textarea>
                    </div>
                    <p id="rx_completion_help" class="text-xs text-slate-600" role="status">Complete the required patient and prescription details to issue.</p>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('prescriptions')) ?>"
                            class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Issue
                            prescription</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'new_referral'): ?>
        <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-slate-900/50 p-3 sm:p-4">
            <div
                class="w-full sm:max-w-lg rounded-3xl bg-white shadow-2xl border border-slate-100 overflow-hidden max-h-[92vh] flex flex-col">
                <div class="px-5 py-4 border-b flex items-center justify-between bg-slate-50">
                    <div>
                        <h2 class="font-extrabold text-slate-900">New referral / follow-up</h2>
                        <p class="text-xs text-slate-500">Internal RHU or external hospital</p>
                    </div>
                    <a href="<?= e(tabUrl('referrals')) ?>"
                        class="h-8 w-8 rounded-full bg-white border border-slate-200 flex items-center justify-center font-bold text-slate-600">×</a>
                </div>
                <form method="post" action="<?= e(tabUrl('referrals')) ?>" class="p-5 space-y-3 overflow-y-auto text-sm">
                    <input type="hidden" name="action" value="save_referral">
                    <div class="space-y-2">
                        <p class="text-xs font-bold text-slate-700">Patient *</p>
                        <div class="flex gap-2">
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                <input type="radio" name="patient_mode" value="existing" class="mr-1 dental-mode-radio"
                                    checked> Existing
                            </label>
                            <label
                                class="flex-1 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
                                <input type="radio" name="patient_mode" value="walkin" class="mr-1 dental-mode-radio">
                                Walk-in (new)
                            </label>
                        </div>
                        <div class="dental-existing-fields">
                            <select name="resident_id" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option value="">— Select registered patient —</option><?= $residentOptionsHtml ?>
                            </select>
                        </div>
                        <?= $walkinFieldsHtml ?>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Referral type</label>
                            <select name="referral_type" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option>External</option>
                                <option>Internal</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Urgency</label>
                            <select name="urgency" class="w-full rounded-xl border border-slate-300 p-2.5 bg-white">
                                <option>Routine</option>
                                <option>Urgent</option>
                                <option>Emergency</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Facility *</label>
                        <input name="facility_name" required list="facilities"
                            class="w-full rounded-xl border border-slate-300 p-2.5"
                            placeholder="e.g. Batangas Provincial Hospital">
                        <datalist id="facilities">
                            <option value="Batangas Medical Center">
                            <option value="Batangas Provincial Hospital">
                            <option value="Nasugbu RHU II">
                            <option value="Private dental clinic">
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Reason *</label>
                        <textarea name="reason" required rows="2" class="w-full rounded-xl border border-slate-300 p-2.5"
                            placeholder="e.g. Impacted wisdom tooth needing specialist care"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Referral date</label>
                            <input type="date" name="referral_date" value="<?= e(date('Y-m-d')) ?>"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Follow-up date</label>
                            <input type="date" name="follow_up_date"
                                class="w-full rounded-xl border border-slate-300 p-2.5">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Notes</label>
                        <textarea name="referral_notes" rows="2"
                            class="w-full rounded-xl border border-slate-300 p-2.5"></textarea>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('referrals')) ?>"
                            class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Save
                            referral</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($modal === 'patient_appointments' && $selectedAppointmentGroup): ?>
        <div class="dental-editor-overlay flex items-end justify-center bg-slate-900/50 p-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="patient-appointments-title">
            <div class="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl sm:max-w-lg">
                <div class="flex items-center justify-between border-b bg-slate-50 px-5 py-4">
                    <div>
                        <h2 id="patient-appointments-title" class="font-extrabold text-slate-900">Dental appointments</h2>
                        <p class="text-xs text-slate-500"><?= e($selectedAppointmentGroup[0]['resident_name'] ?: 'Resident') ?> · <?= count($selectedAppointmentGroup) ?> appointments</p>
                    </div>
                    <a href="<?= e(tabUrl('queue')) ?>" class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white font-bold text-slate-600" aria-label="Close appointment list">&times;</a>
                </div>
                <div class="space-y-2 overflow-y-auto p-5">
                    <?php foreach ($selectedAppointmentGroup as $patientAppointment): ?>
                        <a href="<?= e(tabUrl('queue', ['modal' => 'edit_appointment', 'appointment_id' => (int)$patientAppointment['id']])) ?>" class="block rounded-xl border border-slate-200 p-3 hover:border-emerald-300 hover:bg-emerald-50">
                            <span class="flex flex-wrap items-center justify-between gap-2 text-sm font-bold text-slate-900">
                                <span><?= e($patientAppointment['appointment_date']) ?><?= !empty($patientAppointment['appointment_time']) ? ' at ' . e($patientAppointment['appointment_time']) : '' ?></span>
                                <span class="text-xs text-emerald-700"><?= e($patientAppointment['status']) ?></span>
                            </span>
                            <span class="mt-1 block break-words text-xs text-slate-600"><?= e($patientAppointment['chief_complaint'] ?: 'No complaint recorded') ?></span>
                            <span class="mt-2 block text-xs font-bold text-emerald-700">Edit this appointment</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'edit_appointment' && $selectedDentalAppointment): ?>
        <div class="dental-editor-overlay flex items-end justify-center bg-slate-900/50 p-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="edit-appointment-title">
            <div class="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl sm:max-w-lg">
                <div class="flex items-center justify-between border-b bg-slate-50 px-5 py-4">
                    <div>
                        <h2 id="edit-appointment-title" class="font-extrabold text-slate-900">Edit dental appointment</h2>
                        <p class="text-xs text-slate-500"><?= e($selectedDentalAppointment['resident_name'] ?: 'Resident') ?></p>
                    </div>
                    <a href="<?= e(tabUrl('queue')) ?>" class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white font-bold text-slate-600" aria-label="Close appointment editor">&times;</a>
                </div>
                <form method="post" action="<?= e(tabUrl('queue')) ?>" class="space-y-3 overflow-y-auto p-5 text-sm">
                    <input type="hidden" name="action" value="update_resident_appointment">
                    <input type="hidden" name="consultation_id" value="<?= (int)$selectedDentalAppointment['id'] ?>">
                    <input type="hidden" name="resident_id" value="<?= (int)$selectedDentalAppointment['resident_id'] ?>">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                        <p class="font-bold text-slate-900"><?= e($selectedDentalAppointment['appointment_date']) ?><?= !empty($selectedDentalAppointment['appointment_time']) ? ' at ' . e($selectedDentalAppointment['appointment_time']) : '' ?></p>
                        <p class="mt-1 break-words"><?= e($selectedDentalAppointment['chief_complaint'] ?: 'No complaint recorded') ?></p>
                    </div>
                    <label class="block text-xs font-bold text-slate-700">Status
                        <select name="consultation_status" class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-2.5">
                            <?php foreach (['Scheduled', 'In Progress', 'Completed', 'No-show', 'Cancelled', 'Referred'] as $appointmentStatus): ?><option value="<?= e($appointmentStatus) ?>" <?= ($selectedDentalAppointment['status'] ?? '') === $appointmentStatus ? 'selected' : '' ?>><?= e($appointmentStatus) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-xs font-bold text-slate-700">Assessment / findings
                        <input name="diagnosis" value="<?= e($selectedDentalAppointment['diagnosis'] ?? '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5">
                    </label>
                    <label class="block text-xs font-bold text-slate-700">Notes and booking details
                        <textarea name="consultation_notes" rows="5" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"><?= e($selectedDentalAppointment['remarks'] ?? '') ?></textarea>
                    </label>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('queue')) ?>" class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit" class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'edit_referral' && $selectedReferral): ?>
        <div class="dental-editor-overlay flex items-end justify-center bg-slate-900/50 p-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="edit-referral-title">
            <div class="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl sm:max-w-lg">
                <div class="flex items-center justify-between border-b bg-slate-50 px-5 py-4">
                    <div>
                        <h2 id="edit-referral-title" class="font-extrabold text-slate-900">Edit referral / follow-up</h2>
                        <p class="text-xs text-slate-500"><?= e(trim((string)($selectedReferral['resident_name'] ?? '')) ?: (string)($selectedReferral['walkin_name'] ?? 'Patient')) ?></p>
                    </div>
                    <a href="<?= e(tabUrl('referrals')) ?>" class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white font-bold text-slate-600" aria-label="Close referral editor">&times;</a>
                </div>
                <form method="post" action="<?= e(tabUrl('referrals')) ?>" class="space-y-3 overflow-y-auto p-5 text-sm">
                    <input type="hidden" name="action" value="edit_referral">
                    <input type="hidden" name="referral_id" value="<?= (int)$selectedReferral['id'] ?>">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-xs font-bold text-slate-700">Referral type
                            <select name="referral_type" class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-2.5">
                                <?php foreach (['Internal', 'External'] as $type): ?><option value="<?= e($type) ?>" <?= ($selectedReferral['referral_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block text-xs font-bold text-slate-700">Urgency
                            <select name="urgency" class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-2.5">
                                <?php foreach (['Routine', 'Urgent', 'Emergency'] as $level): ?><option value="<?= e($level) ?>" <?= ($selectedReferral['urgency'] ?? '') === $level ? 'selected' : '' ?>><?= e($level) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label class="block text-xs font-bold text-slate-700">Facility
                        <input name="facility_name" required value="<?= e($selectedReferral['facility_name'] ?? '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5">
                    </label>
                    <label class="block text-xs font-bold text-slate-700">Reason
                        <textarea name="reason" required rows="2" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"><?= e($selectedReferral['reason'] ?? '') ?></textarea>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-xs font-bold text-slate-700">Referral date
                            <input type="date" name="referral_date" required value="<?= e($selectedReferral['referral_date'] ?? '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5">
                        </label>
                        <label class="block text-xs font-bold text-slate-700">Follow-up date
                            <input type="date" name="follow_up_date" value="<?= e($selectedReferral['follow_up_date'] ?? '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5">
                        </label>
                    </div>
                    <label class="block text-xs font-bold text-slate-700">Status
                        <select name="status" class="mt-1 w-full rounded-xl border border-slate-300 bg-white p-2.5">
                            <?php foreach (['Pending', 'Completed', 'Cancelled'] as $state): ?><option value="<?= e($state) ?>" <?= ($selectedReferral['status'] ?? '') === $state ? 'selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-xs font-bold text-slate-700">Notes
                        <textarea name="referral_notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"><?= e($selectedReferral['notes'] ?? '') ?></textarea>
                    </label>
                    <div class="flex gap-2 pt-1">
                        <a href="<?= e(tabUrl('referrals')) ?>" class="flex-1 rounded-xl border border-slate-300 py-2.5 text-center font-bold text-slate-700">Cancel</a>
                        <button type="submit" class="flex-1 rounded-xl bg-emerald-700 py-2.5 font-bold text-white hover:bg-emerald-800">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if (function_exists('portalRenderNotificationPanel'))
        echo portalRenderNotificationPanel(); ?>

    <script defer src="assets/js/dental-exam-suggestions.js?v=8"></script>
    <script defer src="assets/js/dental-prescription-validation.js?v=1"></script>
    <script>
        (function () {
            const sidebar = document.querySelector('[data-feature-drawer]');
            const backdrop = document.querySelector('[data-drawer-backdrop]');
            const openBtn = document.querySelector('[data-drawer-open]');
            const setOpen = (open) => {
                sidebar?.classList.toggle('is-open', open);
                backdrop?.classList.toggle('is-open', open);
            };
            openBtn?.addEventListener('click', () => setOpen(true));
            backdrop?.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });

            function syncPatientMode(form) {
                if (!form) return;
                const mode = form.querySelector('input[name="patient_mode"]:checked')?.value || 'existing';
                const existing = form.querySelector('.dental-existing-fields');
                const walkin = form.querySelector('.dental-walkin-fields');
                if (existing) existing.classList.toggle('hidden', mode !== 'existing');
                if (walkin) walkin.classList.toggle('hidden', mode !== 'walkin');
                const sel = form.querySelector('select[name="resident_id"]');
                if (sel) sel.required = mode === 'existing';
            }
            document.querySelectorAll('.dental-mode-radio').forEach((radio) => {
                radio.addEventListener('change', () => syncPatientMode(radio.closest('form')));
            });
            document.querySelectorAll('form').forEach((form) => syncPatientMode(form));


            document.querySelectorAll('[data-care-toggle]').forEach(toggle => {
                const fields = toggle.form.querySelector('[data-care-fields="' + toggle.dataset.careToggle + '"]');
                const sync = () => {
                    fields.disabled = !toggle.checked;
                    fields.hidden = false;
                    const approval = toggle.form.elements.exam_approved;
                    if (approval) approval.checked = false;
                };
                toggle.addEventListener('change', sync);
                sync();
            });

            // Compare with the loaded history, never count existing treatment as new work.
            function detectVisitTreatments(current, previous) {
                const result = {procedure:[], extraction:[]};
                Object.entries(current).forEach(([tooth, codes]) => {
                    if (!/^[1-4][1-8]$/.test(tooth) || !Array.isArray(codes)) return;
                    const condition = codes[0];
                    if (condition === previous[tooth]?.[0]) return;
                    if (condition === 'filling') result.procedure.push(tooth);
                    if (condition === 'missing') result.extraction.push(tooth);
                });
                Object.values(result).forEach(teeth => teeth.sort((a,b) => Number(a)-Number(b)));
                return result;
            }

            function treatmentDetailSuggestions(kind, teethText, previous, procedureType) {
                const teeth = [...new Set(teethText.match(/\b[1-4][1-8]\b/g) || [])];
                const labels = {caries:'Caries / decay',filling:'Filled / Pasta',missing:'Extracted / missing',to_extract:'For extraction assessment',crown:'Crown / prosthesis'};
                const history = teeth.map(tooth => '#' + tooth + ': ' + (labels[previous[tooth]?.[0]] || 'No previous condition recorded')).join('; ');
                if (kind === 'extraction') return {
                    care_extraction_reason: 'Clinical indication not documented.' + (history ? ' Prior chart: ' + history + '.' : ''),
                    care_extraction_notes: teeth.length ? 'Teeth ' + teeth.map(tooth => '#' + tooth).join(', ') + ' selected for extraction in this visit. Verify the actual procedure and details before saving.' : '',
                };
                return {
                    care_materials: 'Not documented',
                    care_procedure_notes: (procedureType || 'Dental procedure') + ' selected for ' + (teeth.length ? teeth.map(tooth => '#' + tooth).join(', ') : teethText || 'the selected teeth') + '.' + (history ? ' Prior chart: ' + history + '.' : '') + ' Verify actual treatment and materials before saving.',
                };
            }

            // One condition is stored for each FDI tooth.
            (function () {
                const board = document.getElementById('odontogram_board');
                const hidden = document.getElementById('odontogram_json');
                if (!board || !hidden) return;
                const picker = document.getElementById('odontogram_picker');
                const legend = document.getElementById('odontogram_legend');
                const heading = document.getElementById('odontogram_selected');
                const cariesCount = hidden.closest('form').querySelector('input[name="caries_count"]');
                const colors = {caries:'#ef4444', filling:'#3b82f6', missing:'#94a3b8', to_extract:'#f59e0b', crown:'#8b5cf6'};
                const labels = {caries:'Caries / decay', filling:'Filled / Pasta', missing:'Extracted / missing', to_extract:'For extraction', crown:'Crown / prosthesis'};
                const marks = {};
                const cleared = new Set();
                let sources = {};
                let previousMarks = {};
                const manualTreatments = new Set();
                const autoValues = new Map();
                const manualFields = new Set();
                const careForm = hidden.closest('form');
                careForm.addEventListener('input', event => {
                    if (event.isTrusted && event.target.name?.startsWith('care_')) manualFields.add(event.target.name);
                });
                careForm.addEventListener('change', event => {
                    if (event.isTrusted && event.target.name?.startsWith('care_')) manualFields.add(event.target.name);
                });

                function renderVisitSummary() {
                    const destinations = ['Examination'];
                    if (careForm.elements.care_procedure.checked) destinations.push('Procedure: ' + (careForm.elements.care_procedure_type.value || 'select type'));
                    if (careForm.elements.care_extraction.checked) destinations.push('Extraction');
                    document.getElementById('care_record_destinations').textContent = 'Records on save: ' + destinations.join(' + ') + '. One linked patient visit.';
                    // Grow the visible clinical fields without rendering a duplicate summary.
                    ['findings','recommendation'].forEach(name => {
                        const input = careForm.elements[name];
                        input.style.height = 'auto';
                        input.style.height = Math.max(100, Math.min(input.scrollHeight + 2, 240)) + 'px';
                    });
                }
                careForm.addEventListener('input', renderVisitSummary);
                careForm.addEventListener('change', renderVisitSummary);
                careForm.addEventListener('dental:summary-updated', renderVisitSummary);
                careForm.addEventListener('invalid', event => {
                    let parent = event.target.parentElement;
                    while (parent && parent !== careForm) {
                        if (parent.tagName === 'DETAILS') parent.open = true;
                        parent = parent.parentElement;
                    }
                }, true);

                careForm.querySelectorAll('[data-care-toggle]').forEach(toggle => {
                    toggle.addEventListener('change', event => {
                        if (event.isTrusted) manualTreatments.add(toggle.dataset.careToggle);
                    });
                });
                function fillSuggested(name, value) {
                    const input = careForm.elements[name];
                    if (!input || manualFields.has(name) || (input.value && input.value !== autoValues.get(name))) return;
                    input.value = value;
                    autoValues.set(name, value);
                }
                function fillTreatmentDetails() {
                    ['procedure','extraction'].forEach(kind => {
                        const enabled = careForm.elements['care_' + kind].checked;
                        const teeth = careForm.elements['care_' + kind + '_teeth'].value;
                        const values = enabled ? treatmentDetailSuggestions(kind, teeth, previousMarks, careForm.elements.care_procedure_type.value) : {};
                        const names = kind === 'procedure' ? ['care_materials','care_procedure_notes'] : ['care_extraction_reason','care_extraction_notes'];
                        names.forEach(name => fillSuggested(name, values[name] || ''));
                    });
                }
                careForm.addEventListener('input', event => {
                    if (event.target.name?.startsWith('care_')) fillTreatmentDetails();
                });
                careForm.addEventListener('change', event => {
                    if (event.target.name?.startsWith('care_')) fillTreatmentDetails();
                });
                function suggestTreatments() {
                    const suggested = detectVisitTreatments(marks, previousMarks);
                    ['procedure','extraction'].forEach(kind => {
                        if (manualTreatments.has(kind)) return;
                        const toggle = careForm.querySelector('[data-care-toggle="' + kind + '"]');
                        const teeth = suggested[kind].join(', ');
                        const selected = Boolean(teeth);
                        // Explicit requests to add treatment remain available for manual entry.
                        if (teeth || toggle.dataset.autoSuggested === '1') {
                            if (toggle.checked !== selected) {
                                toggle.checked = selected;
                                toggle.dispatchEvent(new Event('change', {bubbles:true}));
                            }
                            toggle.dataset.autoSuggested = '1';
                        }
                        fillSuggested('care_' + kind + '_teeth', teeth);
                        if (kind === 'procedure') fillSuggested('care_procedure_type', teeth ? 'Dental Filling' : '');
                    });
                    fillTreatmentDetails();
                    const count = suggested.procedure.length + suggested.extraction.length;
                    document.getElementById('care_detection_status').textContent = count
                        ? 'Detected chart changes: ' + suggested.procedure.length + ' newly filled and ' + suggested.extraction.length + ' newly missing teeth. Confirm these are treatments performed today.'
                        : 'No new fillings or extractions detected. Other procedures can be added manually.';
                    careForm.elements.care_procedure_teeth.dispatchEvent(new Event('change', {bubbles:true}));
                    renderVisitSummary();
                }
                let selected = null;
                function refresh() {
                    board.querySelectorAll('.odo-tooth').forEach(btn => {
                        const tooth = btn.dataset.tooth;
                        const codes = marks[tooth] || [];
                        const fill = codes.length ? colors[codes[0]] : '#fff';
                        btn.querySelector('.odo-surface').style.fill = fill;
                        btn.querySelector('.odo-extracted-mark').style.display = codes.includes('missing') ? '' : 'none';
                        btn.setAttribute('aria-label', 'Tooth ' + tooth + ', ' + (codes.map(c => labels[c]).join(', ') || 'sound'));
                        btn.setAttribute('aria-pressed', String(tooth === selected));
                    });
                    hidden.value = JSON.stringify(marks);
                    document.getElementById('odontogram_cleared').value = JSON.stringify([...cleared]);
                    if (cariesCount) cariesCount.value = Object.values(marks).filter(codes => codes.includes('caries')).length;
                    legend.textContent = Object.keys(marks).map(t => '#' + t + ': ' + marks[t].map(c => labels[c]).join(', ')).join(' | ') || 'No marks yet.';
                    hidden.dispatchEvent(new Event('change', {bubbles:true}));
                    suggestTreatments();
                }
                function select(btn) {
                    if (loading || failed) return;
                    selected = btn.dataset.tooth;
                    heading.textContent = 'Tooth ' + selected + ' - conditions' + (sources[selected] ? ' (recorded ' + sources[selected] + ')' : '');
                    if (!picker.open) picker.showModal();
                    picker.querySelectorAll('input').forEach(input => input.checked = (marks[selected] || []).includes(input.value));
                    refresh();
                    picker.querySelector('input').focus({preventScroll:true});
                }
                board.querySelectorAll('.odo-tooth').forEach(btn => {
                    btn.addEventListener('click', () => select(btn));
                    btn.addEventListener('keydown', event => {
                        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(btn); }
                    });
                });
                picker.addEventListener('change', () => {
                    if (!selected) return;
                    const choice = picker.querySelector('input:checked');
                    const codes = choice ? [choice.value] : [];
                    if (codes.length) { marks[selected] = codes; cleared.delete(selected); }
                    else { delete marks[selected]; cleared.add(selected); }
                    refresh();
                });
                document.getElementById('odontogram_clear').addEventListener('click', () => {
                    if (!selected) return;
                    delete marks[selected];
                    cleared.add(selected);
                    picker.querySelectorAll('input').forEach(input => input.checked = false);
                    refresh();
                });
                function closePicker() {
                    const btn = board.querySelector('[data-tooth="' + selected + '"]');
                    picker.close();
                    selected = null;
                    refresh();
                    btn?.focus({preventScroll:true});
                }
                document.getElementById('odontogram_done').addEventListener('click', closePicker);
                picker.addEventListener('cancel', event => { event.preventDefault(); closePicker(); });
                picker.addEventListener('keydown', event => {
                    if (event.key === 'Escape') event.stopPropagation();
                    if (event.key === 'Enter' && event.target.matches('input')) event.preventDefault();
                });
                picker.addEventListener('click', event => {
                    if (event.target !== picker) return;
                    const rect = picker.getBoundingClientRect();
                    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closePicker();
                });
                const form = hidden.closest('form');
                const patient = form.querySelector('[name="resident_id"]');
                const examDate = form.querySelector('[name="exam_date"]');
                const historyStatus = document.getElementById('odontogram_history_status');
                const retry = document.getElementById('odontogram_history_retry');
                let request = 0;
                let loading = false;
                let failed = false;
                async function loadPreviousChart() {
                    const version = ++request;
                    const mode = form.querySelector('[name="patient_mode"]:checked')?.value || 'existing';
                    Object.keys(marks).forEach(tooth => delete marks[tooth]);
                    cleared.clear();
                    sources = {};
                    previousMarks = {};
                    manualTreatments.clear();
                    manualFields.clear();
                    autoValues.clear();
                    careForm.querySelectorAll('[data-care-toggle]').forEach(toggle => {
                        toggle.checked = false;
                        delete toggle.dataset.autoSuggested;
                        toggle.dispatchEvent(new Event('change', {bubbles:true}));
                    });
                    careForm.querySelectorAll('[data-care-fields] input, [data-care-fields] textarea, [data-care-fields] select').forEach(input => input.value = '');
                    selected = null;
                    if (picker.open) picker.close();
                    // Do not carry another patient's generated assessment into this visit.
                    form.elements.findings.value = '';
                    form.elements.recommendation.value = '';
                    form.elements.exam_approved.checked = false;
                    loading = false;
                    failed = false;
                    board.style.pointerEvents = '';
                    retry.classList.add('hidden');
                    refresh();
                    if (mode !== 'existing' || !patient.value) {
                        historyStatus.textContent = 'Select a patient to load their previous tooth colors.';
                        return;
                    }
                    loading = true;
                    board.style.pointerEvents = 'none';
                    historyStatus.textContent = 'Loading tooth colors from examination history?';
                    const controller = new AbortController();
                    const timeout = setTimeout(() => controller.abort(), 10000);
                    try {
                        const url = new URL('DentalDashboard.php', location.href);
                        url.searchParams.set('api', 'latest_odontogram');
                        url.searchParams.set('resident_id', patient.value);
                        url.searchParams.set('exam_date', examDate.value);
                        const response = await fetch(url, {credentials:'same-origin', cache:'no-store', signal:controller.signal});
                        if (!response.ok || response.redirected) throw new Error('Chart unavailable');
                        const data = await response.json();
                        if (version !== request) return;
                        if (!data.success) throw new Error('Chart unavailable');
                        sources = data.sources || {};
                        Object.entries(data.marks || {}).forEach(([tooth, codes]) => {
                            if (/^[1-4][1-8]$/.test(tooth) && Array.isArray(codes) && colors[codes[0]]) marks[tooth] = [codes[0]];
                        });
                        previousMarks = JSON.parse(JSON.stringify(marks));
                        refresh();
                        form.dispatchEvent(new Event('dental:chart-loaded'));
                        historyStatus.textContent = data.record_date
                            ? 'Combined tooth colors from ' + data.record_count + ' past examinations through ' + data.record_date + '. Each tooth uses its latest recorded condition. Click a tooth to see its record date and update it.' + (data.warnings?.length ? ' Previous record has inconsistencies; verify the chart before saving.' : '')
                            : 'No previous examination found. Record the current tooth conditions.';
                    } catch (_) {
                        if (version !== request) return;
                        failed = true;
                        historyStatus.textContent = 'Previous chart could not be loaded. Retry before saving.';
                        retry.classList.remove('hidden');
                    } finally {
                        clearTimeout(timeout);
                        if (version === request) { loading = false; board.style.pointerEvents = ''; }
                    }
                }
                patient.addEventListener('change', loadPreviousChart);
                examDate.addEventListener('change', loadPreviousChart);
                form.querySelectorAll('[name="patient_mode"]').forEach(input => input.addEventListener('change', loadPreviousChart));
                retry.addEventListener('click', loadPreviousChart);
                form.addEventListener('submit', event => {
                    if (loading || failed) { event.preventDefault(); historyStatus.scrollIntoView({block:'center'}); }
                });
                loadPreviousChart();
            })();

            document.querySelectorAll('form').forEach((form) => {
                form.addEventListener('submit', (ev) => {
                    const mode = form.querySelector('input[name="patient_mode"]:checked')?.value || 'existing';
                    if (mode === 'walkin') {
                        const fn = form.querySelector('[name="walkin_first_name"]');
                        const ln = form.querySelector('[name="walkin_last_name"]');
                        if (!fn?.value.trim() || !ln?.value.trim()) {
                            ev.preventDefault();
                            alert('Walk-in patient requires first name and last name.');
                            (fn || ln)?.focus();
                        }
                        return;
                    }
                    const sel = form.querySelector('select[name="resident_id"]');
                    const hid = form.querySelector('input[name="resident_id_hidden"]');
                    if (!sel) return;
                    let v = (sel.value || '').trim();
                    if ((!v || v === '0') && sel.selectedIndex > 0) {
                        const opt = sel.options[sel.selectedIndex];
                        v = (opt.getAttribute('value') || opt.value || '').trim();
                        sel.value = v;
                    }
                    if (hid) hid.value = v;
                    if (!v || v === '0') {
                        ev.preventDefault();
                        alert('Select a registered patient, or choose Walk-in (new) to register a walk-in patient.');
                        sel.focus();
                    }
                });
            });
        })();
    </script>
<?php if (in_array($tab, ['queue', 'overview'], true) && $modal === ''): ?>
<script>
(() => {
    let busy = false;
    let editing = false;
    const main = document.querySelector('main');
    main.addEventListener('input', () => { editing = true; });
    main.addEventListener('change', () => { editing = true; });
    async function refreshAppointments() {
        if (busy || editing || document.hidden || main.querySelector('details[open]') || main.contains(document.activeElement) && document.activeElement.matches('input,select,textarea')) return;
        busy = true;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(location.href, {credentials:'same-origin', cache:'no-store', signal:controller.signal});
            if (!response.ok || response.redirected) return;
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const incoming = page.querySelector('main');
            if (!incoming || !page.querySelector('.rhu-dental-ui') || editing) return;
            const scroll = [...main.querySelectorAll('.dental-table-scroll')].map(node => [node.scrollTop,node.scrollLeft]);
            const mainTop = main.scrollTop;
            if (incoming.innerHTML !== main.innerHTML) {
                main.innerHTML = incoming.innerHTML;
                main.querySelectorAll('.dental-table-scroll').forEach((node,index) => {
                    node.scrollTop = scroll[index]?.[0] || 0;
                    node.scrollLeft = scroll[index]?.[1] || 0;
                });
                main.scrollTop = mainTop;
            }
        } catch (_) {
            // Keep the current queue available if the connection is interrupted.
        } finally { clearTimeout(timer); busy = false; }
    }
    setInterval(refreshAppointments, 5000);
    addEventListener('focus', refreshAppointments);
    document.addEventListener('visibilitychange', refreshAppointments);
})();
</script>
<?php endif; ?>
<script src="assets/js/staff-qr-modal.js?v=3" defer></script>
<script src="assets/js/staff-qr-appointment.js?v=4" defer></script>
<script src="assets/js/resident-select-search.js?v=4" defer></script>
<link rel="stylesheet" href="styles/staff-record-scroll.css?v=2">
<style data-record-scroll-critical>
@media screen {
[data-staff-record-scroll], .staff-record-scroll {
  display: block; box-sizing: border-box; min-height: 0 !important;
  max-height: 420px !important; max-height: min(45vh, 420px) !important;
  overflow-y: auto !important; overflow-x: auto !important;
  overscroll-behavior: contain; scrollbar-gutter: stable;
}
[data-staff-record-scroll]::-webkit-scrollbar, .staff-record-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
[data-staff-record-scroll]::-webkit-scrollbar-thumb, .staff-record-scroll::-webkit-scrollbar-thumb { background: #8cb5a5; border: 2px solid #f1f7f4; border-radius: 10px; }
}
@media print { [data-staff-record-scroll], .staff-record-scroll { max-height: none !important; overflow: visible !important; } }
</style>

<script src="assets/js/staff-record-scroll.js?v=4" defer></script>
<script src="assets/js/staff-resident-record-groups.js?v=2" defer></script>
<?php if ($pdo instanceof PDO): ?>
<?= rhuIntakeSavedData($pdo) ?>
<?php endif; ?>
</body>

</html>
