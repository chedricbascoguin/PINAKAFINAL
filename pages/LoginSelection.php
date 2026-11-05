<?php
if (session_status() === PHP_SESSION_NONE) session_start();

header('Location: rhulandingpage.php');
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/images/favicon.ico?v=20260915" type="image/x-icon">
    <link rel="shortcut icon" href="assets/images/favicon.ico?v=20260915" type="image/x-icon">
    <title>Staff & Admin Access - ResiHUnity RHU</title>
    <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
    <link rel="stylesheet" href="styles/login-theme.css">
</head>
<body class="bg-linear-to-br from-slate-900 via-slate-800 to-gray-900 min-h-screen font-sans flex items-center justify-center antialiased">
    <div class="max-w-4xl w-full px-4 py-8">

        <!-- Header -->
        <div class="text-center mb-10">
            <a href="LandingPage.php" class="inline-flex items-center justify-center gap-3 mb-5 group">
                <img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity Logo" class="h-12 w-auto object-contain rounded-xl bg-white/10 p-1 shadow-md group-hover:scale-105 transition-transform" />
                <span class="text-3xl font-extrabold text-white tracking-tight">ResiHUnity RHU</span>
            </a>
            <div class="inline-flex items-center gap-2 bg-yellow-500/15 border border-yellow-500/30 rounded-full px-4 py-1.5 mb-4">
                <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-xs font-bold text-yellow-300">Authorized Personnel Only</span>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Staff &amp; Admin Access</h1>
            <p class="text-slate-400 text-sm">Select your role to proceed. Restricted to authorized RHU healthcare personnel.</p>
        </div>

        <!-- Primary RHU Roles (2 Columns: RHU Staff & MHO/Admin) -->
        <div class="grid md:grid-cols-2 gap-6 mb-8">

            <!-- RHU Staff -->
            <a href="RHULogin.php" class="group bg-white/5 hover:bg-white/10 border border-white/10 hover:border-blue-500/50 rounded-2xl p-8 transition-all duration-200 hover:-translate-y-0.5">
                <div class="flex flex-col items-center text-center">
                    <div class="w-16 h-16 bg-linear-to-br from-blue-500 to-blue-700 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-105 transition-transform shadow-lg">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.452a6 6 0 00-5.011 1.595h-.209V7a4 4 0 016.364 3.536v9.636l.75.75a2 2 0 11-2.828 2.828l-.75-.75v-.009a6 6 0 00-6-6v.009a4 4 0 00 6.364 3.536h.209V7a6 6 0 00-1.595 5.011l.452 2.387a2 2 0 00.547 1.022l2.387.452a6 6 0 005.011-1.595h.209v6.25a4 4 0 01-6.364-3.536"></path>
                        </svg>
                    </div>
                    <h2 class="text-xl font-bold text-white mb-2">RHU Medical Staff</h2>
                    <p class="text-slate-400 text-xs mb-5">Physicians, nurses, midwives, medical technologists, and sanitary inspectors</p>
                    <span class="inline-flex items-center gap-1.5 text-blue-400 font-bold text-sm group-hover:gap-2.5 transition-all">
                        Staff Login &rarr;
                    </span>
                </div>
            </a>

            <!-- RHU Admin -->
            <a href="RHUAdminLogin.php" class="group bg-white/5 hover:bg-white/10 border border-white/10 hover:border-purple-500/50 rounded-2xl p-8 transition-all duration-200 hover:-translate-y-0.5">
                <div class="flex flex-col items-center text-center">
                    <div class="w-16 h-16 bg-linear-to-br from-purple-600 to-slate-800 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-105 transition-transform shadow-lg">
                        <svg class="w-8 h-8 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h2 class="text-xl font-bold text-white mb-2">MHO / System Admin</h2>
                    <p class="text-slate-400 text-xs mb-5">Municipal Health Officer &amp; System Administrator â€” full clinical + system management</p>
                    <span class="inline-flex items-center gap-1.5 text-purple-400 font-bold text-sm group-hover:gap-2.5 transition-all">
                        Admin Panel &rarr;
                    </span>
                </div>
            </a>

        </div>

        <!-- Security Notice -->
        <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6 flex items-start gap-3">
            <svg class="w-5 h-5 text-yellow-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-xs text-yellow-300">
                Unauthorized access is a violation of the Data Privacy Act (RA 10173) and DOH IT Security Policy. All login attempts are logged and monitored.
            </p>
        </div>

        <!-- Resident redirect -->
        <div class="text-center space-y-3">
            <div class="bg-emerald-900/30 border border-emerald-700/30 rounded-xl p-4 inline-flex items-center gap-3">
                <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <p class="text-xs text-emerald-300">
                    Are you a resident looking for your health records?
                    <a href="ResidentLogin.php" class="font-bold text-emerald-400 hover:underline">Go to the Resident Portal &rarr;</a>
                </p>
            </div>
            <div>
                <a href="LandingPage.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-slate-300 font-medium text-xs transition-colors">
                    &larr; Back to Home
                </a>
            </div>
        </div>

    </div>
</body>
</html>
