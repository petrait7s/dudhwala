<?php

namespace Tests\Feature;

use App\Exceptions\ShopException;
use App\Livewire\Admin;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\StaffAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\TestData;
use Tests\TestCase;

class AdminOpsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'ADMIN', string $email = null, bool $mfa = true): User
    {
        return User::create(['name' => ucfirst(strtolower($role)), 'email' => $email ?? strtolower($role).'@x.test', 'role' => $role, 'password' => 'long-enough-pass', 'mfa_enabled' => $mfa, 'mfa_secret' => $mfa ? 'JBSWY3DPEHPK3PXP' : null])->fresh();
    }
    private function login(User $u): void { $this->actingAs($u)->withSession(['mfa_ok' => true]); }
    private function code(callable $f): string { try { $f(); return 'ok'; } catch (ShopException $e) { return $e->errorCode; } }

    /* ───── staff ───── */

    public function test_admin_creates_staff_and_new_admin_must_still_enrol_two_factor(): void
    {
        $a = $this->user(); $svc = app(StaffAdminService::class);
        $m = $svc->create($a, ['name' => 'Manager One', 'email' => 'Mgr@X.test', 'role' => 'MANAGER', 'password' => 'a-long-temp-pass']);
        $this->assertSame(['mgr@x.test', 'MANAGER'], [$m->email, $m->role]);
        $this->assertTrue(Hash::check('a-long-temp-pass', $m->password));
        $n = $svc->create($a, ['name' => 'Admin Two', 'email' => 'a2@x.test', 'role' => 'ADMIN', 'password' => 'another-long-pass']);
        $this->actingAs($n->fresh())->get('/admin')->assertRedirect(route('admin.security'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.created', 'entity_id' => (string) $m->id]);
        foreach ([['email' => 'bad', 'password' => 'a-long-temp-pass'], ['email' => 'mgr@x.test', 'password' => 'a-long-temp-pass'], ['email' => 'ok@x.test', 'password' => 'short']] as $bad) {
            $this->assertSame('VALIDATION_FAILED', $this->code(fn () => $svc->create($a, $bad + ['name' => 'X', 'role' => 'MANAGER'])));
        }
        $this->assertSame('VALIDATION_FAILED', $this->code(fn () => $svc->create($a, ['name' => 'X', 'email' => 'r@x.test', 'role' => 'ROOT', 'password' => 'a-long-temp-pass'])));
    }

    public function test_manager_cannot_manage_staff_even_by_calling_the_service_or_page(): void
    {
        $m = $this->user('MANAGER'); $a = $this->user('ADMIN');
        $svc = app(StaffAdminService::class);
        $this->assertSame('FORBIDDEN', $this->code(fn () => $svc->create($m, ['name' => 'X', 'email' => 'r@x.test', 'role' => 'ADMIN', 'password' => 'a-long-temp-pass'])));
        $this->assertSame('FORBIDDEN', $this->code(fn () => $svc->setStatus($m, $a, false)));
        $this->assertSame('FORBIDDEN', $this->code(fn () => $svc->resetPassword($m, $a, 'a-long-temp-pass')));
        $this->login($m);
        $this->get('/admin/staff')->assertForbidden();
        $this->get('/admin/orders-export')->assertForbidden();
    }

    public function test_suspend_signs_the_person_out_and_blocks_login_and_protects_the_last_admin(): void
    {
        $a = $this->user(); $b = $this->user('ADMIN', 'b@x.test'); $m = $this->user('MANAGER');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $m->id, 'payload' => 'x', 'last_activity' => time()]);
        $svc = app(StaffAdminService::class);
        $svc->setStatus($a, $m, false);
        $this->assertSame('SUSPENDED', $m->fresh()->status);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $m->id)->count());
        $this->assertNull(app(\App\Services\StaffAuth::class)->attempt($m->email, 'long-enough-pass'));
        $svc->setStatus($a, $m, true);
        $this->assertNotNull(app(\App\Services\StaffAuth::class)->attempt($m->email, 'long-enough-pass'));

        $this->assertSame('CONFLICT', $this->code(fn () => $svc->setStatus($a, $a, false)), 'not yourself');
        $svc->setStatus($a, $b, false);
        $b->forceFill(['status' => 'ACTIVE'])->save();
        $a->update(['status' => 'SUSPENDED']);
        $this->assertSame('CONFLICT', $this->code(fn () => $svc->setStatus($b->fresh(), $a->fresh(), true) ?: $svc->setStatus($b->fresh(), $b->fresh(), false)));
    }

    public function test_reset_two_factor_and_temporary_password(): void
    {
        $a = $this->user(); $b = $this->user('ADMIN', 'b@x.test');
        $svc = app(StaffAdminService::class);
        $svc->resetMfa($a, $b);
        $this->assertSame([false, null], [$b->fresh()->mfa_enabled, $b->fresh()->mfa_secret]);
        $svc->resetPassword($a, $b, 'brand-new-temp-pass');
        $this->assertTrue(Hash::check('brand-new-temp-pass', $b->fresh()->password));
        $this->assertSame('VALIDATION_FAILED', $this->code(fn () => $svc->resetPassword($a, $b, 'short')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.password_reset']);
        $this->assertStringNotContainsString('brand-new', json_encode(AuditLog::all()->toArray()), 'passwords never reach the audit log');
    }

    public function test_staff_screen_create_and_one_time_password(): void
    {
        $a = $this->user(); $this->login($a);
        $c = Livewire::test(Admin\Staff::class)->call('generate')->set('new.name', 'Rahim')->set('new.email', 'rahim@x.test')->call('create');
        $c->assertSet('flash', __('admin.saved'))->assertSet('shownPassword', fn ($p) => strlen($p) >= 12);
        $this->assertNotNull(User::where('email', 'rahim@x.test')->first());
        $c->call('toggle', User::where('email', 'rahim@x.test')->value('id'));
        $this->assertSame('SUSPENDED', User::where('email', 'rahim@x.test')->value('status'));
        $this->get('/admin/staff')->assertOk()->assertSee('rahim@x.test');
    }

    public function test_change_own_password(): void
    {
        $m = $this->user('MANAGER'); $svc = app(StaffAdminService::class);
        $this->assertSame('VALIDATION_FAILED', $this->code(fn () => $svc->changeOwnPassword($m, 'wrong-current-pass', 'a-brand-new-pass-1')));
        $this->assertSame('VALIDATION_FAILED', $this->code(fn () => $svc->changeOwnPassword($m, 'long-enough-pass', 'short')));
        $this->assertSame('VALIDATION_FAILED', $this->code(fn () => $svc->changeOwnPassword($m, 'long-enough-pass', 'long-enough-pass')));
        DB::table('sessions')->insert([['id' => 'keep', 'user_id' => $m->id, 'payload' => 'x', 'last_activity' => time()], ['id' => 'drop', 'user_id' => $m->id, 'payload' => 'x', 'last_activity' => time()]]);
        $svc->changeOwnPassword($m, 'long-enough-pass', 'a-brand-new-pass-1', 'keep');
        $this->assertTrue(Hash::check('a-brand-new-pass-1', $m->fresh()->password));
        $this->assertSame(['keep'], DB::table('sessions')->where('user_id', $m->id)->pluck('id')->all(), 'other devices are signed out');
        $this->login($m->fresh());
        Livewire::test(Admin\Account::class)->set('current', 'a-brand-new-pass-1')->set('new', 'yet-another-pass-22')->call('save')->assertSet('flash', __('admin.saved'));
    }

    /* ───── CSV export ───── */

    private function order(string $name = 'রহিম', string $status = 'PLACED'): Order
    {
        $o = TestData::outlet(); $c = Customer::firstOrCreate(['phone' => '01712345678'], ['name' => 'N']);
        $ord = Order::create(['order_number' => 'DW-'.bin2hex(random_bytes(3)), 'customer_id' => $c->id, 'outlet_id' => $o->id, 'method' => 'HOME_DELIVERY', 'zone' => 'IN_CITY', 'status' => $status, 'contact_name' => $name, 'contact_phone' => '01712345678', 'address_line1' => 'সিলেট', 'subtotal_poisha' => 12345, 'total_poisha' => 12345, 'idempotency_key' => bin2hex(random_bytes(6))]);
        Payment::create(['order_id' => $ord->id, 'method' => 'COD', 'amount_poisha' => 12345, 'status' => 'UNPAID']);
        return $ord;
    }

    public function test_csv_export_is_admin_only_filtered_and_safe_from_formula_injection(): void
    {
        $this->login($this->user());
        $this->order('=HYPERLINK("http://evil","x")'); $this->order('রহিম', 'CANCELLED');
        $all = $this->get('/admin/orders-export')->assertOk();
        $this->assertStringContainsString('text/csv', $all->headers->get('Content-Type'));
        $csv = $all->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'leading = is neutralised');
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertSame(3, count(array_filter(explode("\n", trim($csv)))), 'header + 2 orders');
        $this->assertStringContainsString('123.45', $csv);
        $only = $this->get('/admin/orders-export?status=CANCELLED')->streamedContent();
        $this->assertSame(2, count(array_filter(explode("\n", trim($only)))));
        $future = $this->get('/admin/orders-export?from='.now('Asia/Dhaka')->addDay()->format('Y-m-d'))->streamedContent();
        $this->assertSame(1, count(array_filter(explode("\n", trim($future)))), 'date filter');
    }

    /* ───── dashboard numbers ───── */

    public function test_orders_screen_shows_what_needs_attention(): void
    {
        $this->login($this->user());
        $this->order(); $this->order(); $this->order('x', 'CANCELLED');
        $o = $this->order(); Payment::where('order_id', $o->id)->update(['method' => 'BKASH', 'status' => 'PENDING_VERIFICATION', 'trx_id' => 'T1']);
        Livewire::test(Admin\Orders::class)->assertViewHas('stats', fn ($s) => $s === ['today' => 4, 'new' => 3, 'verify' => 1, 'cod_due' => 2]);
    }
}
