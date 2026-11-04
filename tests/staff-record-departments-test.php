<?php
require dirname(__DIR__) . '/includes/staff-record-departments.php';
function departmentCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
foreach (['DENTAL','MEDTECH','MIDWIFE','NURSE','SANITARY_INSPECTOR','PHYSICIAN','SECRETARY','BHW'] as $role) {
    departmentCheck(rhuStaffRecordDepartment(['rhu_staff_login'=>['staff_type'=>$role]])===$role,'Department resolution failed');
    departmentCheck(!isset(rhuDepartmentRecordSources($role)['confidential_lab_tests']),'Confidential source exposed');
}
departmentCheck(rhuStaffRecordDepartment(['rhu_admin_authenticated'=>true])==='ADMIN','Admin department failed');
departmentCheck(rhuStaffRecordDepartment(['rhu_admin_authenticated'=>true,'rhu_staff_login'=>['staff_type'=>'DENTAL']])==='DENTAL','Mixed session broadens department');
departmentCheck(rhuDepartmentCanReadRecord('DENTAL','dental_records',['performed_by'=>99]),'Another dentist in same service hidden');
departmentCheck(!rhuDepartmentCanReadRecord('MEDTECH','dental_records',[]),'Cross-service record exposed');
departmentCheck(rhuDepartmentCanReadRecord('MEDTECH','consultations',['chief_complaint'=>'Laboratory test']), 'Lab history hidden');
departmentCheck(!rhuDepartmentCanReadRecord('NURSE','consultations',['chief_complaint'=>'Laboratory test']), 'Lab history exposed to nursing folder');
departmentCheck(rhuDepartmentCanReadRecord('MIDWIFE','consultations',['chief_complaint'=>'Prenatal examination']), 'Maternal history hidden');
departmentCheck(!rhuDepartmentCanReadRecord('MIDWIFE','consultations',['chief_complaint'=>'Dental examination']), 'Dental history exposed to maternal folder');
departmentCheck(rhuDepartmentCanReadRecord('NURSE','health_records',['remarks'=>'Record Type: OPD Triage']), 'Nursing history hidden');
departmentCheck(!rhuDepartmentCanReadRecord('NURSE','health_records',['remarks'=>'Record Type: Dental Mouth Examination']), 'Dental snapshot exposed to nursing folder');
departmentCheck(rhuDepartmentCanReadRecord('MEDTECH','consultations',['chief_complaint'=>'Result review','_record_provider_type'=>'Medical Technologist']), 'Provider department ignored');
departmentCheck(rhuDepartmentCanReadRecord('ADMIN','dental_records',[]),'Admin non-confidential access missing');
departmentCheck(!rhuDepartmentCanReadRecord('ADMIN','health_records',['is_confidential'=>1]),'Admin confidential exception bypassed');
departmentCheck(rhuDepartmentRecordSources('UNKNOWN')===[],'Unknown staff role can see records');
echo "Department record checks passed: service scoping, all same-service providers, administrator scope and confidential exclusions.\n";
