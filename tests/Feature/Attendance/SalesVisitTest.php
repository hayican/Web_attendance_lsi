<?php

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\ActivityCategory;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\TokenGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('outlet search hides exact coordinates and sorts by nearest distance', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    Location::factory()->create([
        'name' => 'Toko Jauh',
        'type' => LocationType::OUTLET,
        'latitude' => -6.2500000,
        'longitude' => 106.8500000,
    ]);

    Location::factory()->create([
        'name' => 'Toko Dekat',
        'type' => LocationType::OUTLET,
        'latitude' => -6.2001000,
        'longitude' => 106.8167000,
    ]);

    $res = $this->actingAs($sales)
        ->getJson('/attendance/sales/outlets?lat=-6.2000000&lng=106.8166667')
        ->assertOk();

    $outlets = $res->json('outlets');
    expect($outlets[0]['name'])->toBe('Toko Dekat')
        ->and($outlets[0])->not->toHaveKey('latitude')
        ->and($outlets[0])->not->toHaveKey('longitude');
});

test('sales visit page uses sales-specific component', function () {
    $office = Location::factory()->create([
        'type' => LocationType::OFFICE,
        'timezone' => 'Asia/Jakarta',
    ]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    $this->actingAs($sales)
        ->get('/attendance/sales')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/Kunjungan')
        );
});

test('sales visit page exposes safe visit summaries and remaining cancel quota', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:02:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'type' => LocationType::OFFICE,
        'timezone' => 'Asia/Jakarta',
    ]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $outlet = Location::factory()->create([
        'type' => LocationType::OUTLET,
        'status' => LocationStatus::ACTIVE,
        'latitude' => -6.2000000,
        'longitude' => 106.8166667,
        'radius_m' => 100,
        'timezone' => 'Asia/Jakarta',
    ]);
    $category = ActivityCategory::query()->create([
        'name' => 'Cek Stok',
        'exempt_short_duration' => false,
        'is_active' => true,
    ]);

    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 06:00:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::CANCELLED,
        'verification_status' => VerificationStatus::AUTO_APPROVED,
        'cancel_reason' => 'Salah pilih toko',
    ]);

    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 07:00:00', 'Asia/Jakarta')->utc(),
        'visit_out_at' => CarbonImmutable::parse('2026-11-23 07:30:00', 'Asia/Jakarta')->utc(),
        'duration_minutes' => 30,
        'activity_category_id' => $category->id,
        'visit_notes' => 'Stok aman',
        'visit_status' => VisitStatus::COMPLETED,
        'verification_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 08:00:00', 'Asia/Jakarta')->utc(),
        'visit_in_photo_url' => 'evidence/sales/secret.jpg',
        'visit_in_lat' => -6.2000000,
        'visit_in_lng' => 106.8166667,
        'visit_in_accuracy_m' => 10.5,
        'visit_in_distance_m' => 12.3,
        'visit_in_device_id' => 'device-secret',
        'visit_in_ip' => '10.10.10.10',
        'visit_in_user_agent' => 'secret-agent',
        'visit_in_idempotency_key' => (string) Str::uuid(),
        'visit_status' => VisitStatus::IN_PROGRESS,
        'verification_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    $this->actingAs($sales)
        ->get('/attendance/sales')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/Kunjungan')
            ->where('serverTimeIso', '2026-11-23T01:02:00+00:00')
            ->where('activeVisit.outlet_timezone', 'Asia/Jakarta')
            ->where('activeVisit.visit_in_time', '08:00')
            ->where('activeVisit.cancel_remaining_today', 2)
            ->where('activeVisit.can_cancel', true)
            ->missing('activeVisit.visit_in_lat')
            ->missing('activeVisit.visit_in_photo_url')
            ->missing('activeVisit.visit_in_device_id')
            ->has('todayVisits', 3)
            ->where('todayVisits.0.visit_in_time', '06:00')
            ->where('todayVisits.1.visit_in_time', '07:00')
            ->where('todayVisits.2.visit_in_time', '08:00')
            ->where('todayVisits.1.duration_minutes', 30)
            ->where('todayVisits.2.visit_status', VisitStatus::IN_PROGRESS->value)
            ->missing('todayVisits.0.outletLocation')
            ->missing('todayVisits.0.outlet_location')
            ->missing('todayVisits.0.visit_in_lat')
            ->missing('todayVisits.0.visit_in_lng')
            ->missing('todayVisits.0.visit_in_device_id')
            ->missing('todayVisits.0.visit_in_ip')
            ->missing('todayVisits.0.visit_in_user_agent')
            ->missing('todayVisits.0.visit_in_idempotency_key')
            ->missing('todayVisits.0.visit_in_photo_url')
            ->missing('todayVisits.2.visit_in_lat')
            ->missing('todayVisits.2.visit_in_lng')
            ->missing('todayVisits.2.visit_in_device_id')
            ->missing('todayVisits.2.visit_in_ip')
            ->missing('todayVisits.2.visit_in_user_agent')
            ->missing('todayVisits.2.visit_in_idempotency_key')
            ->missing('todayVisits.2.visit_in_photo_url')
        );

    foreach (['2026-11-23 05:00:00', '2026-11-23 05:30:00'] as $cancelledAt) {
        SalesVisit::query()->create([
            'user_id' => $sales->id,
            'outlet_location_id' => $outlet->id,
            'visit_date' => '2026-11-23',
            'visit_in_at' => CarbonImmutable::parse($cancelledAt, 'Asia/Jakarta')->utc(),
            'visit_status' => VisitStatus::CANCELLED,
            'verification_status' => VerificationStatus::AUTO_APPROVED,
            'cancel_reason' => 'Salah pilih toko',
        ]);
    }

    $this->actingAs($sales)
        ->get('/attendance/sales')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/Kunjungan')
            ->where('activeVisit.cancel_remaining_today', 0)
            ->where('activeVisit.can_cancel', false)
        );

    CarbonImmutable::setTestNow();
});

