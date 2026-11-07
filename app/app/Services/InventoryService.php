<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Per-outlet stock for tracked variants. Every change is one guarded UPDATE, so parallel orders
 * cannot reserve the same unit; each change also writes an append-only ledger row.
 * Methods that touch more than one row must run inside DB::transaction (callers' or ours).
 *
 * Actor: ['user' => int] or ['system' => 'name'].
 */
class InventoryService
{
    private function row(int $inventoryId): object
    {
        return DB::table('inventory')->where('id', $inventoryId)->first();
    }

    private function log(object $inv, string $type, int $dOnHand, int $dReserved, array $actor, ?int $orderId = null, ?string $reason = null): void
    {
        StockMovement::create([
            'inventory_id' => $inv->id,
            'outlet_id' => $inv->outlet_id,
            'product_variant_id' => $inv->product_variant_id,
            'type' => $type,
            'qty_delta_on_hand' => $dOnHand,
            'qty_delta_reserved' => $dReserved,
            'on_hand_after' => $inv->quantity_on_hand,
            'reserved_after' => $inv->reserved_quantity,
            'reason' => $reason,
            'order_id' => $orderId,
            'actor_user_id' => $actor['user'] ?? null,
            'actor_system' => $actor['system'] ?? null,
        ]);
    }

    /** Admin sets the counted quantity. Cannot go below what open orders hold. */
    public function setStock(int $outletId, int $variantId, int $quantity, array $actor, string $reason): void
    {
        if ($quantity < 0 || $quantity > 1_000_000) throw new ShopException('VALIDATION_FAILED', 'Stock must be a whole number from 0 to 1,000,000');
        if (trim($reason) === '') throw new ShopException('VALIDATION_FAILED', 'A reason is required');

        DB::transaction(function () use ($outletId, $variantId, $quantity, $actor, $reason) {
            $cur = DB::table('inventory')->where(['outlet_id' => $outletId, 'product_variant_id' => $variantId])->lockForUpdate()->first();
            if ($cur && $cur->reserved_quantity > $quantity) throw new ShopException('CONFLICT', 'Open orders already hold more than this quantity');
            if ($cur) {
                DB::table('inventory')->where('id', $cur->id)->update(['quantity_on_hand' => $quantity, 'updated_at' => now()]);
                $id = $cur->id;
            } else {
                $id = DB::table('inventory')->insertGetId(['outlet_id' => $outletId, 'product_variant_id' => $variantId, 'quantity_on_hand' => $quantity, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->log($this->row($id), 'SET', $quantity - ($cur->quantity_on_hand ?? 0), 0, $actor, null, $reason);
        });
    }

    /**
     * Reserve every line at the outlet or throw OUT_OF_STOCK (nothing is kept on failure).
     * @param array<int, array{variant_id:int, qty:int}> $lines tracked variants only
     */
    public function reserve(int $outletId, array $lines, int $orderId, array $actor): void
    {
        usort($lines, fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']); // fixed lock order => no deadlocks
        DB::transaction(function () use ($outletId, $lines, $orderId, $actor) {
            foreach ($lines as $l) {
                if ($l['qty'] < 1) throw new ShopException('VALIDATION_FAILED', 'Quantity must be at least 1');
                $n = DB::update(
                    'UPDATE inventory SET reserved_quantity = reserved_quantity + ?, updated_at = ?
                     WHERE outlet_id = ? AND product_variant_id = ? AND quantity_on_hand - reserved_quantity >= ?',
                    [$l['qty'], now(), $outletId, $l['variant_id'], $l['qty']],
                );
                if ($n === 0) throw new ShopException('OUT_OF_STOCK', 'Not enough stock', ['variant_id' => $l['variant_id']]);
                $inv = DB::table('inventory')->where(['outlet_id' => $outletId, 'product_variant_id' => $l['variant_id']])->first();
                $this->log($inv, 'RESERVE', 0, $l['qty'], $actor, $orderId);
            }
        });
    }

    /** What an order still holds, per inventory row. */
    private function held(int $orderId): array
    {
        // lock the rows first so two concurrent release/commit calls cannot both see the same hold
        $ids = DB::table('stock_movements')->where('order_id', $orderId)->distinct()->pluck('inventory_id')->sort()->values()->all();
        if ($ids) DB::table('inventory')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        return DB::table('stock_movements')->where('order_id', $orderId)
            ->groupBy('inventory_id')->havingRaw('SUM(qty_delta_reserved) > 0')
            ->selectRaw('inventory_id, SUM(qty_delta_reserved) AS held')->get()->all();
    }

    /** Give back everything the order holds (cancel). Safe to call twice. */
    public function release(int $orderId, array $actor, string $reason): void
    {
        DB::transaction(function () use ($orderId, $actor, $reason) {
            foreach ($this->held($orderId) as $h) {
                $held = (int) $h->held;
                DB::update('UPDATE inventory SET reserved_quantity = reserved_quantity - ?, updated_at = ? WHERE id = ?', [$held, now(), $h->inventory_id]);
                $this->log($this->row($h->inventory_id), 'RELEASE', 0, -$held, $actor, $orderId, $reason);
            }
        });
    }

    /** Order fulfilled: reserved units leave the shelf. Safe to call twice. */
    public function commit(int $orderId, array $actor): void
    {
        DB::transaction(function () use ($orderId, $actor) {
            foreach ($this->held($orderId) as $h) {
                $held = (int) $h->held;
                DB::update('UPDATE inventory SET quantity_on_hand = quantity_on_hand - ?, reserved_quantity = reserved_quantity - ?, updated_at = ? WHERE id = ?', [$held, $held, now(), $h->inventory_id]);
                $this->log($this->row($h->inventory_id), 'COMMIT_SALE', -$held, -$held, $actor, $orderId);
            }
        });
    }

    /** @return array<int, array<int, int>> [variant_id => [outlet_id => available]] for ACTIVE outlets */
    public function availableAt(array $variantIds): array
    {
        $out = [];
        $rows = DB::table('inventory')->join('outlets', 'outlets.id', '=', 'inventory.outlet_id')
            ->whereIn('product_variant_id', $variantIds)->where('outlets.status', 'ACTIVE')
            ->get(['product_variant_id', 'outlet_id', 'quantity_on_hand', 'reserved_quantity']);
        foreach ($rows as $r) $out[$r->product_variant_id][$r->outlet_id] = $r->quantity_on_hand - $r->reserved_quantity;
        return $out;
    }
}
