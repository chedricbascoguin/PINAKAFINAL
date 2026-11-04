<?php
require dirname(__DIR__) . '/includes/dental-record-history.php';
class DentalHistoryTestPDO extends PDO {
    public array $records=[];
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if (!str_starts_with($query,'INSERT INTO dental_records')) throw new RuntimeException('History must append, not replace or hide entries');
        return new DentalHistoryTestStatement($this);
    }
    public function lastInsertId(?string $name=null): string|false { return (string)count($this->records); }
}
class DentalHistoryTestStatement extends PDOStatement {
    public function __construct(private DentalHistoryTestPDO $pdo) {}
    public function execute(?array $params=null): bool { $this->pdo->records[]=$params; return true; }
}
$pdo=new DentalHistoryTestPDO();
$base=['resident_id'=>17,'record_type'=>'examination','record_date'=>'2026-10-14','findings'=>'Earlier findings','meta'=>['gum_condition'=>'Normal']];
$first=dentalInsertHistoricalRecord($pdo,$base);
$next=$base; $next['record_date']='2026-10-15'; $next['findings']='New findings';
$second=dentalInsertHistoricalRecord($pdo,$next);
if ($first===$second || count($pdo->records)!==2 || $pdo->records[0]['findings']!=='Earlier findings') throw new RuntimeException('Older examination overwritten');
$rx=['resident_id'=>17,'record_type'=>'prescription','title'=>'Same medicine','record_date'=>'2026-10-14','meta'=>['quantity'=>'7']];
dentalInsertHistoricalRecord($pdo,$rx);
$rx['record_date']='2026-10-15'; $rx['meta']['quantity']='14';
dentalInsertHistoricalRecord($pdo,$rx);
if (count($pdo->records)!==4 || json_decode($pdo->records[2]['meta'],true)['quantity']!=='7') throw new RuntimeException('Earlier prescription overwritten');
echo "Dental history checks passed: older exams and repeat prescriptions remain separate records for one patient ID.\n";
