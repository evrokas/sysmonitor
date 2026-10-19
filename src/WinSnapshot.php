<?php
namespace Sysmon;

/** PowerShell that gathers every Windows metric as one compact JSON document. */
class WinSnapshot
{
    public const SCRIPT = <<<'PS'
$ErrorActionPreference='SilentlyContinue'
$r=[ordered]@{}
$r.cpu=(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average
$os=Get-CimInstance Win32_OperatingSystem
$r.os=@{tot=$os.TotalVisibleMemorySize;free=$os.FreePhysicalMemory;vtot=$os.TotalVirtualMemorySize;vfree=$os.FreeVirtualMemory}
$r.up=[int]((Get-Date)-$os.LastBootUpTime).TotalSeconds
$r.disks=@(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object {@{id=$_.DeviceID;size=$_.Size;free=$_.FreeSpace}})
$r.net=@(Get-NetAdapterStatistics | ForEach-Object {@{name=$_.Name;rx=$_.ReceivedBytes;tx=$_.SentBytes;err=($_.ReceivedPacketErrors+$_.OutboundPacketErrors)}})
$r.diskio=@(Get-CimInstance Win32_PerfFormattedData_PerfDisk_PhysicalDisk | Where-Object {$_.Name -ne '_Total'} | ForEach-Object {@{name=$_.Name;r=$_.DiskReadBytesPersec;w=$_.DiskWriteBytesPersec}})
$r.temps=@(Get-CimInstance -Namespace root/wmi -ClassName MSAcpi_ThermalZoneTemperature | ForEach-Object {@{name=$_.InstanceName;c=[math]::Round($_.CurrentTemperature/10-273.15,1)}})
$r | ConvertTo-Json -Depth 4 -Compress
PS;
}
