<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Email approval links authenticate with their request token, without a staff login.
if (isset($_GET['certificate_signature_approval']) || isset($_POST['certificate_signature_approval'])) {
    require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/patient-identifiers.php';
    require_once __DIR__ . '/includes/portal.php';
    require_once __DIR__ . '/includes/mailer.php';
    if (!($pdo instanceof PDO)) {
        http_response_code(503);
        exit('Certificate approval is temporarily unavailable. Please try again later.');
    }
    portalHandleCertificateSignatureApproval($pdo);
    http_response_code(400);
    exit('Missing certificate approval token.');
}
require_once __DIR__ . '/includes/patient-identifiers.php';
require_once __DIR__ . '/includes/staff-access.php';
require_once __DIR__ . '/includes/inventory-control.php';
$certificateApprovalApiRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'approve_certificate'
    && ($_POST['response_format'] ?? '') === 'json';
$dashboardApiRequest = $certificateApprovalApiRequest || in_array($_GET['api'] ?? '', ['admin_messages', 'get_notifications', 'mark_read', 'delete_notifications'], true);
if (empty($_SESSION['rhu_admin_authenticated']) && !rhuStaffCanAccessAdminDashboard((string)($_SESSION['rhu_staff_login']['staff_type'] ?? ''))) {
    if ($dashboardApiRequest) {
        http_response_code(empty($_SESSION['rhu_staff_login']) ? 401 : 403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Please sign in with an admin or secretary account.']);
        exit;
    }
    header('Location: RHULogin.php');
    exit;
}
if ($dashboardApiRequest) {
    ob_start();
    set_exception_handler(function (Throwable $exception) {
        ob_clean();
        error_log('Dashboard API error: ' . $exception->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unable to load dashboard data. Please try again.']);
        exit;
    });
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/portal.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/appointment-email-reminders.php';
require_once __DIR__ . '/includes/admin_extended.php';

if (!($pdo instanceof PDO)) {
    http_response_code(503);
    if ($dashboardApiRequest) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Database connection is unavailable.']);
        exit;
    }
    exit('Database connection is unavailable. Check the database configuration and try again.');
}

require_once __DIR__ . '/includes/resident-qr.php';
require_once __DIR__ . '/includes/fhsis-services.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'generate_doh_report') {
    if (!portalVerifyCsrf()) {
        http_response_code(403);
        exit('Your session expired. Reload the dashboard and try again.');
    }
    $reportMonth = (int)($_POST['month'] ?? 0);
    $reportYear = (int)($_POST['year'] ?? 0);
    if ($reportMonth < 1 || $reportMonth > 12 || $reportYear < 1900 || $reportYear > 9998) {
        http_response_code(400);
        exit('Select a valid reporting month and year.');
    }
    require_once __DIR__ . '/includes/fhsis-template-report.php';
    // Release PHP's session mutex before database reads and workbook generation.
    // Otherwise the lock heartbeat and every other admin request wait for this export.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $reportFile = tempnam(sys_get_temp_dir(), 'fhsis-');
    try {
        // Read all departments from one consistent database snapshot.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try {
            $reportData = fhsisTemplateReportData($pdo, $reportYear, $reportMonth);
            $pdo->commit();
        } catch (Throwable $sourceError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $sourceError;
        }
        fhsisWriteTemplateReport($reportData, $reportFile);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="FHSIS-' . sprintf('%04d-%02d', $reportYear, $reportMonth) . '.xlsx"');
        header('Cache-Control: private, no-store');
        header('X-FHSIS-Status: draft-review-required');
        header('Content-Length: ' . filesize($reportFile));
        readfile($reportFile);
    } catch (Throwable $e) {
        error_log('FHSIS Excel export: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
            echo 'Unable to generate the FHSIS report. ' . $e->getMessage();
        } else {
            echo 'Unable to generate the FHSIS report. Check the latest "FHSIS Excel export" entry in the server PHP error log for the specific cause.';
        }
    } finally {
        if ($reportFile && is_file($reportFile)) unlink($reportFile);
    }
    exit;
}

portalHandleCertificateSignatureApproval($pdo);
if (function_exists('portalEnsureHealthWorkerIds')) {
    portalEnsureHealthWorkerIds($pdo);
}
if (!$dashboardApiRequest && function_exists('rhuSendUpcomingAppointmentEmailReminders')) {
    rhuSendUpcomingAppointmentEmailReminders($pdo);
}
if (isset($_GET['export']) && $_GET['export'] === 'sql_backup' && !empty($pdo)) {
    adminDownloadSqlBackup($pdo);
}
if (empty($_SESSION['rhu_admin_authenticated']) && empty($_SESSION['rhu_staff_login'])) {
    if ($dashboardApiRequest) {
        ob_clean();
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Your session has expired. Please sign in again.']);
        exit;
    }
    header('Location: RHULogin.php');
    exit;
}
if (empty($_SESSION['user']) && !empty($_SESSION['rhu_staff_login'])) {
    $_SESSION['user'] = [
        'user_id' => (int)($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0),
        'id' => (int)($_SESSION['rhu_staff_login']['id'] ?? 0),
        'email' => $_SESSION['rhu_staff_login']['email'] ?? '',
        'username' => $_SESSION['rhu_staff_login']['email'] ?? 'rhu_staff',
        'first_name' => strtok($_SESSION['rhu_staff_login']['name'] ?? 'RHU Staff', ' ') ?: 'RHU',
        'last_name' => trim(substr($_SESSION['rhu_staff_login']['name'] ?? 'Staff', strlen(strtok($_SESSION['rhu_staff_login']['name'] ?? 'RHU Staff', ' ') ?: 'RHU'))),
        'admin_code' => $_SESSION['rhu_staff_login']['staff_type'] ?? 'RHU-STAFF',
        'designation' => $_SESSION['rhu_staff_login']['position'] ?? 'RHU Healthcare Staff',
    ];
}
if ($dashboardApiRequest) ob_clean();
portalHandleNotificationApi($pdo);

if (($_GET['api'] ?? '') === 'admin_messages') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
            $mStmt = $pdo->query("
                SELECT m.id, m.resident_id, r.first_name, r.last_name, r.email, m.subject, m.message, m.admin_reply, m.status, m.created_at
                FROM messages m
                LEFT JOIN residents r ON m.resident_id = r.id
                ORDER BY m.created_at DESC LIMIT 50
            ");
        } else {
            $mStmt = $pdo->query("
                SELECT a.id, a.record_id AS resident_id, r.first_name, r.last_name, r.email,
                       COALESCE(NULLIF(TRIM(a.action), ''), 'Resident Inquiry') AS subject,
                       a.description AS message, '' AS admin_reply, 'Pending' AS status, a.created_at
                FROM audit_logs a
                INNER JOIN residents r ON a.record_id = r.id
                WHERE a.module_name = 'Resident Portal' AND a.action = 'Resident Message'
                ORDER BY a.created_at DESC LIMIT 50
            ");
        }
        $messages = $mStmt->fetchAll(pdo::FETCH_ASSOC) ?: [];
        echo json_encode(['success' => true, 'messages' => $messages, 'count' => count($messages)]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'messages' => [], 'count' => 0]);
    }
    exit;
}

ensurePortalTables($pdo);
if (function_exists('adminExtendedSyncDeathVitalRecords')) {
    adminExtendedSyncDeathVitalRecords($pdo);
}

if (isset($_GET['logout'])) {
    portalAudit($pdo, (int)($_SESSION['user']['user_id'] ?? 0), 'RHU Admin Logout', 'users', (int)($_SESSION['user']['user_id'] ?? 0));
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: RHUAdminLogin.php');
    exit;
}

if (($_GET['export'] ?? '') === 'summary' && $pdo) {
    portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Exported dashboard summary report', 'reports', null);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rhu-summary-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Metric', 'Count']);
    foreach (
        [
            'Users' => 'health_workers',
            'Active Staff' => 'health_workers WHERE status = \'Active\'',
            'Residents' => 'residents',
            'Consultations' => 'consultations',
            'Pregnancies' => 'pregnancy_records',
            'Vaccinations' => 'vaccination_records',
            'Disease Cases' => 'disease_cases',
            'Medicine Items' => 'medicine_inventory',
        ] as $label => $source
    ) {
        fputcsv($output, [$label, (int)$pdo->query("SELECT COUNT(*) FROM {$source}")->fetchColumn()]);
    }
    fclose($output);
    exit;
}

if (($_GET['backup'] ?? '') === 'sql' && $pdo) {
    portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Downloaded SQL database backup', 'system', null);
    adminDownloadSqlBackup($pdo);
}

if (isset($_GET['certificate_pdf']) && ctype_digit((string)$_GET['certificate_pdf']) && $pdo) {
    portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Downloaded certificate PDF', 'certificates', (int)$_GET['certificate_pdf']);
    adminDownloadCertificatePdf($pdo, (int)$_GET['certificate_pdf']);
}

/**
 * Escape output for HTML.
 * @param mixed $value
 */
function esc(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function isCertificateAdminAccount(array $worker): bool
{
    $position = strtoupper(trim((string) ($worker['position_title'] ?? '')));
    $role = strtoupper(trim((string) ($worker['role'] ?? '')));
    if (strpos($position . ' ' . $role, 'SECRETARY') !== false) return false;
    return in_array($position, ['ADMIN_STAFF', 'ADMIN STAFF', 'RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN', 'MHO'], true)
        || in_array($role, ['ADMIN_STAFF', 'RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN', 'MHO'], true)
        || strpos($position, 'MUNICIPAL HEALTH OFFICER') !== false;
}

function resolveCertificateAdmin(PDO $pdo): ?array
{
    $rows = $pdo->query("SELECT id, email, position_title, role FROM health_workers WHERE status = 'Active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (isCertificateAdminAccount($row)
            && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            return ['staff_id' => (int) $row['id'], 'user_id' => (int) $row['id'], 'email' => $row['email']];
        }
    }
    return null;
}

function resolveCurrentAdminEmail(PDO $pdo): string
{
    return (string) (resolveCertificateAdmin($pdo)['email'] ?? '');
}

$canUploadAdminSignature = false;
$signatureStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
if ($signatureStaffId > 0) {
    $signatureStaffStmt = $pdo->prepare('SELECT position_title, role FROM health_workers WHERE id = :id AND status = \'Active\'');
    $signatureStaffStmt->execute(['id' => $signatureStaffId]);
    $signatureStaff = $signatureStaffStmt->fetch(PDO::FETCH_ASSOC);
    $canUploadAdminSignature = $signatureStaff && isCertificateAdminAccount($signatureStaff);
} elseif (!empty($_SESSION['rhu_admin_authenticated']) && empty($_SESSION['rhu_staff_login'])) {
    $canUploadAdminSignature = true;
}

function iconSvg(string $name, string $class = 'w-5 h-5'): string
{
    $icons = [
        'shield' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'users' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'settings' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6V20a2 2 0 0 1-4 0v-.1a1.65 1.65 0 0 0-1-.6 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1H4a2 2 0 0 1 0-4h.1a1.65 1.65 0 0 0 .6-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-.6V4a2 2 0 0 1 4 0v.1a1.65 1.65 0 0 0 1 .6 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.24.31.4.68.6 1H20a2 2 0 0 1 0 4h-.1a1.65 1.65 0 0 0-.5 1z"/></svg>',
        'bar' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>',
        'logout' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/><path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/></svg>',
        'bell' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M9 17a3 3 0 0 0 6 0"/></svg>',
        'home' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>',
        'activity' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'file' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'database' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>',
        'lock' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'check' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>',
        'clock' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
        'trend' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'right' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 5l7 7-7 7"/></svg>',
        'eye' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        'edit' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
        'trash' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
        'plus' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        'download' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'refresh' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        'server' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/></svg>',
        'stethoscope' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v7a6 6 0 0 0 12 0V3"/><path d="M6 10a3 3 0 0 0 6 0"/><path d="M12 20a4 4 0 0 0 8 0v-2"/></svg>',
        'building' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M9 22V12h6v10"/><path d="M9 8h.01"/><path d="M13 8h.01"/><path d="M9 12h.01"/><path d="M13 12h.01"/></svg>',
        'mail' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
        'phone' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.09 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.77.6 2.6a2 2 0 0 1-.45 2.11L8 9a16 16 0 0 0 7 7l.57-1.24a2 2 0 0 1 2.11-.45c.83.27 1.7.48 2.6.6A2 2 0 0 1 22 16.92z"/></svg>',
        'key' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M10.5 12.5L21 2"/><path d="M17 5l2 2"/></svg>',
        'menu' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'close' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'baby' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h.01M15 12h.01"/><path d="M10 16c.5.35 1.17.5 2 .5s1.5-.15 2-.5"/><path d="M12 3a9 9 0 1 0 9 9c0-2.5-1-4.7-2.7-6.3"/><path d="M12 3c2 0 3 1 3 2.5S14 8 12 8"/></svg>',
        'alert' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>',
        'pill' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a5 5 0 0 0-7-7l-10 10a5 5 0 0 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function renderAdminFloatingToolsModal(PDO $pdo, string $modalId, string $title, string $description, string $panelTab, string $maxWidth = 'max-w-6xl'): void
{
?>
    <div data-floating-modal="<?php echo esc($modalId); ?>" class="fixed inset-0 z-[90] hidden bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6">
        <div class="mx-auto mt-8 max-h-[86vh] w-full <?php echo esc($maxWidth); ?> overflow-y-auto rounded-2xl border border-white/70 bg-white p-4 shadow-2xl sm:p-5">
            <div class="mb-4 flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
                <div>
                    <h3 class="text-base font-black text-gray-900"><?php echo esc($title); ?></h3>
                    <p class="text-xs text-gray-500"><?php echo esc($description); ?></p>
                </div>
                <button type="button" data-floating-modal-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close <?php echo esc($title); ?>">&times;</button>
            </div>
            <?php renderAdminExtendedPanel($pdo, $panelTab); ?>
        </div>
    </div>
<?php
}

function certificateStaffMatchGroups(string $certificateType): array
{
    $name = strtolower($certificateType);
    $groups = ['physician'];
    if (str_contains($name, 'birth') || str_contains($name, 'maternal') || str_contains($name, 'prenatal') || str_contains($name, 'family planning')) {
        $groups = ['midwife', 'nurse', 'physician'];
    } elseif (str_contains($name, 'immunization') || str_contains($name, 'vaccine') || str_contains($name, 'nursing') || str_contains($name, 'vital signs')) {
        $groups = ['nurse', 'physician'];
    } elseif (str_contains($name, 'laboratory') || str_contains($name, 'diagnostic') || str_contains($name, 'specimen')) {
        $groups = ['medtech', 'physician'];
    } elseif (str_contains($name, 'sanitary') || str_contains($name, 'sanitation') || str_contains($name, 'clearance')) {
        $groups = ['sanitary', 'physician'];
    } elseif (str_contains($name, 'barangay') || str_contains($name, 'community')) {
        $groups = ['bhw', 'nurse', 'physician'];
    }
    return $groups;
}

function certificateStaffMatchesType(string $certificateType, string $staffType, string $specialization = ''): bool
{
    $haystack = strtolower($staffType . ' ' . $specialization);
    foreach (certificateStaffMatchGroups($certificateType) as $group) {
        if ($group === 'physician' && (str_contains($haystack, 'physician') || str_contains($haystack, 'doctor'))) return true;
        if ($group === 'nurse' && str_contains($haystack, 'nurse')) return true;
        if ($group === 'midwife' && str_contains($haystack, 'midwife')) return true;
        if ($group === 'medtech' && (str_contains($haystack, 'medtech') || str_contains($haystack, 'medical technolog') || str_contains($haystack, 'laboratory'))) return true;
        if ($group === 'sanitary' && (str_contains($haystack, 'sanitary') || str_contains($haystack, 'sanitation') || str_contains($haystack, 'inspector'))) return true;
        if ($group === 'bhw' && (str_contains($haystack, 'bhw') || str_contains($haystack, 'barangay health'))) return true;
    }
    return false;
}

$tab = $_GET['tab'] ?? $_POST['tab'] ?? 'overview';
$showNotifs = isset($_GET['notifs']) && $_GET['notifs'] === '1';

// ----------------------------------------------------
// 1. DATABASE POST HANDLERS FOR ACTIONS
// ----------------------------------------------------
$flashSuccess = '';
$flashError = '';
// staff_id is the health_workers primary key; user/id session fields use another identifier.
$consultationEditorStaffId = (int)($_SESSION['rhu_staff_login']['staff_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($pdo)) {
    if (!portalVerifyCsrf()) {
        http_response_code(419);
        if ($certificateApprovalApiRequest) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Your form token expired. Reload the dashboard and retry.']);
            exit;
        }
        exit('Invalid or expired form token. Refresh the page and try again.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_my_admin_account') {
        try {
            if (empty($_SESSION['rhu_admin_authenticated'])) {
                throw new RuntimeException('Sign in through the admin portal to delete your admin account.');
            }
            $accountId = (int) ($_SESSION['user']['user_id'] ?? 0);
            if (($_POST['delete_confirmation'] ?? '') !== 'DELETE') {
                throw new RuntimeException('Type DELETE to confirm account deletion.');
            }
            $pdo->beginTransaction();
            $admins = $pdo->query("SELECT id, password_hash FROM health_workers
                WHERE status = 'Active' AND (UPPER(position_title) IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')
                    OR UPPER(position_title) LIKE '%MUNICIPAL HEALTH OFFICER%' OR UPPER(role) = 'ADMIN')
                ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $account = null;
            foreach ($admins as $admin) {
                if ((int) $admin['id'] === $accountId) $account = $admin;
            }
            if (!$account || !password_verify((string) ($_POST['current_password'] ?? ''), $account['password_hash'])) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            if (count($admins) < 2) {
                throw new RuntimeException('Create another active administrator before deleting this account.');
            }
            // Do not allow cascading deletion to remove records owned by this account.
            $references = $pdo->query("SELECT k.TABLE_NAME, k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                    AND rc.TABLE_NAME = k.TABLE_NAME
                WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'health_workers'
                    AND k.REFERENCED_COLUMN_NAME = 'id' AND rc.DELETE_RULE = 'CASCADE'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($references as $reference) {
                $tableName = str_replace('`', '``', $reference['TABLE_NAME']);
                $columnName = str_replace('`', '``', $reference['COLUMN_NAME']);
                $linked = $pdo->prepare("SELECT 1 FROM `{$tableName}` WHERE `{$columnName}` = :id LIMIT 1");
                $linked->execute(['id' => $accountId]);
                if ($linked->fetchColumn()) {
                    throw new RuntimeException('This account has linked records. Reassign them before deleting the account.');
                }
            }
            $deleteAccount = $pdo->prepare('DELETE FROM health_workers WHERE id = :id');
            $deleteAccount->execute(['id' => $accountId]);
            portalAudit($pdo, null, 'Administrator deleted own account #' . $accountId, 'health_workers', $accountId);
            $pdo->commit();
            $_SESSION = [];
            session_destroy();
            header('Location: RHUAdminLogin.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Admin self-deletion: ' . $e->getMessage());
            $flashError = $e instanceof PDOException
                ? 'Account deletion was blocked by linked records. No account was deleted.'
                : $e->getMessage();
        }
    }

    // Action: Update Facility Profile Info (System Tab)
    if ($action === 'update_rhu_info') {
        portalSaveSettings($pdo, [
            'rhu_name' => $_POST['rhu_name'] ?? '',
            'rhu_mho_name' => $_POST['mho_name'] ?? '',
            'rhu_municipality' => $_POST['municipality'] ?? '',
            'rhu_province' => $_POST['province'] ?? '',
            'rhu_contact_number' => $_POST['contact'] ?? '',
            'rhu_email' => $_POST['email'] ?? '',
        ]);
        portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Updated RHU Facility Settings", 'system', 1);
        $flashSuccess = "RHU Facility information updated successfully!";
    }

    // Action: Change Admin Password (Security Tab)
    if ($action === 'change_admin_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        $adminUserId = (int)($_SESSION['user']['user_id'] ?? 0);

        if (!$currentPass || !$newPass || !$confirmPass) {
            $flashError = "Please complete all password fields.";
        } elseif ($newPass !== $confirmPass) {
            $flashError = "New password and confirmation do not match.";
        } elseif (strlen($newPass) < 8) {
            $flashError = "New password must be at least 8 characters long.";
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM health_workers WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $adminUserId]);
            $hash = $stmt->fetchColumn();

            if ($hash && password_verify($currentPass, $hash)) {
                $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE health_workers SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                $upd->execute(['hash' => $newHash, 'id' => $adminUserId]);
                portalAudit($pdo, $adminUserId, "Admin Password Changed Successfully", 'health_workers', $adminUserId);
                $flashSuccess = "Administrator password updated successfully in database!";
            } else {
                $flashError = "Incorrect current password. Please try again.";
            }
        }
    }

    // Action: Send Test Email (System Tab)
    if ($action === 'send_test_email') {
        $targetEmail = trim($_POST['test_email'] ?? 'chedricbascoguin27@gmail.com');
        $testCode = sprintf('%06d', mt_rand(100000, 999999));
        $subject = 'ResiHUnity RHU - System SMTP Integration Test';
        $html = "<h3>ResiHUnity RHU System Test</h3><p>This is a test 2FA email sent from RHU System Settings.</p><div class='code-box'>{$testCode}</div>";
        $res = sendRHUEmail($targetEmail, $subject, $html);
        if ($res['success']) {
            $flashSuccess = "Test email dispatched successfully to {$targetEmail}! (Method: {$res['method']})";
        } else {
            $flashError = "Failed to dispatch test email to {$targetEmail}. Check SMTP settings.";
        }
    }

    // Action: Admin generate certificate and request required e-signature approvals
    if ($action === 'issue_certificate') {
        try {
            if (!$canUploadAdminSignature && (int) ($_FILES['admin_signature_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Only the admin/MHO may upload their signature. Request approval through email.');
            }
            $certificateAdmin = resolveCertificateAdmin($pdo);
            if (!$certificateAdmin) throw new RuntimeException('No active admin/MHO with a valid email is configured.');
            $residentId = (int)($_POST['resident_id'] ?? 0);
            $certificateTypeId = (int)($_POST['certificate_type_id'] ?? 0);
            $assignedStaffId = (int)($_POST['assigned_staff_id'] ?? 0);
            $purpose = trim((string)($_POST['purpose'] ?? ''));
            $issueDate = trim((string)($_POST['issue_date'] ?? date('Y-m-d')));
            $expiryDate = trim((string)($_POST['expiry_date'] ?? ''));
            if ($residentId <= 0 || $certificateTypeId <= 0 || $assignedStaffId <= 0 || $purpose === '') {
                throw new InvalidArgumentException('Resident, certificate type, assigned doctor/staff, and purpose are required.');
            }
            $types = $pdo->query("SELECT MIN(id) AS id, certificate_type AS certificate_type_name FROM certificates WHERE certificate_type IS NOT NULL AND TRIM(certificate_type) != '' GROUP BY certificate_type ORDER BY certificate_type")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $defaults = ['Medical Certificate', 'Sanitary Clearance', 'Health Clearance', 'Immunization Certificate', 'Barangay Health Certificate'];
            if (!$types) {
                foreach ($defaults as $index => $name) $types[] = ['id' => $index + 1, 'certificate_type_name' => $name];
            } else {
                $existingNames = array_map(static fn($type) => strtolower($type['certificate_type_name']), $types);
                $nextTypeId = 100;
                foreach ($defaults as $name) {
                    if (!in_array(strtolower($name), $existingNames, true)) $types[] = ['id' => $nextTypeId++, 'certificate_type_name' => $name];
                }
            }
            $typeName = '';
            foreach ($types as $type) {
                if ((int) $type['id'] === $certificateTypeId) { $typeName = $type['certificate_type_name']; break; }
            }
            if ($typeName === '') throw new RuntimeException('Certificate type is no longer available. Reload and select it again.');
            $staffCheck = $pdo->prepare("
                SELECT hw.id, hw.position_title AS staff_type, hw.role AS specialization, hw.email, CONCAT(hw.first_name, ' ', hw.last_name) AS name
                FROM health_workers hw
                WHERE hw.id = :staff
                  AND hw.status = 'Active'
                LIMIT 1
            ");
            $staffCheck->execute(['staff' => $assignedStaffId]);
            $selectedStaff = $staffCheck->fetch(pdo::FETCH_ASSOC);
            if (!$selectedStaff) {
                throw new RuntimeException('Selected doctor/staff must be an active health worker.');
            }
            if (!filter_var($selectedStaff['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('The selected staff account needs a valid email address before requesting approval.');
            }

            $certNo = 'RHU-' . date('Ymd-His') . '-' . str_pad((string)$residentId, 4, '0', STR_PAD_LEFT) . '-' . random_int(10, 99);
            $insert = $pdo->prepare("INSERT INTO certificates (resident_id, certificate_type, certificate_number, issue_date, expiry_date, issued_by_id, purpose, status, created_at) VALUES (:resident, :type, :number, :issue_date, :expiry_date, :doctor, :purpose, 'Pending Doctor Approval', NOW())");
            $insert->execute([
                'resident' => $residentId,
                'type' => $typeName,
                'number' => $certNo,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                'purpose' => $purpose,
                'doctor' => $assignedStaffId,
            ]);
            $certificateId = (int)$pdo->lastInsertId();

            $adminSignaturePath = $canUploadAdminSignature ? portalCertificateSignatureUploadPath($_FILES['admin_signature_image'] ?? []) : '';
            if ($adminSignaturePath !== '') {
                $targetDir = __DIR__ . '/uploads/signatures';
                if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
                @copy(__DIR__ . '/' . ltrim($adminSignaturePath, '/'), $targetDir . '/admin_' . $certificateId . '.png');
            }

            $adminEmail = resolveCurrentAdminEmail($pdo);
            if ($adminEmail === '') {
                throw new RuntimeException('No valid admin email found in the database for this account.');
            }

            $adminPerson = array_merge($certificateAdmin, [
                'signature_path' => $adminSignaturePath,
            ]);
            $doctorPerson = [
                'staff_id' => (int)$selectedStaff['id'],
                'user_id' => (int)$selectedStaff['id'],
                'email' => (string)($selectedStaff['email'] ?? ''),
            ];

            $approvalResults = portalSendCertificateApprovalRequests($pdo, $certificateId, $adminPerson, $doctorPerson);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Issued certificate {$certNo} and requested e-signature from {$selectedStaff['name']}", 'certificates', $certificateId);
            portalNotifyResident($pdo, $residentId, "{$typeName} {$certNo} was created by RHU admin and routed to {$selectedStaff['name']} for e-signature approval.", 'ResidentDashboard.php?tab=certificates');

            $acceptedRecipients = [];
            $deliveryErrors = [];
            foreach ($approvalResults as $approvalResult) {
                if (!empty($approvalResult['skipped'])) continue;
                if ($approvalResult['sent']) {
                    $acceptedRecipients[] = $approvalResult['email'];
                } else {
                    $deliveryErrors[] = $approvalResult['email'] . ': ' . ($approvalResult['error'] ?: 'Mail delivery failed.');
                }
            }
            $flashSuccess = "{$typeName} {$certNo} was created. " . ($acceptedRecipients ? 'Mail accepted for: ' . implode(', ', $acceptedRecipients) . '.' : 'No approval emails were sent.');
            if ($deliveryErrors) $flashError = implode(' ', $deliveryErrors);
        } catch (Throwable $e) {
            $flashError = 'Certificate Error: ' . $e->getMessage();
        }
    }

    // Action: Approve requested certificate
    if ($action === 'approve_certificate') {
        $certId = (int)($_POST['request_id'] ?? 0);
        if ($certId > 0) {
            try {
                $certStmt = $pdo->prepare("SELECT resident_id, certificate_type, purpose, issue_date, certificate_number, issued_by_id FROM certificates WHERE id = :id");
                $certStmt->execute(['id' => $certId]);
                $cert = $certStmt->fetch(pdo::FETCH_ASSOC);
                if (!$cert) throw new RuntimeException('Certificate request not found.');
                $certNo = $cert['certificate_number'] ?: ('CERT-' . date('Y') . '-' . str_pad((string)$certId, 4, '0', STR_PAD_LEFT));
                
                $doctorStaffId = (int)($cert['issued_by_id'] ?? 0);
                $doctorRoleSql = preg_match('/dental/i', (string) $cert['certificate_type'])
                    ? "(LOWER(CONCAT(position_title, ' ', role)) REGEXP 'dentist|dental doctor')"
                    : "(LOWER(CONCAT(position_title, ' ', role)) REGEXP 'doctor|physician|medical officer|health officer' AND LOWER(CONCAT(position_title, ' ', role)) NOT REGEXP 'dentist|dental')";
                $docCheck = $pdo->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name FROM health_workers WHERE id = :id AND status = 'Active' AND {$doctorRoleSql} LIMIT 1");
                $docCheck->execute(['id' => $doctorStaffId]);
                $docRow = $docCheck->fetch(pdo::FETCH_ASSOC);
                if (!$docRow) {
                    $docCheck = $pdo->query("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name FROM health_workers WHERE status = 'Active' AND {$doctorRoleSql} ORDER BY id ASC LIMIT 1");
                    $docRow = $docCheck->fetch(pdo::FETCH_ASSOC);
                }

                if (!$docRow || !filter_var($docRow['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('No active doctor with a valid email is available for certificate approval.');
                }
                $relatedStaff = [];
                $certificateType = trim((string) $cert['certificate_type']);
                if (rhuTableExists($pdo, 'certificate_doctor_assignments') && rhuTableExists($pdo, 'certificate_types')) {
                    $assignedStmt = $pdo->prepare("SELECT hw.id, hw.email FROM certificate_doctor_assignments a
                        JOIN certificate_types ct ON ct.id = a.certificate_type_id
                        JOIN health_workers hw ON hw.id = a.staff_id
                        WHERE a.is_active = 1 AND hw.status = 'Active'
                          AND LOWER(ct.certificate_type_name) = LOWER(:type)
                          AND (a.purpose_keyword = '' OR LOCATE(LOWER(a.purpose_keyword), LOWER(:purpose)) > 0)");
                    $assignedStmt->execute(['type' => $certificateType, 'purpose' => $cert['purpose'] ?? '']);
                    $relatedStaff = $assignedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                if (!$relatedStaff) {
                    $roles = preg_match('/birth|prenatal|pregnancy/i', $certificateType) ? 'midwife|nurse'
                        : (preg_match('/dental/i', $certificateType) ? 'dentist|dental' : 'nurse|doctor|physician|medical officer');
                    $relatedStmt = $pdo->prepare("SELECT id, email FROM health_workers WHERE status = 'Active'
                        AND LOWER(CONCAT(position_title, ' ', role)) REGEXP :roles");
                    $relatedStmt->execute(['roles' => $roles]);
                    $relatedStaff = $relatedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                $sentEmails = [];
                $failedEmails = [];

                if ($docRow) {
                    $adminEmail = resolveCurrentAdminEmail($pdo);
                    if ($adminEmail === '') {
                        throw new RuntimeException('No valid admin email found in the database for this account.');
                    }

                    $adminPerson = resolveCertificateAdmin($pdo);
                    $doctorPerson = [
                        'staff_id' => (int)$docRow['id'],
                        'user_id' => (int)$docRow['id'],
                        'email' => (string)($docRow['email'] ?? ''),
                    ];
                    $stmt = $pdo->prepare("UPDATE certificates SET status = 'Pending Doctor Approval', issued_by_id = :doctor, certificate_number = :cert_no, issue_date = COALESCE(issue_date, CURDATE()), updated_at = NOW() WHERE id = :id");
                    $stmt->execute(['doctor' => $docRow['id'], 'cert_no' => $certNo, 'id' => $certId]);
                    $approvalResults = portalSendCertificateApprovalRequests($pdo, $certId, $adminPerson, $doctorPerson);
                    foreach ($approvalResults as $result) {
                        if (!empty($result['skipped'])) continue;
                        $emailKey = strtolower(trim((string) $result['email']));
                        if ($result['sent']) $sentEmails[$emailKey] = true;
                        else $failedEmails[$emailKey] = true;
                    }
                    foreach ($relatedStaff as $worker) {
                        $email = trim((string) ($worker['email'] ?? ''));
                        $emailKey = strtolower($email);
                        if (isset($sentEmails[$emailKey]) || isset($failedEmails[$emailKey])) continue;
                        $body = '<p>A ' . esc($certificateType) . ' request requires review.</p>'
                            . '<p>Certificate reference: ' . esc($certNo) . '</p>'
                            . '<p>Please sign in to your staff dashboard to review the related resident records. Doctor approval has been requested separately.</p>';
                        $result = sendRHUEmail($email, 'RHU certificate review request - ' . $certificateType, $body);
                        portalRecordCertificateEmail($pdo, $certId, $email, 'staff review', 'RHU certificate review request - ' . $certificateType, $result);
                        if (!empty($result['success'])) $sentEmails[$emailKey] = true;
                        else $failedEmails[$emailKey] = true;
                    }
                }

                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Approved Certificate Request #{$certId}", 'certificates', $certId);
                portalNotifyResident($pdo, (int)$cert['resident_id'], "Your certificate request #{$certId} was approved by Admin and routed for doctor e-signature approval.", 'ResidentDashboard.php?tab=certificates');
                $flashSuccess = "Certificate request #{$certId} routed for approval. " . count($sentEmails) . ' email notification(s) sent.';
                if ($failedEmails) {
                    $flashError = 'Email delivery failed for: ' . implode(', ', array_keys($failedEmails)) . '. Check staff email addresses and SMTP settings. Use Retry Approval Emails after correcting them.';
                }
            } catch (Throwable $e) {
                $flashError = 'Certificate Error: ' . $e->getMessage();
            }
        }
    }

    if ($certificateApprovalApiRequest) {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $flashSuccess !== '' && $flashError === '',
            'message' => $flashSuccess, 'error' => $flashError ?: ($flashSuccess === '' ? 'No valid certificate request was submitted.' : '')]);
        exit;
    }

    if ($action === 'regenerate_certificate') {
        $certId = (int)($_POST['certificate_id'] ?? 0);
        if ($certId > 0) {
            try {
                $html = portalGenerateCertificateHtml($pdo, $certId);
                $stmt = $pdo->prepare("UPDATE certificates SET status = 'Draft' WHERE id = :id");
                $stmt->execute(['id' => $certId]);
                portalCertificateWorkflowLog($pdo, $certId, 'Certificate regenerated', 'Admin regenerated the certificate preview.');
                $flashSuccess = "Certificate #{$certId} was regenerated as Draft.";
            } catch (Throwable $e) {
                $flashError = 'Regenerate failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'send_final_certificate') {
        $certId = (int)($_POST['certificate_id'] ?? 0);
        if ($certId > 0) {
            try {
                $status = portalRefreshCertificateWorkflowStatus($pdo, $certId);
                if ($status !== 'Signed') throw new RuntimeException('Both administrator and doctor/staff must approve signature use before sending.');
                $stmt = $pdo->prepare("SELECT hc.certificate_number, r.email, CONCAT(r.first_name, ' ', r.last_name) AS resident_name FROM certificates hc JOIN residents r ON r.id = hc.resident_id WHERE hc.id = :id");
                $stmt->execute(['id' => $certId]);
                $cert = $stmt->fetch(pdo::FETCH_ASSOC);
                if (!$cert || empty($cert['email'])) throw new RuntimeException('Resident email is not available.');
                $downloadUrl = portalCertificateUrl(['certificate_pdf' => $certId]);
                $subject = 'Your RHU certificate is ready';
                $html = '<p>Your signed RHU certificate is approved and ready.</p><p><a href="' . esc($downloadUrl) . '">Download certificate</a></p>';
                $result = sendRHUEmail((string)$cert['email'], $subject, $html);
                portalRecordCertificateEmail($pdo, $certId, (string)$cert['email'], 'resident_signed_certificate', $subject, $result);
                $pdo->prepare("UPDATE certificates SET status = 'Approved & Issued' WHERE id = :id")->execute(['id' => $certId]);
                portalNotifyResident($pdo, (int)$pdo->query("SELECT resident_id FROM certificates WHERE id = {$certId}")->fetchColumn(), 'Your signed certificate is ready to download.', 'ResidentDashboard.php?tab=certificates');
                portalCertificateWorkflowLog($pdo, $certId, 'Certificate sent', 'Final signed certificate was emailed and released to the resident portal.');
                $flashSuccess = "Signed certificate was sent to the resident.";
            } catch (Throwable $e) {
                $flashError = 'Send failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'save_certificate_doctor_mapping') {
        $certificateTypeId = (int)($_POST['certificate_type_id'] ?? 0);
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $purposeKeyword = trim((string)($_POST['purpose_keyword'] ?? ''));
        if ($certificateTypeId > 0 && $staffId > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO certificate_doctor_assignments (certificate_type_id, purpose_keyword, staff_id, is_active) VALUES (:type, :keyword, :staff, 1) ON DUPLICATE KEY UPDATE is_active = 1");
                $stmt->execute(['type' => $certificateTypeId, 'keyword' => $purposeKeyword, 'staff' => $staffId]);
                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Updated certificate doctor assignment', 'certificate_doctor_assignments', $certificateTypeId);
                $flashSuccess = 'Certificate doctor/staff assignment saved.';
            } catch (Throwable $e) {
                $flashError = 'Mapping failed: ' . $e->getMessage();
            }
        } else {
            $flashError = 'Select a certificate type and doctor/staff.';
        }
    }

    if ($action === 'delete_certificate_doctor_mapping') {
        $mappingId = (int)($_POST['mapping_id'] ?? 0);
        if ($mappingId > 0) {
            $pdo->prepare("UPDATE certificate_doctor_assignments SET is_active = 0 WHERE id = :id")->execute(['id' => $mappingId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Removed certificate doctor assignment', 'certificate_doctor_assignments', $mappingId);
            $flashSuccess = 'Certificate doctor/staff assignment removed.';
        }
    }

    if ($action === 'delete_selected_certificates') {
        $certificateIds = [];
        foreach ((array) ($_POST['certificate_ids'] ?? []) as $selectedIds) {
            foreach (explode(',', (string) $selectedIds) as $selectedId) {
                if (ctype_digit($selectedId) && (int) $selectedId > 0) {
                    $certificateIds[] = (int) $selectedId;
                }
            }
        }
        $certificateIds = array_values(array_unique($certificateIds));
        if ($certificateIds) {
            $placeholders = implode(',', array_fill(0, count($certificateIds), '?'));
            $deleteCertificates = $pdo->prepare("DELETE FROM certificates WHERE id IN ({$placeholders})");
            $deleteCertificates->execute($certificateIds);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Deleted selected certificate requests', 'certificates', null);
            $flashSuccess = $deleteCertificates->rowCount() . ' certificate request(s) deleted.';
        } else {
            $flashError = 'Select at least one certificate request to delete.';
        }
    }

    // Action: Reject Certificate Request
    if ($action === 'reject_certificate') {
        $certId = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? 'Requirements incomplete');
        if ($certId > 0) {
            $residentId = (int)$pdo->query("SELECT resident_id FROM certificates WHERE id = {$certId}")->fetchColumn();
            $stmt = $pdo->prepare("UPDATE certificates SET status = 'Rejected' WHERE id = :id");
            $stmt->execute(['id' => $certId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Rejected Certificate Request #{$certId}", 'certificates', $certId);
            portalNotifyResident($pdo, $residentId, 'Your certificate request was rejected: ' . $reason, 'ResidentDashboard.php?tab=certificates');
            $flashSuccess = "Certificate request #{$certId} rejected.";
        }
    }

    // Action: Reply to Resident Message
    if ($action === 'reply_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');
        if ($msgId > 0 && $reply !== '') {
            $messageRow = null;
            $isStoredMessage = false;
            if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
                $messageStmt = $pdo->prepare("SELECT id, resident_id, subject, message, admin_reply FROM messages WHERE id = :id LIMIT 1");
                $messageStmt->execute(['id' => $msgId]);
                $messageRow = $messageStmt->fetch(pdo::FETCH_ASSOC) ?: null;
                $isStoredMessage = $messageRow !== null;
            }

            if (!$messageRow) {
                $messageStmt = $pdo->prepare("SELECT record_id AS resident_id, action AS subject FROM audit_logs WHERE id = :id LIMIT 1");
                $messageStmt->execute(['id' => $msgId]);
                $messageRow = $messageStmt->fetch(pdo::FETCH_ASSOC) ?: [];
            }

            $residentId = (int)($messageRow['resident_id'] ?? 0);
            $subject = trim((string)($messageRow['subject'] ?? 'your message')) ?: 'your message';

            if ($isStoredMessage) {
                $stmt = $pdo->prepare("UPDATE messages SET admin_reply = :reply, status = 'Replied', replied_at = NOW() WHERE id = :id");
            } else {
                $stmt = $pdo->prepare("UPDATE audit_logs SET description = CONCAT(description, ' [Replied: ', :reply, ']') WHERE id = :id");
            }
            $stmt->execute(['reply' => $reply, 'id' => $msgId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Replied to Resident Message #{$msgId}", 'messages', $msgId);
            if ($residentId > 0 && $stmt->rowCount() > 0) {
                portalNotifyResident($pdo, $residentId, "RHU admin replied to {$subject}: {$reply}", 'ResidentDashboard.php?tab=contact');
            }
            $flashSuccess = "Reply sent to resident message #{$msgId}.";
        }
    }

    // Action: Delete selected resident messages
    if ($action === 'delete_selected_messages') {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $_POST['message_ids'] ?? []))));
        if ($messageIds) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE id IN ({$placeholders})");
            $stmt->execute($messageIds);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Deleted selected resident messages', 'audit_logs', null);
            $flashSuccess = count($messageIds) . " resident message(s) deleted.";
        } else {
            $flashError = 'Select at least one message to delete.';
        }
    }

    // Action: Clean all resident messages
    if ($action === 'delete_all_messages') {
        portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Blocked bulk resident message cleanup', 'audit_logs', null);
        $flashError = 'Bulk message cleanup is disabled. Use Select Delete to remove chosen messages only.';
    }

    // Action: Confirm Event Registration
    if ($action === 'confirm_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            $stmt = $pdo->prepare("UPDATE events SET status = 'Confirmed' WHERE id = :id");
            $stmt->execute(['id' => $eventId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Confirmed Event #{$eventId}", 'events', $eventId);
            $flashSuccess = "Event #{$eventId} confirmed.";
        }
    }

    if ($action === 'mark_notifications_read') {
        $stmt = $pdo->prepare("UPDATE portal_notifications SET is_read = 1 WHERE user_id = :user_id OR audience_role IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')");
        $stmt->execute(['user_id' => (int)$_SESSION['user']['user_id']]);
        $flashSuccess = 'Notifications marked as read.';
    }

    if ($action === 'update_resident_status') {
        $id = (int)($_POST['resident_id'] ?? 0);
        $status = (int)($_POST['new_status'] ?? 0);
        $stmt = $pdo->prepare('UPDATE residents SET is_active = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        if ($id > 0) {
            portalNotifyResident($pdo, $id, 'Your RHU resident profile status was updated by admin: ' . ($status ? 'Active' : 'Inactive') . '.', 'ResidentDashboard.php?tab=profile');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated resident status', 'residents', $id);
        $flashSuccess = 'Resident status updated.';
    }

    if ($action === 'update_consultation') {
        $id = (int)($_POST['consultation_id'] ?? 0);
        $assignedConsultationStmt = $pdo->prepare('SELECT COALESCE(NULLIF(physician_id, 0), health_worker_id) FROM consultations WHERE id = :id LIMIT 1');
        $assignedConsultationStmt->execute(['id' => $id]);
        $assignedConsultationStaffId = (int)$assignedConsultationStmt->fetchColumn();
        if ($consultationEditorStaffId <= 0 || $assignedConsultationStaffId !== $consultationEditorStaffId) {
            http_response_code(403);
            exit('Only the assigned staff member or health worker may edit this consultation.');
        }
        $diag = trim($_POST['diagnosis'] ?? '');
        $plan = trim($_POST['treatment_plan'] ?? '');
        $status = trim($_POST['consultation_status'] ?? 'Scheduled');

        $updateColumns = ['diagnosis = :diagnosis'];
        $updateParams = [
            'diagnosis' => $diag,
            'id' => $id,
        ];
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'treatment_plan')) {
            $updateColumns[] = 'treatment_plan = :treatment_plan';
            $updateParams['treatment_plan'] = $plan;
        } elseif (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'treatment')) {
            $updateColumns[] = 'treatment = :treatment_plan';
            $updateParams['treatment_plan'] = $plan;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_notes')) {
            $updateColumns[] = 'consultation_notes = :notes';
            $updateParams['notes'] = $plan;
        } elseif (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'remarks')) {
            $updateColumns[] = 'remarks = :notes';
            $updateParams['notes'] = $plan;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_status')) {
            $updateColumns[] = 'consultation_status = :status';
            $updateParams['status'] = $status;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'updated_at')) {
            $updateColumns[] = 'updated_at = NOW()';
        }

        $updateParams['assigned_staff_id'] = $consultationEditorStaffId;
        $stmt = $pdo->prepare('UPDATE consultations SET ' . implode(', ', $updateColumns) . ' WHERE id = :id AND COALESCE(NULLIF(physician_id, 0), health_worker_id) = :assigned_staff_id');
        $stmt->execute($updateParams);
        if ($stmt->rowCount() === 0) {
            exit('No changes saved. The record may be unchanged or its assignment may have changed.');
        }
        $consultationLookup = $pdo->prepare('SELECT resident_id, chief_complaint, consultation_date, health_worker_id, physician_id FROM consultations WHERE id = :id LIMIT 1');
        $consultationLookup->execute(['id' => $id]);
        $consultationRow = $consultationLookup->fetch(PDO::FETCH_ASSOC) ?: [];
        $residentId = (int)($consultationRow['resident_id'] ?? 0);
        try { fhsisSaveInlineServices($pdo, 'consultations', $id, $residentId, $_POST, $_SESSION); }
        catch (Throwable $error) { http_response_code(400); exit(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')); }
        $autoDiseaseCase = portalAutoSaveDiseaseCaseFromConsultation($pdo, $id, [
            'resident_id' => $residentId,
            'chief_complaint' => $consultationRow['chief_complaint'] ?? '',
            'consultation_date' => $consultationRow['consultation_date'] ?? '',
            'diagnosis' => $diag,
            'remarks' => $plan,
            'consultation_notes' => $plan,
            'health_worker_id' => $consultationRow['health_worker_id'] ?? null,
            'physician_id' => $consultationRow['physician_id'] ?? null,
        ]);
        if ($residentId > 0) {
            rhuNotifySavedResidentAppointment($pdo, $id, 'RHU appointment');
            $statusText = $status !== '' ? $status : 'updated';
            portalNotifyResident($pdo, $residentId, "Your RHU consultation was updated. Status: {$statusText}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated consultation', 'consultations', $id);
        $flashSuccess = 'Consultation updated.' . (($autoDiseaseCase && !empty($autoDiseaseCase['created'])) ? ' Disease case auto-saved: ' . $autoDiseaseCase['disease_name'] . '.' : '');
    }

    if ($action === 'update_pregnancy') {
        $id = (int)($_POST['pregnancy_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'Active');
        $stmt = $pdo->prepare('UPDATE pregnancy_records SET pregnancy_status = :status, high_risk = :high_risk WHERE id = :id');
        $stmt->execute(['status' => $status, 'high_risk' => isset($_POST['high_risk']) ? 1 : 0, 'id' => $id]);
        $pregnancyResidentId = (int)($pdo->query("SELECT resident_id FROM pregnancy_records WHERE id = {$id}")->fetchColumn() ?: 0);
        if ($pregnancyResidentId > 0) {
            portalNotifyResident($pdo, $pregnancyResidentId, "Your maternal health record was updated by RHU admin. Status: {$status}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated maternal case', 'pregnancy_records', $id);
        $flashSuccess = 'Maternal case updated.';
    }

    if ($action === 'update_maternal_referral') {
        $id = (int)($_POST['referral_id'] ?? 0);
        $status = trim($_POST['referral_status'] ?? 'Pending');
        $stmt = $pdo->prepare('UPDATE maternal_referrals SET referral_status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        $residentStmt = $pdo->prepare('SELECT resident_id, referred_to FROM maternal_referrals WHERE id = :id');
        $residentStmt->execute(['id' => $id]);
        if ($referral = $residentStmt->fetch(pdo::FETCH_ASSOC)) {
            portalNotifyResident($pdo, (int)$referral['resident_id'], "Your referral to {$referral['referred_to']} is now {$status}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated maternal referral', 'maternal_referrals', $id);
        $flashSuccess = 'Maternal referral updated.';
    }

    if ($action === 'update_disease_case') {
        $id = (int)($_POST['case_id'] ?? 0);
        $classification = trim($_POST['classification'] ?? 'Suspected');
        $outcome = trim($_POST['outcome'] ?? '');
        $stmt = $pdo->prepare('UPDATE disease_cases SET case_classification = :classification, outcome = :outcome, reported_to_doh = :reported WHERE id = :id');
        $stmt->execute([
            'classification' => $classification,
            'outcome' => $outcome,
            'reported' => isset($_POST['reported_to_doh']) ? 1 : 0,
            'id' => $id,
        ]);
        $caseResidentId = (int)($pdo->query("SELECT resident_id FROM disease_cases WHERE id = {$id}")->fetchColumn() ?: 0);
        if ($caseResidentId > 0) {
            $caseStatus = $outcome !== '' ? "{$classification}, outcome: {$outcome}" : $classification;
            portalNotifyResident($pdo, $caseResidentId, "Your disease surveillance record was updated by RHU admin. Status: {$caseStatus}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated disease surveillance case', 'disease_cases', $id);
        $flashSuccess = 'Disease case updated.';
    }

    if ($action === 'adjust_medicine_stock') {
        if (($_POST['stock_mode'] ?? '') !== 'give' && !inventoryIsAdmin()) { http_response_code(403); exit('Only the administrator can add or adjust stock.'); }
        $flashError = 'Use Inventory / Stock History to record the quantity, location and purpose of the stock movement.';
    }

    if ($action === 'update_smtp_settings') {
        portalSaveSettings($pdo, [
            'smtp_host' => $_POST['smtp_host'] ?? '',
            'smtp_port' => $_POST['smtp_port'] ?? '587',
            'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
            'smtp_user' => $_POST['smtp_user'] ?? '',
            'two_factor_enabled' => isset($_POST['two_factor_enabled']) ? '1' : '0',
        ]);
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated mail and 2FA settings', 'portal_settings', null);
        $flashSuccess = 'Mail and 2FA settings saved.';
    }

    if ($action === 'create_scheduled_event') {
        $title = trim($_POST['title'] ?? '');
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        if ($title !== '' && $scheduledDate !== '') {
            $stmt = $pdo->prepare(
                "INSERT INTO events (title, description, location, start_datetime, organizer_id, status, created_at)
                 VALUES (:title, :description, :location, :start_datetime, :organizer_id, 'Scheduled', NOW())"
            );
            $stmt->execute([
                'title' => $title,
                'description' => trim($_POST['description'] ?? ''),
                'location' => trim($_POST['venue'] ?? 'Nasugbu RHU'),
                'start_datetime' => $scheduledDate . ' ' . (($_POST['start_time'] ?? '') ?: '08:00') . ':00',
                'organizer_id' => (int)($_SESSION['user']['user_id'] ?? 0) ?: null,
            ]);
            portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Created RHU event', 'events', (int)$pdo->lastInsertId());
            portalNotify($pdo, "New RHU event scheduled: {$title}", null, 'RESIDENT', 'ResidentDashboard.php?tab=events');
            $flashSuccess = 'Event created and published to the Resident Portal.';
        }
    }

    if ($action === 'update_user_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        $roleNames = [1 => 'PHYSICIAN', 2 => 'NURSE', 3 => 'MIDWIFE', 4 => 'MEDTECH', 5 => 'SANITARY_INSPECTOR', 6 => 'BHW', 7 => 'ADMIN_STAFF', 8 => 'DENTAL'];
        $newRoleName = (string)($roleNames[$roleId] ?? trim((string)($_POST['role_name'] ?? '')));
        $stmt = $pdo->prepare('UPDATE health_workers SET role = :role_name, position_title = :position_title, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'role_name' => $newRoleName !== '' ? $newRoleName : 'Staff',
            'position_title' => $newRoleName !== '' ? $newRoleName : 'Staff',
            'id' => $userId,
        ]);
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Changed user role', 'health_workers', $userId);
        $flashSuccess = 'User role updated.';
    }

    // Action: Toggle User Status
    if ($action === 'toggle_user_status') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        if ($targetUserId > 0) {
            $stmt = $pdo->prepare("UPDATE health_workers SET status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['status' => $newStatus ? 'Active' : 'Inactive', 'id' => $targetUserId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Toggled User #{$targetUserId} status to " . ($newStatus ? 'Active' : 'Inactive'), 'health_workers', $targetUserId);
            $flashSuccess = "User status updated successfully.";
        }
    }

    // Action: Delete User
    if ($action === 'delete_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId > 0 && $targetUserId !== (int)($_SESSION['user']['user_id'] ?? 0)) {
            $stmt = $pdo->prepare("DELETE FROM health_workers WHERE id = :id");
            $stmt->execute(['id' => $targetUserId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted User #{$targetUserId}", 'health_workers', $targetUserId);
            $flashSuccess = "User account removed from database.";
        }
    }

    // Action: Toggle Staff Status
    if ($action === 'toggle_staff_status') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        if ($staffId > 0) {
            $stmt = $pdo->prepare("UPDATE health_workers SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $newStatus ? 'Active' : 'Inactive', 'id' => $staffId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Toggled Staff #{$staffId} status to " . ($newStatus ? 'Active' : 'Inactive'), 'health_workers', $staffId);
            $flashSuccess = "Staff account status updated.";
        }
    }

    // Action: Create New Healthcare Staff / BHW Account
    if ($action === 'create_staff') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $staffType = trim($_POST['staff_type'] ?? 'ADMIN_STAFF');
        $specialization = trim($_POST['specialization'] ?? '');
        $licenseNumber = trim($_POST['license_number'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $customCertNumber = trim($_POST['cert_number'] ?? '');

        if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
            $flashError = "Please fill in all required fields.";
        } else {
            try {
                $pdo->beginTransaction();
                $check = $pdo->prepare("SELECT id FROM health_workers WHERE email = :email LIMIT 1");
                $check->execute(['email' => $email]);
                if ($check->fetch()) {
                    throw new Exception("An account with email {$email} already exists.");
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $username = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0])) . rand(100, 999);

                $insUser = $pdo->prepare("INSERT INTO health_workers (username, email, password_hash, first_name, last_name, role, position_title, license_number, contact_number, status, created_at) VALUES (:u, :e, :h, :f, :l, :role_name, :position_title, :license_number, :contact_number, 'Active', NOW())");
                $insUser->execute([
                    'u' => $username,
                    'e' => $email,
                    'h' => $hash,
                    'f' => $firstName,
                    'l' => $lastName,
                    'role_name' => $staffType,
                    'position_title' => $staffType,
                    'license_number' => $licenseNumber,
                    'contact_number' => $phone,
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                $newStaffId = $newUserId;

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Created new Healthcare Staff Account for {$email} ({$staffType})", 'health_workers', $newStaffId);
                $flashSuccess = "New healthcare staff account created successfully for {$firstName} {$lastName}!";
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flashError = $ex->getMessage();
            }
        }
    }

    // Action: Publish Announcement for Landing Page
    if ($action === 'create_announcement') {
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'Health Awareness');
        $content = trim($_POST['content'] ?? '');
        $badgeText = trim($_POST['badge_text'] ?? 'Awareness');
        $postedBy = trim($_SESSION['user']['username'] ?? 'MHO Admin');
        $imageUrl = '';
        $imageUploadError = '';
        $isPopup = !empty($_POST['is_popup']) ? 1 : 0;

        if (isset($_FILES['announcement_image']) && ($_FILES['announcement_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                if (($_FILES['announcement_image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('The announcement image upload failed. Please choose an image smaller than 2 MB and try again.');
                }
                $imageUrl = portalPublicAssetUrl(portalSaveUploadedImage($_FILES['announcement_image']['tmp_name'], $_FILES['announcement_image']['name'] ?? 'announcement.jpg', 'announcements'));
            } catch (Throwable $e) {
                $imageUploadError = $e->getMessage();
            }
        }

        if ($imageUploadError !== '') {
            $flashError = $imageUploadError;
        } elseif ($title !== '' && $content !== '') {
            $previousAnnouncementImages = [];
            try {
                $oldImagesStmt = $pdo->query("SELECT image_url FROM portal_announcements WHERE image_url IS NOT NULL AND TRIM(image_url) != ''");
                $previousAnnouncementImages = $oldImagesStmt ? array_map('strval', $oldImagesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
                foreach ($previousAnnouncementImages as $oldAnnouncementImage) {
                    $oldAnnouncementImage = (string)$oldAnnouncementImage;
                    if ($imageUrl !== $oldAnnouncementImage && str_starts_with($oldAnnouncementImage, 'uploads/announcements/')) {
                        portalDeleteUploadedImage($oldAnnouncementImage);
                    }
                }
                $pdo->exec("DELETE FROM portal_announcements");
            } catch (Throwable $announcementReplaceError) {
                error_log('Replace announcement cleanup failed: ' . $announcementReplaceError->getMessage());
            }
            $stmt = $pdo->prepare("INSERT INTO portal_announcements
                (title, category, content, badge_text, image_url, is_popup, is_active, posted_by, created_at, updated_at)
                VALUES (:title, :category, :content, :badge_text, :image_url, :is_popup, 1, :posted_by, NOW(), NOW())");
            $stmt->execute([
                'title' => $title,
                'category' => $category,
                'content' => $content,
                'badge_text' => $badgeText,
                'image_url' => $imageUrl,
                'is_popup' => $isPopup,
                'posted_by' => $postedBy,
            ]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Published Landing Page Awareness Post: {$title}", 'audit_logs', (int)$pdo->lastInsertId());
            portalNotify($pdo, "RHU announcement: {$title}", null, 'RESIDENT', 'ResidentDashboard.php?tab=home');
            $flashSuccess = "Health Awareness & Announcement post published live to Landing Page!";
        } else {
            $flashError = "Please provide both title and content for the announcement.";
        }
    }

    // Action: Delete Announcement
    if ($action === 'delete_announcement') {
        $annId = (int)($_POST['announcement_id'] ?? 0);
        if ($annId > 0) {
            $imageStmt = $pdo->prepare("SELECT image_url FROM portal_announcements WHERE id = :id LIMIT 1");
            $imageStmt->execute(['id' => $annId]);
            $announcementImage = (string)($imageStmt->fetchColumn() ?: '');
            if ($announcementImage !== '' && str_starts_with($announcementImage, 'uploads/announcements/')) {
                portalDeleteUploadedImage($announcementImage);
            }
            $stmt = $pdo->prepare("DELETE FROM portal_announcements WHERE id = :id");
            $stmt->execute(['id' => $annId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted Announcement #{$annId}", 'portal_announcements', $annId);
            $flashSuccess = "Announcement removed from Landing Page.";
        }
    }

    // Action: Create Health Event
    if ($action === 'create_event') {
        ensurePortalTables($pdo);
        $eventDateInput = trim($_POST['event_date'] ?? '');
        $eventDateObject = DateTime::createFromFormat('!Y-m-d', $eventDateInput);
        $eventDateIsValid = $eventDateObject instanceof DateTime
            && $eventDateObject->format('Y-m-d') === $eventDateInput;
        $eventDate = $eventDateIsValid ? $eventDateObject->format('M d, Y') : '';
        $title = trim($_POST['title'] ?? '');
        $barangayId = (int)($_POST['barangay_id'] ?? 0);
        $venue = '';
        if ($barangayId > 0) {
            $barangayStmt = $pdo->prepare("SELECT name FROM barangays WHERE id = :id LIMIT 1");
            $barangayStmt->execute(['id' => $barangayId]);
            $venue = trim((string)($barangayStmt->fetchColumn() ?: ''));
        }
        $description = trim($_POST['description'] ?? '');
        $badgeColor = trim($_POST['badge_color'] ?? 'bg-emerald-500');
        $imageUrl = '';
        $imageUploadError = '';

        if (isset($_FILES['event_image']) && ($_FILES['event_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                if (($_FILES['event_image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('The event cover picture upload failed. Please choose an image smaller than 2 MB and try again.');
                }
                $imageUrl = portalPublicAssetUrl(portalSaveUploadedImage($_FILES['event_image']['tmp_name'], $_FILES['event_image']['name'] ?? 'event.jpg', 'events'));
            } catch (Throwable $e) {
                $imageUploadError = $e->getMessage();
            }
        }

        if ($imageUploadError !== '') {
            $flashError = $imageUploadError;
        } elseif ($title !== '' && $eventDate !== '' && $venue !== '') {
            $stmt = $pdo->prepare("INSERT INTO portal_events
                (event_date, title, venue, description, image_url, badge_color, scheduled_date, start_time, status, is_active, created_at)
                VALUES (:event_date, :title, :venue, :description, :image_url, :badge_color, :scheduled_date, '08:00:00', 'Scheduled', 1, NOW())");
            $stmt->execute([
                'event_date' => $eventDate,
                'title' => $title,
                'venue' => $venue,
                'description' => $description,
                'image_url' => $imageUrl,
                'badge_color' => $badgeColor,
                'scheduled_date' => $eventDateInput,
            ]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Created Health Event: {$title}", 'portal_events', (int)$pdo->lastInsertId());
            portalNotify($pdo, "New RHU health event: {$title} at {$venue} on {$eventDate}", null, 'RESIDENT', 'ResidentDashboard.php?tab=events');
            $flashSuccess = "Health Event with picture published live to Landing Page!";
        } else {
            $flashError = "Please select a valid event date, enter a title, and choose a barangay.";
        }
    }

    // Action: Delete Health Event (and attached uploaded picture)
    if ($action === 'delete_event') {
        $evtId = (int)($_POST['event_id'] ?? 0);
        if ($evtId > 0) {
            $imageStmt = $pdo->prepare("SELECT image_url FROM portal_events WHERE id = :id LIMIT 1");
            $imageStmt->execute(['id' => $evtId]);
            $eventImage = (string)($imageStmt->fetchColumn() ?: '');
            if ($eventImage !== '' && str_starts_with($eventImage, 'uploads/events/')) {
                portalDeleteUploadedImage($eventImage);
            }
            $stmt = $pdo->prepare("DELETE FROM portal_events WHERE id = :id");
            $stmt->execute(['id' => $evtId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted Health Event #{$evtId}", 'portal_events', $evtId);
            $flashSuccess = "Health event and attached picture deleted successfully.";
        }
    }

    // Action: Delete Event Cover Picture Only
    if ($action === 'delete_event_picture') {
        $evtId = (int)($_POST['event_id'] ?? 0);
        if ($evtId > 0) {
            $imageStmt = $pdo->prepare("SELECT image_url FROM portal_events WHERE id = :id LIMIT 1");
            $imageStmt->execute(['id' => $evtId]);
            $eventImage = (string)($imageStmt->fetchColumn() ?: '');
            if ($eventImage !== '' && str_starts_with($eventImage, 'uploads/events/')) {
                portalDeleteUploadedImage($eventImage);
            }
            $stmt = $pdo->prepare("UPDATE portal_events SET image_url = '' WHERE id = :id");
            $stmt->execute(['id' => $evtId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted Health Event Picture #{$evtId}", 'portal_events', $evtId);
            $flashSuccess = "Event cover picture removed successfully.";
        }
    }

    // Action: Add Municipal Official to portal_settings
    if ($action === 'add_official') {
        $name = trim($_POST['official_name'] ?? '');
        $position = trim($_POST['official_position'] ?? '');
        $office = trim($_POST['official_office'] ?? '');

        if ($name !== '' && $position !== '') {
            $settings = portalSettings($pdo);
            $currentOfficials = [];
            if (!empty($settings['rhu_municipal_officials'])) {
                $currentOfficials = json_decode($settings['rhu_municipal_officials'], true) ?: [];
            }
            $currentOfficials[] = [
                'name' => $name,
                'position' => $position,
                'office' => $office ?: 'LGU Nasugbu'
            ];
            $settings['rhu_municipal_officials'] = json_encode($currentOfficials, JSON_UNESCAPED_SLASHES);
            portalSaveSettings($pdo, $settings);
            $settingStmt = $pdo->prepare("REPLACE INTO portal_settings (setting_key, setting_value) VALUES ('rhu_municipal_officials', :setting_value)");
            $settingStmt->execute(['setting_value' => $settings['rhu_municipal_officials']]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Added Municipal Official: {$name}", 'portal_settings', 1);
            $flashSuccess = "Municipal Official '{$name}' added and updated live on Landing Page!";
        } else {
            $flashError = "Please enter official name and position.";
        }
    }

    // Action: Delete Municipal Official
    if ($action === 'delete_official') {
        $index = (int)($_POST['official_index'] ?? -1);
        $settings = portalSettings($pdo);
        if (!empty($settings['rhu_municipal_officials'])) {
            $currentOfficials = json_decode($settings['rhu_municipal_officials'], true) ?: [];
            if (isset($currentOfficials[$index])) {
                $removed = $currentOfficials[$index]['name'] ?? 'Official';
                array_splice($currentOfficials, $index, 1);
                $settings['rhu_municipal_officials'] = json_encode($currentOfficials, JSON_UNESCAPED_SLASHES);
                portalSaveSettings($pdo, $settings);
                $settingStmt = $pdo->prepare("REPLACE INTO portal_settings (setting_key, setting_value) VALUES ('rhu_municipal_officials', :setting_value)");
                $settingStmt->execute(['setting_value' => $settings['rhu_municipal_officials']]);
                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Removed Municipal Official #{$index}", 'portal_settings', 1);
                $flashSuccess = "Municipal Official '{$removed}' removed from Landing Page.";
            }
        }
    }

    // Action: Add Photo to Landing Page Event Gallery (stored in portal_settings)
    if ($action === 'add_gallery_photo') {
        $photoTitle = trim($_POST['photo_title'] ?? '');
        $photoCategory = trim($_POST['photo_category'] ?? 'Event Photo');
        $photoDate = trim($_POST['photo_date'] ?? date('M Y'));
        $imageUrl = trim($_POST['image_url'] ?? '');

        if (!empty($_FILES['gallery_file']['tmp_name'])) {
            try {
                $imageUrl = portalPublicAssetUrl(portalSaveUploadedImage($_FILES['gallery_file']['tmp_name'], $_FILES['gallery_file']['name'] ?? 'gallery.jpg', 'events'));
            } catch (Throwable $e) {
                $imageUrl = '';
            }
        }

        if ($photoTitle !== '' && $imageUrl !== '') {
            $currentGallery = getPortalEventGallery($pdo);
            array_unshift($currentGallery, [
                'title' => $photoTitle,
                'category' => $photoCategory,
                'image_url' => $imageUrl,
                'date' => $photoDate
            ]);
            $settings = portalSettings($pdo);
            $settings['rhu_event_gallery'] = json_encode($currentGallery, JSON_UNESCAPED_SLASHES);
            portalSaveSettings($pdo, $settings);
            $settingStmt = $pdo->prepare("REPLACE INTO portal_settings (setting_key, setting_value) VALUES ('rhu_event_gallery', :setting_value)");
            $settingStmt->execute(['setting_value' => $settings['rhu_event_gallery']]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Added Event Gallery Photo: {$photoTitle}", 'portal_settings', 1);
            $flashSuccess = "Event photo '{$photoTitle}' published to Landing Page gallery!";
        } else {
            $flashError = "Please provide photo title and image file/URL.";
        }
    }

    // Action: Delete Photo from Landing Page Event Gallery
    if ($action === 'delete_gallery_photo') {
        $index = (int)($_POST['photo_index'] ?? -1);
        $currentGallery = getPortalEventGallery($pdo);
        if (isset($currentGallery[$index])) {
            $removed = $currentGallery[$index]['title'] ?? 'Photo';
            $imgFile = $currentGallery[$index]['image_url'] ?? '';

            if (!empty($imgFile) && str_starts_with($imgFile, 'uploads/events/')) {
                portalDeleteUploadedImage($imgFile);
            }

            array_splice($currentGallery, $index, 1);
            $settings = portalSettings($pdo);
            $settings['rhu_event_gallery'] = json_encode($currentGallery, JSON_UNESCAPED_SLASHES);
            portalSaveSettings($pdo, $settings);
            $settingStmt = $pdo->prepare("REPLACE INTO portal_settings (setting_key, setting_value) VALUES ('rhu_event_gallery', :setting_value)");
            $settingStmt->execute(['setting_value' => $settings['rhu_event_gallery']]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted Gallery Photo #{$index}", 'portal_settings', 1);
            $flashSuccess = "Picture '{$removed}' deleted successfully from gallery and server.";
        }
    }

    if (empty($flashSuccess) && empty($flashError) && function_exists('adminExtendedAction')) {
        try {
            $msg = adminExtendedAction($pdo, $action, (int)($_SESSION['user']['user_id'] ?? 0));
            if ($msg) {
                $flashSuccess = $msg;
            }
        } catch (Exception $e) {
            $flashError = 'Action failed: ' . $e->getMessage();
        }
    }
}


// RHU Profile Settings
$savedPortalSettings = portalSettings($pdo);
$rhuProfile = [
    'name' => portalSetting($savedPortalSettings, 'rhu_name', 'Nasugbu Rural Health Unit I'),
    'mho_name' => portalSetting($savedPortalSettings, 'rhu_mho_name', 'Municipal Health Officer'),
    'municipality' => portalSetting($savedPortalSettings, 'rhu_municipality', 'Nasugbu'),
    'province' => portalSetting($savedPortalSettings, 'rhu_province', 'Batangas'),
    'contact' => portalSetting($savedPortalSettings, 'rhu_contact_number', '(043) 416-1234'),
    'email' => portalSetting($savedPortalSettings, 'rhu_email', 'rhu.nasugbu@batangas.gov.ph'),
];

// ----------------------------------------------------
// 2. FETCH REAL DATABASE RECORDS
// ----------------------------------------------------
$totalUsersCount = 0;
$totalStaffCount = 0;
$totalBhwCount = 0;
$totalResidentsCount = 0;

$dbUsersList = [];
$dbStaffList = [];
$dbResidentList = [];
$dbAuditLogsList = [];
$dbCertificatesList = [];
$adminCertificateTypes = [];
$adminResidentCertificateOptions = [];
$adminResidentCertificateRequests = [];
$certificateDoctorMappings = [];
$certificateDoctorOptions = [];
$dbMessagesList = [];
$dbEventsList = [];
$dbAnnouncementsList = [];
$dbPortalEventsList = [];
$dbBarangayOptions = [];
$adminNotifications = [];

// Clinical data
$dbConsultationsList = [];
$dbHealthProfileRecords = [];
$dbMaternalCases = [];
$dbVaccinationRecords = [];
$dbFamilyPlanningRecords = [];
$dbMaternalReferrals = [];
$dbDiseaseCases = [];
$dbDentalRecords = [];
$dbMedicineInventory = [];
$dbVitalStatistics = [];

$barangayStats = [];
$staffTypeStats = [];
$roleStats = [];
$dbRoles = [];
$consultationStats = [];
$maternalStats = [];
$vaccinationStats = [];
$diseaseStats = [];
$medicineStats = [];
$reportsSelectedBarangay = trim((string)($_GET['analytics_barangay'] ?? ''));
$reportsSelectedDisease = trim((string)($_GET['analytics_disease'] ?? ''));
$reportsMapStats = [];
$reportsDiseaseOptions = [];
$reportsDiseaseByBarangay = [];
$reportsStaffUserMix = [];
$reportsMonthlySignals = [];
$databaseHealth = ['name' => rhuEnv('DB_NAME', 'rhu'), 'version' => 'Unavailable', 'tables' => 0];

if (!empty($pdo)) {
    try {
        $databaseHealth['version'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $databaseHealth['tables'] = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $totalUsersCount = (int)$pdo->query("SELECT COUNT(*) FROM health_workers")->fetchColumn();
        $totalStaffCount = (int)$pdo->query("SELECT COUNT(*) FROM health_workers WHERE status = 'Active'")->fetchColumn();
        $totalBhwCount = (int)$pdo->query("SELECT COUNT(*) FROM health_workers WHERE (UPPER(position_title) LIKE '%BHW%' OR UPPER(position_title) LIKE '%BARANGAY HEALTH%') AND status = 'Active'")->fetchColumn();
        $totalResidentsCount = (int)$pdo->query("SELECT COUNT(*) FROM residents")->fetchColumn();
        try {
            $dbBarangayOptions = $pdo->query(
                "SELECT id, name FROM barangays ORDER BY name"
            )->fetchAll(pdo::FETCH_ASSOC) ?: [];
        } catch (Throwable $barangayLoadError) {
            error_log('Admin barangay options: ' . $barangayLoadError->getMessage());
        }

        $uStmt = $pdo->query("
            SELECT id, username, email, first_name, last_name, (status = 'Active') AS is_active, created_at, last_login_at AS last_login, role AS role_name
            FROM health_workers
            ORDER BY id DESC LIMIT 100
        ");
        $dbUsersList = $uStmt->fetchAll(pdo::FETCH_ASSOC);

        $sStmt = $pdo->query("
            SELECT id, id AS user_id, first_name, last_name, email, position_title AS staff_type, role AS specialization, license_number, NULL AS license_expiry, contact_number AS phone_number, '' AS address, (status = 'Active') AS is_active, DATE(created_at) AS date_hired
            FROM health_workers
            ORDER BY id DESC LIMIT 100
        ");
        $dbStaffList = $sStmt->fetchAll(pdo::FETCH_ASSOC);

        $rStmt = $pdo->query("
            SELECT r.id, r.first_name, r.last_name, r.middle_name, r.birthdate AS date_of_birth, r.sex AS sex, r.contact_number, r.email, COALESCE(b.name, '') AS barangay, r.address, r.philhealth_number AS philhealth_id, COALESCE(hr.blood_type, '') AS blood_type, r.created_at, (r.status = 'Active') AS is_active, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN (
                SELECT resident_id, MAX(blood_type) AS blood_type
                FROM health_records
                GROUP BY resident_id
            ) hr ON hr.resident_id = r.id
            ORDER BY r.id DESC LIMIT 200
        ");
        $dbResidentList = $rStmt->fetchAll(pdo::FETCH_ASSOC);
        $residentCertificateOptionsById = [];
        foreach ($dbResidentList as $resident) {
            $residentId = (int)($resident['id'] ?? 0);
            if ($residentId <= 0 || (int)($resident['is_active'] ?? 1) !== 1 || isset($residentCertificateOptionsById[$residentId])) {
                continue;
            }
            $residentCertificateOptionsById[$residentId] = [
                'id' => $residentId,
                'name' => trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')) ?: 'Resident #' . $residentId,
                'barangay' => $resident['barangay'] ?? '',
            ];
        }
        $adminResidentCertificateOptions = array_values($residentCertificateOptionsById);
        $dbCertTypes = $pdo->query("SELECT MIN(id) AS id, certificate_type AS certificate_type_name FROM certificates WHERE certificate_type IS NOT NULL AND TRIM(certificate_type) != '' GROUP BY certificate_type ORDER BY certificate_type")->fetchAll(pdo::FETCH_ASSOC) ?: [];
        $defaultCertTypes = [
            ['id' => 1, 'certificate_type_name' => 'Medical Certificate'],
            ['id' => 2, 'certificate_type_name' => 'Sanitary Clearance'],
            ['id' => 3, 'certificate_type_name' => 'Health Clearance'],
            ['id' => 4, 'certificate_type_name' => 'Immunization Certificate'],
            ['id' => 5, 'certificate_type_name' => 'Barangay Health Certificate']
        ];
        if (empty($dbCertTypes)) {
            $adminCertificateTypes = $defaultCertTypes;
        } else {
            $adminCertificateTypes = $dbCertTypes;
            $existingNames = array_map(static fn($t) => strtolower($t['certificate_type_name']), $adminCertificateTypes);
            $nextId = 100;
            foreach ($defaultCertTypes as $dt) {
                if (!in_array(strtolower($dt['certificate_type_name']), $existingNames, true)) {
                    $adminCertificateTypes[] = ['id' => $nextId++, 'certificate_type_name' => $dt['certificate_type_name']];
                }
            }
        }
        $requestStmt = $pdo->query("SELECT id, resident_id, certificate_type, purpose
            FROM certificates
            WHERE status IN ('Pending', 'Draft', 'Requested', 'Pending Approval')
            ORDER BY id DESC");
        foreach ($requestStmt->fetchAll(pdo::FETCH_ASSOC) ?: [] as $request) {
            $residentId = (int)$request['resident_id'];
            if (!isset($adminResidentCertificateRequests[$residentId])) {
                $adminResidentCertificateRequests[$residentId] = [
                    'id' => (int)$request['id'],
                    'certificate_type_name' => (string)($request['certificate_type'] ?? ''),
                    'purpose' => (string)($request['purpose'] ?? ''),
                ];
            }
        }
        $certificateDoctorOptions = $pdo->query("
            SELECT id, position_title AS staff_type, role AS specialization, '' AS work_days, 1 AS is_on_duty,
                   CONCAT(first_name, ' ', last_name) AS name, email
            FROM health_workers
            WHERE status = 'Active'
            ORDER BY position_title, first_name, last_name
        ")->fetchAll(pdo::FETCH_ASSOC) ?: [];
        $certificateDoctorMappings = [];

        $auditHasHealthWorkerId = function_exists('rhuColumnExists') ? rhuColumnExists($pdo, 'audit_logs', 'health_worker_id') : true;
        $auditHasUserId = function_exists('rhuColumnExists') ? rhuColumnExists($pdo, 'audit_logs', 'user_id') : false;
        $auditHasModuleName = function_exists('rhuColumnExists') ? rhuColumnExists($pdo, 'audit_logs', 'module_name') : true;
        $auditHasEntityType = function_exists('rhuColumnExists') ? rhuColumnExists($pdo, 'audit_logs', 'entity_type') : false;
        $auditHasRecordId = function_exists('rhuColumnExists') ? rhuColumnExists($pdo, 'audit_logs', 'record_id') : true;
        $auditHasEntityId = function_exists('rhuColumnExists') ? rhuColumnExists($pdo, 'audit_logs', 'entity_id') : false;
        $auditUserExprParts = [];
        if ($auditHasHealthWorkerId) $auditUserExprParts[] = 'a.health_worker_id';
        if ($auditHasUserId) $auditUserExprParts[] = 'a.user_id';
        $auditUserExpr = $auditUserExprParts ? 'COALESCE(' . implode(', ', $auditUserExprParts) . ')' : 'NULL';
        $auditModuleExprParts = [];
        if ($auditHasModuleName) $auditModuleExprParts[] = 'a.module_name';
        if ($auditHasEntityType) $auditModuleExprParts[] = 'a.entity_type';
        $auditModuleExpr = $auditModuleExprParts ? 'COALESCE(' . implode(', ', $auditModuleExprParts) . ')' : "''";
        $auditRecordExprParts = [];
        if ($auditHasRecordId) $auditRecordExprParts[] = 'a.record_id';
        if ($auditHasEntityId) $auditRecordExprParts[] = 'a.entity_id';
        $auditRecordExpr = $auditRecordExprParts ? 'COALESCE(' . implode(', ', $auditRecordExprParts) . ')' : 'NULL';
        $aStmt = $pdo->query("
            SELECT a.id, {$auditUserExpr} AS user_id, hw.first_name, hw.last_name, hw.email, hw.role AS role_name, a.action, {$auditModuleExpr} AS table_name, {$auditRecordExpr} AS record_id, a.created_at AS timestamp
            FROM audit_logs a
            LEFT JOIN health_workers hw ON hw.id = {$auditUserExpr}
            WHERE COALESCE({$auditModuleExpr}, '') <> 'Resident Portal'
              AND (
                   hw.id IS NOT NULL
                   OR {$auditModuleExpr} IN ('health_workers', 'users', 'system', 'portal_settings', 'MedTech', 'Sanitary')
                   OR a.action LIKE '%Admin%'
                   OR a.action LIKE '%Staff%'
                   OR a.action LIKE '%User%'
                   OR a.action LIKE '%Login%'
                   OR a.action LIKE '%Logout%'
                   OR a.action LIKE '%Recorded%'
                   OR a.action LIKE '%Updated%'
                   OR a.action LIKE '%Saved%'
                   OR a.action LIKE '%Issued%'
              )
            ORDER BY a.created_at DESC LIMIT 50
        ");
        $dbAuditLogsList = $aStmt->fetchAll(pdo::FETCH_ASSOC);

        try {
            $cStmt = $pdo->query("
                SELECT c.id, c.resident_id, c.certificate_type AS certificate_type_id,
                       r.first_name, r.middle_name, r.last_name,
                       CONCAT_WS(' ', NULLIF(TRIM(r.first_name), ''), NULLIF(TRIM(r.middle_name), ''), NULLIF(TRIM(r.last_name), '')) AS resident_full_name,
                       c.certificate_type,
                       c.purpose, c.status, c.status AS workflow_status,
                       c.issue_date, c.certificate_number, c.created_at, NULL AS generated_html, NULL AS sent_at,
                       c.issued_by_id, c.issued_by_id AS assigned_doctor_id, c.issued_by_id AS admin_approver_user_id,
                       1 AS duplicate_count,
                       CONCAT(COALESCE(hw.first_name,''), ' ', COALESCE(hw.last_name,'')) AS assigned_doctor_name,
                       hw.position_title AS assigned_doctor_position
                FROM certificates c
                LEFT JOIN residents r ON c.resident_id = r.id
                LEFT JOIN health_workers hw ON hw.id = c.issued_by_id
                ORDER BY c.created_at DESC
            ");
            $dbCertificatesList = $cStmt->fetchAll(pdo::FETCH_ASSOC);
            $groupedAdminCertificates = [];
            foreach ($dbCertificatesList as $certificateRow) {
                $purposeText = trim((string)($certificateRow['purpose'] ?? ''));
                $requestedType = preg_replace('/^Portal Request:\s*/i', '', $purposeText);
                $requestedType = preg_replace('/\s*\((FREE|NO PAYMENT|PHP\s*\d+(?:\.\d{1,2})?)\)\s*$/i', '', (string)$requestedType);
                $requestedType = trim((string)$requestedType);
                if ($requestedType === '') {
                    $requestedType = trim((string)($certificateRow['certificate_type'] ?? 'Certificate'));
                }

                $statusText = strtolower(trim((string)($certificateRow['workflow_status'] ?? '') . ' ' . (string)($certificateRow['status'] ?? '')));
                $statusRank = 1;
                if (str_contains($statusText, 'reject') || str_contains($statusText, 'declin') || str_contains($statusText, 'cancel')) {
                    $statusRank = 5;
                } elseif ((str_contains($statusText, 'approved') || str_contains($statusText, 'issued') || str_contains($statusText, 'sent') || str_contains($statusText, 'valid')) && !str_contains($statusText, 'invalid')) {
                    $statusRank = 4;
                } elseif (str_contains($statusText, 'signed') || str_contains($statusText, 'approval') || str_contains($statusText, 'process')) {
                    $statusRank = 3;
                } elseif (str_contains($statusText, 'pending') || str_contains($statusText, 'draft') || str_contains($statusText, 'requested')) {
                    $statusRank = 2;
                }

                $residentId = (int)($certificateRow['resident_id'] ?? 0);
                $groupKey = $residentId . '|' . strtolower(preg_replace('/\s+/', ' ', $requestedType));
                $certificateRow['certificate_type'] = $requestedType;
                $certificateRow['_display_rank'] = $statusRank;
                $certificateRow['_combined_ids'] = [(int)($certificateRow['id'] ?? 0)];

                if (!isset($groupedAdminCertificates[$groupKey])) {
                    $groupedAdminCertificates[$groupKey] = $certificateRow;
                    continue;
                }

                $groupedAdminCertificates[$groupKey]['_combined_ids'][] = (int)($certificateRow['id'] ?? 0);
                $currentRank = (int)($groupedAdminCertificates[$groupKey]['_display_rank'] ?? 0);
                $currentId = (int)($groupedAdminCertificates[$groupKey]['id'] ?? 0);
                $nextId = (int)($certificateRow['id'] ?? 0);
                if ($statusRank > $currentRank || ($statusRank === $currentRank && $nextId > $currentId)) {
                    $certificateRow['_combined_ids'] = $groupedAdminCertificates[$groupKey]['_combined_ids'];
                    $certificateRow['duplicate_count'] = count(array_unique($certificateRow['_combined_ids']));
                    $groupedAdminCertificates[$groupKey] = $certificateRow;
                } else {
                    $groupedAdminCertificates[$groupKey]['duplicate_count'] = count(array_unique($groupedAdminCertificates[$groupKey]['_combined_ids']));
                }
            }
            $dbCertificatesList = array_values($groupedAdminCertificates);
        } catch (Exception $e) {
            error_log('Admin certificates: ' . $e->getMessage());
            $dbCertificatesList = [];
        }

        try {
            if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'dental_records')) {
                $drStmt = $pdo->query("
                    SELECT dr.*, r.first_name, r.last_name, COALESCE(b.name, '') AS barangay,
                           CONCAT(COALESCE(hw.first_name,''), ' ', COALESCE(hw.last_name,'')) AS provider_name
                    FROM dental_records dr
                    LEFT JOIN residents r ON r.id = dr.resident_id
                    LEFT JOIN barangays b ON b.id = r.barangay_id
                    LEFT JOIN health_workers hw ON hw.id = dr.performed_by
                    ORDER BY dr.record_date DESC, dr.id DESC
                    LIMIT 100
                ");
                $dbDentalRecords = $drStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Exception $e) {
            error_log('Admin dental records: ' . $e->getMessage());
            $dbDentalRecords = [];
        }

        try {
            if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
                $mStmt = $pdo->query("
                    SELECT m.id, m.resident_id, r.first_name, r.last_name, r.email,
                           m.subject, m.message, m.admin_reply, m.status, m.created_at
                    FROM messages m
                    LEFT JOIN residents r ON m.resident_id = r.id
                    ORDER BY m.created_at DESC LIMIT 50
                ");
            } else {
                $mStmt = $pdo->query("
                    SELECT a.id, a.record_id AS resident_id, r.first_name, r.last_name, r.email,
                           COALESCE(NULLIF(TRIM(a.action), ''), 'Resident Inquiry') AS subject,
                           a.description AS message, '' AS admin_reply, 'Pending' AS status, a.created_at
                    FROM audit_logs a
                    INNER JOIN residents r ON a.record_id = r.id
                    WHERE a.module_name = 'Resident Portal'
                      AND a.record_id IS NOT NULL
                      AND a.record_id > 0
                      AND a.action NOT LIKE '%Deleted%'
                      AND a.action NOT LIKE '%Blocked%'
                      AND a.action NOT LIKE '%Replied%'
                      AND a.action NOT LIKE '%Update%'
                    ORDER BY a.created_at DESC LIMIT 50
                ");
            }
            $dbMessagesList = $mStmt->fetchAll(pdo::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $dbMessagesList = [];
        }

        try {
            $eStmt = $pdo->query("
                SELECT e.id, NULL AS resident_id, '' AS first_name, '' AS last_name, e.title AS event_title,
                       DATE(e.start_datetime) AS event_date, e.status, e.created_at
                FROM events e
                ORDER BY e.created_at DESC LIMIT 50
            ");
            $dbEventsList = $eStmt->fetchAll(pdo::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('Admin events: ' . $e->getMessage());
            $dbEventsList = [];
        }

        $barangayStats = $pdo->query("SELECT COALESCE(b.name, 'Unassigned') AS barangay, COUNT(*) AS count FROM residents r LEFT JOIN barangays b ON b.id = r.barangay_id GROUP BY COALESCE(b.name, 'Unassigned') ORDER BY count DESC")->fetchAll(pdo::FETCH_ASSOC);
        $staffTypeStats = $pdo->query("SELECT COALESCE(position_title, role, 'Staff') AS staff_type, COUNT(*) AS count FROM health_workers GROUP BY COALESCE(position_title, role, 'Staff') ORDER BY count DESC")->fetchAll(pdo::FETCH_ASSOC);
        $dbRoles = $pdo->query("SELECT MIN(id) AS id, COALESCE(role, position_title, 'Staff') AS name FROM health_workers GROUP BY COALESCE(role, position_title, 'Staff') ORDER BY name")->fetchAll(pdo::FETCH_ASSOC);
        $existingRoleNames = array_map(static fn($role) => strtoupper((string)($role['name'] ?? '')), $dbRoles);
        foreach (['PHYSICIAN', 'NURSE', 'MIDWIFE', 'MEDTECH', 'SANITARY_INSPECTOR', 'DENTAL', 'BHW', 'ADMIN_STAFF', 'SECRETARY'] as $index => $roleName) {
            if (!in_array($roleName, $existingRoleNames, true)) {
                $dbRoles[] = ['id' => 1000 + $index, 'name' => $roleName];
            }
        }

        try {
            $reportsDiseaseOptions = $pdo->query("SELECT name AS disease_name FROM disease_types ORDER BY name")->fetchAll(pdo::FETCH_COLUMN) ?: [];
            $reportsMapWhere = [];
            $reportsMapParams = [];
            if ($reportsSelectedBarangay !== '') {
                $reportsMapWhere[] = 'b.name = ?';
                $reportsMapParams[] = $reportsSelectedBarangay;
            }
            if ($reportsSelectedDisease !== '') {
                $reportsMapWhere[] = 'dt.name = ?';
                $reportsMapParams[] = $reportsSelectedDisease;
            }
            $reportsMapSql = "
                SELECT b.name AS barangay, NULL AS latitude, NULL AS longitude, b.population,
                       COUNT(DISTINCT r.id) AS residents,
                       COUNT(dc.id) AS disease_cases,
                       COUNT(DISTINCT CASE WHEN dc.case_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN dc.id END) AS recent_cases,
                       SUM(CASE WHEN dc.case_status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_cases
                FROM barangays b
                LEFT JOIN residents r ON r.barangay_id = b.id AND r.status = 'Active'
                LEFT JOIN disease_cases dc ON dc.resident_id = r.id
                LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
                WHERE 1=1" . ($reportsMapWhere ? ' AND ' . implode(' AND ', $reportsMapWhere) : '') . "
                GROUP BY b.id, b.name, b.population
                ORDER BY disease_cases DESC, residents DESC, b.name
            ";
            $reportsMapStmt = $pdo->prepare($reportsMapSql);
            $reportsMapStmt->execute($reportsMapParams);
            $reportsMapStats = $reportsMapStmt->fetchAll(pdo::FETCH_ASSOC) ?: [];

            $reportsDiseaseWhere = [];
            $reportsDiseaseParams = [];
            if ($reportsSelectedBarangay !== '') {
                $reportsDiseaseWhere[] = 'b.name = ?';
                $reportsDiseaseParams[] = $reportsSelectedBarangay;
            }
            if ($reportsSelectedDisease !== '') {
                $reportsDiseaseWhere[] = 'dt.name = ?';
                $reportsDiseaseParams[] = $reportsSelectedDisease;
            }
            $reportsDiseaseSql = "
                SELECT COALESCE(b.name, 'Unassigned') AS barangay, dt.name AS disease_name, COUNT(*) AS cases,
                       SUM(CASE WHEN dc.case_status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed
                FROM disease_cases dc
                LEFT JOIN residents r ON r.id = dc.resident_id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
                WHERE r.id IS NOT NULL" . ($reportsDiseaseWhere ? ' AND ' . implode(' AND ', $reportsDiseaseWhere) : '') . "
                GROUP BY COALESCE(b.name, 'Unassigned'), dt.name
                ORDER BY cases DESC, confirmed DESC
                LIMIT 12
            ";
            $reportsDiseaseStmt = $pdo->prepare($reportsDiseaseSql);
            $reportsDiseaseStmt->execute($reportsDiseaseParams);
            $reportsDiseaseByBarangay = $reportsDiseaseStmt->fetchAll(pdo::FETCH_ASSOC) ?: [];

            $reportsStaffUserMix = $pdo->query("
                SELECT COALESCE(position_title, role, 'Staff') AS label, COUNT(id) AS count
                FROM health_workers
                WHERE status = 'Active'
                GROUP BY COALESCE(position_title, role, 'Staff')
                ORDER BY count DESC
            ")->fetchAll(pdo::FETCH_ASSOC) ?: [];

            $reportsMonthlySignals = $pdo->query("
                SELECT DATE_FORMAT(month_start, '%b') AS label, DATE_FORMAT(month_start, '%Y-%m') AS month_key,
                       COALESCE(residents, 0) AS residents,
                       COALESCE(consultations, 0) AS consultations,
                       COALESCE(disease_cases, 0) AS disease_cases,
                       COALESCE(vaccinations, 0) AS vaccinations,
                       COALESCE(pregnancies, 0) AS pregnancies
                FROM (
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01') AS month_start UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 4 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 3 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 2 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01')
                ) months
                LEFT JOIN (SELECT DATE_FORMAT(created_at, '%Y-%m-01') m, COUNT(*) residents FROM residents GROUP BY m) r ON r.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(consultation_date, '%Y-%m-01') m, COUNT(*) consultations FROM consultations GROUP BY m) c ON c.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(case_date, '%Y-%m-01') m, COUNT(*) disease_cases FROM disease_cases GROUP BY m) d ON d.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(vaccination_date, '%Y-%m-01') m, COUNT(*) vaccinations FROM vaccination_records GROUP BY m) v ON v.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(created_at, '%Y-%m-01') m, COUNT(*) pregnancies FROM pregnancy_records GROUP BY m) p ON p.m = months.month_start
                ORDER BY months.month_start
            ")->fetchAll(pdo::FETCH_ASSOC) ?: [];
        } catch (Throwable $reportsError) {
            error_log('Admin reports analytics: ' . $reportsError->getMessage());
        }

        // CLINICAL DATA QUERIES
        // Consultations
        try {
            $cslStmt = $pdo->query("
                SELECT c.id, c.resident_id, r.first_name, r.last_name, b.name AS barangay, c.chief_complaint, c.diagnosis, COALESCE(c.treatment_plan, c.treatment) AS treatment_plan, COALESCE(c.consultation_notes, c.remarks) AS consultation_notes, COALESCE(NULLIF(c.physician_id, 0), c.health_worker_id) AS physician_id, c.health_worker_id AS recorded_by_staff_id, c.health_worker_id AS recorded_by_user_id, c.consultation_date, COALESCE(c.consultation_status, 'Scheduled') AS consultation_status
                FROM consultations c
                LEFT JOIN residents r ON c.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                ORDER BY c.consultation_date DESC, c.id DESC
            ");
            $dbConsultationsList = $cslStmt->fetchAll(pdo::FETCH_ASSOC);
        } catch (Exception $e) {
            $dbConsultationsList = [];
        }

        try {
            $dbHealthProfileRecords = $pdo->query("
                SELECT hp.id, hp.resident_id, hp.recorded_by_id AS updated_by_staff_id, hp.recorded_by_id AS updated_by_user_id, hp.height_cm AS height, hp.weight_kg AS weight, hp.blood_pressure, hp.last_checkup_date, hp.updated_at,
                       r.first_name, r.last_name, b.name AS barangay
                FROM health_records hp
                LEFT JOIN residents r ON r.id = hp.resident_id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                ORDER BY hp.updated_at DESC, hp.id DESC
                LIMIT 200
            ")->fetchAll(pdo::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $dbHealthProfileRecords = [];
        }

        // Maternal Health Cases
        try {
            $mtlStmt = $pdo->query("
                SELECT p.id, p.resident_id, r.first_name, r.last_name, b.name AS barangay, p.last_menstrual_period as lmp, p.expected_delivery_date as edc, p.pregnancy_status as status, (p.risk_level = 'High Risk') as high_risk, hr.blood_type, p.health_worker_id as midwife_staff_id, p.created_at
                FROM pregnancy_records p
                LEFT JOIN residents r ON p.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                LEFT JOIN health_records hr ON hr.resident_id = r.id
                ORDER BY p.last_menstrual_period DESC
            ");
            $dbMaternalCases = $mtlStmt->fetchAll(pdo::FETCH_ASSOC);
        } catch (Exception $e) {
            $dbMaternalCases = [];
        }

        try {
            if (rhuTableExists($pdo, 'family_planning_records')) {
                $fpStmt = $pdo->query("
                    SELECT fp.*, r.first_name, r.last_name, b.name AS barangay
                    FROM family_planning_records fp
                    JOIN residents r ON r.id = fp.resident_id
                    LEFT JOIN barangays b ON b.id = r.barangay_id
                    WHERE " . rhuLivingResidentWhereSql('r') . "
                    ORDER BY fp.id DESC
                ");
                $dbFamilyPlanningRecords = $fpStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Exception $e) {
            error_log('Admin family planning records load failed: ' . $e->getMessage());
        }

        try {
            if (rhuTableExists($pdo, 'maternal_referrals')) {
                $referralStmt = $pdo->query("
                    SELECT mr.*, r.first_name, r.last_name, b.name AS barangay
                    FROM maternal_referrals mr
                    JOIN residents r ON r.id = mr.resident_id
                    LEFT JOIN barangays b ON b.id = r.barangay_id
                    WHERE " . rhuLivingResidentWhereSql('r') . "
                    ORDER BY mr.id DESC
                ");
                $dbMaternalReferrals = $referralStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Exception $e) {
            error_log('Admin maternal referrals load failed: ' . $e->getMessage());
        }

        // Immunization/Vaccination Records
        try {
            $vaccinationProviderSql = rhuColumnExists($pdo, 'vaccination_records', 'provider_name')
                ? "COALESCE(NULLIF(TRIM(v.provider_name), ''), CONCAT_WS(' ', hw.first_name, hw.last_name))"
                : "CONCAT_WS(' ', hw.first_name, hw.last_name)";
            $vccStmt = $pdo->query("
                SELECT v.id, v.resident_id, r.first_name, r.last_name, b.name AS barangay, v.vaccine_name, v.vaccination_date, v.health_worker_id AS healthcare_provider_id, {$vaccinationProviderSql} AS provider_name, 'Completed' as status
                FROM vaccination_records v
                LEFT JOIN residents r ON v.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                LEFT JOIN health_workers hw ON hw.id = v.health_worker_id
                ORDER BY v.vaccination_date DESC
            ");
            $dbVaccinationRecords = $vccStmt->fetchAll(pdo::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Admin vaccinations: ' . $e->getMessage());
            $dbVaccinationRecords = [];
        }

        // Disease Surveillance Cases
        try {
            $dcsStmt = $pdo->query("
                SELECT dc.id, dc.disease_type_id AS disease_id, dt.name AS disease_name, dc.resident_id, r.first_name, r.last_name, b.name AS barangay, dc.case_date, dc.reported_by_id, dc.case_status as status, dc.outcome
                FROM disease_cases dc
                LEFT JOIN disease_types dt ON dc.disease_type_id = dt.id
                LEFT JOIN residents r ON dc.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                ORDER BY dc.case_date DESC LIMIT 100
            ");
            $dbDiseaseCases = $dcsStmt->fetchAll(pdo::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Admin disease cases: ' . $e->getMessage());
            $dbDiseaseCases = [];
        }

        // Medicine Inventory
        try {
            $medStmt = $pdo->query("
                SELECT MIN(id) AS id,
                       MIN(item_name) AS generic_name,
                       MIN(item_name) AS brand_name,
                       SUM(quantity) AS quantity,
                       MAX(reorder_level) AS reorder_level,
                       0.00 AS unit_price,
                       MIN(expiration_date) AS expiry_date,
                       GROUP_CONCAT(DISTINCT NULLIF(batch_number, '') ORDER BY batch_number SEPARATOR ', ') AS batch_number,
                       CASE
                           WHEN MIN(expiration_date) < CURDATE() THEN 'expired'
                           WHEN MIN(expiration_date) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'almost expired'
                           WHEN SUM(quantity) <= MAX(reorder_level) THEN 'critical'
                           WHEN SUM(quantity) <= (MAX(reorder_level) * 1.5) THEN 'low'
                           ELSE 'adequate'
                       END as status
                FROM medicine_inventory
                GROUP BY LOWER(TRIM(item_name))
                ORDER BY quantity ASC LIMIT 100
            ");
            $dbMedicineInventory = $medStmt->fetchAll(pdo::FETCH_ASSOC);
        } catch (Exception $e) {
            $dbMedicineInventory = [];
        }

        // Vital Statistics (Births & Deaths)
        try {
            $vitalStmt = $pdo->query("
                SELECT v.id, v.resident_id, v.remarks, v.event_type as type, v.full_name AS child_name, COALESCE(b.name, '') as location, v.event_date as date_recorded, v.created_at AS record_created_at, v.recorded_by_id AS delivery_attendant_id, r.first_name AS mother_first_name, r.last_name AS mother_last_name, b.name AS barangay
                FROM vital_statistics v
                LEFT JOIN residents r ON r.id = v.resident_id
                LEFT JOIN barangays b ON b.id = v.barangay_id
                ORDER BY v.event_date DESC
            ");
            $dbVitalStatistics = rhuUniqueBirthDisplay($vitalStmt->fetchAll(pdo::FETCH_ASSOC));
        } catch (Exception $e) {
            $dbVitalStatistics = [];
        }

        // Statistics Summaries
        try {
            $consultationStats = $pdo->query("SELECT COUNT(*) as total FROM consultations")->fetchAll(pdo::FETCH_ASSOC);
            $maternalStats = $pdo->query("SELECT pregnancy_status as status, COUNT(*) as count FROM pregnancy_records GROUP BY pregnancy_status")->fetchAll(pdo::FETCH_ASSOC);
            $vaccinationStats = $pdo->query("SELECT COUNT(*) as count FROM vaccination_records")->fetchAll(pdo::FETCH_ASSOC);
            $diseaseStats = $pdo->query("SELECT dt.name AS disease_name, COUNT(*) as count FROM disease_cases dc LEFT JOIN disease_types dt ON dc.disease_type_id = dt.id GROUP BY dt.name ORDER BY count DESC LIMIT 10")->fetchAll(pdo::FETCH_ASSOC);
            $medicineStats = $pdo->query("
                SELECT status, COUNT(*) AS count
                FROM (
                    SELECT CASE
                        WHEN MIN(expiration_date) < CURDATE() THEN 'expired'
                        WHEN MIN(expiration_date) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'almost expired'
                        WHEN SUM(quantity) <= MAX(reorder_level) THEN 'critical'
                        WHEN SUM(quantity) <= (MAX(reorder_level) * 1.5) THEN 'low'
                        ELSE 'adequate'
                    END AS status
                    FROM medicine_inventory
                    GROUP BY LOWER(TRIM(item_name))
                ) grouped_medicine
                GROUP BY status
            ")->fetchAll(pdo::FETCH_ASSOC);
        } catch (Exception $e) {
        }



        $pendingCertCount = (int)($pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'Pending'")->fetchColumn() ?: 0);
        if ($pendingCertCount > 0) {
            $adminNotifications[] = ['id' => 'n1', 'msg' => "{$pendingCertCount} pending certificate request(s) require review", 'type' => 'alert', 'time' => 'Action Needed', 'unread' => true];
        }

        try {
            $pendingMsgCount = (int)($pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module_name = 'Resident Portal' AND action = 'Resident Message'")->fetchColumn() ?: 0);
            if ($pendingMsgCount > 0) {
                $adminNotifications[] = ['id' => 'n2', 'msg' => "{$pendingMsgCount} unread resident message(s) received", 'type' => 'alert', 'time' => 'Action Needed', 'unread' => true];
            }
        } catch (Exception $e) {
        }

        try {
            if (!rhuTableExists($pdo, 'portal_notifications')) {
                throw new RuntimeException('portal_notifications disabled by 16-table schema');
            }
            $nStmt = $pdo->prepare(
                "SELECT id, message AS msg, created_at AS time, (is_read = 0) AS unread
                 FROM portal_notifications
                 WHERE user_id = :user_id OR audience_role IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')
                 ORDER BY created_at DESC LIMIT 20"
            );
            $nStmt->execute(['user_id' => (int)($_SESSION['user']['user_id'] ?? 0)]);
            $adminNotifications = array_merge($nStmt->fetchAll(pdo::FETCH_ASSOC) ?: [], $adminNotifications);
        } catch (Exception $e) {
            error_log('Admin notifications: ' . $e->getMessage());
        }

        $adminNotifications[] = ['id' => 'n4', 'msg' => 'System connected to MySQL rhu database.', 'type' => 'check', 'time' => 'Active', 'unread' => false];
    } catch (PDOException $ex) {
        error_log("RHUAdminDashboard DB Error: " . $ex->getMessage());
    }
}

$overviewChartSeries = [];
$overviewChartYear = (int)date('Y');
for ($monthNumber = 1; $monthNumber <= 12; ++$monthNumber) {
    $monthDate = DateTimeImmutable::createFromFormat('!Y-n-j', "{$overviewChartYear}-{$monthNumber}-1");
    $monthKey = $monthDate->format('Y-m');
    $monthLabel = $monthDate->format('M');
    $monthCount = 0;
    foreach ($dbConsultationsList as $consultation) {
        if (empty($consultation['consultation_date'])) {
            continue;
        }
        $consultationMonth = date('Y-m', strtotime((string)$consultation['consultation_date']));
        if ($consultationMonth === $monthKey) {
            $monthCount++;
        }
    }
    $overviewChartSeries[] = ['label' => $monthLabel, 'value' => $monthCount];
}
$overviewChartRawMax = max(1, ...array_map(static fn($point) => (int)$point['value'], $overviewChartSeries));
$overviewChartMax = $overviewChartRawMax;
$overviewChartStepValue = max(1, (int)ceil($overviewChartMax / 4));
$overviewChartPoints = [];
$overviewChartSoftPoints = [];
$overviewChartWidth = 520;
$overviewChartBaseline = 156;
$overviewChartTop = 28;
$overviewChartUsableHeight = $overviewChartBaseline - $overviewChartTop;
$overviewChartLeft = 44;
$overviewChartRight = 500;
$overviewChartStep = ($overviewChartRight - $overviewChartLeft) / max(1, count($overviewChartSeries) - 1);
foreach ($overviewChartSeries as $index => $point) {
    $x = $overviewChartLeft + ($index * $overviewChartStep);
    $y = $overviewChartBaseline - ((int)$point['value'] / $overviewChartMax) * $overviewChartUsableHeight;
    $overviewChartPoints[] = ['x' => $x, 'y' => $y, 'value' => (int)$point['value']];
    $softValue = min($overviewChartMax, max((int)$point['value'] + (int)ceil($overviewChartMax * 0.22), (int)round($overviewChartMax * 0.12)));
    $softY = $overviewChartBaseline - ($softValue / $overviewChartMax) * $overviewChartUsableHeight;
    $overviewChartSoftPoints[] = ['x' => $x, 'y' => $softY];
}
$overviewChartPolyline = implode(' ', array_map(static fn($point) => $point['x'] . ',' . $point['y'], $overviewChartPoints));
$overviewChartPath = '';
if (!empty($overviewChartPoints)) {
    $firstChartPoint = $overviewChartPoints[0];
    $overviewChartPath = 'M ' . $firstChartPoint['x'] . ' ' . $firstChartPoint['y'];
    for ($i = 1, $pointCount = count($overviewChartPoints); $i < $pointCount; ++$i) {
        $previous = $overviewChartPoints[$i - 1];
        $current = $overviewChartPoints[$i];
        $controlX = ($previous['x'] + $current['x']) / 2;
        $overviewChartPath .= ' C ' . $controlX . ' ' . $previous['y'] . ', ' . $controlX . ' ' . $current['y'] . ', ' . $current['x'] . ' ' . $current['y'];
    }
}
$overviewChartAreaPath = $overviewChartPath !== ''
    ? $overviewChartPath . " L {$overviewChartRight} {$overviewChartBaseline} L {$overviewChartLeft} {$overviewChartBaseline} Z"
    : '';
$overviewChartFill = implode(' ', array_map(static fn($point) => $point['x'] . ',' . $point['y'], $overviewChartPoints));
$overviewChartFill .= " {$overviewChartRight},{$overviewChartBaseline} {$overviewChartLeft},{$overviewChartBaseline}";
$overviewChartSoftFill = implode(' ', array_map(static fn($point) => $point['x'] . ',' . $point['y'], $overviewChartSoftPoints));
$overviewChartSoftFill .= " {$overviewChartRight},{$overviewChartBaseline} {$overviewChartLeft},{$overviewChartBaseline}";
$overviewAdminCount = count(array_filter($dbStaffList, static function (array $staff): bool {
    $roleText = strtoupper((string)($staff['staff_type'] ?? '') . ' ' . (string)($staff['specialization'] ?? ''));
    return str_contains($roleText, 'ADMIN') || str_contains($roleText, 'MUNICIPAL HEALTH OFFICER') || str_contains($roleText, 'MHO');
}));
$overviewHealthWorkerCount = max(0, $totalStaffCount - $overviewAdminCount);
$overviewDonutTotal = max(1, $totalResidentsCount + $overviewAdminCount + $overviewHealthWorkerCount);
$overviewUsersPct = ($totalResidentsCount / $overviewDonutTotal) * 100;
$overviewStaffPct = ($overviewAdminCount / $overviewDonutTotal) * 100;
$overviewBhwPct = ($overviewHealthWorkerCount / $overviewDonutTotal) * 100;

$tabLabelMap = [
    'overview' => 'Overview',
    'emergency' => 'Emergency Referrals',
    'users' => 'User Management',
    'staff' => 'Staff Accounts',
    'residents' => 'Resident Registry',
    'announcements' => 'Landing Page Content',
    'consultations' => 'OPD Consultations',
    'maternal' => 'Maternal Health',
    'vaccination' => 'Immunization',
    'disease' => 'Disease Surveillance',
    'medicine' => 'Medicine Inventory',
    'vital' => 'Vital Statistics',
    'reports' => 'Reports & Analytics',
    'audit' => 'Audit Logs',
    'system' => 'System Settings',
    'security' => 'Security',
];

$tabIconMap = [
    'overview' => 'home',
    'emergency' => 'alert',
    'users' => 'users',
    'staff' => 'stethoscope',
    'residents' => 'check',
    'announcements' => 'bell',
    'consultations' => 'activity',
    'maternal' => 'baby',
    'vaccination' => 'shield',
    'disease' => 'alert',
    'medicine' => 'pill',
    'vital' => 'file',
    'reports' => 'bar',
    'audit' => 'database',
    'system' => 'settings',
    'security' => 'lock',
];

$tabDescriptionMap = [
    'overview' => 'Monitor system accounts, residents, clinical activity, and administrative priorities.',
    'emergency' => 'Resident emergency referrals and pickup details.',
    'users' => 'Manage database accounts, roles, activation status, and access credentials.',
    'staff' => 'Review healthcare personnel records, positions, licenses, and account status.',
    'residents' => 'Search and maintain the municipal resident health registry.',
    'announcements' => 'Manage landing-page announcements, alerts, events, and public health content.',
    'consultations' => 'Review OPD encounters, diagnoses, attending staff, and consultation records.',
    'maternal' => 'Monitor prenatal cases, expected delivery dates, and maternal risk levels.',
    'vaccination' => 'Review resident immunization records, schedules, providers, and dose history.',
    'disease' => 'Monitor reportable disease cases and public-health surveillance activity.',
    'medicine' => 'Manage medicine stock, reorder levels, expiration, and supply availability.',
    'vital' => 'Review registered births and other municipal vital-statistics records.',
    'reports' => 'Review database summaries and export administrative reports.',
    'audit' => 'Track accountable administrator and staff actions across the system.',
    'system' => 'Configure RHU facility identity, contact information, and system behavior.',
    'security' => 'Manage administrator security, passwords, sessions, backup, and access controls.',
];

$drawerGroups = [
    'Dashboard' => ['overview', 'announcements'],
    'People & Accounts' => ['users', 'staff', 'residents'],
    'Clinical Services' => ['emergency', 'consultations', 'maternal', 'vaccination', 'disease'],
    'Records & Resources' => ['medicine', 'vital'],
    'Governance' => ['reports', 'audit', 'system', 'security'],
];

function tabUrl(string $tab, bool $notifs = false, array $extra = []): string
{
    $params = ['tab' => $tab];
    if ($notifs) {
        $params['notifs'] = '1';
    }
    foreach ($extra as $key => $value) {
        if ($value !== null) {
            $params[$key] = $value;
        }
    }
    return '?' . http_build_query($params);
}

function renderMetricCard(string $label, mixed $value, string $sub, string $icon, string $iconClass, string $textColor): void
{
    echo '<div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">';
    echo '<div class="w-9 h-9 ' . esc($iconClass) . ' rounded-lg flex items-center justify-center mb-3 text-white">' . iconSvg($icon, 'w-4.5 h-4.5') . '</div>';
    echo '<p class="text-2xl font-black text-gray-900">' . esc($value) . '</p>';
    echo '<p class="text-sm font-bold text-gray-700">' . esc($label) . '</p>';
    echo '<p class="text-xs text-gray-400">' . esc($sub) . '</p>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="manifest.php?v=20261003-icon-1">
    <meta name="theme-color" content="#0f766e">
    <link rel="apple-touch-icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1">
    <link rel="icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1" type="image/png" sizes="192x192">
    <link rel="shortcut icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1" type="image/png">
    <script src="assets/js/pwa-install.js?v=20260916" defer></script>
    <title>Unified RHU Dashboard - ResiHUnity RHU</title>
    <script>
        (function() {
            try {
                const url = new URL(window.location.href);
                const currentTab = <?= json_encode($tab, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                url.searchParams.set('tab', currentTab);
                window.history.replaceState(window.history.state, '', url.toString());
            } catch (error) {}
        })();
    </script>
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <?php if ($tab === 'reports'): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
        <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <?php endif; ?>
    <style>
        html {
            scroll-behavior: auto;
        }

        body {
            overflow-x: hidden;
        }

        #nasugbuHealthMap {
            display: block;
            height: clamp(560px, 68vh, 760px) !important;
            min-height: 560px;
            width: 100% !important;
            z-index: 0;
            position: relative;
            border-radius: 0.75rem;
            overflow: hidden;
        }

        #nasugbuHealthMap.leaflet-container {
            overflow: hidden;
            position: relative;
            outline-style: none;
            background: #dbeafe;
            font-family: inherit;
        }

        #nasugbuHealthMap .leaflet-pane,
        #nasugbuHealthMap .leaflet-tile,
        #nasugbuHealthMap .leaflet-marker-icon,
        #nasugbuHealthMap .leaflet-marker-shadow,
        #nasugbuHealthMap .leaflet-tile-container,
        #nasugbuHealthMap .leaflet-pane>svg,
        #nasugbuHealthMap .leaflet-pane>canvas,
        #nasugbuHealthMap .leaflet-zoom-box,
        #nasugbuHealthMap .leaflet-image-layer,
        #nasugbuHealthMap .leaflet-layer {
            position: absolute;
            left: 0;
            top: 0;
        }

        #nasugbuHealthMap .leaflet-map-pane,
        #nasugbuHealthMap .leaflet-tile-pane,
        #nasugbuHealthMap .leaflet-overlay-pane,
        #nasugbuHealthMap .leaflet-shadow-pane,
        #nasugbuHealthMap .leaflet-marker-pane,
        #nasugbuHealthMap .leaflet-tooltip-pane,
        #nasugbuHealthMap .leaflet-popup-pane {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
        }

        #nasugbuHealthMap .leaflet-tile,
        #nasugbuHealthMap .leaflet-marker-icon,
        #nasugbuHealthMap .leaflet-marker-shadow {
            user-select: none;
            -webkit-user-drag: none;
        }

        #nasugbuHealthMap .leaflet-tile-container {
            pointer-events: none;
        }

        /* Full-size panes must not intercept events intended for markers below. */
        #nasugbuHealthMap .leaflet-pane {
            pointer-events: none;
        }

        #nasugbuHealthMap .leaflet-popup,
        #nasugbuHealthMap .leaflet-control {
            pointer-events: auto;
        }

        #nasugbuHealthMap .leaflet-tooltip {
            pointer-events: none;
        }

        #nasugbuHealthMap .leaflet-control-container .leaflet-top,
        #nasugbuHealthMap .leaflet-control-container .leaflet-bottom {
            position: absolute;
            z-index: 1000;
            pointer-events: none;
        }

        #nasugbuHealthMap .leaflet-top {
            top: 0;
        }

        #nasugbuHealthMap .leaflet-right {
            right: 0;
        }

        #nasugbuHealthMap .leaflet-bottom {
            bottom: 0;
        }

        #nasugbuHealthMap .leaflet-left {
            left: 0;
        }

        #nasugbuHealthMap .leaflet-container {
            width: 100%;
            height: 100%;
            background: #f8fafc;
            font-family: inherit;
        }

        #nasugbuHealthMap .leaflet-tile {
            width: 256px !important;
            height: 256px !important;
            max-width: none !important;
            max-height: none !important;
        }

        #nasugbuHealthMap img.leaflet-tile,
        #nasugbuHealthMap .leaflet-marker-icon,
        #nasugbuHealthMap .leaflet-marker-shadow {
            max-width: none !important;
            max-height: none !important;
        }

        #nasugbuHealthMap .leaflet-tile-pane {
            filter: contrast(92%) brightness(103%) saturate(0.75);
            opacity: 1;
        }

        #nasugbuHealthMap .leaflet-popup-content-wrapper {
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        #nasugbuHealthMap .leaflet-popup-content {
            margin: 0;
            min-width: 220px;
        }

        #nasugbuHealthMap .leaflet-tooltip {
            border-radius: 8px;
            font-weight: 700;
            font-size: 11px;
            padding: 4px 8px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            background: #ffffff;
            color: #0f172a;
        }

        #nasugbuHealthMap .nasugbu-map-floating-info {
            position: absolute;
            z-index: 700;
            display: none;
            width: min(280px, calc(100% - 24px));
            border: 1px solid #dbeafe;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .22);
            padding: 12px;
            pointer-events: auto;
        }

        #nasugbuHealthMap .nasugbu-map-floating-info.is-open {
            display: block;
        }

        #nasugbuHealthMap .nasugbu-map-floating-info::before {
            position: absolute;
            left: -7px;
            top: 50%;
            width: 14px;
            height: 14px;
            content: "";
            background: #ffffff;
            border-left: 1px solid #dbeafe;
            border-bottom: 1px solid #dbeafe;
            transform: translateY(-50%) rotate(45deg);
        }

        #nasugbuHealthMap .nasugbu-map-floating-info.is-left::before {
            left: auto;
            right: -7px;
            border-right: 1px solid #dbeafe;
            border-top: 1px solid #dbeafe;
            border-left: 0;
            border-bottom: 0;
        }

        #nasugbuHealthMap .nasugbu-map-floating-info__close {
            position: absolute;
            top: 8px;
            right: 8px;
            color: #0f172a;
            font-size: 18px;
            font-weight: 800;
            line-height: 1;
        }

        #nasugbuHealthMap .leaflet-interactive {
            cursor: pointer;
            pointer-events: auto;
        }

        #nasugbuHealthMap .nasugbu-marker-hitbox {
            align-items: center;
            background: transparent !important;
            border: 0 !important;
            border-radius: 999px !important;
            box-shadow: none !important;
            color: inherit !important;
            cursor: pointer;
            display: flex;
            height: 44px;
            justify-content: center;
            margin: 0;
            padding: 0;
            pointer-events: auto;
            transform: none !important;
            width: 44px;
        }

        #nasugbuHealthMap .leaflet-marker-icon {
            cursor: pointer;
            pointer-events: auto !important;
        }

        #nasugbuHealthMap .nasugbu-dot-marker {
            align-items: center;
            background: #10b981;
            border: 2px solid #ffffff;
            border-radius: 999px 999px 999px 0.25rem;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .24);
            cursor: pointer;
            display: flex;
            height: 24px;
            justify-content: center;
            pointer-events: auto;
            position: relative;
            transform: rotate(-45deg);
            transition: transform .12s ease, background-color .12s ease, box-shadow .12s ease;
            width: 24px;
        }

        #nasugbuHealthMap .nasugbu-dot-marker::after {
            background: #ffffff;
            border-radius: 999px;
            content: "";
            height: 8px;
            width: 8px;
        }

        #nasugbuHealthMap .nasugbu-dot-marker:hover,
        #nasugbuHealthMap .nasugbu-dot-marker.is-hovered,
        #nasugbuHealthMap .leaflet-marker-icon:focus .nasugbu-dot-marker,
        #nasugbuHealthMap .nasugbu-marker-hitbox:focus .nasugbu-dot-marker {
            background: #059669;
            box-shadow: 0 6px 16px rgba(5, 150, 105, .45);
            transform: rotate(-45deg) scale(1.14);
        }

        #nasugbuHealthMap .nasugbu-dot-marker.is-hotspot {
            background: #ef4444;
            height: 30px;
            width: 30px;
            box-shadow: 0 8px 24px rgba(239, 68, 68, .42);
        }

        #nasugbuHealthMap .nasugbu-dot-marker.is-hotspot:hover,
        #nasugbuHealthMap .nasugbu-dot-marker.is-hotspot.is-hovered,
        #nasugbuHealthMap .leaflet-marker-icon:focus .nasugbu-dot-marker.is-hotspot,
        #nasugbuHealthMap .nasugbu-marker-hitbox:focus .nasugbu-dot-marker.is-hotspot {
            background: #dc2626;
            box-shadow: 0 10px 28px rgba(220, 38, 38, .55);
            transform: rotate(-45deg) scale(1.12);
        }

        .nasugbu-map-popup {
            color: #334155;
            font-family: inherit;
            padding: 0.75rem;
        }

        .nasugbu-map-popup__title {
            color: #0f172a;
            font-size: 0.875rem;
            font-weight: 800;
            line-height: 1.25;
            margin: 0 0 0.625rem;
        }

        .nasugbu-map-popup__stats {
            display: grid;
            gap: 0.5rem;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .nasugbu-map-popup__stat {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.45rem;
        }

        .nasugbu-map-popup__label {
            color: #64748b;
            display: block;
            font-size: 0.625rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .nasugbu-map-popup__value {
            color: #0f172a;
            display: block;
            font-size: 0.875rem;
            font-weight: 900;
            margin-top: 0.15rem;
        }

        .dashboard-header {
            transition: box-shadow .28s ease, transform .28s ease;
            will-change: box-shadow;
        }

        .dashboard-header.is-scrolled {
            box-shadow: 0 16px 35px -22px rgba(15, 23, 42, .75);
        }

        .feature-drawer-backdrop {
            opacity: 0;
            pointer-events: none;
            transition: opacity .28s ease;
        }

        .feature-drawer-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }

        .feature-drawer {
            transform: translateX(-102%);
            transition: transform .34s cubic-bezier(.2, .75, .25, 1);
            will-change: transform;
            height: 100vh;
            max-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .feature-drawer.is-open {
            transform: translateX(0);
        }

        .feature-drawer nav {
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: rgba(147, 51, 234, 0.3) transparent;
        }

        .feature-drawer nav::-webkit-scrollbar {
            width: 5px;
        }

        .feature-drawer nav::-webkit-scrollbar-thumb {
            background-color: rgba(147, 51, 234, 0.3);
            border-radius: 9999px;
        }

        .feature-drawer a svg {
            transition: transform .2s ease;
        }

        .feature-drawer a:hover svg {
            transform: scale(1.08);
        }

        body.drawer-open {
            overflow: hidden;
        }

        main>div,
        main>section,
        main .bg-white.rounded-xl,
        main .bg-white.rounded-2xl,
        main details {
            transition: transform .24s ease, box-shadow .24s ease, opacity .24s ease;
        }

        main .bg-white.rounded-xl:hover,
        main .bg-white.rounded-2xl:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 32px -24px rgba(15, 23, 42, .55);
        }

        [data-scroll-reveal],
        [data-scroll-reveal].is-visible {
            opacity: 1;
            transform: none;
            transition: none;
            transition-delay: 0ms;
        }

        table tbody tr {
            transition: transform .18s ease, box-shadow .18s ease;
        }

        table tbody tr:not([hidden]):hover {
            position: relative;
            z-index: 1;
            transform: translateX(3px);
            box-shadow: -4px 0 0 currentColor;
        }

        button,
        a,
        input,
        select,
        textarea,
        summary {
            transition:
                transform .18s ease,
                box-shadow .18s ease,
                opacity .18s ease,
                border-color .18s ease;
        }

        button:not(:disabled):active,
        a:active {
            transform: scale(.97);
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(15, 23, 42, .09);
        }

        details[open]>summary {
            margin-bottom: .65rem;
        }

        details>summary::marker {
            transition: transform .2s ease;
        }

        .metric-value-pop {
            animation: none !important;
        }

        @keyframes metricPop {
            from {
                opacity: 0;
                transform: translateY(7px) scale(.94);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .scroll-top-button {
            opacity: 0;
            pointer-events: none;
            transform: translateY(12px) scale(.92);
            transition: opacity .22s ease, transform .22s ease;
        }

        .scroll-top-button.is-visible {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }

            [data-scroll-reveal] {
                opacity: 1;
                transform: none;
            }
        }

        /* Embedded styles/rhu-admin-ui.css */
        :root {
            --admin-brand-50: #ecfdf5;
            --admin-brand-100: #d1fae5;
            --admin-brand-200: #a7f3d0;
            --admin-brand-500: #10b981;
            --admin-brand-600: #059669;
            --admin-brand-700: #047857;
            --admin-brand-800: #065f46;
            --admin-brand-900: #064e3b;
            --admin-ink: #0f172a;
            --admin-muted: #64748b;
            --admin-line: #e2e8f0;
            --admin-canvas: #f6f9f8;
        }

        body.rhu-admin-ui {
            min-width: 320px;
            background:
                linear-gradient(135deg, rgba(214, 247, 220, .88), rgba(200, 239, 205, .84) 48%, rgba(228, 245, 235, .92)),
                url("../../../assets/admin-municipal-background.png") center / cover fixed no-repeat,
                var(--admin-canvas);
            color: var(--admin-ink);
        }

        body.rhu-admin-ui::before {
            position: fixed;
            z-index: 0;
            inset: 0;
            pointer-events: none;
            content: "";
            background:
                radial-gradient(circle at 12% 8%, rgba(255, 255, 255, .64), transparent 30rem),
                radial-gradient(circle at 80% 30%, rgba(142, 201, 120, .28), transparent 26rem),
                linear-gradient(to bottom, rgba(11, 72, 48, .14), rgba(6, 78, 59, .08));
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .rhu-admin-ui>.min-h-screen {
            position: relative;
            z-index: 1;
        }

        .rhu-admin-ui .admin-shell-header {
            background: linear-gradient(105deg, var(--admin-brand-900), var(--admin-brand-800) 55%, #155e75) !important;
            border-bottom: 1px solid rgba(167, 243, 208, .22);
            box-shadow: 0 10px 30px rgba(15, 23, 42, .14);
        }

        .rhu-admin-ui .admin-brand-logo {
            width: 42px;
            height: 42px;
            padding: 2px;
            border: 2px solid rgba(255, 255, 255, .72);
            border-radius: 12px;
            object-fit: contain;
            background: #fff;
            box-shadow: 0 7px 18px rgba(15, 23, 42, .2);
        }

        .rhu-admin-ui .admin-shell-header [class*="bg-purple-"],
        .rhu-admin-ui .admin-shell-header [class*="text-purple-"] {
            border-color: rgba(255, 255, 255, .2) !important;
            background-color: rgba(255, 255, 255, .13) !important;
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer {
            border-color: var(--admin-line);
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child {
            background: linear-gradient(135deg, var(--admin-brand-50), #fff);
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child>div>span {
            background: var(--admin-brand-700) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav {
            padding: 1rem;
            scrollbar-gutter: stable;
            scrollbar-width: thin;
            scrollbar-color: var(--admin-brand-200) transparent;
        }

        .rhu-admin-ui .admin-feature-drawer nav section {
            margin-bottom: 1.35rem;
        }

        .rhu-admin-ui .admin-feature-drawer nav h2 {
            color: var(--admin-brand-700);
        }

        .rhu-admin-ui .admin-feature-drawer nav a {
            min-height: 3rem;
            border: 1px solid transparent;
        }

        .rhu-admin-ui .admin-feature-drawer nav a:hover {
            border-color: var(--admin-brand-100);
            background: var(--admin-brand-50) !important;
            color: var(--admin-brand-800) !important;
            transform: translateX(3px);
        }

        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"] {
            border-color: var(--admin-brand-200);
            background: var(--admin-brand-50) !important;
            color: var(--admin-brand-900) !important;
            box-shadow: inset 3px 0 0 var(--admin-brand-600);
        }

        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"]>span:first-child {
            background: var(--admin-brand-700) !important;
        }

        .rhu-admin-ui .admin-main {
            width: min(100%, 1600px);
            max-width: none !important;
            margin-inline: auto;
            padding: 1.25rem clamp(1rem, 2.2vw, 2rem) 2.5rem;
        }

        .rhu-admin-ui .admin-main>section,
        .rhu-admin-ui .admin-main>div {
            position: relative;
        }

        .rhu-admin-ui .admin-page-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.1rem 1.25rem;
            border: 1px solid var(--admin-line);
            border-radius: 1rem;
            background: rgba(255, 255, 255, .9);
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
            backdrop-filter: blur(14px);
        }

        .rhu-admin-ui .admin-main .bg-white {
            background-color: rgba(255, 255, 255, .93) !important;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .07);
            backdrop-filter: blur(10px);
        }

        @media (max-width: 767px) {
            body.rhu-admin-ui {
                background-position: 58% center;
                background-attachment: scroll;
            }
        }

        .rhu-admin-ui .admin-page-heading-icon {
            display: grid;
            width: 2.75rem;
            height: 2.75rem;
            flex: 0 0 2.75rem;
            place-items: center;
            border-radius: .85rem;
            background: var(--admin-brand-50);
            color: var(--admin-brand-700);
            box-shadow: inset 0 0 0 1px var(--admin-brand-100);
        }

        .rhu-admin-ui .admin-page-heading h2 {
            font-size: clamp(1.05rem, 2vw, 1.35rem);
            font-weight: 850;
            letter-spacing: -.02em;
        }

        .rhu-admin-ui .admin-page-heading p {
            margin-top: .2rem;
            color: var(--admin-muted);
            font-size: .78rem;
        }

        .rhu-admin-ui .admin-live-badge {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            flex: 0 0 auto;
            padding: .5rem .7rem;
            border: 1px solid var(--admin-brand-200);
            border-radius: 999px;
            background: var(--admin-brand-50);
            color: var(--admin-brand-800);
            font-size: .68rem;
            font-weight: 800;
        }

        .rhu-admin-ui .admin-live-badge::before {
            content: "";
            width: .45rem;
            height: .45rem;
            border-radius: 999px;
            background: var(--admin-brand-500);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, .12);
        }

        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white {
            border-color: var(--admin-line);
            box-shadow: 0 5px 18px rgba(15, 23, 42, .04);
        }

        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white:hover {
            transform: translateY(-1px);
            border-color: #cbd5e1;
            box-shadow: 0 10px 25px rgba(15, 23, 42, .07);
        }

        .rhu-admin-ui .admin-main table {
            border-collapse: separate;
            border-spacing: 0;
        }

        .rhu-admin-ui .admin-main table thead {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
        }

        .rhu-admin-ui .admin-main table th {
            padding-block: .75rem;
            color: #475569;
            font-size: .68rem;
            font-weight: 800;
            letter-spacing: .035em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .rhu-admin-ui .admin-main table td {
            padding-block: .8rem;
            vertical-align: middle;
        }

        .rhu-admin-ui .admin-main table tbody tr {
            transition: background-color 150ms ease;
        }

        .rhu-admin-ui .admin-main table tbody tr:hover {
            background: #f8fafc;
        }

        .rhu-admin-ui .admin-main .overflow-x-auto {
            border-radius: .8rem;
            scrollbar-width: thin;
            scrollbar-color: var(--admin-brand-200) transparent;
        }

        .rhu-admin-ui .admin-main table+*,
        .rhu-admin-ui .admin-main [class*="border-b"][class*="bg-gray-50"] {
            border-color: var(--admin-line);
        }

        .rhu-admin-ui .admin-main :is(input, select, textarea) {
            min-height: 2.7rem;
            border-color: #cbd5e1;
            background: #fff;
        }

        .rhu-admin-ui .admin-main textarea {
            min-height: 5rem;
        }

        .rhu-admin-ui .admin-main label {
            color: #334155;
            font-weight: 700;
        }

        .rhu-admin-ui :is(button, a):focus-visible {
            outline: 3px solid rgba(16, 185, 129, .32);
            outline-offset: 3px;
        }

        .rhu-admin-ui details {
            border-color: var(--admin-line) !important;
            background: #fff;
        }

        .rhu-admin-ui details summary {
            min-height: 3rem;
        }

        .rhu-admin-ui .scroll-top-button {
            background: var(--admin-brand-700) !important;
            color: #fff !important;
        }

        @media (max-width: 767px) {
            .rhu-admin-ui .admin-shell-header>div {
                padding-block: .65rem;
            }

            .rhu-admin-ui .staff-logout-trigger {
                width: 2.75rem;
                height: 2.75rem;
                padding: 0;
            }

            .rhu-admin-ui .staff-logout-label {
                display: none;
            }

            .rhu-admin-ui .admin-brand-logo {
                width: 38px;
                height: 38px;
            }

            .rhu-admin-ui .admin-main {
                padding-top: .9rem;
            }

            .rhu-admin-ui .admin-page-heading {
                align-items: flex-start;
                padding: .9rem;
            }

            .rhu-admin-ui .admin-live-badge {
                padding: .4rem;
                font-size: 0;
            }

            .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white {
                padding: .9rem;
            }
        }

        @media (max-width: 420px) {
            .rhu-admin-ui .admin-shell-header h1 {
                font-size: .95rem;
            }

            .rhu-admin-ui .admin-shell-header p {
                max-width: 12rem;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .rhu-admin-ui .admin-page-heading-icon {
                width: 2.4rem;
                height: 2.4rem;
                flex-basis: 2.4rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .rhu-admin-ui *,
            .rhu-admin-ui *::before,
            .rhu-admin-ui *::after {
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }

        /*
         * Unified admin + RHU clinical dashboard skin aligned with the landing page.
         */

        body.rhu-admin-ui {
            --dash-pink: #a7d291;
            --dash-purple: #c6dfab;
            --dash-cyan: #dff5c7;
            --dash-amber: #edf9d4;
            --dash-orange: #8f3f18;
            --dash-ink: #172015;
            --dash-muted: #5f6b58;
            --dash-line: #e5eadf;
            --dash-canvas: #faf9f3;
            --dash-soft: #f4f7ee;
            --dash-deep: #082812;
            background: var(--dash-canvas) !important;
        }

        body.rhu-admin-ui::before {
            background:
                radial-gradient(circle at 88% 10%, rgba(169, 200, 119, .18), transparent 22rem),
                radial-gradient(circle at 8% 78%, rgba(40, 91, 45, .10), transparent 22rem) !important;
        }

        .rhu-admin-ui>.min-h-screen {
            min-height: 100vh;
        }

        .rhu-admin-ui .admin-shell-header {
            top: 0;
            background: linear-gradient(90deg, #0a3d35 0%, #0d4c42 50%, #0a5a52 100%) !important;
            border-bottom: 1px solid rgba(196, 227, 201, 0.24) !important;
            color: #f4faf7 !important;
            box-shadow: 0 10px 28px rgba(7, 41, 34, 0.2) !important;
        }

        .rhu-admin-ui .admin-shell-header,
        .rhu-admin-ui .admin-main {
            transition: margin-left .25s ease, width .25s ease;
        }

        .rhu-admin-ui .admin-shell-header h1,
        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-shell-header span,
        .rhu-admin-ui .admin-shell-header a,
        .rhu-admin-ui .admin-shell-header button {
            color: #f4faf7 !important;
        }

        .rhu-admin-ui .admin-shell-header [class*="bg-white/10"],
        .rhu-admin-ui .admin-shell-header a,
        .rhu-admin-ui .admin-shell-header button {
            background: rgba(255, 255, 255, 0.06) !important;
            border-color: rgba(232, 247, 238, 0.2) !important;
        }

        .rhu-admin-ui .admin-shell-header .staff-logout-trigger {
            color: #f4faf7 !important;
            background: #f3faf4 !important;
            border-color: rgba(215, 232, 217, 0.28) !important;
            color: #0d4038 !important;
        }

        .rhu-admin-ui .admin-brand-logo {
            border-radius: 50%;
            border: 0;
            box-shadow: none;
        }

        .rhu-admin-ui .admin-feature-drawer {
            background: #fff !important;
            border-right: 1px solid var(--dash-line) !important;
            box-shadow: 12px 0 34px rgba(13, 53, 24, .06) !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child {
            min-height: 74px;
            background: linear-gradient(135deg, var(--dash-deep), var(--dash-purple)) !important;
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child p,
        .rhu-admin-ui .admin-feature-drawer>div:first-child button {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child>div>span {
            background: rgba(255, 255, 255, .18) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav {
            padding: 1rem .85rem;
        }

        .rhu-admin-ui .admin-feature-drawer nav h2 {
            color: #89957f;
            letter-spacing: 0 !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a {
            min-height: 2.45rem;
            border-radius: .55rem;
            color: var(--dash-muted) !important;
            font-size: .78rem;
            box-shadow: none !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a:hover,
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"] {
            background: #eef5e5 !important;
            border-color: transparent !important;
            color: var(--dash-pink) !important;
            transform: none;
            box-shadow: inset 3px 0 0 var(--dash-pink) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a>span:first-child,
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"]>span:first-child {
            width: 1.75rem;
            height: 1.75rem;
            background: transparent !important;
            color: currentColor !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:last-child {
            background: #fff !important;
        }

        .rhu-admin-ui .admin-main {
            width: min(100%, 1420px);
            padding: 1.15rem clamp(.9rem, 1.7vw, 1.5rem) 2rem;
        }

        .rhu-admin-ui .admin-page-heading {
            border-color: var(--dash-line);
            border-radius: .65rem;
            background: #fff;
            box-shadow: 0 10px 22px rgba(13, 53, 24, .055);
        }

        .rhu-admin-ui .admin-page-heading-icon {
            background: linear-gradient(135deg, #eef5e5, #f4f7ee);
            color: var(--dash-pink);
            box-shadow: inset 0 0 0 1px #dfe8d5;
        }

        .rhu-admin-ui .admin-page-heading h2,
        .rhu-admin-ui .admin-main h2,
        .rhu-admin-ui .admin-main h3 {
            color: var(--dash-ink) !important;
            letter-spacing: 0 !important;
        }

        .rhu-admin-ui .admin-page-heading p,
        .rhu-admin-ui .admin-main p,
        .rhu-admin-ui .admin-main .text-gray-500,
        .rhu-admin-ui .admin-main .text-gray-400 {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-live-badge {
            border-color: #dfe8d5;
            background: #f2f6e9;
            color: var(--dash-pink);
        }

        .rhu-admin-ui .admin-live-badge::before {
            background: var(--dash-cyan);
            box-shadow: 0 0 0 4px rgba(169, 200, 119, .18);
        }

        .rhu-admin-ui .admin-main .bg-white,
        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white {
            border: 1px solid rgba(223, 236, 216, .9) !important;
            border-radius: .65rem !important;
            background: rgba(255, 255, 255, .70) !important;
            backdrop-filter: blur(10px) saturate(1.08) !important;
            -webkit-backdrop-filter: blur(10px) saturate(1.08) !important;
            box-shadow: 0 10px 24px rgba(13, 53, 24, .10) !important;
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main .bg-white>*,
        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white>* {
            color: inherit !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(1),
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(2),
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(3),
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(4) {
            color: #fff !important;
            overflow: hidden;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(1) {
            background: linear-gradient(135deg, #0d3518, #285b2d) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(2) {
            background: linear-gradient(135deg, #285b2d, #4f7a39) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(3) {
            background: linear-gradient(135deg, #082812, #35663a) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(4) {
            background: linear-gradient(135deg, #285b2d, #8f3f18) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white :is(p, span, svg) {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white>div:first-child {
            background: rgba(255, 255, 255, .17) !important;
        }

        .unified-chart-card {
            position: relative;
            min-height: 286px;
            overflow: hidden;
            border-radius: 1.1rem;
            background: linear-gradient(145deg, #ffffff 0%, #f1f8fb 100%);
            border: 1px solid #c9e1e8;
            box-shadow: inset 0 1px 0 #ffffff, 0 16px 30px rgba(15, 65, 86, .1);
            animation: chartFadeIn .85s ease-out both;
        }

        .unified-chart-heading {
            position: absolute;
            top: 14px;
            right: 18px;
            left: 18px;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #164e63;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .unified-chart-heading strong {
            padding: 4px 8px;
            border: 1px solid #b9dbe3;
            border-radius: 999px;
            background: #e8f4f7;
            color: #0f5f73;
            font-size: 9px;
        }

        .unified-chart-grid {
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle at 90% 12%, rgba(20, 157, 173, .14), transparent 34%), linear-gradient(to bottom, rgba(20, 93, 116, .04), rgba(20, 93, 116, 0));
            animation: gridPulse .7s ease-out both;
        }

        .unified-chart-svg {
            position: absolute;
            inset: 30px 12px 18px 12px;
            width: calc(100% - 24px);
            height: calc(100% - 32px);
            overflow: visible;
        }

        .unified-chart-line {
            fill: none;
            stroke: url(#overviewChartStroke);
            stroke-width: 3.2;
            stroke-linecap: round;
            stroke-linejoin: round;
            filter: drop-shadow(0 6px 12px rgba(15, 87, 105, .2));
            stroke-dasharray: 900;
            stroke-dashoffset: 900;
            animation: chartLineDraw 1.25s cubic-bezier(.22, .8, .3, 1) .2s forwards;
        }

        .unified-chart-fill {
            fill: url(#overviewChartArea);
            opacity: 0;
            transform-origin: center bottom;
            animation: chartAreaReveal .75s ease-out .8s forwards;
        }

        .unified-chart-soft-fill {
            fill: rgba(184, 225, 218, .34);
            opacity: 0;
            animation: chartAreaReveal .8s ease-out .55s forwards;
        }

        .unified-chart-baseline {
            stroke: rgba(15, 95, 115, .28);
            stroke-width: 1.2;
        }

        .unified-chart-gridline {
            stroke: rgba(30, 108, 130, .12);
            stroke-dasharray: 3 7;
            stroke-width: 1;
        }

        .unified-chart-month {
            fill: #244b5a;
            font-size: 10px;
            font-weight: 800;
            paint-order: stroke;
            stroke: rgba(255, 255, 255, .7);
            stroke-width: 2px;
        }

        .unified-chart-dot {
            position: absolute;
            width: .7rem;
            height: .7rem;
            border: 2px solid #fff;
            border-radius: 999px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
            animation: none;
        }

        .unified-chart-dot-one {
            left: 58%;
            top: 36%;
            background: var(--dash-amber);
        }

        .unified-chart-dot-two {
            left: 54%;
            top: 58%;
            background: var(--dash-purple);
        }

        .unified-chart-point {
            fill: #ffffff;
            stroke: #087f8c;
            stroke-width: 2;
            cursor: pointer;
            filter: drop-shadow(0 4px 8px rgba(11, 70, 92, .2));
            opacity: 0;
            transition: fill .18s ease, transform .18s ease, stroke-width .18s ease, r .18s ease;
            transform-origin: center;
            animation: chartPointReveal .42s cubic-bezier(.2, .85, .3, 1) forwards;
        }

        .unified-chart-point:nth-of-type(1) { animation-delay: .78s; }
        .unified-chart-point:nth-of-type(2) { animation-delay: .84s; }
        .unified-chart-point:nth-of-type(3) { animation-delay: .9s; }
        .unified-chart-point:nth-of-type(4) { animation-delay: .96s; }
        .unified-chart-point:nth-of-type(5) { animation-delay: 1.02s; }
        .unified-chart-point:nth-of-type(6) { animation-delay: 1.08s; }
        .unified-chart-point:nth-of-type(7) { animation-delay: 1.14s; }
        .unified-chart-point:nth-of-type(8) { animation-delay: 1.2s; }
        .unified-chart-point:nth-of-type(9) { animation-delay: 1.26s; }
        .unified-chart-point:nth-of-type(10) { animation-delay: 1.32s; }
        .unified-chart-point:nth-of-type(11) { animation-delay: 1.38s; }
        .unified-chart-point:nth-of-type(12) { animation-delay: 1.44s; }

        .unified-chart-point:hover {
            fill: #b9f2e6;
            stroke: #075985;
            stroke-width: 3;
            r: 7;
        }

        .unified-chart-point.is-active {
            fill: #f8d477;
            stroke: #164e63;
            stroke-width: 3;
            transform: scale(1.24);
        }

        .unified-chart-hover-line {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            background: linear-gradient(to bottom, rgba(14, 116, 144, 0), rgba(14, 116, 144, .7), rgba(14, 116, 144, 0));
            opacity: 0;
            transform: translateX(-50%);
            transition: opacity .15s ease;
            pointer-events: none;
        }

        .unified-chart-hover-line.is-visible {
            opacity: 1;
        }

        .unified-chart-tooltip {
            position: absolute;
            left: 0;
            top: 58%;
            transform: translate(-50%, -50%) scale(.94);
            min-width: 112px;
            padding: 8px 10px;
            border-radius: 12px;
            border: 1px solid rgba(125, 211, 220, .55);
            background: rgba(15, 55, 74, .97);
            color: #f4fbfc !important;
            box-shadow: 0 16px 28px rgba(8, 44, 61, .25);
            opacity: 0;
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease;
            z-index: 5;
        }

        .unified-chart-tooltip.is-visible {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        .unified-chart-tooltip-label {
            display: block;
            font-size: 9px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #b9e5eb;
        }

        .unified-chart-tooltip-value {
            display: block;
            margin-top: 2px;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
            color: #ffffff !important;
        }

        .unified-donut {
            width: min(168px, 46vw);
            aspect-ratio: 1;
            border-radius: 50%;
            background: conic-gradient(#8bb36a 0 var(--users-pct, 35%),
                    #a7d291 var(--users-pct, 35%) calc(var(--users-pct, 35%) + var(--staff-pct, 40%)),
                    #dceeb9 calc(var(--users-pct, 35%) + var(--staff-pct, 40%)) calc(var(--users-pct, 35%) + var(--staff-pct, 40%) + var(--bhw-pct, 25%)),
                    #edf5d1 calc(var(--users-pct, 35%) + var(--staff-pct, 40%) + var(--bhw-pct, 25%)) 100%);
            position: relative;
            transform: scale(0.92);
            animation: donutReveal .9s cubic-bezier(.2, .7, .2, 1) both;
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .04);
        }

        .unified-donut::after {
            content: "";
            position: absolute;
            inset: 23%;
            border-radius: inherit;
            background: #fff;
            box-shadow: inset 0 0 0 1px rgba(17, 24, 39, .04);
        }

        .overview-card-polished {
            border: 1px solid #c9e1e8 !important;
            background: linear-gradient(145deg, rgba(255, 255, 255, .96), rgba(241, 248, 251, .92)) !important;
            box-shadow: 0 18px 42px rgba(15, 65, 86, .1), inset 0 1px 0 rgba(255, 255, 255, .9) !important;
        }

        .overview-clinical-heading {
            color: #0f3f52 !important;
        }

        .overview-clinical-subtitle {
            color: #557383 !important;
        }

        .overview-clinical-legend {
            color: #31596b !important;
        }

        .overview-clinical-legend i:first-child {
            background: #0d9488 !important;
        }

        .overview-clinical-legend i:last-child {
            background: #f2b84b !important;
        }

        .overview-clinical-stat {
            color: #0f3f52 !important;
        }

        .overview-clinical-label {
            color: #557383 !important;
        }

        .overview-clinical-action {
            border: 1px solid #0f7490 !important;
            background: linear-gradient(135deg, #075985, #0d9488) !important;
            color: #ffffff !important;
            box-shadow: 0 8px 18px rgba(7, 89, 133, .2) !important;
        }

        .overview-clinical-action:hover {
            background: linear-gradient(135deg, #064e73, #087f73) !important;
        }

        .official-certificate-template {
            position: relative;
            overflow: hidden;
            margin: 0 auto;
            width: min(100%, 760px);
            min-height: 1040px;
            border: 0;
            background: #fff;
            padding: 58px 68px 44px;
            color: #050505;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.45;
            box-shadow: 0 1px 0 rgba(15, 23, 42, .08);
        }

        .official-certificate-template .cert-header {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 112px 1fr 112px;
            align-items: center;
            text-align: center;
            margin-bottom: 10px;
        }

        .official-certificate-template .cert-seal {
            width: 96px;
            height: 96px;
            object-fit: contain;
            justify-self: center;
        }

        .official-certificate-template .cert-watermark {
            position: absolute;
            z-index: 0;
            left: 50%;
            top: 285px;
            width: 560px;
            height: 560px;
            transform: translateX(-50%);
            object-fit: contain;
            opacity: .1;
            pointer-events: none;
        }

        .official-certificate-template .cert-header-copy {
            font-size: 11px;
            line-height: 1.2;
        }

        .official-certificate-template .cert-header-copy p {
            margin: 0;
        }

        .official-certificate-template .cert-republic {
            font-family: Georgia, "Times New Roman", serif;
            font-style: italic;
            font-size: 13px;
        }

        .official-certificate-template .cert-rule {
            position: relative;
            z-index: 1;
            border-top: 2px solid #111;
            border-bottom: 1px solid #111;
            height: 4px;
            margin: 6px 0 42px;
        }

        .official-certificate-template h1,
        .official-certificate-template h2,
        .official-certificate-template h3 {
            position: relative;
            z-index: 1;
            margin: 5px 0;
            text-align: center;
            font-weight: 900;
            text-transform: uppercase;
        }

        .official-certificate-template h1 {
            font-size: 18px;
            letter-spacing: 0;
        }

        .official-certificate-template h2 {
            font-size: 21px;
            font-style: italic;
        }

        .official-certificate-template h3 {
            font-size: 28px;
            letter-spacing: 0;
            margin-bottom: 2px;
        }

        .official-certificate-template .cert-no {
            position: relative;
            z-index: 1;
            text-align: center;
            font-family: "Courier New", monospace;
            font-size: 10px;
            font-weight: 700;
            color: #334155;
            margin: 0 0 44px;
        }

        .official-certificate-template .cert-body {
            position: relative;
            z-index: 1;
            margin: 0;
            font-size: 12px;
            line-height: 1.65;
            text-align: justify;
        }

        .official-certificate-template .cert-body p {
            margin: 0 0 18px;
            text-indent: 34px;
        }

        .official-certificate-template .cert-body .cert-greeting {
            text-indent: 0;
            margin-bottom: 26px;
            text-align: left;
        }

        .official-certificate-template .cert-dates {
            display: flex;
            gap: 34px;
            margin-top: 6px;
            font-size: 10px;
            text-align: left;
        }

        .official-certificate-template .cert-signatures {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 52px;
            margin-top: 118px;
            text-align: center;
        }

        .official-certificate-template .cert-signatures>div {
            min-height: 104px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            font-size: 12px;
        }

        .official-certificate-template .cert-signatures strong {
            display: block;
            min-width: 250px;
            padding-top: 5px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .official-certificate-template .signature-wrap {
            position: relative;
            width: 170px;
            height: 58px;
            margin: 0 auto;
        }

        .official-certificate-template .certificate-signature-image {
            position: absolute;
            left: 50%;
            bottom: 6px;
            transform: translateX(-50%);
            display: block;
            width: 170px;
            height: 52px;
            object-fit: contain;
            object-position: center bottom;
            mix-blend-mode: multiply;
            z-index: 2;
        }

        .official-certificate-template .signature-line {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 1px;
            width: 170px;
            margin: 0;
            border-top: 1px solid #111;
            background: transparent;
        }

        .official-certificate-template small,
        .official-certificate-template .cert-footer {
            display: block;
            color: #111;
            font-size: 10px;
        }

        .official-certificate-template .cert-footer {
            position: absolute;
            z-index: 1;
            left: 68px;
            right: 68px;
            bottom: 40px;
            display: flex;
            justify-content: space-between;
            border-top: 1px solid #64748b;
            padding-top: 6px;
            font-family: "Courier New", monospace;
            color: #0f172a;
        }

        @media (max-width: 820px) {
            .official-certificate-template {
                width: 680px;
                padding: 48px 50px 38px;
            }
        }

        @keyframes drawLine {
            to {
                stroke-dashoffset: 0;
            }
        }

        @keyframes fillRise {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes donutReveal {
            from {
                opacity: 0;
                transform: scale(.75) rotate(-12deg);
            }

            to {
                opacity: 1;
                transform: scale(1) rotate(0deg);
            }
        }

        @keyframes chartFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes gridPulse {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes chartLineDraw {
            to {
                stroke-dashoffset: 0;
            }
        }

        @keyframes chartAreaReveal {
            from {
                opacity: 0;
                transform: scaleY(.08);
            }

            to {
                opacity: 1;
                transform: scaleY(1);
            }
        }

        @keyframes chartPointReveal {
            from {
                opacity: 0;
                transform: scale(.35);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes dotPulse {
            from {
                transform: scale(.92);
            }

            to {
                transform: scale(1.12);
            }
        }

        .staff-folder-panel {
            overflow: hidden;
        }

        .staff-folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
            gap: 1rem .85rem;
        }

        .staff-folder-card {
            display: grid;
            justify-items: center;
            align-content: start;
            gap: .28rem;
            min-height: 104px;
            padding: .35rem .25rem .55rem;
            border: 1px solid transparent;
            border-radius: .65rem;
            color: var(--dash-muted) !important;
            text-align: center;
            box-shadow: none !important;
        }

        .staff-folder-card:hover,
        .staff-folder-card.is-active {
            background: #eef5e5 !important;
            border-color: #dfe8d5 !important;
            color: var(--dash-pink) !important;
            transform: translateY(-2px);
        }

        .staff-folder-icon {
            position: relative;
            width: 68px;
            height: 46px;
            margin-bottom: .15rem;
            border-radius: 7px 7px 5px 5px;
            background: linear-gradient(180deg, #d7d9db 0%, #aeb2b4 72%, #989da0 100%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .72),
                inset 0 -2px 0 rgba(80, 85, 90, .16),
                0 6px 12px rgba(39, 45, 52, .16);
        }

        .staff-folder-icon::before {
            content: "";
            position: absolute;
            left: 0;
            top: -7px;
            width: 31px;
            height: 11px;
            border-radius: 6px 6px 0 0;
            background: linear-gradient(180deg, #bfc3c5, #a2a7aa);
        }

        .staff-folder-card.is-active .staff-folder-icon,
        .staff-folder-card:hover .staff-folder-icon {
            background: linear-gradient(180deg, #d7efb4 0%, #8fb56f 72%, #285b2d 100%);
        }

        .staff-folder-card.is-active .staff-folder-icon::before,
        .staff-folder-card:hover .staff-folder-icon::before {
            background: linear-gradient(180deg, #e5f4ca, #9fc27b);
        }

        .staff-folder-name {
            max-width: 94px;
            color: inherit !important;
            font-size: .68rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .staff-folder-count {
            color: #8b957f !important;
            font-size: .58rem;
            font-weight: 700;
        }

        .rhu-admin-ui .staff-folder-card.is-active {
            background: #063f2f !important;
            border-color: #b7d78a !important;
            box-shadow: 0 14px 28px rgba(6, 63, 47, .18) !important;
        }

        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-name,
        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-count {
            color: #f4faf0 !important;
            opacity: 1 !important;
        }

        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-icon {
            background: linear-gradient(180deg, #e2f5bf 0%, #a8d47c 65%, #6f9d4f 100%) !important;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .78),
                inset 0 -2px 0 rgba(8, 40, 18, .16),
                0 8px 16px rgba(4, 33, 23, .28) !important;
        }

        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-icon::before {
            background: linear-gradient(180deg, #ecf8cf, #b9dc90) !important;
        }

        .rhu-admin-ui .admin-main details.mb-4 {
            display: inline-block;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            overflow: visible;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary {
            width: fit-content;
            min-height: 44px;
            padding: 0 .95rem;
            border: 1px solid rgba(13, 53, 24, .22);
            border-radius: 999px;
            background: linear-gradient(135deg, #bfe4b5, var(--dash-pink));
            color: #21472b !important;
            list-style: none;
            justify-content: center;
            gap: .55rem;
            box-shadow: 0 12px 24px rgba(110, 154, 98, .18);
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary::-webkit-details-marker {
            display: none;
        }

        .rhu-admin-ui .admin-main details.mb-4[open]>summary {
            border-color: rgba(40, 91, 45, .28);
            background: linear-gradient(135deg, var(--dash-purple), var(--dash-deep));
        }

        .rhu-admin-ui .admin-main details.mb-4>summary::before {
            content: "";
            order: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: .48rem;
            height: .48rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(-45deg);
            transition: transform .18s ease;
        }

        .rhu-admin-ui .admin-main details.mb-4[open]>summary::before {
            content: "";
            transform: rotate(45deg);
        }

        .rhu-admin-ui .admin-main details.mb-4>summary:hover::before {
            color: inherit;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary:hover {
            filter: brightness(1.03);
            box-shadow: 0 16px 30px rgba(13, 53, 24, .24);
            transform: translateY(-1px);
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span {
            font-size: .9rem;
            font-weight: 900;
            min-width: 0;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span:first-child {
            display: none;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span:first-child::before {
            content: "";
            width: .45rem;
            height: .45rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(-45deg);
            transition: transform .18s ease;
        }

        .rhu-admin-ui .admin-main details.mb-4[open]>summary>span:first-child::before {
            transform: rotate(45deg);
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span:last-child {
            order: 1;
            flex: 0 1 auto;
        }

        .rhu-admin-ui .admin-main details.mb-4>div {
            width: min(100%, 100vw - 2rem);
            margin-top: .9rem;
            padding: 1rem;
            border: 1px solid var(--dash-line);
            border-radius: .85rem;
            background: #fff;
            box-shadow: 0 12px 28px rgba(13, 53, 24, .055);
        }

        .rhu-admin-ui .admin-main details.mb-4 form {
            border-radius: .75rem;
        }

        .rhu-admin-ui .admin-main details.mb-4 form.grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: .75rem !important;
            padding: 1rem;
            border: 1px solid #dfe8d5;
            background: #fbfaf5;
        }

        .rhu-admin-ui .admin-main details.mb-4 form.flex {
            display: grid !important;
            grid-template-columns: minmax(220px, 1fr) auto;
            align-items: stretch;
            gap: .75rem !important;
            padding: 1rem;
            border: 1px solid #f3d6c4;
            background: #fff8f1;
        }

        .rhu-admin-ui .admin-main details.mb-4 input,
        .rhu-admin-ui .admin-main details.mb-4 select,
        .create-staff-panel input,
        .create-staff-panel select {
            min-height: 44px;
            border: 1px solid #dce4d4 !important;
            border-radius: .55rem !important;
            background: #fff !important;
            color: var(--dash-ink) !important;
            padding: .65rem .75rem !important;
            font-size: .82rem;
        }

        .rhu-admin-ui .admin-main details.mb-4 input:focus,
        .rhu-admin-ui .admin-main details.mb-4 select:focus,
        .create-staff-panel input:focus,
        .create-staff-panel select:focus {
            border-color: var(--dash-pink) !important;
            box-shadow: 0 0 0 3px rgba(40, 91, 45, .14) !important;
        }

        .rhu-admin-ui .admin-main details.mb-4 button,
        .create-staff-panel button[type="submit"] {
            min-height: 44px;
            border-radius: .55rem !important;
            background: var(--dash-pink) !important;
            color: #fff !important;
            font-weight: 900 !important;
            box-shadow: 0 10px 18px rgba(13, 53, 24, .18) !important;
        }

        .rhu-admin-ui .admin-main details.mb-4 form.flex button {
            background: var(--dash-pink) !important;
            box-shadow: 0 10px 18px rgba(13, 53, 24, .18) !important;
        }

        .create-staff-panel {
            padding: 0 !important;
            overflow: hidden;
        }

        .create-staff-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid var(--dash-line);
            background: linear-gradient(90deg, #f4f7ee, #fff);
        }

        .create-staff-heading>span {
            display: grid;
            width: 2.4rem;
            height: 2.4rem;
            place-items: center;
            border-radius: .75rem;
            background: #eef5e5;
            color: var(--dash-pink);
        }

        .create-staff-form {
            padding: 1rem !important;
        }

        .create-staff-form label {
            display: grid !important;
            gap: .4rem;
            color: var(--dash-muted) !important;
            font-size: .72rem;
            font-weight: 900 !important;
            text-transform: uppercase;
        }

        .create-staff-form .sm\:col-span-2 {
            padding-top: .4rem !important;
            border-top: 1px solid var(--dash-line);
        }

        .create-staff-form a {
            border-radius: .55rem !important;
            background: #f4f7ee !important;
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui table thead {
            background: var(--dash-deep) !important;
        }

        .rhu-admin-ui table th {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main table tbody tr:hover {
            background: #f4f7ee !important;
        }

        .rhu-admin-ui .admin-main :is(button, .rounded.bg-purple-700, .bg-purple-700, .bg-blue-600, .bg-indigo-600) {
            background-color: var(--dash-pink) !important;
        }

        .rhu-admin-ui :is(.bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100) {
            background-color: var(--dash-soft) !important;
        }

        .rhu-admin-ui :is(.text-purple-600,
            .text-purple-700,
            .text-purple-800,
            .text-purple-900,
            .text-purple-950,
            .text-blue-600,
            .text-blue-700,
            .text-blue-800,
            .text-blue-900,
            .text-indigo-800,
            .text-emerald-600,
            .text-emerald-700,
            .text-emerald-800,
            .text-emerald-900,
            .text-green-600,
            .text-green-700,
            .text-green-800,
            .text-pink-600,
            .text-red-600,
            .text-red-700,
            .text-red-800,
            .text-amber-700,
            .text-amber-800) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui :is(.border-purple-100,
            .border-purple-200,
            .border-purple-600,
            .border-blue-200,
            .border-emerald-200,
            .border-green-200,
            .border-red-100,
            .border-red-200,
            .border-amber-200) {
            border-color: var(--dash-line) !important;
        }

        .rhu-admin-ui :is(button.bg-purple-500,
            button.bg-purple-600,
            button.bg-purple-700,
            button.bg-purple-800,
            button.bg-blue-600,
            button.bg-blue-700,
            button.bg-indigo-600,
            button.bg-indigo-700,
            button.bg-emerald-600,
            button.bg-emerald-700,
            button.bg-green-600,
            button.bg-red-600,
            button.bg-red-700,
            button.bg-pink-500,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-indigo-600,
            a.bg-emerald-700) {
            background-color: var(--dash-pink) !important;
            color: #fff !important;
            box-shadow: 0 10px 18px rgba(13, 53, 24, .16) !important;
        }

        .rhu-admin-ui :is(button.hover\:bg-purple-800:hover,
            button.hover\:bg-blue-700:hover,
            button.hover\:bg-blue-800:hover,
            button.hover\:bg-indigo-800:hover,
            button.hover\:bg-emerald-700:hover,
            button.hover\:bg-emerald-800:hover,
            button.hover\:bg-green-700:hover,
            button.hover\:bg-red-700:hover,
            button.hover\:bg-red-800:hover,
            a.hover\:bg-purple-800:hover) {
            background-color: var(--dash-purple) !important;
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main,
        .rhu-admin-ui .admin-main :is(td, label, input, select, textarea, li, dd),
        .rhu-admin-ui .admin-page-heading,
        .rhu-admin-ui .admin-feature-drawer {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(h1, h2, h3, h4, h5, h6, strong, b, th),
        .rhu-admin-ui .admin-shell-header :is(h1, h2, h3, strong, b),
        .rhu-admin-ui .admin-feature-drawer :is(strong, b) {
            color: var(--dash-deep) !important;
        }

        .rhu-admin-ui .admin-main :is(p, small, figcaption),
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-gray-400,
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-gray-500,
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-slate-400,
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-slate-500,
        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-feature-drawer nav h2,
        .rhu-admin-ui .admin-feature-drawer>div:last-child {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main :is(a:not([class*="bg-"]), .font-mono) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui .admin-main :is(.bg-white, .bg-gray-50, .bg-slate-50, .bg-slate-100, .bg-gray-100, .rounded-xl, .rounded-2xl) :is(p, span, td, label, div, a):not(button *):not(.text-white):not([class*="bg-"]) {
            color: inherit;
        }

        .rhu-admin-ui .admin-main :is(.bg-white, .bg-gray-50, .bg-slate-50, .bg-slate-100, .bg-gray-100, .rounded-xl, .rounded-2xl) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(.text-gray-300,
            .text-gray-400,
            .text-gray-500,
            .text-slate-300,
            .text-slate-400,
            .text-slate-500,
            .text-purple-200,
            .text-purple-300) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main :is(.text-gray-600,
            .text-gray-700,
            .text-gray-800,
            .text-gray-900,
            .text-slate-600,
            .text-slate-700,
            .text-slate-800,
            .text-slate-900) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui,
        .rhu-admin-ui * {
            text-shadow: none !important;
        }

        .rhu-admin-ui .admin-main *,
        .rhu-admin-ui .admin-shell-header *,
        .rhu-admin-ui .admin-feature-drawer nav * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main .unified-chart-tooltip,
        .rhu-admin-ui .admin-main .unified-chart-tooltip-value {
            color: #ffffff !important;
        }

        .rhu-admin-ui .admin-main .unified-chart-tooltip-label {
            color: #b9e5eb !important;
        }

        .rhu-admin-ui .admin-main :is(h1, h2, h3, h4, h5, h6, strong, b, th, .font-bold, .font-black, .font-semibold) {
            color: var(--dash-deep) !important;
        }

        .rhu-admin-ui .admin-main :is(p, small, figcaption, .text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500),
        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-feature-drawer nav h2 {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main :is(a, .font-mono):not(button):not([class*="bg-"]) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui :is(button,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-blue-700,
            a.bg-indigo-600,
            a.bg-indigo-700,
            a.bg-emerald-600,
            a.bg-emerald-700,
            a.bg-green-600,
            a.bg-red-600,
            a.bg-red-700,
            a.bg-pink-500,
            .admin-main details.mb-4 > summary),
        .rhu-admin-ui :is(button,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-blue-700,
            a.bg-indigo-600,
            a.bg-indigo-700,
            a.bg-emerald-600,
            a.bg-emerald-700,
            a.bg-green-600,
            a.bg-red-600,
            a.bg-red-700,
            a.bg-pink-500,
            .admin-main details.mb-4 > summary) * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child,
        .rhu-admin-ui .admin-feature-drawer>div:first-child * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main :is(.bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-white),
        .rhu-admin-ui .admin-main :is(.bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-white) *:not(button):not(button *) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui :is(button.text-red-600, button.text-red-700, button.text-red-800, a.text-red-600, a.text-red-700, a.text-red-800):not([class*="bg-"]) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui .admin-main :is(input::placeholder, textarea::placeholder) {
            color: #65745d !important;
            opacity: 1 !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white *,
        .rhu-admin-ui table thead,
        .rhu-admin-ui table thead *,
        .rhu-admin-ui .admin-feature-drawer>div:first-child,
        .rhu-admin-ui .admin-feature-drawer>div:first-child *,
        .rhu-admin-ui :is(button, button *, a[class*="bg-"], a[class*="bg-"] *) {
            color: #fff !important;
        }

        .rhu-admin-ui :is(.bg-white,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .rounded-xl,
            .rounded-2xl,
            .admin-page-heading,
            .create-staff-panel),
        .rhu-admin-ui :is(.bg-white,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .rounded-xl,
            .rounded-2xl,
            .admin-page-heading,
            .create-staff-panel) *:not(button):not(button *):not(a[class*="bg-"]):not(a[class*="bg-"] *) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui :is(.bg-white,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .rounded-xl,
            .rounded-2xl,
            .admin-page-heading,
            .create-staff-panel) :is(p, small, figcaption, .text-xs, .text-sm, .text-gray-300, .text-gray-400, .text-gray-500, .text-slate-300, .text-slate-400, .text-slate-500):not(button):not(button *) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui :is(.bg-black,
            .bg-gray-800,
            .bg-gray-900,
            .bg-slate-800,
            .bg-slate-900,
            .bg-slate-950,
            .bg-green-800,
            .bg-green-900,
            .bg-emerald-800,
            .bg-emerald-900,
            .bg-teal-800,
            .bg-teal-900,
            .bg-cyan-900,
            .from-emerald-800,
            .from-green-900,
            .from-slate-900,
            .via-teal-800,
            .to-cyan-900,
            .to-green-900,
            .to-slate-950,
            table thead),
        .rhu-admin-ui :is(.bg-black,
            .bg-gray-800,
            .bg-gray-900,
            .bg-slate-800,
            .bg-slate-900,
            .bg-slate-950,
            .bg-green-800,
            .bg-green-900,
            .bg-emerald-800,
            .bg-emerald-900,
            .bg-teal-800,
            .bg-teal-900,
            .bg-cyan-900,
            .from-emerald-800,
            .from-green-900,
            .from-slate-900,
            .via-teal-800,
            .to-cyan-900,
            .to-green-900,
            .to-slate-950,
            table thead) * {
            color: #fff !important;
        }

        .rhu-admin-ui :is(.bg-black,
            .bg-gray-800,
            .bg-gray-900,
            .bg-slate-800,
            .bg-slate-900,
            .bg-slate-950,
            .bg-green-800,
            .bg-green-900,
            .bg-emerald-800,
            .bg-emerald-900,
            .bg-teal-800,
            .bg-teal-900,
            .bg-cyan-900,
            .from-emerald-800,
            .from-green-900,
            .from-slate-900,
            .via-teal-800,
            .to-cyan-900,
            .to-green-900,
            .to-slate-950,
            table thead) :is(p, small, span, .text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500, .text-purple-200, .text-purple-300) {
            color: #e9f2df !important;
        }

        .rhu-admin-ui .admin-shell-header,
        .rhu-admin-ui .admin-shell-header * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-shell-header :is(.text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500, .text-purple-200, .text-purple-300) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white *,
        .rhu-admin-ui .admin-main [style*="background: linear-gradient"],
        .rhu-admin-ui .admin-main [style*="background: linear-gradient"] *,
        .rhu-admin-ui .admin-main [class*="bg-gradient-to-"],
        .rhu-admin-ui .admin-main [class*="bg-gradient-to-"] * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white :is(p, small, span, .text-xs, .text-sm),
        .rhu-admin-ui .admin-main [style*="background: linear-gradient"] :is(p, small, span, .text-xs, .text-sm),
        .rhu-admin-ui .admin-main [class*="bg-gradient-to-"] :is(p, small, span, .text-xs, .text-sm) {
            color: #e9f2df !important;
        }

        @media (min-width: 1024px) {
            body.rhu-admin-ui {
                overflow: hidden;
            }

            .rhu-admin-ui>.min-h-screen {
                height: 100vh;
                overflow: hidden;
            }

            .rhu-admin-ui .admin-feature-drawer {
                transform: none !important;
                visibility: visible !important;
                max-width: none;
                width: 220px;
            }

            .rhu-admin-ui .feature-drawer-backdrop,
            .rhu-admin-ui [data-drawer-open],
            .rhu-admin-ui [data-drawer-close] {
                display: none !important;
            }

            .rhu-admin-ui .admin-shell-header {
                margin-left: 220px;
                position: fixed !important;
                right: 0;
                left: 0;
                z-index: 45;
            }

            .rhu-admin-ui .admin-main {
                margin-left: 220px;
                width: calc(100% - 220px);
                max-width: none !important;
                height: calc(100vh - 72px);
                margin-top: 72px;
                overflow-y: auto;
                overflow-x: hidden;
                scrollbar-gutter: stable;
                scrollbar-width: thin;
                scrollbar-color: #dfe8d5 transparent;
            }

            .rhu-admin-ui .admin-main::-webkit-scrollbar,
            .rhu-admin-ui .admin-feature-drawer nav::-webkit-scrollbar {
                width: 8px;
            }

            .rhu-admin-ui .admin-main::-webkit-scrollbar-thumb,
            .rhu-admin-ui .admin-feature-drawer nav::-webkit-scrollbar-thumb {
                border-radius: 999px;
                background: #dfe8d5;
            }

            .rhu-admin-ui .admin-feature-drawer {
                z-index: 50;
            }

            .rhu-admin-ui .admin-feature-drawer nav {
                max-height: calc(100vh - 150px);
            }
        }

        @media (max-width: 767px) {
            body.rhu-admin-ui {
                overflow-x: hidden;
            }

            .rhu-admin-ui .admin-shell-header {
                position: sticky !important;
                z-index: 70;
            }

            .rhu-admin-ui [data-drawer-open] {
                min-height: 44px;
                min-width: 44px;
                position: relative;
                z-index: 80;
                touch-action: manipulation;
            }

            .rhu-admin-ui .admin-feature-drawer {
                width: min(88vw, 340px);
            }

            .rhu-admin-ui .admin-feature-drawer nav {
                max-height: calc(100vh - 150px);
            }

            .rhu-admin-ui .admin-main {
                overflow: visible;
            }

            .unified-chart-card {
                min-height: 240px;
            }

            .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .rhu-admin-ui .admin-main details.mb-4 form.grid,
            .create-staff-form {
                grid-template-columns: 1fr !important;
            }

            .rhu-admin-ui .admin-main details.mb-4 form.flex {
                grid-template-columns: 1fr;
            }

            .create-staff-heading {
                align-items: flex-start;
            }
        }

        @media (min-width: 768px) and (max-width: 1023px) {
            body.rhu-admin-ui {
                overflow-x: hidden;
            }

            .rhu-admin-ui .admin-shell-header {
                position: sticky !important;
            }

            .rhu-admin-ui .admin-feature-drawer {
                width: min(82vw, 360px);
            }

            .rhu-admin-ui .admin-main {
                overflow: visible;
            }

            .rhu-admin-ui .admin-main details.mb-4 form.grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        /* Final contrast guard: keep every admin label readable after Tailwind utility clashes. */
        .rhu-admin-ui .admin-main {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(h1, h2, h3, h4, h5, h6, p, span, div, td, label, li, small, strong, b, input, select, textarea):not(button):not(button *):not(a[class*="bg-"]):not(a[class*="bg-"] *) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500):not(button):not(button *):not(a[class*="bg-"]):not(a[class*="bg-"] *) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-shell-header,
        .rhu-admin-ui .admin-shell-header * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-shell-header .text-xs {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child,
        .rhu-admin-ui .admin-feature-drawer>div:first-child *,
        .rhu-admin-ui table thead,
        .rhu-admin-ui table thead *,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white *,
        .rhu-admin-ui .admin-main details.mb-4>summary,
        .rhu-admin-ui .admin-main details.mb-4>summary *,
        .rhu-admin-ui :is(button, button *, a.bg-purple-500, a.bg-purple-500 *, a.bg-purple-600, a.bg-purple-600 *, a.bg-purple-700, a.bg-purple-700 *, a.bg-blue-600, a.bg-blue-600 *, a.bg-blue-700, a.bg-blue-700 *, a.bg-indigo-600, a.bg-indigo-600 *, a.bg-indigo-700, a.bg-indigo-700 *, a.bg-emerald-600, a.bg-emerald-600 *, a.bg-emerald-700, a.bg-emerald-700 *, a.bg-green-600, a.bg-green-600 *, a.bg-red-600, a.bg-red-600 *, a.bg-red-700, a.bg-red-700 *, a.bg-pink-500, a.bg-pink-500 *) {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a,
        .rhu-admin-ui .admin-feature-drawer nav a *,
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"],
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"] * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"],
        .rhu-admin-ui .admin-feature-drawer nav a:hover {
            background: #eef5e5 !important;
            border-color: #dfe8d5 !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:last-child,
        .rhu-admin-ui .admin-feature-drawer>div:last-child * {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:last-child :is(strong, b, a) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main table thead,
        .rhu-admin-ui .admin-main table thead tr,
        .rhu-admin-ui .admin-main table thead th,
        .rhu-admin-ui .admin-main table thead td,
        .rhu-admin-ui .admin-main :is(.bg-green-900, .bg-emerald-900, .bg-slate-900, .bg-slate-950, .bg-\[\#082812\], .bg-\[\#0d3518\]) {
            background-color: #d7efb4 !important;
            color: #0d3518 !important;
        }

        .rhu-admin-ui .admin-main table thead *,
        .rhu-admin-ui .admin-main :is(.bg-green-900, .bg-emerald-900, .bg-slate-900, .bg-slate-950, .bg-\[\#082812\], .bg-\[\#0d3518\]) * {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        .rhu-admin-ui .admin-main table thead,
        .rhu-admin-ui .admin-main table thead tr,
        .rhu-admin-ui .admin-main table thead th,
        .rhu-admin-ui .admin-main table thead th *,
        .rhu-admin-ui table thead,
        .rhu-admin-ui table thead tr,
        .rhu-admin-ui table thead th,
        .rhu-admin-ui table thead th * {
            background-color: #d7efb4 !important;
            color: #0d3518 !important;
            opacity: 1 !important;
        }

        .rhu-admin-ui .admin-main table thead th {
            font-weight: 900 !important;
            text-shadow: none !important;
        }

        /* Light landing-page button palette */
        .rhu-admin-ui :is(button,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-blue-700,
            a.bg-indigo-600,
            a.bg-indigo-700,
            a.bg-emerald-600,
            a.bg-emerald-700,
            a.bg-green-600,
            a.bg-red-600,
            a.bg-red-700,
            a.bg-pink-500,
            a.bg-amber-50) {
            background-color: #c7e1aa !important;
            border: 1px solid #a7d291 !important;
            color: #0d3518 !important;
            box-shadow: 0 8px 18px rgba(13, 53, 24, .10) !important;
        }

        .rhu-admin-ui :is(button *,
            a.bg-purple-500 *,
            a.bg-purple-600 *,
            a.bg-purple-700 *,
            a.bg-blue-600 *,
            a.bg-blue-700 *,
            a.bg-indigo-600 *,
            a.bg-indigo-700 *,
            a.bg-emerald-600 *,
            a.bg-emerald-700 *,
            a.bg-green-600 *,
            a.bg-red-600 *,
            a.bg-red-700 *,
            a.bg-pink-500 *,
            a.bg-amber-50 *) {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        .rhu-admin-ui :is(button:hover,
            a.bg-purple-500:hover,
            a.bg-purple-600:hover,
            a.bg-purple-700:hover,
            a.bg-blue-600:hover,
            a.bg-blue-700:hover,
            a.bg-indigo-600:hover,
            a.bg-indigo-700:hover,
            a.bg-emerald-600:hover,
            a.bg-emerald-700:hover,
            a.bg-green-600:hover,
            a.bg-red-600:hover,
            a.bg-red-700:hover,
            a.bg-pink-500:hover,
            a.bg-amber-50:hover) {
            background-color: #b7d78a !important;
            border-color: #8fb56f !important;
            color: #082812 !important;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary,
        .rhu-admin-ui .admin-main details.mb-4>summary * {
            background: #d7efb4 !important;
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        /* Admin municipal background image */
        body.rhu-admin-ui {
            position: relative;
            background:
                linear-gradient(180deg, rgba(250, 249, 243, .88), rgba(250, 249, 243, .82)),
                url("../../../assets/admin-municipal-background.png") center center / cover fixed no-repeat,
                var(--dash-canvas) !important;
        }

        body.rhu-admin-ui::before {
            position: fixed !important;
            inset: 0 !important;
            z-index: 0 !important;
            content: "" !important;
            pointer-events: none !important;
            background: url("../../../assets/admin-municipal-background.png") center center / cover no-repeat !important;
            filter: saturate(.95) brightness(1.02) !important;
            transform: none !important;
            opacity: .58 !important;
        }

        body.rhu-admin-ui::after {
            position: fixed;
            inset: 0;
            z-index: 0;
            content: "";
            pointer-events: none;
            background:
                linear-gradient(180deg, rgba(250, 249, 243, .88), rgba(250, 249, 243, .82)),
                radial-gradient(circle at 85% 5%, rgba(169, 200, 119, .24), transparent 28rem);
        }

        .rhu-admin-ui>.min-h-screen {
            position: relative;
            z-index: 1;
        }

        html.overflow-hidden,
        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] {
            overflow: hidden !important;
            overscroll-behavior: none !important;
            touch-action: none !important;
        }

        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] [data-certificate-preview-modal],
        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] [data-floating-modal],
        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] [data-report-modal-backdrop] {
            overflow: hidden !important;
            overscroll-behavior: none !important;
            touch-action: none !important;
        }

        /* Final admin button guard */
        .rhu-admin-ui button,
        .rhu-admin-ui button[type="button"],
        .rhu-admin-ui button[type="submit"],
        .rhu-admin-ui .admin-main a[class*="bg-"],
        .rhu-admin-ui .admin-shell-header a[class*="bg-"],
        .rhu-admin-ui .scroll-top-button {
            background: #d7efb4 !important;
            background-color: #d7efb4 !important;
            border: 1px solid #a9c877 !important;
            color: #0d3518 !important;
            box-shadow: 0 8px 18px rgba(13, 53, 24, .10) !important;
        }

        .rhu-admin-ui button *,
        .rhu-admin-ui .admin-main a[class*="bg-"] *,
        .rhu-admin-ui .admin-shell-header a[class*="bg-"] *,
        .rhu-admin-ui .scroll-top-button * {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        .rhu-admin-ui button:hover,
        .rhu-admin-ui .admin-main a[class*="bg-"]:hover,
        .rhu-admin-ui .admin-shell-header a[class*="bg-"]:hover,
        .rhu-admin-ui .scroll-top-button:hover {
            background: #c8e89a !important;
            background-color: #c8e89a !important;
            border-color: #8fb56f !important;
            color: #082812 !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child button,
        .rhu-admin-ui .admin-feature-drawer>div:first-child button *,
        .rhu-admin-ui .admin-main details.mb-4>summary,
        .rhu-admin-ui .admin-main details.mb-4>summary * {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        /* Staff folder contrast guard */
        .rhu-admin-ui .admin-main .staff-folder-card {
            min-height: 116px;
            padding: .5rem .35rem .7rem;
            text-decoration: none !important;
        }

        .rhu-admin-ui .admin-main .staff-folder-card .staff-folder-name {
            max-width: 100%;
            color: #21331c !important;
            font-size: .72rem;
            line-height: 1.15;
        }

        .rhu-admin-ui .admin-main .staff-folder-card .staff-folder-count {
            color: #5f6b58 !important;
            font-size: .6rem;
            line-height: 1.1;
        }

        .rhu-admin-ui .resident-filter-name,
        .rhu-admin-ui .resident-filter-name:hover {
            color: #111827 !important;
            text-decoration: none !important;
            transform: none !important;
            transition: none !important;
            animation: none !important;
        }

        .rhu-admin-ui .assigned-records-table thead th {
            color: #334155 !important;
            font-weight: 900;
            letter-spacing: .06em;
        }

        .rhu-admin-ui .assigned-records-table {
            table-layout: fixed;
        }

        .rhu-admin-ui .assigned-records-table th:nth-child(1),
        .rhu-admin-ui .assigned-records-table td:nth-child(1) {
            width: 42%;
        }

        .rhu-admin-ui .assigned-records-table th:nth-child(2),
        .rhu-admin-ui .assigned-records-table td:nth-child(2) {
            width: 9rem;
        }

        .rhu-admin-ui .assigned-records-table th:nth-child(4),
        .rhu-admin-ui .assigned-records-table td:nth-child(4) {
            width: 11rem;
        }

        .rhu-admin-ui .assigned-resident-row {
            background: #ffffff;
            border-left: 3px solid transparent;
        }

        .rhu-admin-ui .assigned-resident-row:hover {
            background: #ffffff;
            border-left-color: transparent;
        }

        .rhu-admin-ui .resident-filter-summary {
            display: inline-block;
            min-width: 0;
            padding: 0;
            border-radius: 0;
            background: transparent !important;
            border: 0 !important;
        }

        .rhu-admin-ui .resident-record-details {
            color: #0f172a;
            max-width: 100%;
            max-height: 15rem;
            overflow-y: auto;
            scrollbar-gutter: stable;
        }

        .rhu-admin-ui .assigned-record-detail-row>td {
            background: #f8fafc;
        }
        

        .rhu-admin-ui .resident-record-item {
            min-height: 2.5rem;
            grid-template-columns: minmax(28rem, 1fr) 14rem 16rem !important;
            align-items: center;
        }

        .rhu-admin-ui .resident-record-item > :first-child {
            display: none !important;
        }

        .rhu-admin-ui .resident-record-item > :nth-child(2) {
            text-align: left;
        }

        .rhu-admin-ui .resident-record-item > :nth-child(3) {
            text-align: left;
        }

        .rhu-admin-ui .resident-record-item > :nth-child(4) {
            text-align: right;
            white-space: nowrap;
        }

        .rhu-admin-ui .resident-record-type {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 7.25rem;
            background: #064e3b !important;
            border: 1px solid #047857 !important;
            color: #ffffff !important;
            font-family: inherit;
            font-size: .68rem;
            font-weight: 900;
            letter-spacing: .01em;
        }

        .rhu-admin-ui .resident-record-type * {
            color: #ffffff !important;
        }

        .rhu-admin-ui .assigned-records-table [data-assigned-records-toggle],
        .rhu-admin-ui .assigned-records-table [data-assigned-records-toggle]:hover {
            display: inline-flex !important;
            min-width: 6.5rem !important;
            justify-content: center !important;
            appearance: none !important;
            background: #ffffff !important;
            background-color: #ffffff !important;
            background-image: none !important;
            border: 1px solid #d7e2ec !important;
            border-radius: 999px !important;
            color: #0f172a !important;
            box-shadow: none !important;
            cursor: pointer !important;
            pointer-events: auto !important;
        }

        .rhu-admin-ui .admin-consultation-detail-wrap {
            max-height: 26rem;
            overflow-y: auto;
            scrollbar-gutter: stable;
        }

        .rhu-admin-ui .admin-consultation-patient-table {
            table-layout: fixed;
        }

        .rhu-admin-ui .admin-consultation-patient-table th:nth-child(1),
        .rhu-admin-ui .admin-consultation-patient-table td:nth-child(1) {
            width: 26%;
        }

        .rhu-admin-ui .admin-consultation-patient-table th:nth-child(2),
        .rhu-admin-ui .admin-consultation-patient-table td:nth-child(2) {
            width: 16%;
        }

        .rhu-admin-ui .admin-consultation-patient-table th:nth-child(3),
        .rhu-admin-ui .admin-consultation-patient-table td:nth-child(3) {
            width: 7rem;
        }

        .rhu-admin-ui .admin-consultation-patient-table th:nth-child(5),
        .rhu-admin-ui .admin-consultation-patient-table td:nth-child(5) {
            width: 11rem;
        }

        .rhu-admin-ui .admin-consultation-record-grid {
            display: grid;
            grid-template-columns: 5.5rem minmax(12rem, 1.2fr) minmax(10rem, 1fr) 7rem;
            gap: .75rem;
            align-items: center;
        }

        .rhu-admin-ui .admin-consultation-record-grid form {
            grid-column: 1 / -1;
            display: grid !important;
            grid-template-columns: minmax(8rem, 1fr) minmax(9rem, 1fr) minmax(12rem, 1.4fr) minmax(8rem, .8fr) auto !important;
            gap: .5rem !important;
            min-width: 0 !important;
        }

        .rhu-admin-ui .admin-consultation-record-grid form :is(input, select) {
            min-width: 0 !important;
            width: 100% !important;
        }

        .rhu-admin-ui .admin-consultation-record-summary {
            grid-column: 1 / -1;
            min-width: 0;
            width: 100%;
            overflow-wrap: anywhere;
        }

        .rhu-admin-ui [data-consultation-records-toggle],
        .rhu-admin-ui [data-consultation-records-toggle]:hover {
            display: inline-flex !important;
            min-width: 6.5rem !important;
            justify-content: center !important;
            background: #ffffff !important;
            border: 1px solid #d7e2ec !important;
            border-radius: 999px !important;
            color: #0f172a !important;
            box-shadow: none !important;
            cursor: pointer !important;
        }

        .rhu-admin-ui .admin-maternal-detail-wrap {
            max-height: 22rem;
            overflow-y: auto;
            scrollbar-gutter: stable;
        }

        .rhu-admin-ui .admin-maternal-patient-table {
            table-layout: fixed;
        }

        .rhu-admin-ui .admin-maternal-patient-table th:nth-child(1),
        .rhu-admin-ui .admin-maternal-patient-table td:nth-child(1) {
            width: 26%;
        }

        .rhu-admin-ui .admin-maternal-patient-table th:nth-child(2),
        .rhu-admin-ui .admin-maternal-patient-table td:nth-child(2) {
            width: 18%;
        }

        .rhu-admin-ui .admin-maternal-patient-table th:nth-child(3),
        .rhu-admin-ui .admin-maternal-patient-table td:nth-child(3) {
            width: 7rem;
        }

        .rhu-admin-ui .admin-maternal-patient-table th:nth-child(5),
        .rhu-admin-ui .admin-maternal-patient-table td:nth-child(5) {
            width: 11rem;
        }

        .rhu-admin-ui .admin-maternal-record-grid {
            display: grid;
            grid-template-columns: 6rem 8rem 8rem 5rem 8rem minmax(16rem, 1fr);
            gap: .75rem;
            align-items: center;
        }

        .rhu-admin-ui [data-maternal-records-toggle],
        .rhu-admin-ui [data-maternal-records-toggle]:hover {
            display: inline-flex !important;
            min-width: 6.5rem !important;
            justify-content: center !important;
            background: #ffffff !important;
            border: 1px solid #d7e2ec !important;
            border-radius: 999px !important;
            color: #0f172a !important;
            box-shadow: none !important;
            cursor: pointer !important;
        }

        .rhu-admin-ui .assigned-records-panel,
        .rhu-admin-ui .assigned-records-panel * {
            transition: none !important;
            animation: none !important;
        }

        .rhu-admin-ui .assigned-records-panel *:hover {
            transform: none !important;
        }

        .rhu-admin-ui #staff-personnel-live-panel,
        .rhu-admin-ui #staff-personnel-live-panel * {
            transition: none !important;
            animation: none !important;
        }

        .rhu-admin-ui #staff-personnel-live-panel *:hover {
            transform: none !important;
        }

        .rhu-admin-ui .admin-main .staff-folder-card.is-active,
        .rhu-admin-ui .admin-main .staff-folder-card.is-active:hover {
            background: #063f2f !important;
            border-color: #b7d78a !important;
            color: #f8fff0 !important;
        }

        .rhu-admin-ui .admin-main .staff-folder-card.is-active .staff-folder-name,
        .rhu-admin-ui .admin-main .staff-folder-card.is-active .staff-folder-count {
            color: #f8fff0 !important;
            opacity: 1 !important;
            text-shadow: 0 1px 1px rgba(0, 0, 0, .2) !important;
        }
    </style>
    <link rel="stylesheet" href="styles/dashboard-enhancements.css?v=<?php echo (int)filemtime(__DIR__ . '/dashboard-enhancements.css'); ?>">
    <style>
        /* No refresh flicker: this admin page should render in-place, not animate in after JS loads. */
        body.rhu-admin-ui,
        body.rhu-admin-ui.dashboard-enhanced,
        body.rhu-admin-ui.staff-dashboard-enhanced {
            animation: none !important;
        }

        body.rhu-admin-ui .dashboard-reveal,
        body.rhu-admin-ui .dashboard-reveal.dashboard-visible,
        body.rhu-admin-ui [data-scroll-reveal],
        body.rhu-admin-ui [data-scroll-reveal].is-visible {
            opacity: 1 !important;
            transform: none !important;
            transition: none !important;
            transition-delay: 0ms !important;
            animation: none !important;
        }

        body.rhu-admin-ui .unified-chart-card,
        body.rhu-admin-ui .unified-chart-grid,
        body.rhu-admin-ui .unified-donut,
        body.rhu-admin-ui .metric-value-pop {
            animation: none !important;
        }

        /* Reference dashboard polish: visual-only overrides for admin overview, certificates, and messages. */
        body.rhu-admin-ui {
            --reference-bg: #f4fbff;
            --reference-panel: #ffffff;
            --reference-line: #cfe5f5;
            --reference-ink: #093c5a;
            --reference-muted: #5c7ca8;
            --reference-teal: #0aa888;
            --reference-green: #6ccd8d;
            --reference-blue: #2878ef;
            background:
                linear-gradient(180deg, rgba(247, 252, 255, .96), rgba(239, 250, 255, .96)),
                var(--reference-bg) !important;
        }

        body.rhu-admin-ui::before,
        body.rhu-admin-ui::after {
            display: none !important;
        }

        .rhu-admin-ui .admin-shell-header {
            min-height: 72px;
            background: rgba(255, 255, 255, .96) !important;
            border-bottom: 1px solid #d9ebf8 !important;
            box-shadow: 0 12px 34px rgba(19, 84, 122, .08) !important;
            backdrop-filter: blur(18px);
        }

        .rhu-admin-ui .admin-feature-drawer {
            background: #ffffff !important;
            border-right: 1px solid #d8eef9 !important;
            box-shadow: 18px 0 48px rgba(20, 83, 116, .08) !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child {
            min-height: 100px;
            background:
                linear-gradient(90deg, rgba(6, 48, 62, .86), rgba(6, 91, 83, .70)),
                url("assets/admin-municipal-background.png") center / cover no-repeat !important;
            border-bottom: 0 !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav {
            padding: .85rem .9rem 1rem;
            scrollbar-color: #93e5c1 transparent;
        }

        .rhu-admin-ui .admin-feature-drawer nav h2 {
            margin: 1rem 0 .45rem;
            padding-inline: .25rem;
            color: #1c2a20 !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            letter-spacing: 0 !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a {
            min-height: 50px;
            border: 1px solid transparent !important;
            border-radius: 8px !important;
            color: #182821 !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            box-shadow: none !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a>span:first-child {
            background: transparent !important;
            color: #182821 !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"] {
            background: #eef6e7 !important;
            border-color: #aee39a !important;
            box-shadow: inset 4px 0 0 #99d881 !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a:hover {
            background: #f2fbf8 !important;
            border-color: #cbeee4 !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:last-child {
            border-top-color: #edf3f7 !important;
            background: #ffffff !important;
        }

        .rhu-admin-ui .admin-main {
            width: min(100%, 1840px);
            padding: 1.25rem clamp(1rem, 1.7vw, 1.9rem) 1.8rem;
            color: var(--reference-ink) !important;
        }

        .rhu-admin-ui .admin-page-heading {
            min-height: 92px;
            overflow: hidden;
            border: 1px solid #cdeee1 !important;
            border-radius: 8px !important;
            background:
                radial-gradient(circle at 93% 42%, rgba(10, 168, 136, .22) 0 3.2rem, transparent 3.3rem),
                radial-gradient(circle at 82% 120%, rgba(108, 205, 141, .28) 0 7rem, transparent 7.1rem),
                linear-gradient(110deg, #dff9eb 0%, #effcf9 58%, #d9f4ef 100%) !important;
            box-shadow: 0 18px 46px rgba(39, 112, 145, .08) !important;
        }

        .rhu-admin-ui .admin-page-heading-icon {
            width: 64px;
            height: 64px;
            border-radius: 10px;
            background: linear-gradient(145deg, #91e6bd, #ddfff0) !important;
            color: #07826d !important;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .65), 0 14px 28px rgba(10, 168, 136, .18);
        }

        .rhu-admin-ui .admin-page-heading h2,
        .rhu-admin-ui .overview-clinical-heading,
        .rhu-admin-ui [data-certificate-cleanup-panel] h3,
        .rhu-admin-ui [data-message-cleanup-panel] h3 {
            color: var(--reference-ink) !important;
            font-weight: 900 !important;
        }

        .rhu-admin-ui .admin-page-heading p,
        .rhu-admin-ui .overview-clinical-subtitle,
        .rhu-admin-ui [data-certificate-cleanup-panel] p,
        .rhu-admin-ui [data-message-cleanup-panel] p {
            color: var(--reference-muted) !important;
        }

        .rhu-admin-ui .admin-live-badge {
            border-color: transparent !important;
            background: rgba(255, 255, 255, .68) !important;
            color: #0b5275 !important;
            box-shadow: inset 0 0 0 1px rgba(207, 229, 245, .8);
        }

        .rhu-admin-ui .overview-card-polished,
        .rhu-admin-ui .admin-main :is(.bg-white, .rounded-xl.bg-white, .rounded-2xl.bg-white),
        .rhu-admin-ui [data-certificate-cleanup-panel],
        .rhu-admin-ui [data-message-cleanup-panel] {
            border: 1px solid var(--reference-line) !important;
            border-radius: 8px !important;
            background: rgba(255, 255, 255, .94) !important;
            box-shadow: 0 18px 50px rgba(29, 94, 131, .07) !important;
        }

        .rhu-admin-ui .unified-dashboard-hero {
            align-items: stretch;
        }

        .rhu-admin-ui .unified-chart-card {
            min-height: 300px;
            border: 0 !important;
            border-radius: 8px !important;
            background: linear-gradient(180deg, #ffffff 0%, #f7fdff 100%) !important;
            box-shadow: none !important;
        }

        .rhu-admin-ui .unified-chart-heading {
            color: var(--reference-ink) !important;
        }

        .rhu-admin-ui .unified-chart-gridline,
        .rhu-admin-ui .unified-chart-baseline {
            stroke: #d8eaf6 !important;
        }

        .rhu-admin-ui .unified-chart-line {
            stroke: var(--reference-teal) !important;
            stroke-width: 3 !important;
        }

        .rhu-admin-ui .unified-chart-point {
            fill: var(--reference-teal) !important;
            stroke: #ffffff !important;
        }

        .rhu-admin-ui .unified-chart-fill {
            fill: rgba(79, 211, 173, .24) !important;
        }

        .rhu-admin-ui .unified-chart-tooltip {
            border-color: #cde5f5 !important;
            background: #ffffff !important;
            color: var(--reference-ink) !important;
            box-shadow: 0 16px 34px rgba(21, 92, 129, .15) !important;
        }

        .rhu-admin-ui .unified-chart-tooltip-value {
            color: var(--reference-muted) !important;
        }

        .rhu-admin-ui .unified-donut {
            width: min(220px, 55vw);
            background: conic-gradient(#17a874 0 var(--users-pct), #72ca8c var(--users-pct) calc(var(--users-pct) + var(--staff-pct)), #69aaf5 calc(var(--users-pct) + var(--staff-pct)) 100%) !important;
            box-shadow: 0 24px 48px rgba(28, 136, 111, .16);
        }

        .rhu-admin-ui .unified-donut::after {
            inset: 30%;
            box-shadow: inset 0 0 0 1px #edf7fc;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white {
            min-height: 150px;
            border-radius: 8px !important;
            background: #ffffff !important;
            color: var(--reference-ink) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white *,
        .rhu-admin-ui .admin-main .grid.grid-cols-1.sm\:grid-cols-2.lg\:grid-cols-5>.bg-white * {
            color: var(--reference-ink) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white>div:first-child,
        .rhu-admin-ui .admin-main .grid.grid-cols-1.sm\:grid-cols-2.lg\:grid-cols-5>.bg-white>div:first-child {
            background: linear-gradient(145deg, #b7f2d2, #e5fff2) !important;
            color: #07826d !important;
        }

        .rhu-admin-ui .admin-main :is(input, select, textarea) {
            border-color: #cfe1ee !important;
            border-radius: 8px !important;
            background: #fbfeff !important;
            color: var(--reference-ink) !important;
            box-shadow: none !important;
        }

        .rhu-admin-ui .admin-main :is(input, select, textarea):focus {
            border-color: #51b7df !important;
            box-shadow: 0 0 0 3px rgba(81, 183, 223, .16) !important;
            outline: none !important;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel],
        .rhu-admin-ui [data-message-cleanup-panel] {
            overflow: hidden;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel] .certificate-requests-scroll {
            max-height: 520px;
            border: 1px solid #d8eee7;
            border-radius: 8px;
            background: #ffffff;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel] table,
        .rhu-admin-ui [data-message-cleanup-panel] table {
            min-width: 920px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel] thead,
        .rhu-admin-ui [data-certificate-cleanup-panel] thead tr,
        .rhu-admin-ui [data-certificate-cleanup-panel] thead th {
            background: #d8f5ed !important;
            color: #14231a !important;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel] thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            white-space: nowrap;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel] tbody tr:hover {
            background: #f4fbff !important;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel][data-certificate-select-mode="1"] tbody tr {
            cursor: pointer;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel] tbody tr.is-certificate-selected {
            background: #fff3f3 !important;
            box-shadow: inset 4px 0 0 #dc2626;
        }

        .rhu-admin-ui [data-certificate-cleanup-panel] td,
        .rhu-admin-ui [data-message-cleanup-panel] div {
            border-color: #e0edf6 !important;
        }

        .rhu-admin-ui [data-message-cleanup-panel] #admin-message-list>.space-y-3>div,
        .rhu-admin-ui [data-message-cleanup-panel] #admin-message-list [class*="bg-gray-50"] {
            border: 1px solid #d9ebf8 !important;
            border-radius: 8px !important;
            background: #f8fcff !important;
            box-shadow: 0 10px 24px rgba(29, 94, 131, .05);
        }

        .rhu-admin-ui [data-message-cleanup-panel] form {
            align-items: center;
        }

        .rhu-admin-ui [data-message-cleanup-panel] input[name="reply"] {
            min-height: 38px;
        }

        .rhu-admin-ui [data-certificate-preview-modal] > div {
            border-radius: 8px !important;
            border-color: #d5e9f7 !important;
        }

        .rhu-admin-ui :is(button, button[type="button"], button[type="submit"]),
        .rhu-admin-ui .admin-main a[class*="bg-"],
        .rhu-admin-ui [data-certificate-preview-download] {
            border-radius: 8px !important;
            border: 1px solid #a9dccc !important;
            background: #dff7ef !important;
            color: #075f55 !important;
            box-shadow: 0 10px 22px rgba(11, 122, 108, .10) !important;
        }

        .rhu-admin-ui :is(button, button[type="button"], button[type="submit"]) *,
        .rhu-admin-ui .admin-main a[class*="bg-"] * {
            color: #075f55 !important;
            fill: #075f55 !important;
            stroke: #075f55 !important;
        }

        .rhu-admin-ui :is(button, button[type="button"], button[type="submit"]):hover,
        .rhu-admin-ui .admin-main a[class*="bg-"]:hover {
            background: #c9f1e5 !important;
            border-color: #78cdb7 !important;
        }

        .rhu-admin-ui .reference-dashboard {
            display: grid;
            gap: 1rem;
        }

        .rhu-admin-ui .reference-welcome {
            position: relative;
            overflow: hidden;
            min-height: 74px;
            border: 1px solid #caeadf;
            border-radius: 8px;
            background:
                radial-gradient(circle at 96% 30%, rgba(54, 190, 151, .36) 0 5rem, transparent 5.1rem),
                radial-gradient(circle at 85% 115%, rgba(120, 214, 179, .38) 0 8rem, transparent 8.1rem),
                linear-gradient(110deg, #dff8ea 0%, #effcf9 60%, #d9f3ef 100%);
            box-shadow: 0 16px 40px rgba(29, 94, 131, .07);
        }

        .rhu-admin-ui .reference-panel,
        .rhu-admin-ui .reference-stat,
        .rhu-admin-ui .reference-shortcut,
        .rhu-admin-ui .reference-quote {
            border: 1px solid #cfe5f5;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 16px 42px rgba(29, 94, 131, .06);
        }

        .rhu-admin-ui .reference-stat {
            display: flex;
            min-height: 148px;
            flex-direction: column;
            align-items: flex-start;
            padding: 1rem 1.25rem;
        }

        .rhu-admin-ui .reference-icon {
            display: grid;
            width: 42px;
            height: 42px;
            place-items: center;
            border-radius: 8px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .65);
        }

        .rhu-admin-ui .reference-stat > p:nth-of-type(1) {
            min-height: 2.5rem;
            line-height: 1.3;
        }

        .rhu-admin-ui .reference-stat > p:nth-of-type(2) {
            margin-top: .15rem;
            line-height: 1;
        }

        .rhu-admin-ui .reference-stat > p:nth-of-type(3) {
            margin-top: auto;
            line-height: 1.35;
        }

        .rhu-admin-ui .reference-stat-trend {
            display: flex;
            align-items: baseline;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .rhu-admin-ui .reference-stat-trend span {
            flex: 0 0 auto;
        }

        .rhu-admin-ui .reference-welcome .reference-icon {
            width: 46px;
            height: 46px;
        }

        .rhu-admin-ui .reference-chart {
            min-height: 300px;
        }

        .rhu-admin-ui .reference-chart .unified-chart-card {
            min-height: 220px;
            overflow: visible !important;
            background: #ffffff !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-svg {
            height: 205px;
            overflow: visible !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-line {
            fill: none;
            stroke: #0f8f7e !important;
            stroke-width: 3.4 !important;
            stroke-linecap: round;
            stroke-linejoin: round;
            filter: none;
        }

        .rhu-admin-ui .reference-chart .unified-chart-fill {
            display: none !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-point {
            display: none !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-grid {
            display: none !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-gridline {
            display: block !important;
            stroke: #d8e2ea !important;
            stroke-width: 1 !important;
            stroke-dasharray: 2 5 !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-baseline {
            stroke: #aeb8c1 !important;
            stroke-width: 1.2 !important;
        }

        .rhu-admin-ui .reference-chart .reference-chart-tick {
            stroke: #aeb8c1;
            stroke-width: 1.2;
        }

        .rhu-admin-ui .reference-chart .unified-chart-month {
            fill: #526170 !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            dominant-baseline: middle;
        }

        .rhu-admin-ui .reference-chart .reference-chart-y-label {
            fill: #6b7b88 !important;
            font-size: 10px !important;
            font-weight: 700 !important;
        }

        .rhu-admin-ui .reference-chart .reference-chart-vertical-grid {
            stroke: #e1e8ee !important;
            stroke-width: 1 !important;
            stroke-dasharray: 2 5 !important;
        }

        .rhu-admin-ui .reference-chart .reference-chart-hit-area {
            cursor: pointer;
            fill: transparent;
            pointer-events: all;
        }

        .rhu-admin-ui .reference-chart .unified-chart-tooltip {
            min-width: 108px;
            max-width: 150px;
            padding: 7px 9px !important;
            border-radius: 8px !important;
            border: 1px solid #bfe9dc !important;
            background: #ffffff !important;
            color: #063f35 !important;
            opacity: 0 !important;
            box-shadow: 0 14px 26px rgba(6, 63, 53, .14) !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-tooltip.is-visible {
            opacity: 1 !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-tooltip-label {
            color: #087764 !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-tooltip-value {
            color: #063f35 !important;
            font-size: 12px !important;
        }


        .rhu-admin-ui .reference-shortcut {
            display: flex;
            min-height: 86px;
            align-items: center;
            gap: .95rem;
            padding: .85rem 1rem;
            text-decoration: none !important;
        }

        .rhu-admin-ui .reference-shortcut-icon {
            display: grid;
            width: 56px;
            height: 56px;
            flex: 0 0 56px;
            place-items: center;
            border-radius: 10px;
            color: #ffffff !important;
            box-shadow: 0 12px 28px rgba(22, 113, 165, .16);
        }

        .rhu-admin-ui .reference-activity table {
            width: 100%;
            min-width: 760px;
        }

        .rhu-admin-ui .reference-activity thead,
        .rhu-admin-ui .reference-activity thead tr,
        .rhu-admin-ui .reference-activity thead th {
            background: #eef6fc !important;
            color: #315f8a !important;
        }

        .rhu-admin-ui .reference-quote {
            position: relative;
            min-height: 205px;
            overflow: hidden;
            background:
                linear-gradient(90deg, rgba(255,255,255,.96), rgba(242,251,249,.82)),
                url("assets/admin-municipal-background.png") center / cover no-repeat;
        }

        .rhu-admin-ui .reference-quote::after {
            position: absolute;
            right: -2.5rem;
            bottom: -4rem;
            width: 15rem;
            height: 15rem;
            border-radius: 999px;
            background: rgba(24, 170, 127, .28);
            content: "";
        }

        .rhu-admin-ui .reference-donut-wrap {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(130px, .8fr);
            gap: 1.2rem;
            align-items: center;
        }

        .rhu-admin-ui .reference-staff-mix {
            align-self: start;
            min-height: 0;
        }

        .rhu-admin-ui .reference-staff-mix .unified-donut {
            width: min(205px, 42vw);
        }

        .rhu-admin-ui .reference-dashboard {
            --dash-one-900: #063f35;
            --dash-one-800: #075448;
            --dash-one-700: #087764;
            --dash-one-600: #0a9b7f;
            --dash-one-500: #15b892;
            --dash-one-300: #85dfc3;
            --dash-one-100: #dcf8ef;
            --dash-one-50: #f2fcf8;
        }

        .rhu-admin-ui .reference-dashboard :is(h2, h3, h4, strong, .font-black, .font-bold) {
            color: var(--dash-one-900) !important;
        }

        .rhu-admin-ui .reference-dashboard :is(p, span, td, th, a):not(.reference-shortcut-icon):not(.reference-shortcut-icon *) {
            color: var(--dash-one-800) !important;
        }

        .rhu-admin-ui .reference-panel,
        .rhu-admin-ui .reference-stat,
        .rhu-admin-ui .reference-shortcut,
        .rhu-admin-ui .reference-quote {
            border-color: #bfe9dc !important;
            box-shadow: 0 16px 42px rgba(6, 63, 53, .06) !important;
        }

        .rhu-admin-ui .reference-stat .reference-icon,
        .rhu-admin-ui .reference-shortcut-icon {
            background: linear-gradient(135deg, var(--dash-one-500), var(--dash-one-700)) !important;
            color: #ffffff !important;
        }

        .rhu-admin-ui .reference-stat .reference-icon *,
        .rhu-admin-ui .reference-shortcut-icon * {
            color: #ffffff !important;
            stroke: #ffffff !important;
            fill: none !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-line {
            stroke: #0f8f7e !important;
        }

        .rhu-admin-ui .reference-chart .unified-chart-baseline,
        .rhu-admin-ui .reference-chart .reference-chart-tick {
            stroke: #9fcfbe !important;
        }

        .rhu-admin-ui .reference-activity thead,
        .rhu-admin-ui .reference-activity thead tr,
        .rhu-admin-ui .reference-activity thead th {
            background: var(--dash-one-100) !important;
            color: var(--dash-one-800) !important;
        }

        .rhu-admin-ui .reference-staff-mix .unified-donut {
            background: conic-gradient(var(--dash-one-700) 0 var(--users-pct), var(--dash-one-500) var(--users-pct) calc(var(--users-pct) + var(--staff-pct)), var(--dash-one-300) calc(var(--users-pct) + var(--staff-pct)) 100%) !important;
        }

        .rhu-admin-ui .reference-staff-mix i {
            background-color: var(--dash-one-500) !important;
        }

        .rhu-admin-ui .admin-main {
            --admin-green-950: #042f27;
            --admin-green-900: #063f35;
            --admin-green-800: #075448;
            --admin-green-700: #087764;
            --admin-green-600: #0a9b7f;
            --admin-green-500: #15b892;
            --admin-green-300: #85dfc3;
            --admin-green-200: #bfe9dc;
            --admin-green-100: #dcf8ef;
            --admin-green-50: #f2fcf8;
        }

        .rhu-admin-ui .admin-main :is(a:not(.reference-shortcut), .text-blue-600, .text-blue-700, .text-purple-700, .text-purple-800, .text-emerald-700, .text-emerald-800, .text-green-700, .text-teal-700) {
            color: var(--admin-green-700) !important;
        }

        .rhu-admin-ui .admin-main :is(button, button[type="button"], button[type="submit"], a[class*="bg-"], .admin-live-badge, [data-certificate-preview-download]) {
            border-color: var(--admin-green-200) !important;
            background: var(--admin-green-100) !important;
            color: var(--admin-green-800) !important;
            box-shadow: 0 10px 22px rgba(6, 63, 53, .08) !important;
        }

        .rhu-admin-ui .admin-main :is(button, button[type="button"], button[type="submit"], a[class*="bg-"], .admin-live-badge, [data-certificate-preview-download]) * {
            color: var(--admin-green-800) !important;
            stroke: var(--admin-green-800) !important;
        }

        .rhu-admin-ui .admin-main :is(button, button[type="button"], button[type="submit"], a[class*="bg-"], [data-certificate-preview-download]):hover {
            border-color: var(--admin-green-300) !important;
            background: #c9f1e5 !important;
            color: var(--admin-green-900) !important;
        }

        .rhu-admin-ui .admin-main :is(.bg-blue-50, .bg-blue-100, .bg-purple-50, .bg-purple-100, .bg-indigo-100, .bg-emerald-50, .bg-emerald-100, .bg-green-50, .bg-green-100, .bg-teal-50, .bg-teal-100, .bg-amber-50, .bg-amber-100, .bg-red-50, .bg-red-100) {
            border-color: var(--admin-green-200) !important;
            background-color: var(--admin-green-50) !important;
            color: var(--admin-green-800) !important;
        }

        .rhu-admin-ui .admin-main :is(.bg-blue-50, .bg-blue-100, .bg-purple-50, .bg-purple-100, .bg-indigo-100, .bg-emerald-50, .bg-emerald-100, .bg-green-50, .bg-green-100, .bg-teal-50, .bg-teal-100, .bg-amber-50, .bg-amber-100, .bg-red-50, .bg-red-100) * {
            color: var(--admin-green-800) !important;
            stroke: var(--admin-green-800) !important;
        }

        .rhu-admin-ui .admin-main .analytics-summary-badge {
            border: 1px solid #c7dff4 !important;
            background: #eff7ff !important;
            color: #164e82 !important;
            box-shadow: none !important;
        }

        .rhu-admin-ui .admin-main .analytics-summary-badge--staff {
            border-color: #bdebdc !important;
            background: #ecfdf6 !important;
            color: #06604f !important;
        }

        .rhu-admin-ui .admin-main .analytics-summary-badge--users {
            border-color: #ddd6fe !important;
            background: #f5f3ff !important;
            color: #5b21b6 !important;
        }

        .rhu-admin-ui .admin-main :is(table thead, table thead tr, table thead th) {
            background: var(--admin-green-100) !important;
            color: var(--admin-green-800) !important;
        }

        .rhu-admin-ui .admin-main :is(input, select, textarea) {
            border-color: var(--admin-green-200) !important;
            color: var(--admin-green-900) !important;
        }

        .rhu-admin-ui .admin-main :is(input, select, textarea):focus {
            border-color: var(--admin-green-500) !important;
            box-shadow: 0 0 0 3px rgba(21, 184, 146, .16) !important;
        }

        .rhu-admin-ui .admin-main input[type="checkbox"],
        .rhu-admin-ui .admin-main input[type="radio"] {
            accent-color: var(--admin-green-600);
        }

        .rhu-admin-ui .admin-main :is(button, button[type="button"], button[type="submit"], a[class*="bg-"], [data-certificate-preview-download]) {
            border: 1px solid #a6d995 !important;
            background: #9bd68d !important;
            color: #ffffff !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 18px rgba(69, 137, 76, .13) !important;
        }

        .rhu-admin-ui .admin-main :is(button, button[type="button"], button[type="submit"], a[class*="bg-"], [data-certificate-preview-download]) * {
            color: #ffffff !important;
            stroke: #ffffff !important;
        }

        .rhu-admin-ui .admin-main :is(button, button[type="button"], button[type="submit"], a[class*="bg-"], [data-certificate-preview-download]):hover {
            border-color: #88c979 !important;
            background: #82c977 !important;
            color: #ffffff !important;
            box-shadow: 0 10px 22px rgba(69, 137, 76, .18) !important;
        }

        .rhu-admin-ui .admin-main :is(button, button[type="button"], button[type="submit"], a[class*="bg-"], [data-certificate-preview-download]):focus-visible {
            outline: 3px solid rgba(155, 214, 141, .32) !important;
            outline-offset: 2px;
        }

        @media (min-width: 1024px) {
            body.rhu-admin-ui {
                overflow: hidden;
            }

            .rhu-admin-ui>.min-h-screen {
                height: 100vh;
                overflow: hidden;
            }

            .rhu-admin-ui .admin-feature-drawer {
                width: 220px;
                max-width: none;
                transform: none !important;
                visibility: visible !important;
                z-index: 60;
            }

            .rhu-admin-ui .feature-drawer-backdrop,
            .rhu-admin-ui [data-drawer-open],
            .rhu-admin-ui [data-drawer-close] {
                display: none !important;
            }

            .rhu-admin-ui .admin-shell-header {
                position: fixed !important;
                top: 0;
                right: 0;
                left: 220px;
                z-index: 50;
                margin-left: 0;
            }

            .rhu-admin-ui .admin-main {
                margin-left: 220px;
                max-width: none !important;
                width: calc(100% - 220px);
                height: calc(100vh - 72px);
                margin-top: 72px;
                overflow-y: auto;
                overflow-x: hidden;
                scrollbar-gutter: stable;
                scrollbar-color: #d8eef9 transparent;
            }

            .rhu-admin-ui .admin-feature-drawer nav {
                max-height: calc(100vh - 165px);
                overflow-y: auto;
            }
        }

        @media (max-width: 767px) {
            .rhu-admin-ui .admin-page-heading {
                min-height: auto;
                align-items: flex-start;
            }

            .rhu-admin-ui .admin-page-heading-icon {
                width: 48px;
                height: 48px;
                flex-basis: 48px;
            }

            .rhu-admin-ui .unified-chart-card {
                min-height: 260px;
            }

            .rhu-admin-ui [data-message-cleanup-panel] form {
                align-items: stretch;
                flex-direction: column;
            }

            .rhu-admin-ui .reference-donut-wrap {
                grid-template-columns: 1fr;
            }
        }

        /* Final content button palette: exact light green action buttons in dashboard content. */
        body.rhu-admin-ui .admin-main button:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]),
        body.rhu-admin-ui .admin-main button[type="button"]:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]),
        body.rhu-admin-ui .admin-main button[type="submit"],
        body.rhu-admin-ui .admin-main form button,
        body.rhu-admin-ui .admin-main td button:not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]),
        body.rhu-admin-ui .admin-main .inline button,
        body.rhu-admin-ui .admin-main a[class*="bg-"],
        body.rhu-admin-ui .admin-main [data-certificate-preview-download] {
            background: #0f8f7e !important;
            background-color: #0f8f7e !important;
            border: 1px solid #087464 !important;
            border-radius: 8px !important;
            color: #ffffff !important;
            box-shadow: 0 8px 18px rgba(15, 143, 126, .14) !important;
        }

        body.rhu-admin-ui .admin-main button *,
        body.rhu-admin-ui .admin-main form button *,
        body.rhu-admin-ui .admin-main td button *,
        body.rhu-admin-ui .admin-main a[class*="bg-"] *,
        body.rhu-admin-ui .admin-main [data-certificate-preview-download] * {
            color: #ffffff !important;
            stroke: #ffffff !important;
        }

        body.rhu-admin-ui .admin-main button:hover,
        body.rhu-admin-ui .admin-main form button:hover,
        body.rhu-admin-ui .admin-main td button:hover,
        body.rhu-admin-ui .admin-main a[class*="bg-"]:hover,
        body.rhu-admin-ui .admin-main [data-certificate-preview-download]:hover {
            background: #0b7769 !important;
            background-color: #0b7769 !important;
            border-color: #065f55 !important;
            color: #ffffff !important;
        }
    </style>
    <script defer src="assets/js/dashboard-enhancements.js?v=<?php echo (int)filemtime(__DIR__ . '/dashboard-enhancements.js'); ?>"></script>
<script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(__DIR__ . "/staff-session-lock.js") ?>"></script>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
</head>

<body class="rhu-admin-ui min-h-screen bg-gray-50 text-gray-900">
    <div class="min-h-screen flex flex-col">
        <!-- HEADER -->
        <header class="admin-shell-header dashboard-header sticky top-0 z-40 border-b border-[#0a332b]/80 bg-[#0b3c35] text-[#f4faf7] shadow-[0_14px_30px_rgba(2,28,23,0.22)]">
            <div class="px-4 py-2 sm:px-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <button type="button" data-drawer-open class="relative z-80 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-[#d6e9df]/20 bg-white/0 text-[#f4faf7] transition hover:bg-white/5 focus:outline-none focus:ring-2 focus:ring-white/30" aria-label="Open feature drawer" aria-controls="feature-drawer" aria-expanded="false">
                            <?php echo iconSvg('menu', 'w-4 h-4'); ?>
                        </button>
                        <div class="flex items-center gap-2 text-[#edf7f2]">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full border border-[#e8f3d8] bg-[#dfeecb] text-[#0b3b2f]">
                                <?php echo iconSvg('shield', 'w-3.5 h-3.5'); ?>
                            </span>
                            <span class="text-[11px] font-black uppercase tracking-[0.17em] text-[#f5f5f2]">Admin Panel</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <?php echo portalRenderNotificationButton(); ?>
                        <?php
                        $currentAdminName = trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?: $rhuProfile['mho_name'];
                        $currentAdminCode = $_SESSION['user']['admin_code'] ?? 'ADM-2026-0001';
                        $currentDesignation = $_SESSION['user']['designation'] ?? 'Municipal Health Officer';
                        ?>
                        <div class="flex items-center gap-2.5 rounded-2xl border border-[#dbeadf]/20 bg-[#d9f0d0]/10 px-2.5 py-1 shadow-sm" title="Admin ID: <?php echo esc($currentAdminCode); ?> | <?php echo esc($currentDesignation); ?>">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-[#dceec4] text-sm font-black text-[#0b3b2f] shadow-inner">
                                <?php echo esc(strtoupper(substr($currentAdminName, 0, 1))); ?>
                            </div>
                            <div class="hidden min-[420px]:block text-left leading-none text-[#f5f7f5]">
                                <p class="text-[12px] font-bold"><?php echo esc($currentAdminName); ?></p>
                                <p class="mt-0.5 text-[10px] text-[#dfeee7]"><?php echo esc($currentAdminCode); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
            <div style="display:flex;justify-content:flex-end;padding:10px 24px"><a href="StaffResidentAppointments.php" data-staff-qr-modal style="display:inline-flex;align-items:center;gap:8px;border:1px solid #b8d8ce;border-radius:10px;background:#fff;color:#0f766e;padding:10px 16px;font-size:13px;font-weight:700;text-decoration:none">&#9638; Scan Resident ID</a></div>

        <div data-drawer-backdrop class="feature-drawer-backdrop fixed inset-0 z-50 hidden bg-slate-950/40" aria-hidden="true"></div>
        <aside id="feature-drawer" data-feature-drawer class="admin-feature-drawer feature-drawer fixed inset-y-0 left-0 z-60 flex h-full max-h-screen w-[88vw] max-w-sm flex-col border-r border-gray-200 bg-white shadow-2xl overflow-hidden" aria-label="RHU Admin features" aria-hidden="true">
            <div class="relative flex items-center justify-between overflow-hidden border-b border-white/20 p-4 shrink-0 bg-white">
                <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('../../../assets/admin-municipal-background.png'); filter: saturate(1.15) brightness(0.68);"></div>
                <div class="absolute inset-0 bg-linear-to-r from-[#0d3518]/80 via-[#173f2d]/75 to-[#2f6f49]/60"></div>
                <div class="relative z-10 flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full border border-white/25 bg-white/10 backdrop-blur-sm shadow-lg">
                        <img src="assets/images/nasugbu_seal.png" alt="Nasugbu Seal" class="h-15 w-15 object-contain" />
                    </span>
                    <div class="text-white" style="color: #fff !important;">
                        <p class="text-lg font-black leading-tight text-white drop-shadow-[0_2px_2px_rgba(0,0,0,0.55)]" style="color: #fff !important;">RURAL HEALTH UNIT</p>
                        <p class="text-[11px] font-semibold text-white/95 drop-shadow-[0_1px_2px_rgba(0,0,0,0.55)]" style="color: #fff !important;"><?php echo esc($tabLabelMap[$tab] ?? 'Dashboard'); ?></p>
                    </div>
                </div>
                <button type="button" data-drawer-close class="relative z-10 flex h-9 w-9 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white hover:bg-white/20" aria-label="Close feature drawer"><?php echo iconSvg('close', 'w-5 h-5'); ?></button>
            </div>
            <nav class="flex-1 overflow-y-auto min-h-0 p-3 overscroll-contain">
                <?php foreach ($drawerGroups as $groupLabel => $groupTabs): ?>
                    <section class="mb-4">
                        <h2 class="mb-1.5 px-3 text-[10px] font-black uppercase tracking-[0.16em] text-gray-400"><?php echo esc($groupLabel); ?></h2>
                        <div class="space-y-1">
                            <?php foreach ($groupTabs as $key): ?>
                                <?php $active = $tab === $key; ?>
                                <a href="<?php echo esc(tabUrl($key)); ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold <?php echo $active ? 'bg-purple-100 text-purple-900 shadow-sm' : 'text-gray-700 hover:bg-gray-50'; ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>>
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg <?php echo $active ? 'bg-purple-700 text-white' : 'bg-gray-100 text-gray-600'; ?>"><?php echo iconSvg($tabIconMap[$key], 'w-4 h-4'); ?></span>
                                    <span class="flex-1"><?php echo esc($tabLabelMap[$key]); ?></span>
                                    <?php if ($active): ?><span aria-hidden="true"><?php echo iconSvg('right', 'w-3.5 h-3.5'); ?></span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </nav>
            <div class="border-t border-gray-100 p-3 shrink-0 bg-white">
                <a href="RHUAdminDashboard.php?logout=1" data-staff-logout class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100"><?php echo iconSvg('logout', 'w-4 h-4'); ?></span>Sign Out</a>
            </div>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main class="admin-main flex-1 max-w-7xl mx-auto w-full px-3 sm:px-4 py-4 sm:py-6 space-y-4 sm:space-y-5 pb-6">
            <?php if ($flashSuccess): ?>
                <div data-auto-dismiss-flash class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800 flex items-center justify-between shadow-sm transition-all duration-300">
                    <span data-admin-action-success class="flex items-center gap-2"><?php echo iconSvg('check', 'w-4 h-4 shrink-0'); ?> <?php echo esc($flashSuccess); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800 font-bold">&times;</button>
                </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div data-auto-dismiss-flash class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 flex items-center justify-between shadow-sm transition-all duration-300">
                    <span data-admin-action-error class="flex items-center gap-2"><?php echo iconSvg('alert', 'w-4 h-4 shrink-0'); ?> <?php echo esc($flashError); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800 font-bold">&times;</button>
                </div>
            <?php endif; ?>

            <section class="admin-page-heading" aria-labelledby="adminCurrentPageTitle">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="admin-page-heading-icon" aria-hidden="true"><?php echo iconSvg($tabIconMap[$tab] ?? 'shield', 'w-5 h-5'); ?></span>
                    <div class="min-w-0">
                        <?php if ($tab === 'overview'): ?>
                            <?php $headerAdminFirstName = trim((string)($_SESSION['user']['first_name'] ?? 'Dr. Marquez')) ?: 'Dr. Marquez'; ?>
                            <h2 id="adminCurrentPageTitle">Overview · Good day, <?php echo esc($headerAdminFirstName); ?>!</h2>
                            <p>Monitor accounts, residents, clinical activity, and administrative priorities.</p>
                        <?php else: ?>
                            <h2 id="adminCurrentPageTitle"><?php echo esc($tabLabelMap[$tab] ?? 'Admin Dashboard'); ?></h2>
                            <p><?php echo esc($tabDescriptionMap[$tab] ?? 'Manage Rural Health Unit administration and governance.'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <?php if ($tab === 'overview'): ?>
                        <div class="flex items-center gap-2 text-[#0b5275]">
                            <?php echo iconSvg('clock', 'w-4 h-4'); ?>
                            <div class="text-right">
                                <p class="text-sm font-black"><?php echo esc(date('F d, Y')); ?></p>
                                <p class="text-xs font-semibold text-[#6484ae]"><?php echo esc(date('l')); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <span class="admin-live-badge">Live database</span>
                </div>
            </section>

            <!-- EMERGENCY TAB -->
            <?php if ($tab === 'emergency'): ?>
                <?php
                $emergencyRequests = [];
                try {
                    $emergencyRequests = $pdo->query("
                        SELECT a.id, a.description, a.created_at,
                               r.first_name, r.last_name, r.contact_number
                        FROM audit_logs a
                        LEFT JOIN residents r ON r.id = a.record_id
                        WHERE a.action = 'URGENT: Emergency Referral'
                        ORDER BY a.created_at DESC, a.id DESC
                        LIMIT 100
                    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $emergencyLoadError) {
                    error_log('Admin emergency requests: ' . $emergencyLoadError->getMessage());
                }
                ?>
                <section id="emergency-requests" class="mb-6 border-l-4 border-red-600 bg-white p-4">
                    <h2 class="text-base font-bold text-red-700">Emergency Referral Requests</h2>
                    <?php if (!$emergencyRequests): ?>
                        <p class="mt-2 text-sm text-gray-500">No emergency requests.</p>
                    <?php else: ?>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead><tr class="border-b text-gray-600"><th class="p-2">Received</th><th class="p-2">Resident</th><th class="p-2">Contact</th><th class="p-2">Emergency / Pickup Location</th></tr></thead>
                                <tbody>
                                    <?php foreach ($emergencyRequests as $request): ?>
                                        <tr class="border-b">
                                            <td class="p-2 whitespace-nowrap"><?= esc($request['created_at']) ?></td>
                                            <td class="p-2"><?= esc(trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')) ?: 'Resident') ?></td>
                                            <td class="p-2"><?= esc($request['contact_number'] ?: 'Not provided') ?></td>
                                            <td class="p-2 break-words"><?= esc($request['description']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- OVERVIEW TAB -->
            <?php if ($tab === 'overview'): ?>
                <div class="reference-dashboard">
                    <?php
                    $adminFirstName = trim((string)($_SESSION['user']['first_name'] ?? 'Dr. Marquez')) ?: 'Dr. Marquez';
                    $overviewMonthStart = date('Y-m-01');
                    $overviewNextMonthStart = date('Y-m-01', strtotime('+1 month'));
                    $overviewPreviousMonthStart = date('Y-m-01', strtotime('-1 month'));
                    $overviewMonthlyCount = static function (?PDO $pdo, string $table, string $dateColumn, string $start, string $end): int {
                        if (!$pdo) return 0;
                        try {
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$dateColumn} >= :start_date AND {$dateColumn} < :end_date");
                            $stmt->execute(['start_date' => $start, 'end_date' => $end]);
                            return (int)$stmt->fetchColumn();
                        } catch (Throwable $e) {
                            return 0;
                        }
                    };
                    $overviewTrend = static function (int $current, int $previous): array {
                        $current = max(0, $current);
                        $previous = max(0, $previous);
                        if ($previous === 0) {
                            return $current > 0
                                ? ['New', 'text-[#08a883]']
                                : ['0%', 'text-[#315f8a]'];
                        }
                        $percent = (($current - $previous) / $previous) * 100;
                        $label = ($percent > 0 ? '+' : '') . number_format($percent, 1) . '%';
                        $class = $percent > 0 ? 'text-[#08a883]' : ($percent < 0 ? 'text-red-600' : 'text-[#315f8a]');
                        return [$label, $class];
                    };
                    $consultationsThisMonth = $overviewMonthlyCount($pdo, 'consultations', 'consultation_date', $overviewMonthStart, $overviewNextMonthStart);
                    $consultationsLastMonth = $overviewMonthlyCount($pdo, 'consultations', 'consultation_date', $overviewPreviousMonthStart, $overviewMonthStart);
                    $vaccinationsThisMonth = $overviewMonthlyCount($pdo, 'vaccination_records', 'vaccination_date', $overviewMonthStart, $overviewNextMonthStart);
                    $vaccinationsLastMonth = $overviewMonthlyCount($pdo, 'vaccination_records', 'vaccination_date', $overviewPreviousMonthStart, $overviewMonthStart);
                    $currentDiseaseCases = $overviewMonthlyCount($pdo, 'disease_cases', 'case_date', $overviewMonthStart, $overviewNextMonthStart);
                    $previousDiseaseCases = $overviewMonthlyCount($pdo, 'disease_cases', 'case_date', $overviewPreviousMonthStart, $overviewMonthStart);
                    $programCountsThisMonth = [
                        $consultationsThisMonth,
                        $vaccinationsThisMonth,
                        $currentDiseaseCases,
                        $overviewMonthlyCount($pdo, 'pregnancy_records', 'created_at', $overviewMonthStart, $overviewNextMonthStart),
                        $overviewMonthlyCount($pdo, 'certificates', 'created_at', $overviewMonthStart, $overviewNextMonthStart),
                    ];
                    $programCountsLastMonth = [
                        $consultationsLastMonth,
                        $vaccinationsLastMonth,
                        $previousDiseaseCases,
                        $overviewMonthlyCount($pdo, 'pregnancy_records', 'created_at', $overviewPreviousMonthStart, $overviewMonthStart),
                        $overviewMonthlyCount($pdo, 'certificates', 'created_at', $overviewPreviousMonthStart, $overviewMonthStart),
                    ];
                    $activeProgramsCount = count(array_filter($programCountsThisMonth, static fn($count) => (int)$count > 0));
                    $activeProgramsLastMonth = count(array_filter($programCountsLastMonth, static fn($count) => (int)$count > 0));
                    [$consultationTrendLabel, $consultationTrendClass] = $overviewTrend($consultationsThisMonth, $consultationsLastMonth);
                    [$vaccinationTrendLabel, $vaccinationTrendClass] = $overviewTrend($vaccinationsThisMonth, $vaccinationsLastMonth);
                    [$diseaseMonthPercentLabel, $diseaseMonthPercentClass] = $overviewTrend($currentDiseaseCases, $previousDiseaseCases);
                    [$programTrendLabel, $programTrendClass] = $overviewTrend($activeProgramsCount, $activeProgramsLastMonth);
                    $quickLinks = [
                    ];
                    ?>
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,2.4fr)_minmax(320px,.95fr)]">
                        <div class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                                <section class="reference-stat">
                                    <span class="reference-icon bg-[#bdf5d8] text-[#07826d]"><?php echo iconSvg('users', 'w-6 h-6'); ?></span>
                                    <p class="mt-2 text-sm font-semibold text-[#426b9c]">Total Registered Residents</p>
                                    <p class="text-3xl font-black text-[#082d50]"><?php echo esc(number_format($totalResidentsCount)); ?></p>
                                </section>
                                <section class="reference-stat">
                                    <span class="reference-icon bg-[#d9ecff] text-[#2878ef]"><?php echo iconSvg('stethoscope', 'w-6 h-6'); ?></span>
                                    <p class="mt-2 text-sm font-semibold text-[#426b9c]">Consultations This Month</p>
                                    <p class="text-3xl font-black text-[#082d50]"><?php echo esc(number_format($consultationsThisMonth)); ?></p>
                                    <p class="reference-stat-trend mt-1 text-xs font-bold text-[#6484ae]"><span class="<?php echo esc($consultationTrendClass); ?>"><?php echo esc($consultationTrendLabel); ?></span> vs. last month</p>
                                </section>
                                <section class="reference-stat">
                                    <span class="reference-icon bg-[#eadcff] text-[#8d42d8]"><?php echo iconSvg('pill', 'w-6 h-6'); ?></span>
                                    <p class="mt-2 text-sm font-semibold text-[#426b9c]">Vaccinations This Month</p>
                                    <p class="text-3xl font-black text-[#082d50]"><?php echo esc(number_format($vaccinationsThisMonth)); ?></p>
                                    <p class="reference-stat-trend mt-1 text-xs font-bold text-[#6484ae]"><span class="<?php echo esc($vaccinationTrendClass); ?>"><?php echo esc($vaccinationTrendLabel); ?></span> vs. last month</p>
                                </section>
                                <section class="reference-stat">
                                    <span class="reference-icon bg-[#d9f5ef] text-[#0f8f7e]"><?php echo iconSvg('activity', 'w-6 h-6'); ?></span>
                                    <p class="mt-2 text-sm font-semibold text-[#426b9c]">Disease Cases</p>
                                    <p class="text-3xl font-black text-[#082d50]"><?php echo esc(number_format($currentDiseaseCases)); ?></p>
                                    <p class="reference-stat-trend mt-1 text-xs font-bold text-[#6484ae]"><span class="<?php echo esc($diseaseMonthPercentClass); ?>"><?php echo esc($diseaseMonthPercentLabel); ?></span> vs. last month</p>
                                </section>
                                <section class="reference-stat">
                                    <span class="reference-icon bg-[#ffe3a8] text-[#f28a00]"><?php echo iconSvg('shield', 'w-6 h-6'); ?></span>
                                    <p class="mt-2 text-sm font-semibold text-[#426b9c]">Active Health Programs</p>
                                    <p class="text-3xl font-black text-[#082d50]"><?php echo esc(number_format($activeProgramsCount)); ?></p>
                                    <p class="reference-stat-trend mt-1 text-xs font-bold text-[#6484ae]"><span class="<?php echo esc($programTrendClass); ?>"><?php echo esc($programTrendLabel); ?></span> vs. last month</p>
                                </section>
                            </div>

                            <section class="reference-panel reference-chart p-4 sm:p-5">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <h3 class="flex items-center gap-2 text-base font-black text-[#0a4660]"><?php echo iconSvg('bar', 'w-5 h-5 text-[#07826d]'); ?> Consultations by Month</h3>
                                    <span class="rounded-lg border border-[#cfe5f5] bg-white px-3 py-1 text-sm font-black text-[#315f8a]"><?php echo esc((string)$overviewChartYear); ?></span>
                                </div>
                                <div class="unified-chart-card" aria-label="Monthly consultation trend for <?php echo esc((string)$overviewChartYear); ?>">
                                    <div class="unified-chart-grid"></div>
                                    <svg class="unified-chart-svg" viewBox="0 0 520 220" preserveAspectRatio="none" aria-hidden="true">
                                        <defs>
                                            <linearGradient id="referenceChartArea" x1="0" x2="0" y1="0" y2="1">
                                                <stop offset="0%" stop-color="#22c7a4" stop-opacity=".34" />
                                                <stop offset="68%" stop-color="#8ee5d1" stop-opacity=".12" />
                                                <stop offset="100%" stop-color="#ffffff" stop-opacity="0" />
                                            </linearGradient>
                                        </defs>
                                        <?php foreach ([0, 1, 2, 3, 4] as $gridLevel): ?>
                                            <?php
                                            $gridY = $overviewChartBaseline - ($gridLevel * ($overviewChartUsableHeight / 4));
                                            $gridValue = (int)($overviewChartStepValue * $gridLevel);
                                            ?>
                                            <line class="unified-chart-gridline" x1="<?php echo esc((string)$overviewChartLeft); ?>" y1="<?php echo esc((string)$gridY); ?>" x2="<?php echo esc((string)$overviewChartRight); ?>" y2="<?php echo esc((string)$gridY); ?>" />
                                        <?php endforeach; ?>
                                        <?php foreach ($overviewChartPoints as $point): ?>
                                            <line class="reference-chart-vertical-grid" x1="<?php echo esc((string)$point['x']); ?>" y1="<?php echo esc((string)$overviewChartTop); ?>" x2="<?php echo esc((string)$point['x']); ?>" y2="<?php echo esc((string)$overviewChartBaseline); ?>" />
                                        <?php endforeach; ?>
                                        <polyline class="unified-chart-line" points="<?php echo esc($overviewChartPolyline); ?>" />
                                        <line class="unified-chart-baseline" x1="<?php echo esc((string)$overviewChartLeft); ?>" y1="<?php echo esc((string)$overviewChartBaseline); ?>" x2="<?php echo esc((string)$overviewChartRight); ?>" y2="<?php echo esc((string)$overviewChartBaseline); ?>" />
                                        <?php foreach ($overviewChartPoints as $index => $point): ?>
                                            <rect class="reference-chart-hit-area" data-label="<?php echo esc($overviewChartSeries[$index]['label']); ?>" data-value="<?php echo esc((string)$overviewChartSeries[$index]['value']); ?>" data-x="<?php echo esc((string)$point['x']); ?>" data-y="<?php echo esc((string)$point['y']); ?>" x="<?php echo esc((string)($point['x'] - 18)); ?>" y="<?php echo esc((string)$overviewChartTop); ?>" width="36" height="<?php echo esc((string)($overviewChartBaseline - $overviewChartTop + 30)); ?>" rx="8"></rect>
                                            <line class="reference-chart-tick" x1="<?php echo esc((string)$point['x']); ?>" y1="<?php echo esc((string)$overviewChartBaseline); ?>" x2="<?php echo esc((string)$point['x']); ?>" y2="<?php echo esc((string)($overviewChartBaseline + 6)); ?>" />
                                            <text class="unified-chart-month" x="<?php echo esc((string)$point['x']); ?>" y="184" text-anchor="middle"><?php echo esc($overviewChartSeries[$index]['label']); ?></text>
                                        <?php endforeach; ?>
                                    </svg>
                                    <div class="unified-chart-hover-line" aria-hidden="true"></div>
                                    <div class="unified-chart-tooltip" aria-live="polite">
                                        <span class="unified-chart-tooltip-label">Month</span>
                                        <span class="unified-chart-tooltip-value">0</span>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <section class="reference-panel reference-staff-mix p-5">
                            <div class="flex items-start gap-3">
                                <span class="text-[#08a883]"><?php echo iconSvg('users', 'w-8 h-8'); ?></span>
                                <div>
                                    <h3 class="text-lg font-black text-[#0a4660]">Community & Healthworkers</h3>
                                    <p class="text-sm font-semibold text-[#6484ae]">Residents, administrators, and active health workers.</p>
                                </div>
                            </div>
                            <div class="reference-donut-wrap reference-pie-wrap mt-6">
                                <div class="flex justify-center">
                                    <div class="unified-donut reference-pie" style="--users-pct: <?php echo number_format($overviewUsersPct, 2, '.', ''); ?>%; --staff-pct: <?php echo number_format($overviewStaffPct, 2, '.', ''); ?>%; --bhw-pct: <?php echo number_format($overviewBhwPct, 2, '.', ''); ?>%;"></div>
                                </div>
                                <div class="reference-pie-info">
                                    <p class="flex items-center justify-between gap-3 text-base font-semibold text-[#0b3764]"><span><i class="mr-3 inline-block h-4 w-4 rounded-full bg-[#0b7f6f]"></i>Residents</span><strong><?php echo esc($totalResidentsCount); ?></strong></p>
                                    <p class="flex items-center justify-between gap-3 text-base font-semibold text-[#0b3764]"><span><i class="mr-3 inline-block h-4 w-4 rounded-full bg-[#1abc9c]"></i>Admin</span><strong><?php echo esc($overviewAdminCount); ?></strong></p>
                                    <p class="flex items-center justify-between gap-3 text-base font-semibold text-[#0b3764]"><span><i class="mr-3 inline-block h-4 w-4 rounded-full bg-[#7fd9c8]"></i>Health Workers</span><strong><?php echo esc($overviewHealthWorkerCount); ?></strong></p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <?php foreach ($quickLinks as $quickLink): ?>
                            <a class="reference-shortcut" href="<?php echo esc(tabUrl($quickLink['tab'])); ?>">
                                <span class="reference-shortcut-icon" style="background: <?php echo esc($quickLink['bg']); ?>;"><?php echo iconSvg($quickLink['icon'], 'w-7 h-7'); ?></span>
                                <span class="min-w-0 flex-1">
                                    <strong class="block text-base font-black text-[#0a4660]"><?php echo esc($quickLink['label']); ?></strong>
                                    <span class="text-xs font-semibold text-[#6484ae]"><?php echo esc($quickLink['sub']); ?></span>
                                </span>
                                <span class="text-[#426b9c]"><?php echo iconSvg('right', 'w-4 h-4'); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-[minmax(0,2.4fr)_minmax(320px,1fr)]">
                        <section class="reference-panel reference-activity overflow-hidden p-4">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <h3 class="flex items-center gap-2 text-base font-black text-[#0a4660]"><?php echo iconSvg('clock', 'w-5 h-5'); ?> Recent Activity</h3>
                                <a href="<?php echo esc(tabUrl('audit')); ?>" class="text-sm font-black text-[#087caf]">View All <?php echo iconSvg('right', 'inline w-4 h-4'); ?></a>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="text-sm">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-2 text-left">Date & Time</th>
                                            <th class="px-3 py-2 text-left">Activity</th>
                                            <th class="px-3 py-2 text-left">User</th>
                                            <th class="px-3 py-2 text-left">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[#e1edf6]">
                                        <?php foreach (array_slice($dbAuditLogsList, 0, 4) as $activity): ?>
                                            <?php
                                            $activityDateRaw = trim((string)($activity['created_at'] ?? $activity['timestamp'] ?? $activity['date_created'] ?? ''));
                                            $activityTimestamp = $activityDateRaw !== '' ? strtotime($activityDateRaw) : false;
                                            $activityDateText = $activityTimestamp ? date('M d, Y h:i A', $activityTimestamp) : 'No date';
                                            ?>
                                            <tr>
                                                <td class="px-3 py-2 font-semibold text-[#426b9c]"><?php echo esc($activityDateText); ?></td>
                                                <td class="px-3 py-2 text-[#315f8a]"><?php echo esc($activity['action'] ?? 'System activity'); ?></td>
                                                <td class="px-3 py-2 text-[#315f8a]"><?php echo esc(trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? '')) ?: 'RHU Admin'); ?></td>
                                                <td class="px-3 py-2 text-[#315f8a]"><?php echo esc($activity['description'] ?? $activity['module_name'] ?? 'Updated record'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="reference-quote p-8">
                            <div class="relative z-10 max-w-sm">
                                <span class="text-[#08a883]"><?php echo iconSvg('users', 'w-11 h-11'); ?></span>
                                <p class="mt-6 text-2xl font-serif italic leading-tight text-[#17415a]">"Accessible healthcare for a healthier Nasugbu."</p>
                                <p class="mt-5 text-sm font-semibold text-[#6484ae]">Rural Health Unit of Nasugbu</p>
                            </div>
                        </section>
                    </div>

                    <!-- ADMIN CERTIFICATE WORKFLOW -->
                    <div class="rounded-xl border border-emerald-100 bg-white/95 p-4 sm:p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Certificate Workflow</h3>
                                <p class="text-xs text-gray-500">Create certificate drafts and request doctor/staff e-signature approval before release.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Admin Issuing</span>
                                <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-800">Doctor / Staff Included</span>
                            </div>
                        </div>
                        <div class="mt-3 rounded-lg border border-emerald-100 bg-emerald-50/70 p-3 text-xs font-semibold text-emerald-900">
                            Admin/MHO and assigned doctor/staff approval required.
                        </div>
                        <?php if (empty($adminResidentCertificateOptions) || empty($adminCertificateTypes)): ?>
                            <p class="mt-4 rounded-lg border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-800">Add active residents and certificate types first before issuing certificates.</p>
                        <?php else: ?>
                            <?php if (($_POST['action'] ?? '') === 'issue_certificate' && ($flashError || $flashSuccess)): ?>
                                <p role="status" class="mt-4 rounded-lg border p-3 text-sm <?php echo $flashError ? 'border-red-200 text-red-800' : 'border-green-200 text-green-800'; ?>"><?php echo esc(trim($flashSuccess . ' ' . $flashError)); ?></p>
                            <?php endif; ?>
                            <form method="post" enctype="multipart/form-data" class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                <input type="hidden" name="action" value="issue_certificate">
                                <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                                <select required name="resident_id" class="rounded-xl border border-gray-300 bg-white p-3 text-gray-900">
                                    <option value="">Select resident</option>
                                    <?php foreach ($adminResidentCertificateOptions as $residentOption): ?>
                                        <?php $residentRequest = $adminResidentCertificateRequests[(int)$residentOption['id']] ?? null; ?>
                                        <option value="<?php echo (int)$residentOption['id']; ?>" data-request-type-name="<?php echo esc($residentRequest['certificate_type_name'] ?? ''); ?>" data-request-purpose="<?php echo esc($residentRequest['purpose'] ?? ''); ?>"><?php echo esc(rhuPatientLabel($residentOption['name'], $residentOption['id'] ?? 0)); ?><?php echo $residentOption['barangay'] !== '' ? ' - ' . esc($residentOption['barangay']) : ''; ?><?php echo $residentRequest ? ' - Pending certificate request' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select required name="certificate_type_id" class="rounded-xl border border-gray-300 bg-white p-3 text-gray-900">
                                    <option value="">Certificate type</option>
                                    <?php foreach ($adminCertificateTypes as $certificateType): ?>
                                        <option value="<?php echo (int)$certificateType['id']; ?>" data-type-name="<?php echo esc($certificateType['certificate_type_name']); ?>" data-staff-groups="<?php echo esc(implode(',', certificateStaffMatchGroups($certificateType['certificate_type_name']))); ?>"><?php echo esc($certificateType['certificate_type_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select required name="assigned_staff_id" data-certificate-staff-select class="rounded-xl border border-gray-300 bg-white p-3 text-gray-900">
                                    <option value="">Assigned doctor / staff</option>
                                    <?php foreach ($certificateDoctorOptions as $doctorOption): ?>
                                        <?php
                                        $staffSearch = strtolower(($doctorOption['staff_type'] ?? '') . ' ' . ($doctorOption['specialization'] ?? ''));
                                        $staffGroups = [];
                                        if (str_contains($staffSearch, 'physician') || str_contains($staffSearch, 'doctor')) $staffGroups[] = 'physician';
                                        if (str_contains($staffSearch, 'nurse')) $staffGroups[] = 'nurse';
                                        if (str_contains($staffSearch, 'midwife')) $staffGroups[] = 'midwife';
                                        if (str_contains($staffSearch, 'medtech') || str_contains($staffSearch, 'medical technolog') || str_contains($staffSearch, 'laboratory')) $staffGroups[] = 'medtech';
                                        if (str_contains($staffSearch, 'sanitary') || str_contains($staffSearch, 'sanitation') || str_contains($staffSearch, 'inspector')) $staffGroups[] = 'sanitary';
                                        if (str_contains($staffSearch, 'bhw') || str_contains($staffSearch, 'barangay health')) $staffGroups[] = 'bhw';
                                        ?>
                                        <option value="<?php echo (int)$doctorOption['id']; ?>" data-staff-groups="<?php echo esc(implode(',', array_unique($staffGroups))); ?>">
                                            <?php echo esc(trim($doctorOption['name']) . ' - ' . $doctorOption['staff_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input required type="date" name="issue_date" value="<?php echo esc(date('Y-m-d')); ?>" class="rounded-xl border border-gray-300 p-3 text-gray-900">
                                <input type="date" name="expiry_date" value="<?php echo esc(date('Y-m-d', strtotime('+6 months'))); ?>" min="<?php echo esc(date('Y-m-d')); ?>" class="rounded-xl border border-gray-300 p-3 text-gray-900">
                                <?php if ($canUploadAdminSignature): ?>
                                <label class="rounded-xl border border-dashed border-emerald-300 bg-emerald-50/70 p-3 font-bold text-emerald-900 lg:col-span-2">
                                    Admin signature image
                                    <input type="file" name="admin_signature_image" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full text-[11px] font-semibold text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-700 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white">
                                    <span class="mt-1 block text-[10px] font-semibold text-emerald-800">Optional. Upload here to sign as admin immediately instead of approving through email.</span>
                                </label>
                                <?php endif; ?>
                                <textarea required name="purpose" data-certificate-purpose rows="3" maxlength="1000" class="rounded-xl border border-gray-300 p-3 text-gray-900 lg:col-span-3" placeholder="Purpose / certification statement"></textarea>
                                <button type="submit" class="rounded-xl bg-emerald-600 p-3 font-extrabold text-white shadow-sm hover:bg-emerald-700">Create & Request Approval</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- PENDING CERTIFICATE REQUESTS PANEL -->
                    <?php
                    $adminCertificateDisplayList = $dbCertificatesList;
                    usort($adminCertificateDisplayList, static function (array $a, array $b): int {
                        $rankCertificate = static function (array $cert): int {
                            $workflow = strtolower((string)($cert['workflow_status'] ?? ''));
                            $status = strtolower((string)($cert['status'] ?? ''));
                            $combined = trim($workflow . ' ' . $status);
                            if (str_contains($combined, 'pending') || str_contains($combined, 'draft') || str_contains($combined, 'requested')) return 0;
                            if (str_contains($combined, 'approval') || str_contains($combined, 'signed')) return 1;
                            if (str_contains($combined, 'sent') || str_contains($combined, 'issued') || str_contains($combined, 'approved') || str_contains($combined, 'completed')) return 2;
                            if (str_contains($combined, 'rejected') || str_contains($combined, 'cancelled')) return 3;
                            return 1;
                        };
                        $rankCompare = $rankCertificate($a) <=> $rankCertificate($b);
                        if ($rankCompare !== 0) return $rankCompare;
                        $aTime = strtotime((string)($a['created_at'] ?? '')) ?: 0;
                        $bTime = strtotime((string)($b['created_at'] ?? '')) ?: 0;
                        return $bTime <=> $aTime;
                    });
                    ?>
                    <div id="certificate-requests" class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100" data-certificate-cleanup-panel>
                        <?php if (($_POST['action'] ?? '') === 'approve_certificate' && ($flashError || $flashSuccess)): ?>
                            <p role="status" class="mb-4 rounded-lg border p-3 text-sm font-semibold <?php echo $flashError ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800'; ?>"><?php echo esc($flashError ?: $flashSuccess); ?></p>
                        <?php endif; ?>
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                            <div>
                                <h3 class="font-bold text-gray-900 text-base">Resident Medical Certificate Requests</h3>
                                <p class="text-xs text-gray-500">Live requests submitted by residents requiring Admin approval</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full"><?php echo count($dbCertificatesList); ?> Records</span>
                                <?php if (!empty($dbCertificatesList)): ?>
                                    <button type="button" data-certificate-select-toggle class="text-xs font-bold rounded-full bg-emerald-100 px-3 py-1.5 text-emerald-900 hover:bg-emerald-200">Select Delete</button>
                                    <button type="submit" form="deleteSelectedCertificatesForm" data-certificate-delete-selected class="hidden text-xs font-bold rounded-full bg-red-100 px-3 py-1.5 text-red-800 hover:bg-red-200" onclick="return confirm('Delete selected certificate requests?')">Delete Selected</button>
                                    <form method="post" id="deleteSelectedCertificatesForm" class="hidden">
                                        <input type="hidden" name="action" value="delete_selected_certificates">
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (empty($adminCertificateDisplayList)): ?>
                            <p class="text-xs text-gray-400 italic py-3">No certificate requests found in database.</p>
                        <?php else: ?>
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                <input type="search" data-certificate-search placeholder="Search certificate records..." class="min-w-[220px] flex-1 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs text-gray-900 sm:max-w-xs">
                                <div class="flex items-center gap-2">
                                    <span data-certificate-page-status class="text-xs font-semibold text-gray-600"></span>
                                    <button type="button" data-certificate-page-prev class="px-3 py-1.5 text-xs font-bold">Previous</button>
                                    <button type="button" data-certificate-page-next class="px-3 py-1.5 text-xs font-bold">Next</button>
                                </div>
                            </div>
                            <div class="certificate-requests-scroll overflow-auto">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50 text-gray-500 uppercase">
                                        <tr>
                                            <th class="hidden px-3 py-2 text-left" data-certificate-select-header>Select</th>
                                            <th class="px-3 py-2 text-left">Resident Name</th>
                                            <th class="px-3 py-2 text-left">Certificate Type</th>
                                            <th class="px-3 py-2 text-left">Purpose</th>
                                            <th class="px-3 py-2 text-left">Status</th>
                                            <th class="px-3 py-2 text-left">Date Requested</th>
                                            <th class="px-3 py-2 text-left">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($adminCertificateDisplayList as $cert): ?>
                                            <?php
                                            $certificateResidentName = trim((string)($cert['resident_full_name'] ?? ''));
                                            if ($certificateResidentName === '') {
                                                $certificateResidentName = trim(implode(' ', array_filter([
                                                    trim((string)($cert['first_name'] ?? '')),
                                                    trim((string)($cert['middle_name'] ?? '')),
                                                    trim((string)($cert['last_name'] ?? '')),
                                                ])));
                                            }
                                            $certificateResidentLabel = $certificateResidentName !== '' ? $certificateResidentName : 'Resident #' . (int)($cert['resident_id'] ?? 0);
                                            $certSearchText = strtolower(trim(implode(' ', [
                                                $certificateResidentLabel,
                                                'Resident #' . (int)($cert['resident_id'] ?? 0),
                                                $cert['certificate_type'] ?? '',
                                                $cert['purpose'] ?? '',
                                                $cert['status'] ?? '',
                                                $cert['workflow_status'] ?? '',
                                                $cert['created_at'] ?? '',
                                            ])));
                                            ?>
                                            <tr class="hover:bg-gray-50" data-certificate-row data-certificate-search-text="<?php echo esc($certSearchText); ?>">
                                                <td class="hidden px-3 py-2.5" data-certificate-select-box>
                                                    <input form="deleteSelectedCertificatesForm" type="checkbox" name="certificate_ids[]" value="<?php echo esc(implode(',', $cert['_combined_ids'] ?? [(int)$cert['id']])); ?>" class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                </td>
                                                 <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc($certificateResidentLabel); ?></td>
                                                <td class="px-3 py-2.5 text-gray-700"><?php echo esc($cert['certificate_type']); ?></td>
                                                <td class="px-3 py-2.5 text-gray-600 max-w-[200px] truncate">
                                                    <?php
                                                    $workflowStatus = (string)($cert['workflow_status'] ?? $cert['status'] ?? 'Draft');
                                                    $certificateStatusText = strtolower((string)($cert['status'] ?? ''));
                                                    $certificateApproved = in_array($workflowStatus, ['Signed', 'Sent'], true)
                                                        || str_contains($certificateStatusText, 'approved')
                                                        || str_contains($certificateStatusText, 'issued');
                                                    ?>
                                                    <?php echo esc($cert['purpose']); ?>
                                                    <?php if ((int)($cert['duplicate_count'] ?? 1) > 1): ?>
                                                        <div class="mt-1 text-[10px] font-bold text-emerald-700"><?php echo (int)$cert['duplicate_count']; ?> similar requests combined</div>
                                                    <?php endif; ?>
                                                    <div class="mt-1 text-[10px] font-bold text-blue-700">Workflow: <?php echo esc($workflowStatus); ?></div>
                                                    <?php if (trim((string)($cert['assigned_doctor_name'] ?? '')) !== ''): ?>
                                                        <div class="text-[10px] text-gray-500">Approver: <?php echo esc(trim($cert['assigned_doctor_name']) . ' - ' . ($cert['assigned_doctor_position'] ?? '')); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    <?php if ($certificateApproved): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-bold"><?php echo iconSvg('check', 'w-3 h-3 shrink-0'); ?> Approved</span>
                                                    <?php elseif ($cert['status'] === 'Rejected' || $workflowStatus === 'Rejected'): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-bold"><?php echo iconSvg('close', 'w-3 h-3 shrink-0'); ?> Rejected</span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-bold"><?php echo iconSvg('clock', 'w-3 h-3 shrink-0'); ?> Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5 text-gray-500"><?php echo esc(date('M d, Y', strtotime($cert['created_at']))); ?></td>
                                                <td class="px-3 py-2.5">
                                                    <?php if (in_array($workflowStatus, ['Pending', 'Draft', 'Pending Doctor Approval'], true) || $cert['status'] === 'Pending'): ?>
                                                        <div class="flex items-center gap-1.5">
                                                            <form method="post" action="#certificate-requests" data-certificate-approval-form class="inline">
                                                                <input type="hidden" name="action" value="approve_certificate">
                                                                <input type="hidden" name="request_id" value="<?php echo $cert['id']; ?>">
                                                                <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                                                                <button type="submit" class="px-2.5 py-1 bg-blue-600 text-white rounded font-semibold text-[11px] hover:bg-blue-700"><?php echo $workflowStatus === 'Pending Doctor Approval' ? 'Retry Approval Emails' : 'Generate & Request Approval'; ?></button>
                                                                <p data-certificate-approval-result role="status" aria-live="polite" class="hidden mt-2 max-w-xs whitespace-normal text-xs"></p>
                                                            </form>
                                                            <?php if (!empty($cert['generated_html'])): ?>
                                                                <button type="button" data-certificate-preview-open="<?php echo (int)$cert['id']; ?>" class="px-2.5 py-1 bg-slate-100 text-slate-700 rounded font-semibold text-[11px] hover:bg-slate-200">Preview</button>
                                                                <template data-certificate-preview-template="<?php echo (int)$cert['id']; ?>"><?php echo $cert['generated_html']; ?></template>
                                                                <form method="post" class="inline">
                                                                    <input type="hidden" name="action" value="regenerate_certificate">
                                                                    <input type="hidden" name="certificate_id" value="<?php echo $cert['id']; ?>">
                                                                    <button type="submit" class="px-2.5 py-1 bg-amber-500 text-white rounded font-semibold text-[11px] hover:bg-amber-600">Regenerate</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if (($workflowStatus ?? '') === 'Signed'): ?>
                                                                <form method="post" class="inline">
                                                                    <input type="hidden" name="action" value="send_final_certificate">
                                                                    <input type="hidden" name="certificate_id" value="<?php echo $cert['id']; ?>">
                                                                    <button type="submit" class="px-2.5 py-1 bg-green-600 text-white rounded font-semibold text-[11px] hover:bg-green-700">Approve Final & Send</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="post" class="inline">
                                                                <input type="hidden" name="action" value="reject_certificate">
                                                                <input type="hidden" name="request_id" value="<?php echo $cert['id']; ?>">
                                                                <button type="submit" class="px-2.5 py-1 bg-red-600 text-white rounded font-semibold text-[11px] hover:bg-red-700">Reject</button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php if (!empty($cert['generated_html'])): ?>
                                                            <button type="button" data-certificate-preview-open="<?php echo (int)$cert['id']; ?>" class="mb-1 px-2.5 py-1 bg-slate-100 text-slate-700 rounded font-semibold text-[11px] hover:bg-slate-200">Preview</button>
                                                            <template data-certificate-preview-template="<?php echo (int)$cert['id']; ?>"><?php echo $cert['generated_html']; ?></template>
                                                        <?php endif; ?>
                                                        <?php if (($workflowStatus ?? '') === 'Signed'): ?>
                                                            <form method="post" class="mb-1 inline">
                                                                <input type="hidden" name="action" value="send_final_certificate">
                                                                <input type="hidden" name="certificate_id" value="<?php echo $cert['id']; ?>">
                                                                <button type="submit" class="px-2.5 py-1 bg-green-600 text-white rounded font-semibold text-[11px] hover:bg-green-700">Approve Final & Send</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if (($workflowStatus ?? '') === 'Sent'): ?>
                                                            <a href="?certificate_pdf=<?php echo (int)$cert['id']; ?>" class="text-[11px] font-bold text-purple-700">Download</a>
                                                        <?php elseif (str_contains((string)($workflowStatus ?? ''), 'Approval')): ?>
                                                            <span class="text-[11px] font-bold text-blue-700">Waiting for e-signature approval</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div data-certificate-preview-modal class="fixed inset-0 z-[95] hidden bg-slate-950/50 p-3 backdrop-blur-sm sm:p-6">
                        <div class="mx-auto flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
                            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
                                <div>
                                    <h3 class="text-sm font-black text-gray-900">Certificate Preview</h3>
                                    <p class="text-xs text-gray-500">Review the generated certificate before final sending.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="#" data-certificate-preview-download class="hidden items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-800">
                                        <?php echo iconSvg('download', 'w-4 h-4'); ?><span>Download</span>
                                    </a>
                                    <button type="button" data-certificate-preview-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close certificate preview">&times;</button>
                                </div>
                            </div>
                            <div data-certificate-preview-content class="flex-1 overflow-auto bg-slate-100 p-4 sm:p-6"></div>
                        </div>
                    </div>
                    <script>
                        (() => {
                            const panel = document.querySelector('[data-certificate-cleanup-panel]');
                            if (!panel || panel.dataset.certificateInlineBound === '1') return;
                            panel.dataset.certificateInlineBound = '1';
                            const toggle = panel.querySelector('[data-certificate-select-toggle]');
                            const deleteSelected = panel.querySelector('[data-certificate-delete-selected]');
                            const rows = Array.from(panel.querySelectorAll('[data-certificate-row]'));
                            const inputs = Array.from(panel.querySelectorAll('input[name="certificate_ids[]"]'));
                            const search = panel.querySelector('[data-certificate-search]');
                            const pageStatus = panel.querySelector('[data-certificate-page-status]');
                            const pagePrev = panel.querySelector('[data-certificate-page-prev]');
                            const pageNext = panel.querySelector('[data-certificate-page-next]');
                            const rowsPerPage = 5;
                            let selectMode = false;
                            let currentPage = 1;

                            const renderSelectMode = () => {
                                panel.dataset.certificateSelectMode = selectMode ? '1' : '0';
                                panel.querySelectorAll('[data-certificate-select-box], [data-certificate-select-header]').forEach((box) => box.classList.toggle('hidden', !selectMode));
                                const hasSelection = inputs.some((input) => input.checked);
                                rows.forEach((row) => {
                                    const checkbox = row.querySelector('input[name="certificate_ids[]"]');
                                    row.classList.toggle('is-certificate-selected', Boolean(checkbox?.checked));
                                });
                                deleteSelected?.classList.toggle('hidden', !selectMode || !hasSelection);
                                if (toggle) toggle.textContent = selectMode ? 'Cancel Select' : 'Select Delete';
                                if (!selectMode) inputs.forEach((input) => input.checked = false);
                            };

                            const renderPage = () => {
                                const query = (search?.value || '').trim().toLowerCase();
                                const visibleRows = rows.filter((row) => !query || (row.dataset.certificateSearchText || '').includes(query));
                                const totalPages = Math.max(1, Math.ceil(visibleRows.length / rowsPerPage));
                                currentPage = Math.min(Math.max(currentPage, 1), totalPages);
                                rows.forEach((row) => row.classList.add('hidden'));
                                visibleRows.slice((currentPage - 1) * rowsPerPage, currentPage * rowsPerPage).forEach((row) => row.classList.remove('hidden'));
                                if (pageStatus) pageStatus.textContent = `${visibleRows.length} record${visibleRows.length === 1 ? '' : 's'} - Page ${currentPage}/${totalPages}`;
                                if (pagePrev) pagePrev.disabled = currentPage <= 1;
                                if (pageNext) pageNext.disabled = currentPage >= totalPages;
                            };

                            toggle?.addEventListener('click', () => {
                                selectMode = !selectMode;
                                renderSelectMode();
                            });
                            inputs.forEach((input) => input.addEventListener('change', renderSelectMode));
                            rows.forEach((row) => row.addEventListener('click', (event) => {
                                if (!selectMode || event.target.closest('a, button, form, input, label, select, textarea')) return;
                                const checkbox = row.querySelector('input[name="certificate_ids[]"]');
                                if (!checkbox) return;
                                checkbox.checked = !checkbox.checked;
                                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                            }));
                            search?.addEventListener('input', () => {
                                currentPage = 1;
                                renderPage();
                            });
                            pagePrev?.addEventListener('click', () => {
                                currentPage -= 1;
                                renderPage();
                            });
                            pageNext?.addEventListener('click', () => {
                                currentPage += 1;
                                renderPage();
                            });
                            renderSelectMode();
                            renderPage();
                        })();
                    </script>

                    <?php
                        $adminMessageGroups = [];
                        foreach ($dbMessagesList as $msg) {
                            $residentName = trim((string)(($msg['first_name'] ?? '') . ' ' . ($msg['last_name'] ?? '')));
                            if ($residentName === '') {
                                $residentName = 'Resident #' . (int)($msg['resident_id'] ?? 0);
                            }
                            $groupKey = !empty($msg['resident_id'])
                                ? 'resident-' . (int)$msg['resident_id']
                                : 'email-' . (string)($msg['email'] ?? $residentName);
                            if (!isset($adminMessageGroups[$groupKey])) {
                                $adminMessageGroups[$groupKey] = [
                                    'residentName' => $residentName,
                                    'email' => (string)($msg['email'] ?? ''),
                                    'latestDate' => (string)($msg['created_at'] ?? ''),
                                    'messages' => [],
                                ];
                            }
                            $cleanMessage = trim((string)preg_replace('/\s+/', ' ', preg_replace('/\s*\[Replied:\s*.*?\]\s*/i', ' ', (string)($msg['message'] ?? ''))));
                            $msg['clean_message'] = $cleanMessage;
                            if (strcmp((string)($msg['created_at'] ?? ''), (string)$adminMessageGroups[$groupKey]['latestDate']) > 0) {
                                $adminMessageGroups[$groupKey]['latestDate'] = (string)($msg['created_at'] ?? '');
                            }
                            $adminMessageGroups[$groupKey]['messages'][] = $msg;
                        }
                        foreach ($adminMessageGroups as &$adminMessageGroup) {
                            usort($adminMessageGroup['messages'], static fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
                        }
                        unset($adminMessageGroup);
                        uasort($adminMessageGroups, static fn($a, $b) => strcmp((string)($b['latestDate'] ?? ''), (string)($a['latestDate'] ?? '')));
                    ?>
                    <!-- INCOMING RESIDENT MESSAGES PANEL -->
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100" data-message-cleanup-panel>
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                            <div>
                                <h3 class="font-bold text-gray-900 text-base">Incoming Resident Inquiries & Messages</h3>
                                <p class="text-xs text-gray-500">Live messages submitted from the Resident Portal</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <span id="admin-message-count" class="text-xs font-bold bg-blue-100 text-blue-800 px-3 py-1 rounded-full"><?php echo count($dbMessagesList); ?> Messages</span>
                                <button type="button" data-message-select-toggle class="<?php echo empty($dbMessagesList) ? 'hidden ' : ''; ?>text-xs font-bold rounded-full bg-emerald-100 px-3 py-1.5 text-emerald-900 hover:bg-emerald-200">Select Delete</button>
                                <button type="submit" form="deleteSelectedMessagesForm" data-message-delete-selected class="hidden text-xs font-bold rounded-full bg-red-100 px-3 py-1.5 text-red-800 hover:bg-red-200" onclick="return confirm('Delete selected resident messages?')">Delete Selected</button>
                                <form method="post" id="deleteSelectedMessagesForm" class="hidden">
                                    <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="delete_selected_messages">
                                    <input type="hidden" name="tab" value="<?php echo esc($tab); ?>">
                                </form>
                            </div>
                        </div>
                        <div id="admin-message-list">
                            <?php if (empty($dbMessagesList)): ?>
                                <p class="text-xs text-gray-400 italic py-3">No resident messages found in database.</p>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach (array_values($adminMessageGroups) as $groupIndex => $group): ?>
                                        <?php
                                            $latestMessage = end($group['messages']) ?: [];
                                            $threadId = 'admin-message-thread-initial-' . $groupIndex;
                                            $selectionKey = (string)($latestMessage['resident_id'] ?? $group['email'] ?? $group['residentName']);
                                            reset($group['messages']);
                                        ?>
                                        <div class="border-b border-gray-100 py-2 last:border-b-0">
                                            <button type="button" class="flex w-full items-center justify-between gap-3 rounded-lg border border-gray-100 bg-white px-3 py-2 text-left hover:bg-gray-50" data-admin-message-thread-toggle="<?php echo esc($threadId); ?>" data-message-ids="<?php echo esc(json_encode(array_map(static fn($item) => (string)$item['id'], $group['messages']))); ?>">
                                                <span class="min-w-0">
                                                    <span class="flex items-center gap-2 truncate text-xs font-black text-gray-900">
                                                        <span data-new-message-dot hidden class="h-2.5 w-2.5 shrink-0 rounded-full bg-red-600" title="New message"></span>
                                                        <span class="truncate"><?php echo esc($group['residentName']); ?><?php echo $group['email'] !== '' ? ' (' . esc($group['email']) . ')' : ''; ?></span>
                                                    </span>
                                                    <span class="mt-1 block truncate text-[11px] font-semibold text-gray-500"><?php echo esc($latestMessage['subject'] ?? 'Resident Message'); ?> - <?php echo esc($latestMessage['clean_message'] ?? ''); ?></span>
                                                </span>
                                                <span class="shrink-0 text-[10px] font-semibold text-gray-400"><?php echo esc(date('M d, Y h:i A', strtotime((string)($group['latestDate'] ?? 'now')))); ?></span>
                                            </button>
                                            <div id="<?php echo esc($threadId); ?>" data-reply-selection-key="<?php echo esc($selectionKey); ?>" class="hidden mt-2 space-y-2 px-2">
                                                <?php foreach ($group['messages'] as $msg): ?>
                                                    <div class="flex items-start gap-2 text-xs">
                                                        <label class="hidden pt-0.5" data-message-select-box>
                                                            <input form="deleteSelectedMessagesForm" type="checkbox" name="message_ids[]" value="<?php echo (int)$msg['id']; ?>" class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                            <span class="sr-only">Select message <?php echo (int)$msg['id']; ?></span>
                                                        </label>
                                                        <div class="flex flex-1 flex-col gap-1.5">
                                                            <button type="button" data-admin-reply-message="<?php echo (int)$msg['id']; ?>" aria-pressed="false" class="admin-message-row w-full text-left">
                                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                                    <span class="font-bold text-gray-900"><?php echo esc($msg['subject'] ?: 'General Inquiry'); ?></span>
                                                                    <span class="text-[10px] font-semibold text-gray-400"><?php echo esc(date('M d, Y h:i A', strtotime($msg['created_at']))); ?></span>
                                                                </div>
                                                                <p class="mt-0.5 whitespace-pre-wrap text-[11px] font-medium leading-snug text-gray-700"><?php echo esc($msg['clean_message']); ?></p>
                                                            </button>
                                                            <?php if (!empty($msg['admin_reply'])): ?>
                                                                <div class="admin-message-reply ml-auto w-full max-w-3xl px-3 py-2">
                                                                    <p class="text-[10px] font-black uppercase tracking-wide text-teal-700">Admin Reply</p>
                                                                    <p class="mt-0.5 whitespace-pre-wrap text-[11px] font-semibold leading-snug"><?php echo esc($msg['admin_reply']); ?></p>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="mt-2 border-t border-gray-100 pt-2">
                                                    <p data-reply-target class="mb-2 text-xs font-semibold text-teal-700">No message selected</p>
                                                    <form method="post" class="flex gap-2">
                                                        <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                                                        <input type="hidden" name="action" value="reply_message">
                                                        <input type="hidden" name="tab" value="<?php echo esc($tab); ?>">
                                                        <input type="hidden" name="message_id" value="0">
                                                        <input required name="reply" class="min-w-0 flex-1 rounded-full border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:border-teal-600" placeholder="Write a reply...">
                                                        <button type="submit" disabled class="rounded-full bg-teal-700 px-4 py-2 text-xs font-bold text-white hover:bg-teal-800 disabled:opacity-50 disabled:cursor-not-allowed">Send</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- USER MANAGEMENT TAB -->
            <?php if ($tab === 'users'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('users', 'w-5 h-5 text-blue-600'); ?> Database User Accounts</h2>
                        <span class="text-xs bg-blue-100 text-blue-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbUsersList); ?> Accounts</span>
                    </div>

                    <section class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-4 shadow-sm">
                        <div class="mb-3">
                            <h3 class="text-sm font-black text-emerald-950">Register Healthcare Staff</h3>
                            <p class="text-xs text-emerald-800">Create an active staff login and save the account to the <code>health_workers</code> table.</p>
                        </div>
                        <form method="post" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                            <input type="hidden" name="action" value="create_staff">
                            <input type="hidden" name="tab" value="users">
                            <input required name="first_name" placeholder="First name" autocomplete="given-name" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input required name="last_name" placeholder="Last name" autocomplete="family-name" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input required type="email" name="email" placeholder="Email address" autocomplete="email" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input required type="password" name="password" placeholder="Temporary password" autocomplete="new-password" minlength="8" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <select required name="staff_type" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                                <option value="">Select staff role</option>
                                <option value="PHYSICIAN">Physician</option>
                                <option value="NURSE">Nurse</option>
                                <option value="MIDWIFE">Midwife</option>
                                <option value="MEDTECH">Medical Technologist</option>
                                <option value="SANITARY_INSPECTOR">Sanitary Inspector</option>
                                <option value="DENTAL">Dental Health Staff</option>
                                <option value="BHW">Barangay Health Worker</option>
                                <option value="SECRETARY">Admin Secretary</option>
                                <option value="ADMIN_STAFF">Admin Staff</option>
                            </select>
                            <input name="license_number" placeholder="License number (optional)" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input name="phone_number" placeholder="Contact number (optional)" autocomplete="tel" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-black text-white shadow-sm hover:bg-emerald-800">Register Staff Account</button>
                        </form>
                    </section>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[700px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">ID</th>
                                        <th class="px-3 py-2.5 text-left">Name</th>
                                        <th class="px-3 py-2.5 text-left">Email Address</th>
                                        <th class="px-3 py-2.5 text-left">Role</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                        <th class="px-3 py-2.5 text-left">Created At</th>
                                        <th class="px-3 py-2.5 text-left">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbUsersList as $u): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">#<?php echo $u['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: $u['username']; ?></td>
                                            <td class="px-3 py-2.5 text-gray-700 font-mono"><?php echo esc($u['email']); ?></td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="flex gap-1"><input type="hidden" name="action" value="update_user_role"><input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>"><select name="role_name" class="rounded border p-1"><?php foreach ($dbRoles as $role): ?><option value="<?php echo esc($role['name']); ?>" <?php echo ($u['role_name'] ?? '') === $role['name'] ? 'selected' : ''; ?>><?php echo esc($role['name']); ?></option><?php endforeach; ?></select><button class="rounded bg-purple-700 px-2 text-white">Set</button></form>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <?php if ($u['is_active']): ?>
                                                    <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-bold">Active</span>
                                                <?php else: ?>
                                                    <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-bold">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2.5 text-gray-500"><?php echo esc(date('M d, Y', strtotime($u['created_at']))); ?></td>
                                            <td class="px-3 py-2.5">
                                                <div class="flex items-center gap-1.5">
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="action" value="toggle_user_status">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <input type="hidden" name="new_status" value="<?php echo $u['is_active'] ? 0 : 1; ?>">
                                                        <button type="submit" class="px-2 py-0.5 text-[11px] font-semibold rounded <?php echo $u['is_active'] ?>">
                                                            <?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                    <form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this user from database?');">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="px-2 py-0.5 text-[11px] font-semibold rounded bg-red-100 text-red-700 hover:bg-red-200">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STAFF ACCOUNTS TAB -->
            <?php if ($tab === 'staff'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'staff-tools', 'Staff Account Tools', 'Create, update, and delete RHU staff accounts without leaving the registry.', 'staff'); ?>
                <?php
                $selectedStaffType = trim($_GET['staff_type'] ?? 'all');
                $selectedStaffId = ctype_digit((string)($_GET['staff_id'] ?? '')) ? (int)$_GET['staff_id'] : 0;
                $staffFolders = [
                    'all' => ['label' => 'All Staff', 'people' => count($dbStaffList), 'records' => 0],
                    'PHYSICIAN' => ['label' => 'Physicians', 'people' => 0, 'records' => 0],
                    'NURSE' => ['label' => 'Nurses', 'people' => 0, 'records' => 0],
                    'MIDWIFE' => ['label' => 'Midwives', 'people' => 0, 'records' => 0],
                    'MEDTECH' => ['label' => 'MedTech', 'people' => 0, 'records' => 0],
                    'SANITARY_INSPECTOR' => ['label' => 'Sanitary', 'people' => 0, 'records' => 0],
                    'DENTAL' => ['label' => 'Dental', 'people' => 0, 'records' => 0],
                    'ADMIN_STAFF' => ['label' => 'Admin Staff', 'people' => 0, 'records' => 0],
                ];
                $staffFolderKeyFromType = static function (string $staffType): string {
                    $type = strtoupper(trim($staffType));
                    if ($type === '') return 'ADMIN_STAFF';
                    if (str_contains($type, 'PHYSICIAN') || str_contains($type, 'DOCTOR')) return 'PHYSICIAN';
                    if (str_contains($type, 'NURSE')) return 'NURSE';
                    if (str_contains($type, 'MIDWIFE')) return 'MIDWIFE';
                    if (str_contains($type, 'MEDTECH') || str_contains($type, 'MEDICAL TECHNOLOG') || str_contains($type, 'LABORATORY')) return 'MEDTECH';
                    if (str_contains($type, 'SANITARY') || str_contains($type, 'SANITATION') || str_contains($type, 'INSPECTOR')) return 'SANITARY_INSPECTOR';
                    if (str_contains($type, 'DENTAL') || str_contains($type, 'DENTIST')) return 'DENTAL';
                    if (str_contains($type, 'BHW') || str_contains($type, 'BARANGAY HEALTH')) return 'BHW';
                    if (str_contains($type, 'ADMIN')) return 'ADMIN_STAFF';
                    return $type;
                };
                if (!isset($staffFolders[$selectedStaffType])) {
                    $selectedStaffType = 'all';
                }
                $staffById = [];
                $staffIdByUserId = [];
                foreach ($dbStaffList as $staffFolderRow) {
                    $staffId = (int)($staffFolderRow['id'] ?? 0);
                    if ($staffId > 0) {
                        $staffById[$staffId] = $staffFolderRow;
                    }
                    $staffUserId = (int)($staffFolderRow['user_id'] ?? 0);
                    if ($staffUserId > 0 && $staffId > 0) {
                        $staffIdByUserId[$staffUserId] = $staffId;
                    }
                    $folderType = $staffFolderKeyFromType((string)($staffFolderRow['staff_type'] ?? ''));
                    if (isset($staffFolders[$folderType])) {
                        $staffFolders[$folderType]['people']++;
                    }
                }
                $midwifeFolderStaffIds = array_keys(array_filter($staffById, fn($staffRow) => $staffFolderKeyFromType((string)($staffRow['staff_type'] ?? '')) === 'MIDWIFE'));
                $singleMidwifeStaffId = count($midwifeFolderStaffIds) === 1 ? (int)$midwifeFolderStaffIds[0] : 0;
                $sanitaryFolderStaffIds = array_keys(array_filter($staffById, fn($staffRow) => $staffFolderKeyFromType((string)($staffRow['staff_type'] ?? '')) === 'SANITARY_INSPECTOR'));
                $singleSanitaryStaffId = count($sanitaryFolderStaffIds) === 1 ? (int)$sanitaryFolderStaffIds[0] : 0;
                $staffFolderRecords = [];
                $recordKeysByFolder = [];
                $resolveStaffFolderId = static function (int $candidateId) use ($staffById, $staffIdByUserId): int {
                    if ($candidateId <= 0) return 0;
                    if (!empty($staffById[$candidateId])) return $candidateId;
                    return (int)($staffIdByUserId[$candidateId] ?? 0);
                };
                $addStaffFolderRecord = static function (int $staffId, string $recordType, string $recordId, string $residentName, string $title, string $status, string $date, string $barangay = '', int $residentId = 0) use (&$staffFolderRecords, &$recordKeysByFolder, &$staffFolders, $staffById, $staffFolderKeyFromType): void {
                    if ($staffId <= 0 || empty($staffById[$staffId])) return;
                    $staffRow = $staffById[$staffId];
                    $folderType = $staffFolderKeyFromType((string)($staffRow['staff_type'] ?? ''));
                    if (!isset($staffFolders[$folderType])) return;
                    $uniqueKey = $recordType . ':' . $recordId . ':' . $staffId;
                    if (!empty($recordKeysByFolder[$folderType][$uniqueKey])) return;
                    $recordKeysByFolder[$folderType][$uniqueKey] = true;
                    $recordKeysByFolder['all'][$uniqueKey] = true;
                    $staffFolders[$folderType]['records']++;
                    $staffFolders['all']['records']++;
                    $staffFolderRecords[] = [
                        'folder_type' => $folderType,
                        'record_type' => $recordType,
                        'record_id' => $recordId,
                        'resident_name' => $residentName,
                        'resident_id' => $residentId,
                        'title' => $title,
                        'status' => $status,
                        'date' => $date,
                        'barangay' => $barangay,
                        'staff_id' => $staffId,
                        'staff_name' => trim((string)($staffRow['first_name'] ?? '') . ' ' . (string)($staffRow['last_name'] ?? '')) ?: 'Staff #' . $staffId,
                    ];
                };
                foreach ($dbConsultationsList as $record) {
                    $consultationStaffId = $resolveStaffFolderId((int)($record['physician_id'] ?? 0));
                    $recordedByStaffId = $resolveStaffFolderId((int)($record['recorded_by_staff_id'] ?? 0));
                    if ($recordedByStaffId <= 0) {
                        $recordedByStaffId = (int)($staffIdByUserId[(int)($record['recorded_by_user_id'] ?? 0)] ?? 0);
                    }
                    $consultationText = strtolower((string)($record['chief_complaint'] ?? '') . ' ' . (string)($record['diagnosis'] ?? '') . ' ' . (string)($record['consultation_notes'] ?? ''));
                    if ($consultationStaffId <= 0 && $singleMidwifeStaffId > 0 && (str_contains($consultationText, 'prenatal') || str_contains($consultationText, 'maternal') || str_contains($consultationText, 'midwife'))) {
                        $consultationStaffId = $singleMidwifeStaffId;
                    }
                    if ($consultationStaffId <= 0 && $singleSanitaryStaffId > 0 && (str_contains($consultationText, 'sanitary') || str_contains($consultationText, 'inspection') || str_contains($consultationText, 'clearance') || str_contains($consultationText, 'sanitation'))) {
                        $consultationStaffId = $singleSanitaryStaffId;
                    }
                    $addStaffFolderRecord($consultationStaffId, 'Consultation', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['chief_complaint'] ?? $record['diagnosis'] ?? 'OPD consultation'), (string)($record['consultation_status'] ?? 'Scheduled'), (string)($record['consultation_date'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                    $addStaffFolderRecord($recordedByStaffId, 'Nursing Triage', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['chief_complaint'] ?? $record['diagnosis'] ?? 'OPD triage'), (string)($record['consultation_status'] ?? 'Recorded'), (string)($record['consultation_date'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbHealthProfileRecords as $record) {
                    $profileStaffId = $resolveStaffFolderId((int)($record['updated_by_staff_id'] ?? 0));
                    if ($profileStaffId <= 0) {
                        $profileStaffId = (int)($staffIdByUserId[(int)($record['updated_by_user_id'] ?? 0)] ?? 0);
                    }
                    $profileTitleParts = array_filter([
                        !empty($record['height']) ? 'Height: ' . $record['height'] . ' cm' : '',
                        !empty($record['weight']) ? 'Weight: ' . $record['weight'] . ' kg' : '',
                        !empty($record['blood_pressure']) ? 'BP: ' . $record['blood_pressure'] : '',
                    ]);
                    $addStaffFolderRecord($profileStaffId, 'Health Profile', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), $profileTitleParts ? implode(' | ', $profileTitleParts) : 'Resident medical profile updated', 'Updated', (string)($record['updated_at'] ?? $record['last_checkup_date'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbMaternalCases as $record) {
                    $riskLabel = !empty($record['high_risk']) ? 'High Risk' : 'Low Risk';
                    $title = 'Prenatal case - EDC ' . ((string)($record['edc'] ?? '') ?: 'to be set');
                    $maternalStaffId = $resolveStaffFolderId((int)($record['midwife_staff_id'] ?? 0)) ?: $singleMidwifeStaffId;
                    $addStaffFolderRecord($maternalStaffId, 'Prenatal Case', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), $title, (string)($record['status'] ?? $riskLabel), (string)($record['created_at'] ?? $record['lmp'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbVaccinationRecords as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['healthcare_provider_id'] ?? 0)), 'Vaccination', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['vaccine_name'] ?? 'Vaccination record'), (string)($record['status'] ?? 'Completed'), (string)($record['vaccination_date'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbFamilyPlanningRecords as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['healthcare_provider_id'] ?? 0)), 'Family Planning', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['contraceptive_method'] ?? 'Family planning record'), (string)($record['status'] ?? 'Active'), (string)($record['created_at'] ?? $record['last_supply_date'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbMaternalReferrals as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['referred_by_id'] ?? 0)), 'Maternal Referral', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['diagnosis'] ?? $record['referral_reason'] ?? 'Maternal referral'), (string)($record['referral_status'] ?? 'Pending'), (string)($record['created_at'] ?? $record['referral_date'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbVitalStatistics as $record) {
                    if (($record['type'] ?? '') !== 'Birth') continue;
                    $motherName = trim(($record['mother_first_name'] ?? '') . ' ' . ($record['mother_last_name'] ?? ''));
                    $residentName = trim((string)($record['child_name'] ?? 'Birth record') . ($motherName !== '' ? ' (Mother: ' . $motherName . ')' : ''));
                    $birthStaffId = $resolveStaffFolderId((int)($record['delivery_attendant_id'] ?? 0)) ?: $singleMidwifeStaffId;
                    $addStaffFolderRecord($birthStaffId, 'Birth Record', (string)($record['id'] ?? ''), $residentName, (string)($record['location'] ?? 'Birth registration'), 'Registered', (string)($record['record_created_at'] ?? $record['date_recorded'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbDiseaseCases as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['reported_by_id'] ?? 0)), 'Disease Case', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['disease_name'] ?? 'Disease surveillance case'), (string)($record['status'] ?? $record['outcome'] ?? 'Recorded'), (string)($record['case_date'] ?? ''), (string)($record['barangay'] ?? ''), (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbCertificatesList as $record) {
                    $residentName = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
                    $certificateTitle = (string)($record['certificate_type'] ?? 'Health certificate');
                    $certificateStatus = (string)($record['workflow_status'] ?? $record['status'] ?? 'Draft');
                    $certificateDate = (string)($record['issue_date'] ?? $record['created_at'] ?? '');
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['assigned_doctor_id'] ?? 0)), 'Certificate', (string)($record['id'] ?? ''), $residentName, $certificateTitle, $certificateStatus, $certificateDate, '', (int)($record['resident_id'] ?? 0));
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['issued_by_id'] ?? 0)), 'Certificate', (string)($record['id'] ?? ''), $residentName, $certificateTitle, $certificateStatus, $certificateDate, '', (int)($record['resident_id'] ?? 0));
                    $adminStaffId = $staffIdByUserId[(int)($record['admin_approver_user_id'] ?? 0)] ?? 0;
                    $addStaffFolderRecord((int)$adminStaffId, 'Certificate Approval', (string)($record['id'] ?? ''), $residentName, $certificateTitle, $certificateStatus, $certificateDate, '', (int)($record['resident_id'] ?? 0));
                }
                foreach ($dbDentalRecords as $record) {
                    $residentName = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
                    if ($residentName === '') {
                        $residentName = (string)($record['walkin_name'] ?? 'Dental patient');
                    }
                    $dentalTitle = trim((string)($record['title'] ?? ''));
                    if ($dentalTitle === '') {
                        $dentalTitle = ucwords(str_replace('_', ' ', (string)($record['record_type'] ?? 'Dental record')));
                    }
                    $addStaffFolderRecord(
                        $resolveStaffFolderId((int)($record['performed_by'] ?? 0)),
                        'Dental ' . ucwords(str_replace('_', ' ', (string)($record['record_type'] ?? 'Record'))),
                        (string)($record['id'] ?? ''),
                        $residentName,
                        $dentalTitle,
                        (string)($record['status'] ?? 'Completed'),
                        (string)($record['record_date'] ?? $record['created_at'] ?? ''),
                        (string)($record['barangay'] ?? ''),
                        (int)($record['resident_id'] ?? 0)
                    );
                }
                usort($staffFolderRecords, static function (array $a, array $b): int {
                    $aTime = strtotime((string)($a['date'] ?? '')) ?: 0;
                    $bTime = strtotime((string)($b['date'] ?? '')) ?: 0;
                    return $bTime <=> $aTime;
                });
                $visibleStaffList = $selectedStaffType === 'all'
                    ? $dbStaffList
                    : array_values(array_filter($dbStaffList, fn($staffRow) => $staffFolderKeyFromType((string)($staffRow['staff_type'] ?? '')) === $selectedStaffType));
                if ($selectedStaffId > 0 && empty($staffById[$selectedStaffId])) {
                    $selectedStaffId = 0;
                }
                $visibleStaffFolderRecords = $selectedStaffType === 'all'
                    ? $staffFolderRecords
                    : array_values(array_filter($staffFolderRecords, fn($record) => (string)($record['folder_type'] ?? '') === $selectedStaffType));
                if ($selectedStaffId > 0) {
                    $visibleStaffFolderRecords = array_values(array_filter($visibleStaffFolderRecords, fn($record) => (int)($record['staff_id'] ?? 0) === $selectedStaffId));
                }
                $visibleStaffResidents = [];
                foreach (array_slice($visibleStaffFolderRecords, 0, 120) as $record) {
                    $residentName = trim((string)($record['resident_name'] ?? '')) ?: 'Resident record';
                    $residentKey = (int)($record['resident_id'] ?? 0) > 0 ? 'resident-' . (int)$record['resident_id'] : 'unlinked-' . ($record['record_type'] ?? '') . '-' . ($record['record_id'] ?? '');
                    if (!isset($visibleStaffResidents[$residentKey])) {
                        $visibleStaffResidents[$residentKey] = [
                            'name' => $residentName,
                            'resident_id' => (int)($record['resident_id'] ?? 0),
                            'barangay' => (string)($record['barangay'] ?? ''),
                            'records' => [],
                        ];
                    }
                    if ($visibleStaffResidents[$residentKey]['barangay'] === '' && trim((string)($record['barangay'] ?? '')) !== '') {
                        $visibleStaffResidents[$residentKey]['barangay'] = (string)$record['barangay'];
                    }
                    $visibleStaffResidents[$residentKey]['records'][] = $record;
                }
                $selectedStaffName = $selectedStaffId > 0 && !empty($staffById[$selectedStaffId])
                    ? (trim(($staffById[$selectedStaffId]['first_name'] ?? '') . ' ' . ($staffById[$selectedStaffId]['last_name'] ?? '')) ?: 'Staff #' . $selectedStaffId)
                    : '';
                ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('stethoscope', 'w-5 h-5 text-emerald-600'); ?> Healthcare Staff Registry</h2>
                        <button type="button" data-floating-modal-open="staff-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Staff Account Tools</button>
                    </div>

                    <div id="staff-folder-live-panel" class="staff-folder-panel bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-black text-gray-900">Staff Folders</h3>
                                <p class="text-xs text-gray-500">Open a folder to view personnel and assigned resident records by role.</p>
                            </div>
                            <?php if ($selectedStaffType !== 'all' || $selectedStaffId > 0): ?>
                                <a href="<?php echo esc(tabUrl('staff')); ?>" class="text-xs font-bold text-pink-600 hover:underline">Show all</a>
                            <?php endif; ?>
                        </div>
                        <div class="staff-folder-grid">
                            <?php foreach ($staffFolders as $folderKey => $folderMeta): ?>
                                <?php $folderActive = $selectedStaffType === $folderKey; ?>
                                <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $folderKey])); ?>" class="staff-folder-card <?php echo $folderActive ? 'is-active' : ''; ?>" <?php echo $folderActive ? 'aria-current="page"' : ''; ?>>
                                    <span class="staff-folder-icon" aria-hidden="true"></span>
                                    <span class="staff-folder-name"><?php echo esc($folderMeta['label']); ?></span>
                                    <span class="staff-folder-count"><?php echo (int)$folderMeta['records']; ?> records</span>
                                    <span class="staff-folder-count"><?php echo (int)$folderMeta['people']; ?> personnel</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($selectedStaffId > 0): ?>
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-lime-200 bg-lime-50 px-4 py-3 text-xs">
                            <span class="font-bold text-lime-900">Showing assigned resident records for <?php echo esc($selectedStaffName); ?>.</span>
                            <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $selectedStaffType])); ?>" class="font-black text-lime-900 hover:underline">Clear staff filter</a>
                        </div>
                    <?php endif; ?>

                    <div id="staff-personnel-live-panel" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="max-h-[34rem] overflow-auto" tabindex="0" role="region" aria-label="Staff personnel list">
                            <table class="w-full text-xs min-w-[700px]">
                                <thead class="sticky top-0 z-10 bg-gray-50 text-gray-500 uppercase shadow-sm">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Staff ID</th>
                                        <th class="px-3 py-2.5 text-left">Full Name</th>
                                        <th class="px-3 py-2.5 text-left">Position</th>
                                        <th class="px-3 py-2.5 text-left">License No.</th>
                                        <th class="px-3 py-2.5 text-left">Contact</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                        <th class="px-3 py-2.5 text-left">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($visibleStaffList as $st): ?>
                                        <?php
                                        $staffRowName = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? '')) ?: 'Staff #' . (int)$st['id'];
                                        $staffRowFolderType = $staffFolderKeyFromType((string)($st['staff_type'] ?? ''));
                                        $staffRowIsSelected = $selectedStaffId === (int)$st['id'];
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">STF-<?php echo $st['id']; ?></td>
                                            <td class="px-3 py-2.5">
                                                <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $staffRowFolderType, 'staff_id' => (int)$st['id']])); ?>" class="inline-flex items-center gap-2 rounded-lg px-2 py-1 font-black <?php echo $staffRowIsSelected ? 'bg-lime-100 text-lime-900' : 'text-gray-900 hover:bg-lime-50 hover:text-lime-900'; ?>">
                                                    <?php echo esc($staffRowName); ?>
                                                    <span class="text-[10px] font-bold text-gray-500">View records</span>
                                                </a>
                                            </td>
                                            <td class="px-3 py-2.5 text-purple-900 font-semibold"><?php echo esc($st['staff_type']); ?></td>
                                            <td class="px-3 py-2.5 font-mono text-gray-600"><?php echo esc($st['license_number'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc($st['phone_number'] ?? $st['email']); ?></td>
                                            <td class="px-3 py-2.5">
                                                <span class="px-2 py-0.5 rounded-full font-bold <?php echo $st['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                    <?php echo $st['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="action" value="toggle_staff_status">
                                                    <input type="hidden" name="staff_id" value="<?php echo $st['id']; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo $st['is_active'] ? 0 : 1; ?>">
                                                    <button type="submit" class="px-2.5 py-1 text-[11px] font-bold rounded <?php echo $st['is_active'] ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'; ?>">
                                                        <?php echo $st['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($visibleStaffList)): ?>
                                        <tr>
                                            <td colspan="7" class="px-3 py-8 text-center text-gray-400">No staff records found in this folder.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="staff-records-live-panel" class="assigned-records-panel bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-white px-4 py-4">
                            <div>
                                <h3 class="text-base font-black text-slate-950">Assigned Resident Records</h3>
                                <p class="mt-1 text-xs text-slate-600">Select a resident to view their connected records.</p>
                            </div>
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-900"><?php echo count($visibleStaffFolderRecords); ?> records</span>
                        </div>
                        <div class="max-h-[34rem] overflow-auto" tabindex="0" role="region" aria-label="Assigned resident records">
                            <table class="assigned-records-table w-full min-w-[820px] text-xs">
                                <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700 uppercase shadow-sm">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Resident</th>
                                        <th class="px-3 py-2.5 text-left">Records</th>
                                        <th class="px-3 py-2.5 text-left">Latest record</th>
                                        <th class="px-3 py-2.5 text-left">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($visibleStaffResidents as $resident): ?>
                                        <?php
                                        $residentRecords = $resident['records'];
                                        usort($residentRecords, static fn(array $a, array $b): int => (strtotime((string)($b['date'] ?? '')) ?: 0) <=> (strtotime((string)($a['date'] ?? '')) ?: 0));
                                        $latestRecord = $residentRecords[0] ?? [];
                                        ?>
                                        <tr class="assigned-resident-row align-top">
                                            <td class="px-4 py-3" data-resident-filter="<?php echo esc(strtolower($resident['name'] . '|' . $resident['barangay'])); ?>">
                                                <span class="resident-filter-name block font-black text-slate-950"><?php echo esc(rhuPatientLabel($resident['name'], $resident['resident_id'] ?? 0)); ?></span>
                                                <?php if ($resident['barangay'] !== ''): ?><span class="block text-[11px] text-slate-600"><?php echo esc($resident['barangay']); ?></span><?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3"><span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 font-black text-slate-900"><?php echo count($residentRecords); ?></span></td>
                                            <td class="px-4 py-3 font-semibold text-slate-800"><?php echo esc($latestRecord['title'] ?? ''); ?></td>
                                            <td class="px-4 py-3"><button type="button" class="text-[11px] font-black text-slate-700 hover:text-slate-950" data-assigned-records-toggle>View records</button></td>
                                        </tr>
                                        <tr class="assigned-record-detail-row hidden">
                                            <td colspan="4" class="px-4 pb-4">
                                                <div data-staff-record-scroll="Resident record list" tabindex="0" role="region" aria-label="Resident record list" class="staff-record-scroll staff-record-scroll-list resident-record-details space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                                    <?php foreach ($residentRecords as $record): ?>
                                                        <div class="resident-record-item grid gap-2 border-b border-slate-200 pb-2 last:border-0 last:pb-0 sm:grid-cols-[1.1fr_1.5fr_1fr_auto] sm:items-center">
                                                            <span class="font-mono font-bold text-slate-700">#<?php echo esc($record['record_id']); ?> <span class="resident-record-type ml-1 rounded-full px-2 py-0.5 text-white"><?php echo esc($record['record_type']); ?></span></span>
                                                            <span class="font-semibold text-slate-800"><?php echo esc($record['title']); ?></span>
                                                            <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $record['folder_type'], 'staff_id' => (int)($record['staff_id'] ?? 0)])); ?>" class="font-black text-slate-900 hover:text-slate-950 hover:underline"><?php echo esc($record['staff_name']); ?></a>
                                                            <span class="font-semibold text-slate-700"><?php echo esc($record['date'] ? date('M d, Y', strtotime((string)$record['date'])) : 'No date'); ?> - <?php echo esc($record['status'] ?: 'Recorded'); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($visibleStaffResidents)): ?>
                                        <tr><td colspan="4" class="px-3 py-8 text-center text-gray-400">No assigned resident records found in this folder yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RESIDENT REGISTRY TAB -->
            <?php if ($tab === 'residents'): ?>
                <style>
                #account-verification-overlay{position:fixed!important;inset:0!important;width:100vw!important;height:100dvh!important;z-index:10000!important;margin:0!important;padding:24px!important;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.5)!important;backdrop-filter:blur(4px)}
                #account-verification-overlay.hidden{display:none!important}
                #account-verification-overlay>section{display:flex!important;flex-direction:column!important;width:min(1050px,100%)!important;height:min(760px,88dvh)!important;max-height:88dvh!important;min-height:0!important;margin:0!important;padding:0!important;border:1px solid #d7e5df!important;border-radius:18px!important;overflow:hidden!important;background:white!important;box-shadow:0 24px 70px #0f172a40!important}
                #account-verification-overlay header{flex-shrink:0!important;margin:0!important;padding:16px 22px!important}
                #account-verification-frame{display:block!important;flex:1 1 0!important;width:100%!important;min-height:0!important;border:0!important}
                @media(max-width:640px){#account-verification-overlay{padding:12px!important}#account-verification-overlay>section{height:92dvh!important;max-height:92dvh!important}}
                </style>
                <div id="account-verification-overlay" data-floating-modal="account-verification" class="fixed inset-0 z-[90] hidden bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6">
                    <section role="dialog" aria-modal="true" aria-labelledby="account-verification-title" class="mx-auto flex h-full max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                        <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                            <h2 id="account-verification-title" class="text-lg font-bold text-teal-900">Resident account verification</h2>
                            <button type="button" data-floating-modal-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600" aria-label="Close resident account verification">&times;</button>
                        </header>
                        <iframe id="account-verification-frame" title="Review resident account requests" class="min-h-0 w-full flex-1 border-0" src="about:blank"></iframe>
                    </section>
                </div>

                <?php renderAdminFloatingToolsModal($pdo, 'resident-tools', 'Resident Registry Tools', 'Create and update resident registry records without leaving the table.', 'residents'); ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('check', 'w-5 h-5 text-teal-600'); ?> Municipal Resident Registry</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="account-verification" onclick="document.getElementById('account-verification-frame').src='ResidentAccountRequests.php?embedded=1'" class="rounded-lg border border-teal-200 px-4 py-2 text-xs font-bold text-teal-800">Review account requests</button>
                            <button type="button" data-floating-modal-open="resident-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Resident Registry Tools</button>
                            <span class="text-xs bg-teal-100 text-teal-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbResidentList); ?> Residents</span>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[700px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Resident ID</th>
                                        <th class="px-3 py-2.5 text-left">Full Name</th>
                                        <th class="px-3 py-2.5 text-left">Age / Sex</th>
                                        <th class="px-3 py-2.5 text-left">Barangay & Address</th>
                                        <th class="px-3 py-2.5 text-left">Contact No.</th>
                                        <th class="px-3 py-2.5 text-left">PhilHealth ID</th>
                                        <th class="px-3 py-2.5 text-left">Email Address</th>
                                        <th class="px-3 py-2.5 text-left">Registered Date</th>
                                        <th class="px-3 py-2.5 text-left">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbResidentList as $res): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">RES-<?php echo $res['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(rhuPatientLabel(trim(($res['first_name'] ?? '') . ' ' . ($res['middle_name'] ?? '') . ' ' . ($res['last_name'] ?? '')), $res['id'] ?? 0)); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc(($res['age'] ?? 'N/A') . ' yrs - ' . ($res['sex'] ?? 'N/A')); ?></td>
                                            <td class="px-3 py-2.5 font-semibold text-purple-900"><?php echo esc($res['barangay'] ?? 'Nasugbu'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600 font-mono"><?php echo esc($res['contact_number'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 font-mono text-gray-600"><?php echo esc($res['philhealth_id'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600 font-mono"><?php echo esc($res['email'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-500"><?php echo esc(date('M d, Y', strtotime($res['created_at']))); ?></td>
                                            <td class="px-3 py-2.5">
                                                <form method="post"><input type="hidden" name="action" value="update_resident_status"><input type="hidden" name="resident_id" value="<?php echo (int)$res['id']; ?>"><input type="hidden" name="new_status" value="<?php echo !empty($res['is_active']) ? 0 : 1; ?>"><button class="rounded px-2 py-1 font-bold <?php echo !empty($res['is_active']) ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'; ?>"><?php echo !empty($res['is_active']) ? 'Deactivate' : 'Activate'; ?></button></form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- OPD CONSULTATIONS TAB -->
            <?php if ($tab === 'consultations'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('activity', 'w-5 h-5 text-blue-600'); ?> OPD Consultations</h2>
                        <span data-consultation-page-badge class="text-xs bg-blue-100 text-blue-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbConsultationsList); ?> Consultations</span>
                    </div>
                    <?php
                    $consultationsByPatient = [];
                    foreach ($dbConsultationsList as $consultationRow) {
                        $patientName = trim(($consultationRow['first_name'] ?? '') . ' ' . ($consultationRow['last_name'] ?? '')) ?: 'Unknown patient';
                        $patientResidentId = (int)($consultationRow['resident_id'] ?? 0);
                        $patientKey = $patientResidentId > 0 ? 'resident-' . $patientResidentId : 'unlinked-' . (int)($consultationRow['id'] ?? 0);
                        if (!isset($consultationsByPatient[$patientKey])) {
                            $consultationsByPatient[$patientKey] = [
                                'name' => $patientName,
                                'resident_id' => $patientResidentId,
                                'barangay' => (string)($consultationRow['barangay'] ?? 'N/A'),
                                'records' => [],
                            ];
                        }
                        if (($consultationsByPatient[$patientKey]['barangay'] === '' || $consultationsByPatient[$patientKey]['barangay'] === 'N/A') && trim((string)($consultationRow['barangay'] ?? '')) !== '') {
                            $consultationsByPatient[$patientKey]['barangay'] = (string)$consultationRow['barangay'];
                        }
                        $consultationsByPatient[$patientKey]['records'][] = $consultationRow;
                    }
                    ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[800px] admin-consultation-patient-table">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Patient Name</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">Records</th>
                                        <th class="px-3 py-2.5 text-left">Latest Consultation</th>
                                        <th class="px-3 py-2.5 text-left">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($consultationsByPatient as $patientGroup): ?>
                                        <?php
                                        $patientRecords = $patientGroup['records'];
                                        usort($patientRecords, static function (array $a, array $b): int {
                                            return (strtotime((string)($b['consultation_date'] ?? '')) ?: 0) <=> (strtotime((string)($a['consultation_date'] ?? '')) ?: 0);
                                        });
                                        $latestConsultation = $patientRecords[0] ?? [];
                                        ?>
                                        <tr class="admin-consultation-patient-row" data-consultation-patient-row>
                                            <td class="px-3 py-3 font-black text-gray-900"><?php echo esc(rhuPatientLabel($patientGroup['name'], $patientGroup['resident_id'] ?? 0)); ?></td>
                                            <td class="px-3 py-3 font-semibold text-purple-900"><?php echo esc($patientGroup['barangay'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-3"><span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 font-black text-slate-900"><?php echo count($patientRecords); ?></span></td>
                                            <td class="px-3 py-3 font-semibold text-gray-800"><?php echo esc($latestConsultation['chief_complaint'] ?? 'No latest record'); ?></td>
                                            <td class="px-3 py-3"><button type="button" class="text-[11px] font-black text-slate-700 hover:text-slate-950" data-consultation-records-toggle>View records</button></td>
                                        </tr>
                                        <tr class="admin-consultation-detail-row hidden" data-consultation-detail-row>
                                            <td colspan="5" class="px-3 pb-4">
                                                <div class="admin-consultation-detail-wrap rounded-lg border border-slate-200 bg-slate-50 p-3">
                                                    <?php foreach ($patientRecords as $csl): ?>
                                                        <div data-qr-consultation-id="<?php echo (int)$csl['id']; ?>" class="admin-consultation-record-grid border-b border-slate-200 py-2 last:border-0">
                                                            <span class="font-mono font-bold text-gray-500">CSL-<?php echo (int)$csl['id']; ?></span>
                                                            <span class="font-semibold text-gray-800"><?php echo esc($csl['chief_complaint'] ?? ''); ?></span>
                                                            <span class="text-gray-700"><?php echo esc($csl['diagnosis'] ?? 'N/A'); ?></span>
                                                            <span class="text-gray-500"><?php echo esc(date('M d, Y', strtotime($csl['consultation_date']))); ?></span>
                                                            <?php
                                                            $assignedConsultationName = 'Unassigned';
                                                            foreach ($dbStaffList as $provider) {
                                                                if ((int)$provider['id'] === (int)($csl['physician_id'] ?? 0)) {
                                                                    $assignedConsultationName = trim($provider['first_name'] . ' ' . $provider['last_name']);
                                                                    break;
                                                                }
                                                            }
                                                            $canEditConsultation = $consultationEditorStaffId > 0
                                                                && $consultationEditorStaffId === (int)($csl['physician_id'] ?? 0);
                                                            ?>
                                                            <?php if ($canEditConsultation): ?>
                                                            <form method="post" class="grid gap-1 lg:grid-cols-[8rem_10rem_12rem_8rem_auto]">
                                                                <input type="hidden" name="action" value="update_consultation">
                                                                <input type="hidden" name="consultation_id" value="<?php echo (int)$csl['id']; ?>">
                                                                <input name="diagnosis" value="<?php echo esc($csl['diagnosis'] ?? ''); ?>" placeholder="Diagnosis" class="rounded border p-1">
                                                                <input name="treatment_plan" value="<?php echo esc($csl['treatment_plan'] ?? $csl['consultation_notes'] ?? ''); ?>" placeholder="Treatment plan" class="rounded border p-1">
                                                                <span class="rounded border p-1"><?php echo esc($assignedConsultationName); ?></span>
                                                                <select name="consultation_status" class="rounded border p-1 font-bold text-gray-800">
                                                                    <option value="Scheduled" <?php echo ($csl['consultation_status'] ?? '') === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                                    <option value="In Progress" <?php echo ($csl['consultation_status'] ?? '') === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                                    <option value="Completed" <?php echo ($csl['consultation_status'] ?? '') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                                    <option value="Cancelled" <?php echo ($csl['consultation_status'] ?? '') === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                                    <option value="Referred" <?php echo ($csl['consultation_status'] ?? '') === 'Referred' ? 'selected' : ''; ?>>Referred</option>
                                                                </select>
                                                                <button class="rounded bg-blue-600 px-2 text-white font-bold">Save</button>
                                                            </form>
                                                            <?php else: ?>
                                                                <div class="admin-consultation-record-summary grid gap-2 rounded border border-slate-200 bg-white p-2 text-xs text-slate-700">
                                                                    <p><strong>Treatment / notes:</strong> <?php echo esc(($csl['treatment_plan'] ?? $csl['consultation_notes'] ?? '') ?: 'Not recorded'); ?></p>
                                                                    <p><strong>Assigned staff:</strong> <?php echo esc($assignedConsultationName); ?></p>
                                                                    <p><strong>Status:</strong> <?php echo esc($csl['consultation_status'] ?? 'Scheduled'); ?></p>
                                                                    <p class="font-semibold text-slate-500">View only. Only the assigned staff member or health worker may edit this record.</p>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbConsultationsList)): ?>
                                <div class="p-4 text-center text-gray-500">No consultation records found.</div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($consultationsByPatient)): ?>
                            <div data-consultation-pagination class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50 px-4 py-3 text-xs font-bold text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                                <span data-consultation-page-status></span>
                                <div class="flex items-center gap-2">
                                    <button type="button" data-consultation-page-prev class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-gray-700 shadow-sm disabled:cursor-not-allowed disabled:opacity-45 hover:bg-gray-100">Prev</button>
                                    <button type="button" data-consultation-page-next class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-gray-700 shadow-sm disabled:cursor-not-allowed disabled:opacity-45 hover:bg-gray-100">Next</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MATERNAL HEALTH TAB -->
            <?php if ($tab === 'maternal'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('baby', 'w-5 h-5 text-pink-600'); ?> Maternal Health Cases</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs bg-pink-100 text-pink-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbMaternalCases); ?> Cases</span>
                        </div>
                    </div>
                    <div class="maternal-inline-tools rounded-2xl border border-emerald-100 bg-white p-4 shadow-sm ring-1 ring-emerald-100">
                        <style>.maternal-inline-tools .admin-tool-panel { position: relative; inset: auto; width: 100%; box-shadow: none; }</style>
                        <div class="mb-3 flex items-center gap-2">
                            <?php echo iconSvg('baby', 'h-5 w-5 text-pink-600'); ?>
                            <div>
                                <h3 class="text-sm font-black text-gray-900">Maternal Health Tools</h3>
                                <p class="text-xs text-gray-500">Create pregnancy cases for female residents.</p>
                            </div>
                        </div>
                        <?php renderAdminExtendedPanel($pdo, 'maternal'); ?>
                    </div>
                    <?php
                    $maternalCasesByPatient = [];
                    foreach ($dbMaternalCases as $maternalRow) {
                        $patientName = trim(($maternalRow['first_name'] ?? '') . ' ' . ($maternalRow['last_name'] ?? '')) ?: 'Unknown patient';
                        $patientResidentId = (int)($maternalRow['resident_id'] ?? 0);
                        $patientKey = $patientResidentId > 0 ? 'resident-' . $patientResidentId : 'unlinked-' . (int)($maternalRow['id'] ?? 0);
                        if (!isset($maternalCasesByPatient[$patientKey])) {
                            $maternalCasesByPatient[$patientKey] = [
                                'name' => $patientName,
                                'resident_id' => $patientResidentId,
                                'barangay' => (string)($maternalRow['barangay'] ?? 'N/A'),
                                'records' => [],
                            ];
                        }
                        if (($maternalCasesByPatient[$patientKey]['barangay'] === '' || $maternalCasesByPatient[$patientKey]['barangay'] === 'N/A') && trim((string)($maternalRow['barangay'] ?? '')) !== '') {
                            $maternalCasesByPatient[$patientKey]['barangay'] = (string)$maternalRow['barangay'];
                        }
                        $maternalCasesByPatient[$patientKey]['records'][] = $maternalRow;
                    }
                    ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[850px] admin-maternal-patient-table">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Patient Name</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">Records</th>
                                        <th class="px-3 py-2.5 text-left">Latest Status</th>
                                        <th class="px-3 py-2.5 text-left">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($maternalCasesByPatient as $patientGroup): ?>
                                        <?php
                                        $maternalRecords = $patientGroup['records'];
                                        usort($maternalRecords, static function (array $a, array $b): int {
                                            return (strtotime((string)($b['created_at'] ?? $b['lmp'] ?? '')) ?: 0) <=> (strtotime((string)($a['created_at'] ?? $a['lmp'] ?? '')) ?: 0);
                                        });
                                        $latestMaternal = $maternalRecords[0] ?? [];
                                        ?>
                                        <tr class="admin-maternal-patient-row">
                                            <td class="px-3 py-3 font-black text-gray-900"><?php echo esc(rhuPatientLabel($patientGroup['name'], $patientGroup['resident_id'] ?? 0)); ?></td>
                                            <td class="px-3 py-3 font-semibold text-purple-900"><?php echo esc($patientGroup['barangay'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-3"><span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 font-black text-slate-900"><?php echo count($maternalRecords); ?></span></td>
                                            <td class="px-3 py-3"><span class="px-2 py-0.5 rounded-full bg-pink-100 text-pink-800 font-bold"><?php echo esc($latestMaternal['status'] ?? 'Active'); ?></span></td>
                                            <td class="px-3 py-3"><button type="button" class="text-[11px] font-black text-slate-700 hover:text-slate-950" data-maternal-records-toggle>View records</button></td>
                                        </tr>
                                        <tr class="admin-maternal-detail-row hidden">
                                            <td colspan="5" class="px-3 pb-4">
                                                <div class="admin-maternal-detail-wrap rounded-lg border border-slate-200 bg-slate-50 p-3">
                                                    <?php foreach ($maternalRecords as $mtl): ?>
                                                        <div class="admin-maternal-record-grid border-b border-slate-200 py-2 last:border-0">
                                                            <span class="font-mono font-bold text-gray-500">MTL-<?php echo (int)$mtl['id']; ?></span>
                                                            <span class="text-gray-600"><?php echo esc(date('M d, Y', strtotime($mtl['lmp'] ?? 'now'))); ?></span>
                                                            <span class="text-gray-600"><?php echo esc(date('M d, Y', strtotime($mtl['edc'] ?? 'now'))); ?></span>
                                                            <span class="font-bold"><?php echo esc($mtl['blood_type'] ?? 'N/A'); ?></span>
                                                            <span class="px-2 py-0.5 rounded-full bg-pink-100 text-pink-800 font-bold"><?php echo esc($mtl['status'] ?? 'Active'); ?></span>
                                                            <form method="post" class="flex flex-wrap items-center gap-2">
                                                                <input type="hidden" name="action" value="update_pregnancy">
                                                                <input type="hidden" name="pregnancy_id" value="<?php echo (int)$mtl['id']; ?>">
                                                                <select name="status" class="rounded border p-1">
                                                                    <option>Active</option>
                                                                    <option>Delivered</option>
                                                                    <option>Completed</option>
                                                                    <option>Referred</option>
                                                                </select>
                                                                <label class="flex items-center gap-1"><input type="checkbox" name="high_risk" <?php echo !empty($mtl['high_risk']) ? 'checked' : ''; ?>> High risk</label>
                                                                <button class="rounded bg-pink-600 px-2 text-white">Save</button>
                                                            </form>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbMaternalCases)): ?>
                                <div class="p-4 text-center text-gray-500">No maternal health cases found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- IMMUNIZATION TAB -->
            <?php if ($tab === 'vaccination'): ?>
                <?php
                $latestVaccinationsByResident = [];
                foreach ($dbVaccinationRecords as $vaccinationRecord) {
                    $residentKey = (int) $vaccinationRecord['resident_id'];
                    $currentRecord = $latestVaccinationsByResident[$residentKey] ?? null;
                    if (!$currentRecord
                        || [$vaccinationRecord['vaccination_date'], (int) $vaccinationRecord['id']]
                            > [$currentRecord['vaccination_date'], (int) $currentRecord['id']]) {
                        $latestVaccinationsByResident[$residentKey] = $vaccinationRecord;
                    }
                }
                $latestVaccinationRecords = array_values($latestVaccinationsByResident);
                usort($latestVaccinationRecords, static fn(array $left, array $right): int =>
                    [$right['vaccination_date'], (int) $right['id']] <=> [$left['vaccination_date'], (int) $left['id']]);
                ?>
                <?php renderAdminFloatingToolsModal($pdo, 'immunization-tools', 'Immunization Tools', 'Create and update vaccination records without leaving Immunization.', 'vaccination'); ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('shield', 'w-5 h-5 text-emerald-600'); ?> Immunization Records</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="immunization-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Immunization Tools</button>
                            <span class="text-xs bg-emerald-100 text-emerald-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($latestVaccinationRecords); ?> Residents</span>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-auto" style="max-height: 60vh;" tabindex="0" role="region" aria-label="Immunization records">
                            <table class="w-full text-xs min-w-[750px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase" style="position: sticky; top: 0; z-index: 1;">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Record ID</th>
                                        <th class="px-3 py-2.5 text-left">Beneficiary</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">Vaccine Name</th>
                                        <th class="px-3 py-2.5 text-left">Vaccination Date</th>
                                        <th class="px-3 py-2.5 text-left">Administered By</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($latestVaccinationRecords as $vcc): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">VCC-<?php echo $vcc['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(($vcc['first_name'] ?? '') . ' ' . ($vcc['last_name'] ?? '')); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($vcc['barangay'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc($vcc['vaccine_name'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($vcc['vaccination_date']))); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc(trim((string)($vcc['provider_name'] ?? '')) ?: 'Not recorded'); ?></td>
                                            <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-bold"><?php echo esc($vcc['status'] ?? 'Completed'); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbVaccinationRecords)): ?>
                                <div class="p-4 text-center text-gray-500">No vaccination records found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- DISEASE SURVEILLANCE TAB -->
            <?php if ($tab === 'disease'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'disease-tools', 'Disease Surveillance Tools', 'Create disease cases and update surveillance records without leaving the case list.', 'disease'); ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('alert', 'w-5 h-5 text-red-600'); ?> Disease Surveillance</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="disease-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Disease Surveillance Tools</button>
                            <span class="text-xs bg-red-100 text-red-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbDiseaseCases); ?> Cases</span>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-auto" style="max-height: 60vh;" tabindex="0" role="region" aria-label="Disease cases">
                            <table class="w-full text-xs min-w-[700px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase" style="position: sticky; top: 0; z-index: 1;">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Case ID</th>
                                        <th class="px-3 py-2.5 text-left">Disease</th>
                                        <th class="px-3 py-2.5 text-left">Patient</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">Case Date</th>
                                        <th class="px-3 py-2.5 text-left">Classification</th>
                                        <th class="px-3 py-2.5 text-left">Outcome</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbDiseaseCases as $dcs): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">DCS-<?php echo $dcs['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-red-700"><?php echo esc($dcs['disease_name'] ?? 'Unknown'); ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(($dcs['first_name'] ?? '') . ' ' . ($dcs['last_name'] ?? '')); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($dcs['barangay'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($dcs['case_date']))); ?></td>
                                            <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-800 font-bold"><?php echo esc($dcs['status'] ?? 'Pending'); ?></span></td>
                                            <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-800 font-bold"><?php echo esc($dcs['outcome'] ?? 'Pending'); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbDiseaseCases)): ?>
                                <div class="p-4 text-center text-gray-500">No disease cases found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MEDICINE INVENTORY TAB -->
            <?php if ($tab === 'medicine'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div><h2 class="text-xl font-extrabold text-slate-900">Medicine &amp; Supply Inventory</h2><p class="mt-1 text-xs text-slate-500">Manage items, receive stock, record usage, and review movement history here.</p></div>
                    <?php renderAdminExtendedPanel($pdo, 'medicine'); ?>
                </div>
            <?php endif; ?>

            <!-- VITAL STATISTICS TAB -->
            <?php if ($tab === 'vital'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('file', 'w-5 h-5 text-purple-600'); ?> Vital Statistics</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="vital-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Vital Record Tools</button>
                            <span class="text-xs bg-purple-100 text-purple-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbVitalStatistics); ?> Records</span>
                        </div>
                    </div>
                    <div data-floating-modal="vital-tools" class="fixed inset-0 z-[90] hidden bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6">
                        <div class="mx-auto mt-8 max-h-[86vh] w-full max-w-6xl overflow-y-auto rounded-2xl border border-white/70 bg-white p-4 shadow-2xl sm:p-5">
                            <div class="mb-4 flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
                                <div>
                                    <h3 class="text-base font-black text-gray-900">Vital Record Tools</h3>
                                    <p class="text-xs text-gray-500">Create birth and death records without leaving Vital Statistics.</p>
                                </div>
                                <button type="button" data-floating-modal-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close vital record tools">&times;</button>
                            </div>
                            <?php renderAdminExtendedPanel($pdo, 'vital'); ?>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[750px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Record ID</th>
                                        <th class="px-3 py-2.5 text-left">Type</th>
                                        <th class="px-3 py-2.5 text-left">Name</th>
                                        <th class="px-3 py-2.5 text-left">Location</th>
                                        <th class="px-3 py-2.5 text-left">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbVitalStatistics as $vital): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">VIT-<?php echo $vital['id']; ?></td>
                                            <td class="px-3 py-2.5">
                                                <span class="px-2 py-0.5 rounded-full font-bold <?php echo $vital['type'] === 'Birth' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                    <?php echo esc($vital['type']); ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc($vital['child_name'] ?? $vital['deceased_name'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($vital['location'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($vital['date_recorded']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbVitalStatistics)): ?>
                                <div class="p-4 text-center text-gray-500">No vital statistics records found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- AUDIT LOGS TAB -->

            <?php if ($tab === 'audit'): ?>
                <?php
                $auditTimezone = new DateTimeZone('Asia/Manila');
                $formatAuditTimestamp = static function ($rawTimestamp) use ($auditTimezone): string {
                    $rawTimestamp = trim((string)$rawTimestamp);
                    if ($rawTimestamp === '') return 'No timestamp';
                    try {
                        $timestamp = new DateTimeImmutable($rawTimestamp, $auditTimezone);
                        return $timestamp->setTimezone($auditTimezone)->format('M d, Y h:i A') . ' PHT';
                    } catch (Throwable $e) {
                        $fallback = strtotime($rawTimestamp);
                        return $fallback ? date('M d, Y h:i A', $fallback) . ' PHT' : $rawTimestamp;
                    }
                };
                ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base sm:text-xl font-black text-slate-900 flex items-center gap-2">
                            <span class="p-1.5 bg-slate-100 rounded-lg text-slate-700"><?php echo iconSvg('database', 'w-5 h-5'); ?></span>
                            System Audit Trail
                        </h2>
                        <span class="text-xs font-semibold text-slate-500 bg-slate-100 px-3 py-1 rounded-full border border-slate-200">50 Recent Logs</span>
                    </div>
                    <div class="bg-white rounded-2xl shadow-xs border border-slate-200/80 overflow-hidden">
                        <div class="max-h-[32rem] overflow-auto">
                            <table class="w-full text-xs min-w-[560px]">
                                <thead class="sticky top-0 z-10 bg-slate-800 text-slate-100 uppercase font-bold text-[11px] tracking-wider border-b border-slate-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left">ID</th>
                                        <th class="px-4 py-3 text-left">User</th>
                                        <th class="px-4 py-3 text-left">Action</th>
                                        <th class="px-4 py-3 text-left">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <?php foreach ($dbAuditLogsList as $log): 
                                        $userName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                                        if (!$userName) {
                                            $userName = !empty($log['user_id']) ? 'Health Worker #' . $log['user_id'] : 'Municipal Health Officer';
                                        }
                                    ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors">
                                            <td class="px-4 py-3 font-mono font-bold text-slate-400">#<?php echo $log['id']; ?></td>
                                            <td class="px-4 py-3 font-bold text-slate-900"><?php echo esc($userName); ?></td>
                                            <td class="px-4 py-3 text-purple-900 font-semibold"><?php echo esc($log['action']); ?></td>
                                            <td class="px-4 py-3 text-slate-500 font-mono text-[11px] whitespace-nowrap"><?php echo esc($formatAuditTimestamp($log['timestamp'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbAuditLogsList)): ?>
                                <div class="p-4 text-center text-xs font-semibold text-slate-500">No user audit logs found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- REPORTS & ANALYTICS TAB -->
            <?php if ($tab === 'reports'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('bar', 'w-5 h-5 text-purple-600'); ?> Database Analytics & Reports</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-report-modal-open class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Report Generator</button>
                        </div>
                    </div>

                    <div data-report-modal-backdrop class="fixed inset-0 z-[90] hidden bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6">
                        <div data-report-modal-panel class="mx-auto mt-8 max-h-[86vh] w-full max-w-5xl overflow-y-auto rounded-2xl border border-white/70 bg-white p-4 shadow-2xl sm:p-5">
                            <div class="mb-4 flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
                                <div>
                                    <h3 class="text-base font-black text-gray-900">DOH & Health Report Generator</h3>
                                    <p class="text-xs text-gray-500">Download the selected month using the FHSIS M1/M2 Excel template. Unsupported indicators remain blank; see Report Notes in the workbook.</p>
                                </div>
                                <button type="button" data-report-modal-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close report generator">&times;</button>
                            </div>
                            <?php renderFhsisReportForm(); ?>
                        </div>
                    </div>

                    <?php
                    $reportMonths = array_column($reportsMonthlySignals, 'label') ?: ['Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'];
                    $consultSeries = array_map('intval', array_column($reportsMonthlySignals, 'consultations')) ?: [2, 4, 5, 7, 8, 10];
                    $caseSeries = array_map('intval', array_column($reportsMonthlySignals, 'disease_cases')) ?: [1, 1, 2, 2, 3, 4];
                    $vaccineSeries = array_map('intval', array_column($reportsMonthlySignals, 'vaccinations')) ?: [3, 5, 7, 8, 10, 12];
                    // Fixed alpha for a transparent baseline with limited monthly history.
                    $smoothingAlpha = 0.3;
                    $smoothedConsultations = (float)($consultSeries[0] ?? 0);
                    $predictiveSeries = [];
                    foreach ($consultSeries as $value) {
                        // Record the forecast before observing this month's actual count.
                        $predictiveSeries[] = $smoothedConsultations;
                        $smoothedConsultations = ($smoothingAlpha * $value)
                            + ((1 - $smoothingAlpha) * $smoothedConsultations);
                    }
                    $nextPrediction = max(0, (int)round($smoothedConsultations));
                    $actualSeries = $consultSeries;
                    $forecastSeries = $predictiveSeries;
                    $chartMax = max(max($actualSeries ?: [0]), max($forecastSeries ?: [0]), max($caseSeries ?: [0]), max($vaccineSeries ?: [0]), 10);
                    $chartWidth = 560;
                    $chartHeight = 220;
                    $chartPadding = 26;
                    $chartInnerWidth = $chartWidth - ($chartPadding * 2);
                    $chartInnerHeight = $chartHeight - ($chartPadding * 2);

                    $pointList = function ($values) use ($chartPadding, $chartHeight, $chartInnerWidth, $chartInnerHeight, $chartMax) {
                        $points = [];
                        $count = count($values);
                        foreach ($values as $index => $value) {
                            $x = $chartPadding + ($chartInnerWidth * $index / max(1, $count - 1));
                            $y = $chartHeight - $chartPadding - (($value / $chartMax) * $chartInnerHeight);
                            $points[] = [$x, $y];
                        }
                        return $points;
                    };

                    $actualPoints = $pointList($actualSeries);
                    $forecastPoints = $pointList($forecastSeries);
                    $casePoints = $pointList($caseSeries);
                    $vaccinePoints = $pointList($vaccineSeries);
                    $actualPath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $actualPoints));
                    $forecastPath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $forecastPoints));
                    $casePath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $casePoints));
                    $vaccinePath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $vaccinePoints));
                    $maxMapCases = max(1, ...array_map(fn($row) => (int)($row['disease_cases'] ?? 0), $reportsMapStats ?: [['disease_cases' => 1]]));
                    $hotspot = $reportsMapStats[0] ?? ['barangay' => 'No barangay data', 'disease_cases' => 0, 'residents' => 0, 'recent_cases' => 0];
                    $staffPieTotal = max(1, array_sum(array_map(fn($row) => (int)$row['count'], $reportsStaffUserMix)));
                    $residentDistributionTotal = max(1, array_sum(array_map(fn($row) => (int)$row['count'], $barangayStats)));
                    $residentDistributionMax = max(1, ...array_map(fn($row) => (int)$row['count'], $barangayStats ?: [['count' => 1]]));
                    $staffTypeTotal = max(1, array_sum(array_map(fn($row) => (int)$row['count'], $staffTypeStats)));
                    $staffTypeMax = max(1, ...array_map(fn($row) => (int)$row['count'], $staffTypeStats ?: [['count' => 1]]));
                    $pieColors = ['#7c3aed', '#059669', '#0284c7', '#dc2626', '#f59e0b', '#475569', '#14b8a6'];
                    $pieOffset = 25;
                    ?>

                    <form method="get" class="grid gap-3 rounded-xl border border-gray-100 bg-white p-4 text-xs shadow-sm sm:grid-cols-[1fr_1fr_auto_auto]">
                        <input type="hidden" name="tab" value="reports">
                        <label class="font-bold text-gray-700">Barangay
                            <select name="analytics_barangay" class="mt-1 w-full rounded-lg border border-gray-300 p-2 font-normal">
                                <option value="">All Nasugbu barangays</option>
                                <?php foreach ($dbBarangayOptions as $barangayOption): ?>
                                    <option value="<?php echo esc($barangayOption['name']); ?>" <?php echo $reportsSelectedBarangay === $barangayOption['name'] ? 'selected' : ''; ?>><?php echo esc($barangayOption['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="font-bold text-gray-700">Disease
                            <select name="analytics_disease" class="mt-1 w-full rounded-lg border border-gray-300 p-2 font-normal">
                                <option value="">All disease cases</option>
                                <?php foreach ($reportsDiseaseOptions as $diseaseOption): ?>
                                    <option value="<?php echo esc($diseaseOption); ?>" <?php echo $reportsSelectedDisease === $diseaseOption ? 'selected' : ''; ?>><?php echo esc($diseaseOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="self-end rounded-lg bg-purple-700 px-4 py-2 font-bold text-white">Apply Filters</button>
                        <a href="?tab=reports" class="self-end rounded-lg border border-gray-300 px-4 py-2 text-center font-bold text-gray-700">Reset</a>
                    </form>

                    <div class="space-y-4">
                        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-5 shadow-sm">
                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                                        Nasugbu Barangay Health Map
                                        <span class="rounded bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700 border border-blue-100">Live Map</span>
                                    </h3>
                                    <p class="text-[11px] text-gray-500">Bubble size and color intensity follow filtered disease cases per barangay.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-red-50 px-3 py-1 text-[10px] font-black text-red-700">Most cases: <?php echo esc($hotspot['barangay']); ?></span>
                                    <button type="button" id="recenterNasugbuMapBtn" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors shadow-xs" title="Recenter on Nasugbu">
                                        <?php echo iconSvg('activity', 'w-3.5 h-3.5 shrink-0'); ?> Recenter
                                    </button>
                                </div>
                            </div>
                            <div id="nasugbuHealthMap" class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50 shadow-inner" data-max-cases="<?php echo (int)$maxMapCases; ?>"></div>
                            <div class="mt-3 grid gap-3 rounded-lg border border-slate-100 bg-slate-50/80 px-3 py-3 text-[11px] text-slate-600 lg:grid-cols-[1fr_auto] lg:items-center">
                                <div class="flex items-center gap-4">
                                    <span class="inline-flex items-center gap-1.5 font-semibold"><span class="h-3 w-3 rounded-full bg-emerald-500 border border-white shadow-xs"></span> Nasugbu Barangay</span>
                                    <span class="inline-flex items-center gap-1.5 font-semibold"><span class="h-3 w-3 rounded-full bg-red-500 border border-white shadow-xs"></span> Most Cases</span>
                                    <span class="font-semibold">Click a dot to show barangay data</span>
                                </div>
                                <span class="font-medium text-slate-500">Nasugbu Focus - Clutter Free</span>
                            </div>
                            <div id="nasugbuMapSelection" class="mt-3 rounded-lg border border-emerald-100 bg-white p-3 shadow-sm" aria-live="polite">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-[10px] font-black uppercase tracking-wide text-emerald-700">Selected Barangay</p>
                                        <h4 class="mt-0.5 text-sm font-black text-slate-900" data-map-selected-name>Click any map dot</h4>
                                    </div>
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700" data-map-selected-status>Ready</span>
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                    <div class="rounded-lg bg-slate-50 p-2">
                                        <p class="text-[10px] font-bold text-slate-500">Residents</p>
                                        <p class="text-base font-black text-slate-900" data-map-selected-residents>0</p>
                                    </div>
                                    <div class="rounded-lg bg-red-50 p-2">
                                        <p class="text-[10px] font-bold text-red-600">Disease Cases</p>
                                        <p class="text-base font-black text-red-800" data-map-selected-cases>0</p>
                                    </div>
                                    <div class="rounded-lg bg-amber-50 p-2">
                                        <p class="text-[10px] font-bold text-amber-600">Recent Cases</p>
                                        <p class="text-base font-black text-amber-800" data-map-selected-recent>0</p>
                                    </div>
                                </div>
                            </div>
                            <script type="application/json" id="nasugbuHealthMapData">
                                <?php echo json_encode($reportsMapStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>
                            </script>
                        </div>

                        <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-[11px] font-bold uppercase text-red-600">Barangay With Most Cases</p>
                                    <p class="mt-1 text-2xl font-black text-gray-900"><?php echo esc($hotspot['barangay']); ?></p>
                                </div>
                                <div class="grid w-full grid-cols-3 gap-2 text-xs sm:w-auto sm:min-w-[28rem]">
                                    <div class="rounded-lg bg-red-50 p-2">
                                        <p class="text-gray-500">Cases</p>
                                        <p class="font-black text-red-800"><?php echo (int)$hotspot['disease_cases']; ?></p>
                                    </div>
                                    <div class="rounded-lg bg-blue-50 p-2">
                                        <p class="text-gray-500">Residents</p>
                                        <p class="font-black text-blue-800"><?php echo (int)$hotspot['residents']; ?></p>
                                    </div>
                                    <div class="rounded-lg bg-amber-50 p-2">
                                        <p class="text-gray-500">30 Days</p>
                                        <p class="font-black text-amber-800"><?php echo (int)$hotspot['recent_cases']; ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 border-t border-gray-100 pt-4">
                                <h3 class="font-bold text-gray-900 text-sm">Disease Cases per Barangay</h3>
                                <div class="mt-3 grid gap-2 lg:grid-cols-2">
                                    <?php foreach ($reportsDiseaseByBarangay as $row): ?>
                                        <div class="text-xs">
                                            <div class="flex justify-between gap-2"><span class="font-semibold text-gray-700"><?php echo esc($row['barangay']); ?> - <?php echo esc($row['disease_name'] ?: 'Unknown'); ?></span><span class="font-black text-red-700"><?php echo (int)$row['cases']; ?></span></div>
                                            <div class="mt-1 h-1.5 rounded-full bg-gray-100">
                                                <div class="h-1.5 rounded-full bg-red-500" style="width: <?php echo min(100, ((int)$row['cases'] / $maxMapCases) * 100); ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$reportsDiseaseByBarangay): ?><p class="text-xs text-gray-500">No disease records match the selected filters.</p><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                            <div>
                                <h3 class="font-bold text-gray-900 text-sm">Consultation Forecast &amp; Service Trends</h3>
                                <p class="text-[11px] text-gray-500">Monthly service counts and consultation estimates using Simple Exponential Smoothing (SES)</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-[10px] font-bold">
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background-color: #10b981"></span>Consultations</span>
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background-color: #ef4444"></span>Cases</span>
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background-color: #3b82f6"></span>Vaccines</span>
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background-color: #f59e0b"></span>SES consultation estimates</span>
                            </div>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_16rem]">
                            <div class="rounded-xl border border-gray-100 bg-slate-50/70 p-3">
                                <svg viewBox="0 0 <?php echo $chartWidth; ?> <?php echo $chartHeight; ?>" class="h-64 w-full">
                                    <?php for ($i = 0; $i <= 4; $i++): ?>
                                        <?php $gridValue = ($chartMax / 4) * $i; ?>
                                        <?php $gridY = $chartHeight - $chartPadding - (($gridValue / $chartMax) * $chartInnerHeight); ?>
                                        <line x1="<?php echo $chartPadding; ?>" x2="<?php echo $chartWidth - $chartPadding; ?>" y1="<?php echo $gridY; ?>" y2="<?php echo $gridY; ?>" stroke="#e2e8f0" stroke-dasharray="4 4" />
                                        <text x="<?php echo $chartPadding - 8; ?>" y="<?php echo $gridY + 4; ?>" text-anchor="end" fill="#94a3b8" font-size="9"><?php echo number_format($gridValue, 0); ?></text>
                                    <?php endfor; ?>

                                    <polyline fill="none" stroke="#10b981" stroke-width="3" points="<?php echo $actualPath; ?>" stroke-linecap="round" stroke-linejoin="round" />
                                    <polyline fill="none" stroke="#ef4444" stroke-width="2.5" points="<?php echo $casePath; ?>" stroke-linecap="round" stroke-linejoin="round" />
                                    <polyline fill="none" stroke="#3b82f6" stroke-width="2.5" points="<?php echo $vaccinePath; ?>" stroke-linecap="round" stroke-linejoin="round" />
                                    <polyline fill="none" stroke="#f59e0b" stroke-width="3" stroke-dasharray="8 6" points="<?php echo $forecastPath; ?>" stroke-linecap="round" stroke-linejoin="round" />

                                    <?php foreach ($actualPoints as $index => $point): ?>
                                        <circle cx="<?php echo $point[0]; ?>" cy="<?php echo $point[1]; ?>" r="4" fill="#10b981" stroke="#ffffff" stroke-width="1.5" />
                                        <text x="<?php echo $point[0]; ?>" y="<?php echo $chartHeight - 8; ?>" text-anchor="middle" fill="#64748b" font-size="10"><?php echo esc($reportMonths[$index]); ?></text>
                                    <?php endforeach; ?>
                                </svg>
                            </div>
                            <div class="rounded-xl border border-amber-100 bg-amber-50 p-4">
                                <p class="text-[11px] font-black uppercase tracking-wide text-amber-700">Estimated consultations next month</p>
                                <p class="mt-2 text-4xl font-black text-amber-900"><?php echo $nextPrediction; ?></p>
                                <p class="mt-1 text-xs font-semibold text-amber-700">Simple Exponential Smoothing using monthly consultations (alpha = 0.3). Limited historical data; accuracy has not been validated.</p>
                                <div class="mt-4 space-y-2 text-xs">
                                    <div class="flex items-center justify-between rounded-lg bg-white/70 px-3 py-2">
                                        <span class="font-semibold text-gray-600">Latest Month</span>
                                        <span class="font-black text-gray-900"><?php echo esc(end($reportMonths) ?: 'Current'); ?></span>
                                    </div>
                                    <div class="flex items-center justify-between rounded-lg bg-white/70 px-3 py-2">
                                        <span class="font-semibold text-gray-600">Peak Scale</span>
                                        <span class="font-black text-gray-900"><?php echo (int)$chartMax; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3 text-xs lg:grid-cols-4">
                            <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-3">
                                <p class="font-semibold text-emerald-700">Consultations</p>
                                <p class="mt-1 text-2xl font-black text-emerald-900"><?php echo array_sum($consultSeries); ?></p>
                                <p class="text-[10px] font-bold text-emerald-600">6-month total</p>
                            </div>
                            <div class="rounded-xl border border-blue-100 bg-blue-50 p-3">
                                <p class="font-semibold text-blue-700">Vaccinations</p>
                                <p class="mt-1 text-2xl font-black text-blue-900"><?php echo array_sum($vaccineSeries); ?></p>
                                <p class="text-[10px] font-bold text-blue-600">6-month total</p>
                            </div>
                            <div class="rounded-xl border border-red-100 bg-red-50 p-3">
                                <p class="font-semibold text-red-700">Disease Cases</p>
                                <p class="mt-1 text-2xl font-black text-red-900"><?php echo array_sum($caseSeries); ?></p>
                                <p class="text-[10px] font-bold text-red-600">6-month total</p>
                            </div>
                            <div class="rounded-xl border border-amber-100 bg-amber-50 p-3">
                                <p class="font-semibold text-amber-700">Next Month Consultations</p>
                                <p class="mt-1 text-2xl font-black text-amber-900"><?php echo $nextPrediction; ?></p>
                                <p class="text-[10px] font-bold text-amber-600">SES estimate</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-[1.15fr_1.15fr_.9fr]">
                        <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                            <div class="mb-4 flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-gray-900 text-sm">Resident Distribution by Barangay</h3>
                                    <p class="mt-1 text-[11px] text-gray-500">Top barangays by resident records</p>
                                </div>
                                <span class="analytics-summary-badge rounded-lg px-3 py-1 text-xs font-black"><?php echo (int)$residentDistributionTotal; ?> total</span>
                            </div>
                            <div class="space-y-3">
                                <?php foreach (array_slice($barangayStats, 0, 8) as $b): ?>
                                    <?php $residentPct = min(100, ((int)$b['count'] / $residentDistributionMax) * 100); ?>
                                    <div class="text-xs">
                                        <div class="mb-1 flex items-center justify-between gap-3">
                                            <span class="min-w-0 truncate font-semibold text-gray-700"><?php echo esc($b['barangay'] ?: 'Nasugbu Poblacion'); ?></span>
                                            <span class="font-mono font-black text-blue-800"><?php echo (int)$b['count']; ?></span>
                                        </div>
                                        <div class="h-2 rounded-full bg-blue-50">
                                            <div class="h-2 rounded-full bg-blue-500" style="width: <?php echo number_format($residentPct, 2, '.', ''); ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                            <div class="mb-4 flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-gray-900 text-sm">Staff Breakdown by Position</h3>
                                    <p class="mt-1 text-[11px] text-gray-500">Current staff count by role</p>
                                </div>
                                <span class="analytics-summary-badge analytics-summary-badge--staff rounded-lg px-3 py-1 text-xs font-black"><?php echo (int)$staffTypeTotal; ?> staff</span>
                            </div>
                            <div class="space-y-3">
                                <?php foreach ($staffTypeStats as $st): ?>
                                    <?php $staffPct = min(100, ((int)$st['count'] / $staffTypeMax) * 100); ?>
                                    <div class="text-xs">
                                        <div class="mb-1 flex items-center justify-between gap-3">
                                            <span class="min-w-0 truncate font-semibold text-gray-700"><?php echo esc(str_replace('_', ' ', $st['staff_type'])); ?></span>
                                            <span class="font-mono font-black text-emerald-800"><?php echo (int)$st['count']; ?></span>
                                        </div>
                                        <div class="h-2 rounded-full bg-emerald-50">
                                            <div class="h-2 rounded-full bg-emerald-500" style="width: <?php echo number_format($staffPct, 2, '.', ''); ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                            <div class="mb-4 flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-gray-900 text-sm">Staff User Mix</h3>
                                    <p class="mt-1 text-[11px] text-gray-500">Share of active user roles</p>
                                </div>
                                <span class="analytics-summary-badge analytics-summary-badge--users rounded-lg px-3 py-1 text-xs font-black"><?php echo (int)$staffPieTotal; ?> users</span>
                            </div>
                            <div class="flex flex-col items-center gap-4 sm:flex-row xl:flex-col 2xl:flex-row">
                                <div class="relative">
                                    <svg viewBox="0 0 42 42" class="h-36 w-36 -rotate-90">
                                        <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#eef2f7" stroke-width="8"></circle>
                                        <?php foreach ($reportsStaffUserMix as $index => $slice): ?>
                                            <?php $pct = ((int)$slice['count'] / $staffPieTotal) * 100; ?>
                                            <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="<?php echo $pieColors[$index % count($pieColors)]; ?>" stroke-width="8" stroke-dasharray="<?php echo $pct; ?> <?php echo 100 - $pct; ?>" stroke-dashoffset="<?php echo $pieOffset; ?>"></circle>
                                            <?php $pieOffset -= $pct; ?>
                                        <?php endforeach; ?>
                                    </svg>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                                        <span class="text-2xl font-black text-gray-900"><?php echo (int)$staffPieTotal; ?></span>
                                        <span class="text-[10px] font-bold uppercase text-gray-500">Total</span>
                                    </div>
                                </div>
                                <div class="w-full min-w-0 flex-1 space-y-2 text-xs">
                                    <?php foreach ($reportsStaffUserMix as $index => $slice): ?>
                                        <?php $slicePct = ((int)$slice['count'] / $staffPieTotal) * 100; ?>
                                        <div class="rounded-lg border border-gray-100 px-2.5 py-2">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="min-w-0 truncate font-semibold text-gray-700"><span class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full" style="background: <?php echo $pieColors[$index % count($pieColors)]; ?>"></span><?php echo esc(str_replace('_', ' ', $slice['label'])); ?></span>
                                                <span class="font-black text-gray-900"><?php echo (int)$slice['count']; ?></span>
                                            </div>
                                            <p class="mt-0.5 text-[10px] font-bold text-gray-400"><?php echo number_format($slicePct, 0); ?>% of staff users</p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RE-DESIGNED SYSTEM SETTINGS TAB -->
            <?php if ($tab === 'system' || $tab === 'settings'): ?>
                <?php if (!empty($_SESSION['rhu_admin_authenticated'])): ?>
                    <details class="mb-3 border border-red-200 rounded-lg bg-white px-3 py-2">
                        <summary class="cursor-pointer font-bold text-red-700">Delete My Account</summary>
                        <form method="post" class="mt-3 max-w-md space-y-3" onsubmit="return confirm('Permanently delete your admin account and sign out?');">
                            <input type="hidden" name="action" value="delete_my_admin_account">
                            <input type="hidden" name="tab" value="settings">
                            <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                            <label class="block text-xs font-bold">Current Password
                                <input type="password" name="current_password" required autocomplete="current-password" class="mt-1 block w-full rounded border p-2">
                            </label>
                            <label class="block text-xs font-bold">Type DELETE to Confirm
                                <input name="delete_confirmation" required pattern="DELETE" autocomplete="off" class="mt-1 block w-full rounded border p-2">
                            </label>
                            <button type="submit" class="rounded-lg bg-red-700 px-4 py-2 text-xs font-bold text-white hover:bg-red-800">Delete My Account</button>
                        </form>
                    </details>
                <?php endif; ?>
                <?php renderAdminFloatingToolsModal($pdo, 'system-tools', 'System Maintenance Tools', 'Run maintenance, download backups, and restore database backups without leaving System Settings.', 'system'); ?>
                <div class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('settings', 'w-6 h-6 text-purple-700'); ?> System & Facility Settings</h2>
                            <p class="text-xs text-gray-500 mt-1">Configure health office profile, SMTP gateways, and system maintenance controls</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="system-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open System Tools</button>
                            <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full">XAMPP Environment</span>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4 items-start">
                        <!-- 1. RHU FACILITY PROFILE FORM -->
                        <div class="bg-white rounded-lg p-4 shadow-sm border border-gray-200 space-y-3">
                            <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('building', 'w-5 h-5'); ?></span>
                                <h3 class="font-bold text-gray-900 text-base">Facility & RHU Profile</h3>
                            </div>
                            <form method="post" class="space-y-4 text-xs">
                                <input type="hidden" name="action" value="update_rhu_info">
                                <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Facility Name</label>
                                    <input required name="rhu_name" value="<?php echo esc($rhuProfile['name']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Municipality</label>
                                        <input required name="municipality" value="<?php echo esc($rhuProfile['municipality']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Province</label>
                                        <input required name="province" value="<?php echo esc($rhuProfile['province']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Municipal Health Officer (MHO)</label>
                                    <input required name="mho_name" value="<?php echo esc($rhuProfile['mho_name']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Contact Telephone</label>
                                        <input required name="contact" value="<?php echo esc($rhuProfile['contact']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Official Email</label>
                                        <input required type="email" name="email" value="<?php echo esc($rhuProfile['email']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                </div>
                                <button type="submit" class="w-full py-2.5 bg-purple-700 text-white rounded-lg font-bold hover:bg-purple-800 transition-all shadow-md">
                                    Save Facility Settings
                                </button>
                            </form>
                        </div>

                        <!-- 2. SMTP MAIL GATEWAY & TEST SENDER -->
                            <div class="bg-white rounded-lg p-4 shadow-sm border border-gray-200 space-y-3">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-blue-100 rounded-lg text-blue-700"><?php echo iconSvg('mail', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">Email SMTP Gateway Configuration</h3>
                                </div>
                                <div class="space-y-3 text-xs">
                                    <div class="flex justify-between py-2 border-b border-gray-100">
                                        <span class="text-gray-500 font-medium">SMTP Server Host</span>
                                        <span class="font-mono font-bold text-gray-900"><?php echo esc(portalSetting($savedPortalSettings, 'smtp_host', 'Not configured') . ':' . portalSetting($savedPortalSettings, 'smtp_port', '587') . ' (' . strtoupper(portalSetting($savedPortalSettings, 'smtp_encryption', 'tls')) . ')'); ?></span>
                                    </div>
                                    <div class="flex justify-between py-2 border-b border-gray-100">
                                        <span class="text-gray-500 font-medium">Active Email Account</span>
                                        <span class="font-mono font-bold text-purple-800"><?php echo esc(portalSetting($savedPortalSettings, 'smtp_user', 'Not configured')); ?></span>
                                    </div>
                                    <div class="flex justify-between py-2 border-b border-gray-100">
                                        <span class="text-gray-500 font-medium">Authentication Status</span>
                                        <span class="px-2 py-0.5 rounded-full <?php echo portalSetting($savedPortalSettings, 'smtp_user') ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'; ?> font-bold"><?php echo portalSetting($savedPortalSettings, 'smtp_user') ? 'Configured' : 'Environment fallback'; ?></span>
                                    </div>
                                </div>

                                <form method="post" class="grid grid-cols-2 gap-2 pt-2 text-xs">
                                    <input type="hidden" name="action" value="update_smtp_settings">
                                    <input name="smtp_host" value="<?php echo esc(portalSetting($savedPortalSettings, 'smtp_host')); ?>" placeholder="SMTP host" class="rounded border p-2">
                                    <input type="number" name="smtp_port" value="<?php echo esc(portalSetting($savedPortalSettings, 'smtp_port', '587')); ?>" placeholder="Port" class="rounded border p-2">
                                    <input type="email" name="smtp_user" value="<?php echo esc(portalSetting($savedPortalSettings, 'smtp_user')); ?>" placeholder="SMTP account" class="rounded border p-2">
                                    <select name="smtp_encryption" class="rounded border p-2">
                                        <option value="tls">TLS</option>
                                        <option value="ssl">SSL</option>
                                    </select>
                                    <label class="col-span-2 flex items-center gap-2"><input type="checkbox" name="two_factor_enabled" <?php echo portalSetting($savedPortalSettings, 'two_factor_enabled', '1') === '1' ? 'checked' : ''; ?>> Require admin 2FA</label>
                                    <button class="col-span-2 rounded bg-slate-700 py-2 font-bold text-white">Save Mail &amp; 2FA Settings</button>
                                </form>

                                <form method="post" class="pt-2 space-y-3 text-xs">
                                    <input type="hidden" name="action" value="send_test_email">
                                    <label class="block font-bold text-gray-700">Dispatch Test Notification Email
                                        <div class="mt-1 flex gap-2">
                                            <input required type="email" name="test_email" value="<?php echo esc($rhuProfile['email']); ?>" class="flex-1 p-2 rounded border border-gray-300">
                                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded font-bold hover:bg-blue-700 whitespace-nowrap">Send Test Email</button>
                                        </div>
                                    </label>
                                </form>
                            </div>

                            <!-- 3. DATABASE MAINTENANCE & OPERATIONS -->
                            <div class="md:col-span-2 bg-white rounded-lg p-4 shadow-sm border border-gray-200 space-y-3">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-emerald-100 rounded-lg text-emerald-700"><?php echo iconSvg('database', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">Database Health & Maintenance</h3>
                                </div>
                                <div class="grid grid-cols-2 lg:grid-cols-3 gap-2 text-xs">
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Database Name</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo esc($databaseHealth['name']); ?></p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Connection Driver</p>
                                        <p class="font-mono font-bold text-green-700 mt-1">pdo MySQL</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">PHP Version</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo phpversion(); ?></p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Total System Users</p>
                                        <p class="font-mono font-bold text-purple-900 mt-1"><?php echo $totalUsersCount; ?> Accounts</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Database Server</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo esc($databaseHealth['version']); ?></p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Schema Tables</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo (int)$databaseHealth['tables']; ?> tables</p>
                                    </div>
                                </div>
                            </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ANNOUNCEMENTS & LANDING PAGE CONTENT TAB -->
            <?php if ($tab === 'announcements'): ?>
                <?php
                $dbAnnouncementsList = getPortalAnnouncements($pdo);
                $dbPortalEventsList = getPortalEvents($pdo);
                ?>
                <div class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('bell', 'w-6 h-6 text-purple-600'); ?> Landing Page Announcements & Health Events</h2>
                            <p class="text-xs text-gray-500 mt-1">Publish health alerts, emergency notices, and upcoming health drives directly to the public Landing Page.</p>
                        </div>
                        <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full">Live Landing Page Sync</span>
                    </div>

                    <style>
                        body.rhu-admin-ui .landing-content-grid {
                            display: grid;
                            grid-template-columns: repeat(2, minmax(0, 1fr));
                            gap: 24px 32px;
                            align-items: stretch;
                        }
                        body.rhu-admin-ui .landing-content-column { display: contents; }
                        body.rhu-admin-ui .landing-content-section {
                            min-width: 0;
                            margin: 0 !important;
                            padding: 20px 0 !important;
                            border: 0 !important;
                            border-top: 1px solid #dce5e3 !important;
                            border-radius: 0 !important;
                            background: transparent !important;
                            box-shadow: none !important;
                        }
                        .landing-content-column:first-child > .landing-content-section { grid-column: 1; }
                        .landing-content-column:last-child > .landing-content-section { grid-column: 2; }
                        .landing-content-editor { grid-row: 1; display: flex; flex-direction: column; }
                        .landing-content-published { grid-row: 2; }
                        .landing-content-editor > form { flex: 1; display: flex; flex-direction: column; gap: 12px; }
                        .landing-content-editor > form > * { margin-top: 0 !important; }
                        .landing-content-editor > form > button[type="submit"] { margin-top: auto !important; }
                        .landing-content-grid input, .landing-content-grid select, .landing-content-grid textarea { min-width: 0; max-width: 100%; }
                        .landing-content-grid input[type="file"] { overflow: hidden; }
                        body.rhu-admin-ui .landing-content-grid .landing-content-item {
                            display: grid;
                            grid-template-columns: minmax(0, 1fr) auto;
                            gap: 12px;
                            padding: 14px 0 !important;
                            border: 0 !important;
                            border-bottom: 1px solid #e2e8e5 !important;
                            border-radius: 0 !important;
                            background: transparent !important;
                            box-shadow: none !important;
                        }
                        .landing-content-item > div:first-child, .landing-content-item > div:first-child > div { min-width: 0; }
                        .landing-content-item h5, .landing-content-item p { overflow-wrap: anywhere; }
                        .landing-content-item details { margin-top: 6px; }
                        .landing-content-item summary { cursor: pointer; font-weight: 600; color: #52625a; }
                        .landing-content-item details p { line-height: 1.6; margin-top: 8px; }
                        @media (max-width: 1023px) {
                            body.rhu-admin-ui .landing-content-grid { grid-template-columns: minmax(0, 1fr); gap: 20px; }
                            .landing-content-column > .landing-content-section { grid-column: 1 !important; grid-row: auto; }
                        }
                        @media (max-width: 480px) {
                            body.rhu-admin-ui .landing-content-grid .landing-content-item { grid-template-columns: minmax(0, 1fr); }
                            .landing-content-item > form, .landing-content-item > div:last-child:not(:first-child) { justify-self: end; }
                        }
                    </style>
                    <div class="landing-content-grid">
                        <!-- 1. ANNOUNCEMENTS FORM & LIST -->
                        <div class="landing-content-column">
                            <div class="landing-content-section landing-content-editor space-y-4">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-red-100 rounded-lg text-red-700"><?php echo iconSvg('bell', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">New Announcement</h3>
                                </div>
                                <form method="post" enctype="multipart/form-data" class="space-y-3 text-xs">
                                    <input type="hidden" name="action" value="create_announcement">
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Announcement / Awareness Title *</label>
                                        <input required type="text" name="title" placeholder="e.g. Dengue Awareness Campaign & Clean-Up Drive" class="w-full p-2.5 rounded-lg border border-gray-300">
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Category</label>
                                            <select name="category" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                <option value="Health Awareness" selected>Health Awareness</option>
                                                <option value="Emergency Alert">Emergency Alert</option>
                                                <option value="Health Notice">Health Notice</option>
                                                <option value="Vaccine Schedule">Vaccine Schedule</option>
                                                <option value="General Announcement">General Announcement</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Badge Label</label>
                                            <input type="text" name="badge_text" value="Health Awareness" class="w-full p-2.5 rounded-lg border border-gray-300">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Awareness Image</label>
                                        <input type="file" name="announcement_image" accept="image/*" class="w-full p-2 rounded-lg border border-gray-300 bg-white text-xs">
                                        <p class="mt-1 text-[10px] font-semibold text-gray-500">Choose a new image to replace the current popup image. If left empty, the previous image will be kept.</p>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Announcement Details / Message *</label>
                                        <textarea required name="content" rows="3" placeholder="Write full awareness description..." class="w-full p-2.5 rounded-lg border border-gray-300"></textarea>
                                    </div>
                                    <div class="flex items-center gap-2 p-2.5 bg-purple-50 rounded-lg border border-purple-100">
                                        <input type="checkbox" id="is_popup" name="is_popup" value="1" checked class="rounded text-purple-600 focus:ring-purple-500">
                                        <label for="is_popup" class="font-bold text-purple-900 text-xs cursor-pointer select-none">
                                            Show as Landing Page Opening Screen Modal (1/8 Screen Awareness Banner)
                                        </label>
                                    </div>
                                    <button type="submit" class="w-full py-2.5 bg-purple-700 text-white rounded-lg font-bold hover:bg-purple-800 transition-all shadow-md">
                                        Publish Announcement
                                    </button>
                                </form>
                            </div>

                            <!-- ANNOUNCEMENTS LIST TABLE -->
                            <div class="landing-content-section landing-content-published space-y-3">
                                <h4 class="font-bold text-gray-900 text-sm">Published Announcements <span class="ml-2 text-gray-500"><?php echo count($dbAnnouncementsList); ?></span></h4>
                                <?php if (empty($dbAnnouncementsList)): ?>
                                    <p class="text-xs text-gray-400 italic py-2">No announcements stored yet. Standard default banner will display.</p>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($dbAnnouncementsList as $ann): ?>
                                            <div class="landing-content-item text-xs">
                                                <div class="flex items-start gap-3">
                                                    <?php if (!empty($ann['image_url'])): ?>
                                                        <img src="<?php echo esc(resolveImageUrl($ann['image_url'])); ?>" alt="Awareness" class="w-12 h-12 rounded-lg object-cover border border-gray-200 shrink-0" referrerpolicy="no-referrer">
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 font-bold text-[10px]"><?php echo esc($ann['badge_text'] ?: $ann['category']); ?></span>
                                                            <?php if (!empty($ann['is_popup'])): ?>
                                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-bold text-[10px]"><?php echo iconSvg('eye', 'w-3 h-3 shrink-0'); ?> 1/8 Screen Popup</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <h5 class="font-bold text-gray-900 mt-1"><?php echo esc($ann['title']); ?></h5>
                                                        <p class="text-gray-600 mt-0.5 line-clamp-2"><?php echo esc($ann['content']); ?></p>
                                                    </div>
                                                </div>
                                                <form method="post" onsubmit="return confirm('Remove this announcement from Landing Page?')">
                                                    <input type="hidden" name="action" value="delete_announcement">
                                                    <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                                    <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded" title="Delete Announcement">
                                                        <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 2. HEALTH EVENTS FORM & LIST -->
                        <div class="landing-content-column">
                            <div class="landing-content-section landing-content-editor space-y-4">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-emerald-100 rounded-lg text-emerald-700"><?php echo iconSvg('activity', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">New Health Event</h3>
                                </div>
                                <form method="post" enctype="multipart/form-data" class="space-y-3 text-xs">
                                    <input type="hidden" name="action" value="create_event">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Event Date *</label>
                                            <input required type="date" name="event_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white" aria-describedby="eventDateHelp">
                                            <p id="eventDateHelp" class="mt-1 text-[10px] font-normal text-gray-500">Choose the scheduled event date from the calendar.</p>
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Badge Color</label>
                                            <select name="badge_color" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                <option value="bg-red-500">Red (Emergency / Blood Drive)</option>
                                                <option value="bg-pink-500">Pink (Women & Maternal)</option>
                                                <option value="bg-emerald-500" selected>Green (Wellness & Senior)</option>
                                                <option value="bg-blue-500">Blue (Child & Vaccine)</option>
                                                <option value="bg-teal-500">Teal (Nutrition)</option>
                                                <option value="bg-purple-500">Purple (Family Planning)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Event Title *</label>
                                        <input required type="text" name="title" placeholder="e.g. Free Senior Citizens ECG & Glucose Check" class="w-full p-2.5 rounded-lg border border-gray-300">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Barangay / Event Location *</label>
                                        <select required name="barangay_id" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                            <option value="">-- Select Barangay from Database --</option>
                                            <?php foreach ($dbBarangayOptions as $barangayOption): ?>
                                                <option value="<?php echo (int)$barangayOption['id']; ?>"><?php echo esc($barangayOption['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (empty($dbBarangayOptions)): ?>
                                            <p class="mt-1 text-[10px] font-semibold text-red-600">No barangay records are available. Import the barangays table first.</p>
                                        <?php else: ?>
                                            <p class="mt-1 text-[10px] font-normal text-gray-500"><?php echo count($dbBarangayOptions); ?> barangay location(s) loaded from the database.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Event Description</label>
                                        <textarea name="description" rows="2" placeholder="Brief event details or free services offered..." class="w-full p-2.5 rounded-lg border border-gray-300"></textarea>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Event Cover Picture</label>
                                        <input type="file" name="event_image" accept="image/*" class="w-full p-2 rounded-lg border border-gray-300 bg-white text-xs">
                                    </div>
                                    <button type="submit" class="w-full py-2.5 bg-emerald-700 text-white rounded-lg font-bold hover:bg-emerald-800 transition-all shadow-md">
                                        Publish Event
                                    </button>
                                </form>
                            </div>

                            <!-- EVENTS LIST TABLE -->
                            <div class="landing-content-section landing-content-published space-y-3">
                                <h4 class="font-bold text-gray-900 text-sm">Published Health Events <span class="ml-2 text-gray-500"><?php echo count($dbPortalEventsList); ?></span></h4>
                                <?php if (empty($dbPortalEventsList)): ?>
                                    <p class="text-xs text-gray-400 italic py-2">No custom events stored yet. Default schedule will display.</p>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($dbPortalEventsList as $evt): ?>
                                            <div class="landing-content-item text-xs">
                                                <div class="flex items-start gap-3">
                                                    <?php if (!empty($evt['image_url'])): ?>
                                                        <div class="h-14 w-14 rounded-lg overflow-hidden shrink-0 bg-slate-200 border border-gray-200">
                                                            <img src="<?php echo esc(portalImgUrl($evt['image_url'])); ?>" alt="<?php echo esc($evt['title']); ?>" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=100&q=80';">
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <span class="px-2 py-0.5 rounded font-mono bg-gray-200 text-gray-800 font-bold text-[10px]"><?php echo esc($evt['event_date']); ?></span>
                                                        <h5 class="font-bold text-gray-900 mt-1"><?php echo esc($evt['title']); ?></h5>
                                                        <p class="text-xs font-semibold text-emerald-700"><?php echo esc($evt['venue']); ?></p>
                                                        <?php if (!empty($evt['description'])): ?>
                                                            <details>
                                                                <summary>Event Details</summary>
                                                                <p class="text-gray-500 whitespace-pre-wrap"><?php echo esc($evt['description']); ?></p>
                                                            </details>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-1 shrink-0">
                                                    <?php if (!empty($evt['image_url'])): ?>
                                                        <form method="post" onsubmit="return confirm('Delete picture attached to this event?')">
                                                            <input type="hidden" name="action" value="delete_event_picture">
                                                            <input type="hidden" name="event_id" value="<?php echo $evt['id']; ?>">
                                                            <button type="submit" class="px-2 py-1 bg-amber-50 text-amber-700 hover:bg-amber-100 rounded text-[10px] font-bold" title="Delete Cover Picture Only">
                                                                Remove Picture
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="post" onsubmit="return confirm('Remove this health event and its picture?')">
                                                        <input type="hidden" name="action" value="delete_event">
                                                        <input type="hidden" name="event_id" value="<?php echo $evt['id']; ?>">
                                                        <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded" title="Delete Event">
                                                            <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 3. MUNICIPAL OFFICIALS MANAGEMENT PANEL -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-6">
                        <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                            <div class="flex items-center gap-2">
                                <span class="p-2 bg-blue-100 rounded-lg text-blue-700"><?php echo iconSvg('building', 'w-5 h-5'); ?></span>
                                <div>
                                    <h3 class="font-bold text-gray-900 text-base">Municipal Government Officials</h3>
                                    <p class="text-xs text-gray-500">Manage LGU leaders displayed in the Municipal Leadership section of the Landing Page.</p>
                                </div>
                            </div>
                            <span class="text-xs font-bold bg-blue-50 text-blue-700 px-3 py-1 rounded-full">Stored in Portal Settings</span>
                        </div>

                        <?php
                        $pSettings = portalSettings($pdo);
                        $adminOfficialsList = [];
                        if (!empty($pSettings['rhu_municipal_officials'])) {
                            $adminOfficialsList = json_decode($pSettings['rhu_municipal_officials'], true) ?: [];
                        }
                        ?>

                        <div class="grid md:grid-cols-12 gap-6">
                            <!-- ADD OFFICIAL FORM -->
                            <form method="post" class="md:col-span-5 space-y-3 text-xs bg-gray-50 p-4 rounded-xl border border-gray-200">
                                <input type="hidden" name="action" value="add_official">
                                <h4 class="font-bold text-gray-900 text-sm">Add New Municipal Official</h4>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Full Name & Honorific *</label>
                                    <input required type="text" name="official_name" placeholder="e.g. Hon. Antonio Jose A. Barcelon" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Official Position *</label>
                                    <input required type="text" name="official_position" placeholder="e.g. Municipal Mayor" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Office / Committee Designation</label>
                                    <input type="text" name="official_office" placeholder="e.g. Office of the Municipal Mayor" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                </div>
                                <button type="submit" class="w-full py-2.5 bg-blue-700 text-white rounded-lg font-bold hover:bg-blue-800 transition-all shadow-md">
                                    Add Municipal Official
                                </button>
                            </form>

                            <!-- OFFICIALS LIST DISPLAY -->
                            <div class="md:col-span-7 space-y-3">
                                <h4 class="font-bold text-gray-900 text-sm">Current Officials Displayed on Landing Page</h4>
                                <?php if (empty($adminOfficialsList)): ?>
                                    <p class="text-xs text-gray-400 italic py-2">No officials specified yet.</p>
                                <?php else: ?>
                                    <div class="grid sm:grid-cols-2 gap-3 max-h-80 overflow-y-auto pr-1">
                                        <?php foreach ($adminOfficialsList as $idx => $off): ?>
                                            <div class="p-3 rounded-xl border border-gray-200 bg-white shadow-xs flex items-center justify-between gap-2 text-xs">
                                                <div>
                                                    <h5 class="font-bold text-gray-900"><?php echo esc($off['name']); ?></h5>
                                                    <p class="text-blue-700 font-semibold text-[11px]"><?php echo esc($off['position']); ?></p>
                                                    <p class="text-gray-400 text-[10px]"><?php echo esc($off['office']); ?></p>
                                                </div>
                                                <form method="post" onsubmit="return confirm('Remove <?php echo esc($off['name']); ?> from Landing Page?')">
                                                    <input type="hidden" name="action" value="delete_official">
                                                    <input type="hidden" name="official_index" value="<?php echo $idx; ?>">
                                                    <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded shrink-0" title="Remove Official">
                                                        <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 4. EVENT PHOTO GALLERY MANAGEMENT PANEL (Stored in portal_settings, no new DB table) -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-6">
                                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                        <div class="flex items-center gap-2">
                                            <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('eye', 'w-5 h-5'); ?></span>
                                            <div>
                                                <h3 class="font-bold text-gray-900 text-base">RHU Event Photo Gallery</h3>
                                                <p class="text-xs text-gray-500">Upload or add photo links to the Event & Community Photo Gallery section on the Landing Page.</p>
                                            </div>
                                        </div>
                                        <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full">Saved in Portal Settings</span>
                                    </div>

                                    <?php $eventGalleryList = getPortalEventGallery($pdo); ?>

                                    <div class="grid md:grid-cols-12 gap-6">
                                        <!-- ADD PHOTO FORM -->
                                        <form method="post" enctype="multipart/form-data" class="md:col-span-5 space-y-3 text-xs bg-gray-50 p-4 rounded-xl border border-gray-200">
                                            <input type="hidden" name="action" value="add_gallery_photo">
                                            <h4 class="font-bold text-gray-900 text-sm">Add Event Gallery Photo</h4>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Photo Title *</label>
                                                <input required type="text" name="photo_title" placeholder="e.g. Free Dental Mission & Medical Checkup" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                            </div>
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Category</label>
                                                    <input type="text" name="photo_category" value="Community Health Drive" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                </div>
                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Date</label>
                                                    <input type="text" name="photo_date" value="<?php echo date('M Y'); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Image File Upload or Image URL *</label>
                                                <input type="file" name="gallery_file" accept="image/*" class="w-full p-1 rounded border border-gray-300 text-[11px] mb-2 bg-white">
                                                <input type="text" name="image_url" placeholder="Or paste Image URL (e.g. https://...)" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                            </div>
                                            <button type="submit" class="w-full py-2.5 bg-purple-700 text-white rounded-lg font-bold hover:bg-purple-800 transition-all shadow-md">
                                                Add Photo to Landing Page Gallery
                                            </button>
                                        </form>

                                        <!-- GALLERY DISPLAY GRID -->
                                        <div class="md:col-span-7 space-y-3">
                                            <h4 class="font-bold text-gray-900 text-sm">Current Landing Page Gallery Photos</h4>
                                            <?php if (empty($eventGalleryList)): ?>
                                                <p class="text-xs text-gray-400 italic py-2">No photos in gallery yet.</p>
                                            <?php else: ?>
                                                <div class="grid sm:grid-cols-2 gap-3 max-h-96 overflow-y-auto pr-1">
                                                    <?php foreach ($eventGalleryList as $gIdx => $gItem): ?>
                                                        <div class="p-3 rounded-xl border border-gray-200 bg-white shadow-xs space-y-2 text-xs relative group">
                                                            <div class="h-28 w-full rounded-lg overflow-hidden bg-slate-100 border border-slate-100">
                                                                <img src="<?php echo esc(portalImgUrl($gItem['image_url'])); ?>" alt="<?php echo esc($gItem['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=400&q=80';">
                                                            </div>
                                                            <div class="flex items-start justify-between gap-2">
                                                                <div>
                                                                    <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 font-bold text-[10px]"><?php echo esc($gItem['category'] ?? 'Event Photo'); ?></span>
                                                                    <h5 class="font-bold text-gray-900 mt-1 truncate max-w-[160px]"><?php echo esc($gItem['title']); ?></h5>
                                                                    <p class="text-[10px] text-gray-400"><?php echo esc($gItem['date'] ?? ''); ?></p>
                                                                </div>
                                                                <form method="post" onsubmit="return confirm('Remove photo from gallery?')">
                                                                    <input type="hidden" name="action" value="delete_gallery_photo">
                                                                    <input type="hidden" name="photo_index" value="<?php echo $gIdx; ?>">
                                                                    <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded shrink-0" title="Delete Photo">
                                                                        <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                        <?php endif; ?>

                        <!-- RE-DESIGNED SECURITY TAB -->
                        <?php if ($tab === 'security'): ?>
                            <?php renderAdminFloatingToolsModal($pdo, 'security-tools', 'Security Permission Tools', 'Manage roles, permissions, and access rules without leaving Security.', 'security'); ?>
                            <div class="space-y-6">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-4">
                                    <div>
                                        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('lock', 'w-6 h-6 text-red-600'); ?> Security Command Center</h2>
                                        <p class="text-xs text-gray-500 mt-1">Manage Admin Credentials, 2-Factor Authentication, Role-Based Access Control, and Audit Security Logs</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" data-floating-modal-open="security-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Security Tools</button>
                                        <span class="text-xs font-bold bg-red-100 text-red-800 px-3 py-1 rounded-full">RA 10173 Compliant</span>
                                    </div>
                                </div>

                                <div class="grid md:grid-cols-2 gap-6">
                                    <!-- 1. CHANGE ADMIN PASSWORD FORM -->
                                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                        <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                            <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('key', 'w-5 h-5'); ?></span>
                                            <h3 class="font-bold text-gray-900 text-base">Change Administrator Password</h3>
                                        </div>
                                        <form method="post" class="space-y-4 text-xs">
                                            <input type="hidden" name="action" value="change_admin_password">
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Current Password *</label>
                                                <input required type="password" name="current_password" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none" placeholder="Enter current admin password">
                                            </div>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">New Password *</label>
                                                <input required type="password" name="new_password" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none" placeholder="Min 8 characters">
                                            </div>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Confirm New Password *</label>
                                                <input required type="password" name="confirm_password" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none" placeholder="Re-type new password">
                                            </div>
                                            <button type="submit" class="w-full py-2.5 bg-red-600 text-white rounded-lg font-bold hover:bg-red-700 transition-all shadow-md">
                                                Update Admin Password in Database
                                            </button>
                                        </form>
                                    </div>

                                    <!-- 2. 2FA ENFORCEMENT & SECURITY STATUS -->
                                    <div class="space-y-6">
                                        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                            <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                                <span class="p-2 bg-emerald-100 rounded-lg text-emerald-700"><?php echo iconSvg('shield', 'w-5 h-5'); ?></span>
                                                <h3 class="font-bold text-gray-900 text-base">2-Factor Authentication (2FA) Status</h3>
                                            </div>
                                            <div class="space-y-3 text-xs">
                                                <div class="p-4 bg-purple-50 rounded-xl border border-purple-200">
                                                    <div class="flex items-center justify-between">
                                                        <span class="font-bold text-purple-900">2FA Enforcement</span>
                                                        <span class="px-2.5 py-1 bg-purple-700 text-white font-bold rounded-full text-[10px]"><?php echo portalSetting($savedPortalSettings, 'two_factor_enabled', '1') === '1' ? 'ACTIVE' : 'DISABLED'; ?></span>
                                                    </div>
                                                    <p class="text-purple-800 mt-2">All login attempts require a unique 6-digit OTP code sent directly to your email address.</p>
                                                </div>
                                                <div class="flex justify-between py-2 border-b border-gray-100">
                                                    <span class="text-gray-500 font-medium">Registered 2FA Email</span>
                                                    <span class="font-mono font-bold text-gray-900"><?php echo esc($_SESSION['user']['email'] ?? 'Not available'); ?></span>
                                                </div>
                                                <div class="flex justify-between py-2 border-b border-gray-100">
                                                    <span class="text-gray-500 font-medium">Code Validity Window</span>
                                                    <span class="font-mono font-bold text-gray-900">10 Minutes</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 3. ROLE-BASED ACCESS CONTROL (RBAC) MATRIX -->
                                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                        <div class="flex items-center gap-2">
                                            <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('users', 'w-5 h-5'); ?></span>
                                            <h3 class="font-bold text-gray-900 text-base">Role-Based Module Permissions Matrix</h3>
                                        </div>
                                        <span class="text-xs bg-gray-100 text-gray-700 px-3 py-1 rounded-full font-semibold">6 System Roles</span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <thead class="bg-gray-50 text-gray-500 uppercase">
                                                <tr>
                                                    <th class="px-3 py-2 text-left">Role Name</th>
                                                    <th class="px-3 py-2 text-left">Access Level</th>
                                                    <th class="px-3 py-2 text-left">Accessible Modules</th>
                                                    <th class="px-3 py-2 text-left">Active Users in DB</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-purple-900">RHU_ADMIN</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-purple-100 text-purple-800 font-bold rounded-full">Full System Control</span></td>
                                                    <td class="px-3 py-3 text-gray-700">All Modules, User Management, Staff Accounts, Audit Logs, Settings</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900">1 Account</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-blue-900">PHYSICIAN</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-blue-100 text-blue-800 font-bold rounded-full">Clinical Lead</span></td>
                                                    <td class="px-3 py-3 text-gray-700">OPD Consultations, ICD-10 Prescriptions, Patient Medical Records</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900">Plantilla Doctors</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-emerald-900">NURSE / MIDWIFE</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-bold rounded-full">Healthcare Operations</span></td>
                                                    <td class="px-3 py-3 text-gray-700">Maternal Health, Immunization (EPI), Triage, Vital Signs</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900">Clinical Staff</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-teal-900">BHW</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-teal-100 text-teal-800 font-bold rounded-full">Community Health</span></td>
                                                    <td class="px-3 py-3 text-gray-700">Barangay Resident Registry, Health Event Verification</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900"><?php echo $totalBhwCount; ?> BHWs</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-gray-900">RESIDENT</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-gray-100 text-gray-800 font-bold rounded-full">Self-Service Portal</span></td>
                                                    <td class="px-3 py-3 text-gray-700">Request Medical Certificates, Send Inquiries, Event Registration</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900"><?php echo $totalResidentsCount; ?> Residents</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
        </main>
    </div>
    <script>
        window.rhuAdminLiveRefreshCurrentTab = window.rhuAdminLiveRefreshCurrentTab || (() => {
            let lastSignature = '';
            let inFlight = false;
            const parser = new DOMParser();
            const signatureFor = (element) => {
                const text = (element?.textContent || '').replace(/\s+/g, ' ').trim();
                let hash = 0;
                for (let index = 0; index < text.length; index += 1) {
                    hash = ((hash << 5) - hash + text.charCodeAt(index)) | 0;
                }
                return `${text.length}:${hash}`;
            };
            const adminIsBusy = () => {
                const active = document.activeElement;
                if (active?.matches?.('input, textarea, select, [contenteditable="true"]')) return true;
                if (document.querySelector('.admin-main input[type="checkbox"]:checked')) return true;
                if (document.querySelector('[data-report-modal-backdrop]:not(.hidden), [data-floating-modal]:not(.hidden), [data-certificate-preview-modal]:not(.hidden)')) return true;
                return false;
            };
            const syncCsrfFields = () => {
                document.querySelectorAll('form[method="post"], form[method="POST"]').forEach((form) => {
                    if (form.querySelector('input[name="csrf_token"]')) return;
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'csrf_token';
                    input.value = <?php echo json_encode(portalCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    form.prepend(input);
                });
            };
            const refreshCurrentTab = async () => {
                if (inFlight || adminIsBusy()) return;
                const currentMain = document.querySelector('.admin-main');
                if (!currentMain) return;
                inFlight = true;
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('live_admin_refresh', String(Date.now()));
                    const response = await fetch(url.toString(), {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    if (response.status === 401 || response.redirected) {
                        window.location.assign('RHUAdminLogin.php');
                        return;
                    }
                    if (!response.ok) return;
                    const nextDocument = parser.parseFromString(await response.text(), 'text/html');
                    const nextMain = nextDocument.querySelector('.admin-main');
                    if (!nextMain) return;
                    const nextSignature = signatureFor(nextMain);
                    if (!lastSignature) {
                        lastSignature = signatureFor(currentMain);
                    }
                    if (nextSignature && nextSignature !== lastSignature && !adminIsBusy()) {
                        const scrollTop = currentMain.scrollTop;
                        currentMain.replaceWith(nextMain);
                        nextMain.scrollTop = scrollTop;
                        lastSignature = nextSignature;
                        syncCsrfFields();
                        document.dispatchEvent(new CustomEvent('rhu-admin-live-refresh', {
                            detail: {
                                main: nextMain
                            }
                        }));
                    }
                } catch (error) {
                    console.error('Failed to live refresh admin dashboard:', error);
                } finally {
                    inFlight = false;
                }
            };
            const start = () => {
                syncCsrfFields();
                const main = document.querySelector('.admin-main');
                if (main) lastSignature = signatureFor(main);
                // Replacing the main panel drops handlers and resets active dashboard controls.
                // Keep full-panel refresh explicit; messages have a separate live updater.
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', start, {
                    once: true
                });
            } else {
                start();
            }
            return refreshCurrentTab;
        })();

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-certificate-approval-form]')) return;
            if (form.dataset.sending === '1') {
                event.preventDefault();
                return;
            }
            const button = form.querySelector('button[type="submit"]');
            const result = form.querySelector('[data-certificate-approval-result]');
            form.dataset.sending = '1';
            button.textContent = 'Sending...';
            result.classList.remove('hidden');
            result.textContent = 'Sending approval emails...';
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (!form.matches('form[method="post"], form[method="POST"]')) return;
            if (form.querySelector('input[name="csrf_token"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = <?php echo json_encode(portalCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            form.prepend(input);
        }, true);

        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach((form) => {
            if (form.querySelector('input[name="csrf_token"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = <?php echo json_encode(portalCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            form.prepend(input);
        });

        let lockedDashboardScrollY = 0;
        const hasOpenDashboardOverlay = () => Boolean(document.querySelector('[data-report-modal-backdrop]:not(.hidden), [data-floating-modal]:not(.hidden), [data-certificate-preview-modal]:not(.hidden)'));
        const lockDashboardBackground = () => {
            if (document.body.dataset.dashboardScrollLocked === '1') return;
            lockedDashboardScrollY = window.scrollY || document.documentElement.scrollTop || 0;
            document.body.dataset.dashboardScrollLocked = '1';
            document.body.style.position = 'fixed';
            document.body.style.top = `-${lockedDashboardScrollY}px`;
            document.body.style.left = '0';
            document.body.style.right = '0';
            document.body.style.width = '100%';
            document.body.classList.add('overflow-hidden');
            document.documentElement.classList.add('overflow-hidden');
        };
        const unlockDashboardBackground = () => {
            if (hasOpenDashboardOverlay()) return;
            if (document.body.dataset.dashboardScrollLocked !== '1') return;
            document.body.dataset.dashboardScrollLocked = '0';
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.left = '';
            document.body.style.right = '';
            document.body.style.width = '';
            document.body.classList.remove('overflow-hidden');
            document.documentElement.classList.remove('overflow-hidden');
            window.scrollTo(0, lockedDashboardScrollY);
        };
        const stopDashboardOverlayScroll = (event) => {
            if (document.body.dataset.dashboardScrollLocked !== '1') return;
            if (!hasOpenDashboardOverlay()) return;
            event.preventDefault();
            event.stopPropagation();
        };
        window.addEventListener('wheel', stopDashboardOverlayScroll, {
            passive: false,
            capture: true
        });
        window.addEventListener('touchmove', stopDashboardOverlayScroll, {
            passive: false,
            capture: true
        });

        const openFloatingModal = (modal) => {
            if (!modal) return;
            // Escape transformed/contained dashboard panels so this dialog uses the viewport.
            if (modal.dataset.floatingModal === 'account-verification' && modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            lockDashboardBackground();
            modal.classList.remove('hidden');
            window.setTimeout(() => modal.querySelector('[data-floating-modal-close], [data-report-modal-close]')?.focus(), 60);
        };
        const closeFloatingModal = (modal) => {
            if (!modal) return;
            modal.classList.add('hidden');
            unlockDashboardBackground();
        };
        document.querySelector('[data-report-modal-open]')?.addEventListener('click', () => {
            openFloatingModal(document.querySelector('[data-report-modal-backdrop]'));
        });
        document.querySelectorAll('[data-floating-modal-open]').forEach((button) => {
            button.addEventListener('click', () => {
                openFloatingModal(document.querySelector(`[data-floating-modal="${button.dataset.floatingModalOpen}"]`));
            });
        });
        document.querySelectorAll('[data-report-modal-close], [data-floating-modal-close]').forEach((button) => {
            button.addEventListener('click', () => closeFloatingModal(button.closest('[data-report-modal-backdrop], [data-floating-modal]')));
        });
        document.querySelectorAll('[data-report-modal-backdrop], [data-floating-modal]').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeFloatingModal(modal);
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('[data-report-modal-backdrop]:not(.hidden), [data-floating-modal]:not(.hidden)').forEach(closeFloatingModal);
        });

        const initializeNasugbuHealthMap = () => {
            const mapElement = document.getElementById('nasugbuHealthMap');
            const dataElement = document.getElementById('nasugbuHealthMapData');
            if (!mapElement || !dataElement || typeof L === 'undefined') return;
            if (mapElement.dataset.initialized === '1') return;
            mapElement.dataset.initialized = '1';

            let barangays = [];
            try {
                barangays = JSON.parse(dataElement.textContent || '[]');
            } catch (error) {
                barangays = [];
            }

            // Accurate coordinates map for Nasugbu, Batangas barangays
            const nasugbuBarangayCoords = {
                'Aga': [14.0980, 120.7420],
                'Balaytiguebalok-Balok': [14.1250, 120.6080],
                'Balaytigue': [14.1250, 120.6080],
                'Balok-Balok': [14.1200, 120.6100],
                'Banilad': [14.0320, 120.6980],
                'Barangay 1 (Pob.)': [14.0720, 120.6320],
                'Barangay 2 (Pob.)': [14.0725, 120.6305],
                'Barangay 3 (Pob.)': [14.0710, 120.6315],
                'Barangay 4 (Pob.)': [14.0700, 120.6330],
                'Barangay 5 (Pob.)': [14.0735, 120.6295],
                'Barangay 6 (Pob.)': [14.0740, 120.6325],
                'Barangay 7 (Pob.)': [14.0690, 120.6340],
                'Barangay 8 (Pob.)': [14.0750, 120.6310],
                'Barangay 9 (Pob.)': [14.0760, 120.6335],
                'Barangay 10 (Pob.)': [14.0680, 120.6300],
                'Barangay 11 (Pob.)': [14.0770, 120.6350],
                'Barangay 12 (Pob.)': [14.0670, 120.6360],
                'Bilaran': [14.0520, 120.6550],
                'Bucana': [14.0840, 120.6320],
                'Bulihan': [14.1120, 120.6380],
                'Bunducan': [14.0480, 120.6400],
                'Butucan': [14.0380, 120.6500],
                'Calayo': [14.1850, 120.6120],
                'Catandaan': [14.0280, 120.6650],
                'Cogunan': [14.0200, 120.6750],
                'Dayap': [14.0150, 120.6850],
                'Kaylaway': [14.0910, 120.7520],
                'Kayrillaw': [14.1020, 120.7600],
                'Latag': [14.0620, 120.6920],
                'Looc': [14.1550, 120.6180],
                'Lumbangan': [14.0450, 120.6850],
                'Malapad na Bato': [14.0880, 120.6700],
                'Mataas na Pulo': [14.0950, 120.6600],
                'Maugat': [14.1050, 120.6500],
                'Munting Indang': [14.0580, 120.7100],
                'Natipuan': [14.1180, 120.6150],
                'Pantalan': [14.0400, 120.6320],
                'Papaya': [14.1980, 120.5980],
                'Putat': [14.0650, 120.6650],
                'Reparo': [14.0350, 120.7150],
                'Talangan': [14.0250, 120.7250],
                'Tumalim': [14.0750, 120.6780],
                'Utod': [14.0320, 120.6480],
                'Wawa': [14.0780, 120.6230]
            };

            const defaultNasugbuCenter = [14.0722, 120.6310]; // Center of Nasugbu, Batangas
            const nasugbuFocusBounds = L.latLngBounds(
                [13.9950, 120.5450],
                [14.2150, 120.7850]
            );

            const map = L.map(mapElement, {
                center: defaultNasugbuCenter,
                zoom: 13,
                minZoom: 12,
                maxZoom: 18,
                maxBounds: nasugbuFocusBounds.pad(0.08),
                maxBoundsViscosity: 1,
                scrollWheelZoom: false,
                zoomControl: true
            });

            const baseLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
                tileSize: 256,
                zoomOffset: 0,
                noWrap: false,
                crossOrigin: true
            }).addTo(map);
            baseLayer.on('load', () => {
                map.invalidateSize({
                    animate: false,
                    pan: false
                });
            });
            L.rectangle(nasugbuFocusBounds, {
                color: '#7c3aed',
                weight: 2,
                dashArray: '8 6',
                fillColor: '#7c3aed',
                fillOpacity: 0.04,
                interactive: false
            }).addTo(map);

            const markerGroup = L.featureGroup().addTo(map);

            const escapeMapText = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
            const formatMapNumber = (value) => Number(value || 0).toLocaleString();
            const selectedPanel = document.getElementById('nasugbuMapSelection');
            const selectedName = selectedPanel?.querySelector('[data-map-selected-name]');
            const selectedStatus = selectedPanel?.querySelector('[data-map-selected-status]');
            const selectedResidents = selectedPanel?.querySelector('[data-map-selected-residents]');
            const selectedCases = selectedPanel?.querySelector('[data-map-selected-cases]');
            const selectedRecent = selectedPanel?.querySelector('[data-map-selected-recent]');
            const floatingInfo = document.createElement('div');
            floatingInfo.className = 'nasugbu-map-floating-info';
            floatingInfo.setAttribute('aria-live', 'polite');
            mapElement.appendChild(floatingInfo);
            let activeMapSelection = null;
            const updateSelectedBarangay = (name, barangay, cases, isHotspot) => {
                if (!selectedPanel) return;
                selectedName.textContent = name || 'Barangay';
                selectedStatus.textContent = isHotspot ? 'Most cases' : 'Selected';
                selectedStatus.className = isHotspot ?
                    'rounded-full bg-red-50 px-2.5 py-1 text-[10px] font-bold text-red-700' :
                    'rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700';
                selectedResidents.textContent = formatMapNumber(barangay.residents);
                selectedCases.textContent = formatMapNumber(cases);
                selectedRecent.textContent = formatMapNumber(barangay.recent_cases);
            };
            const positionFloatingInfo = () => {
                if (!activeMapSelection || !floatingInfo.classList.contains('is-open')) return;
                const point = map.latLngToContainerPoint(activeMapSelection.latLng);
                const mapWidth = mapElement.clientWidth;
                const mapHeight = mapElement.clientHeight;
                const cardWidth = floatingInfo.offsetWidth || 280;
                const cardHeight = floatingInfo.offsetHeight || 120;
                let left = point.x + 34;
                let top = point.y - (cardHeight / 2);
                if (left + cardWidth > mapWidth - 12) {
                    left = point.x - cardWidth - 34;
                    floatingInfo.classList.add('is-left');
                } else {
                    floatingInfo.classList.remove('is-left');
                }
                top = Math.max(12, Math.min(mapHeight - cardHeight - 12, top));
                floatingInfo.style.left = `${Math.max(12, left)}px`;
                floatingInfo.style.top = `${top}px`;
            };
            const showFloatingInfo = (name, barangay, cases, isHotspot, latLng) => {
                activeMapSelection = { latLng };
                floatingInfo.innerHTML = `
                    <button type="button" class="nasugbu-map-floating-info__close" aria-label="Close barangay details">&times;</button>
                    <div class="nasugbu-map-popup">
                        <h4 class="nasugbu-map-popup__title">${escapeMapText(name || 'Barangay')}</h4>
                        <div class="nasugbu-map-popup__stats">
                            <div class="nasugbu-map-popup__stat"><span class="nasugbu-map-popup__label">Residents</span><span class="nasugbu-map-popup__value">${formatMapNumber(barangay.residents)}</span></div>
                            <div class="nasugbu-map-popup__stat"><span class="nasugbu-map-popup__label">Cases</span><span class="nasugbu-map-popup__value">${formatMapNumber(cases)}</span></div>
                            <div class="nasugbu-map-popup__stat"><span class="nasugbu-map-popup__label">30 Days</span><span class="nasugbu-map-popup__value">${formatMapNumber(barangay.recent_cases)}</span></div>
                        </div>
                    </div>
                `;
                floatingInfo.classList.add('is-open');
                floatingInfo.querySelector('.nasugbu-map-floating-info__close')?.addEventListener('click', () => {
                    floatingInfo.classList.remove('is-open');
                    activeMapSelection = null;
                });
                window.requestAnimationFrame(positionFloatingInfo);
            };

            const barangayStatsByName = new Map(
                barangays.map((barangay) => [String(barangay.barangay || '').trim(), barangay])
            );
            const hotspotBarangayName = barangays.reduce((hotspotName, barangay) => {
                const currentCases = Number(barangay.disease_cases || 0);
                const hotspotCases = Number(barangayStatsByName.get(hotspotName)?.disease_cases || 0);
                return currentCases > hotspotCases ? String(barangay.barangay || '').trim() : hotspotName;
            }, '');

            Object.entries(nasugbuBarangayCoords).forEach(([name, coords]) => {
                const [lat, lng] = coords;
                const barangay = barangayStatsByName.get(name) || {};
                const cases = Number(barangay.disease_cases || 0);
                const isHotspot = name === hotspotBarangayName && cases > 0;
                const markerSize = 44;
                const iconAnchor = markerSize / 2;
                const markerLatLng = L.latLng(lat, lng);
                const selectBarangay = () => {
                    updateSelectedBarangay(name, barangay, cases, isHotspot);
                    showFloatingInfo(name, barangay, cases, isHotspot, markerLatLng);
                };
                const marker = L.marker([lat, lng], {
                    keyboard: true,
                    title: name,
                    alt: name,
                    interactive: true,
                    bubblingMouseEvents: false,
                    icon: L.divIcon({
                        className: 'nasugbu-marker-icon',
                        html: `<span role="button" tabindex="0" class="nasugbu-marker-hitbox" data-barangay="${escapeMapText(name)}" aria-label="Show ${escapeMapText(name)} barangay data"><span class="nasugbu-dot-marker${isHotspot ? ' is-hotspot' : ''}"></span></span>`,
                        iconSize: [markerSize, markerSize],
                        iconAnchor: [iconAnchor, iconAnchor],
                        tooltipAnchor: [0, isHotspot ? -18 : -12],
                        popupAnchor: [150, isHotspot ? -36 : -32]
                    })
                });

                // Hover tooltip displaying barangay name & cases
                marker.bindTooltip(`
            <div class="text-center font-sans">
                <span class="font-bold text-slate-900">${escapeMapText(name || 'Barangay')}</span>
            </div>
        `, {
                    permanent: false,
                    direction: 'top',
                    offset: [0, -12],
                    sticky: false,
                    interactive: false
                });
                marker.on('mouseover focus', () => {
                    marker.openTooltip();
                    marker.getElement()?.querySelector('.nasugbu-dot-marker')?.classList.add('is-hovered');
                });
                marker.on('mouseout blur popupclose', () => {
                    marker.closeTooltip();
                    marker.getElement()?.querySelector('.nasugbu-dot-marker')?.classList.remove('is-hovered');
                });

                marker.on('click keypress', (event) => {
                    if (event.originalEvent?.type === 'keypress' && !['Enter', ' '].includes(event.originalEvent.key)) return;
                    selectBarangay();
                });

                const bindMarkerHitbox = () => {
                    const hitbox = marker.getElement()?.querySelector('.nasugbu-marker-hitbox');
                    if (!hitbox || hitbox.dataset.clickBound === '1') return;
                    hitbox.dataset.clickBound = '1';
                    const showMarkerName = () => {
                        marker.openTooltip();
                        hitbox.querySelector('.nasugbu-dot-marker')?.classList.add('is-hovered');
                    };
                    const hideMarkerName = () => {
                        marker.closeTooltip();
                        hitbox.querySelector('.nasugbu-dot-marker')?.classList.remove('is-hovered');
                    };
                    hitbox.addEventListener('pointerenter', showMarkerName);
                    hitbox.addEventListener('pointerleave', hideMarkerName);
                    hitbox.addEventListener('focus', showMarkerName);
                    hitbox.addEventListener('blur', hideMarkerName);
                    const openMarkerDetails = (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        selectBarangay();
                    };
                    hitbox.addEventListener('click', openMarkerDetails);
                    hitbox.addEventListener('keydown', (event) => {
                        if (!['Enter', ' '].includes(event.key)) return;
                        event.preventDefault();
                        selectBarangay();
                    });
                };
                marker.on('add', bindMarkerHitbox);
                marker.addTo(markerGroup);
                bindMarkerHitbox();
            });

            map.fitBounds(nasugbuFocusBounds, {
                padding: [18, 18],
                maxZoom: 13,
                animate: false
            });

            const recenterBtn = document.getElementById('recenterNasugbuMapBtn');
            if (recenterBtn) {
                recenterBtn.addEventListener('click', () => {
                    map.fitBounds(nasugbuFocusBounds, {
                        padding: [18, 18],
                        maxZoom: 13,
                        animate: true
                    });
                });
            }

            const refreshMapLayout = (fitBounds = false) => {
                map.invalidateSize({
                    animate: false,
                    pan: false
                });
                if (fitBounds) {
                    map.fitBounds(nasugbuFocusBounds, {
                        padding: [18, 18],
                        maxZoom: 13,
                        animate: false
                    });
                }
            };

            refreshMapLayout();
            [0, 80, 250, 600].forEach((delay) => {
                window.setTimeout(refreshMapLayout, delay);
            });
            if ('ResizeObserver' in window) {
                new ResizeObserver(refreshMapLayout).observe(mapElement);
            }
            map.on('move zoom resize', positionFloatingInfo);
            window.addEventListener('resize', refreshMapLayout);
        };

        const initializeAdminOverviewChart = () => {
            const overviewChartCard = document.querySelector('.unified-chart-card');
            if (!overviewChartCard || overviewChartCard.dataset.chartInitialized === '1') return;
            const overviewChartTooltip = overviewChartCard.querySelector('.unified-chart-tooltip');
            const overviewChartHoverLine = overviewChartCard.querySelector('.unified-chart-hover-line');
            const overviewChartPoints = Array.from(overviewChartCard.querySelectorAll('.unified-chart-point, .reference-chart-hit-area'));
            if (!overviewChartTooltip || !overviewChartHoverLine || !overviewChartPoints.length) return;
            overviewChartCard.dataset.chartInitialized = '1';
            let chartTooltipPinned = false;
            const showChartTooltip = (point) => {
                const rect = overviewChartCard.getBoundingClientRect();
                const chartWidth = 520;
                const pointX = Number(point.dataset.x || point.getAttribute('cx') || 0);
                const pointY = Number(point.dataset.y || point.getAttribute('cy') || 42);
                const relativeX = (pointX / chartWidth) * rect.width;
                const relativeY = (pointY / 220) * rect.height;
                const label = point.dataset.label || 'Month';
                const value = point.dataset.value || '0';

                overviewChartHoverLine.style.left = `${relativeX}px`;
                overviewChartTooltip.style.left = `${Math.min(Math.max(relativeX, 72), rect.width - 72)}px`;
                overviewChartTooltip.style.top = `${Math.max(28, relativeY - 34)}px`;
                overviewChartTooltip.querySelector('.unified-chart-tooltip-label').textContent = label;
                overviewChartTooltip.querySelector('.unified-chart-tooltip-value').textContent = `${value} consultation${Number(value) === 1 ? '' : 's'}`;

                overviewChartPoints.forEach((item) => item.classList.toggle('is-active', item === point));
                overviewChartTooltip.classList.add('is-visible');
                overviewChartHoverLine.classList.add('is-visible');
            };

            const updateHover = (event) => {
                if (chartTooltipPinned) return;
                const rect = overviewChartCard.getBoundingClientRect();
                const localX = Math.min(Math.max(event.clientX - rect.left, 0), rect.width);
                const chartWidth = 520;
                const xInChart = (localX / rect.width) * chartWidth;
                const nearest = overviewChartPoints.reduce((closest, point) => {
                    const pointX = Number(point.dataset.x || point.getAttribute('cx') || 0);
                    const currentDistance = Math.abs(pointX - xInChart);
                    const closestDistance = Math.abs(Number(closest.dataset.x || closest.getAttribute('cx') || 0) - xInChart);
                    return currentDistance < closestDistance ? point : closest;
                }, overviewChartPoints[0]);
                showChartTooltip(nearest);
            };

            overviewChartCard.addEventListener('pointermove', updateHover);
            overviewChartPoints.forEach((point) => {
                point.addEventListener('click', () => {
                    chartTooltipPinned = true;
                    showChartTooltip(point);
                });
            });
            overviewChartCard.addEventListener('pointerleave', () => {
                if (chartTooltipPinned) return;
                overviewChartPoints.forEach((point) => point.classList.remove('is-active'));
                overviewChartTooltip.classList.remove('is-visible');
                overviewChartHoverLine.classList.remove('is-visible');
            });
            overviewChartCard.addEventListener('pointerdown', (event) => {
                if (event.target.closest?.('.reference-chart-hit-area')) return;
                chartTooltipPinned = false;
            });
        };

        const consultationPageState = { page: 1, query: '' };
        const initializeAdminConsultationPagination = () => {
            const table = document.querySelector('.admin-consultation-patient-table');
            if (!table || table.dataset.consultationPaginationBound === '1') return;
            table.dataset.consultationPaginationBound = '1';

            const rows = Array.from(table.querySelectorAll('[data-consultation-patient-row]'));
            const badge = document.querySelector('[data-consultation-page-badge]');
            const pageStatus = document.querySelector('[data-consultation-page-status]');
            const pagePrev = document.querySelector('[data-consultation-page-prev]');
            const pageNext = document.querySelector('[data-consultation-page-next]');
            const rowsPerPage = 5;
            let currentPage = consultationPageState.page;
            const toolbar = document.createElement('div');
            toolbar.className = 'flex flex-wrap items-center gap-2 border-b bg-gray-50 p-2 text-xs';
            toolbar.innerHTML = '<input aria-label="Search consultations" class="w-full max-w-xs rounded border p-2" placeholder="Search patient name...">';
            table.parentElement.insertBefore(toolbar, table);
            const search = toolbar.querySelector('input');
            search.value = consultationPageState.query;

            const detailForRow = (row) => {
                const detailRow = row.nextElementSibling;
                return detailRow?.matches?.('[data-consultation-detail-row]') ? detailRow : null;
            };

            const requestedVisit = new URL(location.href).searchParams.get('qr_appointment');
            if (/^[1-9]\d*$/.test(requestedVisit || '')) {
                const selectedIndex = rows.findIndex(row => detailForRow(row)?.querySelector(`[data-qr-consultation-id="${requestedVisit}"]`));
                if (selectedIndex >= 0) {
                    consultationPageState.query = '';
                    search.value = '';
                    currentPage = Math.floor(selectedIndex / rowsPerPage) + 1;
                }
            }

            const renderPage = () => {
                const query = consultationPageState.query.trim().toLowerCase();
                const matching = rows.filter((row) => !query || `${row.textContent} ${detailForRow(row)?.textContent || ''}`.toLowerCase().includes(query));
                const totalPages = Math.max(1, Math.ceil(matching.length / rowsPerPage));
                currentPage = Math.min(Math.max(currentPage, 1), totalPages);
                consultationPageState.page = currentPage;
                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;
                const visibleRows = new Set(matching.slice(start, end));
                let visibleConsultationCount = 0;

                rows.forEach((row) => {
                    const isVisible = visibleRows.has(row);
                    row.hidden = !isVisible;
                    row.classList.toggle('hidden', !isVisible);
                    const detailRow = detailForRow(row);
                    if (!isVisible) {
                        detailRow?.classList.add('hidden');
                        const toggle = row.querySelector('[data-consultation-records-toggle]');
                        if (toggle) toggle.textContent = 'View records';
                    }
                    if (isVisible) {
                        const countText = row.querySelector('td:nth-child(3)')?.textContent || '0';
                        visibleConsultationCount += Number.parseInt(countText, 10) || 0;
                    }
                });

                if (badge) {
                    const patientLabel = visibleRows.size === 1 ? 'Patient' : 'Patients';
                    const consultationLabel = visibleConsultationCount === 1 ? 'Consultation' : 'Consultations';
                    badge.textContent = `Page ${currentPage}: ${visibleRows.size} ${patientLabel} / ${visibleConsultationCount} ${consultationLabel}`;
                }
                if (pageStatus) {
                    pageStatus.textContent = `${matching.length} patient${matching.length === 1 ? '' : 's'}${query ? ' matched' : ' total'} - Page ${currentPage}/${totalPages}`;
                }
                if (pagePrev) pagePrev.disabled = currentPage <= 1;
                if (pageNext) pageNext.disabled = currentPage >= totalPages;
            };

            search.addEventListener('input', () => {
                consultationPageState.query = search.value;
                currentPage = 1;
                renderPage();
            });
            pagePrev?.addEventListener('click', () => {
                currentPage -= 1;
                renderPage();
            });
            pageNext?.addEventListener('click', () => {
                currentPage += 1;
                renderPage();
            });

            renderPage();
        };

        const initializeLiveRefreshedAdminWidgets = () => {
            initializeNasugbuHealthMap();
            initializeAdminOverviewChart();
            initializeAdminConsultationPagination();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeLiveRefreshedAdminWidgets);
        } else {
            initializeLiveRefreshedAdminWidgets();
        }
        window.addEventListener('load', initializeLiveRefreshedAdminWidgets);
        document.addEventListener('rhu-admin-live-refresh', () => {
            window.requestAnimationFrame(initializeLiveRefreshedAdminWidgets);
        });

        document.querySelectorAll('[data-certificate-cleanup-panel]').forEach((panel) => {
            if (panel.dataset.certificateInlineBound === '1') return;
            const toggle = panel.querySelector('[data-certificate-select-toggle]');
            const deleteSelected = panel.querySelector('[data-certificate-delete-selected]');
            const selectBoxes = Array.from(panel.querySelectorAll('[data-certificate-select-box]'));
            const selectHeaders = Array.from(panel.querySelectorAll('[data-certificate-select-header]'));
            const inputs = Array.from(panel.querySelectorAll('input[name="certificate_ids[]"]'));
            const search = panel.querySelector('[data-certificate-search]');
            const pageStatus = panel.querySelector('[data-certificate-page-status]');
            const pagePrev = panel.querySelector('[data-certificate-page-prev]');
            const pageNext = panel.querySelector('[data-certificate-page-next]');
            const rows = Array.from(panel.querySelectorAll('[data-certificate-row]'));
            const rowsPerPage = 5;
            let selectMode = false;
            let currentPage = 1;

            const renderSelectMode = () => {
                panel.dataset.certificateSelectMode = selectMode ? '1' : '0';
                selectBoxes.forEach((box) => box.classList.toggle('hidden', !selectMode));
                selectHeaders.forEach((box) => box.classList.toggle('hidden', !selectMode));
                const hasSelection = inputs.some((input) => input.checked);
                rows.forEach((row) => {
                    const checkbox = row.querySelector('input[name="certificate_ids[]"]');
                    row.classList.toggle('is-certificate-selected', Boolean(checkbox?.checked));
                });
                deleteSelected?.classList.toggle('hidden', !selectMode || !hasSelection);
                if (toggle) {
                    toggle.textContent = selectMode ? 'Cancel Select' : 'Select Delete';
                    toggle.classList.toggle('bg-red-100', selectMode);
                    toggle.classList.toggle('text-red-800', selectMode);
                    toggle.classList.toggle('hover:bg-red-200', selectMode);
                    toggle.classList.toggle('bg-emerald-100', !selectMode);
                    toggle.classList.toggle('text-emerald-900', !selectMode);
                    toggle.classList.toggle('hover:bg-emerald-200', !selectMode);
                }
                if (!selectMode) {
                    inputs.forEach((input) => {
                        input.checked = false;
                    });
                }
            };

            const renderCertificatePage = () => {
                if (!rows.length) return;
                const query = (search?.value || '').trim().toLowerCase();
                const visibleRows = rows.filter((row) => !query || (row.dataset.certificateSearchText || '').includes(query));
                const totalPages = Math.max(1, Math.ceil(visibleRows.length / rowsPerPage));
                currentPage = Math.min(Math.max(currentPage, 1), totalPages);
                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;

                rows.forEach((row) => {
                    row.classList.add('hidden');
                });
                visibleRows.slice(start, end).forEach((row) => {
                    row.classList.remove('hidden');
                });

                if (pageStatus) {
                    pageStatus.textContent = `${visibleRows.length} record${visibleRows.length === 1 ? '' : 's'} - Page ${currentPage}/${totalPages}`;
                }
                if (pagePrev) pagePrev.disabled = currentPage <= 1;
                if (pageNext) pageNext.disabled = currentPage >= totalPages;
            };

            toggle?.addEventListener('click', () => {
                selectMode = !selectMode;
                renderSelectMode();
            });
            inputs.forEach((input) => input.addEventListener('change', renderSelectMode));
            rows.forEach((row) => {
                row.addEventListener('click', (event) => {
                    if (!selectMode) return;
                    if (event.target.closest('a, button, form, input, label, select, textarea')) return;
                    const checkbox = row.querySelector('input[name="certificate_ids[]"]');
                    if (!checkbox) return;
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
            search?.addEventListener('input', () => {
                currentPage = 1;
                renderCertificatePage();
            });
            pagePrev?.addEventListener('click', () => {
                currentPage -= 1;
                renderCertificatePage();
            });
            pageNext?.addEventListener('click', () => {
                currentPage += 1;
                renderCertificatePage();
            });
            renderSelectMode();
            renderCertificatePage();
        });

        const initializeAdminMessagePanel = (panel) => {
            const toggle = panel.querySelector('[data-message-select-toggle]');
            const deleteSelected = panel.querySelector('[data-message-delete-selected]');
            let selectMode = false;

            const renderSelectMode = () => {
                const selectBoxes = Array.from(panel.querySelectorAll('[data-message-select-box]'));
                const checkedBoxes = Array.from(panel.querySelectorAll('input[name="message_ids[]"]:checked'));
                panel.dataset.messageSelectMode = selectMode ? '1' : '0';
                selectBoxes.forEach((box) => box.classList.toggle('hidden', !selectMode));
                deleteSelected?.classList.toggle('hidden', !selectMode || checkedBoxes.length === 0);
                if (toggle) {
                    toggle.textContent = selectMode ? 'Cancel Select' : 'Select Delete';
                    toggle.classList.toggle('bg-red-100', selectMode);
                    toggle.classList.toggle('text-red-800', selectMode);
                    toggle.classList.toggle('hover:bg-red-200', selectMode);
                    toggle.classList.toggle('bg-emerald-100', !selectMode);
                    toggle.classList.toggle('text-emerald-900', !selectMode);
                    toggle.classList.toggle('hover:bg-emerald-200', !selectMode);
                }
                if (!selectMode) {
                    panel.querySelectorAll('input[name="message_ids[]"]').forEach((input) => {
                        input.checked = false;
                    });
                }
            };

            panel.querySelectorAll('input[name="message_ids[]"]').forEach((input) => {
                if (input.dataset.liveMessageBound === '1') return;
                input.dataset.liveMessageBound = '1';
                input.addEventListener('change', renderSelectMode);
            });

            if (toggle && toggle.dataset.liveMessageBound !== '1') {
                toggle.dataset.liveMessageBound = '1';
                toggle.addEventListener('click', () => {
                    selectMode = !selectMode;
                    renderSelectMode();
                });
            }

            renderSelectMode();
            panel.renderMessageSelectMode = renderSelectMode;
        };

        document.querySelectorAll('[data-message-cleanup-panel]').forEach(initializeAdminMessagePanel);

        const adminSeenMessagesKey = 'rhu-admin-seen-messages-' + <?php echo json_encode((string)($_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? $_SESSION['rhu_admin_email'] ?? 'admin')); ?>;
        const initialAdminMessageIds = <?php echo json_encode(array_map(static fn($item) => (string)$item['id'], $dbMessagesList ?? [])); ?>;
        let storedAdminSeenMessages = null;
        try {
            storedAdminSeenMessages = JSON.parse(localStorage.getItem(adminSeenMessagesKey));
        } catch (_) {}
        const adminSeenMessages = new Set(Array.isArray(storedAdminSeenMessages) ? storedAdminSeenMessages : initialAdminMessageIds);
        const persistAdminSeenMessages = () => {
            try {
                localStorage.setItem(adminSeenMessagesKey, JSON.stringify([...adminSeenMessages]));
            } catch (_) {}
        };
        persistAdminSeenMessages();

        const bindAdminMessageThreads = (panel) => {
            panel.querySelectorAll('[data-admin-message-thread-toggle]').forEach((button) => {
                const messageIds = JSON.parse(button.dataset.messageIds || '[]');
                const dot = button.querySelector('[data-new-message-dot]');
                if (dot) dot.hidden = !messageIds.some((id) => !adminSeenMessages.has(String(id)));
                if (button.dataset.threadBound === '1') return;
                button.dataset.threadBound = '1';
                button.addEventListener('click', () => {
                    const thread = document.getElementById(button.dataset.adminMessageThreadToggle);
                    thread?.classList.toggle('hidden');
                    if (thread && !thread.classList.contains('hidden')) {
                        messageIds.forEach((id) => adminSeenMessages.add(String(id)));
                        persistAdminSeenMessages();
                        if (dot) dot.hidden = true;
                    }
                });
            });
            panel.querySelectorAll('[data-admin-reply-message]').forEach((button) => {
                if (button.dataset.replyBound === '1') return;
                button.dataset.replyBound = '1';
                button.addEventListener('click', () => {
                    const thread = button.closest('[data-reply-selection-key]');
                    if (!thread) return;
                    const messageId = button.dataset.adminReplyMessage;
                    adminReplySelections.set(thread.dataset.replySelectionKey, messageId);
                    thread.querySelector('input[name="message_id"]').value = messageId;
                    thread.querySelector('button[type="submit"]').disabled = false;
                    thread.querySelector('[data-reply-target]').textContent = 'Replying to: ' + (button.querySelector('p')?.textContent || 'Resident Message');
                    thread.querySelectorAll('[data-admin-reply-message]').forEach((row) => {
                        row.setAttribute('aria-pressed', String(row === button));
                    });
                    thread.querySelector('input[name="reply"]')?.focus();
                });
            });
        };

        const escapeAdminMessageHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const formatAdminMessageDate = (value) => {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value || '';
            return date.toLocaleString([], {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        };

        const cleanAdminMessageText = (value) => String(value || '')
            .replace(/\s*\[Replied:\s*.*?\]\s*/gi, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        const groupAdminMessagesByResident = (messages) => {
            const groups = new Map();
            messages.forEach((msg) => {
                const residentName = `${msg.first_name || ''} ${msg.last_name || ''}`.trim() || `Resident #${msg.resident_id || ''}`;
                const groupKey = msg.resident_id ? `resident-${msg.resident_id}` : `email-${msg.email || residentName}`;
                if (!groups.has(groupKey)) {
                    groups.set(groupKey, {
                        residentName,
                        email: msg.email || '',
                        latestDate: msg.created_at || '',
                        messages: [],
                        seen: new Set()
                    });
                }
                const group = groups.get(groupKey);
                const cleanMessage = cleanAdminMessageText(msg.message || '');
                const duplicateKey = `${msg.subject || ''}|${cleanMessage}|${msg.admin_reply || ''}`;
                if (group.seen.has(duplicateKey)) return;
                group.seen.add(duplicateKey);
                group.latestDate = String(msg.created_at || '') > String(group.latestDate || '') ? msg.created_at : group.latestDate;
                group.messages.push({
                    ...msg,
                    clean_message: cleanMessage
                });
            });
            return Array.from(groups.values()).map((group) => {
                group.messages.sort((a, b) => String(a.created_at || '').localeCompare(String(b.created_at || '')));
                return group;
            }).sort((a, b) => String(b.latestDate || '').localeCompare(String(a.latestDate || '')));
        };

        const adminReplySelections = new Map();
        document.querySelectorAll('[data-message-cleanup-panel]').forEach(bindAdminMessageThreads);
        const renderAdminMessagesLive = (messages) => {
            const panel = document.querySelector('[data-message-cleanup-panel]');
            const count = document.getElementById('admin-message-count');
            const list = document.getElementById('admin-message-list');
            if (!panel || !count || !list) return;

            const activeElement = document.activeElement;
            const adminIsTypingReply = activeElement && list.contains(activeElement) && activeElement.matches('input[name="reply"]');
            const adminHasSelectedMessages = panel.querySelectorAll('input[name="message_ids[]"]:checked').length > 0;
            if (adminIsTypingReply || adminHasSelectedMessages) return;

            const groupedMessages = groupAdminMessagesByResident(messages);
            count.textContent = `${groupedMessages.length} Resident${groupedMessages.length === 1 ? '' : 's'}`;
            panel.querySelector('[data-message-select-toggle]')?.classList.toggle('hidden', messages.length === 0);

            if (!groupedMessages.length) {
                list.innerHTML = '<p class="text-xs text-gray-400 italic py-3">No resident messages found in database.</p>';
                panel.renderMessageSelectMode?.();
                return;
            }

            list.innerHTML = `<div class="space-y-3">${groupedMessages.map((group, groupIndex) => {
                const latest = group.messages[group.messages.length - 1] || {};
                const threadId = `admin-message-thread-${groupIndex}`;
                const email = group.email ? `<span class="font-semibold text-gray-500">(${escapeAdminMessageHtml(group.email)})</span>` : '';
                const selectionKey = String(latest.resident_id || group.email || group.residentName);
                const selectedMessage = group.messages.find((msg) => String(msg.id) === adminReplySelections.get(selectionKey));
                const replyComposer = `<div class="mt-2 border-t border-gray-100 pt-2">
                      <p data-reply-target class="mb-2 text-xs font-semibold text-teal-700">${selectedMessage ? 'Replying to: ' + escapeAdminMessageHtml(selectedMessage.clean_message || selectedMessage.subject || 'Resident Message') : 'No message selected'}</p>
                      <form method="post" class="flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                        <input type="hidden" name="action" value="reply_message">
                        <input type="hidden" name="tab" value="<?php echo esc($tab); ?>">
                        <input type="hidden" name="message_id" value="${Number(selectedMessage?.id) || 0}">
                        <input required name="reply" class="min-w-0 flex-1 rounded-full border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:border-teal-600" placeholder="Write a reply...">
                        <button type="submit" ${selectedMessage ? '' : 'disabled'} class="rounded-full bg-teal-700 px-4 py-2 text-xs font-bold text-white hover:bg-teal-800 disabled:opacity-50 disabled:cursor-not-allowed">Send</button>
                      </form></div>`;
                const threadMessages = group.messages.map((msg) => {
                    return `<div>
                    <div class="flex items-start gap-2 text-xs">
                      <label class="hidden pt-0.5" data-message-select-box>
                        <input form="deleteSelectedMessagesForm" type="checkbox" name="message_ids[]" value="${Number(msg.id) || 0}" class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span class="sr-only">Select message ${Number(msg.id) || 0}</span>
                      </label>
                      <div class="flex flex-1 flex-col gap-1.5">
                        <button type="button" data-admin-reply-message="${Number(msg.id) || 0}" aria-pressed="${String(msg.id) === String(selectedMessage?.id)}" class="admin-message-row w-full text-left">
                          <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-bold text-gray-900">${escapeAdminMessageHtml(msg.subject || 'General Inquiry')}</span>
                            <span class="text-[10px] font-semibold text-gray-400">${escapeAdminMessageHtml(formatAdminMessageDate(msg.created_at))}</span>
                          </div>
                          <p class="mt-0.5 whitespace-pre-wrap text-[11px] font-medium leading-snug text-gray-700">${escapeAdminMessageHtml(msg.clean_message || '')}</p>
                        </button>
                        ${msg.admin_reply ? `<div class="admin-message-reply ml-auto w-full max-w-3xl px-3 py-2">
                            <p class="text-[10px] font-black uppercase tracking-wide text-teal-700">Admin Reply</p>
                            <p class="mt-0.5 whitespace-pre-wrap text-[11px] font-semibold leading-snug">${escapeAdminMessageHtml(msg.admin_reply)}</p>
                          </div>` : ''}
                      </div>
                    </div>
                  </div>`;
                }).join('');
                return `<div class="border-b border-gray-100 py-2 last:border-b-0">
                    <button type="button" class="flex w-full items-center justify-between gap-3 rounded-lg border border-gray-100 bg-white px-3 py-2 text-left hover:bg-gray-50" data-admin-message-thread-toggle="${threadId}" data-message-ids="${escapeAdminMessageHtml(JSON.stringify(group.messages.map((msg) => String(msg.id))))}">
                      <span class="min-w-0">
                        <span class="flex items-center gap-2 truncate text-xs font-black text-gray-900"><span data-new-message-dot hidden class="h-2.5 w-2.5 shrink-0 rounded-full bg-red-600" title="New message"></span><span class="truncate">${escapeAdminMessageHtml(group.residentName)} ${email}</span></span>
                        <span class="mt-1 block truncate text-[11px] font-semibold text-gray-500">${escapeAdminMessageHtml(latest.subject || 'Resident Message')} - ${escapeAdminMessageHtml(cleanAdminMessageText(latest.message || ''))}</span>
                      </span>
                      <span class="flex shrink-0 items-center gap-2">
                        <span class="text-[10px] font-semibold text-gray-400">${escapeAdminMessageHtml(formatAdminMessageDate(group.latestDate))}</span>
                      </span>
                    </button>
                    <div id="${threadId}" data-reply-selection-key="${escapeAdminMessageHtml(selectionKey)}" class="hidden mt-2 space-y-2 px-2">
                      ${threadMessages}
                      ${replyComposer}
                    </div>
                  </div>`;
            }).join('')}</div>`;

            bindAdminMessageThreads(panel);
            panel.querySelectorAll('input[name="message_ids[]"]').forEach((input) => {
                input.addEventListener('change', () => panel.renderMessageSelectMode?.());
            });
            panel.renderMessageSelectMode?.();
        };

        const fetchAdminMessagesLive = async () => {
            try {
                const response = await fetch('RHUAdminDashboard.php?api=admin_messages', {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                if (response.status === 401 || response.redirected) {
                    window.location.assign('RHUAdminLogin.php');
                    return;
                }
                if (!response.ok) return;
                if (!(response.headers.get('content-type') || '').includes('application/json')) {
                    throw new Error('Message endpoint returned a non-JSON response. Check the server response in Network.');
                }
                const data = await response.json();
                if (data.success) {
                    const messages = data.messages || [];
                    const signature = messages.map((message) => `${message.id}:${message.status}:${message.admin_reply || ''}:${message.created_at}`).join('|');
                    const panel = document.querySelector('[data-message-cleanup-panel]');
                    const list = document.getElementById('admin-message-list');
                    const activeElement = document.activeElement;
                    const adminIsTypingReply = activeElement && list?.contains(activeElement) && activeElement.matches('input[name="reply"]');
                    const adminHasSelectedMessages = panel?.querySelectorAll('input[name="message_ids[]"]:checked').length > 0;
                    if (adminIsTypingReply || adminHasSelectedMessages) return;
                    if (!panel || panel.dataset.messageLiveSignature !== signature) {
                        if (panel) panel.dataset.messageLiveSignature = signature;
                        renderAdminMessagesLive(messages);
                    }
                }
            } catch (error) {
                console.error('Failed to refresh resident messages:', error);
            }
        };

        if (document.querySelector('[data-message-cleanup-panel]')) {
            fetchAdminMessagesLive();
            window.setInterval(fetchAdminMessagesLive, 5000);
        }

        document.querySelectorAll('form').forEach((form) => {
            const residentSelect = form.querySelector('select[name="resident_id"]');
            const certificateType = form.querySelector('select[name="certificate_type_id"]');
            const staffSelect = form.querySelector('[data-certificate-staff-select]');
            if (!certificateType || !staffSelect) return;
            const purposeField = form.querySelector('[data-certificate-purpose]');
            const staffOptions = Array.from(staffSelect.options);

            const filterRelatedStaff = () => {
                const selected = certificateType.selectedOptions[0];
                const allowedGroups = (selected?.dataset.staffGroups || '').split(',').filter(Boolean);
                let visibleCount = 0;
                staffOptions.forEach((option, index) => {
                    if (index === 0) {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }
                    const staffGroups = (option.dataset.staffGroups || '').split(',').filter(Boolean);
                    const isRelated = allowedGroups.length > 0 && staffGroups.some((group) => allowedGroups.includes(group));
                    option.hidden = !isRelated;
                    option.disabled = !isRelated;
                    if (isRelated) visibleCount++;
                });
                staffSelect.value = '';
                staffSelect.options[0].textContent = visibleCount > 0 ? 'Assigned doctor / staff' : 'No related staff for this certificate type';
            };

            certificateType.addEventListener('change', filterRelatedStaff);
            residentSelect?.addEventListener('change', () => {
                const request = residentSelect.selectedOptions[0]?.dataset || {};
                const requestedTypeName = (request.requestTypeName || '').trim().toLowerCase();
                if (!requestedTypeName && !request.requestPurpose) return;
                const matchingType = Array.from(certificateType.options).find((option) => {
                    const optionName = (option.dataset.typeName || option.textContent || '').trim().toLowerCase();
                    return optionName !== '' && optionName === requestedTypeName;
                });
                if (matchingType) certificateType.value = matchingType.value;
                if (purposeField) purposeField.value = request.requestPurpose || purposeField.value;
                certificateType.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            });
            filterRelatedStaff();
        });

        const certificatePreviewModal = document.querySelector('[data-certificate-preview-modal]');
        const certificatePreviewContent = certificatePreviewModal?.querySelector('[data-certificate-preview-content]');
        const certificatePreviewClose = certificatePreviewModal?.querySelector('[data-certificate-preview-close]');
        const certificatePreviewDownload = certificatePreviewModal?.querySelector('[data-certificate-preview-download]');
        const closeCertificatePreview = () => {
            certificatePreviewModal?.classList.add('hidden');
            if (certificatePreviewContent) certificatePreviewContent.innerHTML = '';
            certificatePreviewDownload?.classList.add('hidden');
            certificatePreviewDownload?.classList.remove('inline-flex');
            unlockDashboardBackground();
        };
        document.querySelectorAll('[data-certificate-preview-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const certificateId = button.getAttribute('data-certificate-preview-open');
                const template = document.querySelector(`[data-certificate-preview-template="${certificateId}"]`);
                if (!certificatePreviewModal || !certificatePreviewContent || !template) return;
                certificatePreviewContent.innerHTML = template.innerHTML;
                if (certificatePreviewDownload) {
                    certificatePreviewDownload.href = `?certificate_pdf=${encodeURIComponent(certificateId)}`;
                    certificatePreviewDownload.classList.remove('hidden');
                    certificatePreviewDownload.classList.add('inline-flex');
                }
                lockDashboardBackground();
                certificatePreviewModal.classList.remove('hidden');
                window.setTimeout(() => certificatePreviewClose?.focus?.(), 80);
            });
        });
        certificatePreviewClose?.addEventListener('click', closeCertificatePreview);
        certificatePreviewModal?.addEventListener('click', (event) => {
            if (event.target === certificatePreviewModal) closeCertificatePreview();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && certificatePreviewModal && !certificatePreviewModal.classList.contains('hidden')) {
                closeCertificatePreview();
            }
        });

        const featureDrawer = document.querySelector('[data-feature-drawer]');
        const drawerBackdrop = document.querySelector('[data-drawer-backdrop]');
        const drawerOpenButton = document.querySelector('[data-drawer-open]');
        const drawerCloseButton = document.querySelector('[data-drawer-close]');
        let drawerReturnFocus = null;
        const setDrawerOpen = (open) => {
            featureDrawer?.classList.toggle('is-open', open);
            drawerBackdrop?.classList.toggle('is-open', open);
            drawerBackdrop?.classList.toggle('hidden', !open);
            document.body.classList.toggle('drawer-open', open);
            featureDrawer?.setAttribute('aria-hidden', String(!open));
            drawerBackdrop?.setAttribute('aria-hidden', String(!open));
            drawerOpenButton?.setAttribute('aria-expanded', String(open));
            if (open) {
                drawerReturnFocus = document.activeElement;
                window.setTimeout(() => drawerCloseButton?.focus(), 120);
            } else if (drawerReturnFocus instanceof HTMLElement) {
                drawerReturnFocus.focus();
            }
        };
        drawerBackdrop?.classList.add('hidden');
        drawerOpenButton?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            setDrawerOpen(true);
        });
        drawerOpenButton?.addEventListener('touchend', (event) => {
            event.preventDefault();
            event.stopPropagation();
            setDrawerOpen(true);
        }, {
            passive: false
        });
        drawerCloseButton?.addEventListener('click', () => setDrawerOpen(false));
        drawerBackdrop?.addEventListener('click', () => setDrawerOpen(false));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && featureDrawer?.classList.contains('is-open')) setDrawerOpen(false);
        });

        document.querySelectorAll('table').forEach((table, tableIndex) => {
            return;
            if (table.closest('[data-certificate-cleanup-panel]')) return;
            if (table.classList.contains('admin-consultation-patient-table')) return;
            const body = table.tBodies[0];
            if (!body || body.rows.length < 5) return;
            const rows = Array.from(body.rows);
            const pageSize = 15;
            let page = 1;
            let query = '';
            const toolbar = document.createElement('div');
            toolbar.className = 'flex items-center justify-between gap-2 border-b bg-gray-50 p-2 text-xs';
            toolbar.innerHTML = `<input aria-label="Search table" class="w-full max-w-xs rounded border p-2" placeholder="Search these records..."><span class="whitespace-nowrap"></span><div class="flex gap-1"><button type="button" class="rounded border bg-white px-2 py-1">Previous</button><button type="button" class="rounded border bg-white px-2 py-1">Next</button></div>`;
            table.parentElement.insertBefore(toolbar, table);
            const input = toolbar.querySelector('input');
            const status = toolbar.querySelector('span');
            const [previous, next] = toolbar.querySelectorAll('button');
            const render = () => {
                const matching = rows.filter(row => row.textContent.toLowerCase().includes(query));
                const pages = Math.max(1, Math.ceil(matching.length / pageSize));
                page = Math.min(page, pages);
                rows.forEach(row => row.hidden = true);
                matching.slice((page - 1) * pageSize, page * pageSize).forEach(row => row.hidden = false);
                status.textContent = `${matching.length} records - Page ${page}/${pages}`;
                previous.disabled = page <= 1;
                next.disabled = page >= pages;
            };
            input.addEventListener('input', () => {
                query = input.value.trim().toLowerCase();
                page = 1;
                render();
            });
            previous.addEventListener('click', () => {
                page--;
                render();
            });
            next.addEventListener('click', () => {
                page++;
                render();
            });
            render();
        });

        initializeAdminConsultationPagination();
        document.querySelectorAll('.admin-consultation-patient-table').forEach((table) => {
            if (table.dataset.consultationPaginationBound === '1') return;
            const body = table.tBodies[0];
            if (!body) return;
            const patientRows = Array.from(body.querySelectorAll('.admin-consultation-patient-row'));
            if (!patientRows.length) return;
            const pageSize = 5;
            let page = 1;
            let query = '';
            const openPatients = new Set();
            const toolbar = document.createElement('div');
            toolbar.className = 'flex items-center justify-between gap-2 border-b bg-gray-50 p-2 text-xs';
            toolbar.innerHTML = `<input aria-label="Search consultations" class="w-full max-w-xs rounded border p-2" placeholder="Search patient name..."><span class="whitespace-nowrap"></span><div class="flex gap-1"><button type="button" class="rounded border bg-white px-2 py-1">Previous</button><button type="button" class="rounded border bg-white px-2 py-1">Next</button></div>`;
            table.parentElement.insertBefore(toolbar, table);
            const input = toolbar.querySelector('input');
            const status = toolbar.querySelector('span');
            const [previous, next] = toolbar.querySelectorAll('button');
            const patientKeyFor = (row) => row.cells[0]?.textContent.trim().toLowerCase() || '';
            const rowMatches = (row) => {
                const detail = row.nextElementSibling;
                return !query || `${row.textContent} ${detail?.textContent || ''}`.toLowerCase().includes(query);
            };
            const render = () => {
                const matching = patientRows.filter(rowMatches);
                const pages = Math.max(1, Math.ceil(matching.length / pageSize));
                page = Math.min(page, pages);
                patientRows.forEach((row) => {
                    row.hidden = true;
                    row.nextElementSibling?.classList.add('hidden');
                    const button = row.querySelector('[data-consultation-records-toggle]');
                    if (button) button.textContent = 'View records';
                });
                matching.slice((page - 1) * pageSize, page * pageSize).forEach((row) => {
                    row.hidden = false;
                    const key = patientKeyFor(row);
                    const detail = row.nextElementSibling;
                    const button = row.querySelector('[data-consultation-records-toggle]');
                    if (openPatients.has(key) && detail?.classList.contains('admin-consultation-detail-row')) {
                        detail.classList.remove('hidden');
                        if (button) button.textContent = 'Hide records';
                    }
                });
                status.textContent = `${matching.length} patient${matching.length === 1 ? '' : 's'} - Page ${page}/${pages}`;
                previous.disabled = page <= 1;
                next.disabled = page >= pages;
            };
            input.addEventListener('input', () => {
                query = input.value.trim().toLowerCase();
                page = 1;
                render();
            });
            previous.addEventListener('click', () => {
                page--;
                render();
            });
            next.addEventListener('click', () => {
                page++;
                render();
            });
            table.addEventListener('click', (event) => {
                const button = event.target.closest('[data-consultation-records-toggle]');
                if (!button) return;
                const row = button.closest('tr');
                const key = patientKeyFor(row);
                if (button.textContent.trim().toLowerCase().startsWith('hide')) {
                    openPatients.delete(key);
                } else {
                    openPatients.add(key);
                }
            }, true);
            render();
        });

        document.querySelectorAll('.admin-maternal-patient-table').forEach((table) => {
            const body = table.tBodies[0];
            if (!body) return;
            const patientRows = Array.from(body.querySelectorAll('.admin-maternal-patient-row'));
            if (!patientRows.length) return;
            const pageSize = 5;
            let page = 1;
            let query = '';
            const openPatients = new Set();
            const toolbar = document.createElement('div');
            toolbar.className = 'flex items-center justify-between gap-2 border-b bg-gray-50 p-2 text-xs';
            toolbar.innerHTML = `<input aria-label="Search maternal records" class="w-full max-w-xs rounded border p-2" placeholder="Search patient name..."><span class="whitespace-nowrap"></span><div class="flex gap-1"><button type="button" class="rounded border bg-white px-2 py-1">Previous</button><button type="button" class="rounded border bg-white px-2 py-1">Next</button></div>`;
            table.parentElement.insertBefore(toolbar, table);
            const input = toolbar.querySelector('input');
            const status = toolbar.querySelector('span');
            const [previous, next] = toolbar.querySelectorAll('button');
            const patientKeyFor = (row) => row.cells[0]?.textContent.trim().toLowerCase() || '';
            const rowMatches = (row) => {
                const detail = row.nextElementSibling;
                return !query || `${row.textContent} ${detail?.textContent || ''}`.toLowerCase().includes(query);
            };
            const render = () => {
                const matching = patientRows.filter(rowMatches);
                const pages = Math.max(1, Math.ceil(matching.length / pageSize));
                page = Math.min(page, pages);
                patientRows.forEach((row) => {
                    row.hidden = true;
                    row.nextElementSibling?.classList.add('hidden');
                    const button = row.querySelector('[data-maternal-records-toggle]');
                    if (button) button.textContent = 'View records';
                });
                matching.slice((page - 1) * pageSize, page * pageSize).forEach((row) => {
                    row.hidden = false;
                    const key = patientKeyFor(row);
                    const detail = row.nextElementSibling;
                    const button = row.querySelector('[data-maternal-records-toggle]');
                    if (openPatients.has(key) && detail?.classList.contains('admin-maternal-detail-row')) {
                        detail.classList.remove('hidden');
                        if (button) button.textContent = 'Hide records';
                    }
                });
                status.textContent = `${matching.length} patient${matching.length === 1 ? '' : 's'} - Page ${page}/${pages}`;
                previous.disabled = page <= 1;
                next.disabled = page >= pages;
            };
            input.addEventListener('input', () => {
                query = input.value.trim().toLowerCase();
                page = 1;
                render();
            });
            previous.addEventListener('click', () => {
                page--;
                render();
            });
            next.addEventListener('click', () => {
                page++;
                render();
            });
            table.addEventListener('click', (event) => {
                const button = event.target.closest('[data-maternal-records-toggle]');
                if (!button) return;
                const row = button.closest('tr');
                const key = patientKeyFor(row);
                if (button.textContent.trim().toLowerCase().startsWith('hide')) {
                    openPatients.delete(key);
                } else {
                    openPatients.add(key);
                }
            }, true);
            render();
        });

        const dashboardHeader = document.querySelector('.dashboard-header');
        const adminScrollContainer = document.querySelector('.admin-main');
        const featureDrawerNav = document.querySelector('#feature-drawer nav');
        const scrollTopButton = document.querySelector('.scroll-top-button');
        const adminTabScrollKey = 'rhuAdminDashboardScroll';
        const adminNavScrollKey = 'rhuAdminDashboardNavScroll';
        const saveAdminTabScroll = () => {
            const scrollTarget = adminScrollContainer && getComputedStyle(adminScrollContainer).overflowY !== 'visible' ?
                adminScrollContainer.scrollTop :
                window.scrollY;
            sessionStorage.setItem(adminTabScrollKey, String(Math.max(0, Number(scrollTarget) || 0)));
            if (featureDrawerNav) {
                sessionStorage.setItem(adminNavScrollKey, String(Math.max(0, Number(featureDrawerNav.scrollTop) || 0)));
            }
        };
        const restoreAdminTabScroll = () => {
            const savedScroll = Number.parseFloat(sessionStorage.getItem(adminTabScrollKey) || '0');
            const savedNavScroll = Number.parseFloat(sessionStorage.getItem(adminNavScrollKey) || '0');
            requestAnimationFrame(() => {
                if (featureDrawerNav && Number.isFinite(savedNavScroll)) {
                    featureDrawerNav.scrollTop = Math.max(0, savedNavScroll);
                }
                if (Number.isFinite(savedScroll) && savedScroll > 0) {
                    if (adminScrollContainer && getComputedStyle(adminScrollContainer).overflowY !== 'visible') {
                        adminScrollContainer.scrollTop = Math.max(0, savedScroll);
                    } else {
                        window.scrollTo({
                            top: Math.max(0, savedScroll),
                            behavior: 'auto'
                        });
                    }
                }
            });
        };
        document.addEventListener('click', event => {
            const clickedLink = event.target.closest('a[href*="tab="]');
            if (!clickedLink) return;
            saveAdminTabScroll();
        });
        window.addEventListener('beforeunload', saveAdminTabScroll);
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        const updateScrollEffects = () => {
            const scrollY = adminScrollContainer?.scrollTop ?? window.scrollY;
            const scrolled = scrollY > 18;
            dashboardHeader?.classList.toggle('is-scrolled', scrolled);
            scrollTopButton?.classList.toggle('is-visible', scrollY > 420);
        };
        window.addEventListener('scroll', updateScrollEffects, {
            passive: true
        });
        adminScrollContainer?.addEventListener('scroll', updateScrollEffects, {
            passive: true
        });
        restoreAdminTabScroll();
        updateScrollEffects();
        scrollTopButton?.addEventListener('click', () => {
            if (adminScrollContainer && getComputedStyle(adminScrollContainer).overflowY !== 'visible') {
                adminScrollContainer.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            } else {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
            saveAdminTabScroll();
        });

        const revealTargets = new Set([
            ...document.querySelectorAll('main > div > div, main > div > section, main .grid > .bg-white, main details')
        ]);
        revealTargets.forEach((element) => {
            element.dataset.scrollReveal = '';
            element.classList.add('is-visible');
            element.style.setProperty('--reveal-delay', '0ms');
        });

        document.querySelectorAll('main .text-2xl.font-black').forEach((value, index) => {
            value.classList.add('metric-value-pop');
            value.style.animationDelay = `${Math.min(index, 8) * 45}ms`;
        });

        document.querySelectorAll('[data-auto-dismiss-flash]').forEach((flash) => {
            window.setTimeout(() => {
                flash.style.opacity = '0';
                flash.style.transform = 'translateY(-6px)';
                flash.style.maxHeight = '0';
                flash.style.margin = '0';
                flash.style.paddingTop = '0';
                flash.style.paddingBottom = '0';
                flash.style.overflow = 'hidden';
                window.setTimeout(() => flash.remove(), 320);
            }, 1000);
        });

        const eventImageUrlInput = document.getElementById('eventImageUrl');
        const eventImagePreview = document.getElementById('eventImagePreview');
        if (eventImageUrlInput && eventImagePreview) {
            const previewImage = eventImagePreview.querySelector('[data-event-preview-image]');
            const previewStatus = eventImagePreview.querySelector('[data-event-preview-status]');
            const previewLink = eventImagePreview.querySelector('[data-event-preview-link]');

            previewImage.addEventListener('load', () => {
                previewImage.classList.remove('hidden');
                previewStatus.className = 'rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700';
                previewStatus.textContent = 'Image link verified. This exact picture will be displayed.';
                eventImageUrlInput.setCustomValidity('');
            });

            previewImage.addEventListener('error', () => {
                previewImage.classList.add('hidden');
                previewImage.removeAttribute('src');
                previewStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                previewStatus.textContent = 'This is a webpage link, not a direct image. Upload the picture or paste a direct JPG, PNG, WEBP, or image-CDN URL.';
                eventImageUrlInput.setCustomValidity('Please provide a direct image URL or remove this link and upload the image.');
            });

            const updateEventImagePreview = () => {
                const url = eventImageUrlInput.value.trim();
                const validUrl = /^https?:\/\/\S+$/i.test(url);
                eventImagePreview.classList.toggle('hidden', !validUrl);
                eventImageUrlInput.setCustomValidity('');
                if (!validUrl) {
                    previewImage.classList.add('hidden');
                    previewImage.removeAttribute('src');
                    previewLink.textContent = '';
                    return;
                }
                previewImage.classList.add('hidden');
                previewStatus.className = 'rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700';
                previewStatus.textContent = 'Checking image link...';
                previewImage.src = url;
                previewLink.textContent = url;
            };
            eventImageUrlInput.addEventListener('input', updateEventImagePreview);
            updateEventImagePreview();
        }

        const eventSourceUrlInput = document.getElementById('eventSourceUrl');
        const eventImageFileInput = document.getElementById('eventImageFile');
        if (eventSourceUrlInput && eventImagePreview) {
            const resolvedImage = eventImagePreview.querySelector('[data-event-preview-image]');
            const resolvedFacebook = eventImagePreview.querySelector('[data-event-preview-facebook]');
            const resolutionStatus = eventImagePreview.querySelector('[data-event-preview-status]');
            const resolutionLink = eventImagePreview.querySelector('[data-event-preview-link]');
            let resolutionTimer = null;
            let resolutionController = null;

            resolvedImage.addEventListener('load', () => {
                resolvedFacebook.classList.add('hidden');
                resolvedFacebook.removeAttribute('src');
                resolvedImage.classList.remove('hidden');
                resolutionStatus.className = 'rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700';
                resolutionStatus.textContent = resolvedImage.dataset.previewSource === 'upload' ?
                    'Uploaded picture is ready and will be displayed.' :
                    'Picture found from the link. This image will be displayed.';
                eventSourceUrlInput.setCustomValidity('');
            });

            resolvedImage.addEventListener('error', () => {
                resolvedImage.classList.add('hidden');
                resolvedImage.removeAttribute('src');
                resolutionStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                const isUploadPreview = resolvedImage.dataset.previewSource === 'upload';
                resolutionStatus.textContent = isUploadPreview ?
                    'This file could not be previewed. Please choose another image.' :
                    'The page provided an image, but its host blocked the preview. Upload the picture instead.';
                if (!isUploadPreview) {
                    eventSourceUrlInput.setCustomValidity('The resolved picture cannot be displayed. Upload the image instead.');
                }
            });

            const resolveEventSourceLink = () => {
                const pageUrl = eventSourceUrlInput.value.trim();
                const validUrl = /^https?:\/\/\S+$/i.test(pageUrl);
                window.clearTimeout(resolutionTimer);
                resolutionController?.abort();
                eventImagePreview.classList.toggle('hidden', !validUrl);
                eventSourceUrlInput.setCustomValidity('');

                if (!validUrl) {
                    resolvedImage.classList.add('hidden');
                    resolvedImage.removeAttribute('src');
                    resolvedFacebook.classList.add('hidden');
                    resolvedFacebook.removeAttribute('src');
                    resolutionLink.textContent = '';
                    return;
                }

                if (eventImageFileInput) {
                    eventImageFileInput.value = '';
                }
                resolvedImage.dataset.previewSource = 'link';
                resolvedImage.classList.add('hidden');
                resolvedImage.removeAttribute('src');
                resolvedFacebook.classList.add('hidden');
                resolvedFacebook.removeAttribute('src');
                resolutionStatus.className = 'rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700';
                resolutionStatus.textContent = 'Reading the link and finding its picture...';
                resolutionLink.textContent = pageUrl;

                resolutionTimer = window.setTimeout(async () => {
                    resolutionController = new AbortController();
                    try {
                        const endpoint = `RHUAdminDashboard.php?resolve_event_image=1&url=${encodeURIComponent(pageUrl)}`;
                        const response = await fetch(endpoint, {
                            signal: resolutionController.signal,
                            headers: {
                                Accept: 'application/json'
                            }
                        });
                        const result = await response.json();
                        if (eventSourceUrlInput.value.trim() !== pageUrl) return;
                        if (!result.ok || !result.resolved_url) {
                            resolutionStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                            resolutionStatus.textContent = result.error || 'No picture could be found on this link.';
                            eventSourceUrlInput.setCustomValidity(result.error || 'No picture could be found on this link.');
                            return;
                        }
                        if (result.render_type === 'facebook_embed' && result.embed_url) {
                            resolvedImage.classList.add('hidden');
                            resolvedImage.removeAttribute('src');
                            resolvedFacebook.src = result.embed_url;
                            resolvedFacebook.classList.remove('hidden');
                            resolutionStatus.className = 'rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700';
                            resolutionStatus.textContent = 'Public Facebook post found. Its post picture will be displayed.';
                            resolutionLink.textContent = pageUrl;
                            eventSourceUrlInput.setCustomValidity('');
                            return;
                        }
                        resolutionLink.textContent = result.resolved_url === pageUrl ?
                            pageUrl :
                            `Page link: ${pageUrl}\nPicture found: ${result.resolved_url}`;
                        resolvedImage.src = result.resolved_url;
                    } catch (error) {
                        if (error.name === 'AbortError') return;
                        resolutionStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                        resolutionStatus.textContent = 'The server could not read this link. Upload the picture instead.';
                        eventSourceUrlInput.setCustomValidity('The server could not read this link.');
                    }
                }, 500);
            };

            eventSourceUrlInput.addEventListener('input', resolveEventSourceLink);
            resolveEventSourceLink();
        }

        if (eventImageFileInput && eventImagePreview) {
            const uploadedPreviewImage = eventImagePreview.querySelector('[data-event-preview-image]');
            const uploadedFacebookPreview = eventImagePreview.querySelector('[data-event-preview-facebook]');
            const uploadedPreviewStatus = eventImagePreview.querySelector('[data-event-preview-status]');
            const uploadedPreviewLink = eventImagePreview.querySelector('[data-event-preview-link]');
            let uploadedObjectUrl = '';

            eventImageFileInput.addEventListener('change', () => {
                const file = eventImageFileInput.files?.[0];
                if (uploadedObjectUrl) {
                    URL.revokeObjectURL(uploadedObjectUrl);
                    uploadedObjectUrl = '';
                }
                eventImageFileInput.setCustomValidity('');

                if (!file) {
                    if (!eventSourceUrlInput?.value.trim()) {
                        eventImagePreview.classList.add('hidden');
                        uploadedPreviewImage.classList.add('hidden');
                        uploadedPreviewImage.removeAttribute('src');
                        uploadedPreviewLink.textContent = '';
                    }
                    return;
                }

                if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                    eventImageFileInput.setCustomValidity('Please choose a JPG, PNG, WEBP, or GIF image.');
                    eventImageFileInput.reportValidity();
                    eventImagePreview.classList.remove('hidden');
                    uploadedPreviewStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                    uploadedPreviewStatus.textContent = 'Please choose a JPG, PNG, WEBP, or GIF image.';
                    return;
                }
                if (file.size > 8 * 1024 * 1024) {
                    eventImageFileInput.setCustomValidity('The picture must be no larger than 8 MB.');
                    eventImageFileInput.reportValidity();
                    eventImagePreview.classList.remove('hidden');
                    uploadedPreviewStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                    uploadedPreviewStatus.textContent = 'The picture must be no larger than 8 MB.';
                    return;
                }

                if (eventSourceUrlInput) {
                    eventSourceUrlInput.value = '';
                    eventSourceUrlInput.setCustomValidity('');
                }
                uploadedFacebookPreview.classList.add('hidden');
                uploadedFacebookPreview.removeAttribute('src');
                uploadedPreviewImage.dataset.previewSource = 'upload';
                uploadedPreviewImage.classList.add('hidden');
                uploadedPreviewStatus.className = 'rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700';
                uploadedPreviewStatus.textContent = 'Loading uploaded picture preview...';
                uploadedPreviewLink.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                eventImagePreview.classList.remove('hidden');
                uploadedObjectUrl = URL.createObjectURL(file);
                uploadedPreviewImage.src = uploadedObjectUrl;
            });
        }
    </script>
    <?php if ($tab === 'staff'): ?>
        <script>
            (() => {
                const livePanelIds = ['staff-folder-live-panel', 'staff-personnel-live-panel', 'staff-records-live-panel'];
                let staffRefreshBusy = false;
                const refreshStaffFolders = async () => {
                    if (staffRefreshBusy || document.hidden) return;
                    if (document.querySelector('[data-floating-modal]:not(.hidden), form:focus-within')) return;
                    staffRefreshBusy = true;
                    const scrollContainer = document.querySelector('.admin-main');
                    const scrollTop = scrollContainer?.scrollTop ?? window.scrollY;
                    const openResidents = new Set(Array.from(document.querySelectorAll('#staff-records-live-panel .assigned-resident-row')).filter((row) => {
                        return !row.nextElementSibling?.classList.contains('hidden');
                    }).map((row) => row.querySelector('[data-resident-filter]')?.dataset.residentFilter).filter(Boolean));
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('_live_staff_folders', String(Date.now()));
                        const response = await fetch(url.toString(), {
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        if (!response.ok) return;
                        const html = await response.text();
                        const nextDoc = new DOMParser().parseFromString(html, 'text/html');
                        livePanelIds.forEach((id) => {
                            const current = document.getElementById(id);
                            const next = nextDoc.getElementById(id);
                            if (current && next) current.replaceWith(next);
                        });
                        openResidents.forEach((residentKey) => {
                            const residentCell = document.querySelector(`#staff-records-live-panel [data-resident-filter="${CSS.escape(residentKey)}"]`);
                            const row = residentCell?.closest('tr');
                            const detailRow = row?.nextElementSibling;
                            const button = row?.querySelector('[data-assigned-records-toggle]');
                            if (detailRow?.classList.contains('assigned-record-detail-row')) {
                                detailRow.classList.remove('hidden');
                                if (button) button.textContent = 'Hide records';
                            }
                        });
                        if (typeof normalizeAssignedResidentRecords === 'function') normalizeAssignedResidentRecords();
                        if (window.lucide) window.lucide.createIcons();
                        if (scrollContainer) {
                            scrollContainer.scrollTop = scrollTop;
                        } else {
                            window.scrollTo(0, scrollTop);
                        }
                    } catch (error) {
                        console.error('Staff folder live refresh failed:', error);
                    } finally {
                        staffRefreshBusy = false;
                    }
                };
                // Staff panels contain bound controls and must not be replaced by a timer.
            })();
        </script>
    <?php endif; ?>
    <?php echo portalRenderNotificationPanel(); ?>
    <script>
        (() => {
            document.addEventListener('change', (event) => {
                const select = event.target.closest('[data-disease-selection]');
                if (!select) return;
                const input = select.closest('form')?.querySelector('[data-new-disease-name]');
                if (!input) return;
                const isNew = select.value === 'new';
                input.disabled = !isNew;
                input.required = isNew;
                input.classList.toggle('hidden', !isNew);
                if (isNew) input.focus();
            });
            document.addEventListener('click', (event) => {
                const input = event.target.closest('[data-disease-case-date]');
                if (!input || typeof input.showPicker !== 'function') return;
                try { input.showPicker(); } catch (error) { input.focus(); }
            });
            window.normalizeAssignedResidentRecords = () => {
                document.querySelectorAll('[data-assigned-records-toggle]').forEach((button) => {
                    const row = button.closest('tr');
                    const detailRow = row?.nextElementSibling;
                    if (!row || !detailRow?.classList.contains('assigned-record-detail-row')) return;
                    button.textContent = detailRow.classList.contains('hidden') ? 'View records' : 'Hide records';
                });
            };

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-assigned-records-toggle]');
                if (!button) return;
                event.preventDefault();
                event.stopPropagation();
                const row = button.closest('tr');
                const detailRow = row?.nextElementSibling;
                if (!detailRow?.classList.contains('assigned-record-detail-row')) return;
                const isHidden = detailRow.classList.toggle('hidden');
                button.textContent = isHidden ? 'View records' : 'Hide records';
            });

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-consultation-records-toggle]');
                if (!button) return;
                event.preventDefault();
                event.stopPropagation();
                const row = button.closest('tr');
                const detailRow = row?.nextElementSibling;
                if (!detailRow?.classList.contains('admin-consultation-detail-row')) return;
                const isHidden = detailRow.classList.toggle('hidden');
                button.textContent = isHidden ? 'View records' : 'Hide records';
            });

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-maternal-records-toggle]');
                if (!button) return;
                event.preventDefault();
                event.stopPropagation();
                const row = button.closest('tr');
                const detailRow = row?.nextElementSibling;
                if (!detailRow?.classList.contains('admin-maternal-detail-row')) return;
                const isHidden = detailRow.classList.toggle('hidden');
                button.textContent = isHidden ? 'View records' : 'Hide records';
            });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', window.normalizeAssignedResidentRecords);
            } else {
                window.normalizeAssignedResidentRecords();
            }
        })();
    </script>
    <style id="rhu-final-action-button-colors">
        body.rhu-admin-ui .admin-main :where(button:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), button[type="button"]:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), button[type="submit"], form button, td button:not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), .inline button, a[class*="bg-"], [data-certificate-preview-download]) {
            appearance: none !important;
            background: #0f8f7e !important;
            background-color: #0f8f7e !important;
            background-image: none !important;
            border: 1px solid #087464 !important;
            border-radius: 8px !important;
            color: #ffffff !important;
            box-shadow: 0 8px 18px rgba(15, 143, 126, .14) !important;
            text-shadow: none !important;
        }

        body.rhu-admin-ui .admin-main :where(button:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), button[type="button"]:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), button[type="submit"], form button, td button:not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), .inline button, a[class*="bg-"], [data-certificate-preview-download]) :where(svg, span, strong, i) {
            color: #ffffff !important;
            fill: none !important;
            stroke: #ffffff !important;
        }

        body.rhu-admin-ui .admin-main :where(button:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), button[type="button"]:not([data-admin-message-thread-toggle]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), button[type="submit"], form button, td button:not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), .inline button, a[class*="bg-"], [data-certificate-preview-download]):hover {
            background: #0b7769 !important;
            background-color: #0b7769 !important;
            background-image: none !important;
            border-color: #065f55 !important;
            color: #ffffff !important;
        }

        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-message-thread-toggle],
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-message-thread-toggle]:hover,
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message],
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message]:hover {
            appearance: none !important;
            background: transparent !important;
            background-color: transparent !important;
            background-image: none !important;
            border: 0 !important;
            color: #111827 !important;
            box-shadow: none !important;
            text-shadow: none !important;
        }

        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-message-thread-toggle] * {
            color: inherit !important;
            text-shadow: none !important;
        }

        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message] *,
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message]:hover * {
            text-shadow: none !important;
        }

        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message] .text-gray-900,
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message]:hover .text-gray-900 {
            color: #111827 !important;
        }

        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message] .text-gray-700,
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message]:hover .text-gray-700 {
            color: #374151 !important;
        }

        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message] .text-gray-400,
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message]:hover .text-gray-400 {
            color: #9ca3af !important;
        }

        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message] .text-teal-700,
        body.rhu-admin-ui .admin-main [data-message-cleanup-panel] button[data-admin-reply-message]:hover .text-teal-700 {
            color: #0f766e !important;
        }

        #admin-message-list button[data-admin-reply-message],
        #admin-message-list button[data-admin-message-thread-toggle],
        #admin-message-list .admin-message-reply {
            display: block !important;
            background: transparent !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            transform: none !important;
            text-align: left !important;
            color: #1f2937 !important;
            padding: 12px 0 !important;
        }

        #admin-message-list button[data-admin-reply-message] {
            border-bottom: 1px solid #e5e7eb !important;
        }

        #admin-message-list button[data-admin-reply-message] > div {
            display: flex !important;
            justify-content: space-between !important;
            text-align: left !important;
        }

        #admin-message-list button[data-admin-reply-message] *,
        #admin-message-list .admin-message-reply * {
            color: #1f2937 !important;
            font-size: 14px !important;
            line-height: 1.6 !important;
            overflow-wrap: anywhere;
        }

        #admin-message-list button[data-admin-reply-message] .text-gray-400 {
            color: #4b5563 !important;
            font-size: 12px !important;
        }

        #admin-message-list button:focus-visible {
            outline: 2px solid #6b7280 !important;
            outline-offset: 2px;
        }

        body.rhu-admin-ui .admin-main .assigned-records-table span.resident-record-type,
        body.rhu-admin-ui .admin-main .assigned-records-table span.resident-record-type:hover,
        body.rhu-admin-ui .admin-main .assigned-records-table .resident-record-type {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        body.rhu-admin-ui .admin-main table,
        body.rhu-admin-ui .admin-main table *,
        body.rhu-admin-ui .admin-main table tr,
        body.rhu-admin-ui .admin-main table td,
        body.rhu-admin-ui .admin-main table th,
        body.rhu-admin-ui .admin-main table button,
        body.rhu-admin-ui .admin-main table a {
            transform: none !important;
            transition-property: background-color, color, border-color !important;
            transition-duration: 0s !important;
            animation: none !important;
        }

        body.rhu-admin-ui .admin-main table tr:hover,
        body.rhu-admin-ui .admin-main table button:hover,
        body.rhu-admin-ui .admin-main table a:hover {
            transform: none !important;
            box-shadow: none !important;
        }

        body.rhu-admin-ui .admin-main table td,
        body.rhu-admin-ui .admin-main table th {
            vertical-align: middle !important;
        }

        @media (max-width: 1280px) {
            body.rhu-admin-ui .reference-dashboard > .grid {
                grid-template-columns: 1fr !important;
            }

            body.rhu-admin-ui .reference-staff-mix {
                display: grid !important;
                grid-template-columns: minmax(0, .9fr) minmax(260px, 1fr) !important;
                gap: 1rem !important;
                align-items: center !important;
            }

            body.rhu-admin-ui .reference-donut-wrap {
                grid-template-columns: minmax(180px, 260px) minmax(180px, 1fr) !important;
            }
        }

        @media (min-width: 1281px) {
            body.rhu-admin-ui .reference-staff-mix {
                height: 100% !important;
                max-height: 100% !important;
            }

            body.rhu-admin-ui .reference-staff-mix .reference-pie {
                width: min(235px, 70%) !important;
            }
        }

        body.rhu-admin-ui .reference-staff-mix {
            overflow: hidden !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-donut-wrap {
            grid-template-columns: minmax(150px, 230px) minmax(0, 1fr) !important;
            column-gap: 1rem !important;
            width: 100% !important;
        }

        body.rhu-admin-ui .reference-staff-mix .unified-donut {
            width: min(205px, 100%) !important;
        }

        body.rhu-admin-ui .reference-staff-mix .space-y-5 {
            min-width: 0 !important;
        }

        body.rhu-admin-ui .reference-staff-mix .space-y-5 p {
            gap: .75rem !important;
            min-width: 0 !important;
            font-size: clamp(.9rem, 1.25vw, 1rem) !important;
        }

        body.rhu-admin-ui .reference-staff-mix {
            min-height: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            align-self: stretch !important;
        }

        body.rhu-admin-ui .reference-pie-wrap {
            flex: 1 !important;
            display: grid !important;
            grid-template-columns: 1fr !important;
            align-content: center !important;
            justify-items: center !important;
            gap: 1.25rem !important;
            margin-top: 1.25rem !important;
        }

        body.rhu-admin-ui .reference-pie {
            width: min(255px, 78vw) !important;
            box-shadow: 0 22px 42px rgba(7, 84, 72, .12) !important;
        }

        body.rhu-admin-ui .reference-pie::after {
            display: none !important;
        }

        body.rhu-admin-ui .reference-pie-info {
            display: grid !important;
            width: 100% !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: .7rem !important;
        }

        body.rhu-admin-ui .reference-pie-info p {
            min-width: 0 !important;
            border: 1px solid #bfe9dc !important;
            border-radius: 8px !important;
            background: #f8fffc !important;
            padding: .7rem .8rem !important;
            font-size: .95rem !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-wrap {
            display: flex !important;
            flex: 1 1 auto !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            min-height: 0 !important;
            gap: .9rem !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-wrap > .flex {
            display: flex !important;
            width: 100% !important;
            justify-content: center !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie {
            display: block !important;
            flex: 0 0 auto !important;
            width: min(220px, 62vw) !important;
            height: auto !important;
            aspect-ratio: 1 / 1 !important;
            border-radius: 50% !important;
            background: conic-gradient(#0b7f6f 0 var(--users-pct), #e89b15 var(--users-pct) calc(var(--users-pct) + var(--staff-pct)), #397bd1 calc(var(--users-pct) + var(--staff-pct)) 100%) !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-info p:nth-child(1) i {
            background: #0b7f6f !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-info p:nth-child(2) i {
            background: #e89b15 !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-info p:nth-child(3) i {
            background: #397bd1 !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-info {
            display: grid !important;
            width: 100% !important;
            grid-template-columns: 1fr !important;
            gap: .55rem !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-info p {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: .75rem !important;
            white-space: normal !important;
            min-height: 42px !important;
            padding: .55rem .75rem !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-info p span {
            display: inline-flex !important;
            min-width: 0 !important;
            align-items: center !important;
            line-height: 1.15 !important;
            overflow-wrap: anywhere !important;
        }

        body.rhu-admin-ui .reference-staff-mix .reference-pie-info p strong {
            flex: 0 0 auto !important;
            font-size: 1.05rem !important;
        }

        @media (max-width: 900px) {
            body.rhu-admin-ui .admin-main {
                padding-inline: .75rem !important;
            }

            body.rhu-admin-ui .admin-page-heading {
                align-items: flex-start !important;
                gap: .9rem !important;
                padding: .9rem !important;
            }

            body.rhu-admin-ui .admin-page-heading > div:last-child {
                width: 100% !important;
                justify-content: space-between !important;
            }

            body.rhu-admin-ui .reference-staff-mix,
            body.rhu-admin-ui .reference-donut-wrap {
                grid-template-columns: 1fr !important;
            }

            body.rhu-admin-ui .reference-donut-wrap .unified-donut {
                width: min(210px, 62vw) !important;
                margin-inline: auto !important;
            }

            body.rhu-admin-ui .reference-pie-info {
                grid-template-columns: 1fr !important;
            }

            body.rhu-admin-ui .reference-chart .unified-chart-card {
                min-height: 210px !important;
                overflow-x: auto !important;
                overflow-y: hidden !important;
                padding-bottom: .35rem !important;
            }

            body.rhu-admin-ui .reference-chart .unified-chart-svg {
                min-width: 620px !important;
                height: 205px !important;
            }
        }

        @media (min-width: 901px) and (max-width: 1180px) {
            body.rhu-admin-ui .reference-staff-mix .reference-donut-wrap {
                grid-template-columns: 1fr !important;
                justify-items: center !important;
            }

            body.rhu-admin-ui .reference-staff-mix .space-y-5 {
                width: min(100%, 260px) !important;
            }
        }

        @media (max-width: 640px) {
            body.rhu-admin-ui .admin-shell-header > div {
                padding-inline: .75rem !important;
            }

            body.rhu-admin-ui .admin-shell-header .staff-logout-label,
            body.rhu-admin-ui .admin-shell-header .hidden.min-\[420px\]\:block {
                display: none !important;
            }

            body.rhu-admin-ui .admin-page-heading h2 {
                font-size: 1.1rem !important;
                line-height: 1.2 !important;
            }

            body.rhu-admin-ui .admin-page-heading p {
                font-size: .78rem !important;
            }

            body.rhu-admin-ui .admin-page-heading-icon {
                width: 44px !important;
                height: 44px !important;
                flex-basis: 44px !important;
            }

            body.rhu-admin-ui .reference-stat {
                min-height: 138px !important;
                padding: .85rem !important;
            }

            body.rhu-admin-ui .reference-stat .text-3xl {
                font-size: 1.7rem !important;
            }

            body.rhu-admin-ui .reference-panel {
                padding: .9rem !important;
            }

            body.rhu-admin-ui .reference-chart .unified-chart-svg {
                min-width: 560px !important;
            }

            body.rhu-admin-ui .reference-shortcut-icon {
                width: 46px !important;
                height: 46px !important;
                flex-basis: 46px !important;
            }

            body.rhu-admin-ui .reference-activity table,
            body.rhu-admin-ui [data-certificate-cleanup-panel] table {
                min-width: 760px !important;
            }
        }

        body.rhu-admin-ui .admin-shell-header {
            min-height: 56px !important;
        }

        body.rhu-admin-ui .admin-shell-header > div {
            padding-block: .45rem !important;
        }

        body.rhu-admin-ui .admin-shell-header [data-drawer-open],
        body.rhu-admin-ui .admin-shell-header :is(button, a.staff-logout-trigger) {
            min-height: 38px !important;
            height: 38px !important;
        }

        body.rhu-admin-ui .admin-shell-header [data-drawer-open],
        body.rhu-admin-ui .admin-shell-header button {
            width: 38px !important;
        }

        body.rhu-admin-ui .admin-shell-header .staff-logout-trigger {
            width: auto !important;
            padding-inline: .8rem !important;
        }

        body.rhu-admin-ui .admin-shell-header .flex.h-9.w-9 {
            width: 34px !important;
            height: 34px !important;
        }

        body.rhu-admin-ui .admin-shell-header,
        body.rhu-admin-ui .admin-shell-header :is(span, p, a, button, svg) {
            color: #0b3b2f !important;
        }

        body.rhu-admin-ui .admin-shell-header [class*="tracking-"] {
            color: #0b3b2f !important;
            opacity: 1 !important;
            text-shadow: none !important;
        }

        body.rhu-admin-ui .admin-shell-header svg {
            stroke: #0b3b2f !important;
        }

        body.rhu-admin-ui .reference-shortcut {
            display: grid !important;
            grid-template-columns: 58px minmax(0, 1fr) 20px !important;
            align-items: center !important;
            gap: 1rem !important;
            min-height: 96px !important;
            padding: 1rem 1.2rem !important;
        }

        body.rhu-admin-ui .reference-shortcut > span:nth-child(2) {
            min-width: 0 !important;
            display: block !important;
        }

        body.rhu-admin-ui .reference-shortcut strong {
            display: block !important;
            color: #102217 !important;
            font-size: clamp(1rem, 1vw, 1.12rem) !important;
            line-height: 1.2 !important;
            font-weight: 900 !important;
            white-space: normal !important;
            overflow-wrap: normal !important;
        }

        body.rhu-admin-ui .reference-shortcut > span:nth-child(2) > span {
            display: block !important;
            margin-top: .35rem !important;
            color: #596b57 !important;
            font-size: .92rem !important;
            line-height: 1.35 !important;
            font-weight: 700 !important;
            white-space: normal !important;
            overflow-wrap: normal !important;
            max-width: 15rem !important;
        }

        body.rhu-admin-ui .reference-shortcut > span:last-child {
            justify-self: end !important;
            color: #102217 !important;
        }

        body.rhu-admin-ui .admin-main {
            margin-top: 58px !important;
            height: calc(100vh - 58px) !important;
        }

        body.rhu-admin-ui .reference-stat {
            display: grid !important;
            grid-template-rows: 42px minmax(40px, auto) 38px auto !important;
            align-content: start !important;
            align-items: start !important;
            gap: 10px !important;
            min-width: 0 !important;
            min-height: 210px !important;
            padding: 18px !important;
            border-radius: 8px !important;
            box-shadow: 0 3px 12px rgba(15, 65, 86, .04) !important;
        }

        body.rhu-admin-ui .reference-stat > p {
            margin: 0 !important;
            letter-spacing: 0 !important;
        }

        body.rhu-admin-ui .reference-stat > p:nth-of-type(1) {
            min-height: 40px !important;
            font-size: 14px !important;
            line-height: 20px !important;
            font-weight: 600 !important;
            color: #52615b !important;
        }

        body.rhu-admin-ui .reference-stat > p:nth-of-type(2) {
            font-size: 30px !important;
            line-height: 38px !important;
            font-weight: 800 !important;
            font-variant-numeric: tabular-nums;
            color: #17251e !important;
        }

        body.rhu-admin-ui .reference-stat .reference-stat-trend {
            display: block !important;
            font-size: 11px !important;
            line-height: 18px !important;
            font-weight: 500 !important;
            color: #66756e !important;
        }

        body.rhu-admin-ui .reference-stat .reference-stat-trend span {
            display: inline !important;
            margin-right: 4px !important;
            font-weight: 700 !important;
            white-space: nowrap;
        }
    </style>
    <script>
        (() => {
            const selector = '.admin-main button:not([data-admin-message-thread-toggle]):not([data-admin-reply-message]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), .admin-main button[type="button"]:not([data-admin-message-thread-toggle]):not([data-admin-reply-message]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), .admin-main button[type="submit"], .admin-main form button:not([data-admin-reply-message]), .admin-main td button:not([data-admin-reply-message]):not([data-assigned-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]):not([data-maternal-records-toggle]):not([data-consultation-records-toggle]):not([data-maternal-records-toggle]), .admin-main .inline button:not([data-admin-reply-message]), .admin-main a[class*="bg-"], .admin-main [data-certificate-preview-download]';
            const paintButtons = () => {
                document.querySelectorAll(selector).forEach((button) => {
                    if (button.closest('[data-message-cleanup-panel]') && button.matches('[data-admin-message-thread-toggle], [data-admin-reply-message]')) return;
                    button.style.setProperty('appearance', 'none', 'important');
                    button.style.setProperty('background', '#0f8f7e', 'important');
                    button.style.setProperty('background-color', '#0f8f7e', 'important');
                    button.style.setProperty('background-image', 'none', 'important');
                    button.style.setProperty('border', '1px solid #087464', 'important');
                    button.style.setProperty('border-radius', '8px', 'important');
                    button.style.setProperty('color', '#ffffff', 'important');
                    button.style.setProperty('box-shadow', '0 8px 18px rgba(15, 143, 126, .14)', 'important');
                    button.querySelectorAll('svg, span, strong, i').forEach((child) => {
                        child.style.setProperty('color', '#ffffff', 'important');
                        child.style.setProperty('stroke', '#ffffff', 'important');
                    });
                });
            };
            paintButtons();
            document.addEventListener('DOMContentLoaded', paintButtons);
            new MutationObserver(paintButtons).observe(document.body, {
                childList: true,
                subtree: true
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

<script src="assets/js/staff-record-scroll.js?v=4" defer></script>
<script src="assets/js/staff-resident-record-groups.js?v=2" defer></script>
</body>

</html>
