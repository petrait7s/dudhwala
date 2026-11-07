<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Models\Category;
use App\Services\AdminCatalogueService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Categories extends Component
{
    use HandlesShopErrors;

    public array $rows = [];
    public array $new = ['slug' => '', 'name_bn' => '', 'name_en' => '', 'sort_order' => 0, 'active' => true];

    public function mount(): void { $this->load(); }

    private function load(): void
    {
        $this->rows = Category::orderBy('sort_order')->orderBy('id')->get()->mapWithKeys(fn ($c) => [$c->id => $c->only(['slug', 'name_bn', 'name_en', 'sort_order', 'active']) + ['name_en' => $c->name_en ?? '']])->all();
    }

    public function save(int $id, AdminCatalogueService $svc): void
    {
        $this->attempt(fn () => $svc->saveCategory(Auth::user(), Category::findOrFail($id), $this->rows[$id]));
        $this->load();
    }

    public function create(AdminCatalogueService $svc): void
    {
        if ($this->attempt(fn () => $svc->saveCategory(Auth::user(), null, $this->new))) $this->new = ['slug' => '', 'name_bn' => '', 'name_en' => '', 'sort_order' => 0, 'active' => true];
        $this->load();
    }

    public function remove(int $id, AdminCatalogueService $svc): void
    {
        $this->attempt(fn () => $svc->deleteCategory(Auth::user(), Category::findOrFail($id)));
        $this->load();
    }

    public function render() { return view('livewire.admin.categories'); }
}
