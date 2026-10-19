# Installing sysmonitor

Vocabulary used below:

- **Server** – the machine that runs the ZPrime dashboard. It also runs the collector, so the dashboard watches itself. It reads its own database directly: no web API, no token, no HTTPS needed for it.
- **Client** – any other machine you want to watch (Linux or Windows). It runs the collector **and** exposes the read-only API that the server asks.

Commands assume Debian/Ubuntu with Apache and `sudo`. PHP 8.1 or newer is required (tested on 8.3) with `pdo_sqlite`.
Keep every machine's clock correct (NTP): charts use the server's clock to ask for "the last 24 h".

---

## 1. The server (the ZPrime machine)

### 1.1 Install the collector

```bash
sudo apt install -y php-cli php-sqlite3 git
sudo git clone https://github.com/evrokas/sysmonitor /opt/sysmonitor      # private repo: use a deploy key or token

# a dedicated user owns the data; the web user joins its group so ZPrime can read the database
sudo groupadd --system sysmon
sudo useradd  --system --gid sysmon --home-dir /opt/sysmonitor --shell /usr/sbin/nologin sysmon
sudo usermod  -aG sysmon www-data           # use your PHP user if it is not www-data (php-fpm pool user)

cd /opt/sysmonitor
sudo cp config.example.php config.php
sudo chown -R sysmon:sysmon /opt/sysmonitor
sudo chmod 2770 data                         # group-writable, new files inherit the group
sudo chmod 640  config.php
```

Verify PHP has the database driver, then do the first collection:

```bash
php -m | grep -i pdo_sqlite                  # must print pdo_sqlite
sudo -u sysmon php bin/tick.php -v           # prints: stored N samples from: cpu, load, mem, ...
```

(CPU, network and disk-I/O rates need two runs, so they appear from the second minute.)

### 1.2 Run it every minute

```bash
which php                                    # if not /usr/bin/php, edit ExecStart in the .service file first
sudo cp deploy/sysmonitor.service deploy/sysmonitor.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sysmonitor.timer
systemctl list-timers sysmonitor.timer       # NEXT should be within a minute
```

### 1.3 Let ZPrime see it

Restart the web server **once** so the PHP process picks up its new group and the SQLite driver:

```bash
sudo systemctl restart apache2               # or: sudo systemctl restart php8.3-fpm
```

Update ZPrime and add the server to its config (no database step needed):

```bash
cd /path/to/zprime && git pull
nano config/site.info.yaml
```

```yaml
sysmonitor:
  sysmonitor_path: /opt/sysmonitor
  hosts:
    - name: "This server"
      local: true
```

Sign in to ZPrime **as the owner**: a **System** block appears with this machine's tiles. (Other accounts never see it unless you add `allow_all_users: true`.)

---

## 2. A Linux client

Run these on the client.

### 2.1 Collector (identical to the server, steps 1.1 and 1.2)

```bash
sudo apt install -y php-cli php-sqlite3 git
sudo git clone https://github.com/evrokas/sysmonitor /opt/sysmonitor
sudo groupadd --system sysmon
sudo useradd  --system --gid sysmon --home-dir /opt/sysmonitor --shell /usr/sbin/nologin sysmon
sudo usermod  -aG sysmon www-data
cd /opt/sysmonitor && sudo cp config.example.php config.php
sudo chown -R sysmon:sysmon /opt/sysmonitor && sudo chmod 2770 data && sudo chmod 640 config.php
sudo cp deploy/sysmonitor.service deploy/sysmonitor.timer /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now sysmonitor.timer
```

### 2.2 Create this client's token

```bash
php /opt/sysmonitor/bin/token.php
```

It prints two lines. **Use one token per client.**

- `token (give to the dashboard, keep secret): …` → goes into ZPrime (step 4).
- `config.php entry for 'api_tokens': '…',` → goes into this client's `config.php`:

