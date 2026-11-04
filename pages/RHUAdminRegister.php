<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/includes/portal.php';

function getActiveAdminCount(?PDO $pdo): int {
  if (!$pdo) return 0;
  try {
    $stmt = $pdo->query("
      SELECT COUNT(*) 
      FROM health_workers
      WHERE status = 'Active'
      AND (UPPER(position_title) IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')
           OR UPPER(position_title) LIKE '%MUNICIPAL HEALTH OFFICER%'
           OR UPPER(role) = 'ADMIN')
    ");
    return (int) ($stmt ? $stmt->fetchColumn() : 0);
  } catch (Exception $e) {
    return 0;
  }
}

// If an active Admin account ALREADY EXISTS in the database, registration is locked!
$activeAdminCount = getActiveAdminCount($pdo);
if ($activeAdminCount > 0) {
  $_SESSION['admin_login_flash'] = 'An active System Administrator account already exists in the database. Registration is disabled.';
  header('Location: RHUAdminLogin.php');
  exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $firstName = trim($_POST['first_name'] ?? '');
  $lastName = trim($_POST['last_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $contact = trim($_POST['contact_number'] ?? '');
  $designation = trim($_POST['designation'] ?? 'Municipal Health Officer');
  $licenseNo = trim($_POST['license_number'] ?? '');
  $password = $_POST['password'] ?? '';
  $confirmPassword = $_POST['confirm_password'] ?? '';

  if (!$firstName || !$lastName || !$email || !$password || !$confirmPassword) {
    $error = 'All fields marked with an asterisk (*) are required.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email address.';
  } elseif ($password !== $confirmPassword) {
    $error = 'Password and Confirm Password do not match.';
  } elseif (strlen($password) < 6) {
    $error = 'Password must be at least 6 characters long.';
  } elseif (empty($pdo)) {
    $error = 'Database connection failed. Please make sure XAMPP MySQL is running.';
  } else {
    try {
      $chk = $pdo->prepare('SELECT id FROM health_workers WHERE email = :email LIMIT 1');
      $chk->execute(['email' => $email]);
      if ($chk->fetch()) {
        $error = 'An account with this email address already exists in the database.';
      } else {
        // Generate Unique Admin Code
        $adminCode = $licenseNo ?: ('ADM-' . date('Y') . '-' . sprintf('%04d', mt_rand(1000, 9999)));
        $username = strtok($email, '@');
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $insUser = $pdo->prepare('
          INSERT INTO health_workers (username, email, password_hash, first_name, last_name, role, position_title, license_number, contact_number, status, created_at)
          VALUES (:username, :email, :password_hash, :first_name, :last_name, "Admin", "RHU_ADMIN", :license_number, :contact_number, "Active", NOW())
        ');
        $insUser->execute([
          'username' => $username,
          'email' => $email,
          'password_hash' => $hash,
          'first_name' => $firstName,
          'last_name' => $lastName,
          'license_number' => $adminCode,
          'contact_number' => $contact,
        ]);
        $newUserId = (int)$pdo->lastInsertId();

        portalAudit($pdo, $newUserId, "Initial RHU Admin Account Registered (Code: {$adminCode})", 'health_workers', $newUserId);

        $_SESSION['admin_login_flash'] = "System Administrator account registered successfully! (Admin ID: {$adminCode}). You may now sign in using your email and password.";
        header('Location: RHUAdminLogin.php');
        exit;
      }
    } catch (PDOException $e) {
      error_log('RHUAdminRegister error: ' . $e->getMessage());
      $error = 'Failed to register admin account: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="manifest.php?v=20261003-icon-1">
  <meta name="theme-color" content="#0f766e">
  <link rel="apple-touch-icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1">
  <link rel="icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1" type="image/png" sizes="192x192">
  <link rel="shortcut icon" href="assets/images/pwa-icon-192.png?v=20261003-icon-1" type="image/png">
  <title>Initial Admin Registration - ResiHUnity RHU</title>
  <script src="assets/js/pwa-install.js?v=20260916" defer></script>
  <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
  <link rel="stylesheet" href="../../styles/login-theme.css">
</head>

<body class="flex min-h-screen items-center justify-center bg-linear-to-br from-slate-900 via-purple-950 to-slate-900 px-4 py-8 text-white">
  <main class="w-full max-w-lg">
    <header class="mb-6 text-center">
      <a href="rhulandingpage.php" class="inline-flex items-center gap-2">
        <span class="text-4xl text-red-500">â™¥</span>
        <span class="text-3xl font-bold">ResiHUnity RHU</span>
      </a>
      <p class="mt-4 inline-block rounded-full border border-purple-700 bg-purple-900/60 px-4 py-1.5 text-xs font-bold text-purple-200">â™¢ One-Time System Setup</p>
      <h1 class="mt-2 text-2xl font-bold">System Administrator Registration</h1>
      <p class="text-xs text-slate-300">No active administrator was detected in the database. Register the primary RHU System Admin account below.</p>
    </header>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-8 backdrop-blur-sm shadow-2xl">
      <?php if ($error): ?>
        <div class="mb-5 rounded-lg border border-red-500/30 bg-red-500/10 p-3.5 text-sm text-red-300">
          <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post" class="space-y-4 text-xs">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block font-semibold text-slate-300 mb-1">First Name *</label>
            <input required name="first_name" type="text" value="<?= htmlspecialchars($_POST['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="e.g. Chedric">
          </div>
          <div>
            <label class="block font-semibold text-slate-300 mb-1">Last Name *</label>
            <input required name="last_name" type="text" value="<?= htmlspecialchars($_POST['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="e.g. Bascoguin">
          </div>
        </div>

        <div>
          <label class="block font-semibold text-slate-300 mb-1">Admin Email Address *</label>
          <input required name="email" type="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-white/10 bg-white/5 px-3.5 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="e.g. bascoguinchedric20@gmail.com">
          <p class="text-[11px] text-slate-400 mt-1">2-Factor Authentication (2FA) codes will be sent to this email upon login.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block font-semibold text-slate-300 mb-1">Contact Number</label>
            <input name="contact_number" type="text" value="<?= htmlspecialchars($_POST['contact_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="09171234567">
          </div>
          <div>
            <label class="block font-semibold text-slate-300 mb-1">Designation / Title</label>
            <input name="designation" type="text" value="<?= htmlspecialchars($_POST['designation'] ?? 'Municipal Health Officer', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="Municipal Health Officer">
          </div>
        </div>

        <div>
          <label class="block font-semibold text-slate-300 mb-1">PRC License / Unique Employee ID (Optional)</label>
          <input name="license_number" type="text" value="<?= htmlspecialchars($_POST['license_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-white/10 bg-white/5 px-3.5 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="Leave empty for auto-generated Admin Code (e.g. ADM-2026-1024)">
        </div>

        <div class="grid grid-cols-2 gap-3 pt-1">
          <div>
            <label class="block font-semibold text-slate-300 mb-1">Password *</label>
            <input required name="password" type="password" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="At least 6 characters">
          </div>
          <div>
            <label class="block font-semibold text-slate-300 mb-1">Confirm Password *</label>
            <input required name="confirm_password" type="password" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-white focus:border-purple-500 focus:outline-none" placeholder="Re-type password">
          </div>
        </div>

        <button class="w-full rounded-lg bg-linear-to-r from-purple-600 to-purple-700 py-3 font-semibold text-sm text-white hover:from-purple-500 hover:to-purple-600 shadow-lg mt-2">Complete Admin Registration â†’</button>
      </form>

      <aside class="mt-5 flex gap-2 rounded-lg border border-yellow-500/20 bg-yellow-500/10 p-3 text-xs text-yellow-300">
        <span>âš </span>Once registered, this registration screen will be locked. If the administrator is deactivated in the future, the system will re-enable registration for a new administrator.
      </aside>
    </section>

    <footer class="mt-6 text-center text-sm text-slate-500">
      <a href="RHUAdminLogin.php" class="hover:text-slate-300">â† Back to Admin Login</a>
    </footer>
  </main>
</body>

</html>
