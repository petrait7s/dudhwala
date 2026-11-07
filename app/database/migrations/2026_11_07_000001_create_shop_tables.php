<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Money columns are integer poisha. Phones are stored as 01XXXXXXXXX.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('name_bn');
            $t->string('name_en')->nullable();
            $t->string('address_line');
            $t->string('phone', 20)->nullable();
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE | PAUSED | CLOSED
            $t->timestamps();
        });

        // weekday 0=Sunday..6=Saturday, Asia/Dhaka local. No row = closed.
        Schema::create('outlet_hours', function (Blueprint $t) {
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');
            $t->time('opens_at');
            $t->time('closes_at');
            $t->primary(['outlet_id', 'weekday']);
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 80)->unique();
            $t->string('name_bn');
            $t->string('name_en')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('active')->default(true);
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->constrained();
            $t->string('slug', 120)->unique();
            $t->string('name_bn');
            $t->string('name_en')->nullable();
            $t->boolean('name_verified')->default(false);
            // null = category default. APPROVED | DISABLED | PENDING_VERIFICATION | PENDING_CONFIRMATION
            $t->string('outer_city_status', 24)->nullable();
            $t->boolean('active')->default(false);
            $t->text('description_bn')->nullable();
            $t->text('ingredients_bn')->nullable();
            $t->text('allergens_bn')->nullable();
            $t->text('storage_bn')->nullable();
            $t->text('shelf_life_bn')->nullable();
            $t->string('image_path')->nullable();
            $t->unsignedInteger('image_version')->default(0);
            $t->timestamps();
            $t->index(['category_id', 'active']);
        });

        // price_poisha null = price not set; cannot be ordered.
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('label_bn')->default('');
            $t->string('label_en')->nullable();
            $t->unsignedInteger('price_poisha')->nullable();
            $t->unsignedInteger('compare_at_poisha')->nullable();
            $t->unsignedInteger('max_qty')->default(20);
            $t->boolean('track_stock')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('inventory', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained();
            $t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $t->integer('quantity_on_hand')->default(0);
            $t->integer('reserved_quantity')->default(0);
            $t->timestamps();
            $t->unique(['outlet_id', 'product_variant_id']);
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('phone', 11)->unique(); // same phone = same customer
            $t->string('name', 80);
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_number', 16)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('outlet_id')->constrained(); // fulfilling outlet
            $t->string('method', 16); // HOME_DELIVERY | EXPRESS_PICKUP | BULK_ORDER
            $t->string('zone', 16)->nullable(); // IN_CITY | OUTER_CITY
            $t->string('status', 24);
            $t->string('contact_name', 80);
            $t->string('contact_phone', 11);
            $t->string('address_line1', 300)->nullable();
            $t->string('address_landmark', 120)->nullable();
            $t->timestamp('pickup_at')->nullable();
            $t->unsignedInteger('subtotal_poisha');
            $t->unsignedInteger('delivery_fee_poisha')->default(0);
            $t->unsignedInteger('total_poisha');
            $t->string('idempotency_key', 64)->unique();
            $t->json('bulk_rule_snapshot')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['customer_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('product_slug', 120);
            $t->string('product_name_bn');
            $t->string('product_name_en')->nullable();
            $t->string('variant_label_bn');
            $t->unsignedInteger('unit_price_poisha');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('line_total_poisha');
        });

        // Append-only (enforced in model).
        Schema::create('order_status_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_role', 32);
            $t->text('reason')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // Payments are never deleted, only voided. trx_id unique => one TrxID verifies one payment.
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->string('method', 8); // COD | BKASH | NAGAD
            $t->unsignedInteger('amount_poisha');
            $t->string('trx_id', 40)->nullable()->unique();
            $t->string('sender_number', 11)->nullable();
            $t->string('status', 24)->default('UNPAID'); // UNPAID | PENDING_VERIFICATION | PAID | VOID
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamps();
            $t->index(['order_id', 'status']);
        });

        // Append-only stock ledger.
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_id')->constrained('inventory');
            $t->foreignId('outlet_id')->constrained();
            $t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 16); // SET | RESERVE | RELEASE | COMMIT_SALE
            $t->integer('qty_delta_on_hand');
            $t->integer('qty_delta_reserved');
            $t->integer('on_hand_after');
            $t->integer('reserved_after');
            $t->text('reason')->nullable();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_system', 64)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['inventory_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_role', 32);
            $t->string('action', 64);
            $t->string('entity_type', 48);
            $t->string('entity_id', 64)->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id']);
            $t->index('created_at');
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 96)->primary();
            $t->json('value');
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['settings','audit_logs','stock_movements','payments','order_status_events','order_items','orders','customers','inventory','product_variants','products','categories','outlet_hours','outlets'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
