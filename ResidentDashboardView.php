<?php
$resident ??= [];
$tabs ??= [
  'home' => ['Overview', 'layout-dashboard'],
  'records' => ['Health Records', 'file-heart'],
  'immunization' => ['Immunization', 'syringe'],
  'certificates' => ['Certificates', 'award'],
  'family' => ['Family Members', 'users'],
  'events' => ['Events & Programs', 'calendar-days'],
  'contact' => ['Contact RHU', 'messages-square'],
  'emergency' => ['Emergency & Referral', 'siren'],
];
$residentMessages ??= [];
$contactSuccess ??= '';
$certificateSuccess ??= '';
$certificateErrors ??= [];
$age ??= null;
$visitsThisYear ??= 0;
$vaccinationRecords ??= [];
$certificates ??= [];
$consultations ??= [];
$portalEvents ??= [];
$initials ??= strtoupper(substr($resident['first_name'] ?? 'R', 0, 1) . substr($resident['last_name'] ?? 'S', 0, 1));

$activeTab = $_GET['tab'] ?? 'home';
if (!isset($tabs[$activeTab])) $activeTab = 'home';
$displayName = trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')) ?: 'Resident';
$residentNumber = $resident['id'] ?? 'â€”';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Resident Dashboard | ResiHUnity RHU</title>
  <link rel="stylesheet" href="assets/tailwind-php.css?v=1">
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
      --ocean-950: #083344;
      --ocean-900: #134e4a;
      --ocean-700: #0f766e;
      --aqua-500: #14b8a6;
      --mint-100: #d1fae5;
      --sky-100: #e0f2fe;
      --sky-600: #0284c7;
      --amber-100: #fef3c7;
      --coral-600: #e11d48;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      font-family: "Plus Jakarta Sans", sans-serif;
      background:
        radial-gradient(circle at 8% 5%, rgba(45, 212, 191, .16), transparent 25rem),
        radial-gradient(circle at 92% 15%, rgba(14, 165, 233, .12), transparent 28rem),
        linear-gradient(160deg, #f8fffe 0%, #f8fafc 48%, #f4f9ff 100%);
    }
    .resident-panel { animation: panel-in .28s ease-out; }
    .nav-active {
      background: linear-gradient(135deg, #ccfbf1, #dbeafe);
      color: #0f766e;
      box-shadow: inset 3px 0 #0d9488;
    }
    .nav-active i { color: #0284c7; }
    .dashboard-card { transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    .dashboard-card {
      background: linear-gradient(145deg, rgba(255,255,255,.98), rgba(248,250,252,.96));
      box-shadow: 0 5px 18px rgba(15, 118, 110, .055);
    }
    .dashboard-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 16px 34px rgba(15, 118, 110, .13);
      border-color: #5eead4;
    }
    .resident-panel button.bg-teal-700 {
      background: linear-gradient(135deg, var(--ocean-700), var(--sky-600));
      box-shadow: 0 8px 18px rgba(15, 118, 110, .18);
    }
    .resident-panel button.bg-teal-700:hover {
      background: linear-gradient(135deg, var(--ocean-900), #0369a1);
    }
    #resident-drawer nav button:not(.nav-active):hover {
      background: linear-gradient(90deg, rgba(204, 251, 241, .68), rgba(224, 242, 254, .5));
      color: var(--ocean-900);
    }
    #resident-drawer::before {
      content: "";
      position: absolute;
      inset: 0 0 auto;
      height: 4px;
      background: linear-gradient(90deg, #10b981, #14b8a6, #0ea5e9);
    }
    input:focus, select:focus, textarea:focus {
      border-color: #0d9488 !important;
      box-shadow: 0 0 0 3px rgba(20, 184, 166, .13);
    }
    @keyframes panel-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
      .resident-panel, .dashboard-card { animation: none; transition: none; }
    }
  </style>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(__DIR__ . "/dashboard-transitions.css") ?>">
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
  <div id="drawer-overlay" class="fixed inset-0 z-40 hidden bg-slate-950/40 backdrop-blur-sm"></div>

  <aside id="resident-drawer" class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-teal-100 bg-linear-to-b from-white via-teal-50/40 to-sky-50/70 shadow-2xl transition-transform duration-300" aria-hidden="true">
    <div class="flex h-20 items-center justify-between border-b border-slate-100 px-5">
      <a href="ResidentDashboard.php" class="flex min-w-0 items-center gap-3">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-emerald-600 via-teal-600 to-sky-600 text-white shadow-lg shadow-teal-700/25">
          <i data-lucide="heart-pulse" class="h-6 w-6"></i>
        </span>
        <span class="min-w-0">
          <strong class="block truncate text-sm text-slate-900">ResiHUnity RHU</strong>
          <span class="block text-xs text-slate-500">Resident Health Portal</span>
        </span>
      </a>
      <button id="drawer-close" type="button" class="rounded-xl p-2 text-slate-500 hover:bg-slate-100" aria-label="Close menu">
        <i data-lucide="x" class="h-5 w-5"></i>
      </button>
    </div>

    <div class="border-b border-slate-100 p-4">
      <div class="flex items-center gap-3 rounded-2xl border border-teal-100 bg-linear-to-r from-emerald-50 to-sky-50 p-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-teal-600 to-sky-600 text-xs font-extrabold text-white shadow-md"><?= esc($initials) ?></span>
        <div class="min-w-0">
          <p class="truncate text-sm font-bold text-slate-900"><?= esc($displayName) ?></p>
          <p class="truncate text-xs text-teal-700">Resident #<?= esc($residentNumber) ?></p>
        </div>
      </div>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto p-3" aria-label="Resident dashboard">
      <?php foreach ($tabs as $id => [$label, $icon]): ?>
        <button type="button" data-tab-button="<?= esc($id) ?>" class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-left text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">
          <i data-lucide="<?= esc($icon) ?>" class="h-5 w-5 shrink-0"></i>
          <span><?= esc($label) ?></span>
        </button>
      <?php endforeach; ?>
    </nav>

    <div class="border-t border-slate-100 p-3">
      <a href="ResidentDashboard.php?logout=1" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-rose-600 hover:bg-rose-50">
        <i data-lucide="log-out" class="h-5 w-5"></i> Sign Out
      </a>
    </div>
  </aside>

  <div class="min-h-screen">
    <header class="sticky top-0 z-30 border-b border-teal-100/80 bg-white/85 shadow-sm shadow-teal-900/5 backdrop-blur-xl">
      <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
          <button id="drawer-open" type="button" class="rounded-xl p-2 text-slate-600 hover:bg-slate-100" aria-label="Open menu" aria-expanded="false">
            <i data-lucide="menu" class="h-5 w-5"></i>
          </button>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-teal-700">Resident Portal</p>
            <h1 id="page-title" class="truncate text-base font-extrabold text-slate-900 sm:text-lg">Overview</h1>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <div class="relative">
            <button id="notification-button" type="button" class="relative rounded-xl p-2.5 text-slate-600 hover:bg-slate-100" aria-label="Notifications" aria-expanded="false">
              <i data-lucide="bell" class="h-5 w-5"></i>
              <?php if ($residentMessages): ?><span class="absolute right-2 top-2 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-white"></span><?php endif; ?>
            </button>
            <div id="notification-panel" class="absolute right-0 top-12 hidden w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
              <div class="border-b border-slate-100 px-4 py-3"><p class="text-sm font-bold">Notifications</p></div>
              <div class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                <?php if (!$residentMessages): ?>
                  <p class="p-5 text-sm text-slate-500">You have no new messages.</p>
                <?php else: foreach (array_slice($residentMessages, 0, 3) as $message): ?>
                  <div class="p-4">
                    <p class="text-xs font-bold text-slate-800"><?= esc($message['subject'] ?? 'RHU message') ?></p>
                    <p class="mt-1 line-clamp-2 text-xs text-slate-500"><?= esc(($message['admin_reply'] ?? '') ?: ($message['message'] ?? '')) ?></p>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
          <span class="hidden text-right sm:block">
            <strong class="block text-xs text-slate-800"><?= esc($displayName) ?></strong>
            <span class="block text-[10px] text-slate-500"><?= esc($resident['barangay'] ?? 'Nasugbu') ?></span>
          </span>
        </div>
      </div>
    </header>

    <main class="mx-auto w-full max-w-7xl p-4 sm:p-6 lg:p-8">
      <?php if ($contactSuccess || $certificateSuccess): ?>
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
          <i data-lucide="circle-check" class="mt-0.5 h-5 w-5 shrink-0"></i>
          <p><?= esc($contactSuccess ?: $certificateSuccess) ?></p>
        </div>
      <?php endif; ?>

      <section data-tab-panel="home" class="resident-panel space-y-6">
        <div class="relative overflow-hidden rounded-3xl bg-linear-to-br from-emerald-700 via-teal-700 to-sky-700 p-6 text-white shadow-xl shadow-teal-900/20 sm:p-8">
          <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-sky-300/10"></div>
          <div class="absolute -bottom-24 left-1/3 h-56 w-56 rounded-full bg-emerald-300/10"></div>
          <div class="relative grid gap-6 lg:grid-cols-[1fr_auto] lg:items-center">
            <div>
              <p class="text-xs font-bold uppercase tracking-[.18em] text-teal-200">Welcome back</p>
              <h2 class="mt-2 text-2xl font-extrabold sm:text-3xl"><?= esc($displayName) ?></h2>
              <p class="mt-2 max-w-2xl text-sm leading-6 text-teal-100">Manage your health records, requests, appointments, and RHU programs from one secure place.</p>
              <div class="mt-5 flex flex-wrap gap-2 text-xs font-semibold">
                <span class="rounded-full bg-white/10 px-3 py-1.5"><i data-lucide="map-pin" class="mr-1 inline h-3.5 w-3.5"></i><?= esc($resident['barangay'] ?? 'Not set') ?></span>
                <span class="rounded-full bg-white/10 px-3 py-1.5"><i data-lucide="droplet" class="mr-1 inline h-3.5 w-3.5"></i><?= esc($resident['blood_type'] ?? 'Unknown') ?></span>
                <span class="rounded-full bg-white/10 px-3 py-1.5"><i data-lucide="user" class="mr-1 inline h-3.5 w-3.5"></i><?= $age === null ? 'Age not set' : esc($age) . ' years old' ?></span>
              </div>
            </div>
            <span class="hidden h-20 w-20 items-center justify-center rounded-3xl bg-white/10 lg:flex"><i data-lucide="user-round-check" class="h-10 w-10"></i></span>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <?php foreach ([
            ['Visits this year', $visitsThisYear, 'stethoscope', 'text-sky-700 bg-sky-100', 'border-t-sky-500'],
            ['Vaccinations', count($vaccinationRecords), 'shield-check', 'text-indigo-700 bg-indigo-100', 'border-t-indigo-500'],
            ['Certificates', count($certificates), 'badge-check', 'text-emerald-700 bg-emerald-100', 'border-t-emerald-500'],
            ['Messages', count($residentMessages), 'messages-square', 'text-amber-700 bg-amber-100', 'border-t-amber-500'],
          ] as [$label, $value, $icon, $style, $accent]): ?>
            <article class="dashboard-card rounded-2xl border border-t-4 border-slate-200 <?= $accent ?> bg-white p-5">
              <div class="flex items-center justify-between">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl <?= $style ?>"><i data-lucide="<?= $icon ?>" class="h-5 w-5"></i></span>
                <strong class="text-2xl font-extrabold text-slate-900"><?= esc($value) ?></strong>
              </div>
              <p class="mt-4 text-xs font-bold uppercase tracking-wide text-slate-500"><?= esc($label) ?></p>
            </article>
          <?php endforeach; ?>
        </div>

        <div>
          <div class="mb-3 flex items-center justify-between"><h3 class="font-extrabold text-slate-900">Quick access</h3><span class="text-xs text-slate-500">Select a service</span></div>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <?php foreach ([['records','Health Records','file-heart'],['immunization','Immunization','syringe'],['certificates','Certificates','award'],['events','Programs','calendar-days']] as [$target,$label,$icon]): ?>
              <button type="button" data-tab-link="<?= $target ?>" class="dashboard-card flex min-h-28 flex-col items-center justify-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-center">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700"><i data-lucide="<?= $icon ?>" class="h-5 w-5"></i></span>
                <span class="text-xs font-bold text-slate-700"><?= $label ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section data-tab-panel="records" class="resident-panel hidden space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div><h2 class="text-xl font-extrabold text-slate-900">Health Records</h2><p class="mt-1 text-sm text-slate-500">Your consultations and treatment history.</p></div>
          <button type="button" data-modal-open class="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-teal-700/20 hover:bg-teal-800"><i data-lucide="calendar-plus" class="h-4 w-4"></i>Request appointment</button>
        </div>
        <?php if (!$consultations): ?>
          <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center"><i data-lucide="folder-open" class="mx-auto h-10 w-10 text-slate-300"></i><p class="mt-3 text-sm font-semibold text-slate-500">No consultations recorded yet.</p></div>
        <?php else: ?><div class="space-y-3"><?php foreach ($consultations as $consultation): ?>
          <article class="dashboard-card rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap justify-between gap-3">
              <div><p class="text-xs font-bold uppercase tracking-wide text-teal-700"><?= esc($consultation['consultation_date'] ?? 'Date unavailable') ?></p><h3 class="mt-1 font-extrabold text-slate-900"><?= esc($consultation['diagnosis'] ?: 'Pending diagnosis') ?></h3><p class="mt-1 text-xs text-slate-500">Attending: <?= esc($consultation['physician_name'] ?: 'RHU healthcare provider') ?></p></div>
              <span class="h-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"><?= esc($consultation['consultation_time'] ?? 'OPD') ?></span>
            </div>
            <div class="mt-4 grid gap-3 border-t border-slate-100 pt-4 text-xs sm:grid-cols-2"><p><strong class="block text-slate-500">Chief complaint</strong><?= esc($consultation['chief_complaint'] ?? 'None specified') ?></p><p><strong class="block text-slate-500">Medication</strong><?= esc($consultation['medications_prescribed'] ?? 'None prescribed') ?></p></div>
          </article>
        <?php endforeach; ?></div><?php endif; ?>
      </section>

      <section data-tab-panel="immunization" class="resident-panel hidden space-y-5">
        <div><h2 class="text-xl font-extrabold text-slate-900">Immunization</h2><p class="mt-1 text-sm text-slate-500">Vaccination records verified by the RHU.</p></div>
        <?php if (!$vaccinationRecords): ?>
          <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center"><i data-lucide="syringe" class="mx-auto h-10 w-10 text-slate-300"></i><p class="mt-3 text-sm font-semibold text-slate-500">No vaccination records found.</p></div>
        <?php else: ?><div class="grid gap-3 lg:grid-cols-2"><?php foreach ($vaccinationRecords as $record): ?>
          <article class="dashboard-card flex gap-4 rounded-2xl border border-slate-200 bg-white p-5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700"><i data-lucide="shield-check" class="h-5 w-5"></i></span>
            <div class="min-w-0 flex-1"><h3 class="truncate font-extrabold text-slate-900"><?= esc($record['vaccine_name']) ?></h3><p class="mt-1 text-xs text-slate-500">Dose <?= esc($record['dose_number'] ?? '1') ?> Â· <?= esc($record['vaccination_date'] ?? 'Date unavailable') ?></p><p class="mt-2 text-xs font-semibold text-emerald-700">Completed</p></div>
          </article>
        <?php endforeach; ?></div><?php endif; ?>
      </section>

      <section data-tab-panel="certificates" class="resident-panel hidden space-y-5">
        <div><h2 class="text-xl font-extrabold text-slate-900">Certificates</h2><p class="mt-1 text-sm text-slate-500">Request and track RHU health certificates.</p></div>
        <?php if ($certificateErrors): ?><div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"><?= esc(implode(' ', $certificateErrors)) ?></div><?php endif; ?>
        <div class="grid gap-5 lg:grid-cols-[1fr_22rem]">
          <div class="space-y-3">
            <?php if (!$certificates): ?><div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm font-semibold text-slate-500">No certificate records available.</div>
            <?php else: foreach ($certificates as $certificate): ?>
              <article class="dashboard-card flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><i data-lucide="award" class="h-5 w-5"></i></span><div class="min-w-0 flex-1"><h3 class="truncate font-bold text-slate-900"><?= esc($certificate['certificate_type_name'] ?? 'Health Certificate') ?></h3><p class="mt-1 text-xs text-slate-500"><?= esc($certificate['certificate_number'] ?? 'Pending number') ?> Â· <?= esc($certificate['issue_date'] ?? 'Pending date') ?></p></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"><?= esc($certificate['validity_status'] ?? 'Pending') ?></span></article>
            <?php endforeach; endif; ?>
          </div>
          <form method="post" action="ResidentDashboard.php?tab=certificates" class="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
            <input type="hidden" name="form" value="certificate_request">
            <div><h3 class="font-extrabold text-slate-900">New request</h3><p class="mt-1 text-xs text-slate-500">Choose the certificate you need.</p></div>
            <select required name="certificate_type" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm focus:border-teal-600 focus:outline-none"><option value="">Select certificate</option><option>Medical Certificate</option><option>Health Certificate</option><option>Barangay Health Certificate</option><option>Certificate of Live Birth</option></select>
            <button class="w-full rounded-xl bg-teal-700 py-3 text-sm font-bold text-white hover:bg-teal-800">Submit request</button>
          </form>
        </div>
      </section>

      <section data-tab-panel="family" class="resident-panel hidden space-y-5">
        <div><h2 class="text-xl font-extrabold text-slate-900">Family Members</h2><p class="mt-1 text-sm text-slate-500">Household information associated with this resident record.</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-6"><div class="flex items-center gap-4"><span class="flex h-12 w-12 items-center justify-center rounded-full bg-teal-700 font-extrabold text-white"><?= esc($initials) ?></span><div><h3 class="font-extrabold text-slate-900"><?= esc($displayName) ?></h3><p class="text-xs text-slate-500">Primary resident Â· <?= esc($resident['address'] ?? $resident['barangay'] ?? 'Address not set') ?></p></div></div><div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Additional household members can be linked by RHU staff after verification.</div></div>
      </section>

      <section data-tab-panel="events" class="resident-panel hidden space-y-5">
        <div><h2 class="text-xl font-extrabold text-slate-900">Events & Programs</h2><p class="mt-1 text-sm text-slate-500">Free RHU programs available to Nasugbu residents.</p></div>
        <?php if (!$portalEvents): ?><div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm font-semibold text-slate-500">No upcoming events are currently scheduled.</div>
        <?php else: ?><div class="grid gap-4 lg:grid-cols-2"><?php foreach ($portalEvents as $event): ?>
          <article class="dashboard-card rounded-2xl border border-slate-200 bg-white p-5"><div class="flex gap-4"><span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700"><i data-lucide="calendar-days" class="h-5 w-5"></i></span><div class="min-w-0 flex-1"><h3 class="font-extrabold text-slate-900"><?= esc($event['title']) ?></h3><p class="mt-1 text-xs text-slate-500"><?= esc(($event['scheduled_date'] ?? '') ?: ($event['event_date'] ?? 'Date unavailable')) ?> Â· <?= esc($event['venue']) ?></p><p class="mt-3 text-sm leading-6 text-slate-600"><?= esc($event['description'] ?? '') ?></p></div></div><?php if (!empty($event['registration_status'])): ?><span class="mt-4 inline-block rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"><?= esc($event['registration_status']) ?></span><?php else: ?><form method="post" action="ResidentDashboard.php?tab=events" class="mt-4"><input type="hidden" name="form" value="event_registration"><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>"><button class="rounded-xl bg-teal-700 px-4 py-2 text-xs font-bold text-white hover:bg-teal-800">Register</button></form><?php endif; ?></article>
        <?php endforeach; ?></div><?php endif; ?>
      </section>

      <section data-tab-panel="contact" class="resident-panel hidden space-y-5">
        <div><h2 class="text-xl font-extrabold text-slate-900">Contact RHU</h2><p class="mt-1 text-sm text-slate-500">Send a non-emergency inquiry to RHU staff.</p></div>
        <div class="grid gap-5 lg:grid-cols-[20rem_1fr]">
          <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5"><?php foreach ([['map-pin','Address','Poblacion, Nasugbu, Batangas'],['phone','Contact','(043) 416-1234'],['clock','Office hours','Mondayâ€“Friday, 8:00 AMâ€“5:00 PM']] as [$icon,$label,$value]): ?><div class="flex gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700"><i data-lucide="<?= $icon ?>" class="h-5 w-5"></i></span><div><p class="text-xs font-bold text-slate-500"><?= $label ?></p><p class="mt-1 text-sm font-semibold text-slate-800"><?= $value ?></p></div></div><?php endforeach; ?></div>
          <form method="post" action="ResidentDashboard.php?tab=contact" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5"><input type="hidden" name="form" value="contact"><select name="subject" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-teal-600 focus:outline-none"><option>General Inquiry</option><option>Appointment Request</option><option>Certificate Request</option><option>Health Concern</option><option>Feedback / Complaint</option></select><textarea required name="message" rows="5" class="w-full resize-none rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-teal-600 focus:outline-none" placeholder="How can the RHU help you?"></textarea><button class="rounded-xl bg-teal-700 px-5 py-3 text-sm font-bold text-white hover:bg-teal-800">Send message</button></form>
        </div>
        <?php if ($residentMessages): ?><div class="space-y-3"><h3 class="font-extrabold text-slate-900">Message history</h3><?php foreach ($residentMessages as $message): ?><article class="rounded-2xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap justify-between gap-2"><h4 class="font-bold text-slate-900"><?= esc($message['subject']) ?></h4><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"><?= esc($message['status']) ?></span></div><p class="mt-3 text-sm text-slate-600"><?= esc($message['message']) ?></p><?php if (!empty($message['admin_reply'])): ?><div class="mt-4 rounded-xl border border-teal-100 bg-teal-50 p-4 text-sm text-teal-900"><strong class="block text-xs uppercase tracking-wide text-teal-700">RHU response</strong><p class="mt-1"><?= esc($message['admin_reply']) ?></p></div><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
      </section>

      <section data-tab-panel="emergency" class="resident-panel hidden space-y-5">
        <div class="rounded-3xl bg-rose-700 p-6 text-white shadow-xl shadow-rose-900/10 sm:p-8"><div class="flex items-start gap-4"><span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/15"><i data-lucide="siren" class="h-6 w-6"></i></span><div><h2 class="text-xl font-extrabold">Emergency & Referral</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-rose-100">For life-threatening emergencies, call 911 immediately. Use this form to alert the RHU response team for urgent local assistance.</p></div></div></div>
        <form method="post" action="ResidentDashboard.php?tab=emergency" class="mx-auto max-w-2xl space-y-4 rounded-2xl border border-slate-200 bg-white p-6"><input type="hidden" name="form" value="emergency_request"><div><label class="mb-2 block text-xs font-bold text-slate-600">Nature of emergency</label><input required name="emergency_nature" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-rose-600 focus:outline-none" placeholder="Describe the urgent concern"></div><div><label class="mb-2 block text-xs font-bold text-slate-600">Pickup location</label><textarea required name="pickup_location" rows="3" class="w-full resize-none rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-rose-600 focus:outline-none"><?= esc($resident['address'] ?? '') ?></textarea></div><button class="w-full rounded-xl bg-rose-700 py-3 text-sm font-extrabold text-white hover:bg-rose-800">Send emergency referral</button></form>
      </section>
    </main>
  </div>

  <div id="appointment-modal" class="fixed inset-0 z-60 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
      <div class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-extrabold text-slate-900">Request OPD Appointment</h2><p class="mt-1 text-xs text-slate-500">Choose your preferred date and describe your concern.</p></div><button type="button" data-modal-close class="rounded-xl p-2 text-slate-500 hover:bg-slate-100"><i data-lucide="x" class="h-5 w-5"></i></button></div>
      <form method="post" action="ResidentDashboard.php?tab=records" class="mt-5 space-y-4"><input type="hidden" name="form" value="appointment_request"><div><label class="mb-2 block text-xs font-bold text-slate-600">Preferred date</label><input required type="date" min="<?= date('Y-m-d') ?>" name="preferred_date" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm"></div><div><label class="mb-2 block text-xs font-bold text-slate-600">Health concern</label><textarea required name="chief_complaint" rows="4" class="w-full resize-none rounded-xl border border-slate-300 px-3 py-3 text-sm" placeholder="Briefly describe your symptoms or reason for consultation"></textarea></div><button class="w-full rounded-xl bg-teal-700 py-3 text-sm font-bold text-white hover:bg-teal-800">Submit appointment request</button></form>
    </div>
  </div>

  <script>
    (() => {
      const labels = <?= json_encode(array_map(fn($tab) => $tab[0], $tabs), JSON_UNESCAPED_UNICODE) ?>;
      const validTabs = <?= json_encode(array_keys($tabs)) ?>;
      const panels = [...document.querySelectorAll('[data-tab-panel]')];
      const buttons = [...document.querySelectorAll('[data-tab-button]')];
      const drawer = document.getElementById('resident-drawer');
      const overlay = document.getElementById('drawer-overlay');
      const modal = document.getElementById('appointment-modal');

      function closeDrawer() {
        drawer.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        drawer.setAttribute('aria-hidden', 'true');
        document.getElementById('drawer-open').setAttribute('aria-expanded', 'false');
      }
      function openDrawer() {
        drawer.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        drawer.setAttribute('aria-hidden', 'false');
        document.getElementById('drawer-open')?.setAttribute('aria-expanded', 'true');
      }
      window.closeDrawer = closeDrawer;
      window.openDrawer = openDrawer;
      function showTab(tab, updateUrl = true) {
        if (!validTabs.includes(tab)) tab = 'home';
        panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.tabPanel !== tab));
        buttons.forEach(button => button.classList.toggle('nav-active', button.dataset.tabButton === tab));
        document.getElementById('page-title').textContent = labels[tab] || 'Overview';
        if (updateUrl) {
          const url = new URL(window.location.href);
          url.searchParams.set('tab', tab);
          history.replaceState(history.state, '', url.toString());
        }
        closeDrawer();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }

      buttons.forEach(button => button.addEventListener('click', () => showTab(button.dataset.tabButton)));
      document.querySelectorAll('[data-tab-link]').forEach(button => button.addEventListener('click', () => showTab(button.dataset.tabLink)));
      document.getElementById('drawer-open').addEventListener('click', openDrawer);
      document.getElementById('drawer-close').addEventListener('click', closeDrawer);
      overlay.addEventListener('click', closeDrawer);

      document.querySelectorAll('[data-modal-open]').forEach(button => button.addEventListener('click', () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
      }));
      document.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      }));
      modal.addEventListener('click', event => { if (event.target === modal) document.querySelector('[data-modal-close]').click(); });

      const notificationButton = document.getElementById('notification-button');
      const notificationPanel = document.getElementById('notification-panel');
      notificationButton.addEventListener('click', event => {
        event.stopPropagation();
        const opened = notificationPanel.classList.toggle('hidden');
        notificationButton.setAttribute('aria-expanded', String(!opened));
      });
      document.addEventListener('click', event => {
        if (!notificationPanel.contains(event.target) && !notificationButton.contains(event.target)) {
          notificationPanel.classList.add('hidden');
          notificationButton.setAttribute('aria-expanded', 'false');
        }
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
          closeDrawer();
          notificationPanel.classList.add('hidden');
          modal.classList.add('hidden');
          modal.classList.remove('flex');
        }
      });

      showTab(<?= json_encode($activeTab) ?>);
      if (window.lucide) window.lucide.createIcons();
    })();
  </script>
</body>
</html>
