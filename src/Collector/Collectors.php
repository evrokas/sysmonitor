<?php
namespace Sysmon\Collector;

use Sysmon\Ctx;

/** The collector sets for Linux and Windows. */
class Collectors
{
    /** @return Collector[] */
    public static function forCtx(Ctx $ctx): array
    {
        return $ctx->os === 'Windows' ? self::windows() : self::linux();
    }

    /** Per-second rate from a monotonically increasing counter, or null if unusable. */
    public static function rate($cur, $prev, int $dt): ?float
    {
        if ($prev === null || $dt <= 0 || $cur < $prev) {
            return null;   // first run, no time passed, or counter reset/wrap
        }
        return ($cur - $prev) / $dt;
    }

    /**
     * Rate samples for a table of counters. $rows: instance => [metric => counter].
     * State keeps the counters and the time they were read.
     */
    private static function rates(array $rows, ?array $prev, int $now): array
    {
        $samples = [];
        $dt = $prev ? $now - (int)($prev['t'] ?? 0) : 0;
        foreach ($rows as $inst => $counters) {
            foreach ($counters as $metric => $val) {
                $r = self::rate($val, $prev['c'][$inst][$metric] ?? null, $dt);
                if ($r !== null) {
                    $samples[] = [$metric, (string)$inst, $r];
                }
            }
        }
        return ['samples' => $samples, 'state' => ['t' => $now, 'c' => $rows]];
    }

    private static function none(): array
    {
        return ['samples' => [], 'state' => []];
    }

    /** Win snapshot sections may be a list, a single object, or null (PowerShell 5.1 quirk). */
    private static function lst($v): array
    {
        if (!is_array($v)) {
            return [];
        }
        return isset($v[0]) || !$v ? $v : [$v];
    }

