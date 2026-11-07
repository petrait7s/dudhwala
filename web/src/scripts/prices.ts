import { getCatalogue } from '../lib/api';
import { formatTaka } from '../lib/money';

/** Fills in live prices on product cards. Without an API (or without a price) the "price to be confirmed" text stays. */
export async function fillPrices(scope: ParentNode = document) {
  const cat = await getCatalogue();
  scope.querySelectorAll<HTMLElement>('[data-slug]').forEach((card) => {
    const p = cat[card.dataset.slug!];
    if (!p) return;
    card.dataset.live = '1';
    const prices = p.variants.map((v) => v.price_poisha).filter((x): x is number => x !== null);
    if (p.image_url) {
      const ph = card.querySelector<HTMLElement>('[data-ph]');
      if (ph) { ph.innerHTML = ''; const im = new Image(); im.src = p.image_url; im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; ph.appendChild(im); }
    }
    if (!prices.length) return;
    const min = Math.min(...prices);
    card.dataset.price = String(min);
    const el = card.querySelector<HTMLElement>('[data-price]');
    if (el) {
      el.classList.remove('soon');
      const more = new Set(prices).size > 1;
      el.innerHTML = `<span class="bn">${more ? 'শুরু ' : ''}${formatTaka(min, 'bn')}</span><span class="en">${more ? 'from ' : ''}${formatTaka(min, 'en')}</span>`;
    }
  });
}
