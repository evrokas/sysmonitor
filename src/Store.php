<?php
namespace Sysmon;

use PDO;

/**
 * SQLite storage: raw samples plus 5-minute and 1-hour rollups.
 * Rollups keep min/max/sum/count so buckets can be merged exactly.
 */
class Store
{
    public PDO $pdo;
    public bool $readonly;

    private function __construct(PDO $pdo, bool $readonly)
    {
        $this->pdo = $pdo;
        $this->readonly = $readonly;
    }

    public static function open(string $path, bool $readonly = false): self
    {
        if ($readonly && !is_file($path)) {
            throw new \RuntimeException('database not found');
        }
        if (!$readonly && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $s = new self($pdo, $readonly);
        if ($readonly) {
            $pdo->exec('PRAGMA query_only=1');   // a WAL reader still needs -shm write access, so no OPEN_READONLY
        } else {
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $s->migrate();
        }
        return $s;
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS samples (
                ts INTEGER NOT NULL, metric TEXT NOT NULL, instance TEXT NOT NULL DEFAULT \'\', value REAL NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_samples_m ON samples (metric, instance, ts);
            CREATE INDEX IF NOT EXISTS idx_samples_ts ON samples (ts);
            CREATE TABLE IF NOT EXISTS rollup_5m (
                ts INTEGER NOT NULL, metric TEXT NOT NULL, instance TEXT NOT NULL,
                mn REAL NOT NULL, mx REAL NOT NULL, sm REAL NOT NULL, cnt INTEGER NOT NULL,
                PRIMARY KEY (metric, instance, ts)) WITHOUT ROWID;
            CREATE TABLE IF NOT EXISTS rollup_1h (
                ts INTEGER NOT NULL, metric TEXT NOT NULL, instance TEXT NOT NULL,
                mn REAL NOT NULL, mx REAL NOT NULL, sm REAL NOT NULL, cnt INTEGER NOT NULL,
                PRIMARY KEY (metric, instance, ts)) WITHOUT ROWID;
            CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT NOT NULL);
        ');
    }

    public function getState(string $k): ?string
    {
        $st = $this->pdo->prepare('SELECT v FROM state WHERE k=?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function setState(string $k, string $v): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO state (k, v) VALUES (?, ?)')->execute([$k, $v]);
    }

    /** @param array<int, array{0:string,1:string,2:float}> $samples */
    public function insert(int $ts, array $samples): void
    {
        $this->pdo->beginTransaction();
        $st = $this->pdo->prepare('INSERT INTO samples (ts, metric, instance, value) VALUES (?, ?, ?, ?)');
        foreach ($samples as [$m, $i, $v]) {
            if (is_finite($v)) {
                $st->execute([$ts, $m, $i, $v]);
            }
        }
        $this->pdo->commit();
    }

    /** Fold complete 5-minute / 1-hour buckets into the rollup tables. Idempotent. */
    public function rollup(int $now): void
    {
        $w5 = (int)$this->getState('w5');
        $b5 = intdiv($now, 300) * 300;
        if ($b5 > $w5) {
            $this->pdo->prepare('INSERT OR REPLACE INTO rollup_5m
                SELECT (ts/300)*300, metric, instance, MIN(value), MAX(value), SUM(value), COUNT(*)
                FROM samples WHERE ts >= ? AND ts < ? GROUP BY 1, metric, instance')->execute([$w5, $b5]);
            $this->setState('w5', (string)$b5);
            $w5 = $b5;
        }
        $w1 = (int)$this->getState('w1');
        $b1 = intdiv($w5, 3600) * 3600;     // 5m rows are only complete below w5
        if ($b1 > $w1) {
            $this->pdo->prepare('INSERT OR REPLACE INTO rollup_1h
                SELECT (ts/3600)*3600, metric, instance, MIN(mn), MAX(mx), SUM(sm), SUM(cnt)
                FROM rollup_5m WHERE ts >= ? AND ts < ? GROUP BY 1, metric, instance')->execute([$w1, $b1]);
            $this->setState('w1', (string)$b1);
        }
    }

    /** Delete expired rows, never anything a coarser tier has not yet absorbed. Daily. */
    public function prune(int $now, array $days): void
    {
        $day = (string)intdiv($now, 86400);
        if ($this->getState('pruned') === $day) {
            return;
        }
        $w5 = (int)$this->getState('w5');
        $w1 = (int)$this->getState('w1');
        $this->pdo->prepare('DELETE FROM samples WHERE ts < ?')->execute([min($now - $days['raw'] * 86400, $w5)]);
        $this->pdo->prepare('DELETE FROM rollup_5m WHERE ts < ?')->execute([min($now - $days['5m'] * 86400, $w1)]);
        $this->pdo->prepare('DELETE FROM rollup_1h WHERE ts < ?')->execute([$now - $days['1h'] * 86400]);
        $this->setState('pruned', $day);
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    }

    /** Oldest timestamp for which raw rows are guaranteed to exist. */
    public function rawFloor(int $now, array $days): int
    {
        return $now - $days['raw'] * 86400;
    }

    /** Oldest timestamp for which 5-minute rollups are guaranteed to exist. */
    public function floor5(int $now, array $days): int
    {
        return (int)(ceil(($now - $days['5m'] * 86400) / 3600) * 3600);   // hour-aligned so tiers never overlap
    }

    /**
     * Series as instance => list of [ts, avg, min, max, n].
     * $bucket 0 returns raw rows; otherwise buckets are merged from whichever
     * tiers cover the range (1h rollup, then 5m rollup, then raw).
     * Ranges served from rollups snap to whole source buckets at the start (always) and end.
     * Throws ApiError for combinations the stored data cannot answer exactly.
     */
    public function series(string $metric, ?array $instances, int $from, int $to, int $bucket, int $now, array $days, int $maxPoints): array
    {
        $instFilter = '';
        $params = [':m' => $metric, ':from' => $from, ':to' => $to];
        if ($instances) {
            $ph = [];
            foreach (array_values($instances) as $k => $i) {
                $ph[] = ":i$k";
                $params[":i$k"] = $i;
            }
            $instFilter = ' AND instance IN (' . implode(',', $ph) . ')';
        }

        if ($bucket === 0) {
            $st = $this->pdo->prepare("SELECT ts, instance, value FROM samples WHERE metric=:m AND ts>=:from AND ts<:to$instFilter ORDER BY ts LIMIT " . ($maxPoints + 1));
            $st->execute($params);
            $out = [];
            $n = 0;
            foreach ($st as $r) {
                $out[$r['instance']][] = [(int)$r['ts'], (float)$r['value'], (float)$r['value'], (float)$r['value'], 1];
                $n++;
            }
            if ($n > $maxPoints) {
                throw new ApiError("too many raw points (max $maxPoints); use a bucket or a shorter range");
            }
            return $out;
        }

        if (($to - $from) / $bucket > $maxPoints) {
            throw new ApiError("bucket too small for this range (max $maxPoints points)");
        }
        $params[':bk'] = $bucket;
        $params[':from'] = $from = intdiv($from, $bucket) * $bucket;   // first bucket is always whole

        if ($from >= $this->rawFloor($now, $days)) {
            $sql = "SELECT (ts/:bk)*:bk AS b, instance, MIN(value) AS mn, MAX(value) AS mx, SUM(value) AS sm, COUNT(*) AS cnt
                    FROM samples WHERE metric=:m AND ts>=:from AND ts<:to$instFilter GROUP BY b, instance ORDER BY b";
        } else {
            // Older than raw retention: 1 h rollup before c5, 5 m rollup from c5 to the 5 m watermark, raw after it.
            $c5 = $this->floor5($now, $days);
            if ($from < $c5 && $bucket % 3600 !== 0) {
                throw new ApiError('bucket must be a multiple of 3600 for ranges older than the 5-minute retention');
            }
            if ($bucket % 300 !== 0) {
                throw new ApiError('bucket must be a multiple of 300 for ranges older than the raw retention');
            }
            $params[':c5'] = $c5;
            $params[':w5'] = (int)$this->getState('w5');
            $sql = "SELECT b, instance, MIN(mn) AS mn, MAX(mx) AS mx, SUM(sm) AS sm, SUM(cnt) AS cnt FROM (
                      SELECT (ts/:bk)*:bk AS b, instance, mn, mx, sm, cnt FROM rollup_1h
                        WHERE metric=:m AND ts>=:from AND ts<:to AND ts<:c5$instFilter
                      UNION ALL
                      SELECT (ts/:bk)*:bk, instance, mn, mx, sm, cnt FROM rollup_5m
                        WHERE metric=:m AND ts>=:from AND ts<:to AND ts>=:c5 AND ts<:w5$instFilter
                      UNION ALL
                      SELECT (ts/:bk)*:bk, instance, value, value, value, 1 FROM samples
                        WHERE metric=:m AND ts>=:from AND ts<:to AND ts>=:w5$instFilter
                    ) GROUP BY b, instance ORDER BY b";
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st as $r) {
            $out[$r['instance']][] = [(int)$r['b'], $r['sm'] / $r['cnt'], (float)$r['mn'], (float)$r['mx'], (int)$r['cnt']];
        }
        return $out;
    }

    /** Latest value per metric/instance in the last 24 h, with 24 h min/max/avg. */
    public function summary(int $now): array
    {
        $since = $now - 86400;
        $st = $this->pdo->prepare('SELECT metric, instance, MIN(value) mn, MAX(value) mx, AVG(value) av, MAX(ts) t
                                   FROM samples WHERE ts >= ? GROUP BY metric, instance ORDER BY metric, instance');
        $st->execute([$since]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $last = $this->pdo->prepare('SELECT value FROM samples WHERE metric=? AND instance=? AND ts=? LIMIT 1');
        foreach ($rows as &$r) {
            $last->execute([$r['metric'], $r['instance'], $r['t']]);
            $r['value'] = (float)$last->fetchColumn();
        }
        return $rows;
    }

    public function catalog(): array
    {
        return $this->pdo->query('SELECT metric, instance, MAX(ts) AS last_ts, COUNT(*) AS n FROM samples GROUP BY metric, instance ORDER BY metric, instance')->fetchAll(PDO::FETCH_ASSOC);
    }
}