    public static function linux(): array
    {
        return [
            new FnCollector('cpu', ['cpu.load', 'cpu.iowait'], function (Ctx $c, ?array $prev, int $now) {
                $s = $c->read($c->proc . '/stat');
                if ($s === null || !preg_match('/^cpu\s+(.+)$/m', $s, $m)) {
                    return self::none();
                }
                $f = array_map('intval', preg_split('/\s+/', trim($m[1])));
                $total = array_sum(array_slice($f, 0, 8));      // user..steal (guest is already in user)
                $idle = ($f[3] ?? 0) + ($f[4] ?? 0);
                $iow = $f[4] ?? 0;
                $out = [];
                if ($prev && ($dt = $total - $prev['total']) > 0) {
                    $out[] = ['cpu.load', 'total', max(0, 100 * ($dt - ($idle - $prev['idle'])) / $dt)];
                    $out[] = ['cpu.iowait', 'total', max(0, 100 * ($iow - $prev['iow']) / $dt)];
                }
                return ['samples' => $out, 'state' => ['total' => $total, 'idle' => $idle, 'iow' => $iow]];
            }),

            new FnCollector('load', ['load.avg'], function (Ctx $c) {
                $s = $c->read($c->proc . '/loadavg');
                $p = $s ? preg_split('/\s+/', trim($s)) : [];
                if (count($p) < 3) {
                    return self::none();
                }
                return ['samples' => [['load.avg', '1m', (float)$p[0]], ['load.avg', '5m', (float)$p[1]], ['load.avg', '15m', (float)$p[2]]], 'state' => []];
            }),

            new FnCollector('mem', ['mem.used_pct', 'mem.available_bytes', 'swap.used_pct'], function (Ctx $c) {
                $s = $c->read($c->proc . '/meminfo');
                if ($s === null) {
                    return self::none();
                }
                $m = [];
                if (preg_match_all('/^(\w+):\s+(\d+)\s*kB/m', $s, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $r) {
                        $m[$r[1]] = (float)$r[2] * 1024;
                    }
                }
                $out = [];
                if (!empty($m['MemTotal']) && isset($m['MemAvailable'])) {
                    $out[] = ['mem.used_pct', '', 100 * (1 - $m['MemAvailable'] / $m['MemTotal'])];
                    $out[] = ['mem.available_bytes', '', $m['MemAvailable']];
                }
                if (!empty($m['SwapTotal']) && isset($m['SwapFree'])) {
                    $out[] = ['swap.used_pct', '', 100 * (1 - $m['SwapFree'] / $m['SwapTotal'])];
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('disk', ['disk.used_pct', 'disk.free_bytes'], function (Ctx $c) {
                $s = $c->read($c->proc . '/mounts');
                $out = [];
                $seen = [];
                $fsRe = $c->cfg->get('disk_fs_types');
                foreach (explode("\n", (string)$s) as $line) {
                    $p = explode(' ', $line);
                    if (count($p) < 3 || !preg_match($fsRe, $p[2]) || strpos($p[0], '/dev/loop') === 0 || isset($seen[$p[0]])) {
                        continue;
                    }
                    $mount = preg_replace_callback('/\\\\([0-7]{3})/', fn($m) => chr(octdec($m[1])), $p[1]);
                    if (preg_match('#^/(snap|var/lib/docker)(/|$)#', $mount) || ($sp = $c->space($mount)) === null) {
                        continue;
                    }
                    $seen[$p[0]] = true;
                    $out[] = ['disk.used_pct', $mount, 100 * ($sp[0] - $sp[1]) / $sp[0]];
                    $out[] = ['disk.free_bytes', $mount, $sp[1]];
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('diskio', ['disk.read_bps', 'disk.write_bps'], function (Ctx $c, ?array $prev, int $now) {
                $s = $c->read($c->proc . '/diskstats');
                if ($s === null) {
                    return self::none();
                }
                $rows = [];
                foreach (explode("\n", $s) as $line) {
                    $p = preg_split('/\s+/', trim($line));
                    if (count($p) < 10 || !preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+|nvme\d+n\d+|mmcblk\d+|md\d+)$/', $p[2])) {
                        continue;
                    }
                    $rows[$p[2]] = ['disk.read_bps' => (int)$p[5] * 512, 'disk.write_bps' => (int)$p[9] * 512];
                }
                return self::rates($rows, $prev, $now);
            }),

            new FnCollector('net', ['net.rx_bps', 'net.tx_bps', 'net.errors_ps'], function (Ctx $c, ?array $prev, int $now) {
                $s = $c->read($c->proc . '/net/dev');
                if ($s === null) {
                    return self::none();
                }
                $rows = [];
                $excl = $c->cfg->get('net_exclude');
                foreach (explode("\n", $s) as $line) {
                    if (!preg_match('/^\s*([^:\s]+):\s*(.+)$/', $line, $m) || preg_match($excl, $m[1])) {
                        continue;
                    }
                    $f = preg_split('/\s+/', trim($m[2]));
                    if (count($f) < 12) {
                        continue;
                    }
                    $rows[$m[1]] = [
                        'net.rx_bps'    => (int)$f[0],
                        'net.tx_bps'    => (int)$f[8],
                        'net.errors_ps' => (int)$f[2] + (int)$f[3] + (int)$f[10] + (int)$f[11],
                    ];
                }
                return self::rates($rows, $prev, $now);
            }),

            new FnCollector('temp', ['temp.c'], function (Ctx $c) {
                $out = [];
                foreach ($c->glob($c->sys . '/class/hwmon/hwmon*') as $d) {
                    $name = trim((string)$c->read("$d/name")) ?: basename($d);
                    foreach ($c->glob("$d/temp*_input") as $f) {
                        $n = preg_replace('/_input$/', '', basename($f));
                        $v = $c->read($f);
                        $t = is_numeric(trim((string)$v)) ? (float)$v / 1000 : null;
                        if ($t === null || $t < -40 || $t > 150) {
                            continue;
                        }
                        $label = trim((string)$c->read("$d/{$n}_label")) ?: $n;
                        $out[] = ['temp.c', "$name/$label", $t];
                    }
                }
                if (!$out) {   // boards without hwmon (Raspberry Pi etc.)
                    foreach ($c->glob($c->sys . '/class/thermal/thermal_zone*') as $d) {
                        $v = $c->read("$d/temp");
                        if (is_numeric(trim((string)$v))) {
                            $out[] = ['temp.c', 'zone/' . (trim((string)$c->read("$d/type")) ?: basename($d)), (float)$v / 1000];
                        }
                    }
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('fan', ['fan.rpm'], function (Ctx $c) {
                $out = [];
                foreach ($c->glob($c->sys . '/class/hwmon/hwmon*') as $d) {
                    $name = trim((string)$c->read("$d/name")) ?: basename($d);
                    foreach ($c->glob("$d/fan*_input") as $f) {
                        $n = preg_replace('/_input$/', '', basename($f));
                        $v = $c->read($f);
                        if (is_numeric(trim((string)$v))) {
                            $label = trim((string)$c->read("$d/{$n}_label")) ?: $n;
                            $out[] = ['fan.rpm', "$name/$label", (float)$v];
                        }
                    }
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('uptime', ['uptime.s'], function (Ctx $c) {
                $s = $c->read($c->proc . '/uptime');
                return ['samples' => $s ? [['uptime.s', '', (float)strtok($s, ' ')]] : [], 'state' => []];
            }),
        ];
    }

    public static function windows(): array
    {
        return [
            new FnCollector('cpu', ['cpu.load'], function (Ctx $c) {
                $w = $c->winSnapshot();
                return ['samples' => isset($w['cpu']) && is_numeric($w['cpu']) ? [['cpu.load', 'total', (float)$w['cpu']]] : [], 'state' => []];
            }),

            new FnCollector('mem', ['mem.used_pct', 'mem.available_bytes', 'swap.used_pct'], function (Ctx $c) {
                $o = ($c->winSnapshot())['os'] ?? null;
                $out = [];
                if ($o && !empty($o['tot']) && isset($o['free'])) {
                    $out[] = ['mem.used_pct', '', 100 * (1 - $o['free'] / $o['tot'])];
                    $out[] = ['mem.available_bytes', '', (float)$o['free'] * 1024];
                }
                // Windows "virtual" memory = RAM + page file; swap use is the page-file share of it.
                if ($o && !empty($o['vtot']) && !empty($o['tot']) && $o['vtot'] > $o['tot'] && isset($o['vfree'])) {
                    $swapTot = $o['vtot'] - $o['tot'];
                    $swapFree = max(0, $o['vfree'] - $o['free']);
                    $out[] = ['swap.used_pct', '', 100 * (1 - min(1, $swapFree / $swapTot))];
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('disk', ['disk.used_pct', 'disk.free_bytes'], function (Ctx $c) {
                $out = [];
                foreach (self::lst(($c->winSnapshot())['disks'] ?? null) as $d) {
                    if (!empty($d['size']) && isset($d['free'], $d['id'])) {
                        $out[] = ['disk.used_pct', $d['id'], 100 * (1 - $d['free'] / $d['size'])];
                        $out[] = ['disk.free_bytes', $d['id'], (float)$d['free']];
                    }
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('diskio', ['disk.read_bps', 'disk.write_bps'], function (Ctx $c) {
                $out = [];   // the performance-counter class already reports per-second rates
                foreach (self::lst(($c->winSnapshot())['diskio'] ?? null) as $d) {
                    if (isset($d['name'], $d['r'], $d['w'])) {
                        $out[] = ['disk.read_bps', $d['name'], (float)$d['r']];
                        $out[] = ['disk.write_bps', $d['name'], (float)$d['w']];
                    }
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('net', ['net.rx_bps', 'net.tx_bps', 'net.errors_ps'], function (Ctx $c, ?array $prev, int $now) {
                $rows = [];
                $excl = $c->cfg->get('net_exclude');
                foreach (self::lst(($c->winSnapshot())['net'] ?? null) as $n) {
                    if (isset($n['name'], $n['rx'], $n['tx']) && !preg_match($excl, $n['name'])) {
                        $rows[$n['name']] = ['net.rx_bps' => (int)$n['rx'], 'net.tx_bps' => (int)$n['tx'], 'net.errors_ps' => (int)($n['err'] ?? 0)];
                    }
                }
                return self::rates($rows, $prev, $now);
            }),

            new FnCollector('temp', ['temp.c'], function (Ctx $c) {
                $out = [];
                foreach (self::lst(($c->winSnapshot())['temps'] ?? null) as $t) {
                    if (isset($t['name'], $t['c']) && $t['c'] > -40 && $t['c'] < 150) {
                        $out[] = ['temp.c', (string)$t['name'], (float)$t['c']];
                    }
                }
                return ['samples' => $out, 'state' => []];
            }),

            new FnCollector('uptime', ['uptime.s'], function (Ctx $c) {
                $u = ($c->winSnapshot())['up'] ?? null;
                return ['samples' => is_numeric($u) ? [['uptime.s', '', (float)$u]] : [], 'state' => []];
            }),
        ];
    }
}
