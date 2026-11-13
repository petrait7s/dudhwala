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
  const single: ApiVariant | undefined = p.variants.length === 1 ? p.variants[0] : undefined;
  const ok = !!single && single.price_poisha !== null && single.in_stock;
  const refresh = () => {
    const z = zoneEligible(card.dataset.cat!, (card.dataset.outer || null) as OuterStatus, getZone());
    btn.hidden = !ok;
    btn.disabled = !z;
  };
  refresh();
  if (card.dataset.quickWired) return;
  card.dataset.quickWired = '1';
  onZone(refresh);
  btn.addEventListener('click', () => {
    if (!single || btn.disabled) return;
    addToCart({ variantId: single.id, slug: card.dataset.slug!, nameBn: card.querySelector('.nm .bn')!.textContent!, nameEn: card.querySelector('.nm .en')!.textContent!, labelBn: single.label_bn, labelEn: single.label_en ?? single.label_bn, unitPoisha: single.price_poisha!, maxQty: single.max_qty, category: card.dataset.cat!, outer: card.dataset.outer || null }, 1);
    (window as any).dwFly?.(btn);
  });
}
