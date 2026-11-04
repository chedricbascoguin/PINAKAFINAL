<?php
require dirname(__DIR__) . '/includes/nurse-immunization-appointments.php';
$base = ['id'=>1,'resident_id'=>12,'patientName'=>'Test Resident','chiefComplaint'=>'[Child Vaccination & Immunization] Booked visit','consultation_status'=>'Scheduled','date'=>'2026-10-09'];
$rows = [$base,array_replace($base,['id'=>2,'date'=>'2026-10-26'])];
foreach (['Completed','Cancelled','No-show','Referred'] as $index=>$status) $rows[]=array_replace($base,['id'=>10+$index,'consultation_status'=>$status]);
$rows[]=array_replace($base,['id'=>20,'chiefComplaint'=>'[General Health Checkup] Ask about a vaccine']);
$rows[]=array_replace($base,['id'=>21,'is_confidential'=>1]);
$rows[]=array_replace($base,['id'=>22,'diagnosis'=>'Treated','medications'=>'Medicine']);
$pending=nursePendingImmunizationAppointments($rows);
if (array_column($pending,'id')!==[1,2]) throw new RuntimeException('Pending appointments lost, grouped by resident, or include closed/other services');
$many=[];
for ($id=1;$id<=250;$id++) $many[]=array_replace($base,['id'=>$id]);
if (count(nursePendingImmunizationAppointments($many))!==250) throw new RuntimeException('Pending list truncated');
echo "Immunization queue checks passed: all visits, same-resident bookings, overdue/future dates, category/status isolation and no list limit.\n";
