<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only. */
class OrderStatusEvent extends Model
{
    public $timestamps = false;
    protected $table = 'order_status_events';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('order_status_events is append-only'));
        static::deleting(fn () => throw new LogicException('order_status_events is append-only'));
    }
}
