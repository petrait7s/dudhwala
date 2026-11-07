<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Business rules ported from packages/shared/src/ordering.ts. Pure: no DB, no clock (callers pass "now").
 * The server is the authority; the browser copy is display only.
 */
final class OrderingRules
{
    public const TZ = 'Asia/Dhaka';
    public const ZONES = ['IN_CITY', 'OUTER_CITY'];
    public const METHODS = ['HOME_DELIVERY', 'EXPRESS_PICKUP', 'BULK_ORDER'];
    public const PAYMENT_METHODS = ['COD', 'BKASH', 'NAGAD'];

    /** Whole categories eligible for Outer Sylhet. */
    public const OUTER_CITY_WHOLE_CATEGORIES = ['bakery'];

    public const DELIVERY_ESTIMATE = [
        'IN_CITY' => ['min_hours' => 0, 'max_hours' => 24],
        'OUTER_CITY' => ['min_hours' => 24, 'max_hours' => 48],
    ];

    public const PICKUP_MIN_LEAD_MINUTES = 60;
    public const PICKUP_SLOT_MINUTES = 30;

    public const FLOW = [
        'HOME_DELIVERY' => ['PLACED', 'PREPARING', 'OUT_FOR_DELIVERY', 'DELIVERED'],
        'EXPRESS_PICKUP' => ['PICKUP_REQUESTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP'],
        'BULK_ORDER' => ['PLACED', 'PREPARING', 'READY_FOR_COLLECTION', 'COLLECTED'],
    ];

    /** @return array{hours: array{open_minute:int, close_minute:int}, bulk: array{min_total_quantity:int, min_total_poisha:int}} */
    public static function defaultConfig(): array
    {
        return [
            'hours' => ['open_minute' => 600, 'close_minute' => 1320],
            'bulk' => ['min_total_quantity' => 20, 'min_total_poisha' => 2000000],
        ];
    }

    public static function configFrom(SettingsService $s): array
    {
        $b = $s->get('bulk.rule');
        return ['hours' => $s->get('ordering.hours'), 'bulk' => ['min_total_quantity' => $b['min_total_quantity'], 'min_total_poisha' => $b['min_total_poisha']]];
    }

    /* ───────────── eligibility ───────────── */

    /**
     * @param array{category_slug: string, outer_city_status?: ?string} $p
     * @return array{eligible: bool, reason: string}
     */
    public static function zoneEligibility(array $p, string $zone): array
    {
        if ($zone === 'IN_CITY') return ['eligible' => true, 'reason' => 'in_city_all'];
        $s = isset($p['outer_city_status']) ? strtolower($p['outer_city_status']) : null;
        // explicit "not confirmed / switched off" flags always win
        if (in_array($s, ['pending_verification', 'pending_confirmation', 'disabled'], true)) return ['eligible' => false, 'reason' => $s];
        if ($s === 'approved') return ['eligible' => true, 'reason' => 'approved_item'];
        if (in_array(strtolower($p['category_slug']), self::OUTER_CITY_WHOLE_CATEGORIES, true)) return ['eligible' => true, 'reason' => 'category_rule'];
        return ['eligible' => false, 'reason' => 'item_not_approved'];
    }

    /* ───────────── time (Asia/Dhaka) ───────────── */

