<?php
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/appointment-expiry.php';
// BEGIN shared appointment notification compatibility
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

// END shared appointment notification compatibility

if (!function_exists('portalServeUploadedImageIfRequested')) {
    function portalOutputImageFile(string $fullPath): void
    {
        $mime = function_exists('mime_content_type') ? mime_content_type($fullPath) : '';
        if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
            $mime = 'image/' . (strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) ?: 'jpeg');
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    function portalServeUploadedImageIfRequested(): void
    {
        $requestedImage = trim((string)($_GET['portal_image'] ?? ''));
        if ($requestedImage === '') {
            return;
        }
        $cleanPath = str_replace('\\', '/', $requestedImage);
        $marker = 'uploads/';
        $markerPosition = stripos($cleanPath, $marker);
        if ($markerPosition !== false) {
            $cleanPath = substr($cleanPath, $markerPosition);
        }
        $cleanPath = ltrim($cleanPath, '/');
        if (!str_starts_with($cleanPath, 'uploads/') || str_contains($cleanPath, '..')) {
            http_response_code(404);
            exit;
        }

        $candidateRoots = array_unique(array_filter([
            $_SERVER['DOCUMENT_ROOT'] ?? null,
            dirname(__DIR__),
            dirname(dirname(__DIR__)),
            dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'htdocs',
            dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'rhu.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
            dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'ruralhealthunit.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
            dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'htdocs',
            dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'rhu.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
            dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'ruralhealthunit.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
        ]));

        foreach ($candidateRoots as $root) {
            $uploadRoot = realpath($root . DIRECTORY_SEPARATOR . 'uploads');
            $fullPath = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath));
            if (!$uploadRoot || !$fullPath || !str_starts_with($fullPath, $uploadRoot) || !is_file($fullPath) || !is_readable($fullPath)) {
                continue;
            }
            portalOutputImageFile($fullPath);
        }

        $requestedDir = str_replace('/', DIRECTORY_SEPARATOR, dirname($cleanPath));
        $requestedBase = strtolower(pathinfo($cleanPath, PATHINFO_FILENAME));
        $requestedPrefix = preg_replace('/_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}$/i', '', $requestedBase);
        foreach ($candidateRoots as $root) {
            $uploadRoot = realpath($root . DIRECTORY_SEPARATOR . 'uploads');
            $folderPath = realpath($root . DIRECTORY_SEPARATOR . $requestedDir);
            if (!$uploadRoot || !$folderPath || !str_starts_with($folderPath, $uploadRoot) || !is_dir($folderPath)) {
                continue;
            }
            $matches = [];
            foreach (glob($folderPath . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $candidateFile) {
                if (!is_file($candidateFile) || !is_readable($candidateFile)) {
                    continue;
                }
                $candidateBase = strtolower(pathinfo($candidateFile, PATHINFO_FILENAME));
                if ($requestedPrefix !== '' && !str_starts_with($candidateBase, $requestedPrefix)) {
                    continue;
                }
                $matches[] = $candidateFile;
            }
            usort($matches, static fn($a, $b) => filemtime($b) <=> filemtime($a));
            if (!empty($matches)) {
                portalOutputImageFile($matches[0]);
            }
        }

        http_response_code(404);
        exit;
    }
}

portalServeUploadedImageIfRequested();

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('resolveImageUrl')) {
    function resolveImageUrl($url) {
        if (!$url) return '';
        if (str_starts_with($url, 'data:')) return $url;
        if (preg_match('/^https?:\/\//i', $url)) return $url;

        $clean = ltrim($url, '/');
        $normalizedClean = str_replace('\\', '/', trim($clean));
        $uploadsMarker = 'uploads/';
        $uploadsMarkerPosition = stripos($normalizedClean, $uploadsMarker);
        if ($uploadsMarkerPosition !== false) {
            $normalizedClean = ltrim(substr($normalizedClean, $uploadsMarkerPosition), '/');
        }
        $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedClean);
        if (str_starts_with($normalizedClean, 'uploads/') && is_file($localPath) && is_readable($localPath)) {
            $mime = function_exists('mime_content_type') ? mime_content_type($localPath) : '';
            if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
                $mime = 'image/' . (strtolower(pathinfo($localPath, PATHINFO_EXTENSION)) ?: 'jpeg');
            }
            $contents = @file_get_contents($localPath);
            if ($contents !== false) {
                return 'data:' . $mime . ';base64,' . base64_encode($contents);
            }
        }
        if (str_starts_with($normalizedClean, 'uploads/')) {
            return 'https://ruralhealthunit.page.gd/' . ltrim($normalizedClean, '/');
        }

        if (file_exists(dirname(__DIR__) . '/' . $clean)) {
            return $clean;
        }
        if (file_exists(dirname(__DIR__) . '/../../' . $clean)) {
            return '../../' . $clean;
        }
        if (file_exists(dirname(__DIR__) . '/../../../' . $clean)) {
            return '../../../' . $clean;
        }
        if (file_exists(dirname(__DIR__) . '/../' . $clean)) {
            return '../' . $clean;
        }

        return $clean;
    }
}

