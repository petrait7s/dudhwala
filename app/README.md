# Dudhwala app (Laravel 11 + Livewire + MySQL/MariaDB)

Admin panel (`/admin`) and public order API (`/api`). PHP 8.2+. No queue worker, no Redis: cache is `file`, queue is `sync`.

## Setup

```bash
composer install --no-dev
cp .env.example .env && php artisan key:generate
# set DB_*, APP_URL, CORS_ALLOWED_ORIGINS (the storefront URL, comma separated)
php artisan migrate --force
php artisan dudhwala:setup --file=owner-setup.json        # outlets, hours, dispatch + collection outlet (see apps/api/data/setup.example.json)
php artisan dudhwala:import-catalogue                     # 59 products, hidden and unpriced; add --activate to publish
php artisan dudhwala:create-staff owner@example.com "Owner" --role=ADMIN
php artisan storage:link
```

Admins must enrol an authenticator app on first sign-in (recovery codes are shown once). `php artisan dudhwala:reset-mfa <email>` clears it.

## Test

```bash
vendor/bin/phpunit                                   # SQLite in memory
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_DATABASE=dudhwala_test DB_USERNAME=... DB_PASSWORD=... \
  php artisan migrate:fresh --force && vendor/bin/phpunit   # also runs the parallel-order test
```

## Public API (contract in `web/README.md`)

`GET /api/catalogue`, `GET /api/config`, `POST /api/orders` (6/min per IP, honeypot `website`, idempotency key), `POST /api/orders/track` (order number + phone).

## Rules live in

`app/Services/OrderingRules.php` (hours, zones, pickup, bulk, status flow, fees), `OrderService`, `PaymentService`, `InventoryService`, `CatalogueService`. Staff alert: `App\Contracts\OrderNotifier` (log only now; bind a WhatsApp notifier in `AppServiceProvider` later).

## Cron and backups (cPanel)

Add one cron entry (every minute): `* * * * * cd /home/USER/app && php artisan schedule:run >> /dev/null 2>&1`

The scheduler runs `dudhwala:backup` daily at 21:00 UTC (03:00 Dhaka): gzipped `mysqldump` into `storage/app/backups/` (mode 0600, 14 days kept; `--keep=N` to change). If the host has no `mysqldump` on PATH, set `DB_DUMP_BINARY` in `.env`. Copy backups off the host regularly (cPanel backup, or download the folder).

Restore a dump into an empty database:

```bash
gunzip -c storage/app/backups/dudhwala-YYYYMMDD-HHMMSS.sql.gz | mysql -u USER -p DBNAME
```

`BackupTest` dumps a live database, restores it into a fresh one and compares the data; run it on MySQL/MariaDB before launch.

## Admin areas

ADMIN: orders, products (prices, per-outlet stock, outer-city status, photo), categories, outlets and hours, settings (hours, showroom limit, fees, outlets, payment numbers, prepaid, maintenance), content pages, audit log. MANAGER: orders and products, view only. Every change writes an audit row with before and after.
