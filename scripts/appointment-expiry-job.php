<?php
// CLI only. Run every 15 minutes independently of browser activity.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/portal.php';
if (!$pdo instanceof PDO) { fwrite(STDERR, "Database unavailable.\n"); exit(1); }
if (in_array('--check', $argv, true)) {
    $pdo->query('SELECT 1');
    echo "Appointment expiry database check passed. No notifications created.\n";
    exit;
}
$lock = fopen(dirname(__DIR__) . '/scratch/appointment-expiry-job.lock', 'c');
if (!$lock) { fwrite(STDERR, "Expiry lock unavailable.\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
try {
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT DISTINCT resident_id FROM consultations WHERE consultation_date < ? AND resident_id IS NOT NULL');
    $stmt->execute([$today]);
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $residentId) $count += rhuNotifyExpiredAppointments($pdo, (int)$residentId);
    file_put_contents(dirname(__DIR__) . '/scratch/appointment-expiry-status.json', json_encode(['last_run'=>date(DATE_ATOM), 'success'=>true, 'expired_appointments_notified'=>$count], JSON_PRETTY_PRINT), LOCK_EX);
    echo "Expired appointments notified: {$count}\n";
} catch (Throwable $error) {
    error_log('Appointment expiry job: ' . $error->getMessage());
    file_put_contents(dirname(__DIR__) . '/scratch/appointment-expiry-status.json', json_encode(['last_run'=>date(DATE_ATOM), 'success'=>false], JSON_PRETTY_PRINT), LOCK_EX);
    fwrite(STDERR, "Appointment expiry job failed; check PHP error log.\n");
    exit(1);
}
