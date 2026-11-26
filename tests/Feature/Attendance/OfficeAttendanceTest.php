<?php

use App\Enums\AttendanceSource;
use App\Enums\HolidayType;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\User;
use App\Services\TokenGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function setupOfficeUser(string $timezone = 'Asia/Jakarta', string $start = '08:00:00', string $end = '16:00:00'): array
{
    $location = Location::factory()->create([
        'latitude' => -6.2000000,
        'longitude' => 106.8166667,
        'radius_m' => 50,
        'timezone' => $timezone,
    ]);

    $shift = Shift::query()->create([
        'code' => 'TST-'.Str::random(4),
        'name' => 'Test Shift',
        'start_time' => $start,
        'end_time' => $end,
        'grace_period_minutes' => 10,
        'auto_close_hours' => 5,
        'is_active' => true,
    ]);

    $pattern = ShiftPattern::query()->create([
        'name' => 'Pattern '.Str::random(4),
        'mon_shift_id' => $shift->id,
        'tue_shift_id' => $shift->id,
        'wed_shift_id' => $shift->id,
        'thu_shift_id' => $shift->id,
        'fri_shift_id' => $shift->id,
        'sat_shift_id' => $shift->id,
        'sun_shift_id' => $shift->id,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $location->id,
        'default_shift_pattern_id' => $pattern->id,
    ]);

    return [$user, $location, $shift];
}

test('office clock-in validates radius vs accuracy, idempotency replay, and on-the-fly schedule fallback', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:05:00', 'Asia/Jakarta'));

    [$user, $location] = setupOfficeUser();
    $tokens = app(TokenGuardService::class);

    // d=40m (offset lat), R=50m, a=80m -> UNCERTAIN_ACCURACY -> requires notes
    $gps = $tokens->issueGpsSessionToken($user->id);
    $cam = $tokens->issueCameraToken($user->id);
    $idemKey = (string) Str::uuid();

    $response = $this->actingAs($user)
        ->withCookie(EnsureDeviceCookie::COOKIE_NAME, (string) Str::uuid())
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => $idemKey,
            'lat' => -6.2003500,
            'lng' => 106.8166667,
            'accuracy_m' => 80,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
            'notes' => 'Sinyal dalam gedung',
        ]);

    $response->assertCreated()
        ->assertJsonPath('attendance.clock_in_status', VerificationStatus::PENDING_REVIEW->value);

    expect($response->json('attendance.clock_in_anomaly_flags'))->toContain('UNCERTAIN_ACCURACY');

    // On-the-fly schedule fallback created user_shift_schedules row
    expect($user->shiftSchedules()->whereDate('work_date', '2026-11-23')->exists())->toBeTrue();

    // FR-5: Replaying same idempotency_key with already-consumed tokens returns 200 OK
    $this->actingAs($user)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => $idemKey,
            'lat' => -6.2003500,
            'lng' => 106.8166667,
            'accuracy_m' => 80,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
            'notes' => 'Sinyal dalam gedung',
        ])->assertOk()->assertJsonPath('idempotent_replay', true);

    CarbonImmutable::setTestNow();
});

