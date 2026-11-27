<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Exports\TabularReportExport;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Models\TalentaMapping;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\AuditLogger;
use App\Services\CsvSanitizer;
use App\Services\LeaveBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CsvSanitizer $sanitizer,
        private readonly LeaveBalanceService $balanceService,
    ) {}

    public function talentaOffice(Request $request): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        [$start, $end, $actor, $format] = $this->validateExportRange($request);

        if (! $request->boolean('confirm_pending')) {
            $pendingCount = LeaveRequest::query()
                ->where('status', LeaveStatus::PENDING)
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->count();

            $pendingAttendanceCount = AttendanceLog::query()
                ->whereDate('work_date', '>=', $start->toDateString())
                ->whereDate('work_date', '<=', $end->toDateString())
                ->where(function ($query): void {
                    $query->where('clock_in_status', VerificationStatus::PENDING_REVIEW)
                        ->orWhere('clock_out_status', VerificationStatus::PENDING_REVIEW);
                })
                ->count();

            if ($pendingCount > 0 || $pendingAttendanceCount > 0) {
                $parts = [];
                if ($pendingCount > 0) {
                    $parts[] = "{$pendingCount} pengajuan Cuti/Izin/Sakit PENDING";
                }
                if ($pendingAttendanceCount > 0) {
                    $parts[] = "{$pendingAttendanceCount} absensi PENDING_REVIEW";
                }

                return response()->json([
                    'requires_pending_confirmation' => true,
                    'pending_count' => $pendingCount,
                    'pending_attendance_count' => $pendingAttendanceCount,
                    'message' => 'Masih ada '.implode(' dan ', $parts).' di periode ini. Data terkait akan diekspor sesuai status saat ini. Tetap export?',
                ], 409);
            }
        }

        $mappings = TalentaMapping::query()->pluck('mapping_value', 'mapping_key');
        $presentCode = (string) ($mappings['attendance_present'] ?? 'H');
        $absentCode = (string) ($mappings['attendance_absent'] ?? 'A');
        $useOffsiteSchedule = (bool) filter_var($mappings['use_offsite_schedule_for_talenta'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $exportOffdayOvertime = (bool) filter_var($mappings['export_offday_overtime_to_talenta'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        /** @var Collection<string, Collection<int, Holiday>> $holidaysByDate */
        $holidaysByDate = Holiday::query()
            ->whereDate('holiday_date', '>=', $start->toDateString())
            ->whereDate('holiday_date', '<=', $end->toDateString())
            ->get(['holiday_date', 'location_id', 'holiday_type'])
            ->groupBy(fn (Holiday $holiday): string => $holiday->holiday_date->toDateString());

        $officeUsers = User::query()
            ->with(['officeLocation'])
            ->whereIn('role', [UserRole::EMPLOYEE_OFFICE, UserRole::HR_ADMIN])
            ->orderBy('nik')
            ->get();

        $officeUserIds = $officeUsers->pluck('id');
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $schedulesByUserDate = UserShiftSchedule::query()
            ->with('shift')
            ->whereIn('user_id', $officeUserIds)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get()
            ->keyBy(fn (UserShiftSchedule $schedule): string => $schedule->user_id.'|'.$schedule->work_date->toDateString());

        $leaveDaysByUserDate = LeaveRequestDay::query()
            ->with('leaveRequest:id,type,scheduled_start_time')
            ->whereIn('user_id', $officeUserIds)
            ->whereDate('leave_date', '>=', $startDate)
            ->whereDate('leave_date', '<=', $endDate)
            ->where('status', LeaveDayStatus::APPROVED)
            ->get()
            ->keyBy(fn (LeaveRequestDay $day): string => $day->user_id.'|'.$day->leave_date->toDateString());

        $logsByUserDate = AttendanceLog::query()
            ->with('shift:id,code,start_time,end_time')
            ->whereIn('user_id', $officeUserIds)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get()
            ->groupBy(fn (AttendanceLog $log): string => $log->user_id.'|'.$log->work_date->toDateString());

        $usedReplacementOrigins = UserShiftSchedule::query()
            ->whereIn('user_id', $officeUserIds)
            ->whereNotNull('compensation_source_date')
            ->get(['user_id', 'compensation_source_date'])
            ->mapWithKeys(fn (UserShiftSchedule $schedule): array => [
                $schedule->user_id.'|'.$schedule->compensation_source_date->toDateString() => true,
            ]);

        $headers = [
            'Employee ID*', 'Full Name', 'Date*', 'Shift', 'Schedule In', 'Schedule Out',
            'Attendance Code', 'Check In', 'Check Out', 'Overtime Check In', 'Overtime Check Out',
            'Overtime Before', 'Overtime After', 'Overtime Break Before', 'Overtime Break After',
            'Time Off Code', 'Time Off Halfday', 'Time Off Schedule In', 'Time Off Schedule Out',
            'Branch', 'Organization', 'Job Position', 'Job Level', 'Employment Status',
            'Join Date', 'Lock Status',
        ];

        $rows = [];
        foreach ($officeUsers as $user) {
            $tz = $user->officeLocation->timezone;
            $todayLocal = CarbonImmutable::now('UTC')->setTimezone($tz)->toDateString();
            $cursor = $start;

            while ($cursor->lessThanOrEqualTo($end)) {
                $dateStr = $cursor->toDateString();

                // PRD v8: employment window decides coverage, not the current is_active flag.
                if ($dateStr < $user->join_date->toDateString()
                    || ($user->deactivated_at !== null && $dateStr > $user->deactivated_at->toDateString())) {
                    $cursor = $cursor->addDay();

                    continue;
                }

                $key = $user->id.'|'.$dateStr;
                $schedule = $schedulesByUserDate->get($key);
                $shift = $schedule?->shift;
                $dayHolidays = ($holidaysByDate->get($dateStr) ?? Collection::empty())
                    ->filter(fn (Holiday $holiday): bool => $holiday->location_id === null || $holiday->location_id === $user->office_location_id);
                $isHoliday = $dayHolidays->isNotEmpty();
                $isCollectiveLeave = $dayHolidays->contains(
                    fn (Holiday $holiday): bool => $holiday->holiday_type === HolidayType::COLLECTIVE_LEAVE
                );
                $isDayOff = ! $this->balanceService->isEffectiveWorkDayFrom($user, $dateStr, $isHoliday, $schedule) || $shift === null;

                $approvedLeave = $leaveDaysByUserDate->get($key);
                $dinasLeave = $approvedLeave?->leaveRequest?->type === LeaveType::DINAS_LUAR
                    ? $approvedLeave->leaveRequest
                    : null;

                $dayLogs = $logsByUserDate->get($key, Collection::empty());
                $log = $dayLogs->firstWhere('is_active_attempt', 1) ?? $dayLogs->sortByDesc('attempt_no')->first();

                $logShift = $log?->shift;
                $shiftCode = $isDayOff ? 'dayoff' : ($logShift !== null ? $logShift->code : $shift->code);
                $schedIn = $isDayOff ? '00:00' : substr($logShift !== null ? $logShift->start_time : $shift->start_time, 0, 5);
                $schedOut = $isDayOff ? '00:00' : substr($logShift !== null ? $logShift->end_time : $shift->end_time, 0, 5);

                if (! $isDayOff && $useOffsiteSchedule && $dinasLeave?->scheduled_start_time !== null) {
                    $shiftStart = CarbonImmutable::parse("{$dateStr} {$shift->start_time}", $tz);
                    $shiftEnd = CarbonImmutable::parse("{$dateStr} {$shift->end_time}", $tz);
                    $dinasStart = CarbonImmutable::parse("{$dateStr} {$dinasLeave->scheduled_start_time}", $tz);
                    $dinasEnd = $dinasStart->addSeconds(max(3600, (int) $shiftStart->diffInSeconds($shiftEnd)));

                    if ($dinasEnd->lessThan($shiftEnd)) {
                        $dinasEnd = $shiftEnd;
                    }

                    $schedIn = $dinasStart->format('H:i');
                    $schedOut = $dinasEnd->format('H:i');
                }

                $attCode = '';
                $checkIn = '';
                $checkOut = '';
                $overtimeIn = '';
                $overtimeOut = '';
                $timeOffCode = '';

                if ($approvedLeave !== null && $approvedLeave->leaveRequest !== null && $approvedLeave->leaveRequest->type !== LeaveType::DINAS_LUAR) {
                    $timeOffCode = match ($approvedLeave->leaveRequest->type) {
                        LeaveType::CUTI => (string) ($mappings['timeoff_annual'] ?? 'CT'),
                        LeaveType::IZIN => (string) ($mappings['timeoff_izin'] ?? 'IZ'),
                        LeaveType::SAKIT => (string) ($mappings['timeoff_sakit'] ?? 'SK'),
                    };
                } elseif ($isDayOff) {
                    if ($isCollectiveLeave) {
                        if ($log !== null && $log->clock_in_status !== VerificationStatus::REJECTED) {
                            $attCode = $presentCode;
                            $checkIn = $log->clock_in_at?->setTimezone($tz)->format('H:i') ?? '';
                            $checkOut = $log->clock_out_status === VerificationStatus::REJECTED
                                ? ''
                                : ($log->clock_out_at?->setTimezone($tz)->format('H:i') ?? '');
                        } else {
                            $timeOffCode = $this->balanceService->isEligibleForAnnualLeave($user, $cursor)
                                ? (string) ($mappings['timeoff_collective'] ?? 'CB')
                                : (string) ($mappings['timeoff_collective_unpaid'] ?? 'CBU');
                        }
                    } elseif ($exportOffdayOvertime
                        && ! $usedReplacementOrigins->has($key)
                        && $log !== null
                        && in_array($log->clock_in_status, [VerificationStatus::AUTO_APPROVED, VerificationStatus::APPROVED], true)) {
                        $overtimeIn = $log->clock_in_at?->setTimezone($tz)->format('H:i') ?? '';
                        $overtimeOut = in_array($log->clock_out_status, [VerificationStatus::AUTO_APPROVED, VerificationStatus::APPROVED], true)
                            ? ($log->clock_out_at?->setTimezone($tz)->format('H:i') ?? '')
                            : '';
                    }
                } elseif ($log !== null && $log->clock_in_status !== VerificationStatus::REJECTED) {
                    $attCode = $presentCode;
                    $checkIn = $log->clock_in_at?->setTimezone($tz)->format('H:i') ?? '';
                    $checkOut = $log->clock_out_status === VerificationStatus::REJECTED
                        ? ''
                        : ($log->clock_out_at?->setTimezone($tz)->format('H:i') ?? '');
                } elseif ($dateStr < $todayLocal) {
                    $attCode = $absentCode;
                }

                $rows[] = [
                    $user->nik, $user->name, $dateStr, $shiftCode, $schedIn, $schedOut,
                    $attCode, $checkIn, $checkOut, $overtimeIn, $overtimeOut,
                    '', '', '', '',
                    $timeOffCode, '', '', '',
                    $user->officeLocation->name, $user->organization ?? '', $user->job_position ?? '', $user->job_level ?? '', $user->employment_status ?? '',
                    $user->join_date->toDateString(), '0',
                ];

                $cursor = $cursor->addDay();
            }
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'attendance_logs',
            recordId: 0,
            action: AuditAction::EXPORT_ATTENDANCE,
            newValues: ['file' => 'talenta_office', 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'format' => $format],
        );

        return $this->downloadReport('talenta-office-'.$start->toDateString(), 'Sheet1', $headers, $rows, $format);
    }

    public function salesVisits(Request $request): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        [$start, $end, $actor, $format] = $this->validateExportRange($request);
        $includeUnverified = $request->boolean('include_unverified');
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $headers = [
            'Date', 'Check Time', 'Type', 'Employee ID', 'Full Name', 'Job Position',
            'Face recognition', 'Liveness validation', 'Shift Name', 'Shift Code', 'Shift Label',
            'Location Setting Name', 'Location GPS Name', 'Location Address', 'Location Coordinate',
            'Description', 'Mobile Flag', 'Status',
        ];

        $salesUsers = User::query()
            ->with(['officeLocation:id,name,address,timezone,work_days'])
            ->where('role', UserRole::EMPLOYEE_SALES)
            ->whereDate('join_date', '<=', $endDate)
            ->where(function ($query) use ($startDate): void {
                $query->whereNull('deactivated_at')
                    ->orWhereDate('deactivated_at', '>=', $startDate);
            })
            ->orderBy('nik')
            ->get();

        $salesUserIds = $salesUsers->pluck('id');

        $rangeVisits = SalesVisit::query()
            ->with(['user:id,nik,name,job_position', 'outletLocation'])
            ->whereDate('visit_date', '>=', $startDate)
            ->whereDate('visit_date', '<=', $endDate)
            ->orderBy('visit_date')
            ->orderBy('visit_in_at')
            ->get();

        $visitsByUserDate = $rangeVisits->groupBy(
            fn (SalesVisit $visit): string => $visit->user_id.'|'.$visit->visit_date->toDateString()
        );

        $exportedVisits = $rangeVisits
            ->reject(fn (SalesVisit $visit): bool => $visit->visit_status === VisitStatus::CANCELLED)
            ->filter(fn (SalesVisit $visit): bool => $includeUnverified
                || in_array($visit->verification_status, [VerificationStatus::AUTO_APPROVED, VerificationStatus::APPROVED], true));

        $exportedByUserDate = $exportedVisits->groupBy(
            fn (SalesVisit $visit): string => $visit->user_id.'|'.$visit->visit_date->toDateString()
        );
        $exportedByDate = $exportedVisits->groupBy(
            fn (SalesVisit $visit): string => $visit->visit_date->toDateString()
        );

        if (! $request->boolean('confirm_pending')) {
            $pendingLeaveCount = LeaveRequest::query()
                ->where('status', LeaveStatus::PENDING)
                ->whereIn('user_id', $salesUserIds)
                ->whereDate('start_date', '<=', $endDate)
                ->whereDate('end_date', '>=', $startDate)
                ->count();

            $pendingReviewCount = 0;
            $pendingInProgressCount = 0;

            if (! $includeUnverified) {
                foreach ($visitsByUserDate as $groupKey => $dayVisits) {
                    $nonCancelled = $dayVisits->reject(
                        fn (SalesVisit $visit): bool => $visit->visit_status === VisitStatus::CANCELLED
                    );

                    if ($nonCancelled->isEmpty()
                        || $exportedByUserDate->get($groupKey, Collection::empty())->isNotEmpty()) {
                        continue;
                    }

                    $pending = $nonCancelled->filter(fn (SalesVisit $visit): bool => $visit->verification_status === VerificationStatus::PENDING_REVIEW
                        || $visit->visit_status === VisitStatus::IN_PROGRESS);

                    if ($pending->isEmpty()) {
                        continue;
                    }

                    $pendingReviewCount += $pending
                        ->filter(fn (SalesVisit $visit): bool => $visit->verification_status === VerificationStatus::PENDING_REVIEW)
                        ->count();
                    $pendingInProgressCount += $pending
                        ->filter(fn (SalesVisit $visit): bool => $visit->visit_status === VisitStatus::IN_PROGRESS)
                        ->count();
                }
            }

            if ($pendingLeaveCount > 0 || $pendingReviewCount > 0 || $pendingInProgressCount > 0) {
                $parts = [];
                if ($pendingLeaveCount > 0) {
                    $parts[] = "{$pendingLeaveCount} pengajuan Cuti/Izin/Sakit PENDING";
                }
                if ($pendingReviewCount > 0) {
                    $parts[] = "{$pendingReviewCount} kunjungan sales PENDING_REVIEW";
                }
                if ($pendingInProgressCount > 0) {
                    $parts[] = "{$pendingInProgressCount} kunjungan sales IN_PROGRESS";
                }

                return response()->json([
                    'requires_pending_confirmation' => true,
                    'pending_count' => $pendingLeaveCount,
                    'pending_leave_count' => $pendingLeaveCount,
                    'pending_sales_visit_count' => $pendingReviewCount,
                    'pending_sales_in_progress_count' => $pendingInProgressCount,
                    'pending_items' => [
                        'leave' => $pendingLeaveCount,
                        'visit_pending_review' => $pendingReviewCount,
                        'visit_in_progress' => $pendingInProgressCount,
                    ],
                    'message' => 'Masih ada '.implode(' dan ', $parts).' di periode ini. Tanggal terkait dilewati dari baris payroll sampai data ditinjau. Tetap export?',
                ], 409);
            }
        }

        $mappings = TalentaMapping::query()->pluck('mapping_value', 'mapping_key');
        $absentCode = (string) ($mappings['attendance_absent'] ?? 'A');

        $holidaysByDate = Holiday::query()
            ->whereDate('holiday_date', '>=', $startDate)
            ->whereDate('holiday_date', '<=', $endDate)
            ->get(['holiday_date', 'location_id', 'holiday_type'])
            ->groupBy(fn (Holiday $holiday): string => $holiday->holiday_date->toDateString());

        $schedulesByUserDate = UserShiftSchedule::query()
            ->whereIn('user_id', $salesUserIds)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get()
            ->keyBy(fn (UserShiftSchedule $schedule): string => $schedule->user_id.'|'.$schedule->work_date->toDateString());

        $leaveDaysByUserDate = LeaveRequestDay::query()
            ->with('leaveRequest:id,type')
            ->whereIn('user_id', $salesUserIds)
            ->whereDate('leave_date', '>=', $startDate)
            ->whereDate('leave_date', '<=', $endDate)
            ->where('status', LeaveDayStatus::APPROVED)
            ->get()
            ->keyBy(fn (LeaveRequestDay $day): string => $day->user_id.'|'.$day->leave_date->toDateString());

        $todayLocalByUser = [];
        foreach ($salesUsers as $salesUser) {
            $todayLocalByUser[$salesUser->id] = CarbonImmutable::now('UTC')
                ->setTimezone($salesUser->officeLocation->timezone)
                ->toDateString();
        }

        $rows = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $dateStr = $cursor->toDateString();

            foreach ($salesUsers as $user) {
                if ($user->join_date->toDateString() > $dateStr) {
                    continue;
                }

                if ($user->deactivated_at !== null && $user->deactivated_at->toDateString() < $dateStr) {
                    continue;
                }

                $key = $user->id.'|'.$dateStr;
                $dayVisits = $visitsByUserDate->get($key, Collection::empty());
                $nonCancelled = $dayVisits->reject(
                    fn (SalesVisit $visit): bool => $visit->visit_status === VisitStatus::CANCELLED
                );
                $dayExported = $exportedByUserDate->get($key, Collection::empty());

                if ($dayExported->isNotEmpty()
                    && ! $dayExported->every(fn (SalesVisit $visit): bool => $visit->verification_status === VerificationStatus::REJECTED)) {
                    continue;
                }

                if (! $includeUnverified && $nonCancelled->contains(fn (SalesVisit $visit): bool => $visit->verification_status === VerificationStatus::PENDING_REVIEW
                    || $visit->visit_status === VisitStatus::IN_PROGRESS)) {
                    continue;
                }

                $schedule = $schedulesByUserDate->get($key);
                $dayHolidays = $holidaysByDate->get($dateStr, Collection::empty());
                $hasHoliday = $dayHolidays->contains(
                    fn (Holiday $holiday): bool => $holiday->location_id === null
                        || $holiday->location_id === $user->office_location_id
                );
                $isEffectiveWorkDay = $this->balanceService->isEffectiveWorkDayFrom($user, $dateStr, $hasHoliday, $schedule);
                $isGantiHari = $schedule !== null
                    && $schedule->source === ScheduleSource::MANUAL_OVERRIDE
                    && $schedule->is_day_off;
                $isCollectiveLeave = $dayHolidays->contains(
                    fn (Holiday $holiday): bool => $holiday->holiday_type === HolidayType::COLLECTIVE_LEAVE
                );

                /** @var LeaveRequestDay|null $leaveDay */
                $leaveDay = $leaveDaysByUserDate->get($key);
                /** @var LeaveRequest|null $leave */
                $leave = $leaveDay?->leaveRequest;

                $shiftName = 'MKT';
                $shiftCode = 'MKT';
                $shiftLabel = 'MKT';

                if ($leave !== null) {
                    $type = 'Time Off';
                    $description = match ($leave->type) {
                        LeaveType::CUTI => 'Cuti Tahunan ('.(string) ($mappings['timeoff_annual'] ?? 'CT').')',
                        LeaveType::IZIN => 'Izin ('.(string) ($mappings['timeoff_izin'] ?? 'IZ').')',
                        LeaveType::SAKIT => 'Sakit ('.(string) ($mappings['timeoff_sakit'] ?? 'SK').')',
                        LeaveType::DINAS_LUAR => 'Dinas Luar',
                    };
                    $statusLabel = 'Approved';
                } elseif ($isCollectiveLeave) {
                    $type = 'Time Off';
                    $description = 'Cuti Bersama ('.(string) ($mappings['timeoff_collective'] ?? 'CB').')';
                    $statusLabel = 'Approved';
                } elseif (! $isEffectiveWorkDay) {
                    $type = 'Day Off';
                    $shiftName = 'dayoff';
                    $shiftCode = 'dayoff';
                    $shiftLabel = 'dayoff';
                    $description = $isGantiHari ? 'Libur (Ganti Hari)' : 'Libur Nasional/Mingguan';
                    $statusLabel = 'Approved';
                } elseif ($dateStr < ($todayLocalByUser[$user->id] ?? $dateStr)) {
                    $allRejected = $nonCancelled->isNotEmpty()
                        && $nonCancelled->every(fn (SalesVisit $visit): bool => $visit->verification_status === VerificationStatus::REJECTED);
                    $type = 'Absent';
                    $description = $allRejected
                        ? "Alpa - Ditolak HR ({$absentCode})"
                        : "Alpa ({$absentCode})";
                    $statusLabel = $allRejected ? 'Rejected' : 'Absent';
                } else {
                    continue;
                }

                $rows[] = [
                    $dateStr, '00:00:00', $type,
                    $user->nik, $user->name, $user->job_position ?? '',
                    '', '',
                    $shiftName, $shiftCode, $shiftLabel,
                    $user->officeLocation->name, $user->officeLocation->name, $user->officeLocation->address,
                    '',
                    $description, 'System', $statusLabel,
                ];
            }

            /** @var Collection<int, SalesVisit> $dayExportRows */
            $dayExportRows = $exportedByDate->get($dateStr, Collection::empty());

            foreach ($dayExportRows as $visit) {
                $tz = $visit->outletLocation->timezone;
                $coord = ($visit->visit_in_lat !== null && $visit->visit_in_lng !== null)
                    ? "{$visit->visit_in_lat},{$visit->visit_in_lng}"
                    : '';
                $flag = $visit->source->value === 'MANUAL_HR' ? 'Manual HR' : 'Mobile App';
                $statusLabel = match ($visit->verification_status) {
                    VerificationStatus::AUTO_APPROVED, VerificationStatus::APPROVED => 'Approved',
                    VerificationStatus::PENDING_REVIEW => 'Pending',
                    VerificationStatus::REJECTED => 'Rejected',
                };

                if ($visit->visit_in_at !== null) {
                    $localIn = $visit->visit_in_at->setTimezone($tz);
                    $rows[] = [
                        $localIn->format('Y-m-d'), $localIn->format('H:i:s'), 'Clock In',
                        $visit->user->nik, $visit->user->name, $visit->user->job_position ?? '',
                        '', '', 'MKT', 'MKT', 'MKT',
                        $visit->outletLocation->name, $visit->outletLocation->name, $visit->outletLocation->address,
                        $coord, $visit->outletLocation->name, $flag, $statusLabel,
                    ];
                }

                if ($visit->visit_out_at !== null) {
                    $localOut = $visit->visit_out_at->setTimezone($tz);
                    $outCoord = ($visit->visit_out_lat !== null && $visit->visit_out_lng !== null)
                        ? "{$visit->visit_out_lat},{$visit->visit_out_lng}"
                        : '';
                    $rows[] = [
                        $localOut->format('Y-m-d'), $localOut->format('H:i:s'), 'Clock Out',
                        $visit->user->nik, $visit->user->name, $visit->user->job_position ?? '',
                        '', '', 'MKT', 'MKT', 'MKT',
                        $visit->outletLocation->name, $visit->outletLocation->name, $visit->outletLocation->address,
                        $outCoord, trim($visit->outletLocation->name.' - '.($visit->visit_notes ?? '')), $flag, $statusLabel,
                    ];
                }
            }

            $cursor = $cursor->addDay();
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'sales_visits',
            recordId: 0,
            action: AuditAction::EXPORT_ATTENDANCE,
            newValues: ['file' => 'sales_visits', 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'format' => $format],
        );

        return $this->downloadReport('sales-visits-'.$start->toDateString(), 'Worksheet', $headers, $rows, $format);
    }

    public function auditInternal(Request $request): StreamedResponse|BinaryFileResponse
    {
        [$start, $end, $actor, $format] = $this->validateExportRange($request);
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $headers = [
            'Tanggal', 'NIK', 'Nama', 'Role', 'Cabang', 'Zona Waktu', 'Shift/Toko',
            'Sumber Data', 'Jam Masuk/Visit In', 'Jam Pulang/Visit Out',
            'Total Durasi Kerja (menit)', 'Status Kehadiran', 'Status Verifikasi',
            'Diverifikasi Oleh', 'Waktu Verifikasi', 'Flag Anomali',
            'Alasan/Catatan', 'Alasan Reject',
        ];

        $users = User::query()
            ->with('officeLocation:id,name,timezone,work_days')
            ->orderBy('name')
            ->get();
        $userIds = $users->pluck('id');

        $holidaysByDate = Holiday::query()
            ->whereDate('holiday_date', '>=', $startDate)
            ->whereDate('holiday_date', '<=', $endDate)
            ->get(['holiday_date', 'location_id', 'holiday_type'])
            ->groupBy(fn (Holiday $holiday): string => $holiday->holiday_date->toDateString());

        $schedulesByUserDate = UserShiftSchedule::query()
            ->with('shift:id,code')
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get()
            ->keyBy(fn (UserShiftSchedule $schedule): string => $schedule->user_id.'|'.$schedule->work_date->toDateString());

        $leaveDaysByUserDate = LeaveRequestDay::query()
            ->with('leaveRequest:id,type,reason,reject_reason')
            ->whereIn('user_id', $userIds)
            ->whereDate('leave_date', '>=', $startDate)
            ->whereDate('leave_date', '<=', $endDate)
            ->where('status', LeaveDayStatus::APPROVED)
            ->get()
            ->keyBy(fn (LeaveRequestDay $day): string => $day->user_id.'|'.$day->leave_date->toDateString());

        $logsByUserDate = AttendanceLog::query()
            ->with(['shift:id,code', 'clockInReviewer:id,nik,name', 'clockOutReviewer:id,nik,name'])
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get()
            ->groupBy(fn (AttendanceLog $log): string => $log->user_id.'|'.$log->work_date->toDateString());

        $visitsByUserDate = SalesVisit::query()
            ->with(['outletLocation:id,name,timezone', 'reviewer:id,nik,name'])
            ->whereIn('user_id', $userIds)
            ->whereDate('visit_date', '>=', $startDate)
            ->whereDate('visit_date', '<=', $endDate)
            ->orderBy('visit_in_at')
            ->get()
            ->groupBy(fn (SalesVisit $visit): string => $visit->user_id.'|'.$visit->visit_date->toDateString());

        $rows = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $dateStr = $cursor->toDateString();
            $dayHolidays = $holidaysByDate->get($dateStr, Collection::empty());

            foreach ($users as $user) {
                if ($dateStr < $user->join_date->toDateString()
                    || ($user->deactivated_at !== null && $dateStr > $user->deactivated_at->toDateString())) {
                    continue;
                }

                $timezone = $user->officeLocation->timezone;
                $todayLocal = CarbonImmutable::now('UTC')->setTimezone($timezone)->toDateString();
                $key = $user->id.'|'.$dateStr;
                $schedule = $schedulesByUserDate->get($key);
                $hasHoliday = $dayHolidays->contains(
                    fn (Holiday $holiday): bool => $holiday->location_id === null
                        || $holiday->location_id === $user->office_location_id
                );
                $isEffectiveWorkDay = $this->balanceService->isEffectiveWorkDayFrom($user, $dateStr, $hasHoliday, $schedule);
                $isGantiHari = $schedule !== null
                    && $schedule->source === ScheduleSource::MANUAL_OVERRIDE
                    && $schedule->is_day_off;
                $dayOffLabel = $isGantiHari ? 'Libur (Ganti Hari)' : 'Libur';

                $leaveDay = $leaveDaysByUserDate->get($key);
                $leave = $leaveDay?->leaveRequest;
                $leaveLabel = $leave !== null ? match ($leave->type) {
                    LeaveType::CUTI => 'Cuti',
                    LeaveType::IZIN => 'Izin',
                    LeaveType::SAKIT => 'Sakit',
                    LeaveType::DINAS_LUAR => 'Dinas Luar',
                } : null;

                $dayLogs = $logsByUserDate->get($key, Collection::empty());
                $log = $dayLogs->firstWhere('is_active_attempt', 1) ?? $dayLogs->sortByDesc('attempt_no')->first();
                $logShift = $log?->getRelation('shift');
                $scheduleShift = $schedule?->getRelation('shift');
                $shiftLabel = $logShift instanceof Shift
                    ? $logShift->code
                    : ($scheduleShift instanceof Shift ? $scheduleShift->code : '-');

                $baseRow = [
                    $dateStr, $user->nik, $user->name, $user->role->value,
                    $user->officeLocation->name, $timezone, $shiftLabel,
                    '-', '-', '-', '', '', '-', '-', '-', '', '', '',
                ];

                if ($user->role === UserRole::EMPLOYEE_SALES) {
                    $dayVisits = $visitsByUserDate->get($key, Collection::empty());

                    if ($leaveLabel !== null) {
                        $row = $baseRow;
                        $row[11] = $leaveLabel;
                        $row[16] = $leave->reason;
                        $row[17] = $leave->reject_reason ?? '';
                        $rows[] = $row;

                        continue;
                    }

                    if ($dayVisits->isNotEmpty()) {
                        foreach ($dayVisits as $visit) {
                            $visitTz = $visit->outletLocation->timezone;
                            $reviewer = $visit->reviewer;
                            $row = $baseRow;
                            $row[5] = $visitTz;
                            $row[6] = $visit->outletLocation->name;
                            $row[7] = $visit->source->value;
                            $row[8] = $visit->visit_in_at?->setTimezone($visitTz)->format('H:i:s') ?? '-';
                            $row[9] = $visit->visit_out_at?->setTimezone($visitTz)->format('H:i:s') ?? '-';
                            $row[10] = $visit->duration_minutes !== null ? (string) $visit->duration_minutes : '';
                            $row[11] = $visit->visit_status === VisitStatus::CANCELLED
                                ? 'Dibatalkan'
                                : ($visit->verification_status === VerificationStatus::REJECTED
                                    ? 'Alpa (Ditolak HR)'
                                    : 'Hadir');
                            $row[12] = $visit->visit_status->value.' / '.$visit->verification_status->value;
                            $row[13] = $reviewer !== null ? $reviewer->nik.' - '.$reviewer->name : '-';
                            $row[14] = $visit->reviewed_at?->setTimezone($visitTz)->format('Y-m-d H:i:s') ?? '-';
                            $row[15] = implode('|', $visit->anomaly_flags ?? []);
                            $row[16] = $visit->cancel_reason ?? $visit->visit_in_notes ?? $visit->visit_notes ?? '';
                            $row[17] = $visit->reject_reason ?? '';
                            $rows[] = $row;
                        }

                        continue;
                    }

                    $row = $baseRow;
                    $row[11] = ! $isEffectiveWorkDay
                        ? $dayOffLabel
                        : ($dateStr < $todayLocal ? 'Alpa' : 'Belum Absen');
                    $rows[] = $row;

                    continue;
                }

                if ($leave !== null && $leaveLabel !== null && $leave->type !== LeaveType::DINAS_LUAR) {
                    $row = $baseRow;
                    $row[11] = $leaveLabel;
                    $row[16] = $leave->reason;
                    $row[17] = $leave->reject_reason ?? '';
                    $rows[] = $row;

                    continue;
                }

                if ($log !== null) {
                    $flags = array_values(array_unique([...($log->clock_in_anomaly_flags ?? []), ...($log->clock_out_anomaly_flags ?? [])]));
                    $reviewer = $log->clockInReviewer ?? $log->clockOutReviewer;
                    $reviewedAt = $log->clock_in_reviewed_at ?? $log->clock_out_reviewed_at;
                    $duration = $log->clock_in_at !== null && $log->clock_out_at !== null
                        ? max(0, (int) $log->clock_in_at->diffInMinutes($log->clock_out_at))
                        : null;
                    $verification = $log->clock_out_status !== null
                        ? $log->clock_in_status->value.' / '.$log->clock_out_status->value
                        : $log->clock_in_status->value;

                    $row = $baseRow;
                    $row[7] = $log->source->value;
                    $row[8] = $log->clock_in_at?->setTimezone($timezone)->format('H:i:s') ?? '-';
                    $row[9] = $log->clock_out_at?->setTimezone($timezone)->format('H:i:s') ?? '-';
                    $row[10] = $duration !== null ? (string) $duration : '';
                    $row[11] = $log->clock_in_status === VerificationStatus::REJECTED
                        ? 'Alpa (Ditolak HR)'
                        : ($log->leave_request_id !== null
                            ? 'Dinas Luar'
                            : ($log->late_minutes > 0 ? 'Terlambat' : 'Hadir'));
                    $row[12] = $verification;
                    $row[13] = $reviewer !== null ? $reviewer->nik.' - '.$reviewer->name : '-';
                    $row[14] = $reviewedAt?->setTimezone($timezone)->format('Y-m-d H:i:s') ?? '-';
                    $row[15] = implode('|', $flags);
                    $row[16] = trim(($log->clock_in_notes ?? '').' | '.($log->clock_out_notes ?? ''), " \t\n\r\0\x0B|");
                    $row[17] = trim(($log->clock_in_reject_reason ?? '').' | '.($log->clock_out_reject_reason ?? ''), " \t\n\r\0\x0B|");
                    $rows[] = $row;

                    continue;
                }

                $row = $baseRow;
                if ($leave !== null && $leaveLabel !== null) {
                    $row[11] = $leaveLabel;
                    $row[16] = $leave->reason;
                    $row[17] = $leave->reject_reason ?? '';
                } elseif (! $isEffectiveWorkDay) {
                    $row[11] = $dayOffLabel;
                } elseif ($dateStr < $todayLocal) {
                    $row[11] = 'Alpa';
                } else {
                    $row[11] = 'Belum Absen';
                }
                $rows[] = $row;
            }

            $cursor = $cursor->addDay();
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'audit_logs',
            recordId: 0,
            action: AuditAction::EXPORT_ATTENDANCE,
            newValues: ['file' => 'audit_internal', 'start_date' => $startDate, 'end_date' => $endDate, 'format' => $format],
        );

        return $this->downloadReport('audit-internal-'.$startDate, 'Audit Internal', $headers, $rows, $format);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: User, 3: string}
     */
    private function validateExportRange(Request $request): array
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);

        $start = CarbonImmutable::parse((string) $validated['start_date'])->startOfDay();
        $end = CarbonImmutable::parse((string) $validated['end_date'])->startOfDay();

        if ($start->diffInDays($end) > 93) {
            throw ValidationException::withMessages([
                'end_date' => ['Rentang ekspor maksimal 93 hari (3 bulan) per unduhan.'],
            ]);
        }

        return [$start, $end, $actor, (string) ($validated['format'] ?? 'csv')];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function downloadReport(string $baseFilename, string $sheetTitle, array $headers, array $rows, string $format): StreamedResponse|BinaryFileResponse
    {
        $rows = $this->sanitizeRows($rows);

        if ($format === 'xlsx') {
            return Excel::download(
                new TabularReportExport($sheetTitle, $headers, $rows),
                "{$baseFilename}.xlsx",
                ExcelFormat::XLSX,
            );
        }

        return $this->streamCsv("{$baseFilename}.csv", $headers, $rows);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<list<string>>
     */
    private function sanitizeRows(array $rows): array
    {
        return array_map(
            fn (array $row): array => array_map(fn (string $cell): string => $this->sanitizer->sanitizeCell($cell), $row),
            $rows,
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            foreach ($this->sanitizeRows($rows) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
