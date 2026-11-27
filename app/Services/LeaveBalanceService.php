<?php

namespace App\Services;

use App\Enums\HolidayType;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\UserShiftSchedule;
use Carbon\CarbonImmutable;

class LeaveBalanceService
{
    public function __construct(private readonly ScheduleResolverService $scheduleResolver) {}

    public function anniversaryDate(CarbonImmutable $joinDate, int $targetYear): CarbonImmutable
    {
        if ($joinDate->month === 2 && $joinDate->day === 29 && ! CarbonImmutable::create($targetYear, 1, 1)->isLeapYear()) {
            return CarbonImmutable::create($targetYear, 2, 28);
        }

        return CarbonImmutable::create($targetYear, $joinDate->month, $joinDate->day);
    }

    public function oneYearAnniversary(CarbonImmutable $joinDate): CarbonImmutable
    {
        return $this->anniversaryDate($joinDate, $joinDate->year + 1);
    }

    public function isEligibleForAnnualLeave(User $user, CarbonImmutable $asOf): bool
    {
        $joinDate = CarbonImmutable::parse($user->join_date->toDateString());
        $oneYearDate = $this->oneYearAnniversary($joinDate);

        return $asOf->toDateString() >= $oneYearDate->toDateString();
    }

    /**
     * @return array{base_quota: float, prorated_deduction: float}
     */
    public function calculateAnnualQuotaAndProration(User $user, int $year, ?CarbonImmutable $asOf = null): array
    {
        $joinDate = CarbonImmutable::parse($user->join_date->toDateString());
        $oneYearDate = $this->oneYearAnniversary($joinDate);
        $referenceDate = $asOf ?? CarbonImmutable::create($year, 12, 31);

        if ($referenceDate->toDateString() < $oneYearDate->toDateString()) {
            return ['base_quota' => 0.0, 'prorated_deduction' => 0.0];
        }

        if ($oneYearDate->year < $year) {
            return ['base_quota' => 12.0, 'prorated_deduction' => 0.0];
        }

        // Anniversary falls in $year
        $deduction = $oneYearDate->day <= 15
            ? (float) ($oneYearDate->month - 1)
            : (float) $oneYearDate->month;

        return [
            'base_quota' => 12.0,
            'prorated_deduction' => min(12.0, max(0.0, $deduction)),
        ];
    }

    public function ensureBalanceForYear(User $user, int $year, ?CarbonImmutable $asOf = null): LeaveBalance
    {
        $existing = LeaveBalance::query()
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->first();

        $quota = $this->calculateAnnualQuotaAndProration($user, $year, $asOf);
        $collectiveDeduction = $quota['base_quota'] > 0.0
            ? $this->countCollectiveLeaveForUser($user, $year)
            : 0.0;

        if ($existing !== null) {
            $existing->update([
                'base_quota' => $quota['base_quota'],
                'prorated_deduction' => $quota['prorated_deduction'],
                'collective_leave_deduction' => $collectiveDeduction,
            ]);

            return $existing;
        }

        return LeaveBalance::query()->create([
            'user_id' => $user->id,
            'year' => $year,
            'base_quota' => $quota['base_quota'],
            'prorated_deduction' => $quota['prorated_deduction'],
            'collective_leave_deduction' => $collectiveDeduction,
            'used_days' => 0.0,
            'pending_days' => 0.0,
            'adjustment_days' => 0.0,
        ]);
    }

    public function countCollectiveLeaveForUser(User $user, int $year): float
    {
        $joinDate = CarbonImmutable::parse($user->join_date->toDateString());
        $oneYearDate = $this->oneYearAnniversary($joinDate);

        $holidays = Holiday::query()
            ->where('holiday_type', HolidayType::COLLECTIVE_LEAVE)
            ->whereYear('holiday_date', $year)
            ->get();

        $count = 0.0;
        foreach ($holidays as $holiday) {
            $hDate = CarbonImmutable::parse($holiday->holiday_date->toDateString());
            if ($hDate->greaterThanOrEqualTo($oneYearDate)) {
                $count += 1.0;
            }
        }

        return $count;
    }

