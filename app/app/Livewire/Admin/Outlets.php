<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Models\Outlet;
use App\Services\AdminCatalogueService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Outlets extends Component
{
    use HandlesShopErrors;

    public ?int $editing = null;
    public array $form = [];
    /** @var array<int, array{open: bool, opens: string, closes: string}> weekday => hours */
    public array $hours = [];

    public function mount(): void { $this->blank(); }

    private function blank(): void
    {
        $this->editing = null;
        $this->form = ['code' => '', 'name_bn' => '', 'name_en' => '', 'address_line' => '', 'phone' => '', 'status' => 'ACTIVE'];
        $this->hours = collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => ['open' => true, 'opens' => '10:00', 'closes' => '22:00']])->all();
    }

    public function edit(int $id): void
    {
        $o = Outlet::with('hours')->findOrFail($id);
        $this->editing = $id;
        $this->form = ['code' => $o->code, 'name_bn' => $o->name_bn, 'name_en' => $o->name_en ?? '', 'address_line' => $o->address_line, 'phone' => $o->phone ?? '', 'status' => $o->status];
        $by = $o->hours->keyBy('weekday');
        $this->hours = collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => isset($by[$d])
            ? ['open' => true, 'opens' => substr($by[$d]->opens_at, 0, 5), 'closes' => substr($by[$d]->closes_at, 0, 5)]
            : ['open' => false, 'opens' => '10:00', 'closes' => '22:00']])->all();
    }

    public function save(AdminCatalogueService $svc): void
    {
        $hours = collect($this->hours)->filter(fn ($h) => $h['open'])->map(fn ($h, $d) => ['weekday' => (int) $d, 'opens' => $h['opens'], 'closes' => $h['closes']])->values()->all();
        $ok = $this->attempt(function () use ($svc, $hours) {
            $o = $svc->saveOutlet(Auth::user(), $this->editing ? Outlet::findOrFail($this->editing) : null, $this->form, $hours);
            $this->editing = $o->id;
        });
    }

    public function newOutlet(): void { $this->blank(); $this->flash = $this->error = null; }

    public function render()
    {
        return view('livewire.admin.outlets', ['outlets' => Outlet::orderBy('id')->get()]);
    }
}