test('sales NOO requires <= 50m GPS accuracy and enforces duplicate warning', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    Location::factory()->create([
        'name' => 'Toko Existing',
        'type' => LocationType::OUTLET,
        'latitude' => -6.2000000,
        'longitude' => 106.8166667,
        'status' => LocationStatus::ACTIVE,
    ]);

    $this->actingAs($sales)->postJson('/attendance/sales/noo', [
        'name' => 'Toko Baru',
        'address' => 'Jl. Baru',
        'lat' => -6.2000500,
        'lng' => 106.8166667,
        'accuracy_m' => 70,
    ])->assertUnprocessable();

    $this->actingAs($sales)->postJson('/attendance/sales/noo', [
        'name' => 'Toko Baru',
        'address' => 'Jl. Baru',
        'lat' => -6.2000500,
        'lng' => 106.8166667,
        'accuracy_m' => 12,
    ])->assertStatus(409)->assertJsonPath('duplicate_warning', true);

    $this->actingAs($sales)->postJson('/attendance/sales/noo', [
        'name' => 'Toko Baru',
        'address' => 'Jl. Baru',
        'lat' => -6.2000500,
        'lng' => 106.8166667,
        'accuracy_m' => 12,
        'confirm_duplicate' => true,
    ])->assertCreated()->assertJsonPath('outlet.status', LocationStatus::PENDING_VERIFICATION->value);
});

test('sales NOO duplicate check covers pending outlets submitted by other sales', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $otherSales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    Location::factory()->create([
        'name' => 'Toko Pending Sales Lain',
        'type' => LocationType::OUTLET,
        'latitude' => -6.2000000,
        'longitude' => 106.8166667,
        'status' => LocationStatus::PENDING_VERIFICATION,
        'created_by' => $otherSales->id,
    ]);

    $this->actingAs($sales)->postJson('/attendance/sales/noo', [
        'name' => 'Toko Baru',
        'address' => 'Jl. Baru',
        'lat' => -6.2000300,
        'lng' => 106.8166800,
        'accuracy_m' => 12,
    ])->assertStatus(409)
        ->assertJsonPath('duplicate_warning', true)
        ->assertJsonPath('nearby_outlets.0.name', 'Toko Pending Sales Lain');
});

test('sales visit page lists own rejected NOO outlets with the rejection reason', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $otherSales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    Location::factory()->create([
        'name' => 'Toko Ditolak Sendiri',
        'type' => LocationType::OUTLET,
        'status' => LocationStatus::INACTIVE,
        'reject_reason' => 'Duplikat toko eksisting',
        'created_by' => $sales->id,
    ]);

    Location::factory()->create([
        'name' => 'Toko Ditolak Sales Lain',
        'type' => LocationType::OUTLET,
        'status' => LocationStatus::INACTIVE,
        'reject_reason' => 'Alasan sales lain',
        'created_by' => $otherSales->id,
    ]);

    $this->actingAs($sales)
        ->get('/attendance/sales')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/Kunjungan')
            ->has('rejectedOutlets', 1)
            ->where('rejectedOutlets.0.name', 'Toko Ditolak Sendiri')
            ->where('rejectedOutlets.0.reject_reason', 'Duplikat toko eksisting')
        );
});

