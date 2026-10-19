<?php
// Copy to config.php (git-ignored) and adjust. Everything here is optional except api_tokens.
return [
    'host_name' => null,                      // shown to the dashboard; default = hostname
    'db_path'   => __DIR__ . '/data/sysmon.sqlite',

    // Collection period in minutes, per metric. Matches the exact name first, then
    // shorter dotted prefixes: 'net.errors_ps' -> 'net.errors_ps', then 'net'.
    'default_interval' => 1,
    'intervals' => [
        'cpu'             => 1,
        'net'             => 1,
        'mem'             => 2,
        'disk.used_pct'   => 5,
        'disk.free_bytes' => 5,
        'temp'            => 2,
        'fan'             => 5,
        'uptime'          => 5,
    ],
    'disabled' => [],                         // e.g. ['fan', 'disk.read_bps']

    'retention_days' => ['raw' => 14, '5m' => 90, '1h' => 730],

    // API: sha256 digests of the bearer tokens allowed to read. Generate with: php bin/token.php
    'api_tokens'        => [],
    'api_require_https' => true,
    'trust_proxy_headers' => false,           // true only behind a proxy that sets X-Forwarded-Proto
];
