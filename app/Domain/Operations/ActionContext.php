<?php

namespace App\Domain\Operations;

use Illuminate\Support\Str;

class ActionContext
{
    /** @var list<array{trace_id: string, parent_trace_id: ?string, actor_id: ?string, started: int}> */
    private array $frames = [];

    public function begin(?string $parent = null): string
    {
        $id = (string) Str::uuid();
        $parent ??= $this->current()['trace_id'] ?? null;
        $this->frames[] = ['trace_id' => $id,
            'parent_trace_id' => Str::isUuid($parent ?? '') ? $parent : null,
            'actor_id' => $this->current()['actor_id'] ?? null, 'started' => hrtime(true)];

        return $id;
    }

    public function actor(?string $id): void
    {
        if ($this->frames !== [] && Str::isUuid($id ?? '')) {
            $this->frames[array_key_last($this->frames)]['actor_id'] = $id;
        }
    }

    public function current(): array
    {
        return $this->frames === [] ? [] : $this->frames[array_key_last($this->frames)];
    }

    public function elapsed(): int
    {
        return (int) round((hrtime(true) - ($this->current()['started'] ?? hrtime(true))) / 1_000_000);
    }

    public function end(string $id): void
    {
        if (($this->current()['trace_id'] ?? null) === $id) {
            array_pop($this->frames);
        }
    }
}
