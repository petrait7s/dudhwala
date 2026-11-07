import raw from '../data/catalogue.json';

export interface Cat { slug: string; nameBn: string; nameEn: string }
export interface Prod { id: string; category: string; nameBn: string; nameEn: string; outerCity: string | null }
export const categories = raw.categories as Cat[];
export const products: Prod[] = (raw.products as any[]).map((p) => ({ id: p.id, category: p.category, nameBn: p.nameBn, nameEn: p.nameEn, outerCity: p.outerCity ?? null }));
export const catName = (slug: string) => categories.find((c) => c.slug === slug);
