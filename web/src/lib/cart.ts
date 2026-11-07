/** Cart lives in the browser. The server re-prices from variant ids; stored prices are for display only. */
export interface CartLine {
  variantId: number;
  slug: string;
  nameBn: string;
  nameEn: string;
  labelBn: string;
  labelEn: string;
  unitPoisha: number;
  maxQty: number;
  category: string;
  outer: string | null;
  quantity: number;
}
const KEY = 'dw_cart_v1';
const safe = <T>(f: () => T, d: T): T => { try { return f(); } catch { return d; } };

export const readCart = (): CartLine[] =>
  safe(() => {
    const v = JSON.parse(localStorage.getItem(KEY) || '[]');
    return Array.isArray(v) ? v.filter((l) => Number.isInteger(l?.variantId) && Number.isInteger(l?.quantity) && l.quantity > 0) : [];
  }, []);

function write(lines: CartLine[]) {
  safe(() => localStorage.setItem(KEY, JSON.stringify(lines)), undefined);
  window.dispatchEvent(new CustomEvent('dw:cart', { detail: lines }));
}

export function addToCart(line: Omit<CartLine, 'quantity'>, qty = 1): void {
  const lines = readCart();
  const f = lines.find((l) => l.variantId === line.variantId);
  if (f) { f.quantity = Math.min(f.quantity + qty, line.maxQty); Object.assign(f, line, { quantity: f.quantity }); }
  else lines.push({ ...line, quantity: Math.min(qty, line.maxQty) });
  write(lines);
}
export function setQty(variantId: number, qty: number): void {
  const lines = readCart().map((l) => (l.variantId === variantId ? { ...l, quantity: Math.min(qty, l.maxQty) } : l)).filter((l) => l.quantity > 0);
  write(lines);
}
export const removeLine = (variantId: number) => write(readCart().filter((l) => l.variantId !== variantId));
export const clearCart = () => write([]);
export const cartCount = (lines = readCart()) => lines.reduce((s, l) => s + l.quantity, 0);
