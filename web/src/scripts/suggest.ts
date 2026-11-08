import { buildSearchIndex, searchIndex, suggestCorrections } from '../lib/search';

interface P { id: string; bn: string; en: string; cbn: string; cen: string }
const el = document.getElementById('dw-suggest');
let list: P[] = [];
try { list = JSON.parse(el?.textContent || '[]'); } catch { /* no data */ }
const index = buildSearchIndex(list.map((p) => ({ item: p, primary: [p.bn, p.en], secondary: [p.cbn, p.cen] })));
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
const lang = () => (document.documentElement.lang === 'en' ? 'en' : 'bn');

function attach(input: HTMLInputElement, mode: 'go' | 'fill') {
  if (!list.length) return;
  const box = document.createElement('ul');
  box.className = 'sugg';
  box.id = 'sg-' + Math.random().toString(36).slice(2, 7);
  box.setAttribute('role', 'listbox');
  box.hidden = true;
  (input.parentElement as HTMLElement).style.position = 'relative';
  input.parentElement!.appendChild(box);
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', box.id);
  let active = -1;

  const close = () => { box.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); };
  const mark = (txt: string, q: string) => {
    const i = txt.toLowerCase().indexOf(q.toLowerCase());
    return i < 0 || !q ? esc(txt) : esc(txt.slice(0, i)) + '<mark>' + esc(txt.slice(i, i + q.length)) + '</mark>' + esc(txt.slice(i + q.length));
  };
  const pick = (li: HTMLElement) => {
    if (li.dataset.fix) { input.value = li.dataset.fix; render(); input.dispatchEvent(new Event('input', { bubbles: true })); return; }
    const p = list.find((x) => x.id === li.dataset.id);
    if (!p) return;
    if (mode === 'go') { location.href = '/product/' + p.id; return; }
    input.value = lang() === 'en' ? p.en : p.bn;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    close();
  };
  function render() {
    const q = input.value.trim();
    if (!q) { close(); return; }
    const L = lang();
    const hits = searchIndex(index, q, 7);
    let html = '';
    if (!hits.length) {
      const fixes = suggestCorrections(index, q, 3);
      if (!fixes.length) html = `<li class="sg-none" aria-disabled="true"><span class="sg-k">${L === 'en' ? 'No match. Try another spelling.' : 'কিছু পাওয়া যায়নি। অন্যভাবে লিখে দেখুন।'}</span></li>`;
      else html = fixes.map((f) => `<li role="option" data-fix="${esc(f)}"><span class="sg-k">${L === 'en' ? 'Did you mean' : 'আপনি কি বুঝিয়েছেন'}</span> <b>${esc(f)}</b>?</li>`).join('');
    } else {
      html = hits.map(({ item: p }) => {
        const main = L === 'en' ? p.en : p.bn, alt = L === 'en' ? p.bn : p.en;
        return `<li role="option" data-id="${esc(p.id)}"><span class="sg-n">${mark(main, q)}</span><span class="sg-c">${esc(alt)} · ${esc(L === 'en' ? p.cen : p.cbn)}</span></li>`;
      }).join('');
    }
    if (!html) { close(); return; }
    box.innerHTML = html;
    box.style.left = input.offsetLeft + 'px';
    box.style.right = 'auto';
    box.style.width = input.offsetWidth + 'px';
    box.style.top = input.offsetTop + input.offsetHeight + 6 + 'px';
    box.hidden = false;
    active = -1;
    input.setAttribute('aria-expanded', 'true');
  }
  const move = (d: number) => {
    const items = [...box.children] as HTMLElement[];
    if (!items.length) return;
    active = (active + d + items.length) % items.length;
    items.forEach((li, i) => li.classList.toggle('on', i === active));
    items[active]?.scrollIntoView({ block: 'nearest' });
  };
  input.addEventListener('input', render);
  input.addEventListener('focus', render);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (box.hidden) render(); move(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Escape') close();
    else if (e.key === 'Enter' && active >= 0 && !box.hidden) { e.preventDefault(); pick(box.children[active] as HTMLElement); }
  });
  box.addEventListener('mousedown', (e) => { e.preventDefault(); const li = (e.target as HTMLElement).closest('li'); if (li) pick(li as HTMLElement); });
  document.addEventListener('click', (e) => { if (!input.parentElement!.contains(e.target as Node)) close(); });
  window.addEventListener('dw:lang', render);
}

const h = document.querySelector<HTMLInputElement>('.hsearch input');
if (h) attach(h, 'go');
const q = document.getElementById('q') as HTMLInputElement | null;
if (q) attach(q, 'fill');
