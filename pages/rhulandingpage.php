<?php
/**
 * RHU Personnel Landing Page - Nasugbu Rural Health Unit I
 * Staff gateway for RHU personnel.
 */
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function e($v): string
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Resolve a local asset from this app's public folders. */
function rhu_asset(string $name): string
{
  $candidates = [
    dirname(__DIR__) . '/' . $name,
    dirname(__DIR__) . '/assets/' . $name,
  ];
  foreach ($candidates as $abs) {
    if (is_file($abs)) {
      return ltrim(str_replace('\\', '/', substr($abs, strlen(dirname(__DIR__)))), '/');
    }
  }
  return $name;
}

$seal = rhu_asset('assets/images/nasugbu_seal.png');
$logo = rhu_asset('assets/images/resihunity_logo.jpg');
$facilityImg = rhu_asset('rhu_building.jpg');
if ($facilityImg === 'rhu_building.jpg') {
  $facilityImg = rhu_asset('admin-municipal-background.png');
}

$rhu = [
  'name' => 'Nasugbu Rural Health Unit I',
  'short' => 'RHU Nasugbu I',
  'municipality' => 'Nasugbu',
  'province' => 'Batangas',
  'address' => 'RHU Nasugbu, J.P. Laurel St, Poblacion, Nasugbu, Batangas',
  'building' => 'Nasugbu Municipal Hall Annex Building',
  'contact' => '(043) 416-1234',
  'hours' => 'Mon-Fri: 8:00 AM - 5:00 PM',
  'mho' => 'Dra. Sarah De los Reyes-Marquez',
];

$portals = [
  [
    'title' => 'Healthcare Staff',
    'subtitle' => 'Clinical & Field Teams',
    'desc' => 'For physicians, nurses, midwives, medical technologists, and sanitary inspectors. Access role-based dashboards for patient care, laboratory work, and environmental health.',
    'href' => 'RHULogin.php',
    'cta' => 'Sign in to Staff Portal',
    'icon_from' => '#059669',
    'icon_to' => '#0f766e',
    'ring' => 'ring-emerald-500/15',
    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4" stroke-width="1.8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 8v6M16 11h6"/>',
  ],
  [
    'title' => 'System Administration',
    'subtitle' => 'RHU Admin Console',
    'desc' => 'Facility configuration, staff accounts, reports, announcements, and system oversight for authorized RHU administrators.',
    'href' => 'RHUAdminLogin.php',
    'cta' => 'Sign in to Admin',
    'icon_from' => '#334155',
    'icon_to' => '#020617',
    'ring' => 'ring-slate-500/15',
    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  ],
];

$roles = [
  ['Physician', 'OPD consultations, diagnosis, prescriptions, clinical referrals', 'bg-blue-50 text-blue-700 border-blue-100'],
  ['Public Health Nurse', 'Triage, vital signs, nursing care, immunization support', 'bg-indigo-50 text-indigo-700 border-indigo-100'],
  ['Rural Health Midwife', 'Maternal care, family planning, EPI, vital statistics', 'bg-indigo-50 text-blue-700 border-indigo-100'],
  ['Medical Technologist', 'Laboratory testing, specimen handling, supply monitoring', 'bg-violet-50 text-violet-700 border-violet-100'],
  ['Sanitary Inspector', 'Establishment inspections, water quality, sanitation notices', 'bg-teal-50 text-teal-700 border-teal-100'],
  ['RHU Administrator', 'Users, reports, facility settings, public portal content', 'bg-slate-50 text-slate-700 border-slate-200'],
];

$pillars = [
  ['Primary Care Services', 'Support outpatient care, maternal and child health, immunization, and continuity of treatment for Nasugbu residents.'],
  ['Environmental Health', 'Strengthen sanitation standards through inspections, water monitoring, food safety, and community education.'],
  ['Digital Health Records', 'Maintain accurate, role-protected health information to improve service delivery and reporting.'],
];

