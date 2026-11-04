<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
$stType = strtoupper((string) ($_SESSION['rhu_staff_login']['staff_type'] ?? ''));
if (empty($_SESSION['rhu_staff_login']) || ($stType !== 'SANITARY_INSPECTOR' && strpos($stType, 'SANITARY') === false && strpos($stType, 'INSPECTOR') === false)) {
    header('Location: RHULogin.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/patient-identifiers.php';
require_once __DIR__ . '/includes/portal.php';
require_once __DIR__ . '/includes/appointment-email-reminders.php';
if ($pdo instanceof PDO && function_exists('rhuSendUpcomingAppointmentEmailReminders')) {
    rhuSendUpcomingAppointmentEmailReminders($pdo);
}
// Use existing notification storage without running shared schema installers.
function sanitaryNotifyResident(PDO $pdo, int $residentId, string $message, string $link): void
{
    if (function_exists('portalNotifyResident')) {
        portalNotifyResident($pdo, $residentId, $message, $link);
        return;
    }
    $owner = $pdo->prepare("SELECT record_id FROM audit_logs WHERE action='Resident Dependent' AND module_name='Resident Portal' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $owner->execute([$residentId]);
    $recipient = (int)($owner->fetchColumn() ?: $residentId);
    $pdo->prepare('INSERT INTO portal_notifications (resident_id, message, link_url) VALUES (?, ?, ?)')->execute([$recipient, $message, $link]);
}

function sanitaryHandleNotificationApi(?PDO $pdo): void
{
    $api = $_GET['api'] ?? '';
    if (!in_array($api, ['get_notifications', 'mark_read', 'delete_notifications'], true)) return;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!$pdo) throw new RuntimeException('Notifications are unavailable.');
        $userId = (int)($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0);
        $role = strtoupper((string)($_SESSION['rhu_staff_login']['staff_type'] ?? 'SANITARY_INSPECTOR'));
        $roleAliases = [$role];
        if (str_contains($role, 'SANITARY') || str_contains($role, 'INSPECTOR')) {
            $roleAliases[] = 'SANITARY';
            $roleAliases[] = 'SANITARY_INSPECTOR';
        }
        $roleAliases = array_values(array_unique($roleAliases));
        $roleMarks = implode(',', array_fill(0, count($roleAliases), '?'));
        $scope = "((user_id=? AND user_id>0) OR audience_role IN ($roleMarks) OR audience_role IN ('RHU_STAFF','ALL') OR (user_id IS NULL AND audience_role IS NULL AND resident_id IS NULL))";
        if ($api === 'get_notifications') {
            $stmt = $pdo->prepare("SELECT id, message, link_url, is_read, created_at FROM portal_notifications WHERE $scope ORDER BY id DESC LIMIT 30");
            $stmt->execute(array_merge([$userId], $roleAliases));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true, 'notifications'=>$rows, 'unreadCount'=>count(array_filter($rows, static fn($r) => empty($r['is_read'])))]);
        } else {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false]); exit; }
            if ($api === 'mark_read') {
                $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE portal_notifications SET is_read=1 WHERE $scope" . ($id > 0 ? ' AND id=?' : ''));
                $params = array_merge([$userId], $roleAliases);
                if ($id > 0) $params[] = $id;
                $stmt->execute($params);
            } else {
                $json = json_decode(file_get_contents('php://input'), true) ?: [];
                $ids = $_POST['ids'] ?? $json['ids'] ?? ($_GET['id'] ?? []);
                $ids = is_array($ids) ? $ids : explode(',', (string)$ids);
                $ids = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
                if ($ids) {
                    $marks = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM portal_notifications WHERE $scope AND id IN ($marks)")->execute(array_merge([$userId], $roleAliases, $ids));
                }
            }
            echo json_encode(['success'=>true]);
        }
    } catch (Throwable $e) {
        error_log('Sanitary notifications: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['success'=>false, 'error'=>'Notifications are temporarily unavailable.']);
    }
    exit;
}
function workflowDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Enter a valid date.');
    return $value;
}

function sanitaryValidateSubmission(array $input): void
{
    $required = [
        'answer_consultation' => ['consultation_id', 'diagnosis'],
        'save_schedule' => ['target_name', 'scheduled_date'],
        'save_sanitary_inspection' => ['establishment', 'inspection_date', 'compliance_rate', 'violations'],
        'save_water_sample' => ['source_name', 'sample_date'],
        'save_food_inspection' => ['establishment', 'inspection_date'],
        'save_waste_record' => ['location', 'activity_date'],
        'save_vector_activity' => ['location', 'activity_date'],
        'issue_notice' => ['establishment', 'violations'],
        'update_notice_status' => ['notice_id', 'notice_status'],
        'save_campaign' => ['title', 'event_date'],
        'issue_inspection_notice' => ['inspection_id'],
    ];
    $action = $input['action'] ?? '';
    if (!is_string($action) || !isset($required[$action])) throw new InvalidArgumentException('Unknown form action.');
    foreach ($input as $value) {
        if (!is_scalar($value) || strlen((string)$value) > 8000) throw new InvalidArgumentException('A submitted field is invalid or too long.');
    }
    foreach ($required[$action] as $field) {
        if (trim((string)($input[$field] ?? '')) === '') throw new InvalidArgumentException('Please complete ' . str_replace('_', ' ', $field) . '.');
    }
    foreach (['scheduled_date','inspection_date','sample_date','activity_date','event_date','next_inspection_date','follow_up_date'] as $field) {
        if (!empty($input[$field])) workflowDate((string)$input[$field]);
    }
    if (!empty($input['next_inspection_date']) && $input['next_inspection_date'] < ($input['inspection_date'] ?? '')) throw new InvalidArgumentException('Next inspection cannot be earlier than the inspection date.');
    foreach (['compliance_rate'=>100, 'score'=>100, 'violations'=>100000, 'sites_found'=>100000, 'sites_treated'=>100000, 'audience_size'=>1000000] as $field => $maximum) {
        if (isset($input[$field]) && $input[$field] !== '') {
            $number = filter_var($input[$field], FILTER_VALIDATE_INT);
            if ($number === false || $number < 0 || $number > $maximum) throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }
    }
    foreach (['ph_level'=>14, 'turbidity'=>null, 'residual_chlorine'=>null] as $field => $maximum) {
        if (isset($input[$field]) && $input[$field] !== '' && (!is_numeric($input[$field]) || !is_finite((float)$input[$field]) || (float)$input[$field] < 0 || ($maximum !== null && (float)$input[$field] > $maximum))) throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
    }
    $choices = [
        'consultation_status'=>['Completed','In Progress','Scheduled','Referred'],
        'overall_status'=>['Safe','Watch','Unsafe','Contaminated'],
        'compliance_status'=>['Compliant','Conditional','Non-compliant'],
        'notice_status'=>['Pending','Issued','Resolved'],
        'priority'=>['Normal','High','Low'],
        'status'=>$action === 'save_sanitary_inspection' ? ['Compliant','Conditional','Non-compliant'] : ['Completed','Scheduled','In Progress','Cancelled'],
    ];
    foreach ($choices as $field=>$values) if (isset($input[$field]) && !in_array($input[$field], $values, true)) throw new InvalidArgumentException('Invalid ' . str_replace('_',' ',$field) . '.');
}

function sanitaryConsultationColumns(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!isset($cache[$key])) {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $columns = $pdo->query($sqlite ? 'PRAGMA table_info(consultations)' : 'SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_ASSOC);
        $cache[$key] = array_column($columns, $sqlite ? 'name' : 'Field');
    }
    return $cache[$key];
}


function workflowFields(string $text): array
{
    $fields = [];
    $key = null;
    $labels = ['type', 'status', 'compliance', 'violations', 'findings', 'next inspection', 'priority', 'time', 'notes', 'ph', 'turbidity', 'coliform', 'residual chlorine', 'remarks', 'score', 'volume', 'sites found', 'sites treated', 'method', 'audience', 'materials', 'establishment', 'barangay', 'follow-up', 'notice status', 'destination', 'received date', 'external results'];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^([A-Za-z][A-Za-z _-]*):\s*(.*)$/', $line, $m) && in_array(strtolower(trim($m[1])), $labels, true)) {
            $key = strtolower(trim($m[1]));
            $fields[$key] = trim($m[2]);
        } elseif ($key !== null) {
            $fields[$key] .= "\n" . $line;
        }
    }
    return array_map('trim', $fields);
}


function workflowAudit(PDO $pdo, int $staffId, string $action, string $module, int $recordId, string $description): int
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (health_worker_id, action, module_name, record_id, description, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$staffId ?: null, $action, $module, $recordId ?: null, $description]);
    return (int)$pdo->lastInsertId();
}


function workflowInspection(array $row): array
{
    $f = workflowFields((string)($row['description'] ?? ''));
    $food = str_starts_with((string)$row['title'], 'Food Safety Inspection:');
    $status = $food ? ($f['compliance'] ?? 'Unknown') : ($f['status'] ?? 'Unknown');
    $flag = match (strtolower($status)) {
        'compliant', 'passed' => 'passed',
        'conditional', 'needs follow-up' => 'conditional',
        'non-compliant', 'non compliant', 'failed' => 'failed',
        default => 'unknown',
    };
    return [
        'id' => (int)$row['id'], 'establishment' => preg_replace('/^[^:]+:\s*/', '', $row['title']),
        'barangay' => $row['barangay'] ?? '', 'inspection_date' => substr($row['start_datetime'], 0, 10),
        'next_inspection_date' => $f['next inspection'] ?? '', 'status' => $status, 'flag' => $flag,
        'compliance_rate' => (float)($food ? ($f['score'] ?? 0) : ($f['compliance'] ?? 0)),
        'violations' => (int)($f['violations'] ?? 0), 'findings' => $f['findings'] ?? '',
        'inspector_name' => $row['inspector_name'] ?? 'Sanitary Inspector', 'type' => $f['type'] ?? 'Establishment',
    ];
}


function workflowNotice(array $row): array
{
    $f = workflowFields((string)$row['description']);
    preg_match('/\bSN-[A-Z0-9-]+/i', (string)$row['description'], $number);
    $noticeStatus = $f['notice status'] ?? 'Issued';
    if ($noticeStatus === '' || !in_array($noticeStatus, ['Pending', 'Issued', 'Resolved'], true)) {
        $noticeStatus = 'Issued';
    }
    return [
        'id' => (int)$row['id'], 'inspection_id' => (int)($row['record_id'] ?? 0),
        'notice_number' => $number[0] ?? ('SN-AUD-' . $row['id']),
        'establishment' => $f['establishment'] ?? '', 'barangay' => $f['barangay'] ?? '',
        'issued_date' => substr($row['created_at'], 0, 10), 'violations' => (int)($f['violations'] ?? 0),
        'findings' => $f['findings'] ?? $row['description'], 'follow_up_date' => $f['follow-up'] ?? '',
        'notice_status' => $noticeStatus,
    ];
}


