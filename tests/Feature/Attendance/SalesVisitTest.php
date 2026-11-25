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
