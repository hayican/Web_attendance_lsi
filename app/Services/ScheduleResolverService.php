<?php

namespace App\Services;

use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\ScheduleSource;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserShiftSchedule;
use Carbon\CarbonImmutable;

class ScheduleResolverService
{
    public function resolveOrCreateSchedule(User $user, string $workDate): ?UserShiftSchedule
    {
        $existing = UserShiftSchedule::query()
            ->with('shift')
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($user->default_shift_pattern_id === null) {
            return null;
        }

        $pattern = $user->defaultShiftPattern;
        if ($pattern === null) {
            return null;
        }

        $dayOfWeek = CarbonImmutable::parse($workDate)->dayOfWeekIso;
        $shiftId = match ($dayOfWeek) {
            1 => $pattern->mon_shift_id,
            2 => $pattern->tue_shift_id,
            3 => $pattern->wed_shift_id,
            4 => $pattern->thu_shift_id,
            5 => $pattern->fri_shift_id,
            6 => $pattern->sat_shift_id,
            7 => $pattern->sun_shift_id,
            default => null,
        };

        $created = UserShiftSchedule::query()->create([
            'user_id' => $user->id,
            'work_date' => $workDate,
            'shift_id' => $shiftId,
            'is_day_off' => $shiftId === null,
            'source' => ScheduleSource::PATTERN,
            'created_by' => $user->id,
        ]);

        return $created->load('shift');
    }

    public function isHoliday(string $workDate, int $locationId): bool
    {
        return Holiday::query()
            ->whereDate('holiday_date', $workDate)
            ->where(function ($query) use ($locationId): void {
                $query->whereNull('location_id')
                    ->orWhere('location_id', $locationId);
            })
            ->exists();
    }

    public function hasApprovedTimeOff(int $userId, string $workDate): bool
    {
        return LeaveRequestDay::query()
            ->where('user_id', $userId)
            ->whereDate('leave_date', $workDate)
            ->where('status', LeaveDayStatus::APPROVED)
            ->whereHas('leaveRequest', function ($query): void {
                $query->whereIn('type', [
                    LeaveType::CUTI,
                    LeaveType::IZIN,
                    LeaveType::SAKIT,
                ]);
            })
            ->exists();
    }

    public function dinasLuarForDate(int $userId, string $workDate): ?LeaveRequest
    {
        /** @var LeaveRequest|null $leave */
        $leave = LeaveRequest::query()
            ->where('user_id', $userId)
            ->where('type', LeaveType::DINAS_LUAR)
            ->whereIn('status', [LeaveStatus::APPROVED, LeaveStatus::PARTIALLY_CANCELLED, LeaveStatus::PENDING])
            ->whereHas('days', function ($query) use ($workDate): void {
                $query->whereDate('leave_date', $workDate)
                    ->whereIn('status', [LeaveDayStatus::APPROVED, LeaveDayStatus::PENDING]);
            })
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [LeaveStatus::PENDING->value])
            ->first();

        return $leave;
    }

    public function effectiveStartTime(string $workDate, string $timezone, ?Shift $shift, ?LeaveRequest $dinasLuar): ?CarbonImmutable
    {
        if ($dinasLuar !== null && $dinasLuar->scheduled_start_time !== null) {
            return CarbonImmutable::parse("{$workDate} {$dinasLuar->scheduled_start_time}", $timezone);
        }

        if ($shift !== null) {
            return CarbonImmutable::parse("{$workDate} {$shift->start_time}", $timezone);
        }

        return null;
    }

    public function effectiveEndTime(string $workDate, string $timezone, ?Shift $shift, ?LeaveRequest $dinasLuar): ?CarbonImmutable
    {
        $shiftStart = $shift !== null ? CarbonImmutable::parse("{$workDate} {$shift->start_time}", $timezone) : null;
        $shiftEnd = $shift !== null ? CarbonImmutable::parse("{$workDate} {$shift->end_time}", $timezone) : null;

        if ($dinasLuar !== null && $dinasLuar->scheduled_start_time !== null) {
            $dinasStart = CarbonImmutable::parse("{$workDate} {$dinasLuar->scheduled_start_time}", $timezone);
            $durationSeconds = ($shiftStart !== null && $shiftEnd !== null)
                ? max(3600, (int) $shiftStart->diffInSeconds($shiftEnd))
                : 8 * 3600;
            $dinasEnd = $dinasStart->addSeconds($durationSeconds);

            if ($shiftEnd !== null) {
                return $dinasEnd->greaterThan($shiftEnd) ? $dinasEnd : $shiftEnd;
            }

            return $dinasEnd;
        }

        return $shiftEnd;
    }
}
