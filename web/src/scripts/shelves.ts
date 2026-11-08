/** Phone-only helpers: swipeable rows with edge fades and ‹ › arrows, like a native app's shelves. */

export function withArrows(scroller: HTMLElement): HTMLElement {
  const wrap = document.createElement('div'); wrap.className = 'sh-wrap';
  scroller.replaceWith(wrap); wrap.appendChild(scroller);
  const mk = (dir: -1 | 1) => {
    const b = document.createElement('button'); b.type = 'button'; b.className = 'sh-nav ' + (dir < 0 ? 'prev' : 'next'); b.tabIndex = -1;
    b.setAttribute('aria-label', dir < 0 ? 'Scroll left' : 'Scroll right');
    b.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${dir < 0 ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7'}"/></svg>`;
    b.addEventListener('click', () => scroller.scrollBy({ left: dir * scroller.clientWidth * 0.75, behavior: 'smooth' }));
    wrap.appendChild(b);
  };
  mk(-1); mk(1);
  const upd = () => {
    const max = scroller.scrollWidth - scroller.clientWidth;
    wrap.classList.toggle('can-l', scroller.scrollLeft > 6);
    wrap.classList.toggle('can-r', scroller.scrollLeft < max - 6);
  };
  scroller.addEventListener('scroll', upd, { passive: true });
  new ResizeObserver(upd).observe(scroller);
  requestAnimationFrame(upd);
  return wrap;
}
