<?php

use App\Enums\AttendanceSource;
use App\Enums\AuditAction;
use App\Enums\HolidayType;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\LocationType;
use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Mail\AttendanceRejectedMail;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Location;
use App\Models\SalesVisit;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\TalentaMapping;
use App\Models\User;
use App\Models\UserShiftSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 09:00:00', 'Asia/Jakarta'));

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

    CarbonImmutable::setTestNow();
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

    AttendanceLog::query()->create([
        'user_id' => $hr->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-23',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-23 08:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::PENDING_REVIEW,
    ]);

    $this->actingAs($hr)->getJson('/admin/exports/talenta-office?start_date=2026-11-23&end_date=2026-11-23')
        ->assertStatus(409)
        ->assertJsonPath('requires_pending_confirmation', true)
        ->assertJsonPath('pending_attendance_count', 1);

    $this->actingAs($hr)->get('/admin/exports/talenta-office?start_date=2026-11-23&end_date=2026-11-23&confirm_pending=1')
        ->assertOk();

    $this->actingAs($hr)->get('/admin/exports/sales-visits?start_date=2026-11-23&end_date=2026-11-23')
        ->assertOk();

    $this->actingAs($hr)->get('/admin/exports/audit?start_date=2026-11-23&end_date=2026-11-23')
        ->assertOk();

    expect(AuditLog::query()->where('action', AuditAction::EXPORT_ATTENDANCE)->count())->toBe(3);
});

test('talenta office export writes tenure-based time off codes for collective leave days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-20 09:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta', 'work_days' => [1, 2, 3, 4, 5]]);
    $hr = User::factory()->create([
        'nik' => 'HR001',
        'role' => UserRole::HR_ADMIN,
        'office_location_id' => $office->id,
        'join_date' => '2023-01-01',
    ]);
    $senior = User::factory()->create([
        'nik' => 'SEN01',
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'join_date' => '2024-01-01',
    ]);
    $junior = User::factory()->create([
        'nik' => 'JUN01',
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'join_date' => '2026-01-10',
    ]);

    Holiday::query()->create([
        'holiday_date' => '2026-03-20',
        'location_id' => null,
        'description' => 'Cuti Bersama Idul Fitri',
        'holiday_type' => HolidayType::COLLECTIVE_LEAVE,
    ]);

    $response = $this->actingAs($hr)
        ->get('/admin/exports/talenta-office?start_date=2026-03-20&end_date=2026-03-20&confirm_pending=1')
        ->assertOk();

    $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($response->streamedContent())) ?: []));
    $rows = collect(array_slice($lines, 1))
        ->map(fn (string $line): array => str_getcsv($line))
        ->keyBy(fn (array $row): string => $row[0]);

    expect($rows['SEN01'][3])->toBe('dayoff')
        ->and($rows['SEN01'][6])->toBe('')
        ->and($rows['SEN01'][15])->toBe('CB')
        ->and($rows['JUN01'][3])->toBe('dayoff')
        ->and($rows['JUN01'][6])->toBe('')
        ->and($rows['JUN01'][15])->toBe('CBU');

    CarbonImmutable::setTestNow();
});

test('daily recap shows Libur and Libur (Ganti Hari) instead of Alpa on a non-working day', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $regular = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $swapped = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    UserShiftSchedule::query()->create([
        'user_id' => $swapped->id,
        'work_date' => '2026-11-22',
        'shift_id' => null,
        'is_day_off' => true,
        'source' => ScheduleSource::MANUAL_OVERRIDE,
        'created_by' => $hr->id,
    ]);

    $res = $this->actingAs($hr)->get('/admin/dashboard?date=2026-11-22')->assertOk();
    $recap = collect($res->original->getData()['page']['props']['dailyRecap'])->keyBy('user_id');

    expect($recap[$regular->id]['attendance_status'])->toBe('Libur')
        ->and($recap[$swapped->id]['attendance_status'])->toBe('Libur (Ganti Hari)');

    CarbonImmutable::setTestNow();
});

