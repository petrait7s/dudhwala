<?php

namespace Tests\Feature;

use App\Contracts\OrderNotifier;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\TestData;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-03-10T05:00:00Z')); // 11:00 Dhaka
        $this->outlet = TestData::outlet('MODINA');
        $s = app(SettingsService::class);
        $s->set('fulfilment.dispatch_outlet_id', $this->outlet->id);
        $s->set('bulk.collection_outlet_id', $this->outlet->id);
        $s->set('delivery.fees', ['IN_CITY' => 6000, 'OUTER_CITY' => 12000]);
        $s->set('bulk.prepaid', false); // most tests use COD; the real default (true) is covered below
        RateLimiter::clear('x');
    }

    private function stocked(int $price = 10000, int $qty = 50, string $cat = 'bakery'): \App\Models\ProductVariant
    {
        $v = TestData::variant($price, $cat);
        app(InventoryService::class)->setStock($this->outlet->id, $v->id, $qty, ['system' => 't'], 'init');
        return $v;
    }

    private function body(array $over = []): array
    {
        $v = $over['_v'] ?? $this->stocked();
        unset($over['_v']);
        return array_replace_recursive([
            'idempotency_key' => bin2hex(random_bytes(8)), 'method' => 'HOME_DELIVERY', 'zone' => 'IN_CITY',
            'items' => [['variant_id' => $v->id, 'quantity' => 2]],
            'contact' => ['name' => 'রহিম', 'phone' => '+8801712345678', 'address_line1' => 'বাসা ১', 'landmark' => ''],
            'payment' => ['method' => 'COD'], 'website' => '',
        ], $over);
    }

    private function admin(string $role = 'ADMIN'): User
    {
        return User::create(['name' => 'S', 'email' => strtolower($role).bin2hex(random_bytes(2)).'@x.test', 'role' => $role, 'password' => 'long-enough-pass'])->fresh();
    }

    private function inv(int $variantId): object { return DB::table('inventory')->where(['outlet_id' => $this->outlet->id, 'product_variant_id' => $variantId])->first(); }

    /* ───── placing ───── */

    public function test_home_delivery_cod_happy_path(): void
    {
        $spy = new class implements OrderNotifier { public int $n = 0; public function orderPlaced(Order $o): void { $this->n++; } };
        $this->app->instance(OrderNotifier::class, $spy);
        $v = $this->stocked(10000, 50);
        $r = $this->postJson('/api/orders', $this->body(['_v' => $v]));
        $r->assertCreated()->assertJson(['status' => 'PLACED', 'subtotal_poisha' => 20000, 'delivery_fee_poisha' => 6000, 'total_poisha' => 26000]);
        $o = Order::first();
        $this->assertMatchesRegularExpression('/^DW-\d{6}-[A-Z2-9]{4}$/', $o->order_number);
        $this->assertSame('01712345678', $o->contact_phone);
        $this->assertSame(2, $this->inv($v->id)->reserved_quantity);
        $this->assertSame(1, \App\Models\OrderItem::where('order_id', $o->id)->count());
        $this->assertSame(['PLACED'], OrderStatusEvent::pluck('to_status')->all());
        $p = Payment::first();
        $this->assertSame(['COD', 'UNPAID', 26000, null], [$p->method, $p->status, $p->amount_poisha, $p->trx_id]);
        $this->assertSame(1, $spy->n);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.placed']);
    }

    public function test_server_ignores_browser_prices_and_snapshots_items(): void
    {
        $v = $this->stocked(10000);
        $b = $this->body(['_v' => $v, 'items' => [['variant_id' => $v->id, 'quantity' => 1, 'price_poisha' => 1, 'unit_price' => 1]], 'total_poisha' => 1]);
        $this->postJson('/api/orders', $b)->assertCreated()->assertJson(['subtotal_poisha' => 10000]);
        $v->update(['price_poisha' => 99999]);
        $item = \App\Models\OrderItem::first();
        $this->assertSame([10000, 'পণ্য'], [$item->unit_price_poisha, $item->product_name_bn], 'later price edits never change history');
    }

    public function test_double_tap_makes_one_order(): void
    {
        $v = $this->stocked();
        $b = $this->body(['_v' => $v]);
        $a = $this->postJson('/api/orders', $b)->assertCreated();
        $c = $this->postJson('/api/orders', $b)->assertOk();
        $this->assertSame($a['order_number'], $c['order_number']);
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $this->inv($v->id)->reserved_quantity, 'stock reserved once');
        $this->assertSame(1, Payment::count());
    }

    public function test_honeypot_blocks_bots_and_creates_nothing(): void
    {
        $this->postJson('/api/orders', $this->body(['website' => 'http://spam']))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_closed_hours_boundaries(): void
    {
        $b = fn () => $this->body();
        foreach (['2026-03-10T03:59:00Z' => 422, '2026-03-10T04:00:00Z' => 201, '2026-03-10T15:59:00Z' => 201, '2026-03-10T16:00:00Z' => 422] as $t => $code) {
            $this->travelTo(\Carbon\CarbonImmutable::parse($t));
            RateLimiter::clear('o:127.0.0.1'); RateLimiter::clear('oh:127.0.0.1');
            $r = $this->postJson('/api/orders', $b());
            $r->assertStatus($code);
            if ($code === 422) $this->assertContains('ordering_closed', array_column($r->json('error.issues'), 'code'), $t);
        }
    }

    public function test_unpriced_and_hidden_products_are_refused(): void
    {
        $v = $this->stocked(); $v->update(['price_poisha' => null]);
        $this->postJson('/api/orders', $this->body(['_v' => $v]))->assertStatus(422)->assertJsonPath('error.code', 'UNPRICED');
        $h = $this->stocked(); $h->product->update(['active' => false]);
        $this->postJson('/api/orders', $this->body(['_v' => $h]))->assertStatus(422)->assertJsonPath('error.code', 'UNAVAILABLE');
        $this->assertSame(0, Order::count());
    }

    public function test_bulk_by_quantity_allows_only_showroom_and_charges_no_fee(): void
    {
        $v = $this->stocked(1000, 100);
        $big = ['items' => [['variant_id' => $v->id, 'quantity' => 20]]];
        $r = $this->postJson('/api/orders', $this->body(['_v' => $v] + $big));
        $r->assertStatus(422)->assertJsonPath('error.code', 'CHECKOUT_INVALID');
        $this->assertContains('bulk_requires_collection', array_column($r->json('error.issues'), 'code'));
        $this->postJson('/api/orders', $this->body(['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null] + $big))
            ->assertCreated()->assertJson(['status' => 'PLACED', 'delivery_fee_poisha' => 0, 'total_poisha' => 20000]);
        $this->assertNotNull(Order::first()->bulk_rule_snapshot);
    }

    public function test_bulk_by_amount(): void
    {
        $v = $this->stocked(2000000, 5);
        $r = $this->postJson('/api/orders', $this->body(['_v' => $v, 'items' => [['variant_id' => $v->id, 'quantity' => 1]]]));
        $this->assertContains('bulk_requires_collection', array_column($r->json('error.issues'), 'code'));
    }

    public function test_showroom_orders_are_prepaid_by_default_like_the_old_system(): void
    {
        \App\Models\Setting::where('key', 'bulk.prepaid')->delete();
        $this->assertTrue(app(SettingsService::class)->get('bulk.prepaid'));
        $v = $this->stocked(1000, 100);
        $b = ['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null, 'items' => [['variant_id' => $v->id, 'quantity' => 20]]];
        $this->assertContains('bulk_prepayment_required', array_column($this->postJson('/api/orders', $this->body($b))->json('error.issues'), 'code'));
        $this->postJson('/api/orders', $this->body($b + ['payment' => ['method' => 'BKASH', 'trx_id' => 'PRE00001', 'sender_number' => '01812345678']]))->assertCreated();
        $this->assertSame('PENDING_VERIFICATION', Payment::first()->status);
    }

    public function test_showroom_prepaid_setting_forces_mobile_payment(): void
    {
        app(SettingsService::class)->set('bulk.prepaid', true);
        $v = $this->stocked(1000, 100);
        $b = ['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null, 'items' => [['variant_id' => $v->id, 'quantity' => 20]]];
        $r = $this->postJson('/api/orders', $this->body($b));
        $this->assertContains('bulk_prepayment_required', array_column($r->json('error.issues'), 'code'));
    }

    public function test_outer_city_names_ineligible_line(): void
    {
        $ok = $this->stocked(1000, 10, 'bakery'); $bad = $this->stocked(1000, 10, 'dairy-sweets');
        $r = $this->postJson('/api/orders', $this->body(['_v' => $ok, 'zone' => 'OUTER_CITY', 'items' => [['variant_id' => $ok->id, 'quantity' => 1], ['variant_id' => $bad->id, 'quantity' => 1]]]));
        $r->assertStatus(422);
        $this->assertSame([['code' => 'item_not_deliverable', 'line' => (string) $bad->id]], $r->json('error.issues'));
        $bad->product->update(['outer_city_status' => 'APPROVED']);
        $this->postJson('/api/orders', $this->body(['_v' => $ok, 'zone' => 'OUTER_CITY', 'items' => [['variant_id' => $ok->id, 'quantity' => 1], ['variant_id' => $bad->id, 'quantity' => 1]]]))
            ->assertCreated()->assertJson(['delivery_fee_poisha' => 12000]);
    }

    public function test_out_of_stock_creates_nothing_and_keeps_no_reservation(): void
    {
        $a = $this->stocked(1000, 5); $b = $this->stocked(1000, 1);
        $r = $this->postJson('/api/orders', $this->body(['_v' => $a, 'items' => [['variant_id' => $a->id, 'quantity' => 3], ['variant_id' => $b->id, 'quantity' => 2]]]));
        $r->assertStatus(409)->assertJsonPath('error.code', 'OUT_OF_STOCK');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, $this->inv($a->id)->reserved_quantity);
    }

    public function test_untracked_variant_needs_no_stock_row(): void
    {
        $v = TestData::variant(500); $v->update(['track_stock' => false]);
        $this->postJson('/api/orders', $this->body(['_v' => $v]))->assertCreated();
        $this->assertSame(0, StockMovement::where('type', 'RESERVE')->count());
    }

    public function test_mobile_payment_needs_trx_and_sender_and_is_pending(): void
    {
        $v = $this->stocked();
        $r = $this->postJson('/api/orders', $this->body(['_v' => $v, 'payment' => ['method' => 'BKASH']]));
        $this->assertEqualsCanonicalizing(['payment_trx_required', 'payment_sender_invalid'], array_column($r->json('error.issues'), 'code'));
        $this->postJson('/api/orders', $this->body(['_v' => $v, 'payment' => ['method' => 'BKASH', 'trx_id' => ' ab12 cd34 ', 'sender_number' => '01812345678']]))->assertCreated();
        $p = Payment::first();
        $this->assertSame(['BKASH', 'PENDING_VERIFICATION', 'AB12CD34', '01812345678'], [$p->method, $p->status, $p->trx_id, $p->sender_number]);
    }

    public function test_same_trxid_cannot_be_used_twice_and_rolls_back_stock(): void
    {
        $v = $this->stocked(1000, 10);
        $pay = ['method' => 'NAGAD', 'trx_id' => 'TRX12345', 'sender_number' => '01812345678'];
        $this->postJson('/api/orders', $this->body(['_v' => $v, 'payment' => $pay]))->assertCreated();
        $r = $this->postJson('/api/orders', $this->body(['_v' => $v, 'payment' => ['trx_id' => 'trx12345'] + $pay]));
        $r->assertStatus(422)->assertJsonPath('error.code', 'TRX_DUPLICATE');
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $this->inv($v->id)->reserved_quantity, 'failed order kept no stock');
    }

    public function test_express_pickup_lead_time_and_outlet(): void
    {
        $v = $this->stocked();
        $base = ['_v' => $v, 'method' => 'EXPRESS_PICKUP', 'zone' => null, 'outlet_id' => $this->outlet->id];
        $r = $this->postJson('/api/orders', $this->body($base + ['pickup_at' => '2026-03-10T05:30:00Z']));
        $this->assertSame(['pickup_too_soon'], array_column($r->json('error.issues'), 'code'));
        $r = $this->postJson('/api/orders', $this->body($base + ['pickup_at' => '2026-03-10T06:30:00Z']));
        $r->assertCreated()->assertJson(['status' => 'PICKUP_REQUESTED', 'delivery_fee_poisha' => 0]);
        $r = $this->postJson('/api/orders', $this->body(array_replace($base, ['outlet_id' => null, 'pickup_at' => '2026-03-10T06:30:00Z'])));
        $this->assertContains('outlet_required', array_column($r->json('error.issues'), 'code'));
    }

    /* ───────── any branch can serve pickup and showroom orders ───────── */

    private function branch(string $code, string $status = 'ACTIVE'): \App\Models\Outlet
    {
        $o = TestData::outlet($code);
        if ($status !== 'ACTIVE') $o->update(['status' => $status]);
        return $o;
    }

    private function stockAt(\App\Models\Outlet $o, \App\Models\ProductVariant $v, int $qty): void
    {
        app(InventoryService::class)->setStock($o->id, $v->id, $qty, ['system' => 't'], 'init');
    }

    public function test_showroom_order_is_collected_at_the_branch_the_customer_picks(): void
    {
        $v = $this->stocked(1000, 100); // stocked at MODINA (default collection outlet)
        $other = $this->branch('ZINDABAZAR'); $this->stockAt($other, $v, 30);
        $b = ['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null, 'items' => [['variant_id' => $v->id, 'quantity' => 20]]];

        $this->postJson('/api/orders', $this->body($b + ['outlet_id' => $other->id]))->assertCreated();
        $o = Order::latest('id')->first();
        $this->assertSame($other->id, $o->outlet_id);
        $this->assertSame(20, (int) DB::table('inventory')->where(['outlet_id' => $other->id, 'product_variant_id' => $v->id])->value('reserved_quantity'));
        $this->assertSame(0, $this->inv($v->id)->reserved_quantity, 'the default branch was not touched');

        // no branch given: falls back to the default collection outlet
        $this->postJson('/api/orders', $this->body($b))->assertCreated();
        $this->assertSame($this->outlet->id, Order::latest('id')->first()->outlet_id);
    }

    public function test_showroom_branch_must_have_the_stock(): void
    {
        $v = $this->stocked(1000, 100);
        $empty = $this->branch('EMPTY'); $this->stockAt($empty, $v, 5);
        $r = $this->postJson('/api/orders', $this->body(['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null, 'outlet_id' => $empty->id, 'items' => [['variant_id' => $v->id, 'quantity' => 20]]]));
        $r->assertStatus(409)->assertJsonPath('error.code', 'OUT_OF_STOCK');
        $this->assertSame(0, Order::count());
    }

    public function test_unknown_or_paused_branch_is_refused_for_showroom_and_pickup(): void
    {
        $v = $this->stocked(1000, 100);
        $paused = $this->branch('PAUSED1', 'PAUSED');
        foreach ([$paused->id, 9999] as $id) {
            $r = $this->postJson('/api/orders', $this->body(['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null, 'outlet_id' => $id, 'items' => [['variant_id' => $v->id, 'quantity' => 20]]]));
            $r->assertStatus(422); $this->assertContains('outlet_required', array_column($r->json('error.issues'), 'code'));
            $r = $this->postJson('/api/orders', $this->body(['_v' => $v, 'method' => 'EXPRESS_PICKUP', 'zone' => null, 'outlet_id' => $id, 'pickup_at' => '2026-03-10T06:30:00Z']));
            $r->assertStatus(422); $this->assertContains('outlet_required', array_column($r->json('error.issues'), 'code'));
        }
        $this->assertSame(0, Order::count());
    }

    public function test_showroom_without_branch_or_default_needs_a_branch(): void
    {
        app(SettingsService::class)->set('bulk.collection_outlet_id', null);
        $v = $this->stocked(1000, 100);
        $r = $this->postJson('/api/orders', $this->body(['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null, 'items' => [['variant_id' => $v->id, 'quantity' => 20]]]));
        $this->assertContains('outlet_required', array_column($r->json('error.issues'), 'code'));
    }

    public function test_pickup_follows_the_chosen_branchs_own_hours(): void
    {
        $v = $this->stocked(1000, 100);
        $b = $this->branch('LATE'); $this->stockAt($b, $v, 10);
        // opens only Tuesdays 14:00-18:00 Dhaka. "now" is Tuesday 11:00; 06:30Z = 12:30 Dhaka, 09:00Z = 15:00 Dhaka
        $b->hours()->create(['weekday' => 2, 'opens_at' => '14:00', 'closes_at' => '18:00']);
        $base = ['_v' => $v, 'method' => 'EXPRESS_PICKUP', 'zone' => null, 'outlet_id' => $b->id];
        $r = $this->postJson('/api/orders', $this->body($base + ['pickup_at' => '2026-03-10T06:30:00Z']));
        $this->assertSame(['pickup_outside_hours'], array_column($r->json('error.issues'), 'code'));
        $this->postJson('/api/orders', $this->body($base + ['pickup_at' => '2026-03-10T09:00:00Z']))->assertCreated();
        $this->assertSame($b->id, Order::latest('id')->first()->outlet_id);
    }

    public function test_config_lists_every_active_branch_with_address_phone_and_hours(): void
    {
        $b = $this->branch('ZINDABAZAR'); $b->update(['address_line' => 'Zindabazar, Sylhet', 'phone' => '01711111111']);
        $b->hours()->create(['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '21:00']);
        $this->branch('OLD', 'CLOSED');
        $o = collect($this->getJson('/api/config')->assertOk()->json('outlets'));
        $this->assertCount(2, $o);
        $z = $o->firstWhere('code', 'ZINDABAZAR');
        $this->assertSame(['Zindabazar, Sylhet', '01711111111', [['weekday' => 1, 'opens' => '09:00', 'closes' => '21:00']]], [$z['address_line'], $z['phone'], $z['hours']]);
    }

    public function test_same_phone_is_same_customer(): void
    {
        $v = $this->stocked(1000, 100);
        $this->postJson('/api/orders', $this->body(['_v' => $v]))->assertCreated();
        $this->postJson('/api/orders', $this->body(['_v' => $v, 'contact' => ['phone' => '01712345678', 'name' => 'অন্য']]))->assertCreated();
        $this->assertSame(1, \App\Models\Customer::count());
        $this->assertSame(2, Order::count());
    }

    public function test_validation_errors_and_unconfigured_outlet(): void
    {
        $this->postJson('/api/orders', ['method' => 'NOPE'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $v = $this->stocked();
        app(SettingsService::class)->set('fulfilment.dispatch_outlet_id', null);
        $this->postJson('/api/orders', $this->body(['_v' => $v]))->assertStatus(503)->assertJsonPath('error.code', 'NOT_CONFIGURED');
        $this->assertSame(0, Order::count());
    }

    public function test_order_endpoint_is_rate_limited(): void
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) $codes[] = $this->postJson('/api/orders', ['website' => 'x'])->status();
        $this->assertSame(429, end($codes));
        $this->assertSame(422, $codes[0]);
    }

    public function test_cors_allows_only_configured_origin(): void
    {
        config(['cors.allowed_origins' => ['https://site.test']]);
        $ok = $this->call('OPTIONS', '/api/orders', [], [], [], ['HTTP_ORIGIN' => 'https://site.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertSame('https://site.test', $ok->headers->get('Access-Control-Allow-Origin'));
        $bad = $this->call('OPTIONS', '/api/orders', [], [], [], ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertNotSame('https://evil.test', $bad->headers->get('Access-Control-Allow-Origin'), 'a browser at evil.test is never granted access');
    }

    /* ───── public read ───── */

    public function test_catalogue_hides_inactive_and_never_shows_stock_counts(): void
    {
        $v = $this->stocked(5000, 7); $h = $this->stocked(5000, 7); $h->product->update(['active' => false]);
        $none = TestData::variant(1000);
        $r = $this->getJson('/api/catalogue')->assertOk();
        $slugs = array_column($r->json('products'), 'slug');
        $this->assertContains($v->product->slug, $slugs);
        $this->assertNotContains($h->product->slug, $slugs);
        $row = collect($r->json('products'))->firstWhere('slug', $none->product->slug);
        $this->assertFalse($row['variants'][0]['in_stock'], 'tracked variant with no stock row');
        $this->assertStringNotContainsString('quantity', $r->getContent());
    }

    public function test_config_endpoint(): void
    {
        $this->getJson('/api/config')->assertOk()->assertJsonPath('fees.OUTER_CITY', 12000)->assertJsonPath('bulk.approved', false)
            ->assertJsonPath('hours.open_minute', 600)->assertJsonPath('outlets.0.is_collection', true);
    }

    /* ───── tracking ───── */

    public function test_track_needs_matching_order_and_phone(): void
    {
        $v = $this->stocked();
        $n = $this->postJson('/api/orders', $this->body(['_v' => $v]))->json('order_number');
        $this->postJson('/api/orders/track', ['order_number' => strtolower($n), 'phone' => '+8801712345678'])->assertOk()
            ->assertJsonPath('order_number', $n)->assertJsonPath('payment_status', 'UNPAID')->assertJsonCount(1, 'items');
        $this->postJson('/api/orders/track', ['order_number' => $n, 'phone' => '01999999999'])->assertStatus(404);
        $this->postJson('/api/orders/track', ['order_number' => 'DW-NOPE', 'phone' => '01712345678'])->assertStatus(404);
        $this->postJson('/api/orders/track', ['order_number' => $n])->assertStatus(404);
        $this->assertStringNotContainsString('contact', $this->postJson('/api/orders/track', ['order_number' => $n, 'phone' => '01712345678'])->getContent());
    }

    public function test_track_is_rate_limited_per_order_number(): void
    {
        $codes = [];
        for ($i = 0; $i < 12; $i++) $codes[] = $this->postJson('/api/orders/track', ['order_number' => 'DW-X', 'phone' => '01712345678'])->status();
        $this->assertContains(429, $codes);
    }

    public function test_customers_have_no_cancel_endpoint(): void
    {
        $v = $this->stocked();
        $n = $this->postJson('/api/orders', $this->body(['_v' => $v]))->json('order_number');
        foreach (['/api/orders/'.$n.'/cancel', '/api/orders/cancel'] as $u) $this->assertContains($this->postJson($u, ['phone' => '01712345678'])->status(), [404, 405]);
        $this->assertSame('PLACED', Order::first()->status);
    }

    /* ───── staff: status flow ───── */

    private function placed(string $method = 'HOME_DELIVERY', array $extra = []): Order
    {
        $v = $this->stocked(1000, 100);
        $body = $this->body(['_v' => $v] + $extra);
        if ($method === 'BULK_ORDER') $body = $this->body(['_v' => $v, 'method' => 'BULK_ORDER', 'zone' => null, 'items' => [['variant_id' => $v->id, 'quantity' => 20]]] + $extra);
        $this->postJson('/api/orders', $body)->assertCreated();
        return Order::latest('id')->first();
    }

    public function test_status_moves_one_step_only_and_logs_events(): void
    {
        $o = $this->placed(); $a = $this->admin(); $svc = app(OrderService::class);
        try { $svc->transition($o, 'OUT_FOR_DELIVERY', $a); $this->fail('skip allowed'); } catch (\App\Exceptions\ShopException $e) { $this->assertSame('CONFLICT', $e->errorCode); }
        $svc->transition($o, 'PREPARING', $a);
        try { $svc->transition($o->fresh(), 'PLACED', $a); $this->fail('backwards allowed'); } catch (\App\Exceptions\ShopException) {}
        $svc->transition($o->fresh(), 'OUT_FOR_DELIVERY', $a);
        $o = $svc->transition($o->fresh(), 'DELIVERED', $a);
        $this->assertSame('DELIVERED', $o->status);
        $this->assertSame(['PLACED', 'PREPARING', 'OUT_FOR_DELIVERY', 'DELIVERED'], OrderStatusEvent::orderBy('id')->pluck('to_status')->all());
        $this->assertSame([null, $a->id, $a->id, $a->id], OrderStatusEvent::orderBy('id')->pluck('actor_user_id')->all());
        try { $svc->transition($o, 'CANCELLED', $a, 'late'); $this->fail(); } catch (\App\Exceptions\ShopException) {}
        $this->assertSame(1, AuditLog::where('action', 'order.status_changed')->where('entity_id', (string) $o->id)->where('after->status', 'DELIVERED')->count());
    }

    public function test_showroom_flow(): void
    {
        $o = $this->placed('BULK_ORDER'); $a = $this->admin(); $svc = app(OrderService::class);
        foreach (['PREPARING', 'READY_FOR_COLLECTION', 'COLLECTED'] as $s) $o = $svc->transition($o->fresh(), $s, $a);
        $this->assertSame('COLLECTED', $o->status);
    }

    public function test_manager_cannot_change_orders_or_payments(): void
    {
        $o = $this->placed(); $m = $this->admin('MANAGER');
        foreach ([fn () => app(OrderService::class)->transition($o, 'PREPARING', $m), fn () => app(PaymentService::class)->recordCodCollected($o, $m), fn () => app(PaymentService::class)->void(Payment::first(), $m, 'x')] as $f) {
            try { $f(); $this->fail('manager allowed'); } catch (\App\Exceptions\ShopException $e) { $this->assertSame('FORBIDDEN', $e->errorCode); }
        }
        $this->assertSame('PLACED', $o->fresh()->status);
    }

    public function test_cancel_needs_reason_releases_stock_and_voids_unpaid(): void
    {
        $o = $this->placed(); $a = $this->admin(); $svc = app(OrderService::class);
        $vid = \App\Models\OrderItem::first()->product_variant_id;
        try { $svc->transition($o, 'CANCELLED', $a, '  '); $this->fail(); } catch (\App\Exceptions\ShopException $e) { $this->assertSame('VALIDATION_FAILED', $e->errorCode); }
        $this->assertSame(2, $this->inv($vid)->reserved_quantity);
        $o = $svc->transition($o, 'CANCELLED', $a, 'customer called');
        $this->assertSame(['CANCELLED', 'customer called'], [$o->status, $o->cancel_reason]);
        $this->assertSame(0, $this->inv($vid)->reserved_quantity);
        $this->assertSame('VOID', Payment::first()->status);
        $this->assertSame(0, AuditLog::where('action', 'payment.refund_required')->count());
        $this->assertSame([], \App\Services\OrderingRules::nextStatuses($o->method, $o->status));
    }

    public function test_cancelling_a_paid_order_writes_refund_required(): void
    {
        $v = $this->stocked(1000, 100);
        $this->postJson('/api/orders', $this->body(['_v' => $v, 'payment' => ['method' => 'BKASH', 'trx_id' => 'PAID0001', 'sender_number' => '01812345678']]))->assertCreated();
        $o = Order::first(); $a = $this->admin();
        app(PaymentService::class)->verify(Payment::first(), $a);
        app(OrderService::class)->transition($o, 'CANCELLED', $a, 'out of stock');
        $log = AuditLog::where('action', 'payment.refund_required')->first();
        $this->assertNotNull($log);
        $this->assertSame([2000 + 6000, 'PAID0001'], [$log->after['amount_poisha'], $log->after['trx_id']]);
        $this->assertSame('PAID', Payment::first()->status, 'paid payments are never deleted or silently voided');
    }

    public function test_delivery_commits_stock(): void
    {
        $o = $this->placed(); $a = $this->admin(); $svc = app(OrderService::class);
        $vid = \App\Models\OrderItem::first()->product_variant_id;
        foreach (['PREPARING', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $s) $o = $svc->transition($o->fresh(), $s, $a);
        $row = $this->inv($vid);
        $this->assertSame([98, 0], [$row->quantity_on_hand, $row->reserved_quantity]);
    }

    public function test_warn_about_earlier_cancellations_for_phone(): void
    {
        $a = $this->admin(); $svc = app(OrderService::class);
        $o1 = $this->placed(); $svc->transition($o1, 'CANCELLED', $a, 'no show');
        $o2 = $this->placed();
        $this->assertSame(1, $svc->cancelledCountForPhone($o2->contact_phone, $o2->id));
        $this->assertSame(0, $svc->cancelledCountForPhone('01999999999'));
    }

    /* ───── courier handoff (manual: Steadfast / Pathao / own rider) ───── */

    public function test_staff_record_the_courier_and_the_customer_sees_the_tracking_code(): void
    {
        $o = $this->placed(); $a = $this->admin(); $svc = app(OrderService::class);
        $svc->transition($o, 'PREPARING', $a);
        $o = $svc->setCourier($o->fresh(), $a, 'STEADFAST', ' SF123456 ', 'call before delivery');
        $this->assertSame(['STEADFAST', 'SF123456', 'call before delivery'], [$o->courier, $o->courier_ref, $o->courier_note]);
        $this->assertNotNull($o->dispatched_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.courier_set', 'entity_id' => (string) $o->id]);
        $first = $o->dispatched_at;
        $o = $svc->setCourier($o, $a, 'PATHAO', 'PT-99', null); // correcting keeps the first dispatch time
        $this->assertSame([$first->timestamp, 'PATHAO'], [$o->dispatched_at->timestamp, $o->courier]);

        $r = $this->postJson('/api/orders/track', ['order_number' => $o->order_number, 'phone' => $o->contact_phone])->assertOk();
        $this->assertSame(['PATHAO', 'PT-99'], [$r['courier'], $r['courier_ref']]);
        $this->assertStringNotContainsString('call before', $r->getContent());
    }

    public function test_courier_rules(): void
    {
        $o = $this->placed(); $a = $this->admin(); $svc = app(OrderService::class);
        $code = function (callable $f) { try { $f(); return 'ok'; } catch (\App\Exceptions\ShopException $e) { return $e->errorCode; } };
        $this->assertSame('VALIDATION_FAILED', $code(fn () => $svc->setCourier($o, $a, 'STEADFAST', '', null)), 'tracking code needed for Steadfast');
        $this->assertSame('VALIDATION_FAILED', $code(fn () => $svc->setCourier($o, $a, 'PATHAO', null, null)));
        $this->assertSame('VALIDATION_FAILED', $code(fn () => $svc->setCourier($o, $a, 'DHL', 'x', null)));
        $this->assertSame('ok', $code(fn () => $svc->setCourier($o, $a, 'OWN_RIDER', null, 'Rahim 017...')));
        $this->assertSame('FORBIDDEN', $code(fn () => $svc->setCourier($o, $this->admin('MANAGER'), 'OTHER', null, null)));

        $showroom = $this->placed('BULK_ORDER');
        $this->assertSame('CONFLICT', $code(fn () => $svc->setCourier($showroom, $a, 'OWN_RIDER', null, null)), 'only home delivery goes to a courier');

        $cancelled = $this->placed(); $svc->transition($cancelled, 'CANCELLED', $a, 'x');
        $this->assertSame('CONFLICT', $code(fn () => $svc->setCourier($cancelled->fresh(), $a, 'OWN_RIDER', null, null)));
    }

    public function test_courier_screen_for_admin_and_read_only_for_manager(): void
    {
        $o = $this->placed();
        $admin = $this->admin(); $admin->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->actingAs($admin->fresh())->withSession(['mfa_ok' => true]);
        \Livewire\Livewire::test(\App\Livewire\Admin\OrderShow::class, ['order' => $o])->set('courier', 'STEADFAST')->set('courierRef', 'SF777')->call('saveCourier')->assertSet('flash', __('admin.saved'));
        $this->assertSame('SF777', $o->fresh()->courier_ref);
        $m = $this->admin('MANAGER');
        $this->actingAs($m);
        $this->get('/admin/orders/'.$o->id)->assertOk()->assertSee('SF777')->assertDontSee(__('admin.courier_ref'));
        \Livewire\Livewire::test(\App\Livewire\Admin\OrderShow::class, ['order' => $o->fresh()])->set('courier', 'PATHAO')->set('courierRef', 'HACK')->call('saveCourier')->assertSet('error', fn ($e) => $e !== null);
        $this->assertSame('SF777', $o->fresh()->courier_ref);
    }

    /* ───── payments ───── */

    public function test_verify_bkash_then_due_is_zero_and_second_verify_refused(): void
    {
        $v = $this->stocked(1000, 100);
        $this->postJson('/api/orders', $this->body(['_v' => $v, 'payment' => ['method' => 'BKASH', 'trx_id' => 'V0000001', 'sender_number' => '01812345678']]))->assertCreated();
        $o = Order::first(); $p = Payment::first(); $a = $this->admin(); $svc = app(PaymentService::class);
        $this->assertSame([8000, 'PENDING_VERIFICATION'], [$svc->due($o), $svc->summary($o)]);
        $svc->verify($p, $a);
        $this->assertSame([0, 'PAID'], [$svc->due($o), $svc->summary($o)]);
        $this->assertSame([$a->id], [Payment::first()->verified_by]);
        try { $svc->verify(Payment::first(), $a); $this->fail(); } catch (\App\Exceptions\ShopException $e) { $this->assertSame('CONFLICT', $e->errorCode); }
        $this->assertSame(1, AuditLog::where('action', 'payment.verified')->count());
    }

    public function test_cod_marked_paid_by_staff_only_once(): void
    {
        $o = $this->placed(); $a = $this->admin(); $svc = app(PaymentService::class);
        $this->assertSame('UNPAID', $svc->summary($o));
        $svc->recordCodCollected($o, $a);
        $this->assertSame('PAID', $svc->summary($o->fresh()));
        try { $svc->recordCodCollected($o, $a); $this->fail(); } catch (\App\Exceptions\ShopException) {}
    }

    public function test_cod_cannot_be_verified_like_mobile_money(): void
    {
        $o = $this->placed();
        $this->expectException(\App\Exceptions\ShopException::class);
        app(PaymentService::class)->verify(Payment::first(), $this->admin());
    }

    public function test_void_needs_reason_is_never_a_delete_and_flags_refund_when_paid(): void
    {
        $o = $this->placed(); $a = $this->admin(); $svc = app(PaymentService::class);
        $p = Payment::first();
        try { $svc->void($p, $a, ''); $this->fail(); } catch (\App\Exceptions\ShopException) {}
        $svc->recordCodCollected($o, $a);
        $svc->void(Payment::first(), $a, 'counted wrong');
        $this->assertSame(1, Payment::count());
        $this->assertSame(['VOID', 'counted wrong'], [Payment::first()->status, Payment::first()->void_reason]);
        $this->assertSame(1, AuditLog::where('action', 'payment.refund_required')->count());
        $this->assertSame($o->total_poisha, $svc->due($o->fresh()));
    }
}
