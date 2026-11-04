<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/includes/staff-session-lock.php';
if (rhuLockStaffId() <= 0) { header('Location: RHULogin.php'); exit; }
require_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/inventory-control.php';
$inventoryAsync = ($_SERVER['HTTP_X_RHU_INVENTORY'] ?? '') === 'update';
$inventoryEmbedded = !empty($inventoryEmbedded) || $inventoryAsync;
if (!$inventoryEmbedded) header('Cache-Control: no-store, private');
function invEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function invCsvValue($value): string {
    $value = (string)$value;
    return preg_match('/^[\s]*[=+@\-\t\r]/', $value) && !is_numeric($value) ? "'" . $value : $value;
}
$admin = inventoryIsAdmin();
$inventoryDashboardReturn = $inventoryEmbedded || ($admin && ($_POST['inventory_return'] ?? '') === 'dashboard');
$error = (string)($_SESSION['inventory_error'] ?? ''); unset($_SESSION['inventory_error']); $items = []; $history = []; $legacy = [];
$notice = $_SESSION['inventory_notice'] ?? ''; unset($_SESSION['inventory_notice']);
$from = (string)($_GET['from'] ?? '1970-01-01'); $to = (string)($_GET['to'] ?? date('Y-m-d'));
$query = trim((string)($_GET['q'] ?? '')); $type = (string)($_GET['movement'] ?? '');
try {
    if (!$pdo instanceof PDO) throw new RuntimeException('Inventory database is unavailable.');
    inventoryEnsureHistory($pdo);
    if ((!$inventoryEmbedded || $inventoryAsync) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!portalVerifyCsrf()) { http_response_code(419); throw new RuntimeException('Form expired. Refresh and try again.'); }
        $action = (string)($_POST['action'] ?? '');
        if (in_array($action, ['add_item','restock'], true) && !$admin) { http_response_code(403); throw new RuntimeException('Only the administrator can add inventory or stock.'); }
        if ($action === 'add_item') {
            inventoryAddItem($pdo, $_POST); $notice = 'Item and stock addition saved to history.';
        } elseif (in_array($action,['restock','use'],true)) {
            if ($action === 'restock') inventoryRequireAdmin();
            $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1) throw new InvalidArgumentException('Enter a positive whole quantity.');
            $usedAt = str_replace('T',' ',(string)($_POST['used_at'] ?? ''));
            if (strlen($usedAt) === 16) $usedAt .= ':00';
            $details = ['type'=>$action === 'restock' ? 'Stock added' : 'Used', 'service'=>$_POST['service'] ?? '',
                'location'=>$_POST['location'] ?? '', 'purpose'=>$_POST['reason'] ?? ''];
            if ($action === 'use') $details['used_at'] = $usedAt;
            if (trim((string)$details['service']) === '') throw new InvalidArgumentException('Enter the service or department.');
            inventoryChange($pdo,(int)($_POST['item_id'] ?? 0),$action === 'restock' ? $quantity : -$quantity,$details);
            $notice = 'Stock movement saved with its usage details.';
        } else throw new InvalidArgumentException('Unknown inventory action.');
        if (!$inventoryAsync) { $_SESSION['inventory_notice'] = $notice; header('Location: ' . ($inventoryDashboardReturn ? 'RHUAdminDashboard.php?tab=medicine' : 'InventoryManagement.php')); exit; }
    }
    $items = $pdo->query('SELECT * FROM medicine_inventory ORDER BY item_type,item_name,expiration_date,id')->fetchAll(PDO::FETCH_ASSOC);
    if (isset($_GET['export']) && !$admin) { http_response_code(403); exit('Inventory history exports are available to the administrator only.'); }
    if ($admin) {
        foreach ([$from,$to] as $value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Select valid report dates.');
        }
        if ($from > $to) throw new InvalidArgumentException('The start date must be before the end date.');
        if (($_GET['export'] ?? '') === 'legacy') {
            $column = rhuColumnExists($pdo,'stock_transactions','medicine_inventory_id') ? 'medicine_inventory_id' : 'medicine_id';
            $old = $pdo->prepare("SELECT st.*, mi.item_name FROM stock_transactions st LEFT JOIN medicine_inventory mi ON mi.id = st.{$column} WHERE st.transaction_date >= ? AND st.transaction_date <= ? ORDER BY st.transaction_date DESC,st.id DESC");
            $old->execute([$from,$to]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="legacy-inventory-history-' . $from . '-to-' . $to . '.csv"');
            $out = fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,['Legacy ID','Date','Item','Movement','Quantity','Recorded reason','Staff ID if recorded','Recorded at'],',','"','');
            while ($row = $old->fetch(PDO::FETCH_ASSOC)) fputcsv($out,array_map('invCsvValue',[$row['id'],$row['transaction_date'],$row['item_name'] ?? 'Item no longer available',$row['transaction_type'],$row['quantity'],$row['reason'] ?? '',$row['health_worker_id'] ?? '',$row['created_at'] ?? '']),',','"','');
            fclose($out); exit;
        }
        $where = 'used_at >= ? AND used_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params = [$from,$to];
        if ($query !== '') { $where .= " AND CONCAT_WS(' ',item_name,item_type,staff_name,service,location,purpose) LIKE ?"; $params[] = '%' . $query . '%'; }
        if ($type !== '') { $where .= ' AND movement_type = ?'; $params[] = $type; }
        $sql = 'SELECT id,used_at,recorded_at,movement_type,item_name,item_type,unit,quantity_change,quantity_before,quantity_after,staff_id,staff_name,service,location,purpose,resident_id FROM inventory_movements WHERE ' . $where . ' ORDER BY used_at DESC,id DESC';
        $stmt = $pdo->prepare($sql . (isset($_GET['export']) ? '' : ' LIMIT 200'));
        $stmt->execute($params);
        if (isset($_GET['export'])) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="inventory-history-' . $from . '-to-' . $to . '.csv"');
            $out = fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,['Movement ID','Used / added on','Recorded on','Movement','Item','Type','Unit','Quantity change','Before','After','Staff ID','Recorded by','Service','Location','Purpose','Patient ID'],',','"','');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out,array_map('invCsvValue',array_values($row)),',','"','');
            fclose($out); exit;
        }
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (function_exists('rhuTableExists') && rhuTableExists($pdo,'stock_transactions')) {
            $legacyItemColumn = rhuColumnExists($pdo,'stock_transactions','medicine_inventory_id') ? 'medicine_inventory_id' : 'medicine_id';
            $legacyStmt = $pdo->prepare("SELECT st.*, mi.item_name FROM stock_transactions st LEFT JOIN medicine_inventory mi ON mi.id = st.{$legacyItemColumn} WHERE st.transaction_date >= ? AND st.transaction_date <= ? ORDER BY st.transaction_date DESC,st.id DESC LIMIT 100");
            $legacyStmt->execute([$from,$to]); $legacy = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $exception) {
    error_log('Inventory management: ' . $exception->getMessage());
    $error = $exception instanceof PDOException ? 'Could not load or save inventory. Please try again.' : $exception->getMessage();
}
if (!$inventoryEmbedded && $inventoryDashboardReturn && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $error !== '') {
    $_SESSION['inventory_error'] = $error;
    header('Location: RHUAdminDashboard.php?tab=medicine'); exit;
}
$exportParams = $_GET; $exportParams['export'] = 'csv';
$legacyParams = ['from'=>$from,'to'=>$to,'export'=>'legacy'];
?><?php if (!$inventoryEmbedded): ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inventory | ResiHUnity</title>
<script src="assets/js/staff-session-lock.js?v=<?= (int) filemtime(dirname(__DIR__) . "/staff-session-lock.js") ?>"></script>
<style>*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#18332e;font:14px system-ui,sans-serif}main{max-width:1320px;margin:auto;padding:24px}section,header{background:white;border:1px solid #dce6e2;border-radius:16px;padding:22px;margin-bottom:18px}h1,h2{margin-top:0}p{line-height:1.5;color:#526860}form{display:flex;gap:12px;flex-wrap:wrap;align-items:end}label{display:flex;flex-direction:column;gap:6px;font-weight:600}input,select,button,.button{font:inherit;border:1px solid #aabfb5;border-radius:8px;padding:10px;background:white;color:#18332e}button,.button{background:#0f766e;color:white;text-decoration:none;cursor:pointer}table{border-collapse:collapse;width:100%;font-size:12px}th,td{text-align:left;padding:12px;border-bottom:1px solid #e2e8f0;vertical-align:top}th{background:#f8fafc}.scroll{overflow:auto}.error{color:#b91c1c}.notice{color:#047857}small{color:#526860}details{margin:16px 0}summary{cursor:pointer;font-weight:700}@media print{form,.button,button,header nav{display:none}main{padding:0}section{border:0}.scroll{overflow:visible}table{font-size:9px}}</style><link rel="stylesheet" href="styles/dashboard-transitions.css?v=<?= (int) filemtime(dirname(__DIR__) . "/dashboard-transitions.css") ?>">
</head><body><main>
<?php endif; ?>
<div data-no-record-scroll class="inventory-dashboard" data-inventory-items="<?= invEscape(json_encode($admin ? array_map(static function ($item) { return array_intersect_key($item, array_flip(['id','item_name','item_type','category','unit','reorder_level'])); }, $items) : [], JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
<style>
.inventory-dashboard{min-width:0;color:#18332e;font:14px system-ui,sans-serif}.inventory-dashboard section{margin-bottom:18px;border:1px solid #dce6e2;border-radius:16px;background:#fff;padding:22px}.inventory-dashboard h2{margin:0 0 18px;font-size:17px;font-weight:750}.inventory-dashboard form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end}.inventory-dashboard label{display:flex;flex-direction:column;gap:7px;font-size:12px;font-weight:650}.inventory-dashboard input,.inventory-dashboard select,.inventory-dashboard button,.inventory-dashboard .button{width:100%;min-width:0;min-height:42px;border:1px solid #cadbd4;border-radius:9px;padding:10px 12px;background:white;font:inherit;color:#18332e}.inventory-dashboard button,.inventory-dashboard .button{display:inline-flex;align-items:center;justify-content:center;background:#0f766e;color:white;text-decoration:none;font-weight:700;cursor:pointer}.inventory-dashboard .scroll{max-height:420px;overflow:auto;scrollbar-gutter:stable;border:1px solid #e2ebe6;border-radius:12px}.inventory-dashboard table{width:100%;border-collapse:collapse;min-width:700px;font-size:12px}.inventory-dashboard th,.inventory-dashboard td{padding:12px;text-align:left;border-bottom:1px solid #e2ebe6;vertical-align:top}.inventory-dashboard thead{position:sticky;top:0;z-index:2;background:#f0f7f3}.inventory-dashboard p{margin:12px 0;color:#64786e;font-size:12px;line-height:1.6}.inventory-dashboard .error{color:#b42318;background:#fff1f0}.inventory-dashboard .notice{color:#067647;background:#ecfdf3}.inventory-dashboard details{margin-top:20px}.inventory-dashboard summary{cursor:pointer;font-weight:700}.inventory-dashboard small{font-size:11px;color:#64786e}@media print{.inventory-dashboard form,.inventory-dashboard button,.inventory-dashboard .button{display:none}.inventory-dashboard .scroll{max-height:none!important;overflow:visible!important}.inventory-dashboard thead{position:static}}
</style>
<?php if (!$inventoryEmbedded): ?>
<header><h1>Inventory<?= $admin ? ' & stock history' : ' / record usage' ?></h1><p>Medicines, test kits and supplies. Stock additions are managed by the administrator.</p><nav><a class="button" href="StaffLock.php?settings=1&amp;return=InventoryManagement.php">Unlock PIN</a> <a class="button" href="StaffLock.php?return=InventoryManagement.php">Lock now</a></nav></header><?php endif; ?>
<?php if ($error): ?><section class="error" role="alert"><?= invEscape($error) ?></section><?php endif; ?>
<?php if ($notice): ?><section class="notice" role="status"><?= invEscape($notice) ?></section><?php endif; ?>
<style>
.inventory-dashboard input[data-inventory-calendar]{color-scheme:light;cursor:pointer}.inventory-dashboard input[data-inventory-calendar]::-webkit-calendar-picker-indicator{display:block!important;opacity:1!important;cursor:pointer;width:20px;height:20px}.inventory-dashboard [hidden]{display:none!important}.inventory-dashboard .scroll{max-height:none!important;overflow-y:visible!important;overflow-x:auto;scrollbar-gutter:auto}.inventory-dashboard .inventory-navigation{display:flex;flex-wrap:wrap;gap:8px;padding:6px;margin-bottom:18px;background:#eef6f2;border:1px solid #dceae3;border-radius:12px}.inventory-dashboard .inventory-navigation button{width:auto!important;min-height:38px!important;padding:9px 16px!important;border:0!important;border-radius:8px!important;background:transparent!important;color:#536b61!important;box-shadow:none!important;font-size:12px!important}.inventory-dashboard .inventory-navigation button[aria-selected="true"]{background:#fff!important;color:#087b6c!important;box-shadow:0 2px 6px #143d2912!important}.inventory-dashboard .staff-record-search{padding:12px;margin-bottom:14px}.inventory-dashboard .staff-record-search-controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.inventory-dashboard .staff-record-search-controls input{flex:1;width:auto;min-width:180px}.inventory-dashboard .staff-record-search-controls button{width:auto!important;min-width:70px!important}.inventory-dashboard .staff-record-search small{display:none}.inventory-dashboard #inventory-fill-status{grid-column:1/-1;margin:0}.inventory-dashboard .inventory-stock-table>thead{position:static}.inventory-dashboard .inventory-batches>summary{min-height:62px}.inventory-dashboard .inventory-batches>summary>span{padding-top:12px;padding-bottom:12px}@media(max-width:600px){.inventory-dashboard section{padding:14px}.inventory-dashboard .inventory-navigation{gap:4px}.inventory-dashboard .inventory-navigation button{padding:8px 10px!important}}@media print{.inventory-navigation{display:none!important}}
</style>
<nav class="inventory-navigation" role="tablist" aria-label="Inventory sections">
<button type="button" role="tab" id="inventory-tab-stock" aria-controls="inventory-panel-stock" aria-selected="true" data-inventory-tab="stock">Current stock</button>
<?php if ($admin): ?><button type="button" role="tab" id="inventory-tab-add" aria-controls="inventory-panel-add" aria-selected="false" tabindex="-1" data-inventory-tab="add">Add item / batch</button><?php endif; ?>
<button type="button" role="tab" id="inventory-tab-movement" aria-controls="inventory-panel-movement" aria-selected="false" tabindex="-1" data-inventory-tab="movement">Record movement</button>
<?php if ($admin): ?><button type="button" role="tab" id="inventory-tab-history" aria-controls="inventory-panel-history" aria-selected="false" tabindex="-1" data-inventory-tab="history">Stock history</button><?php endif; ?>
</nav>
<?php if ($admin): ?><section id="inventory-panel-add" role="tabpanel" aria-labelledby="inventory-tab-add" data-inventory-view="add" hidden><h2>Add inventory item / new batch</h2><form method="post" action="InventoryManagement.php"><?php if ($inventoryDashboardReturn): ?><input type="hidden" name="inventory_return" value="dashboard"><?php endif; ?><input type="hidden" name="csrf_token" value="<?= invEscape(portalCsrfToken()) ?>"><input type="hidden" name="action" value="add_item">
<datalist id="inventory-item-suggestions"><?php $inventoryNames = []; foreach ($items as $item): if (isset($inventoryNames[$item['item_name']])) continue; $inventoryNames[$item['item_name']] = true; ?><option value="<?= invEscape($item['item_name']) ?>"></option><?php endforeach; ?></datalist>
<p id="inventory-fill-status" role="status" aria-live="polite">Choose an existing item name to reuse its type, category, unit and reorder level. All fields remain editable.</p>
<label>Item name<input name="item_name" required maxlength="150" list="inventory-item-suggestions" autocomplete="off" aria-describedby="inventory-fill-status"></label><label>Type<select name="item_type"><option>Medicine</option><option>Laboratory</option><option>Supply</option><option>Equipment</option></select></label><label>Category<input name="category" maxlength="100"></label><label>Unit<input name="unit" value="pcs" required maxlength="50"></label><label>Quantity received<input type="number" name="quantity" min="1" step="1" required></label><label>Reorder level<input type="number" name="reorder_level" min="0" value="0"></label><label>Expiry (if applicable)<input type="date" name="expiry_date"></label><label>Batch number<input name="batch_number" maxlength="100"></label><label>Received at / location<input name="location" required maxlength="200"></label><label>Supplier / purpose<input name="reason" required maxlength="500"></label><button>Add item and stock</button></form></section><?php endif; ?>
<section id="inventory-panel-movement" role="tabpanel" aria-labelledby="inventory-tab-movement" data-inventory-view="movement" hidden><h2>Record <?= $admin ? 'stock received or usage' : 'usage' ?></h2><form method="post" action="InventoryManagement.php"><?php if ($inventoryDashboardReturn): ?><input type="hidden" name="inventory_return" value="dashboard"><?php endif; ?><input type="hidden" name="csrf_token" value="<?= invEscape(portalCsrfToken()) ?>">
<label>Movement<select name="action"><option value="use">Used / issued</option><?php if ($admin): ?><option value="restock">Stock received (same batch / expiry)</option><?php endif; ?></select></label>
<label>Item / batch<select name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= (int)$item['id'] ?>"><?= invEscape($item['item_name'] . ' / ' . $item['item_type'] . ' / batch ' . ($item['batch_number'] ?: 'not set') . ' / item #' . $item['id'] . ' / ' . $item['quantity'] . ' ' . $item['unit']) ?></option><?php endforeach; ?></select></label>
<label>Quantity<input type="number" name="quantity" min="1" step="1" required></label><label>Used on<input type="datetime-local" name="used_at" value="<?= date('Y-m-d\TH:i') ?>" required></label><label>Service / department<input name="service" value="<?= invEscape($_SESSION['rhu_staff_login']['staff_type'] ?? 'RHU') ?>" required maxlength="100"></label><label>Used / received at<input name="location" required maxlength="200"></label><label>Purpose<input name="reason" required maxlength="1000"></label><button>Save movement</button></form><p><small>Usage automatically deducts the earliest-expiring unexpired batch first, then continues to the next batch. Stock received here adds to the selected batch and keeps its existing expiry. For a different batch or expiry, use Add inventory item / new batch above. Record actual usage here only if it has not already been deducted by a test or dispensing form.</small></p></section>
<style>
.inventory-stock-table{table-layout:fixed;width:100%;min-width:720px;border-collapse:collapse}.inventory-stock-table>thead th{padding:14px 18px!important;font-size:11px;letter-spacing:.04em;color:#376459;background:#e9f6f0}.inventory-stock-table>tbody>tr>td{padding:0!important;vertical-align:top}.inventory-stock-table details.inventory-batches{margin:0!important}.inventory-batches>summary{display:grid;grid-template-columns:28% 23% 15% 16% 18%;align-items:center;list-style:none;font-weight:400!important;cursor:pointer;min-height:76px;background:#fff}.inventory-batches>summary::-webkit-details-marker{display:none}.inventory-batches>summary:hover{background:#f5faf8}.inventory-batches>summary:focus-visible{outline:2px solid #0f9488;outline-offset:-3px}.inventory-batches>summary>span{padding:16px 18px;min-width:0;overflow-wrap:anywhere}.inventory-batches .inventory-item-name{font-weight:700;color:#183f35;font-size:14px}.inventory-batches .inventory-category{font-size:12px;color:#64786e}.inventory-batches .inventory-stock-total{font-weight:700;white-space:nowrap}.inventory-batches .inventory-batch-toggle{display:inline-flex;align-items:center;gap:8px;border:1px solid #bce3d5;border-radius:8px;padding:7px 10px;background:#f0faf6;color:#08786a;font-size:12px;font-weight:700;white-space:nowrap}.inventory-batch-toggle::after{content:'\2304';font-size:16px}.inventory-batches[open] .inventory-batch-toggle::after{content:'\2303'}.inventory-batches[open]>summary{background:#f4faf7}.inventory-batch-panel{padding:18px 22px 22px;background:#f6faf8;border-top:1px solid #dcece4}.inventory-batch-panel>p{margin:0 0 12px!important;font-size:12px;color:#5a7368}.inventory-batch-panel table{min-width:0!important;width:100%;background:#fff;border:1px solid #dde9e3;border-radius:10px;font-size:12px}.inventory-batch-panel th{background:#edf4f0!important;color:#456357;font-size:10px;letter-spacing:.04em}.inventory-batch-panel th,.inventory-batch-panel td{padding:10px 14px!important}.inventory-batch-panel thead{position:static!important}.inventory-stock-table .inventory-expiry{font-size:12px;white-space:nowrap}
.inventory-stock-table .inventory-batches>summary .inventory-batch-toggle{width:auto!important;min-height:0!important;min-width:0!important;box-shadow:none!important;border:1px solid #d1e7df!important;background:#f0f8f4!important;color:#167265!important;border-radius:999px!important;padding:5px 10px!important;font-size:11px!important;font-weight:650!important;line-height:1.4!important;gap:7px!important;white-space:nowrap!important}.inventory-batches>summary>span:nth-child(4){padding-left:10px;padding-right:10px}.inventory-batch-toggle::after{content:''!important;width:6px;height:6px;border-right:1.5px solid currentColor;border-bottom:1.5px solid currentColor;transform:rotate(45deg);margin-top:-3px;transition:transform .15s}.inventory-batches[open] .inventory-batch-toggle::after{content:''!important;transform:rotate(225deg);margin-top:3px}
</style>
<section id="inventory-panel-stock" role="tabpanel" aria-labelledby="inventory-tab-stock" data-inventory-view="stock"><h2>Current inventory</h2><p>Expand an item to view its batches and original expiry dates.</p><div class="scroll"><table class="inventory-stock-table"><colgroup><col style="width:28%"><col style="width:23%"><col style="width:15%"><col style="width:16%"><col style="width:18%"></colgroup><thead><tr><th>Item</th><th>Type / category</th><th>Total stock</th><th>Batches</th><th>Earliest expiry in stock</th></tr></thead><tbody>
<?php foreach (inventoryDisplayGroups($items) as $group): ?>
<tr data-record-search-row><td colspan="5"><details class="inventory-batches"><summary aria-label="<?= invEscape($group['item_name']) ?>: view batches">
<span class="inventory-item-name"><?= invEscape($group['item_name']) ?></span><span class="inventory-category"><?= invEscape($group['item_type'] . ' / ' . implode(', ', $group['categories'])) ?></span><span class="inventory-stock-total"><?= (int)$group['quantity'] ?> <?= invEscape($group['unit']) ?></span><span><span class="inventory-batch-toggle"><?= count($group['batches']) ?> <?= count($group['batches']) === 1 ? 'batch' : 'batches' ?></span></span><span class="inventory-expiry"><?= invEscape($group['earliest_expiry'] ?: 'Not set / no dated stock') ?></span>
</summary><div class="inventory-batch-panel" data-no-record-search><p>Batch details: Total stock includes all batches below. Each expiry date is kept separately.</p>
<table aria-label="<?= invEscape($group['item_name']) ?> batches"><thead><tr><th>Item ID</th><th>Batch number</th><th>Stock</th><th>Expiry</th></tr></thead><tbody>
<?php foreach ($group['batches'] as $batch): ?><tr><td>#<?= (int)$batch['id'] ?></td><td><?= invEscape($batch['batch_number'] ?: 'Not set') ?></td><td><?= (int)$batch['quantity'] ?> <?= invEscape($batch['unit']) ?></td><td><?= invEscape($batch['expiration_date'] ?: 'Not set') ?></td></tr><?php endforeach; ?>
</tbody></table></div></details></td></tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="5" style="padding:18px!important">No inventory items recorded.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php if ($admin): ?><section id="inventory-panel-history" role="tabpanel" aria-labelledby="inventory-tab-history" data-inventory-view="history" data-no-record-search hidden><h2>Stock additions &amp; staff usage history</h2><form method="get" action="<?= $inventoryEmbedded ? 'RHUAdminDashboard.php' : 'InventoryManagement.php' ?>"><?php if ($inventoryEmbedded): ?><input type="hidden" name="tab" value="medicine"><?php endif; ?><label>From<input type="date" data-inventory-calendar name="from" value="<?= invEscape($from) ?>" required></label><label>To<input type="date" data-inventory-calendar name="to" value="<?= invEscape($to) ?>" required></label><label>Search<input name="q" value="<?= invEscape($query) ?>" placeholder="Item, staff, service or location"></label><label>Movement<select name="movement"><option value="">All</option><?php foreach (['Stock added','Used'] as $option): ?><option <?= $type === $option ? 'selected' : '' ?>><?= invEscape($option) ?></option><?php endforeach; ?></select></label><button>Filter</button><a class="button" href="InventoryManagement.php?<?= invEscape(http_build_query($exportParams)) ?>">Download history CSV</a><button type="button" onclick="printInventoryHistory(this)">Print stock history</button></form>
<p>Showing up to 200 movements. The CSV includes every movement matching these filters. Detailed tracking starts when this feature is enabled; missing older details are not reconstructed.</p>
<div class="scroll"><table><thead><tr><th>Used / added on</th><th>Recorded on</th><th>Item / type</th><th>Movement</th><th>Change</th><th>Before / after</th><th>Recorded by</th><th>Service</th><th>Location</th><th>Purpose</th></tr></thead><tbody><?php foreach ($history as $row): ?><tr><td><?= invEscape($row['used_at']) ?></td><td><?= invEscape($row['recorded_at']) ?></td><td><?= invEscape($row['item_name'] . ' / ' . $row['item_type']) ?></td><td><?= invEscape($row['movement_type']) ?></td><td><?= (int)$row['quantity_change'] ?> <?= invEscape($row['unit']) ?></td><td><?= (int)$row['quantity_before'] ?> / <?= (int)$row['quantity_after'] ?></td><td><?= invEscape($row['staff_name']) ?> (#<?= (int)$row['staff_id'] ?>)</td><td><?= invEscape($row['service']) ?></td><td><?= invEscape($row['location']) ?></td><td><?= invEscape($row['purpose']) ?></td></tr><?php endforeach; ?><?php if (!$history): ?><tr><td colspan="10">No detailed movements recorded for these filters.</td></tr><?php endif; ?></tbody></table></div>
<div class="inventory-legacy-history"><h3>Older stock transaction records (up to 100, selected dates)</h3><p>These older entries may not contain the staff, location or service. They are separate from the detailed history and CSV.</p><a class="button" href="InventoryManagement.php?<?= invEscape(http_build_query($legacyParams)) ?>">Download older transactions CSV</a><div class="scroll"><table><thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Quantity</th><th>Recorded reason</th></tr></thead><tbody><?php if (!$legacy): ?><tr><td colspan="5">No older transactions recorded for the selected dates.</td></tr><?php endif; ?><?php foreach ($legacy as $row): ?><tr><td><?= invEscape($row['transaction_date'] ?? '') ?></td><td><?= invEscape($row['item_name'] ?? 'Item no longer available') ?></td><td><?= invEscape($row['transaction_type'] ?? '') ?></td><td><?= invEscape($row['quantity'] ?? '') ?></td><td><?= invEscape($row['reason'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div></section><?php endif; ?>
<?php if ($admin): ?>
<script>
function initializeInventoryAutofill(root = document) {
    const container = root.matches?.('.inventory-dashboard') ? root : root.querySelector('.inventory-dashboard');
    const items = container ? JSON.parse(container.dataset.inventoryItems || '[]') : <?= json_encode(array_map(static function ($item) { return array_intersect_key($item, array_flip(['id','item_name','item_type','category','unit','reorder_level'])); }, $items), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const name = root.querySelector('input[list="inventory-item-suggestions"]');
    if (!name) return;
    const form = name.form;
    const status = root.querySelector('#inventory-fill-status');
    const fields = ['item_type', 'category', 'unit', 'reorder_level'];
    const defaults = {};
    const automatic = {};
    const edited = new Set();
    fields.forEach(key => {
        const field = form.elements.namedItem(key);
        defaults[key] = field.value;
        field.addEventListener('input', () => { edited.add(key); delete automatic[key]; });
        field.addEventListener('change', () => { edited.add(key); delete automatic[key]; });
    });
    const normalize = value => String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase();
    name.addEventListener('input', () => {
        // Newer batches carry the most recently saved item settings.
        const matches = items.filter(item => normalize(item.item_name) === normalize(name.value))
            .sort((a, b) => Number(b.id) - Number(a.id));
        const match = matches[0];
        let count = 0;
        fields.forEach(key => {
            const field = form.elements.namedItem(key);
            if (edited.has(key)) return;
            if (match && match[key] !== null && match[key] !== undefined && String(match[key]) !== '') {
                field.value = String(match[key]);
                automatic[key] = field.value;
                count++;
            } else if (Object.hasOwn(automatic, key)) {
                field.value = defaults[key];
                delete automatic[key];
            }
        });
        status.textContent = match
            ? (count ? 'Filled saved item details from the latest batch. ' : 'Your edited details have been kept. ') + 'Review before saving; quantity, expiry, batch and receiving details must be entered for this delivery.'
            : 'Choose an existing item name to reuse its details. For a new item, enter its details manually.';
    });
    form.addEventListener('reset', () => {
        edited.clear();
        Object.keys(automatic).forEach(key => delete automatic[key]);
        status.textContent = 'Choose an existing item name to reuse its type, category, unit and reorder level. All fields remain editable.';
    });
}
initializeInventoryAutofill();
</script>
<?php endif; ?>
<script>
function selectInventoryView(root, view) {
    if (![...root.querySelectorAll('[data-inventory-tab]')].some(tab => tab.dataset.inventoryTab === view)) return;
    const currentUrl = new URL(location.href);
    currentUrl.searchParams.set('view', view);
    history.replaceState(history.state, '', currentUrl);
    root.querySelectorAll('[data-inventory-view]').forEach(panel => { panel.hidden = panel.dataset.inventoryView !== view; });
    root.querySelectorAll('[data-inventory-tab]').forEach(button => {
        const active = button.dataset.inventoryTab === view;
        button.setAttribute('aria-selected', String(active));
        button.tabIndex = active ? 0 : -1;
    });
}
document.querySelectorAll('.inventory-dashboard').forEach(root => {
    const requested = new URLSearchParams(location.search).get('view');
    if (requested && [...root.querySelectorAll('[data-inventory-tab]')].some(tab => tab.dataset.inventoryTab === requested)) selectInventoryView(root, requested);
});
if (!window.rhuInventoryNavigationInstalled) {
    window.rhuInventoryNavigationInstalled = true;
    document.addEventListener('click', event => {
        const dateField = event.target.closest('[data-inventory-calendar]');
        if (dateField && typeof dateField.showPicker === 'function') {
            try { dateField.showPicker(); } catch (_) { /* Native date input remains available. */ }
        }
        const button = event.target.closest('[data-inventory-tab]');
        if (button) selectInventoryView(button.closest('.inventory-dashboard'), button.dataset.inventoryTab);
    });
    document.addEventListener('keydown', event => {
        const button = event.target.closest('[data-inventory-tab]');
        if (!button || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        event.preventDefault();
        const buttons = [...button.parentElement.querySelectorAll('[data-inventory-tab]')];
        let index = buttons.indexOf(button);
        index = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
        buttons[index].click(); buttons[index].focus();
    });
}
</script>
<script>
if (!window.rhuInventoryAsyncInstalled) {
    window.rhuInventoryAsyncInstalled = true;
    document.addEventListener('submit', async event => {
        const form = event.target;
        const root = form.closest('.inventory-dashboard');
        if (!root) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (root.getAttribute('aria-busy') === 'true') return;
        const scrollPositions = [];
        for (let parent = root.parentElement; parent; parent = parent.parentElement) {
            scrollPositions.push([parent, parent.scrollTop, parent.scrollLeft]);
        }
        const activeView = root.querySelector('[data-inventory-tab][aria-selected="true"]')?.dataset.inventoryTab || 'stock';
        const isSave = form.method.toLowerCase() === 'post';
        const data = new FormData(form);
        // Preserve history filters when stock changes are saved.
        const filter = root.querySelector('form[method="get"]');
        const params = new URLSearchParams(filter ? new FormData(filter) : undefined);
        params.delete('tab');
        const url = new URL('InventoryManagement.php', location.href);
        url.search = params.toString();
        const buttons = [...root.querySelectorAll('button')].map(button => [button, button.disabled]);
        buttons.forEach(([button]) => button.disabled = true);
        root.setAttribute('aria-busy', 'true');
        let feedback = root.querySelector('[data-inventory-feedback]');
        if (!feedback) {
            feedback = document.createElement('p');
            feedback.dataset.inventoryFeedback = '';
            feedback.setAttribute('role', 'status');
            form.after(feedback);
        }
        feedback.textContent = isSave ? 'Saving stock changes...' : 'Updating history...';
        try {
            const response = await fetch(url, {
                method: isSave ? 'POST' : 'GET',
                credentials: 'same-origin',
                headers: { 'X-RHU-Inventory': 'update' },
                ...(isSave ? { body: data } : {})
            });
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const updated = page.querySelector('.inventory-dashboard');
            if (!updated) throw new Error('Your session may have expired. Refresh the page and sign in again.');
            const failure = updated.querySelector('.error');
            if (!response.ok || failure) throw new Error(failure?.textContent || 'The update could not be completed.');
            const currentUrl = new URL(location.href);
            ['from', 'to', 'q', 'movement'].forEach(key => {
                if (params.has(key)) currentUrl.searchParams.set(key, params.get(key));
            });
            currentUrl.searchParams.set('view', activeView);
            history.replaceState(history.state, '', currentUrl);
            // Keep unsaved receiving/usage forms intact when filtering history.
            if (!isSave) {
                const replacement = updated.querySelector('form[method="get"]').closest('section');
                replacement.hidden = false;
                form.closest('section').replaceWith(replacement);
            } else {
                updated.querySelectorAll('script').forEach(script => script.remove());
                selectInventoryView(updated, activeView);
                root.replaceWith(updated);
                if (typeof initializeInventoryAutofill === 'function') initializeInventoryAutofill(updated);
            }
        } catch (error) {
            feedback.setAttribute('role', 'alert');
            feedback.textContent = (isSave ? 'Could not confirm the save. Check stock history before submitting again. ' : '') + error.message;
        } finally {
            root.removeAttribute('aria-busy');
            scrollPositions.forEach(([element, top, left]) => { element.scrollTop = top; element.scrollLeft = left; });
            buttons.forEach(([button, disabled]) => button.disabled = disabled);
        }
    }, true);
}
</script>
<script>
function printInventoryHistory(button) {
    const section = button.closest('section');
    const table = section.querySelector('.scroll table');
    if (!table) return;
    button.disabled = true;
    const frame = document.createElement('iframe');
    frame.title = 'Stock history print report';
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;width:0;height:0;border:0;bottom:0;left:0';
    document.body.appendChild(frame);
    const report = frame.contentDocument;
    report.open();
    report.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><title>RHU Stock History Report</title><style>
    @page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{margin:0;font:11px Arial,sans-serif;color:#18332e}
    .report-header{display:grid;grid-template-columns:80px 1fr 80px;align-items:center;gap:20px;border-bottom:2px solid #0f766e;padding:0 0 14px;margin-bottom:18px;text-align:center}
    .report-header img{width:76px;height:76px;object-fit:contain}.report-header p{margin:4px 0;font-size:11px}.report-header strong{display:block;font-size:18px;letter-spacing:1px;margin:6px 0}.report-header h1{font-size:15px;margin:12px 0 0;text-transform:uppercase;letter-spacing:.5px}
    .report-summary{padding:10px 0;margin:0 0 10px;border-bottom:1px solid #cbd5e1;line-height:1.6}
    table{border-collapse:collapse;width:100%;table-layout:fixed;font-size:9px}th,td{border:1px solid #b9cec7;padding:7px 6px;text-align:left;overflow-wrap:anywhere;vertical-align:top}th{background:#e3f2ec;color:#173d33}thead{display:table-header-group}tr{break-inside:avoid}tbody tr:nth-child(even){background:#f6faf8}.report-note{font-size:10px;color:#526860;margin-top:12px}.report-footer{display:flex;justify-content:space-between;border-top:1px solid #b9cec7;margin-top:24px;padding-top:9px;font-size:9px;color:#526860}
    </style></head><body><header class="report-header"><img class="municipality-logo" alt="Municipality of Nasugbu seal"><div><p>Republic of the Philippines</p><p>Province of Batangas &bull; Municipality of Nasugbu</p><strong>RURAL HEALTH UNIT</strong><p>Nasugbu, Batangas</p><h1>Stock Additions &amp; Usage History</h1></div><img class="rhu-logo" alt="RHU ResiHUnity logo"></header></body></html>`);
    report.close();
    report.querySelector('.municipality-logo').src = new URL('assets/images/nasugbu_seal.png', location.href).href;
    report.querySelector('.rhu-logo').src = new URL('assets/images/resihunity_logo.jpg', location.href).href;
    const filters = section.querySelector('form');
    const summary = report.createElement('p');
    const from = filters.elements.namedItem('from').value;
    const to = filters.elements.namedItem('to').value;
    const search = filters.elements.namedItem('q').value;
    const movement = filters.elements.namedItem('movement').value;
    summary.className = 'report-summary';
    summary.textContent = 'Period: ' + from + ' to ' + to + ' | Movement: ' + (movement || 'All') + (search ? ' | Search: ' + search : '');
    report.body.appendChild(summary);
    report.body.appendChild(report.importNode(table, true));
    const note = report.createElement('p');
    note.className = 'report-note';
    note.textContent = 'Includes the displayed results (up to 200 movements). Download history CSV for all matching movements.';
    report.body.appendChild(note);
    const footer = report.createElement('footer');
    footer.className = 'report-footer';
    const office = report.createElement('span');
    office.textContent = 'Rural Health Unit | Municipality of Nasugbu';
    const generated = report.createElement('span');
    generated.textContent = 'Generated: ' + new Date().toLocaleString('en-PH', {timeZone:'Asia/Manila'});
    footer.append(office, generated);
    report.body.appendChild(footer);
    const cleanup = () => { frame.remove(); button.disabled = false; };
    frame.contentWindow.addEventListener('afterprint', cleanup, { once: true });
    Promise.all([...report.images].map(img => img.decode())).then(() => {
        try { frame.contentWindow.focus(); frame.contentWindow.print(); }
        catch (error) { cleanup(); }
        finally { button.disabled = false; }
    }).catch(() => { cleanup(); alert('Report logos could not load. Refresh the page and try printing again.'); });
}
</script>
</div><?php if (!$inventoryEmbedded): ?><link rel="stylesheet" href="styles/staff-record-search.css?v=2"><script defer src="assets/js/staff-record-search.js?v=2"></script>
</main></body></html><?php endif; ?>
