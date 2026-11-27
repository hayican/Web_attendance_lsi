<?php

namespace App\Models;

use App\Enums\HolidayType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $holiday_date
 * @property int|null $location_id
 * @property int $location_scope_id
 * @property string $description
 * @property HolidayType $holiday_type
 * @property-read Location|null $location
 */
#[Fillable([
    'holiday_date',
    'location_id',
    'location_scope_id',
    'description',
    'holiday_type',
])]
class Holiday extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'holiday_type' => HolidayType::class,
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
