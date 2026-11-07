import { getCatalogue, type ApiVariant } from '../lib/api';
import { addToCart } from '../lib/cart';
import { formatTaka } from '../lib/money';
import { getZone, onZone } from '../lib/zone';
import { REASON_TEXT, zoneReason, zoneEligible, type OuterStatus } from '../lib/rules';
import { fillPrices } from './prices';

const root = document.querySelector<HTMLElement>('[data-product]')!;
const $ = <T extends HTMLElement>(s: string) => root.parentElement!.querySelector<T>(s)!;
const slug = root.dataset.slug!;
let variants: ApiVariant[] = [];
let cur: ApiVariant | null = null;
let qty = 1;

const zoneOk = () => zoneReason(root.dataset.cat!, (root.dataset.outer || null) as OuterStatus, getZone());
const priced = (v: ApiVariant | null): v is ApiVariant & { price_poisha: number } => !!v && v.price_poisha !== null && v.in_stock;
const both = (f: (l: 'bn' | 'en') => string) => `<span class="bn">${f('bn')}</span><span class="en">${f('en')}</span>`;

function render() {
  const z = zoneOk();
  const ok = priced(cur) && z.eligible;
  const priceEl = $('[data-price]');
  if (cur && cur.price_poisha !== null) priceEl.innerHTML = both((l) => formatTaka(cur!.price_poisha! * qty, l));
  ($('[data-add]') as HTMLButtonElement).disabled = !ok;
  ($('[data-buy]') as HTMLButtonElement).disabled = !ok;
  ($('[data-add2]') as HTMLButtonElement).disabled = !ok;
  ($('[data-buy2]') as HTMLButtonElement).disabled = !ok;
  $('[data-qty]').textContent = String(qty);
  $('[data-bartotal]').innerHTML = cur && cur.price_poisha !== null ? both((l) => formatTaka(cur!.price_poisha! * qty, l)) : '';
  const na = $('[data-na]');
  na.hidden = !((variants.length && !ok) || !z.eligible);
  if (!z.eligible) na.innerHTML = `<span class="bn">${REASON_TEXT[z.reason].bn}</span><span class="en">${REASON_TEXT[z.reason].en}</span>`;
  else if (cur && cur.price_poisha !== null && !cur.in_stock) na.innerHTML = '<span class="bn">স্টকে নেই</span><span class="en">Out of stock</span>';
  else na.innerHTML = '<span class="bn">এখন অর্ডার করা যাচ্ছে না</span><span class="en">Not available to order yet</span>';
}

function pick(v: ApiVariant) {
  cur = v; qty = 1;
  $('[data-sizes]').querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(Number((b as HTMLElement).dataset.id) === v.id)));
  render();
}

function line() {
  const v = cur!;
  return {
    variantId: v.id, slug, nameBn: root.dataset.nameBn!, nameEn: root.dataset.nameEn!, labelBn: v.label_bn, labelEn: v.label_en ?? v.label_bn,
    unitPoisha: v.price_poisha!, maxQty: v.max_qty, category: root.dataset.cat!, outer: root.dataset.outer || null,
  };
}
function add(btn: HTMLElement) {
  if (!priced(cur) || !zoneOk().eligible) return false;
  addToCart(line(), qty);
  (window as any).dwFly?.(btn);
  return true;
}
const click = (sel: string, f: (el: HTMLElement) => void) => $(sel).addEventListener('click', (e) => f(e.currentTarget as HTMLElement));
click('[data-add]', (b) => add(b));
click('[data-add2]', (b) => add(b));
click('[data-buy]', (b) => { if (add(b)) location.href = '/cart'; });
click('[data-buy2]', (b) => { if (add(b)) location.href = '/cart'; });
click('[data-minus]', () => { qty = Math.max(1, qty - 1); render(); });
click('[data-plus]', () => { if (cur) qty = Math.min(cur.max_qty, qty + 1); render(); });

onZone(render);
/* related products: live prices, and only what can go to the chosen zone */
const rel = Array.from(document.querySelectorAll<HTMLElement>('.rel-list .pc'));
const relZone = () => rel.forEach((c) => { (c.closest('li') as HTMLElement).hidden = !zoneEligible(c.dataset.cat!, (c.dataset.outer || null) as OuterStatus, getZone()); });
relZone(); onZone(relZone); fillPrices();
const note = root.dataset.outer;
if (note === 'DISABLED' || note === 'PENDING_VERIFICATION' || note === 'PENDING_CONFIRMATION') {
  $('[data-outer-note]').innerHTML = '<span class="bn">আউটার সিলেটে ডেলিভারি এখন নেই।</span><span class="en">Not available for Outer Sylhet delivery.</span>';
}

getCatalogue().then((cat) => {
  const p = cat[slug];
  if (!p) return;
  variants = p.variants;
  if (p.image_url) { const pic = $('[data-pic]'); pic.innerHTML = ''; const im = new Image(); im.src = p.image_url; im.alt = ''; im.decoding = 'async'; pic.appendChild(im); }
  const box = $('[data-sizes]');
  variants.forEach((v) => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'chip'; b.dataset.id = String(v.id);
    b.innerHTML = `<span class="bn">${v.label_bn || '—'}</span><span class="en">${v.label_en || v.label_bn || '—'}</span>`;
    b.addEventListener('click', () => pick(v));
    box.appendChild(b);
  });
  $('[data-sizes-wrap]').hidden = variants.length < 2 || variants.every((v) => !v.label_bn);
  $('[data-qty-wrap]').hidden = false;
  const first = variants.find((v) => priced(v)) ?? variants[0] ?? null;
  if (first) pick(first); else render();
});
