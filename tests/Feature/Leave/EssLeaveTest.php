<?php

use App\Enums\HolidayType;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\LeaveBalanceService;
use App\Services\ShiftScheduleGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function makePatternedUser(string $joinDate, UserRole $role = UserRole::EMPLOYEE_OFFICE): User
{
    $loc = Location::factory()->create(['timezone' => 'Asia/Jakarta', 'work_days' => [1, 2, 3, 4, 5]]);
    $shift = Shift::query()->create([
        'code' => 'S-'.Str::random(4),
        'name' => 'Office',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'grace_period_minutes' => 10,
        'auto_close_hours' => 5,
        'is_active' => true,
    ]);
    $pattern = ShiftPattern::query()->create([
        'name' => 'Pat-'.Str::random(4),
        'mon_shift_id' => $shift->id,
        'tue_shift_id' => $shift->id,
        'wed_shift_id' => $shift->id,
        'thu_shift_id' => $shift->id,
        'fri_shift_id' => $shift->id,
        'sat_shift_id' => null,
        'sun_shift_id' => null,
        'is_active' => true,
    ]);

    return User::factory()->create([
        'role' => $role,
        'office_location_id' => $loc->id,
        'default_shift_pattern_id' => $pattern->id,
        'join_date' => $joinDate,
    ]);
}

test('proration follows <= 15th vs > 15th cutoff and < 1 year employees get 0 collective leave deduction', function () {
    $balService = app(LeaveBalanceService::class);

    $u1 = makePatternedUser('2025-07-10');
    $q1 = $balService->calculateAnnualQuotaAndProration($u1, 2026, CarbonImmutable::parse('2026-07-10'));
    expect($q1['base_quota'])->toBe(12.0)->and($q1['prorated_deduction'])->toBe(6.0);

    $u2 = makePatternedUser('2025-07-20');
    $q2 = $balService->calculateAnnualQuotaAndProration($u2, 2026, CarbonImmutable::parse('2026-07-20'));
    expect($q2['base_quota'])->toBe(12.0)->and($q2['prorated_deduction'])->toBe(7.0);

    $newHire = makePatternedUser('2026-06-01');
    Holiday::query()->create([
        'holiday_date' => '2026-03-20',
        'description' => 'Cuti Bersama',
        'holiday_type' => HolidayType::COLLECTIVE_LEAVE,
    ]);
    $bal = $balService->ensureBalanceForYear($newHire, 2026, CarbonImmutable::parse('2026-11-23'));
    expect((float) $bal->base_quota)->toBe(0.0)
        ->and((float) $bal->collective_leave_deduction)->toBe(0.0);
});

test('cross-year CUTI locks pending_days per year and survives Jan 1 annual maintenance reset', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-20 09:00:00', 'Asia/Jakarta'));

    $user = makePatternedUser('2024-01-01');
    app(ShiftScheduleGeneratorService::class)->generateForUser($user, CarbonImmutable::parse('2026-12-20', 'Asia/Jakarta'), 90);

    // 2026-12-30 (Wed), 2026-12-31 (Thu), 2027-01-01 (Fri) -> 2 days in 2026, 1 day in 2027
    $res = $this->actingAs($user)->postJson('/leave', [
        'type' => LeaveType::CUTI->value,
        'start_date' => '2026-12-30',
        'end_date' => '2027-01-01',
        'reason' => 'Liburan akhir tahun',
    ])->assertCreated();

    expect((float) $res->json('leave_request.total_days'))->toBe(3.0);

    $bal2026 = LeaveBalance::query()->where('user_id', $user->id)->where('year', 2026)->firstOrFail();
    $bal2027 = LeaveBalance::query()->where('user_id', $user->id)->where('year', 2027)->firstOrFail();

    expect((float) $bal2026->pending_days)->toBe(2.0)
        ->and((float) $bal2027->pending_days)->toBe(1.0);

    // Run Jan 1 maintenance -> pending_days in 2027 MUST be preserved
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 00:10:00', 'Asia/Jakarta'));
    $this->artisan('leave:annual-maintenance')->assertSuccessful();

    $bal2027->refresh();
    expect((float) $bal2027->base_quota)->toBe(12.0)
        ->and((float) $bal2027->pending_days)->toBe(1.0);

    CarbonImmutable::setTestNow();
});

