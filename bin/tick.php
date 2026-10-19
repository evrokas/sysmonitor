<?php
// Usage: php bin/tick.php [-v] [config.php]   -- run every minute from the scheduler.
require __DIR__ . '/../src/bootstrap.php';

use Sysmon\{Config, Ctx, Store, Tick};
use Sysmon\Collector\Collectors;

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '-v'));
$cfg = Config::load($args[0] ?? null);
$dir = dirname($cfg->get('db_path'));
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$lock = fopen($dir . '/tick.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // previous tick still running
}
$ctx = new Ctx($cfg);
$store = Store::open($cfg->get('db_path'));
$res = (new Tick($cfg, $store, $ctx, Collectors::forCtx($ctx)))->run(time());
if (in_array('-v', $argv, true)) {
    echo "stored {$res['stored']} samples from: " . implode(', ', $res['ran']) . "\n";
}