    /**
     * @return list<array{balance: LeaveBalance, old: float, new: float}>
     */
    public function syncCollectiveLeaveForYear(int $year, ?User $only = null): array
    {
        $today = CarbonImmutable::now('Asia/Jakarta');
        $asOf = $year <= $today->year ? $today : null;
        $users = $only !== null
            ? User::query()->whereKey($only->id)->get()
            : User::query()->where('is_active', true)->get();
        $changes = [];

        foreach ($users as $user) {
            $balance = LeaveBalance::query()
                ->where('user_id', $user->id)
                ->where('year', $year)
                ->first();

            if ($balance === null) {
                $created = $this->ensureBalanceForYear($user, $year, $asOf);
                if ((float) $created->collective_leave_deduction > 0.0) {
                    $changes[] = [
                        'balance' => $created,
                        'old' => 0.0,
                        'new' => (float) $created->collective_leave_deduction,
                    ];
                }

                continue;
            }

            $oldDeduction = (float) $balance->collective_leave_deduction;
            $newDeduction = ($asOf === null || $this->isEligibleForAnnualLeave($user, $asOf))
                ? $this->countCollectiveLeaveForUser($user, $year)
                : 0.0;

            if ($oldDeduction !== $newDeduction) {
                $balance->update([
                    'collective_leave_deduction' => $newDeduction,
                ]);
                $changes[] = [
                    'balance' => $balance,
                    'old' => $oldDeduction,
                    'new' => $newDeduction,
                ];
            }
        }

        return $changes;
    }

    public function isEffectiveWorkDay(User $user, string $date): bool
    {
        $isHoliday = $this->scheduleResolver->isHoliday($date, $user->office_location_id);
        $schedule = $user->role === UserRole::EMPLOYEE_SALES
            ? $user->shiftSchedules()->whereDate('work_date', $date)->first()
            : $this->scheduleResolver->resolveOrCreateSchedule($user, $date);

        return $this->isEffectiveWorkDayFrom($user, $date, $isHoliday, $schedule);
    }

    public function isEffectiveWorkDayFrom(
        User $user,
        string $date,
        bool $isHoliday,
        ?UserShiftSchedule $schedule,
    ): bool {
        if ($isHoliday) {
            return false;
        }

        $dayIso = CarbonImmutable::parse($date)->dayOfWeekIso;
        $workDays = $user->officeLocation->work_days;

        if ($user->role === UserRole::EMPLOYEE_SALES) {
            $manualDayOff = $schedule !== null
                && $schedule->is_day_off
                && $schedule->source === ScheduleSource::MANUAL_OVERRIDE;

            return ! $manualDayOff && in_array($dayIso, $workDays, true);
        }

        return $schedule !== null
            ? ! $schedule->is_day_off
            : in_array($dayIso, $workDays, true);
    }

    /**
     * Recalculate effective day values and leave balances for PENDING/APPROVED requests from $fromDate onward.
     *
     * @return list<int> IDs of requests whose total_days changed
     */
    public function recalculateLeaveRequestDays(string $fromDate, ?int $onlyUserId = null): array
    {
        $requests = LeaveRequest::query()
            ->with(['user.officeLocation', 'days'])
            ->whereIn('status', [LeaveStatus::PENDING, LeaveStatus::APPROVED, LeaveStatus::PARTIALLY_CANCELLED])
            ->whereDate('end_date', '>=', $fromDate)
            ->when($onlyUserId !== null, fn ($q) => $q->where('user_id', $onlyUserId))
            ->get();

        $changedIds = [];

        foreach ($requests as $leaveRequest) {
            $user = $leaveRequest->user;
            $changed = false;

            foreach ($leaveRequest->days as $day) {
                if (! in_array($day->status, [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED], true)) {
                    continue;
                }

                $dateStr = $day->leave_date->toDateString();
                if ($dateStr < $fromDate) {
                    continue;
                }

                $newVal = $this->isEffectiveWorkDay($user, $dateStr) ? 1.0 : 0.0;
                $oldVal = (float) $day->day_value;

                if ($newVal !== $oldVal) {
                    $delta = $newVal - $oldVal;
                    $day->update(['day_value' => $newVal]);
                    $changed = true;

                    if ($leaveRequest->type === LeaveType::CUTI) {
                        $balance = $this->ensureBalanceForYear($user, (int) $day->leave_date->year);
                        if ($day->status === LeaveDayStatus::PENDING) {
                            $balance->update(['pending_days' => max(0.0, (float) $balance->pending_days + $delta)]);
                        } else {
                            $balance->update(['used_days' => max(0.0, (float) $balance->used_days + $delta)]);
                        }
                    }
                }
            }

            if ($changed) {
                $newTotal = (float) $leaveRequest->days()
                    ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
                    ->sum('day_value');

                $updates = ['total_days' => $newTotal];
                if ($newTotal <= 0.0) {
                    $updates['status'] = LeaveStatus::CANCELLED;
                    $leaveRequest->days()
                        ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
                        ->update(['status' => LeaveDayStatus::CANCELLED]);
                }

                $leaveRequest->update($updates);
                $changedIds[] = $leaveRequest->id;
            }
        }

        return $changedIds;
    }
}
