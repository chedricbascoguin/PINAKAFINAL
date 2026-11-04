<?php
@include_once dirname(__DIR__) . '/db.php';
header('Content-Type: text/plain; charset=utf-8');
$email = $_GET['email'] ?? '';
if (!$email) {
    echo "Usage: ?email=you@example.com\n";
    exit;
}
if (!isset($pdo) || !$pdo) {
    echo "DB unavailable (db.php could not connect)\n";
    exit;
}
try {
    $stmt = $pdo->prepare('SELECT id, username, email, first_name, last_name, role, position_title, status, created_at FROM health_workers WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    if ($user) {
        echo "Health worker/admin found:\n";
        foreach ($user as $k => $v) echo "$k: $v\n";
    } else {
        echo "Health worker/admin not found for $email\n";
    }

    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email, barangay, contact_number, created_at FROM residents WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $res = $stmt->fetch();
    if ($res) {
        echo "\nResident found:\n";
        foreach ($res as $k => $v) echo "$k: $v\n";
    } else {
        echo "\nResident not found for $email\n";
    }
} catch (PDOException $e) {
    echo 'Query error: ' . $e->getMessage() . "\n";
}
