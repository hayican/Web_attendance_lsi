<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'outlet_location_id',
    'source',
    'visit_date',
    'visit_in_at',
    'visit_in_photo_url',
    'visit_in_lat',
    'visit_in_lng',
    'visit_in_accuracy_m',
    'visit_in_distance_m',
    'visit_in_device_id',
    'visit_in_ip',
    'visit_in_user_agent',
    'visit_in_notes',
    'cancel_reason',
    'visit_in_idempotency_key',
    'visit_out_at',
    'duration_minutes',
    'activity_category_id',
    'visit_notes',
    'visit_out_lat',
    'visit_out_lng',
    'visit_out_accuracy_m',
    'visit_out_distance_m',
    'visit_out_device_id',
    'visit_out_ip',
    'visit_out_user_agent',
    'visit_out_idempotency_key',
    'visit_status',
    'verification_status',
    'anomaly_flags',
    'reviewed_by',
    'reviewed_at',
    'reject_reason',
])]
class SalesVisit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'visit_date' => 'date',
            'visit_in_at' => 'datetime',
            'visit_in_lat' => 'decimal:7',
            'visit_in_lng' => 'decimal:7',
            'visit_in_accuracy_m' => 'decimal:2',
            'visit_in_distance_m' => 'decimal:2',
            'visit_out_at' => 'datetime',
            'duration_minutes' => 'integer',
            'visit_out_lat' => 'decimal:7',
            'visit_out_lng' => 'decimal:7',
            'visit_out_accuracy_m' => 'decimal:2',
            'visit_out_distance_m' => 'decimal:2',
            'visit_status' => VisitStatus::class,
            'verification_status' => VerificationStatus::class,
            'anomaly_flags' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function outletLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'outlet_location_id');
    }

    /** @return BelongsTo<ActivityCategory, $this> */
    public function activityCategory(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'activity_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
