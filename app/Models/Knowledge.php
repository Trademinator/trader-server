<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'knowledge_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'microtimestamp',
        'exchange',
        'symbol',
        'period',
        'action',
        'value',
        'learned',
    ];
}
