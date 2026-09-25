<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUniqueIdentifier
{
    public function initializeHasUniqueIdentifier(): void
    {
        $this->setKeyType('string');
        $this->setIncrementing(false);
    }

    public static function bootHasUniqueIdentifier(): void
    {
        static::creating(function (Model $model): void {
            $keyName = $model->getKeyName();
            $current = $model->getAttribute($keyName);

            if ($current === null || $current === '') {
                $model->setAttribute($keyName, (string) Str::uuid7());
            }
        });
    }
}
