<?php
require dirname(__DIR__) . '/includes/patient-identifiers.php';
require dirname(__DIR__) . '/includes/dashboard-workflows.php';
if (rhuPatientLabel('Ana Cruz', 17) !== 'Ana Cruz (Patient #17)') throw new RuntimeException('ID display failed');
if (rhuPatientLabel('Ana Cruz', 0) !== 'Ana Cruz') throw new RuntimeException('Unlinked record given a false ID');
if (rhuPatientLabel('Ana Cruz', 17) === rhuPatientLabel('Ana Cruz', 18)) throw new RuntimeException('Same-name patients not distinguished');
$rows = workflowTestRows([['id'=>999,'resident_id'=>17,'patient'=>'Ana Cruz','test_name'=>'Lab test']], []);
if ($rows[0]['resident_id'] !== 17 || $rows[0][10] !== 999) throw new RuntimeException('Lab visit ID confused with patient ID');
echo "Patient ID checks passed: consistent labels, same-name patients, unlinked records and lab visit/patient identity.\n";