function workflowChangeNoticeStatus(PDO $pdo, int $noticeId, string $status, int $staffId): void
{
    if (!in_array($status, ['Resolved', 'Pending', 'Issued'], true)) throw new InvalidArgumentException('Invalid notice status.');
    $normalizedStatus = $status;
    $stmt = $pdo->prepare("SELECT id FROM audit_logs WHERE id=? AND module_name='Sanitary' AND (action='Sanitary Notice Issued' OR action LIKE 'Issued inspection notice %')");
    $stmt->execute([$noticeId]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Notice not found.');
    workflowAudit($pdo, $staffId, 'Sanitary Notice Status Changed', 'Sanitary', $noticeId, $normalizedStatus);
}


function workflowLoadNotices(PDO $pdo): array
{
    $rows = $pdo->query("SELECT * FROM audit_logs WHERE module_name='Sanitary' AND (action='Sanitary Notice Issued' OR action LIKE 'Issued inspection notice %') ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $states = [];
    foreach ($pdo->query("SELECT record_id, action, description FROM audit_logs WHERE module_name='Sanitary' AND (action='Sanitary Notice Status Changed' OR action LIKE 'Updated sanitary notice status to %') ORDER BY id") as $state) {
        $status = $state['action'] === 'Sanitary Notice Status Changed' ? $state['description'] : substr($state['action'], strlen('Updated sanitary notice status to '));
        $normalizedStatus = in_array($status, ['Resolved', 'Pending', 'Issued'], true) ? $status : 'Issued';
        if (in_array($normalizedStatus, ['Resolved', 'Pending', 'Issued'], true)) $states[(int)$state['record_id']] = $normalizedStatus;
    }
    return array_map(static function ($row) use ($states) {
        $notice = workflowNotice($row);
        $notice['notice_status'] = $states[$notice['id']] ?? $notice['notice_status'];
        return $notice;
    }, $rows);
}


function sanitaryBarangayId(PDO $pdo, string $name)
{
    $name = trim($name);
    if ($name === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM barangays WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id <= 0) throw new InvalidArgumentException('Select a barangay registered in the system.');
    return $id;
}


function sanitaryRecordEvent(PDO $pdo, string $title, string $barangay, string $date, string $location, string $description, int $staffId): int
{
    $eventDate = workflowDate($date !== '' ? $date : date('Y-m-d'));
    $fields = workflowFields($description);
    $time = '08:00:00';
    if (!empty($fields['time'])) {
        $parsedTime = DateTimeImmutable::createFromFormat('!H:i', $fields['time']) ?: DateTimeImmutable::createFromFormat('!h:i A', strtoupper($fields['time']));
        if (!$parsedTime || ($parsedTime->format('H:i') !== $fields['time'] && $parsedTime->format('h:i A') !== strtoupper($fields['time']))) throw new InvalidArgumentException('Enter a valid schedule time.');
        $time = $parsedTime->format('H:i:s');
    }
    $eventStatus = str_starts_with($title, 'Sanitation Schedule:') ? 'Scheduled' : ($fields['status'] ?? 'Completed');
    if (!in_array($eventStatus, ['Scheduled', 'Completed', 'In Progress', 'Cancelled'], true)) $eventStatus = 'Completed';
    $stmt = $pdo->prepare("INSERT INTO events (title, description, barangay_id, location, start_datetime, organizer_id, status, created_at)
        VALUES (:title, :description, :barangay_id, :location, :start_at, :organizer, :event_status, NOW())");
    $stmt->execute([
        'title' => $title,
        'description' => $description,
        'barangay_id' => sanitaryBarangayId($pdo, $barangay),
        'location' => $location !== '' ? $location : $barangay,
        'start_at' => $eventDate . ' ' . $time,
        'organizer' => $staffId ?: null,
        'event_status' => $eventStatus,
    ]);
    return (int) $pdo->lastInsertId();
}



function workflowSanitaryRows(array $eventRows): array
{
    $inspections = $schedules = $waterSamples = $foodInspections = $wasteRecords = $vectorActivities = $campaigns = [];
    foreach ($eventRows as $row) {
        $title = (string)$row['title'];
        $notes = (string)($row['description'] ?? '');
        $f = workflowFields($notes);
        $date = substr($row['start_datetime'], 0, 10);
        $name = preg_replace('/^[^:]+:\s*/', '', $title);
        $barangay = $row['barangay'] ?? '';
        $base = ['id' => $row['id'], 'barangay' => $barangay, 'status' => $f['status'] ?? $row['status'], 'notes' => $f['notes'] ?? '', 'location' => $row['location'] ?? '', 'activity_date' => $date];
        if (str_starts_with($title, 'Sanitary Inspection:') || str_starts_with($title, 'Food Safety Inspection:')) {
            $detail = workflowInspection($row);
            $inspections[] = ['EV' . $row['id'], $name, $detail['type'], $barangay, $date, $detail['next_inspection_date'], $detail['flag'], $detail['compliance_rate'], $detail['violations'], preg_split('/\R/', $detail['findings']), $detail['inspector_name'], (int)$row['id']];
        }
        if (str_starts_with($title, 'Sanitation Schedule:')) $schedules[] = $base + ['target_name' => $name, 'target_type' => $f['type'] ?? 'Sanitation', 'scheduled_date' => $date, 'scheduled_time' => $f['time'] ?? substr($row['start_datetime'], 11, 5), 'priority' => $f['priority'] ?? 'Normal', 'schedule_status' => $f['status'] ?? $row['status']];
        if (str_starts_with($title, 'Water Quality Sample:')) $waterSamples[] = $base + ['source_name' => $name, 'source_type' => $f['type'] ?? 'Water Source', 'sample_date' => $date, 'overall_status' => $f['status'] ?? 'Recorded', 'ph_level' => $f['ph'] ?? '', 'turbidity' => $f['turbidity'] ?? '', 'coliform_result' => $f['coliform'] ?? '', 'residual_chlorine' => $f['residual chlorine'] ?? '', 'remarks' => $f['remarks'] ?? ''];
        if (str_starts_with($title, 'Food Safety Inspection:')) $foodInspections[] = $base + ['establishment' => $name, 'establishment_type' => $f['type'] ?? 'Food Establishment', 'inspection_date' => $date, 'compliance_status' => $f['compliance'] ?? 'Unknown', 'score' => (int)($f['score'] ?? 0), 'findings' => $f['findings'] ?? '', 'next_inspection_date' => $f['next inspection'] ?? ''];
        if (str_starts_with($title, 'Waste Management:')) $wasteRecords[] = $base + ['record_type' => $name, 'volume_estimate' => $f['volume'] ?? ''];
        if (str_starts_with($title, 'Vector Control:')) $vectorActivities[] = $base + ['activity_type' => $name, 'sites_found' => (int)($f['sites found'] ?? 0), 'sites_treated' => (int)($f['sites treated'] ?? 0), 'method_used' => $f['method'] ?? ''];
        if (str_contains(strtolower($title), 'campaign') || (isset($f['audience']) && isset($f['materials']))) $campaigns[] = $base + ['title' => str_starts_with($title, 'Community Campaign:') ? $name : $title, 'campaign_type' => $f['type'] ?? 'Community Campaign', 'event_date' => $date, 'venue' => $row['location'] ?? '', 'audience_size' => (int)($f['audience'] ?? 0), 'materials' => $f['materials'] ?? ''];
    }
    return compact('inspections', 'schedules', 'waterSamples', 'foodInspections', 'wasteRecords', 'vectorActivities', 'campaigns');
}

sanitaryHandleNotificationApi($pdo);
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function iconSvg(string $name, string $class = 'w-5 h-5'): string
{
    $icons = [
        'menu' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'logout' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/><path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/></svg>',
        'close' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'shield' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    ];
    return $icons[$name] ?? '';
}
function tabUrl(string $tab, array $extra = []): string
{
    return '?' . http_build_query(array_merge(['tab' => $tab], $extra));
}
$barangayOptions = [
    'Aga',
    'Balaytigue',
    'Balok-Balok',
    'Banilad',
    'Barangay 1 (Pob.)',
    'Barangay 2 (Pob.)',
    'Barangay 3 (Pob.)',
    'Barangay 4 (Pob.)',
    'Barangay 5 (Pob.)',
    'Barangay 6 (Pob.)',
    'Barangay 7 (Pob.)',
    'Barangay 8 (Pob.)',
    'Barangay 9 (Pob.)',
    'Barangay 10 (Pob.)',
    'Barangay 11 (Pob.)',
    'Barangay 12 (Pob.)',
    'Bilaran',
    'Bucana',
    'Bulihan',
    'Bunducan',
    'Butucan',
    'Calayo',
    'Catandaan',
    'Cagunan',
    'Dayap',
    'Kaylaway',
    'Kayrillaw',
    'Latag',
    'Looc',
    'Lumbangan',
    'Malapad na Bato',
    'Mataas na Pulo',
    'Maugat',
    'Munting Indang',
    'Natipuan',
    'Pantalan',
    'Papaya',
    'Putat',
    'Reparo',
    'Talangan',
    'Tumalim',
    'Utod',
    'Wawa',
];

$sanitaryFlashSuccess = $_SESSION['sanitary_flash_success'] ?? '';
$sanitaryFlashError = $_SESSION['sanitary_flash_error'] ?? '';
unset($_SESSION['sanitary_flash_success'], $_SESSION['sanitary_flash_error']);
$sanitaryStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
$sanitaryUserId = (int) ($_SESSION['rhu_staff_login']['id'] ?? $_SESSION['rhu_staff_login']['user_id'] ?? 0);

if (!empty($pdo)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sanitary_records (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(80) NOT NULL,
            title VARCHAR(200) NOT NULL,
            barangay VARCHAR(120) NULL,
            record_date DATE NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Recorded',
            reference_id BIGINT UNSIGNED NULL,
            resident_id INT NULL,
            staff_id INT NULL,
            details_json JSON NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sanitary_type_date (record_type, record_date),
            INDEX idx_sanitary_staff (staff_id),
            INDEX idx_sanitary_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
}

function sanitarySaveRecord(PDO $pdo, string $recordType, string $title, string $barangay, ?string $recordDate, string $status, array $details, int $staffId, ?int $referenceId = null, ?int $residentId = null, string $notes = ''): void
{
    if (!function_exists('rhuTableExists') || !rhuTableExists($pdo, 'sanitary_records')) return;
    $stmt = $pdo->prepare("INSERT INTO sanitary_records (record_type, title, barangay, record_date, status, reference_id, resident_id, staff_id, details_json, notes, created_at) VALUES (:record_type, :title, :barangay, :record_date, :status, :reference_id, :resident_id, :staff_id, :details_json, :notes, NOW())");
    $stmt->execute([
        'record_type' => $recordType,
        'title' => $title,
        'barangay' => $barangay,
        'record_date' => $recordDate,
        'status' => $status,
        'reference_id' => $referenceId,
        'resident_id' => $residentId,
        'staff_id' => $staffId ?: null,
        'details_json' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'notes' => $notes,
    ]);
    fhsisSaveInlineServices($pdo, 'sanitary_records', (int)$pdo->lastInsertId(), (int)$residentId, $_POST, $_SESSION);
}


if (!empty($pdo) && isset($_GET['print_inspection'])) {
    $printId = (int) $_GET['print_inspection'];
    $printStmt = $pdo->prepare("SELECT ev.*, b.name AS barangay, CONCAT(hw.first_name,' ',hw.last_name) AS inspector_name
        FROM events ev LEFT JOIN barangays b ON b.id=ev.barangay_id LEFT JOIN health_workers hw ON hw.id=ev.organizer_id
        WHERE ev.id=:id AND (ev.title LIKE 'Sanitary Inspection:%' OR ev.title LIKE 'Food Safety Inspection:%') LIMIT 1");
    $printStmt->execute(['id' => $printId]);
    $event = $printStmt->fetch(PDO::FETCH_ASSOC);
    $report = $event ? workflowInspection($event) : null;
    if (!$report) {
        http_response_code(404);
        exit('Inspection report not found.');
    }
    ?><!doctype html>
    <html>

    <head>
        <meta charset="utf-8">
        <title>Inspection Report <?= e('EV' . $report['id']) ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                color: #172033;
                margin: 40px
            }

            .print-button {
                margin-bottom: 18px;
                border: 0;
                border-radius: 8px;
                background: #059669;
                color: #fff;
                padding: 10px 16px;
                font-weight: 700;
                cursor: pointer
            }

            .head {
                display: grid;
                grid-template-columns: 92px 1fr;
                gap: 12px;
                align-items: center;
                border-bottom: 3px solid #0f766e;
                padding-bottom: 16px;
                margin-bottom: 24px
            }

            .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px
            }

            .box {
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                padding: 12px
            }

            .full {
                grid-column: 1/-1
            }

            .label {
                font-size: 11px;
                text-transform: uppercase;
                color: #64748b;
                font-weight: bold
            }

            .value {
                margin-top: 5px;
                font-size: 14px
            }

            .sign {
                margin-top: 70px;
                width: 260px;
                border-top: 1px solid #334155;
                padding-top: 6px;
                text-align: center
            }

            @media print {
                .print-button {
                    display: none
                }
            }
        </style>
    </head>

    <body>
        <button class="print-button" onclick="window.print()">Print this report</button>
        <div class="head">
            <img src="assets/images/nasugbu_seal.png" alt="Seal" style="width:82px;height:82px;object-fit:contain"
                onerror="this.style.display='none'">
            <div>
                <p
                    style="margin:0;color:#475569;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">
                    Municipality of Nasugbu · Rural Health Unit I</p>
                <h1 style="margin:0;color:#0f766e;font-size:25px">Sanitation Inspection Report</h1>
                <p style="margin:7px 0 0;color:#475569">Report <?= e('EV' . $report['id']) ?></p>
            </div>
        </div>
        <div class="grid">
            <div class="box">
                <div class="label">Establishment</div>
                <div class="value"><?= e($report['establishment']) ?></div>
            </div>
            <div class="box">
                <div class="label">Barangay</div>
                <div class="value"><?= e($report['barangay']) ?></div>
            </div>
            <div class="box">
                <div class="label">Inspection Date</div>
                <div class="value"><?= e($report['inspection_date']) ?></div>
            </div>
            <div class="box">
                <div class="label">Next Inspection</div>
                <div class="value"><?= e($report['next_inspection_date'] ?: 'Not scheduled') ?></div>
            </div>
            <div class="box">
                <div class="label">Status</div>
                <div class="value"><?= e($report['status']) ?></div>
            </div>
            <div class="box">
                <div class="label">Compliance</div>
                <div class="value"><?= e($report['compliance_rate']) ?>% · <?= (int) $report['violations'] ?> violation(s)
                </div>
            </div>
            <div class="box full">
                <div class="label">Findings</div>
                <div class="value"><?= nl2br(e($report['findings'] ?: 'No findings recorded.')) ?></div>
            </div>
        </div>
        <div class="sign"><?= e($report['inspector_name'] ?: 'Sanitary Inspector') ?><br><small>Inspecting Officer</small>
        </div>
        <script>window.addEventListener('load', () => window.print());</script>
    </body>

    </html>
    <?php exit;
}


$tabs = [
    'overview' => ['Overview', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'inspections' => ['Inspections', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>'],
    'water' => ['Water Quality', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>'],
    'food' => ['Food Safety', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>'],
    'waste' => ['Waste Mgmt', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>'],
    'vector' => ['Vector Control', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m8 12 2 2 4-4"/></svg>'],
    'violations' => ['Violations', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'],
    'community' => ['Community', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    'reports' => ['Reports', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>'],
];
$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab]))
    $tab = 'overview';
$modal = $_GET['modal'] ?? '';

require_once __DIR__ . '/includes/resident-qr.php';
require_once __DIR__ . '/includes/fhsis-services.php';

$residentOptions = $staffOptions = [];
$inspections = $schedules = $waterSamples = $foodInspections = [];
$wasteRecords = $vectorActivities = $notices = $campaigns = [];
$sanitaryConsultations = [];
$sanitaryLoadErrors = [];
if (empty($pdo)) $sanitaryLoadErrors[] = 'The database is unavailable. Records cannot be loaded or saved right now.';

if (!empty($pdo)) {
    try {
        $residentOptions = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM residents r WHERE " . rhuLivingResidentWhereSql('r') . " ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $staffOptions = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM health_workers ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dbBrgy = $pdo->query("SELECT name FROM barangays ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($dbBrgy as $_b) {
            $_b = trim((string) $_b);
            if ($_b !== '' && !in_array($_b, $barangayOptions, true))
                $barangayOptions[] = $_b;
        }
        sort($barangayOptions, SORT_NATURAL | SORT_FLAG_CASE);
    } catch (Throwable $e) {
        error_log('Sanitary dashboard load: ' . $e->getMessage());
        $sanitaryLoadErrors[] = 'Some records could not be loaded. Please refresh or contact your administrator.';
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!portalVerifyCsrf()) { http_response_code(419); exit('Form expired. Refresh the page and retry.'); }
        $action = $_POST['action'] ?? '';
        try {
            sanitaryValidateSubmission($_POST);
            $pdo->beginTransaction();
            if ($action === 'answer_consultation') {
                $cslId = (int) ($_POST['consultation_id'] ?? 0);
                $owner = $pdo->prepare("SELECT resident_id FROM consultations WHERE id=? AND (health_worker_id=? OR physician_id=? OR chief_complaint LIKE '%Sanitary%' OR chief_complaint LIKE '%Inspection%' OR chief_complaint LIKE '%Clearance%' OR remarks LIKE '%Sanitary%' OR remarks LIKE '%Inspection%' OR remarks LIKE '%Clearance%') FOR UPDATE");
                $owner->execute([$cslId, $sanitaryStaffId, $sanitaryStaffId]);
                $resId = (int)$owner->fetchColumn();
                if ($resId <= 0) throw new RuntimeException('This consultation was not found or is not assigned to you.');
                if ($cslId > 0) {
                    $pdo->prepare("UPDATE consultations SET diagnosis=?, remarks=?, updated_at=NOW() WHERE id=?")
                        ->execute([trim($_POST['diagnosis'] ?? ''), trim($_POST['consultation_notes'] ?? '') . "\nStatus: " . trim($_POST['consultation_status'] ?? 'Completed'), $cslId]);
                    $optionalColumns = sanitaryConsultationColumns($pdo);
                    foreach (['consultation_status' => trim($_POST['consultation_status'] ?? 'Completed'), 'consultation_notes' => trim($_POST['consultation_notes'] ?? '')] as $column => $value) {
                        if (in_array($column, $optionalColumns, true)) $pdo->prepare("UPDATE consultations SET `$column`=? WHERE id=?")->execute([$value, $cslId]);
                    }
                    fhsisSaveInlineServices($pdo, 'consultations', $cslId, $resId, $_POST, $_SESSION);
                    $savedHealthRecord = portalSaveHealthRecordEntry($pdo, $resId, [
                        'record_type' => 'Sanitary consultation response',
                        'diagnosis' => trim($_POST['diagnosis'] ?? ''),
                        'notes' => trim($_POST['consultation_notes'] ?? ''),
                        'status' => trim($_POST['consultation_status'] ?? 'Completed'),
                        'recorded_by_id' => $sanitaryStaffId ?: null,
                    ]);
                    if (!$savedHealthRecord) throw new RuntimeException('The resident health record could not be saved. Please retry.');
                    if ($resId > 0) {
                        rhuNotifySavedResidentAppointment($pdo, $cslId, 'Sanitary appointment');
                        sanitaryNotifyResident($pdo, $resId, 'Your Sanitary request was updated. Status: ' . trim($_POST['consultation_status'] ?? ''), 'ResidentDashboard.php?tab=records');
                    }
                    if (function_exists('portalNotifyRhuStaff')) {
                        portalNotifyRhuStaff($pdo, 'Sanitary updated consultation #' . $cslId . ' for resident #' . $resId . '. Status: ' . trim($_POST['consultation_status'] ?? 'Completed'), 'RHUAdminDashboard.php?tab=consultations');
                    }
                    $_SESSION['sanitary_flash_success'] = 'Consultation updated and resident notified.';
                }
                $pdo->commit();
                header('Location: ' . tabUrl('overview'));
                exit;
            }
            if ($action === 'save_schedule') {
                sanitaryRecordEvent($pdo, 'Sanitation Schedule: ' . trim($_POST['target_name'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['scheduled_date'] ?? date('Y-m-d'), trim($_POST['target_name'] ?? ''), 'Type: ' . trim($_POST['target_type'] ?? 'Food Establishment') . "\nPriority: " . trim($_POST['priority'] ?? 'Normal') . "\nTime: " . trim($_POST['scheduled_time'] ?? '') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                sanitarySaveRecord($pdo, 'schedule', trim($_POST['target_name'] ?? 'Sanitation Schedule'), trim($_POST['barangay'] ?? ''), $_POST['scheduled_date'] ?? date('Y-m-d'), trim($_POST['status'] ?? 'Scheduled'), [
                    'target_type' => trim($_POST['target_type'] ?? 'Food Establishment'),
                    'scheduled_time' => trim($_POST['scheduled_time'] ?? ''),
                    'priority' => trim($_POST['priority'] ?? 'Normal'),
                ], $sanitaryStaffId, null, null, trim($_POST['notes'] ?? ''));
                $_SESSION['sanitary_flash_success'] = 'Inspection scheduled.';
                $pdo->commit();
                header('Location: ' . tabUrl('inspections'));
                exit;
            }
            if ($action === 'save_sanitary_inspection') {
                sanitaryRecordEvent($pdo, 'Sanitary Inspection: ' . trim($_POST['establishment'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['inspection_date'] ?? date('Y-m-d'), trim($_POST['establishment'] ?? ''), 'Status: ' . trim($_POST['status'] ?? 'Compliant') . "\nCompliance: " . trim($_POST['compliance_rate'] ?? '0') . "%\nViolations: " . trim($_POST['violations'] ?? '0') . "\nFindings: " . trim($_POST['findings'] ?? '') . "\nNext inspection: " . trim($_POST['next_inspection_date'] ?? ''), (int) ($_POST['inspector_staff_id'] ?? 0) ?: $sanitaryStaffId);
                sanitarySaveRecord($pdo, 'sanitary_inspection', trim($_POST['establishment'] ?? 'Sanitary Inspection'), trim($_POST['barangay'] ?? ''), $_POST['inspection_date'] ?? date('Y-m-d'), trim($_POST['status'] ?? 'Compliant'), [
                    'compliance_rate' => trim($_POST['compliance_rate'] ?? '0'),
                    'violations' => trim($_POST['violations'] ?? '0'),
                    'next_inspection_date' => trim($_POST['next_inspection_date'] ?? ''),
                ], (int) ($_POST['inspector_staff_id'] ?? 0) ?: $sanitaryStaffId, null, null, trim($_POST['findings'] ?? ''));
                $_SESSION['sanitary_flash_success'] = 'Inspection saved.';
                $pdo->commit();
                header('Location: ' . tabUrl('inspections'));
                exit;
            }
            if ($action === 'save_water_sample') {
                sanitaryRecordEvent($pdo, 'Water Quality Sample: ' . trim($_POST['source_name'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['sample_date'] ?? date('Y-m-d'), trim($_POST['source_name'] ?? ''), 'Type: ' . trim($_POST['source_type'] ?? 'Well') . "\nStatus: " . trim($_POST['overall_status'] ?? 'Safe') . "\npH: " . trim($_POST['ph_level'] ?? '') . "\nTurbidity: " . trim($_POST['turbidity'] ?? '') . "\nColiform: " . trim($_POST['coliform_result'] ?? '') . "\nResidual chlorine: " . trim($_POST['residual_chlorine'] ?? '') . "\nRemarks: " . trim($_POST['remarks'] ?? ''), $sanitaryStaffId);
                sanitarySaveRecord($pdo, 'water_quality', trim($_POST['source_name'] ?? 'Water Quality Sample'), trim($_POST['barangay'] ?? ''), $_POST['sample_date'] ?? date('Y-m-d'), trim($_POST['overall_status'] ?? 'Safe'), [
                    'source_type' => trim($_POST['source_type'] ?? 'Well'),
                    'ph_level' => trim($_POST['ph_level'] ?? ''),
                    'turbidity' => trim($_POST['turbidity'] ?? ''),
                    'coliform_result' => trim($_POST['coliform_result'] ?? ''),
                    'residual_chlorine' => trim($_POST['residual_chlorine'] ?? ''),
                ], $sanitaryStaffId, null, null, trim($_POST['remarks'] ?? ''));
                $_SESSION['sanitary_flash_success'] = 'Water sample logged.';
                $pdo->commit();
                header('Location: ' . tabUrl('water'));
                exit;
            }
            if ($action === 'save_food_inspection') {
                sanitaryRecordEvent($pdo, 'Food Safety Inspection: ' . trim($_POST['establishment'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['inspection_date'] ?? date('Y-m-d'), trim($_POST['establishment'] ?? ''), 'Type: ' . trim($_POST['establishment_type'] ?? 'Eatery') . "\nCompliance: " . trim($_POST['compliance_status'] ?? 'Compliant') . "\nScore: " . trim($_POST['score'] ?? '0') . "\nFindings: " . trim($_POST['findings'] ?? '') . "\nNext inspection: " . trim($_POST['next_inspection_date'] ?? ''), $sanitaryStaffId);
                sanitarySaveRecord($pdo, 'food_safety', trim($_POST['establishment'] ?? 'Food Safety Inspection'), trim($_POST['barangay'] ?? ''), $_POST['inspection_date'] ?? date('Y-m-d'), trim($_POST['compliance_status'] ?? 'Compliant'), [
                    'establishment_type' => trim($_POST['establishment_type'] ?? 'Eatery'),
                    'score' => (int)($_POST['score'] ?? 0),
                    'next_inspection_date' => trim($_POST['next_inspection_date'] ?? ''),
                ], $sanitaryStaffId, null, null, trim($_POST['findings'] ?? ''));
                $_SESSION['sanitary_flash_success'] = 'Food inspection saved.';
                $pdo->commit();
                header('Location: ' . tabUrl('food'));
                exit;
            }
            if ($action === 'save_waste_record') {
                sanitaryRecordEvent($pdo, 'Waste Management: ' . trim($_POST['record_type'] ?? 'Garbage Collection'), trim($_POST['barangay'] ?? ''), $_POST['activity_date'] ?? date('Y-m-d'), trim($_POST['location'] ?? ''), 'Status: ' . trim($_POST['status'] ?? 'Completed') . "\nVolume: " . trim($_POST['volume_estimate'] ?? '') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                sanitarySaveRecord($pdo, 'waste_management', trim($_POST['location'] ?? 'Waste Management'), trim($_POST['barangay'] ?? ''), $_POST['activity_date'] ?? date('Y-m-d'), trim($_POST['status'] ?? 'Completed'), [
                    'record_type' => trim($_POST['record_type'] ?? 'Garbage Collection'),
                    'volume_estimate' => trim($_POST['volume_estimate'] ?? ''),
                ], $sanitaryStaffId, null, null, trim($_POST['notes'] ?? ''));
                $_SESSION['sanitary_flash_success'] = 'Waste record saved.';
                $pdo->commit();
                header('Location: ' . tabUrl('waste'));
                exit;
            }
            if ($action === 'save_vector_activity') {
                sanitaryRecordEvent($pdo, 'Vector Control: ' . trim($_POST['activity_type'] ?? 'Breeding Site Inspection'), trim($_POST['barangay'] ?? ''), $_POST['activity_date'] ?? date('Y-m-d'), trim($_POST['location'] ?? ''), 'Status: ' . trim($_POST['status'] ?? 'Completed') . "\nSites found: " . trim($_POST['sites_found'] ?? '0') . "\nSites treated: " . trim($_POST['sites_treated'] ?? '0') . "\nMethod: " . trim($_POST['method_used'] ?? '') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                sanitarySaveRecord($pdo, 'vector_control', trim($_POST['location'] ?? 'Vector Control'), trim($_POST['barangay'] ?? ''), $_POST['activity_date'] ?? date('Y-m-d'), trim($_POST['status'] ?? 'Completed'), [
                    'activity_type' => trim($_POST['activity_type'] ?? 'Breeding Site Inspection'),
                    'sites_found' => (int)($_POST['sites_found'] ?? 0),
                    'sites_treated' => (int)($_POST['sites_treated'] ?? 0),
                    'method_used' => trim($_POST['method_used'] ?? ''),
                ], $sanitaryStaffId, null, null, trim($_POST['notes'] ?? ''));
                $_SESSION['sanitary_flash_success'] = 'Vector activity recorded.';
                $pdo->commit();
                header('Location: ' . tabUrl('vector'));
                exit;
            }
            if ($action === 'issue_notice') {
                $violations = (int) ($_POST['violations'] ?? 0);
                $noticeStatus = isset($_POST['notice_status']) && in_array($_POST['notice_status'], ['Pending', 'Issued', 'Resolved'], true)
                    ? $_POST['notice_status']
                    : 'Issued';
                if ($violations <= 0)
                    throw new RuntimeException('A notice requires at least one violation.');
                $noticeNumber = 'SN-' . date('Ymd') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
                $noticeDescription = $noticeNumber
                    . "\nEstablishment: " . trim($_POST['establishment'] ?? '')
                    . "\nBarangay: " . trim($_POST['barangay'] ?? '')
                    . "\nViolations: " . $violations
                    . "\nFindings: " . trim($_POST['findings'] ?? '')
                    . "\nFollow-up: " . trim($_POST['follow_up_date'] ?? '')
                    . "\nNotice Status: " . $noticeStatus;
                $pdo->prepare("INSERT INTO audit_logs (health_worker_id, action, module_name, description, ip_address, user_agent, created_at) VALUES (?, 'Sanitary Notice Issued', 'Sanitary', ?, ?, ?, NOW())")
                    ->execute([$sanitaryStaffId ?: null, $noticeDescription, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
                sanitarySaveRecord($pdo, 'notice', trim($_POST['establishment'] ?? 'Sanitary Notice'), trim($_POST['barangay'] ?? ''), date('Y-m-d'), $noticeStatus, [
                    'notice_number' => $noticeNumber,
                    'violations' => $violations,
                    'follow_up_date' => trim($_POST['follow_up_date'] ?? ''),
                ], $sanitaryStaffId, null, null, trim($_POST['findings'] ?? ''));
                $_SESSION['sanitary_flash_success'] = "Notice {$noticeNumber} issued.";
                $pdo->commit();
                header('Location: ' . tabUrl('violations'));
                exit;
            }
            if ($action === 'update_notice_status') {
                workflowChangeNoticeStatus($pdo, (int)($_POST['notice_id'] ?? 0), $_POST['notice_status'] ?? '', $sanitaryStaffId);
                if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'sanitary_records')) {
                    $pdo->prepare("UPDATE sanitary_records SET status = :notice_status WHERE record_type = 'notice' AND (id = :notice_id OR reference_id = :notice_id)")->execute([
                        'notice_status' => $_POST['notice_status'] ?? '',
                        'notice_id' => (int)($_POST['notice_id'] ?? 0),
                    ]);
                }
                $_SESSION['sanitary_flash_success'] = 'Notice status updated.';
                $pdo->commit();
                header('Location: ' . tabUrl('violations'));
                exit;
            }
            if ($action === 'save_campaign') {
                sanitaryRecordEvent($pdo, 'Community Campaign: ' . trim($_POST['title'] ?? 'Community Campaign'), trim($_POST['barangay'] ?? ''), $_POST['event_date'] ?? date('Y-m-d'), trim($_POST['venue'] ?? ''), 'Type: ' . trim($_POST['campaign_type'] ?? 'Hygiene Awareness') . "\nAudience: " . trim($_POST['audience_size'] ?? '') . "\nMaterials: " . trim($_POST['materials'] ?? '') . "\nStatus: " . trim($_POST['status'] ?? 'Scheduled') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                sanitarySaveRecord($pdo, 'campaign', trim($_POST['title'] ?? 'Community Campaign'), trim($_POST['barangay'] ?? ''), $_POST['event_date'] ?? date('Y-m-d'), trim($_POST['status'] ?? 'Scheduled'), [
                    'campaign_type' => trim($_POST['campaign_type'] ?? 'Hygiene Awareness'),
                    'venue' => trim($_POST['venue'] ?? ''),
                    'audience_size' => ($_POST['audience_size'] ?? '') !== '' ? (int)$_POST['audience_size'] : null,
                    'materials' => trim($_POST['materials'] ?? ''),
                ], $sanitaryStaffId, null, null, trim($_POST['notes'] ?? ''));
                $_SESSION['sanitary_flash_success'] = 'Campaign saved.';
                $pdo->commit();
                header('Location: ' . tabUrl('community'));
                exit;
            }
            if ($action === 'issue_inspection_notice') {
                $inspectionId = (int) ($_POST['inspection_id'] ?? 0);
                $noticeNumber = 'SN-' . date('Ymd') . '-' . str_pad((string) max(1, $inspectionId), 5, '0', STR_PAD_LEFT);
                $inspectionCheck = $pdo->prepare("SELECT ev.*, b.name AS barangay FROM events ev LEFT JOIN barangays b ON b.id=ev.barangay_id WHERE ev.id=? AND (ev.title LIKE 'Sanitary Inspection:%' OR ev.title LIKE 'Food Safety Inspection:%')");
                $inspectionCheck->execute([$inspectionId]);
                $inspection = $inspectionCheck->fetch(PDO::FETCH_ASSOC);
                if (!$inspection) throw new RuntimeException('Inspection not found.');
                $detail = workflowInspection($inspection);
                if ($detail['violations'] <= 0) throw new RuntimeException('An inspection notice requires recorded violations.');
                workflowAudit($pdo, $sanitaryStaffId, "Issued inspection notice {$noticeNumber}", 'Sanitary', $inspectionId, $noticeNumber . "\nEstablishment: " . $detail['establishment'] . "\nBarangay: " . $detail['barangay'] . "\nViolations: " . $detail['violations'] . "\nFindings: " . $detail['findings'] . "\nFollow-up: " . $detail['next_inspection_date']);
                sanitarySaveRecord($pdo, 'notice', $detail['establishment'] ?: 'Sanitary Notice', $detail['barangay'] ?? '', date('Y-m-d'), 'Issued', [
                    'notice_number' => $noticeNumber,
                    'violations' => (int)$detail['violations'],
                    'follow_up_date' => $detail['next_inspection_date'] ?? '',
                ], $sanitaryStaffId, $inspectionId, null, $detail['findings'] ?? '');
                $_SESSION['sanitary_flash_success'] = "Notice {$noticeNumber} issued.";
                $pdo->commit();
                header('Location: ' . tabUrl('violations'));
                exit;
            }
        } catch (Throwable $postErr) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['sanitary_flash_error'] = $postErr->getMessage();
            header('Location: ' . tabUrl($tab));
            exit;
        }
    }

    try {
        $sanStmt = $pdo->prepare("SELECT c.id,c.resident_id,CONCAT(r.first_name,' ',r.last_name) AS patientName,TIMESTAMPDIFF(YEAR,r.birthdate,CURDATE()) as age,r.sex as gender,c.chief_complaint as chiefComplaint,c.diagnosis,COALESCE(b.name,'Unassigned') as barangay,c.consultation_date as date,c.remarks as consultation_notes,CASE WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' AND c.diagnosis <> 'Pending OPD Triage' THEN 'Completed' ELSE 'In Progress' END AS consultation_status
            FROM consultations c JOIN residents r ON c.resident_id=r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE " . rhuLivingResidentWhereSql('r') . "
              AND (c.health_worker_id=:sid_hw OR c.physician_id=:sid_phy OR c.chief_complaint LIKE '%Sanitary%' OR c.chief_complaint LIKE '%Inspection%' OR c.chief_complaint LIKE '%Clearance%' OR c.remarks LIKE '%Sanitary%' OR c.remarks LIKE '%Inspection%' OR c.remarks LIKE '%Clearance%')
            ORDER BY (c.id = :qr_appointment) DESC, c.id DESC LIMIT 40");
        $sanStmt->execute(['sid_hw' => $sanitaryStaffId, 'sid_phy' => $sanitaryStaffId, 'qr_appointment'=>max(0, (int)($_GET['qr_appointment'] ?? 0))]);
        $sanitaryConsultations = $sanStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($sanitaryConsultations as &$consultation) {
            $savedStatus = workflowFields((string)($consultation['consultation_notes'] ?? ''))['status'] ?? '';
            if (in_array($savedStatus, ['Completed','In Progress','Scheduled','Referred'], true)) $consultation['consultation_status'] = $savedStatus;
        }
        unset($consultation);
    } catch (Throwable $e) {
        error_log('Sanitary dashboard load: ' . $e->getMessage());
        $sanitaryLoadErrors[] = 'Some records could not be loaded. Please refresh or contact your administrator.';
    }

    try {
        $eventRows = $pdo->query("SELECT ev.*, b.name AS barangay, CONCAT(hw.first_name,' ',hw.last_name) AS inspector_name FROM events ev LEFT JOIN barangays b ON b.id=ev.barangay_id LEFT JOIN health_workers hw ON hw.id=ev.organizer_id WHERE ev.title LIKE 'Sanitary Inspection:%' OR ev.title LIKE 'Sanitation Schedule:%' OR ev.title LIKE 'Water Quality Sample:%' OR ev.title LIKE 'Food Safety Inspection:%' OR ev.title LIKE 'Waste Management:%' OR ev.title LIKE 'Vector Control:%' OR ev.title LIKE '%Campaign%' OR (ev.description LIKE '%Audience:%' AND ev.description LIKE '%Materials:%') ORDER BY ev.start_datetime DESC, ev.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        ['inspections' => $inspections, 'schedules' => $schedules, 'waterSamples' => $waterSamples, 'foodInspections' => $foodInspections, 'wasteRecords' => $wasteRecords, 'vectorActivities' => $vectorActivities, 'campaigns' => $campaigns] = workflowSanitaryRows($eventRows);
        $notices = workflowLoadNotices($pdo);
    } catch (Throwable $e) {
        error_log('Sanitary dashboard load: ' . $e->getMessage());
        $sanitaryLoadErrors[] = 'Some records could not be loaded. Please refresh or contact your administrator.';
    }
}

$passed = count(array_filter($inspections, function ($i) { return $i[6] === 'passed'; }));
$failed = count(array_filter($inspections, function ($i) { return $i[6] === 'failed'; }));
$conditional = count(array_filter($inspections, function ($i) { return $i[6] === 'conditional'; }));
$avg = count($inspections) ? (int) round(array_sum(array_column($inspections, 7)) / count($inspections)) : 0;
$unsafeWater = count(array_filter($waterSamples, function ($w) { return stripos((string) ($w['overall_status'] ?? ''), 'unsafe') !== false || stripos((string) ($w['overall_status'] ?? ''), 'contaminated') !== false; }));
$foodCompliant = count(array_filter($foodInspections, function ($f) { return stripos((string) ($f['compliance_status'] ?? ''), 'non') === false && stripos((string) ($f['compliance_status'] ?? ''), 'compliant') !== false; }));
$foodTotal = count($foodInspections);
$pendingNotices = count(array_filter($notices, function ($n) { return in_array(strtolower((string) ($n['notice_status'] ?? '')), ['pending', 'issued'], true); }));
$upcomingSchedules = array_values(array_filter($schedules, function ($s) { return ($s['scheduled_date'] ?? '') >= date('Y-m-d') && strtolower((string) ($s['schedule_status'] ?? '')) !== 'cancelled'; }));
$upcomingCampaigns = array_values(array_filter($campaigns, function ($c) { return ($c['event_date'] ?? '') >= date('Y-m-d'); }));
$btnPrimary = 'inline-flex items-center gap-1.5 rounded-xl bg-teal-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-teal-600/20 hover:bg-teal-700 transition';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        (() => {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', <?= json_encode($tab, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                window.history.replaceState(window.history.state, '', url.toString());
            } catch (error) {}
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/images/favicon.ico?v=20260915" type="image/x-icon">
    <link rel="shortcut icon" href="assets/images/favicon.ico?v=20260915" type="image/x-icon">
    <title>Sanitary Inspector Portal — ResiHUnity RHU</title>
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <style>
        html {
            scroll-behavior: auto
        }

        body.rhu-sanitary-ui {
            overflow: hidden;
            background: #f3f6f4;
            color: #0f172a;
            height: 100vh;
            height: 100dvh
        }

        .sanitary-sidebar {
            width: 14rem;
            background: #fff;
            border-right: 1px solid #e5ebe7;
            display: flex;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            position: sticky;
            top: 0;
            z-index: 30;
            flex-shrink: 0;
            overflow: hidden
        }

        .sanitary-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.25rem;
            padding: .9rem 1rem;
            flex-shrink: 0
        }

        .sanitary-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(.52)
        }

        .sanitary-sidebar-brand .brand-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg, rgba(13, 53, 24, .92) 0%, rgba(23, 63, 45, .82) 55%, rgba(47, 111, 73, .7) 100%)
        }

        .admin-shell-header {
            background: #0b3c35;
            border-bottom: 1px solid rgba(10, 51, 43, .9);
            box-shadow: 0 10px 28px rgba(2, 28, 23, .18);
            min-height: 5rem;
            position: sticky;
            top: 0;
            z-index: 50
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #94a3b8;
            padding: .85rem 1.1rem .35rem
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: .1rem .65rem;
            padding: .65rem .85rem;
            border-radius: .85rem;
            font-size: .8125rem;
            font-weight: 600;
            color: #475569;
            transition: background .15s ease, color .15s ease
        }

        .nav-item:hover {
            background: #f0fdf6;
            color: #0f766e
        }

        .nav-item.is-active {
            background: #e8f8ef;
            color: #0b3c35;
            font-weight: 800;
            box-shadow: inset 0 0 0 1px #c6ebd4
        }

        .nav-item.is-active svg {
            color: #0f766e
        }

        .sanitary-main-wrap {
            position: relative;
            flex: 1;
            min-width: 0;
            min-height: 0;
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            background: #f3f6f4;
            isolation: isolate;
            overflow: hidden
        }

        .sanitary-main-wrap::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url('assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center top;
            opacity: .06;
            pointer-events: none;
            z-index: 0
        }

        .sanitary-main-wrap>* {
            position: relative;
            z-index: 1
        }

        .sanitary-main-wrap>header.admin-shell-header {
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0
        }

        .sanitary-main-wrap>main {
            z-index: 1;
            position: relative;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden
        }

        .dashboard-card {
            background: rgba(255, 255, 255, .95);
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease
        }

        .dashboard-card:hover {
            border-color: #a7e0bc;
            box-shadow: 0 6px 18px rgba(11, 60, 53, .08);
            transform: translateY(-1px)
        }

        @media (prefers-reduced-motion:reduce) {
            .dashboard-card:hover {
                transform: none
            }
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, .14) !important
        }

        @media (max-width:1023px) {
            .sanitary-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                height: 100vh;
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 40px rgba(15, 23, 42, .18)
            }

            .sanitary-sidebar.is-open {
                transform: translateX(0)
            }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                z-index: 50;
                background: rgba(2, 6, 23, .42);
                opacity: 0;
                pointer-events: none;
                transition: opacity .15s ease
            }

            .sidebar-backdrop.is-open {
                opacity: 1;
                pointer-events: auto
            }

            body.drawer-open {
                overflow: hidden
            }
        }

        @media (min-width:1024px) {
            .sidebar-backdrop {
                display: none !important
            }

            .sanitary-sidebar {
                transform: none !important
            }
        }
    </style>
    <link rel="stylesheet" href="styles/dashboard-enhancements.css">
    <style>
        html { color-scheme: light; }
        .rhu-sanitary-ui { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .rhu-sanitary-ui .sanitary-main-wrap { background: #f4f7f6; }
        .rhu-sanitary-ui .sanitary-main-wrap::before { background-image: none; }
        .rhu-sanitary-ui .sanitary-sidebar { width: 15.5rem; border-color: #e1e9e4; }
        .rhu-sanitary-ui .sanitary-sidebar-brand { min-height: 5rem; display: flex; align-items: center; }
        .rhu-sanitary-ui .nav-section-label { color: #72877c; padding-top: 1.4rem; }
        .rhu-sanitary-ui .nav-item { min-height: 44px; border-radius: 10px; margin: .2rem .7rem; }
        .rhu-sanitary-ui .nav-item.is-active { background: #e6f3eb; color: #155338; box-shadow: inset 3px 0 #258253; }
        .rhu-sanitary-ui .admin-shell-header { box-shadow: 0 2px 12px #153c3210; }
        .rhu-sanitary-ui #sanitary-content { max-width: 1500px; padding: clamp(1rem, 2.5vw, 2rem); scrollbar-gutter: stable; }
        .sanitary-page-title { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .25rem 0 .5rem; }
        .sanitary-eyebrow { font-size: 10px; font-weight: 750; letter-spacing: .14em; text-transform: uppercase; color: #547463; margin-bottom: .5rem; }
        .sanitary-page-title h1 { color: #163e30; font-size: clamp(1.5rem, 2.5vw, 2rem); line-height: 1.2; font-weight: 750; letter-spacing: -.035em; }
        .sanitary-subtitle { margin-top: .65rem; font-size: 13px; line-height: 1.7; color: #677c71; }
        .sanitary-role-badge { display: flex; align-items: center; gap: .5rem; border: 1px solid #dbe8e0; background: white; padding: .65rem .85rem; border-radius: 99px; color: #476a54; font-size: 11px; white-space: nowrap; }
        .sanitary-welcome { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; padding: clamp(1.3rem, 3vw, 2rem); border-radius: 18px; background: radial-gradient(ellipse at top right, #39784d, transparent 65%), #143e30; color: white; }
        .sanitary-welcome .sanitary-eyebrow { color: #c3e1c8; }
        .sanitary-welcome h2 { font-size: clamp(1.2rem, 2vw, 1.6rem); line-height: 1.4; font-weight: 700; letter-spacing: -.02em; }
        .sanitary-welcome h2 + p { font-size: 12px; line-height: 1.8; color: #d0e3d5; margin-top: .6rem; }
        .sanitary-quick-actions { display: grid; gap: .65rem; flex-shrink: 0; }
        .sanitary-quick-actions a { padding: .8rem 1rem; border: 1px solid #ffffff40; border-radius: 10px; text-align: center; font-size: 12px; font-weight: 700; min-height: 44px; }
        .sanitary-quick-actions a:first-child { background: #e2f0c5; color: #28462a; border-color: #e2f0c5; }
        .sanitary-quick-actions a:hover { filter: brightness(1.08); }
        .rhu-sanitary-ui main .dashboard-card { border-color: #e0e8e2; border-radius: 14px; box-shadow: 0 3px 14px #163d3005; }
        .rhu-sanitary-ui .dashboard-card:hover { transform: none; }
        .rhu-sanitary-ui main table thead { background: #edf4ef; color: #4d6a57; font-size: 10px; letter-spacing: .04em; }
        .rhu-sanitary-ui main table th, .rhu-sanitary-ui main table td { padding: .85rem .75rem; }
        .rhu-sanitary-ui main table tbody tr:hover { background: #f4f8f5; }
        .rhu-sanitary-ui :is(a, button, input, select, textarea, summary):focus-visible { outline: 3px solid #0d9488; outline-offset: 3px; }
        .sanitary-skip { position: fixed; top: -100px; left: 1rem; z-index: 100001; background: #103f3a; color: white; padding: .8rem 1rem; border-radius: 8px; }
        .sanitary-skip:focus { top: 1rem; }
        @media (max-width: 767px) { .sanitary-role-badge { display: none; } .sanitary-welcome { flex-direction: column; align-items: stretch; } .sanitary-quick-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 480px) { .sanitary-quick-actions { grid-template-columns: 1fr; } .rhu-sanitary-ui input, .rhu-sanitary-ui select, .rhu-sanitary-ui textarea { font-size: 16px; } }
        @media (prefers-reduced-motion: reduce) { .rhu-sanitary-ui *, .rhu-sanitary-ui *::before { transition: none !important; scroll-behavior: auto !important; } }
    </style>
<script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(__DIR__ . "/staff-session-lock.js") ?>"></script>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
</head>

<body class="rhu-sanitary-ui antialiased">
    <a class="sanitary-skip" href="#sanitary-content">Skip to dashboard content</a>
    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>
        <aside data-feature-drawer class="sanitary-sidebar shrink-0" aria-label="Sanitary navigation">
            <div class="sanitary-sidebar-brand">
                <div class="brand-bg" aria-hidden="true"></div>
                <div class="brand-overlay" aria-hidden="true"></div>
                <div class="relative z-10 flex items-center gap-3">
                    <span
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/30 bg-white shadow-md overflow-hidden">
                        <img src="../../../assets/nasugbu_seal.png" alt="Nasugbu Seal" class="h-10 w-10 object-contain"
                            onerror="this.onerror=null;this.src='assets/images/nasugbu_seal.png'">
                    </span>
                    <div class="min-w-0 text-white">
                        <p class="text-[14px] font-black leading-tight tracking-tight drop-shadow-sm">RURAL HEALTH UNIT
                        </p>
                        <p class="text-[11px] font-semibold text-white/90 truncate">
                            <?= e($tabs[$tab][0] ?? 'Overview') ?></p>
                    </div>
                </div>
                <button type="button" data-drawer-close
                    class="absolute top-2.5 right-2.5 z-10 grid h-8 w-8 place-items-center rounded-full border border-white/25 bg-white/10 text-white lg:hidden"
                    aria-label="Close menu"><?= iconSvg('close', 'w-4 h-4') ?></button>
            </div>
            <nav class="flex-1 overflow-y-auto py-2 min-h-0">
<?php
if (is_file(__DIR__ . '/includes/error_bootstrap.php')) {
    require_once __DIR__ . '/includes/error_bootstrap.php';
}

                $drawerGroups = [
                    'Dashboard' => ['overview'],
                    'Field Operations' => ['inspections', 'water', 'food', 'waste', 'vector'],
                    'Enforcement' => ['violations'],
                    'Outreach & Analytics' => ['community', 'reports'],
                ];
                foreach ($drawerGroups as $groupLabel => $groupTabs):
                    $visible = array_values(array_filter($groupTabs, function ($k) use ($tabs) { return isset($tabs[$k]); }));
                    if (!$visible)
                        continue;
                    ?>
                    <p class="nav-section-label"><?= e($groupLabel) ?></p>
                    <?php foreach ($visible as $id):
                        [$label, $icon] = $tabs[$id];
                        $active = $tab === $id;
                        ?>
                        <a href="<?= e(tabUrl($id)) ?>" class="nav-item <?= $active ? 'is-active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                            <span class="shrink-0 opacity-90"><?= $icon ?></span>
                            <span class="truncate flex-1"><?= e($label) ?></span>
                            <?php if ($active): ?><span class="text-teal-700 text-sm font-black">→</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="border-t border-slate-100 p-3 shrink-0">
                <a href="StaffLogout.php" data-staff-logout
                    class="nav-item text-slate-500 hover:bg-rose-50 hover:text-rose-700">
                    <?= iconSvg('logout', 'w-5 h-5') ?><span>Log Out</span>
                </a>
            </div>
        </aside>

        <div class="sanitary-main-wrap">
            <header class="admin-shell-header sticky top-0 z-50 text-[#f4faf7]">
                <div class="flex h-20 items-center justify-between gap-3 px-5 sm:px-7">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <button type="button" data-drawer-open
                            class="lg:hidden flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/15 text-white/95 hover:bg-white/10"
                            aria-label="Open menu" aria-expanded="false"><?= iconSvg('menu', 'w-4 h-4') ?></button>
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full border border-[#e8f3d8]/80 bg-[#dfeecb] text-[#0b3b2f]"><?= iconSvg('shield', 'w-3.5 h-3.5') ?></span>
                            <span class="text-xs font-black uppercase tracking-[0.16em] text-[#f5f5f2]">Sanitary
                                Panel</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 sm:gap-2.5">
                        <?php if (function_exists('portalRenderNotificationButton'))
                            echo portalRenderNotificationButton(); ?>
                        <div
                            class="flex items-center gap-2 rounded-full border border-[#dbeadf]/20 bg-white/10 pl-1 pr-2.5 py-1">
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#dceec4] text-sm font-black text-[#0b3b2f]">
                                <?= e(strtoupper(substr($_SESSION['rhu_staff_login']['name'] ?? 'S', 0, 1))) ?></div>
                            <div class="hidden sm:block text-left leading-tight pr-1">
                                <p class="text-[12px] font-bold text-white">
                                    <?= e($_SESSION['rhu_staff_login']['name'] ?? 'Sanitary Inspector') ?></p>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-[#cfe5d8]">Sanitary</p>
                            </div>
                        </div>
                        <a href="StaffLogout.php" data-staff-logout
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-white/20 bg-[#f3faf4] px-3 text-xs font-bold text-[#0c3a32] hover:bg-white transition">
                            <?= iconSvg('logout', 'w-3.5 h-3.5') ?><span class="hidden sm:inline">Log out</span>
                        </a>
                    </div>
                </div>
            </header>
            <div style="display:flex;justify-content:flex-end;padding:10px 24px"><a href="StaffResidentAppointments.php" data-staff-qr-modal style="display:inline-flex;align-items:center;gap:8px;border:1px solid #b8d8ce;border-radius:10px;background:#fff;color:#0f766e;padding:10px 16px;font-size:13px;font-weight:700;text-decoration:none">&#9638; Scan Resident ID</a></div>

            <main id="sanitary-content" tabindex="-1" class="flex-1 mx-auto w-full max-w-7xl p-4 sm:p-6 space-y-5 pb-6">
                <section class="sanitary-page-title" aria-labelledby="sanitary-page-heading">
                    <div><p class="sanitary-eyebrow">ResiHUnity / Environmental health</p><h1 id="sanitary-page-heading"><?= $tab === 'overview' ? 'Sanitary overview' : e($tabs[$tab][0]) ?></h1><p class="sanitary-subtitle">Manage inspections, monitor community safety, and follow up on findings.</p></div>
                    <span class="sanitary-role-badge"><?= iconSvg('shield', 'w-4 h-4') ?> Sanitary Inspector</span>
                </section>
                <?php foreach (array_unique($sanitaryLoadErrors) as $loadError): ?><div role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><?= e($loadError) ?></div><?php endforeach; ?>
                <?php if ($sanitaryFlashSuccess): ?>
                    <div
                        class="dashboard-card rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">
                        <?= e($sanitaryFlashSuccess) ?></div><?php endif; ?>
                <?php if ($sanitaryFlashError): ?>
                    <div
                        class="dashboard-card rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800">
                        <?= e($sanitaryFlashError) ?></div><?php endif; ?>

                <?php if ($tab === 'overview'): ?>
                    <section class="sanitary-welcome">
                        <div><p class="sanitary-eyebrow">A healthier community, every day</p><h2>Plan the visit. Follow up on what matters.</h2><p>Keep inspections, water monitoring, and sanitation activities in one place.</p></div>
                        <div class="sanitary-quick-actions"><a href="<?= e(tabUrl('inspections', ['modal'=>'inspection'])) ?>">+ Record an inspection</a><a href="<?= e(tabUrl('inspections', ['modal'=>'schedule'])) ?>">Schedule a visit &rarr;</a></div>
                    </section>
                    <?php if ($failed > 0 || $unsafeWater > 0 || $pendingNotices > 0): ?>
                        <section class="dashboard-card flex flex-wrap gap-3 rounded-xl border border-red-200 bg-red-50 p-4">
                            <div class="flex-1 min-w-[200px]">
                                <b class="text-sm text-red-800">Priority alerts</b>
                                <p class="text-xs text-red-700 mt-1"><?= $failed ?> failed inspection(s) · <?= $unsafeWater ?>
                                    unsafe water sample(s) · <?= $pendingNotices ?> open notice(s)</p>
                            </div>
                            <a class="rounded-lg bg-red-600 px-3 py-1.5 text-xs text-white font-bold self-center"
                                href="<?= e(tabUrl('violations')) ?>">Review</a>
                        </section>
                    <?php endif; ?>

                                        <?php
                    $inspTotal = count($inspections);
                    $passPct = $inspTotal ? (int) round(($passed / $inspTotal) * 100) : 0;
                    $condPct = $inspTotal ? (int) round(($conditional / $inspTotal) * 100) : 0;
                    $failPct = $inspTotal ? (int) round(($failed / $inspTotal) * 100) : 0;
                    $kpiCards = [
                        [
                            'label' => 'Passed',
                            'value' => (string) $passed,
                            'sub' => $inspTotal ? "{$passPct}% of {$inspTotal}" : 'No inspections yet',
                            'icon_bg' => 'bg-emerald-100 text-emerald-700',
                            'card_bg' => 'bg-linear-to-br from-emerald-50 to-white border-emerald-100',
                            'value_cls' => 'text-emerald-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
                        ],
                        [
                            'label' => 'Conditional',
                            'value' => (string) $conditional,
                            'sub' => $inspTotal ? "{$condPct}% of {$inspTotal}" : 'No inspections yet',
                            'icon_bg' => 'bg-amber-100 text-amber-700',
                            'card_bg' => 'bg-linear-to-br from-amber-50 to-white border-amber-100',
                            'value_cls' => 'text-amber-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
                        ],
                        [
                            'label' => 'Failed',
                            'value' => (string) $failed,
                            'sub' => $inspTotal ? "{$failPct}% of {$inspTotal}" : 'No inspections yet',
                            'icon_bg' => 'bg-rose-100 text-rose-700',
                            'card_bg' => 'bg-linear-to-br from-rose-50 to-white border-rose-100',
                            'value_cls' => 'text-rose-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
                        ],
                        [
                            'label' => 'Avg Compliance',
                            'value' => $avg . '%',
                            'sub' => $inspTotal ? "Across {$inspTotal} inspection" . ($inspTotal === 1 ? '' : 's') : 'No data yet',
                            'icon_bg' => 'bg-teal-100 text-teal-700',
                            'card_bg' => 'bg-linear-to-br from-teal-50 to-white border-teal-100',
                            'value_cls' => 'text-teal-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>',
                        ],
                    ];
                    ?>
                    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <?php foreach ($kpiCards as $kpi): ?>
                            <div class="dashboard-card rounded-2xl border <?= $kpi['card_bg'] ?> p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500"><?= e($kpi['label']) ?></p>
                                        <p class="mt-1 text-2xl font-black tabular-nums <?= $kpi['value_cls'] ?>"><?= e($kpi['value']) ?></p>
                                        <p class="mt-1 text-[11px] font-medium text-slate-500 truncate"><?= e($kpi['sub']) ?></p>
                                    </div>
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= $kpi['icon_bg'] ?>">
                                        <?= $kpi['icon'] ?>
                                    </span>
                                </div>
                                <?php if ($kpi['label'] === 'Avg Compliance' && $inspTotal > 0): ?>
                                    <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-1.5 rounded-full bg-teal-500 transition-all" style="width: <?= max(0, min(100, (int)$avg)) ?>%"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Upcoming inspections</h2>
                                <a href="<?= e(tabUrl('inspections', ['modal' => 'schedule'])) ?>"
                                    class="text-[11px] font-bold text-teal-700">Schedule</a>
                            </div>
                            <?php if (!$upcomingSchedules): ?>
                                <p class="text-xs text-slate-400 italic">No upcoming scheduled inspections.</p>
                            <?php else:
                                foreach (array_slice($upcomingSchedules, 0, 5) as $s): ?>
                                    <div class="mb-2 flex justify-between rounded-lg bg-slate-50 p-2.5 text-xs">
                                        <div><b class="block text-slate-900"><?= e($s['target_name']) ?></b><span
                                                class="text-slate-500"><?= e($s['target_type']) ?> ·
                                                <?= e($s['barangay'] ?: '—') ?></span></div>
                                        <div class="text-right"><b class="text-teal-700"><?= e($s['scheduled_date']) ?></b><span
                                                class="block text-slate-400"><?= e($s['scheduled_time'] ?: '') ?></span></div>
                                    </div>
                                <?php endforeach; endif; ?>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Water quality</h2><a
                                    href="<?= e(tabUrl('water')) ?>" class="text-[11px] font-bold text-teal-700">View
                                    all</a>
                            </div>
                            <p class="text-2xl font-black <?= $unsafeWater ? 'text-red-600' : 'text-emerald-600' ?>">
                                <?= $unsafeWater ?></p>
                            <p class="text-xs text-slate-500">Unsafe / contaminated samples</p>
                            <p class="mt-2 text-xs text-slate-600"><?= count($waterSamples) ?> total logged samples</p>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Food safety</h2><a
                                    href="<?= e(tabUrl('food')) ?>" class="text-[11px] font-bold text-teal-700">View all</a>
                            </div>
                            <?php $foodPct = $foodTotal ? (int) round(($foodCompliant / $foodTotal) * 100) : 0; ?>
                            <p class="text-2xl font-black text-teal-700"><?= $foodPct ?>%</p>
                            <p class="text-xs text-slate-500"><?= $foodCompliant ?> / <?= $foodTotal ?> compliant</p>
                            <div class="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-2 rounded-full bg-teal-500" style="width:<?= $foodPct ?>%"></div>
                            </div>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="font-bold text-sm text-slate-900 mb-3">Waste & vector</h2>
                            <div class="space-y-2 text-xs">
                                <div class="flex justify-between"><span class="text-slate-600">Waste
                                        records</span><b><?= count($wasteRecords) ?></b></div>
                                <div class="flex justify-between"><span class="text-slate-600">Vector
                                        activities</span><b><?= count($vectorActivities) ?></b></div>
                                <div class="flex justify-between"><span class="text-slate-600">Open notices</span><b
                                        class="<?= $pendingNotices ? 'text-red-600' : '' ?>"><?= $pendingNotices ?></b>
                                </div>
                            </div>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:col-span-2">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Community education</h2><a
                                    href="<?= e(tabUrl('community')) ?>"
                                    class="text-[11px] font-bold text-teal-700">Manage</a>
                            </div>
                            <?php if (!$upcomingCampaigns): ?>
                                <p class="text-xs text-slate-400 italic">No upcoming campaigns.</p>
                            <?php else: ?>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <?php foreach (array_slice($upcomingCampaigns, 0, 4) as $c): ?>
                                        <div class="rounded-lg bg-slate-50 p-3 text-xs"><b
                                                class="block text-slate-900"><?= e($c['title']) ?></b><span
                                                class="text-slate-500"><?= e($c['event_date']) ?> ·
                                                <?= e($c['barangay'] ?: $c['venue'] ?: '—') ?></span></div>
                                    <?php endforeach; ?>
                                </div><?php endif; ?>
                        </div>
                    </section>

                    <section class="dashboard-card rounded-xl border border-teal-200 bg-white p-5 shadow-sm space-y-3">
                        <div class="flex justify-between items-center border-b border-teal-100 pb-2">
                            <h2 class="font-bold text-sm text-teal-950">Sanitary requests queue
                                (<?= count($sanitaryConsultations) ?>)</h2>
                            <span
                                class="text-[10px] font-bold bg-teal-50 text-teal-700 px-2 py-0.5 rounded-full border border-teal-200">Live</span>
                        </div>
                        <?php if (empty($sanitaryConsultations)): ?>
                            <p class="text-xs text-gray-400 italic py-2 text-center">No sanitary requests assigned yet.</p>
                        <?php else: ?><div data-staff-record-scroll="Resident sanitary consultations" class="staff-record-scroll staff-record-scroll-list space-y-3">
                            <?php foreach ($sanitaryConsultations as $sc): ?>
                                <div data-qr-consultation-id="<?= (int) $sc['id'] ?>" class="py-3 flex flex-col space-y-2 bg-slate-50/50 p-3 rounded-xl border border-teal-100">
                                    <div class="flex items-center justify-between text-xs gap-2">
                                        <div>
                                            <p class="font-bold text-gray-900"><?= e(rhuPatientLabel($sc['patientName'], $sc['resident_id'] ?? 0)) ?> <span
                                                    class="text-gray-500 font-normal">(<?= e($sc['age'] ?? 'N/A') ?>y ·
                                                    <?= e($sc['barangay']) ?>)</span></p>
                                            <p class="text-teal-700 font-medium"><?= e($sc['chiefComplaint']) ?></p>
                                            <p class="text-[10px] text-gray-400 font-mono">Requested: <?= e($sc['date']) ?></p>
                                        </div>
                                        <span
                                            class="px-2 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-lg font-bold text-[10px] shrink-0"><?= e($sc['consultation_status']) ?></span>
                                    </div>
                                    <details class="group border-t border-teal-100 pt-2">
                                        <summary class="cursor-pointer text-xs font-bold text-teal-700 py-1">Answer / update
                                            response</summary>
                                        <form method="post"
                                            class="mt-2 bg-white p-3 rounded-xl border border-teal-200/70 space-y-2.5"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="answer_consultation">
                                            <input type="hidden" name="consultation_id" value="<?= (int) $sc['id'] ?>">
                                            <input type="hidden" name="resident_id" value="<?= (int) ($sc['resident_id'] ?? 0) ?>">
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                <div><label
                                                        class="block text-[11px] font-bold text-gray-700 mb-0.5">Findings</label><input
                                                        type="text" name="diagnosis" value="<?= e($sc['diagnosis'] ?? '') ?>" required
                                                        class="w-full p-2 border border-gray-300 rounded-lg text-xs"></div>
                                                <div><label class="block text-[11px] font-bold text-gray-700 mb-0.5">Status</label>
                                                    <select name="consultation_status"
                                                        class="w-full p-2 border border-gray-300 rounded-lg text-xs font-bold text-teal-900">
                                                        <?php foreach (['Completed', 'In Progress', 'Scheduled', 'Referred'] as $st): ?>
                                                            <option value="<?= $st ?>"
                                                                <?= ($sc['consultation_status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?>
                                                            </option><?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div><label class="block text-[11px] font-bold text-gray-700 mb-0.5">Notes for
                                                    resident</label><textarea name="consultation_notes" rows="2"
                                                    class="w-full p-2 border border-gray-300 rounded-lg text-xs resize-none"><?= e($sc['consultation_notes'] ?? '') ?></textarea>
                                            </div>
                                            <div class="flex justify-end"><button type="submit"
                                                    class="px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white text-xs font-extrabold rounded-lg">Save
                                                    & notify</button></div>
                                        </form>
                                    </details>
                                </div>
                            <?php endforeach; endif; ?>
                    </section>

                <?php elseif ($tab === 'inspections'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Inspections & scheduling</h2>
                            <p class="text-xs text-slate-500">Plan and log field inspections.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="<?= e(tabUrl('inspections', ['modal' => 'schedule'])) ?>"
                                class="<?= $btnPrimary ?>">Schedule inspection</a>
                            <a href="<?= e(tabUrl('inspections', ['modal' => 'inspection'])) ?>" class="<?= $btnPrimary ?>">Log
                                inspection</a>
                        </div>
                    </div>
                    <section class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-sm text-slate-900 mb-3">Upcoming schedule</h3>
                        <?php if (!$upcomingSchedules): ?>
                            <p class="text-xs text-slate-400 italic">No scheduled inspections yet.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[640px] text-sm">
                                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                        <tr>
                                            <th class="p-3">Date</th>
                                            <th>Target</th>
                                            <th>Type</th>
                                            <th>Barangay</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y"><?php foreach ($upcomingSchedules as $s): ?>
                                            <tr class="hover:bg-slate-50/80">
                                                <td class="p-3 font-mono text-xs">
                                                    <?= e($s['scheduled_date']) ?>            <?= $s['scheduled_time'] ? ' ' . e($s['scheduled_time']) : '' ?>
                                                </td>
                                                <td class="font-semibold"><?= e($s['target_name']) ?></td>
                                                <td><?= e($s['target_type']) ?></td>
                                                <td><?= e($s['barangay'] ?: '—') ?></td>
                                                <td><span
                                                        class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= strtolower($s['priority'] ?? '') === 'high' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600' ?>"><?= e($s['priority']) ?></span>
                                                </td>
                                                <td><?= e($s['schedule_status']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div><?php endif; ?>
                    </section>
                    <div class="space-y-3">
                        <h3 class="font-bold text-sm text-slate-900">Logged findings</h3>
                        <?php if (!$inspections): ?>
                            <p class="text-xs text-slate-400 italic">No inspection records yet.</p><?php endif; ?>
                        <?php foreach ($inspections as $i):
                            $color = $i[6] === 'passed' ? 'green' : ($i[6] === 'conditional' ? 'yellow' : 'red'); ?>
                            <article
                                class="dashboard-card rounded-xl border-l-4 border-<?= $color ?>-400 bg-white p-5 shadow-sm">
                                <div class="flex flex-wrap justify-between gap-2">
                                    <div><b><?= e($i[1]) ?></b>
                                        <p class="text-xs text-gray-500"><?= e($i[3]) ?> · Inspector: <?= e($i[10]) ?></p>
                                        <p class="text-xs text-gray-400">Inspected: <?= e($i[4]) ?> · Next: <?= e($i[5]) ?></p>
                                    </div>
                                    <div class="text-right"><span
                                            class="rounded-full bg-<?= $color ?>-100 px-2 py-1 text-xs font-bold text-<?= $color ?>-700"><?= strtoupper($i[6]) ?></span><b
                                            class="mt-1 block text-lg text-<?= $color ?>-600"><?= $i[7] ?>%</b><small
                                            class="text-gray-400">compliance</small></div>
                                </div>
                                <div class="mt-3 h-2 rounded-full bg-gray-100"><i
                                        class="block h-2 rounded-full bg-<?= $color ?>-500"
                                        style="width:<?= max(0, min(100, $i[7])) ?>%"></i></div>
                                <p class="mt-3 text-xs font-bold text-gray-500">Findings (<?= $i[8] ?>
                                    violation<?= $i[8] === 1 ? '' : 's' ?>)</p>
                                <?php foreach ($i[9] as $finding): ?>
                                    <p class="mt-1 text-xs text-gray-600">• <?= e($finding) ?></p><?php endforeach; ?>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php if ($i[8] > 0): ?>
                                        <form method="post" class="inline"
                                            onsubmit="return confirm('Issue a formal sanitation notice?')"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="issue_inspection_notice">
                                            <input type="hidden" name="inspection_id" value="<?= (int) $i[11] ?>">
                                            <button type="submit"
                                                class="rounded-lg bg-teal-600 px-3 py-1.5 text-xs text-white hover:bg-teal-700">Issue
                                                notice</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="?print_inspection=<?= (int) $i[11] ?>" target="_blank" rel="noopener"
                                        class="inline-block rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">Print
                                        report</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($tab === 'water'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Water quality monitoring</h2>
                            <p class="text-xs text-slate-500">Log sample results and track contamination alerts.</p>
                        </div>
                        <a href="<?= e(tabUrl('water', ['modal' => 'water'])) ?>" class="<?= $btnPrimary ?>">Log sample</a>
                    </div>
                    <?php if ($unsafeWater > 0): ?>
                        <div
                            class="dashboard-card rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800">
                            <?= $unsafeWater ?> sample(s) flagged unsafe / contaminated.</div><?php endif; ?>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[800px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Source</th>
                                    <th>Type</th>
                                    <th>Barangay</th>
                                    <th>pH</th>
                                    <th>Turbidity</th>
                                    <th>Coliform</th>
                                    <th>Residual chlorine</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$waterSamples): ?>
                                    <tr>
                                        <td colspan="9" class="p-6 text-center text-xs text-slate-400 italic">No water samples
                                            logged yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($waterSamples as $w):
                                    $bad = stripos((string) $w['overall_status'], 'unsafe') !== false || stripos((string) $w['overall_status'], 'contaminated') !== false; ?>
                                    <tr class="hover:bg-slate-50/80 <?= $bad ? 'bg-red-50/40' : '' ?>">
                                        <td class="p-3 font-mono text-xs"><?= e($w['sample_date']) ?></td>
                                        <td class="font-semibold"><?= e($w['source_name']) ?></td>
                                        <td><?= e($w['source_type']) ?></td>
                                        <td><?= e($w['barangay'] ?: '—') ?></td>
                                        <td><?= e($w['ph_level'] ?? '—') ?></td>
                                        <td><?= e($w['turbidity'] ?? '—') ?></td>
                                        <td><?= e($w['coliform_result'] ?: '—') ?></td>
                                        <td><?= e($w['residual_chlorine'] !== '' ? $w['residual_chlorine'] : '—') ?></td>
                                        <td><span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= $bad ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' ?>"><?= e($w['overall_status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'food'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Food safety reports</h2>
                            <p class="text-xs text-slate-500">Eateries, markets, and food vendors.</p>
                        </div>
                        <a href="<?= e(tabUrl('food', ['modal' => 'food'])) ?>" class="<?= $btnPrimary ?>">New food
                            inspection</a>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="dashboard-card rounded-xl border bg-emerald-50 p-4"><b
                                class="text-2xl text-emerald-700"><?= $foodCompliant ?></b>
                            <p class="text-xs font-bold">Compliant</p>
                        </div>
                        <div class="dashboard-card rounded-xl border bg-red-50 p-4"><b
                                class="text-2xl text-red-700"><?= max(0, $foodTotal - $foodCompliant) ?></b>
                            <p class="text-xs font-bold">Non-compliant</p>
                        </div>
                        <div class="dashboard-card rounded-xl border bg-teal-50 p-4"><b
                                class="text-2xl text-teal-700"><?= $foodTotal ? (int) round(($foodCompliant / $foodTotal) * 100) : 0 ?>%</b>
                            <p class="text-xs font-bold">Compliance rate</p>
                        </div>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[720px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Establishment</th>
                                    <th>Type</th>
                                    <th>Barangay</th>
                                    <th>Score</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$foodInspections): ?>
                                    <tr>
                                        <td colspan="6" class="p-6 text-center text-xs text-slate-400 italic">No food
                                            inspections yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($foodInspections as $f):
                                    $ok = stripos((string) $f['compliance_status'], 'non') === false; ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs"><?= e($f['inspection_date']) ?></td>
                                        <td class="font-semibold"><?= e($f['establishment']) ?></td>
                                        <td><?= e($f['establishment_type']) ?></td>
                                        <td><?= e($f['barangay'] ?: '—') ?></td>
                                        <td><?= (int) $f['score'] ?></td>
                                        <td><span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= $ok ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>"><?= e($f['compliance_status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'waste'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Waste management</h2>
                            <p class="text-xs text-slate-500">Garbage, sewage, and sanitation projects.</p>
                        </div>
                        <a href="<?= e(tabUrl('waste', ['modal' => 'waste'])) ?>" class="<?= $btnPrimary ?>">Add record</a>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[700px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Barangay</th>
                                    <th>Volume</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$wasteRecords): ?>
                                    <tr>
                                        <td colspan="6" class="p-6 text-center text-xs text-slate-400 italic">No waste records
                                            yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($wasteRecords as $w): ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs"><?= e($w['activity_date']) ?></td>
                                        <td class="font-semibold"><?= e($w['record_type']) ?></td>
                                        <td><?= e($w['location']) ?></td>
                                        <td><?= e($w['barangay'] ?: '—') ?></td>
                                        <td><?= e($w['volume_estimate'] ?: '—') ?></td>
                                        <td><?= e($w['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'vector'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Vector control</h2>
                            <p class="text-xs text-slate-500">Breeding sites, fumigation, pest control.</p>
                        </div>
                        <a href="<?= e(tabUrl('vector', ['modal' => 'vector'])) ?>" class="<?= $btnPrimary ?>">Log activity</a>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Activity</th>
                                    <th>Location</th>
                                    <th>Barangay</th>
                                    <th>Found</th>
                                    <th>Treated</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$vectorActivities): ?>
                                    <tr>
                                        <td colspan="8" class="p-6 text-center text-xs text-slate-400 italic">No vector
                                            activities yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($vectorActivities as $v): ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs"><?= e($v['activity_date']) ?></td>
                                        <td class="font-semibold"><?= e($v['activity_type']) ?></td>
                                        <td><?= e($v['location']) ?></td>
                                        <td><?= e($v['barangay'] ?: '—') ?></td>
                                        <td><?= (int) $v['sites_found'] ?></td>
                                        <td><?= (int) $v['sites_treated'] ?></td>
                                        <td><?= e($v['method_used'] ?: '—') ?></td>
                                        <td><?= e($v['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'violations'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Violation notices</h2>
                            <p class="text-xs text-slate-500">Issue, track, and resolve non-compliance notices.</p>
                        </div>
                        <a href="<?= e(tabUrl('violations', ['modal' => 'notice'])) ?>" class="<?= $btnPrimary ?>">Issue
                            notice</a>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[800px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Notice #</th>
                                    <th>Establishment</th>
                                    <th>Barangay</th>
                                    <th>Issued</th>
                                    <th>Violations</th>
                                    <th>Status</th>
                                    <th>Follow-up</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$notices): ?>
                                    <tr>
                                        <td colspan="8" class="p-6 text-center text-xs text-slate-400 italic">No notices yet.
                                        </td>
                                    </tr><?php endif; ?>
                                <?php foreach ($notices as $n):
                                    $st = strtolower((string) ($n['notice_status'] ?? 'pending'));
                                    $stClass = $st === 'resolved' ? 'bg-emerald-100 text-emerald-700' : (($st === 'pending' || $st === 'issued') ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'); ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs font-bold"><?= e($n['notice_number'] ?? '—') ?></td>
                                        <td class="font-semibold"><?= e($n['establishment'] ?? '—') ?></td>
                                        <td><?= e($n['barangay'] ?? '—') ?></td>
                                        <td class="font-mono text-xs"><?= e($n['issued_date'] ?? '—') ?></td>
                                        <td><?= (int) $n['violations'] ?></td>
                                        <td><span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= $stClass ?>"><?= e($n['notice_status'] ?? '—') ?></span>
                                        </td>
                                        <td class="text-xs"><?= e($n['follow_up_date'] ?? '—') ?></td>
                                        <td><?php if ($st !== 'resolved'): ?>
                                                <form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>"><input type="hidden" name="action"
                                                        value="update_notice_status"><input type="hidden" name="notice_id"
                                                        value="<?= (int) $n['id'] ?>"><input type="hidden" name="notice_status"
                                                        value="Resolved"><button type="submit"
                                                        class="text-[11px] font-bold text-teal-700 hover:underline">Mark
                                                        resolved</button></form><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'community'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Community education</h2>
                            <p class="text-xs text-slate-500">Hygiene campaigns and sanitation drives.</p>
                        </div>
                        <a href="<?= e(tabUrl('community', ['modal' => 'campaign'])) ?>" class="<?= $btnPrimary ?>">Add
                            campaign</a>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <?php if (!$campaigns): ?>
                            <p class="text-xs text-slate-400 italic col-span-2">No campaigns yet.</p><?php endif; ?>
                        <?php foreach ($campaigns as $c): ?>
                            <article class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex justify-between gap-2">
                                    <div><b class="text-slate-900"><?= e($c['title']) ?></b>
                                        <p class="text-xs text-slate-500 mt-0.5"><?= e($c['campaign_type']) ?> ·
                                            <?= e($c['barangay'] ?: '—') ?></p>
                                    </div>
                                    <span
                                        class="rounded-full bg-teal-50 text-teal-800 border border-teal-100 px-2 py-0.5 text-[11px] font-bold h-fit"><?= e($c['status']) ?></span>
                                </div>
                                <p class="mt-3 text-xs text-slate-600"><span
                                        class="font-mono"><?= e($c['event_date']) ?></span><?= $c['venue'] ? ' · ' . e($c['venue']) : '' ?><?= $c['audience_size'] ? ' · Audience: ' . (int) $c['audience_size'] : '' ?>
                                </p>
                                <?php if (!empty($c['materials'])): ?>
                                    <p class="mt-2 text-xs text-slate-500"><span class="font-bold text-slate-700">Materials:</span>
                                        <?= e($c['materials']) ?></p><?php endif; ?>
                                <?php if (!empty($c['notes'])): ?>
                                    <p class="mt-1 text-xs text-slate-500"><?= e($c['notes']) ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>

                <?php else: /* reports */ ?>
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Reports & analytics</h2>
                        <p class="text-xs text-slate-500">Sanitation trends and module activity.</p>
                    </div>
                    <section class="grid gap-4 md:grid-cols-2">
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
                            <h3 class="font-bold text-sm text-slate-900 border-b border-slate-100 pb-2">Inspection outcomes
                            </h3>
                            <?php $inspTotal = max(1, count($inspections));
                            foreach ([['Passed', $passed, 'bg-emerald-500'], ['Conditional', $conditional, 'bg-amber-500'], ['Failed', $failed, 'bg-rose-500']] as [$lab, $cnt, $bar]):
                                $pct = (int) round(($cnt / $inspTotal) * 100); ?>
                                <div>
                                    <div class="flex justify-between text-xs mb-1"><span
                                            class="font-semibold text-slate-700"><?= $lab ?></span><b><?= $cnt ?>
                                            (<?= $pct ?>%)</b></div>
                                    <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-2.5 rounded-full <?= $bar ?>" style="width:<?= $pct ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <p class="text-xs text-slate-500 pt-1">Average compliance: <b><?= $avg ?>%</b></p>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
                            <h3 class="font-bold text-sm text-slate-900 border-b border-slate-100 pb-2">Module activity</h3>
                            <?php foreach ([['Water samples', count($waterSamples), $unsafeWater . ' unsafe'], ['Food inspections', $foodTotal, $foodCompliant . ' compliant'], ['Waste records', count($wasteRecords), ''], ['Vector activities', count($vectorActivities), ''], ['Violation notices', count($notices), $pendingNotices . ' open'], ['Community campaigns', count($campaigns), count($upcomingCampaigns) . ' upcoming']] as [$lab, $val, $extra]): ?>
                                <div class="flex justify-between text-xs border-b border-slate-50 py-1.5"><span
                                        class="text-slate-600 font-medium"><?= $lab ?></span><span><b
                                            class="text-slate-900"><?= $val ?></b><?= $extra ? ' <span class="text-slate-400">· ' . e($extra) . '</span>' : '' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-sm text-slate-900 mb-3">Export inspection reports</h3>
                        <p class="text-xs text-slate-500 mb-3">Open any logged inspection and use <b>Print report</b> for
                            PDF-ready output.</p>
                        <div class="flex flex-wrap gap-2">
                            <a href="<?= e(tabUrl('inspections')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Inspections</a>
                            <a href="<?= e(tabUrl('water')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Water
                                quality</a>
                            <a href="<?= e(tabUrl('food')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Food
                                safety</a>
                            <a href="<?= e(tabUrl('violations')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Violations</a>
                        </div>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($modal === 'schedule'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Schedule inspection</h2>
                        <p class="text-xs text-slate-500">Calendar plan for field inspection</p>
                    </div>
                    <a href="<?= e(tabUrl('inspections')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_schedule">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Target name
                                *</label><input required name="target_name"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="Establishment / facility"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Type</label><select name="target_type"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Food Establishment</option>
                                <option>Water Source</option>
                                <option>School</option>
                                <option>Public Facility</option>
                                <option>Market</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="scheduled_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Time</label><input name="scheduled_time"
                                placeholder="09:00 AM" class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Priority</label><select name="priority"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Normal</option>
                                <option>High</option>
                                <option>Low</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('inspections')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'inspection'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Log inspection</h2>
                        <p class="text-xs text-slate-500">Record establishment inspection findings</p>
                    </div>
                    <a href="<?= e(tabUrl('inspections')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_sanitary_inspection">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Establishment
                                *</label><input required name="establishment"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay *</label><select required
                                name="barangay" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Inspector</label><select
                                name="inspector_staff_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select...</option><?php foreach ($staffOptions as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Inspection date *</label><input required
                                type="date" name="inspection_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Next inspection</label><input type="date"
                                name="next_inspection_date"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Compliant</option>
                                <option>Conditional</option>
                                <option>Non-compliant</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Compliance % *</label><input required
                                type="number" min="0" max="100" name="compliance_rate"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Violations *</label><input required
                                type="number" min="0" name="violations"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div class="sm:col-span-2"><label
                                class="block font-bold text-slate-700 mb-1">Findings</label><textarea name="findings"
                                rows="3"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('inspections')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'water'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Log water sample</h2>
                        <p class="text-xs text-slate-500">Record water quality test results</p>
                    </div>
                    <a href="<?= e(tabUrl('water')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_water_sample">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Source name
                                *</label><input required name="source_name"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="Well / spring / system"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Source type</label><select
                                name="source_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Well</option>
                                <option>Spring</option>
                                <option>Piped System</option>
                                <option>Other</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Sample date *</label><input required
                                type="date" name="sample_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Overall status</label><select
                                name="overall_status" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Safe</option>
                                <option>Watch</option>
                                <option>Unsafe</option>
                                <option>Contaminated</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">pH</label><input type="number" step="0.01"
                                name="ph_level" class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Turbidity</label><input type="number"
                                step="0.01" name="turbidity" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div><label class="block font-bold text-slate-700 mb-1">Coliform result</label><input
                                name="coliform_result" class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="Absent / Present"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Residual chlorine</label><input
                                type="number" step="0.01" name="residual_chlorine"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div class="sm:col-span-2"><label
                                class="block font-bold text-slate-700 mb-1">Remarks</label><textarea name="remarks" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('water')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'food'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Food safety inspection</h2>
                        <p class="text-xs text-slate-500">Document eatery / market compliance</p>
                    </div>
                    <a href="<?= e(tabUrl('food')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_food_inspection">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Establishment
                                *</label><input required name="establishment"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Type</label><select
                                name="establishment_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Eatery</option>
                                <option>Market Vendor</option>
                                <option>Canteen</option>
                                <option>Catering</option>
                                <option>Other</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="inspection_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Compliance</label><select
                                name="compliance_status" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Compliant</option>
                                <option>Conditional</option>
                                <option>Non-compliant</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Score (0-100)</label><input type="number"
                                min="0" max="100" name="score" value="80"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Next inspection</label><input type="date"
                                name="next_inspection_date"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label
                                class="block font-bold text-slate-700 mb-1">Findings</label><textarea name="findings"
                                rows="3"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('food')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'waste'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Waste management record</h2>
                        <p class="text-xs text-slate-500">Log collection or sanitation activity</p>
                    </div>
                    <a href="<?= e(tabUrl('waste')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_waste_record">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="block font-bold text-slate-700 mb-1">Record type</label><select
                                name="record_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Garbage Collection</option>
                                <option>Sewage Disposal</option>
                                <option>Community Cleanup</option>
                                <option>Sanitation Project</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="activity_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Location
                                *</label><input required name="location"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Volume estimate</label><input
                                name="volume_estimate" class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="e.g. 12 sacks"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Completed</option>
                                <option>Scheduled</option>
                                <option>In Progress</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('waste')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'vector'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Vector control activity</h2>
                        <p class="text-xs text-slate-500">Record breeding site or fumigation work</p>
                    </div>
                    <a href="<?= e(tabUrl('vector')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_vector_activity">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="block font-bold text-slate-700 mb-1">Activity type</label><select
                                name="activity_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Breeding Site Inspection</option>
                                <option>Fumigation</option>
                                <option>Larviciding</option>
                                <option>Pest Control</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="activity_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Location
                                *</label><input required name="location"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Method</label><input name="method_used"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="e.g. Thermal fogging"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Sites found</label><input type="number"
                                min="0" name="sites_found" value="0"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Sites treated</label><input type="number"
                                min="0" name="sites_treated" value="0"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Completed</option>
                                <option>Scheduled</option>
                                <option>In Progress</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('vector')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'notice'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Issue violation notice</h2>
                        <p class="text-xs text-slate-500">Create formal sanitation notice</p>
                    </div>
                    <a href="<?= e(tabUrl('violations')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="issue_notice">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Establishment
                                *</label><input required name="establishment"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Violations *</label><input required
                                type="number" min="1" name="violations" value="1"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="notice_status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Pending</option>
                                <option>Issued</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Follow-up date</label><input type="date"
                                name="follow_up_date"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Findings /
                                grounds</label><textarea name="findings" rows="3"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('violations')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'campaign'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Community campaign</h2>
                        <p class="text-xs text-slate-500">Schedule education or sanitation drive</p>
                    </div>
                    <a href="<?= e(tabUrl('community')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto"><input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">

                    <input type="hidden" name="action" value="save_campaign">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Title *</label><input
                                required name="title" class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Type</label><select name="campaign_type"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Hygiene Awareness</option>
                                <option>Sanitation Drive</option>
                                <option>Handwashing Campaign</option>
                                <option>Dengue Awareness</option>
                                <option>Other</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Event date *</label><input required
                                type="date" name="event_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Venue</label><input name="venue"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Audience size</label><input type="number"
                                min="0" name="audience_size" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Scheduled</option>
                                <option>Completed</option>
                                <option>Cancelled</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Materials /
                                posters</label><textarea name="materials" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"
                                placeholder="List materials or announcement notes"></textarea></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('community')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <style>
        #sanitary-notifications[hidden], #sanitary-notification-detail[hidden] { display: none !important; }
        #sanitary-notifications { position: fixed; top: 5.5rem; right: 1rem; z-index: 99999; width: min(26rem, calc(100vw - 2rem)); max-height: calc(100dvh - 6.5rem); overflow: auto; color: #1e293b; background: white; border: 1px solid #cbd5e1; border-radius: 1rem; box-shadow: 0 16px 48px #0f172a30; }
        #sanitary-notifications button:focus-visible, #sanitary-notification-detail button:focus-visible { outline: 3px solid #0d9488; outline-offset: 2px; }
        #sanitary-notification-detail { position: fixed; inset: 0; z-index: 100000; display: grid; place-items: center; padding: 1rem; background: #0f172a99; }
        #sanitary-notification-detail > div { width: min(28rem, 100%); max-height: 80dvh; overflow: auto; background: white; color: #1e293b; padding: 1.25rem; border-radius: 1rem; }
        #notification-bell-btn { color: #fff; min-width: 40px; min-height: 40px; }
        #notification-bell-btn { border: 1px solid #ffffff30; border-radius: 12px; background: #ffffff0a; transition: background .15s; }
        #notification-bell-btn:hover, #notification-bell-btn[aria-expanded="true"] { background: #ffffff24; }
        #sanitary-notifications { width: min(29rem, calc(100vw - 2rem)); display: flex; flex-direction: column; overflow: hidden; border-color: #dbe7e1; border-radius: 20px; box-shadow: 0 24px 72px #103f3a30; color-scheme: light; }
        #sanitary-notifications .san-notif-heading { background: linear-gradient(125deg, #103f3a, #22674e); padding: 20px; flex-shrink: 0; }
        .san-notif-eyebrow { font-size: 10px; letter-spacing: .14em; text-transform: uppercase; color: #b8decb; margin-bottom: 5px; font-weight: 700; }
        #sanitary-notifications h2 { font-size: 19px; letter-spacing: -.02em; }
        #sanitary-unread { display: inline-block; margin-left: 6px; border: 1px solid #ffffff30; background: #ffffff12; border-radius: 99px; padding: 3px 8px; font-size: 10px; color: #e2f5d1; vertical-align: middle; }
        #sanitary-close-notifications { width: 36px; height: 36px; border-radius: 10px; background: #ffffff12; font-size: 23px; line-height: 1; }
        .san-notif-filters { display: flex; align-items: center; gap: 6px; padding: 12px 16px; border-bottom: 1px solid #edf2ef; flex-shrink: 0; }
        .san-notif-filters button { font-size: 12px; font-weight: 700; padding: 8px 13px; border-radius: 8px; color: #61766c; }
        .san-notif-filters button[aria-pressed="true"] { background: #e6f3ed; color: #155d43; }
        #sanitary-refresh { margin-left: auto; }
        .san-notif-toolbar { background: #f8faf9; font-size: 11px; padding: 10px 16px; flex-shrink: 0; }
        .san-notif-toolbar button { min-height: 32px; }
        #sanitary-notifications button:disabled { opacity: .4; cursor: not-allowed; }
        #sanitary-notification-list { overflow-y: auto; min-height: 0; overscroll-behavior: contain; }
        #sanitary-notification-list input, #sanitary-select-all { accent-color: #187655; width: 15px; height: 15px; flex-shrink: 0; }
        .san-notif-row { display: flex; align-items: flex-start; gap: 12px; padding: 17px 18px; border-left: 3px solid transparent; transition: background .15s; }
        .san-notif-row:hover { background: #f4f8f6; }
        .san-notif-row.is-unread { background: #f0f8f3; border-left-color: #2a9264; }
        .san-notif-row input { margin-top: 3px; }
        .san-notif-row[hidden] { display: none !important; }
        .san-notif-message { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.6; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 13px; }
        .is-unread .san-notif-message { font-weight: 650; color: #174b38; }
        .san-notif-meta { display: block; margin-top: 7px; font-size: 10px; color: #6c8176; }
        #sanitary-notification-status:empty { display: none; }
        #sanitary-notification-status { padding: 12px 18px; font-size: 12px; line-height: 1.6; background: #f8faf9; flex-shrink: 0; }
        #sanitary-notification-status.is-empty { text-align: center; padding: 40px 24px; color: #526e60; }
        #sanitary-notification-status.is-error { color: #9f1239; background: #fff1f2; }
        #sanitary-notification-detail > div { border: 1px solid #d9e8df; border-radius: 20px; padding: 24px; box-shadow: 0 24px 80px #103f3a35; color-scheme: light; }
        #sanitary-detail-title { color: #164d3a; font-size: 18px; }
        #sanitary-detail-message { background: #f4f8f5; border-radius: 12px; padding: 16px; line-height: 1.8; }
        #sanitary-delete-detail { border: 1px solid #fecdd3; border-radius: 9px; padding: 9px 14px; }
        @media (max-width: 480px) { #sanitary-notifications { top: auto; bottom: 12px; left: 12px; right: 12px; width: auto; max-height: calc(100dvh - 100px); } .san-notif-toolbar { gap: 10px; } }
        @media (prefers-reduced-motion: reduce) { #notification-bell-btn, .san-notif-row { transition: none; } }
    </style>
    <section id="sanitary-notifications" hidden aria-label="Notifications">
        <div class="san-notif-heading flex items-center justify-between gap-3 text-white">
            <div><p class="san-notif-eyebrow">Sanitary workspace</p><h2 class="font-bold">Notifications <span id="sanitary-unread"></span></h2></div>
            <button type="button" id="sanitary-close-notifications" aria-label="Close notifications" class="p-2">&times;</button>
        </div>
        <div class="san-notif-filters" role="group" aria-label="Filter notifications">
            <button type="button" id="sanitary-filter-all" aria-pressed="true">All</button>
            <button type="button" id="sanitary-filter-unread" aria-pressed="false">Unread</button>
            <button type="button" id="sanitary-refresh">Refresh</button>
        </div>
        <div class="san-notif-toolbar flex flex-wrap items-center gap-3 border-b">
            <label class="flex items-center gap-2"><input type="checkbox" id="sanitary-select-all"> Select visible</label>
            <button type="button" id="sanitary-mark-all" class="font-bold text-teal-700">Mark all read</button>
            <button type="button" id="sanitary-delete-selected" class="font-bold text-rose-700" disabled>Delete selected</button>
        </div>
        <p id="sanitary-notification-status" class="px-4 py-2 text-xs text-slate-500" role="status" aria-live="polite"></p>
        <div id="sanitary-notification-list" class="divide-y"></div>
    </section>
    <div id="sanitary-notification-detail" hidden role="dialog" aria-modal="true" aria-labelledby="sanitary-detail-title">
        <div>
            <div class="flex items-center justify-between gap-3"><h2 id="sanitary-detail-title" class="font-bold">Notification details</h2><button type="button" id="sanitary-close-detail" aria-label="Close notification details" class="p-2">&times;</button></div>
            <p id="sanitary-detail-date" class="text-xs text-slate-500 my-3"></p>
            <p id="sanitary-detail-message" class="text-sm whitespace-pre-wrap break-words"></p>
            <button type="button" id="sanitary-delete-detail" class="mt-4 text-sm font-bold text-rose-700">Delete notification</button>
        </div>
    </div>
    <script>
        // Local notification controls: independent of the shared portal's markup/scripts.
        (() => {
            const bell = document.getElementById('notification-bell-btn');
            const panel = document.getElementById('sanitary-notifications');
            const detail = document.getElementById('sanitary-notification-detail');
            const list = document.getElementById('sanitary-notification-list');
            const status = document.getElementById('sanitary-notification-status');
            const selectAll = document.getElementById('sanitary-select-all');
            if (!bell || !panel) return;
            let activeId = null;
            let loading = false;
            let detailTrigger = null;
            let unreadOnly = false;
            const visibleChecks = () => [...list.children].filter(row => !row.hidden).map(row => row.querySelector('input'));
            const updateSelection = () => {
                const checks = visibleChecks();
                const count = checks.filter(input => input.checked).length;
                selectAll.checked = checks.length > 0 && count === checks.length;
                selectAll.indeterminate = count > 0 && count < checks.length;
                selectAll.disabled = checks.length === 0;
                const deleteButton = document.getElementById('sanitary-delete-selected');
                deleteButton.disabled = count === 0;
                deleteButton.textContent = count ? `Delete selected (${count})` : 'Delete selected';
            };
            const applyFilter = () => {
                for (const row of list.children) row.hidden = unreadOnly && row.dataset.unread !== 'true';
                const empty = visibleChecks().length === 0;
                status.classList.toggle('is-empty', empty);
                status.textContent = empty ? (unreadOnly ? 'You’re all caught up. No unread notifications.' : 'No notifications yet. New updates will appear here.') : '';
                updateSelection();
            };
            const formatDate = value => {
                if (!value) return 'Date unavailable';
                const date = new Date(String(value).replace(' ', 'T'));
                return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
            };
            bell.removeAttribute('onclick');
            bell.setAttribute('aria-controls', panel.id);
            bell.setAttribute('aria-expanded', 'false');
            bell.setAttribute('aria-label', 'Notifications');
            const request = async (action, payload) => {
                const url = new URL('SanitaryDashboard.php', window.location.href);
                url.searchParams.set('api', action);
                const options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
                if (payload) { options.method = 'POST'; options.body = new URLSearchParams(payload); }
                const response = await fetch(url, options);
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error('Notifications could not be loaded. Please try Refresh.');
                return data;
            };
            const closeDetail = () => { detail.hidden = true; activeId = null; (detailTrigger?.isConnected ? detailTrigger : bell).focus(); };
            const setOpen = open => {
                panel.hidden = !open;
                bell.setAttribute('aria-expanded', String(open));
                if (open) { document.getElementById('sanitary-close-notifications').focus(); refresh(); }
            };
            const refresh = async () => {
                if (loading) return;
                loading = true;
                panel.setAttribute('aria-busy', 'true');
                status.classList.remove('is-error', 'is-empty');
                status.textContent = 'Loading notifications…';
                try {
                    const data = await request('get_notifications');
                    const selected = new Set([...list.querySelectorAll('input:checked')].map(input => input.value));
                    list.replaceChildren();
                    for (const notification of data.notifications || []) {
                        const row = document.createElement('div');
                        const unread = !Number(notification.is_read);
                        row.className = 'san-notif-row' + (unread ? ' is-unread' : '');
                        row.dataset.unread = String(unread);
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox'; checkbox.value = String(notification.id); checkbox.checked = selected.has(checkbox.value);
                        checkbox.setAttribute('aria-label', 'Select notification');
                        const button = document.createElement('button');
                        button.type = 'button'; button.className = 'flex-1 min-w-0 text-left text-sm whitespace-pre-wrap break-words';
                        const message = document.createElement('span');
                        message.className = 'san-notif-message'; message.textContent = notification.message;
                        const time = document.createElement('span');
                        time.className = 'san-notif-meta'; time.textContent = (unread ? 'Unread · ' : 'Read · ') + formatDate(notification.created_at);
                        button.append(message, time);
                        button.addEventListener('click', async () => {
                            activeId = notification.id; detailTrigger = button;
                            document.getElementById('sanitary-detail-message').textContent = notification.message;
                            document.getElementById('sanitary-detail-date').textContent = formatDate(notification.created_at);
                            detail.hidden = false;
                            document.getElementById('sanitary-close-detail').focus();
                            try { await request('mark_read', { id: notification.id }); await refresh(); } catch (error) { status.textContent = error.message; }
                        });
                        row.append(checkbox, button); list.append(row);
                    }
                    const unread = Number(data.unreadCount) || 0;
                    document.getElementById('sanitary-unread').textContent = `${unread} unread`;
                    document.getElementById('sanitary-mark-all').disabled = unread === 0;
                    bell.setAttribute('aria-label', `Notifications (${unread} unread)`);
                    const badge = document.getElementById('notif-badge-count');
                    if (badge) { badge.textContent = String(unread); badge.classList.toggle('hidden', unread === 0); }
                    applyFilter();
                } catch (error) { status.classList.add('is-error'); status.textContent = error.message; }
                finally { loading = false; panel.setAttribute('aria-busy', 'false'); }
            };
            bell.addEventListener('click', () => setOpen(panel.hidden));
            document.getElementById('sanitary-close-notifications').addEventListener('click', () => { setOpen(false); bell.focus(); });
            document.getElementById('sanitary-refresh').addEventListener('click', refresh);
            document.getElementById('sanitary-close-detail').addEventListener('click', closeDetail);
            document.addEventListener('click', event => { if (detail.hidden && !panel.hidden && !panel.contains(event.target) && !bell.contains(event.target)) setOpen(false); });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') { if (!detail.hidden) closeDetail(); else if (!panel.hidden) { setOpen(false); bell.focus(); } }
                if (event.key === 'Tab' && !detail.hidden) {
                    const buttons = [...detail.querySelectorAll('button')];
                    const first = buttons[0], last = buttons[buttons.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                }
            });
            detail.addEventListener('click', event => { if (event.target === detail) closeDetail(); });
            selectAll.addEventListener('change', () => { visibleChecks().forEach(input => input.checked = selectAll.checked); updateSelection(); });
            list.addEventListener('change', updateSelection);
            for (const [id, filter] of [['sanitary-filter-all', false], ['sanitary-filter-unread', true]]) {
                document.getElementById(id).addEventListener('click', () => {
                    unreadOnly = filter;
                    list.querySelectorAll('input').forEach(input => input.checked = false);
                    document.getElementById('sanitary-filter-all').setAttribute('aria-pressed', String(!filter));
                    document.getElementById('sanitary-filter-unread').setAttribute('aria-pressed', String(filter));
                    applyFilter();
                });
            }
            document.getElementById('sanitary-mark-all').addEventListener('click', async () => { try { await request('mark_read', {}); await refresh(); } catch (error) { status.textContent = error.message; } });
            const deleteNotifications = async ids => {
                if (!ids.length) { status.textContent = 'Select a notification first.'; return; }
                try { await request('delete_notifications', { ids: ids.join(',') }); if (!detail.hidden) closeDetail(); await refresh(); }
                catch (error) { status.textContent = error.message; }
            };
            document.getElementById('sanitary-delete-selected').addEventListener('click', () => deleteNotifications(visibleChecks().filter(input => input.checked).map(input => input.value)));
            document.getElementById('sanitary-delete-detail').addEventListener('click', () => { if (activeId !== null) deleteNotifications([activeId]); });
            refresh();
            window.setInterval(() => { if (!document.hidden && !panel.hidden && detail.hidden) refresh(); }, 30000);
        })();
    </script>

    <script>
        (function () {
            const sidebar = document.querySelector('[data-feature-drawer]');
            const backdrop = document.querySelector('[data-drawer-backdrop]');
            const openBtn = document.querySelector('[data-drawer-open]');
            const closeBtn = document.querySelector('[data-drawer-close]');
            const setOpen = (open) => { sidebar?.classList.toggle('is-open', open); backdrop?.classList.toggle('is-open', open); document.body.classList.toggle('drawer-open', open); openBtn?.setAttribute('aria-expanded', String(!!open)); };
            openBtn?.addEventListener('click', () => setOpen(true));
            closeBtn?.addEventListener('click', () => setOpen(false));
            backdrop?.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });
        })();
    </script>
<link rel="stylesheet" href="styles/staff-record-search.css?v=1">
<script defer src="assets/js/staff-record-search.js?v=2"></script>
<script src="assets/js/staff-qr-modal.js?v=3" defer></script>
<script src="assets/js/staff-qr-appointment.js?v=4" defer></script>
<script src="assets/js/resident-select-search.js?v=4" defer></script>
<link rel="stylesheet" href="styles/staff-record-scroll.css?v=2">
<style data-record-scroll-critical>
@media screen {
[data-staff-record-scroll], .staff-record-scroll {
  display: block; box-sizing: border-box; min-height: 0 !important;
  max-height: 420px !important; max-height: min(45vh, 420px) !important;
  overflow-y: auto !important; overflow-x: auto !important;
  overscroll-behavior: contain; scrollbar-gutter: stable;
}
[data-staff-record-scroll]::-webkit-scrollbar, .staff-record-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
[data-staff-record-scroll]::-webkit-scrollbar-thumb, .staff-record-scroll::-webkit-scrollbar-thumb { background: #8cb5a5; border: 2px solid #f1f7f4; border-radius: 10px; }
}
@media print { [data-staff-record-scroll], .staff-record-scroll { max-height: none !important; overflow: visible !important; } }
</style>

<script src="assets/js/staff-record-scroll.js?v=4" defer></script>
<script src="assets/js/staff-resident-record-groups.js?v=2" defer></script>
</body>

</html>
