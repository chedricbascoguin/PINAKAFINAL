<?php
require dirname(__DIR__) . '/includes/appointment-hours.php';
foreach (['06:00','06:00:00','12:00','17:59:59','18:00','18:00:00'] as $time) rhuValidateAppointmentTime($time);
foreach (['00:00','05:59','05:59:59','18:00:01','18:01','23:59','24:00','06:60','6:00','','06:00 extra'] as $time) {
    try { rhuValidateAppointmentTime($time); } catch (InvalidArgumentException $error) { continue; }
    throw new RuntimeException('Invalid or out-of-hours appointment accepted: '.$time);
}
echo "Appointment hours checks passed: opening/closing boundaries, outside hours and malformed times.\n";
