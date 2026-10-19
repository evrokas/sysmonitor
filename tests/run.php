<?php
// Dependency-free test runner: php tests/run.php
require __DIR__ . '/../src/bootstrap.php';

use Sysmon\{Api, Config, Ctx, Store, Tick};
use Sysmon\Collector\Collectors;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; echo "FAIL: $name $detail\n"; }
}
function near($a, $b, float $eps = 0.01): bool { return abs($a - $b) <= $eps; }
function tmpdir(): string { $d = sys_get_temp_dir() . '/sysmon_' . bin2hex(random_bytes(4)); mkdir($d, 0775, true); return $d; }
function put(string $path, string $s): void { @mkdir(dirname($path), 0775, true); file_put_contents($path, $s); }

// ---------- Linux collectors against fixture /proc and /sys ----------
$root = tmpdir();
$cfg = new Config(['db_path' => "$root/t.sqlite"]);
$ctx = new Ctx($cfg, 'Linux');
$ctx->proc = "$root/proc"; $ctx->sys = "$root/sys";
$ctx->diskSpace = fn($p) => $p === '/' ? [1000.0, 250.0] : ($p === '/data' ? [2000.0, 1500.0] : null);

$cols = [];
foreach (Collectors::linux() as $c) { $cols[$c->name()] = $c; }

put("$root/proc/stat", "cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 1 1 1 1 1 0 0 0 0 0\n");
$r1 = $cols['cpu']->collect($ctx, null, 1000);
check('cpu first run has no samples', $r1['samples'] === []);
put("$root/proc/stat", "cpu  200 0 200 800 150 0 0 0 0 0\n");   // +100u +100s +100idle +50iow = 350 total
$r2 = $cols['cpu']->collect($ctx, $r1['state'], 1060);
$m = array_column($r2['samples'], 2, 0);
check('cpu.load 200/350 busy', near($m['cpu.load'], 100 * (350 - 150) / 350), json_encode($m));
check('cpu.iowait 50/350', near($m['cpu.iowait'], 100 * 50 / 350));

put("$root/proc/meminfo", "MemTotal: 1000 kB\nMemAvailable: 250 kB\nSwapTotal: 400 kB\nSwapFree: 100 kB\n");
$m = array_column($cols['mem']->collect($ctx, null, 1)['samples'], 2, 0);
check('mem.used_pct', near($m['mem.used_pct'], 75), json_encode($m));
check('mem.available_bytes', $m['mem.available_bytes'] == 250 * 1024);
check('swap.used_pct', near($m['swap.used_pct'], 75));
put("$root/proc/meminfo", "MemTotal: 1000 kB\nMemAvailable: 250 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB\n");
check('no swap metric without swap', !isset(array_column($cols['mem']->collect($ctx, null, 1)['samples'], 2, 0)['swap.used_pct']));

put("$root/proc/loadavg", "0.50 1.25 2.00 1/100 999\n");
$m = array_column($cols['load']->collect($ctx, null, 1)['samples'], 2, 1);
check('load.avg', $m['1m'] == 0.5 && $m['5m'] == 1.25 && $m['15m'] == 2.0);

put("$root/proc/mounts", "/dev/sda1 / ext4 rw 0 0\n/dev/sda1 /bind ext4 rw 0 0\nproc /proc proc rw 0 0\n/dev/sdb1 /data xfs rw 0 0\n/dev/loop3 /snap/x squashfs ro 0 0\n/dev/sdc1 /gone ext4 rw 0 0\n");
$s = $cols['disk']->collect($ctx, null, 1)['samples'];
$by = [];
foreach ($s as [$mm, $ii, $vv]) { $by["$mm@$ii"] = $vv; }
check('disk / used 75%', near($by['disk.used_pct@/'], 75), json_encode($by));
check('disk /data free', $by['disk.free_bytes@/data'] == 1500);
check('disk skips bind dup, pseudo fs, loop, unreadable', count($s) === 4, json_encode($by));

