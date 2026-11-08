<?php

namespace Tests\Feature;

use App\Exceptions\ShopException;
use App\Livewire\Admin;
use App\Models\User;
use App\Services\AdminCatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::create(['name' => 'A', 'email' => 'a@x.test', 'role' => 'ADMIN', 'password' => 'long-enough-pass', 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->fresh();
        $this->actingAs($u)->withSession(['mfa_ok' => true]);
        return $u;
    }

    public function test_language_switch_is_remembered_in_session_and_cookie(): void
    {
        $this->get('/admin/login')->assertSee('Sign in')->assertDontSee('সাইন ইন');
        $r = $this->post('/admin/locale/bn')->assertRedirect();
        $r->assertCookie('dw_admin_locale', 'bn');
        $this->get('/admin/login')->assertSee('সাইন ইন')->assertSee('বাংলা');
        $this->post('/admin/locale/en');
        $this->get('/admin/login')->assertSee('Sign in');
        $this->post('/admin/locale/fr')->assertNotFound();
    }

    public function test_cookie_alone_restores_the_language(): void
    {
        $this->withCookie('dw_admin_locale', 'bn')->get('/admin/login')->assertSee('সাইন ইন');
    }

    public function test_every_admin_page_renders_in_bangla_and_english(): void
    {
        $this->admin();
        foreach (['bn', 'en'] as $l) {
            $this->withSession(['mfa_ok' => true, 'locale' => $l]);
            foreach (['orders', 'products', 'bulk', 'categories', 'outlets', 'settings', 'pages', 'staff', 'audit', 'account'] as $r) {
                $res = $this->get(route($r === 'orders' ? 'admin.orders' : 'admin.'.$r))->assertOk();
                $res->assertSee(__('admin.nav.'.($r === 'account' ? 'orders' : $r), [], $l));
                $this->assertStringContainsString('<html lang="'.$l.'">', $res->getContent());
            }
        }
    }

    public function test_sidebar_shows_the_admin_group_only_to_admins(): void
    {
        $m = User::create(['name' => 'M', 'email' => 'm@x.test', 'role' => 'MANAGER', 'password' => 'long-enough-pass'])->fresh();
        $this->actingAs($m)->get('/admin')->assertOk()->assertSee(__('admin.nav.products'))->assertDontSee(__('admin.nav.staff'))->assertDontSee(__('admin.nav.settings'));
    }

    public function test_service_messages_and_validation_are_translated(): void
    {
        $this->admin();
        app()->setLocale('bn');
        $c = Livewire::test(Admin\Settings::class)->set('f.open', '25:00')->call('save');
        $c->assertSet('error', __('admin.error', ['msg' => 'সময় এভাবে লিখুন: 10:00']));
        try {
            app(AdminCatalogueService::class)->saveCategory(auth()->user(), null, ['slug' => 'ok', 'name_bn' => '']);
            $this->fail();
        } catch (ShopException $e) {
            $this->assertStringContainsString('বাংলা নাম লিখতে হবে', $e->getMessage());
        }
    }

    public function test_every_service_message_has_a_bangla_translation(): void
    {
        $bn = json_decode(file_get_contents(lang_path('bn.json')), true);
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $f) {
            if ($f->getExtension() !== 'php') continue;
            $src = file_get_contents($f->getPathname());
            // ShopException messages in the admin-side services (public API texts stay English)
            if (! preg_match('#Services/(Admin|Staff|Payment|Order|ProductImage|Catalogue|Inventory)#', $f->getPathname()) && ! str_contains($f->getPathname(), 'Livewire')) continue;
            preg_match_all("/new ShopException\('[A-Z_]+', '((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
            foreach ($m[1] as $msg) { $msg = stripslashes($msg); if (! isset($bn[$msg])) $missing[$msg] = true; }
            preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'(?:, \[|\))/", $src, $m2);
            foreach ($m2[1] as $msg) { $msg = stripslashes($msg); if (! str_contains($msg, '.') || str_contains($msg, ' ')) { if (! isset($bn[$msg])) $missing[$msg] = true; } }
        }
        // customer-facing API messages are English by design
        foreach (['Product not found', 'Product not available', 'Price not set yet', 'Quantity must be a whole number of at least 1', 'Not enough stock', 'Checkout is not valid', 'Cart is empty', 'Could not place the order, please retry', 'Could not allocate an order number', 'No fulfilling outlet is configured yet'] as $api) unset($missing[$api]);
        $this->assertSame([], array_keys($missing), 'add these to lang/bn.json');
    }
}
