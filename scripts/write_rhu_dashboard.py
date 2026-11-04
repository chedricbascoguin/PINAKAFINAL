from pathlib import Path

content = """<?php
if (session_status() === PHP_SESSION_NONE) session_start();
@include_once __DIR__ . '/db.php';

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboardUrl(string $tab = 'overview', array $extra = []): string
{
    return '?' . http_build_query(array_merge(['tab' => $tab], $extra));
}

function iconSvg(string $name, string $class = 'w-5 h-5'): string
{
    $icons = [
        'home' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>',
        'stethoscope' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v7a6 6 0 0 0 12 0V3"/><path d="M6 10a3 3 0 0 0 6 0"/><path d="M12 20a4 4 0 0 0 8 0v-2"/></svg>',
        'clipboard' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>',
        'droplets' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8 6 5 9.5 5 13.5a7 7 0 0 0 14 0C19 9.5 16 6 12 2z"/></svg>',
        'users' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'activity' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'calendar' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        'syringe' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M16 8l6-6"/><path d="M2 22l5-5 5 5"/><path d="M7 17l3 3"/></svg>',
        'send' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        'testtube' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2h8v4H8z"/><path d="M8 6v6a4 4 0 1 0 8 0V6"/><path d="M12 14v6"/></svg>',
        'baby' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a8 8 0 0 1-8-8V7a4 4 0 0 1 8 0v7a8 8 0 0 1-8 8"/><path d="M12 2v4"/></svg>',
        'heart' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 22l7.8-8.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>',
        'microscope' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 20h12"/><path d="M10 14l2 2 2-2"/><path d="M7 8c0-2.5 2-5 5-5s5 2.5 5 5"/><path d="M7 8l4 4"/><path d="M17 18c0-1.1-.9-2-2-2"/></svg>',
        'scale' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4"/><path d="M4 7h16"/><path d="M6 21h12"/><path d="M12 7c-2 0-4 2-4 4v4h8v-4c0-2-2-4-4-4z"/></svg>',
        'shield' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'file' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',
        'pill' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 15L4.5 19.5a4.5 4.5 0 0 0 6.36 6.36L15 21"/><path d="M9 15l6-6"/><path d="M15 9L19.5 4.5A4.5 4.5 0 0 0 13.14-1.86L9 3"/></svg>',
        'usercheck' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M17 11l2 2 4-4"/></svg>',
        'bar' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="20" x2="6" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="18" y1="20" x2="18" y2="14"/></svg>',
        'trend' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'bell' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'logout' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'search' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'plus' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'x' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'alert' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'check' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>',
        'download' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'refresh' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/><path d="M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        'eye' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>',
        'edit' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
        'printer' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="2"/></svg>',
        'phone' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.09 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.77.6 2.6a2 2 0 0 1-.45 2.11L8 9a16 16 0 0 0 7 7l.57-1.24a2 2 0 0 1 2.11-.45c.83.27 1.7.48 2.6.6A2 2 0 0 1 22 16.92z"/></svg>',
        'mail' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><polyline points="4 4 12 13 20 4"/></svg>',
        'map' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6.5 9 4l6 2 6-2v15l-6 2-6-2-6 2z"/><path d="M9 4v15"/><path d="M15 6v15"/></svg>',
        'package' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73L13 2.27a2 2 0 0 0-2 0L4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 3.72a2 2 0 0 0 2 0l7-3.72A2 2 0 0 0 21 16z"/><path d="M7 9h10"/><path d="M7 15h10"/></svg>',
        'book' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 7H20v14H6.5A2.5 2.5 0 0 1 4 18.5V4.5z"/></svg>',
        'filecheck' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 16l2 2 4-4"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function formatDate(string $date): string
{
    return date('F j, Y', strtotime($date));
}

$tabs = [
    'overview' => ['Overview', 'home'],
    'opd' => ['OPD / Consultations', 'stethoscope'],
    'patients' => ['Patient Records', 'clipboard'],
    'blood_inventory' => ['Blood Inventory', 'droplets'],
    'donors' => ['Donor Registry', 'users'],
    'requests' => ['Blood Requests', 'activity'],
    'drives' => ['Blood Drives', 'calendar'],
    'transfusions' => ['Transfusion Log', 'syringe'],
    'referrals' => ['Referrals', 'send'],
    'immunization' => ['Immunization', 'testtube'],
    'maternal' => ['Maternal Health', 'baby'],
    'fp' => ['Family Planning', 'heart'],
    'tb_dots' => ['TB-DOTS', 'microscope'],
    'nutrition' => ['Nutrition', 'scale'],
    'disease' => ['Disease Surveillance', 'shield'],
    'vital' => ['Vital Statistics', 'file'],
    'medicine' => ['Medicine Inventory', 'pill'],
    'sanitation' => ['Sanitation', 'shield'],
    'certificates' => ['Health Certificates', 'filecheck'],
    'bhw' => ['BHW Management', 'usercheck'],
    'staff' => ['Staff', 'users'],
    'reports' => ['DOH Reports', 'bar'],
    'analytics' => ['Analytics', 'trend'],
];

$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}
$modal = $_GET['modal'] ?? '';
$showNotifs = isset($_GET['notifs']) && $_GET['notifs'] === '1';
$searchQuery = trim($_GET['q'] ?? '');

$RHU_INFO = [
    'name' => 'Nasugbu Rural Health Unit I',
    'code' => 'RHU-NSG-001',
    'municipality' => 'Nasugbu',
    'province' => 'Batangas',
    'region' => 'Region IV-A (CALABARZON)',
    'address' => 'Poblacion, Nasugbu, Batangas',
    'contactNumber' => '(043) 416-1234',
    'email' => 'rhu1.nasugbu@doh.gov.ph',
    'chiefMHO' => 'Dr. Maria C. Santos',
    'totalPopulation' => 165000,
    'catchmentBarangays' => ['Halang', 'Mabini', 'Balibago', 'Poblacion', 'Anilao', 'Nagsabaran'],
];

$notifications = [
    ['id' => 1, 'title' => 'Critical Stock Alert', 'message' => 'Metformin & Folic Acid critically low', 'time' => '5 min ago', 'unread' => true, 'type' => 'critical'],
    ['id' => 2, 'title' => 'Dengue Cluster Alert', 'message' => '4 dengue cases in Halang & Mabini this week', 'time' => '1 hour ago', 'unread' => true, 'type' => 'critical'],
    ['id' => 3, 'title' => 'Overdue Immunization', 'message' => '3 children with overdue vaccines', 'time' => '2 hours ago', 'unread' => true, 'type' => 'warning'],
];

$mockDonors = [
    ['id' => 'D-001', 'name' => 'Juan Santos', 'bloodType' => 'O+', 'barangay' => 'Halang', 'age' => 32, 'gender' => 'Male', 'donationHistory' => 4, 'cluster' => 'Reliable', 'availability' => true, 'contactNumber' => '0917 111 2222'],
    ['id' => 'D-002', 'name' => 'Liza Reyes', 'bloodType' => 'A+', 'barangay' => 'Mabini', 'age' => 28, 'gender' => 'Female', 'donationHistory' => 2, 'cluster' => 'Moderate', 'availability' => false, 'contactNumber' => '0918 333 4444'],
    ['id' => 'D-003', 'name' => 'Ramon Cruz', 'bloodType' => 'B+', 'barangay' => 'Poblacion', 'age' => 45, 'gender' => 'Male', 'donationHistory' => 5, 'cluster' => 'Reliable', 'availability' => true, 'contactNumber' => '0920 555 6666'],
];

$mockBloodRequests = [
    ['id' => 'RQ-001', 'bloodType' => 'O+', 'quantity' => 2, 'urgency' => 'urgent', 'patientInfo' => 'Jose dela Cruz • Suspected hemorrhage', 'status' => 'pending'],
    ['id' => 'RQ-002', 'bloodType' => 'AB-', 'quantity' => 1, 'urgency' => 'critical', 'patientInfo' => 'Maria Flores • Postpartum transfusion', 'status' => 'matching'],
];

$mockRHUInventory = [
    ['bloodType' => 'O+', 'units' => 24, 'status' => 'adequate', 'expiringUnits' => 2, 'source' => 'RHU bank', 'expiryDate' => '2026-07-12'],
    ['bloodType' => 'A+', 'units' => 8, 'status' => 'low', 'expiringUnits' => 0, 'source' => 'PRC', 'expiryDate' => '2026-08-09'],
    ['bloodType' => 'B+', 'units' => 5, 'status' => 'critical', 'expiringUnits' => 1, 'source' => 'RHU bank', 'expiryDate' => '2026-06-28'],
];

$mockBloodDrives = [
    ['id' => 'BD-001', 'title' => 'Halang Barangay Blood Drive', 'barangay' => 'Halang', 'venue' => 'Halang Covered Court', 'date' => '2026-07-15', 'startTime' => '08:00', 'endTime' => '15:00', 'targetDonors' => 40, 'organizer' => 'BHW Team', 'status' => 'scheduled', 'registeredDonors' => 18, 'unitsCollected' => 0],
    ['id' => 'BD-002', 'title' => 'Mabini Barangay Drive', 'barangay' => 'Mabini', 'venue' => 'Mabini Barangay Hall', 'date' => '2026-06-20', 'startTime' => '08:00', 'endTime' => '14:00', 'targetDonors' => 35, 'organizer' => 'RHU Logistics', 'status' => 'completed', 'registeredDonors' => 30, 'unitsCollected' => 28],
];

$mockPatients = [
    ['id' => 'P-001', 'name' => 'Alyssa Navarro', 'age' => 24, 'gender' => 'Female', 'bloodType' => 'A+', 'barangay' => 'Balibago', 'diagnosis' => 'Acute gastroenteritis', 'admissionDate' => '2026-06-10', 'philhealthCharged' => true, 'philhealthNo' => 'PH123456789', 'disposition' => 'outpatient'],
    ['id' => 'P-002', 'name' => 'Rogelio Herrera', 'age' => 52, 'gender' => 'Male', 'bloodType' => 'O-', 'barangay' => 'Poblacion', 'diagnosis' => 'Hypertension', 'admissionDate' => '2026-06-09', 'philhealthCharged' => false, 'philhealthNo' => null, 'disposition' => 'admitted'],
];

$mockTransfusions = [
    ['id' => 'T-001', 'patientName' => 'Maria Flores', 'bloodType' => 'AB-', 'units' => 1, 'date' => '2026-06-10', 'transfusedBy' => 'Dr. Santos', 'component' => 'Packed RBC', 'reaction' => 'None'],
];

$mockReferrals = [
    ['id' => 'RF-001', 'patientName' => 'Liza Reyes', 'age' => 28, 'gender' => 'Female', 'diagnosis' => 'Suspected dengue', 'referredTo' => 'Nasugbu District Hospital', 'status' => 'pending', 'referralDate' => '2026-06-10', 'urgency' => 'urgent', 'reason' => 'Platelet drop below 50k', 'referringMD' => 'Dr. Santos'],
];

$mockOPDConsultations = [
    ['id' => 'C-001', 'patientName' => 'Alyssa Navarro', 'age' => 24, 'gender' => 'F', 'diagnosis' => 'Acute gastroenteritis', 'barangay' => 'Balibago', 'physician' => 'Dr. Santos', 'date' => '2026-06-10', 'disposition' => 'outpatient', 'philhealthCharged' => true, 'vitals' => ['bp' => '110/70', 'temp' => 37.0, 'weight' => '54kg', 'rr' => 18, 'hr' => 82], 'chiefComplaint' => 'Persistent diarrhea', 'icd10' => 'A09', 'medications' => ['ORS', 'Paracetamol']],
];

$mockImmunizations = [
    ['id' => 'V-001', 'childName' => 'Baby Buenaventura', 'motherName' => 'Mariel Buenaventura', 'barangay' => 'Mabini', 'bhw' => 'Natividad Puno', 'dob' => '2025-12-10', 'age' => '7m', 'status' => 'overdue', 'nextVisit' => '2026-06-15', 'vaccines' => [['name' => 'DPT-HepB-Hib', 'date' => '2026-06-10', 'lot' => 'G123', 'status' => 'due']]],
];

$mockMaternalCases = [
    ['id' => 'M-001', 'name' => 'Cristina Magpayo', 'age' => 28, 'barangay' => 'Halang', 'gravida' => 2, 'para' => 1, 'aog' => '28 wks', 'lmp' => '2025-11-20', 'edc' => '2026-08-15', 'bloodType' => 'A+', 'prenatalVisits' => 3, 'midwife' => 'Midwife Peralta', 'deliveryPlan' => 'Facility-based', 'riskLevel' => 'high', 'status' => 'active_prenatal', 'philhealthStatus' => 'enrolled', 'lastVisit' => '2026-06-08', 'nextVisit' => '2026-06-22', 'labResults' => ['HBsAg' => 'Reactive', 'CBC' => 'Normal'], 'supplements' => ['Folic Acid', 'Iron']],
];

$mockFPClients = [
    ['id' => 'F-001', 'name' => 'Ana Lopez', 'age' => 31, 'barangay' => 'Poblacion', 'method' => 'DMPA', 'acceptorType' => 'new', 'lastSupply' => '2026-05-10', 'nextVisit' => '2026-08-10', 'status' => 'active'],
];

$mockTBCases = [
    ['id' => 'TB-001', 'name' => 'Danilo Espiritu', 'age' => 43, 'gender' => 'Male', 'classification' => 'Pulmonary TB', 'barangay' => 'Mabini', 'supporter' => 'BHW Ernesto', 'outcome' => 'on_treatment', 'caseType' => 'New', 'phase' => 'Intensive', 'monthsCompleted' => 2, 'totalMonths' => 6, 'adherence' => 93, 'treatmentRegimen' => 'RHZE', 'treatmentStartDate' => '2026-04-10', 'weight' => '58kg', 'nextCollection' => '2026-07-01', 'sputumResults' => [['date' => '2026-05-10', 'result' => 'Negative']] ],
];

$mockNutritionCases = [
    ['id' => 'N-001', 'name' => 'Angelo Lim', 'age' => 2, 'gender' => 'Male', 'barangay' => 'Balibago', 'classification' => 'SAM', 'weight' => 8.5, 'height' => 75, 'muac' => 10.5, 'bmi' => 14.9, 'lastVisit' => '2026-06-01', 'nextVisit' => '2026-06-21', 'interventions' => ['Plumpy Nut', 'Home visit']],
];

$mockDiseaseReports = [
    ['id' => 'DS-001', 'disease' => 'Dengue Fever', 'icd10' => 'A90', 'reportingWeek' => '2026-W23', 'cases' => 14, 'deaths' => 0, 'barangays' => ['Halang', 'Mabini'], 'actionTaken' => 'Fogging and community mobilization', 'status' => 'Active', 'alert' => true],
];

$mockVitalRecords = [
    ['id' => 'VR-001', 'type' => 'Birth', 'name' => 'Baby Cruz', 'motherName' => 'Angela Cruz', 'fatherName' => 'Miguel Cruz', 'barangay' => 'Poblacion', 'date' => '2026-06-10', 'attendant' => 'Midwife Peralta', 'registrationStatus' => 'registered', 'lncrn' => 'LNCRN-2026-001', 'remarks' => 'Facility-based delivery'],
];

$mockMedicineInventory = [
    ['id' => 'MED-001', 'genericName' => 'Metformin', 'brandName' => 'Glucophage', 'form' => 'Tablet', 'category' => 'Chronic disease', 'stock' => 12, 'reorderLevel' => 20, 'status' => 'critical', 'expiryDate' => '2026-07-05', 'batchNo' => 'B1234', 'source' => 'Procurement', 'usage30days' => 145, 'unit' => 'tabs'],
    ['id' => 'MED-002', 'genericName' => 'Folic Acid', 'brandName' => 'Folvite', 'form' => 'Tablet', 'category' => 'Supplement', 'stock' => 18, 'reorderLevel' => 30, 'status' => 'low', 'expiryDate' => '2027-02-10', 'batchNo' => 'F4567', 'source' => 'RHU Supply', 'usage30days' => 98, 'unit' => 'tabs'],
];

$mockHealthCertificates = [
    ['id' => 'HC-001', 'certNo' => 'C-2026-001', 'type' => 'Medical Certificate', 'recipientName' => 'Nestor dela Cruz', 'age' => 37, 'barangay' => 'Halang', 'purpose' => 'Employment', 'issuedBy' => 'Dr. Santos', 'issuedDate' => '2026-06-05', 'validUntil' => '2026-12-05', 'fee' => 50],
];

$mockSanitationInspections = [
    ['id' => 'SI-001', 'establishment' => 'Mabini Carinderia', 'barangay' => 'Mabini', 'inspector' => 'Sanitary Inspector Reyes', 'inspectionDate' => '2026-06-08', 'nextInspection' => '2026-08-08', 'status' => 'conditional', 'complianceRate' => 78, 'violations' => 2, 'findings' => ['Food storage temperature', 'Waste segregation']],
];

$mockDOHReports = [
    ['id' => 'DR-001', 'reportType' => 'Monthly Blood Inventory', 'period' => 'Jun 2026', 'generatedDate' => '2026-06-29', 'totalDonations' => 145, 'totalUnitsCollected' => 132, 'totalTransfusions' => 85, 'totalReferrals' => 14, 'bloodDrivesConducted' => 2, 'status' => 'submitted'],
];

$mockRHUStaff = [
    ['id' => 'ST-001', 'name' => 'Dr. Maria C. Santos', 'position' => 'Municipal Health Officer', 'email' => 'mcsantos.mho@nasugbu.gov.ph', 'licenseNo' => 'MD-2005-12345', 'prcExpiry' => '2026-06-30', 'status' => 'active'],
    ['id' => 'ST-002', 'name' => 'Midwife Rosario Peralta', 'position' => 'Midwife', 'email' => 'rperalta@nasugbu.gov.ph', 'licenseNo' => 'MID-2018-0754', 'prcExpiry' => '2028-04-11', 'status' => 'active'],
];

$mockBHWs = [
    ['id' => 'BHW-001', 'name' => 'Natividad Puno', 'barangay' => 'Mabini', 'contactNo' => '0917 222 3333', 'activeStatus' => true, 'donorsReferred' => 28, 'householdsAssigned' => 60, 'trainingLevel' => 'Senior BHW', 'lastTraining' => '2025-11-15'],
];

$mockDemandForecast = [
    ['date' => '2026-06-10', 'predicted' => 18, 'actual' => 17],
    ['date' => '2026-06-11', 'predicted' => 20, 'actual' => 19],
    ['date' => '2026-06-12', 'predicted' => 21, 'actual' => 20],
    ['date' => '2026-06-13', 'predicted' => 19, 'actual' => 18],
    ['date' => '2026-06-14', 'predicted' => 17, 'actual' => 16],
];

$weeklyOPDData = [
    ['day' => 'Mon', 'consultations' => 24, 'referred' => 2],
    ['day' => 'Tue', 'consultations' => 31, 'referred' => 3],
    ['day' => 'Wed', 'consultations' => 18, 'referred' => 1],
    ['day' => 'Thu', 'consultations' => 27, 'referred' => 4],
    ['day' => 'Fri', 'consultations' => 35, 'referred' => 2],
];

$diagnosisData = [
    ['name' => 'Hypertension', 'value' => 28],
    ['name' => 'Respiratory', 'value' => 22],
    ['name' => 'Diabetes', 'value' => 15],
];

$monthlyBloodData = [
    ['month' => 'Jan', 'donations' => 112, 'transfusions' => 38, 'referrals' => 7],
    ['month' => 'Feb', 'donations' => 98, 'transfusions' => 32, 'referrals' => 5],
    ['month' => 'Mar', 'donations' => 135, 'transfusions' => 45, 'referrals' => 10],
    ['month' => 'Apr', 'donations' => 125, 'transfusions' => 38, 'referrals' => 6],
    ['month' => 'May', 'donations' => 145, 'transfusions' => 42, 'referrals' => 8],
    ['month' => 'Jun', 'donations' => 62, 'transfusions' => 18, 'referrals' => 3],
];

$totalInventory = 0;
foreach ($mockRHUInventory as $item) {
    $totalInventory += $item['units'];
}
$criticalStock = count(array_filter($mockRHUInventory, static fn($item) => $item['status'] === 'critical'));
$criticalMeds = count(array_filter($mockMedicineInventory, static fn($item) => $item['status'] === 'critical'));
$lowMeds = count(array_filter($mockMedicineInventory, static fn($item) => $item['status'] === 'low'));
$pendingRequests = count(array_filter($mockBloodRequests, static fn($request) => in_array($request['status'], ['pending', 'matching'], true)));
$activeDonors = count(array_filter($mockDonors, static fn($donor) => !empty($donor['availability'])));
$overdueVaccines = count(array_filter($mockImmunizations, static fn($imm) => $imm['status'] === 'overdue'));
$dueVaccines = count(array_filter($mockImmunizations, static fn($imm) => $imm['status'] === 'due'));
$activeTB = count(array_filter($mockTBCases, static fn($case) => $case['outcome'] === 'on_treatment'));
$samCases = count(array_filter($mockNutritionCases, static fn($item) => $item['classification'] === 'SAM'));
$mamCases = count(array_filter($mockNutritionCases, static fn($item) => $item['classification'] === 'MAM'));
$dengueCases = $mockDiseaseReports[0]['cases'] ?? 0;
$highRiskMom = count(array_filter($mockMaternalCases, static fn($item) => $item['riskLevel'] === 'high'));
$todayOPD = count(array_filter($mockOPDConsultations, static fn($c) => $c['date'] === '2026-06-10'));

$filteredDonors = array_filter($mockDonors, static function ($donor) use ($searchQuery) {
    if ($searchQuery === '') {
        return true;
    }
    $search = strtolower($searchQuery);
    return str_contains(strtolower($donor['name']), $search)
        || str_contains(strtolower($donor['bloodType']), $search)
        || str_contains(strtolower($donor['barangay']), $search);
});

if (!empty($_SESSION['rhu_requests'])) {
    $mockBloodRequests = array_merge($_SESSION['rhu_requests'], $mockBloodRequests);
}
if (!empty($_SESSION['rhu_drives'])) {
    $mockBloodDrives = array_merge($_SESSION['rhu_drives'], $mockBloodDrives);
}
if (!empty($_SESSION['rhu_referrals'])) {
    $mockReferrals = array_merge($_SESSION['rhu_referrals'], $mockReferrals);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_request') {
        $newRequest = [
            'id' => 'RQ-' . time(),
            'bloodType' => trim($_POST['bloodType'] ?? ''),
            'quantity' => (int) ($_POST['quantity'] ?? 1),
            'urgency' => trim($_POST['urgency'] ?? 'urgent'),
            'patientInfo' => trim($_POST['patientInfo'] ?? ''),
            'status' => 'pending',
        ];
        if ($newRequest['bloodType'] === '' || $newRequest['patientInfo'] === '') {
            $_SESSION['rhu_error'] = 'Please fill blood type and patient details for the request.';
        } else {
            $_SESSION['rhu_requests'][] = $newRequest;
            $_SESSION['rhu_flash'] = 'Blood request submitted successfully.';
        }
        header('Location: ' . dashboardUrl('requests'));
        exit;
    }
    if ($action === 'save_drive') {
        $newDrive = [
            'id' => 'BD-' . time(),
            'title' => trim($_POST['title'] ?? ''),
            'barangay' => trim($_POST['barangay'] ?? ''),
            'venue' => trim($_POST['venue'] ?? ''),
            'date' => trim($_POST['date'] ?? ''),
            'startTime' => trim($_POST['startTime'] ?? ''),
            'endTime' => trim($_POST['endTime'] ?? ''),
            'targetDonors' => (int) ($_POST['targetDonors'] ?? 30),
            'organizer' => trim($_POST['coordinator'] ?? 'RHU Team'),
            'status' => 'scheduled',
            'registeredDonors' => 0,
            'unitsCollected' => 0,
        ];
        if ($newDrive['title'] === '' || $newDrive['barangay'] === '' || $newDrive['venue'] === '' || $newDrive['date'] === '') {
            $_SESSION['rhu_error'] = 'Please fill the required drive details.';
        } else {
            $_SESSION['rhu_drives'][] = $newDrive;
            $_SESSION['rhu_flash'] = 'Blood drive scheduled successfully.';
        }
        header('Location: ' . dashboardUrl('drives'));
        exit;
    }
    if ($action === 'save_referral') {
        $newReferral = [
            'id' => 'RF-' . time(),
            'patientName' => trim($_POST['patientName'] ?? ''),
            'age' => (int) ($_POST['age'] ?? 0),
            'gender' => trim($_POST['gender'] ?? 'Female'),
            'diagnosis' => trim($_POST['diagnosis'] ?? ''),
            'referredTo' => trim($_POST['referredTo'] ?? ''),
            'status' => 'pending',
            'referralDate' => date('Y-m-d'),
            'urgency' => trim($_POST['urgency'] ?? 'urgent'),
            'reason' => trim($_POST['reason'] ?? ''),
            'referringMD' => 'Dr. Maria C. Santos',
        ];
        if ($newReferral['patientName'] === '' || $newReferral['referredTo'] === '' || $newReferral['reason'] === '') {
            $_SESSION['rhu_error'] = 'Please complete the referral form.';
        } else {
            $_SESSION['rhu_referrals'][] = $newReferral;
            $_SESSION['rhu_flash'] = 'Referral submitted successfully.';
        }
        header('Location: ' . dashboardUrl('referrals'));
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RHU Dashboard - RedPulse RHU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{font-family:ui-sans-serif,system-ui,sans-serif}.safe-area-pb{padding-bottom:env(safe-area-inset-bottom)}@media(min-width:640px){.mobile-nav{display:none}}@media(max-width:639px){.desktop-tabs{display:none}}</style>
</head>
<body class="bg-gray-50 text-slate-900">
<div class="min-h-screen">
    <header class="bg-gradient-to-r from-blue-700 to-blue-900 text-white shadow-xl sticky top-0 z-40">
        <div class="px-4 sm:px-6 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="text-2xl">♥</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-lg font-bold tracking-tight">RedPulse RHU</h1>
                            <span class="hidden sm:block text-xs bg-blue-600 px-2 py-0.5 rounded-full text-blue-100 border border-blue-500">Integrated Health System</span>
                        </div>
                        <p class="text-xs text-blue-200"><?php echo esc($RHU_INFO['name']); ?> — <?php echo esc($RHU_INFO['code']); ?> | <?php echo esc($RHU_INFO['municipality']); ?>, <?php echo esc($RHU_INFO['province']); ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="hidden lg:flex flex-col items-end mr-1">
                        <span class="text-sm font-semibold"><?php echo esc($RHU_INFO['chiefMHO']); ?></span>
                        <span class="text-xs text-blue-200">Municipal Health Officer</span>
                    </div>
                    <a href="<?php echo esc(dashboardUrl($tab, ['notifs' => $showNotifs ? '0' : '1'])); ?>" class="relative p-2 hover:bg-blue-600 rounded-lg transition-colors" title="Notifications">
                        <?php echo iconSvg('bell', 'w-5 h-5'); ?>
                        <span class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-400 rounded-full border border-blue-700 animate-pulse"></span>
                    </a>
                    <a href="RHUAdminDashboard.php" class="hidden sm:flex items-center gap-1.5 text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 px-3 py-1.5 rounded-lg transition-all" title="Admin Panel">
                        <?php echo iconSvg('shield', 'w-3.5 h-3.5'); ?> Admin Panel
                    </a>
                    <span class="inline-flex items-center gap-1 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold"><?php echo iconSvg('bar', 'w-3.5 h-3.5'); ?> <?php echo $showNotifs ? 'Live' : 'Mock'; ?> data</span>
                    <a href="index.php" class="p-2 hover:bg-blue-600 rounded-lg transition-colors" title="Logout">
                        <?php echo iconSvg('logout', 'w-5 h-5'); ?>
                    </a>
                </div>
            </div>
            <div class="mt-3 flex gap-1 overflow-x-auto pb-1 scrollbar-hide desktop-tabs">
                <?php foreach ($tabs as $id => [$label, $icon]): ?>
                    <a href="<?php echo esc(dashboardUrl($id)); ?>" class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition-all <?php echo $tab === $id ? 'bg-white text-blue-700 shadow-sm' : 'text-blue-100 hover:bg-blue-600'; ?>">
                        <?php echo iconSvg($icon, 'w-3.5 h-3.5 flex-shrink-0'); ?>
                        <span class="hidden sm:inline"><?php echo esc($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </header>

    <nav class="mobile-nav fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-gray-200 safe-area-pb">
        <div class="flex items-stretch">
            <?php foreach (['overview','opd','patients','disease','analytics'] as $mobileTab): ?>
                <?php [$label, $icon] = $tabs[$mobileTab]; ?>
                <a href="<?php echo esc(dashboardUrl($mobileTab)); ?>" class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2 transition-colors relative <?php echo $tab === $mobileTab ? 'text-blue-600' : 'text-gray-400'; ?>">
                    <?php if ($tab === $mobileTab): ?><span class="absolute top-0 left-1/2 -translate-x-1/2 w-6 h-0.5 bg-blue-500 rounded-full"></span><?php endif; ?>
                    <?php echo iconSvg($icon, 'w-5 h-5'); ?>
                    <span class="text-[10px] font-semibold leading-none mt-0.5"><?php echo esc($label); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <main class="px-4 sm:px-6 py-5 pb-28 sm:pb-5">
        <?php if (!empty($flash)): ?>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800 mb-4"><?php echo esc($flash); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800 mb-4"><?php echo esc($error); ?></div>
        <?php endif; ?>

        <?php if ($tab === 'overview'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
                    <?php echo iconSvg('alert', 'w-5 h-5 text-red-600 flex-shrink-0'); ?>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-red-800">Active Alerts <?php echo '(' . count(array_filter($notifications, static fn($n) => $n['type'] === 'critical')) . ')'; ?></p>
                        <p class="text-sm text-red-700">Dengue cluster in Halang/Mabini • <?php echo $criticalMeds + $lowMeds; ?> medicine items need resupply • <?php echo $highRiskMom; ?> high-risk pregnant patients</p>
                    </div>
                    <a href="<?php echo esc(dashboardUrl('reports')); ?>" class="text-xs bg-red-600 text-white px-3 py-1.5 rounded-lg hover:bg-red-700 whitespace-nowrap">View All</a>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <?php $kpis = [
                        ['label' => "Today's OPD", 'value' => $todayOPD, 'sub' => 'consultations', 'color' => 'bg-blue-600', 'tab' => 'opd'],
                        ['label' => 'Blood Units', 'value' => $totalInventory, 'sub' => $criticalStock . ' types critical', 'color' => 'bg-red-600', 'tab' => 'blood_inventory'],
                        ['label' => 'Active TB Cases', 'value' => $activeTB, 'sub' => 'on treatment', 'color' => 'bg-orange-500', 'tab' => 'tb_dots'],
                        ['label' => 'Malnourished', 'value' => $samCases + $mamCases, 'sub' => $samCases . ' SAM / ' . $mamCases . ' MAM', 'color' => 'bg-yellow-500', 'tab' => 'nutrition'],
                        ['label' => 'Dengue Cases', 'value' => $dengueCases, 'sub' => 'This week', 'color' => 'bg-purple-600', 'tab' => 'disease'],
                        ['label' => 'Medicine Alerts', 'value' => $criticalMeds + $lowMeds, 'sub' => $criticalMeds . ' critical', 'color' => 'bg-teal-600', 'tab' => 'medicine'],
                    ]; foreach ($kpis as $kpi): ?>
                        <a href="<?php echo esc(dashboardUrl($kpi['tab'])); ?>" class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 hover:shadow-md hover:-translate-y-0.5 transition-all text-left">
                            <div class="w-8 h-8 <?php echo esc($kpi['color']); ?> rounded-lg flex items-center justify-center mb-2 text-white">+</div>
                            <p class="text-2xl font-black text-gray-900"><?php echo esc($kpi['value']); ?></p>
                            <p class="text-xs font-bold text-gray-700"><?php echo esc($kpi['label']); ?></p>
                            <p class="text-xs text-gray-400"><?php echo esc($kpi['sub']); ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2">
                    <?php $secondary = [
                        ['label' => 'Total Donors', 'value' => count($mockDonors), 'sub' => $activeDonors . ' available', 'tab' => 'donors', 'color' => 'text-blue-600'],
                        ['label' => 'Active Patients', 'value' => count(array_filter($mockPatients, static fn($p) => $p['disposition'] === 'admitted')), 'sub' => 'Admitted', 'tab' => 'patients', 'color' => 'text-purple-600'],
                        ['label' => 'Prenatal Cases', 'value' => count(array_filter($mockMaternalCases, static fn($m) => $m['status'] === 'active_prenatal')), 'sub' => $highRiskMom . ' high-risk', 'tab' => 'maternal', 'color' => 'text-pink-600'],
                        ['label' => 'FP Clients', 'value' => count($mockFPClients), 'sub' => 'Active acceptors', 'tab' => 'fp', 'color' => 'text-rose-600'],
                        ['label' => 'Overdue Vaccines', 'value' => $overdueVaccines + $dueVaccines, 'sub' => 'Need visit', 'tab' => 'immunization', 'color' => 'text-orange-600'],
                        ['label' => 'Blood Drives', 'value' => count(array_filter($mockBloodDrives, static fn($d) => $d['status'] === 'scheduled')), 'sub' => 'Scheduled', 'tab' => 'drives', 'color' => 'text-green-600'],
                        ['label' => 'Referrals', 'value' => count(array_filter($mockReferrals, static fn($r) => $r['status'] === 'pending')), 'sub' => 'Pending', 'tab' => 'referrals', 'color' => 'text-indigo-600'],
                        ['label' => 'Active BHWs', 'value' => count(array_filter($mockBHWs, static fn($b) => $b['activeStatus'])), 'sub' => 'of ' . count($mockBHWs), 'tab' => 'bhw', 'color' => 'text-teal-600'],
                    ]; foreach ($secondary as $stat): ?>
                        <a href="<?php echo esc(dashboardUrl($stat['tab'])); ?>" class="bg-white rounded-xl p-3 shadow-sm border border-gray-100 hover:shadow-md text-center transition-all">
                            <p class="text-xl font-black <?php echo esc($stat['color']); ?>"><?php echo esc($stat['value']); ?></p>
                            <p class="text-xs font-bold text-gray-700 mt-0.5"><?php echo esc($stat['label']); ?></p>
                            <p class="text-xs text-gray-400"><?php echo esc($stat['sub']); ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                        <h3 class="font-bold text-gray-900 mb-4">Weekly OPD Consultations</h3>
                        <div class="space-y-2 text-sm text-gray-600">
                            <?php foreach ($weeklyOPDData as $row): ?>
                                <div class="flex justify-between"><span><?php echo esc($row['day']); ?></span><span><?php echo esc($row['consultations']); ?> consultations</span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                        <h3 class="font-bold text-gray-900 mb-4">Top Diagnoses (June 2026)</h3>
                        <div class="space-y-2 text-sm text-gray-600">
                            <?php foreach ($diagnosisData as $item): ?>
                                <div class="flex justify-between"><span><?php echo esc($item['name']); ?></span><span><?php echo esc($item['value']); ?>%</span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <?php $actions = [
                        ['label' => 'New OPD Consultation', 'tab' => 'opd', 'color' => 'bg-blue-600'],
                        ['label' => 'Record Immunization', 'tab' => 'immunization', 'color' => 'bg-indigo-600'],
                        ['label' => 'Prenatal Check-up', 'tab' => 'maternal', 'color' => 'bg-pink-500'],
                        ['label' => 'Issue Health Certificate', 'tab' => 'certificates', 'color' => 'bg-green-600'],
                        ['label' => 'Create Referral', 'tab' => 'referrals', 'color' => 'bg-purple-600'],
                        ['label' => 'PIDSR Report', 'tab' => 'disease', 'color' => 'bg-orange-600'],
                        ['label' => 'Nutrition Assessment', 'tab' => 'nutrition', 'color' => 'bg-yellow-500'],
                        ['label' => 'TB-DOTS Entry', 'tab' => 'tb_dots', 'color' => 'bg-red-600'],
                    ]; foreach ($actions as $actionItem): ?>
                        <a href="<?php echo esc(dashboardUrl($actionItem['tab'])); ?>" class="w-full flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors text-left bg-white shadow-sm border border-gray-100">
                            <span class="w-7 h-7 <?php echo esc($actionItem['color']); ?> rounded-lg flex items-center justify-center text-white">+</span>
                            <span class="text-xs font-semibold text-gray-700"><?php echo esc($actionItem['label']); ?></span>
                            <span class="ml-auto text-gray-400"><?php echo iconSvg('right', 'w-4 h-4'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                        <div class="flex items-center justify-between mb-4"><h3 class="font-bold text-gray-900">Today's OPD</h3><a href="<?php echo esc(dashboardUrl('opd')); ?>" class="text-xs text-blue-600 hover:underline">View all</a></div>
                        <div class="space-y-2">
                            <?php foreach ($mockOPDConsultations as $c): ?>
                                <div class="rounded-xl border border-gray-100 p-3">
                                    <p class="font-bold text-gray-900"><?php echo esc($c['patientName']); ?> <span class="text-gray-500 text-xs"><?php echo esc($c['age']); ?>y • <?php echo esc($c['gender']); ?></span></p>
                                    <p class="text-xs text-gray-500"><?php echo esc($c['diagnosis']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                        <h3 class="font-bold text-gray-900 mb-3">Health Alerts</h3>
                        <div class="space-y-2">
                            <?php $alerts = [
                                ['label' => 'Dengue Cluster (Wk 23)', 'value' => $dengueCases . ' cases', 'color' => 'red', 'tab' => 'disease'],
                                ['label' => 'Overdue Immunization', 'value' => $overdueVaccines . ' children', 'color' => 'orange', 'tab' => 'immunization'],
                                ['label' => 'High-Risk Prenatal', 'value' => $highRiskMom . ' mothers', 'color' => 'pink', 'tab' => 'maternal'],
                            ]; foreach ($alerts as $alert): ?>
                                <a href="<?php echo esc(dashboardUrl($alert['tab'])); ?>" class="flex items-center justify-between p-2 rounded-lg hover:bg-gray-50 transition-colors bg-white border border-gray-100">
                                    <span class="text-xs font-semibold text-gray-700"><?php echo esc($alert['label']); ?></span>
                                    <span class="text-xs font-bold text-<?php echo esc($alert['color']); ?>-600"><?php echo esc($alert['value']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="bg-blue-800 rounded-xl p-4 text-white">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div><p class="text-blue-200 text-xs">Municipality</p><p class="font-semibold text-sm"><?php echo esc($RHU_INFO['municipality']); ?>, <?php echo esc($RHU_INFO['province']); ?></p></div>
                            <div><p class="text-blue-200 text-xs">Population Served</p><p class="font-semibold text-sm"><?php echo number_format($RHU_INFO['totalPopulation']); ?></p></div>
                            <div><p class="text-blue-200 text-xs">Total Barangays</p><p class="font-semibold text-sm"><?php echo count($RHU_INFO['catchmentBarangays']); ?> barangays</p></div>
                            <div><p class="text-blue-200 text-xs">Municipal Health Officer</p><p class="font-semibold text-sm"><?php echo esc($RHU_INFO['chiefMHO']); ?></p></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'opd'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">OPD / Consultation Log</h2>
                    <div class="flex gap-2">
                        <a href="<?php echo esc(dashboardUrl('opd', ['modal' => 'new_opd'])); ?>" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700"><?php echo iconSvg('plus', 'w-4 h-4'); ?> New Consultation</a>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php $stats = [
                        ['label' => "Today's Consultations", 'value' => $todayOPD, 'color' => 'blue'],
                        ['label' => 'Referred Out', 'value' => count(array_filter($mockOPDConsultations, static fn($c) => $c['disposition'] === 'referred')), 'color' => 'orange'],
                        ['label' => 'PhilHealth Charged', 'value' => count(array_filter($mockOPDConsultations, static fn($c) => $c['philhealthCharged'])), 'color' => 'green'],
                        ['label' => 'Total This Week', 'value' => count($mockOPDConsultations), 'color' => 'purple'],
                    ]; foreach ($stats as $stat): ?>
                        <div class="bg-white rounded-xl p-3 sm:p-4 shadow-sm border border-gray-100 text-center">
                            <p class="text-2xl font-black <?php echo esc($stat['color'] === 'blue' ? 'text-blue-600' : ($stat['color'] === 'orange' ? 'text-orange-600' : ($stat['color'] === 'green' ? 'text-green-600' : 'text-purple-600'))); ?>"><?php echo esc($stat['value']); ?></p>
                            <p class="text-xs text-gray-600 font-semibold mt-0.5"><?php echo esc($stat['label']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="space-y-3">
                    <?php foreach ($mockOPDConsultations as $c): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                            <div class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-bold text-gray-900"><?php echo esc($c['patientName']); ?> <span class="font-normal text-gray-500 text-sm"><?php echo esc($c['age']); ?>y / <?php echo esc($c['gender']); ?></span></p>
                                        <p class="text-sm text-gray-700 mt-1"><?php echo esc($c['diagnosis']); ?></p>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo esc($c['barangay']); ?> • <?php echo esc($c['physician']); ?> • <?php echo esc($c['date']); ?></p>
                                    </div>
                                    <span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $c['disposition'] === 'referred' ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700'; ?>"><?php echo esc($c['disposition']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'patients'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">Patient Records</h2>
                    <a href="<?php echo esc(dashboardUrl('patients', ['modal' => 'new_patient'])); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700"><?php echo iconSvg('plus', 'w-4 h-4'); ?> Admit Patient</a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php foreach (['admitted','outpatient','discharged','referred'] as $status): ?>
                        <div class="bg-white rounded-xl p-3 sm:p-4 shadow-sm border border-gray-100 text-center">
                            <p class="text-2xl font-black text-gray-900"><?php echo esc(count(array_filter($mockPatients, static fn($p) => $p['disposition'] === $status))); ?></p>
                            <p class="text-xs text-gray-600 font-semibold capitalize mt-0.5"><?php echo esc($status); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm min-w-[600px]">
                            <thead class="bg-gray-50"><tr><?php foreach (['Patient ID', 'Name', 'Age/Sex', 'Blood Type', 'Barangay', 'Diagnosis', 'Admission', 'PhilHealth', 'Status'] as $h): ?><th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap"><?php echo esc($h); ?></th><?php endforeach; ?></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($mockPatients as $patient): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-500"><?php echo esc($patient['id']); ?></td>
                                        <td class="px-4 py-3 font-semibold text-gray-900"><?php echo esc($patient['name']); ?></td>
                                        <td class="px-3 py-2.5 text-gray-600 whitespace-nowrap"><?php echo esc($patient['age']); ?>y / <?php echo esc($patient['gender']); ?></td>
                                        <td class="px-4 py-3"><span class="font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded"><?php echo esc($patient['bloodType']); ?></span></td>
                                        <td class="px-3 py-2.5 text-gray-600"><?php echo esc($patient['barangay']); ?></td>
                                        <td class="px-4 py-3 text-gray-700"><?php echo esc($patient['diagnosis']); ?></td>
                                        <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap"><?php echo esc(date('M d, Y', strtotime($patient['admissionDate']))); ?></td>
                                        <td class="px-4 py-3"><?php echo $patient['philhealthNo'] ? '<span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full font-semibold">Enrolled</span>' : '<span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">None</span>'; ?></td>
                                        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $patient['disposition'] === 'admitted' ? 'bg-blue-100 text-blue-700' : ($patient['disposition'] === 'referred' ? 'bg-purple-100 text-purple-700' : 'bg-yellow-100 text-yellow-700'); ?>"><?php echo esc($patient['disposition']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'blood_inventory'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">Blood Inventory Management</h2>
                    <a href="<?php echo esc(dashboardUrl('blood_inventory', ['refresh' => '1'])); ?>" class="inline-flex items-center gap-1.5 px-3 sm:px-4 py-2 bg-blue-600 text-white rounded-lg text-xs sm:text-sm font-semibold hover:bg-blue-700"><?php echo iconSvg('refresh', 'w-4 h-4'); ?> Request Resupply</a>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <?php foreach ($mockRHUInventory as $inv): ?>
                        <div class="bg-white rounded-xl p-4 shadow-sm border-2 <?php echo $inv['status'] === 'critical' ? 'border-red-300' : ($inv['status'] === 'low' ? 'border-orange-200' : 'border-gray-100'); ?>">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-3xl font-black text-gray-900"><?php echo esc($inv['bloodType']); ?></span>
                                <span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $inv['status'] === 'critical' ? 'bg-red-100 text-red-700' : ($inv['status'] === 'low' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700'); ?>"><?php echo esc($inv['status']); ?></span>
                            </div>
                            <p class="text-2xl font-bold text-gray-800"><?php echo esc($inv['units']); ?> <span class="text-sm font-normal text-gray-500">units</span></p>
                            <?php if ($inv['expiringUnits'] > 0): ?><p class="text-xs text-red-600 mt-1"><?php echo esc($inv['expiringUnits']); ?> expiring</p><?php endif; ?>
                            <p class="text-xs text-gray-400 mt-1">Source: <?php echo esc($inv['source']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-5 border-b"><h3 class="font-bold text-gray-900">Detailed Inventory</h3><p class="text-sm text-gray-500 mt-0.5">Last updated: <?php echo esc(date('F j, Y')); ?></p></div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm min-w-[600px]">
                            <thead class="bg-gray-50"><tr><?php foreach (['Blood Type','Units','Status','Expiring','Expiry Date','Source'] as $h): ?><th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide"><?php echo esc($h); ?></th><?php endforeach; ?></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($mockRHUInventory as $inv): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-black text-gray-900"><?php echo esc($inv['bloodType']); ?></td>
                                        <td class="px-4 py-3 font-bold"><?php echo esc($inv['units']); ?></td>
                                        <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-bold border <?php echo $inv['status'] === 'critical' ? 'bg-red-100 text-red-700 border-red-200' : ($inv['status'] === 'low' ? 'bg-orange-100 text-orange-700 border-orange-200' : 'bg-green-100 text-green-700 border-green-200'); ?>"><?php echo esc(strtoupper($inv['status'])); ?></span></td>
                                        <td class="px-4 py-3"><?php echo $inv['expiringUnits'] > 0 ? esc($inv['expiringUnits']) : '—'; ?></td>
                                        <td class="px-3 py-2.5 text-gray-600"><?php echo esc($inv['expiryDate']); ?></td>
                                        <td class="px-3 py-2.5 text-gray-600"><?php echo esc($inv['source']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50"><tr><td class="px-4 py-3 font-bold">TOTAL</td><td class="px-4 py-3 font-bold text-blue-700"><?php echo esc($totalInventory); ?> units</td><td colspan="4"></td></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'donors'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">Donor Registry</h2>
                    <form action="<?php echo esc(dashboardUrl('donors')); ?>" method="get" class="flex gap-2 w-full sm:w-auto">
                        <input type="hidden" name="tab" value="donors">
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><?php echo iconSvg('search', 'w-4 h-4'); ?></span>
                            <input type="text" name="q" value="<?php echo esc($searchQuery); ?>" placeholder="Search donors..." class="w-full sm:w-56 pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700"><?php echo iconSvg('search', 'w-4 h-4'); ?> Search</button>
                    </form>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <?php $donorInfo = [
                        ['label' => 'Total Registered', 'value' => count($mockDonors), 'color' => 'blue'],
                        ['label' => 'Available Now', 'value' => $activeDonors, 'color' => 'green'],
                        ['label' => 'BHW Referred', 'value' => count(array_filter($mockDonors, static fn($d) => $d['cluster'] === 'Reliable')), 'color' => 'purple'],
                    ]; foreach ($donorInfo as $info): ?>
                        <div class="bg-white rounded-xl p-3 sm:p-4 shadow-sm border border-gray-100 text-center">
                            <p class="text-2xl font-black <?php echo esc($info['color'] === 'blue' ? 'text-blue-600' : ($info['color'] === 'green' ? 'text-green-600' : 'text-purple-600')); ?>"><?php echo esc($info['value']); ?></p>
                            <p class="text-xs text-gray-600 font-semibold mt-0.5"><?php echo esc($info['label']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm min-w-[600px]">
                            <thead class="bg-gray-50"><tr><?php foreach (['ID','Name','Blood Type','Barangay','Age/Sex','Donations','Cluster','Status'] as $h): ?><th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap"><?php echo esc($h); ?></th><?php endforeach; ?></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($filteredDonors as $donor): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-500"><?php echo esc($donor['id']); ?></td>
                                        <td class="px-4 py-3 font-semibold text-gray-900"><?php echo esc($donor['name']); ?></td>
                                        <td class="px-4 py-3"><span class="font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded"><?php echo esc($donor['bloodType']); ?></span></td>
                                        <td class="px-3 py-2.5 text-gray-600"><?php echo esc($donor['barangay']); ?></td>
                                        <td class="px-3 py-2.5 text-gray-600 whitespace-nowrap"><?php echo esc($donor['age']); ?>y / <?php echo esc($donor['gender']); ?></td>
                                        <td class="px-4 py-3 font-bold text-gray-900"><?php echo esc($donor['donationHistory']); ?></td>
                                        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $donor['cluster'] === 'Reliable' ? 'bg-blue-100 text-blue-700' : ($donor['cluster'] === 'Moderate' ? 'bg-yellow-100 text-yellow-700' : 'bg-purple-100 text-purple-700'); ?>"><?php echo esc($donor['cluster']); ?></span></td>
                                        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $donor['availability'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>"><?php echo $donor['availability'] ? 'Available' : 'Unavailable'; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'requests'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">Blood Requests</h2>
                    <a href="<?php echo esc(dashboardUrl('requests', ['modal' => 'new_request'])); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700"><?php echo iconSvg('plus', 'w-4 h-4'); ?> New Request</a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php foreach (['pending','matching','fulfilled','referred'] as $status): ?>
                        <div class="bg-white rounded-xl p-3 sm:p-4 shadow-sm border border-gray-100 text-center">
                            <p class="text-2xl font-black text-gray-900"><?php echo esc(count(array_filter($mockBloodRequests, static fn($r) => $r['status'] === $status))); ?></p>
                            <p class="text-xs text-gray-600 font-semibold capitalize mt-0.5"><?php echo esc($status); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="space-y-3">
                    <?php foreach ($mockBloodRequests as $req): ?>
                        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 <?php echo $req['urgency'] === 'critical' ? 'border-red-500' : ($req['urgency'] === 'urgent' ? 'border-orange-400' : 'border-yellow-400'); ?>">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap mb-1">
                                        <span class="font-mono text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded"><?php echo esc($req['id']); ?></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full font-bold border <?php echo $req['urgency'] === 'critical' ? 'bg-red-100 text-red-700 border-red-200' : ($req['urgency'] === 'urgent' ? 'bg-orange-100 text-orange-700 border-orange-200' : 'bg-yellow-100 text-yellow-700 border-yellow-200'); ?>"><?php echo strtoupper(esc($req['urgency'])); ?></span>
                                    </div>
                                    <p class="font-bold text-gray-900"><?php echo esc($req['patientInfo']); ?></p>
                                    <p class="text-sm text-gray-600 mt-0.5">Blood Type: <strong class="text-red-600"><?php echo esc($req['bloodType']); ?></strong> • <?php echo esc($req['quantity']); ?> unit(s)</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs px-2 py-1 rounded-full font-semibold <?php echo $req['status'] === 'fulfilled' ? 'bg-green-100 text-green-700' : ($req['status'] === 'matching' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700'); ?>"><?php echo esc($req['status']); ?></span>
                                    <?php if ($req['status'] !== 'fulfilled'): ?>
                                        <a href="<?php echo esc(dashboardUrl('donors')); ?>" class="text-xs bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700">Match Donors</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'drives'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">Blood Drive Management</h2>
                    <a href="<?php echo esc(dashboardUrl('drives', ['modal' => 'new_drive'])); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700"><?php echo iconSvg('plus', 'w-4 h-4'); ?> Schedule Drive</a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php $driveStats = [
                        ['label' => 'Scheduled', 'count' => count(array_filter($mockBloodDrives, static fn($d) => $d['status'] === 'scheduled')), 'color' => 'blue'],
                        ['label' => 'Ongoing', 'count' => count(array_filter($mockBloodDrives, static fn($d) => $d['status'] === 'ongoing')), 'color' => 'green'],
                        ['label' => 'Completed', 'count' => count(array_filter($mockBloodDrives, static fn($d) => $d['status'] === 'completed')), 'color' => 'gray'],
                        ['label' => 'Units Collected', 'count' => array_sum(array_map(static fn($d) => $d['unitsCollected'] ?? 0, $mockBloodDrives)), 'color' => 'red'],
                    ]; foreach ($driveStats as $ds): ?>
                        <div class="bg-white rounded-xl p-3 sm:p-4 shadow-sm border border-gray-100 text-center">
                            <p class="text-2xl font-black <?php echo esc($ds['color'] === 'blue' ? 'text-blue-600' : ($ds['color'] === 'green' ? 'text-green-600' : ($ds['color'] === 'red' ? 'text-red-600' : 'text-gray-700'))); ?>"><?php echo esc($ds['count']); ?></p>
                            <p class="text-xs text-gray-600 font-semibold mt-0.5"><?php echo esc($ds['label']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <?php foreach ($mockBloodDrives as $drive): ?>
                        <div class="bg-white rounded-xl p-5 shadow-sm border-2 <?php echo $drive['status'] === 'scheduled' ? 'border-blue-200' : ($drive['status'] === 'ongoing' ? 'border-green-300' : 'border-gray-200'); ?>">
                            <div class="flex items-start justify-between mb-3"><div><h3 class="font-bold text-gray-900"><?php echo esc($drive['title']); ?></h3><span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $drive['status'] === 'scheduled' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'; ?>"><?php echo esc(ucfirst($drive['status'])); ?></span></div></div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-gray-600">
                                <div class="flex items-center gap-2"><?php echo iconSvg('map', 'w-4 h-4 text-gray-400'); ?><span><?php echo esc($drive['venue']); ?>, <?php echo esc($drive['barangay']); ?></span></div>
                                <div class="flex items-center gap-2"><?php echo iconSvg('calendar', 'w-4 h-4 text-gray-400'); ?><span><?php echo esc($drive['date']); ?></span></div>
                                <div class="flex items-center gap-2"><?php echo iconSvg('users', 'w-4 h-4 text-gray-400'); ?><span>Target: <?php echo esc($drive['targetDonors']); ?> donors</span></div>
                                <div class="flex items-center gap-2"><?php echo iconSvg('activity', 'w-4 h-4 text-gray-400'); ?><span>Registered: <?php echo esc($drive['registeredDonors']); ?> donors</span></div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-1"><span class="text-xs px-2 py-0.5 bg-blue-50 text-blue-700 rounded-full border border-blue-100"><?php echo esc($drive['organizer']); ?></span><?php if (!empty($drive['unitsCollected'])): ?><span class="text-xs px-2 py-0.5 bg-green-50 text-green-700 rounded-full border border-green-100"><?php echo esc($drive['unitsCollected']); ?> units collected</span><?php endif; ?></div>
                            <div class="mt-3 flex gap-2">
                                <a href="<?php echo esc(dashboardUrl('drives')); ?>" class="flex-1 text-xs py-1.5 border border-gray-200 rounded-lg text-gray-600 font-semibold hover:bg-gray-50 text-center">View Details</a>
                                <?php if ($drive['status'] === 'scheduled'): ?><a href="<?php echo esc(dashboardUrl('drives', ['modal' => 'new_drive'])); ?>" class="flex-1 text-xs py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold text-center">Edit Drive</a><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'transfusions'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">Transfusion Log</h2>
                    <a href="<?php echo esc(dashboardUrl('transfusions', ['modal' => 'new_transfusion'])); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700"><?php echo iconSvg('plus', 'w-4 h-4'); ?> Record Transfusion</a>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm min-w-[600px]">
                            <thead class="bg-gray-50"><tr><?php foreach (['Record ID','Patient','Blood Type','Units','Date','Ordered By','Indication','Outcome'] as $h): ?><th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap"><?php echo esc($h); ?></th><?php endforeach; ?></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($mockTransfusions as $tr): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-500"><?php echo esc($tr['id']); ?></td>
                                        <td class="px-3 py-2.5 font-semibold text-gray-900"><?php echo esc($tr['patientName']); ?></td>
                                        <td class="px-4 py-3"><span class="font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded"><?php echo esc($tr['bloodType']); ?></span></td>
                                        <td class="px-4 py-3 font-bold"><?php echo esc($tr['units']); ?></td>
                                        <td class="px-3 py-2.5 text-gray-600 whitespace-nowrap"><?php echo esc($tr['date']); ?></td>
                                        <td class="px-3 py-2.5 text-gray-600"><?php echo esc($tr['transfusedBy']); ?></td>
                                        <td class="px-4 py-3 text-gray-700"><?php echo esc($tr['component']); ?></td>
                                        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $tr['reaction'] === 'None' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>"><?php echo esc($tr['reaction'] === 'None' ? 'No reaction' : $tr['reaction']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'referrals'): ?>
            <div class="space-y-4 sm:space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base sm:text-xl font-bold text-gray-900">Referral Management</h2>
                    <a href="<?php echo esc(dashboardUrl('referrals', ['modal' => 'new_referral'])); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700"><?php echo iconSvg('plus', 'w-4 h-4'); ?> New Referral</a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php $refStats = [
                        ['label' => 'Pending', 'count' => count(array_filter($mockReferrals, static fn($r) => $r['status'] === 'pending')), 'color' => 'yellow'],
                        ['label' => 'Accepted', 'count' => count(array_filter($mockReferrals, static fn($r) => $r['status'] === 'accepted')), 'color' => 'blue'],
                        ['label' => 'Completed', 'count' => count(array_filter($mockReferrals, static fn($r) => $r['status'] === 'completed')), 'color' => 'green'],
                        ['label' => 'Total', 'count' => count($mockReferrals), 'color' => 'gray'],
                    ]; foreach ($refStats as $s): ?>
                        <div class="bg-white rounded-xl p-3 sm:p-4 shadow-sm border border-gray-100 text-center">
                            <p class="text-2xl font-black <?php echo esc($s['color'] === 'green' ? 'text-green-600' : ($s['color'] === 'blue' ? 'text-blue-600' : ($s['color'] === 'yellow' ? 'text-yellow-600' : 'text-gray-700'))); ?>"><?php echo esc($s['count']); ?></p>
                            <p class="text-xs text-gray-600 font-semibold mt-0.5"><?php echo esc($s['label']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="space-y-3 sm:space-y-4">
                    <?php foreach ($mockReferrals as $ref): ?>
                        <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                            <div class="flex items-start justify-between gap-3 flex-wrap">
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap mb-1">
                                        <span class="font-mono text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded"><?php echo esc($ref['id']); ?></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full font-bold border <?php echo $ref['urgency'] === 'critical' ? 'bg-red-100 text-red-700 border-red-200' : 'bg-yellow-100 text-yellow-700 border-yellow-200'; ?>"><?php echo strtoupper(esc($ref['urgency'])); ?></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $ref['status'] === 'accepted' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700'; ?>"><?php echo esc($ref['status']); ?></span>
                                    </div>
                                    <h3 class="font-bold text-gray-900"><?php echo esc($ref['patientName']); ?>, <?php echo esc($ref['age']); ?> y/o <?php echo esc($ref['gender']); ?></h3>
                                    <p class="text-sm text-gray-600"><strong>Dx:</strong> <?php echo esc($ref['diagnosis']); ?></p>
                                </div>
                                <div class="text-right"><p class="text-sm font-bold text-gray-900"><?php echo esc($ref['referredTo']); ?></p><p class="text-xs text-gray-500"><?php echo esc($ref['referralDate']); ?></p></div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <p class="text-sm text-gray-600"><strong>Reason:</strong> <?php echo esc($ref['reason']); ?></p>
                                <div class="flex items-center justify-between mt-2">
                                    <p class="text-xs text-gray-500">By: <?php echo esc($ref['referringMD']); ?> • <?php echo esc($ref['referralDate']); ?></p>
                                    <div class="flex gap-2">
                                        <a href="#" class="text-xs text-blue-600 hover:underline font-semibold flex items-center gap-1"><?php echo iconSvg('printer', 'w-3 h-3'); ?> Print</a>
                                        <a href="#" class="text-xs text-green-600 hover:underline font-semibold flex items-center gap-1"><?php echo iconSvg('eye', 'w-3 h-3'); ?> View</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'analytics'): ?>
            <div class="space-y-4 sm:space-y-5">
                <h2 class="text-base sm:text-xl font-bold text-gray-900">Analytics & Forecasting Dashboard</h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                        <h3 class="font-bold text-gray-900 mb-4">Monthly Trends (Jan–Jun 2026)</h3>
                        <div class="space-y-2 text-sm text-gray-600">
                            <?php foreach ($monthlyBloodData as $row): ?>
                                <div class="flex justify-between"><span><?php echo esc($row['month']); ?></span><span><?php echo esc($row['donations']); ?> donations</span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                        <h3 class="font-bold text-gray-900 mb-4">10-Day Blood Demand Forecast (O+)</h3>
                        <div class="space-y-2 text-sm text-gray-600">
                            <?php foreach ($mockDemandForecast as $row): ?>
                                <div class="flex justify-between"><span><?php echo esc($row['date']); ?></span><span><?php echo esc($row['predicted']); ?> predicted</span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                    <h3 class="font-bold text-gray-900 mb-4">Health Program Indicators (June 2026)</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php $inds = [
                            ['label' => 'Immunization Coverage', 'value' => '78%', 'target' => '95%', 'status' => 'below'],
                            ['label' => 'Prenatal Coverage (4+ visits)', 'value' => '82%', 'target' => '90%', 'status' => 'below'],
                            ['label' => 'FP Prevalence Rate', 'value' => '64%', 'target' => '60%', 'status' => 'met'],
                            ['label' => 'TB Treatment Success', 'value' => '91%', 'target' => '85%', 'status' => 'met'],
                        ]; foreach ($inds as $ind): ?>
                            <div class="p-3 rounded-xl border border-gray-100 bg-gray-50">
                                <span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $ind['status'] === 'met' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'; ?>"><?php echo $ind['status'] === 'met' ? 'Target Met' : 'Below Target'; ?></span>
                                <p class="text-2xl font-black <?php echo $ind['status'] === 'met' ? 'text-green-600' : 'text-orange-600'; ?>"><?php echo esc($ind['value']); ?></p>
                                <p class="text-xs font-semibold text-gray-700"><?php echo esc($ind['label']); ?></p>
                                <p class="text-xs text-gray-400">Target: <?php echo esc($ind['target']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if ($modal === 'new_request'): ?>
    <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-md max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-900">New Blood Request</h2>
                <a href="<?php echo esc(dashboardUrl('requests')); ?>" class="text-gray-400"><?php echo iconSvg('x', 'w-5 h-5'); ?></a>
            </div>
            <form class="p-5 space-y-4" method="post">
                <input type="hidden" name="action" value="save_request">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Blood Type</label>
                    <select name="bloodType" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                        <option value="">Select</option>
                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?><option value="<?php echo esc($bt); ?>"><?php echo esc($bt); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Quantity (units)</label>
                    <input type="number" name="quantity" min="1" max="10" value="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Urgency Level</label>
                    <select name="urgency" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        <option value="critical">Critical</option>
                        <option value="urgent" selected>Urgent</option>
                        <option value="moderate">Moderate</option>
                        <option value="scheduled">Scheduled</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Clinical Indication</label>
                    <textarea name="patientInfo" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required></textarea>
                </div>
                <div class="flex gap-3 pt-2">
                    <a href="<?php echo esc(dashboardUrl('requests')); ?>" class="flex-1 py-2.5 border border-gray-300 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50 text-center">Cancel</a>
                    <button type="submit" class="flex-1 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($modal === 'new_drive'): ?>
    <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-md max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-900">Schedule Blood Drive</h2>
                <a href="<?php echo esc(dashboardUrl('drives')); ?>" class="text-gray-400"><?php echo iconSvg('x', 'w-5 h-5'); ?></a>
            </div>
            <form class="p-5 space-y-4" method="post">
                <input type="hidden" name="action" value="save_drive">
                <div><label class="block text-sm font-semibold text-gray-700 mb-1">Drive Title</label><input name="title" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-semibold text-gray-700 mb-1">Barangay</label><select name="barangay" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Select</option><?php foreach ($RHU_INFO['catchmentBarangays'] as $barangay): ?><option value="<?php echo esc($barangay); ?>"><?php echo esc($barangay); ?></option><?php endforeach; ?></select></div><div><label class="block text-sm font-semibold text-gray-700 mb-1">Target Donors</label><input type="number" name="targetDonors" min="10" value="30" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div></div>
                <div><label class="block text-sm font-semibold text-gray-700 mb-1">Venue</label><input name="venue" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3"><div><label class="block text-sm font-semibold text-gray-700 mb-1">Date</label><input type="date" name="date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div><div><label class="block text-sm font-semibold text-gray-700 mb-1">Start</label><input type="time" name="startTime" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div><div><label class="block text-sm font-semibold text-gray-700 mb-1">End</label><input type="time" name="endTime" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div></div>
                <div><label class="block text-sm font-semibold text-gray-700 mb-1">Coordinator</label><input name="coordinator" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
                <div class="flex gap-3 pt-2"><a href="<?php echo esc(dashboardUrl('drives')); ?>" class="flex-1 py-2.5 border border-gray-300 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50 text-center">Cancel</a><button type="submit" class="flex-1 py-2.5 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700">Schedule Drive</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($modal === 'new_referral'): ?>
    <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-md max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-900">Create Referral</h2>
                <a href="<?php echo esc(dashboardUrl('referrals')); ?>" class="text-gray-400"><?php echo iconSvg('x', 'w-5 h-5'); ?></a>
            </div>
            <form class="p-5 space-y-4" method="post">
                <input type="hidden" name="action" value="save_referral">
                <div><label class="block text-sm font-semibold text-gray-700 mb-1">Patient Name</label><input name="patientName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-semibold text-gray-700 mb-1">Age</label><input type="number" name="age" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div><div><label class="block text-sm font-semibold text-gray-700 mb-1">Gender</label><select name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option>Female</option><option>Male</option></select></div></div>
                <div><label class="block text-sm font-semibold text-gray-700 mb-1">Refer To</label><select name="referredTo" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Select facility</option><option>Nasugbu District Hospital</option><option>Batangas Provincial Hospital</option><option>Philippine Red Cross - Batangas</option></select></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-semibold text-gray-700 mb-1">Urgency</label><select name="urgency" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="critical">Critical</option><option value="urgent">Urgent</option><option value="moderate">Moderate</option><option value="scheduled">Scheduled</option></select></div><div><label class="block text-sm font-semibold text-gray-700 mb-1">Units Needed</label><input type="number" name="unitsNeeded" min="1" value="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div></div>
                <div><label class="block text-sm font-semibold text-gray-700 mb-1">Reason for Referral</label><textarea name="reason" rows="3" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></textarea></div>
                <div class="flex gap-3 pt-2"><a href="<?php echo esc(dashboardUrl('referrals')); ?>" class="flex-1 py-2.5 border border-gray-300 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50 text-center">Cancel</a><button type="submit" class="flex-1 py-2.5 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700">Send Referral</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
"""

Path('src/app/components/RHUDashboard.php').write_text(content, 'utf-8')
