<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceSource;
use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Mail\AttendanceRejectedMail;
use App\Models\ActivityCategory;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\AttendanceHelperService;
use App\Services\AuditLogger;
use App\Services\ImageEvidenceService;
use App\Services\LeaveBalanceService;
use App\Services\ScheduleResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HrDashboardController extends Controller
{
    private const array BULK_EXCLUDED_FLAGS = [
        'MISSED_CLOCK_OUT',
        'MISSED_VISIT_OUT',
        'SHARED_DEVICE',
        'IMPOSSIBLE_TRAVEL',
        'REPEATED_COORDS',
        'CONSTANT_ACCURACY',
        'STALE_TIMESTAMP',
        'UNVERIFIED_OUTLET',
        'OFFSITE_DUTY',
        'OFF_DAY_ATTENDANCE',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ImageEvidenceService $imageEvidence,
        private readonly ScheduleResolverService $scheduleResolver,
        private readonly AttendanceHelperService $helper,
        private readonly LeaveBalanceService $balanceService,
    ) {}

    public function index(Request $request): Response
    {
        $selectedDate = (string) $request->query('date', CarbonImmutable::now('Asia/Jakarta')->toDateString());
        $activeHrCount = User::query()
            ->where('role', UserRole::HR_ADMIN)
            ->where('is_active', true)
            ->count();

        $users = User::query()
            ->with(['officeLocation:id,name,timezone,work_days,latitude,longitude,radius_m', 'defaultShiftPattern'])
            ->where('is_active', true)
            ->whereDate('join_date', '<=', $selectedDate)
            ->where(fn ($query) => $query
                ->whereNull('deactivated_at')
                ->orWhereDate('deactivated_at', '>=', $selectedDate))
            ->orderBy('name')
            ->get();

        $userIds = $users->pluck('id');
        $locationIds = $users->pluck('office_location_id')->unique()->values();

        $holidays = Holiday::query()
            ->with('location:id,name')
            ->whereDate('holiday_date', $selectedDate)
            ->where(fn ($query) => $query->whereNull('location_id')->orWhereIn('location_id', $locationIds))
            ->get(['id', 'location_id', 'holiday_type', 'description']);
        $globalHoliday = $holidays->contains(fn (Holiday $holiday): bool => $holiday->location_id === null);
        $holidayLocationIds = $holidays->whereNotNull('location_id')->pluck('location_id')->all();

        $schedulesByUser = UserShiftSchedule::query()
            ->with('shift:id,code,start_time,end_time')
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', $selectedDate)
            ->get()
            ->keyBy('user_id');

        $dayOfWeekIso = CarbonImmutable::parse($selectedDate)->dayOfWeekIso;
        $patternShiftIds = $users
            ->filter(fn (User $u): bool => ! $schedulesByUser->has($u->id) && $u->defaultShiftPattern !== null)
            ->map(fn (User $u): ?int => match ($dayOfWeekIso) {
                1 => $u->defaultShiftPattern->mon_shift_id,
                2 => $u->defaultShiftPattern->tue_shift_id,
                3 => $u->defaultShiftPattern->wed_shift_id,
                4 => $u->defaultShiftPattern->thu_shift_id,
                5 => $u->defaultShiftPattern->fri_shift_id,
                6 => $u->defaultShiftPattern->sat_shift_id,
                7 => $u->defaultShiftPattern->sun_shift_id,
                default => null,
            })
            ->filter()
            ->unique()
            ->values();
        $patternShiftsById = $patternShiftIds->isNotEmpty()
            ? Shift::query()->whereIn('id', $patternShiftIds)->get(['id', 'code', 'start_time', 'end_time'])->keyBy('id')
            : collect();

        $leaveDaysByUser = LeaveRequestDay::query()
            ->with('leaveRequest:id,type,scheduled_start_time')
            ->whereIn('user_id', $userIds)
            ->whereDate('leave_date', $selectedDate)
            ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
            ->get()
            ->groupBy('user_id');

        $logsByUser = AttendanceLog::query()
            ->with('shift:id,code,end_time')
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', $selectedDate)
            ->get()
            ->groupBy('user_id');

        $visitsByUser = SalesVisit::query()
            ->with(['outletLocation:id,name,code', 'activityCategory:id,name'])
            ->whereIn('user_id', $userIds)
            ->whereDate('visit_date', $selectedDate)
            ->orderBy('visit_in_at')
            ->get()
            ->groupBy('user_id');

        $dailyRecap = $users->map(function (User $u) use ($selectedDate, $schedulesByUser, $patternShiftsById, $dayOfWeekIso, $leaveDaysByUser, $logsByUser, $visitsByUser, $globalHoliday, $holidayLocationIds): array {
            $tz = $u->officeLocation->timezone;
            $nowLocal = CarbonImmutable::now('UTC')->setTimezone($tz);
            $todayLocal = $nowLocal->toDateString();
            $schedule = $schedulesByUser->get($u->id);
            $hasHoliday = $globalHoliday || in_array($u->office_location_id, $holidayLocationIds, true);
            $isEffectiveWorkDay = $this->balanceService->isEffectiveWorkDayFrom($u, $selectedDate, $hasHoliday, $schedule);
            $isGantiHari = $schedule !== null
                && $schedule->source === ScheduleSource::MANUAL_OVERRIDE
                && $schedule->is_day_off;
            $dayOffLabel = $isGantiHari ? 'Libur (Ganti Hari)' : 'Libur';
            /** @var Collection<int, LeaveRequestDay> $dayLeaves */
            $dayLeaves = $leaveDaysByUser->get($u->id, collect());
            $approvedLeave = $dayLeaves->first(fn (LeaveRequestDay $day): bool => $day->status === LeaveDayStatus::APPROVED);
            $pendingLeave = $dayLeaves->first(fn (LeaveRequestDay $day): bool => $day->status === LeaveDayStatus::PENDING);
            $dayLogs = $logsByUser->get($u->id, collect());
            $activeLog = $dayLogs->firstWhere('is_active_attempt', 1);
            $lastRejectedLog = $activeLog === null
                ? $dayLogs
                    ->filter(fn (AttendanceLog $log): bool => $log->clock_in_status === VerificationStatus::REJECTED)
                    ->sortByDesc('attempt_no')
                    ->first()
                : null;

            if ($u->role === UserRole::EMPLOYEE_SALES) {
                $visits = $visitsByUser->get($u->id, collect());

                $validVisits = $visits->filter(
                    fn (SalesVisit $v): bool => $v->visit_status !== VisitStatus::CANCELLED
                        && $v->verification_status !== VerificationStatus::REJECTED
                );
                $rejectedVisits = $visits->filter(
                    fn (SalesVisit $v): bool => $v->verification_status === VerificationStatus::REJECTED
                );

                $status = 'Belum Absen';
                if ($approvedLeave !== null && $approvedLeave->leaveRequest !== null) {
                    $status = match ($approvedLeave->leaveRequest->type) {
                        LeaveType::CUTI => 'Cuti',
                        LeaveType::IZIN => 'Izin',
                        LeaveType::SAKIT => 'Sakit',
                        LeaveType::DINAS_LUAR => 'Dinas Luar',
                    };
                } elseif ($validVisits->isNotEmpty()) {
                    $status = 'Hadir';
                } elseif (! $isEffectiveWorkDay) {
                    $status = $dayOffLabel;
                } elseif ($selectedDate < $todayLocal) {
                    $status = $rejectedVisits->isNotEmpty() ? 'Alpa (Ditolak HR)' : 'Alpa';
                } elseif ($pendingLeave !== null && $pendingLeave->leaveRequest !== null) {
                    $status = 'Belum Absen (Pending '.$pendingLeave->leaveRequest->type->value.')';
                }

                $firstIn = $validVisits->first()?->visit_in_at?->setTimezone($tz)->format('H:i');
                $lastOut = $validVisits->last()?->visit_out_at?->setTimezone($tz)->format('H:i');

                return [
                    'user_id' => $u->id,
                    'nik' => $u->nik,
                    'name' => $u->name,
                    'role' => $u->role->value,
                    'branch' => $u->officeLocation->name,
                    'shift' => 'MKT',
                    'clock_in' => $firstIn,
                    'clock_out' => $lastOut,
                    'late_minutes' => 0,
                    'visit_count' => $validVisits->count(),
                    'attendance_status' => $status,
                    'verification_status' => $validVisits->first()?->verification_status->value ?? '-',
                    'source' => $validVisits->first()?->source->value ?? '-',
                    'rejected_log_id' => null,
                    'rejected_phase' => null,
                ];
            }

            $status = 'Belum Absen';
            if ($approvedLeave !== null && $approvedLeave->leaveRequest !== null && $approvedLeave->leaveRequest->type !== LeaveType::DINAS_LUAR) {
                $status = match ($approvedLeave->leaveRequest->type) {
                    LeaveType::CUTI => 'Cuti',
                    LeaveType::IZIN => 'Izin',
                    LeaveType::SAKIT => 'Sakit',
                };
            } elseif ($activeLog !== null) {
                $status = $activeLog->leave_request_id !== null
                    ? 'Dinas Luar'
                    : ($activeLog->late_minutes > 0 ? 'Terlambat' : 'Hadir');
            } elseif (! $isEffectiveWorkDay) {
                $status = $dayOffLabel;
            } elseif ($selectedDate < $todayLocal) {
                $status = $lastRejectedLog !== null ? 'Alpa (Ditolak HR)' : 'Alpa';
            } else {
                $effectiveShift = $schedule?->shift;
                if ($effectiveShift === null && $schedule === null && $u->defaultShiftPattern !== null) {
                    $patternShiftId = match ($dayOfWeekIso) {
                        1 => $u->defaultShiftPattern->mon_shift_id,
                        2 => $u->defaultShiftPattern->tue_shift_id,
                        3 => $u->defaultShiftPattern->wed_shift_id,
                        4 => $u->defaultShiftPattern->thu_shift_id,
                        5 => $u->defaultShiftPattern->fri_shift_id,
                        6 => $u->defaultShiftPattern->sat_shift_id,
                        7 => $u->defaultShiftPattern->sun_shift_id,
                        default => null,
                    };
                    $effectiveShift = $patternShiftId !== null ? $patternShiftsById->get($patternShiftId) : null;
                }
                $dinasLeave = ($approvedLeave !== null && $approvedLeave->leaveRequest?->type === LeaveType::DINAS_LUAR)
                    ? $approvedLeave->leaveRequest
                    : null;
                $effectiveEnd = $selectedDate === $todayLocal
                    ? $this->scheduleResolver->effectiveEndTime($selectedDate, $tz, $effectiveShift, $dinasLeave)
                    : null;

                if ($selectedDate === $todayLocal && $effectiveEnd !== null && $nowLocal->greaterThanOrEqualTo($effectiveEnd)) {
                    if ($pendingLeave !== null) {
                        $status = 'Alpa (Ada Pengajuan Pending)';
                    } elseif ($lastRejectedLog !== null) {
                        $status = 'Alpa (Ditolak HR)';
                    } else {
                        $status = 'Alpa';
                    }
                } elseif ($pendingLeave !== null && $pendingLeave->leaveRequest !== null) {
                    $status = 'Belum Absen (Pending '.$pendingLeave->leaveRequest->type->value.')';
                }
            }

            $rejectedLogId = null;
            $rejectedPhase = null;
            if ($lastRejectedLog !== null) {
                $rejectedLogId = $lastRejectedLog->id;
                $rejectedPhase = 'clock_in';
            } elseif ($activeLog !== null && $activeLog->clock_out_status === VerificationStatus::REJECTED) {
                $rejectedLogId = $activeLog->id;
                $rejectedPhase = 'clock_out';
            }

            return [
                'user_id' => $u->id,
                'nik' => $u->nik,
                'name' => $u->name,
                'role' => $u->role->value,
                'branch' => $u->officeLocation->name,
                'shift' => $activeLog !== null && $activeLog->shift !== null ? $activeLog->shift->code : '-',
                'clock_in' => $activeLog?->clock_in_at?->setTimezone($tz)->format('H:i'),
                'clock_out' => $activeLog?->clock_out_status === VerificationStatus::REJECTED
                    ? null
                    : $activeLog?->clock_out_at?->setTimezone($tz)->format('H:i'),
                'late_minutes' => $activeLog !== null ? $activeLog->late_minutes : 0,
                'visit_count' => 0,
                'attendance_status' => $status,
                'verification_status' => $activeLog?->clock_in_status->value ?? ($lastRejectedLog?->clock_in_status->value ?? '-'),
                'source' => $activeLog?->source->value ?? '-',
                'rejected_log_id' => $rejectedLogId,
                'rejected_phase' => $rejectedPhase,
            ];
        })->values();

        $pendingAttendanceRecords = AttendanceLog::query()
            ->with([
                'user:id,nik,name,reference_photo_url',
                'location:id,name,latitude,longitude,radius_m,timezone',
                'shift:id,code,start_time,end_time',
                'leaveRequest:id,status,destination_location,scheduled_start_time',
            ])
            ->where(function ($q): void {
                $q->where('clock_in_status', VerificationStatus::PENDING_REVIEW)
                    ->orWhere('clock_out_status', VerificationStatus::PENDING_REVIEW);
            })
            ->orderByRaw('CASE WHEN work_date = ? THEN 0 ELSE 1 END', [$selectedDate])
            ->orderByDesc('work_date')
            ->limit(100)
            ->get();

        $pendingVisitRecords = SalesVisit::query()
            ->with(['user:id,nik,name,reference_photo_url', 'outletLocation:id,name,latitude,longitude,radius_m,timezone'])
            ->where('verification_status', VerificationStatus::PENDING_REVIEW)
            ->orderByRaw('CASE WHEN visit_date = ? THEN 0 ELSE 1 END', [$selectedDate])
            ->orderByDesc('visit_date')
            ->limit(100)
            ->get();

        $queueDates = $pendingAttendanceRecords
            ->map(fn (AttendanceLog $l): string => $l->work_date->toDateString())
            ->concat($pendingVisitRecords->map(fn (SalesVisit $v): string => $v->visit_date->toDateString()))
            ->unique()
            ->values();

        $collectiveLeaveHolidays = $queueDates->isNotEmpty()
            ? Holiday::query()
                ->where('holiday_type', HolidayType::COLLECTIVE_LEAVE)
                ->whereIn('holiday_date', $queueDates)
                ->get(['holiday_date', 'location_id'])
            : collect();

        $isCollectiveLeave = fn (string $date, int $locationId): bool => $collectiveLeaveHolidays->contains(
            fn (Holiday $h): bool => $h->holiday_date->toDateString() === $date
                && ($h->location_id === null || $h->location_id === $locationId)
        );

        $sharedDevicePeers = function (array $flags, ?string $deviceId, string $date, int $excludeUserId): array {
            if (! in_array('SHARED_DEVICE', $flags, true) || $deviceId === null || $deviceId === '') {
                return [];
            }

            $attPeers = AttendanceLog::query()
                ->with(['user:id,nik,name', 'location:id,timezone'])
                ->whereDate('work_date', $date)
                ->where('user_id', '!=', $excludeUserId)
                ->where(fn ($q) => $q->where('clock_in_device_id', $deviceId)->orWhere('clock_out_device_id', $deviceId))
                ->get()
                ->map(fn (AttendanceLog $peer): array => [
                    'nik' => $peer->user->nik,
                    'name' => $peer->user->name,
                    'time' => ($peer->clock_in_device_id === $deviceId ? $peer->clock_in_at : $peer->clock_out_at)
                        ?->setTimezone($peer->location->timezone)
                        ->format('H:i'),
                ]);

            $visitPeers = SalesVisit::query()
                ->with(['user:id,nik,name', 'outletLocation:id,timezone'])
                ->whereDate('visit_date', $date)
                ->where('user_id', '!=', $excludeUserId)
                ->where(fn ($q) => $q->where('visit_in_device_id', $deviceId)->orWhere('visit_out_device_id', $deviceId))
                ->get()
                ->map(fn (SalesVisit $peer): array => [
                    'nik' => $peer->user->nik,
                    'name' => $peer->user->name,
                    'time' => ($peer->visit_in_device_id === $deviceId ? $peer->visit_in_at : $peer->visit_out_at)
                        ?->setTimezone($peer->outletLocation->timezone)
                        ->format('H:i'),
                ]);

            return $attPeers->concat($visitPeers)->values()->all();
        };

        $pendingAttendance = $pendingAttendanceRecords->flatMap(function (AttendanceLog $log) use ($isCollectiveLeave, $sharedDevicePeers): array {
            $phases = [];
            if ($log->clock_in_status === VerificationStatus::PENDING_REVIEW) {
                $phases[] = 'clock_in';
            }
            if ($log->clock_out_status === VerificationStatus::PENDING_REVIEW) {
                $phases[] = 'clock_out';
            }

            $date = $log->work_date->toDateString();
            $dinasInfo = $log->leaveRequest !== null ? [
                'destination_location' => $log->leaveRequest->destination_location,
                'scheduled_start_time' => $log->leaveRequest->scheduled_start_time,
                'status' => $log->leaveRequest->status->value,
            ] : null;
            $collective = $isCollectiveLeave($date, $log->location_id);

            return array_map(function (string $phase) use ($log, $date, $dinasInfo, $collective, $sharedDevicePeers): array {
                $flags = $phase === 'clock_in' ? ($log->clock_in_anomaly_flags ?? []) : ($log->clock_out_anomaly_flags ?? []);
                $photoPath = $phase === 'clock_in' ? $log->clock_in_photo_url : $log->clock_out_photo_url;
                $deviceId = $phase === 'clock_in' ? $log->clock_in_device_id : $log->clock_out_device_id;

                $defaultOutTime = null;
                if (in_array('MISSED_CLOCK_OUT', $flags, true)) {
                    $defaultOutTime = $log->schedule_end_time !== null
                        ? substr($log->schedule_end_time, 0, 5)
                        : $this->scheduleResolver->effectiveEndTime(
                            $date,
                            $log->location->timezone,
                            $log->shift,
                            $log->leaveRequest,
                        )?->format('H:i');
                }

                return [
                    'kind' => 'attendance',
                    'id' => $log->id,
                    'phase' => $phase,
                    'user_id' => $log->user_id,
                    'nik' => $log->user->nik,
                    'name' => $log->user->name,
                    'date' => $date,
                    'location_name' => $log->location->name,
                    'target_lat' => (float) ($log->office_lat ?? $log->location->latitude),
                    'target_lng' => (float) ($log->office_lng ?? $log->location->longitude),
                    'radius_m' => $log->office_radius_m ?? $log->location->radius_m,
                    'user_lat' => $phase === 'clock_in' ? ($log->clock_in_lat !== null ? (float) $log->clock_in_lat : null) : ($log->clock_out_lat !== null ? (float) $log->clock_out_lat : null),
                    'user_lng' => $phase === 'clock_in' ? ($log->clock_in_lng !== null ? (float) $log->clock_in_lng : null) : ($log->clock_out_lng !== null ? (float) $log->clock_out_lng : null),
                    'accuracy_m' => $phase === 'clock_in' ? ($log->clock_in_accuracy_m !== null ? (float) $log->clock_in_accuracy_m : null) : ($log->clock_out_accuracy_m !== null ? (float) $log->clock_out_accuracy_m : null),
                    'flags' => $flags,
                    'notes' => $phase === 'clock_in' ? $log->clock_in_notes : $log->clock_out_notes,
                    'photo_url' => $photoPath !== null ? $this->imageEvidence->signedUrl($photoPath) : null,
                    'reference_photo_url' => $log->user->reference_photo_url !== null ? $this->imageEvidence->signedUrl($log->user->reference_photo_url) : null,
                    'bulk_eligible' => array_intersect($flags, self::BULK_EXCLUDED_FLAGS) === [],
                    'review_locked' => $log->leave_request_id !== null
                        && $log->leaveRequest?->status === LeaveStatus::PENDING,
                    'default_out_time' => $defaultOutTime,
                    'dinas_info' => $dinasInfo,
                    'is_collective_leave_date' => $collective,
                    'shared_device_peers' => $sharedDevicePeers($flags, $deviceId, $date, $log->user_id),
                ];
            }, $phases);
        })->values();

        $pendingVisits = $pendingVisitRecords->map(function (SalesVisit $visit) use ($isCollectiveLeave, $sharedDevicePeers): array {
            $flags = $visit->anomaly_flags ?? [];
            $date = $visit->visit_date->toDateString();

            return [
                'kind' => 'visit',
                'id' => $visit->id,
                'phase' => 'visit',
                'user_id' => $visit->user_id,
                'nik' => $visit->user->nik,
                'name' => $visit->user->name,
                'date' => $date,
                'location_name' => $visit->outletLocation->name,
                'target_lat' => (float) ($visit->outlet_lat ?? $visit->outletLocation->latitude),
                'target_lng' => (float) ($visit->outlet_lng ?? $visit->outletLocation->longitude),
                'radius_m' => $visit->outlet_radius_m ?? $visit->outletLocation->radius_m,
                'user_lat' => $visit->visit_in_lat !== null ? (float) $visit->visit_in_lat : null,
                'user_lng' => $visit->visit_in_lng !== null ? (float) $visit->visit_in_lng : null,
                'accuracy_m' => $visit->visit_in_accuracy_m !== null ? (float) $visit->visit_in_accuracy_m : null,
                'flags' => $flags,
                'notes' => $visit->visit_in_notes ?? $visit->visit_notes,
                'photo_url' => $visit->visit_in_photo_url !== null ? $this->imageEvidence->signedUrl($visit->visit_in_photo_url) : null,
                'reference_photo_url' => $visit->user->reference_photo_url !== null ? $this->imageEvidence->signedUrl($visit->user->reference_photo_url) : null,
                'bulk_eligible' => array_intersect($flags, self::BULK_EXCLUDED_FLAGS) === [],
                'review_locked' => $visit->visit_status === VisitStatus::IN_PROGRESS,
                'visit_status' => $visit->visit_status->value,
                'default_out_time' => null,
                'dinas_info' => null,
                'is_collective_leave_date' => $isCollectiveLeave($date, $visit->outlet_location_id),
                'shared_device_peers' => $sharedDevicePeers($flags, $visit->visit_in_device_id, $date, $visit->user_id),
            ];
        });

        $pendingLeaveCount = LeaveRequest::query()
            ->where('status', LeaveStatus::PENDING)
            ->count();

        $pendingNooCount = Location::query()
            ->where('status', LocationStatus::PENDING_VERIFICATION)
            ->count();

        $activeHolidays = $holidays->map(function (Holiday $holiday): array {
            $location = $holiday->getRelation('location');

            return [
                'id' => $holiday->id,
                'holiday_type' => $holiday->holiday_type->value,
                'description' => $holiday->description,
                'location_name' => $location instanceof Location ? $location->name : 'Semua Cabang',
            ];
        })->values();

        $salesVisits = $users
            ->where('role', UserRole::EMPLOYEE_SALES)
            ->flatMap(function (User $u) use ($visitsByUser): Collection {
                $tz = $u->officeLocation->timezone;

                return $visitsByUser->get($u->id, collect())->map(function (SalesVisit $v) use ($tz, $u): array {
                    $category = $v->getRelation('activityCategory');

                    return [
                        'id' => $v->id,
                        'user_id' => $u->id,
                        'nik' => $u->nik,
                        'name' => $u->name,
                        'outlet_name' => $v->outletLocation->name,
                        'outlet_code' => $v->outletLocation->code ?? 'NOO',
                        'activity_category' => $category instanceof ActivityCategory ? $category->name : '-',
                        'visit_in' => $v->visit_in_at?->setTimezone($tz)->format('H:i'),
                        'visit_out' => $v->visit_out_at?->setTimezone($tz)->format('H:i'),
                        'duration_minutes' => $v->duration_minutes,
                        'visit_status' => $v->visit_status->value,
                        'verification_status' => $v->verification_status->value,
                        'anomaly_flags' => $v->anomaly_flags ?? [],
                        'notes' => $v->visit_notes ?? $v->visit_in_notes,
                    ];
                });
            })
            ->values();

        /** @var User $actor */
        $actor = $request->user();
        $recentManualRaw = AuditLog::query()
            ->with('actor:id,nik,name')
            ->where('action', AuditAction::MANUAL_ATTENDANCE)
            ->where('actor_id', '!=', $actor->id)
            ->where('created_at', '>=', now('UTC')->subDays(7))
            ->latest('id')
            ->limit(20)
            ->get();

        $manualTargetIds = $recentManualRaw
            ->map(fn (AuditLog $entry): ?int => isset($entry->new_values['user_id']) ? (int) $entry->new_values['user_id'] : null)
            ->filter()
            ->unique()
            ->values();
        $manualTargetNames = $manualTargetIds->isNotEmpty()
            ? User::query()->whereIn('id', $manualTargetIds)->pluck('name', 'id')
            : collect();

        $recentManualEntries = $recentManualRaw->map(function (AuditLog $entry) use ($manualTargetNames): array {
            $targetId = isset($entry->new_values['user_id']) ? (int) $entry->new_values['user_id'] : null;

            return [
                'id' => $entry->id,
                'actor_nik' => $entry->actor?->nik,
                'actor_name' => $entry->actor?->name,
                'entity' => $entry->entity,
                'record_id' => $entry->record_id,
                'target_name' => $targetId !== null ? ($manualTargetNames->get($targetId) ?? '-') : '-',
                'date' => $entry->new_values['work_date'] ?? $entry->new_values['visit_date'] ?? null,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at->toIso8601String(),
            ];
        })->values();

        return Inertia::render('admin/Dashboard', [
            'selectedDate' => $selectedDate,
            'todayDate' => CarbonImmutable::now('Asia/Jakarta')->toDateString(),
            'showSingleHrBanner' => $activeHrCount < 2,
            'pendingLeaveCount' => $pendingLeaveCount,
            'pendingNooCount' => $pendingNooCount,
            'activeHolidays' => $activeHolidays,
            'dailyRecap' => $dailyRecap,
            'salesVisits' => $salesVisits,
            'pendingQueue' => $pendingAttendance->concat($pendingVisits)->values(),
            'recentManualEntries' => $recentManualEntries,
            'employees' => $users->map(fn (User $u): array => ['id' => $u->id, 'nik' => $u->nik, 'name' => $u->name, 'role' => $u->role->value]),
            'shifts' => Shift::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'outlets' => Location::query()->where('type', LocationType::OUTLET)->where('status', LocationStatus::ACTIVE)->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => ActivityCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function reviewAttendance(Request $request, AttendanceLog $attendanceLog): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($attendanceLog->user_id === $actor->id) {
            abort(403, 'Dilarang memverifikasi absensi milik sendiri.');
        }

        if ($this->linkedDinasIsPending($attendanceLog)) {
            throw ValidationException::withMessages([
                'attendance' => ['Tunggu persetujuan Dinas Luar sebelum memverifikasi absensi ini.'],
            ]);
        }

        $validated = $request->validate([
            'phase' => ['required', 'in:clock_in,clock_out'],
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'reject_reason' => ['nullable', 'string', 'max:500'],
            'clock_out_at' => ['nullable', 'date'],
        ]);

        $phase = (string) $validated['phase'];
        $decision = VerificationStatus::from((string) $validated['decision']);

        if ($decision === VerificationStatus::REJECTED && trim((string) ($validated['reject_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reject_reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        if ($phase === 'clock_in') {
            $updates = [
                'clock_in_status' => $decision,
                'clock_in_reviewed_by' => $actor->id,
                'clock_in_reviewed_at' => now('UTC'),
                'clock_in_reject_reason' => $decision === VerificationStatus::REJECTED ? trim((string) $validated['reject_reason']) : null,
            ];
            if ($decision === VerificationStatus::REJECTED) {
                $updates['is_active_attempt'] = null;
            }
            $this->applyAttendanceReview($attendanceLog, $updates);
        } else {
            $updates = [
                'clock_out_status' => $decision,
                'clock_out_reviewed_by' => $actor->id,
                'clock_out_reviewed_at' => now('UTC'),
                'clock_out_reject_reason' => $decision === VerificationStatus::REJECTED ? trim((string) $validated['reject_reason']) : null,
            ];
            if ($decision === VerificationStatus::APPROVED && $attendanceLog->clock_out_at === null) {
                $outAt = null;
                if (! empty($validated['clock_out_at'])) {
                    $outAt = CarbonImmutable::parse((string) $validated['clock_out_at'])->utc();
                } else {
                    // PRD v8: default to effective_end_time (covers day-off / no-shift rows too).
                    $tz = $attendanceLog->location->timezone;
                    $effectiveEnd = $this->scheduleResolver->effectiveEndTime(
                        $attendanceLog->work_date->toDateString(),
                        $tz,
                        $attendanceLog->shift,
                        $attendanceLog->leaveRequest,
                    );

                    if ($effectiveEnd !== null) {
                        $outAt = $effectiveEnd->utc();
                    }
                }

                if ($outAt !== null) {
                    if ($attendanceLog->clock_in_at !== null && $outAt->lessThanOrEqualTo($attendanceLog->clock_in_at)) {
                        throw ValidationException::withMessages([
                            'clock_out_at' => ['Jam pulang harus setelah jam masuk.'],
                        ]);
                    }
                    $updates['clock_out_at'] = $outAt;
                }
            }
            $this->applyAttendanceReview($attendanceLog, $updates);
        }

        if ($decision === VerificationStatus::REJECTED) {
            $owner = $attendanceLog->user;
            Mail::to($owner->email)->queue(new AttendanceRejectedMail(
                employeeName: $owner->name,
                dateLabel: $attendanceLog->work_date->toDateString(),
                phaseLabel: $phase === 'clock_in' ? 'Clock-In' : 'Clock-Out',
                rejectReason: trim((string) $validated['reject_reason']),
            ));
        }

        return response()->json(['attendance' => $attendanceLog->fresh()]);
    }

    public function correctAttendanceStatus(Request $request, AttendanceLog $attendanceLog): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($attendanceLog->user_id === $actor->id) {
            abort(403, 'Dilarang mengoreksi status absensi milik sendiri.');
        }

        $validated = $request->validate([
            'phase' => ['required', 'in:clock_in,clock_out'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'clock_in_time' => ['nullable', 'date_format:H:i'],
            'clock_out_time' => ['nullable', 'date_format:H:i'],
        ]);

        $phase = (string) $validated['phase'];
        $workDate = $attendanceLog->work_date->toDateString();
        $tz = $attendanceLog->location->timezone;

        $newClockInAt = ! empty($validated['clock_in_time'])
            ? CarbonImmutable::parse("{$workDate} {$validated['clock_in_time']}:00", $tz)->utc()
            : $attendanceLog->clock_in_at;
        $newClockOutAt = ! empty($validated['clock_out_time'])
            ? CarbonImmutable::parse("{$workDate} {$validated['clock_out_time']}:00", $tz)->utc()
            : $attendanceLog->clock_out_at;

        if ($newClockInAt !== null && $newClockOutAt !== null && $newClockOutAt->lessThanOrEqualTo($newClockInAt)) {
            throw ValidationException::withMessages([
                'clock_out_time' => ['Jam pulang harus setelah jam masuk.'],
            ]);
        }

        $timeUpdates = [];
        $oldValues = [$phase => VerificationStatus::REJECTED->value];
        $newValues = [$phase => VerificationStatus::APPROVED->value];

        if (! empty($validated['clock_in_time']) && $newClockInAt !== null) {
            $oldValues['clock_in_at'] = $attendanceLog->clock_in_at?->toIso8601String();
            $newValues['clock_in_at'] = $newClockInAt->toIso8601String();
            $timeUpdates['clock_in_at'] = $newClockInAt;

            $effectiveStart = $attendanceLog->schedule_start_time !== null
                ? CarbonImmutable::parse("{$workDate} {$attendanceLog->schedule_start_time}", $tz)
                : $this->scheduleResolver->effectiveStartTime($workDate, $tz, $attendanceLog->shift, $attendanceLog->leaveRequest);
            if ($effectiveStart !== null) {
                $shift = $attendanceLog->getRelationValue('shift');
                $grace = $attendanceLog->grace_period_minutes ?? ($shift instanceof Shift ? $shift->grace_period_minutes : 10);
                $localIn = $newClockInAt->setTimezone($tz);
                $lateLimit = $effectiveStart->addMinutes($grace);
                $timeUpdates['late_minutes'] = $localIn->greaterThan($lateLimit)
                    ? (int) ceil($effectiveStart->diffInSeconds($localIn) / 60)
                    : 0;
                $oldValues['late_minutes'] = $attendanceLog->late_minutes;
                $newValues['late_minutes'] = $timeUpdates['late_minutes'];
            }
        }

        if (! empty($validated['clock_out_time']) && $newClockOutAt !== null) {
            $oldValues['clock_out_at'] = $attendanceLog->clock_out_at?->toIso8601String();
            $newValues['clock_out_at'] = $newClockOutAt->toIso8601String();
            $timeUpdates['clock_out_at'] = $newClockOutAt;

            $effectiveEnd = $attendanceLog->schedule_end_time !== null
                ? CarbonImmutable::parse("{$workDate} {$attendanceLog->schedule_end_time}", $tz)
                : $this->scheduleResolver->effectiveEndTime($workDate, $tz, $attendanceLog->shift, $attendanceLog->leaveRequest);
            if ($effectiveEnd !== null) {
                $localOut = $newClockOutAt->setTimezone($tz);
                $timeUpdates['early_leave_minutes'] = $localOut->lessThan($effectiveEnd)
                    ? (int) ceil($localOut->diffInSeconds($effectiveEnd) / 60)
                    : 0;
                $oldValues['early_leave_minutes'] = $attendanceLog->early_leave_minutes;
                $newValues['early_leave_minutes'] = $timeUpdates['early_leave_minutes'];
            }
        }

        if ($phase === 'clock_in') {
            $newerExists = AttendanceLog::query()
                ->where('user_id', $attendanceLog->user_id)
                ->whereDate('work_date', $workDate)
                ->where('attempt_no', '>', $attendanceLog->attempt_no)
                ->exists();

            $activeOtherExists = AttendanceLog::query()
                ->where('user_id', $attendanceLog->user_id)
                ->whereDate('work_date', $workDate)
                ->where('is_active_attempt', 1)
                ->where('id', '!=', $attendanceLog->id)
                ->exists();

            if ($newerExists || $activeOtherExists || $this->scheduleResolver->hasApprovedTimeOff($attendanceLog->user_id, $workDate)) {
                return response()->json([
                    'message' => 'Koreksi ditolak karena sudah ada percobaan lebih baru, baris aktif lain, atau cuti/izin/sakit APPROVED.',
                ], 409);
            }

            $this->applyAttendanceReview($attendanceLog, array_merge([
                'clock_in_status' => VerificationStatus::APPROVED,
                'is_active_attempt' => 1,
                'clock_in_reviewed_by' => $actor->id,
                'clock_in_reviewed_at' => now('UTC'),
            ], $timeUpdates), cancelPendingLeave: true);
        } else {
            $this->applyAttendanceReview($attendanceLog, array_merge([
                'clock_out_status' => VerificationStatus::APPROVED,
                'clock_out_reviewed_by' => $actor->id,
                'clock_out_reviewed_at' => now('UTC'),
            ], $timeUpdates));
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'attendance_logs',
            recordId: $attendanceLog->id,
            action: AuditAction::CORRECT_STATUS,
            oldValues: $oldValues,
            newValues: $newValues,
            reason: trim((string) $validated['reason']),
        );

        return response()->json(['attendance' => $attendanceLog->fresh()]);
    }

    public function correctVisitStatus(Request $request, SalesVisit $salesVisit): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($salesVisit->user_id === $actor->id) {
            abort(403, 'Dilarang mengoreksi status kunjungan milik sendiri.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($salesVisit->verification_status !== VerificationStatus::REJECTED) {
            throw ValidationException::withMessages([
                'visit' => ['Hanya kunjungan berstatus REJECTED yang dapat dikoreksi.'],
            ]);
        }

        $visitDate = $salesVisit->visit_date->toDateString();
        if ($this->scheduleResolver->hasApprovedTimeOff($salesVisit->user_id, $visitDate)) {
            return response()->json([
                'message' => 'Koreksi ditolak karena terdapat Cuti/Izin/Sakit APPROVED pada tanggal tersebut.',
            ], 409);
        }

        DB::transaction(function () use ($salesVisit, $actor, $visitDate, $validated): void {
            $salesVisit->update([
                'verification_status' => VerificationStatus::APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now('UTC'),
                'reject_reason' => null,
            ]);

            $this->helper->cancelPendingLeaveDayOnPresence($salesVisit->user_id, $visitDate);
            $this->syncCollectiveLeaveFor($salesVisit->user, $visitDate);

            $this->audit->log(
                actorId: $actor->id,
                entity: 'sales_visits',
                recordId: $salesVisit->id,
                action: AuditAction::CORRECT_STATUS,
                oldValues: ['verification_status' => VerificationStatus::REJECTED->value],
                newValues: ['verification_status' => VerificationStatus::APPROVED->value],
                reason: trim((string) $validated['reason']),
            );
        });

        return response()->json(['visit' => $salesVisit->fresh()]);
    }

    public function reviewVisit(Request $request, SalesVisit $salesVisit): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($salesVisit->user_id === $actor->id) {
            abort(403, 'Dilarang memverifikasi kunjungan milik sendiri.');
        }

        if ($salesVisit->visit_status === VisitStatus::IN_PROGRESS) {
            throw ValidationException::withMessages([
                'visit' => ['Kunjungan masih berjalan. Tunggu Visit Out sebelum meninjau.'],
            ]);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'reject_reason' => ['nullable', 'string', 'max:500'],
            'visit_out_at' => ['nullable', 'date'],
        ]);

        $decision = VerificationStatus::from((string) $validated['decision']);
        if ($decision === VerificationStatus::REJECTED && trim((string) ($validated['reject_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reject_reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        $updates = [
            'verification_status' => $decision,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now('UTC'),
            'reject_reason' => $decision === VerificationStatus::REJECTED ? trim((string) $validated['reject_reason']) : null,
        ];

        if ($decision === VerificationStatus::APPROVED && $salesVisit->visit_out_at === null && ! empty($validated['visit_out_at'])) {
            $outAt = CarbonImmutable::parse((string) $validated['visit_out_at'])->utc();
            $updates['visit_out_at'] = $outAt;
            $updates['duration_minutes'] = $salesVisit->visit_in_at !== null
                ? (int) $salesVisit->visit_in_at->diffInMinutes($outAt)
                : null;
        }

        DB::transaction(function () use ($salesVisit, $updates): void {
            $salesVisit->update($updates);
            $this->syncCollectiveLeaveFor($salesVisit->user, $salesVisit->visit_date->toDateString());
        });

        if ($decision === VerificationStatus::REJECTED) {
            $owner = $salesVisit->user;
            Mail::to($owner->email)->queue(new AttendanceRejectedMail(
                employeeName: $owner->name,
                dateLabel: $salesVisit->visit_date->toDateString(),
                phaseLabel: 'Kunjungan Sales',
                rejectReason: trim((string) $validated['reject_reason']),
            ));
        }

        return response()->json(['visit' => $salesVisit->fresh()]);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.kind' => ['required', 'in:attendance,visit'],
            'items.*.id' => ['required', 'integer'],
            'items.*.phase' => ['nullable', 'in:clock_in,clock_out'],
        ]);

        $approved = 0;

        DB::transaction(function () use ($validated, $actor, &$approved): void {
            foreach ($validated['items'] as $item) {
                if ($item['kind'] === 'attendance') {
                    $log = AttendanceLog::query()->find((int) $item['id']);
                    if ($log === null || $log->user_id === $actor->id || $this->linkedDinasIsPending($log)) {
                        continue;
                    }
                    $phase = (string) ($item['phase'] ?? 'clock_in');
                    $flags = $phase === 'clock_in' ? ($log->clock_in_anomaly_flags ?? []) : ($log->clock_out_anomaly_flags ?? []);
                    if (array_intersect($flags, self::BULK_EXCLUDED_FLAGS) !== []) {
                        continue;
                    }
                    if ($phase === 'clock_in' && $log->clock_in_status === VerificationStatus::PENDING_REVIEW) {
                        $log->update([
                            'clock_in_status' => VerificationStatus::APPROVED,
                            'clock_in_reviewed_by' => $actor->id,
                            'clock_in_reviewed_at' => now('UTC'),
                        ]);
                        $this->syncCollectiveLeaveFor($log->user, $log->work_date->toDateString());
                        $approved++;
                    } elseif ($phase === 'clock_out' && $log->clock_out_status === VerificationStatus::PENDING_REVIEW) {
                        $log->update([
                            'clock_out_status' => VerificationStatus::APPROVED,
                            'clock_out_reviewed_by' => $actor->id,
                            'clock_out_reviewed_at' => now('UTC'),
                        ]);
                        $this->syncCollectiveLeaveFor($log->user, $log->work_date->toDateString());
                        $approved++;
                    }
                } else {
                    $visit = SalesVisit::query()->find((int) $item['id']);
                    if ($visit === null || $visit->user_id === $actor->id) {
                        continue;
                    }
                    if (array_intersect($visit->anomaly_flags ?? [], self::BULK_EXCLUDED_FLAGS) !== []) {
                        continue;
                    }
                    if ($visit->verification_status === VerificationStatus::PENDING_REVIEW) {
                        $visit->update([
                            'verification_status' => VerificationStatus::APPROVED,
                            'reviewed_by' => $actor->id,
                            'reviewed_at' => now('UTC'),
                        ]);
                        $this->syncCollectiveLeaveFor($visit->user, $visit->visit_date->toDateString());
                        $approved++;
                    }
                }
            }
        });

        return response()->json(['approved_count' => $approved]);
    }

    private function linkedDinasIsPending(AttendanceLog $log): bool
    {
        if ($log->leave_request_id === null) {
            return false;
        }

        return LeaveRequest::query()
            ->whereKey($log->leave_request_id)
            ->first()?->status === LeaveStatus::PENDING;
    }

    /** PRD US-008: any HR decision touching a COLLECTIVE_LEAVE date refreshes the yearly deduction. */
    private function syncCollectiveLeaveFor(User $user, string $date): void
    {
        $this->balanceService->syncCollectiveLeaveForYear(CarbonImmutable::parse($date)->year, $user);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function applyAttendanceReview(AttendanceLog $log, array $updates, bool $cancelPendingLeave = false): void
    {
        DB::transaction(function () use ($log, $updates, $cancelPendingLeave): void {
            if ($cancelPendingLeave) {
                // PRD US-008: presence wins, so a same-day pending CUTI/IZIN/SAKIT day is cancelled.
                $this->helper->cancelPendingLeaveDayOnPresence($log->user_id, $log->work_date->toDateString());
            }

            $log->update($updates);
            $this->syncCollectiveLeaveFor($log->user, $log->work_date->toDateString());
        });
    }

    public function storeManualAttendance(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $baseValidated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $targetUserId = (int) $baseValidated['user_id'];
        if ($targetUserId === $actor->id) {
            abort(403, 'Dilarang menginput absen manual untuk diri sendiri.');
        }

        $workDate = (string) $baseValidated['work_date'];
        $reason = trim((string) $baseValidated['reason']);

        /** @var User $targetUser */
        $targetUser = User::query()->with('officeLocation')->findOrFail($targetUserId);
        $tz = $targetUser->officeLocation->timezone;
        $todayLocal = CarbonImmutable::now('UTC')->setTimezone($tz)->toDateString();
        $minManualDate = CarbonImmutable::now('UTC')->setTimezone($tz)->subDays(6)->toDateString();

        if ($workDate < $minManualDate || $workDate > $todayLocal) {
            throw ValidationException::withMessages([
                'work_date' => ['Input manual HR hanya untuk 7 hari terakhir (termasuk hari ini).'],
            ]);
        }

        if ($targetUser->role === UserRole::EMPLOYEE_SALES) {
            $salesValidated = $request->validate([
                'outlet_location_id' => ['required', 'integer', 'exists:locations,id'],
                'visit_in_time' => ['nullable', 'date_format:H:i'],
                'clock_in_time' => ['nullable', 'date_format:H:i'],
                'visit_out_time' => ['nullable', 'date_format:H:i'],
                'clock_out_time' => ['nullable', 'date_format:H:i'],
                'activity_category_id' => ['nullable', 'integer', 'exists:activity_categories,id'],
            ]);

            $inTime = $salesValidated['visit_in_time'] ?? $salesValidated['clock_in_time'] ?? null;
            $outTime = $salesValidated['visit_out_time'] ?? $salesValidated['clock_out_time'] ?? null;

            if ($inTime === null) {
                throw ValidationException::withMessages([
                    'visit_in_time' => ['Jam mulai kunjungan wajib diisi.'],
                ]);
            }

            if ($outTime !== null && empty($salesValidated['activity_category_id'])) {
                throw ValidationException::withMessages([
                    'activity_category_id' => ['Kategori aktivitas wajib diisi bila jam selesai terisi.'],
                ]);
            }

            if ($this->scheduleResolver->hasApprovedTimeOff($targetUserId, $workDate)) {
                throw ValidationException::withMessages([
                    'work_date' => ['Terdapat pengajuan Cuti/Izin/Sakit APPROVED pada tanggal tersebut.'],
                ]);
            }

            /** @var Location $outlet */
            $outlet = Location::query()->findOrFail((int) $salesValidated['outlet_location_id']);
            if ($outlet->type !== LocationType::OUTLET) {
                throw ValidationException::withMessages([
                    'outlet_location_id' => ['Lokasi yang dipilih bukan outlet.'],
                ]);
            }

            $visitInAt = CarbonImmutable::parse("{$workDate} {$inTime}:00", $tz)->utc();
            $visitOutAt = $outTime !== null
                ? CarbonImmutable::parse("{$workDate} {$outTime}:00", $tz)->utc()
                : null;

            if ($visitOutAt !== null && $visitOutAt->lessThanOrEqualTo($visitInAt)) {
                throw ValidationException::withMessages([
                    'visit_out_time' => ['Jam selesai kunjungan harus setelah jam mulai.'],
                ]);
            }

            $durationMinutes = $visitOutAt !== null ? (int) $visitInAt->diffInMinutes($visitOutAt) : null;

            $visit = DB::transaction(function () use ($targetUser, $actor, $workDate, $outlet, $salesValidated, $visitInAt, $visitOutAt, $durationMinutes, $reason): SalesVisit {
                $this->helper->cancelPendingLeaveDayOnPresence($targetUser->id, $workDate);

                $created = SalesVisit::query()->create([
                    'user_id' => $targetUser->id,
                    'outlet_location_id' => $outlet->id,
                    'outlet_lat' => $outlet->latitude,
                    'outlet_lng' => $outlet->longitude,
                    'outlet_radius_m' => $outlet->radius_m,
                    'source' => AttendanceSource::MANUAL_HR,
                    'visit_date' => $workDate,
                    'visit_in_at' => $visitInAt,
                    'visit_in_notes' => $reason,
                    'visit_out_at' => $visitOutAt,
                    'duration_minutes' => $durationMinutes,
                    'activity_category_id' => $salesValidated['activity_category_id'] ?? null,
                    'visit_status' => $visitOutAt !== null ? VisitStatus::COMPLETED : VisitStatus::AUTO_CLOSED,
                    'verification_status' => VerificationStatus::APPROVED,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now('UTC'),
                ]);

                $this->syncCollectiveLeaveFor($targetUser, $workDate);

                $this->audit->log(
                    actorId: $actor->id,
                    entity: 'sales_visits',
                    recordId: $created->id,
                    action: AuditAction::MANUAL_ATTENDANCE,
                    newValues: [
                        'user_id' => $targetUser->id,
                        'visit_date' => $workDate,
                        'outlet_location_id' => $outlet->id,
                        'source' => AttendanceSource::MANUAL_HR->value,
                    ],
                    reason: $reason,
                );

                return $created;
            });

            return response()->json(['visit' => $visit], 201);
        }

        $validated = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'clock_in_time' => ['required', 'date_format:H:i'],
            'clock_out_time' => ['nullable', 'date_format:H:i'],
        ]);

        $hasActive = AttendanceLog::query()
            ->where('user_id', $targetUserId)
            ->whereDate('work_date', $workDate)
            ->where('is_active_attempt', 1)
            ->exists();

        if ($hasActive || $this->scheduleResolver->hasApprovedTimeOff($targetUserId, $workDate)) {
            throw ValidationException::withMessages([
                'work_date' => ['Sudah ada absensi aktif atau pengajuan Cuti/Izin/Sakit APPROVED pada tanggal tersebut.'],
            ]);
        }

        $clockInAt = CarbonImmutable::parse("{$workDate} {$validated['clock_in_time']}:00", $tz)->utc();
        $clockOutAt = ! empty($validated['clock_out_time'])
            ? CarbonImmutable::parse("{$workDate} {$validated['clock_out_time']}:00", $tz)->utc()
            : null;

        if ($clockOutAt !== null && $clockOutAt->lessThanOrEqualTo($clockInAt)) {
            throw ValidationException::withMessages([
                'clock_out_time' => ['Jam pulang harus setelah jam masuk.'],
            ]);
        }

        $log = DB::transaction(function () use ($targetUser, $actor, $workDate, $validated, $clockInAt, $clockOutAt, $reason): AttendanceLog {
            $this->helper->cancelPendingLeaveDayOnPresence($targetUser->id, $workDate);

            $created = AttendanceLog::query()->create([
                'user_id' => $targetUser->id,
                'location_id' => $targetUser->office_location_id,
                'shift_id' => $validated['shift_id'] ?? null,
                'source' => AttendanceSource::MANUAL_HR,
                'work_date' => $workDate,
                'attempt_no' => 1,
                'is_active_attempt' => 1,
                'clock_in_at' => $clockInAt,
                'clock_in_notes' => $reason,
                'clock_in_status' => VerificationStatus::APPROVED,
                'clock_in_reviewed_by' => $actor->id,
                'clock_in_reviewed_at' => now('UTC'),
                'clock_out_at' => $clockOutAt,
                'clock_out_status' => $clockOutAt !== null ? VerificationStatus::APPROVED : null,
                'clock_out_reviewed_by' => $clockOutAt !== null ? $actor->id : null,
                'clock_out_reviewed_at' => $clockOutAt !== null ? now('UTC') : null,
            ]);

            $this->syncCollectiveLeaveFor($targetUser, $workDate);

            $this->audit->log(
                actorId: $actor->id,
                entity: 'attendance_logs',
                recordId: $created->id,
                action: AuditAction::MANUAL_ATTENDANCE,
                newValues: ['user_id' => $targetUser->id, 'work_date' => $workDate, 'source' => AttendanceSource::MANUAL_HR->value],
                reason: $reason,
            );

            return $created;
        });

        return response()->json(['attendance' => $log], 201);
    }
}
