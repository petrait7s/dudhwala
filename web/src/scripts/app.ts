import { cartCount, readCart } from '../lib/cart';
import { getPages } from '../lib/api';

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
function badge() {
  const n = cartCount(readCart());
  document.querySelectorAll<HTMLElement>('[data-cartcount]').forEach((b) => { b.textContent = String(n); b.hidden = n === 0; });
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
