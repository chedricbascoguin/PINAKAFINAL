<?php
function rhuEnv($key, $default = null) { return $default; }
require_once __DIR__ . '/../rhu_chat_schedule.php';

$settings = ['rhu_weekly_hours' => '{"monday":[["08:00","12:00"],["13:00","17:00"]],"sunday":[]}'];
$cases = [
    [$settings, '2026-10-05 09:00', 'open'],
    [$settings, '2026-10-05 12:00', 'closed'],
    [$settings, '2026-10-05 13:00', 'open'],
    [$settings, '2026-10-05 17:00', 'closed'],
    [$settings, '2026-10-04 09:00', 'closed'],
    [$settings, '2026-10-06 09:00', 'unknown'],
    [[], '2026-10-05 09:00', 'unknown'],
    [['rhu_weekly_hours' => '{"monday":[["25:00","26:00"]]}'], '2026-10-05 09:00', 'unknown'],
    [$settings + ['rhu_schedule_exceptions' => '{"2026-10-05":[]}'], '2026-10-05 09:00', 'closed'],
];
foreach ($cases as [$config, $time, $expected]) {
    $actual = rhuChatOpeningStatus($config, new DateTimeImmutable($time, new DateTimeZone('Asia/Manila')));
    if ($actual['status_now'] !== $expected) throw new RuntimeException($time . ': expected ' . $expected);
}
echo "9 schedule checks passed.\n";
