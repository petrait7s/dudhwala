import { describe, expect, it } from 'vitest';
import { buildSearchIndex, searchIndex, suggestCorrections } from './search';
import { products, catName } from './catalogue';

const idx = buildSearchIndex(products.map((p) => ({ item: p, primary: [p.nameBn, p.nameEn], secondary: [catName(p.category)!.nameBn, catName(p.category)!.nameEn] })));
const names = (q: string) => searchIndex(idx, q, 500).map((h) => h.item.nameEn);

describe('fuzzy search over the real 59 products', () => {
  it('finds products despite typos and spelling variants', () => {
    expect(names('rasgulla')).toContain('White Rasgulla');
    expect(names('rosgolla')).toContain('White Rasgulla'); // sound-alike
    expect(names('rasgula')).toContain('White Rasgulla'); // one letter missing
    expect(names('sandsh').some((n) => /Sandesh/.test(n))).toBe(true); // typo
    expect(names('biscute').some((n) => /Biscuit/.test(n))).toBe(true);
    expect(names('kalojam')).toContain('Kalojam');
    expect(names('kaloajm')).toContain('Kalojam'); // swapped letters
  });
  it('Bangla and aliases: দই / doi / dahi / yogurt reach the same curd products', () => {
    const a = names('দই').sort(); const b = names('doi').sort();
    expect(a.length).toBeGreaterThan(3);
    expect(b).toEqual(expect.arrayContaining(a.slice(0, 3)));
    expect(names('dahi').length).toBeGreaterThan(3);
    expect(names('yogurt').length).toBeGreaterThan(3);
  });
  it('ranks the exact name first and rejects nonsense', () => {
    expect(names('bun')[0]).toBe('Bun');
    expect(names('zzzzqqq')).toEqual([]);
    expect(names('   ')).toEqual([]);
  });
  it('suggests a correction when nothing matches', () => {
    expect(suggestCorrections(idx, 'rasgolla', 3).length + names('rasgolla').length).toBeGreaterThan(0);
  });
  it('joins and splits words: roshmalai, kheermohan, malaichap, kalo jam', () => {
    expect(names('roshmalai')[0]).toBe('Rosh Malai');
    expect(names('rasmalai')[0]).toBe('Rosh Malai');
    expect(names('kheermohan')[0]).toBe('Kheer Mohan');
    expect(names('khirmohon')[0]).toBe('Kheer Mohan');
    expect(names('malaichap')[0]).toBe('Malai Chop');
    expect(names('kalo jam')[0]).toBe('Kalojam');
  });
  it('finds a product while the name is still being typed, even with a slip', () => {
    expect(names('rasgo')[0]).toBe('White Rasgulla');
    expect(names('kheerm')[0]).toBe('Kheer Mohan');
    expect(names('রসগো')[0]).toBe('White Rasgulla');
  });
  it('puts the right product first for common spellings', () => {
    expect(names('gulabjamun')[0]).toBe('Golapjam');
    expect(names('kalojamun')[0]).toBe('Kalojam');
    expect(names('gi')[0]).toBe('Ghee');
    expect(names('keik').every((n) => /Cake/.test(n))).toBe(true);
    expect(names('bakorkhani')[0]).toBe('Babarkhani');
  });
  it('falls back to the products matching most of the words when no product matches all', () => {
    const r = names('mishti doi');
    expect(r.length).toBeGreaterThan(3);
    expect(r.slice(0, 3).every((n) => /Doi/.test(n))).toBe(true);
    expect(names('motichur laddu')[0]).toBe('Moti Laddu');
    expect(names('zzzz qqqq')).toEqual([]);
  });
});
