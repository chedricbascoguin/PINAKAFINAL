<?php
// Used only by the CLI harness bound to 127.0.0.1. Never deploy this router.
$name = getenv('RHU_TEST_DB');
if (PHP_SAPI !== 'cli-server' || !preg_match('/^rhu_pw_[0-9]{13}$/', (string)$name)) { http_response_code(404); exit; }
define('DB_HOST', '127.0.0.1'); define('DB_NAME', $name); define('DB_USER', 'root'); define('DB_PASS', ''); define('DB_PORT', '3306');
// Prevent db.php from trying any configured real database if the test DB fails.
function rhuDotEnv($key, $default = null) { return $default; }
putenv('RHU_ALLOW_LOCAL_DB_FALLBACK=0');
// Capture messages in the isolated browser session; never send email or SMS.
function sendRHUEmail(string $toEmail, string $subject, string $htmlContent, bool $requireSmtp = false): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['test_mail'][] = ['to'=>$toEmail,'subject'=>$subject,'html'=>$htmlContent];
    return ['success'=>true,'method'=>'isolated-test-capture'];
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/__test/')) {
    if (!hash_equals((string)getenv('RHU_TEST_TOKEN'), $_SERVER['HTTP_X_TEST_TOKEN'] ?? '')) { http_response_code(403); exit; }
    header('Content-Type: application/json');
    if ($path === '/__test/health') { echo json_encode(['database'=>$name]); exit; }
    session_start();
    if ($path === '/__test/mail') { echo json_encode($_SESSION['test_mail'] ?? []); exit; }
    require dirname(__DIR__, 2).'/db.php';
    if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) throw new RuntimeException('Isolation failed');
    echo json_encode([
        'consultations'=>$pdo->query('SELECT * FROM consultations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'inventory'=>$pdo->query('SELECT * FROM medicine_inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ]);
    exit;
}
if (preg_match('#^/(tests|scratch|tooling|database|includes)/#', $path)) { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
if (is_file($root.$path)) {
    if (str_ends_with($path, '.php')) {
        $_SERVER['SCRIPT_NAME'] = $path;
        $_SERVER['PHP_SELF'] = $path;
        require $root.$path;
        exit;
    }
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root.'/index.php';
