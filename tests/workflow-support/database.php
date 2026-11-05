<?php
// CLI-only, schema-only fixtures. Never reads .env or copies patient data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('RHU_TEST_DB');
if (!preg_match('/^rhu_pw_[0-9]{13}$/', (string)$name)) throw new RuntimeException('Invalid isolated database name');
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (($argv[1] ?? '') === 'cleanup') {
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    echo "Removed isolated database $name\n";
    exit;
}
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $pdo->exec("USE `$name`");
    $schema = file_get_contents(dirname(__DIR__, 2) . '/database/database_schema.sql');
    $schema = preg_replace('/^--[^\r\n]*/m', '', $schema);
    $schema = preg_replace('/^(CREATE DATABASE[^;]+;|USE\s+`[^`]+`;)/mi', '', $schema);
    if (preg_match('/\b(INSERT INTO|DROP DATABASE|USE\s+`)\b/i', $schema)) throw new RuntimeException('Expected schema only');
    foreach (explode(';', $schema) as $statement) {
        if (!trim($statement)) continue;
        // The schema export omits sanitary_records' primary key.
        if (preg_match('/ALTER TABLE `sanitary_records`\s+MODIFY/i', $statement)) {
            $pdo->exec('ALTER TABLE sanitary_records ADD PRIMARY KEY (id)');
        }
        try { $pdo->exec($statement); }
        catch (Throwable $error) { throw new RuntimeException(trim($statement) . ': ' . $error->getMessage()); }
    }
    $pdo->exec("INSERT INTO barangays (id,name) VALUES (1,'Test Barangay')");
    $hash = password_hash('Workflow-Test-123!', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO residents (id,first_name,last_name,birthdate,sex,barangay_id,address,email,username,password_hash) VALUES (1,'Workflow','Resident','1995-01-01','Female',1,'Synthetic test address','resident@example.invalid','workflowresident',?)");
    $stmt->execute([$hash]);
    $pdo->exec("INSERT INTO residents (id,first_name,last_name,birthdate,sex,barangay_id,address) VALUES (2,'Existing','Testpatient','1990-03-04','Female',1,'Synthetic existing patient')");
    $staff = $pdo->prepare('INSERT INTO health_workers (id,first_name,last_name,email,username,password_hash,role,position_title) VALUES (?,?,?,?,?,?,?,?)');
    foreach ([1=>['admin','Admin','RHU_ADMIN'],2=>['nurse','Staff','Public Health Nurse'],3=>['dental','Staff','Dental Health Staff'],4=>['midwife','Staff','Rural Health Midwife'],5=>['medtech','Staff','Medical Technologist'],6=>['sanitary','Staff','Sanitary Inspector'],7=>['secretary','Staff','Admin Secretary']] as $id=>$role) {
        $staff->execute([$id,'Workflow',ucfirst($role[0]),$role[0].'@example.invalid','workflow'.$role[0],$hash,$role[1],$role[2]]);
    }
    echo "Created isolated database $name with synthetic accounts\n";
} catch (Throwable $error) {
    $pdo->exec("DROP DATABASE `$name`");
    throw $error;
}