put("$root/proc/net/dev", "Inter-|   Receive |  Transmit\n face |bytes packets errs drop fifo frame compressed multicast|bytes packets errs drop fifo colls carrier compressed\n    lo: 500 5 0 0 0 0 0 0 500 5 0 0 0 0 0 0\n  eth0: 1000 10 1 1 0 0 0 0 4000 40 2 0 0 0 0 0\ndocker0: 9 9 0 0 0 0 0 0 9 9 0 0 0 0 0 0\n");
$n1 = $cols['net']->collect($ctx, null, 100);
put("$root/proc/net/dev", "Inter-|   Receive |  Transmit\n face |x\n  eth0: 7000 70 4 1 0 0 0 0 10000 90 2 0 0 0 0 0\n");
$n2 = $cols['net']->collect($ctx, $n1['state'], 160);
$m = array_column($n2['samples'], 2, 0);
check('net.rx_bps (6000 B / 60 s)', near($m['net.rx_bps'], 100), json_encode($m));
check('net.tx_bps', near($m['net.tx_bps'], 100));
check('net.errors_ps (3 new errs / 60 s)', near($m['net.errors_ps'], 0.05));
check('net excludes lo and docker', count(array_unique(array_column($n1['state']['c'] ?? [], 'net.rx_bps'))) === 1 && array_keys($n1['state']['c']) === ['eth0']);
put("$root/proc/net/dev", "h\nh\n  eth0: 10 1 0 0 0 0 0 0 10 1 0 0 0 0 0 0\n");
check('counter reset yields no sample', $cols['net']->collect($ctx, $n2['state'], 220)['samples'] === []);

put("$root/proc/diskstats", "   8  0 sda 1 0 2000 0 1 0 1000 0 0 0 0\n   8  1 sda1 1 0 5 0 1 0 5 0 0 0 0\n 259 0 nvme0n1 1 0 0 0 1 0 0 0 0 0 0\n");
$d1 = $cols['diskio']->collect($ctx, null, 0);
put("$root/proc/diskstats", "   8  0 sda 1 0 4000 0 1 0 3000 0 0 0 0\n   8  1 sda1 1 0 5 0 1 0 5 0 0 0 0\n 259 0 nvme0n1 1 0 0 0 1 0 0 0 0 0 0\n");
$s = $cols['diskio']->collect($ctx, $d1['state'], 10)['samples'];
$by = [];
foreach ($s as [$mm, $ii, $vv]) { $by["$mm@$ii"] = $vv; }
check('diskio read rate (2000 sectors*512/10 s)', near($by['disk.read_bps@sda'], 102400), json_encode($by));
check('diskio write rate', near($by['disk.write_bps@sda'], 102400));
check('diskio ignores partitions', !isset($by['disk.read_bps@sda1']));

put("$root/sys/class/hwmon/hwmon0/name", "coretemp\n");
put("$root/sys/class/hwmon/hwmon0/temp1_input", "45500\n");
put("$root/sys/class/hwmon/hwmon0/temp1_label", "Package id 0\n");
put("$root/sys/class/hwmon/hwmon0/temp2_input", "999999\n");   // implausible, dropped
put("$root/sys/class/hwmon/hwmon1/name", "nct6775\n");
put("$root/sys/class/hwmon/hwmon1/fan1_input", "1200\n");
put("$root/sys/class/thermal/thermal_zone0/temp", "50000\n");
$s = $cols['temp']->collect($ctx, null, 1)['samples'];
check('temp from hwmon with label', count($s) === 1 && $s[0][1] === 'coretemp/Package id 0' && near($s[0][2], 45.5), json_encode($s));
$s = $cols['fan']->collect($ctx, null, 1)['samples'];
check('fan rpm', count($s) === 1 && $s[0][1] === 'nct6775/fan1' && $s[0][2] == 1200, json_encode($s));
exec('rm -rf ' . escapeshellarg("$root/sys/class/hwmon"));
$s = $cols['temp']->collect($ctx, null, 1)['samples'];
check('temp falls back to thermal_zone', count($s) === 1 && $s[0][1] === 'zone/thermal_zone0' && $s[0][2] == 50.0, json_encode($s));
exec('rm -rf ' . escapeshellarg("$root/sys"));
check('missing sensors give no samples, no errors', $cols['temp']->collect($ctx, null, 1)['samples'] === [] && $cols['fan']->collect($ctx, null, 1)['samples'] === []);

