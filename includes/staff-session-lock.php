<?php
const RHU_STAFF_IDLE_SECONDS = 300;
function rhuLockStaffId(): int
{
    if (!empty($_SESSION['rhu_staff_login'])) return (int)($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
    if (!empty($_SESSION['rhu_admin_authenticated'])) return (int)($_SESSION['user']['staff_id'] ?? $_SESSION['user']['user_id'] ?? 0);
    return 0;
}
function rhuLockReturnUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $allowed = ['DentalDashboard.php','NurseDashboard.php','MidwifeDashboard.php','MedTechDashboard.php','SanitaryDashboard.php','RHUAdminDashboard.php','StaffResidentRecords.php','StaffResidentAppointments.php','InventoryManagement.php','ResidentAccountRequests.php'];
    if (!is_string($path) || !in_array(basename($path), $allowed, true) || str_contains($url, chr(10)) || str_contains($url, chr(13)) || str_contains($url, chr(92))) return 'RHUAdminDashboard.php';
    $query = parse_url($url, PHP_URL_QUERY);
    return basename($path) . ($query ? '?' . $query : '');
}
function rhuStaffLockExpired(array $state, int $now): bool
{
    return !empty($state['locked']) || $now - (int)($state['last_activity'] ?? $now) >= RHU_STAFF_IDLE_SECONDS;
}
function rhuStaffLockState(int $id): array
{
    if ((int)($_SESSION['staff_screen_lock']['staff_id'] ?? 0) !== $id) {
        $_SESSION['staff_screen_lock'] = ['staff_id' => $id, 'last_activity' => time(), 'locked' => false, 'pin_reset_allowed' => true];
    }
    if (rhuStaffLockExpired($_SESSION['staff_screen_lock'], time())) $_SESSION['staff_screen_lock']['locked'] = true;
    if (empty($_SESSION['staff_lock_csrf'])) $_SESSION['staff_lock_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['staff_screen_lock'];
}
function rhuStaffPinValid(string $pin): bool
{
    return preg_match('/\A[0-9]{4,6}\z/', $pin) === 1;
}
function rhuStaffPinHash(PDO $pdo, int $id): string
{
    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'staff_screen_pins'")->fetchColumn();
    if (!$exists) return '';
    $stmt = $pdo->prepare('SELECT pin_hash FROM staff_screen_pins WHERE staff_id = :id');
    $stmt->execute(['id'=>$id]); return (string)($stmt->fetchColumn() ?: '');
}
function rhuEnforceStaffScreenLock(): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
    $id = rhuLockStaffId();
    if ($id <= 0) return;
    header('Cache-Control: no-store, private');
    $state = rhuStaffLockState($id);
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['StaffLock.php','StaffLogout.php','RHULogin.php','RHUAdminLogin.php','ResidentLogin.php'], true)) return;
    global $pdo;
    try {
        if (!$pdo instanceof PDO) throw new RuntimeException('Security settings unavailable');
        $setupRequired = rhuStaffPinHash($pdo, $id) === '';
    } catch (Throwable $error) {
        http_response_code(503); exit('Security settings are unavailable. Please try again.');
    }
    if (empty($state['locked']) && !$setupRequired) return;
    $json = isset($_GET['api']) || isset($_GET['ajax']) || isset($_POST['response_format'])
        || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    if ($json || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(423); header('Content-Type: application/json');
        echo json_encode(['success'=>false,'locked'=>true,'setup_required'=>$setupRequired,'error'=>'Enter your unlock PIN to continue.','lock_url'=>'StaffLock.php']);
    } else {
        $_SESSION['staff_lock_return'] = rhuLockReturnUrl($_SERVER['REQUEST_URI'] ?? '');
        header('Location: StaffLock.php' . ($setupRequired && empty($state['locked']) ? '?settings=1' : ''));
    }
    exit;
}
