<?php
namespace Sysmon;

/** Every metric the agent can produce, with display unit and label. */
class Catalog
{
    public const METRICS = [
        'cpu.load'            => ['%',     'CPU load'],
        'cpu.iowait'          => ['%',     'CPU I/O wait'],
        'load.avg'            => ['',      'Load average (instance 1m/5m/15m)'],
        'mem.used_pct'        => ['%',     'Memory used'],
        'mem.available_bytes' => ['B',     'Memory available'],
        'swap.used_pct'       => ['%',     'Swap used'],
        'disk.used_pct'       => ['%',     'Disk space used'],
        'disk.free_bytes'     => ['B',     'Disk space free'],
        'disk.read_bps'       => ['B/s',   'Disk read rate'],
        'disk.write_bps'      => ['B/s',   'Disk write rate'],
        'net.rx_bps'          => ['B/s',   'Network receive rate'],
        'net.tx_bps'          => ['B/s',   'Network transmit rate'],
        'net.errors_ps'       => ['/s',    'Network errors and drops'],
        'temp.c'              => ['°C',    'Temperature'],
        'fan.rpm'             => ['rpm',   'Fan speed'],
        'uptime.s'            => ['s',     'Uptime'],
    ];

    public static function unit(string $metric): string
    {
        return self::METRICS[$metric][0] ?? '';
    }
}
