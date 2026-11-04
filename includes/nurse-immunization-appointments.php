<?php
require_once dirname(__DIR__) . '/includes/resident-qr.php';
function nursePendingImmunizationAppointments(array $consultations): array
{
    $pending = [];
    foreach ($consultations as $row) {
        $row['chief_complaint'] = $row['chiefComplaint'] ?? $row['chief_complaint'] ?? '';
        $row['medications_prescribed'] = $row['medications_prescribed'] ?? $row['medications'] ?? '';
        if ((int)($row['id'] ?? 0) <= 0 || (int)($row['resident_id'] ?? 0) <= 0 || rhuRecordIsConfidential($row) || !residentQrPending($row)) continue;
        $category = residentQrCategory($row);
        if (!preg_match('/vaccination|immunization|vaccine/i', $category !== '' ? $category : $row['chief_complaint'])) continue;
        $pending[(int)$row['id']] = $row;
    }
    $pending = array_values($pending);
    usort($pending, static fn($a, $b) => [($a['date'] ?? $a['consultation_date'] ?? ''), ($a['id'] ?? 0)] <=> [($b['date'] ?? $b['consultation_date'] ?? ''), ($b['id'] ?? 0)]);
    return $pending;
}
