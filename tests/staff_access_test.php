<?php
require_once __DIR__ . '/../staff-access.php';

function checkStaffAccess(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach (['SECRETARY', 'ADMIN_STAFF', 'PHYSICIAN', 'RHU_ADMIN', 'SUPER_ADMIN'] as $type) {
    checkStaffAccess(rhuStaffCanAccessAdminDashboard($type), "$type must have admin access");
    checkStaffAccess(rhuStaffMatchesLoginRole($type, 'SECRETARY'), "$type must use the secretary login");
}
foreach (['MIDWIFE', 'NURSE', 'MEDTECH', 'DENTAL', 'BHW', 'SANITARY_INSPECTOR', '', 'Staff'] as $type) {
    checkStaffAccess(!rhuStaffCanAccessAdminDashboard($type), "$type must not have admin access");
    checkStaffAccess(!rhuStaffMatchesLoginRole($type, 'SECRETARY'), "$type must not use the secretary login");
}
checkStaffAccess(rhuResolveStaffType('Admin Secretary', 'Staff') === 'SECRETARY', 'Recognize secretary position');
checkStaffAccess(rhuResolveStaffType('ADMIN_STAFF', 'ADMIN_STAFF') === 'SECRETARY', 'Support existing admin staff');
checkStaffAccess(rhuResolveStaffType('Rural Health Physician', 'Doctor') === 'PHYSICIAN', 'Preserve physician identity');
checkStaffAccess(rhuResolveStaffType('', 'Staff') === '', 'Unknown roles must not gain access');
checkStaffAccess(rhuStaffMatchesLoginRole('NURSE', 'NURSE'), 'Keep nurse login working');
checkStaffAccess(!rhuStaffMatchesLoginRole('MIDWIFE', 'NURSE'), 'Reject mismatched roles');
echo "Staff access checks passed.\n";
