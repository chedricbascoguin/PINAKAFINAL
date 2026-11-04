<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store, private, max-age=0');
require_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

if (!function_exists('e')) {
    function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/**
 * Fetches the single active Admin account from the database.
 */
function getActiveAdminAccount(?PDO $pdo): ?array
{
  if (!$pdo) return null;
  try {
    $stmt = $pdo->prepare("
      SELECT 
        hw.id AS user_id, hw.id AS staff_id, hw.username, hw.email, hw.password_hash,
        hw.first_name, hw.last_name, 9 AS role_id, (hw.status = 'Active') AS is_active, hw.created_at,
        hw.license_number AS admin_code, hw.position_title AS designation, hw.contact_number AS phone_number,
        hw.created_at AS date_hired, 'RHU_ADMIN' AS role_name
      FROM health_workers hw
      WHERE hw.status = 'Active'
      AND (UPPER(hw.position_title) IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')
           OR UPPER(hw.position_title) LIKE '%MUNICIPAL HEALTH OFFICER%'
           OR UPPER(hw.role) = 'ADMIN')
      ORDER BY hw.id ASC
      LIMIT 1
    ");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    return $admin ?: null;
  } catch (Exception $e) {
    return null;
  }
}

$activeAdmin = getActiveAdminAccount($pdo);
$step = $_GET['step'] ?? 'credentials';
$error = '';
$infoMsg = '';

if (!empty($_SESSION['admin_login_flash'])) {
  $infoMsg = $_SESSION['admin_login_flash'];
  unset($_SESSION['admin_login_flash']);
}

/**
 * Dispatches 2FA MFA code via email.
 */
function sendMfaEmail(string $targetEmail, string $code): bool
{
  $subject = 'ResiHUnity RHU - Your 2-Factor Authentication (2FA) Code';
  $message = "
    <html>
    <head>
      <title>ResiHUnity RHU 2-Factor Authentication</title>
      <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f9; padding: 20px; color: #333; }
        .container { max-width: 500px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e1e8ed; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .header { text-align: center; border-bottom: 2px solid #7c3aed; padding-bottom: 15px; margin-bottom: 20px; }
        .header h2 { color: #5b21b6; margin: 0; }
        .code-box { background: #f3e8ff; border: 2px dashed #7c3aed; padding: 15px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #5b21b6; border-radius: 8px; margin: 25px 0; }
        .footer { font-size: 12px; color: #888; text-align: center; margin-top: 25px; border-top: 1px solid #eee; padding-top: 15px; }
      </style>
    </head>
    <body>
      <div class='container'>
        <div class='header'>
          <h2>ResiHUnity RHU System</h2>
          <p style='margin:5px 0 0 0; color:#666; font-size:14px;'>Municipal Health Office - Nasugbu, Batangas</p>
        </div>
        <p>Dear Administrator,</p>
        <p>Your two-factor authentication (2FA) verification code for accessing the RHU Administrator Panel is:</p>
        <div class='code-box'>{$code}</div>
        <p>This code is valid for <strong>10 minutes</strong>. If you did not attempt to sign in to the ResiHUnity RHU System, please secure your account immediately.</p>
        <div class='footer'>
          <p>&copy; " . date('Y') . " Nasugbu Rural Health Unit I. All rights reserved.</p>
          <p>Republic Act 10173 - Data Privacy Act Compliant</p>
        </div>
      </div>
    </body>
    </html>
  ";

  $res = sendRHUEmail($targetEmail, $subject, $message, true);
  $_SESSION['mfa_mail_delivery'] = [
    'success'=>!empty($res['success']), 'method'=>(string)($res['method'] ?? 'unknown'),
    'time'=>date('Y-m-d H:i:s'),
  ];
  $_SESSION['mfa_delivery_error'] = !empty($res['success']) ? '' : (string)($res['error'] ?? 'SMTP delivery failed.');
  if (empty($res['success'])) error_log('Admin 2FA email delivery failed: ' . $_SESSION['mfa_delivery_error']);
  return (bool) ($res['success'] ?? false);
}

function issueAdminMfaCode(string $targetEmail): bool
{
  $code = sprintf('%06d', random_int(0, 999999));
  if (!sendMfaEmail($targetEmail, $code)) return false;
  // A failed resend must not invalidate the code already in the administrator's inbox.
  $_SESSION['mfa_otp'] = $code;
  $_SESSION['mfa_otp_email'] = $targetEmail;
  $_SESSION['mfa_otp_expires'] = time() + 600;
  return true;
}

// Resend 2FA Action
if (isset($_GET['action']) && $_GET['action'] === 'resend' && !empty($_SESSION['pending_admin_user'])) {
  $targetEmail = $_SESSION['rhu_admin_email'] ?? '';
  if ($targetEmail) {
    unset($_SESSION['mfa_flash_info'], $_SESSION['mfa_flash_error']);
    if (issueAdminMfaCode($targetEmail)) {
      $_SESSION['mfa_flash_info'] = "The mail server accepted a new verification email for {$targetEmail}. Check your inbox and spam folder.";
    } else {
      $_SESSION['mfa_flash_error'] = 'The verification email could not be sent. ' . ($_SESSION['mfa_delivery_error'] ?? 'Check the hosted SMTP configuration and try again.');
    }
  }
  header('Location: RHUAdminLogin.php?step=mfa');
  exit;
}

if (!empty($_SESSION['mfa_flash_info'])) {
  $infoMsg = $_SESSION['mfa_flash_info'];
  unset($_SESSION['mfa_flash_info']);
}
if (!empty($_SESSION['mfa_flash_error'])) {
  $error = $_SESSION['mfa_flash_error'];
  unset($_SESSION['mfa_flash_error']);
}
if ($step === 'mfa' && $error === '' && !empty($_SESSION['pending_admin_user'])
    && isset($_SESSION['mfa_mail_delivery']) && empty($_SESSION['mfa_mail_delivery']['success'])) {
  $error = 'The verification email was not sent. ' . ($_SESSION['mfa_delivery_error'] ?? 'SMTP delivery failed on this server.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $actionStep = $_POST['step'] ?? '';

  if ($actionStep === 'credentials') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
      $error = 'Please enter your admin email address and password.';
    } elseif (empty($pdo)) {
      $error = 'Database connection failed. Please ensure XAMPP MySQL is running.';
    } elseif (!$activeAdmin) {
      $error = 'No active administrator account exists in the database. Registration is required.';
    } else {
      // 1. STRICT UNIQUE ACCOUNT CHECK: Email MUST match the unique registered admin email in DB!
      if (strtolower($email) !== strtolower($activeAdmin['email'])) {
        $error = 'Access denied. Only the registered System Administrator account is authorized to sign in.';
      } elseif (!password_verify($password, $activeAdmin['password_hash'])) {
        $error = 'Incorrect password for the System Administrator account.';
      } else {
        $loginSettings = portalSettings($pdo);
        if (portalSetting($loginSettings, 'two_factor_enabled', '1') !== '1') {
          unset($_SESSION['rhu_staff_login'], $_SESSION['bhw_user']);
          session_regenerate_id(true);
          $_SESSION['rhu_admin_authenticated'] = true;
          unset($_SESSION['staff_screen_lock'], $_SESSION['staff_lock_csrf'], $_SESSION['staff_lock_failures'], $_SESSION['staff_lock_retry_after']);
          $_SESSION['user'] = $activeAdmin;
          $upd = $pdo->prepare('UPDATE health_workers SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
          $upd->execute(['id' => $activeAdmin['user_id']]);
          portalAudit($pdo, (int)$activeAdmin['user_id'], 'RHU Admin Login', 'health_workers', (int)$activeAdmin['user_id']);
          header('Location: RHUAdminDashboard.php');
          exit;
        }
        // Valid Unique Admin Credentials
        $_SESSION['pending_admin_user'] = $activeAdmin;
        $_SESSION['rhu_admin_email'] = $activeAdmin['email'];

        unset($_SESSION['mfa_otp'], $_SESSION['mfa_otp_email'], $_SESSION['mfa_otp_expires'], $_SESSION['mfa_flash_info'], $_SESSION['mfa_flash_error']);
        if (issueAdminMfaCode($activeAdmin['email'])) {
          $_SESSION['mfa_flash_info'] = "The mail server accepted a verification email for {$activeAdmin['email']}. Check your inbox and spam folder.";
        } else {
          $_SESSION['mfa_flash_error'] = 'The verification email could not be sent. ' . ($_SESSION['mfa_delivery_error'] ?? 'Check the hosted SMTP configuration and try again.');
        }
        header('Location: RHUAdminLogin.php?step=mfa');
        exit;
      }
    }
  } elseif ($actionStep === 'mfa') {
    $mfaCode = trim($_POST['mfaCode'] ?? '');
    $storedOtp = $_SESSION['mfa_otp'] ?? '';
    $otpExpires = $_SESSION['mfa_otp_expires'] ?? 0;

    if (strlen($mfaCode) !== 6 || !ctype_digit($mfaCode)) {
      $error = 'Please enter a valid 6-digit numeric 2FA verification code.';
      $step = 'mfa';
    } elseif ($otpExpires > 0 && time() > $otpExpires) {
      $error = 'Your 2FA code has expired. Please click "Resend 2FA Code" to receive a new code.';
      $step = 'mfa';
    } elseif ($storedOtp === '' || $mfaCode !== $storedOtp) {
      // 2. STRICT 2FA CODE VALIDATION: Will NOT accept unless it is the exact code sent to the email!
      $error = 'Incorrect 2FA code. Access will not be granted unless you enter the exact code sent to your email address.';
      $step = 'mfa';
    } else {
      $user = $_SESSION['pending_admin_user'] ?? null;
      unset($_SESSION['rhu_staff_login'], $_SESSION['bhw_user']);
      session_regenerate_id(true);
      $_SESSION['rhu_admin_authenticated'] = true;
          unset($_SESSION['staff_screen_lock'], $_SESSION['staff_lock_csrf'], $_SESSION['staff_lock_failures'], $_SESSION['staff_lock_retry_after']);
      if ($user) {
        $_SESSION['user'] = $user;
      }
      unset($_SESSION['pending_admin_user'], $_SESSION['mfa_otp'], $_SESSION['mfa_otp_expires']);

      if (!empty($pdo) && !empty($user['user_id'])) {
        try {
          $upd = $pdo->prepare('UPDATE health_workers SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
          $upd->execute(['id' => $user['user_id']]);
          portalAudit($pdo, (int)$user['user_id'], 'RHU Admin Login (Strict 2FA Verified)', 'health_workers', (int)$user['user_id']);
        } catch (Exception $e) {
          error_log('RHUAdminLogin audit error: ' . $e->getMessage());
        }
      }

      header('Location: RHUAdminDashboard.php');
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="manifest.php?portal=staff&amp;v=20261003-icon-1">
  <meta name="theme-color" content="#0f766e">
  <meta name="apple-mobile-web-app-title" content="RHU Staff">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <link rel="apple-touch-icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1">
  <link rel="icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1" type="image/png" sizes="192x192">
  <link rel="shortcut icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1" type="image/png">
  <title>RHU Administrator Login | ResiHUnity RHU</title>
  <script src="assets/js/pwa-install.js?v=20260916" defer></script>
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Tailwind CSS -->
  <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
  <style>html, .font-sans { font-family: "Plus Jakarta Sans", sans-serif; }</style>
  <link rel="stylesheet" href="styles/rhu-login.css">
</head>
<body class="worker-login-shell min-h-screen text-stone-950 font-sans antialiased">
  <main class="relative z-10 flex min-h-screen items-center justify-center px-2.5 py-12 sm:px-4">
    <section class="worker-login-card grid w-full max-w-5xl overflow-hidden rounded-[1.75rem] shadow-2xl md:grid-cols-[1.05fr_.95fr]">
            <div class="worker-brand-panel relative min-h-[20rem] overflow-hidden p-8 text-white md:min-h-[34rem] md:p-10">
                <div class="relative z-10 flex h-full flex-col justify-between">
                    <a href="rhulandingpage.php" class="inline-flex w-fit items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-green-800 shadow-lg sm:h-14 sm:w-14">
                            <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity RHU" class="h-8 w-8 rounded-full object-cover sm:h-10 sm:w-10">
                        </span>
                        <span>
                            <span class="block text-sm font-extrabold leading-tight sm:text-lg">ResiHUnity</span>
                            <span class="block text-[10px] font-semibold text-green-50/85 sm:text-xs">Administrator Access</span>
                        </span>
                    </a>

                    <div class="max-w-sm py-5 sm:py-10 md:py-0">
                        <p class="text-[11px] font-bold uppercase tracking-[.22em] text-green-100/90 sm:text-sm">Admin Sign In</p>
                        <h1 class="mt-3 text-[1.7rem] font-black leading-[1.05] tracking-tight sm:text-4xl md:text-5xl">Municipal health administration.</h1>
                        <p class="mt-4 max-w-[18rem] text-[11px] font-semibold leading-5 text-green-50/90 sm:max-w-sm sm:text-sm sm:leading-6">Manage healthcare teams, resident records, and municipal health services.</p>
                    </div>

                    <div class="relative z-10 flex flex-wrap items-center gap-2 text-[9px] font-bold uppercase tracking-wide text-green-50/90 sm:gap-3 sm:text-[11px]">
                        <a href="rhulandingpage.php" class="rounded-full border border-white/30 px-3 py-2 hover:bg-white/10 sm:px-4">Back home</a>
                        <a href="RHULogin.php" class="rounded-full border border-white/30 px-3 py-2 hover:bg-white/10 sm:px-4">RHU Staff</a>
                    </div>
                </div>
            </div>


      <div class="worker-form-panel admin-form-panel relative overflow-hidden px-6 py-10 sm:px-10">
        <div class="relative z-10 mx-auto w-full max-w-sm space-y-5">
        
        <!-- Header -->
        <div class="text-center space-y-1.5">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-purple-500/10 border border-purple-500/20 text-purple-300 text-xs font-bold uppercase tracking-wider">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                MHO & Administrator Access
            </span>
            <h1 class="text-2xl font-extrabold text-white">admin login</h1>
            <p class="text-xs text-slate-400">Municipal Health Office - Nasugbu RHU I</p>
        </div>

        <?php if ($error): ?>
          <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs font-medium flex items-center gap-2.5">
            <svg class="w-4 h-4 text-rose-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endif; ?>

        <?php if ($infoMsg): ?>
          <div class="p-3.5 rounded-2xl bg-purple-500/10 border border-purple-500/20 text-purple-300 text-xs font-medium flex items-start gap-2.5 leading-relaxed">
            <svg class="w-4 h-4 text-purple-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <div><?= htmlspecialchars($infoMsg, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php endif; ?>

        <?php if (!$activeAdmin): ?>
          <!-- NO ACTIVE ADMIN IN DB -> REGISTRATION REQUIRED -->
          <div class="text-center py-4 space-y-4">
            <div class="w-16 h-16 bg-purple-900/50 border border-purple-500/40 rounded-2xl flex items-center justify-center mx-auto text-purple-300 text-2xl shadow-lg">
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            </div>
            <div>
              <h2 class="text-lg font-bold text-white">No System Administrator Account Found</h2>
              <p class="text-xs text-slate-300 mt-1">There is currently no active System Administrator account registered in the database.</p>
            </div>
            <a href="RHUAdminRegister.php" class="inline-block w-full py-3.5 rounded-xl bg-linear-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 font-bold text-xs text-white shadow-xl shadow-purple-600/25 transition-all">
              + Register System Administrator Account
            </a>
          </div>
        <?php else: ?>
          <!-- STEP INDICATOR -->
          <div class="flex items-center justify-between gap-2 text-xs py-1">
            <div class="flex items-center gap-2">
              <span class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold <?= $step === 'credentials' ? 'bg-purple-600 text-white' : 'bg-emerald-500 text-white' ?>">
                <?php if ($step === 'credentials'): ?>1<?php else: ?>
                  <svg class="h-3.5 w-3.5 shrink-0" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>
                <?php endif; ?>
              </span>
              <span class="<?= $step === 'credentials' ? 'text-slate-200 font-semibold' : 'text-emerald-400 font-semibold' ?>">
                Credentials
              </span>
            </div>
            <div class="h-px flex-1 bg-slate-800"></div>
            <div class="flex items-center gap-2">
              <span class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold <?= $step === 'mfa' ? 'bg-purple-600 text-white' : 'bg-slate-800 text-slate-500' ?>">
                2
              </span>
              <span class="<?= $step === 'mfa' ? 'text-slate-200 font-semibold' : 'text-slate-500' ?>">
                2FA Verification
              </span>
            </div>
          </div>

          <?php if ($step === 'credentials'): ?>
            <form method="post" class="space-y-4">
              <input type="hidden" name="step" value="credentials">
              
              <div class="space-y-1.5">
                <label for="admin-email" class="block text-xs font-bold text-slate-300 uppercase tracking-wider">Admin Email Address</label>
                <div class="relative">
                  <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                  </span>
                  <input id="admin-email" required name="email" type="email" autocomplete="username" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-xl border border-slate-700 bg-slate-800 text-white placeholder-slate-400 pl-10 pr-4 py-3 text-sm font-medium focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none transition-all" placeholder="registered.admin@nasugbu.gov.ph">
                </div>
              </div>

              <div class="space-y-1.5">
                <label for="pwd" class="block text-xs font-bold text-slate-300 uppercase tracking-wider">Password</label>
                <div class="relative">
                  <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  </span>
                  <input id="pwd" required name="password" type="password" autocomplete="current-password" class="w-full rounded-xl border border-slate-700 bg-slate-800 text-white placeholder-slate-400 pl-10 pr-12 py-3 text-sm font-medium focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none transition-all" placeholder="Enter admin password">
                  <button type="button" id="togglePwd" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-slate-400 hover:text-purple-300 transition-colors">Show</button>
                </div>
              </div>

              <div class="pt-2">
                <button type="submit" class="w-full py-3.5 px-4 rounded-xl bg-linear-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold text-sm shadow-lg shadow-purple-600/25 transition-all flex items-center justify-center gap-2">
                  <span>Send 2FA Verification Code</span>
                  <svg class="w-4 h-4 shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
                </button>
              </div>
            </form>
          <?php else: ?>
            <form method="post" class="space-y-4">
              <input type="hidden" name="step" value="mfa">
              <div class="rounded-2xl border border-purple-500/20 bg-purple-500/10 p-4 text-center space-y-1">
                <span class="inline-block p-2 rounded-xl bg-purple-500/20 text-purple-300 mb-1">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </span>
                <b class="block text-sm text-purple-200">Two-Factor Security Verification</b>
                <p class="text-xs text-slate-300">Enter the 6-digit verification code emailed to <strong><?= htmlspecialchars($_SESSION['rhu_admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>.</p>
              </div>

              <div class="space-y-1.5">
                <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider text-center">6-Digit 2FA Security Code</label>
                <input required name="mfaCode" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" class="w-full rounded-xl border border-slate-700 bg-slate-800 px-4 py-4 text-center font-mono text-3xl tracking-[0.4em] text-white focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none transition-all" placeholder="------" autofocus>
              </div>

              <div class="pt-2">
                <button type="submit" class="w-full py-3.5 px-4 rounded-xl bg-linear-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold text-sm shadow-lg shadow-purple-600/25 transition-all flex items-center justify-center gap-2">
                  <span>Verify Code &amp; Access Console</span>
                  <svg class="h-4 w-4 shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
                </button>
              </div>

              <div class="flex items-center justify-between text-xs text-slate-400 pt-2">
                <a href="RHUAdminLogin.php?action=resend" class="text-purple-300 hover:underline">Resend 2FA Code</a>
                <a href="RHUAdminLogin.php" class="inline-flex items-center gap-1.5 hover:underline"><svg class="h-4 w-4 shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 12H5m7-7-7 7 7 7"/></svg><span>Change Email</span></a>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Warning Alert -->
        <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-300 flex items-start gap-2.5 leading-relaxed">
          <svg class="w-4 h-4 text-amber-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          <span>Restricted to authorized DOH/LGU personnel. Sessions are monitored and logged per RA 10173.</span>
        </div>

        <!-- Footer Links -->
        <div class="pt-1 text-center">
            <span class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Other Portals</span>
            <div class="flex items-center justify-center gap-3 text-xs text-slate-300">
                <a href="RHULogin.php" class="flex w-full items-center justify-center rounded-xl border border-stone-200 bg-white px-3 py-2.5 font-bold text-green-950 hover:bg-green-50">RHU Staff Login</a>
                <span class="text-slate-700">&bull;</span>
                <a href="BHWLogin.php" class="hover:text-purple-400 hover:underline">BHW Portal</a>
                <span class="text-slate-700">&bull;</span>
                <a href="ResidentLogin.php" class="hover:text-purple-400 hover:underline">Resident Portal</a>
            </div>
        </div>

        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="p-6 text-center text-xs text-slate-500 relative z-10">
      <p>Â© <?= date('Y') ?> Nasugbu Rural Health Unit I. Republic Act 10173 Data Privacy & DOH Security Policy Compliant.</p>
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
