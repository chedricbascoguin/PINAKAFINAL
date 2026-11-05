param([switch]$RunAsSystem)
$ErrorActionPreference = 'Stop'
$taskName = 'ResiHUnity Appointment Expiry'
$phpPath = 'C:\xampp\php\php-win.exe'
# php-win runs reminders without opening a console in the signed-in session.
if (-not (Test-Path -LiteralPath $phpPath)) {
    throw "Windowless PHP executable not found: $phpPath"
}
$projectRoot = Split-Path -Parent $PSScriptRoot
$jobPath = Join-Path $PSScriptRoot 'appointment-expiry-job.php'
if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    throw "Task already exists; inspect it before making changes."
}
$action = New-ScheduledTaskAction -Execute $phpPath -Argument ('"' + $jobPath + '"') -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 15)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 14)
if ($RunAsSystem) {
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Limited
} else {
    # Runs while this Windows account is signed in, even with the browser closed.
    $currentAccount = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $principal = New-ScheduledTaskPrincipal -UserId $currentAccount -LogonType Interactive -RunLevel Limited
}
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Notify residents of expired appointments every 15 minutes, independently of browser activity.'
