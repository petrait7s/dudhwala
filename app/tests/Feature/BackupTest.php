<?php

namespace Tests\Feature;

use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

/** Needs MySQL/MariaDB and mysqldump. Skipped on SQLite. */
class BackupTest extends TestCase
{
    public function test_backup_restores_cleanly_into_a_fresh_database(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) $this->markTestSkipped('needs MySQL/MariaDB');
        while (DB::transactionLevel() > 0) DB::commit();
        $this->artisan('migrate:fresh')->run();

        $o = TestData::outlet('BK'); $v = TestData::variant(12345);
        app(\App\Services\InventoryService::class)->setStock($o->id, $v->id, 9, ['system' => 't'], 'x');
        $before = ['outlets' => Outlet::count(), 'variants' => DB::table('product_variants')->count(), 'stock' => (int) DB::table('inventory')->value('quantity_on_hand'), 'price' => (int) DB::table('product_variants')->value('price_poisha')];

        $this->artisan('dudhwala:backup')->assertSuccessful();
        $file = collect(Storage::disk('local')->files('backups'))->sort()->last();
        $this->assertNotNull($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms(Storage::disk('local')->path($file))), -4));

        // restore into a brand new database and compare
        $c = config('database.connections.'.config('database.default'));
        $tmp = $c['database'].'_restore';
        DB::statement("DROP DATABASE IF EXISTS `$tmp`");
        DB::statement("CREATE DATABASE `$tmp`");
        $cmd = sprintf('gunzip -c %s | MYSQL_PWD=%s mariadb -h %s -P %s -u %s %s', escapeshellarg(Storage::disk('local')->path($file)), escapeshellarg((string) $c['password']), escapeshellarg($c['host']), escapeshellarg((string) $c['port']), escapeshellarg($c['username']), escapeshellarg($tmp));
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $after = [
            'outlets' => (int) DB::selectOne("SELECT COUNT(*) c FROM `$tmp`.outlets")->c,
            'variants' => (int) DB::selectOne("SELECT COUNT(*) c FROM `$tmp`.product_variants")->c,
            'stock' => (int) DB::selectOne("SELECT quantity_on_hand q FROM `$tmp`.inventory")->q,
            'price' => (int) DB::selectOne("SELECT price_poisha p FROM `$tmp`.product_variants")->p,
        ];
        $this->assertSame($before, $after);
        DB::statement("DROP DATABASE `$tmp`");
        Storage::disk('local')->delete($file);
        $this->artisan('migrate:fresh')->run();
    }

    public function test_prune_keeps_recent_files(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) $this->markTestSkipped('needs MySQL/MariaDB');
        Storage::disk('local')->put('backups/old.sql.gz', 'x');
        touch(Storage::disk('local')->path('backups/old.sql.gz'), time() - 30 * 86400);
        $this->artisan('dudhwala:backup', ['--keep' => 14])->assertSuccessful();
        Storage::disk('local')->assertMissing('backups/old.sql.gz');
        foreach (Storage::disk('local')->files('backups') as $f) Storage::disk('local')->delete($f);
    }
}
