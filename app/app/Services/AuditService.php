<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditService
{
    public function log(string $action, string $entityType, string|int|null $entityId = null, mixed $before = null, mixed $after = null, ?User $actor = null, string $role = 'SYSTEM'): AuditLog
    {
        $actor ??= auth()->user();
        $req = app()->bound('request') ? request() : null;
        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->role ?? $role,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 512) : null,
        ]);
    }
}
