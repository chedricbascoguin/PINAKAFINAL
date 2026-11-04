<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
$embedded = ($_GET['embed'] ?? '') === '1';
$residentId = filter_var($_GET['resident_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
if ((int)($_SESSION['rhu_staff_login']['staff_id'] ?? 0) <= 0
    && (empty($_SESSION['rhu_admin_authenticated']) || (int)($_SESSION['user']['staff_id'] ?? $_SESSION['user']['user_id'] ?? 0) <= 0)) {
    $_SESSION['staff_qr_return'] = 'StaffResidentAppointments.php' . (($residentId || $embedded) ? '?' . http_build_query(array_filter(['resident_id'=>$residentId, 'embed'=>$embedded ? '1' : ''])) : '');
    header('Location: RHULogin.php'); exit;
}
require_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/resident-qr.php';
require_once dirname(__DIR__) . '/includes/staff-record-sheets.php';
$department = rhuStaffRecordDepartment($_SESSION);
$labels = ['DENTAL'=>'Dental','MEDTECH'=>'Laboratory','MIDWIFE'=>'Maternal care','NURSE'=>'Nursing','SANITARY_INSPECTOR'=>'Sanitary','PHYSICIAN'=>'Medical','ADMIN'=>'All departments','SECRETARY'=>'All departments'];
if (!isset($labels[$department])) { http_response_code(403); exit('Appointment scanning is unavailable for this staff role.'); }
$resident = null; $appointments = []; $error = '';
try {
    $database = isset($pdo) ? $pdo : null;
    if (!$database instanceof PDO) throw new RuntimeException('Database unavailable');
    if ($residentId) {
        $stmt = $database->prepare('SELECT id, first_name, middle_name, last_name FROM residents WHERE id = :id');
        $stmt->execute(['id'=>$residentId]); $resident = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$resident) http_response_code(404);
        if ($resident && rhuTableExists($database, 'consultations')) {
            $providers = [];
            foreach (['health_worker_id','physician_id'] as $column) {
                if (rhuColumnExists($database,'consultations',$column)) $providers[] = "NULLIF(c.{$column}, 0)";
            }
            $join = ''; $select = 'c.*';
            if ($providers) {
                $providerSql = count($providers) > 1 ? 'COALESCE('.implode(',', $providers).')' : $providers[0];
                $join = " LEFT JOIN health_workers hw ON hw.id = {$providerSql}";
                $select .= ", hw.position_title AS _record_provider_type, hw.role AS _record_provider_role, CONCAT_WS(' ',hw.first_name,hw.last_name) AS provider_name";
            }
            $stmt = $database->prepare("SELECT {$select} FROM consultations c{$join} WHERE c.resident_id = :id ORDER BY c.consultation_date, c.consultation_time, c.id");
            $stmt->execute(['id'=>$residentId]);
            // Authorization runs before rendering; other departments are never sent to the browser.
            $appointments = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn($row)=>residentQrCanRead($department,$row)));
        }
    }
} catch (Throwable $ex) { error_log('Resident QR appointments: '.$ex->getMessage()); http_response_code(503); $error = 'Appointments could not be loaded. Please try again.'; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scan Resident ID | ResiHUnity</title><link rel="stylesheet" href="styles/resident-qr.css?v=5"><script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(dirname(__DIR__) . "/staff-session-lock.js") ?>" defer></script></head><body<?= $embedded ? ' class="qr-embedded"' : '' ?>><main>
<header><div><span class="eyebrow">STAFF PORTAL &middot; <?= residentQrEscape($labels[$department]) ?></span><h1>Scan Resident ID</h1><p>View a resident's pending appointments for your department.</p></div><a class="button secondary" href="<?= residentQrEscape(staffRecordBackUrl($department)) ?>">Back to dashboard</a></header>
<section class="panel scanner-panel" aria-label="Camera scanner">
  <div class="scanner-heading"><span class="scanner-step">01</span><div><h2>Scan the resident's QR code</h2><p>Position the QR code inside the frame.</p></div><span class="scanner-camera-badge">Camera scan</span></div>
  <div class="camera-stage">
    <video id="qr-video" playsinline muted hidden></video>
    <div class="camera-placeholder"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M14 4h-4L8 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-4z"/><circle cx="12" cy="13" r="4"/></svg><strong>Your camera preview appears here</strong><span>Start the camera to scan a resident ID</span></div>
    <div class="camera-guide" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
  </div>
  <canvas id="qr-canvas" hidden></canvas>
  <div class="scanner-controls"><button id="start-camera" type="button"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="13" height="14" rx="3"/><path d="m16 10 5-3v10l-5-3"/></svg>Start camera</button><button id="stop-camera" type="button" class="secondary" hidden>Stop camera</button></div>
  <p id="scan-status" class="scanner-status" role="status" aria-live="polite">Allow camera access when your browser asks.</p>
  <div class="scanner-footer"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4v5c0 5-8 9-8 9s-8-4-8-9V7z"/><path d="m8 12 3 3 5-5"/></svg><span>Only your department's pending appointments will appear.</span></div>
</section>
<?php if ($error): ?><section class="panel error" role="alert"><?= residentQrEscape($error) ?></section>
<?php elseif ($resident): ?><section class="panel"><span class="eyebrow">PATIENT #<?= $residentId ?></span><h2 style="margin-top:10px"><?= residentQrEscape(implode(' ',array_filter([$resident['first_name'],$resident['middle_name'],$resident['last_name']]))) ?></h2><p><?= residentQrEscape($labels[$department]) ?> &middot; <?= count($appointments) ?> pending appointment<?= count($appointments) === 1 ? '' : 's' ?></p>
<?php if (!$appointments): ?><p>No pending appointments for your department.</p><?php endif; ?>
<?php foreach ($appointments as $appointment): ?><a class="appointment appointment-link" href="<?= residentQrEscape(residentQrAppointmentUrl($department, $appointment)) ?>" target="<?= $embedded ? '_parent' : '_self' ?>"><h3><?= residentQrEscape($appointment['chief_complaint'] ?? 'Appointment') ?></h3><span class="badge"><?= residentQrEscape($appointment['consultation_status'] ?? 'Pending') ?></span><p><strong>Date:</strong> <?= residentQrEscape($appointment['consultation_date'] ?? '') ?><br><strong>Time:</strong> <?= residentQrEscape($appointment['consultation_time'] ?? 'Not specified') ?><br><strong>Provider:</strong> <?= residentQrEscape($appointment['provider_name'] ?? 'Not assigned') ?></p><span class="appointment-open">Open appointment <span aria-hidden="true">&rarr;</span></span></a><?php endforeach; ?></section>
<?php elseif ($residentId): ?><section class="panel"><h2>Resident not found</h2><p>Please scan the correct resident ID again.</p></section><?php endif; ?>
</main><script src="assets/vendor/jsQR.js"></script><script src="assets/js/resident-qr-scanner.js?v=6"></script></body></html>
