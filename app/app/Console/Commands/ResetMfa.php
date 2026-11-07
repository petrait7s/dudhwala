<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use App\Services\TotpService;
use Illuminate\Console\Command;

class ResetMfa extends Command
{
    protected $signature = 'dudhwala:reset-mfa {email}';
    protected $description = 'Clear two-factor and recovery codes for a staff user';

    public function handle(TotpService $totp, AuditService $audit): int
    {
        $u = User::where('email', strtolower($this->argument('email')))->first();
        if (! $u) { $this->error('No such user'); return self::FAILURE; }
        $totp->reset($u);
        $audit->log('staff.mfa_reset', 'user', $u->id, null, null, null, 'CLI');
        $this->info("Two-factor reset for {$u->email}; re-enrol at next login.");
        return self::SUCCESS;
    }
}
