<?php
namespace Sysmon\Collector;

use Sysmon\Ctx;

/** Collector built from a name, a metric list and a closure. */
class FnCollector implements Collector
{
    private string $name;
    private array $metrics;
    /** @var callable */
    private $fn;

    public function __construct(string $name, array $metrics, callable $fn)
    {
        $this->name = $name;
        $this->metrics = $metrics;
        $this->fn = $fn;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function metrics(): array
    {
        return $this->metrics;
    }

    public function collect(Ctx $ctx, ?array $prev, int $now): array
    {
        return ($this->fn)($ctx, $prev, $now);
    }
}
