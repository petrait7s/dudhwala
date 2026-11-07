<?php

namespace App\Services;

use App\Models\MfaRecoveryCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TotpService
{
    public function __construct(private Google2FA $g = new Google2FA()) {}

    public function beginEnrol(User $u): string
    {
        $secret = $this->g->generateSecretKey(32);
        $u->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => false, 'mfa_last_step' => null])->save();
        return $this->g->getQRCodeUrl(config('app.name'), $u->email, $secret);
    }

    /** Verify a 6-digit code. Rejects replay of an already-used time step. */
    public function verify(User $u, string $code): bool
    {
        if (! $u->mfa_secret) return false;
        $step = $this->g->verifyKeyNewer($u->mfa_secret, $code, $u->mfa_last_step ?? 0, 1);
        // 0 (not null) as old step: null makes the library return true instead of the step.
        if (! is_int($step)) return false;
        $u->forceFill(['mfa_last_step' => $step])->save();
        return true;
    }

    public function confirmEnrol(User $u, string $code): ?array
    {
        if (! $this->verify($u, $code)) return null;
        $u->forceFill(['mfa_enabled' => true])->save();
        return $this->issueRecoveryCodes($u);
    }

    /** @return string[] plaintext codes, shown once */
    public function issueRecoveryCodes(User $u, int $n = 8): array
    {
        $u->recoveryCodes()->delete();
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $c = strtolower(bin2hex(random_bytes(5)));
            $codes[] = $c;
            $u->recoveryCodes()->create(['code_hash' => Hash::make($c)]);
        }
        return $codes;
    }

    public function useRecoveryCode(User $u, string $code): bool
    {
        foreach ($u->recoveryCodes()->whereNull('used_at')->get() as $rc) {
            if (Hash::check(strtolower(trim($code)), $rc->code_hash)) {
                $rc->forceFill(['used_at' => now()])->save();
                return true;
            }
        }
        return false;
    }

    public function reset(User $u): void
    {
        $u->recoveryCodes()->delete();
        $u->forceFill(['mfa_secret' => null, 'mfa_enabled' => false, 'mfa_last_step' => null])->save();
    }
}
