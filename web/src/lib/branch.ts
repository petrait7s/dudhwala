/** The branch the customer picked for pickup / showroom orders (remembered in the browser). */
const KEY = 'dw_branch';
export const getBranch = (): string => { try { return localStorage.getItem(KEY) || ''; } catch { return ''; } };
export function setBranch(id: string): void {
  try { localStorage.setItem(KEY, id); } catch { /* blocked */ }
  window.dispatchEvent(new Event('dw:branch'));
}
