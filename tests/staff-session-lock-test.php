<?php
require dirname(__DIR__) . '/includes/staff-session-lock.php';
function checkLock(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
checkLock(!rhuStaffLockExpired(['last_activity'=>1000], 1299), 'Locked before timeout');
checkLock(rhuStaffLockExpired(['last_activity'=>1000], 1300), 'Timeout boundary not locked');
checkLock(rhuStaffLockExpired(['last_activity'=>1000,'locked'=>true], 1001), 'Manual lock bypassed');
$_SESSION = ['rhu_staff_login'=>['staff_id'=>7]];
checkLock(rhuLockStaffId() === 7, 'Staff identity missing');
$state = rhuStaffLockState(7);
checkLock(!$state['locked'] && strlen($_SESSION['staff_lock_csrf']) === 64, 'Initialization failed');
$_SESSION['staff_screen_lock']['last_activity'] = time() - 301;
checkLock(rhuStaffLockState(7)['locked'], 'Expired session bypassed');
checkLock(!rhuStaffLockState(8)['locked'], 'Account switch retained another account lock');
$_SESSION = ['rhu_admin_authenticated'=>true,'user'=>['staff_id'=>9]];
checkLock(rhuLockStaffId() === 9, 'Admin identity missing');
$_SESSION = ['user'=>['resident_id'=>9]];
checkLock(rhuLockStaffId() === 0, 'Resident subject to staff lock');
checkLock(rhuLockReturnUrl('https://evil.example/NurseDashboard.php?tab=patients') === 'NurseDashboard.php?tab=patients', 'Unsafe redirect');
checkLock(rhuLockReturnUrl('//evil.example/foo') === 'RHUAdminDashboard.php', 'External redirect allowed');
checkLock(rhuLockReturnUrl("NurseDashboard.php\r\nX-Foo: bar") === 'RHUAdminDashboard.php', 'Header injection allowed');
foreach (['0000', '0042', '12345', '000042'] as $pin) checkLock(rhuStaffPinValid($pin), 'Valid PIN rejected');
foreach (['123', '1234567', '12ab', ' 1234', '1234 ', '12.34', ''] as $pin) checkLock(!rhuStaffPinValid($pin), 'Invalid PIN accepted');
echo "Staff lock policy checks passed.\n";
