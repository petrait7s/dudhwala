<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Password step with lockout. MFA is a separate second step (TotpService). */
class StaffAuth
{
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 15;

    public function __construct(private AuditService $audit) {}

    public function attempt(string $email, string $password): ?User
    {
        $u = User::where('email', strtolower(trim($email)))->first();
        if (! $u) { Hash::check($password, Hash::make('timing-equaliser')); return null; }
        if (! $u->isActive() || ($u->locked_until && $u->locked_until->isFuture())) return null;

        if (! Hash::check($password, $u->password)) {
            $fails = $u->failed_logins + 1;
            $u->forceFill($fails >= self::MAX_FAILS
                ? ['failed_logins' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
                : ['failed_logins' => $fails])->save();
            if ($fails >= self::MAX_FAILS) $this->audit->log('auth.locked', 'user', $u->id, null, null, $u, $u->role);
            return null;
        }
        $u->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        return $u;
    }
}
