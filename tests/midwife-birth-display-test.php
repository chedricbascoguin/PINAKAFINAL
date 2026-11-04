<?php
require dirname(__DIR__) . '/includes/patient-identifiers.php';
$base = ['id'=>2,'resident_id'=>27,'name'=>'Patrick Castillo','date'=>'2026-09-30','weight'=>"Place: RHU\nFather: \nWeight: 4\nLength: 48.5"];
$withFather = array_replace($base,['id'=>1,'weight'=>"Place: RHU\nFather: Kim Taehyung\nWeight: 4\nLength: 5.1"]);
$rows = rhuUniqueBirthDisplay([$base,$withFather]);
if (count($rows)!==1 || $rows[0]['id']!==1) throw new RuntimeException('Duplicate birth or father information lost');
if ($rows[0]['weight']!==$withFather['weight']) throw new RuntimeException('Unverified measurements changed');
foreach ([['resident_id'=>28],['date'=>'2026-10-01'],['name'=>'Other Child']] as $identity) {
    if (count(rhuUniqueBirthDisplay([$base,array_replace($base,$identity)]))!==2) throw new RuntimeException('Different child identity hidden');
}
if (count(rhuUniqueBirthDisplay([array_replace($base,['resident_id'=>0]),array_replace($base,['resident_id'=>0])]))!==2) throw new RuntimeException('Unlinked births merged');
echo "Birth display checks passed: one entry for duplicate child, father retained, measurements unchanged and different children preserved.\n";

$adminRows = [
 ['id'=>2,'resident_id'=>27,'child_name'=>'Patrick Castillo','date_recorded'=>'2026-09-30','type'=>'Birth','remarks'=>"Father: \nWeight: 4"],
 ['id'=>1,'resident_id'=>27,'child_name'=>'Patrick Castillo','date_recorded'=>'2026-09-30','type'=>'Birth','remarks'=>"Father: Kim Taehyung\nWeight: 4"],
 ['id'=>3,'resident_id'=>27,'child_name'=>'Patrick Castillo','date_recorded'=>'2026-09-30','type'=>'Death'],
];
$unique = rhuUniqueBirthDisplay($adminRows);
if (count($unique)!==2 || $unique[0]['id']!==1 || $unique[1]['type']!=='Death') throw new RuntimeException('Admin birth deduplication changed other vital records');
echo "Shared birth display checks passed for admin and other staff history views.\n";
