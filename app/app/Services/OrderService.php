<?php

namespace App\Services;

use App\Contracts\OrderNotifier;
use App\Exceptions\ShopException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderService
{
    public function __construct(
        private CatalogueService $catalogue,
        private InventoryService $inventory,
        private SettingsService $settings,
        private PaymentService $payments,
        private AuditService $audit,
        private OrderNotifier $notifier,
    ) {}

    /**
     * Place an order from a public (guest) request. The server recomputes every price, fee and total.
     * @return array{order: Order, replayed: bool}
     * @throws ShopException VALIDATION_FAILED | CHECKOUT_INVALID(issues) | UNPRICED | UNAVAILABLE | QTY_LIMIT | OUT_OF_STOCK | TRX_DUPLICATE | NOT_CONFIGURED
     */
    public function place(array $in, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $v = Validator::make($in, [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'method' => 'required|in:'.implode(',', OrderingRules::METHODS),
            'zone' => 'nullable|in:'.implode(',', OrderingRules::ZONES),
            'items' => 'required|array|min:1|max:60',
            'items.*.variant_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:1000',
            'contact.name' => 'required|string|max:80',
            'contact.phone' => 'required|string|max:20',
            'contact.address_line1' => 'nullable|string|max:300',
            'contact.landmark' => 'nullable|string|max:120',
            'outlet_id' => 'nullable|integer',
            'pickup_at' => 'nullable|date',
            'payment.method' => 'nullable|in:'.implode(',', OrderingRules::PAYMENT_METHODS),
            'payment.trx_id' => 'nullable|string|max:40',
            'payment.sender_number' => 'nullable|string|max:20',
        ]);
        if ($v->fails()) throw new ShopException('VALIDATION_FAILED', $v->errors()->first(), ['fields' => array_keys($v->errors()->toArray())]);

        // idempotency: a double tap returns the same order
        if ($existing = Order::where('idempotency_key', $in['idempotency_key'])->first()) return ['order' => $existing, 'replayed' => true];

        $method = $in['method'];
        $zone = $method === 'HOME_DELIVERY' ? ($in['zone'] ?? null) : null;
        $phone = Phone::normalize($in['contact']['phone']);
        $payMethod = $in['payment']['method'] ?? 'COD';
        $trx = PaymentService::normalizeTrx($in['payment']['trx_id'] ?? null);
        $sender = Phone::normalize($in['payment']['sender_number'] ?? null);

        $priced = $this->catalogue->priceCart(array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity']], $in['items']));

        $cfg = OrderingRules::configFrom($this->settings);
        $outlet = $this->resolveOutlet($method, $in['outlet_id'] ?? null);
        if (! empty($in['outlet_id']) && in_array($method, ['EXPRESS_PICKUP', 'BULK_ORDER'], true) && ! $outlet) {
            throw new ShopException('CHECKOUT_INVALID', 'Checkout is not valid', ['issues' => [['code' => 'outlet_required']]]); // unknown, paused or closed branch
        }
        $pickupAt = $method === 'EXPRESS_PICKUP' && ! empty($in['pickup_at']) ? CarbonImmutable::parse($in['pickup_at']) : null;
        $bulkPrepaid = (bool) $this->settings->get('bulk.prepaid');

        $issues = OrderingRules::validateCheckout([
            'now' => $now, 'method' => $method, 'zone' => $zone,
            'lines' => array_map(fn ($l) => [
                'key' => (string) $l['variant']->id,
                'product' => ['category_slug' => $l['product']->category->slug, 'outer_city_status' => $l['product']->outer_city_status],
                'quantity' => $l['quantity'], 'line_poisha' => $l['line_poisha'],
            ], $priced),
            'address' => ['name' => $in['contact']['name'], 'phone' => $in['contact']['phone'], 'line1' => $in['contact']['address_line1'] ?? ''],
            'outlet_id' => $outlet?->id, 'pickup_at' => $pickupAt,
            'payment_method' => $payMethod, 'payment_details' => ['trx_id' => $trx, 'sender_number' => $sender],
            'bulk_prepaid' => $bulkPrepaid, 'config' => $cfg,
        ]);
        // express pickup must also fall inside the chosen branch's own opening hours
        if ($method === 'EXPRESS_PICKUP' && $outlet && $pickupAt && ! in_array('pickup_outside_hours', array_column($issues, 'code'), true)
            && ! OrderingRules::inBranchHours($pickupAt, $outlet->hours->map(fn ($h) => ['weekday' => $h->weekday, 'opens' => $h->opens_at, 'closes' => $h->closes_at])->all())) {
            $issues[] = ['code' => 'pickup_outside_hours'];
        }
        if ($issues) throw new ShopException('CHECKOUT_INVALID', 'Checkout is not valid', ['issues' => $issues]);

        if ($method !== 'EXPRESS_PICKUP' && ! $outlet) throw new ShopException('NOT_CONFIGURED', 'No fulfilling outlet is configured yet');

        $subtotal = Money::sum(array_column($priced, 'line_poisha'));
        $fee = OrderingRules::deliveryFeePoisha($method, $zone, $this->settings->get('delivery.fees'));
        $total = $subtotal + $fee;
        $bulk = OrderingRules::bulkStatus(array_map(fn ($l) => ['quantity' => $l['quantity'], 'line_poisha' => $l['line_poisha']], $priced), $cfg['bulk']);

        try {
            $order = DB::transaction(function () use ($in, $method, $zone, $phone, $outlet, $pickupAt, $priced, $subtotal, $fee, $total, $payMethod, $trx, $sender, $cfg, $bulk) {
                $customer = Customer::firstOrCreate(['phone' => $phone], ['name' => trim($in['contact']['name'])]);

                $order = Order::create([
                    'order_number' => $this->newNumber(),
                    'customer_id' => $customer->id, 'outlet_id' => $outlet->id,
                    'method' => $method, 'zone' => $zone, 'status' => OrderingRules::initialStatus($method),
                    'contact_name' => trim($in['contact']['name']), 'contact_phone' => $phone,
                    'address_line1' => $method === 'HOME_DELIVERY' ? trim($in['contact']['address_line1'] ?? '') : null,
                    'address_landmark' => $method === 'HOME_DELIVERY' ? ($in['contact']['landmark'] ?? null) : null,
                    'pickup_at' => $pickupAt,
                    'subtotal_poisha' => $subtotal, 'delivery_fee_poisha' => $fee, 'total_poisha' => $total,
                    'idempotency_key' => $in['idempotency_key'],
                    'bulk_rule_snapshot' => $bulk['is_bulk'] ? ['rule' => $cfg['bulk'], 'approved' => (bool) ($this->settings->get('bulk.rule')['approved']), 'totals' => $bulk] : null,
                ]);

                foreach ($priced as $l) {
                    OrderItem::create([
                        'order_id' => $order->id, 'product_variant_id' => $l['variant']->id, 'product_slug' => $l['product']->slug,
                        'product_name_bn' => $l['product']->name_bn, 'product_name_en' => $l['product']->name_en,
                        'variant_label_bn' => $l['variant']->label_bn, 'unit_price_poisha' => $l['unit_poisha'],
                        'quantity' => $l['quantity'], 'line_total_poisha' => $l['line_poisha'],
                    ]);
                }

                $tracked = array_values(array_map(fn ($l) => ['variant_id' => $l['variant']->id, 'qty' => $l['quantity']], array_filter($priced, fn ($l) => $l['variant']->track_stock)));
                if ($tracked) $this->inventory->reserve($outlet->id, $tracked, $order->id, ['system' => 'order']);

                OrderStatusEvent::create(['order_id' => $order->id, 'from_status' => null, 'to_status' => $order->status, 'actor_role' => 'CUSTOMER']);

                try {
                    Payment::create([
                        'order_id' => $order->id, 'method' => $payMethod, 'amount_poisha' => $total,
                        'trx_id' => $payMethod === 'COD' ? null : $trx, 'sender_number' => $payMethod === 'COD' ? null : $sender,
                        'status' => $payMethod === 'COD' ? 'UNPAID' : 'PENDING_VERIFICATION',
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw new ShopException('TRX_DUPLICATE', 'This TrxID was already used');
                }
                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // lost an idempotency race: the other request created the order
            if ($existing = Order::where('idempotency_key', $in['idempotency_key'])->first()) return ['order' => $existing, 'replayed' => true];
            throw new ShopException('CONFLICT', 'Could not place the order, please retry');
        }

        $this->audit->log('order.placed', 'order', $order->id, null, ['number' => $order->order_number, 'total_poisha' => $order->total_poisha, 'method' => $method], null, 'CUSTOMER');
        try { $this->notifier->orderPlaced($order); } catch (\Throwable $e) { report($e); } // an alert failure never loses an order
        return ['order' => $order, 'replayed' => false];
    }

    private function resolveOutlet(string $method, mixed $requested): ?Outlet
    {
        $id = match ($method) {
            'HOME_DELIVERY' => $this->settings->get('fulfilment.dispatch_outlet_id'),
            // showroom orders: the branch the customer picked; the configured collection outlet is only the default
            'BULK_ORDER' => $requested ?: $this->settings->get('bulk.collection_outlet_id'),
            'EXPRESS_PICKUP' => $requested,
        };
        return $id ? Outlet::with('hours')->where('id', $id)->where('status', 'ACTIVE')->first() : null;
    }

    private function newNumber(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 8; $try++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $n = 'DW-'.now()->format('ymd').'-'.$s;
            if (! Order::where('order_number', $n)->exists()) return $n;
        }
        throw new ShopException('CONFLICT', 'Could not allocate an order number');
    }

    /**
     * Staff moves an order one step forward, or cancels it. Customers cannot cancel.
     * Cancel: needs a reason, releases stock, voids unpaid/pending payments, and writes
     * payment.refund_required for any payment that was already PAID.
     */
    public function transition(Order $order, string $to, User $by, ?string $reason = null): Order
    {
        if (! $by->isActive() || ! $by->isAdmin()) throw new ShopException('FORBIDDEN', 'Only an admin can change orders');

        return DB::transaction(function () use ($order, $to, $by, $reason) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! OrderingRules::canTransition($o->method, $o->status, $to)) {
                throw new ShopException('CONFLICT', 'This order cannot move to that status', ['allowed' => OrderingRules::nextStatuses($o->method, $o->status)]);
            }
            if ($to === 'CANCELLED' && trim((string) $reason) === '') throw new ShopException('VALIDATION_FAILED', 'A cancel reason is required');

            $from = $o->status;
            $o->update(['status' => $to, 'cancel_reason' => $to === 'CANCELLED' ? $reason : $o->cancel_reason]);
            OrderStatusEvent::create(['order_id' => $o->id, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $by->id, 'actor_role' => $by->role, 'reason' => $reason]);

            if ($to === 'CANCELLED') {
                $this->inventory->release($o->id, ['user' => $by->id], 'order cancelled');
                $paid = 0;
                foreach (Payment::where('order_id', $o->id)->whereIn('status', ['UNPAID', 'PENDING_VERIFICATION', 'PAID'])->get() as $p) {
                    if ($p->status === 'PAID') {
                        $paid += $p->amount_poisha;
                        $this->audit->log('payment.refund_required', 'payment', $p->id, null, ['order_id' => $o->id, 'order_number' => $o->order_number, 'amount_poisha' => $p->amount_poisha, 'method' => $p->method, 'trx_id' => $p->trx_id], $by);
                    } else {
                        $p->update(['status' => 'VOID', 'void_reason' => 'order cancelled']);
                    }
                }
            } elseif ($to === OrderingRules::finalStatus($o->method)) {
                $this->inventory->commit($o->id, ['user' => $by->id]);
            }

            $this->audit->log('order.status_changed', 'order', $o->id, ['status' => $from], ['status' => $to, 'reason' => $reason], $by);
            return $o->fresh();
        });
    }

    public const COURIERS = ['STEADFAST', 'PATHAO', 'OWN_RIDER', 'OTHER'];

    /**
     * Staff hand a home-delivery parcel to a courier or rider and record it (no courier API, no gateway: manual and simple).
     * The tracking code is required for Steadfast and Pathao. Can be corrected until the order is delivered or cancelled.
     */
    public function setCourier(Order $order, User $by, string $courier, ?string $ref, ?string $note): Order
    {
        if (! $by->isActive() || ! $by->isAdmin()) throw new ShopException('FORBIDDEN', 'Only an admin can change orders');
        if (! in_array($courier, self::COURIERS, true)) throw new ShopException('VALIDATION_FAILED', 'Choose a courier');
        $ref = trim((string) $ref) ?: null;
        $note = trim((string) $note) ?: null;
        if (in_array($courier, ['STEADFAST', 'PATHAO'], true) && ! $ref) throw new ShopException('VALIDATION_FAILED', 'The tracking code is required for this courier');
        if (mb_strlen((string) $ref) > 64 || mb_strlen((string) $note) > 200) throw new ShopException('VALIDATION_FAILED', 'Tracking code or note is too long');

        return DB::transaction(function () use ($order, $by, $courier, $ref, $note) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($o->method !== 'HOME_DELIVERY') throw new ShopException('CONFLICT', 'Only home delivery orders go to a courier');
            if (in_array($o->status, ['CANCELLED', OrderingRules::finalStatus($o->method)], true)) throw new ShopException('CONFLICT', 'This order is finished');
            $before = $o->only(['courier', 'courier_ref', 'courier_note']);
            $o->update(['courier' => $courier, 'courier_ref' => $ref, 'courier_note' => $note, 'dispatched_at' => $o->dispatched_at ?? now()]);
            $this->audit->log('order.courier_set', 'order', $o->id, $before, ['courier' => $courier, 'courier_ref' => $ref, 'courier_note' => $note], $by);
            return $o->fresh();
        });
    }

    /** The courier's own tracking page for this parcel, or null (own rider, no code, no template). */
    public function courierUrl(Order $o): ?string
    {
        if (! $o->courier || ! $o->courier_ref) return null;
        $tpl = ($this->settings->get('courier.tracking_urls')[$o->courier] ?? null);
        if (! $tpl) return null;
        return str_replace(['{code}', '{phone}'], [rawurlencode($o->courier_ref), rawurlencode($o->contact_phone)], $tpl);
    }

    /** How many earlier cancelled orders this phone has (shown to staff as a warning). */
    public function cancelledCountForPhone(string $phone, ?int $excludeOrderId = null): int
    {
        return Order::where('contact_phone', $phone)->where('status', 'CANCELLED')
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))->count();
    }
}
