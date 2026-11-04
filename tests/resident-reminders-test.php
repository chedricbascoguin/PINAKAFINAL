<?php
date_default_timezone_set('Asia/Manila');
class ReminderTestPDO extends PDO {
    public function __construct() { parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    private function sql(string $sql): string {
        $sql = preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/', ')', $sql);
        return str_replace(['INSERT IGNORE','IF(JSON_VALID'], ['INSERT OR IGNORE','IIF(JSON_VALID'], $sql);
    }
    public function exec(string $statement): int|false { return parent::exec($this->sql($statement)); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sql($query), $options); }
}
function rhuTableExists(PDO $pdo, string $table): bool {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");$stmt->execute([$table]);return (bool)$stmt->fetchColumn();
}
function rhuColumnExists(PDO $pdo, string $table, string $column): bool { return in_array($column,array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC),'name'),true); }
function portalEnsureNotificationTables(PDO $pdo): void { $pdo->exec('CREATE TABLE IF NOT EXISTS portal_notifications (resident_id INTEGER, message TEXT, link_url TEXT)'); }
$emails=[]; $emailBodies=[]; $pushes=[]; $failEmail=true;
function sendRHUEmail(string $email,string $subject,string $body,bool $requireSmtp=false): array {
    global $emails,$emailBodies,$failEmail;
    if ($failEmail) { $failEmail=false; return ['success'=>false,'error'=>'Simulated SMTP outage']; }
    reminderCheck($requireSmtp, 'Reminder email must require configured SMTP');
    $emails[]=$email;$emailBodies[]=$body;return ['success'=>true];
}
function portalSendPushToResident(PDO $pdo,int $residentId,string $message,string $url): array { global $pushes; $pushes[]=$residentId; return ['attempted'=>1,'sent'=>1,'failed'=>0]; }
require dirname(__DIR__).'/includes/appointment-email-reminders.php';
require dirname(__DIR__).'/includes/resident-reminder-delivery.php';
// The portal must supply these helpers even when the hosted reminder file is older.
$portalSource=file_get_contents(dirname(__DIR__).'/includes/portal.php');
$helperStart=strpos($portalSource,'// BEGIN shared appointment notification compatibility');
$helperEnd=strpos($portalSource,'// END shared appointment notification compatibility');
eval(substr($portalSource,$helperStart,$helperEnd-$helperStart));

function reminderCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo=new ReminderTestPDO();
$pdo->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value);
$pdo->exec('CREATE TABLE residents (id INTEGER PRIMARY KEY,email TEXT)');
$pdo->exec("INSERT INTO residents VALUES (1,'resident@example.test'),(2,'child@example.test'),(3,'guardian@example.test')");
$pdo->exec('CREATE TABLE audit_logs (action TEXT,module_name TEXT,entity_id INTEGER,record_id INTEGER,id INTEGER)');
$pdo->exec("INSERT INTO audit_logs VALUES ('Resident Dependent','Resident Portal',2,3,1)");
$pdo->exec('CREATE TABLE consultations (id INTEGER PRIMARY KEY,resident_id INTEGER,consultation_date TEXT,consultation_status TEXT,follow_up_date TEXT)');
$pdo->exec('CREATE TABLE dental_records (id INTEGER PRIMARY KEY,resident_id INTEGER,record_type TEXT,status TEXT,meta_json TEXT)');
$today=date('Y-m-d');$tomorrow=date('Y-m-d',strtotime('+1 day'));$later=date('Y-m-d',strtotime('+2 days'));
$stmt=$pdo->prepare('INSERT INTO consultations VALUES (?,?,?,?,?)');
foreach ([[1,1,$today,'Scheduled',null],[2,1,$tomorrow,'Scheduled',null],[3,1,$today,'Cancelled',null],[4,1,$today,'Completed',$tomorrow],[5,1,$later,'Scheduled',null],[6,2,$today,'Scheduled',null]] as $row) $stmt->execute($row);
$stmt=$pdo->prepare('INSERT INTO dental_records VALUES (1,1,?,?,?)');$stmt->execute(['referral','Pending',json_encode(['follow_up_date'=>$tomorrow])]);
reminderCheck(rhuCreateFollowUpReminders($pdo,null,true)===5,'Same-day, next-day or follow-up reminders missing');
reminderCheck(rhuCreateFollowUpReminders($pdo,null,true)===0,'In-app reminders duplicated');
reminderCheck($pdo->query('SELECT COUNT(*) FROM portal_notifications WHERE resident_id=3')->fetchColumn()==1,'Dependent reminder not routed to guardian');
// Cancel and reschedule after queuing; stale reminders must not be sent.
$pdo->exec("UPDATE consultations SET consultation_date='$later' WHERE id=1");
$pdo->exec("UPDATE consultations SET consultation_status='Cancelled' WHERE id=2");
$first=rhuDeliverResidentReminders($pdo);
reminderCheck($first['failed']===1 && $first['email_sent']===2 && $first['push_sent']===3,'Delivery failure was swallowed or stale appointment sent');
$second=rhuDeliverResidentReminders($pdo);
reminderCheck($second['failed']===0 && $second['email_sent']===1 && $second['push_sent']===0,'Failed email not retried independently');
reminderCheck(in_array('guardian@example.test',$emails,true) && !in_array('child@example.test',$emails,true),'Wrong dependent recipient');
reminderCheck(rhuDeliverResidentReminders($pdo)['sent']===0,'Successful email/push sent again');
$pdo->exec("INSERT INTO consultations VALUES (7,1,'$today','Scheduled',NULL),(8,2,'$today','Scheduled',NULL)");
$pdo->exec("ALTER TABLE consultations ADD COLUMN consultation_time TEXT");
$pdo->exec("UPDATE consultations SET consultation_time = '09:00:00' WHERE id = 7");
reminderCheck(rhuCreateFollowUpReminders($pdo,null,true)===2,'New scoped reminders were not queued');
$emails=[]; $emailBodies=[]; $pushes=[];
$scoped=rhuDeliverResidentReminders($pdo,1);
reminderCheck($scoped['email_sent']===1 && $emails===['resident@example.test'] && $pushes===[1], 'Resident-scoped delivery reached another account');
reminderCheck(str_contains($emailBodies[0], 'at 09:00'), 'Appointment time missing from email reminder');
$guardianScoped=rhuDeliverResidentReminders($pdo,3);
reminderCheck($guardianScoped['email_sent']===1 && $emails[1]==='guardian@example.test' && $pushes[1]===3, 'Dependent reminder did not reach its guardian');
// Immediate staff-created schedules use the same resident notification path, independently of daily reminders.
function portalNotifyResident($db, $residentId, $message, $link = null) {
    $GLOBALS['scheduleNotices'][] = ['resident_id'=>$residentId,'message'=>$message,'link'=>$link];
}
$scheduleNotices = [];
$nextDate = (new DateTimeImmutable('tomorrow',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
reminderCheck(rhuNotifyResidentSchedule($pdo,1,'Family Planning follow-up',$nextDate,'09:30'), 'Saved staff schedule did not notify');
reminderCheck(count($scheduleNotices)===1 && $scheduleNotices[0]['resident_id']===1 && str_contains($scheduleNotices[0]['message'],$nextDate.' at 09:30') && $scheduleNotices[0]['link']==='ResidentDashboard.php?tab=appointments', 'Staff schedule notification lost its resident, date, time or destination');
reminderCheck(!rhuNotifyResidentSchedule($pdo,1,'Dental follow-up',$nextDate,'',$nextDate), 'Unchanged follow-up sent another notification');
foreach (['', '2026-02-30', '2000-01-01'] as $invalidDate) reminderCheck(!rhuNotifyResidentSchedule($pdo,1,'Nursing appointment',$invalidDate), 'Invalid/past schedule notified');
reminderCheck(!rhuNotifyResidentSchedule($pdo,0,'Nursing appointment',$nextDate), 'Schedule sent without a resident');
$appointmentDb = new ReminderTestPDO();
$appointmentDb->exec('CREATE TABLE consultations(id INTEGER PRIMARY KEY,resident_id INTEGER,consultation_date TEXT,consultation_time TEXT,consultation_status TEXT)');
$insert=$appointmentDb->prepare("INSERT INTO consultations VALUES(1,2,?,'10:00:00','Scheduled'),(2,2,?,'11:00:00','Completed')"); $insert->execute([$nextDate,$nextDate]);
reminderCheck(rhuNotifySavedResidentAppointment($appointmentDb,1,'Dental appointment'), 'Saved appointment did not notify its owner');
reminderCheck(end($scheduleNotices)['resident_id']===2 && str_contains(end($scheduleNotices)['message'],'10:00'), 'Saved appointment notified the wrong resident or time');
reminderCheck(!rhuNotifySavedResidentAppointment($appointmentDb,2,'Dental appointment'), 'Completed appointment sent a scheduled notice');

echo "Resident reminder checks passed: same-day/tomorrow bookings, completed-visit follow-ups, dental, dependents, cancellation, rescheduling, retries per-channel deduplication and immediate staff schedule notifications.\n";
