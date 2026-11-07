You are rebuilding **Dudhwala** (দুধওয়ালা, Dudhwala Food Products, Sylhet) so that the whole system runs on **one cPanel hosting plan**, with a new front-end look and structure copied from a finished sister project, **Tales of Tea**. Dudhwala's colours, logo and business rules stay exactly as they are today.

## 0. Read first (source of truth)

In this repository (`ak7shuvo/dudhwala`):
- `docs/PHASE2_BACKEND.md`, especially section 8 (latest decisions), and `docs/OPEN_DECISIONS.md`.
- `packages/shared/src/ordering.ts` and its tests. This file holds the business rules. Port it exactly.
- `apps/api/prisma/schema.prisma` for the data model, and `docs/USER_ROLES_AND_PERMISSIONS.md`.
- `packages/ui/styles/dw.css` for brand tokens, `assets/brand/` and `apps/web/public/logo.png` for the logo.
- `apps/api/data/setup.example.json`, `apps/api/src/cli/import-catalogue*` and `docs/CATALOGUE_V2_5_1.md` for the 59 products (hidden and unpriced by default).

Reference build: the repository `petrait7s/talesoftea` (try `add_repo`; if you cannot read it, everything you need is in this prompt). Look at `web/` (Astro site), `web/src/styles/global.css` and `CLAUDE.md`.

Do not delete or edit the old `apps/`, `packages/`, `demo/`. Work on a new branch `rebuild-hosting` and add new folders `web/` and `app/`. The old code stays as the behaviour reference until the new one passes the parity checklist in section 9.

## 1. Goal

Same shop, same rules, new stack and new face:
- No separate database service. No Vercel, Render, Supabase, S3, Docker, Redis or queue workers. Everything runs on the one host, using the database that comes with the cPanel plan.
- Front end: **Astro** (static build), built like Tales of Tea.
- Back end: **Laravel + Livewire + MySQL**, one app for the admin panel and the order API.
- Orders are handled manually by staff. No payment gateway. Payment is Cash on Delivery, or bKash/Nagad paid by the customer and checked by staff using the TrxID.

## 2. Architecture

- `web/`: Astro + TypeScript, static build uploaded to the main domain.
- `app/`: Laravel 11, PHP 8.2+, Livewire, MySQL/MariaDB, on a subdomain (admin and `POST /api/orders`).
- Cart lives in the browser (localStorage). The server never trusts browser prices: it recomputes every price, fee and total from product/variant ids.
- Public API is small: catalogue read (or build-time export), config (hours, thresholds, fees, payment numbers), create order, track order by order number + phone. CORS limited to the site domain.
- Images are stored on the host (`storage/app/public`), resized on upload. No object storage.
- Background work (retry alerts, expiry, backups) runs from cron. No Telegram. New-order alerts use the `OrderNotifier` interface (log only for now; a WhatsApp notifier is added once the owner supplies the contact). No SMS unless the owner chooses a provider later.
- Money is stored as integers (poisha), never floats, as in `packages/shared/src/money.ts`. Phone numbers are stored as `01XXXXXXXXX` (normalise `+880` and `880`).
- Rate limit and honeypot on the public order endpoint. CSRF on admin. Secrets only in `.env`.
- Port the shared rules into one PHP service class with the same test cases (see section 9). A small TypeScript copy may exist in `web/` for display only; the server is the authority.

## 3. Business rules that must not change

Take exact values from `ordering.ts`; they are listed here so nothing is missed.
- **Ordering hours** 10:00 to 22:00, timezone Asia/Dhaka, configurable in Settings. Boundary tests at 09:59, 10:00, 21:59, 22:00.
- **Three ways to order:** home delivery, express pickup, and "Order From Showroom" (internally `BULK_ORDER`, collected at Modina Market Branch).
- **Zones for home delivery:** In Sylhet (`IN_CITY`) and Outer Sylhet (`OUTER_CITY`), Bangla labels ইন সিলেট / আউটার সিলেট. Estimates: in city within 24 hours, outer 24 to 48 hours. Never say "instant". Each zone has its own delivery fee in Settings (default 0). Only home delivery is charged.
- **Outer Sylhet eligibility:** whole Bakery category is eligible; any other item only if the owner marked it `approved`. `pending_verification`, `pending_confirmation` and `disabled` are never eligible.
- **Express pickup:** at least 60 minutes ahead, 30-minute slots, inside outlet hours.
- **Showroom/bulk:** when the cart reaches the quantity threshold (placeholder 20 items) or the amount threshold (placeholder ৳20,000), only the showroom method is available. Thresholds are Settings and are flagged unapproved until the owner saves them.
- **Order status flows** (forward one step only, or cancel while live):
  - Home delivery: PLACED, PREPARING, OUT_FOR_DELIVERY, DELIVERED
  - Express pickup: PICKUP_REQUESTED, PREPARING, READY_FOR_PICKUP, PICKED_UP
  - Showroom: PLACED, PREPARING, READY_FOR_COLLECTION, COLLECTED
