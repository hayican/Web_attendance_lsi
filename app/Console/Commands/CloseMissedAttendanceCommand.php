<?php

namespace App\Console\Commands;

use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Models\AttendanceLog;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Services\ScheduleResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CloseMissedAttendanceCommand extends Command
{
    protected $signature = 'attendance:close-missed';

    protected $description = 'Close missed office clock-outs and stale sales visits (idempotent catch-up)';

    public function handle(ScheduleResolverService $scheduleResolver): int
    {
        $nowUtc = CarbonImmutable::now('UTC');
        $closedLogs = 0;
        $closedVisits = 0;

        $openLogs = AttendanceLog::query()
            ->with(['location', 'shift', 'leaveRequest'])
            ->where('is_active_attempt', 1)
            ->whereNull('clock_out_at')
            ->get();

        foreach ($openLogs as $log) {
            $location = $log->location;
            $timezone = $location->timezone;
            $workDate = $log->work_date->toDateString();
            $nowLocal = $nowUtc->setTimezone($timezone);

            /** @var Shift|null $shift */
            $shift = $log->shift;

            $effectiveEnd = $log->schedule_end_time !== null
                ? CarbonImmutable::parse("{$workDate} {$log->schedule_end_time}", $timezone)
                : $scheduleResolver->effectiveEndTime($workDate, $timezone, $shift, $log->leaveRequest);
            // @phpstan-ignore nullsafe.neverNull (Larastan treats the nullable shift relation as non-null)
            $autoCloseHours = $log->auto_close_hours ?? $log->shift?->auto_close_hours ?? 5;

            $endOfDay = CarbonImmutable::parse("{$workDate} 23:59:00", $timezone);
            $deadline = $effectiveEnd !== null
                ? $effectiveEnd->addHours($autoCloseHours)
                : $endOfDay;
            $deadline = $deadline->greaterThan($endOfDay) ? $endOfDay : $deadline;

            if ($nowLocal->greaterThanOrEqualTo($deadline)) {
                $flags = $log->clock_out_anomaly_flags ?? [];
                if (! in_array('MISSED_CLOCK_OUT', $flags, true)) {
                    $flags[] = 'MISSED_CLOCK_OUT';
                }

                $log->update([
                    'clock_out_status' => VerificationStatus::PENDING_REVIEW,
                    'clock_out_anomaly_flags' => $flags,
                ]);
                $closedLogs++;
            }
        }

        $openVisits = SalesVisit::query()
            ->with(['user.officeLocation'])
            ->where('visit_status', VisitStatus::IN_PROGRESS)
            ->get();

        foreach ($openVisits as $visit) {
            $timezone = $visit->user->officeLocation->timezone ?? 'Asia/Jakarta';
            $visitDate = $visit->visit_date->toDateString();
            $nowLocal = $nowUtc->setTimezone($timezone);
            $midnightClose = CarbonImmutable::parse("{$visitDate} 23:59:00", $timezone);

            if ($nowLocal->greaterThanOrEqualTo($midnightClose)) {
                $flags = $visit->anomaly_flags ?? [];
                if (! in_array('MISSED_VISIT_OUT', $flags, true)) {
                    $flags[] = 'MISSED_VISIT_OUT';
                }

                $visit->update([
                    'visit_status' => VisitStatus::AUTO_CLOSED,
                    'verification_status' => VerificationStatus::PENDING_REVIEW,
                    'anomaly_flags' => $flags,
                ]);
                $closedVisits++;
            }
        }

        $this->info("Closed {$closedLogs} missed clock-outs and {$closedVisits} stale sales visits.");

        return self::SUCCESS;
    }
}