test('office clock-in hard-blocks distance over max(200m, R) unless DINAS_LUAR is approved', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:05:00', 'Asia/Jakarta'));

    [$user] = setupOfficeUser();
    $tokens = app(TokenGuardService::class);
    $gps = $tokens->issueGpsSessionToken($user->id);
    $cam = $tokens->issueCameraToken($user->id);

    // ~500m away -> blocked 422
    $this->actingAs($user)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.2045000,
            'lng' => 106.8166667,
            'accuracy_m' => 15,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
        ])->assertUnprocessable();

    // Approve DINAS_LUAR -> radius unlocked & leave_request_id linked
    $leave = LeaveRequest::query()->create([
        'user_id' => $user->id,
        'type' => LeaveType::DINAS_LUAR,
        'start_date' => '2026-11-23',
        'end_date' => '2026-11-23',
        'total_days' => 1.0,
        'destination_location' => 'Bandung',
        'scheduled_start_time' => '09:00:00',
        'reason' => 'Meeting klien',
        'status' => LeaveStatus::APPROVED,
    ]);
    LeaveRequestDay::query()->create([
        'leave_request_id' => $leave->id,
        'user_id' => $user->id,
        'leave_date' => '2026-11-23',
        'day_value' => 1.0,
        'status' => LeaveDayStatus::APPROVED,
    ]);

    $dinasResponse = $this->actingAs($user)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.9175000,
            'lng' => 107.6191000,
            'accuracy_m' => 15,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
        ])->assertCreated()
        ->assertJsonPath('attendance.leave_request_id', $leave->id)
        ->assertJsonPath('attendance.clock_in_status', VerificationStatus::PENDING_REVIEW->value);

    expect($dinasResponse->json('attendance.clock_in_anomaly_flags'))->toContain('OFFSITE_DUTY');

    CarbonImmutable::setTestNow();
});

test('SHARED_DEVICE retroactively flags first user and IMPOSSIBLE_TRAVEL flags teleportation', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:05:00', 'Asia/Jakarta'));

    [$user1, $location] = setupOfficeUser();
    $user2 = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $location->id,
        'default_shift_pattern_id' => $user1->default_shift_pattern_id,
    ]);

    $deviceId = (string) Str::uuid();
    $tokens = app(TokenGuardService::class);

    $g1 = $tokens->issueGpsSessionToken($user1->id);
    $c1 = $tokens->issueCameraToken($user1->id);

    $res1 = $this->actingAs($user1)
        ->withCredentials()
        ->withUnencryptedCookie(EnsureDeviceCookie::COOKIE_NAME, $deviceId)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $g1['token'],
            'camera_token' => $c1['token'],
            'photo' => UploadedFile::fake()->image('u1.jpg', 320, 240),
        ])->assertCreated();

    $log1Id = (int) $res1->json('attendance.id');
    expect($res1->json('attendance.clock_in_status'))->toBe(VerificationStatus::AUTO_APPROVED->value);

    // User 2 clocks in with the SAME device cookie -> both get SHARED_DEVICE + PENDING_REVIEW
    $g2 = $tokens->issueGpsSessionToken($user2->id);
    $c2 = $tokens->issueCameraToken($user2->id);

    $res2 = $this->actingAs($user2)
        ->withCredentials()
        ->withUnencryptedCookie(EnsureDeviceCookie::COOKIE_NAME, $deviceId)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $g2['token'],
            'camera_token' => $c2['token'],
            'photo' => UploadedFile::fake()->image('u2.jpg', 320, 240),
        ])->assertCreated();

    expect($res2->json('attendance.clock_in_anomaly_flags'))->toContain('SHARED_DEVICE');

    $log1Fresh = AttendanceLog::query()->findOrFail($log1Id);
    expect($log1Fresh->clock_in_status)->toBe(VerificationStatus::PENDING_REVIEW)
        ->and($log1Fresh->clock_in_anomaly_flags)->toContain('SHARED_DEVICE');

    // User 1 clocks out 5 minutes later 100km away -> IMPOSSIBLE_TRAVEL
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:10:00', 'Asia/Jakarta'));
    $g3 = $tokens->issueGpsSessionToken($user1->id);
    $c3 = $tokens->issueCameraToken($user1->id);

    $resOut = $this->actingAs($user1)
        ->withCredentials()
        ->withUnencryptedCookie(EnsureDeviceCookie::COOKIE_NAME, $deviceId)
        ->postJson('/attendance/office/clock-out', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.9175000,
            'lng' => 107.6191000,
            'accuracy_m' => 10,
            'gps_session_token' => $g3['token'],
            'camera_token' => $c3['token'],
            'photo' => UploadedFile::fake()->image('u1out.jpg', 320, 240),
            'notes' => 'Pulang darurat',
        ])->assertOk();

    expect($resOut->json('attendance.clock_out_anomaly_flags'))->toContain('IMPOSSIBLE_TRAVEL');

    CarbonImmutable::setTestNow();
});

