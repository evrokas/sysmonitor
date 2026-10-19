<?php
namespace Sysmon\Collector;

use Sysmon\Ctx;

/**
 * A collector owns a group of related metrics. collect() returns every sample
 * it can produce plus the state it wants back next time (counters for rates).
 * Missing sources produce no samples, never errors.
 */
interface Collector
{
    public function name(): string;

    /** @return string[] metric names this collector can produce */
    public function metrics(): array;

    /**
     * @param array|null $prev state returned by the previous run
     * @return array{samples: array<int, array{0:string,1:string,2:float}>, state: array}
     */
    public function collect(Ctx $ctx, ?array $prev, int $now): array;
}
