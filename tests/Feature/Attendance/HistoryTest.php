<?php

use App\Enums\AttendanceSource;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\AttendanceLog;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

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

test('attendance history supports 30 day range and exposes selfie evidence details', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 09:00:00', 'Asia/Jakarta'));

    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $user = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'office_location_id' => $office->id,
    ]);

    AttendanceLog::query()->create([
        'user_id' => $user->id,
        'location_id' => $office->id,
        'source' => AttendanceSource::APP,
        'work_date' => '2026-11-25',
        'attempt_no' => 1,
        'is_active_attempt' => null,
        'clock_in_at' => CarbonImmutable::parse('2026-11-25 08:05:00', 'Asia/Jakarta')->utc(),
        'clock_in_photo_url' => 'evidence/office/clock-in.jpg',
        'clock_in_status' => VerificationStatus::AUTO_APPROVED,
        'clock_in_notes' => 'Sinyal dalam gedung',
        'late_minutes' => 5,
    ]);

    $this->actingAs($user)
        ->get('/attendance/history?range=30d')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('office/Riwayat')
            ->where('mode', 'office')
            ->where('range', '30d')
            ->has('items', 1)
            ->where('items.0.clock_in_notes', 'Sinyal dalam gedung')
            ->where('items.0.late_minutes', 5)
            ->where('items.0.source', AttendanceSource::APP->value)
            ->where('items.0.clock_in_photo_url', fn (mixed $url): bool => is_string($url) && str_contains($url, 'signature='))
        );

    CarbonImmutable::setTestNow();
});

test('sales history uses sales-specific page component', function () {
    $office = Location::factory()->create(['timezone' => 'Asia/Jakarta']);
    $sales = User::factory()->create([
        'role' => UserRole::EMPLOYEE_SALES,
        'office_location_id' => $office->id,
        'default_shift_pattern_id' => null,
    ]);

    $this->actingAs($sales)
        ->get('/attendance/history')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/Riwayat')
            ->where('mode', 'sales')
        );
});
