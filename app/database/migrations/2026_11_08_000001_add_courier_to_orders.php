<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Manual courier handoff (Steadfast, Pathao, own rider): staff record who took the parcel and its tracking code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('courier', 16)->nullable()->after('cancel_reason'); // STEADFAST | PATHAO | OWN_RIDER | OTHER
            $t->string('courier_ref', 64)->nullable()->after('courier');   // consignment / tracking code
            $t->string('courier_note', 200)->nullable()->after('courier_ref'); // rider name and phone, etc.
            $t->timestamp('dispatched_at')->nullable()->after('courier_note');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['courier', 'courier_ref', 'courier_note', 'dispatched_at']));
    }
};
