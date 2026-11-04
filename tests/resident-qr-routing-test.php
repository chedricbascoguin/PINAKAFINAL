<?php
require dirname(__DIR__) . '/includes/resident-qr.php';
require dirname(__DIR__) . '/includes/nurse-immunization-appointments.php';
function routeCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$services = ['DENTAL'=>['Dental Consultation & Oral Health','DentalDashboard.php','examinations'], 'MEDTECH'=>['Laboratory Test & Blood Work','MedTechDashboard.php','rapid'], 'MIDWIFE'=>['Prenatal & Maternal Care','MidwifeDashboard.php','overview'], 'SANITARY_INSPECTOR'=>['Sanitary Inspection & Clearance','SanitaryDashboard.php','overview'], 'NURSE'=>['General Health Checkup','NurseDashboard.php','opd'], 'PHYSICIAN'=>['General Medical Consultation','RHUAdminDashboard.php','consultations']];
foreach ($services as $role=>[$category,$page,$tab]) {
    $row = ['id'=>71,'resident_id'=>12,'chief_complaint'=>"[$category] Test",'consultation_status'=>'Scheduled','consultation_date'=>'2026-11-12','consultation_time'=>'09:30:00'];
    $url = residentQrAppointmentUrl($role,$row);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    routeCheck(parse_url($url, PHP_URL_PATH) === $page && $query['tab'] === $tab, "$role routed to wrong workflow");
    routeCheck($query['qr_appointment'] === '71' && $query['resident_id'] === '12', 'Exact visit identity missing');
    routeCheck(($query['modal'] ?? '') !== 'edit_qr_appointment', "$role opened an extra process");
    if ($role === 'DENTAL') routeCheck($query['modal'] === 'new_exam' && $query['consultation_id'] === '71', 'Dental existing workflow missing');
    if ($role === 'MEDTECH') routeCheck($query['modal'] === 'test' && $query['consultation_id'] === '71', 'Laboratory existing workflow missing');
    routeCheck(residentQrAppointmentUrl($role,array_replace($row,['consultation_status'=>'Completed'])) === '', 'Closed visit linked');
}
$vaccine = ['id'=>72,'resident_id'=>12,'chief_complaint'=>'[Child Vaccination & Immunization] Follow-up','consultation_status'=>'Scheduled'];
$url = residentQrAppointmentUrl('NURSE',$vaccine);
parse_str(parse_url($url,PHP_URL_QUERY),$query);
routeCheck($query['tab']==='immunization' && $query['modal']==='new_immunization' && $query['qr_appointment']==='72', 'Vaccine routed to wrong visit/workflow');
routeCheck(residentQrAppointmentUrl('DENTAL',$vaccine)==='', 'Other department received appointment link');
routeCheck(residentQrAppointmentUrl('NURSE',array_replace($vaccine,['chief_complaint'=>'[General Health Checkup] Ask about immunization'])) !== $url, 'Complaint overrode booking category');
$fp = ['id'=>73,'resident_id'=>40,'chief_complaint'=>'Family Planning - Continuing User: Three or more pregnancies or live births','consultation_status'=>'Scheduled'];
parse_str(parse_url(residentQrAppointmentUrl('MIDWIFE', $fp), PHP_URL_QUERY), $query);
routeCheck($query['tab'] === 'fp' && $query['modal'] === 'new_fp' && $query['qr_appointment'] === '73' && $query['resident_id'] === '40', 'Family planning must open exact resident in existing workflow');
// Exercise the actual loader with two visits for one resident and an unrelated resident.
class QrRoutingStatement extends PDOStatement {
    private array $rows;
    private array $args = [];
    public function __construct(array $rows) { $this->rows = $rows; }
    public function execute(?array $params = null): bool { $this->args = $params ?? []; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        foreach ($this->rows as $row) if ($row['id'] === $this->args[0] && $row['resident_id'] === $this->args[1]) return $row;
        return false;
    }
}
class QrRoutingDatabase extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        routeCheck(str_contains($query, 'c.id = ? AND c.resident_id = ?'), 'Loader must match both appointment and owner');
        return new QrRoutingStatement([
            ['id'=>73,'resident_id'=>40,'chief_complaint'=>'Family Planning - Continuing User','consultation_status'=>'Scheduled'],
            ['id'=>74,'resident_id'=>40,'chief_complaint'=>'Family Planning - Other visit','consultation_status'=>'Scheduled'],
            ['id'=>75,'resident_id'=>41,'chief_complaint'=>'[Dental Consultation] Other resident','consultation_status'=>'Scheduled'],
        ]);
    }
}
$fakeDatabase = new QrRoutingDatabase();
routeCheck(residentQrLoadAppointment($fakeDatabase, 'MIDWIFE', 73, 40)['id'] === 73, 'Selected visit replaced by another booking');
foreach ([['MIDWIFE',73,41], ['DENTAL',73,40], ['MIDWIFE',75,41]] as [$role,$visit,$owner]) {
    $rejected = false;
    try { residentQrLoadAppointment($fakeDatabase,$role,$visit,$owner); } catch (RuntimeException $expected) { $rejected = true; }
    routeCheck($rejected, 'Loader allowed a mismatched resident or department');
}
// Execute the actual vaccine prefill builder with two bookings for the same person.
$source = file_get_contents(dirname(__DIR__) . '/NurseDashboard.php');
$start = strpos($source,'$qrRequestedAppointmentId =');
$end = strpos($source,"\n?>",$start);
$builder = substr($source,$start,$end-$start);
$opdConsultations = [
    ['id'=>99,'resident_id'=>12,'chiefComplaint'=>'[Child Vaccination & Immunization] Newer visit','date'=>'2026-12-01','consultation_status'=>'Scheduled'],
    ['id'=>72,'resident_id'=>12,'chiefComplaint'=>'[Child Vaccination & Immunization] Selected visit','date'=>'2026-11-12','consultation_status'=>'Scheduled'],
];
$vaccineSchedules = []; $selectedResidentId = 12;
$_GET = ['qr_appointment'=>'72']; eval($builder);
routeCheck($immunizationAppointments[12]['id']===72 && $immunizationAppointments[12]['date']==='2026-11-12', 'Selected vaccine replaced by newest appointment');
$_GET = ['qr_appointment'=>'555']; eval($builder);
routeCheck(!isset($immunizationAppointments[12]), 'Unavailable visit silently replaced by another');
$_GET = []; eval($builder);
routeCheck($immunizationAppointments[12]['id']===99, 'Normal vaccine prefill regressed');
echo "Appointment routing checks passed: every staff workflow, existing service workflows, owner matching, department isolation and unavailable bookings.\n";
