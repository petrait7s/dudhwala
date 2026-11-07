<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    private array $flags = ['--skip-key' => true, '--skip-cache' => true];

    public function test_one_command_sets_up_branches_catalogue_and_first_admin_and_is_repeatable(): void
    {
        $this->artisan('dudhwala:install', $this->flags + ['--admin-email' => 'Owner@Example.com', '--admin-password' => 'a-long-owner-pass'])
            ->expectsOutputToContain('created ADMIN owner@example.com')->assertSuccessful();
        $this->assertSame(4, Outlet::count());
        $this->assertSame(59, Product::count());
        $a = User::where('role', 'ADMIN')->first();
        $this->assertSame('owner@example.com', $a->email);
        $this->assertFalse($a->mfa_enabled, 'two-factor is enrolled at first sign-in');

        $this->artisan('dudhwala:install', $this->flags + ['--admin-email' => 'other@example.com'])->expectsOutputToContain('an admin already exists')->assertSuccessful();
        $this->assertSame([4, 59, 1], [Outlet::count(), Product::count(), User::count()]);
    }

    public function test_generated_password_is_shown_once_and_weak_ones_are_refused(): void
    {
        $this->artisan('dudhwala:install', $this->flags + ['--admin-email' => 'a@example.com', '--skip-catalogue' => true, '--branches' => 'skip'])->expectsOutputToContain('Temporary password')->assertSuccessful();
        $this->assertSame(1, User::count());
        User::query()->delete();
        $this->artisan('dudhwala:install', $this->flags + ['--admin-email' => 'a@example.com', '--admin-password' => 'short', '--skip-catalogue' => true, '--branches' => 'skip'])->expectsOutputToContain('at least 12')->assertSuccessful();
        $this->assertSame(0, User::count());
    }

    public function test_bad_branches_file_stops_the_install(): void
    {
        $this->artisan('dudhwala:install', $this->flags + ['--branches' => '/nope.json'])->assertFailed();
    }

    public function test_without_an_admin_email_it_only_warns(): void
    {
        $this->artisan('dudhwala:install', $this->flags + ['--skip-catalogue' => true, '--branches' => 'skip'])->expectsOutputToContain('No admin yet')->assertSuccessful();
    }
}
