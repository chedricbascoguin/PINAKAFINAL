<?php
require_once __DIR__ . '/appointment-expiry.php';
require_once dirname(__DIR__) . '/includes/staff-record-departments.php';
function residentQrCategory(array $row): string
{
    $category = (string)($row['appointment_type'] ?? '');
    if (preg_match('/Appointment Category:\s*([^|\r\n]+)/i', (string)($row['remarks'] ?? '') . "\n" . (string)($row['consultation_notes'] ?? ''), $match)) $category = trim($match[1]);
    if ($category === '' && preg_match('/^\[([^\]]+)\]/', (string)($row['chief_complaint'] ?? ''), $match)) $category = $match[1];
    if ($category === '' && preg_match('/^Family Planning\s*-/i', (string)($row['chief_complaint'] ?? ''))) $category = 'Family Planning';
    return $category;
}
function residentQrDepartment(array $row): string
{
    $category = residentQrCategory($row);
    foreach (['DENTAL'=>'dental|oral|tooth', 'MEDTECH'=>'laboratory|blood|medtech', 'MIDWIFE'=>'prenatal|maternal|midwife|family planning', 'SANITARY_INSPECTOR'=>'sanitary|inspection|clearance', 'NURSE'=>'vaccination|immunization|general health checkup|nutrition|tuberculosis|disease|nurs', 'PHYSICIAN'=>'general medical|physician|doctor'] as $department=>$pattern) {
        if (preg_match('/'.$pattern.'/i', $category)) return $department;
    }
    // Legacy bookings without a category use the assigned provider, never complaint keywords.
    return rhuResolveStaffType((string)($row['_record_provider_type'] ?? ''), (string)($row['_record_provider_role'] ?? ''));
}
function residentQrPending(array $row): bool
{
    if (rhuAppointmentExpired($row)) return false;
    $status = strtolower(trim((string)($row['consultation_status'] ?? $row['status'] ?? '')));
    if (preg_match('/^\s*Status:\s*([^|\r\n]+)/mi', (string)($row['consultation_notes'] ?? '') . "\n" . (string)($row['remarks'] ?? ''), $match)
        && in_array($status, ['', 'scheduled'], true)) $status = strtolower(trim($match[1]));
    if (in_array($status, ['completed','cancelled','canceled','no-show','no show','referred'], true)) return false;
    $diagnosis = strtolower(trim((string)($row['diagnosis'] ?? '')));
    if ($status === 'scheduled' && $diagnosis !== '' && $diagnosis !== 'pending opd triage' && !empty($row['medications_prescribed'])) return false;
    if ($status !== '') return in_array($status, ['scheduled','pending','confirmed','in progress','in-progress','waiting','approved'], true);
    return $diagnosis === '' || $diagnosis === 'pending opd triage';
}
function residentQrCanRead(string $department, array $row): bool
{
    if (rhuRecordIsConfidential($row) || !residentQrPending($row)) return false;
    if (in_array($department, ['ADMIN','SECRETARY'], true)) return true;
    return $department !== '' && residentQrDepartment($row) === $department;
}
function residentQrEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function residentQrAppointmentUrl(string $department, array $row): string
{
    if (!residentQrCanRead($department, $row) || (int)($row['id'] ?? 0) <= 0 || (int)($row['resident_id'] ?? 0) <= 0) return '';
    $query = ['qr_appointment'=>(int)$row['id'], 'resident_id'=>(int)$row['resident_id']];
    $page = match ($department) {
        'NURSE'=>'NurseDashboard.php', 'DENTAL'=>'DentalDashboard.php', 'MIDWIFE'=>'MidwifeDashboard.php',
        'MEDTECH'=>'MedTechDashboard.php', 'SANITARY_INSPECTOR'=>'SanitaryDashboard.php',
        'ADMIN','SECRETARY','PHYSICIAN'=>'RHUAdminDashboard.php', default=>'',
    };
    if ($page === '') return '';
    $query['tab'] = match ($department) {
        'NURSE'=>'opd', 'MIDWIFE'=>'overview', 'DENTAL'=>'queue', 'MEDTECH'=>'rapid', 'SANITARY_INSPECTOR'=>'overview', default=>'consultations',
    };
    if ($department === 'DENTAL') {
        $query['tab'] = 'examinations';
        $query['modal'] = 'new_exam';
        $query['consultation_id'] = (int)$row['id'];
    }
    if ($department === 'MEDTECH') {
        $query['modal'] = 'test';
        $query['consultation_id'] = (int)$row['id'];
        $query['appointment_date'] = $row['consultation_date'] ?? '';
        $query['appointment_time'] = $row['consultation_time'] ?? '';
    }
    if ($department === 'MIDWIFE' && preg_match('/family planning/i', residentQrCategory($row))) {
        $query['tab'] = 'fp';
        $query['modal'] = 'new_fp';
        $query['consultation_id'] = (int)$row['id'];
    }
    if ($department === 'NURSE' && preg_match('/vaccin|immunization/i', residentQrCategory($row))) {
        $query['tab'] = 'immunization';
        $query['modal'] = 'new_immunization';
        $query['consultation_id'] = (int)$row['id'];
    }
    return $page . '?' . http_build_query($query);
}

function residentQrLoadAppointment(PDO $pdo, string $department, int $id, int $residentId, bool $lock = false): array
{
    $stmt = $pdo->prepare("SELECT c.*, hw.position_title AS _record_provider_type, hw.role AS _record_provider_role, CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) AS resident_name
        FROM consultations c JOIN residents r ON r.id = c.resident_id LEFT JOIN health_workers hw ON hw.id = c.health_worker_id
        WHERE c.id = ? AND c.resident_id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id, $residentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !residentQrCanRead($department, $row)) throw new RuntimeException('This appointment is no longer available to your department.');
    return $row;
}

