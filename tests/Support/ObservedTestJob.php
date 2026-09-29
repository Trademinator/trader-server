<?php

namespace Tests\Support;

use App\Domain\Operations\ActionLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class ObservedTestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $secret, public string $mode) {}

    public function handle(ActionLog $log): void
    {
        if ($this->mode === 'fail') {
            throw new RuntimeException($this->secret);
        }
        if ($this->mode === 'release') {
            $this->release(60);
        }
        $log->write('test.job_action', ['outcome' => 'completed']);
    }
}
