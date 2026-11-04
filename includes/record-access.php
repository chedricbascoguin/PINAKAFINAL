<?php
function rhuRecordStaffIsMedtech(array $staff): bool
{
    $role = strtoupper((string)($staff['staff_type'] ?? ''));
    return str_contains($role, 'MEDTECH') || str_contains($role, 'TECHNOLOGIST');
}
function rhuCanReadConfidentialRecord(array $staff, array $record): bool
{
    $id = (int)($staff['staff_id'] ?? 0);
    return $id > 0 && rhuRecordStaffIsMedtech($staff)
        && (int)($record['created_by_staff_id'] ?? 0) === $id;
}
function rhuRecordIsConfidential(array $record): bool
{
    return !empty($record['is_confidential'])
        || strtolower(trim((string)($record['confidentiality'] ?? ''))) === 'confidential';
}
