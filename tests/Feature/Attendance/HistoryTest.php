<?php

use App\Enums\AttendanceSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\AttendanceLog;
use App\Models\Location;
use App\Models\User;

test('attendance history shows only authenticated user logs', function () {
    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $u1 = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id]);
    $u2 = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE, 'office_location_id' => $office->id]);

    AttendanceLog::query()->create([
        'user_id' => $u1->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => now()->toDateString(),
        'attempt_no' => 1,
        'is_active_attempt' => null,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::REJECTED,
        'clock_in_reject_reason' => 'Foto gelap',
    ]);

    AttendanceLog::query()->create([
        'user_id' => $u2->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => now()->toDateString(),
        'attempt_no' => 1,
        'is_active_attempt' => 1,
        'clock_in_at' => now(),
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
    ]);

    $res = $this->actingAs($u1)->get('/attendance/history')->assertOk();
    $props = $res->original->getData()['page']['props'];
    expect($props['items'])->toHaveCount(1)
        ->and($props['items'][0]['clock_in_reject_reason'])->toBe('Foto gelap');
});