test('attendance:close-missed command marks missed clock-outs as PENDING_REVIEW with MISSED_CLOCK_OUT', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 21:30:00', 'Asia/Jakarta'));

    [$user, $location, $shift] = setupOfficeUser('Asia/Jakarta', '08:00:00', '16:00:00');
    $log = AttendanceLog::query()->create([
        'user_id' => $user->id,
        'location_id' => $location->id,
        'shift_id' => $shift->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-23',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-23 08:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    $this->artisan('attendance:close-missed')->assertSuccessful();

    $log->refresh();
    expect($log->clock_out_status)->toBe(VerificationStatus::PENDING_REVIEW)
        ->and($log->clock_out_anomaly_flags)->toContain('MISSED_CLOCK_OUT')
        ->and($log->clock_out_at)->toBeNull();

    CarbonImmutable::setTestNow();
});

test('office clock-in stores snapshots and close-missed uses the snapshot deadline', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:05:00', 'Asia/Jakarta'));

    [$user, $location, $shift] = setupOfficeUser('Asia/Jakarta', '08:00:00', '16:00:00');
    $tokens = app(TokenGuardService::class);
    $gps = $tokens->issueGpsSessionToken($user->id);
    $cam = $tokens->issueCameraToken($user->id);

    $res = $this->actingAs($user)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
        ])->assertCreated();

    $log = AttendanceLog::query()->findOrFail((int) $res->json('attendance.id'));
    expect($log->schedule_start_time)->toBe('08:00:00')
        ->and($log->schedule_end_time)->toBe('16:00:00')
        ->and($log->grace_period_minutes)->toBe(10)
        ->and($log->auto_close_hours)->toBe(5)
        ->and((float) $log->office_lat)->toBe(-6.2)
        ->and((float) $log->office_lng)->toBe(106.8166667)
        ->and($log->office_radius_m)->toBe(50);

    $shift->update(['auto_close_hours' => 1]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 17:30:00', 'Asia/Jakarta'));
    $this->artisan('attendance:close-missed')->assertSuccessful();
    expect($log->fresh()->clock_out_status)->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 21:30:00', 'Asia/Jakarta'));
    $this->artisan('attendance:close-missed')->assertSuccessful();
    expect($log->fresh()->clock_out_status)->toBe(VerificationStatus::PENDING_REVIEW)
        ->and($log->fresh()->clock_out_anomaly_flags)->toContain('MISSED_CLOCK_OUT');

    CarbonImmutable::setTestNow();
});

