<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureSingleSession;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;

test('a newer login invalidates the previous session of the same account', function () {
    $user = User::factory()->create();

    Cache::put(EnsureSingleSession::key($user->id), 'session-from-other-device', now()->addMinutes(10));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a successful login records the active session marker', function () {
    $user = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    expect(Cache::get(EnsureSingleSession::key($user->id)))->toBe(session()->getId());
});

test('a session without a recorded marker keeps working', function () {
    $user = User::factory()->create();

    Cache::forget(EnsureSingleSession::key($user->id));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect('/attendance/office');
});

test('audit logger strips sensitive columns and keeps the hash chain verifiable', function () {
    $logger = app(AuditLogger::class);

    $log = $logger->log(
        actorId: null,
        entity: 'users',
        recordId: 1,
        action: AuditAction::UPDATE,
        oldValues: ['password_hash' => 'hash', 'remember_token' => 'token', 'name' => 'Lama'],
        newValues: ['password' => 'plain', 'two_factor_secret' => 'totp', 'name' => 'Baru'],
    );

    expect($log->old_values)->toBe(['name' => 'Lama'])
        ->and($log->new_values)->toBe(['name' => 'Baru'])
        ->and($log->actor_id)->toBeNull()
        ->and($logger->verifyHash($log->fresh()))->toBeTrue();
});
