<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;

class Ticker extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'ticker_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'microtimestamp',
        'exchange',
        'symbol',
        'period',
        'payload',
    ];
}
