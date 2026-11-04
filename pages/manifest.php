<?php
// Serve the manifest through PHP on hosts that do not serve .webmanifest files.
$manifest = file_get_contents(dirname(__DIR__) . '/assets/pwa/manifest.webmanifest');
if (isset($_GET['portal']) && $_GET['portal'] === 'staff') {
    $manifest = file_get_contents(dirname(__DIR__) . '/assets/pwa/staff.webmanifest');
}
if ($manifest === false || json_decode($manifest, true) === null) {
    http_response_code(500);
    exit;
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo $manifest;
