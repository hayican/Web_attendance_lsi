<?php

namespace App\Http\Controllers\Leave;

use App\Enums\AttendanceSource;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\SalesVisit;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\ImageEvidenceService;
use App\Services\LeaveBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly ImageEvidenceService $imageEvidence,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('officeLocation');

        $timezone = $user->officeLocation->timezone;
        $todayLocal = CarbonImmutable::now('UTC')->setTimezone($timezone)->startOfDay();
        $balance = $this->balanceService->ensureBalanceForYear($user, $todayLocal->year, $todayLocal);

        $requests = LeaveRequest::query()
            ->with(['days', 'reviewer:id,name'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(function (LeaveRequest $item): array {
                $arr = $item->toArray();
                $arr['attachment_url'] = $item->attachment_path !== null
                    ? $this->imageEvidence->signedUrl($item->attachment_path)
                    : null;

                return $arr;
            });

        return Inertia::render('leave/Index', [
            'balance' => [
                'year' => $balance->year,
                'base_quota' => (float) $balance->base_quota,
                'prorated_deduction' => (float) $balance->prorated_deduction,
                'collective_leave_deduction' => (float) $balance->collective_leave_deduction,
                'used_days' => (float) $balance->used_days,
                'pending_days' => (float) $balance->pending_days,
                'adjustment_days' => (float) $balance->adjustment_days,
                'available_balance' => $balance->availableBalance(),
                'eligible_for_annual_leave' => $this->balanceService->isEligibleForAnnualLeave($user, $todayLocal),
            ],
            'requests' => $requests,
            'todayDate' => $todayLocal->toDateString(),
            'canSubmitDinasLuar' => $user->role !== UserRole::EMPLOYEE_SALES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('officeLocation');

        $validated = $request->validate([
            'type' => ['required', Rule::enum(LeaveType::class)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'destination_location' => ['nullable', 'string', 'max:255'],
            'scheduled_start_time' => ['nullable', 'date_format:H:i'],
            'attachment' => ['nullable', 'file', 'max:2048'],
        ]);

        $type = LeaveType::from((string) $validated['type']);
        $timezone = $user->officeLocation->timezone;
        $todayLocal = CarbonImmutable::now('UTC')->setTimezone($timezone)->startOfDay();
        $startDate = CarbonImmutable::parse((string) $validated['start_date'], $timezone)->startOfDay();
        $endDate = CarbonImmutable::parse((string) $validated['end_date'], $timezone)->startOfDay();

        if ($type === LeaveType::DINAS_LUAR) {
            if ($user->role === UserRole::EMPLOYEE_SALES) {
                throw ValidationException::withMessages([
                    'type' => ['Tim sales tidak menggunakan pengajuan Dinas Luar.'],
                ]);
            }
            if ($startDate->lessThanOrEqualTo($todayLocal)) {
                throw ValidationException::withMessages([
                    'start_date' => ['Dinas Luar terjadwal wajib diajukan paling lambat H-1 sebelum tanggal dinas.'],
                ]);
            }
            if (trim((string) ($validated['destination_location'] ?? '')) === '' || empty($validated['scheduled_start_time'])) {
                throw ValidationException::withMessages([
                    'destination_location' => ['Lokasi tujuan dan jam mulai agenda dinas wajib diisi untuk Dinas Luar.'],
                ]);
            }
        }

        if ($type === LeaveType::CUTI) {
            if (! $this->balanceService->isEligibleForAnnualLeave($user, $todayLocal)) {
                throw ValidationException::withMessages([
                    'type' => ['Hak Cuti Tahunan baru aktif setelah masa kerja genap 1 tahun.'],
                ]);
            }
            if ($startDate->lessThan($todayLocal)) {
                throw ValidationException::withMessages([
                    'start_date' => ['Cuti Tahunan tidak dapat diajukan mundur (backdate).'],
                ]);
            }
        }

        if (in_array($type, [LeaveType::IZIN, LeaveType::SAKIT], true)) {
            $minBackdate = $this->findMinBackdateDate($user, $todayLocal, 2);
            if ($startDate->lessThan($minBackdate)) {
                throw ValidationException::withMessages([
                    'start_date' => ['Pengajuan susulan (backdate) maksimal H-2 hari kerja efektif.'],
                ]);
            }
        }

        if ($type === LeaveType::SAKIT && ! $request->hasFile('attachment')) {
            throw ValidationException::withMessages([
                'attachment' => ['Surat Keterangan Dokter (SKD) wajib dilampirkan untuk pengajuan Sakit.'],
            ]);
        }

        // Horizon 3 months / 90 days check
        if ($user->default_shift_pattern_id !== null) {
            $cursor = $startDate;
            while ($cursor->lessThanOrEqualTo($endDate)) {
                $exists = UserShiftSchedule::query()
                    ->where('user_id', $user->id)
                    ->whereDate('work_date', $cursor->toDateString())
                    ->exists();

                if (! $exists) {
                    throw ValidationException::withMessages([
                        'end_date' => ['Jadwal kerja untuk tanggal tersebut belum diterbitkan. Pengajuan hanya dapat dilakukan untuk tanggal yang jadwalnya sudah tersedia (maksimal 3 bulan ke depan).'],
                    ]);
                }
                $cursor = $cursor->addDay();
            }
        } elseif ($endDate->greaterThan($todayLocal->addDays(90))) {
            throw ValidationException::withMessages([
                'end_date' => ['Pengajuan maksimal 90 hari ke depan.'],
            ]);
        }

        // Build per-date breakdown & check overlaps
        /** @var list<array{date: string, year: int, month: string, value: float}> $dayRows */
        $dayRows = [];
        $totalDays = 0.0;
        /** @var array<int, float> $perYearCuti */
        $perYearCuti = [];

        $cursor = $startDate;
        while ($cursor->lessThanOrEqualTo($endDate)) {
            $dateStr = $cursor->toDateString();

            $overlapLeave = LeaveRequestDay::query()
                ->where('user_id', $user->id)
                ->whereDate('leave_date', $dateStr)
                ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
                ->exists();

            if ($overlapLeave) {
                throw ValidationException::withMessages([
                    'start_date' => ["Pengajuan gagal: Tanggal {$dateStr} sudah memiliki pengajuan Cuti/Izin/Sakit/Dinas Luar lain yang sedang diproses atau disetujui."],
                ]);
            }

            $hasActiveAttendance = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', $dateStr)
                ->where('is_active_attempt', 1)
                ->exists();

            $hasActiveVisit = SalesVisit::query()
                ->where('user_id', $user->id)
                ->whereDate('visit_date', $dateStr)
                ->where('visit_status', '!=', VisitStatus::CANCELLED)
                ->where('verification_status', '!=', VerificationStatus::REJECTED)
                ->exists();

            if ($hasActiveAttendance || $hasActiveVisit) {
                throw ValidationException::withMessages([
                    'start_date' => ["Pengajuan gagal: Tanggal {$dateStr} sudah memiliki riwayat kehadiran/kunjungan aktif."],
                ]);
            }

            if ($type === LeaveType::IZIN) {
                $hasRejectedAttendance = AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->whereDate('work_date', $dateStr)
                    ->where('clock_in_status', VerificationStatus::REJECTED)
                    ->where('source', AttendanceSource::APP)
                    ->exists();

                $hasRejectedVisit = SalesVisit::query()
                    ->where('user_id', $user->id)
                    ->whereDate('visit_date', $dateStr)
                    ->where('verification_status', VerificationStatus::REJECTED)
                    ->exists();

                if ($hasRejectedAttendance || $hasRejectedVisit) {
                    throw ValidationException::withMessages([
                        'start_date' => ["Pengajuan Izin backdate ditolak pada {$dateStr} karena ada absen/kunjungan REJECTED. Selesaikan lewat Koreksi Status HR."],
                    ]);
                }
            }

            $val = $this->balanceService->isEffectiveWorkDay($user, $dateStr) ? 1.0 : 0.0;
            $dayRows[] = [
                'date' => $dateStr,
                'year' => $cursor->year,
                'month' => $cursor->format('Y-m'),
                'value' => $val,
            ];
            $totalDays += $val;

            if ($type === LeaveType::CUTI && $val > 0.0) {
                $perYearCuti[$cursor->year] = ($perYearCuti[$cursor->year] ?? 0.0) + $val;
            }

            $cursor = $cursor->addDay();
        }

        if ($totalDays <= 0.0) {
            throw ValidationException::withMessages([
                'start_date' => ['Rentang tanggal yang dipilih seluruhnya jatuh pada hari libur atau jadwal OFF (0 hari kerja efektif).'],
            ]);
        }

        if ($type === LeaveType::IZIN && $totalDays > 2.0) {
            throw ValidationException::withMessages([
                'end_date' => ['Pengajuan Izin maksimal 2 hari kerja efektif.'],
            ]);
        }

        $monthlyLimitWarning = false;
        if ($type === LeaveType::CUTI) {
            foreach ($perYearCuti as $yr => $needed) {
                $bal = $this->balanceService->ensureBalanceForYear($user, $yr, $todayLocal);
                if ($bal->availableBalance() < $needed) {
                    throw ValidationException::withMessages([
                        'start_date' => ["Saldo cuti tahun {$yr} tidak mencukupi (tersedia {$bal->availableBalance()} hari, dibutuhkan {$needed} hari)."],
                    ]);
                }
            }

            $monthlyLimitWarning = $this->exceedsMonthlyLeaveLimit($user->id, $dayRows);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            /** @var UploadedFile $file */
            $file = $request->file('attachment');
            $attachmentPath = $this->imageEvidence->storeLeaveAttachment($file, $user->id);
        }

        $leaveRequest = DB::transaction(function () use (
            $user,
            $type,
            $startDate,
            $endDate,
            $totalDays,
            $validated,
            $attachmentPath,
            $dayRows,
            $perYearCuti,
            $todayLocal,
        ): LeaveRequest {
            $header = LeaveRequest::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'total_days' => $totalDays,
                'destination_location' => $type === LeaveType::DINAS_LUAR ? trim((string) $validated['destination_location']) : null,
                'scheduled_start_time' => $type === LeaveType::DINAS_LUAR ? ((string) $validated['scheduled_start_time']).':00' : null,
                'reason' => trim((string) $validated['reason']),
                'attachment_path' => $attachmentPath,
                'status' => LeaveStatus::PENDING,
            ]);

            foreach ($dayRows as $row) {
                LeaveRequestDay::query()->create([
                    'leave_request_id' => $header->id,
                    'user_id' => $user->id,
                    'leave_date' => $row['date'],
                    'day_value' => $row['value'],
                    'status' => LeaveDayStatus::PENDING,
                ]);
            }

            if ($type === LeaveType::CUTI) {
                foreach ($perYearCuti as $yr => $needed) {
                    $bal = $this->balanceService->ensureBalanceForYear($user, $yr, $todayLocal);
                    $bal->update([
                        'pending_days' => (float) $bal->pending_days + $needed,
                    ]);
                }
            }

            return $header;
        });

        $hrConflictWarning = false;
        if ($user->role === UserRole::HR_ADMIN && in_array($type, [LeaveType::CUTI, LeaveType::IZIN, LeaveType::DINAS_LUAR], true)) {
            $activeHrCount = User::query()
                ->where('role', UserRole::HR_ADMIN)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            $otherHrOnLeave = LeaveRequestDay::query()
                ->where('user_id', '!=', $user->id)
                ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
                ->whereBetween('leave_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->whereHas('user', fn ($q) => $q->where('role', UserRole::HR_ADMIN)->where('is_active', true))
                ->distinct('user_id')
                ->count('user_id');

            $hrConflictWarning = ($activeHrCount - $otherHrOnLeave) < 2;
        }

        return response()->json([
            'leave_request' => $leaveRequest->load('days'),
            'monthly_limit_warning' => $monthlyLimitWarning,
            'hr_conflict_warning' => $hrConflictWarning,
        ], 201);
    }

    public function cancelOwn(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($leaveRequest->user_id !== $user->id) {
            abort(403);
        }

        if ($leaveRequest->status !== LeaveStatus::PENDING) {
            throw ValidationException::withMessages([
                'leave' => ['Hanya pengajuan berstatus PENDING yang dapat dibatalkan sendiri.'],
            ]);
        }

        DB::transaction(function () use ($leaveRequest, $user): void {
            $pendingDays = $leaveRequest->days()
                ->where('status', LeaveDayStatus::PENDING)
                ->get();

            if ($leaveRequest->type === LeaveType::CUTI) {
                foreach ($pendingDays->groupBy(fn (LeaveRequestDay $d): int => (int) $d->leave_date->year) as $year => $days) {
                    $sum = (float) $days->sum(fn (LeaveRequestDay $d): float => (float) $d->day_value);
                    $bal = LeaveBalance::query()
                        ->where('user_id', $user->id)
                        ->where('year', (int) $year)
                        ->first();
                    if ($bal !== null && $sum > 0.0) {
                        $bal->update(['pending_days' => max(0.0, (float) $bal->pending_days - $sum)]);
                    }
                }
            }

            $leaveRequest->days()
                ->where('status', LeaveDayStatus::PENDING)
                ->update([
                    'status' => LeaveDayStatus::CANCELLED,
                    'cancelled_by' => $user->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'Dibatalkan oleh pemohon',
                ]);

            $leaveRequest->update(['status' => LeaveStatus::CANCELLED]);
        });

        return response()->json(['leave_request' => $leaveRequest->fresh('days')]);
    }

    private function findMinBackdateDate(User $user, CarbonImmutable $todayLocal, int $effectiveWorkDays): CarbonImmutable
    {
        $cursor = $todayLocal;
        $counted = 0;

        for ($i = 0; $i < 20 && $counted < $effectiveWorkDays; $i++) {
            $cursor = $cursor->subDay();
            if ($this->balanceService->isEffectiveWorkDay($user, $cursor->toDateString())) {
                $counted++;
            }
        }

        return $cursor;
    }

    /**
     * @param  list<array{date: string, year: int, month: string, value: float}>  $dayRows
     */
    private function exceedsMonthlyLeaveLimit(int $userId, array $dayRows): bool
    {
        /** @var array<string, float> $byMonth */
        $byMonth = [];
        foreach ($dayRows as $row) {
            if ($row['value'] > 0.0) {
                $byMonth[$row['month']] = ($byMonth[$row['month']] ?? 0.0) + $row['value'];
            }
        }

        foreach ($byMonth as $month => $requestedDays) {
            $monthStart = "{$month}-01";
            $monthEnd = CarbonImmutable::parse($monthStart)->endOfMonth()->toDateString();

            $existingInMonth = (float) LeaveRequestDay::query()
                ->where('user_id', $userId)
                ->whereBetween('leave_date', [$monthStart, $monthEnd])
                ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
                ->whereHas('leaveRequest', fn ($q) => $q->where('type', LeaveType::CUTI))
                ->sum('day_value');

            if (($existingInMonth + $requestedDays) > 2.0) {
                return true;
            }
        }

        return false;
    }
}
