<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if ((int)($_SESSION['rhu_staff_login']['staff_id'] ?? 0) <= 0
    && (empty($_SESSION['rhu_admin_authenticated']) || (int)($_SESSION['user']['staff_id'] ?? $_SESSION['user']['user_id'] ?? 0) <= 0)) {
    header('Location: RHULogin.php'); exit;
}
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/record-access.php';
require_once dirname(__DIR__) . '/includes/fhsis-services.php';
require_once dirname(__DIR__) . '/includes/patient-identifiers.php';
require_once dirname(__DIR__) . '/includes/staff-record-departments.php';
require_once dirname(__DIR__) . '/includes/staff-record-sheets.php';
function recordEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$search = trim((string)($_GET['q'] ?? ''));
$residentId = max(0, (int)($_GET['resident_id'] ?? 0));
$residents = []; $resident = null; $sections = []; $sectionTables = []; $error = '';
$department = rhuStaffRecordDepartment($_SESSION);
$sources = rhuDepartmentRecordSources($department);
if (!$sources) { http_response_code(403); exit('Patient records are unavailable for this staff role.'); }
$departmentLabel = ['ADMIN'=>'All non-confidential','DENTAL'=>'Dental','MEDTECH'=>'Laboratory','MIDWIFE'=>'Maternal and midwife','NURSE'=>'Nursing','SANITARY_INSPECTOR'=>'Sanitary','PHYSICIAN'=>'Medical','SECRETARY'=>'Administrative','BHW'=>'Community health'][$department] ?? 'Department';
try {
    if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable');
    if ($residentId) {
        $stmt = $pdo->prepare('SELECT id, first_name, middle_name, last_name, birthdate, sex, address FROM residents WHERE id = :id');
        $stmt->execute(['id' => $residentId]); $resident = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($resident) {
            foreach ($sources as $table => $label) {
                if (!rhuTableExists($pdo, $table) || !rhuColumnExists($pdo, $table, 'resident_id')) continue;
                $where = 't.resident_id = :id';
                if (rhuColumnExists($pdo, $table, 'is_confidential')) $where .= ' AND COALESCE(t.is_confidential, 0) = 0';
                if (rhuColumnExists($pdo, $table, 'confidentiality')) $where .= " AND LOWER(TRIM(COALESCE(t.confidentiality, ''))) <> 'confidential'";
                $select = 't.*'; $join = '';
                if (in_array($table, ['consultations','health_records','dental_records'], true)) {
                    $providers = [];
                    foreach (['physician_id','health_worker_id','recorded_by_id','performed_by'] as $providerColumn) {
                        if (rhuColumnExists($pdo,$table,$providerColumn)) $providers[] = "NULLIF(t.{$providerColumn}, 0)";
                    }
                    if ($providers) {
                        $providerSql = count($providers) > 1 ? 'COALESCE(' . implode(',', $providers) . ')' : $providers[0];
                        $join = " LEFT JOIN health_workers hw ON hw.id = {$providerSql}";
                        $select .= ", hw.position_title AS _record_provider_type, hw.role AS _record_provider_role, CONCAT_WS(' ',hw.first_name,hw.last_name) AS provider_name";
                    }
                }
                $order = 't.id DESC';
                foreach (['record_date','consultation_date','visit_date','vaccination_date','event_date','issue_date','created_at'] as $dateColumn) {
                    if (rhuColumnExists($pdo,$table,$dateColumn)) { $order = "t.{$dateColumn} DESC,t.id DESC"; break; }
                }
                $stmt = $pdo->prepare("SELECT {$select} FROM `{$table}` t{$join} WHERE {$where} ORDER BY {$order}");
                $stmt->execute(['id' => $residentId]);
                $sectionTables[$label] = $table;
                $sections[$label] = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn($row) => rhuDepartmentCanReadRecord($department,$table,$row)));
                if ($table === 'vital_statistics') $sections[$label] = rhuUniqueBirthDisplay($sections[$label]);
            }
        }
    }
    if ($search !== '') {
        $stmt = $pdo->prepare("SELECT id, first_name, middle_name, last_name, birthdate, sex FROM residents
            WHERE CONCAT_WS(' ', first_name, middle_name, last_name) LIKE :name OR id = :id
            ORDER BY last_name, first_name LIMIT 50");
        $stmt->execute(['name' => '%' . $search . '%', 'id' => ctype_digit($search) ? (int)$search : 0]);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $exception) { error_log('Staff resident records: ' . $exception->getMessage()); $error = 'Records could not be loaded. Please try again.'; }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Resident records | ResiHUnity</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#18332e;font:15px system-ui,sans-serif}main{max-width:1100px;margin:auto;padding:28px 20px}header,section,.patient{background:white;border:1px solid #dce6e2;border-radius:16px;padding:22px;margin-bottom:18px}h1{margin:0 0 8px}h2{font-size:20px;margin:0 0 14px}h3{font-size:16px}p{line-height:1.5;color:#526860}form{display:flex;gap:10px;flex-wrap:wrap}input{flex:1;min-width:200px;border:1px solid #aabfb5;border-radius:8px;padding:12px;font:inherit}button,.open{background:#0f766e;color:white;border:0;border-radius:8px;padding:12px 18px;font:inherit;text-decoration:none}a{color:#0f766e}li{margin:14px 0;line-height:2}article{border-top:1px solid #e2e8f0;padding:16px 0}dl{display:grid;grid-template-columns:minmax(120px,200px) 1fr;gap:8px 18px;margin:0}dt{font-weight:650}dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.error{color:#b91c1c}@media(max-width:550px){dl{grid-template-columns:1fr}dd{margin-bottom:8px}}
</style><link rel="stylesheet" href="styles/staff-record-sheets.css?v=4"><script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(dirname(__DIR__) . "/staff-session-lock.js") ?>"></script>
<link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(dirname(__DIR__) . "/dashboard-transitions.css") ?>">
</head><body class="records-page"><main>
<header class="records-header"><div class="records-header-top"><div><span class="records-eyebrow">PATIENT HISTORY</span><h1><?= recordEscape($departmentLabel) ?> records</h1><p>Consultations, findings and prescriptions in one place.</p></div><a class="back-button" href="<?= recordEscape(staffRecordBackUrl($department, $residentId)) ?>">&larr; Back to dashboard</a></div>
<form method="get" class="patient-search"><label for="resident-search">Find a patient</label><div><input id="resident-search" name="q" value="<?= recordEscape($search) ?>" placeholder="Enter patient name or ID" aria-label="Resident name or ID" required><button>Search</button></div></form></header>
<?php if ($error): ?><section class="error" role="alert"><?= recordEscape($error) ?></section><?php endif; ?>
<?php if ($search !== ''): ?><section><h2>Search results</h2><?php if (!$residents): ?><p>No residents found.</p><?php else: ?><ul><?php foreach ($residents as $person): ?><li><a href="?resident_id=<?= (int)$person['id'] ?>"><?= recordEscape(trim($person['first_name'] . ' ' . $person['middle_name'] . ' ' . $person['last_name'])) ?></a> ? #<?= (int)$person['id'] ?> / Born <?= recordEscape($person['birthdate']) ?></li><?php endforeach; ?></ul><?php endif; ?></section><?php endif; ?>
<?php if ($resident): ?><div class="patient"><div class="patient-avatar" aria-hidden="true"><?= recordEscape(mb_substr($resident['first_name'],0,1) . mb_substr($resident['last_name'],0,1)) ?></div><div class="patient-identity"><span class="records-eyebrow">PATIENT PROFILE</span><h2><?= recordEscape(trim($resident['first_name'] . ' ' . $resident['middle_name'] . ' ' . $resident['last_name'])) ?></h2><p><span><?= recordEscape($resident['sex']) ?></span><span>Born <?= recordEscape($resident['birthdate']) ?></span></p></div><span class="patient-id">Patient #<?= $residentId ?></span></div>
<?php foreach ($sections as $label => $records):
    $table = $sectionTables[$label];
    $placements = $table === 'dental_records' ? dentalPrescriptionPlacements($records) : ['embedded'=>[], 'by_exam'=>[]];
?>
<section class="history-section"><h2><?= recordEscape($label) ?> (<?= count($records) ?>)</h2>
<?php if (!$records): ?><p>No records for this service.</p><?php endif; ?>
<div data-staff-record-scroll="<?= recordEscape($label) ?> history">
<?php foreach ($records as $row):
    if (isset($placements['embedded'][(int)$row['id']])) continue;
?>
<div class="history-entry" data-record-search-row><?= staffRecordSheet($row, $table, $label, $placements['by_exam'][(int)$row['id']] ?? []) ?>
<?php if (isset(fhsisStorageFields()[$table]) && in_array($department,['MIDWIFE','NURSE','PHYSICIAN','DENTAL','MEDTECH','SANITARY_INSPECTOR'],true) && fhsisRecorded($row)): ?><p><a href="FhsisServiceRecord.php?<?= recordEscape(http_build_query(['resident_id'=>$residentId,'source_table'=>$table,'source_id'=>(int)$row['id']])) ?>">Document qualifying FHSIS services</a></p><?php endif; ?></div>
<?php endforeach; ?></div></section>
<?php endforeach; ?>
<?php elseif ($residentId && !$error): ?><section><p>Resident not found.</p></section><?php endif; ?>
</main><link rel="stylesheet" href="styles/staff-record-search.css?v=1">
<script defer src="assets/js/staff-record-search.js?v=2"></script>
<link rel="stylesheet" href="styles/staff-record-scroll.css?v=2">
<style data-record-scroll-critical>
@media screen {
[data-staff-record-scroll], .staff-record-scroll {
  display: block; box-sizing: border-box; min-height: 0 !important;
  max-height: 420px !important; max-height: min(45vh, 420px) !important;
  overflow-y: auto !important; overflow-x: auto !important;
  overscroll-behavior: contain; scrollbar-gutter: stable;
}
[data-staff-record-scroll]::-webkit-scrollbar, .staff-record-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
[data-staff-record-scroll]::-webkit-scrollbar-thumb, .staff-record-scroll::-webkit-scrollbar-thumb { background: #8cb5a5; border: 2px solid #f1f7f4; border-radius: 10px; }
}
@media print { [data-staff-record-scroll], .staff-record-scroll { max-height: none !important; overflow: visible !important; } }
</style>

<script src="assets/js/staff-record-scroll.js?v=4" defer></script>
<script src="assets/js/staff-resident-record-groups.js?v=2" defer></script>
</body></html>

