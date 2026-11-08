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

    public string $paginationTheme = 'dw';

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
                $trx = \App\Services\PaymentService::normalizeTrx($q);
                $x->where(fn ($w) => $w->where('order_number', 'like', '%'.addcslashes(strtoupper($q), '%_\\').'%')
                    ->when($phone, fn ($w2) => $w2->orWhere('contact_phone', $phone)->orWhereHas('payments', fn ($p) => $p->where('sender_number', $phone)))
                    ->orWhereHas('payments', fn ($p) => $p->where('trx_id', $trx)));
            })
            ->orderByDesc('id')->paginate(25);
        $summary = $orders->getCollection()->mapWithKeys(fn ($o) => [$o->id => $payments->summary($o)]);
        $today = \Carbon\CarbonImmutable::now('Asia/Dhaka')->startOfDay()->utc();
        $stats = [
            'today' => Order::where('created_at', '>=', $today)->count(),
            'new' => Order::whereIn('status', ['PLACED', 'PICKUP_REQUESTED'])->count(),
            'verify' => \App\Models\Payment::where('status', 'PENDING_VERIFICATION')->whereHas('order', fn ($o) => $o->where('status', '!=', 'CANCELLED'))->count(),
            'cod_due' => \App\Models\Payment::where('method', 'COD')->where('status', 'UNPAID')->whereHas('order', fn ($o) => $o->where('status', '!=', 'CANCELLED'))->count(),
        ];
        return view('livewire.admin.orders', ['orders' => $orders, 'summary' => $summary, 'statuses' => array_keys(__('admin.s')), 'stats' => $stats, 'canExport' => auth()->user()->isAdmin()]);
    }
}
