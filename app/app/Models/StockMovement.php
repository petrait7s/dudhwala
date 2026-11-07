<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only. */
class StockMovement extends Model
{
    public $timestamps = false;
    protected $table = 'stock_movements';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('stock_movements is append-only'));
        static::deleting(fn () => throw new LogicException('stock_movements is append-only'));
    }
}
