<?php
if (is_file(__DIR__ . '/includes/error_bootstrap.php')) {
    require_once __DIR__ . '/includes/error_bootstrap.php';
}

if (isset($_GET['health']) && (string)$_GET['health'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'MidwifeDashboard health OK';
    exit;
}

if (session_status() === PHP_SESSION_NONE)
    session_start();
$stType = strtoupper((string) ($_SESSION['rhu_staff_login']['staff_type'] ?? ''));
if (empty($_SESSION['rhu_staff_login']) || ($stType !== 'MIDWIFE' && strpos($stType, 'MIDWIFE') === false)) {
    header('Location: RHULogin.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/rhu-patient-intake.php';
require_once __DIR__ . '/includes/family-planning-follow-up.php';
if ($pdo instanceof PDO) rhuIntakeEnsure($pdo);
require_once __DIR__ . '/includes/patient-identifiers.php';
$pdo = isset($pdo) ? $pdo : null;
if (is_file(__DIR__ . '/includes/portal.php')) {
    require_once __DIR__ . '/includes/portal.php';
}
require_once __DIR__ . '/includes/appointment-email-reminders.php';
require_once __DIR__ . '/includes/fhsis-quality.php';
require_once __DIR__ . '/includes/family-planning-client-type.php';

if (!function_exists('rhuTableExists')) {
    function rhuTableExists($pdo, $table)
    {
        static $cache = [];
        if (!$pdo || $table === '' || (function_exists('rhuIsApprovedTable') && !rhuIsApprovedTable($table))) return false;
        if (!array_key_exists($table, $cache)) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
                $stmt->execute(['table_name' => $table]);
                $cache[$table] = (bool)$stmt->fetchColumn();
            } catch (Exception $e) {
                $cache[$table] = false;
            }
        }
        return $cache[$table];
    }

    function rhuColumnExists($pdo, $table, $column)
    {
        static $cache = [];
        if (!$pdo || $table === '' || $column === '') return false;
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
                $stmt->execute(['column_name' => $column]);
                $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }

    function rhuResidentListSql()
    {
        return "SELECT r.id, CONCAT(r.first_name, ' ', r.last_name) AS name, COALESCE(b.name, 'Unassigned') AS barangay
                FROM residents r LEFT JOIN barangays b ON b.id = r.barangay_id";
    }
}

if (!function_exists('portalHandleNotificationApi')) {
    function portalHandleNotificationApi($pdo) {}
    function portalRenderNotificationButton() { return ''; }
    function portalRenderNotificationPanel() { return ''; }

    function portalNumericValue($value)
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float)$value;
        if (preg_match('/-?\d+(?:\.\d+)?/', (string)$value, $match)) return (float)$match[0];
        return null;
    }

    function portalSaveHealthRecordEntry($pdo, $residentId, $data = [])
    {
        if (!$pdo || $residentId <= 0 || !rhuTableExists($pdo, 'health_records')) return null;
        $remarksParts = [];
        foreach (['record_type', 'chief_complaint', 'diagnosis', 'treatment', 'notes', 'status', 'follow_up_date'] as $key) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') $remarksParts[] = ucwords(str_replace('_', ' ', $key)) . ': ' . $value;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO health_records
                (resident_id, blood_type, height_cm, weight_kg, blood_pressure, temperature, allergies, medical_conditions, current_medications, immunization_notes, family_history, last_checkup_date, recorded_by_id, remarks, created_at, updated_at)
                VALUES
                (:resident_id, :blood_type, :height_cm, :weight_kg, :blood_pressure, :temperature, :allergies, :medical_conditions, :current_medications, :immunization_notes, :family_history, :last_checkup_date, :recorded_by_id, :remarks, NOW(), NOW())");
            $stmt->execute([
                'resident_id' => $residentId,
                'blood_type' => trim((string)($data['blood_type'] ?? '')) ?: null,
                'height_cm' => portalNumericValue($data['height_cm'] ?? null),
                'weight_kg' => portalNumericValue($data['weight_kg'] ?? $data['weight'] ?? null),
                'blood_pressure' => trim((string)($data['blood_pressure'] ?? $data['bp'] ?? '')) ?: null,
                'temperature' => portalNumericValue($data['temperature'] ?? $data['temp'] ?? null),
                'allergies' => trim((string)($data['allergies'] ?? '')) ?: null,
                'medical_conditions' => trim((string)($data['medical_conditions'] ?? '')) ?: null,
                'current_medications' => trim((string)($data['current_medications'] ?? $data['medications'] ?? '')) ?: null,
                'immunization_notes' => trim((string)($data['immunization_notes'] ?? '')) ?: null,
                'family_history' => trim((string)($data['family_history'] ?? '')) ?: null,
                'last_checkup_date' => trim((string)($data['last_checkup_date'] ?? $data['date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                'recorded_by_id' => !empty($data['recorded_by_id']) ? (int)$data['recorded_by_id'] : null,
                'remarks' => trim(implode("\n", $remarksParts)) ?: null,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            error_log('portalSaveHealthRecordEntry fallback: ' . $e->getMessage());
            return null;
        }
    }

    function portalNotifyResident($pdo, $residentId, $message, $link = null)
    {
        if (!$pdo || $residentId <= 0) return;
        try {
            if (!rhuTableExists($pdo, 'portal_notifications')) {
                if (!rhuTableExists($pdo, 'audit_logs')) return;
                $stmt = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, description, created_at)
                    VALUES ('Resident Notification', 'Resident Portal', :resident_id, :description, NOW())");
                $stmt->execute(['resident_id' => $residentId, 'description' => (string)$message]);
                return;
            }
            $userId = null;
            if (rhuColumnExists($pdo, 'residents', 'user_id')) {
                $lookup = $pdo->prepare('SELECT user_id FROM residents WHERE id = :resident_id LIMIT 1');
                $lookup->execute(['resident_id' => $residentId]);
                $found = $lookup->fetchColumn();
                if ($found) $userId = (int)$found;
            }
            $hasResidentId = rhuColumnExists($pdo, 'portal_notifications', 'resident_id');
            if ($hasResidentId) {
                $stmt = $pdo->prepare('INSERT INTO portal_notifications (resident_id, user_id, audience_role, message, link_url) VALUES (:resident_id, :user_id, NULL, :message, :link_url)');
                $stmt->execute(['resident_id' => $residentId, 'user_id' => $userId, 'message' => $message, 'link_url' => $link]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO portal_notifications (user_id, audience_role, message, link_url) VALUES (:user_id, NULL, :message, :link_url)');
                $stmt->execute(['user_id' => $userId, 'message' => $message, 'link_url' => $link]);
            }
        } catch (Exception $e) {
            error_log('portalNotifyResident fallback: ' . $e->getMessage());
        }
    }

    function portalEnsureCertificateTypes($pdo, $typeNames)
    {
        if (!$pdo || !$typeNames) return [];
        if (!rhuTableExists($pdo, 'certificate_types')) {
            $rows = [];
            $id = 1;
            foreach ($typeNames as $name) {
                $rows[] = ['id' => $id++, 'certificate_type_name' => $name];
            }
            return $rows;
        }
        try {
            $insert = $pdo->prepare('INSERT IGNORE INTO certificate_types (certificate_type_name, description, requirements, fee) VALUES (:name, :description, :requirements, 0)');
            foreach ($typeNames as $name) {
                $name = trim((string)$name);
                if ($name !== '') {
                    $insert->execute(['name' => $name, 'description' => 'Issued by an authorized RHU healthcare professional.', 'requirements' => 'Verified resident record']);
                }
            }
            $placeholders = implode(',', array_fill(0, count($typeNames), '?'));
            $select = $pdo->prepare("SELECT id, certificate_type_name FROM certificate_types WHERE certificate_type_name IN ({$placeholders}) ORDER BY certificate_type_name");
            $select->execute($typeNames);
            return $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    function portalIssueResidentCertificate($pdo, $input, $staffId, $issuerRole)
    {
        if (!$pdo || !rhuTableExists($pdo, 'certificates')) {
            throw new RuntimeException('Certificate records are not available on this server.');
        }
        $residentId = (int)($input['resident_id'] ?? 0);
        $typeId = (int)($input['certificate_type_id'] ?? 0);
        $purpose = trim((string)($input['purpose'] ?? ''));
        $issueDate = trim((string)($input['issue_date'] ?? date('Y-m-d')));
        $expiryDate = trim((string)($input['expiry_date'] ?? ''));
        if ($residentId <= 0 || $purpose === '') throw new InvalidArgumentException('Resident and purpose are required.');
        $typeName = trim((string)($input['certificate_type'] ?? ''));
        if ($typeName === '' && rhuTableExists($pdo, 'certificate_types') && $typeId > 0) {
            $typeStmt = $pdo->prepare('SELECT certificate_type_name FROM certificate_types WHERE id = :id LIMIT 1');
            $typeStmt->execute(['id' => $typeId]);
            $typeName = (string)($typeStmt->fetchColumn() ?: '');
        }
        if ($typeName === '') $typeName = 'Medical Certificate';
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $issuerRole), 0, 3)) ?: 'RHU';
        $certificateNumber = $prefix . '-' . date('Ymd-His') . '-' . str_pad((string)$residentId, 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT INTO certificates (resident_id, issued_by_id, certificate_number, certificate_type, purpose, issue_date, expiry_date, status, created_at)
            VALUES (:resident_id, :issued_by_id, :certificate_number, :certificate_type, :purpose, :issue_date, :expiry_date, 'Issued', NOW())");
        $stmt->execute([
            'resident_id' => $residentId,
            'issued_by_id' => $staffId > 0 ? $staffId : null,
            'certificate_number' => $certificateNumber,
            'certificate_type' => $typeName,
            'purpose' => $purpose,
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
        ]);
        portalNotifyResident($pdo, $residentId, "{$typeName} {$certificateNumber} was issued by {$issuerRole}.", 'ResidentDashboard.php?tab=certificates');
        return ['type' => $typeName, 'number' => $certificateNumber, 'id' => (int)$pdo->lastInsertId()];
    }
}
portalHandleNotificationApi($pdo);
if ($pdo instanceof PDO && function_exists('rhuSendUpcomingAppointmentEmailReminders')) {
    rhuSendUpcomingAppointmentEmailReminders($pdo);
}

function esc($v)
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}


function iconSvg($name, $class = 'w-5 h-5')
{
    $icons = [
        'menu' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'logout' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/><path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/></svg>',
        'close' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'shield' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function tabUrl($tab, $extra = [])
{
    return '?' . http_build_query(array_merge(['tab' => $tab], $extra));
}

function midwifeColumnExists($pdo, $table, $column)
{
    if (!$pdo || !rhuTableExists($pdo, $table)) {
        return false;
    }
    static $columns = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $columns)) {
        return $columns[$key];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return $columns[$key] = ((int) $stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        return $columns[$key] = false;
    }
}

function midwifeMotherAgeSql($alias = 'r')
{
    return "TIMESTAMPDIFF(YEAR, COALESCE({$alias}.birthdate, {$alias}.date_of_birth), CURDATE()) >= 16";
}

function midwifeResidentFullName(array $resident): string
{
    return trim(implode(' ', array_filter([
        $resident['first_name'] ?? '',
        $resident['middle_name'] ?? '',
        $resident['last_name'] ?? '',
    ])));
}

function midwifeFindMaleHouseholdOwner(PDO $pdo, int $dependentResidentId): array
{
    if ($dependentResidentId <= 0 || !rhuTableExists($pdo, 'audit_logs')) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT a.record_id AS owner_id, a.description, r.*
        FROM audit_logs a
        JOIN residents r ON r.id = a.record_id
        WHERE a.action = 'Resident Dependent'
          AND a.module_name = 'Resident Portal'
          AND a.entity_id = :dependent_id
        ORDER BY a.id DESC
    ");
    $stmt->execute(['dependent_id' => $dependentResidentId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $relationshipData = json_decode((string)($row['description'] ?? ''), true);
        $relationship = strtolower((string)($relationshipData['relationship'] ?? ''));
        $sex = strtolower(trim((string)($row['sex'] ?? $row['gender'] ?? '')));
        $looksSpouse = in_array($relationship, ['spouse', 'wife', 'husband'], true);
        if ($looksSpouse && in_array($sex, ['male', 'm'], true)) {
            return $row;
        }
    }
    return [];
}

function midwifeUpdateResidentColumns(PDO $pdo, int $residentId, array $values): void
{
    if ($residentId <= 0) return;
    $parts = [];
    $params = ['id' => $residentId];
    foreach ($values as $column => $value) {
        if (!midwifeColumnExists($pdo, 'residents', $column)) continue;
        $param = 'v_' . $column;
        $parts[] = "{$column} = :{$param}";
        $params[$param] = $value;
    }
    if (midwifeColumnExists($pdo, 'residents', 'updated_at')) {
        $parts[] = 'updated_at = NOW()';
    }
    if (!$parts) return;
    $stmt = $pdo->prepare('UPDATE residents SET ' . implode(', ', $parts) . ' WHERE id = :id');
    $stmt->execute($params);
}

function midwifeLinkDependent(PDO $pdo, int $primaryResidentId, int $dependentResidentId, array $description): bool
{
    if ($primaryResidentId <= 0 || $dependentResidentId <= 0 || !rhuTableExists($pdo, 'audit_logs')) {
        return false;
    }
    $check = $pdo->prepare("SELECT id FROM audit_logs WHERE action = 'Resident Dependent' AND module_name = 'Resident Portal' AND record_id = :primary_id AND entity_id = :dependent_id LIMIT 1");
    $check->execute(['primary_id' => $primaryResidentId, 'dependent_id' => $dependentResidentId]);
    if ($check->fetchColumn()) {
        return false;
    }
    $link = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, entity_type, entity_id, description, ip_address, user_agent, created_at) VALUES ('Resident Dependent', 'Resident Portal', :primary_id, 'resident', :dependent_id, :description, :ip, :ua, NOW())");
    $link->execute([
        'primary_id' => $primaryResidentId,
        'dependent_id' => $dependentResidentId,
        'description' => json_encode($description, JSON_UNESCAPED_UNICODE),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    return true;
}

$tabs = [
    'overview' => ['Overview', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'maternal' => ['Maternal Health', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 12h.01M15 12h.01"/><path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/><path d="M12 3a9 9 0 1 0 9 9"/></svg>'],
    'fp' => ['Family Planning', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>'],
    'opd' => ['Prenatal OPD', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>'],
    'referrals' => ['Referrals', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>'],
    'vital' => ['Vital Statistics', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>']
];

$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab]))
    $tab = 'overview';

$modal = $_GET['modal'] ?? '';
$flashSuccess = $_SESSION['midwife_flash_success'] ?? '';
$flashError = $_SESSION['midwife_flash_error'] ?? '';
unset($_SESSION['midwife_flash_success'], $_SESSION['midwife_flash_error']);

// The live RHU database is managed by rhu.sql. Do not create compatibility tables here.

$loggedInStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
$eligibleMotherAgeSql = midwifeMotherAgeSql('r');
$midwifeProfile = [
    'id' => $loggedInStaffId,
    'staff_id' => $loggedInStaffId,
    'specialty' => 'Maternal and Newborn Care',
    'cases_assisted' => 0,
    'assigned_facility' => 'Nasugbu RHU I',
];

require_once __DIR__ . '/includes/resident-qr.php';
require_once __DIR__ . '/includes/fhsis-services.php';

// ----------------------------------------------------
// 1. POST FORM HANDLERS FOR MIDWIFERY & PRENATAL CARE
// ----------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($pdo)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'issue_certificate') {
        try {
            $issued = portalIssueResidentCertificate($pdo, $_POST, $loggedInStaffId, 'Rural Health Midwife');
            $_SESSION['midwife_flash_success'] = "{$issued['type']} {$issued['number']} was issued and sent to the Resident.";
        } catch (Exception $e) {
            $_SESSION['midwife_flash_error'] = 'Certificate Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('certificates'));
        exit;
    }

    // Action: Answer / Update Resident Consultation
    if ($action === 'answer_consultation') {
        $cslId = (int) ($_POST['consultation_id'] ?? 0);
        $resId = (int) ($_POST['resident_id'] ?? 0);
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $notes = trim($_POST['consultation_notes'] ?? '');
        $meds = trim($_POST['medications_prescribed'] ?? '');
        $status = trim($_POST['consultation_status'] ?? 'Completed');
        $followUpDate = trim($_POST['follow_up_date'] ?? '');
        if ($status === 'Scheduled' && $followUpDate === '') {
            $followUpDate = date('Y-m-d', strtotime('+7 days'));
        }
        if ($status === 'Scheduled' && $followUpDate !== '') {
            $followUpDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $followUpDate);
            if (!$followUpDateObject || (int)$followUpDateObject->format('N') > 5) {
                $_SESSION['midwife_flash_error'] = 'Choose a weekday when the midwife is available.';
                header('Location: ' . tabUrl('overview'));
                exit;
            }
            $followUpDate = $followUpDateObject->format('Y-m-d');
        }

        if ($cslId > 0 && !empty($pdo)) {
            try {
                $stmt = $pdo->prepare("UPDATE consultations SET diagnosis = :dx, remarks = :remarks, consultation_notes = :clinical_notes, medications_prescribed = :medications, treatment = :treatment, follow_up_date = :follow_up_date, health_worker_id = COALESCE(:provider, health_worker_id), consultation_status = :status, updated_at = NOW() WHERE id = :id");
                $stmt->execute([
                    'dx' => $diagnosis,
                    'remarks' => trim($notes . "\nStatus: {$status}"),
                    'clinical_notes' => trim($notes . "\nStatus: {$status}"),
                    'medications' => $meds,
                    'treatment' => $meds,
                    'follow_up_date' => $status === 'Scheduled' ? ($followUpDate ?: null) : null,
                    'status' => $status,
                    'provider' => $loggedInStaffId ?: null,
                    'id' => $cslId
                ]);
                fhsisSaveInlineServices($pdo, 'consultations', $cslId, $resId, $_POST, $_SESSION);
                portalSaveHealthRecordEntry($pdo, $resId, [
                    'record_type' => 'Prenatal consultation response',
                    'diagnosis' => $diagnosis,
                    'notes' => $notes,
                    'medications' => $meds,
                    'status' => $status,
                    'follow_up_date' => $status === 'Scheduled' ? $followUpDate : '',
                    'recorded_by_id' => $loggedInStaffId ?: null,
                ]);
                if ($resId > 0) {
                    rhuNotifyResidentSchedule($pdo, $resId, 'Maternal Care follow-up', $status === 'Scheduled' ? $followUpDate : '');
                    $returnMessage = $status === 'Scheduled' && $followUpDate !== '' ? " Please return on {$followUpDate}." : '';
                    portalNotifyResident($pdo, $resId, "Your Prenatal & Maternal consultation has been updated by the Midwife. Status: {$status}. Assessment: {$diagnosis}.{$returnMessage}", "ResidentDashboard.php?tab=appointments");
                }
                $_SESSION['midwife_flash_success'] = 'Consultation updated and response sent to resident successfully!';
            } catch (Exception $e) {
                $_SESSION['midwife_flash_error'] = 'Error updating consultation: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('overview'));
        exit;
    }

    // Action: Save New Maternal Pregnancy Case
    if ($action === 'save_maternal') {
        $residentSelection = (string) ($_POST['resident_id'] ?? '');
        $isNewMother = $residentSelection === 'new';
        $residentId = $isNewMother ? 0 : (int) $residentSelection;
        $gravida = (int) ($_POST['gravida'] ?? 1);
        $para = (int) ($_POST['para'] ?? 0);
        $lmp = trim($_POST['lmp'] ?? date('Y-m-d', strtotime('-3 months')));
        $lmpDate = DateTime::createFromFormat('!Y-m-d', $lmp);
        $edc = $lmpDate ? $lmpDate->modify('+280 days')->format('Y-m-d') : '';
        $edcDate = $edc !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $edc) : false;
        $today = new DateTimeImmutable('today');
        $isPastDueDelivery = $edcDate && ($edcDate <= $today || $edcDate->modify('+2 days') <= $today);
        $highRisk = isset($_POST['high_risk']) ? 1 : 0;
        $riskFactors = trim($_POST['risk_factors'] ?? 'Routine Monitoring');
        $status = trim($_POST['pregnancy_status'] ?? 'Active');
        if ($isPastDueDelivery) {
            $status = 'Delivered';
            $riskFactors = trim($riskFactors . "\nMarked as delivered: EDC {$edc} has reached or passed the due date and the mother should be recorded in Vital Statistics.");
        }

        if ($residentId <= 0 && !$isNewMother) {
            $_SESSION['midwife_flash_error'] = 'Please select a valid resident mother.';
        } elseif ($gravida < 1 || $para < 0 || $para > $gravida) {
            $_SESSION['midwife_flash_error'] = 'Enter a valid obstetric history. Para cannot be greater than gravida.';
        } elseif (!$lmpDate || $edc <= $lmp) {
            $_SESSION['midwife_flash_error'] = 'Enter a valid LMP date so the EDC can be calculated automatically.';
        } else {
            try {
                $pdo->beginTransaction();
                if ($isNewMother) {
                    $newFirstName = trim($_POST['new_first_name'] ?? '');
                    $newMiddleName = trim($_POST['new_middle_name'] ?? '');
                    $newLastName = trim($_POST['new_last_name'] ?? '');
                    $newDob = trim($_POST['new_date_of_birth'] ?? '');
                    $newBarangay = trim($_POST['new_barangay'] ?? '');
                    $newAddress = trim($_POST['new_address'] ?? '');
                    $newContact = trim($_POST['new_contact_number'] ?? '');
                    if ($newFirstName === '' || $newLastName === '' || $newDob === '' || $newBarangay === '') {
                        throw new RuntimeException("Complete the new mother's name, birth date, and barangay.");
                    }
                    try {
                        $newMotherAge = (new DateTimeImmutable($newDob))->diff(new DateTimeImmutable('today'))->y;
                    } catch (Exception $e) {
                        throw new RuntimeException("Enter a valid birth date for the new mother.");
                    }
                    if ($newMotherAge < 16) {
                        throw new RuntimeException('Pregnancy records are available only for female residents who are at least 16 years old.');
                    }
                    $duplicateStmt = $pdo->prepare("SELECT id FROM residents
                        WHERE first_name = :first_name AND last_name = :last_name AND birthdate = :dob LIMIT 1");
                    $duplicateStmt->execute(['first_name' => $newFirstName, 'last_name' => $newLastName, 'dob' => $newDob]);
                    $residentId = (int) ($duplicateStmt->fetchColumn() ?: 0);
                    if ($residentId <= 0) {
                        $newResidentStmt = $pdo->prepare("INSERT INTO residents
                            (first_name, middle_name, last_name, birthdate, sex, contact_number, address, barangay_id, status, created_at, updated_at)
                            VALUES (:first_name, :middle_name, :last_name, :dob, 'Female', :contact, :address, :barangay_id, 'Active', NOW(), NOW())");
                        $newResidentStmt->execute([
                            'first_name' => $newFirstName,
                            'middle_name' => $newMiddleName !== '' ? $newMiddleName : null,
                            'last_name' => $newLastName,
                            'dob' => $newDob,
                            'contact' => $newContact !== '' ? $newContact : null,
                            'address' => $newAddress !== '' ? $newAddress : $newBarangay,
                            'barangay_id' => rhuBarangayId($pdo, $newBarangay)
                        ]);
                        $residentId = (int) $pdo->lastInsertId();
                    }
                }
                if ($isNewMother) rhuIntakeSave($pdo, $residentId, $_POST);
                rhuVisitSave($pdo, $residentId, $_POST, 'Prenatal', (int)$loggedInStaffId);
                $motherCheck = $pdo->prepare("SELECT id FROM residents WHERE id = :id AND sex LIKE 'Female%' AND " . midwifeMotherAgeSql('residents') . " LIMIT 1");
                $motherCheck->execute(['id' => $residentId]);
                if (!$motherCheck->fetchColumn()) {
                    throw new RuntimeException('Select a female resident who is at least 16 years old.');
                }

                $pregnancyTable = 'pregnancy_records';
                $activePregnancyId = 0;
                $requestedPregnancyStatus = strtolower($status);
                if (in_array($requestedPregnancyStatus, ['active', 'pregnant', 'ongoing'], true)) {
                    $recentBirthStmt = $pdo->prepare("SELECT event_date FROM vital_statistics
                        WHERE resident_id = :resident
                          AND LOWER(event_type) = 'birth'
                          AND event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
                        ORDER BY event_date DESC, id DESC
                        LIMIT 1");
                    $recentBirthStmt->execute(['resident' => $residentId]);
                    $recentBirthDate = (string) ($recentBirthStmt->fetchColumn() ?: '');
                    if ($recentBirthDate !== '') {
                        $eligibleDate = (new DateTimeImmutable($recentBirthDate))->modify('+7 months')->format('Y-m-d');
                        throw new RuntimeException("This mother has a recorded birth on {$recentBirthDate}. New active pregnancy records are blocked until {$eligibleDate} because the child is still within the first 7 months postpartum.");
                    }
                }
                if (!$isNewMother) {
                    $activePregnancyCheck = $pdo->prepare("SELECT id FROM {$pregnancyTable}
                        WHERE resident_id = :resident
                          AND LOWER(COALESCE(pregnancy_status, 'active')) IN ('active', 'pregnant', 'ongoing')
                        ORDER BY id DESC LIMIT 1");
                    $activePregnancyCheck->execute(['resident' => $residentId]);
                    $activePregnancyId = (int) ($activePregnancyCheck->fetchColumn() ?: 0);
                }
                if ($activePregnancyId > 0) {
                    $stmt = $pdo->prepare("UPDATE pregnancy_records
                        SET gravida = :gravida,
                            para = :para,
                            health_worker_id = :midwife_staff_id,
                            last_menstrual_period = :lmp,
                            expected_delivery_date = :edc,
                            risk_level = :risk_level,
                            pregnancy_status = :st,
                            remarks = :remarks,
                            updated_at = NOW()
                        WHERE id = :id AND resident_id = :res");
                    $stmt->execute([
                        'res' => $residentId,
                        'id' => $activePregnancyId,
                        'gravida' => $gravida,
                        'para' => $para,
                        'midwife_staff_id' => $loggedInStaffId ?: null,
                        'lmp' => $lmp,
                        'edc' => $edc,
                        'risk_level' => $highRisk ? 'High Risk' : 'Normal',
                        'remarks' => $riskFactors !== '' ? $riskFactors : 'Routine Monitoring',
                        'st' => $status
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO pregnancy_records
                        (resident_id, gravida, para, health_worker_id, last_menstrual_period, expected_delivery_date, risk_level, pregnancy_status, remarks, created_at, updated_at)
                        VALUES (:res, :gravida, :para, :midwife_staff_id, :lmp, :edc, :risk_level, :st, :remarks, NOW(), NOW())");
                    $stmt->execute([
                        'res' => $residentId,
                        'gravida' => $gravida,
                        'para' => $para,
                        'midwife_staff_id' => $loggedInStaffId ?: null,
                        'lmp' => $lmp,
                        'edc' => $edc,
                        'risk_level' => $highRisk ? 'High Risk' : 'Normal',
                        'remarks' => $riskFactors !== '' ? $riskFactors : 'Routine Monitoring',
                        'st' => $status
                    ]);
                }
                portalSaveHealthRecordEntry($pdo, $residentId, [
                    'record_type' => 'Maternal pregnancy record',
                    'diagnosis' => $highRisk ? 'High-risk pregnancy' : 'Routine pregnancy monitoring',
                    'medical_conditions' => "Pregnancy status: {$status}; Gravida: {$gravida}; Para: {$para}; LMP: {$lmp}; EDC: {$edc}",
                    'notes' => $riskFactors,
                    'status' => $status,
                    'follow_up_date' => $edc,
                    'recorded_by_id' => $loggedInStaffId ?: null,
                ]);
                $pdo->commit();
                portalNotifyResident($pdo, $residentId, "Your maternal health & prenatal tracking record was updated by the Rural Health Midwife. LMP: {$lmp}. EDC: {$edc}.", "ResidentDashboard.php?tab=records");
                if ($isPastDueDelivery) {
                    $_SESSION['midwife_flash_success'] = "Pregnancy record marked as Delivered because EDC {$edc} is more than 2 days overdue. Please complete the birth record in Vital Statistics.";
                    header('Location: ' . tabUrl('vital', ['modal' => 'new_birth', 'mother_id' => $residentId, 'birth_date' => $edc]));
                    exit;
                }
                $_SESSION['midwife_flash_success'] = $activePregnancyId > 0
                    ? 'Existing maternal prenatal record updated successfully!'
                    : 'New Maternal Prenatal record saved successfully into database!';
            } catch (Exception $e) {
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                $_SESSION['midwife_flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('maternal'));
        exit;
    }

    if ($action === 'save_family_planning') {
        $residentId = (int) ($_POST['resident_id'] ?? 0);
        $method = trim($_POST['contraceptive_method'] ?? '');
        $acceptorType = trim($_POST['acceptor_type'] ?? '');
        $counselingReason = trim($_POST['counseling_reason'] ?? 'Family planning counseling');
        $supplies = trim($_POST['supplies'] ?? '');
        $supplyDate = trim($_POST['last_supply_date'] ?? date('Y-m-d'));
        $nextVisit = trim($_POST['next_visit_date'] ?? '');
        $notes = trim($_POST['clinical_notes'] ?? '');
        $fpOwnTransaction = false;
        try {
            if (!portalVerifyCsrf()) throw new RuntimeException('Your session expired. Reload the form and try again.');
            if ($residentId <= 0 || $method === '')
                throw new RuntimeException('Select a resident and contraceptive method.');
            $supplyDateObj = fhsisDate($supplyDate);
            if (!$supplyDateObj || $supplyDateObj->format('Y-m-d') !== $supplyDate)
                throw new RuntimeException('Enter a valid last supply date.');
            if ($supplyDate > date('Y-m-d')) throw new RuntimeException('A completed family planning service cannot have a future date.');
            $fpEvent = fhsisFpServiceEvent($acceptorType);
            if ($fpEvent !== 'counseling' && !array_key_exists($method,fhsisFpMethods())) throw new RuntimeException('Select the exact method/subtype for a reportable service event.');
            $pdo->beginTransaction(); $fpOwnTransaction = true;
            $fpAppointmentId = max(0, (int)($_POST['consultation_id'] ?? 0));
            if ($fpAppointmentId) {
                $fpBooking = residentQrLoadAppointment($pdo, 'MIDWIFE', $fpAppointmentId, $residentId, true);
                if (!preg_match('/family planning/i', residentQrCategory($fpBooking))) throw new RuntimeException('Select a family planning appointment.');
            }
            $fpResident = $pdo->prepare('SELECT id FROM residents WHERE id=? FOR UPDATE');
            $fpResident->execute([$residentId]);
            if (!$fpResident->fetchColumn()) throw new RuntimeException('The selected resident no longer exists.');
            if ($fpEvent !== 'counseling') {
                $history = $pdo->prepare("SELECT consultation_date, remarks FROM consultations WHERE resident_id=? AND remarks LIKE '%FHSIS_FP_V1:%'");
                $history->execute([$residentId]);
                foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $prior) {
                    $priorEvent = fhsisFpEvent($prior);
                    if (!$priorEvent) continue;
                    if ($priorEvent['event']==='new' && $fpEvent==='new') throw new RuntimeException('A new-acceptor event already exists for this resident. Use counseling/resupply or the correct other-acceptor event.');
                    if (substr($prior['consultation_date'],0,10)===$supplyDate && $priorEvent['event']===$fpEvent && $priorEvent['method']===$method) throw new RuntimeException('This family planning event is already recorded for the resident on this date.');
                }
            }
            // Free text cannot create a structured reporting event.
            $notes = preg_replace('/^FHSIS_FP_V1:.*$/m','',$notes);
            if ($nextVisit === '') {
                $nextVisit = $supplyDateObj->modify('+30 days')->format('Y-m-d');
            }
            $nextVisitObj = fhsisDate($nextVisit);
            if (!$nextVisitObj || $nextVisitObj->format('Y-m-d') !== $nextVisit || $nextVisit <= $supplyDate) throw new RuntimeException('Next visit must be a valid date after the supply date.');
            if (midwifeColumnExists($pdo, 'consultations', 'consultation_status')) {
                $closePrior = $pdo->prepare("UPDATE consultations SET consultation_status='Completed', updated_at=NOW()
                    WHERE resident_id=? AND chief_complaint LIKE 'Family Planning - %'
                    AND consultation_date <= ? AND id <> ?
                    AND LOWER(COALESCE(consultation_status,'')) NOT IN ('cancelled','canceled','no-show','no show')");
                $closePrior->execute([$residentId, $supplyDate, $fpAppointmentId]);
            }
            $chiefComplaint = 'Family Planning - ' . $acceptorType . ': ' . $counselingReason;
            $treatment = $method . ($supplies !== '' ? ' | Supplies/medications: ' . $supplies : '');
            $notes = trim($notes . ($supplies !== '' ? "\nSupplies/medications provided: {$supplies}" : ''));
            $notes = preg_replace('/^FHSIS_FP_V1:.*$/m','',$notes);
            if ($fpEvent !== 'counseling') $notes .= "\nFHSIS_FP_V1:" . json_encode(['event'=>$fpEvent,'method'=>$method,'service_date'=>$supplyDate], JSON_UNESCAPED_SLASHES);
            if ($fpAppointmentId) {
                $stmt = $pdo->prepare("UPDATE consultations SET consultation_date = :service_date, health_worker_id = :provider, chief_complaint = :chief, diagnosis = :diagnosis, treatment = :treatment, follow_up_date = :next_visit, remarks = :notes, updated_at = NOW() WHERE id = :id AND resident_id = :resident");
                $stmt->execute(['service_date'=>$supplyDate, 'provider'=>$loggedInStaffId ?: null, 'chief'=>$chiefComplaint, 'diagnosis'=>'Family Planning Counseling', 'treatment'=>$treatment, 'next_visit'=>$nextVisit ?: null, 'notes'=>$notes, 'id'=>$fpAppointmentId, 'resident'=>$residentId]);
                $fpConsultationId = $fpAppointmentId;
            } else {
            $stmt = $pdo->prepare("INSERT INTO consultations
                (resident_id, health_worker_id, consultation_date, consultation_time, chief_complaint, diagnosis, treatment, follow_up_date, remarks, created_at)
                VALUES (:resident, :provider, :supply, CURTIME(), :chief, :diagnosis, :treatment, :next_visit, :notes, NOW())");
            $stmt->execute([
                'resident' => $residentId,
                'provider' => $loggedInStaffId ?: null,
                'supply' => $supplyDate,
                'chief' => $chiefComplaint,
                'diagnosis' => 'Family Planning Counseling',
                'treatment' => $treatment,
                'next_visit' => $nextVisit !== '' ? $nextVisit : null,
                'notes' => $notes,
            ]);
            $fpConsultationId = (int)$pdo->lastInsertId();
            }
            if (midwifeColumnExists($pdo,'consultations','consultation_status')) {
                $pdo->prepare("UPDATE consultations SET consultation_status='Completed' WHERE id=?")->execute([$fpConsultationId]);
            }
            if (
                rhuTableExists($pdo, 'family_planning_records')
                && midwifeColumnExists($pdo, 'family_planning_records', 'contraceptive_method')
                && midwifeColumnExists($pdo, 'family_planning_records', 'acceptor_type')
                && midwifeColumnExists($pdo, 'family_planning_records', 'last_supply_date')
            ) {
                $fpStmt = $pdo->prepare("INSERT INTO family_planning_records
                    (resident_id, contraceptive_method, acceptor_type, last_supply_date, next_visit_date, status, clinical_notes, healthcare_provider_id, created_at, updated_at)
                    VALUES (:resident, :method, :acceptor_type, :last_supply, :next_visit, 'Active', :notes, :provider, NOW(), NOW())");
                $fpStmt->execute([
                    'resident' => $residentId,
                    'method' => $method,
                    'acceptor_type' => $acceptorType,
                    'last_supply' => $supplyDate,
                    'next_visit' => $nextVisit !== '' ? $nextVisit : null,
                    'notes' => $notes,
                    'provider' => $loggedInStaffId ?: null,
                ]);
            }
            fhsisSaveInlineServices($pdo, 'consultations', $fpConsultationId, $residentId, $_POST, $_SESSION);
            $fpHealthRecordId = portalSaveHealthRecordEntry($pdo, $residentId, [
                'record_type' => 'Family planning counseling',
                'diagnosis' => 'Family Planning Counseling',
                'treatment' => $method,
                'current_medications' => $supplies,
                'notes' => trim("Acceptor type: {$acceptorType}\nReason: {$counselingReason}\n{$notes}"),
                'status' => 'Active',
                'follow_up_date' => $nextVisit,
                'date' => $supplyDate,
                'recorded_by_id' => $loggedInStaffId ?: null,
            ]);
            if (!$fpHealthRecordId) throw new RuntimeException('The resident health record could not be saved. No family planning changes were committed.');
            $pdo->commit(); $fpOwnTransaction = false;
            portalNotifyResident($pdo, $residentId, "Your family planning counseling was recorded. Method: {$method}; reason: {$counselingReason}. Supplies/medications: " . ($supplies ?: 'None recorded') . ". Next visit: " . ($nextVisit ?: 'To be scheduled') . '.', 'ResidentDashboard.php?tab=records');
            rhuNotifyResidentSchedule($pdo, $residentId, 'Family Planning follow-up', $nextVisit);
            $_SESSION['midwife_flash_success'] = 'Family planning client record saved.';
        } catch (Exception $e) {
            if ($fpOwnTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['midwife_flash_error'] = 'Family Planning Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('fp'));
        exit;
    }

    if ($action === 'save_immunization') {
        http_response_code(403);
        exit('Immunization records must be handled by a nurse.');
    }

    if ($action === 'save_birth_record') {
        $motherId = (int) ($_POST['mother_id'] ?? 0);
        $childName = trim($_POST['child_name'] ?? '');
        $birthDate = trim($_POST['date_of_birth'] ?? '');
        try {
            if ($motherId <= 0 || $childName === '' || $birthDate === '')
                throw new RuntimeException('Mother, child name, and birth date are required.');
            $certificateNo = trim($_POST['birth_certificate_number'] ?? '') ?: 'BR-' . date('YmdHis');
            $motherStmt = $pdo->prepare("SELECT * FROM residents WHERE id = :id LIMIT 1");
            $motherStmt->execute(['id' => $motherId]);
            $motherRow = $motherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$motherRow) {
                throw new RuntimeException('Mother resident record was not found.');
            }
            $motherBarangayId = (int)($motherRow['barangay_id'] ?? 1);
            $motherHouseholdOwner = midwifeFindMaleHouseholdOwner($pdo, $motherId);
            $inferredFatherName = $motherHouseholdOwner ? midwifeResidentFullName($motherHouseholdOwner) : '';
            if ($inferredFatherName !== '' && trim((string)($_POST['father_name'] ?? '')) === '') {
                $_POST['father_name'] = $inferredFatherName;
            }
            if ($motherHouseholdOwner) {
                midwifeUpdateResidentColumns($pdo, $motherId, [
                    'civil_status' => 'Married',
                    'marital_status' => 'Married',
                ]);
            }
            $childNameParts = preg_split('/\s+/', $childName, -1, PREG_SPLIT_NO_EMPTY);
            $childFirstName = $childNameParts[0] ?? '';
            $childLastName = $childNameParts[count($childNameParts) - 1] ?? $childFirstName;
            $childMiddleName = trim(implode(' ', array_slice($childNameParts, 1, -1)));

            $stmt = $pdo->prepare("INSERT INTO vital_statistics
                (resident_id, barangay_id, event_type, event_date, full_name, birth_status, status, reference_number, remarks, recorded_by_id, created_at)
                VALUES (:mother, :barangay_id, 'Birth', :birth_date, :child, :gender, 'Recorded', :certificate, :remarks, :attendant, NOW())");
            $stmt->execute([
                'certificate' => $certificateNo,
                'child' => $childName,
                'birth_date' => $birthDate,
                'barangay_id' => $motherBarangayId,
                'mother' => $motherId,
                'gender' => trim($_POST['gender'] ?? ''),
                'remarks' => trim('Place: ' . (trim($_POST['place_of_birth'] ?? '') ?: 'Nasugbu RHU I') . "\nFather: " . trim($_POST['father_name'] ?? '') . "\nWeight: " . trim($_POST['birth_weight_kg'] ?? '') . "\nLength: " . trim($_POST['birth_length_cm'] ?? '')),
                'attendant' => $loggedInStaffId ?: null
            ]);

            $pregnancyTable = rhuTableExists($pdo, 'pregnancies') ? 'pregnancies' : 'pregnancy_records';
            if ($pregnancyTable === 'pregnancies') {
                $closePregnancy = $pdo->prepare("UPDATE pregnancy_records
                    SET pregnancy_status = 'Postpartum',
                        risk_factors = TRIM(CONCAT(COALESCE(risk_factors, ''), '\nDelivery recorded: ', :birth_date, ' | Child: ', :child_name)),
                        updated_at = NOW()
                    WHERE resident_id = :mother
                      AND LOWER(COALESCE(pregnancy_status, 'active')) IN ('active', 'pregnant', 'ongoing')
                ");
            } else {
                $closePregnancy = $pdo->prepare("UPDATE pregnancy_records
                    SET pregnancy_status = 'Postpartum',
                        remarks = TRIM(CONCAT(COALESCE(remarks, ''), '\nDelivery recorded: ', :birth_date, ' | Child: ', :child_name)),
                        updated_at = NOW()
                    WHERE resident_id = :mother
                      AND LOWER(COALESCE(pregnancy_status, 'active')) IN ('active', 'pregnant', 'ongoing')
                ");
            }
            $closePregnancy->execute([
                'birth_date' => $birthDate,
                'child_name' => $childName,
                'mother' => $motherId,
            ]);

            $childResidentId = (int)($pdo->query("SELECT id FROM residents WHERE LOWER(first_name) = LOWER('" . addslashes($childFirstName) . "') AND LOWER(last_name) = LOWER('" . addslashes($childLastName) . "') AND birthdate = '{$birthDate}' LIMIT 1")->fetchColumn() ?: 0);
            if ($childResidentId <= 0) {
                $childColumns = ['first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'address', 'barangay_id', 'status', 'created_at', 'updated_at'];
                $childValues = [':first_name', ':middle_name', ':last_name', ':birthdate', ':sex', ':address', ':barangay_id', "'Active'", 'NOW()', 'NOW()'];
                $childParams = [
                    'first_name' => $childFirstName,
                    'middle_name' => $childMiddleName !== '' ? $childMiddleName : null,
                    'last_name' => $childLastName,
                    'birthdate' => $birthDate,
                    'sex' => trim($_POST['gender'] ?? 'Other') ?: 'Other',
                    'address' => $motherRow['address'] ?? 'Nasugbu, Batangas',
                    'barangay_id' => $motherBarangayId,
                ];
                if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'residents', 'date_of_birth')) {
                    $childColumns[] = 'date_of_birth';
                    $childValues[] = ':date_of_birth';
                    $childParams['date_of_birth'] = $birthDate;
                }
                $childInsert = $pdo->prepare("INSERT INTO residents (" . implode(', ', $childColumns) . ") VALUES (" . implode(', ', $childValues) . ")");
                $childInsert->execute($childParams);
                $childResidentId = (int) $pdo->lastInsertId();
            } else {
                $childUpdateColumns = ['birthdate = :birthdate', 'sex = :sex', 'updated_at = NOW()'];
                $childUpdateParams = [
                    'birthdate' => $birthDate,
                    'sex' => trim($_POST['gender'] ?? 'Other') ?: 'Other',
                    'id' => $childResidentId,
                ];
                if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'residents', 'date_of_birth')) {
                    $childUpdateColumns[] = 'date_of_birth = :date_of_birth';
                    $childUpdateParams['date_of_birth'] = $birthDate;
                }
                $childUpdate = $pdo->prepare("UPDATE residents SET " . implode(', ', $childUpdateColumns) . " WHERE id = :id");
                $childUpdate->execute($childUpdateParams);
            }

            $childLinkCheck = $pdo->prepare("SELECT id FROM audit_logs WHERE action = 'Resident Dependent' AND module_name = 'Resident Portal' AND record_id = :resident_id AND entity_id = :dependent_id LIMIT 1");
            $childLinkCheck->execute(['resident_id' => $motherId, 'dependent_id' => $childResidentId]);
            if (!$childLinkCheck->fetch()) {
                $childLink = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, entity_type, entity_id, description, ip_address, user_agent, created_at) VALUES ('Resident Dependent', 'Resident Portal', :resident_id, 'resident', :dependent_id, :description, :ip, :ua, NOW())");
                $childLink->execute([
                    'resident_id' => $motherId,
                    'dependent_id' => $childResidentId,
                    'description' => json_encode([
                        'relationship' => 'Child',
                        'status' => 'pending',
                        'source' => 'birth_registration',
                        'date_of_birth' => $birthDate,
                        'gender' => trim($_POST['gender'] ?? ''),
                        'birth_certificate_number' => $certificateNo,
                        'place_of_birth' => trim($_POST['place_of_birth'] ?? '') ?: 'Nasugbu RHU I',
                        'birth_weight_kg' => trim($_POST['birth_weight_kg'] ?? ''),
                        'birth_length_cm' => trim($_POST['birth_length_cm'] ?? ''),
                    ], JSON_UNESCAPED_UNICODE),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
            }
            if (!empty($motherHouseholdOwner['owner_id'])) {
                midwifeLinkDependent($pdo, (int)$motherHouseholdOwner['owner_id'], $childResidentId, [
                    'relationship' => 'Child',
                    'status' => 'approved',
                    'source' => 'birth_registration_father_household',
                    'mother_resident_id' => $motherId,
                    'date_of_birth' => $birthDate,
                    'gender' => trim($_POST['gender'] ?? ''),
                    'birth_certificate_number' => $certificateNo,
                    'place_of_birth' => trim($_POST['place_of_birth'] ?? '') ?: 'Nasugbu RHU I',
                    'birth_weight_kg' => trim($_POST['birth_weight_kg'] ?? ''),
                    'birth_length_cm' => trim($_POST['birth_length_cm'] ?? ''),
                ]);
            }

            $motherFullName = trim((string)($motherRow['first_name'] ?? '') . ' ' . (string)($motherRow['last_name'] ?? ''));
            portalSaveHealthRecordEntry($pdo, $motherId, [
                'record_type' => 'Birth and delivery record',
                'diagnosis' => 'Delivery recorded',
                'notes' => "Child: {$childName}\nBirth certificate: {$certificateNo}\nPlace: " . (trim($_POST['place_of_birth'] ?? '') ?: 'Nasugbu RHU I'),
                'status' => 'Recorded',
                'date' => $birthDate,
                'recorded_by_id' => $loggedInStaffId ?: null,
            ]);
            if ($childResidentId > 0) {
                portalSaveHealthRecordEntry($pdo, $childResidentId, [
                    'record_type' => 'Newborn birth record',
                    'diagnosis' => 'Live birth record',
                    'height_cm' => trim($_POST['birth_length_cm'] ?? ''),
                    'weight_kg' => trim($_POST['birth_weight_kg'] ?? ''),
                    'notes' => "Mother: {$motherFullName}\nBirth certificate: {$certificateNo}\nPlace: " . (trim($_POST['place_of_birth'] ?? '') ?: 'Nasugbu RHU I'),
                    'status' => 'Recorded',
                    'date' => $birthDate,
                    'recorded_by_id' => $loggedInStaffId ?: null,
                ]);
            }
            portalNotifyResident($pdo, $motherId, "Birth record {$certificateNo} for {$childName} was registered and the child was automatically linked as your dependent. This record is also visible under your household profile.", 'ResidentDashboard.php?tab=family');
            if ($childResidentId > 0) {
                portalNotifyResident($pdo, $childResidentId, "Your birth record ({$certificateNo}) has been saved and linked to your mother, {$motherFullName}. You can now access your health profile from your own dashboard.", 'ResidentDashboard.php?tab=profile');
            }
            if (!empty($motherHouseholdOwner['owner_id'])) {
                portalNotifyResident($pdo, (int)$motherHouseholdOwner['owner_id'], "Birth record {$certificateNo} for {$childName} was registered and automatically added to your family members.", 'ResidentDashboard.php?tab=family');
            }
            $_SESSION['midwife_flash_success'] = 'Birth and vital-statistics record registered. The child was automatically added as a dependent, and the father was optionally linked.';
        } catch (Exception $e) {
            $_SESSION['midwife_flash_error'] = 'Vital Statistics Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('vital'));
        exit;
    }

    if ($action === 'save_referral') {
        $residentId = (int) ($_POST['resident_id'] ?? 0);
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $facility = trim($_POST['referred_to'] ?? '');
        $reason = trim($_POST['referral_reason'] ?? '');
        try {
            if ($residentId <= 0 || $diagnosis === '' || $facility === '' || $reason === '')
                throw new RuntimeException('Complete all required referral details.');
            $pregnancyTable = rhuTableExists($pdo, 'pregnancies') ? 'pregnancies' : 'pregnancy_records';
            $pregnancyStmt = $pdo->prepare("SELECT id FROM {$pregnancyTable} WHERE resident_id = :resident AND pregnancy_status IN ('Active','Pregnant') ORDER BY id DESC LIMIT 1");
            $pregnancyStmt->execute(['resident' => $residentId]);
            $pregnancyId = $pregnancyStmt->fetchColumn() ?: null;
            $stmt = $pdo->prepare("INSERT INTO consultations
                (resident_id, health_worker_id, consultation_date, consultation_time, chief_complaint, diagnosis, treatment, referral_needed, referral_to, remarks, created_at)
                VALUES (:resident, :provider, CURDATE(), CURTIME(), :chief, :diagnosis, :treatment, 1, :facility, :remarks, NOW())");
            $stmt->execute([
                'resident' => $residentId,
                'provider' => $loggedInStaffId ?: null,
                'chief' => 'Maternal Referral',
                'diagnosis' => $diagnosis,
                'treatment' => 'Referred to ' . $facility,
                'facility' => $facility,
                'remarks' => 'Pregnancy ID: ' . ($pregnancyId ?: 'N/A') . "\nUrgency: " . trim($_POST['urgency'] ?? 'Routine') . "\nReason: " . $reason,
            ]);
            portalSaveHealthRecordEntry($pdo, $residentId, [
                'record_type' => 'Maternal referral',
                'diagnosis' => $diagnosis,
                'treatment' => 'Referred to ' . $facility,
                'notes' => 'Urgency: ' . trim($_POST['urgency'] ?? 'Routine') . "\nReason: " . $reason,
                'status' => 'Referred',
                'recorded_by_id' => $loggedInStaffId ?: null,
            ]);
            portalNotifyResident($pdo, $residentId, "Maternal referral created for {$facility}. Diagnosis: {$diagnosis}.", 'ResidentDashboard.php?tab=records');
            $_SESSION['midwife_flash_success'] = 'Maternal referral created and resident notified.';
        } catch (Exception $e) {
            $_SESSION['midwife_flash_error'] = 'Referral Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('referrals'));
        exit;
    }
}

// ----------------------------------------------------
// 2. LIVE MYSQL DATA HYDRATION FROM DATABASE `rhu`
// ----------------------------------------------------
$maternalCases = [];
$fpClients = [];
$vitalRecords = [];
$referralsList = [];
$prenatalOPDList = [];
$activePrenatalCount = 0;
$highRiskCount = 0;
$postpartumCount = 0;
$fpClientCount = 0;
$maternalHistoryByResident = [];
$maternalCasesByResident = [];
$maternalMotherCards = [];

$allMothersList = [];
$allResidentsList = [];
$midwifeCertificateTypes = [];
$allStaffList = [];

if (!empty($pdo)) {
    try {
        $pregnancyTableForStats = rhuTableExists($pdo, 'pregnancies') ? 'pregnancies' : (rhuTableExists($pdo, 'pregnancy_records') ? 'pregnancy_records' : '');
        if ($pregnancyTableForStats !== '') {
            $statusExpr = "LOWER(COALESCE(p.pregnancy_status, 'active'))";
            $recentBirthExclusion = "NOT EXISTS (
                SELECT 1 FROM vital_statistics vb
                WHERE vb.resident_id = p.resident_id
                  AND LOWER(vb.event_type) = 'birth'
                  AND vb.event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
            )";
            $highRiskExpr = $pregnancyTableForStats === 'pregnancies'
                ? "COALESCE(p.high_risk, 0) = 1"
                : "(LOWER(COALESCE(p.risk_level, '')) LIKE '%high%' OR LOWER(COALESCE(p.remarks, '')) LIKE '%high risk%')";
            try {
                if ($pregnancyTableForStats === 'pregnancies') {
                    $pdo->exec("UPDATE pregnancy_records p
                        SET p.pregnancy_status = 'Postpartum',
                            p.risk_factors = TRIM(CONCAT(COALESCE(p.risk_factors, ''), '\nAuto-updated to Postpartum: recent birth record found.')),
                            p.updated_at = NOW()
                        WHERE (LOWER(COALESCE(p.pregnancy_status, 'active')) IN ('active', 'pregnant', 'ongoing')
                               OR LOWER(COALESCE(p.pregnancy_status, 'active')) LIKE '%active%'
                               OR LOWER(COALESCE(p.pregnancy_status, 'active')) LIKE '%pregnant%')
                          AND EXISTS (
                              SELECT 1 FROM vital_statistics vb
                              WHERE vb.resident_id = p.resident_id
                                AND LOWER(vb.event_type) = 'birth'
                                AND vb.event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
                          )");
                } else {
                    $pdo->exec("UPDATE pregnancy_records p
                        SET p.pregnancy_status = 'Postpartum',
                            p.remarks = TRIM(CONCAT(COALESCE(p.remarks, ''), '\nAuto-updated to Postpartum: recent birth record found.')),
                            p.updated_at = NOW()
                        WHERE (LOWER(COALESCE(p.pregnancy_status, 'active')) IN ('active', 'pregnant', 'ongoing')
                               OR LOWER(COALESCE(p.pregnancy_status, 'active')) LIKE '%active%'
                               OR LOWER(COALESCE(p.pregnancy_status, 'active')) LIKE '%pregnant%')
                          AND EXISTS (
                              SELECT 1 FROM vital_statistics vb
                              WHERE vb.resident_id = p.resident_id
                                AND LOWER(vb.event_type) = 'birth'
                                AND vb.event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
                          )");
                }
            } catch (Exception $recentBirthCleanupError) {
                error_log('Recent birth pregnancy cleanup failed: ' . $recentBirthCleanupError->getMessage());
            }
            $activePrenatalCount = (int) $pdo->query("
                SELECT COUNT(DISTINCT p.resident_id)
                FROM {$pregnancyTableForStats} p
                JOIN residents r ON r.id = p.resident_id
                WHERE r.sex LIKE 'Female%'
                  AND {$eligibleMotherAgeSql}
                  AND {$recentBirthExclusion}
                  AND ({$statusExpr} IN ('active', 'active_prenatal', 'pregnant', 'ongoing')
                       OR {$statusExpr} LIKE '%active%'
                       OR {$statusExpr} LIKE '%pregnant%')
            ")->fetchColumn();
            $highRiskCount = (int) $pdo->query("
                SELECT COUNT(DISTINCT p.resident_id)
                FROM {$pregnancyTableForStats} p
                JOIN residents r ON r.id = p.resident_id
                WHERE r.sex LIKE 'Female%' AND {$eligibleMotherAgeSql} AND {$recentBirthExclusion} AND {$highRiskExpr}
            ")->fetchColumn();
            $postpartumCount = (int) $pdo->query("
                SELECT COUNT(DISTINCT resident_id)
                FROM (
                    SELECT p.resident_id
                    FROM {$pregnancyTableForStats} p
                    JOIN residents r ON r.id = p.resident_id
                    WHERE r.sex LIKE 'Female%'
                      AND {$eligibleMotherAgeSql}
                      AND ({$statusExpr} = 'postpartum'
                           OR {$statusExpr} LIKE '%postpartum%'
                           OR {$statusExpr} LIKE '%post-natal%'
                           OR {$statusExpr} LIKE '%postnatal%')
                    UNION ALL
                    SELECT vs.resident_id
                    FROM vital_statistics vs
                    JOIN residents r ON r.id = vs.resident_id
                    WHERE vs.resident_id > 0
                      AND LOWER(COALESCE(vs.event_type, '')) = 'birth'
                      AND vs.event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
                      AND r.sex LIKE 'Female%'
                      AND {$eligibleMotherAgeSql}
                ) postpartum_stats
            ")->fetchColumn();
        }

        $fpSources = [];
        if (rhuTableExists($pdo, 'family_planning_records')) {
            $fpSources[] = "SELECT fp.resident_id FROM family_planning_records fp JOIN residents r ON r.id = fp.resident_id WHERE {$eligibleMotherAgeSql}";
        }
        if (rhuTableExists($pdo, 'consultations')) {
            $fpSources[] = "SELECT c.resident_id FROM consultations c JOIN residents r ON r.id = c.resident_id WHERE c.chief_complaint LIKE 'Family Planning - %' AND {$eligibleMotherAgeSql}";
        }
        if ($fpSources) {
            $fpClientCount = (int) $pdo->query("SELECT COUNT(DISTINCT resident_id) FROM (" . implode(" UNION ALL ", $fpSources) . ") fp_stats")->fetchColumn();
        }
    } catch (Exception $e) {
        error_log("MidwifeDashboard analytics load error: " . $e->getMessage());
    }

    try {
        // Dropdown options
        $allMothersList = $pdo->query(rhuResidentListSql() . " WHERE r.sex LIKE 'Female%' AND {$eligibleMotherAgeSql} AND " . rhuLivingResidentWhereSql('r') . " ORDER BY r.first_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($allMothersList as &$motherOption) {
            $householdOwner = midwifeFindMaleHouseholdOwner($pdo, (int)($motherOption['id'] ?? 0));
            $motherOption['inferred_father_name'] = $householdOwner ? midwifeResidentFullName($householdOwner) : '';
        }
        unset($motherOption);
        $postpartumLockByResident = [];
        $postpartumLockStmt = $pdo->query("SELECT resident_id, MAX(event_date) AS latest_birth_date
            FROM vital_statistics
            WHERE LOWER(COALESCE(event_type, '')) = 'birth'
            GROUP BY resident_id");
        foreach ($postpartumLockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $residentIdForLock = (int) (isset($row['resident_id']) ? $row['resident_id'] : 0);
            $latestBirthDate = trim((string) (isset($row['latest_birth_date']) ? $row['latest_birth_date'] : ''));
            if ($residentIdForLock > 0 && $latestBirthDate !== '') {
                $latestBirth = DateTimeImmutable::createFromFormat('!Y-m-d', $latestBirthDate);
                $today = new DateTimeImmutable('today');
                $cutoffDate = $today->modify('-7 months');
                if ($latestBirth && $latestBirth > $cutoffDate) {
                    $postpartumLockByResident[$residentIdForLock] = $latestBirthDate;
                }
            }
        }
        $allResidentsList = $pdo->query(rhuResidentListSql() . " WHERE " . rhuLivingResidentWhereSql('r') . " ORDER BY r.first_name, r.last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $midwifeCertificateTypes = portalEnsureCertificateTypes($pdo, ['Prenatal Care Certificate', 'Maternal Health Certificate', 'Birth Attendance Certificate', 'Family Planning Counseling Certificate']);
        $allStaffList = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, position_title as staff_type FROM health_workers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 1. Maternal Pregnancies
        $pregnancyTable = rhuTableExists($pdo, 'pregnancies') ? 'pregnancies' : 'pregnancy_records';
        $pregnancyGravidaSelect = midwifeColumnExists($pdo, $pregnancyTable, 'gravida') ? 'p.gravida' : '1';
        $pregnancyParaSelect = midwifeColumnExists($pdo, $pregnancyTable, 'para') ? 'p.para' : '0';
        $pregnancyRisksSelect = $pregnancyTable === 'pregnancies' ? 'p.risk_factors' : 'p.remarks';
        $pregnancyHighRiskSelect = $pregnancyTable === 'pregnancies' ? 'p.high_risk' : "(LOWER(COALESCE(p.risk_level, '')) LIKE '%high%')";
        $recentBirthExclusion = "NOT EXISTS (
            SELECT 1 FROM vital_statistics vb
            WHERE vb.resident_id = p.resident_id
              AND LOWER(vb.event_type) = 'birth'
              AND vb.event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
        )";
        $pStmt = $pdo->query("
            SELECT p.id,
                   p.resident_id,
                   {$pregnancyGravidaSelect} AS gravida,
                   {$pregnancyParaSelect} AS para,
                   CONCAT(r.first_name, ' ', r.last_name) as name,
                   TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age,
                   r.sex as gender,
                   COALESCE(b.name, 'Unassigned') as barangay,
                   COALESCE(hr.blood_type, 'N/A') as bloodType,
                   p.last_menstrual_period as lmp,
                   p.expected_delivery_date as edc,
                   {$pregnancyRisksSelect} as risks,
                   {$pregnancyHighRiskSelect} as highRisk,
                   p.pregnancy_status as status,
                   DATE_ADD(p.expected_delivery_date, INTERVAL -1 MONTH) as nextVisit
            FROM {$pregnancyTable} p
            JOIN residents r ON p.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_records hr ON hr.resident_id = r.id
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND r.sex LIKE 'Female%'
              AND {$eligibleMotherAgeSql}
              AND {$recentBirthExclusion}
            ORDER BY p.id DESC
        ");
        $maternalCases = $pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($maternalCases as $case) {
            $residentIdForHistory = (int) ($case['resident_id'] ?? 0);
            if ($residentIdForHistory > 0 && !isset($maternalHistoryByResident[$residentIdForHistory])) {
                $maternalHistoryByResident[$residentIdForHistory] = [
                    'gravida' => max(1, (int) ($case['gravida'] ?? 1)),
                    'para' => max(0, (int) ($case['para'] ?? 0)),
                    'status' => (string) ($case['status'] ?? ''),
                    'lmp' => (string) ($case['lmp'] ?? ''),
                    'edc' => (string) ($case['edc'] ?? ''),
                ];
            }
        }


        if (rhuTableExists($pdo, 'family_planning_records')) {
            $fpClients = $pdo->query("SELECT fp.id, fp.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS name,
                TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age, COALESCE(b.name, 'Unassigned') barangay,
                fp.contraceptive_method AS method, fp.acceptor_type AS acceptorType,
                fp.last_supply_date AS lastSupply, fp.next_visit_date AS nextVisit,
                CASE WHEN LOWER(COALESCE(fp.status,'')) IN ('cancelled','canceled') THEN fp.status
                    WHEN EXISTS (SELECT 1 FROM family_planning_records newer WHERE newer.resident_id=fp.resident_id
                        AND (newer.last_supply_date > fp.last_supply_date OR (newer.last_supply_date=fp.last_supply_date AND newer.id > fp.id))
                        AND LOWER(COALESCE(newer.status,'')) NOT IN ('cancelled','canceled')) THEN 'Completed'
                    WHEN fp.next_visit_date < CURDATE() THEN 'Overdue'
                    WHEN fp.next_visit_date IS NOT NULL THEN 'Scheduled' ELSE 'Completed' END AS status
                FROM family_planning_records fp
                JOIN residents r ON r.id = fp.resident_id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                WHERE " . rhuLivingResidentWhereSql('r') . "
                AND {$eligibleMotherAgeSql}
                ORDER BY fp.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (empty($fpClients)) {
            $fpLaterVisit = fpLaterVisitSql('c');
            $fpClients = $pdo->query("SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS name,
                TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age, COALESCE(b.name, 'Unassigned') barangay,
                c.treatment AS method, c.chief_complaint AS acceptorType,
                c.consultation_date AS lastSupply, c.follow_up_date AS nextVisit,
                CASE WHEN LOWER(COALESCE(c.consultation_status,'')) IN ('cancelled','canceled') THEN c.consultation_status
                    WHEN {$fpLaterVisit} THEN 'Completed'
                    WHEN c.follow_up_date < CURDATE() THEN 'Overdue'
                    WHEN c.follow_up_date IS NOT NULL THEN 'Scheduled' ELSE 'Completed' END AS status
                FROM consultations c JOIN residents r ON r.id = c.resident_id LEFT JOIN barangays b ON b.id = r.barangay_id
                WHERE " . rhuLivingResidentWhereSql('r') . "
                  AND c.chief_complaint LIKE 'Family Planning - %'
                  AND {$eligibleMotherAgeSql}
                ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $vitalRecords = $pdo->query("SELECT vs.id, vs.resident_id, vs.full_name AS name, CONCAT(r.first_name, ' ', r.last_name) AS motherName,
            vs.event_date AS date, COALESCE(b.name, 'Unassigned') barangay, vs.remarks AS weight,
            COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'RHU Midwife') AS attendant,
            vs.status AS registrationStatus, vs.reference_number AS lncrn
            FROM vital_statistics vs LEFT JOIN residents r ON r.id = vs.resident_id
            LEFT JOIN barangays b ON b.id = vs.barangay_id
            LEFT JOIN health_workers hw ON hw.id = vs.recorded_by_id
            WHERE vs.event_type = 'Birth'
            ORDER BY vs.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $vitalRecords = rhuUniqueBirthDisplay($vitalRecords);

        if ($loggedInStaffId > 0 && rhuTableExists($pdo, 'maternal_referrals') && rhuColumnExists($pdo, 'maternal_referrals', 'referred_by_id')) {
            $maternalReferralDateSelect = rhuColumnExists($pdo, 'maternal_referrals', 'referral_date') ? 'COALESCE(mr.referral_date, DATE(mr.created_at))' : 'DATE(mr.created_at)';
            $maternalReferralWorkerJoin = rhuColumnExists($pdo, 'maternal_referrals', 'referred_by_id') ? 'LEFT JOIN health_workers hw ON hw.id = mr.referred_by_id' : 'LEFT JOIN health_workers hw ON 1 = 0';
            $maternalReferralStmt = $pdo->prepare("SELECT CONCAT('MR-', mr.id) AS displayId, mr.id, mr.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName,
                TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age, r.sex AS gender, COALESCE(b.name, 'Unassigned') AS barangay,
                mr.diagnosis, mr.referred_to AS referredTo, mr.referral_reason AS reason, mr.urgency,
                COALESCE(mr.referral_status, 'Pending') AS status, {$maternalReferralDateSelect} AS referralDate,
                COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'RHU Midwife') AS referredBy, 'maternal_referrals' AS sourceTable
                FROM maternal_referrals mr
                JOIN residents r ON r.id = mr.resident_id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                {$maternalReferralWorkerJoin}
                WHERE " . rhuLivingResidentWhereSql('r') . "
                AND r.sex LIKE 'Female%' AND {$eligibleMotherAgeSql}
                AND mr.referred_by_id = :staff_id
                ORDER BY mr.id DESC");
            $maternalReferralStmt->execute(['staff_id' => $loggedInStaffId]);
            $referralsList = $maternalReferralStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $consultationReferralStmt = $pdo->prepare("SELECT CONCAT('C-', c.id) AS displayId, c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName,
            TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age, r.sex AS gender, COALESCE(b.name, 'Unassigned') AS barangay,
            c.diagnosis, c.referral_to AS referredTo,
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.remarks, ''), 'Reason:', -1), '\n', 1)) AS reason,
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.remarks, ''), 'Urgency:', -1), '\n', 1)) AS urgency,
            CASE WHEN c.referral_needed = 1 THEN 'Referred' ELSE COALESCE(c.consultation_status, 'Pending') END AS status,
            c.consultation_date AS referralDate,
            COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'RHU Midwife') AS referredBy, 'consultations' AS sourceTable
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_workers hw ON hw.id = c.health_worker_id
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND r.sex LIKE 'Female%' AND {$eligibleMotherAgeSql}
              AND (c.chief_complaint LIKE 'Maternal Referral%' OR c.referral_needed = 1)
              AND (c.referral_to IS NOT NULL AND c.referral_to <> '')
              AND c.health_worker_id = :staff_id AND :valid_staff_id > 0
            ORDER BY c.id DESC");
        $consultationReferralStmt->execute(['staff_id' => $loggedInStaffId, 'valid_staff_id' => $loggedInStaffId]);
        $consultationReferralRows = $consultationReferralStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($consultationReferralRows as &$consultationReferralRow) {
            if ($consultationReferralRow['urgency'] === '' || $consultationReferralRow['urgency'] === ($consultationReferralRow['reason'] ?? '')) {
                $consultationReferralRow['urgency'] = 'Routine';
            }
            if ($consultationReferralRow['reason'] === '') {
                $consultationReferralRow['reason'] = 'See clinical diagnosis and remarks.';
            }
        }
        unset($consultationReferralRow);
        $referralsList = array_merge($referralsList, $consultationReferralRows);
        usort($referralsList, static function ($a, $b) {
            return strcmp((string)($b['referralDate'] ?? ''), (string)($a['referralDate'] ?? ''));
        });

        // 3. Prenatal OPD Consultations (Filtered by assigned staff)
        $midwifeStaffId = $loggedInStaffId;
        $midwifeUserId = (int) ($_SESSION['rhu_staff_login']['id'] ?? 0);
        $prenatalNotesSelect = 'COALESCE(c.consultation_notes, c.remarks)';
        $prenatalStatusSelect = "COALESCE(c.consultation_status, CASE WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' AND c.diagnosis <> 'Pending OPD Triage' THEN 'Completed' ELSE 'In Progress' END)";
        $midwifeAppointmentTextScope = "LOWER(CONCAT_WS(' ', COALESCE(c.chief_complaint, ''), COALESCE({$prenatalNotesSelect}, '')))";
        $midwifeAppointmentScope = "({$midwifeAppointmentTextScope} REGEXP 'midwife|prenatal|maternal|pregnan|ob|lying-in|lying in|antenatal' AND {$midwifeAppointmentTextScope} NOT REGEXP 'vaccination|immunization|vaccine')";
        $residentPortalMidwifeAppointmentScope = "({$prenatalNotesSelect} LIKE '%Requested via Resident Portal%' AND {$prenatalStatusSelect} NOT IN ('Completed', 'Cancelled', 'No-show') AND {$midwifeAppointmentScope})";

        if ($midwifeStaffId > 0) {
            $opdStmt = $pdo->prepare("
                SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age, r.sex as gender, c.chief_complaint as chiefComplaint, c.diagnosis, '' as icd10, c.medications_prescribed as medications, COALESCE(b.name, 'Unassigned') as barangay, c.consultation_date as date, c.follow_up_date, 0 as referral_needed, '' as referral_to, {$prenatalNotesSelect} as consultation_notes, {$prenatalStatusSelect} AS consultation_status
                FROM consultations c
                JOIN residents r ON c.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                WHERE (
                    c.health_worker_id = :sid_hw
                    OR c.physician_id = :sid_phy
                    OR {$residentPortalMidwifeAppointmentScope}
                  )
                  AND c.chief_complaint NOT LIKE 'Maternal Referral%'
                  AND {$midwifeAppointmentTextScope} NOT REGEXP 'vaccination|immunization|vaccine'
                  AND c.chief_complaint NOT LIKE 'Family Planning - %'
                  AND r.sex LIKE 'Female%'
                  AND {$eligibleMotherAgeSql}
                ORDER BY c.id DESC
            ");
            $opdStmt->execute(['sid_hw' => $midwifeStaffId, 'sid_phy' => $midwifeStaffId]);
        } else {
            $opdStmt = $pdo->query("
                SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age, r.sex as gender, c.chief_complaint as chiefComplaint, c.diagnosis, '' as icd10, c.medications_prescribed as medications, COALESCE(b.name, 'Unassigned') as barangay, c.consultation_date as date, c.follow_up_date, 0 as referral_needed, '' as referral_to, {$prenatalNotesSelect} as consultation_notes, {$prenatalStatusSelect} AS consultation_status
                FROM consultations c
                JOIN residents r ON c.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                WHERE {$midwifeAppointmentScope}
                  AND c.chief_complaint NOT LIKE 'Maternal Referral%'
                  AND c.chief_complaint NOT LIKE 'Family Planning - %'
                  AND r.sex LIKE 'Female%'
                  AND {$eligibleMotherAgeSql}
                ORDER BY c.id DESC
            ");
        }
        $prenatalOPDList = rhuWithoutExpiredAppointments($opdStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $statusExpr = "LOWER(COALESCE(p.pregnancy_status, 'active'))";
        $highRiskExpr = $pregnancyTable === 'pregnancies'
            ? "COALESCE(p.high_risk, 0) = 1"
            : "(LOWER(COALESCE(p.risk_level, '')) LIKE '%high%' OR LOWER(COALESCE(p.remarks, '')) LIKE '%high risk%')";
        $recentBirthExclusion = "NOT EXISTS (
            SELECT 1 FROM vital_statistics vb
            WHERE vb.resident_id = p.resident_id
              AND LOWER(vb.event_type) = 'birth'
              AND vb.event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
        )";
        $activePrenatalCount = (int) $pdo->query("
            SELECT COUNT(DISTINCT p.resident_id)
            FROM {$pregnancyTable} p
            JOIN residents r ON r.id = p.resident_id
            WHERE r.sex LIKE 'Female%'
              AND {$eligibleMotherAgeSql}
              AND {$recentBirthExclusion}
              AND ({$statusExpr} IN ('active', 'active_prenatal', 'pregnant', 'ongoing')
                   OR {$statusExpr} LIKE '%active%'
                   OR {$statusExpr} LIKE '%pregnant%')
        ")->fetchColumn();
        $highRiskCount = (int) $pdo->query("
            SELECT COUNT(DISTINCT p.resident_id)
            FROM {$pregnancyTable} p
            JOIN residents r ON r.id = p.resident_id
            WHERE r.sex LIKE 'Female%' AND {$eligibleMotherAgeSql} AND {$recentBirthExclusion} AND {$highRiskExpr}
        ")->fetchColumn();
        $postpartumCount = (int) $pdo->query("
            SELECT COUNT(DISTINCT resident_id)
            FROM (
                SELECT p.resident_id
                FROM {$pregnancyTable} p
                JOIN residents r ON r.id = p.resident_id
                WHERE r.sex LIKE 'Female%'
                  AND {$eligibleMotherAgeSql}
                  AND ({$statusExpr} = 'postpartum'
                       OR {$statusExpr} LIKE '%postpartum%'
                       OR {$statusExpr} LIKE '%post-natal%'
                       OR {$statusExpr} LIKE '%postnatal%')
                UNION ALL
                SELECT vs.resident_id
                FROM vital_statistics vs
                JOIN residents r ON r.id = vs.resident_id
                WHERE vs.resident_id > 0
                  AND LOWER(COALESCE(vs.event_type, '')) = 'birth'
                  AND vs.event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
                  AND r.sex LIKE 'Female%'
                  AND {$eligibleMotherAgeSql}
            ) postpartum_stats
        ")->fetchColumn();
        $fpClientCount = count(array_unique(array_map(static function ($row) {
            return (int) ($row['resident_id'] ?? 0);
        }, array_filter($fpClients, static function ($row) {
            return (int) ($row['resident_id'] ?? 0) > 0;
        }))));

    } catch (Exception $e) {
        error_log("MidwifeDashboard DB Load Error: " . $e->getMessage());
    }
}

// Fallback calculations when the database count queries are unavailable.
if ($activePrenatalCount === 0 && !empty($maternalCases)) {
    $activePrenatalCount = count(array_filter($maternalCases, function ($m) { return in_array(strtolower((string) ($m['status'] ?? 'active')), ['active', 'active_prenatal', 'pregnant', 'ongoing'], true); }));
}
if ($highRiskCount === 0 && !empty($maternalCases)) {
    $highRiskCount = count(array_filter($maternalCases, function ($m) { return !empty($m['highRisk']); }));
}
if ($postpartumCount === 0 && !empty($maternalCases)) {
    $postpartumCount = count(array_filter($maternalCases, function ($m) { return strpos(strtolower((string) ($m['status'] ?? '')), 'postpartum') !== false; }));
}
if ($fpClientCount === 0 && !empty($fpClients)) {
    $fpClientCount = count(array_unique(array_map(static function ($row) {
        return (int) ($row['resident_id'] ?? 0);
    }, array_filter($fpClients, static function ($row) {
        return (int) ($row['resident_id'] ?? 0) > 0;
    }))));
}

foreach ($maternalCases as $case) {
    $residentId = (int) ($case['resident_id'] ?? 0);
    if ($residentId <= 0) {
        continue;
    }
    $maternalCasesByResident[$residentId][] = $case;
}

foreach ($maternalCasesByResident as $residentId => $cases) {
    $latestCase = $cases[0];
    $maternalMotherCards[$residentId] = [
        'latest' => $latestCase,
        'pregnancies' => $cases,
        'prenatal' => array_values(array_filter($prenatalOPDList, function ($row) use ($residentId) { return (int) ($row['resident_id'] ?? 0) === $residentId; })),
        'family_planning' => array_values(array_filter($fpClients, function ($row) use ($residentId) { return (int) ($row['resident_id'] ?? 0) === $residentId; })),
        'referrals' => array_values(array_filter($referralsList, function ($row) use ($residentId) { return (int) ($row['resident_id'] ?? 0) === $residentId; })),
    ];
}

$defaultMaternalLmp = date('Y-m-d', strtotime('-3 months'));
$defaultMaternalEdc = (new DateTimeImmutable($defaultMaternalLmp))->modify('+280 days')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<head>
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
    <link rel="icon" href="assets/images/favicon.ico?v=20260915" type="image/x-icon">
    <link rel="shortcut icon" href="assets/images/favicon.ico?v=20260915" type="image/x-icon">
    <title>Midwife Portal RHU</title>
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <style>
        html {
            scroll-behavior: auto;
        }

        body.rhu-midwife-ui {
  overflow: hidden;
  background: #f3f6f4;
  color: #0f172a;
  height: 100vh;
  height: 100dvh;
}

        .midwife-sidebar {
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

        .midwife-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.75rem;
            padding: 0.9rem 1rem;
            flex-shrink: 0;
        }

        .midwife-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(0.52);
        }

        .midwife-sidebar-brand .brand-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg, rgba(13, 53, 24, 0.92) 0%, rgba(23, 63, 45, 0.82) 55%, rgba(47, 111, 73, 0.7) 100%);
        }

        .admin-shell-header {
            background: #0b3c35;
            border-bottom: 1px solid rgba(167, 243, 208, .22);
            box-shadow: 0 10px 28px rgba(2, 28, 23, 0.18);
            min-height: 4rem;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .admin-shell-header.is-scrolled {
            box-shadow: 0 14px 32px -18px rgba(15, 23, 42, 0.65);
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #94a3b8;
            padding: 0.85rem 1.1rem 0.35rem;
        }

        .nav-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 0.1rem 0.65rem;
  padding: 0.65rem 0.85rem;
  border-radius: 0.85rem;
  font-size: 0.8125rem;
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
            color: #0b3c35;
            font-weight: 800;
            box-shadow: inset 0 0 0 1px #c6ebd4;
        }

        .nav-item.is-active svg {
            color: #0f766e;
        }

        .midwife-main-wrap {
  position: relative;
  flex: 1;
  min-width: 0;
  min-height: 0;
  height: 100vh;
  height: 100dvh;
  display: flex;
  flex-direction: column;
  background: #f3f6f4;
  isolation: isolate;
  overflow: hidden;
}

        .midwife-main-wrap::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center top;
            opacity: 0.06;
            pointer-events: none;
            z-index: 0;
        }

        .midwife-main-wrap>* {
            position: relative;
            z-index: 1;
        }

        .midwife-main-wrap>.admin-shell-header,
        .midwife-main-wrap>header.admin-shell-header {
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .midwife-main-wrap > main {
  z-index: 1;
  position: relative;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  -webkit-overflow-scrolling: touch;
}

        .dashboard-card {
  background: rgba(255,255,255,0.95);
  transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}

        .dashboard-card:hover {
  border-color: #a7e0bc;
  box-shadow: 0 6px 18px rgba(11, 60, 53, 0.08);
  transform: translateY(-1px);
}

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14) !important;
        }

        .midwife-form-modal,
        .midwife-form-modal *,
        .maternal-case-modal,
        .maternal-case-modal * {
            color-scheme: light;
        }

        .midwife-form-modal {
            position: fixed !important;
            inset: 0 !important;
            display: flex !important;
            align-items: flex-start !important;
            justify-content: center !important;
            z-index: 80 !important;
            padding: 5.25rem 1rem 1rem !important;
            background: rgba(15, 23, 42, 0.22);
            -webkit-backdrop-filter: blur(4px);
            backdrop-filter: blur(4px);
        }

        .midwife-form-modal .midwife-modal-panel,
        .maternal-case-modal .maternal-modal-panel {
            background: rgba(255, 255, 255, 0.98);
            color: #1e293b;
            width: min(100%, 760px);
            max-height: 92vh;
            margin: 0 auto;
            border-radius: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.12), 0 4px 12px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .midwife-form-modal form {
            padding: 1.15rem 1.15rem 1rem !important;
            gap: 0.9rem !important;
        }

        .midwife-form-modal .midwife-modal-panel .px-5.py-4 {
            background: rgba(248, 250, 252, 0.96) !important;
            border-bottom: 1px solid rgba(226, 232, 240, 0.9) !important;
            padding-top: 0.85rem !important;
            padding-bottom: 0.85rem !important;
        }

        .midwife-form-modal .midwife-modal-panel .p-5 {
            padding: 1rem 1.1rem !important;
        }

        .midwife-form-modal .midwife-modal-panel .space-y-4 > * + * {
            margin-top: 0.8rem !important;
        }

        .midwife-form-modal .midwife-modal-panel input,
        .midwife-form-modal .midwife-modal-panel select,
        .midwife-form-modal .midwife-modal-panel textarea,
        .midwife-form-modal .midwife-modal-panel button {
            border-radius: 0.8rem !important;
        }

        .midwife-form-modal .midwife-modal-panel .maternal-info-box,
        .midwife-form-modal .midwife-modal-panel .maternal-risk-box {
            border-radius: 0.9rem !important;
        }

        .midwife-form-modal label,
        .midwife-form-modal .maternal-field-label,
        .maternal-case-modal label,
        .maternal-case-modal .maternal-field-label {
            color: #334155 !important;
        }

        .midwife-form-modal input,
        .midwife-form-modal select,
        .midwife-form-modal textarea,
        .maternal-case-modal input,
        .maternal-case-modal select,
        .maternal-case-modal textarea {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #0f172a !important;
            caret-color: #0f766e;
            box-shadow: inset 0 1px 0 rgba(15, 23, 42, .03);
        }

        .midwife-form-modal select,
        .maternal-case-modal select {
            appearance: auto;
        }

        .midwife-form-modal option,
        .maternal-case-modal option {
            background: #ffffff;
            color: #0f172a;
        }

        .midwife-form-modal input::placeholder,
        .midwife-form-modal textarea::placeholder,
        .maternal-case-modal input::placeholder,
        .maternal-case-modal textarea::placeholder {
            color: #64748b !important;
            opacity: 1;
        }

        .midwife-form-modal input[type="date"]::-webkit-calendar-picker-indicator,
        .midwife-form-modal input[type="time"]::-webkit-calendar-picker-indicator,
        .maternal-case-modal input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: .78;
            filter: none;
        }

        .midwife-form-modal input:focus,
        .midwife-form-modal select:focus,
        .midwife-form-modal textarea:focus,
        .maternal-case-modal input:focus,
        .maternal-case-modal select:focus,
        .maternal-case-modal textarea:focus {
            background: #ffffff !important;
            border-color: #0d9488 !important;
            color: #0f172a !important;
            outline: none;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, .16) !important;
        }

        .maternal-case-modal .maternal-info-box {
            background: #f0fdfa !important;
            border-color: #99f6e4 !important;
            color: #134e4a !important;
        }

        .maternal-case-modal .maternal-info-box p,
        .maternal-case-modal .maternal-info-box label {
            color: #134e4a !important;
        }

        .maternal-case-modal .maternal-risk-box {
            background: #fff1f2 !important;
            border-color: #fecdd3 !important;
        }

        .maternal-case-modal .maternal-risk-box label {
            color: #881337 !important;
        }

        .midwife-form-modal input[type="checkbox"],
        .maternal-case-modal input[type="checkbox"] {
            accent-color: #0d9488;
            background: #ffffff !important;
            border-color: #94a3b8 !important;
        }

        @media (max-width: 1023px) {
            .midwife-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                height: 100vh;
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 40px rgba(15, 23, 42, .18);
            }

            .midwife-sidebar.is-open {
                transform: translateX(0);
            }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                z-index: 50;
                background: rgba(2, 6, 23, .42);
                opacity: 0;
                pointer-events: none;
                transition: opacity .15s ease;
            }

            .sidebar-backdrop.is-open {
                opacity: 1;
                pointer-events: auto;
            }

            body.drawer-open {
                overflow: hidden;
            }
        }

        @media (min-width: 1024px) {
            .sidebar-backdrop {
                display: none !important;
            }

            .midwife-sidebar {
                transform: none !important;
            }
        }

        @media (prefers-reduced-motion: reduce) {
  .dashboard-card:hover { transform: none; }
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
  }
}
    
/* subtle interactive */
a.bg-teal-600:hover,
button.bg-teal-600:hover {
  filter: brightness(1.05);
}
a.bg-teal-600,
button.bg-teal-600,
button.bg-teal-700 {
  transition: background-color .15s ease, filter .15s ease, box-shadow .15s ease;
}
</style>
    <link rel="stylesheet" href="styles/dashboard-enhancements.css">
    <!-- assets/js/dashboard-enhancements.js disabled: reduced motion -->
<script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(__DIR__ . "/staff-session-lock.js") ?>"></script>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
</head>

<body class="rhu-midwife-ui antialiased">
    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>

        <aside id="midwife-sidebar" data-feature-drawer class="midwife-sidebar shrink-0"
            aria-label="Midwife navigation">
            <div class="midwife-sidebar-brand">
                <div class="brand-bg" aria-hidden="true"></div>
                <div class="brand-overlay" aria-hidden="true"></div>
                <div class="relative z-10 flex items-center gap-3">
                    <span
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/30 bg-white shadow-md overflow-hidden">
                        <img src="../../../assets/nasugbu_seal.png" alt="Nasugbu Seal" class="h-10 w-10 object-contain"
                            onerror="this.onerror=null;this.src='assets/images/nasugbu_seal.png';" />
                    </span>
                    <div class="min-w-0 text-white">
                        <p class="text-[14px] font-black leading-tight tracking-tight drop-shadow-sm">RURAL HEALTH UNIT
                        </p>
                        <p class="text-[11px] font-semibold text-white/90 truncate">
                            <?= esc($tabs[$tab][0] ?? 'Overview') ?></p>
                    </div>
                </div>
                <button type="button" data-drawer-close
                    class="absolute top-2.5 right-2.5 z-10 grid h-8 w-8 place-items-center rounded-full border border-white/25 bg-white/10 text-white lg:hidden"
                    aria-label="Close menu"><?= iconSvg('close', 'w-4 h-4') ?></button>
            </div>

            <nav class="flex-1 overflow-y-auto py-2 min-h-0">
                <?php
                $drawerGroups = [
                    'Dashboard' => ['overview'],
                    'Maternal Care' => ['maternal', 'fp', 'opd', 'referrals'],
                    'Child & Vitals' => ['vital'],
                ];
                foreach ($drawerGroups as $groupLabel => $groupTabs):
                    $visible = array_values(array_filter($groupTabs, function ($k) use ($tabs) { return isset($tabs[$k]); }));
                    if (!$visible)
                        continue;
                    ?>
                    <p class="nav-section-label"><?= esc($groupLabel) ?></p>
                    <?php foreach ($visible as $id):
                        [$label, $icon] = $tabs[$id];
                        $active = $tab === $id;
                        ?>
                        <a href="<?= esc(tabUrl($id)) ?>" class="nav-item <?= $active ? 'is-active' : '' ?>">
                            <span class="shrink-0 opacity-90"><?= $icon ?></span>
                            <span class="truncate flex-1"><?= esc($label) ?></span>
                            <?php if ($active): ?><span class="text-teal-700 text-sm font-black">&rarr;</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>

            <div class="border-t border-slate-100 p-3 shrink-0">
                <a href="StaffLogout.php" data-staff-logout
                    class="nav-item text-slate-500 hover:bg-rose-50 hover:text-rose-700">
                    <?= iconSvg('logout', 'w-5 h-5') ?>
                    <span>Log Out</span>
                </a>
            </div>
        </aside>

        <div class="midwife-main-wrap">
            <header class="admin-shell-header dashboard-header sticky top-0 z-50 text-[#f4faf7]">
                <div class="flex h-20 items-center justify-between gap-3 px-4 sm:px-5">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <button type="button" data-drawer-open
                            class="lg:hidden flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/15 text-white/95 hover:bg-white/10"
                            aria-label="Open menu" aria-expanded="false">
                            <?= iconSvg('menu', 'w-4 h-4') ?>
                        </button>
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-6 w-6 items-center justify-center rounded-full border border-[#e8f3d8]/80 bg-[#dfeecb] text-[#0b3b2f]">
                                <?= iconSvg('shield', 'w-3.5 h-3.5') ?>
                            </span>
                            <span class="text-[11px] font-black uppercase tracking-[0.16em] text-[#f5f5f2]">Midwife
                                Panel</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 sm:gap-2.5">
                        <?php if (function_exists('portalRenderNotificationButton')) {
                            echo portalRenderNotificationButton();
                        } ?>
                        <div
                            class="flex items-center gap-2 rounded-full border border-[#dbeadf]/20 bg-white/10 pl-1 pr-2.5 py-1">
                            <div
                                class="flex h-8 w-8 items-center justify-center rounded-full bg-[#dceec4] text-[13px] font-black text-[#0b3b2f]">
                                <?= esc(strtoupper(substr($_SESSION['rhu_staff_login']['name'] ?? 'M', 0, 1))) ?>
                            </div>
                            <div class="hidden sm:block text-left leading-tight pr-1">
                                <p class="text-[12px] font-bold text-white">
                                    <?= esc($_SESSION['rhu_staff_login']['name'] ?? 'Rural Health Midwife') ?></p>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-[#cfe5d8]">Midwife</p>
                            </div>
                        </div>
                        <a href="StaffLogout.php" data-staff-logout
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-white/20 bg-[#f3faf4] px-3 text-xs font-bold text-[#0c3a32] hover:bg-white transition">
                            <?= iconSvg('logout', 'w-3.5 h-3.5') ?>
                            <span class="hidden sm:inline">Log out</span>
                        </a>
                    </div>
                </div>
            </header>
            <div style="display:flex;justify-content:flex-end;padding:10px 24px"><a href="StaffResidentAppointments.php" data-staff-qr-modal style="display:inline-flex;align-items:center;gap:8px;border:1px solid #b8d8ce;border-radius:10px;background:#fff;color:#0f766e;padding:10px 16px;font-size:13px;font-weight:700;text-decoration:none">&#9638; Scan Resident ID</a></div>

            <main class="flex-1 mx-auto w-full max-w-7xl p-4 sm:p-6 space-y-5 pb-6">


                <?php if ($flashSuccess): ?>
                    <div
                        class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 flex items-center gap-2 shadow-sm">
                        <span
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><svg
                                class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6 9 17l-5-5" />
                            </svg></span>
                        <?= esc($flashSuccess); ?>
                    </div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div
                        class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-800 flex items-center gap-2 shadow-sm">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-rose-100 text-rose-700"><svg
                                class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                <path d="M12 9v4" />
                                <path d="M12 17h.01" />
                            </svg></span>
                        <?= esc($flashError); ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'overview'): ?>
                    <div class="space-y-6">
                        <?php if ($highRiskCount > 0): ?>
                            <div
                                class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-rose-700 via-red-700 to-red-800 p-5 sm:p-6 text-white shadow-lg shadow-red-900/20 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                <div class="flex items-start gap-4 relative z-10">
                                    <span
                                        class="w-11 h-11 rounded-2xl bg-white/20 flex items-center justify-center shrink-0 border border-white/30 text-white"><svg
                                            class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path
                                                d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                            <path d="M12 9v4" />
                                            <path d="M12 17h.01" />
                                        </svg></span>
                                    <div>
                                        <p class="font-extrabold text-base sm:text-lg tracking-tight text-white">High-Risk Pregnancy Alert
                                            -
                                            Immediate Follow-Up</p>
                                        <p class="text-xs sm:text-sm text-rose-50 mt-1.5 font-semibold"><?= $highRiskCount; ?>
                                            high-risk expectant mothers identified. Hospital delivery referral &amp; blood donor
                                            standby required.</p>
                                    </div>
                                </div>
                                <a href="<?= esc(tabUrl('maternal')); ?>"
                                    class="relative z-10 text-xs bg-white hover:bg-rose-50 text-red-700 font-bold px-4 py-2.5 rounded-xl shadow-md shrink-0">Review
                                    Cases</a>
                            </div>
                        <?php endif; ?>
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                            <a href="<?= esc(tabUrl('maternal')); ?>"
                                class="dashboard-card group bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                                <div class="flex items-center justify-between"><span
                                        class="w-11 h-11 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center border border-teal-100"><svg
                                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 12h.01" />
                                            <path d="M15 12h.01" />
                                            <path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5" />
                                            <path
                                                d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1" />
                                        </svg></span><span
                                        class="text-[11px] font-semibold text-teal-700 bg-teal-50 px-2.5 py-0.5 rounded-full border border-teal-200">Active</span>
                                </div>
                                <p
                                    class="text-3xl font-extrabold text-slate-800 mt-4 group-hover:text-teal-700 transition-colors">
                                    <?= $activePrenatalCount; ?>
                                </p>
                                <p class="text-xs font-bold text-slate-700 mt-1">Active Prenatal Cases</p>
                                <p class="text-[11px] text-slate-400 font-medium">Ongoing Checkups</p>
                            </a>
                            <a href="<?= esc(tabUrl('maternal')); ?>"
                                class="dashboard-card group bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                                <div class="flex items-center justify-between"><span
                                        class="w-11 h-11 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center border border-rose-100"><svg
                                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path
                                                d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                            <path d="M12 9v4" />
                                            <path d="M12 17h.01" />
                                        </svg></span><span
                                        class="text-[11px] font-semibold text-rose-700 bg-rose-50 px-2.5 py-0.5 rounded-full border border-rose-200">High
                                        Risk</span></div>
                                <p
                                    class="text-3xl font-extrabold text-slate-800 mt-4 group-hover:text-rose-700 transition-colors">
                                    <?= $highRiskCount; ?>
                                </p>
                                <p class="text-xs font-bold text-slate-700 mt-1">High-Risk Mothers</p>
                                <p class="text-[11px] text-slate-400 font-medium">Special Care Required</p>
                            </a>
                            <a href="<?= esc(tabUrl('maternal')); ?>"
                                class="dashboard-card group bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                                <div class="flex items-center justify-between"><span
                                        class="w-11 h-11 rounded-xl bg-sky-50 text-sky-700 flex items-center justify-center border border-sky-100"><svg
                                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path
                                                d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
                                        </svg></span><span
                                        class="text-[11px] font-semibold text-sky-700 bg-sky-50 px-2.5 py-0.5 rounded-full border border-sky-200">Follow-up</span>
                                </div>
                                <p
                                    class="text-3xl font-extrabold text-slate-800 mt-4 group-hover:text-sky-700 transition-colors">
                                    <?= $postpartumCount; ?>
                                </p>
                                <p class="text-xs font-bold text-slate-700 mt-1">Postpartum Mothers</p>
                                <p class="text-[11px] text-slate-400 font-medium">Post-natal Care</p>
                            </a>
                            <a href="<?= esc(tabUrl('fp')); ?>"
                                class="dashboard-card group bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                                <div class="flex items-center justify-between"><span
                                        class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-700 flex items-center justify-center border border-indigo-100"><svg
                                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z" />
                                            <path d="m8.5 8.5 7 7" />
                                        </svg></span><span
                                        class="text-[11px] font-semibold text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-full border border-indigo-200">FP
                                        Registry</span></div>
                                <p
                                    class="text-3xl font-extrabold text-slate-800 mt-4 group-hover:text-indigo-700 transition-colors">
                                    <?= $fpClientCount; ?>
                                </p>
                                <p class="text-xs font-bold text-slate-700 mt-1">Family Planning Clients</p>
                                <p class="text-[11px] text-slate-400 font-medium">Contraceptive Supply</p>
                            </a>
                        </div>
                        <div class="dashboard-card bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                                <div>
                                    <h3 class="font-bold text-slate-800 text-base flex items-center gap-2"><span
                                            class="w-2.5 h-2.5 rounded-full bg-teal-500 animate-pulse"></span> Received
                                        Resident
                                        Prenatal Consultations</h3>
                                    <p class="text-xs text-slate-500 font-medium">Live consultation requests for Midwifery
                                        &amp;
                                        Prenatal Care</p>
                                </div>
                                <a href="<?= esc(tabUrl('maternal', ['modal' => 'new_maternal'])); ?>"
                                    class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition-all flex items-center gap-1.5"><svg
                                        class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M5 12h14" />
                                        <path d="M12 5v14" />
                                    </svg> New Prenatal Case</a>
                            </div>
                            <?php if (empty($prenatalOPDList)): ?>
                                <div class="text-center py-10 bg-slate-50/50 rounded-xl border border-dashed border-slate-200">
                                    <span
                                        class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                            class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect width="8" height="4" x="8" y="2" rx="1" ry="1" />
                                            <path
                                                d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                                            <path d="M12 11h4" />
                                            <path d="M12 16h4" />
                                            <path d="M8 11h.01" />
                                            <path d="M8 16h.01" />
                                        </svg></span>
                                    <p class="text-sm font-semibold text-slate-700">No Prenatal Consultations Assigned Yet</p>
                                    <p class="text-xs text-slate-400 mt-0.5">When expectant mothers book appointments, they will
                                        appear here.</p>
                                </div>
                            <?php else: ?>
                                <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3">
                                    <?php
                                    $qrAppointmentId = max(0, (int)($_GET['qr_appointment'] ?? 0));
                                    $qrPrenatalList = $prenatalOPDList;
                                    if ($qrAppointmentId > 0) usort($qrPrenatalList, static fn($a, $b) => ((int)$b['id'] === $qrAppointmentId) <=> ((int)$a['id'] === $qrAppointmentId));
                                    foreach ($qrPrenatalList as $opd): ?>
                                        <div data-qr-consultation-id="<?= (int) $opd['id']; ?>"
                                            class="bg-gradient-to-r from-slate-50/80 to-white rounded-xl p-4 border border-gray-200/80 hover:border-pink-200 transition-all space-y-3">
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <p class="font-bold text-slate-800 text-sm sm:text-base">
                                                            <?= esc(rhuPatientLabel($opd['patientName'], $opd['resident_id'] ?? 0)); ?>
                                                        </p>
                                                        <span
                                                            class="text-xs font-semibold text-teal-800 bg-teal-50 px-2 py-0.5 rounded-md border border-teal-100"><?= esc($opd['age'] ?? 'N/A'); ?>y
                                                            &middot; <?= esc($opd['gender']); ?></span>
                                                        <span
                                                            class="text-xs font-medium text-slate-600 bg-slate-50 px-2 py-0.5 rounded-md border border-slate-200 inline-flex items-center gap-1"><svg
                                                                class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                                viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round"
                                                                stroke-linejoin="round">
                                                                <path
                                                                    d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" />
                                                                <circle cx="12" cy="10" r="3" />
                                                            </svg> <?= esc($opd['barangay']); ?></span>
                                                    </div>
                                                    <p class="text-xs font-semibold text-slate-700 mt-1">Chief Complaint: <span
                                                            class="text-slate-600 font-normal"><?= esc($opd['chiefComplaint']); ?></span>
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <span
                                                        class="font-mono text-xs bg-teal-50 text-teal-900 font-semibold px-2.5 py-1 rounded-lg border border-teal-200"><?= esc($opd['icd10'] ?: 'Z34.8'); ?></span>
                                                    <p
                                                        class="text-[10px] font-medium text-slate-400 mt-1 inline-flex items-center gap-1">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M8 2v4" />
                                                            <path d="M16 2v4" />
                                                            <rect width="18" height="18" x="3" y="4" rx="2" />
                                                            <path d="M3 10h18" />
                                                        </svg> <?= esc($opd['date']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <div
                                                class="text-xs text-gray-600 font-mono bg-white p-2.5 rounded-xl border border-gray-200/60">
                                                <?= esc($opd['consultation_notes']); ?>
                                            </div>

                                            <!-- MIDWIFE RESPONSE / UPDATE FORM -->
                                            <details class="group border-t border-pink-100 pt-2">
                                                <summary
                                                    class="cursor-pointer text-xs font-bold text-pink-700 hover:text-pink-900 flex items-center justify-between py-1">
                                                    <span>Answer / Update Consultation Response for Resident</span>
                                                    <span
                                                        class="text-[10px] bg-pink-100 text-pink-800 font-extrabold px-2 py-0.5 rounded-md">Status:
                                                        <?= esc($opd['consultation_status']); ?></span>
                                                </summary>
                                                <form method="post"
                                                    class="mt-2 bg-pink-50/50 p-3 rounded-xl border border-pink-200/70 space-y-2.5">
                                                    <input type="hidden" name="action" value="answer_consultation">
                                                    <input type="hidden" name="consultation_id" value="<?= (int) $opd['id']; ?>">
                                                    <input type="hidden" name="resident_id"
                                                        value="<?= (int) ($opd['resident_id'] ?? 0); ?>">

                                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                        <div>
                                                            <label class="block text-[11px] font-bold text-gray-700 mb-0.5">Clinical
                                                                Diagnosis / Assessment</label>
                                                            <input type="text" name="diagnosis"
                                                                value="<?= esc($opd['diagnosis'] ?? ''); ?>"
                                                                placeholder="e.g. Normal Pregnancy 16 weeks AOG"
                                                                class="w-full p-2 border border-gray-300 rounded-lg text-xs outline-none focus:border-pink-500 bg-white"
                                                                required>
                                                        </div>
                                                        <div>
                                                            <label
                                                                class="block text-[11px] font-bold text-gray-700 mb-0.5">Consultation
                                                                Status</label>
                                                            <select name="consultation_status"
                                                                onchange="const f=this.closest('form').querySelector('[data-follow-up-wrapper]'); const d=this.closest('form').querySelector('[name=follow_up_date]'); const s=this.value === 'Scheduled'; f.classList.toggle('hidden', !s); d.required = s;"
                                                                class="w-full p-2 border border-gray-300 rounded-lg text-xs outline-none focus:border-teal-500 bg-white font-bold text-teal-900">
                                                                <option value="Completed" <?= ($opd['consultation_status'] ?? '') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                                <option value="In Progress" <?= ($opd['consultation_status'] ?? '') === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                                <option value="Scheduled" <?= ($opd['consultation_status'] ?? '') === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                                <option value="Referred" <?= ($opd['consultation_status'] ?? '') === 'Referred' ? 'selected' : ''; ?>>Referred to Doctor
                                                                </option>
                                                            </select>
                                                        </div>
                                                        <div data-follow-up-wrapper class="<?= ($opd['consultation_status'] ?? '') === 'Scheduled' ? '' : 'hidden'; ?>">
                                                            <label class="block text-[11px] font-bold text-gray-700 mb-0.5">Suggested Return Date</label>
                                                            <input type="date" name="follow_up_date" min="<?= date('Y-m-d'); ?>" value="<?= esc($opd['follow_up_date'] ?? date('Y-m-d', strtotime('+7 days'))); ?>" <?= ($opd['consultation_status'] ?? '') === 'Scheduled' ? 'required' : ''; ?> onchange="if(this.value && new Date(this.value + 'T00:00:00').getDay() === 0 || this.value && new Date(this.value + 'T00:00:00').getDay() === 6){alert('Choose a weekday when the midwife is available.'); this.value='';}" class="w-full p-2 border border-gray-300 rounded-lg text-xs outline-none focus:border-teal-500 bg-white">
                                                            <p class="mt-0.5 text-[10px] text-gray-500">Required for Scheduled status. Available midwife days: Monday-Friday.</p>
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <label class="block text-[11px] font-bold text-gray-700 mb-0.5">Midwife
                                                            Notes &amp; Advice for Resident</label>
                                                        <textarea name="consultation_notes" rows="2"
                                                            placeholder="Enter prenatal advice, diet recommendations, and next visit schedule..."
                                                            class="w-full p-2 border border-gray-300 rounded-lg text-xs outline-none focus:border-teal-500 bg-white resize-none"><?= esc($opd['consultation_notes'] ?? ''); ?></textarea>
                                                    </div>

                                                    <div>
                                                        <label
                                                            class="block text-[11px] font-bold text-gray-700 mb-0.5">Prescriptions /
                                                            Supplements</label>
                                                        <input type="text" name="medications_prescribed"
                                                            value="<?= esc($opd['medications'] ?? ''); ?>"
                                                            placeholder="e.g. Ferrous Sulfate + Folic Acid 1 tab OD"
                                                            class="w-full p-2 border border-gray-300 rounded-lg text-xs outline-none focus:border-pink-500 bg-white">
                                                    </div>

                                                    <div class="flex justify-end pt-1">
                                                        <button type="submit"
                                                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-extrabold rounded-lg shadow-sm transition-all flex items-center gap-1">
                                                            <span>&check;</span> Save Response &amp; Notify Resident
                                                        </button>
                                                    </div>
                                                </form>
                                            </details>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($modal === 'new_maternal'): ?>
                    <div class="midwife-form-modal fixed inset-0 bg-slate-950/35 flex items-center justify-center z-50 p-3 sm:p-6">
                        <div class="midwife-modal-panel bg-white rounded-[1.2rem] shadow-[0_18px_38px_rgba(15,23,42,0.12)] w-full max-w-[760px] max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between bg-white shrink-0">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-300 bg-slate-50 text-slate-700">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 12h.01" />
                                            <path d="M15 12h.01" />
                                            <path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5" />
                                            <path d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1" />
                                        </svg>
                                    </span>
                                    <div>
                                        <h2 class="text-[1.05rem] font-bold text-slate-800 leading-none">Register New Prenatal Maternal Case</h2>
                                        <p class="text-[0.72rem] text-slate-500 mt-1">Log pregnancy tracking, LMP, and EDC calculation</p>
                                    </div>
                                </div>
                                <a href="<?= esc(tabUrl('maternal')); ?>" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></a>
                            </div>
                            <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post">
                                <input type="hidden" name="action" value="save_maternal">
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Select Expectant Mother *</label>
                                    <select id="maternal-resident-select" name="resident_id" required class="w-full p-3 border border-gray-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                                        <option value="">-- Select Female Resident --</option>
                                        <option value="new">+ Type and register a new mother</option>
                                        <?php foreach ($allMothersList as $m):
                                            $history = $maternalHistoryByResident[(int) $m['id']] ?? ['gravida' => 1, 'para' => 0, 'status' => '', 'lmp' => '', 'edc' => ''];
                                            $lockDate = $postpartumLockByResident[(int) $m['id']] ?? '';
                                        ?>
                                            <option value="<?= esc($m['id']); ?>" data-gravida="<?= (int) $history['gravida']; ?>" data-para="<?= (int) $history['para']; ?>" data-pregnancy-status="<?= esc($history['status']); ?>" data-lmp="<?= esc($history['lmp']); ?>" data-edc="<?= esc($history['edc']); ?>" data-postpartum-lock="<?= $lockDate !== '' ? '1' : '0'; ?>" data-postpartum-date="<?= esc($lockDate); ?>"><?= esc(rhuPatientLabel($m['name'], $m['id'] ?? 0)); ?> (<?= esc($m['barangay']); ?>)<?= $lockDate !== '' ? ' — postpartum lock' : ''; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div id="new-mother-fields" class="maternal-info-box hidden rounded-2xl border border-teal-200 bg-teal-50/60 p-4">
                                    <p class="mb-3 font-extrabold text-teal-900">New Mother Information</p>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        
                                        <?php ob_start(); ?><input name="new_first_name" data-new-mother-required placeholder="First name *" class="rounded-xl border border-gray-300 p-3">
                                        <input name="new_middle_name" placeholder="Middle name (optional)" class="rounded-xl border border-gray-300 p-3">
                                        <input name="new_last_name" data-new-mother-required placeholder="Last name *" class="rounded-xl border border-gray-300 p-3">
                                        <input type="date" name="new_date_of_birth" data-new-mother-required max="<?= date('Y-m-d') ?>" class="rounded-xl border border-gray-300 p-3" aria-label="Date of birth">
                                        <input name="new_barangay" data-new-mother-required placeholder="Barangay *" class="rounded-xl border border-gray-300 p-3">
                                        <input name="new_contact_number" placeholder="Contact number (optional)" class="rounded-xl border border-gray-300 p-3">
                                        <input name="new_address" placeholder="Complete address (optional)" class="rounded-xl border border-gray-300 p-3 sm:col-span-2">
                            <?php $motherIntakeIdentity = ob_get_clean(); ?>
                            <?= rhuIntakeFields(['middle_name'], $motherIntakeIdentity) ?>
                                    </div>
                                    <p class="mt-2 text-[10px] text-teal-700">A resident record will be created and linked automatically to this prenatal case.</p>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div><label class="block font-bold text-slate-700 mb-1">Gravida (Total Pregnancies)</label><input type="number" name="gravida" value="1" min="1" data-maternal-gravida class="w-full p-3 border border-slate-300 rounded-xl text-sm font-bold"></div>
                                    <div><label class="block font-bold text-slate-700 mb-1">Para (Total Deliveries)</label><input type="number" name="para" value="0" min="0" data-maternal-para class="w-full p-3 border border-slate-300 rounded-xl text-sm font-bold"></div>
                                </div>
                                <?= rhuVisitFields() ?>
                    <div data-active-pregnancy-notice class="hidden rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900"></div>
                                <div class="maternal-info-box bg-teal-50/60 p-4 rounded-2xl border border-teal-100 space-y-3">
                                    <p class="font-bold text-teal-900 text-xs">Maternal Timeline &amp; Dates</p>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div><label class="block font-bold text-slate-700 mb-1">Last Menstrual Period (LMP) *</label><input type="date" name="lmp" id="maternal-lmp-date" required value="<?= esc($defaultMaternalLmp); ?>" class="w-full p-2.5 border border-slate-300 rounded-xl text-xs font-mono font-bold text-slate-800"></div>
                                        <div><label class="block font-bold text-slate-700 mb-1">Expected EDC (40 weeks / 9 months) *</label><input type="date" name="edc" id="maternal-edc-date" required readonly value="<?= esc($defaultMaternalEdc); ?>" class="w-full p-2.5 border border-slate-300 rounded-xl text-xs font-mono font-bold text-slate-800"></div>
                                    </div>
                                </div>
                                <div class="maternal-risk-box flex items-center gap-3 p-3.5 bg-rose-50 rounded-2xl border border-rose-200">
                                    <input type="checkbox" name="high_risk" value="1" id="high_risk_chk" class="w-4 h-4 text-rose-600 rounded">
                                    <label for="high_risk_chk" class="font-bold text-rose-900 cursor-pointer text-xs">Classify as HIGH-RISK Pregnancy</label>
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">Risk Factors &amp; Clinical Health Plan</label>
                                    <textarea name="risk_factors" rows="3" class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none outline-none" placeholder="e.g., Gestational hypertension, previous C-section, teenage pregnancy"></textarea>
                                </div>
                                <div class="pt-2">
                                    <button type="submit" data-maternal-submit class="w-full py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 shadow-md shadow-teal-600/20 transition-all">Save Prenatal Maternal Case</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php elseif ($tab === 'maternal'): ?>
                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-slate-800 flex items-center gap-2"><svg
                                        class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 12h.01" />
                                        <path d="M15 12h.01" />
                                        <path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5" />
                                        <path
                                            d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1" />
                                    </svg> Maternal Health &amp; Prenatal Care Registry</h2>
                                <p class="text-xs text-slate-500 font-medium">Pregnancy tracking, EDC calculations, and
                                    high-risk monitoring</p>
                            </div>
                            <a href="<?= esc(tabUrl('maternal', ['modal' => 'new_maternal'])); ?>"
                                class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition-all flex items-center gap-1.5"><svg
                                    class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14" />
                                    <path d="M12 5v14" />
                                </svg> Register New Prenatal Case</a>
                        </div>
                        <?php if (empty($maternalMotherCards)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 12h.01" />
                                        <path d="M15 12h.01" />
                                        <path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5" />
                                        <path
                                            d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1" />
                                    </svg></span>
                                <p class="text-sm font-semibold text-slate-700">No Maternal Prenatal Cases Recorded</p>
                                <p class="text-xs text-slate-400 mt-0.5">Click "Register New Prenatal Case" above to add an
                                    expectant mother.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-4">
                                <?php foreach ($maternalMotherCards as $residentId => $motherCard):
                                    $mom = $motherCard['latest'];
                                    $gpTag = '';
                                    if (isset($mom['gravida']) && isset($mom['para'])) {
                                        $gpTag = ' &middot; G' . esc($mom['gravida']) . 'P' . esc($mom['para']);
                                    } elseif (!empty($mom['risks']) && preg_match('/G\d+P\d+/i', $mom['risks'], $mMatch)) {
                                        $gpTag = ' &middot; ' . esc(strtoupper($mMatch[0]));
                                    }
                                    ?>
                                    <details data-record-search-row
                                        class="dashboard-card bg-white rounded-2xl border border-slate-200 shadow-sm border-l-4 <?= !empty($mom['highRisk']) ? 'border-l-rose-500' : 'border-l-emerald-500'; ?> group overflow-hidden">
                                        <summary class="list-none cursor-pointer p-5 space-y-3">
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <p class="font-bold text-slate-800 text-base inline-flex items-center gap-2">
                                                        <?= esc(rhuPatientLabel($mom['name'], $residentId)); ?>
                                                        <span class="text-xs text-slate-500 font-medium">(<?= esc($mom['age']); ?> y/o<?= $gpTag; ?>)</span>
                                                        <span class="text-[10px] font-bold text-teal-700 bg-teal-50 border border-teal-100 rounded-full px-2 py-0.5"><?= count($motherCard['pregnancies']); ?> pregnancy record<?= count($motherCard['pregnancies']) === 1 ? '' : 's'; ?></span>
                                                    </p>
                                                    <p class="text-xs text-slate-600 font-medium mt-0.5">Barangay:
                                                        <?= esc($mom['barangay']); ?> &middot; Blood Type: <span
                                                            class="text-rose-700 font-bold bg-rose-50 px-2 py-0.5 rounded border border-rose-200"><?= esc($mom['bloodType']); ?></span>
                                                    </p>
                                                    <p class="text-[11px] text-slate-400 mt-0.5">Latest LMP: <?= esc($mom['lmp']); ?> &middot;
                                                        Latest EDC: <strong class="text-slate-800 font-bold"><?= esc($mom['edc']); ?></strong>
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <?php if (!empty($mom['highRisk'])): ?>
                                                        <span class="px-3 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-200 inline-flex items-center gap-1">HIGH RISK</span>
                                                    <?php else: ?>
                                                        <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 inline-flex items-center gap-1">LOW RISK</span>
                                                    <?php endif; ?>
                                                    <p class="text-xs font-bold text-teal-700 mt-1.5">Next Visit:
                                                        <?= esc($mom['nextVisit']); ?>
                                                    </p>
                                                    <p class="text-[10px] font-bold text-slate-400 mt-1 group-open:hidden">Click to view filtered records</p>
                                                    <p class="text-[10px] font-bold text-slate-400 mt-1 hidden group-open:block">Click again to collapse</p>
                                                </div>
                                            </div>
                                            <div class="bg-teal-50/60 p-3.5 rounded-xl border border-teal-100 text-xs">
                                                <p class="font-bold text-teal-900 mb-1">Latest Risk Factors &amp; Clinical Health Plan:</p>
                                                <p class="text-teal-950 font-medium"><?= esc($mom['risks'] ?: 'No risk factors recorded.'); ?></p>
                                            </div>
                                        </summary>
                                        <div class="border-t border-slate-100 p-5 bg-slate-50/70 space-y-4">
                                            <div>
                                                <h3 class="text-xs font-extrabold uppercase tracking-wide text-slate-700 mb-2">Pregnancy History</h3>
                                                <div class="grid gap-2">
                                                    <?php foreach ($motherCard['pregnancies'] as $pregnancy): ?>
                                                        <div class="rounded-xl border border-slate-200 bg-white p-3 text-xs">
                                                            <p class="font-bold text-slate-800"><?= esc($pregnancy['status'] ?: 'Active'); ?> &middot; G<?= esc($pregnancy['gravida'] ?? '1'); ?>P<?= esc($pregnancy['para'] ?? '0'); ?></p>
                                                            <p class="mt-1 text-slate-500">LMP: <?= esc($pregnancy['lmp']); ?> &middot; EDC: <?= esc($pregnancy['edc']); ?> &middot; Next Visit: <?= esc($pregnancy['nextVisit']); ?></p>
                                                            <p class="mt-1 text-slate-600"><?= esc($pregnancy['risks'] ?: 'No risk factors recorded.'); ?></p>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <div class="grid gap-4 lg:grid-cols-3">
                                                <div class="rounded-xl border border-slate-200 bg-white p-3 text-xs">
                                                    <h3 class="font-extrabold text-slate-800 mb-2">Prenatal Consultations</h3>
                                                    <?php if (empty($motherCard['prenatal'])): ?>
                                                        <p class="text-slate-400 font-semibold">No prenatal consultations for this mother.</p>
                                                    <?php else: ?>
                                                        <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-2">
                                                            <?php foreach ($motherCard['prenatal'] as $row): ?>
                                                                <div class="border-t border-slate-100 pt-2 first:border-t-0 first:pt-0">
                                                                    <p class="font-bold text-slate-700"><?= esc($row['date']); ?> &middot; <?= esc($row['consultation_status']); ?></p>
                                                                    <p class="text-slate-500"><?= esc($row['chiefComplaint']); ?></p>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="rounded-xl border border-slate-200 bg-white p-3 text-xs">
                                                    <h3 class="font-extrabold text-slate-800 mb-2">Family Planning</h3>
                                                    <?php if (empty($motherCard['family_planning'])): ?>
                                                        <p class="text-slate-400 font-semibold">No family planning records for this mother.</p>
                                                    <?php else: ?>
                                                        <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-2">
                                                            <?php foreach ($motherCard['family_planning'] as $row): ?>
                                                                <div class="border-t border-slate-100 pt-2 first:border-t-0 first:pt-0">
                                                                    <p class="font-bold text-slate-700"><?= esc($row['method']); ?></p>
                                                                    <p class="text-slate-500">Next visit: <?= esc($row['nextVisit']); ?> &middot; <?= esc($row['status']); ?></p>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="rounded-xl border border-slate-200 bg-white p-3 text-xs">
                                                    <h3 class="font-extrabold text-slate-800 mb-2">Referrals</h3>
                                                    <?php if (empty($motherCard['referrals'])): ?>
                                                        <p class="text-slate-400 font-semibold">No referrals for this mother.</p>
                                                    <?php else: ?>
                                                        <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-2">
                                                            <?php foreach ($motherCard['referrals'] as $row): ?>
                                                                <div class="border-t border-slate-100 pt-2 first:border-t-0 first:pt-0">
                                                                    <p class="font-bold text-slate-700"><?= esc($row['diagnosis']); ?></p>
                                                                    <p class="text-slate-500"><?= esc($row['referredTo']); ?> &middot; <?= esc($row['status']); ?></p>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'fp'): ?>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-slate-800 flex items-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                                    Family Planning &amp; Reproductive Health Registry
                                </h2>
                                <p class="text-xs text-slate-500 font-medium">Contraceptive methods, acceptor tracking, and supply follow-ups</p>
                            </div>
                            <a href="<?= esc(tabUrl('fp', ['modal' => 'new_fp'])); ?>"
                                class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition-all flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Register FP Client
                            </a>
                        </div>

                        <?php if (empty($fpClients)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z" />
                                        <path d="m8.5 8.5 7 7" />
                                    </svg></span>
                                <p class="text-sm font-semibold text-slate-700">No Family Planning Clients Registered</p>
                                <p class="text-xs text-slate-400 mt-0.5">Contraceptive users and acceptors will be logged here.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-card bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs min-w-[650px]">
                                        <thead class="bg-slate-50 text-slate-600 uppercase font-bold border-b border-slate-200">
                                            <tr>
                                                <th class="px-4 py-3.5 text-left">Client Name</th>
                                                <th class="px-4 py-3.5 text-left">Age</th>
                                                <th class="px-4 py-3.5 text-left">Barangay</th>
                                                <th class="px-4 py-3.5 text-left">Method</th>
                                                <th class="px-4 py-3.5 text-left">Acceptor Type</th>
                                                <th class="px-4 py-3.5 text-left">Last Supply</th>
                                                <th class="px-4 py-3.5 text-left">Next Visit</th>
                                                <th class="px-4 py-3.5 text-left">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($fpClients as $fp): ?>
                                                <tr class="hover:bg-teal-50/40 transition-colors">
                                                    <td class="px-4 py-3.5 font-bold text-slate-800"><?= esc(rhuPatientLabel($fp['name'], $fp['resident_id'] ?? 0)); ?></td>
                                                    <td class="px-4 py-3.5 text-slate-700 font-semibold"><?= esc($fp['age']); ?>
                                                    </td>
                                                    <td class="px-4 py-3.5 font-semibold text-slate-700">
                                                        <?= esc($fp['barangay']); ?>
                                                    </td>
                                                    <td class="px-4 py-3.5 text-teal-900 font-bold"><?= esc($fp['method']); ?></td>
                                                    <td class="px-4 py-3.5"><span
                                                            class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-100 text-sky-800"><?= esc($fp['acceptorType']); ?></span>
                                                    </td>
                                                    <td class="px-4 py-3.5 text-slate-500 font-mono"><?= esc($fp['lastSupply']); ?>
                                                    </td>
                                                    <td class="px-4 py-3.5 font-bold text-teal-600 font-mono">
                                                        <?= esc($fp['nextVisit']); ?>
                                                    </td>
                                                    <td class="px-4 py-3.5"><span
                                                            class="px-2.5 py-0.5 rounded-full text-[10px] font-bold <?= strtolower($fp['status']) === 'overdue' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'; ?>"><?= esc($fp['status']); ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>


                <?php if ($tab === 'vital'): ?>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-slate-800 flex items-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                                    Municipal Births &amp; Vital Statistics
                                </h2>
                                <p class="text-xs text-slate-500 font-medium">Register births and update vital records</p>
                            </div>
                            <a href="<?= esc(tabUrl('vital', ['modal' => 'new_birth'])); ?>"
                                class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition-all flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Register Birth
                            </a>
                        </div>

                        <?php if (empty($vitalRecords)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                                        <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                                        <path d="M10 9H8" />
                                        <path d="M16 13H8" />
                                        <path d="M16 17H8" />
                                    </svg></span>
                                <p class="text-sm font-semibold text-slate-700">No Registered Birth Records</p>
                                <p class="text-xs text-slate-400 mt-0.5">Municipal birth registrations will display here.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3">
                                <?php foreach ($vitalRecords as $vr): ?>
                                    <div data-record-search-row data-qr-consultation-id="<?= (int) $opd['id']; ?>"
                                        class="dashboard-card bg-white rounded-2xl p-4 border border-slate-200 shadow-sm flex items-center justify-between">
                                        <div>
                                            <p class="font-bold text-slate-800 text-sm sm:text-base"><?= esc($vr['name']); ?> <span
                                                    class="text-xs text-slate-500 font-medium">(Mother:
                                                    <?= esc(rhuPatientLabel($vr['motherName'], $vr['resident_id'] ?? 0)); ?>)</span></p>
                                            <p class="text-xs text-slate-600 mt-0.5">DOB: <?= esc($vr['date']); ?> &middot; Barangay:
                                                <?= esc($vr['barangay']); ?> &middot; Weight: <?= esc($vr['weight']); ?>
                                            </p>
                                            <p class="text-[11px] text-slate-400 mt-0.5">Birth Attendant:
                                                <?= esc($vr['attendant']); ?>
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <span
                                                class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200"><?= esc($vr['registrationStatus']); ?></span>
                                            <p class="text-xs font-mono text-slate-400 mt-1"><?= esc($vr['lncrn']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'referrals'): ?>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-slate-800 flex items-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>
                                    High-Risk OB Hospital Referrals
                                </h2>
                                <p class="text-xs text-slate-500 font-medium">Create maternal referrals to hospitals and tertiary facilities</p>
                            </div>
                            <a href="<?= esc(tabUrl('referrals', ['modal' => 'new_referral'])); ?>"
                                class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition-all flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create Referral
                            </a>
                        </div>

                        <?php if (empty($referralsList)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 6v4" />
                                        <path d="M14 14h-4" />
                                        <path d="M14 18h-4" />
                                        <path d="M14 8h-4" />
                                        <path d="M18 12h2a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2h2" />
                                        <path d="M18 22V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v18" />
                                    </svg></span>
                                <p class="text-sm font-semibold text-slate-700">No Active OB Hospital Referrals</p>
                                <p class="text-xs text-slate-400 mt-0.5">Emergency and tertiary hospital referral forms will be
                                    listed here.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-4">
                                <?php foreach ($referralsList as $ref): ?>
                                    <div data-record-search-row class="dashboard-card bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
                                        <div class="bg-slate-50 border-b border-slate-200 px-5 py-4 flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Referral Form</p>
                                                <h3 class="text-base font-black text-slate-900">High-Risk OB Hospital Referral</h3>
                                                <p class="text-xs font-semibold text-slate-500">Rural Health Unit Maternal Care Service</p>
                                            </div>
                                            <div class="text-left sm:text-right">
                                                <p class="font-mono text-xs font-black text-teal-700"><?= esc($ref['displayId'] ?? $ref['id']); ?></p>
                                                <p class="text-xs text-slate-500"><?= esc($ref['referralDate'] ?? ''); ?></p>
                                            </div>
                                        </div>
                                        <div class="p-5 space-y-4">
                                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
                                                <div class="sm:col-span-2 border-b border-slate-200 pb-2">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px]">Patient Name</p>
                                                    <p class="font-bold text-slate-900 text-sm"><?= esc(rhuPatientLabel($ref['patientName'], $ref['resident_id'] ?? 0)); ?></p>
                                                </div>
                                                <div class="border-b border-slate-200 pb-2">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px]">Age / Sex</p>
                                                    <p class="font-bold text-slate-800"><?= esc($ref['age']); ?> / <?= esc($ref['gender'] ?? 'Female'); ?></p>
                                                </div>
                                                <div class="border-b border-slate-200 pb-2">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px]">Barangay</p>
                                                    <p class="font-bold text-slate-800"><?= esc($ref['barangay'] ?? 'Unassigned'); ?></p>
                                                </div>
                                                <div class="sm:col-span-2 border-b border-slate-200 pb-2">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px]">Receiving Hospital / Facility</p>
                                                    <p class="font-bold text-indigo-900"><?= esc($ref['referredTo']); ?></p>
                                                </div>
                                                <div class="border-b border-slate-200 pb-2">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px]">Urgency</p>
                                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-900 border border-amber-200"><?= esc($ref['urgency'] ?: 'Routine'); ?></span>
                                                </div>
                                                <div class="border-b border-slate-200 pb-2">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px]">Status</p>
                                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200"><?= esc($ref['status'] ?? 'Pending'); ?></span>
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                                <div class="rounded-lg border border-slate-200 p-3">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px] mb-1">Diagnosis / Impression</p>
                                                    <p class="font-semibold text-slate-800 leading-relaxed"><?= esc($ref['diagnosis']); ?></p>
                                                </div>
                                                <div class="rounded-lg border border-slate-200 p-3">
                                                    <p class="font-black text-slate-400 uppercase tracking-wide text-[10px] mb-1">Reason for Referral</p>
                                                    <p class="font-semibold text-slate-800 leading-relaxed"><?= nl2br(esc($ref['reason'])); ?></p>
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 pt-2 text-xs">
                                                <div class="border-t border-slate-300 pt-2">
                                                    <p class="font-bold text-slate-800"><?= esc($ref['referredBy'] ?? 'RHU Midwife'); ?></p>
                                                    <p class="text-slate-500">Referring Midwife / Health Worker</p>
                                                </div>
                                                <div class="border-t border-slate-300 pt-2">
                                                    <p class="font-bold text-slate-800">Receiving Facility</p>
                                                    <p class="text-slate-500">Acknowledgement / Signature</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'opd'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-lg font-bold text-slate-800 flex items-center gap-2"><svg
                                class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <rect width="8" height="4" x="8" y="2" rx="1" ry="1" />
                                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                                <path d="M12 11h4" />
                                <path d="M12 16h4" />
                                <path d="M8 11h.01" />
                                <path d="M8 16h.01" />
                            </svg> Routine Prenatal OPD Checkups</h2>
                        <?php if (empty($prenatalOPDList)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="8" height="4" x="8" y="2" rx="1" ry="1" />
                                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                                        <path d="M12 11h4" />
                                        <path d="M12 16h4" />
                                        <path d="M8 11h.01" />
                                        <path d="M8 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-semibold text-slate-700">No Routine Prenatal Checkups Found</p>
                                <p class="text-xs text-slate-400 mt-0.5">Routine OPD prenatal notes will be listed here.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3">
                                <?php foreach ($prenatalOPDList as $opd): ?>
                                    <div data-record-search-row
                                        class="dashboard-card bg-white rounded-2xl p-5 border border-slate-200 shadow-sm space-y-2.5">
                                        <div class="flex items-center justify-between">
                                            <p class="font-bold text-slate-800 text-base"><?= esc(rhuPatientLabel($opd['patientName'], $opd['resident_id'] ?? 0)); ?> <span
                                                    class="text-xs font-medium text-slate-500">(<?= esc($opd['age'] ?? 'N/A'); ?>y /
                                                    <?= esc($opd['gender']); ?>)</span></p>
                                            <span
                                                class="font-mono text-xs bg-teal-50 text-teal-900 font-bold px-2.5 py-1 rounded-lg border border-teal-200"><?= esc($opd['icd10'] ?: 'Z34.8'); ?></span>
                                        </div>
                                        <p class="text-xs text-slate-700"><strong>Chief Complaint:</strong>
                                            <?= esc($opd['chiefComplaint']); ?>
                                        </p>
                                        <div
                                            class="text-xs text-slate-600 font-mono bg-slate-50 p-3 rounded-xl border border-slate-200/60">
                                            <?= esc($opd['consultation_notes']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>




            </main>
        </div>
    </div>

    

    <?php
    $qrFpBooking = null;
    if ($modal === 'new_fp' && (int)($_GET['qr_appointment'] ?? 0) > 0) {
        try {
            if (!($pdo instanceof PDO)) {
                throw new RuntimeException('Database connection is unavailable.');
            }
            $qrFpBooking = residentQrLoadAppointment($pdo, 'MIDWIFE', (int)$_GET['qr_appointment'], (int)($_GET['resident_id'] ?? 0));
            if (!preg_match('/family planning/i', residentQrCategory($qrFpBooking))) throw new RuntimeException('This is not a family planning appointment.');
        } catch (Throwable $error) {
            $modal = '';
            echo '<p role="alert" class="p-4 bg-white">' . esc($error->getMessage()) . '</p>';
        }
    }
    ?>
    <?php if ($modal === 'new_fp'): ?>
        <?php
        $fpTypeData = ['available' => false, 'entries' => [], 'methods' => array_keys(fhsisFpMethods())];
        try {
            if ($pdo instanceof PDO) {
                $fpTypeData['entries'] = fpClientTypeHistory($pdo, (int)($qrFpBooking['id'] ?? 0));
                $fpTypeData['available'] = true;
            }
        } catch (Throwable $error) { error_log('Family planning client-type history could not be loaded.'); }
        ?>
        <div class="midwife-form-modal fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div class="midwife-modal-panel bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Register Family Planning Client</h2>
                        <p class="text-xs text-slate-500">Contraceptive method, supply date, and follow-up</p>
                    </div>
                    <a href="<?= esc(tabUrl('fp')); ?>" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></a>
                </div>
                <form data-fp-client-type <?= $qrFpBooking ? 'data-qr-fp-appointment' : '' ?> class="p-5 space-y-4 text-xs overflow-y-auto" method="post">

                    <input type="hidden" name="action" value="save_family_planning">
                    <?php if ($qrFpBooking): ?><input type="hidden" name="consultation_id" value="<?= (int)$qrFpBooking['id'] ?>"><p class="font-bold"><?= esc(rhuPatientLabel($qrFpBooking['resident_name'], $qrFpBooking['resident_id'])) ?> ? Appointment #<?= (int)$qrFpBooking['id'] ?></p><?php endif; ?>
                    <input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Select Resident *</label>
                        <select required name="resident_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">-- Select resident --</option>
                            <?php foreach ($allResidentsList as $r): ?>
                                <option value="<?= (int)$r['id'] ?>"><?= esc(rhuPatientLabel($r['name'], $r['id'] ?? 0)) ?> - <?= esc($r['barangay']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Contraceptive Method *</label>
                            <select required name="contraceptive_method" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select method</option>
                                <?php foreach (array_merge(array_keys(fhsisFpMethods()), ['Oral contraceptive pills (subtype unknown)', 'IUD (timing unknown)', 'Implant (timing unknown)', 'Emergency contraception', 'Natural Family Planning (method unknown)', 'Permanent method counseling', 'Other medication / method']) as $method): ?>
                                    <option><?= esc($method) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Actual Service / Client Type *</label>
                            <select required name="acceptor_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select the service provided</option>
                                <option>New Acceptor</option>
                                <option>Continuing User</option>
                                <option>Changing Method</option>
                                <option>Changing Clinic</option>
                                <option>Restarting User</option>
                                <option>Dropout</option>
                                <option>Counseling Only</option>
                                <option>Male Client</option>
                                <option>Couple / Partner</option>
                                <option>Premarital Counseling</option>
                                <option>Postpartum or 3+ Pregnancies</option>
                            </select>
                            <p data-fp-type-message role="status" class="text-xs text-teal-700 mt-1"></p>
                            <button type="button" data-fp-type-auto hidden class="text-xs font-bold text-teal-700 underline mt-1">Use automatic suggestion</button>
                            <p class="text-xs text-slate-600 mt-1">FHSIS uses this selection automatically: new acceptance, changing method/clinic, restarting, or dropout. Continuing users and counseling do not add acceptors. Select the exact method and actual service date. Male client counts need verified partner linkage for WRA reporting.</p>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Counseling Reason</label>
                            <select name="counseling_reason" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Limit or space pregnancies</option>
                                <option>Three or more pregnancies or live births</option>
                                <option>Preparing for marriage</option>
                                <option>Postpartum family planning</option>
                                <option>Partner counseling</option>
                                <option>Method change or side-effect counseling</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Medications / Supplies Provided</label>
                            <input name="supplies" placeholder="e.g. Condoms (12), pills, injectable, iron/folate" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Actual Service / Supply Date *</label>
                            <input required type="date" name="last_supply_date" id="fp-last-supply-date" value="<?= date('Y-m-d') ?>" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Next Visit Date</label>
                            <input type="date" name="next_visit_date" id="fp-next-visit-date" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Clinical Notes</label>
                        <textarea name="clinical_notes" rows="3" placeholder="Counseling or clinical notes" class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('fp')); ?>" class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit" class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition-all">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    <script type="application/json" id="fp-client-type-history"><?= json_encode($fpTypeData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <script src="assets/js/family-planning-client-type.js?v=1"></script>
    <?php if ($qrFpBooking): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const booking = <?= json_encode($qrFpBooking, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const form = document.querySelector('[data-qr-fp-appointment]');
        if (!form) return;
        const set = (name, value) => {
            const field = form.elements.namedItem(name);
            if (!field || !value) return;
            if (field.tagName === 'SELECT' && !Array.from(field.options).some(option => option.value === String(value))) field.add(new Option(value, value));
            field.value = value;
            field.dispatchEvent(new Event('change', {bubbles:true}));
        };
        set('resident_id', booking.resident_id);
        const complaint = booking.chief_complaint.match(/^Family Planning\s*-\s*([^:]+):?\s*(.*)$/i);
        if (complaint) set('counseling_reason', complaint[2].trim());
        const treatment = (booking.treatment || '').split(' | Supplies/medications: ');
        set('contraceptive_method', treatment[0]);
        set('supplies', treatment[1]);
        set('next_visit_date', booking.follow_up_date);
        set('clinical_notes', booking.remarks || booking.consultation_notes);
    }, {once:true});
    </script>
    <?php endif; ?>
    <?php endif; ?>


    <?php if ($modal === 'new_birth'): ?>
        <?php
        $prefillBirthMotherId = (int) ($_GET['mother_id'] ?? 0);
        $prefillBirthDate = trim($_GET['birth_date'] ?? date('Y-m-d'));
        ?>
        <div class="midwife-form-modal fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div class="midwife-modal-panel bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Register Birth Record</h2>
                        <p class="text-xs text-slate-500">Child details and vital statistics</p>
                    </div>
                    <a href="<?= esc(tabUrl('vital')); ?>" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></a>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post">

                    <input type="hidden" name="action" value="save_birth_record">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Select Mother *</label>
                        <select required name="mother_id" id="birth-mother-select" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">-- Select mother --</option>
                            <?php foreach ($allMothersList as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $prefillBirthMotherId ? 'selected' : '' ?> data-father-name="<?= esc($r['inferred_father_name'] ?? '') ?>"><?= esc(rhuPatientLabel($r['name'], $r['id'] ?? 0)) ?> - <?= esc($r['barangay']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Child's Complete Name *</label>
                            <input required name="child_name" placeholder="Child's complete name" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Date of Birth *</label>
                            <input required type="date" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= esc($prefillBirthDate) ?>" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Time of Birth</label>
                            <input type="time" name="time_of_birth" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Gender</label>
                            <select name="gender" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select</option>
                                <option>Female</option>
                                <option>Male</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Father's Name</label>
                            <input name="father_name" id="birth-father-name" placeholder="Father's name" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Place of Birth</label>
                            <input name="place_of_birth" value="<?= esc($midwifeProfile['assigned_facility'] ?? 'Nasugbu RHU I') ?>" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Certificate Number</label>
                            <input name="birth_certificate_number" placeholder="Auto if blank" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Weight (kg)</label>
                            <input type="number" step="0.01" min="0" name="birth_weight_kg" placeholder="e.g., 3.20" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Length (cm)</label>
                            <input type="number" step="0.1" min="0" name="birth_length_cm" placeholder="e.g., 49.0" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('vital')); ?>" class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit" class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition-all">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
                <script>
                    (function () {
                        const motherSelect = document.getElementById('birth-mother-select');
                        const fatherInput = document.getElementById('birth-father-name');
                        if (!motherSelect || !fatherInput) return;
                        const syncFather = () => {
                            const selected = motherSelect.options[motherSelect.selectedIndex];
                            const fatherName = selected?.dataset?.fatherName || '';
                            if (fatherName && fatherInput.value.trim() === '') {
                                fatherInput.value = fatherName;
                            }
                        };
                        motherSelect.addEventListener('change', syncFather);
                        syncFather();
                    })();
                </script>
    <?php endif; ?>

    <?php if ($modal === 'new_referral'): ?>
        <div class="midwife-form-modal fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div class="midwife-modal-panel bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Create Maternal Referral</h2>
                        <p class="text-xs text-slate-500">Hospital referral for high-risk OB cases</p>
                    </div>
                    <a href="<?= esc(tabUrl('referrals')); ?>" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></a>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post">

                    <input type="hidden" name="action" value="save_referral">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Expectant Mother *</label>
                        <select required name="resident_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">-- Select expectant mother --</option>
                            <?php foreach ($allMothersList as $r): ?>
                                <option value="<?= (int)$r['id'] ?>"><?= esc(rhuPatientLabel($r['name'], $r['id'] ?? 0)) ?> - <?= esc($r['barangay']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Urgency</label>
                            <select name="urgency" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Routine</option>
                                <option>Urgent</option>
                                <option>Emergency</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Diagnosis *</label>
                            <input required name="diagnosis" placeholder="Clinical diagnosis" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Referred To *</label>
                            <input required name="referred_to" placeholder="Receiving hospital/facility" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Referral Reason *</label>
                        <textarea required name="referral_reason" rows="3" placeholder="Referral reason and clinical findings" class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('referrals')); ?>" class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit" class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition-all">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'maternal'): ?>
        <div
            class="maternal-case-modal mt-5 w-full">
            <div
                class="maternal-modal-panel bg-white rounded-2xl shadow-sm w-full overflow-hidden border border-slate-200">
                <div
                    class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800 flex items-center gap-2"><svg class="w-5 h-5"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 12h.01" />
                                <path d="M15 12h.01" />
                                <path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5" />
                                <path
                                    d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1" />
                            </svg> Register New Prenatal Maternal Case</h2>
                        <p class="text-xs text-slate-500">Log pregnancy tracking, LMP, and EDC calculation</p>
                    </div>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post">
                    <input type="hidden" name="action" value="save_maternal">
                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Select Expectant Mother *</label>
                        <select id="maternal-resident-select" name="resident_id" required
                            class="w-full p-3 border border-gray-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">-- Select Female Resident --</option>
                            <option value="new">+ Type and register a new mother</option>
                            <?php foreach ($allMothersList as $m):
                                $history = $maternalHistoryByResident[(int) $m['id']] ?? ['gravida' => 1, 'para' => 0, 'status' => '', 'lmp' => '', 'edc' => ''];
                            ?>
                                <option value="<?= esc($m['id']); ?>" data-gravida="<?= (int) $history['gravida']; ?>" data-para="<?= (int) $history['para']; ?>" data-pregnancy-status="<?= esc($history['status']); ?>" data-lmp="<?= esc($history['lmp']); ?>" data-edc="<?= esc($history['edc']); ?>"><?= esc(rhuPatientLabel($m['name'], $m['id'] ?? 0)); ?> (<?= esc($m['barangay']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="new-mother-fields" class="maternal-info-box hidden rounded-2xl border border-teal-200 bg-teal-50/60 p-4">
                        <p class="mb-3 font-extrabold text-teal-900">New Mother Information</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            
                                        <?php ob_start(); ?><input name="new_first_name" data-new-mother-required placeholder="First name *"
                                class="rounded-xl border border-gray-300 p-3">
                            <input name="new_middle_name" placeholder="Middle name (optional)"
                                class="rounded-xl border border-gray-300 p-3">
                            <input name="new_last_name" data-new-mother-required placeholder="Last name *"
                                class="rounded-xl border border-gray-300 p-3">
                            <input type="date" name="new_date_of_birth" data-new-mother-required max="<?= date('Y-m-d') ?>"
                                class="rounded-xl border border-gray-300 p-3" aria-label="Date of birth">
                            <input name="new_barangay" data-new-mother-required placeholder="Barangay *"
                                class="rounded-xl border border-gray-300 p-3">
                            <input name="new_contact_number" placeholder="Contact number (optional)"
                                class="rounded-xl border border-gray-300 p-3">
                            <input name="new_address" placeholder="Complete address (optional)"
                                class="rounded-xl border border-gray-300 p-3 sm:col-span-2">
                            <?php $motherIntakeIdentity = ob_get_clean(); ?>
                            <?= rhuIntakeFields(['middle_name'], $motherIntakeIdentity) ?>
                        </div>
                        <p class="mt-2 text-[10px] text-teal-700">A resident record will be created and linked automatically
                            to this prenatal case.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block font-bold text-slate-700 mb-1">Gravida (Total Pregnancies)</label><input
                                type="number" name="gravida" value="1" min="1" data-maternal-gravida
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-bold"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Para (Total Deliveries)</label><input
                                type="number" name="para" value="0" min="0" data-maternal-para
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-bold"></div>
                    </div>
                    <?= rhuVisitFields() ?>
                    <div data-active-pregnancy-notice class="hidden rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900"></div>
                    <div class="maternal-info-box bg-teal-50/60 p-4 rounded-2xl border border-teal-100 space-y-3">
                        <p class="font-bold text-teal-900 text-xs">Maternal Timeline &amp; Dates</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="block font-bold text-slate-700 mb-1">Last Menstrual Period (LMP)
                                    *</label><input type="date" name="lmp" id="maternal-lmp-date" required
                                    value="<?= esc($defaultMaternalLmp); ?>"
                                    class="w-full p-2.5 border border-slate-300 rounded-xl text-xs font-mono font-bold text-slate-800">
                            </div>
                            <div><label class="block font-bold text-slate-700 mb-1">Expected EDC (40 weeks / 9 months) *</label><input type="date"
                                    name="edc" id="maternal-edc-date" required readonly value="<?= esc($defaultMaternalEdc); ?>"
                                    class="w-full p-2.5 border border-slate-300 rounded-xl text-xs font-mono font-bold text-slate-800">
                            </div>
                        </div>
                    </div>
                    <div class="maternal-risk-box flex items-center gap-3 p-3.5 bg-rose-50 rounded-2xl border border-rose-200">
                        <input type="checkbox" name="high_risk" value="1" id="high_risk_chk"
                            class="w-4 h-4 text-rose-600 rounded">
                        <label for="high_risk_chk" class="font-bold text-rose-900 cursor-pointer text-xs">Classify as
                            HIGH-RISK Pregnancy</label>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Risk Factors &amp; Clinical Health Plan</label>
                        <textarea name="risk_factors" rows="3"
                            class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none outline-none"
                            placeholder="e.g., Gestational hypertension, previous C-section, teenage pregnancy"></textarea>
                    </div>
                    <div class="pt-2">
                        <button type="submit"
                            data-maternal-submit class="w-full py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 shadow-md shadow-teal-600/20 transition-all">Save
                            Prenatal Maternal Case</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <?= portalRenderNotificationPanel(); ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('select[id="maternal-resident-select"]').forEach((residentSelect) => {
                const form = residentSelect.closest('form');
                const newMotherFields = form?.querySelector('#new-mother-fields');
                const gravidaInput = form?.querySelector('[data-maternal-gravida]');
                const paraInput = form?.querySelector('[data-maternal-para]');
                const lmpInput = form?.querySelector('[name="lmp"]');
                const edcInput = form?.querySelector('[name="edc"]');
                    const submitButton = form?.querySelector('[data-maternal-submit]');
                const activeNotice = form?.querySelector('[data-active-pregnancy-notice]');
                if (!form || !newMotherFields) return;

                const isActivePregnancyStatus = (status) => ['active', 'pregnant', 'ongoing'].includes(String(status || '').trim().toLowerCase());
                const syncEdcFromLmp = () => {
                    if (!lmpInput?.value || !edcInput) return;
                    const [year, month, day] = lmpInput.value.split('-').map(Number);
                    const lmpDate = new Date(year, month - 1, day);
                    if (Number.isNaN(lmpDate.getTime())) return;
                    lmpDate.setDate(lmpDate.getDate() + 280);
                    const edcYear = lmpDate.getFullYear();
                    const edcMonth = String(lmpDate.getMonth() + 1).padStart(2, '0');
                    const edcDay = String(lmpDate.getDate()).padStart(2, '0');
                    edcInput.value = `${edcYear}-${edcMonth}-${edcDay}`;
                };

                const updateMaternalFields = () => {
                    const isNew = residentSelect.value === 'new';
                    newMotherFields.classList.toggle('hidden', !isNew);
                    newMotherFields.querySelectorAll('[data-new-mother-required]').forEach(input => {
                        input.required = isNew;
                    });

                    const selectedOption = residentSelect.selectedOptions?.[0];
                    if (!selectedOption || isNew || residentSelect.value === '') {
                        if (gravidaInput) gravidaInput.value = '1';
                        if (paraInput) paraInput.value = '0';
                        if (submitButton) submitButton.textContent = 'Save Prenatal Maternal Case';
                        activeNotice?.classList.add('hidden');
                        syncEdcFromLmp();
                        return;
                    }

                    const gravida = parseInt(selectedOption.dataset.gravida || '1', 10);
                    const para = parseInt(selectedOption.dataset.para || '0', 10);
                    if (gravidaInput) gravidaInput.value = String(Math.max(1, Number.isFinite(gravida) ? gravida : 1));
                    if (paraInput) paraInput.value = String(Math.max(0, Number.isFinite(para) ? para : 0));
                    if (selectedOption.dataset.lmp && lmpInput) lmpInput.value = selectedOption.dataset.lmp;
                    if (selectedOption.dataset.edc && edcInput) edcInput.value = selectedOption.dataset.edc;

                    const alreadyPregnant = isActivePregnancyStatus(selectedOption.dataset.pregnancyStatus) && !!selectedOption.dataset.edc;
                    const postpartumLock = selectedOption.dataset.postpartumLock === '1';
                    const postpartumDate = selectedOption.dataset.postpartumDate || 'recent birth';
                    if (submitButton) {
                        submitButton.disabled = postpartumLock;
                        submitButton.textContent = postpartumLock
                            ? 'Postpartum Lock Active'
                            : (alreadyPregnant ? 'Update Prenatal Maternal Case' : 'Save Prenatal Maternal Case');
                    }
                    if (activeNotice) {
                        if (postpartumLock) {
                            activeNotice.textContent = `This mother gave birth on ${postpartumDate}. She is still within the first 7 months postpartum, so a new active pregnancy record is blocked.`;
                            activeNotice.classList.remove('hidden');
                        } else if (alreadyPregnant) {
                            activeNotice.textContent = `This mother already has an active pregnancy record. Expected due date: ${selectedOption.dataset.edc}. Saving this form will update that pregnancy record instead of creating a duplicate.`;
                            activeNotice.classList.remove('hidden');
                        } else {
                            activeNotice.textContent = '';
                            activeNotice.classList.add('hidden');
                        }
                    }
                };
                residentSelect.addEventListener('change', updateMaternalFields);
                lmpInput?.addEventListener('change', syncEdcFromLmp);
                lmpInput?.addEventListener('input', syncEdcFromLmp);
                updateMaternalFields();
            });

            const fpLastSupplyInput = document.getElementById('fp-last-supply-date');
            const fpNextVisitInput = document.getElementById('fp-next-visit-date');
            const syncFpNextVisit = () => {
                if (!fpLastSupplyInput?.value || !fpNextVisitInput) return;
                const [year, month, day] = fpLastSupplyInput.value.split('-').map(Number);
                const supplyDate = new Date(year, month - 1, day);
                if (Number.isNaN(supplyDate.getTime())) return;
                supplyDate.setDate(supplyDate.getDate() + 30);
                const nextYear = supplyDate.getFullYear();
                const nextMonth = String(supplyDate.getMonth() + 1).padStart(2, '0');
                const nextDay = String(supplyDate.getDate()).padStart(2, '0');
                fpNextVisitInput.value = `${nextYear}-${nextMonth}-${nextDay}`;
            };
            fpLastSupplyInput?.addEventListener('change', syncFpNextVisit);
            fpLastSupplyInput?.addEventListener('input', syncFpNextVisit);
            syncFpNextVisit();
        });
    </script>





    <script>
        (function () {
            // Escape the main content's isolated stacking context so the
            // backdrop also covers the navigation and sticky header.
            document.querySelectorAll('.midwife-form-modal').forEach((modal) => {
                document.body.appendChild(modal);
            });
            const sidebar = document.querySelector('[data-feature-drawer]');
            const backdrop = document.querySelector('[data-drawer-backdrop]');
            const openBtn = document.querySelector('[data-drawer-open]');
            const closeBtn = document.querySelector('[data-drawer-close]');
            const header = document.querySelector('.dashboard-header');
            const setOpen = (open) => {
                sidebar?.classList.toggle('is-open', open);
                backdrop?.classList.toggle('is-open', open);
                document.body.classList.toggle('drawer-open', open);
                openBtn?.setAttribute('aria-expanded', String(!!open));
            };
            openBtn?.addEventListener('click', () => setOpen(true));
            closeBtn?.addEventListener('click', () => setOpen(false));
            backdrop?.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });
            /* scroll shadow effect disabled for reduced motion */
        })();
    </script>
<link rel="stylesheet" href="styles/staff-record-search.css?v=1">
<script defer src="assets/js/staff-record-search.js?v=2"></script>
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
<?= $pdo instanceof PDO ? rhuIntakeSavedData($pdo) : '' ?>
</body>

</html>
