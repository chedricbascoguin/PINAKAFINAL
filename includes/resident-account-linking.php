<?php
function residentLinkEnsure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS resident_account_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(150) NOT NULL,
        last_name VARCHAR(150) NOT NULL, birthdate DATE NOT NULL, email VARCHAR(254) NOT NULL,
        phone VARCHAR(100) NOT NULL, barangay VARCHAR(150) NOT NULL, password_hash VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending', resident_id BIGINT UNSIGNED NULL,
        reviewed_by BIGINT UNSIGNED NULL, verification_note TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at DATETIME NULL,
        INDEX idx_account_request_email(email), INDEX idx_account_request_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function residentLinkApprove(PDO $pdo, int $requestId, int $residentId, int $staffId, string $note): void {
    if ($staffId <= 0 || trim($note) === '') throw new RuntimeException('Record how the identity was verified.');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("SELECT * FROM resident_account_requests WHERE id=? AND status='Pending' FOR UPDATE");
        $stmt->execute([$requestId]); $request=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['password_hash']) throw new RuntimeException('This request is no longer pending.');
        $stmt=$pdo->prepare('SELECT * FROM residents WHERE id=? FOR UPDATE');
        $stmt->execute([$residentId]); $resident=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$resident || strcasecmp(trim($resident['first_name']),trim($request['first_name'])) || strcasecmp(trim($resident['last_name']),trim($request['last_name'])) || $resident['birthdate'] !== $request['birthdate']) throw new RuntimeException('Select a matching patient record.');
        if (!empty($resident['password_hash']) || !empty($resident['user_id'])) throw new RuntimeException('This patient already has an account. Use password recovery.');
        $stmt=$pdo->prepare('SELECT id FROM residents WHERE LOWER(email)=LOWER(?) AND id<>? FOR UPDATE');
        $stmt->execute([$request['email'],$residentId]);
        if ($stmt->fetchColumn()) throw new RuntimeException('This email is already used by another resident.');
        $stmt=$pdo->prepare('UPDATE residents SET email=?, username=?, password_hash=? WHERE id=?');
        $stmt->execute([$request['email'],$request['email'],$request['password_hash'],$residentId]);
        $stmt=$pdo->prepare("UPDATE resident_account_requests SET status='Approved',resident_id=?,reviewed_by=?,verification_note=?,password_hash=NULL,reviewed_at=NOW() WHERE id=?");
        $stmt->execute([$residentId,$staffId,trim($note),$requestId]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
