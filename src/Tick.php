<?php
namespace Sysmon;

use Sysmon\Collector\Collector;

/**
 * One scheduler pass, run every minute from cron / systemd timer / Task Scheduler.
 * A collector runs when any of its metrics is due; only due metrics are stored.
 * Metrics in the same group should share an interval: rate state advances each run.
 */
class Tick
{
    private const SLACK = 10;   // seconds of scheduler jitter tolerated

    private Config $cfg;
    private Store $store;
    private Ctx $ctx;
    /** @var Collector[] */
    private array $collectors;
    /** @var callable */
    private $log;

    public function __construct(Config $cfg, Store $store, Ctx $ctx, array $collectors, ?callable $log = null)
    {
        $this->cfg = $cfg;
        $this->store = $store;
        $this->ctx = $ctx;
        $this->collectors = $collectors;
        $this->log = $log ?? function (string $m): void { fwrite(STDERR, $m . "\n"); };
    }

    /** @return array{stored:int, ran:string[]} */
    public function run(int $now): array
    {
        $stored = 0;
        $ran = [];
        foreach ($this->collectors as $col) {
            $due = [];
            foreach ($col->metrics() as $m) {
                $last = (int)$this->store->getState("last:$m");
                if ($this->cfg->enabled($m) && $now - $last >= $this->cfg->interval($m) * 60 - self::SLACK) {
                    $due[$m] = true;
                }
            }
            if (!$due) {
                continue;
            }
            try {
                $prev = json_decode((string)$this->store->getState('c:' . $col->name()), true);
                $res = $col->collect($this->ctx, is_array($prev) ? $prev : null, $now);
                $samples = array_values(array_filter($res['samples'], fn($s) => isset($due[$s[0]])));
                $this->store->insert($now, $samples);
                $stored += count($samples);
                foreach (array_keys($due) as $m) {
                    $this->store->setState("last:$m", (string)$now);
                }
                $this->store->setState('c:' . $col->name(), json_encode($res['state']));
                $ran[] = $col->name();
            } catch (\Throwable $e) {
                ($this->log)('collector ' . $col->name() . ' failed: ' . $e->getMessage());
            }
        }
        $this->store->rollup($now);
        $this->store->prune($now, $this->cfg->get('retention_days'));
        return ['stored' => $stored, 'ran' => $ran];
    }
}
