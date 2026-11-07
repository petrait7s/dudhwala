<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * One command for the whole first-run on a cPanel host (also safe to run again after an update):
 * key, migrations, photo storage, branches, catalogue, first admin, caches, preflight.
 *
 *   php artisan dudhwala:install --admin-email=owner@example.com --admin-name="Owner"
 */
class Install extends Command
{
    protected $signature = 'dudhwala:install
        {--admin-email= : create this ADMIN if no admin exists yet}
        {--admin-name=Owner : name for that admin}
        {--admin-password= : omit to generate one (shown once)}
        {--branches= : setup JSON (default database/data/owner-branches.json; use "skip" to leave outlets alone)}
        {--skip-catalogue : do not import the 59 products}
        {--public-path= : web folder when it is not app/public (public_html layout), e.g. /home/USER/public_html}
        {--skip-key : do not touch APP_KEY}
        {--skip-cache : do not cache config/routes/views}';
    protected $description = 'First-run setup for the host: key, migrate, storage, branches, catalogue, first admin, caches, preflight';

    public function handle(AuditService $audit): int
    {
        if ($pp = $this->option('public-path')) {
            if (! is_dir($pp)) { $this->error("Web folder not found: $pp"); return self::FAILURE; }
            file_put_contents(base_path('.public_path'), $pp);
            app()->usePublicPath($pp);
            $this->line("web folder: $pp");
        }

        $this->info('1/7 Key');
        if ($this->option('skip-key')) $this->line('skipped');
        elseif (config('app.key')) $this->line('APP_KEY already set');
        else { Artisan::call('key:generate', ['--force' => true]); $this->line('APP_KEY generated'); }

        $this->info('2/7 Database');
        if (Artisan::call('migrate', ['--force' => true]) !== 0) { $this->error(trim(Artisan::output())); return self::FAILURE; }
        $this->line('migrations up to date');

        $this->info('3/7 Photo storage');
        $this->storage();

        $this->info('4/7 Branches');
        $file = $this->option('branches') ?: database_path('data/owner-branches.json');
        if ($file === 'skip') $this->line('skipped');
        elseif (! is_file($file)) { $this->error("File not found: $file"); return self::FAILURE; }
        else {
            if (Artisan::call('dudhwala:setup', ['--file' => $file]) !== 0) { $this->error(trim(Artisan::output())); return self::FAILURE; }
            $this->line(trim(Artisan::output()));
        }

        $this->info('5/7 Catalogue');
        if ($this->option('skip-catalogue')) $this->line('skipped');
        else { Artisan::call('dudhwala:import-catalogue'); $this->line(trim(Artisan::output())); }

        $this->info('6/7 First admin');
        $this->admin($audit);

        $this->info('7/7 Caches');
        if ($this->option('skip-cache')) $this->line('skipped');
        else foreach (['config:cache', 'route:cache', 'view:cache'] as $c) { Artisan::call($c); $this->line($c.' ok'); }

        $this->newLine();
        $this->call('dudhwala:preflight');
        return self::SUCCESS;
    }

    /** storage:link; when the host forbids symlinks, keep photos inside the web folder instead. */
    private function storage(): void
    {
        $link = public_path('storage');
        if (is_link($link) || is_dir($link)) { $this->line('public/storage exists'); return; }
        try {
            if (@symlink(storage_path('app/public'), $link)) { $this->line('public/storage linked'); return; }
        } catch (\Throwable) { /* fall through */ }
        File::ensureDirectoryExists($link);
        $env = base_path('.env');
        if (is_file($env) && ! str_contains((string) file_get_contents($env), 'PUBLIC_DISK_IN_PUBLIC')) file_put_contents($env, "\nPUBLIC_DISK_IN_PUBLIC=true\n", FILE_APPEND);
        $this->warn('Symlinks are not allowed here: photos will be stored in public/storage (PUBLIC_DISK_IN_PUBLIC=true written to .env). Run php artisan config:cache again if you cached config.');
    }

    private function admin(AuditService $audit): void
    {
        if (User::where('role', 'ADMIN')->exists()) { $this->line('an admin already exists'); return; }
        $email = $this->option('admin-email');
        if (! $email) { $this->warn('No admin yet. Run again with --admin-email=you@example.com'); return; }
        $pw = $this->option('admin-password') ?: Str::password(16, symbols: false);
        $generated = ! $this->option('admin-password');
        if (strlen($pw) < 12) { $this->error('Password must be at least 12 characters'); return; }
        $u = User::create(['name' => $this->option('admin-name'), 'email' => strtolower($email), 'role' => 'ADMIN', 'password' => $pw]);
        $audit->log('staff.created', 'user', $u->id, null, ['email' => $u->email, 'role' => 'ADMIN'], null, 'CLI');
        $this->line("created ADMIN {$u->email}");
        if ($generated) $this->warn("Temporary password (shown once): $pw");
        $this->line('Sign in at /admin, set up the authenticator app and save the recovery codes.');
    }
}
