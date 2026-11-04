<?php
require_once dirname(__DIR__) . "/includes/family-planning-follow-up.php";
// Immediate notice when staff saves a schedule; daily reminders remain independent.
if (!function_exists('rhuNotifyResidentSchedule')) {
function rhuNotifyResidentSchedule(PDO $pdo, int $residentId, string $service, string $date, string $time = '', ?string $previousDate = null): bool
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if ($residentId <= 0 || !$day || $day->format('Y-m-d') !== $date || $date < $today || $previousDate === $date) return false;
    $time = trim($time);
    if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) return false;
    $when = $date . ($time !== '' ? ' at ' . substr($time, 0, 5) : ' (time to be confirmed)');
    portalNotifyResident($pdo, $residentId, "RHU staff scheduled your {$service} on {$when}. Please check your appointments and follow-up details.", 'ResidentDashboard.php?tab=appointments');
    return true;
}
}

if (!function_exists('rhuNotifySavedResidentAppointment')) {
function rhuNotifySavedResidentAppointment(PDO $pdo, int $consultationId, string $service): bool
{
    if ($consultationId <= 0) return false;
    $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id = ?');
    $stmt->execute([$consultationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || strtolower(trim((string)($row['consultation_status'] ?? ''))) !== 'scheduled') return false;
    return rhuNotifyResidentSchedule($pdo, (int)$row['resident_id'], $service, substr((string)$row['consultation_date'], 0, 10), (string)($row['consultation_time'] ?? ''));
}
}

// Called by the scheduler; independent of resident browser sessions.
function rhuDeliverResidentReminders(PDO $pdo, ?int $onlyRecipientId = null): array
{
    require_once dirname(__DIR__) . '/includes/mailer.php';
    $pdo->exec("CREATE TABLE IF NOT EXISTS follow_up_external_deliveries (
        source_type VARCHAR(30) NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
        follow_up_date DATE NOT NULL, reminder_date DATE NOT NULL,
        channel VARCHAR(10) NOT NULL, sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (source_type, source_id, follow_up_date, reminder_date, channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT * FROM follow_up_reminder_deliveries WHERE reminder_date = :today');
    $stmt->execute(['today' => $today]);
    $sent = 0; $failed = 0; $missingEmail = 0; $unsubscribed = 0; $emailSent = 0; $pushSent = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = $row['source_type'] === 'dental' ? 'dental_records' : 'consultations';
        $record = $pdo->prepare("SELECT resident_id FROM {$table} WHERE id = :id");
        $record->execute(['id' => $row['source_id']]);
        $residentId = (int)$record->fetchColumn();
        if (!$residentId) continue;
        // Re-check cancellation/rescheduling before delivering a queued reminder.
        if ($table === 'dental_records') {
            $valid = $pdo->prepare("SELECT COUNT(*) FROM dental_records WHERE id = :id
                AND LOWER(COALESCE(status, 'pending')) NOT IN ('completed', 'cancelled', 'canceled')
                AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(meta_json), meta_json, '{}'), '$.follow_up_date')) = :date");
        } elseif ($row['source_type'] === 'appointment') {
            $status = rhuAppointmentColumnExists($pdo, 'consultations', 'consultation_status')
                ? " AND LOWER(TRIM(COALESCE(consultation_status, 'scheduled'))) NOT IN ('completed','cancelled','canceled','no-show','no show','rejected','declined')" : '';
            $valid = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE id = :id AND consultation_date = :date" . $status);
        } else {
            $status = rhuAppointmentColumnExists($pdo, 'consultations', 'consultation_status')
                ? " AND LOWER(COALESCE(consultation_status, '')) NOT IN ('cancelled','canceled','no-show','no show')" : '';
            $valid = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE id = :id AND follow_up_date = :date" . $status . fpReminderGuardSql($pdo));
        }
        $valid->execute(['id' => $row['source_id'], 'date' => $row['follow_up_date']]);
        if (!$valid->fetchColumn()) continue;
        $recipient = $residentId;
        if (rhuTableExists($pdo, 'audit_logs')) {
            $owner = $pdo->prepare("SELECT record_id FROM audit_logs WHERE action = 'Resident Dependent'
                AND module_name = 'Resident Portal' AND entity_id = :id ORDER BY id DESC LIMIT 1");
            $owner->execute(['id' => $residentId]);
            $recipient = (int)($owner->fetchColumn() ?: $residentId);
        }
        if ($onlyRecipientId !== null && $recipient !== $onlyRecipientId) continue;
        $emailStmt = $pdo->prepare('SELECT email FROM residents WHERE id = :id');
        $emailStmt->execute(['id' => $recipient]);
        $email = trim((string)$emailStmt->fetchColumn());
        $when = $row['follow_up_date'] === $today ? 'today' : 'tomorrow';
        $kind = $row['source_type'] === 'appointment' ? 'RHU appointment' : 'RHU follow-up check-up';
        $message = "Reminder: Patient #{$residentId} has an {$kind} {$when}, {$row['follow_up_date']}. Please check your patient records for details.";
        $emailMessage = $message;
        if ($row['source_type'] === 'appointment' && rhuAppointmentColumnExists($pdo, 'consultations', 'consultation_time')) {
            $timeStmt = $pdo->prepare('SELECT consultation_time FROM consultations WHERE id = :id');
            $timeStmt->execute(['id' => $row['source_id']]);
            $appointmentTime = substr(trim((string)$timeStmt->fetchColumn()), 0, 5);
            if ($appointmentTime !== '') {
                $emailMessage = "Reminder: Patient #{$residentId} has an RHU appointment {$when}, {$row['follow_up_date']} at {$appointmentTime}. Please check your patient records for details.";
            }
        }
        foreach (['email', 'push'] as $channel) {
            $key = [$row['source_type'], $row['source_id'], $row['follow_up_date'], $row['reminder_date'], $channel];
            $exists = $pdo->prepare('SELECT 1 FROM follow_up_external_deliveries WHERE source_type = ? AND source_id = ? AND follow_up_date = ? AND reminder_date = ? AND channel = ?');
            $exists->execute($key);
            if ($exists->fetchColumn()) continue;
            if ($channel === 'email') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $missingEmail++; continue; }
                $result = sendRHUEmail($email, $kind . ' reminder', '<p>' . htmlspecialchars($emailMessage, ENT_QUOTES, 'UTF-8') . '</p>', true);
                $success = !empty($result['success']);
            } else {
                $result = portalSendPushToResident($pdo, $recipient, $message, 'ResidentDashboard.php?tab=records');
                $success = ($result['sent'] ?? 0) > 0;
                if (($result['attempted'] ?? 0) === 0) { $unsubscribed++; continue; }
            }
            if ($success) {
                $done = $pdo->prepare('INSERT IGNORE INTO follow_up_external_deliveries (source_type, source_id, follow_up_date, reminder_date, channel) VALUES (?, ?, ?, ?, ?)');
                $done->execute($key); $sent++;
                if ($channel === 'email') $emailSent++; else $pushSent++;
            } else {
                $failed++;
                error_log('Resident reminder delivery failed: source=' . $row['source_type'] . '#' . (int)$row['source_id'] . ' channel=' . $channel . ' reason=' . ($result['error'] ?? 'Transport rejected delivery'));
            }
        }
    }
    return ['sent'=>$sent,'email_sent'=>$emailSent,'push_sent'=>$pushSent,'failed'=>$failed,'missing_email'=>$missingEmail,'unsubscribed'=>$unsubscribed];
}
