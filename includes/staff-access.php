<?php

function rhuResolveStaffType(string $position, string $role): string
{
    $text = strtoupper(trim($position . ' ' . $role));
    foreach ([
        'MIDWIFE' => ['MIDWIFE'],
        'NURSE' => ['NURSE'],
        'MEDTECH' => ['MEDTECH', 'TECHNOLOGIST'],
        'SANITARY_INSPECTOR' => ['SANITARY', 'INSPECTOR'],
        'DENTAL' => ['DENTAL', 'DENTIST'],
        'SECRETARY' => ['SECRETARY', 'ADMIN_STAFF', 'ADMIN STAFF'],
        'PHYSICIAN' => ['PHYSICIAN', 'DOCTOR'],
        'RHU_ADMIN' => ['RHU_ADMIN', 'SUPER_ADMIN', 'MUNICIPAL HEALTH OFFICER'],
        'BHW' => ['BHW', 'BARANGAY HEALTH'],
    ] as $type => $aliases) {
        foreach ($aliases as $alias) {
            if (strpos($text, $alias) !== false) return $type;
        }
    }
    return strtoupper(trim($role)) === 'ADMIN' ? 'RHU_ADMIN' : '';
}

function rhuStaffCanAccessAdminDashboard(string $staffType): bool
{
    return in_array(strtoupper(trim($staffType)), ['SECRETARY', 'ADMIN_STAFF', 'PHYSICIAN', 'RHU_ADMIN', 'SUPER_ADMIN'], true);
}

function rhuStaffMatchesLoginRole(string $actualType, string $selectedType): bool
{
    return $selectedType === 'SECRETARY'
        ? rhuStaffCanAccessAdminDashboard($actualType)
        : $actualType !== '' && $actualType === $selectedType;
}
