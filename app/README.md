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
