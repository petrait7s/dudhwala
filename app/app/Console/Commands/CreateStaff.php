<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateStaff extends Command
{
    protected $signature = 'dudhwala:create-staff {email} {name} {--role=MANAGER : ADMIN or MANAGER} {--password= : omit to be prompted}';
    protected $description = 'Create a staff user (ADMIN or MANAGER)';

    public function handle(AuditService $audit): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 12 chars)');
        $v = Validator::make(
            ['email' => $this->argument('email'), 'name' => $this->argument('name'), 'role' => $this->option('role'), 'password' => $password],
            ['email' => 'required|email|unique:users,email', 'name' => 'required|max:120', 'role' => 'required|in:ADMIN,MANAGER', 'password' => 'required|min:12'],
        );
        if ($v->fails()) { foreach ($v->errors()->all() as $e) $this->error($e); return self::FAILURE; }

        $u = User::create(['email' => strtolower($this->argument('email')), 'name' => $this->argument('name'), 'role' => $this->option('role'), 'password' => $password]);
        $audit->log('staff.created', 'user', $u->id, null, ['email' => $u->email, 'role' => $u->role], null, 'CLI');
        $this->info("Created {$u->role} {$u->email}".($u->isAdmin() ? ' (two-factor must be enrolled at first login)' : ''));
        return self::SUCCESS;
    }
}
