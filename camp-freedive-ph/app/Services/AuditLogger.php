<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuditLogger
{
    /** Whether audit_logs.actor_id exists (null = not checked yet in this process). */
    private static ?bool $hasActorColumn = null;

    /**
     * Save an audit log entry.
     *
     * $user is the account the entry is about (the target). The actor is whoever is logged in
     * (null for guests, e.g. failed logins or forgot-password).
     *
     * Audit logging never breaks the request it records: logins run through here, so a missing
     * column (migration not applied yet) or a failed insert must not lock anyone out. The insert
     * runs in its own savepoint, so a failure cannot abort a surrounding transaction either.
     */
    public static function log(string $action, string $description, ?User $user = null, ?string $actorName = null, ?Request $request = null): AuditLog
    {
        $req = $request ?: request();

        $attributes = [
            'user_id' => $user?->id,
            'actor_name' => $actorName ?: ($user?->name ?: 'System'),
            'action' => $action,
            'description' => $description,
            'ip_address' => $req->ip(),
            'user_agent' => substr((string) $req->userAgent(), 0, 255),
            'created_at' => now(),
        ];

        if (self::hasActorColumn()) {
            $attributes['actor_id'] = auth()->id();
        }

        try {
            return DB::transaction(fn () => AuditLog::create($attributes));
        } catch (Throwable $e) {
            // Class name only: messages can contain SQL with personal data
            Log::error('Audit log entry could not be saved.', ['action' => $action, 'exception' => class_basename($e)]);

            return new AuditLog($attributes);
        }
    }

    /** Forget the cached column check (tests, or a worker that outlived a migration). */
    public static function flushSchemaCache(): void
    {
        self::$hasActorColumn = null;
    }

    private static function hasActorColumn(): bool
    {
        return self::$hasActorColumn ??= (bool) rescue(fn () => Schema::hasColumn('audit_logs', 'actor_id'), false, false);
    }
}
