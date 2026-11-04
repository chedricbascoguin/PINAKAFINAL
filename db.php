<?php
// Shared database connection for PHP components (PDO).
// Values set by the web-server environment take precedence over .env values.
// InfinityFree requires the MySQL host shown in its control panel; localhost/root
// only work on a local XAMPP install.
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$rhuTimezone = getenv('RHU_TIMEZONE') ?: 'Asia/Manila';
if (!@date_default_timezone_set($rhuTimezone)) {
    $rhuTimezone = 'Asia/Manila';
    date_default_timezone_set($rhuTimezone);
}

if (!function_exists('rhuEnv')) {
    function rhuEnv($key, $default = null) {
        $value = getenv($key);
        if ($value !== false && $value !== '') return $value;

        static $environment = null;
        if ($environment === null) {
            $environment = [];
            $envFiles = [
                __DIR__ . '/.env',
                dirname(__DIR__) . '/.env',
                dirname(__DIR__, 3) . '/.env',
            ];
            foreach ($envFiles as $envFile) {
                if (!is_readable($envFile)) continue;
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                    $envParts = explode('=', $line, 2);
                    $envKey = $envParts[0];
                    $envValue = $envParts[1];
                    $environment[trim($envKey)] = trim(trim($envValue), "\"'");
                }
                break;
            }
        }
        return array_key_exists($key, $environment) ? $environment[$key] : $default;
    }
}

if (!function_exists('rhuDotEnv')) {
    function rhuDotEnv($key, $default = null) {
        static $environment = null;
        if ($environment === null) {
            $environment = [];
            $envFiles = [
                __DIR__ . '/.env',
                dirname(__DIR__) . '/.env',
                dirname(__DIR__, 3) . '/.env',
            ];
            foreach ($envFiles as $envFile) {
                if (!is_readable($envFile)) continue;
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                    $envParts = explode('=', $line, 2);
                    $envKey = $envParts[0];
                    $envValue = $envParts[1];
                    $environment[trim($envKey)] = trim(trim($envValue), "\"'");
                }
                break;
            }
        }
        return array_key_exists($key, $environment) ? $environment[$key] : $default;
    }
}

if (!function_exists('rhuEnvAny')) {
    function rhuEnvAny(array $keys, $default = null) {
        foreach ($keys as $key) {
            $value = rhuEnv($key);
            if ($value !== null && $value !== '') return $value;
        }
        return $default;
    }
}

if (!function_exists('rhuApprovedTables')) {
    function rhuApprovedTables(): array
    {
        return [
            'audit_logs',
            'barangays',
            'certificates',
            'confidential_access_logs',
            'confidential_lab_tests',
            'consultations',
            'dental_records',
            'disease_cases',
            'disease_types',
            'events',
            'fhsis_reports',
            'health_records',
            'health_workers',
            'medicine_inventory',
            'messages',
            'portal_announcements',
            'portal_events',
            'portal_notifications',
            'portal_push_settings',
            'portal_push_subscriptions',
            'portal_settings',
            'pregnancy_records',
            'residents',
            'resident_health_profiles',
            'resident_locations',
            'sanitary_records',
            'stock_transactions',
            'vaccination_records',
            'vital_statistics',
        ];
    }
}

if (!function_exists('rhuIsApprovedTable')) {
    function rhuIsApprovedTable(string $table): bool
    {
        return in_array($table, rhuApprovedTables(), true);
    }
}

$primaryHost = rhuEnvAny(['DB_HOST', 'MYSQL_HOST', 'MYSQLHOST', 'DATABASE_HOST'], '127.0.0.1');
$primaryName = rhuEnvAny(['DB_NAME', 'MYSQL_DATABASE', 'MYSQLDATABASE', 'DATABASE_NAME'], 'rhu');
$primaryUser = rhuEnvAny(['DB_USER', 'MYSQL_USER', 'MYSQLUSER', 'DATABASE_USER'], 'root');
$primaryPass = rhuEnvAny(['DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD', 'MYSQLPASSWORD', 'DATABASE_PASSWORD'], '');
$primaryPort = rhuEnvAny(['DB_PORT', 'MYSQL_PORT', 'MYSQLPORT', 'DATABASE_PORT'], '3306');

