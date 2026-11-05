<?php
require dirname(__DIR__) . '/includes/appointment-expiry.php';
$base = ['consultation_date'=>'2026-11-04', 'consultation_status'=>'Scheduled', 'diagnosis'=>'Pending OPD Triage'];
$cases = [
    [$base, true],
    [array_replace($base, ['consultation_date'=>'2026-11-05']), false],
    [array_replace($base, ['consultation_date'=>'2026-11-06']), false],
    [array_replace($base, ['consultation_status'=>'Completed']), false],
    [array_replace($base, ['consultation_status'=>'Cancelled']), false],
    [array_replace($base, ['consultation_status'=>'In Progress']), true],
    [array_replace($base, ['remarks'=>"Status: Completed\nResults: Normal"]), false],
    [array_replace($base, ['diagnosis'=>'Treated', 'medications_prescribed'=>'Medication']), false],
    [array_replace($base, ['consultation_date'=>'invalid']), false],
    [['date'=>'2026-11-04', 'consultation_status'=>'Pending'], true],
];
foreach ($cases as $index => [$row, $expected]) {
    if (rhuAppointmentExpired($row, '2026-11-05') !== $expected) throw new RuntimeException('Expiry case failed: ' . $index);
}
echo "Appointment expiry checks passed: overdue, today, future, terminal statuses, clinical completion, legacy notes and invalid dates.\n";