test('sales visit-in with selfie, single in-progress lock, cancel <= 5m, and photo-less visit-out with SHORT_DURATION vs exempt category', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['type' => LocationType::OFFICE, 'timezone' => 'Asia/Jakarta']);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $outlet = Location::factory()->create([
        'type' => LocationType::OUTLET,
        'latitude' => -6.2000000,
        'longitude' => 106.8166667,
        'radius_m' => 100,
    ]);

    $normalCat = ActivityCategory::query()->create(['name' => 'Cek Stok', 'exempt_short_duration' => false, 'is_active' => true]);
    $closedCat = ActivityCategory::query()->create(['name' => 'Toko Tutup', 'exempt_short_duration' => true, 'is_active' => true]);

    $tokens = app(TokenGuardService::class);
    $g1 = $tokens->issueGpsSessionToken($sales->id);
    $c1 = $tokens->issueCameraToken($sales->id);
    $deviceId = (string) Str::uuid();

    $visitIn = $this->actingAs($sales)
        ->withCookie(EnsureDeviceCookie::COOKIE_NAME, $deviceId)
        ->postJson('/attendance/sales/visit-in', [
            'idempotency_key' => (string) Str::uuid(),
            'outlet_location_id' => $outlet->id,
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $g1['token'],
            'camera_token' => $c1['token'],
            'photo' => UploadedFile::fake()->image('visit.jpg', 320, 240),
        ])->assertCreated();

    $visitId = (int) $visitIn->json('visit.id');
    expect($visitIn->json('visit.visit_status'))->toBe(VisitStatus::IN_PROGRESS->value);

    // Second concurrent Visit In is blocked while IN_PROGRESS
    $g2 = $tokens->issueGpsSessionToken($sales->id);
    $c2 = $tokens->issueCameraToken($sales->id);
    $this->actingAs($sales)->postJson('/attendance/sales/visit-in', [
        'idempotency_key' => (string) Str::uuid(),
        'outlet_location_id' => $outlet->id,
        'lat' => -6.2000000,
        'lng' => 106.8166667,
        'accuracy_m' => 10,
        'gps_session_token' => $g2['token'],
        'camera_token' => $c2['token'],
        'photo' => UploadedFile::fake()->image('visit2.jpg', 320, 240),
    ])->assertUnprocessable();

    // Visit Out after 1 minute with normal category -> SHORT_DURATION PENDING_REVIEW (without photo!)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:01:00', 'Asia/Jakarta'));
    $gOut = $tokens->issueGpsSessionToken($sales->id);

    $visitOut = $this->actingAs($sales)
        ->withCookie(EnsureDeviceCookie::COOKIE_NAME, $deviceId)
        ->postJson("/attendance/sales/{$visitId}/visit-out", [
            'idempotency_key' => (string) Str::uuid(),
            'activity_category_id' => $normalCat->id,
            'visit_notes' => 'Cek stok cepat',
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $gOut['token'],
        ])->assertOk();

    expect($visitOut->json('visit.visit_status'))->toBe(VisitStatus::COMPLETED->value)
        ->and($visitOut->json('visit.verification_status'))->toBe(VerificationStatus::PENDING_REVIEW->value)
        ->and($visitOut->json('visit.anomaly_flags'))->toContain('SHORT_DURATION');

    // New visit with 'Toko Tutup' exempt_short_duration=true after 1 min -> AUTO_APPROVED
    $g3 = $tokens->issueGpsSessionToken($sales->id);
    $c3 = $tokens->issueCameraToken($sales->id);
    $v2 = $this->actingAs($sales)
        ->withCookie(EnsureDeviceCookie::COOKIE_NAME, $deviceId)
        ->postJson('/attendance/sales/visit-in', [
            'idempotency_key' => (string) Str::uuid(),
            'outlet_location_id' => $outlet->id,
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $g3['token'],
            'camera_token' => $c3['token'],
            'photo' => UploadedFile::fake()->image('visit3.jpg', 320, 240),
        ])->assertCreated();

    $v2Id = (int) $v2->json('visit.id');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:02:00', 'Asia/Jakarta'));
    $g4 = $tokens->issueGpsSessionToken($sales->id);

    $v2Out = $this->actingAs($sales)
        ->withCookie(EnsureDeviceCookie::COOKIE_NAME, $deviceId)
        ->postJson("/attendance/sales/{$v2Id}/visit-out", [
            'idempotency_key' => (string) Str::uuid(),
            'activity_category_id' => $closedCat->id,
            'visit_notes' => 'Toko sedang tutup',
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $g4['token'],
        ])->assertOk();

    expect($v2Out->json('visit.verification_status'))->toBe(VerificationStatus::AUTO_APPROVED->value);

    // Cancel within 5 minutes works
    $g5 = $tokens->issueGpsSessionToken($sales->id);
    $c5 = $tokens->issueCameraToken($sales->id);
    $v3 = $this->actingAs($sales)->postJson('/attendance/sales/visit-in', [
        'idempotency_key' => (string) Str::uuid(),
        'outlet_location_id' => $outlet->id,
        'lat' => -6.2000000,
        'lng' => 106.8166667,
        'accuracy_m' => 10,
        'gps_session_token' => $g5['token'],
        'camera_token' => $c5['token'],
        'photo' => UploadedFile::fake()->image('visit4.jpg', 320, 240),
    ])->assertCreated();

    $this->actingAs($sales)
        ->postJson('/attendance/sales/'.$v3->json('visit.id').'/cancel', [
            'cancel_reason' => 'Salah pilih toko',
        ])->assertOk()->assertJsonPath('visit.visit_status', VisitStatus::CANCELLED->value);

    // Test scheduler AUTO_CLOSED
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 23:59:30', 'Asia/Jakarta'));
    $stale = SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 15:00:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::IN_PROGRESS,
        'verification_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    $this->artisan('attendance:close-missed')->assertSuccessful();
    $stale->refresh();
    expect($stale->visit_status)->toBe(VisitStatus::AUTO_CLOSED)
        ->and($stale->verification_status)->toBe(VerificationStatus::PENDING_REVIEW)
        ->and($stale->anomaly_flags)->toContain('MISSED_VISIT_OUT');

    CarbonImmutable::setTestNow();
});

