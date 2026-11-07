<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only. */
class AuditLog extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs is append-only'));
        static::deleting(fn () => throw new LogicException('audit_logs is append-only'));
    }
}
