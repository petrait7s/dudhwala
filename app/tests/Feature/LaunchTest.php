<?php

namespace Tests\Feature;

use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class LaunchTest extends TestCase
{
    use RefreshDatabase;

    private function prod(): void
    {
        config(['app.debug' => false, 'app.url' => 'https://admin.example.com', 'cors.allowed_origins' => ['https://www.example.com'], 'session.secure' => true, 'hashing.driver' => 'argon2id']);
    }

    public function test_preflight_blocks_debug_and_missing_cors(): void
    {
        config(['app.debug' => true, 'cors.allowed_origins' => []]);
        $this->artisan('dudhwala:preflight')->expectsOutputToContain('APP_DEBUG is true')->expectsOutputToContain('CORS_ALLOWED_ORIGINS is empty')->assertFailed();
    }

    public function test_preflight_passes_with_production_settings_and_warns_about_missing_owner_data(): void
    {
        $this->prod();
        $this->artisan('dudhwala:preflight')->expectsOutputToContain('No blocking problems')->expectsOutputToContain('No active outlet')
            ->expectsOutputToContain('Showroom limit is still the unapproved placeholder')->expectsOutputToContain('No bKash/Nagad number')->assertSuccessful();
    }

    public function test_preflight_fails_on_placeholder_outlet_data(): void
    {
        $this->prod();
        Outlet::create(['code' => 'M', 'name_bn' => 'x', 'address_line' => 'REPLACE_WITH_REAL_ADDRESS']);
        $this->artisan('dudhwala:preflight')->expectsOutputToContain('REPLACE_WITH_* placeholder')->assertFailed();
    }

    public function test_preflight_goes_green_when_owner_data_is_in(): void
    {
        $this->prod();
        $o = TestData::outlet('M');
        $s = app(\App\Services\SettingsService::class);
        $s->set('fulfilment.dispatch_outlet_id', $o->id); $s->set('bulk.collection_outlet_id', $o->id);
        $s->set('bulk.rule', ['min_total_quantity' => 20, 'min_total_poisha' => 2000000, 'approved' => true]);
        $s->set('payment.numbers', ['bkash' => '01700000000', 'nagad' => null]);
        $v = TestData::variant(1000);
        app(\App\Services\InventoryService::class)->setStock($o->id, $v->id, 5, ['system' => 't'], 'x');
        \Artisan::call('dudhwala:preflight');
        $out = \Artisan::output();
        foreach (['Dispatch outlet set', 'Showroom limit approved', 'bKash/Nagad number(s) set', '1 of 1 products published', 'Stock entered'] as $needle) $this->assertStringContainsString($needle, $out);
        $this->assertStringNotContainsString('WARN Showroom', $out);
    }

    public function test_security_headers_on_admin_and_api(): void
    {
        $r = $this->get('/admin/login');
        $r->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy');
        $api = $this->getJson('/api/config');
        $api->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNull($api->headers->get('X-Frame-Options'));
    }

    public function test_argon2id_is_usable_for_staff_passwords(): void
    {
        if (! defined('PASSWORD_ARGON2ID')) $this->markTestSkipped('PHP without argon2id: bcrypt fallback');
        $h = \Illuminate\Support\Facades\Hash::driver('argon2id')->make('long-enough-pass');
        $this->assertStringStartsWith('$argon2id$', $h);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::driver('argon2id')->check('long-enough-pass', $h));
    }

    public function test_staff_login_works_with_either_hash_driver(): void
    {
        $u = \App\Models\User::create(['name' => 'A', 'email' => 'a@x.test', 'role' => 'ADMIN', 'password' => 'long-enough-pass']);
        $this->assertNotNull(app(\App\Services\StaffAuth::class)->attempt('a@x.test', 'long-enough-pass'));
    }
}
