<?php

use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Models\ActivityCategory;
use App\Models\AuditLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserShiftSchedule;
use App\Services\LoginThrottleService;
use Carbon\CarbonImmutable;

test('HR admin cannot manipulate self and system enforces minimum 2 active HR admins', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE]);
    $hr1 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id, 'is_active' => true]);
    $hr2 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id, 'is_active' => true]);

    // Self-manipulation blocked
    $this->actingAs($hr1)->patchJson("/admin/users/{$hr1->id}", ['is_active' => false])->assertForbidden();
    $this->actingAs($hr1)->patchJson("/admin/users/{$hr1->id}", ['office_location_id' => $office->id])->assertForbidden();
    $this->actingAs($hr1)->patchJson("/admin/users/{$hr1->id}", ['default_shift_pattern_id' => null])->assertForbidden();
    $this->actingAs($hr1)->postJson("/admin/users/{$hr1->id}/reset-password", ['new_password' => 'secret123'])->assertForbidden();
    $this->actingAs($hr1)->postJson("/admin/users/{$hr1->id}/unlock")->assertForbidden();

    // Demoting hr2 when only 2 active HR_ADMINs exist must be blocked (422)
    $this->actingAs($hr1)->patchJson("/admin/users/{$hr2->id}", ['role' => UserRole::EMPLOYEE_OFFICE->value])
        ->assertUnprocessable();

    // Adding a 3rd HR_ADMIN allows demoting hr2 + records audit_logs
    $hr3 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id, 'is_active' => true]);

    $this->actingAs($hr1)->patchJson("/admin/users/{$hr2->id}", ['role' => UserRole::EMPLOYEE_OFFICE->value])
        ->assertOk();

    expect(AuditLog::query()->where('entity', 'users')->where('record_id', $hr2->id)->where('action', AuditAction::UPDATE)->exists())->toBeTrue();
    expect($hr3->is_active)->toBeTrue();
});

