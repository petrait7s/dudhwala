import { getConfig } from '../lib/api';
import { getBranch, setBranch } from '../lib/branch';
import type { BranchHours, Outlet } from '../lib/rules';
import { toBn } from './bn';

/**
 * Branches from the admin's Outlets: name, address, phone, weekly hours.
 * With data-select the cards are choosable (showroom page) and the choice carries over to checkout.
 */
const box = document.getElementById('branches');
const DAYS: [string, string][] = [['রবি', 'Sun'], ['সোম', 'Mon'], ['মঙ্গল', 'Tue'], ['বুধ', 'Wed'], ['বৃহ', 'Thu'], ['শুক্র', 'Fri'], ['শনি', 'Sat']];

function hoursText(h: BranchHours[] | undefined): [string, string] | null {
  if (!h?.length) return null;
  const same = h.length === 7 && h.every((x) => x.opens === h[0]!.opens && x.closes === h[0]!.closes);
  if (same) return [`প্রতিদিন ${toBn(h[0]!.opens)} – ${toBn(h[0]!.closes)}`, `Daily ${h[0]!.opens} – ${h[0]!.closes}`];
  const sorted = [...h].sort((a, b) => a.weekday - b.weekday);
  return [sorted.map((x) => `${DAYS[x.weekday]![0]} ${toBn(x.opens)}–${toBn(x.closes)}`).join(', '), sorted.map((x) => `${DAYS[x.weekday]![1]} ${x.opens}–${x.closes}`).join(', ')];
}
const brand = box?.hasAttribute('data-brand') ?? false;
/** Branded storefront picture for a branch: a real photo at /outlets/<code>.jpg when present, otherwise this drawn shopfront. */
function shopfront(o: Outlet): HTMLElement {
  const wrap = document.createElement('div'); wrap.className = 'sf';
  const svg = () => {
    const bn = (o.name_bn || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
    wrap.innerHTML = `<svg viewBox="0 0 320 132" role="img" aria-label="${bn}"><defs><linearGradient id="sfs${o.id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2b2f9e"/><stop offset="1" stop-color="#5a62d8"/></linearGradient></defs>
      <rect width="320" height="132" fill="url(#sfs${o.id})"/>
      <rect x="40" y="38" width="240" height="94" fill="#fff"/>
      <rect x="40" y="26" width="240" height="22" fill="#fff"/>
      ${Array.from({ length: 12 }, (_, i) => `<path d="M${40 + i * 20} 48v-22h20v22a10 10 0 01-20 0z" fill="${i % 2 ? '#fff' : '#1d2672'}"/>`).join('')}
      <rect x="86" y="8" width="148" height="26" rx="6" fill="#1d2672" stroke="#fff" stroke-width="2"/>
      <text x="160" y="26" text-anchor="middle" fill="#fff" font-size="15" font-weight="800" font-family="inherit">দুধওয়ালা</text>
      <rect x="62" y="70" width="70" height="46" rx="4" fill="#e4e6fa" stroke="#2b2f9e" stroke-width="2"/>
      <rect x="188" y="70" width="70" height="46" rx="4" fill="#e4e6fa" stroke="#2b2f9e" stroke-width="2"/>
      <rect x="142" y="66" width="36" height="66" rx="3" fill="#2b2f9e"/><circle cx="170" cy="100" r="2.5" fill="#fff"/>
      <path d="M85 108h24M85 98h24M211 108h24M211 98h24" stroke="#2b2f9e" stroke-width="2" opacity=".35"/></svg>`;
  };
  const img = new Image(); img.alt = o.name_bn; img.loading = 'lazy';
  img.onload = () => { wrap.replaceChildren(img); };
  img.onerror = svg;
  img.src = `/outlets/${o.code}.jpg`;
  svg();
  return wrap;
}
const span = (cls: string, text: string) => { const s = document.createElement('span'); s.className = cls; s.textContent = text; return s; };
const row = (parent: HTMLElement, bn: string, en: string, cls = 'muted') => { const d = document.createElement('div'); d.className = cls; d.append(span('bn', bn), span('en', en)); parent.appendChild(d); };

if (box) getConfig().then((c) => {
  if (!c.outlets.length) return;
  const select = box.hasAttribute('data-select');
  const nb = document.getElementById('ab-branches'); if (nb) nb.textContent = String(c.outlets.length);
  const h2 = document.createElement('h2'); h2.style.fontSize = '1.1rem';
  h2.innerHTML = select ? '<span class="bn">কোন শাখা থেকে নেবেন?</span><span class="en">Which branch will you collect from?</span>' : '<span class="bn">আমাদের শাখাসমূহ</span><span class="en">Our branches</span>';
  box.appendChild(h2);
  const ul = document.createElement('ul'); ul.className = 'branches';
  const cards: { li: HTMLElement; o: Outlet }[] = [];
  for (const o of c.outlets) {
    const li = document.createElement('li'); li.className = 'branch';
    if (brand) li.appendChild(shopfront(o));
    const head = document.createElement('div'); head.className = 'bh';
    head.append(span('bn', o.name_bn), span('en', o.name_en || o.name_bn));
    if (select) { const tick = document.createElement('i'); tick.className = 'tick'; tick.textContent = '✓'; head.appendChild(tick); }
    li.appendChild(head);
    if (o.address_line) row(li, o.address_line, o.address_line);
    if (o.phone) { const d = document.createElement('div'); d.className = 'muted'; const a = document.createElement('a'); a.href = `tel:${o.phone}`; a.textContent = o.phone; d.appendChild(a); li.appendChild(d); }
    const ht = hoursText(o.hours); if (ht) row(li, ht[0], ht[1]);
    if (select) {
      li.tabIndex = 0; li.setAttribute('role', 'radio');
      const pick = () => { setBranch(String(o.id)); mark(); };
      li.addEventListener('click', (e) => { if ((e.target as HTMLElement).closest('a')) return; pick(); });
      li.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
    }
    cards.push({ li, o }); ul.appendChild(li);
  }
  if (select) ul.setAttribute('role', 'radiogroup');
  const mark = () => {
    const cur = cards.some(({ o }) => String(o.id) === getBranch()) ? getBranch() : String((c.outlets.find((x) => x.is_collection) ?? c.outlets[0])!.id);
    cards.forEach(({ li, o }) => { const on = String(o.id) === cur; li.classList.toggle('on', on); li.setAttribute('aria-checked', String(on)); });
  };
  box.appendChild(ul);
  if (select) mark();
});
