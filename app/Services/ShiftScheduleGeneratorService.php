<?php

namespace App\Services;

use App\Enums\ScheduleSource;
use App\Models\ShiftPattern;
use App\Models\User;
use App\Models\UserShiftSchedule;
use Carbon\CarbonImmutable;

class ShiftScheduleGeneratorService
{
    public const int HORIZON_DAYS = 90;

    public function generateForUser(User $user, ?CarbonImmutable $fromDate = null, int $days = self::HORIZON_DAYS): int
    {
        if ($user->default_shift_pattern_id === null) {
            return 0;
        }

        $pattern = ShiftPattern::query()->find($user->default_shift_pattern_id);
        if ($pattern === null) {
            return 0;
        }

        $timezone = $user->officeLocation->timezone ?? 'Asia/Jakarta';
        $start = ($fromDate ?? CarbonImmutable::now($timezone))->startOfDay();
        $createdOrUpdated = 0;

        for ($i = 0; $i <= $days; $i++) {
            $date = $start->addDays($i);
            $dateStr = $date->toDateString();

            $existing = UserShiftSchedule::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', $dateStr)
                ->first();

            if ($existing !== null && $existing->source === ScheduleSource::MANUAL_OVERRIDE) {
                continue;
            }

            $shiftId = $this->shiftForIsoDay($pattern, $date->dayOfWeekIso);

            if ($existing !== null) {
                $existing->update([
                    'shift_id' => $shiftId,
                    'is_day_off' => $shiftId === null,
                    'source' => ScheduleSource::PATTERN,
                ]);
            } else {
                UserShiftSchedule::query()->create([
                    'user_id' => $user->id,
                    'work_date' => $dateStr,
                    'shift_id' => $shiftId,
                    'is_day_off' => $shiftId === null,
                    'source' => ScheduleSource::PATTERN,
                    'created_by' => $user->id,
                ]);
            }

            $createdOrUpdated++;
        }

        return $createdOrUpdated;
    }

    private function shiftForIsoDay(ShiftPattern $pattern, int $isoDay): ?int
    {
        return match ($isoDay) {
            1 => $pattern->mon_shift_id,
            2 => $pattern->tue_shift_id,
            3 => $pattern->wed_shift_id,
            4 => $pattern->thu_shift_id,
            5 => $pattern->fri_shift_id,
            6 => $pattern->sat_shift_id,
            7 => $pattern->sun_shift_id,
            default => null,
        };
    }
}
