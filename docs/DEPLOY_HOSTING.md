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
scripts/package-app.sh            # writes dudhwala-app.zip (about 19 MB: vendor included, no dev packages, no secrets)
```

## 2. cPanel: app

1. **Subdomain** `admin.yourdomain.com`. Set its **document root to `app/public`** (for example `/home/USER/dudhwala/app/public`). Upload and unzip `dudhwala-app.zip` into `/home/USER/dudhwala/` so only `public/` is web-reachable. If the host forces the docroot to `public_html/admin`, keep `app/` outside `public_html` and make `public_html/admin` a symlink to `app/public`.
2. **PHP version**: Select PHP 8.2 or newer for the subdomain (MultiPHP Manager) and enable the extensions above.
3. **Database**: MySQL Databases: create a database and user, grant all privileges.
4. **Config**: copy `.env.production.example` to `.env` and fill `APP_URL`, `DB_*`, `CORS_ALLOWED_ORIGINS=https://www.yourdomain.com`. Keep `APP_DEBUG=false`. Set `HASH_DRIVER=argon2id` only on a fresh install (switching later invalidates existing passwords); use `bcrypt` if the PHP has no Argon2id.
5. **First run** (cPanel Terminal or SSH, inside `app/`):

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link                      # product photos (if exec/symlink is blocked: ask the host, or symlink public/storage -> ../storage/app/public in File Manager)
php artisan dudhwala:setup --file=owner-setup.json    # outlets, hours, dispatch + showroom outlet (see apps/api/data/setup.example.json; real values only, placeholders are refused)
php artisan dudhwala:import-catalogue         # the 59 products, hidden and unpriced
php artisan dudhwala:create-staff owner@example.com "Owner" --role=ADMIN
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan dudhwala:preflight
```

No terminal? Create a temporary cron job (every minute) with the command, wait one minute, then delete it. Do this for each command above in order.

6. **Cron** (one line, every minute): `* * * * * cd /home/USER/dudhwala/app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1`. It runs the daily 03:00 (Dhaka) database backup.
7. Sign in at `https://admin.yourdomain.com/admin`, enrol the authenticator app, **save the recovery codes**, then in the admin:
   Settings (hours, showroom limit, fees, outlets, bKash/Nagad numbers) → Products (prices, stock per outlet, photos, Outer Sylhet status) → publish.
8. Create a **Manager** for view-only staff: `php artisan dudhwala:create-staff manager@example.com "Name" --role=MANAGER`.

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
