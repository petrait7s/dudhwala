import { getConfig } from '../lib/api';
import { readCart } from '../lib/cart';
import { getZone, onZone, setZone } from '../lib/zone';
import { DELIVERY_ESTIMATE, dhakaMinutes, hhmm, orderingOpen, zoneEligible, DEFAULT_CONFIG, type OuterStatus, type Zone } from '../lib/rules';
import { toBn } from './bn';

const bar = document.querySelector<HTMLElement>('[data-zonebar]');
if (bar) {
  let hours = DEFAULT_CONFIG.hours;
  const both = (bn: string, en: string) => `<span class="bn">${bn}</span><span class="en">${en}</span>`;
  const eta = bar.querySelector<HTMLElement>('[data-eta]')!, chip = bar.querySelector<HTMLElement>('[data-status]')!, warn = bar.querySelector<HTMLAnchorElement>('[data-warn]')!;

  function render() {
    const z = getZone();
    bar!.querySelectorAll<HTMLElement>('[data-z]').forEach((b) => { const on = b.dataset.z === z; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on)); });
    eta.innerHTML = both(DELIVERY_ESTIMATE[z].bn, DELIVERY_ESTIMATE[z].en);

    const now = new Date();
    if (orderingOpen(now, hours)) {
      const t = hhmm(hours.close_minute);
      chip.className = 'zchip open'; chip.innerHTML = both(`অর্ডার নেওয়া হচ্ছে · ${toBn(t)} পর্যন্ত`, `Taking orders · until ${t}`);
    } else {
      const t = hhmm(hours.open_minute); const tomorrow = dhakaMinutes(now) >= hours.open_minute;
      chip.className = 'zchip closed'; chip.innerHTML = both(`অর্ডার এখন বন্ধ · আবার খুলবে ${tomorrow ? 'আগামীকাল ' : ''}${toBn(t)}`, `Orders closed · reopens ${tomorrow ? 'tomorrow ' : ''}${t}`);
    }

    const blocked = z === 'OUTER_CITY' ? readCart().filter((l) => !zoneEligible(l.category, l.outer as OuterStatus, 'OUTER_CITY' as Zone)).length : 0;
    warn.hidden = blocked === 0;
    if (blocked) warn.innerHTML = both(`${toBn(String(blocked))}টি পণ্য আউটার সিলেটে ডেলিভারি হবে না, কার্ট দেখুন`, `${blocked} item(s) cannot be delivered to Outer Sylhet, see cart`);
  }

  bar.querySelectorAll<HTMLElement>('[data-z]').forEach((b) => b.addEventListener('click', () => setZone(b.dataset.z as Zone)));
  onZone(render); window.addEventListener('dw:cart', render);
  render();
  getConfig().then((c) => { hours = c.hours; render(); });
  setInterval(render, 30_000);
}
