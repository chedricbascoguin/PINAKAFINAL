<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/portal.php';

$userId = (int)($_SESSION['rhu_staff_login']['id'] ?? $_SESSION['user']['user_id'] ?? 0);
if ($userId > 0) {
    portalAudit($pdo, $userId, 'Staff logout', 'users', $userId);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $parameters = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
}
session_destroy();

header('Location: RHULogin.php');
exit;
