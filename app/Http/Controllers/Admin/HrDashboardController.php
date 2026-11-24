<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceSource;
use App\Enums\AuditAction;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Mail\AttendanceRejectedMail;
use App\Models\AttendanceLog;
use App\Models\LeaveRequestDay;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceHelperService;
use App\Services\AuditLogger;
use App\Services\ImageEvidenceService;
use App\Services\ScheduleResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ImageEvidenceService $imageEvidence,
        private readonly ScheduleResolverService $scheduleResolver,
        private readonly AttendanceHelperService $helper,
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

        $dailyRecap = $users->map(function (User $u) use ($selectedDate): array {
            $tz = $u->officeLocation->timezone;
            $nowLocal = CarbonImmutable::now('UTC')->setTimezone($tz);
            $todayLocal = $nowLocal->toDateString();

            $approvedLeave = LeaveRequestDay::query()
                ->with('leaveRequest:id,type')
                ->where('user_id', $u->id)
                ->whereDate('leave_date', $selectedDate)
                ->where('status', LeaveDayStatus::APPROVED)
                ->first();

            $pendingLeave = LeaveRequestDay::query()
                ->with('leaveRequest:id,type')
                ->where('user_id', $u->id)
                ->whereDate('leave_date', $selectedDate)
                ->where('status', LeaveDayStatus::PENDING)
                ->first();

            if ($u->role === UserRole::EMPLOYEE_SALES) {
                $visits = SalesVisit::query()
                    ->where('user_id', $u->id)
                    ->whereDate('visit_date', $selectedDate)
                    ->orderBy('visit_in_at')
                    ->get();

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
                ];
            }

            $activeLog = AttendanceLog::query()
                ->with(['shift:id,code,end_time'])
                ->where('user_id', $u->id)
                ->whereDate('work_date', $selectedDate)
                ->where('is_active_attempt', 1)
                ->first();

            $lastRejectedLog = $activeLog === null
                ? AttendanceLog::query()
                    ->where('user_id', $u->id)
                    ->whereDate('work_date', $selectedDate)
                    ->where('clock_in_status', VerificationStatus::REJECTED)
                    ->latest('attempt_no')
                    ->first()
                : null;

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
            } elseif ($selectedDate < $todayLocal) {
                $status = $lastRejectedLog !== null ? 'Alpa (Ditolak HR)' : 'Alpa';
            } elseif ($pendingLeave !== null && $pendingLeave->leaveRequest !== null) {
                $status = 'Belum Absen (Pending '.$pendingLeave->leaveRequest->type->value.')';
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
            ];
        })->values();

        $pendingAttendance = AttendanceLog::query()
            ->with(['user:id,nik,name,reference_photo_url', 'location:id,name,latitude,longitude,radius_m,timezone', 'shift:id,code,end_time'])
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
                ];
            });

        return Inertia::render('admin/Dashboard', [
            'selectedDate' => $selectedDate,
            'showSingleHrBanner' => $activeHrCount < 2,
            'dailyRecap' => $dailyRecap,
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
                    if ($log === null || $log->user_id === $actor->id) {
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
        $clockInAt = CarbonImmutable::parse("{$workDate} {$validated['clock_in_time']}:00", $tz)->utc();
        $clockOutAt = ! empty($validated['clock_out_time'])
            ? CarbonImmutable::parse("{$workDate} {$validated['clock_out_time']}:00", $tz)->utc()
            : null;
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
