<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutletHour extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $table = 'outlet_hours';
    protected $guarded = [];
}
