<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$isResidentDashboardApiRequest = isset($_GET['api']) || isset($_POST['api']);
if ($isResidentDashboardApiRequest) {
  ob_start();
}

$base = '';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/patient-identifiers.php';
require_once __DIR__ . '/includes/appointment-hours.php';
require_once __DIR__ . '/includes/staff-record-sheets.php';
require_once __DIR__ . '/includes/portal.php';

// Available on every render path, including database-load failures.
$residentId = (int) ($_SESSION['user']['resident_id'] ?? 0);
if ($residentId > 0 && isset($pdo) && $pdo instanceof PDO) {
  try {
    rhuNotifyExpiredAppointments($pdo, $residentId);
  } catch (Throwable $error) {
    error_log('Resident appointment expiry notification: ' . $error->getMessage());
  }
}

if (isset($pdo) && $pdo && function_exists('portalEnsureHealthWorkerIds')) {
  portalEnsureHealthWorkerIds($pdo);
}

if (isset($_GET['logout'])) {
  unset($_SESSION['user']);
  header('Location: ResidentLogin.php');
  exit;
}

if (empty($_SESSION['user']) || empty($_SESSION['user']['resident_id'])) {
  unset($_SESSION['user']);
  if ($isResidentDashboardApiRequest) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Resident session expired. Please sign in again.']);
    exit;
  }
  header('Location: ResidentLogin.php');
  exit;
}

if (isset($_GET['download_appointment_calendar'])) {
  $event = $_SESSION['resident_appointment_calendar'] ?? null;
  if (!is_array($event) || empty($event['start']) || empty($event['title'])) {
    http_response_code(404);
    exit('Appointment calendar entry is unavailable.');
  }
  $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $event['start']);
  if (!$start) {
    http_response_code(404);
    exit('Appointment calendar entry is unavailable.');
  }
  $escapeCalendarText = static fn(string $value): string => str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\,", "\\n", "\\n"], $value);
  $end = $start->modify('+1 hour');
  $uid = hash('sha256', (string)($_SESSION['user']['resident_id']) . '|' . $event['start'] . '|' . $event['title']) . '@resihunity';
  $lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Resihunity//Appointments//EN',
    'BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
    'DTEND:' . $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
    'SUMMARY:' . $escapeCalendarText($event['title']),
    'DESCRIPTION:' . $escapeCalendarText('Appointment requested through the resident portal. Please check the portal for schedule changes.'),
    'BEGIN:VALARM', 'TRIGGER:-PT1H', 'ACTION:DISPLAY',
    'DESCRIPTION:Upcoming RHU appointment', 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
  ];
  header('Content-Type: text/calendar; charset=utf-8');
  header('Content-Disposition: inline; filename="rhu-appointment.ics"');
  echo implode("\r\n", $lines) . "\r\n";
  exit;
}

// ----------------------------------------------------
// REALTIME NOTIFICATIONS API ENDPOINTS
// ----------------------------------------------------
if ($isResidentDashboardApiRequest) {
  $residentApiRespond = static function (array $payload, int $statusCode = 200): never {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  };

  header('Content-Type: application/json; charset=utf-8');
  $api = $_GET['api'] ?? $_POST['api'] ?? '';
  $user = $_SESSION['user'] ?? [];
  $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
  $residentId = (int)($user['resident_id'] ?? 0);

  if (empty($pdo)) {
    $residentApiRespond(['success' => false, 'error' => 'Database connection unavailable'], 503);
  }

  if ($api === 'get_consultations') {
    try {
      $consultationNotesSql = residentConsultationNotesSql($pdo, 'c');
      $consultationStatusSql = residentConsultationStatusSql($pdo, 'c');
      $hasConsultationHealthWorker = residentColumnExists($pdo, 'consultations', 'health_worker_id');
      $hasConsultationPhysician = residentColumnExists($pdo, 'consultations', 'physician_id');
      $consultationProviderJoinId = $hasConsultationHealthWorker && $hasConsultationPhysician
        ? 'COALESCE(c.health_worker_id, c.physician_id)'
        : ($hasConsultationHealthWorker ? 'c.health_worker_id' : ($hasConsultationPhysician ? 'c.physician_id' : 'NULL'));
      $stmt = $pdo->prepare(
        "SELECT c.*, {$consultationNotesSql} AS consultation_notes, {$consultationStatusSql} AS consultation_status, CONCAT_WS(' ', hw.first_name, hw.last_name) AS physician_name
         FROM consultations c
         LEFT JOIN health_workers hw ON hw.id = {$consultationProviderJoinId}
         WHERE c.resident_id = :resident_id
         ORDER BY c.consultation_date DESC, c.id DESC"
      );
      $stmt->execute(['resident_id' => $residentId]);
      $records = rhuWithoutExpiredAppointments($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
      foreach ($records as &$record) {
        $record['consultation_status'] = residentResolveConsultationStatus($record);
        $record['icd10'] = residentExtractConsultationIcd10($record);
      }
      unset($record);

      $currentYear = (int)date('Y');
      $visitsThisYear = count(array_filter($records, fn($record) => !empty($record['consultation_date']) && (int)date('Y', strtotime((string)$record['consultation_date'])) === $currentYear));
      $totalPrescriptions = array_sum(array_map(fn($record) => empty($record['medications_prescribed']) ? 0 : count(array_filter(preg_split('/,\s*/', (string)$record['medications_prescribed']))), $records));
      $fingerprint = sha1(json_encode(array_map(static function (array $record): array {
        return [
          'id' => $record['id'] ?? null,
          'updated_at' => $record['updated_at'] ?? null,
          'diagnosis' => $record['diagnosis'] ?? '',
          'status' => $record['consultation_status'] ?? '',
          'icd10' => $record['icd10'] ?? '',
          'medications' => $record['medications_prescribed'] ?? '',
          'notes' => $record['consultation_notes'] ?? '',
        ];
      }, $records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

      $residentApiRespond([
        'success' => true,
        'consultations' => $records,
        'fingerprint' => $fingerprint,
        'totals' => [
          'total' => count($records),
          'visits_this_year' => $visitsThisYear,
          'prescriptions' => $totalPrescriptions,
        ],
      ]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'get_health_profile') {
    try {
      $residentStmt = $pdo->prepare("SELECT * FROM residents WHERE id = :resident_id LIMIT 1");
      $residentStmt->execute(['resident_id' => $residentId]);
      $residentRow = $residentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

      $profile = [];
      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'resident_health_profiles')) {
        $profileStmt = $pdo->prepare("SELECT * FROM resident_health_profiles WHERE resident_id = :resident_id LIMIT 1");
        $profileStmt->execute(['resident_id' => $residentId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
      }
      if (!$profile && function_exists('rhuTableExists') && rhuTableExists($pdo, 'health_records')) {
        $healthStmt = $pdo->prepare("SELECT blood_type, height_cm AS height, weight_kg AS weight, blood_pressure, allergies, medical_conditions AS chronic_conditions, last_checkup_date, updated_at FROM health_records WHERE resident_id = :resident_id ORDER BY id DESC LIMIT 1");
        $healthStmt->execute(['resident_id' => $residentId]);
        $profile = $healthStmt->fetch(PDO::FETCH_ASSOC) ?: [];
      }

      $residentBloodType = trim((string)($residentRow['blood_type'] ?? ''));
      $residentPhilhealthNumber = trim((string)($residentRow['philhealth_number'] ?? ($residentRow['philhealth_id'] ?? '')));
      $data = [
        'blood_type' => $residentBloodType !== '' ? $residentBloodType : ($profile['blood_type'] ?? ''),
        'philhealth_number' => $residentPhilhealthNumber !== '' ? $residentPhilhealthNumber : ($profile['philhealth_number'] ?? ''),
        'height' => $profile['height'] ?? '',
        'weight' => $profile['weight'] ?? '',
        'blood_pressure' => $profile['blood_pressure'] ?? ($profile['bp'] ?? ''),
        'heart_rate' => $profile['heart_rate'] ?? '',
        'temperature' => $profile['temperature'] ?? '',
        'smoking_status' => $profile['smoking_status'] ?? '',
        'alcohol_consumption' => $profile['alcohol_consumption'] ?? '',
        'exercise_frequency' => $profile['exercise_frequency'] ?? '',
        'diet_type' => $profile['diet_type'] ?? '',
        'allergies' => $profile['allergies'] ?? ($residentRow['allergies'] ?? ''),
        'chronic_conditions' => $profile['chronic_conditions'] ?? ($profile['medical_conditions'] ?? ($residentRow['medical_conditions'] ?? '')),
        'current_medications' => $profile['current_medications'] ?? ($profile['medications'] ?? ''),
        'last_checkup_date' => $profile['last_checkup_date'] ?? '',
      ];
      $residentApiRespond([
        'success' => true,
        'profile' => $data,
        'fingerprint' => sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
      ]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'save_resident_location') {
    try {
      $rawInput = file_get_contents('php://input');
      $input = json_decode($rawInput, true);
      if (!is_array($input)) $input = $_POST;
      $latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
      $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
      $accuracy = isset($input['accuracy']) && is_numeric($input['accuracy']) ? (int)round((float)$input['accuracy']) : null;
      if ($residentId <= 0 || $latitude === false || $longitude === false) {
        $residentApiRespond(['success' => false, 'error' => 'Invalid resident location.'], 422);
      }
      if (!function_exists('portalSaveResidentLocation')) {
        $residentApiRespond(['success' => false, 'error' => 'Resident location storage is unavailable.'], 500);
      }
      portalSaveResidentLocation($pdo, $residentId, (float)$latitude, (float)$longitude, $accuracy);
      $residentApiRespond(['success' => true]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'get_push_public_key') {
    try {
      $keys = function_exists('portalGetVapidKeys') ? portalGetVapidKeys($pdo) : null;
      $residentApiRespond(['success' => (bool)$keys, 'public_key' => $keys['publicKey'] ?? '']);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'save_push_subscription') {
    try {
      $rawInput = file_get_contents('php://input');
      $input = json_decode($rawInput, true);
      if (!is_array($input)) $input = $_POST;
      $endpoint = trim((string)($input['endpoint'] ?? ''));
      $keys = $input['keys'] ?? [];
      $p256dh = trim((string)($keys['p256dh'] ?? $input['p256dh'] ?? ''));
      $auth = trim((string)($keys['auth'] ?? $input['auth'] ?? ''));
      if ($residentId <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') {
        $residentApiRespond(['success' => false, 'error' => 'Invalid push subscription'], 422);
      }
      if (!function_exists('portalEnsurePushTables')) {
        $residentApiRespond(['success' => false, 'error' => 'Push subscription storage is unavailable.'], 500);
      }
      portalEnsurePushTables($pdo);
      $stmt = $pdo->prepare("
        INSERT INTO portal_push_subscriptions (resident_id, endpoint, p256dh, auth, user_agent, is_active)
        VALUES (:resident_id, :endpoint, :p256dh, :auth, :user_agent, 1)
        ON DUPLICATE KEY UPDATE
          resident_id = VALUES(resident_id),
          p256dh = VALUES(p256dh),
          auth = VALUES(auth),
          user_agent = VALUES(user_agent),
          is_active = 1,
          updated_at = NOW()
      ");
      $stmt->execute([
        'resident_id' => $residentId,
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
      ]);
      $residentApiRespond(['success' => true]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'test_push') {
    try {
      $result = function_exists('portalSendPushToResident')
        ? portalSendPushToResident($pdo, $residentId, 'This is a test RHU device notification.', 'ResidentDashboard.php')
        : ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => [['error' => 'Push sender is unavailable']]];
      $residentApiRespond(['success' => true, 'push_result' => $result]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'get_messages') {
    try {
      $messages = [];
      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
        $messageStmt = $pdo->prepare('SELECT id, resident_id, subject, message, admin_reply, status, created_at, replied_at FROM messages WHERE resident_id = :resident_id ORDER BY created_at DESC LIMIT 50');
        $messageStmt->execute(['resident_id' => $residentId]);
        $messages = $messageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }
      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'audit_logs')) {
        $legacyStmt = $pdo->prepare("SELECT id, description, created_at FROM audit_logs WHERE module_name = 'Resident Portal' AND action = 'Resident Message' AND record_id = :resident_id ORDER BY created_at DESC LIMIT 50");
        $legacyStmt->execute(['resident_id' => $residentId]);
        foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $legacyRow) {
          $legacyText = (string)$legacyRow['description'];
          $legacyReply = '';
          if (preg_match('/\s*\[Replied:\s*(.*?)\]\s*$/s', $legacyText, $replyMatch)) {
            $legacyReply = trim($replyMatch[1]);
            $legacyText = trim((string)preg_replace('/\s*\[Replied:\s*.*?\]\s*$/s', '', $legacyText));
          }
          $parts = preg_split('/\R/', $legacyText, 2);
          $messages[] = [
            'id' => 'legacy-' . $legacyRow['id'],
            'subject' => trim($parts[0] ?? '') ?: 'General Inquiry',
            'message' => trim($parts[1] ?? $legacyText),
            'admin_reply' => $legacyReply,
            'status' => $legacyReply !== '' ? 'Replied' : 'Pending',
            'created_at' => $legacyRow['created_at'],
            'replied_at' => null,
          ];
        }
        usort($messages, static fn(array $left, array $right): int => strcmp((string)$right['created_at'], (string)$left['created_at']));
        $messages = array_slice($messages, 0, 50);
      }
      foreach ($messages as &$messageRow) {
        if (trim((string)($messageRow['admin_reply'] ?? '')) !== '') {
          $messageRow['status'] = 'Replied';
        }
        $createdTimestamp = strtotime((string)($messageRow['created_at'] ?? ''));
        $replyTimestamp = strtotime((string)($messageRow['replied_at'] ?? ''));
        $messageRow['created_at_display'] = $createdTimestamp ? date('M j, Y, g:i A', $createdTimestamp) : (string)($messageRow['created_at'] ?? '');
        $messageRow['replied_at_display'] = $replyTimestamp ? date('M j, Y, g:i A', $replyTimestamp) : (string)($messageRow['replied_at'] ?? '');
      }
      unset($messageRow);
      $residentApiRespond(['success' => true, 'messages' => $messages, 'count' => count($messages)]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  // The live RHU database is managed by rhu.sql; portal notification tables are optional compatibility storage.
  if (false && function_exists('rhuEnv') && rhuEnv('RHU_ALLOW_PORTAL_SCHEMA', '0') === '1') try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            resident_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            audience_role VARCHAR(50) NULL,
            message TEXT NOT NULL,
            link_url VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_resident (resident_id),
            INDEX idx_user_role (user_id, audience_role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
      $pdo->exec("ALTER TABLE portal_notifications ADD COLUMN resident_id BIGINT UNSIGNED NULL AFTER id");
    } catch (Throwable $ignored) {
    }
    try {
      $pdo->exec("ALTER TABLE portal_notifications ADD INDEX idx_resident (resident_id)");
    } catch (Throwable $ignored) {
    }
  } catch (Throwable $t) {
  }

  if ($api === 'get_notifications') {
    try {
      require_once __DIR__ . '/includes/appointment-email-reminders.php';
      rhuCreateFollowUpReminders($pdo, (int)$residentId);
      // The bell polls frequently; attempt external delivery at most once every 15 minutes per session.
      if (time() - (int)($_SESSION['resident_reminder_delivery_last_attempt'] ?? 0) >= 900) {
        $_SESSION['resident_reminder_delivery_last_attempt'] = time();
        try {
          require_once __DIR__ . '/includes/resident-reminder-delivery.php';
          rhuDeliverResidentReminders($pdo, (int)$residentId);
        } catch (Throwable $deliveryError) {
          error_log('Resident reminder email delivery: ' . $deliveryError->getMessage());
        }
      }
      if (function_exists('portalEnsureNotificationTables')) {
        portalEnsureNotificationTables($pdo);
      }
      $portalNotificationsAvailable = function_exists('rhuTableExists') && rhuTableExists($pdo, 'portal_notifications');
      $deletedIds = array_map('intval', $_SESSION['resident_deleted_notification_ids'] ?? []);
      $readIds = array_map('intval', $_SESSION['resident_read_notification_ids'] ?? []);
      if ($portalNotificationsAvailable) {
        $stmt = $pdo->prepare("
                  SELECT id, message, link_url, is_read, created_at
                  FROM portal_notifications
                  WHERE (user_id = :uid AND user_id > 0)
                    OR (resident_id = :rid AND resident_id > 0)
                  ORDER BY id DESC LIMIT 50
              ");
        $stmt->execute(['uid' => $userId, 'rid' => $residentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } elseif (function_exists('rhuTableExists') && rhuTableExists($pdo, 'audit_logs')) {
        $stmt = $pdo->prepare("SELECT id, description AS message, created_at
          FROM audit_logs
          WHERE module_name = 'Resident Portal'
            AND record_id = :resident_id
            AND action IN ('Resident Notification', 'Resident Message')
          ORDER BY id DESC LIMIT 50");
        $stmt->execute(['resident_id' => $residentId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
          $row['link_url'] = 'ResidentDashboard.php?tab=contact';
          $row['is_read'] = in_array((int)$row['id'], $readIds, true) ? 1 : 0;
          if (!in_array((int)$row['id'], $deletedIds, true)) $rows[] = $row;
        }
      } else {
        $rows = [];
      }

      // Seed initial system notifications if zero existing
      if (empty($rows) && $portalNotificationsAvailable) {
        $seedMsg = "Welcome to the RHU Resident Portal! Access your medical history, health records, and book OPD consultations online.";
        $ins = $pdo->prepare("INSERT INTO portal_notifications (resident_id, user_id, audience_role, message, link_url, is_read) VALUES (:rid, :uid, NULL, :msg, 'ResidentDashboard.php?tab=profile', 0)");
        $ins->execute(['rid' => $residentId, 'uid' => $userId ?: null, 'msg' => $seedMsg]);

        $seedMsg2 = "Reminder: Please keep your Emergency Contact Person and PhilHealth Number updated under the Profile tab.";
        $ins->execute(['rid' => $residentId, 'uid' => $userId ?: null, 'msg' => $seedMsg2]);

        $stmt->execute(['uid' => $userId, 'rid' => $residentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }

      $uniqueRows = [];
      foreach ($rows as $r) {
        $messageKey = preg_replace('/\s+/', ' ', trim((string)($r['message'] ?? '')));
        $linkKey = trim((string)($r['link_url'] ?? ''));
        $dedupeKey = strtolower($messageKey . '|' . $linkKey);
        if ($dedupeKey === '|') {
          $dedupeKey = 'notification-' . (int)($r['id'] ?? 0);
        }
        if (!isset($uniqueRows[$dedupeKey])) {
          $uniqueRows[$dedupeKey] = $r;
          continue;
        }
        if (empty($r['is_read'])) {
          $uniqueRows[$dedupeKey]['is_read'] = 0;
        }
      }

      $unreadCount = 0;
      $formatted = [];
      foreach (array_values($uniqueRows) as $r) {
        $isRead = (int)$r['is_read'];
        if (!$isRead) $unreadCount++;

        $timestamp = strtotime($r['created_at']);
        $displayTime = $timestamp ? date('M j, Y, g:i A', $timestamp) : (string)$r['created_at'];
        $isoTime = $timestamp ? date(DATE_ATOM, $timestamp) : '';

        $formatted[] = [
          'id' => (int)$r['id'],
          'title' => 'RHU Resident Notification',
          'message' => $r['message'],
          'link_url' => $r['link_url'],
          'is_read' => $isRead,
          'created_at' => $r['created_at'],
          'created_at_iso' => $isoTime,
          'created_at_display' => $displayTime,
          'time_ago' => $displayTime
        ];
      }

      $residentApiRespond(['success' => true, 'notifications' => $formatted, 'unread_count' => $unreadCount]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'mark_read') {
    try {
      if (function_exists('portalEnsureNotificationTables')) {
        portalEnsureNotificationTables($pdo);
      }
      $notifId = (int)($_POST['id'] ?? 0);
      $markAll = (int)($_POST['all'] ?? 0);

      if (!function_exists('rhuTableExists') || !rhuTableExists($pdo, 'portal_notifications')) {
        $readIds = array_map('intval', $_SESSION['resident_read_notification_ids'] ?? []);
        if ($markAll) {
          $stmt = $pdo->prepare("SELECT id FROM audit_logs WHERE module_name = 'Resident Portal' AND record_id = :resident_id AND action IN ('Resident Notification', 'Resident Message')");
          $stmt->execute(['resident_id' => $residentId]);
          $readIds = array_merge($readIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
        } elseif ($notifId > 0) {
          $readIds[] = $notifId;
        }
        $_SESSION['resident_read_notification_ids'] = array_values(array_unique($readIds));
      } elseif ($markAll) {
        $stmt = $pdo->prepare("UPDATE portal_notifications SET is_read = 1 WHERE (user_id = :uid AND user_id > 0) OR (resident_id = :rid AND resident_id > 0)");
        $stmt->execute(['uid' => $userId, 'rid' => $residentId]);
      } elseif ($notifId > 0) {
        $stmt = $pdo->prepare("UPDATE portal_notifications SET is_read = 1 WHERE id = :id AND ((user_id = :uid AND user_id > 0) OR (resident_id = :rid AND resident_id > 0))");
        $stmt->execute(['id' => $notifId, 'uid' => $userId, 'rid' => $residentId]);
      }
      $residentApiRespond(['success' => true]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($api === 'delete_notifications') {
    try {
      if (function_exists('portalEnsureNotificationTables')) {
        portalEnsureNotificationTables($pdo);
      }
      $idsRaw = $_POST['ids'] ?? '';
      $ids = json_decode($idsRaw, true);
      if (!is_array($ids)) {
        $ids = array_filter(array_map('intval', explode(',', (string)$idsRaw)));
      }

      if (!function_exists('rhuTableExists') || !rhuTableExists($pdo, 'portal_notifications')) {
        $deletedIds = array_map('intval', $_SESSION['resident_deleted_notification_ids'] ?? []);
        $_SESSION['resident_deleted_notification_ids'] = array_values(array_unique(array_merge($deletedIds, array_map('intval', $ids))));
      } elseif (!empty($ids)) {
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM portal_notifications WHERE id IN ($inQuery) AND ((user_id = ? AND user_id > 0) OR (resident_id = ? AND resident_id > 0))");
        $stmt->execute(array_merge(array_values($ids), [$userId, $residentId]));
      }
      $residentApiRespond(['success' => true, 'deleted_count' => count($ids)]);
    } catch (Throwable $e) {
      $residentApiRespond(['success' => false, 'error' => $e->getMessage()], 500);
    }
  }

  $residentApiRespond(['success' => false, 'error' => 'Unknown resident dashboard API action.'], 404);
}

// Audit records are restricted to authenticated RHU staff and administrators.
// Never allow a resident-controlled tab value to enter an audit-log view.
if (strtolower((string)($_GET['tab'] ?? '')) === 'audit') {
  $_SESSION['resident_dashboard_access_flash'] = 'Audit logs are restricted to RHU staff and administrators.';
  header('Location: ResidentDashboard.php?tab=home');
  exit;
}

if (!function_exists('esc')) {
  function esc(mixed $value): string
  {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
function residentAge(?string $dateOfBirth): ?int
{
  if (!$dateOfBirth || $dateOfBirth === '0000-00-00') return null;
  try {
    return (new DateTime($dateOfBirth))->diff(new DateTime('today'))->y;
  } catch (Exception $e) {
    return null;
  }
}

function residentAgeLabel(?string $dateOfBirth): string
{
  if (!$dateOfBirth || $dateOfBirth === '0000-00-00') return 'N/A';
  try {
    $birthDate = new DateTime($dateOfBirth);
    $today = new DateTime('today');
    if ($birthDate > $today) return 'N/A';
    $diff = $birthDate->diff($today);
    if ($diff->y > 0) return $diff->y . ' y/o';
    $months = ($diff->y * 12) + $diff->m;
    if ($months > 0) return $months . ' month' . ($months === 1 ? '' : 's') . ' old';
    return max(0, $diff->d) . ' day' . ($diff->d === 1 ? '' : 's') . ' old';
  } catch (Exception $e) {
    return 'N/A';
  }
}

function residentColumnExists(?PDO $pdo, string $table, string $column): bool
{
  if (!$pdo || !function_exists('rhuColumnExists')) return false;
  return rhuColumnExists($pdo, $table, $column);
}

function residentConfidentialKey(): string
{
  $configured = function_exists('rhuEnv') ? (string) rhuEnv('RHU_CONFIDENTIAL_KEY', '') : '';
  $material = $configured !== '' ? $configured : (__DIR__ . '|rhu-confidential-testing-local-key');
  return hash('sha256', $material, true);
}

function residentDecryptConfidential(?string $payload): string
{
  $payload = (string)($payload ?? '');
  if ($payload === '') return '';
  $raw = base64_decode($payload, true);
  if ($raw === false || strlen($raw) < 29) return '[Unable to decrypt]';
  $iv = substr($raw, 0, 12);
  $tag = substr($raw, 12, 16);
  $cipherText = substr($raw, 28);
  $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', residentConfidentialKey(), OPENSSL_RAW_DATA, $iv, $tag);
  return $plainText === false ? '[Unable to decrypt]' : $plainText;
}

function residentProviderMatchesAppointmentType(string $appointmentType, array $provider): bool
{
  return in_array(residentAppointmentCategoryKey($appointmentType), residentAppointmentRolesForProvider($provider), true);
}

function residentAppointmentCategoryKey(string $appointmentType): string
{
  $type = strtolower($appointmentType);
  if (preg_match('/prenatal|maternal/i', $type)) return 'prenatal';
  if (preg_match('/child|vaccination|immunization|vaccine/i', $type)) return 'vaccination';
  if (preg_match('/laboratory|blood|test|diagnostic/i', $type)) return 'laboratory';
  if (preg_match('/sanitary|inspection|clearance/i', $type)) return 'sanitary';
  if (preg_match('/dental|dentist|tooth|oral/i', $type)) return 'dental';
  if (preg_match('/health checkup|checkup/i', $type)) return 'checkup';
  return 'general';
}

function residentAppointmentRolesForProvider(array $provider): array
{
  $providerText = strtolower(implode(' ', array_filter([
    $provider['first_name'] ?? '',
    $provider['last_name'] ?? '',
    $provider['staff_type'] ?? '',
    $provider['specialization'] ?? '',
    $provider['role'] ?? '',
    $provider['position'] ?? '',
    $provider['position_title'] ?? '',
  ], fn($value) => trim((string)$value) !== '')));

  $roles = [];
  if (preg_match('/doctor|physician|medical officer|dr\.|rhu_admin/i', $providerText)) {
    array_push($roles, 'general', 'prenatal', 'checkup');
  }
  if (preg_match('/nurse|public health nurse/i', $providerText)) {
    array_push($roles, 'vaccination', 'checkup');
  }
  if (preg_match('/midwife|rural health midwife|ob/i', $providerText)) {
    $roles[] = 'prenatal';
  }
  if (preg_match('/medtech|medical technologist|laboratory|lab/i', $providerText)) {
    $roles[] = 'laboratory';
  }
  if (preg_match('/sanitary|inspector|sanitation/i', $providerText)) {
    $roles[] = 'sanitary';
  }
  if (preg_match('/dental|dentist|oral health/i', $providerText)) {
    $roles[] = 'dental';
  }
  if ($providerText !== '' && empty($roles)) {
    $roles[] = 'general';
  }
  return array_values(array_unique($roles));
}

function residentConsultationNotesSql(?PDO $pdo, string $alias = 'c'): string
{
  if (residentColumnExists($pdo, 'consultations', 'consultation_notes')) {
    return "CASE
      WHEN {$alias}.remarks REGEXP '(^|[[:space:]])(ICD-?10:|Status:)' THEN {$alias}.remarks
      WHEN {$alias}.consultation_notes REGEXP '(^|[[:space:]])(ICD-?10:|Status:)' THEN {$alias}.consultation_notes
      ELSE COALESCE({$alias}.consultation_notes, {$alias}.remarks)
    END";
  }
  return "{$alias}.remarks";
}

function residentConsultationStatusSql(?PDO $pdo, string $alias = 'c'): string
{
  if (residentColumnExists($pdo, 'consultations', 'consultation_status')) {
    return "COALESCE({$alias}.consultation_status, CASE WHEN {$alias}.follow_up_date IS NOT NULL THEN 'Scheduled' WHEN {$alias}.diagnosis IS NOT NULL AND {$alias}.diagnosis <> '' AND {$alias}.diagnosis <> 'Pending OPD Triage' THEN 'Completed' ELSE 'Scheduled' END)";
  }
  return "CASE WHEN {$alias}.follow_up_date IS NOT NULL THEN 'Scheduled' WHEN {$alias}.diagnosis IS NOT NULL AND {$alias}.diagnosis <> '' AND {$alias}.diagnosis <> 'Pending OPD Triage' THEN 'Completed' ELSE 'Scheduled' END";
}

function residentResolveConsultationStatus(array $consultation): string
{
  if (rhuAppointmentExpired($consultation)) return 'Due date expired';
  $status = trim((string)($consultation['consultation_status'] ?? ''));
  $notes = trim((string)($consultation['consultation_notes'] ?? '') . "\n" . (string)($consultation['remarks'] ?? '') . "\n" . (string)($consultation['treatment_plan'] ?? ''));
  $noteStatus = '';

  if ($notes !== '' && preg_match('/^\s*Status:\s*([^\r\n|]+)/mi', $notes, $match)) {
    $candidate = trim($match[1]);
    $allowed = ['Scheduled', 'In Progress', 'Completed', 'Cancelled', 'Referred', 'No-show'];
    foreach ($allowed as $allowedStatus) {
      if (strcasecmp($candidate, $allowedStatus) === 0) {
        $noteStatus = $allowedStatus;
        break;
      }
    }
  }

  if ($noteStatus !== '' && ($status === '' || strcasecmp($status, 'Scheduled') === 0)) {
    return $noteStatus;
  }

  if ($status !== '') {
    $diagnosis = trim((string)($consultation['diagnosis'] ?? ''));
    $medications = trim((string)($consultation['medications_prescribed'] ?? ''));
    if (strcasecmp($status, 'Scheduled') === 0 && $diagnosis !== '' && strcasecmp($diagnosis, 'Pending OPD Triage') !== 0 && $medications !== '') {
      return 'Completed';
    }
    return $status;
  }

  $diagnosis = trim((string)($consultation['diagnosis'] ?? ''));
  return ($diagnosis !== '' && strcasecmp($diagnosis, 'Pending OPD Triage') !== 0) ? 'Completed' : 'Scheduled';
}

function residentExtractConsultationIcd10(array $consultation): string
{
  $existing = trim((string)($consultation['icd10'] ?? ''));
  if ($existing !== '') {
    return $existing;
  }

  $notes = trim((string)($consultation['consultation_notes'] ?? '') . "\n" . (string)($consultation['remarks'] ?? '') . "\n" . (string)($consultation['treatment_plan'] ?? ''));
  if ($notes !== '' && preg_match('/\bICD-?10:\s*([A-Z][0-9][0-9A-Z](?:\.[0-9A-Z]+)?)/i', $notes, $match)) {
    return strtoupper($match[1]);
  }

  $diagnosis = trim((string)($consultation['diagnosis'] ?? ''));
  if ($diagnosis !== '' && preg_match('/\(([A-Z][0-9][0-9A-Z](?:\.[0-9A-Z]+)?)\)/i', $diagnosis, $match)) {
    return strtoupper($match[1]);
  }

  return '';
}

function recordResidentChange(PDO $pdo, int $residentId, string $action, array $changes): void
{
  if ($residentId <= 0 || !function_exists('rhuTableExists') || !rhuTableExists($pdo, 'audit_logs')) return;
  try {
    $stmt = $pdo->prepare("INSERT INTO audit_logs
      (action, module_name, record_id, entity_type, entity_id, description, ip_address, user_agent, created_at)
      VALUES (:action, 'Resident Portal', :record_id, 'resident', :entity_id, :description, :ip, :ua, NOW())");
    $stmt->execute([
      'action' => $action,
      'record_id' => $residentId,
      'entity_id' => $residentId,
      'description' => json_encode(['changed_fields' => $changes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
      'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
  } catch (Throwable $e) {
    error_log('recordResidentChange: ' . $e->getMessage());
  }
}

function applyMarriedSurnameRule(PDO $pdo, int $residentId, string $civilStatus, string $middleName, string $lastName, ?string $existingLastName = null): array
{
  $trimmedMiddle = trim($middleName);
  $trimmedLast = trim($lastName);
  $previousLastName = trim((string)($existingLastName ?? ''));

  if (strcasecmp($civilStatus, 'Married') !== 0) {
    return [$trimmedMiddle, $trimmedLast];
  }

  $fatherLastName = '';
  if ($residentId > 0) {
    $fatherStmt = $pdo->prepare("SELECT r.last_name
      FROM audit_logs a
      INNER JOIN residents r ON r.id = a.entity_id
      WHERE a.action = 'Resident Dependent'
        AND a.module_name = 'Resident Portal'
        AND a.record_id = :resident_id
        AND a.entity_type = 'resident'
        AND LOWER(COALESCE(r.sex, '')) IN ('male', 'm')
      ORDER BY a.id DESC LIMIT 1");
    $fatherStmt->execute(['resident_id' => $residentId]);
    $fatherLastName = trim((string)($fatherStmt->fetchColumn() ?: ''));
  }

  if ($previousLastName !== '') {
    $middleNameToStore = $previousLastName;
    $resolvedLastName = $fatherLastName !== '' ? $fatherLastName : ($trimmedLast !== '' ? $trimmedLast : $previousLastName);
    return [$middleNameToStore, $resolvedLastName];
  }

  return [$trimmedMiddle, $trimmedLast];
}

$user = $_SESSION['user'];
$resident = null;
$isFemaleResident = false;
$consultations = [];
$consultationsPage = [];
$dentalRecords = [];
$laboratoryRecords = [];
$residentDentalDashboardRecords = [];
$vaccinationRecords = [];
$confidentialLabRecords = [];
$familyPlanningRecords = [];
$maternalReferrals = [];
$emergencyRequestCount = 0;
$pregnancyRecords = [];
$birthRecords = [];
$certificates = [];
$healthCertificates = [];
$postedEvents = [];
$residentAnnouncements = [];
$residentOfficials = [];
$residentEventGallery = [];
$rhuStaffList = [];
$loadError = null;
$contactSuccess = $_SESSION['resident_dashboard_message_flash'] ?? '';
$appointmentCalendar = $_SESSION['resident_appointment_calendar_flash'] ?? null;
$autoDismissSuccess = is_array($appointmentCalendar) || !empty($_SESSION['resident_dashboard_auto_dismiss_flash']);
$certificateSuccess = $_SESSION['resident_dashboard_certificate_flash'] ?? '';
unset($_SESSION['resident_dashboard_message_flash'], $_SESSION['resident_dashboard_certificate_flash'], $_SESSION['resident_appointment_calendar_flash'], $_SESSION['resident_dashboard_auto_dismiss_flash']);
$contactErrors = [];
$certificateErrors = [];
$residentMessages = [];
$dependents = [];
$dependentErrors = [];
$dependentSuccess = $_SESSION['resident_dashboard_dependent_flash'] ?? '';
unset($_SESSION['resident_dashboard_dependent_flash']);
if (empty($_SESSION['resident_dashboard_csrf'])) {
  $_SESSION['resident_dashboard_csrf'] = bin2hex(random_bytes(32));
}
$dashboardCsrf = $_SESSION['resident_dashboard_csrf'];

$dbBarangays = [];
$dbBarangaysById = [];
if (!empty($pdo)) {
  try {
    $dbBarangays = getPortalBarangays($pdo);
    if (!empty($dbBarangays)) {
      $bgyRows = $pdo->query("SELECT id, name FROM barangays ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($bgyRows as $bgyRow) {
        $dbBarangaysById[(int)$bgyRow['id']] = (string)$bgyRow['name'];
      }
    }
  } catch (Throwable $e) {
    $dbBarangays = [];
    $dbBarangaysById = [];
  }
}

if (empty($dbBarangays)) {
  $dbBarangays = [
    'Aga', 'Anilao', 'Balaytigue', 'Balibago', 'Banilad', 'Barangay 1 (Pob.)', 'Barangay 2 (Pob.)', 'Barangay 3 (Pob.)', 'Barangay 4 (Pob.)', 'Bilaran', 'Bucana', 'Bulihan', 'Calayo', 'Catandaan', 'Cogunan', 'Dayap', 'Halang', 'Kaylaway', 'Looc', 'Lumbangan', 'Mabini', 'Nagsabaran', 'Natipuan', 'Pantalan', 'Poblacion', 'Wawa'
  ];
}

if (!empty($pdo)) {
  try {
    if (!empty($user['resident_id'])) {
      $statement = $pdo->prepare('SELECT * FROM residents WHERE id = :id LIMIT 1');
      $statement->execute(['id' => $user['resident_id']]);
      $resident = $statement->fetch();
    }
    if (!$resident && !empty($user['email'])) {
      $statement = $pdo->prepare('SELECT * FROM residents WHERE email = :email LIMIT 1');
      $statement->execute(['email' => $user['email']]);
      $resident = $statement->fetch();
    }
    if (!$resident && !empty($user['last_name'])) {
      $statement = $pdo->prepare('SELECT * FROM residents WHERE last_name = :last_name ORDER BY id');
      $statement->execute(['last_name' => trim((string)$user['last_name'])]);
      $sameSurnameResidents = $statement->fetchAll(PDO::FETCH_ASSOC);
      $accountFirstName = strtolower(trim((string)($user['first_name'] ?? '')));
      $matchingResidents = array_values(array_filter(
        $sameSurnameResidents,
        static function (array $candidate) use ($accountFirstName): bool {
          $residentFirstName = strtolower(trim((string)($candidate['first_name'] ?? '')));
          return $residentFirstName !== ''
            && ($accountFirstName === $residentFirstName
              || str_starts_with($accountFirstName, $residentFirstName . ' ')
              || str_starts_with($residentFirstName, $accountFirstName . ' '));
        }
      ));
      if (count($matchingResidents) === 1) {
        $resident = $matchingResidents[0];
        $_SESSION['user']['resident_id'] = (int)$resident['id'];
        $user['resident_id'] = (int)$resident['id'];
      }
    }
    if ($resident) {
      if (empty($resident['date_of_birth']) && !empty($resident['birthdate'])) {
        $resident['date_of_birth'] = $resident['birthdate'];
      }
      if (empty($resident['birthdate']) && !empty($resident['date_of_birth'])) {
        $resident['birthdate'] = $resident['date_of_birth'];
      }
    }
    if ($resident && !empty($resident['barangay_id'])) {
      $bgyStmt = $pdo->prepare("SELECT name FROM barangays WHERE id = :id LIMIT 1");
      $bgyStmt->execute(['id' => (int)$resident['barangay_id']]);
      $loadedBarangay = $bgyStmt->fetchColumn();
      if ($loadedBarangay) {
        $resident['barangay'] = (string)$loadedBarangay;
      }
    }
    if (!$resident && (!empty($user['first_name']) || !empty($user['name']) || !empty($user['email']))) {
      try {
        $autoFn = !empty($user['first_name']) ? $user['first_name'] : (explode(' ', $user['name'] ?? 'Resident')[0] ?? 'Resident');
        $autoLn = !empty($user['last_name']) ? $user['last_name'] : (explode(' ', $user['name'] ?? 'Account')[1] ?? 'Account');
        $autoEm = !empty($user['email']) ? $user['email'] : ('user_' . rand(1000, 9999) . '@rhu.gov.ph');

        $insPrimaryRes = $pdo->prepare("
          INSERT INTO residents (first_name, last_name, email, date_of_birth, address, barangay, is_active, created_at, updated_at)
          VALUES (:f, :l, :e, '1995-01-01', 'Nasugbu, Batangas', 'Aplaya', 1, NOW(), NOW())
        ");
        $insPrimaryRes->execute(['f' => $autoFn, 'l' => $autoLn, 'e' => $autoEm]);
        $newPrimaryResId = (int)$pdo->lastInsertId();

        $fetchStmt = $pdo->prepare("SELECT * FROM residents WHERE id = :id LIMIT 1");
        $fetchStmt->execute(['id' => $newPrimaryResId]);
        $resident = $fetchStmt->fetch();
        if ($resident && !empty($_SESSION['user'])) {
          $_SESSION['user']['resident_id'] = $newPrimaryResId;
          $user['resident_id'] = $newPrimaryResId;
        }
      } catch (Throwable $tAutoRes) {
      }
    }

    if ($resident) {
      $residentId = (int)$resident['id'];
      try {
        $emergencyCountStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs
          WHERE action = 'URGENT: Emergency Referral'
            AND module_name = 'Resident Portal'
            AND record_id = :resident_id
            AND created_at >= :year_start
            AND created_at <= NOW()");
        $emergencyCountStmt->execute([
          'resident_id' => $residentId,
          'year_start' => date('Y-01-01 00:00:00'),
        ]);
        $emergencyRequestCount = (int)$emergencyCountStmt->fetchColumn();
      } catch (Throwable $emergencyCountError) {
        error_log('Resident emergency request count: ' . $emergencyCountError->getMessage());
      }
      $residentSex = strtolower(trim((string)($resident['sex'] ?? $resident['gender'] ?? '')));
      $isFemaleResident = in_array($residentSex, ['female', 'f', 'woman'], true);
      if (false && function_exists('rhuEnv') && rhuEnv('RHU_ALLOW_PORTAL_SCHEMA', '0') === '1') $pdo->exec(
        "CREATE TABLE IF NOT EXISTS resident_dependents (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    primary_resident_id BIGINT UNSIGNED NOT NULL,
                    dependent_resident_id BIGINT UNSIGNED NULL,
                    first_name VARCHAR(100) NOT NULL,
                    middle_name VARCHAR(100) NULL,
                    last_name VARCHAR(100) NOT NULL,
                    relationship VARCHAR(40) NOT NULL,
                    date_of_birth DATE NOT NULL,
                    gender VARCHAR(20) NULL,
                    blood_type VARCHAR(10) NULL,
                    medical_notes TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_resident_dependents_primary (primary_resident_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
      );

      if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // Validate CSRF token
        $submittedCsrf = $_POST['csrf_token'] ?? '';
        $csrfValid = !empty($submittedCsrf) && hash_equals($dashboardCsrf, $submittedCsrf);

        $formType = $_POST['form'] ?? '';
        if ($formType === 'add_dependent') {
          if (!$csrfValid) {
            $dependentErrors[] = 'Security validation failed. Please try again.';
          } else {
            $firstName = trim($_POST['first_name'] ?? '');
            $middleName = trim($_POST['middle_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $relationship = trim($_POST['relationship'] ?? 'Family Member');
            if (empty($relationship)) $relationship = 'Family Member';
            $rawDob = trim($_POST['date_of_birth'] ?? '');
            $parsedTime = strtotime($rawDob);
            $dateOfBirth = ($parsedTime && $parsedTime <= time()) ? date('Y-m-d', $parsedTime) : '';
            $gender = trim($_POST['gender'] ?? '');
            $bloodType = trim($_POST['blood_type'] ?? '');
            $medicalNotes = trim($_POST['medical_notes'] ?? '');

            if ($firstName === '' || $lastName === '') {
              $dependentErrors[] = 'First name and last name are required.';
            } elseif ($dateOfBirth === '') {
              $dependentErrors[] = 'Please provide a valid date of birth.';
            } else {
              $residentHasColumn = static function (string $column) use ($pdo): bool {
                return residentColumnExists($pdo, 'residents', $column);
              };
              $dobColumns = array_values(array_filter(['birthdate', 'date_of_birth'], $residentHasColumn));
              $dobParams = [];
              $dobConditions = [];
              foreach ($dobColumns as $index => $column) {
                $param = 'dependent_dob_' . $index;
                $dobConditions[] = "{$column} = :{$param}";
                $dobParams[$param] = $dateOfBirth;
              }
              $dobCondition = $dobConditions ? '(' . implode(' OR ', $dobConditions) . ')' : '1 = 0';
              $checkRes = $pdo->prepare("SELECT id FROM residents
                WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(:dependent_first_name))
                  AND LOWER(TRIM(last_name)) = LOWER(TRIM(:dependent_last_name))
                  AND {$dobCondition}
                LIMIT 1");
              $checkRes->execute(array_merge([
                'dependent_first_name' => $firstName,
                'dependent_last_name' => $lastName,
              ], $dobParams));
              $dependentResidentId = (int)($checkRes->fetchColumn() ?: 0);

              // Dependents are resident records without login credentials.
              if (!$dependentResidentId) {
                $insertValues = [
                  'first_name' => $firstName,
                  'last_name' => $lastName,
                  'middle_name' => $middleName ?: null,
                  'birthdate' => $dateOfBirth,
                  'date_of_birth' => $dateOfBirth,
                  'sex' => $gender ?: 'Other',
                  'gender' => $gender ?: 'Other',
                  'civil_status' => 'Dependent',
                  'barangay_id' => (int)($resident['barangay_id'] ?? 1),
                  'address' => $resident['address'] ?: 'Nasugbu, Batangas',
                  'contact_number' => $resident['contact_number'] ?: null,
                  'password_hash' => null,
                  'status' => 'Active',
                  'blood_type' => $bloodType ?: null,
                  'medical_conditions' => $medicalNotes ?: null,
                  'is_active' => 1,
                  'emergency_contact_name' => trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')),
                  'emergency_contact_number' => $resident['contact_number'] ?: null,
                  'emergency_contact_phone' => $resident['contact_number'] ?: null,
                  'created_at' => null,
                  'updated_at' => null,
                ];
                $insertColumns = [];
                $insertPlaceholders = [];
                $insertParams = [];
                foreach ($insertValues as $column => $value) {
                  if (!$residentHasColumn($column) || in_array($column, ['created_at', 'updated_at'], true)) continue;
                  $insertColumns[] = $column;
                  if ($column === 'created_at' || $column === 'updated_at') {
                    $insertPlaceholders[] = 'NOW()';
                  } else {
                    $param = 'dependent_' . $column;
                    $insertPlaceholders[] = ':' . $param;
                    $insertParams[$param] = $value;
                  }
                }
                foreach (['created_at', 'updated_at'] as $timestampColumn) {
                  if ($residentHasColumn($timestampColumn)) {
                    $insertColumns[] = $timestampColumn;
                    $insertPlaceholders[] = 'NOW()';
                  }
                }
                $insRes = $pdo->prepare('INSERT INTO residents (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertPlaceholders) . ')');
                $insRes->execute($insertParams);
                $dependentResidentId = (int)$pdo->lastInsertId();
              } else {
                $updateValues = [
                  'middle_name' => $middleName ?: null,
                  'sex' => $gender ?: 'Other',
                  'gender' => $gender ?: 'Other',
                  'blood_type' => $bloodType ?: null,
                  'medical_conditions' => $medicalNotes ?: null,
                  'address' => $resident['address'] ?: 'Nasugbu, Batangas',
                  'barangay_id' => (int)($resident['barangay_id'] ?? 1),
                  'emergency_contact_name' => trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')),
                  'emergency_contact_number' => $resident['contact_number'] ?: null,
                  'emergency_contact_phone' => $resident['contact_number'] ?: null,
                ];
                $updateParts = [];
                $updateParams = ['dependent_id' => $dependentResidentId];
                foreach ($updateValues as $column => $value) {
                  if (!$residentHasColumn($column)) continue;
                  $param = 'dependent_update_' . $column;
                  $updateParts[] = "{$column} = :{$param}";
                  $updateParams[$param] = $value;
                }
                if ($residentHasColumn('updated_at')) $updateParts[] = 'updated_at = NOW()';
                if ($updateParts) {
                  $updateDependentProfile = $pdo->prepare('UPDATE residents SET ' . implode(', ', $updateParts) . ' WHERE id = :dependent_id');
                  $updateDependentProfile->execute($updateParams);
                }
              }

              $primaryIsMale = in_array(strtolower(trim((string)($resident['sex'] ?? $resident['gender'] ?? ''))), ['male', 'm', 'man'], true);
              $dependentIsFemale = in_array(strtolower(trim((string)$gender)), ['female', 'f', 'woman'], true);
              $relationshipIsSpouse = in_array(strtolower(trim((string)$relationship)), ['spouse', 'wife'], true);
              if ($primaryIsMale && $dependentIsFemale && $relationshipIsSpouse) {
                $marriedUpdates = [];
                $marriedParams = ['dependent_id' => $dependentResidentId];
                foreach (['civil_status', 'marital_status'] as $statusColumn) {
                  if (residentColumnExists($pdo, 'residents', $statusColumn)) {
                    $marriedUpdates[] = "{$statusColumn} = :{$statusColumn}";
                    $marriedParams[$statusColumn] = 'Married';
                  }
                }
                if (residentColumnExists($pdo, 'residents', 'updated_at')) $marriedUpdates[] = 'updated_at = NOW()';
                if ($marriedUpdates) {
                  $marriedStmt = $pdo->prepare('UPDATE residents SET ' . implode(', ', $marriedUpdates) . ' WHERE id = :dependent_id');
                  $marriedStmt->execute($marriedParams);
                }
              }

              $statement = $pdo->prepare("INSERT INTO audit_logs
                (action, module_name, record_id, entity_type, entity_id, description, ip_address, user_agent, created_at)
                VALUES ('Resident Dependent', 'Resident Portal', :resident_id, 'resident', :dependent_id, :description, :ip, :ua, NOW())");
              $statement->execute([
                'resident_id' => $residentId,
                'dependent_id' => $dependentResidentId,
                'description' => json_encode(['relationship' => $relationship], JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
              ]);

              $_SESSION['resident_dashboard_dependent_flash'] = "{$firstName} {$lastName} was added to your household and registered as a resident.";
              header('Location: ResidentDashboard.php?tab=family');
              exit;
            }
          }
        } elseif ($formType === 'edit_dependent') {
          if (!$csrfValid) {
            $dependentErrors[] = 'Security validation failed. Please try again.';
          } else {
            $linkId = (int)($_POST['dependent_link_id'] ?? 0);
            $dependentResidentId = (int)($_POST['dependent_resident_id'] ?? 0);
            $firstName = trim($_POST['first_name'] ?? '');
            $middleName = trim($_POST['middle_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $relationship = trim($_POST['relationship'] ?? 'Family Member') ?: 'Family Member';
            $rawDob = trim($_POST['date_of_birth'] ?? '');
            $parsedTime = strtotime($rawDob);
            $dateOfBirth = ($parsedTime && $parsedTime <= time()) ? date('Y-m-d', $parsedTime) : '';
            $gender = trim($_POST['gender'] ?? '');
            $bloodType = trim($_POST['blood_type'] ?? '');
            $medicalNotes = trim($_POST['medical_notes'] ?? '');

            if ($linkId <= 0 || $dependentResidentId <= 0) {
              $dependentErrors[] = 'Dependent record not found.';
            } elseif ($firstName === '' || $lastName === '') {
              $dependentErrors[] = 'First name and last name are required.';
            } elseif ($dateOfBirth === '') {
              $dependentErrors[] = 'Please provide a valid date of birth.';
            } else {
              $linkStmt = $pdo->prepare("SELECT id, description FROM audit_logs WHERE id = :id AND record_id = :resident_id AND entity_id = :dependent_id AND action = 'Resident Dependent' AND module_name = 'Resident Portal' LIMIT 1");
              $linkStmt->execute(['id' => $linkId, 'resident_id' => $residentId, 'dependent_id' => $dependentResidentId]);
              $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
              if (!$linkRow) {
                $dependentErrors[] = 'Dependent record not found.';
              } else {
                $updateDependentProfile = $pdo->prepare("UPDATE residents SET
                    first_name = :fn,
                    middle_name = :mn,
                    last_name = :ln,
                    birthdate = :birthdate,
                    date_of_birth = :date_of_birth,
                    sex = :gender,
                    blood_type = :bt,
                    medical_conditions = :med,
                    updated_at = NOW()
                  WHERE id = :id");
                $updateDependentProfile->execute([
                  'fn' => $firstName,
                  'mn' => $middleName !== '' ? $middleName : null,
                  'ln' => $lastName,
                  'birthdate' => $dateOfBirth,
                  'date_of_birth' => $dateOfBirth,
                  'gender' => $gender ?: 'Other',
                  'bt' => $bloodType ?: null,
                  'med' => $medicalNotes ?: null,
                  'id' => $dependentResidentId,
                ]);

                $relationshipData = json_decode((string)($linkRow['description'] ?? ''), true);
                if (!is_array($relationshipData)) $relationshipData = [];
                $relationshipData['relationship'] = $relationship;
                $relationshipData['date_of_birth'] = $dateOfBirth;
                $relationshipData['gender'] = $gender;
                $relationshipData['updated_by_household'] = true;
                $relationshipData['updated_at'] = date('Y-m-d H:i:s');
                $updateLink = $pdo->prepare("UPDATE audit_logs SET description = :description WHERE id = :id AND record_id = :resident_id AND action = 'Resident Dependent' AND module_name = 'Resident Portal'");
                $updateLink->execute([
                  'description' => json_encode($relationshipData, JSON_UNESCAPED_UNICODE),
                  'id' => $linkId,
                  'resident_id' => $residentId,
                ]);

                $primaryIsMale = in_array(strtolower(trim((string)($resident['sex'] ?? $resident['gender'] ?? ''))), ['male', 'm', 'man'], true);
                $dependentIsFemale = in_array(strtolower(trim((string)$gender)), ['female', 'f', 'woman'], true);
                $relationshipIsSpouse = in_array(strtolower(trim((string)$relationship)), ['spouse', 'wife'], true);
                if ($primaryIsMale && $dependentIsFemale && $relationshipIsSpouse) {
                  $marriedUpdates = [];
                  $marriedParams = ['dependent_id' => $dependentResidentId];
                  foreach (['civil_status', 'marital_status'] as $statusColumn) {
                    if (residentColumnExists($pdo, 'residents', $statusColumn)) {
                      $marriedUpdates[] = "{$statusColumn} = :{$statusColumn}";
                      $marriedParams[$statusColumn] = 'Married';
                    }
                  }
                  if (residentColumnExists($pdo, 'residents', 'updated_at')) $marriedUpdates[] = 'updated_at = NOW()';
                  if ($marriedUpdates) {
                    $marriedStmt = $pdo->prepare('UPDATE residents SET ' . implode(', ', $marriedUpdates) . ' WHERE id = :dependent_id');
                    $marriedStmt->execute($marriedParams);
                  }
                }

                portalSaveHealthRecordEntry($pdo, $dependentResidentId, [
                  'record_type' => 'Dependent profile update',
                  'blood_type' => $bloodType,
                  'medical_conditions' => $medicalNotes,
                  'notes' => "Relationship: {$relationship}\nDate of birth: {$dateOfBirth}\nGender: {$gender}",
                ]);

                $_SESSION['resident_dashboard_dependent_flash'] = "{$firstName} {$lastName}'s dependent profile was updated.";
                header('Location: ResidentDashboard.php?tab=family');
                exit;
              }
            }
          }
        } elseif ($formType === 'remove_dependent') {
          if (!$csrfValid) {
            $dependentErrors[] = 'Security validation failed. Please try again.';
          } else {
            $dependentId = (int)($_POST['dependent_id'] ?? 0);
            if ($dependentId > 0) {
              $statement = $pdo->prepare(
                "DELETE FROM audit_logs WHERE id = :id AND record_id = :resident_id AND action = 'Resident Dependent'"
              );
              $statement->execute(['id' => $dependentId, 'resident_id' => $residentId]);
              $_SESSION['resident_dashboard_dependent_flash'] = $statement->rowCount()
                ? 'The dependent was removed from your household.'
                : 'Dependent record not found.';
              header('Location: ResidentDashboard.php?tab=family');
              exit;
            }
          }
        } elseif ($formType === 'approve_dependent_request') {
          if (!$csrfValid) {
            $dependentErrors[] = 'Security validation failed. Please try again.';
          } else {
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($linkId > 0) {
              $linkStmt = $pdo->prepare("SELECT id, description FROM audit_logs WHERE id = :id AND record_id = :resident_id AND action = 'Resident Dependent' AND module_name = 'Resident Portal' LIMIT 1");
              $linkStmt->execute(['id' => $linkId, 'resident_id' => $residentId]);
              $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
              if ($linkRow) {
                $data = json_decode((string)($linkRow['description'] ?? '{}'), true);
                if (!is_array($data)) $data = [];
                $data['status'] = 'approved';
                $data['approved_by'] = $residentId;
                $data['approved_at'] = date('Y-m-d H:i:s');
                $updateStmt = $pdo->prepare("UPDATE audit_logs SET description = :description WHERE id = :id AND record_id = :resident_id AND action = 'Resident Dependent' AND module_name = 'Resident Portal'");
                $updateStmt->execute([
                  'description' => json_encode($data, JSON_UNESCAPED_UNICODE),
                  'id' => $linkId,
                  'resident_id' => $residentId,
                ]);
                $_SESSION['resident_dashboard_dependent_flash'] = 'Dependent request approved.';
              } else {
                $_SESSION['resident_dashboard_dependent_flash'] = 'Dependent request not found.';
              }
              header('Location: ResidentDashboard.php?tab=family');
              exit;
            }
          }
        } elseif ($formType === 'reject_dependent_request') {
          if (!$csrfValid) {
            $dependentErrors[] = 'Security validation failed. Please try again.';
          } else {
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($linkId > 0) {
              $linkStmt = $pdo->prepare("SELECT id, description FROM audit_logs WHERE id = :id AND record_id = :resident_id AND action = 'Resident Dependent' AND module_name = 'Resident Portal' LIMIT 1");
              $linkStmt->execute(['id' => $linkId, 'resident_id' => $residentId]);
              $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
              if ($linkRow) {
                $data = json_decode((string)($linkRow['description'] ?? '{}'), true);
                if (!is_array($data)) $data = [];
                $data['status'] = 'rejected';
                $data['rejected_by'] = $residentId;
                $data['rejected_at'] = date('Y-m-d H:i:s');
                $updateStmt = $pdo->prepare("UPDATE audit_logs SET description = :description WHERE id = :id AND record_id = :resident_id AND action = 'Resident Dependent' AND module_name = 'Resident Portal'");
                $updateStmt->execute([
                  'description' => json_encode($data, JSON_UNESCAPED_UNICODE),
                  'id' => $linkId,
                  'resident_id' => $residentId,
                ]);
                $_SESSION['resident_dashboard_dependent_flash'] = 'Dependent request rejected.';
              } else {
                $_SESSION['resident_dashboard_dependent_flash'] = 'Dependent request not found.';
              }
              header('Location: ResidentDashboard.php?tab=family');
              exit;
            }
          }
        } elseif ($formType === 'contact') {
          $subject = trim($_POST['subject'] ?? 'General Inquiry');
          $message = trim($_POST['message'] ?? '');
          if ($message === '') {
            $contactErrors[] = 'Please type your message before sending.';
          } else {
            if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
              $ins = $pdo->prepare("INSERT INTO messages (resident_id, subject, message, status, created_at) VALUES (:res, :subject, :msg, 'Pending', NOW())");
              $ins->execute(['res' => $residentId, 'subject' => $subject, 'msg' => $message]);
            } else {
              $ins = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, description, ip_address, user_agent, created_at) VALUES ('Resident Message', 'Resident Portal', :res, :msg, :ip, :ua, NOW())");
              $ins->execute(['res' => $residentId, 'msg' => "{$subject}\n{$message}", 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
            }
            if (function_exists('portalNotify')) {
              $resName = trim(($resident['first_name'] ?? 'Resident') . ' ' . ($resident['last_name'] ?? ''));
              portalNotifyRhuStaff($pdo, "New resident message from {$resName}: {$subject}", 'RHUAdminDashboard.php');
            }
            $_SESSION['resident_dashboard_message_flash'] = 'Your message has been sent directly to the RHU Staff & Admin. We will respond shortly.';
            header('Location: ResidentDashboard.php?tab=contact');
            exit;
          }
        } elseif ($formType === 'certificate_request') {
          $certificateType = trim($_POST['certificate_type'] ?? '');
          if ($certificateType === '') {
            $certificateErrors[] = 'Please choose a certificate to request.';
          } else {
            $certNo = 'REQ-' . $residentId . '-' . rand(1000, 9999);
            $ins = $pdo->prepare("INSERT INTO certificates (resident_id, issued_by_id, certificate_number, certificate_type, purpose, issue_date, expiry_date, status, created_at) VALUES (:res, NULL, :cno, :type, :purp, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 6 MONTH), 'Pending', NOW())");
            $ins->execute(['res' => $residentId, 'cno' => $certNo, 'type' => $certificateType, 'purp' => "Portal Request: {$certificateType}"]);
            recordResidentChange($pdo, $residentId, 'Resident Certificate Requested', [
              'certificate_type' => $certificateType,
              'certificate_number' => $certNo,
              'purpose' => "Portal Request: {$certificateType}",
            ]);
            if (function_exists('portalNotify')) {
              $resName = trim(($resident['first_name'] ?? 'Resident') . ' ' . ($resident['last_name'] ?? ''));
              portalNotifyRhuStaff($pdo, "New certificate request from {$resName}: {$certificateType}", 'RHUAdminDashboard.php');
            }
            $_SESSION['resident_dashboard_certificate_flash'] = "Request for {$certificateType} submitted to RHU Staff & Admin for processing.";
            header('Location: ResidentDashboard.php?tab=certificates');
            exit;
          }
        } elseif ($formType === 'appointment_request') {
          $chiefComplaint = trim($_POST['chief_complaint'] ?? 'Primary Health Checkup');
          $appointmentType = trim($_POST['appointment_type'] ?? 'General Medical Consultation');
          $appointmentResidentId = $residentId;
          $requestedPatientId = (int)($_POST['patient_resident_id'] ?? 0);
          if ($requestedPatientId > 0 && $requestedPatientId !== $residentId) {
            $patientLink = $pdo->prepare("SELECT entity_id FROM audit_logs
              WHERE action = 'Resident Dependent' AND module_name = 'Resident Portal'
                AND record_id = :resident_id AND entity_id = :dependent_id
                AND LOWER(COALESCE(description, '')) NOT LIKE '%rejected%'
                AND LOWER(COALESCE(description, '')) NOT LIKE '%inactive%'
                ORDER BY id DESC LIMIT 1");
            $patientLink->execute(['resident_id' => $residentId, 'dependent_id' => $requestedPatientId]);
            if ((int)$patientLink->fetchColumn() === $requestedPatientId) {
              $appointmentResidentId = $requestedPatientId;
            }
          }

          try {
            $residentSexColumns = residentColumnExists($pdo, 'residents', 'sex')
            ? 'sex'
            : (residentColumnExists($pdo, 'residents', 'gender') ? 'gender' : "'' AS sex");
            $residentGenderColumns = residentColumnExists($pdo, 'residents', 'gender')
            ? 'gender'
            : (residentColumnExists($pdo, 'residents', 'sex') ? 'sex' : "'' AS gender");
            $patientSexStmt = $pdo->prepare("SELECT {$residentSexColumns}, {$residentGenderColumns} FROM residents WHERE id = :id LIMIT 1");
            $patientSexStmt->execute(['id' => $appointmentResidentId]);
            $patientSexRow = $patientSexStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $patientSex = strtolower(trim((string)($patientSexRow['sex'] ?? $patientSexRow['gender'] ?? '')));
            $appointmentIsFemale = in_array($patientSex, ['female', 'f', 'woman'], true);
            if (!$appointmentIsFemale && str_contains(strtolower($appointmentType), 'prenatal')) {
              $_SESSION['resident_dashboard_message_flash'] = 'Prenatal and maternal appointments are available only for female residents.';
              header('Location: ResidentDashboard.php?tab=records');
              exit;
            }
            $preferredDate = trim($_POST['preferred_date'] ?? date('Y-m-d'));
            if (!$csrfValid) {
              throw new RuntimeException('Security validation failed. Please refresh and try again.');
            }
            $preferredTime = trim((string)($_POST['preferred_time'] ?? ''));
            rhuValidateAppointmentTime($preferredTime);
            $appointmentDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $preferredDate . ' ' . $preferredTime);
            if (!$appointmentDateTime || $appointmentDateTime->format('Y-m-d H:i') !== $preferredDate . ' ' . $preferredTime) {
              throw new RuntimeException('Please choose a valid appointment date and time.');
            }
            if ($appointmentDateTime <= new DateTimeImmutable()) {
              throw new RuntimeException('Please choose an appointment time in the future.');
            }
            $selectedStaffId = !empty($_POST['health_worker_id'])
              ? (int)$_POST['health_worker_id']
              : (!empty($_POST['physician_id']) ? (int)$_POST['physician_id'] : null);
            if ($appointmentResidentId <= 0 || !$patientSexRow) {
              throw new RuntimeException('The selected resident record could not be found. Please refresh and try again.');
            }
            if ($selectedStaffId === null) {
              throw new RuntimeException('Please choose an available healthcare provider for this appointment.');
            }
            $selectedProviderName = 'selected provider';
            if ($selectedStaffId !== null) {
              $providerStmt = $pdo->prepare("SELECT id, first_name, last_name,
                       COALESCE(NULLIF(position_title, ''), NULLIF(role, ''), 'RHU Staff') AS staff_type,
                       COALESCE(NULLIF(position_title, ''), NULLIF(role, ''), 'Healthcare Staff') AS specialization,
                       position_title,
                       role
                FROM health_workers
                WHERE id = :id AND LOWER(COALESCE(status, 'active')) NOT IN ('inactive', 'disabled', 'terminated', 'deleted')
                LIMIT 1");
              $providerStmt->execute(['id' => $selectedStaffId]);
              $selectedProvider = $providerStmt->fetch(PDO::FETCH_ASSOC);
              if (!$selectedProvider) {
                throw new RuntimeException('The selected healthcare provider is no longer available. Please choose another provider.');
              }
              if (!residentProviderMatchesAppointmentType($appointmentType, $selectedProvider)) {
                throw new RuntimeException('The selected healthcare provider does not match this appointment type. Please choose one of the available providers.');
              }
              $selectedProviderName = trim(($selectedProvider['first_name'] ?? '') . ' ' . ($selectedProvider['last_name'] ?? '')) ?: 'selected provider';
            }

          $notes = "Appointment Category: {$appointmentType} | Booking Date: {$preferredDate} | Appointment Time: {$preferredTime} | Assigned Provider: {$selectedProviderName} | Requested via Resident Portal";

          $consultColumns = ['resident_id', 'consultation_date', 'consultation_time', 'chief_complaint', 'diagnosis', 'remarks', 'created_at'];
          $consultValues = [':res', ':cdate', ':ctime', ':chief', ':diagnosis', ':remarks', 'NOW()'];
          $consultParams = [
            'res' => $appointmentResidentId,
            'cdate' => $preferredDate,
            'ctime' => $preferredTime . ':00',
            'chief' => "[{$appointmentType}] {$chiefComplaint}",
            'diagnosis' => 'Pending OPD Triage',
            'remarks' => $notes,
          ];
          if ($selectedStaffId && residentColumnExists($pdo, 'consultations', 'physician_id')) {
            $consultColumns[] = 'physician_id';
            $consultValues[] = ':pid';
            $consultParams['pid'] = $selectedStaffId;
          }
          if ($selectedStaffId && residentColumnExists($pdo, 'consultations', 'health_worker_id')) {
            $consultColumns[] = 'health_worker_id';
            $consultValues[] = ':hwid';
            $consultParams['hwid'] = $selectedStaffId;
          }
          if (residentColumnExists($pdo, 'consultations', 'consultation_notes')) {
            $consultColumns[] = 'consultation_notes';
            $consultValues[] = ':notes';
            $consultParams['notes'] = $notes;
          }
          if (residentColumnExists($pdo, 'consultations', 'consultation_status')) {
            $consultColumns[] = 'consultation_status';
            $consultValues[] = ':status';
            $consultParams['status'] = 'Scheduled';
          }
          $ins = $pdo->prepare("INSERT INTO consultations (" . implode(', ', $consultColumns) . ") VALUES (" . implode(', ', $consultValues) . ")");
          $ins->execute($consultParams);
          recordResidentChange($pdo, $appointmentResidentId, 'Resident Consultation Requested', [
            'appointment_type' => $appointmentType,
            'chief_complaint' => $chiefComplaint,
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
            'health_worker_id' => $selectedStaffId,
            'physician_id' => $selectedStaffId,
          ]);
          portalSaveHealthRecordEntry($pdo, $appointmentResidentId, [
            'record_type' => 'Resident appointment request',
            'chief_complaint' => "[{$appointmentType}] {$chiefComplaint}",
            'diagnosis' => 'Pending OPD Triage',
            'notes' => $notes,
            'status' => 'Scheduled',
            'date' => $preferredDate,
          ]);

          if (function_exists('portalNotifyRhuStaff')) {
            $resName = trim(($resident['first_name'] ?? 'Resident') . ' ' . ($resident['last_name'] ?? ''));
            $appointmentMessage = "New {$appointmentType} appointment booked by {$resName} for {$preferredDate} at {$preferredTime} with {$selectedProviderName}.";
            portalNotifyRhuStaff($pdo, $appointmentMessage, 'RHUAdminDashboard.php');
            if (function_exists('portalNotify')) {
              $appointmentTypeLower = strtolower($appointmentType);
              $notifyRole = 'NURSE';
              $notifyLink = 'NurseDashboard.php?tab=opd';
              if (str_contains($appointmentTypeLower, 'prenatal') || str_contains($appointmentTypeLower, 'maternal')) {
                $notifyRole = 'MIDWIFE';
                $notifyLink = 'MidwifeDashboard.php?tab=opd';
              } elseif (str_contains($appointmentTypeLower, 'laboratory') || str_contains($appointmentTypeLower, 'blood')) {
                $notifyRole = 'MEDTECH';
                $notifyLink = 'MedTechDashboard.php';
              } elseif (str_contains($appointmentTypeLower, 'sanitary') || str_contains($appointmentTypeLower, 'inspection') || str_contains($appointmentTypeLower, 'clearance')) {
                $notifyRole = 'SANITARY';
                $notifyLink = 'SanitaryDashboard.php';
              } elseif (str_contains($appointmentTypeLower, 'dental') || str_contains($appointmentTypeLower, 'dentist') || str_contains($appointmentTypeLower, 'tooth') || str_contains($appointmentTypeLower, 'oral')) {
                $notifyRole = 'DENTAL';
                $notifyLink = 'DentalDashboard.php?tab=queue';
              }
              portalNotify($pdo, $appointmentMessage, null, $notifyRole, $notifyLink);
            }
          }

            $_SESSION['resident_dashboard_message_flash'] = "Appointment request for {$appointmentType} on {$preferredDate} at {$preferredTime} submitted successfully for the selected resident!";
            $_SESSION['resident_appointment_calendar'] = [
              'start' => $preferredDate . ' ' . $preferredTime,
              'title' => 'RHU appointment request: ' . $appointmentType,
            ];
            $_SESSION['resident_appointment_calendar_flash'] = $_SESSION['resident_appointment_calendar'];
            header('Location: ResidentDashboard.php?tab=records');
            exit;
          } catch (Throwable $appointmentError) {
            error_log('Resident OPD appointment request failed: ' . $appointmentError->getMessage());
            $_SESSION['resident_dashboard_message_flash'] = 'OPD request was not saved: ' . $appointmentError->getMessage();
            header('Location: ResidentDashboard.php?tab=records');
            exit;
          }
        }
        // Handling Emergency Referral Request
        elseif ($formType === 'emergency_request') {
          $nature = trim($_POST['emergency_nature'] ?? 'Medical Emergency');
          $location = trim($_POST['pickup_location'] ?? ($resident['address'] ?? 'Barangay Area'));

          $ins = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, description, ip_address, user_agent, created_at) VALUES ('URGENT: Emergency Referral', 'Resident Portal', :res, :msg, :ip, :ua, NOW())");
          $ins->execute([
            'res' => $residentId,
            'msg' => "EMERGENCY REFERRAL REQUEST - Type: {$nature} | Location: {$location}",
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
          ]);
          $resName = trim(($resident['first_name'] ?? 'Resident') . ' ' . ($resident['last_name'] ?? ''));
          $contact = trim((string)($resident['contact_number'] ?? '')) ?: 'Not provided';
          portalNotify($pdo, "URGENT emergency referral from {$resName}: {$nature}. Pickup: {$location}. Contact: {$contact}.", null, 'RHU_ADMIN', 'RHUAdminDashboard.php?tab=emergency');

          $_SESSION['resident_dashboard_message_flash'] = 'Emergency request sent to the RHU admin. For immediate assistance, call the emergency hotline.';
          $_SESSION['resident_dashboard_auto_dismiss_flash'] = true;
          header('Location: ResidentDashboard.php?tab=emergency');
          exit;
        }
        // Handling Update Health Profile
        elseif ($formType === 'update_personal_profile') {
          try {
          if (!$csrfValid) throw new RuntimeException('Your form expired. Refresh the page and try again.');
          $firstName = trim($_POST['first_name'] ?? '');
          $middleName = trim($_POST['middle_name'] ?? '');
          $lastName = trim($_POST['last_name'] ?? '');
          $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
          $sexValue = trim($_POST['sex'] ?? $_POST['gender'] ?? '');
          $civilStatus = trim($_POST['civil_status'] ?? '');
          [$middleName, $lastName] = applyMarriedSurnameRule($pdo, $residentId, $civilStatus, $middleName, $lastName, (string)($resident['last_name'] ?? ''));
          $contactNumber = trim($_POST['contact_number'] ?? '');
          $email = trim($_POST['email'] ?? '');
          $barangay = trim($_POST['barangay'] ?? '');
          $address = trim($_POST['address'] ?? '');

          if ($firstName === '' || $lastName === '' || $dateOfBirth === '') {
            $_SESSION['resident_dashboard_message_flash'] = 'First name, last name, and date of birth are required.';
            header('Location: ResidentDashboard.php?tab=profile');
            exit;
          }

          $birthDateObj = DateTime::createFromFormat('!Y-m-d', $dateOfBirth);
          if (!$birthDateObj || $birthDateObj->format('Y-m-d') !== $dateOfBirth || $dateOfBirth > date('Y-m-d')) {
            $_SESSION['resident_dashboard_message_flash'] = 'Please choose a valid date of birth.';
            header('Location: ResidentDashboard.php?tab=profile');
            exit;
          }

          $barangayId = null;
          if ($barangay !== '') {
            $barangayId = function_exists('rhuBarangayId') ? rhuBarangayId($pdo, $barangay) : null;
          }

          $updates = [
            'first_name = :first_name',
            'middle_name = :middle_name',
            'last_name = :last_name',
            'birthdate = :date_of_birth',
            'sex = :sex',
            'civil_status = :civil_status',
            'contact_number = :contact_number',
            'email = :email',
            'address = :address',
            'barangay_id = :barangay_id',
          ];
          if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'residents', 'date_of_birth')) {
            $updates[] = 'date_of_birth = :legacy_date_of_birth';
          }
          if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'residents', 'gender')) {
            $updates[] = 'gender = :legacy_gender';
          }
          $updatesBarangayName = function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'residents', 'barangay');
          if ($updatesBarangayName) {
            $updates[] = 'barangay = :barangay_name';
          }
          if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'residents', 'updated_at')) {
            $updates[] = 'updated_at = NOW()';
          }

          $updateProfileStmt = $pdo->prepare("UPDATE residents SET " . implode(",\n                ", $updates) . " WHERE id = :resident_id");

          $updateParams = [
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'sex' => $sexValue !== '' ? $sexValue : null,
            'civil_status' => $civilStatus !== '' ? $civilStatus : null,
            'contact_number' => $contactNumber !== '' ? $contactNumber : null,
            'email' => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
            'barangay_id' => $barangayId ?: ($resident['barangay_id'] ?? null),
            'resident_id' => $residentId,
          ];
          if (in_array('date_of_birth = :legacy_date_of_birth', $updates, true)) $updateParams['legacy_date_of_birth'] = $dateOfBirth;
          if (in_array('gender = :legacy_gender', $updates, true)) $updateParams['legacy_gender'] = $sexValue !== '' ? $sexValue : null;
          if ($updatesBarangayName) {
            $updateParams['barangay_name'] = $barangay !== '' ? $barangay : null;
          }
          $updateProfileStmt->execute($updateParams);

          recordResidentChange($pdo, $residentId, 'Resident Personal Profile Updated', [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'sex' => $sexValue,
            'civil_status' => $civilStatus,
            'contact_number' => $contactNumber,
            'email' => $email,
            'barangay' => $barangay,
            'address' => $address,
          ]);

          $_SESSION['user']['first_name'] = $firstName;
          $_SESSION['user']['last_name'] = $lastName;
          $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName);
          if ($email !== '') {
            $_SESSION['user']['email'] = $email;
          }

          $_SESSION['resident_dashboard_message_flash'] = 'Your personal and contact details were updated successfully.';
          header('Location: ResidentDashboard.php?tab=profile');
          exit;
          } catch (Throwable $profileError) {
            error_log('Resident personal profile save: ' . $profileError->getMessage());
            $_SESSION['resident_dashboard_message_flash'] = $profileError instanceof PDOException
                ? 'Your details could not be saved. Please try again or contact the RHU.' : $profileError->getMessage();
            header('Location: ResidentDashboard.php?tab=profile&edit_personal=1');
            exit;
          }
        }
        elseif ($formType === 'update_health_profile') {
          if (!$csrfValid) {
            $_SESSION['resident_dashboard_message_flash'] = 'Your form expired. Refresh the page and try again.';
            header('Location: ResidentDashboard.php?tab=profile'); exit;
          }

          $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
          $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
          $bloodPressure = trim($_POST['blood_pressure'] ?? '');
          $heartRate = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
          $temperature = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
          $lastCheckupDate = !empty($_POST['last_checkup_date']) ? trim($_POST['last_checkup_date']) : null;
          $smokingStatus = trim($_POST['smoking_status'] ?? '');
          $alcoholConsumption = trim($_POST['alcohol_consumption'] ?? '');
          $exerciseFrequency = trim($_POST['exercise_frequency'] ?? '');
          $dietType = trim($_POST['diet_type'] ?? '');

          $bloodType = trim($_POST['blood_type'] ?? '');
          $philhealthNo = trim($_POST['philhealth_number'] ?? '');
          $allergies = trim($_POST['allergies'] ?? '');
          $chronicConditions = trim($_POST['chronic_conditions'] ?? '');
          $currentMedications = trim($_POST['current_medications'] ?? '');
          $emergencyContactName = trim($_POST['emergency_contact_name'] ?? '');
          $emergencyContactRelationship = trim($_POST['emergency_contact_relationship'] ?? '');
          $emergencyContactPhone = trim($_POST['emergency_contact_phone'] ?? '');

          try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS resident_health_profiles (
                            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            resident_id BIGINT UNSIGNED NOT NULL UNIQUE,
                            height DOUBLE(5,2) NULL,
                            weight DOUBLE(5,2) NULL,
                            blood_pressure VARCHAR(20) NULL,
                            heart_rate INT NULL,
                            temperature DOUBLE(4,1) NULL,
                            last_checkup_date DATE NULL,
                            smoking_status VARCHAR(50) NULL,
                            alcohol_consumption VARCHAR(50) NULL,
                            exercise_frequency VARCHAR(50) NULL,
                            diet_type VARCHAR(50) NULL,
                            blood_type VARCHAR(10) NULL,
                            philhealth_number VARCHAR(50) NULL,
                            allergies TEXT NULL,
                            chronic_conditions TEXT NULL,
                            current_medications TEXT NULL,
                            emergency_contact_name VARCHAR(150) NULL,
                            emergency_contact_relationship VARCHAR(100) NULL,
                            emergency_contact_phone VARCHAR(50) NULL,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            INDEX idx_rhp_resident (resident_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            foreach (
              [
                "ALTER TABLE resident_health_profiles ADD COLUMN blood_pressure VARCHAR(20) NULL AFTER weight",
                "ALTER TABLE resident_health_profiles ADD COLUMN heart_rate INT NULL AFTER blood_pressure",
                "ALTER TABLE resident_health_profiles ADD COLUMN temperature DOUBLE(4,1) NULL AFTER heart_rate",
                "ALTER TABLE resident_health_profiles ADD COLUMN last_checkup_date DATE NULL AFTER temperature",
                "ALTER TABLE resident_health_profiles ADD COLUMN smoking_status VARCHAR(50) NULL AFTER last_checkup_date",
                "ALTER TABLE resident_health_profiles ADD COLUMN alcohol_consumption VARCHAR(50) NULL AFTER smoking_status",
                "ALTER TABLE resident_health_profiles ADD COLUMN exercise_frequency VARCHAR(50) NULL AFTER alcohol_consumption",
                "ALTER TABLE resident_health_profiles ADD COLUMN diet_type VARCHAR(50) NULL AFTER exercise_frequency",
                "ALTER TABLE resident_health_profiles ADD COLUMN blood_type VARCHAR(10) NULL AFTER diet_type",
                "ALTER TABLE resident_health_profiles ADD COLUMN philhealth_number VARCHAR(50) NULL AFTER blood_type",
                "ALTER TABLE resident_health_profiles ADD COLUMN allergies TEXT NULL AFTER philhealth_number",
                "ALTER TABLE resident_health_profiles ADD COLUMN chronic_conditions TEXT NULL AFTER allergies",
                "ALTER TABLE resident_health_profiles ADD COLUMN current_medications TEXT NULL AFTER chronic_conditions",
                "ALTER TABLE resident_health_profiles ADD COLUMN emergency_contact_name VARCHAR(150) NULL AFTER current_medications",
                "ALTER TABLE resident_health_profiles ADD COLUMN emergency_contact_relationship VARCHAR(100) NULL AFTER emergency_contact_name",
                "ALTER TABLE resident_health_profiles ADD COLUMN emergency_contact_phone VARCHAR(50) NULL AFTER emergency_contact_relationship"
              ] as $alterHpSql
            ) {
              try {
                $pdo->exec($alterHpSql);
              } catch (Throwable $tSingleHpCol) {
              }
            }
          } catch (Throwable $tHp) {
          }

          foreach (
            [
              "ALTER TABLE residents ADD COLUMN allergies TEXT NULL",
              "ALTER TABLE residents ADD COLUMN medical_conditions TEXT NULL",
              "ALTER TABLE residents ADD COLUMN emergency_contact_name VARCHAR(150) NULL",
              "ALTER TABLE residents ADD COLUMN emergency_contact_number VARCHAR(50) NULL",
              "ALTER TABLE residents ADD COLUMN emergency_contact_phone VARCHAR(50) NULL",
              "ALTER TABLE residents ADD COLUMN emergency_contact_relationship VARCHAR(100) NULL",
              "ALTER TABLE residents ADD COLUMN philhealth_id VARCHAR(50) NULL",
              "ALTER TABLE residents ADD COLUMN blood_type VARCHAR(10) NULL"
            ] as $alterSql
          ) {
            try {
              $pdo->exec($alterSql);
            } catch (Throwable $tSingleCol) {
            }
          }

          $chkStmt = $pdo->prepare("SELECT id FROM resident_health_profiles WHERE resident_id = :rid LIMIT 1");
          $chkStmt->execute(['rid' => $residentId]);
          $existingHpId = $chkStmt->fetchColumn();

          if ($existingHpId) {
            $upStmt = $pdo->prepare("UPDATE resident_health_profiles SET 
                            height = :h, weight = :w, blood_pressure = :bp, heart_rate = :hr,
                            temperature = :temp, last_checkup_date = :lcd, smoking_status = :smoke,
                            alcohol_consumption = :alc, exercise_frequency = :ex, diet_type = :dt,
                            blood_type = :bt, philhealth_number = :ph, allergies = :alg,
                            chronic_conditions = :cc, current_medications = :cm,
                            emergency_contact_name = :ecn, emergency_contact_relationship = :ecr,
                            emergency_contact_phone = :ecp, updated_at = NOW()
                            WHERE resident_id = :rid");
            $upStmt->execute([
              'h' => $height,
              'w' => $weight,
              'bp' => $bloodPressure,
              'hr' => $heartRate,
              'temp' => $temperature,
              'lcd' => $lastCheckupDate,
              'smoke' => $smokingStatus,
              'alc' => $alcoholConsumption,
              'ex' => $exerciseFrequency,
              'dt' => $dietType,
              'bt' => $bloodType,
              'ph' => $philhealthNo,
              'alg' => $allergies,
              'cc' => $chronicConditions,
              'cm' => $currentMedications,
              'ecn' => $emergencyContactName,
              'ecr' => $emergencyContactRelationship,
              'ecp' => $emergencyContactPhone,
              'rid' => $residentId
            ]);
          } else {
            $insStmt = $pdo->prepare("INSERT INTO resident_health_profiles (
                            resident_id, height, weight, blood_pressure, heart_rate, temperature,
                            last_checkup_date, smoking_status, alcohol_consumption, exercise_frequency, diet_type,
                            blood_type, philhealth_number, allergies, chronic_conditions, current_medications,
                            emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, updated_at
                        ) VALUES (
                            :rid, :h, :w, :bp, :hr, :temp, :lcd, :smoke, :alc, :ex, :dt,
                            :bt, :ph, :alg, :cc, :cm, :ecn, :ecr, :ecp, NOW()
                        )");
            $insStmt->execute([
              'rid' => $residentId,
              'h' => $height,
              'w' => $weight,
              'bp' => $bloodPressure,
              'hr' => $heartRate,
              'temp' => $temperature,
              'lcd' => $lastCheckupDate,
              'smoke' => $smokingStatus,
              'alc' => $alcoholConsumption,
              'ex' => $exerciseFrequency,
              'dt' => $dietType,
              'bt' => $bloodType,
              'ph' => $philhealthNo,
              'alg' => $allergies,
              'cc' => $chronicConditions,
              'cm' => $currentMedications,
              'ecn' => $emergencyContactName,
              'ecr' => $emergencyContactRelationship,
              'ecp' => $emergencyContactPhone
            ]);
          }

          try {
            $upRes = $pdo->prepare("UPDATE residents SET 
                            blood_type = :bt, philhealth_id = :ph, philhealth_number = :ph2, allergies = :alg, medical_conditions = :mc,
                            emergency_contact_name = :ecn, emergency_contact_relationship = :ecr,
                            emergency_contact_phone = :ecp, emergency_contact_number = :ecp2
                            WHERE id = :rid");
            $upRes->execute([
              'bt' => $bloodType,
              'ph' => $philhealthNo,
              'ph2' => $philhealthNo,
              'alg' => $allergies,
              'mc' => $chronicConditions,
              'ecn' => $emergencyContactName,
              'ecr' => $emergencyContactRelationship,
              'ecp' => $emergencyContactPhone,
              'ecp2' => $emergencyContactPhone,
              'rid' => $residentId
            ]);
          } catch (Throwable $tRes) {
            try {
              $upResFallback = $pdo->prepare("UPDATE residents SET 
                                blood_type = :bt, philhealth_id = :ph, allergies = :alg, medical_conditions = :mc,
                                emergency_contact_name = :ecn, emergency_contact_number = :ecp
                                WHERE id = :rid");
              $upResFallback->execute([
                'bt' => $bloodType,
                'ph' => $philhealthNo,
                'alg' => $allergies,
                'mc' => $chronicConditions,
                'ecn' => $emergencyContactName,
                'ecp' => $emergencyContactPhone,
                'rid' => $residentId
              ]);
            } catch (Throwable $tResFB) {
            }
          }

          try {
            $latestHealthRecordStmt = $pdo->prepare("SELECT id FROM health_records WHERE resident_id = :rid ORDER BY id DESC LIMIT 1");
            $latestHealthRecordStmt->execute(['rid' => $residentId]);
            $latestHealthRecordId = (int) ($latestHealthRecordStmt->fetchColumn() ?: 0);
            $healthRemarks = trim("Record Type: Resident health profile update\nHeart Rate: " . ($heartRate !== null ? "{$heartRate} bpm" : 'Not recorded') . "\nSmoking Status: {$smokingStatus}\nAlcohol Consumption: {$alcoholConsumption}\nExercise Frequency: {$exerciseFrequency}\nDiet Type: {$dietType}\nEmergency Contact: {$emergencyContactName} ({$emergencyContactRelationship}) {$emergencyContactPhone}");
            if ($latestHealthRecordId > 0) {
              $healthUpdate = $pdo->prepare("UPDATE health_records SET
                                blood_type = :bt,
                                height_cm = :h,
                                weight_kg = :w,
                                blood_pressure = :bp,
                                temperature = :temp,
                                allergies = :alg,
                                medical_conditions = :mc,
                                current_medications = :cm,
                                last_checkup_date = :lcd,
                                remarks = :remarks,
                                updated_at = NOW()
                                WHERE id = :id AND resident_id = :rid");
              $healthUpdate->execute([
                'bt' => $bloodType,
                'h' => $height,
                'w' => $weight,
                'bp' => $bloodPressure,
                'temp' => $temperature,
                'alg' => $allergies,
                'mc' => $chronicConditions,
                'cm' => $currentMedications,
                'lcd' => $lastCheckupDate,
                'remarks' => $healthRemarks,
                'id' => $latestHealthRecordId,
                'rid' => $residentId,
              ]);
            } else {
              portalSaveHealthRecordEntry($pdo, $residentId, [
                'record_type' => 'Resident health profile update',
                'blood_type' => $bloodType,
                'height_cm' => $height,
                'weight_kg' => $weight,
                'blood_pressure' => $bloodPressure,
                'temperature' => $temperature,
                'allergies' => $allergies,
                'medical_conditions' => $chronicConditions,
                'current_medications' => $currentMedications,
                'last_checkup_date' => $lastCheckupDate,
                'notes' => "Heart Rate: " . ($heartRate !== null ? "{$heartRate} bpm" : 'Not recorded') . "\nSmoking Status: {$smokingStatus}\nAlcohol Consumption: {$alcoholConsumption}\nExercise Frequency: {$exerciseFrequency}\nDiet Type: {$dietType}\nEmergency Contact: {$emergencyContactName} ({$emergencyContactRelationship}) {$emergencyContactPhone}",
              ]);
            }
          } catch (Throwable $tHealthRecord) {
          }

          recordResidentChange($pdo, $residentId, 'Resident Health Profile Updated', [
            'height' => $height,
            'weight' => $weight,
            'blood_pressure' => $bloodPressure,
            'heart_rate' => $heartRate,
            'temperature' => $temperature,
            'last_checkup_date' => $lastCheckupDate,
            'smoking_status' => $smokingStatus,
            'alcohol_consumption' => $alcoholConsumption,
            'exercise_frequency' => $exerciseFrequency,
            'diet_type' => $dietType,
            'blood_type' => $bloodType,
            'philhealth_number' => $philhealthNo,
            'allergies' => $allergies,
            'chronic_conditions' => $chronicConditions,
            'current_medications' => $currentMedications,
            'emergency_contact_name' => $emergencyContactName,
            'emergency_contact_relationship' => $emergencyContactRelationship,
            'emergency_contact_phone' => $emergencyContactPhone,
          ]);

          $_SESSION['resident_dashboard_message_flash'] = 'Your Health Profile has been updated successfully!';
          header('Location: ResidentDashboard.php?tab=profile');
          exit;
        }
      }

      $consultationNotesSql = residentConsultationNotesSql($pdo, 'c');
      $consultationStatusSql = residentConsultationStatusSql($pdo, 'c');
      $hasConsultationHealthWorker = residentColumnExists($pdo, 'consultations', 'health_worker_id');
      $hasConsultationPhysician = residentColumnExists($pdo, 'consultations', 'physician_id');
      $consultationProviderJoinId = $hasConsultationHealthWorker && $hasConsultationPhysician
        ? 'COALESCE(c.health_worker_id, c.physician_id)'
        : ($hasConsultationHealthWorker ? 'c.health_worker_id' : ($hasConsultationPhysician ? 'c.physician_id' : 'NULL'));
      $statement = $pdo->prepare(
        "SELECT c.*, {$consultationNotesSql} AS consultation_notes, {$consultationStatusSql} AS consultation_status, CONCAT_WS(' ', hw.first_name, hw.last_name) AS physician_name
                 FROM consultations c
                 LEFT JOIN health_workers hw ON hw.id = {$consultationProviderJoinId}
                 WHERE c.resident_id = :resident_id
                 ORDER BY c.consultation_date DESC, c.id DESC"
      );
      $statement->execute(['resident_id' => $residentId]);
      $consultations = rhuWithoutExpiredAppointments($statement->fetchAll(PDO::FETCH_ASSOC));
      foreach ($consultations as &$consultationRow) {
        $consultationRow['consultation_status'] = residentResolveConsultationStatus($consultationRow);
        $consultationRow['icd10'] = residentExtractConsultationIcd10($consultationRow);
      }
      unset($consultationRow);

      // Confidential examinations are accessible only to the examining medtech.
      $confidentialLabRecords = [];

      // Ordinary laboratory results are saved separately from consultations.
      // Load only this public record type; private examinations stay excluded.
      try {
        $statement = $pdo->prepare("SELECT hr.*, CONCAT_WS(' ', hw.first_name, hw.last_name) AS provider_name
          FROM health_records hr
          LEFT JOIN health_workers hw ON hw.id = hr.recorded_by_id
          WHERE hr.resident_id = :resident_id AND hr.remarks LIKE :record_type
          ORDER BY hr.last_checkup_date DESC, hr.created_at DESC, hr.id DESC");
        $statement->execute(['resident_id' => $residentId, 'record_type' => "Record Type: Laboratory test result\n%"]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $labRecord) {
          $fields = [];
          preg_match_all('/^(Record Type|Chief Complaint|Diagnosis|Treatment|Notes|Status|Follow Up Date): (.*?)(?=^(?:Record Type|Chief Complaint|Diagnosis|Treatment|Notes|Status|Follow Up Date): |\z)/ms', (string)$labRecord['remarks'], $matches, PREG_SET_ORDER);
          foreach ($matches as $match) $fields[$match[1]] = trim($match[2]);
          $labRecord['record_type'] = 'Laboratory test result';
          $labRecord['record_date'] = $labRecord['last_checkup_date'];
          $labRecord['title'] = $fields['Diagnosis'] ?? 'Laboratory test result';
          $labRecord['findings'] = $fields['Diagnosis'] ?? '';
          $labRecord['notes'] = $fields['Notes'] ?? '';
          $labRecord['status'] = $fields['Status'] ?? 'Completed';
          $laboratoryRecords[] = $labRecord;
        }
      } catch (Throwable $error) {
        error_log('Resident laboratory history: ' . $error->getMessage());
      }

      if (function_exists('portalEnsureDentalTables')) {
        portalEnsureDentalTables($pdo);
      }
      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'dental_records')) {
        try {
          $statement = $pdo->prepare(
            "SELECT dr.*, CONCAT_WS(' ', hw.first_name, hw.last_name) AS provider_name
             FROM dental_records dr
             LEFT JOIN health_workers hw ON hw.id = dr.performed_by
             WHERE dr.resident_id = :resident_id
             ORDER BY dr.record_date DESC, dr.id DESC"
          );
          $statement->execute(['resident_id' => $residentId]);
          $dentalRecords = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
          foreach ($dentalRecords as &$dentalRecordRow) {
            $dentalMeta = [];
            if (!empty($dentalRecordRow['meta_json'])) {
              $decodedDentalMeta = json_decode((string)$dentalRecordRow['meta_json'], true);
              if (is_array($decodedDentalMeta)) {
                $dentalMeta = $decodedDentalMeta;
              }
            }
            $dentalRecordRow['meta'] = $dentalMeta;
          }
          unset($dentalRecordRow);
          $residentDentalDashboardRecords = array_values(array_filter(
            $dentalRecords,
            static fn(array $record): bool => in_array((string)($record['record_type'] ?? ''), ['examination', 'prescription'], true)
          ));
        } catch (Throwable $ignored) {
          $dentalRecords = [];
          $residentDentalDashboardRecords = [];
        }
      }

      // Pagination for consultations
      $itemsPerPage = 4;
      $currentPage = max(1, (int)($_GET['cons_page'] ?? 1));
      $totalConsultations = count($consultations);
      $totalConsultationPages = max(1, ceil($totalConsultations / $itemsPerPage));
      $currentPage = min($currentPage, $totalConsultationPages);
      $consultationOffset = ($currentPage - 1) * $itemsPerPage;
      $consultationsPage = array_slice($consultations, $consultationOffset, $itemsPerPage);

      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'vaccination_records')) {
        try {
          $vaccinationProviderSql = rhuColumnExists($pdo, 'vaccination_records', 'provider_name')
            ? 'COALESCE(NULLIF(TRIM(vr.provider_name), ""), CONCAT_WS(" ", hw.first_name, hw.last_name))'
            : 'CONCAT_WS(" ", hw.first_name, hw.last_name)';
          $statement = $pdo->prepare(
            'SELECT vr.*, CONCAT_WS(" ", r.first_name, r.last_name) AS resident_name,
                 COALESCE(vr.vaccine_name, CONCAT("Vaccine record #", vr.id)) AS vaccine_name,
                          ' . $vaccinationProviderSql . ' AS provider_name
                   FROM vaccination_records vr
               INNER JOIN residents r ON r.id = vr.resident_id
                   LEFT JOIN health_workers hw ON hw.id = vr.health_worker_id
                   WHERE vr.resident_id = :resident_id
                   ORDER BY vr.vaccination_date DESC, vr.id DESC'
          );
          $statement->execute(['resident_id' => $residentId]);
          $vaccinationRecords = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ignored) {
          $vaccinationRecords = [];
        }
      }

      try {
        $statement = $pdo->prepare('SELECT * FROM family_planning_records WHERE resident_id = :resident_id ORDER BY id DESC');
        $statement->execute(['resident_id' => $residentId]);
        $familyPlanningRecords = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $ignored) {
      }

      if ($isFemaleResident) {
        try {
          $residentPregnancyTable = function_exists('rhuTableExists') && rhuTableExists($pdo, 'pregnancies') ? 'pregnancies' : 'pregnancy_records';
          $pregnancyHighRiskExpr = residentColumnExists($pdo, $residentPregnancyTable, 'high_risk')
            ? 'COALESCE(pr.high_risk, 0)'
            : "CASE WHEN LOWER(COALESCE(pr.risk_level, '')) LIKE '%high%' THEN 1 ELSE 0 END";
          $hasPregnancyRiskFactors = residentColumnExists($pdo, $residentPregnancyTable, 'risk_factors');
          $hasPregnancyRemarks = residentColumnExists($pdo, $residentPregnancyTable, 'remarks');
          if ($hasPregnancyRiskFactors && $hasPregnancyRemarks) {
            $pregnancyRiskFactorsExpr = "COALESCE(pr.risk_factors, pr.remarks, '')";
          } elseif ($hasPregnancyRiskFactors) {
            $pregnancyRiskFactorsExpr = "COALESCE(pr.risk_factors, '')";
          } else {
            $pregnancyRiskFactorsExpr = "COALESCE(pr.remarks, '')";
          }
          $statement = $pdo->prepare("
            SELECT pr.*,
                   {$pregnancyHighRiskExpr} AS high_risk,
                   {$pregnancyRiskFactorsExpr} AS risk_factors
            FROM {$residentPregnancyTable} pr
            WHERE pr.resident_id = :resident_id
            ORDER BY pr.updated_at DESC, pr.id DESC
          ");
          $statement->execute(['resident_id' => $residentId]);
          $pregnancyRecords = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ignored) {
        }
      }

      $statement = $pdo->prepare(
        'SELECT c.*, c.certificate_type AS certificate_type_name, c.status AS validity_status
                 FROM certificates c
                 WHERE c.resident_id = :resident_id
                 ORDER BY c.id DESC'
      );
      $statement->execute(['resident_id' => $residentId]);
      $certificates = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $groupedCertificates = [];
      foreach ($certificates as $certificateRow) {
        $purposeText = trim((string)($certificateRow['purpose'] ?? ''));
        $requestType = preg_replace('/^Portal Request:\s*/i', '', $purposeText);
        $requestType = preg_replace('/\s*\((FREE|NO PAYMENT|PHP\s*\d+(?:\.\d{1,2})?)\)\s*$/i', '', (string)$requestType);
        $requestType = trim((string)$requestType);
        if ($requestType === '') {
          $requestType = trim((string)($certificateRow['certificate_type_name'] ?? $certificateRow['certificate_type'] ?? 'Certificate'));
        }

        $groupKey = strtolower(preg_replace('/\s+/', ' ', $requestType));
        $statusText = strtolower((string)($certificateRow['validity_status'] ?? $certificateRow['status'] ?? ''));
        $statusRank = 1;
        if (str_contains($statusText, 'reject') || str_contains($statusText, 'declin')) {
          $statusRank = 5;
        } elseif ((str_contains($statusText, 'approved') || str_contains($statusText, 'issued') || str_contains($statusText, 'valid')) && !str_contains($statusText, 'invalid')) {
          $statusRank = 4;
        } elseif (str_contains($statusText, 'doctor') || str_contains($statusText, 'approval') || str_contains($statusText, 'process')) {
          $statusRank = 3;
        } elseif (str_contains($statusText, 'pending')) {
          $statusRank = 2;
        }

        $certificateRow['certificate_type_name'] = $requestType;
        $certificateRow['_display_rank'] = $statusRank;
        if (
          !isset($groupedCertificates[$groupKey])
          || $statusRank > (int)($groupedCertificates[$groupKey]['_display_rank'] ?? 0)
          || ($statusRank === (int)($groupedCertificates[$groupKey]['_display_rank'] ?? 0) && (int)$certificateRow['id'] > (int)$groupedCertificates[$groupKey]['id'])
        ) {
          $groupedCertificates[$groupKey] = $certificateRow;
        }
      }
      $certificates = array_values($groupedCertificates);
      $healthCertificates = $certificates;

      $residentMessages = [];

      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
        try {
          $messageStmt = $pdo->prepare('SELECT id, resident_id, subject, message, admin_reply, status, created_at, replied_at FROM messages WHERE resident_id = :resident_id ORDER BY created_at DESC');
          $messageStmt->execute(['resident_id' => $residentId]);
          $residentMessages = $messageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $messageLoadError) {
        }
      }

      // Keep older inquiries visible while the application transitions from audit logs to messages.
      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'audit_logs')) {
        try {
          $legacyMessageStmt = $pdo->prepare("SELECT id, record_id AS resident_id, action, description AS message, created_at
            FROM audit_logs
            WHERE module_name = 'Resident Portal' AND action = 'Resident Message' AND record_id = :resident_id
            ORDER BY created_at DESC");
          $legacyMessageStmt->execute(['resident_id' => $residentId]);
          foreach ($legacyMessageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $legacyMessage) {
            $legacyText = (string)($legacyMessage['message'] ?? '');
            $legacyReply = '';
            if (preg_match('/\s*\[Replied:\s*(.*?)\]\s*$/s', $legacyText, $replyMatch)) {
              $legacyReply = trim((string)$replyMatch[1]);
              $legacyText = trim((string)preg_replace('/\s*\[Replied:\s*.*?\]\s*$/s', '', $legacyText));
            }
            $legacyParts = preg_split('/\R/', $legacyText, 2);
            $residentMessages[] = [
              'id' => (int)$legacyMessage['id'],
              'resident_id' => (int)$legacyMessage['resident_id'],
              'subject' => trim((string)($legacyParts[0] ?? 'General Inquiry')) ?: 'General Inquiry',
              'message' => trim((string)($legacyParts[1] ?? $legacyText)),
              'admin_reply' => $legacyReply,
              'status' => $legacyReply !== '' ? 'Replied' : 'Pending',
              'created_at' => $legacyMessage['created_at'],
              'replied_at' => null,
            ];
          }
          usort($residentMessages, static fn(array $left, array $right): int => strcmp((string)$right['created_at'], (string)$left['created_at']));
        } catch (Throwable $legacyMessageLoadError) {
        }
      }

      $dependents = [];
      $pendingDependentRequests = [];
      if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'audit_logs')) {
        try {
          $dependentDobExpression = residentColumnExists($pdo, 'residents', 'date_of_birth') && residentColumnExists($pdo, 'residents', 'birthdate')
            ? "COALESCE(NULLIF(d.date_of_birth, '0000-00-00'), NULLIF(d.birthdate, '0000-00-00'))"
            : (residentColumnExists($pdo, 'residents', 'date_of_birth') ? "NULLIF(d.date_of_birth, '0000-00-00')" : (residentColumnExists($pdo, 'residents', 'birthdate') ? "NULLIF(d.birthdate, '0000-00-00')" : 'NULL'));
          $dependentAccountFilter = residentColumnExists($pdo, 'residents', 'password_hash')
            ? "AND (d.password_hash IS NULL OR d.password_hash = '')"
            : '';
          $dependentStmt = $pdo->prepare("SELECT a.id AS link_id, a.entity_id AS dependent_resident_id,
              a.description AS relationship_data, d.*,
              {$dependentDobExpression} AS date_of_birth
            FROM audit_logs a
            INNER JOIN residents d ON d.id = a.entity_id
            WHERE a.action = 'Resident Dependent'
              AND a.module_name = 'Resident Portal'
              AND a.record_id = :resident_id
              {$dependentAccountFilter}
              AND " . rhuLivingResidentWhereSql('d') . "
            ORDER BY a.id DESC");
          $dependentStmt->execute(['resident_id' => $residentId]);
          foreach ($dependentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dependent) {
            $relationshipData = json_decode((string)($dependent['relationship_data'] ?? ''), true);
            $dependencyStatus = strtolower((string)($relationshipData['status'] ?? 'approved'));
            $dependent['id'] = (int)$dependent['link_id'];
            $dependent['relationship'] = is_array($relationshipData) ? ($relationshipData['relationship'] ?? 'Family Member') : 'Family Member';
            if (strcasecmp((string)$dependent['relationship'], 'Father') === 0) {
              $dependent['relationship'] = 'Husband';
              if (is_array($relationshipData)) {
                $relationshipData['relationship'] = 'Husband';
              }
            }
            if (empty($dependent['date_of_birth']) && is_array($relationshipData) && !empty($relationshipData['date_of_birth'])) {
              $dependent['date_of_birth'] = $relationshipData['date_of_birth'];
            }
            if (empty($dependent['sex']) && is_array($relationshipData) && !empty($relationshipData['gender'])) {
              $dependent['sex'] = $relationshipData['gender'];
            }
            $dependent['dependency_status'] = $dependencyStatus;
            $dependent['medical_notes'] = $dependent['medical_conditions'] ?? '';
            $dependent['record_folder'] = ['consultations' => [], 'certificates' => [], 'vaccinations' => [], 'pregnancies' => []];
            try {
              $dependentConsultationNotesSql = residentConsultationNotesSql($pdo, 'c');
              $dependentConsultationStatusSql = residentConsultationStatusSql($pdo, 'c');
              $treatmentPlanSelect = residentColumnExists($pdo, 'consultations', 'treatment_plan') ? 'c.treatment_plan' : 'c.treatment';
              $recordStmt = $pdo->prepare("SELECT c.id, c.consultation_date, c.diagnosis, c.chief_complaint, {$treatmentPlanSelect} AS treatment_plan, {$dependentConsultationNotesSql} AS consultation_notes, c.medications_prescribed, {$dependentConsultationStatusSql} AS consultation_status, c.follow_up_date FROM consultations c WHERE c.resident_id = :resident_id ORDER BY c.consultation_date DESC, c.id DESC LIMIT 20");
              $recordStmt->execute(['resident_id' => (int)$dependent['dependent_resident_id']]);
              $dependent['record_folder']['consultations'] = $recordStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
              foreach ($dependent['record_folder']['consultations'] as &$dependentConsultationRow) {
                $dependentConsultationRow['consultation_status'] = residentResolveConsultationStatus($dependentConsultationRow);
                $dependentConsultationRow['icd10'] = residentExtractConsultationIcd10($dependentConsultationRow);
              }
              unset($dependentConsultationRow);
            } catch (Throwable $ignored) {
            }
            try {
              $recordStmt = $pdo->prepare('SELECT id, certificate_type, certificate_number, status, issue_date FROM certificates WHERE resident_id = :resident_id ORDER BY id DESC LIMIT 20');
              $recordStmt->execute(['resident_id' => (int)$dependent['dependent_resident_id']]);
              $dependent['record_folder']['certificates'] = $recordStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $ignored) {
            }
            try {
              $vaccinationProviderSql = rhuColumnExists($pdo, 'vaccination_records', 'provider_name')
                ? 'COALESCE(NULLIF(TRIM(vr.provider_name), ""), CONCAT_WS(" ", hw.first_name, hw.last_name))'
                : 'CONCAT_WS(" ", hw.first_name, hw.last_name)';
              $recordStmt = $pdo->prepare('SELECT vr.id, vr.vaccine_name, vr.dose_number, vr.vaccination_date, vr.next_dose_date, vr.batch_number, vr.site_of_injection, vr.adverse_reactions, vr.remarks, ' . $vaccinationProviderSql . ' AS provider_name FROM vaccination_records vr LEFT JOIN health_workers hw ON hw.id = vr.health_worker_id WHERE vr.resident_id = :resident_id ORDER BY vr.vaccination_date DESC, vr.id DESC LIMIT 20');
              $recordStmt->execute(['resident_id' => (int)$dependent['dependent_resident_id']]);
              $dependent['record_folder']['vaccinations'] = $recordStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $ignored) {
            }
            if (in_array(strtolower(trim((string)($dependent['sex'] ?? $dependent['gender'] ?? ''))), ['female', 'f', 'woman'], true)) {
              try {
                $pregnancyTable = 'pregnancy_records';
                if (residentColumnExists($pdo, $pregnancyTable, 'risk_level')) {
                  $riskLevelSelect = 'risk_level';
                } elseif (residentColumnExists($pdo, $pregnancyTable, 'high_risk')) {
                  $riskLevelSelect = "CASE WHEN COALESCE(high_risk, 0) = 1 THEN 'High Risk' ELSE 'Normal' END AS risk_level";
                } else {
                  $riskLevelSelect = "'Normal' AS risk_level";
                }
                if (residentColumnExists($pdo, $pregnancyTable, 'remarks')) {
                  $remarksSelect = 'remarks';
                } elseif (residentColumnExists($pdo, $pregnancyTable, 'risk_factors')) {
                  $remarksSelect = 'risk_factors AS remarks';
                } else {
                  $remarksSelect = "'' AS remarks";
                }
                $recordStmt = $pdo->prepare("SELECT id, pregnancy_status, last_menstrual_period, expected_delivery_date, {$riskLevelSelect}, {$remarksSelect} FROM {$pregnancyTable} WHERE resident_id = :resident_id ORDER BY id DESC LIMIT 20");
                $recordStmt->execute(['resident_id' => (int)$dependent['dependent_resident_id']]);
                $dependent['record_folder']['pregnancies'] = $recordStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
              } catch (Throwable $ignored) {
              }
            }

            if ($dependencyStatus === 'pending') {
              $pendingDependentRequests[] = $dependent;
              continue;
            }
            if ($dependencyStatus === 'rejected') {
              continue;
            }
            $dependents[] = $dependent;
          }
        } catch (Throwable $dependentLoadError) {
          $dependents = [];
          $pendingDependentRequests = [];
        }
      }

      $pregnantDependentSuggestions = [];
      foreach ($dependents as $dependent) {
        $residentHasAccount = !empty($dependent['password_hash'] ?? '') || !empty($dependent['email'] ?? '');
        if ($residentHasAccount) {
          continue;
        }

        $dependentPregnancies = $dependent['record_folder']['pregnancies'] ?? [];
        if (empty($dependentPregnancies)) {
          continue;
        }

        $dependentName = trim(($dependent['first_name'] ?? '') . ' ' . ($dependent['middle_name'] ?? '') . ' ' . ($dependent['last_name'] ?? ''));
        if ($dependentName === '') {
          continue;
        }

        foreach ($dependentPregnancies as $pregnancyRecord) {
          $pregnancyStatus = strtolower(trim((string)($pregnancyRecord['pregnancy_status'] ?? '')));
          if ($pregnancyStatus === '' || $pregnancyStatus === 'active' || str_contains($pregnancyStatus, 'pregnant')) {
            $pregnantDependentSuggestions[] = [
              'id' => (int)($dependent['dependent_resident_id'] ?? $dependent['id'] ?? 0),
              'name' => $dependentName,
              'first_name' => trim((string)($dependent['first_name'] ?? '')),
              'last_name' => trim((string)($dependent['last_name'] ?? '')),
              'dob' => trim((string)($dependent['date_of_birth'] ?? $dependent['birthdate'] ?? '')),
              'relationship' => $dependent['relationship'] ?? 'Family Member',
            ];
            break;
          }
        }
      }

      // Hydrate resident_health_profiles record
      $healthProfile = null;
      try {
        $hpStmt = $pdo->prepare("SELECT * FROM resident_health_profiles WHERE resident_id = :rid LIMIT 1");
        $hpStmt->execute(['rid' => $residentId]);
        $healthProfile = $hpStmt->fetch(PDO::FETCH_ASSOC);
      } catch (Throwable $tHp) {
      }
      if (!$healthProfile) {
        try {
          $hpStmt3 = $pdo->prepare("
            SELECT
              height_cm AS height,
              weight_kg AS weight,
              blood_pressure,
              temperature,
              last_checkup_date,
              blood_type,
              allergies,
              medical_conditions AS chronic_conditions,
              current_medications,
              remarks,
              NULL AS heart_rate,
              NULL AS smoking_status,
              NULL AS alcohol_consumption,
              NULL AS exercise_frequency,
              NULL AS diet_type,
              COALESCE(:resident_philhealth_number, :resident_philhealth_id, '') AS philhealth_number,
              :emergency_contact_name AS emergency_contact_name,
              :emergency_contact_relationship AS emergency_contact_relationship,
              :emergency_contact_phone AS emergency_contact_phone
            FROM health_records
            WHERE resident_id = :rid
            ORDER BY id DESC
            LIMIT 1
          ");
          $hpStmt3->execute([
            'rid' => $residentId,
            'resident_philhealth_number' => $resident['philhealth_number'] ?? '',
            'resident_philhealth_id' => $resident['philhealth_id'] ?? '',
            'emergency_contact_name' => $resident['emergency_contact_name'] ?? '',
            'emergency_contact_relationship' => $resident['emergency_contact_relationship'] ?? '',
            'emergency_contact_phone' => $resident['emergency_contact_phone'] ?? ($resident['emergency_contact_number'] ?? ''),
          ]);
          $healthProfile = $hpStmt3->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $tHp3) {
        }
      }
    }

    // Hydrate RHU Doctors, Nurses, and Healthcare Staff list with working schedule
    $rhuStaffList = [];
    $staffBookingsPerDate = [];
    try {
      $staffStatusWhere = residentColumnExists($pdo, 'health_workers', 'status')
        ? "WHERE LOWER(COALESCE(status, 'active')) NOT IN ('inactive', 'disabled', 'terminated', 'deleted')"
        : '';
      $staffStmt = $pdo->query("SELECT id AS staff_id,
                       COALESCE(NULLIF(position_title, ''), NULLIF(role, ''), 'RHU Staff') AS staff_type,
                       COALESCE(NULLIF(position_title, ''), NULLIF(role, ''), 'Healthcare Staff') AS specialization,
                       position_title,
                       role,
                       '' AS work_days,
                       NULL AS shift_start,
                       NULL AS shift_end,
                       NULL AS is_on_duty,
                       COALESCE(first_name, 'RHU Staff') AS first_name,
                       COALESCE(last_name, '') AS last_name,
                       email, contact_number AS phone_number
                FROM health_workers
                {$staffStatusWhere}
                ORDER BY id ASC");
      $rhuStaffList = $staffStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $hasBookingHealthWorker = residentColumnExists($pdo, 'consultations', 'health_worker_id');
      $hasBookingPhysician = residentColumnExists($pdo, 'consultations', 'physician_id');
      $bookingProviderColumn = ($hasBookingHealthWorker && $hasBookingPhysician)
        ? 'COALESCE(health_worker_id, physician_id)'
        : ($hasBookingHealthWorker ? 'health_worker_id' : ($hasBookingPhysician ? 'physician_id' : null));
      if ($bookingProviderColumn === null) {
        throw new RuntimeException('Consultation provider column is unavailable.');
      }
      $bookingStmt = $pdo->query(" 
                SELECT {$bookingProviderColumn} AS provider_id, consultation_date, COUNT(*) AS total_booked
                FROM consultations
                WHERE consultation_date >= CURDATE()
                GROUP BY {$bookingProviderColumn}, consultation_date
            ");
      while ($row = $bookingStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['provider_id'];
        $cdate = $row['consultation_date'];
        $staffBookingsPerDate[$pid][$cdate] = (int)$row['total_booked'];
      }
    } catch (Throwable $tSt) {
    }

    if (!empty($rhuStaffList) && function_exists('mergeJsonScheduleIntoStaffList')) {
      $rhuStaffList = mergeJsonScheduleIntoStaffList($rhuStaffList);
    }
    foreach ($rhuStaffList as &$staffForAppointmentRoles) {
      $staffForAppointmentRoles['appointment_roles'] = residentAppointmentRolesForProvider($staffForAppointmentRoles);
    }
    unset($staffForAppointmentRoles);

    // Hydrate Admin Posted Landing Page Content for residents.
    try {
      if (function_exists('ensurePortalTables')) {
        ensurePortalTables($pdo);
      }
      $residentAnnouncements = function_exists('getPortalAnnouncements') ? getPortalAnnouncements($pdo) : [];
      $postedEvents = function_exists('getPortalEvents') ? getPortalEvents($pdo) : [];
      $residentEventGallery = function_exists('getPortalEventGallery') ? getPortalEventGallery($pdo) : [];
      $portalSet = function_exists('portalSettings') ? portalSettings($pdo) : [];
      if (!empty($portalSet['rhu_municipal_officials'])) {
        $decodedOfficials = json_decode((string)$portalSet['rhu_municipal_officials'], true);
        $residentOfficials = is_array($decodedOfficials) ? $decodedOfficials : [];
      }
      if (empty($postedEvents) && function_exists('rhuTableExists') && rhuTableExists($pdo, 'events')) {
        $evStmt = $pdo->query("
                  SELECT id, title, DATE(start_datetime) AS event_date, DATE(start_datetime) AS scheduled_date, TIME(start_datetime) AS start_time,
                  location AS venue, description, '' AS image_url, 'emerald' AS badge_color, NULL AS capacity, status, 1 AS is_active, created_at
                  FROM events
                  ORDER BY start_datetime DESC, id DESC
                  LIMIT 20
              ");
        $postedEvents = $evStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }
    } catch (Throwable $tEv) {
      error_log('Resident landing content hydration failed: ' . $tEv->getMessage());
    }

    if (empty($postedEvents)) {
      $postedEvents = [
        [
          'id' => 101,
          'title' => 'National Immunization & Child Growth Monitoring Day',
          'event_date' => '2026-08-20',
          'start_time' => '08:00:00',
          'venue' => 'Nasugbu RHU Main Center - OPD Hall',
          'description' => 'Free routine vaccine immunizations for infants & children (0-5 years old) plus OPT+ nutrition & height/weight screening. Please present your Child Health Record book.',
          'badge_color' => 'emerald',
          'status' => 'Upcoming',
          'image_url' => ''
        ],
        [
          'id' => 102,
          'title' => 'Community Cardiovascular & Diabetes Screening Fair',
          'event_date' => '2026-08-25',
          'start_time' => '07:30:00',
          'venue' => 'Halang Barangay Health Station',
          'description' => 'Complimentary 12-Lead ECG, fasting blood sugar testing, lipid profile, and blood pressure monitoring conducted by RHU doctors & nurses.',
          'badge_color' => 'rose',
          'status' => 'Upcoming',
          'image_url' => ''
        ],
        [
          'id' => 103,
          'title' => 'Municipal Blood Donation Drive & Wellness Seminar',
          'event_date' => '2026-09-02',
          'start_time' => '09:00:00',
          'venue' => 'Bahay Pamahalaan Function Hall',
          'description' => 'Organized in coordination with the Philippine Red Cross. All healthy residents aged 18-60 are encouraged to donate blood and save lives!',
          'badge_color' => 'amber',
          'status' => 'Upcoming',
          'image_url' => ''
        ],
        [
          'id' => 104,
          'title' => 'Maternal Care & Family Planning Consultation Day',
          'event_date' => '2026-09-10',
          'start_time' => '08:30:00',
          'venue' => 'RHU Maternal & Women Wellness Clinic',
          'description' => 'Free prenatal examinations, iron-folic acid distribution, and individual family planning counseling with licensed RHU midwives.',
          'badge_color' => 'sky',
          'status' => 'Upcoming',
          'image_url' => ''
        ]
      ];
    }
  } catch (Exception $ex) {
    error_log("ResidentDashboard DB Hydration Error: " . $ex->getMessage());
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? '') === 'add_dependent') {
      $dependentErrors[] = 'The dependent could not be saved: ' . $ex->getMessage();
    }
  }
}

if (empty($postedEvents)) {
  $postedEvents = [
    [
      'id' => 101,
      'title' => 'National Immunization & Child Growth Monitoring Day',
      'event_date' => '2026-08-20',
      'start_time' => '08:00:00',
      'venue' => 'Nasugbu RHU Main Center - OPD Hall',
      'description' => 'Free routine vaccine immunizations for infants & children (0-5 years old) plus OPT+ nutrition & height/weight screening.',
      'badge_color' => 'emerald',
      'status' => 'Upcoming',
      'image_url' => ''
    ],
    [
      'id' => 102,
      'title' => 'Community Cardiovascular & Diabetes Screening Fair',
      'event_date' => '2026-08-25',
      'start_time' => '07:30:00',
      'venue' => 'Halang Barangay Health Station',
      'description' => 'Complimentary 12-Lead ECG, fasting blood sugar testing, lipid profile, and blood pressure monitoring.',
      'badge_color' => 'rose',
      'status' => 'Upcoming',
      'image_url' => ''
    ]
  ];
}

$postedEvents = (static function (array $events): array {
  $seen = [];
  $unique = [];
  foreach ($events as $event) {
    $key = strtolower(trim((string)($event['title'] ?? '')))
      . '|' . strtolower(trim((string)($event['event_date'] ?? $event['scheduled_date'] ?? '')))
      . '|' . strtolower(trim((string)($event['venue'] ?? '')));
    if ($key === '||') {
      $key = 'id|' . (string)($event['id'] ?? count($unique));
    }
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $unique[] = $event;
  }
  return $unique;
})($postedEvents);

if (empty($healthCertificates)) {
  $healthCertificates = $certificates ?? [];
}

if (!$resident && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? '') === 'add_dependent') {
  $dependentErrors[] = 'Your account is not linked to a resident record, so the dependent was not saved. Please contact RHU staff to verify your resident profile.';
}

if (!$resident && !empty($_SESSION['resident_registration'])) $resident = $_SESSION['resident_registration'];

if (!empty($_GET['certificate_document']) && !empty($pdo) && !empty($resident['id'])) {
  $certificateId = (int)$_GET['certificate_document'];
  $certificateStmt = $pdo->prepare(
    "SELECT c.*, c.certificate_type AS certificate_type_name, c.status AS validity_status,
                CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) AS resident_name,
                r.address, COALESCE(b.name, '') AS barangay,
                CONCAT_WS(' ', hw.first_name, hw.last_name) AS issuer_name,
                COALESCE(hw.position_title, 'Authorized RHU Officer') AS issuer_position
        FROM certificates c
        JOIN residents r ON r.id = c.resident_id
        LEFT JOIN barangays b ON b.id = r.barangay_id
        LEFT JOIN health_workers hw ON hw.id = c.issued_by_id
        WHERE c.id = :certificate_id AND c.resident_id = :resident_id
        LIMIT 1"
  );
  $certificateStmt->execute(['certificate_id' => $certificateId, 'resident_id' => (int)$resident['id']]);
  $certificateDocument = $certificateStmt->fetch(PDO::FETCH_ASSOC);
  $documentStatus = strtolower((string)($certificateDocument['validity_status'] ?? ''));
  $canGenerateCertificate = $certificateDocument
    && (str_contains($documentStatus, 'valid') || str_contains($documentStatus, 'approved') || str_contains($documentStatus, 'issued'))
    && !str_contains($documentStatus, 'invalid')
    && !str_contains($documentStatus, 'revoked');
  if (!$canGenerateCertificate) {
    http_response_code(403);
    exit('This certificate is not available for generation.');
  }
  $certificateNumber = $certificateDocument['certificate_number'] ?: ('HC-' . str_pad((string)$certificateDocument['id'], 8, '0', STR_PAD_LEFT));
  // Rebuild on every resident view so approved signatures and asset paths stay current.
  $certificateHtml = portalGenerateCertificateHtml($pdo, (int)$certificateDocument['id']);
?>
  <!doctype html>
  <html lang="en">

  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= esc($certificateDocument['certificate_type_name']) ?> - <?= esc($certificateNumber) ?></title>
    <style>
    #edit-personal-profile-modal:not(.hidden),
    #edit-health-profile-modal:not(.hidden) {
      display: flex;
    }

      * {
        box-sizing: border-box
      }

      body {
        margin: 0;
        background: #e5e7eb;
        color: #111;
        font-family: Arial, sans-serif;
        overflow-x: hidden
      }

      .toolbar {
        position: sticky;
        top: 0;
        z-index: 5;
        display: flex;
        justify-content: center;
        gap: 10px;
        padding: 14px;
        background: #0f766e
      }

      .toolbar button,
      .toolbar a {
        border: 1px solid rgba(255, 255, 255, .5);
        border-radius: 8px;
        background: #fff;
        padding: 9px 16px;
        color: #0f766e;
        font: 700 13px Arial;
        text-decoration: none;
        cursor: pointer
      }

      .official-certificate-template {
        position: relative;
        overflow: hidden;
        margin: 24px auto;
        width: min(100%, 760px);
        min-height: 1040px;
        background: #fff;
        padding: 58px 68px 44px;
        color: #050505;
        font-family: Arial, Helvetica, sans-serif;
        line-height: 1.45;
        box-shadow: 0 18px 50px rgba(15, 23, 42, .18)
      }

      .certificate-scroll-wrap {
        width: 100%;
        overflow-x: auto;
        padding: 0 12px 24px
      }

      .official-certificate-template .cert-header {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 112px 1fr 112px;
        align-items: center;
        text-align: center;
        margin-bottom: 10px
      }

      .official-certificate-template .cert-seal {
        width: 96px;
        height: 96px;
        object-fit: contain;
        justify-self: center
      }

      .official-certificate-template .cert-watermark {
        position: absolute;
        z-index: 0;
        left: 50%;
        top: 285px;
        width: 560px;
        height: 560px;
        transform: translateX(-50%);
        object-fit: contain;
        opacity: .1;
        pointer-events: none
      }

      .official-certificate-template .cert-header-copy {
        font-size: 11px;
        line-height: 1.2
      }

      .official-certificate-template .cert-header-copy p {
        margin: 0
      }

      .official-certificate-template .cert-republic {
        font-family: Georgia, "Times New Roman", serif;
        font-style: italic;
        font-size: 13px
      }

      .official-certificate-template .cert-rule {
        position: relative;
        z-index: 1;
        border-top: 2px solid #111;
        border-bottom: 1px solid #111;
        height: 4px;
        margin: 6px 0 42px
      }

      .official-certificate-template h1,
      .official-certificate-template h2,
      .official-certificate-template h3 {
        position: relative;
        z-index: 1;
        margin: 5px 0;
        text-align: center;
        font-weight: 900;
        text-transform: uppercase
      }

      .official-certificate-template h1 {
        font-size: 18px;
        letter-spacing: 0
      }

      .official-certificate-template h2 {
        font-size: 21px;
        font-style: italic
      }

      .official-certificate-template h3 {
        font-size: 28px;
        letter-spacing: 0;
        margin-bottom: 2px
      }

      .official-certificate-template .cert-no {
        position: relative;
        z-index: 1;
        text-align: center;
        font-family: "Courier New", monospace;
        font-size: 10px;
        font-weight: 700;
        color: #334155;
        margin: 0 0 44px
      }

      .official-certificate-template .cert-body {
        position: relative;
        z-index: 1;
        margin: 0;
        font-size: 12px;
        line-height: 1.65;
        text-align: justify
      }

      .official-certificate-template .cert-body p {
        margin: 0 0 18px;
        text-indent: 34px
      }

      .official-certificate-template .cert-body .cert-greeting {
        text-indent: 0;
        margin-bottom: 26px;
        text-align: left
      }

      .official-certificate-template .cert-dates {
        display: flex;
        gap: 34px;
        margin-top: 6px;
        font-size: 10px;
        text-align: left
      }

      .official-certificate-template .cert-signatures {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 52px;
        margin-top: 118px;
        text-align: center
      }

      .official-certificate-template .cert-signatures>div {
        min-height: 104px;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        align-items: center;
        font-size: 12px
      }

      .official-certificate-template .cert-signatures strong {
        display: block;
        min-width: 250px;
        padding-top: 5px;
        font-weight: 900;
        text-transform: uppercase
      }

      .official-certificate-template .signature-wrap {
        position: relative;
        width: 170px;
        height: 58px;
        margin: 0 auto
      }

      .official-certificate-template .certificate-signature-image {
        position: absolute;
        left: 50%;
        bottom: 6px;
        transform: translateX(-50%);
        display: block;
        width: 170px;
        height: 52px;
        object-fit: contain;
        object-position: center bottom;
        mix-blend-mode: multiply;
        z-index: 2
      }

      .official-certificate-template .signature-line {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 1px;
        width: 170px;
        margin: 0;
        border-top: 1px solid #111;
        background: transparent
      }

      .official-certificate-template small,
      .official-certificate-template .cert-footer {
        display: block;
        color: #111;
        font-size: 10px
      }

      .official-certificate-template .cert-footer {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        gap: 18px;
        margin-top: 34px;
        border-top: 1px solid #64748b;
        padding-top: 6px;
        font-family: "Courier New", monospace;
        color: #0f172a
      }

      @media(max-width:820px) {
        .toolbar {
          padding: 12px;
          position: sticky
        }

        .toolbar button,
        .toolbar a {
          border-radius: 14px;
          padding: 12px 16px;
          font-size: 13px
        }

        .certificate-scroll-wrap {
          padding: 0;
          overflow-x: hidden
        }

        .official-certificate-template {
          width: 100%;
          min-height: auto;
          margin: 0;
          padding: 34px 20px 28px;
          box-shadow: none
        }

        .official-certificate-template .cert-header {
          grid-template-columns: 72px 1fr 72px;
          gap: 8px
        }

        .official-certificate-template .cert-seal {
          width: 64px;
          height: 64px
        }

        .official-certificate-template .cert-watermark {
          top: 230px;
          width: 92vw;
          height: 92vw
        }

        .official-certificate-template .cert-header-copy {
          font-size: 10px
        }

        .official-certificate-template h1 {
          font-size: 17px
        }

        .official-certificate-template h2 {
          font-size: 18px
        }

        .official-certificate-template h3 {
          font-size: clamp(24px, 9vw, 38px);
          line-height: 1.35
        }

        .official-certificate-template .cert-no {
          overflow-wrap: anywhere;
          margin-bottom: 34px
        }

        .official-certificate-template .cert-body {
          font-size: 15px;
          line-height: 1.75;
          text-align: left
        }

        .official-certificate-template .cert-body p {
          text-indent: 0;
          margin-bottom: 24px
        }

        .official-certificate-template .cert-dates {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 18px;
          font-size: 13px
        }

        .official-certificate-template .cert-signatures {
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 20px;
          margin-top: 86px
        }

        .official-certificate-template .cert-signatures strong {
          min-width: 0;
          width: 100%;
          font-size: 13px;
          line-height: 1.2
        }

        .official-certificate-template .certificate-signature-image {
          width: min(34vw, 150px);
          height: 54px
        }

        .official-certificate-template .cert-footer {
          display: grid;
          grid-template-columns: 1fr;
          text-align: center;
          margin-top: 24px
        }
      }

      @media print {
        @page {
          size: A4 portrait;
          margin: 0
        }

        body {
          background: #fff
        }

        .toolbar {
          display: none
        }

        .official-certificate-template {
          width: 210mm;
          min-height: 297mm;
          margin: 0;
          box-shadow: none
        }
      }
    </style>
  </head>

  <body>
    <div class="toolbar"><button onclick="window.print()">Download / Save as PDF</button><a href="ResidentDashboard.php?tab=certificates">Back to certificates</a></div>
    <div class="certificate-scroll-wrap">
      <?= $certificateHtml ?>
    </div>
  </body>

  </html>
<?php
  exit;
}

$lastConsultation = $consultations[0] ?? null;
$currentYear = (int)date('Y');
$visitsThisYear = count(array_filter($consultations, fn($consultation) => !empty($consultation['consultation_date']) && (int)date('Y', strtotime($consultation['consultation_date'])) === $currentYear));
$totalPrescriptions = array_sum(array_map(fn($consultation) => empty($consultation['medications_prescribed']) ? 0 : count(array_filter(preg_split('/,\s*/', $consultation['medications_prescribed']))), $consultations));
$initials = strtoupper(substr($resident['first_name'] ?? 'R', 0, 1) . substr($resident['last_name'] ?? 'S', 0, 1));
$loggedInName = trim((string)($user['name'] ?? ''));
if ($loggedInName === '') {
  $loggedInName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
}
if ($loggedInName === '') {
  $loggedInName = trim((string)(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')));
}
if ($loggedInName === '') {
  $loggedInName = 'Resident User';
}
$age = residentAge($resident['date_of_birth'] ?? null);
$defaultHeroImage = '../../../assets/admin-municipal-background.png';
$firstPostedImage = !empty($postedEvents[0]['image_url']) ? resolveImageUrl((string)$postedEvents[0]['image_url']) : '';
$initialHeroImage = $firstPostedImage !== '' ? $firstPostedImage : $defaultHeroImage;

$tabs = [
  'home' => ['Overview', 'home'],
  'profile' => ['My Profile', 'user'],
  'records' => ['Health Records', 'file-text'],
  'immunization' => ['Immunization', 'shield-check'],
  'certificates' => ['Certificates', 'award'],
  'family' => ['Family Members', 'users'],
  'events' => ['Events & Programs', 'calendar'],
  'map' => ['Nearby Map', 'map-pinned'],
  'contact' => ['Contact RHU', 'phone-call'],
  'emergency' => ['Emergency & Referral', 'siren'],
];

$events = [
  ['Jun 20', 'Blood Drive - City Hall', 'Free blood pressure check for all donors'],
  ['Jun 24', 'Free Cervical Cancer Screening', 'Halang Barangay Hall, 8AM-12NN'],
  ['Jun 28', 'Senior Citizens Health Fair', 'Free ECG, blood glucose, BP monitoring'],
  ['Jul 1-31', 'Nutrition Month (OPT+)', 'Free growth monitoring for 0-5 years'],
  ['Jul 10', 'Family Planning Counseling Day', 'RHU Main, free FP consultation'],
  ['Jul 15', 'TB Awareness Seminar', 'Barangay Halang, 9AM'],
  ['Aug 1', 'National Immunization Day', 'Free vaccines for children 0-5'],
];
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="manifest.php?v=20261003-icon-2">
  <meta name="theme-color" content="#0f766e">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/resihunity-icon-192-v2.png">
  <link rel="icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png" sizes="192x192">
  <link rel="shortcut icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png">
  <script src="assets/js/pwa-install.js?v=20261003" defer></script>
  <title>ResiHUnity</title>
  <script src="assets/js/resident-live.js?v=20261027-qr" defer></script>
  <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');

    html {
      scroll-behavior: smooth;
    }

    body.resident-dashboard {
      font-family: 'Plus Jakarta Sans', sans-serif;
      min-height: 100vh;
      background: transparent !important;
      position: relative;
      overflow-x: hidden;
    }

    #scroll-progress {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 9999;
      width: 0;
      height: 3px;
      pointer-events: none;
      background: #10b981;
    }

    body.resident-dashboard > .resident-main-shell > header.sticky {
      position: sticky !important;
      top: 0 !important;
      z-index: 9000 !important;
      isolation: isolate;
    }

    #edit-personal-profile-modal,
    #edit-health-profile-modal {
      z-index: 100001;
      position: fixed;
      inset: 0;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }

    #edit-personal-profile-modal:not(.hidden),
    #edit-health-profile-modal:not(.hidden) {
      display: flex !important;
    }

    #edit-personal-profile-modal > div,
    #edit-health-profile-modal > div {
      width: 100%;
      max-width: 44rem;
      margin: auto;
      max-height: calc(100dvh - 2rem);
      overflow-y: auto;
      overscroll-behavior: contain;
    }

    @media (min-width: 768px) {
      body.resident-dashboard {
        flex-direction: row !important;
      }

      body.resident-dashboard #sidebar {
        order: 0;
        left: 0;
        align-self: flex-start;
      }

      body.resident-dashboard .resident-main-shell {
        order: 1;
        width: calc(100% - 16rem);
        max-width: calc(100% - 16rem);
      }

      body.resident-dashboard.sidebar-is-collapsed .resident-main-shell {
        width: calc(100% - 4.5rem);
        max-width: calc(100% - 4.5rem);
      }
    }

    body.resident-dashboard #sidebar {
      height: 100vh;
      height: 100dvh;
      overflow: hidden;
    }

    body.resident-dashboard #sidebar > div:first-child {
      display: flex;
      flex: 1;
      flex-direction: column;
      min-height: 0;
      overflow: hidden;
    }

    body.resident-dashboard #sidebar .sidebar-brand-header {
      flex-shrink: 0;
    }

    body.resident-dashboard #sidebar nav {
      flex: 1;
      min-height: 0;
      max-height: none;
      overflow-y: auto;
      overscroll-behavior: contain;
    }

    body.resident-dashboard #sidebar > div:last-child {
      flex-shrink: 0;
      padding-bottom: max(.75rem, env(safe-area-inset-bottom));
    }

    @media (max-width: 767px) {
      body.resident-dashboard #sidebar {
        width: min(20rem, calc(100vw - 3rem)) !important;
        z-index: 9020;
      }

      body.resident-dashboard #sidebar-overlay {
        z-index: 9010;
      }

      body.resident-dashboard #sidebar .sidebar-text,
      body.resident-dashboard #sidebar .sidebar-header-text {
        display: block;
      }

      body.resident-dashboard #sidebar .sidebar-link {
        min-height: 44px;
      }

      body.resident-dashboard .resident-main-shell {
        width: 100%;
        max-width: 100%;
      }
    }

    /* ====================================================
       UNIVERSAL LIGHT GLASS MODE OVERRIDES
       ==================================================== */
    html:not(.dark) body,
    html:not(.dark) .resident-dashboard,
    body:not(.dark) {
      color: #0f172a !important;
    }

    html:not(.dark) #rhu-bg-overlay {
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.86) 0%, rgba(241, 245, 249, 0.78) 50%, rgba(236, 253, 245, 0.84) 100%) !important;
      backdrop-filter: blur(4px);
    }

    html:not(.dark) #rhu-bg-img {
      filter: brightness(0.98) contrast(1.02);
    }

    /* Light Mode Header & Sidebar */
    html:not(.dark) header.sticky {
      background: rgba(255, 255, 255, 0.88) !important;
      border-bottom: 1px solid rgba(226, 232, 240, 0.9) !important;
      backdrop-filter: blur(20px) saturate(180%) !important;
      box-shadow: 0 4px 25px rgba(15, 23, 42, 0.05) !important;
    }

    html:not(.dark) header h1 {
      color: #0f172a !important;
    }

    html:not(.dark) #sidebar {
      background: rgba(255, 255, 255, 0.92) !important;
      border-right: 1px solid rgba(226, 232, 240, 0.9) !important;
      box-shadow: 8px 0 30px rgba(15, 23, 42, 0.06) !important;
    }

    html:not(.dark) #sidebar .sidebar-link {
      color: #475569 !important;
    }

    html:not(.dark) #sidebar .sidebar-link i {
      color: #64748b !important;
    }

    html:not(.dark) #sidebar .sidebar-link:hover {
      background: rgba(16, 185, 129, 0.12) !important;
      color: #047857 !important;
    }

    html:not(.dark) .nav-active {
      background-color: #059669 !important;
      color: #ffffff !important;
      font-weight: 700 !important;
      border: 1px solid #047857 !important;
      box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35) !important;
    }

    /* Light Mode ALL Cards & Containers (excluding Hero Carousel) */
    html:not(.dark) main .bg-\[\#131916\],
    html:not(.dark) main .bg-slate-900,
    html:not(.dark) main .bg-slate-800,
    html:not(.dark) main .bg-slate-950,
    html:not(.dark) main [data-tab-panel] article:not(#hero-events-container),
    html:not(.dark) main [data-tab-panel] .rounded-3xl:not(#hero-events-container),
    html:not(.dark) main [data-tab-panel] .rounded-2xl:not(#hero-events-container) {
      background-color: rgba(255, 255, 255, 0.88) !important;
      backdrop-filter: blur(16px) saturate(180%) !important;
      border-color: rgba(226, 232, 240, 0.9) !important;
      color: #0f172a !important;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06) !important;
    }

    /* Preserve Dark Hero Spotlight Banner styling in Light Mode */
    html:not(.dark) #hero-events-container {
      background-color: #0f1714 !important;
      border-color: rgba(255, 255, 255, 0.15) !important;
      color: #ffffff !important;
    }

    html:not(.dark) #hero-events-container .text-slate-300,
    html:not(.dark) #hero-events-container .text-slate-400,
    html:not(.dark) #hero-events-container .text-slate-500,
    html:not(.dark) #hero-events-container .text-white {
      color: #ffffff !important;
      text-shadow: 0 2px 10px rgba(0, 0, 0, .65);
    }

    html:not(.dark) #hero-events-container .text-emerald-300 {
      color: #6ee7b7 !important;
      text-shadow: 0 2px 10px rgba(0, 0, 0, .55);
    }

    .resident-side-card-list {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1rem;
    }

    .resident-side-card {
      display: grid;
      grid-template-columns: minmax(220px, 38%) minmax(0, 1fr);
      min-height: 0;
    }

    .resident-side-card-media {
      height: 290px;
      min-height: 0;
      overflow: hidden;
      background: linear-gradient(135deg, rgba(16, 185, 129, .14), rgba(14, 165, 233, .12));
    }

    .resident-side-card-media img,
    .resident-side-card-fallback {
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center center;
    }

    .resident-side-card-body {
      display: flex;
      min-width: 0;
      flex-direction: column;
      justify-content: space-between;
      gap: 1rem;
      padding: 1.25rem;
    }

    .resident-side-card-copy {
      display: -webkit-box;
      -webkit-line-clamp: 4;
      line-clamp: 4;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .resident-image-card-list {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1.25rem;
    }

    .resident-image-card {
      overflow: hidden;
      border-radius: 1.5rem;
    }

    .resident-image-card-button {
      position: relative;
      display: block;
      width: 100%;
      height: 300px;
      overflow: hidden;
      text-align: left;
      background: linear-gradient(135deg, rgba(16, 185, 129, .14), rgba(14, 165, 233, .12));
    }

    .resident-image-card-button img,
    .resident-image-card-fallback {
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center center;
      transition: transform .35s ease;
    }

    .resident-image-card-button:hover img {
      transform: scale(1.04);
    }

    .resident-image-card-overlay {
      position: absolute;
      inset: auto 0 0;
      padding: 1rem;
      background: linear-gradient(to top, rgba(2, 6, 23, .82), rgba(2, 6, 23, 0));
      color: #fff;
    }

    .resident-image-card-overlay h4 {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .resident-detail-modal-body {
      width: min(96vw, 960px);
      max-height: min(86vh, 860px);
    }

    .resident-detail-modal-content {
      grid-template-columns: minmax(0, 48%) minmax(0, 52%);
      max-height: calc(min(86vh, 860px) - 132px);
    }

    .resident-detail-modal-image {
      height: 100%;
      min-height: 620px;
    }

    @media (max-width: 767px) {
      .resident-detail-modal-content {
        grid-template-columns: 1fr;
        max-height: calc(86vh - 112px);
      }

      .resident-detail-modal-image {
        min-height: 260px;
        max-height: 320px;
      }
    }

    @media (max-width: 767px) {
      .resident-side-card {
        grid-template-columns: 1fr;
      }

      .resident-side-card-media {
        height: 190px;
        min-height: 0;
      }

      .resident-side-card-body {
        padding: 1rem;
      }

      .resident-image-card-list {
        grid-template-columns: 1fr;
        gap: 1rem;
      }

      .resident-image-card-button {
        height: 230px;
      }
    }

    /* Light Mode Text Overrides */
    html:not(.dark) main .text-white:not(#hero-events-container *):not(button.bg-emerald-600 *):not(.bg-emerald-600 *):not(.nav-active *),
    html:not(.dark) main .text-slate-200,
    html:not(.dark) main .text-slate-100 {
      color: #0f172a !important;
    }

    html:not(.dark) main .text-slate-300 {
      color: #334155 !important;
    }

    html:not(.dark) main .text-slate-400 {
      color: #64748b !important;
    }

    /* ====================================================
       UNIVERSAL DARK GLASS MODE OVERRIDES
       ==================================================== */
    html.dark body,
    html.dark .resident-dashboard,
    body.dark {
      color: #f8fafc !important;
    }

    html.dark #rhu-bg-overlay {
      background: linear-gradient(135deg, rgba(6, 11, 8, 0.94) 0%, rgba(8, 14, 10, 0.90) 50%, rgba(8, 18, 11, 0.94) 100%) !important;
      backdrop-filter: blur(4px);
    }

    html.dark #rhu-bg-img {
      filter: brightness(0.35) contrast(1.1);
    }

    /* Dark Mode Header & Sidebar */
    html.dark header.sticky {
      background: rgba(14, 19, 16, 0.92) !important;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.5) !important;
    }

    html.dark header h1 {
      color: #ffffff !important;
    }

    html.dark #sidebar {
      background: rgba(12, 17, 14, 0.94) !important;
      border-right: 1px solid rgba(255, 255, 255, 0.1) !important;
      box-shadow: 12px 0 35px rgba(0, 0, 0, 0.6) !important;
    }

    html.dark #sidebar .sidebar-link {
      color: #94a3b8 !important;
    }

    html.dark #sidebar .sidebar-link i {
      color: #64748b !important;
    }

    html.dark #sidebar .sidebar-link:hover {
      background: rgba(255, 255, 255, 0.07) !important;
      color: #ffffff !important;
    }

    html.dark .nav-active {
      background-color: #062e21 !important;
      color: #34d399 !important;
      font-weight: 700 !important;
      border: 1px solid rgba(16, 185, 129, 0.4) !important;
      box-shadow: 0 0 20px rgba(16, 185, 129, 0.2) !important;
    }

    /* Dark Mode ALL Cards & Containers */
    html.dark main .bg-white,
    html.dark main .bg-slate-50,
    html.dark main .bg-slate-100,
    html.dark main .bg-\[\#131916\],
    html.dark main [data-tab-panel] article,
    html.dark main [data-tab-panel] .rounded-3xl,
    html.dark main [data-tab-panel] .rounded-2xl {
      background-color: rgba(19, 25, 22, 0.88) !important;
      backdrop-filter: blur(16px) saturate(180%) !important;
      border-color: rgba(255, 255, 255, 0.1) !important;
      color: #f8fafc !important;
      box-shadow: 0 12px 35px rgba(0, 0, 0, 0.55) !important;
    }

    /* Dark Mode Text Overrides */
    html.dark main .text-slate-900,
    html.dark main .text-slate-800,
    html.dark main .text-slate-700 {
      color: #f8fafc !important;
    }

    html.dark main .text-slate-600,
    html.dark main .text-slate-500 {
      color: #cbd5e1 !important;
    }

    html.dark main .text-slate-400 {
      color: #94a3b8 !important;
    }

    html.dark main .border-white\/10,
    html.dark main .border-white\/5 {
      border-color: rgba(255, 255, 255, 0.1) !important;
    }

    html.dark input,
    html.dark select,
    html.dark textarea {
      background-color: rgba(0, 0, 0, 0.45) !important;
      border: 1px solid rgba(255, 255, 255, 0.15) !important;
      color: #f8fafc !important;
    }

    /* Common Components & Animations */
    .sidebar-collapsed {
      width: 4.5rem !important;
    }

    .sidebar-link {
      border-radius: .875rem;
    }

    .sidebar-brand-logo {
      width: 4.45rem;
      height: 4.45rem;
      border-radius: 1.35rem;
      background: #ffffff;
      padding: .38rem;
      box-shadow: 0 12px 28px rgba(15, 23, 42, .12);
      border: 1px solid rgba(16, 185, 129, .22);
    }

    .sidebar-brand-logo img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: contain;
      border-radius: 9999px;
    }

    .sidebar-brand {
      min-width: 0;
      width: 100%;
      align-items: center;
      justify-content: center;
      text-align: center;
    }

    .sidebar-brand-header {
      position: relative;
      min-height: 8.4rem;
      align-items: center;
      justify-content: center;
      padding: .9rem 3rem .85rem 1rem;
    }

    .sidebar-header-text {
      line-height: 1.1;
    }

    .sidebar-header-title {
      color: #0f172a !important;
      text-shadow: none !important;
    }

    .sidebar-header-subtitle {
      color: #059669 !important;
      text-shadow: none !important;
    }

    .top-profile-brand-logo {
      height: 3.45rem;
      width: auto;
      max-width: 9.5rem;
      object-fit: contain;
    }

    .top-profile-logo-avatar {
      height: 3.75rem;
      width: 3.75rem;
      object-fit: contain;
      border-radius: 9999px;
      background: #ffffff;
      padding: .25rem;
      box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
      border: 1px solid rgba(16, 185, 129, .28);
    }

    .top-profile-name {
      color: #0f172a !important;
      text-shadow: none !important;
    }

    html.dark .top-profile-name {
      color: #ffffff !important;
    }

    #notification-bell-btn {
      background: #ffffff !important;
      border-color: rgba(5, 150, 105, .35) !important;
      color: #0f766e !important;
      box-shadow: 0 10px 22px rgba(15, 23, 42, .10) !important;
    }

    #notification-bell-btn svg {
      width: 1.15rem !important;
      height: 1.15rem !important;
      stroke-width: 2.35 !important;
    }

    #notification-bell-btn:hover {
      background: #ecfdf5 !important;
      border-color: rgba(5, 150, 105, .7) !important;
      color: #047857 !important;
    }

    html.dark #notification-bell-btn {
      background: rgba(15, 23, 42, .95) !important;
      border-color: rgba(52, 211, 153, .35) !important;
      color: #34d399 !important;
    }

    html.dark .sidebar-header-title {
      color: #ffffff !important;
    }

    html.dark .sidebar-header-subtitle {
      color: #34d399 !important;
    }

    .sidebar-collapsed .sidebar-text,
    .sidebar-collapsed .sidebar-header-text {
      display: none !important;
    }

    .sidebar-collapsed .sidebar-header-text {
      width: 0 !important;
    }

    .sidebar-collapsed>div:first-child>.flex {
      position: relative !important;
      justify-content: center !important;
      min-height: 5.75rem !important;
      padding: .75rem !important;
    }

    .sidebar-collapsed .sidebar-brand {
      justify-content: center !important;
      overflow: visible !important;
      width: 100% !important;
    }

    .sidebar-collapsed .sidebar-brand-logo {
      width: 3.55rem !important;
      height: 3.55rem !important;
      border-radius: 1.1rem !important;
      padding: .25rem !important;
    }

    .sidebar-collapsed #sidebar-collapse-btn {
      position: absolute !important;
      right: .25rem !important;
      top: .55rem !important;
      width: 1.45rem !important;
      height: 1.45rem !important;
      border-radius: .45rem !important;
      background: rgba(255, 255, 255, .92) !important;
      border: 1px solid rgba(148, 163, 184, .35) !important;
      color: #64748b !important;
      box-shadow: 0 6px 14px rgba(15, 23, 42, .08) !important;
    }

    .sidebar-collapsed #sidebar-collapse-btn svg {
      width: .85rem !important;
      height: .85rem !important;
    }

    html.dark .sidebar-collapsed #sidebar-collapse-btn {
      background: rgba(15, 23, 42, .92) !important;
      border-color: rgba(255, 255, 255, .14) !important;
      color: #cbd5e1 !important;
    }

    .sidebar-collapsed nav {
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      gap: .55rem !important;
      padding-left: .75rem !important;
      padding-right: .75rem !important;
    }

    .sidebar-collapsed .sidebar-link {
      justify-content: center !important;
      width: 2.75rem !important;
      height: 2.75rem !important;
      padding-left: 0 !important;
      padding-right: 0 !important;
      padding-top: 0 !important;
      padding-bottom: 0 !important;
      border-radius: 1rem !important;
    }

    .sidebar-collapsed .sidebar-link i,
    .sidebar-collapsed .sidebar-link svg {
      width: 1.1rem !important;
      height: 1.1rem !important;
      margin: 0 !important;
    }

    .sidebar-collapsed .nav-active {
      background: linear-gradient(135deg, #059669 0%, #0ea5e9 100%) !important;
      color: #ffffff !important;
      border-color: rgba(255, 255, 255, .55) !important;
      border-radius: 1rem !important;
      box-shadow: 0 12px 24px rgba(5, 150, 105, .28) !important;
    }

    .sidebar-collapsed .nav-active i,
    .sidebar-collapsed .nav-active svg {
      color: #ffffff !important;
      stroke: currentColor !important;
    }

    .sidebar-collapsed>div:last-child {
      display: flex !important;
      justify-content: center !important;
      padding: .75rem !important;
    }

    #resident-location-map {
      min-height: 31rem;
      background: #111613;
    }

    .map-user-marker {
      position: relative;
      display: flex;
      height: 2.75rem;
      width: 2.75rem;
      align-items: center;
      justify-content: center;
      border: 3px solid #ffffff;
      border-radius: 9999px;
      background: #0ea5e9;
      color: #ffffff;
      box-shadow: 0 10px 28px rgba(14, 165, 233, .38);
    }

    .map-user-marker::before {
      content: "";
      position: absolute;
      inset: -.65rem;
      border-radius: inherit;
      background: rgba(14, 165, 233, .22);
      animation: map-user-pulse 1.8s ease-out infinite;
    }

    .map-user-marker::after {
      content: "";
      position: relative;
      z-index: 1;
      width: 0;
      height: 0;
      border-left: .42rem solid transparent;
      border-right: .42rem solid transparent;
      border-bottom: 1rem solid #ffffff;
      transform: translateY(-1px);
    }

    @keyframes map-user-pulse {
      0% {
        transform: scale(.75);
        opacity: .8;
      }

      100% {
        transform: scale(1.65);
        opacity: 0;
      }
    }

    [data-tab-panel] {
      animation: panel-enter 300ms cubic-bezier(.2, .8, .2, 1);
    }

    [data-tab-panel]>.rounded-2xl,
    [data-tab-panel] article,
    [data-tab-panel] .dashboard-surface {
      transition: transform 220ms cubic-bezier(.2, .8, .2, 1), box-shadow 220ms ease, border-color 220ms ease;
    }

    [data-tab-panel]>.rounded-2xl:hover,
    [data-tab-panel] article:hover,
    [data-tab-panel] .dashboard-surface:hover {
      transform: translateY(-3px) scale(1.008);
      position: relative;
      z-index: 2;
    }

    /* Smooth color & surface background transitions for all UI elements */
    body,
    header,
    #sidebar,
    main,
    main *,
    article,
    .dashboard-surface,
    input,
    select,
    textarea,
    table,
    th,
    td {
      transition: background-color 500ms cubic-bezier(0.4, 0, 0.2, 1),
        border-color 500ms cubic-bezier(0.4, 0, 0.2, 1),
        color 350ms cubic-bezier(0.4, 0, 0.2, 1),
        box-shadow 500ms cubic-bezier(0.4, 0, 0.2, 1),
        backdrop-filter 500ms cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    #rhu-bg-overlay,
    #rhu-bg-img {
      transition: background 600ms cubic-bezier(0.4, 0, 0.2, 1),
        filter 600ms cubic-bezier(0.4, 0, 0.2, 1),
        opacity 600ms ease !important;
    }

    /* Toggle Button Spin & Pop Keyframe Animation */
    @keyframes theme-toggle-spin {
      0% {
        transform: scale(1) rotate(0deg);
      }

      50% {
        transform: scale(1.4) rotate(180deg);
      }

      100% {
        transform: scale(1) rotate(360deg);
      }
    }

    .theme-icon-spin {
      display: inline-block;
      animation: theme-toggle-spin 550ms cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    button,
    a {
      -webkit-tap-highlight-color: transparent;
    }

    button:not([disabled]),
    a[href] {
      transition: transform 180ms ease, box-shadow 180ms ease, background-color 180ms ease, color 180ms ease, border-color 180ms ease;
    }

    button:not([disabled]):active,
    a[href]:active {
      transform: scale(.97);
    }

    .resident-dashboard .add-dependent-trigger {
      background: linear-gradient(135deg, #f0fdfa 0%, #eff6ff 100%) !important;
      border-color: #7dd3fc !important;
      color: #0f172a !important;
      box-shadow: 0 14px 34px rgba(14, 165, 233, .14) !important;
    }

    .resident-dashboard .add-dependent-trigger i {
      color: #0284c7 !important;
      stroke: currentColor !important;
    }

    .resident-dashboard .add-dependent-trigger strong {
      color: #0f172a !important;
    }

    .resident-dashboard .add-dependent-trigger .text-slate-500 {
      color: #475569 !important;
    }

    .resident-dashboard #dependent-modal button[type="submit"] {
      background: linear-gradient(90deg, #0f766e 0%, #0284c7 100%) !important;
      color: #ffffff !important;
      box-shadow: 0 14px 30px rgba(13, 148, 136, .24) !important;
    }

    .resident-dashboard #dependent-modal [data-dependent-close] {
      background: #ffffff !important;
      color: #334155 !important;
      border-color: #cbd5e1 !important;
    }

    * {
      scrollbar-width: thin;
      scrollbar-color: #059669 transparent;
    }

    @keyframes panel-enter {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    @keyframes modal-enter {
      from {
        opacity: 0;
        transform: translateY(12px) scale(.97);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }
  </style>
  <script>
    window.applyDashboardTheme = function(theme, clickX, clickY) {
      const isDark = theme === 'dark';
      const docEl = document.documentElement;

      // 1. Icon Spin Animation
      const iconEl = document.getElementById('theme-toggle-icon');
      const textEl = document.getElementById('theme-toggle-text');
      if (iconEl) {
        iconEl.classList.remove('theme-icon-spin');
        void iconEl.offsetWidth; // Force reflow for animation restart
        iconEl.classList.add('theme-icon-spin');
        iconEl.innerHTML = isDark ?
          '<i data-lucide="moon" class="h-4 w-4"></i>' :
          '<i data-lucide="sun" class="h-4 w-4"></i>';
        if (window.lucide) lucide.createIcons();
      }
      if (textEl) textEl.textContent = isDark ? 'Dark Mode' : 'Light Mode';

      // 2. Fullscreen Radial Wave Ripple Animation
      const rippleWave = document.getElementById('theme-ripple-wave');
      if (rippleWave && typeof clickX === 'number' && typeof clickY === 'number') {
        const bgGrad = isDark ?
          `radial-gradient(circle at ${clickX}px ${clickY}px, rgba(16, 185, 129, 0.45) 0%, rgba(6, 11, 8, 0.95) 75%)` :
          `radial-gradient(circle at ${clickX}px ${clickY}px, rgba(52, 211, 153, 0.45) 0%, rgba(255, 255, 255, 0.94) 75%)`;

        rippleWave.style.background = bgGrad;
        rippleWave.style.opacity = '0.7';
        window.setTimeout(() => {
          rippleWave.style.opacity = '0';
        }, 350);
      }

      // 3. Toggle Class on Root
      if (isDark) {
        docEl.classList.add('dark');
      } else {
        docEl.classList.remove('dark');
      }

      try {
        localStorage.setItem('rhu_resident_theme_pref', theme);
      } catch (e) {}
    };

    window.toggleDashboardTheme = function(event) {
      const isDarkNow = document.documentElement.classList.contains('dark');
      const targetTheme = isDarkNow ? 'light' : 'dark';

      let x = window.innerWidth / 2;
      let y = 40;
      if (event) {
        x = event.clientX || x;
        y = event.clientY || y;
      } else {
        const btn = document.getElementById('theme-toggle-btn');
        if (btn) {
          const rect = btn.getBoundingClientRect();
          x = rect.left + rect.width / 2;
          y = rect.top + rect.height / 2;
        }
      }

      window.applyDashboardTheme(targetTheme, x, y);
    };

    (function() {
      let saved = 'light';
      try {
        saved = localStorage.getItem('rhu_resident_theme_pref') || 'light';
      } catch (e) {}
      window.applyDashboardTheme(saved);
    })();

    document.addEventListener('DOMContentLoaded', function() {
      const btn = document.getElementById('theme-toggle-btn');
      if (btn) {
        btn.onclick = function(e) {
          if (e) {
            e.preventDefault();
            e.stopPropagation();
          }
          window.toggleDashboardTheme(e);
          return false;
        };
      }
    });

    window.openResidentDetailCard = function(card) {
      const modal = document.getElementById('resident-detail-card-modal');
      if (!modal || !card) return;

      const imageUrl = card.dataset.detailImage || '';
      const image = document.getElementById('resident-detail-card-image');
      const imageWrap = document.getElementById('resident-detail-card-image-wrap');
      const typeEl = document.getElementById('resident-detail-card-type');
      const titleEl = document.getElementById('resident-detail-card-title');
      const badgeEl = document.getElementById('resident-detail-card-badge');
      const dateEl = document.getElementById('resident-detail-card-date');
      const descEl = document.getElementById('resident-detail-card-description');
      const locationEl = document.getElementById('resident-detail-card-location');

      if (typeEl) typeEl.textContent = card.dataset.detailType || 'Details';
      if (titleEl) titleEl.textContent = card.dataset.detailTitle || 'RHU Update';
      if (badgeEl) badgeEl.textContent = card.dataset.detailBadge || 'Official';
      if (dateEl) dateEl.textContent = card.dataset.detailDate || '';
      if (descEl) descEl.textContent = card.dataset.detailDescription || 'No additional details provided.';
      if (locationEl) {
        const location = card.dataset.detailLocation || '';
        locationEl.innerHTML = location ? '<i data-lucide="map-pin" class="h-3.5 w-3.5 text-emerald-500"></i><span></span>' : '';
        const textSpan = locationEl.querySelector('span');
        if (textSpan) textSpan.textContent = location;
      }
      if (image && imageWrap) {
        imageWrap.classList.toggle('hidden', !imageUrl);
        image.src = imageUrl || '';
        image.alt = card.dataset.detailTitle || 'RHU update image';
      }

      modal.classList.remove('hidden');
      modal.classList.add('flex');
      document.body.classList.add('overflow-hidden');
      if (window.lucide) lucide.createIcons();
    };

    window.closeResidentDetailCard = function() {
      const modal = document.getElementById('resident-detail-card-modal');
      if (!modal) return;
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      document.body.classList.remove('overflow-hidden');
    };

    document.addEventListener('click', function(event) {
      const modal = document.getElementById('resident-detail-card-modal');
      if (modal && event.target === modal) window.closeResidentDetailCard();
    });

    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') window.closeResidentDetailCard();
    });
  </script>

  <!-- MODAL FUNCTIONS - Define early so onclick handlers can use them -->
  <script>
    // Dependent Modal Functions
    window.openDependentModal = function(e) {
      if (e && e.preventDefault) {
        e.preventDefault();
        e.stopPropagation();
      }
      const dependentModal = document.getElementById('dependent-modal');
      if (!dependentModal) {
        console.error('Dependent modal element not found');
        return false;
      }
      console.log('Opening dependent modal...');
      dependentModal.classList.remove('hidden');
      dependentModal.classList.add('flex');
      document.body.classList.add('overflow-hidden');
      return false;
    };

    window.closeDependentModal = function(e) {
      if (e && e.preventDefault) {
        e.preventDefault();
        e.stopPropagation();
      }
      const dependentModal = document.getElementById('dependent-modal');
      if (!dependentModal) return false;
      console.log('Closing dependent modal...');
      dependentModal.classList.add('hidden');
      dependentModal.classList.remove('flex');
      document.body.classList.remove('overflow-hidden');
      return false;
    };

    console.log('Modal functions defined early');
  </script>
<style>
/* Resident records reuse the staff dental sheet within a bounded, keyboard-scrollable list. */
.resident-dental-sheets{max-height: min(68vh, 680px);overflow-y:auto;overscroll-behavior-y:contain;scrollbar-gutter:stable;padding:4px 12px 12px 4px;border:1px solid #dce9e5;border-radius:16px;background:#f4f8f7;scrollbar-width:thin;scrollbar-color:#90b6ac #edf4f1}
.resident-dental-sheets:focus-visible{outline:2px solid #0f766e;outline-offset:3px}
.resident-dental-sheets .history-entry{margin:12px 0}
.resident-dental-sheets .history-entry:first-child{margin-top:0}
.resident-dental-sheets dl,.resident-dental-sheets dd{margin:0}
.resident-dental-sheets .history-entry{margin:14px 0}.resident-dental-sheets .history-entry article{padding:0;border-top:0}.resident-dental-sheets .dental-exam-sheet{display:grid;grid-template-columns:minmax(260px,.9fr) minmax(0,1.1fr);border:1px solid #e4c8b8;border-radius:14px;background:#fffcf7;overflow:hidden}.resident-dental-sheets .dental-sheet-chart{padding:18px 14px;border-right:3px double #c47b5e}.resident-dental-sheets .dental-sheet-chart svg{display:block;width:100%;max-width:350px;margin:auto}.resident-dental-sheets .dental-sheet-label,.resident-dental-sheets .record-quadrant{color:#a65e42;fill:#a65e42;font:700 10px system-ui,sans-serif;letter-spacing:.5px;text-transform:uppercase}.resident-dental-sheets .dental-sheet-label{text-align:center;margin:0 0 8px}.resident-dental-sheets .record-tooth-number{fill:#704c3c;font:700 12px system-ui,sans-serif}.resident-dental-sheets .dental-sheet-details{padding:20px;min-width:0}.resident-dental-sheets .dental-sheet-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.resident-dental-sheets .dental-sheet-heading h3{margin:0;color:#a65e42;font-size:16px;letter-spacing:1px;text-transform:uppercase}.resident-dental-sheets .sheet-status{border-radius:20px;padding:5px 10px;background:#e9f8f1;color:#086349;font-size:11px;font-weight:700}.resident-dental-sheets .sheet-visit{display:block}.resident-dental-sheets .sheet-visit>div{display:flex;gap:16px;border-bottom:1px solid #e4c8b8;padding:8px 0}.resident-dental-sheets .sheet-visit dt{width:60px;color:#a65e42;font-size:11px}.resident-dental-sheets .sheet-visit dd{font-size:13px}.resident-dental-sheets .dental-sheet-section{margin-top:16px}.resident-dental-sheets .dental-sheet-section h4{margin:0 0 6px;color:#a65e42;font-size:11px;text-transform:uppercase}.resident-dental-sheets .dental-sheet-section p{margin:0;font-size:13px;line-height:1.7;white-space:pre-line;overflow-wrap:anywhere}.resident-dental-sheets .sheet-observations{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.resident-dental-sheets .sheet-observations>div{flex:1;padding:10px;border:1px solid #ead8cc;border-radius:8px;min-width:85px}.resident-dental-sheets .sheet-observations dt{font-size:10px;color:#93624d}.resident-dental-sheets .sheet-observations dd{font-size:12px;font-weight:700;margin-top:5px}.resident-dental-sheets .dental-sheet-footer{margin:14px 0 0;color:#a65e42;font-size:9px;text-transform:uppercase}.resident-dental-sheets .sheet-color-key{display:flex;flex-wrap:wrap;gap:8px;font-size:9px;color:#704c3c}.resident-dental-sheets .sheet-color-key span:before{content:'';display:inline-block;width:7px;height:7px;margin-right:3px;border-radius:50%;background:var(--key)}.resident-dental-sheets .service-record-sheet,.resident-dental-sheets .dental-prescription-sheet{border:1px solid #e4c8b8;border-radius:12px;background:#fffcf7;overflow:hidden}.resident-dental-sheets .history-entry .service-record-sheet{padding:18px 20px}.resident-dental-sheets .sheet-record-subtitle,.resident-dental-sheets .dental-rx-record-info{font-size:12px;margin:0 0 12px;color:#64748b}.resident-dental-sheets .service-record-fields{display:block}.resident-dental-sheets .service-record-fields>div{display:grid;grid-template-columns:150px 1fr;gap:14px;padding:9px 0;border-bottom:1px solid #eeded3}.resident-dental-sheets .service-record-fields dt{font-size:11px;color:#93624d;text-transform:uppercase}.resident-dental-sheets .service-record-fields dd{font-size:13px;line-height:1.6;white-space:pre-line;overflow-wrap:anywhere}.resident-dental-sheets .dental-prescription-sheet{max-width:640px;margin-inline:auto}.resident-dental-sheets .dental-prescription-sheet .dental-sheet-details{padding:16px}.resident-dental-sheets .dental-rx-medicine{display:flex;align-items:center;gap:10px}.resident-dental-sheets .dental-rx-symbol{font:italic 26px Georgia,serif;color:#a65e42}.resident-dental-sheets .dental-rx-name{font-size:15px!important;font-weight:700}.resident-dental-sheets .dental-rx-directions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px;margin-top:12px}.resident-dental-sheets .dental-rx-directions dt{font-size:10px;color:#a65e42;text-transform:uppercase}.resident-dental-sheets .dental-rx-directions dd{font-size:12px;margin-top:3px}.resident-dental-sheets .dental-prescription-embedded{border:0;border-top:1px solid #e4c8b8;border-radius:0;margin-top:16px;padding-top:12px!important;box-shadow:none}.resident-dental-sheets .dental-prescription-embedded .dental-sheet-details{padding:0}.resident-dental-sheets .dental-prescription-embedded .dental-sheet-heading h3{font-size:12px}.resident-dental-sheets .dental-sheet-patient{display:block}.resident-dental-sheets .dental-sheet-patient>div{display:flex;gap:12px;padding:5px 0}.resident-dental-sheets .dental-sheet-patient dt{font-size:10px;width:65px}.resident-dental-sheets .dental-sheet-patient dd{font-size:12px}.resident-dental-sheets .history-entry .dental-exam-sheet{max-width:920px;margin-inline:auto;grid-template-columns:minmax(260px,.85fr) minmax(0,1.15fr);gap:0;border:1px solid #e4c8b8;background:#fffcf7;overflow:hidden;align-items:start}.resident-dental-sheets .history-entry .dental-exam-sheet>.dental-sheet-chart{padding:16px;border-right:1px solid #e4c8b8}.resident-dental-sheets .history-entry .dental-exam-sheet>.dental-sheet-details{padding:16px}.resident-dental-sheets .history-entry .dental-sheet-chart svg{max-width:260px;height:auto}.resident-dental-sheets .history-entry .sheet-color-key{justify-content:center;gap:6px;margin-top:8px}.resident-dental-sheets .sheet-prescriptions{grid-column:1/-1;padding:0 16px 14px}.resident-dental-sheets .history-entry .sheet-prescriptions .dental-prescription-embedded{max-width:none;border:0;border-top:1px solid #e4c8b8;border-radius:0;margin:0;padding-top:12px!important;background:transparent}.resident-dental-sheets .sheet-prescriptions .dental-prescription-embedded+.dental-prescription-embedded{margin-top:12px}.resident-dental-sheets .sheet-prescriptions .dental-sheet-details{display:grid;grid-template-columns:minmax(180px,1fr) minmax(0,1.5fr);column-gap:24px;padding:0}.resident-dental-sheets .sheet-prescriptions .dental-sheet-heading,.resident-dental-sheets .sheet-prescriptions .dental-rx-record-info,.resident-dental-sheets .sheet-prescriptions .dental-sheet-footer{grid-column:1/-1}.resident-dental-sheets .sheet-prescriptions .dental-sheet-heading{margin-bottom:4px}.resident-dental-sheets .sheet-prescriptions .dental-rx-record-info{font-size:11px;margin-bottom:4px}.resident-dental-sheets .sheet-prescriptions .dental-sheet-section{margin-top:8px}.resident-dental-sheets .sheet-prescriptions .dental-sheet-section p{line-height:1.45}.resident-dental-sheets .history-entry .sheet-prescriptions .dental-rx-directions{grid-template-columns:repeat(4,minmax(0,1fr));gap:8px 12px;margin-top:8px}.resident-dental-sheets .sheet-prescriptions .dental-sheet-footer{margin-top:10px}

.resident-dental-sheets .dental-prescription-sheet{width:100%;max-width:920px}
.resident-dental-sheets .dental-sheet-heading{flex-wrap:wrap}
/* Compact two-by-two dental cards; additional rows scroll within the list. */
.resident-dental-sheets{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:start;max-height:620px;padding:10px;background:rgba(244,248,247,.7)}
.resident-dental-sheets .history-entry,.resident-dental-sheets .history-entry:first-child{margin:0;min-width:0}
.resident-dental-sheets .history-entry .dental-exam-sheet{max-width:none;grid-template-columns:minmax(110px,.7fr) minmax(0,1.3fr);min-height:284px;border-radius:12px}
.resident-dental-sheets .history-entry .dental-exam-sheet>.dental-sheet-chart{padding:12px 6px}
.resident-dental-sheets .history-entry .dental-sheet-chart svg{max-width:150px;height:auto}
.resident-dental-sheets .history-entry .dental-exam-sheet>.dental-sheet-details,.resident-dental-sheets .dental-prescription-sheet .dental-sheet-details{padding:12px}
.resident-dental-sheets .dental-sheet-heading{gap:6px;margin-bottom:8px}
.resident-dental-sheets .dental-sheet-heading h3{font-size:12px;letter-spacing:.4px;font-weight:700}
.resident-dental-sheets .sheet-status{padding:3px 7px;font-size:10px}
.resident-dental-sheets .sheet-visit>div{gap:6px;padding:5px 0}
.resident-dental-sheets .sheet-visit dt{width:40px;flex-shrink:0;font-size:10px}
.resident-dental-sheets .sheet-visit dd{font-size:11px;overflow-wrap:anywhere}
.resident-dental-sheets .dental-sheet-section{margin-top:10px}
.resident-dental-sheets .dental-sheet-section h4{font-size:9px;margin-bottom:3px}
.resident-dental-sheets .dental-sheet-section p{font-size:11px;line-height:1.5}
.resident-dental-sheets .sheet-observations{gap:5px;margin-top:10px}
.resident-dental-sheets .sheet-observations>div{padding:6px;min-width:58px}
.resident-dental-sheets .sheet-observations dt{font-size:9px}
.resident-dental-sheets .sheet-observations dd{font-size:10px;margin-top:3px}
.resident-dental-sheets .sheet-color-key{font-size:8px;gap:4px}
.resident-dental-sheets .dental-sheet-footer{font-size:8px;margin-top:10px}
.resident-dental-sheets .dental-prescription-sheet{max-width:none;min-height:284px}
.resident-dental-sheets .dental-rx-name{font-size:13px!important}
.resident-dental-sheets .dental-rx-record-info{font-size:10px;margin-bottom:8px}
.resident-dental-sheets .dental-rx-directions{gap:8px;margin-top:10px}
.resident-dental-sheets .dental-rx-directions dd{font-size:11px;overflow-wrap:anywhere}
/* Use the full card height for the chart and examination details. */
.resident-dental-sheets .history-entry .dental-exam-sheet{align-items:stretch}
.resident-dental-sheets .history-entry .dental-exam-sheet>.dental-sheet-chart{display:flex;flex-direction:column;justify-content:space-between;padding:14px 8px}
.resident-dental-sheets .history-entry .dental-sheet-chart svg{width:100%;max-width:185px;flex:1;min-height:0;margin:4px auto;max-height:260px}
.resident-dental-sheets .history-entry .dental-exam-sheet>.dental-sheet-details{display:flex;flex-direction:column;justify-content:space-between;gap:8px;padding:14px}
.resident-dental-sheets .history-entry .dental-exam-sheet .dental-sheet-heading{margin-bottom:0}
.resident-dental-sheets .history-entry .dental-exam-sheet .dental-sheet-section,.resident-dental-sheets .history-entry .dental-exam-sheet .sheet-observations{margin-top:0}
.resident-dental-sheets .history-entry .dental-exam-sheet .dental-sheet-footer{margin-top:0}
.resident-dental-sheets .history-entry>.service-record-sheet{height:100%;min-height:0;overflow-y:auto;padding:14px;display:flex;flex-direction:column;scrollbar-width:thin}
.resident-dental-sheets .service-record-sheet .service-record-fields{flex:1}
.resident-dental-sheets .service-record-sheet .service-record-fields>div{grid-template-columns:100px minmax(0,1fr);gap:10px;padding:8px 0}
.resident-dental-sheets .service-record-sheet .service-record-fields dt{font-size:10px}
.resident-dental-sheets .service-record-sheet .service-record-fields dd{font-size:11px}
.resident-dental-sheets .service-record-sheet .sheet-record-subtitle{font-size:11px}
.resident-general-records{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));grid-auto-rows:340px;gap:12px;height:362px;overflow-y:auto;padding:10px;border:1px solid #dce9e5;border-radius:16px;background:#f4f8f7;scrollbar-width:thin;scrollbar-gutter:stable;overscroll-behavior-y:contain}
.resident-general-records:focus-visible{outline:2px solid #0f766e;outline-offset:3px}
.resident-general-card{min-width:0;padding:18px;border:1px solid #e2e8f0;border-radius:14px;background:#fff;overflow-y:auto;scrollbar-width:thin;color:#334155}
.resident-general-card header{display:flex;justify-content:space-between;align-items:center;gap:8px}
.resident-record-category{font-size:10px;font-weight:750;text-transform:uppercase;letter-spacing:.5px;color:#0f766e}
.resident-record-status{font-size:10px;padding:4px 8px;border-radius:20px;background:#f1f5f9;color:#475569}
.resident-general-card h5{margin:12px 0 6px;font-size:16px;font-weight:750;color:#0f172a}
.resident-record-meta{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:11px;color:#64748b;border-bottom:1px solid #edf2f7;padding-bottom:12px}
.resident-general-card dl{margin:0}.resident-general-card dl>div{padding-top:12px}.resident-general-card dt{font-size:10px;font-weight:700;color:#64748b}.resident-general-card dd{margin:4px 0 0;font-size:12px;line-height:1.6;white-space:pre-line;overflow-wrap:anywhere}
@media(max-width:700px){.resident-general-records{grid-template-columns:1fr}}
/* One visible row of equal-sized cards; later records scroll into view. */
.resident-dental-sheets{--dental-card-height:360px;height:382px;max-height:382px;grid-auto-rows:var(--dental-card-height);align-items:stretch;scroll-snap-type:y proximity;scroll-padding-top:10px}
.resident-dental-sheets .history-entry{height:var(--dental-card-height);scroll-snap-align:start}
.resident-dental-sheets .history-entry>.dental-exam-sheet,.resident-dental-sheets .history-entry>.dental-prescription-sheet{height:100%;min-height:0;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#c7d8d2 transparent}
.resident-dental-sheets .history-entry>.dental-prescription-sheet{margin:0}
@media(max-width:700px){.resident-dental-sheets{grid-template-columns:1fr;height:378px;max-height:378px;padding:8px}.resident-dental-sheets .history-entry .dental-exam-sheet{grid-template-columns:minmax(100px,.65fr) minmax(0,1.35fr)}}
@media print{.resident-dental-sheets{height:auto;max-height:none;grid-auto-rows:auto;overflow:visible;border:0;padding:0}.resident-dental-sheets .history-entry{height:auto;break-inside:avoid}.resident-dental-sheets .history-entry>article{height:auto;overflow:visible}}
</style>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
</head>

<body class="resident-dashboard theme-light min-h-screen antialiased flex flex-col md:flex-row">
  <!-- Radial Ripple Wave Overlay -->
  <div id="theme-ripple-wave" class="fixed inset-0 pointer-events-none z-99999 opacity-0 transition-opacity duration-500"></div>

  <!-- Fixed Fullscreen RHU Municipal Hall Background -->
  <div id="rhu-dashboard-bg" class="fixed inset-0 z-[-1] pointer-events-none overflow-hidden">
    <img id="rhu-bg-img" src="../../../assets/admin-municipal-background.png" alt="RHU Municipal Hall" class="h-full w-full object-cover object-center transition-all duration-500" />
    <div id="rhu-bg-overlay" class="absolute inset-0 transition-all duration-500"></div>
  </div>

  <div id="scroll-progress" aria-hidden="true"></div>

  <!-- Mobile Overlay -->
  <div id="sidebar-overlay" class="fixed inset-0 z-40 hidden md:hidden" aria-hidden="true"></div>

  <!-- Sidebar -->
  <aside id="sidebar" class="fixed md:sticky top-0 z-50 h-screen w-64 shrink-0 border-r transition-all duration-200 ease-in-out flex flex-col justify-between -translate-x-full md:translate-x-0 shadow-2xl">
    <div>
      <!-- Header / Toggle -->
      <div class="sidebar-brand-header flex border-b border-slate-200 dark:border-white/10">
        <div class="sidebar-brand flex flex-col gap-2 overflow-hidden">
          <div class="sidebar-brand-logo flex shrink-0 items-center justify-center">
            <img src="assets/images/nasugbu_seal.png" alt="Nasugbu seal">
          </div>
          <div class="sidebar-header-text">
            <h2 class="sidebar-header-title text-sm font-extrabold tracking-wide">ResiHUnity</h2>
            <p class="sidebar-header-subtitle text-[10px] font-semibold">Resident Portal</p>
          </div>
        </div>
        <div class="absolute right-3 top-4 flex items-center gap-1">
          <button id="sidebar-close-btn" type="button" onclick="window.toggleMobileSidebar && window.toggleMobileSidebar(event)" class="md:hidden flex h-11 w-11 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors" title="Close Menu">
            <i data-lucide="x" class="h-5 w-5"></i>
          </button>
          <button id="sidebar-collapse-btn" type="button" class="hidden md:flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors">
            <i data-lucide="panel-left-close" class="h-4 w-4"></i>
          </button>
        </div>
      </div>

      <!-- Navigation -->
      <nav class="p-3 space-y-1 overflow-y-auto max-h-[calc(100vh-11rem)]">
        <?php foreach ($tabs as $key => [$label, $icon]): ?>
          <button type="button" data-tab-button="<?= $key ?>" class="sidebar-link w-full flex items-center gap-3 px-3.5 py-2.5 text-xs font-bold transition-all text-slate-600 dark:text-slate-400 hover:bg-emerald-500/10 hover:text-emerald-700 dark:hover:text-white">
            <i data-lucide="<?= $icon ?>" class="h-4 w-4 shrink-0"></i>
            <span class="sidebar-text truncate"><?= esc($label) ?></span>
          </button>
        <?php endforeach; ?>
      </nav>
    </div>

    <!-- User Section -->
    <div class="p-3 border-t border-slate-200 dark:border-white/10 space-y-2">
      <a href="ResidentDashboard.php?logout=1" data-logout-link class="sidebar-link flex w-full items-center gap-3 px-3.5 py-2.5 text-xs font-bold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-full transition-all">
        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i>
        <span class="sidebar-text truncate">Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- Main Area -->
  <div class="resident-main-shell flex-1 min-w-0 flex flex-col min-h-screen">

    <!-- Top Navigation Bar -->
    <header class="sticky top-0 z-100 h-16 sm:h-20 px-3 sm:px-8 flex items-center justify-between backdrop-blur-xl">
      <div class="flex items-center gap-2.5 sm:gap-4 min-w-0">
        <button id="mobile-menu-btn" type="button" onclick="window.toggleMobileSidebar && window.toggleMobileSidebar(event)" class="relative z-110 md:hidden flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/80 dark:bg-white/5 text-slate-700 dark:text-slate-300 hover:bg-white">
          <i data-lucide="menu" class="h-5 w-5"></i>
        </button>
        <h1 class="text-base sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight truncate">Hello Nasugbueño, <?= esc($resident['first_name'] ?? 'Alex') ?>!</h1>
        <span class="rounded-lg bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-800">Patient #<?= (int)$residentId ?></span>
      </div>

      <!-- Notifications, Theme Switcher & Profile Header -->
      <div class="flex items-center gap-2 sm:gap-3">
        <!-- Light / Dark Mode Toggle Switcher Button -->
        <button type="button" id="theme-toggle-btn" onclick="toggleDashboardTheme()" class="flex items-center gap-1.5 sm:gap-2 rounded-full border border-slate-300 dark:border-white/20 bg-white/90 dark:bg-white/10 px-2.5 sm:px-3.5 py-1.5 text-xs font-bold text-slate-800 dark:text-slate-100 shadow-sm hover:scale-105 transition-all cursor-pointer shrink-0">
          <span id="theme-toggle-icon" class="inline-flex h-4 w-4 items-center justify-center">
            <i data-lucide="sun" class="h-4 w-4"></i>
          </span>
          <span id="theme-toggle-text" class="hidden sm:inline">Light Mode</span>
        </button>

        <div class="relative">
          <button type="button" id="notification-bell-btn" onclick="toggleNotificationPanel(event)" data-notifications class="relative flex h-10 w-10 items-center justify-center rounded-full transition-colors">
            <i data-lucide="bell" class="h-4 w-4"></i>
            <span id="notif-badge-count" class="absolute -right-1 -top-1 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-emerald-500 px-1 text-[10px] font-black text-white dark:text-slate-950 shadow-md" style="display: none;">0</span>
          </button>
        </div>

        <div class="flex items-center gap-2.5 pl-2 border-l border-slate-300 dark:border-white/10">
          <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity" class="top-profile-logo-avatar">
          <div class="hidden sm:block text-left">
            <p class="top-profile-name max-w-[9rem] truncate text-xs font-bold leading-tight"><?= esc($loggedInName) ?></p>
          </div>
        </div>
      </div>
    </header>

    <!-- Dynamic Content Section -->
    <main class="flex-1 p-3.5 sm:p-8 max-w-7xl w-full mx-auto space-y-6">

      <!-- Flashes & Alerts -->
      <?php if ($contactSuccess): ?>
        <div <?= $autoDismissSuccess ? 'id="resident-success-message"' : '' ?> class="rounded-2xl border border-teal-200 bg-teal-50 p-4 text-xs font-semibold text-teal-800 flex items-center gap-2">
          <i data-lucide="check-circle" class="h-4 w-4 text-teal-600 shrink-0"></i> <?= esc($contactSuccess) ?>
        </div>
        <?php if ($autoDismissSuccess): ?>
          <script>
            setTimeout(() => document.getElementById('resident-success-message')?.remove(), 5000);
          </script>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (is_array($appointmentCalendar) && !empty($appointmentCalendar['start'])):
        $calendarStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $appointmentCalendar['start']);
        $googleCalendarUrl = $calendarStart ? 'https://calendar.google.com/calendar/render?' . http_build_query([
          'action' => 'TEMPLATE',
          'text' => $appointmentCalendar['title'],
          'dates' => $calendarStart->format('Ymd\THis') . '/' . $calendarStart->modify('+1 hour')->format('Ymd\THis'),
          'ctz' => date_default_timezone_get(),
          'details' => 'Appointment requested through the resident portal. Please check the portal for schedule changes.',
        ], '', '&', PHP_QUERY_RFC3986) : '';
      ?>
        <div id="appointment-calendar-prompt" class="rounded-2xl border border-teal-200 bg-white p-4 text-sm text-slate-700">
          <p class="font-semibold">Add this requested appointment to your calendar for a reminder?</p>
          <p class="mt-1 text-xs text-slate-500">The requested time may change. Check the portal for updates.</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <a id="phone-calendar-link" href="ResidentDashboard.php?download_appointment_calendar=1" class="rounded-xl bg-teal-700 px-4 py-2 text-xs font-bold text-white hover:bg-teal-800">Add to phone calendar</a>
            <?php if ($googleCalendarUrl): ?><a id="google-calendar-link" href="<?= esc($googleCalendarUrl) ?>" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-teal-300 px-4 py-2 text-xs font-bold text-teal-800 hover:bg-teal-50">Add to Google Calendar</a><?php endif; ?>
          </div>
          <p class="mt-2 text-xs text-slate-500">On your phone, choose your Calendar app when the event opens or in the share menu.</p>
        </div>
        <script>
          const dismissAppointmentCalendarPrompt = () => document.getElementById('appointment-calendar-prompt')?.remove();
          document.getElementById('google-calendar-link')?.addEventListener('click', dismissAppointmentCalendarPrompt);
          document.getElementById('phone-calendar-link')?.addEventListener('click', async function (event) {
            if (!navigator.share || !navigator.canShare || typeof File === 'undefined') {
              setTimeout(dismissAppointmentCalendarPrompt, 300);
              return;
            }
            event.preventDefault();
            try {
              const response = await fetch(this.href, { credentials: 'same-origin' });
              if (!response.ok) throw new Error('Calendar event unavailable');
              const file = new File([await response.blob()], 'rhu-appointment.ics', { type: 'text/calendar' });
              if (!navigator.canShare({ files: [file] })) {
                window.location.href = this.href;
                dismissAppointmentCalendarPrompt();
                return;
              }
              await navigator.share({ files: [file], title: 'RHU appointment request' });
              dismissAppointmentCalendarPrompt();
            } catch (error) {
              if (error.name !== 'AbortError') {
                window.location.href = this.href;
                dismissAppointmentCalendarPrompt();
              }
            }
          });
        </script>
      <?php endif; ?>

      <?php if ($certificateSuccess): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 flex items-center gap-2">
          <i data-lucide="check-circle" class="h-4 w-4 text-emerald-600 shrink-0"></i> <?= esc($certificateSuccess) ?>
        </div>
      <?php endif; ?>

      <!-- 1. HOME TAB -->
      <section data-tab-panel="home" class="space-y-6">

        <!-- Row 1: Top Hero Photo Card + RHU Notices Panel -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

          <!-- Left Hero Card: Admin Posted Health Events Carousel (~60% width) -->
          <div class="lg:col-span-7 relative min-h-[23rem] overflow-hidden rounded-3xl border border-white/10 bg-slate-900 shadow-2xl flex flex-col justify-between p-6 sm:p-8 group" id="hero-events-container">
            <!-- Background Image -->
            <img id="hero-event-bg" src="<?= esc($initialHeroImage) ?>" alt="RHU Event Background" class="absolute inset-0 h-full w-full object-cover object-center transition-all duration-700 group-hover:scale-105" onerror="this.onerror=null; this.src='<?= esc($defaultHeroImage) ?>';" />
            <div class="absolute inset-0 bg-gradient-to-r from-black/90 via-black/70 to-black/35"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-transparent"></div>

            <!-- Top Bar: Badge + Patient Context -->
            <div class="relative z-10 flex items-center justify-between gap-3">
              <span class="inline-flex items-center gap-2 rounded-full bg-emerald-950/90 px-3.5 py-1.5 text-xs font-bold text-emerald-300 border border-emerald-500/40 backdrop-blur-md shadow-lg">
                <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span>RHU Admin Event Spotlight</span>
              </span>
              <div class="flex items-center gap-2">
                <span class="text-[11px] font-mono font-medium text-slate-300 bg-black/60 px-3 py-1 rounded-full border border-white/10 backdrop-blur-sm hidden sm:inline-block">
                  Patient #<?= esc($resident['id'] ?? '8') ?> - Brgy. <?= esc($resident['barangay'] ?? 'Nasugbu') ?>
                </span>
                <label class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-full border border-white/20 bg-black/60 px-3 text-[10px] font-extrabold uppercase tracking-wide text-white backdrop-blur-md transition hover:border-emerald-300 hover:bg-emerald-500/80" title="Save a cover picture on this device">
                  <i data-lucide="image-plus" class="h-3.5 w-3.5 text-emerald-300"></i>
                  <span class="hidden sm:inline">Upload cover</span>
                  <input id="hero-event-image-upload" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only">
                </label>
                <button id="hero-event-image-reset" type="button" class="hidden h-9 w-9 items-center justify-center rounded-full border border-white/20 bg-black/60 text-slate-300 backdrop-blur-md transition hover:border-rose-300 hover:bg-rose-500/80 hover:text-white" title="Remove saved cover picture" aria-label="Remove saved cover picture">
                  <i data-lucide="image-off" class="h-3.5 w-3.5"></i>
                </button>
              </div>
            </div>

            <!-- Slides Container -->
            <div class="relative z-10 my-3 space-y-2">
              <?php foreach ($postedEvents as $index => $ev):
                $evTitle = $ev['title'] ?? 'RHU Health Program';
                $evVenue = $ev['venue'] ?? 'Nasugbu RHU Center';
                $evDesc = $ev['description'] ?? 'No description provided.';
                $rawDate = $ev['event_date'] ?? ($ev['scheduled_date'] ?? 'now');
                $evDateFormatted = date('M d, Y', strtotime($rawDate));
                $evTime = !empty($ev['start_time']) ? date('g:i A', strtotime($ev['start_time'])) : '8:00 AM';
                $evImg = !empty($ev['image_url']) ? resolveImageUrl((string)$ev['image_url']) : $defaultHeroImage;
                if ($evImg === '') {
                  $evImg = $defaultHeroImage;
                }
              ?>
                <div data-hero-slide="<?= $index ?>" data-bg="<?= $evImg ?>" class="hero-slide-item transition-all duration-500 <?= $index === 0 ? 'block' : 'hidden' ?> space-y-2">
                  <div class="flex items-center gap-2">
                    <span class="rounded-md bg-emerald-500/20 px-2.5 py-0.5 text-[10px] font-extrabold text-emerald-300 uppercase tracking-wider border border-emerald-500/30">
                      <?= esc($ev['status'] ?? 'Upcoming Event') ?>
                    </span>
                    <span class="rounded-md bg-black/60 px-2 py-0.5 text-xs font-bold text-white border border-white/15">Post <?= $index + 1 ?> of <?= count($postedEvents) ?></span>
                  </div>

                  <h2 class="text-xl sm:text-2xl font-black text-white leading-tight tracking-tight drop-shadow-md">
                    <?= esc($evTitle) ?>
                  </h2>

                  <p class="text-sm text-white line-clamp-2 leading-relaxed font-semibold max-w-xl drop-shadow-md">
                    <?= esc($evDesc) ?>
                  </p>

                  <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold text-emerald-300/90 pt-1">
                    <span class="flex items-center gap-1.5 bg-black/50 px-2.5 py-1 rounded-lg border border-white/10">
                      <i data-lucide="calendar" class="h-3.5 w-3.5 text-emerald-400"></i>
                      <?= esc($evDateFormatted) ?> - <?= esc($evTime) ?>
                    </span>
                    <span class="flex items-center gap-1.5 bg-black/50 px-2.5 py-1 rounded-lg border border-white/10">
                      <i data-lucide="map-pin" class="h-3.5 w-3.5 text-emerald-400"></i>
                      <?= esc($evVenue) ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- Bottom Action Row: Action Button + Slide Navigation Controls -->
            <div class="relative z-10 flex items-center justify-between border-t border-white/10 pt-3 mt-1">
              <button type="button" data-tab-link="events" class="rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-4 py-2 text-xs font-black shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-1.5 active:scale-95">
                <span>View All Admin Events</span>
                <i data-lucide="arrow-right" class="h-4 w-4"></i>
              </button>

              <!-- Carousel Control Arrows & Dots -->
              <div class="flex items-center gap-3">
                <div class="flex items-center gap-1.5" id="hero-slide-dots">
                  <?php foreach ($postedEvents as $index => $ev): ?>
                    <button type="button" onclick="window.setHeroSlide(<?= $index ?>)" class="hero-dot h-2 rounded-full transition-all duration-300 <?= $index === 0 ? 'w-6 bg-emerald-400' : 'w-2 bg-white/30 hover:bg-white/60' ?>" aria-label="Slide <?= $index + 1 ?>"></button>
                  <?php endforeach; ?>
                </div>

                <div class="flex items-center gap-1 pl-2 border-l border-white/10">
                  <button type="button" onclick="window.prevHeroSlide()" class="rounded-lg p-1.5 text-slate-300 hover:bg-white/10 hover:text-white transition-colors" title="Previous Event">
                    <i data-lucide="chevron-left" class="h-4 w-4"></i>
                  </button>
                  <button type="button" onclick="window.nextHeroSlide()" class="rounded-lg p-1.5 text-slate-300 hover:bg-white/10 hover:text-white transition-colors" title="Next Event">
                    <i data-lucide="chevron-right" class="h-4 w-4"></i>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Right Messages Card (~40% width) -->
          <div class="lg:col-span-5 rounded-3xl border border-white/10 bg-[#131916] p-6 shadow-2xl flex flex-col justify-between space-y-4">
            <div class="flex items-center justify-between border-b border-white/5 pb-3">
              <div class="flex items-center gap-2">
                <i data-lucide="message-square" class="h-5 w-5 text-emerald-400"></i>
                <h3 class="font-bold text-white text-base">RHU Staff Notices</h3>
                <span id="staff-notices-count" class="rounded-full bg-emerald-950 px-2 py-0.5 text-[10px] font-black text-emerald-400 border border-emerald-800/60">0</span>
              </div>
              <button type="button" onclick="toggleNotificationPanel(event)" class="text-xs font-semibold text-slate-400 hover:text-emerald-400 transition-colors flex items-center gap-1">
                View all <i data-lucide="arrow-up-right" class="h-4 w-4"></i>
              </button>
            </div>

            <div id="staff-notices-list" class="space-y-3.5 flex-1 overflow-y-auto max-h-[16rem] pr-1">
              <div class="p-4 text-center text-xs font-semibold text-slate-400">Loading RHU notices...</div>
            </div>
          </div>
        </div>

        <!-- Row 2: 5 RHU System Stat / Metric Cards Organized 2x2 Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">

          <!-- Card 1: Health Consultations -->
          <div class="col-span-1 rounded-2xl sm:rounded-3xl border border-white/10 bg-[#131916] p-3.5 sm:p-5 shadow-xl hover:border-emerald-500/30 transition-all flex flex-col justify-between group">
            <div class="flex items-center justify-between">
              <div class="flex h-8 sm:h-9 w-8 sm:w-9 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <i data-lucide="stethoscope" class="h-4 w-4"></i>
              </div>
              <button type="button" data-tab-link="records" class="flex h-7 sm:h-8 w-7 sm:w-8 items-center justify-center rounded-full bg-white/5 text-slate-300 group-hover:bg-emerald-500 group-hover:text-slate-950 transition-all">
                <i data-lucide="arrow-up-right" class="h-3.5 sm:h-4 w-3.5 sm:w-4"></i>
              </button>
            </div>
            <div class="mt-2.5 sm:mt-4">
              <p class="text-[11px] sm:text-xs font-semibold text-slate-400 truncate">OPD Consultations</p>
              <div class="mt-0.5 sm:mt-1 flex items-baseline gap-1.5">
                <span class="text-xl sm:text-2xl font-black text-white"><?= (int)$visitsThisYear ?> <span class="text-xs font-medium text-slate-400">Visits</span></span>
              </div>
            </div>
            <p class="mt-2 sm:mt-3 text-[9px] sm:text-[10px] font-medium text-slate-500">Jan 01, <?= (int)date('Y') ?> - Present</p>
          </div>

          <!-- Card 2: Prescriptions Issued -->
          <div class="col-span-1 rounded-2xl sm:rounded-3xl border border-white/10 bg-[#131916] p-3.5 sm:p-5 shadow-xl hover:border-emerald-500/30 transition-all flex flex-col justify-between group">
            <div class="flex items-center justify-between">
              <div class="flex h-8 sm:h-9 w-8 sm:w-9 items-center justify-center rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20">
                <i data-lucide="pill" class="h-4 w-4"></i>
              </div>
              <button type="button" data-tab-link="records" class="flex h-7 sm:h-8 w-7 sm:w-8 items-center justify-center rounded-full bg-white/5 text-slate-300 group-hover:bg-emerald-500 group-hover:text-slate-950 transition-all">
                <i data-lucide="arrow-up-right" class="h-3.5 sm:h-4 w-3.5 sm:w-4"></i>
              </button>
            </div>
            <div class="mt-2.5 sm:mt-4">
              <p class="text-[11px] sm:text-xs font-semibold text-slate-400 truncate">Prescriptions Issued</p>
              <div class="mt-0.5 sm:mt-1 flex items-baseline gap-1.5">
                <span class="text-xl sm:text-2xl font-black text-white"><?= (int)$totalPrescriptions ?> <span class="text-xs font-medium text-slate-400">Rx</span></span>
              </div>
            </div>
            <p class="mt-2 sm:mt-3 text-[9px] sm:text-[10px] font-medium text-slate-500">Jan 01, <?= (int)date('Y') ?> - Present</p>
          </div>

          <!-- Card 3: Health Certificates -->
          <div class="col-span-1 rounded-2xl sm:rounded-3xl border border-white/10 bg-[#131916] p-3.5 sm:p-5 shadow-xl hover:border-emerald-500/30 transition-all flex flex-col justify-between group">
            <div class="flex items-center justify-between">
              <div class="flex h-8 sm:h-9 w-8 sm:w-9 items-center justify-center rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20">
                <i data-lucide="award" class="h-4 w-4"></i>
              </div>
              <button type="button" data-tab-link="certificates" class="flex h-7 sm:h-8 w-7 sm:w-8 items-center justify-center rounded-full bg-white/5 text-slate-300 group-hover:bg-emerald-500 group-hover:text-slate-950 transition-all">
                <i data-lucide="arrow-up-right" class="h-3.5 sm:h-4 w-3.5 sm:w-4"></i>
              </button>
            </div>
            <div class="mt-2.5 sm:mt-4">
              <p class="text-[11px] sm:text-xs font-semibold text-slate-400 truncate">Health Certificates</p>
              <div class="mt-0.5 sm:mt-1 flex items-baseline gap-1.5">
                <span class="text-xl sm:text-2xl font-black text-white"><?= count($healthCertificates ?? []) ?> <span class="text-xs font-medium text-slate-400">Docs</span></span>
              </div>
            </div>
            <p class="mt-2 sm:mt-3 text-[9px] sm:text-[10px] font-medium text-slate-500">Jan 01, <?= (int)date('Y') ?> - Present</p>
          </div>

          <!-- Card 4: Immunization Doses -->
          <div class="col-span-1 rounded-2xl sm:rounded-3xl border border-white/10 bg-[#131916] p-3.5 sm:p-5 shadow-xl hover:border-emerald-500/30 transition-all flex flex-col justify-between group">
            <div class="flex items-center justify-between">
              <div class="flex h-8 sm:h-9 w-8 sm:w-9 items-center justify-center rounded-full bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                <i data-lucide="shield-check" class="h-4 w-4"></i>
              </div>
              <button type="button" data-tab-link="immunization" class="flex h-7 sm:h-8 w-7 sm:w-8 items-center justify-center rounded-full bg-white/5 text-slate-300 group-hover:bg-emerald-500 group-hover:text-slate-950 transition-all">
                <i data-lucide="arrow-up-right" class="h-3.5 sm:h-4 w-3.5 sm:w-4"></i>
              </button>
            </div>
            <div class="mt-2.5 sm:mt-4">
              <p class="text-[11px] sm:text-xs font-semibold text-slate-400 truncate">Vaccine Doses</p>
              <div class="mt-0.5 sm:mt-1 flex items-baseline gap-1.5">
                <span class="text-xl sm:text-2xl font-black text-white"><?= count($vaccinationRecords ?? []) ?> <span class="text-xs font-medium text-slate-400">Doses</span></span>
              </div>
            </div>
            <p class="mt-2 sm:mt-3 text-[9px] sm:text-[10px] font-medium text-slate-500">Jan 01, <?= (int)date('Y') ?> - Present</p>
          </div>

          <!-- Card 5: Emergency Referrals -->
          <div class="col-span-2 sm:col-span-1 rounded-2xl sm:rounded-3xl border border-white/10 bg-[#131916] p-3.5 sm:p-5 shadow-xl hover:border-emerald-500/30 transition-all flex flex-col justify-between group">
            <div class="flex items-center justify-between">
              <div class="flex h-8 sm:h-9 w-8 sm:w-9 items-center justify-center rounded-full bg-teal-500/10 text-teal-400 border border-teal-500/20">
                <i data-lucide="siren" class="h-4 w-4"></i>
              </div>
              <button type="button" data-tab-link="emergency" class="flex h-7 sm:h-8 w-7 sm:w-8 items-center justify-center rounded-full bg-white/5 text-slate-300 group-hover:bg-emerald-500 group-hover:text-slate-950 transition-all">
                <i data-lucide="arrow-up-right" class="h-3.5 sm:h-4 w-3.5 sm:w-4"></i>
              </button>
            </div>
            <div class="mt-2.5 sm:mt-4">
              <p class="text-[11px] sm:text-xs font-semibold text-slate-400 truncate">Emergency & Referrals</p>
              <div class="mt-0.5 sm:mt-1 flex items-baseline gap-1.5">
                <span class="text-xl sm:text-2xl font-black text-white"><?= $emergencyRequestCount ?> <span class="text-xs font-medium text-slate-400">Requests</span></span>
              </div>
            </div>
            <p class="mt-2 sm:mt-3 text-[9px] sm:text-[10px] font-medium text-slate-500">Jan 01, <?= (int)date('Y') ?> - Present</p>
          </div>

        </div>

        <!-- Row 3: 2 RHU Data Tables Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

          <!-- Left Table: Latest Health Certificates & Clearances -->
          <div class="rounded-3xl border border-white/10 bg-[#131916] p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-white/5 pb-3">
              <div class="flex items-center gap-2">
                <h3 class="font-bold text-white text-base">Health Certificates & Permits</h3>
                <span class="rounded-full bg-emerald-950 px-2 py-0.5 text-[10px] font-black text-emerald-400 border border-emerald-800/60"><?= count($healthCertificates ?? []) ?: 1 ?></span>
              </div>
              <button type="button" data-tab-link="certificates" class="text-xs font-semibold text-slate-400 hover:text-emerald-400 transition-colors flex items-center gap-1">
                View all <i data-lucide="arrow-up-right" class="h-4 w-4"></i>
              </button>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-left text-xs">
                <thead>
                  <tr class="text-slate-400 font-semibold border-b border-white/10">
                    <th class="pb-3 font-semibold">Certificate Type</th>
                    <th class="pb-3 font-semibold">Issued Date</th>
                    <th class="pb-3 font-semibold">Control No.</th>
                    <th class="pb-3 font-semibold min-w-[8rem]">Status</th>
                    <th class="pb-3 text-right font-semibold"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5 font-medium text-slate-300">
                  <?php if (!empty($healthCertificates)): ?>
                    <?php foreach (array_slice($healthCertificates, 0, 4) as $cert): ?>
                      <tr class="hover:bg-white/5 transition-colors">
                        <td class="py-3 flex items-center gap-2 text-white font-semibold">
                          <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                          <?= esc($cert['certificate_type_name'] ?? 'Medical Certificate') ?>
                        </td>
                        <td class="py-3 text-slate-400"><?= esc(date('m/d/Y', strtotime($cert['created_at'] ?? 'now'))) ?></td>
                        <td class="py-3 text-white font-mono font-bold"><?= esc($cert['certificate_number'] ?? ('HC-' . rand(1000, 9999))) ?></td>
                        <td class="py-3 align-middle">
                          <?php
                            $tableStatus = trim((string)($cert['status'] ?? 'Issued'));
                            $tableStatusLower = strtolower($tableStatus);
                            $tableStatusClass = str_contains($tableStatusLower, 'reject')
                              ? 'text-rose-600'
                              : ((str_contains($tableStatusLower, 'approved') || str_contains($tableStatusLower, 'issued') || str_contains($tableStatusLower, 'valid')) ? 'text-emerald-600' : 'text-amber-600');
                          ?>
                          <span class="block max-w-[8.5rem] whitespace-normal text-left text-[11px] font-bold leading-snug <?= $tableStatusClass ?>"><?= esc($tableStatus) ?></span>
                        </td>
                        <td class="py-3 text-right">
                          <a href="ResidentDashboard.php?certificate_document=<?= (int)$cert['id'] ?>" target="_blank" class="text-slate-400 hover:text-emerald-400 p-1"><i data-lucide="download" class="h-4 w-4"></i></a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php elseif (false): ?>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 flex items-center gap-2 text-white font-semibold">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                        Medical Clearance - OPD Consultation
                      </td>
                      <td class="py-3 text-slate-400">02/05/2026</td>
                      <td class="py-3 text-white font-mono font-bold">HC-2026-0842</td>
                      <td class="py-3">
                        <span class="rounded-md bg-emerald-950 px-2 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-800/50">Issued</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="certificates" class="text-slate-400 hover:text-emerald-400 p-1"><i data-lucide="download" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 text-slate-300">Sanitary & Food Handler Clearance</td>
                      <td class="py-3 text-slate-400">05/04/2026</td>
                      <td class="py-3 text-white font-mono font-bold">HC-2026-0711</td>
                      <td class="py-3">
                        <span class="rounded-md bg-amber-950/60 px-2 py-0.5 text-[10px] font-bold text-amber-400 border border-amber-800/40">In Review</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="certificates" class="text-slate-400 hover:text-emerald-400 p-1"><i data-lucide="download" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 text-slate-300">Barbershop & Beauty Salon Health Permit</td>
                      <td class="py-3 text-slate-400">01/03/2026</td>
                      <td class="py-3 text-white font-mono font-bold">HC-2026-0599</td>
                      <td class="py-3">
                        <span class="rounded-md bg-emerald-950 px-2 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-800/50">Signed</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="certificates" class="text-slate-400 hover:text-emerald-400 p-1"><i data-lucide="download" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 text-slate-300">Annual Employment Health Clearance</td>
                      <td class="py-3 text-slate-400">02/02/2026</td>
                      <td class="py-3 text-white font-mono font-bold">HC-2026-0410</td>
                      <td class="py-3">
                        <span class="rounded-md bg-white/5 px-2 py-0.5 text-[10px] font-bold text-slate-400 border border-white/10">Archived</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="certificates" class="text-slate-400 hover:text-emerald-400 p-1"><i data-lucide="download" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                  <?php elseif (empty($healthCertificates)): ?>
                    <tr>
                      <td colspan="5" class="py-6 text-center text-slate-400">No health certificates recorded for this resident.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Right Table: Recent Consultations & Triage -->
          <div class="rounded-3xl border border-white/10 bg-[#131916] p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-white/5 pb-3">
              <h3 class="font-bold text-white text-base">Recent OPD Consultations</h3>
              <button type="button" data-tab-link="records" class="text-xs font-semibold text-slate-400 hover:text-emerald-400 transition-colors flex items-center gap-1">
                View all <i data-lucide="arrow-up-right" class="h-4 w-4"></i>
              </button>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-left text-xs">
                <thead>
                  <tr class="text-slate-400 font-semibold border-b border-white/10">
                    <th class="pb-3 font-semibold">ID</th>
                    <th class="pb-3 font-semibold">Health Concern / Diagnosis</th>
                    <th class="pb-3 font-semibold">Date</th>
                    <th class="pb-3 font-semibold">Physician</th>
                    <th class="pb-3 font-semibold min-w-[6.5rem]">Status</th>
                    <th class="pb-3 text-right font-semibold"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5 font-medium text-slate-300">
                  <?php if (!empty($consultations)): ?>
                    <?php foreach ($consultationsPage as $idx => $c): ?>
                      <?php
                        $recentStatus = trim((string)($c['consultation_status'] ?? ''));
                        if ($recentStatus === '') {
                          $recentStatus = (($c['diagnosis'] ?? '') === 'Pending OPD Triage') ? 'Scheduled' : 'Completed';
                        }
                        $recentStatusClass = str_contains(strtolower($recentStatus), 'complete')
                          ? 'text-emerald-600'
                          : 'text-amber-600';
                        $recentConcern = trim((string)($c['chief_complaint'] ?? ''));
                        $recentDiagnosis = trim((string)($c['diagnosis'] ?? ''));
                        if ($recentDiagnosis === '' || $recentDiagnosis === 'Pending OPD Triage') {
                          $recentDiagnosis = $recentConcern !== '' ? $recentConcern : 'OPD Consultation Request';
                        }
                      ?>
                      <tr class="hover:bg-white/5 transition-colors">
                        <td class="py-3 text-slate-400">CONS-<?= str_pad((string)($c['id'] ?? ($idx + 1)), 4, '0', STR_PAD_LEFT) ?></td>
                        <td class="py-3 text-white font-semibold"><?= esc($recentDiagnosis) ?></td>
                        <td class="py-3 text-slate-400"><?= esc(date('m/d/Y', strtotime($c['consultation_date'] ?? 'now'))) ?></td>
                        <td class="py-3 text-slate-300"><?= esc(($c['physician_name'] ?? '') ?: 'Dr. RHU Physician') ?></td>
                        <td class="py-3 align-middle">
                          <span class="block max-w-[7rem] whitespace-normal text-left text-[11px] font-bold leading-snug <?= $recentStatusClass ?>"><?= esc($recentStatus) ?></span>
                        </td>
                        <td class="py-3 text-right">
                          <button type="button" data-tab-link="records" class="text-slate-500 hover:text-white p-1"><i data-lucide="chevron-right" class="h-4 w-4"></i></button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php elseif (false): ?>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 text-slate-400">CONS-0042</td>
                      <td class="py-3 text-white font-semibold">Acute Upper Respiratory Infection</td>
                      <td class="py-3 text-slate-400">02/05/2026</td>
                      <td class="py-3 text-slate-300">Dr. Nasugbu RHU</td>
                      <td class="py-3">
                        <span class="rounded-md bg-emerald-950 px-2 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-800/40">Completed</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="records" class="text-slate-500 hover:text-white p-1"><i data-lucide="chevron-right" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 text-slate-400">CONS-0039</td>
                      <td class="py-3 text-white font-semibold">Hypertension & Vital Signs Follow-up</td>
                      <td class="py-3 text-slate-400">05/04/2026</td>
                      <td class="py-3 text-slate-300">Dr. Municipal Health</td>
                      <td class="py-3">
                        <span class="rounded-md bg-emerald-950 px-2 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-800/40">Completed</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="records" class="text-slate-500 hover:text-white p-1"><i data-lucide="chevron-right" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 text-slate-400">CONS-0028</td>
                      <td class="py-3 text-white font-semibold">Routine Maternal & Prenatal Checkup</td>
                      <td class="py-3 text-slate-400">01/03/2026</td>
                      <td class="py-3 text-slate-300">Rural Health Midwife</td>
                      <td class="py-3">
                        <span class="rounded-md bg-emerald-950 px-2 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-800/40">Completed</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="records" class="text-slate-500 hover:text-white p-1"><i data-lucide="chevron-right" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                    <tr class="hover:bg-white/5 transition-colors">
                      <td class="py-3 text-slate-400">CONS-0015</td>
                      <td class="py-3 text-white font-semibold">General Physical Exam & Triage</td>
                      <td class="py-3 text-slate-400">02/02/2026</td>
                      <td class="py-3 text-slate-300">Public Health Nurse</td>
                      <td class="py-3">
                        <span class="rounded-md bg-emerald-950 px-2 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-800/40">Completed</span>
                      </td>
                      <td class="py-3 text-right">
                        <button type="button" data-tab-link="records" class="text-slate-500 hover:text-white p-1"><i data-lucide="chevron-right" class="h-4 w-4"></i></button>
                      </td>
                    </tr>
                  <?php elseif (empty($consultations)): ?>
                    <tr>
                      <td colspan="6" class="py-6 text-center text-slate-400">No consultations recorded for this resident.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <!-- Pagination Controls -->
            <?php if ($totalConsultationPages > 1): ?>
              <div class="flex justify-between items-center mt-4 pt-4 border-t border-slate-200">
                <div class="text-sm text-slate-600">
                  Page <span class="font-semibold"><?= $currentPage ?></span> of <span class="font-semibold"><?= $totalConsultationPages ?></span> (<?= '$totalConsultations' ?> total records)
                </div>
                <div class="flex gap-2">
                  <?php if ($currentPage > 1): ?>
                    <a href="ResidentDashboard.php?tab=records&cons_page=<?= $currentPage - 1 ?>" class="px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300 transition-colors text-sm font-medium"> Previous</a>
                  <?php else: ?>
                    <button disabled class="px-4 py-2 bg-slate-100 text-slate-400 rounded-lg text-sm font-medium cursor-not-allowed"> Previous</button>
                  <?php endif; ?>
                  <?php if ($currentPage < $totalConsultationPages): ?>
                    <a href="ResidentDashboard.php?tab=records&cons_page=<?= $currentPage + 1 ?>" class="px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300 transition-colors text-sm font-medium">Next -></a>
                  <?php else: ?>
                    <button disabled class="px-4 py-2 bg-slate-100 text-slate-400 rounded-lg text-sm font-medium cursor-not-allowed">Next -></button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>

        </div>


      </section>

      <!-- 2. PROFILE TAB (Health Profile & Medical Info) -->
      <section data-tab-panel="profile" class="hidden space-y-6">

        <!-- Header Card -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="flex items-center gap-4">
              <div class="h-16 w-16 shrink-0 rounded-2xl bg-gradient-to-r from-teal-600 to-emerald-500 flex items-center justify-center text-white font-extrabold text-xl shadow-md">
                <?= esc($initials) ?>
              </div>
              <div>
                <h2 class="text-xl font-extrabold text-slate-900 tracking-tight">
                  <?= esc(trim(($resident['first_name'] ?? '') . ' ' . ($resident['middle_name'] ?? '') . ' ' . ($resident['last_name'] ?? ''))) ?>
                </h2>
                <p class="text-xs text-slate-500 font-medium mt-0.5">
                  <?= esc($resident['sex'] ?? $resident['gender'] ?? 'Resident') ?> - <?= $age === null ? 'Age N/A' : esc($age) . ' years old' ?> - <?= esc($resident['barangay'] ?? 'Nasugbu') ?>
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                  <span class="inline-flex items-center gap-1 rounded-md bg-teal-50 px-2.5 py-1 font-bold text-teal-700 border border-teal-200/60">
                    <i data-lucide="droplet" class="h-3.5 w-3.5 text-teal-600"></i> Blood Type: <span data-live-profile="blood_type"><?= esc(!empty($resident['blood_type']) ? $resident['blood_type'] : ($healthProfile['blood_type'] ?? 'Unknown')) ?></span>
                  </span>
                  <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-2.5 py-1 font-bold text-sky-700 border border-sky-200/60">
                    <i data-lucide="credit-card" class="h-3.5 w-3.5 text-sky-600"></i> PhilHealth #: <span data-live-profile="philhealth_number"><?= esc(!empty($resident['philhealth_number']) ? $resident['philhealth_number'] : (!empty($resident['philhealth_id']) ? $resident['philhealth_id'] : ($healthProfile['philhealth_number'] ?? 'Not Recorded'))) ?></span>
                  </span>
                  <?php if (!empty($healthProfile['bmi'])): ?>
                    <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2.5 py-1 font-bold text-emerald-700 border border-emerald-200/60">
                      <i data-lucide="activity" class="h-3.5 w-3.5 text-emerald-600"></i> BMI: <?= esc($healthProfile['bmi']) ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <button type="button" onclick="document.getElementById('edit-health-profile-modal')?.classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-teal-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-teal-700 transition-all cursor-pointer">
              <i data-lucide="edit-3" class="h-4 w-4"></i> Edit Health Profile
            </button>
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
          <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div><h3 class="text-base font-extrabold text-slate-900">My Resident ID</h3><p class="mt-1 text-xs text-slate-500">Present your QR code when you visit the RHU.</p></div>
            <a href="ResidentID.php" class="inline-flex items-center gap-2 rounded-xl border border-teal-200 bg-teal-50 px-4 py-2.5 text-xs font-bold text-teal-800 hover:bg-teal-100"><i data-lucide="printer" class="h-4 w-4"></i> View / Print ID</a>
          </div>
          <link rel="stylesheet" href="styles/resident-id-card.css?v=3">
          <?php require_once __DIR__ . '/includes/resident-id-card.php'; residentIdCard($resident); ?>
        </div>

        <!-- Detailed Information Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

          <!-- Card 1: Personal & Demographic Info -->
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
              <div class="flex items-center gap-2">
                <div class="h-8 w-8 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center">
                  <i data-lucide="user" class="h-4 w-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-800">Personal & Contact Details</h3>
              </div>
              <button type="button" onclick="document.getElementById('edit-personal-profile-modal')?.classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-700 hover:bg-slate-100 transition-colors">
                <i data-lucide="pencil" class="h-3.5 w-3.5"></i> Edit
              </button>
            </div>
            <div class="grid grid-cols-2 gap-4 text-xs">
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Full Name</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')) ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Date of Birth</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc($resident['date_of_birth'] ?? 'Not specified') ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Sex / Civil Status</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc($resident['sex'] ?? $resident['gender'] ?? '-') ?> / <?= esc($resident['civil_status'] ?? '-') ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Contact Number</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc($resident['contact_number'] ?? 'Not specified') ?></p>
              </div>
              <div class="col-span-2">
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Email Address</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc($resident['email'] ?? 'Not specified') ?></p>
              </div>
              <div class="col-span-2">
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Barangay & Address</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc($resident['barangay'] ?? 'Nasugbu') ?>, <?= esc($resident['address'] ?? '') ?></p>
              </div>
            </div>
          </div>

          <!-- Card 2: Physical Vitals & Measurements -->
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
              <div class="flex items-center gap-2">
                <div class="h-8 w-8 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center">
                  <i data-lucide="heart-pulse" class="h-4 w-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-800">Vitals & Physical Attributes</h3>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4 text-xs">
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Height (cm)</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="height"><?= !empty($healthProfile['height']) ? esc($healthProfile['height']) . ' cm' : 'Not recorded' ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Weight (kg)</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="weight"><?= !empty($healthProfile['weight']) ? esc($healthProfile['weight']) . ' kg' : 'Not recorded' ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Blood Pressure</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="blood_pressure"><?= esc(!empty($healthProfile['blood_pressure']) ? $healthProfile['blood_pressure'] : (!empty($healthProfile['bp']) ? $healthProfile['bp'] : 'Not recorded')) ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Heart Rate (bpm)</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="heart_rate"><?= !empty($healthProfile['heart_rate']) ? esc($healthProfile['heart_rate']) . ' bpm' : 'Not recorded' ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Temperature (deg C)</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="temperature"><?= !empty($healthProfile['temperature']) ? esc($healthProfile['temperature']) . ' deg C' : 'Not recorded' ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Last Checkup Date</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="last_checkup_date"><?= esc(($healthProfile['last_checkup_date'] ?? '') ?: 'Not recorded') ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Smoking Status</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="smoking_status"><?= esc(($healthProfile['smoking_status'] ?? '') ?: 'Not recorded') ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Alcohol Consumption</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="alcohol_consumption"><?= esc(($healthProfile['alcohol_consumption'] ?? '') ?: 'Not recorded') ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Exercise Frequency</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="exercise_frequency"><?= esc(($healthProfile['exercise_frequency'] ?? '') ?: 'Not recorded') ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Diet Type</p>
                <p class="font-bold text-slate-800 mt-0.5" data-live-profile="diet_type"><?= esc(($healthProfile['diet_type'] ?? '') ?: 'Not recorded') ?></p>
              </div>
            </div>
          </div>

          <!-- Card 3: Medical History & Clinical Notes -->
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
              <div class="flex items-center gap-2">
                <div class="h-8 w-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
                  <i data-lucide="shield-alert" class="h-4 w-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-800">Medical Conditions & History</h3>
              </div>
            </div>
            <div class="space-y-3 text-xs">
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Known Allergies</p>
                <p class="font-medium text-slate-800 mt-0.5 bg-slate-50 p-3 rounded-xl border border-slate-100">
                  <span data-live-profile="allergies"><?= esc(!empty($healthProfile['allergies']) ? $healthProfile['allergies'] : (!empty($resident['allergies']) ? $resident['allergies'] : 'No known allergies recorded.')) ?></span>
                </p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Chronic Conditions / Illnesses</p>
                <p class="font-medium text-slate-800 mt-0.5 bg-slate-50 p-3 rounded-xl border border-slate-100">
                  <span data-live-profile="chronic_conditions"><?= esc(!empty($healthProfile['chronic_conditions']) ? $healthProfile['chronic_conditions'] : (!empty($healthProfile['medical_conditions']) ? $healthProfile['medical_conditions'] : (!empty($resident['medical_conditions']) ? $resident['medical_conditions'] : 'No pre-existing chronic conditions recorded.'))) ?></span>
                </p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Current Prescribed Medications</p>
                <p class="font-medium text-slate-800 mt-0.5 bg-slate-50 p-3 rounded-xl border border-slate-100">
                  <?= esc(!empty($healthProfile['current_medications']) ? $healthProfile['current_medications'] : (!empty($healthProfile['medications']) ? $healthProfile['medications'] : 'None currently listed.')) ?>
                </p>
              </div>
            </div>
          </div>

          <!-- Card 4: Emergency Contacts & PhilHealth -->
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
              <div class="flex items-center gap-2">
                <div class="h-8 w-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                  <i data-lucide="phone-call" class="h-4 w-4"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-800">Emergency Contacts & PhilHealth</h3>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4 text-xs">
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Emergency Contact Person</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc(!empty($healthProfile['emergency_contact_name']) ? $healthProfile['emergency_contact_name'] : (!empty($resident['emergency_contact_name']) ? $resident['emergency_contact_name'] : 'Not set')) ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Relationship</p>
                <p class="font-bold text-slate-800 mt-0.5"><?= esc(!empty($healthProfile['emergency_contact_relationship']) ? $healthProfile['emergency_contact_relationship'] : (!empty($resident['emergency_contact_relationship']) ? $resident['emergency_contact_relationship'] : 'Family Member')) ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">Emergency Phone Number</p>
                <p class="font-bold mt-0.5 text-teal-700"><?= esc(!empty($healthProfile['emergency_contact_phone']) ? $healthProfile['emergency_contact_phone'] : (!empty($resident['emergency_contact_phone']) ? $resident['emergency_contact_phone'] : 'Not set')) ?></p>
              </div>
              <div>
                <p class="text-slate-400 font-semibold uppercase text-[10px]">PhilHealth Number</p>
                <p class="font-bold mt-0.5 font-mono text-emerald-700"><?= esc(!empty($healthProfile['philhealth_number']) ? $healthProfile['philhealth_number'] : (!empty($resident['philhealth_id']) ? $resident['philhealth_id'] : 'Not set')) ?></p>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- 3. HEALTH RECORDS TAB -->
      <section data-tab-panel="records" class="hidden space-y-6">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-bold text-slate-900">Health Records & Consultations</h3>
          <button type="button" data-appointment-open class="rounded-xl bg-teal-600 px-4 py-2 text-xs font-bold text-white hover:bg-teal-700 transition-all flex items-center gap-2" aria-haspopup="dialog" aria-controls="appointment-modal">
            <i data-lucide="plus" class="h-4 w-4"></i> Request OPD Appointment
          </button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 sm:gap-4">
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs">
            <p class="text-xs font-bold text-slate-400 uppercase">Total Visits</p>
            <p id="records-total-visits" class="text-2xl font-black text-slate-800 mt-1"><?= count($consultations) ?></p>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs">
            <p class="text-xs font-bold text-slate-400 uppercase">Visits This Year (<?= $currentYear ?>)</p>
            <p id="records-visits-this-year" class="text-2xl font-black text-teal-600 mt-1"><?= $visitsThisYear ?></p>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs">
            <p class="text-xs font-bold text-slate-400 uppercase">Prescriptions Issued</p>
            <p id="records-total-prescriptions" class="text-2xl font-black text-indigo-600 mt-1"><?= $totalPrescriptions ?></p>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs">
            <p class="text-xs font-bold text-slate-400 uppercase">Dental Records</p>
            <p class="text-2xl font-black text-cyan-700 mt-1"><?= count($dentalRecords) ?></p>
          </div>
        </div>

        <?php if (!empty($confidentialLabRecords)): ?>
          <div class="rounded-2xl border border-rose-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-2 border-b border-rose-100 pb-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h4 class="text-sm font-black text-slate-950">Private Confidential Testing</h4>
                <p class="mt-0.5 text-xs font-semibold text-slate-500">Visible only in your resident account and to authorized confidential testing personnel.</p>
              </div>
              <span class="w-fit rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-[11px] font-black text-rose-800">Private</span>
            </div>
            <div class="mt-4 space-y-3">
              <?php foreach ($confidentialLabRecords as $privateRecord): ?>
                <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                      <p class="text-sm font-black text-slate-900"><?= esc($privateRecord['test_type'] ?? 'Confidential Test') ?></p>
                      <p class="mt-1 text-xs font-bold text-rose-800">Status: <?= esc($privateRecord['result_status'] ?? 'Pending') ?></p>
                    </div>
                    <span class="w-fit rounded-full bg-white px-3 py-1 text-[11px] font-black text-slate-600">Specimen: <?= esc($privateRecord['specimen_date'] ?? 'N/A') ?></span>
                  </div>
                  <div class="mt-3 grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                    <div class="rounded-xl bg-white p-3">
                      <p class="font-black uppercase tracking-wide text-slate-400">Private Result</p>
                      <p class="mt-1 font-bold text-slate-900"><?= esc($privateRecord['result_text'] ?: 'For private release/counseling') ?></p>
                    </div>
                    <div class="rounded-xl bg-white p-3">
                      <p class="font-black uppercase tracking-wide text-slate-400">Counseling Notes</p>
                      <p class="mt-1 whitespace-pre-line font-semibold text-slate-900"><?= esc($privateRecord['confidential_notes'] ?: 'No notes released') ?></p>
                    </div>
                  </div>
                  <p class="mt-2 text-[11px] font-semibold text-slate-500">Assigned personnel: <?= esc($privateRecord['counselor_name'] ?? 'RHU personnel') ?> · Release date: <?= esc($privateRecord['release_date'] ?? 'Not set') ?></p>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="space-y-4">
          <div class="flex items-center justify-between border-b border-cyan-100 pb-2">
            <h4 class="text-sm font-black text-slate-900">Dental E-Prescriptions &amp; Mouth Examinations</h4>
            <span class="rounded-full bg-cyan-50 px-3 py-1 text-xs font-black text-cyan-800"><?= count($residentDentalDashboardRecords) ?> records</span>
          </div>
          <?php if (!$residentDentalDashboardRecords): ?>
            <div class="rounded-2xl border border-dashed border-cyan-200 bg-white p-6 text-center text-slate-400">
              <i data-lucide="clipboard-list" class="mx-auto mb-2 h-9 w-9 text-cyan-200"></i>
              <p class="text-sm font-semibold">No dental e-prescriptions or mouth examinations recorded yet</p>
            </div>
          <?php else: ?>
            <p id="resident-dental-scroll-hint" class="text-xs font-semibold text-slate-500">Scroll to view your dental examination sheets and prescriptions.</p>
            <div class="resident-dental-sheets" role="region" aria-label="Dental examination sheets and prescriptions" aria-describedby="resident-dental-scroll-hint" tabindex="0">
              <?php foreach ($residentDentalDashboardRecords as $dentalRecord): ?>
                <div class="history-entry"><?= staffRecordSheet($dentalRecord, 'dental_records', 'Dental records') ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php $activeConsultations = array_values(array_filter($consultations, static fn(array $record): bool => !in_array(strtolower(trim((string)($record['consultation_status'] ?? ''))), ['completed', 'complete'], true))); ?>
        <div class="space-y-4">
          <?php if (!$consultations): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-400">
              <i data-lucide="folder-open" class="mx-auto mb-2 h-10 w-10 text-slate-300"></i>
              <p class="text-sm font-semibold">No consultations recorded yet</p>
            </div>
          <?php else:
            $itemsPerPage = 4;
            $totalConsultationRecords = count($activeConsultations);
            $totalConsultationPages = max(1, (int)ceil($totalConsultationRecords / $itemsPerPage));
          ?>
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500 border-b border-slate-100 pb-2">
              <span>Showing 4 records per page</span>
              <span>Total Records: <strong id="records-total-count" class="text-slate-800 font-bold"><?= $totalConsultationRecords ?></strong></span>
            </div>

            <div class="max-h-[34rem] space-y-3 overflow-y-auto overscroll-contain pr-1 sm:max-h-[38rem] sm:pr-2" id="records-list-container">
              <?php if (!$activeConsultations): ?><p class="rounded-2xl bg-white p-6 text-sm text-slate-500">No active appointments. Completed visits are available in Health Record History.</p><?php endif; ?>
              <?php foreach ($activeConsultations as $idx => $consultation):
                $pageNum = (int)floor($idx / $itemsPerPage) + 1;
                $hiddenClass = $pageNum > 1 ? 'hidden' : '';
                $rawSt = strtolower($consultation['consultation_status'] ?? 'scheduled');
                $icd10Code = trim((string)($consultation['icd10'] ?? ''));
                $stClass = match (true) {
                  str_contains($rawSt, 'completed') => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                  str_contains($rawSt, 'progress') => 'bg-blue-100 text-blue-800 border-blue-300',
                  str_contains($rawSt, 'referred') => 'bg-purple-100 text-purple-800 border-purple-300',
                  str_contains($rawSt, 'cancel') => 'bg-rose-100 text-rose-800 border-rose-300',
                  str_contains($rawSt, 'expired') => 'bg-rose-100 text-rose-800 border-rose-300',
                  default => 'bg-amber-100 text-amber-800 border-amber-300'
                };
                $stLabel = ucfirst($consultation['consultation_status'] ?? 'Scheduled');
              ?>
                <article data-pagination-item="records" data-page="<?= $pageNum ?>" class="<?= $hiddenClass ?> rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs transition-all hover:border-slate-300 space-y-3">
                  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 border-b border-slate-100 pb-3">
                    <div>
                      <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-teal-600"><?= esc($consultation['consultation_time'] ?? 'OPD') ?></span>
                        <span class="rounded-full px-2.5 py-0.5 text-[10px] font-extrabold border <?= $stClass ?>">Status: <?= esc($stLabel) ?></span>
                        <?php if ($icd10Code !== ''): ?>
                          <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 font-mono text-[10px] font-extrabold text-slate-700">ICD-10: <?= esc($icd10Code) ?></span>
                        <?php endif; ?>
                      </div>
                      <h4 class="font-bold text-slate-900 text-base mt-1"><?= esc($consultation['diagnosis'] ?? 'Consultation') ?></h4>
                      <p class="text-xs text-slate-500 font-medium">Attending Provider: <?= esc($consultation['physician_name'] ?: 'RHU Healthcare Staff') ?></p>
                    </div>
                    <span class="rounded-lg bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"> <?= esc($consultation['consultation_date'] ?? '-') ?></span>
                  </div>
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                      <p class="font-bold text-slate-400">Chief Complaint:</p>
                      <p class="text-slate-700 font-medium"><?= esc($consultation['chief_complaint'] ?? 'None specified') ?></p>
                    </div>
                    <div>
                      <p class="font-bold text-slate-400">Prescribed Medications:</p>
                      <p class="text-slate-700 font-medium"><?= esc($consultation['medications_prescribed'] ?: 'None recorded yet') ?></p>
                    </div>
                    <div>
                      <p class="font-bold text-slate-400">ICD-10 Code:</p>
                      <p class="font-mono text-slate-700 font-bold"><?= esc($icd10Code !== '' ? $icd10Code : 'None recorded yet') ?></p>
                    </div>
                  </div>
                  <?php if (!empty($consultation['consultation_notes']) || !empty($consultation['treatment_plan'])): ?>
                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-200/60 text-xs space-y-1">
                      <p class="font-bold text-teal-800 uppercase text-[10px]"> Healthcare Staff Response &amp; Clinical Notes:</p>
                      <p class="text-slate-800 font-medium whitespace-pre-line"><?= esc(!empty($consultation['consultation_notes']) ? $consultation['consultation_notes'] : $consultation['treatment_plan']); ?></p>
                    </div>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>

            <!-- Pagination Bar for Records -->
            <?php if ($totalConsultationPages > 1): ?>
              <div id="records-pagination-bar" class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm text-xs font-semibold text-slate-600">
                <span class="text-slate-500 font-medium">Page <strong class="text-slate-900 font-bold" id="records-current-page">1</strong> of <strong class="text-slate-900 font-bold"><?= $totalConsultationPages ?></strong> (<?= $totalConsultationRecords ?> total records)</span>

                <div class="flex items-center gap-1.5">
                  <button type="button" id="records-prev-btn" onclick="changeDashboardPage('records', -1, <?= $totalConsultationPages ?>)" disabled class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-slate-700 hover:bg-teal-50 hover:border-teal-300 disabled:opacity-40 disabled:cursor-not-allowed transition-all font-bold">
                    <i data-lucide="chevron-left" class="h-4 w-4"></i> Previous
                  </button>

                  <div class="flex items-center gap-1" id="records-page-numbers">
                    <?php for ($p = 1; $p <= $totalConsultationPages; $p++): ?>
                      <button type="button" onclick="goToDashboardPage('records', <?= $p ?>, <?= $totalConsultationPages ?>)" data-page-btn="records-<?= $p ?>" class="<?= $p === 1  ?>">
                        <?= $p ?>
                      </button>
                    <?php endfor; ?>
                  </div>

                  <button type="button" id="records-next-btn" onclick="changeDashboardPage('records', 1, <?= $totalConsultationPages ?>)" class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-slate-700 hover:bg-teal-50 hover:border-teal-300 disabled:opacity-40 disabled:cursor-not-allowed transition-all font-bold">
                    Next <i data-lucide="chevron-right" class="h-4 w-4"></i>
                  </button>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php
          $residentGeneralRecords = [];
          foreach ([
            ['records' => $consultations, 'label' => 'Consultation'],
            ['records' => $laboratoryRecords, 'label' => 'Laboratory'],
            ['records' => $dentalRecords, 'label' => 'Dental'],
            ['records' => $vaccinationRecords, 'label' => 'Vaccination'],
            ['records' => $familyPlanningRecords, 'label' => 'Family planning'],
            ['records' => $pregnancyRecords, 'label' => 'Prenatal'],
            ['records' => $birthRecords, 'label' => 'Birth'],
          ] as $recordGroup) {
            foreach ($recordGroup['records'] as $record) {
              $record['_category'] = $recordGroup['label'];
              // History follows when the entry was recorded, not its appointment date.
              $record['_date'] = !empty($record['created_at']) ? $record['created_at'] : '';
              $residentGeneralRecords[] = $record;
            }
          }
          // Compare the full recording timestamp, including time of day.
          usort($residentGeneralRecords, static function (array $a, array $b): int {
            $dateOrder = strcmp((string)$b['_date'], (string)$a['_date']);
            if ($dateOrder !== 0) return $dateOrder;
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
          });
        ?>
        <div class="space-y-4">
          <div class="flex items-center justify-between border-b border-slate-100 pb-2">
            <h4 class="text-sm font-black text-slate-900">Health Record History</h4>
            <span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-black text-teal-800"><?= count($residentGeneralRecords) ?> records</span>
          </div>
          <?php if (!$residentGeneralRecords): ?>
            <p class="rounded-2xl bg-white p-6 text-sm text-slate-500">No health records recorded yet.</p>
          <?php else: ?>
            <p id="resident-clinic-scroll-hint" class="text-xs font-semibold text-slate-500">Latest records first. Scroll to view older health records.</p>
            <div class="resident-general-records" role="region" aria-label="Health record history" aria-describedby="resident-clinic-scroll-hint" tabindex="0">
              <?php foreach ($residentGeneralRecords as $record):
                $recordType = ucwords(str_replace('_', ' ', (string)($record['record_type'] ?? $record['_category'])));
                $recordTitle = trim((string)($record['title'] ?? $record['chief_complaint'] ?? $record['vaccine_name'] ?? '')) ?: $recordType;
                $recordFindings = (string)($record['findings'] ?? $record['diagnosis'] ?? '');
                if ($record['_category'] === 'Dental' && ($record['record_type'] ?? '') === 'examination') {
                  $recordFindings = dentalRecordViewData($record)['findings'];
                }
                $recordMeta = is_array($record['meta'] ?? null) ? $record['meta'] : (json_decode((string)($record['meta_json'] ?? ''), true) ?: []);
              ?>
                <article class="resident-general-card">
                  <header><span class="resident-record-category"><?= esc($record['_category']) ?> / <?= esc($recordType) ?></span><span class="resident-record-status"><?= esc($record['status'] ?? $record['consultation_status'] ?? 'Recorded') ?></span></header>
                  <h5><?= esc($recordTitle) ?></h5>
                  <div class="resident-record-meta"><span>Recorded: <?= esc((string)$record['_date'] ?: 'Date not recorded') ?></span><span>Provider: <?= esc($record['provider_name'] ?? 'RHU Healthcare Staff') ?></span></div>
                  <dl>
                    <?php foreach ([
                      'Findings / diagnosis' => $recordFindings,
                      'Notes / instructions' => $record['notes'] ?? $record['consultation_notes'] ?? $record['treatment_plan'] ?? '',
                      'Tooth numbers' => $record['tooth_numbers'] ?? '',
                      'Dosage' => $recordMeta['dosage'] ?? '',
                      'Frequency' => $recordMeta['frequency'] ?? '',
                      'Duration' => $recordMeta['duration'] ?? '',
                      'Quantity' => $recordMeta['quantity'] ?? '',
                    ] as $caption => $value): if (trim((string)$value) === '') continue; ?>
                      <div><dt><?= esc($caption) ?></dt><dd><?= esc($value) ?></dd></div>
                    <?php endforeach; ?>
                  </dl>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($pregnantDependentSuggestions)): ?>
          <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
            <div class="flex items-start gap-3">
              <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                <i data-lucide="shield-alert" class="h-5 w-5"></i>
              </div>
              <div class="flex-1">
                <p class="text-sm font-black text-amber-900">Separate account recommended</p>
                <p class="mt-1 text-xs font-medium text-amber-800">A pregnant dependent is linked to your household profile. Creating a separate resident account helps protect the sensitive health information of both the dependent and the family.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <?php foreach ($pregnantDependentSuggestions as $suggestion): ?>
                    <?php $suggestionUrl = 'ResidentRegistration.php?' . http_build_query([
                      'first_name' => $suggestion['first_name'],
                      'last_name' => $suggestion['last_name'],
                      'dob' => $suggestion['dob'],
                      'dependent_pregnant' => 1,
                    ]); ?>
                    <a href="<?= esc($suggestionUrl) ?>" class="inline-flex items-center gap-2 rounded-xl bg-amber-600 px-3 py-2 text-[11px] font-black text-white shadow-sm transition hover:bg-amber-700">
                      <i data-lucide="user-plus" class="h-3.5 w-3.5"></i>
                      Create account for <?= esc($suggestion['name']) ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($dependents): ?>
          <div class="space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2">
              <div>
                <h3 class="flex items-center gap-2 text-base font-black text-slate-900"><i data-lucide="folders" class="h-5 w-5 text-sky-600"></i> Dependent Health Record Folders</h3>
                <p class="mt-1 text-xs font-medium text-slate-500">Each folder contains records for a household dependent.</p>
              </div>
              <span class="rounded-full bg-sky-100 px-3 py-1 text-[10px] font-black text-sky-800"><?= count($dependents) ?> <?= count($dependents) === 1 ? 'Folder' : 'Folders' ?></span>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
              <?php foreach ($dependents as $dependent):
                $folder = $dependent['record_folder'] ?? ['consultations' => [], 'certificates' => [], 'vaccinations' => [], 'pregnancies' => []];
                $dependentFolderName = trim(($dependent['first_name'] ?? '') . ' ' . ($dependent['middle_name'] ?? '') . ' ' . ($dependent['last_name'] ?? ''));
                $folderTotal = count($folder['consultations'] ?? []) + count($folder['certificates'] ?? []) + count($folder['vaccinations'] ?? []) + count($folder['pregnancies'] ?? []);
                $folderPayload = [
                  'name' => $dependentFolderName,
                  'first_name' => $dependent['first_name'] ?? '',
                  'last_name' => $dependent['last_name'] ?? '',
                  'relationship' => $dependent['relationship'] ?? 'Family Member',
                  'dob' => $dependent['date_of_birth'] ?? 'N/A',
                  'gender' => $dependent['gender'] ?? ($dependent['sex'] ?? 'Not specified'),
                  'blood_type' => $dependent['blood_type'] ?? 'Unknown / N/A',
                  'notes' => $dependent['medical_notes'] ?? 'No known allergies or medical conditions recorded.',
                  'dep_res_id' => $dependent['dependent_resident_id'] ?? null,
                  'record_folder' => $folder,
                ];
                $folderPayloadAttr = htmlspecialchars(json_encode($folderPayload), ENT_QUOTES, 'UTF-8');
              ?>
                <article onclick="window.openDependentConsultationFolderFromElement(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.openDependentConsultationFolderFromElement(this)}" data-dependent-payload="<?= $folderPayloadAttr ?>" tabindex="0" role="button" class="cursor-pointer rounded-2xl border border-sky-100 bg-white p-5 shadow-sm transition hover:border-sky-300 hover:shadow-md">
                  <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
                    <div class="flex min-w-0 items-center gap-3">
                      <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sm font-black text-sky-700"><i data-lucide="folder" class="h-5 w-5"></i></div>
                      <div class="min-w-0">
                        <h4 class="truncate text-sm font-black text-slate-900"><?= esc(rhuPatientLabel($dependentFolderName, $dependent['dependent_resident_id'] ?? 0)) ?></h4>
                        <p class="text-[10px] font-semibold text-slate-500"><?= esc($dependent['relationship'] ?? 'Family Member') ?> · <?= $folderTotal ?> record<?= $folderTotal === 1 ? '' : 's' ?></p>
                      </div>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">Resident</span>
                  </div>
                  <div class="mt-4 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase text-slate-400">Visits</p><p class="mt-1 text-lg font-black text-slate-800"><?= count($folder['consultations'] ?? []) ?></p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase text-slate-400">Certificates</p><p class="mt-1 text-lg font-black text-slate-800"><?= count($folder['certificates'] ?? []) ?></p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase text-slate-400">Vaccines</p><p class="mt-1 text-lg font-black text-slate-800"><?= count($folder['vaccinations'] ?? []) ?></p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase text-slate-400">Pregnancy</p><p class="mt-1 text-lg font-black text-slate-800"><?= count($folder['pregnancies'] ?? []) ?></p></div>
                  </div>
                  <div class="mt-4 space-y-2">
                    <?php if ($folderTotal === 0): ?>
                      <p class="rounded-xl border border-dashed border-slate-200 p-3 text-[11px] font-medium text-slate-500">No records have been recorded for this dependent yet.</p>
                    <?php else: ?>
                      <?php foreach (array_slice($folder['consultations'] ?? [], 0, 2) as $record): ?>
                        <div class="rounded-xl border border-teal-100 bg-teal-50/50 p-3 text-xs"><p class="font-bold text-slate-800">Consultation: <?= esc($record['diagnosis'] ?? 'Pending OPD Triage') ?></p><p class="mt-1 text-[10px] text-slate-600"><?= esc($record['consultation_status'] ?? 'Scheduled') ?> · <?= esc($record['consultation_date'] ?? '-') ?></p><p class="mt-1 text-[11px] text-slate-700"><?= esc($record['consultation_notes'] ?? $record['treatment_plan'] ?? 'No clinical update yet.') ?></p></div>
                      <?php endforeach; ?>
                      <?php foreach (array_slice($folder['certificates'] ?? [], 0, 1) as $record): ?>
                        <div class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-3 text-xs"><p class="font-bold text-slate-800">Certificate: <?= esc($record['certificate_type'] ?? 'Health Certificate') ?></p><p class="mt-1 text-[10px] text-slate-600"><?= esc($record['status'] ?? 'Pending') ?> · <?= esc($record['certificate_number'] ?? '') ?></p></div>
                      <?php endforeach; ?>
                      <?php foreach ($folder['vaccinations'] ?? [] as $record): ?>
                        <?php $dependentVaccinationPayload = htmlspecialchars(json_encode(['resident_name' => $dependentFolderName, 'vaccine_name' => $record['vaccine_name'] ?? 'Vaccine', 'provider_name' => $record['provider_name'] ?? 'RHU Staff', 'dose_number' => $record['dose_number'] ?? '1', 'vaccination_date' => $record['vaccination_date'] ?? '', 'next_dose_date' => $record['next_dose_date'] ?? '', 'batch_number' => $record['batch_number'] ?? '', 'site_of_injection' => $record['site_of_injection'] ?? '', 'adverse_reactions' => $record['adverse_reactions'] ?? '', 'remarks' => $record['remarks'] ?? '']), ENT_QUOTES, 'UTF-8'); ?>
                        <div onclick="event.stopPropagation(); openVaccinationRecordFromElement(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();event.stopPropagation();openVaccinationRecordFromElement(this)}" data-vaccination-payload="<?= $dependentVaccinationPayload ?>" tabindex="0" role="button" class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-3 text-xs transition hover:border-emerald-300"><p class="font-bold text-slate-800">Vaccination: <?= esc($record['vaccine_name'] ?? 'Vaccine') ?></p><p class="mt-1 text-[10px] text-slate-600">Dose <?= esc($record['dose_number'] ?? '1') ?> · <?= esc($record['vaccination_date'] ?? '-') ?> · Click for details</p></div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($isFemaleResident && ($pregnancyRecords || $familyPlanningRecords || $maternalReferrals || $birthRecords)): ?>
          <div class="rounded-2xl border border-pink-200 bg-white p-5 shadow-sm">
            <h3 class="mb-4 flex items-center gap-2 font-black text-slate-900"><i data-lucide="heart-handshake" class="h-5 w-5 text-pink-600"></i> Maternal &amp; Midwife Service Records</h3>
            <div class="grid gap-3 md:grid-cols-2">
              <?php foreach ($pregnancyRecords as $record): ?>
                <?php
                $isHighRiskPregnancy = !empty($record['high_risk']);
                $pregnancyStatus = trim((string)($record['pregnancy_status'] ?? 'Active')) ?: 'Active';
                ?>
                <article class="rounded-xl border <?= $isHighRiskPregnancy ? 'border-rose-200 bg-rose-50/60' : 'border-pink-100 bg-pink-50/50' ?> p-4 text-xs space-y-3">
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p class="font-black <?= $isHighRiskPregnancy ? 'text-rose-900' : 'text-pink-900' ?>">Prenatal Maternal Case</p>
                      <p class="mt-1 text-slate-600 font-semibold">Status: <?= esc($pregnancyStatus) ?></p>
                    </div>
                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-black <?= $isHighRiskPregnancy ? 'border-rose-200 bg-rose-100 text-rose-800' : 'border-emerald-200 bg-emerald-100 text-emerald-800' ?>">
                      <?= $isHighRiskPregnancy ? 'HIGH RISK' : 'LOW RISK' ?>
                    </span>
                  </div>
                  <div class="grid grid-cols-2 gap-2 text-slate-700 sm:grid-cols-4">
                    <div class="rounded-lg bg-white/80 p-2">
                      <p class="font-bold text-slate-400">Gravida</p>
                      <p class="font-black text-slate-900"><?= (int)($record['gravida'] ?? 1) ?></p>
                    </div>
                    <div class="rounded-lg bg-white/80 p-2">
                      <p class="font-bold text-slate-400">Para</p>
                      <p class="font-black text-slate-900"><?= (int)($record['para'] ?? 0) ?></p>
                    </div>
                    <div class="rounded-lg bg-white/80 p-2">
                      <p class="font-bold text-slate-400">LMP</p>
                      <p class="font-black text-slate-900"><?= esc($record['last_menstrual_period'] ?? '-') ?></p>
                    </div>
                    <div class="rounded-lg bg-white/80 p-2">
                      <p class="font-bold text-slate-400">Expected EDC</p>
                      <p class="font-black text-slate-900"><?= esc($record['expected_delivery_date'] ?? '-') ?></p>
                    </div>
                  </div>
                  <div class="rounded-lg border border-white/80 bg-white/80 p-3">
                    <p class="font-black uppercase tracking-wide text-[10px] <?= $isHighRiskPregnancy ? 'text-rose-700' : 'text-teal-700' ?>">Risk Factors &amp; Clinical Health Plan</p>
                    <p class="mt-1 whitespace-pre-line font-medium text-slate-700"><?= esc(($record['risk_factors'] ?? $record['remarks'] ?? '') ?: 'Routine monitoring') ?></p>
                  </div>
                </article>
              <?php endforeach; ?>
              <?php foreach ($familyPlanningRecords as $record): ?>
                <article class="rounded-xl border border-rose-100 bg-rose-50/50 p-4 text-xs">
                  <p class="font-black text-rose-900">Family Planning - <?= esc($record['contraceptive_method']) ?></p>
                  <p class="mt-1 text-slate-600"><?= esc($record['acceptor_type']) ?> - Next visit: <?= esc($record['next_visit_date'] ?: 'To be scheduled') ?></p>
                </article>
              <?php endforeach; ?>
              <?php foreach ($maternalReferrals as $record): ?>
                <article class="rounded-xl border border-purple-100 bg-purple-50/50 p-4 text-xs">
                  <p class="font-black text-purple-900">Maternal Referral - <?= esc($record['referral_status']) ?></p>
                  <p class="mt-1 text-slate-600">To: <?= esc($record['referred_to']) ?> - <?= esc($record['urgency']) ?></p>
                  <p class="mt-1 text-slate-500"><?= esc($record['referral_reason']) ?></p>
                </article>
              <?php endforeach; ?>
              <?php foreach ($birthRecords as $record): ?>
                <article class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4 text-xs">
                  <p class="font-black text-emerald-900">Birth Record - <?= esc($record['child_name']) ?></p>
                  <p class="mt-1 text-slate-600">Born <?= esc($record['date_of_birth']) ?> - Certificate: <?= esc($record['birth_certificate_number']) ?></p>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <!-- 3. IMMUNIZATION TAB -->
      <section data-tab-panel="immunization" class="hidden space-y-6">
        <h3 class="text-lg font-bold text-slate-900">Immunization History</h3>
        <div class="max-h-[70vh] space-y-3 overflow-y-auto overscroll-contain pr-2 sm:max-h-[38rem]" role="region" aria-label="Immunization history records" tabindex="0">
          <?php if (!$vaccinationRecords): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-400">
              <i data-lucide="syringe" class="mx-auto mb-2 h-10 w-10 text-slate-300"></i>
              <p class="text-sm font-semibold">No vaccination records found</p>
            </div>
            <?php else: foreach ($vaccinationRecords as $record):
              $vaccinationPayload = htmlspecialchars(json_encode([
                'resident_name' => $record['resident_name'] ?? 'Resident',
                'vaccine_name' => $record['vaccine_name'] ?? 'Vaccine',
                'provider_name' => $record['provider_name'] ?? 'RHU Staff',
                'dose_number' => $record['dose_number'] ?? '1',
                'vaccination_date' => $record['vaccination_date'] ?? '',
                'next_dose_date' => $record['next_dose_date'] ?? '',
                'batch_number' => $record['batch_number'] ?? '',
                'site_of_injection' => $record['site_of_injection'] ?? '',
                'adverse_reactions' => $record['adverse_reactions'] ?? '',
                'remarks' => $record['remarks'] ?? '',
              ]), ENT_QUOTES, 'UTF-8');
            ?>
              <article onclick="openVaccinationRecordFromElement(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openVaccinationRecordFromElement(this)}" data-vaccination-payload="<?= $vaccinationPayload ?>" tabindex="0" role="button" class="flex cursor-pointer flex-col sm:flex-row items-start sm:items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs transition hover:border-indigo-300 hover:shadow-md">
                <div class="space-y-1">
                  <div class="flex items-center gap-2">
                    <span class="rounded-md bg-indigo-50 p-1.5 text-indigo-600"><i data-lucide="shield-check" class="h-4 w-4"></i></span>
                    <p class="text-base font-bold text-slate-900"><?= esc($record['vaccine_name']) ?></p>
                  </div>
                  <p class="text-xs text-slate-500 font-medium pl-8">Administered by: <?= esc($record['provider_name'] ?: 'RHU Staff') ?> (Dose #<?= esc($record['dose_number'] ?? '1') ?>)</p>
                </div>
                <div class="text-right sm:text-right text-xs">
                  <span class="inline-block rounded-full px-3 py-1 font-bold bg-emerald-50 text-emerald-700 mb-1">Completed</span>
                  <p class="text-slate-400 font-medium"><?= esc($record['vaccination_date'] ?? '-') ?></p>
                </div>
              </article>
          <?php endforeach;
          endif; ?>
        </div>
      </section>

      <!-- 4. CERTIFICATES TAB -->
      <section data-tab-panel="certificates" class="hidden space-y-6">
        <h3 class="text-lg font-bold text-slate-900">Health Certificates & Clearances</h3>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs space-y-4">
          <p class="text-sm font-bold text-slate-800">Request New Certificate</p>
          <?php if ($certificateErrors): ?>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700 font-medium">
              <?= esc(implode(' ', $certificateErrors)) ?>
            </div>
          <?php endif; ?>
          <form method="post" action="ResidentDashboard.php?tab=certificates" class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <input type="hidden" name="form" value="certificate_request">
            <?php foreach (['Medical Certificate', 'Health Certificate', 'Barangay Health Certificate', 'Certificate of Live Birth'] as $certificateType): ?>
              <button type="submit" name="certificate_type" value="<?= esc($certificateType) ?>" class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-4 font-bold text-slate-700 hover:border-slate-300 hover:bg-slate-100 transition-all text-left">
                <span><?= esc($certificateType) ?> <span class="text-emerald-600">(No Payment)</span></span>
                <i data-lucide="arrow-right" class="h-4 w-4 text-slate-400"></i>
              </button>
            <?php endforeach; ?>
          </form>
        </div>

        <div class="space-y-3">
          <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">Requested / Issued Certificates</h4>
          <?php if (!$certificates): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-400">
              <i data-lucide="award" class="mx-auto mb-2 h-10 w-10 text-slate-300"></i>
              <p class="text-sm font-semibold">No requested certificates on file</p>
            </div>
          <?php else:
            $certPerPage = 4;
            $totalCertRecords = count($certificates);
            $totalCertPages = max(1, (int)ceil($totalCertRecords / $certPerPage));
          ?>
            <div class="max-h-[60vh] space-y-3 overflow-y-auto overscroll-contain pr-2 sm:max-h-[22rem]" id="certificates-list-container" role="region" aria-label="Requested and issued certificates" tabindex="0">
              <?php foreach ($certificates as $idx => $cert):
                $pageNum = (int)floor($idx / $certPerPage) + 1;
                $hiddenClass = $pageNum > 1 ? 'hidden' : '';
              ?>
                <article data-pagination-item="certificates" data-page="<?= $pageNum ?>" class="<?= $hiddenClass ?> flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs">
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-900"><?= esc($cert['certificate_type_name']) ?></p>
                    <p class="text-xs text-slate-500 font-medium">No: <span class="font-mono text-slate-700"><?= esc($cert['certificate_number']) ?></span> | Purpose: <?= esc($cert['purpose']) ?></p>
                  </div>
                  <div class="flex w-full flex-col items-start gap-2 text-xs sm:w-auto sm:items-end">
                    <?php
                    $certificateStatus = strtolower((string)($cert['validity_status'] ?? ''));
                    $certificateReady = (str_contains($certificateStatus, 'valid') || str_contains($certificateStatus, 'approved') || str_contains($certificateStatus, 'issued'))
                      && !str_contains($certificateStatus, 'invalid') && !str_contains($certificateStatus, 'revoked');
                    $certificateRejected = str_contains($certificateStatus, 'reject') || str_contains($certificateStatus, 'declin') || str_contains($certificateStatus, 'revoked') || str_contains($certificateStatus, 'invalid');
                    $certificateBadgeClass = $certificateReady
                      ? 'border-emerald-200 text-emerald-700'
                      : ($certificateRejected ? 'border-rose-200 text-rose-700' : 'border-amber-200 text-amber-700');
                    ?>
                    <span class="inline-flex max-w-full items-center justify-center rounded-full border bg-white px-3 py-1 text-center font-bold leading-snug shadow-xs sm:max-w-44 <?= $certificateBadgeClass ?>">
                      <?= esc($cert['validity_status']) ?>
                    </span>
                    <?php if ($certificateReady): ?>
                      <a href="ResidentDashboard.php?certificate_document=<?= (int)$cert['id'] ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-xl bg-teal-700 px-3 py-2 font-bold text-white hover:bg-teal-800">
                        <i data-lucide="file-badge" class="h-3.5 w-3.5"></i> View / Print Certificate
                      </a>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>

            <?php if ($totalCertPages > 1): ?>
              <div id="certificates-pagination-bar" class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm text-xs font-semibold text-slate-600">
                <span class="text-slate-500 font-medium">Page <strong class="text-slate-900 font-bold" id="certificates-current-page">1</strong> of <strong class="text-slate-900 font-bold"><?= $totalCertPages ?></strong> (<?= $totalCertRecords ?> certificates)</span>

                <div class="flex items-center gap-1.5">
                  <button type="button" id="certificates-prev-btn" onclick="changeDashboardPage('certificates', -1, <?= $totalCertPages ?>)" disabled class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-slate-700 hover:bg-teal-50 hover:border-teal-300 disabled:opacity-40 disabled:cursor-not-allowed transition-all font-bold">
                    <i data-lucide="chevron-left" class="h-4 w-4"></i> Previous
                  </button>

                  <div class="flex items-center gap-1" id="certificates-page-numbers">
                    <?php for ($p = 1; $p <= $totalCertPages; $p++): ?>
                      <button type="button" onclick="goToDashboardPage('certificates', <?= $p ?>, <?= $totalCertPages ?>)" data-page-btn="certificates-<?= $p ?>" class="<?= $p === 1  ?>">
                        <?= $p ?>
                      </button>
                    <?php endfor; ?>
                  </div>

                  <button type="button" id="certificates-next-btn" onclick="changeDashboardPage('certificates', 1, <?= $totalCertPages ?>)" class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-slate-700 hover:bg-teal-50 hover:border-teal-300 disabled:opacity-40 disabled:cursor-not-allowed transition-all font-bold">
                    Next <i data-lucide="chevron-right" class="h-4 w-4"></i>
                  </button>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>

      <!-- 5. EVENTS TAB -->
      <section data-tab-panel="events" class="hidden space-y-6">
        <div class="flex items-center justify-between border-b border-white/10 pb-4">
          <div>
            <h3 class="text-xl font-extrabold text-white">RHU Official Health Events & Programs</h3>
            <p class="text-xs text-slate-400">Public announcements, programs, photos, and municipal updates published by RHU Admin.</p>
          </div>
          <span class="rounded-full bg-emerald-950 px-3 py-1 text-xs font-bold text-emerald-400 border border-emerald-800/60"><?= count($postedEvents) ?> Programs Listed</span>
        </div>

        <?php if (!empty($residentAnnouncements)): ?>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <h4 class="text-sm font-black uppercase tracking-[0.18em] text-emerald-300">Health Announcements</h4>
              <span class="text-[11px] font-bold text-slate-400"><?= count($residentAnnouncements) ?> active</span>
            </div>
            <div class="resident-image-card-list">
              <?php foreach ($residentAnnouncements as $ann): ?>
                <?php
                  $annTitle = (string)($ann['title'] ?? 'RHU Announcement');
                  $annBadge = (string)($ann['badge_text'] ?? $ann['category'] ?? 'Announcement');
                  $annContent = (string)($ann['content'] ?? '');
                  $annImage = !empty($ann['image_url']) ? resolveImageUrl((string)$ann['image_url']) : '';
                ?>
                <article class="resident-image-card border border-white/10 bg-[#131916] shadow-xl">
                  <button type="button" class="resident-image-card-button" onclick="openResidentDetailCard(this)" data-detail-type="Announcement" data-detail-title="<?= esc($annTitle) ?>" data-detail-badge="<?= esc($annBadge) ?>" data-detail-description="<?= esc($annContent) ?>" data-detail-location="Official RHU advisory" data-detail-date="" data-detail-image="<?= esc($annImage) ?>">
                    <?php if (!empty($ann['image_url'])): ?>
                      <img src="<?= esc($annImage) ?>" alt="<?= esc($annTitle) ?>" referrerpolicy="no-referrer">
                    <?php else: ?>
                      <div class="resident-image-card-fallback flex items-center justify-center text-emerald-300">
                        <i data-lucide="megaphone" class="h-14 w-14"></i>
                      </div>
                    <?php endif; ?>
                    <div class="resident-image-card-overlay">
                      <span class="inline-flex rounded-lg bg-emerald-950/90 px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-emerald-300 border border-emerald-700/70"><?= esc($annBadge) ?></span>
                      <p class="mt-1 text-[11px] font-semibold text-emerald-200">Tap image to view details</p>
                    </div>
                  </button>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="resident-image-card-list">
          <?php foreach ($postedEvents as $ev):
            $evTitle = $ev['title'] ?? 'RHU Health Event';
            $evVenue = $ev['venue'] ?? 'Nasugbu RHU Center';
            $evDesc = $ev['description'] ?? 'No description provided.';
            $rawDate = $ev['event_date'] ?? ($ev['scheduled_date'] ?? 'now');
            $evDateFormatted = date('M d, Y', strtotime($rawDate));
            $evTime = !empty($ev['start_time']) ? date('g:i A', strtotime($ev['start_time'])) : '8:00 AM';
            $evStatus = $ev['status'] ?? 'Scheduled';
            $evImage = !empty($ev['image_url']) ? resolveImageUrl((string)$ev['image_url']) : '';
          ?>
            <article class="resident-image-card border border-white/10 bg-[#131916] shadow-xl hover:border-emerald-500/40 transition-all">
              <button type="button" class="resident-image-card-button" onclick="openResidentDetailCard(this)" data-detail-type="Event" data-detail-title="<?= esc($evTitle) ?>" data-detail-badge="<?= esc($evStatus) ?>" data-detail-description="<?= esc($evDesc) ?>" data-detail-location="<?= esc($evVenue) ?>" data-detail-date="<?= esc($evDateFormatted . ' - ' . $evTime) ?>" data-detail-image="<?= esc($evImage) ?>">
                <?php if ($evImage !== ''): ?>
                  <img src="<?= esc($evImage) ?>" alt="<?= esc($evTitle) ?>" onerror="this.closest('.resident-image-card-button').querySelector('img')?.remove(); this.closest('.resident-image-card-button').insertAdjacentHTML('afterbegin', '<div class=&quot;resident-image-card-fallback flex items-center justify-center text-emerald-300&quot;><i data-lucide=&quot;calendar-heart&quot; class=&quot;h-14 w-14&quot;></i></div>'); if (window.lucide) lucide.createIcons();">
                <?php else: ?>
                  <div class="resident-image-card-fallback flex items-center justify-center text-emerald-300">
                    <i data-lucide="calendar-heart" class="h-14 w-14"></i>
                  </div>
                <?php endif; ?>
                <div class="resident-image-card-overlay">
                  <div class="flex items-center justify-between gap-2">
                    <span class="rounded-lg bg-emerald-950/90 px-2.5 py-1 text-[10px] font-black text-emerald-300 border border-emerald-700/70 uppercase tracking-wider"><?= esc($evStatus) ?></span>
                    <span class="text-[10px] font-mono font-bold text-slate-200"><?= esc($evDateFormatted) ?></span>
                  </div>
                  <p class="mt-1 text-[11px] font-semibold text-emerald-200">Tap image to view details</p>
                </div>
              </button>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($residentEventGallery)): ?>
          <div class="space-y-3">
            <h4 class="text-sm font-black uppercase tracking-[0.18em] text-emerald-300">Event Photo Gallery</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <?php foreach ($residentEventGallery as $photo): ?>
                <figure class="overflow-hidden rounded-2xl border border-white/10 bg-[#131916]">
                  <img src="<?= esc(resolveImageUrl((string)($photo['image_url'] ?? ''))) ?>" alt="<?= esc($photo['title'] ?? 'RHU event photo') ?>" class="h-32 w-full object-cover">
                  <figcaption class="p-3">
                    <p class="text-xs font-black text-white"><?= esc($photo['title'] ?? 'RHU Event Photo') ?></p>
                    <p class="text-[10px] font-bold text-slate-400"><?= esc($photo['category'] ?? 'Event Photo') ?><?= !empty($photo['date']) ? ' · ' . esc($photo['date']) : '' ?></p>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($residentOfficials)): ?>
          <div class="space-y-3">
            <h4 class="text-sm font-black uppercase tracking-[0.18em] text-emerald-300">Municipal Officials</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <?php foreach ($residentOfficials as $official): ?>
                <article class="rounded-2xl border border-white/10 bg-[#131916] p-4">
                  <p class="text-sm font-black text-white"><?= esc($official['name'] ?? 'Municipal Official') ?></p>
                  <p class="mt-1 text-xs font-bold text-emerald-300"><?= esc($official['position'] ?? 'Official') ?></p>
                  <p class="mt-1 text-[11px] font-semibold text-slate-400"><?= esc($official['office'] ?? 'LGU Nasugbu') ?></p>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <div id="resident-detail-card-modal" class="fixed inset-0 z-[100000] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md" role="dialog" aria-modal="true" aria-labelledby="resident-detail-card-title">
        <div class="resident-detail-modal-body overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-100 bg-gradient-to-r from-teal-50 to-sky-50 px-5 py-4">
            <div>
              <p id="resident-detail-card-type" class="text-[10px] font-black uppercase tracking-[0.18em] text-emerald-700">Details</p>
              <h3 id="resident-detail-card-title" class="mt-1 text-lg font-black text-slate-950">RHU Update</h3>
            </div>
            <button type="button" onclick="closeResidentDetailCard()" class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-slate-600 shadow-sm hover:bg-slate-100" aria-label="Close details">
              <i data-lucide="x" class="h-5 w-5"></i>
            </button>
          </div>
          <div class="resident-detail-modal-content grid overflow-y-auto">
            <div id="resident-detail-card-image-wrap" class="resident-detail-modal-image bg-slate-100">
              <img id="resident-detail-card-image" src="" alt="" class="h-full w-full object-cover object-center">
            </div>
            <div class="space-y-5 p-6">
              <div class="flex flex-wrap items-center gap-2">
                <span id="resident-detail-card-badge" class="rounded-lg bg-emerald-950 px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-emerald-300">Official</span>
                <span id="resident-detail-card-date" class="text-xs font-bold text-slate-500"></span>
              </div>
              <p id="resident-detail-card-description" class="whitespace-pre-wrap text-base font-medium leading-8 text-slate-700"></p>
              <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs font-bold">
                <span id="resident-detail-card-location" class="flex items-center gap-1.5 text-slate-600"></span>
                <span class="text-emerald-600">Admin Verified</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 6. CONTACT TAB -->
      <section data-tab-panel="contact" class="hidden space-y-6">
        <h3 class="text-lg font-bold text-slate-900">Contact RHU Staff</h3>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
            <p class="text-sm font-bold text-slate-800 mb-4">Send Inquiry or Message</p>
            <?php if ($contactErrors): ?>
              <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700 font-medium">
                <?= esc(implode(' ', $contactErrors)) ?>
              </div>
            <?php endif; ?>
            <form method="post" action="ResidentDashboard.php?tab=contact" data-contact-message-form class="space-y-4 text-xs">
              <input type="hidden" name="form" value="contact">
              <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">
              <div>
                <label class="block font-bold text-slate-700 mb-1">Subject</label>
                <select name="subject" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500">
                  <option value="General Inquiry">General Inquiry</option>
                  <option value="Appointment Request">Appointment Request</option>
                  <option value="Certificate Request">Certificate Request</option>
                  <option value="Vaccination Query">Vaccination Query</option>
                </select>
              </div>
              <div>
                <label class="block font-bold text-slate-700 mb-1">Message Detail</label>
                <textarea name="message" rows="4" class="w-full resize-none rounded-xl border border-slate-200 p-3 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-teal-500" placeholder="Type your concerns or requests here..."></textarea>
              </div>
              <button type="submit" class="w-full rounded-xl bg-teal-600 py-3 text-xs font-bold text-white hover:bg-teal-700 transition-all">Send Message to Staff</button>
            </form>
          </div>

          <!-- History Messages -->
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs space-y-4">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">Previous Messages</h4>
            <div class="space-y-3 max-h-96 overflow-y-auto" data-resident-message-list>
              <?php if (!$residentMessages): ?>
                <p class="text-xs text-slate-400 font-medium text-center py-4">No sent messages yet</p>
                <?php else: foreach ($residentMessages as $msg): ?>
                  <div class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs">
                    <div class="flex justify-between font-bold text-slate-800">
                      <span><?= esc($msg['subject']) ?></span>
                      <span class="text-[10px] text-teal-600 font-semibold"><?= esc(trim((string)($msg['admin_reply'] ?? '')) !== '' ? 'Replied' : ($msg['status'] ?? 'Pending')) ?></span>
                    </div>
                    <p class="mt-1 text-slate-600 font-medium text-[11px]"><?= esc($msg['message']) ?></p>
                    <p class="mt-2 text-[9px] text-slate-400"><?= esc($msg['created_at']) ?></p>
                    <?php if (trim((string)($msg['admin_reply'] ?? '')) !== ''): ?>
                      <div class="mt-2 rounded-lg border-l-4 border-teal-500 bg-teal-50 p-2 text-[11px] font-medium text-teal-900">
                        <p class="font-black">RHU Reply</p>
                        <p class="mt-0.5"><?= esc($msg['admin_reply']) ?></p>
                      </div>
                    <?php endif; ?>
                  </div>
              <?php endforeach;
              endif; ?>
            </div>
          </div>
        </div>
      </section>

      <!-- 5. FAMILY MEMBERS TAB -->
      <section data-tab-panel="family" class="hidden space-y-6">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-slate-900">Family & Household Health Profile</h3>
            <p class="text-xs text-slate-500 font-medium">Manage and view health records for dependents linked to your household</p>
          </div>
          <button type="button" id="add-dependent-btn" onclick="openDependentModal()" data-dependent-open class="family-hover add-dependent-trigger rounded-xl bg-gradient-to-r from-teal-600 to-sky-600 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-teal-600/20 hover:from-teal-700 hover:to-sky-700 flex items-center gap-2 cursor-pointer">
            <i data-lucide="user-plus" class="h-4 w-4"></i> Add Dependent
          </button>
        </div>

        <?php if ($dependentSuccess): ?>
          <div class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800">
            <i data-lucide="circle-check" class="h-5 w-5 shrink-0"></i>
            <p><?= esc($dependentSuccess) ?></p>
          </div>
        <?php endif; ?>
        <?php if ($dependentErrors): ?>
          <div class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-800">
            <i data-lucide="circle-alert" class="h-5 w-5 shrink-0"></i>
            <div><?php foreach ($dependentErrors as $error): ?><p><?= esc($error) ?></p><?php endforeach; ?></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($pendingDependentRequests)): ?>
          <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex items-center justify-between gap-3 pb-3">
              <div>
                <h4 class="text-sm font-black text-amber-900">Pending dependent approval</h4>
                <p class="text-[11px] font-medium text-amber-800">These household links need your approval before they appear as active dependents.</p>
              </div>
            </div>
            <div class="space-y-3">
              <?php foreach ($pendingDependentRequests as $request): ?>
                <?php $requestName = trim(($request['first_name'] ?? '') . ' ' . ($request['middle_name'] ?? '') . ' ' . ($request['last_name'] ?? '')); ?>
                <div class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p class="text-sm font-black text-slate-900"><?= esc($requestName) ?></p>
                    <p class="text-[11px] font-medium text-slate-500"><?= esc($request['relationship'] ?? 'Family Member') ?> • <?= esc($request['sex'] ?: 'Not specified') ?></p>
                  </div>
                  <div class="flex gap-2">
                    <form method="post" action="ResidentDashboard.php?tab=family" onsubmit="return confirm('Approve this dependent request?')">
                      <input type="hidden" name="form" value="approve_dependent_request">
                      <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">
                      <input type="hidden" name="link_id" value="<?= (int)$request['id'] ?>">
                      <button type="submit" class="rounded-xl bg-emerald-600 px-3 py-2 text-[11px] font-black text-white hover:bg-emerald-700">Approve</button>
                    </form>
                    <form method="post" action="ResidentDashboard.php?tab=family" onsubmit="return confirm('Reject this dependent request?')">
                      <input type="hidden" name="form" value="reject_dependent_request">
                      <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">
                      <input type="hidden" name="link_id" value="<?= (int)$request['id'] ?>">
                      <button type="submit" class="rounded-xl bg-rose-600 px-3 py-2 text-[11px] font-black text-white hover:bg-rose-700">Reject</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div class="family-hover rounded-2xl border border-teal-100 bg-gradient-to-br from-teal-50 to-emerald-50 p-4">
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-700">Household members</p>
            <p class="mt-1 text-2xl font-black text-teal-900"><?= count($dependents) + 1 ?></p>
          </div>
          <div class="family-hover rounded-2xl border border-sky-100 bg-gradient-to-br from-sky-50 to-indigo-50 p-4">
            <p class="text-[10px] font-bold uppercase tracking-wider text-sky-700">Dependents</p>
            <p class="mt-1 text-2xl font-black text-sky-900"><?= count($dependents) ?></p>
          </div>
          <div class="family-hover col-span-2 rounded-2xl border border-violet-100 bg-gradient-to-br from-violet-50 to-fuchsia-50 p-4 sm:col-span-1">
            <p class="text-[10px] font-bold uppercase tracking-wider text-violet-700">Profile status</p>
            <p class="mt-2 text-xs font-extrabold text-violet-900">Verified resident</p>
          </div>
        </div>

        <!-- Household Head Card -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3.5 sm:gap-4">
          <!-- Self (Head) -->
          <?php
          $selfName = trim(($resident['first_name'] ?? '') . ' ' . ($resident['middle_name'] ?? '') . ' ' . ($resident['last_name'] ?? ''));
          $selfPayload = [
            'name' => $selfName ?: 'My Profile',
            'first_name' => $resident['first_name'] ?? '',
            'middle_name' => $resident['middle_name'] ?? '',
            'last_name' => $resident['last_name'] ?? '',
            'relationship' => 'Head of Family',
            'dob' => $resident['date_of_birth'] ?? 'N/A',
            'age' => $age !== null ? $age . ' y/o' : 'N/A',
            'gender' => $resident['sex'] ?: ($resident['gender'] ?: 'Not specified'),
            'blood_type' => !empty($resident['blood_type']) ? $resident['blood_type'] : ($healthProfile['blood_type'] ?? 'Unknown / N/A'),
            'notes' => !empty($healthProfile['allergies']) || !empty($healthProfile['chronic_conditions']) || !empty($healthProfile['current_medications'])
              ? trim(
                'Allergies: ' . ($healthProfile['allergies'] ?? 'None recorded') . "\n" .
                  'Chronic conditions: ' . ($healthProfile['chronic_conditions'] ?? ($healthProfile['medical_conditions'] ?? 'None recorded')) . "\n" .
                  'Current medications: ' . ($healthProfile['current_medications'] ?? ($healthProfile['medications'] ?? 'None recorded'))
              )
              : 'No known allergies or medical conditions recorded.',
            'address' => trim(($resident['address'] ?? 'Nasugbu, Batangas') . ', ' . ($resident['barangay'] ?? '')),
            'barangay' => $resident['barangay'] ?? 'Nasugbu',
            'emergency_contact' => $healthProfile['emergency_contact_name'] ?? ($resident['emergency_contact_name'] ?? 'Not set'),
            'emergency_phone' => $healthProfile['emergency_contact_phone'] ?? ($resident['emergency_contact_phone'] ?? 'N/A'),
            'dep_res_id' => $resident['id'] ?? $residentId
          ];
          $selfJsonAttr = htmlspecialchars(json_encode($selfPayload), ENT_QUOTES, 'UTF-8');
          ?>
          <article onclick="window.openDependentInfoModalFromElement(this)" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.openDependentInfoModalFromElement(this); }" data-dependent-payload="<?= $selfJsonAttr ?>" tabindex="0" role="button" aria-label="View details for <?= esc($selfName ?: 'my profile') ?>" class="family-hover rounded-2xl border-2 border-teal-500 bg-teal-50/30 p-5 shadow-2xs relative cursor-pointer transition-all hover:border-teal-600 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-teal-100">
            <span class="absolute top-4 right-4 rounded-full bg-teal-100 text-teal-800 text-[10px] font-bold px-2 py-0.5">Head of Family</span>
            <div class="flex items-center gap-3">
              <div class="flex h-12 w-12 items-center justify-center rounded-full bg-teal-600 text-white font-bold text-sm">
                <?= esc($initials) ?>
              </div>
              <div>
                <h4 class="font-bold text-slate-900 text-sm"><?= esc(trim(($resident['first_name'] ?? '') . ' ' . ($resident['middle_name'] ?? '') . ' ' . ($resident['last_name'] ?? ''))) ?></h4>
                <p class="text-xs text-slate-500 font-medium">Age: <?= $age ?? '-' ?> | <?= esc($resident['sex'] ?? $resident['gender'] ?? 'N/A') ?></p>
              </div>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-200/60 flex justify-between text-xs font-semibold text-teal-700">
              <span>Active Profile</span>
              <span class="flex items-center gap-1"><i data-lucide="check-circle" class="h-3.5 w-3.5"></i> Viewing</span>
            </div>
          </article>

          <?php foreach ($dependents as $dependent):
            $dependentName = trim(($dependent['first_name'] ?? '') . ' ' . ($dependent['middle_name'] ?? '') . ' ' . ($dependent['last_name'] ?? ''));
            $dependentInitials = strtoupper(substr($dependent['first_name'] ?? 'D', 0, 1) . substr($dependent['last_name'] ?? 'P', 0, 1));
            $dependentAge = residentAge($dependent['date_of_birth'] ?? null);
            $dependentAgeLabel = residentAgeLabel($dependent['date_of_birth'] ?? null);

            $depPayload = [
              'link_id' => $dependent['id'] ?? null,
              'name' => $dependentName,
              'first_name' => $dependent['first_name'] ?? '',
              'middle_name' => $dependent['middle_name'] ?? '',
              'last_name' => $dependent['last_name'] ?? '',
              'relationship' => $dependent['relationship'] ?? 'Family Member',
              'dob' => $dependent['date_of_birth'] ?? 'N/A',
              'age' => $dependentAgeLabel,
              'gender' => $dependent['sex'] ?: 'Not specified',
              'blood_type' => $dependent['blood_type'] ?: 'Unknown / N/A',
              'notes' => $dependent['medical_notes'] ?: 'No known allergies or medical conditions recorded.',
              'address' => trim(($resident['address'] ?? 'Nasugbu, Batangas') . ', ' . ($resident['barangay'] ?? '')),
              'barangay' => $resident['barangay'] ?? 'Nasugbu',
              'emergency_contact' => trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')),
              'emergency_phone' => $resident['contact_number'] ?? 'N/A',
              'dep_res_id' => $dependent['dependent_resident_id'] ?? null,
              'record_folder' => $dependent['record_folder'] ?? ['consultations' => [], 'certificates' => [], 'vaccinations' => [], 'pregnancies' => []]
            ];
            $depJsonAttr = htmlspecialchars(json_encode($depPayload), ENT_QUOTES, 'UTF-8');
          ?>
            <article onclick="window.openDependentInfoModalFromElement(this)" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.openDependentInfoModalFromElement(this); }" data-dependent-payload="<?= $depJsonAttr ?>" tabindex="0" role="button" aria-label="View details for <?= esc($dependentName) ?>" class="family-hover group rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-sky-50/40 p-5 shadow-sm hover:border-sky-400 hover:shadow-md transition-all cursor-pointer focus:outline-none focus:ring-4 focus:ring-sky-100">
              <div class="flex items-start gap-3">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-sky-500 to-indigo-600 text-sm font-bold text-white shadow-md"><?= esc($dependentInitials) ?></div>
                <div class="min-w-0 flex-1">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <h4 class="truncate text-sm font-bold text-slate-900 group-hover:text-teal-700 transition-colors"><?= esc($dependentName) ?></h4>
                      <p class="mt-1 text-xs font-medium text-slate-500"><?= esc($dependent['relationship']) ?> - <?= esc($dependentAgeLabel) ?> - <?= esc($dependent['sex'] ?: 'Not specified') ?></p>
                    </div>
                    <span class="rounded-full bg-sky-100 px-2 py-1 text-[9px] font-bold uppercase tracking-wide text-sky-700"><?= esc($dependent['blood_type'] ?: 'Blood N/A') ?></span>
                  </div>
                </div>
              </div>
              <?php if (!empty($dependent['medical_notes'])): ?><p class="mt-4 rounded-xl bg-amber-50 p-3 text-[11px] font-medium leading-5 text-amber-800"><i data-lucide="notebook-tabs" class="mr-1 inline h-3.5 w-3.5"></i><?= esc($dependent['medical_notes']) ?></p><?php endif; ?>
              <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3">
                <span class="flex items-center gap-1 text-[10px] font-bold text-emerald-700"><i data-lucide="link" class="h-3.5 w-3.5"></i>Linked dependent</span>
                <form method="post" action="ResidentDashboard.php?tab=family" onclick="event.stopPropagation()" onsubmit="return confirm('Remove this dependent from your household?')">
                  <input type="hidden" name="form" value="remove_dependent"><input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>"><input type="hidden" name="dependent_id" value="<?= (int)$dependent['id'] ?>">
                  <button type="submit" onclick="event.stopPropagation()" class="rounded-lg px-2 py-1 text-[10px] font-bold text-rose-600 hover:bg-rose-50">Remove</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>

          <?php if (!$dependents): ?>
            <button type="button" onclick="openDependentModal()" data-dependent-open class="family-hover add-dependent-trigger flex min-h-44 flex-col items-center justify-center rounded-2xl border-2 border-dashed border-sky-200 bg-sky-50/40 p-5 text-center hover:border-sky-400 hover:bg-sky-50 cursor-pointer">
              <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white text-sky-600 shadow-sm"><i data-lucide="user-plus" class="h-5 w-5"></i></span><strong class="mt-3 text-sm text-slate-800">Add your first dependent</strong><span class="mt-1 text-xs text-slate-500">Create a linked household profile</span>
            </button>
          <?php endif; ?>

          <?php if (false): ?>
            <!-- Sample Dependent 1 -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs hover:border-slate-300 transition-all">
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-100 text-indigo-600 font-bold text-sm">
                  JR
                </div>
                <div>
                  <h4 class="font-bold text-slate-900 text-sm">Juan Dela Cruz Jr.</h4>
                  <p class="text-xs text-slate-500 font-medium">Child - 4 y/o - Male</p>
                </div>
              </div>
              <div class="mt-4 pt-3 border-t border-slate-100 flex justify-between items-center text-xs font-medium text-slate-600">
                <span class="text-emerald-600 font-bold">Vaccine Complete (OPT+)</span>
                <button type="button" data-tab-link="immunization" class="text-teal-600 font-bold hover:underline">View Records</button>
              </div>
            </div>

            <!-- Sample Dependent 2 -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs hover:border-slate-300 transition-all">
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-rose-100 text-rose-600 font-bold text-sm">
                  MD
                </div>
                <div>
                  <h4 class="font-bold text-slate-900 text-sm">Maria Dela Cruz</h4>
                  <p class="text-xs text-slate-500 font-medium">Spouse - 31 y/o - Female</p>
                </div>
              </div>
              <div class="mt-4 pt-3 border-t border-slate-100 flex justify-between items-center text-xs font-medium text-slate-600">
                <span class="text-amber-600 font-bold">Prenatal Checkup Due</span>
                <button type="button" data-tab-link="records" class="text-teal-600 font-bold hover:underline">View Records</button>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- NEARBY RHU & BARANGAY MAP TAB -->
      <section data-tab-panel="map" class="hidden space-y-6">
        <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-teal-600">Community navigation</p>
            <h2 class="mt-1 text-xl font-black text-slate-900">RHU & nearby barangay locations</h2>
            <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">Allow location access to follow your live position, nearby health facilities and barangay halls. Distances shown are straight-line estimates.</p>
          </div>
          <button id="map-locate-button" type="button" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-teal-700 px-5 py-3 text-xs font-bold text-white shadow-lg shadow-teal-700/20 hover:bg-teal-800">
            <i data-lucide="locate-fixed" class="h-4 w-4"></i><span>Start live tracking</span>
          </button>
        </div>

        <div id="map-status" role="status" aria-live="polite" class="flex items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 p-3 text-xs font-semibold text-sky-800">
          <i data-lucide="info" class="h-4 w-4 shrink-0"></i>
          <span>Select "Start live tracking" to follow your location and calculate your distance from the RHU and nearby barangay facilities.</span>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.65fr)_minmax(18rem,.75fr)]">
          <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="relative">
              <div id="resident-location-map" aria-label="Interactive map showing your location, nearby RHUs and barangay halls"></div>
              <div id="map-live-location-card" class="pointer-events-none absolute bottom-4 left-4 z-[500] hidden max-w-[calc(100%-2rem)] rounded-xl border border-sky-200 bg-white/95 px-4 py-3 text-xs shadow-xl backdrop-blur-md">
                <div class="flex items-start gap-2">
                  <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-sky-600 text-white shadow-md">
                    <i data-lucide="navigation" class="h-3.5 w-3.5"></i>
                  </span>
                  <div class="min-w-0">
                    <p class="font-black uppercase tracking-wider text-sky-700 text-[9px]">Your live location</p>
                    <p id="map-live-location-name" class="mt-0.5 truncate font-black text-slate-900">Finding your place...</p>
                    <p id="map-live-location-address" class="mt-0.5 line-clamp-2 text-[11px] font-semibold text-slate-500">GPS is updating while you move.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <aside class="min-w-0">
            <div class="mb-3 flex items-center justify-between">
              <h3 class="text-sm font-black text-slate-900">Nearest locations</h3>
              <span id="map-result-count" class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">1 location</span>
            </div>
            <div id="nearby-location-list" class="max-h-[31rem] space-y-3 overflow-y-auto pr-1">
              <article class="rounded-2xl border border-teal-200 bg-teal-50/60 p-4">
                <div class="flex items-start justify-between gap-3">
                  <div><span class="text-[9px] font-black uppercase tracking-wider text-teal-700">Rural Health Unit</span>
                    <h4 class="mt-1 text-sm font-black text-slate-900">Nasugbu Rural Health Unit</h4>
                    <p class="mt-1 text-[11px] text-slate-500">Escalera St., Barangay 2, Nasugbu</p>
                  </div>
                  <span class="rounded-lg bg-white p-2 text-teal-700"><i data-lucide="hospital" class="h-4 w-4"></i></span>
                </div>
                <p class="mt-3 text-xs font-bold text-slate-500">Distance: <strong data-rhu-distance class="text-slate-900">Enable location</strong></p>
                <button id="main-rhu-route-button" type="button" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-teal-700 hover:underline"><i data-lucide="navigation" class="h-3.5 w-3.5"></i> Show directions</button>
              </article>
            </div>
          </aside>
        </div>
        <p class="text-center text-[10px] text-slate-400">Map data OpenStreetMap contributors. Nearby results depend on available community map data.</p>
      </section>

      <!-- EMERGENCY & REFERRAL TAB -->
      <section data-tab-panel="emergency" class="hidden space-y-6">
        <!-- Urgent Hotline Banner -->
        <div class="rounded-2xl bg-gradient-to-r from-rose-600 to-red-700 p-6 text-white shadow-lg space-y-4">
          <div class="flex items-center gap-3">
            <div class="rounded-full bg-white/20 p-2.5 text-white">
              <i data-lucide="siren" class="h-6 w-6 animate-pulse"></i>
            </div>
            <div>
              <h3 class="text-lg font-black tracking-tight">RHU Emergency & Quick Referral Desk</h3>
              <p class="text-xs text-rose-100">For life-threatening situations, immediate ambulance transport, or urgent hospital referral.</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 pt-2">
            <a href="tel:09387933806" class="flex items-center justify-between rounded-xl bg-white/10 hover:bg-white/20 p-3.5 transition-all text-xs font-bold border border-white/20">
              <span class="flex items-center gap-2"><i data-lucide="phone-call" class="h-4 w-4 text-rose-200"></i> RHU Hotline</span>
              <span class="font-mono text-white">0938-793-3806</span>
            </a>
            <a href="tel:911" class="flex items-center justify-between rounded-xl bg-white/10 hover:bg-white/20 p-3.5 transition-all text-xs font-bold border border-white/20">
              <span class="flex items-center gap-2"><i data-lucide="ambulance" class="h-4 w-4 text-rose-200"></i> MDRRMO Ambulance</span>
              <span class="font-mono text-white">(042) 710-XXXX</span>
            </a>
            <a href="tel:117" class="flex items-center justify-between rounded-xl bg-white/10 hover:bg-white/20 p-3.5 transition-all text-xs font-bold border border-white/20">
              <span class="flex items-center gap-2"><i data-lucide="shield-alert" class="h-4 w-4 text-rose-200"></i> Barangay Health Response</span>
              <span class="font-mono text-white">Direct BHW</span>
            </a>
          </div>
        </div>

        <!-- Quick Referral Form -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs space-y-4">
          <div class="flex items-center gap-2 text-rose-600">
            <i data-lucide="send" class="h-5 w-5"></i>
            <h4 class="text-sm font-bold text-slate-800">Send Instant Referral / Transport Request</h4>
          </div>

          <form method="post" action="ResidentDashboard.php?tab=emergency" class="space-y-4 text-xs">
            <input type="hidden" name="form" value="emergency_request">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block font-bold text-slate-700 mb-1">Nature of Emergency</label>
                <select name="emergency_nature" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-rose-500">
                  <option value="Severe Injury / Fracture">Severe Injury / Accident</option>
                  <option value="High Fever / Convulsion (Child)">High Fever / Convulsion (Child)</option>
                  <option value="Maternal / Labor Urgency">Maternal Urgency / Severe Labor Pain</option>
                  <option value="Difficulty Breathing / Asthma Attack">Difficulty Breathing / Asthma Attack</option>
                  <option value="Severe Allergic Reaction">Severe Allergic Reaction</option>
                  <option value="Other Medical Urgent Need">Other Medical Urgency</option>
                </select>
              </div>
              <div>
                <label class="block font-bold text-slate-700 mb-1">Pickup / Patient Location</label>
                <input type="text" name="pickup_location" required value="<?= esc(($resident['address'] ?? '') . ' ' . ($resident['barangay'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-medium focus:outline-none focus:ring-2 focus:ring-rose-500" placeholder="Purok, Barangay, Landmark">
              </div>
            </div>
            <button type="submit" class="w-full rounded-xl bg-rose-600 py-3 text-xs font-bold text-white hover:bg-rose-700 transition-all flex items-center justify-center gap-2">
              <i data-lucide="alert-triangle" class="h-4 w-4"></i> Submit Emergency Referral Request
            </button>
          </form>
        </div>
      </section>

    </main>
  </div>

  <!-- Logout Confirmation Modal -->
  <div id="logout-modal" class="fixed inset-0 z-80 hidden items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="logout-title">
    <div class="w-full max-w-sm overflow-hidden rounded-3xl border border-white/70 bg-white shadow-2xl">
      <div class="bg-gradient-to-br from-rose-50 via-white to-amber-50 p-6 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-100 text-rose-600 shadow-sm"><i data-lucide="log-out" class="h-7 w-7"></i></span>
        <h3 id="logout-title" class="mt-4 text-lg font-black text-slate-900">Log out of your account?</h3>
        <p class="mt-2 text-sm leading-6 text-slate-500">You will need to sign in again to access your health records and resident services.</p>
      </div>
      <div class="flex gap-3 border-t border-slate-100 bg-white p-4">
        <button type="button" data-logout-cancel class="flex-1 rounded-xl border border-slate-200 py-3 text-xs font-bold text-slate-600 hover:bg-slate-50">Stay signed in</button>
        <a href="ResidentDashboard.php?logout=1" class="flex flex-1 items-center justify-center rounded-xl bg-rose-600 py-3 text-xs font-bold text-white shadow-lg shadow-rose-600/20 hover:bg-rose-700">Yes, log out</a>
      </div>
    </div>
  </div>

  <!-- Add Dependent Modal -->
  <div id="dependent-modal" class="fixed inset-0 z-99999 hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md" style="z-index: 99999;">
    <div class="relative max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-3xl border border-white/70 bg-white shadow-2xl">
      <div class="sticky top-0 z-10 flex items-start justify-between border-b border-slate-100 bg-gradient-to-r from-teal-50 to-sky-50 px-6 py-5">
        <div class="flex gap-3">
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-sky-600 text-white shadow-md"><i data-lucide="user-round-plus" class="h-5 w-5"></i></span>
          <div>
            <h3 class="text-base font-black text-slate-900">Add Household Dependent</h3>
            <p class="mt-1 text-xs text-slate-500">Create a profile linked to your resident account.</p>
          </div>
        </div>
        <button type="button" onclick="closeDependentModal()" data-dependent-close class="rounded-xl p-2 text-slate-500 hover:bg-white hover:text-slate-800" aria-label="Close dependent form"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form method="post" action="ResidentDashboard.php?tab=family" class="space-y-5 p-6 text-xs">
        <input type="hidden" name="form" value="add_dependent">
        <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">

        <?php if ($dependentErrors): ?>
          <div class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-800">
            <i data-lucide="circle-alert" class="h-5 w-5 shrink-0"></i>
            <div><?php foreach ($dependentErrors as $error): ?><p><?= esc($error) ?></p><?php endforeach; ?></div>
          </div>
        <?php endif; ?>

        <div>
          <p class="mb-3 font-bold uppercase tracking-wider text-slate-400">Personal information</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1.5"><span class="font-bold text-slate-700">First name <b class="text-rose-500">*</b></span><input required maxlength="100" name="first_name" value="<?= esc($_POST['first_name'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100" placeholder="First name"></label>
            <label class="space-y-1.5"><span class="font-bold text-slate-700">Middle name</span><input maxlength="100" name="middle_name" value="<?= esc($_POST['middle_name'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100" placeholder="Optional"></label>
            <label class="space-y-1.5"><span class="font-bold text-slate-700">Last name <b class="text-rose-500">*</b></span><input required maxlength="100" name="last_name" value="<?= esc($_POST['last_name'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100" placeholder="Last name"></label>
            <label class="space-y-1.5"><span class="font-bold text-slate-700">Relationship <b class="text-rose-500">*</b></span><select required name="relationship" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                <option value="">Select relationship</option><?php foreach (['Child', 'Spouse', 'Parent', 'Sibling', 'Grandchild', 'Other'] as $option): ?><option <?= ($_POST['relationship'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?>
              </select></label>
          </div>
        </div>
        <div>
          <p class="mb-3 font-bold uppercase tracking-wider text-slate-400">Health profile</p>
          <div class="grid gap-4 sm:grid-cols-3">
            <label class="space-y-1.5"><span class="font-bold text-slate-700">Date of birth <b class="text-rose-500">*</b></span><input required type="date" max="<?= date('Y-m-d') ?>" name="date_of_birth" value="<?= esc($_POST['date_of_birth'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100"></label>
            <label class="space-y-1.5"><span class="font-bold text-slate-700">Gender</span><select name="gender" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                <option value="">Select</option><?php foreach (['Female', 'Male', 'Other', 'Prefer not to say'] as $option): ?><option <?= ($_POST['gender'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?>
              </select></label>
            <label class="space-y-1.5"><span class="font-bold text-slate-700">Blood type</span><select name="blood_type" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                <option value="">Unknown</option><?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $option): ?><option <?= ($_POST['blood_type'] ?? '') === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?>
              </select></label>
          </div>
        </div>
        <label class="block space-y-1.5"><span class="font-bold text-slate-700">Medical notes</span><textarea maxlength="1000" name="medical_notes" rows="3" class="w-full resize-none rounded-xl border border-slate-200 px-3 py-3 font-medium outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100" placeholder="Allergies, conditions, or other important notes"><?= esc($_POST['medical_notes'] ?? '') ?></textarea></label>
        <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
          <button type="button" onclick="closeDependentModal()" data-dependent-close class="rounded-xl border border-slate-200 px-5 py-3 font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="rounded-xl bg-gradient-to-r from-teal-600 to-sky-600 px-5 py-3 font-bold text-white shadow-lg shadow-teal-600/20 hover:from-teal-700 hover:to-sky-700"><i data-lucide="user-plus" class="mr-1 inline h-4 w-4"></i>Add Dependent</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Dependent Consultation Records Modal -->
  <div id="dependent-consultation-modal" class="fixed inset-0 z-[100000] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md" style="display: none;">
    <div class="max-h-[88vh] w-full max-w-3xl overflow-y-auto rounded-3xl border border-white/70 bg-white shadow-2xl">
      <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-gradient-to-r from-sky-50 to-teal-50 px-6 py-5">
        <div>
          <p class="text-[10px] font-black uppercase tracking-wider text-sky-700">Consultation Record</p>
          <h3 id="dependent-consultation-name" class="text-lg font-black text-slate-900">Dependent consultations</h3>
          <p class="text-xs font-medium text-slate-500">Clinical updates recorded by RHU staff and health workers</p>
        </div>
        <button type="button" onclick="closeDependentConsultationFolder()" class="rounded-xl p-2 text-slate-400 hover:bg-white hover:text-slate-700" aria-label="Close consultation records"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div id="dependent-consultation-list" class="space-y-3 p-6"></div>
      <div class="border-t border-slate-100 bg-slate-50/60 px-6 py-4 text-right">
        <button type="button" onclick="closeDependentConsultationFolder()" class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white">Close Records</button>
      </div>
    </div>
  </div>

  <div id="vaccination-record-modal" class="fixed inset-0 z-[100000] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md" style="display: none;">
    <div class="w-full max-w-lg overflow-hidden rounded-3xl border border-white/70 bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b border-slate-100 bg-indigo-50 px-6 py-5">
        <div><p class="text-[10px] font-black uppercase tracking-wider text-indigo-700">Immunization Record</p><h3 id="vaccination-record-name" class="text-lg font-black text-slate-900">Resident - Vaccine Record</h3></div>
        <button type="button" onclick="closeVaccinationRecord()" class="rounded-xl p-2 text-slate-400 hover:bg-white hover:text-slate-700" aria-label="Close vaccination record"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div id="vaccination-record-details" class="grid grid-cols-1 gap-3 p-6 text-xs sm:grid-cols-2"></div>
      <div class="border-t border-slate-100 bg-slate-50/60 px-6 py-4 text-right"><button type="button" onclick="closeVaccinationRecord()" class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white">Close Record</button></div>
    </div>
  </div>

  <!-- View Dependent Info Modal -->
  <div id="dependent-info-modal" class="fixed inset-0 z-99999 hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md" style="z-index: 99999; display: none;">
    <div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-3xl border border-white/70 bg-white shadow-2xl">
      <!-- Modal Header -->
      <div class="sticky top-0 z-10 flex items-start justify-between border-b border-slate-100 bg-gradient-to-r from-sky-50 via-teal-50 to-emerald-50 px-6 py-5">
        <div class="flex items-center gap-4">
          <div id="dep-info-avatar" class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-lg font-black text-white shadow-lg shadow-sky-500/20">
            PB
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h3 id="dep-info-name" class="text-lg font-black text-slate-900">Dependent Profile</h3>
              <span id="dep-info-blood" class="rounded-full bg-sky-100 px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-sky-800">Blood N/A</span>
            </div>
            <p id="dep-info-sub" class="mt-0.5 text-xs font-medium text-slate-500">Family Member</p>
          </div>
        </div>
        <button type="button" onclick="closeDependentInfoModal()" class="rounded-xl p-2 text-slate-400 hover:bg-white hover:text-slate-700 transition-colors" aria-label="Close modal">
          <i data-lucide="x" class="h-5 w-5"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <div class="space-y-6 p-6 text-xs">

        <!-- Status Banner -->
        <div class="flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4">
          <div class="flex items-center gap-2.5">
            <span class="flex h-3 w-3 rounded-full bg-emerald-500 animate-pulse"></span>
            <div>
              <p class="font-bold text-emerald-900 text-xs">Verified System Resident</p>
              <p class="text-[11px] text-emerald-700">Official health profile registered under Nasugbu RHU I</p>
            </div>
          </div>
          <span class="rounded-full bg-emerald-200/80 px-3 py-1 text-[10px] font-black text-emerald-900 uppercase tracking-wider">Active</span>
        </div>

        <!-- Personal & Demographic Details -->
        <div>
          <h4 class="mb-3 font-extrabold uppercase tracking-wider text-slate-400 text-[11px] flex items-center gap-1.5">
            <i data-lucide="user" class="h-3.5 w-3.5 text-sky-600"></i> Personal &amp; Demographic Information
          </h4>
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3.5">
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Relationship</span>
              <span id="dep-info-rel" class="mt-1 block text-xs font-black text-slate-800">Family Member</span>
            </div>
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Date of Birth</span>
              <span id="dep-info-dob" class="mt-1 block text-xs font-black text-slate-800">N/A</span>
            </div>
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Age</span>
              <span id="dep-info-age" class="mt-1 block text-xs font-black text-slate-800">N/A</span>
            </div>
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Gender</span>
              <span id="dep-info-gender" class="mt-1 block text-xs font-black text-slate-800">Not specified</span>
            </div>
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Blood Type</span>
              <span id="dep-info-bt" class="mt-1 block text-xs font-black text-slate-800">Unknown</span>
            </div>
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Barangay</span>
              <span id="dep-info-brgy" class="mt-1 block text-xs font-black text-slate-800">Nasugbu</span>
            </div>
          </div>
        </div>

        <form method="post" action="ResidentDashboard.php?tab=family" id="dependent-edit-form" class="hidden rounded-2xl border border-teal-100 bg-teal-50/60 p-4">
          <input type="hidden" name="form" value="edit_dependent">
          <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">
          <input type="hidden" name="dependent_link_id" id="dep-edit-link-id">
          <input type="hidden" name="dependent_resident_id" id="dep-edit-resident-id">
          <div class="mb-3 flex items-center justify-between gap-3">
            <div>
              <p class="text-xs font-black text-teal-950">Edit Dependent Information</p>
              <p class="text-[11px] font-medium text-teal-700">Age is automatic from the date of birth.</p>
            </div>
            <span id="dep-edit-auto-age" class="rounded-full bg-white px-2.5 py-1 text-[10px] font-black text-teal-800">Age: N/A</span>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="space-y-1"><span class="font-bold text-slate-700">First name</span><input required name="first_name" id="dep-edit-first-name" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"></label>
            <label class="space-y-1"><span class="font-bold text-slate-700">Middle name</span><input name="middle_name" id="dep-edit-middle-name" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"></label>
            <label class="space-y-1"><span class="font-bold text-slate-700">Last name</span><input required name="last_name" id="dep-edit-last-name" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"></label>
            <label class="space-y-1"><span class="font-bold text-slate-700">Relationship</span><input required name="relationship" id="dep-edit-relationship" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"></label>
            <label class="space-y-1"><span class="font-bold text-slate-700">Date of birth</span><input required type="date" name="date_of_birth" id="dep-edit-dob" max="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"></label>
            <label class="space-y-1"><span class="font-bold text-slate-700">Gender</span><select name="gender" id="dep-edit-gender" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"><option>Female</option><option>Male</option><option>Other</option></select></label>
            <label class="space-y-1"><span class="font-bold text-slate-700">Blood type</span><select name="blood_type" id="dep-edit-blood-type" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"><option value="">Unknown / N/A</option><option>A+</option><option>A-</option><option>B+</option><option>B-</option><option>AB+</option><option>AB-</option><option>O+</option><option>O-</option></select></label>
            <label class="space-y-1 sm:col-span-2"><span class="font-bold text-slate-700">Medical notes</span><textarea name="medical_notes" id="dep-edit-notes" rows="2" class="w-full rounded-xl border border-teal-100 bg-white p-2.5 font-semibold outline-none focus:border-teal-500"></textarea></label>
          </div>
          <div class="mt-3 flex justify-end">
            <button type="submit" class="rounded-xl bg-teal-600 px-4 py-2.5 text-xs font-black text-white shadow-sm hover:bg-teal-700">Save Dependent Details</button>
          </div>
        </form>

        <!-- Household Address & Emergency Contact -->
        <div>
          <h4 class="mb-3 font-extrabold uppercase tracking-wider text-slate-400 text-[11px] flex items-center gap-1.5">
            <i data-lucide="home" class="h-3.5 w-3.5 text-sky-600"></i> Household Address &amp; Emergency Contact
          </h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100 space-y-1">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Registered Household Address</span>
              <span id="dep-info-addr" class="block text-xs font-bold text-slate-800 leading-relaxed">Nasugbu, Batangas</span>
            </div>
            <div class="rounded-2xl bg-slate-50 p-3.5 border border-slate-100 space-y-1">
              <span class="block text-[10px] font-bold text-slate-400 uppercase">Head of Household (Emergency Contact)</span>
              <span id="dep-info-head" class="block text-xs font-bold text-slate-800">Head of Household</span>
              <span id="dep-info-phone" class="block text-[11px] font-mono text-slate-500">Contact #: N/A</span>
            </div>
          </div>
        </div>

        <!-- Medical Conditions & Notes -->
        <div>
          <h4 class="mb-3 font-extrabold uppercase tracking-wider text-slate-400 text-[11px] flex items-center gap-1.5">
            <i data-lucide="stethoscope" class="h-3.5 w-3.5 text-amber-600"></i> Medical Notes, Allergies &amp; Health Profile
          </h4>
          <div id="dep-info-notes-box" class="rounded-2xl bg-amber-50/80 border border-amber-200/80 p-4 text-amber-900 font-medium leading-relaxed">
            <p id="dep-info-notes">No known allergies or medical conditions specified.</p>
          </div>
        </div>

        <!-- Dependent record folder -->
        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <h4 class="mb-3 flex items-center gap-1.5 font-extrabold uppercase tracking-wider text-[11px] text-sky-800">
            <i data-lucide="folder-open" class="h-3.5 w-3.5"></i> Health Record Folder
          </h4>
          <div id="dep-info-record-folder" class="space-y-2"></div>
        </div>

      </div>

      <!-- Modal Footer -->
      <div class="flex flex-col-reverse sm:flex-row items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-6 py-4">
        <button type="button" onclick="closeDependentInfoModal()" class="w-full sm:w-auto rounded-xl border border-slate-200 bg-white px-5 py-2.5 font-bold text-slate-700 hover:bg-slate-100 transition-colors">
          Close Profile
        </button>
        <button type="button" onclick="bookAppointmentForDependent()" class="w-full sm:w-auto rounded-xl bg-gradient-to-r from-teal-600 to-sky-600 px-5 py-2.5 font-bold text-white shadow-md hover:from-teal-700 hover:to-sky-700 transition-all flex items-center justify-center gap-2">
          <i data-lucide="calendar-plus" class="h-4 w-4"></i> Book Consultation Appointment
        </button>
      </div>
    </div>
  </div>

  <!-- OPD / RHU Appointment Modal -->
  <div id="appointment-modal" class="fixed inset-0 z-200 flex min-h-screen items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="appointment-modal-title">
    <div class="m-auto w-full max-w-2xl rounded-3xl bg-white p-5 shadow-2xl max-h-[90vh] overflow-y-auto" data-appointment-dialog>
      <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-100 bg-white pb-4">
        <div class="flex items-center gap-2.5">
          <div class="h-9 w-9 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center">
            <i data-lucide="calendar-plus" class="h-5 w-5"></i>
          </div>
          <div>
            <h3 id="appointment-modal-title" class="text-base font-extrabold text-slate-900">Book RHU Appointment</h3>
            <p class="mt-0.5 text-xs leading-5 text-slate-500">Choose the appointment type, preferred date, and an available healthcare provider.</p>
          </div>
        </div>
        <button type="button" data-appointment-close class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" aria-label="Close appointment form">
          <i data-lucide="x" class="h-5 w-5"></i>
        </button>
      </div>

      <form method="post" action="ResidentDashboard.php?tab=records" class="mt-4 grid grid-cols-1 gap-4 text-xs sm:grid-cols-2">
        <input type="hidden" name="form" value="appointment_request">
        <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">
        <input type="hidden" name="patient_resident_id" id="appointment_patient_resident_id" value="<?= (int)$residentId ?>">

        <!-- 1. Select Appointment Category / Type -->
        <div class="min-w-0">
          <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1.5">Type of Appointment *</label>
          <select id="appointment_type_select" name="appointment_type" required onchange="filterAvailableStaff()" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-bold text-slate-800 outline-none focus:border-teal-500 focus:bg-white transition-all">
            <option value="General Medical Consultation"> General Medical Consultation (Physician / Doctor)</option>
            <option value="Prenatal & Maternal Care" data-prenatal-option <?= $isFemaleResident ? '' : 'disabled' ?>> Prenatal & Maternal Care (Midwife / Doctor)</option>
            <option value="Child Vaccination & Immunization"> Child Vaccination & Immunization (Nurse)</option>
            <option value="Laboratory Test & Blood Work"> Laboratory & Blood Test (Medical Technologist)</option>
            <option value="Sanitary Inspection & Clearance"> Sanitary Inspection & Clearance (Sanitary Inspector)</option>
            <option value="Dental Consultation & Oral Health"> Dental Consultation & Oral Health (Dental Health Staff)</option>
            <option value="General Health Checkup"> General Health Checkup (Public Health Nurse)</option>
          </select>
        </div>

        <!-- 2. Select Appointment Date -->
        <div class="min-w-0">
          <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1.5">Preferred Appointment Date *</label>
          <input type="date" id="appointment_date_input" name="preferred_date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" onchange="filterAvailableStaff()" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white transition-all">
        </div>

        <div class="min-w-0 sm:col-span-2">
          <label for="appointment_time_input" class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1.5">Preferred Appointment Time *</label>
          <input type="time" id="appointment_time_input" name="preferred_time" required min="06:00" max="18:00" aria-describedby="appointment-hours-hint" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white transition-all">
          <p id="appointment-hours-hint" class="mt-1.5 text-[11px] text-slate-500">RHU clinic hours: 6:00 AM to 6:00 PM.</p>
        </div>

        <!-- 3. Available Healthcare Provider (Doctor / Nurse / Staff) -->
        <div class="sm:col-span-2">
          <div class="flex items-center justify-between mb-1.5">
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px]">Assigned Available Doctor / Staff *</label>
            <span class="text-[10px] text-teal-600 font-bold" id="staff_count_badge">Loading available staff...</span>
          </div>
          <div id="staff_selection_container" class="grid max-h-40 grid-cols-1 gap-2 overflow-y-auto rounded-2xl border border-slate-100 bg-slate-50/50 p-1.5 sm:grid-cols-2">
            <?php
            $initialAppointmentStaff = array_values(array_filter($rhuStaffList ?? [], fn($staff) => in_array('general', $staff['appointment_roles'] ?? [], true)));
            ?>
            <?php if (!empty($initialAppointmentStaff)): ?>
              <?php foreach ($initialAppointmentStaff as $idx => $staff): ?>
                <label class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded-xl border border-slate-200 bg-white p-2.5 transition-all hover:border-teal-300 hover:bg-teal-50/50">
                  <div class="flex min-w-0 items-center gap-2.5">
                    <input type="radio" name="health_worker_id" value="<?= (int)($staff['staff_id'] ?? $staff['id'] ?? 0) ?>" <?= $idx === 0 ? 'checked' : '' ?> class="h-4 w-4 text-teal-600 focus:ring-teal-500">
                    <div class="min-w-0">
                      <p class="truncate text-xs font-bold text-slate-800"><?= esc(trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))) ?></p>
                      <p class="truncate text-[10px] font-medium text-slate-500"><?= esc($staff['staff_type'] ?? $staff['position_title'] ?? 'RHU Staff') ?><?= !empty($staff['specialization']) ? ' - ' . esc($staff['specialization']) : '' ?></p>
                      <p class="truncate font-mono text-[9px] text-slate-400">Duty: <?= esc($staff['work_days'] ?? 'Monday, Tuesday, Wednesday, Thursday, Friday') ?></p>
                    </div>
                  </div>
                  <span class="rounded-md border border-emerald-200/60 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">Available</span>
                </label>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="p-3 text-center text-xs font-semibold text-slate-400">No doctor or physician is available for this appointment type.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- 4. Chief Complaint / Notes -->
        <div class="sm:col-span-2">
          <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1.5">Chief Complaint / Reason for Visit *</label>
          <textarea name="chief_complaint" rows="2" required placeholder="Briefly describe your symptoms or reason for consultation..." class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-medium text-slate-800 outline-none transition-all focus:border-teal-500 focus:bg-white"></textarea>
        </div>

        <div class="sticky bottom-0 z-10 -mx-1 flex items-center justify-end gap-2 border-t border-slate-100 bg-white px-1 pt-4 sm:col-span-2">
          <button type="button" data-appointment-close class="rounded-xl px-4 py-2.5 font-bold text-slate-600 hover:bg-slate-100 transition-colors">
            Cancel
          </button>
          <button type="submit" class="rounded-xl bg-teal-600 px-5 py-2.5 font-bold text-white shadow-md hover:bg-teal-700 transition-colors">
            Confirm & Book Appointment
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (() => {
      const appointmentStaff = <?= json_encode(array_values($rhuStaffList ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      const appointmentBookings = <?= json_encode($staffBookingsPerDate ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      const appointmentRules = [
        { pattern: /prenatal|maternal/i, role: 'prenatal', emptyMessage: 'No midwife or doctor is available for this appointment type.' },
        { pattern: /child|vaccination|immunization|vaccine/i, role: 'vaccination', emptyMessage: 'No nurse is available for this vaccination appointment.' },
        { pattern: /laboratory|blood|test|diagnostic/i, role: 'laboratory', emptyMessage: 'No medical technologist is available for this laboratory appointment.' },
        { pattern: /sanitary|inspection|clearance/i, role: 'sanitary', emptyMessage: 'No sanitary inspector is available for this appointment type.' },
        { pattern: /dental|dentist|tooth|oral/i, role: 'dental', emptyMessage: 'No dental health staff is available for this appointment type.' },
        { pattern: /health checkup|checkup/i, role: 'checkup', emptyMessage: 'No nurse or doctor is available for this checkup.' }
      ];
      const getAppointmentRule = value => appointmentRules.find(rule => rule.pattern.test(value || '')) || {
        role: 'general',
        emptyMessage: 'No doctor or physician is available for this appointment type.'
      };
      const appointmentRoleKeywords = {
        general: ['doctor', 'physician', 'medical officer', 'dr.', 'rhu_admin'],
        prenatal: ['midwife', 'ob', 'doctor', 'physician', 'dr.', 'rhu_admin'],
        vaccination: ['nurse'],
        laboratory: ['medtech', 'medical technologist', 'laboratory', 'lab'],
        sanitary: ['sanitary', 'inspector', 'sanitation'],
        dental: ['dental', 'dentist', 'oral health'],
        checkup: ['nurse', 'doctor', 'physician', 'medical officer', 'dr.', 'public health nurse']
      };
      const staffHasAppointmentRole = (staff, role) => {
        const roles = Array.isArray(staff.appointment_roles) ? staff.appointment_roles.map(item => String(item).toLowerCase()) : [];
        if (roles.includes(role)) return true;
        const text = [
          staff.first_name,
          staff.last_name,
          staff.staff_type,
          staff.specialization,
          staff.role,
          staff.position,
          staff.position_title
        ].map(value => String(value || '').toLowerCase()).join(' ');
        return (appointmentRoleKeywords[role] || []).some(keyword => text.includes(keyword));
      };
      const escapeAppointmentHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[char]);

      window.filterAvailableStaff = function() {
        const typeEl = document.getElementById('appointment_type_select');
        const dateEl = document.getElementById('appointment_date_input');
        const container = document.getElementById('staff_selection_container');
        const badgeEl = document.getElementById('staff_count_badge');
        if (!typeEl || !dateEl || !container) return;

        const selectedDate = dateEl.value || '';
        const rule = getAppointmentRule(typeEl.value || '');
        const dateObj = selectedDate ? new Date(`${selectedDate}T00:00:00`) : null;
        const selectedDay = dateObj && !Number.isNaN(dateObj.getTime()) ? ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][dateObj.getDay()] : 'Monday';
        const filtered = appointmentStaff
          .map(staff => ({
            ...staff,
            staff_id: Number.parseInt(staff.staff_id || staff.id || 0, 10),
            staff_type: String(staff.staff_type || staff.position_title || staff.position || staff.role || 'RHU Staff'),
            specialization: String(staff.specialization || staff.position_title || staff.role || ''),
            work_days: staff.work_days || staff.workDays || '',
            appointment_roles: Array.isArray(staff.appointment_roles) ? staff.appointment_roles : []
          }))
          .filter(staff => staff.staff_id > 0 && staffHasAppointmentRole(staff, rule.role))
          .filter(staff => (staff.is_on_duty == null || Number.parseInt(staff.is_on_duty, 10) === 1)
            && (!staff.work_days || String(staff.work_days).toLowerCase().includes(selectedDay.toLowerCase())));

        if (badgeEl) badgeEl.textContent = `${filtered.length} provider(s) available`;
        if (filtered.length === 0) {
          container.innerHTML = `<div class="p-3 text-center text-xs font-semibold text-slate-400">${rule.emptyMessage}</div>`;
          return;
        }

        container.innerHTML = filtered.map(staff => {
          const isOnDuty = staff.is_on_duty == null || Number.parseInt(staff.is_on_duty, 10) === 1;
          const isScheduled = !staff.work_days || String(staff.work_days).toLowerCase().includes(String(selectedDay).toLowerCase());
          const disabled = !isOnDuty || !isScheduled;
          const checkedAttr = '';
          const status = !isOnDuty
            ? '<span class="rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-700">Off Duty</span>'
            : (!isScheduled
              ? `<span class="rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">Not Scheduled (${escapeAppointmentHtml(selectedDay)})</span>`
              : '');
          const name = `${staff.first_name || ''} ${staff.last_name || ''}`.trim();
          return `
            <label class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded-xl border p-2.5 ${disabled ? 'cursor-not-allowed border-slate-200 bg-slate-100/70 opacity-60' : 'cursor-pointer border-slate-200 bg-white hover:border-teal-300 hover:bg-teal-50/50'} transition-all">
              <div class="flex min-w-0 items-center gap-2.5">
                <input type="radio" name="health_worker_id" value="${staff.staff_id}" ${checkedAttr} ${disabled ? 'disabled' : ''} class="h-4 w-4 text-teal-600 focus:ring-teal-500">
                <div class="min-w-0">
                  <p class="truncate text-xs font-bold text-slate-800">${escapeAppointmentHtml(name)}</p>
                  <p class="truncate text-[10px] font-medium text-slate-500">${escapeAppointmentHtml(staff.staff_type)}${staff.specialization ? ' - ' + escapeAppointmentHtml(staff.specialization) : ''}</p>
                  ${staff.work_days ? `<p class="truncate font-mono text-[9px] text-slate-400" title="Duty: ${escapeAppointmentHtml(staff.work_days)}">Duty: ${escapeAppointmentHtml(staff.work_days)}</p>` : ''}
                </div>
              </div>
              <div>${status}</div>
            </label>`;
        }).join('');
      };

      document.getElementById('appointment_type_select')?.addEventListener('change', window.filterAvailableStaff);
      document.getElementById('appointment_date_input')?.addEventListener('change', window.filterAvailableStaff);
      window.filterAvailableStaff();
    })();
  </script>

  <script>
    window.openDependentInfoModal = function(dep) {
      if (!dep) return false;
      const modal = document.getElementById('dependent-info-modal');
      if (!modal) return false;

      const avatarEl = document.getElementById('dep-info-avatar');
      const nameEl = document.getElementById('dep-info-name');
      const bloodEl = document.getElementById('dep-info-blood');
      const subEl = document.getElementById('dep-info-sub');
      const relEl = document.getElementById('dep-info-rel');
      const dobEl = document.getElementById('dep-info-dob');
      const ageEl = document.getElementById('dep-info-age');
      const genderEl = document.getElementById('dep-info-gender');
      const btEl = document.getElementById('dep-info-bt');
      const brgyEl = document.getElementById('dep-info-brgy');
      const addrEl = document.getElementById('dep-info-addr');
      const headEl = document.getElementById('dep-info-head');
      const phoneEl = document.getElementById('dep-info-phone');
      const notesEl = document.getElementById('dep-info-notes');
      const folderEl = document.getElementById('dep-info-record-folder');

      if (nameEl) nameEl.textContent = dep.name || 'Dependent Profile';
      if (avatarEl) {
        const fnLetter = dep.first_name ? dep.first_name[0].toUpperCase() : 'D';
        const lnLetter = dep.last_name ? dep.last_name[0].toUpperCase() : 'P';
        avatarEl.textContent = fnLetter + lnLetter;
      }
      if (bloodEl) bloodEl.textContent = dep.blood_type || 'Blood N/A';
      if (subEl) subEl.textContent = (dep.relationship || 'Family Member') + ' - ' + (dep.age || 'N/A') + ' - ' + (dep.gender || 'Not specified');
      if (relEl) relEl.textContent = dep.relationship || 'Family Member';
      if (dobEl) dobEl.textContent = dep.dob || 'N/A';
      if (ageEl) ageEl.textContent = dep.age || 'N/A';
      if (genderEl) genderEl.textContent = dep.gender || 'Not specified';
      if (btEl) btEl.textContent = dep.blood_type || 'Unknown';
      if (brgyEl) brgyEl.textContent = dep.barangay || 'Nasugbu';
      if (addrEl) addrEl.textContent = dep.address || 'Nasugbu, Batangas';
      if (headEl) headEl.textContent = dep.emergency_contact || 'Head of Household';
      if (phoneEl) phoneEl.textContent = 'Contact #: ' + (dep.emergency_phone || 'N/A');
      if (notesEl) notesEl.textContent = dep.notes || 'No known allergies or medical conditions recorded.';
      if (folderEl) {
        const folder = dep.record_folder || {};
        const consultations = Array.isArray(folder.consultations) ? folder.consultations : [];
        const certificates = Array.isArray(folder.certificates) ? folder.certificates : [];
        const vaccinations = Array.isArray(folder.vaccinations) ? folder.vaccinations : [];
        const pregnancies = Array.isArray(folder.pregnancies) ? folder.pregnancies : [];
        const rows = [];
        consultations.forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Consultation - ${escapeHtml(record.diagnosis || record.chief_complaint || 'Pending OPD Triage')}</p><p class="mt-1 text-[10px] text-slate-500">${escapeHtml(record.consultation_status || 'Scheduled')} | ${escapeHtml(record.consultation_date || 'No date')}${record.icd10 ? ` | ICD-10: ${escapeHtml(record.icd10)}` : ''}</p><p class="mt-1 text-[11px] text-slate-700">${escapeHtml(record.consultation_notes || record.treatment_plan || 'No clinical update yet.')}</p></div>`));
        certificates.forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Certificate - ${escapeHtml(record.certificate_type || 'Health Certificate')}</p><p class="mt-1 text-[10px] text-slate-500">${escapeHtml(record.status || 'Pending')} | ${escapeHtml(record.issue_date || 'No issue date')} | ${escapeHtml(record.certificate_number || '')}</p></div>`));
        vaccinations.forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Vaccination - ${escapeHtml(record.vaccine_name || 'Vaccine')}</p><p class="mt-1 text-[10px] text-slate-500">Dose ${escapeHtml(record.dose_number || '1')} | ${escapeHtml(record.vaccination_date || 'No date')} | Next: ${escapeHtml(record.next_dose_date || 'Not scheduled')}</p></div>`));
        pregnancies.forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Pregnancy record - ${escapeHtml(record.pregnancy_status || 'Active')}</p><p class="mt-1 text-[10px] text-slate-500">EDC: ${escapeHtml(record.expected_delivery_date || 'Not recorded')} | Risk: ${escapeHtml(record.risk_level || 'Routine')}</p></div>`));
        folderEl.innerHTML = rows.length ? rows.join('') : '<p class="rounded-xl bg-white p-3 text-[11px] font-medium text-slate-500">No health records have been recorded for this dependent yet.</p>';
      }

      window.currentSelectedDependent = dep;

      modal.classList.remove('hidden');
      modal.classList.add('flex');
      modal.style.setProperty('display', 'flex', 'important');
      document.body.classList.add('overflow-hidden');
      if (window.lucide) lucide.createIcons();
      return false;
    };

    window.openDependentConsultationFolder = function(dep) {
      const modal = document.getElementById('dependent-consultation-modal');
      const nameEl = document.getElementById('dependent-consultation-name');
      const listEl = document.getElementById('dependent-consultation-list');
      if (!modal || !listEl || !dep) return false;
      const records = Array.isArray(dep.record_folder?.consultations) ? dep.record_folder.consultations : [];
      if (nameEl) nameEl.textContent = `${dep.name || 'Dependent'} - Consultation Records`;
      listEl.innerHTML = records.length ? records.map(record => `
        <article class="rounded-2xl border border-teal-100 bg-teal-50/50 p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div><p class="font-black text-slate-900">${escapeHtml(record.diagnosis || record.chief_complaint || 'Pending OPD Triage')}</p><p class="mt-1 text-[11px] font-semibold text-slate-600">${escapeHtml(record.consultation_status || 'Scheduled')} · ${escapeHtml(record.consultation_date || 'No date')}</p></div>
            ${record.follow_up_date ? `<span class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black text-amber-800">Return: ${escapeHtml(record.follow_up_date)}</span>` : ''}
          </div>
          <p class="mt-3 text-xs text-slate-700"><strong>Chief complaint:</strong> ${escapeHtml(record.chief_complaint || 'None recorded')}</p>
          <p class="mt-2 text-xs text-slate-700"><strong>ICD-10 Code:</strong> ${escapeHtml(record.icd10 || 'None recorded')}</p>
          <p class="mt-2 whitespace-pre-line text-xs text-slate-700"><strong>Clinical update:</strong> ${escapeHtml(record.consultation_notes || record.treatment_plan || 'No clinical update yet.')}</p>
          <p class="mt-2 text-xs text-slate-700"><strong>Medications:</strong> ${escapeHtml(record.medications_prescribed || 'None recorded')}</p>
        </article>`).join('') : '<div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm font-semibold text-slate-500">No consultation records have been recorded for this dependent.</div>';
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      modal.style.setProperty('display', 'flex', 'important');
      document.body.classList.add('overflow-hidden');
      if (window.lucide) lucide.createIcons();
      return false;
    };

    window.openDependentConsultationFolderFromElement = function(card) {
      try { return window.openDependentConsultationFolder(JSON.parse(card.getAttribute('data-dependent-payload') || '{}')); } catch (error) { console.error('Unable to open consultation records:', error); return false; }
    };

    window.closeDependentConsultationFolder = function() {
      const modal = document.getElementById('dependent-consultation-modal');
      if (!modal) return false;
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      modal.style.setProperty('display', 'none', 'important');
      document.body.classList.remove('overflow-hidden');
      return false;
    };

    function normalizeVaccinationDetails(record) {
      const details = { ...record };
      const lines = String(record.remarks || '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
      // Earlier nurse saves placed the site first and reactions second in remarks.
      if (!String(record.site_of_injection || '').trim() && lines.length
          && /^(?:(?:left|right|bilateral)\s+)?(?:deltoid|thigh|arm|upper arm|shoulder|gluteal|buttock|vastus lateralis)\b/i.test(lines[0])) {
        details.site_of_injection = lines.shift();
        if (!String(record.adverse_reactions || '').trim() && lines.length) {
          details.adverse_reactions = lines.join('\n');
          lines.length = 0;
        }
        details.remarks = lines.join('\n');
      }
      return details;
    }

    window.openVaccinationRecordFromElement = function(card) {
      try {
        const record = normalizeVaccinationDetails(JSON.parse(card.getAttribute('data-vaccination-payload') || '{}'));
        const modal = document.getElementById('vaccination-record-modal');
        const title = document.getElementById('vaccination-record-name');
        const details = document.getElementById('vaccination-record-details');
        if (!modal || !details) return false;
        if (title) title.textContent = `${record.resident_name || 'Resident'} - ${record.vaccine_name || 'Vaccine Record'}`;
        const fields = [
          ['Administered by', record.provider_name || 'RHU Staff'],
          ['Dose number', record.dose_number || '1'],
          ['Vaccination date', record.vaccination_date || 'Not recorded'],
          ['Next dose', record.next_dose_date || 'Not scheduled'],
          ['Batch number', record.batch_number || 'Not recorded'],
          ['Injection site', record.site_of_injection || 'Not recorded'],
          ['Adverse reactions', record.adverse_reactions || 'None recorded'],
          ['Remarks', record.remarks || 'No remarks recorded']
        ];
        details.innerHTML = fields.map(([label, value]) => `<div class="rounded-xl border border-slate-100 bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase text-slate-400">${escapeHtml(label)}</p><p class="mt-1 whitespace-pre-line font-semibold text-slate-800">${escapeHtml(value)}</p></div>`).join('');
        modal.classList.remove('hidden'); modal.classList.add('flex'); modal.style.setProperty('display', 'flex', 'important'); document.body.classList.add('overflow-hidden');
        if (window.lucide) lucide.createIcons();
      } catch (error) { console.error('Unable to open vaccination record:', error); }
      return false;
    };

    window.closeVaccinationRecord = function() {
      const modal = document.getElementById('vaccination-record-modal');
      if (!modal) return false;
      modal.classList.add('hidden'); modal.classList.remove('flex'); modal.style.setProperty('display', 'none', 'important'); document.body.classList.remove('overflow-hidden');
      return false;
    };

    window.openDependentInfoModalFromElement = function(card) {
      if (!card) return false;
      try {
        const payload = JSON.parse(card.getAttribute('data-dependent-payload') || '{}');
        return window.openDependentInfoModal(payload);
      } catch (error) {
        console.error('Unable to open participant details:', error);
        return false;
      }
    };

    window.closeDependentInfoModal = function() {
      const modal = document.getElementById('dependent-info-modal');
      if (!modal) return false;
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      modal.style.setProperty('display', 'none', 'important');
      document.body.classList.remove('overflow-hidden');
      return false;
    };

    var openDependentInfoModal = window.openDependentInfoModal;
    var openDependentInfoModalFromElement = window.openDependentInfoModalFromElement;
    var closeDependentInfoModal = window.closeDependentInfoModal;

    (() => {
      if (window.lucide) lucide.createIcons();

      window.allRhuStaff = <?= json_encode(array_values($rhuStaffList ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      let allRhuStaff = Array.isArray(window.allRhuStaff) ? window.allRhuStaff : Object.values(window.allRhuStaff || {});
      const staffBookings = <?= json_encode($staffBookingsPerDate ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      const mainRhu = {
        name: 'Nasugbu Rural Health Unit',
        type: 'Rural Health Unit',
        lat: 14.07423,
        lng: 120.63096,
        address: 'Escalera St., Barangay 2, Nasugbu'
      };
      let residentMap = null;
      let residentMapLayer = null;
      let residentRouteLayer = null;
      let residentPosition = null;
      let residentUserMarker = null;
      let residentLocationWatchId = null;
      let residentWatchedDestination = null;
      let residentNearbyPlaces = [mainRhu];
      let residentLastPlacesPosition = null;
      let residentLastAddressPosition = null;
      let residentLastRouteRefreshAt = 0;
      let mapIsLoading = false;

      const escapeMapText = (value) => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[character]);

      const distanceKm = (from, to) => {
        const radians = degrees => degrees * Math.PI / 180;
        const deltaLat = radians(to.lat - from.lat);
        const deltaLng = radians(to.lng - from.lng);
        const a = Math.sin(deltaLat / 2) ** 2 +
          Math.cos(radians(from.lat)) * Math.cos(radians(to.lat)) * Math.sin(deltaLng / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
      };

      const formatMapDistance = kilometres => kilometres < 1 ?
        `${Math.round(kilometres * 1000)} m` :
        `${kilometres.toFixed(kilometres < 10 ? 1 : 0)} km`;

      const setMapStatus = (message, tone = 'sky') => {
        const status = document.getElementById('map-status');
        if (!status) return;
        const styles = {
          sky: 'border-sky-200 bg-sky-50 text-sky-800',
          teal: 'border-teal-200 bg-teal-50 text-teal-800',
          rose: 'border-rose-200 bg-rose-50 text-rose-800',
          amber: 'border-amber-200 bg-amber-50 text-amber-800'
        };
        status.className = `flex items-center gap-2 rounded-xl border p-3 text-xs font-semibold ${styles[tone] || styles.sky}`;
        status.querySelector('span').textContent = message;
      };

      const updateLiveLocationCard = (name, address) => {
        const card = document.getElementById('map-live-location-card');
        const nameElement = document.getElementById('map-live-location-name');
        const addressElement = document.getElementById('map-live-location-address');
        if (!card || !nameElement || !addressElement) return;
        card.classList.remove('hidden');
        nameElement.textContent = name || 'Your current location';
        addressElement.textContent = address || `${residentPosition.lat.toFixed(5)}, ${residentPosition.lng.toFixed(5)}`;
      };

      const updateLiveLocationAddress = async () => {
        if (!residentPosition) return;
        const movedSinceAddressRefresh = residentLastAddressPosition ? distanceKm(residentLastAddressPosition, residentPosition) : Infinity;
        if (residentLastAddressPosition && movedSinceAddressRefresh < .25) return;
        residentLastAddressPosition = {
          ...residentPosition
        };
        updateLiveLocationCard('Finding your place...', `${residentPosition.lat.toFixed(5)}, ${residentPosition.lng.toFixed(5)}`);
        try {
          const endpoint = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${residentPosition.lat}&lon=${residentPosition.lng}&zoom=18&addressdetails=1`;
          const response = await fetch(endpoint, {
            headers: {
              'Accept': 'application/json'
            }
          });
          if (!response.ok) throw new Error('Reverse geocoding unavailable');
          const data = await response.json();
          const address = data.address || {};
          const placeName = address.neighbourhood || address.suburb || address.village || address.town || address.city || address.municipality || address.county || data.name || 'Your current area';
          const displayAddress = data.display_name || `${residentPosition.lat.toFixed(5)}, ${residentPosition.lng.toFixed(5)}`;
          updateLiveLocationCard(placeName, displayAddress);
          if (residentUserMarker) {
            residentUserMarker.setPopupContent(`<strong>You are here</strong><br><small>${escapeMapText(displayAddress)}</small>`);
          }
        } catch (error) {
          updateLiveLocationCard('Your current location', `${residentPosition.lat.toFixed(5)}, ${residentPosition.lng.toFixed(5)}`);
        }
      };

      const mapPlaceIcon = (place) => L.divIcon({
        className: '',
        html: `<span style="display:flex;width:30px;height:30px;align-items:center;justify-content:center;border:3px solid white;border-radius:999px;background:${place.type === 'Barangay' ? '#7c3aed' : '#0f766e'};color:white;box-shadow:0 2px 8px rgba(15,23,42,.3);font-size:14px">${place.type === 'Barangay' ? 'Home' : 'Health'}</span>`,
        iconSize: [30, 30],
        iconAnchor: [15, 15]
      });

      const renderNearbyPlaces = places => {
        const list = document.getElementById('nearby-location-list');
        const count = document.getElementById('map-result-count');
        if (!list) return;
        const sorted = places.map(place => ({
          ...place,
          distance: residentPosition ? distanceKm(residentPosition, place) : null
        })).sort((a, b) => (a.distance ?? 9999) - (b.distance ?? 9999));

        count.textContent = `${sorted.length} location${sorted.length === 1 ? '' : 's'}`;
        list.innerHTML = sorted.map(place => {
          const isBarangay = place.type === 'Barangay';
          const accent = isBarangay ? 'violet' : 'teal';
          const distance = place.distance === null ? 'Enable location' : formatMapDistance(place.distance);
          return `<article class="rounded-2xl border border-${accent}-200 bg-${accent}-50/60 p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0"><span class="text-[9px] font-black uppercase tracking-wider text-${accent}-700">${escapeMapText(place.type)}</span>
                <h4 class="mt-1 truncate text-sm font-black text-slate-900" title="${escapeMapText(place.name)}">${escapeMapText(place.name)}</h4>
                <p class="mt-1 text-[11px] text-slate-500">${escapeMapText(place.address || (isBarangay ? 'Barangay location' : 'Health facility'))}</p>
              </div>
              <span class="rounded-lg bg-white p-2 text-${accent}-700">${isBarangay ? 'Home' : 'Health'}</span>
            </div>
            <p class="mt-3 text-xs font-bold text-slate-500">Distance: <strong class="text-slate-900">${distance}</strong></p>
            <div class="mt-3 flex gap-4">
              <button type="button" data-map-focus="${place.lat},${place.lng}" class="text-xs font-bold text-slate-600 hover:underline">Show on map</button>
              <button type="button" data-map-route="${place.lat},${place.lng}" data-map-route-name="${escapeMapText(place.name)}" class="inline-flex items-center gap-1 text-xs font-bold text-${accent}-700 hover:underline">Show directions -></button>
            </div>
          </article>`;
        }).join('');

        list.querySelectorAll('[data-map-focus]').forEach(button => button.addEventListener('click', () => {
          const [lat, lng] = button.dataset.mapFocus.split(',').map(Number);
          residentMap.setView([lat, lng], 16);
        }));
        list.querySelectorAll('[data-map-route]').forEach(button => button.addEventListener('click', () => {
          const [lat, lng] = button.dataset.mapRoute.split(',').map(Number);
          showResidentRoute({
            lat,
            lng,
            name: button.dataset.mapRouteName
          });
        }));
      };

      const showResidentRoute = async destination => {
        if (!residentPosition) {
          setMapStatus('Use your current location first before requesting directions.', 'amber');
          return;
        }
        residentWatchedDestination = destination;
        residentLastRouteRefreshAt = Date.now();
        setMapStatus(`Calculating the driving route to ${destination.name}...`);
        const endpoint = `https://router.project-osrm.org/route/v1/driving/${residentPosition.lng},${residentPosition.lat};${destination.lng},${destination.lat}?overview=full&geometries=geojson&steps=true`;
        try {
          const response = await fetch(endpoint);
          if (!response.ok) throw new Error('Routing service unavailable');
          const data = await response.json();
          const route = data.routes?.[0];
          if (!route) throw new Error('No route found');
          if (residentRouteLayer) residentRouteLayer.remove();
          residentRouteLayer = L.geoJSON(route.geometry, {
            style: {
              color: '#0f766e',
              weight: 6,
              opacity: .9,
              lineCap: 'round',
              lineJoin: 'round'
            }
          }).addTo(residentMap);
          residentMap.fitBounds(residentRouteLayer.getBounds(), {
            padding: [35, 35]
          });
          const drivingDistance = formatMapDistance(route.distance / 1000);
          const minutes = Math.max(1, Math.round(route.duration / 60));
          setMapStatus(`Driving route to ${destination.name}: approximately ${drivingDistance}, ${minutes} min.`, 'teal');
        } catch (error) {
          setMapStatus('A road route could not be calculated right now. Please try again in a moment.', 'rose');
        }
      };

      const loadNearbyMapPlaces = async () => {
        if (!residentPosition || mapIsLoading) return;
        mapIsLoading = true;
        setMapStatus('Google Maps is finding nearby RHUs, health centers and barangay locations...');
        const {
          lat,
          lng
        } = residentPosition;
        try {
          const location = new google.maps.LatLng(lat, lng);
          const [healthResults, barangayResults] = await Promise.all([
            googlePlacesSearch({
              location,
              radius: 15000,
              keyword: 'RHU rural health unit health center clinic hospital'
            }),
            googlePlacesSearch({
              location,
              radius: 15000,
              keyword: 'barangay hall barangay health center'
            })
          ]);
          const found = [...healthResults, ...barangayResults].map(place => {
            const barangay = /barangay|brgy/i.test(place.name || '');
            return {
              name: place.name || (barangay ? 'Barangay facility' : 'Community health facility'),
              type: barangay ? 'Barangay' : (/hospital/i.test(place.name || '') ? 'Hospital' : 'Health Center / RHU'),
              lat: place.geometry.location.lat(),
              lng: place.geometry.location.lng(),
              address: place.vicinity || ''
            };
          });

          const unique = [mainRhu, ...found].filter((place, index, array) =>
            array.findIndex(other => other.name.toLowerCase() === place.name.toLowerCase()) === index
          ).sort((a, b) => distanceKm(residentPosition, a) - distanceKm(residentPosition, b)).slice(0, 16);
          unique.forEach(place => addGoogleMarker(place, place.type === 'Barangay' ? '#7c3aed' : '#0f766e'));
          renderNearbyPlaces(unique);
          setMapStatus(`Showing ${unique.length} nearby locations, ordered by distance from you.`, 'teal');
        } catch (error) {
          addGoogleMarker(mainRhu);
          renderNearbyPlaces([mainRhu]);
          setMapStatus('Your location is shown, but Google Places could not load nearby results. Check that Places API is enabled.', 'amber');
        } finally {
          mapIsLoading = false;
        }
      };

      function initializeResidentMap() {
        initializeLeafletMap();
      }

      const locateResident = () => {
        initializeResidentMap();
        if (!residentMap) {
          setMapStatus('Google Maps is still loading. Please try again in a moment.', 'amber');
          return;
        }
        if (!navigator.geolocation) {
          setMapStatus('Location is not supported by this browser. You can still view and navigate to the RHU.', 'rose');
          return;
        }
        const button = document.getElementById('map-locate-button');
        button.disabled = true;
        button.querySelector('span').textContent = 'Locating...';
        setMapStatus('Requesting your current location...');
        navigator.geolocation.getCurrentPosition(position => {
          residentPosition = {
            lat: position.coords.latitude,
            lng: position.coords.longitude
          };
          shareResidentDeviceLocation(position);
          mapMarkers.forEach(marker => marker.setMap(null));
          mapMarkers = [];
          addGoogleMarker({
            ...residentPosition,
            name: 'Your current location',
            type: 'You'
          }, '#2563eb');
          residentMap.setCenter(residentPosition);
          residentMap.setZoom(14);
          loadNearbyMapPlaces();
          button.disabled = false;
          button.querySelector('span').textContent = 'Refresh my location';
        }, error => {
          const messages = {
            1: 'Location permission was denied. Enable it in your browser settings to calculate distances.',
            2: 'Your location is currently unavailable. Check your device location settings and try again.',
            3: 'Finding your location timed out. Please try again.'
          };
          setMapStatus(messages[error.code] || 'Your location could not be detected.', 'rose');
          button.disabled = false;
          button.querySelector('span').textContent = 'Try location again';
        }, {
          enableHighAccuracy: true,
          timeout: 12000,
          maximumAge: 60000
        });
      };

      const loadOpenStreetMapPlaces = async () => {
        if (!residentPosition || mapIsLoading) return;
        mapIsLoading = true;
        setMapStatus('Finding nearby RHUs, health centers and barangay locations...');
        const {
          lat,
          lng
        } = residentPosition;
        const query = `[out:json][timeout:20];(
          nwr(around:15000,${lat},${lng})["amenity"~"clinic|doctors|hospital|health_post"];
          nwr(around:15000,${lat},${lng})["healthcare"~"clinic|doctor|hospital|centre"];
          nwr(around:15000,${lat},${lng})["amenity"~"townhall|community_centre"]["name"~"Barangay|Brgy",i];
          node(around:15000,${lat},${lng})["place"="barangay"];
        );out center tags;`;
        try {
          const response = await fetch('https://overpass-api.de/api/interpreter', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: `data=${encodeURIComponent(query)}`
          });
          if (!response.ok) throw new Error('Map service unavailable');
          const data = await response.json();
          const found = data.elements.map(element => {
            const point = element.center || element;
            const tags = element.tags || {};
            const barangay = tags.place === 'barangay' || (/barangay|brgy/i.test(tags.name || '') && /townhall|community_centre/.test(tags.amenity || ''));
            return {
              name: tags.name || (barangay ? 'Barangay facility' : 'Community health facility'),
              type: barangay ? 'Barangay' : (/hospital/i.test(tags.amenity || tags.healthcare || '') ? 'Hospital' : 'Health Center / RHU'),
              lat: Number(point.lat),
              lng: Number(point.lon),
              address: [tags['addr:street'], tags['addr:barangay']].filter(Boolean).join(', ')
            };
          }).filter(place => Number.isFinite(place.lat) && Number.isFinite(place.lng));
          const unique = [mainRhu, ...found].filter((place, index, array) =>
            array.findIndex(other => other.name.toLowerCase() === place.name.toLowerCase()) === index
          ).sort((a, b) => distanceKm(residentPosition, a) - distanceKm(residentPosition, b)).slice(0, 16);
          residentNearbyPlaces = unique;
          residentMapLayer.clearLayers();
          unique.forEach(place => L.marker([place.lat, place.lng], {
              icon: mapPlaceIcon(place)
            })
            .bindPopup(`<strong>${escapeMapText(place.name)}</strong><br><small>${escapeMapText(place.type)} - ${formatMapDistance(distanceKm(residentPosition, place))}</small>`)
            .addTo(residentMapLayer));
          renderNearbyPlaces(unique);
          setMapStatus(`Showing ${unique.length} nearby locations, ordered by distance from you.`, 'teal');
        } catch (error) {
          residentMapLayer.clearLayers();
          L.marker([mainRhu.lat, mainRhu.lng], {
            icon: mapPlaceIcon(mainRhu)
          }).bindPopup(mainRhu.name).addTo(residentMapLayer);
          residentNearbyPlaces = [mainRhu];
          renderNearbyPlaces(residentNearbyPlaces);
          setMapStatus('Your location is shown, but nearby community data could not be loaded. The main RHU remains available.', 'amber');
        } finally {
          mapIsLoading = false;
        }
      };

      function initializeLeafletMap() {
        if (!window.L) {
          window.setTimeout(initializeLeafletMap, 150);
          return;
        }
        if (!residentMap) {
          residentMap = L.map('resident-location-map', {
            zoomControl: true
          }).setView([mainRhu.lat, mainRhu.lng], 14);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
          }).addTo(residentMap);
          residentMapLayer = L.layerGroup().addTo(residentMap);
          L.marker([mainRhu.lat, mainRhu.lng], {
              icon: mapPlaceIcon(mainRhu)
            })
            .bindPopup(`<strong>${mainRhu.name}</strong><br><small>${mainRhu.address}</small>`)
            .addTo(residentMapLayer);
        }
        window.setTimeout(() => residentMap.invalidateSize(), 50);
      }

      const shareResidentDeviceLocation = (() => {
        let lastSentAt = 0;
        let lastSentPosition = null;
        return async (position) => {
          if (!position || !position.coords) return;
          const point = {
            lat: Number(position.coords.latitude),
            lng: Number(position.coords.longitude)
          };
          if (!Number.isFinite(point.lat) || !Number.isFinite(point.lng)) return;
          const now = Date.now();
          const movedKm = lastSentPosition ? distanceKm(lastSentPosition, point) : Infinity;
          if (lastSentPosition && movedKm < 0.02 && now - lastSentAt < 30000) return;
          lastSentAt = now;
          lastSentPosition = point;
          try {
            await fetch('ResidentDashboard.php?api=save_resident_location', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                latitude: point.lat,
                longitude: point.lng,
                accuracy: Number.isFinite(position.coords.accuracy) ? Math.round(position.coords.accuracy) : null
              })
            });
          } catch (error) {
            console.warn('Resident location share failed', error);
          }
        };
      })();

      const updateResidentLivePosition = (position, shouldCenter = false) => {
        const previousPosition = residentPosition;
        residentPosition = {
          lat: position.coords.latitude,
          lng: position.coords.longitude
        };
        shareResidentDeviceLocation(position);
        const accuracy = Number.isFinite(position.coords.accuracy) ? Math.round(position.coords.accuracy) : null;
        const positionLatLng = [residentPosition.lat, residentPosition.lng];

        if (!residentUserMarker) {
          residentUserMarker = L.marker(positionLatLng, {
            icon: L.divIcon({
              className: '',
              html: '<div class="map-user-marker"></div>',
              iconSize: [44, 44],
              iconAnchor: [22, 22]
            })
          }).bindPopup('<strong>You are here</strong><br><small>Live GPS tracking is active.</small>').addTo(residentMap).openPopup();
        } else {
          residentUserMarker.setLatLng(positionLatLng);
        }

        if (shouldCenter || !previousPosition) {
          residentMap.setView(positionLatLng, 14);
        } else if (residentLocationWatchId !== null) {
          residentMap.panTo(positionLatLng, {
            animate: true,
            duration: .8
          });
        }

        updateLiveLocationCard('Your current location', `${residentPosition.lat.toFixed(5)}, ${residentPosition.lng.toFixed(5)}`);
        updateLiveLocationAddress();

        const movedSincePlacesRefresh = residentLastPlacesPosition ? distanceKm(residentLastPlacesPosition, residentPosition) : Infinity;
        if (!residentLastPlacesPosition || movedSincePlacesRefresh >= 1) {
          residentLastPlacesPosition = {
            ...residentPosition
          };
          loadOpenStreetMapPlaces();
        } else {
          renderNearbyPlaces(residentNearbyPlaces);
        }

        const now = Date.now();
        if (residentWatchedDestination && now - residentLastRouteRefreshAt > 20000) {
          residentLastRouteRefreshAt = now;
          showResidentRoute(residentWatchedDestination);
        } else if (residentLocationWatchId !== null) {
          setMapStatus(`Live tracking is active${accuracy ? `, GPS accuracy about ${accuracy} m` : ''}.`, 'teal');
        }
      };

      const stopResidentLiveTracking = () => {
        if (residentLocationWatchId !== null) {
          navigator.geolocation.clearWatch(residentLocationWatchId);
          residentLocationWatchId = null;
        }
        const button = document.getElementById('map-locate-button');
        if (button) button.querySelector('span').textContent = 'Start live tracking';
        setMapStatus('Live tracking stopped. Tap Start live tracking to follow your location again.', 'amber');
      };

      const locateResidentWithLeaflet = () => {
        initializeLeafletMap();
        if (!window.isSecureContext && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
          setMapStatus('Location requires HTTPS. Open this site using https://, then tap Use my location again.', 'amber');
          return;
        }
        if (!navigator.geolocation) {
          setMapStatus('Location is not supported by this browser. You can still view and navigate to the RHU.', 'rose');
          return;
        }
        const button = document.getElementById('map-locate-button');
        if (residentLocationWatchId !== null) {
          stopResidentLiveTracking();
          return;
        }
        button.disabled = true;
        button.querySelector('span').textContent = 'Starting...';
        setMapStatus('Starting live location tracking...');
        let firstUpdate = true;
        residentLocationWatchId = navigator.geolocation.watchPosition(position => {
          updateResidentLivePosition(position, firstUpdate);
          firstUpdate = false;
          button.disabled = false;
          button.querySelector('span').textContent = 'Stop live tracking';
        }, error => {
          const messages = {
            1: 'Location is blocked for this site. In your browser site settings, allow Location for this domain, then reload and try again.',
            2: 'Your location is currently unavailable. Check your device location settings and try again.',
            3: 'Finding your location timed out. Please try again.'
          };
          if (residentLocationWatchId !== null) {
            navigator.geolocation.clearWatch(residentLocationWatchId);
            residentLocationWatchId = null;
          }
          setMapStatus(messages[error.code] || 'Your location could not be detected.', 'rose');
          button.disabled = false;
          button.querySelector('span').textContent = 'Try live tracking again';
        }, {
          enableHighAccuracy: true,
          timeout: 15000,
          maximumAge: 5000
        });
      };

      document.getElementById('map-locate-button')?.addEventListener('click', locateResidentWithLeaflet);
      document.getElementById('main-rhu-route-button')?.addEventListener('click', () => showResidentRoute(mainRhu));

      const appointmentProviderRules = [{
          pattern: /prenatal|maternal/i,
          role: 'prenatal',
          keywords: ['midwife', 'ob', 'doctor', 'physician', 'dr.', 'rhu_admin'],
          emptyMessage: 'No midwife or doctor is available for this appointment type.'
        },
        {
          pattern: /child|vaccination|immunization|vaccine/i,
          role: 'vaccination',
          keywords: ['nurse'],
          emptyMessage: 'No nurse is available for this vaccination appointment.'
        },
        {
          pattern: /laboratory|blood|test|diagnostic/i,
          role: 'laboratory',
          keywords: ['medtech', 'medical technologist', 'laboratory', 'lab'],
          emptyMessage: 'No medical technologist is available for this laboratory appointment.'
        },
        {
          pattern: /sanitary|inspection|clearance/i,
          role: 'sanitary',
          keywords: ['sanitary', 'inspector', 'sanitation'],
          emptyMessage: 'No sanitary inspector is available for this appointment type.'
        },
        {
          pattern: /dental|dentist|tooth|oral/i,
          role: 'dental',
          keywords: ['dental', 'dentist', 'oral health'],
          emptyMessage: 'No dental health staff is available for this appointment type.'
        },
        {
          pattern: /health checkup|checkup/i,
          role: 'checkup',
          keywords: ['nurse', 'doctor', 'physician', 'medical officer', 'dr.', 'public health nurse'],
          emptyMessage: 'No nurse or doctor is available for this checkup.'
        }
      ];

      const getAppointmentProviderRule = appointmentType => {
        const selected = appointmentProviderRules.find(rule => rule.pattern.test(appointmentType || ''));
        return selected || {
          role: 'general',
          keywords: ['doctor', 'physician', 'medical officer', 'dr.', 'rhu_admin'],
          emptyMessage: 'No doctor or physician is available for this appointment type.'
        };
      };

      const staffMatchesAppointmentType = (staff, appointmentType) => {
        const rule = getAppointmentProviderRule(appointmentType);
        const staffRoles = Array.isArray(staff.appointment_roles) ? staff.appointment_roles.map(role => String(role).toLowerCase()) : [];
        if (staffRoles.includes(rule.role)) return true;
        const providerText = [
          staff.first_name,
          staff.last_name,
          staff.staff_type,
          staff.specialization,
          staff.role,
          staff.position,
          staff.position_title
        ].map(value => String(value || '').toLowerCase()).join(' ');
        if (rule.keywords.some(keyword => providerText.includes(keyword))) return true;
        return false;
      };

      const legacyFilterAvailableStaff = function() {
        const typeEl = document.getElementById('appointment_type_select');
        const dateEl = document.getElementById('appointment_date_input');
        const container = document.getElementById('staff_selection_container');
        const badgeEl = document.getElementById('staff_count_badge');

        if (!container || !typeEl || !dateEl) return;
        container.innerHTML = '';

        const selectedType = typeEl.value;
        const selectedDate = dateEl.value;
        let filteredStaff = allRhuStaff
          .map(staff => ({
            ...staff,
            staff_id: Number.parseInt(staff.staff_id || staff.id || 0, 10),
            staff_type: String(staff.staff_type || staff.position || staff.position_title || staff.role || 'RHU Staff'),
            specialization: String(staff.specialization || staff.role || staff.position_title || staff.position || ''),
            work_days: staff.work_days || staff.workDays || 'Monday, Tuesday, Wednesday, Thursday, Friday'
          }))
          .filter(staff => staff.staff_id > 0)
          .filter(staff => staffMatchesAppointmentType(staff, selectedType));

        if (badgeEl) {
          badgeEl.textContent = `${filteredStaff.length} provider(s) available`;
        }

        if (filteredStaff.length === 0) {
          const emptyMessage = getAppointmentProviderRule(selectedType).emptyMessage;
          container.innerHTML = `<div class="p-3 text-center text-slate-400 text-xs font-semibold">${emptyMessage}</div>`;
          return;
        }

        const daysOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        let selectedDayName = 'Monday';
        if (selectedDate) {
          const parts = selectedDate.split('-');
          if (parts.length === 3) {
            const dObj = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            selectedDayName = daysOfWeek[dObj.getDay()];
          }
        }

        let hasCheckedFirst = false;

        filteredStaff.forEach((staff, index) => {
          const pid = staff.staff_id;
          const isOnDuty = parseInt(staff.is_on_duty || 1) === 1;
          const workDaysStr = staff.work_days || 'Monday, Tuesday, Wednesday, Thursday, Friday';
          const isScheduled = workDaysStr.toLowerCase().includes(selectedDayName.toLowerCase());

          let statusBadge = '';
          let isDisabled = false;

          if (!isOnDuty) {
            statusBadge = `<span class="inline-flex items-center gap-1 text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-md border border-rose-200"> Off Duty (On Leave)</span>`;
            isDisabled = true;
          } else if (!isScheduled) {
            statusBadge = `<span class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200"> Not Scheduled (${selectedDayName})</span>`;
            isDisabled = true;
          }

          const label = document.createElement('label');
          label.className = `grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded-xl border p-2.5 ${isDisabled ? 'border-slate-200 bg-slate-100/70 opacity-60 cursor-not-allowed' : 'border-slate-200 bg-white hover:bg-teal-50/50 hover:border-teal-300 cursor-pointer'} transition-all`;

          let checkAttr = '';
          if (!isDisabled && !hasCheckedFirst) {
            checkAttr = 'checked';
            hasCheckedFirst = true;
          }
          let disabledAttr = isDisabled ? 'disabled' : '';

          label.innerHTML = `
            <div class="flex min-w-0 items-center gap-2.5">
              <input type="radio" name="health_worker_id" value="${staff.staff_id}" ${checkAttr} ${disabledAttr} class="text-teal-600 focus:ring-teal-500 h-4 w-4">
              <div class="min-w-0">
                <p class="truncate font-bold text-slate-800 text-xs">${staff.first_name || ''} ${staff.last_name || ''}</p>
                <p class="truncate text-[10px] text-slate-500 font-medium">${staff.staff_type || 'RHU Staff'} ${staff.specialization ? '- ' + staff.specialization : ''}</p>
                <p class="truncate text-[9px] text-slate-400 font-mono" title="Duty: ${workDaysStr}"> Duty: ${workDaysStr}</p>
              </div>
            </div>
            <div>${statusBadge}</div>
          `;
          container.appendChild(label);
        });
      };

      filterAvailableStaff();

      const appointmentModal = document.getElementById('appointment-modal');
      const primaryResidentId = <?= (int)$residentId ?>;
      let appointmentTrigger = null;

      // Keep the dialog outside animated/transformed dashboard containers so
      // fixed positioning is calculated against the actual browser viewport.
      if (appointmentModal && appointmentModal.parentElement !== document.body) {
        document.body.appendChild(appointmentModal);
      }

      const openAppointmentModal = trigger => {
        if (!appointmentModal) return;
        appointmentTrigger = trigger || document.activeElement;
        if (trigger?.matches?.('[data-appointment-open]')) {
          const patientInput = document.getElementById('appointment_patient_resident_id');
          if (patientInput) patientInput.value = String(primaryResidentId);
        }
        appointmentModal.style.display = 'flex';
        document.body.classList.add('overflow-hidden');
        filterAvailableStaff();
        window.setTimeout(() => {
          appointmentModal.querySelector('select, input, textarea, button')?.focus();
        }, 0);
      };
      window.openAppointmentModal = openAppointmentModal;

      const closeAppointmentModal = () => {
        if (!appointmentModal) return;
        appointmentModal.style.display = 'none';
        document.body.classList.remove('overflow-hidden');
        appointmentTrigger?.focus?.();
      };

      document.addEventListener('click', event => {
        const button = event.target.closest('[data-appointment-open]');
        if (button) openAppointmentModal(button);
      });
      document.querySelectorAll('[data-appointment-close]').forEach(button => {
        button.addEventListener('click', closeAppointmentModal);
      });
      appointmentModal?.addEventListener('click', event => {
        if (event.target === appointmentModal) closeAppointmentModal();
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && appointmentModal && appointmentModal.style.display !== 'none') {
          closeAppointmentModal();
        }
      });

      const sidebar = document.getElementById('sidebar');
      const sidebarOverlay = document.getElementById('sidebar-overlay');
      const collapseBtn = document.getElementById('sidebar-collapse-btn');
      const mobileBtn = document.getElementById('mobile-menu-btn');
      const pageTitle = document.getElementById('current-page-title');

      const buttons = document.querySelectorAll('[data-tab-button]');
      const panels = document.querySelectorAll('[data-tab-panel]');

      const tabTitles = {
        'home': 'Overview',
        'profile': 'My Health Profile',
        'records': 'Health Records',
        'immunization': 'Immunization Records',
        'certificates': 'Health Certificates',
        'family': 'Family Members',
        'events': 'Events & Programs',
        'map': 'Nearby Map',
        'contact': 'Contact RHU',
        'emergency': 'Emergency & Referral'
      };

      if (collapseBtn) {
        collapseBtn.addEventListener('click', () => {
          sidebar.classList.toggle('sidebar-collapsed');
          const collapsed = sidebar.classList.contains('sidebar-collapsed');
          document.body.classList.toggle('sidebar-is-collapsed', collapsed);
          collapseBtn.innerHTML = collapsed ?
            '<i data-lucide="panel-left-open" class="h-4 w-4"></i>' :
            '<i data-lucide="panel-left-close" class="h-4 w-4"></i>';
          if (window.lucide) lucide.createIcons();
        });
      }

      const toggleMobileSidebar = (event) => {
        if (event) {
          event.preventDefault();
          event.stopPropagation();
        }
        const isOpen = !sidebar.classList.contains('-translate-x-full');
        if (isOpen) {
          sidebar.classList.add('-translate-x-full');
          sidebarOverlay.classList.add('hidden');
          sidebarOverlay.setAttribute('aria-hidden', 'true');
          document.body.classList.remove('overflow-hidden');
        } else {
          sidebar.classList.remove('-translate-x-full');
          sidebarOverlay.classList.remove('hidden');
          sidebarOverlay.setAttribute('aria-hidden', 'false');
          document.body.classList.add('overflow-hidden');
        }
      };
      window.toggleMobileSidebar = toggleMobileSidebar;

      if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleMobileSidebar);

      const sidebarBreakpoint = window.matchMedia('(min-width: 768px)');
      const resetSidebarForViewport = () => {
        sidebar.classList.add('-translate-x-full');
        sidebarOverlay.classList.add('hidden');
        sidebarOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
        if (!sidebarBreakpoint.matches) {
          sidebar.classList.remove('sidebar-collapsed');
          document.body.classList.remove('sidebar-is-collapsed');
          if (collapseBtn) {
            collapseBtn.innerHTML = '<i data-lucide="panel-left-close" class="h-4 w-4"></i>';
            if (window.lucide) lucide.createIcons();
          }
        }
      };
      sidebarBreakpoint.addEventListener('change', resetSidebarForViewport);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !sidebarBreakpoint.matches && !sidebar.classList.contains('-translate-x-full')) {
          toggleMobileSidebar(event);
        }
      });

      const setTab = (tab, options = {}) => {
        if (!tabTitles[tab]) tab = 'home';
        panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.tabPanel !== tab));

        buttons.forEach(button => {
          const active = button.dataset.tabButton === tab;
          button.classList.toggle('nav-active', active);
        });

        try {
          localStorage.setItem('rhu_resident_active_tab', tab);
        } catch (e) {}

        if (!options.skipUrlUpdate) {
          const nextUrl = new URL(window.location.href);
          nextUrl.searchParams.set('tab', tab);
          window.history.replaceState(window.history.state, '', nextUrl.toString());
        }

        // Breadcrumb logic na may Clickable "Resident Dashboard"
        if (pageTitle && tabTitles[tab]) {
          if (tab === 'home') {
            pageTitle.innerHTML = `<span class="font-bold text-slate-800">Resident Dashboard</span>`;
          } else {
            pageTitle.innerHTML = `
            <button type="button" data-breadcrumb-home class="text-slate-400 font-medium hover:text-teal-600 hover:underline transition-colors focus:outline-none">
              Resident Dashboard
            </button>
            <i data-lucide="chevron-right" class="inline-block h-4 w-4 text-slate-400 mx-1"></i>
            <span class="font-bold text-slate-800">${tabTitles[tab]}</span>
          `;

            // Lagyan ng click event para kapag pinindot ang "Resident Dashboard" ay babalik sa home tab
            const homeBreadcrumbBtn = pageTitle.querySelector('[data-breadcrumb-home]');
            if (homeBreadcrumbBtn) {
              homeBreadcrumbBtn.addEventListener('click', () => setTab('home'));
            }

            // I-re-render ang Lucide chevron icon
            if (window.lucide) lucide.createIcons();
          }
        }

        if (window.innerWidth < 768) {
          sidebar.classList.add('-translate-x-full');
          sidebarOverlay.classList.add('hidden');
          sidebarOverlay.setAttribute('aria-hidden', 'true');
          document.body.classList.remove('overflow-hidden');
        }

        if (!options.keepScroll) {
          window.scrollTo({
            top: 0,
            behavior: 'smooth'
          });
        }
        if (tab === 'map') window.setTimeout(initializeLeafletMap, 80);
      };

      buttons.forEach(button => button.addEventListener('click', () => setTab(button.dataset.tabButton)));
      document.addEventListener('click', event => {
        const button = event.target.closest('[data-tab-link]');
        if (button) setTab(button.dataset.tabLink);
      });

      const urlParams = new URLSearchParams(window.location.search);
      const initialTab = urlParams.get('tab');
      if (initialTab && tabTitles[initialTab]) {
        setTab(initialTab, { keepScroll: true });
      } else {
        setTab('home', { keepScroll: true });
      }

      const residentMessageList = document.querySelector('[data-resident-message-list]');
      let residentMessageSignature = '';

      const formatResidentMessageHtml = (message) => {
        const status = String(message.admin_reply || '').trim() ? 'Replied' : (message.status || 'Pending');
        const reply = message.admin_reply
          ? `<div class="mt-2 rounded-lg border-l-4 border-teal-500 bg-teal-50 p-2 text-[11px] font-medium text-teal-900">
              <p class="font-black">RHU Reply</p>
              <p class="mt-0.5">${escapeHtml(message.admin_reply)}</p>
              ${message.replied_at_display ? `<p class="mt-1 text-[9px] font-semibold text-teal-700">${escapeHtml(message.replied_at_display)}</p>` : ''}
            </div>`
          : '';
        return `<div class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs">
            <div class="flex justify-between gap-3 font-bold text-slate-800">
              <span>${escapeHtml(message.subject || 'General Inquiry')}</span>
              <span class="shrink-0 text-[10px] font-semibold ${status === 'Replied' ? 'text-teal-600' : 'text-amber-600'}">${escapeHtml(status)}</span>
            </div>
            <p class="mt-1 text-slate-600 font-medium text-[11px]">${escapeHtml(message.message || '')}</p>
            <p class="mt-2 text-[9px] text-slate-400">${escapeHtml(message.created_at_display || message.created_at || '')}</p>
            ${reply}
          </div>`;
      };

      const renderResidentMessages = (messages) => {
        if (!residentMessageList) return;
        if (!messages.length) {
          residentMessageList.innerHTML = '<p class="text-xs text-slate-400 font-medium text-center py-4">No sent messages yet</p>';
          return;
        }
        residentMessageList.innerHTML = messages.map(formatResidentMessageHtml).join('');
      };

      const fetchResidentMessages = async () => {
        if (!residentMessageList) return;
        try {
          const url = new URL(window.location.href);
          url.search = '';
          url.searchParams.set('api', 'get_messages');
          url.searchParams.set('_', String(Date.now()));
          const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' }
          });
          if (!response.ok) return;
          const data = await response.json();
          if (!data.success) return;
          const messages = data.messages || [];
          const nextSignature = messages.map((message) => `${message.id}:${message.status}:${message.admin_reply || ''}:${message.created_at}`).join('|');
          if (nextSignature !== residentMessageSignature) {
            residentMessageSignature = nextSignature;
            renderResidentMessages(messages);
          }
        } catch (error) {
          console.error('Failed to refresh resident messages:', error);
        }
      };

      document.querySelector('[data-contact-message-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submitButton = form.querySelector('button[type="submit"]');
        const originalText = submitButton ? submitButton.textContent : '';
        try {
          if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Sending...';
          }
          const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
          });
          if (!response.ok) throw new Error('Message request failed.');
          form.reset();
          await fetchResidentMessages();
          await fetchNotifications?.();
        } catch (error) {
          console.error('Failed to send resident message:', error);
          form.submit();
        } finally {
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalText;
          }
        }
      });

      fetchResidentMessages();
      window.setInterval(fetchResidentMessages, 3000);

      (function () {
        const list = document.getElementById('records-list-container');
        if (!list) return;

        const perPage = 4;
        let latestFingerprint = '';
        let isPolling = false;

        const escapeRecordHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        }[char]));
        const statusClass = status => {
          const raw = String(status || '').toLowerCase();
          if (raw.includes('completed')) return 'bg-emerald-100 text-emerald-800 border-emerald-300';
          if (raw.includes('progress')) return 'bg-blue-100 text-blue-800 border-blue-300';
          if (raw.includes('referred')) return 'bg-purple-100 text-purple-800 border-purple-300';
          if (raw.includes('cancel')) return 'bg-rose-100 text-rose-800 border-rose-300';
          return 'bg-amber-100 text-amber-800 border-amber-300';
        };
        const titleCaseStatus = status => {
          const clean = String(status || 'Scheduled').trim();
          return clean ? clean.charAt(0).toUpperCase() + clean.slice(1) : 'Scheduled';
        };
        const renderConsultationCard = (record, index) => {
          const pageNum = Math.floor(index / perPage) + 1;
          const hiddenClass = pageNum > 1 ? 'hidden' : '';
          const status = record.consultation_status || 'Scheduled';
          const icd10 = String(record.icd10 || '').trim();
          const notes = record.consultation_notes || record.treatment_plan || '';
          return `
            <article data-pagination-item="records" data-page="${pageNum}" class="${hiddenClass} rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs transition-all hover:border-slate-300 space-y-3">
              <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 border-b border-slate-100 pb-3">
                <div>
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-teal-600">${escapeRecordHtml(record.consultation_time || 'OPD')}</span>
                    <span class="rounded-full px-2.5 py-0.5 text-[10px] font-extrabold border ${statusClass(status)}">Status: ${escapeRecordHtml(titleCaseStatus(status))}</span>
                    ${icd10 ? `<span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 font-mono text-[10px] font-extrabold text-slate-700">ICD-10: ${escapeRecordHtml(icd10)}</span>` : ''}
                  </div>
                  <h4 class="font-bold text-slate-900 text-base mt-1">${escapeRecordHtml(record.diagnosis || 'Consultation')}</h4>
                  <p class="text-xs text-slate-500 font-medium">Attending Provider: ${escapeRecordHtml(record.physician_name || 'RHU Healthcare Staff')}</p>
                </div>
                <span class="rounded-lg bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">${escapeRecordHtml(record.consultation_date || '-')}</span>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div>
                  <p class="font-bold text-slate-400">Chief Complaint:</p>
                  <p class="text-slate-700 font-medium">${escapeRecordHtml(record.chief_complaint || 'None specified')}</p>
                </div>
                <div>
                  <p class="font-bold text-slate-400">Prescribed Medications:</p>
                  <p class="text-slate-700 font-medium">${escapeRecordHtml(record.medications_prescribed || 'None recorded yet')}</p>
                </div>
                <div>
                  <p class="font-bold text-slate-400">ICD-10 Code:</p>
                  <p class="font-mono text-slate-700 font-bold">${escapeRecordHtml(icd10 || 'None recorded yet')}</p>
                </div>
              </div>
              ${notes ? `<div class="bg-slate-50 p-3 rounded-xl border border-slate-200/60 text-xs space-y-1">
                <p class="font-bold text-teal-800 uppercase text-[10px]"> Healthcare Staff Response &amp; Clinical Notes:</p>
                <p class="text-slate-800 font-medium whitespace-pre-line">${escapeRecordHtml(notes)}</p>
              </div>` : ''}
            </article>`;
        };
        const updateRecordCounters = (payload, activeCount) => {
          const totals = payload.totals || {};
          const total = totals.total ?? (payload.consultations || []).length;
          const visitsYear = totals.visits_this_year ?? 0;
          const prescriptions = totals.prescriptions ?? 0;
          const totalVisitsEl = document.getElementById('records-total-visits');
          const visitsYearEl = document.getElementById('records-visits-this-year');
          const prescriptionsEl = document.getElementById('records-total-prescriptions');
          const totalCountEl = document.getElementById('records-total-count');
          if (totalVisitsEl) totalVisitsEl.textContent = total;
          if (visitsYearEl) visitsYearEl.textContent = visitsYear;
          if (prescriptionsEl) prescriptionsEl.textContent = prescriptions;
          if (totalCountEl) totalCountEl.textContent = activeCount;
        };
        const notifyResidentRecordUpdate = records => {
          if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
            const latest = records?.[0] || {};
            new Notification('RHU consultation updated', {
              body: `${latest.diagnosis || 'Your consultation'} - ${latest.consultation_status || 'Updated'}`,
              tag: 'resident-consultation-update'
            });
          }
        };
        const refreshConsultationRecords = async () => {
          if (isPolling) return;
          isPolling = true;
          try {
            const response = await fetch('ResidentDashboard.php?api=get_consultations', {
              headers: { 'Accept': 'application/json' },
              cache: 'no-store'
            });
            const payload = await response.json();
            if (!payload.success) return;
            const shouldPaintRecords = !latestFingerprint || (payload.fingerprint && payload.fingerprint !== latestFingerprint);
            if (shouldPaintRecords) {
              const wasInitialPaint = !latestFingerprint;
              latestFingerprint = payload.fingerprint;
              const records = (Array.isArray(payload.consultations) ? payload.consultations : []).filter(record => !['completed', 'complete'].includes(String(record.consultation_status || '').trim().toLowerCase()));
              list.innerHTML = records.length
                ? records.map(renderConsultationCard).join('')
                : `<div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-400"><p class="text-sm font-semibold">No active appointments. Completed visits are available in Health Record History.</p></div>`;
              updateRecordCounters(payload, records.length);
              window.dashboardCurrentPages = window.dashboardCurrentPages || {};
              const totalPages = Math.max(1, Math.ceil(records.length / perPage));
              const currentPage = Math.min(window.dashboardCurrentPages.records || 1, totalPages);
              window.dashboardCurrentPages.records = currentPage;
              list.querySelectorAll('[data-pagination-item="records"]').forEach(item => {
                item.classList.toggle('hidden', Number(item.dataset.page) !== currentPage);
              });
              const currentPageLabel = document.getElementById('records-current-page');
              if (currentPageLabel) currentPageLabel.textContent = currentPage;
              const previousButton = document.getElementById('records-prev-btn');
              const nextButton = document.getElementById('records-next-btn');
              if (previousButton) previousButton.disabled = currentPage <= 1;
              if (nextButton) nextButton.disabled = currentPage >= totalPages;
              if (!wasInitialPaint) notifyResidentRecordUpdate(records);
              if (window.lucide) lucide.createIcons();
            }
          } catch (error) {
            console.warn('Resident consultation live refresh failed', error);
          } finally {
            isPolling = false;
          }
        };

        refreshConsultationRecords();
        window.setInterval(refreshConsultationRecords, 4000);
        document.addEventListener('visibilitychange', () => {
          if (!document.hidden) refreshConsultationRecords();
        });
      })();

      (function () {
        const liveProfileNodes = document.querySelectorAll('[data-live-profile]');
        if (!liveProfileNodes.length) return;

        let latestProfileFingerprint = '';
        let isProfilePolling = false;
        const formatProfileValue = (key, value) => {
          const clean = String(value ?? '').trim();
          if (key === 'height') return clean ? `${clean} cm` : 'Not recorded';
          if (key === 'weight') return clean ? `${clean} kg` : 'Not recorded';
          if (key === 'blood_pressure') return clean || 'Not recorded';
          if (key === 'heart_rate') return clean ? `${clean} bpm` : 'Not recorded';
          if (key === 'temperature') return clean ? `${clean} deg C` : 'Not recorded';
          if (key === 'last_checkup_date') return clean || 'Not recorded';
          if (key === 'smoking_status') return clean || 'Not recorded';
          if (key === 'alcohol_consumption') return clean || 'Not recorded';
          if (key === 'exercise_frequency') return clean || 'Not recorded';
          if (key === 'diet_type') return clean || 'Not recorded';
          if (key === 'blood_type') return clean || 'Unknown';
          if (key === 'philhealth_number') return clean || 'Not Recorded';
          if (key === 'allergies') return clean || 'No known allergies recorded.';
          if (key === 'chronic_conditions') return clean || 'No pre-existing chronic conditions recorded.';
          return clean || 'Not recorded';
        };
        const paintHealthProfile = (profile) => {
          liveProfileNodes.forEach((node) => {
            const key = node.dataset.liveProfile;
            node.textContent = formatProfileValue(key, profile[key]);
          });
        };
        const refreshHealthProfile = async () => {
          if (isProfilePolling) return;
          isProfilePolling = true;
          try {
            const response = await fetch('ResidentDashboard.php?api=get_health_profile', {
              headers: { 'Accept': 'application/json' },
              cache: 'no-store'
            });
            const payload = await response.json();
            if (!payload.success) return;
            if (!latestProfileFingerprint) {
              latestProfileFingerprint = payload.fingerprint || '';
              paintHealthProfile(payload.profile || {});
              return;
            }
            if (payload.fingerprint && payload.fingerprint !== latestProfileFingerprint) {
              latestProfileFingerprint = payload.fingerprint;
              paintHealthProfile(payload.profile || {});
              if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
                new Notification('RHU profile updated', {
                  body: 'Your health profile was updated by RHU staff.',
                  tag: 'resident-profile-update'
                });
              }
            }
          } catch (error) {
            console.warn('Resident profile live refresh failed', error);
          } finally {
            isProfilePolling = false;
          }
        };

        refreshHealthProfile();
        window.setInterval(refreshHealthProfile, 4000);
        document.addEventListener('visibilitychange', () => {
          if (!document.hidden) refreshHealthProfile();
        });
      })();

      // ----------------------------------------------------
      // HERO CAROUSEL: ADMIN POSTED EVENTS
      // ----------------------------------------------------
      let currentHeroSlideIndex = 0;
      let heroSlides = document.querySelectorAll('[data-hero-slide]');
      let heroDots = document.querySelectorAll('.hero-dot');
      const heroBgImg = document.getElementById('hero-event-bg');
      const heroContainer = document.getElementById('hero-events-container');
      const heroImageUpload = document.getElementById('hero-event-image-upload');
      const heroImageReset = document.getElementById('hero-event-image-reset');
      const heroImageStorageKey = 'rhu-resident-event-cover';
      let heroCustomImage = '';
      let heroRotationTimer = null;
      let heroRotationPaused = false;

      try {
        heroCustomImage = window.localStorage.getItem(heroImageStorageKey) || '';
      } catch (error) {
        heroCustomImage = '';
      }

      const updateHeroImageControls = () => {
        if (heroImageReset) heroImageReset.classList.toggle('hidden', !heroCustomImage);
        if (heroImageReset) heroImageReset.classList.toggle('flex', Boolean(heroCustomImage));
      };

      const resizeHeroImage = file => new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
          const image = new Image();
          image.onload = () => {
            const maxWidth = 1600;
            const scale = Math.min(1, maxWidth / image.width);
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(image.width * scale));
            canvas.height = Math.max(1, Math.round(image.height * scale));
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL('image/jpeg', 0.84));
          };
          image.onerror = reject;
          image.src = reader.result;
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
      });

      const applyHeroImage = () => {
        const activeSlide = heroSlides[currentHeroSlideIndex];
        const eventImage = activeSlide?.getAttribute('data-bg');
        if (heroBgImg) heroBgImg.src = heroCustomImage || eventImage || '<?= esc($defaultHeroImage) ?>';
        updateHeroImageControls();
      };

      heroImageUpload?.addEventListener('change', async event => {
        const file = event.target.files?.[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
          event.target.value = '';
          return;
        }
        try {
          heroCustomImage = await resizeHeroImage(file);
          window.localStorage.setItem(heroImageStorageKey, heroCustomImage);
          applyHeroImage();
        } catch (error) {
          heroCustomImage = '';
        }
        event.target.value = '';
      });

      heroImageReset?.addEventListener('click', () => {
        heroCustomImage = '';
        try {
          window.localStorage.removeItem(heroImageStorageKey);
        } catch (error) {}
        applyHeroImage();
      });

      window.setHeroSlide = function(index) {
        if (!heroSlides || !heroSlides.length) return;
        currentHeroSlideIndex = (index + heroSlides.length) % heroSlides.length;

        heroSlides.forEach((slide, idx) => {
          if (idx === currentHeroSlideIndex) {
            slide.classList.remove('hidden');
            slide.classList.add('block');
            const bg = slide.getAttribute('data-bg');
            if (heroBgImg && bg !== 'undefined') {
              heroBgImg.src = heroCustomImage || bg;
            }
          } else {
            slide.classList.add('hidden');
            slide.classList.remove('block');
          }
        });

        heroDots.forEach((dot, idx) => {
          if (idx === currentHeroSlideIndex) {
            dot.classList.remove('w-2', 'bg-white/30');
            dot.classList.add('w-6', 'bg-emerald-400');
          } else {
            dot.classList.remove('w-6', 'bg-emerald-400');
            dot.classList.add('w-2', 'bg-white/30');
          }
        });

        applyHeroImage();
      };

      window.nextHeroSlide = function() {
        window.setHeroSlide(currentHeroSlideIndex + 1);
      };

      window.prevHeroSlide = function() {
        window.setHeroSlide(currentHeroSlideIndex - 1);
      };

      window.addEventListener('resident:sections-updated', () => {
        heroSlides = document.querySelectorAll('[data-hero-slide]');
        heroDots = document.querySelectorAll('.hero-dot');
        window.setHeroSlide(currentHeroSlideIndex);
      });

      if (heroContainer) {
        const startHeroRotation = () => {
          window.clearInterval(heroRotationTimer);
          heroRotationTimer = window.setInterval(() => {
            if (!heroRotationPaused && !document.hidden) {
              window.nextHeroSlide();
            }
          }, 6000);
        };

        const restartHeroRotation = () => {
          startHeroRotation();
        };

        heroContainer?.addEventListener('mouseenter', () => {
          heroRotationPaused = true;
        });
        heroContainer?.addEventListener('mouseleave', () => {
          heroRotationPaused = false;
          restartHeroRotation();
        });

        heroDots.forEach(dot => dot.addEventListener('click', restartHeroRotation));
        document.querySelectorAll('[onclick="window.prevHeroSlide()"], [onclick="window.nextHeroSlide()"]')
          .forEach(button => button.addEventListener('click', restartHeroRotation));

        startHeroRotation();
      }

      updateHeroImageControls();

      // ----------------------------------------------------
      // LIGHT / DARK MODE GLASSMORPHISM THEME SWITCHER
      // ----------------------------------------------------
      window.applyDashboardTheme = function(theme) {
        const isDark = theme === 'dark';
        const docEl = document.documentElement;
        const bodyEl = document.body;

        if (isDark) {
          docEl.classList.add('dark', 'theme-dark');
          docEl.classList.remove('light', 'theme-light');
          bodyEl.classList.add('dark', 'theme-dark');
          bodyEl.classList.remove('light', 'theme-light');
        } else {
          docEl.classList.remove('dark', 'theme-dark');
          docEl.classList.add('light', 'theme-light');
          bodyEl.classList.remove('dark', 'theme-dark');
          bodyEl.classList.add('light', 'theme-light');
        }

        const iconEl = document.getElementById('theme-toggle-icon');
        const textEl = document.getElementById('theme-toggle-text');
        const btnEl = document.getElementById('theme-toggle-btn');

        if (iconEl && textEl) {
          iconEl.innerHTML = isDark ?
            '<i data-lucide="moon" class="h-4 w-4"></i>' :
            '<i data-lucide="sun" class="h-4 w-4"></i>';
          textEl.textContent = isDark ? 'Dark Mode' : 'Light Mode';
          if (window.lucide) lucide.createIcons();
        }

        if (btnEl) {
          if (isDark) {
            btnEl.className = 'flex items-center gap-2 rounded-full border border-emerald-500/40 bg-emerald-950/80 px-3.5 py-1.5 text-xs font-bold text-emerald-300 shadow-md hover:scale-105 transition-all cursor-pointer';
          } else {
            btnEl.className = 'flex items-center gap-2 rounded-full border border-slate-300 bg-white/90 px-3.5 py-1.5 text-xs font-bold text-slate-800 shadow-md hover:scale-105 transition-all cursor-pointer';
          }
        }

        const bgOverlay = document.getElementById('rhu-bg-overlay');
        const bgImg = document.getElementById('rhu-bg-img');
        if (bgOverlay) {
          bgOverlay.className = isDark ?
            'absolute inset-0 bg-gradient-to-br from-[#060b08]/94 via-[#080e0a]/90 to-[#08120b]/94 backdrop-blur-[2px] transition-all duration-500' :
            'absolute inset-0 bg-gradient-to-br from-white/85 via-slate-100/76 to-emerald-50/80 backdrop-blur-[2px] transition-all duration-500';
        }
        if (bgImg) {
          bgImg.style.filter = isDark ? 'brightness(0.35) contrast(1.1)' : 'brightness(0.98) contrast(1.02)';
        }

        try {
          localStorage.setItem('rhu_resident_theme_pref', theme);
        } catch (e) {}
      };

      window.toggleDashboardTheme = function() {
        const isDarkNow = document.body.classList.contains('dark') || document.body.classList.contains('theme-dark');
        applyDashboardTheme(isDarkNow ? 'light' : 'dark');
      };

      (function() {
        let savedTheme = 'light';
        try {
          savedTheme = localStorage.getItem('rhu_resident_theme_pref') || 'light';
        } catch (e) {}
        applyDashboardTheme(savedTheme);
      })();

      const notificationButton = document.querySelector('[data-notifications]');
      const notificationPanel = document.querySelector('[data-notification-panel]');
      if (notificationButton && notificationPanel) {
        notificationButton.addEventListener('click', (e) => {
          e.stopPropagation();
          notificationPanel.classList.toggle('hidden');
        });
        const closeButton = document.querySelector('[data-close-notifications]');
        if (closeButton) closeButton.addEventListener('click', () => notificationPanel.classList.add('hidden'));

        document.addEventListener('click', (e) => {
          if (!notificationPanel.classList.contains('hidden')) {
            if (!notificationPanel.contains(e.target) && !notificationButton.contains(e.target)) {
              notificationPanel.classList.add('hidden');
            }
          }
        });
      }

      window.openDependentModal = function(e) {
        if (e && e.preventDefault) {
          e.preventDefault();
          e.stopPropagation();
        }
        const dependentModal = document.getElementById('dependent-modal');
        if (!dependentModal) {
          console.error('Modal element not found');
          return false;
        }
        console.log('Opening dependent modal...');
        dependentModal.classList.remove('hidden');
        dependentModal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        return false;
      };

      window.openDependentInfoModal = function(dep) {
        if (!dep) return;

        const avatarEl = document.getElementById('dep-info-avatar');
        const nameEl = document.getElementById('dep-info-name');
        const bloodEl = document.getElementById('dep-info-blood');
        const subEl = document.getElementById('dep-info-sub');
        const relEl = document.getElementById('dep-info-rel');
        const dobEl = document.getElementById('dep-info-dob');
        const ageEl = document.getElementById('dep-info-age');
        const genderEl = document.getElementById('dep-info-gender');
        const btEl = document.getElementById('dep-info-bt');
        const brgyEl = document.getElementById('dep-info-brgy');
        const addrEl = document.getElementById('dep-info-addr');
        const headEl = document.getElementById('dep-info-head');
        const phoneEl = document.getElementById('dep-info-phone');
        const notesEl = document.getElementById('dep-info-notes');
        const folderEl = document.getElementById('dep-info-record-folder');
        const editForm = document.getElementById('dependent-edit-form');

        if (nameEl) nameEl.textContent = dep.name || 'Dependent Profile';
        if (avatarEl) {
          const fnLetter = dep.first_name ? dep.first_name[0].toUpperCase() : 'D';
          const lnLetter = dep.last_name ? dep.last_name[0].toUpperCase() : 'P';
          avatarEl.textContent = fnLetter + lnLetter;
        }
        if (bloodEl) bloodEl.textContent = dep.blood_type || 'Blood N/A';
        if (subEl) subEl.textContent = (dep.relationship || 'Family Member') + ' - ' + (dep.age || 'N/A') + ' - ' + (dep.gender || 'Not specified');
        if (relEl) relEl.textContent = dep.relationship || 'Family Member';
        if (dobEl) dobEl.textContent = dep.dob || 'N/A';
        if (ageEl) ageEl.textContent = dep.age || 'N/A';
        if (genderEl) genderEl.textContent = dep.gender || 'Not specified';
        if (btEl) btEl.textContent = dep.blood_type || 'Unknown';
        if (brgyEl) brgyEl.textContent = dep.barangay || 'Nasugbu';
        if (addrEl) addrEl.textContent = dep.address || 'Nasugbu, Batangas';
        if (headEl) headEl.textContent = dep.emergency_contact || 'Head of Household';
        if (phoneEl) phoneEl.textContent = 'Contact #: ' + (dep.emergency_phone || 'N/A');
        if (notesEl) notesEl.textContent = dep.notes || 'No known allergies or medical conditions recorded.';
        if (editForm) {
          const canEditDependent = !!dep.link_id && !!dep.dep_res_id;
          editForm.classList.toggle('hidden', !canEditDependent);
          const setValue = (id, value) => {
            const input = document.getElementById(id);
            if (input) input.value = value || '';
          };
          setValue('dep-edit-link-id', dep.link_id);
          setValue('dep-edit-resident-id', dep.dep_res_id);
          setValue('dep-edit-first-name', dep.first_name);
          setValue('dep-edit-middle-name', dep.middle_name);
          setValue('dep-edit-last-name', dep.last_name);
          setValue('dep-edit-relationship', dep.relationship || 'Family Member');
          setValue('dep-edit-dob', dep.dob && dep.dob !== 'N/A' ? dep.dob : '');
          setValue('dep-edit-gender', dep.gender || 'Other');
          setValue('dep-edit-blood-type', dep.blood_type && dep.blood_type !== 'Unknown / N/A' ? dep.blood_type : '');
          setValue('dep-edit-notes', dep.notes && dep.notes !== 'No known allergies or medical conditions recorded.' ? dep.notes : '');
          const agePill = document.getElementById('dep-edit-auto-age');
          if (agePill) agePill.textContent = 'Age: ' + (dep.age || 'N/A');
        }
        if (folderEl) {
          const folder = dep.record_folder || {};
          const rows = [];
          (folder.consultations || []).forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Consultation - ${escapeHtml(record.diagnosis || record.chief_complaint || 'Pending OPD Triage')}</p><p class="mt-1 text-[10px] text-slate-500">${escapeHtml(record.consultation_status || 'Scheduled')} | ${escapeHtml(record.consultation_date || 'No date')}${record.icd10 ? ` | ICD-10: ${escapeHtml(record.icd10)}` : ''}</p><p class="mt-1 text-[11px] text-slate-700">${escapeHtml(record.consultation_notes || record.treatment_plan || 'No clinical update yet.')}</p></div>`));
          (folder.certificates || []).forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Certificate - ${escapeHtml(record.certificate_type || 'Health Certificate')}</p><p class="mt-1 text-[10px] text-slate-500">${escapeHtml(record.status || 'Pending')} | ${escapeHtml(record.issue_date || 'No issue date')} | ${escapeHtml(record.certificate_number || '')}</p></div>`));
          (folder.vaccinations || []).forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Vaccination - ${escapeHtml(record.vaccine_name || 'Vaccine')}</p><p class="mt-1 text-[10px] text-slate-500">Dose ${escapeHtml(record.dose_number || '1')} | ${escapeHtml(record.vaccination_date || 'No date')} | Next: ${escapeHtml(record.next_dose_date || 'Not scheduled')}</p></div>`));
          (folder.pregnancies || []).forEach(record => rows.push(`<div class="rounded-xl bg-white p-3"><p class="font-bold text-slate-800">Pregnancy record - ${escapeHtml(record.pregnancy_status || 'Active')}</p><p class="mt-1 text-[10px] text-slate-500">EDC: ${escapeHtml(record.expected_delivery_date || 'Not recorded')} | Risk: ${escapeHtml(record.risk_level || 'Routine')}</p></div>`));
          folderEl.innerHTML = rows.length ? rows.join('') : '<p class="rounded-xl bg-white p-3 text-[11px] font-medium text-slate-500">No health records have been recorded for this dependent yet.</p>';
        }

        window.currentSelectedDependent = dep;

        const infoModal = document.getElementById('dependent-info-modal');
        if (!infoModal) return;
        infoModal.classList.remove('hidden');
        infoModal.classList.add('flex');
        infoModal.style.setProperty('display', 'flex', 'important');
        document.body.classList.add('overflow-hidden');
        if (window.lucide) lucide.createIcons();
      };

      window.openDependentInfoModalFromElement = function(card) {
        if (!card) return false;
        try {
          const payload = JSON.parse(card.getAttribute('data-dependent-payload') || '{}');
          window.openDependentInfoModal(payload);
        } catch (error) {
          console.error('Unable to open participant details:', error);
        }
        return false;
      };

      window.closeDependentInfoModal = function() {
        const infoModal = document.getElementById('dependent-info-modal');
        if (!infoModal) return;
        infoModal.classList.add('hidden');
        infoModal.classList.remove('flex');
        infoModal.style.setProperty('display', 'none', 'important');
        document.body.classList.remove('overflow-hidden');
      };

      window.bookAppointmentForDependent = function() {
        const dependent = window.currentSelectedDependent || {};
        const patientInput = document.getElementById('appointment_patient_resident_id');
        const prenatalOption = document.querySelector('#appointment_type_select option[data-prenatal-option]');
        const patientGender = String(dependent.gender || '').toLowerCase().trim();
        const dependentIsFemale = ['female', 'f', 'woman'].includes(patientGender);
        if (patientInput) patientInput.value = Number(dependent.dep_res_id || <?= (int)$residentId ?>);
        if (prenatalOption) {
          prenatalOption.hidden = false;
          prenatalOption.disabled = !dependentIsFemale;
          if (!dependentIsFemale && document.getElementById('appointment_type_select').value === 'Prenatal & Maternal Care') {
            document.getElementById('appointment_type_select').value = 'General Medical Consultation';
          }
        }
        window.closeDependentInfoModal();
        if (typeof window.openAppointmentModal === 'function') {
          window.openAppointmentModal();
        }
      };

      // Document-level delegated click listener for 100% bulletproof modal opening
      document.addEventListener('click', function(e) {
        const openTrigger = e.target.closest('#add-dependent-btn, [data-dependent-open], .add-dependent-trigger');
        if (openTrigger) {
          console.log('Open dependent modal triggered');
          window.openDependentModal(e);
          return;
        }
        const closeTrigger = e.target.closest('[data-dependent-close], .close-dependent-trigger');
        if (closeTrigger) {
          console.log('Close dependent modal triggered');
          window.closeDependentModal(e);
          return;
        }
      });

      const depModalEl = document.getElementById('dependent-modal');
      if (depModalEl) {
        console.log('Dependent modal element found on page');
        depModalEl.addEventListener('click', event => {
          if (event.target === depModalEl) window.closeDependentModal(event);
        });
      } else {
        console.error('CRITICAL: Dependent modal element NOT found on page');
      }

      document.addEventListener('keydown', event => {
        if (event.key === 'Escape') window.closeDependentModal(event);
      });

      // Additional check for Add Dependent button with enhanced debugging
      setTimeout(() => {
        const addDepBtn = document.getElementById('add-dependent-btn');
        const depModal = document.getElementById('dependent-modal');

        console.log('%c=== ADD DEPENDENT MODAL DEBUG ===', 'color: blue; font-weight: bold');
        console.log('Button found:', !!addDepBtn);
        console.log('Modal found:', !!depModal);

        if (addDepBtn) {
          console.log('Button text:', addDepBtn.innerText);
          console.log('Button onclick attr:', addDepBtn.getAttribute('onclick'));
          console.log('openDependentModal function:', typeof window.openDependentModal);

          // Add test listener
          addDepBtn.addEventListener('click', function(e) {
            console.log('%cBUTTON CLICKED!', 'color: green; font-weight: bold', e);
            window.openDependentModal(e);
          });

          console.log('OK Button event listener attached');
        } else {
          console.error('ERROR BUTTON NOT FOUND!');
        }

        if (depModal) {
          console.log('Modal HTML length:', depModal.innerHTML.length);
          console.log('Modal current display:', getComputedStyle(depModal).display);
          console.log('Modal has hidden class:', depModal.classList.contains('hidden'));
        } else {
          console.error('ERROR MODAL NOT FOUND!');
        }

        console.log('%c=== END DEBUG ===', 'color: blue; font-weight: bold');
      }, 500);

      <?php if (!empty($dependentErrors)): ?>
        if (typeof window.openDependentModal === 'function') window.openDependentModal();
      <?php endif; ?>

      const logoutModal = document.getElementById('logout-modal');
      const openLogoutModal = event => {
        event.preventDefault();
        logoutModal.classList.remove('hidden');
        logoutModal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        logoutModal.querySelector('[data-logout-cancel]').focus();
      };
      const closeLogoutModal = () => {
        logoutModal.classList.add('hidden');
        logoutModal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
      };
      document.querySelectorAll('[data-logout-link]').forEach(link => link.addEventListener('click', openLogoutModal));
      document.querySelectorAll('[data-logout-cancel]').forEach(button => button.addEventListener('click', closeLogoutModal));
      logoutModal.addEventListener('click', event => {
        if (event.target === logoutModal) closeLogoutModal();
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeLogoutModal();
      });

      const revealItems = document.querySelectorAll(
        '[data-tab-panel] > div, [data-tab-panel] > article, [data-tab-panel] form'
      );
      if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const revealObserver = new IntersectionObserver(entries => {
          entries.forEach(entry => {
            if (entry.isIntersecting) {
              entry.target.classList.add('is-visible');
              revealObserver.unobserve(entry.target);
            }
          });
        }, {
          threshold: 0.08,
          rootMargin: '0px 0px -24px'
        });

        revealItems.forEach((item, index) => {
          item.classList.add('reveal-on-scroll');
          item.style.transitionDelay = `${Math.min(index % 4, 3) * 55}ms`;
          revealObserver.observe(item);
        });
      } else {
        revealItems.forEach(item => item.classList.add('is-visible'));
      }

      const scrollProgress = document.getElementById('scroll-progress');
      const updateScrollProgress = () => {
        const scrollable = document.documentElement.scrollHeight - window.innerHeight;
        const progress = scrollable > 0 ? Math.min((window.scrollY / scrollable) * 100, 100) : 0;
        scrollProgress.style.width = `${progress}%`;
      };
      updateScrollProgress();
      window.addEventListener('scroll', updateScrollProgress, {
        passive: true
      });
      window.addEventListener('resize', updateScrollProgress);

      // Final verification that dependent modal is properly initialized
      console.log('=== DEPENDENT MODAL INITIALIZATION CHECK ===');
      const depModal = document.getElementById('dependent-modal');
      const depButton = document.getElementById('add-dependent-btn');
      console.log('Modal exists:', !!depModal);
      console.log('Button exists:', !!depButton);
      if (depModal && depButton) {
        console.log('Modal and button both found - should be ready to use');
        console.log('Modal current classes:', depModal.className);
        console.log('Modal visible:', !depModal.classList.contains('hidden'));
      } else {
        console.error('ERROR: Modal or button missing!');
      }
      console.log('=========================================');

      const personalProfileForm = document.querySelector('#edit-personal-profile-modal form');
      if (personalProfileForm) {
        const civilStatusSelect = personalProfileForm.querySelector('select[name="civil_status"]');
        const middleNameInput = personalProfileForm.querySelector('input[name="middle_name"]');
        const lastNameInput = personalProfileForm.querySelector('input[name="last_name"]');
        const previousLastNameInput = personalProfileForm.querySelector('input[name="previous_last_name"]');
        const normalizeMarriedMiddleName = () => {
          if (!civilStatusSelect || !middleNameInput || !lastNameInput || !previousLastNameInput) return;
          const isMarried = (civilStatusSelect.value || '').trim() === 'Married';
          if (!isMarried) return;
          const previousLastName = (previousLastNameInput.value || '').trim();
          if (previousLastName !== '') {
            middleNameInput.value = previousLastName;
          }
        };
        civilStatusSelect?.addEventListener('change', normalizeMarriedMiddleName);
        normalizeMarriedMiddleName();
      }

    })();
  </script>
  <!-- EDIT PERSONAL PROFILE MODAL -->
  <div id="edit-personal-profile-modal" class="fixed inset-0 z-50 <?= isset($_GET['edit_personal']) ? '' : 'hidden' ?> items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
    <div class="w-full max-w-2xl rounded-3xl bg-white p-6 shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3">
        <div class="flex items-center gap-2.5">
          <div class="h-9 w-9 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center">
            <i data-lucide="user-cog" class="h-5 w-5"></i>
          </div>
          <div>
            <h3 class="text-base font-extrabold text-slate-900">Update Personal & Contact Details</h3>
            <p class="text-xs text-slate-500">Edit resident demographic and contact information.</p>
          </div>
        </div>
        <button type="button" onclick="document.getElementById('edit-personal-profile-modal')?.classList.add('hidden')" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
          <i data-lucide="x" class="h-5 w-5"></i>
        </button>
      </div>

      <form method="post" action="ResidentDashboard.php" class="space-y-4 text-xs">
        <input type="hidden" name="form" value="update_personal_profile">
        <?php if (isset($_GET['edit_personal']) && $contactSuccess !== ''): ?><p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-3 text-red-800"><?= esc($contactSuccess) ?></p><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">
        <input type="hidden" name="previous_last_name" value="<?= esc($resident['last_name'] ?? '') ?>">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">First Name</label>
            <input type="text" name="first_name" value="<?= esc($resident['first_name'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white" required>
          </div>
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Middle Name</label>
            <input type="text" name="middle_name" value="<?= esc($resident['middle_name'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Last Name</label>
            <input type="text" name="last_name" value="<?= esc($resident['last_name'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white" required>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Date of Birth</label>
            <input type="date" name="date_of_birth" value="<?= esc($resident['date_of_birth'] ?? ($resident['birthdate'] ?? '')) ?>" max="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white" required>
          </div>
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Sex</label>
            <select name="sex" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
              <option value="">Select</option>
              <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                <option value="<?= $g ?>" <?= (($resident['sex'] ?? $resident['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Civil Status</label>
            <select name="civil_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
              <option value="">Select</option>
              <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Live-in'] as $cs): ?>
                <option value="<?= $cs ?>" <?= (($resident['civil_status'] ?? '') === $cs) ? 'selected' : '' ?>><?= $cs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Contact Number</label>
            <input type="text" name="contact_number" value="<?= esc($resident['contact_number'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>
        </div>

        <div>
          <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Email Address</label>
          <input type="email" name="email" value="<?= esc($resident['email'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Barangay</label>
            <select name="barangay" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
              <option value="">Select</option>
              <?php foreach ($dbBarangays as $b): ?>
                <?php $selected = (($resident['barangay'] ?? '') === $b) || (($resident['barangay_id'] ?? 0) && isset($dbBarangaysById[$resident['barangay_id']]) && $dbBarangaysById[$resident['barangay_id']] === $b); ?>
                <option value="<?= esc($b) ?>" <?= $selected ? 'selected' : '' ?>><?= esc($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Address</label>
            <input type="text" name="address" value="<?= esc($resident['address'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>
        </div>

        <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-100">
          <button type="button" onclick="document.getElementById('edit-personal-profile-modal')?.classList.add('hidden')" class="rounded-xl px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
            Cancel
          </button>
          <button type="submit" class="rounded-xl bg-teal-600 px-5 py-2.5 text-xs font-bold text-white shadow-md hover:bg-teal-700 transition-colors">
            Save Personal Details
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- EDIT HEALTH PROFILE MODAL -->
  <div id="edit-health-profile-modal" class="fixed inset-0 hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
    <div class="w-full max-w-xl rounded-3xl bg-white p-6 shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3">
        <div class="flex items-center gap-2.5">
          <div class="h-9 w-9 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center">
            <i data-lucide="user-cog" class="h-5 w-5"></i>
          </div>
          <div>
            <h3 class="text-base font-extrabold text-slate-900">Update Health Profile</h3>
            <p class="text-xs text-slate-500">Edit medical information for your RHU resident record.</p>
          </div>
        </div>
        <button type="button" onclick="document.getElementById('edit-health-profile-modal')?.classList.add('hidden')" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
          <i data-lucide="x" class="h-5 w-5"></i>
        </button>
      </div>

      <form method="post" action="ResidentDashboard.php" class="space-y-4 text-xs">
        <input type="hidden" name="form" value="update_health_profile">
        <input type="hidden" name="csrf_token" value="<?= esc($dashboardCsrf) ?>">

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Blood Type</label>
            <select name="blood_type" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
              <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'] as $bt): ?>
                <option value="<?= $bt ?>" <?= (($healthProfile['blood_type'] ?? ($resident['blood_type'] ?? '')) === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">PhilHealth Number</label>
            <input type="text" name="philhealth_number" value="<?= esc($healthProfile['philhealth_number'] ?? ($resident['philhealth_id'] ?? '')) ?>" placeholder="e.g. 12-345678901-2" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Height (cm)</label>
            <input type="number" step="0.1" name="height" value="<?= esc($healthProfile['height'] ?? '') ?>" placeholder="e.g. 165" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Weight (kg)</label>
            <input type="number" step="0.1" name="weight" value="<?= esc($healthProfile['weight'] ?? '') ?>" placeholder="e.g. 62.5" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Blood Pressure</label>
            <input type="text" name="blood_pressure" value="<?= esc($healthProfile['blood_pressure'] ?? ($healthProfile['bp'] ?? '')) ?>" placeholder="e.g. 120/80" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Heart Rate (bpm)</label>
            <input type="number" name="heart_rate" value="<?= esc($healthProfile['heart_rate'] ?? '') ?>" placeholder="e.g. 72" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Temperature (deg C)</label>
            <input type="number" step="0.1" name="temperature" value="<?= esc($healthProfile['temperature'] ?? '') ?>" placeholder="e.g. 36.5" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Last Checkup Date</label>
            <input type="date" name="last_checkup_date" value="<?= esc($healthProfile['last_checkup_date'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Smoking Status</label>
            <select name="smoking_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
              <option value="">Not recorded</option>
              <?php foreach (['Non-Smoker', 'Former Smoker', 'Occasional Smoker', 'Daily Smoker'] as $ss): ?>
                <option value="<?= $ss ?>" <?= (($healthProfile['smoking_status'] ?? '') === $ss) ? 'selected' : '' ?>><?= $ss ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Alcohol Consumption</label>
            <select name="alcohol_consumption" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
              <option value="">Not recorded</option>
              <?php foreach (['Non-Drinker', 'Occasional Drinker', 'Moderate Drinker', 'Heavy Drinker'] as $ac): ?>
                <option value="<?= $ac ?>" <?= (($healthProfile['alcohol_consumption'] ?? '') === $ac) ? 'selected' : '' ?>><?= $ac ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Exercise Frequency</label>
            <select name="exercise_frequency" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
              <option value="">Not recorded</option>
              <?php foreach (['Sedentary (No exercise)', 'Occasional (1-2x/week)', 'Active (3-5x/week)', 'Daily Athlete'] as $ef): ?>
                <option value="<?= $ef ?>" <?= (($healthProfile['exercise_frequency'] ?? '') === $ef) ? 'selected' : '' ?>><?= $ef ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Diet Type</label>
            <input type="text" name="diet_type" value="<?= esc($healthProfile['diet_type'] ?? '') ?>" placeholder="e.g. Low Sodium, Diabetic, Balanced" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>
        </div>

        <div>
          <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Known Allergies</label>
          <textarea name="allergies" rows="2" placeholder="List food, drug, or environmental allergies..." class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white"><?= esc($healthProfile['allergies'] ?? ($resident['allergies'] ?? '')) ?></textarea>
        </div>

        <div>
          <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Chronic Conditions / Illnesses</label>
          <textarea name="chronic_conditions" rows="2" placeholder="Hypertension, Asthma, Diabetes, etc." class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white"><?= esc($healthProfile['chronic_conditions'] ?? ($healthProfile['medical_conditions'] ?? ($resident['medical_conditions'] ?? ''))) ?></textarea>
        </div>

        <div>
          <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Current Prescribed Medications</label>
          <textarea name="current_medications" rows="2" placeholder="e.g. Amlodipine 5mg once daily..." class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white"><?= esc($healthProfile['current_medications'] ?? ($healthProfile['medications'] ?? '')) ?></textarea>
        </div>

        <div class="grid grid-cols-3 gap-3 border-t border-slate-100 pt-3">
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Emergency Contact Person</label>
            <input type="text" name="emergency_contact_name" value="<?= esc($healthProfile['emergency_contact_name'] ?? ($resident['emergency_contact_name'] ?? '')) ?>" placeholder="Name" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Relationship</label>
            <input type="text" name="emergency_contact_relationship" value="<?= esc($healthProfile['emergency_contact_relationship'] ?? ($resident['emergency_contact_relationship'] ?? '')) ?>" placeholder="Spouse, Mother, etc." class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>
          <div>
            <label class="block font-bold text-slate-700 uppercase tracking-wider text-[10px] mb-1">Emergency Phone #</label>
            <input type="text" name="emergency_contact_phone" value="<?= esc($healthProfile['emergency_contact_phone'] ?? ($resident['emergency_contact_phone'] ?? '')) ?>" placeholder="0917XXXXXXX" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs font-medium text-slate-800 outline-none focus:border-teal-500 focus:bg-white">
          </div>
        </div>

        <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-100">
          <button type="button" onclick="document.getElementById('edit-health-profile-modal')?.classList.add('hidden')" class="rounded-xl px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
            Cancel
          </button>
          <button type="submit" class="rounded-xl bg-teal-600 px-5 py-2.5 text-xs font-bold text-white shadow-md hover:bg-teal-700 transition-colors">
            Save Health Profile
          </button>
        </div>
      </form>
    </div>
  </div>
  <!-- GLOBAL NOTIFICATION POPOVER PANEL -->
  <div id="global-notification-panel" data-notification-panel class="hidden fixed top-16 right-4 sm:right-8 z-[99999] w-[calc(100vw-2rem)] sm:w-96 max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-800 shadow-2xl transition-all" style="z-index:99999;">
    <!-- Header Bar -->
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 bg-slate-50">
      <div class="flex items-center gap-2">
        <span class="font-bold text-slate-900 text-xs">Notifications</span>
        <span id="notif-header-count" class="rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-extrabold text-teal-800 border border-teal-200">0 Unread</span>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" onclick="markAllNotificationsRead()" class="text-[10px] font-bold text-teal-700 hover:text-teal-900 hover:underline">Mark all read</button>
        <button type="button" onclick="toggleNotificationPanel(event)" class="text-xs font-bold text-slate-400 hover:text-slate-600 p-1">x</button>
      </div>
    </div>

    <div id="push-permission-row" class="hidden border-b border-slate-100 bg-sky-50 px-4 py-3 text-xs">
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <p class="font-black text-sky-900">Device alerts</p>
          <p id="push-permission-status" class="mt-0.5 text-[11px] font-semibold text-sky-700">Allow alerts to receive RHU updates even when this site is closed.</p>
        </div>
        <button type="button" id="enable-push-notifications-btn" class="shrink-0 rounded-xl bg-sky-700 px-3 py-2 text-[10px] font-black text-white shadow-sm hover:bg-sky-800">
          Enable
        </button>
      </div>
    </div>

    <!-- Action Toolbar for Selection & Deletion -->
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2 bg-slate-100/80 text-[11px]">
      <label class="flex items-center gap-1.5 font-bold text-slate-700 cursor-pointer select-none">
        <input type="checkbox" id="notif-select-all" onclick="toggleSelectAllNotifications(this)" class="rounded text-teal-600 focus:ring-teal-500">
        <span>Select All</span>
      </label>
      <button type="button" id="notif-delete-selected-btn" onclick="deleteSelectedNotifications()" disabled class="flex items-center gap-1 text-[10px] font-bold text-rose-600 hover:text-rose-800 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
        <span>Delete Selected</span>
      </button>
    </div>

    <!-- Notifications List Container -->
    <div id="notif-items-list" class="divide-y divide-slate-100 text-xs max-h-80 overflow-y-auto bg-white">
      <div class="p-4 text-center text-slate-400 text-xs">Loading notifications...</div>
    </div>
  </div>

  <!-- NOTIFICATION DETAIL MODAL -->
  <div id="notification-detail-modal" class="fixed inset-0 z-[100000] hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm" style="z-index:100000;">
    <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3">
        <div class="flex items-center gap-2.5">
          <div class="h-9 w-9 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center">
            <i data-lucide="bell" class="h-5 w-5"></i>
          </div>
          <div>
            <h3 class="text-base font-extrabold text-slate-900" id="notif-modal-title">RHU Notification</h3>
            <p class="text-[10px] text-slate-400 font-mono" id="notif-modal-date"></p>
          </div>
        </div>
        <button type="button" onclick="closeNotificationModal()" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
          x
        </button>
      </div>

      <div class="space-y-3 text-xs">
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 leading-relaxed font-medium text-slate-800" id="notif-modal-body">
          <!-- Notification Content -->
        </div>
        <div id="notif-modal-action-container" class="hidden">
          <a id="notif-modal-action-link" href="#" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-teal-600 py-2.5 text-xs font-bold text-white hover:bg-teal-700">
            View Related Section ->
          </a>
        </div>
      </div>

      <div class="flex items-center justify-between border-t border-slate-100 pt-3 text-xs">
        <button type="button" id="notif-modal-delete-btn" onclick="" class="flex items-center gap-1 font-bold text-rose-600 hover:text-rose-800">
          Delete Notification
        </button>
        <button type="button" onclick="closeNotificationModal()" class="rounded-xl bg-slate-100 px-4 py-2 font-bold text-slate-700 hover:bg-slate-200">
          Close
        </button>
      </div>
    </div>
  </div>

  <script>
    function toggleNotificationPanel(event) {
      if (event) {
        event.stopPropagation();
        if (typeof event.preventDefault === 'function') event.preventDefault();
      }
      const panel = document.getElementById('global-notification-panel') || document.querySelector('[data-notification-panel]');
      if (panel) {
        panel.classList.toggle('hidden');
      }
    }

    document.addEventListener('click', (event) => {
      const panel = document.getElementById('global-notification-panel') || document.querySelector('[data-notification-panel]');
      const bell = document.getElementById('notification-bell-btn') || document.querySelector('[data-notifications]');
      if (panel && !panel.classList.contains('hidden')) {
        if (bell && (panel.contains(event.target) || bell.contains(event.target))) return;
        if (!panel.contains(event.target)) {
          panel.classList.add('hidden');
        }
      }
    });

    let currentNotifications = [];
    let notifPollTimer = null;
    let residentNotifFirstLoadComplete = false;
    const residentSeenNotificationIds = new Set();

    function pushBase64ToUint8Array(base64String) {
      const padding = '='.repeat((4 - base64String.length % 4) % 4);
      const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
      const rawData = window.atob(base64);
      const outputArray = new Uint8Array(rawData.length);
      for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
      }
      return outputArray;
    }

    function updatePushPermissionUi(message, enabled = false) {
      const row = document.getElementById('push-permission-row');
      const status = document.getElementById('push-permission-status');
      const button = document.getElementById('enable-push-notifications-btn');
      if (!row || !status || !button) return;
      row.classList.remove('hidden');
      status.textContent = message;
      button.textContent = enabled ? 'Enabled' : 'Enable';
      button.disabled = enabled;
      button.classList.toggle('opacity-60', enabled);
      button.classList.toggle('cursor-not-allowed', enabled);
    }

    async function enableResidentPushNotifications() {
      if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        updatePushPermissionUi('Device alerts are not supported by this browser.');
        return;
      }
      if (!window.isSecureContext && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
        updatePushPermissionUi('Device alerts require HTTPS. Open this portal using https:// first.');
        return;
      }

      const button = document.getElementById('enable-push-notifications-btn');
      if (button) {
        button.disabled = true;
        button.textContent = 'Enabling...';
      }

      try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
          updatePushPermissionUi('Device alerts are blocked. Allow notifications in browser settings to receive updates.');
          if (button) button.disabled = false;
          return;
        }

        const keyResponse = await fetch('ResidentDashboard.php?api=get_push_public_key', {
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json'
          }
        });
        const keyData = await parseNotificationJsonResponse(keyResponse);
        if (!keyData.success || !keyData.public_key) {
          updatePushPermissionUi('Device alerts are not configured for this RHU portal.');
          if (button) button.disabled = false;
          return;
        }

        const registration = await navigator.serviceWorker.register('sw.js');
        await registration.update();
        const readyRegistration = await navigator.serviceWorker.ready;
        const existingSubscription = await readyRegistration.pushManager.getSubscription();
        const currentApplicationKey = pushBase64ToUint8Array(keyData.public_key);
        let reusableSubscription = existingSubscription;
        if (existingSubscription && existingSubscription.options && existingSubscription.options.applicationServerKey) {
          const oldKey = new Uint8Array(existingSubscription.options.applicationServerKey);
          const sameKey = oldKey.length === currentApplicationKey.length && oldKey.every((value, index) => value === currentApplicationKey[index]);
          if (!sameKey) {
            await existingSubscription.unsubscribe();
            reusableSubscription = null;
          }
        }
        const subscription = reusableSubscription || await readyRegistration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: currentApplicationKey
        });

        const saveResponse = await fetch('ResidentDashboard.php?api=save_push_subscription', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(subscription)
        });
        const saveData = await parseNotificationJsonResponse(saveResponse);
        if (!saveData.success) throw new Error(saveData.error || 'Push subscription was not saved');

        const testResponse = await fetch('ResidentDashboard.php?api=test_push', {
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json'
          }
        });
        const testData = await parseNotificationJsonResponse(testResponse);
        const pushResult = testData.push_result || {};
        if (testData.success && Number(pushResult.attempted || 0) > 0 && Number(pushResult.sent || 0) > 0) {
          updatePushPermissionUi('Device alerts are enabled. A test alert was sent to this device.', true);
        } else if (testData.success && Number(pushResult.attempted || 0) > 0) {
          const status = Array.isArray(pushResult.statuses) && pushResult.statuses[0] ? pushResult.statuses[0] : {};
          throw new Error(status.error || status.response || 'The subscription was saved, but the push service did not accept the test alert.');
        } else {
          updatePushPermissionUi('Device alerts are enabled for RHU updates.', true);
        }
      } catch (error) {
        console.error('Push notification setup failed:', error);
        updatePushPermissionUi(error && error.message ? `Device alerts could not be enabled: ${error.message}` : 'Device alerts could not be enabled. Please try again.');
        if (button) button.disabled = false;
      }
    }

    function initializeResidentPushPrompt() {
      const row = document.getElementById('push-permission-row');
      const button = document.getElementById('enable-push-notifications-btn');
      if (!row || !button) return;
      if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
      if (Notification.permission === 'granted') {
        enableResidentPushNotifications();
      } else if (Notification.permission === 'default') {
        row.classList.remove('hidden');
      } else {
        updatePushPermissionUi('Device alerts are blocked in this browser.');
      }
      button.addEventListener('click', enableResidentPushNotifications);
    }

    function showResidentForegroundNotification(item) {
      if (!item || !('Notification' in window) || Notification.permission !== 'granted') return;
      if (!residentNotifFirstLoadComplete) return;
      const id = String(item.id || '');
      if (!id || residentSeenNotificationIds.has(id) || Number(item.is_read) === 1) return;
      residentSeenNotificationIds.add(id);
      try {
        const notice = new Notification(item.title || 'RHU Resident Portal', {
          body: item.message || 'You have a new RHU update.',
          icon: 'assets/images/resihunity_logo.jpg',
          badge: 'assets/images/resihunity_logo.jpg',
          tag: `rhu-resident-${id}`,
          data: {
            url: item.link_url || 'ResidentDashboard.php'
          }
        });
        notice.onclick = () => {
          window.focus();
          if (item.link_url) window.location.href = item.link_url;
          notice.close();
        };
      } catch (error) {
        console.error('Foreground notification failed:', error);
      }
    }

    async function fetchNotifications() {
      try {
        const notificationApiUrl = new URL(window.location.href);
        notificationApiUrl.search = '';
        notificationApiUrl.searchParams.set('api', 'get_notifications');
        notificationApiUrl.searchParams.set('_', String(Date.now()));
        const res = await fetch(notificationApiUrl.toString(), {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Accept': 'application/json',
            'Cache-Control': 'no-cache'
          }
        });
        const contentType = res.headers.get('content-type') || '';
        const rawBody = await res.text();
        if (!res.ok) throw new Error(`Notification request failed: ${res.status}`);
        if (!contentType.includes('application/json')) {
          throw new Error('Notification endpoint returned non-JSON content.');
        }
        const data = JSON.parse(rawBody);
        if (data && data.success) {
          const nextNotifications = data.notifications || [];
          nextNotifications.forEach((item) => {
            const id = String(item.id || '');
            if (residentNotifFirstLoadComplete && id && !residentSeenNotificationIds.has(id)) {
              showResidentForegroundNotification(item);
            }
            if (id) {
              residentSeenNotificationIds.add(id);
            }
          });
          residentNotifFirstLoadComplete = true;
          currentNotifications = nextNotifications;
          renderStaffNotices(currentNotifications);
          renderNotificationList(currentNotifications, data.unread_count || 0);
        } else {
          throw new Error(data?.error || 'Notification response was not successful');
        }
      } catch (err) {
        console.error('Error fetching notifications:', err);
        renderStaffNotices([]);
        renderNotificationList([], 0);
      }
    }

    async function parseNotificationJsonResponse(res) {
      const contentType = res.headers.get('content-type') || '';
      const rawBody = await res.text();
      if (!res.ok) throw new Error(`Notification request failed: ${res.status}`);
      if (!contentType.includes('application/json')) {
        throw new Error('Notification endpoint returned non-JSON content.');
      }
      return JSON.parse(rawBody);
    }

    function renderStaffNotices(items) {
      const count = document.getElementById('staff-notices-count');
      const list = document.getElementById('staff-notices-list');
      if (count) count.textContent = items.length;
      if (!list) return;

      if (!items.length) {
        list.innerHTML = '<div class="p-4 text-center text-xs font-semibold text-slate-400">No RHU staff notices yet.</div>';
        return;
      }

      list.innerHTML = items.slice(0, 3).map((item, index) => {
        const colors = [
          ['bg-emerald-950', 'border-emerald-800/60', 'text-emerald-400'],
          ['bg-teal-950', 'border-teal-800/60', 'text-teal-400'],
          ['bg-sky-950', 'border-sky-800/60', 'text-sky-400']
        ][index % 3];
        const message = String(item.message || 'RHU staff notice');
        const initials = 'RHU';
        return `
          <button type="button" onclick="openNotificationDetail(${Number(item.id)})" class="w-full flex items-start gap-3 p-2.5 rounded-2xl text-left hover:bg-white/5 transition-all">
            <div class="h-10 w-10 shrink-0 rounded-full ${colors[0]} border ${colors[1]} flex items-center justify-center text-xs font-black ${colors[2]}">${initials}</div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-extrabold text-white truncate">RHU Staff Notice</p>
                <span class="text-[10px] font-medium text-slate-400 shrink-0">${escapeHtml(item.created_at_display || item.time_ago || item.created_at || '')}</span>
              </div>
              <p class="text-xs font-bold text-slate-200 mt-0.5 line-clamp-2">${escapeHtml(message)}</p>
            </div>
            <i data-lucide="more-vertical" class="h-4 w-4 shrink-0 text-slate-500"></i>
          </button>`;
      }).join('');
      if (window.lucide) lucide.createIcons();
    }

    function renderNotificationList(items, unreadCount) {
      const badge = document.getElementById('notif-badge-count');
      const headerCount = document.getElementById('notif-header-count');
      const list = document.getElementById('notif-items-list');

      if (badge) {
        if (unreadCount > 0) {
          badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
          badge.style.display = 'flex';
        } else {
          badge.style.display = 'none';
        }
      }

      if (headerCount) {
        headerCount.textContent = `${unreadCount} Unread`;
      }

      if (!list) return;

      if (items.length === 0) {
        list.innerHTML = `
          <div class="p-6 text-center text-slate-400">
            <span class="text-2xl">Notification</span>
            <p class="mt-1 text-xs font-bold text-slate-600">No notifications</p>
            <p class="text-[10px]">You are all caught up!</p>
          </div>`;
        updateDeleteSelectedBtnState();
        return;
      }

      const checkedIds = new Set(
        [...document.querySelectorAll('.notif-checkbox:checked')].map(cb => cb.value)
      );

      list.innerHTML = items.map(item => {
        const isChecked = checkedIds.has(String(item.id)) ? 'checked' : '';
        const unreadBg = !item.is_read ? 'bg-teal-50/60 font-bold border-l-4 border-l-teal-600' : 'hover:bg-slate-50';
        const unreadDot = !item.is_read ? '<span class="h-2 w-2 rounded-full bg-teal-600 shrink-0"></span>' : '';

        return `
          <div class="group flex items-start justify-between gap-2 p-3 text-xs transition-all ${unreadBg}">
            <div class="flex items-start gap-2.5 min-w-0 flex-1">
              <input type="checkbox" value="${item.id}" ${isChecked} onchange="onNotifCheckboxChange()" class="notif-checkbox mt-1 rounded text-teal-600 focus:ring-teal-500 shrink-0">
              <div class="min-w-0 flex-1 cursor-pointer" onclick="openNotificationDetail(${item.id})">
                <div class="flex items-center gap-1.5">
                  ${unreadDot}
                  <p class="text-slate-800 font-semibold truncate ${!item.is_read ? 'font-bold' : ''}">${escapeHtml(item.message)}</p>
                </div>
                <p class="mt-1 text-[10px] text-slate-400 font-mono">${escapeHtml(item.created_at_display || item.time_ago || item.created_at)}</p>
              </div>
            </div>
            <button type="button" onclick="deleteOneNotification(${item.id}, event)" title="Delete notification" class="opacity-70 group-hover:opacity-100 p-1 text-slate-400 hover:text-rose-600 transition-colors shrink-0">
              Delete
            </button>
          </div>
        `;
      }).join('');

      updateDeleteSelectedBtnState();
    }

    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    initializeResidentPushPrompt();
    fetchNotifications();
    window.setInterval(fetchNotifications, 2000);
    window.addEventListener('focus', fetchNotifications);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) fetchNotifications();
    });

    function toggleSelectAllNotifications(masterCb) {
      const checkboxes = document.querySelectorAll('.notif-checkbox');
      checkboxes.forEach(cb => cb.checked = masterCb.checked);
      updateDeleteSelectedBtnState();
    }

    function onNotifCheckboxChange() {
      const master = document.getElementById('notif-select-all');
      const all = document.querySelectorAll('.notif-checkbox');
      const checked = document.querySelectorAll('.notif-checkbox:checked');
      if (master) {
        master.checked = all.length > 0 && checked.length === all.length;
      }
      updateDeleteSelectedBtnState();
    }

    function updateDeleteSelectedBtnState() {
      const btn = document.getElementById('notif-delete-selected-btn');
      const checked = document.querySelectorAll('.notif-checkbox:checked');
      if (btn) {
        btn.disabled = checked.length === 0;
      }
    }

    async function deleteSelectedNotifications() {
      const checked = [...document.querySelectorAll('.notif-checkbox:checked')].map(cb => cb.value);
      if (checked.length === 0) return;

      if (!confirm(`Are you sure you want to delete ${checked.length} selected notification(s)?`)) return;

      try {
        const formData = new FormData();
        formData.append('ids', JSON.stringify(checked));
        const res = await fetch('ResidentDashboard.php?api=delete_notifications', {
          method: 'POST',
          body: formData
        });
        const data = await parseNotificationJsonResponse(res);
        if (data && data.success) {
          const master = document.getElementById('notif-select-all');
          if (master) master.checked = false;
          await fetchNotifications();
        }
      } catch (err) {
        console.error('Failed to delete notifications:', err);
      }
    }

    async function deleteOneNotification(id, event) {
      if (event) event.stopPropagation();
      if (!confirm('Delete this notification?')) return;

      try {
        const formData = new FormData();
        formData.append('ids', JSON.stringify([id]));
        const res = await fetch('ResidentDashboard.php?api=delete_notifications', {
          method: 'POST',
          body: formData
        });
        const data = await parseNotificationJsonResponse(res);
        if (data && data.success) {
          closeNotificationModal();
          await fetchNotifications();
        }
      } catch (err) {
        console.error('Failed to delete notification:', err);
      }
    }

    async function markAllNotificationsRead() {
      try {
        const formData = new FormData();
        formData.append('all', '1');
        const res = await fetch('ResidentDashboard.php?api=mark_read', {
          method: 'POST',
          body: formData
        });
        const data = await parseNotificationJsonResponse(res);
        if (data && data.success) {
          await fetchNotifications();
        }
      } catch (err) {
        console.error('Failed to mark notifications read:', err);
      }
    }

    async function openNotificationDetail(id) {
      const notifPanel = document.querySelector('[data-notification-panel]');
      if (notifPanel) notifPanel.classList.add('hidden');

      const item = currentNotifications.find(n => n.id == id);
      if (!item) return;

      document.getElementById('notif-modal-title').textContent = item.title || 'RHU Notification';
      document.getElementById('notif-modal-date').textContent = 'Sent: ' + (item.created_at || 'Just now');
      document.getElementById('notif-modal-body').textContent = item.message || '';

      const actionContainer = document.getElementById('notif-modal-action-container');
      const actionLink = document.getElementById('notif-modal-action-link');
      if (item.link_url) {
        actionLink.href = item.link_url;
        actionContainer.classList.remove('hidden');
      } else {
        actionContainer.classList.add('hidden');
      }

      const deleteBtn = document.getElementById('notif-modal-delete-btn');
      if (deleteBtn) {
        deleteBtn.onclick = (e) => deleteOneNotification(item.id, e);
      }

      const modal = document.getElementById('notification-detail-modal');
      modal.classList.remove('hidden');
      modal.classList.add('flex');

      if (!item.is_read) {
        try {
          const formData = new FormData();
          formData.append('id', item.id);
          await fetch('ResidentDashboard.php?api=mark_read', {
            method: 'POST',
            body: formData
          });
          item.is_read = 1;
          renderNotificationList(currentNotifications, Math.max(0, currentNotifications.filter(n => !n.is_read).length));
        } catch (e) {}
      }
    }

    function closeNotificationModal() {
      const modal = document.getElementById('notification-detail-modal');
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    }

    window.dashboardCurrentPages = {
      records: 1,
      certificates: 1,
      immunization: 1
    };

    window.goToDashboardPage = function(groupKey, targetPage, totalPages) {
      if (targetPage < 1 || targetPage > totalPages) return;
      window.dashboardCurrentPages[groupKey] = targetPage;

      const items = document.querySelectorAll(`[data-pagination-item="${groupKey}"]`);
      items.forEach(item => {
        const page = parseInt(item.dataset.page, 10);
        if (page === targetPage) {
          item.classList.remove('hidden');
        } else {
          item.classList.add('hidden');
        }
      });

      const prevBtn = document.getElementById(`${groupKey}-prev-btn`);
      const nextBtn = document.getElementById(`${groupKey}-next-btn`);
      if (prevBtn) prevBtn.disabled = (targetPage <= 1);
      if (nextBtn) nextBtn.disabled = (targetPage >= totalPages);

      const currentText = document.getElementById(`${groupKey}-current-page`);
      if (currentText) currentText.textContent = targetPage;

      const pageBtns = document.querySelectorAll(`[data-page-btn^="${groupKey}-"]`);
      pageBtns.forEach(btn => {
        const pNum = parseInt(btn.dataset.pageBtn.replace(`${groupKey}-`, ''), 10);
        if (pNum === targetPage) {
          btn.className = 'h-8 min-w-[2rem] px-2.5 rounded-xl text-xs font-extrabold transition-all bg-teal-600 text-white shadow-md';
        } else {
          btn.className = 'h-8 min-w-[2rem] px-2.5 rounded-xl text-xs font-extrabold transition-all border border-slate-200 dark:border-white/10 bg-white dark:bg-white/5 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10';
        }
      });

      // Smooth scroll back up to tab top
      const activePanel = document.querySelector(`[data-tab-panel]:not(.hidden)`);
      if (activePanel) {
        activePanel.scrollIntoView({
          behavior: 'smooth',
          block: 'start'
        });
      }

      if (window.lucide) lucide.createIcons();
    };

    window.changeDashboardPage = function(groupKey, delta, totalPages) {
      const current = window.dashboardCurrentPages[groupKey] || 1;
      window.goToDashboardPage(groupKey, current + delta, totalPages);
    };
  </script>

</body>

</html>
