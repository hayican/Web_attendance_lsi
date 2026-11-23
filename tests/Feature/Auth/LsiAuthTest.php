<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\LoginThrottleService;

test('users can login using NIK or email and are redirected by role', function () {
    $office = User::factory()->create([
        'nik' => 'OFF100',
        'email' => 'off100@lsi.co.id',
        'role' => UserRole::EMPLOYEE_OFFICE,
    ]);

    $this->post('/login', [
        'email' => 'OFF100',
        'password' => 'password',
    ])->assertRedirect('/attendance/office');

    $this->assertAuthenticatedAs($office);
    $this->post('/logout');

    $sales = User::factory()->create([
        'nik' => 'SLS100',
        'email' => 'sls100@lsi.co.id',
        'role' => UserRole::EMPLOYEE_SALES,
    ]);

    $this->post('/login', [
        'email' => 'sls100@lsi.co.id',
        'password' => 'password',
    ])->assertRedirect('/attendance/sales');

    $this->assertAuthenticatedAs($sales);
    $this->post('/logout');

    $hr = User::factory()->create([
        'nik' => 'HR100',
        'email' => 'hr100@lsi.co.id',
        'role' => UserRole::HR_ADMIN,
    ]);

    $this->post('/login', [
        'email' => 'HR100',
        'password' => 'password',
    ])->assertRedirect('/admin/dashboard');

    $this->assertAuthenticatedAs($hr);
});

test('5 failed logins lock only that NIK+IP combination and unlockUser clears the lock', function () {
    $user = User::factory()->create(['nik' => 'OFF200']);

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->post('/login', [
                'email' => 'OFF200',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
    }

    // Locked on 10.0.0.1 even with correct password
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post('/login', [
            'email' => 'OFF200',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

    $this->assertGuest();

    // Another IP is NOT locked
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post('/login', [
            'email' => 'OFF200',
            'password' => 'password',
        ])->assertRedirect('/attendance/office');

    $this->post('/logout');

    // HR unlocks account via version increment -> 10.0.0.1 can login again
    app(LoginThrottleService::class)->unlockUser($user->id);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post('/login', [
            'email' => 'OFF200',
            'password' => 'password',
        ])->assertRedirect('/attendance/office');
});

test('inactive user cannot login and is logged out on active request', function () {
    $user = User::factory()->create([
        'nik' => 'OFF300',
        'is_active' => false,
    ]);

    $this->post('/login', [
        'email' => 'OFF300',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();

    // Simulate already-logged-in user deactivated by HR
    $this->actingAs($user)
        ->get('/attendance/office')
        ->assertRedirect('/login');

    $this->assertGuest();
});

test('user with must_change_password is forced to security page', function () {
    $user = User::factory()->create([
        'must_change_password' => true,
        'role' => UserRole::EMPLOYEE_OFFICE,
    ]);

    $this->actingAs($user)
        ->get('/attendance/office')
        ->assertRedirect('/settings/security');
});

test('user without consent is redirected to UU PDP consent page and decline logs out', function () {
    $user = User::factory()->create([
        'consent_accepted_at' => null,
        'role' => UserRole::EMPLOYEE_OFFICE,
    ]);

    $this->actingAs($user)
        ->get('/attendance/office')
        ->assertRedirect('/consent');

    $this->actingAs($user)
        ->post('/consent/decline')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    $this->actingAs($user)
        ->post('/consent/accept')
        ->assertRedirect('/attendance/office');

    expect($user->fresh()?->consent_accepted_at)->not->toBeNull();
});

test('role middleware strictly separates office, sales, and HR routes', function () {
    $office = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE]);
    $sales = User::factory()->create(['role' => UserRole::EMPLOYEE_SALES]);
    $hr = User::factory()->create(['role' => UserRole::HR_ADMIN]);

    $this->actingAs($office)->get('/attendance/office')->assertOk();
    $this->actingAs($office)->get('/attendance/sales')->assertForbidden();
    $this->actingAs($office)->get('/admin/dashboard')->assertForbidden();

    $this->actingAs($sales)->get('/attendance/sales')->assertOk();
    $this->actingAs($sales)->get('/attendance/office')->assertForbidden();
    $this->actingAs($sales)->get('/admin/dashboard')->assertForbidden();

    $this->actingAs($hr)->get('/admin/dashboard')->assertOk();
    $this->actingAs($hr)->get('/attendance/office')->assertOk();
    $this->actingAs($hr)->get('/attendance/sales')->assertForbidden();
});
