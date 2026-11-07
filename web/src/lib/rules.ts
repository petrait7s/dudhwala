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
  bulk_prepaid: false,
  outlets: [],
};

export const DELIVERY_ESTIMATE: Record<Zone, { bn: string; en: string }> = {
  IN_CITY: { bn: '২৪ ঘণ্টার মধ্যে', en: 'within 24 hours' },
  OUTER_CITY: { bn: '২৪ থেকে ৪৮ ঘণ্টা', en: '24 to 48 hours' },
};

const DHAKA_MS = 6 * 3600_000;
export const dhakaMinutes = (at: Date) => Math.floor((((at.getTime() + DHAKA_MS) % 86_400_000) + 86_400_000) % 86_400_000 / 60_000);

export function orderingOpen(now: Date, h = DEFAULT_CONFIG.hours): boolean {
  const m = dhakaMinutes(now);
  return m >= h.open_minute && m < h.close_minute;
}
export const hhmm = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

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