test('dashboard recap does not create missing schedules', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $employee = makePatternUserWithoutSchedules($office, '2024-01-01');

    $this->actingAs($hr)->get('/admin/dashboard?date=2026-11-23')->assertOk();

    expect(UserShiftSchedule::query()
        ->where('user_id', $employee->id)
        ->whereDate('work_date', '2026-11-23')
        ->exists())->toBeFalse();

    CarbonImmutable::setTestNow();
});

test('talenta export does not create missing schedules', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $hr = User::factory()->create([
        'role' => UserRole::HR_ADMIN,
        'office_location_id' => $office->id,
        'join_date' => '2023-01-01',
    ]);
    $employee = makePatternUserWithoutSchedules($office, '2023-01-01');

    $this->actingAs($hr)
        ->get('/admin/exports/talenta-office?start_date=2026-11-23&end_date=2026-11-23&confirm_pending=1')
        ->assertOk();

    expect(UserShiftSchedule::query()
        ->where('user_id', $employee->id)
        ->whereDate('work_date', '2026-11-23')
        ->exists())->toBeFalse();

    CarbonImmutable::setTestNow();
});

function makePatternUserWithoutSchedules(Location $office, string $joinDate): User
{
    $shift = Shift::query()->create([
        'code' => 'M2-'.Str::random(4),
        'name' => 'M2 Shift',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'grace_period_minutes' => 10,
        'auto_close_hours' => 5,
        'is_active' => true,
    ]);
    $pattern = ShiftPattern::query()->create([
        'name' => 'M2-'.Str::random(4),
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
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => $pattern->id,
        'join_date' => $joinDate,
    ]);
}

test('talenta file 1 writes off-day overtime only when enabled and origin not swapped', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta', 'work_days' => [1, 2, 3, 4, 5]]);
    $hr = User::factory()->create([
        'role' => UserRole::HR_ADMIN,
        'office_location_id' => $office->id,
        'join_date' => '2023-01-01',
    ]);
    $employee = User::factory()->create([
        'nik' => 'OVT01',
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
        'join_date' => '2023-01-01',
    ]);

    Holiday::query()->create([
        'holiday_date' => '2026-11-22',
        'description' => 'Libur Minggu',
        'holiday_type' => HolidayType::NATIONAL,
    ]);

    AttendanceLog::query()->create([
        'user_id' => $employee->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-22',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => CarbonImmutable::parse('2026-11-22 09:00:00', 'Asia/Jakarta')->utc(),
        'clock_out_at' => CarbonImmutable::parse('2026-11-22 12:00:00', 'Asia/Jakarta')->utc(),
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
        'clock_out_status' => VerificationStatus::AUTO_APPROVED,
        'clock_in_anomaly_flags' => ['OFF_DAY_ATTENDANCE'],
    ]);

    TalentaMapping::query()->create([
        'mapping_key' => 'export_offday_overtime_to_talenta',
        'mapping_value' => 'true',
        'description' => 'Test',
    ]);

    $res = $this->actingAs($hr)
        ->get('/admin/exports/talenta-office?start_date=2026-11-22&end_date=2026-11-22&confirm_pending=1')
        ->assertOk();
    $row = collect(exportRows($res->streamedContent()))->first(fn (array $row): bool => $row[0] === 'OVT01');

    expect($row)->not->toBeNull()
        ->and($row[3])->toBe('dayoff')
        ->and($row[6])->toBe('')
        ->and($row[9])->toBe('09:00')
        ->and($row[10])->toBe('12:00');

    UserShiftSchedule::query()->create([
        'user_id' => $employee->id,
        'work_date' => '2026-11-30',
        'shift_id' => null,
        'is_day_off' => true,
        'source' => ScheduleSource::MANUAL_OVERRIDE,
        'notes' => 'Ganti hari 22 Nov',
        'replacement_for_date' => '2026-11-22',
        'created_by' => $hr->id,
    ]);

    $swapped = $this->actingAs($hr)
        ->get('/admin/exports/talenta-office?start_date=2026-11-22&end_date=2026-11-22&confirm_pending=1')
        ->assertOk();
    $swappedRow = collect(exportRows($swapped->streamedContent()))->first(fn (array $row): bool => $row[0] === 'OVT01');

    expect($swappedRow[9])->toBe('')
        ->and($swappedRow[10])->toBe('');

    CarbonImmutable::setTestNow();
});

