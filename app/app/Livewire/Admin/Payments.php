<?php

namespace App\Livewire\Admin;

use App\Exceptions\ShopException;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** One queue for every bKash/Nagad payment that still waits for a person to check it against the merchant app. */
#[Layout('components.layouts.admin')]
class Payments extends Component
{
    public ?int $rejecting = null;
    public string $reason = '';
    public ?string $flash = null;
    public ?string $error = null;

    private function run(callable $f): void
    {
        $this->flash = $this->error = null;
        try { $f(); $this->flash = __('admin.saved'); } catch (ShopException $e) { $this->error = __('admin.error', ['msg' => __($e->getMessage())]); }
    }

    public function verify(int $id, PaymentService $svc): void
    {
        $this->run(fn () => $svc->verify(Payment::findOrFail($id), Auth::user()));
    }

    public function reject(PaymentService $svc): void
    {
        $this->run(function () use ($svc) {
            $svc->void(Payment::findOrFail($this->rejecting), Auth::user(), $this->reason);
            $this->rejecting = null; $this->reason = '';
        });
    }

    public function render()
    {
        $rows = Payment::with('order')->where('status', 'PENDING_VERIFICATION')
            ->whereHas('order', fn ($o) => $o->where('status', '!=', 'CANCELLED'))->orderBy('id')->get();
        return view('livewire.admin.payments', ['rows' => $rows, 'canManage' => Auth::user()->isAdmin()]);
    }
}
