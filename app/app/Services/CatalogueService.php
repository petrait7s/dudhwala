<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CatalogueService
{
    /** A variant can be ordered only if its product and category are live and a price is set. */
    public function isOrderable(ProductVariant $v): bool
    {
        $p = $v->product()->with('category')->first();
        return $p && $p->active && $p->category->active && $v->price_poisha !== null;
    }

    /**
     * Server-side pricing from ids. Never trusts browser prices.
     * @param array<int, array{variant_id:int, quantity:int}> $cart
     * @return array<int, array{variant: ProductVariant, product: Product, quantity: int, unit_poisha: int, line_poisha: int}>
     * @throws ShopException UNPRICED, UNAVAILABLE, QTY_LIMIT, VALIDATION_FAILED
     */
    public function priceCart(array $cart): array
    {
        if ($cart === []) throw new ShopException('VALIDATION_FAILED', 'Cart is empty');
        $merged = [];
        foreach ($cart as $c) {
            $q = $c['quantity'] ?? 0;
            if (! is_int($q) || $q < 1) throw new ShopException('VALIDATION_FAILED', 'Quantity must be a whole number of at least 1');
            $merged[$c['variant_id']] = ($merged[$c['variant_id']] ?? 0) + $q;
        }
        $variants = ProductVariant::with('product.category')->whereIn('id', array_keys($merged))->get()->keyBy('id');
        $out = [];
        foreach ($merged as $vid => $qty) {
            $v = $variants[$vid] ?? throw new ShopException('UNAVAILABLE', 'Product not found', ['variant_id' => $vid]);
            $p = $v->product;
            if (! $p->active || ! $p->category->active) throw new ShopException('UNAVAILABLE', 'Product not available', ['variant_id' => $vid]);
            if ($v->price_poisha === null) throw new ShopException('UNPRICED', 'Price not set yet', ['variant_id' => $vid]);
            if ($qty > $v->max_qty) throw new ShopException('QTY_LIMIT', "Maximum {$v->max_qty} per order", ['variant_id' => $vid]);
            $out[] = ['variant' => $v, 'product' => $p, 'quantity' => $qty, 'unit_poisha' => $v->price_poisha, 'line_poisha' => Money::multiply($v->price_poisha, $qty)];
        }
        return $out;
    }

    /** A variant with orders or stock history cannot be removed. */
    public function deleteVariant(ProductVariant $v): void
    {
        $used = DB::table('order_items')->where('product_variant_id', $v->id)->exists()
            || DB::table('stock_movements')->where('product_variant_id', $v->id)->exists();
        if ($used) throw new ShopException('CONFLICT', 'Variant has orders or stock history and cannot be removed');
        $v->delete();
    }

    public function setPrice(ProductVariant $v, ?int $poisha): void
    {
        if ($poisha !== null) Money::assertPoisha($poisha, 'price');
        $v->update(['price_poisha' => $poisha]);
    }

    /** Publish (show to customers). Priced variants are not required to publish; unpriced ones stay unorderable. */
    public function setActive(Product $p, bool $active): void
    {
        $p->update(['active' => $active]);
    }

    /* ───────────── search (Bangla + English, alias-aware) ───────────── */

    /** @return string[] lower-cased search terms: the query plus every alias of any alias group it hits. */
    public function expandQuery(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') return [];
        $terms = [$q];
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        foreach (config('search_aliases') as $group) {
            $g = array_map('mb_strtolower', $group);
            foreach (array_merge([$q], $words) as $w) {
                if (in_array($w, $g, true)) { $terms = array_merge($terms, $g); break; }
            }
        }
        return array_values(array_unique($terms));
    }

    /** Active products whose name or category matches the query or an alias. */
    public function search(string $q, int $limit = 50): \Illuminate\Support\Collection
    {
        $terms = $this->expandQuery($q);
        if ($terms === []) return collect();
        return Product::query()->where('products.active', true)
            ->join('categories', 'categories.id', '=', 'products.category_id')->where('categories.active', true)
            ->where(function (Builder $w) use ($terms) {
                foreach ($terms as $t) {
                    $like = '%'.addcslashes($t, '%_\\').'%';
                    $w->orWhere('products.name_bn', 'like', $like)->orWhere('products.name_en', 'like', $like)
                      ->orWhere('products.slug', 'like', $like)->orWhere('categories.name_bn', 'like', $like)->orWhere('categories.name_en', 'like', $like);
                }
            })
            ->select('products.*')->limit($limit)->get();
    }
}
