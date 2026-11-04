<?php
require dirname(__DIR__) . '/includes/family-planning-follow-up.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE consultations(id INTEGER PRIMARY KEY, resident_id INTEGER, consultation_date TEXT, chief_complaint TEXT, diagnosis TEXT)');
$insert = $pdo->prepare('INSERT INTO consultations VALUES(?,?,?,?,?)');
foreach ([[1,64,'2026-10-28','Family Planning - New Acceptor','Family Planning Counseling'],
 [2,64,'2026-11-01','Family Planning - Continuing User','Family Planning Counseling'],
 [3,65,'2026-11-01','Family Planning - Continuing User','Family Planning Counseling'],
 [4,64,'2026-12-01','Family Planning - Appointment','Pending OPD Triage']] as $row) $insert->execute($row);
function rhuAppointmentColumnExists(PDO $pdo, string $table, string $column): bool { return true; }
$ids = $pdo->query('SELECT id FROM consultations WHERE 1=1' . fpReminderGuardSql($pdo) . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if ($ids !== [2,3,4]) throw new RuntimeException('Old follow-up not suppressed, latest follow-up suppressed, or another resident affected.');
$insert->execute([5,64,'2026-11-01','Family Planning - Continuing User','Family Planning Counseling']);
$ids = $pdo->query('SELECT id FROM consultations WHERE 1=1' . fpReminderGuardSql($pdo) . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if ($ids !== [3,4,5]) throw new RuntimeException('Same-day resupply did not replace the earlier follow-up.');
echo "Family planning follow-up checks passed: return supply, latest visit, other residents, pending bookings and same-day order.\n";
