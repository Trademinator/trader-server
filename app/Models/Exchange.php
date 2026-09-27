<?php

namespace App\Models;

use App\Domain\MarketData\MarketCatalog;
use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Exchange extends Model
{
    use HasUniqueIdentifier;

    protected static function booted(): void
    {
        $invalidate = function (self $exchange): void {
            Cache::forget(MarketCatalog::EXCHANGES_CACHE);
            Cache::forget(MarketCatalog::PAIRS_CACHE_PREFIX.$exchange->exchange_id);
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected $primaryKey = 'exchange_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'class',
        'config',
    ];
}