test('pending dinas luar opens the radius, locks review, and rejection syncs the attendance', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:05:00', 'Asia/Jakarta'));

    [$user, $location] = setupOfficeUser('Asia/Jakarta', '08:00:00', '16:00:00');
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $location->id]);

    $leave = LeaveRequest::query()->create([
        'user_id' => $user->id,
        'type' => LeaveType::DINAS_LUAR,
        'start_date' => '2026-11-23',
        'end_date' => '2026-11-23',
        'total_days' => 1.0,
        'destination_location' => 'Bandung',
        'scheduled_start_time' => '08:00:00',
        'reason' => 'Meeting klien',
        'status' => LeaveStatus::PENDING,
    ]);
    LeaveRequestDay::query()->create([
        'leave_request_id' => $leave->id,
        'user_id' => $user->id,
        'leave_date' => '2026-11-23',
        'day_value' => 1.0,
        'status' => LeaveDayStatus::PENDING,
    ]);

    $tokens = app(TokenGuardService::class);
    $gps = $tokens->issueGpsSessionToken($user->id);
    $cam = $tokens->issueCameraToken($user->id);

    $res = $this->actingAs($user)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.2045000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
        ])->assertCreated()
        ->assertJsonPath('attendance.clock_in_status', VerificationStatus::PENDING_REVIEW->value);

    expect($res->json('attendance.leave_request_id'))->toBe($leave->id)
        ->and($res->json('attendance.clock_in_anomaly_flags'))->toContain('OFFSITE_DUTY');

    $reviewLog = AttendanceLog::query()->findOrFail((int) $res->json('attendance.id'));
    expect($reviewLog->leave_request_id)->toBe($leave->id)
        ->and($reviewLog->leaveRequest?->status)->toBe(LeaveStatus::PENDING);

    $reviewLog->update(['clock_in_anomaly_flags' => null]);
    $this->actingAs($hr)->postJson('/admin/reviews/bulk-approve', [
        'items' => [['kind' => 'attendance', 'id' => $reviewLog->id, 'phase' => 'clock_in']],
    ])->assertOk()->assertJsonPath('approved_count', 0);
    expect($reviewLog->fresh()->clock_in_status)->toBe(VerificationStatus::PENDING_REVIEW);

    $this->actingAs($hr)
        ->postJson("/admin/attendance/{$res->json('attendance.id')}/review", [
            'phase' => 'clock_in',
            'decision' => 'APPROVED',
        ])->assertUnprocessable()
        ->assertJsonValidationErrors('attendance');

    $reviewLog->update([
        'clock_out_at' => CarbonImmutable::parse('2026-11-23 16:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::PENDING_REVIEW,
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/leaves/{$leave->id}/reject", ['reject_reason' => 'Tidak sesuai agenda'])
        ->assertOk();

    $log = AttendanceLog::query()->findOrFail((int) $res->json('attendance.id'));
    expect($log->clock_in_status)->toBe(VerificationStatus::REJECTED)
        ->and($log->clock_out_status)->toBe(VerificationStatus::REJECTED)
        ->and($log->is_active_attempt)->toBeNull()
        ->and($log->clock_in_reviewed_by)->toBe($hr->id)
        ->and($log->clock_out_reviewed_by)->toBe($hr->id)
        ->and($log->clock_in_reviewed_at)->not->toBeNull()
        ->and($log->clock_out_reviewed_at)->not->toBeNull()
        ->and($log->clock_in_reject_reason)->toBe('Tidak sesuai agenda')
        ->and($log->clock_out_reject_reason)->toBe('Tidak sesuai agenda');

    CarbonImmutable::setTestNow();
});

test('late permission requires ten characters of notes and adds LATE_PERMISSION', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:30:00', 'Asia/Jakarta'));

    [$user] = setupOfficeUser('Asia/Jakarta', '08:00:00', '16:00:00');
    $tokens = app(TokenGuardService::class);
    $gps = $tokens->issueGpsSessionToken($user->id);
    $cam = $tokens->issueCameraToken($user->id);

    $payload = [
        'idempotency_key' => (string) Str::uuid(),
        'lat' => -6.2000000,
        'lng' => 106.8166667,
        'accuracy_m' => 10,
        'gps_session_token' => $gps['token'],
        'camera_token' => $cam['token'],
        'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
        'is_late_permission' => true,
    ];

    $this->actingAs($user)->postJson('/attendance/office/clock-in', [...$payload, 'notes' => 'Telat'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notes');

    $res = $this->actingAs($user)->postJson('/attendance/office/clock-in', [...$payload, 'notes' => 'Sakit perut parah'])
        ->assertCreated()
        ->assertJsonPath('attendance.clock_in_status', VerificationStatus::PENDING_REVIEW->value)
        ->assertJsonPath('attendance.late_minutes', 30);

    expect($res->json('attendance.clock_in_anomaly_flags'))->toContain('LATE_PERMISSION');

    CarbonImmutable::setTestNow();
});

test('office clock-in on a holiday adds OFF_DAY_ATTENDANCE', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 09:00:00', 'Asia/Jakarta'));

    [$user] = setupOfficeUser('Asia/Jakarta', '08:00:00', '16:00:00');
    Holiday::query()->create([
        'holiday_date' => '2026-11-22',
        'description' => 'Libur Nasional',
        'holiday_type' => HolidayType::NATIONAL,
    ]);

    $tokens = app(TokenGuardService::class);
    $gps = $tokens->issueGpsSessionToken($user->id);
    $cam = $tokens->issueCameraToken($user->id);

    $res = $this->actingAs($user)
        ->postJson('/attendance/office/clock-in', [
            'idempotency_key' => (string) Str::uuid(),
            'lat' => -6.2000000,
            'lng' => 106.8166667,
            'accuracy_m' => 10,
            'gps_session_token' => $gps['token'],
            'camera_token' => $cam['token'],
            'photo' => UploadedFile::fake()->image('selfie.jpg', 320, 240),
            'notes' => 'Lembur hari libur',
        ])->assertCreated();

    expect($res->json('attendance.clock_in_anomaly_flags'))->toContain('OFF_DAY_ATTENDANCE');

    CarbonImmutable::setTestNow();
});

test('office page exposes ui summary without leaking device credentials', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 08:05:00', 'Asia/Jakarta'));

    [$user, $location, $shift] = setupOfficeUser();

    AttendanceLog::query()->create([
        'user_id' => $user->id,
        'location_id' => $location->id,
        'shift_id' => $shift->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-23',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-23 08:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_photo_url' => 'evidence/secret.jpg',
        'clock_in_lat' => -6.2000000,
        'clock_in_lng' => 106.8166667,
        'clock_in_accuracy_m' => 150,
        'clock_in_device_id' => 'device-secret',
        'clock_in_ip' => '10.10.10.10',
        'clock_in_user_agent' => 'secret-agent',
        'clock_in_idempotency_key' => (string) Str::uuid(),
        'clock_in_status' => VerificationStatus::PENDING_REVIEW,
        'clock_in_anomaly_flags' => ['LOW_ACCURACY'],
    ]);

    $this->actingAs($user)
        ->get('/attendance/office')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('office/Absen')
            ->where('office.latitude', -6.2)
            ->where('office.longitude', 106.8166667)
            ->where('office.radius_m', 50)
            ->where('effectiveStartIso', '2026-11-23T08:00:00+07:00')
            ->where('effectiveEndIso', '2026-11-23T16:00:00+07:00')
            ->where('closeDeadlineIso', '2026-11-23T21:00:00+07:00')
            ->where('activeLog.attempt_no', 1)
            ->where('activeLog.clock_in_status', VerificationStatus::PENDING_REVIEW->value)
            ->where('activeLog.clock_in_anomaly_flags', ['LOW_ACCURACY'])
            ->missing('activeLog.clock_in_device_id')
            ->missing('activeLog.clock_in_ip')
            ->missing('activeLog.clock_in_user_agent')
            ->missing('activeLog.clock_in_idempotency_key')
            ->missing('activeLog.clock_in_photo_url')
        );

    CarbonImmutable::setTestNow();
});

test('hr attendance uses admin-specific page component', function () {
    $location = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create([
        'role' => UserRole::HR_ADMIN,
        'office_location_id' => $location->id,
        'default_shift_pattern_id' => null,
    ]);

    $this->actingAs($hr)
        ->get('/attendance/office')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/AbsenSayaMobile')
        );
});

test('hr admin has a desktop attendance page', function () {
    $location = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create([
        'role' => UserRole::HR_ADMIN,
        'office_location_id' => $location->id,
        'default_shift_pattern_id' => null,
    ]);

    $this->actingAs($hr)
        ->get('/admin/absen-saya')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/AbsenSaya')
        );
});