if (!function_exists('rhuTableExists')) {
    function rhuTableExists($pdo, $table) {
        static $cache = [];
        if (!$pdo || $table === '' || !rhuIsApprovedTable($table)) return false;
        if (!array_key_exists($table, $cache)) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
                $stmt->execute(['table_name' => $table]);
                $cache[$table] = (bool)$stmt->fetchColumn();
            } catch (Throwable $e) {
                $cache[$table] = false;
            }
        }
        return $cache[$table];
    }

    function rhuColumnExists($pdo, $table, $column) {
        static $cache = [];
        if (!$pdo || $table === '' || $column === '' || !rhuIsApprovedTable($table)) return false;
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = :table_name
                      AND COLUMN_NAME = :column_name
                ");
                $stmt->execute([
                    'table_name' => $table,
                    'column_name' => $column,
                ]);
                $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }

    function rhuBarangayId($pdo, $barangay) {
        if (!$pdo) return 1;
        $name = trim($barangay) ?: 'Poblacion';
        try {
            $stmt = $pdo->prepare('SELECT id FROM barangays WHERE name = :name LIMIT 1');
            $stmt->execute(['name' => $name]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) return $id;
            return (int)($pdo->query('SELECT id FROM barangays ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
        } catch (Throwable $e) {
            return 1;
        }
    }

    function rhuResidentListSql() {
        return "SELECT r.id, CONCAT(r.first_name, ' ', r.last_name) AS name, COALESCE(b.name, 'Unassigned') AS barangay
                FROM residents r LEFT JOIN barangays b ON b.id = r.barangay_id";
    }

    function rhuLivingResidentWhereSql(string $alias = 'r'): string {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'r';
        return "COALESCE({$alias}.is_active, 1) = 1
                AND LOWER(COALESCE({$alias}.status, 'Active')) NOT IN ('deceased', 'dead', 'inactive')
                AND NOT EXISTS (
                    SELECT 1
                    FROM vital_statistics death_vs
                    WHERE death_vs.resident_id = {$alias}.id
                      AND LOWER(COALESCE(death_vs.event_type, '')) = 'death'
                )";
    }
}

if (!function_exists('portalSaveHealthRecordEntry')) {
    function portalNumericValue($value) {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float)$value;
        if (preg_match('/-?\d+(?:\.\d+)?/', (string)$value, $match)) {
            return (float)$match[0];
        }
        return null;
    }

    function portalSaveHealthRecordEntry($pdo, $residentId, $data = []) {
        if (!$pdo || $residentId <= 0 || !rhuTableExists($pdo, 'health_records')) {
            return null;
        }

        $remarksParts = [];
        foreach (['record_type', 'chief_complaint', 'diagnosis', 'treatment', 'notes', 'status', 'follow_up_date'] as $key) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') {
                $label = ucwords(str_replace('_', ' ', $key));
                $remarksParts[] = "{$label}: {$value}";
            }
        }

        $inlineOwnTransaction = false;
        $inlineServices = !empty($_POST['fhsis_inline']);
        if ($inlineServices) {
            require_once dirname(__DIR__) . '/includes/fhsis-services.php';
            $inlineServices = (fhsisInlineActionSources(rhuStaffRecordDepartment($_SESSION))[$_POST['action'] ?? ''] ?? '') === 'health_records';
        }
        try {
            if ($inlineServices && !$pdo->inTransaction()) { $pdo->beginTransaction(); $inlineOwnTransaction = true; }
            $stmt = $pdo->prepare("INSERT INTO health_records
                (resident_id, blood_type, height_cm, weight_kg, blood_pressure, temperature, allergies, medical_conditions, current_medications, immunization_notes, family_history, last_checkup_date, recorded_by_id, remarks, created_at, updated_at)
                VALUES
                (:resident_id, :blood_type, :height_cm, :weight_kg, :blood_pressure, :temperature, :allergies, :medical_conditions, :current_medications, :immunization_notes, :family_history, :last_checkup_date, :recorded_by_id, :remarks, NOW(), NOW())");
            $stmt->execute([
                'resident_id' => $residentId,
                'blood_type' => trim((string)($data['blood_type'] ?? '')) ?: null,
                'height_cm' => portalNumericValue($data['height_cm'] ?? null),
                'weight_kg' => portalNumericValue($data['weight_kg'] ?? $data['weight'] ?? null),
                'blood_pressure' => trim((string)($data['blood_pressure'] ?? $data['bp'] ?? '')) ?: null,
                'temperature' => portalNumericValue($data['temperature'] ?? $data['temp'] ?? null),
                'allergies' => trim((string)($data['allergies'] ?? '')) ?: null,
                'medical_conditions' => trim((string)($data['medical_conditions'] ?? '')) ?: null,
                'current_medications' => trim((string)($data['current_medications'] ?? $data['medications'] ?? '')) ?: null,
                'immunization_notes' => trim((string)($data['immunization_notes'] ?? '')) ?: null,
                'family_history' => trim((string)($data['family_history'] ?? '')) ?: null,
                'last_checkup_date' => trim((string)($data['last_checkup_date'] ?? $data['date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                'recorded_by_id' => !empty($data['recorded_by_id']) ? (int)$data['recorded_by_id'] : null,
                'remarks' => trim(implode("\n", $remarksParts)) ?: null,
            ]);
            $recordId = (int)$pdo->lastInsertId();
            if ($inlineServices) {
                $inlinePost = $_POST;
                if (!is_array($inlinePost['fhsis_inline'])) throw new RuntimeException('Invalid completed service details.');
                if (($_POST['action'] ?? '') === 'save_birth_record') {
                    $subjectOwner = ($data['record_type'] ?? '') === 'Newborn birth record' ? 'child' : 'mother';
                    $inlinePost['fhsis_inline'] = array_values(array_filter($inlinePost['fhsis_inline'], static fn($entry)=>is_array($entry) && ($entry['subject_owner'] ?? 'mother') === $subjectOwner));
                }
                fhsisSaveInlineServices($pdo, 'health_records', $recordId, (int)$residentId, $inlinePost, $_SESSION);
            }
            if ($inlineOwnTransaction) $pdo->commit();
            return $recordId;
        } catch (Throwable $e) {
            if ($inlineOwnTransaction && $pdo->inTransaction()) $pdo->rollBack();
            if ($inlineServices) throw $e;
            error_log('portalSaveHealthRecordEntry: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('portalAutoSaveDiseaseCaseFromConsultation')) {
    function portalDiseaseTextMatches(string $text, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') return false;
        return (bool)preg_match('/(?<![a-z0-9])' . preg_quote(strtolower($needle), '/') . '(?![a-z0-9])/i', $text);
    }

    function portalAutoSaveDiseaseCaseFromConsultation($pdo, int $consultationId = 0, array $consultationData = []): ?array
    {
        if (!$pdo || !rhuTableExists($pdo, 'disease_cases') || !rhuTableExists($pdo, 'disease_types')) {
            return null;
        }

        try {
            if ($consultationId > 0) {
                $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $consultationId]);
                $dbConsultation = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $consultationData = array_merge($dbConsultation, $consultationData);
            }

            $residentId = (int)($consultationData['resident_id'] ?? 0);
            if ($residentId <= 0) return null;

            $caseDate = trim((string)($consultationData['consultation_date'] ?? date('Y-m-d')));
            $caseDate = $caseDate !== '' ? substr($caseDate, 0, 10) : date('Y-m-d');
            $haystack = strtolower(trim(implode(' ', array_filter([
                $consultationData['chief_complaint'] ?? '',
                $consultationData['diagnosis'] ?? '',
                $consultationData['remarks'] ?? '',
                $consultationData['consultation_notes'] ?? '',
                $consultationData['treatment'] ?? '',
                $consultationData['treatment_plan'] ?? '',
            ], static fn($value) => trim((string)$value) !== ''))));
            if ($haystack === '') return null;

            $diseaseTypes = $pdo->query('SELECT id, name FROM disease_types ORDER BY LENGTH(name) DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $aliases = [
                'tuberculosis' => ['tb', 'ptb', 'pulmonary tuberculosis'],
                'covid' => ['covid-19', 'covid 19', 'coronavirus'],
                'measles' => ['rubeola'],
                'chickenpox' => ['varicella'],
                'influenza' => ['flu'],
                'hypertension' => ['high blood pressure', 'htn'],
            ];

            $matchedDisease = null;
            foreach ($diseaseTypes as $diseaseType) {
                $name = trim((string)($diseaseType['name'] ?? ''));
                if ($name === '') continue;
                $candidates = [$name];
                $key = strtolower($name);
                foreach ($aliases as $aliasKey => $aliasList) {
                    if (str_contains($key, $aliasKey) || str_contains($aliasKey, $key)) {
                        $candidates = array_merge($candidates, $aliasList);
                    }
                }
                foreach (array_unique($candidates) as $candidate) {
                    if (portalDiseaseTextMatches($haystack, $candidate)) {
                        $matchedDisease = $diseaseType;
                        break 2;
                    }
                }
            }
            if (!$matchedDisease) return null;

            $diseaseId = (int)$matchedDisease['id'];
            $duplicate = $pdo->prepare('SELECT id FROM disease_cases WHERE resident_id = :resident_id AND disease_type_id = :disease_type_id AND case_date = :case_date LIMIT 1');
            $duplicate->execute([
                'resident_id' => $residentId,
                'disease_type_id' => $diseaseId,
                'case_date' => $caseDate,
            ]);
            $existingId = (int)($duplicate->fetchColumn() ?: 0);
            if ($existingId > 0) {
                return ['id' => $existingId, 'disease_name' => (string)$matchedDisease['name'], 'created' => false];
            }

            $columns = ['resident_id', 'disease_type_id', 'case_date'];
            $values = [':resident_id', ':disease_type_id', ':case_date'];
            $params = [
                'resident_id' => $residentId,
                'disease_type_id' => $diseaseId,
                'case_date' => $caseDate,
            ];
            if (rhuColumnExists($pdo, 'disease_cases', 'case_status')) {
                $columns[] = 'case_status';
                $values[] = ':case_status';
                $params['case_status'] = 'Suspected';
            }
            if (rhuColumnExists($pdo, 'disease_cases', 'case_classification')) {
                $columns[] = 'case_classification';
                $values[] = ':case_classification';
                $params['case_classification'] = 'Suspected';
            }
            if (rhuColumnExists($pdo, 'disease_cases', 'outcome')) {
                $columns[] = 'outcome';
                $values[] = ':outcome';
                $params['outcome'] = 'Ongoing';
            }
            if (rhuColumnExists($pdo, 'disease_cases', 'remarks')) {
                $columns[] = 'remarks';
                $values[] = ':remarks';
                $params['remarks'] = 'Auto-created from consultation #' . ($consultationId > 0 ? $consultationId : 'new') . '. Diagnosis: ' . trim((string)($consultationData['diagnosis'] ?? ''));
            }
            if (rhuColumnExists($pdo, 'disease_cases', 'reported_by_id')) {
                $columns[] = 'reported_by_id';
                $values[] = ':reported_by_id';
                $params['reported_by_id'] = (int)($consultationData['health_worker_id'] ?? $consultationData['physician_id'] ?? 0) ?: null;
            }

            $insert = $pdo->prepare('INSERT INTO disease_cases (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');
            $insert->execute($params);
            return ['id' => (int)$pdo->lastInsertId(), 'disease_name' => (string)$matchedDisease['name'], 'created' => true];
        } catch (Throwable $e) {
            error_log('Auto disease case detection failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('portalEnsureResidentLocationTable')) {
    function portalEnsureResidentLocationTable(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS resident_locations (
                resident_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                latitude DECIMAL(10,7) NOT NULL,
                longitude DECIMAL(10,7) NOT NULL,
                accuracy_m INT UNSIGNED NULL,
                source VARCHAR(40) NOT NULL DEFAULT 'resident_device',
                shared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_resident_locations_shared_at (shared_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    function portalSaveResidentLocation(PDO $pdo, int $residentId, float $latitude, float $longitude, ?int $accuracyM = null): bool
    {
        if ($residentId <= 0) return false;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) return false;
        portalEnsureResidentLocationTable($pdo);
        $accuracyM = $accuracyM !== null ? max(0, min($accuracyM, 100000)) : null;
        $stmt = $pdo->prepare("
            INSERT INTO resident_locations (resident_id, latitude, longitude, accuracy_m, source, shared_at, updated_at)
            VALUES (:resident_id, :latitude, :longitude, :accuracy_m, 'resident_device', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                accuracy_m = VALUES(accuracy_m),
                source = VALUES(source),
                shared_at = NOW(),
                updated_at = NOW()
        ");
        return $stmt->execute([
            'resident_id' => $residentId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => $accuracyM,
        ]);
    }
}

if (!function_exists('portalSaveSettings')) {
    function portalSaveSettings($pdo, $settings)
    {
        if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
        if (rhuTableExists($pdo, 'portal_settings')) {
            $settingStatement = $pdo->prepare("REPLACE INTO portal_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)");
            foreach ($settings as $key => $value) {
                $settingStatement->execute([
                    'setting_key' => (string)$key,
                    'setting_value' => is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES),
                ]);
            }
        }
        if (rhuTableExists($pdo, 'audit_logs')) {
            $statement = $pdo->prepare(
                "INSERT INTO audit_logs (action, module_name, record_id, description, created_at)
                 VALUES ('Settings Updated', 'System', 0, :description, NOW())"
            );
            $statement->execute(['description' => json_encode($settings, JSON_UNESCAPED_SLASHES)]);
        }
    }

    function portalCsrfToken() {
        if (empty($_SESSION['portal_csrf_token'])) {
            $_SESSION['portal_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['portal_csrf_token'];
    }

    function portalVerifyCsrf() {
        $submitted = (string)($_POST['csrf_token'] ?? '');
        $stored = (string)($_SESSION['portal_csrf_token'] ?? '');
        return $submitted !== '' && $stored !== '' && hash_equals($stored, $submitted);
    }

    function portalRequireAdmin()
    {
        if (empty($_SESSION['rhu_admin_authenticated']) || empty($_SESSION['user']['user_id'])) {
            header('Location: RHUAdminLogin.php');
            exit;
        }
    }

    function portalEnsureNotificationId(PDO $pdo): void
    {
        $column = $pdo->query("SHOW COLUMNS FROM portal_notifications LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            return;
        }
        if (stripos((string)($column['Extra'] ?? ''), 'auto_increment') === false) {
            $zeroRows = $pdo->query("SELECT id, message, created_at FROM portal_notifications WHERE id <= 0 ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($zeroRows as $row) {
                $newId = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM portal_notifications WHERE id > 0')->fetchColumn() ?: 1);
                $update = $pdo->prepare("UPDATE portal_notifications SET id = :new_id WHERE id = :old_id AND message = :message AND created_at = :created_at LIMIT 1");
                $update->execute([
                    'new_id' => $newId,
                    'old_id' => (int)($row['id'] ?? 0),
                    'message' => (string)($row['message'] ?? ''),
                    'created_at' => (string)($row['created_at'] ?? ''),
                ]);
            }
        }
        $primary = $pdo->query("SHOW INDEX FROM portal_notifications WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
        if (!$primary) {
            $pdo->exec('ALTER TABLE portal_notifications ADD PRIMARY KEY (`id`)');
        }
        $pdo->exec('ALTER TABLE portal_notifications MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
    }

    function portalEnsureNotificationTables($pdo): void
    {
        if (!$pdo) return;
        static $ensured = false;
        if ($ensured) return;
        $ensured = true;
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS portal_notifications (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    resident_id BIGINT UNSIGNED NULL,
                    user_id BIGINT UNSIGNED NULL,
                    audience_role VARCHAR(50) NULL,
                    message TEXT NOT NULL,
                    link_url VARCHAR(255) NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_resident (resident_id),
                    INDEX idx_user_role (user_id, audience_role),
                    INDEX idx_audience_read (audience_role, is_read)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('portalEnsureNotificationTables: ' . $e->getMessage());
        }
    }

    function portalNotify($pdo, $message, $userId = null, $role = null, $link = null)
    {
        if (!$pdo) return;
        try {
            portalEnsureNotificationTables($pdo);
            if (!rhuTableExists($pdo, 'portal_notifications')) {
                if (!rhuTableExists($pdo, 'audit_logs')) return;
                $statement = $pdo->prepare(
                    "INSERT INTO audit_logs (action, module_name, record_id, description, ip_address, user_agent, created_at)
                     VALUES ('Resident Notification', 'Resident Portal', :record_id, :description, :ip, :ua, NOW())"
                );
                $statement->execute([
                    'record_id' => (int)$userId,
                    'description' => (string)$message,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
                return;
            }
            $statement = $pdo->prepare(
                'INSERT INTO portal_notifications (user_id, audience_role, message, link_url)
                 VALUES (:user_id, :audience_role, :message, :link_url)'
            );
            $statement->execute([
                'user_id' => $userId,
                'audience_role' => $role,
                'message' => $message,
                'link_url' => $link,
            ]);
            $normalizedRole = strtoupper((string)$role);
            if (in_array($normalizedRole, ['RESIDENT', 'ALL'], true)) {
                portalSendPushToResidents($pdo, $message, $link);
            }
        } catch (PDOException $e) {
            error_log('portalNotify: ' . $e->getMessage());
        }
    }

    function portalNotifyRhuStaff($pdo, $message, $link = null)
    {
        portalNotify($pdo, $message, null, 'RHU_STAFF', $link);
    }

    function portalBase64UrlEncode($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    function portalBase64UrlDecode($value) {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }

    function portalEnsurePushTables($pdo)
    {
        if (!$pdo) return;
        static $ensured = false;
        if ($ensured) return;
        $ensured = true;
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS portal_push_settings (
                    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                    setting_value TEXT NOT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS portal_push_subscriptions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    resident_id BIGINT UNSIGNED NOT NULL,
                    endpoint TEXT NOT NULL,
                    p256dh TEXT NOT NULL,
                    auth TEXT NOT NULL,
                    user_agent VARCHAR(255) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_push_endpoint (endpoint(191)),
                    INDEX idx_push_resident (resident_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('portalEnsurePushTables: ' . $e->getMessage());
        }
    }

    function portalGetPushSetting($pdo, $key) {
        $stmt = $pdo->prepare('SELECT setting_value FROM portal_push_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    function portalSavePushSetting($pdo, $key, $value)
    {
        $stmt = $pdo->prepare('INSERT INTO portal_push_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
    }

    function portalOpenSslConfig(): array {
        static $config = null;
        if ($config !== null) return $config;
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: '',
            function_exists('rhuEnv') ? (string)rhuEnv('OPENSSL_CONF', '') : '',
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/openssl/openssl.cnf',
            'C:/xampp/php/extras/ssl/openssl.cnf',
            'C:/xampp/php/windowsXamppPhp/extras/ssl/openssl.cnf',
        ]);
        foreach ($candidates as $candidate) {
            $normalized = str_replace('\\', '/', $candidate);
            if (is_readable($normalized)) {
                $config = ['config' => $normalized];
                return $config;
            }
        }
        $config = [];
        return $config;
    }

    function portalGetVapidKeys($pdo) {
        if (!$pdo || !function_exists('openssl_pkey_new')) return null;
        try {
            portalEnsurePushTables($pdo);
            $publicKey = portalGetPushSetting($pdo, 'vapid_public_key');
            $privateKey = portalGetPushSetting($pdo, 'vapid_private_key_pem');
            if ($publicKey && $privateKey) {
                return ['publicKey' => $publicKey, 'privateKey' => $privateKey];
            }

            $key = openssl_pkey_new(array_merge(portalOpenSslConfig(), [
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ]));
            if (!$key) return null;
            openssl_pkey_export($key, $privatePem, null, portalOpenSslConfig());
            $details = openssl_pkey_get_details($key);
            $x = $details['ec']['x'] ?? '';
            $y = $details['ec']['y'] ?? '';
            if ($x === '' || $y === '' || empty($privatePem)) return null;
            $rawPublic = "\x04" . str_pad($x, 32, "\0", STR_PAD_LEFT) . str_pad($y, 32, "\0", STR_PAD_LEFT);
            $publicKey = portalBase64UrlEncode($rawPublic);
            portalSavePushSetting($pdo, 'vapid_public_key', $publicKey);
            portalSavePushSetting($pdo, 'vapid_private_key_pem', $privatePem);
            return ['publicKey' => $publicKey, 'privateKey' => $privatePem];
        } catch (Throwable $e) {
            error_log('portalGetVapidKeys: ' . $e->getMessage());
            return null;
        }
    }

    function portalDerReadLength(string $der, int &$offset): int {
        $length = ord($der[$offset++] ?? "\0");
        if (($length & 0x80) === 0) return $length;
        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) + ord($der[$offset++] ?? "\0");
        }
        return $length;
    }

    function portalDerSignatureToRaw($der, $partLength = 32) {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") return $der;
        portalDerReadLength($der, $offset);
        if (($der[$offset++] ?? '') !== "\x02") return $der;
        $rLen = portalDerReadLength($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        if (($der[$offset++] ?? '') !== "\x02") return $der;
        $sLen = portalDerReadLength($der, $offset);
        $s = substr($der, $offset, $sLen);
        $r = substr(str_pad(ltrim($r, "\0"), $partLength, "\0", STR_PAD_LEFT), -$partLength);
        $s = substr(str_pad(ltrim($s, "\0"), $partLength, "\0", STR_PAD_LEFT), -$partLength);
        return $r . $s;
    }

    function portalPublicKeyPemFromRawPoint($rawPoint) {
        $spkiPrefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spkiPrefix . $rawPoint), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    function portalHkdfExpand($prk, $info, $length) {
        $output = '';
        $last = '';
        for ($i = 1; strlen($output) < $length; $i++) {
            $last = hash_hmac('sha256', $last . $info . chr($i), $prk, true);
            $output .= $last;
        }
        return substr($output, 0, $length);
    }

    function portalEncryptPushPayload($payload, $clientPublicKey, $authSecret) {
        $receiverPublicPem = portalPublicKeyPemFromRawPoint($clientPublicKey);
        $receiverPublic = openssl_pkey_get_public($receiverPublicPem);
        $serverKey = openssl_pkey_new(array_merge(portalOpenSslConfig(), [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]));
        if (!$receiverPublic || !$serverKey) return null;
        $serverDetails = openssl_pkey_get_details($serverKey);
        $serverPublicKey = "\x04" . str_pad($serverDetails['ec']['x'] ?? '', 32, "\0", STR_PAD_LEFT) . str_pad($serverDetails['ec']['y'] ?? '', 32, "\0", STR_PAD_LEFT);
        $sharedSecret = openssl_pkey_derive($receiverPublic, $serverKey, 32);
        if (!$sharedSecret) return null;
        $salt = random_bytes(16);
        $recordSize = 4096;
        $authPrk = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $context = "WebPush: info\0" . $clientPublicKey . $serverPublicKey;
        $ikm = portalHkdfExpand($authPrk, $context, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $contentEncryptionKey = portalHkdfExpand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = portalHkdfExpand($prk, "Content-Encoding: nonce\0", 12);
        $cipherText = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $contentEncryptionKey, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipherText === false) return null;
        $header = $salt . pack('N', $recordSize) . chr(strlen($serverPublicKey)) . $serverPublicKey;
        return ['body' => $header . $cipherText . $tag, 'contentLength' => strlen($header . $cipherText . $tag)];
    }

    function portalVapidSubject(): string {
        $configured = trim((string)(function_exists('rhuEnv') ? rhuEnv('VAPID_SUBJECT', '') : ''));
        if ($configured !== '' && (str_starts_with($configured, 'mailto:') || str_starts_with($configured, 'https://'))) {
            return $configured;
        }
        $email = trim((string)(function_exists('rhuEnvAny') ? rhuEnvAny(['SMTP_FROM', 'SMTP_USER'], '') : ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $email;
        }
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
        return $host === 'localhost' || $host === '127.0.0.1'
            ? 'mailto:admin@example.com'
            : 'https://' . $host;
    }

    function portalBuildVapidAuthorization($endpoint, $keys) {
        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) return null;
        $audience = $parts['scheme'] . '://' . $parts['host'];
        $header = portalBase64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = portalBase64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 3600,
            'sub' => portalVapidSubject(),
        ]));
        $unsignedToken = $header . '.' . $claims;
        if (!openssl_sign($unsignedToken, $signature, $keys['privateKey'], OPENSSL_ALGO_SHA256)) return null;
        $jwt = $unsignedToken . '.' . portalBase64UrlEncode(portalDerSignatureToRaw($signature));
        return [
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['publicKey'],
        ];
    }

    function portalSendPushToResident($pdo, $residentId, $message, $link = null) {
        $result = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => []];
        if (!$pdo || $residentId <= 0 || !function_exists('curl_init')) return $result;
        try {
            portalEnsurePushTables($pdo);
            $keys = portalGetVapidKeys($pdo);
            if (!$keys) return $result;
            $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM portal_push_subscriptions WHERE resident_id = :resident_id AND is_active = 1');
            $stmt->execute(['resident_id' => $residentId]);
            $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($subscriptions as $subscription) {
                $result['attempted']++;
                $vapidHeaders = portalBuildVapidAuthorization((string)$subscription['endpoint'], $keys);
                if (!$vapidHeaders) {
                    $result['failed']++;
                    $result['statuses'][] = ['id' => (int)$subscription['id'], 'status' => 0, 'error' => 'Invalid VAPID authorization'];
                    continue;
                }
                $clientPublicKey = portalBase64UrlDecode((string)$subscription['p256dh']);
                $authSecret = portalBase64UrlDecode((string)$subscription['auth']);
                $payload = json_encode([
                    'title' => 'RHU Resident Notification',
                    'body' => $message,
                    'url' => $link ?: 'ResidentDashboard.php',
                ], JSON_UNESCAPED_SLASHES);
                $encrypted = $payload !== false ? portalEncryptPushPayload($payload, $clientPublicKey, $authSecret) : null;
                if (!$encrypted) {
                    $result['failed']++;
                    $result['statuses'][] = ['id' => (int)$subscription['id'], 'status' => 0, 'error' => 'Payload encryption failed'];
                    continue;
                }
                $headers = array_merge($vapidHeaders, [
                    'TTL: 86400',
                    'Urgency: high',
                    'Topic: rhu-resident-notification',
                    'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream',
                ]);
                $postFields = $encrypted['body'];
                $headers[] = 'Content-Length: ' . (int)$encrypted['contentLength'];
                $ch = curl_init((string)$subscription['endpoint']);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POSTFIELDS => $postFields,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                ]);
                $response = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $curlError = curl_error($ch);
                if ($status < 200 || $status >= 300) {
                    error_log('portalSendPushToResident HTTP ' . $status . ' for subscription #' . (int)$subscription['id'] . ': ' . trim((string)$response . ' ' . $curlError));
                    $result['failed']++;
                } else {
                    $result['sent']++;
                }
                $result['statuses'][] = [
                    'id' => (int)$subscription['id'],
                    'status' => $status,
                    'error' => $curlError ?: null,
                    'response' => is_string($response) ? substr($response, 0, 200) : null,
                ];
                unset($ch);
                if (in_array($status, [404, 410], true)) {
                    $disable = $pdo->prepare('UPDATE portal_push_subscriptions SET is_active = 0 WHERE id = :id');
                    $disable->execute(['id' => (int)$subscription['id']]);
                }
            }
        } catch (Throwable $e) {
            error_log('portalSendPushToResident: ' . $e->getMessage());
            $result['failed']++;
            $result['statuses'][] = ['id' => 0, 'status' => 0, 'error' => $e->getMessage()];
        }
        return $result;
    }

    function portalSendPushToResidents($pdo, $message, $link = null)
    {
        if (!$pdo || !function_exists('curl_init')) return;
        try {
            if (!rhuTableExists($pdo, 'portal_push_subscriptions')) return;
            portalEnsurePushTables($pdo);
            $residentIds = $pdo->query('SELECT DISTINCT resident_id FROM portal_push_subscriptions WHERE is_active = 1 AND resident_id > 0')
                ->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($residentIds as $residentId) {
                portalSendPushToResident($pdo, (int)$residentId, $message, $link);
            }
        } catch (Throwable $e) {
            error_log('portalSendPushToResidents: ' . $e->getMessage());
        }
    }

    function portalNotifyResident($pdo, $residentId, $message, $link = null)
    {
        if (!$pdo) return;
        try {
            portalEnsureNotificationTables($pdo);
            $notificationResidentId = $residentId;
            try {
                if (rhuTableExists($pdo, 'audit_logs')) {
                    $ownerStmt = $pdo->prepare("SELECT record_id FROM audit_logs WHERE action = 'Resident Dependent' AND module_name = 'Resident Portal' AND entity_id = :dependent_id ORDER BY id DESC LIMIT 1");
                    $ownerStmt->execute(['dependent_id' => $residentId]);
                    $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
                    if ($ownerId > 0) $notificationResidentId = $ownerId;
                }
            } catch (Throwable $t) {
            }
            if (!rhuTableExists($pdo, 'portal_notifications')) {
                if (rhuTableExists($pdo, 'audit_logs')) {
                    $statement = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, description, ip_address, user_agent, created_at)
                        VALUES ('Resident Notification', 'Resident Portal', :resident_id, :message, :ip_address, :user_agent, NOW())");
                    $statement->execute([
                        'resident_id' => $notificationResidentId,
                        'message' => $message,
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    ]);
                }
                portalSendPushToResident($pdo, $notificationResidentId, $message, $link);
                return;
            }
            $userId = null;
            try {
                $statement1 = $pdo->prepare('SELECT user_id FROM residents WHERE id = :resident_id LIMIT 1');
                $statement1->execute(['resident_id' => $notificationResidentId]);
                $rUid = $statement1->fetchColumn();
                if ($rUid) $userId = (int)$rUid;
            } catch (Throwable $t) {
            }

            if (!$userId) {
                try {
                    $statement2 = $pdo->prepare('SELECT u.id FROM residents r JOIN users u ON u.email = r.email WHERE r.id = :resident_id LIMIT 1');
                    $statement2->execute(['resident_id' => $notificationResidentId]);
                    $uId = $statement2->fetchColumn();
                    if ($uId) $userId = (int)$uId;
                } catch (Throwable $t) {
                }
            }

            $statement = $pdo->prepare(
                'INSERT INTO portal_notifications (resident_id, user_id, audience_role, message, link_url)
                 VALUES (:resident_id, :user_id, NULL, :message, :link_url)'
            );
            $statement->execute([
                'resident_id' => $notificationResidentId,
                'user_id' => $userId ?: null,
                'message' => $message,
                'link_url' => $link,
            ]);
            portalSendPushToResident($pdo, $notificationResidentId, $message, $link);
        } catch (PDOException $e) {
            error_log('portalNotifyResident: ' . $e->getMessage());
        }
    }

    if (!function_exists('portalBroadcastWorkflowUpdate')) {
        function portalBroadcastWorkflowUpdate($pdo, string $message, ?int $residentId = null, ?string $residentLink = null, array $roles = ['RHU_STAFF']): void
        {
            if ($residentId !== null && $residentId > 0 && function_exists('portalNotifyResident')) {
                portalNotifyResident($pdo, $residentId, $message, $residentLink);
            }
            if (function_exists('portalNotify')) {
                foreach (array_values(array_unique(array_filter($roles))) as $role) {
                    portalNotify($pdo, $message, null, strtoupper($role), $role === 'RHU_STAFF' ? 'RHUAdminDashboard.php?tab=staff' : 'MedTechDashboard.php?tab=overview');
                }
            }
        }
    }

    function portalEnsureCertificateTypes($pdo, $typeNames) {
        if (!$pdo || !$typeNames) return [];
        if (!rhuTableExists($pdo, 'certificate_types')) {
            $rows = [];
            $id = 1;
            foreach ($typeNames as $name) {
                $name = trim((string)$name);
                if ($name !== '') $rows[] = ['id' => $id++, 'certificate_type_name' => $name];
            }
            return $rows;
        }
        $insert = $pdo->prepare('INSERT IGNORE INTO certificate_types (certificate_type_name, description, requirements, fee) VALUES (:name, :description, :requirements, 0)');
        $cleanNames = [];
        foreach ($typeNames as $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $cleanNames[] = $name;
            $insert->execute(['name' => $name, 'description' => 'Issued by an authorized RHU healthcare professional.', 'requirements' => 'Verified resident record']);
        }
        if (!$cleanNames) return [];
        $placeholders = implode(',', array_fill(0, count($cleanNames), '?'));
        $select = $pdo->prepare("SELECT id, certificate_type_name FROM certificate_types WHERE certificate_type_name IN ({$placeholders}) ORDER BY certificate_type_name");
        $select->execute($cleanNames);
        return $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function portalIssueResidentCertificate($pdo, $input, $staffId, $issuerRole) {
        if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
        if (!rhuTableExists($pdo, 'certificate_types') && rhuTableExists($pdo, 'certificates')) {
            $fallbackTypes = [
                1 => 'Medical Certificate',
                2 => 'Vaccination Certificate',
                3 => 'Pregnancy Certificate',
                4 => 'Barangay Health Certificate',
                5 => 'Fitness Certificate',
                6 => 'Travel Health Certificate',
                7 => 'Mental Health Certificate',
            ];
            $residentId = (int)($input['resident_id'] ?? 0);
            $typeId = (int)($input['certificate_type_id'] ?? 0);
            $typeName = trim((string)($input['certificate_type'] ?? '')) ?: ($fallbackTypes[$typeId] ?? 'Medical Certificate');
            $purpose = trim((string)($input['purpose'] ?? ''));
            $issueDate = trim((string)($input['issue_date'] ?? date('Y-m-d')));
            $expiryDate = trim((string)($input['expiry_date'] ?? ''));
            if ($residentId <= 0 || $purpose === '') {
                throw new InvalidArgumentException('Resident and purpose are required.');
            }
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $issuerRole), 0, 3)) ?: 'RHU';
            $certificateNumber = $prefix . '-' . date('Ymd-His') . '-' . str_pad((string)$residentId, 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("INSERT INTO certificates (resident_id, issued_by_id, certificate_number, certificate_type, purpose, issue_date, expiry_date, status, created_at)
                VALUES (:resident_id, :issued_by_id, :certificate_number, :certificate_type, :purpose, :issue_date, :expiry_date, 'Issued', NOW())");
            $stmt->execute([
                'resident_id' => $residentId,
                'issued_by_id' => $staffId > 0 ? $staffId : null,
                'certificate_number' => $certificateNumber,
                'certificate_type' => $typeName,
                'purpose' => $purpose,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            ]);
            portalNotifyResident($pdo, $residentId, "{$typeName} {$certificateNumber} was issued by {$issuerRole}.", 'ResidentDashboard.php?tab=certificates');
            return ['type' => $typeName, 'number' => $certificateNumber, 'id' => (int)$pdo->lastInsertId()];
        }
        $residentId = (int)($input['resident_id'] ?? 0);
        $certificateTypeId = (int)($input['certificate_type_id'] ?? 0);
        $purpose = trim((string)($input['purpose'] ?? ''));
        $issueDate = trim((string)($input['issue_date'] ?? date('Y-m-d')));
        $expiryDate = trim((string)($input['expiry_date'] ?? ''));
        if ($residentId <= 0 || $certificateTypeId <= 0 || $purpose === '') {
            throw new InvalidArgumentException('Resident, certificate type, and purpose are required.');
        }
        $residentStmt = $pdo->prepare('SELECT id FROM residents WHERE id = :id AND is_active = 1 LIMIT 1');
        $residentStmt->execute(['id' => $residentId]);
        if (!$residentStmt->fetchColumn()) throw new RuntimeException('The selected active resident was not found.');
        $typeStmt = $pdo->prepare('SELECT certificate_type_name FROM certificate_types WHERE id = :id LIMIT 1');
        $typeStmt->execute(['id' => $certificateTypeId]);
        $typeName = $typeStmt->fetchColumn();
        if (!$typeName) throw new RuntimeException('The selected certificate type was not found.');
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $issuerRole), 0, 3)) ?: 'RHU';
        $certificateNumber = $prefix . '-' . date('Ymd-His') . '-' . str_pad((string)$residentId, 4, '0', STR_PAD_LEFT) . '-' . random_int(10, 99);
        $stmt = $pdo->prepare("INSERT INTO health_certificates
            (resident_id, certificate_type_id, certificate_number, issue_date, expiry_date, issued_by_id, purpose, validity_status, created_at)
            VALUES (:resident, :type, :number, :issue_date, :expiry_date, :issuer, :purpose, 'Valid', NOW())");
        $stmt->execute([
            'resident' => $residentId,
            'type' => $certificateTypeId,
            'number' => $certificateNumber,
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            'issuer' => $staffId > 0 ? $staffId : null,
            'purpose' => $purpose
        ]);
        $certificateId = (int)$pdo->lastInsertId();
        portalNotifyResident($pdo, $residentId, "{$typeName} {$certificateNumber} was issued by {$issuerRole} and is ready to view or print.", 'ResidentDashboard.php?tab=certificates');
        portalAudit($pdo, (int)($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? $_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0), "Issued {$typeName}", 'health_certificates', $certificateId);
        return ['id' => $certificateId, 'number' => $certificateNumber, 'type' => $typeName];
    }

    function portalCertificateWorkflowLog($pdo, $certificateId, $action, $notes = '', $staffId = null)
    {
        $workflow = portalCertificateWorkflow($pdo, $certificateId);
        $workflow['logs'][] = [
            'action' => $action,
            'notes' => $notes,
            'staff_id' => $staffId ?: (int)($_SESSION['rhu_staff_login']['staff_id'] ?? $_SESSION['user']['user_id'] ?? 0) ?: null,
            'at' => date('Y-m-d H:i:s'),
        ];
        portalSaveCertificateWorkflow($pdo, $certificateId, $workflow);
    }

    function portalCertificateWorkflow($pdo, $certificateId) {
        $stmt = $pdo->prepare("SELECT remarks FROM certificates WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $certificateId]);
        $remarks = (string)($stmt->fetchColumn() ?: '');
        $decoded = json_decode($remarks, true);
        if (!is_array($decoded) || empty($decoded['_certificate_workflow'])) {
            $decoded = [
                '_certificate_workflow' => 1,
                'note' => $remarks !== '' ? $remarks : '',
                'admin' => ['status' => 'Pending'],
                'doctor' => ['status' => 'Pending'],
                'logs' => [],
            ];
        }
        $decoded['admin'] = is_array($decoded['admin'] ?? null) ? $decoded['admin'] : ['status' => 'Pending'];
        $decoded['doctor'] = is_array($decoded['doctor'] ?? null) ? $decoded['doctor'] : ['status' => 'Pending'];
        $decoded['logs'] = is_array($decoded['logs'] ?? null) ? $decoded['logs'] : [];
        return $decoded;
    }

    function portalSaveCertificateWorkflow($pdo, $certificateId, $workflow)
    {
        $workflow['_certificate_workflow'] = 1;
        $stmt = $pdo->prepare("UPDATE certificates SET remarks = :remarks, updated_at = NOW() WHERE id = :id");
        $stmt->execute([
            'remarks' => json_encode($workflow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'id' => $certificateId,
        ]);
    }

    function portalFindCertificateApprovalByToken($pdo, $token) {
        $token = trim($token);
        if ($token === '') return null;
        $hash = hash('sha256', $token);
        $rows = $pdo->query("SELECT id, remarks FROM certificates WHERE remarks IS NOT NULL AND remarks <> '' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $workflow = json_decode((string)($row['remarks'] ?? ''), true);
            if (!is_array($workflow)) continue;
            foreach (['admin', 'doctor'] as $role) {
                if (($workflow[$role]['token_hash'] ?? '') === $hash) {
                    return [
                        'certificate_id' => (int)$row['id'],
                        'role' => $role,
                        'approver_type' => $role === 'admin' ? 'Administrator' : 'Doctor/Staff',
                        'approver_email' => (string)($workflow[$role]['email'] ?? ''),
                        'workflow' => $workflow,
                    ];
                }
            }
        }
        return null;
    }

    function portalSendCertificateApprovalRequests($pdo, $certificateId, $adminPerson, $doctorPerson) {
        $workflow = portalCertificateWorkflow($pdo, $certificateId);
        $results = [];
        foreach (['admin' => $adminPerson, 'doctor' => $doctorPerson] as $role => $person) {
            if ($role === 'admin' && (!empty($person['signature_path'])
                || (($workflow['admin']['status'] ?? '') === 'Approved' && !empty($workflow['admin']['signature_path'])))) {
                if (!empty($person['signature_path'])) {
                    $workflow['admin'] = array_merge($workflow['admin'] ?? [], $person, [
                        'status' => 'Approved', 'approved_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                unset($workflow['admin']['token_hash']);
                portalSaveCertificateWorkflow($pdo, $certificateId, $workflow);
                $results[$role] = ['email' => $person['email'] ?? '', 'sent' => false,
                    'skipped' => true, 'error' => '', 'approval_url' => ''];
                continue;
            }
            $token = bin2hex(random_bytes(24));
            $workflow[$role] = array_merge($workflow[$role] ?? [], [
                'email' => (string)($person['email'] ?? ''),
                'user_id' => (int)($person['user_id'] ?? 0) ?: null,
                'staff_id' => (int)($person['staff_id'] ?? 0) ?: null,
                'token_hash' => hash('sha256', $token),
                'requested_at' => date('Y-m-d H:i:s'),
                'status' => !empty($person['signature_path']) ? 'Approved' : 'Pending',
            ]);
            if (!empty($person['signature_path'])) {
                $workflow[$role]['signature_path'] = (string)$person['signature_path'];
                $workflow[$role]['approved_at'] = date('Y-m-d H:i:s');
            }
            $approveUrl = portalCertificateUrl(['certificate_signature_approval' => $token, 'decision' => 'approve']);
            $rejectUrl = portalCertificateUrl(['certificate_signature_approval' => $token, 'decision' => 'reject']);
            $email = (string)($person['email'] ?? '');
            $sent = false;
            $mailResult = ['success' => false, 'error' => 'Recipient email or mailer unavailable.'];
            portalSaveCertificateWorkflow($pdo, $certificateId, $workflow);
            if ($email !== '' && function_exists('sendRHUEmail')) {
                $subject = 'RHU certificate e-signature approval request';
                $body = '<p>A certificate is waiting for your e-signature approval.</p>'
                    . '<p><a href="' . htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8') . '">Approve and upload signature</a></p>'
                    . '<p><a href="' . htmlspecialchars($rejectUrl, ENT_QUOTES, 'UTF-8') . '">Reject request</a></p>';
                $mailResult = sendRHUEmail($email, $subject, $body);
                $sent = !empty($mailResult['success']);
            }
            portalRecordCertificateEmail($pdo, $certificateId, $email, $role . ' approval', 'RHU certificate e-signature approval request', $mailResult);
            $results[$role] = ['email' => $email, 'sent' => $sent, 'error' => $mailResult['error'] ?? '', 'approval_url' => $approveUrl];
        }
        $workflow['logs'][] = ['action' => 'Approval requested', 'notes' => 'E-signature approval tokens created for admin and assigned doctor/staff.', 'at' => date('Y-m-d H:i:s')];
        portalSaveCertificateWorkflow($pdo, $certificateId, $workflow);
        portalRefreshCertificateWorkflowStatus($pdo, $certificateId);
        return $results;
    }

    function portalCertificateUrl($params) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = $_SERVER['SCRIPT_NAME'] ?? '/RHUAdminDashboard.php';
        return $scheme . '://' . $host . $path . '?' . http_build_query($params);
    }

    function portalPublicAssetUrl($path) {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }
        $path = portalNormalizeAssetPath($path);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/';
        if (strpos($script, '/src/app/components/') !== false) {
            $base = preg_replace('#/src/app/components/[^/]+$#', '/', $script) ?: '/';
        } else {
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        }
        return $scheme . '://' . $host . rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    function portalNormalizeAssetPath($path) {
        $path = str_replace('\\', '/', trim($path));
        $marker = 'uploads/';
        $markerPosition = stripos($path, $marker);
        if ($markerPosition !== false) {
            return ltrim(substr($path, $markerPosition), '/');
        }
        return ltrim($path, '/');
    }

    function portalSignatureImageSource($path, $absolute = false) {
        $normalized = portalNormalizeAssetPath($path);
        if ($normalized === '') return '';
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://') || str_starts_with($normalized, 'data:')) {
            return $normalized;
        }
        $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        if (is_file($filePath) && is_readable($filePath)) {
            $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : '';
            if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
                $mime = 'image/' . (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'png');
            }
            $contents = @file_get_contents($filePath);
            if ($contents !== false) return 'data:' . $mime . ';base64,' . base64_encode($contents);
        }
        return 'https://ruralhealthunit.page.gd/' . ltrim($normalized, '/');
    }

    function portalSelectCertificateDoctor($pdo, $certificateTypeId, $purpose, $processingDate, $preferredStaffId = 0) {
        if ($preferredStaffId > 0) {
            $stmt = $pdo->prepare("
                SELECT id AS staff_id, position_title AS staff_type, role AS specialization, email, CONCAT(first_name, ' ', last_name) AS name, id AS user_id
                FROM health_workers
                WHERE id = :id AND status = 'Active'
                LIMIT 1
            ");
            $stmt->execute(['id' => $preferredStaffId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($doc) return $doc;
        }
        $stmt = $pdo->query("
            SELECT id AS staff_id, position_title AS staff_type, role AS specialization, email, CONCAT(first_name, ' ', last_name) AS name, id AS user_id
            FROM health_workers
            WHERE status = 'Active'
            ORDER BY CASE WHEN position_title LIKE '%Doctor%' OR position_title LIKE '%Physician%' OR role LIKE '%Doctor%' THEN 0 ELSE 1 END, id ASC
            LIMIT 1
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function portalGenerateCertificateHtml($pdo, $certificateId, $absoluteImages = false) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.certificate_number, c.certificate_type AS certificate_type_name, c.purpose, c.issue_date, c.expiry_date, c.status AS validity_status, c.status AS workflow_status, c.remarks,
                   CONCAT(r.first_name, ' ', r.last_name) AS resident_name, r.address, COALESCE(b.name, r.barangay, '') AS barangay,
                   r.birthdate AS date_of_birth, r.sex AS gender, r.email,
                   CONCAT(hw.first_name, ' ', hw.last_name) AS doctor_name, hw.position_title AS doctor_position
            FROM certificates c
            LEFT JOIN residents r ON r.id = c.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_workers hw ON hw.id = c.issued_by_id
            WHERE c.id = :id
        ");
        $stmt->execute(['id' => $certificateId]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cert) throw new RuntimeException('Certificate record not found.');
        $workflow = json_decode((string)($cert['remarks'] ?? ''), true);
        $workflow = is_array($workflow) ? $workflow : [];
        $doctorSignature = portalSignatureImageSource((string)($workflow['doctor']['signature_path'] ?? ''), $absoluteImages);
        $adminSignature = portalSignatureImageSource((string)($workflow['admin']['signature_path'] ?? ''), $absoluteImages);
        $certificateNo = $cert['certificate_number'] ?: ('RHU-' . str_pad((string)$certificateId, 8, '0', STR_PAD_LEFT));
        $issueDate = $cert['issue_date'] ? date('F j, Y', strtotime($cert['issue_date'])) : date('F j, Y');
        $expiryDate = $cert['expiry_date'] ? date('F j, Y', strtotime($cert['expiry_date'])) : 'No fixed expiration';
        $adminName = 'RHU Administrator';
        $doctorName = trim((string)($cert['doctor_name'] ?? '')) ?: 'Authorized RHU Physician';
        $doctorPosition = trim((string)($cert['doctor_position'] ?? '')) ?: 'Authorized Staff';
        $sealUrl = portalPublicAssetUrl('assets/images/nasugbu_seal.png');
        $residentAddress = trim(($cert['address'] ?? '') . ', ' . ($cert['barangay'] ?? ''), ' ,');
        $certificateType = strtoupper((string)($cert['certificate_type_name'] ?? 'HEALTH CERTIFICATE'));
        $verificationRef = 'Ref: ' . substr(hash('sha256', $certificateNo . '|' . $certificateId), 0, 18);
        return '<section class="official-certificate-template">'
            . '<img class="cert-watermark" src="' . htmlspecialchars($sealUrl, ENT_QUOTES, 'UTF-8') . '" alt="">'
            . '<div class="cert-header"><img class="cert-seal" src="' . htmlspecialchars($sealUrl, ENT_QUOTES, 'UTF-8') . '" width="96" height="96" style="width:96px;max-width:20vw;height:96px;object-fit:contain" alt="Municipality of Nasugbu official seal"><div class="cert-header-copy">'
            . '<p class="cert-republic">Republic of the Philippines</p><p>CALABARZON Region</p><p>Province of Batangas</p>'
            . '<h1>Municipality of Nasugbu</h1><h2>Nasugbu Rural Health Unit I</h2></div></div>'
            . '<div class="cert-rule"></div>'
            . '<h3>' . htmlspecialchars($certificateType, ENT_QUOTES, 'UTF-8') . '</h3>'
            . '<p class="cert-no">Certificate No. ' . htmlspecialchars($certificateNo, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div class="cert-body"><p class="cert-greeting"><strong>TO WHOM IT MAY CONCERN:</strong></p>'
            . '<p>This is to certify that <strong>' . htmlspecialchars($cert['resident_name'] ?: 'Resident', ENT_QUOTES, 'UTF-8') . '</strong>, a resident of '
            . htmlspecialchars($residentAddress ?: 'Nasugbu, Batangas', ENT_QUOTES, 'UTF-8') . ', has been duly examined and/or verified by this office and is hereby issued this '
            . htmlspecialchars(strtolower((string)($cert['certificate_type_name'] ?? 'certificate')), ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p>This is to certify further that the above-named person has satisfied the applicable requirements of the Nasugbu Rural Health Unit I for <strong>'
            . htmlspecialchars($cert['purpose'] ?: 'official health certification', ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p>Issued this <strong>' . htmlspecialchars(strtoupper($issueDate), ENT_QUOTES, 'UTF-8') . '</strong> at the Municipality of Nasugbu, Province of Batangas, Philippines, upon request of the interested party for whatever lawful purpose this certificate may serve.</p>'
            . '<div class="cert-dates"><span>Issue date: <strong>' . htmlspecialchars($issueDate, ENT_QUOTES, 'UTF-8') . '</strong></span><span>Valid until: <strong>' . htmlspecialchars($expiryDate, ENT_QUOTES, 'UTF-8') . '</strong></span></div></div>'
            . '<div class="cert-signatures"><div><div class="signature-wrap">' . ($doctorSignature !== '' ? '<img class="certificate-signature-image" src="' . htmlspecialchars($doctorSignature, ENT_QUOTES, 'UTF-8') . '" alt="Doctor signature">' : '') . '<div class="signature-line"></div></div><strong>' . htmlspecialchars($doctorName, ENT_QUOTES, 'UTF-8') . '</strong><small>' . htmlspecialchars($doctorPosition, ENT_QUOTES, 'UTF-8') . '</small></div>'
            . '<div><div class="signature-wrap">' . ($adminSignature !== '' ? '<img class="certificate-signature-image" src="' . htmlspecialchars($adminSignature, ENT_QUOTES, 'UTF-8') . '" alt="Admin signature">' : '') . '<div class="signature-line"></div></div><strong>' . htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') . '</strong><small>Authorized RHU Officer</small></div></div>'
            . '<div class="cert-footer"><span>Verification ' . htmlspecialchars($verificationRef, ENT_QUOTES, 'UTF-8') . '</span><span>Status: ' . htmlspecialchars($cert['workflow_status'] ?: $cert['validity_status'], ENT_QUOTES, 'UTF-8') . '</span></div>'
            . '</section>';
    }

    function portalRefreshCertificateWorkflowStatus($pdo, $certificateId) {
        try {
            $workflow = portalCertificateWorkflow($pdo, $certificateId);
            $adminOk = strtolower((string)($workflow['admin']['status'] ?? '')) === 'approved';
            $doctorOk = strtolower((string)($workflow['doctor']['status'] ?? '')) === 'approved';
            $status = $adminOk && $doctorOk ? 'Signed' : 'Pending Doctor Approval';
            if (strtolower((string)($workflow['admin']['status'] ?? '')) === 'rejected' || strtolower((string)($workflow['doctor']['status'] ?? '')) === 'rejected') {
                $status = 'Rejected';
            }
            $pdo->prepare("UPDATE certificates SET status = :status, updated_at = NOW() WHERE id = :id")->execute(['status' => $status, 'id' => $certificateId]);
            return $status;
        } catch (Throwable $e) {
            return 'Pending Doctor Approval';
        }
    }

    function portalAutoSendSignedCertificate($pdo, $certificateId) {
        $stmt = $pdo->prepare("
            SELECT c.certificate_number, c.resident_id, r.email, CONCAT(r.first_name, ' ', r.last_name) AS resident_name
            FROM certificates c
            JOIN residents r ON r.id = c.resident_id
            WHERE c.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $certificateId]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cert || empty($cert['email'])) return false;

        $signedHtml = portalGenerateCertificateHtml($pdo, $certificateId);
        $emailCertificateHtml = portalGenerateCertificateHtml($pdo, $certificateId, true);
        $downloadUrl = portalCertificateUrl(['certificate_pdf' => $certificateId]);
        $subject = 'Your RHU certificate is ready';
        $html = '<p>Your signed RHU certificate is approved and ready. The signed certificate is shown below.</p>'
            . '<div style="margin:16px 0;padding:14px;border:1px solid #d1d5db;background:#ffffff;max-width:900px">'
            . $emailCertificateHtml
            . '</div>'
            . '<p>You can also open it from your Resident Portal certificates tab.</p>'
            . '<p><a href="' . htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') . '">Download certificate</a></p>';
        $result = function_exists('sendRHUEmail')
            ? sendRHUEmail((string)$cert['email'], $subject, $html)
            : ['success' => false, 'method' => 'none', 'error' => 'Mailer unavailable'];
        portalRecordCertificateEmail($pdo, $certificateId, (string)$cert['email'], 'resident_signed_certificate_auto', $subject, $result);

        try {
            $pdo->prepare("UPDATE certificates SET status = 'Approved & Issued' WHERE id = :id")->execute(['id' => $certificateId]);
        } catch (Throwable $ignored) {
        }
        portalNotifyResident($pdo, (int)$cert['resident_id'], 'Your signed certificate is ready to download.', 'ResidentDashboard.php?tab=certificates');

        if (!empty($result['success'])) {
            portalCertificateWorkflowLog($pdo, $certificateId, 'Certificate auto-sent', 'All required signatures were completed, so the signed certificate was automatically emailed and released.');
            return true;
        }

        portalCertificateWorkflowLog($pdo, $certificateId, 'Certificate released; email failed', 'The signed certificate was released to the resident portal, but email delivery failed: ' . (string)($result['error'] ?? 'Email delivery failed.'));
        return false;
    }

    function portalRenderCertificateSignatureApprovalPage($approval, $token, $decision, $error = '')
    {
        $title = $decision === 'reject' ? 'Reject certificate signature request' : 'Approve certificate e-signature';
        $actionText = $decision === 'reject' ? 'Reject Request' : 'Approve & Apply Signature';
        $buttonClass = $decision === 'reject' ? '#be123c' : '#047857';
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $safeDecision = htmlspecialchars($decision, ENT_QUOTES, 'UTF-8');
        $safeType = htmlspecialchars((string)($approval['approver_type'] ?? 'Approver'), ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars((string)($approval['approver_email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safeError = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>body{margin:0;background:#eef2f7;font-family:Arial,sans-serif;color:#0f172a}.wrap{min-height:100vh;display:grid;place-items:center;padding:24px}.panel{width:min(560px,100%);background:#fff;border:1px solid #dbe3ef;border-radius:18px;box-shadow:0 20px 60px rgba(15,23,42,.12);padding:28px}.eyebrow{font-size:11px;font-weight:800;color:#047857;text-transform:uppercase;letter-spacing:.08em}h1{margin:8px 0 8px;font-size:24px}p{font-size:14px;line-height:1.55;color:#475569}.meta{border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:12px;margin:16px 0}.field{margin-top:16px}.field label{display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:6px;text-transform:uppercase}.field input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:12px;background:#fff}.error{border:1px solid #fecdd3;background:#fff1f2;color:#9f1239;border-radius:12px;padding:11px;font-size:13px;font-weight:700}.actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}.btn{border:0;border-radius:12px;padding:12px 16px;color:#fff;font-weight:900;cursor:pointer}.back{display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:12px;padding:11px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:800}</style></head><body><main class="wrap"><section class="panel">'
            . '<div class="eyebrow">ResiHUnity RHU Certificate Workflow</div><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>This secure page confirms whether RHU may use your signature on the certificate. If approving, upload a clear picture of your signature and it will be placed automatically on the certificate.</p>'
            . '<div class="meta"><p><strong>Approver:</strong> ' . $safeType . '<br><strong>Email:</strong> ' . $safeEmail . '<br><strong>Request ID:</strong> #' . (int)($approval['certificate_id'] ?? 0) . '</p></div>'
            . ($safeError !== '' ? '<div class="error">' . $safeError . '</div>' : '')
            . '<form method="post" enctype="multipart/form-data">'
            . '<input type="hidden" name="certificate_signature_approval" value="' . $safeToken . '"><input type="hidden" name="decision" value="' . $safeDecision . '">';
        if ($decision === 'approve') {
            echo '<div class="field"><label for="signature_image">Signature Picture</label><input id="signature_image" name="signature_image" type="file" accept="image/png,image/jpeg,image/webp" required></div>'
                . '<p>Tip: use a clean white background. PNG/JPG/WEBP, up to 2MB.</p>';
        }
        echo '<div class="actions"><button class="btn" style="background:' . $buttonClass . '" type="submit">' . htmlspecialchars($actionText, ENT_QUOTES, 'UTF-8') . '</button>'
            . '<a class="back" href="portal.php">Cancel</a></div></form></section></main></body></html>';
        exit;
    }

    function portalRecordCertificateEmail($pdo, $certificateId, $email, $type, $subject, $result)
    {
        portalCertificateWorkflowLog(
            $pdo,
            $certificateId,
            'Email: ' . $type,
            $subject . ' | Recipient: ' . $email . ' | Status: ' . (!empty($result['success']) ? 'sent' : 'not sent')
        );
    }

    function portalHandleCertificateSignatureApproval($pdo)
    {
        if (!$pdo) return;
        $token = trim((string)($_POST['certificate_signature_approval'] ?? $_GET['certificate_signature_approval'] ?? ''));
        if ($token === '') return;
        $decision = trim((string)($_POST['decision'] ?? $_GET['decision'] ?? 'approve'));
        $decision = $decision === 'reject' ? 'reject' : 'approve';
        $approval = portalFindCertificateApprovalByToken($pdo, $token);
        if (!$approval) {
            portalRenderCertificateSignatureApprovalPage(['approver_type' => 'Approver', 'approver_email' => ''], $token, $decision, 'This approval link is invalid or already unavailable.');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            portalRenderCertificateSignatureApprovalPage($approval, $token, $decision);
        }

        $workflow = $approval['workflow'];
        $role = $approval['role'];
        if (($workflow[$role]['status'] ?? '') !== 'Pending') {
            portalRenderCertificateSignatureApprovalPage($approval, $token, $decision, 'This signature request has already been processed.');
        }

        if ($decision === 'reject') {
            $workflow[$role]['status'] = 'Rejected';
            $workflow[$role]['rejected_at'] = date('Y-m-d H:i:s');
            $workflow['logs'][] = ['action' => 'Signature rejected', 'notes' => ucfirst($role) . ' rejected the e-signature request.', 'at' => date('Y-m-d H:i:s')];
            portalSaveCertificateWorkflow($pdo, (int)$approval['certificate_id'], $workflow);
            portalRefreshCertificateWorkflowStatus($pdo, (int)$approval['certificate_id']);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html><body style="font-family:Arial;padding:32px"><h2>Signature request rejected.</h2><p>The RHU certificate workflow has been updated.</p></body></html>';
            exit;
        }

        try {
            $signaturePath = portalCertificateSignatureUploadPath($_FILES['signature_image'] ?? []);
            if ($signaturePath === '') {
                throw new RuntimeException('Please upload your signature image.');
            }
            $workflow[$role]['status'] = 'Approved';
            $workflow[$role]['signature_path'] = $signaturePath;
            $workflow[$role]['approved_at'] = date('Y-m-d H:i:s');
            $workflow['logs'][] = ['action' => 'Signature approved', 'notes' => ucfirst($role) . ' approved and uploaded signature.', 'at' => date('Y-m-d H:i:s')];
            portalSaveCertificateWorkflow($pdo, (int)$approval['certificate_id'], $workflow);
            $status = portalRefreshCertificateWorkflowStatus($pdo, (int)$approval['certificate_id']);
            if ($status === 'Signed') {
                portalAutoSendSignedCertificate($pdo, (int)$approval['certificate_id']);
            }
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html><body style="font-family:Arial;padding:32px"><h2>Signature approved.</h2><p>Your signature has been applied to the certificate.</p></body></html>';
            exit;
        } catch (Throwable $e) {
            portalRenderCertificateSignatureApprovalPage($approval, $token, $decision, $e->getMessage());
        }
    }

    function portalHandleNotificationApi($pdo)
    {
        if (!$pdo || empty($_GET['api'])) return;

        $api = $_GET['api'];
        if (!in_array($api, ['get_notifications', 'mark_read', 'delete_notifications'], true)) return;

        header('Content-Type: application/json');
        try {

        $uid = (int)($_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? $_SESSION['resident_login']['id'] ?? $_SESSION['bhw_user']['id'] ?? 0);
        $role = strtoupper((string)($_SESSION['user']['role'] ?? $_SESSION['rhu_staff_login']['staff_type'] ?? ''));
        if (empty($role) && !empty($_SESSION['resident_login'])) $role = 'RESIDENT';
        if (empty($role) && !empty($_SESSION['bhw_user'])) $role = 'BHW';
        if (empty($role) && !empty($_SESSION['rhu_admin_authenticated'])) $role = 'RHU_ADMIN';
        portalEnsureNotificationTables($pdo);
        $roleAliases = [$role];
        if (str_contains($role, 'SANITARY') || str_contains($role, 'INSPECTOR')) {
            $roleAliases[] = 'SANITARY';
            $roleAliases[] = 'SANITARY_INSPECTOR';
        }
        if (str_contains($role, 'MEDTECH') || str_contains($role, 'TECHNOLOGIST')) {
            $roleAliases[] = 'MEDTECH';
            $roleAliases[] = 'MEDICAL_TECHNOLOGIST';
        }
        if (str_contains($role, 'MIDWIFE')) {
            $roleAliases[] = 'MIDWIFE';
        }
        if (str_contains($role, 'NURSE')) {
            $roleAliases[] = 'NURSE';
        }
        if (str_contains($role, 'DENTAL') || str_contains($role, 'DENTIST')) {
            $roleAliases[] = 'DENTAL';
            $roleAliases[] = 'DENTIST';
        }
        if (str_contains($role, 'PHYSICIAN') || str_contains($role, 'DOCTOR') || str_contains($role, 'RHU_ADMIN')) {
            $roleAliases[] = 'PHYSICIAN';
            $roleAliases[] = 'DOCTOR';
            $roleAliases[] = 'RHU_ADMIN';
            $roleAliases[] = 'ADMIN_STAFF';
        }
        if (!empty($_SESSION['rhu_admin_authenticated'])) {
            $roleAliases = ['RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF'];
        }
        if (in_array($role, ['SECRETARY', 'ADMIN_STAFF'], true)) {
            $roleAliases = ['SECRETARY', 'ADMIN_STAFF', 'RHU_ADMIN'];
        }
        $roleAliases = array_values(array_unique(array_filter($roleAliases)));
        if (!$roleAliases) {
            $roleAliases[] = '__NO_ROLE__';
        }
        $rolePlaceholderSql = implode(',', array_fill(0, count($roleAliases), '?'));

        if ($api === 'get_notifications') {
            $stmt = $pdo->prepare("
                SELECT id, message, link_url, is_read, created_at
                   FROM portal_notifications
                   WHERE (user_id = ? AND user_id > 0)
                   OR audience_role IN ({$rolePlaceholderSql})
                ORDER BY id DESC LIMIT 30
            ");
            $stmt->execute(array_merge([$uid], $roleAliases));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $uniqueNotifs = [];
            foreach ($rows as $notification) {
                $messageKey = preg_replace('/\s+/', ' ', trim((string)($notification['message'] ?? '')));
                $linkKey = trim((string)($notification['link_url'] ?? ''));
                $dedupeKey = strtolower($messageKey . '|' . $linkKey);
                if ($dedupeKey === '|') {
                    $dedupeKey = 'notification-' . (int)($notification['id'] ?? 0);
                }
                if (!isset($uniqueNotifs[$dedupeKey])) {
                    $timestamp = strtotime((string)($notification['created_at'] ?? ''));
                    $notification['created_at_iso'] = $timestamp ? date(DATE_ATOM, $timestamp) : '';
                    $notification['created_at_display'] = $timestamp ? date('M j, Y, g:i A', $timestamp) : (string)($notification['created_at'] ?? '');
                    $uniqueNotifs[$dedupeKey] = $notification;
                    continue;
                }
                if (empty($notification['is_read'])) {
                    $uniqueNotifs[$dedupeKey]['is_read'] = 0;
                }
            }
            $notifs = array_values($uniqueNotifs);

            $unread = 0;
            foreach ($notifs as $n) {
                if (empty($n['is_read'])) $unread++;
            }

            echo json_encode(['success' => true, 'notifications' => $notifs, 'unreadCount' => $unread]);
            exit;
        }

        if ($api === 'mark_read') {
            $notifId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($notifId === 0) {
                $stmt = $pdo->prepare("UPDATE portal_notifications SET is_read = 1 WHERE (user_id = ? AND user_id > 0) OR audience_role IN ({$rolePlaceholderSql})");
                $stmt->execute(array_merge([$uid], $roleAliases));
            } else {
                $stmt = $pdo->prepare("UPDATE portal_notifications SET is_read = 1 WHERE id = :id");
                $stmt->execute(['id' => $notifId]);
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if ($api === 'delete_notifications') {
            $rawInput = file_get_contents('php://input');
            $inputData = json_decode($rawInput, true);

            $ids = [];
            if (!empty($_POST['ids'])) {
                $ids = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', $_POST['ids']);
            } elseif (!empty($inputData['ids']) && is_array($inputData['ids'])) {
                $ids = $inputData['ids'];
            } elseif (!empty($_GET['id'])) {
                $ids = [(int)$_GET['id']];
            }

            $cleanIds = array_map('intval', array_filter($ids));

            if (!empty($cleanIds)) {
                $inQuery = implode(',', $cleanIds);
                $stmt = $pdo->prepare("DELETE FROM portal_notifications WHERE id IN ($inQuery)");
                $stmt->execute();
            }
            echo json_encode(['success' => true]);
            exit;
        }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    function portalRenderNotificationButton() {
        $securityMenu = '';
        if (!empty($_SESSION['rhu_staff_login']) || !empty($_SESSION['rhu_admin_authenticated'])) {
            $returnUrl = rawurlencode($_SERVER['REQUEST_URI'] ?? '');
            $securityMenu = '<details class="staff-security-menu" style="position:relative;display:inline-block;margin-right:6px">
                <summary title="Account security" aria-label="Account security" style="cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid #b8d7cf;border-radius:50%;background:#f0fdf8;color:#14594d">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>
                </summary>
                <div style="position:absolute;right:0;top:44px;z-index:1000;min-width:190px;padding:8px;background:#fff;border:1px solid #dce6e2;border-radius:12px;box-shadow:0 8px 28px #12312d26;color:#18332e">
                    <p style="margin:4px 10px 8px;font-size:11px;font-weight:700;color:#64748b">ACCOUNT SECURITY</p>
                    <a href="StaffLock.php?settings=1&amp;return=' . $returnUrl . '" style="display:block;padding:10px;color:#14594d;font-size:13px;text-decoration:none">Unlock PIN</a>
                    <a href="StaffLock.php?return=' . $returnUrl . '" style="display:block;padding:10px;color:#14594d;font-size:13px;text-decoration:none">Lock now</a>
                </div>
            </details>';
        }
        return $securityMenu . '
        <button id="notification-bell-btn" onclick="toggleNotificationPanel(event)" type="button" class="relative p-2 rounded-full text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-colors" title="Notifications">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
          <span id="notif-badge-count" class="hidden absolute top-1 right-1 min-w-[18px] h-[18px] px-1 text-center leading-[14px] text-[10px] font-extrabold text-white bg-rose-500 rounded-full border-2 border-white shadow-sm">0</span>
        </button>';
    }

    function portalRenderNotificationPanel() {
        return <<<'HTML'
  <style>
    #global-notification-panel, #global-notification-panel * { color: #12312d !important; box-sizing: border-box; }
    #global-notification-panel {
      border-radius: 18px !important;
      border: 1px solid #b9e1d7 !important;
      background: #f8fffc !important;
      box-shadow: 0 24px 60px rgba(15, 23, 42, .18), 0 2px 10px rgba(13, 148, 136, .12) !important;
    }
    #global-notification-panel .notif-panel-header {
      background: linear-gradient(135deg, #0f332f 0%, #155e55 100%) !important;
      border-bottom: 0 !important;
    }
    #global-notification-panel .notif-panel-header, #global-notification-panel .notif-panel-header * { color: #ffffff !important; }
    #global-notification-panel .notif-panel-title-icon {
      display: grid;
      place-items: center;
      width: 34px;
      height: 34px;
      border-radius: 12px;
      background: rgba(210, 250, 239, .16);
      border: 1px solid rgba(210, 250, 239, .24);
    }
    #global-notification-panel #notif-panel-count-badge {
      color: #0d4f47 !important;
      background: #d8f7e8 !important;
      border: 1px solid rgba(45, 212, 191, .55) !important;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
    }
    #global-notification-panel .notif-panel-action {
      min-height: 30px;
      border-radius: 999px;
      border: 1px solid rgba(216, 247, 232, .7);
      background: rgba(255,255,255,.12);
      padding: 0 10px;
    }
    #global-notification-panel .notif-panel-action:hover { background: rgba(255,255,255,.2); }
    #global-notification-panel .notif-toolbar {
      background: #f2fbf7 !important;
      border-bottom: 1px solid #d6eee6 !important;
    }
    #global-notification-panel input[type="checkbox"] {
      accent-color: #0f766e;
      width: 15px;
      height: 15px;
    }
    #global-notification-panel .notif-item-unread {
      background: linear-gradient(135deg, #e7fbf4 0%, #f4fffb 100%) !important;
      border: 1px solid #9de1d1 !important;
      box-shadow: 0 8px 20px rgba(15, 118, 110, .08) !important;
    }
    #global-notification-panel .notif-item-read {
      background: #ffffff !important;
      border: 1px solid #e1eee9 !important;
    }
    #global-notification-panel .notif-card {
      border-radius: 12px;
      margin: 10px 12px;
      padding: 11px 12px !important;
    }
    #global-notification-panel .notif-card:hover {
      border-color: #5fcdbb !important;
      transform: translateY(-1px);
    }
    #global-notification-panel .notif-item-time { color: #52736c !important; }
    #global-notification-panel .notif-delete {
      width: 26px;
      height: 26px;
      border-radius: 999px;
      border: 1px solid #b9e6dd;
      background: #edfffa;
      color: #0f766e !important;
    }
    #global-notification-panel .notif-delete:hover {
      border-color: #fecdd3;
      background: #fff1f2;
      color: #be123c !important;
    }
    @media (max-width: 640px) {
      #global-notification-panel {
        top: 72px !important;
        right: 10px !important;
        width: calc(100vw - 20px) !important;
        max-width: none !important;
      }
    }
  </style>
  <div id="global-notification-panel" class="hidden fixed top-20 right-3 sm:right-8 z-[99999] w-[calc(100vw-1.5rem)] max-w-sm sm:max-w-md rounded-2xl bg-white shadow-2xl border border-slate-200/90 overflow-hidden font-sans text-slate-900" style="z-index:99999;">
    <div class="notif-panel-header flex items-center justify-between gap-3 px-4 py-4 bg-slate-900 text-white border-b border-slate-800">
      <div class="flex min-w-0 items-center gap-3">
        <span class="notif-panel-title-icon shrink-0">
          <svg class="w-5 h-5 text-teal-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        </span>
        <div class="min-w-0">
          <h4 class="font-extrabold text-sm leading-tight">Notifications</h4>
          <p class="text-[10px] font-semibold leading-tight text-teal-100/80">Recent resident and staff alerts</p>
        </div>
        <span id="notif-panel-count-badge" class="shrink-0 px-2 py-1 text-[10px] font-extrabold bg-teal-500/30 text-teal-300 rounded-full border border-teal-500/40">0 New</span>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <button type="button" onclick="markAllNotificationsRead()" class="notif-panel-action text-[11px] font-bold text-slate-300 hover:text-teal-300 transition-colors">Mark all read</button>
        <button type="button" onclick="toggleNotificationPanel(event)" class="grid h-8 w-8 place-items-center rounded-full bg-white/10 text-slate-400 hover:text-white hover:bg-white/20 transition-colors" aria-label="Close notifications">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <div class="notif-toolbar flex items-center justify-between px-4 py-3 text-xs text-slate-700">
      <label class="flex items-center gap-2 cursor-pointer select-none font-medium">
        <input type="checkbox" id="notif-select-all" onchange="toggleSelectAllNotifications(this)" class="rounded text-teal-600 focus:ring-teal-500">
        <span>Select All</span>
      </label>
      <button id="notif-delete-selected-btn" type="button" onclick="deleteSelectedNotifications()" class="hidden items-center gap-1 rounded-full bg-rose-50 px-3 py-1.5 text-rose-600 font-bold hover:bg-rose-100 hover:text-rose-700 transition-colors">
        Delete Selected (<span id="notif-selected-count">0</span>)
      </button>
    </div>
    <div id="notif-panel-list" class="max-h-80 overflow-y-auto text-xs">
      <div class="p-6 text-center text-slate-400 font-medium">Loading notifications...</div>
    </div>
  </div>

  <div id="notification-detail-modal" class="hidden fixed inset-0 z-[100000] items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="z-index:100000;">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden font-sans">
      <div class="flex items-center justify-between px-5 py-4 bg-slate-900 text-white">
        <h3 class="font-bold text-sm">Notification Details</h3>
        <button type="button" onclick="closeNotifDetailModal()" class="text-slate-400 hover:text-white transition-colors" aria-label="Close notification details">x</button>
      </div>
      <div class="p-5 space-y-4 text-xs">
        <p id="notif-detail-time" class="text-[11px] font-semibold text-teal-600">--</p>
        <div id="notif-detail-text" class="text-slate-700 text-sm leading-relaxed whitespace-pre-wrap">--</div>
        <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
          <button id="notif-detail-delete-btn" type="button" class="px-3 py-2 bg-rose-50 text-rose-600 hover:bg-rose-100 font-bold rounded-xl transition-colors">Delete</button>
          <button type="button" onclick="closeNotifDetailModal()" class="px-3 py-2 bg-slate-100 text-slate-700 hover:bg-slate-200 font-bold rounded-xl transition-colors">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    let currentNotifications = [];
    let knownNotificationIds = new Set();
    let notificationsBootstrapped = false;

    function notificationApiUrl(api) {
      const url = new URL(window.location.href);
      url.searchParams.set("api", api);
      url.searchParams.set("_", String(Date.now()));
      return url.toString();
    }

    function toggleNotificationPanel(event) {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      const panel = document.getElementById("global-notification-panel");
      if (!panel) return;
      panel.classList.toggle("hidden");
      if (!panel.classList.contains("hidden")) fetchNotifications();
    }

    async function fetchNotifications() {
      try {
        const res = await fetch(notificationApiUrl("get_notifications"), {
          credentials: "same-origin",
          cache: "no-store",
          headers: { "Accept": "application/json", "Cache-Control": "no-cache" }
        });
        if (res.status === 401 || res.redirected) {
          window.location.reload();
          return;
        }
        if (!res.ok) return;
        if (!(res.headers.get("content-type") || "").includes("application/json")) {
          throw new Error("Notification endpoint returned a non-JSON response. Check the server response in Network.");
        }
        const data = await res.json();
        if (!data.success) return;
        const nextNotifications = data.notifications || [];
        const freshUnread = nextNotifications.filter(item => {
          const id = String(item.id || "");
          return id !== "" && !knownNotificationIds.has(id) && Number(item.is_read || 0) === 0;
        });
        currentNotifications = nextNotifications;
        knownNotificationIds = new Set(nextNotifications.map(item => String(item.id || "")).filter(Boolean));
        updateNotificationUI(Number(data.unreadCount || 0));
        if (notificationsBootstrapped && freshUnread.length > 0) {
          showLiveNotificationToast(freshUnread[0], freshUnread.length);
        }
        notificationsBootstrapped = true;
      } catch (err) {
        console.error("Notification fetch failed:", err);
      }
    }

    function showLiveNotificationToast(notification, count) {
      let toast = document.getElementById("live-notification-toast");
      if (!toast) {
        toast = document.createElement("button");
        toast.type = "button";
        toast.id = "live-notification-toast";
        toast.className = "fixed right-3 top-24 z-[100001] max-w-sm rounded-2xl border border-teal-200 bg-white px-4 py-3 text-left text-sm font-semibold text-slate-800 shadow-2xl transition-all hover:border-teal-400";
        toast.onclick = event => {
          toggleNotificationPanel(event);
          toast.classList.add("hidden");
        };
        document.body.appendChild(toast);
      }
      const extra = count > 1 ? ` +${count - 1} more` : "";
      toast.innerHTML = `<span class="block text-[11px] font-black uppercase tracking-wide text-teal-600">New notification${extra}</span><span class="mt-1 block">${escapeHtml(notification.message || "You have a new notification.")}</span>`;
      toast.classList.remove("hidden");
      window.clearTimeout(toast._hideTimer);
      toast._hideTimer = window.setTimeout(() => toast.classList.add("hidden"), 6000);
    }

    function updateNotificationUI(unreadCount) {
      const badge = document.getElementById("notif-badge-count");
      const panelBadge = document.getElementById("notif-panel-count-badge");
      if (badge) {
        badge.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
        badge.classList.toggle("hidden", unreadCount <= 0);
      }
      if (panelBadge) panelBadge.textContent = `${unreadCount} New`;
      renderNotificationList();
    }

    function renderNotificationList() {
      const list = document.getElementById("notif-panel-list");
      if (!list) return;
      if (!currentNotifications.length) {
        list.innerHTML = '<div class="p-6 text-center text-slate-400 font-medium">No notifications yet.</div>';
        updateSelectedNotifCount();
        return;
      }
      list.innerHTML = currentNotifications.map(n => {
        const id = Number(n.id || 0);
        const isRead = Number(n.is_read || 0) === 1;
        const created = n.created_at_display || n.created_at || "";
        return `
          <div class="notif-card ${isRead ? "notif-item-read" : "notif-item-unread font-semibold"} transition-all flex items-start gap-3">
            <input type="checkbox" class="notif-item-checkbox mt-1 rounded text-teal-600 focus:ring-teal-500" value="${id}" onchange="updateSelectedNotifCount()" aria-label="Select notification">
            <button type="button" class="flex-1 text-left" onclick="openNotifDetail(${id})">
              <p class="text-slate-900 leading-relaxed">${escapeHtml(n.message || "")}</p>
              <span class="notif-item-time text-[10px] mt-2 block font-semibold">${escapeHtml(created)}</span>
            </button>
            <button type="button" onclick="deleteSingleNotification(${id}, event)" class="notif-delete grid shrink-0 place-items-center text-sm font-bold transition-colors" title="Delete" aria-label="Delete notification">x</button>
          </div>`;
      }).join("");
      updateSelectedNotifCount();
    }

    function escapeHtml(text) {
      return String(text || "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#039;" }[c]));
    }

    function toggleSelectAllNotifications(masterCb) {
      document.querySelectorAll(".notif-item-checkbox").forEach(cb => cb.checked = masterCb.checked);
      updateSelectedNotifCount();
    }

    function updateSelectedNotifCount() {
      const count = document.querySelectorAll(".notif-item-checkbox:checked").length;
      const countSpan = document.getElementById("notif-selected-count");
      const delBtn = document.getElementById("notif-delete-selected-btn");
      if (countSpan) countSpan.textContent = String(count);
      if (delBtn) {
        delBtn.classList.toggle("hidden", count <= 0);
        delBtn.classList.toggle("flex", count > 0);
      }
    }

    async function markAllNotificationsRead() {
      await fetch(notificationApiUrl("mark_read"), { method: "POST", credentials: "same-origin" });
      fetchNotifications();
    }

    function openNotifDetail(notifId) {
      const notif = currentNotifications.find(n => Number(n.id) === Number(notifId));
      if (!notif) return;
      const modal = document.getElementById("notification-detail-modal");
      const timeEl = document.getElementById("notif-detail-time");
      const textEl = document.getElementById("notif-detail-text");
      const delBtn = document.getElementById("notif-detail-delete-btn");
      if (timeEl) timeEl.textContent = notif.created_at ? new Date(notif.created_at).toLocaleString() : "";
      if (textEl) textEl.textContent = notif.message || "";
      if (delBtn) delBtn.onclick = async () => {
        await deleteSingleNotification(notifId);
        closeNotifDetailModal();
      };
      if (modal) {
        modal.classList.remove("hidden");
        modal.classList.add("flex");
      }
      fetch(notificationApiUrl("mark_read") + "&id=" + encodeURIComponent(notifId), { method: "POST", credentials: "same-origin" }).then(fetchNotifications);
    }

    function closeNotifDetailModal() {
      const modal = document.getElementById("notification-detail-modal");
      if (modal) {
        modal.classList.add("hidden");
        modal.classList.remove("flex");
      }
    }

    async function deleteSingleNotification(id, event) {
      if (event) event.stopPropagation();
      await fetch(notificationApiUrl("delete_notifications"), {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids: [Number(id)] })
      });
      fetchNotifications();
    }

    async function deleteSelectedNotifications() {
      const ids = Array.from(document.querySelectorAll(".notif-item-checkbox:checked")).map(cb => Number(cb.value)).filter(Boolean);
      if (!ids.length) return;
      await fetch(notificationApiUrl("delete_notifications"), {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids })
      });
      const master = document.getElementById("notif-select-all");
      if (master) master.checked = false;
      fetchNotifications();
    }

    document.addEventListener("click", event => {
      const panel = document.getElementById("global-notification-panel");
      const bell = document.getElementById("notification-bell-btn");
      if (!panel || panel.classList.contains("hidden")) return;
      if ((bell && bell.contains(event.target)) || panel.contains(event.target)) return;
      panel.classList.add("hidden");
    });

    document.addEventListener("DOMContentLoaded", () => {
      fetchNotifications();
      window.setInterval(fetchNotifications, 3000);
      window.addEventListener("focus", fetchNotifications);
    });
  </script>
HTML;
    }
}

if (!function_exists('portalSettings')) {
    function portalSettings($pdo) {
        if (!$pdo) return [];
        try {
            $settings = [];
            if (rhuTableExists($pdo, 'portal_settings')) {
                $rows = $pdo->query("SELECT setting_key, setting_value FROM portal_settings")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $key = trim((string)($row['setting_key'] ?? ''));
                    if ($key !== '' && !str_starts_with($key,'_private_')) {
                        $settings[$key] = (string)($row['setting_value'] ?? '');
                    }
                }
            }
            if (rhuTableExists($pdo, 'audit_logs')) {
                $rows = $pdo->query("SELECT description FROM audit_logs
                    WHERE action = 'Settings Updated' AND module_name = 'System'
                    ORDER BY id DESC LIMIT 1")->fetchColumn();
                $auditSettings = json_decode((string)$rows, true);
                if (is_array($auditSettings)) {
                    $settings = array_merge($settings, $auditSettings);
                }
            }
            foreach (array_keys($settings) as $key) if (str_starts_with($key,'_private_')) unset($settings[$key]);
            return $settings;
        } catch (PDOException $e) {
            error_log('portalSettings: ' . $e->getMessage());
            return [];
        }
    }
}

function portalSetting($settings, $key, $fallback = '') {
    return trim((string)($settings[$key] ?? '')) ?: $fallback;
}

function portalAudit($pdo, $userId, $action, $entityType = null, $entityId = null)
{
    if (!$pdo) return;
    try {
        if (rhuColumnExists($pdo, 'audit_logs', 'health_worker_id')) {
            $statement = $pdo->prepare('INSERT INTO audit_logs (health_worker_id, action, module_name, record_id, description, ip_address, user_agent, created_at) VALUES (:health_worker_id, :action, :module_name, :record_id, :description, :ip_address, :user_agent, NOW())');
            $statement->execute([
                'health_worker_id' => $userId,
                'action' => $action,
                'module_name' => $entityType,
                'record_id' => $entityId,
                'description' => $action,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } else {
            $columns = ['user_id', 'action', 'entity_type', 'entity_id', 'ip_address'];
            $values = [':user_id', ':action', ':entity_type', ':entity_id', ':ip_address'];
            if (rhuColumnExists($pdo, 'audit_logs', 'created_at')) {
                $columns[] = 'created_at';
                $values[] = 'NOW()';
            }
            $statement = $pdo->prepare('INSERT INTO audit_logs (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');
            $statement->execute([
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }
    } catch (PDOException $e) {
        error_log('portalAudit: ' . $e->getMessage());
    }
}

function portalImgUrl($url) {
    $url = trim($url);
    if ($url === '') return '';
    if (str_starts_with($url, 'data:')) return $url;
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }
    $cleanPath = portalNormalizeAssetPath($url);
    $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath);
    if (str_starts_with($cleanPath, 'uploads/') && is_file($localPath) && is_readable($localPath)) {
        $mime = function_exists('mime_content_type') ? mime_content_type($localPath) : '';
        if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
            $mime = 'image/' . (strtolower(pathinfo($localPath, PATHINFO_EXTENSION)) ?: 'jpeg');
        }
        $contents = @file_get_contents($localPath);
        if ($contents !== false) {
            return 'data:' . $mime . ';base64,' . base64_encode($contents);
        }
    }
    if (str_starts_with($cleanPath, 'uploads/')) {
        return 'https://ruralhealthunit.page.gd/' . ltrim($cleanPath, '/');
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($script, '/src/app/components/') !== false) {
        return '../../../' . $cleanPath;
    }
    return $cleanPath;
}

function portalCertificateSignatureUploadPath($file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Signature upload failed. Please choose the image again.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Signature image must be 2MB or smaller.');
    }
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $imageInfo = $tmpPath !== '' ? @getimagesize($tmpPath) : false;
    if (!$imageInfo || !in_array((int)$imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Upload a valid PNG, JPG, JPEG, or WEBP signature image.');
    }
    $extensions = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create the signature upload folder.');
    }
    $cleanedPath = portalCleanSignatureImage($tmpPath, (int)$imageInfo[2], $uploadDir);
    if ($cleanedPath !== '') {
        return 'uploads/signatures/' . basename($cleanedPath);
    }
    $filename = 'signature_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extensions[(int)$imageInfo[2]];
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpPath, $target)) {
        throw new RuntimeException('Unable to save the uploaded signature.');
    }
    return 'uploads/signatures/' . $filename;
}

function portalSaveUploadedImage($tmpPath, $originalName, $folder) {
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('No uploaded image was found.');
    }
    $imageInfo = @getimagesize($tmpPath);
    $allowedTypes = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    $detectedType = $imageInfo ? (int)$imageInfo[2] : (function_exists('exif_imagetype') ? (int)@exif_imagetype($tmpPath) : 0);
    if (!$detectedType || !in_array($detectedType, $allowedTypes, true)) {
        throw new RuntimeException('Upload a valid PNG, JPG, JPEG, WEBP, or GIF image.');
    }
    $safeFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', $folder) ?: 'portal';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extensionMap = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $extension = in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) ? ($extension === 'jpeg' ? 'jpg' : $extension) : $extensionMap[$detectedType];
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $safeFolder;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create the image upload folder.');
    }
    $filename = $safeFolder . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpPath, $target)) {
        throw new RuntimeException('Unable to save the uploaded image.');
    }
    $documentRoots = array_unique(array_filter([
        $_SERVER['DOCUMENT_ROOT'] ?? null,
        dirname(__DIR__),
        dirname(dirname(__DIR__)),
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'rhu.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'ruralhealthunit.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'rhu.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'ruralhealthunit.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
    ], static fn($root) => is_string($root) && $root !== ''));
    foreach ($documentRoots as $documentRoot) {
        $mirrorDir = $documentRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $safeFolder;
        if (realpath($mirrorDir) === realpath($uploadDir)) {
            continue;
        }
        try {
            if (!is_dir($mirrorDir)) {
                @mkdir($mirrorDir, 0775, true);
            }
            if (is_dir($mirrorDir) && is_readable($target)) {
                @copy($target, $mirrorDir . DIRECTORY_SEPARATOR . $filename);
            }
        } catch (Throwable $mirrorError) {
            error_log('portalSaveUploadedImage mirror failed for ' . $mirrorDir . ': ' . $mirrorError->getMessage());
        }
    }
    return 'uploads/' . $safeFolder . '/' . $filename;
}

function portalDeleteUploadedImage($path)
{
    $cleanPath = portalNormalizeAssetPath($path);
    if (!str_starts_with($cleanPath, 'uploads/')) {
        return;
    }
    $fullPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath));
    $uploadRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    if ($fullPath && $uploadRoot && str_starts_with($fullPath, $uploadRoot) && is_file($fullPath)) {
        @unlink($fullPath);
    }
    $documentRoots = array_unique(array_filter([
        $_SERVER['DOCUMENT_ROOT'] ?? null,
        dirname(dirname(__DIR__)),
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'rhu.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'ruralhealthunit.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'rhu.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'ruralhealthunit.page.gd' . DIRECTORY_SEPARATOR . 'htdocs',
    ], static fn($root) => is_string($root) && $root !== ''));
    foreach ($documentRoots as $documentRoot) {
        $mirrorRoot = realpath($documentRoot . DIRECTORY_SEPARATOR . 'uploads');
        $mirrorPath = realpath($documentRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath));
        if ($mirrorPath && $mirrorRoot && str_starts_with($mirrorPath, $mirrorRoot) && is_file($mirrorPath)) {
            @unlink($mirrorPath);
        }
    }
}

