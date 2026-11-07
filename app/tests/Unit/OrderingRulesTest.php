<?php

namespace Tests\Unit;

use App\Services\OrderingRules as R;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Ported 1:1 from packages/shared/src/ordering.test.ts. Dhaka = UTC+6. */
class OrderingRulesTest extends TestCase
{
    private function at(string $iso): CarbonImmutable { return CarbonImmutable::parse($iso); }
    private const BAKERY = ['category_slug' => 'bakery'];
    private const SWEET_APPROVED = ['category_slug' => 'dairy-sweets', 'outer_city_status' => 'approved'];
    private const SWEET_PLAIN = ['category_slug' => 'dairy-sweets'];

    /* eligibility */
    public function test_in_city_accepts_everything(): void
    {
        foreach ([self::BAKERY, self::SWEET_PLAIN, self::SWEET_APPROVED, ['category_slug' => 'doi-dairy'], ['category_slug' => 'x', 'outer_city_status' => 'pending_verification']] as $p) {
            $this->assertTrue(R::zoneEligibility($p, 'IN_CITY')['eligible']);
        }
    }

    public function test_outer_city_rules(): void
    {
        $this->assertSame(['eligible' => true, 'reason' => 'category_rule'], R::zoneEligibility(self::BAKERY, 'OUTER_CITY'));
        $this->assertSame(['eligible' => true, 'reason' => 'approved_item'], R::zoneEligibility(self::SWEET_APPROVED, 'OUTER_CITY'));
        $this->assertSame(['eligible' => false, 'reason' => 'item_not_approved'], R::zoneEligibility(self::SWEET_PLAIN, 'OUTER_CITY'));
        $this->assertFalse(R::zoneEligibility(['category_slug' => 'doi-dairy'], 'OUTER_CITY')['eligible']);
        $this->assertTrue(R::zoneEligibility(['category_slug' => 'doi-dairy', 'outer_city_status' => 'approved'], 'OUTER_CITY')['eligible']);
        $this->assertTrue(R::zoneEligibility(['category_slug' => 'any-new', 'outer_city_status' => 'approved'], 'OUTER_CITY')['eligible']);
        $this->assertFalse(R::zoneEligibility(['category_slug' => 'any-new'], 'OUTER_CITY')['eligible']);
    }

    public function test_uncertain_flags_never_eligible_and_db_uppercase_works(): void
    {
        foreach ([['bakery', 'pending_verification'], ['dairy-sweets', 'pending_verification'], ['doi-dairy', 'pending_confirmation'], ['bakery', 'disabled'], ['bakery', 'DISABLED'], ['bakery', 'PENDING_VERIFICATION'], ['bakery', 'PENDING_CONFIRMATION']] as [$c, $s]) {
            $this->assertFalse(R::zoneEligibility(['category_slug' => $c, 'outer_city_status' => $s], 'OUTER_CITY')['eligible'], "$c $s");
        }
        $this->assertTrue(R::zoneEligibility(['category_slug' => 'doi-dairy', 'outer_city_status' => 'APPROVED'], 'OUTER_CITY')['eligible']);
    }

    /* ordering hours: boundaries 09:59, 10:00, 21:59, 22:00 */
    public function test_hours_boundaries(): void
    {
        $this->assertFalse(R::orderingStatus($this->at('2026-03-10T03:59:00Z'))['open']); // 09:59
        $this->assertTrue(R::orderingStatus($this->at('2026-03-10T04:00:00Z'))['open']); // 10:00
        $this->assertTrue(R::orderingStatus($this->at('2026-03-10T15:59:00Z'))['open']); // 21:59
        $this->assertFalse(R::orderingStatus($this->at('2026-03-10T16:00:00Z'))['open']); // 22:00
    }

