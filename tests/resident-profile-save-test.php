<?php
$source=file_get_contents(dirname(__DIR__).'/ResidentDashboard.php');
$start=strpos($source, '          $updates = [', strpos($source, "elseif ($" . "formType === 'update_personal_profile')"));
$end=strpos($source, '          recordResidentChange(', $start);
$save=substr($source,$start,$end-$start);
class ProfilePDO extends PDO {
 public function prepare(string $query,array $options=[]): PDOStatement|false {
    preg_match_all('/:([a-z_]+)/i',$query,$matches);
    if (count($matches[1])!==count(array_unique($matches[1]))) throw new RuntimeException('Repeated native PDO parameter');
    return parent::prepare($query,$options);
 }
}
function rhuColumnExists(PDO $pdo,string $table,string $column): bool {
 return in_array($column,array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
foreach ([false,true] as $legacy) {
 $pdo=new ProfilePDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $pdo->sqliteCreateFunction('NOW',static fn()=>'2026-11-01 12:00:00');
 $pdo->exec('CREATE TABLE residents(id INTEGER PRIMARY KEY,first_name TEXT,middle_name TEXT,last_name TEXT,birthdate TEXT,sex TEXT,civil_status TEXT,contact_number TEXT,email TEXT,address TEXT,barangay_id INTEGER,updated_at TEXT'.($legacy?',date_of_birth TEXT,gender TEXT,barangay TEXT':'').')');
 $pdo->exec('INSERT INTO residents(id) VALUES(64),(65)');
 $residentId=64;$firstName='Ryan';$middleName='Example';$lastName='Achbang';$dateOfBirth='2005-06-28';$sexValue='Male';$civilStatus='Single';$contactNumber='09171234567';$email='ryan@example.test';$address='New address';$barangayId=2;$barangay='Aga';$resident=[];
 eval($save);
 $row=$pdo->query('SELECT * FROM residents WHERE id=64')->fetch(PDO::FETCH_ASSOC);
 if ($row['address']!=='New address' || $row['middle_name']!=='Example' || $row['birthdate']!==$dateOfBirth || $row['barangay_id']!=2) throw new RuntimeException('Profile edits not persisted');
 if ($legacy && ($row['date_of_birth']!==$dateOfBirth || $row['gender']!==$sexValue || $row['barangay']!==$barangay)) throw new RuntimeException('Legacy columns not saved');
 if ($pdo->query('SELECT first_name FROM residents WHERE id=65')->fetchColumn()!==null) throw new RuntimeException('Another resident was changed');
}
echo "Profile save checks passed: standard and legacy schemas, unique native PDO parameters, persisted edits and resident isolation.\n";
