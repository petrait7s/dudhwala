<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Everything the admin can change in the shop. ADMIN only; every change writes an audit row with before/after.
 * Throws ShopException(VALIDATION_FAILED | FORBIDDEN | CONFLICT).
 */
class AdminCatalogueService
{
    public const OUTER = ['APPROVED', 'DISABLED', 'PENDING_VERIFICATION', 'PENDING_CONFIRMATION'];

    public function __construct(
        private AuditService $audit,
        private CatalogueService $catalogue,
        private InventoryService $inventory,
        private ProductImageService $images,
        private SettingsService $settings,
    ) {}

    private function admin(User $u): void
    {
        if (! $u->isActive() || ! $u->isAdmin()) throw new ShopException('FORBIDDEN', 'Only an admin can change this');
    }

    private function validate(array $data, array $rules): array
    {
        $v = Validator::make($data, $rules);
        if ($v->fails()) throw new ShopException('VALIDATION_FAILED', $v->errors()->first(), ['fields' => array_keys($v->errors()->toArray())]);
        return $v->validated();
    }

    /* ───────── products ───────── */

    public function saveProduct(User $by, ?Product $p, array $in): Product
    {
        $this->admin($by);
        $d = $this->validate($in, [
            'category_id' => 'required|integer|exists:categories,id',
            'slug' => ['required', 'regex:/^[a-z0-9-]{1,120}$/', 'unique:products,slug'.($p ? ','.$p->id : '')],
            'name_bn' => 'required|string|max:255', 'name_en' => 'nullable|string|max:255',
            'name_verified' => 'boolean',
            'outer_city_status' => 'nullable|in:'.implode(',', self::OUTER),
            'description_bn' => 'nullable|string|max:5000', 'ingredients_bn' => 'nullable|string|max:5000',
            'allergens_bn' => 'nullable|string|max:5000', 'storage_bn' => 'nullable|string|max:5000', 'shelf_life_bn' => 'nullable|string|max:5000',
        ]);
        $d['outer_city_status'] = ($d['outer_city_status'] ?? null) ?: null;
        $before = $p?->only(array_keys($d));
        $p = DB::transaction(function () use ($p, $d) {
            if ($p) { $p->update($d); return $p; }
            $new = Product::create($d + ['active' => false]);
            $new->variants()->create([]); // one blank, unpriced option
            return $new;
        });
        $this->audit->log($before ? 'product.updated' : 'product.created', 'product', $p->id, $before, $p->only(array_keys($d)), $by);
        return $p;
    }

    public function setActive(User $by, Product $p, bool $active): void
    {
        $this->admin($by);
        $before = $p->active;
        $this->catalogue->setActive($p, $active);
        $this->audit->log($active ? 'product.published' : 'product.hidden', 'product', $p->id, ['active' => $before], ['active' => $active], $by);
    }

    public function uploadImage(User $by, Product $p, UploadedFile $file): void
    {
        $this->admin($by);
        $old = $p->image_path;
        $path = $this->images->store($p, $file);
        $this->audit->log('product.image_changed', 'product', $p->id, ['image' => $old], ['image' => $path], $by);
    }

    public function addGalleryImage(User $by, Product $p, UploadedFile $file): void
    {
        $this->admin($by);
        $img = $this->images->addExtra($p, $file);
        $this->audit->log('product.gallery_added', 'product', $p->id, null, ['path' => $img->path], $by);
    }

    public function removeGalleryImage(User $by, Product $p, int $imageId): void
    {
        $this->admin($by);
        $img = \App\Models\ProductImage::where('product_id', $p->id)->findOrFail($imageId);
        $path = $img->path;
        $this->images->removeExtra($img);
        $this->audit->log('product.gallery_removed', 'product', $p->id, ['path' => $path], null, $by);
    }

    /* ───────── variants ───────── */

    /** price: taka string ("250", "250.50", "") or null = not priced. */
    public function saveVariant(User $by, Product $p, ?ProductVariant $v, array $in): ProductVariant
    {
        $this->admin($by);
        if ($v && $v->product_id !== $p->id) throw new ShopException('VALIDATION_FAILED', 'Variant does not belong to this product');
        $d = $this->validate($in, [
            'label_bn' => 'nullable|string|max:255', 'label_en' => 'nullable|string|max:255',
            'price' => 'nullable|string|max:20', 'compare_at' => 'nullable|string|max:20',
            'max_qty' => 'required|integer|min:1|max:1000', 'track_stock' => 'boolean', 'sort_order' => 'nullable|integer|min:0|max:10000',
        ]);
        try {
            $price = filled($d['price'] ?? null) ? Money::parseTaka($d['price']) : null;
            $cmp = filled($d['compare_at'] ?? null) ? Money::parseTaka($d['compare_at']) : null;
        } catch (\InvalidArgumentException) {
            throw new ShopException('VALIDATION_FAILED', 'Price must be a number such as 250 or 250.50', ['fields' => ['price']]);
        }
        if ($price !== null && $price <= 0) throw new ShopException('VALIDATION_FAILED', 'Price must be more than zero', ['fields' => ['price']]);
        if ($price !== null && $cmp !== null && $cmp < $price) throw new ShopException('VALIDATION_FAILED', 'Compare-at price must not be below the price', ['fields' => ['compare_at']]);

        $row = ['label_bn' => $d['label_bn'] ?? '', 'label_en' => $d['label_en'] ?? null, 'price_poisha' => $price, 'compare_at_poisha' => $cmp,
            'max_qty' => $d['max_qty'], 'track_stock' => (bool) ($d['track_stock'] ?? true), 'sort_order' => $d['sort_order'] ?? 0];
        $before = $v?->only(array_keys($row));
        $v = $v ? tap($v)->update($row) : $p->variants()->create($row);
        $this->audit->log($before ? 'variant.updated' : 'variant.created', 'product_variant', $v->id, $before, $row, $by);
        return $v;
    }