test('SAKIT requires SKD, DINAS_LUAR forbidden for sales, monthly > 2 days requires HR override, and HR partial cancel works', function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));

    $office = makePatternedUser('2023-01-01', UserRole::EMPLOYEE_OFFICE);
    $hr = makePatternedUser('2023-01-01', UserRole::HR_ADMIN);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->office_location_id,
        'default_shift_pattern_id' => null,
    ]);

    app(ShiftScheduleGeneratorService::class)->generateForUser($office, CarbonImmutable::parse('2026-11-18', 'Asia/Jakarta'), 95);

    // Sales cannot submit DINAS_LUAR
    $this->actingAs($sales)->postJson('/leave', [
        'type' => LeaveType::DINAS_LUAR->value,
        'start_date' => '2026-11-24',
        'end_date' => '2026-11-24',
        'destination_location' => 'Bandung',
        'scheduled_start_time' => '09:00',
        'reason' => 'Visit',
    ])->assertUnprocessable();

    // SAKIT without attachment fails; with SKD succeeds (H-2 backdate allowed)
    $this->actingAs($office)->postJson('/leave', [
        'type' => LeaveType::SAKIT->value,
        'start_date' => '2026-11-20',
        'end_date' => '2026-11-20',
        'reason' => 'Demam',
    ])->assertUnprocessable();

    $this->actingAs($office)->postJson('/leave', [
        'type' => LeaveType::SAKIT->value,
        'start_date' => '2026-11-20',
        'end_date' => '2026-11-20',
        'reason' => 'Demam',
        'attachment' => UploadedFile::fake()->image('skd.jpg', 300, 300),
    ])->assertCreated();

    // 3-day CUTI triggers monthly limit soft warning + HR override confirmation (409 -> confirm -> 200)
    $cutiRes = $this->actingAs($office)->postJson('/leave', [
        'type' => LeaveType::CUTI->value,
        'start_date' => '2026-11-26',
        'end_date' => '2026-11-30',
        'reason' => 'Acara keluarga',
    ])->assertCreated()->assertJsonPath('monthly_limit_warning', true);

    $leaveId = (int) $cutiRes->json('leave_request.id');

    $this->actingAs($hr)
        ->postJson("/admin/leaves/{$leaveId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('requires_override_confirmation', true);

    $this->actingAs($hr)
        ->postJson("/admin/leaves/{$leaveId}/approve", ['override_monthly_limit' => true])
        ->assertOk()
        ->assertJsonPath('leave_request.status', LeaveStatus::APPROVED->value);

    // Partial cancel 1 day (2026-11-30) -> PARTIALLY_CANCELLED & used_days drops 3 -> 2
    $leave = LeaveRequest::query()->with('days')->findOrFail($leaveId);
    $lastDayId = $leave->days->firstWhere('leave_date', CarbonImmutable::parse('2026-11-30'))?->id;

    $this->actingAs($hr)
        ->postJson("/admin/leaves/{$leaveId}/partial-cancel", [
            'day_ids' => [$lastDayId],
            'cancel_reason' => 'Masuk kerja lebih cepat',
        ])
        ->assertOk()
        ->assertJsonPath('leave_request.status', LeaveStatus::PARTIALLY_CANCELLED->value);

    $bal = LeaveBalance::query()->where('user_id', $office->id)->where('year', 2026)->firstOrFail();
    expect((float) $bal->used_days)->toBe(2.0);
    expect($leave->fresh()?->days->where('status', LeaveDayStatus::CANCELLED)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('annual maintenance scheduler handles 1-year anniversary proration, Feb 29 leap rule, and Jan 1 reset for < 1 year employees', function () {
    $julyHire = makePatternedUser('2025-07-10');
    $leapHire = makePatternedUser('2024-02-29');
    $juniorNextYear = makePatternedUser('2026-06-01');

    // Pre-existing collective leave in Aug 2026 & Aug 2027
    Holiday::query()->create([
        'holiday_date' => '2026-08-18',
        'description' => 'Cuti Bersama 2026',
        'holiday_type' => HolidayType::COLLECTIVE_LEAVE,
    ]);
    Holiday::query()->create([
        'holiday_date' => '2027-08-18',
        'description' => 'Cuti Bersama 2027',
        'holiday_type' => HolidayType::COLLECTIVE_LEAVE,
    ]);

    // Feb 28, 2025: leapHire turns 1 year (Feb 29 -> Feb 28 in non-leap year 2025)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-28 00:05:00', 'Asia/Jakarta'));
    $this->artisan('leave:annual-maintenance')->assertSuccessful();
    $leapBal = LeaveBalance::query()->where('user_id', $leapHire->id)->where('year', 2025)->firstOrFail();
    expect((float) $leapBal->base_quota)->toBe(12.0)->and((float) $leapBal->prorated_deduction)->toBe(2.0);

    // July 9, 2026 (day before anniversary): julyHire still has 0 quota & 0 collective deduction
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-09 00:05:00', 'Asia/Jakarta'));
    $this->artisan('leave:annual-maintenance')->assertSuccessful();
    $julyBal = LeaveBalance::query()->where('user_id', $julyHire->id)->where('year', 2026)->firstOrFail();
    expect((float) $julyBal->base_quota)->toBe(0.0)->and((float) $julyBal->collective_leave_deduction)->toBe(0.0);

    // July 10, 2026 (exact 1-year anniversary): julyHire gets 12 - 6 = 6 days & collective leave deduction 1
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 00:05:00', 'Asia/Jakarta'));
    $this->artisan('leave:annual-maintenance')->assertSuccessful();
    $this->artisan('leave:annual-maintenance')->assertSuccessful();
    $julyBal->refresh();
    expect((float) $julyBal->base_quota)->toBe(12.0)
        ->and((float) $julyBal->prorated_deduction)->toBe(6.0)
        ->and((float) $julyBal->collective_leave_deduction)->toBe(1.0);

    // Jan 1, 2027 reset:
    // - julyHire gets full 12 days (prorated_deduction = 0) + 2027 collective leave deduction = 1
    // - juniorNextYear (< 1 yr on 2027-01-01) gets base_quota = 0, prorated = 0, collective = 0 (not negative!)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 00:05:00', 'Asia/Jakarta'));
    $this->artisan('leave:annual-maintenance')->assertSuccessful();

    $july2027 = LeaveBalance::query()->where('user_id', $julyHire->id)->where('year', 2027)->firstOrFail();
    $junior2027 = LeaveBalance::query()->where('user_id', $juniorNextYear->id)->where('year', 2027)->firstOrFail();

    expect((float) $july2027->base_quota)->toBe(12.0)
        ->and((float) $july2027->prorated_deduction)->toBe(0.0)
        ->and((float) $july2027->collective_leave_deduction)->toBe(1.0);
    expect((float) $junior2027->base_quota)->toBe(0.0)
        ->and((float) $junior2027->prorated_deduction)->toBe(0.0)
        ->and((float) $junior2027->collective_leave_deduction)->toBe(0.0);

    CarbonImmutable::setTestNow();
});

test('CUTI requires at least H-3 effective workdays before start', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));

    $user = makePatternedUser('2024-01-01');
    app(ShiftScheduleGeneratorService::class)->generateForUser(
        $user,
        CarbonImmutable::parse('2026-11-20', 'Asia/Jakarta'),
        30,
    );

    // 2026-11-25 is only H-2 effective days away from Monday 2026-11-23.
    $this->actingAs($user)->postJson('/leave', [
        'type' => LeaveType::CUTI->value,
        'start_date' => '2026-11-25',
        'end_date' => '2026-11-25',
        'reason' => 'Keperluan keluarga',
    ])->assertUnprocessable()->assertJsonValidationErrors('start_date');

    // 2026-11-26 is exactly H-3 effective days away.
    $this->actingAs($user)->postJson('/leave', [
        'type' => LeaveType::CUTI->value,
        'start_date' => '2026-11-26',
        'end_date' => '2026-11-26',
        'reason' => 'Keperluan keluarga',
    ])->assertCreated();

    CarbonImmutable::setTestNow();
});

