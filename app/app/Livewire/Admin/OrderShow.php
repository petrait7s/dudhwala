<?php

namespace App\Livewire\Admin;

use App\Exceptions\ShopException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderingRules;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class OrderShow extends Component
{
    public Order $order;
    public string $cancelReason = '';
    public string $voidReason = '';
    public ?int $voidingPayment = null;
    public bool $cancelling = false;
    public ?string $flash = null;
    public ?string $error = null;

    public function mount(Order $order): void
    {
        $this->order = $order;
    }

    private function run(callable $f): void
    {
        $this->flash = $this->error = null;
        try {
            $f();
            $this->flash = __('admin.saved');
            $this->order = $this->order->fresh();
        } catch (ShopException $e) {
            $this->error = __('admin.error', ['msg' => $e->getMessage()]);
        }
    }

    public function advance(string $to, OrderService $svc): void
    {
        $this->run(fn () => $svc->transition($this->order, $to, Auth::user()));
    }

    public function confirmCancel(OrderService $svc): void
    {
        $this->run(function () use ($svc) {
            $svc->transition($this->order, 'CANCELLED', Auth::user(), $this->cancelReason);
            $this->cancelling = false; $this->cancelReason = '';
        });
    }

    public function codCollected(PaymentService $svc): void
    {
        $this->run(fn () => $svc->recordCodCollected($this->order, Auth::user()));
    }

    public function verify(int $paymentId, PaymentService $svc): void
    {
        $this->run(fn () => $svc->verify(Payment::where('order_id', $this->order->id)->findOrFail($paymentId), Auth::user()));
    }

    public function confirmVoid(PaymentService $svc): void
    {
        $this->run(function () use ($svc) {
            $svc->void(Payment::where('order_id', $this->order->id)->findOrFail($this->voidingPayment), Auth::user(), $this->voidReason);
            $this->voidingPayment = null; $this->voidReason = '';
        });
    }

    public function render(PaymentService $payments, OrderService $orders)
    {
        $o = $this->order->load(['items', 'payments', 'events', 'outlet']);
        return view('livewire.admin.order-show', [
            'o' => $o,
            'canManage' => Auth::user()->isAdmin(),
            'next' => array_values(array_diff(OrderingRules::nextStatuses($o->method, $o->status), ['CANCELLED'])),
            'live' => ! in_array($o->status, ['CANCELLED', OrderingRules::finalStatus($o->method)], true),
            'paid' => $payments->paidTotal($o),
            'due' => $payments->due($o),
            'priorCancels' => $orders->cancelledCountForPhone($o->contact_phone, $o->id),
            'refundFlag' => AuditLog::where('action', 'payment.refund_required')->where('after->order_id', $o->id)->exists(),
        ]);
    }
}