    public function deleteVariant(User $by, ProductVariant $v): void
    {
        $this->admin($by);
        $snap = $v->only(['product_id', 'label_bn', 'price_poisha']);
        $this->catalogue->deleteVariant($v); // refuses when orders or stock history exist
        $this->audit->log('variant.deleted', 'product_variant', $v->id, $snap, null, $by);
    }

    public function setStock(User $by, int $outletId, ProductVariant $v, int $qty, string $reason): void
    {
        $this->admin($by);
        Outlet::findOrFail($outletId);
        $before = DB::table('inventory')->where(['outlet_id' => $outletId, 'product_variant_id' => $v->id])->value('quantity_on_hand');
        $this->inventory->setStock($outletId, $v->id, $qty, ['user' => $by->id], $reason);
        $this->audit->log('stock.set', 'product_variant', $v->id, ['outlet_id' => $outletId, 'on_hand' => $before], ['outlet_id' => $outletId, 'on_hand' => $qty, 'reason' => $reason], $by);
    }

    /** Largest quantity the sheet writes for "in stock". Customers never see counts; this only keeps the reservation ledger working. */
    public const IN_STOCK_QTY = 100000;

    /** In stock / out of stock for one branch. In: plenty on hand above what open orders hold. Out: only what open orders hold. */
    public function setAvailability(User $by, int $outletId, ProductVariant $v, bool $in): void
    {
        $this->admin($by);
        Outlet::findOrFail($outletId);
        $cur = DB::table('inventory')->where(['outlet_id' => $outletId, 'product_variant_id' => $v->id])->first();
        $reserved = (int) ($cur->reserved_quantity ?? 0);
        $avail = $cur ? $cur->quantity_on_hand - $reserved : 0;
        if (($avail > 0) === $in) return; // already in that state
        $this->setStock($by, $outletId, $v, $in ? $reserved + self::IN_STOCK_QTY : $reserved, $in ? 'in stock' : 'out of stock');
    }

    /**
     * Apply many sheet edits at once: label, price, shown on the site, in/out of stock per branch, and brand-new sizes
     * (variant_id 0 plus product_id). All or nothing. Each change is audited by the single-purpose methods it calls.
     * @param array<int, array{variant_id:int, product_id?:int, key?:string, label_bn?:string, label_en?:string, price?:string, active?:bool, stock?:array<int,bool>}> $changes
     * @return array{n:int, created:array<string,int>} number of changes and the ids given to new sizes, by the row key the caller sent
     */
    public function saveSheet(User $by, array $changes): array
    {
        $this->admin($by);
        $n = 0; $created = [];
        $fmt = fn ($p) => $p === null ? '' : rtrim(rtrim(number_format($p / 100, 2, '.', ''), '0'), '.');
        DB::transaction(function () use ($by, $changes, &$n, &$created, $fmt) {
            foreach ($changes as $c) {
                $id = (int) ($c['variant_id'] ?? 0);
                if ($id === 0) {
                    $p = Product::findOrFail((int) ($c['product_id'] ?? 0));
                    $v = $this->saveVariant($by, $p, null, [
                        'label_bn' => $c['label_bn'] ?? '', 'label_en' => $c['label_en'] ?? '', 'price' => trim((string) ($c['price'] ?? '')), 'compare_at' => '',
                        'max_qty' => 20, 'track_stock' => true, 'sort_order' => (int) $p->variants()->max('sort_order') + 1,
                    ]);
                    if (isset($c['key'])) $created[(string) $c['key']] = $v->id;
                    $n++;
                } else {
                    $v = ProductVariant::with('product')->findOrFail($id);
                    $p = $v->product;
                    if (array_key_exists('label_bn', $c) || array_key_exists('label_en', $c) || array_key_exists('price', $c)) {
                        $this->saveVariant($by, $p, $v, [
                            'label_bn' => $c['label_bn'] ?? $v->label_bn, 'label_en' => $c['label_en'] ?? $v->label_en,
                            'price' => array_key_exists('price', $c) ? trim((string) $c['price']) : $fmt($v->price_poisha),
                            'compare_at' => $fmt($v->compare_at_poisha), 'max_qty' => $v->max_qty, 'track_stock' => $v->track_stock, 'sort_order' => $v->sort_order,
                        ]);
                        $n++;
                    }
                }
                if (array_key_exists('active', $c) && (bool) $c['active'] !== (bool) $p->active) { $this->setActive($by, $p, (bool) $c['active']); $n++; }
                foreach (($c['stock'] ?? []) as $outletId => $in) { $this->setAvailability($by, (int) $outletId, $v, (bool) $in); $n++; }
            }
        });
        return ['n' => $n, 'created' => $created];
    }

