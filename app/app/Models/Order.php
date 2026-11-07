<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['bulk_rule_snapshot' => 'array', 'pickup_at' => 'datetime'];

    public function items() { return $this->hasMany(OrderItem::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function events() { return $this->hasMany(OrderStatusEvent::class); }
    public function outlet() { return $this->belongsTo(Outlet::class); }
}
