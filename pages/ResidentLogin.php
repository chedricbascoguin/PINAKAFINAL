<?php
if (session_status() === PHP_SESSION_NONE) session_start();
@include_once dirname(__DIR__) . '/db.php';
@include_once dirname(__DIR__) . '/includes/portal.php';

if (!function_exists('e')) {
    function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$error = '';
$flash = $_SESSION['resident_registration_flash'] ?? '';
unset($_SESSION['resident_registration_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        if (isset($pdo) && $pdo) {
            try {
                // Direct authentication query from residents table
                $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, username, password_hash FROM residents WHERE LOWER(email) = LOWER(:email) OR LOWER(username) = LOWER(:username) LIMIT 1");
                $stmt->execute(['email' => $email, 'username' => $email]);
                $resident = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($resident) {
                    $residentId = (int)($resident['id'] ?? 0);
                    if ($residentId <= 0 && function_exists('portalRepairResidentId')) {
                        $residentId = portalRepairResidentId($pdo, $email);
                        if ($residentId > 0) {
                            $resident['id'] = $residentId;
                        }
                    }

                    $validPassword = false;
                    if (!empty($resident['password_hash']) && password_verify($password, $resident['password_hash'])) {
                        $validPassword = true;

                    }

                    if ($validPassword && (int)$resident['id'] > 0) {
                        unset($_SESSION['rhu_admin_authenticated'], $_SESSION['rhu_staff_login'], $_SESSION['bhw_user']);
                        session_regenerate_id(true);

                        $_SESSION['user'] = [
                            'id' => (int)$resident['id'],
                            'resident_id' => (int)$resident['id'],
                            'username' => strtok($resident['email'], '@'),
                            'email' => $resident['email'],
                            'first_name' => $resident['first_name'],
                            'last_name' => $resident['last_name'],
                            'role_id' => 1
                        ];

                        header('Location: ResidentDashboard.php');
                        exit;
                    } elseif ($validPassword) {
                        $error = 'This resident account needs a valid database ID before login. Please try again.';
                    } else {
                        $error = 'Invalid password for this resident account.';
                    }
                } else {
                    $error = 'No active resident account found. If you requested account linking, please visit the RHU for verification.';
                }
            } catch (PDOException $e) {
                error_log('ResidentLogin DB error: ' . $e->getMessage());
                $error = 'Database error: ' . $e->getMessage();
            }
        } else {
            $error = 'The database is unavailable. Please check the hosting database settings.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="manifest" href="manifest.php?v=20261003-icon-2">
    <meta name="theme-color" content="#0f766e">
    <link rel="apple-touch-icon" sizes="192x192" href="assets/images/resihunity-icon-192-v2.png">
    <script src="assets/js/pwa-install.js?v=20261003" defer></script>
    <link rel="icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png" sizes="192x192">
    <link rel="shortcut icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png">
    <title>Resident Portal - Sign In | ResiHUnity RHU</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <style>html, .font-sans { font-family: "Plus Jakarta Sans", sans-serif; }</style>
    <style>
      body {
        font-family: "Plus Jakarta Sans", sans-serif;
      }

      .resident-login-shell {
        position: relative;
        overflow-x: hidden;
        background: #e8f3ed;
      }

      .resident-login-shell::before {
        content: "";
        position: fixed;
        inset: -1.5rem;
        z-index: -2;
        background: url("assets/admin-municipal-background.png") center / cover no-repeat;
        filter: blur(12px);
        transform: scale(1.04);
      }

      .resident-login-shell::after {
        content: "";
        position: fixed;
        inset: 0;
        z-index: -1;
        background:
          linear-gradient(115deg, rgba(235, 248, 240, .86), rgba(255, 255, 255, .66) 46%, rgba(218, 238, 226, .78));
      }

      .brand-panel {
        background:
          linear-gradient(rgba(15, 65, 43, .78), rgba(24, 104, 67, .76)),
          url("assets/admin-municipal-background.png") center / cover no-repeat;
      }

      .brand-panel::before {
        content: "";
        position: absolute;
        width: 26rem;
        height: 26rem;
        right: -9rem;
        top: -7rem;
        border-radius: 999px;
        background: rgba(248, 253, 250, .94);
      }

      .brand-panel::after {
        content: "";
        position: absolute;
        width: 24rem;
        height: 24rem;
        left: -8rem;
        bottom: -8rem;
        border-radius: 999px;
        background: rgba(255,255,255,.13);
        border: 1px solid rgba(255,255,255,.18);
      }

      .login-panel::before {
        content: "";
        position: absolute;
        width: 20rem;
        height: 20rem;
        right: -10rem;
        top: -12rem;
        border-radius: 999px;
        background: linear-gradient(150deg, #23764d, #63a779);
      }

      .login-card {
        background: rgba(255, 255, 255, .88);
        border: 1px solid rgba(255, 255, 255, .74);
        backdrop-filter: blur(14px);
      }

      .login-panel {
        background: rgba(250, 253, 251, .91);
      }

      @media (max-width: 767px) {
        .resident-login-shell {
          background: #e8f3ed;
        }

        .login-card {
          border-radius: 1.25rem;
          max-width: 24rem;
        }

        .brand-panel {
          min-height: 17.25rem;
          padding: 1.35rem 1.45rem 1.25rem;
        }

        .brand-panel::before {
          width: 12rem;
          height: 12rem;
          right: -4.25rem;
          top: -4.75rem;
        }

        .brand-panel::after {
          width: 16rem;
          height: 16rem;
          left: auto;
          right: 4rem;
          bottom: -9rem;
        }

        .login-panel {
          min-height: auto;
          padding: 2.6rem 1.25rem 1.8rem;
        }

        .login-panel::before {
          width: 9rem;
          height: 9rem;
          right: -4.5rem;
          top: -5.25rem;
        }
      }
    </style>
</head>
<body class="resident-login-shell min-h-screen text-slate-900 antialiased selection:bg-sky-700 selection:text-white">
    <main class="flex min-h-screen items-center justify-center px-2.5 py-3 sm:px-4 sm:py-8">
        <section class="login-card grid w-full max-w-5xl overflow-hidden rounded-[1.75rem] shadow-2xl shadow-slate-900/20 md:grid-cols-[1.05fr_.95fr]">
            <div class="brand-panel relative min-h-[20rem] overflow-hidden p-8 text-white md:min-h-[34rem] md:p-10">
                <div class="relative z-10 flex h-full flex-col justify-between">
                    <a href="LandingPage.php" class="inline-flex w-fit items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-sky-700 shadow-lg sm:h-14 sm:w-14">
                            <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity RHU" class="h-8 w-8 rounded-full object-cover sm:h-10 sm:w-10">
                        </span>
                        <span>
                            <span class="block text-sm font-extrabold leading-tight sm:text-lg">ResiHUnity</span>
                            <span class="block text-[10px] font-semibold text-sky-50/85 sm:text-xs">Resident Health Portal</span>
                        </span>
                    </a>

                    <div class="max-w-sm py-5 sm:py-10 md:py-0">
                        <p class="text-[11px] font-bold uppercase tracking-[.22em] text-sky-100/90 sm:text-sm">Welcome Back!</p>
                        <h1 class="mt-3 text-[1.7rem] font-black leading-[1.05] tracking-tight sm:text-4xl md:text-5xl">Your RHU care, one sign in away.</h1>
                        <p class="mt-4 max-w-[18rem] text-[11px] font-semibold leading-5 text-sky-50/90 sm:max-w-sm sm:text-sm sm:leading-6">View health records, appointments, certificates, messages, and dental updates from the Nasugbu RHU resident dashboard.</p>
                    </div>

                    <div class="relative z-10 flex flex-wrap items-center gap-2 text-[9px] font-bold uppercase tracking-wide text-sky-50/90 sm:gap-3 sm:text-[11px]">
                        <a href="LandingPage.php" class="rounded-full border border-white/30 px-3 py-2 hover:bg-white/10 sm:px-4">Back home</a>
                        <a href="ResidentRegistration.php" class="rounded-full border border-white/30 px-3 py-2 hover:bg-white/10 sm:px-4">Create account</a>
                    </div>
                </div>
            </div>

            <div class="login-panel relative flex min-h-[34rem] items-center justify-center overflow-hidden px-6 py-10 sm:px-10">
                <div class="relative z-10 w-full max-w-sm">
                    <div class="mb-6 text-center sm:mb-8">
                        <h2 class="text-3xl font-black lowercase tracking-tight text-emerald-900 sm:text-4xl">welcome</h2>
                        <p class="mt-2 text-xs font-semibold text-slate-500">Login into your account to continue</p>
                    </div>

                    <?php if ($flash): ?>
                        <div class="mb-4 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs font-bold text-sky-800">
                            <?= e($flash) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-bold text-rose-700">
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="space-y-3.5 sm:space-y-4">
                        <label for="resident-email" class="sr-only">Email</label>
                        <input id="resident-email" required name="email" type="email" autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>" placeholder="Email" class="h-11 w-full rounded-full border-0 bg-emerald-50 px-6 text-center text-xs font-semibold text-slate-900 placeholder-emerald-900/50 outline-none ring-1 ring-emerald-200/70 transition focus:bg-white focus:ring-emerald-500 sm:h-12 sm:text-sm">

                        <div class="relative">
                            <label for="pwd" class="sr-only">Password</label>
                            <input id="pwd" required name="password" type="password" autocomplete="current-password" placeholder="Password" class="h-11 w-full rounded-full border-0 bg-emerald-50 px-6 pr-16 text-center text-xs font-semibold text-slate-900 placeholder-emerald-900/50 outline-none ring-1 ring-emerald-200/70 transition focus:bg-white focus:ring-emerald-500 sm:h-12 sm:text-sm">
                            <button type="button" id="togglePwd" class="absolute inset-y-0 right-5 text-[11px] font-black text-emerald-800 hover:text-emerald-950">Show</button>
                        </div>

                        <div class="text-center">
                            <a href="ForgotPassword.php?portal=resident" class="text-[11px] font-bold text-slate-500 hover:text-sky-800">Forgot your password?</a>
                        </div>

                        <button type="submit" class="mx-auto flex h-10 min-w-32 items-center justify-center rounded-full bg-emerald-700 px-7 text-[11px] font-black uppercase tracking-wide text-white shadow-lg shadow-emerald-900/20 transition hover:bg-emerald-800 sm:h-11 sm:min-w-36 sm:px-8 sm:text-xs">
                            Log In
                        </button>
                    </form>

                    <p class="mt-5 text-center text-[11px] font-semibold text-slate-500 sm:mt-6 sm:text-xs">
                        Don't have an account?
                        <a href="ResidentRegistration.php" class="font-black text-emerald-800 hover:text-emerald-950">sign up</a>
                    </p>
                </div>
            </div>
        </section>
    </main>

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
