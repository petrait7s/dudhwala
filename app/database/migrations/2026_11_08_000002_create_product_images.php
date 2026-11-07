<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extra gallery photos of a product (the cover photo stays in products.image_path).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void { Schema::dropIfExists('product_images'); }
};
