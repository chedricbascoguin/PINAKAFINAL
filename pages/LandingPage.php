<?php
if (is_file(dirname(__DIR__) . '/includes/error_bootstrap.php')) {
    require_once dirname(__DIR__) . '/includes/error_bootstrap.php';
}

if (isset($_GET['health']) && (string)$_GET['health'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'LandingPage health OK';
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

require_once dirname(__DIR__) . '/db.php';
$pdo = isset($pdo) ? $pdo : null;

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars(cleanDisplayText((string) $v), ENT_QUOTES, 'UTF-8');
    }
}

require_once dirname(__DIR__) . '/includes/portal.php';

if (!function_exists('landingTableExists')) {
    function landingTableExists($pdo, $table): bool {
        static $cache = [];
      if (!$pdo || $table === '' || (function_exists('rhuIsApprovedTable') && !rhuIsApprovedTable($table))) return false;
        if (!array_key_exists($table, $cache)) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
                $stmt->execute(['table_name' => $table]);
                $cache[$table] = (bool)$stmt->fetchColumn();
            } catch (Exception $e) {
                $cache[$table] = false;
            }
        }
        return $cache[$table];
    }
}

if (!function_exists('landingSaveContactMessage')) {
    function landingSaveContactMessage($pdo, array $message): bool {
        if (!$pdo) return false;
        try {
        if (!rhuTableExists($pdo, 'audit_logs')) return false;
        $stmt = $pdo->prepare("INSERT INTO audit_logs
          (action, module_name, record_id, description, ip_address, user_agent, created_at)
          VALUES ('Resident Message', 'Resident Portal', 0, :description, :ip, :ua, NOW())");
            $stmt->execute([
          'description' => trim((string)($message['subject'] ?? 'Contact inquiry')) . "\nFrom: " . trim((string)($message['name'] ?? '')) . "\nContact: " . trim((string)($message['contact'] ?? '')) . "\n\n" . trim((string)($message['message'] ?? '')),
          'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
          'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            return true;
        } catch (Exception $e) {
            error_log('LandingPage contact save: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('portalNormalizeAssetPath')) {
    function portalNormalizeAssetPath(string $path): string {
        $path = str_replace('\\', '/', trim($path));
        $marker = 'uploads/';
        $markerPosition = stripos($path, $marker);
        if ($markerPosition !== false) {
            return ltrim(substr($path, $markerPosition), '/');
        }
        return ltrim($path, '/');
    }
}

if (!function_exists('portalImgUrl')) {
    function portalImgUrl(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        if (strpos($url, 'data:') === 0) return $url;
        if (preg_match('/^https?:\/\//i', $url)) return $url;
        $cleanPath = portalNormalizeAssetPath($url);
        if (str_starts_with($cleanPath, 'uploads/')) {
            return 'https://ruralhealthunit.page.gd/' . ltrim($cleanPath, '/');
        }
        return $cleanPath;
    }
}

if (!function_exists('portalSettings')) {
    function portalSettings($pdo): array {
        if (!$pdo) return [];
        try {
            $settings = [];
            if (landingTableExists($pdo, 'portal_settings')) {
                $settings = $pdo->query('SELECT setting_key, setting_value FROM portal_settings')
                    ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            }
            if (landingTableExists($pdo, 'audit_logs')) {
                $snapshot = $pdo->query("SELECT description FROM audit_logs
                    WHERE action = 'Settings Updated' AND module_name = 'System'
                    ORDER BY id DESC LIMIT 1")->fetchColumn();
                $auditSettings = json_decode((string)$snapshot, true);
                if (is_array($auditSettings)) {
                    $settings = array_merge($settings, $auditSettings);
                }
            }
            return $settings;
        } catch (Exception $e) {
            error_log('LandingPage portalSettings: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getPortalAnnouncements')) {
    function landingColumnExists($pdo, string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                if (!landingTableExists($pdo, $table)) {
                    $cache[$key] = false;
                } else {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
                    $stmt->execute(['column_name' => $column]);
                    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }

    function landingFirstColumn($pdo, string $table, array $columns): ?string {
        foreach ($columns as $column) {
            if (landingColumnExists($pdo, $table, $column)) return $column;
        }
        return null;
    }

    function landingDateExpression($pdo, string $table): string {
        $dateColumn = landingFirstColumn($pdo, $table, ['start_datetime', 'event_datetime', 'scheduled_at', 'scheduled_date', 'event_date', 'date']);
        if (!$dateColumn) return "'TBA'";
        $quoted = "`{$dateColumn}`";
        if (in_array($dateColumn, ['start_datetime', 'event_datetime', 'scheduled_at'], true)) {
            return "DATE_FORMAT({$quoted}, '%b %d')";
        }
        return "COALESCE(DATE_FORMAT({$quoted}, '%b %d'), {$quoted})";
    }

    function landingOrderExpression($pdo, string $table): string {
        foreach (['start_datetime', 'event_datetime', 'scheduled_at', 'scheduled_date', 'event_date', 'date', 'created_at', 'id'] as $column) {
            if (landingColumnExists($pdo, $table, $column)) return "`{$column}` DESC";
        }
        return '1';
    }

    function getPortalAnnouncements($pdo): array {
      if (!$pdo) return [];
        try {
        if (landingTableExists($pdo, 'portal_announcements')) {
          $stmt = $pdo->query("SELECT * FROM portal_announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 10");
          $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
          if (!empty($rows)) return $rows;
        }

        if (landingTableExists($pdo, 'audit_logs')) {
          $stmt = $pdo->query("SELECT id, description, created_at FROM audit_logs
            WHERE action = 'Landing Announcement' AND module_name = 'Landing Page'
            ORDER BY id DESC LIMIT 10");
          $rows = [];
          foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $announcement = json_decode((string)$row['description'], true);
            if (!is_array($announcement)) continue;
            $rows[] = array_merge($announcement, ['id' => (int)$row['id'], 'created_at' => $row['created_at'], 'is_active' => 1]);
          }
          if (!empty($rows)) return $rows;
        }

        return [];
        } catch (Exception $e) {
            error_log('LandingPage announcements: ' . $e->getMessage());
            return [];
        }
    }

    function getPortalEvents($pdo): array {
        if (!$pdo) return [];
        try {
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
            if (landingTableExists($pdo, 'portal_events')) {
                $stmt = $pdo->query("SELECT * FROM portal_events WHERE is_active = 1 ORDER BY id DESC LIMIT 20");
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) return $dedupeEvents($rows);
            }

            if (landingTableExists($pdo, 'events')) {
                $titleColumn = landingFirstColumn($pdo, 'events', ['title', 'event_title', 'name', 'event_name']);
                $venueColumn = landingFirstColumn($pdo, 'events', ['venue', 'location', 'barangay', 'address']);
                $descriptionColumn = landingFirstColumn($pdo, 'events', ['description', 'details', 'remarks', 'content']);
                $imageColumn = landingFirstColumn($pdo, 'events', ['image_url', 'cover_image', 'photo_url', 'picture']);
                $statusColumn = landingFirstColumn($pdo, 'events', ['status', 'event_status']);
                $dateExpression = landingDateExpression($pdo, 'events');
                $orderExpression = landingOrderExpression($pdo, 'events');
                $where = $statusColumn ? "WHERE (`{$statusColumn}` IS NULL OR `{$statusColumn}` NOT IN ('Deleted', 'Cancelled', 'Inactive'))" : '';
                $stmt = $pdo->query(
                    "SELECT {$dateExpression} AS event_date, "
                    . ($titleColumn ? "`{$titleColumn}`" : "'RHU Health Event'") . " AS title, "
                    . ($venueColumn ? "COALESCE(`{$venueColumn}`, 'RHU Nasugbu')" : "'RHU Nasugbu'") . " AS venue, "
                    . ($descriptionColumn ? "COALESCE(`{$descriptionColumn}`, '')" : "''") . " AS description, "
                    . "'bg-emerald-500' AS badge_color, "
                    . ($imageColumn ? "COALESCE(`{$imageColumn}`, '')" : "''") . " AS image_url "
                    . "FROM events {$where} ORDER BY {$orderExpression} LIMIT 20"
                );
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) return $dedupeEvents($rows);
            }
        } catch (Exception $e) {
            error_log('LandingPage events: ' . $e->getMessage());
        }
        return [];
    }

    function getPortalEventGalleryDefaults(): array {
        return [
            ['title' => 'Municipal Health & Blood Donation Drive', 'category' => 'Blood Drive Mission', 'image_url' => 'https://images.unsplash.com/photo-1615461066841-6116e61058f4?auto=format&fit=crop&w=800&q=80', 'date' => 'Jun 2026'],
            ['title' => 'Women\'s Free Cancer Screening Campaign', 'category' => 'Maternal Wellness', 'image_url' => 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=800&q=80', 'date' => 'Jun 2026'],
            ['title' => 'Senior Citizens Free ECG & Medical Clinic', 'category' => 'Elderly Care', 'image_url' => 'https://images.unsplash.com/photo-1584515979956-d9f6e5d09982?auto=format&fit=crop&w=800&q=80', 'date' => 'Jun 2026'],
            ['title' => 'Barangay Child Immunization (EPI) Day', 'category' => 'Child Health', 'image_url' => 'https://images.unsplash.com/photo-1631815588090-d4bfec5b1cdb?auto=format&fit=crop&w=800&q=80', 'date' => 'Jul 2026'],
        ];
    }

    function getPortalEventGallery($pdo): array {
        if ($pdo) {
            try {
                $settings = portalSettings($pdo);
                if (!empty($settings['rhu_event_gallery'])) {
                    $gallery = json_decode($settings['rhu_event_gallery'], true);
                    if (is_array($gallery) && count($gallery) > 0) return $gallery;
                }
            } catch (Exception $e) {
                error_log('LandingPage gallery: ' . $e->getMessage());
            }
        }
        return getPortalEventGalleryDefaults();
    }

    function getPortalBarangays($pdo): array {
        if (!$pdo || !landingTableExists($pdo, 'barangays')) return [];
        try {
            $stmt = $pdo->query("SELECT name FROM barangays ORDER BY name ASC");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Exception $e) {
            error_log('LandingPage barangays: ' . $e->getMessage());
            return [];
        }
    }
}

function cleanDisplayText(string $value): string {
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/u', '', $value) ?? $value;
    $value = preg_replace('/\b(?:A|Ae|a|e|tm|oe|EUR|cent|deg)+\b/i', '', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
function initials(string $name): string {
    $clean = preg_replace('/^(Hon\. |Dr\. |Midwife |RN |Engr\. |Atty\. )/', '', $name);
    $parts = array_values(array_filter(explode(' ', trim($clean))));
    $first = $parts[0][0] ?? '';
    $last = isset($parts[count($parts)-1]) && count($parts) > 1 ? $parts[count($parts)-1][0] : '';
    return strtoupper($first . $last);
}

function cleanAnnouncementContent(string $content, int $limit = 220): string {
    $content = preg_replace('/\[[^\]]*\]\((https?:\/\/[^)]+)\)/i', '', $content);
    $content = preg_replace('/https?:\/\/\S+/i', '', $content);
    $content = preg_replace('/\[\d+\]/', '', $content);
    $content = trim(preg_replace('/\s+/', ' ', $content));
    if (function_exists('mb_strlen') && mb_strlen($content) > $limit) {
        return rtrim(mb_substr($content, 0, $limit - 1)) . '...';
    }
    if (!function_exists('mb_strlen') && strlen($content) > $limit) {
        return rtrim(substr($content, 0, $limit - 1)) . '...';
    }
    return $content;
}

function awarenessImageUrl(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (str_starts_with($url, 'data:')) return $url;
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    $cleanPath = portalNormalizeAssetPath($url);
    if (str_starts_with($cleanPath, 'uploads/')) {
        return 'https://ruralhealthunit.page.gd/' . ltrim($cleanPath, '/');
    }
    return $cleanPath;
}

function imageUrlWithVersion(string $url): string {
    $url = trim($url);
    if ($url === '' || str_starts_with($url, 'data:')) return $url;
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'v=' . rawurlencode(date('YmdHis'));
}

// Fetch Admin Announcements, Health Events & Photo Gallery live from database / portal settings
$dbAnnouncements = getPortalAnnouncements($pdo);
$dbEvents = getPortalEvents($pdo);
$eventGallery = getPortalEventGallery($pdo);

$topAlert = !empty($dbAnnouncements) ? $dbAnnouncements[0] : null;

$awarenessPopup = null;
if (!empty($dbAnnouncements)) {
    foreach ($dbAnnouncements as $annItem) {
        if (!empty($annItem['is_popup'])) {
            $awarenessPopup = $annItem;
            break;
        }
    }
    if (!$awarenessPopup && !empty($dbAnnouncements[0]['image_url'])) {
        $awarenessPopup = $dbAnnouncements[0];
    }
}

// Fetch all Barangays dynamically from the database `barangays` table
$dbBarangays = getPortalBarangays($pdo);

// Fetch dynamic database counts
$dbBarangayCount = 42;
$dbTotalPopulation = 48230;
$dbHealthProgramsCount = '23+';

if (!empty($pdo)) {
    try {
        if (landingTableExists($pdo, 'barangays')) {
            $bgyRes = (int) $pdo->query("SELECT COUNT(*) FROM barangays")->fetchColumn();
            if ($bgyRes > 0) $dbBarangayCount = $bgyRes;

            $popRes = (int) $pdo->query("SELECT SUM(population) FROM barangays")->fetchColumn();
            if ($popRes > 0) $dbTotalPopulation = $popRes;
        }

        $diseaseTypesCount = landingTableExists($pdo, 'disease_types') ? (int) $pdo->query("SELECT COUNT(*) FROM disease_types")->fetchColumn() : 0;
        $certTypesCount = landingTableExists($pdo, 'certificates') ? (int) $pdo->query("SELECT COUNT(DISTINCT certificate_type) FROM certificates")->fetchColumn() : 0;
        $immCount = landingTableExists($pdo, 'vaccination_records') ? (int) $pdo->query("SELECT COUNT(DISTINCT vaccine_name) FROM vaccination_records")->fetchColumn() : 0;
        $totalProg = $diseaseTypesCount + $certTypesCount + $immCount;
        if ($totalProg > 0) $dbHealthProgramsCount = $totalProg . '+';
    } catch (Exception $e) {
        error_log('LandingPage stats: ' . $e->getMessage());
    }
}

$rhu = [
    'name' => 'Nasugbu Rural Health Unit I',
    'municipality' => 'Nasugbu',
    'province' => 'Batangas',
    'address' => 'RHU Nasugbu, J.P. Laurel St, Poblacion, Nasugbu, Batangas',
    'contact' => '(043) 416-1234',
    'emergency' => '(043) 416-9999',
    'hours' => 'Mon-Fri: 8:00 AM - 5:00 PM',
    'mho' => 'Dr. Rosalinda V. Castillo',
    'population' => $dbTotalPopulation,
    'barangay_count' => $dbBarangayCount,
    'programs_count' => $dbHealthProgramsCount,
    'barangays' => !empty($dbBarangays) ? $dbBarangays : ['Aga','Balaytigue','Banilad','Barangay 1 (Pob.)','Barangay 2 (Pob.)','Barangay 3 (Pob.)','Barangay 4 (Pob.)','Bilaran','Bucana','Bulihan','Calayo','Catandaan','Dayap','Kaylaway','Looc','Lumbangan','Natipuan','Pantalan','Wawa']
];

$services = [
    [
        'id' => 'opd',
        'cat' => 'consultation',
        'label' => 'OPD Consultations',
        'schedule' => 'Mon-Fri, 8:00 AM - 5:00 PM',
        'desc' => 'General physical examinations, medical checkups, diagnosis, treatment, and referral services.',
        'color' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L5.6 15.11a2 2 0 01-1.183-1.84V5.05a2 2 0 011.183-1.84l2.386-.477a6 6 0 013.86.517l.318.158a6 6 0 003.86.517l2.387-.477A2 2 0 0120 4.73v9.698a2 2 0 01-.572 1.4z"/></svg>'
    ],
    [
        'id' => 'maternal',
        'cat' => 'maternal',
        'label' => 'Maternal & Child Care',
        'schedule' => 'Prenatal, Delivery & Postpartum',
        'desc' => 'Comprehensive maternal health monitoring, safe delivery care, and newborn wellness visits.',
        'color' => 'bg-rose-50 text-rose-600 border-rose-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>'
    ],
    [
        'id' => 'epi',
        'cat' => 'immunization',
        'label' => 'Child Immunization (EPI)',
        'schedule' => 'Wed & Fri, 8:00 AM - 12:00 NN',
        'desc' => 'Free routine vaccines for infants and young children under the Expanded Program on Immunization.',
        'color' => 'bg-indigo-50 text-indigo-600 border-indigo-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>'
    ],
    [
        'id' => 'fp',
        'cat' => 'maternal',
        'label' => 'Family Planning',
        'schedule' => 'Tuesdays, 8:00 AM - 12:00 NN',
        'desc' => 'Reproductive health counseling, contraceptives, and family planning guidance.',
        'color' => 'bg-pink-50 text-pink-600 border-pink-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>'
    ],
    [
        'id' => 'tb',
        'cat' => 'consultation',
        'label' => 'TB-DOTS Program',
        'schedule' => 'Daily Medicine Dispensing',
        'desc' => 'Directly Observed Therapy Short-Course for Tuberculosis testing, diagnosis, and free treatment.',
        'color' => 'bg-amber-50 text-amber-600 border-amber-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L5.6 15.11a2 2 0 01-1.183-1.84V5.05a2 2 0 011.183-1.84l2.386-.477a6 6 0 013.86.517l.318.158a6 6 0 003.86.517l2.387-.477A2 2 0 0120 4.73v9.698a2 2 0 01-.572 1.4z"/></svg>'
    ],
    [
        'id' => 'surveillance',
        'cat' => 'consultation',
        'label' => 'Disease Surveillance',
        'schedule' => '24/7 PIDSR Response',
        'desc' => 'Philippine Integrated Disease Surveillance & Response monitoring for community outbreak prevention.',
        'color' => 'bg-red-50 text-red-600 border-red-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>'
    ],
    [
        'id' => 'certs',
        'cat' => 'cert',
        'label' => 'Health Certificates',
        'schedule' => 'Mon-Fri, 8:00 AM - 3:00 PM',
        'desc' => 'Official medical certificates, sanitary clearances, pre-employment health checks, and birth records.',
        'color' => 'bg-teal-50 text-teal-600 border-teal-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>'
    ],
    [
        'id' => 'sanitation',
        'cat' => 'cert',
        'label' => 'Sanitation Inspection',
        'schedule' => 'Field Inspections Daily',
        'desc' => 'Environmental health audits, water safety monitoring, food establishment clearance inspections.',
        'color' => 'bg-cyan-50 text-cyan-600 border-cyan-100',
        'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>'
    ]
];

$events = [
    ['Jun 20', 'Blood Donation Drive', 'Municipal Hall Quadrangle - 8:00 AM', 'Free BP, hemoglobin screening & donor meal kit', 'bg-red-500', 'https://images.unsplash.com/photo-1615461066841-6116e61058f4?auto=format&fit=crop&w=800&q=80'],
    ['Jun 24', 'Free Cervical Cancer Screening', 'Halang Barangay Health Station - 8:00 AM', 'Free VIA testing & doctor consultation for women ages 21-65', 'bg-pink-500', 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=800&q=80'],
    ['Jun 28', 'Senior Citizens Wellness Day', 'RHU Covered Court - 8:00 AM', 'Free ECG, blood sugar test, lipid profile & maintenance meds check', 'bg-emerald-500', 'https://images.unsplash.com/photo-1584515979956-d9f6e5d09982?auto=format&fit=crop&w=800&q=80'],
    ['Jul 01', 'Nutrition Month OPT+ Drive', 'All 8 Covered Barangays', 'Operation Timbang Plus for infants & young children (0-5 yrs)', 'bg-teal-500', 'https://images.unsplash.com/photo-1631815588090-d4bfec5b1cdb?auto=format&fit=crop&w=800&q=80'],
    ['Jul 10', 'Family Planning & Wellness Fair', 'RHU Main Clinic - 8:00 AM', 'Free FP counseling, subdermal implant, & health lectures', 'bg-purple-500', 'https://images.unsplash.com/photo-1576091160550-2173dba999ef?auto=format&fit=crop&w=800&q=80'],
    ['Aug 01', 'National Child Immunization Day', 'RHU Main & Barangay Clinics', 'Free catch-up vaccines for Measles, Polio & Pentavalent', 'bg-blue-500', 'https://images.unsplash.com/photo-1631815588090-d4bfec5b1cdb?auto=format&fit=crop&w=800&q=80']
];

if (!empty($dbEvents)) {
    $formattedEvents = [];
    foreach ($dbEvents as $ev) {
        $formattedEvents[] = [
            $ev['event_date'],
            $ev['title'],
            $ev['venue'],
            $ev['description'],
            $ev['badge_color'] ?: 'bg-emerald-500',
            $ev['image_url'] ?? ''
        ];
    }
    $events = $formattedEvents;
}

// Fetch Municipal Officials from Admin Portal Settings
$portalSet = portalSettings($pdo);
$officials = [];
if (!empty($portalSet['rhu_municipal_officials'])) {
    $decodedOff = json_decode($portalSet['rhu_municipal_officials'], true);
    if (is_array($decodedOff) && count($decodedOff) > 0) {
        foreach ($decodedOff as $off) {
            if (!empty($off['name'])) {
                $officials[] = [$off['name'], $off['position'] ?? 'Municipal Official', $off['office'] ?? 'LGU Nasugbu'];
            }
        }
    }
}

// Fetch Healthcare Staff directly from the live `health_workers` table
$staff = [];
if (!empty($pdo)) {
    try {
        $candidateQueries = [
            "SELECT id, first_name, last_name, email, position_title, role, contact_number, status, created_at FROM health_workers WHERE UPPER(TRIM(COALESCE(status, ''))) IN ('ACTIVE', 'APPROVED', 'ENABLED', 'ONLINE', 'PENDING') ORDER BY id ASC LIMIT 12",
            "SELECT id, first_name, last_name, email, position_title, role, contact_number, status, created_at FROM health_workers WHERE status IS NULL OR TRIM(COALESCE(status, '')) = '' ORDER BY id ASC LIMIT 12",
            "SELECT id, first_name, last_name, email, position_title, role, contact_number, status, created_at FROM health_workers ORDER BY id ASC LIMIT 12",
        ];

        foreach ($candidateQueries as $query) {
            $sStmt = $pdo->query($query);
            $fetchedStaff = $sStmt ? $sStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if (empty($fetchedStaff)) continue;

            foreach ($fetchedStaff as $st) {
                $status = trim((string)($st['status'] ?? ''));
                $normalizedStatus = strtolower($status === '' ? 'active' : $status);
                if ($status !== '' && !in_array($normalizedStatus, ['active', 'approved', 'enabled', 'online', 'pending'], true)) {
                    continue;
                }

                $fullName = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? '')) ?: 'RHU Healthcare Professional';
                $rawPos = strtoupper((string)($st['position_title'] ?? $st['role'] ?? 'HEALTH_PERSONNEL'));
                $pos = $rawPos;
                $cat = 'doctor';

                if (strpos($rawPos, 'ADMIN') !== false || strpos($rawPos, 'MHO') !== false) { $pos = 'RHU Administrator'; $cat = 'leader'; }
                elseif (strpos($rawPos, 'PHYSICIAN') !== false || strpos($rawPos, 'DOCTOR') !== false) { $pos = 'Rural Health Physician'; $cat = 'doctor'; }
                elseif (strpos($rawPos, 'NURSE') !== false) { $pos = 'Public Health Nurse'; $cat = 'nurse'; }
                elseif (strpos($rawPos, 'MIDWIFE') !== false) { $pos = 'Rural Health Midwife'; $cat = 'midwife'; }
                elseif (strpos($rawPos, 'BHW') !== false || strpos($rawPos, 'HEALTH WORKER') !== false) { $pos = 'Barangay Health Worker'; $cat = 'bhw'; }
                elseif (strpos($rawPos, 'MEDTECH') !== false || strpos($rawPos, 'TECH') !== false) { $pos = 'Medical Technologist'; $cat = 'tech'; }
                elseif (strpos($rawPos, 'SANITARY') !== false || strpos($rawPos, 'INSPECTOR') !== false) { $pos = 'Sanitary Inspector'; $cat = 'tech'; }

                $spec = $st['role'] ?: 'Public Health & Primary Care';
                $hired = !empty($st['created_at']) ? 'Plantilla (Added ' . date('Y', strtotime($st['created_at'])) . ')' : 'Plantilla';

                $staff[] = [$fullName, $pos, $spec, $hired, $status ?: 'Active', $cat];
            }

            if (!empty($staff)) break;
        }
    } catch (Exception $e) {
        error_log("LandingPage Staff Fetch Error: " . $e->getMessage());
    }
}

if (empty($staff)) {
    $staff = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? '') === 'contact') {
    $landingMessage = [
        'name' => trim($_POST['name'] ?? ''),
        'contact' => trim($_POST['contact'] ?? ''),
        'subject' => $_POST['subject'] ?? 'General Inquiry',
        'message' => trim($_POST['message'] ?? ''),
        'at' => date('Y-m-d H:i:s')
    ];
    $savedToDatabase = landingSaveContactMessage($pdo, $landingMessage);
    if (!$savedToDatabase) {
        $_SESSION['landing_messages'][] = $landingMessage;
    }
    $_SESSION['landing_flash'] = $savedToDatabase
        ? 'Thank you! Your message has been saved to the RHU administrative database.'
        : 'Thank you! Your message has been received by the RHU administrative desk.';
    header('Location: ?contact_success=1#contact');
    exit;
}

$flash = $_SESSION['landing_flash'] ?? '';
unset($_SESSION['landing_flash']);
?>
<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ResiHUnity RHU | Nasugbu Rural Health Unit I</title>
  <link rel="manifest" href="manifest.php?v=20261003-icon-2">
  <meta name="theme-color" content="#0f766e">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/resihunity-icon-192-v2.png">
  <link rel="icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png" sizes="192x192">
  <link rel="shortcut icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  
  <!-- Stylesheets -->
  <link rel="stylesheet" href="styles/mobile-optimization.css">
  <link rel="stylesheet" href="styles/landing-responsive.css">
  
  <!-- Tailwind CSS -->
  <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
  <style>html, .font-sans { font-family: "Plus Jakarta Sans", sans-serif; }</style>
  <style>
    body {
      background-color: #f8fafc;
    }
    .luxury-panel {
      background: linear-gradient(135deg, rgba(255,255,255,0.96) 0%, rgba(240,253,244,0.92) 100%);
      border: 1px solid rgba(15, 23, 42, 0.08);
      box-shadow: 0 24px 70px rgba(15, 23, 42, 0.12);
      backdrop-filter: blur(16px);
    }
    .luxury-card {
      background: linear-gradient(145deg, rgba(255,255,255,0.98) 0%, rgba(250,252,247,0.96) 100%);
      box-shadow: 0 14px 36px rgba(15, 23, 42, 0.06);
      border: 1px solid rgba(15, 23, 42, 0.06);
    }
    .luxury-grid {
      background-image: linear-gradient(rgba(6, 78, 59, 0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(6, 78, 59, 0.04) 1px, transparent 1px);
      background-size: 32px 32px;
    }
    .awareness-overlay {
      z-index: 999999;
    }
    .awareness-dialog {
      width: min(92vw, 520px);
      max-height: min(760px, calc(100vh - 2rem));
      overflow: hidden;
      border-radius: 1.5rem;
    }
    .awareness-media {
      height: clamp(220px, 42vh, 360px);
      background: #0f172a;
    }
    .awareness-copy {
      overflow-wrap: anywhere;
      word-break: normal;
    }
    .event-list {
      display: grid;
      gap: 1.5rem;
    }
    .event-card {
      display: grid;
      grid-template-columns: minmax(260px, 42%) minmax(0, 1fr);
      min-height: 260px;
    }
    .event-media {
      position: relative;
      min-height: 260px;
      overflow: hidden;
      background: #e2e8f0;
    }
    .event-media img,
    .event-media-fallback {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .event-details {
      display: flex;
      min-width: 0;
      flex-direction: column;
      justify-content: space-between;
      padding: 1.5rem;
    }
    .event-description {
      display: -webkit-box;
      display: -webkit-box;
      -webkit-line-clamp: 4;
      line-clamp: 4;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    @media (max-width: 768px) {
      .event-card {
        grid-template-columns: 1fr;
      }
      .event-media {
        min-height: 190px;
      }
      .event-details {
        padding: 1.1rem;
      }
    }
    @media (max-width: 640px) {
      .awareness-dialog {
        width: calc(100vw - 1.5rem);
        border-radius: 1.25rem;
      }
      .awareness-media {
        height: 240px;
      }
    }
  </style>
  <script src="assets/js/pwa-install.js?v=20261003" defer></script>
</head>
<body class="landing-page bg-slate-50 text-slate-900 font-sans antialiased selection:bg-brand-500 selection:text-white">
  <div class="landing-scroll-progress" aria-hidden="true"></div>
  <nav class="landing-mobile-jump" aria-label="Mobile section shortcuts">
    <a href="#top"><span>Home</span></a>
    <a href="#services"><span>Services</span></a>
    <a href="#events"><span>Events</span></a>
    <a href="#map"><span>Map</span></a>
  </nav>

<?php if ($awarenessPopup): ?>
  <!-- AWARENESS ANNOUNCEMENT POPUP OVERLAY -->
  <div id="awareness-landing-overlay" class="awareness-overlay fixed inset-0 flex items-center justify-center bg-slate-950/80 p-3 sm:p-4 backdrop-blur-md transition-all duration-300">
    <div class="awareness-dialog relative bg-white shadow-2xl border border-slate-100 transition-all transform scale-100 animate-in fade-in zoom-in-95 duration-300">
      
      <!-- Close Button -->
      <button type="button" onclick="closeAwarenessOverlay()" title="Close Announcement" class="absolute right-3 top-3 z-30 flex h-9 w-9 items-center justify-center rounded-full bg-slate-900/70 text-white backdrop-blur-md hover:bg-rose-600 hover:rotate-90 transition-all shadow-lg border border-white/20">
        &times;
      </button>

      <!-- Badge -->
      <div class="absolute left-3 top-3 z-30">
        <span class="inline-flex max-w-[calc(100vw-6rem)] items-center gap-1.5 rounded-full bg-teal-600/90 px-3.5 py-1 text-[11px] font-extrabold uppercase tracking-wider text-white shadow-md backdrop-blur-md border border-white/20">
          <?= e( $awarenessPopup['badge_text'] ?: ($awarenessPopup['category'] ?: 'Health Advisory')) ?>
        </span>
      </div>

      <!-- Banner Image -->
      <?php if (!empty($awarenessPopup['image_url'])): ?>
        <div class="awareness-media relative w-full overflow-hidden">
          <img src="<?= e(imageUrlWithVersion(awarenessImageUrl($awarenessPopup['image_url']))) ?>" alt="<?= e($awarenessPopup['title']) ?>" class="h-full w-full object-contain" referrerpolicy="no-referrer" onerror="this.onerror=null; this.remove();">
        </div>
      <?php else: ?>
        <div class="awareness-media relative w-full p-6 text-white flex items-end">
          <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.15),transparent)]"></div>
          <p class="relative z-10 text-xs font-bold text-teal-200 uppercase tracking-widest">Public Health Bulletin</p>
        </div>
      <?php endif; ?>

      <!-- Message Content -->
      <div class="p-5 sm:p-6 space-y-3 bg-white">
        <h3 class="text-base sm:text-lg font-black text-slate-900 leading-snug">
          <?= e($awarenessPopup['title']) ?>
        </h3>
        <p class="awareness-copy text-sm text-slate-600 leading-6">
          <?= e(cleanAnnouncementContent((string)$awarenessPopup['content'])) ?>
        </p>

        <div class="pt-3 flex items-center justify-between border-t border-slate-100 gap-3">
          <span class="text-[10px] font-semibold text-slate-400">RHU Public Health Desk</span>
          <button type="button" onclick="closeAwarenessOverlay()" class="inline-flex items-center gap-1.5 rounded-xl bg-teal-700 px-4 py-2.5 text-xs font-extrabold text-white shadow-md hover:bg-teal-800 transition-all hover:scale-105">
            Proceed to Site &rarr;
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    function closeAwarenessOverlay() {
      const overlay = document.getElementById('awareness-landing-overlay');
      if (overlay) {
        overlay.classList.add('opacity-0', 'pointer-events-none');
        setTimeout(() => {
          overlay.style.display = 'none';
        }, 300);
      }
    }
  </script>
<?php endif; ?>

  <!-- Top Emergency Announcement Ticker Bar -->
  <div class="top-alert-bar bg-linear-to-r from-rose-700 via-red-600 to-rose-700 text-white py-2.5 px-4 text-xs sm:text-sm font-medium shadow-sm">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2">
      <div class="flex items-center gap-2 text-center sm:text-left">
        <span class="top-alert-badge inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-white/20 text-white font-bold text-xs uppercase tracking-wide shrink-0">
          <svg class="w-3.5 h-3.5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          <?= e($topAlert['badge_text'] ?? $topAlert['category'] ?? 'Health Advisory') ?>
        </span>
        <span class="line-clamp-1">
          <?php if ($topAlert): ?>
            <b><?= e($topAlert['title']) ?>:</b> <?= e($topAlert['content']) ?>
          <?php else: ?>
            <b>Dengue Awareness Notice:</b> Active monitoring in Brgy. Halang & Mabini. Maintain clean surroundings & eliminate stagnant water.
          <?php endif; ?>
        </span>
      </div>
      <div class="flex items-center gap-3 text-xs shrink-0 font-semibold">
        <a href="tel:0434161234" class="hover:underline">Landline: <b><?= e($rhu['contact']) ?></b></a>
        <span>|</span>
        <a href="tel:0434169999" class="hover:underline text-amber-200">Emergency: <b><?= e($rhu['emergency']) ?></b></a>
      </div>
    </div>
  </div>

  <!-- Main Navigation Header -->
  <header class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200/80 shadow-sm transition-all duration-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-20 gap-2">
        
        <!-- Logo & Branding -->
        <a href="#top" class="flex min-w-0 items-center gap-2 sm:gap-3 group">
          <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity Logo" class="h-10 w-10 sm:h-12 sm:w-12 object-cover rounded-xl shadow-md group-hover:scale-105 transition-transform" />
          <div class="min-w-0">
            <span class="block truncate text-base font-extrabold leading-none tracking-tight text-slate-900 sm:text-xl">ResiHUnity <span class="text-brand-600">RHU</span></span>
            <span class="mt-0.5 block truncate text-[10px] font-medium text-slate-500 sm:text-xs"><?= e($rhu['name']) ?></span>
          </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="hidden lg:flex items-center gap-7 font-semibold text-sm text-slate-600">
          <a href="#services" class="hover:text-brand-600 transition-colors">Services</a>
          <a href="#how-it-works" class="hover:text-brand-600 transition-colors">Portal Guide</a>
          <a href="#events" class="hover:text-brand-600 transition-colors">Health Events</a>
          <a href="#officials" class="hover:text-brand-600 transition-colors">Officials & Staff</a>
          <a href="#map" class="hover:text-brand-600 transition-colors">Health Map</a>
          <a href="#contact" class="hover:text-brand-600 transition-colors">Contact</a>
        </nav>

        <!-- Navigation Actions -->
        <div class="flex shrink-0 items-center gap-1.5 sm:gap-3">
          
          <!-- Resident Login / Register -->
          <a href="ResidentLogin.php" class="inline-flex rounded-xl border border-brand-100 bg-brand-50 px-3 py-2.5 text-xs font-extrabold text-brand-800 transition-all hover:bg-brand-100 sm:px-4 sm:text-sm">
            <span class="hidden sm:inline">Resident Sign In</span>
            <span class="sm:hidden">Sign In</span>
          </a>
          <a href="ResidentRegistration.php" class="rounded-xl bg-linear-to-r from-brand-600 to-teal-600 px-3 py-2.5 text-xs font-extrabold text-white shadow-md shadow-brand-600/20 transition-all hover:opacity-95 hover:shadow-lg sm:px-5 sm:text-sm">
            <span class="hidden sm:inline">Register Free Account</span>
            <span class="sm:hidden">Register</span>
          </a>

          <?php if (false): ?>
          <!-- Staff Portals Dropdown -->
          <div class="relative inline-block text-left" id="staff-dropdown-container">
            <button type="button" onclick="toggleStaffDropdown(event)" class="p-2.5 rounded-xl border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-colors flex items-center gap-1" title="Staff Portals">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </button>
            <div id="staff-dropdown-menu" class="hidden absolute right-0 mt-2 w-52 bg-white rounded-2xl shadow-xl border border-slate-100 p-2 z-50 animate-slide-down">
              <span class="block px-3 py-1.5 text-[11px] font-extrabold text-slate-400 uppercase tracking-wider">Staff Access</span>
              <a href="rhulandingpage.php" class="block px-3 py-2 rounded-xl text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-700">RHU Healthcare Staff</a>
              <a href="rhulandingpage.php" class="block px-3 py-2 rounded-xl text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-700">System Administrator</a>
            </div>
          </div>
          <?php endif; ?>

          <!-- Mobile Menu Button -->
          <button id="mobile-menu-btn" onclick="toggleMobileMenu()" class="lg:hidden rounded-xl border border-slate-200 p-2.5 text-slate-700 hover:bg-slate-100" aria-label="Toggle Navigation Menu">
            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>

        </div>
      </div>
    </div>

    <!-- Mobile Drawer Menu -->
    <div id="mobile-drawer" class="hidden lg:hidden border-t border-slate-200/80 bg-white/95 backdrop-blur-xl px-5 pt-4 pb-6 space-y-4 shadow-2xl">
      <nav class="flex flex-col space-y-1.5 font-bold text-slate-800 text-sm">
        <a href="#services" onclick="toggleMobileMenu()" class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-100 transition-colors">
          <span class="p-2 rounded-lg bg-emerald-50 text-emerald-600">+</span> Services Showcase
        </a>
        <a href="ResidentLogin.php" class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-100 transition-colors">
          <span class="p-2 rounded-lg bg-purple-50 text-purple-600">In</span> Resident Portal
        </a>
        <a href="#events" onclick="toggleMobileMenu()" class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-100 transition-colors">
          <span class="p-2 rounded-lg bg-blue-50 text-blue-600">Cal</span> Health Events Calendar
        </a>
        <a href="#officials" onclick="toggleMobileMenu()" class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-100 transition-colors">
          <span class="p-2 rounded-lg bg-amber-50 text-amber-600">Gov</span> Officials & Staff Directory
        </a>
        <a href="#map" onclick="toggleMobileMenu()" class="flex items-center gap-3 p-3 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200/60">
          <span class="p-2 rounded-lg bg-emerald-600 text-white">Pin</span> Health Stations Map
        </a>
        <a href="#contact" onclick="toggleMobileMenu()" class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-100 transition-colors">
          <span class="p-2 rounded-lg bg-teal-50 text-teal-600">@</span> Contact RHU Desk
        </a>
      </nav>
      <div class="pt-4 border-t border-slate-200/80 flex flex-col gap-2.5">
        <div class="grid grid-cols-2 gap-2.5">
          <a href="#services" onclick="toggleMobileMenu()" class="mobile-drawer-pill flex items-center justify-center gap-2 rounded-xl bg-slate-100 text-slate-800 border border-slate-200 font-bold text-xs py-3">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L5.6 15.11a2 2 0 01-1.183-1.84V5.05a2 2 0 011.183-1.84l2.386-.477a6 6 0 013.86.517l.318.158a6 6 0 003.86.517l2.387-.477A2 2 0 0120 4.73v9.698a2 2 0 01-.572 1.4z"/></svg>
            Services
          </a>
          <a href="tel:0434169999" class="mobile-drawer-pill mobile-drawer-emergency flex items-center justify-center gap-2 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 font-bold text-xs py-3">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h2.2a1 1 0 01.98.804l.74 3.7a1 1 0 01-.28.91l-1.23 1.23a13.3 13.3 0 006.95 6.95l1.23-1.23a1 1 0 01.91-.28l3.7.74a1 1 0 01.8.98V19a2 2 0 01-2 2h-1C10.72 21 3 13.28 3 6V5z"/></svg>
            Call 24/7
          </a>
        </div>
        <a href="ResidentLogin.php" class="w-full text-center py-3 rounded-xl bg-slate-900 text-white font-bold text-sm shadow-sm">Resident Sign In</a>
        <a href="ResidentRegistration.php" class="w-full text-center py-3 rounded-xl bg-linear-to-r from-brand-600 to-teal-600 text-white font-bold text-sm shadow-md">Register Free Account</a>
        <div class="pt-2 flex justify-around text-xs font-semibold text-slate-500 border-t border-slate-100">
          <a href="rhulandingpage.php" class="hover:text-brand-600">RHU Staff</a>
          <span>-</span>
          <a href="rhulandingpage.php" class="hover:text-brand-600">Admin Console</a>
        </div>
      </div>
    </div>
  </header>

  <!-- Hero Section -->
  <section id="top" class="relative overflow-hidden bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.25),transparent_35%),linear-gradient(135deg,#07111c_0%,#0f172a_45%,#134e4a_100%)] text-white pt-16 pb-16 sm:pt-24 sm:pb-24">
    <div class="absolute inset-0 bg-[linear-gradient(110deg,rgba(255,255,255,0.06)_0%,transparent_35%,rgba(255,255,255,0.04)_70%,transparent_100%)] pointer-events-none"></div>
    <div class="absolute -top-10 right-0 h-64 w-64 rounded-full border border-emerald-400/20 bg-emerald-500/10 blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-8 left-6 h-56 w-56 rounded-full border border-amber-300/15 bg-amber-400/10 blur-3xl pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
      <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center">
        
        <!-- Left Hero Content -->
        <div class="lg:col-span-7 space-y-6 text-center lg:text-left">
          <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/15 text-emerald-200 text-xs sm:text-sm font-semibold backdrop-blur-md">
            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
            Official Digital Health Portal - Municipality of Nasugbu
          </div>

          <h1 class="text-3xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight text-white">
            Your Health,<br class="hidden sm:inline">
            <span class="bg-linear-to-r from-emerald-300 via-teal-200 to-amber-200 bg-clip-text text-transparent">Our Priority.</span>
          </h1>

          <p class="text-base sm:text-lg text-slate-300 font-normal leading-relaxed max-w-2xl mx-auto lg:mx-0">
            Welcome to <strong class="text-white"><?= e($rhu['name']) ?></strong>. Access free medical consultations, digital immunization cards, health clearances, and community wellness programs for all residents.
          </p>

          <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3 sm:gap-4 pt-2">
            <a href="ResidentLogin.php" class="btn btn-primary w-full sm:w-auto inline-flex items-center justify-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
              Access Resident Portal
            </a>
            <a href="#services" class="btn btn-secondary w-full sm:w-auto inline-flex items-center justify-center gap-2">
              Explore RHU Services
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </a>
          </div>

          <div class="pt-6 border-t border-white/10 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-slate-300">
            <span class="flex items-center justify-center lg:justify-start gap-2 p-2 rounded-xl bg-white/5 border border-white/10">
              <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
              100% Free Consultations
            </span>
            <span class="flex items-center justify-center lg:justify-start gap-2 p-2 rounded-xl bg-white/5 border border-white/10">
              <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
              RA 10173 Privacy Compliant
            </span>
            <span class="flex items-center justify-center lg:justify-start gap-2 p-2 rounded-xl bg-white/5 border border-white/10">
              <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
              DOH Accredited Facility
            </span>
          </div>
        </div>

        <!-- Right Hero Privileges Card (Un-hidden & Stacked Responsive) -->
        <div class="lg:col-span-5">
          <div class="luxury-panel rounded-3xl p-6 sm:p-8 space-y-5">
            <div class="flex flex-col gap-3 pb-4 border-b border-slate-200/80">
              <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900">Resident Digital Features</h2>
                <p class="text-xs text-emerald-700">Convenient, paperless healthcare</p>
              </div>
              <span class="hero-registration-badge w-fit px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs font-bold">
                Free Registration
              </span>
            </div>

            <ul class="space-y-3 text-xs sm:text-sm text-slate-700">
              <li class="flex items-start gap-3">
                <span class="h-5 w-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5 font-bold">&#10003;</span>
                <span>View consultation records, diagnoses & prescribed medications online.</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="h-5 w-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5 font-bold">&#10003;</span>
                <span>Track infant & child immunization schedules with digital vaccine cards.</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="h-5 w-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5 font-bold">&#10003;</span>
                <span>Request official health, medical & sanitary clearance certificates.</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="h-5 w-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5 font-bold">&#10003;</span>
                <span>Receive automated SMS notifications for follow-up appointments.</span>
              </li>
            </ul>

            <div class="pt-2">
              <a href="ResidentRegistration.php" class="block w-full py-3.5 px-4 rounded-2xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-center text-xs sm:text-sm shadow-md transition-all">
                Create Free Resident Profile &rarr;
              </a>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- Impact Statistics Counter Bar -->
  <section class="bg-slate-900 border-y border-white/10 py-10 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6 text-center divide-y md:divide-y-0 md:divide-x divide-white/10">
        <div class="p-2 space-y-1">
          <span class="block text-3xl sm:text-4xl lg:text-5xl font-extrabold text-emerald-300 tracking-tight">
            <?= number_format($rhu['population']) ?>
          </span>
          <span class="block text-xs sm:text-sm text-slate-300 font-medium tracking-wide">
            Population Served
          </span>
        </div>
        <div class="p-2 space-y-1 pt-4 md:pt-2">
          <span class="block text-3xl sm:text-4xl lg:text-5xl font-extrabold text-emerald-300 tracking-tight">
            <?= e($rhu['barangay_count']) ?>
          </span>
          <span class="block text-xs sm:text-sm text-slate-300 font-medium tracking-wide">
            Barangays Covered
          </span>
        </div>
        <div class="p-2 space-y-1 pt-4 md:pt-2">
          <span class="block text-3xl sm:text-4xl lg:text-5xl font-extrabold text-emerald-300 tracking-tight">
            <?= e($rhu['programs_count']) ?>
          </span>
          <span class="block text-xs sm:text-sm text-slate-300 font-medium tracking-wide">
            Health Programs
          </span>
        </div>
        <div class="p-2 space-y-1 pt-4 md:pt-2">
          <span class="block text-3xl sm:text-4xl lg:text-5xl font-extrabold text-emerald-300 tracking-tight">
            100% FREE
          </span>
          <span class="block text-xs sm:text-sm text-slate-300 font-medium tracking-wide">
            Primary Care Services
          </span>
        </div>
      </div>
    </div>
  </section>

  <section id="services" class="py-16 sm:py-20 bg-white relative overflow-hidden luxury-grid">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
      
      <!-- Section Header -->
      <div class="text-center max-w-3xl mx-auto mb-10">
        <span class="px-3.5 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold uppercase tracking-wider">Comprehensive Care</span>
        <h2 class="mt-3 text-2xl sm:text-4xl font-extrabold text-slate-900">RHU Healthcare Services</h2>
        <p class="mt-3 text-xs sm:text-base text-slate-600">All primary healthcare services are provided free of charge to residents of <?= e($rhu['municipality']) ?>. Walk-in patients and registered account holders are always welcome.</p>
        <p class="landing-swipe-hint">Swipe sideways to view more services</p>
      </div>

      <!-- Service Filter Pills -->
      <div class="flex items-center justify-center gap-2 overflow-x-auto pb-4 mb-8 scrollbar-none">
        <button type="button" onclick="filterServices('all', this)" class="filter-tab active">All Services</button>
        <button type="button" onclick="filterServices('consultation', this)" class="filter-tab">OPD & Consultations</button>
        <button type="button" onclick="filterServices('maternal', this)" class="filter-tab">Maternal & Child</button>
        <button type="button" onclick="filterServices('immunization', this)" class="filter-tab">Immunization (EPI)</button>
        <button type="button" onclick="filterServices('cert', this)" class="filter-tab">Clearances & Inspection</button>
      </div>

      <!-- Services Grid -->
      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5" id="services-grid">
        <?php foreach ($services as $srv): ?>
        <div class="service-card luxury-card rounded-3xl p-6 hover:shadow-xl hover:-translate-y-1 transition-all duration-200 flex flex-col justify-between group" data-category="<?= e($srv['cat']) ?>">
          <div>
            <div class="h-12 w-12 rounded-2xl border <?= $srv['color'] ?> flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
              <?= $srv['icon'] ?>
            </div>
            <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-emerald-700 transition-colors"><?= e($srv['label']) ?></h3>
            <span class="inline-block mt-2 px-2.5 py-1 rounded-md bg-slate-100 text-slate-600 text-xs font-semibold">
              <?= e($srv['schedule']) ?>
            </span>
            <p class="mt-3 text-xs text-slate-500 leading-relaxed"><?= e($srv['desc']) ?></p>
          </div>
          <div class="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between">
            <span class="text-xs font-bold text-emerald-600">Free Service</span>
            <a href="ResidentLogin.php" class="text-xs font-bold text-slate-700 hover:text-emerald-600 flex items-center gap-1">
              Inquire &rarr;
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- CTA Banner -->
      <div class="mt-12 rounded-3xl bg-linear-to-r from-slate-900 via-emerald-900 to-teal-900 p-6 sm:p-8 text-white flex flex-col md:flex-row items-center justify-between gap-6 shadow-xl">
        <div class="space-y-1 text-center md:text-left">
          <h3 class="text-lg sm:text-2xl font-bold">Ready to access your digital health profile?</h3>
          <p class="text-xs sm:text-sm text-emerald-100">Register your account today to track consultation history, vaccine records, and requests.</p>
        </div>
        <a href="ResidentRegistration.php" class="shrink-0 px-6 py-3 rounded-xl bg-white text-emerald-950 font-extrabold text-xs sm:text-sm shadow-md hover:bg-slate-50 transition-colors">
          Create Free Account &rarr;
        </a>
      </div>

    </div>
  </section>

  <!-- How It Works Section -->
  <section id="how-it-works" class="py-16 sm:py-20 bg-slate-100/70 border-y border-slate-200/60">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-3xl mx-auto mb-14">
        <span class="px-3.5 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold uppercase tracking-wider">Simple & Accessible</span>
        <h2 class="mt-3 text-2xl sm:text-4xl font-extrabold text-slate-900">How the Resident Portal Works</h2>
        <p class="mt-3 text-xs sm:text-base text-slate-600">Accessing RHU healthcare services and digital records is quick and paperless.</p>
      </div>

      <div class="grid md:grid-cols-3 gap-6 relative">
        <div class="luxury-card rounded-3xl p-6 sm:p-8 text-center">
          <div class="h-14 w-14 rounded-2xl bg-linear-to-br from-emerald-600 to-teal-600 text-white font-extrabold text-xl flex items-center justify-center mx-auto mb-5 shadow-lg shadow-emerald-600/20">
            1
          </div>
          <h3 class="text-lg font-bold text-slate-900">Register Your Account</h3>
          <p class="mt-2 text-xs sm:text-sm text-slate-500 leading-relaxed">Sign up online using your name, contact number, email, and barangay residence details in Nasugbu.</p>
        </div>

        <div class="luxury-card rounded-3xl p-6 sm:p-8 text-center">
          <div class="h-14 w-14 rounded-2xl bg-linear-to-br from-emerald-600 to-teal-600 text-white font-extrabold text-xl flex items-center justify-center mx-auto mb-5 shadow-lg shadow-emerald-600/20">
            2
          </div>
          <h3 class="text-lg font-bold text-slate-900">RHU Verification</h3>
          <p class="mt-2 text-xs sm:text-sm text-slate-500 leading-relaxed">Once verified by RHU staff, your medical history, consultation records, and immunization cards link automatically.</p>
        </div>

        <div class="luxury-card rounded-3xl p-6 sm:p-8 text-center">
          <div class="h-14 w-14 rounded-2xl bg-linear-to-br from-emerald-600 to-teal-600 text-white font-extrabold text-xl flex items-center justify-center mx-auto mb-5 shadow-lg shadow-emerald-600/20">
            3
          </div>
          <h3 class="text-lg font-bold text-slate-900">Stay Connected & Healthy</h3>
          <p class="mt-2 text-xs sm:text-sm text-slate-500 leading-relaxed">Receive SMS follow-up alerts, request medical clearances, and view upcoming barangay health drives.</p>
        </div>
      </div>
    </div>
  </section>

  <section id="events" class="py-16 sm:py-20 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-3xl mx-auto mb-14">
        <span class="px-3.5 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold uppercase tracking-wider">Community Outreach</span>
        <h2 class="mt-3 text-2xl sm:text-4xl font-extrabold text-slate-900">Upcoming Health Events</h2>
        <p class="mt-3 text-xs sm:text-base text-slate-600">Join our upcoming free health drives, screening programs, and medical missions across <?= e($rhu['municipality']) ?>.</p>
        <p class="landing-swipe-hint">Swipe sideways to browse events</p>
      </div>

      <div class="event-list">
        <?php foreach ($events as $evt): 
          $date = $evt[0] ?? '';
          $title = $evt[1] ?? '';
          $location = $evt[2] ?? '';
          $detail = $evt[3] ?? '';
          $dotColor = $evt[4] ?? 'bg-emerald-500';
          $imgUrl = $evt[5] ?? '';
        ?>
        <div class="event-card luxury-card rounded-3xl overflow-hidden hover:shadow-xl hover:-translate-y-1 transition-all duration-200 group">
          <div class="event-media">
            <?php if (!empty($imgUrl)): ?>
              <img loading="lazy" src="<?= e(portalImgUrl($imgUrl)) ?>" alt="<?= e($title) ?>" class="card-img group-hover:scale-105 transition-transform duration-500" referrerpolicy="no-referrer" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=800&q=80';">
            <?php else: ?>
              <div class="event-media-fallback luxury-grid flex items-center justify-center bg-emerald-50 text-emerald-700">
                <i data-lucide="calendar-heart" class="h-16 w-16"></i>
              </div>
            <?php endif; ?>
            <div class="absolute top-4 left-4 px-3 py-1 rounded-full bg-slate-900/80 backdrop-blur-md font-mono text-xs font-bold text-white shadow">
              <?= e($date) ?>
            </div>
          </div>
          <div class="event-details">
            <div>
              <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                  <span class="h-2 w-2 rounded-full <?= $dotColor ?>"></span>
                  Free Entry
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500">
                  <i data-lucide="map-pin" class="h-3.5 w-3.5 text-emerald-500"></i>
                  <?= e($location) ?>
                </span>
              </div>
              <h3 class="text-xl sm:text-2xl font-extrabold text-slate-900 mb-3 group-hover:text-emerald-700 transition-colors"><?= e($title) ?></h3>
              <p class="event-description text-sm text-slate-600 leading-relaxed"><?= e($detail) ?></p>
            </div>
            <div class="card-footer mt-5">
              <span class="text-xs text-slate-400">Target: All Residents</span>
              <a href="ResidentLogin.php" class="text-xs font-bold text-emerald-700 hover:text-emerald-800 flex items-center gap-1">
                Details & Register &rarr;
              </a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Mobile progressive disclosure button -->
      <div class="mt-4 md:hidden">
        <button id="view-more-events-btn" class="view-more-events-mobile" type="button">View More Events</button>
      </div>
    </div>
  </section>

  <!-- Photo Gallery Carousel Section -->
  <?php if (!empty($eventGallery)): ?>
  <section class="py-16 bg-slate-950 text-white border-t border-slate-900 overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex flex-col md:flex-row md:items-end justify-between mb-8 gap-4">
        <div>
          <span class="px-3.5 py-1.5 rounded-full bg-white/10 border border-white/15 text-brand-300 text-xs font-bold uppercase tracking-wider">Photo Gallery</span>
          <h2 class="mt-3 text-2xl sm:text-4xl font-extrabold text-white">Health Events in Action</h2>
          <p class="mt-2 text-xs sm:text-sm text-slate-300">Moments captured from recent public health drives, medical missions, and community programs.</p>
        </div>
        <div class="flex items-center gap-3">
          <button type="button" onclick="prevGallerySlide()" class="h-10 w-10 rounded-full bg-white/10 border border-white/20 flex items-center justify-center text-white hover:bg-white/20 transition-all" aria-label="Previous Slide">
            &lsaquo;
          </button>
          <button type="button" onclick="nextGallerySlide()" class="h-10 w-10 rounded-full bg-white/10 border border-white/20 flex items-center justify-center text-white hover:bg-white/20 transition-all" aria-label="Next Slide">
            &rsaquo;
          </button>
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="relative overflow-hidden rounded-3xl border border-slate-800 shadow-2xl bg-slate-900">
        <div class="min-h-[380px] sm:min-h-[540px] relative">
          <?php foreach ($eventGallery as $idx => $gPic): ?>
          <article class="absolute inset-0 transition-opacity duration-700 ease-out <?= $idx === 0 ? 'opacity-100 z-20' : 'opacity-0 z-10' ?>" data-slide-index="<?= $idx ?>">
            <img src="<?= e(portalImgUrl($gPic['image_url'])) ?>" alt="<?= e($gPic['title']) ?>" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=1200&q=80';">
            <div class="absolute inset-0 bg-linear-to-t from-slate-950/90 via-slate-950/30 to-transparent"></div>
            <div class="absolute bottom-0 left-0 right-0 p-6 sm:p-10">
              <span class="inline-flex items-center gap-2 rounded-full bg-emerald-600/90 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-white shadow-md">
                <?= e($gPic['category'] ?? 'Event Photo') ?>
              </span>
              <h3 class="mt-3 text-xl sm:text-3xl font-extrabold text-white drop-shadow-md"><?= e($gPic['title']) ?></h3>
              <?php if (!empty($gPic['description'])): ?>
              <p class="mt-2 text-xs sm:text-sm text-slate-300 max-w-2xl leading-relaxed hidden sm:block"><?= e($gPic['description']) ?></p>
              <?php endif; ?>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <!-- Dot Indicators -->
        <div class="absolute bottom-4 left-1/2 z-30 flex -translate-x-1/2 items-center gap-2">
          <?php foreach ($eventGallery as $idx => $gPic): ?>
          <button type="button" class="slide-indicator h-2.5 w-2.5 rounded-full bg-white/40 transition-all duration-300 hover:bg-white" data-indicator-index="<?= $idx ?>" aria-label="Show slide <?= $idx + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Municipal Leadership & Staff Directory Section -->
  <section id="officials" class="py-16 sm:py-20 bg-slate-50 border-t border-slate-200/60">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      
      <!-- Section Header -->
      <div class="text-center max-w-3xl mx-auto mb-10">
        <span class="px-3.5 py-1.5 rounded-full bg-brand-50 border border-brand-200 text-brand-700 text-xs font-bold uppercase tracking-wider">Leadership & Personnel</span>
        <h2 class="mt-3 text-2xl sm:text-4xl font-extrabold text-slate-900">Municipal Officials & Health Staff</h2>
        <p class="mt-3 text-xs sm:text-base text-slate-600">In partnership with the Municipal Local Government Unit of <?= e($rhu['municipality']) ?>, dedicated to public health excellence.</p>
        <p class="landing-swipe-hint">Swipe sideways to view personnel</p>
      </div>

      <!-- Staff Filter Pills -->
      <div class="flex items-center justify-center gap-2 overflow-x-auto pb-4 mb-8 scrollbar-none">
        <button type="button" onclick="filterStaff('all', this)" class="filter-tab active">All Personnel</button>
        <button type="button" onclick="filterStaff('doctor', this)" class="filter-tab">Doctors & Nurses</button>
        <button type="button" onclick="filterStaff('midwife', this)" class="filter-tab">Midwives & BHWs</button>
        <button type="button" onclick="filterStaff('tech', this)" class="filter-tab">Medical Techs & Inspectors</button>
      </div>

      <!-- Healthcare Staff Cards -->
      <div class="mb-14">
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5" id="staff-grid">
          <?php foreach ($staff as $i => $staffRow): ?>
          <?php
            $name = $staffRow[0] ?? '';
            $position = $staffRow[1] ?? '';
            $specialty = $staffRow[2] ?? '';
            $employment = $staffRow[3] ?? '';
            $status = $staffRow[4] ?? '';
            $cat = $staffRow[5] ?? 'doctor';
          ?>
          <div class="staff-card bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm flex items-start gap-4 hover:shadow-md transition-all" data-category="<?= e($cat) ?>">
            <div class=" rounded-2xl bg-linear-to-tr from-brand-600 to-teal-600 text-white font-extrabold text-base flex items-center justify-center shrink-0 shadow-md">
              <?= e(initials($name)) ?>
            </div>
            <div class="min-w-0 flex-1">
              <h4 class="font-bold text-slate-900 text-sm sm:text-base truncate"><?= e($name) ?></h4>
              <p class="text-xs font-semibold text-brand-600 mt-0.5"><?= e($position) ?></p>
              <p class="text-xs text-slate-400 mt-1 truncate"><?= e($specialty) ?></p>
              <div class="mt-2.5 flex flex-wrap gap-1.5">
                <span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 font-semibold text-[11px]">
                  <?= e($employment) ?>
                </span>
                <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 font-semibold text-[11px]">
                  PhilHealth Accredited
                </span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Municipal Officials Grid -->
      <?php if (!empty($officials)): ?>
      <div>
        <h3 class="text-lg font-bold text-slate-900 mb-5 text-center">Municipal Government Leadership</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3.5">
          <?php foreach ($officials as $i => $officialRow): ?>
          <?php
            $name = $officialRow[0] ?? '';
            $position = $officialRow[1] ?? '';
            $office = $officialRow[2] ?? '';
          ?>
          <div class="bg-white rounded-2xl p-4 border border-slate-200/80 text-center shadow-xs hover:shadow-md transition-all">
            <div class="h-11 w-11 rounded-full <?= $i < 2 ? 'bg-teal-700' : 'bg-slate-700' ?> text-white font-extrabold text-xs flex items-center justify-center mx-auto mb-2 shadow-xs">
              <?= e(initials($name)) ?>
            </div>
            <h4 class="font-bold text-slate-900 text-xs leading-tight line-clamp-1"><?= e($name) ?></h4>
            <p class="text-[11px] font-semibold text-teal-700 mt-0.5 leading-tight"><?= e($position) ?></p>
            <p class="text-[10px] text-slate-400 mt-0.5 leading-tight"><?= e($office) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </section>

  <!-- Interactive Health Centers & Barangay Coverage Map Section -->
  <section id="map" class="py-16 sm:py-20 bg-slate-950 text-white relative overflow-hidden">
    <div class="absolute top-0 right-1/4 w-96 h-96 bg-emerald-600/15 rounded-full blur-3xl pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
      
      <!-- Section Header -->
      <div class="text-center max-w-3xl mx-auto space-y-2 mb-10">
        <span class="px-3.5 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-wider">
          Resident Health Map & Directory
        </span>
        <h2 class="text-2xl sm:text-4xl font-extrabold text-white">
          Locate Your Nearest Health Center
        </h2>
        <p class="text-slate-400 text-xs sm:text-sm leading-relaxed">
          View main RHU headquarters, barangay health stations, operating hours, and 1-tap Google Maps directions.
        </p>
      </div>

      <!-- Map & Location Selector Grid -->
      <div class="grid lg:grid-cols-12 gap-6 sm:gap-8 items-start">
        
        <!-- Left: Interactive Map -->
        <div class="lg:col-span-8 rounded-3xl border border-slate-800 bg-slate-900 p-4 sm:p-5 shadow-2xl space-y-4">
          
          <!-- Station Pills on Small Screens -->
          <div class="lg:hidden flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none text-xs">
            <button type="button" onclick="switchMapCenter('RHU Nasugbu (J.P. Laurel St)', 'https://maps.google.com/maps?q=14.0740668,120.6321294&t=&z=17&ie=UTF8&iwloc=&output=embed', 'https://maps.app.goo.gl/rcqBNWJBuYsd4YiXA', this)" class="station-card active shrink-0 px-3.5 py-2 rounded-xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 font-bold cursor-pointer">1. RHU Main</button>
            <button type="button" onclick="switchMapCenter('Barangay Wawa Health Station', 'https://maps.google.com/maps?q=Barangay%20Wawa,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Wawa+Nasugbu+Batangas', this)" class="station-card shrink-0 px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-800 text-slate-300 font-medium cursor-pointer">2. Wawa</button>
            <button type="button" onclick="switchMapCenter('Barangay Kaylaway Health Station', 'https://maps.google.com/maps?q=Barangay%20Kaylaway,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Kaylaway+Nasugbu+Batangas', this)" class="station-card shrink-0 px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-800 text-slate-300 font-medium cursor-pointer">3. Kaylaway</button>
            <button type="button" onclick="switchMapCenter('Barangay Bucana Health Station', 'https://maps.google.com/maps?q=Barangay%20Bucana,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Bucana+Nasugbu+Batangas', this)" class="station-card shrink-0 px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-800 text-slate-300 font-medium cursor-pointer">4. Bucana</button>
            <button type="button" onclick="switchMapCenter('Barangay Calayo Health Station', 'https://maps.google.com/maps?q=Barangay%20Calayo,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Calayo+Nasugbu+Batangas', this)" class="station-card shrink-0 px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-800 text-slate-300 font-medium cursor-pointer">5. Calayo</button>
          </div>

          <!-- Top Map Info Bar -->
          <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3.5 bg-slate-950 rounded-2xl border border-slate-800 gap-3">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full bg-emerald-400 animate-pulse shrink-0"></span>
              <span class="text-xs font-bold text-slate-200">Active Pin: <span id="active-center-title" class="text-emerald-400 font-extrabold">RHU Nasugbu (J.P. Laurel St)</span></span>
            </div>
            <a id="get-directions-btn" href="https://maps.app.goo.gl/rcqBNWJBuYsd4YiXA" target="_blank" rel="noopener noreferrer" class="px-4 py-2 rounded-xl bg-linear-to-r from-emerald-600 to-teal-600 text-white font-bold text-xs shadow-md hover:opacity-95 transition-all inline-flex items-center justify-center gap-1.5">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              Open in Google Maps &rarr;
            </a>
          </div>

          <!-- Embedded Google Map -->
          <div class="relative w-full h-[300px] sm:h-[440px] rounded-2xl overflow-hidden border border-slate-800 shadow-inner">
            <iframe
              id="rhu-map-iframe"
              class="w-full h-full border-0 grayscale-15 contrast-105"
              src="https://maps.google.com/maps?q=14.0740668,120.6321294&t=&z=17&ie=UTF8&iwloc=&output=embed"
              allowfullscreen
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"></iframe>
          </div>

          <!-- Legend Badges -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-center p-3 bg-slate-950 rounded-2xl border border-slate-800 text-xs">
            <div class="p-2 rounded-xl bg-slate-900 border border-slate-800">
              <span class="block text-slate-400 text-[10px]">Facility</span>
              <span class="text-white font-bold block truncate">RHU Nasugbu</span>
            </div>
            <div class="p-2 rounded-xl bg-slate-900 border border-slate-800">
              <span class="block text-slate-400 text-[10px]">GPS</span>
              <span class="text-emerald-400 font-bold block truncate">14.0740&deg;N, 120.6321&deg;E</span>
            </div>
            <div class="p-2 rounded-xl bg-slate-900 border border-slate-800">
              <span class="block text-slate-400 text-[10px]">Barangays</span>
              <span class="text-white font-bold block truncate">19 Sectors</span>
            </div>
            <div class="p-2 rounded-xl bg-slate-900 border border-slate-800">
              <span class="block text-slate-400 text-[10px]">PhilHealth</span>
              <span class="text-emerald-400 font-bold block truncate">Konsulta Desk</span>
            </div>
          </div>

        </div>

        <!-- Right: Station Selector Sidebar Directory -->
        <div class="lg:col-span-4 space-y-4">
          <div class="rounded-3xl border border-slate-800 bg-slate-900 p-5 shadow-2xl space-y-4">
            
            <div class="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 class="text-sm sm:text-base font-bold text-white flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0H7m3 0h3m-3-4H7m3 0h3"/></svg>
                Health Stations Directory
              </h3>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Directory</span>
            </div>

            <!-- Health Station List Cards -->
            <div class="space-y-3 max-h-[460px] overflow-y-auto pr-1">
              
              <!-- Station 1 -->
              <button type="button" onclick="switchMapCenter('RHU Nasugbu (J.P. Laurel St)', 'https://maps.google.com/maps?q=14.0740668,120.6321294&t=&z=17&ie=UTF8&iwloc=&output=embed', 'https://maps.app.goo.gl/rcqBNWJBuYsd4YiXA', this)" class="station-card active w-full text-left p-3.5 rounded-2xl border border-emerald-500/50 bg-emerald-500/10 transition-all space-y-1 group cursor-pointer">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-xs sm:text-sm text-white group-hover:text-emerald-300">1. RHU Nasugbu Main HQ</span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300">Main Facility</span>
                </div>
                <p class="text-xs text-slate-300">J.P. Laurel St, Poblacion, Nasugbu</p>
                <div class="flex items-center gap-3 text-[11px] text-slate-400 pt-1">
                  <span>Phone (043) 416-1234</span>
                  <span>-</span>
                  <span>Hours: Mon-Fri 8AM-5PM</span>
                </div>
              </button>

              <!-- Station 2 -->
              <button type="button" onclick="switchMapCenter('Barangay Wawa Health Station', 'https://maps.google.com/maps?q=Barangay%20Wawa,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Wawa+Nasugbu+Batangas', this)" class="station-card w-full text-left p-3.5 rounded-2xl border border-slate-800 bg-slate-950 hover:border-slate-700 transition-all space-y-1 group cursor-pointer">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-xs sm:text-sm text-white group-hover:text-emerald-300">2. Barangay Wawa Station</span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-blue-500/20 text-blue-300">Coastal Sector</span>
                </div>
                <p class="text-xs text-slate-300">Seaside Health Desk, Bgy Wawa</p>
                <div class="flex items-center gap-3 text-[11px] text-slate-400 pt-1">
                  <span>Phone 0917-889-1021</span>
                  <span>-</span>
                  <span>Hours: Mon-Sat 8AM-4PM</span>
                </div>
              </button>

              <!-- Station 3 -->
              <button type="button" onclick="switchMapCenter('Barangay Kaylaway Health Station', 'https://maps.google.com/maps?q=Barangay%20Kaylaway,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Kaylaway+Nasugbu+Batangas', this)" class="station-card w-full text-left p-3.5 rounded-2xl border border-slate-800 bg-slate-950 hover:border-slate-700 transition-all space-y-1 group cursor-pointer">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-xs sm:text-sm text-white group-hover:text-emerald-300">3. Kaylaway Substation</span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-purple-500/20 text-purple-300">Upland Sector</span>
                </div>
                <p class="text-xs text-slate-300">Highway Health Desk, Bgy Kaylaway</p>
                <div class="flex items-center gap-3 text-[11px] text-slate-400 pt-1">
                  <span>Phone 0919-554-3210</span>
                  <span>-</span>
                  <span>Hours: Daily 8AM-5PM</span>
                </div>
              </button>

              <!-- Station 4 -->
              <button type="button" onclick="switchMapCenter('Barangay Bucana Health Station', 'https://maps.google.com/maps?q=Barangay%20Bucana,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Bucana+Nasugbu+Batangas', this)" class="station-card w-full text-left p-3.5 rounded-2xl border border-slate-800 bg-slate-950 hover:border-slate-700 transition-all space-y-1 group cursor-pointer">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-xs sm:text-sm text-white group-hover:text-emerald-300">4. Bucana Health Center</span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-teal-500/20 text-teal-300">Community Sector</span>
                </div>
                <p class="text-xs text-slate-300">Plaza Health Desk, Bgy Bucana</p>
                <div class="flex items-center gap-3 text-[11px] text-slate-400 pt-1">
                  <span>Phone 0920-112-3344</span>
                  <span>-</span>
                  <span>Hours: Mon-Fri 8AM-4PM</span>
                </div>
              </button>

              <!-- Station 5 -->
              <button type="button" onclick="switchMapCenter('Barangay Calayo Health Station', 'https://maps.google.com/maps?q=Barangay%20Calayo,%20Nasugbu,%20Batangas&t=&z=15&ie=UTF8&iwloc=&output=embed', 'https://maps.google.com/?q=Barangay+Calayo+Nasugbu+Batangas', this)" class="station-card w-full text-left p-3.5 rounded-2xl border border-slate-800 bg-slate-950 hover:border-slate-700 transition-all space-y-1 group cursor-pointer">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-xs sm:text-sm text-white group-hover:text-emerald-300">5. Calayo Health Desk</span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-amber-500/20 text-amber-300">Southern Sector</span>
                </div>
                <p class="text-xs text-slate-300">Beach Road Desk, Bgy Calayo</p>
                <div class="flex items-center gap-3 text-[11px] text-slate-400 pt-1">
                  <span>Phone 0917-778-9900</span>
                  <span>-</span>
                  <span>Hours: Mon-Sat 8AM-4PM</span>
                </div>
              </button>

            </div>

            <!-- Emergency Hotline Banner -->
            <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-xs text-rose-200 flex items-center gap-2.5">
              <svg class="w-4 h-4 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              <span><strong>Emergency Hotline:</strong> Call Dispatch <a href="tel:0434169999" class="underline font-bold text-white"><?= e($rhu['emergency']) ?></a></span>
            </div>

          </div>
        </div>

      </div>

    </div>
  </section>

  <!-- Contact & Inquiry Section -->
  <section id="contact" class="py-16 sm:py-20 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid lg:grid-cols-12 gap-10 items-start">
        
        <!-- Left Contact Info -->
        <div class="lg:col-span-5 space-y-6">
          <div>
            <span class="px-3.5 py-1.5 rounded-full bg-brand-50 border border-brand-200 text-brand-700 text-xs font-bold uppercase tracking-wider">Get In Touch</span>
            <h2 class="mt-3 text-2xl sm:text-3xl font-extrabold text-slate-900">Contact the RHU Desk</h2>
            <p class="mt-2 text-xs sm:text-sm text-slate-600 leading-relaxed">Have questions about health services, immunization schedules, or medical clearances? Reach out to our administrative staff.</p>
          </div>

          <div class="space-y-3.5">
            <div class="flex items-start gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200/80">
              <div class="p-3 rounded-xl bg-brand-100 text-brand-700 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              </div>
              <div>
                <span class="block text-[11px] font-bold uppercase text-slate-400">RHU Main Address</span>
                <span class="text-xs sm:text-sm font-semibold text-slate-800"><?= e($rhu['address']) ?></span>
              </div>
            </div>

            <div class="flex items-start gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200/80">
              <div class="p-3 rounded-xl bg-brand-100 text-brand-700 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
              </div>
              <div>
                <span class="block text-[11px] font-bold uppercase text-slate-400">Contact Hotlines</span>
                <span class="text-xs sm:text-sm font-semibold text-slate-800">Landline: <?= e($rhu['contact']) ?></span><br>
                <span class="text-xs text-rose-600 font-bold">Emergency 24/7: <?= e($rhu['emergency']) ?></span>
              </div>
            </div>

            <div class="flex items-start gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200/80">
              <div class="p-3 rounded-xl bg-brand-100 text-brand-700 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <div>
                <span class="block text-[11px] font-bold uppercase text-slate-400">Operating Hours</span>
                <span class="text-xs sm:text-sm font-semibold text-slate-800"><?= e($rhu['hours']) ?></span>
              </div>
            </div>
          </div>

          <!-- Barangays Coverage Pill List -->
          <div class="p-4 rounded-2xl bg-emerald-50/60 border border-emerald-200/60 space-y-2">
            <div class="flex items-center justify-between">
              <span class="block text-xs font-bold text-emerald-900 uppercase tracking-wide">Barangays Under RHU Coverage</span>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-200/70 text-emerald-800"><?= count($rhu['barangays']) ?> Barangays</span>
            </div>
            <div class="flex flex-wrap gap-1.5 max-h-36 overflow-y-auto pr-1">
              <?php foreach ($rhu['barangays'] as $bgy): ?>
              <span class="px-2.5 py-1 rounded-full bg-white text-emerald-900 text-xs font-semibold shadow-xs border border-emerald-100">
                <?= e($bgy) ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Right Inquiry Form -->
        <div class="lg:col-span-7">
          <div class="bg-slate-50 rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm">
            <h3 class="text-lg sm:text-xl font-bold text-slate-900 mb-1">Send an Online Message</h3>
            <p class="text-xs text-slate-500 mb-5">Fill out the form below and our health officers will respond to your inquiry.</p>

            <?php if (isset($_GET['contact_success']) || $flash): ?>
            <div class="mb-5 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-medium flex items-center gap-3">
              <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
              <span><?= e($flash ?: 'Thank you! Your message has been sent to the RHU desk.') ?></span>
            </div>
            <?php endif; ?>

            <form method="post" action="LandingPage.php#contact" class="space-y-4">
              <input type="hidden" name="form" value="contact">

              <div class="grid sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Your Full Name *</label>
                  <input required name="name" type="text" placeholder="e.g. Juan dela Cruz" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                </div>
                <div>
                  <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Contact Number</label>
                  <input name="contact" type="text" placeholder="0917XXXXXXX" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                </div>
              </div>

              <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Inquiry Subject</label>
                <select name="subject" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all bg-white">
                  <option>General Inquiry</option>
                  <option>Appointment Inquiry</option>
                  <option>Health Certificate / Clearance</option>
                  <option>Immunization Program</option>
                  <option>Feedback & Suggestions</option>
                </select>
              </div>

              <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Your Message *</label>
                <textarea required name="message" rows="4" placeholder="How can the RHU help you?" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all"></textarea>
              </div>

              <button type="submit" class="w-full py-4 rounded-xl bg-linear-to-r from-brand-600 to-teal-600 hover:from-brand-700 hover:to-teal-700 text-white font-bold text-sm shadow-md shadow-brand-600/20 transition-all">
                Send Message to RHU &rarr;
              </button>
            </form>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- Modern Footer -->
  <footer class="relative overflow-hidden bg-[radial-gradient(circle_at_18%_10%,rgba(45,212,191,0.18),transparent_28rem),radial-gradient(circle_at_85%_85%,rgba(14,165,233,0.12),transparent_24rem),linear-gradient(135deg,#031b13_0%,#06331f_48%,#071a2c_100%)] text-emerald-50 py-12 sm:py-16 border-t border-emerald-900/50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid gap-5 lg:grid-cols-[1.35fr_0.9fr] lg:items-stretch">
        <div class="relative overflow-hidden rounded-[2rem] border border-white/10 bg-white/[0.075] p-6 sm:p-8 shadow-2xl shadow-black/15 backdrop-blur-md">
          <div class="absolute right-0 top-0 h-32 w-32 rounded-full bg-emerald-300/10 blur-2xl"></div>
          <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-4">
              <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity Logo" class="h-14 w-14 object-cover rounded-2xl bg-white p-1 shadow-lg" />
              <div>
                <span class="block text-2xl font-extrabold tracking-tight text-white">ResiHUnity RHU</span>
                <span class="text-sm font-semibold text-emerald-200">Nasugbu Rural Health Unit I</span>
              </div>
            </div>
            <span class="w-fit rounded-full border border-emerald-300/25 bg-emerald-300/10 px-4 py-2 text-xs font-extrabold uppercase tracking-wider text-emerald-100">Official Health Portal</span>
          </div>

          <p class="relative mt-6 max-w-3xl text-sm sm:text-base leading-8 text-emerald-50/78">
            The official primary care and public health portal of <?= e($rhu['name']) ?>, built to keep Nasugbu residents connected to consultations, immunization, health certificates, and community care.
          </p>

          <div class="relative mt-7 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-emerald-300/15 bg-black/15 p-4">
              <span class="block text-[11px] font-bold uppercase tracking-wider text-emerald-200/80">Coverage</span>
              <strong class="mt-1 block text-xl text-white"><?= e((string)$rhu['barangay_count']) ?> Barangays</strong>
            </div>
            <div class="rounded-2xl border border-sky-300/15 bg-black/15 p-4">
              <span class="block text-[11px] font-bold uppercase tracking-wider text-sky-200/80">Programs</span>
              <strong class="mt-1 block text-xl text-white"><?= e((string)$rhu['programs_count']) ?></strong>
            </div>
            <div class="rounded-2xl border border-teal-300/15 bg-black/15 p-4">
              <span class="block text-[11px] font-bold uppercase tracking-wider text-teal-200/80">Privacy</span>
              <strong class="mt-1 block text-sm text-white">RA 10173 Compliant</strong>
            </div>
          </div>
        </div>

        <div class="rounded-[2rem] border border-white/10 bg-white/[0.055] p-6 sm:p-7 shadow-2xl shadow-black/10 backdrop-blur-md">
          <span class="inline-flex rounded-full bg-white/10 px-3 py-1 text-[11px] font-extrabold uppercase tracking-wider text-emerald-200">Need Assistance?</span>
          <h3 class="mt-4 text-2xl font-extrabold text-white">Reach the RHU Desk</h3>
          <p class="mt-2 text-sm leading-6 text-emerald-50/70">For inquiries, appointments, certificates, and urgent local health concerns.</p>

          <div class="mt-6 space-y-3">
            <a href="tel:0434161234" class="flex items-center justify-between rounded-2xl border border-white/10 bg-white/8 px-4 py-3 text-sm font-bold text-white hover:bg-white/12 transition-colors">
              <span>Landline: <?= e($rhu['contact']) ?></span>
              <span aria-hidden="true">&rarr;</span>
            </a>
            <a href="tel:0434169999" class="flex items-center justify-between rounded-2xl border border-rose-300/20 bg-rose-400/15 px-4 py-3 text-sm font-extrabold text-rose-50 hover:bg-rose-400/20 transition-colors">
              <span>Emergency: <?= e($rhu['emergency']) ?></span>
              <span aria-hidden="true">&rarr;</span>
            </a>
          </div>

          <div class="mt-5 grid grid-cols-2 gap-3">
            <a href="ResidentLogin.php" class="rounded-2xl bg-white px-4 py-3 text-center text-xs font-extrabold text-emerald-950 shadow-lg hover:bg-emerald-50 transition-colors">Resident Sign In</a>
            <a href="ResidentRegistration.php" class="rounded-2xl bg-emerald-500 px-4 py-3 text-center text-xs font-extrabold text-white shadow-lg shadow-emerald-950/20 hover:bg-emerald-400 transition-colors">Register Free</a>
          </div>
        </div>
      </div>

      <div class="mt-8 flex flex-col items-center justify-between gap-4 border-t border-white/10 pt-6 text-center text-sm text-emerald-50/65 sm:flex-row sm:text-left">
        <p>&copy; <?= date('Y') ?> <?= e($rhu['name']) ?>. Municipality of <?= e($rhu['municipality']) ?>, <?= e($rhu['province']) ?>. All rights reserved.</p>
        <a href="#top" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 font-bold text-emerald-50 hover:bg-white/10 transition-colors">Back to top &uarr;</a>
      </div>
    </div>
  </footer>

  <script>
    function toggleMobileMenu() {
      const drawer = document.getElementById('mobile-drawer');
      if (drawer) drawer.classList.toggle('hidden');
    }

    function toggleStaffDropdown(event) {
      event.stopPropagation();
      const menu = document.getElementById('staff-dropdown-menu');
      if (menu) menu.classList.toggle('hidden');
    }

    document.addEventListener('click', function(event) {
      const container = document.getElementById('staff-dropdown-container');
      const menu = document.getElementById('staff-dropdown-menu');
      if (container && menu && !container.contains(event.target)) {
        menu.classList.add('hidden');
      }
    });

    function setActiveButton(button, activeClass, inactiveClass) {
      if (!button) return;
      button.className = button.className.replace(inactiveClass, '').replace(activeClass, '').trim();
      button.className += ' ' + activeClass;
    }

    function filterServices(category, button) {
      document.querySelectorAll('.service-card').forEach(card => {
        const matches = category === 'all' || card.dataset.category === category;
        card.classList.toggle('hidden', !matches);
        card.setAttribute('aria-hidden', matches ? 'false' : 'true');
      });
      document.querySelectorAll('#services .filter-tab').forEach(tab => tab.classList.remove('active'));
      if (button) button.classList.add('active');
    }

    function filterStaff(category, button) {
      document.querySelectorAll('.staff-card').forEach(card => {
        const matches = category === 'all' || card.dataset.category === category;
        card.classList.toggle('hidden', !matches);
        card.setAttribute('aria-hidden', matches ? 'false' : 'true');
      });
      document.querySelectorAll('#officials .filter-tab').forEach(tab => tab.classList.remove('active'));
      if (button) button.classList.add('active');
    }

    function switchMapCenter(title, embedUrl, directionsUrl, button) {
      const titleEl = document.getElementById('active-center-title');
      const iframe = document.getElementById('rhu-map-iframe');
      const directions = document.getElementById('get-directions-btn');
      if (titleEl) titleEl.textContent = title;
      if (iframe) iframe.src = embedUrl;
      if (directions) directions.href = directionsUrl;

      document.querySelectorAll('.station-card').forEach(card => {
        card.classList.remove('active', 'border-emerald-500/50', 'border-emerald-500/40', 'bg-emerald-500/10', 'bg-emerald-500/20', 'text-emerald-300');
        card.classList.add('border-slate-800', 'bg-slate-950', 'text-slate-300');
      });
      if (button) {
        button.classList.add('active', 'border-emerald-500/50', 'bg-emerald-500/10', 'text-emerald-300');
        button.classList.remove('border-slate-800', 'bg-slate-950', 'text-slate-300');
      }
    }

    let currentGallerySlide = 0;

    function showGallerySlide(index) {
      const slides = [...document.querySelectorAll('[data-slide-index]')];
      const indicators = [...document.querySelectorAll('.slide-indicator')];
      if (!slides.length) return;

      if (index < 0) index = slides.length - 1;
      if (index >= slides.length) index = 0;

      slides.forEach((slide, idx) => {
        const active = idx === index;
        slide.classList.toggle('opacity-100', active);
        slide.classList.toggle('opacity-0', !active);
        slide.style.zIndex = active ? '20' : '10';
      });

      indicators.forEach((button, idx) => {
        button.classList.toggle('bg-white', idx === index);
        button.classList.toggle('bg-white/40', idx !== index);
      });

      currentGallerySlide = index;
    }

    function prevGallerySlide() {
      showGallerySlide(currentGallerySlide - 1);
    }

    function nextGallerySlide() {
      showGallerySlide(currentGallerySlide + 1);
    }

    document.addEventListener('DOMContentLoaded', function() {
      const header = document.querySelector('.landing-page header');
      const progress = document.querySelector('.landing-scroll-progress');
      const navLinks = [...document.querySelectorAll('header nav a[href^="#"]')];
      const sections = navLinks
        .map(link => document.querySelector(link.getAttribute('href')))
        .filter(Boolean);

      const updateScrollUi = () => {
        const scrollTop = window.scrollY || document.documentElement.scrollTop;
        const scrollable = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
        if (progress) progress.style.width = `${Math.min(100, (scrollTop / scrollable) * 100)}%`;
        if (header) header.classList.toggle('is-scrolled', scrollTop > 10);

        let activeId = 'top';
        sections.forEach(section => {
          if (section.getBoundingClientRect().top <= 120) {
            activeId = section.id;
          }
        });
        navLinks.forEach(link => {
          link.classList.toggle('is-active', link.getAttribute('href') === `#${activeId}`);
        });
      };

      updateScrollUi();
      window.addEventListener('scroll', updateScrollUi, { passive: true });

      document.querySelectorAll('#mobile-drawer a[href^="#"]').forEach(link => {
        link.addEventListener('click', () => {
          const drawer = document.getElementById('mobile-drawer');
          if (drawer) drawer.classList.add('hidden');
        });
      });

      const revealTargets = document.querySelectorAll(
        '#top .lg\\:col-span-7, #top .luxury-panel, section:not(#top) .luxury-card, .event-card, .staff-card, #map .rounded-3xl, #contact .rounded-3xl'
      );
      revealTargets.forEach((el, index) => {
        el.dataset.reveal = '';
        el.style.transitionDelay = `${Math.min(index % 6, 5) * 45}ms`;
      });

      if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.isIntersecting) {
              entry.target.classList.add('is-visible');
              revealObserver.unobserve(entry.target);
            }
          });
        }, { threshold: 0.14, rootMargin: '0px 0px -40px 0px' });
        revealTargets.forEach(el => revealObserver.observe(el));
      } else {
        revealTargets.forEach(el => el.classList.add('is-visible'));
      }

      const counterObserver = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting || entry.target.dataset.counted === '1') return;
          entry.target.dataset.counted = '1';
          const raw = entry.target.textContent.trim();
          const value = Number(raw.replace(/[^0-9]/g, ''));
          if (!value || raw.includes('%')) return;
          const duration = 900;
          const start = performance.now();
          const formatter = new Intl.NumberFormat();
          const tick = now => {
            const progressAmount = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - progressAmount, 3);
            entry.target.textContent = formatter.format(Math.round(value * eased));
            if (progressAmount < 1) requestAnimationFrame(tick);
          };
          requestAnimationFrame(tick);
          counterObserver.unobserve(entry.target);
        });
      }, { threshold: 0.6 }) : null;

      document.querySelectorAll('section.bg-slate-900 .text-emerald-300').forEach(counter => {
        if (counterObserver) counterObserver.observe(counter);
      });

      document.querySelectorAll('.service-card, .event-card').forEach(card => {
        const link = card.querySelector('a[href]');
        if (!link) return;
        card.tabIndex = 0;
        card.addEventListener('keydown', event => {
          if (event.key === 'Enter') link.click();
        });
      });

      document.querySelectorAll('.slide-indicator').forEach(button => {
        button.addEventListener('click', () => showGallerySlide(Number(button.dataset.indicatorIndex)));
      });

      if (document.querySelectorAll('[data-slide-index]').length) {
        setInterval(nextGallerySlide, 5000);
      }

      const viewMoreBtn = document.getElementById('view-more-events-btn');
      if (viewMoreBtn) {
        viewMoreBtn.addEventListener('click', function() {
          const eventsList = document.querySelector('#events .event-list');
          if (eventsList) eventsList.classList.add('show-all');
          viewMoreBtn.style.display = 'none';
        });
      }
    });
  </script>
</body>
</html>
