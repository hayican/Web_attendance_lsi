<?php

use App\Enums\AttendanceSource;
use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\User;
use App\Models\UserShiftSchedule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

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
        'clock_in_status' => VerificationStatus::APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
        'clock_out_at' => CarbonImmutable::parse('2026-11-22 17:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::APPROVED,
    ]);

    $payload = [
        'work_date' => '2026-11-24',
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
        ->whereDate('work_date', '2026-11-24')
        ->firstOrFail();
    expect($schedule->is_day_off)->toBeTrue()
        ->and($schedule->source)->toBe(ScheduleSource::MANUAL_OVERRIDE)
        ->and($schedule->compensation_source_date?->toDateString())->toBe('2026-11-22')
        ->and($schedule->compensation_source_type)->toBe('ATTENDANCE_LOG')
        ->and($schedule->compensation_source_id)->not->toBeNull()
        ->and($schedule->notes)->toBe('Ganti hari libur 22 November');

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            ...$payload,
            'work_date' => '2026-11-25',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            ...$payload,
            'work_date' => '2026-11-25',
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
        'verification_status' => VerificationStatus::APPROVED,
        'anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$sales->id}/schedule-override", [
            'work_date' => '2026-11-24',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Ganti hari kunjungan Minggu',
        ])->assertOk();

    expect(UserShiftSchedule::query()
        ->where('user_id', $sales->id)
        ->whereDate('work_date', '2026-11-24')
        ->whereDate('compensation_source_date', '2026-11-22')
        ->exists())->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('ganti hari enforces 25th-of-month cutoff including cross-year transition', function () {
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

    // Origin 1..24 (2026-11-22) -> deadline 2026-11-25
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));
    AttendanceLog::query()->create([
        'user_id' => $employee->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-22',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-22 09:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
        'clock_out_at' => CarbonImmutable::parse('2026-11-22 17:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::APPROVED,
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-11-26',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Melewati batas 25 Nov',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    // Origin 25..end (2026-11-28) -> deadline 2026-12-25
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-29 09:00:00', 'Asia/Jakarta'));
    AttendanceLog::query()->create([
        'user_id' => $employee->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-28',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-28 09:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
        'clock_out_at' => CarbonImmutable::parse('2026-11-28 17:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::APPROVED,
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-12-26',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-28',
            'notes' => 'Melewati batas 25 Des',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-12-25',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-28',
            'notes' => 'Tepat di batas 25 Des',
        ])->assertOk();

    // Cross-year origin 2026-12-28 -> deadline 2027-01-25
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-29 09:00:00', 'Asia/Jakarta'));
    AttendanceLog::query()->create([
        'user_id' => $employee->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-12-28',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-12-28 09:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
        'clock_out_at' => CarbonImmutable::parse('2026-12-28 17:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::APPROVED,
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2027-01-26',
            'is_day_off' => true,
            'replacement_for_date' => '2026-12-28',
            'notes' => 'Melewati batas 25 Jan tahun depan',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2027-01-25',
            'is_day_off' => true,
            'replacement_for_date' => '2026-12-28',
            'notes' => 'Tepat di batas 25 Jan tahun depan',
        ])->assertOk();

    CarbonImmutable::setTestNow();
});

test('ganti hari rejects collective leave origin, 1-phase or unapproved evidence, and active today sales visit', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'type' => LocationType::OFFICE,
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $outlet = Location::factory()->create(['type' => LocationType::OUTLET, 'timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $employee = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    // Collective leave origin must be rejected even with 2-phase APPROVED attendance
    Holiday::query()->create([
        'holiday_date' => '2026-11-20',
        'location_id' => null,
        'location_scope_id' => 0,
        'description' => 'Cuti Bersama',
        'holiday_type' => HolidayType::COLLECTIVE_LEAVE,
    ]);
    AttendanceLog::query()->create([
        'user_id' => $employee->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-20',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-20 09:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
        'clock_out_at' => CarbonImmutable::parse('2026-11-20 17:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::APPROVED,
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-11-24',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-20',
            'notes' => 'Coba tukar cuti bersama',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    // 1-phase only (no clock_out) must be rejected
    AttendanceLog::query()->create([
        'user_id' => $employee->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-21',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-21 09:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
        'clock_out_at' => null,
        'clock_out_status' => null,
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-11-24',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-21',
            'notes' => 'Hanya clock-in 1 fase',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    // AUTO_APPROVED (not HR APPROVED) must be rejected
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
        'clock_out_at' => CarbonImmutable::parse('2026-11-22 17:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-11-24',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Belum APPROVED HR',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    // Sales visit IN_PROGRESS / AUTO_APPROVED rejected as origin, and active visit today blocks same-day override
    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'source' => AttendanceSource::APP,
        'visit_date' => '2026-11-22',
        'visit_in_at' => CarbonImmutable::parse('2026-11-22 10:00:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::IN_PROGRESS,
        'verification_status' => VerificationStatus::APPROVED,
        'anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
    ]);
    $this->actingAs($hr)
        ->postJson("/admin/users/{$sales->id}/schedule-override", [
            'work_date' => '2026-11-24',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Kunjungan belum selesai',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_for_date');

    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'source' => AttendanceSource::APP,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 08:30:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::IN_PROGRESS,
        'verification_status' => VerificationStatus::PENDING_REVIEW,
    ]);
    $this->actingAs($hr)
        ->postJson("/admin/users/{$sales->id}/schedule-override", [
            'work_date' => '2026-11-23',
            'is_day_off' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('work_date');

    CarbonImmutable::setTestNow();
});

test('HR can cancel and reschedule ganti hari and MasterData exposes eligible origins and upcoming schedules', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'type' => LocationType::OFFICE,
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $outlet = Location::factory()->create(['type' => LocationType::OUTLET, 'timezone' => 'Asia/Jakarta']);
    $shift = Shift::query()->create([
        'code' => 'REG',
        'name' => 'Reguler',
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'grace_period_minutes' => 15,
        'auto_close_hours' => 4,
        'is_active' => true,
    ]);
    $pattern = ShiftPattern::query()->create([
        'name' => 'Mon-Fri',
        'mon_shift_id' => $shift->id,
        'tue_shift_id' => $shift->id,
        'wed_shift_id' => $shift->id,
        'thu_shift_id' => $shift->id,
        'fri_shift_id' => $shift->id,
        'sat_shift_id' => null,
        'sun_shift_id' => null,
        'is_active' => true,
    ]);

    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $employee = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => $pattern->id,
    ]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
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
        'clock_in_status' => VerificationStatus::APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
        'clock_out_at' => CarbonImmutable::parse('2026-11-22 17:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_status' => VerificationStatus::APPROVED,
    ]);
    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'source' => AttendanceSource::APP,
        'visit_date' => '2026-11-22',
        'visit_in_at' => CarbonImmutable::parse('2026-11-22 10:00:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::AUTO_CLOSED,
        'verification_status' => VerificationStatus::APPROVED,
        'anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
    ]);

    // MasterData exposes both eligible origins with deadline 2026-11-25
    $this->actingAs($hr)
        ->get('/admin/master-data')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('eligibleGantiHariOrigins', 2)
            ->where('eligibleGantiHariOrigins.0.deadline_date', '2026-11-25')
            ->has('upcomingGantiHari', 0)
        );

    // Schedule ganti hari for both
    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-11-24',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Ganti hari kantor',
        ])->assertOk();

    $this->actingAs($hr)
        ->postJson("/admin/users/{$sales->id}/schedule-override", [
            'work_date' => '2026-11-24',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Ganti hari sales',
        ])->assertOk();

    // Now eligible origins = 0 and upcomingGantiHari = 2
    $this->actingAs($hr)
        ->get('/admin/master-data')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('eligibleGantiHariOrigins', 0)
            ->has('upcomingGantiHari', 2)
        );

    // Self-cancel forbidden
    $this->actingAs($hr)
        ->postJson("/admin/users/{$hr->id}/schedule-override/cancel", ['work_date' => '2026-11-24'])
        ->assertForbidden();

    // Cancel office ganti hari -> reverts to PATTERN and frees origin for reschedule
    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override/cancel", [
            'work_date' => '2026-11-24',
            'reason' => 'Reschedule ke 25 Nov',
        ])->assertOk()->assertJsonPath('cancelled', true);

    $reverted = UserShiftSchedule::query()
        ->where('user_id', $employee->id)
        ->whereDate('work_date', '2026-11-24')
        ->firstOrFail();
    expect($reverted->source)->toBe(ScheduleSource::PATTERN)
        ->and($reverted->shift_id)->toBe($shift->id)
        ->and($reverted->is_day_off)->toBeFalse()
        ->and($reverted->compensation_source_date)->toBeNull()
        ->and($reverted->compensation_source_type)->toBeNull()
        ->and($reverted->compensation_source_id)->toBeNull()
        ->and($reverted->notes)->toBeNull()
        ->and($reverted->created_by)->toBeNull();

    expect(AuditLog::query()
        ->where('entity', 'user_shift_schedules')
        ->where('record_id', $reverted->id)
        ->where('action', AuditAction::UPDATE)
        ->where('reason', 'Reschedule ke 25 Nov')
        ->exists())->toBeTrue();

    // Reschedule same origin to 2026-11-25 succeeds
    $this->actingAs($hr)
        ->postJson("/admin/users/{$employee->id}/schedule-override", [
            'work_date' => '2026-11-25',
            'is_day_off' => true,
            'replacement_for_date' => '2026-11-22',
            'notes' => 'Reschedule ganti hari',
        ])->assertOk();

    // Cancel sales ganti hari (no pattern) -> deletes row
    $this->actingAs($hr)
        ->postJson("/admin/users/{$sales->id}/schedule-override/cancel", [
            'work_date' => '2026-11-24',
        ])->assertOk()->assertJsonPath('cancelled', true);

    expect(UserShiftSchedule::query()
        ->where('user_id', $sales->id)
        ->whereDate('work_date', '2026-11-24')
        ->exists())->toBeFalse();

    // Cancelling again when no MANUAL_OVERRIDE exists -> 422
    $this->actingAs($hr)
        ->postJson("/admin/users/{$sales->id}/schedule-override/cancel", [
            'work_date' => '2026-11-24',
        ])->assertUnprocessable()->assertJsonValidationErrors('work_date');

    CarbonImmutable::setTestNow();
});