test('sales effective workday honors a manual day-off override', function () {
    $office = Location::factory()->create(['work_days' => [1, 2, 3, 4, 5]]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    $service = app(LeaveBalanceService::class);
    expect($service->isEffectiveWorkDay($sales, '2026-11-23'))->toBeTrue();

    UserShiftSchedule::query()->create([
        'user_id' => $sales->id,
        'work_date' => '2026-11-23',
        'shift_id' => null,
        'is_day_off' => true,
        'source' => ScheduleSource::MANUAL_OVERRIDE,
        'created_by' => $sales->id,
    ]);

    expect($service->isEffectiveWorkDay($sales, '2026-11-23'))->toBeFalse();
});

test('dinas luar scheduled start cannot pass the shift end on an effective day', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 09:00:00', 'Asia/Jakarta'));

    $user = makePatternedUser('2024-01-01');
    $shiftId = $user->defaultShiftPattern?->mon_shift_id;

    UserShiftSchedule::query()->create([
        'user_id' => $user->id,
        'work_date' => '2026-11-23',
        'shift_id' => $shiftId,
        'is_day_off' => false,
        'source' => ScheduleSource::PATTERN,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)->postJson('/leave', [
        'type' => LeaveType::DINAS_LUAR->value,
        'start_date' => '2026-11-23',
        'end_date' => '2026-11-23',
        'reason' => 'Meeting klien pagi',
        'destination_location' => 'Bandung',
        'scheduled_start_time' => '17:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_start_time');

    CarbonImmutable::setTestNow();
});
