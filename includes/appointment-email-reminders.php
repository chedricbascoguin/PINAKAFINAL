<?php
require_once dirname(__DIR__) . "/includes/family-planning-follow-up.php";
// Sends one-time email reminders to assigned staff and RHU admins for upcoming resident appointments.

if (!function_exists('rhuAppointmentColumnExists')) {
    function rhuAppointmentColumnExists(PDO $pdo, string $table, string $column): bool
    {
        if (function_exists('rhuColumnExists')) {
            return rhuColumnExists($pdo, $table, $column);
        }
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute(['table_name' => $table, 'column_name' => $column]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            error_log('rhuAppointmentColumnExists: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rhuAppointmentReminderAlreadySent')) {
    function rhuAppointmentReminderAlreadySent(PDO $pdo, int $consultationId, string $windowKey): bool
    {
        if (!function_exists('rhuTableExists') || !rhuTableExists($pdo, 'audit_logs')) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT id
                FROM audit_logs
                WHERE action = 'Appointment Email Reminder'
                  AND module_name = 'Consultations'
                  AND record_id = :record_id
                  AND description LIKE :window_key
                LIMIT 1
            ");
            $stmt->execute([
                'record_id' => $consultationId,
                'window_key' => '%' . $windowKey . '%',
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('rhuAppointmentReminderAlreadySent: ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('rhuRecordAppointmentReminderSent')) {
    function rhuRecordAppointmentReminderSent(PDO $pdo, int $consultationId, string $windowKey, array $recipients): void
    {
        if (!function_exists('rhuTableExists') || !rhuTableExists($pdo, 'audit_logs')) {
            return;
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (action, module_name, record_id, description, ip_address, user_agent, created_at)
                VALUES ('Appointment Email Reminder', 'Consultations', :record_id, :description, :ip_address, :user_agent, NOW())
            ");
            $stmt->execute([
                'record_id' => $consultationId,
                'description' => $windowKey . ' | Sent to: ' . implode(', ', $recipients),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'system',
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'system'), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('rhuRecordAppointmentReminderSent: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rhuSendUpcomingAppointmentEmailReminders')) {
    function rhuSendUpcomingAppointmentEmailReminders(PDO $pdo, int $hoursAhead = 24): int
    {
        rhuCreateFollowUpReminders($pdo);
        require_once dirname(__DIR__) . '/includes/mailer.php';

        if (!function_exists('sendRHUEmail')) {
            return 0;
        }
        if (!function_exists('rhuTableExists') || !rhuTableExists($pdo, 'consultations') || !rhuTableExists($pdo, 'health_workers')) {
            return 0;
        }

        $hoursAhead = max(1, min(168, $hoursAhead));
        $hasTime = rhuAppointmentColumnExists($pdo, 'consultations', 'consultation_time');
        $hasStatus = rhuAppointmentColumnExists($pdo, 'consultations', 'consultation_status');
        $hasPhysician = rhuAppointmentColumnExists($pdo, 'consultations', 'physician_id');
        $hasHealthWorker = rhuAppointmentColumnExists($pdo, 'consultations', 'health_worker_id');
        $providerIdExpr = $hasPhysician && $hasHealthWorker
            ? 'COALESCE(c.physician_id, c.health_worker_id)'
            : ($hasPhysician ? 'c.physician_id' : ($hasHealthWorker ? 'c.health_worker_id' : 'NULL'));
        $appointmentDateTimeExpr = $hasTime
            ? "TIMESTAMP(c.consultation_date, COALESCE(c.consultation_time, '00:00:00'))"
            : "TIMESTAMP(c.consultation_date, '08:00:00')";
        $statusSelect = $hasStatus ? "COALESCE(c.consultation_status, 'Scheduled')" : "'Scheduled'";
        $statusWhere = $hasStatus ? "AND LOWER(COALESCE(c.consultation_status, 'scheduled')) NOT IN ('completed', 'cancelled', 'canceled', 'no-show', 'no show')" : '';

        try {
            $stmt = $pdo->prepare("
                SELECT c.id, c.resident_id, c.consultation_date,
                       " . ($hasTime ? 'c.consultation_time' : "NULL") . " AS consultation_time,
                       c.chief_complaint, c.remarks, {$statusSelect} AS consultation_status,
                       {$providerIdExpr} AS provider_id,
                       CONCAT_WS(' ', r.first_name, r.last_name) AS resident_name,
                       r.email AS resident_email,
                       hw.email AS provider_email,
                       CONCAT_WS(' ', hw.first_name, hw.last_name) AS provider_name
                FROM consultations c
                JOIN residents r ON r.id = c.resident_id
                LEFT JOIN health_workers hw ON hw.id = {$providerIdExpr}
                WHERE {$appointmentDateTimeExpr} BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL {$hoursAhead} HOUR)
                  {$statusWhere}
                ORDER BY c.consultation_date ASC, " . ($hasTime ? 'c.consultation_time ASC,' : '') . " c.id ASC
                LIMIT 30
            ");
            $stmt->execute();
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('rhuSendUpcomingAppointmentEmailReminders query: ' . $e->getMessage());
            return 0;
        }

        if (!$appointments) {
            return 0;
        }

        $adminRows = [];
        try {
            $adminRows = $pdo->query("
                SELECT DISTINCT email
                FROM health_workers
                WHERE email IS NOT NULL AND email <> ''
                  AND LOWER(COALESCE(status, 'active')) = 'active'
                  AND (
                    UPPER(COALESCE(position_title, '')) IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')
                    OR UPPER(COALESCE(role, '')) IN ('ADMIN', 'RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')
                    OR UPPER(COALESCE(position_title, '')) LIKE '%MUNICIPAL HEALTH OFFICER%'
                  )
            ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            error_log('rhuSendUpcomingAppointmentEmailReminders admins: ' . $e->getMessage());
        }

        $sentCount = 0;
        foreach ($appointments as $appointment) {
            $consultationId = (int)($appointment['id'] ?? 0);
            if ($consultationId <= 0) continue;

            $date = substr((string)($appointment['consultation_date'] ?? ''), 0, 10);
            $time = substr((string)($appointment['consultation_time'] ?? ''), 0, 5);
            $windowKey = 'upcoming-' . $hoursAhead . 'h-' . $date . '-' . ($time ?: 'all-day');
            if (rhuAppointmentReminderAlreadySent($pdo, $consultationId, $windowKey)) {
                continue;
            }

            $recipients = $adminRows;
            $providerEmail = trim((string)($appointment['provider_email'] ?? ''));
            if ($providerEmail !== '') {
                $recipients[] = $providerEmail;
            }
            $recipients = array_values(array_unique(array_filter($recipients, static fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
            if (!$recipients) {
                continue;
            }

            $residentName = trim((string)($appointment['resident_name'] ?? 'Resident')) ?: 'Resident';
            $providerName = trim((string)($appointment['provider_name'] ?? 'Assigned staff')) ?: 'Assigned staff';
            $complaint = trim((string)($appointment['chief_complaint'] ?? 'Appointment'));
            $appointmentWhen = trim($date . ($time !== '' ? ' at ' . $time : ''));
            $subject = 'Upcoming RHU appointment reminder';
            $html = '<p>Hello RHU Team,</p>'
                . '<p>This is an automatic reminder that an appointment is coming up.</p>'
                . '<table role="presentation" cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-size:14px;">'
                . '<tr><td><strong>Patient</strong></td><td>' . htmlspecialchars($residentName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td><strong>Schedule</strong></td><td>' . htmlspecialchars($appointmentWhen, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td><strong>Assigned Staff</strong></td><td>' . htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td><strong>Concern</strong></td><td>' . htmlspecialchars($complaint, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td><strong>Status</strong></td><td>' . htmlspecialchars((string)($appointment['consultation_status'] ?? 'Scheduled'), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '</table>'
                . '<p>Please prepare the patient record and coordinate with the assigned staff before the appointment time.</p>';

            $successfulRecipients = [];
            foreach ($recipients as $recipient) {
                $result = sendRHUEmail($recipient, $subject, $html);
                if (!empty($result['success'])) {
                    $successfulRecipients[] = $recipient;
                }
            }

            if ($successfulRecipients) {
                rhuRecordAppointmentReminderSent($pdo, $consultationId, $windowKey, $successfulRecipients);
                $sentCount += count($successfulRecipients);
            }
        }

        return $sentCount;
    }
}

// In-app follow-up reminders. A durable unique key survives read/delete actions.
function rhuCreateFollowUpReminders(PDO $pdo, ?int $residentId = null, bool $throwOnError = false): int
{
    if (!function_exists('rhuTableExists') || $pdo->inTransaction()) return 0;
    $count = 0;
    try {
        portalEnsureNotificationTables($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS follow_up_reminder_deliveries (
            source_type VARCHAR(30) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            follow_up_date DATE NOT NULL,
            reminder_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (source_type, source_id, follow_up_date, reminder_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $tomorrow = $today->modify('+1 day')->format('Y-m-d');
        $todayString = $today->format('Y-m-d');
        $sources = [];
        if (rhuTableExists($pdo, 'consultations')) {
            $statusWhere = rhuAppointmentColumnExists($pdo, 'consultations', 'consultation_status')
                ? " AND LOWER(TRIM(COALESCE(consultation_status, 'scheduled'))) NOT IN ('completed', 'cancelled', 'canceled', 'no-show', 'no show', 'rejected', 'declined')" : '';
            // Include same-day bookings and the next Manila calendar day.
            $sources['appointment'] = "SELECT id, resident_id, consultation_date AS follow_up_date FROM consultations
                WHERE consultation_date BETWEEN :today AND :tomorrow" . $statusWhere;
        }
        if (rhuTableExists($pdo, 'consultations') && rhuAppointmentColumnExists($pdo, 'consultations', 'follow_up_date')) {
            $statusWhere = rhuAppointmentColumnExists($pdo, 'consultations', 'consultation_status')
                ? " AND LOWER(COALESCE(consultation_status, '')) NOT IN ('cancelled', 'canceled', 'no-show', 'no show')" : '';
            $sources['consultation'] = "SELECT id, resident_id, follow_up_date FROM consultations
                WHERE follow_up_date BETWEEN :today AND :tomorrow" . $statusWhere . fpReminderGuardSql($pdo);
        }
        if (rhuTableExists($pdo, 'dental_records')) {
            $sources['dental'] = "SELECT id, resident_id,
                JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(meta_json), meta_json, '{}'), '$.follow_up_date')) AS follow_up_date
                FROM dental_records WHERE record_type = 'referral'
                AND LOWER(COALESCE(status, 'pending')) NOT IN ('completed', 'cancelled', 'canceled')
                AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(meta_json), meta_json, '{}'), '$.follow_up_date')) BETWEEN :today AND :tomorrow";
        }
        foreach ($sources as $type => $sql) {
            $params = ['today' => $todayString, 'tomorrow' => $tomorrow];
            if ($residentId !== null) {
                $sql .= rhuTableExists($pdo, 'audit_logs')
                    ? " AND (resident_id = :resident_id OR resident_id IN
                        (SELECT entity_id FROM audit_logs WHERE action = 'Resident Dependent'
                         AND module_name = 'Resident Portal' AND record_id = :owner_id))"
                    : ' AND resident_id = :resident_id';
                if (rhuTableExists($pdo, 'audit_logs')) $params['owner_id'] = $residentId;
                $params['resident_id'] = $residentId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $recipient = (int)$row['resident_id'];
                if ($recipient <= 0) continue;
                // Family accounts receive reminders for their registered dependents.
                if (rhuTableExists($pdo, 'audit_logs')) {
                    $owner = $pdo->prepare("SELECT record_id FROM audit_logs WHERE action = 'Resident Dependent'
                        AND module_name = 'Resident Portal' AND entity_id = :id ORDER BY id DESC LIMIT 1");
                    $owner->execute(['id' => $recipient]);
                    $recipient = (int)($owner->fetchColumn() ?: $recipient);
                }
                $date = substr((string)$row['follow_up_date'], 0, 10);
                $when = $date === $todayString ? 'today' : 'tomorrow';
                $kind = $type === 'appointment' ? 'RHU appointment' : ($type === 'dental' ? 'dental follow-up' : 'follow-up check-up');
                $message = "Reminder: Patient #{$row['resident_id']} has a {$kind} {$when}, {$date}. Please check the patient records for details.";
                $pdo->beginTransaction();
                try {
                    $claim = $pdo->prepare('INSERT IGNORE INTO follow_up_reminder_deliveries
                        (source_type, source_id, follow_up_date, reminder_date) VALUES (:type, :id, :date, :today)');
                    $claim->execute(['type' => $type, 'id' => $row['id'], 'date' => $date, 'today' => $todayString]);
                    if ($claim->rowCount() === 1) {
                        $insert = $pdo->prepare("INSERT INTO portal_notifications (resident_id, message, link_url)
                            VALUES (:resident, :message, 'ResidentDashboard.php?tab=records')");
                        $insert->execute(['resident' => $recipient, 'message' => $message]);
                        $count++;
                    }
                    $pdo->commit();
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $error;
                }
            }
        }
    } catch (Throwable $error) {
        error_log('rhuCreateFollowUpReminders: ' . $error->getMessage());
        if ($throwOnError) throw $error;
    }
    return $count;
}
