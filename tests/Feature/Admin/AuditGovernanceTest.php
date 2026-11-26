<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

test('audit logs are hash-chained and append-only', function () {
    $actor = User::factory()->create();
    $logger = app(AuditLogger::class);

    $first = $logger->log(
        actorId: $actor->id,
        entity: 'users',
        recordId: $actor->id,
        action: AuditAction::CREATE,
        newValues: ['name' => 'First'],
    );
    $second = $logger->log(
        actorId: $actor->id,
        entity: 'users',
        recordId: $actor->id,
        action: AuditAction::UPDATE,
        oldValues: ['name' => 'First'],
        newValues: ['name' => 'Second'],
    );

    expect($first->row_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($second->prev_hash)->toBe($first->row_hash)
        ->and($second->row_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($logger->verifyHash($first))->toBeTrue()
        ->and($logger->verifyHash($second))->toBeTrue()
        ->and(fn () => $first->update(['reason' => 'tampered']))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $first->delete())
        ->toThrow(RuntimeException::class);
});

test('audit logs are readable by HR admins and flagged users only', function () {
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN]);
    $viewer = User::factory()->create([
        'role' => UserRole::EMPLOYEE_OFFICE,
        'can_view_audit_logs' => true,
    ]);
    $plain = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE]);

    $this->actingAs($hr)->get('/admin/audit-logs')->assertOk();
    $this->actingAs($viewer)->get('/admin/audit-logs')->assertOk();
    $this->actingAs($plain)->get('/admin/audit-logs')->assertForbidden();
});

test('audit log page exposes verified diff data', function () {
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN]);

    app(AuditLogger::class)->log(
        actorId: $hr->id,
        entity: 'users',
        recordId: $hr->id,
        action: AuditAction::UPDATE,
        oldValues: ['name' => 'Old'],
        newValues: ['name' => 'New'],
        reason: 'Uji audit',
    );

    $this->actingAs($hr)
        ->get('/admin/audit-logs')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/AuditLogs')
            ->has('logs', 1)
            ->where('logs.0.action', AuditAction::UPDATE->value)
            ->where('logs.0.actor.nik', $hr->nik)
            ->where('logs.0.old_values.name', 'Old')
            ->where('logs.0.new_values.name', 'New')
            ->where('logs.0.reason', 'Uji audit')
            ->where('logs.0.has_hash', true)
            ->where('logs.0.hash_valid', true)
            ->where('logs.0.chain_valid', true)
        );
});

test('audit page marks pre-hash logs as legacy', function () {
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN]);

    DB::table('audit_logs')->insert([
        'actor_id' => $hr->id,
        'entity' => 'users',
        'record_id' => $hr->id,
        'action' => AuditAction::CREATE->value,
        'old_values' => null,
        'new_values' => null,
        'reason' => null,
        'prev_hash' => null,
        'row_hash' => null,
        'created_at' => now('UTC'),
    ]);

    $this->actingAs($hr)
        ->get('/admin/audit-logs')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/AuditLogs')
            ->has('logs', 1)
            ->where('logs.0.has_hash', false)
            ->where('logs.0.hash_valid', false)
            ->where('logs.0.chain_valid', true)
        );
});

test('deactivating and reactivating a user stamps and clears deactivated_at', function () {
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN]);
    $employee = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE]);

    $this->actingAs($hr)
        ->patchJson("/admin/users/{$employee->id}", ['is_active' => false])
        ->assertOk();
    expect($employee->fresh()->deactivated_at)->not->toBeNull();

    $this->actingAs($hr)
        ->patchJson("/admin/users/{$employee->id}", ['is_active' => true])
        ->assertOk();
    expect($employee->fresh()->deactivated_at)->toBeNull();
});

test('retention removes reference photos for long-deactivated users only', function () {
    Storage::fake('local');

    $oldPath = 'evidence/90/reference/old.jpg';
    $activePath = 'evidence/91/reference/active.jpg';
    Storage::disk('local')->put($oldPath, 'old-photo');
    Storage::disk('local')->put($activePath, 'active-photo');

    $inactive = User::factory()->create([
        'is_active' => false,
        'deactivated_at' => now()->subMonths(13),
        'reference_photo_url' => $oldPath,
    ]);
    $active = User::factory()->create([
        'is_active' => true,
        'reference_photo_url' => $activePath,
    ]);

    $this->artisan('attendance:prune-retention')->assertSuccessful();

    expect(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and($inactive->fresh()->reference_photo_url)->toBeNull()
        ->and(Storage::disk('local')->exists($activePath))->toBeTrue()
        ->and($active->fresh()->reference_photo_url)->toBe($activePath);
});

test('audit logger chains multiple writes inside one transaction', function () {
    $actor = User::factory()->create();
    $logger = app(AuditLogger::class);

    /** @var array{0: AuditLog, 1: AuditLog} $logs */
    $logs = DB::transaction(function () use ($logger, $actor): array {
        return [
            $logger->log(
                actorId: $actor->id,
                entity: 'users',
                recordId: $actor->id,
                action: AuditAction::CREATE,
                newValues: ['name' => 'First'],
            ),
            $logger->log(
                actorId: $actor->id,
                entity: 'users',
                recordId: $actor->id,
                action: AuditAction::UPDATE,
                oldValues: ['name' => 'First'],
                newValues: ['name' => 'Second'],
            ),
        ];
    });

    [$first, $second] = $logs;

    expect($second->prev_hash)->toBe($first->row_hash)
        ->and($second->row_hash)->not->toBe($first->row_hash);
});
