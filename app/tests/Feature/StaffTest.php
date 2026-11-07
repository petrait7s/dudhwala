<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\StaffAuth;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'ADMIN'): User
    {
        return User::create(['name' => 'T', 'email' => strtolower($role).'@x.test', 'role' => $role, 'password' => 'correct-horse-battery'])->fresh();
    }

    public function test_create_staff_command_and_audit(): void
    {
        $this->artisan('dudhwala:create-staff', ['email' => 'a@x.test', 'name' => 'A', '--role' => 'ADMIN', '--password' => 'long-enough-pass'])->assertSuccessful();
        $u = User::first();
        $this->assertSame('ADMIN', $u->role);
        $this->assertNotSame('long-enough-pass', $u->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.created', 'entity_id' => (string) $u->id]);
    }

    public function test_create_staff_rejects_weak_password_and_bad_role(): void
    {
        $this->artisan('dudhwala:create-staff', ['email' => 'a@x.test', 'name' => 'A', '--role' => 'ADMIN', '--password' => 'short'])->assertFailed();
        $this->artisan('dudhwala:create-staff', ['email' => 'a@x.test', 'name' => 'A', '--role' => 'ROOT', '--password' => 'long-enough-pass'])->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_manager_is_view_only_admin_can_manage(): void
    {
        $this->assertTrue(Gate::forUser($this->staff('ADMIN'))->allows('manage'));
        $m = $this->staff('MANAGER');
        $this->assertTrue(Gate::forUser($m)->allows('view'));
        $this->assertFalse(Gate::forUser($m)->allows('manage'));
    }

    public function test_suspended_staff_has_no_access(): void
    {
        $u = $this->staff('ADMIN');
        $u->update(['status' => 'SUSPENDED']);
        $this->assertFalse(Gate::forUser($u)->allows('view'));
        $this->assertNull(app(StaffAuth::class)->attempt($u->email, 'correct-horse-battery'));
    }

    public function test_lockout_after_five_failures_then_blocks_correct_password(): void
    {
        $u = $this->staff();
        $auth = app(StaffAuth::class);
        for ($i = 0; $i < 5; $i++) $this->assertNull($auth->attempt($u->email, 'wrong'));
        $this->assertNull($auth->attempt($u->email, 'correct-horse-battery'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.locked']);
        $this->travel(16)->minutes();
        $this->assertNotNull($auth->attempt($u->email, 'correct-horse-battery'));
    }

    public function test_totp_enrol_replay_and_recovery_codes(): void
    {
        $u = $this->staff();
        $totp = app(TotpService::class);
        $g = new Google2FA();
        $totp->beginEnrol($u);
        $secret = $u->fresh()->mfa_secret;
        $this->assertNotEmpty($secret);
        $this->assertNotEquals($secret, \DB::table('users')->value('mfa_secret'), 'secret stored encrypted');

        $this->assertNull($totp->confirmEnrol($u->fresh(), '000000'));
        $codes = $totp->confirmEnrol($u->fresh(), $g->getCurrentOtp($secret));
        $this->assertCount(8, $codes);
        $this->assertTrue($u->fresh()->mfa_enabled);

        // same code cannot be replayed
        $this->assertFalse($totp->verify($u->fresh(), $g->getCurrentOtp($secret)));

        // recovery code works once
        $this->assertTrue($totp->useRecoveryCode($u->fresh(), $codes[0]));
        $this->assertFalse($totp->useRecoveryCode($u->fresh(), $codes[0]));
    }

    public function test_reset_mfa_command(): void
    {
        $u = $this->staff();
        $t = app(TotpService::class);
        $t->beginEnrol($u);
        $t->confirmEnrol($u->fresh(), (new Google2FA())->getCurrentOtp($u->fresh()->mfa_secret));
        $this->artisan('dudhwala:reset-mfa', ['email' => $u->email])->assertSuccessful();
        $u = $u->fresh();
        $this->assertFalse($u->mfa_enabled);
        $this->assertNull($u->mfa_secret);
        $this->assertSame(0, $u->recoveryCodes()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.mfa_reset']);
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = app(\App\Services\AuditService::class)->log('x.y', 'thing', 1, ['a' => 1], ['a' => 2]);
        $this->assertSame(['a' => 2], $log->fresh()->after);
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tampered']);
    }
}
