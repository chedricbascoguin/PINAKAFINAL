<?php
/**
 * Server-rendered equivalent of DeviceIndicator.tsx.
 * Add `?device=ios`, `?device=android`, or `?device=desktop` to override
 * automatic user-agent detection while testing.
 */
$agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$device = $_GET['device'] ?? (preg_match('/iPhone|iPad|iPod/i', $agent) ? 'ios' : (preg_match('/Android/i', $agent) ? 'android' : 'desktop'));
$devices = [
    'ios' => ['label' => 'iOS Style', 'icon' => '▯', 'color' => 'from-blue-500 to-purple-500'],
    'android' => ['label' => 'Android Style', 'icon' => '▯', 'color' => 'from-green-500 to-emerald-500'],
    'desktop' => ['label' => 'Desktop Style', 'icon' => '▣', 'color' => 'from-gray-600 to-gray-700'],
];
if (!isset($devices[$device])) $device = 'desktop';
$info = $devices[$device];
?>
<div class="pointer-events-none fixed right-4 top-4 z-[9999]">
  <div class="flex items-center gap-2 rounded-full bg-gradient-to-r <?= $info['color'] ?> px-3 py-2 text-xs font-semibold text-white shadow-lg">
    <span aria-hidden="true"><?= $info['icon'] ?></span>
    <span><?= htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8') ?></span>
  </div>
</div>
