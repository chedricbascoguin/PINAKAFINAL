<?php
require dirname(__DIR__) . '/includes/dental-exam-suggestions.php';
function dentalCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
dentalCheck(in_array('Bleeding gums', dentalGumOptions(), true), 'Gum options missing');
$review = dentalValidateExamApproval(['exam_approved'=>'1','recommendation_mode'=>'automatic'], 8);
dentalCheck($review['approved_by'] === 8 && $review['source'] === 'automatic_recommendation', 'Automatic review metadata missing');
$review = dentalValidateExamApproval(['exam_approved'=>'1','recommendation_mode'=>'manual'], 8);
dentalCheck($review['source'] === 'manual', 'Manual edit metadata missing');
foreach ([[[],8],[['exam_approved'=>'1'],0]] as [$post,$staff]) {
    $rejected=false;
    try { dentalValidateExamApproval($post,$staff); } catch (InvalidArgumentException $error) { $rejected=true; }
    dentalCheck($rejected,'Approval bypass');
}
echo "Dental approval and gum dropdown checks passed.\n";

$care = dentalValidateCareTreatments([
    'care_procedure'=>'1', 'care_procedure_type'=>'Dental Filling', 'care_procedure_teeth'=>'14, 15',
    'care_extraction'=>'1', 'care_extraction_teeth'=>'36', 'care_extraction_reason'=>'Recorded reason',
]);
if (count($care) !== 2 || $care[0]['record_type'] !== 'procedure' || $care[1]['record_type'] !== 'extraction') throw new RuntimeException('Combined treatments not constructed');
if (dentalValidateCareTreatments([]) !== []) throw new RuntimeException('Optional treatments should be optional');
foreach ([
    ['care_procedure'=>'1','care_procedure_type'=>'Invalid','care_procedure_teeth'=>'14'],
    ['care_extraction'=>'1','care_extraction_teeth'=>'99','care_extraction_reason'=>'Reason','care_anesthesia'=>'Local'],
    ['care_extraction'=>'1','care_extraction_teeth'=>'36','care_anesthesia'=>'Local'],
] as $invalidCare) {
    try { dentalValidateCareTreatments($invalidCare); throw new RuntimeException('Invalid treatment accepted'); }
    catch (InvalidArgumentException $expected) {}
}
echo "Combined dental care validation passed: optional treatments, separate typed records, invalid procedure/tooth/reason rejected.\n";
