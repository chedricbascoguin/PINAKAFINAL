<?php
require dirname(__DIR__) . '/includes/inventory-control.php';
function invCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
class InventoryTestPDO extends PDO {
    public array $item = ['id'=>3,'item_name'=>'Test kit','item_type'=>'Laboratory','unit'=>'kits','quantity'=>10];
    public array $batches = []; public array $movements = []; public bool $active = false; public bool $failHistory = false; private array $backup = [];
    public function __construct() {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->backup=[$this->item,$this->movements,$this->batches]; $this->active=true; return true; }
    public function commit(): bool { $this->active=false; return true; }
    public function rollBack(): bool { [$this->item,$this->movements,$this->batches]=$this->backup; $this->active=false; return true; }
    public function exec(string $statement): int|false { invCheck(!$this->active,'DDL inside stock transaction'); return 0; }
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new InventoryTestStatement($this,$query); }
}
class InventoryTestStatement extends PDOStatement {
    public function __construct(private InventoryTestPDO $db, private string $sql) {}
    public function execute(?array $params=null): bool {
        if (str_starts_with($this->sql,'UPDATE medicine_inventory')) {
            if ($this->db->batches) $this->db->batches[$params[2]]['quantity']=$params[0];
            else $this->db->item['quantity']=$params[0];
        }
        if (str_starts_with($this->sql,'INSERT INTO inventory_movements')) {
            if ($this->db->failHistory) throw new RuntimeException('Simulated history failure');
            $this->db->movements[]=$params;
        }
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array {
        if (!$this->db->batches) return [$this->db->item];
        $rows = array_values(array_filter($this->db->batches, fn($row) => $row['quantity'] > 0 && (empty($row['expiration_date']) || $row['expiration_date'] >= date('Y-m-d')) && $row['item_name'] === $this->db->item['item_name'] && $row['unit'] === $this->db->item['unit']));
        usort($rows, fn($a,$b) => strcmp($a['expiration_date'] ?? '9999-12-31',$b['expiration_date'] ?? '9999-12-31') ?: $a['id'] <=> $b['id']);
        return $rows;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->db->item; }
}
foreach (['MEDTECH','NURSE','MIDWIFE','DENTAL','SANITARY_INSPECTOR','SECRETARY','PHYSICIAN','ADMIN_STAFF'] as $role) {
    invCheck(!inventoryIsAdmin(['rhu_staff_login'=>['staff_type'=>$role]]),'Staff can add inventory: '.$role);
}
invCheck(inventoryIsAdmin(['rhu_admin_authenticated'=>true]),'Admin login rejected');
invCheck(!inventoryIsAdmin(['rhu_admin_authenticated'=>true,'rhu_staff_login'=>['staff_type'=>'MEDTECH']]),'Mixed-session staff privilege escalation');
invCheck(inventoryIsAdmin(['rhu_staff_login'=>['staff_type'=>'RHU_ADMIN']]),'Staff admin rejected');
$_SESSION=['rhu_staff_login'=>['staff_id'=>7,'staff_type'=>'MEDTECH','name'=>'Test Staff']];
$pdo=new InventoryTestPDO();
$details=['service'=>'Laboratory','location'=>'Clinic room 2','purpose'=>'Patient test'];
$denied=false;
try { inventoryChange($pdo,3,5,$details); } catch (RuntimeException $e) { $denied=true; }
invCheck($denied && $pdo->item['quantity']===10,'Staff restock bypass');
$denied=false;
try { inventoryAddItem($pdo,['item_name'=>'Forged stock','quantity'=>5]); } catch (RuntimeException $e) { $denied=true; }
invCheck($denied,'Staff item creation bypass');
inventoryChange($pdo,3,-2,$details);
invCheck($pdo->item['quantity']===8 && count($pdo->movements)===1,'Usage not saved');
$row=$pdo->movements[0];
invCheck($row[5]===-2 && $row[6]===10 && $row[7]===8 && $row[8]===7 && $row[11]==='Clinic room 2','Audit detail mismatch');
try { inventoryChange($pdo,3,-20,$details); } catch (InvalidArgumentException $e) {}
invCheck($pdo->item['quantity']===8 && count($pdo->movements)===1,'Overspending stock allowed');
$pdo->failHistory=true;
try { inventoryChange($pdo,3,-1,$details); } catch (RuntimeException $e) {}
invCheck($pdo->item['quantity']===8 && count($pdo->movements)===1,'History failure did not roll back stock');
$pdo->failHistory=false;
try { inventoryChange($pdo,3,-1,['purpose'=>'Missing location']); } catch (InvalidArgumentException $e) {}
invCheck($pdo->item['quantity']===8,'Unattributed usage allowed');
$_SESSION=['rhu_admin_authenticated'=>true,'user'=>['staff_id'=>9,'first_name'=>'Test','last_name'=>'Admin']];
inventoryChange($pdo,3,5,['type'=>'Stock added','service'=>'Inventory','location'=>'Stockroom','purpose'=>'Delivery']);
invCheck($pdo->item['quantity']===13 && $pdo->movements[1][8]===9,'Admin restock not attributed');
echo "Inventory checks passed: admin-only additions, staff usage, attribution, location, no negative stock and atomic history rollback.\n";

$original = [
    ['id'=>36,'item_name'=>'Blood Glucose Test Strips','item_type'=>'Laboratory','category'=>'Test Kits','unit'=>'Box','quantity'=>10,'batch_number'=>'MMR-2026-008','expiration_date'=>'2026-12-02'],
    ['id'=>35,'item_name'=>'blood glucose test strips','item_type'=>'Laboratory','category'=>'test kits','unit'=>'Box','quantity'=>49,'batch_number'=>'GLU-2029-10','expiration_date'=>'2029-06-30']
];
$groups = inventoryDisplayGroups($original);
invCheck(count($groups) === 1 && $groups[0]['quantity'] === 59, 'Matching batches should display as one item with total stock');
invCheck($groups[0]['earliest_expiry'] === '2026-12-02', 'Older stock expiry must remain visible');
invCheck(count($groups[0]['batches']) === 2 && $groups[0]['batches'][1]['expiration_date'] === '2029-06-30', 'Individual batch expiry lost');
$restocked = $original;
$restocked[] = array_merge($original[0], ['id'=>37,'quantity'=>20,'batch_number'=>'NEW','expiration_date'=>'2030-01-01']);
$groups = inventoryDisplayGroups($restocked);
invCheck($groups[0]['quantity'] === 79 && $groups[0]['earliest_expiry'] === '2026-12-02', 'New batch replaced older expiry');
$restocked[] = array_merge($original[0], ['id'=>38,'unit'=>'pcs']);
invCheck(count(inventoryDisplayGroups($restocked)) === 2, 'Different units must not be summed');
$restocked[0]['quantity'] = 0;
invCheck(inventoryDisplayGroups($restocked)[0]['earliest_expiry'] === '2029-06-30', 'Empty batch should retain its details without setting earliest stocked expiry');
invCheck($original[0]['expiration_date'] === '2026-12-02', 'Grouping changed original batch');
echo "Batch display checks passed: grouped totals, original expiry preservation, new batches, empty batches and separate units.\n";

$batchDb = new InventoryTestPDO();
$base = $batchDb->item;
$batchDb->batches = [
    3=>array_merge($base,['quantity'=>20,'expiration_date'=>'2031-01-01']),
    4=>array_merge($base,['id'=>4,'quantity'=>4,'expiration_date'=>'2030-01-01']),
    5=>array_merge($base,['id'=>5,'quantity'=>99,'expiration_date'=>'2000-01-01']),
    6=>array_merge($base,['id'=>6,'quantity'=>99,'unit'=>'Box','expiration_date'=>'2030-01-01'])
];
$expires = array_column($batchDb->batches, 'expiration_date', 'id');
inventoryChange($batchDb,3,-7,$details);
invCheck($batchDb->batches[4]['quantity'] === 0 && $batchDb->batches[3]['quantity'] === 17, 'Usage did not drain oldest eligible batch before newer batch');
invCheck($batchDb->batches[5]['quantity'] === 99 && $batchDb->batches[6]['quantity'] === 99, 'Expired or different-unit batch was deducted');
invCheck(count($batchDb->movements) === 2 && $batchDb->movements[0][0] === 4 && $batchDb->movements[1][0] === 3, 'Per-batch audit history missing');
invCheck(array_column($batchDb->batches,'expiration_date','id') === $expires, 'Usage changed expiry dates');
$before = $batchDb->batches;
try { inventoryChange($batchDb,3,-18,$details); } catch (InvalidArgumentException $e) {}
invCheck($batchDb->batches === $before, 'Insufficient total stock partially deducted');
$batchDb->failHistory = true;
try { inventoryChange($batchDb,3,-1,$details); } catch (RuntimeException $e) {}
invCheck($batchDb->batches === $before, 'Batch history failure did not roll back');
echo "Oldest-batch checks passed: expiry order, split usage, per-batch history, expiry preservation and rollback.\n";
