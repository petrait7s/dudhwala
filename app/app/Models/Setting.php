<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['value' => 'array', 'updated_at' => 'datetime'];
}
