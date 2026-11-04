<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['resident_id']) || !empty($_SESSION['rhu_staff_login']) || !empty($_SESSION['rhu_admin_authenticated'])) { header('Location: ResidentLogin.php'); exit; }
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/resident-qr.php';
require_once dirname(__DIR__) . '/includes/resident-id-card.php';
$resident = null; $error = '';
try {
    if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable');
    $stmt = $pdo->prepare('SELECT * FROM residents WHERE id = :id');
    $stmt->execute(['id'=>(int)$_SESSION['user']['resident_id']]);
    $resident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$resident) { http_response_code(404); $error = 'Resident account not found. Please sign in again.'; }
} catch (Throwable $ex) { error_log('Resident ID: '.$ex->getMessage()); http_response_code(503); $error = 'Your ID could not be loaded. Please try again.'; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My Resident ID | ResiHUnity</title><link rel="stylesheet" href="styles/resident-qr.css"><link rel="stylesheet" href="styles/resident-id-card.css?v=3"></head><body><main>
<header><div><span class="eyebrow">RESIDENT PORTAL</span><h1>My Resident ID</h1></div><a class="button secondary" href="ResidentDashboard.php?tab=profile">Back to My Profile</a></header>
<?php if ($error): ?><p class="error" role="alert"><?= residentQrEscape($error) ?></p><?php endif; ?>
<?php if ($resident): ?>
<?php residentIdCard($resident); ?>
<div class="no-print" style="text-align:center;margin-top:24px"><button type="button" data-print-resident-id>Print / Save as PDF</button><p class="muted">You can also keep a screenshot of your ID.</p><p class="muted">For RHU services only. This is not a government-issued identity document.</p></div>
<?php endif; ?></main></body></html>
