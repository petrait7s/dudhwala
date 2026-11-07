# Deploying Dudhwala (Vercel storefront + cPanel app)

Two pieces, one database:

| Piece | Folder | Where | Notes |
|---|---|---|---|
| Storefront (static) | `web/` | Vercel | `www.yourdomain.com` |
| Admin + order API | `app/` | cPanel host | `admin.yourdomain.com`, MySQL/MariaDB from the same plan |

The storefront only calls `https://admin.yourdomain.com/api`. Prices, stock and orders live on the host.

## 0. Before you start (owner provides)

bKash and Nagad numbers, delivery fee per zone, approved showroom limit, outlet addresses/phones/hours, prices, photos, WhatsApp number, (showroom orders are prepaid by default, like the old system; untick it in Settings if the owner decides otherwise). Anything missing shows up as a WARN in `php artisan dudhwala:preflight`.

Host needs: PHP 8.2+ with `pdo_mysql, mbstring, openssl, gd (WebP), sodium, fileinfo, ctype, xml`; MySQL 8 / MariaDB 10.6+; cron; a subdomain whose document root you can point at `app/public`; SSL (AutoSSL / Let's Encrypt in cPanel). `mysqldump` is needed for backups. SSH or cPanel Terminal makes the first-run commands easy; without it use the one-off cron trick below.

## 1. Build the upload (on your computer)

```bash
scripts/package-app.sh
```

Writes two files next to each other:

- `dudhwala-app.zip` (about 19 MB): the whole app, vendor included, no dev packages, no secrets.
- `dudhwala-public_html.zip` (a few KB): only the web folder, for hosts where you cannot point the (sub)domain at `app/public`. Its `index.php` finds the app at `<document root>/../dudhwala/app` (change with `APP_REL=/path scripts/package-app.sh`).

## 2. cPanel: app

1. **Subdomain** `admin.yourdomain.com` (cPanel → Domains).
2. **Upload**
   - *Best (you can set the document root):* unzip `dudhwala-app.zip` into `/home/USER/dudhwala/` and set the subdomain's document root to `/home/USER/dudhwala/app/public`.
   - *Document root is fixed (for example `/home/USER/admin.yourdomain.com`):* unzip `dudhwala-app.zip` into `/home/USER/dudhwala/` and unzip `dudhwala-public_html.zip` into the subdomain's document root. Then run the install below with `--public-path=<that document root>`.
   Either way only the web folder is reachable from the internet.
3. **PHP**: MultiPHP Manager: PHP 8.2 or newer for the subdomain, with `pdo_mysql, mbstring, openssl, gd (WebP), sodium, fileinfo, ctype, xml` (Select PHP Version → Extensions).
4. **Database**: MySQL Databases: create a database and a user, grant all privileges.
5. **Config**: copy `.env.production.example` to `.env` in `/home/USER/dudhwala/app/` and fill `APP_URL`, `DB_*` and `CORS_ALLOWED_ORIGINS=https://www.yourdomain.com`. Keep `APP_DEBUG=false`. Use `HASH_DRIVER=argon2id` only on a fresh install (or `bcrypt` if the PHP has no Argon2id).
6. **Install (one command)**, in cPanel Terminal or SSH, inside `/home/USER/dudhwala/app/`:

```bash
php artisan dudhwala:install --admin-email=owner@yourdomain.com --admin-name="Owner"
# document root is not app/public:   add  --public-path=/home/USER/admin.yourdomain.com
```

It generates the app key, migrates the database, links photo storage (or, if the host forbids symlinks, keeps photos inside the web folder and sets `PUBLIC_DISK_IN_PUBLIC=true`), loads the four Sylhet branches from `database/data/owner-branches.json`, imports the 59 products (hidden, unpriced), creates the first admin (a temporary password is printed once unless you pass `--admin-password`), caches config/routes/views and finishes with the preflight report. It is safe to run again after an update.

No terminal? Create a temporary cron job (every minute) with the same command, wait for the minute to pass, read the result in the cron email or add `> /home/USER/install.log 2>&1`, then delete the cron job.

7. **Cron** (one line, every minute): `* * * * * cd /home/USER/dudhwala/app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1` runs the daily 03:00 (Dhaka) database backup.
8. Sign in at `https://admin.yourdomain.com/admin`, enrol the authenticator app (admins must), **save the recovery codes**, then in the admin:
   **Outlets** (real address, phone, hours for each branch) → **Settings** (hours, showroom limit, fees, bKash/Nagad numbers, courier link formats) → **Products** (prices, stock per branch, photos, Outer Sylhet status) → publish.
9. **Staff**: Admin → *Staff* creates Managers (view only) or more Admins, suspends people, resets two-factor or issues a temporary password. Everyone changes their own password under their name in the top bar.

## 3. Vercel: storefront

See `web/README.md`. Short version: import the repo, **Root Directory `web`**, environment `PUBLIC_API_URL=https://admin.yourdomain.com/api` and `PUBLIC_SITE_URL=https://www.yourdomain.com`, deploy, add the domain. Redeploy after changing `PUBLIC_*`.

## 4. DNS

`www` → CNAME to Vercel (`cname.vercel-dns.com`); apex → Vercel A record or redirect to `www`; `admin` → A record to the cPanel host IP. Wait for SSL on both.

## 5. Go-live checklist

- [ ] `php artisan dudhwala:preflight` shows no FAIL; WARNs are understood
- [ ] Place a real test order from the live site (COD), see it in the admin, move it through every status, mark cash collected
- [ ] Place a bKash test order, verify the TrxID in the admin, try the same TrxID again (must be refused)
- [ ] Cancel a paid order and see the refund flag
- [ ] Track the order on the site with order number + phone
- [ ] Run a backup (`php artisan dudhwala:backup`) and **restore it** into an empty database (see `app/README.md`)
- [ ] Phone check on a real device: smooth scrolling, cart, checkout, install-to-home-screen
- [ ] Remove test orders (or start from a restored pre-launch backup); delete the `dudhwala-app.zip` from the server

## 6. Updating

Build a new zip, upload over the old `app/` (keep `.env` and `storage/`), then:

```bash
php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Roll back by restoring the previous zip and, if a migration ran, the pre-update backup (take one first: `php artisan dudhwala:backup`).

## 7. Operations

- **Backups**: daily to `storage/app/backups` (14 days). Copy them off the host (cPanel Backup, or download). A backup on the same disk is not a backup.
- **Locked out of two-factor**: `php artisan dudhwala:reset-mfa email`.
- **Stop new orders**: Settings → maintenance mode.
- **Logs**: `storage/logs` (daily files).
- **Alerts**: new orders are only logged for now; the `OrderNotifier` interface is ready for a WhatsApp notifier once the contact is supplied.

## 8. Courier handoff (no payment gateway, no courier API)

Orders are confirmed by staff and handed to a courier or rider by hand. In the admin, open a **home delivery** order, move it to *Preparing*, then in the **Courier / rider** box choose Steadfast, Pathao, Own rider or Other, enter the tracking/consignment code (required for Steadfast and Pathao) and an optional note (rider name and phone), and save. The customer sees the courier and tracking code under *Track order*. Showroom and pickup orders are collected at the chosen branch and have no courier. Steadfast/Pathao API booking can be added later if wanted (needs their API keys).
