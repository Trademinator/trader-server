<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\ActionLog;
use Closure;
use Throwable;

/** Bounded operational progress; never log annotation payloads or trainer identities. */
final class HumanTrainingProgress
{
    private Closure $clock;

    private float $started;

    private float $lastLogged;

    private ?float $deadline = null;

    private float $budget = 0;

    private string $stage = 'starting';

    private array $metrics = [];

    private array $context;

    public function __construct(private ActionLog $log, array $manifest, ?Closure $clock = null)
    {
        $this->clock = $clock ?? microtime(...);
        $this->started = $this->lastLogged = ($this->clock)(true);
        $this->context = array_intersect_key($manifest, array_flip(['exchange', 'symbol', 'period', 'dataset_id']));
    }

    public function start(float $deadline, float $budget): void
    {
        $this->started = ($this->clock)(true);
        $this->deadline = $deadline;
        $this->budget = $budget;
        $this->write('started', 'running');
    }

    public function stage(string $stage, array $metrics = []): void
    {
        if ($stage === $this->stage) {
            $this->tick($metrics);

            return;
        }
        $this->stage = $stage;
        $this->metrics = $metrics;
        $this->write('progress', 'running');
    }

    public function tick(array $metrics = []): void
    {
        $this->metrics = [...$this->metrics, ...$metrics];
        if (($this->clock)(true) - $this->lastLogged >= 10) {
            $this->write('progress', 'running');
        }
    }

    public function finish(string $outcome, string $reason, ?Throwable $error = null): void
    {
        $this->write($outcome, $outcome, ['reason' => $reason,
            ...($error === null ? [] : $this->log->exception($error))]);
    }

    private function write(string $event, string $outcome, array $fields = []): void
    {
        $now = ($this->clock)(true);
        $this->lastLogged = $now;
        $this->log->write('intelligence.human_training.'.$event, [
            ...$this->context, ...$this->metrics, ...$fields,
            'stage' => $this->stage, 'outcome' => $outcome,
            'duration_ms' => (int) round(max(0, $now - $this->started) * 1000),
            'remaining_ms' => (int) round(max(0, ($this->deadline ?? $now) - $now) * 1000),
            'budget_seconds' => $this->budget, 'memory_bytes' => memory_get_usage(true),
        ]);
    }
}
