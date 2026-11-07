<?php

namespace Tests\Feature;

use App\Exceptions\ShopException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminCatalogueService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class AdminCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private AdminCatalogueService $svc;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AdminCatalogueService::class);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@x.test', 'role' => 'ADMIN', 'password' => 'long-enough-pass'])->fresh();
    }

    private function manager(): User { return User::create(['name' => 'M', 'email' => 'm@x.test', 'role' => 'MANAGER', 'password' => 'long-enough-pass'])->fresh(); }
    private function cat(): Category { return Category::create(['slug' => 'bakery', 'name_bn' => 'বেকারি']); }
    private function expectCode(string $code, callable $f): void
    {
        try { $f(); $this->fail("expected $code"); } catch (ShopException $e) { $this->assertSame($code, $e->errorCode, $e->getMessage()); }
    }

    public function test_manager_can_change_nothing(): void
    {
        $m = $this->manager(); $c = $this->cat();
        $this->expectCode('FORBIDDEN', fn () => $this->svc->saveProduct($m, null, ['category_id' => $c->id, 'slug' => 'x', 'name_bn' => 'x']));
        $this->expectCode('FORBIDDEN', fn () => $this->svc->saveCategory($m, null, ['slug' => 'y', 'name_bn' => 'y']));
        $this->expectCode('FORBIDDEN', fn () => $this->svc->saveSettings($m, ['site.maintenance' => true]));
        $this->expectCode('FORBIDDEN', fn () => $this->svc->saveOutlet($m, null, ['code' => 'AB', 'name_bn' => 'x', 'address_line' => 'y', 'status' => 'ACTIVE']));
        $this->assertSame(0, Product::count());
    }

    public function test_create_product_is_hidden_with_one_blank_variant_and_audited(): void
    {
        $c = $this->cat();
        $p = $this->svc->saveProduct($this->admin, null, ['category_id' => $c->id, 'slug' => 'ghee-1', 'name_bn' => 'ঘি', 'name_en' => 'Ghee', 'outer_city_status' => '']);
        $this->assertFalse($p->active);
        $this->assertNull($p->outer_city_status);
        $this->assertSame(1, $p->variants()->count());
        $this->assertNull($p->variants()->first()->price_poisha);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'entity_id' => (string) $p->id, 'actor_user_id' => $this->admin->id]);
    }

    public function test_product_validation(): void
    {
        $c = $this->cat();
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveProduct($this->admin, null, ['category_id' => 999, 'slug' => 'x', 'name_bn' => 'x']));
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveProduct($this->admin, null, ['category_id' => $c->id, 'slug' => 'Bad Slug', 'name_bn' => 'x']));
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveProduct($this->admin, null, ['category_id' => $c->id, 'slug' => 'ok', 'name_bn' => 'x', 'outer_city_status' => 'MAYBE']));
        $this->svc->saveProduct($this->admin, null, ['category_id' => $c->id, 'slug' => 'dup', 'name_bn' => 'x']);
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveProduct($this->admin, null, ['category_id' => $c->id, 'slug' => 'dup', 'name_bn' => 'y']));
    }

    public function test_edit_product_logs_before_and_after(): void
    {
        $c = $this->cat();
        $p = $this->svc->saveProduct($this->admin, null, ['category_id' => $c->id, 'slug' => 'a', 'name_bn' => 'পুরনো']);
        $this->svc->saveProduct($this->admin, $p, ['category_id' => $c->id, 'slug' => 'a', 'name_bn' => 'নতুন', 'outer_city_status' => 'APPROVED']);
        $log = AuditLog::where('action', 'product.updated')->first();
        $this->assertSame('পুরনো', $log->before['name_bn']);
        $this->assertSame(['নতুন', 'APPROVED'], [$log->after['name_bn'], $log->after['outer_city_status']]);
        $this->assertSame('APPROVED', $p->fresh()->outer_city_status);
    }

    public function test_publish_and_hide(): void
    {
        $v = TestData::variant(1000, 'bakery', false);
        $this->svc->setActive($this->admin, $v->product, true);
        $this->assertTrue($v->product->fresh()->active);
        $this->svc->setActive($this->admin, $v->product, false);
        $this->assertSame(['product.published', 'product.hidden'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_variant_price_parsing_and_rules(): void
    {
        $v = TestData::variant(1000); $p = $v->product;
        $base = ['label_bn' => '১ কেজি', 'max_qty' => 10, 'track_stock' => true];
        $this->svc->saveVariant($this->admin, $p, $v, $base + ['price' => '250.50', 'compare_at' => '300']);
        $this->assertSame([25050, 30000], [$v->fresh()->price_poisha, $v->fresh()->compare_at_poisha]);
        $this->svc->saveVariant($this->admin, $p, $v, $base + ['price' => '']);
        $this->assertNull($v->fresh()->price_poisha, 'blank = not priced');
        foreach (['abc', '0', '12.345', '-5'] as $bad) $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveVariant($this->admin, $p, $v, $base + ['price' => $bad]));
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveVariant($this->admin, $p, $v, $base + ['price' => '300', 'compare_at' => '200']));
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveVariant($this->admin, $p, $v, ['price' => '10', 'max_qty' => 0]));
        $other = TestData::variant();
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveVariant($this->admin, $p, $other, $base + ['price' => '10']));
        $n = $this->svc->saveVariant($this->admin, $p, null, $base + ['price' => '99']);
        $this->assertSame(9900, $n->price_poisha);
        $this->assertSame(2, $p->variants()->count());
    }

    public function test_variant_with_stock_history_cannot_be_deleted_and_stock_is_audited(): void
    {
        $o = TestData::outlet(); $v = TestData::variant();
        $this->svc->setStock($this->admin, $o->id, $v, 12, 'opening count');
        $this->assertSame(12, DB::table('inventory')->value('quantity_on_hand'));
        $m = \App\Models\StockMovement::first();
        $this->assertSame([$this->admin->id, 'opening count'], [$m->actor_user_id, $m->reason]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.set']);
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->setStock($this->admin, $o->id, $v, 5, ' '));
        $this->expectCode('CONFLICT', fn () => $this->svc->deleteVariant($this->admin, $v));
        $clean = TestData::variant();
        $this->svc->deleteVariant($this->admin, $clean);
        $this->assertDatabaseHas('audit_logs', ['action' => 'variant.deleted']);
    }

    public function test_image_upload_is_audited(): void
    {
        Storage::fake('public');
        $p = TestData::variant()->product;
        $this->svc->uploadImage($this->admin, $p, UploadedFile::fake()->image('a.jpg', 400, 400));
        $this->assertNotNull($p->fresh()->image_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.image_changed']);
        $this->expectCode('FORBIDDEN', fn () => $this->svc->uploadImage($this->manager(), $p, UploadedFile::fake()->image('b.jpg')));
    }

    public function test_categories(): void
    {
        $c = $this->svc->saveCategory($this->admin, null, ['slug' => 'sweets', 'name_bn' => 'মিষ্টি', 'active' => true]);
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveCategory($this->admin, null, ['slug' => 'sweets', 'name_bn' => 'x']));
        $this->svc->saveCategory($this->admin, $c, ['slug' => 'sweets', 'name_bn' => 'মিষ্টি', 'active' => false]);
        $this->assertFalse($c->fresh()->active);
        TestData::variant(100, 'sweets');
        $this->expectCode('CONFLICT', fn () => $this->svc->deleteCategory($this->admin, $c));
        $empty = $this->svc->saveCategory($this->admin, null, ['slug' => 'empty', 'name_bn' => 'x']);
        $this->svc->deleteCategory($this->admin, $empty);
        $this->assertNull(Category::find($empty->id));
    }

    public function test_outlet_hours_and_guard_on_closing_the_dispatch_outlet(): void
    {
        $o = $this->svc->saveOutlet($this->admin, null, ['code' => 'MAIN', 'name_bn' => 'প্রধান', 'address_line' => 'সিলেট', 'status' => 'ACTIVE'], [['weekday' => 0, 'opens' => '10:00', 'closes' => '22:00'], ['weekday' => 1, 'opens' => '10:00', 'closes' => '22:00']]);
        $this->assertSame(2, $o->hours()->count());
        $this->svc->saveOutlet($this->admin, $o, ['code' => 'MAIN', 'name_bn' => 'প্রধান', 'address_line' => 'সিলেট', 'status' => 'ACTIVE'], [['weekday' => 3, 'opens' => '09:00', 'closes' => '20:00']]);
        $this->assertSame([3], $o->hours()->pluck('weekday')->all());
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveOutlet($this->admin, $o, ['code' => 'MAIN', 'name_bn' => 'x', 'address_line' => 'y', 'status' => 'ACTIVE'], [['weekday' => 1, 'opens' => '22:00', 'closes' => '10:00']]));
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveOutlet($this->admin, $o, ['code' => 'MAIN', 'name_bn' => 'x', 'address_line' => 'y', 'status' => 'ACTIVE'], [['weekday' => 1, 'opens' => '09:00', 'closes' => '10:00'], ['weekday' => 1, 'opens' => '11:00', 'closes' => '12:00']]));
        app(SettingsService::class)->set('fulfilment.dispatch_outlet_id', $o->id);
        $this->expectCode('CONFLICT', fn () => $this->svc->saveOutlet($this->admin, $o, ['code' => 'MAIN', 'name_bn' => 'প্রধান', 'address_line' => 'সিলেট', 'status' => 'PAUSED']));
        $this->assertSame('ACTIVE', $o->fresh()->status);
    }

    public function test_settings_save_validates_audits_and_is_atomic(): void
    {
        $o = $this->svc->saveOutlet($this->admin, null, ['code' => 'MAIN', 'name_bn' => 'প্রধান', 'address_line' => 'x', 'status' => 'ACTIVE']);
        $s = app(SettingsService::class);
        $this->svc->saveSettings($this->admin, [
            'delivery.fees' => ['IN_CITY' => 6000, 'OUTER_CITY' => 12000],
            'bulk.rule' => ['min_total_quantity' => 15, 'min_total_poisha' => 1500000, 'approved' => true],
            'payment.numbers' => ['bkash' => '01700000000', 'nagad' => '01800000000'],
            'fulfilment.dispatch_outlet_id' => $o->id, 'bulk.collection_outlet_id' => $o->id, 'bulk.prepaid' => false,
        ]);
        $this->assertSame(12000, $s->get('delivery.fees')['OUTER_CITY']);
        $this->assertTrue($s->get('bulk.rule')['approved']);
        $this->assertSame(6, AuditLog::where('action', 'setting.changed')->count());
        $log = AuditLog::where('entity_id', 'delivery.fees')->first();
        $this->assertSame(0, $log->before['value']['IN_CITY']);

        // unchanged value writes no audit row
        $n = AuditLog::count();
        $this->svc->saveSettings($this->admin, ['bulk.prepaid' => false]);
        $this->assertSame($n, AuditLog::count());

        // one bad value => nothing from this save is kept
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveSettings($this->admin, ['site.maintenance' => true, 'ordering.hours' => ['open_minute' => 900, 'close_minute' => 600]]));
        $this->assertFalse($s->get('site.maintenance'));
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveSettings($this->admin, ['fulfilment.dispatch_outlet_id' => 999]));
    }

    public function test_content_pages_setting_and_public_endpoint(): void
    {
        $pages = [['slug' => 'delivery-policy', 'title_bn' => 'ডেলিভারি নীতি', 'title_en' => 'Delivery policy', 'body_bn' => "প্রথম\n\nদ্বিতীয়", 'body_en' => null]];
        $this->svc->saveSettings($this->admin, ['content.pages' => $pages]);
        $this->getJson('/api/pages')->assertOk()->assertJsonPath('pages.0.slug', 'delivery-policy');
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveSettings($this->admin, ['content.pages' => [['slug' => 'Bad Slug', 'title_bn' => 'x', 'body_bn' => 'y']]]));
        $this->expectCode('VALIDATION_FAILED', fn () => $this->svc->saveSettings($this->admin, ['content.pages' => [$pages[0], $pages[0]]]));
        $this->assertSame([], $this->getJson('/api/pages')->json('pages.1') ?? []);
    }
}