    public static function minutesToHHMM(int $m): string
    {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    public static function hhmmToMinutes(string $v): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m)) return null;
        [$h, $min] = [(int) $m[1], (int) $m[2]];
        return $h <= 24 && $min < 60 && $h * 60 + $min <= 1440 ? $h * 60 + $min : null;
    }

    private static function zoned(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::TZ);
    }

    public static function minutesOfDay(DateTimeInterface $at): int
    {
        $z = self::zoned($at);
        return $z->hour * 60 + $z->minute;
    }

    private static function atMinutes(CarbonImmutable $day, int $minutes): CarbonImmutable
    {
        return $day->startOfDay()->addMinutes($minutes);
    }

    /** @return array{open: bool, reopens_at: ?CarbonImmutable, closes_at: ?CarbonImmutable} */
    public static function orderingStatus(DateTimeInterface $now, ?array $hours = null): array
    {
        $hours ??= self::defaultConfig()['hours'];
        $z = self::zoned($now);
        $m = $z->hour * 60 + $z->minute;
        if ($m >= $hours['open_minute'] && $m < $hours['close_minute']) {
            return ['open' => true, 'reopens_at' => null, 'closes_at' => self::atMinutes($z, $hours['close_minute'])];
        }
        $day = $m < $hours['open_minute'] ? $z : $z->addDay();
        return ['open' => false, 'reopens_at' => self::atMinutes($day, $hours['open_minute']), 'closes_at' => null];
    }

    /* ───────────── express pickup ───────────── */

    public static function earliestPickup(DateTimeInterface $now, int $lead = self::PICKUP_MIN_LEAD_MINUTES): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->addMinutes($lead);
    }

    /** Pickup hours reuse ordering hours; closing minute is inclusive (22:00 pickup is allowed). */
    private static function inPickupHours(DateTimeInterface $at, array $hours): bool
    {
        $m = self::minutesOfDay($at);
        return $m >= $hours['open_minute'] && $m <= $hours['close_minute'];
    }

    /** @return CarbonImmutable[] */
    public static function pickupSlots(DateTimeInterface $now, int $count = 12, ?array $hours = null): array
    {
        $hours ??= self::defaultConfig()['hours'];
        $step = self::PICKUP_SLOT_MINUTES * 60;
        $t = (int) (ceil(self::earliestPickup($now)->getTimestamp() / $step) * $step);
        $out = [];
        for ($i = 0; $i < 24 * 60 && count($out) < $count; $i++, $t += $step) {
            $d = CarbonImmutable::createFromTimestamp($t);
            if (self::inPickupHours($d, $hours)) $out[] = $d;
        }
        return $out;
    }

    /** @return array{ok: true}|array{ok: false, reason: string} */
    public static function validatePickup(DateTimeInterface $now, ?DateTimeInterface $at, ?array $hours = null): array
    {
        $hours ??= self::defaultConfig()['hours'];
        if ($at === null) return ['ok' => false, 'reason' => 'invalid'];
        if ($at->getTimestamp() < self::earliestPickup($now)->getTimestamp()) return ['ok' => false, 'reason' => 'too_soon'];
        if (! self::inPickupHours($at, $hours)) return ['ok' => false, 'reason' => 'outside_hours'];
        return ['ok' => true];
    }

    /* ───────────── bulk (showroom) ───────────── */

    /**
     * Bulk when EITHER threshold is reached. Unknown price => amount rule not evaluated (null), never true.
     * @param array<int, array{quantity:int, line_poisha:?int}> $lines
     */
    public static function bulkStatus(array $lines, ?array $cfg = null): array
    {
        $cfg ??= self::defaultConfig()['bulk'];
        $qty = array_sum(array_column($lines, 'quantity'));
        $unknown = false;
        $total = 0;
        foreach ($lines as $l) {
            if ($l['line_poisha'] === null) { $unknown = true; break; }
            $total += $l['line_poisha'];
        }
        $totalPoisha = $unknown ? null : $total;
        $byQty = $qty >= $cfg['min_total_quantity'];
        $byAmount = $totalPoisha === null ? null : $totalPoisha >= $cfg['min_total_poisha'];
        return ['is_bulk' => $byQty || $byAmount === true, 'total_quantity' => $qty, 'total_poisha' => $totalPoisha, 'by_quantity' => $byQty, 'by_amount' => $byAmount];
    }

    /** Bulk carts can only be collected at the showroom. */
    public static function availableFulfilments(bool $bulk): array
    {
        return $bulk ? ['BULK_ORDER'] : ['HOME_DELIVERY', 'EXPRESS_PICKUP'];
    }

    /* ───────────── checkout validation ───────────── */

    /**
     * @param array{
     *   now: DateTimeInterface, method: string, zone: ?string,
     *   lines: array<int, array{key: string, product: array, quantity: int, line_poisha: ?int}>,
     *   address?: array{name: string, phone: string, line1: string}, outlet_id?: mixed, pickup_at?: ?DateTimeInterface,
     *   payment_method?: string, payment_details?: array{trx_id?: ?string, sender_number?: ?string},
     *   bulk_prepaid?: bool, config?: array
     * } $i
     * @return array<int, array{code: string, line?: string}>
     */
    public static function validateCheckout(array $i): array
    {
        $out = [];
        $cfg = $i['config'] ?? self::defaultConfig();
        $hours = $cfg['hours'];
        $method = $i['method'];
        $lines = $i['lines'];

        if (! self::orderingStatus($i['now'], $hours)['open']) $out[] = ['code' => 'ordering_closed'];
        if (count($lines) === 0) $out[] = ['code' => 'cart_empty'];

        $bulk = self::bulkStatus(array_map(fn ($l) => ['quantity' => $l['quantity'], 'line_poisha' => $l['line_poisha']], $lines), $cfg['bulk']);
        if ($bulk['is_bulk'] && $method !== 'BULK_ORDER') $out[] = ['code' => 'bulk_requires_collection'];
        if (! $bulk['is_bulk'] && $method === 'BULK_ORDER') $out[] = ['code' => 'not_bulk'];

        if ($method === 'HOME_DELIVERY') {
            if (empty($i['zone'])) $out[] = ['code' => 'zone_required'];
            else foreach ($lines as $l) {
                if (! self::zoneEligibility($l['product'], $i['zone'])['eligible']) $out[] = ['code' => 'item_not_deliverable', 'line' => $l['key']];
            }
        }

        // every method needs a contact name and mobile; only home delivery needs a street address
        $addr = $i['address'] ?? null;
        if ($addr === null || trim($addr['name']) === '') $out[] = ['code' => 'address_name_required'];
        if ($addr === null || Phone::normalize($addr['phone']) === null) $out[] = ['code' => 'address_phone_invalid'];
        if ($method === 'HOME_DELIVERY' && ($addr === null || trim($addr['line1']) === '')) $out[] = ['code' => 'address_line_required'];

        // pickup and showroom orders are collected at a branch the customer chooses (any active outlet)
        if (in_array($method, ['EXPRESS_PICKUP', 'BULK_ORDER'], true) && empty($i['outlet_id'])) $out[] = ['code' => 'outlet_required'];
        if ($method === 'EXPRESS_PICKUP') {
            $c = self::validatePickup($i['now'], $i['pickup_at'] ?? null, $hours);
            if (! $c['ok']) $out[] = ['code' => ['invalid' => 'pickup_invalid', 'too_soon' => 'pickup_too_soon', 'outside_hours' => 'pickup_outside_hours'][$c['reason']]];
        }

        // payment: COD, or bKash/Nagad with sender number + TrxID
        $pm = $i['payment_method'] ?? 'COD';
        if (! in_array($pm, self::PAYMENT_METHODS, true)) {
            $out[] = ['code' => 'payment_method_invalid'];
        } elseif ($pm !== 'COD') {
            $d = $i['payment_details'] ?? [];
            if (trim((string) ($d['trx_id'] ?? '')) === '') $out[] = ['code' => 'payment_trx_required'];
            if (Phone::normalize($d['sender_number'] ?? null) === null) $out[] = ['code' => 'payment_sender_invalid'];
        }
        if ($method === 'BULK_ORDER' && ($i['bulk_prepaid'] ?? false) && $pm === 'COD') $out[] = ['code' => 'bulk_prepayment_required'];

        return $out;
    }

    /**
     * Is $at inside the branch's own opening hours (weekday 0=Sunday, Dhaka time, closing minute inclusive)?
     * $rows: [['weekday'=>int,'opens'=>'HH:MM(:SS)','closes'=>'HH:MM(:SS)']]. No rows at all = no per-branch hours set: true.
     */
    public static function inBranchHours(DateTimeInterface $at, array $rows): bool
    {
        if ($rows === []) return true;
        $z = self::zoned($at);
        $m = $z->hour * 60 + $z->minute;
        foreach ($rows as $r) {
            if ((int) $r['weekday'] !== (int) $z->dayOfWeek) continue;
            $o = self::hhmmToMinutes(substr($r['opens'], 0, 5)); $c = self::hhmmToMinutes(substr($r['closes'], 0, 5));
            if ($o !== null && $c !== null && $m >= $o && $m <= $c) return true;
        }
        return false;
    }

    /* ───────────── order status flow ───────────── */

    public static function initialStatus(string $method): string { return self::FLOW[$method][0]; }
    public static function finalStatus(string $method): string { $f = self::FLOW[$method]; return $f[count($f) - 1]; }

    /** Strictly one step forward, or CANCELLED while the order is live. */
    public static function nextStatuses(string $method, string $current): array
    {
        if ($current === 'CANCELLED' || $current === self::finalStatus($method)) return [];
        $i = array_search($current, self::FLOW[$method], true);
        if ($i === false) return [];
        $out = [];
        if (isset(self::FLOW[$method][$i + 1])) $out[] = self::FLOW[$method][$i + 1];
        $out[] = 'CANCELLED';
        return $out;
    }

    public static function canTransition(string $method, string $from, string $to): bool
    {
        return in_array($to, self::nextStatuses($method, $from), true);
    }

    /* ───────────── delivery fee ───────────── */

    /** Only home delivery is charged. */
    public static function deliveryFeePoisha(string $method, ?string $zone, ?array $fees = null): int
    {
        $fees ??= ['IN_CITY' => 0, 'OUTER_CITY' => 0];
        return $method === 'HOME_DELIVERY' && $zone ? (int) $fees[$zone] : 0;
    }
}
