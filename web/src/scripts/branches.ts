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
const span = (cls: string, text: string) => { const s = document.createElement('span'); s.className = cls; s.textContent = text; return s; };
const row = (parent: HTMLElement, bn: string, en: string, cls = 'muted') => { const d = document.createElement('div'); d.className = cls; d.append(span('bn', bn), span('en', en)); parent.appendChild(d); };

if (box) getConfig().then((c) => {
  if (!c.outlets.length) return;
  const select = box.hasAttribute('data-select');
  const h2 = document.createElement('h2'); h2.style.fontSize = '1.1rem';
  h2.innerHTML = select ? '<span class="bn">কোন শাখা থেকে নেবেন?</span><span class="en">Which branch will you collect from?</span>' : '<span class="bn">আমাদের শাখাসমূহ</span><span class="en">Our branches</span>';
  box.appendChild(h2);
  const ul = document.createElement('ul'); ul.className = 'branches';
  const cards: { li: HTMLElement; o: Outlet }[] = [];
  for (const o of c.outlets) {
    const li = document.createElement('li'); li.className = 'branch';
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
