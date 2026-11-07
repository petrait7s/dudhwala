import aliases from '../data/search_aliases.json';
import { fillPrices } from './prices';
import { getZone, onZone } from '../lib/zone';
import { zoneEligible, type OuterStatus } from '../lib/rules';
import { toBn } from './bn';

const grid = document.getElementById('grid')!;
const cards = Array.from(grid.querySelectorAll<HTMLElement>('.pc'));
const q = document.getElementById('q') as HTMLInputElement | null;
const sort = document.getElementById('sort') as HTMLSelectElement | null;
const none = document.getElementById('none');
const params = new URLSearchParams(location.search);
let cat = params.get('c') || '';
if (q && params.get('q')) q.value = params.get('q')!.slice(0, 80);
if (q && params.get('focus')) setTimeout(() => q.focus(), 50);

function expand(text: string): string[] {
  const t = text.toLowerCase().trim();
  if (!t) return [];
  const words = t.split(/[^\p{L}\p{M}\p{N}]+/u).filter(Boolean);
  const out = new Set([t]);
  for (const g of aliases as string[][]) {
    const lg = g.map((x) => x.toLowerCase());
    if ([t, ...words].some((w) => lg.includes(w))) lg.forEach((x) => out.add(x));
  }
  return [...out];
}

function apply() {
  const terms = expand(q?.value ?? '');
  let shown = 0, hiddenByZone = 0;
  const zone = getZone();
  for (const c of cards) {
    const okZone = zoneEligible(c.dataset.cat!, (c.dataset.outer || null) as OuterStatus, zone);
    if (!okZone && (!cat || c.dataset.cat === cat)) hiddenByZone++;
    const okCat = !cat || c.dataset.cat === cat;
    const okQ = !terms.length || terms.some((t) => c.dataset.name!.includes(t) || c.dataset.cat!.includes(t));
    const on = okCat && okQ && okZone;
    c.hidden = !on;
    if (on) shown++;
  }
  if (none) none.hidden = shown > 0;
  const cnt = document.getElementById('count');
  if (cnt) cnt.innerHTML = `<span class="bn">${toBn(String(shown))}টি পণ্য</span><span class="en">${shown} products</span>`;
  // category pill counts follow the search and the zone (not the selected category)
  const per = new Map<string, number>(); let all = 0;
  for (const c of cards) {
    const okQ2 = !terms.length || terms.some((t) => c.dataset.name!.includes(t) || c.dataset.cat!.includes(t));
    if (okQ2 && zoneEligible(c.dataset.cat!, (c.dataset.outer || null) as OuterStatus, zone)) { all++; per.set(c.dataset.cat!, (per.get(c.dataset.cat!) ?? 0) + 1); }
  }
  document.querySelectorAll<HTMLElement>('[data-n]').forEach((b) => { const k = b.dataset.n!; b.textContent = toBn(String(k === '' ? all : per.get(k) ?? 0)); });
  const zn = document.getElementById('zonenote');
  if (zn) {
    zn.hidden = zone !== 'OUTER_CITY';
    zn.innerHTML = `<span class="bn">আউটার সিলেটের জন্য শুধু ডেলিভারিযোগ্য পণ্য দেখানো হচ্ছে। লুকানো পণ্য: <b>${toBn(String(hiddenByZone))}</b></span><span class="en">Showing only products that can be delivered to Outer Sylhet. Hidden: <b>${hiddenByZone}</b></span>`;
  }
  document.querySelectorAll<HTMLElement>('[data-chip]').forEach((b) => b.setAttribute('aria-pressed', String((b.dataset.chip || '') === cat)));
  const mode = sort?.value || 'name';
  const price = (c: HTMLElement) => (c.dataset.price ? Number(c.dataset.price) : Infinity);
  const sorted = [...cards].sort((a, b) =>
    mode === 'low' ? price(a) - price(b) : mode === 'high' ? (price(b) === Infinity ? -1 : price(b)) - (price(a) === Infinity ? -1 : price(a)) : 0);
  if (mode !== 'name') sorted.forEach((c) => grid.appendChild(c)); else cards.forEach((c) => grid.appendChild(c));
}

document.querySelectorAll<HTMLElement>('[data-chip]').forEach((b) => b.addEventListener('click', () => {
  cat = b.dataset.chip || '';
  const u = new URL(location.href); cat ? u.searchParams.set('c', cat) : u.searchParams.delete('c'); history.replaceState(null, '', u);
  apply();
}));
q?.addEventListener('input', apply);
sort?.addEventListener('change', apply);
onZone(apply);
apply();
fillPrices().then(apply);
