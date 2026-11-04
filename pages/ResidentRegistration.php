<?php
if (session_status() === PHP_SESSION_NONE) session_start();
@include_once dirname(__DIR__) . '/db.php';
@include_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/resident-account-linking.php';

if (!function_exists('e')) {
    function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('residentRegistrationLoginUrl')) {
    function residentRegistrationLoginUrl(): string {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $protocol = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/ResidentRegistration.php';
        $scriptDir = trim(str_replace('\\', '/', dirname($scriptName)), '/');
        $path = ($scriptDir !== '' && $scriptDir !== '.') ? '/' . $scriptDir . '/ResidentLogin.php' : '/ResidentLogin.php';
        return $protocol . '://' . $host . $path;
    }
}

$flash = '';
$error = '';
$duplicateWarning = false;
$duplicateConfirmationToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $philhealth_no = trim($_POST['philhealth_no'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $blood_type = trim($_POST['blood_type'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');
    $medical_conditions = trim($_POST['medical_conditions'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
    $dob = trim($_POST['dob'] ?? '');

    if ($first_name === '' || $last_name === '' || $email === '' || $password === '' || $dob === '' || $barangay === '' || $address === '') {
        $error = 'First name, last name, email, password, date of birth, barangay, and address are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($password !== $confirm_password) {
        $error = 'Password and confirmation do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        if (isset($pdo) && $pdo) {
            try {
                if (!portalVerifyCsrf()) throw new RuntimeException('Form expired. Refresh and try again.');
                residentLinkEnsure($pdo);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT id FROM residents WHERE LOWER(email) = LOWER(:email) AND (COALESCE(password_hash, '') <> '' OR LOWER(TRIM(first_name)) <> LOWER(:first_name) OR LOWER(TRIM(last_name)) <> LOWER(:last_name) OR birthdate <> :dob) LIMIT 1");
                $stmt->execute(['email' => $email, 'first_name'=>$first_name, 'last_name'=>$last_name, 'dob'=>$dob]);
                if ($stmt->fetch()) {
                    throw new Exception('A resident account with that email address already exists.');
                }

                $match = $pdo->prepare('SELECT id, password_hash FROM residents WHERE LOWER(TRIM(first_name)) = LOWER(:first_name) AND LOWER(TRIM(last_name)) = LOWER(:last_name) AND birthdate = :dob');
                $match->execute(['first_name' => $first_name, 'last_name' => $last_name, 'dob' => $dob]);
                $matchedResidents = $match->fetchAll(PDO::FETCH_ASSOC);
                if ($matchedResidents) {
                    if (!array_filter($matchedResidents, static fn($resident) => empty($resident['password_hash']))) {
                        throw new RuntimeException('An account already exists for this patient. Please sign in or use password recovery.');
                    }
                    $pending=$pdo->prepare("SELECT id FROM resident_account_requests WHERE LOWER(email)=LOWER(?) AND status='Pending' LIMIT 1");
                    $pending->execute([$email]);
                    if (!$pending->fetchColumn()) {
                        $request=$pdo->prepare('INSERT INTO resident_account_requests(first_name,last_name,birthdate,email,phone,barangay,password_hash) VALUES(?,?,?,?,?,?,?)');
                        $request->execute([$first_name,$last_name,$dob,$email,$phone,$barangay,password_hash($password,PASSWORD_DEFAULT)]);
                    }
                    $pdo->commit();
                    $_SESSION['resident_registration_flash']='Your account-link request was submitted. Visit the RHU with your patient ID or identification so staff can verify you. After approval, sign in using the email and password you entered; your existing RHU records will be available.';
                    header('Location: ResidentLogin.php');
                    exit;
                }
                $pending=$pdo->prepare("SELECT id FROM resident_account_requests WHERE LOWER(email)=LOWER(?) AND status='Pending' LIMIT 1");
                $pending->execute([$email]);
                if ($pending->fetchColumn()) throw new RuntimeException('An account-link request for this email is awaiting RHU verification.');

                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $barangayId = function_exists('rhuBarangayId') ? rhuBarangayId($pdo, $barangay) : 1;
                $username = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0])) . rand(100, 999);
                $sex = $sex !== '' ? $sex : 'Other';
                $civil_status = $civil_status !== '' ? $civil_status : 'Single';

                // Only residents without an existing identity receive a new patient profile.
                $stmt = $pdo->prepare('INSERT INTO residents (first_name, middle_name, last_name, birthdate, sex, civil_status, contact_number, email, username, password_hash, address, barangay_id, philhealth_number, national_id, blood_type, allergies, medical_conditions, emergency_contact_name, emergency_contact_number, status, created_at) VALUES (:first_name, :middle_name, :last_name, :dob, :sex, :civil_status, :phone, :email, :username, :password_hash, :address, :barangay_id, :philhealth_number, :national_id, :blood_type, :allergies, :medical_conditions, :emergency_contact_name, :emergency_contact_number, "Active", NOW())');
                $stmt->execute([
                    'first_name' => $first_name,
                    'middle_name' => $middle_name,
                    'last_name' => $last_name,
                    'dob' => $dob ?: null,
                    'sex' => $sex,
                    'civil_status' => $civil_status,
                    'phone' => $phone,
                    'email' => $email,
                    'username' => $username,
                    'password_hash' => $password_hash,
                    'address' => $address,
                    'barangay_id' => $barangayId,
                    'philhealth_number' => $philhealth_no,
                    'national_id' => $national_id,
                    'blood_type' => $blood_type,
                    'allergies' => $allergies,
                    'medical_conditions' => $medical_conditions,
                    'emergency_contact_name' => $emergency_contact_name,
                    'emergency_contact_number' => $emergency_contact_number,
                ]);

                $pdo->commit();
                unset($_SESSION['resident_registration_duplicate']);

                // Dispatch Welcome Email
                @include_once dirname(__DIR__) . '/includes/mailer.php';
                if (function_exists('sendRHUEmail')) {
                    $welcomeSubject = "Welcome to ResiHUnity RHU Resident Portal!";
                    $residentLoginUrl = residentRegistrationLoginUrl();
                    $welcomeBody = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #cbd5e1; border-radius: 16px; background-color: #ffffff;'>
                            <div style='text-align: center; border-bottom: 2px solid #0f766e; padding-bottom: 16px; margin-bottom: 20px;'>
                                <h2 style='color: #0f766e; margin: 0; font-size: 20px;'>Nasugbu Rural Health Unit I</h2>
                                <p style='color: #64748b; font-size: 13px; margin: 4px 0 0 0;'>Official Resident Portal Registration</p>
                            </div>
                            <p style='font-size: 14px; color: #1e293b;'>Hello <strong>" . htmlspecialchars($first_name . ' ' . $last_name) . "</strong>,</p>
                            <p style='font-size: 14px; color: #334155;'>Your resident account for <strong>" . htmlspecialchars($barangay) . "</strong> has been created successfully!</p>
                            <p style='font-size: 13px; color: #475569;'>You can now log into your Resident Dashboard to request OPD physician consultations, view health records, request health certificates, and access 24/7 emergency services.</p>
                            <p style='text-align: center; margin-top: 24px;'>
                                <a href='" . htmlspecialchars($residentLoginUrl, ENT_QUOTES, 'UTF-8') . "' style='background-color: #0d9488; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 10px; font-weight: bold; font-size: 14px; display: inline-block;'>Log In to Resident Portal</a>
                            </p>
                        </div>
                    ";
                    sendRHUEmail($email, $welcomeSubject, $welcomeBody);
                }

                $_SESSION['resident_registration_flash'] = 'Account created successfully! You can now sign in to your Resident Portal.';
                header('Location: ResidentLogin.php');
                exit;
            } catch (Exception $ex) {
                if (isset($pdo) && $pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!$duplicateWarning) {
                    error_log('ResidentRegistration: ' . $ex->getMessage());
                    $error = $ex->getMessage();
                }
            }
        } else {
            $error = 'The database is unavailable. Please try again later.';
        }
    }
}

$flash = $_SESSION['resident_registration_flash'] ?? '';
unset($_SESSION['resident_registration_flash']);

$prefillData = [
    'first_name' => trim((string)($_POST['first_name'] ?? $_GET['first_name'] ?? '')),
    'middle_name' => trim((string)($_POST['middle_name'] ?? $_GET['middle_name'] ?? '')),
    'last_name' => trim((string)($_POST['last_name'] ?? $_GET['last_name'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? $_GET['email'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? $_GET['phone'] ?? '')),
    'barangay' => trim((string)($_POST['barangay'] ?? $_GET['barangay'] ?? '')),
    'address' => trim((string)($_POST['address'] ?? $_GET['address'] ?? '')),
    'sex' => trim((string)($_POST['sex'] ?? $_GET['sex'] ?? '')),
    'civil_status' => trim((string)($_POST['civil_status'] ?? $_GET['civil_status'] ?? '')),
    'philhealth_no' => trim((string)($_POST['philhealth_no'] ?? $_GET['philhealth_no'] ?? '')),
    'national_id' => trim((string)($_POST['national_id'] ?? $_GET['national_id'] ?? '')),
    'blood_type' => trim((string)($_POST['blood_type'] ?? $_GET['blood_type'] ?? '')),
    'allergies' => trim((string)($_POST['allergies'] ?? $_GET['allergies'] ?? '')),
    'medical_conditions' => trim((string)($_POST['medical_conditions'] ?? $_GET['medical_conditions'] ?? '')),
    'emergency_contact_name' => trim((string)($_POST['emergency_contact_name'] ?? $_GET['emergency_contact_name'] ?? '')),
    'emergency_contact_number' => trim((string)($_POST['emergency_contact_number'] ?? $_GET['emergency_contact_number'] ?? '')),
    'dob' => trim((string)($_POST['dob'] ?? $_GET['dob'] ?? '')),
];
$dependentPregnantSuggestion = !empty($_GET['dependent_pregnant']) || !empty($_POST['dependent_pregnant']);

$dbBarangays = [];
if (isset($pdo) && $pdo) {
    try {
        $dbBarangays = $pdo->query("SELECT name FROM barangays ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $eBrgy) {
        error_log('Barangay fetch error: ' . $eBrgy->getMessage());
    }
}
if (empty($dbBarangays)) {
    $dbBarangays = ['Aga','Anilao','Balaytigue','Balibago','Banilad','Barangay 1 (Pob.)','Barangay 2 (Pob.)','Barangay 3 (Pob.)','Barangay 4 (Pob.)','Bilaran','Bucana','Bulihan','Calayo','Catandaan','Cogunan','Dayap','Halang','Kaylaway','Looc','Lumbangan','Mabini','Nagsabaran','Natipuan','Pantalan','Poblacion','Wawa'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Resident Registration | ResiHUnity RHU</title>
  <link rel="manifest" href="manifest.php?v=20261003-icon-1">
  <meta name="theme-color" content="#0f766e">
  <link rel="apple-touch-icon" href="assets/images/resident-icon-192.png?v=20261003-icon-1">
  <link rel="icon" href="assets/images/resident-icon-192.png?v=20261003-icon-1" type="image/png" sizes="192x192">
  <link rel="shortcut icon" href="assets/images/resident-icon-192.png?v=20261003-icon-1" type="image/png">
  <script src="assets/js/pwa-install.js?v=20261003" defer></script>
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Tailwind CSS -->
  <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
  <style>html, .font-sans { font-family: "Plus Jakarta Sans", sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen font-sans flex flex-col justify-between antialiased">
  
  <!-- Navigation Header -->
  <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
      <a href="index.php" class="flex items-center gap-3 group">
        <div class="w-10 h-10 rounded-xl bg-teal-700 flex items-center justify-center text-white font-extrabold text-lg shadow-md group-hover:bg-teal-800 transition-all">
          R
        </div>
        <div>
          <span class="font-extrabold text-slate-900 text-lg tracking-tight">ResiHUnity</span>
          <span class="text-xs text-teal-700 font-bold block -mt-1">Nasugbu RHU Portal</span>
        </div>
      </a>
      <a href="ResidentLogin.php" class="text-sm font-semibold text-teal-700 hover:text-teal-900 transition-colors">
        Already have an account? Sign in &rarr;
      </a>
    </div>
  </header>

  <!-- Main Registration Section -->
  <main class="max-w-2xl mx-auto w-full px-4 sm:px-6 py-8">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-200 p-6 sm:p-10">
      
      <div class="text-center mb-8">
        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Create Resident Account</h1>
        <p class="text-sm text-slate-500 mt-2">Register to schedule OPD consultations, access medical records, and request health certificates.</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-xs font-semibold text-red-800 flex items-start gap-3">
          <span class="text-red-500 font-bold text-base">⚠️</span>
          <div><?= e($error) ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($flash)): ?>
        <div class="mb-6 rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-xs font-semibold text-emerald-800 flex items-start gap-3">
          <span class="text-emerald-500 font-bold text-base">✅</span>
          <div><?= e($flash) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($dependentPregnantSuggestion): ?>
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs font-semibold text-amber-800 flex items-start gap-3">
          <span class="text-amber-500 font-bold text-base">⚠️</span>
          <div>This account is being created for a pregnant dependent. Keep this account separate from the family account to protect sensitive health information and maintain privacy.</div>
        </div>
      <?php endif; ?>

      <form method="post" action="ResidentRegistration.php" class="space-y-5 text-sm">
        <input type="hidden" name="csrf_token" value="<?= e(portalCsrfToken()) ?>">
        <p class="text-xs text-slate-600">Already visited the RHU? Use the same name and birth date as your patient record. Existing records require staff identity verification before your account is activated.</p>

        
        <!-- Full Name -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">First Name *</label>
            <input required type="text" name="first_name" value="<?= e($prefillData['first_name']) ?>" placeholder="Juan" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Middle Name</label>
            <input type="text" name="middle_name" value="<?= e($prefillData['middle_name']) ?>" placeholder="(Optional)" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Last Name *</label>
            <input required type="text" name="last_name" value="<?= e($prefillData['last_name']) ?>" placeholder="Dela Cruz" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
        </div>

        <!-- Contact & Email -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Email Address *</label>
            <input required type="email" name="email" value="<?= e($prefillData['email']) ?>" placeholder="juan.delacruz@gmail.com" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Mobile / Phone Number</label>
            <input type="text" name="phone" value="<?= e($prefillData['phone']) ?>" placeholder="09171234567" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
        </div>

        <!-- DOB, Sex, Civil Status -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Date of Birth *</label>
            <input required type="date" name="dob" max="<?= date('Y-m-d') ?>" value="<?= e($prefillData['dob']) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all bg-white">
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Sex</label>
            <select name="sex" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all bg-white">
              <option value="">Prefer not to say</option>
              <?php foreach (['Male', 'Female', 'Other'] as $option): ?>
                <option value="<?= e($option) ?>" <?= ($prefillData['sex'] === $option) ? 'selected' : '' ?>><?= e($option) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Civil Status</label>
            <select name="civil_status" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all bg-white">
              <option value="">Prefer not to say</option>
              <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Live-in'] as $option): ?>
                <option value="<?= e($option) ?>" <?= ($prefillData['civil_status'] === $option) ? 'selected' : '' ?>><?= e($option) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Health & ID Info -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">PhilHealth ID No.</label>
            <input type="text" name="philhealth_no" value="<?= e($prefillData['philhealth_no']) ?>" placeholder="12-345678901-2" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">National ID No.</label>
            <input type="text" name="national_id" value="<?= e($prefillData['national_id']) ?>" placeholder="(Optional)" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Blood Type</label>
            <select name="blood_type" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all bg-white">
              <option value="">Unknown</option>
              <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $option): ?>
                <option value="<?= e($option) ?>" <?= ($prefillData['blood_type'] === $option) ? 'selected' : '' ?>><?= e($option) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Known Allergies</label>
            <textarea name="allergies" rows="2" placeholder="(Optional) e.g. Penicillin, Seafood" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all resize-none"><?= e($prefillData['allergies']) ?></textarea>
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Medical Conditions</label>
            <textarea name="medical_conditions" rows="2" placeholder="(Optional) e.g. Asthma, Hypertension" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all resize-none"><?= e($prefillData['medical_conditions']) ?></textarea>
          </div>
        </div>

        <!-- Emergency Contact -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Emergency Contact Name</label>
            <input type="text" name="emergency_contact_name" value="<?= e($prefillData['emergency_contact_name']) ?>" placeholder="(Optional)" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Emergency Contact Number</label>
            <input type="text" name="emergency_contact_number" value="<?= e($prefillData['emergency_contact_number']) ?>" placeholder="(Optional)" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
        </div>

        <!-- Barangay & Address -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Barangay Location *</label>
            <select required name="barangay" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all bg-white">
              <option value="">-- Select Barangay --</option>
              <?php foreach ($dbBarangays as $b): ?>
                <option value="<?= e($b) ?>" <?= ($prefillData['barangay'] === $b) ? 'selected' : '' ?>><?= e($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Complete Address / Sitio *</label>
            <input required type="text" name="address" value="<?= e($prefillData['address']) ?>" placeholder="House No., Street Name" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
        </div>

        <!-- Password -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Password *</label>
            <input required type="password" name="password" placeholder="At least 6 characters" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
          <div>
            <label class="block font-bold text-slate-700 mb-1">Confirm Password *</label>
            <input required type="password" name="confirm_password" placeholder="Re-enter password" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 placeholder-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 outline-none transition-all">
          </div>
        </div>

        <div class="pt-2">
          <button type="submit" class="w-full rounded-xl bg-teal-700 hover:bg-teal-800 text-white font-extrabold py-3.5 px-6 shadow-lg shadow-teal-700/20 transition-all cursor-pointer">
            Create Account &amp; Enter Resident Portal
          </button>
        </div>

      </form>

    </div>
  </main>

  <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500">
    &copy; <?= date('Y') ?> Nasugbu Rural Health Unit I · Official Resident Portal
  </footer>

</body>
</html>
