<?php
require_once dirname(__DIR__) . '/db.php';

echo "<h1>Admin Account Check</h1>";
echo "<p><strong>PDO Connection:</strong> " . ($pdo ? "✓ Connected" : "✗ Failed (null)") . "</p>";

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM health_workers WHERE status = 'Active' AND (UPPER(position_title) IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF') OR UPPER(position_title) LIKE '%MUNICIPAL HEALTH OFFICER%' OR UPPER(role) = 'ADMIN')");
        $count = (int) ($stmt ? $stmt->fetchColumn() : 0);
        echo "<p><strong>Active Admin Count:</strong> $count</p>";

        if ($count === 0) {
            echo "<p style='color: green;'><strong>✓ Registration should appear!</strong></p>";
        } else {
            echo "<p style='color: red;'><strong>✗ Admin already exists - registration will be blocked</strong></p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color: red;'><strong>Cannot check - no database connection</strong></p>";
}
?>
<hr>
<p><a href="RHUAdminLogin.php">← Back to Login</a></p>
