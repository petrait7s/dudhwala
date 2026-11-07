#!/usr/bin/env python3
"""Turn originals in web/hero-originals/*.jpg|png into public/hero/<name>-800.webp and -1400.webp, then list the entries
to paste into src/data/hero.json. Needs Pillow:  pip install pillow
Usage: python3 scripts/make-hero-variants.py
Set the focal point (where faces / products sit) per photo in hero.json: fx, fy are percentages (0-100)."""
import json, pathlib
from PIL import Image
src, out = pathlib.Path('hero-originals'), pathlib.Path('public/hero')
out.mkdir(parents=True, exist_ok=True)
entries = []
for f in sorted(list(src.glob('*.jpg')) + list(src.glob('*.jpeg')) + list(src.glob('*.png'))):
    im = Image.open(f).convert('RGB')
    for w in (800, 1400):
        t = im.copy(); t.thumbnail((w, 10_000)); t.save(out / f'{f.stem}-{w}.webp', quality=80, method=6)
    entries.append({'src': f'/hero/{f.stem}', 'fx': 50, 'fy': 50, 'altBn': '', 'altEn': ''})
print(json.dumps(entries, ensure_ascii=False, indent=2))