```bash
sudoedit /opt/sysmonitor/config.php
```
```php
'api_tokens' => ['PASTE_THE_HASH_HERE'],
'host_name'  => 'nas',            // optional label; default is the hostname
```

### 2.3 Publish the API over HTTPS (Apache)

Only `web/` is exposed, never the repository root (it holds `config.php` and `data/`).

```bash
sudo apt install -y apache2 libapache2-mod-php
sudo cp /opt/sysmonitor/deploy/apache-sysmonitor.conf /etc/apache2/conf-available/sysmonitor.conf
# only the ZPrime server may call it (recommended): replace the IP with the server's public/LAN address
sudo sed -i 's/Require all granted/Require ip 203.0.113.10/' /etc/apache2/conf-available/sysmonitor.conf
sudo a2enconf sysmonitor
sudo systemctl restart apache2              # restart (not reload): www-data just joined a group
```

TLS certificate (the client needs a DNS name that reaches it, port 80 open once for the challenge):

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d client1.example.com
```

No public name (home LAN or VPN only)? Keep it on the trusted network and use plain HTTP instead: in the client's `config.php` add `'api_require_https' => false,`, and in ZPrime give that host `allow_http: true`. The token then travels unencrypted, so only do this inside a LAN/VPN you trust.

Check it from the **ZPrime server** (not from the client itself):

```bash
curl -s -H "Authorization: Bearer THE_TOKEN" "https://client1.example.com/sysmonitor/api.php?action=info"
# {"host":"nas","os":"Linux","version":"0.1.0","now":...}
curl -s -H "Authorization: Bearer THE_TOKEN" "https://client1.example.com/sysmonitor/api.php?action=summary" | head -c 300
```

---

## 3. A Windows client

Everything in an **elevated PowerShell** (Run as administrator).

### 3.1 PHP

1. Install the **Microsoft Visual C++ Redistributable (x64)** if the machine does not have it (`vc_redist.x64.exe` from microsoft.com).
2. Download **PHP 8.3 → VS16 or VS17 x64 Non Thread Safe → Zip** from <https://windows.php.net/download> and extract it to `C:\php`.
3. Enable the two extensions sysmonitor needs:

```powershell
Copy-Item C:\php\php.ini-production C:\php\php.ini
(Get-Content C:\php\php.ini) `
  -replace '^;\s*extension_dir = "ext"','extension_dir = "ext"' `
  -replace '^;\s*extension=pdo_sqlite','extension=pdo_sqlite' `
  -replace '^;\s*extension=mbstring','extension=mbstring' | Set-Content C:\php\php.ini
C:\php\php.exe -m | findstr /i "pdo_sqlite mbstring"      # both names must be printed
```

### 3.2 sysmonitor

```powershell
git clone https://github.com/evrokas/sysmonitor C:\sysmonitor
# no git? download https://github.com/evrokas/sysmonitor/archive/refs/heads/master.zip and extract it to C:\sysmonitor

Copy-Item C:\sysmonitor\config.example.php C:\sysmonitor\config.php
C:\php\php.exe C:\sysmonitor\bin\token.php        # prints the token and its hash, as in 2.2
notepad C:\sysmonitor\config.php
```

In `config.php` set `'api_tokens' => ['PASTE_THE_HASH_HERE'],` and, because the built-in server in 3.4 has no TLS, `'api_require_https' => false,`.

First collection (check what this machine reports):

```powershell
C:\php\php.exe C:\sysmonitor\bin\tick.php -v      # stored N samples from: cpu, mem, disk, ...
```

Windows temperature and fan speed are best effort: most boards do not expose them, so those tiles may simply be absent. Everything else works.

### 3.3 Collect every minute

```powershell
powershell -ExecutionPolicy Bypass -File C:\sysmonitor\deploy\install-windows.ps1 -Php C:\php\php.exe -Root C:\sysmonitor
Get-ScheduledTask sysmonitor | Get-ScheduledTaskInfo      # LastTaskResult 0 after the first minute
```

### 3.4 Publish the API