$pst = new DateTime('now', new DateTimeZone('Asia/Manila'));
$pstLabel = $pst->format('l, F d, Y \a\t g:i A');
?>
<!doctype html>
<html lang="en" class="scroll-smooth">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="<?= e($rhu_page_base ?? './') ?>">
  <title>RHU Personnel Portal | <?= e($rhu['name']) ?></title>
  <link rel="manifest" href="manifest.php?portal=staff&amp;v=20261003-icon-2">
  <meta name="theme-color" content="#0f766e">
  <meta name="apple-mobile-web-app-title" content="RHU Staff">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/resihunity-icon-192-v2.png">
  <link rel="icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png" sizes="192x192">
  <link rel="shortcut icon" href="assets/images/resihunity-icon-192-v2.png" type="image/png">
  <script src="assets/js/pwa-install.js?v=20261003" defer></script>
  <meta name="description"
    content="Official personnel access portal of Nasugbu Rural Health Unit I. Secure sign-in for administrators, and municipal health workers.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
  <style>html, .font-sans { font-family: "Plus Jakarta Sans", system-ui, sans-serif; }</style>
  <style>
    body {
      background: #f8fafc;
    }

    .govph-bar {
      background: #fff;
      border-bottom: 1px solid #e2e8f0;
    }

    .agency-banner {
      background: linear-gradient(90deg, #0b6b4f 0%, #0f766e 45%, #115e59 100%);
    }

    .hero-mesh {
      background:
        linear-gradient(135deg, rgba(7, 17, 28, .92) 0%, rgba(15, 23, 42, .9) 46%, rgba(19, 78, 74, .9) 100%),
        url('<?= e($facilityImg) ?>') center/cover no-repeat;
    }

    .glass-card {
      background: linear-gradient(145deg, rgba(255, 255, 255, .98) 0%, rgba(248, 250, 252, .96) 100%);
      border: 1px solid rgba(15, 23, 42, 0.07);
      box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
    }

    .portal-card {
      transition: transform .2s ease, box-shadow .2s ease;
    }

    .portal-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 22px 55px rgba(15, 23, 42, 0.12);
    }

    .portal-icon {
      background-image: linear-gradient(135deg, var(--icon-from), var(--icon-to));
    }

    .image-overlay {
      background: linear-gradient(to top, rgba(2, 6, 23, .9), rgba(2, 6, 23, .28), rgba(2, 6, 23, .08));
    }

    .facility-frame {
      aspect-ratio: 4 / 3;
    }

    @media (min-width: 640px) {
      .facility-frame {
        aspect-ratio: 16 / 11;
      }
    }

    .nav-link {
      position: relative;
      color: #334155;
      font-weight: 600;
      font-size: 0.875rem;
      padding: 0.5rem 0.25rem;
      transition: color .15s ease;
    }

    .nav-link:hover {
      color: #047857;
    }

    .nav-link::after {
      content: '';
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      height: 2px;
      background: #059669;
      transform: scaleX(0);
      transition: transform .18s ease;
      transform-origin: center;
    }

    .nav-link:hover::after {
      transform: scaleX(1);
    }

    @media (prefers-reduced-motion: reduce) {
      .portal-card:hover {
        transform: none;
      }

      .nav-link::after,
      .portal-card,
      .portal-card:hover svg {
        transition: none;
      }
    }
  </style>
</head>