    public function test_reopen_and_close_times(): void
    {
        $this->assertSame('2026-03-10T04:00:00.000000Z', R::orderingStatus($this->at('2026-03-10T03:00:00Z'))['reopens_at']->utc()->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertSame('2026-03-11T04:00:00.000000Z', R::orderingStatus($this->at('2026-03-10T17:00:00Z'))['reopens_at']->utc()->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertSame('2026-03-10T16:00:00.000000Z', R::orderingStatus($this->at('2026-03-10T04:00:00Z'))['closes_at']->utc()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function test_uses_dhaka_zone_not_machine_clock(): void
    {
        $this->assertSame(30, R::minutesOfDay($this->at('2026-03-10T18:30:00Z'))); // 00:30 next day in Dhaka
        // non-UTC input instant is still read in Dhaka
        $this->assertTrue(R::orderingStatus(CarbonImmutable::parse('2026-03-10T10:00:00+06:00', 'America/New_York'))['open']);
    }

    /* express pickup */
    public function test_pickup_lead_time(): void
    {
        $now = $this->at('2026-03-10T05:10:00Z'); // 11:10 Dhaka
        $this->assertSame('2026-03-10T06:10:00Z', R::earliestPickup($now)->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(['ok' => false, 'reason' => 'too_soon'], R::validatePickup($now, $this->at('2026-03-10T06:00:00Z')));
        $this->assertSame(['ok' => true], R::validatePickup($now, $this->at('2026-03-10T06:30:00Z')));
        $this->assertSame(['ok' => false, 'reason' => 'invalid'], R::validatePickup($now, null));
        $this->assertSame(['ok' => false, 'reason' => 'outside_hours'], R::validatePickup($now, $this->at('2026-03-10T17:00:00Z')));
    }

    public function test_pickup_slots(): void
    {
        $now = $this->at('2026-03-10T05:10:00Z');
        $s = R::pickupSlots($now, 4);
        $this->assertCount(4, $s);
        $this->assertSame('2026-03-10T06:30:00Z', $s[0]->utc()->format('Y-m-d\TH:i:s\Z'));
        foreach ($s as $d) {
            $this->assertGreaterThanOrEqual(R::earliestPickup($now)->getTimestamp(), $d->getTimestamp());
            $this->assertSame(0, $d->getTimestamp() % 1800);
        }
    }

    public function test_late_orders_get_next_day_slots(): void
    {
        $s = R::pickupSlots($this->at('2026-03-10T15:30:00Z'), 2); // 21:30 Dhaka
        $this->assertSame('2026-03-11T04:00:00Z', $s[0]->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    /* bulk */
    public function test_bulk_by_quantity_or_amount(): void
    {
        $cfg = ['min_total_quantity' => 5, 'min_total_poisha' => 10000];
        $b = R::bulkStatus([['quantity' => 5, 'line_poisha' => 100]], $cfg);
        $this->assertTrue($b['is_bulk']); $this->assertTrue($b['by_quantity']); $this->assertFalse($b['by_amount']);
        $b = R::bulkStatus([['quantity' => 1, 'line_poisha' => 10000]], $cfg);
        $this->assertTrue($b['is_bulk']); $this->assertFalse($b['by_quantity']); $this->assertTrue($b['by_amount']);
        $this->assertFalse(R::bulkStatus([['quantity' => 1, 'line_poisha' => 9999]], $cfg)['is_bulk']);
    }

    public function test_bulk_quantity_boundary_19_20_and_amount_boundary(): void
    {
        $this->assertFalse(R::bulkStatus([['quantity' => 19, 'line_poisha' => 1]])['is_bulk']);
        $this->assertTrue(R::bulkStatus([['quantity' => 20, 'line_poisha' => 1]])['is_bulk']);
        $this->assertFalse(R::bulkStatus([['quantity' => 1, 'line_poisha' => 1999999]])['is_bulk']);
        $this->assertTrue(R::bulkStatus([['quantity' => 1, 'line_poisha' => 2000000]])['is_bulk']);
        // threshold across multiple lines
        $this->assertTrue(R::bulkStatus([['quantity' => 1, 'line_poisha' => 1000000], ['quantity' => 1, 'line_poisha' => 1000000]])['is_bulk']);
    }

    public function test_unknown_price_never_makes_amount_true(): void
    {
        $b = R::bulkStatus([['quantity' => 1, 'line_poisha' => null]]);
        $this->assertFalse($b['is_bulk']); $this->assertNull($b['by_amount']); $this->assertNull($b['total_poisha']);
        $this->assertTrue(R::bulkStatus([['quantity' => 20, 'line_poisha' => null]])['is_bulk']);
    }

    public function test_bulk_carts_only_showroom(): void
    {
        $this->assertSame(['BULK_ORDER'], R::availableFulfilments(true));
        $this->assertSame(['HOME_DELIVERY', 'EXPRESS_PICKUP'], R::availableFulfilments(false));
    }

    /* checkout */
    private function line(string $key, array $product = self::BAKERY, int $qty = 1, ?int $poisha = null): array
    {
        return ['key' => $key, 'product' => $product, 'quantity' => $qty, 'line_poisha' => $poisha];
    }

    private function home(array $over = []): array
    {
        return array_merge(['now' => $this->at('2026-03-10T05:00:00Z'), 'method' => 'HOME_DELIVERY', 'zone' => 'IN_CITY', 'lines' => [$this->line('a')], 'address' => ['name' => 'রহিম', 'phone' => '01712345678', 'line1' => 'বাসা ১']], $over);
    }

    private function codes(array $i): array { return array_column(R::validateCheckout($i), 'code'); }

    public function test_valid_home_delivery_and_closed_and_empty(): void
    {
        $this->assertSame([], $this->codes($this->home()));
        $this->assertContains('ordering_closed', $this->codes($this->home(['now' => $this->at('2026-03-10T17:00:00Z')])));
        $this->assertContains('cart_empty', $this->codes($this->home(['lines' => []])));
    }

    public function test_outer_city_names_every_ineligible_line(): void
    {
        $r = R::validateCheckout($this->home(['zone' => 'OUTER_CITY', 'lines' => [$this->line('ok'), $this->line('bad', self::SWEET_PLAIN), $this->line('ok2', self::SWEET_APPROVED)]]));
        $this->assertSame([['code' => 'item_not_deliverable', 'line' => 'bad']], $r);
    }

    public function test_zone_and_address_required_for_delivery(): void
    {
        $this->assertContains('zone_required', $this->codes($this->home(['zone' => null])));
        $this->assertSame(['address_name_required', 'address_phone_invalid', 'address_line_required'], $this->codes($this->home(['address' => ['name' => ' ', 'phone' => '123', 'line1' => '']])));
    }

    public function test_pickup_needs_outlet_and_slot(): void
    {
        $p = fn (array $o = []) => array_merge(['now' => $this->at('2026-03-10T05:00:00Z'), 'method' => 'EXPRESS_PICKUP', 'zone' => null, 'lines' => [$this->line('a')], 'address' => ['name' => 'রহিম', 'phone' => '01712345678', 'line1' => ''], 'outlet_id' => 1, 'pickup_at' => $this->at('2026-03-10T06:30:00Z')], $o);
        $this->assertSame([], $this->codes($p()));
        $this->assertSame(['outlet_required'], $this->codes($p(['outlet_id' => null])));
        $this->assertSame(['pickup_too_soon'], $this->codes($p(['pickup_at' => $this->at('2026-03-10T05:30:00Z')])));
        $this->assertSame(['pickup_outside_hours'], $this->codes($p(['pickup_at' => $this->at('2026-03-10T17:00:00Z')])));
        $this->assertSame(['pickup_invalid'], $this->codes($p(['pickup_at' => null])));
    }

    public function test_bulk_carts_must_use_showroom(): void
    {
        $now = $this->at('2026-03-10T05:00:00Z');
        $big = [$this->line('a', self::BAKERY, 20)];
        $contact = ['name' => 'রহিম', 'phone' => '01712345678', 'line1' => ''];
        $this->assertContains('bulk_requires_collection', $this->codes($this->home(['lines' => $big])));
        $this->assertContains('bulk_requires_collection', $this->codes(['now' => $now, 'method' => 'EXPRESS_PICKUP', 'zone' => null, 'lines' => $big, 'address' => $contact, 'outlet_id' => 1, 'pickup_at' => $this->at('2026-03-10T07:00:00Z')]));
        $this->assertSame(['address_name_required', 'address_phone_invalid'], $this->codes(['now' => $now, 'method' => 'BULK_ORDER', 'zone' => null, 'lines' => $big]));
        $this->assertSame([], $this->codes(['now' => $now, 'method' => 'BULK_ORDER', 'zone' => null, 'lines' => $big, 'address' => $contact]));
        $this->assertContains('not_bulk', $this->codes(['now' => $now, 'method' => 'BULK_ORDER', 'zone' => null, 'lines' => [$this->line('a')], 'address' => $contact]));
    }

    public function test_bulk_by_amount_forces_showroom(): void
    {
        $this->assertContains('bulk_requires_collection', $this->codes($this->home(['lines' => [$this->line('a', self::BAKERY, 1, 2000000)]])));
        $this->assertNotContains('bulk_requires_collection', $this->codes($this->home(['lines' => [$this->line('a', self::BAKERY, 1, 1999999)]])));
    }

    public function test_showroom_prepaid_setting(): void
    {
        $now = $this->at('2026-03-10T05:00:00Z');
        $base = ['now' => $now, 'method' => 'BULK_ORDER', 'zone' => null, 'lines' => [$this->line('a', self::BAKERY, 20)], 'address' => ['name' => 'রহিম', 'phone' => '01712345678', 'line1' => '']];
        $this->assertSame([], $this->codes($base + ['bulk_prepaid' => false]));
        $this->assertSame(['bulk_prepayment_required'], $this->codes($base + ['bulk_prepaid' => true]));
        $this->assertSame([], $this->codes($base + ['bulk_prepaid' => true, 'payment_method' => 'BKASH', 'payment_details' => ['trx_id' => 'AB12CD34', 'sender_number' => '01812345678']]));
    }

    public function test_mobile_payment_needs_trx_and_sender(): void
    {
        $this->assertSame([], $this->codes($this->home(['payment_method' => 'COD'])));
        $this->assertSame(['payment_trx_required', 'payment_sender_invalid'], $this->codes($this->home(['payment_method' => 'NAGAD'])));
        $this->assertSame([], $this->codes($this->home(['payment_method' => 'BKASH', 'payment_details' => ['trx_id' => 'AB12CD34', 'sender_number' => '+8801812345678']])));
        $this->assertSame(['payment_method_invalid'], $this->codes($this->home(['payment_method' => 'CARD'])));
    }

    public function test_custom_hours_and_bulk_threshold(): void
    {
        $cfg = ['hours' => ['open_minute' => 480, 'close_minute' => 1200], 'bulk' => ['min_total_quantity' => 3, 'min_total_poisha' => 1000000]];
        $t = $this->at('2026-03-10T02:30:00Z'); // 08:30 Dhaka
        $this->assertContains('ordering_closed', $this->codes($this->home(['now' => $t])));
        $this->assertSame([], R::validateCheckout($this->home(['now' => $t, 'config' => $cfg])));
        $this->assertContains('bulk_requires_collection', $this->codes($this->home(['now' => $t, 'config' => $cfg, 'lines' => [$this->line('a', self::BAKERY, 3)]])));
    }

    public function test_hhmm_helpers(): void
    {
        $this->assertSame('10:00', R::minutesToHHMM(600));
        $this->assertSame(1350, R::hhmmToMinutes('22:30'));
        $this->assertNull(R::hhmmToMinutes('25:00'));
        $this->assertNull(R::hhmmToMinutes('abc'));
    }

    /* status flow */
    public function test_flow_one_step_or_cancel(): void
    {
        $this->assertSame(['PREPARING', 'CANCELLED'], R::nextStatuses('HOME_DELIVERY', 'PLACED'));
        $this->assertSame(['PICKED_UP', 'CANCELLED'], R::nextStatuses('EXPRESS_PICKUP', 'READY_FOR_PICKUP'));
        $this->assertSame(['PREPARING', 'CANCELLED'], R::nextStatuses('BULK_ORDER', 'PLACED'));
        $this->assertSame(['COLLECTED', 'CANCELLED'], R::nextStatuses('BULK_ORDER', 'READY_FOR_COLLECTION'));
    }

    public function test_flow_locks_final_cancelled_and_foreign_statuses(): void
    {
        $this->assertSame([], R::nextStatuses('HOME_DELIVERY', 'DELIVERED'));
        $this->assertSame([], R::nextStatuses('BULK_ORDER', 'CANCELLED'));
        $this->assertSame([], R::nextStatuses('HOME_DELIVERY', 'READY_FOR_PICKUP'));
        $this->assertFalse(R::canTransition('HOME_DELIVERY', 'PLACED', 'OUT_FOR_DELIVERY')); // no skipping
        $this->assertFalse(R::canTransition('HOME_DELIVERY', 'PREPARING', 'PLACED')); // no going back
        $this->assertTrue(R::canTransition('HOME_DELIVERY', 'PREPARING', 'CANCELLED'));
    }

    public function test_initial_and_final(): void
    {
        $this->assertSame('PLACED', R::initialStatus('BULK_ORDER'));
        $this->assertSame('COLLECTED', R::finalStatus('BULK_ORDER'));
        $this->assertSame('PICKUP_REQUESTED', R::initialStatus('EXPRESS_PICKUP'));
    }

    /* fees */
    public function test_fee_home_delivery_only(): void
    {
        $fees = ['IN_CITY' => 6000, 'OUTER_CITY' => 12000];
        $this->assertSame(6000, R::deliveryFeePoisha('HOME_DELIVERY', 'IN_CITY', $fees));
        $this->assertSame(12000, R::deliveryFeePoisha('HOME_DELIVERY', 'OUTER_CITY', $fees));
        $this->assertSame(0, R::deliveryFeePoisha('EXPRESS_PICKUP', null, $fees));
        $this->assertSame(0, R::deliveryFeePoisha('BULK_ORDER', null, $fees));
        $this->assertSame(0, R::deliveryFeePoisha('HOME_DELIVERY', 'IN_CITY'));
    }

    public function test_delivery_estimates(): void
    {
        $this->assertSame(24, R::DELIVERY_ESTIMATE['IN_CITY']['max_hours']);
        $this->assertSame([24, 48], array_values(R::DELIVERY_ESTIMATE['OUTER_CITY']));
    }
}
