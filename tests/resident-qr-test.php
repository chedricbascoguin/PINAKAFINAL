<?php
require dirname(__DIR__) . '/includes/resident-qr.php';
require dirname(__DIR__) . '/includes/staff-session-lock.php';
function qrCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$services = ['DENTAL'=>'Dental Consultation & Oral Health', 'MEDTECH'=>'Laboratory Test & Blood Work', 'MIDWIFE'=>'Prenatal & Maternal Care', 'SANITARY_INSPECTOR'=>'Sanitary Inspection & Clearance', 'NURSE'=>'Child Vaccination & Immunization', 'PHYSICIAN'=>'General Medical Consultation'];
foreach ($services as $owner=>$category) {
    $row = ['chief_complaint'=>"[$category] Test complaint",'remarks'=>"Appointment Category: $category | Booking Date: 2026-10-23",'consultation_status'=>'Scheduled','diagnosis'=>'Pending OPD Triage'];
    foreach (array_keys($services) as $viewer) qrCheck(residentQrCanRead($viewer,$row) === ($owner === $viewer), "$viewer saw $owner appointment or lost own appointment");
    foreach (['ADMIN','SECRETARY'] as $viewer) qrCheck(residentQrCanRead($viewer,$row), 'Administrative queue missing');
    foreach (['Completed','Cancelled','No-show','Referred','Unknown'] as $status) qrCheck(!residentQrCanRead($owner,array_replace($row,['consultation_status'=>$status])), 'Terminal/unknown status exposed');
    foreach (['is_confidential'=>1,'confidentiality'=>'confidential'] as $key=>$value) qrCheck(!residentQrCanRead('ADMIN',array_replace($row,[$key=>$value])), 'Confidential appointment exposed');
    qrCheck(!residentQrCanRead($owner,array_replace($row,['consultation_notes'=>"Status: Completed"])), 'Completed note ignored');
    qrCheck(!residentQrCanRead($owner,array_replace($row,['diagnosis'=>'Treated','medications_prescribed'=>'Medicine'])), 'Completed legacy visit exposed');
    qrCheck(residentQrCanRead($owner,array_replace($row,['consultation_status'=>''])), 'Legacy pending visit missing');
    qrCheck(!residentQrCanRead($owner,array_replace($row,['consultation_status'=>'','diagnosis'=>'Treated'])), 'Legacy completed visit exposed');
}
qrCheck(residentQrDepartment(['chief_complaint'=>'[General Health Checkup] Dental pain']) === 'NURSE', 'Complaint changed department');
qrCheck(residentQrDepartment(['chief_complaint'=>'[General Medical Consultation] Blood concern']) === 'PHYSICIAN', 'Complaint changed physician booking');
qrCheck(residentQrDepartment(['_record_provider_type'=>'Dentist']) === 'DENTAL', 'Legacy provider fallback missing');
qrCheck(!residentQrCanRead('NURSE',['diagnosis'=>'Pending OPD Triage']), 'Unknown service assigned to nursing');
qrCheck(!residentQrCanRead('',['chief_complaint'=>'[General Medical Consultation]']), 'Unknown role allowed');
qrCheck(rhuLockReturnUrl('StaffResidentAppointments.php?resident_id=12') === 'StaffResidentAppointments.php?resident_id=12', 'PIN unlock lost scanned resident');
echo "Resident QR policy checks passed: department matrix, pending statuses, confidentiality, legacy records and PIN return.\n";
