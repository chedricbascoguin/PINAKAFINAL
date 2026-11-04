<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/includes/staff-session-lock.php';
require_once dirname(__DIR__) . '/includes/inventory-control.php';
if (!inventoryIsAdmin() || rhuLockStaffId() <= 0) { http_response_code(403); exit('Administrator access required.'); }
require_once dirname(__DIR__) . '/db.php';
rhuEnforceStaffScreenLock();
require_once dirname(__DIR__) . '/includes/portal.php';
require_once dirname(__DIR__) . '/includes/resident-account-linking.php';
residentLinkEnsure($pdo);
$embedded = ($_GET['embedded'] ?? '') === '1';
$message=''; $error='';
function linkEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!portalVerifyCsrf()) throw new RuntimeException('Form expired. Refresh and try again.');
        $note=trim((string)($_POST['verification_note'] ?? ''));
        if ($note==='') throw new RuntimeException('Enter the identity verification or rejection reason.');
        if (($_POST['action'] ?? '') === 'approve') {
            residentLinkApprove($pdo,(int)($_POST['request_id'] ?? 0),(int)($_POST['resident_id'] ?? 0),rhuLockStaffId(),$note);
            $message='Account linked to the existing patient ID. The resident can now sign in and see their records.';
        } elseif (($_POST['action'] ?? '') === 'reject') {
            $stmt=$pdo->prepare("UPDATE resident_account_requests SET status='Rejected',password_hash=NULL,reviewed_by=?,verification_note=?,reviewed_at=NOW() WHERE id=? AND status='Pending'");
            $stmt->execute([rhuLockStaffId(),$note,(int)($_POST['request_id'] ?? 0)]);
            $message='Request rejected. Patient records were preserved.';
        } else throw new RuntimeException('Invalid action.');
    } catch (Throwable $exception) { $error=$exception->getMessage(); }
}
$requests=$pdo->query("SELECT * FROM resident_account_requests WHERE status='Pending' ORDER BY created_at,id")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resident account verification</title><script src="assets/js/staff-session-lock.js" defer></script><style>body{font:14px system-ui;background:#f0fdfa;color:#163c36;margin:0}main{max-width:1000px;margin:auto;padding:30px}article{background:white;border:1px solid #cbded7;border-radius:14px;padding:22px;margin:20px 0}label{display:block;margin:14px 0;font-weight:600}input,select,textarea,button{font:inherit;padding:10px;border:1px solid #aac8bc;border-radius:7px}select,textarea{width:100%;box-sizing:border-box}button{background:#0f766e;color:white;cursor:pointer}.error{color:#b91c1c}.success{color:#047857}<?php if ($embedded): ?>body{background:#fff}main{max-width:none;padding:20px}article{margin:16px 0}<?php endif; ?></style></head><body><main><?php if (!$embedded): ?><a href="RHUAdminDashboard.php">Back to dashboard</a><h1>Resident account verification</h1><?php endif; ?><p>Verify the resident in person using their patient ID, identification and existing RHU details before approving. Names and birthdays alone are insufficient. Approval attaches the login to the selected existing patient ID.</p>
<?php if ($message): ?><p role="status" class="success"><?= linkEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p role="alert" class="error"><?= linkEscape($error) ?></p><?php endif; ?>
<?php if (!$requests): ?><article>No pending account-link requests.</article><?php endif; ?>
<?php foreach ($requests as $request):
$stmt=$pdo->prepare('SELECT id,first_name,middle_name,last_name,birthdate,address,contact_number,password_hash FROM residents WHERE LOWER(TRIM(first_name))=LOWER(?) AND LOWER(TRIM(last_name))=LOWER(?) AND birthdate=? ORDER BY id');
$stmt->execute([$request['first_name'],$request['last_name'],$request['birthdate']]); $matches=$stmt->fetchAll(PDO::FETCH_ASSOC);
?><article><h2><?= linkEscape($request['first_name'].' '.$request['last_name']) ?></h2><p>Requested email: <?= linkEscape($request['email']) ?><br>Birth date: <?= linkEscape($request['birthdate']) ?><br>Phone: <?= linkEscape($request['phone']) ?><br>Barangay: <?= linkEscape($request['barangay']) ?></p><form method="post"><input type="hidden" name="csrf_token" value="<?= linkEscape(portalCsrfToken()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><label>Existing patient record<select name="resident_id"><option value="">Select verified patient ID</option><?php foreach ($matches as $match): ?><option value="<?= (int)$match['id'] ?>" <?= !empty($match['password_hash']) ? 'disabled' : '' ?>><?= linkEscape('Patient #'.$match['id'].' - '.$match['first_name'].' '.$match['middle_name'].' '.$match['last_name'].' | '.$match['address'].(!empty($match['password_hash']) ? ' (already has account)' : '')) ?></option><?php endforeach; ?></select></label><label>Verification details / rejection reason<textarea name="verification_note" required maxlength="1000" placeholder="Document the identity check performed; do not enter identification numbers."></textarea></label><button name="action" value="approve">Approve and link existing record</button> <button name="action" value="reject">Reject request</button></form></article><?php endforeach; ?></main></body></html>
