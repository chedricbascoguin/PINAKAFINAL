<?php
function rhuValidateAppointmentTime(string $time): void
{
    if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $time)) {
        throw new InvalidArgumentException('Please choose a valid appointment time.');
    }
    $normalized = strlen($time) === 5 ? $time . ':00' : $time;
    if ($normalized < '06:00:00' || $normalized > '18:00:00') {
        throw new InvalidArgumentException('RHU appointments are available only from 6:00 AM to 6:00 PM. Please choose a time within clinic hours.');
    }
}
