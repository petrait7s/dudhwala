<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Services\PaymentService;
use App\Services\Phone;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class Orders extends Component
{
    use WithPagination;

    #[Url] public string $status = '';
    #[Url] public string $q = '';

    public function updating($name): void { $this->resetPage(); }

    public function render(PaymentService $payments)
    {
        $q = trim($this->q);
        $orders = Order::query()
            ->when($this->status !== '', fn ($x) => $x->where('status', $this->status))
            ->when($q !== '', function ($x) use ($q) {
                $phone = Phone::normalize($q);
                $x->where(fn ($w) => $w->where('order_number', 'like', '%'.addcslashes(strtoupper($q), '%_\\').'%')->when($phone, fn ($w2) => $w2->orWhere('contact_phone', $phone)));
            })
            ->orderByDesc('id')->paginate(25);
        $summary = $orders->getCollection()->mapWithKeys(fn ($o) => [$o->id => $payments->summary($o)]);
        return view('livewire.admin.orders', ['orders' => $orders, 'summary' => $summary, 'statuses' => array_keys(__('admin.s'))]);
    }
}
