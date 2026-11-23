<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'mon_shift_id',
    'tue_shift_id',
    'wed_shift_id',
    'thu_shift_id',
    'fri_shift_id',
    'sat_shift_id',
    'sun_shift_id',
    'is_active',
])]
class ShiftPattern extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function monShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'mon_shift_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function tueShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'tue_shift_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function wedShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'wed_shift_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function thuShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'thu_shift_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function friShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'fri_shift_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function satShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'sat_shift_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function sunShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'sun_shift_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'default_shift_pattern_id');
    }
}
