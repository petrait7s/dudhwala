import { DEFAULT_CONFIG, type Config } from './rules';

export const API = (import.meta.env.PUBLIC_API_URL as string | undefined)?.replace(/\/$/, '') || '';
export const hasApi = () => API !== '';

export interface ApiVariant { id: number; label_bn: string; label_en: string | null; price_poisha: number | null; max_qty: number; in_stock: boolean }
export interface ApiProduct { slug: string; image_url: string | null; variants: ApiVariant[] }

async function j<T>(path: string, init?: RequestInit): Promise<T> {
  const r = await fetch(API + path, { ...init, headers: { Accept: 'application/json', ...(init?.body ? { 'Content-Type': 'application/json' } : {}), ...init?.headers } });
  const body = await r.json().catch(() => ({}));
  if (!r.ok) throw Object.assign(new Error(body?.error?.code || 'ERROR'), { status: r.status, body });
  return body as T;
}

let catalogue: Promise<Record<string, ApiProduct>> | null = null;
/** Live prices, variant ids and stock. Resolves {} when no API is configured or it is unreachable. */
export function getCatalogue(): Promise<Record<string, ApiProduct>> {
  if (!hasApi()) return Promise.resolve({});
  catalogue ??= j<{ products: ApiProduct[] }>('/catalogue').then((r) => Object.fromEntries(r.products.map((p) => [p.slug, p]))).catch(() => ({}));
  return catalogue;
}
export async function getConfig(): Promise<Config> {
  if (!hasApi()) return DEFAULT_CONFIG;
  return j<Partial<Config>>('/config').then((c) => ({ ...DEFAULT_CONFIG, ...c })).catch(() => DEFAULT_CONFIG);
}

export interface OrderRequest {
  idempotency_key: string;
  method: string;
  zone: string | null;
  items: { variant_id: number; quantity: number }[];
  contact: { name: string; phone: string; address_line1?: string; landmark?: string };
  outlet_id?: number | null;
  pickup_at?: string | null;
  payment: { method: string; trx_id?: string; sender_number?: string };
  website?: string; // honeypot, must stay empty
}
export interface OrderResult { order_number: string; status: string; total_poisha: number; subtotal_poisha: number; delivery_fee_poisha: number }
export const createOrder = (b: OrderRequest) => j<OrderResult>('/orders', { method: 'POST', body: JSON.stringify(b) });
export const trackOrder = (order_number: string, phone: string) => j<any>('/orders/track', { method: 'POST', body: JSON.stringify({ order_number, phone }) });

export interface InfoPage { slug: string; title_bn: string; title_en: string | null; body_bn: string; body_en: string | null }
let pages: Promise<InfoPage[]> | null = null;
/** Owner-written pages (policies, FAQ) edited in the admin. */
export function getPages(): Promise<InfoPage[]> {
  if (!hasApi()) return Promise.resolve([]);
  pages ??= j<{ pages: InfoPage[] }>('/pages').then((r) => r.pages).catch(() => []);
  return pages;
}
