<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Location;
use App\Models\User;
use App\Services\AntiFraudService;
use App\Services\AttendanceHelperService;
use App\Services\GeoLocationService;
use App\Services\ImageEvidenceService;
use App\Services\ScheduleResolverService;
use App\Services\TokenGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OfficeAttendanceController extends Controller
{
    public function __construct(
        private readonly GeoLocationService $geo,
        private readonly ScheduleResolverService $scheduleResolver,
        private readonly AntiFraudService $antiFraud,
        private readonly TokenGuardService $tokenGuard,
        private readonly ImageEvidenceService $imageEvidence,
        private readonly AttendanceHelperService $helper,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->load(['officeLocation', 'defaultShiftPattern']);

        /** @var Location $office */
        $office = $user->officeLocation;
        $timezone = $office->timezone;
        $nowUtc = CarbonImmutable::now('UTC');
        $nowLocal = $nowUtc->setTimezone($timezone);
        $workDate = $nowLocal->toDateString();

        $schedule = $this->scheduleResolver->resolveOrCreateSchedule($user, $workDate);
        $shift = $schedule?->shift;
        $isHoliday = $this->scheduleResolver->isHoliday($workDate, $office->id);
        $isDayOff = $isHoliday || ($schedule !== null && $schedule->is_day_off);
        $hasApprovedTimeOff = $this->scheduleResolver->hasApprovedTimeOff($user->id, $workDate);
        $dinasLuar = $this->scheduleResolver->approvedDinasLuar($user->id, $workDate);

        $activeLog = AttendanceLog::query()
            ->with(['location', 'shift'])
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate)
            ->where('is_active_attempt', 1)
            ->first();

        $rejectedLog = null;
        if ($activeLog === null) {
            $rejectedLog = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', $workDate)
                ->where('clock_in_status', VerificationStatus::REJECTED)
                ->latest('attempt_no')
                ->first();
        }

        $effectiveEnd = $this->scheduleResolver->effectiveEndTime($workDate, $timezone, $shift, $dinasLuar);
        $autoCloseHours = $shift !== null ? $shift->auto_close_hours : 5;
        $closeDeadline = $effectiveEnd !== null
            ? $effectiveEnd->addHours($autoCloseHours)
            : CarbonImmutable::parse("{$workDate} 23:59:59", $timezone);

        $canRetryRejected = $rejectedLog !== null
            && $rejectedLog->clock_out_at === null
            && $nowLocal->lessThan($closeDeadline);

        return Inertia::render('attendance/Office', [
            'office' => [
                'id' => $office->id,
                'name' => $office->name,
                'radius_m' => $office->radius_m,
                'timezone' => $timezone,
            ],
            'serverTimeIso' => $nowUtc->toIso8601String(),
            'workDate' => $workDate,
            'shift' => $shift !== null ? [
                'code' => $shift->code,
                'name' => $shift->name,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
                'grace_period_minutes' => $shift->grace_period_minutes,
            ] : null,
            'isDayOff' => $isDayOff,
            'hasApprovedTimeOff' => $hasApprovedTimeOff,
            'dinasLuar' => $dinasLuar !== null ? [
                'id' => $dinasLuar->id,
                'destination_location' => $dinasLuar->destination_location,
                'scheduled_start_time' => $dinasLuar->scheduled_start_time,
            ] : null,
            'activeLog' => $activeLog,
            'rejectedLog' => $rejectedLog,
            'canRetryRejected' => $canRetryRejected,
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load(['officeLocation', 'defaultShiftPattern']);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'min:0'],
            'gps_session_token' => ['required', 'string'],
            'camera_token' => ['required', 'string'],
            'photo' => ['required', 'file', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $idempotencyKey = (string) $validated['idempotency_key'];

        // FR-5: Check idempotency BEFORE consuming single-use tokens
        $existingByKey = AttendanceLog::query()
            ->where('clock_in_idempotency_key', $idempotencyKey)
            ->first();

        if ($existingByKey !== null) {
            if ($existingByKey->user_id !== $user->id) {
                return response()->json(['message' => 'Idempotency key sudah digunakan oleh pengguna lain.'], 409);
            }

            return response()->json(['attendance' => $existingByKey, 'idempotent_replay' => true]);
        }

        /** @var Location $office */
        $office = $user->officeLocation;
        $timezone = $office->timezone;
        $nowUtc = CarbonImmutable::now('UTC');
        $nowLocal = $nowUtc->setTimezone($timezone);
        $workDate = $nowLocal->toDateString();

        if ($this->scheduleResolver->hasApprovedTimeOff($user->id, $workDate)) {
            throw ValidationException::withMessages([
                'attendance' => ['Anda tidak dapat absen karena sedang dalam status Cuti/Izin/Sakit yang telah disetujui hari ini.'],
            ]);
        }

        $activeExists = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate)
            ->where('is_active_attempt', 1)
            ->exists();

        if ($activeExists) {
            throw ValidationException::withMessages([
                'attendance' => ['Anda sudah melakukan Clock-In aktif hari ini.'],
            ]);
        }

        $schedule = $this->scheduleResolver->resolveOrCreateSchedule($user, $workDate);
        $shift = $schedule?->shift;
        $isHoliday = $this->scheduleResolver->isHoliday($workDate, $office->id);
        $isDayOff = $isHoliday || ($schedule !== null && $schedule->is_day_off) || $shift === null;
        $dinasLuar = $this->scheduleResolver->approvedDinasLuar($user->id, $workDate);

        $effectiveStart = $this->scheduleResolver->effectiveStartTime($workDate, $timezone, $shift, $dinasLuar);
        $effectiveEnd = $this->scheduleResolver->effectiveEndTime($workDate, $timezone, $shift, $dinasLuar);
        $autoCloseHours = $shift !== null ? $shift->auto_close_hours : 5;
        $closeDeadline = $effectiveEnd !== null
            ? $effectiveEnd->addHours($autoCloseHours)
            : CarbonImmutable::parse("{$workDate} 23:59:59", $timezone);

        $lastRejected = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate)
            ->where('clock_in_status', VerificationStatus::REJECTED)
            ->latest('attempt_no')
            ->first();

        $isRetry = false;
        $attemptNo = 1;

        if ($lastRejected !== null) {
            if ($lastRejected->clock_out_at !== null || $nowLocal->greaterThanOrEqualTo($closeDeadline)) {
                throw ValidationException::withMessages([
                    'attendance' => ['Batas waktu absen ulang sudah berakhir. Hubungi HR Admin untuk koreksi status.'],
                ]);
            }
            $isRetry = true;
            $attemptNo = $lastRejected->attempt_no + 1;
        } elseif (! $isDayOff && $effectiveStart !== null && $effectiveEnd !== null) {
            $openAt = $effectiveStart->subHours(2);
            if ($nowLocal->lessThan($openAt) || $nowLocal->greaterThanOrEqualTo($effectiveEnd)) {
                throw ValidationException::withMessages([
                    'attendance' => ['Clock-In di luar jendela waktu shift yang diizinkan.'],
                ]);
            }
        }

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $accuracyM = (float) $validated['accuracy_m'];
        $distanceM = $this->geo->haversineMeters($lat, $lng, (float) $office->latitude, (float) $office->longitude);

        $geoEval = $this->geo->evaluate(
            distanceM: $distanceM,
            accuracyM: $accuracyM,
            radiusM: $office->radius_m,
            isEntryPhase: true,
            bypassRadius: $dinasLuar !== null,
        );

        if ($geoEval['blocked']) {
            throw ValidationException::withMessages([
                'location' => [$geoEval['block_message'] ?? 'Lokasi terlalu jauh dari kantor.'],
            ]);
        }

        $notes = trim((string) ($validated['notes'] ?? ''));
        $isRetryAfterEnd = $isRetry && $effectiveEnd !== null && $nowLocal->greaterThanOrEqualTo($effectiveEnd);
        $lateMinutes = 0;

        if (! $isDayOff && $effectiveStart !== null) {
            $grace = $shift->grace_period_minutes;
            $lateCutoff = $effectiveStart->addMinutes($grace);
            if ($nowLocal->greaterThan($lateCutoff)) {
                $lateMinutes = (int) $effectiveStart->diffInMinutes($nowLocal);
            }
        }

        if (($geoEval['requires_notes'] || $isDayOff || $isRetryAfterEnd) && $notes === '') {
            throw ValidationException::withMessages([
                'notes' => ['Alasan wajib diisi untuk kondisi lokasi, lembur/hari libur, atau absen ulang.'],
            ]);
        }

        $gpsTokenResult = $this->tokenGuard->consumeGpsSessionToken((string) $validated['gps_session_token'], $user->id);
        $camTokenResult = $this->tokenGuard->consumeCameraToken((string) $validated['camera_token'], $user->id);

        $deviceId = $this->helper->resolveDeviceId($request);
        $fraud = $this->antiFraud->evaluate(
            userId: $user->id,
            localDate: $workDate,
            lat: $lat,
            lng: $lng,
            accuracyM: $accuracyM,
            nowUtc: $nowUtc,
            deviceId: $deviceId,
            isInsideRadius: $distanceM <= $office->radius_m,
            isStaleTimestamp: $gpsTokenResult['stale'] || $camTokenResult['stale'],
        );

        $flags = array_values(array_unique([...$geoEval['flags'], ...$fraud['flags']]));
        $needsReview = $flags !== [] || $isDayOff || $isRetryAfterEnd;

        /** @var UploadedFile $photo */
        $photo = $request->file('photo');
        $photoPath = $this->imageEvidence->storeWatermarkedPhoto(
            file: $photo,
            userId: $user->id,
            nik: $user->nik,
            locationLabel: $dinasLuar !== null ? "Dinas: {$dinasLuar->destination_location}" : $office->name,
            lat: $lat,
            lng: $lng,
            accuracyM: $accuracyM,
            serverTime: $nowUtc,
            timezone: $timezone,
        );

        try {
            $log = DB::transaction(function () use (
                $user,
                $office,
                $shift,
                $dinasLuar,
                $workDate,
                $attemptNo,
                $nowUtc,
                $photoPath,
                $lat,
                $lng,
                $accuracyM,
                $distanceM,
                $deviceId,
                $request,
                $notes,
                $idempotencyKey,
                $lateMinutes,
                $needsReview,
                $flags,
            ): AttendanceLog {
                $this->helper->cancelPendingLeaveDayOnPresence($user->id, $workDate);

                return AttendanceLog::query()->create([
                    'user_id' => $user->id,
                    'location_id' => $office->id,
                    'shift_id' => $shift?->id,
                    'leave_request_id' => $dinasLuar?->id,
                    'source' => AttendanceSource::APP,
                    'work_date' => $workDate,
                    'attempt_no' => $attemptNo,
                    'is_active_attempt' => 1,
                    'clock_in_at' => $nowUtc,
                    'clock_in_photo_url' => $photoPath,
                    'clock_in_lat' => $lat,
                    'clock_in_lng' => $lng,
                    'clock_in_accuracy_m' => $accuracyM,
                    'clock_in_distance_m' => $distanceM,
                    'clock_in_device_id' => $deviceId !== '' ? $deviceId : null,
                    'clock_in_ip' => $request->ip(),
                    'clock_in_user_agent' => $request->userAgent(),
                    'clock_in_notes' => $notes !== '' ? $notes : null,
                    'clock_in_idempotency_key' => $idempotencyKey,
                    'late_minutes' => $lateMinutes,
                    'clock_in_status' => $needsReview ? VerificationStatus::PENDING_REVIEW : VerificationStatus::AUTO_APPROVED,
                    'clock_in_anomaly_flags' => $flags !== [] ? $flags : null,
                ]);
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                $replayed = AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->where('clock_in_idempotency_key', $idempotencyKey)
                    ->first();

                if ($replayed !== null) {
                    return response()->json(['attendance' => $replayed, 'idempotent_replay' => true]);
                }
            }
            throw $e;
        }

        return response()->json(['attendance' => $log], 201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'min:0'],
            'gps_session_token' => ['required', 'string'],
            'camera_token' => ['required', 'string'],
            'photo' => ['required', 'file', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $idempotencyKey = (string) $validated['idempotency_key'];

        $existingByKey = AttendanceLog::query()
            ->where('clock_out_idempotency_key', $idempotencyKey)
            ->first();

        if ($existingByKey !== null) {
            if ($existingByKey->user_id !== $user->id) {
                return response()->json(['message' => 'Idempotency key sudah digunakan oleh pengguna lain.'], 409);
            }

            return response()->json(['attendance' => $existingByKey, 'idempotent_replay' => true]);
        }

        /** @var AttendanceLog|null $log */
        $log = AttendanceLog::query()
            ->with(['location', 'shift', 'leaveRequest'])
            ->where('user_id', $user->id)
            ->where('is_active_attempt', 1)
            ->whereNull('clock_out_at')
            ->latest('work_date')
            ->first();

        if ($log === null) {
            throw ValidationException::withMessages([
                'attendance' => ['Tidak ada sesi Clock-In aktif yang perlu ditutup.'],
            ]);
        }

        /** @var Location $lockedLocation */
        $lockedLocation = $log->location;
        $timezone = $lockedLocation->timezone;
        $nowUtc = CarbonImmutable::now('UTC');
        $nowLocal = $nowUtc->setTimezone($timezone);
        $workDate = $log->work_date->toDateString();

        $shift = $log->shift;
        $dinasLuar = $log->leaveRequest;
        $schedule = $this->scheduleResolver->resolveOrCreateSchedule($user, $workDate);
        $isHoliday = $this->scheduleResolver->isHoliday($workDate, $lockedLocation->id);
        $isDayOff = $isHoliday || ($schedule !== null && $schedule->is_day_off) || $shift === null;

        $effectiveEnd = $this->scheduleResolver->effectiveEndTime($workDate, $timezone, $shift, $dinasLuar);
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $accuracyM = (float) $validated['accuracy_m'];
        $distanceM = $this->geo->haversineMeters($lat, $lng, (float) $lockedLocation->latitude, (float) $lockedLocation->longitude);

        $geoEval = $this->geo->evaluate(
            distanceM: $distanceM,
            accuracyM: $accuracyM,
            radiusM: $lockedLocation->radius_m,
            isEntryPhase: false,
            bypassRadius: $dinasLuar !== null,
        );

        $earlyLeaveMinutes = 0;
        $flags = $geoEval['flags'];

        if (! $isDayOff && $effectiveEnd !== null && $nowLocal->lessThan($effectiveEnd)) {
            $earlyLeaveMinutes = (int) $nowLocal->diffInMinutes($effectiveEnd);
            $flags[] = 'EARLY_LEAVE';
        }

        $isRetryAfterEnd = $log->attempt_no > 1
            && $effectiveEnd !== null
            && CarbonImmutable::parse($log->clock_in_at)->setTimezone($timezone)->greaterThanOrEqualTo($effectiveEnd);

        $notes = trim((string) ($validated['notes'] ?? ''));
        $requiresNotes = $geoEval['requires_notes'] || $earlyLeaveMinutes > 0 || $isDayOff || $isRetryAfterEnd;

        if ($requiresNotes && $notes === '') {
            throw ValidationException::withMessages([
                'notes' => ['Alasan wajib diisi saat pulang awal, di luar radius, atau akurasi GPS rendah.'],
            ]);
        }

        $gpsTokenResult = $this->tokenGuard->consumeGpsSessionToken((string) $validated['gps_session_token'], $user->id);
        $camTokenResult = $this->tokenGuard->consumeCameraToken((string) $validated['camera_token'], $user->id);

        $deviceId = $this->helper->resolveDeviceId($request);
        $fraud = $this->antiFraud->evaluate(
            userId: $user->id,
            localDate: $workDate,
            lat: $lat,
            lng: $lng,
            accuracyM: $accuracyM,
            nowUtc: $nowUtc,
            deviceId: $deviceId,
            isInsideRadius: $distanceM <= $lockedLocation->radius_m,
            isStaleTimestamp: $gpsTokenResult['stale'] || $camTokenResult['stale'],
        );

        $allFlags = array_values(array_unique([...$flags, ...$fraud['flags']]));
        $needsReview = $allFlags !== [] || $isDayOff || $isRetryAfterEnd;

        /** @var UploadedFile $photo */
        $photo = $request->file('photo');
        $photoPath = $this->imageEvidence->storeWatermarkedPhoto(
            file: $photo,
            userId: $user->id,
            nik: $user->nik,
            locationLabel: $dinasLuar !== null ? "Dinas: {$dinasLuar->destination_location}" : $lockedLocation->name,
            lat: $lat,
            lng: $lng,
            accuracyM: $accuracyM,
            serverTime: $nowUtc,
            timezone: $timezone,
        );

        $log->update([
            'clock_out_at' => $nowUtc,
            'clock_out_photo_url' => $photoPath,
            'clock_out_lat' => $lat,
            'clock_out_lng' => $lng,
            'clock_out_accuracy_m' => $accuracyM,
            'clock_out_distance_m' => $distanceM,
            'clock_out_device_id' => $deviceId !== '' ? $deviceId : null,
            'clock_out_ip' => $request->ip(),
            'clock_out_user_agent' => $request->userAgent(),
            'clock_out_notes' => $notes !== '' ? $notes : null,
            'clock_out_idempotency_key' => $idempotencyKey,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'clock_out_status' => $needsReview ? VerificationStatus::PENDING_REVIEW : VerificationStatus::AUTO_APPROVED,
            'clock_out_anomaly_flags' => $allFlags !== [] ? $allFlags : null,
        ]);

        return response()->json(['attendance' => $log->fresh()]);
    }
}