test('sales visit on a manual day off adds OFF_DAY_ATTENDANCE', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'type' => LocationType::OFFICE,
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $outlet = Location::factory()->create([
        'type' => LocationType::OUTLET,
        'latitude' => -6.2000000,
        'longitude' => 106.8166667,
        'radius_m' => 100,
    ]);

    UserShiftSchedule::query()->create([
        'user_id' => $sales->id,
        'work_date' => '2026-11-23',
        'shift_id' => null,
        'is_day_off' => true,
        'source' => ScheduleSource::MANUAL_OVERRIDE,
        'created_by' => $sales->id,
    ]);

    $tokens = app(TokenGuardService::class);
    $gps = $tokens->issueGpsSessionToken($sales->id);
    $cam = $tokens->issueCameraToken($sales->id);

    $res = $this->actingAs($sales)
        ->postJson('/attendance/sales/visit-in', [
            'idempotency_key' => (string) Str::uuid(),
            'outlet_location_id' => $outlet->id,
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('visit.jpg', 320, 240),
        ])->assertCreated()
        ->assertJsonPath('visit.verification_status', VerificationStatus::PENDING_REVIEW->value);

    expect($res->json('visit.anomaly_flags'))->toContain('OFF_DAY_ATTENDANCE');

    CarbonImmutable::setTestNow();
});

test('sales NOO daily quota follows the branch calendar day, not the UTC date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 01:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['type' => LocationType::OFFICE, 'timezone' => 'Asia/Jakarta']);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    // 00:10-00:50 WIB is still the previous UTC date; these five must count as today.
    foreach (range(0, 4) as $i) {
        Location::factory()->create([
            'type' => LocationType::OUTLET,
            'status' => LocationStatus::ACTIVE,
            'created_by' => $sales->id,
            'created_at' => CarbonImmutable::parse('2026-11-24 00:10:00', 'Asia/Jakarta')->addMinutes($i * 10)->utc(),
        ]);
    }

    $this->actingAs($sales)->postJson('/attendance/sales/noo', [
        'name' => 'Toko Keenam',
        'address' => 'Jl. Keenam',
        'lat' => -6.2000000,
        'lng' => 106.8166667,
        'accuracy_m' => 10,
        'confirm_duplicate' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('noo');

    CarbonImmutable::setTestNow();
});
