<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
$isNurseAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || ($_POST['ajax'] ?? '') === '1'
    || ($_GET['ajax'] ?? '') === '1';
$stType = strtoupper((string) ($_SESSION['rhu_staff_login']['staff_type'] ?? ''));
if (empty($_SESSION['rhu_staff_login']) || ($stType !== 'NURSE' && strpos($stType, 'NURSE') === false)) {
    if ($isNurseAjaxRequest) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Nurse session expired. Please sign in again.']);
        exit;
    }
    header('Location: RHULogin.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/rhu-patient-intake.php';
if ($pdo instanceof PDO) rhuIntakeEnsure($pdo);
require_once __DIR__ . '/includes/patient-identifiers.php';
require_once __DIR__ . '/includes/nurse-immunization-appointments.php';
require_once __DIR__ . '/includes/portal.php';
require_once __DIR__ . '/includes/appointment-email-reminders.php';
portalHandleNotificationApi($pdo);
if (!$isNurseAjaxRequest && $pdo instanceof PDO && function_exists('rhuSendUpcomingAppointmentEmailReminders')) {
    rhuSendUpcomingAppointmentEmailReminders($pdo);
}

        $vaccineSchedules = [
            ['id' => 1, 'vaccine_name' => 'BCG', 'age_group' => 'Infant'],
            ['id' => 2, 'vaccine_name' => 'Hepatitis B', 'age_group' => 'Infant/Adult'],
            ['id' => 3, 'vaccine_name' => 'Pentavalent', 'age_group' => 'Infant'],
            ['id' => 4, 'vaccine_name' => 'MMR', 'age_group' => 'Child'],
        ];

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

function parseTriageVitals(string $notes): array
{
    $vitals = [];
    if (preg_match('/BP:\s*([^\s,]+)/i', $notes, $m))
        $vitals['bp'] = $m[1];
    if (preg_match('/Temp:\s*([^\s,]+)/i', $notes, $m))
        $vitals['temp'] = $m[1];
    if (preg_match('/(?:Wt|Weight):\s*([^\s,]+(?:\s*kg)?)/i', $notes, $m))
        $vitals['weight'] = $m[1];
    if (preg_match('/RR:\s*([^\s,]+)/i', $notes, $m))
        $vitals['rr'] = $m[1];
    if (preg_match('/HR:\s*([^\s,]+)/i', $notes, $m))
        $vitals['hr'] = $m[1];
    return array_filter($vitals);
}

function dbHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $quotedTable = str_replace('`', '``', $table);
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$quotedTable}`");
            $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log('Nurse dashboard column lookup failed for ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }
    return in_array($column, $cache[$table], true);
}

function dbHasTable(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE :table_name");
            $stmt->execute(['table_name' => $table]);
            $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

function nurseEnsureResidentHealthProfiles(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS resident_health_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        resident_id BIGINT UNSIGNED NOT NULL UNIQUE,
        height DOUBLE(5,2) NULL,
        weight DOUBLE(5,2) NULL,
        blood_pressure VARCHAR(20) NULL,
        blood_type VARCHAR(10) NULL,
        philhealth_number VARCHAR(50) NULL,
        allergies TEXT NULL,
        chronic_conditions TEXT NULL,
        current_medications TEXT NULL,
        last_checkup_date DATE NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_rhp_resident (resident_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        "ALTER TABLE resident_health_profiles ADD COLUMN height DOUBLE(5,2) NULL AFTER resident_id",
        "ALTER TABLE resident_health_profiles ADD COLUMN weight DOUBLE(5,2) NULL AFTER height",
        "ALTER TABLE resident_health_profiles ADD COLUMN blood_pressure VARCHAR(20) NULL AFTER weight",
        "ALTER TABLE resident_health_profiles ADD COLUMN blood_type VARCHAR(10) NULL AFTER blood_pressure",
        "ALTER TABLE resident_health_profiles ADD COLUMN philhealth_number VARCHAR(50) NULL AFTER blood_type",
        "ALTER TABLE resident_health_profiles ADD COLUMN allergies TEXT NULL AFTER philhealth_number",
        "ALTER TABLE resident_health_profiles ADD COLUMN chronic_conditions TEXT NULL AFTER allergies",
        "ALTER TABLE resident_health_profiles ADD COLUMN current_medications TEXT NULL AFTER chronic_conditions",
        "ALTER TABLE resident_health_profiles ADD COLUMN last_checkup_date DATE NULL AFTER current_medications",
        "ALTER TABLE resident_health_profiles ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE resident_health_profiles ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        "ALTER TABLE resident_health_profiles ADD UNIQUE KEY uniq_resident_health_profile (resident_id)",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $ignored) {
        }
    }

    foreach ([
        "ALTER TABLE residents ADD COLUMN blood_type VARCHAR(10) NULL",
        "ALTER TABLE residents ADD COLUMN philhealth_number VARCHAR(50) NULL",
        "ALTER TABLE residents ADD COLUMN philhealth_id VARCHAR(50) NULL",
        "ALTER TABLE residents ADD COLUMN contact_number VARCHAR(50) NULL",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->exec("
            UPDATE residents
            SET philhealth_number = COALESCE(NULLIF(TRIM(philhealth_number), ''), NULLIF(TRIM(philhealth_id), ''))
            WHERE (philhealth_number IS NULL OR TRIM(philhealth_number) = '')
              AND NULLIF(TRIM(philhealth_id), '') IS NOT NULL
        ");
    } catch (Throwable $ignored) {
    }
}

function nurseBarangayId(PDO $pdo, string $barangay): int
{
    $name = trim($barangay);
    if ($name === '') {
        $name = 'Poblacion';
    }
    $stmt = $pdo->prepare("SELECT id FROM barangays WHERE name = :name LIMIT 1");
    $stmt->execute(['name' => $name]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }
    return (int) ($pdo->query("SELECT id FROM barangays ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
}

if (!function_exists('portalRenderCertificateIssuancePanel')) {
    function portalRenderCertificateIssuancePanel(PDO $pdo, array $residents, array $certificateTypes, int $staffId, string $accent = 'emerald'): string
    {
        $today = date('Y-m-d');
        $expiry = date('Y-m-d', strtotime('+6 months'));
        $accentClass = $accent === 'blue' ? 'blue' : 'emerald';
        $buttonClass = $accentClass === 'blue'
            ? 'bg-blue-700 hover:bg-blue-800 focus:ring-blue-200'
            : 'bg-emerald-700 hover:bg-emerald-800 focus:ring-emerald-200';

        ob_start();
        ?>
        <section class="dashboard-card rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="mb-4 flex flex-col gap-2 border-b border-slate-100 pb-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-extrabold text-slate-950 sm:text-xl">Issue Resident Certificate</h2>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">Create nursing health certificates for registered residents.</p>
                </div>
                <span class="w-fit rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-extrabold text-slate-700">Nurse Issuance</span>
            </div>

            <?php if (empty($residents) || empty($certificateTypes)): ?>
                <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
                    Add active residents and certificate types first before issuing certificates.
                </div>
            <?php else: ?>
                <form method="post" class="resident-record-form grid grid-cols-1 gap-3 text-xs lg:grid-cols-2">
                    <input type="hidden" name="action" value="issue_certificate">
                    <input type="hidden" name="issued_by_id" value="<?= (int) $staffId ?>">

                    <div>
                        <label class="mb-1 block font-bold text-gray-700">Resident *</label>
                        <select name="resident_id" required class="resident-patient-select w-full rounded-xl border border-gray-300 p-3 text-sm font-semibold outline-none focus:ring-2">
                            <option value="">-- Select Resident Patient --</option>
                            <?php foreach ($residents as $resident): ?>
                                <option value="<?= (int) ($resident['id'] ?? 0) ?>" data-barangay="<?= esc($resident['barangay'] ?? '') ?>" title="<?= esc(trim(($resident['name'] ?? 'Resident') . ' - ' . ($resident['barangay'] ?? ''))) ?>">
                                    <?= esc($resident['name'] ?? 'Resident') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block font-bold text-gray-700">Certificate Type *</label>
                        <select name="certificate_type_id" required class="w-full rounded-xl border border-gray-300 bg-white p-3 text-sm font-semibold outline-none focus:ring-2">
                            <?php foreach ($certificateTypes as $type): ?>
                                <option value="<?= (int) ($type['id'] ?? 0) ?>"><?= esc($type['certificate_type_name'] ?? 'Health Certificate') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="mb-1 block font-bold text-gray-700">Purpose *</label>
                        <textarea name="purpose" required rows="3" class="w-full rounded-xl border border-gray-300 p-3 text-sm outline-none focus:ring-2" placeholder="Purpose of certificate"></textarea>
                    </div>

                    <div>
                        <label class="mb-1 block font-bold text-gray-700">Issue Date</label>
                        <input type="date" name="issue_date" value="<?= esc($today) ?>" class="w-full rounded-xl border border-gray-300 p-3 text-sm font-semibold outline-none focus:ring-2">
                    </div>

                    <div>
                        <label class="mb-1 block font-bold text-gray-700">Expiry Date</label>
                        <input type="date" name="expiry_date" value="<?= esc($expiry) ?>" class="w-full rounded-xl border border-gray-300 p-3 text-sm font-semibold outline-none focus:ring-2">
                    </div>

                    <div class="flex justify-end lg:col-span-2">
                        <button type="submit" class="rounded-xl px-4 py-2.5 text-sm font-extrabold text-white shadow-sm focus:outline-none focus:ring-4 <?= esc($buttonClass) ?>">
                            Issue certificate
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

$tabs = [
    'overview' => ['Overview', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'opd' => ['OPD Triage', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'],
    'requests' => ['Resident Requests', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H7l-4 4V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 8h8M8 12h6"/></svg>'],
    'patients' => ['Patient Records', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    'immunization' => ['Immunization', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m18 2 4 4"/><path d="m17 7 3-3"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/><path d="m9 11 4 4"/><path d="m5 19-3 3"/><path d="m14 4 6 6"/></svg>'],
    'nutrition' => ['Nutrition (OPT+)', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 20.94c1.5-1.35 4.5-4.05 4.5-7.44A4.5 4.5 0 0 0 12 9a4.5 4.5 0 0 0-4.5 4.5c0 3.39 3 6.09 4.5 7.44z"/><path d="M12 9V5"/><path d="M12 5c.5-1 1.5-2 3-2"/></svg>'],
    'tb' => ['TB-DOTS', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 3h6v4a3 3 0 0 1-6 0V3z"/><path d="M9 7v13"/><path d="M15 7v13"/><path d="M9 12h6"/></svg>'],
    'disease' => ['Disease Surveillance', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'],
];

$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab]))
    $tab = 'overview';

$modal = $_GET['modal'] ?? '';
$selectedResidentId = (int) ($_GET['resident_id'] ?? 0);
$flashSuccess = $_SESSION['nurse_flash_success'] ?? '';
$flashError = $_SESSION['nurse_flash_error'] ?? '';
unset($_SESSION['nurse_flash_success'], $_SESSION['nurse_flash_error']);
if (empty($pdo)) {
    $flashError = 'Database unavailable. OPD records cannot be loaded or saved. Check that MySQL is running and the database settings are correct.';
}

// ----------------------------------------------------
require_once __DIR__ . '/includes/resident-qr.php';
require_once __DIR__ . '/includes/fhsis-services.php';

// 1. POST FORM HANDLERS FOR NURSING & OPD TRIAGE
// ----------------------------------------------------
if (empty($_SESSION['nurse_bmi_alert_token'])) {
    $_SESSION['nurse_bmi_alert_token'] = bin2hex(random_bytes(32));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($pdo)) {
    $action = $_POST['action'] ?? '';
    $isAjaxRequest = $isNurseAjaxRequest;
    $nurseJsonRespond = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    if ($action === 'alert_resident_bmi') {
        try {
            if (!hash_equals($_SESSION['nurse_bmi_alert_token'], (string) ($_POST['bmi_alert_token'] ?? ''))) {
                throw new RuntimeException('Please refresh the page before sending an alert.');
            }
            $residentId = (int) ($_POST['resident_id'] ?? 0);
            $measurementStmt = $pdo->prepare("SELECT r.id,
                CONCAT(r.first_name, ' ', r.last_name) AS name,
                TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age,
                COALESCE(CASE WHEN p.height > 0 THEN p.height END, h.height_cm) AS height,
                COALESCE(CASE WHEN p.weight > 0 THEN p.weight END, h.weight_kg) AS weight
                FROM residents r
                LEFT JOIN resident_health_profiles p ON p.resident_id = r.id
                LEFT JOIN health_records h ON h.id = (
                    SELECT MAX(latest.id) FROM health_records latest WHERE latest.resident_id = r.id
                        AND latest.height_cm > 0 AND latest.weight_kg > 0
                )
                WHERE r.id = :resident_id AND " . rhuLivingResidentWhereSql('r'));
            $measurementStmt->execute(['resident_id' => $residentId]);
            $measurement = $measurementStmt->fetch(PDO::FETCH_ASSOC);
            $height = (float) ($measurement['height'] ?? 0);
            $weight = (float) ($measurement['weight'] ?? 0);
            if (!$measurement || $height <= 0 || $weight <= 0) {
                throw new RuntimeException('Valid saved height and weight are required for this alert.');
            }
            $bmi = $weight / (($height / 100) ** 2);
            if ($bmi >= 18.5 && $bmi < 25) {
                throw new RuntimeException('The latest BMI is normal. No abnormal BMI alert was sent.');
            }
            $alertKey = $residentId . ':' . $height . ':' . $weight . ':' . date('Y-m-d');
            if (!empty($_SESSION['nurse_bmi_alerts'][$alertKey])) {
                throw new RuntimeException('An alert for these measurements was already submitted today.');
            }
            $bmiText = number_format($bmi, 1);
            $message = "Nutrition follow-up for {$measurement['name']}: recorded height {$height} cm, weight {$weight} kg, BMI {$bmiText}. ";
            if ((int) $measurement['age'] >= 20) {
                $message .= 'The recorded BMI is ' . ($bmi < 18.5 ? 'below' : 'above') . ' the normal range. ';
            }
            $message .= 'Please contact the Rural Health Unit for a nutrition assessment with the nurse.';
            portalNotifyResident($pdo, $residentId, $message, 'ResidentDashboard.php?tab=records');
            $_SESSION['nurse_bmi_alerts'][$alertKey] = true;
            $_SESSION['nurse_flash_success'] = 'Nutrition alert submitted for ' . $measurement['name'] . '.';
        } catch (Throwable $e) {
            $_SESSION['nurse_flash_error'] = $e->getMessage();
        }
        header('Location: ' . tabUrl('nutrition'));
        exit;
    }

    if ($action === 'save_immunization') {
        $nurseStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? $_SESSION['rhu_staff_login']['health_worker_id'] ?? 0);
        $residentId = (int) ($_POST['resident_id'] ?? 0);
        $vaccineId = (int) ($_POST['vaccine_id'] ?? 0);
        $dateGiven = trim($_POST['vaccination_date'] ?? date('Y-m-d'));
        $nextDose = trim($_POST['next_dose_date'] ?? '');
        $batch = trim($_POST['batch_number'] ?? '');
        $consultationId = (int) ($_POST['consultation_id'] ?? 0);
        try {
            if ($residentId <= 0 || $vaccineId <= 0 || $batch === '')
                throw new RuntimeException('Resident, vaccine, and batch number are required.');
            $vaccines = [1 => ['BCG', 'Infant'], 2 => ['Hepatitis B', 'Infant/Adult'], 3 => ['Pentavalent', 'Infant'], 4 => ['MMR', 'Child']];
            if (!isset($vaccines[$vaccineId]) || $nurseStaffId <= 0) {
                throw new RuntimeException('Select a valid vaccine and sign in with a nurse staff account.');
            }
            foreach ([$dateGiven, $nextDose] as $dateValue) {
                if ($dateValue === '') continue;
                $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue) {
                    throw new RuntimeException('Enter a valid vaccination or next dose date.');
                }
            }
            if ($dateGiven === '' || ($nextDose !== '' && $nextDose < $dateGiven)) {
                throw new RuntimeException('Next dose must be on or after the vaccination date.');
            }
            $vaccineName = $vaccines[$vaccineId][0];
            $ageGroup = $vaccines[$vaccineId][1];
            if ($vaccineName === '' && $vaccineId > 0) {
                $vaccineName = 'Vaccine #' . $vaccineId;
            }
            $pdo->beginTransaction();
            $linkedConsultation = null;
            if ($consultationId > 0) {
                $appointmentStmt = $pdo->prepare('SELECT * FROM consultations WHERE id = :id AND resident_id = :resident FOR UPDATE');
                $appointmentStmt->execute(['id' => $consultationId, 'resident' => $residentId]);
                $linkedConsultation = $appointmentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$linkedConsultation
                    || !preg_match('/vaccination|immunization|vaccine/i', ($linkedConsultation['chief_complaint'] ?? '') . ' ' . ($linkedConsultation['diagnosis'] ?? ''))
                    || in_array(strtolower(trim((string) ($linkedConsultation['consultation_status'] ?? ''))), ['completed', 'cancelled', 'no-show'], true)) {
                    throw new RuntimeException('The selected vaccination appointment is no longer available. Reopen the form and try again.');
                }
            }
            $stmt = $pdo->prepare("INSERT INTO vaccination_records
                (resident_id, vaccine_inventory_id, health_worker_id, age_group, vaccine_name, vaccination_date, batch_number, site_of_injection, adverse_reactions, remarks, next_dose_date)
                VALUES (:resident, NULL, :provider, :age_group, :vaccine_name, :given, :batch, :site, :reactions, :remarks, :next_dose)");
            $stmt->execute([
                'resident' => $residentId,
                'given' => $dateGiven,
                'provider' => $nurseStaffId ?: null,
                'age_group' => $ageGroup,
                'vaccine_name' => $vaccineName,
                'batch' => $batch,
                'site' => trim((string) ($_POST['site_of_injection'] ?? '')) ?: null,
                'reactions' => trim((string) ($_POST['adverse_reactions'] ?? '')) ?: null,
                'remarks' => null,
                'next_dose' => $nextDose !== '' ? $nextDose : null
            ]);
            $fhsisVaccineId = (int)$pdo->lastInsertId();
            fhsisSaveInlineServices($pdo, 'vaccination_records', $fhsisVaccineId, $residentId, $_POST, $_SESSION);
            portalSaveHealthRecordEntry($pdo, $residentId, [
                'record_type' => 'Immunization',
                'immunization_notes' => "Vaccine: {$vaccineName}; Age group: {$ageGroup}; Batch: {$batch}; Next dose: " . ($nextDose ?: 'Not required'),
                'notes' => trim(($_POST['site_of_injection'] ?? '') . "\n" . ($_POST['adverse_reactions'] ?? '')),
                'date' => $dateGiven,
                'recorded_by_id' => $nurseStaffId ?: null,
            ]);
            if ($linkedConsultation) {
                $updates = ['diagnosis = :diagnosis'];
                $appointmentParams = ['id' => $consultationId, 'diagnosis' => 'Vaccination administered: ' . $vaccineName];
                if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                    $updates[] = 'consultation_status = :status';
                    $appointmentParams['status'] = 'Completed';
                }
                if (dbHasColumn($pdo, 'consultations', 'health_worker_id')) {
                    $updates[] = 'health_worker_id = :provider';
                    $appointmentParams['provider'] = $nurseStaffId;
                }
                if (dbHasColumn($pdo, 'consultations', 'updated_at')) $updates[] = 'updated_at = NOW()';
                foreach (['consultation_notes', 'treatment_plan'] as $notesColumn) {
                    if (!dbHasColumn($pdo, 'consultations', $notesColumn)) continue;
                    $updates[] = "{$notesColumn} = :vaccine_notes";
                    $appointmentParams['vaccine_notes'] = trim((string) ($linkedConsultation[$notesColumn] ?? ''))
                        . "\nStatus: Completed\nVaccine administered: {$vaccineName}; Date: {$dateGiven}; Batch: {$batch}; Next dose: " . ($nextDose ?: 'Not required');
                    break;
                }
                $appointmentUpdate = $pdo->prepare('UPDATE consultations SET ' . implode(', ', $updates) . ' WHERE id = :id');
                $appointmentUpdate->execute($appointmentParams);
            }
            $pdo->commit();
            portalNotifyResident($pdo, $residentId, "A vaccination was recorded on {$dateGiven}. Next dose: " . ($nextDose ?: 'Not required') . '.', 'ResidentDashboard.php?tab=immunization');
            rhuNotifyResidentSchedule($pdo, $residentId, $vaccineName . ' next vaccination dose', $nextDose);
            $_SESSION['nurse_flash_success'] = 'Immunization record saved and resident notified.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['nurse_flash_error'] = 'Immunization Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('immunization'));
        exit;
    }

    if ($action === 'issue_certificate') {
        try {
            if (dbHasTable($pdo, 'certificate_types') && dbHasTable($pdo, 'health_certificates')) {
                $issued = portalIssueResidentCertificate($pdo, $_POST, (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0), 'Public Health Nurse');
                $_SESSION['nurse_flash_success'] = "{$issued['type']} {$issued['number']} was issued and sent to the Resident.";
            } else {
                $fallbackTypes = [
                    1 => 'Nursing Health Assessment Certificate',
                    2 => 'Immunization Status Certificate',
                    3 => 'Vital Signs Assessment Certificate',
                    4 => 'Community Health Clearance',
                ];
                $residentId = (int) ($_POST['resident_id'] ?? 0);
                $typeId = (int) ($_POST['certificate_type_id'] ?? 0);
                $typeName = $fallbackTypes[$typeId] ?? 'Community Health Clearance';
                $purpose = trim((string) ($_POST['purpose'] ?? ''));
                $issueDate = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
                $expiryDate = trim((string) ($_POST['expiry_date'] ?? ''));
                if ($residentId <= 0 || $purpose === '') {
                    throw new InvalidArgumentException('Resident and purpose are required.');
                }
                $certificateNumber = 'NUR-' . date('Ymd-His') . '-' . str_pad((string) $residentId, 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO certificates (resident_id, issued_by_id, certificate_number, certificate_type, purpose, issue_date, expiry_date, status, created_at) VALUES (:resident_id, :issued_by_id, :certificate_number, :certificate_type, :purpose, :issue_date, :expiry_date, 'Issued', NOW())");
                $stmt->execute([
                    'resident_id' => $residentId,
                    'issued_by_id' => (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null,
                    'certificate_number' => $certificateNumber,
                    'certificate_type' => $typeName,
                    'purpose' => $purpose,
                    'issue_date' => $issueDate,
                    'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                ]);
                portalNotifyResident($pdo, $residentId, "{$typeName} {$certificateNumber} was issued by the Public Health Nurse.", "ResidentDashboard.php?tab=certificates");
                $_SESSION['nurse_flash_success'] = "{$typeName} {$certificateNumber} was issued and sent to the Resident.";
            }
        } catch (Throwable $e) {
            $_SESSION['nurse_flash_error'] = 'Certificate Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('overview'));
        exit;
    }

    // Action: Answer / Update Resident Consultation
    if ($action === 'answer_consultation') {
        $cslId = (int) ($_POST['consultation_id'] ?? 0);
        $resId = (int) ($_POST['resident_id'] ?? 0);
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $icd10 = trim($_POST['icd10'] ?? '');
        $notes = trim($_POST['consultation_notes'] ?? '');
        $meds = trim($_POST['medications_prescribed'] ?? '');
        $status = trim($_POST['consultation_status'] ?? 'Completed');
        $allowedStatuses = ['Completed', 'In Progress', 'Scheduled', 'Referred'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'In Progress';
        }

        if ($cslId <= 0) {
            $_SESSION['nurse_flash_error'] = 'No consultation record was submitted. Please reopen the record and try again.';
            header('Location: ' . tabUrl('opd'));
            exit;
        }

        if ($cslId > 0 && !empty($pdo)) {
            try {
                $baseNotes = trim((string)preg_replace('/^\s*(?:ICD-?10|Status):\s*.*$/mi', '', $notes));
                $statusLine = $status !== '' ? "\nStatus: {$status}" : '';
                $icdLine = $icd10 !== '' ? "\nICD-10: {$icd10}" : '';
                $savedClinicalNotes = trim($baseNotes . $icdLine . $statusLine);
                $updateColumns = [
                    'diagnosis = :dx',
                    'remarks = :notes',
                    'medications_prescribed = :meds',
                    'treatment = :treatment',
                ];
                $params = [
                    'dx' => $diagnosis,
                    'notes' => $savedClinicalNotes,
                    'meds' => $meds,
                    'treatment' => $meds,
                    'id' => $cslId
                ];
                if (dbHasColumn($pdo, 'consultations', 'consultation_notes')) {
                    $updateColumns[] = 'consultation_notes = :consultation_notes';
                    $params['consultation_notes'] = $savedClinicalNotes;
                }
                if (dbHasColumn($pdo, 'consultations', 'health_worker_id')) {
                    $updateColumns[] = 'health_worker_id = COALESCE(:health_worker_id, health_worker_id)';
                    $params['health_worker_id'] = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? $_SESSION['rhu_staff_login']['health_worker_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0) ?: null;
                }
                if (dbHasColumn($pdo, 'consultations', 'updated_at')) {
                    $updateColumns[] = 'updated_at = NOW()';
                }
                if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                    $updateColumns[] = 'consultation_status = :saved_consultation_status';
                    $params['saved_consultation_status'] = $status;
                }
                $stmt = $pdo->prepare("UPDATE consultations SET " . implode(', ', $updateColumns) . " WHERE id = :id");
                $stmt->execute($params);
                if ($stmt->rowCount() === 0) {
                    $existsStmt = $pdo->prepare('SELECT id FROM consultations WHERE id = :id LIMIT 1');
                    $existsStmt->execute(['id' => $cslId]);
                    if (!$existsStmt->fetchColumn()) {
                        throw new RuntimeException("Consultation #{$cslId} was not found.");
                    }
                }
                $savedStatus = $status;
                if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                    $statusCheck = $pdo->prepare('SELECT consultation_status FROM consultations WHERE id = :id LIMIT 1');
                    $statusCheck->execute(['id' => $cslId]);
                    $savedStatus = trim((string)($statusCheck->fetchColumn() ?? ''));
                    if ($savedStatus === '') {
                        throw new RuntimeException('Consultation status was not saved.');
                    }
                    if ($savedStatus !== $status) {
                        throw new RuntimeException('Consultation status could not be synchronized.');
                    }
                }
                fhsisSaveInlineServices($pdo, 'consultations', $cslId, $resId, $_POST, $_SESSION);
                portalSaveHealthRecordEntry($pdo, $resId, [
                    'record_type' => 'Nurse consultation response',
                    'diagnosis' => trim($diagnosis . ($icd10 !== '' ? " ({$icd10})" : '')),
                    'notes' => $notes,
                    'medications' => $meds,
                    'status' => $status,
                    'recorded_by_id' => (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null,
                ]);
                $autoDiseaseCase = portalAutoSaveDiseaseCaseFromConsultation($pdo, $cslId, [
                    'resident_id' => $resId,
                    'diagnosis' => $diagnosis,
                    'remarks' => $savedClinicalNotes,
                    'consultation_notes' => $savedClinicalNotes,
                    'health_worker_id' => (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null,
                ]);
                if ($resId > 0) {
                    rhuNotifySavedResidentAppointment($pdo, $cslId, 'Nursing appointment');
                    portalNotifyResident($pdo, $resId, "Your OPD Triage / Consultation request has been updated by the Nurse. Status: {$status}. Assessment: {$diagnosis}", "ResidentDashboard.php?tab=appointments");
                }
                $diseaseMessage = ($autoDiseaseCase && !empty($autoDiseaseCase['created'])) ? ' Disease case auto-saved: ' . $autoDiseaseCase['disease_name'] . '.' : '';
                $_SESSION['nurse_flash_success'] = "Consultation updated successfully. Saved status: {$savedStatus}. Resident and Admin dashboards will show the same status.{$diseaseMessage}";
            } catch (Exception $e) {
                $_SESSION['nurse_flash_error'] = 'Error updating consultation: ' . $e->getMessage();
            }
        }
        $returnTab = ($_POST['return_tab'] ?? '') === 'opd' ? 'opd' : 'overview';
        header('Location: ' . tabUrl($returnTab));
        exit;
    }

    // Action: Save New OPD Triage & Vitals
    if ($action === 'save_triage') {
        $residentId = (int) ($_POST['resident_id'] ?? 0);
        $nurseStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? $_SESSION['rhu_staff_login']['health_worker_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0);
        $nurseUserId = (int) ($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0);
        $physicianId = $nurseStaffId ?: (int) ($_POST['physician_id'] ?? 1);
        $chiefComplaint = trim($_POST['chief_complaint'] ?? '');
        $bp = trim($_POST['bp'] ?? '120/80');
        $temp = trim($_POST['temp'] ?? '36.8°C');
        $weight = trim($_POST['weight'] ?? '60 kg');
        $rr = trim($_POST['rr'] ?? '18/min');
        $hr = trim($_POST['hr'] ?? '75 bpm');
        $diagnosis = trim($_POST['diagnosis'] ?? 'OPD Patient Evaluation');
        $icd10 = trim($_POST['icd10'] ?? 'Z00.0');
        $meds = trim($_POST['medications'] ?? '');

        if ($residentId <= 0 || empty($chiefComplaint)) {
            $_SESSION['nurse_flash_error'] = 'Please select a valid resident patient and fill chief complaint.';
        } else {
            try {
                $notes = "NURSING TRIAGE VITALS: BP: {$bp}, Temp: {$temp}, Wt: {$weight}, RR: {$rr}, HR: {$hr}.";
                if ($icd10 !== '') {
                    $notes .= " ICD-10: {$icd10}.";
                }
                $insertColumns = ['resident_id', 'health_worker_id', 'consultation_date', 'consultation_time', 'chief_complaint', 'diagnosis', 'treatment', 'medications_prescribed', 'remarks'];
                $insertValues = [':res', ':health_worker_id', 'CURDATE()', 'CURTIME()', ':chief', ':dx', ':treatment', ':meds', ':notes'];
                $insertParams = [
                    'res' => $residentId,
                    'health_worker_id' => $nurseStaffId ?: null,
                    'chief' => $chiefComplaint,
                    'dx' => $diagnosis,
                    'treatment' => $meds,
                    'meds' => $meds,
                    'notes' => $notes
                ];
                if (dbHasColumn($pdo, 'consultations', 'consultation_notes')) {
                    $insertColumns[] = 'consultation_notes';
                    $insertValues[] = ':consultation_notes';
                    $insertParams['consultation_notes'] = $notes;
                }
                if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                    $insertColumns[] = 'consultation_status';
                    $insertValues[] = ':consultation_status';
                    $insertParams['consultation_status'] = 'In Progress';
                }
                if (dbHasColumn($pdo, 'consultations', 'physician_id')) {
                    $insertColumns[] = 'physician_id';
                    $insertValues[] = ':physician_id';
                    $insertParams['physician_id'] = $physicianId ?: null;
                }
                $stmt = $pdo->prepare("INSERT INTO consultations (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")");
                $stmt->execute($insertParams);
                $consultationId = (int)$pdo->lastInsertId();
                portalSaveHealthRecordEntry($pdo, $residentId, [
                    'record_type' => 'OPD triage and vitals',
                    'chief_complaint' => $chiefComplaint,
                    'diagnosis' => trim($diagnosis . ($icd10 !== '' ? " ({$icd10})" : '')),
                    'medications' => $meds,
                    'bp' => $bp,
                    'temp' => $temp,
                    'weight' => $weight,
                    'notes' => "RR: {$rr}; HR: {$hr}",
                    'status' => 'Triage recorded',
                    'recorded_by_id' => $nurseStaffId ?: ($nurseUserId ?: null),
                ]);
                $autoDiseaseCase = portalAutoSaveDiseaseCaseFromConsultation($pdo, $consultationId, [
                    'resident_id' => $residentId,
                    'health_worker_id' => $nurseStaffId ?: null,
                    'chief_complaint' => $chiefComplaint,
                    'diagnosis' => $diagnosis,
                    'remarks' => $notes,
                    'consultation_notes' => $notes,
                ]);
                nurseEnsureResidentHealthProfiles($pdo);
                $profileColumns = ['resident_id', 'weight', 'blood_pressure', 'last_checkup_date', 'created_at', 'updated_at'];
                $profileValues = [':resident_id', ':weight', ':blood_pressure', 'CURDATE()', 'NOW()', 'NOW()'];
                $profileUpdates = [
                    'weight = VALUES(weight)',
                    'blood_pressure = VALUES(blood_pressure)',
                    'last_checkup_date = VALUES(last_checkup_date)',
                    'updated_at = NOW()',
                ];
                $profileParams = [
                    'resident_id' => $residentId,
                    'weight' => portalNumericValue($weight),
                    'blood_pressure' => $bp !== '' ? $bp : null,
                ];
                if (dbHasColumn($pdo, 'resident_health_profiles', 'temperature')) {
                    $profileColumns[] = 'temperature';
                    $profileValues[] = ':temperature';
                    $profileUpdates[] = 'temperature = VALUES(temperature)';
                    $profileParams['temperature'] = portalNumericValue($temp);
                }
                if (dbHasColumn($pdo, 'resident_health_profiles', 'current_medications')) {
                    $profileColumns[] = 'current_medications';
                    $profileValues[] = ':current_medications';
                    $profileUpdates[] = 'current_medications = VALUES(current_medications)';
                    $profileParams['current_medications'] = $meds !== '' ? $meds : null;
                }
                $profileStmt = $pdo->prepare("
                    INSERT INTO resident_health_profiles (" . implode(', ', $profileColumns) . ")
                    VALUES (" . implode(', ', $profileValues) . ")
                    ON DUPLICATE KEY UPDATE " . implode(', ', $profileUpdates)
                );
                $profileStmt->execute($profileParams);
                portalNotifyResident($pdo, $residentId, "Your OPD Triage & Vital Signs (BP: {$bp}, Temp: {$temp}) were recorded by Public Health Nurse.", "ResidentDashboard.php?tab=history");
                portalNotify($pdo, "New OPD Triage recorded for resident patient", null, 'PHYSICIAN', "RHUAdminDashboard.php");
                $diseaseMessage = ($autoDiseaseCase && !empty($autoDiseaseCase['created'])) ? ' Disease case auto-saved: ' . $autoDiseaseCase['disease_name'] . '.' : '';
                $_SESSION['nurse_flash_success'] = 'New OPD Triage record and vital signs saved successfully into database!' . $diseaseMessage;
            } catch (Exception $e) {
                $_SESSION['nurse_flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('opd'));
        exit;
    }

    // Action: Save Resident Medical Record & Profile Info
    if ($action === 'save_patient_record') {
        nurseEnsureResidentHealthProfiles($pdo);
        $residentId = (int) ($_POST['resident_id'] ?? 0);

        $height = trim($_POST['height'] ?? '');
        $weight = trim($_POST['weight'] ?? '');
        $bp = trim($_POST['blood_pressure'] ?? '');
        $allergies = trim($_POST['allergies'] ?? '');
        $medHistory = trim($_POST['medical_history'] ?? '');
        $bloodType = trim($_POST['blood_type'] ?? '');
        $philhealthNumber = trim($_POST['philhealth_id'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');

        try {
            $nurseStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
            $nurseUserId = (int) ($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0);
            $gender = trim((string) ($_POST['gender'] ?? ''));
            if (($residentId <= 0 || $gender !== '') && !in_array($gender, ['Male', 'Female'], true)) {
                throw new RuntimeException('Please select Male or Female for the resident gender.');
            }
            $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
            if ($residentId <= 0 || $dob !== '') {
                $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
                if (!$birthDate || $birthDate->format('Y-m-d') !== $dob || $birthDate > new DateTimeImmutable('today')) {
                    throw new RuntimeException('Please enter a valid date of birth that is not in the future.');
                }
            }

            if ($residentId <= 0) {
                // Register NEW Resident
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $barangay = trim($_POST['barangay'] ?? 'Poblacion');
                $barangayId = nurseBarangayId($pdo, $barangay);
                $philhealthId = trim($_POST['philhealth_id'] ?? '') ?: trim((string)($_POST['rhu_intake']['philhealth_number'] ?? ''));
                $philhealthNumber = $philhealthId;

                if (empty($firstName) || empty($lastName)) {
                    $_SESSION['nurse_flash_error'] = 'First name and last name are required for new resident registration.';
                    if ($isAjaxRequest) {
                        $nurseJsonRespond(['success' => false, 'error' => $_SESSION['nurse_flash_error']], 422);
                    }
                    header('Location: ' . tabUrl('patients'));
                    exit;
                }
                $duplicate = $pdo->prepare('SELECT id FROM residents WHERE first_name = ? AND last_name = ? AND birthdate = ? LIMIT 1');
                $duplicate->execute([$firstName, $lastName, $dob]);
                if ($duplicate->fetchColumn()) throw new RuntimeException('This resident already has a record. Select the existing resident to record this visit.');
                $insRes = $pdo->prepare("INSERT INTO residents (first_name, last_name, birthdate, sex, barangay_id, address, blood_type, philhealth_number, contact_number, created_at) VALUES (:fn, :ln, :dob, :sex, :barangay_id, :addr, :bt, :phi, :cn, NOW())");
                $insRes->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'dob' => $dob,
                    'sex' => $gender,
                    'barangay_id' => $barangayId,
                    'addr' => trim($_POST['address'] ?? '') ?: $barangay,
                    'bt' => $bloodType !== '' ? $bloodType : null,
                    'phi' => $philhealthId,
                    'cn' => $contactNumber
                ]);
                $residentId = (int) $pdo->lastInsertId();
            } else {
                $identityStmt = $pdo->prepare('SELECT philhealth_number, contact_number FROM residents WHERE id = ?');
                $identityStmt->execute([$residentId]);
                $savedIdentity = $identityStmt->fetch(PDO::FETCH_ASSOC);
                if (!$savedIdentity) throw new RuntimeException('Resident not found.');
                $philhealthNumber = (string)($savedIdentity['philhealth_number'] ?? '');
                $contactNumber = (string)($savedIdentity['contact_number'] ?? '');
                $residentBloodTypeSql = dbHasColumn($pdo, 'residents', 'blood_type')
                    ? "NULLIF(TRIM(r.blood_type), '')"
                    : 'NULL';
                $savedBloodTypeStmt = $pdo->prepare("
                    SELECT COALESCE({$residentBloodTypeSql},
                        (SELECT NULLIF(TRIM(p.blood_type), '') FROM resident_health_profiles p WHERE p.resident_id = r.id LIMIT 1),
                        (SELECT NULLIF(TRIM(h.blood_type), '') FROM health_records h WHERE h.resident_id = r.id AND NULLIF(TRIM(h.blood_type), '') IS NOT NULL ORDER BY h.id DESC LIMIT 1), '')
                    FROM residents r WHERE r.id = :resident_id
                ");
                $savedBloodTypeStmt->execute(['resident_id' => $residentId]);
                $savedBloodType = trim((string) $savedBloodTypeStmt->fetchColumn());
                if ($savedBloodType !== '') {
                    $bloodType = $savedBloodType;
                }
                $residentAllergiesSql = dbHasColumn($pdo, 'residents', 'allergies')
                    ? "NULLIF(TRIM(r.allergies), '')" : 'NULL';
                {
                    $savedAllergiesStmt = $pdo->prepare("SELECT COALESCE(
                        {$residentAllergiesSql},
                        (SELECT NULLIF(TRIM(p.allergies), '') FROM resident_health_profiles p WHERE p.resident_id = r.id LIMIT 1),
                        (SELECT NULLIF(TRIM(h.allergies), '') FROM health_records h WHERE h.resident_id = r.id AND NULLIF(TRIM(h.allergies), '') IS NOT NULL ORDER BY h.id DESC LIMIT 1), '')
                        FROM residents r WHERE r.id = :resident_id");
                    $savedAllergiesStmt->execute(['resident_id' => $residentId]);
                    $savedAllergies = trim((string) $savedAllergiesStmt->fetchColumn());
                    if ($savedAllergies !== '') {
                        $allergies = $savedAllergies;
                    }
                }
                $residentUpdateColumns = [];
                $residentUpdateParams = ['resident_id' => $residentId];
                if ($dob !== '') {
                    $residentUpdateColumns[] = 'birthdate = :birthdate';
                    $residentUpdateParams['birthdate'] = $dob;
                    if (dbHasColumn($pdo, 'residents', 'date_of_birth')) {
                        $residentUpdateColumns[] = 'date_of_birth = :date_of_birth';
                        $residentUpdateParams['date_of_birth'] = $dob;
                    }
                }
                if ($gender !== '') {
                    $residentUpdateColumns[] = 'sex = :sex';
                    $residentUpdateParams['sex'] = $gender;
                }
                if (dbHasColumn($pdo, 'residents', 'blood_type')) {
                    $residentUpdateColumns[] = 'blood_type = :blood_type';
                    $residentUpdateParams['blood_type'] = $bloodType !== '' ? $bloodType : null;
                }
                if (dbHasColumn($pdo, 'residents', 'philhealth_number')) {
                    $residentUpdateColumns[] = 'philhealth_number = :philhealth_number';
                    $residentUpdateParams['philhealth_number'] = $philhealthNumber !== '' ? $philhealthNumber : null;
                }
                if (dbHasColumn($pdo, 'residents', 'philhealth_id')) {
                    $residentUpdateColumns[] = 'philhealth_id = :philhealth_id';
                    $residentUpdateParams['philhealth_id'] = $philhealthNumber !== '' ? $philhealthNumber : null;
                }
                if (dbHasColumn($pdo, 'residents', 'contact_number')) {
                    $residentUpdateColumns[] = 'contact_number = :contact_number';
                    $residentUpdateParams['contact_number'] = $contactNumber !== '' ? $contactNumber : null;
                }
                if ($residentUpdateColumns) {
                    $upRes = $pdo->prepare("UPDATE residents SET " . implode(', ', $residentUpdateColumns) . " WHERE id = :resident_id");
                    $upRes->execute($residentUpdateParams);
                }
            }

            rhuIntakeSave($pdo, $residentId, $_POST);
            // Save or Update health profile vitals record for the resident
            $chkHp = $pdo->prepare("SELECT id FROM health_records WHERE resident_id = :rid ORDER BY id DESC LIMIT 1");
            $chkHp->execute(['rid' => $residentId]);
            $healthRecordId = 0; // Each visit retains its own measurements.
            if ($healthRecordId > 0) {
                $upHp = $pdo->prepare("UPDATE health_records SET blood_type = :bt, height_cm = :h, weight_kg = :w, blood_pressure = :bp, allergies = :alg, medical_conditions = :mc, last_checkup_date = CURDATE(), recorded_by_id = :recorded_by, updated_at = NOW() WHERE id = :id");
                $upHp->execute([
                    'bt' => $bloodType,
                    'h' => !empty($height) ? (float) $height : null,
                    'w' => !empty($weight) ? (float) $weight : null,
                    'bp' => $bp,
                    'alg' => $allergies,
                    'mc' => $medHistory,
                    'recorded_by' => $nurseStaffId ?: ($nurseUserId ?: null),
                    'id' => $healthRecordId,
                ]);
            } else {
                $insHp = $pdo->prepare("INSERT INTO health_records (resident_id, blood_type, height_cm, weight_kg, blood_pressure, allergies, medical_conditions, last_checkup_date, recorded_by_id, created_at) VALUES (:rid, :bt, :h, :w, :bp, :alg, :mc, CURDATE(), :recorded_by, NOW())");
                $insHp->execute([
                    'rid' => $residentId,
                    'bt' => $bloodType,
                    'h' => !empty($height) ? (float) $height : null,
                    'w' => !empty($weight) ? (float) $weight : null,
                    'bp' => $bp,
                    'alg' => $allergies,
                    'mc' => $medHistory,
                    'recorded_by' => $nurseStaffId ?: ($nurseUserId ?: null),
                ]);
            }

            $newVisitHealthRecordId = (int)$pdo->lastInsertId();
            nurseEnsureResidentHealthProfiles($pdo);
            $profileStmt = $pdo->prepare("
                INSERT INTO resident_health_profiles (
                    resident_id, height, weight, blood_pressure, blood_type, philhealth_number,
                    allergies, chronic_conditions, last_checkup_date, created_at, updated_at
                ) VALUES (
                    :resident_id, :height, :weight, :blood_pressure, :blood_type, :philhealth_number,
                    :allergies, :chronic_conditions, CURDATE(), NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    height = VALUES(height),
                    weight = VALUES(weight),
                    blood_pressure = VALUES(blood_pressure),
                    blood_type = VALUES(blood_type),
                    philhealth_number = VALUES(philhealth_number),
                    allergies = VALUES(allergies),
                    chronic_conditions = VALUES(chronic_conditions),
                    last_checkup_date = VALUES(last_checkup_date),
                    updated_at = NOW()
            ");
            $profileStmt->execute([
                'resident_id' => $residentId,
                'height' => $height !== '' ? (float)$height : null,
                'weight' => $weight !== '' ? (float)$weight : null,
                'blood_pressure' => $bp,
                'blood_type' => $bloodType !== '' ? $bloodType : null,
                'philhealth_number' => $philhealthNumber !== '' ? $philhealthNumber : null,
                'allergies' => $allergies,
                'chronic_conditions' => $medHistory,
            ]);

            if ($residentId > 0) {
                $summaryParts = [];
                if ($bp !== '') $summaryParts[] = "BP: {$bp}";
                if ($bloodType !== '') $summaryParts[] = "Blood type: {$bloodType}";
                $summary = $summaryParts ? ' (' . implode(', ', $summaryParts) . ')' : '';
                portalNotifyResident($pdo, $residentId, "Your patient medical record was updated by the Public Health Nurse{$summary}.", "ResidentDashboard.php?tab=records");
            }

            $visitData = $_POST;
            $visitData['rhu_visit'] = array_merge($_POST['rhu_visit'] ?? [], ['bp'=>$bp, 'height'=>$height, 'weight'=>$weight]);
            // The health record above holds BP/height/weight; attach the remaining visit fields to that same row.
            $visitNotes = [];
            foreach (['chief_complaint'=>'Chief complaint','treatment'=>'Treatment / services rendered','diagnosis'=>'Diagnosis','visit_remarks'=>'Remarks'] as $key=>$label) {
                if (trim((string)($_POST[$key] ?? '')) !== '') $visitNotes[] = $label . ': ' . trim($_POST[$key]);
            }
            foreach (['rr'=>'Respiratory rate','pr'=>'Pulse rate'] as $key=>$label) {
                if (($visitData['rhu_visit'][$key] ?? '') !== '') $visitNotes[] = $label . ': ' . (float)$visitData['rhu_visit'][$key];
            }
            $visitUpdate = $pdo->prepare('UPDATE health_records SET temperature = ?, remarks = ? WHERE id = ? AND resident_id = ?');
            $visitUpdate->execute([portalNumericValue($visitData['rhu_visit']['temperature'] ?? null), implode("\n", $visitNotes), $newVisitHealthRecordId, $residentId]);
            fhsisSaveInlineServices($pdo, 'health_records', $newVisitHealthRecordId, $residentId, $_POST, $_SESSION);
            $_SESSION['nurse_flash_success'] = 'Resident medical profile saved successfully into database!';
            if ($isAjaxRequest) {
                $nurseJsonRespond([
                    'success' => true,
                    'message' => $_SESSION['nurse_flash_success'],
                    'resident' => [
                        'id' => $residentId,
                        'bloodType' => $bloodType,
                        'gender' => $gender,
                        'philhealthNo' => $philhealthNumber,
                        'contactNo' => $contactNumber,
                        'height' => $height,
                        'weight' => $weight,
                        'bloodPressure' => $bp,
                        'allergies' => $allergies,
                        'medicalHistory' => $medHistory,
                    ],
                ]);
            }
        } catch (Exception $e) {
            $_SESSION['nurse_flash_error'] = 'Database Error: ' . $e->getMessage();
            if ($isAjaxRequest) {
                $nurseJsonRespond(['success' => false, 'error' => $_SESSION['nurse_flash_error']], 500);
            }
        }
        header('Location: ' . tabUrl('patients'));
        exit;
    }
}

// ----------------------------------------------------
// 2. LIVE MYSQL DATA HYDRATION FROM DATABASE `rhu`
// ----------------------------------------------------
$opdConsultations = [];
$patientRecords = [];
$patientProfilesMap = [];
$residentRequests = [];
$immunizationRecords = [];
$nutritionCases = [];
$tbCases = [];
$diseaseReports = [];

$allResidentsList = [];
$nurseCertificateTypes = [];
$allStaffList = [];
$selectedResidentData = null;

if (!empty($pdo)) {
    try {
        nurseEnsureResidentHealthProfiles($pdo);

        // Dropdown option queries
        $allResidentsList = $pdo->query("
            SELECT r.id, TRIM(CONCAT(COALESCE(r.first_name, ''), ' ', COALESCE(r.last_name, ''))) as name, COALESCE(b.name, 'Unassigned') as barangay
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE " . rhuLivingResidentWhereSql('r') . "
            ORDER BY r.first_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (dbHasTable($pdo, 'certificate_types')) {
            $nurseCertificateTypes = portalEnsureCertificateTypes($pdo, ['Nursing Health Assessment Certificate', 'Immunization Status Certificate', 'Vital Signs Assessment Certificate', 'Community Health Clearance']);
        } else {
            $nurseCertificateTypes = [
                ['id' => 1, 'certificate_type_name' => 'Nursing Health Assessment Certificate'],
                ['id' => 2, 'certificate_type_name' => 'Immunization Status Certificate'],
                ['id' => 3, 'certificate_type_name' => 'Vital Signs Assessment Certificate'],
                ['id' => 4, 'certificate_type_name' => 'Community Health Clearance'],
            ];
        }
        $allStaffList = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, position_title as staff_type FROM health_workers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (dbHasTable($pdo, 'messages') && dbHasTable($pdo, 'consultations')) {
            try {
                $requestMessages = $pdo->query("
                    SELECT m.id, m.resident_id, m.subject, m.message, m.status, m.created_at
                    FROM messages m
                    WHERE m.resident_id > 0
                      AND LOWER(CONCAT(COALESCE(m.subject, ''), ' ', COALESCE(m.message, ''))) REGEXP 'appointment|opd|checkup|check-up|consult|consultation|medical|nurse|doctor|physician|vaccination|immunization|vaccine'
                    ORDER BY m.id ASC
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($requestMessages as $messageRow) {
                    $residentRequestId = (int)($messageRow['resident_id'] ?? 0);
                    $subject = trim((string)($messageRow['subject'] ?? 'Resident Request'));
                    $messageText = trim((string)($messageRow['message'] ?? ''));
                    $createdAt = trim((string)($messageRow['created_at'] ?? ''));
                    if ($residentRequestId <= 0 || $messageText === '') {
                        continue;
                    }

                    $notesDuplicateCondition = dbHasColumn($pdo, 'consultations', 'consultation_notes')
                        ? "(remarks LIKE :message_marker_remarks OR consultation_notes LIKE :message_marker_notes)"
                        : "remarks LIKE :message_marker_remarks";
                    $duplicateCheck = $pdo->prepare("
                        SELECT id
                        FROM consultations
                        WHERE resident_id = :resident_id
                          AND chief_complaint = :chief_complaint
                          AND {$notesDuplicateCondition}
                        LIMIT 1
                    ");
                    $messageMarker = '%Resident Message #' . (int)$messageRow['id'] . '%';
                    $chiefComplaint = "[Resident Message] {$subject}";
                    $duplicateParams = [
                        'resident_id' => $residentRequestId,
                        'chief_complaint' => $chiefComplaint,
                        'message_marker_remarks' => $messageMarker,
                    ];
                    if (dbHasColumn($pdo, 'consultations', 'consultation_notes')) {
                        $duplicateParams['message_marker_notes'] = $messageMarker;
                    }
                    $duplicateCheck->execute($duplicateParams);
                    if ($duplicateCheck->fetchColumn()) {
                        continue;
                    }

                    $consultationColumns = ['resident_id', 'consultation_date', 'consultation_time', 'chief_complaint', 'diagnosis', 'remarks', 'created_at'];
                    $consultationValues = [':resident_id', ':consultation_date', 'CURTIME()', ':chief_complaint', ':diagnosis', ':remarks', ':created_at'];
                    $consultationParams = [
                        'resident_id' => $residentRequestId,
                        'consultation_date' => $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : date('Y-m-d'),
                        'chief_complaint' => $chiefComplaint,
                        'diagnosis' => 'Pending OPD Triage',
                        'remarks' => "Resident Message #" . (int)$messageRow['id'] . "\n{$messageText}",
                        'created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
                    ];
                    if (dbHasColumn($pdo, 'consultations', 'consultation_notes')) {
                        $consultationColumns[] = 'consultation_notes';
                        $consultationValues[] = ':consultation_notes';
                        $consultationParams['consultation_notes'] = $consultationParams['remarks'];
                    }
                    if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                        $consultationColumns[] = 'consultation_status';
                        $consultationValues[] = ':consultation_status';
                        $consultationParams['consultation_status'] = 'Scheduled';
                    }
                    if (dbHasColumn($pdo, 'consultations', 'health_worker_id')) {
                        $consultationColumns[] = 'health_worker_id';
                        $consultationValues[] = ':health_worker_id';
                        $consultationParams['health_worker_id'] = (int)($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null;
                    }
                    if (dbHasColumn($pdo, 'consultations', 'physician_id')) {
                        $consultationColumns[] = 'physician_id';
                        $consultationValues[] = ':physician_id';
                        $consultationParams['physician_id'] = (int)($pdo->query("SELECT id FROM health_workers ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
                    }
                    $importMessageStmt = $pdo->prepare("INSERT INTO consultations (" . implode(', ', $consultationColumns) . ") VALUES (" . implode(', ', $consultationValues) . ")");
                    $importMessageStmt->execute($consultationParams);
                }
            } catch (Throwable $messageImportError) {
                error_log('NurseDashboard message request import: ' . $messageImportError->getMessage());
            }
        }

        // 1. Nurse appointments and unassigned general/nursing triage.
        $consultationNotesSelect = dbHasColumn($pdo, 'consultations', 'consultation_notes')
            ? 'COALESCE(c.consultation_notes, c.remarks)'
            : 'c.remarks';
        // Legacy schemas save the explicit status in clinical notes instead of a column.
        $statusNotes = "CONCAT_WS(CHAR(10), NULLIF(c.remarks, ''), NULLIF({$consultationNotesSelect}, ''))";
        $savedNotesStatus = "TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX({$statusNotes}, 'Status:', -1), CHAR(10), 1))";
        $fallbackStatus = "CASE
            WHEN {$statusNotes} LIKE '%Status:%'
                AND {$savedNotesStatus} IN ('Completed', 'In Progress', 'Scheduled', 'Referred', 'Cancelled', 'No-show')
                THEN {$savedNotesStatus}
            WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' AND c.diagnosis <> 'Pending OPD Triage' THEN 'Completed'
            WHEN c.follow_up_date IS NOT NULL THEN 'Scheduled'
            ELSE 'In Progress' END";
        $consultationStatusSelect = dbHasColumn($pdo, 'consultations', 'consultation_status')
            ? "CASE
                WHEN TRIM(c.consultation_status) IN ('Completed', 'Cancelled', 'No-show', 'Referred')
                    THEN TRIM(c.consultation_status)
                WHEN {$statusNotes} LIKE '%Status:%'
                    AND {$savedNotesStatus} IN ('Completed', 'In Progress', 'Scheduled', 'Referred', 'Cancelled', 'No-show')
                    THEN {$savedNotesStatus}
                WHEN TRIM(c.consultation_status) = 'Scheduled'
                    AND c.diagnosis LIKE 'Vaccination administered:%' THEN 'Completed'
                ELSE COALESCE(NULLIF(TRIM(c.consultation_status), ''), {$fallbackStatus}) END"
            : $fallbackStatus;
        $nurseStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? $_SESSION['rhu_staff_login']['health_worker_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0);
        $assignmentWhere = 'WHERE 1 = 0';
        $assignmentParams = [];
        $unassignedParts = [];
        foreach (['health_worker_id', 'physician_id'] as $providerColumn) {
            if (dbHasColumn($pdo, 'consultations', $providerColumn)) {
                $unassignedParts[] = "COALESCE(c.{$providerColumn}, 0) = 0";
            }
        }
        $appointmentTextScope = "LOWER(CONCAT_WS(' ', COALESCE(c.chief_complaint, ''), COALESCE({$consultationNotesSelect}, '')))";
        $nurseRequestScope = "({$appointmentTextScope} REGEXP 'vaccination|immunization|vaccine' OR {$appointmentTextScope} NOT REGEXP 'dental|dentist|tooth|oral|prenatal|maternal|laboratory|blood work|blood test|diagnostic|sanitary|inspection|clearance')";
        $nurseAppointmentScope = "{$appointmentTextScope} REGEXP 'nurse|nursing|public health nurse|vaccination|immunization|vaccine|epi|health checkup|checkup'";
        $nursingTriageRecordScope = "({$statusNotes} LIKE '%NURSING TRIAGE VITALS:%' OR {$statusNotes} LIKE '%OPD triage and vitals%')";
        $generalMedicalAppointmentScope = "({$statusNotes} LIKE '%Appointment Category: General Medical Consultation |%' OR c.chief_complaint LIKE '[General Medical Consultation]%')";
        $residentPortalNurseAppointmentScope = "({$statusNotes} LIKE '%Requested via Resident Portal%' AND {$consultationStatusSelect} NOT IN ('Completed', 'Cancelled', 'No-show') AND ({$generalMedicalAppointmentScope} OR ({$nurseRequestScope} AND {$nurseAppointmentScope})))";
        $savedNursingTriageScope = "({$nursingTriageRecordScope} AND {$nurseRequestScope})";
        if ($nurseStaffId > 0) {
            $assignmentParts = [];
            if (dbHasColumn($pdo, 'consultations', 'health_worker_id')) {
                $assignmentParts[] = 'c.health_worker_id = :nurse_staff_id_hw';
                $assignmentParams['nurse_staff_id_hw'] = $nurseStaffId;
            }
            if (dbHasColumn($pdo, 'consultations', 'physician_id')) {
                $assignmentParts[] = 'c.physician_id = :nurse_staff_id_phy';
                $assignmentParams['nurse_staff_id_phy'] = $nurseStaffId;
            }
            if ($assignmentParts) {
                $unassignedProviderScope = $unassignedParts ? '(' . implode(' AND ', $unassignedParts) . ')' : '1 = 1';
                $assignmentWhere = 'WHERE ((' . implode(' OR ', $assignmentParts) . ") OR {$residentPortalNurseAppointmentScope} OR {$savedNursingTriageScope} OR ({$unassignedProviderScope} AND (((c.diagnosis = :unassigned_opd_diagnosis AND " . $consultationStatusSelect . " IN ('Scheduled', 'In Progress', 'Pending')) AND {$nurseAppointmentScope}) OR {$nursingTriageRecordScope}) AND " . $nurseRequestScope . '))';
                $assignmentParams['unassigned_opd_diagnosis'] = 'Pending OPD Triage';
            }
            if (!$assignmentParts) {
                $assignmentWhere = "WHERE ({$residentPortalNurseAppointmentScope} OR {$savedNursingTriageScope})";
            }
        } else {
            $assignmentWhere = "WHERE ({$residentPortalNurseAppointmentScope} OR {$savedNursingTriageScope})";
        }
        $appointmentTimeSelect = dbHasColumn($pdo, 'consultations', 'consultation_time') ? 'c.consultation_time' : 'NULL';
        $opdStmt = $pdo->prepare("
            SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName,
                   TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age, r.sex as gender,
                   c.chief_complaint as chiefComplaint, c.diagnosis,
                   CASE WHEN c.remarks REGEXP 'ICD-10:[[:space:]]*[A-Z0-9.]+' THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.remarks, 'ICD-10:', -1), '.', 1)) ELSE '' END as icd10,
                   c.medications_prescribed as medications, COALESCE(b.name, 'Unassigned') as barangay,
                   c.consultation_date as date, {$appointmentTimeSelect} AS time, c.follow_up_date, 0 as referral_needed, '' as referral_to,
                   {$consultationNotesSelect} as consultation_notes,
                   {$consultationStatusSelect} AS consultation_status
            FROM consultations c
            JOIN residents r ON c.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            {$assignmentWhere}
              AND {$consultationStatusSelect} NOT IN ('Completed', 'Cancelled', 'No-show')
              AND " . rhuLivingResidentWhereSql('r') . "
            ORDER BY c.id DESC
        ");
        $opdStmt->execute($assignmentParams);
        $opdConsultations = $opdStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $opdConsultationsById = [];
        foreach ($opdConsultations as $consultationRow) {
            $opdConsultationsById[(int)($consultationRow['id'] ?? 0)] = $consultationRow;
        }

        $residentAppointmentStmt = $pdo->prepare("
            SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName,
                   TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age, r.sex as gender,
                   c.chief_complaint as chiefComplaint, c.diagnosis,
                   CASE WHEN c.remarks REGEXP 'ICD-10:[[:space:]]*[A-Z0-9.]+' THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.remarks, 'ICD-10:', -1), '.', 1)) ELSE '' END as icd10,
                   c.medications_prescribed as medications, COALESCE(b.name, 'Unassigned') as barangay,
                   c.consultation_date as date, {$appointmentTimeSelect} AS time, c.follow_up_date, 0 as referral_needed, '' as referral_to,
                   {$consultationNotesSelect} as consultation_notes,
                   {$consultationStatusSelect} AS consultation_status
            FROM consultations c
            JOIN residents r ON c.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE c.resident_id > 0
              AND " . rhuLivingResidentWhereSql('r') . "
              AND {$residentPortalNurseAppointmentScope}
            ORDER BY c.id DESC
            LIMIT 200
        ");
        $residentAppointmentStmt->execute();
        foreach (($residentAppointmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $appointmentRow) {
            $opdConsultationsById[(int)($appointmentRow['id'] ?? 0)] = $appointmentRow;
        }
        $opdConsultations = rhuWithoutExpiredAppointments(array_values($opdConsultationsById));
        foreach ($opdConsultations as &$consultationRow) {
            $clinicalNotes = (string)($consultationRow['consultation_notes'] ?? '');
            // Stop at the code even when legacy notes concatenate the status label.
            preg_match_all('/ICD-?10:\s*([A-Z][0-9]{2}(?:\.[A-Z0-9]{1,4})?)/i', $clinicalNotes, $icdMatches);
            $consultationRow['icd10'] = !empty($icdMatches[1]) ? strtoupper(end($icdMatches[1])) : '';
        }
        unset($consultationRow);
        usort($opdConsultations, static function (array $left, array $right): int {
            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        // 2. Patient Records (Residents + Health Profiles)
        $residentDobSelect = dbHasColumn($pdo, 'residents', 'date_of_birth')
            ? "COALESCE(NULLIF(r.date_of_birth, '0000-00-00'), NULLIF(r.birthdate, '0000-00-00'))"
            : "NULLIF(r.birthdate, '0000-00-00')";
        $residentAgeSelect = dbHasColumn($pdo, 'residents', 'date_of_birth')
            ? "TIMESTAMPDIFF(YEAR, {$residentDobSelect}, CURDATE())"
            : "TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE())";
        $residentPhilhealthSelect = dbHasColumn($pdo, 'residents', 'philhealth_number') && dbHasColumn($pdo, 'residents', 'philhealth_id')
            ? "COALESCE(r.philhealth_number, r.philhealth_id)"
            : (dbHasColumn($pdo, 'residents', 'philhealth_number') ? "r.philhealth_number" : (dbHasColumn($pdo, 'residents', 'philhealth_id') ? "r.philhealth_id" : "''"));
        $residentBloodTypeSelect = dbHasColumn($pdo, 'residents', 'blood_type')
            ? "COALESCE(NULLIF(TRIM(r.blood_type), ''), '')"
            : "''";
        $residentAllergiesSelect = dbHasColumn($pdo, 'residents', 'allergies')
            ? "COALESCE(TRIM(r.allergies), '')"
            : "''";
        $residentContactSelect = dbHasColumn($pdo, 'residents', 'contact_number')
            ? "COALESCE(NULLIF(TRIM(r.contact_number), ''), '')"
            : "''";
        $residentEmailSelect = dbHasColumn($pdo, 'residents', 'email') ? 'r.email' : "''";
        $residentCivilStatusSelect = dbHasColumn($pdo, 'residents', 'civil_status') ? 'r.civil_status' : "''";
        $residentEmergencyNameSelect = dbHasColumn($pdo, 'residents', 'emergency_contact_name') ? 'r.emergency_contact_name' : "''";
        $residentEmergencyNumberSelect = dbHasColumn($pdo, 'residents', 'emergency_contact_phone') && dbHasColumn($pdo, 'residents', 'emergency_contact_number')
            ? "COALESCE(r.emergency_contact_number, r.emergency_contact_phone, '')"
            : (dbHasColumn($pdo, 'residents', 'emergency_contact_number') ? "COALESCE(r.emergency_contact_number, '')" : (dbHasColumn($pdo, 'residents', 'emergency_contact_phone') ? "COALESCE(r.emergency_contact_phone, '')" : "''"));
        $residentEmergencyRelationshipSelect = dbHasColumn($pdo, 'residents', 'emergency_contact_relationship') ? 'r.emergency_contact_relationship' : "''";
        $patStmt = $pdo->query("
            SELECT r.id, CONCAT(r.first_name, ' ', r.last_name) as name, r.first_name, r.middle_name, r.last_name,
                   {$residentDobSelect} as date_of_birth,
                   {$residentAgeSelect} as age, r.sex as gender,
                   {$residentCivilStatusSelect} as civil_status, {$residentEmailSelect} as email, r.address,
                   COALESCE(NULLIF({$residentBloodTypeSelect}, ''), NULLIF(TRIM(rhp.blood_type), ''),
                       (SELECT NULLIF(TRIM(hbt.blood_type), '') FROM health_records hbt
                        WHERE hbt.resident_id = r.id AND NULLIF(TRIM(hbt.blood_type), '') IS NOT NULL
                        ORDER BY hbt.id DESC LIMIT 1), '') as bloodType,
                   COALESCE(b.name, 'Unassigned') as barangay, {$residentPhilhealthSelect} as philhealthNo, {$residentContactSelect} as contactNo, r.created_at as admissionDate,
                   COALESCE(NULLIF({$residentAllergiesSelect}, ''), NULLIF(TRIM(rhp.allergies), ''),
                       (SELECT NULLIF(TRIM(ha.allergies), '') FROM health_records ha
                        WHERE ha.resident_id = r.id AND NULLIF(TRIM(ha.allergies), '') IS NOT NULL
                        ORDER BY ha.id DESC LIMIT 1), '') AS allergies,
                   COALESCE(rhp.chronic_conditions, hp.medical_conditions) AS medicalHistory,
                   COALESCE(rhp.height, hp.height_cm) AS height,
                   COALESCE(rhp.weight, hp.weight_kg) AS weight,
                   COALESCE(rhp.blood_pressure, hp.blood_pressure) AS bloodPressure,
                   COALESCE(rhp.last_checkup_date, hp.last_checkup_date) AS lastCheckup,
                   COALESCE({$residentEmergencyNameSelect}, '') as emergencyContactName,
                   {$residentEmergencyNumberSelect} as emergencyContactNo,
                   COALESCE({$residentEmergencyRelationshipSelect}, '') as emergencyContactRelationship
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN resident_health_profiles rhp ON rhp.resident_id = r.id
            LEFT JOIN (
                SELECT h1.*
                FROM health_records h1
                JOIN (SELECT resident_id, MAX(id) id FROM health_records GROUP BY resident_id) latest ON latest.id = h1.id
            ) hp ON hp.resident_id = r.id
            WHERE " . rhuLivingResidentWhereSql('r') . "
            ORDER BY r.id DESC
        ");
        $patientRecords = $patStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Build associative map keyed by resident_id for JS auto-fill
        foreach ($patientRecords as $pr) {
            $patientProfilesMap[(int) $pr['id']] = [
                'id' => (int) $pr['id'],
                'first_name' => $pr['first_name'] ?? '',
                'middle_name' => $pr['middle_name'] ?? '',
                'last_name' => $pr['last_name'] ?? '',
                'name' => $pr['name'] ?? '',
                'date_of_birth' => $pr['date_of_birth'] ?? '',
                'age' => $pr['age'] ?? '',
                'gender' => $pr['gender'] ?? '',
                'civil_status' => $pr['civil_status'] ?? '',
                'email' => $pr['email'] ?? '',
                'address' => $pr['address'] ?? '',
                'barangay' => $pr['barangay'] ?? '',
                'bloodType' => $pr['bloodType'] ?? '',
                'philhealthNo' => $pr['philhealthNo'] ?? '',
                'contactNo' => $pr['contactNo'] ?? '',
                'height' => $pr['height'] ?? '',
                'weight' => $pr['weight'] ?? '',
                'bloodPressure' => $pr['bloodPressure'] ?? '',
                'allergies' => $pr['allergies'] ?? '',
                'medicalHistory' => $pr['medicalHistory'] ?? '',
                'lastCheckup' => $pr['lastCheckup'] ?? '',
                'emergencyContactName' => $pr['emergencyContactName'] ?? '',
                'emergencyContactNo' => $pr['emergencyContactNo'] ?? '',
                'emergencyContactRelationship' => $pr['emergencyContactRelationship'] ?? '',
            ];
        }

        if ($selectedResidentId > 0 && isset($patientProfilesMap[$selectedResidentId])) {
            $selectedResidentData = $patientProfilesMap[$selectedResidentId];
        }

        $residentRequests = [];
        $appendResidentRequest = static function (array $request) use (&$residentRequests, $patientProfilesMap): void {
            $residentId = (int)($request['resident_id'] ?? 0);
            $profile = $patientProfilesMap[$residentId] ?? [];
            $request['resident'] = $profile;
            $request['resident_name'] = $profile['name'] ?? ($request['resident_name'] ?? ('Resident #' . $residentId));
            $request['barangay'] = $profile['barangay'] ?? ($request['barangay'] ?? 'Unassigned');
            $request['requested_at'] = $request['requested_at'] ?? '';
            $residentRequests[] = $request;
        };

        if (dbHasTable($pdo, 'consultations')) {
            $requestConsultationsStmt = $pdo->prepare("
                SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS resident_name,
                       c.chief_complaint AS title, {$consultationNotesSelect} AS details,
                       {$consultationStatusSelect} AS status, c.consultation_date AS request_date,
                       COALESCE(c.created_at, c.consultation_date) AS requested_at
                FROM consultations c
                JOIN residents r ON r.id = c.resident_id
                {$assignmentWhere}
                  AND c.resident_id > 0
                  AND " . rhuLivingResidentWhereSql('r') . "
                  AND (
                    c.diagnosis = 'Pending OPD Triage'
                    OR c.chief_complaint LIKE '[%]%'
                    OR {$consultationNotesSelect} LIKE '%Requested via Resident Portal%'
                    OR {$consultationNotesSelect} LIKE '%Resident Message #%'
                  )
                ORDER BY requested_at DESC, c.id DESC
                LIMIT 200
            ");
            $requestConsultationsStmt->execute($assignmentParams);
            $requestConsultations = $requestConsultationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($requestConsultations as $row) {
                $appendResidentRequest([
                    'source' => 'consultation',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => 'OPD / Consultation Request',
                    'title' => $row['title'] ?: 'OPD consultation request',
                    'details' => $row['details'] ?: 'No additional notes provided.',
                    'status' => $row['status'] ?: 'Scheduled',
                    'request_date' => $row['request_date'] ?? '',
                    'requested_at' => $row['requested_at'] ?? '',
                    'action_url' => tabUrl('opd'),
                ]);
            }
        }

        if (dbHasTable($pdo, 'messages')) {
            $messageRequests = $pdo->query("
                SELECT m.id, m.resident_id, m.subject, m.message, m.status, m.created_at
                FROM messages m
                JOIN residents r ON r.id = m.resident_id
                WHERE m.resident_id > 0
                  AND " . rhuLivingResidentWhereSql('r') . "
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($messageRequests as $row) {
                $appendResidentRequest([
                    'source' => 'message',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => 'Resident Message',
                    'title' => $row['subject'] ?: 'Resident inquiry',
                    'details' => $row['message'] ?: 'No message body provided.',
                    'status' => $row['status'] ?: 'Pending',
                    'request_date' => !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : '',
                    'requested_at' => $row['created_at'] ?? '',
                    'action_url' => tabUrl('opd'),
                ]);
            }
        }

        if (dbHasTable($pdo, 'certificates')) {
            $certificateRequests = $pdo->query("
                SELECT c.id, c.resident_id, c.certificate_type, c.purpose, c.status, c.created_at, c.issue_date
                FROM certificates c
                JOIN residents r ON r.id = c.resident_id
                WHERE c.resident_id > 0
                  AND " . rhuLivingResidentWhereSql('r') . "
                  AND LOWER(COALESCE(c.status, '')) IN ('pending', 'draft', 'requested', 'pending approval', 'pending doctor approval', 'in review')
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($certificateRequests as $row) {
                $appendResidentRequest([
                    'source' => 'certificate',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => 'Certificate Request',
                    'title' => $row['certificate_type'] ?: 'Health certificate request',
                    'details' => $row['purpose'] ?: 'No purpose recorded.',
                    'status' => $row['status'] ?: 'Pending',
                    'request_date' => $row['issue_date'] ?? '',
                    'requested_at' => $row['created_at'] ?? '',
                    'action_url' => tabUrl('patients', ['resident_id' => (int)$row['resident_id']]),
                ]);
            }
        }

        if (dbHasTable($pdo, 'audit_logs')) {
            $auditRequests = $pdo->query("
                SELECT al.id, al.record_id AS resident_id, al.action, al.description, al.created_at
                FROM audit_logs al
                JOIN residents r ON r.id = al.record_id
                WHERE al.module_name = 'Resident Portal'
                  AND al.record_id > 0
                  AND " . rhuLivingResidentWhereSql('r') . "
                  AND al.action IN ('URGENT: Emergency Referral', 'Resident Message')
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($auditRequests as $row) {
                $appendResidentRequest([
                    'source' => 'audit',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => $row['action'] === 'URGENT: Emergency Referral' ? 'Emergency Referral Request' : 'Resident Message',
                    'title' => $row['action'] ?: 'Resident request',
                    'details' => $row['description'] ?: 'No details recorded.',
                    'status' => $row['action'] === 'URGENT: Emergency Referral' ? 'Urgent' : 'Pending',
                    'request_date' => !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : '',
                    'requested_at' => $row['created_at'] ?? '',
                    'action_url' => tabUrl('opd'),
                ]);
            }
        }

        $requestSeen = [];
        $residentRequests = array_values(array_filter($residentRequests, static function (array $request) use (&$requestSeen): bool {
            $key = ($request['source'] ?? '') . ':' . (int)($request['source_id'] ?? 0);
            if (isset($requestSeen[$key])) {
                return false;
            }
            $requestSeen[$key] = true;
            return true;
        }));
        usort($residentRequests, static function (array $left, array $right): int {
            return strcmp((string)($right['requested_at'] ?? ''), (string)($left['requested_at'] ?? ''));
        });

        // 3. Immunization Records
        $immStmt = $pdo->query("
            SELECT vr.id, vr.resident_id, CONCAT(r.first_name, ' ', r.last_name) as childName, TIMESTAMPDIFF(MONTH, r.birthdate, CURDATE()) as ageMonths,
                   COALESCE(b.name, 'Unassigned') as barangay, vr.vaccine_name as vaccineName, vr.age_group as targetAge,
                   vr.vaccination_date as dateGiven, vr.next_dose_date as nextVisit, vr.batch_number as lot,
                   EXISTS (
                       SELECT 1 FROM vaccination_records return_dose
                       WHERE return_dose.resident_id = vr.resident_id
                         AND LOWER(TRIM(return_dose.vaccine_name)) = LOWER(TRIM(vr.vaccine_name))
                         AND return_dose.vaccination_date >= vr.next_dose_date
                         AND return_dose.vaccination_date <= CURDATE()
                         AND (return_dose.vaccination_date > vr.vaccination_date
                              OR (return_dose.vaccination_date = vr.vaccination_date AND return_dose.id > vr.id))
                   ) AS nextDoseReceived
            FROM vaccination_records vr
            JOIN residents r ON vr.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE " . rhuLivingResidentWhereSql('r') . "
            ORDER BY vr.vaccination_date DESC, vr.id DESC
        ");
        $immunizationRecords = $immStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 4. Nutrition Cases
        $nutrStmt = $pdo->query("
            SELECT r.id, CONCAT(r.first_name, ' ', r.last_name) as name, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age,
                   r.sex as gender, COALESCE(b.name, 'Unassigned') as barangay,
                   COALESCE(CASE WHEN rhp.height > 0 THEN rhp.height END, hp.height_cm) as height,
                   COALESCE(CASE WHEN rhp.weight > 0 THEN rhp.weight END, hp.weight_kg) as weight
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN resident_health_profiles rhp ON rhp.resident_id = r.id
            LEFT JOIN (
                SELECT h1.*
                FROM health_records h1
                JOIN (SELECT resident_id, MAX(id) id FROM health_records
                      WHERE height_cm > 0 AND weight_kg > 0 GROUP BY resident_id) latest ON latest.id = h1.id
            ) hp ON hp.resident_id = r.id
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND COALESCE(CASE WHEN rhp.height > 0 THEN rhp.height END, hp.height_cm, 0) > 0
              AND COALESCE(CASE WHEN rhp.weight > 0 THEN rhp.weight END, hp.weight_kg, 0) > 0
            ORDER BY r.last_name ASC, r.first_name ASC
        ");
        $nutritionCases = $nutrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($nutritionCases as &$nutritionCase) {
            $heightCm = (float)($nutritionCase['height'] ?? 0);
            $weightKg = (float)($nutritionCase['weight'] ?? 0);
            $heightM = $heightCm / 100;
            $bmi = $heightM > 0 ? $weightKg / ($heightM * $heightM) : 0;
            $nutritionCase['bmi'] = $bmi > 0 ? number_format($bmi, 1) : '';
            if ($bmi > 0 && $bmi < 18.5) {
                $nutritionCase['bmi_status'] = 'Underweight';
                $nutritionCase['bmi_badge_class'] = 'bg-amber-100 text-amber-800 border-amber-200';
            } elseif ($bmi >= 25) {
                $nutritionCase['bmi_status'] = 'Overweight';
                $nutritionCase['bmi_badge_class'] = 'bg-rose-100 text-rose-800 border-rose-200';
            } else {
                $nutritionCase['bmi_status'] = 'Normal';
                $nutritionCase['bmi_badge_class'] = 'bg-emerald-100 text-emerald-800 border-emerald-200';
            }
        }
        unset($nutritionCase);

        // 5. TB-DOTS Cases inferred from disease surveillance records in the live schema.
        $tbStmt = $pdo->query("
            SELECT dc.id, dc.resident_id, CONCAT(r.first_name, ' ', r.last_name) as name, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age,
                   r.sex as gender, CONCAT('TB-', dc.id) as tb_registration_number, dt.name as classification,
                   dc.case_status as treatment_status, dc.case_date as treatment_start_date, COALESCE(b.name, 'Unassigned') as barangay,
                   CONCAT(dt.name, ' ', COALESCE(dt.icd_code, ''), ' ', COALESCE(dc.symptoms, ''), ' ', COALESCE(dc.remarks, '')) AS tb_match_text,
                   'Surveillance' AS record_source
            FROM disease_cases dc
            JOIN disease_types dt ON dt.id = dc.disease_type_id
            JOIN residents r ON r.id = dc.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND LOWER(CONCAT(dt.name, ' ', COALESCE(dt.icd_code, ''), ' ', COALESCE(dc.symptoms, ''), ' ', COALESCE(dc.remarks, ''))) REGEXP 'tb|tuberculosis|koch'
            ORDER BY dc.id DESC
        ");
        $tbCases = $tbStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tbPattern = '/\b(tb|tuberculosis|tuberculous|tb-dots|dots|koch)\b|\bA1[5-9](?:\.[0-9]+)?\b|\bZ22\.?7\b|\bB90(?:\.[0-9]+)?\b/i';
        $tbCases = array_values(array_filter($tbCases, static function ($record) use ($tbPattern) {
            return preg_match($tbPattern, $record['tb_match_text'] ?? '') === 1;
        }));
        $tbOpdRows = $pdo->query("SELECT c.id, CONCAT(r.first_name, ' ', r.last_name) AS name,
            TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age, r.sex AS gender,
            CONCAT('OPD-', c.id) AS tb_registration_number,
            COALESCE(NULLIF(c.diagnosis, ''), c.chief_complaint) AS classification,
            COALESCE(c.consultation_status, 'Recorded') AS treatment_status,
            c.consultation_date AS treatment_start_date, COALESCE(b.name, 'Unassigned') AS barangay,
            CONCAT(COALESCE(c.diagnosis, ''), ' ', COALESCE(c.chief_complaint, ''), ' ',
                COALESCE(c.remarks, ''), ' ', COALESCE({$consultationNotesSelect}, '')) AS tb_match_text,
            'OPD' AS record_source
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE " . rhuLivingResidentWhereSql('r') . "
            ORDER BY c.consultation_date DESC, c.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($tbOpdRows as $tbOpdRow) {
            if (preg_match($tbPattern, $tbOpdRow['tb_match_text'] ?? '') === 1) {
                $tbCases[] = $tbOpdRow;
            }
        }
        usort($tbCases, static function ($a, $b) {
            return strcmp((string) $b['treatment_start_date'], (string) $a['treatment_start_date']);
        });

        // 6. Disease Surveillance summaries for PIDSR tab
        $surveillanceRows = $pdo->query("SELECT dc.resident_id, dc.case_date AS record_date,
            dt.name AS disease_name, dt.icd_code, dc.case_status AS status,
            COALESCE(b.name, 'Unassigned') AS barangay
            FROM disease_cases dc
            LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
            LEFT JOIN residents r ON r.id = dc.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $opdDiseaseRows = $pdo->query("SELECT c.resident_id, c.consultation_date AS record_date,
            c.diagnosis AS disease_name, '' AS icd_code, 'Recorded' AS status,
            COALESCE(b.name, 'Unassigned') AS barangay,
            CONCAT(COALESCE(c.remarks, ''), ' ', COALESCE({$consultationNotesSelect}, '')) AS notes
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE NULLIF(TRIM(c.diagnosis), '') IS NOT NULL
              AND {$consultationStatusSelect} NOT IN ('Cancelled', 'No-show')
              AND " . rhuLivingResidentWhereSql('r'))->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $diseaseGroups = [];
        $seenDiseaseCases = [];
        $diseaseCatalog = [];
        $diseaseCatalogByCode = [];
        $diseaseNameCodes = [];
        $normalizeDiseaseName = static function ($name) {
            $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', (string) $name)));
            $aliases = ['essential hypertension' => 'hypertension', 'primary hypertension' => 'hypertension',
                'essential primary hypertension' => 'hypertension'];
            return $aliases[$normalized] ?? $normalized;
        };
        $catalogRows = $pdo->query('SELECT name, icd_code FROM disease_types')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($catalogRows as $catalogRow) {
            $diseaseCatalog[$normalizeDiseaseName($catalogRow['name'])] = $catalogRow;
            $catalogCode = strtoupper(trim((string) ($catalogRow['icd_code'] ?? '')));
            if ($catalogCode !== '') {
                $diseaseCatalogByCode[$catalogCode] = $catalogRow;
                $diseaseNameCodes[$normalizeDiseaseName($catalogRow['name'])] = $catalogCode;
            }
        }
        $allDiseaseRows = array_merge($surveillanceRows, $opdDiseaseRows);
        $diseaseFamilies = [
            ['pattern' => '/^A9[01](?:\.|$)/', 'name' => 'Dengue'],
            ['pattern' => '/^A1[5-9](?:\.|$)/', 'name' => 'Tuberculosis (TB)'],
            ['pattern' => '/^A01(?:\.0|$)/', 'name' => 'Typhoid fever'],
            ['pattern' => '/^J1[2-8](?:\.|$)/', 'name' => 'Pneumonia'],
            ['pattern' => '/^I1[0-5](?:\.|$)/', 'name' => 'High blood pressure'],
            ['pattern' => '/^J4[56](?:\.|$)/', 'name' => 'Asthma'],
            ['pattern' => '/^E1[0-4](?:\.|$)/', 'name' => 'Diabetes'],
            ['pattern' => '/^K58(?:\.|$)/', 'name' => 'Irritable bowel syndrome (IBS)'],
            ['pattern' => '/^A27(?:\.|$)/', 'name' => 'Leptospirosis'],
            ['pattern' => '/^U07\.[12]$/', 'name' => 'COVID-19'],
            ['pattern' => '/^A09(?:\.|$)/', 'name' => 'Diarrhea'],
        ];
        foreach ($allDiseaseRows as $row) {
            $rowName = trim((string) ($row['disease_name'] ?? ''));
            $rowCode = strtoupper(trim((string) ($row['icd_code'] ?? '')));
            if (preg_match('/\s*\(([A-Z][0-9]{2}(?:\.[A-Z0-9]+)?)\)\s*$/i', $rowName, $match)) {
                $rowCode = $rowCode ?: strtoupper($match[1]);
                $rowName = trim(substr($rowName, 0, -strlen($match[0])));
            }
            if ($rowCode === '' && preg_match('/ICD-10:\s*([A-Z][0-9]{2}(?:\.[A-Z0-9]+)?)/i', $row['notes'] ?? '', $match)) {
                $rowCode = strtoupper($match[1]);
            }
            if ($rowCode !== '') {
                $key = $normalizeDiseaseName($rowName);
                if (!array_key_exists($key, $diseaseNameCodes)) {
                    $diseaseNameCodes[$key] = $rowCode;
                } elseif ($diseaseNameCodes[$key] !== $rowCode) {
                    $diseaseNameCodes[$key] = null;
                }
            }
        }
        foreach ($allDiseaseRows as $diseaseRow) {
            $name = trim((string) ($diseaseRow['disease_name'] ?? '')) ?: 'Unspecified disease';
            $code = strtoupper(trim((string) ($diseaseRow['icd_code'] ?? '')));
            if ($code === '' && preg_match('/ICD-10:\s*([A-Z][0-9]{2}(?:\.[A-Z0-9]+)?)/i', $diseaseRow['notes'] ?? '', $codeMatch)) {
                $code = strtoupper($codeMatch[1]);
            }
            if (preg_match('/\s*\(([A-Z][0-9]{2}(?:\.[A-Z0-9]+)?)\)\s*$/i', $name, $nameCode)) {
                $code = $code ?: strtoupper($nameCode[1]);
                $name = trim(substr($name, 0, -strlen($nameCode[0])));
            }
            $normalizedName = $normalizeDiseaseName($name);
            if ($normalizedName === '' || preg_match('/^(unspecified disease|unknown|undiagnosed|no diagnosis|none|n a|normal|healthy|for assessment)$/i', $normalizedName)
                || preg_match('/\b(manganganak|manganganak na|panganganak|normal delivery|normal labor|normal labour|routine checkup|routine check up|prenatal visit|antenatal visit|vaccination|immunization|immunisation)\b/i', $name)) {
                continue;
            }
            $catalogDisease = $diseaseCatalog[$normalizedName] ?? null;
            if ($code === '' && $catalogDisease) {
                $code = strtoupper(trim((string) ($catalogDisease['icd_code'] ?? '')));
            }
            $code = $code ?: ($diseaseNameCodes[$normalizedName] ?? '');
            $catalogDisease = $diseaseCatalogByCode[$code] ?? $catalogDisease;
            // Symptoms, encounter codes, and uncomplicated childbirth are not disease classifications.
            if ($code !== '' && (!preg_match('/^[A-Z][0-9]{2}(?:\.[A-Z0-9]+)?$/', $code)
                || preg_match('/^[RV-Z]|^O8[0-4](?:\.|$)/', $code))) {
                continue;
            }
            if ($code === '' && !$catalogDisease) {
                continue;
            }
            if ($catalogDisease) {
                $name = trim((string) $catalogDisease['name']);
            }
            $groupKey = $code !== '' ? 'code:' . $code : 'name:' . $normalizeDiseaseName($name);
            $caseDiseaseKey = $groupKey;
            if (in_array($code, ['A90', 'A91'], true)
                || in_array($normalizeDiseaseName($name), ['dengue', 'dengue fever', 'dengue hemorrhagic fever', 'dengue with bleeding'], true)) {
                $groupKey = 'family:dengue';
                $name = 'Dengue';
            }
            foreach ($diseaseFamilies as $family) {
                if (preg_match($family['pattern'], $code)) {
                    $groupKey = 'family:' . $normalizeDiseaseName($family['name']);
                    $name = $family['name'];
                    break;
                }
            }
            $date = substr((string) ($diseaseRow['record_date'] ?? ''), 0, 10);
            $caseKey = ($diseaseRow['resident_id'] ?? '') . '|' . $date . '|' . $caseDiseaseKey;
            if (isset($seenDiseaseCases[$caseKey])) {
                continue;
            }
            $seenDiseaseCases[$caseKey] = true;
            if (!isset($diseaseGroups[$groupKey])) {
                $diseaseGroups[$groupKey] = ['disease_name' => $name, 'icd_code' => str_starts_with($groupKey, 'family:') ? '' : $code,
                    'case_count' => 0, 'confirmed_count' => 0, 'suspected_count' => 0,
                    'latest_case_date' => '', 'barangays' => [], 'statuses' => []];
            }
            $group = &$diseaseGroups[$groupKey];
            $status = trim((string) ($diseaseRow['status'] ?? '')) ?: 'Recorded';
            $group['case_count']++;
            $group['confirmed_count'] += strtolower($status) === 'confirmed' ? 1 : 0;
            $group['suspected_count'] += in_array(strtolower($status), ['suspected', 'probable'], true) ? 1 : 0;
            $group['latest_case_date'] = max($group['latest_case_date'], $date);
            $group['barangays'][$diseaseRow['barangay']] = true;
            $group['statuses'][$status] = true;
            unset($group);
        }
        $diseaseReports = array_values($diseaseGroups);
        foreach ($diseaseReports as &$report) {
            $report['barangays'] = implode(', ', array_keys($report['barangays']));
            $report['statuses'] = implode(', ', array_keys($report['statuses']));
        }
        unset($report);
        usort($diseaseReports, static function ($a, $b) {
            return strcmp($b['latest_case_date'], $a['latest_case_date'])
                ?: ($b['case_count'] <=> $a['case_count'])
                ?: strcasecmp($a['disease_name'], $b['disease_name']);
        });


    } catch (Exception $e) {
        error_log("NurseDashboard DB Load Error: " . $e->getMessage());
        $flashError = 'Unable to load nurse records. Please check the database connection and server error log.';
    }
}

$todayOPDCount = count($opdConsultations);
$diagnosedResidentIds = [];
$immunizationDiagnosisCount = count($immunizationRecords);
$immunizationDiagnosisMentions = 0;
$tbDiagnosisResidentIds = [];
foreach ($opdConsultations as $consultation) {
    $diagnosisText = strtolower(trim(
        ($consultation['diagnosis'] ?? '') . ' ' .
        ($consultation['chiefComplaint'] ?? '') . ' ' .
        ($consultation['consultation_notes'] ?? '') . ' ' .
        ($consultation['icd10'] ?? '')
    ));
    $residentKey = (int) ($consultation['resident_id'] ?? 0);

    if ($residentKey > 0 && trim((string) ($consultation['diagnosis'] ?? '')) !== '') {
        $diagnosedResidentIds[$residentKey] = true;
    }
    if (preg_match('/\b(immuni[sz]ation|vaccine|vaccination|epi)\b/i', $diagnosisText)) {
        $immunizationDiagnosisMentions++;
    }
    if ($residentKey > 0 && preg_match('/\b(tb|tuberculosis|tuberculous|latent\s+tb|latent\s+tuberculosis|tb-dots|dots|koch)\b|a1[5-9]\b|z22\.?7\b|b90\b/i', $diagnosisText)) {
        $tbDiagnosisResidentIds[$residentKey] = true;
    }
}
$diagnosedPatientCount = count($diagnosedResidentIds);
$immunizationDiagnosisCount = max($immunizationDiagnosisCount, $immunizationDiagnosisMentions);
$activeTBCount = max(count($tbCases), count($tbDiagnosisResidentIds));
$immunizationAdministeredCount = count($immunizationRecords);
$immunizationDueThisMonthCount = 0;
$immunizationOverdueCount = 0;
$todayDate = new DateTimeImmutable('today');
$monthStartDate = $todayDate->modify('first day of this month');
$monthEndDate = $todayDate->modify('last day of this month');
$latestImmunizationRecords = [];
foreach ($immunizationRecords as $immunizationRecord) {
    $residentKey = (int) $immunizationRecord['resident_id'];
    if (!isset($latestImmunizationRecords[$residentKey])) {
        $nextVisit = substr(trim((string) ($immunizationRecord['nextVisit'] ?? '')), 0, 10);
        $immunizationRecord['isOverdue'] = empty($immunizationRecord['nextDoseReceived'])
            && $nextVisit !== '' && $nextVisit !== '0000-00-00'
            && $nextVisit < $todayDate->format('Y-m-d');
        $latestImmunizationRecords[$residentKey] = $immunizationRecord;
    }
}
foreach ($immunizationRecords as $immunizationRecord) {
    if (!empty($immunizationRecord['nextDoseReceived'])) {
        continue;
    }
    $nextVisitRaw = trim((string)($immunizationRecord['nextVisit'] ?? ''));
    if ($nextVisitRaw === '' || $nextVisitRaw === '0000-00-00') {
        continue;
    }
    try {
        $nextVisitDate = new DateTimeImmutable(substr($nextVisitRaw, 0, 10));
    } catch (Throwable $invalidNextVisitDate) {
        continue;
    }
    if ($nextVisitDate < $todayDate) {
        $immunizationOverdueCount++;
        continue;
    }
    if ($nextVisitDate >= $monthStartDate && $nextVisitDate <= $monthEndDate) {
        $immunizationDueThisMonthCount++;
    }
}
if (!empty($pdo)) {
    try {
        $diagnosedCountStmt = $pdo->query("
            SELECT COUNT(DISTINCT c.resident_id)
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            WHERE c.resident_id > 0
              AND " . rhuLivingResidentWhereSql('r') . "
              AND NULLIF(TRIM(COALESCE(c.diagnosis, '')), '') IS NOT NULL
              AND LOWER(TRIM(COALESCE(c.diagnosis, ''))) NOT IN ('pending opd triage', 'pending', 'scheduled')
        ");
        $diagnosedPatientCount = max($diagnosedPatientCount, (int)($diagnosedCountStmt->fetchColumn() ?: 0));
    } catch (Throwable $diagnosedCountError) {
        error_log('NurseDashboard diagnosed patient count: ' . $diagnosedCountError->getMessage());
    }
}
$opdResidents = [];
foreach ($opdConsultations as $consultation) {
    $consultationText = ($consultation['chiefComplaint'] ?? '') . "\n"
        . ($consultation['consultation_notes'] ?? '') . "\n" . ($consultation['diagnosis'] ?? '');
    // Vaccination visits are processed through the immunization workflow.
    if (preg_match('/\b(?:vaccin\w*|immuni[sz]\w*|epi)\b/i', $consultationText)) continue;
    $residentKey = (int) ($consultation['resident_id'] ?? 0);
    if ($residentKey <= 0) {
        $residentKey = (int) ($consultation['id'] ?? 0);
    }
    if (!isset($opdResidents[$residentKey])) {
        $opdResidents[$residentKey] = [
            'resident_id' => $residentKey,
            'patientName' => $consultation['patientName'] ?? '',
            'age' => $consultation['age'] ?? '',
            'gender' => $consultation['gender'] ?? '',
            'barangay' => $consultation['barangay'] ?? '',
            'records' => [],
        ];
    }
    $opdResidents[$residentKey]['records'][] = $consultation;
}
$opdResidents = array_values($opdResidents);
$qrRequestedAppointmentId = max(0, (int)($_GET['qr_appointment'] ?? 0));
$pendingImmunizationAppointments = nursePendingImmunizationAppointments($opdConsultations);
$immunizationAppointments = [];
$immunizationPrefillAppointments = $pendingImmunizationAppointments;
usort($immunizationPrefillAppointments, static fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
foreach ($immunizationPrefillAppointments as $appointment) {
    $residentId = (int) ($appointment['resident_id'] ?? 0);
    $appointmentText = ($appointment['chiefComplaint'] ?? '') . "\n" . ($appointment['consultation_notes'] ?? '');
    if ($residentId <= 0 || (isset($immunizationAppointments[$residentId]) && (int)$appointment['id'] !== $qrRequestedAppointmentId)
        || in_array(strtolower((string) ($appointment['consultation_status'] ?? '')), ['completed', 'cancelled', 'no-show'], true)
        || !preg_match('/vaccination|immunization|vaccine/i', $appointmentText)) continue;
    $immunizationAppointments[$residentId] = [
        'id' => (int) $appointment['id'],
        'date' => substr((string) (($appointment['follow_up_date'] ?? '') ?: ($appointment['date'] ?? '')), 0, 10),
        'status' => $appointment['consultation_status'] ?? 'Pending',
        'complaint' => $appointment['chiefComplaint'] ?? '',
        'vaccine_id' => '',
        'batch_number' => '',
        'site_of_injection' => '',
        'next_dose_date' => '',
    ];
    $matchingVaccines = [];
    foreach ($vaccineSchedules as $vaccine) {
        if (preg_match('/\b' . preg_quote($vaccine['vaccine_name'], '/') . '\b/i', $appointmentText)) {
            $matchingVaccines[] = (string) $vaccine['id'];
        }
    }
    if (count($matchingVaccines) === 1) $immunizationAppointments[$residentId]['vaccine_id'] = $matchingVaccines[0];
    foreach (['batch_number' => 'Batch(?: / Lot Number)?|Lot Number', 'site_of_injection' => 'Injection Site', 'next_dose_date' => 'Next Dose(?: Date)?'] as $field => $label) {
        if (preg_match('~(?:^|[\n;|])\s*(?:' . $label . ')\s*:\s*([^\n;|]+)~i', $appointmentText, $match)) {
            $immunizationAppointments[$residentId][$field] = trim($match[1]);
        }
    }
}
// Never substitute a different visit when an explicit scanned booking is unavailable.
if ($qrRequestedAppointmentId > 0 && isset($immunizationAppointments[$selectedResidentId])
    && (int)$immunizationAppointments[$selectedResidentId]['id'] !== $qrRequestedAppointmentId) {
    unset($immunizationAppointments[$selectedResidentId]);
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
    <title>Public Health Nurse Portal — ResiHUnity RHU</title>
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <style>
        html {
            scroll-behavior: auto;
        }

        body.rhu-nurse-ui {
            overflow: hidden;
            background: #f3f6f4;
            color: #0f172a;
            height: 100vh;
            height: 100dvh;
        }

        .nurse-sidebar {
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

        .nurse-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.25rem;
            padding: 0.9rem 1rem;
            flex-shrink: 0;
        }

        .nurse-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(0.52);
        }

        .nurse-sidebar-brand .brand-overlay {
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

        .nurse-main-wrap {
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

        .nurse-main-wrap::before {
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

        .nurse-main-wrap>* {
            position: relative;
            z-index: 1;
        }

        .nurse-main-wrap>header.admin-shell-header {
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0;
        }

        .nurse-main-wrap>main {
            z-index: 1;
            position: relative;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
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

        .triage-queue-card {
            border-color: rgba(209, 213, 219, .9);
            box-shadow: 0 8px 18px rgba(15, 23, 42, .04);
        }

        .triage-queue-card:hover {
            border-color: rgba(16, 185, 129, .36);
            box-shadow: 0 10px 22px rgba(15, 23, 42, .055);
        }

        .triage-chip {
            display: inline-flex;
            align-items: center;
            min-height: 1.625rem;
            border-radius: 999px;
            padding: .25rem .625rem;
            font-size: .72rem;
            font-weight: 800;
            line-height: 1;
            white-space: nowrap;
        }

        .triage-vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
            gap: .5rem;
        }

        .triage-vital {
            border: 1px solid rgba(209, 213, 219, .85);
            border-radius: .75rem;
            background: #fff;
            padding: .625rem .75rem;
        }

        .triage-vital span {
            display: block;
            color: #64748b;
            font-size: .62rem;
            font-weight: 900;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .triage-vital strong {
            display: block;
            margin-top: .125rem;
            color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .78rem;
        }

        .triage-response summary::-webkit-details-marker {
            display: none;
        }

        .triage-response summary::after {
            content: '+';
            display: grid;
            place-items: center;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            font-weight: 900;
        }

        .triage-response[open] summary::after {
            content: '-';
        }

        .triage-note summary::-webkit-details-marker {
            display: none;
        }

        .triage-note summary {
            list-style: none;
        }

        .triage-workbench {
            --triage-workbench-height: min(36rem, calc(100dvh - 18rem));
            display: grid;
            grid-template-columns: minmax(240px, 340px) minmax(0, 1fr);
            gap: 1.25rem;
            align-items: stretch;
        }

        .triage-suggest-pane {
            min-width: 0;
            overflow: hidden;
            position: relative;
            z-index: 2;
            height: var(--triage-workbench-height);
            display: flex;
            flex-direction: column;
        }

        .triage-view-pane {
            min-width: 0;
            position: relative;
            z-index: 1;
            height: var(--triage-workbench-height);
        }

        .triage-search {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: .875rem;
            background: #fff;
            padding: .625rem .75rem;
            font-size: .82rem;
            font-weight: 700;
            color: #0f172a;
        }

        .triage-patient-list {
            display: grid;
            align-content: start;
            flex: 1;
            gap: .5rem;
            min-height: 0;
            overflow-y: auto;
            padding-right: .2rem;
            scrollbar-width: thin;
            scrollbar-color: #94a3b8 transparent;
        }

        .triage-patient-list::-webkit-scrollbar {
            width: 8px;
        }

        .triage-patient-list::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 999px;
        }

        .triage-patient-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .triage-patient-tab {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            border: 1px solid #e2e8f0;
            border-radius: .875rem;
            background: #fff;
            padding: .75rem;
            text-align: left;
            transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease;
        }

        .triage-patient-tab:hover,
        .triage-patient-tab.is-active {
            border-color: #34d399;
            background: #ecfdf5;
            box-shadow: inset 3px 0 0 #10b981;
        }

        .triage-detail-panel {
            display: none;
        }

        .triage-detail-panel.is-active {
            display: block;
            height: 100%;
        }

        .triage-detail-panel.is-active > .triage-queue-card {
            height: 100%;
            overflow-y: auto;
        }

        .triage-empty-filter {
            display: none;
        }

        .triage-empty-filter.is-visible {
            display: block;
        }

        .icd-lookup-wrap {
            position: relative;
        }

        .icd-suggestions {
            display: none;
            position: relative;
            z-index: 5;
            margin-top: .4rem;
            max-height: 15rem;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
            border-radius: .875rem;
            background: #fff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
        }

        .icd-suggestions.is-open {
            display: block;
        }

        .icd-suggestion {
            width: 100%;
            padding: .65rem .75rem;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: .78rem;
            color: #334155;
        }

        .icd-suggestion:last-child {
            border-bottom: 0;
        }

        .icd-suggestion:hover,
        .icd-suggestion:focus {
            background: #ecfdf5;
            outline: none;
        }

        .icd-suggestion strong {
            display: block;
            color: #047857;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .75rem;
        }

        @media (max-width: 900px) {
            .triage-workbench {
                --triage-workbench-height: auto;
                grid-template-columns: 1fr;
            }

            .triage-suggest-pane,
            .triage-view-pane {
                height: auto;
            }

            .triage-patient-list {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                max-height: 24rem;
            }
        }

        a.bg-teal-600:hover,
        button.bg-teal-600:hover,
        a.bg-emerald-700:hover,
        button.bg-emerald-700:hover {
            filter: brightness(1.05);
        }

        a.bg-teal-600,
        button.bg-teal-600,
        button.bg-emerald-700,
        a.bg-emerald-700 {
            transition: background-color .15s ease, filter .15s ease, box-shadow .15s ease;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14) !important;
        }

        #nurse-vaccine-modal { z-index: 100000; }
        #nurse-vaccine-form { min-height: 0; }
        #nurse-vaccine-form input:not([type="hidden"]),
        #nurse-vaccine-form select {
            min-width: 0;
            width: 100%;
            min-height: 44px;
            border-radius: 8px;
            border-color: #cbd5e1;
            font-size: 14px;
            line-height: 20px;
        }
        #nurse-vaccine-form select { appearance: auto; }
        #nurse-vaccine-form input:focus,
        #nurse-vaccine-form select:focus {
            outline: 2px solid #059669;
            outline-offset: 2px;
        }
        #nurse-vaccine-form input::placeholder { color: #64748b !important; }
        #nurse-vaccine-form a, #nurse-vaccine-form button { border-radius: 8px; }

        .resident-record-form input,
        .resident-record-form select,
        .resident-record-form textarea {
            background: #ffffff !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            color-scheme: light;
            -webkit-appearance: none;
            appearance: none;
        }

        .resident-record-form input::placeholder,
        .resident-record-form textarea::placeholder {
            color: #9ca3af !important;
            opacity: 1;
        }

        .resident-record-form option {
            background-color: #ffffff;
            color: #000000;
        }

        .resident-record-form .resident-patient-select {
            display: block;
            min-height: 2.875rem;
            line-height: 1.25rem;
            padding-right: 2.5rem;
            background-image: linear-gradient(45deg, transparent 50%, #64748b 50%), linear-gradient(135deg, #64748b 50%, transparent 50%);
            background-position: calc(100% - 1.05rem) 50%, calc(100% - .75rem) 50%;
            background-size: .35rem .35rem, .35rem .35rem;
            background-repeat: no-repeat;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }

        .resident-combobox {
            position: relative;
        }

        .resident-combobox-button {
            min-height: 2.875rem;
            display: flex;
            width: 100%;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            border: 1px solid #d1d5db;
            border-radius: .75rem;
            background: #fff;
            padding: .625rem .875rem;
            color: #020617;
            font-size: .875rem;
            font-weight: 700;
            line-height: 1.25rem;
            text-align: left;
        }

        .resident-combobox-button:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, .18);
            outline: none;
        }

        .resident-combobox-button svg {
            flex: 0 0 auto;
            color: #64748b;
        }

        .resident-combobox-label {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .resident-combobox-panel {
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

        .resident-combobox.is-open .resident-combobox-panel {
            display: block;
        }

        .resident-combobox-option {
            display: block;
            width: 100%;
            padding: .7rem .85rem;
            border-bottom: 1px solid #f1f5f9;
            color: #020617;
            font-size: .875rem;
            font-weight: 650;
            text-align: left;
        }

        .resident-combobox-option:last-child {
            border-bottom: 0;
        }

        .resident-combobox-option:hover,
        .resident-combobox-option:focus,
        .resident-combobox-option.is-selected {
            background: #ecfdf5;
            outline: none;
        }

        @media (max-width: 1023px) {
            .nurse-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                height: 100vh;
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 40px rgba(15, 23, 42, .18);
            }

            .nurse-sidebar.is-open {
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

            .nurse-sidebar {
                transform: none !important;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-card:hover {
                transform: none;
            }
        }
    </style>
    <link rel="stylesheet" href="styles/dashboard-enhancements.css">
<script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(__DIR__ . "/staff-session-lock.js") ?>"></script>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
</head>

<body class="rhu-nurse-ui antialiased">

    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>

        <aside id="nurse-sidebar" data-feature-drawer class="nurse-sidebar shrink-0" aria-label="Nurse navigation">
            <div class="nurse-sidebar-brand">
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
                    'Clinical Care' => ['opd', 'patients', 'immunization'],
                    'Programs' => ['nutrition', 'tb', 'disease'],
                    'Records' => ['certificates'],
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

        <div class="nurse-main-wrap">
            <header class="admin-shell-header dashboard-header sticky top-0 z-50 text-[#f4faf7]">
                <div class="flex h-20 items-center justify-between gap-3 px-5 sm:px-7">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <button type="button" data-drawer-open
                            class="lg:hidden flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/15 text-white/95 hover:bg-white/10"
                            aria-label="Open menu" aria-expanded="false">
                            <?= iconSvg('menu', 'w-4 h-4') ?>
                        </button>
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full border border-[#e8f3d8]/80 bg-[#dfeecb] text-[#0b3b2f]">
                                <?= iconSvg('shield', 'w-3.5 h-3.5') ?>
                            </span>
                            <span class="text-[11px] font-black uppercase tracking-[0.16em] text-[#f5f5f2]">Nurse
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
                                <?= esc(strtoupper(substr($_SESSION['rhu_staff_login']['name'] ?? 'N', 0, 1))) ?>
                            </div>
                            <div class="hidden sm:block text-left leading-tight pr-1">
                                <p class="text-[12px] font-bold text-white">
                                    <?= esc($_SESSION['rhu_staff_login']['name'] ?? 'Public Health Nurse') ?></p>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-[#cfe5d8]">Nurse</p>
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
                        class="bg-emerald-50 border border-emerald-200 text-emerald-900 px-4 py-3 rounded-2xl text-sm font-bold shadow-sm flex items-center justify-between">
                        <span class="flex items-center gap-2"><?= esc($flashSuccess); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div
                        class="bg-red-50 border border-red-200 text-red-900 px-4 py-3 rounded-2xl text-sm font-bold shadow-sm flex items-center justify-between">
                        <span class="flex items-center gap-2"><?= esc($flashError); ?></span>
                    </div>
                <?php endif; ?>

                <!-- TAB 1: OVERVIEW -->
                <?php if ($tab === 'overview'): ?>
                    <div class="space-y-5">
                        <!-- SURVEILLANCE BANNER -->
                        <div
                            class="bg-linear-to-r from-red-50 via-rose-50 to-orange-50 border border-red-200/80 rounded-2xl p-4 sm:p-5 flex items-start gap-3 shadow-sm">
                            <span class="w-9 h-9 rounded-xl bg-red-100 text-red-600 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="font-extrabold text-red-900 text-sm sm:text-base">DOH Active Disease
                                        Surveillance Alert</p>
                                    <span class="bg-red-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">PIDSR
                                        Active</span>
                                </div>
                                <p class="text-xs sm:text-sm text-red-700 mt-0.5">Continuous community monitoring ongoing
                                    for Dengue, Typhoid, and Leptospirosis across Nasugbu barangays.</p>
                            </div>
                            <a href="<?= esc(tabUrl('disease')); ?>"
                                class="text-xs bg-red-600 hover:bg-red-700 text-white font-bold px-3.5 py-2 rounded-xl whitespace-nowrap shadow-md transition">View
                                Surveillance</a>
                        </div>

                        <!-- METRIC CARDS GRID -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <a href="<?= esc(tabUrl('opd')); ?>"
                                class="dashboard-card group bg-linear-to-br from-emerald-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-emerald-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">Active</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-emerald-900 mt-3  transition-transform">
                                    <?= $todayOPDCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Received OPD Consults</p>
                                <p class="text-[11px] text-gray-400 font-medium">Assigned Nursing Triage</p>
                            </a>

                            <a href="<?= esc(tabUrl('patients')); ?>"
                                class="dashboard-card group bg-linear-to-br from-blue-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-blue-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200">Registry</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-blue-900 mt-3  transition-transform">
                                    <?= $diagnosedPatientCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Diagnosed Patients</p>
                                <p class="text-[11px] text-gray-400 font-medium">Residents with Diagnosis</p>
                            </a>

                            <a href="<?= esc(tabUrl('immunization')); ?>"
                                class="dashboard-card group bg-linear-to-br from-purple-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-purple-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m18 2 4 4"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-purple-700 bg-purple-50 px-2 py-0.5 rounded-full border border-purple-200">EPI
                                        Program</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-purple-900 mt-3  transition-transform">
                                    <?= $immunizationDiagnosisCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Child Immunizations</p>
                                <p class="text-[11px] text-gray-400 font-medium">Vaccination Records</p>
                            </a>

                            <a href="<?= esc(tabUrl('tb')); ?>"
                                class="dashboard-card group bg-linear-to-br from-amber-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-amber-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 3h6v4a3 3 0 0 1-6 0V3z"/><path d="M9 7v13"/><path d="M15 7v13"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">DOTS</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-amber-900 mt-3  transition-transform">
                                    <?= $activeTBCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Active TB Cases</p>
                                <p class="text-[11px] text-gray-400 font-medium">Treatment Monitoring</p>
                            </a>
                        </div>

                        <!-- RECEIVED OPD CONSULTATION & TRIAGE LOG SUMMARY -->
                        <div
                            class="dashboard-card bg-white rounded-2xl p-4 sm:p-5 shadow-sm border border-slate-200/80 space-y-4">
                            <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <h3 class="font-extrabold text-gray-950 text-base flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                                        Received Resident Consultation & Triage Queue
                                    </h3>
                                    <p class="mt-1 text-xs text-slate-500">All resident consultation records submitted for
                                        Nursing Triage</p>
                                </div>
                            </div>

                            <?php if (empty($opdConsultations)): ?>
                                <div class="text-center py-8 bg-slate-50/50 rounded-2xl border border-dashed border-gray-200">
                                    <span class="text-3xl block mb-2"></span>
                                    <p class="text-sm font-bold text-gray-700">No OPD Consultations Found</p>
                                    <p class="text-xs text-gray-400 mt-0.5">When residents book appointments for nursing care,
                                        they will appear here automatically.</p>
                                </div>
                            <?php else: ?>
                                <?php $queuePreview = $opdConsultations; ?>
                                <div class="triage-workbench" data-triage-workbench>
                                    <aside class="triage-suggest-pane rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                                        <div class="mb-3">
                                            <label for="triageQueueSearch" class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">Search Resident</label>
                                            <input id="triageQueueSearch" data-triage-search class="triage-search" type="search" placeholder="Type a name, barangay, status..." autocomplete="off">
                                        </div>
                                        <div class="triage-patient-list" data-triage-list>
                                            <?php foreach ($queuePreview as $index => $c): ?>
                                                <?php
                                                    $tabSearch = strtolower(trim(($c['resident_id'] ?? '') . ' ' . ($c['patientName'] ?? '') . ' ' . ($c['barangay'] ?? '') . ' ' . ($c['chiefComplaint'] ?? '') . ' ' . ($c['consultation_status'] ?? '') . ' ' . ($c['icd10'] ?? '')));
                                                ?>
                                                <button type="button" class="triage-patient-tab <?= $index === 0 ? 'is-active' : ''; ?>" data-triage-tab data-target="triage-panel-<?= (int) $c['id']; ?>" data-search="<?= esc($tabSearch); ?>">
                                                    <span class="block truncate text-sm font-extrabold text-slate-950"><?= esc(rhuPatientLabel($c['patientName'], $c['resident_id'] ?? 0)); ?></span>
                                                    <span class="mt-1 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-[11px] font-bold text-slate-500">
                                                        <span class="min-w-0 truncate"><?= esc($c['chiefComplaint']); ?></span>
                                                        <span class="max-w-[92px] shrink-0 truncate rounded-full bg-white px-2 py-0.5 text-emerald-800 border border-emerald-100"><?= esc($c['consultation_status']); ?></span>
                                                    </span>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="triage-empty-filter rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center text-xs font-bold text-slate-500" data-triage-empty>No matching resident found.</p>
                                    </aside>

                                    <section class="triage-view-pane">
                                    <?php foreach ($queuePreview as $index => $c): ?>
                                        <?php $vitals = parseTriageVitals($c['consultation_notes'] ?? ''); ?>
                                        <div id="triage-panel-<?= (int) $c['id']; ?>"
                                            class="triage-detail-panel <?= $index === 0 ? 'is-active' : ''; ?>">
                                            <div class="triage-queue-card rounded-2xl bg-white p-4 sm:p-5 transition space-y-4">
                                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                <div class="min-w-0 space-y-2">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <p class="min-w-0 text-base font-extrabold text-gray-950">
                                                            <?= esc(rhuPatientLabel($c['patientName'], $c['resident_id'] ?? 0)); ?></p>
                                                        <span
                                                            class="triage-chip bg-emerald-50 text-emerald-800 border border-emerald-100">
                                                            <?= esc($c['age'] ?? 'N/A'); ?>y &bull; <?= esc($c['gender']); ?>
                                                        </span>
                                                        <span
                                                            class="triage-chip bg-violet-50 text-violet-700 border border-violet-100">
                                                            Barangay <?= esc($c['barangay']); ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm font-semibold text-slate-900">Chief Complaint: <span
                                                            class="font-normal text-slate-700"><?= esc($c['chiefComplaint']); ?></span>
                                                    </p>
                                                </div>
                                                <div class="flex shrink-0 flex-row items-center gap-2 lg:flex-col lg:items-end">
                                                    <span
                                                        class="font-mono text-xs bg-slate-50 text-slate-800 font-bold px-3 py-1.5 rounded-xl border border-slate-200"><?= esc($c['icd10'] ?: 'OPD Triage'); ?></span>
                                                    <p class="text-xs font-semibold text-slate-400"><?= esc($c['date']); ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <?php if (!empty($vitals)): ?>
                                                <div class="rounded-2xl border border-emerald-100 bg-emerald-50/50 p-3">
                                                    <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-emerald-900">Triage Vitals</p>
                                                    <div class="triage-vitals-grid">
                                                        <?php if (!empty($vitals['bp'])): ?><div class="triage-vital"><span>Blood Pressure</span><strong><?= esc($vitals['bp']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['temp'])): ?><div class="triage-vital"><span>Temperature</span><strong><?= esc($vitals['temp']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['weight'])): ?><div class="triage-vital"><span>Weight</span><strong><?= esc($vitals['weight']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['hr'])): ?><div class="triage-vital"><span>Heart Rate</span><strong><?= esc($vitals['hr']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['rr'])): ?><div class="triage-vital"><span>Resp. Rate</span><strong><?= esc($vitals['rr']); ?></strong></div><?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <details class="triage-note rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                                <summary class="cursor-pointer text-xs font-extrabold text-slate-700">Clinical Notes</summary>
                                                <p class="mt-2 text-xs font-medium leading-relaxed text-slate-600"><?= esc($c['consultation_notes']); ?></p>
                                            </details>

                                            <!-- VIEW-ONLY CONSULTATION RESPONSE -->
                                            <details class="triage-response group border-t border-slate-100 pt-3">
                                                <summary
                                                    class="cursor-pointer list-none text-xs font-bold text-slate-800 hover:text-emerald-800 flex items-center justify-between gap-3 py-1">
                                                    <span>Consultation Response</span>
                                                    <span
                                                        class="ml-auto rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-extrabold text-emerald-800 border border-emerald-100">Status:
                                                        <?= esc($c['consultation_status']); ?></span>
                                                </summary>
                                                <div class="mt-3 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-3 sm:grid-cols-3 sm:p-4">
                                                    <div class="rounded-xl bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Assessment</p>
                                                        <p class="mt-1 text-sm font-bold text-slate-900"><?= esc($c['diagnosis'] ?: 'No assessment recorded'); ?></p>
                                                    </div>
                                                    <div class="rounded-xl bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Medication</p>
                                                        <p class="mt-1 text-sm font-bold text-slate-900"><?= esc($c['medications'] ?: 'None recorded'); ?></p>
                                                    </div>
                                                    <div class="rounded-xl bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Current Status</p>
                                                        <p class="mt-1 text-sm font-bold text-emerald-800"><?= esc($c['consultation_status']); ?></p>
                                                    </div>
                                                </div>
                                            </details>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </section>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- TAB: RESIDENT REQUESTS -->
                <?php if ($tab === 'requests'): ?>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-emerald-100 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-xl font-extrabold text-gray-900">Resident Requests</h2>
                                <p class="text-xs text-gray-500">All requests submitted from the Resident Portal with resident profile, contact, and health details.</p>
                            </div>
                            <span class="rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1 text-xs font-extrabold text-emerald-800">
                                <?= count($residentRequests); ?> request<?= count($residentRequests) === 1 ? '' : 's'; ?>
                            </span>
                        </div>

                        <?php if (empty($residentRequests)): ?>
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center shadow-sm">
                                <p class="text-sm font-extrabold text-slate-800">No resident requests found</p>
                                <p class="mt-1 text-xs font-medium text-slate-500">New OPD appointments, resident messages, certificate requests, and emergency referrals will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="triage-workbench" data-triage-workbench>
                                <aside class="triage-suggest-pane rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                                    <div class="mb-3">
                                        <label for="residentRequestSearch" class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">Search Request</label>
                                        <input id="residentRequestSearch" data-triage-search class="triage-search" type="search" placeholder="Type name, barangay, request, status..." autocomplete="off">
                                    </div>
                                    <div class="triage-patient-list" data-triage-list>
                                        <?php foreach ($residentRequests as $index => $request): ?>
                                            <?php
                                            $residentInfo = $request['resident'] ?? [];
                                            $requestSearch = strtolower(trim(
                                                ($request['resident_id'] ?? '') . ' ' .
                                                ($request['resident_name'] ?? '') . ' ' .
                                                ($request['barangay'] ?? '') . ' ' .
                                                ($request['type'] ?? '') . ' ' .
                                                ($request['title'] ?? '') . ' ' .
                                                ($request['details'] ?? '') . ' ' .
                                                ($request['status'] ?? '')
                                            ));
                                            ?>
                                            <button type="button" class="triage-patient-tab <?= $index === 0 ? 'is-active' : ''; ?>" data-triage-tab data-target="resident-request-panel-<?= $index; ?>" data-search="<?= esc($requestSearch); ?>">
                                                <span class="block truncate text-sm font-extrabold text-slate-950"><?= esc(rhuPatientLabel($request['resident_name'] ?? 'Resident', $request['resident_id'] ?? 0)); ?></span>
                                                <span class="mt-1 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-[11px] font-bold text-slate-500">
                                                    <span class="min-w-0 truncate"><?= esc($request['type'] ?? 'Request'); ?></span>
                                                    <span class="max-w-[96px] shrink-0 truncate rounded-full bg-white px-2 py-0.5 text-emerald-800 border border-emerald-100"><?= esc($request['status'] ?? 'Pending'); ?></span>
                                                </span>
                                                <span class="mt-2 block truncate text-[10px] font-bold text-slate-500">
                                                    <?= esc($request['requested_at'] ? date('M d, Y g:i A', strtotime($request['requested_at'])) : ($request['request_date'] ?? 'No date')); ?>
                                                </span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="triage-empty-filter rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center text-xs font-bold text-slate-500" data-triage-empty>No matching request found.</p>
                                </aside>

                                <section class="triage-view-pane">
                                    <?php foreach ($residentRequests as $index => $request): ?>
                                        <?php
                                        $residentInfo = $request['resident'] ?? [];
                                        $requestStatus = strtolower((string)($request['status'] ?? ''));
                                        $statusClass = str_contains($requestStatus, 'urgent') || str_contains($requestStatus, 'emergency')
                                            ? 'bg-rose-50 text-rose-800 border-rose-100'
                                            : (str_contains($requestStatus, 'pending') || str_contains($requestStatus, 'scheduled') ? 'bg-amber-50 text-amber-800 border-amber-100' : 'bg-emerald-50 text-emerald-800 border-emerald-100');
                                        ?>
                                        <div id="resident-request-panel-<?= $index; ?>" class="triage-detail-panel <?= $index === 0 ? 'is-active' : ''; ?>">
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                                                <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="min-w-0 space-y-2">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <h3 class="text-lg font-extrabold text-slate-950"><?= esc(rhuPatientLabel($request['resident_name'] ?? 'Resident', $request['resident_id'] ?? 0)); ?></h3>
                                                            <span class="triage-chip border border-emerald-100 bg-emerald-50 text-emerald-800">Resident #<?= (int)($request['resident_id'] ?? 0); ?></span>
                                                            <span class="triage-chip border border-violet-100 bg-violet-50 text-violet-700">Barangay <?= esc($request['barangay'] ?? 'Unassigned'); ?></span>
                                                        </div>
                                                        <p class="text-sm font-bold text-slate-900"><?= esc($request['title'] ?? 'Resident request'); ?></p>
                                                        <p class="text-xs font-semibold text-slate-500"><?= esc($request['type'] ?? 'Request'); ?> &bull; Source <?= esc($request['source'] ?? 'portal'); ?> #<?= (int)($request['source_id'] ?? 0); ?></p>
                                                    </div>
                                                    <div class="flex shrink-0 flex-col gap-2 lg:items-end">
                                                        <span class="w-fit rounded-full border px-3 py-1 text-[11px] font-extrabold <?= $statusClass; ?>"><?= esc($request['status'] ?? 'Pending'); ?></span>
                                                        <span class="text-xs font-bold text-slate-500"><?= esc($request['requested_at'] ? date('M d, Y g:i A', strtotime($request['requested_at'])) : ($request['request_date'] ?? 'No date')); ?></span>
                                                    </div>
                                                </div>

                                                <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                                                    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Resident Information</p>
                                                        <dl class="mt-2 space-y-1.5 text-xs">
                                                            <div><dt class="font-bold text-slate-500">Birthdate / Age</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['date_of_birth'] ?? 'Not recorded'); ?><?= !empty($residentInfo['age']) ? ' - ' . esc($residentInfo['age']) . ' years old' : ''; ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Sex / Civil Status</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['gender'] ?? 'Not recorded'); ?><?= !empty($residentInfo['civil_status']) ? ' / ' . esc($residentInfo['civil_status']) : ''; ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Address</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['address'] ?? 'Not recorded'); ?></dd></div>
                                                        </dl>
                                                    </section>

                                                    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Contact Details</p>
                                                        <dl class="mt-2 space-y-1.5 text-xs">
                                                            <div><dt class="font-bold text-slate-500">Phone</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['contactNo'] ?? 'Not recorded'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Email</dt><dd class="break-all font-semibold text-slate-900"><?= esc($residentInfo['email'] ?? 'Not recorded'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Emergency Contact</dt><dd class="font-semibold text-slate-900"><?= esc(trim(($residentInfo['emergencyContactName'] ?? '') . ' ' . ($residentInfo['emergencyContactNo'] ?? '')) ?: 'Not recorded'); ?></dd></div>
                                                        </dl>
                                                    </section>

                                                    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Health Snapshot</p>
                                                        <dl class="mt-2 space-y-1.5 text-xs">
                                                            <div><dt class="font-bold text-slate-500">Blood / PhilHealth</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['bloodType'] ?? 'Not recorded'); ?> / <?= esc($residentInfo['philhealthNo'] ?? 'Not recorded'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Vitals</dt><dd class="font-semibold text-slate-900">Ht <?= esc($residentInfo['height'] ?? '-'); ?> cm, Wt <?= esc($residentInfo['weight'] ?? '-'); ?> kg, BP <?= esc($residentInfo['bloodPressure'] ?? '-'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Allergies / Conditions</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['allergies'] ?? 'None recorded'); ?><?= !empty($residentInfo['medicalHistory']) ? ' / ' . esc($residentInfo['medicalHistory']) : ''; ?></dd></div>
                                                        </dl>
                                                    </section>
                                                </div>

                                                <section class="mt-4 rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4">
                                                    <p class="text-[10px] font-black uppercase tracking-wide text-emerald-900">Complete Request Details</p>
                                                    <p class="mt-2 whitespace-pre-line text-sm font-semibold leading-relaxed text-slate-900"><?= esc($request['details'] ?? 'No details recorded.'); ?></p>
                                                </section>

                                                <div class="mt-4 flex flex-wrap justify-end gap-2">
                                                    <a href="<?= esc(tabUrl('patients', ['resident_id' => (int)($request['resident_id'] ?? 0)])); ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-extrabold text-slate-700 hover:bg-slate-50">Open Patient Record</a>
                                                    <a href="<?= esc($request['action_url'] ?? tabUrl('opd')); ?>" class="rounded-xl bg-emerald-700 px-3 py-2 text-xs font-extrabold text-white shadow-sm hover:bg-emerald-800">Review in Nurse Workflow</a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </section>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 2: OPD TRIAGE & NURSING LOG -->
                <?php if ($tab === 'opd'): ?>
                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-emerald-100 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">OPD
                                    Triage & Nursing Assessment Log</h2>
                                <p class="text-xs text-gray-500">Record and review vital signs assessment for all outpatient
                                    consultations</p>
                            </div>
                        </div>

                        <section class="dashboard-card bg-white rounded-2xl p-4 sm:p-5 shadow-sm border border-emerald-100/80">
                            <div class="mb-4 flex flex-col gap-2 border-b border-slate-100 pb-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-extrabold text-slate-950">Record OPD Patient Triage &amp; Vitals</h3>
                                    <p class="mt-0.5 text-xs font-medium text-slate-500">Patient intake, vital signs, diagnosis, and initial medication record.</p>
                                </div>
                                <span class="w-fit rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-extrabold text-emerald-800 border border-emerald-100">New Record</span>
                            </div>
                            <form class="resident-record-form grid grid-cols-1 gap-3 text-xs lg:grid-cols-2" method="post">
                                <input type="hidden" name="action" value="save_triage">

                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Select Patient Resident *</label>
                                    <div class="resident-combobox" data-resident-combobox>
                                        <input type="hidden" name="resident_id" data-resident-combobox-value>
                                        <button type="button" class="resident-combobox-button" data-resident-combobox-button aria-haspopup="listbox" aria-expanded="false">
                                            <span class="resident-combobox-label" data-resident-combobox-label>-- Select Resident Patient --</span>
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        <div class="resident-combobox-panel" data-resident-combobox-panel role="listbox">
                                            <?php foreach ($allResidentsList as $r): ?>
                                                <button type="button" class="resident-combobox-option" data-resident-combobox-option data-value="<?= esc($r['id']); ?>" title="<?= esc(trim(($r['name'] ?? '') . ' - ' . ($r['barangay'] ?? ''))); ?>" role="option">
                                                    <?= esc(rhuPatientLabel($r['name'], $r['id'] ?? 0)); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Attending Physician / Staff *</label>
                                    <input type="hidden" name="physician_id" value="<?= (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0); ?>">
                                    <div class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-900">
                                        <?= esc($_SESSION['rhu_staff_login']['name'] ?? 'Logged-in Nurse'); ?>
                                        <span class="font-semibold text-slate-500">(<?= esc($_SESSION['rhu_staff_login']['staff_type'] ?? 'Public Health Nurse'); ?>)</span>
                                    </div>
                                </div>

                                <div class="lg:col-span-2">
                                    <label class="block font-bold text-gray-700 mb-1">Chief Health Complaint *</label>
                                    <input name="chief_complaint" required placeholder="e.g., High fever, productive cough, and body malaise" class="w-full p-2.5 border border-gray-300 rounded-xl text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none">
                                </div>

                                <section class="rounded-xl border border-emerald-100 bg-emerald-50/60 px-3 py-3 lg:col-span-2">
                                    <div class="mb-3 flex items-center justify-between gap-2">
                                        <p class="text-xs font-extrabold text-emerald-900">Vital Signs Assessment</p>
                                        <span class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Initial Triage</span>
                                    </div>
                                    <div class="mt-3 grid grid-cols-2 gap-2 md:grid-cols-5">
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">BP</label>
                                            <input name="bp" value="120/80" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-mono font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Temp</label>
                                            <input name="temp" value="36.8C" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-mono font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Weight</label>
                                            <input name="weight" value="60 kg" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-mono font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Resp. Rate</label>
                                            <input name="rr" value="18/min" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Heart Rate</label>
                                            <input name="hr" value="75 bpm" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-bold text-gray-800">
                                        </div>
                                    </div>
                                </section>

                                <div class="icd-lookup-wrap">
                                    <label class="block font-bold text-gray-700 mb-1">Primary Diagnosis</label>
                                    <input name="diagnosis" value="Acute Upper Respiratory Tract Infection" class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold" data-icd-diagnosis-input data-icd-target="new_triage_icd10">
                                    <div class="icd-suggestions" data-icd-suggestions></div>
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">ICD-10 Code</label>
                                    <input id="new_triage_icd10" name="icd10" value="J06.9" class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono font-bold" data-icd-code-input>
                                </div>

                                <div class="lg:col-span-2">
                                    <label class="block font-bold text-gray-700 mb-1">Prescribed Medications</label>
                                    <input name="medications" placeholder="Paracetamol 500mg, Amoxicillin 500mg" class="w-full p-2.5 border border-gray-300 rounded-xl text-xs">
                                </div>

                                <div class="flex justify-end lg:col-span-2">
                                    <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-extrabold text-white shadow-sm transition hover:bg-emerald-800 sm:w-auto">Save Nursing Triage Vitals</button>
                                </div>
                            </form>
                        </section>

                        <div id="opd-triage-live-panel" data-opd-triage-live data-live-src="NurseDashboard.php?tab=opd">
                        <?php if (empty($opdResidents)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No OPD Triage Consultations Recorded</p>
                                <p class="text-xs text-gray-400 mt-0.5">Use the intake form above to start logging patient vital signs.</p>
                            </div>
                        <?php else: ?>
                            <div class="triage-workbench" data-triage-workbench data-consult-date-workbench>
                                <aside class="triage-suggest-pane rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                                    <div class="mb-3">
                                        <label for="opdTriageSearch" class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">Search Record</label>
                                        <input id="opdTriageSearch" data-triage-search class="triage-search" type="search" placeholder="Type patient, complaint, status..." autocomplete="off">
                                    </div>
                                    <div class="triage-patient-list" data-triage-list>
                                        <?php foreach ($opdResidents as $index => $resident): ?>
                                            <?php
                                            $latestRecord = $resident['records'][0] ?? [];
                                            $recordDates = array_values(array_unique(array_filter(array_map(function ($item) { return (string) ($item['date'] ?? ''); }, $resident['records']))));
                                            $tabSearch = strtolower(trim(($resident['resident_id'] ?? '') . ' ' . ($resident['patientName'] ?? '') . ' ' . ($resident['barangay'] ?? '') . ' ' . implode(' ', array_map(function ($item) { return (($item['chiefComplaint'] ?? '') . ' ' . ($item['consultation_status'] ?? '') . ' ' . ($item['icd10'] ?? '') . ' ' . ($item['date'] ?? '')); }, $resident['records']))));
                                            ?>
                                            <button type="button" class="triage-patient-tab <?= $index === 0 ? 'is-active' : ''; ?>" data-triage-tab data-target="opd-resident-panel-<?= (int) $resident['resident_id']; ?>" data-search="<?= esc($tabSearch); ?>">
                                                <span class="block truncate text-sm font-extrabold text-slate-950"><?= esc(rhuPatientLabel($resident['patientName'], $resident['resident_id'] ?? 0)); ?></span>
                                                <span class="mt-1 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-[11px] font-bold text-slate-500">
                                                    <span class="min-w-0 truncate"><?= esc($latestRecord['chiefComplaint'] ?? 'No complaint recorded'); ?></span>
                                                    <span class="max-w-[92px] shrink-0 truncate rounded-full bg-white px-2 py-0.5 text-emerald-800 border border-emerald-100"><?= count($resident['records']); ?> visit<?= count($resident['records']) === 1 ? '' : 's'; ?></span>
                                                </span>
                                                <span class="mt-2 flex flex-wrap gap-1 text-[10px] font-bold text-slate-500">
                                                    <?php foreach (array_slice($recordDates, 0, 3) as $recordDate): ?>
                                                        <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5"><?= esc($recordDate); ?></span>
                                                    <?php endforeach; ?>
                                                </span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="triage-empty-filter rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center text-xs font-bold text-slate-500" data-triage-empty>No matching record found.</p>
                                </aside>

                                <section class="triage-view-pane">
                                    <?php foreach ($opdResidents as $index => $resident): ?>
                                        <div id="opd-resident-panel-<?= (int) $resident['resident_id']; ?>" class="triage-detail-panel <?= $index === 0 ? 'is-active' : ''; ?>">
                                            <div class="triage-queue-card rounded-2xl bg-white p-4 sm:p-5 transition">
                                                <div class="mb-4 flex flex-col gap-3 border-b border-slate-100 pb-3 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="min-w-0 space-y-2">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <p class="min-w-0 text-base font-extrabold text-gray-950"><?= esc(rhuPatientLabel($resident['patientName'], $resident['resident_id'] ?? 0)); ?></p>
                                                            <span class="triage-chip bg-emerald-50 text-emerald-800 border border-emerald-100"><?= esc($resident['age'] ?: 'N/A'); ?>y &bull; <?= esc($resident['gender']); ?></span>
                                                            <span class="triage-chip bg-violet-50 text-violet-700 border border-violet-100">Barangay <?= esc($resident['barangay']); ?></span>
                                                        </div>
                                                        <p class="text-xs font-semibold text-slate-500"><?= count($resident['records']); ?> consultation record<?= count($resident['records']) === 1 ? '' : 's'; ?> on file. Select a date to view that visit.</p>
                                                    </div>
                                                    <label class="w-full max-w-xs">
                                                        <span class="mb-1 block text-[11px] font-bold text-slate-700">Filter by Consultation Date</span>
                                                        <select class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm font-bold text-emerald-900 outline-none focus:border-emerald-500" data-consult-date-select>
                                                            <?php foreach ($resident['records'] as $recordIndex => $record): ?>
                                                                <option value="consult-record-<?= (int) $record['id']; ?>" <?= $recordIndex === 0 ? 'selected' : ''; ?>><?= esc($record['date'] ?? 'No date'); ?> - <?= esc($record['consultation_status'] ?? 'Scheduled'); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                </div>

                                                <?php foreach ($resident['records'] as $recordIndex => $c): ?>
                                                    <?php $vitals = parseTriageVitals($c['consultation_notes'] ?? ''); ?>
                                                    <form method="post" action="NurseDashboard.php?tab=opd" id="consult-record-<?= (int) $c['id']; ?>" class="space-y-4 <?= $recordIndex === 0 ? '' : 'hidden'; ?>" data-consult-date-record>
                                                <input type="hidden" name="action" value="answer_consultation">
                                                <input type="hidden" name="return_tab" value="opd">
                                                <input type="hidden" name="consultation_id" value="<?= (int) $c['id']; ?>">
                                                <input type="hidden" name="resident_id" value="<?= (int) ($c['resident_id'] ?? 0); ?>">

                                                <div class="flex flex-col gap-3 border-b border-slate-100 pb-3 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="min-w-0 space-y-1">
                                                        <p class="text-sm font-semibold text-slate-900">Chief Complaint: <span class="font-normal text-slate-700"><?= esc($c['chiefComplaint']); ?></span></p>
                                                        <p class="text-xs font-semibold text-slate-400"><?= esc($c['date']); ?></p>
                                                    </div>
                                                    <div class="flex shrink-0 flex-row items-center gap-2 lg:flex-col lg:items-end">
                                                        <span class="font-mono text-xs bg-slate-50 text-slate-800 font-bold px-3 py-1.5 rounded-xl border border-slate-200"><?= esc($c['icd10'] ?: 'OPD Triage'); ?></span>
                                                        <?php if (!empty($c['referral_needed'])): ?>
                                                            <span class="max-w-[180px] truncate rounded-full bg-violet-50 px-3 py-1 text-[11px] font-bold text-violet-700 border border-violet-100">Referred to <?= esc($c['referral_to']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <?php if (!empty($vitals)): ?>
                                                    <details class="triage-note rounded-xl border border-emerald-100 bg-emerald-50/50 px-3 py-2" open>
                                                        <summary class="cursor-pointer text-xs font-extrabold text-emerald-900">Vitals</summary>
                                                        <div class="triage-vitals-grid mt-2">
                                                            <?php if (!empty($vitals['bp'])): ?><div class="triage-vital"><span>Blood Pressure</span><strong><?= esc($vitals['bp']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['temp'])): ?><div class="triage-vital"><span>Temperature</span><strong><?= esc($vitals['temp']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['weight'])): ?><div class="triage-vital"><span>Weight</span><strong><?= esc($vitals['weight']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['hr'])): ?><div class="triage-vital"><span>Heart Rate</span><strong><?= esc($vitals['hr']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['rr'])): ?><div class="triage-vital"><span>Resp. Rate</span><strong><?= esc($vitals['rr']); ?></strong></div><?php endif; ?>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>

                                                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                                    <div class="icd-lookup-wrap">
                                                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Clinical Diagnosis / Assessment</label>
                                                        <input type="text" name="diagnosis" value="<?= esc($c['diagnosis'] ?? ''); ?>" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm outline-none focus:border-emerald-500" placeholder="Enter clinical assessment" required data-icd-diagnosis-input data-icd-target="opd_icd10_<?= (int) $c['id']; ?>">
                                                        <div class="icd-suggestions" data-icd-suggestions></div>
                                                    </div>
                                                    <div>
                                                        <label class="block text-[11px] font-bold text-slate-700 mb-1">ICD-10 Code</label>
                                                        <input id="opd_icd10_<?= (int) $c['id']; ?>" name="icd10" value="<?= esc($c['icd10'] ?? ''); ?>" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm font-mono font-bold outline-none focus:border-emerald-500" placeholder="Auto-filled from diagnosis" data-icd-code-input>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Consultation Status</label>
                                                        <select name="consultation_status" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm font-bold text-emerald-900 outline-none focus:border-emerald-500">
                                                            <option value="Completed" <?= ($c['consultation_status'] ?? '') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                            <option value="In Progress" <?= ($c['consultation_status'] ?? '') === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                            <option value="Scheduled" <?= ($c['consultation_status'] ?? '') === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                            <option value="Referred" <?= ($c['consultation_status'] ?? '') === 'Referred' ? 'selected' : ''; ?>>Referred to Doctor</option>
                                                        </select>
                                                </div>

                                                <section class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                                                    <div class="mb-3 flex items-center justify-between gap-2">
                                                        <p class="text-xs font-extrabold text-slate-800">Edit Notes & Medication</p>
                                                        <span class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Clinical Plan</span>
                                                    </div>
                                                    <div class="space-y-3">
                                                        <div>
                                                            <label class="block text-[11px] font-bold text-slate-700 mb-1">Clinical Assessment Notes</label>
                                                            <textarea name="consultation_notes" rows="3" class="w-full min-h-[84px] rounded-xl border border-slate-300 bg-white p-2.5 text-sm outline-none resize-y focus:border-emerald-500" placeholder="Enter notes and recommendations"><?= esc($c['consultation_notes'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div>
                                                            <label class="block text-[11px] font-bold text-slate-700 mb-1">Prescribed Medications</label>
                                                            <input type="text" name="medications_prescribed" value="<?= esc($c['medications'] ?? ''); ?>" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm outline-none focus:border-emerald-500" placeholder="e.g. Paracetamol, ORS">
                                                        </div>
                                                    </div>
                                                </section>

                                                <div class="flex justify-end">
                                                    <button type="submit" name="save_changes" value="1" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-extrabold text-white shadow-sm transition hover:bg-emerald-800 sm:w-auto">Save Changes</button>
                                                </div>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </section>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- TAB 3: PATIENT RECORDS -->
                <?php if ($tab === 'patients'): ?>
                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-blue-100 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">
                                    Municipal Resident Health Records</h2>
                                <p class="text-xs text-gray-500">Complete resident health profiles, medical history,
                                    allergies, and vitals registry</p>
                            </div>
                            <a href="<?= esc(tabUrl('patients', ['modal' => 'new_patient_record'])); ?>"
                                class="px-4 py-2.5 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md transition-all flex items-center gap-1.5">
                                <span>+</span> Add / Register Medical Record
                            </a>
                        </div>

                        <?php if (empty($patientRecords)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No Patient Records Registered</p>
                                <p class="text-xs text-gray-400 mt-0.5">Click "+ Add / Register Medical Record" above to record
                                    a resident's medical info.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3">
                                <?php foreach ($patientRecords as $p): ?>
                                    <div data-record-search-row data-patient-card="<?= (int)$p['id']; ?>"
                                        class="dashboard-card bg-white rounded-2xl p-5 shadow-sm border border-gray-200/80 hover:border-blue-200 transition-all space-y-3">
                                        <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 pb-3">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="font-mono font-bold text-xs bg-slate-100 text-slate-700 px-2 py-0.5 rounded">Patient #<?= (int)$p['id']; ?></span>
                                                    <p class="font-extrabold text-gray-900 text-base"><?= esc($p['name']); ?></p>
                                                    <span
                                                        class="text-xs font-bold text-purple-800 bg-purple-50 px-2 py-0.5 rounded border border-purple-100">
                                                        <?= esc($p['barangay']); ?>
                                                    </span>
                                                </div>
                                                <p class="text-xs text-gray-600 mt-1 font-semibold">
                                                    Age: <?= esc($p['age'] ?? 'N/A'); ?>y • Sex: <?= esc($p['gender']); ?> •
                                                    PhilHealth No: <span
                                                        class="font-mono text-blue-900"><?= esc($p['philhealthNo'] ?: 'N/A'); ?></span>
                                                    <?php if (!empty($p['contactNo'])): ?> ·
                                                        <?= esc($p['contactNo']); ?>            <?php endif; ?>
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="font-bold text-xs text-red-700 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200">
                                                    Blood Type: <span data-patient-blood-type><?= esc($p['bloodType'] ?: 'Not recorded'); ?></span>
                                                </span>
                                                <a href="<?= esc(tabUrl('patients', ['modal' => 'new_patient_record', 'resident_id' => $p['id']])); ?>"
                                                    class="px-3 py-1 bg-slate-100 hover:bg-blue-50 text-blue-700 border border-slate-200 hover:border-blue-300 font-bold text-xs rounded-xl transition-all">
                                                    Edit Medical Record
                                                </a>
                                            </div>
                                        </div>

                                        <!-- Medical Profile Details Pill Grid -->
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
                                            <div class="bg-slate-50/80 p-2.5 rounded-xl border border-slate-100">
                                                <span class="font-bold text-rose-900 block text-[11px]">Known Allergies:</span>
                                                <span
                                                    data-patient-allergies
                                                    class="text-gray-800 font-medium"><?= esc($p['allergies'] ?: 'None Reported'); ?></span>
                                            </div>
                                            <div class="bg-slate-50/80 p-2.5 rounded-xl border border-slate-100">
                                                <span class="font-bold text-purple-900 block text-[11px]">Pre-existing Medical
                                                    History:</span>
                                                <span
                                                    data-patient-medical-history
                                                    class="text-gray-800 font-medium"><?= esc($p['medicalHistory'] ?: 'No chronic conditions recorded'); ?></span>
                                            </div>
                                        </div>

                                        <?php if (!empty($p['height']) || !empty($p['weight']) || !empty($p['bloodPressure'])): ?>
                                            <div
                                                class="flex flex-wrap items-center gap-2 bg-emerald-50/40 p-2 rounded-xl border border-emerald-100/60 text-xs">
                                                <span class="font-bold text-emerald-900 text-[11px] mr-1">Latest Physical
                                                    Profile:</span>
                                                <?php if (!empty($p['height'])): ?><span
                                                        class="bg-white text-emerald-800 font-mono font-bold px-2 py-0.5 rounded border border-emerald-200">Ht:
                                                        <span data-patient-height><?= esc($p['height']); ?></span> cm</span><?php endif; ?>
                                                <?php if (!empty($p['weight'])): ?><span
                                                        class="bg-white text-blue-800 font-mono font-bold px-2 py-0.5 rounded border border-blue-200">Wt:
                                                        <span data-patient-weight><?= esc($p['weight']); ?></span> kg</span><?php endif; ?>
                                                <?php if (!empty($p['bloodPressure'])): ?><span
                                                        class="bg-white text-purple-800 font-mono font-bold px-2 py-0.5 rounded border border-purple-200">BP:
                                                        <span data-patient-bp><?= esc($p['bloodPressure']); ?></span></span><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

    <?php if ($modal === 'new_immunization'): ?>
        <div id="nurse-vaccine-modal" role="dialog" aria-modal="true" aria-labelledby="nurse-vaccine-title" class="nurse-form-modal fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-center justify-center z-50 p-3 sm:p-4">
            <div class="nurse-modal-panel bg-white rounded-lg shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 id="nurse-vaccine-title" class="text-base font-bold text-slate-800">Record Administered Vaccine</h2>
                        <p class="text-xs text-slate-500">Vaccine, batch number, and next dose</p>
                    </div>
                    <a href="<?= esc(tabUrl('immunization')); ?>" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></a>
                </div>
                <form id="nurse-vaccine-form" class="resident-record-form p-5 space-y-4 text-xs overflow-y-auto" method="post" action="<?= esc(tabUrl('immunization')); ?>">

                    <input type="hidden" name="action" value="save_immunization">
                    <input type="hidden" name="consultation_id" value="">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Select Resident / Child *</label>
                        <select required name="resident_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">-- Select resident --</option>
                            <?php foreach ($allResidentsList as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= $selectedResidentId === (int)$r['id'] ? 'selected' : '' ?>><?= esc(rhuPatientLabel($r['name'], $r['id'] ?? 0)) ?> - <?= esc($r['barangay']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="nurse-vaccine-appointment" class="hidden rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-900" aria-live="polite"></div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Vaccine *</label>
                            <select required name="vaccine_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select vaccine</option>
                                <?php foreach ($vaccineSchedules as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>"><?= esc($v['vaccine_name']) ?> (<?= esc($v['age_group']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Vaccination Date *</label>
                            <input required type="date" name="vaccination_date" value="<?= date('Y-m-d') ?>" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Batch / Lot Number *</label>
                            <input required name="batch_number" placeholder="Vaccine batch/lot number" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Injection Site</label>
                            <input name="site_of_injection" placeholder="e.g., Left deltoid" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Next Dose Date</label>
                            <input type="date" name="next_dose_date" class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Adverse Reactions</label>
                        <input name="adverse_reactions" placeholder="Adverse reactions, if any" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('immunization')); ?>" class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit" class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition-all">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

                <script>
                    (() => {
                        const form = document.getElementById('nurse-vaccine-form');
                        if (!form) return;
                        const appointments = <?= json_encode($immunizationAppointments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                        const resident = form.elements.namedItem('resident_id');
                        const summary = document.getElementById('nurse-vaccine-appointment');
                        const today = <?= json_encode(date('Y-m-d')) ?>;
                        const fillAppointment = () => {
                            const appointment = appointments[resident.value];
                            form.elements.namedItem('consultation_id').value = appointment?.id || '';
                            form.elements.namedItem('vaccination_date').value = appointment?.date || today;
                            for (const name of ['vaccine_id', 'batch_number', 'site_of_injection', 'next_dose_date']) {
                                form.elements.namedItem(name).value = appointment?.[name] || '';
                            }
                            form.elements.namedItem('adverse_reactions').value = '';
                            form.elements.namedItem('next_dose_date').min = form.elements.namedItem('vaccination_date').value;
                            summary.classList.toggle('hidden', !appointment);
                            summary.textContent = appointment
                                ? `Appointment #${appointment.id} | ${appointment.date} | ${appointment.status} | ${appointment.complaint}`
                                : '';
                        };
                        resident.addEventListener('change', fillAppointment);
                        fillAppointment();
                        form.elements.namedItem('vaccination_date').addEventListener('change', () => {
                            form.elements.namedItem('next_dose_date').min = form.elements.namedItem('vaccination_date').value;
                        });
                        document.addEventListener('keydown', event => {
                            if (event.key === 'Escape') window.location.href = <?= json_encode(tabUrl('immunization')) ?>;
                        });
                    })();
                </script>
                <!-- TAB 4: IMMUNIZATION -->
                <?php if ($tab === 'immunization'): ?>
                    <div class="space-y-4">
                        <a href="<?= esc(tabUrl('immunization', ['modal' => 'new_immunization'])); ?>" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-800"><i data-lucide="plus" class="h-4 w-4"></i> Record Vaccine</a>
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">Expanded
                            Program on Immunization (EPI) Monitoring</h2>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            <div
                                class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-emerald-100 text-center">
                                <p class="text-3xl font-black text-emerald-700"><?= $immunizationAdministeredCount; ?></p>
                                <p class="text-xs font-bold text-gray-600 mt-1">Administered Records</p>
                            </div>
                            <div
                                class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-amber-100 text-center">
                                <p class="text-3xl font-black text-amber-600"><?= $immunizationDueThisMonthCount; ?></p>
                                <p class="text-xs font-bold text-gray-600 mt-1">Due This Month</p>
                            </div>
                            <div
                                class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-rose-100 text-center">
                                <p class="text-3xl font-black text-rose-600"><?= $immunizationOverdueCount; ?></p>
                                <p class="text-xs font-bold text-gray-600 mt-1">Overdue Vaccines</p>
                            </div>
                        </div>

                        <section class="rounded-2xl border border-emerald-100 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="pending-immunization-heading">
                            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                                <div><h3 id="pending-immunization-heading" class="text-base font-extrabold text-slate-900">Pending immunization appointments</h3><p class="mt-1 text-xs text-slate-500">All booked vaccination visits, including appointments awaiting completion.</p></div>
                                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800"><?= count($pendingImmunizationAppointments) ?> pending</span>
                            </div>
                            <?php if (!$pendingImmunizationAppointments): ?>
                                <p class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-5 text-center text-sm text-slate-500">No pending immunization appointments.</p>
                            <?php else: ?>
                                <div class="grid gap-3 lg:grid-cols-2">
                                    <?php foreach ($pendingImmunizationAppointments as $booking): ?>
                                        <?php $bookingDate = substr((string)($booking['date'] ?? ''), 0, 10); $overdueBooking = $bookingDate !== '' && $bookingDate < date('Y-m-d'); ?>
                                        <a data-record-search-row href="<?= esc(tabUrl('immunization', ['modal'=>'new_immunization', 'resident_id'=>(int)$booking['resident_id'], 'qr_appointment'=>(int)$booking['id'], 'consultation_id'=>(int)$booking['id']])); ?>" class="block rounded-xl border border-slate-200 bg-white p-4 transition hover:border-emerald-400 hover:bg-emerald-50/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                                            <div class="flex items-start justify-between gap-3"><h4 class="text-sm font-extrabold text-slate-900"><?= esc(rhuPatientLabel($booking['patientName'] ?? 'Resident', $booking['resident_id'])) ?></h4><span class="shrink-0 rounded-full bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-800"><?= esc($booking['consultation_status'] ?: 'Pending') ?></span></div>
                                            <p class="mt-2 text-xs font-semibold text-emerald-800"><?= esc($booking['chiefComplaint'] ?? '') ?></p>
                                            <p class="mt-2 text-xs text-slate-500">Appointment #<?= (int)$booking['id'] ?> &middot; <?= esc($bookingDate ?: 'Date not specified') ?><?= !empty($booking['time']) ? ' &middot; ' . esc(substr($booking['time'],0,5)) : '' ?><?= $overdueBooking ? ' &middot; Awaiting completion' : '' ?></p>
                                            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs font-bold text-emerald-700"><span>Record vaccine for this appointment</span><span aria-hidden="true">&rarr;</span></div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <?php if (empty($immunizationRecords)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No Immunization Records Found</p>
                                <p class="text-xs text-gray-400 mt-0.5">Vaccination records will appear here as doses are
                                    administered and logged.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3 rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600" style="max-height:min(60vh,480px);overflow-y:auto;overscroll-behavior-y:contain;scrollbar-gutter:stable;padding:2px 8px 2px 2px" role="region" aria-label="Administered immunization records" tabindex="0">
                                <?php foreach ($latestImmunizationRecords as $im): ?>
                                    <div data-record-search-row
                                        class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-gray-200/80 flex items-center justify-between gap-3">
                                        <div>
                                            <p class="font-extrabold text-gray-900 text-sm sm:text-base">
                                                <?= esc(rhuPatientLabel($im['childName'], $im['resident_id'] ?? 0)); ?></p>
                                            <p class="text-xs text-gray-600 mt-0.5">Vaccine: <strong
                                                    class="text-indigo-900 font-extrabold"><?= esc($im['vaccineName']); ?></strong>
                                                (<?= esc($im['targetAge']); ?>) · Batch: <?= esc($im['lot']); ?></p>
                                            <p class="text-[11px] text-gray-400 mt-0.5">Barangay: <?= esc($im['barangay']); ?> ·
                                                Given: <?= esc($im['dateGiven']); ?> · Next Visit: <strong
                                                    class="<?= !empty($im['isOverdue']) ? 'text-red-600' : 'text-emerald-700'; ?> font-bold"><?= esc($im['nextVisit'] ?: 'Not scheduled'); ?></strong></p>
                                        </div>
                                        <span
                                            class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 shrink-0">Administered</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 5: NUTRITION (OPT+) -->
                <?php if ($tab === 'nutrition'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">Operation
                            Timbang Plus (OPT+) Child Nutrition</h2>
                        <?php if (empty($nutritionCases)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No Operation Timbang Profiles Recorded</p>
                                <p class="text-xs text-gray-400 mt-0.5">Child growth monitoring measurements will display here.
                                </p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3">
                                <?php foreach ($nutritionCases as $n): ?>
                                    <div data-record-search-row
                                        class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-gray-200/80 space-y-2.5">
                                        <div class="flex items-center justify-between">
                                            <p class="font-extrabold text-gray-900 text-sm"><?= esc(rhuPatientLabel($n['name'], $n['resident_id'] ?? $n['id'] ?? 0)); ?> <span
                                                    class="text-xs text-gray-500 font-semibold">(<?= esc($n['age']); ?>y /
                                                    <?= esc($n['gender']); ?>)</span></p>
                                            <span
                                                class="px-2.5 py-0.5 rounded-full text-xs font-bold border <?= esc($n['bmi_badge_class'] ?? 'bg-slate-100 text-slate-700 border-slate-200'); ?>"><?= esc($n['bmi_status'] ?? 'No BMI Status'); ?></span>
                                        </div>
                                        <div
                                            class="flex gap-4 text-xs font-semibold text-gray-600 bg-slate-50 p-3 rounded-xl border border-slate-200/60">
                                            <span>Barangay: <strong
                                                    class="text-gray-900"><?= esc($n['barangay']); ?></strong></span>
                                            <span>Height: <strong class="text-emerald-700"><?= esc($n['height']); ?>
                                                    cm</strong></span>
                                            <span>Weight: <strong class="text-blue-700"><?= esc($n['weight']); ?> kg</strong></span>
                                            <span>BMI: <strong class="text-gray-900"><?= esc($n['bmi'] ?? ''); ?></strong></span>
                                        </div>
                                        <?php if (($n['bmi_status'] ?? '') !== 'Normal'): ?>
                                            <form method="post" action="<?= esc(tabUrl('nutrition')); ?>" class="flex justify-end">
                                                <input type="hidden" name="action" value="alert_resident_bmi">
                                                <input type="hidden" name="resident_id" value="<?= (int) $n['id']; ?>">
                                                <input type="hidden" name="bmi_alert_token" value="<?= esc($_SESSION['nurse_bmi_alert_token']); ?>">
                                                <button type="submit" class="px-3 py-2 rounded-lg bg-amber-100 text-amber-900 border border-amber-300 text-xs font-bold hover:bg-amber-200 disabled:opacity-50" onclick="this.form.addEventListener('submit', () => { this.disabled = true; }, { once: true });">Alert Resident</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 6: TB-DOTS -->
                <?php if ($tab === 'tb'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">TB-DOTS Case
                            Management &amp; Treatment Adherence</h2>
                        <?php if (empty($tbCases)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No TB-DOTS Cases Recorded</p>
                                <p class="text-xs text-gray-400 mt-0.5">Tuberculosis patients on treatment regimen will be
                                    listed here.</p>
                            </div>
                        <?php else: ?>
                            <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list space-y-3">
                                <?php foreach ($tbCases as $tb): ?>
                                    <div data-record-search-row
                                        class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-gray-200/80 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="font-extrabold text-gray-900 text-sm sm:text-base">
                                                    <?= esc(rhuPatientLabel($tb['name'], $tb['resident_id'] ?? 0)); ?> <span
                                                        class="text-xs text-gray-500 font-semibold">(<?= esc($tb['age']); ?>y /
                                                        <?= esc($tb['gender']); ?>)</span></p>
                                                <p class="text-xs text-gray-500 font-mono mt-0.5"><?= esc($tb['record_source'] ?? 'Surveillance'); ?> Record:
                                                    <?= esc($tb['tb_registration_number']); ?> · Type:
                                                    <?= esc($tb['classification']); ?></p>
                                            </div>
                                            <span
                                                class="px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-200"><?= esc($tb['treatment_status']); ?></span>
                                        </div>
                                        <p class="text-xs text-gray-600 bg-slate-50 p-2.5 rounded-xl border border-slate-100">
                                            Barangay: <strong><?= esc($tb['barangay']); ?></strong> · Record Date:
                                            <strong><?= esc($tb['treatment_start_date']); ?></strong></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 7: DISEASE SURVEILLANCE (PIDSR) -->
                <?php if ($tab === 'disease'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">Disease
                            Surveillance (PIDSR)</h2>
                        <div class="dashboard-card bg-white rounded-2xl p-5 shadow-sm border border-gray-200/80 space-y-4">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                <h3 class="font-extrabold text-gray-900 text-sm">Recorded Diseases Surveillance
                                </h3>
                                <span
                                    class="text-xs font-bold bg-red-100 text-red-800 px-2.5 py-1 rounded-full border border-red-200">DOH
                                    Region IV-A</span>
                            </div>
                            <?php if (empty($diseaseReports)): ?>
                                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                                    <p class="text-sm font-extrabold text-slate-700">No disease surveillance cases recorded</p>
                                    <p class="mt-1 text-xs font-medium text-slate-500">PIDSR summaries will appear here once cases are encoded in the disease surveillance database.</p>
                                </div>
                            <?php else: ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                    <?php foreach ($diseaseReports as $report): ?>
                                        <?php
                                        $caseCount = (int)($report['case_count'] ?? 0);
                                        $confirmedCount = (int)($report['confirmed_count'] ?? 0);
                                        $suspectedCount = (int)($report['suspected_count'] ?? 0);
                                        $alertClass = $confirmedCount > 0
                                            ? 'bg-red-50/80 border-red-200 text-red-900'
                                            : ($suspectedCount > 0 ? 'bg-amber-50/80 border-amber-200 text-amber-900' : 'bg-emerald-50/80 border-emerald-200 text-emerald-900');
                                        $detailClass = $confirmedCount > 0
                                            ? 'text-red-700'
                                            : ($suspectedCount > 0 ? 'text-amber-700' : 'text-emerald-700');
                                        $diseaseName = trim((string)($report['disease_name'] ?? 'Unspecified disease')) ?: 'Unspecified disease';
                                        $icdCode = trim((string)($report['icd_code'] ?? ''));
                                        $commonDiseaseNames = [
                                            'tuberculosis of lung' => 'Lung tuberculosis (TB)',
                                            'pulmonary tuberculosis' => 'Lung tuberculosis (TB)',
                                            'dengue fever' => 'Dengue',
                                            'dengue hemorrhagic fever' => 'Dengue with bleeding',
                                            'typhoid fever unspecified' => 'Typhoid fever',
                                            'hypertension' => 'High blood pressure',
                                            'essential hypertension' => 'High blood pressure',
                                            'cervicogenic headache' => 'Headache related to the neck',
                                            'irritable bowel syndrome with constipation' => 'Irritable bowel syndrome (IBS) with constipation',
                                            'acute gastroenteritis' => 'Stomach and intestinal inflammation',
                                        ];
                                        $displayNameKey = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $diseaseName)));
                                        $diseaseName = $commonDiseaseNames[$displayNameKey] ?? $diseaseName;
                                        if (preg_match('/^A01(?:\.0[0-9]?)?$/i', $icdCode)) {
                                            $diseaseName = 'Typhoid fever';
                                        } elseif (strtoupper($icdCode) === 'I10') {
                                            $diseaseName = 'High blood pressure';
                                        }
                                        $latestDate = trim((string)($report['latest_case_date'] ?? ''));
                                        ?>
                                        <div data-record-search-row class="p-4 border rounded-2xl space-y-2 <?= esc($alertClass); ?>">
                                            <div class="flex items-start justify-between gap-3">
                                                <p class="font-extrabold text-sm">
                                                    <?= esc($diseaseName); ?>
                                                </p>
                                                <span class="shrink-0 rounded-full bg-white/80 px-2.5 py-1 text-[11px] font-black">
                                                    <?= $caseCount; ?> case<?= $caseCount === 1 ? '' : 's'; ?>
                                                </span>
                                            </div>
                                            <p class="<?= esc($detailClass); ?>">
                                                <?= $confirmedCount; ?> confirmed<?= $suspectedCount > 0 ? ' · ' . $suspectedCount . ' suspected/probable' : ''; ?>
                                                <?= $latestDate !== '' ? ' · Latest: ' . esc($latestDate) : ''; ?>
                                            </p>
                                            <p class="text-[11px] font-semibold <?= esc($detailClass); ?>">
                                                Barangay<?= str_contains((string)($report['barangays'] ?? ''), ',') ? 's' : ''; ?>:
                                                <?= esc($report['barangays'] ?: 'Unassigned'); ?>
                                            </p>
                                            <p class="text-[11px] font-semibold <?= esc($detailClass); ?>">
                                                Status: <?= esc($report['statuses'] ?: 'Recorded'); ?>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'certificates' && $pdo instanceof PDO): ?>
                    <?= portalRenderCertificateIssuancePanel($pdo, $allResidentsList, $nurseCertificateTypes, (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0), 'emerald') ?>
                <?php elseif ($tab === 'certificates'): ?>
                    <div class="rounded-2xl border border-red-100 bg-red-50 p-4 text-sm font-bold text-red-800">
                        Database connection is unavailable. Certificates cannot be issued right now.
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>



    <!-- MOBILE BOTTOM TAB BAR -->
    <nav class="sm:hidden fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-gray-200 safe-area-pb shadow-2xl">
        <div class="flex items-stretch">
            <?php foreach ($tabs as $id => [$label, $icon]): ?>
                <a href="<?= esc(tabUrl($id)); ?>"
                    class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2 text-[10px] font-semibold transition-colors relative <?= $tab === $id ? 'text-emerald-700 font-extrabold' : 'text-gray-400'; ?>">
                    <?php if ($tab === $id): ?>
                        <span class="absolute top-0 left-1/2 -translate-x-1/2 w-6 h-0.5 bg-emerald-600 rounded-full"></span>
                    <?php endif; ?>
                    <span class="text-base leading-none"><?= $icon; ?></span>
                    <span class="truncate px-0.5"><?= esc($label); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    </div>

    <!-- MODAL: ADD / EDIT RESIDENT MEDICAL RECORD -->
    <?php if ($modal === 'new_patient_record'): ?>
        <div
            class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-3xl shadow-2xl w-full sm:max-w-2xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-100">
                <div
                    class="p-5 border-b flex items-center justify-between bg-white-700 text-black rounded-t-3xl shrink-0">
                    <div>
                        <h2 class="text-base font-extrabold flex items-center gap-2">Save Resident Medical Record &amp;
                            Health Profile</h2>
                        <p class="text-xs text-black-500">Select a resident to auto-fill their info. Medical history and
                            vitals can be edited.</p>
                    </div>
                    <a href="<?= esc(tabUrl('patients')); ?>"
                        class="text-black-100 hover:text-black800 text-lg font-bold w-8 h-8 rounded-full bg-white/10 flex items-center justify-center">×</a>
                </div>
                <form class="resident-record-form p-5 space-y-4 text-xs overflow-y-auto" method="post" action="NurseDashboard.php?tab=patients" data-nurse-patient-record-form>
                    <input type="hidden" name="action" value="save_patient_record">
                    <input type="hidden" name="ajax" value="1">

                    <div class="bg-blue-50/60 p-4 rounded-2xl border border-blue-100 space-y-3">
                        <p class="font-extrabold text-blue-950 text-xs">Resident Identity Selection</p>
                        <div>
                            <label for="nurse-resident-search" class="mb-1 block font-bold text-gray-700">Search residents</label>
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>
                                <input id="nurse-resident-search" type="search" autocomplete="off" placeholder="Search by name, patient number, or barangay" data-resident-select-search="nurse_resident_select" aria-controls="nurse_resident_select" aria-describedby="nurse-resident-search-status" class="w-full rounded-xl border border-blue-200 bg-white py-3 pl-10 pr-3 text-sm font-medium text-slate-800 outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-200">
                            </div>
                            <p id="nurse-resident-search-status" role="status" aria-live="polite" class="mt-1.5 text-[11px] text-slate-500"></p>
                        </div>
                        <div>
                            <label for="nurse_resident_select" class="block font-bold text-gray-700 mb-1">Select Existing Resident (or leave blank to
                                create new) *</label>
                            <select name="resident_id" id="nurse_resident_select"
                                onchange="onNurseResidentSelectChange(this)"
                                class="resident-patient-select w-full p-3 border border-gray-300 rounded-xl text-sm font-semibold focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none">
                                <option value="0">-- Create New Resident Record --</option>
                                <?php foreach ($allResidentsList as $r): ?>
                                    <option value="<?= esc($r['id']); ?>" data-barangay="<?= esc($r['barangay']); ?>" title="<?= esc(trim(($r['name'] ?? '') . ' - ' . ($r['barangay'] ?? ''))); ?>" <?= $selectedResidentId === (int) $r['id'] ? 'selected' : '' ?>><?= esc(rhuPatientLabel($r['name'], $r['id'] ?? 0)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php ob_start(); ?>
                        <div id="nurse_new_resident_fields" class="grid grid-cols-2 gap-3 pt-1">
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">First Name</label>
                                <input name="first_name" id="nurse_first_name" placeholder="First Name"
                                    value="<?= esc($selectedResidentData['first_name'] ?? '') ?>"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold">
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Middle Name (optional)</label>
                                <input name="rhu_intake[middle_name]" maxlength="150" class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold">
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Last Name</label>
                                <input name="last_name" id="nurse_last_name" placeholder="Last Name"
                                    value="<?= esc($selectedResidentData['last_name'] ?? '') ?>"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold">
                            </div>
                        </div>
                        <?php $nurseIntakeIdentity = ob_get_clean(); ?>
                    </div>

                    <?php ob_start(); ?>
                    <div id="nurse_identity_details" class="grid grid-cols-3 gap-2.5">
                        <div>
                            <label for="nurse_date_of_birth" class="block font-bold text-gray-700 mb-1">Date of Birth *</label>
                            <input type="date" name="date_of_birth" id="nurse_date_of_birth" required max="<?= date('Y-m-d'); ?>"
                                value="<?= esc($selectedResidentData['date_of_birth'] ?? ''); ?>"
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold mb-3">
                            <label for="nurse_gender" class="block font-bold text-gray-700 mb-1">Gender *</label>
                            <select name="gender" id="nurse_gender" required class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold mb-3">
                                <option value="">Select gender</option>
                                <?php foreach (['Male', 'Female'] as $genderOption): ?>
                                    <option value="<?= $genderOption; ?>" <?= ($selectedResidentData['gender'] ?? '') === $genderOption ? 'selected' : ''; ?>><?= $genderOption; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="nurse_blood_type" class="flex items-center justify-between font-bold text-gray-700 mb-1">
                                <span>Blood Type (optional)</span>
                                <span id="nurse_blood_type_locked" class="text-[10px] text-gray-400 font-normal <?= empty($selectedResidentData['bloodType']) ? 'hidden' : '' ?>">Locked</span>
                            </label>
                            <select name="blood_type" id="nurse_blood_type"
                                <?= !empty($selectedResidentData['bloodType']) ? 'disabled' : '' ?>
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold">
                                <option value="" <?= ($selectedResidentData['bloodType'] ?? '') === '' ? 'selected' : '' ?>>Not recorded</option>
                                <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $bt): ?>
                                    <option value="<?= $bt ?>" <?= ($selectedResidentData['bloodType'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input id="nurse_blood_type_display" type="text" readonly
                                value="<?= esc($selectedResidentData['bloodType'] ?? '') ?>"
                                aria-label="Recorded blood type"
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold bg-slate-100 <?= empty($selectedResidentData['bloodType']) ? 'hidden' : '' ?>">
                        </div>
                        <div>
                            <label for="nurse_philhealth_id">
                                <span>PhilHealth ID (optional)</span>
                                <span class="text-[10px] text-gray-400 font-normal">Locked</span>
                            </label>
                            <input name="philhealth_id" id="nurse_philhealth_id"
                                value="<?= esc($selectedResidentData['philhealthNo'] ?? '') ?>"
                                placeholder="PH-12-345678901-2"
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label for="nurse_contact_number"><span>Contact Phone (optional)</span>
                                <span class="text-[10px] text-gray-400 font-normal">Locked</span>
                            </label>
                            <input name="contact_number" id="nurse_contact_number"
                                value="<?= esc($selectedResidentData['contactNo'] ?? '') ?>" placeholder="0917 123 4567"
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono">
                        </div>
                    </div>

<label class="block font-bold mt-3">Barangay *<input name="barangay" class="w-full p-2.5 border rounded-xl" required></label><label class="block font-bold mt-3">Complete address (optional)<input name="address" class="w-full p-2.5 border rounded-xl"></label>
                    <?php $nurseIntakeIdentity .= ob_get_clean(); ?>
                    <div id="nurse_rhu_intake"><?= rhuIntakeFields(['middle_name', 'philhealth_number'], $nurseIntakeIdentity) ?></div>
                    <p id="nurse_returning_notice" class="hidden text-sm text-teal-800">Resident details are saved. Enter current measurements and visit information below.</p>
                    <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <p class="font-extrabold text-slate-900 text-xs">Current Visit Vital Signs</p>
                            <span
                                class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">Editable
                                by Nurse</span>
                        </div>
                        <p class="text-xs text-slate-500">Record available measurements taken today. All vitals are optional; leave unmeasured values blank.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Height (cm)</label>
                                <input name="height" id="nurse_height"
                                    value="<?= esc($selectedResidentData['height'] ?? '') ?>" placeholder="e.g. 165"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold text-gray-800">
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Weight (kg)</label>
                                <input name="weight" id="nurse_weight"
                                    value="<?= esc($selectedResidentData['weight'] ?? '') ?>" placeholder="e.g. 62"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold text-gray-800">
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Current Blood Pressure</label>
                                <input name="blood_pressure" id="nurse_blood_pressure"
                                    value="<?= esc($selectedResidentData['bloodPressure'] ?? '') ?>" placeholder="120/80"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono font-bold text-gray-800">
                            </div>
                            <div>
                                <label for="nurse_visit_temperature" class="block font-bold text-gray-700 mb-1">Temperature (?C)</label>
                                <input name="rhu_visit[temperature]" id="nurse_visit_temperature" type="number" min="0" step="any"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold text-gray-800">
                            </div>
                            <div>
                                <label for="nurse_visit_rr" class="block font-bold text-gray-700 mb-1">Respiratory rate (/min)</label>
                                <input name="rhu_visit[rr]" id="nurse_visit_rr" type="number" min="0" step="any"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold text-gray-800">
                            </div>
                            <div>
                                <label for="nurse_visit_pr" class="block font-bold text-gray-700 mb-1">Pulse rate (bpm)</label>
                                <input name="rhu_visit[pr]" id="nurse_visit_pr" type="number" min="0" step="any"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold text-gray-800">
                            </div>
                        </div>
                    </div>

                    <div>
                        
                        <label for="nurse_allergies" class="flex items-center justify-between font-bold text-gray-700 mb-1">
                            <span>Known Drug & Food Allergies (optional)</span>
                            <span
                                id="nurse_allergies_status" class="text-[10px] font-bold text-gray-500"><?= !empty($selectedResidentData['allergies']) ? 'Locked' : 'Editable by Nurse' ?></span>
                        </label>
                        <textarea name="allergies" id="nurse_allergies" rows="2"
                            <?= !empty($selectedResidentData['allergies']) ? 'readonly' : '' ?>
                            class="w-full p-2.5 border border-gray-300 rounded-xl text-xs resize-none focus:border-blue-500 outline-none"
                            placeholder="e.g. Penicillin, Sulfur, Seafood, Latex (Leave blank if unknown; write 'None' only if confirmed)"><?= esc($selectedResidentData['allergies'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label for="nurse_medical_history">
                            <span>Pre-existing Medical History & Chronic Conditions (optional)</span>
                            <span
                                class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">Editable
                                by Nurse</span>
                        </label>
                        <textarea name="medical_history" id="nurse_medical_history" rows="2"
                            class="w-full p-2.5 border border-gray-300 rounded-xl text-xs resize-none focus:border-blue-500 outline-none"
                            placeholder="e.g. Essential Hypertension, Type 2 Diabetes Mellitus, Asthma, Previous Appendectomy (2021)"><?= esc($selectedResidentData['medicalHistory'] ?? '') ?></textarea>
                    </div>

                    <label class="block font-bold">Chief complaint<textarea name="chief_complaint" class="w-full border rounded-xl p-3"></textarea></label>
                    <label class="block font-bold">Treatment / services rendered<textarea name="treatment" class="w-full border rounded-xl p-3"></textarea></label>
                    <label class="block font-bold">Diagnosis<input name="diagnosis" class="w-full border rounded-xl p-3"></label>
                    <label class="block font-bold">Remarks<textarea name="visit_remarks" class="w-full border rounded-xl p-3"></textarea></label>
                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('patients')); ?>"
                            class="flex-1 py-3 border border-gray-300 rounded-xl text-xs font-bold text-gray-700 hover:bg-gray-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-3 bg-teal-700 text-white rounded-xl text-xs font-extrabold hover:bg-teal-800 shadow-md transition-all">Save
                            Complete Resident Medical Record</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            const residentProfilesMap = <?= json_encode($patientProfilesMap, JSON_UNESCAPED_UNICODE) ?> || {};

            function onNurseResidentSelectChange(target) {
                let selectEl = target;
                if (typeof target === 'string') {
                    selectEl = document.getElementById(target);
                }
                if (!selectEl) return;

                const rid = parseInt(selectEl.value || 0, 10);
                const profile = residentProfilesMap[rid];
                const existing = rid > 0;
                ['nurse_new_resident_fields', 'nurse_identity_details', 'nurse_rhu_intake'].forEach(id => {
                    const section = document.getElementById(id);
                    if (!section) return;
                    section.classList.toggle('hidden', existing);
                    section.querySelectorAll('input, select').forEach(input => { input.disabled = existing; });
                });
                document.getElementById('nurse_returning_notice')?.classList.toggle('hidden', !existing);
                ['nurse_first_name','nurse_last_name','nurse_date_of_birth','nurse_gender'].forEach(id => {
                    const input = document.getElementById(id);
                    if (input) input.required = !existing;
                });

                const fnInput = document.getElementById('nurse_first_name');
                const lnInput = document.getElementById('nurse_last_name');
                const genderSelect = document.getElementById('nurse_gender');
                const birthDateInput = document.getElementById('nurse_date_of_birth');
                const btSelect = document.getElementById('nurse_blood_type');
                const btDisplay = document.getElementById('nurse_blood_type_display');
                const phiInput = document.getElementById('nurse_philhealth_id');
                const cnInput = document.getElementById('nurse_contact_number');

                const htInput = document.getElementById('nurse_height');
                const wtInput = document.getElementById('nurse_weight');
                const bpInput = document.getElementById('nurse_blood_pressure');
                const algInput = document.getElementById('nurse_allergies');
                const mhInput = document.getElementById('nurse_medical_history');

                const lockedNotice = document.getElementById('nurse_locked_fields_notice');

                if (profile && rid > 0) {
                    if (genderSelect) genderSelect.value = profile.gender || '';
                    if (birthDateInput) birthDateInput.value = (profile.date_of_birth || '').slice(0, 10);
                    if (fnInput) { fnInput.value = profile.first_name || ''; fnInput.readOnly = true; fnInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }
                    if (lnInput) { lnInput.value = profile.last_name || ''; lnInput.readOnly = true; lnInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }

                    if (btSelect) {
                        const bloodType = (profile.bloodType || '').trim();
                        if (bloodType && !Array.from(btSelect.options).some(option => option.value === bloodType)) {
                            btSelect.add(new Option(bloodType, bloodType));
                        }
                        btSelect.value = bloodType;
                        btSelect.disabled = existing || bloodType !== '';
                        btSelect.classList.toggle('hidden', btSelect.disabled);
                        if (btDisplay) {
                            btDisplay.value = bloodType;
                            btDisplay.classList.toggle('hidden', !btSelect.disabled);
                        }
                        btSelect.classList.toggle('bg-slate-100', btSelect.disabled);
                        btSelect.classList.toggle('cursor-not-allowed', btSelect.disabled);
                    }
                    document.getElementById('nurse_blood_type_locked')?.classList.toggle('hidden', !btSelect?.disabled);
                    if (phiInput) { phiInput.value = profile.philhealthNo || ''; phiInput.readOnly = true; phiInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }
                    if (cnInput) { cnInput.value = profile.contactNo || ''; cnInput.readOnly = true; cnInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }

                    if (htInput) htInput.value = '';
                    if (wtInput) wtInput.value = '';
                    if (bpInput) bpInput.value = '';
                    if (algInput) {
                        algInput.value = profile.allergies || '';
                        algInput.readOnly = algInput.value.trim() !== '';
                        algInput.classList.toggle('bg-slate-100', algInput.readOnly);
                        const status = document.getElementById('nurse_allergies_status');
                        if (status) status.textContent = algInput.readOnly ? 'Locked' : 'Editable by Nurse';
                    }
                    if (mhInput) mhInput.value = profile.medicalHistory || '';

                    if (lockedNotice) lockedNotice.classList.remove('hidden');
                } else {
                    if (genderSelect) genderSelect.value = '';
                    if (birthDateInput) birthDateInput.value = '';
                    if (fnInput) { fnInput.value = ''; fnInput.readOnly = false; fnInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }
                    if (lnInput) { lnInput.value = ''; lnInput.readOnly = false; lnInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }

                    if (btSelect) { btSelect.value = ''; btSelect.disabled = false; btSelect.classList.remove('hidden', 'bg-slate-100', 'cursor-not-allowed'); }
                    if (btDisplay) { btDisplay.value = ''; btDisplay.classList.add('hidden'); }
                    document.getElementById('nurse_blood_type_locked')?.classList.add('hidden');
                    if (phiInput) { phiInput.value = ''; phiInput.readOnly = false; phiInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }
                    if (cnInput) { cnInput.value = ''; cnInput.readOnly = false; cnInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }

                    if (htInput) htInput.value = '';
                    if (wtInput) wtInput.value = '';
                    if (bpInput) bpInput.value = '';
                    if (algInput) {
                        algInput.value = '';
                        algInput.readOnly = false;
                        algInput.classList.remove('bg-slate-100');
                        const status = document.getElementById('nurse_allergies_status');
                        if (status) status.textContent = 'Editable by Nurse';
                    }
                    if (mhInput) mhInput.value = '';

                    if (lockedNotice) lockedNotice.classList.add('hidden');
                }
            }

            // Execute auto-fill immediately if a resident is selected or on page load
            (function () {
                const selectEl = document.getElementById('nurse_resident_select');
                if (selectEl) {
                    onNurseResidentSelectChange(selectEl);
                }
            })();

            (function () {
                const form = document.querySelector('[data-nurse-patient-record-form]');
                if (!form) return;

                const setText = (root, selector, value, fallback) => {
                    const el = root?.querySelector(selector);
                    if (el) el.textContent = value || fallback;
                };

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const submitButton = form.querySelector('button[type="submit"]');
                    const originalText = submitButton?.textContent || '';
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.textContent = 'Saving...';
                    }

                    try {
                        const saveUrl = new URL(window.location.href);
                        saveUrl.search = '';
                        saveUrl.searchParams.set('tab', 'patients');
                        saveUrl.searchParams.set('ajax', '1');

                        const response = await fetch(saveUrl.toString(), {
                            method: 'POST',
                            body: new FormData(form),
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            credentials: 'same-origin',
                            cache: 'no-store'
                        });
                        const responseText = await response.text();
                        let payload = null;
                        try {
                            payload = JSON.parse(responseText);
                        } catch (parseError) {
                            const preview = responseText.trim().slice(0, 120).replace(/\s+/g, ' ');
                            const redirectHint = response.redirected ? ' The request was redirected, so your Nurse session may have expired.' : '';
                            throw new Error(`The save response was not JSON (HTTP ${response.status}).${redirectHint} Server replied: ${preview || 'empty response'}`);
                        }
                        if (!payload.success) throw new Error(payload.error || 'Unable to save resident medical record.');

                        const resident = payload.resident || {};
                        const rid = String(resident.id || form.querySelector('[name="resident_id"]')?.value || '');
                        if (residentProfilesMap[rid]) {
                            if (resident.gender) residentProfilesMap[rid].gender = resident.gender;
                            residentProfilesMap[rid].bloodType = resident.bloodType || '';
                            residentProfilesMap[rid].philhealthNo = resident.philhealthNo || '';
                            residentProfilesMap[rid].contactNo = resident.contactNo || '';
                            residentProfilesMap[rid].height = resident.height || '';
                            residentProfilesMap[rid].weight = resident.weight || '';
                            residentProfilesMap[rid].bloodPressure = resident.bloodPressure || '';
                            residentProfilesMap[rid].allergies = resident.allergies || '';
                            residentProfilesMap[rid].medicalHistory = resident.medicalHistory || '';
                        }

                        const card = document.querySelector(`[data-patient-card="${CSS.escape(rid)}"]`);
                        if (card) {
                            if (resident.gender) {
                                window.location.href = 'NurseDashboard.php?tab=patients';
                                return;
                            }
                            setText(card, '[data-patient-blood-type]', resident.bloodType, 'Not recorded');
                            setText(card, '[data-patient-allergies]', resident.allergies, 'None Reported');
                            setText(card, '[data-patient-medical-history]', resident.medicalHistory, 'No chronic conditions recorded');
                            setText(card, '[data-patient-height]', resident.height, '-');
                            setText(card, '[data-patient-weight]', resident.weight, '-');
                            setText(card, '[data-patient-bp]', resident.bloodPressure, '-');
                        }

                        const flash = document.createElement('div');
                        flash.className = 'fixed right-4 top-4 z-[70] rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-extrabold text-emerald-900 shadow-xl';
                        flash.textContent = payload.message || 'Resident medical profile saved successfully.';
                        document.body.appendChild(flash);
                        window.setTimeout(() => flash.remove(), 2600);
                        document.querySelector('.fixed.inset-0.bg-slate-900\\/60')?.remove();
                    } catch (error) {
                        alert(error.message || 'Unable to save resident medical record.');
                    } finally {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.textContent = originalText;
                        }
                    }
                });
            })();
        </script>
    <?php endif; ?>
    <?= portalRenderNotificationPanel(); ?>


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

        (function () {
            document.querySelectorAll('[data-resident-combobox]').forEach((combo) => {
                const button = combo.querySelector('[data-resident-combobox-button]');
                const label = combo.querySelector('[data-resident-combobox-label]');
                const valueInput = combo.querySelector('[data-resident-combobox-value]');
                const options = Array.from(combo.querySelectorAll('[data-resident-combobox-option]'));

                const setComboOpen = (open) => {
                    combo.classList.toggle('is-open', open);
                    button?.setAttribute('aria-expanded', String(open));
                };

                button?.addEventListener('click', () => {
                    const willOpen = !combo.classList.contains('is-open');
                    document.querySelectorAll('[data-resident-combobox].is-open').forEach((openCombo) => {
                        if (openCombo !== combo) {
                            openCombo.classList.remove('is-open');
                            openCombo.querySelector('[data-resident-combobox-button]')?.setAttribute('aria-expanded', 'false');
                        }
                    });
                    setComboOpen(willOpen);
                });

                options.forEach((option) => {
                    option.addEventListener('click', () => {
                        const selectedValue = option.getAttribute('data-value') || '';
                        if (valueInput) valueInput.value = selectedValue;
                        if (label) label.textContent = option.textContent.trim();
                        options.forEach((item) => item.classList.toggle('is-selected', item === option));
                        setComboOpen(false);
                        button?.focus();
                    });
                });

                combo.closest('form')?.addEventListener('submit', (event) => {
                    if (!valueInput?.value) {
                        event.preventDefault();
                        setComboOpen(true);
                        button?.focus();
                        alert('Select a resident patient before saving triage vitals.');
                    }
                });
            });

            document.addEventListener('click', (event) => {
                document.querySelectorAll('[data-resident-combobox].is-open').forEach((combo) => {
                    if (!combo.contains(event.target)) {
                        combo.classList.remove('is-open');
                        combo.querySelector('[data-resident-combobox-button]')?.setAttribute('aria-expanded', 'false');
                    }
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                document.querySelectorAll('[data-resident-combobox].is-open').forEach((combo) => {
                    combo.classList.remove('is-open');
                    combo.querySelector('[data-resident-combobox-button]')?.setAttribute('aria-expanded', 'false');
                });
            });
        })();

        (function () {
            const initTriageWorkbenches = (root = document) => {
                root.querySelectorAll('[data-triage-workbench]').forEach((workbench) => {
                    if (workbench.dataset.triageReady === '1') return;
                    workbench.dataset.triageReady = '1';
                const tabs = Array.from(workbench.querySelectorAll('[data-triage-tab]'));
                const panels = Array.from(workbench.querySelectorAll('.triage-detail-panel'));
                const search = workbench.querySelector('[data-triage-search]');
                const empty = workbench.querySelector('[data-triage-empty]');

                const activatePanel = (targetId) => {
                    tabs.forEach((tab) => {
                        const active = tab.dataset.target === targetId;
                        tab.classList.toggle('is-active', active);
                        tab.setAttribute('aria-selected', String(active));
                    });
                    panels.forEach((panel) => panel.classList.toggle('is-active', panel.id === targetId));
                };

                tabs.forEach((tab) => {
                    tab.addEventListener('click', () => activatePanel(tab.dataset.target));
                });

                search?.addEventListener('input', () => {
                    const term = search.value.trim().toLowerCase();
                    let firstVisible = null;
                    let visibleCount = 0;

                    tabs.forEach((tab) => {
                        const matches = !term || (tab.dataset.search || '').includes(term);
                        tab.classList.toggle('hidden', !matches);
                        if (matches) {
                            visibleCount += 1;
                            firstVisible = firstVisible || tab;
                        }
                    });

                    empty?.classList.toggle('is-visible', visibleCount === 0);
                    if (firstVisible && !firstVisible.classList.contains('is-active')) {
                        activatePanel(firstVisible.dataset.target);
                    } else if (!firstVisible) {
                        tabs.forEach((tab) => {
                            tab.classList.remove('is-active');
                            tab.setAttribute('aria-selected', 'false');
                        });
                        panels.forEach((panel) => panel.classList.remove('is-active'));
                    }
                });

                workbench.querySelectorAll('[data-consult-date-select]').forEach((select) => {
                    select.addEventListener('change', () => {
                        const panel = select.closest('.triage-detail-panel');
                        if (!panel) return;
                        panel.querySelectorAll('[data-consult-date-record]').forEach((record) => {
                            record.classList.toggle('hidden', record.id !== select.value);
                        });
                    });
                });
            });
            };

            window.initNurseTriageWorkbenches = initTriageWorkbenches;
            initTriageWorkbenches();
        })();

        (function () {
            const panel = document.querySelector('[data-opd-triage-live]');
            if (!panel) return;

            const source = panel.dataset.liveSrc || 'NurseDashboard.php?tab=opd';
            let lastMarkup = panel.innerHTML.trim();
            let controller = null;

            const refreshOpdTriagePanel = async () => {
                if (document.hidden) return;
                try {
                    if (controller) controller.abort();
                    controller = new AbortController();
                    const url = new URL(source, window.location.href);
                    url.searchParams.set('_live', Date.now().toString());
                    const response = await fetch(url.toString(), {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: controller.signal
                    });
                    if (!response.ok) return;
                    const html = await response.text ;
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const freshPanel = doc.querySelector('[data-opd-triage-live]');
                    if (!freshPanel) return;
                    const freshMarkup = freshPanel.innerHTML.trim();
                    if (freshMarkup === lastMarkup) return;
                    lastMarkup = freshMarkup;
                    panel.innerHTML = freshPanel.innerHTML;
                    window.initNurseTriageWorkbenches?.(panel);
                    if (window.lucide?.createIcons) window.lucide.createIcons();
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.warn('OPD triage live refresh failed', error);
                    }
                }
            };

            window.setInterval(refreshOpdTriagePanel, 4000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) refreshOpdTriagePanel();
            });
        })();

        (function () {
            const diagnosisInputs = Array.from(document.querySelectorAll('[data-icd-diagnosis-input]'));
            if (!diagnosisInputs.length) return;

            const apiBase = 'https://clinicaltables.nlm.nih.gov/api/icd10cm/v3/search';
            const debounceTimers = new WeakMap();
            const controllers = new WeakMap();
            const localIcdMatches = [
                { pattern: /\b(fever|pyrexia)\b/i, code: 'R50.9', label: 'Fever, unspecified' },
                { pattern: /\b(cough)\b/i, code: 'R05.9', label: 'Cough, unspecified' },
                { pattern: /\b(cold|common cold|nasopharyngitis)\b/i, code: 'J00', label: 'Acute nasopharyngitis [common cold]' },
                { pattern: /\b(upper respiratory|urti|uri|respiratory tract infection)\b/i, code: 'J06.9', label: 'Acute upper respiratory infection, unspecified' },
                { pattern: /\b(influenza|flu)\b/i, code: 'J11.1', label: 'Influenza due to unidentified influenza virus with other respiratory manifestations' },
                { pattern: /\b(headache)\b/i, code: 'R51.9', label: 'Headache, unspecified' },
                { pattern: /\b(diarrhea|diarrhoea|loose bowel)\b/i, code: 'R19.7', label: 'Diarrhea, unspecified' },
                { pattern: /\b(vomit|vomiting|nausea)\b/i, code: 'R11.2', label: 'Nausea with vomiting, unspecified' },
                { pattern: /\b(abdominal pain|stomach pain)\b/i, code: 'R10.9', label: 'Unspecified abdominal pain' },
                { pattern: /\b(hypertension|high blood pressure)\b/i, code: 'I10', label: 'Essential (primary) hypertension' },
                { pattern: /\b(diabetes|dm)\b/i, code: 'E11.9', label: 'Type 2 diabetes mellitus without complications' },
                { pattern: /\b(asthma)\b/i, code: 'J45.909', label: 'Unspecified asthma, uncomplicated' },
                { pattern: /\b(uti|urinary tract infection)\b/i, code: 'N39.0', label: 'Urinary tract infection, site not specified' },
                { pattern: /\b(dengue)\b/i, code: 'A90', label: 'Dengue fever [classical dengue]' },
                { pattern: /\b(wound|laceration)\b/i, code: 'T14.8', label: 'Other injury of unspecified body region' }
            ];

            const closeSuggestions = (box) => {
                if (!box) return;
                box.classList.remove('is-open');
                box.innerHTML = '';
            };

            const getSuggestionBox = (input) => input.closest('.icd-lookup-wrap')?.querySelector('[data-icd-suggestions]');
            const getCodeInput = (input) => document.getElementById(input.dataset.icdTarget || '');
            const normalizeDiagnosisTerm = (term) => {
                const clean = term.trim();
                return clean.toLowerCase() === 'flue' ? 'flu influenza' : clean;
            };
            const applyIcdCode = (input, code, force = false) => {
                const codeInput = getCodeInput(input);
                if (!codeInput || !code) return;
                if (force || codeInput.value.trim() === '' || codeInput.dataset.icdAutoFilled === '1') {
                    codeInput.value = code;
                    codeInput.dataset.icdAutoFilled = '1';
                }
            };
            const findLocalIcdMatch = (term) => localIcdMatches.find((item) => item.pattern.test(term));
            const selectIcdSuggestion = (input, result) => {
                const box = getSuggestionBox(input);
                const codeInput = getCodeInput(input);
                const code = String(result?.code || '').trim();
                const label = String(result?.label || '').trim();
                if (!codeInput || code === '' || label === '') return;

                input.value = label;
                input.dataset.icdSelectedLabel = label;
                codeInput.value = code;
                codeInput.dataset.icdAutoFilled = '1';
                input.dispatchEvent(new Event('change', { bubbles: true }));
                codeInput.dispatchEvent(new Event('change', { bubbles: true }));
                closeSuggestions(box);
            };

            const renderSuggestions = (input, results) => {
                const box = getSuggestionBox(input);
                const codeInput = getCodeInput(input);
                if (!box || !codeInput) return;

                box.innerHTML = '';
                if (!results.length) {
                    closeSuggestions(box);
                    return;
                }

                results.forEach(({ code, label }) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'icd-suggestion';
                    option.setAttribute('aria-label', `Use ICD-10 ${code}: ${label}`);
                    const codeEl = document.createElement('strong');
                    const labelEl = document.createElement('span');
                    codeEl.textContent = code;
                    labelEl.textContent = label;
                    option.append(codeEl, labelEl);
                    option.addEventListener('pointerdown', (event) => {
                        event.preventDefault();
                        selectIcdSuggestion(input, { code, label });
                    });
                    option.addEventListener('click', () => {
                        selectIcdSuggestion(input, { code, label });
                    });
                    option.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            selectIcdSuggestion(input, { code, label });
                        }
                    });
                    box.appendChild(option);
                });
                box.classList.add('is-open');
            };

            const lookupIcd10 = async (input) => {
                const term = input.value.trim();
                const lookupTerm = normalizeDiagnosisTerm(term);
                const box = getSuggestionBox(input);
                if (term.length < 3) {
                    closeSuggestions(box);
                    return;
                }

                controllers.get(input)?.abort();
                const controller = new AbortController();
                controllers.set(input, controller);

                try {
                    const url = `${apiBase}?sf=code,name&df=code,name&maxList=8&terms=${encodeURIComponent(lookupTerm)}`;
                    const response = await fetch(url, { signal: controller.signal });
                    if (!response.ok) throw new Error(`ICD API returned ${response.status}`);
                    const [, codes = [], , details = []] = await response.json();
                    const results = details.map((item, index) => ({
                        code: item?.[0] || codes[index] || '',
                        label: item?.[1] || ''
                    })).filter((item) => item.code && item.label);
                    if (results[0]) {
                        applyIcdCode(input, results[0].code);
                    }
                    renderSuggestions(input, results);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error('Error communicating with ICD-10 terminology API:', error);
                    }
                }
            };

            diagnosisInputs.forEach((input) => {
                input.setAttribute('autocomplete', 'off');
                input.addEventListener('input', () => {
                    delete input.dataset.icdSelectedLabel;
                    const localMatch = findLocalIcdMatch(input.value.trim());
                    if (localMatch) {
                        applyIcdCode(input, localMatch.code, true);
                        renderSuggestions(input, [localMatch]);
                    }
                    clearTimeout(debounceTimers.get(input));
                    debounceTimers.set(input, setTimeout(() => lookupIcd10(input), 300));
                });
                input.addEventListener('blur', () => {
                    setTimeout(() => closeSuggestions(getSuggestionBox(input)), 180);
                });
            });
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

<script src="assets/js/staff-record-scroll.js?v=<?= (int) filemtime(__DIR__ . '/staff-record-scroll.js') ?>" defer></script>
<script src="assets/js/staff-resident-record-groups.js?v=<?= (int) filemtime(__DIR__ . '/staff-resident-record-groups.js') ?>" defer></script>
<?= $pdo instanceof PDO ? rhuIntakeSavedData($pdo) : '' ?>
</body>

</html>
