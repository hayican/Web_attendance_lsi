<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityCategory;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\TalentaMapping;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\AuditLogger;
use App\Services\CsvSanitizer;
use App\Services\LeaveBalanceService;
use App\Services\LoginThrottleService;
use App\Services\ShiftScheduleGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MasterDataController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LeaveBalanceService $balanceService,
        private readonly ShiftScheduleGeneratorService $scheduleGenerator,
        private readonly LoginThrottleService $loginThrottle,
        private readonly CsvSanitizer $csvSanitizer,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/MasterData', [
            'users' => User::query()->with(['officeLocation:id,name', 'defaultShiftPattern:id,name'])->orderBy('name')->get(),
            'locations' => Location::query()->orderBy('type')->orderBy('name')->get(),
            'shifts' => Shift::query()->orderBy('code')->get(),
            'patterns' => ShiftPattern::query()->orderBy('name')->get(),
            'holidays' => Holiday::query()->with('location:id,name')->orderByDesc('holiday_date')->get(),
            'categories' => ActivityCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function talentaMappingIndex(): Response
    {
        return Inertia::render('admin/TalentaMapping', [
            'mappings' => TalentaMapping::query()->orderBy('mapping_key')->get(),
        ]);
    }

    public function updateTalentaMapping(Request $request, TalentaMapping $talentaMapping): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'mapping_value' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $old = ['mapping_value' => $talentaMapping->mapping_value, 'description' => $talentaMapping->description];
        $talentaMapping->update([
            'mapping_value' => trim((string) $validated['mapping_value']),
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : $talentaMapping->description,
            'updated_by' => $actor->id,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'talenta_mappings',
            recordId: $talentaMapping->id,
            action: AuditAction::UPDATE,
            oldValues: $old,
            newValues: ['mapping_value' => $talentaMapping->mapping_value, 'description' => $talentaMapping->description],
        );

        return response()->json(['mapping' => $talentaMapping->fresh()]);
    }

    public function auditLogsIndex(): Response
    {
        return Inertia::render('admin/AuditLogs', [
            'logs' => AuditLog::query()->with('actor:id,nik,name')->latest('id')->limit(200)->get(),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'nik' => ['required', 'string', 'max:50', 'unique:users,nik'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'office_location_id' => ['required', 'integer', 'exists:locations,id'],
            'default_shift_pattern_id' => ['nullable', 'integer', 'exists:shift_patterns,id'],
            'join_date' => ['required', 'date'],
            'organization' => ['nullable', 'string', 'max:150'],
            'job_position' => ['nullable', 'string', 'max:150'],
            'job_level' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:50'],
        ]);

        $user = DB::transaction(function () use ($validated, $actor): User {
            $created = User::query()->create([
                'nik' => strtoupper(trim((string) $validated['nik'])),
                'email' => mb_strtolower(trim((string) $validated['email'])),
                'name' => trim((string) $validated['name']),
                'password' => Hash::make((string) $validated['password']),
                'must_change_password' => true,
                'role' => UserRole::from((string) $validated['role']),
                'office_location_id' => (int) $validated['office_location_id'],
                'default_shift_pattern_id' => isset($validated['default_shift_pattern_id']) ? (int) $validated['default_shift_pattern_id'] : null,
                'join_date' => (string) $validated['join_date'],
                'organization' => $validated['organization'] ?? null,
                'job_position' => $validated['job_position'] ?? null,
                'job_level' => $validated['job_level'] ?? null,
                'employment_status' => $validated['employment_status'] ?? null,
                'is_active' => true,
                'email_verified_at' => now('UTC'),
            ]);

            $this->balanceService->ensureBalanceForYear($created, (int) now('UTC')->year, CarbonImmutable::now('UTC'));
            $this->scheduleGenerator->generateForUser($created);

            $this->audit->log(
                actorId: $actor->id,
                entity: 'users',
                recordId: $created->id,
                action: AuditAction::CREATE,
                newValues: ['nik' => $created->nik, 'role' => $created->role->value, 'office_location_id' => $created->office_location_id],
            );

            return $created;
        });

        return response()->json(['user' => $user], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'office_location_id' => ['sometimes', 'integer', 'exists:locations,id'],
            'default_shift_pattern_id' => ['nullable', 'integer', 'exists:shift_patterns,id'],
            'join_date' => ['sometimes', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'organization' => ['nullable', 'string', 'max:150'],
            'job_position' => ['nullable', 'string', 'max:150'],
            'job_level' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:50'],
        ]);

        $isSelf = $user->id === $actor->id;
        if ($isSelf && (isset($validated['role']) || isset($validated['join_date']) || array_key_exists('is_active', $validated))) {
            abort(403, 'Dilarang mengubah role, tanggal masuk, atau status aktif akun sendiri.');
        }

        $newRole = isset($validated['role']) ? UserRole::from((string) $validated['role']) : $user->role;
        $newActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $user->is_active;

        // Protect minimum 2 active HR_ADMINs
        if ($user->role === UserRole::HR_ADMIN && ($newRole !== UserRole::HR_ADMIN || ! $newActive)) {
            $remainingHr = User::query()
                ->where('role', UserRole::HR_ADMIN)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($remainingHr < 2) {
                throw ValidationException::withMessages([
                    'role' => ['Gagal mengubah status akun. Sistem mewajibkan minimal 2 HR Admin aktif agar proses saling verifikasi (peer review) tetap berjalan. Silakan tunjuk atau aktifkan HR Admin pengganti terlebih dahulu.'],
                ]);
            }
        }

        // Prevent role change while active today's session exists
        if ($newRole !== $user->role) {
            $hasOpenVisit = SalesVisit::query()
                ->where('user_id', $user->id)
                ->where('visit_status', VisitStatus::IN_PROGRESS)
                ->exists();

            $hasOpenAttendance = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->where('is_active_attempt', 1)
                ->whereNull('clock_out_at')
                ->exists();

            if ($hasOpenVisit || $hasOpenAttendance) {
                throw ValidationException::withMessages([
                    'role' => ['Tidak dapat mengubah role saat karyawan masih memiliki sesi absensi atau kunjungan berjalan.'],
                ]);
            }
        }

        $oldValues = [
            'role' => $user->role->value,
            'office_location_id' => $user->office_location_id,
            'join_date' => $user->join_date->toDateString(),
            'is_active' => $user->is_active,
        ];

        DB::transaction(function () use ($user, $validated, $actor, $oldValues, $newActive): void {
            $patternChanged = array_key_exists('default_shift_pattern_id', $validated)
                && $validated['default_shift_pattern_id'] !== $user->default_shift_pattern_id;
            $joinDateChanged = isset($validated['join_date'])
                && $validated['join_date'] !== $user->join_date->toDateString();

            $user->update($validated);

            if ($joinDateChanged) {
                $this->balanceService->ensureBalanceForYear($user->fresh(), (int) now('UTC')->year, CarbonImmutable::now('UTC'));
            }

            if ($patternChanged) {
                $this->scheduleGenerator->generateForUser($user->fresh());
            }

            if (! $newActive) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            $this->audit->log(
                actorId: $actor->id,
                entity: 'users',
                recordId: $user->id,
                action: $newActive ? AuditAction::UPDATE : AuditAction::SOFT_DELETE,
                oldValues: $oldValues,
                newValues: [
                    'role' => $user->role->value,
                    'office_location_id' => $user->office_location_id,
                    'join_date' => $user->join_date->toDateString(),
                    'is_active' => $user->is_active,
                ],
            );
        });

        return response()->json(['user' => $user->fresh()]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($user->id === $actor->id) {
            abort(403, 'Dilarang mereset password akun sendiri lewat panel admin.');
        }

        $validated = $request->validate([
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        $user->update([
            'password' => Hash::make((string) $validated['new_password']),
            'must_change_password' => true,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'users',
            recordId: $user->id,
            action: AuditAction::RESET_PASSWORD,
            newValues: ['must_change_password' => true],
        );

        return response()->json(['message' => 'Password direset. Karyawan wajib mengganti password saat login berikutnya.']);
    }

    public function unlockUser(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($user->id === $actor->id) {
            abort(403, 'Dilarang membuka kunci akun sendiri.');
        }

        $newVersion = $this->loginThrottle->unlockUser($user->id);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'users',
            recordId: $user->id,
            action: AuditAction::UNLOCK_ACCOUNT,
            newValues: ['user_lock_version' => $newVersion],
        );

        return response()->json(['lock_version' => $newVersion]);
    }

    public function adjustLeaveBalance(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($user->id === $actor->id) {
            abort(403, 'Dilarang menyesuaikan saldo cuti akun sendiri.');
        }

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2024', 'max:2100'],
            'adjustment_days' => ['required', 'numeric', 'between:-30,30'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $balance = $this->balanceService->ensureBalanceForYear($user, (int) $validated['year']);
        $oldAdjustment = (float) $balance->adjustment_days;

        $balance->update(['adjustment_days' => (float) $validated['adjustment_days']]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'leave_balances',
            recordId: $balance->id,
            action: AuditAction::UPDATE,
            oldValues: ['adjustment_days' => $oldAdjustment],
            newValues: ['adjustment_days' => (float) $balance->adjustment_days],
            reason: trim((string) $validated['reason']),
        );

        return response()->json(['leave_balance' => $balance->fresh()]);
    }

    public function importLeaveBalancesCsv(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2024', 'max:2100'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.nik' => ['required', 'string'],
            'rows.*.adjustment_days' => ['required', 'numeric', 'between:-30,30'],
        ]);

        $year = (int) $validated['year'];
        $updated = 0;

        DB::transaction(function () use ($validated, $actor, $year, &$updated): void {
            foreach ($validated['rows'] as $row) {
                $nikRaw = (string) $row['nik'];
                if ($this->csvSanitizer->hasFormulaPrefix($nikRaw)) {
                    throw ValidationException::withMessages([
                        'rows' => ["Karakter formula berbahaya terdeteksi pada NIK: {$nikRaw}"],
                    ]);
                }

                $target = User::query()->where('nik', strtoupper(trim($nikRaw)))->first();
                if ($target === null || $target->id === $actor->id) {
                    continue;
                }

                $bal = $this->balanceService->ensureBalanceForYear($target, $year);
                $old = (float) $bal->adjustment_days;
                $bal->update(['adjustment_days' => (float) $row['adjustment_days']]);

                $this->audit->log(
                    actorId: $actor->id,
                    entity: 'leave_balances',
                    recordId: $bal->id,
                    action: AuditAction::BULK_IMPORT,
                    oldValues: ['adjustment_days' => $old],
                    newValues: ['adjustment_days' => (float) $row['adjustment_days']],
                    reason: 'Bulk import saldo awal cuti Talenta',
                );
                $updated++;
            }
        });

        return response()->json(['updated_count' => $updated]);
    }

    public function overrideSchedule(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($user->id === $actor->id) {
            abort(403, 'Dilarang mengubah jadwal milik sendiri.');
        }

        $validated = $request->validate([
            'work_date' => ['required', 'date'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'is_day_off' => ['required', 'boolean'],
        ]);

        $timezone = $user->officeLocation->timezone ?? 'Asia/Jakarta';
        $todayLocal = CarbonImmutable::now('UTC')->setTimezone($timezone)->toDateString();
        $targetDate = (string) $validated['work_date'];

        if ($targetDate < $todayLocal) {
            throw ValidationException::withMessages([
                'work_date' => ['Override jadwal hanya diperbolehkan untuk tanggal >= hari ini.'],
            ]);
        }

        if ($targetDate === $todayLocal) {
            $hasActiveToday = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', $targetDate)
                ->where('is_active_attempt', 1)
                ->exists();

            if ($hasActiveToday) {
                throw ValidationException::withMessages([
                    'work_date' => ['Tidak dapat override jadwal hari ini karena karyawan sudah memiliki absensi aktif.'],
                ]);
            }
        }

        $schedule = DB::transaction(function () use ($user, $actor, $targetDate, $validated): UserShiftSchedule {
            $existing = UserShiftSchedule::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', $targetDate)
                ->first();

            $old = $existing?->only(['shift_id', 'is_day_off', 'source']);

            $saved = UserShiftSchedule::query()->updateOrCreate(
                ['user_id' => $user->id, 'work_date' => $targetDate],
                [
                    'shift_id' => (bool) $validated['is_day_off'] ? null : ($validated['shift_id'] ?? null),
                    'is_day_off' => (bool) $validated['is_day_off'],
                    'source' => ScheduleSource::MANUAL_OVERRIDE,
                    'created_by' => $actor->id,
                ]
            );

            $this->balanceService->recalculateLeaveRequestDays($targetDate, $user->id);

            $this->audit->log(
                actorId: $actor->id,
                entity: 'user_shift_schedules',
                recordId: $saved->id,
                action: $existing !== null ? AuditAction::UPDATE : AuditAction::CREATE,
                oldValues: $old,
                newValues: $saved->only(['shift_id', 'is_day_off', 'source']),
            );

            return $saved;
        });

        return response()->json(['schedule' => $schedule]);
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'holiday_date' => ['required', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'description' => ['required', 'string', 'max:255'],
            'holiday_type' => ['required', Rule::enum(HolidayType::class)],
        ]);

        $type = HolidayType::from((string) $validated['holiday_type']);
        $locationId = $type === HolidayType::LOCAL ? ($validated['location_id'] ?? null) : null;

        if ($type === HolidayType::LOCAL && $locationId === null) {
            throw ValidationException::withMessages([
                'location_id' => ['Libur lokal wajib memilih cabang tertentu.'],
            ]);
        }

        $duplicate = Holiday::query()
            ->whereDate('holiday_date', (string) $validated['holiday_date'])
            ->when(
                $locationId === null,
                fn ($q) => $q->whereNull('location_id'),
                fn ($q) => $q->where('location_id', $locationId)
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'holiday_date' => ['Tanggal libur untuk cakupan lokasi tersebut sudah terdaftar.'],
            ]);
        }

        $holiday = DB::transaction(function () use ($validated, $type, $locationId, $actor): Holiday {
            $created = Holiday::query()->create([
                'holiday_date' => (string) $validated['holiday_date'],
                'location_id' => $locationId,
                'description' => trim((string) $validated['description']),
                'holiday_type' => $type,
            ]);

            $todayStr = CarbonImmutable::now('UTC')->toDateString();
            if ($created->holiday_date->toDateString() >= $todayStr) {
                $this->balanceService->recalculateLeaveRequestDays($created->holiday_date->toDateString());
            }

            if ($type === HolidayType::COLLECTIVE_LEAVE) {
                $this->balanceService->syncCollectiveLeaveForYear((int) $created->holiday_date->year);
            }

            $this->audit->log(
                actorId: $actor->id,
                entity: 'holidays',
                recordId: $created->id,
                action: AuditAction::CREATE,
                newValues: $created->only(['holiday_date', 'location_id', 'description', 'holiday_type']),
            );

            return $created;
        });

        return response()->json(['holiday' => $holiday], 201);
    }

    public function verifyNooOutlet(Request $request, Location $location): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($location->type !== LocationType::OUTLET) {
            abort(422, 'Lokasi bukan tipe OUTLET.');
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([LocationStatus::ACTIVE->value, LocationStatus::INACTIVE->value])],
            'code' => ['nullable', 'string', 'max:50'],
            'radius_m' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'reject_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = LocationStatus::from((string) $validated['status']);
        if ($newStatus === LocationStatus::INACTIVE && trim((string) ($validated['reject_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reject_reason' => ['Alasan penolakan wajib diisi saat menolak toko NOO.'],
            ]);
        }

        $old = ['status' => $location->status->value, 'code' => $location->code];
        $location->update([
            'status' => $newStatus,
            'code' => isset($validated['code']) ? strtoupper(trim((string) $validated['code'])) : ($location->code ?? 'OUT-'.strtoupper(Str::random(5))),
            'radius_m' => (int) ($validated['radius_m'] ?? $location->radius_m),
            'reject_reason' => $newStatus === LocationStatus::INACTIVE ? trim((string) $validated['reject_reason']) : null,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'locations',
            recordId: $location->id,
            action: AuditAction::UPDATE,
            oldValues: $old,
            newValues: ['status' => $location->status->value, 'code' => $location->code],
            reason: $location->reject_reason,
        );

        return response()->json(['location' => $location->fresh()]);
    }

    public function deactivateLocation(Request $request, Location $location): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($location->type === LocationType::OFFICE) {
            $hasActiveUsers = User::query()
                ->where('office_location_id', $location->id)
                ->where('is_active', true)
                ->exists();

            if ($hasActiveUsers) {
                throw ValidationException::withMessages([
                    'location' => ['Tidak dapat menonaktifkan kantor yang masih memiliki karyawan aktif.'],
                ]);
            }
        }

        $old = ['status' => $location->status->value];
        $location->update(['status' => LocationStatus::INACTIVE]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'locations',
            recordId: $location->id,
            action: AuditAction::SOFT_DELETE,
            oldValues: $old,
            newValues: ['status' => LocationStatus::INACTIVE->value],
        );

        return response()->json(['location' => $location->fresh()]);
    }

    public function deactivateShift(Request $request, Shift $shift): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $usedInPattern = ShiftPattern::query()
            ->where('is_active', true)
            ->where(function ($q) use ($shift): void {
                $q->where('mon_shift_id', $shift->id)
                    ->orWhere('tue_shift_id', $shift->id)
                    ->orWhere('wed_shift_id', $shift->id)
                    ->orWhere('thu_shift_id', $shift->id)
                    ->orWhere('fri_shift_id', $shift->id)
                    ->orWhere('sat_shift_id', $shift->id)
                    ->orWhere('sun_shift_id', $shift->id);
            })
            ->exists();

        $usedInFutureSchedule = UserShiftSchedule::query()
            ->where('shift_id', $shift->id)
            ->whereDate('work_date', '>=', CarbonImmutable::now('UTC')->toDateString())
            ->exists();

        if ($usedInPattern || $usedInFutureSchedule) {
            throw ValidationException::withMessages([
                'shift' => ['Shift masih dipakai di pola aktif atau jadwal mendatang. Alihkan terlebih dahulu sebelum menonaktifkan.'],
            ]);
        }

        $shift->update(['is_active' => false]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'shifts',
            recordId: $shift->id,
            action: AuditAction::SOFT_DELETE,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
        );

        return response()->json(['shift' => $shift->fresh()]);
    }
}
