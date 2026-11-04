<?php
function checkPwa(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
require_once $root . '/includes/project-paths.php';
ob_start();
require $root . '/pages/manifest.php';
$manifest = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
checkPwa(!empty($manifest['name']), 'The app needs a name');
checkPwa($manifest['display'] === 'standalone', 'The app must open standalone');
checkPwa(is_file(rhuProjectPagePath(pathinfo($manifest['start_url'], PATHINFO_FILENAME))), 'The launch page must exist');
foreach ([192, 512] as $size) {
    $icons = array_filter($manifest['icons'], fn($icon) => $icon['sizes'] === "{$size}x{$size}" && in_array('any', explode(' ', $icon['purpose'] ?? 'any'), true));
    checkPwa(count($icons) > 0, "Missing install icon at $size pixels");
    foreach ($icons as $icon) {
        $image = getimagesize($root . '/' . $icon['src']);
        checkPwa($image && $image[0] === $size && $image[1] === $size && $image['mime'] === $icon['type'], "Invalid icon: {$icon['src']}");
    }
}
foreach (['LandingPage.php', 'ResidentLogin.php', 'ResidentRegistration.php', 'ResidentDashboard.php'] as $page) {
    $document = new DOMDocument();
    // The dashboard also emits a separate certificate document before its main page.
    $source = file_get_contents(rhuProjectPagePath(pathinfo($page, PATHINFO_FILENAME)));
    $headStart = strrpos($source, '<head>');
    checkPwa($headStart !== false, "$page must have a head");
    $headEnd = strpos($source, '</head>', $headStart);
    checkPwa($headEnd !== false, "$page must close its head");
    @$document->loadHTML('<html>' . substr($source, $headStart, $headEnd + 7 - $headStart) . '<body></body></html>');
    $xpath = new DOMXPath($document);
    $links = $xpath->query('//head/link[@rel="manifest"]');
    checkPwa($links->length === 1, "$page must expose one manifest in its head");
    checkPwa(strtok($links->item(0)->getAttribute('href'), '?') === 'manifest.php', "$page must use the hosting-compatible manifest");
    checkPwa($xpath->query('//head/script[starts-with(@src, "assets/js/pwa-install.js")]')->length === 1, "$page must register the service worker");
}
checkPwa(is_file($root . '/assets/pwa/sw.js'), 'The service worker must exist');
echo "PWA manifest, icon dimensions, and all resident entry pages passed.\n";
