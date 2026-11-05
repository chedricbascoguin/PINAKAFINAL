<?php
// Derive expiry at read time so it updates without a scheduled background job.
function rhuAppointmentExpired(array $row, ?string $today = null): bool
{
    $status = strtolower(trim((string)($row['consultation_status'] ?? $row['status'] ?? '')));
    if (in_array($status, ['due date expired', 'expired'], true)) return true;
    $notes = (string)($row['consultation_notes'] ?? '') . "\n" . (string)($row['remarks'] ?? '');
    if (in_array($status, ['', 'scheduled'], true) && preg_match('/^\s*Status:\s*([^|\r\n]+)/mi', $notes, $match)) {
        $status = strtolower(trim($match[1]));
    }
    if (!in_array($status, ['', 'scheduled', 'pending', 'in progress', 'in_progress', 'in-progress', 'waiting', 'approved', 'confirmed'], true)) return false;
    $diagnosis = strtolower(trim((string)($row['diagnosis'] ?? '')));
    if ($diagnosis !== '' && $diagnosis !== 'pending opd triage' && ($status === '' || !empty($row['medications_prescribed']) || !empty($row['medications']))) return false;
    $date = substr((string)($row['consultation_date'] ?? $row['date'] ?? ''), 0, 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) return false;
    $today ??= (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    return $date < $today;
}

function rhuWithoutExpiredAppointments(array $rows): array
{
    return array_values(array_filter($rows, static fn(array $row): bool => !rhuAppointmentExpired($row)));
}

function rhuNotifyExpiredAppointments(PDO $pdo, int $residentId): int
{
    if ($residentId <= 0 || $pdo->inTransaction()) return 0;
    portalEnsureNotificationTables($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS appointment_expiry_notices (
        consultation_id BIGINT UNSIGNED PRIMARY KEY,
        resident_id BIGINT UNSIGNED NOT NULL,
        notified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->beginTransaction();
    try {
        // Lock the resident row to serialize concurrent dashboard/poll requests.
        $lock = $pdo->prepare('SELECT id FROM residents WHERE id = ? FOR UPDATE');
        $lock->execute([$residentId]);
        $stmt = $pdo->prepare('SELECT c.* FROM consultations c
            LEFT JOIN appointment_expiry_notices n ON n.consultation_id = c.id
            WHERE c.resident_id = ? AND n.consultation_id IS NULL');
        $stmt->execute([$residentId]);
        $expired = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), 'rhuAppointmentExpired'));
        if ($expired) {
            $count = count($expired);
            $message = $count === 1
                ? '1 of your appointments expired and was automatically removed from your appointment list. Please book a new appointment if needed.'
                : $count . ' of your appointments expired and were automatically removed from your appointment list. Please book new appointments if needed.';
            $notify = $pdo->prepare('INSERT INTO portal_notifications (resident_id, audience_role, message, link_url) VALUES (?, NULL, ?, ?)');
            $notify->execute([$residentId, $message, 'ResidentDashboard.php?tab=records']);
            $mark = $pdo->prepare('INSERT INTO appointment_expiry_notices (consultation_id, resident_id) VALUES (?, ?)');
            foreach ($expired as $row) $mark->execute([(int)$row['id'], $residentId]);
        }
        $pdo->commit();
        return count($expired);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
