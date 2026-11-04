<?php
function inventoryIsAdmin(?array $session = null): bool
{
    $session ??= $_SESSION ?? [];
    if (!empty($session['rhu_staff_login'])) return in_array(strtoupper((string)($session['rhu_staff_login']['staff_type'] ?? '')), ['RHU_ADMIN','SUPER_ADMIN'], true);
    return !empty($session['rhu_admin_authenticated']);
}
function inventoryRequireAdmin(): void
{
    if (!inventoryIsAdmin()) throw new RuntimeException('Only the administrator can add items, restock or correct inventory.');
}
function inventoryEnsureHistory(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) return;
    if ($pdo->inTransaction()) throw new RuntimeException('Inventory history must be initialized before the transaction.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        item_id BIGINT UNSIGNED NOT NULL, item_name VARCHAR(150) NOT NULL,
        item_type VARCHAR(30) NOT NULL, unit VARCHAR(50) NOT NULL,
        movement_type VARCHAR(30) NOT NULL, quantity_change INT NOT NULL,
        quantity_before INT NOT NULL, quantity_after INT NOT NULL,
        staff_id BIGINT UNSIGNED NULL, staff_name VARCHAR(200) NOT NULL,
        service VARCHAR(100) NOT NULL, location VARCHAR(200) NOT NULL,
        purpose TEXT NOT NULL, resident_id BIGINT UNSIGNED NULL,
        used_at DATETIME NOT NULL, recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_movement_date (used_at), INDEX idx_movement_item (item_id), INDEX idx_movement_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready[$key] = true;
}
function inventoryMovement(PDO $pdo, array $item, int $before, int $after, array $details): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Inventory changes and history must be saved together.');
    $staff = $_SESSION['rhu_staff_login'] ?? $_SESSION['user'] ?? [];
    $staffId = (int)($staff['staff_id'] ?? $staff['health_worker_id'] ?? $staff['user_id'] ?? 0);
    if ($staffId <= 0) throw new RuntimeException('An authenticated staff account is required.');
    $name = trim((string)($staff['name'] ?? trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))));
    $service = trim((string)($details['service'] ?? $staff['staff_type'] ?? 'RHU'));
    $location = trim((string)($details['location'] ?? ''));
    $purpose = trim((string)($details['purpose'] ?? ''));
    if ($location === '' || $purpose === '') throw new InvalidArgumentException('Location and purpose are required for inventory history.');
    $usedAt = (string)($details['used_at'] ?? date('Y-m-d H:i:s'));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $usedAt);
    if (!$date || $date->format('Y-m-d H:i:s') !== $usedAt || $date->getTimestamp() > time()+60) throw new InvalidArgumentException('Enter a valid date and time, not in the future.');
    $stmt = $pdo->prepare('INSERT INTO inventory_movements
        (item_id,item_name,item_type,unit,movement_type,quantity_change,quantity_before,quantity_after,staff_id,staff_name,service,location,purpose,resident_id,used_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([(int)$item['id'], $item['item_name'], $item['item_type'] ?? 'Medicine', $item['unit'] ?? 'pcs',
        $details['type'] ?? ($after > $before ? 'Stock added' : 'Used'), $after-$before, $before, $after,
        $staffId, $name ?: 'Staff #' . $staffId, $service, $location, $purpose, (int)($details['resident_id'] ?? 0) ?: null, $usedAt]);
}
function inventoryChange(PDO $pdo, int $itemId, int $change, array $details, bool $correction = false): array
{
    if ($change > 0 || $correction) inventoryRequireAdmin();
    if ($change === 0) throw new InvalidArgumentException('Enter a non-zero stock quantity.');
    inventoryEnsureHistory($pdo);
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM medicine_inventory WHERE id = ?' . ($change < 0 && !$correction ? '' : ' FOR UPDATE'));
        $stmt->execute([$itemId]); $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new InvalidArgumentException('Inventory item not found.');
        if ($change < 0 && !$correction) {
            // The selected ID identifies the item; stock is taken from eligible batches in expiry order.
            $batches = $pdo->prepare("SELECT * FROM medicine_inventory
                WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?))
                  AND LOWER(TRIM(item_type)) = LOWER(TRIM(?))
                  AND LOWER(TRIM(unit)) = LOWER(TRIM(?))
                  AND quantity > 0 AND (expiration_date IS NULL OR expiration_date >= CURRENT_DATE())
                ORDER BY expiration_date IS NULL, expiration_date ASC, created_at ASC, id ASC FOR UPDATE");
            $batches->execute([$item['item_name'], $item['item_type'] ?? 'Medicine', $item['unit'] ?? 'pcs']);
            $available = $batches->fetchAll(PDO::FETCH_ASSOC);
            $remaining = -$change;
            if (array_sum(array_column($available, 'quantity')) < $remaining) throw new InvalidArgumentException('Not enough unexpired stock for ' . $item['item_name'] . '.');
            foreach ($available as $batch) {
                if ($remaining <= 0) break;
                $before = (int)$batch['quantity'];
                $take = min($before, $remaining);
                $after = $before - $take;
                $pdo->prepare('UPDATE medicine_inventory SET quantity=?,status=?,updated_at=NOW() WHERE id=?')
                    ->execute([$after, $after > 0 ? 'Available' : 'Out of Stock', (int)$batch['id']]);
                inventoryMovement($pdo, $batch, $before, $after, $details);
                $remaining -= $take;
            }
            if ($owns) $pdo->commit();
            return $item;
        }
        if ($change > 0 && !empty($item['expiration_date']) && $item['expiration_date'] <= date('Y-m-d', strtotime(($item['item_type'] ?? '') === 'Medicine' ? '+7 days' : '-1 day'))) throw new InvalidArgumentException('Use a new valid batch instead of adding stock to an expired or soon-expiring item.');
        $before = (int)$item['quantity']; $after = $before + $change;
        if ($after < 0) throw new InvalidArgumentException('Not enough stock for ' . $item['item_name'] . '.');
        $pdo->prepare('UPDATE medicine_inventory SET quantity=?,status=?,updated_at=NOW() WHERE id=?')->execute([$after,$after > 0 ? 'Available' : 'Out of Stock',$itemId]);
        inventoryMovement($pdo, $item, $before, $after, $details);
        if ($owns) $pdo->commit();
        return $item;
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
function inventoryAddItem(PDO $pdo, array $post): int
{
    inventoryRequireAdmin();
    $name = trim((string)($post['item_name'] ?? $post['generic_name'] ?? ''));
    $qty = filter_var($post['quantity'] ?? '', FILTER_VALIDATE_INT);
    $type = (string)($post['item_type'] ?? 'Medicine');
    if ($name === '' || strlen($name) > 150 || $qty === false || $qty < 1 || !in_array($type,['Medicine','Laboratory','Supply','Equipment'],true)) throw new InvalidArgumentException('Enter an item name, item type and a positive whole quantity.');
    $unit = trim((string)($post['unit'] ?? 'pcs'));
    $expiry = trim((string)($post['expiry_date'] ?? ''));
    if ($unit === '' || strlen($unit) > 50) throw new InvalidArgumentException('Enter a valid unit.');
    if ($expiry !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$expiry);
        if (!$date || $date->format('Y-m-d') !== $expiry || $expiry < date('Y-m-d')) throw new InvalidArgumentException('Expiry date must be valid and not expired.');
        if ($type === 'Medicine' && $expiry <= date('Y-m-d', strtotime('+7 days'))) throw new InvalidArgumentException('Medicine expiring within 7 days cannot be added.');
    }
    inventoryEnsureHistory($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO medicine_inventory (item_name,item_type,category,unit,quantity,reorder_level,expiration_date,batch_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$name,$type,trim((string)($post['category'] ?? '')),$unit,$qty,max(0,(int)($post['reorder_level'] ?? 0)),$expiry ?: null,trim((string)($post['batch_number'] ?? '')),'Available']);
        $id = (int)$pdo->lastInsertId();
        inventoryMovement($pdo,['id'=>$id,'item_name'=>$name,'item_type'=>$type,'unit'=>$unit],0,$qty,
            ['type'=>'Stock added','service'=>'Inventory','location'=>$post['location'] ?? '', 'purpose'=>$post['reason'] ?? 'New item received']);
        $pdo->commit(); return $id;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Group the inventory display without merging batches or changing their expiry dates. */
function inventoryDisplayGroups(array $items): array
{
    $groups = [];
    $normalize = static function ($value): string {
        $value = preg_replace('/\s+/u', ' ', trim((string)$value));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    foreach ($items as $item) {
        // Different units must not be added together (for example boxes and pieces).
        $key = json_encode(array_map($normalize, [$item['item_name'], $item['item_type'], $item['unit']]));
        if (!isset($groups[$key])) $groups[$key] = [
            'item_name' => $item['item_name'], 'item_type' => $item['item_type'],
            'unit' => $item['unit'], 'quantity' => 0, 'categories' => [], 'batches' => [], 'earliest_expiry' => null
        ];
        $group = &$groups[$key];
        $group['quantity'] += (int)$item['quantity'];
        $category = trim((string)($item['category'] ?? ''));
        if ($category !== '') $group['categories'][$normalize($category)] = $category;
        $group['batches'][] = $item;
        $expiry = $item['expiration_date'] ?? null;
        if ((int)$item['quantity'] > 0 && $expiry && (!$group['earliest_expiry'] || $expiry < $group['earliest_expiry'])) $group['earliest_expiry'] = $expiry;
        unset($group);
    }
    foreach ($groups as &$group) {
        usort($group['batches'], static function ($a, $b): int {
            return strcmp($a['expiration_date'] ?: '9999-12-31', $b['expiration_date'] ?: '9999-12-31') ?: ((int)$a['id'] <=> (int)$b['id']);
        });
    }
    unset($group);
    return array_values($groups);
}
