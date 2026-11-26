<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property int $actor_id
 * @property string $entity
 * @property int $record_id
 * @property AuditAction $action
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $reason
 * @property string|null $prev_hash
 * @property string|null $row_hash
 * @property Carbon $created_at
 */
#[Fillable([
    'actor_id',
    'entity',
    'record_id',
    'action',
    'old_values',
    'new_values',
    'reason',
    'prev_hash',
    'row_hash',
    'created_at',
])]
class AuditLog extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('audit_logs is append-only');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('audit_logs is append-only');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
