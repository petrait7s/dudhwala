/**
 * Product photo slider: swipe (scroll-snap), arrows, dots, thumbnails, keyboard, gentle auto-advance.
 * Auto-advance stops for good once the visitor touches it, pauses off-screen / hidden tab, and never runs under reduced motion.
 */
export function initGallery(root: HTMLElement) {
  const track = root.querySelector<HTMLElement>('[data-track]')!;
  const dots = root.querySelector<HTMLElement>('[data-dots]')!;
  const thumbsBox = root.parentElement!.querySelector<HTMLElement>('[data-thumbs]')!;
  const prev = root.querySelector<HTMLButtonElement>('[data-prev]')!;
  const next = root.querySelector<HTMLButtonElement>('[data-next]')!;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  let urls: string[] = [];
  let timer = 0, touched = false, onScreen = true;

  const slides = () => Array.from(track.children) as HTMLElement[];
  const count = () => slides().length;
  const index = () => Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
  const go = (i: number, smooth = true) => track.scrollTo({ left: Math.max(0, Math.min(count() - 1, i)) * track.clientWidth, behavior: smooth && !reduced ? 'smooth' : 'auto' });

  function mark() {
    const i = index();
    dots.querySelectorAll('button').forEach((d, k) => d.setAttribute('aria-current', String(k === i)));
    thumbsBox.querySelectorAll('button').forEach((d, k) => d.setAttribute('aria-current', String(k === i)));
    prev.disabled = i <= 0; next.disabled = i >= count() - 1;
  }

  function build() {
    const n = count();
    dots.innerHTML = ''; thumbsBox.innerHTML = '';
    root.dataset.multi = n > 1 ? '1' : '0';
    for (let k = 0; k < n; k++) {
      const d = document.createElement('button'); d.type = 'button'; d.setAttribute('aria-label', `Photo ${k + 1}`); d.addEventListener('click', () => { stop(); go(k); }); dots.appendChild(d);
      if (urls[k]) { const t = document.createElement('button'); t.type = 'button'; t.setAttribute('aria-label', `Photo ${k + 1}`); const im = new Image(); im.src = urls[k]!; im.alt = ''; im.width = 64; im.height = 64; im.loading = 'lazy'; t.appendChild(im); t.addEventListener('click', () => { stop(); go(k); }); thumbsBox.appendChild(t); }
    }
    mark();
  }

  /** Swap the placeholder art for real photos (first one loads eagerly). */
  function setImages(list: string[]) {
    if (!list.length) return;
    urls = list;
    track.textContent = '';
    list.forEach((u, k) => {
      const f = document.createElement('figure'); f.className = 'gal-slide';
      const im = new Image(); im.src = u; im.alt = ''; im.decoding = 'async'; im.loading = k === 0 ? 'eager' : 'lazy'; if (k === 0) im.fetchPriority = 'high';
      f.appendChild(im); track.appendChild(f);
    });
    track.scrollLeft = 0; build();
  }

  const stop = () => { touched = true; clearInterval(timer); };
  function start() {
    if (reduced || touched || count() < 2) return;
    clearInterval(timer);
    timer = window.setInterval(() => { if (document.hidden || !onScreen || touched) return; const i = index(); go(i >= count() - 1 ? 0 : i + 1); }, 4500);
  }

  let raf = 0;
  track.addEventListener('scroll', () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(mark); }, { passive: true });
  prev.addEventListener('click', () => { stop(); go(index() - 1); });
  next.addEventListener('click', () => { stop(); go(index() + 1); });
  track.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') { stop(); go(index() - 1); e.preventDefault(); } if (e.key === 'ArrowRight') { stop(); go(index() + 1); e.preventDefault(); } });
  ['pointerdown', 'touchstart', 'wheel'].forEach((ev) => track.addEventListener(ev, stop, { passive: true }));
  new IntersectionObserver(([e]) => { onScreen = !!e?.isIntersecting; }).observe(root);
  addEventListener('resize', () => go(index(), false));

  build(); start();
  return { setImages, restart: start };
}