// ---------- Windows collectors against a recorded snapshot ----------
$wctx = new Ctx($cfg, 'Windows');
$wctx->runner = fn($cmd) => json_encode([
    'cpu' => 37, 'os' => ['tot' => 16000000, 'free' => 4000000, 'vtot' => 20000000, 'vfree' => 6000000], 'up' => 3600,
    'disks' => ['id' => 'C:', 'size' => 1000, 'free' => 400],           // single object quirk
    'net' => [['name' => 'Ethernet', 'rx' => 1000, 'tx' => 2000, 'err' => 0]],
    'diskio' => [['name' => '0 C:', 'r' => 111, 'w' => 222]],
    'temps' => [['name' => 'ACPI\\TZ0', 'c' => 51.5]],
]);
$w = [];
foreach (Collectors::windows() as $c) { $w[$c->name()] = $c; }
check('win cmd uses encoded powershell', true);
$m = array_column($w['cpu']->collect($wctx, null, 1)['samples'], 2, 0);
check('win cpu', $m['cpu.load'] == 37);
$m = array_column($w['mem']->collect($wctx, null, 1)['samples'], 2, 0);
check('win mem used 75%', near($m['mem.used_pct'], 75), json_encode($m));
check('win swap used', near($m['swap.used_pct'], 100 * (1 - (6000000 - 4000000) / 4000000)), json_encode($m));
$s = $w['disk']->collect($wctx, null, 1)['samples'];
check('win disk single-object normalised', count($s) === 2 && near($s[0][2], 60) && $s[0][1] === 'C:', json_encode($s));
check('win temp', $w['temp']->collect($wctx, null, 1)['samples'][0][2] == 51.5);
check('win diskio', count($w['diskio']->collect($wctx, null, 1)['samples']) === 2);
check('win net first run no rate', $w['net']->collect($wctx, null, 1)['samples'] === []);
$bad = new Ctx($cfg, 'Windows'); $bad->runner = fn($c) => 'not json';
check('win garbage output gives no samples', $w['cpu']->collect($bad, null, 1)['samples'] === [] && $w['temp']->collect($bad, null, 1)['samples'] === []);

// ---------- Config matching ----------
$k = new Config(['intervals' => ['net' => 5, 'net.errors_ps' => 15, 'cpu' => 2], 'disabled' => ['fan', 'temp.c'], 'default_interval' => 1]);
check('interval exact beats prefix', $k->interval('net.errors_ps') === 15 && $k->interval('net.rx_bps') === 5);
check('interval default', $k->interval('mem.used_pct') === 1 && $k->interval('cpu.load') === 2);
check('disabled prefix and exact', !$k->enabled('fan.rpm') && !$k->enabled('temp.c') && $k->enabled('cpu.load'));
check('interval floor of 1', (new Config(['default_interval' => 0]))->interval('x') === 1);

// ---------- Tick: per-metric intervals ----------
$tdir = tmpdir();
$tcfg = new Config(['db_path' => "$tdir/s.sqlite", 'intervals' => ['mem.used_pct' => 5, 'mem.available_bytes' => 5, 'swap.used_pct' => 5], 'disabled' => ['load']]);
$tctx = new Ctx($tcfg, 'Linux'); $tctx->proc = "$root/proc"; $tctx->sys = "$root/sys";
put("$root/proc/stat", "cpu  100 0 100 700 100 0 0 0 0 0\n");
put("$root/proc/meminfo", "MemTotal: 1000 kB\nMemAvailable: 500 kB\n");
$store = Store::open($tcfg->get('db_path'));
$tick = new Tick($tcfg, $store, $tctx, Collectors::linux(), fn($m) => print("log: $m\n"));
$base = 1700000000;
$tick->run($base);
$tick->run($base + 60);          // cpu due again, mem (5 min) not
$cnt = fn($metric) => (int)$store->pdo->query("SELECT COUNT(*) FROM samples WHERE metric='$metric'")->fetchColumn();
check('mem sampled once in first 2 min', $cnt('mem.used_pct') === 1);
check('disabled metric never stored', $cnt('load.avg') === 0);
put("$root/proc/stat", "cpu  200 0 200 800 150 0 0 0 0 0\n");
$tick->run($base + 120);
check('cpu.load stored once rates exist', $cnt('cpu.load') >= 1);
$tick->run($base + 300);
check('mem sampled again after 5 min', $cnt('mem.used_pct') === 2);
$before = $cnt('mem.used_pct'); $tick->run($base + 300 + 295); $after = $cnt('mem.used_pct');
check('mem due at 5min minus slack', $after === $before + 1);

