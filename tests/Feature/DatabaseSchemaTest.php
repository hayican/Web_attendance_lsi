<?php

use App\Enums\AttendanceSource;
use App\Enums\LocationType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\ActivityCategory;
use App\Models\AttendanceLog;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeder creates 5 office branches, at least 2 active HR admins, and Toko Tutup exemption', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Location::query()->where('type', LocationType::OFFICE)->count())->toBe(5)
        ->and(User::query()->where('role', UserRole::HR_ADMIN)->where('is_active', true)->count())->toBeGreaterThanOrEqual(2)
        ->and(ActivityCategory::query()->where('name', 'Toko Tutup')->value('exempt_short_duration'))->toBeTrue();
});

test('attendance_logs enforces single active attempt per user and work_date while allowing retry after REJECTED', function () {
    $user = User::factory()->create();
    $workDate = '2026-11-20';

    $first = AttendanceLog::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->office_location_id,
        'source' => AttendanceSource::APP,
        'work_date' => $workDate,
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    expect(fn () => AttendanceLog::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->office_location_id,
        'source' => AttendanceSource::APP,
        'work_date' => $workDate,
        'attempt_no' => 2,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
    ]))->toThrow(QueryException::class);

    $first->update([
        'clock_in_status' => VerificationStatus::REJECTED,
        'is_active_attempt' => null,
    ]);

    $retry = AttendanceLog::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->office_location_id,
        'source' => AttendanceSource::APP,
        'work_date' => $workDate,
        'attempt_no' => 2,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::PENDING_REVIEW,
    ]);

    expect($retry->attempt_no)->toBe(2);
});

test('restrictOnDelete blocks deleting user that has attendance_logs', function () {
    $user = User::factory()->create();

    AttendanceLog::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->office_location_id,
        'source' => AttendanceSource::MANUAL_HR,
        'work_date' => '2026-11-20',
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::APPROVED,
    ]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});
