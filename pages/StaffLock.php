<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/db.php';
header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
$id = rhuLockStaffId();
if ($id <= 0) {
    if (isset($_GET['api'])) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['authenticated'=>false]); exit; }
    header('Location: RHULogin.php'); exit;
}
$state = rhuStaffLockState($id);
/** @param mixed $value */
function lockEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$error = ''; $message = ''; $customHash = '';
$validCsrf = false;
try { if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable'); $customHash = rhuStaffPinHash($pdo, $id); }
catch (Throwable $exception) { $error = 'Security settings are unavailable. Please try again.'; }
if (isset($_GET['return'])) $_SESSION['staff_lock_return'] = rhuLockReturnUrl((string)$_GET['return']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $validCsrf = hash_equals($_SESSION['staff_lock_csrf'], (string)($_POST['csrf'] ?? ''));
    if (!$validCsrf) { http_response_code(403); $error = 'Please reload this page and try again.'; }
    elseif ($action === 'activity' && !$error && empty($state['locked'])) {
        $_SESSION['staff_screen_lock']['last_activity'] = time();
    } elseif ($action === 'lock') {
        $_SESSION['staff_screen_lock']['locked'] = true;
    } elseif (in_array($action, ['unlock','set_pin'], true) && !$error) {
        if ((int)($_SESSION['staff_lock_retry_after'] ?? 0) > time()) {
            $error = 'Too many attempts. Wait one minute before trying again.';
        } else {
            try {
                if (!$pdo instanceof PDO) throw new RuntimeException('Security settings are unavailable. Please try again.');
                $stmt = $pdo->prepare("SELECT id FROM health_workers WHERE id = :id AND LOWER(status) = 'active'");
                $stmt->execute(['id'=>$id]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Your staff account is unavailable. Sign out and contact the administrator.');
                $canReset = empty($state['locked']) && !empty($state['pin_reset_allowed']);
                if ($action === 'set_pin' && !empty($state['locked'])) throw new RuntimeException('Unlock the system before changing your PIN.');
                $needsCurrent = $action === 'unlock' || ($customHash !== '' && !$canReset);
                $pin = (string)($_POST['pin'] ?? '');
                if ($needsCurrent && ($customHash === '' || !rhuStaffPinValid($pin) || !password_verify($pin, $customHash))) {
                    $_SESSION['staff_lock_failures'] = (int)($_SESSION['staff_lock_failures'] ?? 0) + 1;
                    if ($_SESSION['staff_lock_failures'] >= 5) { $_SESSION['staff_lock_retry_after'] = time() + 60; $_SESSION['staff_lock_failures'] = 0; }
                    throw new RuntimeException('Incorrect PIN.');
                }
                if ($action === 'set_pin') {
                    $new = (string)($_POST['new_pin'] ?? '');
                    if (!rhuStaffPinValid($new)) throw new RuntimeException('Use 4 to 6 digits for your unlock PIN.');
                    if ($new !== (string)($_POST['confirm_pin'] ?? '')) throw new RuntimeException('The PINs do not match.');
                    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_screen_pins (staff_id BIGINT UNSIGNED PRIMARY KEY, pin_hash VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $save = $pdo->prepare('INSERT INTO staff_screen_pins (staff_id, pin_hash) VALUES (:id, :hash) ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash)');
                    $save->execute(['id'=>$id,'hash'=>password_hash($new, PASSWORD_DEFAULT)]);
                    $_SESSION['staff_screen_lock']['pin_reset_allowed'] = false;
                    $_SESSION['staff_screen_lock']['last_activity'] = time();
                    unset($_SESSION['staff_lock_failures'], $_SESSION['staff_lock_retry_after']);
                    header('Location: ' . rhuLockReturnUrl($_SESSION['staff_lock_return'] ?? 'RHUAdminDashboard.php')); exit;
                } else {
                    session_regenerate_id(true);
                    $_SESSION['staff_screen_lock'] = ['staff_id'=>$id,'last_activity'=>time(),'locked'=>false,'pin_reset_allowed'=>false];
                    unset($_SESSION['staff_lock_failures'], $_SESSION['staff_lock_retry_after']);
                    header('Location: ' . rhuLockReturnUrl($_SESSION['staff_lock_return'] ?? 'RHUAdminDashboard.php')); exit;
                }
            } catch (Throwable $exception) { $error = $exception instanceof PDOException ? 'Could not save security settings. Please try again.' : $exception->getMessage(); }
        }
    }
}
$state = rhuStaffLockState($id);
if (isset($_GET['api'])) {
    if ($error !== '') http_response_code(503);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$validCsrf) http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['authenticated'=>true,'locked'=>!empty($state['locked']),'setup_required'=>$customHash === '','last_activity'=>(int)$state['last_activity'],'server_time'=>time(),'timeout'=>RHU_STAFF_IDLE_SECONDS,'csrf'=>$_SESSION['staff_lock_csrf']]); exit;
}
$settings = (isset($_GET['settings']) || $customHash === '') && empty($state['locked']);
$needsCurrentPin = $customHash !== '' && empty($state['pin_reset_allowed']);
if (!$settings && empty($state['locked'])) { $_SESSION['staff_screen_lock']['locked'] = true; $state['locked'] = true; }
$name = $_SESSION['rhu_staff_login']['name'] ?? trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $settings ? 'Unlock PIN' : 'System locked' ?> | ResiHUnity</title>
<style> *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#103c35;color:#18332e;font:15px system-ui,sans-serif;padding:24px}main{background:white;width:100%;max-width:460px;border-radius:22px;padding:32px}h1{margin:0 0 12px}p{line-height:1.5;color:#526860}label{display:block;font-weight:650;margin:18px 0 6px}input{width:100%;padding:12px;border:1px solid #aabfb5;border-radius:8px;font:inherit}button{width:100%;padding:13px;margin-top:22px;border:0;border-radius:8px;background:#0f766e;color:white;font:700 15px system-ui}a{color:#0f766e}.error{color:#b91c1c}.success{color:#047857}small{display:block;margin-top:18px}</style></head><body><main>
<h1><?= $settings ? 'Create your unlock PIN' : 'System locked' ?></h1><p><?= lockEscape($name ?: 'RHU staff') ?></p>
<p><?= $settings ? 'The system locks after 5 minutes without activity. Create a separate 4 to 6 digit PIN to unlock it.' : 'Your session is protected. Enter your 4 to 6 digit PIN to continue.' ?></p>
<?php if ($error): ?><p class="error" role="alert"><?= lockEscape($error) ?></p><?php endif; ?><?php if ($message): ?><p class="success" role="status"><?= lockEscape($message) ?></p><?php endif; ?>
<?php if ($settings || $customHash !== ''): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= lockEscape($_SESSION['staff_lock_csrf']) ?>"><input type="hidden" name="action" value="<?= $settings ? 'set_pin' : 'unlock' ?>">
<?php if (!$settings || $needsCurrentPin): ?><label for="pin"><?= $settings ? 'Current unlock PIN' : 'Unlock PIN' ?></label><input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="off" required autofocus><?php endif; ?>
<?php if ($settings): ?><label for="new_pin">New unlock PIN (4 to 6 digits)</label><input id="new_pin" name="new_pin" type="password" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="off" required><label for="confirm_pin">Confirm unlock PIN</label><input id="confirm_pin" name="confirm_pin" type="password" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="off" required><?php endif; ?>
<button><?= $settings ? 'Save unlock PIN' : 'Unlock' ?></button></form>
<?php else: ?><p>Sign out and sign in again to create your unlock PIN.</p><?php endif; ?>
<?php if ($settings && $customHash !== ''): ?><p><a href="<?= lockEscape(rhuLockReturnUrl($_SESSION['staff_lock_return'] ?? 'RHUAdminDashboard.php')) ?>">Return to system</a></p><?php endif; ?>
<small>Forgot your PIN? Sign out, sign in normally, then open Unlock PIN to create a new one.</small><p><a href="StaffLogout.php">Sign out</a></p>
</main></body></html>
