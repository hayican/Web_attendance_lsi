<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users are redirected from dashboard based on role', function () {
    $officeUser = User::factory()->create(['role' => UserRole::EMPLOYEE_OFFICE]);

    $this->actingAs($officeUser)
        ->get(route('dashboard'))
        ->assertRedirect('/attendance/office');
});
