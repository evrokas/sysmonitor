#!/bin/bash
# Real tick -> real SQLite -> real HTTP API (php -S). Usage: tests/e2e.sh
set -e
cd "$(dirname "$0")/.."
D=$(mktemp -d); trap 'kill $PID 2>/dev/null; rm -rf "$D"' EXIT
TOKEN=$(php bin/token.php | sed -n 's/^token[^:]*: //p'); HASH=$(php -r 'echo hash("sha256", $argv[1]);' "$TOKEN")
cat > "$D/config.php" <<PHP
<?php return ['db_path' => '$D/s.sqlite', 'api_tokens' => ['$HASH'], 'api_require_https' => false, 'host_name' => 'e2e'];
PHP
export SYSMON_CONFIG="$D/config.php"
php bin/tick.php -v; sleep 2
php -r '(new PDO("sqlite:$argv[1]"))->exec("UPDATE state SET v=0 WHERE k LIKE \"last:%\"");' "$D/s.sqlite"   # make everything due again
php bin/tick.php -v
php -S 127.0.0.1:8099 -t web >/dev/null 2>&1 & PID=$!; sleep 1
U=http://127.0.0.1:8099/api.php
fail() { echo "E2E FAIL: $1"; exit 1; }
[ "$(curl -s -o /dev/null -w '%{http_code}' "$U?action=info")" = 401 ] || fail "no token not 401"
curl -sf -H "Authorization: Bearer $TOKEN" "$U?action=info" | grep -q '"host":"e2e"' || fail info
curl -sf -H "Authorization: Bearer $TOKEN" "$U?action=summary" | grep -q '"metric":"mem.used_pct"' || fail summary
curl -sf -H "Authorization: Bearer $TOKEN" "$U?action=query&metric=mem.used_pct&bucket=60" | grep -q '"series":\[{"instance"' || fail query
echo "e2e ok"
