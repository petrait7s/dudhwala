<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $table = 'product_variants';
    protected $guarded = [];

    protected $casts = ['track_stock' => 'boolean'];
    public function product() { return $this->belongsTo(Product::class); }
}