function portalCleanSignatureImage($tmpPath, $imageType, $uploadDir) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return '';
    $source = false;
    if ($imageType === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($tmpPath);
    } elseif ($imageType === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
        $source = @imagecreatefromjpeg($tmpPath);
    } elseif ($imageType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($tmpPath);
    }
    if (!$source) return '';

    $width = imagesx($source);
    $height = imagesy($source);
    $maxWidth = 900;
    $targetWidth = min($width, $maxWidth);
    $targetHeight = (int)round($height * ($targetWidth / max(1, $width)));
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    $cornerSamples = [
        imagecolorat($canvas, 0, 0),
        imagecolorat($canvas, max(0, $targetWidth - 1), 0),
        imagecolorat($canvas, 0, max(0, $targetHeight - 1)),
        imagecolorat($canvas, max(0, $targetWidth - 1), max(0, $targetHeight - 1)),
    ];
    $background = ['r' => 0, 'g' => 0, 'b' => 0];
    foreach ($cornerSamples as $sample) {
        $background['r'] += ($sample >> 16) & 0xFF;
        $background['g'] += ($sample >> 8) & 0xFF;
        $background['b'] += $sample & 0xFF;
    }
    $background = array_map(static function ($value) {
        return (int)round($value / 4);
    }, $background);
    $backgroundLum = (int)round(($background['r'] * 0.299) + ($background['g'] * 0.587) + ($background['b'] * 0.114));

    $minX = $targetWidth;
    $minY = $targetHeight;
    $maxX = 0;
    $maxY = 0;
    for ($y = 0; $y < $targetHeight; $y++) {
        for ($x = 0; $x < $targetWidth; $x++) {
            $rgba = imagecolorat($canvas, $x, $y);
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $brightness = max($r, $g, $b);
            $lum = (int)round(($r * 0.299) + ($g * 0.587) + ($b * 0.114));
            $darkness = max(0, $backgroundLum - $lum);
            $contrast = max($r, $g, $b) - min($r, $g, $b);
            $backgroundDistance = abs($r - $background['r']) + abs($g - $background['g']) + abs($b - $background['b']);
            $isInk = $darkness > 28 && ($backgroundDistance > 42 || $contrast > 18);
            if (!$isInk) {
                imagesetpixel($canvas, $x, $y, $transparent);
                continue;
            }
            $inkStrength = max(80, min(255, ($darkness * 4) + ($contrast * 2)));
            $alpha = max(0, min(52, 72 - (int)round($inkStrength / 4)));
            $inkLevel = max(0, 58 - (int)round($inkStrength / 6));
            $ink = imagecolorallocatealpha($canvas, $inkLevel, $inkLevel, $inkLevel, $alpha);
            imagesetpixel($canvas, $x, $y, $ink);
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }

    if ($minX <= $maxX && $minY <= $maxY) {
        $padding = 14;
        $cropX = max(0, $minX - $padding);
        $cropY = max(0, $minY - $padding);
        $cropW = min($targetWidth - $cropX, ($maxX - $minX + 1) + ($padding * 2));
        $cropH = min($targetHeight - $cropY, ($maxY - $minY + 1) + ($padding * 2));
        $cropped = imagecreatetruecolor($cropW, $cropH);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        imagefilledrectangle($cropped, 0, 0, $cropW, $cropH, $transparent);
        imagecopy($cropped, $canvas, 0, 0, $cropX, $cropY, $cropW, $cropH);
        unset($canvas);
        $canvas = $cropped;
    }

    $filename = 'signature_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.png';
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    $saved = imagepng($canvas, $target, 6);
    unset($source, $canvas);
    return $saved ? $target : '';
}

