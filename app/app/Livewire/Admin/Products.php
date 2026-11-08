<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Models\Category;
use App\Models\Product;
use App\Services\AdminCatalogueService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class Products extends Component
{
    use HandlesShopErrors, WithPagination;

    public string $paginationTheme = 'dw';

    #[Url] public string $q = '';
    #[Url] public string $category = '';
    #[Url] public string $vis = '';

    public function updating($n): void { $this->resetPage(); }

    public function toggle(int $id, AdminCatalogueService $svc): void
    {
        $p = Product::findOrFail($id);
        $this->attempt(fn () => $svc->setActive(Auth::user(), $p, ! $p->active));
    }

    public function render()
    {
        $q = trim($this->q);
        $products = Product::with(['category', 'variants'])
            ->when($this->category !== '', fn ($x) => $x->where('category_id', $this->category))
            ->when($this->vis !== '', fn ($x) => $x->where('active', $this->vis === '1'))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name_bn', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('name_en', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('slug', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->orderBy('category_id')->orderBy('id')->paginate(30);
        return view('livewire.admin.products', ['products' => $products, 'categories' => Category::orderBy('sort_order')->get(), 'canManage' => Auth::user()->isAdmin()]);
    }
}
