<?php
require dirname(__DIR__) . '/includes/record-access.php';
$record = ['created_by_staff_id' => 7, 'counselor_staff_id' => 8];
$cases = [
    [['staff_type'=>'MEDTECH','staff_id'=>7], true],
    [['staff_type'=>'MEDTECH','staff_id'=>8], false],
    [['staff_type'=>'MEDTECH','staff_id'=>9], false],
    [['staff_type'=>'RHU_ADMIN','staff_id'=>7], false],
    [['staff_type'=>'NURSE','staff_id'=>7], false],
    [['staff_type'=>'HIV COUNSELOR','staff_id'=>7], false],
    [['staff_type'=>'MEDTECH','staff_id'=>0], false],
    [[], false],
];
foreach ($cases as [$staff, $expected]) {
    if (rhuCanReadConfidentialRecord($staff, $record) !== $expected) throw new RuntimeException('Confidential ownership policy failed');
}
if (rhuCanReadConfidentialRecord(['staff_type'=>'MEDTECH','staff_id'=>7], [])) throw new RuntimeException('Missing owner allowed');
if (!rhuRecordIsConfidential(['is_confidential'=>1]) || !rhuRecordIsConfidential(['confidentiality'=>' Confidential ']) || rhuRecordIsConfidential([])) throw new RuntimeException('Shared record filtering failed');
echo "Record access policy checks passed.\n";
