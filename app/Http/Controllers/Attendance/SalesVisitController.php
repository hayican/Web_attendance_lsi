<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityCategory;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\User;
use App\Services\AntiFraudService;
use App\Services\AttendanceHelperService;
use App\Services\GeoLocationService;
use App\Services\ImageEvidenceService;
use App\Services\LeaveBalanceService;
use App\Services\ScheduleResolverService;
use App\Services\TokenGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SalesVisitController extends Controller
{
    private const MAX_CANCELS_PER_DAY = 3;

    public function __construct(
        private readonly GeoLocationService $geo,
        private readonly ScheduleResolverService $scheduleResolver,
        private readonly AntiFraudService $antiFraud,
        private readonly TokenGuardService $tokenGuard,
        private readonly ImageEvidenceService $imageEvidence,
        private readonly AttendanceHelperService $helper,
        private readonly LeaveBalanceService $balanceService,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('officeLocation');

        /** @var Location $homeOffice */
        $homeOffice = $user->officeLocation;
        $nowUtc = CarbonImmutable::now('UTC');
        $todayBranch = $nowUtc->setTimezone($homeOffice->timezone)->toDateString();

        // On-the-fly auto-close stale IN_PROGRESS visits from prior dates (US-005)
        $this->autoCloseStaleVisits($user->id, $todayBranch);

        $activeVisit = SalesVisit::query()
            ->with('outletLocation')
            ->where('user_id', $user->id)
            ->where('visit_status', VisitStatus::IN_PROGRESS)
            ->first();

        $recentOutletIds = SalesVisit::query()
            ->where('user_id', $user->id)
            ->where('visit_status', '!=', VisitStatus::CANCELLED)
            ->latest('visit_in_at')
            ->limit(30)
            ->pluck('outlet_location_id')
            ->unique()
            ->take(5)
            ->values();

        $recentOutlets = Location::query()
            ->whereIn('id', $recentOutletIds)
            ->where('status', '!=', LocationStatus::INACTIVE)
            ->get()
            ->map(fn (Location $loc): array => $this->serializeOutlet($loc, null))
            ->values();

        $cancelledTodayCount = SalesVisit::query()
            ->where('user_id', $user->id)
            ->whereDate('visit_date', $todayBranch)
            ->where('visit_status', VisitStatus::CANCELLED)
            ->count();

        $cancelRemainingToday = max(0, self::MAX_CANCELS_PER_DAY - $cancelledTodayCount);

        $todayVisits = SalesVisit::query()
            ->with(['outletLocation', 'activityCategory'])
            ->where('user_id', $user->id)
            ->whereDate('visit_date', $todayBranch)
            ->orderBy('visit_in_at')
            ->get()
            ->map(fn (SalesVisit $visit): array => [
                'id' => $visit->id,
                'outlet_name' => $visit->outletLocation->name,
                'outlet_code' => $visit->outletLocation->code,
                'outlet_address' => $visit->outletLocation->address,
                'outlet_timezone' => $visit->outletLocation->timezone,
                'visit_in_time' => $visit->visit_in_at?->setTimezone($visit->outletLocation->timezone)->format('H:i'),
                'visit_out_time' => $visit->visit_out_at?->setTimezone($visit->outletLocation->timezone)->format('H:i'),
                'duration_minutes' => $visit->duration_minutes,
                'activity_category_name' => $visit->activityCategory?->name,
                'visit_notes' => $visit->visit_notes,
                'visit_in_notes' => $visit->visit_in_notes,
                'cancel_reason' => $visit->cancel_reason,
                'visit_status' => $visit->visit_status->value,
                'verification_status' => $visit->verification_status->value,
                'source' => $visit->source->value,
                'reject_reason' => $visit->reject_reason,
            ])
            ->values();

        $categories = ActivityCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'exempt_short_duration']);

        $rejectedOutlets = Location::query()
            ->where('type', LocationType::OUTLET)
            ->where('created_by', $user->id)
            ->where('status', LocationStatus::INACTIVE)
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Location $loc): array => [
                'id' => $loc->id,
                'name' => $loc->name,
                'address' => $loc->address,
                'reject_reason' => $loc->reject_reason,
            ])
            ->values();

        return Inertia::render('sales/Kunjungan', [
            'homeTimezone' => $homeOffice->timezone,
            'todayDate' => $todayBranch,
            'serverTimeIso' => $nowUtc->toIso8601String(),
            'hasApprovedTimeOff' => $this->scheduleResolver->hasApprovedTimeOff($user->id, $todayBranch),
            'rejectedOutlets' => $rejectedOutlets,
            'activeVisit' => $activeVisit !== null ? [
                'id' => $activeVisit->id,
                'outlet_name' => $activeVisit->outletLocation->name,
                'outlet_code' => $activeVisit->outletLocation->code,
                'outlet_address' => $activeVisit->outletLocation->address,
                'outlet_radius_m' => $activeVisit->outletLocation->radius_m,
                'outlet_timezone' => $activeVisit->outletLocation->timezone,
                'visit_in_at' => $activeVisit->visit_in_at?->toIso8601String(),
                'visit_in_time' => $activeVisit->visit_in_at?->setTimezone($activeVisit->outletLocation->timezone)->format('H:i'),
                'verification_status' => $activeVisit->verification_status->value,
                'reject_reason' => $activeVisit->reject_reason,
                'can_cancel' => $cancelRemainingToday > 0
                    && $activeVisit->visit_in_at !== null
                    && $activeVisit->visit_in_at->diffInSeconds($nowUtc) <= 300,
                'cancel_remaining_today' => $cancelRemainingToday,
            ] : null,
            'recentOutlets' => $recentOutlets,
            'todayVisits' => $todayVisits,
            'categories' => $categories,
        ]);
    }

    public function searchOutlets(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $queryText = trim((string) ($validated['q'] ?? ''));
        $lat = isset($validated['lat']) ? (float) $validated['lat'] : null;
        $lng = isset($validated['lng']) ? (float) $validated['lng'] : null;

        $outlets = Location::query()
            ->where('type', LocationType::OUTLET)
            ->where(function ($query) use ($user): void {
                $query->where('status', LocationStatus::ACTIVE)
                    ->orWhere(function ($sub) use ($user): void {
                        $sub->where('status', LocationStatus::PENDING_VERIFICATION)
                            ->where('created_by', $user->id);
                    });
            })
            ->when($queryText !== '', function ($query) use ($queryText): void {
                $like = '%'.$queryText.'%';
                $query->where(function ($sub) use ($like): void {
                    $sub->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('address', 'like', $like);
                });
            })
            ->get()
            ->map(function (Location $loc) use ($lat, $lng): array {
                $distance = ($lat !== null && $lng !== null)
                    ? $this->geo->haversineMeters($lat, $lng, (float) $loc->latitude, (float) $loc->longitude)
                    : null;

                return $this->serializeOutlet($loc, $distance);
            });

        if ($lat !== null && $lng !== null) {
            $outlets = $outlets->sortBy('distance_m');
        }

        return response()->json([
            'outlets' => $outlets->take(30)->values(),
        ]);
    }

    public function storeNoo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('officeLocation');

        /** @var Location $homeOffice */
        $homeOffice = $user->officeLocation;
        $todayBranch = CarbonImmutable::now('UTC')->setTimezone($homeOffice->timezone)->toDateString();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'min:0'],
            'timezone' => ['nullable', 'in:Asia/Jakarta,Asia/Makassar'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ]);

        if ((float) $validated['accuracy_m'] > 50.0) {
            throw ValidationException::withMessages([
                'accuracy_m' => ['Akurasi GPS untuk pengajuan toko baru (NOO) wajib <= 50m. Silakan cari area terbuka dan muat ulang GPS.'],
            ]);
        }

        // created_at is UTC; the daily quota must follow the branch's calendar day.
        $dayStartUtc = CarbonImmutable::parse($todayBranch, $homeOffice->timezone)->startOfDay()->utc();
        $dayEndUtc = CarbonImmutable::parse($todayBranch, $homeOffice->timezone)->endOfDay()->utc();

        $todayNooCount = Location::query()
            ->where('type', LocationType::OUTLET)
            ->where('created_by', $user->id)
            ->whereBetween('created_at', [$dayStartUtc, $dayEndUtc])
            ->count();

        if ($todayNooCount >= 5) {
            throw ValidationException::withMessages([
                'noo' => ['Batas pengajuan toko baru (NOO) maksimal 5 toko per hari.'],
            ]);
        }

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $nearby = Location::query()
            ->where('type', LocationType::OUTLET)
            ->whereIn('status', [LocationStatus::ACTIVE, LocationStatus::PENDING_VERIFICATION])
            ->get()
            ->map(function (Location $loc) use ($lat, $lng): array {
                $distance = $this->geo->haversineMeters($lat, $lng, (float) $loc->latitude, (float) $loc->longitude);

                return $this->serializeOutlet($loc, $distance);
            })
            ->filter(fn (array $item): bool => ($item['distance_m'] ?? 9999) <= 100.0)
            ->values();

        if ($nearby->isNotEmpty() && ! (bool) ($validated['confirm_duplicate'] ?? false)) {
            return response()->json([
                'duplicate_warning' => true,
                'nearby_outlets' => $nearby,
            ], 409);
        }

        $outlet = Location::query()->create([
            'code' => 'NOO-'.strtoupper(Str::random(6)),
            'name' => trim((string) $validated['name']),
            'type' => LocationType::OUTLET,
            'address' => trim((string) $validated['address']),
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_m' => 100,
            'timezone' => (string) ($validated['timezone'] ?? $homeOffice->timezone),
            'work_days' => [1, 2, 3, 4, 5, 6],
            'status' => LocationStatus::PENDING_VERIFICATION,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'outlet' => $this->serializeOutlet($outlet, 0.0),
        ], 201);
    }

    public function visitIn(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('officeLocation');

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'outlet_location_id' => ['required', 'integer', 'exists:locations,id'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'min:0'],
            'gps_session_token' => ['required', 'string'],
            'camera_token' => ['required', 'string'],
            'photo' => ['required', 'file', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $idempotencyKey = (string) $validated['idempotency_key'];

        $existingByKey = SalesVisit::query()
            ->where('visit_in_idempotency_key', $idempotencyKey)
            ->first();

        if ($existingByKey !== null) {
            if ($existingByKey->user_id !== $user->id) {
                return response()->json(['message' => 'Idempotency key sudah digunakan oleh pengguna lain.'], 409);
            }

            return response()->json(['visit' => $existingByKey, 'idempotent_replay' => true]);
        }

        /** @var Location $homeOffice */
        $homeOffice = $user->officeLocation;
        $nowUtc = CarbonImmutable::now('UTC');
        $visitDate = $nowUtc->setTimezone($homeOffice->timezone)->toDateString();
        $isEffectiveWorkDay = $this->balanceService->isEffectiveWorkDay($user, $visitDate);

        if ($this->scheduleResolver->hasApprovedTimeOff($user->id, $visitDate)) {
            throw ValidationException::withMessages([
                'visit' => ['Anda tidak dapat berkunjung karena sedang dalam status Cuti/Izin/Sakit yang telah disetujui hari ini.'],
            ]);
        }

        /** @var Location $outlet */
        $outlet = Location::query()->findOrFail((int) $validated['outlet_location_id']);
        if ($outlet->type !== LocationType::OUTLET || $outlet->status === LocationStatus::INACTIVE) {
            throw ValidationException::withMessages([
                'outlet_location_id' => ['Toko tidak aktif atau tidak valid.'],
            ]);
        }
        if ($outlet->status === LocationStatus::PENDING_VERIFICATION && $outlet->created_by !== $user->id) {
            throw ValidationException::withMessages([
                'outlet_location_id' => ['Toko NOO masih menunggu verifikasi HR dan hanya dapat dikunjungi pengajunya.'],
            ]);
        }

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $accuracyM = (float) $validated['accuracy_m'];
        $distanceM = $this->geo->haversineMeters($lat, $lng, (float) $outlet->latitude, (float) $outlet->longitude);

        $geoEval = $this->geo->evaluate(
            distanceM: $distanceM,
            accuracyM: $accuracyM,
            radiusM: $outlet->radius_m,
            isEntryPhase: true,
        );

        if ($geoEval['blocked']) {
            throw ValidationException::withMessages([
                'location' => [$geoEval['block_message'] ?? 'Jarak terlalu jauh dari toko.'],
            ]);
        }

        $isUnverifiedOutlet = $outlet->status === LocationStatus::PENDING_VERIFICATION;
        $notes = trim((string) ($validated['notes'] ?? ''));

        if (($geoEval['requires_notes'] || $isUnverifiedOutlet) && mb_strlen($notes) < 10) {
            throw ValidationException::withMessages([
                'notes' => ['Alasan Visit In wajib diisi minimal 10 karakter untuk toko NOO, luar radius, atau akurasi GPS rendah.'],
            ]);
        }

        $gpsTokenResult = $this->tokenGuard->consumeGpsSessionToken((string) $validated['gps_session_token'], $user->id);
        $camTokenResult = $this->tokenGuard->consumeCameraToken((string) $validated['camera_token'], $user->id);

        $deviceId = $this->helper->resolveDeviceId($request);
        $fraud = $this->antiFraud->evaluate(
            userId: $user->id,
            localDate: $visitDate,
            lat: $lat,
            lng: $lng,
            accuracyM: $accuracyM,
            nowUtc: $nowUtc,
            deviceId: $deviceId,
            isInsideRadius: $distanceM <= $outlet->radius_m,
            isStaleTimestamp: $gpsTokenResult['stale'] || $camTokenResult['stale'],
            currentOutletId: $outlet->id,
        );

        $flags = [...$geoEval['flags'], ...$fraud['flags']];
        if (! $isEffectiveWorkDay) {
            $flags[] = 'OFF_DAY_ATTENDANCE';
        }
        if ($isUnverifiedOutlet) {
            $flags[] = 'UNVERIFIED_OUTLET';
        }
        $flags = array_values(array_unique($flags));
        $needsReview = $flags !== [];

        /** @var UploadedFile $photo */
        $photo = $request->file('photo');
        $photoPath = $this->imageEvidence->storeWatermarkedPhoto(
            file: $photo,
            userId: $user->id,
            nik: $user->nik,
            locationLabel: $outlet->name,
            lat: $lat,
            lng: $lng,
            accuracyM: $accuracyM,
            serverTime: $nowUtc,
            timezone: $outlet->timezone,
            employeeName: $user->name,
            distanceM: $distanceM,
        );

        try {
            $visit = DB::transaction(function () use (
                $user,
                $outlet,
                $visitDate,
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
                $needsReview,
                $flags,
            ): SalesVisit {
                User::query()->where('id', $user->id)->lockForUpdate()->first();

                $this->autoCloseStaleVisits($user->id, $visitDate);

                $hasInProgress = SalesVisit::query()
                    ->where('user_id', $user->id)
                    ->where('visit_status', VisitStatus::IN_PROGRESS)
                    ->exists();

                if ($hasInProgress) {
                    throw ValidationException::withMessages([
                        'visit' => ['Anda masih memiliki kunjungan yang sedang berjalan. Selesaikan Visit Out terlebih dahulu.'],
                    ]);
                }

                $this->helper->cancelPendingLeaveDayOnPresence($user->id, $visitDate);

                return SalesVisit::query()->create([
                    'user_id' => $user->id,
                    'outlet_location_id' => $outlet->id,
                    'outlet_lat' => $outlet->latitude,
                    'outlet_lng' => $outlet->longitude,
                    'outlet_radius_m' => $outlet->radius_m,
                    'source' => AttendanceSource::APP,
                    'visit_date' => $visitDate,
                    'visit_in_at' => $nowUtc,
                    'visit_in_photo_url' => $photoPath,
                    'visit_in_lat' => $lat,
                    'visit_in_lng' => $lng,
                    'visit_in_accuracy_m' => $accuracyM,
                    'visit_in_distance_m' => $distanceM,
                    'visit_in_device_id' => $deviceId !== '' ? $deviceId : null,
                    'visit_in_ip' => $request->ip(),
                    'visit_in_user_agent' => $request->userAgent(),
                    'visit_in_notes' => $notes !== '' ? $notes : null,
                    'visit_in_idempotency_key' => $idempotencyKey,
                    'visit_status' => VisitStatus::IN_PROGRESS,
                    'verification_status' => $needsReview ? VerificationStatus::PENDING_REVIEW : VerificationStatus::AUTO_APPROVED,
                    'anomaly_flags' => $flags !== [] ? $flags : null,
                ]);
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                $replayed = SalesVisit::query()
                    ->where('user_id', $user->id)
                    ->where('visit_in_idempotency_key', $idempotencyKey)
                    ->first();

                if ($replayed !== null) {
                    $this->imageEvidence->delete($photoPath);

                    return response()->json(['visit' => $replayed, 'idempotent_replay' => true]);
                }
            }

            $this->imageEvidence->delete($photoPath);

            throw $e;
        } catch (Throwable $e) {
            $this->imageEvidence->delete($photoPath);

            throw $e;
        }

        return response()->json(['visit' => $visit], 201);
    }

    public function cancelVisitIn(Request $request, SalesVisit $visit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($visit->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        if ($visit->visit_status !== VisitStatus::IN_PROGRESS || $visit->visit_out_at !== null) {
            throw ValidationException::withMessages([
                'visit' => ['Kunjungan sudah selesai atau tidak dapat dibatalkan.'],
            ]);
        }

        if ($visit->visit_in_at === null || $visit->visit_in_at->diffInSeconds(now('UTC')) > 300) {
            throw ValidationException::withMessages([
                'visit' => ['Batas waktu pembatalan Visit In (5 menit) sudah terlewati.'],
            ]);
        }

        $cancelledToday = SalesVisit::query()
            ->where('user_id', $user->id)
            ->whereDate('visit_date', $visit->visit_date->toDateString())
            ->where('visit_status', VisitStatus::CANCELLED)
            ->count();

        if ($cancelledToday >= self::MAX_CANCELS_PER_DAY) {
            throw ValidationException::withMessages([
                'visit' => [sprintf(
                    'Batas pembatalan Visit In maksimal %d kali per hari telah tercapai.',
                    self::MAX_CANCELS_PER_DAY,
                )],
            ]);
        }

        $visit->update([
            'visit_status' => VisitStatus::CANCELLED,
            'cancel_reason' => trim((string) $validated['cancel_reason']),
        ]);

        return response()->json(['visit' => $visit->fresh()]);
    }

    public function visitOut(Request $request, SalesVisit $visit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($visit->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'activity_category_id' => ['required', 'integer', 'exists:activity_categories,id'],
            'visit_notes' => ['required', 'string', 'min:3', 'max:1000'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'min:0'],
            'gps_session_token' => ['required', 'string'],
        ]);

        $idempotencyKey = (string) $validated['idempotency_key'];

        $existingByKey = SalesVisit::query()
            ->where('visit_out_idempotency_key', $idempotencyKey)
            ->first();

        if ($existingByKey !== null) {
            if ($existingByKey->user_id !== $user->id) {
                return response()->json(['message' => 'Idempotency key sudah digunakan oleh pengguna lain.'], 409);
            }

            return response()->json(['visit' => $existingByKey, 'idempotent_replay' => true]);
        }

        if ($visit->visit_status !== VisitStatus::IN_PROGRESS) {
            throw ValidationException::withMessages([
                'visit' => ['Kunjungan ini tidak sedang dalam status berjalan.'],
            ]);
        }

        /** @var Location $outlet */
        $outlet = $visit->outletLocation;
        // Snapshot from Visit In wins: HR may later edit outlet coords/radius, history must stay truthful.
        $outletLat = $visit->outlet_lat !== null ? (float) $visit->outlet_lat : (float) $outlet->latitude;
        $outletLng = $visit->outlet_lng !== null ? (float) $visit->outlet_lng : (float) $outlet->longitude;
        $outletRadiusM = $visit->outlet_radius_m ?? $outlet->radius_m;
        /** @var ActivityCategory $category */
        $category = ActivityCategory::query()->findOrFail((int) $validated['activity_category_id']);

        $nowUtc = CarbonImmutable::now('UTC');
        $visitDate = $visit->visit_date->toDateString();
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $accuracyM = (float) $validated['accuracy_m'];
        $distanceM = $this->geo->haversineMeters($lat, $lng, $outletLat, $outletLng);

        $geoEval = $this->geo->evaluate(
            distanceM: $distanceM,
            accuracyM: $accuracyM,
            radiusM: $outletRadiusM,
            isEntryPhase: false,
            bypassRadius: false,
            isVisitOut: true,
        );

        $notesRequired = $geoEval['requires_notes'] || in_array('OUT_OF_RADIUS', $geoEval['flags'], true)
            || in_array('UNCERTAIN_ACCURACY', $geoEval['flags'], true)
            || in_array('LOW_ACCURACY', $geoEval['flags'], true);

        if ($notesRequired && mb_strlen(trim((string) $validated['visit_notes'])) < 10) {
            throw ValidationException::withMessages([
                'visit_notes' => ['Catatan Visit Out wajib diisi minimal 10 karakter karena di luar radius atau akurasi GPS rendah.'],
            ]);
        }

        $gpsTokenResult = $this->tokenGuard->consumeGpsSessionToken((string) $validated['gps_session_token'], $user->id);

        $deviceId = $this->helper->resolveDeviceId($request);
        $fraud = $this->antiFraud->evaluate(
            userId: $user->id,
            localDate: $visitDate,
            lat: $lat,
            lng: $lng,
            accuracyM: $accuracyM,
            nowUtc: $nowUtc,
            deviceId: $deviceId,
            isInsideRadius: $distanceM <= $outletRadiusM,
            isStaleTimestamp: $gpsTokenResult['stale'],
            currentOutletId: $outlet->id,
        );

        $durationMinutes = $visit->visit_in_at !== null
            ? (int) $visit->visit_in_at->diffInMinutes($nowUtc)
            : 0;

        $existingFlags = $visit->anomaly_flags ?? [];
        $outFlags = [...$existingFlags, ...$geoEval['flags'], ...$fraud['flags']];

        if (! $this->balanceService->isEffectiveWorkDay($user, $visitDate)) {
            $outFlags[] = 'OFF_DAY_ATTENDANCE';
        }

        if ($durationMinutes < 3 && ! $category->exempt_short_duration) {
            $outFlags[] = 'SHORT_DURATION';
        }

        if ($outlet->status !== LocationStatus::ACTIVE) {
            $outFlags[] = 'UNVERIFIED_OUTLET';
        }

        $outFlags = array_values(array_unique($outFlags));
        $verificationStatus = $outFlags !== []
            ? VerificationStatus::PENDING_REVIEW
            : VerificationStatus::AUTO_APPROVED;

        $visit->update([
            'visit_out_at' => $nowUtc,
            'duration_minutes' => $durationMinutes,
            'activity_category_id' => $category->id,
            'visit_notes' => trim((string) $validated['visit_notes']),
            'visit_out_lat' => $lat,
            'visit_out_lng' => $lng,
            'visit_out_accuracy_m' => $accuracyM,
            'visit_out_distance_m' => $distanceM,
            'visit_out_device_id' => $deviceId !== '' ? $deviceId : null,
            'visit_out_ip' => $request->ip(),
            'visit_out_user_agent' => $request->userAgent(),
            'visit_out_idempotency_key' => $idempotencyKey,
            'visit_status' => VisitStatus::COMPLETED,
            'verification_status' => $verificationStatus,
            'anomaly_flags' => $outFlags !== [] ? $outFlags : null,
        ]);

        return response()->json(['visit' => $visit->fresh()]);
    }

    private function autoCloseStaleVisits(int $userId, string $todayBranch): void
    {
        $staleVisits = SalesVisit::query()
            ->where('user_id', $userId)
            ->where('visit_status', VisitStatus::IN_PROGRESS)
            ->whereDate('visit_date', '<', $todayBranch)
            ->get();

        foreach ($staleVisits as $stale) {
            $flags = $stale->anomaly_flags ?? [];
            if (! in_array('MISSED_VISIT_OUT', $flags, true)) {
                $flags[] = 'MISSED_VISIT_OUT';
            }

            $stale->update([
                'visit_status' => VisitStatus::AUTO_CLOSED,
                'verification_status' => VerificationStatus::PENDING_REVIEW,
                'anomaly_flags' => $flags,
            ]);
        }
    }

    /**
     * Never expose exact outlet lat/lng to frontend (US-005 privacy rule).
     *
     * @return array{id: int, code: string|null, name: string, address: string, radius_m: int, status: string, distance_m: float|null}
     */
    private function serializeOutlet(Location $loc, ?float $distanceM): array
    {
        return [
            'id' => $loc->id,
            'code' => $loc->code,
            'name' => $loc->name,
            'address' => $loc->address,
            'radius_m' => $loc->radius_m,
            'status' => $loc->status->value,
            'distance_m' => $distanceM,
        ];
    }
}
