<?php

namespace App\Models;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $code
 * @property string $name
 * @property LocationType $type
 * @property string $address
 * @property string $latitude
 * @property string $longitude
 * @property int $radius_m
 * @property string $timezone
 * @property array<int, int> $work_days
 * @property LocationStatus $status
 * @property string|null $reject_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'code',
    'name',
    'type',
    'address',
    'latitude',
    'longitude',
    'radius_m',
    'timezone',
    'work_days',
    'status',
    'reject_reason',
    'created_by',
])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'status' => LocationStatus::class,
            'work_days' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_m' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'office_location_id');
    }

    /** @return HasMany<AttendanceLog, $this> */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'location_id');
    }

    /** @return HasMany<SalesVisit, $this> */
    public function salesVisits(): HasMany
    {
        return $this->hasMany(SalesVisit::class, 'outlet_location_id');
    }

    /** @return HasMany<Holiday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class, 'location_id');
    }
}
