<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'year',
    'base_quota',
    'prorated_deduction',
    'collective_leave_deduction',
    'used_days',
    'pending_days',
    'adjustment_days',
])]
class LeaveBalance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'base_quota' => 'decimal:1',
            'prorated_deduction' => 'decimal:1',
            'collective_leave_deduction' => 'decimal:1',
            'used_days' => 'decimal:1',
            'pending_days' => 'decimal:1',
            'adjustment_days' => 'decimal:1',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function availableBalance(): float
    {
        return (float) $this->base_quota
            - (float) $this->prorated_deduction
            - (float) $this->collective_leave_deduction
            - (float) $this->used_days
            - (float) $this->pending_days
            + (float) $this->adjustment_days;
    }
}
