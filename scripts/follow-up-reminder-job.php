<?php
// CLI only: run every 15 minutes with PHP, independently of the website.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/appointment-email-reminders.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/resident-reminder-delivery.php';
if (!$pdo instanceof PDO) { fwrite(STDERR, "Database unavailable.\n"); exit(1); }
if (in_array('--check', $argv, true)) {
    echo "PHP and database connection OK. No reminders sent.\n";
    echo 'SMTP password configured: ' . (rhuEnv('SMTP_PASS', '') || rhuEnv('SMTP_PASSWORD', '') ? 'yes' : 'check saved system SMTP settings') . "\n";
    exit;
}
$lock = fopen(dirname(__DIR__) . '/scratch/follow-up-reminder-job.lock', 'c');
if (!$lock) { fwrite(STDERR, "Reminder lock file unavailable.\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    rhuCreateFollowUpReminders($pdo, null, true);
    $summary = rhuDeliverResidentReminders($pdo);
    file_put_contents(dirname(__DIR__) . '/scratch/reminder-job-status.json', json_encode([
        'last_run'=>date(DATE_ATOM), 'success'=>$summary['failed'] === 0, 'delivery'=>$summary,
    ], JSON_PRETTY_PRINT), LOCK_EX);
    echo date(DATE_ATOM) . ' ' . json_encode($summary) . "\n";
    if ($summary['failed']) exit(1);
} catch (Throwable $error) {
    file_put_contents(dirname(__DIR__) . '/scratch/reminder-job-status.json', json_encode([
        'last_run'=>date(DATE_ATOM), 'success'=>false, 'error'=>'Reminder job failed; check PHP error log.',
    ], JSON_PRETTY_PRINT), LOCK_EX);
    error_log('Follow-up reminder job: ' . $error->getMessage());
    fwrite(STDERR, "Reminder job failed; check PHP error log.\n"); exit(1);
}
