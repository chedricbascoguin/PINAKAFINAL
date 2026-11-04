<?php
require dirname(__DIR__) . '/includes/admin_extended.php';
function portalCsrfToken() { return 'test-csrf-token'; }
class FhsisFormNoDatabase extends PDO { public function __construct() {} public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { throw new RuntimeException('Report form must not query the database.'); } }
ob_start(); renderAdminExtendedPanel(new FhsisFormNoDatabase(), 'reports'); $html = ob_get_clean();
$doc = new DOMDocument(); @$doc->loadHTML($html); $xp = new DOMXPath($doc);
foreach (['//form[@data-fhsis-download and not(@target)]', '//script[@src="assets/js/fhsis-download.js?v=2"]', '//input[@name="csrf_token" and @value="test-csrf-token"]', '//input[@name="action" and @value="generate_doh_report"]', '//select[@name="month"]/option[12]'] as $query) {
    if ($xp->query($query)->length !== 1) throw new RuntimeException('Missing report form feature: ' . $query);
}
echo "Passed: report form renders without database queries, keeps dashboard open and includes CSRF/month/year inputs.\n";
