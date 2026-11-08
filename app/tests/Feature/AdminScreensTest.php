<?php

namespace Tests\Feature;

use App\Livewire\Admin;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\TestData;
use Tests\TestCase;

class AdminScreensTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role): User
    {
        $u = User::create(['name' => ucfirst(strtolower($role)), 'email' => strtolower($role).'@x.test', 'role' => $role, 'password' => 'long-enough-pass', 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->fresh();
        $this->actingAs($u)->withSession(['mfa_ok' => true]);
        return $u;
    }

    private function keys(array $a, string $p = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) $out = array_merge($out, is_array($v) ? $this->keys($v, $p.$k.'.') : [$p.$k]);
        sort($out);
        return $out;
    }

    public function test_lang_files_have_identical_keys(): void
    {
        $this->assertSame($this->keys(require lang_path('en/admin.php')), $this->keys(require lang_path('bn/admin.php')));
    }

    public function test_manager_pages_and_blocks(): void
    {
        $this->as('MANAGER');
        $v = TestData::variant(5000);
        $this->get('/admin/products')->assertOk();
        $this->get('/admin/products/'.$v->product->id)->assertOk()->assertSee(__('admin.view_only'))->assertDontSee(__('admin.upload'));
        foreach (['products-new', 'categories', 'outlets', 'settings', 'pages', 'audit'] as $p) $this->get('/admin/'.$p)->assertForbidden();
    }

    public function test_manager_cannot_change_a_product_by_calling_livewire_methods(): void
    {
        $this->as('MANAGER');
        $v = TestData::variant(5000);
        $c = Livewire::test(Admin\ProductEdit::class, ['product' => $v->product]);
        $c->set('form.name_bn', 'hacked')->call('saveProduct')->assertSet('error', fn ($e) => str_contains($e, 'Only an admin'));
        $c->set('variants.'.$v->id.'.price', '1')->call('saveVariant', $v->id);
        $this->assertNotSame('hacked', $v->product->fresh()->name_bn);
        $this->assertSame(5000, $v->fresh()->price_poisha);
        $res = Livewire::test(Admin\Products::class)->call('saveSheet', [['variant_id' => $v->id, 'active' => false, 'price' => '1']])->effects['returns'][0] ?? null;
        $this->assertFalse($res['ok'] ?? true);
        $this->assertTrue($v->product->fresh()->active);
        $this->assertSame(5000, $v->fresh()->price_poisha);
    }

    public function test_admin_creates_product_then_prices_and_stocks_it(): void
    {
        $admin = $this->as('ADMIN');
        $cat = Category::create(['slug' => 'bakery', 'name_bn' => 'বেকারি']); $o = TestData::outlet('M');
        $c = Livewire::test(Admin\ProductEdit::class)
            ->set('form.category_id', $cat->id)->set('form.slug', 'new-cake')->set('form.name_bn', 'নতুন কেক')->set('form.name_en', 'New cake')
            ->call('saveProduct');
        $p = Product::where('slug', 'new-cake')->first();
        $this->assertNotNull($p);
        $c->assertRedirect(route('admin.product', $p));

        $c = Livewire::test(Admin\ProductEdit::class, ['product' => $p]);
        $vid = $p->variants()->first()->id;
        $c->set("variants.$vid.price", '320.50')->call('saveVariant', $vid)->assertSet('flash', __('admin.saved'));
        $this->assertSame(32050, $p->variants()->first()->price_poisha);
        $c->set('stockReason', 'opening count')->set("stock.$vid-{$o->id}", '15')->call('saveStock', $vid, $o->id);
        $this->assertSame(15, (int) DB::table('inventory')->value('quantity_on_hand'));
        $c->set("stock.$vid-{$o->id}", 'abc')->call('saveStock', $vid, $o->id)->assertSet('error', fn ($e) => $e !== null);
        $c->call('addVariant')->assertSet('flash', __('admin.saved'));
        $this->assertSame(2, $p->variants()->count());
        $c->set("variants.$vid.price", 'abc')->call('saveVariant', $vid)->assertSet('error', fn ($e) => str_contains($e, 'Price'));
        $c->call('deleteVariant', $vid)->assertSet('error', fn ($e) => str_contains($e, 'cannot be removed'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'variant.updated', 'actor_user_id' => $admin->id]);

        // publish makes it appear in the public catalogue
        $this->getJson('/api/catalogue')->assertJsonMissing(['slug' => 'new-cake']);
        Livewire::test(Admin\Products::class)->call('saveSheet', [['variant_id' => $vid, 'active' => true]]);
        $this->getJson('/api/catalogue')->assertJsonFragment(['slug' => 'new-cake']);
    }

    public function test_product_sheet_lists_every_product_with_sizes_and_branch_availability(): void
    {
        $this->as('ADMIN');
        $a = TestData::variant(1000, 'bakery'); $b = TestData::variant(1000, 'sweets', false);
        $a->product->update(['name_bn' => 'রুটি']); $b->product->update(['name_bn' => 'রসগোল্লা']);
        Livewire::test(Admin\Products::class)->assertViewHas('data', function ($d) {
            $names = collect($d['products'])->pluck('name_bn')->all();
            return in_array('রুটি', $names, true) && in_array('রসগোল্লা', $names, true) && collect($d['products'])->every(fn ($p) => count($p['variants']) >= 1);
        });
    }

    public function test_sheet_saves_price_size_stock_and_visibility_all_or_nothing_without_showing_counts(): void
    {
        $admin = $this->as('ADMIN');
        $v = TestData::variant(5000);
        $o = TestData::outlet('sheet-branch');
        $res = Livewire::test(Admin\Products::class)->call('saveSheet', [[
            'variant_id' => $v->id, 'label_bn' => '২ কেজি', 'label_en' => '2 kg', 'price' => '80', 'active' => false, 'stock' => [$o->id => false],
        ]])->effects['returns'][0];
        $this->assertTrue($res['ok']);
        $v = $v->fresh();
        $this->assertSame([8000, '২ কেজি', '2 kg'], [$v->price_poisha, $v->label_bn, $v->label_en]);
        $this->assertFalse($v->product->fresh()->active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'variant.updated', 'actor_user_id' => $admin->id]);

        // in stock writes a large buffer above what open orders hold; out of stock leaves only the reserved part
        app(\App\Services\AdminCatalogueService::class)->setAvailability($admin, $o->id, $v, true);
        $row = \DB::table('inventory')->where(['outlet_id' => $o->id, 'product_variant_id' => $v->id])->first();
        $this->assertSame(\App\Services\AdminCatalogueService::IN_STOCK_QTY, $row->quantity_on_hand - $row->reserved_quantity);
        app(\App\Services\AdminCatalogueService::class)->setAvailability($admin, $o->id, $v, false);
        $row = \DB::table('inventory')->where(['outlet_id' => $o->id, 'product_variant_id' => $v->id])->first();
        $this->assertSame(0, $row->quantity_on_hand - $row->reserved_quantity);

        // a brand-new size (per kg next to per pc) is created with its price and in-stock branches
        $new = Livewire::test(Admin\Products::class)->call('saveSheet', [[
            'variant_id' => 0, 'product_id' => $v->product_id, 'key' => 'n1', 'label_bn' => '১ কেজি', 'label_en' => '1 kg', 'price' => '600', 'stock' => [$o->id => true],
        ]])->effects['returns'][0];
        $this->assertTrue($new['ok']);
        $kg = \App\Models\ProductVariant::findOrFail($new['created']['n1']);
        $this->assertSame([60000, '১ কেজি', $v->product_id], [$kg->price_poisha, $kg->label_bn, $kg->product_id]);
        $this->assertGreaterThan(0, (int) \DB::table('inventory')->where(['outlet_id' => $o->id, 'product_variant_id' => $kg->id])->value('quantity_on_hand'));

        // one bad row rolls the whole save back
        $bad = Livewire::test(Admin\Products::class)->call('saveSheet', [
            ['variant_id' => $v->id, 'price' => '90'], ['variant_id' => $v->id, 'price' => 'abc'],
        ])->effects['returns'][0];
        $this->assertFalse($bad['ok']);
        $this->assertSame(8000, $v->fresh()->price_poisha);
    }

    public function test_settings_screen_saves_and_approves_bulk_limit(): void
    {
        $this->as('ADMIN');
        $o = TestData::outlet('M');
        $s = app(SettingsService::class);
        $this->assertFalse($s->get('bulk.rule')['approved']);
        $c = Livewire::test(Admin\Settings::class);
        $c->set('f.open', '09:00')->set('f.close', '21:30')->set('f.bulk_qty', 12)->set('f.bulk_taka', '15000')->set('f.fee_in', '60')->set('f.fee_out', '120.50')
            ->set('f.dispatch', (string) $o->id)->set('f.collection', (string) $o->id)->set('f.bkash', '01700000000')->set('f.prepaid', true)->call('save')
            ->assertSet('flash', __('admin.saved'))->assertSet('bulkApproved', true);
        $this->assertSame(['open_minute' => 540, 'close_minute' => 1290], $s->get('ordering.hours'));
        $this->assertSame([12, 1500000, true], array_values($s->get('bulk.rule')));
        $this->assertSame(['IN_CITY' => 6000, 'OUTER_CITY' => 12050], $s->get('delivery.fees'));
        $this->assertSame($o->id, $s->get('fulfilment.dispatch_outlet_id'));
        $this->assertTrue($s->get('bulk.prepaid'));
        // and the storefront sees it
        $this->getJson('/api/config')->assertJsonPath('fees.OUTER_CITY', 12050)->assertJsonPath('bulk.approved', true)->assertJsonPath('hours.open_minute', 540);

        $c->set('f.open', '25:00')->call('save')->assertSet('error', fn ($e) => str_contains($e, 'Hours'));
        $c->set('f.open', '09:00')->set('f.fee_in', 'free')->call('save')->assertSet('error', fn ($e) => str_contains($e, 'Amounts'));
        $this->assertSame(540, $s->get('ordering.hours')['open_minute']);
    }

    public function test_outlets_and_categories_screens(): void
    {
        $this->as('ADMIN');
        $c = Livewire::test(Admin\Outlets::class);
        $c->set('form.code', 'MAIN')->set('form.name_bn', 'প্রধান')->set('form.address_line', 'সিলেট')->set('hours.5.open', false)->call('save')->assertSet('flash', __('admin.saved'));
        $o = Outlet::first();
        $this->assertSame(6, $o->hours()->count());
        $c->call('edit', $o->id)->assertSet('hours.5.open', false)->assertSet('hours.0.opens', '10:00');

        $k = Livewire::test(Admin\Categories::class);
        $k->set('new.slug', 'dairy')->set('new.name_bn', 'দুগ্ধ')->call('create')->assertSet('flash', __('admin.saved'));
        $id = Category::where('slug', 'dairy')->value('id');
        $k->set("rows.$id.active", false)->call('save', $id);
        $this->assertFalse(Category::find($id)->active);
        $k->call('remove', $id);
        $this->assertNull(Category::find($id));
    }

    public function test_content_pages_screen_and_audit_screen(): void
    {
        $admin = $this->as('ADMIN');
        $c = Livewire::test(Admin\ContentPages::class)->call('add')
            ->set('pages.0.slug', 'refund-policy')->set('pages.0.title_bn', 'ফেরত নীতি')->set('pages.0.body_bn', 'লেখা')->call('save')->assertSet('flash', __('admin.saved'));
        $this->getJson('/api/pages')->assertJsonPath('pages.0.slug', 'refund-policy');
        $c->set('pages.0.slug', 'BAD')->call('save')->assertSet('error', fn ($e) => $e !== null);
        Livewire::test(Admin\AuditLogView::class)->assertSee('setting.changed')->assertSee($admin->name)->set('q', 'nomatchzzz')->assertDontSee('setting.changed');
    }
}
