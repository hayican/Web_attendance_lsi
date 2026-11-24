<?php

namespace App\Models;

use App\Enums\ScheduleSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $work_date
 * @property int|null $shift_id
 * @property bool $is_day_off
 * @property ScheduleSource $source
 * @property int $created_by
 * @property-read Shift|null $shift
 */
#[Fillable([
    'user_id',
    'work_date',
    'shift_id',
    'is_day_off',
    'source',
    'created_by',
])]
class UserShiftSchedule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_day_off' => 'boolean',
            'source' => ScheduleSource::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
