<?php
namespace Sysmon;

/**
 * Configuration: defaults overlaid with the user's config.php (an array).
 * Per-metric settings (intervals, disabled) match the exact metric name first,
 * then each shorter dotted prefix ("net.rx_bps" -> "net.rx_bps", "net").
 */
class Config
{
    public array $c;

    public function __construct(array $user = [])
    {
        $this->c = array_replace_recursive(self::defaults(), $user);
    }

    public static function load(?string $path): self
    {
        $path = $path ?: getenv('SYSMON_CONFIG') ?: dirname(__DIR__) . '/config.php';
        $user = is_file($path) ? (require $path) : [];
        return new self(is_array($user) ? $user : []);
    }

    public static function defaults(): array
    {
        return [
            'db_path'            => dirname(__DIR__) . '/data/sysmon.sqlite',
            'host_name'          => null,           // null = gethostname()
            'default_interval'   => 1,              // minutes
            'intervals'          => [],             // 'cpu' => 1, 'disk.used_pct' => 5, ...
            'disabled'           => [],             // ['fan', 'temp.c'] switches metrics off
            'retention_days'     => ['raw' => 14, '5m' => 90, '1h' => 730],
            'net_exclude'        => '/^(lo|veth.*|docker.*|br-.*)$/',
            'disk_fs_types'      => '/^(ext[234]|xfs|btrfs|zfs|f2fs|vfat|exfat|ntfs3?|fuseblk|jfs|reiserfs)$/',
            'api_tokens'         => [],             // sha256 hex digests, see bin/token.php
            'api_require_https'  => true,
            'trust_proxy_headers'=> false,          // honour X-Forwarded-Proto
            'max_points'         => 5000,
        ];
    }

    public function get(string $key, $default = null)
    {
        return $this->c[$key] ?? $default;
    }

    public function hostName(): string
    {
        return (string)($this->c['host_name'] ?: gethostname());
    }

    private function match(array $map, string $metric)
    {
        $k = $metric;
        while (true) {
            if (array_key_exists($k, $map)) {
                return $map[$k];
            }
            $p = strrpos($k, '.');
            if ($p === false) {
                return null;
            }
            $k = substr($k, 0, $p);
        }
    }

    public function interval(string $metric): int
    {
        $v = $this->match($this->c['intervals'], $metric);
        return max(1, (int)($v ?? $this->c['default_interval']));
    }

    public function enabled(string $metric): bool
    {
        $k = $metric;
        while (true) {
            if (in_array($k, $this->c['disabled'], true)) {
                return false;
            }
            $p = strrpos($k, '.');
            if ($p === false) {
                return true;
            }
            $k = substr($k, 0, $p);
        }
    }
}
