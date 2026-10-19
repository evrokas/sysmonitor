# sysmonitor

A small system monitor for Linux and Windows machines, in plain PHP (no composer, no extensions beyond `pdo_sqlite`).

- **Collector** (`bin/tick.php`): run every minute by the scheduler. Each metric has its own interval; only due metrics are sampled.
- **Storage**: one SQLite file per machine (WAL). Raw samples, plus 5-minute and 1-hour rollups (min/max/avg/count) kept longer.
- **API** (`web/api.php`): read-only JSON, bearer-token auth, HTTPS required. Returns raw points or aggregated buckets, so a dashboard (ZPrime or another) asks for exactly what it draws.
- The dashboard machine runs the same collector and reads its own SQLite file directly.

## Metrics

| Metric | Unit | Instance | Linux | Windows |
|---|---|---|---|---|
| `cpu.load`, `cpu.iowait` | % | `total` | `/proc/stat` | CIM (`cpu.load` only) |
| `load.avg` | | `1m` `5m` `15m` | `/proc/loadavg` | – |
| `mem.used_pct`, `mem.available_bytes`, `swap.used_pct` | % / B | – | `/proc/meminfo` | CIM |
| `disk.used_pct`, `disk.free_bytes` | % / B | mount / drive | `/proc/mounts` | CIM |
| `disk.read_bps`, `disk.write_bps` | B/s | device | `/proc/diskstats` | perf counters |
| `net.rx_bps`, `net.tx_bps`, `net.errors_ps` | B/s, /s | interface | `/proc/net/dev` | `Get-NetAdapterStatistics` |
| `temp.c` | °C | `chip/label` | hwmon, thermal_zone | `MSAcpi_ThermalZoneTemperature` if the board exposes it |
| `fan.rpm` | rpm | `chip/fan` | hwmon | not available |
| `uptime.s` | s | – | `/proc/uptime` | CIM |

A source that does not exist (VMs, many Windows boards) simply produces no samples. **Windows temperature and fans are best-effort**: most desktop and server boards do not expose them to a normal process.

**Step-by-step install for the server and for Linux/Windows clients: [docs/INSTALL.md](docs/INSTALL.md).** The sections below are a summary.

## Install (Linux)

```
git clone https://github.com/evrokas/sysmonitor /opt/sysmonitor
cd /opt/sysmonitor && cp config.example.php config.php
php bin/token.php                      # put the hash in config.php 'api_tokens', give the token to the dashboard
php bin/tick.php -v                    # first run; rate metrics (cpu, net, disk I/O) start on the second run
cp deploy/sysmonitor.{service,timer} /etc/systemd/system/ && systemctl enable --now sysmonitor.timer
```
(or `deploy/sysmonitor.cron`). Expose only `web/` with `deploy/apache-sysmonitor.conf`, over HTTPS.

**Permissions**: the collector user owns `data/`; the web user must be in its group with `data/` group-writable, because a SQLite WAL database cannot be read without write access to its `-shm` file. The API connection itself is made read-only (`query_only`).

## Install (Windows)

Unpack PHP, enable `extension=pdo_sqlite` in `php.ini`, then from an elevated PowerShell: `deploy\install-windows.ps1 -Php C:\php\php.exe`. **Not yet run on a real Windows machine** – the collectors are tested against a recorded PowerShell JSON snapshot only. Please run `php bin\tick.php -v` there and report what it collects.

## Per-metric intervals

`config.php` → `intervals` (minutes). A name matches exactly first, then shorter dotted prefixes, so `'net' => 5` covers all `net.*` and `'net.errors_ps' => 15` overrides one. Minimum 1 minute. Metrics in the same group (e.g. `net.*`) should share an interval: their counters advance together when the group runs.

## API

All calls: `Authorization: Bearer <token>`, `GET web/api.php?action=…`.

| action | returns |
|---|---|
| `info` | host name, OS, version, server time |
| `catalog` | metrics/instances that have data, unit, interval |
| `summary` | latest value, age, and 24 h min/max/avg for every metric/instance (one card each) |
| `query` | `metric` (required), `from`, `to` (unix seconds, default last 24 h), `bucket` (`auto`, `0` = raw, or seconds ≥ 60), `instance` (comma list or `*`) |

`query` returns `{columns:[ts,avg,min,max,n], series:[{instance, points:[[…]]}]}`. Buckets older than the raw retention must be a multiple of 300 (older than the 5-minute retention: 3600), and those ranges snap to whole rollup buckets. Requests over `max_points` are refused rather than truncated.

## Retention

Defaults: raw 14 days, 5-minute rollups 90 days, hourly rollups 2 years (`retention_days`). Raw rows are never deleted before a rollup has absorbed them.

## Tests

`bin/run_tests.sh` – lint, collector tests on fixture `/proc` and `/sys` trees, a Windows snapshot test, rollup/tier queries checked against brute force, API auth and validation, and a real tick → SQLite → `php -S` → curl end-to-end.
