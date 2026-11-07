<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Spreadsheet round-trip for the whole catalogue (prices, visibility, Outer Sylhet status, stock per branch)
 * and bulk photo upload by file name. ADMIN only; all-or-nothing; every change goes through the normal
 * audited services.
 */
class AdminBulkService
{
    public const FIXED = ['product_slug', 'product_name_bn', 'product_name_en', 'category', 'variant_id', 'variant_label_bn', 'variant_label_en', 'price_taka', 'max_qty', 'visible', 'outer_sylhet'];
    public const OUTER = ['default', 'APPROVED', 'DISABLED', 'PENDING_VERIFICATION', 'PENDING_CONFIRMATION'];

    public function __construct(private AdminCatalogueService $catalogue, private AuditService $audit) {}

    private function admin(User $u): void
    {
        if (! $u->isActive() || ! $u->isAdmin()) throw new ShopException('FORBIDDEN', 'Only an admin can do bulk updates');
    }

    /** @return string CSV (UTF-8 with BOM) prefilled with current values: edit and upload it back. */
    public function exportCsv(): string
    {
        $outlets = Outlet::where('status', 'ACTIVE')->orderBy('id')->get();
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(self::FIXED, $outlets->map(fn ($o) => 'stock:'.$o->code)->all()));
        $stock = DB::table('inventory')->get()->groupBy('product_variant_id');
        $products = Product::with(['category', 'variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->orderBy('category_id')->orderBy('id')->get();
        foreach ($products as $p) {
            foreach ($p->variants as $v) {
                $row = [$p->slug, $p->name_bn, $p->name_en, $p->category->slug, $v->id, $v->label_bn, $v->label_en,
                    $v->price_poisha === null ? '' : rtrim(rtrim(number_format($v->price_poisha / 100, 2, '.', ''), '0'), '.'),
                    $v->max_qty, $p->active ? 'yes' : 'no', $p->outer_city_status ?: 'default'];
                foreach ($outlets as $o) {
                    $inv = ($stock[$v->id] ?? collect())->firstWhere('outlet_id', $o->id);
                    $row[] = $v->track_stock ? (string) ($inv->quantity_on_hand ?? 0) : '';
                }
                fputcsv($out, array_map([\App\Http\Controllers\OrderExportController::class, 'safe'], $row));
            }
        }
        rewind($out);
        return stream_get_contents($out);
    }

    private static function bool(string $v): ?bool
    {
        $v = mb_strtolower(trim($v));
        if (in_array($v, ['yes', 'y', 'true', '1', 'হ্যাঁ', 'ha', 'on'], true)) return true;
        if (in_array($v, ['no', 'n', 'false', '0', 'না', 'na', 'off', ''], true)) return false;
        return null;
    }

    /**
     * Validates the whole file first; applies nothing if any row is wrong.
     * @return array{rows:int, changed_variants:int, published:int, hidden:int, stock_set:int, errors:string[]}
     */
    public function importCsv(User $by, string $csv): array
    {
        $this->admin($by);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $fh = fopen('php://temp', 'w+'); fwrite($fh, $csv); rewind($fh);
        $head = fgetcsv($fh);
        if (! $head) throw new ShopException('VALIDATION_FAILED', 'The file is empty');
        $head = array_map(fn ($h) => trim((string) $h), $head);
        foreach (['product_slug', 'variant_id', 'price_taka'] as $need) {
            if (! in_array($need, $head, true)) throw new ShopException('VALIDATION_FAILED', "Column \"$need\" is missing. Download the template first.");
        }
        $col = array_flip($head);
        $outlets = Outlet::all()->keyBy('code');
        $stockCols = [];
        foreach ($head as $h) {
            if (str_starts_with($h, 'stock:')) {
                $code = substr($h, 6);
                if (! isset($outlets[$code])) throw new ShopException('VALIDATION_FAILED', "Unknown branch column \"$h\"");
                $stockCols[$h] = $outlets[$code];
            }
        }

        $errors = []; $plan = []; $line = 1;
        $variants = ProductVariant::with('product')->get()->keyBy('id');
        while (($r = fgetcsv($fh)) !== false) {
            $line++;
            if (count(array_filter($r, fn ($c) => trim((string) $c) !== '')) === 0) continue;
            $get = fn ($k) => isset($col[$k]) ? ltrim(trim((string) ($r[$col[$k]] ?? '')), "'") : null;
            $v = $variants[(int) $get('variant_id')] ?? null;
            if (! $v || $v->product->slug !== $get('product_slug')) { $errors[] = "Row $line: variant ".$get('variant_id').' does not belong to '.$get('product_slug'); continue; }
            $item = ['v' => $v, 'line' => $line];
            $price = $get('price_taka');
            try { $item['price'] = $price === '' ? null : Money::parseTaka($price); } catch (\InvalidArgumentException) { $errors[] = "Row $line: price \"$price\" is not a number"; continue; }
            if ($item['price'] !== null && $item['price'] <= 0) { $errors[] = "Row $line: price must be more than zero"; continue; }
            $mq = $get('max_qty');
            $item['max_qty'] = ($mq === null || $mq === '') ? $v->max_qty : (ctype_digit($mq) && (int) $mq >= 1 && (int) $mq <= 1000 ? (int) $mq : null);
            if ($item['max_qty'] === null) { $errors[] = "Row $line: max_qty must be 1 to 1000"; continue; }
            $item['label_bn'] = $get('variant_label_bn') ?? $v->label_bn;
            $item['label_en'] = $get('variant_label_en') ?? $v->label_en;
            $vis = $get('visible');
            $item['visible'] = $vis === null ? null : self::bool($vis);
            if ($vis !== null && $item['visible'] === null) { $errors[] = "Row $line: visible must be yes or no"; continue; }
            $outer = $get('outer_sylhet');
            if ($outer !== null && $outer !== '' && ! in_array($outer, self::OUTER, true)) { $errors[] = "Row $line: outer_sylhet must be one of ".implode(', ', self::OUTER); continue; }
            $item['outer'] = ($outer === null || $outer === '') ? false : ($outer === 'default' ? null : $outer);
            $item['stock'] = [];
            foreach ($stockCols as $h => $o) {
                $s = $get($h);
                if ($s === '' || $s === null) continue;
                if (! ctype_digit($s) || (int) $s > 1000000) { $errors[] = "Row $line: $h must be a whole number"; continue 2; }
                if (! $v->track_stock) { $errors[] = "Row $line: stock is not counted for this item (leave $h empty)"; continue 2; }
                $item['stock'][$o->id] = (int) $s;
            }
            $plan[] = $item;
        }
        if ($errors) return ['rows' => count($plan), 'changed_variants' => 0, 'published' => 0, 'hidden' => 0, 'stock_set' => 0, 'errors' => $errors];

        $sum = ['rows' => count($plan), 'changed_variants' => 0, 'published' => 0, 'hidden' => 0, 'stock_set' => 0, 'errors' => []];
        DB::transaction(function () use ($by, $plan, &$sum) {
            $productDone = [];
            foreach ($plan as $it) {
                $v = $it['v']->fresh(); $p = $v->product;
                $changed = $v->price_poisha !== $it['price'] || $v->max_qty !== $it['max_qty'] || (string) $v->label_bn !== (string) $it['label_bn'] || (string) $v->label_en !== (string) $it['label_en'];
                if ($changed) {
                    $this->catalogue->saveVariant($by, $p, $v, [
                        'label_bn' => $it['label_bn'], 'label_en' => $it['label_en'], 'max_qty' => $it['max_qty'], 'track_stock' => $v->track_stock,
                        'sort_order' => $v->sort_order, 'price' => $it['price'] === null ? '' : number_format($it['price'] / 100, 2, '.', ''),
                        'compare_at' => $v->compare_at_poisha === null ? '' : number_format($v->compare_at_poisha / 100, 2, '.', ''),
                    ]);
                    $sum['changed_variants']++;
                }
                foreach ($it['stock'] as $outletId => $qty) {
                    $cur = DB::table('inventory')->where(['outlet_id' => $outletId, 'product_variant_id' => $v->id])->value('quantity_on_hand');
                    if ((int) ($cur ?? 0) !== $qty) { /* no row yet = 0 in stock */ $this->catalogue->setStock($by, $outletId, $v, $qty, 'Bulk update (CSV)'); $sum['stock_set']++; }
                }
                if (! isset($productDone[$p->id])) {
                    $productDone[$p->id] = true;
                    $p = $p->fresh();
                    if ($it['outer'] !== false && $p->outer_city_status !== $it['outer']) {
                        $before = $p->outer_city_status;
                        $p->update(['outer_city_status' => $it['outer']]);
                        $this->audit->log('product.updated', 'product', $p->id, ['outer_city_status' => $before], ['outer_city_status' => $it['outer']], $by);
                    }
                    if ($it['visible'] !== null && $p->active !== $it['visible']) {
                        $this->catalogue->setActive($by, $p, $it['visible']);
                        $sum[$it['visible'] ? 'published' : 'hidden']++;
                    }
                }
            }
            $this->audit->log('catalogue.bulk_import', 'catalogue', null, null, $sum, $by);
        });
        return $sum;
    }

    /**
     * Photos named by product slug: "ds-01.jpg" = cover, "ds-01-2.jpg", "ds-01-3.png" = extra photos.
     * @param UploadedFile[] $files
     * @return array{covers:int, extras:int, errors:string[]}
     */
    public function importPhotos(User $by, array $files): array
    {
        $this->admin($by);
        $res = ['covers' => 0, 'extras' => 0, 'errors' => []];
        $products = Product::all()->keyBy('slug');
        foreach ($files as $f) {
            $name = strtolower(pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME));
            $slug = $name; $extra = false;
            if (! isset($products[$slug]) && preg_match('/^(.*)-(\d{1,2})$/', $name, $m) && isset($products[$m[1]])) { $slug = $m[1]; $extra = (int) $m[2] >= 2; }
            if (! isset($products[$slug])) { $res['errors'][] = $f->getClientOriginalName().': no product with slug "'.$name.'"'; continue; }
            try {
                if ($extra) { $this->catalogue->addGalleryImage($by, $products[$slug]->fresh(), $f); $res['extras']++; }
                else { $this->catalogue->uploadImage($by, $products[$slug]->fresh(), $f); $res['covers']++; }
            } catch (ShopException $e) {
                $res['errors'][] = $f->getClientOriginalName().': '.$e->getMessage();
            }
        }
        return $res;
    }
}
