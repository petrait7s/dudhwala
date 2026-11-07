<?php

namespace Tests\Feature;

use App\Exceptions\ShopException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CatalogueService;
use App\Services\InventoryService;
use App\Services\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_59_hidden_unpriced_products_and_is_idempotent(): void
    {
        $this->artisan('dudhwala:import-catalogue')->assertSuccessful();
        $this->assertSame(59, Product::count());
        $this->assertSame(3, Category::count());
        $this->assertSame(0, Product::where('active', true)->count());
        $this->assertSame(59, ProductVariant::whereNull('price_poisha')->count());
        $this->assertSame(59, ProductVariant::count());

        $first = Product::first();
        $first->update(['name_bn' => 'owner edit']);
        ProductVariant::where('product_id', $first->id)->update(['price_poisha' => 5000]);

        $this->artisan('dudhwala:import-catalogue')->assertSuccessful();
        $this->assertSame(59, Product::count());
        $this->assertSame(59, ProductVariant::count());
        $this->assertSame('owner edit', $first->fresh()->name_bn, 'admin edits survive a re-run');
        $this->assertSame(5000, $first->variants()->first()->price_poisha);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.imported']);
    }

    public function test_import_activate_flag_and_outer_city_marks(): void
    {
        $this->artisan('dudhwala:import-catalogue', ['--activate' => true])->assertSuccessful();
        $this->assertSame(59, Product::where('active', true)->count());
        $statuses = Product::pluck('outer_city_status')->filter()->unique()->all();
        foreach ($statuses as $s) $this->assertContains($s, ['APPROVED', 'DISABLED', 'PENDING_VERIFICATION', 'PENDING_CONFIRMATION']);
    }

    public function test_import_rejects_unknown_category(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'c').'.json';
        file_put_contents($f, json_encode(['categories' => [['slug' => 'a', 'nameBn' => 'x', 'nameEn' => 'x']], 'products' => [['id' => 'p', 'category' => 'zz', 'nameBn' => 'x', 'nameEn' => 'x', 'nameVerified' => true]]]));
        $this->artisan('dudhwala:import-catalogue', ['--file' => $f])->assertFailed();
        $this->assertSame(0, Product::count());
    }

    public function test_unpriced_or_hidden_product_cannot_be_ordered(): void
    {
        $svc = app(CatalogueService::class);
        $v = TestData::variant(10000);
        $v->update(['price_poisha' => null]);
        $this->assertFalse($svc->isOrderable($v->fresh()));
        try { $svc->priceCart([['variant_id' => $v->id, 'quantity' => 1]]); $this->fail(); } catch (ShopException $e) { $this->assertSame('UNPRICED', $e->errorCode); }

        $h = TestData::variant(10000, 'bakery', false);
        $this->assertFalse($svc->isOrderable($h));
        try { $svc->priceCart([['variant_id' => $h->id, 'quantity' => 1]]); $this->fail(); } catch (ShopException $e) { $this->assertSame('UNAVAILABLE', $e->errorCode); }

        $svc->setPrice($v, 12000);
        $svc->setActive($v->product, true);
        $this->assertTrue($svc->isOrderable($v->fresh()));
    }

    public function test_inactive_category_hides_products(): void
    {
        $v = TestData::variant(10000, 'seasonal');
        $v->product->category->update(['active' => false]);
        $this->expectException(ShopException::class);
        app(CatalogueService::class)->priceCart([['variant_id' => $v->id, 'quantity' => 1]]);
    }

    public function test_price_cart_is_server_side_and_merges_duplicates(): void
    {
        $a = TestData::variant(10000); $b = TestData::variant(2550);
        $r = app(CatalogueService::class)->priceCart([
            ['variant_id' => $a->id, 'quantity' => 2, 'price' => 1], // browser price ignored
            ['variant_id' => $b->id, 'quantity' => 1],
            ['variant_id' => $a->id, 'quantity' => 1],
        ]);
        $this->assertCount(2, $r);
        $this->assertSame([3, 30000], [$r[0]['quantity'], $r[0]['line_poisha']]);
        $this->assertSame(2550, $r[1]['line_poisha']);
    }

    public function test_quantity_rules(): void
    {
        $v = TestData::variant(100, 'bakery', true, 5);
        $svc = app(CatalogueService::class);
        foreach ([0, -1] as $q) {
            try { $svc->priceCart([['variant_id' => $v->id, 'quantity' => $q]]); $this->fail(); } catch (ShopException $e) { $this->assertSame('VALIDATION_FAILED', $e->errorCode); }
        }
        try { $svc->priceCart([['variant_id' => $v->id, 'quantity' => 6]]); $this->fail(); } catch (ShopException $e) { $this->assertSame('QTY_LIMIT', $e->errorCode); }
        try { $svc->priceCart([]); $this->fail(); } catch (ShopException $e) { $this->assertSame('VALIDATION_FAILED', $e->errorCode); }
        $this->assertSame(500, $svc->priceCart([['variant_id' => $v->id, 'quantity' => 5]])[0]['line_poisha']);
    }

    public function test_variant_with_orders_or_stock_history_cannot_be_removed(): void
    {
        $svc = app(CatalogueService::class);
        $clean = TestData::variant();
        $svc->deleteVariant($clean);
        $this->assertNull(ProductVariant::find($clean->id));

        $o = TestData::outlet();
        $withStock = TestData::variant();
        app(InventoryService::class)->setStock($o->id, $withStock->id, 3, ['system' => 't'], 'init');
        try { $svc->deleteVariant($withStock); $this->fail(); } catch (ShopException $e) { $this->assertSame('CONFLICT', $e->errorCode); }

        $withOrder = TestData::variant();
        $ord = TestData::order($o);
        \App\Models\OrderItem::create(['order_id' => $ord->id, 'product_variant_id' => $withOrder->id, 'product_slug' => 's', 'product_name_bn' => 'x', 'variant_label_bn' => 'y', 'unit_price_poisha' => 100, 'quantity' => 1, 'line_total_poisha' => 100]);
        try { $svc->deleteVariant($withOrder); $this->fail(); } catch (ShopException $e) { $this->assertSame('CONFLICT', $e->errorCode); }
        $this->assertNotNull(ProductVariant::find($withOrder->id));
    }

    public function test_search_in_bangla_and_english_with_aliases(): void
    {
        $this->artisan('dudhwala:import-catalogue', ['--activate' => true])->assertSuccessful();
        $svc = app(CatalogueService::class);
        $this->assertGreaterThan(0, $svc->search('দই')->count());
        $this->assertGreaterThan(0, $svc->search('doi')->count());
        $this->assertEqualsCanonicalizing($svc->search('দই')->pluck('id')->all(), $svc->search('yogurt')->pluck('id')->all());
        $this->assertGreaterThan(0, $svc->search('sandesh')->count());
        $this->assertSame(0, $svc->search('zzzzqqq')->count());
        $this->assertSame(0, $svc->search('  ')->count());
        // hidden products never appear
        Product::query()->update(['active' => false]);
        $this->assertSame(0, $svc->search('doi')->count());
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->artisan('dudhwala:import-catalogue', ['--activate' => true])->assertSuccessful();
        $this->assertSame(0, app(CatalogueService::class)->search('%')->count());
    }

    public function test_image_upload_reencodes_to_webp_and_resizes(): void
    {
        Storage::fake('public');
        $p = TestData::variant()->product;
        $img = UploadedFile::fake()->image('big.png', 2400, 1800);
        $path = app(ProductImageService::class)->store($p, $img);
        Storage::disk('public')->assertExists($path);
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame([1200, 900], [$w, $h]);
        $this->assertSame(1, $p->fresh()->image_version);

        $path2 = app(ProductImageService::class)->store($p->fresh(), UploadedFile::fake()->image('b.jpg', 300, 300));
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(2, $p->fresh()->image_version);
        $this->assertNotSame($path, $path2);
    }

    public function test_image_upload_rejects_non_images(): void
    {
        Storage::fake('public');
        $p = TestData::variant()->product;
        $this->expectException(ShopException::class);
        app(ProductImageService::class)->store($p, UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;'));
    }
}
