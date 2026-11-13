import { getCatalogue, type ApiProduct, type ApiVariant } from '../lib/api';
import { addToCart } from '../lib/cart';
import { formatTaka } from '../lib/money';
import { getZone, onZone } from '../lib/zone';
import { zoneEligible, type OuterStatus } from '../lib/rules';

const both = (bn: string, en: string) => `<span class="bn">${bn}</span><span class="en">${en}</span>`;

/** Fills in live prices, size labels, stock badge and the quick-add button on product cards. Without an API (or without a price) the "price to be confirmed" text stays. */
export async function fillPrices(scope: ParentNode = document) {
  const cat = await getCatalogue();
  scope.querySelectorAll<HTMLElement>('.pc[data-slug]').forEach((card) => {
    const p = cat[card.dataset.slug!];
    if (!p) return;
    card.dataset.live = '1';
    const prices = p.variants.map((v) => v.price_poisha).filter((x): x is number => x !== null);
    const anyStock = p.variants.some((v) => v.in_stock);
    if (p.image_url) {
      const ph = card.querySelector<HTMLElement>('[data-ph]');
      if (ph) { ph.innerHTML = ''; const im = new Image(); im.src = p.image_url; im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.classList.add('bl'); im.onload = () => im.classList.add('ld'); ph.appendChild(im); }
    }
    if (!anyStock) {
      card.classList.add('out');
      const ph = card.querySelector<HTMLElement>('[data-ph]');
      if (ph && !ph.querySelector('.badge-out')) ph.insertAdjacentHTML('beforeend', `<span class="badge-out">${both('স্টকে নেই', 'Out of stock')}</span>`);
    }
    const labels = p.variants.map((v) => v.label_bn).filter(Boolean).join(' · ');
    const lab = card.querySelector<HTMLElement>('[data-labels]'); if (lab) lab.textContent = labels;
    if (prices.length) {
      const min = Math.min(...prices);
      card.dataset.price = String(min);
      const el = card.querySelector<HTMLElement>('[data-price]');
      if (el) {
        el.classList.remove('soon');
        const more = new Set(prices).size > 1;
        el.innerHTML = both(`${formatTaka(min, 'bn')}${more ? ' থেকে' : ''}`, `${more ? 'from ' : ''}${formatTaka(min, 'en')}`);
      }
    }
    wireQuick(card, p);
  });
}

/** One-tap add for single-size priced products; products with sizes open their page. */
function wireQuick(card: HTMLElement, p: ApiProduct) {
  const btn = card.querySelector<HTMLButtonElement>('[data-quick]');
  if (!btn) return;
  const sizes = p.variants.filter((v) => v.price_poisha !== null && v.in_stock);
  const ok = sizes.length > 0;
  const refresh = () => {
    const z = zoneEligible(card.dataset.cat!, (card.dataset.outer || null) as OuterStatus, getZone());
    btn.hidden = !ok;
    btn.disabled = !z;
  };
  refresh();
  if (card.dataset.quickWired) return;
  card.dataset.quickWired = '1';
  onZone(refresh);
  const line = (v: ApiVariant) => ({ variantId: v.id, slug: card.dataset.slug!, nameBn: card.querySelector('.nm .bn')!.textContent!, nameEn: card.querySelector('.nm .en')!.textContent!, labelBn: v.label_bn, labelEn: v.label_en ?? v.label_bn, unitPoisha: v.price_poisha!, maxQty: v.max_qty, category: card.dataset.cat!, outer: card.dataset.outer || null });
  btn.addEventListener('click', () => {
    if (btn.disabled || !ok) return;
    if (sizes.length === 1) { addToCart(line(sizes[0]!)); (window as any).dwFly?.(btn); return; }
    openSizes(card, btn, sizes, (v) => { addToCart(line(v)); (window as any).dwFly?.(btn); });
  });
}

/** Size picker (per piece / per kg ...) shown above the card's button; tapping a size adds it to the cart. */
function openSizes(card: HTMLElement, btn: HTMLElement, sizes: ApiVariant[], pick: (v: ApiVariant) => void) {
  document.querySelectorAll('.sizepick').forEach((e) => e.remove());
  const box = document.createElement('div');
  box.className = 'sizepick'; box.setAttribute('role', 'dialog');
  box.innerHTML = `<p class="sp-t">${both('সাইজ বাছুন', 'Choose size')} · ${card.querySelector('.nm')?.innerHTML ?? ''}</p>` + sizes.map((v, i) =>
    `<button type="button" class="sp-o" data-i="${i}"><span>${both(esc(v.label_bn), esc(v.label_en ?? v.label_bn))}</span><b>${both(formatTaka(v.price_poisha!, 'bn'), formatTaka(v.price_poisha!, 'en'))}</b></button>`).join('');
  const phone = matchMedia('(max-width:859px)').matches;
  (phone ? document.body : card).appendChild(box);
  if (phone) box.classList.add('sheet');
  const close = () => { box.remove(); document.removeEventListener('click', away, true); document.removeEventListener('keydown', key); };
  const away = (e: Event) => { if (!box.contains(e.target as Node) && e.target !== btn && !btn.contains(e.target as Node)) close(); };
  const key = (e: KeyboardEvent) => { if (e.key === 'Escape') close(); };
  box.addEventListener('click', (e) => {
    const o = (e.target as HTMLElement).closest<HTMLElement>('.sp-o'); if (!o) return;
    pick(sizes[Number(o.dataset.i)]!); close();
  });
  setTimeout(() => { document.addEventListener('click', away, true); document.addEventListener('keydown', key); }, 0);
}
const esc = (t: string) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
