# Dudhwala web (Astro, static)

Storefront for Dudhwala. Static build, no server. Talks to the Laravel API in `../app` over HTTPS.

## Run

```bash
npm install
npm run dev      # http://localhost:4321
npm test         # display-rule tests (vitest)
npm run build    # -> dist/
```

## Deploy to Vercel

1. Import the repo in Vercel. **Root Directory: `web`**. Framework preset: Astro (`vercel.json` already sets build and headers).
2. Environment variables (Project Settings, Environment Variables):
   - `PUBLIC_API_URL` = the Laravel API base, for example `https://admin.yourdomain.com/api` (no trailing slash). Leave empty to publish a browse-only site: prices and ordering show "to be confirmed / not open yet".
   - `PUBLIC_SITE_URL` = the public site URL (canonical links).
3. In Laravel `.env`, set CORS to the Vercel domain (`CORS_ALLOWED_ORIGINS=https://your-site.vercel.app`).
4. Redeploy after changing any `PUBLIC_*` variable: they are baked in at build time.

## What you edit

| File | What |
|---|---|
| `src/data/site.json` | Shop name, showroom address and phone, **WhatsApp number** (digits with country code, e.g. `8801XXXXXXXXX`; the Contact page shows the button only when set), phone, email |
| `src/data/hero.json` | Hero slideshow photos (empty = calm brand-gradient hero) |
| `src/data/pages.json` | Content pages (policies, FAQ): `[{ "slug", "titleBn", "titleEn", "bodyBn", "bodyEn" }]`; each becomes `/info/<slug>` and a footer link |
| `src/data/catalogue.json` | Product names (59). Prices, variants and photos come live from the API |

### Hero photos

Put originals in `web/hero-originals/`, run `python3 scripts/make-hero-variants.py`, paste the printed entries into `src/data/hero.json`, and set `fx`/`fy` (focal point, percent) so faces and products clear the headline. Make sure people shown have agreed to it.

## API contract the site expects

All JSON. Money is integer poisha. Phones `01XXXXXXXXX`.

| Call | Response |
|---|---|
| `GET /catalogue` | `{ products: [{ slug, image_url, variants: [{ id, label_bn, label_en, price_poisha \| null, max_qty, in_stock }] }] }` (slug = catalogue id, e.g. `ds-01`) |
| `GET /config` | `{ hours:{open_minute,close_minute}, bulk:{min_total_quantity,min_total_poisha,approved}, fees:{IN_CITY,OUTER_CITY}, payment_numbers:{bkash,nagad}, bulk_prepaid, outlets:[{id,code,name_bn,name_en}] }` |
| `POST /orders` | body `{ idempotency_key, method, zone, items:[{variant_id,quantity}], contact:{name,phone,address_line1?,landmark?}, outlet_id?, pickup_at?, payment:{method,trx_id?,sender_number?}, website }` -> `201 { order_number, status, total_poisha, subtotal_poisha, delivery_fee_poisha }`; errors `{ error:{ code, issues:[{code,line?}] } }` (issue codes = `OrderingRules::validateCheckout`) |
| `POST /orders/track` | `{ order_number, phone }` -> `{ order_number, method, status, payment_status, total_poisha, items:[{name_bn,variant_label_bn,quantity,line_total_poisha}] }`, 404 when the pair does not match |

`website` is the honeypot: it must stay empty. The browser prices shown are for display; the server re-prices from `variant_id`.

## Design notes

- Brand tokens are copied unchanged from `packages/ui/styles/dw.css` (`src/styles/tokens.css`). Light and dark themes via `html[data-theme]`.
- Bilingual: every text has `<span class="bn">` and `<span class="en">`; CSS shows one. Bangla default; language and theme are set before paint from `localStorage`.
- Fonts: Montserrat (Latin) and Tiro Bangla (Bengali) from Google Fonts for now. To self-host for offline PWA, download both, put the files in `public/fonts/`, and replace the stylesheet link in `src/layouts/Base.astro` with `@font-face` rules.
- Phone performance rules followed: no `backdrop-filter`, no permanent `will-change`, hover only under `(hover: hover)`, `content-visibility: auto` below the fold, `touch-action: manipulation`, responsive images, reduced-motion respected.
- `src/lib/rules.ts` is a display-only copy of the ordering rules; the server is the authority.
