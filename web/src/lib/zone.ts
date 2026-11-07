/** Delivery zone (In Sylhet / Outer Sylhet), remembered in the browser. Default: In Sylhet. Display only; the server re-checks. */
import type { Zone } from './rules';
const KEY = 'dw_zone';

export function getZone(): Zone {
  try { return localStorage.getItem(KEY) === 'OUTER_CITY' ? 'OUTER_CITY' : 'IN_CITY'; } catch { return 'IN_CITY'; }
}
export function setZone(z: Zone): void {
  try { localStorage.setItem(KEY, z); } catch { /* storage blocked: lasts until reload */ }
  window.dispatchEvent(new Event('dw:zone'));
}
export const onZone = (f: () => void) => { window.addEventListener('dw:zone', f); window.addEventListener('storage', f); };
