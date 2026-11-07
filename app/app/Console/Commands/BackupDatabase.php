<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Gzipped mysqldump into storage/app/backups, old files pruned. Runs from cron via the scheduler.
 * Needs the mysqldump (or mariadb-dump) binary; set DB_DUMP_BINARY if it is not on PATH.
 */
class BackupDatabase extends Command
{
    protected $signature = 'dudhwala:backup {--keep=14 : days of backups to keep}';
    protected $description = 'Dump the database to storage/app/backups (gzip) and prune old dumps';

    public function handle(AuditService $audit): int
    {
        $c = config('database.connections.'.config('database.default'));
        if (! in_array($c['driver'], ['mysql', 'mariadb'], true)) { $this->error('Backups need MySQL/MariaDB'); return self::FAILURE; }

        $bin = env('DB_DUMP_BINARY') ?: collect(['mysqldump', 'mariadb-dump'])->first(fn ($b) => (new Process(['which', $b]))->run() === 0);
        if (! $bin) { $this->error('mysqldump not found. Set DB_DUMP_BINARY.'); return self::FAILURE; }

        Storage::disk('local')->makeDirectory('backups');
        $name = 'dudhwala-'.now()->format('Ymd-His').'.sql.gz';
        $path = Storage::disk('local')->path('backups/'.$name);

        $p = new Process([$bin, '--single-transaction', '--routines', '--no-tablespaces', '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], $c['database']], null, ['MYSQL_PWD' => (string) $c['password']]);
        $p->setTimeout(600);
        $gz = gzopen($path, 'wb9');
        $p->run(function ($type, $buf) use ($gz) { if ($type === Process::OUT) gzwrite($gz, $buf); });
        gzclose($gz);
        if (! $p->isSuccessful()) { @unlink($path); $this->error('Dump failed: '.trim($p->getErrorOutput())); return self::FAILURE; }
        chmod($path, 0600);

        $cut = now()->subDays((int) $this->option('keep'))->getTimestamp();
        foreach (Storage::disk('local')->files('backups') as $f) {
            if (str_ends_with($f, '.sql.gz') && Storage::disk('local')->lastModified($f) < $cut) Storage::disk('local')->delete($f);
        }
        $audit->log('backup.created', 'backup', $name, null, ['bytes' => filesize($path)], null, 'CRON');
        $this->info("Backup written: $name (".number_format(filesize($path)).' bytes)');
        return self::SUCCESS;
    }
}