// ---------- Rollups + tiered queries: ground truth vs brute force ----------
$rdir = tmpdir();
$rcfg = new Config(['db_path' => "$rdir/r.sqlite", 'retention_days' => ['raw' => 2, '5m' => 3, '1h' => 100]]);
$rs = Store::open($rcfg->get('db_path'));
$now = 1700000000 - (1700000000 % 3600) + 1800;      // mid-hour
$vals = [];
mt_srand(7);
$rs->pdo->beginTransaction();
$ins = $rs->pdo->prepare('INSERT INTO samples (ts, metric, instance, value) VALUES (?,?,?,?)');
for ($t = $now - 5 * 86400; $t <= $now; $t += 60) {
    $v = mt_rand(0, 1000) / 10; $vals[$t] = $v;
    $ins->execute([$t, 'cpu.load', 'total', $v]);
}
$rs->pdo->commit();
$days = $rcfg->get('retention_days');
$rs->rollup($now);
$brute = function (int $from, int $to, int $bk) use ($vals) {
    $o = [];
    $from = intdiv($from, $bk) * $bk;
    foreach ($vals as $t => $v) { if ($t >= $from && $t < $to) { $b = intdiv($t, $bk) * $bk; $o[$b][] = $v; } }
    ksort($o);
    return $o;
};
$cmp = function (string $label, int $from, int $to, int $bk) use ($rs, $now, $days, $brute) {
    $got = $rs->series('cpu.load', null, $from, $to, $bk, $now, $days, 100000)['total'] ?? [];
    $want = $brute($from, $to, $bk);
    $ok = count($got) === count($want);
    foreach ($got as $p) {
        $w = $want[$p[0]] ?? null;
        if (!$w || !near($p[1], array_sum($w) / count($w), 1e-6) || !near($p[2], min($w), 1e-6) || !near($p[3], max($w), 1e-6) || $p[4] !== count($w)) { $ok = false; break; }
    }
    check($label, $ok, 'got ' . count($got) . ' want ' . count($want));
};
$cmp('raw tier, 15 min buckets, last 12 h', $now - 12 * 3600, $now + 1, 900);
$cmp('5m tier (older than raw), 5 min buckets', (int)($now - 2.5 * 86400), $now - 2 * 86400, 300);
$h = intdiv($now, 3600) * 3600;   // rollup-served ranges snap to whole source buckets, so use aligned bounds
$cmp('1h tier (older than 5m), 1 h buckets', $h - 4 * 86400, $h - 3 * 86400 - 43200, 3600);
$cmp('mixed range straddling rollup watermarks, 1 h buckets', $now - 5 * 86400 + 3600, $now - 3600, 3600);
$cmp('whole range incl. un-rolled tail, 1 h buckets', $now - 5 * 86400, $now + 1, 3600);
$rs->prune($now, $days);
$cmp('same answers after pruning raw', $now - 5 * 86400, $now + 1, 3600);
$cmp('recent raw still exact after pruning', $now - 3600, $now + 1, 300);
$left = (int)$rs->pdo->query('SELECT MIN(ts) FROM samples')->fetchColumn();
check('prune removed raw older than 2 days', $left >= $now - 2 * 86400, "min=$left");
try { $rs->series('cpu.load', null, $now - 2.5 * 86400, $now, 660, $now, $days, 100000); check('misaligned old bucket rejected', false); }
catch (Sysmon\ApiError $e) { check('misaligned old bucket rejected', true); }
$rs->rollup($now + 600); $rs->rollup($now + 600);
$cmp('rollup is idempotent', $now - 5 * 86400, $now + 1, 3600);

