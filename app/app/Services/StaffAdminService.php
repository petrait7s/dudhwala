<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/** Staff accounts. ADMIN only (except changing your own password). Every change is audited. */
class StaffAdminService
{
    public function __construct(private AuditService $audit, private TotpService $totp) {}

    private function admin(User $u): void
    {
        if (! $u->isActive() || ! $u->isAdmin()) throw new ShopException('FORBIDDEN', 'Only an admin can manage staff');
    }

    private function validate(array $data, array $rules): array
    {
        $v = Validator::make($data, $rules);
        if ($v->fails()) throw new ShopException('VALIDATION_FAILED', $v->errors()->first(), ['fields' => array_keys($v->errors()->toArray())]);
        return $v->validated();
    }

    public function create(User $by, array $in): User
    {
        $this->admin($by);
        $d = $this->validate($in, ['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'role' => 'required|in:ADMIN,MANAGER', 'password' => 'required|string|min:12|max:200']);
        $u = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'role' => $d['role'], 'password' => $d['password']]);
        $this->audit->log('staff.created', 'user', $u->id, null, ['email' => $u->email, 'role' => $u->role], $by);
        return $u;
    }

    public function setStatus(User $by, User $target, bool $active): void
    {
        $this->admin($by);
        if (! $active) {
            if ($target->id === $by->id) throw new ShopException('CONFLICT', 'You cannot suspend yourself');
            $others = User::where('role', 'ADMIN')->where('status', 'ACTIVE')->where('id', '!=', $target->id)->count();
            if ($target->isAdmin() && $target->isActive() && $others === 0) throw new ShopException('CONFLICT', 'At least one active admin must remain');
        }
        $before = $target->status;
        $target->forceFill(['status' => $active ? 'ACTIVE' : 'SUSPENDED', 'failed_logins' => 0, 'locked_until' => null])->save();
        if (! $active) DB::table('sessions')->where('user_id', $target->id)->delete(); // signed out everywhere
        $this->audit->log($active ? 'staff.activated' : 'staff.suspended', 'user', $target->id, ['status' => $before], ['status' => $target->status], $by);
    }

    public function resetMfa(User $by, User $target): void
    {
        $this->admin($by);
        $this->totp->reset($target);
        DB::table('sessions')->where('user_id', $target->id)->delete();
        $this->audit->log('staff.mfa_reset', 'user', $target->id, null, null, $by);
    }

    /** Set a new temporary password for someone else (they should change it after signing in). */
    public function resetPassword(User $by, User $target, string $password): void
    {
        $this->admin($by);
        $this->validate(['p' => $password], ['p' => 'required|string|min:12|max:200']);
        $target->forceFill(['password' => $password, 'failed_logins' => 0, 'locked_until' => null])->save();
        DB::table('sessions')->where('user_id', $target->id)->delete();
        $this->audit->log('staff.password_reset', 'user', $target->id, null, null, $by);
    }

    /** Anyone can change their own password. */
    public function changeOwnPassword(User $me, string $current, string $new, ?string $keepSessionId = null): void
    {
        if (! Hash::check($current, $me->password)) throw new ShopException('VALIDATION_FAILED', 'Current password is wrong', ['fields' => ['current']]);
        $this->validate(['p' => $new], ['p' => 'required|string|min:12|max:200']);
        if (hash_equals($current, $new)) throw new ShopException('VALIDATION_FAILED', 'The new password must be different', ['fields' => ['new']]);
        $me->forceFill(['password' => $new])->save();
        DB::table('sessions')->where('user_id', $me->id)->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))->delete();
        $this->audit->log('staff.password_changed', 'user', $me->id, null, null, $me);
    }
}
