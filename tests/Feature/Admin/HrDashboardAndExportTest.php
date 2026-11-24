<?php

use App\Enums\AttendanceSource;
use App\Enums\AuditAction;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Mail\AttendanceRejectedMail;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('HR cannot self-approve, reject queues email, and correct status blocks when newer attempt exists', function () {
    Mail::fake();

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr1 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $emp = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id]);

    $hrLog = AttendanceLog::query()->create([
        'user_id' => $hr1->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-23',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::PENDING_REVIEW,
    ]);

    // Self-approve blocked
    $this->actingAs($hr1)
        ->postJson("/admin/attendance/{$hrLog->id}/review", ['phase' => 'clock_in', 'decision' => 'APPROVED'])
        ->assertForbidden();

    $empLog1 = AttendanceLog::query()->create([
        'user_id' => $emp->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-23',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::PENDING_REVIEW,
    ]);

    // Reject releases is_active_attempt and queues email
    $this->actingAs($hr1)
        ->postJson("/admin/attendance/{$empLog1->id}/review", [
            'phase' => 'clock_in',
            'decision' => 'REJECTED',
            'reject_reason' => 'Foto buram',
        ])->assertOk();

    Mail::assertQueued(AttendanceRejectedMail::class);
    expect($empLog1->fresh()?->is_active_attempt)->toBeNull();

    // Attempt 2 exists -> correcting attempt 1 returns 409 Conflict
    AttendanceLog::query()->create([
        'user_id' => $emp->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-23',
        'attempt_no' => 2,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    $this->actingAs($hr1)
        ->postJson("/admin/attendance/{$empLog1->id}/correct", [
            'phase' => 'clock_in',
            'reason' => 'Coba koreksi',
        ])->assertStatus(409);
});

test('bulk approve skips fraud/missed flags and manual attendance cancels pending leave and logs audit', function () {
    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $emp1 = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id]);
    $emp2 = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id]);
    $shift = Shift::query()->create([
        'code' => 'JWD-T',
        'name' => 'Jakarta',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'grace_period_minutes' => 10,
        'auto_close_hours' => 5,
        'is_active' => true,
    ]);

    $eligible = AttendanceLog::query()->create([
        'user_id' => $emp1->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-23',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::PENDING_REVIEW,
        'clock_in_anomaly_flags' => ['OUT_OF_RADIUS'],
    ]);

    $excluded = AttendanceLog::query()->create([
        'user_id' => $emp2->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-22',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::PENDING_REVIEW,
        'clock_in_anomaly_flags' => ['SHARED_DEVICE'],
    ]);

    $this->actingAs($hr)->postJson('/admin/reviews/bulk-approve', [
        'items' => [
            ['kind' => 'attendance', 'id' => $eligible->id, 'phase' => 'clock_in'],
            ['kind' => 'attendance', 'id' => $excluded->id, 'phase' => 'clock_in'],
        ],
    ])->assertOk()->assertJsonPath('approved_count', 1);

    expect($eligible->fresh()?->clock_in_status)->toBe(VerificationStatus::APPROVED)
        ->and($excluded->fresh()?->clock_in_status)->toBe(VerificationStatus::PENDING_REVIEW);

    // Pending leave on 2026-11-24 gets auto-cancelled on manual HR input
    $pendingLeave = LeaveRequest::query()->create([
        'user_id' => $emp2->id,
        'type' => LeaveType::IZIN,
        'start_date' => '2026-11-24',
        'end_date' => '2026-11-24',
        'total_days' => 1.0,
        'reason' => 'Izin',
        'status' => LeaveStatus::PENDING,
    ]);
    $pendingDay = LeaveRequestDay::query()->create([
        'leave_request_id' => $pendingLeave->id,
        'user_id' => $emp2->id,
        'leave_date' => '2026-11-24',
        'day_value' => 1.0,
        'status' => LeaveDayStatus::PENDING,
    ]);

    $this->actingAs($hr)->postJson('/admin/attendance/manual', [
        'user_id' => $emp2->id,
        'work_date' => '2026-11-24',
        'shift_id' => $shift->id,
        'clock_in_time' => '08:00',
        'clock_out_time' => '16:00',
        'reason' => 'HP karyawan rusak berat sehingga input manual HR',
    ])->assertCreated();

    expect($pendingDay->fresh()?->status)->toBe(LeaveDayStatus::CANCELLED)
        ->and(AuditLog::query()->where('action', AuditAction::MANUAL_ATTENDANCE)->exists())->toBeTrue();
});

test('exports enforce 93 day max, warn on pending leaves, and write EXPORT_ATTENDANCE audit', function () {
    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);

    $this->actingAs($hr)->getJson('/admin/exports/talenta-office?start_date=2026-01-01&end_date=2026-06-01')
        ->assertUnprocessable();

    LeaveRequest::query()->create([
        'user_id' => $hr->id,
        'type' => LeaveType::IZIN,
        'start_date' => '2026-11-23',
        'end_date' => '2026-11-23',
        'total_days' => 1.0,
        'reason' => 'Pending',
        'status' => LeaveStatus::PENDING,
    ]);

    $this->actingAs($hr)->getJson('/admin/exports/talenta-office?start_date=2026-11-23&end_date=2026-11-23')
        ->assertStatus(409)
        ->assertJsonPath('requires_pending_confirmation', true);

    $this->actingAs($hr)->get('/admin/exports/talenta-office?start_date=2026-11-23&end_date=2026-11-23&confirm_pending=1')
        ->assertOk();

    $this->actingAs($hr)->get('/admin/exports/sales-visits?start_date=2026-11-23&end_date=2026-11-23')
        ->assertOk();

    $this->actingAs($hr)->get('/admin/exports/audit?start_date=2026-11-23&end_date=2026-11-23')
        ->assertOk();

    expect(AuditLog::query()->where('action', AuditAction::EXPORT_ATTENDANCE)->count())->toBe(3);
});
