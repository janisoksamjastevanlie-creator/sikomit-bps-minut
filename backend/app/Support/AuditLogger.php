<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function record(
        Request $request,
        string $action,
        string $subjectType,
        string|int $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
    ): void {
        AuditLog::create([
            'user_id' => $actorId ?? $request->user()?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'before_values' => $before,
            'after_values' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            'occurred_at' => now(),
        ]);
    }
}
