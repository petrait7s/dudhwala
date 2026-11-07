<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Go-live checklist. Exit code 1 if any hard check fails; warnings (owner data still missing) do not fail. */
class Preflight extends Command
{
    protected $signature = 'dudhwala:preflight';
    protected $description = 'Check the server and the shop data before going live';

    private int $fails = 0;

    private function row(string $level, string $label, string $detail = ''): void
    {
        $tag = ['ok' => '<info>OK  </info>', 'warn' => '<comment>WARN</comment>', 'fail' => '<error>FAIL</error>'][$level];
        $this->line("$tag $label".($detail ? "  — $detail" : ''));
        if ($level === 'fail') $this->fails++;
    }

    public function handle(SettingsService $s): int
    {
        $this->info('Server');
        PHP_VERSION_ID >= 80200 ? $this->row('ok', 'PHP '.PHP_VERSION) : $this->row('fail', 'PHP 8.2+ required', PHP_VERSION);
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'gd', 'sodium', 'fileinfo', 'ctype', 'xml'] as $ext) extension_loaded($ext) ? null : $this->row('fail', "PHP extension $ext missing");
        extension_loaded('gd') && function_exists('imagewebp') ? $this->row('ok', 'GD with WebP (photo upload)') : $this->row('fail', 'GD lacks WebP support (photo upload will fail)');
        config('app.key') ? $this->row('ok', 'APP_KEY set') : $this->row('fail', 'APP_KEY missing: php artisan key:generate --force');
        config('app.debug') ? $this->row('fail', 'APP_DEBUG is true', 'set APP_DEBUG=false in production') : $this->row('ok', 'APP_DEBUG is false');
        app()->environment('production') ? $this->row('ok', 'APP_ENV=production') : $this->row('warn', 'APP_ENV is '.app()->environment());
        str_starts_with((string) config('app.url'), 'https://') ? $this->row('ok', 'APP_URL is https') : $this->row('warn', 'APP_URL is not https', (string) config('app.url'));
        config('session.secure') ? $this->row('ok', 'Session cookie is secure-only') : $this->row('warn', 'SESSION_SECURE_COOKIE is off (needs https)');
        config('hashing.driver') === 'argon2id' ? $this->row('ok', 'Password hashing: argon2id') : $this->row('warn', 'Password hashing: '.config('hashing.driver'), defined('PASSWORD_ARGON2ID') ? 'this PHP supports argon2id: set HASH_DRIVER=argon2id' : 'bcrypt is fine here');
        array_filter((array) config('cors.allowed_origins')) ? $this->row('ok', 'CORS origins: '.implode(', ', config('cors.allowed_origins'))) : $this->row('fail', 'CORS_ALLOWED_ORIGINS is empty: the storefront cannot call the API');
        is_writable(storage_path()) && is_writable(storage_path('logs')) ? $this->row('ok', 'storage/ writable') : $this->row('fail', 'storage/ is not writable');
        is_link(public_path('storage')) || is_dir(public_path('storage')) ? $this->row('ok', 'public/storage link exists (product photos)') : $this->row('warn', 'public/storage missing: run php artisan storage:link');
        $bin = env('DB_DUMP_BINARY') ?: collect(['mysqldump', 'mariadb-dump'])->first(fn ($b) => (new Process(['which', $b]))->run() === 0);
        $bin ? $this->row('ok', 'Backup tool found', (string) $bin) : $this->row('warn', 'mysqldump not found: backups will fail', 'set DB_DUMP_BINARY');

        $this->info('Database');
        try {
            DB::connection()->getPdo();
            $this->row('ok', 'Database connection ('.DB::getDriverName().')');
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys()->diff(DB::table('migrations')->pluck('migration'));
            $pending->isEmpty() ? $this->row('ok', 'Migrations up to date') : $this->row('fail', 'Pending migrations', $pending->implode(', '));
        } catch (\Throwable $e) {
            $this->row('fail', 'Database not reachable', $e->getMessage());
            return self::FAILURE;
        }
        in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? null : $this->row('warn', 'Database is not MySQL/MariaDB');

        $this->info('Shop data (warnings mean the owner still has to provide something)');
        User::where('role', 'ADMIN')->where('status', 'ACTIVE')->where('mfa_enabled', true)->exists() ? $this->row('ok', 'An admin with two-factor exists') : $this->row('warn', 'No admin with two-factor yet', 'dudhwala:create-staff, then sign in and enrol');
        $active = Outlet::where('status', 'ACTIVE')->count();
        $active ? $this->row('ok', "$active active outlet(s)") : $this->row('warn', 'No active outlet: ordering is impossible');
        foreach (['fulfilment.dispatch_outlet_id' => 'Dispatch outlet', 'bulk.collection_outlet_id' => 'Showroom collection outlet'] as $k => $l) {
            $id = $s->get($k);
            $id && Outlet::where('id', $id)->where('status', 'ACTIVE')->exists() ? $this->row('ok', "$l set") : $this->row('warn', "$l not set");
        }
        Outlet::where('address_line', 'like', 'REPLACE_WITH%')->orWhere('phone', 'like', 'REPLACE_WITH%')->exists() ? $this->row('fail', 'An outlet still has REPLACE_WITH_* placeholder data') : null;
        $s->get('bulk.rule')['approved'] ? $this->row('ok', 'Showroom limit approved by the owner') : $this->row('warn', 'Showroom limit is still the unapproved placeholder (20 items / ৳20,000)');
        $n = $s->get('payment.numbers');
        ($n['bkash'] ?? null) || ($n['nagad'] ?? null) ? $this->row('ok', 'bKash/Nagad number(s) set') : $this->row('warn', 'No bKash/Nagad number: only cash on delivery is possible');
        $s->get('site.maintenance') ? $this->row('warn', 'Maintenance mode is ON (new orders blocked)') : null;
        $total = Product::count(); $live = Product::where('active', true)->count();
        $priced = ProductVariant::whereNotNull('price_poisha')->whereHas('product', fn ($q) => $q->where('active', true))->count();
        $total ? $this->row($live ? 'ok' : 'warn', "$live of $total products published, $priced priced variant(s) on sale") : $this->row('warn', 'No products: run dudhwala:import-catalogue');
        $stocked = DB::table('inventory')->where('quantity_on_hand', '>', 0)->exists();
        $stocked ? $this->row('ok', 'Stock entered') : $this->row('warn', 'No stock entered: tracked items show as out of stock');

        $this->newLine();
        $this->fails ? $this->error("{$this->fails} blocking problem(s).") : $this->info('No blocking problems.');
        return $this->fails ? self::FAILURE : self::SUCCESS;
    }
}
