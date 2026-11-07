<?php

namespace App\Livewire\Admin;

use App\Services\AuditService;
use App\Services\TotpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Mfa extends Component
{
    public string $code = '';
    public string $recovery = '';
    public ?string $error = null;

    public function mount()
    {
        $u = Auth::user();
        if (! $u->isAdmin()) return redirect()->route('admin.orders');
        if (! $u->mfa_enabled) return redirect()->route('admin.security');
        if (session('mfa_ok')) return redirect()->route('admin.orders');
    }

    public function submit(TotpService $totp, AuditService $audit)
    {
        $u = Auth::user();
        $key = 'mfa:'.$u->id;
        if (RateLimiter::tooManyAttempts($key, 8)) { $this->error = __('admin.mfa_bad'); return; }
        RateLimiter::hit($key, 300);

        $ok = $this->code !== '' ? $totp->verify($u, trim($this->code)) : ($this->recovery !== '' && $totp->useRecoveryCode($u, $this->recovery));
        if (! $ok) { $this->error = __('admin.mfa_bad'); $this->code = $this->recovery = ''; return; }
        if ($this->code === '') $audit->log('auth.recovery_code_used', 'user', $u->id, null, null, $u);

        RateLimiter::clear($key);
        session()->put('mfa_ok', true);
        return redirect()->route('admin.orders');
    }

    public function render()
    {
        return view('livewire.admin.mfa');
    }
}
