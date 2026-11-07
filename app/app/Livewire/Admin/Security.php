<?php

namespace App\Livewire\Admin;

use App\Services\AuditService;
use App\Services\TotpService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Admin two-factor enrolment. Secret is kept server-side only (encrypted); the browser gets the QR and the key text. */
#[Layout('components.layouts.admin')]
class Security extends Component
{
    public string $code = '';
    public ?string $error = null;
    /** @var string[] */
    public array $recoveryCodes = [];

    public function mount()
    {
        $u = Auth::user();
        if (! $u->isAdmin() || ($u->mfa_enabled && ! $this->recoveryCodes)) return redirect()->route('admin.orders');
    }

    private function start(TotpService $totp): array
    {
        $u = Auth::user();
        $url = $u->mfa_secret && ! $u->mfa_enabled
            ? (new \PragmaRX\Google2FA\Google2FA())->getQRCodeUrl(config('app.name'), $u->email, $u->mfa_secret)
            : $totp->beginEnrol($u);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd())))->writeString($url);
        return ['svg' => $svg, 'secret' => Auth::user()->fresh()->mfa_secret];
    }

    public function confirm(TotpService $totp, AuditService $audit)
    {
        $u = Auth::user();
        $codes = $totp->confirmEnrol($u->fresh(), trim($this->code));
        if (! $codes) { $this->error = __('admin.mfa_bad'); $this->code = ''; return; }
        $audit->log('staff.mfa_enrolled', 'user', $u->id, null, null, $u);
        session()->put('mfa_ok', true);
        $this->recoveryCodes = $codes;
        $this->error = null;
    }

    public function done()
    {
        return redirect()->route('admin.orders');
    }

    public function render(TotpService $totp)
    {
        $qr = $this->recoveryCodes ? null : $this->start($totp);
        return view('livewire.admin.security', ['qr' => $qr]);
    }
}
