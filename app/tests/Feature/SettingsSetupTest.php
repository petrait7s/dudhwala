<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\OrderStatusEvent;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_match_business_rules(): void
    {
        $s = app(SettingsService::class);
        $this->assertSame(['open_minute' => 600, 'close_minute' => 1320], $s->get('ordering.hours'));
        $this->assertFalse($s->get('bulk.rule')['approved']);
        $this->assertSame(20, $s->get('bulk.rule')['min_total_quantity']);
        $this->assertSame(2000000, $s->get('bulk.rule')['min_total_poisha']);
        $this->assertSame(['IN_CITY' => 0, 'OUTER_CITY' => 0], $s->get('delivery.fees'));
    }

    public function test_set_validates(): void
    {
        $s = app(SettingsService::class);
        $s->set('delivery.fees', ['IN_CITY' => 5000, 'OUTER_CITY' => 12000]);
        $this->assertSame(12000, $s->get('delivery.fees')['OUTER_CITY']);
        $this->expectException(\InvalidArgumentException::class);
        $s->set('ordering.hours', ['open_minute' => 900, 'close_minute' => 600]);
    }

    public function test_unknown_or_bad_value_rejected(): void
    {
        $s = app(SettingsService::class);
        try { $s->set('nope', 1); $this->fail(); } catch (\InvalidArgumentException) {}
        $this->expectException(\InvalidArgumentException::class);
        $s->set('delivery.fees', ['IN_CITY' => -1, 'OUTER_CITY' => 0]);
    }

    public function test_setup_command_is_idempotent(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dw').'.json';
        file_put_contents($file, file_get_contents(base_path('../apps/api/data/setup.example.json')));
        $this->artisan('dudhwala:setup', ['--file' => $file])->assertSuccessful();
        $this->artisan('dudhwala:setup', ['--file' => $file])->assertSuccessful();
        $this->assertSame(1, Outlet::count());
        $o = Outlet::first();
        $this->assertSame(1, $o->hours()->count());
        $s = app(SettingsService::class);
        $this->assertSame($o->id, $s->get('fulfilment.dispatch_outlet_id'));
        $this->assertSame($o->id, $s->get('bulk.collection_outlet_id'));
    }

    public function test_setup_rejects_unknown_outlet_code(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dw').'.json';
        file_put_contents($file, json_encode(['outlets' => [['code' => 'A-1', 'nameBn' => 'x', 'addressLine' => 'y']], 'dispatchOutletCode' => 'ZZ']));
        $this->artisan('dudhwala:setup', ['--file' => $file])->assertFailed();
        $this->assertSame(0, Outlet::count());
    }

    public function test_status_events_append_only(): void
    {
        $o = Outlet::create(['code' => 'T-1', 'name_bn' => 'x', 'address_line' => 'y']);
        $c = \App\Models\Customer::create(['phone' => '01712345678', 'name' => 'N']);
        $order = \App\Models\Order::create(['order_number' => 'DW-1', 'customer_id' => $c->id, 'outlet_id' => $o->id, 'method' => 'HOME_DELIVERY', 'zone' => 'IN_CITY', 'status' => 'PLACED', 'contact_name' => 'N', 'contact_phone' => '01712345678', 'subtotal_poisha' => 100, 'total_poisha' => 100, 'idempotency_key' => 'k1']);
        $ev = OrderStatusEvent::create(['order_id' => $order->id, 'to_status' => 'PLACED', 'actor_role' => 'SYSTEM']);
        $this->expectException(\LogicException::class);
        $ev->delete();
    }
}
