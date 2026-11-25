<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $location_id
 * @property int|null $shift_id
 * @property int|null $leave_request_id
 * @property string|null $schedule_start_time
 * @property string|null $schedule_end_time
 * @property int|null $grace_period_minutes
 * @property int|null $auto_close_hours
 * @property string|null $office_lat
 * @property string|null $office_lng
 * @property int|null $office_radius_m
 * @property AttendanceSource $source
 * @property Carbon $work_date
 * @property int $attempt_no
 * @property int|null $is_active_attempt
 * @property Carbon|null $clock_in_at
 * @property string|null $clock_in_photo_url
 * @property string|null $clock_in_lat
 * @property string|null $clock_in_lng
 * @property string|null $clock_in_accuracy_m
 * @property string|null $clock_in_distance_m
 * @property string|null $clock_in_device_id
 * @property Carbon|null $clock_out_at
 * @property string|null $clock_out_photo_url
 * @property string|null $clock_out_lat
 * @property string|null $clock_out_lng
 * @property string|null $clock_out_accuracy_m
 * @property string|null $clock_out_distance_m
 * @property string|null $clock_out_device_id
 * @property int $late_minutes
 * @property int $early_leave_minutes
 * @property VerificationStatus $clock_in_status
 * @property list<string>|null $clock_in_anomaly_flags
 * @property VerificationStatus|null $clock_out_status
 * @property list<string>|null $clock_out_anomaly_flags
 * @property-read Location $location
 * @property-read Shift|null $shift
 * @property-read LeaveRequest|null $leaveRequest
 */
#[Fillable([
    'user_id',
    'location_id',
    'shift_id',
    'leave_request_id',
    'schedule_start_time',
    'schedule_end_time',
    'grace_period_minutes',
    'auto_close_hours',
    'office_lat',
    'office_lng',
    'office_radius_m',
    'source',
    'work_date',
    'attempt_no',
    'is_active_attempt',
    'clock_in_at',
    'clock_in_photo_url',
    'clock_in_lat',
    'clock_in_lng',
    'clock_in_accuracy_m',
    'clock_in_distance_m',
    'clock_in_device_id',
    'clock_in_ip',
    'clock_in_user_agent',
    'clock_in_notes',
    'clock_in_idempotency_key',
    'late_minutes',
    'clock_out_at',
    'clock_out_photo_url',
    'clock_out_lat',
    'clock_out_lng',
    'clock_out_accuracy_m',
    'clock_out_distance_m',
    'clock_out_device_id',
    'clock_out_ip',
    'clock_out_user_agent',
    'clock_out_notes',
    'clock_out_idempotency_key',
    'early_leave_minutes',
    'clock_in_status',
    'clock_in_anomaly_flags',
    'clock_in_reviewed_by',
    'clock_in_reviewed_at',
    'clock_in_reject_reason',
    'clock_out_status',
    'clock_out_anomaly_flags',
    'clock_out_reviewed_by',
    'clock_out_reviewed_at',
    'clock_out_reject_reason',
])]
class AttendanceLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'work_date' => 'date',
            'attempt_no' => 'integer',
            'is_active_attempt' => 'integer',
            'grace_period_minutes' => 'integer',
            'auto_close_hours' => 'integer',
            'office_lat' => 'decimal:7',
            'office_lng' => 'decimal:7',
            'office_radius_m' => 'integer',
            'clock_in_at' => 'datetime',
            'clock_in_lat' => 'decimal:7',
            'clock_in_lng' => 'decimal:7',
            'clock_in_accuracy_m' => 'decimal:2',
            'clock_in_distance_m' => 'decimal:2',
            'late_minutes' => 'integer',
            'clock_out_at' => 'datetime',
            'clock_out_lat' => 'decimal:7',
            'clock_out_lng' => 'decimal:7',
            'clock_out_accuracy_m' => 'decimal:2',
            'clock_out_distance_m' => 'decimal:2',
            'early_leave_minutes' => 'integer',
            'clock_in_status' => VerificationStatus::class,
            'clock_in_anomaly_flags' => 'array',
            'clock_in_reviewed_at' => 'datetime',
            'clock_out_status' => VerificationStatus::class,
            'clock_out_anomaly_flags' => 'array',
            'clock_out_reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function clockInReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clock_in_reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function clockOutReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clock_out_reviewed_by');
    }
}