test('HR can unlock user, import leave balances without formula injection, verify NOO, and create collective leave', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $emp = User::factory()->create(['nik' => 'EMP777', 'role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id, 'join_date' => '2024-01-01']);

    app(LoginThrottleService::class)->recordFailure('EMP777', '10.0.0.5', $emp->id);
    $this->actingAs($hr)->postJson("/admin/users/{$emp->id}/unlock")
        ->assertOk()
        ->assertJsonPath('lock_version', 1);

    // Formula injection blocked
    $this->actingAs($hr)->postJson('/admin/users/leave-balances/import', [
        'year' => 2026,
        'rows' => [['nik' => '=CMD()', 'adjustment_days' => 2]],
    ])->assertUnprocessable();

    $this->actingAs($hr)->postJson('/admin/users/leave-balances/import', [
        'year' => 2026,
        'rows' => [
            ['nik' => 'EMP777', 'adjustment_days' => 2.5],
            ['nik' => $hr->nik, 'adjustment_days' => 5], // self skipped
        ],
    ])->assertOk()->assertJsonPath('updated_count', 1);

    // NOO rejection requires reject_reason
    $noo = Location::factory()->create(['type' => LocationType::OUTLET, 'status' => LocationStatus::PENDING_VERIFICATION, 'code' => null]);
    $this->actingAs($hr)->postJson("/admin/locations/{$noo->id}/verify-noo", [
        'status' => LocationStatus::INACTIVE->value,
    ])->assertUnprocessable();

    $this->actingAs($hr)->postJson("/admin/locations/{$noo->id}/verify-noo", [
        'status' => LocationStatus::ACTIVE->value,
        'code' => 'OUT-001',
        'radius_m' => 120,
    ])->assertOk()->assertJsonPath('location.status', LocationStatus::ACTIVE->value);

    // Collective leave updates employee >= 1 year balance
    $this->actingAs($hr)->postJson('/admin/holidays', [
        'holiday_date' => '2026-03-20',
        'description' => 'Cuti Bersama Idul Fitri',
        'holiday_type' => HolidayType::COLLECTIVE_LEAVE->value,
    ])->assertCreated();

    expect((float) $emp->leaveBalances()->where('year', 2026)->value('collective_leave_deduction'))->toBe(1.0);
});

test('HR can CRUD shifts, shift patterns with 90-day generation, offices, outlets CSV, and activity categories', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE, 'code' => 'JKT']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $staff = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id]);

    // Shift CRUD
    $shiftRes = $this->actingAs($hr)->postJson('/admin/shifts', [
        'code' => 'PGT',
        'name' => 'Pagi Tetap',
        'start_time' => '08:00',
        'end_time' => '16:00',
        'grace_period_minutes' => 10,
        'auto_close_hours' => 5,
    ])->assertCreated();
    $shiftId = $shiftRes->json('shift.id');

    $this->actingAs($hr)->patchJson("/admin/shifts/{$shiftId}", [
        'name' => 'Pagi Kantor',
        'grace_period_minutes' => 15,
    ])->assertOk()->assertJsonPath('shift.grace_period_minutes', 15);

    // Shift Pattern CRUD + 90-day auto-generate preserving MANUAL_OVERRIDE
    $patternRes = $this->actingAs($hr)->postJson('/admin/shift-patterns', [
        'name' => 'Pola 5 Hari',
        'mon_shift_id' => $shiftId,
        'tue_shift_id' => $shiftId,
        'wed_shift_id' => $shiftId,
        'thu_shift_id' => $shiftId,
        'fri_shift_id' => $shiftId,
        'sat_shift_id' => null,
        'sun_shift_id' => null,
    ])->assertCreated();
    $patternId = $patternRes->json('pattern.id');

    // Shift cannot be deactivated while used by active pattern
    $this->actingAs($hr)->postJson("/admin/shifts/{$shiftId}/deactivate")->assertUnprocessable();

    // Assign pattern to staff -> auto-generates 90-day schedules
    $this->actingAs($hr)->patchJson("/admin/users/{$staff->id}", [
        'default_shift_pattern_id' => $patternId,
    ])->assertOk();

    expect(UserShiftSchedule::query()->where('user_id', $staff->id)->count())->toBe(91);

    // Manual override tomorrow must survive pattern regeneration
    $tomorrow = now('Asia/Jakarta')->addDay()->toDateString();
    $this->actingAs($hr)->postJson("/admin/users/{$staff->id}/schedule-override", [
        'work_date' => $tomorrow,
        'shift_id' => null,
        'is_day_off' => true,
    ])->assertOk();

    $this->actingAs($hr)->patchJson("/admin/shift-patterns/{$patternId}", [
        'name' => 'Pola 5 Hari Updated',
    ])->assertOk();

    $overrideRow = UserShiftSchedule::query()->where('user_id', $staff->id)->whereDate('work_date', $tomorrow)->first();
    expect($overrideRow?->source)->toBe(ScheduleSource::MANUAL_OVERRIDE);
    expect($overrideRow?->is_day_off)->toBeTrue();

    // Pattern deactivation blocked while active employee uses it
    $this->actingAs($hr)->postJson("/admin/shift-patterns/{$patternId}/deactivate")->assertUnprocessable();

    // Role change to SALES blocked if future DINAS_LUAR exists, succeeds after cancellation and clears pattern
    $dinas = LeaveRequest::query()->create([
        'user_id' => $staff->id,
        'type' => LeaveType::DINAS_LUAR,
        'start_date' => $tomorrow,
        'end_date' => $tomorrow,
        'total_days' => 1,
        'reason' => 'Visit klien',
        'destination_location' => 'Surabaya',
        'scheduled_start_time' => '09:00:00',
        'status' => LeaveStatus::PENDING,
    ]);

    $this->actingAs($hr)->patchJson("/admin/users/{$staff->id}", [
        'role' => UserRole::EMPLOYEE_SALES->value,
    ])->assertUnprocessable();

    $dinas->update(['status' => LeaveStatus::CANCELLED]);
    $this->actingAs($hr)->patchJson("/admin/users/{$staff->id}", [
        'role' => UserRole::EMPLOYEE_SALES->value,
    ])->assertOk()->assertJsonPath('user.default_shift_pattern_id', null);

    // Office CRUD + deactivate blocked when active users exist
    $officeRes = $this->actingAs($hr)->postJson('/admin/locations', [
        'type' => LocationType::OFFICE->value,
        'code' => 'MLG',
        'name' => 'Kantor Malang',
        'address' => 'Jl. Ijen 1',
        'latitude' => -7.9666,
        'longitude' => 112.6326,
        'radius_m' => 100,
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ])->assertCreated();
    $malangId = $officeRes->json('location.id');

    $this->actingAs($hr)->patchJson("/admin/locations/{$malangId}", [
        'radius_m' => 150,
    ])->assertOk()->assertJsonPath('location.radius_m', 150);

    $this->actingAs($hr)->postJson("/admin/locations/{$office->id}/deactivate")->assertUnprocessable();

    // Outlet CSV import blocks formula injection & commits atomically
    $this->actingAs($hr)->postJson('/admin/locations/outlets/import', [
        'rows' => [[
            'code' => '=HYPERLINK()',
            'name' => 'Toko A',
            'address' => 'Jl A',
            'latitude' => -7.25,
            'longitude' => 112.75,
        ]],
    ])->assertUnprocessable();

    $this->actingAs($hr)->postJson('/admin/locations/outlets/import', [
        'rows' => [[
            'code' => 'OUT-900',
            'name' => 'Toko Berkah',
            'address' => 'Jl Darmo 9',
            'latitude' => -7.28,
            'longitude' => 112.74,
            'radius_m' => 100,
            'timezone' => 'Asia/Jakarta',
        ]],
    ])->assertOk()->assertJsonPath('imported_count', 1);

    // Activity Category CRUD
    $catRes = $this->actingAs($hr)->postJson('/admin/activity-categories', [
        'name' => 'Display Produk',
        'exempt_short_duration' => false,
    ])->assertCreated();
    $catId = $catRes->json('category.id');

    $this->actingAs($hr)->patchJson("/admin/activity-categories/{$catId}", [
        'exempt_short_duration' => true,
    ])->assertOk()->assertJsonPath('category.exempt_short_duration', true);

    $this->actingAs($hr)->postJson("/admin/activity-categories/{$catId}/deactivate")
        ->assertOk()
        ->assertJsonPath('category.is_active', false);

    expect(ActivityCategory::query()->find($catId)?->is_active)->toBeFalse();
    expect(AuditLog::query()->where('entity', 'activity_categories')->where('record_id', $catId)->count())->toBe(3);
});