- **Catalogue:** products are hidden and unpriced until the admin publishes them. An unpriced product cannot be added to a cart. Search works in Bangla and English. Categories and content pages (policies, FAQ, banners) are editable.
- **Stock:** per outlet, atomic reserve, commit and release, so parallel orders cannot oversell. Append-only stock ledger with actor, time and reason. A variant with orders or stock history cannot be removed.
- **Orders:** idempotency key (a double tap makes one order), snapshot of item names, prices and address on the order, status events append-only, ownership checks on tracking.
- **Staff cancel orders; customers cannot.** A cancelled order that was already paid writes a `payment.refund_required` audit entry for a manual refund.
- **Audit log** for sensitive actions, with before and after values.

## 4. Payments (the one change from today)

Today Dudhwala is COD only. New rule, same as Tales of Tea:
- Methods: `COD`, `BKASH`, `NAGAD`. No gateway.
- For bKash/Nagad the customer sees the shop's number and the total, sends the money, then enters the **sender number** and **TrxID**.
- A `payments` table: order, method, amount, `trx_id` (unique, nullable for COD), sender number, status `UNPAID | PENDING_VERIFICATION | PAID | VOID`, who verified and when, void reason.
- One TrxID can verify one payment only. Payments are never deleted, only voided with a reason.
- COD becomes PAID when staff record that the cash was collected. Staff verify bKash/Nagad against the merchant app. The order total is computed from the items; "due" is total minus verified payments and is never stored.
- Confirm with me whether showroom orders must be prepaid.

## 5. Customers and login

Dudhwala today uses phone OTP login, which needs a paid SMS provider. Recommended instead:
- Guest checkout with name, phone, address.
- "Track my order" by order number plus phone.
- Same phone means same customer record.
- Warn admin when a phone has earlier cancelled orders.
- Keep OTP as an optional module that stays off until the owner chooses an SMS provider.

I will confirm this choice before you code the customer side.

## 6. Admin panel (Laravel + Livewire)

Roles as today: **ADMIN** (everything, TOTP two-factor with recovery codes) and **MANAGER** (view only). Login lockout, rate limits, Argon2id (or Laravel default bcrypt if Argon is missing on the host).
Pages: orders list and detail with next-status buttons and payment actions, products and variants (prices, per-outlet stock, outer-city status, photo upload), categories, outlets and hours, Settings (ordering hours, bulk thresholds, zone fees, dispatch and collection outlets, bKash/Nagad numbers), content pages, audit log.
Artisan commands replacing the old CLI: `create-staff`, `setup` (from the owner JSON), `import-catalogue` (idempotent, `--activate`), `reset-mfa`.
Language files for Bangla and English. No hard-coded text.

## 7. Front-end design (copy the Tales of Tea approach)

**Keep Dudhwala's colours and logo.** Use the tokens in `packages/ui/styles/dw.css` (brand indigo `#1d2672`, `#2a2fae`, `#2f35b6`, sky and cyan accents, light and dark themes). Do not invent new brand colours. Keep the existing light/dark theme switch.

**Fonts:** Tiro Bangla for Bangla, Montserrat for English. Latin glyphs fall through to Montserrat, Bengali to Tiro Bangla. Tiro Bangla has one weight, so bold Bangla is synthesised. Plan to self-host the font files later for offline PWA.

**Bilingual:** put both languages in the HTML (`<span class="bn">` and `<span class="en">`), show one with CSS (`html[lang=bn] .en {display:none}`), set the language before paint from localStorage. Bangla is the default.