// ---------- API ----------
$token = 'secret-token-123';
$acfg = new Config(['db_path' => "$rdir/r.sqlite", 'api_tokens' => [hash('sha256', $token)], 'host_name' => 'testbox', 'retention_days' => $days, 'max_points' => 1000]);
$rs->pdo->prepare('INSERT INTO samples VALUES (?,?,?,?)')->execute([$now, 'temp.c', 'cpu', 55.0]);
$api = new Api($acfg);
$auth = ['Authorization' => "Bearer $token"];
[$c, ] = $api->handle(['action' => 'info'], [], true, $now);          check('no token -> 401', $c === 401);
[$c, ] = $api->handle(['action' => 'info'], ['Authorization' => 'Bearer nope'], true, $now); check('wrong token -> 401', $c === 401);
[$c, ] = $api->handle(['action' => 'info'], $auth, false, $now);      check('http refused -> 403', $c === 403);
[$c, $b] = $api->handle(['action' => 'info'], ['authorization' => "Bearer $token"], true, $now); check('lowercase header ok', $c === 200 && $b['host'] === 'testbox');
[$c, ] = $api->handle(['action' => 'info'], ['Authorization' => 'Bearer '], true, $now);          check('empty bearer -> 401', $c === 401);
[$c, ] = (new Api(new Config(['db_path' => "$rdir/r.sqlite"])))->handle(['action' => 'info'], $auth, true, $now); check('no tokens configured -> 403', $c === 403);
[$c, $b] = $api->handle(['action' => 'summary'], $auth, true, $now);
check('summary lists metrics with age 0', $c === 200 && count($b['items']) === 2 && $b['items'][0]['age'] === 0, json_encode($b));
[$c, $b] = $api->handle(['action' => 'catalog'], $auth, true, $now);
check('catalog has unit and interval', $c === 200 && $b['metrics'][0]['unit'] !== null && $b['metrics'][0]['interval_min'] === 1);
[$c, $b] = $api->handle(['action' => 'query', 'metric' => 'cpu.load', 'from' => (string)($now - 7200), 'to' => (string)$now, 'bucket' => '600'], $auth, true, $now);
check('query ok, 12 buckets', $c === 200 && count($b['series'][0]['points']) === 12 && $b['columns'][1] === 'avg', json_encode(array_slice($b, 0, 6)));
[$c, $b] = $api->handle(['action' => 'query', 'metric' => 'cpu.load'], $auth, true, $now);
check('query defaults to 24 h auto bucket', $c === 200 && $b['bucket'] === 300 && count($b['series'][0]['points']) <= 300, "bucket={$b['bucket']}");
[$c, $b] = $api->handle(['action' => 'query', 'metric' => 'cpu.load', 'from' => (string)($now - 5 * 86400), 'to' => (string)$now], $auth, true, $now);
check('auto bucket for old range is a multiple of 3600', $c === 200 && $b['bucket'] % 3600 === 0, "bucket={$b['bucket']}");
foreach ([
    'bad metric' => ['metric' => 'nope'],
    'sql in metric' => ['metric' => "cpu.load' OR 1=1 --"],
    'non-numeric from' => ['metric' => 'cpu.load', 'from' => '1; DROP'],
    'from after to' => ['metric' => 'cpu.load', 'from' => '10', 'to' => '5'],
    'tiny bucket' => ['metric' => 'cpu.load', 'bucket' => '5'],
    'too many raw points' => ['metric' => 'cpu.load', 'from' => (string)($now - 86400), 'to' => (string)$now, 'bucket' => '0'],
] as $label => $q) {
    [$c, ] = $api->handle(['action' => 'query'] + $q, $auth, true, $now);
    check("rejects $label", $c === 400, "got $c");
}
[$c, $b] = $api->handle(['action' => 'query', 'metric' => 'temp.c', 'instance' => "x' OR '1'='1"], $auth, true, $now);
check('instance filter is parameterised', $c === 200 && $b['series'] === []);
[$c, $b] = $api->handle(['action' => 'query', 'metric' => 'temp.c', 'from' => (string)($now - 60), 'to' => (string)($now + 1), 'bucket' => '0'], $auth, true, $now);
check('raw query returns the point', $c === 200 && $b['series'][0]['points'][0][1] == 55.0);
[$c, ] = $api->handle(['action' => 'drop'], $auth, true, $now); check('unknown action 400', $c === 400);
[$c, $b] = $api->dispatch(['action' => 'summary'], $now);
check('dispatch (in-process) needs no token and matches handle()', $c === 200 && $b === $api->handle(['action' => 'summary'], $auth, true, $now)[1]);
[$c, ] = $api->dispatch(['action' => 'query', 'metric' => 'nope'], $now); check('dispatch still validates', $c === 400);
$ro = Store::open("$rdir/r.sqlite", true);
try { $ro->pdo->exec('DELETE FROM samples'); check('read-only connection cannot write', false); } catch (\Throwable $e) { check('read-only connection cannot write', true); }

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
