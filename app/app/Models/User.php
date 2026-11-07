<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    public const ADMIN = 'ADMIN';
    public const MANAGER = 'MANAGER';

    protected $guarded = [];
    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_enabled' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool { return $this->role === self::ADMIN; }
    public function isActive(): bool { return $this->status === 'ACTIVE'; }
    public function recoveryCodes() { return $this->hasMany(MfaRecoveryCode::class); }
}
