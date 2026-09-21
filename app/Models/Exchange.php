<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;

class Exchange extends Model
{
    use HasUniqueIdentifier;
    protected $primaryKey = 'exchange_id';
    public $incrementing = false;
    protected $keyType = 'uuid';	// TODO, check if UUID is valid

    protected $fillable = [
        'name',
        'config',
    ];
}
