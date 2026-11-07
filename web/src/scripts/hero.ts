/**
 * Hero slideshow. Hold 4s, fade 1.9s. The new slide fades in ON TOP of the old one (old stays opaque below, so there is
 * no dark dip) and the old one keeps its zoom until covered. A slide is only shown after img.decode() resolves.
 * Pauses when the tab is hidden or the hero is off-screen. Desktop-only mouse parallax on requestAnimationFrame.
 */
const HOLD = 4000, FADE = 1900;
const hero = document.querySelector<HTMLElement>('[data-hero]');
const slides = hero ? Array.from(hero.querySelectorAll<HTMLElement>('.slide')) : [];
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

if (hero && slides.length > 1 && !reduced) {
  let i = 0, timer = 0, busy = false, tabOn = !document.hidden, inView = true;
  const schedule = () => { clearTimeout(timer); if (tabOn && inView) timer = window.setTimeout(next, HOLD + FADE); };

  async function next() {
    if (busy || !tabOn || !inView) return;
    busy = true;
    const cur = slides[i]!, n = (i + 1) % slides.length, nxt = slides[n]!;
    const img = nxt.querySelector('img')!;
    img.loading = 'eager';
    try { await img.decode(); } catch { /* broken image: skip this slide */ busy = false; i = n; schedule(); return; }
    cur.classList.add('under'); cur.style.zIndex = '1';
    nxt.style.zIndex = '2';
    nxt.classList.add('on');
    setTimeout(() => { cur.classList.remove('on', 'under'); cur.style.zIndex = ''; nxt.style.zIndex = ''; i = n; busy = false; schedule(); }, FADE + 50);
  }

  document.addEventListener('visibilitychange', () => { tabOn = !document.hidden; if (tabOn) schedule(); else clearTimeout(timer); });
  new IntersectionObserver(([e]) => { inView = !!e?.isIntersecting; if (inView) schedule(); else clearTimeout(timer); }).observe(hero);
  schedule();
}

/* mouse parallax: desktop only */
if (hero && !reduced && matchMedia('(hover: hover) and (pointer: fine)').matches) {
  const layer = hero.querySelector<HTMLElement>('[data-slides]');
  const copy = hero.querySelector<HTMLElement>('[data-copy]');
  let tx = 0, ty = 0, raf = 0;
  hero.addEventListener('mousemove', (e) => {
    const r = hero.getBoundingClientRect();
    tx = ((e.clientX - r.left) / r.width - 0.5) * 2; ty = ((e.clientY - r.top) / r.height - 0.5) * 2;
    if (!raf) raf = requestAnimationFrame(() => {
      raf = 0;
      if (layer) layer.style.transform = `translate3d(${(-tx * 10).toFixed(1)}px,${(-ty * 6).toFixed(1)}px,0)`;
      if (copy) copy.style.transform = `translate3d(${(tx * 6).toFixed(1)}px,${(ty * 4).toFixed(1)}px,0)`;
    });
  });
  hero.addEventListener('mouseleave', () => { if (layer) layer.style.transform = ''; if (copy) copy.style.transform = ''; });
}
