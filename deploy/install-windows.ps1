# Registers a Task Scheduler job that runs the collector every minute as SYSTEM.
# Run from an elevated PowerShell. Requires php.exe (zip from windows.php.net) with extension=pdo_sqlite enabled.
param([Parameter(Mandatory)][string]$Php, [string]$Root = (Split-Path -Parent $PSScriptRoot))
$action  = New-ScheduledTaskAction -Execute $Php -Argument "`"$Root\bin\tick.php`"" -WorkingDirectory $Root
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$set     = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'sysmonitor' -Action $action -Trigger $trigger -Settings $set -User 'SYSTEM' -RunLevel Highest -Force
Write-Host "Registered. Test with: & '$Php' '$Root\bin\tick.php' -v"