PHP's built-in web server, started at boot, restricted by the firewall to the ZPrime server. It speaks plain HTTP, so **use it only on a LAN or VPN** (WireGuard/Tailscale work well); for an open-internet Windows client, put IIS or Apache with a certificate in front instead.

```powershell
$a = New-ScheduledTaskAction -Execute 'C:\php\php.exe' -Argument '-S 0.0.0.0:8099 -t C:\sysmonitor\web' -WorkingDirectory 'C:\sysmonitor'
$t = New-ScheduledTaskTrigger -AtStartup
$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Seconds 0) -RestartCount 99 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'sysmonitor-api' -Action $a -Trigger $t -Settings $s -User 'SYSTEM' -RunLevel Highest -Force
Start-ScheduledTask -TaskName 'sysmonitor-api'
New-NetFirewallRule -DisplayName 'sysmonitor-api' -Direction Inbound -Protocol TCP -LocalPort 8099 -RemoteAddress 203.0.113.10 -Action Allow
```

(Replace `203.0.113.10` with the ZPrime server's address.) Check from the ZPrime server:

```bash
curl -s -H "Authorization: Bearer THE_TOKEN" "http://WINDOWS_IP:8099/api.php?action=info"
```

---

## 4. Tell ZPrime about the clients

On the server, in `config/site.info.yaml` (hosts are numbered by position, so append new ones at the end):

```yaml
sysmonitor:
  cache_seconds: 30
  sysmonitor_path: /opt/sysmonitor
  hosts:
    - name: "This server"
      local: true
    - name: "NAS"
      url: "https://client1.example.com/sysmonitor/api.php"
      token: "TOKEN_FROM_STEP_2.2"
    - name: "Office PC"
      url: "http://192.168.1.50:8099/api.php"
      token: "TOKEN_FROM_STEP_3.2"
      allow_http: true            # plain http: trusted LAN/VPN only
```

Reload ZPrime as the owner. Each host appears within a minute of its first collections. No restart is needed.

---

## 5. Check and troubleshoot

| Symptom | Cause and fix |
|---|---|
| Tile says **Offline** | From the ZPrime server run the `curl` from 2.3 / 3.4. A timeout means network/firewall (`Require ip`, Windows rule); fix that first. |
| `curl` → `401 unauthorized` | You pasted the hash where the token belongs, or the reverse. ZPrime gets the **token**, the client's `config.php` gets the **hash**. |
| `403 HTTPS required` | Use an `https://` URL, or set `'api_require_https' => false` on the client (LAN/VPN only). |
| `403 API disabled` | `api_tokens` is empty in the client's `config.php`. |
| `500 internal error` / blank | Apache user cannot read the files: `id www-data` must list `sysmon`; `sudo -u www-data test -r /opt/sysmonitor/config.php && echo ok`; then `sudo systemctl restart apache2`. Details in `/var/log/apache2/error.log`. |
| `attempt to write a readonly database` in `journalctl -u sysmonitor` | Database created by an older version with a non-group-writable mode. `cd /opt/sysmonitor && sudo -u sysmon git pull`, then `sudo chmod 660 data/sysmon.sqlite*`. |
| **Collector silent** badge | The machine answers but nothing new is written: `systemctl status sysmonitor.timer`, `journalctl -u sysmonitor -n 50` (Windows: Task Scheduler → sysmonitor → History). |
| No temperature or fans | `ls /sys/class/hwmon` is empty on VMs and some boards; nothing to collect. On Windows see 3.2. |
| Fewer metrics than expected | `sudo -u sysmon php bin/tick.php -v` lists the collectors that produced data. |

## 6. Updating

```bash
cd /opt/sysmonitor && sudo -u sysmon git pull        # nothing to restart: each tick starts a fresh PHP
cd C:\sysmonitor; git pull                           # Windows
cd /path/to/zprime && git pull                       # the dashboard
```

Per-metric collection periods, retention and the rest of `config.php` are described in `config.example.php` and the README.
