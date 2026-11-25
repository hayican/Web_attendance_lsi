<?php

use App\Enums\AttendanceSource;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Models\AttendanceLog;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\User;
use App\Models\UserShiftSchedule;
use Carbon\CarbonImmutable;

test('office ganti hari requires a valid origin and cannot reuse it', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'type' => LocationType::OFFICE,
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $employee = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    AttendanceLog::query()->create([
        'user_id' => $employee->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-22',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-22 09:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
    ]);

    $payload = [
        'work_date' => '2026-11-30',
        'is_day_off' => true,
        'replacement_for_date' => '2026-11-22',
        'notes' => 'Ganti hari libur 22 November',
    ];

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [...$payload, 'notes' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notes');

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", $payload)
        ->assertOk();

    $schedule = UserShiftSchedule::query()
        ->where('user_id', $employee->id)
        ->whereDate('work_date', '2026-11-30')
        ->firstOrFail();
    expect($schedule->is_day_off)->toBeTrue()
        ->and($schedule->source)->toBe(ScheduleSource::MANUAL_OVERRIDE)
        ->and($schedule->replacement_for_date?->toDateString())->toBe('2026-11-22')
        ->and($schedule->notes)->toBe('Ganti hari libur 22 November');

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            ...$payload,
            'work_date' => '2026-12-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            ...$payload,
            'work_date' => '2026-12-02',
            'replacement_for_date' => '2026-11-21',
            'notes' => 'Origin tanpa kehadiran',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    CarbonImmutable::setTestNow();
});

test('sales ganti hari accepts an approved off-day visit as origin', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'type' => LocationType::OFFICE,
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $outlet = Location::factory()->create(['type' => LocationType::OUTLET, 'timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'source' => AttendanceSource::APP,
        'visit_date' => '2026-11-22',
        'visit_in_at' => CarbonImmutable::parse('2026-11-22 10:00:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::COMPLETED,
        'verification_status' => VerificationStatus::AUTO_APPROVED,
        'anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$sales->id}/schedule-override", [
            'work_date' => '2026-11-30',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Ganti hari kunjungan Minggu',
        ])->assertOk();

    expect(UserShiftSchedule::query()
        ->where('user_id', $sales->id)
        ->whereDate('work_date', '2026-11-30')
        ->whereDate('replacement_for_date', '2026-11-22')
        ->exists())->toBeTrue();

    CarbonImmutable::setTestNow();
});
