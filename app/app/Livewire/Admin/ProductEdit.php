<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AdminCatalogueService;
use App\Services\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class ProductEdit extends Component
{
    use HandlesShopErrors, WithFileUploads;

    public ?Product $product = null;
    public array $form = [];
    /** @var array<int, array<string, mixed>> variant id => fields */
    public array $variants = [];
    public array $newVariant = ['label_bn' => '', 'label_en' => '', 'price' => '', 'compare_at' => '', 'max_qty' => 20, 'track_stock' => true, 'sort_order' => 0];
    /** @var array<string, string> "variantId-outletId" => quantity text */
    public array $stock = [];
    public string $stockReason = '';
    public $photo = null;

    public function mount(?Product $product = null): void
    {
        $this->product = $product?->exists ? $product : null;
        $p = $this->product;
        $this->form = [
            'category_id' => $p?->category_id ?? Category::orderBy('sort_order')->value('id'),
            'slug' => $p?->slug ?? '', 'name_bn' => $p?->name_bn ?? '', 'name_en' => $p?->name_en ?? '',
            'name_verified' => (bool) $p?->name_verified, 'outer_city_status' => $p?->outer_city_status ?? '',
            'description_bn' => $p?->description_bn ?? '', 'ingredients_bn' => $p?->ingredients_bn ?? '', 'allergens_bn' => $p?->allergens_bn ?? '',
            'storage_bn' => $p?->storage_bn ?? '', 'shelf_life_bn' => $p?->shelf_life_bn ?? '',
        ];
        $this->loadVariants();
    }

    private function loadVariants(): void
    {
        $this->variants = []; $this->stock = [];
        if (! $this->product) return;
        $rows = DB::table('inventory')->whereIn('product_variant_id', $this->product->variants()->pluck('id'))->get();
        foreach ($this->product->variants()->orderBy('sort_order')->orderBy('id')->get() as $v) {
            $this->variants[$v->id] = [
                'label_bn' => $v->label_bn, 'label_en' => $v->label_en ?? '', 'max_qty' => $v->max_qty, 'track_stock' => $v->track_stock, 'sort_order' => $v->sort_order,
                'price' => $v->price_poisha === null ? '' : rtrim(rtrim(number_format($v->price_poisha / 100, 2, '.', ''), '0'), '.'),
                'compare_at' => $v->compare_at_poisha === null ? '' : rtrim(rtrim(number_format($v->compare_at_poisha / 100, 2, '.', ''), '0'), '.'),
            ];
        }
        foreach ($rows as $r) $this->stock[$r->product_variant_id.'-'.$r->outlet_id] = (string) $r->quantity_on_hand;
    }

    public function saveProduct(AdminCatalogueService $svc)
    {
        $new = $this->product === null;
        $ok = $this->attempt(function () use ($svc) {
            $this->product = $svc->saveProduct(Auth::user(), $this->product, $this->form + ['name_verified' => (bool) ($this->form['name_verified'] ?? false)]);
        });
        if ($ok && $new) return redirect()->route('admin.product', $this->product);
        if ($ok) $this->loadVariants();
    }

    public function saveVariant(int $id, AdminCatalogueService $svc): void
    {
        $v = ProductVariant::where('product_id', $this->product->id)->findOrFail($id);
        $this->attempt(fn () => $svc->saveVariant(Auth::user(), $this->product, $v, $this->variants[$id]));
        $this->loadVariants();
    }

    public function addVariant(AdminCatalogueService $svc): void
    {
        if ($this->attempt(fn () => $svc->saveVariant(Auth::user(), $this->product, null, $this->newVariant))) {
            $this->newVariant = ['label_bn' => '', 'label_en' => '', 'price' => '', 'compare_at' => '', 'max_qty' => 20, 'track_stock' => true, 'sort_order' => 0];
        }
        $this->loadVariants();
    }

    public function deleteVariant(int $id, AdminCatalogueService $svc): void
    {
        $v = ProductVariant::where('product_id', $this->product->id)->findOrFail($id);
        $this->attempt(fn () => $svc->deleteVariant(Auth::user(), $v));
        $this->loadVariants();
    }

    public function saveStock(int $variantId, int $outletId, AdminCatalogueService $svc): void
    {
        $v = ProductVariant::where('product_id', $this->product->id)->findOrFail($variantId);
        $raw = trim($this->stock["$variantId-$outletId"] ?? '');
        if (! ctype_digit($raw)) { $this->error = __('admin.error', ['msg' => 'Stock must be a whole number']); return; }
        $this->attempt(fn () => $svc->setStock(Auth::user(), $outletId, $v, (int) $raw, $this->stockReason));
        $this->loadVariants();
    }

    public function updatedPhoto(AdminCatalogueService $svc): void
    {
        if (! $this->product || ! $this->photo) return;
        $this->attempt(fn () => $svc->uploadImage(Auth::user(), $this->product, new \Illuminate\Http\UploadedFile($this->photo->getRealPath(), $this->photo->getClientOriginalName(), null, null, true)));
        $this->photo = null;
        $this->product->refresh();
    }

    public function render()
    {
        return view('livewire.admin.product-edit', [
            'categories' => Category::orderBy('sort_order')->get(), 'outlets' => Outlet::orderBy('id')->get(),
            'canManage' => Auth::user()->isAdmin(), 'outerOptions' => AdminCatalogueService::OUTER,
        ]);
    }
}
