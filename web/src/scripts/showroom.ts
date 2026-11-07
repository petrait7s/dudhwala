import aliases from '../data/search_aliases.json';
import { getCatalogue, getConfig, hasApi, type ApiVariant } from '../lib/api';
import { addToCart, clearCart } from '../lib/cart';
import { formatTaka } from '../lib/money';
import { DEFAULT_CONFIG, bulkStatus, type Config } from '../lib/rules';
import { toBn } from './bn';

const both = (bn: string, en: string) => `<span class="bn">${bn}</span><span class="en">${en}</span>`;
const MAX = 999;
const rows = Array.from(document.querySelectorAll<HTMLElement>('.bulk-row'));
const qty = new Map<string, number>();
const variant = new Map<string, ApiVariant>();
const meta = new Map<string, { nameBn: string; nameEn: string; cat: string }>();
let cfg: Config = DEFAULT_CONFIG;

const $ = (id: string) => document.getElementById(id)!;
const buyable = (slug: string) => { const v = variant.get(slug); return !!v && v.price_poisha !== null && v.in_stock; };

function lines() {
  return [...qty.entries()].filter(([s, n]) => n > 0 && variant.has(s)).map(([s, n]) => ({ slug: s, n, v: variant.get(s)! }));
}

function summary() {
  const ls = lines();
  const st = bulkStatus(ls.map((l) => ({ quantity: l.n, line_poisha: l.v.price_poisha === null ? null : l.v.price_poisha * l.n })), cfg.bulk);
  const need = Math.max(0, cfg.bulk.min_total_quantity - st.qty);
  const amt = st.total === null ? '' : ` · ${formatTaka(st.total, 'bn')}`;
  const amtEn = st.total === null ? '' : ` · ${formatTaka(st.total, 'en')}`;
  $('sum').innerHTML = both(`মোট: <b>${toBn(String(st.qty))}</b> টি${amt}${st.isBulk ? '' : ` · আরও লাগবে: <b>${toBn(String(need))}</b> টি`}`, `Total: <b>${st.qty}</b> items${amtEn}${st.isBulk ? '' : ` · need <b>${need}</b> more`}`);
  ($('go') as HTMLButtonElement).disabled = !st.isBulk || !hasApi();
  rows.forEach((r) => r.classList.toggle('on', (qty.get(r.dataset.slug!) ?? 0) > 0));
}

function setQ(slug: string, n: number) {
  const row = rows.find((r) => r.dataset.slug === slug)!;
  const v = variant.get(slug);
  const q = Math.max(0, Math.min(v ? Math.min(MAX, v.max_qty * 50) : MAX, Math.floor(Number.isFinite(n) ? n : 0)));
  qty.set(slug, q);
  (row.querySelector('.bulk-qty') as HTMLInputElement).value = q ? String(q) : '';
  summary();
}

rows.forEach((r) => {
  const slug = r.dataset.slug!;
  const input = r.querySelector<HTMLInputElement>('.bulk-qty')!;
  r.querySelectorAll<HTMLButtonElement>('button[data-d]').forEach((b) => b.addEventListener('click', () => { if (buyable(slug)) setQ(slug, (qty.get(slug) ?? 0) + Number(b.dataset.d)); }));
  input.addEventListener('input', () => { if (buyable(slug)) setQ(slug, Number(input.value.replace(/\D/g, ''))); else input.value = ''; });
  const names = r.querySelectorAll('.nm .bn, .nm .en');
  meta.set(slug, { nameBn: names[0]?.textContent ?? '', nameEn: names[1]?.textContent ?? '', cat: r.dataset.cat! });
});

/* search (aliases, Bangla + English) */
function expand(text: string): string[] {
  const t = text.toLowerCase().trim(); if (!t) return [];
  const words = t.split(/[^\p{L}\p{M}\p{N}]+/u).filter(Boolean); const out = new Set([t]);
  for (const g of aliases as string[][]) { const lg = g.map((x) => x.toLowerCase()); if ([t, ...words].some((w) => lg.includes(w))) lg.forEach((x) => out.add(x)); }
  return [...out];
}
($('bq') as HTMLInputElement).addEventListener('input', (e) => {
  const terms = expand((e.target as HTMLInputElement).value); let n = 0;
  rows.forEach((r) => { const on = !terms.length || terms.some((t) => r.dataset.name!.includes(t) || r.dataset.cat!.includes(t)); r.hidden = !on; if (on) n++; });
  document.querySelectorAll<HTMLElement>('section[data-cat]').forEach((s) => (s.hidden = !Array.from(s.querySelectorAll<HTMLElement>('.bulk-row')).some((r) => !r.hidden)));
  $('none').hidden = n > 0;
});

$('go').addEventListener('click', () => {
  const ls = lines();
  if (!ls.length) return;
  clearCart();
  for (const l of ls) {
    const m = meta.get(l.slug)!;
    addToCart({ variantId: l.v.id, slug: l.slug, nameBn: m.nameBn, nameEn: m.nameEn, labelBn: l.v.label_bn, labelEn: l.v.label_en ?? l.v.label_bn, unitPoisha: l.v.price_poisha!, maxQty: Math.max(l.v.max_qty, l.n), category: m.cat, outer: null }, l.n);
  }
  location.href = '/cart?method=BULK_ORDER';
});

$('noapi').hidden = hasApi();
summary();
Promise.all([getCatalogue(), getConfig()]).then(([cat, c]) => {
  cfg = c;
  const pend = c.bulk.approved === false;
  $('limit').innerHTML = both(`ন্যূনতম${pend ? ' (চূড়ান্ত নয়)' : ''}: <b>${toBn(String(c.bulk.min_total_quantity))}</b> টি পণ্য, অথবা মোট দাম <b>${formatTaka(c.bulk.min_total_poisha, 'bn')}</b> বা তার বেশি।`, `Minimum${pend ? ' (not final)' : ''}: <b>${c.bulk.min_total_quantity}</b> items, or a total of <b>${formatTaka(c.bulk.min_total_poisha, 'en')}</b> or more.`);
  rows.forEach((r) => {
    const slug = r.dataset.slug!; const p = cat[slug];
    const v = p?.variants.find((x) => x.price_poisha !== null && x.in_stock) ?? null;
    if (v) variant.set(slug, v);
    const ok = !!v;
    r.classList.toggle('off', !ok);
    r.querySelectorAll<HTMLElement>('button,input').forEach((e) => ((e as HTMLButtonElement).disabled = !ok));
    const note = r.querySelector<HTMLElement>('[data-note]')!;
    if (!ok && hasApi()) note.innerHTML = both('· দাম শীঘ্রই / স্টকে নেই', '· price soon / out of stock');
    else if (v?.label_bn) note.innerHTML = both(`· ${v.label_bn} · ${formatTaka(v.price_poisha!, 'bn')}`, `· ${v.label_en || v.label_bn} · ${formatTaka(v.price_poisha!, 'en')}`);
  });
  summary();
});
