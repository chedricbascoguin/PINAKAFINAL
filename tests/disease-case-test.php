<?php
require dirname(__DIR__) . '/includes/admin_extended.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('DATABASE', static fn() => 'test');
$pdo->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));
$pdo->exec("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
$pdo->exec('CREATE TABLE INFORMATION_SCHEMA.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
$pdo->exec('CREATE TABLE residents (id INTEGER PRIMARY KEY, barangay_id INTEGER, first_name TEXT, last_name TEXT, sex TEXT, is_active INTEGER, status TEXT)');
$pdo->exec("INSERT INTO residents VALUES (1, 2, 'Test', 'Resident', 'Female', 1, 'Active')");
$pdo->exec('CREATE TABLE health_workers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
$pdo->exec('CREATE TABLE disease_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
$pdo->exec("INSERT INTO disease_types (name) VALUES ('Dengue')");
$pdo->exec('CREATE TABLE disease_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, resident_id INTEGER NOT NULL, disease_type_id INTEGER NOT NULL, case_date TEXT NOT NULL, case_status TEXT, outcome TEXT, remarks TEXT, barangay_id INTEGER NOT NULL, date_reported TEXT NOT NULL, reported_by_id INTEGER, symptoms TEXT, case_classification TEXT)');
foreach (['barangay_id', 'date_reported', 'reported_by_id', 'symptoms', 'case_classification'] as $column) {
    $pdo->prepare("INSERT INTO INFORMATION_SCHEMA.COLUMNS VALUES ('test', 'disease_cases', ?)")->execute([$column]);
}
function portalAudit($pdo, $adminId, $message, $table, $id) {
    $GLOBALS['diseaseTestAudit'][] = compact('adminId', 'message', 'table', 'id');
}
function checkDisease($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
if (in_array('--render', $argv, true)) {
    renderAdminExtendedPanel($pdo, 'disease');
    exit;
}
$_POST = ['resident_id' => 1, 'disease_id' => 'new', 'new_disease_name' => 'Test disease', 'case_date' => '2026-10-10', 'classification' => 'Suspected', 'symptoms' => 'Test symptoms'];
checkDisease(adminExtendedAction($pdo, 'create_disease_case', 5) === 'Disease case recorded.', 'New disease case failed.');
$saved = $pdo->query('SELECT dc.*, dt.name FROM disease_cases dc JOIN disease_types dt ON dt.id=dc.disease_type_id')->fetch(PDO::FETCH_ASSOC);
checkDisease($saved['name'] === 'Test disease' && $saved['case_date'] === '2026-10-10', 'Disease and selected date not persisted.');
checkDisease((int)$saved['barangay_id'] === 2 && (int)$saved['reported_by_id'] === 5 && $saved['symptoms'] === 'Test symptoms', 'Case attribution or symptoms missing.');
$_POST['new_disease_name'] = ' test DISEASE ';
checkDisease(adminExtendedAction($pdo, 'create_disease_case', 5) === 'Disease case recorded.', 'Existing disease name reuse failed.');
checkDisease((int)$pdo->query('SELECT COUNT(*) FROM disease_types')->fetchColumn() === 2, 'Duplicate disease type created.');
$_POST['disease_id'] = '1';
checkDisease(adminExtendedAction($pdo, 'create_disease_case', 5) === 'Disease case recorded.', 'Selected disease case failed.');
foreach ([['case_date', '2026-02-30'], ['resident_id', 999], ['disease_id', '999'], ['classification', 'Invalid']] as [$key, $value]) {
    $original = $_POST[$key];
    $_POST[$key] = $value;
    checkDisease(adminExtendedAction($pdo, 'create_disease_case', 5) !== 'Disease case recorded.', 'Invalid ' . $key . ' accepted.');
    $_POST[$key] = $original;
}
$_POST['disease_id'] = 'new';
$_POST['new_disease_name'] = '';
checkDisease(adminExtendedAction($pdo, 'create_disease_case', 5) === 'Enter the new disease name.', 'Empty new disease accepted.');
$_POST['new_disease_name'] = 'Must roll back';
$pdo->exec("CREATE TRIGGER reject_case BEFORE INSERT ON disease_cases BEGIN SELECT RAISE(ABORT, 'test failure'); END");
try {
    adminExtendedAction($pdo, 'create_disease_case', 5);
    throw new RuntimeException('Expected case insert failure.');
} catch (PDOException $e) {
    checkDisease((int)$pdo->query("SELECT COUNT(*) FROM disease_types WHERE name='Must roll back'")->fetchColumn() === 0, 'Failed case left an orphan disease type.');
    checkDisease(!$pdo->inTransaction(), 'Failed case left transaction open.');
}
echo "Passed: new/listed disease cases, duplicate-name reuse, date and selection validation, attribution and rollback.\n";
