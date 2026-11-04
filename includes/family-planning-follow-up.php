<?php
// A later recorded supply visit fulfils the preceding family planning follow-up.
function fpLaterVisitSql(string $alias = 'c'): string
{
    if (!in_array($alias, ['c', 'consultations'], true)) throw new InvalidArgumentException('Invalid consultation alias.');
    return "EXISTS (SELECT 1 FROM consultations fp_return WHERE fp_return.resident_id = {$alias}.resident_id
        AND fp_return.chief_complaint LIKE 'Family Planning - %'
        AND fp_return.diagnosis = 'Family Planning Counseling'
        AND (fp_return.consultation_date > {$alias}.consultation_date
            OR (fp_return.consultation_date = {$alias}.consultation_date AND fp_return.id > {$alias}.id)))";
}
function fpReminderGuardSql(PDO $pdo): string
{
    if (!rhuAppointmentColumnExists($pdo, 'consultations', 'chief_complaint')
        || !rhuAppointmentColumnExists($pdo, 'consultations', 'diagnosis')) return '';
    return " AND (COALESCE(consultations.chief_complaint, '') NOT LIKE 'Family Planning - %' OR NOT " . fpLaterVisitSql('consultations') . ')';
}
