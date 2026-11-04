<?php
require_once dirname(__DIR__) . '/includes/inventory-control.php';

function adminDownloadSqlBackup(PDO $pdo): never
{
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rhu-backup-' . date('Y-m-d-His') . '.sql"');
    echo "-- RedPulse RHU database backup\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
        echo "\nDROP TABLE IF EXISTS {$quoted};\n", $create[1], ";\n";
        $rows = $pdo->query("SELECT * FROM {$quoted}");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = implode(',', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row)));
            $values = implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row)));
            echo "INSERT INTO {$quoted} ({$columns}) VALUES ({$values});\n";
        }
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

function adminDownloadCertificatePdf(PDO $pdo, int $certificateId): never
{
    $stmt = $pdo->prepare("SELECT c.id, c.certificate_number, NULL AS generated_html, c.status AS validity_status, c.certificate_type AS certificate_type_name FROM certificates c WHERE c.id=?");
    $stmt->execute([$certificateId]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$certificate) {
        http_response_code(404);
        exit('Certificate not found.');
    }
    $certificateHtml = trim((string)($certificate['generated_html'] ?? ''));
    if ($certificateHtml === '' && function_exists('portalGenerateCertificateHtml')) {
        $certificateHtml = portalGenerateCertificateHtml($pdo, $certificateId, true);
    }
    $fileName = 'certificate-' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$certificate['certificate_number']) . '.html';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars((string)$certificate['certificate_type_name'], ENT_QUOTES, 'UTF-8') . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#e5e7eb;color:#111;font-family:Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:5;display:flex;justify-content:center;gap:10px;padding:14px;background:#0f766e}.toolbar button{border:1px solid rgba(255,255,255,.5);border-radius:8px;background:#fff;padding:9px 16px;color:#0f766e;font:700 13px Arial;cursor:pointer}'
        . '.official-certificate-template{position:relative;overflow:hidden;margin:24px auto;width:min(100%,760px);min-height:1040px;background:#fff;padding:58px 68px 44px;color:#050505;font-family:Arial,Helvetica,sans-serif;line-height:1.45;box-shadow:0 18px 50px rgba(15,23,42,.18)}.cert-header{position:relative;z-index:1;display:grid;grid-template-columns:112px 1fr 112px;align-items:center;text-align:center;margin-bottom:10px}.cert-seal{width:96px;height:96px;object-fit:contain;justify-self:center}.cert-watermark{position:absolute;z-index:0;left:50%;top:285px;width:560px;height:560px;transform:translateX(-50%);object-fit:contain;opacity:.1;pointer-events:none}.cert-header-copy{font-size:11px;line-height:1.2}.cert-header-copy p{margin:0}.cert-republic{font-family:Georgia,"Times New Roman",serif;font-style:italic;font-size:13px}.cert-rule{position:relative;z-index:1;border-top:2px solid #111;border-bottom:1px solid #111;height:4px;margin:6px 0 42px}.official-certificate-template h1,.official-certificate-template h2,.official-certificate-template h3{position:relative;z-index:1;margin:5px 0;text-align:center;font-weight:900;text-transform:uppercase}.official-certificate-template h1{font-size:18px}.official-certificate-template h2{font-size:21px;font-style:italic}.official-certificate-template h3{font-size:28px;margin-bottom:2px}.cert-no{position:relative;z-index:1;text-align:center;font-family:"Courier New",monospace;font-size:10px;font-weight:700;color:#334155;margin:0 0 44px}.cert-body{position:relative;z-index:1;margin:0;font-size:12px;line-height:1.65;text-align:justify}.cert-body p{margin:0 0 18px;text-indent:34px}.cert-body .cert-greeting{text-indent:0;margin-bottom:26px;text-align:left}.cert-dates{display:flex;gap:34px;margin-top:6px;font-size:10px;text-align:left}.cert-signatures{position:relative;z-index:1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:52px;margin-top:118px;text-align:center}.cert-signatures>div{min-height:104px;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;font-size:12px}.cert-signatures strong{border-top:1px solid #111;min-width:250px;padding-top:5px;font-weight:900;text-transform:uppercase}.certificate-signature-image{display:block;width:170px;height:58px;margin:0 auto -5px;object-fit:contain;object-position:center bottom;mix-blend-mode:multiply}.signature-line{height:58px;margin-bottom:-5px;width:170px}.official-certificate-template small,.cert-footer{display:block;color:#111;font-size:10px}.cert-footer{position:absolute;z-index:1;left:68px;right:68px;bottom:40px;display:flex;justify-content:space-between;border-top:1px solid #64748b;padding-top:6px;font-family:"Courier New",monospace;color:#0f172a}@media print{@page{size:A4 portrait;margin:0}body{background:#fff}.toolbar{display:none}.official-certificate-template{width:210mm;min-height:297mm;margin:0;box-shadow:none}}'
        . '</style></head><body><div class="toolbar"><button onclick="window.print()">Save as PDF</button></div>' . $certificateHtml . '</body></html>';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($html));
    echo $html;
    exit;
}

function adminExtendedColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (function_exists('rhuColumnExists')) {
        return rhuColumnExists($pdo, $table, $column);
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function adminExtendedTableExists(PDO $pdo, string $table): bool
{
    if (function_exists('rhuTableExists')) {
        return rhuTableExists($pdo, $table);
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute(['table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function adminExtendedFetchRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('adminExtendedFetchRows: ' . $e->getMessage());
        return [];
    }
}

function adminExtendedFetchCount(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('adminExtendedFetchCount: ' . $e->getMessage());
        return 0;
    }
}

function adminExtendedGenerateFhsisReportData(PDO $pdo, int $year, int $month): array
{
    $month = max(1, min(12, $month));
    $periodStart = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-1') ?: new DateTimeImmutable('first day of this month');
    $periodEnd = $periodStart->modify('first day of next month');
    $periodParams = [
        'start' => $periodStart->format('Y-m-d'),
        'end' => $periodEnd->format('Y-m-d'),
    ];
    $livingWhere = function_exists('rhuLivingResidentWhereSql') ? rhuLivingResidentWhereSql('r') : "COALESCE(r.is_active, 1) = 1 AND LOWER(COALESCE(r.status, 'Active')) NOT IN ('deceased', 'dead', 'inactive')";
    $data = [
        'report_name' => 'Field Health Services Information System',
        'report_period' => $periodStart->format('F Y'),
        'period_start' => $periodParams['start'],
        'period_end' => $periodEnd->modify('-1 day')->format('Y-m-d'),
        'generated_at' => date('Y-m-d H:i:s'),
        'summary' => [],
        'barangay_breakdown' => [],
        'sections' => [],
    ];

    if (adminExtendedTableExists($pdo, 'residents')) {
        $data['summary']['registered_residents'] = adminExtendedFetchCount($pdo, "SELECT COUNT(*) FROM residents r WHERE {$livingWhere}");
        $data['barangay_breakdown']['residents'] = adminExtendedFetchRows($pdo, "
            SELECT COALESCE(b.name, 'Unassigned') AS barangay, COUNT(*) AS total_residents,
                   SUM(CASE WHEN r.sex LIKE 'Female%' THEN 1 ELSE 0 END) AS female_residents,
                   SUM(CASE WHEN r.sex LIKE 'Male%' THEN 1 ELSE 0 END) AS male_residents
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE {$livingWhere}
            GROUP BY COALESCE(b.name, 'Unassigned')
            ORDER BY barangay
        ");
    }

    if (adminExtendedTableExists($pdo, 'consultations')) {
        $data['sections']['consultations'] = adminExtendedFetchRows($pdo, "
            SELECT c.id, c.consultation_date, CONCAT(r.first_name, ' ', r.last_name) AS patient_name,
                   TIMESTAMPDIFF(YEAR, r.birthdate, c.consultation_date) AS age, r.sex,
                   COALESCE(b.name, 'Unassigned') AS barangay, c.chief_complaint, c.diagnosis,
                   c.treatment, c.medications_prescribed, c.follow_up_date,
                   COALESCE(c.consultation_status, 'Recorded') AS status,
                   COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'Unassigned') AS health_worker
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_workers hw ON hw.id = c.health_worker_id
            WHERE c.consultation_date >= :start AND c.consultation_date < :end
            ORDER BY c.consultation_date, patient_name, c.id
        ", $periodParams);
        $data['summary']['consultations'] = count($data['sections']['consultations']);
        $data['barangay_breakdown']['consultations'] = adminExtendedFetchRows($pdo, "
            SELECT COALESCE(b.name, 'Unassigned') AS barangay, COUNT(*) AS consultations
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE c.consultation_date >= :start AND c.consultation_date < :end
            GROUP BY COALESCE(b.name, 'Unassigned')
            ORDER BY barangay
        ", $periodParams);
        $data['sections']['laboratory_related_consultations'] = adminExtendedFetchRows($pdo, "
            SELECT c.id, c.consultation_date, CONCAT(r.first_name, ' ', r.last_name) AS patient_name,
                   COALESCE(b.name, 'Unassigned') AS barangay, c.chief_complaint AS test_or_request,
                   c.diagnosis AS result_status, c.remarks
            FROM consultations c
            JOIN residents r ON r.id = c.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE c.consultation_date >= :start AND c.consultation_date < :end
              AND LOWER(CONCAT_WS(' ', COALESCE(c.chief_complaint, ''), COALESCE(c.remarks, ''))) REGEXP 'laboratory|lab test|diagnostic|rapid|rdt|blood work'
            ORDER BY c.consultation_date, patient_name, c.id
        ", $periodParams);
        $data['summary']['laboratory_related_consultations'] = count($data['sections']['laboratory_related_consultations']);
    }

    if (adminExtendedTableExists($pdo, 'vaccination_records')) {
        $data['sections']['immunizations'] = adminExtendedFetchRows($pdo, "
            SELECT v.id, v.vaccination_date, CONCAT(r.first_name, ' ', r.last_name) AS patient_name,
                   TIMESTAMPDIFF(YEAR, r.birthdate, v.vaccination_date) AS age, r.sex,
                   COALESCE(b.name, 'Unassigned') AS barangay, v.vaccine_name, v.batch_number,
                   v.next_dose_date, COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'Unassigned') AS provider
            FROM vaccination_records v
            JOIN residents r ON r.id = v.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_workers hw ON hw.id = v.health_worker_id
            WHERE v.vaccination_date >= :start AND v.vaccination_date < :end
            ORDER BY v.vaccination_date, patient_name, v.id
        ", $periodParams);
        $data['summary']['immunizations'] = count($data['sections']['immunizations']);
        $data['summary']['immunizations_by_vaccine'] = adminExtendedFetchRows($pdo, "
            SELECT vaccine_name, COUNT(*) AS total
            FROM vaccination_records
            WHERE vaccination_date >= :start AND vaccination_date < :end
            GROUP BY vaccine_name
            ORDER BY vaccine_name
        ", $periodParams);
    }

    if (adminExtendedTableExists($pdo, 'pregnancy_records')) {
        $dateColumn = adminExtendedColumnExists($pdo, 'pregnancy_records', 'created_at') ? 'p.created_at' : 'p.last_menstrual_period';
        $data['sections']['maternal_health'] = adminExtendedFetchRows($pdo, "
            SELECT p.id, {$dateColumn} AS record_date, CONCAT(r.first_name, ' ', r.last_name) AS mother_name,
                   TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age, COALESCE(b.name, 'Unassigned') AS barangay,
                   p.last_menstrual_period, p.expected_delivery_date, p.risk_level, p.pregnancy_status, p.remarks
            FROM pregnancy_records p
            JOIN residents r ON r.id = p.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE {$dateColumn} >= :start AND {$dateColumn} < :end
            ORDER BY {$dateColumn}, mother_name, p.id
        ", $periodParams);
        $data['summary']['maternal_records'] = count($data['sections']['maternal_health']);
        $data['summary']['active_pregnancies'] = adminExtendedFetchCount($pdo, "SELECT COUNT(*) FROM pregnancy_records WHERE pregnancy_status = 'Pregnant'");
        $data['summary']['high_risk_pregnancies'] = adminExtendedFetchCount($pdo, "SELECT COUNT(*) FROM pregnancy_records WHERE LOWER(COALESCE(risk_level, '')) LIKE '%high%' AND pregnancy_status = 'Pregnant'");
    }

    if (adminExtendedTableExists($pdo, 'disease_cases')) {
        $data['sections']['disease_surveillance'] = adminExtendedFetchRows($pdo, "
            SELECT dc.id, dc.case_date, CONCAT(r.first_name, ' ', r.last_name) AS patient_name,
                   TIMESTAMPDIFF(YEAR, r.birthdate, dc.case_date) AS age, r.sex,
                   COALESCE(b.name, 'Unassigned') AS barangay, COALESCE(dt.name, 'Unspecified') AS disease_name,
                   dc.case_status, dc.outcome, dc.remarks
            FROM disease_cases dc
            JOIN residents r ON r.id = dc.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
            WHERE dc.case_date >= :start AND dc.case_date < :end
            ORDER BY dc.case_date, disease_name, patient_name, dc.id
        ", $periodParams);
        $data['summary']['disease_cases'] = count($data['sections']['disease_surveillance']);
        $data['summary']['disease_cases_by_type'] = adminExtendedFetchRows($pdo, "
            SELECT COALESCE(dt.name, 'Unspecified') AS disease_name, COUNT(*) AS total
            FROM disease_cases dc
            LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
            WHERE dc.case_date >= :start AND dc.case_date < :end
            GROUP BY COALESCE(dt.name, 'Unspecified')
            ORDER BY total DESC, disease_name
        ", $periodParams);
    }

    if (adminExtendedTableExists($pdo, 'vital_statistics')) {
        $data['sections']['vital_statistics'] = adminExtendedFetchRows($pdo, "
            SELECT vs.id, vs.event_date, vs.event_type, vs.full_name, COALESCE(b.name, 'Unassigned') AS barangay,
                   vs.status, vs.remarks
            FROM vital_statistics vs
            LEFT JOIN barangays b ON b.id = vs.barangay_id
            WHERE vs.event_date >= :start AND vs.event_date < :end
            ORDER BY vs.event_date, vs.event_type, vs.full_name
        ", $periodParams);
        $data['summary']['vital_statistics'] = count($data['sections']['vital_statistics']);
        $data['summary']['births'] = adminExtendedFetchCount($pdo, "SELECT COUNT(*) FROM vital_statistics WHERE LOWER(event_type) = 'birth' AND event_date >= :start AND event_date < :end", $periodParams);
        $data['summary']['deaths'] = adminExtendedFetchCount($pdo, "SELECT COUNT(*) FROM vital_statistics WHERE LOWER(event_type) = 'death' AND event_date >= :start AND event_date < :end", $periodParams);
    }

    if (adminExtendedTableExists($pdo, 'medicine_inventory')) {
        $data['sections']['medicine_inventory_snapshot'] = adminExtendedFetchRows($pdo, "
            SELECT id, item_name, item_type, unit, quantity, reorder_level, expiration_date, status
            FROM medicine_inventory
            ORDER BY item_name, expiration_date, id
        ");
        $data['summary']['medicine_items'] = count($data['sections']['medicine_inventory_snapshot']);
        $data['summary']['low_stock_medicine_items'] = adminExtendedFetchCount($pdo, "SELECT COUNT(*) FROM medicine_inventory WHERE quantity <= reorder_level");
    }

    return $data;
}

function adminExtendedMarkResidentDeceased(PDO $pdo, int $residentId): void
{
    if ($residentId <= 0) {
        return;
    }

    $updates = [];
    if (adminExtendedColumnExists($pdo, 'residents', 'status')) {
        $updates[] = "status = 'Deceased'";
    }
    if (adminExtendedColumnExists($pdo, 'residents', 'is_active')) {
        $updates[] = 'is_active = 0';
    }
    if (adminExtendedColumnExists($pdo, 'residents', 'updated_at')) {
        $updates[] = 'updated_at = NOW()';
    }
    if ($updates) {
        $pdo->prepare('UPDATE residents SET ' . implode(', ', $updates) . ' WHERE id = :id')->execute(['id' => $residentId]);
    }

    $pdo->prepare("DELETE FROM audit_logs WHERE action = 'Resident Dependent' AND module_name = 'Resident Portal' AND entity_id = :resident_id")->execute(['resident_id' => $residentId]);
    if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'portal_push_subscriptions')) {
        $pdo->prepare('UPDATE portal_push_subscriptions SET is_active = 0 WHERE resident_id = :resident_id')->execute(['resident_id' => $residentId]);
    }
}

function adminExtendedSyncDeathVitalRecords(PDO $pdo): void
{
    if (!function_exists('rhuTableExists') || !rhuTableExists($pdo, 'vital_statistics')) {
        return;
    }
    try {
        $stmt = $pdo->query("SELECT DISTINCT resident_id FROM vital_statistics WHERE resident_id IS NOT NULL AND resident_id > 0 AND LOWER(COALESCE(event_type, '')) = 'death'");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $residentId) {
            adminExtendedMarkResidentDeceased($pdo, (int)$residentId);
        }
    } catch (Throwable $e) {
        error_log('adminExtendedSyncDeathVitalRecords: ' . $e->getMessage());
    }
}

function adminExtendedAction(PDO $pdo, string $action, int $adminId): ?string
{
    $audit = static function (string $message, string $table, int $id = 0) use ($pdo, $adminId): void {
        portalAudit($pdo, $adminId, $message, $table, $id ?: null);
    };

    if ($action === 'save_resident') {
        $id = (int)($_POST['resident_id'] ?? 0);
        $genderValue = trim($_POST['gender'] ?? 'Other');
        $values = [
            trim($_POST['first_name'] ?? ''), trim($_POST['last_name'] ?? ''),
            trim($_POST['middle_name'] ?? ''), $_POST['date_of_birth'] ?? date('Y-m-d'),
            $genderValue, trim($_POST['civil_status'] ?? ''),
            trim($_POST['contact_number'] ?? ''), trim($_POST['email'] ?? ''),
            trim($_POST['address'] ?? ''), 1,
            trim($_POST['philhealth_id'] ?? ''),
        ];
        if ($id) {
            $stmt = $pdo->prepare('UPDATE residents SET first_name=?,last_name=?,middle_name=?,birthdate=?,sex=?,civil_status=?,contact_number=?,email=?,address=?,barangay_id=?,philhealth_number=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO residents (first_name,last_name,middle_name,birthdate,sex,civil_status,contact_number,email,address,barangay_id,philhealth_number) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute($values);
            $id = (int)$pdo->lastInsertId();
        }
        $audit('Saved resident profile', 'residents', $id);
        return 'Resident profile saved.';
    }

    if ($action === 'request_staff_password_code') {
        $id = (int)($_POST['staff_id'] ?? 0);
        if ($id <= 0) return 'Select a staff account first.';
        $staffStmt = $pdo->prepare('SELECT first_name, email FROM health_workers WHERE id = :id LIMIT 1');
        $staffStmt->execute(['id' => $id]);
        $staffRow = $staffStmt->fetch(PDO::FETCH_ASSOC);
        $staffEmail = trim((string)($staffRow['email'] ?? ''));
        if (!$staffRow || $staffEmail === '' || !filter_var($staffEmail, FILTER_VALIDATE_EMAIL)) {
            return 'The selected staff account has no valid email address for verification.';
        }
        $code = (string)random_int(100000, 999999);
        $_SESSION['staff_password_verification'] = [
            'staff_id' => $id,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + 600,
            'used' => false,
        ];
        $subject = 'RHU staff password change verification code';
        $html = '<p>Hello ' . htmlspecialchars((string)($staffRow['first_name'] ?? 'Staff'), ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your RHU administrator requested a password change for your staff account.</p>'
            . '<p>Your verification code is <strong style="font-size:24px;letter-spacing:4px">' . $code . '</strong></p>'
            . '<p>This code expires in 10 minutes. If you did not request this, contact the RHU administrator.</p>';
        $mailResult = function_exists('sendRHUEmail') ? sendRHUEmail($staffEmail, $subject, $html) : ['success' => false];
        if (!empty($mailResult['success'])) {
            return 'A verification code was sent to the staff member\'s email address.';
        }
        unset($_SESSION['staff_password_verification']);
        return 'The verification email could not be sent. Check the mail settings and try again.';
    }

    if ($action === 'save_staff_profile') {
        $id = (int)($_POST['staff_id'] ?? 0);
        if ($id <= 0) return 'Select a staff account first.';
        $updateColumns = ['position_title = :position_title', 'license_number = :license_number', 'contact_number = :contact_number', 'email = :email', 'updated_at = NOW()'];
        $params = [
            'position_title' => trim($_POST['staff_type'] ?? ''),
            'license_number' => trim($_POST['license_number'] ?? ''),
            'contact_number' => trim($_POST['phone_number'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'id' => $id,
        ];
        if ($params['email'] === '' || !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid staff email address.';
        }
        $newPassword = (string)($_POST['new_password'] ?? '');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8 || $newPassword !== (string)($_POST['confirm_password'] ?? '')) {
                return 'Password must be at least 8 characters and match the confirmation.';
            }
            $verification = $_SESSION['staff_password_verification'] ?? null;
            $verificationCode = trim((string)($_POST['verification_code'] ?? ''));
            if (!is_array($verification) || (int)($verification['staff_id'] ?? 0) !== $id || !empty($verification['used']) || (int)($verification['expires_at'] ?? 0) < time() || $verificationCode === '' || !password_verify($verificationCode, (string)($verification['code_hash'] ?? ''))) {
                return 'A valid password verification code is required. Request a new code and try again.';
            }
            $updateColumns[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            unset($_SESSION['staff_password_verification']);
        }
        $stmt = $pdo->prepare('UPDATE health_workers SET ' . implode(', ', $updateColumns) . ' WHERE id = :id');
        $stmt->execute($params);
        $audit('Updated staff profile', 'health_workers', $id);
        return $newPassword !== '' ? 'Staff profile and password updated successfully.' : 'Staff profile updated.';
    }

    if ($action === 'delete_staff') {
        $id = (int)($_POST['staff_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_workers WHERE id=?')->execute([$id]);
        $audit('Deleted staff account', 'health_workers', $id);
        return 'Staff account deleted.';
    }

    if ($action === 'save_vaccination') {
        $id = (int)($_POST['vaccination_id'] ?? 0);
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $providerName = trim((string)($_POST['provider_name'] ?? ''));
        $providerId = (int)($_POST['provider_id'] ?? 0) ?: null;
        $vaccineName = trim((string)($_POST['vaccine_name'] ?? ''));
        $vaccinationDate = (string)($_POST['vaccination_date'] ?? '');
        $nextDoseDate = (string)($_POST['next_dose_date'] ?? '');
        if ($providerName === '' && !$providerId) return 'Enter the name of the staff member who administered the vaccine.';
        if ((function_exists('mb_strlen') ? mb_strlen($providerName) : strlen($providerName)) > 150) return 'Provider name must be 150 characters or fewer.';
        if ($vaccineName === '') return 'Enter a vaccine name.';
        $givenDate = DateTimeImmutable::createFromFormat('!Y-m-d', $vaccinationDate);
        if (!$givenDate || $givenDate->format('Y-m-d') !== $vaccinationDate) return 'Enter a valid vaccination date.';
        if ($nextDoseDate !== '') {
            $nextDate = DateTimeImmutable::createFromFormat('!Y-m-d', $nextDoseDate);
            if (!$nextDate || $nextDate->format('Y-m-d') !== $nextDoseDate || $nextDoseDate < $vaccinationDate) return 'Next dose date must be on or after the vaccination date.';
        }
        $residentStmt = $pdo->prepare('SELECT birthdate FROM residents WHERE id=?');
        $residentStmt->execute([$residentId]);
        $resident = $residentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$resident) return 'Select a valid resident.';
        if ($id) {
            $recordStmt = $pdo->prepare('SELECT id FROM vaccination_records WHERE id=?');
            $recordStmt->execute([$id]);
            if (!$recordStmt->fetchColumn()) return 'Vaccination record not found.';
        }
        if ($providerName !== '') {
            $providerId = null;
        } else {
            $providerStmt = $pdo->prepare("SELECT CONCAT_WS(' ', first_name, last_name) FROM health_workers WHERE id=?");
            $providerStmt->execute([$providerId]);
            $providerName = (string)$providerStmt->fetchColumn();
            if ($providerName === '') return 'Select a valid provider.';
        }
        $columnStmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vaccination_records' AND COLUMN_NAME='provider_name'");
        if (!(int)$columnStmt->fetchColumn()) {
            try {
                $pdo->exec('ALTER TABLE vaccination_records ADD COLUMN provider_name VARCHAR(150) NULL AFTER health_worker_id');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;
            }
        }
        $values = [
            $residentId,
            $vaccineName,
            $vaccinationDate,
            $providerId,
            trim($_POST['batch_number'] ?? ''),
            $nextDoseDate ?: null,
            $providerName,
        ];
        if ($id) {
            $stmt = $pdo->prepare('UPDATE vaccination_records SET resident_id=?,vaccine_name=?,vaccination_date=?,health_worker_id=?,batch_number=?,next_dose_date=?,provider_name=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([...$values, $id]);
        } else {
            $birthdate = !empty($resident['birthdate']) ? new DateTimeImmutable($resident['birthdate']) : null;
            $age = $birthdate && $birthdate <= $givenDate ? $birthdate->diff($givenDate)->y : null;
            $ageGroup = $age === null ? 'Unknown' : ($age < 1 ? 'Infant' : ($age < 10 ? 'Child' : ($age < 20 ? 'Adolescent' : ($age < 60 ? 'Adult' : 'Senior'))));
            $stmt = $pdo->prepare('INSERT INTO vaccination_records (resident_id,vaccine_name,vaccination_date,health_worker_id,batch_number,next_dose_date,provider_name,age_group) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([...$values, $ageGroup]);
            $id = (int)$pdo->lastInsertId();
        }
        $audit('Saved vaccination record administered by ' . $providerName, 'vaccination_records', $id);
        return 'Vaccination record saved.';
    }

    if ($action === 'delete_vaccination') {
        $id = (int)($_POST['vaccination_id'] ?? 0);
        $pdo->prepare('DELETE FROM vaccination_records WHERE id=?')->execute([$id]);
        $audit('Deleted vaccination record', 'vaccination_records', $id);
        return 'Vaccination record deleted.';
    }

    if ($action === 'create_pregnancy') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $femaleCheck = $pdo->prepare("SELECT id FROM residents WHERE id = :id AND sex LIKE 'Female%' LIMIT 1");
        $femaleCheck->execute(['id' => $residentId]);
        if (!$femaleCheck->fetchColumn()) return 'Only female residents can have pregnancy records.';
        $recentBirthCheck = $pdo->prepare("SELECT event_date FROM vital_statistics
            WHERE resident_id = :resident AND LOWER(event_type) = 'birth'
              AND event_date > DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
            ORDER BY event_date DESC, id DESC LIMIT 1");
        $recentBirthCheck->execute(['resident' => $residentId]);
        $recentBirthDate = $recentBirthCheck->fetchColumn();
        if ($recentBirthDate) {
            $eligibleDate = (new DateTimeImmutable((string)$recentBirthDate))->modify('+7 months')->format('Y-m-d');
            return "This resident has a recorded birth on {$recentBirthDate}. A new pregnancy case can be registered from {$eligibleDate}, after the seven-month postpartum period.";
        }
        $lmpDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['lmp'] ?? ''));
        if (!$lmpDate) return 'Please provide a valid LMP date.';
        $edc = $lmpDate->modify('+280 days')->format('Y-m-d');
        $stmt = $pdo->prepare('INSERT INTO pregnancy_records (resident_id,last_menstrual_period,expected_delivery_date,remarks,risk_level,pregnancy_status) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $residentId,
            $lmpDate->format('Y-m-d'),
            $edc,
            trim($_POST['risk_factors'] ?? ''),
            isset($_POST['high_risk']) ? 'High Risk' : 'Normal',
            'Pregnant'
        ]);
        $audit('Created pregnancy record', 'pregnancy_records', (int)$pdo->lastInsertId());
        return 'Pregnancy record created.';
    }

    if ($action === 'create_disease_case') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $diseaseSelection = (string)($_POST['disease_id'] ?? '');
        $newDiseaseName = trim((string)($_POST['new_disease_name'] ?? ''));
        $caseDate = (string)($_POST['case_date'] ?? '');
        $classification = (string)($_POST['classification'] ?? 'Suspected');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $caseDate);
        if (!$date || $date->format('Y-m-d') !== $caseDate) return 'Enter a valid case date.';
        if (!in_array($classification, ['Suspected', 'Probable', 'Confirmed'], true)) return 'Select a valid classification.';
        if ($diseaseSelection === 'new') {
            if ($newDiseaseName === '') return 'Enter the new disease name.';
            if ((function_exists('mb_strlen') ? mb_strlen($newDiseaseName) : strlen($newDiseaseName)) > 150) return 'Disease name must be 150 characters or fewer.';
        } elseif ((int)$diseaseSelection <= 0) {
            return 'Select a disease or add a new disease.';
        }
        $residentStmt = $pdo->prepare('SELECT id, barangay_id FROM residents WHERE id=?');
        $residentStmt->execute([$residentId]);
        $resident = $residentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$resident) return 'Select a valid resident.';

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            if ($diseaseSelection === 'new') {
                $diseaseStmt = $pdo->prepare('SELECT id FROM disease_types WHERE LOWER(TRIM(name))=LOWER(?) ORDER BY id LIMIT 1');
                $diseaseStmt->execute([$newDiseaseName]);
                $diseaseId = (int)$diseaseStmt->fetchColumn();
                if (!$diseaseId) {
                    $pdo->prepare('INSERT INTO disease_types (name) VALUES (?)')->execute([$newDiseaseName]);
                    $diseaseId = (int)$pdo->lastInsertId();
                    $audit('Added disease type: ' . $newDiseaseName, 'disease_types', $diseaseId);
                }
            } else {
                $diseaseStmt = $pdo->prepare('SELECT id FROM disease_types WHERE id=?');
                $diseaseStmt->execute([(int)$diseaseSelection]);
                $diseaseId = (int)$diseaseStmt->fetchColumn();
                if (!$diseaseId) {
                    if ($ownsTransaction) $pdo->rollBack();
                    return 'Select a valid disease.';
                }
            }
            $symptoms = trim((string)($_POST['symptoms'] ?? ''));
            $columns = ['resident_id', 'disease_type_id', 'case_date', 'case_status', 'outcome', 'remarks'];
            $values = [$residentId, $diseaseId, $caseDate, $classification, trim((string)($_POST['outcome'] ?? 'Active')), $symptoms];
            foreach (['barangay_id' => $resident['barangay_id'], 'date_reported' => date('Y-m-d'), 'reported_by_id' => $adminId ?: null, 'symptoms' => $symptoms, 'case_classification' => $classification] as $column => $value) {
                if (adminExtendedColumnExists($pdo, 'disease_cases', $column)) {
                    $columns[] = $column;
                    $values[] = $value;
                }
            }
            $stmt = $pdo->prepare('INSERT INTO disease_cases (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
            $stmt->execute($values);
            $audit('Created disease case', 'disease_cases', (int)$pdo->lastInsertId());
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return 'Disease case recorded.';
    }

    if (in_array($action, ['save_medicine','delete_medicine'], true)) {
        inventoryRequireAdmin();
        return 'Use Inventory / Stock History to add, restock or record a stock change with its location and purpose.';
    }

    if ($action === 'save_vital') {
        $residentId = (int)($_POST['resident_id'] ?? $_POST['mother_id'] ?? 0) ?: null;
        $vitalType = trim((string)($_POST['vital_type'] ?? 'Birth')) ?: 'Birth';
        $stmt = $pdo->prepare('INSERT INTO vital_statistics (resident_id,barangay_id,event_type,event_date,full_name,status,remarks) VALUES (?,1,?,?,?,"Recorded",?)');
        $stmt->execute([
            $residentId,
            $vitalType,
            $_POST['record_date'] ?? date('Y-m-d'),
            trim($_POST['person_name'] ?? ''),
            trim($_POST['cause_of_death'] ?? $_POST['location'] ?? '')
        ]);
        if (strcasecmp($vitalType, 'Death') === 0 && $residentId) {
            adminExtendedMarkResidentDeceased($pdo, $residentId);
        }
        $audit('Saved vital statistics record', 'vital_statistics');
        return 'Vital statistics record created.';
    }

    if ($action === 'generate_doh_report') {
        $type = $_POST['report_type'] ?? 'fhsis';
        $year = (int)($_POST['year'] ?? date('Y'));
        $month = (int)($_POST['month'] ?? date('n'));
        if ($month < 1 || $month > 12) {
            return 'Select a valid report month.';
        }
        $data = adminExtendedGenerateFhsisReportData($pdo, $year, $month);
        $pdo->prepare("INSERT INTO fhsis_reports (report_type,reporting_period,report_month,report_year,report_data,status,remarks) VALUES (?,?,?,?,?,'Draft',?)")
            ->execute([strtoupper($type), $data['report_period'] ?? ($month . '-' . $year), $month, $year, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), trim($_POST['notes'] ?? '')]);
        $audit('Generated DOH report', 'fhsis_reports');
        return strtoupper($type) . ' report generated for ' . ($data['report_period'] ?? ($month . '-' . $year)) . ' with organized monthly records.';
    }

    if ($action === 'run_database_maintenance') {
        $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) $pdo->query('ANALYZE TABLE `' . str_replace('`', '``', $table) . '`');
        $audit('Ran database table analysis', 'system');
        return count($tables) . ' database tables analyzed.';
    }

    return null;
}

function renderFhsisReportForm(): void
{
    $csrf = htmlspecialchars(portalCsrfToken(), ENT_QUOTES, 'UTF-8');
    echo '<form data-fhsis-download method="post" action="RHUAdminDashboard.php?tab=reports" class="admin-tool-panel grid gap-2 text-xs sm:grid-cols-4">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="generate_doh_report">';
    echo '<select name="report_type" aria-label="Report type" class="rounded border p-2"><option value="FHSIS">FHSIS</option></select>';
    echo '<select required name="month" aria-label="Report month" class="rounded border p-2">';
    foreach ([1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $number => $name) {
        echo '<option value="' . $number . '"' . ($number === (int)date('n') ? ' selected' : '') . '>' . $name . '</option>';
    }
    echo '</select><input required type="number" min="1900" max="9999" name="year" aria-label="Report year" value="' . date('Y') . '" class="rounded border p-2">';
    echo '<button class="rounded bg-purple-700 p-2 font-bold text-white">Generate DOH Report</button></form>';
    echo '<p data-fhsis-download-status role="status" aria-live="polite" class="mt-2 text-sm"></p>';
    echo '<p class="mt-2 text-xs text-amber-800">FHSIS exports are drafts for RHU review. Check Indicator Review and Data Quality before submission. Missing evidence stays blank; a zero does not certify a complete register. Resident names and clinical details are excluded from the export.</p>';
    echo '<script src="assets/js/fhsis-download.js?v=2" defer></script>';
}

function renderAdminExtendedPanel(PDO $pdo, string $tab): void
{
    // The report form has no resident/staff list dependency.
    if ($tab === 'reports') { renderFhsisReportForm(); return; }
    echo '<style>
        .admin-tool-group { position: relative; z-index: 40; }
        .admin-tool-group > summary::-webkit-details-marker { display: none; }
        .admin-tool-group > summary::marker { display: none; }
        .admin-tool-group .admin-tool-panel {
            position: absolute; left: 0; top: calc(100% + .6rem); width: min(100%, 1200px);
            z-index: 9999; box-shadow: 0 24px 50px rgba(15, 23, 42, .18);
        }
        input[data-disease-case-date] { color-scheme: light; }
        input[data-disease-case-date]::-webkit-calendar-picker-indicator {
            display: block !important; opacity: 1 !important;
            width: 20px; height: 20px; cursor: pointer;
        }
    </style>';

    $livingWhere = function_exists('rhuLivingResidentWhereSql') ? rhuLivingResidentWhereSql('r') : "COALESCE(r.is_active, 1) = 1 AND LOWER(COALESCE(r.status, 'Active')) NOT IN ('deceased', 'dead', 'inactive')";
    $residents = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM residents r WHERE {$livingWhere} ORDER BY first_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $femaleResidents = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM residents r WHERE sex LIKE 'Female%' AND {$livingWhere} ORDER BY first_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $staff = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM health_workers ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);
    $select = static function (string $name, array $rows, string $placeholder): void {
        echo '<select required name="' . htmlspecialchars($name) . '" class="rounded border p-2"><option value="">' . htmlspecialchars($placeholder) . '</option>';
        foreach ($rows as $row) echo '<option value="' . (int)$row['id'] . '">' . htmlspecialchars($row['name']) . '</option>';
        echo '</select>';
    };

    $open = '<div class="admin-tool-panel mb-4 grid gap-4 rounded-2xl border border-emerald-100 bg-white/95 p-4 shadow-sm ring-1 ring-emerald-100">';
    $close = '</div>';

    if ($tab === 'residents') {
        echo $open;
        echo '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_resident"><input type="number" name="resident_id" placeholder="Existing ID (blank=new)" class="rounded border p-2"><input required name="first_name" placeholder="First name" class="rounded border p-2"><input required name="last_name" placeholder="Last name" class="rounded border p-2"><input name="middle_name" placeholder="Middle name" class="rounded border p-2"><input required type="date" name="date_of_birth" class="rounded border p-2"><select name="gender" class="rounded border p-2"><option>Female</option><option>Male</option><option>Other</option></select><input name="civil_status" placeholder="Civil status" class="rounded border p-2"><input name="contact_number" placeholder="Contact" class="rounded border p-2"><input type="email" name="email" placeholder="Email" class="rounded border p-2"><input required name="address" placeholder="Address" class="rounded border p-2"><input required name="barangay" placeholder="Barangay" class="rounded border p-2"><input name="philhealth_id" placeholder="PhilHealth ID" class="rounded border p-2"><button class="rounded bg-teal-700 p-2 font-bold text-white">Create / Update Resident</button></form>';
        echo $close;
    } elseif ($tab === 'staff') {
        echo $open;
        echo '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_staff_profile"><input type="hidden" name="tab" value="staff">';
        echo '<select required name="staff_id" id="admin-staff-edit-select" class="rounded border p-2"><option value="">Select staff</option>';
        foreach ($staff as $staffRow) {
            $staffLabel = trim((string)($staffRow['name'] ?? ''));
            $staffDetails = $pdo->prepare('SELECT email, position_title, license_number, contact_number FROM health_workers WHERE id = :id LIMIT 1');
            $staffDetails->execute(['id' => (int)$staffRow['id']]);
            $details = $staffDetails->fetch(PDO::FETCH_ASSOC) ?: [];
            echo '<option value="' . (int)$staffRow['id'] . '" data-email="' . esc($details['email'] ?? '') . '" data-position="' . esc($details['position_title'] ?? '') . '" data-license="' . esc($details['license_number'] ?? '') . '" data-phone="' . esc($details['contact_number'] ?? '') . '">' . esc($staffLabel) . '</option>';
        }
        echo '</select>';
        echo '<input required type="email" name="email" id="admin-staff-edit-email" placeholder="Email address" class="rounded border p-2"><input required name="staff_type" id="admin-staff-edit-position" placeholder="Position" class="rounded border p-2"><input name="license_number" id="admin-staff-edit-license" placeholder="License" class="rounded border p-2"><input name="phone_number" id="admin-staff-edit-phone" placeholder="Phone" class="rounded border p-2">';
        echo '<input type="password" name="new_password" placeholder="New password (optional)" autocomplete="new-password" minlength="8" class="rounded border p-2"><input type="password" name="confirm_password" placeholder="Confirm new password" autocomplete="new-password" minlength="8" class="rounded border p-2"><input name="verification_code" placeholder="Password verification code" inputmode="numeric" maxlength="6" class="rounded border p-2"><button class="rounded bg-emerald-700 p-2 font-bold text-white">Update Staff</button></form>';
        echo '<p class="mt-2 text-[11px] text-slate-600">Password verification codes are sent to the staff member\'s registered email address.</p><form method="post" class="mt-2 flex flex-wrap gap-2 text-xs"><input type="hidden" name="action" value="request_staff_password_code"><input type="hidden" name="tab" value="staff"><select required name="staff_id" class="rounded border p-2"><option value="">Select staff for password code</option>';
        foreach ($staff as $staffRow) echo '<option value="' . (int)$staffRow['id'] . '">' . esc($staffRow['name'] ?? '') . '</option>';
        echo '</select><button class="rounded bg-slate-800 px-4 py-2 font-bold text-white">Send password code</button></form>';
        echo '<script>(function(){const s=document.getElementById("admin-staff-edit-select");if(!s)return;const fill=()=>{const o=s.options[s.selectedIndex];["email","position","license","phone"].forEach(k=>{const el=document.getElementById("admin-staff-edit-"+k);if(el)el.value=o?.dataset?.[k]||"";});};s.addEventListener("change",fill);fill();})();</script>';
        echo '<form method="post" onsubmit="return confirm(\'Delete this staff account?\')" class="flex gap-2 text-xs"><input type="hidden" name="action" value="delete_staff">';
        $select('staff_id', $staff, 'Staff to delete');
        echo '<button class="rounded bg-red-700 px-4 font-bold text-white">Delete Staff</button></form>' . $close;
    } elseif ($tab === 'vaccination') {
        $vaccines = $pdo->query("SELECT MIN(id) AS id, vaccine_name AS name FROM vaccination_records GROUP BY vaccine_name ORDER BY vaccine_name")->fetchAll(PDO::FETCH_ASSOC);
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_vaccination"><input type="number" name="vaccination_id" placeholder="Existing ID (blank=new)" class="rounded border p-2">';
        $select('resident_id', $residents, 'Resident');
        echo '<input required name="vaccine_name" placeholder="Vaccine Name" class="rounded border p-2"><input required type="date" name="vaccination_date" class="rounded border p-2">';
        echo '<input required name="provider_name" maxlength="150" aria-label="Administered by (staff name)" placeholder="Administered by (staff name)" class="min-w-0 rounded border p-2">';
        echo '<input name="batch_number" placeholder="Batch" class="rounded border p-2"><input type="date" name="next_dose_date" class="rounded border p-2"><button class="rounded bg-emerald-700 p-2 font-bold text-white">Save Vaccination</button></form>' . $close;
    } elseif ($tab === 'maternal') {
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-3"><input type="hidden" name="action" value="create_pregnancy">';
        $select('resident_id', $femaleResidents, 'Mother/resident');
        echo '<label>LMP (start date)<input required type="date" name="lmp" id="admin-maternal-lmp" class="ml-2 rounded border p-2"></label><label>EDC (40 weeks / 9 months)<input required readonly type="date" name="edc" id="admin-maternal-edc" class="ml-2 rounded border bg-gray-50 p-2"><small class="ml-2 text-gray-500">Calculated automatically from LMP</small></label><input name="risk_factors" placeholder="Risk factors" class="rounded border p-2"><label><input type="checkbox" name="high_risk"> High risk</label><button class="rounded bg-pink-700 p-2 font-bold text-white">Create Pregnancy Case</button></form><script>(function(){const l=document.getElementById("admin-maternal-lmp"),e=document.getElementById("admin-maternal-edc");if(!l||!e)return;const update=()=>{if(!l.value){e.value="";return;}const d=new Date(l.value+"T00:00:00");d.setDate(d.getDate()+280);e.value=d.toISOString().slice(0,10);};l.addEventListener("input",update);l.addEventListener("change",update);update();})();</script>' . $close;
    } elseif ($tab === 'disease') {
        $diseases = $pdo->query("SELECT id, name FROM disease_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        echo $open . '<form method="post" data-disease-case-form class="grid items-start gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="create_disease_case">';
        $select('resident_id', $residents, 'Resident');
        echo '<div class="min-w-0"><select required name="disease_id" data-disease-selection aria-label="Disease" class="w-full rounded border p-2"><option value="">Disease</option>';
        foreach ($diseases as $disease) echo '<option value="' . (int)$disease['id'] . '">' . htmlspecialchars($disease['name'], ENT_QUOTES, 'UTF-8') . '</option>';
        echo '<option value="new">Other / Add new disease</option></select><input disabled data-new-disease-name name="new_disease_name" maxlength="150" aria-label="New disease name" placeholder="New disease name" class="hidden mt-2 w-full rounded border p-2"></div>';
        echo '<input required type="date" name="case_date" data-disease-case-date aria-label="Case date" title="Choose case date" class="min-w-0 rounded border p-2"><select name="classification" aria-label="Classification" class="rounded border p-2"><option>Suspected</option><option>Probable</option><option>Confirmed</option></select><input name="symptoms" placeholder="Symptoms" class="rounded border p-2"><button class="rounded bg-red-700 p-2 font-bold text-white">Create Disease Case</button></form>' . $close;
    } elseif ($tab === 'medicine') {
        (static function (PDO $pdo): void {
            $inventoryEmbedded = true;
            require dirname(__DIR__) . '/pages/InventoryManagement.php';
        })($pdo);
    } elseif ($tab === 'vital') {
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_vital"><select name="vital_type" id="admin-vital-type" class="rounded border p-2"><option value="Birth">Birth</option><option value="Death">Death</option></select><input required name="person_name" id="admin-vital-person-name" placeholder="Full Name" class="rounded border p-2"><input required type="date" name="record_date" class="rounded border p-2">';
        $select('resident_id', $residents, 'Resident');
        echo '<input name="cause_of_death" id="admin-vital-remarks" placeholder="Cause of death / remarks" class="rounded border p-2"><button class="rounded bg-purple-700 p-2 font-bold text-white">Create Vital Record</button></form><script>(function(){const t=document.getElementById("admin-vital-type"),n=document.getElementById("admin-vital-person-name"),r=document.getElementById("admin-vital-remarks");if(!t||!n||!r)return;const sync=()=>{const death=t.value==="Death";n.placeholder=death?"Deceased full name":"Child / person full name";r.placeholder=death?"Cause of death / remarks":"Birth location / remarks";};t.addEventListener("change",sync);sync();})();</script>' . $close;
    } elseif ($tab === 'reports') {
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="generate_doh_report"><select name="report_type" class="rounded border p-2"><option value="FHSIS">FHSIS</option></select><select required name="month" aria-label="Report month" class="rounded border p-2">';
        foreach ([1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $monthNumber => $monthName) {
            echo '<option value="' . $monthNumber . '"' . ($monthNumber === (int)date('n') ? ' selected' : '') . '>' . $monthName . '</option>';
        }
        echo '</select><input type="number" name="year" value="' . date('Y') . '" class="rounded border p-2"><button class="rounded bg-purple-700 p-2 font-bold text-white">Generate DOH Report</button></form>' . $close;
    } elseif ($tab === 'system' || $tab === 'settings') {
        echo $open . '<div class="flex flex-wrap items-center justify-between gap-3 text-xs"><div><h4 class="font-bold text-gray-900">Database Maintenance &amp; SQL Backup</h4><p class="text-gray-500">Run table analysis or download full SQL database backup.</p></div><div class="flex items-center gap-2"><form method="post"><input type="hidden" name="action" value="run_database_maintenance"><button class="rounded bg-blue-700 px-4 py-2 font-bold text-white shadow-sm hover:bg-blue-800">Run Table Maintenance</button></form><a href="?export=sql_backup" class="rounded bg-emerald-700 px-4 py-2 font-bold text-white shadow-sm hover:bg-emerald-800">Download SQL Backup</a></div></div>' . $close;
    }
}
