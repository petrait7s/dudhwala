import { cartCount, readCart } from '../lib/cart';
import { getPages, getCatalogue, API, dbg } from '../lib/api';

const root = document.documentElement;
const safe = (f: () => void) => { try { f(); } catch { /* storage blocked */ } };

/* language + theme */
document.getElementById('lang')?.addEventListener('click', () => {
  const next = root.lang === 'en' ? 'bn' : 'en';
  root.lang = next;
  safe(() => localStorage.setItem('dw_lang', next));
  applyPlaceholders();
  window.dispatchEvent(new Event('dw:lang'));
});
document.getElementById('theme')?.addEventListener('click', () => {
  const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
  root.dataset.theme = next;
  safe(() => localStorage.setItem('dw_theme', next));
});
function applyPlaceholders() {
  document.querySelectorAll<HTMLInputElement>('[data-ph-bn]').forEach((el) => (el.placeholder = (root.lang === 'en' ? el.dataset.phEn : el.dataset.phBn) || ''));
}
applyPlaceholders();

/* cart badge */
let prevCount = cartCount(readCart());
function badge() {
  const n = cartCount(readCart());
  document.querySelectorAll<HTMLElement>('[data-cartcount]').forEach((b) => {
    b.textContent = String(n); b.hidden = n === 0;
    if (n > prevCount) { b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); b.parentElement?.classList.remove('shake'); void b.offsetWidth; b.parentElement?.classList.add('shake'); }
  });
  prevCount = n;
}
badge();
window.addEventListener('dw:cart', badge);
window.addEventListener('storage', badge);

/* scroll reveal: observer is removed after the class is added so hover transforms work */
const io = 'IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches
  ? new IntersectionObserver((es) => es.forEach((e) => {
      if (!e.isIntersecting) return;
      const el = e.target as HTMLElement;
      el.classList.add('in');
      io!.unobserve(el);
      setTimeout(() => { el.classList.remove('reveal', 'in'); }, 650);
    }), { rootMargin: '0px 0px -8% 0px' })
  : null;
export function observeReveal(scope: ParentNode = document) {
  scope.querySelectorAll<HTMLElement>('.reveal:not([data-seen])').forEach((el) => {
    el.dataset.seen = '1';
    if (io) io.observe(el); else el.classList.remove('reveal');
  });
}
observeReveal();
(window as any).dwObserve = observeReveal;

/* "pouch" flies from the button to the cart icon */
(window as any).dwFly = (from: HTMLElement) => {
  if (!from.dataset.tick) { // the button turns into a tick for a moment
    from.dataset.tick = '1'; const html = from.innerHTML; const w = from.offsetWidth;
    from.style.minWidth = w + 'px'; from.classList.add('ticked'); from.textContent = '✓';
    setTimeout(() => { from.innerHTML = html; from.classList.remove('ticked'); from.style.minWidth = ''; delete from.dataset.tick; }, 1000);
  }
  if (matchMedia('(prefers-reduced-motion: reduce)').matches || !('animate' in Element.prototype)) return;
  const targets = Array.from(document.querySelectorAll<HTMLElement>('[data-cartcount]')).map((b) => b.parentElement!).filter((e) => e.getBoundingClientRect().width > 0);
  const to = targets[0]; if (!to) return;
  const a = from.getBoundingClientRect(), b = to.getBoundingClientRect();
  const el = document.createElement('div'); el.className = 'fly'; document.body.appendChild(el);
  const x0 = a.left + a.width / 2 - 17, y0 = a.top + a.height / 2 - 17, x1 = b.left + b.width / 2 - 17, y1 = b.top + b.height / 2 - 17;
  const anim = el.animate([
    { transform: `translate(${x0}px,${y0}px) scale(1)`, opacity: 1 },
    { transform: `translate(${(x0 + x1) / 2}px,${Math.min(y0, y1) - 60}px) scale(.8)`, opacity: 1, offset: 0.55 },
    { transform: `translate(${x1}px,${y1}px) scale(.25)`, opacity: 0.2 },
  ], { duration: 650, easing: 'cubic-bezier(.22,1,.36,1)' });
  anim.onfinish = () => el.remove();
};

/* installable PWA: network-first service worker */
if ('serviceWorker' in navigator && location.protocol === 'https:') {
  addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

/* footer links to owner-written pages (managed in the admin) */
getPages().then((pages) => {
  const end = document.querySelector('[data-pages-end]');
  if (!end) return;
  for (const p of pages) {
    const li = document.createElement('li'); const a = document.createElement('a');
    a.href = `/info?p=${encodeURIComponent(p.slug)}`;
    const bn = document.createElement('span'); bn.className = 'bn'; bn.textContent = p.title_bn;
    const en = document.createElement('span'); en.className = 'en'; en.textContent = p.title_en || p.title_bn;
    a.append(bn, en); li.appendChild(a); end.before(li);
  }
});

/* mobile tab bar: the highlight glides from the previous tab to the current one */
(() => {
  const nav = document.querySelector<HTMLElement>('nav.tabs'); if (!nav) return;
  const links = Array.from(nav.querySelectorAll<HTMLElement>('a'));
  const cur = links.findIndex((a) => a.getAttribute('aria-current') === 'page');
  if (cur < 0) return;
  const ind = document.createElement('i'); ind.className = 'tab-ind'; ind.setAttribute('aria-hidden', 'true'); nav.prepend(ind);
  const put = (a: HTMLElement) => { const w = Math.min(60, a.offsetWidth - 6); ind.style.width = w + 'px'; ind.style.height = '32px'; ind.style.transform = `translate(${a.offsetLeft + (a.offsetWidth - w) / 2}px,${a.offsetTop + 5}px)`; };
  let prev = cur; try { const v = Number(sessionStorage.getItem('dw_tab')); if (Number.isInteger(v) && links[v]) prev = v; sessionStorage.setItem('dw_tab', String(cur)); } catch { /* storage blocked */ }
  ind.style.transition = 'none'; put(links[prev]!); nav.classList.add('has-ind');
  requestAnimationFrame(() => requestAnimationFrame(() => { ind.style.transition = ''; put(links[cur]!); }));
  addEventListener('resize', () => { ind.style.transition = 'none'; put(links[cur]!); });
})();

/* a light tap on the phone for tabs and add-to-cart */
document.addEventListener('click', (e) => {
  const t = e.target as HTMLElement;
  if (t.closest('nav.tabs a,[data-add],.add,.seg')) try { navigator.vibrate?.(8); } catch { /* not supported */ }
}, { passive: true });

/* /?debug=1 : shows which API the site calls and what came back */
if (new URLSearchParams(location.search).has('debug')) {
  getCatalogue().then(() => {
    const d = document.createElement('pre');
    d.style.cssText = 'position:fixed;left:8px;right:8px;top:8px;z-index:99999;background:#111;color:#8f8;padding:10px;border-radius:8px;font:12px/1.4 monospace;white-space:pre-wrap;max-height:60vh;overflow:auto';
    d.textContent = `API: ${API || '(empty)'}\nproducts: ${dbg.n ?? '-'}\nerror: ${dbg.err ?? 'none'}\ncards: ${document.querySelectorAll('.pc').length}, priced: ${document.querySelectorAll('.pc[data-live]').length}`;
    document.body.appendChild(d);
  });
}
