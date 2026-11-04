<?php
if (session_status()===PHP_SESSION_NONE) session_start();
if ((int)($_SESSION['rhu_staff_login']['staff_id']??0)<=0) { header('Location: RHULogin.php'); exit; }
require_once dirname(__DIR__).'/includes/portal.php';
require_once dirname(__DIR__).'/includes/fhsis-services.php';
header('Cache-Control: no-store, private');
function serviceEscape($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$department=rhuStaffRecordDepartment($_SESSION);
$residentId=(int)($_GET['resident_id']??$_POST['resident_id']??0);
$sourceId=(int)($_GET['source_id']??$_POST['source_id']??0);
$sourceTable=(string)($_GET['source_table']??$_POST['source_table']??'');
$error=''; $source=null; $resident=null; $history=[];
try {
    if (!$pdo instanceof PDO) throw new RuntimeException('Database connection is unavailable.');
    $source=fhsisSource($pdo,$sourceTable,$sourceId,$residentId,$department);
    $stmt=$pdo->prepare('SELECT id,first_name,last_name,birthdate,sex FROM residents WHERE id=?'); $stmt->execute([$residentId]); $resident=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$resident) throw new RuntimeException('Resident not found.');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!portalVerifyCsrf()) throw new RuntimeException('Your session expired. Reload the page and try again.');
        if (($_POST['action']??'')==='void') {
            fhsisWithdrawService($pdo,$sourceTable,$sourceId,$residentId,(string)($_POST['record_id']??''),$_SESSION['rhu_staff_login']);
        } else fhsisSaveService($pdo,$_POST,$_SESSION);
        $_SESSION['fhsis_service_success']='Reporting documentation updated. The next export will recalculate from the verified records.';
        header('Location: FhsisServiceRecord.php?'.http_build_query(['resident_id'=>$residentId,'source_table'=>$sourceTable,'source_id'=>$sourceId])); exit;
    }
    $history=fhsisServiceHistory($source,$sourceTable);
} catch (Throwable $e) { if ($e instanceof PDOException) { error_log('FHSIS documentation: '.$e->getMessage()); $error='Reporting documentation could not be saved or loaded. Please try again.'; } else $error=$e->getMessage(); }
$catalog=fhsisIndicators(); $choices=array_filter($catalog,static fn($metric)=>fhsisIndicatorAllowed($metric,$department));
$success=$_SESSION['fhsis_service_success']??''; unset($_SESSION['fhsis_service_success']);
$defaultDate=''; foreach (['consultation_date','record_date','vaccination_date','visit_date','event_date','last_supply_date','last_checkup_date'] as $field) if (!empty($source[$field])) { $defaultDate=substr($source[$field],0,10); break; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FHSIS service documentation</title>
<style>body{margin:0;background:#f1f5f9;color:#193c35;font:15px system-ui,sans-serif}main{max-width:980px;margin:auto;padding:24px}section,header{padding:24px;background:white;border:1px solid #d5e4df;border-radius:14px;margin-bottom:18px}h1{font-size:24px}p{line-height:1.6}label{display:block;font-weight:650;margin:16px 0 7px}input,select,textarea{box-sizing:border-box;width:100%;padding:12px;border:1px solid #9db5ac;border-radius:8px;font:inherit}input[type=checkbox]{width:auto;margin-right:9px}button{background:#087f70;color:white;border:0;border-radius:8px;padding:12px 20px;font:inherit;cursor:pointer}a{color:#067564}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.error{color:#9b1c1c}.success{color:#087346}.hint{font-size:13px;color:#536e64}[hidden]{display:none!important}article{border-top:1px solid #dce8e2;padding:15px 0}details{margin:16px 0}@media(max-width:650px){.grid{grid-template-columns:1fr}}</style>
<script src="assets/js/staff-session-lock.js?v=<?= (int)filemtime(dirname(__DIR__).'/staff-session-lock.js') ?>"></script></head><body><main>
<header><a href="StaffResidentRecords.php?resident_id=<?= $residentId ?>">&larr; Resident records</a><h1>FHSIS service documentation</h1><p>Connect a documented service or outcome to its reporting indicator. Age groups, duplicate checks and totals are calculated by the system. Select a criterion only when the clinical record establishes every part of it.</p>
<?php if ($resident): ?><strong><?= serviceEscape($resident['first_name'].' '.$resident['last_name']) ?></strong><p>Born <?= serviceEscape($resident['birthdate']) ?> &middot; <?= serviceEscape($resident['sex']) ?> &middot; <?= serviceEscape(ucwords(str_replace('_',' ',$sourceTable))) ?> #<?= $sourceId ?></p><?php endif; ?></header>
<?php if ($error): ?><section role="alert" class="error"><?= serviceEscape($error) ?></section><?php endif; ?>
<?php if ($success): ?><section role="status" class="success"><?= serviceEscape($success) ?></section><?php endif; ?>
<?php if ($resident && $source): ?><section><form method="post" id="service-form">
<input type="hidden" name="csrf_token" value="<?= serviceEscape(portalCsrfToken()) ?>"><input type="hidden" name="resident_id" value="<?= $residentId ?>"><input type="hidden" name="source_table" value="<?= serviceEscape($sourceTable) ?>"><input type="hidden" name="source_id" value="<?= $sourceId ?>">
<label for="filter">Find the documented service</label><input id="filter" type="search" placeholder="Search by program or service, for example anemia, ANC, nutrition, rabies">
<label for="indicator">Qualifying service / outcome</label><select required id="indicator" name="indicator_key"><option value="">Select documented service</option>
<?php foreach ($choices as $key=>$metric): ?><option value="<?= serviceEscape($key) ?>"><?= serviceEscape($metric['section'].' / '.$metric['context'].' / '.$metric['label']) ?></option><?php endforeach; ?></select>
<p class="hint" id="criteria"></p>
<div class="grid"><div><label for="date">Actual service / outcome date</label><input required type="date" id="date" name="service_date" value="<?= serviceEscape($defaultDate) ?>" max="<?= (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>"></div>
<div><label for="reference">Supporting clinical entry / register reference</label><input required maxlength="500" name="evidence_reference" id="reference" placeholder="Entry identifying the completed service and qualifying findings"></div></div>
<div data-field="pregnancy" hidden><label for="pregnancy">Pregnancy record ID</label><input type="number" min="1" id="pregnancy" name="pregnancy_id"><label><input type="checkbox" name="pregnancy_verified" value="1">The record confirms this pregnancy episode meets the indicator at the service date.</label></div>
<div data-field="mother_link" hidden><label for="mother">Mother's resident ID</label><input type="number" min="1" id="mother" name="mother_resident_id"><p class="hint">The documented mother-child relationship and mother's age at birth are checked.</p></div>
<div data-field="event_key" hidden><label for="event-key">Individual fetal event identifier</label><input maxlength="100" id="event-key" name="event_key" placeholder="Same documented event identifier across records"></div>
<div data-field="subject_sex" hidden><label for="subject-sex">Documented fetal sex</label><select id="subject-sex" name="subject_sex"><option value="">Select documented sex</option><option value="male">Male</option><option value="female">Female</option><option value="unknown">Unknown / not documented</option></select></div>
<div data-field="household" hidden><label for="household">Stable household register identifier</label><input maxlength="100" id="household" name="household_key"><p class="hint">Use the same official household identifier on every assessment. Multiple residents or inspections of one household count once.</p></div>
<div data-field="minimum_visits" hidden><label for="visits">Actual visit dates</label><textarea id="visits" name="visit_dates" rows="3" placeholder="YYYY-MM-DD, one date per visit"></textarea><p class="hint">Duplicate and future dates cannot establish completion. Use visits from the linked episode.</p></div>
<div data-field="scheduled" hidden><label><input type="checkbox" name="schedule_verified" value="1">The documented visits meet the required clinical schedule.</label></div>
<label><input type="checkbox" required name="criteria_confirmed" value="1">I verified the complete selected criterion against the supporting record, including eligibility, service completion and outcome where required.</label>
<p class="hint">A prescription, appointment or counseling note alone does not establish treatment, completion or acceptance. If the supporting record changes, its reporting documentation must be reviewed again.</p><button>Save verified service</button>
</form></section>
<?php if ($department==='MEDTECH'): ?><section><p>Pregnancy HIV, syphilis and hepatitis B reporting is approved from the private laboratory form. It releases aggregate counts without adding the private result to ordinary resident records.</p><a href="MedTechDashboard.php?tab=confidential">Open private laboratory records</a></section><?php endif; ?>
<section><h2>Documentation for this record</h2><?php if (!$history): ?><p>No reporting documentation has been attached yet.</p><?php endif; ?>
<?php foreach ($history as $entry): ?><article><strong><?= serviceEscape($catalog[$entry['indicator_key']]['label']??'Unavailable indicator') ?></strong><p><?= serviceEscape($entry['service_date'].' / '.$entry['status']) ?></p>
<?php if ((int)$entry['recorded_by_staff_id']===(int)$_SESSION['rhu_staff_login']['staff_id'] && $entry['status']==='verified'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= serviceEscape(portalCsrfToken()) ?>"><input type="hidden" name="resident_id" value="<?= $residentId ?>"><input type="hidden" name="source_table" value="<?= serviceEscape($sourceTable) ?>"><input type="hidden" name="source_id" value="<?= $sourceId ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="record_id" value="<?= serviceEscape($entry['id']) ?>"><button>Withdraw from reporting</button></form><?php endif; ?></article><?php endforeach; ?></section>
<?php endif; ?>
</main><script type="application/json" id="definitions"><?= json_encode($choices,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><script>
const definitions=JSON.parse(document.getElementById('definitions').textContent);const select=document.getElementById('indicator');
if(select){const options=[...select.options];document.getElementById('filter').addEventListener('input',event=>{const term=event.target.value.toLowerCase();for(const option of options)option.hidden=!!option.value&&!option.textContent.toLowerCase().includes(term)});
select.addEventListener('change',()=>{const metric=definitions[select.value];document.getElementById('criteria').textContent=metric?metric.context+' — '+metric.label:'';for(const box of document.querySelectorAll('[data-field]')){const key=box.dataset.field;const show=metric&&(key==='household'?metric.unit==='household':!!metric[key]);box.hidden=!show;for(const input of box.querySelectorAll('input,textarea')){input.disabled=!show;input.required=!!show}}});select.dispatchEvent(new Event('change'));}
</script></body></html>
