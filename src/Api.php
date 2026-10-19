<?php
namespace Sysmon;

/**
 * Read-only JSON API. handle() takes plain arrays so it is testable without a web server.
 *   ?action=info                       host, os, version, server time
 *   ?action=catalog                    metrics and instances that have data
 *   ?action=summary                    latest value + 24 h min/max/avg per metric/instance
 *   ?action=query&metric=cpu.load&from=&to=&bucket=auto|0|<seconds>&instance=a,b
 */
class Api
{
    public const VERSION = '0.1.0';

    private Config $cfg;
    private ?Store $store = null;

    public function __construct(Config $cfg, ?Store $store = null)
    {
        $this->cfg = $cfg;
        $this->store = $store;
    }

    /** @return array{0:int,1:array} status code and body */
    public function handle(array $query, array $headers, bool $https, ?int $now = null): array
    {
        $now = $now ?? time();
        try {
            $this->authorize($headers, $https);
            return $this->dispatch($query, $now);
        } catch (ApiError $e) {
            return [$e->status, ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            error_log('sysmonitor api: ' . $e->getMessage());
            return [500, ['error' => 'internal error']];
        }
    }

    /**
     * The same actions without authentication or HTTPS checks, for in-process
     * callers (a dashboard on the same machine reading the local database).
     * Never expose this to a request: it is the caller's job to be trusted.
     *
     * @return array{0:int,1:array}
     */
    public function dispatch(array $query, ?int $now = null): array
    {
        $now = $now ?? time();
        try {
            $store = $this->store ?? Store::open($this->cfg->get('db_path'), true);
            switch ($query['action'] ?? 'info') {
                case 'info':
                    return [200, ['host' => $this->cfg->hostName(), 'os' => PHP_OS_FAMILY, 'version' => self::VERSION, 'now' => $now]];
                case 'catalog':
                    $rows = array_map(fn($r) => $r + ['unit' => Catalog::unit($r['metric']), 'interval_min' => $this->cfg->interval($r['metric'])], $store->catalog());
                    return [200, ['metrics' => $rows]];
                case 'summary':
                    $rows = array_map(fn($r) => [
                        'metric' => $r['metric'], 'instance' => $r['instance'], 'unit' => Catalog::unit($r['metric']),
                        'value' => $r['value'], 'ts' => (int)$r['t'], 'age' => $now - (int)$r['t'],
                        'min24h' => (float)$r['mn'], 'max24h' => (float)$r['mx'], 'avg24h' => (float)$r['av'],
                        'interval_min' => $this->cfg->interval($r['metric']),
                    ], $store->summary($now));
                    return [200, ['host' => $this->cfg->hostName(), 'now' => $now, 'items' => $rows]];
                case 'query':
                    return [200, $this->query($store, $query, $now)];
                default:
                    throw new ApiError('unknown action');
            }
        } catch (ApiError $e) {
            return [$e->status, ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            error_log('sysmonitor api: ' . $e->getMessage());
            return [500, ['error' => 'internal error']];
        }
    }

    private function authorize(array $headers, bool $https): void
    {
        if ($this->cfg->get('api_require_https') && !$https) {
            throw new ApiError('HTTPS required', 403);
        }
        $tokens = $this->cfg->get('api_tokens');
        if (!$tokens) {
            throw new ApiError('API disabled: no api_tokens configured', 403);
        }
        $auth = '';
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $auth = (string)$v;
            }
        }
        $given = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? hash('sha256', $m[1]) : '';
        $ok = false;
        foreach ($tokens as $t) {
            $ok = hash_equals(strtolower((string)$t), $given) || $ok;   // no early exit
        }
        if (!$ok || $given === '') {
            throw new ApiError('unauthorized', 401);
        }
    }

    private function query(Store $store, array $q, int $now): array
    {
        $metric = (string)($q['metric'] ?? '');
        if (!isset(Catalog::METRICS[$metric])) {
            throw new ApiError('unknown metric');
        }
        $to = isset($q['to']) ? $this->int($q['to'], 'to') : $now;
        $from = isset($q['from']) ? $this->int($q['from'], 'from') : $to - 86400;
        if ($from >= $to) {
            throw new ApiError('from must be before to');
        }
        $days = $this->cfg->get('retention_days');
        $b = $q['bucket'] ?? 'auto';
        $bucket = $b === 'auto' ? $this->autoBucket($store, $from, $to, $now, $days) : $this->int($b, 'bucket');
        if ($bucket < 0 || ($bucket > 0 && $bucket < 60)) {
            throw new ApiError('bucket must be 0 (raw) or at least 60 seconds');
        }
        $inst = null;
        if (isset($q['instance']) && $q['instance'] !== '*' && $q['instance'] !== '') {
            $inst = array_slice(explode(',', (string)$q['instance']), 0, 20);
        }
        $series = $store->series($metric, $inst, $from, $to, $bucket, $now, $days, (int)$this->cfg->get('max_points'));
        $out = [];
        foreach ($series as $i => $pts) {
            $out[] = ['instance' => (string)$i, 'points' => $pts];
        }
        return [
            'host' => $this->cfg->hostName(), 'metric' => $metric, 'unit' => Catalog::unit($metric),
            'from' => $from, 'to' => $to, 'bucket' => $bucket,
            'columns' => ['ts', 'avg', 'min', 'max', 'n'], 'series' => $out,
        ];
    }

    private function autoBucket(Store $store, int $from, int $to, int $now, array $days): int
    {
        $needs = $from < $store->rawFloor($now, $days)
            ? ($from < $store->floor5($now, $days) ? 3600 : 300) : 60;
        foreach ([60, 300, 900, 3600, 14400, 86400] as $c) {
            if ($c >= $needs && $c % $needs === 0 && ($to - $from) / $c <= 300) {
                return $c;
            }
        }
        return 86400;
    }

    private function int($v, string $name): int
    {
        if (!is_string($v) && !is_int($v) || !preg_match('/^-?\d{1,12}$/', (string)$v)) {
            throw new ApiError("$name must be an integer");
        }
        return (int)$v;
    }
}
