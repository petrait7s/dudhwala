<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payments are never deleted, only voided with a reason. A TrxID belongs to one payment (unique column).
 * COD becomes PAID when staff record the cash. bKash/Nagad are verified by staff against the merchant app.
 * "Due" is computed (total - PAID), never stored.
 */
class PaymentService
{
    public function __construct(private AuditService $audit) {}

    public static function normalizeTrx(?string $t): ?string
    {
        $t = strtoupper(preg_replace('/\s+/', '', (string) $t));
        return $t === '' ? null : $t;
    }

    public function paidTotal(Order $o): int
    {
        return (int) Payment::where('order_id', $o->id)->where('status', 'PAID')->sum('amount_poisha');
    }

    public function due(Order $o): int
    {
        return max(0, $o->total_poisha - $this->paidTotal($o));
    }

    /** UNPAID | PENDING_VERIFICATION | PAID for customer display. */
    public function summary(Order $o): string
    {
        if ($this->paidTotal($o) >= $o->total_poisha) return 'PAID';
        if (Payment::where('order_id', $o->id)->where('status', 'PENDING_VERIFICATION')->exists()) return 'PENDING_VERIFICATION';
        return 'UNPAID';
    }

    private function mustManage(User $u): void
    {
        if (! $u->isActive() || ! $u->isAdmin()) throw new ShopException('FORBIDDEN', 'Only an admin can change payments');
    }

    /** Staff confirmed the bKash/Nagad money arrived. */
    public function verify(Payment $p, User $by): Payment
    {
        $this->mustManage($by);
        return DB::transaction(function () use ($p, $by) {
            $p = Payment::whereKey($p->id)->lockForUpdate()->first();
            if ($p->method === 'COD') throw new ShopException('VALIDATION_FAILED', 'COD is recorded as cash collected, not verified');
            if ($p->status !== 'PENDING_VERIFICATION') throw new ShopException('CONFLICT', 'Payment is not waiting for verification');
            $order = Order::find($p->order_id);
            if ($order->status === 'CANCELLED') throw new ShopException('CONFLICT', 'Order is cancelled');
            $before = $p->only(['status', 'verified_by']);
            $p->update(['status' => 'PAID', 'verified_by' => $by->id, 'verified_at' => now()]);
            $this->audit->log('payment.verified', 'payment', $p->id, $before, ['status' => 'PAID', 'trx_id' => $p->trx_id, 'amount_poisha' => $p->amount_poisha], $by);
            return $p;
        });
    }

    /** Staff marks cash collected (COD). */
    public function recordCodCollected(Order $o, User $by): Payment
    {
        $this->mustManage($by);
        return DB::transaction(function () use ($o, $by) {
            $o = Order::whereKey($o->id)->lockForUpdate()->first();
            if ($o->status === 'CANCELLED') throw new ShopException('CONFLICT', 'Order is cancelled');
            $p = Payment::where('order_id', $o->id)->where('method', 'COD')->whereIn('status', ['UNPAID'])->lockForUpdate()->first();
            if (! $p) throw new ShopException('CONFLICT', 'No unpaid cash payment on this order');
            $p->update(['status' => 'PAID', 'verified_by' => $by->id, 'verified_at' => now()]);
            $this->audit->log('payment.cod_collected', 'payment', $p->id, ['status' => 'UNPAID'], ['status' => 'PAID', 'amount_poisha' => $p->amount_poisha], $by);
            return $p;
        });
    }

    /** Void with a reason (wrong TrxID, money not received...). */
    public function void(Payment $p, User $by, string $reason): Payment
    {
        $this->mustManage($by);
        if (trim($reason) === '') throw new ShopException('VALIDATION_FAILED', 'A reason is required');
        return DB::transaction(function () use ($p, $by, $reason) {
            $p = Payment::whereKey($p->id)->lockForUpdate()->first();
            if ($p->status === 'VOID') throw new ShopException('CONFLICT', 'Payment is already void');
            $before = $p->only(['status']);
            $p->update(['status' => 'VOID', 'void_reason' => $reason]);
            $this->audit->log('payment.voided', 'payment', $p->id, $before, ['status' => 'VOID', 'reason' => $reason, 'was_paid' => $before['status'] === 'PAID'], $by);
            if ($before['status'] === 'PAID') {
                $this->audit->log('payment.refund_required', 'payment', $p->id, null, ['order_id' => $p->order_id, 'amount_poisha' => $p->amount_poisha, 'reason' => $reason], $by);
            }
            return $p;
        });
    }
}
