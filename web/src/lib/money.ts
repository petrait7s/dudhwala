/** Integer poisha helpers (display side). The server is the authority on every amount. */
const BN = '০১২৩৪৫৬৭৮৯';
const bn = (s: string) => s.replace(/\d/g, (d) => BN[Number(d)] ?? d);
const group = (n: number) => {
  const s = String(n);
  return s.length > 3 ? s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + s.slice(-3) : s;
};
export function formatTaka(poisha: number, lang: 'bn' | 'en' = 'bn'): string {
  const neg = poisha < 0;
  const abs = Math.abs(poisha);
  let t = group(Math.trunc(abs / 100));
  if (abs % 100) t += '.' + String(abs % 100).padStart(2, '0');
  return `${neg ? '-' : ''}৳${lang === 'bn' ? bn(t) : t}`;
}
export const toLatinDigits = (s: string) => s.replace(/[০-৯]/g, (d) => String(BN.indexOf(d)));

/** 01XXXXXXXXX or null. Accepts +880 / 880 / Bangla digits. */
export function normalizePhone(raw: string): string | null {
  let d = toLatinDigits(raw).replace(/[\s\-().]/g, '');
  if (d.startsWith('+880')) d = '0' + d.slice(4);
  else if (d.startsWith('880')) d = '0' + d.slice(3);
  else if (/^1[3-9]\d{8}$/.test(d)) d = '0' + d;
  return /^01[3-9]\d{8}$/.test(d) ? d : null;
}