function portalSaveApprovalSignature($pdo, $approval, $signaturePath)
{
    if ($signaturePath === '' || empty($approval['certificate_id']) || empty($approval['role'])) return;
    $workflow = portalCertificateWorkflow($pdo, (int)$approval['certificate_id']);
    $role = (string)$approval['role'];
    $workflow[$role]['signature_path'] = $signaturePath;
    $workflow[$role]['status'] = 'Approved';
    $workflow[$role]['approved_at'] = date('Y-m-d H:i:s');
    portalSaveCertificateWorkflow($pdo, (int)$approval['certificate_id'], $workflow);
}

function portalEnsureDentalTables($pdo): void
{
    if (!$pdo) return;
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dental_records (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                record_type VARCHAR(40) NOT NULL,
                resident_id BIGINT UNSIGNED NULL,
                walkin_name VARCHAR(150) NULL,
                record_date DATE NOT NULL,
                title VARCHAR(200) NULL,
                tooth_numbers VARCHAR(120) NULL,
                findings TEXT NULL,
                notes TEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'Completed',
                priority VARCHAR(30) NULL,
                meta_json LONGTEXT NULL,
                performed_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type (record_type),
                INDEX idx_resident (resident_id),
                INDEX idx_date (record_date),
                INDEX idx_status (status),
                INDEX idx_type_date (record_type, record_date),
                INDEX idx_performed_by (performed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $columns = [
            'record_type' => "ALTER TABLE dental_records ADD COLUMN record_type VARCHAR(40) NOT NULL DEFAULT 'other' AFTER id",
            'resident_id' => "ALTER TABLE dental_records ADD COLUMN resident_id BIGINT UNSIGNED NULL AFTER record_type",
            'walkin_name' => "ALTER TABLE dental_records ADD COLUMN walkin_name VARCHAR(150) NULL AFTER resident_id",
            'record_date' => "ALTER TABLE dental_records ADD COLUMN record_date DATE NOT NULL AFTER walkin_name",
            'title' => "ALTER TABLE dental_records ADD COLUMN title VARCHAR(200) NULL AFTER record_date",
            'tooth_numbers' => "ALTER TABLE dental_records ADD COLUMN tooth_numbers VARCHAR(120) NULL AFTER title",
            'findings' => "ALTER TABLE dental_records ADD COLUMN findings TEXT NULL AFTER tooth_numbers",
            'notes' => "ALTER TABLE dental_records ADD COLUMN notes TEXT NULL AFTER findings",
            'status' => "ALTER TABLE dental_records ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'Completed' AFTER notes",
            'priority' => "ALTER TABLE dental_records ADD COLUMN priority VARCHAR(30) NULL AFTER status",
            'meta_json' => "ALTER TABLE dental_records ADD COLUMN meta_json LONGTEXT NULL AFTER priority",
            'performed_by' => "ALTER TABLE dental_records ADD COLUMN performed_by INT NULL AFTER meta_json",
            'created_at' => "ALTER TABLE dental_records ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER performed_by",
            'updated_at' => "ALTER TABLE dental_records ADD COLUMN updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        foreach ($columns as $column => $sql) {
            $exists = $pdo->query("SHOW COLUMNS FROM dental_records LIKE " . $pdo->quote($column))->fetchAll();
            if (empty($exists)) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log('portalEnsureDentalTables: ' . $e->getMessage());
    }
}

function portalRepairResidentId(PDO $pdo, string $login): int
{
    $login = trim($login);
    if ($login === '') return 0;

    try {
        $stmt = $pdo->prepare("SELECT id, email, username FROM residents WHERE LOWER(email) = LOWER(:login1) OR LOWER(username) = LOWER(:login2) LIMIT 1");
        $stmt->execute(['login1' => $login, 'login2' => $login]);
        $resident = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$resident) return 0;

        $currentId = (int)($resident['id'] ?? 0);
        if ($currentId > 0) {
            return $currentId;
        }

        $newId = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM residents WHERE id > 0')->fetchColumn() ?: 1);
        if ($newId <= 0) $newId = 1;

        $pdo->beginTransaction();
        $updateResident = $pdo->prepare("UPDATE residents SET id = :new_id WHERE (LOWER(email) = LOWER(:email) OR LOWER(username) = LOWER(:username)) AND id = :old_id");
        $updateResident->execute([
            'new_id' => $newId,
            'email' => (string)($resident['email'] ?? $login),
            'username' => (string)($resident['username'] ?? $login),
            'old_id' => $currentId,
        ]);

        $tableStmt = $pdo->prepare("
            SELECT TABLE_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME = 'resident_id'
              AND TABLE_NAME <> 'residents'
        ");
        $tableStmt->execute();
        foreach ($tableStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) continue;
            try {
                $pdo->prepare("UPDATE `{$table}` SET resident_id = :new_id WHERE resident_id = :old_id")
                    ->execute(['new_id' => $newId, 'old_id' => $currentId]);
            } catch (Throwable $ignored) {
            }
        }

        $pdo->commit();

        try {
            $primary = $pdo->query("SHOW INDEX FROM residents WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
            if (!$primary) {
                $pdo->exec('ALTER TABLE residents ADD PRIMARY KEY (`id`)');
            }
            $pdo->exec('ALTER TABLE residents MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        } catch (Throwable $schemaError) {
            error_log('portalRepairResidentId schema: ' . $schemaError->getMessage());
        }

        return $newId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('portalRepairResidentId: ' . $e->getMessage());
        return 0;
    }
}

function portalEnsureHealthWorkerIds(PDO $pdo): void
{
    try {
        $idColumn = $pdo->query("SHOW COLUMNS FROM health_workers LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if (!$idColumn) return;

        if (stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
            $zeroRows = $pdo->query("SELECT id, email, username FROM health_workers WHERE id <= 0 ORDER BY created_at ASC, email ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($zeroRows as $row) {
                $newId = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM health_workers WHERE id > 0')->fetchColumn() ?: 1);
                $identity = (string)(($row['email'] ?? '') ?: ($row['username'] ?? ''));
                if ($identity === '') continue;
                $update = $pdo->prepare("UPDATE health_workers SET id = :new_id WHERE id = :old_id AND COALESCE(email, username, '') = :identity LIMIT 1");
                $update->execute([
                    'new_id' => $newId,
                    'old_id' => (int)($row['id'] ?? 0),
                    'identity' => $identity,
                ]);
            }
        }

        $primary = $pdo->query("SHOW INDEX FROM health_workers WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
        if (!$primary) {
            $pdo->exec('ALTER TABLE health_workers ADD PRIMARY KEY (`id`)');
        }
        $pdo->exec('ALTER TABLE health_workers MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
    } catch (Throwable $e) {
        error_log('portalEnsureHealthWorkerIds: ' . $e->getMessage());
    }
}

function ensurePortalTables($pdo)
{
    if (!$pdo) return;
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    $runSchemaRepairs = function_exists('rhuEnv') && rhuEnv('RHU_RUN_PORTAL_SCHEMA_REPAIRS', '0') === '1';
    try {
        portalEnsureDentalTables($pdo);

        $pdo->exec("
                CREATE TABLE IF NOT EXISTS portal_settings (
                    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                    setting_value TEXT NOT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS portal_announcements (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    category VARCHAR(50) NOT NULL DEFAULT 'Health Notice',
                    content TEXT NOT NULL,
                    badge_text VARCHAR(50) NULL,
                    image_url TEXT NULL,
                    is_popup TINYINT(1) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    posted_by VARCHAR(100) NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_announcements_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS portal_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    event_date VARCHAR(50) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    venue VARCHAR(255) NOT NULL,
                    description TEXT NOT NULL,
                    image_url TEXT NULL,
                    badge_color VARCHAR(50) DEFAULT 'bg-emerald-500',
                    scheduled_date DATE NULL,
                    start_time TIME NULL,
                    capacity INT NULL,
                    status VARCHAR(50) NOT NULL DEFAULT 'Scheduled',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_events_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS barangays (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    municipality VARCHAR(100) NOT NULL DEFAULT 'Nasugbu',
                    province VARCHAR(100) NOT NULL DEFAULT 'Batangas',
                    population INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_barangay_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

        if ($runSchemaRepairs) try {
            $eventColumns = [
                'image_url' => "ALTER TABLE portal_events ADD COLUMN image_url TEXT NULL AFTER description",
                'badge_color' => "ALTER TABLE portal_events ADD COLUMN badge_color VARCHAR(50) DEFAULT 'bg-emerald-500' AFTER image_url",
                'scheduled_date' => "ALTER TABLE portal_events ADD COLUMN scheduled_date DATE NULL AFTER badge_color",
                'start_time' => "ALTER TABLE portal_events ADD COLUMN start_time TIME NULL AFTER scheduled_date",
                'capacity' => "ALTER TABLE portal_events ADD COLUMN capacity INT NULL AFTER start_time",
                'status' => "ALTER TABLE portal_events ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Scheduled' AFTER capacity",
                'is_active' => "ALTER TABLE portal_events ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status",
            ];
            foreach ($eventColumns as $column => $sql) {
                $cols = $pdo->query("SHOW COLUMNS FROM portal_events LIKE " . $pdo->quote($column))->fetchAll();
                if (empty($cols)) {
                    $pdo->exec($sql);
                }
            }
        } catch (Exception $e) {
        }

        if ($runSchemaRepairs) try {
            $announcementColumns = [
                'badge_text' => "ALTER TABLE portal_announcements ADD COLUMN badge_text VARCHAR(50) NULL AFTER content",
                'image_url' => "ALTER TABLE portal_announcements ADD COLUMN image_url TEXT NULL AFTER badge_text",
                'is_popup' => "ALTER TABLE portal_announcements ADD COLUMN is_popup TINYINT(1) NOT NULL DEFAULT 0 AFTER image_url",
                'is_active' => "ALTER TABLE portal_announcements ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_popup",
                'posted_by' => "ALTER TABLE portal_announcements ADD COLUMN posted_by VARCHAR(100) NULL AFTER is_active",
            ];
            foreach ($announcementColumns as $column => $sql) {
                $cols = $pdo->query("SHOW COLUMNS FROM portal_announcements LIKE " . $pdo->quote($column))->fetchAll();
                if (empty($cols)) {
                    $pdo->exec($sql);
                }
            }

            $countAnn = (int)$pdo->query("SELECT COUNT(*) FROM portal_announcements")->fetchColumn();
            if ($countAnn === 0) {
                $seedTitle = "Dengue & Clean Community Awareness Drive";
                $seedCategory = "Health Awareness";
                $seedContent = "Join RHU Nasugbu in keeping our barangays safe from dengue fever. Remember the 4-S strategy: Search and destroy mosquito breeding sites, Self-protection measures, Seek early consultation, and Say yes to fogging when needed.";
                $seedImg = "https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=800&q=80";
                $insSeed = $pdo->prepare("INSERT INTO portal_announcements (title, category, content, badge_text, image_url, is_popup, is_active, posted_by) VALUES (:t, :c, :cnt, 'Health Awareness', :img, 1, 1, 'MHO Admin')");
                $insSeed->execute(['t' => $seedTitle, 'c' => $seedCategory, 'cnt' => $seedContent, 'img' => $seedImg]);
            }
        } catch (Exception $e) {
        }

        try {
            $pdo->exec("
                    CREATE TABLE IF NOT EXISTS certificate_doctor_assignments (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        certificate_type_id INT NOT NULL,
                        purpose_keyword VARCHAR(150) NOT NULL DEFAULT '',
                        staff_id INT NOT NULL,
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_certificate_assignment (certificate_type_id, purpose_keyword, staff_id),
                        INDEX idx_certificate_assignment_lookup (certificate_type_id, purpose_keyword, is_active)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                    CREATE TABLE IF NOT EXISTS certificate_signature_approvals (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        certificate_id INT NOT NULL,
                        approver_type VARCHAR(30) NOT NULL,
                        user_id INT NULL,
                        staff_id INT NULL,
                        approver_email VARCHAR(255) NOT NULL,
                        token_hash CHAR(64) NOT NULL UNIQUE,
                        status VARCHAR(30) NOT NULL DEFAULT 'Pending',
                        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        responded_at DATETIME NULL,
                        expires_at DATETIME NOT NULL,
                        ip_address VARCHAR(64) NULL,
                        user_agent VARCHAR(255) NULL,
                        INDEX idx_certificate_signature_certificate (certificate_id),
                        INDEX idx_certificate_signature_status (status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                    CREATE TABLE IF NOT EXISTS certificate_email_logs (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        certificate_id INT NOT NULL,
                        recipient_email VARCHAR(255) NOT NULL,
                        email_type VARCHAR(60) NOT NULL,
                        subject VARCHAR(255) NOT NULL,
                        delivery_status VARCHAR(30) NOT NULL,
                        delivery_method VARCHAR(60) NULL,
                        error_message TEXT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_certificate_email_certificate (certificate_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                    CREATE TABLE IF NOT EXISTS certificate_workflow_logs (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        certificate_id INT NOT NULL,
                        actor_user_id INT NULL,
                        actor_staff_id INT NULL,
                        action VARCHAR(100) NOT NULL,
                        notes TEXT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_certificate_workflow_certificate (certificate_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

            $certTableType = (string)($pdo->query("SELECT TABLE_TYPE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'health_certificates' LIMIT 1")->fetchColumn() ?: '');
            if (strtoupper($certTableType) === 'BASE TABLE') {
                $certificateColumns = [
                    'workflow_status' => "ALTER TABLE health_certificates ADD COLUMN workflow_status VARCHAR(50) NOT NULL DEFAULT 'Draft' AFTER validity_status",
                    'assigned_doctor_id' => "ALTER TABLE health_certificates ADD COLUMN assigned_doctor_id INT NULL AFTER issued_by_id",
                    'admin_approver_user_id' => "ALTER TABLE health_certificates ADD COLUMN admin_approver_user_id INT NULL AFTER assigned_doctor_id",
                    'admin_signature_approved_at' => "ALTER TABLE health_certificates ADD COLUMN admin_signature_approved_at DATETIME NULL AFTER workflow_status",
                    'doctor_signature_approved_at' => "ALTER TABLE health_certificates ADD COLUMN doctor_signature_approved_at DATETIME NULL AFTER admin_signature_approved_at",
                    'final_approved_at' => "ALTER TABLE health_certificates ADD COLUMN final_approved_at DATETIME NULL AFTER doctor_signature_approved_at",
                    'sent_at' => "ALTER TABLE health_certificates ADD COLUMN sent_at DATETIME NULL AFTER final_approved_at",
                    'generated_html' => "ALTER TABLE health_certificates ADD COLUMN generated_html LONGTEXT NULL AFTER purpose",
                ];
                foreach ($certificateColumns as $column => $sql) {
                    $exists = $pdo->query("SHOW COLUMNS FROM health_certificates LIKE " . $pdo->quote($column))->fetchAll();
                    if (empty($exists)) $pdo->exec($sql);
                }
            }

            foreach (['staff', 'users'] as $signatureTable) {
                $tableType = (string)($pdo->query("SELECT TABLE_TYPE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($signatureTable) . " LIMIT 1")->fetchColumn() ?: '');
                if (strtoupper($tableType) !== 'BASE TABLE') continue;
                $exists = $pdo->query("SHOW COLUMNS FROM {$signatureTable} LIKE 'e_signature_path'")->fetchAll();
                if (empty($exists)) $pdo->exec("ALTER TABLE {$signatureTable} ADD COLUMN e_signature_path TEXT NULL");
            }
        } catch (Throwable $certificateWorkflowError) {
            error_log('ensure certificate workflow: ' . $certificateWorkflowError->getMessage());
        }
    } catch (Exception $e) {
        error_log('ensurePortalTables: ' . $e->getMessage());
    }
}

function getPortalBarangays($pdo) {
    if (!$pdo) return [];
    try {
        ensurePortalTables($pdo);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM barangays")->fetchColumn();
        if ($count === 0) {
            $defaultBgys = [
                'Aga',
                'Balaytigue',
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
                'Cogunan',
                'Dayap',
                'Kaylaway',
                'Kayrilaw',
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
                'Wawa'
            ];
            $stmt = $pdo->prepare("INSERT IGNORE INTO barangays (name, municipality, province) VALUES (:name, 'Nasugbu', 'Batangas')");
            foreach ($defaultBgys as $bgyName) {
                $stmt->execute(['name' => $bgyName]);
            }
        }

        $stmt = $pdo->query("SELECT name FROM barangays ORDER BY name ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        return $rows ?: [];
    } catch (PDOException $e) {
        error_log('getPortalBarangays: ' . $e->getMessage());
        return [];
    }
}

function getPortalAnnouncements($pdo) {
    if (!$pdo) return [];
    try {
        ensurePortalTables($pdo);
        if (!rhuTableExists($pdo, 'portal_announcements')) {
            return [];
        }
        $stmt = $pdo->query("SELECT * FROM portal_announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 10");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        error_log('getPortalAnnouncements: ' . $e->getMessage());
        return [];
    }
}

function getPortalEvents($pdo) {
    if (!$pdo) return [];
    try {
        ensurePortalTables($pdo);
        $dedupeEvents = static function (array $events): array {
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
        };
        if (!rhuTableExists($pdo, 'portal_events') && rhuTableExists($pdo, 'events')) {
            $stmt = $pdo->query("SELECT DATE_FORMAT(start_datetime, '%b %d') AS event_date, title, COALESCE(location, 'RHU Nasugbu') AS venue, COALESCE(description, '') AS description, 'bg-emerald-500' AS badge_color, '' AS image_url FROM events ORDER BY start_datetime DESC LIMIT 20");
            return $stmt ? $dedupeEvents($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }
        if (!rhuTableExists($pdo, 'portal_events')) {
            return [];
        }
        $stmt = $pdo->query("SELECT * FROM portal_events WHERE is_active = 1 ORDER BY id DESC LIMIT 20");
        return $stmt ? $dedupeEvents($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (PDOException $e) {
        error_log('getPortalEvents: ' . $e->getMessage());
        return [];
    }
}

function getPortalEventGallery($pdo) {
    if ($pdo) {
        try {
            $settings = portalSettings($pdo);
            if (!empty($settings['rhu_event_gallery'])) {
                $gallery = json_decode($settings['rhu_event_gallery'], true);
                if (is_array($gallery) && count($gallery) > 0) return $gallery;
            }
        } catch (Exception $e) {
        }
    }
    return getPortalEventGalleryDefaults();
}

function getPortalEventGalleryDefaults() {
    return [
        [
            'title' => 'Municipal Health & Blood Donation Drive',
            'category' => 'Blood Drive Mission',
            'image_url' => 'https://images.unsplash.com/photo-1615461066841-6116e61058f4?auto=format&fit=crop&w=800&q=80',
            'date' => 'Jun 2026'
        ],
        [
            'title' => 'Women\'s Free Cancer Screening Campaign',
            'category' => 'Maternal Wellness',
            'image_url' => 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=800&q=80',
            'date' => 'Jun 2026'
        ],
        [
            'title' => 'Senior Citizens Free ECG & Medical Clinic',
            'category' => 'Elderly Care',
            'image_url' => 'https://images.unsplash.com/photo-1584515979956-d9f6e5d09982?auto=format&fit=crop&w=800&q=80',
            'date' => 'Jun 2026'
        ],
        [
            'title' => 'Barangay Child Immunization (EPI) Day',
            'category' => 'Child Health',
            'image_url' => 'https://images.unsplash.com/photo-1631815588090-d4bfec5b1cdb?auto=format&fit=crop&w=800&q=80',
            'date' => 'Jul 2026'
        ]
    ];
}

if (!function_exists('getStaffSchedulesFilePath')) {
    function getStaffSchedulesFilePath() {
        $paths = [
            dirname(__DIR__) . '/../../data/staff_schedules.json',
            dirname(__DIR__) . '/../data/staff_schedules.json',
            dirname(dirname(__DIR__), 2) . '/data/staff_schedules.json',
            sys_get_temp_dir() . '/staff_schedules.json'
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        $primary = dirname(__DIR__) . '/../../data/staff_schedules.json';
        $dir = dirname($primary);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $primary;
    }
}

if (!function_exists('loadStaffSchedulesFromJson')) {
    function loadStaffSchedulesFromJson() {
        $file = getStaffSchedulesFilePath();
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return [];
    }
}

if (!function_exists('saveStaffScheduleToJson')) {
    function saveStaffScheduleToJson($staffId, $scheduleData) {
        if ($staffId <= 0) return false;

        $schedules = loadStaffSchedulesFromJson();
        $key = (string)$staffId;
        $existing = $schedules[$key] ?? [];

        $merged = array_merge($existing, $scheduleData, [
            'staff_id' => $staffId,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $schedules[$key] = $merged;

        $file = getStaffSchedulesFilePath();
        $jsonStr = json_encode($schedules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $res = @file_put_contents($file, $jsonStr) !== false;

        $secondary = dirname(__DIR__) . '/../data/staff_schedules.json';
        if (is_dir(dirname($secondary)) && $secondary !== $file) {
            @file_put_contents($secondary, $jsonStr);
        }
        return $res;
    }
}

if (!function_exists('syncStaffFromDatabaseToJson')) {
    function syncStaffFromDatabaseToJson($pdo) {
        $existingSchedules = loadStaffSchedulesFromJson();
        if (!$pdo) return $existingSchedules;

        try {
            $staffTableExists = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'staff'")->fetchColumn();
            if (!$staffTableExists) {
                return $existingSchedules;
            }

            try {
                $pdo->exec("ALTER TABLE staff ADD COLUMN work_days VARCHAR(100) DEFAULT 'Monday, Tuesday, Wednesday, Thursday, Friday'");
                $pdo->exec("ALTER TABLE staff ADD COLUMN shift_start TIME DEFAULT '08:00:00'");
                $pdo->exec("ALTER TABLE staff ADD COLUMN shift_end TIME DEFAULT '17:00:00'");
                $pdo->exec("ALTER TABLE staff ADD COLUMN is_on_duty TINYINT(1) DEFAULT 1");
            } catch (Throwable $tCols) {
            }

            $stmt = $pdo->query("
                SELECT s.id AS staff_id, s.staff_type, s.specialization,
                       COALESCE(s.work_days, 'Monday, Tuesday, Wednesday, Thursday, Friday') AS db_work_days,
                       COALESCE(s.shift_start, '08:00:00') AS db_shift_start,
                       COALESCE(s.shift_end, '17:00:00') AS db_shift_end,
                       COALESCE(s.is_on_duty, 1) AS db_is_on_duty,
                       COALESCE(u.last_name, '') AS last_name,
                       u.email, s.phone_number
                FROM staff s
                LEFT JOIN users u ON s.user_id = u.id
                ORDER BY s.id ASC
            ");

            $dbStaff = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if (!empty($dbStaff)) {
                $newSchedules = [];
                foreach ($dbStaff as $row) {
                    $sid = (int)$row['staff_id'];
                    $key = (string)$sid;
                    $existing = $existingSchedules[$key] ?? [];

                    $fName = trim($row['first_name']);
                    $lName = trim($row['last_name']);
                    $fullName = trim($fName . ' ' . $lName);
                    if ($fullName === '') {
                        $fullName = 'RHU Staff #' . $sid;
                        $fName = 'RHU';
                        $lName = 'Staff #' . $sid;
                    }

                    $sType = !empty($row['staff_type']) ? $row['staff_type'] : 'Rural Health Staff';
                    $spec = !empty($row['specialization']) ? $row['specialization'] : 'General Healthcare';

                    $newSchedules[$key] = [
                        'staff_id' => $sid,
                        'first_name' => $fName,
                        'last_name' => $lName,
                        'name' => $fullName,
                        'staff_type' => $sType,
                        'position' => $sType,
                        'specialization' => $spec,
                        'email' => $row['email'] ?? '',
                        'phone_number' => $row['phone_number'] ?? '',
                        'work_days' => $existing['work_days'] ?? $row['db_work_days'],
                        'shift_start' => $existing['shift_start'] ?? $row['db_shift_start'],
                        'shift_end' => $existing['shift_end'] ?? $row['db_shift_end'],
                        'is_on_duty' => isset($existing['is_on_duty']) ? (int)$existing['is_on_duty'] : (int)$row['db_is_on_duty'],
                        'updated_at' => $existing['updated_at'] ?? date('Y-m-d H:i:s')
                    ];
                }

                $file = getStaffSchedulesFilePath();
                $jsonStr = json_encode($newSchedules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                @file_put_contents($file, $jsonStr);
                $secondary = dirname(__DIR__) . '/../data/staff_schedules.json';
                if (is_dir(dirname($secondary)) && $secondary !== $file) {
                    @file_put_contents($secondary, $jsonStr);
                }
                return $newSchedules;
            }
        } catch (Throwable $e) {
            error_log('syncStaffFromDatabaseToJson error: ' . $e->getMessage());
        }

        return $existingSchedules;
    }
}

if (!function_exists('mergeJsonScheduleIntoStaffList')) {
    function mergeJsonScheduleIntoStaffList($staffList, $pdo = null) {
        if ($pdo) {
            $jsonSchedules = syncStaffFromDatabaseToJson($pdo);
        } else {
            $jsonSchedules = loadStaffSchedulesFromJson();
        }

        if (empty($staffList) && !empty($jsonSchedules)) {
            $formatted = [];
            foreach ($jsonSchedules as $sched) {
                $sStart = !empty($sched['shift_start']) ? date('g:i A', strtotime($sched['shift_start'])) : '8:00 AM';
                $sEnd = !empty($sched['shift_end']) ? date('g:i A', strtotime($sched['shift_end'])) : '5:00 PM';
                $formatted[] = array_merge($sched, [
                    'id' => 'ST-' . ($sched['staff_id'] ?? 1),
                    'staff_id' => (int)($sched['staff_id'] ?? 1),
                    'first_name' => $sched['first_name'] ?? 'RHU',
                    'last_name' => $sched['last_name'] ?? 'Staff',
                    'name' => $sched['name'] ?? trim(($sched['first_name'] ?? '') . ' ' . ($sched['last_name'] ?? '')),
                    'position' => $sched['position'] ?? $sched['staff_type'] ?? 'Rural Health Staff',
                    'staff_type' => $sched['staff_type'] ?? $sched['position'] ?? 'Rural Health Staff',
                    'specialization' => $sched['specialization'] ?? 'General Medicine',
                    'workDays' => $sched['work_days'] ?? 'Monday, Tuesday, Wednesday, Thursday, Friday',
                    'work_days' => $sched['work_days'] ?? 'Monday, Tuesday, Wednesday, Thursday, Friday',
                    'shiftHours' => "{$sStart} - {$sEnd}",
                    'rawShiftStart' => $sched['shift_start'] ?? '08:00:00',
                    'rawShiftEnd' => $sched['shift_end'] ?? '17:00:00',
                    'isOnDuty' => !empty($sched['is_on_duty']),
                    'is_on_duty' => !empty($sched['is_on_duty']) ? 1 : 0
                ]);
            }
            return $formatted;
        }

        if (empty($jsonSchedules)) return $staffList;

        foreach ($staffList as &$staff) {
            $sid = (int)($staff['staff_id'] ?? $staff['id'] ?? 0);
            $key = (string)$sid;
            if ($sid > 0 && isset($jsonSchedules[$key])) {
                $sched = $jsonSchedules[$key];
                if (isset($sched['work_days'])) {
                    $staff['work_days'] = $sched['work_days'];
                    $staff['workDays'] = $sched['work_days'];
                }
                if (isset($sched['shift_start'])) {
                    $staff['shift_start'] = $sched['shift_start'];
                    $staff['rawShiftStart'] = $sched['shift_start'];
                }
                if (isset($sched['shift_end'])) {
                    $staff['shift_end'] = $sched['shift_end'];
                    $staff['rawShiftEnd'] = $sched['shift_end'];
                }
                if (isset($sched['is_on_duty'])) {
                    $staff['is_on_duty'] = (int)$sched['is_on_duty'];
                    $staff['isOnDuty'] = (bool)$sched['is_on_duty'];
                }
                $sStart = !empty($staff['shift_start']) ? date('g:i A', strtotime($staff['shift_start'])) : '8:00 AM';
                $sEnd = !empty($staff['shift_end']) ? date('g:i A', strtotime($staff['shift_end'])) : '5:00 PM';
                $staff['shiftHours'] = "{$sStart} - {$sEnd}";
            }
        }
        unset($staff);
        return $staffList;
    }
}