    /* ───────── categories ───────── */

    public function saveCategory(User $by, ?Category $c, array $in): Category
    {
        $this->admin($by);
        $d = $this->validate($in, [
            'slug' => ['required', 'regex:/^[a-z0-9-]{1,80}$/', 'unique:categories,slug'.($c ? ','.$c->id : '')],
            'name_bn' => 'required|string|max:255', 'name_en' => 'nullable|string|max:255', 'sort_order' => 'nullable|integer|min:0|max:10000', 'active' => 'boolean',
        ]);
        $d['sort_order'] ??= 0; $d['active'] = (bool) ($d['active'] ?? true);
        $before = $c?->only(array_keys($d));
        $c = $c ? tap($c)->update($d) : Category::create($d);
        $this->audit->log($before ? 'category.updated' : 'category.created', 'category', $c->id, $before, $d, $by);
        return $c;
    }

    public function deleteCategory(User $by, Category $c): void
    {
        $this->admin($by);
        if (Product::where('category_id', $c->id)->exists()) throw new ShopException('CONFLICT', 'Category has products. Hide it instead.');
        $snap = $c->only(['slug', 'name_bn']);
        $c->delete();
        $this->audit->log('category.deleted', 'category', $c->id, $snap, null, $by);
    }

    /* ───────── outlets ───────── */

    /** @param array<int, array{weekday:int, opens:string, closes:string}> $hours */
    public function saveOutlet(User $by, ?Outlet $o, array $in, ?array $hours = null): Outlet
    {
        $this->admin($by);
        $d = $this->validate($in, [
            'code' => ['required', 'regex:/^[A-Z0-9-]{2,32}$/', 'unique:outlets,code'.($o ? ','.$o->id : '')],
            'name_bn' => 'required|string|max:255', 'name_en' => 'nullable|string|max:255', 'address_line' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20', 'status' => 'required|in:ACTIVE,PAUSED,CLOSED',
        ]);
        if ($hours !== null) {
            $hv = $this->validate(['h' => $hours], [
                'h' => 'array|max:7', 'h.*.weekday' => 'required|integer|between:0,6|distinct',
                'h.*.opens' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'h.*.closes' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            ])['h'];
            foreach ($hv as $h) if ($h['opens'] >= $h['closes']) throw new ShopException('VALIDATION_FAILED', 'Opening time must be before closing time');
        }
        $closing = $o && $o->status === 'ACTIVE' && $d['status'] !== 'ACTIVE';
        if ($closing && in_array($o->id, [$this->settings->get('fulfilment.dispatch_outlet_id'), $this->settings->get('bulk.collection_outlet_id')], true)) {
            throw new ShopException('CONFLICT', 'This outlet is the dispatch or showroom collection outlet. Change that in Settings first.');
        }
        $before = $o?->only(array_keys($d));
        DB::transaction(function () use (&$o, $d, $hours) {
            $o = $o ? tap($o)->update($d) : Outlet::create($d);
            if ($hours !== null) {
                $o->hours()->delete();
                foreach ($hours as $h) $o->hours()->create(['weekday' => $h['weekday'], 'opens_at' => $h['opens'], 'closes_at' => $h['closes']]);
            }
        });
        $this->audit->log($before ? 'outlet.updated' : 'outlet.created', 'outlet', $o->id, $before, $d + ($hours !== null ? ['hours' => $hours] : []), $by);
        return $o;
    }

    /* ───────── settings ───────── */

    /** @param array<string, mixed> $values registry key => new value; only the keys given are changed */
    public function saveSettings(User $by, array $values): void
    {
        $this->admin($by);
        DB::transaction(function () use ($by, $values) {
            foreach ($values as $key => $val) {
                $before = $this->settings->get($key);
                if ($before === $val) continue;
                try { $this->settings->set($key, $val, $by->id); }
                catch (\InvalidArgumentException $e) { throw new ShopException('VALIDATION_FAILED', $e->getMessage(), ['fields' => [$key]]); }
                if (in_array($key, ['fulfilment.dispatch_outlet_id', 'bulk.collection_outlet_id'], true) && $val !== null && ! Outlet::where('id', $val)->where('status', 'ACTIVE')->exists()) {
                    throw new ShopException('VALIDATION_FAILED', 'Choose an active outlet', ['fields' => [$key]]);
                }
                $this->audit->log('setting.changed', 'setting', $key, ['value' => $before], ['value' => $val], $by);
            }
        });
    }
}
