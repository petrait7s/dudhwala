import { getConfig } from '../lib/api';

/** Lists every active branch (name, address, phone, weekly hours) from the admin's Outlets. */
const box = document.getElementById('branches');
const DAYS = [['রবি', 'Sun'], ['সোম', 'Mon'], ['মঙ্গল', 'Tue'], ['বুধ', 'Wed'], ['বৃহ', 'Thu'], ['শুক্র', 'Fri'], ['শনি', 'Sat']];
if (box) getConfig().then((c) => {
  if (!c.outlets.length) return;
  const h2 = document.createElement('h2'); h2.style.fontSize = '1.1rem';
  h2.innerHTML = '<span class="bn">আমাদের শাখাসমূহ</span><span class="en">Our branches</span>'; box.appendChild(h2);
  const ul = document.createElement('ul'); ul.className = 'bulk-list';
  for (const o of c.outlets) {
    const li = document.createElement('li'); li.className = 'bulk-row'; li.style.display = 'block';
    const nm = document.createElement('div'); nm.className = 'nm';
    const bn = document.createElement('span'); bn.className = 'bn'; bn.textContent = o.name_bn;
    const en = document.createElement('span'); en.className = 'en'; en.textContent = o.name_en || o.name_bn;
    nm.append(bn, en); li.appendChild(nm);
    const line = [o.address_line, o.phone].filter(Boolean).join(' · ');
    if (line) { const p = document.createElement('div'); p.className = 'muted'; p.textContent = line; li.appendChild(p); }
    if (o.hours?.length) {
      const p = document.createElement('div'); p.className = 'muted'; p.style.fontSize = '.85rem';
      p.textContent = o.hours.map((h) => `${DAYS[h.weekday]![1]} ${h.opens}-${h.closes}`).join(', ');
      li.appendChild(p);
    }
    ul.appendChild(li);
  }
  box.appendChild(ul);
});
