<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Idempotent import. Creates missing categories (by slug) and products (by slug), each product with ONE blank
 * variant whose price is NULL. Existing rows are never changed, so admin edits survive a re-run.
 * New products are hidden unless $activate is true; an unpriced product cannot be ordered either way.
 */
class CatalogueImporter
{
    public function __construct(private AuditService $audit) {}

    /** @return array{categories_created:int, products_created:int, products_skipped:int} */
    public function import(array $data, bool $activate = false): array
    {
        $v = Validator::make($data, [
            'categories' => 'required|array|min:1',
            'categories.*.slug' => ['required', 'regex:/^[a-z0-9-]{1,80}$/'],
            'categories.*.nameBn' => 'required|string',
            'categories.*.nameEn' => 'required|string',
            'products' => 'required|array|min:1',
            'products.*.id' => ['required', 'regex:/^[a-z0-9-]{1,120}$/'],
            'products.*.category' => 'required|string',
            'products.*.nameBn' => 'required|string',
            'products.*.nameEn' => 'required|string',
            'products.*.nameVerified' => 'required|boolean',
            'products.*.outerCity' => 'nullable|in:approved,disabled,pending_verification,pending_confirmation',
        ]);
        if ($v->fails()) throw new InvalidArgumentException($v->errors()->first());

        $known = array_column($data['categories'], 'slug');
        foreach ($data['products'] as $p) {
            if (! in_array($p['category'], $known, true)) throw new InvalidArgumentException("Product {$p['id']} uses unknown category {$p['category']}");
        }

        return DB::transaction(function () use ($data, $activate) {
            $r = ['categories_created' => 0, 'products_created' => 0, 'products_skipped' => 0];
            $ids = [];
            foreach ($data['categories'] as $i => $c) {
                $found = Category::where('slug', $c['slug'])->first();
                if (! $found) {
                    $found = Category::create(['slug' => $c['slug'], 'name_bn' => $c['nameBn'], 'name_en' => $c['nameEn'], 'sort_order' => $i]);
                    $r['categories_created']++;
                }
                $ids[$c['slug']] = $found->id;
            }
            foreach ($data['products'] as $p) {
                if (Product::where('slug', $p['id'])->exists()) { $r['products_skipped']++; continue; }
                $prod = Product::create([
                    'category_id' => $ids[$p['category']], 'slug' => $p['id'], 'name_bn' => $p['nameBn'], 'name_en' => $p['nameEn'],
                    'name_verified' => $p['nameVerified'], 'outer_city_status' => ! empty($p['outerCity']) ? strtoupper($p['outerCity']) : null,
                    'active' => $activate,
                ]);
                $prod->variants()->create([]);
                $r['products_created']++;
            }
            $this->audit->log('catalogue.imported', 'catalogue', null, null, $r, null, 'CLI');
            return $r;
        });
    }
}
