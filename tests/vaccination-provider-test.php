<?php
require dirname(__DIR__) . '/includes/admin_extended.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('DATABASE', static fn() => 'test');
$pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('CONCAT_WS', static fn($separator, ...$parts) => implode($separator, array_filter($parts, static fn($part) => $part !== null)));
$pdo->exec("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
$pdo->exec('CREATE TABLE INFORMATION_SCHEMA.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
$pdo->exec("INSERT INTO INFORMATION_SCHEMA.COLUMNS VALUES ('test', 'vaccination_records', 'provider_name')");
$pdo->exec('CREATE TABLE residents (id INTEGER PRIMARY KEY, birthdate TEXT)');
$pdo->exec("INSERT INTO residents VALUES (1, '2020-01-01')");
$pdo->exec('CREATE TABLE health_workers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
$pdo->exec("INSERT INTO health_workers VALUES (5, 'Registered', 'Provider')");
$pdo->exec('CREATE TABLE vaccination_records (id INTEGER PRIMARY KEY AUTOINCREMENT, resident_id INTEGER NOT NULL, vaccine_name TEXT NOT NULL, vaccination_date TEXT NOT NULL, health_worker_id INTEGER, batch_number TEXT, next_dose_date TEXT, provider_name TEXT, age_group TEXT NOT NULL, updated_at TEXT)');

function portalAudit($pdo, $adminId, $message, $table, $id) {
    $GLOBALS['vaccinationTestAudit'] = compact('adminId', 'message', 'table', 'id');
}

function checkVaccination($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$pdo->beginTransaction();
try {
    $_POST = ['resident_id' => 1, 'vaccine_name' => 'Test Vaccine', 'vaccination_date' => '2026-10-10', 'provider_name' => 'Non-account staff test', 'batch_number' => 'TEST'];
    checkVaccination(adminExtendedAction($pdo, 'save_vaccination', 0) === 'Vaccination record saved.', 'Create failed.');
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT provider_name,health_worker_id FROM vaccination_records WHERE id=?');
    $stmt->execute([$id]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);
    checkVaccination($saved['provider_name'] === 'Non-account staff test' && $saved['health_worker_id'] === null, 'Provider must be stored without a login account.');
    checkVaccination($GLOBALS['vaccinationTestAudit']['id'] === $id && $GLOBALS['vaccinationTestAudit']['adminId'] === 0, 'Audit must identify the encoder and record.');
    checkVaccination($pdo->query('SELECT age_group FROM vaccination_records')->fetchColumn() === 'Child', 'New record must include the required age group.');

    $_POST['vaccination_id'] = $id;
    $_POST['provider_name'] = 'Updated offline provider';
    checkVaccination(adminExtendedAction($pdo, 'save_vaccination', 0) === 'Vaccination record saved.', 'Update failed.');
    $stmt->execute([$id]);
    checkVaccination($stmt->fetch(PDO::FETCH_ASSOC)['provider_name'] === 'Updated offline provider', 'Provider update not persisted.');

    $_POST['provider_name'] = '';
    checkVaccination(adminExtendedAction($pdo, 'save_vaccination', 0) !== 'Vaccination record saved.', 'Missing provider accepted.');
    $_POST['provider_name'] = 'Offline provider';
    $_POST['vaccination_id'] = PHP_INT_MAX;
    checkVaccination(adminExtendedAction($pdo, 'save_vaccination', 0) === 'Vaccination record not found.', 'Missing record accepted.');
    $_POST['vaccination_date'] = '2026-02-30';
    checkVaccination(adminExtendedAction($pdo, 'save_vaccination', 0) === 'Enter a valid vaccination date.', 'Invalid date accepted.');

    $_POST['vaccination_date'] = '2026-10-10';
    $_POST['vaccination_id'] = $id;
    $_POST['next_dose_date'] = '2026-10-09';
    checkVaccination(adminExtendedAction($pdo, 'save_vaccination', 0) !== 'Vaccination record saved.', 'Invalid next dose accepted.');
    $_POST['next_dose_date'] = '';
    $_POST['provider_name'] = '';
    $_POST['provider_id'] = 5;
    checkVaccination(adminExtendedAction($pdo, 'save_vaccination', 0) === 'Vaccination record saved.', 'Existing account provider submissions must remain supported.');
    $stmt->execute([$id]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);
    checkVaccination($saved['provider_name'] === 'Registered Provider' && (int)$saved['health_worker_id'] === 5, 'Existing account attribution lost.');
    echo "Passed: offline provider create/update, audit attribution, required age group, validation and existing account compatibility.\n";
} finally {
    $pdo->rollBack();
}
