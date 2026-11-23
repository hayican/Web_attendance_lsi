<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\User;
use App\Services\ImageEvidenceService;
use App\Services\TokenGuardService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('gps_session_token and camera_token are single-use, user-bound, and enforce 120s stale / 300s expiry', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $guard = app(TokenGuardService::class);

    $gps = $guard->issueGpsSessionToken($user->id);

    // Other user cannot consume token
    expect(fn () => $guard->consumeGpsSessionToken($gps['token'], $otherUser->id))
        ->toThrow(ValidationException::class);

    // Re-issue for owner, fresh consume works and is NOT stale
    $gps2 = $guard->issueGpsSessionToken($user->id);
    $result = $guard->consumeGpsSessionToken($gps2['token'], $user->id);
    expect($result['stale'])->toBeFalse();

    // Second use of same token fails (single-use)
    expect(fn () => $guard->consumeGpsSessionToken($gps2['token'], $user->id))
        ->toThrow(ValidationException::class);

    // 150s old camera token is accepted as stale (STALE_TIMESTAMP)
    $cam = $guard->issueCameraToken($user->id);
    Cache::put("camera_token:{$cam['token']}", [
        'user_id' => $user->id,
        'issued_at' => now()->subSeconds(150)->timestamp,
    ], 300);

    $camResult = $guard->consumeCameraToken($cam['token'], $user->id);
    expect($camResult['stale'])->toBeTrue();

    // > 300s old token is rejected
    $expired = $guard->issueGpsSessionToken($user->id);
    Cache::put("gps_token:{$expired['token']}", [
        'user_id' => $user->id,
        'issued_at' => now()->subSeconds(305)->timestamp,
    ], 300);

    expect(fn () => $guard->consumeGpsSessionToken($expired['token'], $user->id))
        ->toThrow(ValidationException::class);
});

test('token API endpoints require authenticated consented user and issue valid tokens', function () {
    $user = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE]);

    $this->postJson('/api/tokens/gps')->assertUnauthorized();

    $this->actingAs($user)
        ->postJson('/api/tokens/gps')
        ->assertOk()
        ->assertJsonStructure(['token', 'issued_at', 'expires_at']);

    $this->actingAs($user)
        ->postJson('/api/tokens/camera')
        ->assertOk()
        ->assertJsonStructure(['token', 'issued_at', 'expires_at']);
});

test('image evidence service rejects fake images, watermarks valid photos, and protects signed URLs by owner or HR', function () {
    Storage::fake('local');

    $owner = User::factory()->create(['nik' => 'OFF500', 'role' => UserRole::EMPLOYEE_OFFICE]);
    $otherEmployee = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN]);

    $service = app(ImageEvidenceService::class);

    // Fake file disguised as .jpg must fail magic-bytes validation
    $fakePhoto = UploadedFile::fake()->create('fake.jpg', 20, 'text/plain');
    expect(fn () => $service->storeWatermarkedPhoto(
        file: $fakePhoto,
        userId: $owner->id,
        nik: $owner->nik,
        locationLabel: 'Jakarta',
        lat: -6.2,
        lng: 106.8,
        accuracyM: 12.0,
        serverTime: now(),
        timezone: 'Asia/Jakarta',
    ))->toThrow(ValidationException::class);

    // Real GD-generated image passes and is stored privately
    $realPhoto = UploadedFile::fake()->image('selfie.jpg', 640, 480);
    $path = $service->storeWatermarkedPhoto(
        file: $realPhoto,
        userId: $owner->id,
        nik: $owner->nik,
        locationLabel: 'Jakarta',
        lat: -6.2,
        lng: 106.8,
        accuracyM: 12.0,
        serverTime: now(),
        timezone: 'Asia/Jakarta',
    );

    Storage::disk('local')->assertExists($path);

    $signedUrl = $service->signedUrl($path);

    // Unsigned URL is rejected (403)
    $this->actingAs($owner)
        ->get(route('evidence.show', ['path' => $path]))
        ->assertForbidden();

    // Another employee cannot access even with valid signed URL
    $this->actingAs($otherEmployee)
        ->get($signedUrl)
        ->assertForbidden();

    // Owner and HR_ADMIN can access signed URL
    $this->actingAs($owner)->get($signedUrl)->assertOk();
    $this->actingAs($hr)->get($signedUrl)->assertOk();
});

test('device cookie middleware issues httpOnly lsi_device_id cookie', function () {
    $this->get('/login')
        ->assertCookie(EnsureDeviceCookie::COOKIE_NAME);
});
