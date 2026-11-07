<?php

namespace App\Livewire\Admin;

use App\Services\StaffAuth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public ?string $error = null;

    public function mount()
    {
        if (Auth::check()) return redirect()->route('admin.orders');
    }

    public function submit(StaffAuth $auth)
    {
        $key = 'login:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 20)) { $this->error = __('admin.bad_login'); return; }
        RateLimiter::hit($key, 300);

        $u = $auth->attempt($this->email, $this->password);
        if (! $u) { $this->error = __('admin.bad_login'); $this->password = ''; return; }

        Auth::login($u);
        session()->regenerate();
        session()->forget('mfa_ok');
        if ($u->isAdmin()) return redirect()->route($u->mfa_enabled ? 'admin.mfa' : 'admin.security');
        return redirect()->route('admin.orders');
    }

    public function render()
    {
        return view('livewire.admin.login');
    }
}