test('talenta file 1 uses dinas times for schedule when mapping enabled', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta', 'work_days' => [1, 2, 3, 4, 5]]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id, 'join_date' => '2023-01-01']);
    $employee = makePatternUserWithoutSchedules($office, '2023-01-01');
    $employee->update(['nik' => 'DNS01']);

    UserShiftSchedule::query()->create([
        'user_id' => $employee->id,
        'work_date' => '2026-11-23',
        'shift_id' => $employee->defaultShiftPattern?->mon_shift_id,
        'is_day_off' => false,
        'source' => ScheduleSource::PATTERN,
        'created_by' => $hr->id,
    ]);

    $leave = LeaveRequest::query()->create([
        'user_id' => $employee->id,
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
        'user_id' => $employee->id,
        'leave_date' => '2026-11-23',
        'day_value' => 1.0,
        'status' => LeaveDayStatus::APPROVED,
    ]);

    $res = $this->actingAs($hr)
        ->get('/admin/exports/talenta-office?start_date=2026-11-23&end_date=2026-11-23&confirm_pending=1')
        ->assertOk();
    $row = collect(exportRows($res->streamedContent()))->first(fn (array $row): bool => $row[0] === 'DNS01');

    expect($row)->not->toBeNull()
        ->and($row[4])->toBe('09:00')
        ->and($row[5])->toBe('17:00');

    CarbonImmutable::setTestNow();
});

test('sales visit export hides unverified visits unless requested', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $sales = User::factory()->create([
        'nik' => 'SLS09',
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);
    $outlet = Location::factory()->create(['type' => LocationType::OUTLET, 'timezone' => 'Asia/Jakarta']);

    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'source' => AttendanceSource::APP,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta')->utc(),
        'visit_out_at' => CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta')->utc(),
        'duration_minutes' => 60,
        'visit_in_notes' => 'Kunjungan disetujui',
        'visit_status' => VisitStatus::COMPLETED,
        'verification_status' => VerificationStatus::AUTO_APPROVED,
    ]);
    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'source' => AttendanceSource::APP,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 11:00:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::IN_PROGRESS,
        'verification_status' => VerificationStatus::PENDING_REVIEW,
    ]);

    $default = $this->actingAs($hr)
        ->get('/admin/exports/sales-visits?start_date=2026-11-23&end_date=2026-11-23')
        ->assertOk();
    expect($default->streamedContent())->not->toContain('Pending');

    $all = $this->actingAs($hr)
        ->get('/admin/exports/sales-visits?start_date=2026-11-23&end_date=2026-11-23&include_unverified=1')
        ->assertOk();
    expect($all->streamedContent())->toContain('Pending');

    CarbonImmutable::setTestNow();
});

