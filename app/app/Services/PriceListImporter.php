<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies a price list (database/data/price-list.json): creates missing products, creates or updates sizes with their
 * prices, publishes priced products, puts new sizes in stock at every active branch, and hides products named in "hide".
 * Safe to re-run. Whatever the admin changes later in the panel is only overwritten for sizes named in the file.
 */
class PriceListImporter
{
    public function __construct(private AuditService $audit, private InventoryService $inventory) {}

    /** @return array{products_created:int, sizes_created:int, sizes_priced:int, published:int, hidden:int, stocked:int} */
    public function apply(array $data): array
    {
        if (empty($data['products']) || ! is_array($data['products'])) throw new InvalidArgumentException('price list has no products');
        $r = ['products_created' => 0, 'sizes_created' => 0, 'sizes_priced' => 0, 'published' => 0, 'hidden' => 0, 'stocked' => 0];

        DB::transaction(function () use ($data, &$r) {
            $outlets = Outlet::where('status', 'ACTIVE')->pluck('id')->all();
            foreach ($data['products'] as $it) {
                $p = Product::where('slug', $it['id'])->first();
                if (! $p) {
                    $cat = Category::where('slug', $it['category'])->first() ?? throw new InvalidArgumentException("unknown category {$it['category']} for {$it['id']}");
                    $p = Product::create(['category_id' => $cat->id, 'slug' => $it['id'], 'name_bn' => $it['nameBn'], 'name_en' => $it['nameEn'], 'name_verified' => false, 'active' => false]);
                    $r['products_created']++;
                }
                $variants = $p->variants()->orderBy('sort_order')->orderBy('id')->get();
                $priced = false;
                foreach ($it['variants'] as $i => $s) {
                    $v = $variants->firstWhere('label_bn', $s['label_bn'])
                        ?? $variants->first(fn ($x) => $x->label_bn === '' && $x->price_poisha === null && ! $x->wasRecentlyCreated);
                    $row = ['label_bn' => $s['label_bn'], 'label_en' => $s['label_en'] ?? null, 'sort_order' => $i];
                    if ($s['price'] !== null) $row['price_poisha'] = (int) round($s['price'] * 100);
                    if ($v) { $v->update($row); $variants = $variants->reject(fn ($x) => $x->id === $v->id); }
                    else { $v = $p->variants()->create($row)->refresh(); $r["sizes_created"]++; }
                    if ($s['price'] !== null) { $r['sizes_priced']++; $priced = true; }
                    if ($v->track_stock) foreach ($outlets as $o) {
                        if (! DB::table('inventory')->where(['outlet_id' => $o, 'product_variant_id' => $v->id])->exists()) {
                            $this->inventory->setStock($o, $v->id, AdminCatalogueService::IN_STOCK_QTY, ['system' => 'price-list'], 'price list import');
                            $r['stocked']++;
                        }
                    }
                }
                if ($priced && ! $p->active) { $p->update(['active' => true]); $r['published']++; }
            }
            foreach ($data['hide'] ?? [] as $slug) {
                $p = Product::where('slug', $slug)->first();
                if ($p && $p->active) { $p->update(['active' => false]); $r['hidden']++; }
            }
            $this->audit->log('prices.imported', 'catalogue', null, null, $r, null, 'CLI');
        });
        return $r;
    }
}
