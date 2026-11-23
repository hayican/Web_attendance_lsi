<?php

namespace App\Models;

use App\Enums\LeaveDayStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $leave_request_id
 * @property int $user_id
 * @property Carbon $leave_date
 * @property string $day_value
 * @property LeaveDayStatus $status
 * @property-read LeaveRequest|null $leaveRequest
 */
#[Fillable([
    'leave_request_id',
    'user_id',
    'leave_date',
    'day_value',
    'status',
    'cancelled_by',
    'cancelled_at',
    'cancel_reason',
])]
class LeaveRequestDay extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leave_date' => 'date',
            'day_value' => 'decimal:1',
            'status' => LeaveDayStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
