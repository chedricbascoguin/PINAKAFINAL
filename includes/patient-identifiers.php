<?php
// Presentation only: always use residents.id, never a visit/test/certificate ID.
function rhuPatientLabel($name, $residentId): string
{
    $name = trim((string)$name);
    $id = (int)$residentId;
    return $name . ($id > 0 ? ' (Patient #' . $id . ')' : '');
}

// Presentation only. Keep stored birth records available for an audited cleanup.
function rhuBirthFatherName(array $row): string
{
    if (trim((string)($row['father_name'] ?? '')) !== '') return trim((string)$row['father_name']);
    $notes = (string)($row['remarks'] ?? $row['weight'] ?? '');
    if (!preg_match('/\bFather:[ \t]*([^\r\n|]*)/i', $notes, $match)) return '';
    return trim((string)preg_replace('/[ \t]*(?:Weight|Length|Place):.*$/i', '', $match[1]));
}
function rhuUniqueBirthDisplay(array $rows): array
{
    $visible = [];
    foreach ($rows as $index=>$row) {
        if (strtolower((string)($row['event_type'] ?? $row['type'] ?? 'birth')) !== 'birth') {
            $visible['other-'.$index] = $row;
            continue;
        }
        $name = preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)($row['name'] ?? $row['full_name'] ?? $row['child_name'] ?? '')), 'UTF-8'));
        $mother = (int)($row['resident_id'] ?? 0);
        $date = substr(trim((string)($row['date'] ?? $row['event_date'] ?? $row['date_recorded'] ?? '')), 0, 10);
        // Incomplete identities remain separate; never group children by mother alone.
        $key = $name !== '' && $mother > 0 && $date !== ''
            ? json_encode([$mother,$date,$name]) : 'unlinked-'.$index;
        if (!isset($visible[$key]) || (rhuBirthFatherName($visible[$key]) === '' && rhuBirthFatherName($row) !== '')) {
            $visible[$key] = $row;
        }
    }
    return array_values($visible);
}