test('audit export builds a calendar grid with status and verification columns', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta', 'work_days' => [1, 2, 3, 4, 5]]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $employee = User::factory()->create([
        'nik' => 'GRID01',
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    $res = $this->actingAs($hr)
        ->get('/admin/exports/audit?start_date=2026-11-20&end_date=2026-11-22')
        ->assertOk();
    $content = $res->streamedContent();

    expect($content)->toContain('Status Kehadiran')
        ->toContain('Zona Waktu')
        ->toContain('Diverifikasi Oleh');

    $rows = collect(exportRows($content));
    $alpa = $rows->first(fn (array $row): bool => $row[0] === '2026-11-20' && $row[1] === 'GRID01');
    $libur = $rows->first(fn (array $row): bool => $row[0] === '2026-11-22' && $row[1] === 'GRID01');

    expect($alpa)->not->toBeNull()
        ->and($alpa[5])->toBe('Asia/Jakarta')
        ->and($alpa[10])->toBe('')
        ->and($alpa[11])->toBe('Alpa')
        ->and($libur)->not->toBeNull()
        ->and($libur[11])->toBe('Libur');

    CarbonImmutable::setTestNow();
});

test('manual HR attendance is limited to the last seven days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $employee = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id]);

    $payload = [
        'user_id' => $employee->id,
        'shift_id' => null,
        'clock_in_time' => '08:00',
        'clock_out_time' => '16:00',
        'reason' => 'HP karyawan rusak total',
    ];

    $this->actingAs($hr)->postJson('/admin/attendance/manual', [...$payload, 'work_date' => '2026-11-16'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('work_date');

    $this->actingAs($hr)->postJson('/admin/attendance/manual', [...$payload, 'work_date' => '2026-11-17'])
        ->assertCreated();

    CarbonImmutable::setTestNow();
});

/**
 * @return list<list<string>>
 */
function exportRows(string $content): array
{
    $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($content)) ?: []));
    array_shift($lines);

    return array_map(fn (string $line): array => str_getcsv($line), $lines);
}

test('dashboard exposes selected-date holidays and sales visits after preload', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $outlet = Location::factory()->create(['type' => LocationType::OUTLET, 'timezone' => 'Asia/Jakarta']);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    Holiday::query()->create([
        'holiday_date' => '2026-11-23',
        'description' => 'Libur Uji',
        'holiday_type' => HolidayType::NATIONAL,
    ]);
    SalesVisit::query()->create([
        'user_id' => $sales->id,
        'outlet_location_id' => $outlet->id,
        'source' => AttendanceSource::APP,
        'visit_date' => '2026-11-23',
        'visit_in_at' => CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta')->utc(),
        'visit_status' => VisitStatus::IN_PROGRESS,
        'verification_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    $res = $this->actingAs($hr)->get('/admin/dashboard?date=2026-11-23')->assertOk();
    $props = $res->original->getData()['page']['props'];

    expect($props['activeHolidays'])->toHaveCount(1)
        ->and($props['activeHolidays'][0]['description'])->toBe('Libur Uji')
        ->and($props['salesVisits'])->toHaveCount(1)
        ->and($props['salesVisits'][0]['outlet_name'])->toBe($outlet->name);

    CarbonImmutable::setTestNow();
});

test('approval queue flags HR leave that leaves fewer than two HR working', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $hr1 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);
    $hr2 = User::factory()->create(['role' => UserRole::HR_ADMIN, 'office_location_id' => $office->id]);

    $leave = LeaveRequest::query()->create([
        'user_id' => $hr1->id,
        'type' => LeaveType::CUTI,
        'start_date' => '2026-11-26',
        'end_date' => '2026-11-26',
        'total_days' => 1.0,
        'reason' => 'Keperluan keluarga',
        'status' => LeaveStatus::PENDING,
    ]);
    LeaveRequestDay::query()->create([
        'leave_request_id' => $leave->id,
        'user_id' => $hr1->id,
        'leave_date' => '2026-11-26',
        'day_value' => 1.0,
        'status' => LeaveDayStatus::PENDING,
    ]);

    $res = $this->actingAs($hr2)->get('/admin/leaves')->assertOk();
    $props = $res->original->getData()['page']['props'];
    $item = collect($props['requests'])->firstWhere('id', $leave->id);

    expect($item['hr_conflict_warning'])->toBeTrue();

    CarbonImmutable::setTestNow();
});
