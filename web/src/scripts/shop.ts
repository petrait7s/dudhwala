import { buildSearchIndex, searchIndex, suggestCorrections } from '../lib/search';
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

/* fuzzy index: typo-tolerant, sound-alike, alias-aware (doi / dahi / yogurt / দই), Bangla and English */
const index = buildSearchIndex(cards.map((c) => ({ item: c, primary: [c.dataset.nbn!, c.dataset.nen!], secondary: [c.dataset.cbn!, c.dataset.cen!] })));
const rank = new Map<HTMLElement, number>();
let query = '';
function runSearch() {
  query = (q?.value ?? '').trim();
  rank.clear();
  if (!query) return;
  searchIndex(index, query, 500).forEach((h, i) => rank.set(h.item, i));
}

function apply() {
  runSearch();
  const searching = query !== '';
  let shown = 0, hiddenByZone = 0;
  const zone = getZone();
  for (const c of cards) {
    const okZone = zoneEligible(c.dataset.cat!, (c.dataset.outer || null) as OuterStatus, zone);
    if (!okZone && (!cat || c.dataset.cat === cat)) hiddenByZone++;
    const okCat = !cat || c.dataset.cat === cat;
    const okQ = !searching || rank.has(c);
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
    const okQ2 = !searching || rank.has(c);
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
  if (mode !== 'name') sorted.forEach((c) => grid.appendChild(c));
  else if (searching) [...cards].sort((a, b) => (rank.get(a) ?? 0) - (rank.get(b) ?? 0)).forEach((c) => grid.appendChild(c)); // best match first
  else cards.forEach((c) => grid.appendChild(c));

  // "did you mean": when nothing matched, offer the closest words
  if (none && shown === 0 && searching) {
    const sg = suggestCorrections(index, query, 3);
    let box = document.getElementById('sugg');
    if (!box) { box = document.createElement('span'); box.id = 'sugg'; none.appendChild(box); }
    box.innerHTML = '';
    if (sg.length) {
      box.append(' ');
      const lead = document.createElement('span'); lead.innerHTML = '<span class="bn">আপনি কি বোঝাতে চেয়েছেন: </span><span class="en">Did you mean: </span>'; box.appendChild(lead);
      sg.forEach((w) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'pill'; b.style.marginRight = '.4rem'; b.textContent = w; b.addEventListener('click', () => { if (q) { q.value = w; apply(); } }); box!.appendChild(b); });
    }
  } else document.getElementById('sugg')?.replaceChildren();
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
