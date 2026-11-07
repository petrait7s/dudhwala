import { describe, expect, it } from 'vitest';
import { bulkStatus, deliveryFee, inBranchHours, orderingOpen, pickupSlots, zoneEligible, dhakaMinutes } from './rules';
import { formatTaka, normalizePhone } from './money';

const at = (s: string) => new Date(s);
describe('hours (Dhaka)', () => {
  it('boundaries 09:59 10:00 21:59 22:00', () => {
    expect(orderingOpen(at('2026-03-10T03:59:00Z'))).toBe(false);
    expect(orderingOpen(at('2026-03-10T04:00:00Z'))).toBe(true);
    expect(orderingOpen(at('2026-03-10T15:59:00Z'))).toBe(true);
    expect(orderingOpen(at('2026-03-10T16:00:00Z'))).toBe(false);
  });
  it('reads Dhaka time not machine time', () => expect(dhakaMinutes(at('2026-03-10T18:30:00Z'))).toBe(30));
});
describe('eligibility', () => {
  it('bakery whole, others only approved, uncertain never', () => {
    expect(zoneEligible('bakery', null, 'OUTER_CITY')).toBe(true);
    expect(zoneEligible('doi-dairy', null, 'OUTER_CITY')).toBe(false);
    expect(zoneEligible('doi-dairy', 'APPROVED', 'OUTER_CITY')).toBe(true);
    expect(zoneEligible('bakery', 'DISABLED', 'OUTER_CITY')).toBe(false);
    expect(zoneEligible('doi-dairy', null, 'IN_CITY')).toBe(true);
  });
});
describe('bulk', () => {
  it('qty 19/20 and amount boundary; unknown price never true', () => {
    expect(bulkStatus([{ quantity: 19, line_poisha: 1 }]).isBulk).toBe(false);
    expect(bulkStatus([{ quantity: 20, line_poisha: 1 }]).isBulk).toBe(true);
    expect(bulkStatus([{ quantity: 1, line_poisha: 1_999_999 }]).isBulk).toBe(false);
    expect(bulkStatus([{ quantity: 1, line_poisha: 2_000_000 }]).isBulk).toBe(true);
    expect(bulkStatus([{ quantity: 1, line_poisha: null }])).toMatchObject({ isBulk: false, byAmount: null });
  });
});
describe('pickup + fees + money', () => {
  it('first slot is >= 60 min ahead on a 30 min step', () => {
    const now = at('2026-03-10T05:10:00Z');
    expect(pickupSlots(now, 2)[0]!.toISOString()).toBe('2026-03-10T06:30:00.000Z');
    expect(pickupSlots(at('2026-03-10T15:30:00Z'), 1)[0]!.toISOString()).toBe('2026-03-11T04:00:00.000Z');
  });
  it('fee only for home delivery', () => {
    const f = { IN_CITY: 6000, OUTER_CITY: 12000 };
    expect(deliveryFee('HOME_DELIVERY', 'OUTER_CITY', f)).toBe(12000);
    expect(deliveryFee('EXPRESS_PICKUP', null, f)).toBe(0);
    expect(deliveryFee('BULK_ORDER', null, f)).toBe(0);
  });
  it('taka + phone', () => {
    expect(formatTaka(125050, 'bn')).toBe('৳১,২৫০.৫০');
    expect(formatTaka(12345600, 'en')).toBe('৳1,23,456');
    expect(normalizePhone('+8801712345678')).toBe('01712345678');
    expect(normalizePhone('০১৭১২৩৪৫৬৭৮')).toBe('01712345678');
    expect(normalizePhone('01212345678')).toBeNull();
  });
});

describe('branch hours', () => {
  const tue = [{ weekday: 2, opens: '14:00', closes: '18:00' }];
  it('weekday + time in Dhaka, closing minute inclusive, no rows = no limit', () => {
    expect(inBranchHours(new Date('2026-03-10T09:00:00Z'), tue)).toBe(true); // Tue 15:00 Dhaka
    expect(inBranchHours(new Date('2026-03-10T12:00:00Z'), tue)).toBe(true); // Tue 18:00
    expect(inBranchHours(new Date('2026-03-10T12:01:00Z'), tue)).toBe(false);
    expect(inBranchHours(new Date('2026-03-11T09:00:00Z'), tue)).toBe(false); // Wednesday
    expect(inBranchHours(new Date('2026-03-11T09:00:00Z'), [])).toBe(true);
  });
  it('pickup slots only inside the chosen branch hours', () => {
    const slots = pickupSlots(new Date('2026-03-10T05:00:00Z'), 3, { open_minute: 600, close_minute: 1320 }, tue);
    expect(slots.map((d) => d.toISOString())).toEqual(['2026-03-10T08:00:00.000Z', '2026-03-10T08:30:00.000Z', '2026-03-10T09:00:00.000Z']); // 14:00, 14:30, 15:00 Dhaka
  });
});
