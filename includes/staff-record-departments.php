<?php
require_once dirname(__DIR__) . '/includes/record-access.php';
require_once dirname(__DIR__) . '/includes/staff-access.php';
function rhuStaffRecordDepartment(array $session): string
{
    if (!empty($session['rhu_staff_login'])) {
        $staff=$session['rhu_staff_login'];
        $type=strtoupper((string)($staff['staff_type'] ?? ''));
        if (in_array($type,['SUPER_ADMIN','RHU_ADMIN'],true)) return 'ADMIN';
        if (in_array($type,['NURSE','MIDWIFE','MEDTECH','DENTAL','SANITARY_INSPECTOR','PHYSICIAN','SECRETARY','BHW'],true)) return $type;
        return rhuResolveStaffType($type . ' ' . ($staff['position'] ?? ''), (string)($staff['role'] ?? ''));
    }
    return !empty($session['rhu_admin_authenticated']) ? 'ADMIN' : '';
}
function rhuDepartmentRecordSources(string $department): array
{
    $all=[
        'resident_health_profiles'=>'Health profiles','sanitary_records'=>'Sanitary records',
        'health_records'=>'Health history','consultations'=>'Consultations', 'appointments'=>'Appointments',
        'disease_cases'=>'Disease cases','pregnancy_records'=>'Maternity records','prenatal_visits'=>'Prenatal visits',
        'prenatal_records'=>'Prenatal records','vaccination_records'=>'Immunizations','dental_records'=>'Dental records',
        'family_planning_records'=>'Family planning','vital_statistics'=>'Vital statistics','certificates'=>'Certificates',
    ];
    $tables=match($department) {
        'ADMIN'=>array_keys($all),
        'DENTAL'=>['dental_records'],
        'MEDTECH'=>['consultations','health_records'],
        'MIDWIFE'=>['pregnancy_records','prenatal_visits','prenatal_records','family_planning_records','vital_statistics','consultations','health_records'],
        'NURSE','BHW'=>['resident_health_profiles','health_records','consultations','disease_cases','vaccination_records'],
        'SANITARY_INSPECTOR'=>['sanitary_records','consultations'],
        'PHYSICIAN'=>['resident_health_profiles','health_records','consultations','disease_cases','certificates'],
        'SECRETARY'=>['appointments','certificates','vital_statistics'],
        default=>[],
    };
    return array_intersect_key($all,array_flip($tables));
}
function rhuRecordService(array $row): string
{
    $text=implode(' ',array_map(static fn($key)=>(string)($row[$key] ?? ''),['service','department','record_type','consultation_type','chief_complaint']));
    if (preg_match('/Record Type:\s*([^\r\n]+)/i',(string)($row['remarks'] ?? ''),$match)) $text.=' '.$match[1];
    foreach ([
        'DENTAL'=>'dental|dentist|tooth|oral|extraction',
        'MEDTECH'=>'laboratory|lab test|medtech|technologist|diagnostic|blood work|\brdt\b|\bfbs\b|hba1c|urinalysis',
        'MIDWIFE'=>'prenatal|maternal|maternity|midwife|pregnancy|postnatal|postpartum|family planning|birth and delivery|newborn birth record',
        'SANITARY_INSPECTOR'=>'sanitary|sanitation|environmental health',
    ] as $department=>$pattern) {
        if (preg_match('/'.$pattern.'/i',$text)) return $department;
    }
    $provider=rhuResolveStaffType((string)($row['_record_provider_type'] ?? ''),(string)($row['_record_provider_role'] ?? ''));
    if (in_array($provider,['DENTAL','MEDTECH','MIDWIFE','SANITARY_INSPECTOR'],true)) return $provider;
    return 'MEDICAL';
}
function rhuDepartmentCanReadRecord(string $department,string $table,array $row): bool
{
    if (rhuRecordIsConfidential($row) || !isset(rhuDepartmentRecordSources($department)[$table])) return false;
    if ($department==='ADMIN') return true;
    if (in_array($table,['consultations','health_records'],true)) {
        $service=rhuRecordService($row);
        return in_array($department,['NURSE','BHW','PHYSICIAN'],true) ? $service==='MEDICAL' : $service===$department;
    }
    return true;
}
