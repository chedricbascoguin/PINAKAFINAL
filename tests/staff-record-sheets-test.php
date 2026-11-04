<?php
require dirname(__DIR__) . '/includes/staff-record-sheets.php';
$exam=['id'=>1,'record_type'=>'examination','resident_id'=>17,'record_date'=>'2026-10-15','findings'=>'Recorded findings','notes'=>'Recorded recommendation','provider_name'=>'Dr Test',
    'meta_json'=>json_encode(['odontogram'=>['11'=>['missing']],'oral_hygiene'=>'Fair','gum_condition'=>'Normal','caries_count'=>0])];
$rx=['id'=>2,'record_type'=>'prescription','resident_id'=>17,'record_date'=>'2026-10-15','title'=>'Test medicine','notes'=>'Recorded instructions','meta_json'=>'{"dosage":"Recorded dose"}'];
$html=staffRecordSheet($exam,'dental_records','Dental records',[$rx]);
foreach (['dental-exam-sheet','record-extracted-mark','Recorded findings','Recorded recommendation','Test medicine','Recorded dose','Dr Test'] as $expected) {
    if (!str_contains($html,$expected)) throw new RuntimeException('Missing sheet detail: '.$expected);
}
if (strpos($html,'<svg') > strpos($html,'Test medicine')) throw new RuntimeException('Prescription not below chart');
if (str_contains($html,'<dt>Patient ID</dt>')) throw new RuntimeException('Patient demographics repeated');
$generic=staffRecordSheet(['id'=>5,'consultation_date'=>'2026-10-01','chief_complaint'=>'Test visit','performed_by'=>99,'provider_name'=>'Test provider','_record_provider_type'=>'Nurse'], 'consultations','Consultations');
if (!str_contains($generic,'service-record-sheet') || !str_contains($generic,'Test provider') || str_contains($generic,'Performed By') || str_contains($generic,'_record_provider_type')) throw new RuntimeException('Generic sheet details incorrect');
if (staffRecordBackUrl('DENTAL',17)!=='DentalDashboard.php?tab=patients&resident_records=17' || staffRecordBackUrl('MEDTECH')!=='MedTechDashboard.php') throw new RuntimeException('Back route incorrect');
$escaped=staffRecordSheet(['id'=>1,'notes'=>'<script>alert(1)</script>'],'consultations','Consultations');
if (str_contains($escaped,'<script>')) throw new RuntimeException('Sheet content not escaped');
echo "History sheets passed: dental chart and X marks, prescription placement, generic sheets, escaping and dashboard Back routes.\n";
