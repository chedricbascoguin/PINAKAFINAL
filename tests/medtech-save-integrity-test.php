<?php
// Exercise save failures without touching the live database.
function rhuColumnExists($pdo, $table, $column): bool { return true; }
function portalSaveHealthRecordEntry($pdo, $residentId, $data) { return $pdo->historyFails ? null : 25; }
function portalBroadcastWorkflowUpdate(...$args): void { $GLOBALS['broadcasts']++; }
require dirname(__DIR__) . '/includes/dashboard-workflows.php';
class TestSavePDO extends PDO {
    public bool $active = false, $rolledBack = false, $committed = false, $historyFails = false;
    public int $updates = 0;
    public array $appointment;
    public function __construct() {
        $this->appointment = ['id'=>1, 'resident_id'=>2, 'consultation_date'=>'2999-01-01', 'consultation_status'=>'Scheduled', 'diagnosis'=>'Pending OPD Triage'];
    }
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->active=true; return true; }
    public function commit(): bool { $this->active=false; $this->committed=true; return true; }
    public function rollBack(): bool { $this->active=false; $this->rolledBack=true; $this->updates=0; return true; }
    public function exec(string $sql): int|false { if ($this->active) throw new RuntimeException('DDL inside transaction'); return 0; }
    public function prepare(string $sql, array $options=[]): PDOStatement|false { return new TestSaveStatement($this,$sql); }
}
class TestSaveStatement extends PDOStatement {
    public function __construct(private TestSavePDO $db, private string $sql) {}
    public function execute(?array $params=null): bool {
        if (str_starts_with($this->sql, 'UPDATE consultations')) $this->db->updates++;
        if (str_starts_with($this->sql, 'SELECT * FROM consultations') && !str_contains($this->sql, 'FOR UPDATE')) throw new RuntimeException('Missing appointment lock');
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed { return $this->db->appointment; }
    public function fetchColumn(int $column=0): mixed { return 2; }
    public function rowCount(): int { return 1; }
}
$post=['consultation_id'=>1,'results'=>'Normal','test_name'=>'Test kit','test_date'=>'2026-11-05'];
foreach (['expired','cancelled','history-failure','success'] as $scenario) {
    $db=new TestSavePDO(); $GLOBALS['broadcasts']=0;
    if ($scenario==='expired') $db->appointment['consultation_date']='2000-01-01';
    if ($scenario==='cancelled') $db->appointment['consultation_status']='Cancelled';
    if ($scenario==='history-failure') $db->historyFails=true;
    $error=null;
    try { workflowRecordTest($db,$post,3); } catch (RuntimeException $caught) { $error=$caught->getMessage(); }
    if ($scenario==='success') {
        if ($error || !$db->committed || $GLOBALS['broadcasts']!==1) throw new RuntimeException('Valid save failed');
    } else {
        $expected=['expired'=>'expired','cancelled'=>'no longer open','history-failure'=>'health history'][$scenario];
        if (!$error || !str_contains($error,$expected) || !$db->rolledBack || $db->committed || $db->updates || $GLOBALS['broadcasts']) throw new RuntimeException('Failed rollback case: '.$scenario);
    }
}
echo "MedTech save integrity passed: expired/cancelled submissions rejected, failed history rolls back, success commits and notifies.\n";