<body class="bg-slate-50 text-slate-900 font-sans antialiased selection:bg-brand-500 selection:text-white">

  <!-- GOVPH-style top utility bar -->
  <div class="govph-bar">
    <div
      class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-10 flex items-center justify-between gap-3 text-[11px] sm:text-xs font-semibold text-slate-600">
      <div class="flex items-center gap-3 min-w-0">
        <span class="font-extrabold tracking-wide text-slate-800">ResiHUnity</span>
        <span class="hidden sm:inline text-slate-300">|</span>
        <span class="truncate text-slate-500">Municipality of <?= e($rhu['municipality']) ?>,
          <?= e($rhu['province']) ?></span>
      </div>
      <div class="flex items-center gap-3 sm:gap-4 shrink-0">
        <span class="hidden md:inline text-slate-500">Philippine Standard Time: <?= e($pstLabel) ?></span>
      </div>
    </div>
  </div>

  <!-- Agency identity banner -->
  <div class="agency-banner text-white shadow-md">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-5">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 sm:gap-5 min-w-0">
          <img src="<?= e($seal) ?>" alt="Sagisag ng Bayan ng Nasugbu"
            class="h-16 w-16 sm:h-20 sm:w-20 lg:h-24 lg:w-24 rounded-full bg-white object-cover shadow-lg ring-2 ring-white/30 shrink-0"
            onerror="this.style.display='none'">
          <div class="min-w-0">
            <p class="text-[10px] sm:text-xs font-bold uppercase tracking-[0.2em] text-emerald-100/90">Republic of the
              Philippines</p>
            <h1 class="text-lg sm:text-2xl lg:text-3xl font-extrabold leading-tight tracking-tight truncate">
              <?= e($rhu['name']) ?>
            </h1>
            <p class="text-xs sm:text-sm font-semibold text-emerald-100/95 mt-0.5">
              Municipality of <?= e($rhu['municipality']) ?> &middot; <?= e($rhu['province']) ?>
            </p>
          </div>
        </div>
        <div class="hidden sm:flex items-center gap-3 shrink-0">
          <div class="text-right hidden lg:block mr-1">
            <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-100/80">Digital Health System</p>
            <p class="text-sm font-extrabold">ResiHUnity RHU</p>
          </div>
          <img src="<?= e($logo) ?>" alt="ResiHUnity"
            class="h-14 w-14 lg:h-16 lg:w-16 rounded-full object-cover bg-white shadow-lg ring-2 ring-white/25"
            onerror="this.style.display='none'">
        </div>
      </div>
    </div>
  </div>

  <!-- Sticky modern navigation -->
  <header class="sticky top-0 z-50 bg-white/95 backdrop-blur border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="h-14 sm:h-16 flex items-center justify-between gap-3">
        <a href="rhulandingpage.php" class="flex items-center gap-2.5 min-w-0 sm:hidden">
          <img src="<?= e($logo) ?>" alt="" class="h-8 w-8 rounded-lg object-cover" onerror="this.style.display='none'">
          <span class="text-sm font-extrabold text-slate-900 truncate">Personnel Portal</span>
        </a>

        <nav class="hidden md:flex items-center gap-6">
          <a href="rhulandingpage.php#portals" class="nav-link">Access Portals</a>
          <a href="rhulandingpage.php#mandate" class="nav-link">RHU Mandate</a>
          <a href="rhulandingpage.php#roles" class="nav-link">Workforce</a>
          <a href="rhulandingpage.php#facility" class="nav-link">Facility</a>
        </nav>

        <div class="flex items-center gap-2 ml-auto">
          <a href="RHULogin.php"
            class="inline-flex items-center gap-2 rounded-xl bg-brand-600 hover:bg-brand-700 px-4 py-2 text-xs sm:text-sm font-extrabold text-white shadow-sm shadow-brand-600/20 transition">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
              <path stroke-linecap="round" stroke-linejoin="round" d="m10 17 5-5-5-5" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12H3" />
            </svg>
            Sign In
          </a>
          <button type="button" id="mobile-nav-btn"
            class="md:hidden inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700"
            aria-label="Open menu">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>
    <!-- Mobile menu -->
    <div id="mobile-nav" class="hidden md:hidden border-t border-slate-100 bg-white">
      <div class="max-w-7xl mx-auto px-4 py-3 flex flex-col gap-1">
        <a href="rhulandingpage.php#portals" class="rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Access
          Portals</a>
        <a href="rhulandingpage.php#mandate" class="rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">RHU
          Mandate</a>
        <a href="rhulandingpage.php#roles"
          class="rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Workforce</a>
        <a href="rhulandingpage.php#facility"
          class="rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Facility</a>
      </div>
    </div>
  </header>

  <!-- Hero -->
  <section class="hero-mesh text-white relative overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20">
      <div class="grid lg:grid-cols-12 gap-10 lg:gap-12 items-center">
        <div class="lg:col-span-6">
          <div
            class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wider text-emerald-200">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
            <?= e($rhu['short']) ?> &middot; Official Staff Gateway
          </div>
          <h2 class="mt-5 text-3xl sm:text-4xl lg:text-[2.75rem] font-extrabold leading-[1.12] tracking-tight">
            Serving Nasugbu through<br>
            <span class="text-emerald-300">professional public health care</span>
          </h2>
          <p class="mt-5 text-sm sm:text-base leading-relaxed text-slate-300 max-w-xl">
            Welcome to the personnel portal of <strong class="text-white"><?= e($rhu['name']) ?></strong>,
            located at the <?= e($rhu['building']) ?>.
            This workspace is for authorized RHU staff, administrators, and municipal health workers only.
          </p>
          <div class="mt-8 flex flex-col sm:flex-row gap-3">
            <a href="RHULogin.php"
              class="inline-flex items-center justify-center gap-2 rounded-2xl bg-brand-500 hover:bg-brand-600 px-6 py-3.5 text-sm font-extrabold text-white shadow-lg shadow-emerald-950/30 transition">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                <path stroke-linecap="round" stroke-linejoin="round" d="m10 17 5-5-5-5" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12H3" />
              </svg>
              Continue to Staff Login
            </a>
            <a href="rhulandingpage.php#portals"
              class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/20 bg-white/5 hover:bg-white/10 px-6 py-3.5 text-sm font-bold text-white transition">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
              </svg>
              View all portals
            </a>
          </div>
          <div class="mt-8 flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-emerald-100/90">
            <span class="inline-flex items-center gap-1"><span class="text-emerald-300">✓</span> Role-based access</span>
            <span class="inline-flex items-center gap-1"><span class="text-emerald-300">✓</span> Secure sessions</span>
            <span class="inline-flex items-center gap-1"><span class="text-emerald-300">✓</span> Authorized personnel only</span>
          </div>
        </div>

        <!-- Facility photo card -->
        <div class="lg:col-span-6">
          <div
            class="facility-frame relative overflow-hidden rounded-[1.75rem] border border-white/15 shadow-2xl shadow-black/40 bg-slate-900">
            <img src="<?= e($facilityImg) ?>" alt="<?= e($rhu['name']) ?> - <?= e($rhu['building']) ?>"
              class="absolute inset-0 h-full w-full object-cover" onerror="
                const paths = [
                  'assets/admin-municipal-background.png',
                  'assets/images/nasugbu_seal.png',
                  'assets/images/resihunity_logo.jpg'
                ];
                const i = Number(this.dataset.i || 0);
                if (i < paths.length) { this.dataset.i = i + 1; this.src = paths[i]; }
              ">
            <div class="image-overlay absolute inset-0 pointer-events-none"></div>

            <div class="absolute bottom-0 left-0 right-0 p-5 sm:p-6">
              <div class="flex items-end gap-3 mb-4">
                <img src="<?= e($seal) ?>" alt=""
                  class="h-12 w-12 sm:h-14 sm:w-14 rounded-full bg-white p-0.5 shadow-lg object-cover shrink-0"
                  onerror="this.style.display='none'">
                <div class="min-w-0">
                  <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-300">Facility</p>
                  <p class="text-base sm:text-lg font-extrabold text-white leading-snug"><?= e($rhu['name']) ?></p>
                  <p class="text-xs text-slate-300"><?= e($rhu['building']) ?> &middot; Poblacion</p>
                </div>
              </div>
              <div class="grid grid-cols-3 gap-2">
                <div class="rounded-xl border border-white/10 bg-black/30 backdrop-blur-sm px-2 py-2.5 text-center">
                  <p class="text-base sm:text-lg font-black text-emerald-300">I</p>
                  <p class="text-[10px] font-semibold text-slate-300">RHU Unit</p>
                </div>
                <div class="rounded-xl border border-white/10 bg-black/30 backdrop-blur-sm px-2 py-2.5 text-center">
                  <p class="text-base sm:text-lg font-black text-emerald-300">7+</p>
                  <p class="text-[10px] font-semibold text-slate-300">Staff Roles</p>
                </div>
                <div class="rounded-xl border border-white/10 bg-black/30 backdrop-blur-sm px-2 py-2.5 text-center">
                  <p class="text-base sm:text-lg font-black text-emerald-300">2</p>
                  <p class="text-[10px] font-semibold text-slate-300">Portals</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Trust strip -->
  <section class="bg-white border-b border-slate-200">
    <div
      class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-wrap items-center justify-center gap-x-8 gap-y-2 text-xs sm:text-sm font-semibold text-slate-600">
      <span class="text-brand-700">RHU Nasugbu primary care</span>
      <span class="hidden sm:inline text-slate-300">|</span>
      <span>PhilHealth partner facility</span>
      <span class="hidden sm:inline text-slate-300">|</span>
      <span>RA 10173 data privacy aware</span>
      <span class="hidden sm:inline text-slate-300">|</span>
      <span>Municipality of <?= e($rhu['municipality']) ?></span>
    </div>
  </section>

  <!-- Portals -->
  <section id="portals" class="py-16 sm:py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="max-w-2xl mb-10">
        <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-brand-700">Secure Access</p>
        <h2 class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900">Choose your official portal</h2>
        <p class="mt-2 text-sm text-slate-600 leading-relaxed">
          Sign in using credentials issued by <?= e($rhu['short']) ?>. Access is limited to your assigned role and
          workstation responsibilities.
        </p>
      </div>
      <div class="grid md:grid-cols-2 gap-5">
        <?php foreach ($portals as $p): ?>
          <a href="<?= e($p['href']) ?>" class="portal-card glass-card group rounded-3xl p-6 flex flex-col">
            <div
              class="portal-icon flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-lg ring-4 <?= e($p['ring']) ?>"
              style="--icon-from: <?= e($p['icon_from']) ?>; --icon-to: <?= e($p['icon_to']) ?>;">
              <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $p['icon'] ?></svg>
            </div>
            <p class="mt-5 text-[11px] font-extrabold uppercase tracking-wider text-brand-700"><?= e($p['subtitle']) ?>
            </p>
            <h3 class="mt-1 text-xl font-extrabold text-slate-900 group-hover:text-brand-800 transition">
              <?= e($p['title']) ?></h3>
            <p class="mt-2 text-sm text-slate-600 leading-relaxed flex-1"><?= e($p['desc']) ?></p>
            <span class="mt-6 inline-flex items-center gap-2 text-sm font-extrabold text-brand-700">
              <?= e($p['cta']) ?>
              <svg class="w-4 h-4 transition group-hover:translate-x-1" fill="none" stroke="currentColor"
                viewBox="0 0 24 24" stroke-width="2.2">
                <path d="M5 12h14" />
                <path d="m12 5 7 7-7 7" />
              </svg>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Mandate -->
  <section id="mandate" class="py-16 sm:py-20 bg-white border-y border-slate-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid lg:grid-cols-12 gap-10 items-start">
        <div class="lg:col-span-4">
          <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-brand-700">Our Mandate</p>
          <h2 class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900 leading-tight">Primary health care for
            every Nasugbue&ntilde;o</h2>
          <p class="mt-3 text-sm text-slate-600 leading-relaxed">
            <?= e($rhu['name']) ?> delivers accessible, quality public health services in coordination with the
            Department of Health, PhilHealth, and the Local Government of <?= e($rhu['municipality']) ?>.
          </p>
          <div class="mt-6 rounded-2xl border border-emerald-100 bg-emerald-50/80 p-4">
            <p class="text-xs font-bold text-emerald-900">Municipal Health Officer</p>
            <p class="text-sm font-extrabold text-emerald-800 mt-0.5"><?= e($rhu['mho']) ?></p>
            <p class="text-xs text-emerald-700/90 mt-1"><?= e($rhu['hours']) ?></p>
          </div>
        </div>
        <div class="lg:col-span-8 grid sm:grid-cols-2 gap-4">
          <?php foreach ($pillars as $i => $pillar): ?>
            <?php $title = $pillar[0]; $text = $pillar[1]; ?>
            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-5">
              <span
                class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-brand-600 text-xs font-black text-white"><?= $i + 1 ?></span>
              <h3 class="mt-3 font-extrabold text-slate-900"><?= e($title) ?></h3>
              <p class="mt-1.5 text-sm text-slate-600 leading-relaxed"><?= e($text) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Roles -->
  <section id="roles" class="py-16 sm:py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-2xl mx-auto mb-10">
        <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-brand-700">Workforce</p>
        <h2 class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900">Built for RHU personnel</h2>
        <p class="mt-2 text-sm text-slate-600">After login, staff are directed to dashboards matched to their
          designation.</p>
      </div>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($roles as $roleInfo): ?>
          <?php $title = $roleInfo[0]; $desc = $roleInfo[1]; $color = $roleInfo[2]; ?>
          <div class="rounded-2xl border bg-white p-5 shadow-sm border-slate-200">
            <span
              class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-extrabold <?= e($color) ?>"><?= e($title) ?></span>
            <p class="mt-3 text-sm text-slate-600 leading-relaxed"><?= e($desc) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Facility -->
  <section id="facility" class="py-16 sm:py-20 bg-slate-900 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid lg:grid-cols-2 gap-10 items-center">
        <div>
          <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-emerald-300">Facility Information</p>
          <h2 class="mt-2 text-2xl sm:text-3xl font-extrabold"><?= e($rhu['name']) ?></h2>
          <p class="mt-3 text-sm text-slate-300 leading-relaxed">
            The unit operates from the <?= e($rhu['building']) ?>, serving as a primary care and public health hub for
            the Municipality of <?= e($rhu['municipality']) ?>.
          </p>
          <dl class="mt-6 space-y-4 text-sm">
            <div>
              <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Address</dt>
              <dd class="font-semibold text-slate-100"><?= e($rhu['address']) ?></dd>
            </div>
            <div>
              <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Contact</dt>
              <dd class="font-semibold text-slate-100"><?= e($rhu['contact']) ?></dd>
            </div>
            <div>
              <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Staff operating hours</dt>
              <dd class="font-semibold text-slate-100"><?= e($rhu['hours']) ?></dd>
            </div>
          </dl>
          <div class="mt-8 flex flex-col sm:flex-row gap-3">
            <a href="RHULogin.php"
              class="inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-500 px-5 py-3 text-sm font-extrabold text-white hover:bg-emerald-600 transition">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                <path stroke-linecap="round" stroke-linejoin="round" d="m10 17 5-5-5-5" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12H3" />
              </svg>
              Staff Login
            </a>
          </div>
        </div>
        <div class="facility-frame relative overflow-hidden rounded-3xl border border-white/10 bg-slate-800 shadow-2xl shadow-black/30">
          <img src="<?= e($facilityImg) ?>" alt="<?= e($rhu['building']) ?>"
            class="absolute inset-0 h-full w-full object-cover" onerror="this.src='<?= e($seal) ?>'">
          <div class="image-overlay absolute inset-0"></div>
          <div class="absolute bottom-0 left-0 right-0 p-5">
            <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-300">Nasugbu RHU I</p>
            <p class="mt-1 text-lg font-extrabold text-white"><?= e($rhu['building']) ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="bg-slate-950 text-slate-400 border-t border-slate-900">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="grid md:grid-cols-3 gap-10 pb-10 border-b border-slate-900">
        <div class="md:col-span-2 space-y-4">
          <div class="flex items-center gap-3">
            <img src="<?= e($logo) ?>" alt="ResiHUnity" class="h-11 w-11 rounded-xl object-cover bg-white p-0.5"
              onerror="this.src='<?= e($seal) ?>'">
            <div>
              <p class="text-lg font-extrabold text-white">ResiHUnity RHU</p>
              <p class="text-[11px] font-bold uppercase tracking-wider text-brand-400">Personnel Portal</p>
            </div>
          </div>
          <p class="text-sm leading-relaxed max-w-md">
            Official staff access gateway of <?= e($rhu['name']) ?>.
            Not intended for public resident registration or general inquiries.
          </p>
        </div>
        <div>
          <h4 class="text-xs font-bold text-white uppercase tracking-wider mb-4">Staff Access</h4>
          <ul class="space-y-2 text-sm">
            <li><a class="hover:text-white" href="RHULogin.php">Healthcare Staff</a></li>
            <li><a class="hover:text-white" href="RHUAdminLogin.php">System Admin</a></li>
          </ul>
        </div>
      </div>
      <div class="pt-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
        <p>&copy; <?= date('Y') ?> <?= e($rhu['name']) ?> &middot; Municipality of <?= e($rhu['municipality']) ?>,
          <?= e($rhu['province']) ?>. All rights reserved.</p>
        <p class="font-semibold">Authorized personnel only</p>
      </div>
    </div>
  </footer>

  <script>
      (function () {
        var btn = document.getElementById('mobile-nav-btn');
        var menu = document.getElementById('mobile-nav');
        if (btn && menu) {
          btn.addEventListener('click', function () {
            menu.classList.toggle('hidden');
          });
          menu.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () { menu.classList.add('hidden'); });
          });
        }
      })();
  </script>
</body>

</html>
