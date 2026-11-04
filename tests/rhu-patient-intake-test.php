<?php
require_once dirname(__DIR__) . '/includes/rhu-patient-intake.php';
class IntakeTestPDO extends PDO {
    public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if ($query === 'SHOW COLUMNS FROM residents') $query = "SELECT name FROM pragma_table_info('residents')";
        return parent::query($query);
    }
}
function checkIntake($value, $message) { if (!$value) throw new RuntimeException($message); }
$pdo = new IntakeTestPDO();
$pdo->exec('CREATE TABLE resident_rhu_intakes (resident_id INTEGER PRIMARY KEY, details TEXT)');
$pdo->exec('CREATE TABLE residents (id INTEGER PRIMARY KEY, middle_name TEXT, civil_status TEXT, philhealth_number TEXT)');
$pdo->exec('INSERT INTO residents (id) VALUES (1)');
rhuIntakeSave($pdo, 1, ['rhu_intake'=>['spouse_name'=>'Example Spouse', 'middle_name'=>'Reyes']]);
rhuIntakeSave($pdo, 1, ['rhu_intake'=>['spouse_name'=>'Replacement']]);
$details = json_decode($pdo->query('SELECT details FROM resident_rhu_intakes')->fetchColumn(), true);
checkIntake($details['spouse_name'] === 'Example Spouse', 'Returning visit overwrote intake');
checkIntake($pdo->query('SELECT middle_name FROM residents')->fetchColumn() === 'Reyes', 'Shared resident name was not updated');
checkIntake($pdo->query('SELECT COUNT(*) FROM resident_rhu_intakes')->fetchColumn() == 1, 'Duplicate intake created');
try { rhuIntakeSave($pdo, 2, ['rhu_intake'=>['spouse_name'=>['invalid']]]); throw new RuntimeException('Array accepted'); }
catch (InvalidArgumentException $expected) {}
$visits = [];
function portalSaveHealthRecordEntry($pdo, $residentId, $data) { global $visits; $visits[] = $data; return count($visits); }
rhuVisitSave($pdo, 1, ['rhu_visit'=>['bp'=>'120/80', 'temperature'=>'36.8', 'pr'=>'78']], 'Dental', 3);
rhuVisitSave($pdo, 1, ['rhu_visit'=>['bp'=>'110/70']], 'Prenatal', 4);
rhuVisitSave($pdo, 1, ['rhu_visit'=>['bp'=>'', 'temperature'=>'']], 'Prenatal', 4);
checkIntake(count($visits) === 2, 'Visit history not appended or blank visit saved');
checkIntake($visits[0]['blood_pressure'] === '120/80' && $visits[1]['blood_pressure'] === '110/70', 'BP history lost');
checkIntake(str_contains($visits[0]['notes'], '78'), 'Pulse not saved');
checkIntake(!str_contains(rhuVisitFields(['bp','height','weight']), 'name="rhu_visit[bp]"'), 'Duplicate BP control');
echo "RHU intake and repeat-visit checks passed.\n";
