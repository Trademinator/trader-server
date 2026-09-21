<?php
namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
//use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;

trait HasUniqueIdentifier
{
    public static function bootHasUniqueIdentifier()
    {
        static::creating(function (Model $model) {
            $model->setKeyType('uuid'); // Original 'string'
            $model->setIncrementing(false);
            $model->setAttribute($model->getKeyName(), (string)Str::uuid7()); // Uuid::uuid4() for Ramsey
        });
    }
}
