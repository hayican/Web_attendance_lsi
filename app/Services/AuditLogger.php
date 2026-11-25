<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

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
        $createdAt = now('UTC');
        $hashPayload = [
            'actor_id' => $actorId,
            'entity' => $entity,
            'record_id' => $recordId,
            'action' => $action->value,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ];

        /** @var AuditLog $log */
        $log = DB::transaction(function () use ($hashPayload, $actorId, $entity, $recordId, $action, $oldValues, $newValues, $reason, $createdAt): AuditLog {
            // shortcut: empty audit_logs has no tip row to lock; concurrent first writes can both start the chain.
            $previous = AuditLog::query()->latest('id')->lockForUpdate()->first();
            $prevHash = $previous?->row_hash;
            $rowHash = hash('sha256', json_encode([...$hashPayload, 'prev_hash' => $prevHash], JSON_THROW_ON_ERROR));

            return AuditLog::query()->create([
                'actor_id' => $actorId,
                'entity' => $entity,
                'record_id' => $recordId,
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'reason' => $reason,
                'prev_hash' => $prevHash,
                'row_hash' => $rowHash,
                'created_at' => $createdAt,
            ]);
        });

        return $log;
    }
}
