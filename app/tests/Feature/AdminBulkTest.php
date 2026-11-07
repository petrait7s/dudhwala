<?php

namespace Tests\Feature;

use App\Exceptions\ShopException;
use App\Livewire\Admin;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AdminBulkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('dudhwala:setup', ['--file' => database_path('data/owner-branches.json')]);
        $this->artisan('dudhwala:import-catalogue');
        $this->admin = User::create(['name' => 'A', 'email' => 'a@x.test', 'role' => 'ADMIN', 'password' => 'long-enough-pass', 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->fresh();
    }

    private function rows(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv))));
        return array_map('str_getcsv', $lines);
    }

    private function toCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'w+'); foreach ($rows as $r) fputcsv($fh, $r); rewind($fh);
        return "\xEF\xBB\xBF".stream_get_contents($fh);
    }

    public function test_export_has_every_variant_and_a_stock_column_per_branch(): void
    {
        $rows = $this->rows(app(AdminBulkService::class)->exportCsv());
        $head = $rows[0];
        $this->assertSame(array_merge(AdminBulkService::FIXED, ['stock:MODINA-MARKET', 'stock:SUPPLY-ROAD', 'stock:MIRJAJANGAL', 'stock:UPOSHOHOR']), $head);
        $this->assertCount(1 + 59, $rows);
        $this->assertSame(['', 'no', 'default'], [$rows[1][7], $rows[1][9], $rows[1][10] === 'default' ? 'default' : $rows[1][10]]);
    }

    public function test_spreadsheet_round_trip_sets_prices_stock_visibility_and_outer_status(): void
    {
        $svc = app(AdminBulkService::class);
        $rows = $this->rows($svc->exportCsv());
        $h = array_flip($rows[0]);
        $rows[1][$h['price_taka']] = '250'; $rows[1][$h['visible']] = 'yes'; $rows[1][$h['stock:MODINA-MARKET']] = '40'; $rows[1][$h['stock:SUPPLY-ROAD']] = '12';
        $rows[2][$h['price_taka']] = '৳১,২৫০.৫০'; $rows[2][$h['visible']] = 'হ্যাঁ'; $rows[2][$h['outer_sylhet']] = 'APPROVED'; $rows[2][$h['max_qty']] = '50';
        $res = $svc->importCsv($this->admin, $this->toCsv($rows));
        $this->assertSame([], $res['errors']);
        $this->assertSame([59, 2, 2, 2], [$res['rows'], $res['changed_variants'], $res['published'], $res['stock_set']]);
        $v1 = ProductVariant::find($rows[1][$h['variant_id']]); $v2 = ProductVariant::find($rows[2][$h['variant_id']]);
        $this->assertSame([25000, true], [$v1->price_poisha, $v1->product->active]);
        $this->assertSame([125050, 50, 'APPROVED', true], [$v2->price_poisha, $v2->max_qty, $v2->product->outer_city_status, $v2->product->active]);
        $this->assertSame([40, 12], DB::table('inventory')->where('product_variant_id', $v1->id)->orderBy('outlet_id')->pluck('quantity_on_hand')->map(fn ($x) => (int) $x)->all());
        $this->assertSame('Bulk update (CSV)', DB::table('stock_movements')->value('reason'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.bulk_import']);
        // the published, priced products now appear in the public catalogue
        $this->assertCount(2, $this->getJson('/api/catalogue')->json('products'));

        // uploading the same file again changes nothing
        $again = $svc->importCsv($this->admin, $this->toCsv($rows));
        $this->assertSame([0, 0, 0], [$again['changed_variants'], $again['published'], $again['stock_set']]);
    }

    public function test_any_bad_row_saves_nothing_and_lists_the_rows(): void
    {
        $svc = app(AdminBulkService::class);
        $rows = $this->rows($svc->exportCsv());
        $h = array_flip($rows[0]);
        $rows[1][$h['price_taka']] = '300';                 // good
        $rows[3][$h['price_taka']] = 'abc';                 // bad price
        $rows[4][$h['stock:UPOSHOHOR']] = '-5';             // bad stock
        $rows[5][$h['visible']] = 'maybe';                  // bad flag
        $rows[6][$h['variant_id']] = '999999';              // unknown variant
        $res = $svc->importCsv($this->admin, $this->toCsv($rows));
        $this->assertCount(4, $res['errors']);
        $this->assertStringContainsString('Row 4:', $res['errors'][0]);
        $this->assertNull(ProductVariant::find($rows[1][$h['variant_id']])->price_poisha, 'all-or-nothing');
        $this->assertSame(0, DB::table('inventory')->count());
    }

    public function test_wrong_file_and_manager_are_refused(): void
    {
        $svc = app(AdminBulkService::class);
        try { $svc->importCsv($this->admin, "name,price\nx,1\n"); $this->fail(); } catch (ShopException $e) { $this->assertSame('VALIDATION_FAILED', $e->errorCode); }
        $m = User::create(['name' => 'M', 'email' => 'm@x.test', 'role' => 'MANAGER', 'password' => 'long-enough-pass'])->fresh();
        try { $svc->importCsv($m, $svc->exportCsv()); $this->fail(); } catch (ShopException $e) { $this->assertSame('FORBIDDEN', $e->errorCode); }
        $this->actingAs($m)->get('/admin/bulk')->assertForbidden();
    }

    public function test_photos_by_file_name_cover_and_extras(): void
    {
        Storage::fake('public');
        $res = app(AdminBulkService::class)->importPhotos($this->admin, [
            UploadedFile::fake()->image('ds-01.jpg', 800, 600), UploadedFile::fake()->image('DS-01-2.png', 600, 600),
            UploadedFile::fake()->image('bk-bun.webp', 500, 500), UploadedFile::fake()->image('nope-9.jpg', 100, 100),
        ]);
        $this->assertSame([2, 1], [$res['covers'], $res['extras']]);
        $this->assertCount(1, $res['errors']);
        $p = Product::where('slug', 'ds-01')->first();
        $this->assertNotNull($p->image_path);
        $this->assertSame(1, $p->gallery()->count());
        $this->assertNotNull(Product::where('slug', 'bk-bun')->value('image_path'));
    }

    public function test_bulk_screen_download_and_upload(): void
    {
        $this->actingAs($this->admin)->withSession(['mfa_ok' => true]);
        $this->get('/admin/bulk')->assertOk()->assertSee(__('admin.bulk_download'));
        $svc = app(AdminBulkService::class);
        $rows = $this->rows($svc->exportCsv()); $h = array_flip($rows[0]);
        $rows[1][$h['price_taka']] = '199';
        $file = UploadedFile::fake()->createWithContent('products.csv', $this->toCsv($rows));
        Livewire::test(Admin\Bulk::class)->set('csv', $file)->call('import')->assertSet('result.changed_variants', 1)->assertSet('flash', __('admin.saved'));
        $this->assertSame(19900, ProductVariant::find($rows[1][$h['variant_id']])->price_poisha);
        Livewire::test(Admin\Bulk::class)->call('download')->assertFileDownloaded();
    }
}