if (defined('DB_HOST')) $primaryHost = (string) DB_HOST;
if (defined('DB_NAME')) $primaryName = (string) DB_NAME;
if (defined('DB_USER')) $primaryUser = (string) DB_USER;
if (defined('DB_PASS')) $primaryPass = (string) DB_PASS;
if (defined('DB_PASSWORD')) $primaryPass = (string) DB_PASSWORD;
if (defined('DB_PORT')) $primaryPort = (string) DB_PORT;

if (str_contains($primaryHost, ':') && !str_starts_with($primaryHost, '[')) {
    $hostParts = explode(':', $primaryHost, 2);
    $hostOnly = $hostParts[0];
    $portOnly = $hostParts[1];
    if ($hostOnly !== '' && ctype_digit($portOnly)) {
        $primaryHost = $hostOnly;
        $primaryPort = $portOnly;
    }
}

$hasExplicitDbConfig = (
    $primaryHost !== '127.0.0.1' || $primaryName !== 'rhu' || $primaryUser !== 'root' ||
    getenv('DB_HOST') !== false || getenv('DB_NAME') !== false || getenv('DB_USER') !== false ||
    getenv('DB_PASS') !== false || getenv('DB_PASSWORD') !== false ||
    getenv('MYSQL_HOST') !== false || getenv('MYSQLHOST') !== false ||
    is_file(__DIR__ . '/.env') || is_file(dirname(__DIR__) . '/.env') ||
    is_file(dirname(__DIR__, 3) . '/.env')
);

if (!$hasExplicitDbConfig) {
    $lastDbError = 'Missing database configuration. Upload a .env file with DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD. On InfinityFree, DB_HOST must be the MySQL hostname from the control panel, not localhost.';
    error_log('db.php: ' . $lastDbError);
    return;
}

$configCandidates = [];
$configCandidates[] = [
    'host' => $primaryHost,
    'name' => $primaryName,
    'user' => $primaryUser,
    'pass' => $primaryPass,
    'port' => $primaryPort,
];

$dotEnvHost = rhuDotEnv('DB_HOST');
$dotEnvName = rhuDotEnv('DB_NAME');
$dotEnvUser = rhuDotEnv('DB_USER');
$dotEnvPass = rhuDotEnv('DB_PASS', rhuDotEnv('DB_PASSWORD'));
$dotEnvPort = rhuDotEnv('DB_PORT', '3306');
if ($dotEnvHost && $dotEnvName && $dotEnvUser) {
    $dotEnvCandidate = [
        'host' => $dotEnvHost,
        'name' => $dotEnvName,
        'user' => $dotEnvUser,
        'pass' => $dotEnvPass !== null ? $dotEnvPass : '',
        'port' => $dotEnvPort,
    ];
    if ($dotEnvCandidate !== $configCandidates[0]) {
        $configCandidates[] = $dotEnvCandidate;
    }
}

$localFallback = [
    'host' => '127.0.0.1',
    'name' => 'rhu',
    'user' => 'root',
    'pass' => '',
    'port' => '3306',
];
if (rhuEnv('RHU_ALLOW_LOCAL_DB_FALLBACK', '0') === '1' && (($primaryHost !== '127.0.0.1') || ($primaryName !== 'rhu') || ($primaryUser !== 'root'))) {
    $configCandidates[] = $localFallback;
}


$pdo = null;
$lastDbError = null;
foreach ($configCandidates as $candidate) {
    $DB_HOST = $candidate['host'];
    $DB_NAME = $candidate['name'];
    $DB_USER = $candidate['user'];
    $DB_PASS = $candidate['pass'];
    $DB_PORT = $candidate['port'];

    try {
        $pdo = new PDO("mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => (int) rhuEnv('DB_TIMEOUT', '5'),
        ]);
        $timezoneOffset = (new DateTimeImmutable('now', new DateTimeZone($rhuTimezone)))->format('P');
        $pdo->prepare('SET time_zone = :time_zone')->execute(['time_zone' => $timezoneOffset]);
        // PDO connection established to the first reachable database.
        break;
    } catch (PDOException $e) {
        $lastDbError = $e->getMessage();
        error_log('db.php: Could not connect to DB at host=' . $DB_HOST . ' db=' . $DB_NAME . ': ' . $e->getMessage());
        $pdo = null;
    }
}

if ($pdo === null && $lastDbError !== null) {
    error_log('db.php: All configured database connection attempts failed. Last error: ' . $lastDbError);
}

require_once __DIR__ . '/includes/staff-session-lock.php';
rhuEnforceStaffScreenLock();

return;
