<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AuditLogger
{
    /** Sensitive column names that must never reach the permanent audit trail (PRD FR-6). */
    private const array SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'session_id',
        'session_token',
        'csrf_token',
        '_token',
        'XSRF-TOKEN',
    ];

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        ?int $actorId,
        string $entity,
        int $recordId,
        AuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
    ): AuditLog {
        $oldValues = $this->withoutSensitiveKeys($oldValues);
        $newValues = $this->withoutSensitiveKeys($newValues);
        $createdAt = now('UTC');
        $createdAtString = $createdAt->format('Y-m-d H:i:s');

        // MySQL advisory lock serialises chain appends across connections; REPEATABLE READ
        // snapshots in a caller transaction would otherwise let two rows share one prev_hash.
        $lockable = DB::connection()->getDriverName() === 'mysql';

        if ($lockable && ! $this->acquireChainLock()) {
            throw new RuntimeException('Lock rantai audit_logs tidak didapat; penulisan audit dibatalkan agar hash chain tidak bercabang.');
        }

        try {
            /** @var AuditLog $log */
            $log = DB::transaction(function () use ($actorId, $entity, $recordId, $action, $oldValues, $newValues, $reason, $createdAt, $createdAtString): AuditLog {
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
        } finally {
            if ($lockable) {
                DB::selectOne('SELECT RELEASE_LOCK(?)', ['audit_logs_chain']);
            }
        }

        return $log;
    }

    private function acquireChainLock(): bool
    {
        foreach ([5, 10] as $timeout) {
            $result = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', ['audit_logs_chain', $timeout]);

            if ((int) ($result->acquired ?? 0) === 1) {
                return true;
            }
        }

        return false;
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
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function withoutSensitiveKeys(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $filtered = [];

        foreach ($values as $key => $value) {
            if (in_array((string) $key, self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $filtered[$key] = is_array($value) ? $this->withoutSensitiveKeys($value) : $value;
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function hashFor(
        ?int $actorId,
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
