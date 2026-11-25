<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nik
 * @property string $email
 * @property string $name
 * @property string $password
 * @property bool $must_change_password
 * @property UserRole $role
 * @property int $office_location_id
 * @property int|null $default_shift_pattern_id
 * @property Carbon $join_date
 * @property string|null $reference_photo_url
 * @property Carbon|null $consent_accepted_at
 * @property string|null $organization
 * @property string|null $job_position
 * @property string|null $job_level
 * @property string|null $employment_status
 * @property bool $is_active
 * @property bool $can_view_audit_logs
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'nik',
    'email',
    'name',
    'password',
    'must_change_password',
    'role',
    'office_location_id',
    'default_shift_pattern_id',
    'join_date',
    'reference_photo_url',
    'consent_accepted_at',
    'organization',
    'job_position',
    'job_level',
    'employment_status',
    'is_active',
    'can_view_audit_logs',
    'deactivated_at',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'can_view_audit_logs' => 'boolean',
            'deactivated_at' => 'datetime',
            'join_date' => 'date',
            'consent_accepted_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function officeLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'office_location_id');
    }

    /** @return BelongsTo<ShiftPattern, $this> */
    public function defaultShiftPattern(): BelongsTo
    {
        return $this->belongsTo(ShiftPattern::class, 'default_shift_pattern_id');
    }

    /** @return HasMany<UserShiftSchedule, $this> */
    public function shiftSchedules(): HasMany
    {
        return $this->hasMany(UserShiftSchedule::class, 'user_id');
    }

    /** @return HasMany<AttendanceLog, $this> */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'user_id');
    }

    /** @return HasMany<SalesVisit, $this> */
    public function salesVisits(): HasMany
    {
        return $this->hasMany(SalesVisit::class, 'user_id');
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'user_id');
    }

    /** @return HasMany<LeaveBalance, $this> */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'user_id');
    }
}
