<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityCategory;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\TalentaMapping;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\AuditLogger;
use App\Services\CsvSanitizer;
use App\Services\ImageEvidenceService;
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
        private readonly ImageEvidenceService $imageEvidence,
    ) {}

    public function index(): Response
    {
        $currentYear = (int) CarbonImmutable::now('Asia/Jakarta')->year;
        $activeHrCount = User::query()
            ->where('role', UserRole::HR_ADMIN)
            ->where('is_active', true)
            ->count();

        $users = User::query()
            ->with([
                'officeLocation:id,name,timezone',
                'defaultShiftPattern:id,name',
                'leaveBalances' => fn ($q) => $q->where('year', $currentYear),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $u): array {
                $arr = $u->toArray();
                $arr['join_date'] = $u->join_date->toDateString();
                $arr['reference_photo_signed_url'] = $u->reference_photo_url !== null
                    ? $this->imageEvidence->signedUrl($u->reference_photo_url)
                    : null;

                return $arr;
            });

        return Inertia::render('admin/MasterData', [
            'currentYear' => $currentYear,
            'activeHrCount' => $activeHrCount,
            'users' => $users,
            'locations' => Location::query()->orderBy('type')->orderBy('name')->get(),
            'shifts' => Shift::query()->orderBy('code')->get(),
            'patterns' => ShiftPattern::query()->orderBy('name')->get(),
            'holidays' => Holiday::query()->with('location:id,name')->orderByDesc('holiday_date')->get(),
            'categories' => ActivityCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function talentaMappingIndex(): Response
    {
        TalentaMapping::ensureDefaults();

        return Inertia::render('admin/TalentaMapping', [
            'mappings' => TalentaMapping::query()
                ->with('updater:id,nik,name')
                ->orderBy('mapping_key')
                ->get(),
        ]);
    }

    public function updateTalentaMapping(Request $request, TalentaMapping $talentaMapping): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $isBooleanOption = in_array($talentaMapping->mapping_key, TalentaMapping::BOOLEAN_KEYS, true);

        $validated = $request->validate([
            'mapping_value' => $isBooleanOption
                ? ['required', 'string', Rule::in(['true', 'false'])]
                : ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $newValue = trim((string) $validated['mapping_value']);
        if (! $isBooleanOption && $this->csvSanitizer->hasFormulaPrefix($newValue)) {
            throw ValidationException::withMessages([
                'mapping_value' => ['Kode mapping tidak boleh diawali karakter formula (=, +, -, @).'],
            ]);
        }

        $old = ['mapping_value' => $talentaMapping->mapping_value, 'description' => $talentaMapping->description];
        $talentaMapping->update([
            'mapping_value' => $newValue,
            'description' => array_key_exists('description', $validated) && $validated['description'] !== null
                ? trim((string) $validated['description'])
                : $talentaMapping->description,
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

        return response()->json(['mapping' => $talentaMapping->fresh('updater:id,nik,name')]);
    }

    public function auditLogsIndex(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role !== UserRole::HR_ADMIN && ! $user->can_view_audit_logs) {
            abort(403, 'Anda tidak berwenang melihat riwayat audit.');
        }

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
            'reference_photo' => ['nullable', 'file', 'max:2048'],
        ]);

        $role = UserRole::from((string) $validated['role']);
        $patternId = $role === UserRole::EMPLOYEE_SALES
            ? null
            : (isset($validated['default_shift_pattern_id']) ? (int) $validated['default_shift_pattern_id'] : null);

        $user = DB::transaction(function () use ($request, $validated, $actor, $role, $patternId): User {
            $created = User::query()->create([
                'nik' => strtoupper(trim((string) $validated['nik'])),
                'email' => mb_strtolower(trim((string) $validated['email'])),
                'name' => trim((string) $validated['name']),
                'password' => Hash::make((string) $validated['password']),
                'must_change_password' => true,
                'role' => $role,
                'office_location_id' => (int) $validated['office_location_id'],
                'default_shift_pattern_id' => $patternId,
                'join_date' => (string) $validated['join_date'],
                'organization' => $validated['organization'] ?? null,
                'job_position' => $validated['job_position'] ?? null,
                'job_level' => $validated['job_level'] ?? null,
                'employment_status' => $validated['employment_status'] ?? null,
                'is_active' => true,
                'email_verified_at' => now('UTC'),
            ]);

            if ($request->hasFile('reference_photo')) {
                $photoPath = $this->imageEvidence->storeReferencePhoto($request->file('reference_photo'), $created->id);
                $created->update(['reference_photo_url' => $photoPath]);
            }

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

        return response()->json(['user' => $user->fresh()], 201);
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
            'reference_photo' => ['nullable', 'file', 'max:2048'],
        ]);

        $isSelf = $user->id === $actor->id;
        $touchesProtected = isset($validated['role'])
            || isset($validated['join_date'])
            || array_key_exists('is_active', $validated)
            || isset($validated['office_location_id'])
            || array_key_exists('default_shift_pattern_id', $validated)
            || $request->hasFile('reference_photo');

        if ($isSelf && $touchesProtected) {
            abort(403, 'Dilarang mengubah role, lokasi kantor, pola shift, tanggal masuk, foto referensi, atau status aktif akun sendiri.');
        }

        $oldRole = $user->role;
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

        $timezone = $user->officeLocation->timezone ?? 'Asia/Jakarta';
        $todayLocal = CarbonImmutable::now('UTC')->setTimezone($timezone)->toDateString();

        // Prevent role change while active today's session or future DINAS_LUAR (when moving to SALES) exists
        if ($newRole !== $oldRole) {
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

            if ($newRole === UserRole::EMPLOYEE_SALES) {
                $hasFutureDinas = LeaveRequest::query()
                    ->where('user_id', $user->id)
                    ->where('type', LeaveType::DINAS_LUAR)
                    ->whereIn('status', [LeaveStatus::PENDING, LeaveStatus::APPROVED])
                    ->whereDate('end_date', '>=', $todayLocal)
                    ->exists();

                if ($hasFutureDinas) {
                    throw ValidationException::withMessages([
                        'role' => ['Tidak dapat mengubah role menjadi Sales karena masih ada pengajuan DINAS_LUAR PENDING/APPROVED untuk tanggal >= hari ini. Batalkan terlebih dahulu.'],
                    ]);
                }
            }
        }

        $oldValues = [
            'role' => $user->role->value,
            'office_location_id' => $user->office_location_id,
            'default_shift_pattern_id' => $user->default_shift_pattern_id,
            'join_date' => $user->join_date->toDateString(),
            'is_active' => $user->is_active,
        ];

        DB::transaction(function () use ($request, $user, $validated, $actor, $oldValues, $oldRole, $newRole, $newActive, $todayLocal): void {
            $attributes = collect($validated)->except('reference_photo')->all();
            if ($newRole === UserRole::EMPLOYEE_SALES) {
                $attributes['default_shift_pattern_id'] = null;
            }

            $patternChanged = array_key_exists('default_shift_pattern_id', $attributes)
                && (int) ($attributes['default_shift_pattern_id'] ?? 0) !== (int) ($user->default_shift_pattern_id ?? 0);
            $joinDateChanged = isset($attributes['join_date'])
                && $attributes['join_date'] !== $user->join_date->toDateString();

            if (array_key_exists('is_active', $attributes)) {
                $attributes['deactivated_at'] = $newActive
                    ? null
                    : ($user->deactivated_at ?? now('UTC'));
            }

            $user->update($attributes);

            if ($request->hasFile('reference_photo')) {
                $photoPath = $this->imageEvidence->storeReferencePhoto($request->file('reference_photo'), $user->id);
                $user->update(['reference_photo_url' => $photoPath]);
            }

            if ($joinDateChanged) {
                $this->balanceService->ensureBalanceForYear($user->fresh(), (int) now('UTC')->year, CarbonImmutable::now('UTC'));
            }

            if ($newRole === UserRole::EMPLOYEE_SALES && $oldRole !== UserRole::EMPLOYEE_SALES) {
                UserShiftSchedule::query()
                    ->where('user_id', $user->id)
                    ->where('source', ScheduleSource::PATTERN)
                    ->whereDate('work_date', '>=', $todayLocal)
                    ->delete();
            } elseif ($patternChanged || ($oldRole === UserRole::EMPLOYEE_SALES && $newRole !== UserRole::EMPLOYEE_SALES)) {
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
                    'default_shift_pattern_id' => $user->default_shift_pattern_id,
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
            'adjustment_days' => ['required', 'numeric', 'between:-30,30', 'multiple_of:0.5'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $year = (int) $validated['year'];
        $today = CarbonImmutable::now('Asia/Jakarta');
        $asOf = $year <= $today->year ? $today : null;

        $balance = $this->balanceService->ensureBalanceForYear($user, $year, $asOf);
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
            'rows.*.adjustment_days' => ['required', 'numeric', 'between:-30,30', 'multiple_of:0.5'],
        ]);

        $year = (int) $validated['year'];
        $today = CarbonImmutable::now('Asia/Jakarta');
        $asOf = $year <= $today->year ? $today : null;
        $updated = 0;

        DB::transaction(function () use ($validated, $actor, $year, $asOf, &$updated): void {
            $seenNiks = [];

            foreach ($validated['rows'] as $idx => $row) {
                $nikRaw = (string) $row['nik'];
                if ($this->csvSanitizer->hasFormulaPrefix($nikRaw)) {
                    throw ValidationException::withMessages([
                        'rows' => ["Karakter formula berbahaya terdeteksi pada NIK: {$nikRaw}"],
                    ]);
                }

                $nik = strtoupper(trim($nikRaw));
                if (isset($seenNiks[$nik])) {
                    throw ValidationException::withMessages([
                        'rows' => ['NIK duplikat dalam satu file pada baris '.($idx + 1).": {$nik}"],
                    ]);
                }
                $seenNiks[$nik] = true;

                $target = User::query()->where('nik', $nik)->first();
                if ($target === null) {
                    throw ValidationException::withMessages([
                        'rows' => ['NIK tidak ditemukan pada baris '.($idx + 1).": {$nik}"],
                    ]);
                }

                if ($target->id === $actor->id) {
                    continue;
                }

                $bal = $this->balanceService->ensureBalanceForYear($target, $year, $asOf);
                $oldAdjustment = (float) $bal->adjustment_days;

                $bal->update(['adjustment_days' => (float) $row['adjustment_days']]);

                $this->audit->log(
                    actorId: $actor->id,
                    entity: 'leave_balances',
                    recordId: $bal->id,
                    action: AuditAction::BULK_IMPORT,
                    oldValues: ['adjustment_days' => $oldAdjustment],
                    newValues: ['adjustment_days' => (float) $bal->adjustment_days],
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
            'replacement_for_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $timezone = $user->officeLocation->timezone ?? 'Asia/Jakarta';
        $todayLocal = CarbonImmutable::now('UTC')->setTimezone($timezone)->toDateString();
        $targetDate = (string) $validated['work_date'];
        $replacementDate = isset($validated['replacement_for_date'])
            ? (string) $validated['replacement_for_date']
            : null;
        $notes = trim((string) ($validated['notes'] ?? ''));

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

        $schedule = DB::transaction(function () use ($user, $actor, $targetDate, $validated, $todayLocal, $replacementDate, $notes): UserShiftSchedule {
            $existing = UserShiftSchedule::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', $targetDate)
                ->first();

            if ($replacementDate !== null) {
                if (! (bool) $validated['is_day_off']) {
                    throw ValidationException::withMessages([
                        'replacement_for_date' => ['Ganti hari wajib menetapkan tanggal pengganti sebagai hari libur.'],
                    ]);
                }

                if (! in_array($user->role, [UserRole::EMPLOYEE_OFFICE, UserRole::EMPLOYEE_SALES], true)) {
                    throw ValidationException::withMessages([
                        'replacement_for_date' => ['Ganti hari hanya berlaku untuk Marketing dan Sales.'],
                    ]);
                }

                if ($replacementDate >= $targetDate || $replacementDate > $todayLocal) {
                    throw ValidationException::withMessages([
                        'replacement_for_date' => ['Tanggal asal ganti hari harus sebelum tanggal pengganti dan tidak melewati hari ini.'],
                    ]);
                }

                if (! $this->balanceService->isEffectiveWorkDay($user, $targetDate)) {
                    throw ValidationException::withMessages([
                        'work_date' => ['Tanggal pengganti harus hari kerja efektif saat ini.'],
                    ]);
                }

                if ($notes === '') {
                    throw ValidationException::withMessages([
                        'notes' => ['Catatan/alasan ganti hari wajib diisi.'],
                    ]);
                }

                $originUsed = UserShiftSchedule::query()
                    ->where('user_id', $user->id)
                    ->whereDate('replacement_for_date', $replacementDate)
                    ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
                    ->exists();

                if ($originUsed) {
                    throw ValidationException::withMessages([
                        'replacement_for_date' => ['Tanggal asal tersebut sudah pernah ditukar menjadi ganti hari.'],
                    ]);
                }

                $originValid = $user->role === UserRole::EMPLOYEE_SALES
                    ? SalesVisit::query()
                        ->where('user_id', $user->id)
                        ->whereDate('visit_date', $replacementDate)
                        ->where('visit_status', '!=', VisitStatus::CANCELLED)
                        ->whereIn('verification_status', [VerificationStatus::AUTO_APPROVED->value, VerificationStatus::APPROVED->value])
                        ->get()
                        ->contains(fn (SalesVisit $visit): bool => in_array('OFF_DAY_ATTENDANCE', $visit->anomaly_flags ?? [], true))
                    : AttendanceLog::query()
                        ->where('user_id', $user->id)
                        ->whereDate('work_date', $replacementDate)
                        ->whereIn('clock_in_status', [VerificationStatus::AUTO_APPROVED->value, VerificationStatus::APPROVED->value])
                        ->where('is_active_attempt', 1)
                        ->get()
                        ->contains(fn (AttendanceLog $log): bool => in_array('OFF_DAY_ATTENDANCE', $log->clock_in_anomaly_flags ?? [], true));

                if (! $originValid) {
                    throw ValidationException::withMessages([
                        'replacement_for_date' => ['Tanggal asal tidak memiliki kehadiran libur yang sah dan disetujui.'],
                    ]);
                }
            }

            $old = $existing?->only(['shift_id', 'is_day_off', 'source', 'notes', 'replacement_for_date']);
            $attributes = [
                'shift_id' => (bool) $validated['is_day_off'] ? null : ($validated['shift_id'] ?? null),
                'is_day_off' => (bool) $validated['is_day_off'],
                'source' => ScheduleSource::MANUAL_OVERRIDE,
                'notes' => $notes !== '' ? $notes : null,
                'replacement_for_date' => $replacementDate,
                'created_by' => $actor->id,
            ];

            if ($existing !== null) {
                $existing->update($attributes);
                $saved = $existing->fresh();
            } else {
                $saved = UserShiftSchedule::query()->create(array_merge(
                    ['user_id' => $user->id, 'work_date' => $targetDate],
                    $attributes
                ));
            }

            $this->balanceService->recalculateLeaveRequestDays($targetDate, $user->id);

            $this->audit->log(
                actorId: $actor->id,
                entity: 'user_shift_schedules',
                recordId: $saved->id,
                action: $existing !== null ? AuditAction::UPDATE : AuditAction::CREATE,
                oldValues: $old,
                newValues: $saved->only(['shift_id', 'is_day_off', 'source', 'notes', 'replacement_for_date']),
            );

            return $saved;
        });

        return response()->json(['schedule' => $schedule]);
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        [$holidayDate, $locationId, $description, $type] = $this->validateHolidayPayload($request);

        $recalculatedIds = [];
        $holiday = DB::transaction(function () use ($holidayDate, $locationId, $description, $type, $actor, &$recalculatedIds): Holiday {
            $created = Holiday::query()->create([
                'holiday_date' => $holidayDate,
                'location_id' => $locationId,
                'description' => $description,
                'holiday_type' => $type,
            ]);

            $recalculatedIds = $this->syncHolidayEffects(
                dates: [$created->holiday_date->toDateString()],
                collectiveYears: $type === HolidayType::COLLECTIVE_LEAVE ? [(int) $created->holiday_date->year] : [],
                actor: $actor,
            );

            $this->audit->log(
                actorId: $actor->id,
                entity: 'holidays',
                recordId: $created->id,
                action: AuditAction::CREATE,
                newValues: $created->only(['holiday_date', 'location_id', 'description', 'holiday_type']),
            );

            return $created;
        });

        return response()->json([
            'holiday' => $holiday,
            'recalculated_leave_request_ids' => $recalculatedIds,
        ], 201);
    }

    public function updateHoliday(Request $request, Holiday $holiday): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        [$holidayDate, $locationId, $description, $type] = $this->validateHolidayPayload($request, $holiday->id);

        $recalculatedIds = [];
        DB::transaction(function () use ($holiday, $holidayDate, $locationId, $description, $type, $actor, &$recalculatedIds): void {
            $oldDate = $holiday->holiday_date->toDateString();
            $oldYear = (int) $holiday->holiday_date->year;
            $oldType = $holiday->holiday_type;
            $oldValues = $holiday->only(['holiday_date', 'location_id', 'description', 'holiday_type']);

            $holiday->update([
                'holiday_date' => $holidayDate,
                'location_id' => $locationId,
                'description' => $description,
                'holiday_type' => $type,
            ]);

            $newYear = (int) $holiday->holiday_date->year;
            $collectiveYears = [];
            if ($oldType === HolidayType::COLLECTIVE_LEAVE || $type === HolidayType::COLLECTIVE_LEAVE) {
                $collectiveYears = array_values(array_unique([$oldYear, $newYear]));
            }

            $recalculatedIds = $this->syncHolidayEffects(
                dates: [$oldDate, $holiday->holiday_date->toDateString()],
                collectiveYears: $collectiveYears,
                actor: $actor,
            );

            $this->audit->log(
                actorId: $actor->id,
                entity: 'holidays',
                recordId: $holiday->id,
                action: AuditAction::UPDATE,
                oldValues: $oldValues,
                newValues: $holiday->only(['holiday_date', 'location_id', 'description', 'holiday_type']),
            );
        });

        return response()->json([
            'holiday' => $holiday->fresh(),
            'recalculated_leave_request_ids' => $recalculatedIds,
        ]);
    }

    public function destroyHoliday(Request $request, Holiday $holiday): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $recalculatedIds = [];
        DB::transaction(function () use ($holiday, $actor, &$recalculatedIds): void {
            $oldDate = $holiday->holiday_date->toDateString();
            $oldYear = (int) $holiday->holiday_date->year;
            $oldType = $holiday->holiday_type;
            $oldValues = $holiday->only(['holiday_date', 'location_id', 'description', 'holiday_type']);
            $recordId = $holiday->id;

            $holiday->delete();

            $recalculatedIds = $this->syncHolidayEffects(
                dates: [$oldDate],
                collectiveYears: $oldType === HolidayType::COLLECTIVE_LEAVE ? [$oldYear] : [],
                actor: $actor,
            );

            $this->audit->log(
                actorId: $actor->id,
                entity: 'holidays',
                recordId: $recordId,
                action: AuditAction::SOFT_DELETE,
                oldValues: $oldValues,
                reason: 'Hapus tanggal libur / cuti bersama',
            );
        });

        return response()->json([
            'deleted' => true,
            'recalculated_leave_request_ids' => $recalculatedIds,
        ]);
    }

    /**
     * @return array{0: string, 1: int|null, 2: string, 3: HolidayType}
     */
    private function validateHolidayPayload(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'holiday_date' => ['required', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'description' => ['required', 'string', 'max:255'],
            'holiday_type' => ['required', Rule::enum(HolidayType::class)],
        ]);

        $type = HolidayType::from((string) $validated['holiday_type']);
        $locationId = $type === HolidayType::LOCAL ? (isset($validated['location_id']) ? (int) $validated['location_id'] : null) : null;

        if ($type === HolidayType::LOCAL && $locationId === null) {
            throw ValidationException::withMessages([
                'location_id' => ['Libur lokal wajib memilih cabang tertentu.'],
            ]);
        }

        $duplicate = Holiday::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
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

        return [
            (string) $validated['holiday_date'],
            $locationId,
            trim((string) $validated['description']),
            $type,
        ];
    }

    /**
     * @param  list<string>  $dates
     * @param  list<int>  $collectiveYears
     * @return list<int>
     */
    private function syncHolidayEffects(array $dates, array $collectiveYears, User $actor): array
    {
        $todayStr = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $futureDates = array_values(array_filter($dates, fn (string $d): bool => $d >= $todayStr));

        $recalculatedIds = [];
        if ($futureDates !== []) {
            $recalculatedIds = $this->balanceService->recalculateLeaveRequestDays(min($futureDates));

            if ($recalculatedIds !== []) {
                $this->audit->log(
                    actorId: $actor->id,
                    entity: 'leave_requests',
                    recordId: 0,
                    action: AuditAction::UPDATE,
                    newValues: ['recalculated_leave_request_ids' => $recalculatedIds],
                    reason: 'Rekalkulasi otomatis akibat perubahan hari libur',
                );
            }
        }

        foreach (array_values(array_unique($collectiveYears)) as $year) {
            $changes = $this->balanceService->syncCollectiveLeaveForYear($year);
            foreach ($changes as $change) {
                $this->audit->log(
                    actorId: $actor->id,
                    entity: 'leave_balances',
                    recordId: $change['balance']->id,
                    action: AuditAction::UPDATE,
                    oldValues: ['collective_leave_deduction' => $change['old']],
                    newValues: ['collective_leave_deduction' => $change['new']],
                    reason: 'Sinkronisasi Cuti Bersama',
                );
            }
        }

        return $recalculatedIds;
    }

    public function storeLocation(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'type' => ['required', Rule::enum(LocationType::class)],
            'code' => ['nullable', 'string', 'max:50', 'unique:locations,code'],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:1000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:10', 'max:2000'],
            'timezone' => ['required', 'string', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7'],
        ]);

        $type = LocationType::from((string) $validated['type']);
        $code = isset($validated['code']) && trim((string) $validated['code']) !== ''
            ? strtoupper(trim((string) $validated['code']))
            : null;

        if ($type === LocationType::OFFICE && $code === null) {
            throw ValidationException::withMessages([
                'code' => ['Kode kantor wajib diisi.'],
            ]);
        }

        $location = Location::query()->create([
            'code' => $code,
            'name' => trim((string) $validated['name']),
            'type' => $type,
            'address' => trim((string) $validated['address']),
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'radius_m' => (int) $validated['radius_m'],
            'timezone' => (string) $validated['timezone'],
            'work_days' => array_values(array_unique(array_map('intval', $validated['work_days']))),
            'status' => LocationStatus::ACTIVE,
            'created_by' => $actor->id,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'locations',
            recordId: $location->id,
            action: AuditAction::CREATE,
            newValues: $location->only(['code', 'name', 'type', 'radius_m', 'timezone', 'status']),
        );

        return response()->json(['location' => $location], 201);
    }

    public function updateLocation(Request $request, Location $location): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('locations', 'code')->ignore($location->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'address' => ['sometimes', 'string', 'max:1000'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_m' => ['sometimes', 'integer', 'min:10', 'max:2000'],
            'timezone' => ['sometimes', 'string', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'work_days' => ['sometimes', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
        ]);

        if (isset($validated['status']) && $validated['status'] === LocationStatus::INACTIVE->value && $location->type === LocationType::OFFICE) {
            $hasActiveUsers = User::query()
                ->where('office_location_id', $location->id)
                ->where('is_active', true)
                ->exists();

            if ($hasActiveUsers) {
                throw ValidationException::withMessages([
                    'status' => ['Tidak dapat menonaktifkan kantor yang masih memiliki karyawan aktif.'],
                ]);
            }
        }

        $old = $location->only(['code', 'name', 'latitude', 'longitude', 'radius_m', 'timezone', 'status']);
        if (array_key_exists('code', $validated)) {
            $trimmedCode = trim((string) ($validated['code'] ?? ''));
            if ($location->type === LocationType::OFFICE && $trimmedCode === '') {
                throw ValidationException::withMessages(['code' => ['Kode kantor wajib diisi.']]);
            }
            $validated['code'] = $trimmedCode !== '' ? strtoupper($trimmedCode) : null;
        }
        if (isset($validated['work_days'])) {
            $validated['work_days'] = array_values(array_unique(array_map('intval', $validated['work_days'])));
        }

        $location->update($validated);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'locations',
            recordId: $location->id,
            action: AuditAction::UPDATE,
            oldValues: $old,
            newValues: $location->only(['code', 'name', 'latitude', 'longitude', 'radius_m', 'timezone', 'status']),
        );

        return response()->json(['location' => $location->fresh()]);
    }

    public function importOutletsCsv(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.code' => ['required', 'string', 'max:50'],
            'rows.*.name' => ['required', 'string', 'max:150'],
            'rows.*.address' => ['required', 'string', 'max:1000'],
            'rows.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'rows.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'rows.*.radius_m' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'rows.*.timezone' => ['nullable', 'string', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
        ]);

        foreach ($validated['rows'] as $idx => $row) {
            foreach (['code', 'name', 'address'] as $field) {
                $val = (string) $row[$field];
                if ($this->csvSanitizer->hasFormulaPrefix($val)) {
                    throw ValidationException::withMessages([
                        'rows' => ['Karakter formula berbahaya terdeteksi pada baris '.($idx + 1)." ({$field}): {$val}"],
                    ]);
                }
            }
        }

        $imported = 0;

        DB::transaction(function () use ($validated, $actor, &$imported): void {
            foreach ($validated['rows'] as $row) {
                $code = strtoupper(trim((string) $row['code']));
                $existing = Location::query()->where('code', $code)->first();

                if ($existing !== null && $existing->type !== LocationType::OUTLET) {
                    throw ValidationException::withMessages([
                        'rows' => ["Kode {$code} sudah dipakai oleh lokasi kantor."],
                    ]);
                }

                $payload = [
                    'name' => trim((string) $row['name']),
                    'type' => LocationType::OUTLET,
                    'address' => trim((string) $row['address']),
                    'latitude' => (float) $row['latitude'],
                    'longitude' => (float) $row['longitude'],
                    'radius_m' => (int) ($row['radius_m'] ?? 100),
                    'timezone' => (string) ($row['timezone'] ?? 'Asia/Jakarta'),
                    'work_days' => [1, 2, 3, 4, 5, 6],
                    'status' => LocationStatus::ACTIVE,
                ];

                if ($existing !== null) {
                    $existing->update($payload);
                    $recordId = $existing->id;
                } else {
                    $created = Location::query()->create(array_merge($payload, [
                        'code' => $code,
                        'created_by' => $actor->id,
                    ]));
                    $recordId = $created->id;
                }

                $this->audit->log(
                    actorId: $actor->id,
                    entity: 'locations',
                    recordId: $recordId,
                    action: AuditAction::BULK_IMPORT,
                    newValues: ['code' => $code, 'name' => $payload['name'], 'type' => LocationType::OUTLET->value],
                    reason: 'Impor CSV Daftar Outlet',
                );
                $imported++;
            }
        });

        return response()->json(['imported_count' => $imported]);
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
            'code' => ['nullable', 'string', 'max:50', Rule::unique('locations', 'code')->ignore($location->id)],
            'radius_m' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'timezone' => ['nullable', 'string', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'reject_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = LocationStatus::from((string) $validated['status']);
        if ($newStatus === LocationStatus::INACTIVE && trim((string) ($validated['reject_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reject_reason' => ['Alasan penolakan wajib diisi saat menolak toko NOO.'],
            ]);
        }

        $old = ['status' => $location->status->value, 'code' => $location->code];
        $codeInput = isset($validated['code']) && trim((string) $validated['code']) !== ''
            ? strtoupper(trim((string) $validated['code']))
            : null;

        $location->update([
            'status' => $newStatus,
            'code' => $codeInput ?? ($location->code ?? 'OUT-'.strtoupper(Str::random(5))),
            'radius_m' => (int) ($validated['radius_m'] ?? $location->radius_m),
            'timezone' => (string) ($validated['timezone'] ?? $location->timezone),
            'reject_reason' => $newStatus === LocationStatus::INACTIVE ? trim((string) $validated['reject_reason']) : null,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'locations',
            recordId: $location->id,
            action: AuditAction::UPDATE,
            oldValues: $old,
            newValues: ['status' => $location->status->value, 'code' => $location->code, 'timezone' => $location->timezone],
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

    public function storeShift(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:shifts,code'],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'grace_period_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'auto_close_hours' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        $startTime = $this->normalizeTime((string) $validated['start_time']);
        $endTime = $this->normalizeTime((string) $validated['end_time']);
        if ($endTime <= $startTime) {
            throw ValidationException::withMessages([
                'end_time' => ['Jam pulang shift harus lebih besar dari jam masuk.'],
            ]);
        }

        $shift = Shift::query()->create([
            'code' => strtoupper(trim((string) $validated['code'])),
            'name' => trim((string) $validated['name']),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'grace_period_minutes' => (int) $validated['grace_period_minutes'],
            'auto_close_hours' => (int) $validated['auto_close_hours'],
            'is_active' => true,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'shifts',
            recordId: $shift->id,
            action: AuditAction::CREATE,
            newValues: $shift->only(['code', 'name', 'start_time', 'end_time', 'grace_period_minutes', 'auto_close_hours']),
        );

        return response()->json(['shift' => $shift], 201);
    }

    public function updateShift(Request $request, Shift $shift): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:30', Rule::unique('shifts', 'code')->ignore($shift->id)],
            'name' => ['sometimes', 'string', 'max:100'],
            'start_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'grace_period_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'auto_close_hours' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $validated) && ! (bool) $validated['is_active'] && $shift->is_active) {
            $this->assertShiftUnused($shift);
        }

        $startTime = isset($validated['start_time']) ? $this->normalizeTime((string) $validated['start_time']) : $shift->start_time;
        $endTime = isset($validated['end_time']) ? $this->normalizeTime((string) $validated['end_time']) : $shift->end_time;
        if ($endTime <= $startTime) {
            throw ValidationException::withMessages([
                'end_time' => ['Jam pulang shift harus lebih besar dari jam masuk.'],
            ]);
        }

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper(trim((string) $validated['code']));
        }
        if (isset($validated['start_time'])) {
            $validated['start_time'] = $startTime;
        }
        if (isset($validated['end_time'])) {
            $validated['end_time'] = $endTime;
        }

        $old = $shift->only(['code', 'name', 'start_time', 'end_time', 'grace_period_minutes', 'auto_close_hours', 'is_active']);
        $shift->update($validated);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'shifts',
            recordId: $shift->id,
            action: AuditAction::UPDATE,
            oldValues: $old,
            newValues: $shift->only(['code', 'name', 'start_time', 'end_time', 'grace_period_minutes', 'auto_close_hours', 'is_active']),
        );

        return response()->json(['shift' => $shift->fresh()]);
    }

    public function deactivateShift(Request $request, Shift $shift): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->assertShiftUnused($shift);

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

    public function storeShiftPattern(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $this->validateShiftPattern($request);

        $pattern = ShiftPattern::query()->create([
            'name' => trim((string) $validated['name']),
            'mon_shift_id' => $validated['mon_shift_id'] ?? null,
            'tue_shift_id' => $validated['tue_shift_id'] ?? null,
            'wed_shift_id' => $validated['wed_shift_id'] ?? null,
            'thu_shift_id' => $validated['thu_shift_id'] ?? null,
            'fri_shift_id' => $validated['fri_shift_id'] ?? null,
            'sat_shift_id' => $validated['sat_shift_id'] ?? null,
            'sun_shift_id' => $validated['sun_shift_id'] ?? null,
            'is_active' => true,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'shift_patterns',
            recordId: $pattern->id,
            action: AuditAction::CREATE,
            newValues: $pattern->only(['name', 'mon_shift_id', 'tue_shift_id', 'wed_shift_id', 'thu_shift_id', 'fri_shift_id', 'sat_shift_id', 'sun_shift_id']),
        );

        return response()->json(['pattern' => $pattern], 201);
    }

    public function updateShiftPattern(Request $request, ShiftPattern $shiftPattern): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $this->validateShiftPattern($request, true);

        if (array_key_exists('is_active', $validated) && ! (bool) $validated['is_active'] && $shiftPattern->is_active) {
            $hasUsers = User::query()
                ->where('default_shift_pattern_id', $shiftPattern->id)
                ->where('is_active', true)
                ->exists();

            if ($hasUsers) {
                throw ValidationException::withMessages([
                    'is_active' => ['Pola jadwal masih dipakai oleh karyawan aktif. Alihkan terlebih dahulu sebelum menonaktifkan.'],
                ]);
            }
        }

        $old = $shiftPattern->only(['name', 'mon_shift_id', 'tue_shift_id', 'wed_shift_id', 'thu_shift_id', 'fri_shift_id', 'sat_shift_id', 'sun_shift_id', 'is_active']);

        $generatedRows = 0;
        DB::transaction(function () use ($shiftPattern, $validated, $actor, $old, &$generatedRows): void {
            $shiftPattern->update($validated);

            if ($shiftPattern->is_active) {
                $users = User::query()
                    ->with('officeLocation')
                    ->where('default_shift_pattern_id', $shiftPattern->id)
                    ->where('is_active', true)
                    ->get();

                foreach ($users as $u) {
                    $generatedRows += $this->scheduleGenerator->generateForUser($u);
                }
            }

            $this->audit->log(
                actorId: $actor->id,
                entity: 'shift_patterns',
                recordId: $shiftPattern->id,
                action: AuditAction::UPDATE,
                oldValues: $old,
                newValues: $shiftPattern->only(['name', 'mon_shift_id', 'tue_shift_id', 'wed_shift_id', 'thu_shift_id', 'fri_shift_id', 'sat_shift_id', 'sun_shift_id', 'is_active']),
            );
        });

        return response()->json([
            'pattern' => $shiftPattern->fresh(),
            'generated_rows' => $generatedRows,
        ]);
    }

    public function generateSchedulesForPattern(Request $request, ShiftPattern $shiftPattern): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $shiftPattern->is_active) {
            throw ValidationException::withMessages([
                'pattern' => ['Tidak dapat men-generate jadwal dari pola yang nonaktif.'],
            ]);
        }

        $users = User::query()
            ->with('officeLocation')
            ->where('default_shift_pattern_id', $shiftPattern->id)
            ->where('is_active', true)
            ->get();

        $generatedRows = 0;
        foreach ($users as $u) {
            $generatedRows += $this->scheduleGenerator->generateForUser($u);
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'shift_patterns',
            recordId: $shiftPattern->id,
            action: AuditAction::UPDATE,
            newValues: ['generated_rows' => $generatedRows, 'users_count' => $users->count()],
            reason: 'Generate jadwal 90 hari ke depan',
        );

        return response()->json([
            'users_count' => $users->count(),
            'generated_rows' => $generatedRows,
        ]);
    }

    public function deactivateShiftPattern(Request $request, ShiftPattern $shiftPattern): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $hasUsers = User::query()
            ->where('default_shift_pattern_id', $shiftPattern->id)
            ->where('is_active', true)
            ->exists();

        if ($hasUsers) {
            throw ValidationException::withMessages([
                'pattern' => ['Pola jadwal masih dipakai oleh karyawan aktif. Alihkan terlebih dahulu sebelum menonaktifkan.'],
            ]);
        }

        $shiftPattern->update(['is_active' => false]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'shift_patterns',
            recordId: $shiftPattern->id,
            action: AuditAction::SOFT_DELETE,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
        );

        return response()->json(['pattern' => $shiftPattern->fresh()]);
    }

    public function storeActivityCategory(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:activity_categories,name'],
            'exempt_short_duration' => ['required', 'boolean'],
        ]);

        $category = ActivityCategory::query()->create([
            'name' => trim((string) $validated['name']),
            'exempt_short_duration' => (bool) $validated['exempt_short_duration'],
            'is_active' => true,
        ]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'activity_categories',
            recordId: $category->id,
            action: AuditAction::CREATE,
            newValues: $category->only(['name', 'exempt_short_duration', 'is_active']),
        );

        return response()->json(['category' => $category], 201);
    }

    public function updateActivityCategory(Request $request, ActivityCategory $activityCategory): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('activity_categories', 'name')->ignore($activityCategory->id)],
            'exempt_short_duration' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['name'])) {
            $validated['name'] = trim((string) $validated['name']);
        }

        $old = $activityCategory->only(['name', 'exempt_short_duration', 'is_active']);
        $activityCategory->update($validated);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'activity_categories',
            recordId: $activityCategory->id,
            action: AuditAction::UPDATE,
            oldValues: $old,
            newValues: $activityCategory->only(['name', 'exempt_short_duration', 'is_active']),
        );

        return response()->json(['category' => $activityCategory->fresh()]);
    }

    public function deactivateActivityCategory(Request $request, ActivityCategory $activityCategory): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $activityCategory->update(['is_active' => false]);

        $this->audit->log(
            actorId: $actor->id,
            entity: 'activity_categories',
            recordId: $activityCategory->id,
            action: AuditAction::SOFT_DELETE,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
        );

        return response()->json(['category' => $activityCategory->fresh()]);
    }

    private function assertShiftUnused(Shift $shift): void
    {
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
    }

    /**
     * @return array<string, mixed>
     */
    private function validateShiftPattern(Request $request, bool $isUpdate = false): array
    {
        $dayRule = ['nullable', 'integer', 'exists:shifts,id'];

        return $request->validate([
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'mon_shift_id' => $dayRule,
            'tue_shift_id' => $dayRule,
            'wed_shift_id' => $dayRule,
            'thu_shift_id' => $dayRule,
            'fri_shift_id' => $dayRule,
            'sat_shift_id' => $dayRule,
            'sun_shift_id' => $dayRule,
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