test('holiday create update and delete trigger recalculateLeaveRequestDays and tenure-based collective leave deduction', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 09:00:00', 'Asia/Jakarta'));

    $jkt = Location::factory()->create(['type' => LocationType::OFFICE, 'code' => 'JKT', 'work_days' => [1, 2, 3, 4, 5]]);
    $bali = Location::factory()->create(['type' => LocationType::OFFICE, 'code' => 'BLI', 'timezone' => 'Asia/Makassar', 'work_days' => [1, 2, 3, 4, 5]]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $jkt->id, 'join_date' => '2023-01-01']);

    $senior = User::factory()->create(['role' => UserRole::EMPLOYEE_SALES, 'office_location_id' => $jkt->id, 'join_date' => '2024-01-01']);
    $junior = User::factory()->create(['role' => UserRole::EMPLOYEE_SALES, 'office_location_id' => $jkt->id, 'join_date' => '2025-06-01']);
    $baliEmp = User::factory()->create(['role' => UserRole::EMPLOYEE_SALES, 'office_location_id' => $bali->id, 'join_date' => '2024-01-01']);

    // Senior has PENDING CUTI covering 2026-03-23..2026-03-25 (Mon-Wed = 3 workdays)
    LeaveBalance::query()->create([
        'user_id' => $senior->id,
        'year' => 2026,
        'base_quota' => 12,
        'prorated_deduction' => 0,
        'collective_leave_deduction' => 0,
        'used_days' => 0,
        'pending_days' => 3,
        'adjustment_days' => 0,
    ]);
    $leave = LeaveRequest::query()->create([
        'user_id' => $senior->id,
        'type' => LeaveType::CUTI,
        'start_date' => '2026-03-23',
        'end_date' => '2026-03-25',
        'total_days' => 3,
        'reason' => 'Cuti Maret',
        'status' => LeaveStatus::PENDING,
    ]);
    foreach (['2026-03-23', '2026-03-24', '2026-03-25'] as $d) {
        LeaveRequestDay::query()->create([
            'leave_request_id' => $leave->id,
            'user_id' => $senior->id,
            'leave_date' => $d,
            'day_value' => 1.0,
            'status' => LeaveDayStatus::PENDING,
        ]);
    }

    // Add COLLECTIVE_LEAVE on 2026-03-24:
    // - senior (>= 1 yr) gets collective_leave_deduction = 1, pending_days 3 -> 2, leave total_days 3 -> 2
    // - junior (< 1 yr on 2026-03-24) gets base_quota = 0 and collective_leave_deduction = 0
    $res = $this->actingAs($hr)->postJson('/admin/holidays', [
        'holiday_date' => '2026-03-24',
        'description' => 'Cuti Bersama Idul Fitri',
        'holiday_type' => HolidayType::COLLECTIVE_LEAVE->value,
    ])->assertCreated();
    $holidayId = $res->json('holiday.id');

    expect((float) $leave->fresh()->total_days)->toBe(2.0);
    expect((float) $senior->leaveBalances()->where('year', 2026)->value('pending_days'))->toBe(2.0);
    expect((float) $senior->leaveBalances()->where('year', 2026)->value('collective_leave_deduction'))->toBe(1.0);
    expect((float) $junior->leaveBalances()->where('year', 2026)->value('collective_leave_deduction'))->toBe(0.0);
    expect((float) $junior->leaveBalances()->where('year', 2026)->value('base_quota'))->toBe(0.0);
    expect(AuditLog::query()->where('entity', 'leave_balances')->where('action', AuditAction::UPDATE)->exists())->toBeTrue();

    // Move holiday from 2026-03-24 to 2026-03-25 and switch type to LOCAL (Bali only)
    // Senior (Jakarta) gets 2026-03-24 restored -> total_days 3, pending_days 3, collective_leave_deduction 0
    $this->actingAs($hr)->patchJson("/admin/holidays/{$holidayId}", [
        'holiday_date' => '2026-03-25',
        'location_id' => $bali->id,
        'description' => 'Nyepi Lokal Bali',
        'holiday_type' => HolidayType::LOCAL->value,
    ])->assertOk();

    expect((float) $leave->fresh()->total_days)->toBe(3.0);
    expect((float) $senior->leaveBalances()->where('year', 2026)->value('pending_days'))->toBe(3.0);
    expect((float) $senior->leaveBalances()->where('year', 2026)->value('collective_leave_deduction'))->toBe(0.0);
    expect((float) $baliEmp->leaveBalances()->where('year', 2026)->value('collective_leave_deduction'))->toBe(0.0);

    // Add NATIONAL holiday on 2026-03-23 then delete it -> restores leave back to 3.0
    $natRes = $this->actingAs($hr)->postJson('/admin/holidays', [
        'holiday_date' => '2026-03-23',
        'description' => 'Libur Nasional',
        'holiday_type' => HolidayType::NATIONAL->value,
    ])->assertCreated();
    expect((float) $leave->fresh()->total_days)->toBe(2.0);

    $this->actingAs($hr)->deleteJson('/admin/holidays/'.$natRes->json('holiday.id'))->assertOk();
    expect((float) $leave->fresh()->total_days)->toBe(3.0);
    expect((float) $senior->leaveBalances()->where('year', 2026)->value('pending_days'))->toBe(3.0);

    CarbonImmutable::setTestNow();
});
