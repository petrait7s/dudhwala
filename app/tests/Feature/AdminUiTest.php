<?php

namespace Tests\Feature;

use App\Livewire\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\TestData;
use Tests\TestCase;

class AdminUiTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, bool $mfa = false): User
    {
        $u = User::create(['name' => 'S', 'email' => strtolower($role).'@x.test', 'role' => $role, 'password' => 'long-enough-pass'])->fresh();
        if ($mfa) {
            $t = app(TotpService::class);
            $t->beginEnrol($u);
            $t->confirmEnrol($u->fresh(), (new Google2FA())->getCurrentOtp($u->fresh()->mfa_secret));
            $u->forceFill(['mfa_last_step' => 1])->save(); // so a fresh current code is accepted in the test
        }
        return $u->fresh();
    }

    private function order(): Order
    {
        $o = TestData::outlet();
        $c = \App\Models\Customer::create(['phone' => '01712345678', 'name' => 'N']);
        $ord = Order::create(['order_number' => 'DW-T1', 'customer_id' => $c->id, 'outlet_id' => $o->id, 'method' => 'HOME_DELIVERY', 'zone' => 'IN_CITY', 'status' => 'PLACED', 'contact_name' => 'রহিম', 'contact_phone' => '01712345678', 'address_line1' => 'x', 'subtotal_poisha' => 10000, 'total_poisha' => 10000, 'idempotency_key' => 'k-1234567']);
        Payment::create(['order_id' => $ord->id, 'method' => 'COD', 'amount_poisha' => 10000, 'status' => 'UNPAID']);
        return $ord;
    }

    public function test_admin_pages_need_login(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/admin/orders/99999')->assertRedirect(route('admin.login')); // no 404 that reveals which ids exist
        $o = $this->order();
        $this->get('/admin/orders/'.$o->id)->assertRedirect(route('admin.login'));
    }

    public function test_login_failure_and_success_routes(): void
    {
        $this->staff('MANAGER');
        Livewire::test(Admin\Login::class)->set('email', 'manager@x.test')->set('password', 'wrong')->call('submit')->assertSet('error', __('admin.bad_login'));
        Livewire::test(Admin\Login::class)->set('email', 'manager@x.test')->set('password', 'long-enough-pass')->call('submit')->assertRedirect(route('admin.orders'));
        $this->assertAuthenticated();
    }

    public function test_admin_without_mfa_is_forced_to_enrol_before_anything(): void
    {
        $a = $this->staff('ADMIN');
        $this->actingAs($a)->get('/admin')->assertRedirect(route('admin.security'));
        $o = $this->order();
        $this->actingAs($a)->get('/admin/orders/'.$o->id)->assertRedirect(route('admin.security'));
    }

    public function test_admin_enrolment_flow_gives_recovery_codes_and_access(): void
    {
        $a = $this->staff('ADMIN');
        $this->actingAs($a);
        $c = Livewire::test(Admin\Security::class)->assertSee('svg');
        $secret = $a->fresh()->mfa_secret;
        $c->set('code', '000000')->call('confirm')->assertSet('error', __('admin.mfa_bad'));
        $c->set('code', (new Google2FA())->getCurrentOtp($secret))->call('confirm')->assertSet('error', null);
        $this->assertCount(8, $c->get('recoveryCodes'));
        $this->assertTrue($a->fresh()->mfa_enabled);
        $this->actingAs($a->fresh())->withSession(['mfa_ok' => true])->get('/admin')->assertOk();
    }

    public function test_admin_with_mfa_must_pass_second_step(): void
    {
        $a = $this->staff('ADMIN', true);
        $this->actingAs($a)->get('/admin')->assertRedirect(route('admin.mfa'));
        Livewire::test(Admin\Mfa::class)->set('code', '111111')->call('submit')->assertSet('error', __('admin.mfa_bad'));
        $this->actingAs($a)->get('/admin')->assertRedirect(route('admin.mfa'));
        $secret = $a->fresh()->mfa_secret;
        Livewire::test(Admin\Mfa::class)->set('code', (new Google2FA())->getCurrentOtp($secret))->call('submit')->assertRedirect(route('admin.orders'));
        $this->withSession(['mfa_ok' => true])->get('/admin')->assertOk();
    }

    public function test_recovery_code_works_once_through_ui(): void
    {
        $a = $this->staff('ADMIN');
        $t = app(TotpService::class); $t->beginEnrol($a);
        $codes = $t->confirmEnrol($a->fresh(), (new Google2FA())->getCurrentOtp($a->fresh()->mfa_secret));
        $this->actingAs($a->fresh());
        Livewire::test(Admin\Mfa::class)->set('recovery', $codes[0])->call('submit')->assertRedirect(route('admin.orders'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.recovery_code_used']);
        session()->forget('mfa_ok');
        Livewire::test(Admin\Mfa::class)->set('recovery', $codes[0])->call('submit')->assertSet('error', __('admin.mfa_bad'));
    }

    public function test_manager_sees_orders_read_only_and_cannot_act_even_by_calling_methods(): void
    {
        $o = $this->order();
        $this->actingAs($this->staff('MANAGER'));
        $this->get('/admin')->assertOk()->assertSee('DW-T1');
        $this->get('/admin/orders/'.$o->id)->assertOk()->assertSee(__('admin.view_only'))->assertDontSee(__('admin.cod_collected'))->assertDontSee(__('admin.cancel'));
        $c = Livewire::test(Admin\OrderShow::class, ['order' => $o]);
        $c->call('advance', 'PREPARING')->assertSet('error', __('admin.error', ['msg' => 'Only an admin can change orders']));
        $c->call('codCollected');
        $this->assertSame('PLACED', $o->fresh()->status);
        $this->assertSame('UNPAID', Payment::first()->status);
    }

    public function test_admin_moves_order_collects_cash_and_cancels_with_reason(): void
    {
        $o = $this->order();
        $this->actingAs($this->staff('ADMIN', true));
        session()->put('mfa_ok', true);
        $c = Livewire::test(Admin\OrderShow::class, ['order' => $o]);
        $c->call('advance', 'PREPARING')->assertSet('flash', __('admin.saved'));
        $this->assertSame('PREPARING', $o->fresh()->status);
        $c->call('advance', 'DELIVERED')->assertSet('error', fn ($e) => str_contains($e, 'cannot move'));
        $c->call('codCollected');
        $this->assertSame('PAID', Payment::first()->status);
        $c->set('cancelReason', '')->call('confirmCancel')->assertSet('error', fn ($e) => $e !== null);
        $c->set('cancelReason', 'customer asked')->call('confirmCancel');
        $this->assertSame('CANCELLED', $o->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.refund_required']);
        $this->get('/admin/orders/'.$o->id)->assertSee(__('admin.refund_flag'));
    }

    public function test_order_page_warns_about_earlier_cancellations(): void
    {
        $o1 = $this->order();
        $o1->update(['status' => 'CANCELLED']);
        $o2 = Order::create(array_merge($o1->only(['customer_id', 'outlet_id', 'method', 'zone', 'contact_name', 'contact_phone', 'address_line1', 'subtotal_poisha', 'total_poisha']), ['order_number' => 'DW-T2', 'status' => 'PLACED', 'idempotency_key' => 'k-7654321']));
        $this->actingAs($this->staff('MANAGER'))->get('/admin/orders/'.$o2->id)->assertSee(__('admin.prior_cancels', ['n' => 1]));
    }

    public function test_orders_list_filters(): void
    {
        $o = $this->order();
        $this->actingAs($this->staff('MANAGER'));
        Livewire::test(Admin\Orders::class)->assertSee('DW-T1')->set('status', 'CANCELLED')->assertDontSee('DW-T1')
            ->set('status', '')->set('q', '+8801712345678')->assertSee('DW-T1')->set('q', '01999999999')->assertDontSee('DW-T1');
    }

    public function test_logout_clears_session(): void
    {
        $a = $this->staff('MANAGER');
        $this->actingAs($a)->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }
}
