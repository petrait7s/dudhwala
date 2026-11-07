/**
 * DISPLAY-ONLY copy of the ordering rules (the server re-checks everything).
 * Mirrors app/app/Services/OrderingRules.php. Times are Asia/Dhaka (UTC+6, no DST).
 */
export type Zone = 'IN_CITY' | 'OUTER_CITY';
export type Method = 'HOME_DELIVERY' | 'EXPRESS_PICKUP' | 'BULK_ORDER';
export type OuterStatus = 'APPROVED' | 'DISABLED' | 'PENDING_VERIFICATION' | 'PENDING_CONFIRMATION' | null;

export interface Config {
  hours: { open_minute: number; close_minute: number };
  bulk: { min_total_quantity: number; min_total_poisha: number; approved?: boolean };
  fees: { IN_CITY: number; OUTER_CITY: number };
  payment_numbers: { bkash: string | null; nagad: string | null };
  bulk_prepaid: boolean;
  outlets: { id: number; code: string; name_bn: string; name_en: string | null; is_collection?: boolean; is_dispatch?: boolean }[];
}
export const DEFAULT_CONFIG: Config = {
  hours: { open_minute: 600, close_minute: 1320 },
  bulk: { min_total_quantity: 20, min_total_poisha: 2_000_000, approved: false },
  fees: { IN_CITY: 0, OUTER_CITY: 0 },
  payment_numbers: { bkash: null, nagad: null },
  bulk_prepaid: true,
  outlets: [],
};

export const DELIVERY_ESTIMATE: Record<Zone, { bn: string; en: string }> = {
  IN_CITY: { bn: '২৪ ঘণ্টার মধ্যে ডেলিভারি', en: 'Delivery within 24 hours' },
  OUTER_CITY: { bn: '১-২ দিনের মধ্যে ডেলিভারি', en: 'Delivery within 1-2 days' },
};
export const ZONE_LABEL: Record<Zone, { bn: string; en: string }> = {
  IN_CITY: { bn: 'ইন সিলেট', en: 'In Sylhet' },
  OUTER_CITY: { bn: 'আউটার সিলেট', en: 'Outer Sylhet' },
};

const DHAKA_MS = 6 * 3600_000;
export const dhakaMinutes = (at: Date) => Math.floor((((at.getTime() + DHAKA_MS) % 86_400_000) + 86_400_000) % 86_400_000 / 60_000);

export function orderingOpen(now: Date, h = DEFAULT_CONFIG.hours): boolean {
  const m = dhakaMinutes(now);
  return m >= h.open_minute && m < h.close_minute;
}
export const hhmm = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

export type Reason = 'in_city_all' | 'category_rule' | 'approved_item' | 'pending_verification' | 'pending_confirmation' | 'disabled' | 'item_not_approved';
/** Why an item can or cannot go to a zone (same logic as the server). */
export function zoneReason(categorySlug: string, status: OuterStatus, zone: Zone): { eligible: boolean; reason: Reason } {
  if (zone === 'IN_CITY') return { eligible: true, reason: 'in_city_all' };
  if (status === 'PENDING_VERIFICATION') return { eligible: false, reason: 'pending_verification' };
  if (status === 'PENDING_CONFIRMATION') return { eligible: false, reason: 'pending_confirmation' };
  if (status === 'DISABLED') return { eligible: false, reason: 'disabled' };
  if (status === 'APPROVED') return { eligible: true, reason: 'approved_item' };
  return categorySlug.toLowerCase() === 'bakery' ? { eligible: true, reason: 'category_rule' } : { eligible: false, reason: 'item_not_approved' };
}
export const REASON_TEXT: Record<Reason, { bn: string; en: string }> = {
  in_city_all: { bn: 'ইন সিলেটে সব পণ্য ডেলিভারি হয়।', en: 'All products are delivered in Sylhet.' },
  category_rule: { bn: 'আউটার সিলেটে ডেলিভারি হয়।', en: 'Delivered to Outer Sylhet.' },
  approved_item: { bn: 'আউটার সিলেটে ডেলিভারি হয়।', en: 'Delivered to Outer Sylhet.' },
  pending_verification: { bn: 'আউটার সিলেটের জন্য এখনো যাচাই হয়নি।', en: 'Not yet verified for Outer Sylhet.' },
  pending_confirmation: { bn: 'আউটার সিলেটের জন্য এখনো নিশ্চিত হয়নি।', en: 'Not yet confirmed for Outer Sylhet.' },
  disabled: { bn: 'আউটার সিলেটে এই পণ্য বন্ধ আছে।', en: 'This product is off for Outer Sylhet.' },
  item_not_approved: { bn: 'আউটার সিলেটে এই পণ্য ডেলিভারি হয় না।', en: 'This product is not delivered to Outer Sylhet.' },
};

export function zoneEligible(categorySlug: string, status: OuterStatus, zone: Zone): boolean {
  if (zone === 'IN_CITY') return true;
  if (status === 'PENDING_VERIFICATION' || status === 'PENDING_CONFIRMATION' || status === 'DISABLED') return false;
  if (status === 'APPROVED') return true;
  return categorySlug.toLowerCase() === 'bakery';
}

export function bulkStatus(lines: { quantity: number; line_poisha: number | null }[], cfg = DEFAULT_CONFIG.bulk) {
  const qty = lines.reduce((s, l) => s + l.quantity, 0);
  const unknown = lines.some((l) => l.line_poisha === null);
  const total = unknown ? null : lines.reduce((s, l) => s + (l.line_poisha ?? 0), 0);
  const byQuantity = qty >= cfg.min_total_quantity;
  const byAmount = total === null ? null : total >= cfg.min_total_poisha;
  return { isBulk: byQuantity || byAmount === true, qty, total, byQuantity, byAmount };
}

/** Pickup slots: every 30 min, at least 60 min ahead, inside hours (closing minute inclusive). */
export function pickupSlots(now: Date, count = 12, h = DEFAULT_CONFIG.hours): Date[] {
  const step = 30 * 60_000;
  let t = Math.ceil((now.getTime() + 60 * 60_000) / step) * step;
  const out: Date[] = [];
  for (let i = 0; i < 24 * 60 && out.length < count; i++, t += step) {
    const m = dhakaMinutes(new Date(t));
    if (m >= h.open_minute && m <= h.close_minute) out.push(new Date(t));
  }
  return out;
}

export const deliveryFee = (method: Method, zone: Zone | null, fees = DEFAULT_CONFIG.fees) => (method === 'HOME_DELIVERY' && zone ? fees[zone] : 0);
