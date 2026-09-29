<?php

namespace App\Domain\Operations;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class RecordObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Create a new class instance.
     */
    public function __construct(private ActionLog $log) {}

    public function created(Model $model): void
    {
        $this->record('created', $model);
    }

    public function updated(Model $model): void
    {
        $this->record('updated', $model);
    }

    public function deleted(Model $model): void
    {
        $this->record('deleted', $model);
    }

    private function record(string $operation, Model $model): void
    {
        $this->log->write('record.'.$operation, ['source' => get_class($model),
            'subject_id' => $model->getKey(), 'outcome' => 'committed']);
    }
}
