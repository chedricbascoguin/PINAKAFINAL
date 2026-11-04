<?php
if (is_file(dirname(__DIR__) . '/includes/error_bootstrap.php')) {
    require_once dirname(__DIR__) . '/includes/error_bootstrap.php';
}

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/staff-access.php';

if (isset($pdo) && $pdo && function_exists('portalEnsureHealthWorkerIds')) {
    portalEnsureHealthWorkerIds($pdo);
}

if (!function_exists('e')) {
    function e($v) {
        return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
    }
}

$roles = [
    'Rural Health Midwife' => ['MIDWIFE', 'MidwifeDashboard.php'],
    'Public Health Nurse' => ['NURSE', 'NurseDashboard.php'],
    'Medical Technologist' => ['MEDTECH', 'MedTechDashboard.php'],
    'Sanitary Inspector' => ['SANITARY_INSPECTOR', 'SanitaryDashboard.php'],
    'Dental Health Staff' => ['DENTAL', 'DentalDashboard.php'],
    'Admin Secretary' => ['SECRETARY', 'RHUAdminDashboard.php']
];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (!$email || !$password) {
        $error = 'Enter your registered email address and password.';
    } else {
        if (empty($pdo)) {
            $error = 'The database is unavailable. Check your InfinityFree MySQL host, database name, username, and password in .env.';
            if (!empty($lastDbError) && strpos((string)$lastDbError, 'Access denied') !== false) {
                $error = 'Database login failed. Please verify the InfinityFree database username and password in .env.';
            } elseif (!empty($lastDbError) && (strpos((string)$lastDbError, 'getaddrinfo') !== false || strpos((string)$lastDbError, 'php_network_getaddresses') !== false)) {
                $error = 'Database host not found. Use the MySQL hostname from InfinityFree, not localhost.';
            }
        } else {
            try {

            // Query the live RHU health_workers table directly by email or username.
            $statement = $pdo->prepare(
                'SELECT id AS health_worker_id, username, email, password_hash, first_name, last_name, role, position_title
                 FROM health_workers
                 WHERE (LOWER(email) = LOWER(:login1) OR LOWER(username) = LOWER(:login2))
                   AND status = :active_status
                 LIMIT 1'
            );
            $statement->execute(['login1' => $email, 'login2' => $email, 'active_status' => 'Active']);
            $staff = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$staff || empty($staff['password_hash'])) {
                $error = "No health worker account found with email or username '{$email}'.";
            } elseif (!password_verify($password, $staff['password_hash'])) {
                $error = 'Invalid password for this account. Please try again.';
            } else {
                $actualStaffId = (int)$staff['health_worker_id'];
                $positionTitle = isset($staff['position_title']) ? $staff['position_title'] : '';
                $staffRole = isset($staff['role']) ? $staff['role'] : '';
                $actualStaffType = rhuResolveStaffType((string)$positionTitle, (string)$staffRole);

                $route = '';
                $role = '';
                foreach ($roles as $roleName => $details) {
                    if (rhuStaffMatchesLoginRole($actualStaffType, $details[0])) {
                        $role = $roleName;
                        $route = $details[1];
                        break;
                    }
                }
                if ($route === '') {
                    $error = 'This account does not have a supported RHU staff role. Please contact the administrator.';
                } else {
                    unset($_SESSION['rhu_admin_authenticated'], $_SESSION['user'], $_SESSION['bhw_user']);
                    session_regenerate_id(true);
                    try {
                        $pdo->prepare("UPDATE health_workers SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id")
                            ->execute(['id' => $actualStaffId]);
                    } catch (Exception $ignored) {}

                    $_SESSION['rhu_staff_login'] = [
                        'id' => (int)$staff['health_worker_id'],
                        'user_id' => (int)$staff['health_worker_id'],
                        'staff_id' => $actualStaffId,
                        'email' => $staff['email'],
                        'role' => $role,
                        'staff_type' => $actualStaffType,
                        'position' => $positionTitle !== '' ? $positionTitle : $staffRole,
                        'name' => trim((isset($staff['first_name']) ? $staff['first_name'] : 'RHU') . ' ' . (isset($staff['last_name']) ? $staff['last_name'] : 'Staff'))
                    ];
                    unset($_SESSION['staff_screen_lock'], $_SESSION['staff_lock_csrf'], $_SESSION['staff_lock_failures'], $_SESSION['staff_lock_retry_after']);
                    $qrReturn = (string)($_SESSION['staff_qr_return'] ?? '');
                    unset($_SESSION['staff_qr_return']);
                    if (preg_match('/\AStaffResidentAppointments\.php(?:\?(?:resident_id=[1-9][0-9]*(?:&embed=1)?|embed=1))?\z/', $qrReturn)) $route = $qrReturn;
                    session_write_close();
                    header('Location: ' . $route);
                    exit;
                }
            }
            } catch (Exception $exception) {
                error_log('RHU staff login error: ' . $exception->getMessage());
                $error = 'Database error: ' . $exception->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RHU Healthcare Staff Portal | ResiHUnity RHU</title>
    <link rel="manifest" href="manifest.php?portal=staff&amp;v=20261003-icon-2">
    <meta name="theme-color" content="#0f766e">
    <meta name="apple-mobile-web-app-title" content="RHU Healthcare Staff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" sizes="192x192" href="assets/images/resihunity-icon-192-v2.png">
    <script src="assets/js/pwa-install.js?v=20261003" defer></script>
    <link rel="icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png" sizes="192x192">
    <link rel="shortcut icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <style>html, .font-sans { font-family: "Plus Jakarta Sans", sans-serif; }</style>
    <style>
      body {
        font-family: "Plus Jakarta Sans", sans-serif;
      }

      .worker-login-shell {
        position: relative;
        overflow-x: hidden;
        background: #f5f7ef;
      }

      .worker-login-shell::before {
        content: "";
        position: fixed;
        inset: -1.5rem;
        z-index: -2;
        background: url("assets/admin-municipal-background.png") center / cover no-repeat;
        filter: blur(13px) saturate(.9);
        transform: scale(1.04);
      }

      .worker-login-shell::after {
        content: "";
        position: fixed;
        inset: 0;
        z-index: -1;
        background:
          linear-gradient(115deg, rgba(251, 250, 245, .92), rgba(255, 255, 255, .72) 44%, rgba(232, 241, 220, .86)),
          radial-gradient(circle at 12% 18%, rgba(169, 200, 119, .35), transparent 24rem),
          radial-gradient(circle at 88% 80%, rgba(40, 91, 45, .20), transparent 24rem);
      }

      .worker-brand-panel {
        background:
          linear-gradient(145deg, rgba(8, 40, 18, .92), rgba(40, 91, 45, .82)),
          url("assets/admin-municipal-background.png") center / cover no-repeat;
      }

      .worker-brand-panel::before {
        content: "";
        position: absolute;
        width: 25rem;
        height: 25rem;
        right: -8rem;
        top: -7rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, .92);
      }

      .worker-brand-panel::after {
        content: "";
        position: absolute;
        width: 22rem;
        height: 22rem;
        left: -8rem;
        bottom: -8rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, .12);
        border: 1px solid rgba(255, 255, 255, .22);
      }

      .worker-form-panel {
        background: rgba(250, 249, 243, .93);
      }

      .worker-form-panel::before {
        content: "";
        position: absolute;
        width: 18rem;
        height: 18rem;
        right: -9rem;
        top: -11rem;
        border-radius: 999px;
        background: linear-gradient(150deg, #285b2d, #7aa24b);
        opacity: .95;
      }

      .worker-login-card {
        background: rgba(255, 255, 255, .9);
        border: 1px solid rgba(229, 234, 223, .95);
        backdrop-filter: blur(14px);
      }

      @media (max-width: 767px) {
        .worker-login-card {
          border-radius: 1.25rem;
          max-width: 24rem;
        }

        .worker-brand-panel {
          min-height: 17.5rem;
          padding: 1.35rem 1.45rem 1.25rem;
        }

        .worker-brand-panel::before {
          width: 12rem;
          height: 12rem;
          right: -4.25rem;
          top: -4.75rem;
        }

        .worker-brand-panel::after {
          width: 15rem;
          height: 15rem;
          left: auto;
          right: 4rem;
          bottom: -8.5rem;
        }

        .worker-form-panel {
          min-height: auto;
          padding: 2.4rem 1.25rem 1.8rem;
        }

        .worker-form-panel::before {
          width: 9rem;
          height: 9rem;
          right: -4.5rem;
          top: -5.25rem;
        }
      }
    </style>
</head>
<body class="worker-login-shell min-h-screen text-stone-950 antialiased selection:bg-green-900 selection:text-white">
    <header class="absolute inset-x-0 top-0 z-20 px-5 py-5 sm:px-8">
        <div class="mx-auto flex max-w-7xl items-center justify-between">
            <a href="rhulandingpage.php" class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-md">
                    <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity Logo" class="h-8 w-8 rounded-full object-cover">
                </span>
                <span class="text-lg font-extrabold tracking-tight text-green-950 sm:text-xl">ResiHUnity RHU</span>
            </a>
        </div>
    </header>

    <main class="relative z-10 flex min-h-screen items-center justify-center px-2.5 py-24 sm:px-4">
        <section class="worker-login-card grid w-full max-w-5xl overflow-hidden rounded-[1.75rem] shadow-2xl shadow-stone-900/15 md:grid-cols-[1.05fr_.95fr]">
            <div class="worker-brand-panel relative min-h-[20rem] overflow-hidden p-8 text-white md:min-h-[34rem] md:p-10">
                <div class="relative z-10 flex h-full flex-col justify-between">
                    <a href="rhulandingpage.php" class="inline-flex w-fit items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-green-800 shadow-lg sm:h-14 sm:w-14">
                            <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity RHU" class="h-8 w-8 rounded-full object-cover sm:h-10 sm:w-10">
                        </span>
                        <span>
                            <span class="block text-sm font-extrabold leading-tight sm:text-lg">ResiHUnity</span>
                            <span class="block text-[10px] font-semibold text-green-50/85 sm:text-xs">Healthcare Worker Access</span>
                        </span>
                    </a>

                    <div class="max-w-sm py-5 sm:py-10 md:py-0">
                        <p class="text-[11px] font-bold uppercase tracking-[.22em] text-green-100/90 sm:text-sm">Worker Sign In</p>
                        <h1 class="mt-3 text-[1.7rem] font-black leading-[1.05] tracking-tight sm:text-4xl md:text-5xl">RHU desk tools for care teams.</h1>
                        <p class="mt-4 max-w-[18rem] text-[11px] font-semibold leading-5 text-green-50/90 sm:max-w-sm sm:text-sm sm:leading-6">Access clinical queues, resident records, laboratory workflows, sanitation reports, and field updates for Nasugbu RHU operations.</p>
                    </div>

                    <div class="relative z-10 flex flex-wrap items-center gap-2 text-[9px] font-bold uppercase tracking-wide text-green-50/90 sm:gap-3 sm:text-[11px]">
                        <a href="rhulandingpage.php" class="rounded-full border border-white/30 px-3 py-2 hover:bg-white/10 sm:px-4">Back home</a>
                        <a href="RHUAdminLogin.php" class="rounded-full border border-white/30 px-3 py-2 hover:bg-white/10 sm:px-4">MHO / Admin</a>
                    </div>
                </div>
            </div>

            <div class="worker-form-panel relative flex min-h-[34rem] items-center justify-center overflow-hidden px-6 py-10 sm:px-10">
                <div class="relative z-10 w-full max-w-sm">
                    <div class="mb-6 text-center sm:mb-7">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-green-200 bg-green-50 px-3 py-1 text-[11px] font-black uppercase tracking-wider text-green-800">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-3-3v6m6-11H6a2 2 0 00-2 2v13h16V6a2 2 0 00-2-2z"/></svg>
                            Healthcare Personnel
                        </span>
                        <h2 class="mt-3 text-3xl font-black tracking-tight text-green-950 sm:text-4xl">staff login</h2>
                        <p class="mt-2 text-xs font-semibold text-stone-500">Sign in with your staff account</p>
                    </div>

                <!-- Error Alert -->
                <?php if ($error): ?>
                    <div class="mb-4 flex items-center gap-2.5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-bold text-rose-700">
                        <svg class="w-4 h-4 text-rose-500 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                    <form method="post" class="space-y-4">
                    <div class="space-y-1.5">
                        <label for="staff-email" class="block text-xs font-black uppercase tracking-wider text-stone-600">RHU Email Address</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-stone-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </span>
                            <input id="staff-email" required name="email" type="email" autocomplete="username" value="<?= e(isset($_POST['email']) ? $_POST['email'] : '') ?>" placeholder="staff@nasugbu.rhu.gov.ph" class="w-full rounded-xl border border-stone-200 bg-white py-3 pl-10 pr-4 text-sm font-semibold text-stone-950 outline-none transition placeholder:text-stone-400 focus:border-green-800 focus:ring-4 focus:ring-green-900/10">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="pwd" class="block text-xs font-black uppercase tracking-wider text-stone-600">Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-stone-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </span>
                            <input id="pwd" required name="password" type="password" autocomplete="current-password" placeholder="Enter your staff password" class="w-full rounded-xl border border-stone-200 bg-white py-3 pl-10 pr-14 text-sm font-semibold text-stone-950 outline-none transition placeholder:text-stone-400 focus:border-green-800 focus:ring-4 focus:ring-green-900/10">
                            <button type="button" id="togglePwd" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-black text-green-800 transition hover:text-green-950">
                                Show
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-end pt-0.5 text-xs">
                        <a href="ForgotPassword.php?portal=staff" class="font-bold text-green-900 transition hover:text-green-950 hover:underline">Forgot password?</a>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-950 px-4 py-3.5 text-sm font-black text-white shadow-lg shadow-green-950/20 transition hover:bg-green-900">
                            <span>Sign In to RHU Staff Portal</span>
                            <svg class="h-4 w-4 shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </form>

                <div class="mt-5 flex items-start gap-2.5 rounded-2xl border border-green-200 bg-white/80 p-3.5 text-xs leading-relaxed text-green-900">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-green-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Restricted to authorized Nasugbu RHU healthcare personnel. Active sessions are audited.</span>
                </div>

                <div class="pt-6 text-center">
                    <span class="mb-2 block text-[11px] font-black uppercase tracking-wider text-stone-500">Switch Access</span>
                    <div class="grid grid-cols-1 gap-2">
                        <a href="RHUAdminLogin.php" class="group flex items-center justify-center gap-2 rounded-xl border border-stone-200 bg-white/85 px-3 py-2.5 text-xs font-black text-green-950 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-green-200 hover:bg-green-50 hover:shadow-md">
                            <svg class="h-3.5 w-3.5 text-green-800 transition group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1118.88 6.196M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span>MHO / Admin</span>
                        </a>
                    </div>
                </div>
            </div>
            </div>
        </section>
    </main>

    <footer class="relative z-10 -mt-16 px-4 pb-5 text-center text-xs font-semibold text-stone-500">
        <p>&copy; <?= date('Y') ?> Nasugbu Rural Health Unit I. RA 10173 Data Privacy & DOH IT Security Compliant.</p>
    </footer>

    <script>
        document.getElementById('togglePwd')?.addEventListener('click', function(){
            const p = document.getElementById('pwd');
            if (!p) return;
            if (p.type === 'password') { 
                p.type = 'text'; 
                this.textContent = 'Hide'; 
            } else { 
                p.type = 'password'; 
                this.textContent = 'Show'; 
            }
        });
    </script>
</body>
</html>