**Structure and feel:**
- Mobile feels like an app: floating bottom tab bar (Home, Shop, Story/Info, Cart), sticky buy bar on product pages, installable PWA (manifest, icons, service worker that is network-first). Desktop is a normal website with a top nav.
- Pages: Home, Shop (category filter and sort), Category, Product, Cart + Checkout, Order success, Track order, Order From Showroom, About, Contact, content pages.
- Home: full-bleed **hero photo slideshow** with floating text; a small bento row below (key facts); product cards; short "how to order" steps; a final call-to-action band; footer.
- Hero slideshow rules (learned the hard way): photos in `web/public/hero/`, hold 4 s, fade 1.9 s. The new slide fades in **on top of** the old one (old stays opaque below, otherwise there is a dark dip). The outgoing slide keeps its zoom animation until covered (otherwise it snaps). Only switch after `img.decode()` resolves. Pause when the tab is hidden or the hero is off-screen. Provide `-800` and `-1400` webp copies and use `srcset`. Per-photo focal point so faces and products clear the headline. Mouse parallax on desktop only, on `requestAnimationFrame`.
- Product page: big product photo (or neutral placeholder until photos exist), size/weight chips that scale a pack illustration if the products have variants, price that updates live, quantity, Add to cart and Buy now.
- Cart: small pouch/box flies from the button to the cart icon (Web Animations API, skipped under reduced motion).
- Scroll reveal on cards (IntersectionObserver), removed after it plays so hover works.
- Hand-made touches: wavy underline under key words, one calm texture on the hero only.
- Do not show fake reviews, ratings, counts or invented claims. Use real data or leave the block out.

**Performance rules for phones (these fixed real lag):**
- No `backdrop-filter` anywhere. No fixed full-screen overlay layers. No permanent `will-change`.
- Hover effects only inside `@media (hover: hover)`.
- `content-visibility: auto` on below-the-fold sections.
- `touch-action: manipulation` on buttons and links.
- Responsive images (`srcset`, `sizes`), first image eager and high priority.
- Respect `prefers-reduced-motion` (no animations, no auto-advance).
- Check a throttled mobile profile (Pixel 5, 4x CPU) before saying it is smooth, and report the numbers.

## 8. Working method

1. Before each phase, show a short plan: files, tables, decisions, alternatives. **Wait for my approval.**
2. Challenge weak ideas and recommend the simpler option. This is a rewrite of a large system (about 150 API tests); do it in phases, not in one go.
3. After coding, run tests, summarise, suggest a commit message. Do not start the next phase until tests pass.
4. If the host lacks something (PHP version, MySQL, SSH, cron), ask before assuming.
5. Never push to `main` unless I say so.

Suggested phases:
1. Foundation: `app/` Laravel skeleton, migrations for the data model (MySQL), roles, TOTP, audit, artisan commands, config and settings.
2. Rules engine: port `ordering.ts` to PHP with the full test suite (hours boundaries, zones, eligibility, pickup, bulk by quantity and by amount, fees, status flow, money, phone).
3. Catalogue and stock: products, variants, categories, per-outlet stock with atomic reserve/commit/release and a parallel-order test, photo upload, catalogue import.
4. Orders and payments: order API (server pricing, idempotency, honeypot, rate limit), payments table with TrxID rules, admin order screens, `OrderNotifier` alert (WhatsApp later).
5. `web/` Astro site: layout, design system from `dw.css`, home with hero slideshow, shop, product, cart and checkout against the API, track order, PWA.
6. Admin polish, content pages, search, i18n files, backups and cron.
7. Launch: real data import, SSL, domain/DNS, deploy steps, README, restore test.

## 9. Parity checklist and acceptance

The rebuild is done when:
- Every rule in section 3 has a passing automated test in the new stack (hours boundaries, zones and eligibility, pickup lead time, bulk by quantity and by amount, fees only on home delivery, forward-only status flow, no overselling under parallel orders, idempotent order, unpriced item blocked, manager read-only, audit entries).
- A real order from the site reaches the admin panel; staff move it through its flow; a bKash/Nagad payment is verified by TrxID and a second use of the same TrxID is refused; COD is marked PAID by staff.
- The site passes the mobile performance rules in section 7, with measured numbers.
- Dudhwala's colours and logo are unchanged and light/dark themes work.
- A database backup restores cleanly on the host.

## 10. Ask me these before you code

1. SMS: guest checkout now and OTP later (recommended), or OTP from day one? If OTP, which provider?
2. Are showroom (bulk) orders prepaid, or paid at the showroom?
3. bKash and Nagad merchant numbers; real delivery fees per zone; approved bulk thresholds; outlet list and hours.
4. Hosting facts: PHP version, MySQL or MariaDB version, SSH access, cron, subdomain for admin.
5. Do the 59 products get prices and photos now, or import them hidden first?
6. Where do hero and product photos come from, and may people in them be shown?
