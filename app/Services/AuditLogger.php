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
        $createdAtString = $createdAt->format('Y-m-d H:i:s');

        /** @var AuditLog $log */
        $log = DB::transaction(function () use ($actorId, $entity, $recordId, $action, $oldValues, $newValues, $reason, $createdAt, $createdAtString): AuditLog {
            // shortcut: empty audit_logs has no tip row to lock; concurrent first writes can both start the chain.
            $previous = AuditLog::query()->latest('id')->lockForUpdate()->first();
            $prevHash = $previous?->row_hash;
            $rowHash = $this->hashFor($actorId, $entity, $recordId, $action, $oldValues, $newValues, $reason, $createdAtString, $prevHash);

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

    public function verifyHash(AuditLog $log): bool
    {
        if ($log->row_hash === null) {
            return false;
        }

        $expected = $this->hashFor(
            actorId: $log->actor_id,
            entity: $log->entity,
            recordId: $log->record_id,
            action: $log->action,
            oldValues: $log->old_values,
            newValues: $log->new_values,
            reason: $log->reason,
            createdAt: $log->created_at->format('Y-m-d H:i:s'),
            prevHash: $log->prev_hash,
        );

        return hash_equals($log->row_hash, $expected);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function hashFor(
        int $actorId,
        string $entity,
        int $recordId,
        AuditAction $action,
        ?array $oldValues,
        ?array $newValues,
        ?string $reason,
        string $createdAt,
        ?string $prevHash,
    ): string {
        return hash('sha256', json_encode([
            'actor_id' => $actorId,
            'entity' => $entity,
            'record_id' => $recordId,
            'action' => $action->value,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'created_at' => $createdAt,
            'prev_hash' => $prevHash,
        ], JSON_THROW_ON_ERROR));
    }
}
