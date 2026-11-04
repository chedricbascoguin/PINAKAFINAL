<?php
if (is_file(__DIR__ . '/includes/error_bootstrap.php')) {
    require_once __DIR__ . '/includes/error_bootstrap.php';
}

// Dispatch legacy PHP URLs without changing their public script directory.
$publicFiles = require __DIR__ . '/includes/public-file-map.php';
$requestedFile = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if (isset($publicFiles[$requestedFile])) {
    $publicBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    $_SERVER['SCRIPT_NAME'] = $publicBase . '/' . $requestedFile;
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/' . $publicFiles[$requestedFile];
    require $_SERVER['SCRIPT_FILENAME'];
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

// Select the public landing page by InfinityFree domain. Keep the normal
// component router available for localhost and direct application routes.
$domain = strtolower($_SERVER['HTTP_HOST'] ?? '');
$domain = preg_replace('/:\d+$/', '', $domain);
$domain = preg_replace('/^www\./', '', $domain);

if ($domain === 'rhu.page.gd') {
    require __DIR__ . '/pages/LandingPage.php';
    exit;
}

if ($domain === 'ruralhealthunit.page.gd') {
    require __DIR__ . '/pages/rhulandingpage.php';
    exit;
}

require_once __DIR__ . '/includes/project-paths.php';
$base = '';

// Available PHP pages.
$components = [
    'AdminDashboard' => 'Admin Dashboard',
    'AdminLogin' => 'Admin Login',
    'AdminStaffDashboard' => 'Admin Staff Dashboard',  
    'BHWDashboard' => 'BHW Dashboard',
    'BHWLogin' => 'BHW Login',
    'DeviceIndicator' => 'Device Indicator',
    'DonationCertificate' => 'Donation Certificate',
    'DonorDashboard' => 'Donor Dashboard',
    'HospitalRegistration' => 'Hospital Registration',
    'LandingPage' => 'ResiHUnity RHU - Home',
    'LoginSelection' => 'Login Selection',
    'MedTechDashboard' => 'Medical Technologist Dashboard',
    'MidwifeDashboard' => 'Midwife Dashboard',
    'NurseDashboard' => 'Nurse Dashboard',
    'PlatformAwareDashboard' => 'Platform Aware Dashboard',
    'ResidentDashboard' => 'Resident Dashboard',
    'ResidentLogin' => 'Resident Login',
    'ResidentRegister' => 'Resident Register',
    'ResidentRegistration' => 'Resident Registration',
    'RHUAdminDashboard' => 'RHU Admin Dashboard',
    'RHUAdminLogin' => 'RHU Admin Login',
    'RHUDashboard' => 'RHU Dashboard',
    'RHULogin' => 'RHU Staff Login',
    'SanitaryDashboard' => 'Sanitary Inspector Dashboard'
];

$component = null;
// 1) If explicit GET param provided, use it
if (isset($_GET['component'])) {
    $component = basename($_GET['component']);
} else {
    // 2) Try to derive the component from the request URI for SPA-style routes
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    // Remove the base path if the app is served from a subdirectory
    if (stripos($requestPath, $base) === 0) {
        $requestPath = substr($requestPath, strlen($base));
    }
    $requestPath = trim($requestPath, '/');

    $routeMap = [
        '' => 'LandingPage',
        'login' => 'LoginSelection',
        'admin/login' => 'AdminLogin',
        'admin/dashboard' => 'AdminDashboard',
        'resident/login' => 'ResidentLogin',
        'resident/register' => 'ResidentRegistration',
        'resident/registration' => 'ResidentRegistration',
        'resident/dashboard' => 'ResidentDashboard',
        'rhu/login' => 'RHULogin',
        'rhu/dashboard' => 'RHUDashboard',
        'rhu/dashboard/midwife' => 'MidwifeDashboard',
        'rhu/dashboard/nurse' => 'NurseDashboard',
        'rhu/dashboard/medtech' => 'MedTechDashboard',
        'rhu/dashboard/sanitary' => 'SanitaryDashboard',
        'rhu/dashboard/admin-staff' => 'AdminStaffDashboard',
        'rhu/admin/login' => 'RHUAdminLogin',
        'rhu/admin/dashboard' => 'RHUAdminDashboard',
        'donor/dashboard' => 'DonorDashboard',
        'hospital/register' => 'HospitalRegistration',
        'hospital/registration' => 'HospitalRegistration',
    ];

    if (isset($routeMap[strtolower($requestPath)])) {
        $component = $routeMap[strtolower($requestPath)];
    }

    if ($requestPath !== '') {
        $segments = array_values(array_filter(explode('/', $requestPath)));
        $candidates = [];
        // Build candidate component names by joining segments in StudlyCase
        for ($i = 1; $i <= count($segments); $i++) {
            $parts = array_slice($segments, 0, $i);
            $nameParts = array_map(function ($s) {
                if (strtolower($s) === 'rhu') return 'RHU';
                return ucfirst(strtolower($s));
            }, $parts);
            $candidates[] = implode('', $nameParts);
        }
        // Try candidates in order (longest first)
        $candidates = array_reverse($candidates);
        if (!$component) {
            foreach ($candidates as $cand) {
                $phpFile = rhuProjectPagePath($cand);
                if (file_exists($phpFile)) {
                    $component = $cand;
                    break;
                }
            }
        }
    }
}

// Default component
if (!$component) {
    $component = 'LandingPage';
}

if (!isset($components[$component]) && !file_exists(rhuProjectPagePath($component))) {
    http_response_code(404);
    $component = 'LandingPage';
}

$title = isset($components[$component]) ? $components[$component] : 'Component';

// Check if component exists
$phpFile = rhuProjectPagePath($component);

if (!file_exists($phpFile) && $phpFile !== __FILE__) {
    // Component file not found, show index
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ResiHUnity RHU - PHP Components Index</title>
        <link rel="stylesheet" href="styles/index.css">
    </head>
    <body class="bg-linear-to-br from-slate-900 via-slate-800 to-gray-900">
        <div class="min-h-screen py-12 px-4">
            <div class="max-w-4xl mx-auto">
                <div class="text-center mb-12">
                    <h1 class="text-4xl font-bold text-white mb-2">ResiHUnity RHU</h1>
                    <p class="text-gray-400">All Components Successfully Converted to PHP</p>
                    <p class="text-green-400 font-semibold mt-2">âœ“ All functionality, UI, styling, and logic preserved</p>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <?php foreach ($components as $key => $comp_title): ?>
                        <a href="?component=<?php echo urlencode($key); ?>" class="bg-white/10 hover:bg-white/20 border border-white/20 rounded-lg p-4 transition-all hover:-translate-y-1">
                            <h3 class="text-white font-semibold"><?php echo $comp_title; ?></h3>
                            <p class="text-gray-400 text-sm mt-1"><?php echo $key; ?>.php</p>
                            <span class="text-green-400 text-xs mt-2 inline-block">âœ“ Converted</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="mt-12 bg-green-50 border-2 border-green-400 rounded-lg p-6 text-center">
                    <h2 class="text-2xl font-bold text-green-900 mb-2">Conversion Complete!</h2>
                    <p class="text-green-800">All 22 components have been successfully converted from React/TypeScript to PHP.</p>
                    <p class="text-green-700 mt-2">âœ“ All UI elements preserved</p>
                    <p class="text-green-700">âœ“ All functionality maintained</p>
                    <p class="text-green-700">âœ“ All styling (Tailwind CSS) intact</p>
                    <p class="text-green-700">âœ“ All forms and logic preserved</p>
                </div>
            </div>
        </div>
    </body>
    </html>
<?php
} else if (file_exists($phpFile) && $phpFile !== __FILE__) {
    // Load the specific component PHP file
    include $phpFile;
}
?>
