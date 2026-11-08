<?php

namespace App\Livewire\Admin;

use App\Exceptions\ShopException;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\AdminCatalogueService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Products as a spreadsheet: look, then press Edit to unlock every price, size and in/out-of-stock cell at once. */
#[Layout('components.layouts.admin')]
class Products extends Component
{
    /** @param array<int, array<string, mixed>> $changes only the rows the person changed */
    public function saveSheet(array $changes, AdminCatalogueService $svc): array
    {
        try {
            return ['ok' => true] + $svc->saveSheet(Auth::user(), $changes);
        } catch (ShopException $e) {
            return ['ok' => false, 'msg' => __('admin.error', ['msg' => __($e->getMessage())])];
        }
    }

    public function render(InventoryService $inventory)
    {
        $outlets = Outlet::where('status', 'ACTIVE')->orderBy('id')->get(['id', 'name_bn']);
        $products = Product::with(['category', 'variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->orderBy('category_id')->orderBy('id')->get();
        $avail = $inventory->availableAt($products->flatMap(fn ($p) => $p->variants->pluck('id'))->all());
        $fmt = fn ($p) => $p === null ? '' : rtrim(rtrim(number_format($p / 100, 2, '.', ''), '0'), '.');
        $data = [
            'outlets' => $outlets->map(fn ($o) => ['id' => $o->id, 'name' => $o->name_bn])->values(),
            'categories' => Category::orderBy('sort_order')->get(['id', 'name_bn'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_bn])->values(),
            'products' => $products->map(fn ($p) => [
                'id' => $p->id, 'name_bn' => $p->name_bn, 'name_en' => (string) $p->name_en, 'category_id' => $p->category_id, 'category' => $p->category->name_bn,
                'active' => (bool) $p->active, 'url' => route('admin.product', $p),
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id, 'label_bn' => $v->label_bn, 'label_en' => (string) $v->label_en, 'price' => $fmt($v->price_poisha), 'untracked' => ! $v->track_stock,
                    'stock' => $outlets->mapWithKeys(fn ($o) => [$o->id => ! $v->track_stock || (($avail[$v->id][$o->id] ?? 0) > 0)])->all(),
                ])->values(),
            ])->values(),
        ];
        return view('livewire.admin.products', ['data' => $data, 'canManage' => Auth::user()->isAdmin()]);
    }
}
