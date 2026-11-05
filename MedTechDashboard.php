<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();

$stType = strtoupper((string) ($_SESSION['rhu_staff_login']['staff_type'] ?? ''));
if (empty($_SESSION['rhu_staff_login']) || ($stType !== 'MEDTECH' && strpos($stType, 'MEDTECH') === false && strpos($stType, 'TECHNOLOGIST') === false)) {
    header('Location: RHULogin.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/patient-identifiers.php';
require_once __DIR__ . '/includes/record-access.php';
define('RHU_PRESERVE_SCHEMA', true);
require_once __DIR__ . '/includes/portal.php';
require_once __DIR__ . '/includes/dashboard-workflows.php';
require_once __DIR__ . '/includes/appointment-email-reminders.php';
require_once __DIR__ . '/includes/fhsis-services.php';
portalHandleNotificationApi($pdo);
if ($pdo instanceof PDO && function_exists('rhuSendUpcomingAppointmentEmailReminders')) {
    rhuSendUpcomingAppointmentEmailReminders($pdo);
}

function esc($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}


function iconSvg(string $name, string $class = 'w-5 h-5'): string
{
    $icons = [
        'menu' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'logout' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/><path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/></svg>',
        'close' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'shield' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function tabUrl(string $tab, array $extra = []): string
{
    return '?' . http_build_query(array_merge(['tab' => $tab], $extra));
}

function flagClass(string $flag): string
{
    $flag = strtolower($flag);
    if ($flag === 'completed') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
    if ($flag === 'normal') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
    if ($flag === 'notable') return 'bg-sky-100 text-sky-800 border-sky-200';
    if ($flag === 'pending') return 'bg-amber-100 text-amber-800 border-amber-200';
    return 'bg-rose-100 text-rose-800 border-rose-200';
}

function bloodCompatibilityRules(): array
{
    return [
        'O-' => ['donate_to' => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'], 'receive_from' => ['O-']],
        'O+' => ['donate_to' => ['O+', 'A+', 'B+', 'AB+'], 'receive_from' => ['O-', 'O+']],
        'A-' => ['donate_to' => ['A-', 'A+', 'AB-', 'AB+'], 'receive_from' => ['O-', 'A-']],
        'A+' => ['donate_to' => ['A+', 'AB+'], 'receive_from' => ['O-', 'O+', 'A-', 'A+']],
        'B-' => ['donate_to' => ['B-', 'B+', 'AB-', 'AB+'], 'receive_from' => ['O-', 'B-']],
        'B+' => ['donate_to' => ['B+', 'AB+'], 'receive_from' => ['O-', 'O+', 'B-', 'B+']],
        'AB-' => ['donate_to' => ['AB-', 'AB+'], 'receive_from' => ['O-', 'A-', 'B-', 'AB-']],
        'AB+' => ['donate_to' => ['AB+'], 'receive_from' => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+']],
    ];
}

function normalizeBloodType(string $bloodType): string
{
    $value = strtoupper(trim($bloodType));
    $value = str_replace([' ', 'POSITIVE', 'NEGATIVE'], ['', '+', '-'], $value);
    return in_array($value, array_keys(bloodCompatibilityRules()), true) ? $value : '';
}

function medtechCanAccessConfidentialTesting(): bool
{
    return (int)($_SESSION['rhu_staff_login']['staff_id'] ?? 0) > 0
        && rhuRecordStaffIsMedtech($_SESSION['rhu_staff_login'] ?? []);
}

function medtechConfidentialKey(): string
{
    $configured = function_exists('rhuEnv') ? (string) rhuEnv('RHU_CONFIDENTIAL_KEY', '') : '';
    $material = $configured !== '' ? $configured : (__DIR__ . '|rhu-confidential-testing-local-key');
    return hash('sha256', $material, true);
}

function medtechEncryptConfidential(string $plainText): string
{
    if ($plainText === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', medtechConfidentialKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherText === false) {
        throw new RuntimeException('Unable to encrypt confidential result.');
    }
    return base64_encode($iv . $tag . $cipherText);
}

function medtechDecryptConfidential(?string $payload): string
{
    $payload = (string)($payload ?? '');
    if ($payload === '') return '';
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) return '[Unable to decrypt]';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipherText = substr($raw, 28);
    $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', medtechConfidentialKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plainText === false ? '[Unable to decrypt]' : $plainText;
}

function medtechEnsureConfidentialTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS confidential_lab_tests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        resident_id BIGINT UNSIGNED NOT NULL,
        test_type VARCHAR(80) NOT NULL DEFAULT 'HIV Screening',
        result_status VARCHAR(50) NOT NULL DEFAULT 'Pending',
        result_encrypted LONGTEXT NULL,
        specimen_date DATE NULL,
        release_date DATE NULL,
        counselor_staff_id BIGINT UNSIGNED NULL,
        created_by_staff_id BIGINT UNSIGNED NOT NULL,
        notes_encrypted LONGTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_confidential_resident (resident_id),
        INDEX idx_confidential_counselor (counselor_staff_id),
        INDEX idx_confidential_type_status (test_type, result_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS confidential_access_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        staff_id BIGINT UNSIGNED NOT NULL,
        resident_id BIGINT UNSIGNED NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(80) NOT NULL,
        accessed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        INDEX idx_confidential_access_record (record_id),
        INDEX idx_confidential_access_staff (staff_id),
        INDEX idx_confidential_access_resident (resident_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function medtechLogConfidentialAccess(PDO $pdo, int $staffId, int $residentId, int $recordId, string $action): void
{
    $stmt = $pdo->prepare("INSERT INTO confidential_access_logs (staff_id, resident_id, record_id, action, ip_address, user_agent, accessed_at) VALUES (:staff_id, :resident_id, :record_id, :action, :ip_address, :user_agent, NOW())");
    $stmt->execute([
        'staff_id' => $staffId,
        'resident_id' => $residentId,
        'record_id' => $recordId,
        'action' => $action,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

function medtechBloodMapPayload(array $sharedLocationResidents, array $compatibleDonorTypes): array
{
    $compatibleResidents = array_values(array_filter($sharedLocationResidents, static function (array $resident) use ($compatibleDonorTypes): bool {
        return in_array((string)($resident['blood_type'] ?? ''), $compatibleDonorTypes, true);
    }));
    return array_map(static fn($resident) => [
        'id' => (int)($resident['id'] ?? 0),
        'name' => (string)($resident['resident_name'] ?? 'Resident'),
        'blood_type' => (string)($resident['blood_type'] ?? ''),
        'barangay' => (string)($resident['barangay'] ?? ''),
        'address' => (string)($resident['address'] ?? ''),
        'recorded_at' => substr((string)($resident['recorded_at'] ?? ''), 0, 10),
        'latitude' => isset($resident['latitude']) ? (float)$resident['latitude'] : null,
        'longitude' => isset($resident['longitude']) ? (float)$resident['longitude'] : null,
        'accuracy_m' => isset($resident['accuracy_m']) ? (int)$resident['accuracy_m'] : null,
        'location_shared_at' => substr((string)($resident['location_shared_at'] ?? ''), 0, 19),
        'is_compatible' => true,
    ], $compatibleResidents);
}

$tabs = [
    'overview' => ['Overview', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'rapid' => ['Rapid Diagnostic Tests', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg>'],
    'confidential' => ['Confidential Testing', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><path d="M12 15v2"/></svg>'],
    'blood' => ['Blood Compatibility', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/><path d="M8.5 14h7"/><path d="M12 10.5v7"/></svg>'],
    'supplies' => ['Kit Usage History', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>'],
];

$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab]))
    $tab = 'overview';

$modal = $_GET['modal'] ?? '';
$flashSuccess = $_SESSION['medtech_flash_success'] ?? '';
$flashError = $_SESSION['medtech_flash_error'] ?? '';
unset($_SESSION['medtech_flash_success'], $_SESSION['medtech_flash_error']);

$medtechStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
$medtechUserId = (int) ($_SESSION['rhu_staff_login']['id'] ?? $_SESSION['rhu_staff_login']['user_id'] ?? 0);
$medtechDisplayName = trim((string)($_SESSION['rhu_staff_login']['first_name'] ?? '') . ' ' . (string)($_SESSION['rhu_staff_login']['last_name'] ?? ''));
if ($medtechDisplayName === '') {
    $medtechDisplayName = trim((string)($_SESSION['rhu_staff_login']['name'] ?? $_SESSION['rhu_staff_login']['username'] ?? 'Logged-in MedTech'));
}

$medtechLabWhereSql = "(LOWER(COALESCE(c.chief_complaint, '')) REGEXP 'laboratory|blood[ \t\r\n]+work|medical technologist|diagnostic|rdt|rapid diagnostic|lab test' OR LOWER(COALESCE(c.remarks, '')) LIKE '%lab test%')";
$medtechNotDentalWhereSql = "LOWER(COALESCE(c.chief_complaint, '')) NOT REGEXP 'dental|dentist|tooth|oral'";

$medtechConsultations = [];
$consultationOptions = [];
$residentOptions = [];
$staffOptions = [];
$tests = [];
$supplies = [];
$inventoryUsageOptions = [];
$bloodLocatorResidents = [];
$sharedLocationResidents = [];
$bloodCompatibility = bloodCompatibilityRules();
$confidentialTests = [];
$confidentialAccessLogs = [];

// ----------------------------------------------------
require_once __DIR__ . '/includes/resident-qr.php';
require_once __DIR__ . '/includes/fhsis-services.php';

// 1. POST FORM HANDLERS FOR MEDTECH DIAGNOSTICS & SUPPLIES
// ----------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($pdo)) {
    if (!portalVerifyCsrf()) { http_response_code(419); exit('Form expired. Refresh the page and retry.'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'adjust_lab_supply') {
        http_response_code(403);
        exit('Record materials used through the resident laboratory test form.');
    }

    // Action: Answer / Update Resident Laboratory Consultation
    if ($action === 'answer_consultation') {
        $cslId = (int) ($_POST['consultation_id'] ?? 0);
        $resId = (int) ($_POST['resident_id'] ?? 0);
        $appointmentDate = trim((string)($_POST['appointment_date'] ?? ''));
        $appointmentTime = trim((string)($_POST['appointment_time'] ?? ''));
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $notes = trim($_POST['consultation_notes'] ?? '');
        $status = trim($_POST['consultation_status'] ?? 'Completed');

        if (($cslId > 0 || ($resId > 0 && $appointmentDate !== '' && $appointmentTime !== '')) && !empty($pdo)) {
            try {
                $where = $cslId > 0 ? 'id = :id' : 'resident_id = :resident_id AND DATE(consultation_date) = :appointment_date AND consultation_time = :appointment_time';
                $params = [
                    'dx' => $diagnosis,
                    'notes' => trim($notes . "\nStatus: {$status}"),
                ];
                if ($cslId > 0) {
                    $params['id'] = $cslId;
                } else {
                    $params['resident_id'] = $resId;
                    $params['appointment_date'] = substr($appointmentDate, 0, 10);
                    $params['appointment_time'] = $appointmentTime;
                }
                $stmt = $pdo->prepare("UPDATE consultations c SET diagnosis = :dx, remarks = :notes, updated_at = NOW() WHERE {$where} AND {$medtechLabWhereSql} AND {$medtechNotDentalWhereSql}");
                $stmt->execute($params);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('The selected record is not a laboratory/diagnostic consultation.');
                }
                if (!empty($_POST['fhsis_inline'])) {
                    $source = $pdo->prepare("SELECT id, resident_id FROM consultations WHERE {$where} AND {$medtechLabWhereSql} AND {$medtechNotDentalWhereSql} ORDER BY id DESC LIMIT 1");
                    $source->execute(array_intersect_key($params, array_flip(['id','resident_id','appointment_date','appointment_time'])));
                    $clinicalSource = $source->fetch(PDO::FETCH_ASSOC);
                    if (!$clinicalSource) throw new RuntimeException('The completed laboratory source could not be found.');
                    if (rhuColumnExists($pdo, 'consultations', 'consultation_status')) $pdo->prepare('UPDATE consultations SET consultation_status=? WHERE id=?')->execute([$status, $clinicalSource['id']]);
                    fhsisSaveInlineServices($pdo, 'consultations', (int)$clinicalSource['id'], (int)$clinicalSource['resident_id'], $_POST, $_SESSION);
                }
                portalSaveHealthRecordEntry($pdo, $resId, [
                    'record_type' => 'Laboratory consultation response',
                    'diagnosis' => $diagnosis,
                    'notes' => $notes,
                    'status' => $status,
                    'recorded_by_id' => $medtechStaffId ?: null,
                ]);
                rhuNotifySavedResidentAppointment($pdo, $cslId, 'Laboratory appointment');
                if (function_exists('portalBroadcastWorkflowUpdate')) {
                    portalBroadcastWorkflowUpdate($pdo, "Your Laboratory / Diagnostic request result was updated by MedTech. Status: {$status}. Assessment: {$diagnosis}", $resId, 'ResidentDashboard.php?tab=appointments', ['RHU_STAFF', 'MEDTECH']);
                }
                $_SESSION['medtech_flash_success'] = 'Laboratory consultation updated and response sent to resident!';
            } catch (Exception $e) {
                $_SESSION['medtech_flash_error'] = 'Error updating consultation: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('overview'));
        exit;
    }

    if ($action === 'save_test') {
        try {
            workflowRecordTest($pdo, $_POST, $medtechStaffId);
            $_SESSION['medtech_flash_success'] = 'Rapid Diagnostic Test result saved successfully!';
        } catch (Throwable $e) { $_SESSION['medtech_flash_error'] = $e->getMessage(); }
        header('Location: ' . tabUrl('rapid'));
        exit;
    }

    if (in_array($action,['approve_fhsis_screening','withdraw_fhsis_screening'],true)) {
        $privateReportingTransaction=false;
        try {
            if (!medtechCanAccessConfidentialTesting() || !portalVerifyCsrf()) throw new RuntimeException('Reload the private laboratory form with an authorized MedTech session.');
            fhsisServicesEnsure($pdo); $reportingKey=fhsisReportingKey($pdo);
            $recordId=(int)($_POST['record_id']??0);
            $pdo->beginTransaction(); $privateReportingTransaction=true;
            $owned=$pdo->prepare('SELECT * FROM confidential_lab_tests WHERE id=? AND created_by_staff_id=? FOR UPDATE'); $owned->execute([$recordId,$medtechStaffId]); $private=$owned->fetch(PDO::FETCH_ASSOC);
            if (!$private) throw new RuntimeException('Only the recording MedTech can change this reporting approval.');
            if ($action==='withdraw_fhsis_screening') {
                fhsisWithdrawScreening($pdo,$recordId,$_SESSION['rhu_staff_login'],$reportingKey);
                medtechLogConfidentialAccess($pdo,$medtechStaffId,(int)$private['resident_id'],$recordId,'Withdrew FHSIS aggregate approval');
            } else {
                if (empty($_POST['fhsis_aggregate_approved'])) throw new RuntimeException('Confirm authorization to include the verified reporting facts.');
                $exact=(string)($_POST['fhsis_test_type']??'');
                if ($exact!=='') {
                    if (!in_array($exact,['HIV Screening','Syphilis Screening','Hepatitis B Screening'],true)) throw new RuntimeException('Select the exact documented test.');
                    $pdo->prepare('UPDATE confidential_lab_tests SET test_type=? WHERE id=? AND created_by_staff_id=?')->execute([$exact,$recordId,$medtechStaffId]);
                }
                fhsisApproveScreening($pdo,$recordId,$_POST,$_SESSION['rhu_staff_login'],$reportingKey,'medtechEncryptConfidential');
                $updatedNotes=medtechDecryptConfidential($private['notes_encrypted'])."\nFHSIS documentation: ".fhsisScreeningDocumentation($_POST);
                $pdo->prepare('UPDATE confidential_lab_tests SET notes_encrypted=? WHERE id=? AND created_by_staff_id=?')->execute([medtechEncryptConfidential($updatedNotes),$recordId,$medtechStaffId]);
            }
            $pdo->commit(); $privateReportingTransaction=false;
            $_SESSION['medtech_flash_success']='Private reporting approval updated. The next FHSIS export will recalculate the aggregate counts.';
        } catch (Throwable $e) {
            if ($privateReportingTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['medtech_flash_error']=$e->getMessage();
        }
        header('Location: '.tabUrl('confidential')); exit;
    }

    if ($action === 'save_confidential_test') {
        $privateReportingTransaction = false;
        try {
            if (!medtechCanAccessConfidentialTesting()) {
                throw new RuntimeException('You are not authorized to record confidential test results.');
            }
            medtechEnsureConfidentialTables($pdo);
            require_once __DIR__.'/includes/fhsis-services.php';
            if (!portalVerifyCsrf()) throw new RuntimeException('Your session expired. Reload the form and try again.');
            if (!empty($_POST['fhsis_aggregate_approved'])) { fhsisServicesEnsure($pdo); $reportingKey=fhsisReportingKey($pdo); }
            $residentId = (int)($_POST['resident_id'] ?? 0);
            $testType = trim((string)($_POST['test_type'] ?? 'HIV Screening'));
            $resultStatus = trim((string)($_POST['result_status'] ?? 'Pending'));
            $resultText = trim((string)($_POST['result_text'] ?? ''));
            $notes = trim((string)($_POST['confidential_notes'] ?? ''));
            if (!empty($_POST['fhsis_aggregate_approved'])) $notes .= "\nFHSIS documentation: ".fhsisScreeningDocumentation($_POST);
            $specimenDate = trim((string)($_POST['specimen_date'] ?? date('Y-m-d')));
            $releaseDate = trim((string)($_POST['release_date'] ?? ''));
            if ($residentId <= 0) {
                throw new InvalidArgumentException('Select a resident for the confidential test.');
            }
            if (!in_array($resultStatus, ['Pending', 'For Counseling', 'Released Privately', 'Referred'], true)) {
                $resultStatus = 'Pending';
            }
            if (!in_array($testType, ['HIV Screening', 'Syphilis Screening', 'Hepatitis B Screening', 'HIV Confirmatory Referral', 'STI Screening', 'Other Confidential Test'], true)) {
                $testType = 'Other Confidential Test';
            }
            $residentCheck = $pdo->prepare('SELECT id FROM residents WHERE id = :id LIMIT 1');
            $residentCheck->execute(['id' => $residentId]);
            if (!$residentCheck->fetchColumn()) {
                throw new InvalidArgumentException('Resident record was not found.');
            }

            $pdo->beginTransaction(); $privateReportingTransaction = true;
            $stmt = $pdo->prepare("INSERT INTO confidential_lab_tests
                (resident_id, test_type, result_status, result_encrypted, specimen_date, release_date, counselor_staff_id, created_by_staff_id, notes_encrypted, created_at)
                VALUES (:resident_id, :test_type, :result_status, :result_encrypted, :specimen_date, :release_date, :counselor_staff_id, :created_by_staff_id, :notes_encrypted, NOW())");
            $stmt->execute([
                'resident_id' => $residentId,
                'test_type' => $testType,
                'result_status' => $resultStatus,
                'result_encrypted' => medtechEncryptConfidential($resultText),
                'specimen_date' => $specimenDate !== '' ? $specimenDate : date('Y-m-d'),
                'release_date' => $releaseDate !== '' ? $releaseDate : null,
                'counselor_staff_id' => $medtechStaffId ?: null,
                'created_by_staff_id' => $medtechStaffId ?: 0,
                'notes_encrypted' => medtechEncryptConfidential($notes),
            ]);
            $recordId = (int)$pdo->lastInsertId();
            medtechLogConfidentialAccess($pdo, $medtechStaffId, $residentId, $recordId, 'Created confidential test record');
            if (!empty($_POST['fhsis_aggregate_approved'])) fhsisApproveScreening($pdo,$recordId,$_POST,$_SESSION['rhu_staff_login'],$reportingKey,'medtechEncryptConfidential');
            $pdo->commit(); $privateReportingTransaction = false;
            $_SESSION['medtech_flash_success'] = 'Confidential test record saved privately.';
        } catch (Throwable $e) {
            if ($privateReportingTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['medtech_flash_error'] = 'Confidential Test Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('confidential'));
        exit;
    }

    if ($action === 'save_lab_supply') {
        http_response_code(403);
        exit('Only the administrator can add inventory items or stock.');
    }

}

// ----------------------------------------------------
// 2. LIVE DATABASE HYDRATION
// ----------------------------------------------------
if (!empty($pdo)) {
    try {
        $mtStmt = $pdo->prepare("
            SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age, r.sex as gender, c.chief_complaint as chiefComplaint, c.diagnosis, COALESCE(b.name, 'Unassigned') as barangay, c.consultation_date as date, c.consultation_time as time, c.remarks as consultation_notes, CASE WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' AND c.diagnosis <> 'Pending OPD Triage' THEN 'Completed' ELSE 'Scheduled' END AS consultation_status
            FROM consultations c
            JOIN residents r ON c.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE " . rhuLivingResidentWhereSql('r') . " AND {$medtechNotDentalWhereSql} AND (
                c.health_worker_id = :sid_hw
                OR c.physician_id = :sid_phy
                OR {$medtechLabWhereSql}
            )
            ORDER BY c.consultation_date DESC, c.consultation_time DESC, c.id DESC
        ");
        $mtStmt->execute(['sid_hw' => $medtechStaffId, 'sid_phy' => $medtechStaffId]);
        $medtechConsultations = rhuWithoutExpiredAppointments($mtStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Exception $e) {
    }

    try {
        $consultationOptionsCompletedFilter = (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_status'))
            ? "AND COALESCE(c.consultation_status, '') <> 'Completed'"
            : "AND (c.diagnosis IS NULL OR c.diagnosis = '' OR c.diagnosis = 'Pending OPD Triage')";
        $consultationOptions = $pdo->query("
            SELECT c.id, c.resident_id, c.consultation_date, c.consultation_time,
                   CONCAT(r.first_name, ' ', r.last_name) patient, c.chief_complaint
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND {$medtechLabWhereSql}
              AND {$medtechNotDentalWhereSql}
              {$consultationOptionsCompletedFilter}
              AND NOT EXISTS (
                  SELECT 1
                  FROM audit_logs al
                  WHERE al.module_name = 'MedTech'
                    AND al.action = 'Laboratory Test Recorded'
                    AND al.record_id = c.id
              )
            ORDER BY c.consultation_date DESC, c.consultation_time DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $consultationOptions = rhuWithoutExpiredAppointments($consultationOptions);
        $residentOptions = $pdo->query(rhuResidentListSql() . " WHERE " . rhuLivingResidentWhereSql('r') . " ORDER BY r.first_name, r.last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $staffOptions = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) name, position_title AS staff_type FROM health_workers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $residentBloodColumn = function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'residents', 'blood_type') ? 'r.blood_type' : "''";
        if (function_exists('portalEnsureResidentLocationTable')) {
            portalEnsureResidentLocationTable($pdo);
        }
        $bloodStmt = $pdo->query("
            SELECT r.id,
                   CONCAT(r.first_name, ' ', r.last_name) AS resident_name,
                   COALESCE(b.name, '') AS barangay,
                   r.address,
                   r.contact_number,
                   COALESCE(NULLIF(hr.blood_type, ''), NULLIF({$residentBloodColumn}, '')) AS blood_type,
                   COALESCE(hr.updated_at, hr.created_at, r.created_at) AS recorded_at,
                   rl.latitude,
                   rl.longitude,
                   rl.accuracy_m,
                   rl.shared_at AS location_shared_at
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_records hr ON hr.id = (
                SELECT h2.id
                FROM health_records h2
                WHERE h2.resident_id = r.id AND h2.blood_type IS NOT NULL AND h2.blood_type <> ''
                ORDER BY h2.updated_at DESC, h2.id DESC
                LIMIT 1
            )
            LEFT JOIN resident_locations rl ON rl.resident_id = r.id
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND COALESCE(NULLIF(hr.blood_type, ''), NULLIF({$residentBloodColumn}, '')) IS NOT NULL
              AND COALESCE(NULLIF(hr.blood_type, ''), NULLIF({$residentBloodColumn}, '')) <> ''
            ORDER BY b.name, r.last_name, r.first_name
        ");
        foreach ($bloodStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $bloodRow) {
            $normalizedBloodType = normalizeBloodType((string)($bloodRow['blood_type'] ?? ''));
            if ($normalizedBloodType === '') continue;
            $bloodRow['blood_type'] = $normalizedBloodType;
            $bloodLocatorResidents[] = $bloodRow;
        }

        $sharedLocationStmt = $pdo->query("
            SELECT r.id,
                   CONCAT(r.first_name, ' ', r.last_name) AS resident_name,
                   COALESCE(b.name, '') AS barangay,
                   r.address,
                   r.contact_number,
                   COALESCE(NULLIF(hr.blood_type, ''), NULLIF({$residentBloodColumn}, '')) AS blood_type,
                   COALESCE(hr.updated_at, hr.created_at, r.created_at) AS recorded_at,
                   rl.latitude,
                   rl.longitude,
                   rl.accuracy_m,
                   rl.shared_at AS location_shared_at
            FROM resident_locations rl
            JOIN residents r ON r.id = rl.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_records hr ON hr.id = (
                SELECT h2.id
                FROM health_records h2
                WHERE h2.resident_id = r.id AND h2.blood_type IS NOT NULL AND h2.blood_type <> ''
                ORDER BY h2.updated_at DESC, h2.id DESC
                LIMIT 1
            )
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND rl.latitude IS NOT NULL
              AND rl.longitude IS NOT NULL
            ORDER BY rl.shared_at DESC, b.name, r.last_name, r.first_name
        ");
        foreach ($sharedLocationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $locationRow) {
            $locationRow['blood_type'] = normalizeBloodType((string)($locationRow['blood_type'] ?? ''));
            $sharedLocationResidents[] = $locationRow;
        }

        $consultationStatusSelect = function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_status') ? 'c.consultation_status' : "'Completed'";
        $testRows = $pdo->query("SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) patient, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) age, COALESCE(b.name, 'Unassigned') barangay, c.chief_complaint AS test_name, c.remarks AS results, CASE WHEN c.remarks LIKE '%positive%' OR c.remarks LIKE '%abnormal%' THEN 'Abnormal' WHEN c.remarks LIKE '%pending%' THEN 'Pending' ELSE COALESCE({$consultationStatusSelect}, 'Completed') END AS test_status, 'Laboratory' AS test_type, COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'RHU MedTech') ordered_by, c.consultation_date AS test_date FROM consultations c JOIN residents r ON r.id=c.resident_id LEFT JOIN barangays b ON b.id=r.barangay_id LEFT JOIN health_workers hw ON hw.id=c.health_worker_id WHERE " . rhuLivingResidentWhereSql('r') . " AND {$medtechNotDentalWhereSql} AND ({$medtechLabWhereSql} OR c.id IN (SELECT record_id FROM audit_logs WHERE module_name='MedTech' AND action='Laboratory Test Recorded')) ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $auditTests = $pdo->query("SELECT id, record_id, description FROM audit_logs WHERE module_name='MedTech' AND action='Laboratory Test Recorded' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $tests = workflowTestRows($testRows, $auditTests);

        if (medtechCanAccessConfidentialTesting()) {
            medtechEnsureConfidentialTables($pdo);
            $confStmt = $pdo->prepare("
                SELECT clt.*, CONCAT(r.first_name, ' ', r.last_name) AS resident_name,
                       COALESCE(b.name, 'Unassigned') AS barangay,
                       COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'Assigned confidential personnel') AS counselor_name
                FROM confidential_lab_tests clt
                JOIN residents r ON r.id = clt.resident_id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                LEFT JOIN health_workers hw ON hw.id = clt.counselor_staff_id
                WHERE " . rhuLivingResidentWhereSql('r') . "
                  AND clt.created_by_staff_id = :staff_id
                ORDER BY clt.created_at DESC, clt.id DESC
                LIMIT 100
            ");
            $confStmt->execute(['staff_id' => $medtechStaffId]);
            $confidentialTests = array_values(array_filter($confStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
                static fn($record) => rhuCanReadConfidentialRecord($_SESSION['rhu_staff_login'] ?? [], $record)));
            foreach ($confidentialTests as &$confidentialTest) {
                $confidentialTest['result_text'] = medtechDecryptConfidential($confidentialTest['result_encrypted'] ?? '');
                $confidentialTest['confidential_notes'] = medtechDecryptConfidential($confidentialTest['notes_encrypted'] ?? '');
            }
            unset($confidentialTest);

            $logStmt = $pdo->prepare("
                SELECT cal.*, CONCAT(hw.first_name, ' ', hw.last_name) AS staff_name, CONCAT(r.first_name, ' ', r.last_name) AS resident_name
                FROM confidential_access_logs cal
                INNER JOIN confidential_lab_tests clt ON clt.id = cal.record_id
                LEFT JOIN health_workers hw ON hw.id = cal.staff_id
                LEFT JOIN residents r ON r.id = cal.resident_id
                WHERE clt.created_by_staff_id = :staff_id
                ORDER BY cal.accessed_at DESC, cal.id DESC
                LIMIT 25
            ");
            $logStmt->execute(['staff_id' => $medtechStaffId]);
            $confidentialAccessLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $supplyRows = $pdo->query("SELECT id, item_name, category, quantity, unit, reorder_level, expiration_date AS expiry_date FROM medicine_inventory WHERE item_type = 'Laboratory' ORDER BY item_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($supplyRows as $row) {
            $status = (int) $row['quantity'] <= 0 ? 'critical' : ((int) $row['quantity'] <= (int) $row['reorder_level'] ? 'low' : 'adequate');
            $supplies[] = [$row['item_name'], $row['category'] ?: 'Laboratory', (int) $row['quantity'], $row['unit'], (int) $row['reorder_level'], $row['expiry_date'], $status, (int)$row['id']];
        }
        $inventoryUsageOptions = $pdo->query("SELECT id, item_name, item_type, category, quantity, unit FROM medicine_inventory ORDER BY item_type, item_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
    }
}

// Usage is recorded atomically with the resident laboratory test.
$kitUsageHistory = [];
$kitUsageHistoryUnavailable = false;
if ($tab === 'supplies' && $pdo instanceof PDO) {
    try {
        inventoryEnsureHistory($pdo);
        $kitUsageHistory = $pdo->query("SELECT m.*, CONCAT_WS(' ', r.first_name, r.last_name) AS resident_name
            FROM inventory_movements m
            LEFT JOIN residents r ON r.id = m.resident_id
            WHERE m.movement_type = 'Used' AND m.service = 'Laboratory' AND m.resident_id IS NOT NULL
            ORDER BY m.used_at DESC, m.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) {
        $kitUsageHistoryUnavailable = true;
        error_log('MedTech kit usage history: ' . $error->getMessage());
    }
}

$today = array_filter($tests, function ($r) { return $r[9] === date('Y-m-d'); });
$abnormal = count(array_filter($tests, function ($r) { return in_array($r[6], ['abnormal', 'notable']); }));
$critical = count(array_filter($supplies, function ($s) { return $s[6] === 'critical'; }));
$low = count(array_filter($supplies, function ($s) { return $s[6] === 'low'; }));
if (($_GET['blood_map_json'] ?? '') === '1') {
    $jsonBloodType = normalizeBloodType((string)($_GET['blood_type'] ?? 'O+')) ?: 'O+';
    $jsonCompatibility = $bloodCompatibility[$jsonBloodType] ?? $bloodCompatibility['O+'];
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'success' => true,
        'blood_type' => $jsonBloodType,
        'residents' => medtechBloodMapPayload($sharedLocationResidents, $jsonCompatibility['receive_from']),
        'generated_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
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
    <title>Medical Technologist Portal — ResiHUnity RHU</title>
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <style>
        html {
            scroll-behavior: auto;
        }

        body.rhu-medtech-ui {
            overflow: hidden;
            background: #f3f6f4;
            color: #0f172a;
            height: 100vh;
            height: 100dvh;
        }

        .medtech-referral-status,
        .medtech-supply-status {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            border: 1px solid;
            font-weight: 800;
        }

        .medtech-referral-status.is-pending { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .medtech-referral-status.is-received { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .medtech-supply-status.is-critical { background: #ffe4e6; color: #9f1239; border-color: #fecdd3; }
        .medtech-supply-status.is-low { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .medtech-supply-status.is-adequate { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .medtech-resident-combobox { position: relative; }
        .medtech-resident-combobox-button {
            min-height: 3rem;
            display: flex;
            width: 100%;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            border: 1px solid #cbd5e1;
            border-radius: .75rem;
            background: #fff;
            padding: .75rem 1rem;
            color: #0f172a;
            font-size: .875rem;
            font-weight: 800;
            text-align: left;
        }
        .medtech-resident-combobox-button:focus {
            border-color: #fb7185;
            box-shadow: 0 0 0 3px rgba(251, 113, 133, .18);
            outline: none;
        }
        .medtech-resident-combobox-label {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .medtech-resident-combobox-panel {
            display: none;
            position: absolute;
            z-index: 45;
            top: calc(100% + .35rem);
            left: 0;
            right: 0;
            max-height: 16rem;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
            border-radius: .875rem;
            background: #fff;
            box-shadow: 0 18px 36px rgba(15, 23, 42, .16);
        }
        .medtech-resident-combobox.is-open .medtech-resident-combobox-panel { display: block; }
        .medtech-resident-combobox-option {
            display: block;
            width: 100%;
            border-bottom: 1px solid #f1f5f9;
            padding: .7rem .85rem;
            color: #0f172a;
            font-size: .875rem;
            font-weight: 700;
            text-align: left;
        }
        .medtech-resident-combobox-option:last-child { border-bottom: 0; }
        .medtech-resident-combobox-option:hover,
        .medtech-resident-combobox-option:focus,
        .medtech-resident-combobox-option.is-selected {
            background: #fff1f2;
            outline: none;
        }
        #blood-compatibility-map,
        #blood-compatibility-map .leaflet-container {
            width: 100%;
            height: 28rem;
            min-height: 28rem;
        }
        .blood-rhu-marker {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border-radius: 9999px;
            border: 3px solid #fff;
            background: #0f766e;
            color: #fff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .28);
            font-size: 10px;
            font-weight: 900;
        }

        .medtech-sidebar {
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

        .medtech-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.25rem;
            padding: 0.9rem 1rem;
            flex-shrink: 0;
        }

        .medtech-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(0.52);
        }

        .medtech-sidebar-brand .brand-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg, rgba(13, 53, 24, 0.92) 0%, rgba(23, 63, 45, 0.82) 55%, rgba(47, 111, 73, 0.7) 100%);
        }

        .admin-shell-header {
            background: #0b3c35;
            border-bottom: 1px solid rgba(10, 51, 43, 0.9);
            box-shadow: 0 10px 28px rgba(2, 28, 23, 0.18);
            min-height: 5rem;
            position: sticky;
            top: 0;
            z-index: 50;
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

        .medtech-main-wrap {
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

        .medtech-main-wrap::before {
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

        .medtech-main-wrap>* {
            position: relative;
            z-index: 1;
        }

        .medtech-main-wrap>header.admin-shell-header {
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0;
        }

        .medtech-main-wrap>main {
            z-index: 1;
            position: relative;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .dashboard-card {
            background: rgba(255, 255, 255, 0.95);
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .dashboard-card:hover {
            border-color: #a7e0bc;
            box-shadow: 0 6px 18px rgba(11, 60, 53, 0.08);
            transform: translateY(-1px);
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-card:hover {
                transform: none;
            }
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14) !important;
        }

        @media (max-width: 1023px) {
            .medtech-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                height: 100vh;
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 40px rgba(15, 23, 42, .18);
            }

            .medtech-sidebar.is-open {
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

            .medtech-sidebar {
                transform: none !important;
            }
        }
    </style>
    <?php if ($tab === 'blood'): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php endif; ?>
    <link rel="stylesheet" href="styles/dashboard-enhancements.css">
    <link rel="stylesheet" href="styles/medtech-dashboard-ui.css?v=<?= (int) filemtime(__DIR__ . '/styles/medtech-dashboard-ui.css') ?>">
<script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(__DIR__ . "/staff-session-lock.js") ?>"></script>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
</head>

<body class="rhu-medtech-ui antialiased">
    <a class="medtech-skip-link" href="#medtech-content">Skip to dashboard content</a>
    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>
        <aside id="medtech-sidebar" data-feature-drawer class="medtech-sidebar shrink-0"
            aria-label="MedTech navigation">
            <div class="medtech-sidebar-brand">
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
if (is_file(__DIR__ . '/includes/error_bootstrap.php')) {
    require_once __DIR__ . '/includes/error_bootstrap.php';
}

                $drawerGroups = [
                    'Dashboard' => ['overview'],
                    'Diagnostics' => ['rapid', 'confidential', 'blood'],
                    'Inventory' => ['supplies'],
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
                        <a href="<?= esc(tabUrl($id)) ?>" class="nav-item <?= $active ? 'is-active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                            <span class="shrink-0 opacity-90"><?= $icon ?></span>
                            <span class="truncate flex-1"><?= esc($label) ?></span>
                            <?php if ($active): ?><span class="text-teal-700 text-sm font-black">→</span><?php endif; ?>
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
        <div class="medtech-main-wrap">
            <header class="admin-shell-header dashboard-header sticky top-0 z-50 text-[#f4faf7]">
                <div class="flex h-20 items-center justify-between gap-3 px-5 sm:px-7">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <button type="button" data-drawer-open
                            class="lg:hidden flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/15 text-white/95 hover:bg-white/10"
                            aria-label="Open menu" aria-expanded="false" aria-controls="medtech-sidebar">
                            <?= iconSvg('menu', 'w-4 h-4') ?>
                        </button>
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full border border-[#e8f3d8]/80 bg-[#dfeecb] text-[#0b3b2f]">
                                <?= iconSvg('shield', 'w-3.5 h-3.5') ?>
                            </span>
                            <span class="text-xs font-black uppercase tracking-[0.16em] text-[#f5f5f2]">MedTech
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
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#dceec4] text-sm font-black text-[#0b3b2f]">
                                <?= esc(strtoupper(substr($_SESSION['rhu_staff_login']['name'] ?? 'M', 0, 1))) ?>
                            </div>
                            <div class="hidden sm:block text-left leading-tight pr-1">
                                <p class="text-[12px] font-bold text-white">
                                    <?= esc($_SESSION['rhu_staff_login']['name'] ?? 'Medical Technologist') ?></p>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-[#cfe5d8]">MedTech</p>
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
            <main id="medtech-content" tabindex="-1" class="flex-1 mx-auto w-full max-w-7xl p-4 sm:p-6 space-y-5 pb-6">
                <section class="medtech-page-heading" aria-labelledby="medtech-page-title">
                    <div>
                        <p class="medtech-eyebrow">ResiHUnity / Laboratory</p>
                        <h1 id="medtech-page-title"><?= $tab === 'overview' ? 'Laboratory overview' : esc($tabs[$tab][0]) ?></h1>
                        <p class="medtech-page-description"><?php
                            $pageDescriptions = [
                                'overview' => 'Your workspace for diagnostic results and laboratory supplies.',
                                'rapid' => 'Record findings and review patient diagnostic results in one place.',
                                'confidential' => 'Restricted HIV and sensitive testing records with private release controls.',
                                'blood' => 'Check blood type compatibility and capture field location for urgent coordination.',
                                'supplies' => 'Review kits and materials used for residents, including quantities, staff, and dates.',
                            ];
                            echo esc($pageDescriptions[$tab]);
                        ?></p>
                    </div>
                    <span class="medtech-workspace-label"><?= iconSvg('shield', 'w-4 h-4') ?> Medical Technologist</span>
                </section>


                <!-- FLASH NOTIFICATIONS -->
                <?php if (!empty($flashSuccess)): ?>
                    <div
                        class="flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50/90 p-4 text-sm font-semibold text-emerald-900 shadow-sm backdrop-blur-sm">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white font-bold"></span>
                            <p><?= esc($flashSuccess) ?></p>
                        </div>
                        <button type="button" onclick="this.parentElement.remove()"
                            class="text-emerald-700 hover:text-emerald-950 font-bold px-2">×</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($flashError)): ?>
                    <div
                        class="flex items-center justify-between rounded-2xl border border-rose-200 bg-rose-50/90 p-4 text-sm font-semibold text-rose-900 shadow-sm backdrop-blur-sm">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-rose-600 text-white font-bold"></span>
                            <p><?= esc($flashError) ?></p>
                        </div>
                        <button type="button" onclick="this.parentElement.remove()"
                            class="text-rose-700 hover:text-rose-950 font-bold px-2">×</button>
                    </div>
                <?php endif; ?>

                <!-- TAB 1: OVERVIEW -->
                <?php if ($tab === 'overview'): ?>
                    <section class="medtech-welcome" aria-label="Laboratory quick actions">
                        <div>
                            <p class="medtech-eyebrow">Patient care starts with clear results</p>
                            <h2>Ready for the next diagnosis.</h2>
                            <p>Record test results and keep laboratory supplies accurate.</p>
                        </div>
                        <div class="medtech-quick-actions">
                            <a class="medtech-action-primary" href="<?= esc(tabUrl('rapid', ['modal' => 'test'])) ?>"><span aria-hidden="true">+</span> Record RDT result</a>
                            <a class="medtech-action-secondary" href="<?= esc(tabUrl('supplies')) ?>">View kit usage <span aria-hidden="true">&rarr;</span></a>
                        </div>
                    </section>

                    <!-- Critical Stock Warning Banner -->
                    <?php if ($critical > 0): ?>
                        <section
                            class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 rounded-2xl border border-rose-300 bg-linear-to-r from-rose-500 via-rose-600 to-red-600 p-5 text-white shadow-lg">
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white/20 text-2xl backdrop-blur-sm"></span>
                                <div>
                                    <h3 class="text-base font-bold tracking-tight"><?= $critical ?> Test Kit / Laboratory Supply
                                        Item(s) Critically Low</h3>
                                    <p class="text-xs text-rose-100 mt-0.5">
                                        Immediate resupply request required for:
                                        <span class="font-bold underline decoration-rose-300">
                                            <?php
                                            $critNames = [];
                                            foreach ($supplies as $s)
                                                if ($s[6] === 'critical')
                                                    $critNames[] = $s[0];
                                            echo esc(implode(' · ', $critNames));
                                            ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <a href="<?= tabUrl('supplies') ?>"
                                class="shrink-0 rounded-xl bg-white px-4 py-2 text-xs font-extrabold text-rose-700 shadow-md hover:bg-rose-50 transition">
                                View Kit Usage →
                            </a>
                        </section>
                    <?php endif; ?>

                    <!-- Quick KPI Summary Widgets -->
                    <section class="medtech-kpis grid grid-cols-2 gap-4 sm:grid-cols-4" aria-label="Laboratory summary">
                        <div
                            class="rounded-2xl border border-violet-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-violet-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Today's RDTs</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-100 text-violet-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-violet-900"><?= count($today) ?></p>
                            <p class="mt-1 text-xs font-semibold text-slate-500">Tests completed today</p>
                        </div>

                        <div
                            class="rounded-2xl border border-rose-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-rose-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Abnormal
                                    Flags</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-100 text-rose-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-rose-600"><?= $abnormal ?></p>
                            <p class="mt-1 text-xs font-semibold text-rose-600/80">Requires physician review</p>
                        </div>

                        <div
                            class="rounded-2xl border border-sky-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-sky-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Supply Items</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-100 text-sky-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-sky-700"><?= count($supplies) ?></p>
                            <p class="mt-1 text-xs font-semibold text-slate-500">Tracked laboratory inventory</p>
                        </div>

                        <div
                            class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-amber-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Low Stock
                                    Kits</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-100 text-amber-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-amber-600"><?= $critical + $low ?></p>
                            <p class="mt-1 text-xs font-semibold text-slate-500"><?= $critical ?> critical · <?= $low ?> low
                            </p>
                        </div>
                    </section>

                    <!-- Live Laboratory Consultation Queue -->
                    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
                        <div
                            class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                            <div>
                                <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                                    <span class="inline-flex text-teal-700"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg></span><span>Laboratory Consultation Queue</span>
                                    <span
                                        class="rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-extrabold text-violet-800 border border-violet-200">
                                        <?= count($medtechConsultations) ?> Pending
                                    </span>
                                </h2>
                                <p class="text-xs text-slate-500 mt-0.5">Assigned patients waiting for diagnostic lab
                                    requests or rapid diagnostic testing.</p>
                            </div>
                            <a href="?tab=rapid&modal=test"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-teal-700 hover:bg-teal-800 px-3.5 py-2 text-xs font-bold text-white shadow-sm transition">
                                <span></span>
                                <span>Record RDT Result</span>
                            </a>
                        </div>

                        <?php if (empty($medtechConsultations)): ?>
                            <div class="py-8 text-center">
                                <span class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
                                <p class="mt-2 text-sm font-semibold text-slate-600">No active laboratory requests assigned in
                                    queue.</p>
                                <p class="text-xs text-slate-400">New diagnostic requests from RHU doctors will appear here in
                                    real time.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident laboratory requests" class="staff-record-scroll staff-record-scroll-list mt-4 divide-y divide-slate-100">
                                <?php foreach ($medtechConsultations as $mc): ?>
                                    <div data-record-search-row
                                        class="py-3.5 flex flex-col space-y-3 bg-slate-50/50 p-3 rounded-xl border border-slate-200/60 mb-2">
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                            <div class="space-y-1">
                                                <div class="flex items-center gap-2">
                                                    <b class="text-sm text-slate-900"><?= esc(rhuPatientLabel($mc['patientName'], $mc['resident_id'] ?? 0)) ?></b>
                                                    <span class="text-xs text-slate-500 font-medium">
                                                        (<?= esc($mc['age'] ?? 'N/A') ?>y • <?= esc($mc['gender'] ?? 'N/A') ?> •
                                                        <?= esc($mc['barangay']) ?>)
                                                    </span>
                                                </div>
                                                <p class="text-xs font-semibold text-violet-700">Complaint / Lab Request:
                                                    <?= esc($mc['chiefComplaint']) ?></p>
                                                <p class="text-[11px] text-slate-400 font-mono"> Requested Date:
                                                    <?= esc($mc['date']) ?></p>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span
                                                    class="rounded-lg bg-amber-50 px-2.5 py-1 text-[11px] font-extrabold text-amber-700 border border-amber-200">
                                                    Status: <?= esc($mc['consultation_status']) ?>
                                                </span>
                                                <a href="<?= esc(tabUrl('rapid', ['modal' => 'test', 'consultation_id' => (int)($mc['id'] ?? 0), 'resident_id' => (int)($mc['resident_id'] ?? 0), 'appointment_date' => $mc['date'] ?? '', 'appointment_time' => $mc['time'] ?? ''])) ?>"
                                                    class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-bold text-teal-700 hover:bg-teal-600 hover:text-white transition">
                                                    Perform RDT →
                                                </a>
                                            </div>
                                        </div>

                                        <!-- MEDTECH RESPONSE / UPDATE FORM -->
                                        <details class="group border-t border-teal-100 pt-2" open>
                                            <summary
                                                class="cursor-pointer text-xs font-bold text-teal-700 hover:text-teal-900 flex items-center justify-between py-1">
                                                <span> Answer / Update Diagnostic Test Response for Resident</span>
                                            </summary>
                                            <form method="post"
                                                class="mt-2 bg-white p-3 rounded-xl border border-teal-200/70 space-y-2.5"><input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="answer_consultation">
                                                <input type="hidden" name="consultation_id" value="<?= (int) $mc['id']; ?>">
                                                <input type="hidden" name="resident_id"
                                                    value="<?= (int) ($mc['resident_id'] ?? 0); ?>">
                                                <input type="hidden" name="appointment_date" value="<?= esc($mc['date'] ?? '') ?>">
                                                <input type="hidden" name="appointment_time" value="<?= esc($mc['time'] ?? '') ?>">

                                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                    <div>
                                                        <label class="block text-[11px] font-bold text-slate-700 mb-0.5">Diagnostic
                                                            Result Summary</label>
                                                        <input type="text" name="diagnosis"
                                                            value="<?= esc($mc['diagnosis'] ?? ''); ?>"
                                                            placeholder="e.g. Fasting Blood Sugar: 95 mg/dL (Normal)"
                                                            class="w-full p-2 border border-slate-300 rounded-lg text-xs outline-none focus:border-teal-500 bg-white"
                                                            required>
                                                    </div>
                                                    <div>
                                                        <label
                                                            class="block text-[11px] font-bold text-slate-700 mb-0.5">Status</label>
                                                        <select name="consultation_status"
                                                            class="w-full p-2 border border-slate-300 rounded-lg text-xs outline-none focus:border-teal-500 bg-white font-bold text-teal-900">
                                                            <option value="Completed" <?= ($mc['consultation_status'] ?? '') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                            <option value="In Progress" <?= ($mc['consultation_status'] ?? '') === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                            <option value="Scheduled" <?= ($mc['consultation_status'] ?? '') === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                            <option value="Referred" <?= ($mc['consultation_status'] ?? '') === 'Referred' ? 'selected' : ''; ?>>Referred to Doctor</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-700 mb-0.5">MedTech Remarks
                                                        &amp; Lab Notes for Resident</label>
                                                    <textarea name="consultation_notes" rows="2"
                                                        placeholder="Enter laboratory testing details, specimen notes, or collection instructions..."
                                                        class="w-full p-2 border border-slate-300 rounded-lg text-xs outline-none focus:border-violet-500 bg-white resize-none"><?= esc($mc['consultation_notes'] ?? ''); ?></textarea>
                                                </div>

                                                <div class="flex justify-end pt-1">
                                                    <button type="submit"
                                                        class="px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white text-xs font-extrabold rounded-lg shadow-sm transition flex items-center gap-1">
                                                        <span></span> Save Response &amp; Notify Resident
                                                    </button>
                                                </div>
                                            </form>
                                        </details>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- RHU Rapid Diagnostic Capabilities -->
                    <section
                        class="rounded-2xl border border-violet-200/70 bg-linear-to-br from-violet-50 via-indigo-50/40 to-slate-50 p-6 shadow-sm">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="text-lg">▣</span>
                            <h3 class="text-sm font-bold text-violet-950 uppercase tracking-wide">Available On-Site Rapid
                                Diagnostic Tests (RDTs)</h3>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $rdtCapabilities = [
                                'Blood Glucose (Glucometer)',
                                'Urinalysis (10-Parameter Dipstick)',
                                'HCG Pregnancy Test (Urine)',
                                'HBsAg Rapid Test (Hepatitis B)',
                                'HIV 1/2 Rapid Antibody',
                                'Syphilis (VDRL/RPR) Rapid',
                                'Dengue NS1 Antigen & IgG/IgM RDT',
                                'Malaria Ag Rapid Diagnostic Test',
                                'TB Sputum GeneXpert / AFB Collection'
                            ];
                            foreach ($rdtCapabilities as $capability):
                                ?>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-violet-200 bg-white px-3 py-1.5 text-xs font-bold text-violet-800 shadow-sm hover:border-violet-400 transition">
                                    <span class="w-1.5 h-1.5 rounded-full bg-violet-600"></span>
                                    <?= $capability ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <p class="mt-3 text-xs text-violet-700/80 italic">
                            Note: Complex automated chemistry, full CBC counter, and bacterial cultures are processed via
                            tests outside RHU on-site capacity should follow the facility's approved external laboratory process.
                        </p>
                    </section>

                    <!-- Today's RDT Results Widget -->
                    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <span>▤ Today's Completed Diagnostic Tests</span>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600"><?= count($today) ?>
                                    Total</span>
                            </h3>
                            <a href="<?= tabUrl('rapid') ?>" class="text-xs font-bold text-violet-700 hover:underline">View
                                All Test Logs →</a>
                        </div>

                        <?php if (empty($today)): ?>
                            <p class="text-xs text-slate-400 italic py-4 text-center">No diagnostic test results recorded for
                                today yet.</p>
                        <?php else: ?>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <?php foreach ($today as $r): ?>
                                    <div
                                        class="flex items-start gap-3 rounded-xl border <?= in_array($r[6], ['abnormal', 'notable']) ? 'border-rose-200 bg-rose-50/50' : 'border-slate-100 bg-slate-50/60' ?> p-3.5 shadow-2xs">
                                        <span
                                            class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full <?= in_array($r[6], ['abnormal', 'notable']) ? 'bg-rose-500 ' : 'bg-emerald-500' ?>"></span>
                                        <div class="min-w-0 flex-1 space-y-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <b class="truncate text-xs text-slate-900 font-bold"><?= esc(rhuPatientLabel($r[1], $r['resident_id'] ?? 0)) ?></b>
                                                <span
                                                    class="rounded-md border px-2 py-0.5 text-[10px] font-extrabold <?= flagClass($r[6]) ?>">
                                                    <?= ucfirst($r[6]) ?>
                                                </span>
                                            </div>
                                            <p class="text-xs font-semibold text-violet-700"><?= esc($r[4]) ?></p>
                                            <p class="text-xs font-bold text-slate-800">Result: <?= esc($r[5]) ?></p>
                                            <p class="text-[10px] text-slate-400">Ordered by: <?= esc($r[8]) ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- TAB 2: RAPID DIAGNOSTIC TESTS -->
                <?php elseif ($tab === 'rapid'): ?>


                    <div
                        class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div>
                            <h2 class="text-base sm:text-lg font-bold text-slate-800">Rapid Diagnostic Test (RDT) Log</h2>
                            <p class="text-xs text-slate-500 font-medium">Manage on-site rapid laboratory test findings and
                                patient diagnostic reports.</p>
                        </div>
                        <a href="<?= esc(tabUrl('rapid', ['modal' => 'test'])); ?>"
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg> Record RDT Result
                        </a>
                    </div>


                    <!-- MODAL: RECORD RDT RESULT -->


                    <!-- Critical Clinical Notice -->
                    <div
                        class="rounded-2xl border border-amber-200 bg-amber-50/90 p-4 text-xs font-semibold text-amber-900 flex items-center gap-3">
                        <span class="text-base"></span>
                        <p>Clinical Directive: All abnormal, positive, or critical diagnostic results must be immediately
                            communicated to the attending MHO physician or requesting nurse for rapid clinical intervention.
                        </p>
                    </div>

                    <!-- Test Search Bar -->
                    <div class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-2xs">
                        <span class="text-slate-400"></span>
                        <input type="text" id="rdtSearchInput" onkeyup="filterRDTs()" aria-label="Search diagnostic test results"
                            placeholder="Search test results by patient name, test type, or status..."
                            class="w-full text-xs outline-none bg-transparent">
                    </div>

                    <!-- RDT Cards List -->
                    <div id="rdtListContainer" class="space-y-3">
                        <?php if (empty($tests)): ?>
                            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                                <span class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
                                <p class="mt-2 text-sm font-bold text-slate-700">No RDT entries logged yet.</p>
                                <p class="text-xs text-slate-400">Click "Record RDT Result" to log a new diagnostic finding.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($tests as $r): ?>
                                <article
                                    class="rdt-item rounded-2xl border <?= in_array(strtolower((string)$r[6]), ['abnormal', 'notable', 'positive'], true) ? 'border-rose-200 bg-rose-50/30' : 'border-slate-200/80 bg-white' ?> p-4 shadow-sm hover:shadow-md transition overflow-hidden">
                                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                        <div class="space-y-2 min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span
                                                    class="max-w-full rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[11px] font-bold text-slate-600 border border-slate-200 break-words"><?= esc($r[0]) ?></span>
                                                <span
                                                    class="rounded-full border px-2.5 py-0.5 text-xs font-extrabold <?= flagClass(strtolower((string)$r[6])) ?>">
                                                    <?= esc(ucfirst((string)$r[6])) ?>
                                                </span>
                                                <span class="text-xs text-slate-400"> <?= esc($r[9]) ?></span>
                                            </div>
                                            <h3 class="text-sm font-bold text-slate-900 mt-1">
                                                <?= esc(rhuPatientLabel($r[1], $r['resident_id'] ?? 0)) ?>, <span class="font-medium text-slate-600"><?= $r[2] ?> yrs ·
                                                    <?= esc($r[3]) ?></span>
                                            </h3>
                                            <p class="text-xs font-bold text-violet-700 break-words"><?= esc($r[4]) ?></p>
                                            <p
                                                class="rounded-xl border border-teal-100 bg-teal-50/40 p-3 text-sm font-extrabold <?= strtolower((string)$r[6]) === 'normal' || strtolower((string)$r[6]) === 'completed' ? 'text-slate-800' : 'text-rose-600' ?>">
                                                <span class="block text-[11px] font-black uppercase tracking-wide text-slate-500">Result / Findings</span>
                                                <span class="mt-1 block break-words"><?= esc($r[7]) ?></span>
                                                <span class="mt-1 block text-xs font-normal text-slate-400">Test type:
                                                    <?= esc($r[5]) ?></span>
                                            </p>
                                        </div>
                                        <div class="w-full sm:w-56 text-left sm:text-right shrink-0 text-xs text-slate-500 rounded-xl border border-slate-100 bg-slate-50/70 p-3">
                                            <p class="font-semibold text-slate-700">Recorded by:</p>
                                            <p><?= esc($r[8]) ?></p>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <script>
                        function filterRDTs() {
                            const query = document.getElementById('rdtSearchInput').value.toLowerCase();
                            const items = document.querySelectorAll('.rdt-item');
                            items.forEach(item => {
                                const text = item.textContent.toLowerCase();
                                item.style.display = text.includes(query) ? '' : 'none';
                            });
                        }
                    </script>

                    <!-- TAB 3: SPECIMEN REFERRALS -->
                <?php elseif (false && $tab === 'referrals'): ?>


                    <div
                        class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div>
                            <h2 class="text-base sm:text-lg font-bold text-slate-800">Specimen Referrals &amp; External
                                Testing</h2>
                            <p class="text-xs text-slate-500 font-medium">Track diagnostic specimens sent to higher-level
                                partner hospital laboratories.</p>
                        </div>
                        <a href="<?= esc(tabUrl('referrals', ['modal' => 'referral'])); ?>"
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg> New Referral
                        </a>
                    </div>


                    <!-- MODAL: NEW REFERRAL -->


                    <div class="grid grid-cols-3 gap-4">
                        <div class="rounded-2xl border border-amber-100 bg-white p-4 text-center shadow-sm">
                            <b class="text-2xl font-black text-amber-600"><?= $pending ?></b>
                            <p class="text-xs font-bold text-slate-500">Pending Results</p>
                        </div>
                        <div class="rounded-2xl border border-emerald-100 bg-white p-4 text-center shadow-sm">
                            <b class="text-2xl font-black text-emerald-600"><?= count($referrals) - $pending ?></b>
                            <p class="text-xs font-bold text-slate-500">Results Received</p>
                        </div>
                        <div class="rounded-2xl border border-sky-100 bg-white p-4 text-center shadow-sm">
                            <b class="text-2xl font-black text-sky-700"><?= count($referrals) ?></b>
                            <p class="text-xs font-bold text-slate-500">Total Referrals</p>
                        </div>
                    </div>

                    <div data-staff-record-scroll="Laboratory referral records" class="staff-record-scroll staff-record-scroll-list space-y-3">
                        <?php if (empty($referrals)): ?>
                            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                                <span class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
                                <p class="mt-2 text-sm font-bold text-slate-700">No specimen referrals logged.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($referrals as $r): ?>
                                <article data-record-search-row
                                    class="rounded-2xl border <?= $r[6] === 'pending' ? 'border-amber-200 bg-amber-50/20' : 'border-slate-200/80 bg-white' ?> p-4 shadow-sm hover:shadow-md transition">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs font-bold text-slate-600 border border-slate-200"><?= $r[0] ?></span>
                                            <span
                                                class="medtech-referral-status px-2.5 py-0.5 text-xs <?= $r[6] === 'pending' ? 'is-pending' : 'is-received' ?>">
                                                <?= $r[6] === 'pending' ? 'Pending External Result' : ' Result Received' ?>
                                            </span>
                                        </div>
                                        <span class="text-xs text-slate-400"> Date: <?= $r[5] ?></span>
                                    </div>
                                    <div class="mt-3 space-y-1">
                                        <b class="text-sm text-slate-900"><?= esc(rhuPatientLabel($r[1], $r['resident_id'] ?? $r[10] ?? 0)) ?>, <?= $r[2] ?> yrs</b>
                                        <p class="text-xs font-bold text-sky-700">Requested: <?= esc($r[3]) ?></p>
                                        <p class="text-xs font-semibold text-slate-600">→ Destination Facility: <?= esc($r[4]) ?>
                                        </p>
                                        <?php if ($r[6] !== 'pending'): ?>
                                            <div
                                                class="mt-2 rounded-xl bg-emerald-50 p-2.5 text-xs font-bold text-emerald-800 border border-emerald-200">
                                                Result Findings: <?= esc($r[7]) ?>
                                            </div>
                                        <?php endif; ?>
                                        <p class="text-[11px] text-slate-400 mt-1">Notes: <?= esc($r[8]) ?></p>
                                        <?php if ($r[6] === 'pending'): ?>
                                        <details class="mt-3"><summary class="cursor-pointer font-bold text-sm text-teal-700">Receive external results</summary>
                                            <form method="post" class="grid gap-3 mt-3"><input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="receive_lab_referral">
                                                <input type="hidden" name="referral_id" value="<?= (int)$r[9] ?>">
                                                <input type="hidden" name="resident_id" value="<?= (int)$r[10] ?>">
                                                <input type="hidden" name="appointment_date" value="<?= esc($r[11]) ?>">
                                                <input type="hidden" name="appointment_time" value="<?= esc($r[12]) ?>">
                                                <label class="text-sm">Received date<input type="date" name="received_date" required value="<?= date('Y-m-d') ?>" class="block rounded-lg border p-2"></label>
                                                <label class="text-sm">Laboratory findings<textarea name="external_results" required class="block w-full rounded-lg border p-2"></textarea></label>
                                                <button class="rounded-lg bg-teal-700 px-4 py-2 text-white">Save received results</button>
                                            </form>
                                        </details>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 4: CONFIDENTIAL TESTING -->
                <?php elseif ($tab === 'confidential'): ?>
                    <?php if (!medtechCanAccessConfidentialTesting()): ?>
                        <section class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm font-bold text-rose-900">
                            You are not authorized to access confidential testing records.
                        </section>
                    <?php else: ?>
                        <section class="rounded-2xl border border-rose-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-col gap-3 border-b border-rose-100 pb-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <h2 class="text-base font-black text-slate-950">Confidential HIV / Sensitive Testing</h2>
                                    <p class="mt-1 max-w-3xl text-xs font-semibold text-slate-600">Restricted workflow. Records here are separated from ordinary laboratory reports, disease surveillance, global search, and staff dashboards. Do not disclose results outside authorized counseling/release.</p>
                                </div>
                                <span class="w-fit rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-[11px] font-black text-rose-800">Private Access</span>
                            </div>

                            <form method="post" class="mt-4 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs lg:grid-cols-2"><input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>">
                                <input type="hidden" name="action" value="save_confidential_test">
                                <div>
                                    <label class="mb-1 block font-bold text-slate-700">Resident *</label>
                                    <div class="medtech-resident-combobox" data-medtech-resident-combobox>
                                        <input type="hidden" name="resident_id" data-medtech-resident-value>
                                        <button type="button" class="medtech-resident-combobox-button" data-medtech-resident-button aria-haspopup="listbox" aria-expanded="false">
                                            <span class="medtech-resident-combobox-label" data-medtech-resident-label>-- Select Resident --</span>
                                            <svg class="h-4 w-4 shrink-0 text-slate-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        <div class="medtech-resident-combobox-panel" data-medtech-resident-panel role="listbox">
                                            <?php foreach ($residentOptions as $resident): ?>
                                                <button type="button" class="medtech-resident-combobox-option" data-medtech-resident-option data-value="<?= (int)($resident['id'] ?? 0) ?>" role="option">
                                                    <?= esc($resident['name'] ?? 'Resident') ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block font-bold text-slate-700">Test Type *</label>
                                    <select name="test_type" required class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-bold outline-none focus:border-rose-400">
                                        <option>HIV Screening</option>
                                        <option>Syphilis Screening</option>
                                        <option>Hepatitis B Screening</option>
                                        <option>HIV Confirmatory Referral</option>
                                        <option>STI Screening</option>
                                        <option>Other Confidential Test</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block font-bold text-slate-700">Specimen Date</label>
                                    <input type="date" name="specimen_date" value="<?= esc(date('Y-m-d')) ?>" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-bold outline-none focus:border-rose-400">
                                </div>
                                <div>
                                    <label class="mb-1 block font-bold text-slate-700">Private Release Date</label>
                                    <input type="date" name="release_date" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-bold outline-none focus:border-rose-400">
                                </div>
                                <div>
                                    <label class="mb-1 block font-bold text-slate-700">Result Status *</label>
                                    <select name="result_status" required class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-bold outline-none focus:border-rose-400">
                                        <option>Pending</option>
                                        <option>For Counseling</option>
                                        <option>Released Privately</option>
                                        <option>Referred</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block font-bold text-slate-700">Handled By</label>
                                    <input readonly value="<?= esc($medtechDisplayName) ?>" class="w-full cursor-default rounded-xl border border-slate-300 bg-slate-100 p-3 text-sm font-bold text-slate-700 outline-none">
                                </div>
                                <div>
                                    <label class="mb-1 block font-bold text-slate-700">Result Details</label>
                                    <input name="result_text" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-bold outline-none focus:border-rose-400" placeholder="Visible only to authorized personnel">
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="mb-1 block font-bold text-slate-700">Confidential Notes</label>
                                    <textarea name="confidential_notes" rows="3" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm outline-none focus:border-rose-400" placeholder="Counseling/referral notes. Encrypted at rest."></textarea>
                                </div>
                                <?php fhsisScreeningFormFields(); ?>
                                <div class="flex justify-end lg:col-span-2">
                                    <button type="submit" class="rounded-xl bg-rose-700 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-rose-800">Save confidential record</button>
                                </div>
                            </form>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-4 flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
                                <div>
                                    <h3 class="text-sm font-black text-slate-950">Restricted Records</h3>
                                    <p class="mt-0.5 text-xs font-semibold text-slate-500">Only confidential examinations recorded by your medtech account are shown here.</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-black text-slate-700"><?= count($confidentialTests) ?> record<?= count($confidentialTests) === 1 ? '' : 's' ?></span>
                            </div>
                            <?php if (empty($confidentialTests)): ?>
                                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm font-bold text-slate-600">No confidential testing records yet.</div>
                            <?php else: ?>
                                <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3">
                                    <?php foreach ($confidentialTests as $record): ?>
                                        <article data-record-search-row class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                                <div>
                                                    <p class="text-sm font-black text-slate-950"><?= esc(rhuPatientLabel($record['resident_name'] ?? 'Resident', $record['resident_id'] ?? 0)) ?> <span class="text-xs font-bold text-slate-500">/ <?= esc($record['barangay'] ?? 'Unassigned') ?></span></p>
                                                    <p class="mt-1 text-xs font-bold text-rose-800"><?= esc($record['test_type'] ?? 'Confidential Test') ?> · <?= esc($record['result_status'] ?? 'Pending') ?></p>
                                                </div>
                                                <span class="w-fit rounded-full border border-slate-200 bg-white px-3 py-1 text-[11px] font-black text-slate-700">Specimen: <?= esc($record['specimen_date'] ?? 'N/A') ?></span>
                                            </div>
                                            <div class="mt-3 grid grid-cols-1 gap-2 text-xs md:grid-cols-2">
                                                <div class="rounded-xl border border-white bg-white p-3">
                                                    <p class="font-black uppercase tracking-wide text-slate-500">Private Result</p>
                                                    <p class="mt-1 font-bold text-slate-900"><?= esc($record['result_text'] ?: 'No result details recorded') ?></p>
                                                </div>
                                                <div class="rounded-xl border border-white bg-white p-3">
                                                    <p class="font-black uppercase tracking-wide text-slate-500">Counseling / Notes</p>
                                                    <p class="mt-1 whitespace-pre-line font-semibold text-slate-900"><?= esc($record['confidential_notes'] ?: 'No notes recorded') ?></p>
                                                </div>
                                            </div>
                                            <p class="mt-2 text-[11px] font-semibold text-slate-500">Counselor: <?= esc($record['counselor_name'] ?? 'Assigned personnel') ?> · Released: <?= esc($record['release_date'] ?? 'Not released') ?></p>
                                            <form method="post" class="mt-3"><input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>"><input type="hidden" name="action" value="approve_fhsis_screening"><input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>">
                                                <label class="block text-xs font-bold">Exact screening type, if clarifying an older generic record<select name="fhsis_test_type" class="mt-1 rounded border p-2"><option value="">Keep recorded test type</option><option>HIV Screening</option><option>Syphilis Screening</option><option>Hepatitis B Screening</option></select></label>
                                                <?php fhsisScreeningFormFields(); ?><button class="mt-2 rounded bg-rose-700 px-3 py-2 text-xs text-white">Approve / update reporting facts</button>
                                            </form>
                                            <form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>"><input type="hidden" name="action" value="withdraw_fhsis_screening"><input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>"><button class="rounded border px-3 py-2 text-xs">Withdraw reporting approval</button></form>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="mb-3 text-sm font-black text-slate-950">Recent Confidential Access Log</h3>
                            <?php if (empty($confidentialAccessLogs)): ?>
                                <p class="rounded-xl bg-slate-50 p-4 text-xs font-bold text-slate-500">No access log entries yet.</p>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[720px] text-left text-xs">
                                        <thead class="border-b border-slate-100 text-[11px] uppercase tracking-wide text-slate-500">
                                            <tr><th class="px-3 py-2">Time</th><th class="px-3 py-2">Staff</th><th class="px-3 py-2">Resident</th><th class="px-3 py-2">Action</th><th class="px-3 py-2">IP</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($confidentialAccessLogs as $log): ?>
                                                <tr><td class="px-3 py-2 font-mono text-slate-600"><?= esc($log['accessed_at'] ?? '') ?></td><td class="px-3 py-2 font-bold text-slate-900"><?= esc($log['staff_name'] ?? ('Staff #' . ($log['staff_id'] ?? ''))) ?></td><td class="px-3 py-2 font-semibold text-slate-700"><?= esc($log['resident_name'] ?? ('Resident #' . ($log['resident_id'] ?? ''))) ?></td><td class="px-3 py-2 text-slate-700"><?= esc($log['action'] ?? '') ?></td><td class="px-3 py-2 font-mono text-slate-500"><?= esc($log['ip_address'] ?? '') ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <!-- TAB 5: BLOOD COMPATIBILITY & GEOLOCATION -->
                <?php elseif ($tab === 'blood'): ?>
                    <?php
                    $selectedBloodType = normalizeBloodType((string)($_GET['blood_type'] ?? 'O+')) ?: 'O+';
                    $selectedCompatibility = $bloodCompatibility[$selectedBloodType] ?? $bloodCompatibility['O+'];
                    $compatibleDonorTypes = $selectedCompatibility['receive_from'];
                    $compatibleRecipientTypes = $selectedCompatibility['donate_to'];
                    $compatibleResidents = array_values(array_filter($bloodLocatorResidents, static fn($resident) => in_array((string)$resident['blood_type'], $compatibleDonorTypes, true)));
                    $bloodByBarangay = [];
                    foreach ($compatibleResidents as $resident) {
                        $barangay = trim((string)($resident['barangay'] ?? '')) ?: 'Unassigned barangay';
                        $bloodByBarangay[$barangay] = ($bloodByBarangay[$barangay] ?? 0) + 1;
                    }
                    arsort($bloodByBarangay);
                    $bloodMapResidents = medtechBloodMapPayload($sharedLocationResidents, $compatibleDonorTypes);
                    ?>
                    <section class="rounded-2xl border border-rose-100 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h2 class="text-lg font-black text-slate-900">Blood Type Compatibility &amp; Location</h2>
                                <p class="mt-1 text-xs font-medium text-slate-500">Use this as a screening aid for urgent coordination. Confirm eligibility, consent, and clinical screening before any blood-related action.</p>
                            </div>
                            <form method="get" class="flex flex-wrap items-end gap-3">
                                <input type="hidden" name="tab" value="blood">
                                <label class="text-xs font-bold text-slate-700">Recipient blood type
                                    <select name="blood_type" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-black text-slate-900">
                                        <?php foreach (array_keys($bloodCompatibility) as $bloodType): ?>
                                            <option value="<?= esc($bloodType) ?>" <?= $selectedBloodType === $bloodType ? 'selected' : '' ?>><?= esc($bloodType) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="rounded-xl bg-teal-700 px-4 py-2 text-xs font-bold text-white hover:bg-teal-800">Check compatibility</button>
                            </form>
                        </div>
                    </section>

                    <section class="grid gap-4 lg:grid-cols-3">
                        <article class="rounded-2xl border border-rose-100 bg-rose-50/60 p-5">
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-rose-500">Recipient</p>
                            <h3 class="mt-2 text-4xl font-black text-rose-700"><?= esc($selectedBloodType) ?></h3>
                            <p class="mt-2 text-xs font-semibold text-rose-900">Can receive red cells from:</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <?php foreach ($compatibleDonorTypes as $type): ?>
                                    <span class="rounded-full border border-rose-200 bg-white px-3 py-1 text-sm font-black text-rose-700"><?= esc($type) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </article>
                        <article class="rounded-2xl border border-teal-100 bg-teal-50/60 p-5">
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-teal-600">Donation match</p>
                            <h3 class="mt-2 text-lg font-black text-teal-900"><?= count($compatibleResidents) ?> recorded compatible resident(s)</h3>
                            <p class="mt-2 text-xs font-semibold text-teal-900">A <?= esc($selectedBloodType) ?> resident can donate to:</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <?php foreach ($compatibleRecipientTypes as $type): ?>
                                    <span class="rounded-full border border-teal-200 bg-white px-3 py-1 text-sm font-black text-teal-700"><?= esc($type) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </article>
                        <article class="rounded-2xl border border-sky-100 bg-sky-50/70 p-5">
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-600">Resident map</p>
                            <h3 class="mt-2 text-lg font-black text-slate-900">Compatible shared locations</h3>
                            <p class="mt-2 min-h-10 text-xs font-semibold leading-5 text-slate-600">The map is pinned to RHU Nasugbu and updates automatically when compatible residents share location.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="#blood-compatibility-map" class="rounded-xl bg-sky-700 px-4 py-2 text-xs font-bold text-white hover:bg-sky-800">View map</a>
                                <span class="rounded-xl border border-sky-200 bg-white px-4 py-2 text-xs font-bold text-sky-700"><?= count($bloodMapResidents) ?> shared location(s)</span>
                            </div>
                        </article>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-sm font-black text-slate-900">Compatible Resident Location Map</h3>
                                <p class="text-xs text-slate-500">RHU is pinned at the center. Only compatible residents with shared device GPS appear here and update automatically.</p>
                            </div>
                            <span id="blood-map-count" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-700"><?= count($bloodMapResidents) ?> shared location(s)</span>
                        </div>
                        <div id="blood-compatibility-map" class="mt-4 h-[28rem] overflow-hidden rounded-2xl border border-slate-200 bg-slate-100"></div>
                        <p id="blood-map-status" class="mt-3 text-xs font-semibold text-slate-500">RHU is pinned at the center; residents appear after they enable location sharing in Resident Dashboard.</p>
                    </section>

                    <section class="grid gap-4 lg:grid-cols-[1.2fr_.8fr]">
                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-black text-slate-900">Compatible Resident Blood Type Records</h3>
                                    <p class="text-xs text-slate-500">Filtered by blood type only. Barangay is shown for coordination, not automatic eligibility.</p>
                                </div>
                                <input id="blood-resident-search" type="search" placeholder="Search name, barangay, blood type..." class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold outline-none">
                            </div>
                            <div id="blood-resident-list" class="mt-4 max-h-[34rem] space-y-3 overflow-y-auto pr-1">
                                <?php if (!$compatibleResidents): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm font-semibold text-slate-500">No compatible blood type records are currently saved for <?= esc($selectedBloodType) ?>.</div>
                                <?php else: ?>
                                    <?php foreach ($compatibleResidents as $resident): ?>
                                        <article class="blood-resident-row rounded-2xl border border-slate-100 bg-slate-50/80 p-4" data-resident-id="<?= (int)($resident['id'] ?? 0) ?>" data-search="<?= esc(strtolower(($resident['id'] ?? '') . ' ' . ($resident['resident_name'] ?? '') . ' ' . ($resident['barangay'] ?? '') . ' ' . ($resident['blood_type'] ?? ''))) ?>">
                                            <div class="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h4 class="text-sm font-black text-slate-900"><?= esc(rhuPatientLabel($resident['resident_name'], $resident['id'] ?? 0)) ?></h4>
                                                    <p class="mt-1 text-xs font-semibold text-slate-500"><?= esc($resident['barangay'] ?: 'Unassigned barangay') ?><?= !empty($resident['address']) ? ' - ' . esc($resident['address']) : '' ?></p>
                                                    <p class="mt-1 text-[11px] font-medium text-slate-400">Last blood type update: <?= esc(substr((string)($resident['recorded_at'] ?? ''), 0, 10) ?: 'Not dated') ?></p>
                                                    <p class="mt-1 text-[11px] font-bold <?= !empty($resident['latitude']) && !empty($resident['longitude']) ? 'text-teal-600' : 'text-slate-400' ?>">
                                                        <?= !empty($resident['latitude']) && !empty($resident['longitude']) ? 'Location shared from resident device' : 'No resident device location shared yet' ?>
                                                    </p>
                                                </div>
                                                <span class="rounded-full bg-rose-100 px-3 py-1 text-sm font-black text-rose-700"><?= esc($resident['blood_type']) ?></span>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </article>

                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="text-sm font-black text-slate-900">Barangay Summary</h3>
                            <p class="mt-1 text-xs text-slate-500">Compatible records grouped by resident barangay.</p>
                            <div class="mt-4 space-y-3">
                                <?php if (!$bloodByBarangay): ?>
                                    <p class="rounded-xl bg-slate-50 p-4 text-xs font-semibold text-slate-500">No barangay summary available.</p>
                                <?php else: ?>
                                    <?php foreach ($bloodByBarangay as $barangay => $count): ?>
                                        <div class="flex items-center justify-between rounded-xl bg-slate-50 p-3 text-xs">
                                            <span class="font-bold text-slate-700"><?= esc($barangay) ?></span>
                                            <span class="rounded-full bg-teal-100 px-2.5 py-1 font-black text-teal-800"><?= (int)$count ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    </section>

                    <!-- TAB 5: TEST SUPPLIES -->
                <?php elseif ($tab === 'supplies'): ?>


                    <div
                        class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div>
                            <h2 class="text-base sm:text-lg font-bold text-slate-800">Kit &amp; Material Usage History</h2>
                            <p class="text-xs text-slate-500 font-medium">Kits and materials used for residents, recorded automatically when laboratory tests are saved. Latest usage appears first.</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[900px] text-xs">
                            <thead class="bg-slate-50 text-left uppercase text-slate-500 border-b border-slate-200">
                                <tr>
                                    <th class="p-3.5 font-extrabold">Used On</th>
                                    <th class="p-3.5 font-extrabold">Kit / Material</th>
                                    <th class="p-3.5 font-extrabold">Quantity Used</th>
                                    <th class="p-3.5 font-extrabold">Resident</th>
                                    <th class="p-3.5 font-extrabold">Recorded By</th>
                                    <th class="p-3.5 font-extrabold">Location</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!$kitUsageHistory): ?>
                                    <tr><td colspan="6" class="medtech-empty-state"><?= $kitUsageHistoryUnavailable ? 'Usage history is temporarily unavailable. Please reload the page.' : 'No kit usage recorded for residents yet. Saving a laboratory test with materials used will add records here automatically.' ?></td></tr>
                                <?php endif; ?>
                                <?php foreach ($kitUsageHistory as $usage): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="p-3.5 text-slate-600 font-mono"><?= esc($usage['used_at']) ?></td>
                                        <td class="p-3.5 font-bold text-slate-900"><?= esc($usage['item_name']) ?></td>
                                        <td class="p-3.5 font-bold text-slate-900"><?= abs((int)$usage['quantity_change']) ?> <?= esc($usage['unit']) ?></td>
                                        <td class="p-3.5 text-slate-600"><?= esc(rhuPatientLabel(trim((string)$usage['resident_name']) ?: 'Resident', (int)$usage['resident_id'])) ?></td>
                                        <td class="p-3.5 text-slate-600"><?= esc($usage['staff_name']) ?></td>
                                        <td class="p-3.5 text-slate-600"><?= esc($usage['location']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </main>
        </div>
    </div>


    <!-- MOBILE BOTTOM NAVIGATION -->

    </div>

    <?php if ($modal === 'test'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div
                    class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Record Rapid Diagnostic Test</h2>
                        <p class="text-xs text-slate-500">Log RDT result and notify the resident</p>
                    </div>
                    <a href="<?= esc(tabUrl('rapid')); ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post"><input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_test">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Patient Consultation *</label>
                        <select required name="consultation_id" data-resident-search
                            class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">Select Patient Consultation...</option>
                            <?php foreach ($consultationOptions as $o): ?>
                                <?php $optionSelected = ((int)($o['id'] ?? 0) > 0 && (int)($o['id'] ?? 0) === (int)($_GET['consultation_id'] ?? 0)) || ((int)($o['id'] ?? 0) === 0 && (int)($o['resident_id'] ?? 0) === (int)($_GET['resident_id'] ?? 0) && ($o['consultation_date'] ?? '') === ($_GET['appointment_date'] ?? '') && ($o['consultation_time'] ?? '') === ($_GET['appointment_time'] ?? '')); ?>
                                <option value="<?= (int) $o['id'] ?>" <?= $optionSelected ? 'selected' : '' ?> data-resident-id="<?= (int)($o['resident_id'] ?? 0) ?>" data-appointment-date="<?= esc($o['consultation_date'] ?? '') ?>" data-appointment-time="<?= esc($o['consultation_time'] ?? '') ?>" data-consultation-text="<?= esc(strtolower((string)($o['chief_complaint'] ?? '') . ' ' . (string)($o['patient'] ?? ''))) ?>"><?= esc(rhuPatientLabel($o['patient'], $o['resident_id'] ?? 0)) ?> —
                                    <?= esc($o['chief_complaint']) ?></option>
                            <?php endforeach; ?>
                        </select>
                            <input type="hidden" name="resident_id" id="medtech-test-resident-id" value="">
                            <input type="hidden" name="appointment_date" id="medtech-test-appointment-date" value="">
                            <input type="hidden" name="appointment_time" id="medtech-test-appointment-time" value="">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Name / Kit *</label>
                            <select required name="test_name" id="medtech-test-kit-select"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select patient consultation first...</option>
                                <?php foreach ($inventoryUsageOptions as $item): ?>
                                    <option value="<?= esc($item['item_name']) ?>" data-item-id="<?= (int)$item['id'] ?>" data-item-text="<?= esc(strtolower((string)$item['item_name'] . ' ' . (string)($item['item_type'] ?? '') . ' ' . (string)($item['category'] ?? ''))) ?>" data-quantity="<?= (int)$item['quantity'] ?>" data-unit="<?= esc($item['unit'] ?: 'unit(s)') ?>">
                                        <?= esc($item['item_name']) ?> (<?= (int)$item['quantity'] ?> <?= esc($item['unit'] ?: 'unit(s)') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p id="medtech-kit-filter-note" class="mt-1 text-[11px] font-semibold text-slate-500">Kits are filtered from inventory based on the selected consultation.</p>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Type / Reference</label>
                            <input name="test_type" placeholder="e.g. Fasting Blood Sugar (70-99 mg/dL)"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Date *</label>
                            <input required type="date" name="test_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        <label class="mt-2 block text-xs font-bold text-slate-700">Materials used at / location<input name="usage_location" maxlength="200" value="RHU Laboratory" class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-sm"></label>
                            <label class="mt-2 block text-xs font-bold text-slate-700">Materials used on<input type="datetime-local" name="usage_datetime" value="<?= date('Y-m-d\TH:i') ?>" class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-sm"></label>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Result Status</label>
                            <select name="test_status" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Normal</option>
                                <option>Abnormal</option>
                                <option>Positive</option>
                                <option>Negative</option>
                                <option>Pending</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Ordered By</label>
                            <input type="hidden" name="ordered_by_id" value="<?= $medtechStaffId > 0 ? (int)$medtechStaffId : '' ?>">
                            <input readonly value="<?= esc($medtechDisplayName) ?>" class="w-full cursor-default rounded-xl border border-slate-300 bg-slate-50 p-3 text-sm font-semibold text-slate-700">
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Results / Findings</label>
                        <textarea name="results" rows="3" placeholder="Enter laboratory findings..."
                            class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea>
                    </div>
                    <div class="rounded-2xl border border-teal-100 bg-teal-50/50 p-4">
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-black text-slate-800">Materials / Kits Used</h3>
                                <p class="mt-0.5 text-[11px] font-semibold text-slate-500">Selected quantities are deducted from the admin inventory after saving.</p>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <?php for ($usageRow = 0; $usageRow < 5; $usageRow++): ?>
                                <div class="grid gap-2 sm:grid-cols-[1fr_110px]">
                                    <select name="inventory_usage_item_id[]" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-xs font-semibold" <?= $usageRow === 0 ? 'data-primary-kit-usage' : '' ?>>
                                        <option value="">No item selected</option>
                                        <?php foreach ($inventoryUsageOptions as $item): ?>
                                            <option value="<?= (int)$item['id'] ?>">
                                                <?= esc($item['item_name']) ?> - <?= esc($item['item_type'] ?: ($item['category'] ?: 'Inventory')) ?> (<?= (int)$item['quantity'] ?> <?= esc($item['unit'] ?: 'unit(s)') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" min="0" step="1" name="inventory_usage_quantity[]" placeholder="Qty used" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-xs font-semibold" <?= $usageRow === 0 ? 'data-primary-kit-quantity' : '' ?>>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('rapid')); ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save
                            Record</button>
                    </div>
                </form>
                <script>
                    (function () {
                        const consultationSelect = document.querySelector('select[name="consultation_id"]');
                        const residentInput = document.getElementById('medtech-test-resident-id');
                        const dateInput = document.getElementById('medtech-test-appointment-date');
                        const timeInput = document.getElementById('medtech-test-appointment-time');
                        const kitSelect = document.getElementById('medtech-test-kit-select');
                        const kitNote = document.getElementById('medtech-kit-filter-note');
                        const primaryUsage = document.querySelector('[data-primary-kit-usage]');
                        const primaryQuantity = document.querySelector('[data-primary-kit-quantity]');
                        if (!consultationSelect) return;

                        const words = (value) => String(value || '').toLowerCase().split(/[^a-z0-9]+/).filter((word) => word.length >= 3);
                        const kitRules = [
                            { request: ['urine', 'urinalysis', 'uti'], kit: ['urine', 'urinalysis', 'urinal', 'dipstick', 'strip'] },
                            { request: ['pregnancy', 'hcg'], kit: ['pregnancy', 'hcg'] },
                            { request: ['covid', 'sars'], kit: ['covid', 'sars', 'antigen'] },
                            { request: ['dengue', 'ns1'], kit: ['dengue', 'ns1'] },
                            { request: ['blood glucose', 'glucose', 'fbs', 'rbs', 'sugar'], kit: ['glucose', 'sugar', 'glucometer'] },
                            { request: ['cholesterol', 'lipid'], kit: ['cholesterol', 'lipid'] },
                            { request: ['hemoglobin', 'hgb'], kit: ['hemoglobin', 'hgb'] },
                            { request: ['malaria'], kit: ['malaria'] },
                            { request: ['hiv'], kit: ['hiv'] },
                            { request: ['hepatitis', 'hbsag'], kit: ['hepatitis', 'hbsag'] },
                            { request: ['syphilis', 'vdrl', 'rpr'], kit: ['syphilis', 'vdrl', 'rpr'] },
                            { request: ['cbc', 'complete blood count'], kit: ['cbc', 'complete blood count'] },
                            { request: ['stool', 'fecal'], kit: ['stool', 'fecal'] },
                        ];

                        const kitMatchesConsultation = (consultationText, itemText) => {
                            const text = String(consultationText || '').toLowerCase();
                            const item = String(itemText || '').toLowerCase();
                            const matchedRule = kitRules.find((rule) => rule.request.some((term) => text.includes(term)));
                            if (matchedRule) {
                                return matchedRule.kit.some((term) => item.includes(term));
                            }
                            const consultationWords = words(text).filter((word) => !['laboratory', 'referral', 'test', 'testing', 'diagnostic', 'patient'].includes(word));
                            return consultationWords.length > 0 && consultationWords.some((word) => item.includes(word));
                        };

                        const syncPrimaryUsage = () => {
                            if (!kitSelect || !primaryUsage || !primaryQuantity) return;
                            const selected = kitSelect.options[kitSelect.selectedIndex];
                            const itemId = selected?.dataset?.itemId || '';
                            primaryUsage.value = itemId;
                            primaryQuantity.value = itemId ? (primaryQuantity.value || '1') : '';
                        };

                        const filterKits = () => {
                            if (!kitSelect) return;
                            const selectedConsultation = consultationSelect.options[consultationSelect.selectedIndex];
                            const consultationText = selectedConsultation?.dataset?.consultationText || selectedConsultation?.textContent || '';
                            let visibleCount = 0;
                            Array.from(kitSelect.options).forEach((option, index) => {
                                if (index === 0) return;
                                const itemText = option.dataset.itemText || option.textContent || '';
                                const matches = kitMatchesConsultation(consultationText, itemText);
                                option.hidden = !matches;
                                if (matches) visibleCount++;
                            });
                            if (kitSelect.selectedOptions[0]?.hidden) {
                                kitSelect.value = '';
                            }
                            kitSelect.options[0].textContent = visibleCount > 0 ? 'Select kit for this consultation...' : 'No accurate kit match in inventory';
                            if (kitNote) {
                                kitNote.textContent = visibleCount > 0
                                    ? `${visibleCount} inventory item(s) match this consultation. Selecting one will deduct 1 by default.`
                                    : 'No inventory kit matches this consultation. Add the correct kit to inventory or adjust the consultation test name.';
                            }
                            syncPrimaryUsage();
                        };

                        const syncConsultation = () => {
                            const option = consultationSelect.options[consultationSelect.selectedIndex];
                            if (residentInput) residentInput.value = option?.dataset?.residentId || '';
                            if (dateInput) dateInput.value = option?.dataset?.appointmentDate || '';
                            if (timeInput) timeInput.value = option?.dataset?.appointmentTime || '';
                            filterKits();
                        };

                        consultationSelect.addEventListener('change', syncConsultation);
                        kitSelect?.addEventListener('change', syncPrimaryUsage);
                        syncConsultation();
                    })();
                </script>
            </div>
        </div>
    <?php endif; ?>

    <?php if (false && $modal === 'referral'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div
                    class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Create Specimen Referral</h2>
                        <p class="text-xs text-slate-500">Send specimen to external laboratory</p>
                    </div>
                    <a href="<?= esc(tabUrl('referrals')); ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post"><input type="hidden" name="csrf_token" value="<?= esc(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_lab_referral">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Resident Patient *</label>
                        <select required name="resident_id"
                            class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">Select Resident Patient...</option>
                            <?php foreach ($residentOptions as $o): ?>
                                <option value="<?= (int) $o['id'] ?>"><?= esc(rhuPatientLabel($o['name'], $o['id'])) ?> (<?= esc($o['barangay']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Requested *</label>
                            <input required name="test_requested" placeholder="e.g. Full CBC, Lipid Profile"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Destination Facility *</label>
                            <input required name="destination_facility"
                                placeholder="e.g. Apacible Memorial District Hospital"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Referral Date *</label>
                            <input required type="date" name="referral_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Referring Staff</label>
                            <select name="referred_by_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select staff...</option>
                                <?php foreach ($staffOptions as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>"><?= esc($o['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Clinical Notes</label>
                        <textarea name="clinical_notes" rows="3" placeholder="Reason for referral..."
                            class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('referrals')); ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save
                            Record</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>



    <?= portalRenderNotificationPanel(); ?>

    <?php if ($tab === 'blood'): ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script>
            window.medtechBloodMapResidents = <?= json_encode($bloodMapResidents ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        </script>
    <?php endif; ?>

    <script>
        (function () {
            document.querySelectorAll('[data-medtech-resident-combobox]').forEach((combo) => {
                const button = combo.querySelector('[data-medtech-resident-button]');
                const label = combo.querySelector('[data-medtech-resident-label]');
                const valueInput = combo.querySelector('[data-medtech-resident-value]');
                const options = Array.from(combo.querySelectorAll('[data-medtech-resident-option]'));

                const setOpen = (open) => {
                    combo.classList.toggle('is-open', open);
                    button?.setAttribute('aria-expanded', String(open));
                };

                button?.addEventListener('click', () => {
                    const nextOpen = !combo.classList.contains('is-open');
                    document.querySelectorAll('[data-medtech-resident-combobox].is-open').forEach((openCombo) => {
                        if (openCombo !== combo) {
                            openCombo.classList.remove('is-open');
                            openCombo.querySelector('[data-medtech-resident-button]')?.setAttribute('aria-expanded', 'false');
                        }
                    });
                    setOpen(nextOpen);
                });

                options.forEach((option) => {
                    option.addEventListener('click', () => {
                        const selectedValue = option.getAttribute('data-value') || '';
                        if (valueInput) valueInput.value = selectedValue;
                        if (label) label.textContent = option.textContent.trim();
                        options.forEach((item) => item.classList.toggle('is-selected', item === option));
                        setOpen(false);
                        button?.focus();
                    });
                });

                combo.closest('form')?.addEventListener('submit', (event) => {
                    if (!valueInput?.value) {
                        event.preventDefault();
                        setOpen(true);
                        button?.focus();
                        alert('Select a resident before saving this confidential record.');
                    }
                });
            });

            document.addEventListener('click', (event) => {
                document.querySelectorAll('[data-medtech-resident-combobox].is-open').forEach((combo) => {
                    if (!combo.contains(event.target)) {
                        combo.classList.remove('is-open');
                        combo.querySelector('[data-medtech-resident-button]')?.setAttribute('aria-expanded', 'false');
                    }
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                document.querySelectorAll('[data-medtech-resident-combobox].is-open').forEach((combo) => {
                    combo.classList.remove('is-open');
                    combo.querySelector('[data-medtech-resident-button]')?.setAttribute('aria-expanded', 'false');
                });
            });
        })();

        (function () {
            const search = document.getElementById('blood-resident-search');
            const rows = Array.from(document.querySelectorAll('.blood-resident-row'));
            const mapStatus = document.getElementById('blood-map-status');
            const mapCount = document.getElementById('blood-map-count');
            const mapElement = document.getElementById('blood-compatibility-map');
            let residents = Array.isArray(window.medtechBloodMapResidents) ? window.medtechBloodMapResidents : [];
            let map = null;
            let residentLayer = null;
            let rhuMarker = null;
            const markerByResidentId = new Map();
            const rhuPoint = { lat: 14.0740668, lng: 120.6321294 };
            const maxSharedLocationKm = 80;

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char]));

            const setMapStatus = (message, tone) => {
                if (!mapStatus) return;
                mapStatus.textContent = message;
                mapStatus.className = 'mt-3 text-xs font-semibold ' + (tone === 'error' ? 'text-rose-700' : tone === 'ok' ? 'text-teal-700' : 'text-slate-500');
            };

            const donorDisplayPoint = (index, total) => {
                const count = Math.max(total, 1);
                const ring = Math.floor(index / 10);
                const position = index % 10;
                const slots = Math.min(10, count);
                const angle = ((position / slots) * Math.PI * 2) + (ring * 0.34);
                const radius = 0.0038 + (ring * 0.0024);
                return {
                    lat: rhuPoint.lat + Math.sin(angle) * radius,
                    lng: rhuPoint.lng + Math.cos(angle) * radius,
                };
            };

            const distanceKm = (a, b) => {
                const toRad = (degrees) => degrees * Math.PI / 180;
                const earthKm = 6371;
                const dLat = toRad(b.lat - a.lat);
                const dLng = toRad(b.lng - a.lng);
                const lat1 = toRad(a.lat);
                const lat2 = toRad(b.lat);
                const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
                return earthKm * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
            };

            const isUsableSharedLocation = (lat, lng) => (
                Number.isFinite(lat) &&
                Number.isFinite(lng) &&
                lat >= -90 &&
                lat <= 90 &&
                lng >= -180 &&
                lng <= 180 &&
                distanceKm(rhuPoint, { lat, lng }) <= maxSharedLocationKm
            );

            const visibleResidentIds = () => {
                const ids = new Set();
                rows.forEach(row => {
                    if (!row.hidden) {
                        const id = Number(row.getAttribute('data-resident-id') || 0);
                        if (id > 0) ids.add(id);
                    }
                });
                return ids;
            };

            const syncVisibleMarkers = () => {
                let visibleCount = 0;
                markerByResidentId.forEach((marker, id) => {
                    marker.setStyle({ opacity: 1, fillOpacity: marker.options.fillOpacity || 0.72 });
                    visibleCount++;
                });
                if (mapCount) mapCount.textContent = `${visibleCount || residents.length} shared location(s)`;
            };

            const renderResidentMarkers = (nextResidents, shouldFit = true) => {
                residents = Array.isArray(nextResidents) ? nextResidents : [];
                markerByResidentId.clear();
                residentLayer?.clearLayers();

                if (!residents.length) {
                    if (mapCount) mapCount.textContent = '0 shared location(s)';
                    setMapStatus('No compatible residents have shared device location yet.', 'info');
                    map?.setView([rhuPoint.lat, rhuPoint.lng], 14);
                    return;
                }

                const bounds = [[rhuPoint.lat, rhuPoint.lng]];
                let sharedLocationCount = 0;
                let ignoredLocationCount = 0;

                residents.forEach((resident, index) => {
                    const sharedLat = Number(resident.latitude);
                    const sharedLng = Number(resident.longitude);
                    const hasAnyLocation = Number.isFinite(sharedLat) && Number.isFinite(sharedLng);
                    const hasSharedLocation = isUsableSharedLocation(sharedLat, sharedLng);
                    const isCompatible = resident.is_compatible === true || resident.is_compatible === 1 || resident.is_compatible === '1';
                    const point = hasSharedLocation ? { lat: sharedLat, lng: sharedLng } : donorDisplayPoint(index, residents.length);
                    if (hasSharedLocation) sharedLocationCount++;
                    else if (hasAnyLocation) ignoredLocationCount++;

                    const marker = L.circleMarker([point.lat, point.lng], {
                        radius: isCompatible ? 9 : 7,
                        color: isCompatible ? '#047857' : '#2563eb',
                        weight: 2,
                        fillColor: isCompatible ? '#14b8a6' : '#60a5fa',
                        fillOpacity: hasSharedLocation ? 0.9 : 0.72
                    }).addTo(residentLayer);
                    const locationLine = hasSharedLocation
                        ? `Shared from resident device${resident.accuracy_m ? `, about ${Number(resident.accuracy_m)}m accuracy` : ''}${resident.location_shared_at ? `<br><small>Updated: ${escapeHtml(resident.location_shared_at)}</small>` : ''}`
                        : (hasAnyLocation ? 'Saved GPS was outside the RHU service radius; shown near RHU for matching only.' : 'No device location shared yet; shown near RHU for matching only.');
                    marker.bindPopup(`<strong>${escapeHtml(resident.name || 'Resident')}</strong><br>Blood type: ${escapeHtml(resident.blood_type || 'Not recorded')}<br>${escapeHtml(resident.barangay || 'Nasugbu')}<br>${isCompatible ? '<strong>Compatible donor for selected recipient type</strong><br>' : ''}<small>${locationLine}</small>`);
                    markerByResidentId.set(Number(resident.id || 0), marker);
                    bounds.push([point.lat, point.lng]);
                });

                const fitBloodMap = () => {
                    map.invalidateSize();
                    if (shouldFit) map.fitBounds(bounds, { padding: [34, 34], maxZoom: 16 });
                };
                requestAnimationFrame(() => {
                    fitBloodMap();
                    setTimeout(fitBloodMap, 250);
                });
                syncVisibleMarkers();
                const compatibleCount = residents.filter((resident) => resident.is_compatible === true || resident.is_compatible === 1 || resident.is_compatible === '1').length;
                setMapStatus(`Showing ${sharedLocationCount} compatible shared location(s); ${compatibleCount} compatible donor(s) on map${ignoredLocationCount ? `, ${ignoredLocationCount} location(s) ignored outside service radius` : ''}.`, 'ok');
            };

            const refreshBloodMapResidents = async () => {
                if (!map) return;
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', 'blood');
                    url.searchParams.set('blood_map_json', '1');
                    const response = await fetch(url.toString(), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });
                    const payload = await response.json();
                    if (!payload.success || !Array.isArray(payload.residents)) return;
                    renderResidentMarkers(payload.residents, false);
                } catch (error) {
                    setMapStatus('Unable to refresh shared locations right now.', 'error');
                }
            };

            const initBloodMap = async () => {
                if (!mapElement || !window.L || map) return;
                map = L.map(mapElement, { scrollWheelZoom: false }).setView([rhuPoint.lat, rhuPoint.lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);
                residentLayer = L.layerGroup().addTo(map);
                const rhuIcon = L.divIcon({
                    className: '',
                    html: '<div class="blood-rhu-marker">RHU</div>',
                    iconSize: [34, 34],
                    iconAnchor: [17, 17],
                    popupAnchor: [0, -18]
                });
                rhuMarker = L.marker([rhuPoint.lat, rhuPoint.lng], { icon: rhuIcon }).addTo(map).bindPopup('<strong>RHU Nasugbu Main</strong><br>J.P. Laurel St, Poblacion, Nasugbu');
                setMapStatus('RHU is pinned at the center. Compatible shared locations update automatically.', 'info');
                renderResidentMarkers(residents, true);
                window.setInterval(refreshBloodMapResidents, 10000);
            };

            if (search && rows.length) {
                search.addEventListener('input', () => {
                    const query = search.value.trim().toLowerCase();
                    rows.forEach(row => {
                        row.hidden = query !== '' && !String(row.dataset.search || '').includes(query);
                    });
                    syncVisibleMarkers();
                });
            }
            initBloodMap();
        })();
    </script>

    <script>
        (function () {
            const sidebar = document.querySelector('[data-feature-drawer]');
            const backdrop = document.querySelector('[data-drawer-backdrop]');
            const openBtn = document.querySelector('[data-drawer-open]');
            const closeBtn = document.querySelector('[data-drawer-close]');
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
</body>

</html>
