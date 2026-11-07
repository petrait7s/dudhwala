<?php

namespace Tests\Feature;

use App\Exceptions\ShopException;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private const SYS = ['system' => 'test'];

    private function inv(int $o, int $v): object { return DB::table('inventory')->where(['outlet_id' => $o, 'product_variant_id' => $v])->first(); }

    public function test_set_stock_writes_ledger_with_actor_and_reason(): void
    {
        $o = TestData::outlet(); $v = TestData::variant();
        $s = app(InventoryService::class);
        $s->setStock($o->id, $v->id, 10, self::SYS, 'opening count');
        $s->setStock($o->id, $v->id, 7, self::SYS, 'recount');
        $this->assertSame(7, $this->inv($o->id, $v->id)->quantity_on_hand);
        $m = StockMovement::orderBy('id')->get();
        $this->assertSame([10, -3], $m->pluck('qty_delta_on_hand')->all());
        $this->assertSame('recount', $m[1]->reason);
        $this->assertSame('test', $m[1]->actor_system);
    }

    public function test_set_stock_needs_reason_and_valid_quantity(): void
    {
        $o = TestData::outlet(); $v = TestData::variant();
        $s = app(InventoryService::class);
        $this->expectException(ShopException::class);
        $s->setStock($o->id, $v->id, -1, self::SYS, 'x');
    }

    public function test_reserve_beyond_available_is_refused_and_keeps_nothing(): void
    {
        $o = TestData::outlet(); $a = TestData::variant(); $b = TestData::variant();
        $s = app(InventoryService::class);
        $s->setStock($o->id, $a->id, 5, self::SYS, 'init');
        $s->setStock($o->id, $b->id, 1, self::SYS, 'init');
        $ord = TestData::order($o);
        try {
            $s->reserve($o->id, [['variant_id' => $a->id, 'qty' => 3], ['variant_id' => $b->id, 'qty' => 2]], $ord->id, self::SYS);
            $this->fail('expected OUT_OF_STOCK');
        } catch (ShopException $e) {
            $this->assertSame('OUT_OF_STOCK', $e->errorCode);
            $this->assertSame($b->id, $e->context['variant_id']);
        }
        $this->assertSame(0, $this->inv($o->id, $a->id)->reserved_quantity, 'first line rolled back');
        $this->assertSame(0, StockMovement::where('type', 'RESERVE')->count());
    }

    public function test_untracked_or_missing_inventory_row_cannot_be_reserved(): void
    {
        $o = TestData::outlet(); $v = TestData::variant();
        $this->expectException(ShopException::class);
        app(InventoryService::class)->reserve($o->id, [['variant_id' => $v->id, 'qty' => 1]], TestData::order($o)->id, self::SYS);
    }

    public function test_release_returns_stock_and_is_idempotent(): void
    {
        $o = TestData::outlet(); $v = TestData::variant(); $s = app(InventoryService::class);
        $s->setStock($o->id, $v->id, 5, self::SYS, 'init');
        $ord = TestData::order($o);
        $s->reserve($o->id, [['variant_id' => $v->id, 'qty' => 4]], $ord->id, self::SYS);
        $this->assertSame(4, $this->inv($o->id, $v->id)->reserved_quantity);
        $s->release($ord->id, self::SYS, 'cancelled');
        $s->release($ord->id, self::SYS, 'cancelled');
        $row = $this->inv($o->id, $v->id);
        $this->assertSame([5, 0], [$row->quantity_on_hand, $row->reserved_quantity]);
        $this->assertSame(1, StockMovement::where('type', 'RELEASE')->count());
    }

    public function test_commit_removes_units_once_and_cannot_be_released_after(): void
    {
        $o = TestData::outlet(); $v = TestData::variant(); $s = app(InventoryService::class);
        $s->setStock($o->id, $v->id, 5, self::SYS, 'init');
        $ord = TestData::order($o);
        $s->reserve($o->id, [['variant_id' => $v->id, 'qty' => 2]], $ord->id, self::SYS);
        $s->commit($ord->id, self::SYS);
        $s->commit($ord->id, self::SYS);
        $s->release($ord->id, self::SYS, 'late');
        $row = $this->inv($o->id, $v->id);
        $this->assertSame([3, 0], [$row->quantity_on_hand, $row->reserved_quantity]);
        $this->assertSame(1, StockMovement::where('type', 'COMMIT_SALE')->count());
        $this->assertSame(0, StockMovement::where('type', 'RELEASE')->count());
    }

    public function test_cannot_set_stock_below_reserved(): void
    {
        $o = TestData::outlet(); $v = TestData::variant(); $s = app(InventoryService::class);
        $s->setStock($o->id, $v->id, 5, self::SYS, 'init');
        $s->reserve($o->id, [['variant_id' => $v->id, 'qty' => 4]], TestData::order($o)->id, self::SYS);
        try { $s->setStock($o->id, $v->id, 3, self::SYS, 'recount'); $this->fail(); } catch (ShopException $e) { $this->assertSame('CONFLICT', $e->errorCode); }
        $s->setStock($o->id, $v->id, 4, self::SYS, 'recount');
        $this->assertSame(4, $this->inv($o->id, $v->id)->quantity_on_hand);
    }

    public function test_stock_is_per_outlet_and_available_counts_active_outlets_only(): void
    {
        $o1 = TestData::outlet(); $o2 = TestData::outlet(); $v = TestData::variant(); $s = app(InventoryService::class);
        $s->setStock($o1->id, $v->id, 5, self::SYS, 'i');
        $s->setStock($o2->id, $v->id, 8, self::SYS, 'i');
        $s->reserve($o1->id, [['variant_id' => $v->id, 'qty' => 2]], TestData::order($o1)->id, self::SYS);
        $this->assertSame([$o1->id => 3, $o2->id => 8], $s->availableAt([$v->id])[$v->id]);
        $o2->update(['status' => 'PAUSED']);
        $this->assertSame([$o1->id => 3], $s->availableAt([$v->id])[$v->id]);
    }

    public function test_stock_ledger_is_append_only(): void
    {
        $o = TestData::outlet(); $v = TestData::variant();
        app(InventoryService::class)->setStock($o->id, $v->id, 1, self::SYS, 'i');
        $this->expectException(\LogicException::class);
        StockMovement::first()->delete();
    }

    /** Needs a real MySQL/MariaDB (separate processes, row locks). Skipped on SQLite. */
    public function test_parallel_orders_cannot_oversell(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) $this->markTestSkipped('needs MySQL/MariaDB');
        // RefreshDatabase wraps the test in a transaction the child processes cannot see: commit what we need.
        while (DB::transactionLevel() > 0) DB::commit();

        $o = TestData::outlet(); $v = TestData::variant();
        app(InventoryService::class)->setStock($o->id, $v->id, 5, self::SYS, 'init');
        $orders = array_map(fn () => TestData::order($o)->id, range(1, 12));

        $start = microtime(true) + 1.5;
        $procs = [];
        foreach ($orders as $oid) {
            $cmd = [PHP_BINARY, base_path('tests/Support/reserve_worker.php'), $o->id, $v->id, $oid, 1, sprintf('%.3f', $start)];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), getenv());
            $procs[] = [$p, $pipes];
        }
        $results = [];
        foreach ($procs as [$p, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1])).trim(stream_get_contents($pipes[2]));
            proc_close($p);
        }
        $counts = array_count_values($results);
        $row = $this->inv($o->id, $v->id);
        $this->assertSame(5, $counts['OK'] ?? 0, json_encode($results));
        $this->assertSame(7, $counts['OUT_OF_STOCK'] ?? 0, json_encode($results));
        $this->assertSame(5, $row->reserved_quantity);
        $this->assertSame(5, StockMovement::where('type', 'RESERVE')->count());
        // clean up committed data (RefreshDatabase will not, we left its transaction)
        $this->artisan('migrate:fresh');
    }
}
