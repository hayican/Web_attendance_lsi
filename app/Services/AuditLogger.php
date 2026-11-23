<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        int $actorId,
        string $entity,
        int $recordId,
        AuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => $actorId,
            'entity' => $entity,
            'record_id' => $recordId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'created_at' => now('UTC'),
        ]);
    }
}
