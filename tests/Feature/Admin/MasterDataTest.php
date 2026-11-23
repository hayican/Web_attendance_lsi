<?php

use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\User;
use App\Services\LoginThrottleService;

test('HR admin cannot manipulate self and system enforces minimum 2 active HR admins', function () {
    $office = Location::factory()->create(['type' => LocationType::OFFICE]);
    $hr1 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id, 'is_active' => true]);
    $hr2 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id, 'is_active' => true]);

    // Self-manipulation blocked
    $this->actingAs($hr1)->patchJson("/admin/users/{$hr1->id}", ['is_active' => false])->assertForbidden();
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
