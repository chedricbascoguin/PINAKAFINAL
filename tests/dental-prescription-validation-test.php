<?php
require dirname(__DIR__) . '/includes/dental-prescription-validation.php';
$complete = ['patient_mode'=>'existing','resident_id'=>'17','medicine_name'=>'Prescribed medicine','dosage'=>'As prescribed',
    'frequency'=>'As prescribed','duration'=>'As prescribed','quantity'=>'21 caps','prescription_date'=>date('Y-m-d'),'instructions'=>'As instructed by dentist'];
dentalValidatePrescription($complete);
foreach (['resident_id','medicine_name','dosage','frequency','duration','quantity','prescription_date','instructions'] as $key) {
    $input=$complete; $input[$key]=' '; $rejected=false;
    try { dentalValidatePrescription($input); } catch (InvalidArgumentException $error) { $rejected=true; }
    if (!$rejected) throw new RuntimeException('Incomplete prescription accepted: '.$key);
}
foreach (['0 caps','-1','1.5','one box','1 box of 20'] as $quantity) {
    $input=$complete; $input['quantity']=$quantity; $rejected=false;
    try { dentalValidatePrescription($input); } catch (InvalidArgumentException $error) { $rejected=true; }
    if (!$rejected) throw new RuntimeException('Invalid dispensing quantity accepted');
}
$stock=$complete+['inventory_id'=>'3','dispense_location'=>'RHU Dental Clinic','dispensed_at'=>date('Y-m-d\TH:i')];
dentalValidatePrescription($stock);
foreach (['dispense_location','dispensed_at'] as $key) {
    $input=$stock; unset($input[$key]); $rejected=false;
    try { dentalValidatePrescription($input); } catch (InvalidArgumentException $error) { $rejected=true; }
    if (!$rejected) throw new RuntimeException('Incomplete dispensing accepted');
}
$walkin=$complete; $walkin['patient_mode']='walkin';
$walkin+=['walkin_first_name'=>'Ana','walkin_last_name'=>'Test','walkin_birthdate'=>'1990-01-01','walkin_sex'=>'Female','walkin_contact'=>'N/A','walkin_barangay'=>'Poblacion'];
unset($walkin['resident_id']); dentalValidatePrescription($walkin);
foreach (['walkin_first_name','walkin_last_name','walkin_birthdate','walkin_sex','walkin_contact','walkin_barangay'] as $key) {
    $input=$walkin; unset($input[$key]); $rejected=false;
    try { dentalValidatePrescription($input); } catch (InvalidArgumentException $error) { $rejected=true; }
    if (!$rejected) throw new RuntimeException('Incomplete walk-in accepted');
}
echo "Prescription validation passed: required fields, walk-in details, valid quantities and conditional dispensing details.\n";
