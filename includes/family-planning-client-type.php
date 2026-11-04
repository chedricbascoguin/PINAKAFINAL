<?php
// Return only the history fields needed by the client-type suggestion.
function fpClientHistoryEntry(array $row, bool $native = false): ?array
{
    if (rhuRecordIsConfidential($row)) return null;
    $status = strtolower(trim((string)($row[$native ? 'status' : 'consultation_status'] ?? '')));
    if (in_array($status, ['cancelled', 'canceled', 'no-show', 'no show', 'rejected', 'declined'], true)) return null;
    $type = $native ? trim((string)($row['acceptor_type'] ?? '')) : '';
    if (!$native && preg_match('/^Family Planning\s*-\s*([^:]+):/i', (string)($row['chief_complaint'] ?? ''), $match)) $type = trim($match[1]);
    $event = $native ? null : fhsisFpEvent($row);
    $accepted = ['New Acceptor', 'Continuing User', 'Changing Method', 'Changing Clinic', 'Restarting User', 'Dropout'];
    if (!$event && !in_array($type, $accepted, true)) return null;
    if (!$native && !$event && ($row['diagnosis'] ?? '') !== 'Family Planning Counseling') return null;
    $method = $event['method'] ?? ($native ? ($row['contraceptive_method'] ?? '') : explode(' | Supplies/medications: ', (string)($row['treatment'] ?? ''))[0]);
    $date = substr((string)($row[$native ? 'last_supply_date' : 'consultation_date'] ?? ''), 0, 10);
    if (!fhsisDate($date)) return null;
    return ['resident_id' => (int)($row['resident_id'] ?? 0), 'date' => $date,
        'method' => trim($method), 'dropout' => $type === 'Dropout' || $status === 'dropout' || ($event['event'] ?? '') === 'dropout',
        'id' => (int)($row['id'] ?? 0), 'source' => $native ? 'family_planning_records' : 'consultations'];
}
function fpClientTypeHistory(PDO $pdo, int $excludeAppointment = 0): array
{
    $entries = [];
    $stmt = $pdo->prepare("SELECT * FROM consultations WHERE id <> ? AND (chief_complaint LIKE 'Family Planning - %' OR remarks LIKE '%FHSIS_FP_V1:%')");
    $stmt->execute([$excludeAppointment]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $entry = fpClientHistoryEntry($row);
        if ($entry && $entry['resident_id'] > 0) $entries[] = $entry;
    }
    if (rhuTableExists($pdo, 'family_planning_records')) {
        // Some installations store family planning in its own table.
        foreach (['resident_id', 'acceptor_type', 'contraceptive_method', 'last_supply_date'] as $column) {
            if (!rhuColumnExists($pdo, 'family_planning_records', $column)) throw new RuntimeException('Incomplete family planning history.');
        }
        foreach ($pdo->query('SELECT * FROM family_planning_records')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entry = fpClientHistoryEntry($row, true);
            if ($entry && $entry['resident_id'] > 0) $entries[] = $entry;
        }
    }
    return $entries;
}
