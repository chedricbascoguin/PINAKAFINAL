<?php
require dirname(__DIR__) . '/includes/dental-prescription-sheet.php';
$record = ['id'=>9,'resident_id'=>17,'record_date'=>'2026-10-15','provider_name'=>'Dr Test','title'=>'Test medicine','notes'=>'Take as instructed',
    'meta'=>['medicine_name'=>'Test medicine','dosage'=>'Recorded dose','frequency'=>'Recorded frequency','duration'=>'Recorded duration','quantity'=>'14',
        'inventory_id'=>3,'inventory_item_name'=>'Test stock','inventory_deducted_quantity'=>14,'inventory_unit'=>'caps','dispense_location'=>'RHU Dental Clinic','dispensed_at'=>'2026-10-15T12:30']];
$patient=['id'=>17,'name'=>'Ana Test','address'=>'Test address'];
$html=dentalPrescriptionSheet($record,$patient);
foreach (['Prescription','#17','Ana Test','Test medicine','Recorded dose','Recorded frequency','Recorded duration','14','Take as instructed','Dr Test','Dispensing details','2026-10-15 12:30'] as $expected) {
    if (!str_contains($html,$expected)) throw new RuntimeException('Missing sheet detail: '.$expected);
}
if (str_contains($html,'>Findings<')) throw new RuntimeException('Prescription should not show an empty findings section');
$record['notes']='<script>alert(1)</script>';
if (str_contains(dentalPrescriptionSheet($record,$patient),'<script>')) throw new RuntimeException('Unescaped instructions');
unset($record['meta']);
$legacy=dentalPrescriptionSheet($record,$patient);
if (!str_contains($legacy,'Not recorded') || str_contains($legacy,'Dispensing details')) throw new RuntimeException('Legacy fallback failed');
$placements = dentalPrescriptionPlacements([
    ['id'=>1,'record_type'=>'examination','resident_id'=>17,'record_date'=>'2026-10-15'],
    ['id'=>2,'record_type'=>'examination','resident_id'=>17,'record_date'=>'2026-10-15'],
    ['id'=>3,'record_type'=>'prescription','resident_id'=>17,'record_date'=>'2026-10-15'],
    ['id'=>4,'record_type'=>'prescription','resident_id'=>17,'record_date'=>'2026-10-14'],
    ['id'=>5,'record_type'=>'prescription','resident_id'=>18,'record_date'=>'2026-10-15'],
]);
if (count($placements['embedded']) !== 1 || !isset($placements['embedded'][3]) || count($placements['by_exam'][1]) !== 1) throw new RuntimeException('Prescription attached to wrong patient/date or duplicated');
$embedded = dentalPrescriptionSheet($record,$patient,true);
if (!str_contains($embedded,'dental-prescription-embedded') || str_contains($embedded,'<dt>Patient ID</dt>')) throw new RuntimeException('Embedded sheet is not compact');
echo "Prescription sheet checks passed: complete details, dispensing metadata, legacy records and escaped content.\n";
