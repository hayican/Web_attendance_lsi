<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'start_time',
    'end_time',
    'grace_period_minutes',
    'auto_close_hours',
    'is_active',
])]
class Shift extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grace_period_minutes' => 'integer',
            'auto_close_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<UserShiftSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(UserShiftSchedule::class, 'shift_id');
    }

    /** @return HasMany<AttendanceLog, $this> */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'shift_id');
    }
}
