<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * Save an audit log entry.
     */
    public static function log(string $action, string $description, ?User $user = null, ?string $actorName = null, ?Request $request = null): AuditLog
    {
        $req = $request ?: request();

        return AuditLog::create([
            'user_id' => $user?->id,
            'actor_name' => $actorName ?: ($user?->name ?: 'System'),
            'action' => $action,
            'description' => $description,
            'ip_address' => $req->ip(),
            'user_agent' => substr((string) $req->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}
