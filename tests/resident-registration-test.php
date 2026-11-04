<?php
require dirname(__DIR__) . '/includes/resident-account-linking.php';
class LinkTestPDO extends PDO {
 public function prepare(string $query, array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
}
$pdo=new LinkTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW',static fn()=>'2026-11-01 12:00:00');
$pdo->exec('CREATE TABLE residents(id INTEGER PRIMARY KEY AUTOINCREMENT,first_name TEXT,middle_name TEXT,last_name TEXT,birthdate TEXT,email TEXT,username TEXT,password_hash TEXT,user_id INTEGER,address TEXT)');
$pdo->exec('CREATE TABLE consultations(resident_id INTEGER,diagnosis TEXT)');
$pdo->exec("INSERT INTO residents(id,first_name,last_name,birthdate,address) VALUES(64,'Ana','Cruz','1995-01-01','Original address'),(65,'Other','Person','1990-01-01','Other address')");
$pdo->exec("INSERT INTO consultations VALUES(64,'Existing medical history')");
$pdo->exec("CREATE TABLE resident_account_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,first_name TEXT,last_name TEXT,birthdate TEXT,email TEXT,phone TEXT,barangay TEXT,password_hash TEXT,status TEXT DEFAULT 'Pending',resident_id INTEGER,reviewed_by INTEGER,verification_note TEXT,reviewed_at TEXT)");
function verifyLink($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$hash=password_hash('secure-example',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO resident_account_requests(first_name,last_name,birthdate,email,password_hash) VALUES(?,?,?,?,?)')->execute(['Ana','Cruz','1995-01-01','ana@example.test',$hash]);
foreach ([[1,65,7,'Checked ID'],[1,64,0,'Checked ID'],[1,64,7,'']] as $args) {
 try { residentLinkApprove($pdo,...$args); throw new RuntimeException('Invalid approval accepted'); }
 catch (RuntimeException $e) { if ($e->getMessage()==='Invalid approval accepted') throw $e; }
 verifyLink(!$pdo->query('SELECT password_hash FROM residents WHERE id=64')->fetchColumn(),'Failed approval changed account');
}
residentLinkApprove($pdo,1,64,7,'Verified in person with patient card and RHU details');
$resident=$pdo->query('SELECT * FROM residents WHERE id=64')->fetch(PDO::FETCH_ASSOC);
verifyLink(password_verify('secure-example',$resident['password_hash']),'Credentials not linked');
verifyLink($resident['address']==='Original address','Patient demographics overwritten');
verifyLink($pdo->query('SELECT COUNT(*) FROM residents')->fetchColumn()==2,'Duplicate profile created');
verifyLink($pdo->query('SELECT resident_id FROM consultations')->fetchColumn()==64,'Medical history moved');
$request=$pdo->query('SELECT * FROM resident_account_requests')->fetch(PDO::FETCH_ASSOC);
verifyLink($request['status']==='Approved' && $request['password_hash']===null && $request['reviewed_by']==7,'Approval audit or credential cleanup missing');
try { residentLinkApprove($pdo,1,64,7,'Repeated'); throw new RuntimeException('Repeat approved'); } catch (RuntimeException $e) { if ($e->getMessage()==='Repeat approved') throw $e; }
$pdo->prepare('INSERT INTO resident_account_requests(first_name,last_name,birthdate,email,password_hash) VALUES(?,?,?,?,?)')->execute(['Ana','Cruz','1995-01-01','other@example.test',$hash]);
try { residentLinkApprove($pdo,2,64,7,'Checked'); throw new RuntimeException('Existing account overwritten'); } catch (RuntimeException $e) { if ($e->getMessage()==='Existing account overwritten') throw $e; }
$source=file_get_contents(dirname(__DIR__).'/pages/ResidentRegistration.php');
verifyLink(!str_contains($source,'name="duplicate_confirmation"'),'Duplicate profile option remains');
$login=file_get_contents(dirname(__DIR__).'/pages/ResidentLogin.php');
verifyLink(!str_contains($login,"$" . "password === 'resident123'") && !str_contains($login,'INSERT INTO residents'),'Demo login bypass remains');
// Exercise the actual public match/request branch without following its redirect.
$pdo->exec("INSERT INTO residents(id,first_name,last_name,birthdate,address) VALUES(66,'Ben','Reyes','1999-02-03','Existing address')");
$first_name='Ben'; $last_name='Reyes'; $dob='1999-02-03'; $email='ben@example.test';
$phone='09170000000'; $barangay='Poblacion'; $password='ben-secret';
$start=strpos($source, '                $match =');
$end=strpos($source, '                $password_hash =', $start);
$branch=substr($source,$start,$end-$start);
$branch=str_replace("header('Location: ResidentLogin.php');", "throw new RuntimeException('test-request-redirect');",$branch);
for ($attempt=0;$attempt<2;$attempt++) {
    $pdo->beginTransaction();
    try { eval($branch); throw new RuntimeException('No verification request created'); }
    catch (RuntimeException $error) { if ($error->getMessage()!=='test-request-redirect') throw $error; }
}
verifyLink($pdo->query("SELECT COUNT(*) FROM resident_account_requests WHERE email='ben@example.test'")->fetchColumn()==1,'Repeated submission duplicated request');
verifyLink(!$pdo->query('SELECT password_hash FROM residents WHERE id=66')->fetchColumn(),'Public registration activated unverified patient');
verifyLink($pdo->query('SELECT COUNT(*) FROM residents')->fetchColumn()==3,'Matching registration created duplicate patient');
residentLinkApprove($pdo,3,66,7,'Verified patient card in person');
verifyLink(password_verify('ben-secret',$pdo->query('SELECT password_hash FROM residents WHERE id=66')->fetchColumn()),'Requested credentials not activated on approval');
echo "Account linking checks passed: verified approval, matching identity, staff attribution, no duplicate patient, preserved records, no credential overwrite, audit and demo bypass removal.\n";
