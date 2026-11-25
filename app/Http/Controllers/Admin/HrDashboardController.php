<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceSource;
use App\Enums\AuditAction;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\LocationStatus;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Mail\AttendanceRejectedMail;
use App\Models\ActivityCategory;
use App\Models\AttendanceLog;
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
            ->with(['officeLocation:id,name,timezone,work_days,latitude,longitude,radius_m'])
            ->where('is_active', true)
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
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', $selectedDate)
            ->get()
            ->keyBy('user_id');

        $leaveDaysByUser = LeaveRequestDay::query()
            ->with('leaveRequest:id,type')
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

        $dailyRecap = $users->map(function (User $u) use ($selectedDate, $schedulesByUser, $leaveDaysByUser, $logsByUser, $visitsByUser, $globalHoliday, $holidayLocationIds): array {
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
            } elseif ($pendingLeave !== null && $pendingLeave->leaveRequest !== null) {
                $status = 'Belum Absen (Pending '.$pendingLeave->leaveRequest->type->value.')';
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

        $pendingAttendance = AttendanceLog::query()
            ->with(['user:id,nik,name,reference_photo_url', 'location:id,name,latitude,longitude,radius_m,timezone', 'shift:id,code,end_time', 'leaveRequest:id,status'])
            ->where(function ($q): void {
                $q->where('clock_in_status', VerificationStatus::PENDING_REVIEW)
                    ->orWhere('clock_out_status', VerificationStatus::PENDING_REVIEW);
            })
            ->orderByRaw('CASE WHEN work_date = ? THEN 0 ELSE 1 END', [$selectedDate])
            ->orderByDesc('work_date')
            ->limit(100)
            ->get()
            ->map(function (AttendanceLog $log): array {
                $phase = $log->clock_in_status === VerificationStatus::PENDING_REVIEW ? 'clock_in' : 'clock_out';
                $flags = $phase === 'clock_in' ? ($log->clock_in_anomaly_flags ?? []) : ($log->clock_out_anomaly_flags ?? []);
                $photoPath = $phase === 'clock_in' ? $log->clock_in_photo_url : $log->clock_out_photo_url;

                return [
                    'kind' => 'attendance',
                    'id' => $log->id,
                    'phase' => $phase,
                    'user_id' => $log->user_id,
                    'nik' => $log->user->nik,
                    'name' => $log->user->name,
                    'date' => $log->work_date->toDateString(),
                    'location_name' => $log->location->name,
                    'target_lat' => (float) $log->location->latitude,
                    'target_lng' => (float) $log->location->longitude,
                    'radius_m' => $log->location->radius_m,
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
                ];
            });

        $pendingVisits = SalesVisit::query()
            ->with(['user:id,nik,name,reference_photo_url', 'outletLocation:id,name,latitude,longitude,radius_m,timezone'])
            ->where('verification_status', VerificationStatus::PENDING_REVIEW)
            ->orderByRaw('CASE WHEN visit_date = ? THEN 0 ELSE 1 END', [$selectedDate])
            ->orderByDesc('visit_date')
            ->limit(100)
            ->get()
            ->map(function (SalesVisit $visit): array {
                $flags = $visit->anomaly_flags ?? [];

                return [
                    'kind' => 'visit',
                    'id' => $visit->id,
                    'phase' => 'visit',
                    'user_id' => $visit->user_id,
                    'nik' => $visit->user->nik,
                    'name' => $visit->user->name,
                    'date' => $visit->visit_date->toDateString(),
                    'location_name' => $visit->outletLocation->name,
                    'target_lat' => (float) $visit->outletLocation->latitude,
                    'target_lng' => (float) $visit->outletLocation->longitude,
                    'radius_m' => $visit->outletLocation->radius_m,
                    'user_lat' => $visit->visit_in_lat !== null ? (float) $visit->visit_in_lat : null,
                    'user_lng' => $visit->visit_in_lng !== null ? (float) $visit->visit_in_lng : null,
                    'accuracy_m' => $visit->visit_in_accuracy_m !== null ? (float) $visit->visit_in_accuracy_m : null,
                    'flags' => $flags,
                    'notes' => $visit->visit_in_notes ?? $visit->visit_notes,
                    'photo_url' => $visit->visit_in_photo_url !== null ? $this->imageEvidence->signedUrl($visit->visit_in_photo_url) : null,
                    'reference_photo_url' => $visit->user->reference_photo_url !== null ? $this->imageEvidence->signedUrl($visit->user->reference_photo_url) : null,
                    'bulk_eligible' => array_intersect($flags, self::BULK_EXCLUDED_FLAGS) === [],
                    'review_locked' => false,
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
            'employees' => $users->map(fn (User $u): array => ['id' => $u->id, 'nik' => $u->nik, 'name' => $u->name, 'role' => $u->role->value]),
            'shifts' => Shift::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
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
            $attendanceLog->update($updates);
        } else {
            $updates = [
                'clock_out_status' => $decision,
                'clock_out_reviewed_by' => $actor->id,
                'clock_out_reviewed_at' => now('UTC'),
                'clock_out_reject_reason' => $decision === VerificationStatus::REJECTED ? trim((string) $validated['reject_reason']) : null,
            ];
            if ($decision === VerificationStatus::APPROVED && $attendanceLog->clock_out_at === null) {
                if (! empty($validated['clock_out_at'])) {
                    $updates['clock_out_at'] = CarbonImmutable::parse((string) $validated['clock_out_at'])->utc();
                } elseif ($attendanceLog->shift !== null) {
                    $tz = $attendanceLog->location->timezone;
                    $updates['clock_out_at'] = CarbonImmutable::parse(
                        "{$attendanceLog->work_date->toDateString()} {$attendanceLog->shift->end_time}",
                        $tz
                    )->utc();
                }
            }
            $attendanceLog->update($updates);
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
        ]);

        $phase = (string) $validated['phase'];
        $workDate = $attendanceLog->work_date->toDateString();

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

            $attendanceLog->update([
                'clock_in_status' => VerificationStatus::APPROVED,
                'is_active_attempt' => 1,
                'clock_in_reviewed_by' => $actor->id,
                'clock_in_reviewed_at' => now('UTC'),
            ]);
        } else {
            $attendanceLog->update([
                'clock_out_status' => VerificationStatus::APPROVED,
                'clock_out_reviewed_by' => $actor->id,
                'clock_out_reviewed_at' => now('UTC'),
            ]);
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'attendance_logs',
            recordId: $attendanceLog->id,
            action: AuditAction::CORRECT_STATUS,
            oldValues: [$phase => VerificationStatus::REJECTED->value],
            newValues: [$phase => VerificationStatus::APPROVED->value],
            reason: trim((string) $validated['reason']),
        );

        return response()->json(['attendance' => $attendanceLog->fresh()]);
    }

    public function reviewVisit(Request $request, SalesVisit $salesVisit): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($salesVisit->user_id === $actor->id) {
            abort(403, 'Dilarang memverifikasi kunjungan milik sendiri.');
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

        $salesVisit->update($updates);

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
                        $approved++;
                    } elseif ($phase === 'clock_out' && $log->clock_out_status === VerificationStatus::PENDING_REVIEW) {
                        $log->update([
                            'clock_out_status' => VerificationStatus::APPROVED,
                            'clock_out_reviewed_by' => $actor->id,
                            'clock_out_reviewed_at' => now('UTC'),
                        ]);
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

    public function storeManualAttendance(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'clock_in_time' => ['required', 'date_format:H:i'],
            'clock_out_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $targetUserId = (int) $validated['user_id'];
        if ($targetUserId === $actor->id) {
            abort(403, 'Dilarang menginput absen manual untuk diri sendiri.');
        }

        $workDate = (string) $validated['work_date'];

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

        $clockInAt = CarbonImmutable::parse("{$workDate} {$validated['clock_in_time']}:00", $tz)->utc();
        $clockOutAt = ! empty($validated['clock_out_time'])
            ? CarbonImmutable::parse("{$workDate} {$validated['clock_out_time']}:00", $tz)->utc()
            : null;

        if ($clockOutAt !== null && $clockOutAt->lessThanOrEqualTo($clockInAt)) {
            throw ValidationException::withMessages([
                'clock_out_time' => ['Jam pulang harus setelah jam masuk.'],
            ]);
        }

        $reason = trim((string) $validated['reason']);

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
