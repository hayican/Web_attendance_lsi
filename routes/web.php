<?php

use App\Enums\UserRole;
use App\Http\Controllers\Attendance\OfficeAttendanceController;
use App\Http\Controllers\Attendance\SalesVisitController;
use App\Http\Controllers\Auth\ConsentController;
use App\Http\Controllers\Security\EvidenceController;
use App\Http\Controllers\Security\TokenController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'active', 'password.changed'])->group(function () {
    Route::get('consent', [ConsentController::class, 'show'])->name('consent.show');
    Route::post('consent/accept', [ConsentController::class, 'accept'])->name('consent.accept');
    Route::post('consent/decline', [ConsentController::class, 'decline'])->name('consent.decline');
});

Route::middleware(['auth', 'verified', 'active', 'password.changed', 'consent'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        /** @var User $user */
        $user = $request->user();

        return redirect()->to(match ($user->role) {
            UserRole::HR_ADMIN => '/admin/dashboard',
            UserRole::EMPLOYEE_SALES => '/attendance/sales',
            default => '/attendance/office',
        });
    })->name('dashboard');

    Route::middleware('role:EMPLOYEE_OFFICE,HR_ADMIN')->group(function () {
        Route::get('attendance/office', [OfficeAttendanceController::class, 'index'])->name('attendance.office');
        Route::post('attendance/office/clock-in', [OfficeAttendanceController::class, 'clockIn'])->name('attendance.office.clock-in');
        Route::post('attendance/office/clock-out', [OfficeAttendanceController::class, 'clockOut'])->name('attendance.office.clock-out');
    });

    Route::middleware('role:EMPLOYEE_SALES')->group(function () {
        Route::get('attendance/sales', [SalesVisitController::class, 'index'])->name('attendance.sales');
        Route::get('attendance/sales/outlets', [SalesVisitController::class, 'searchOutlets'])->name('attendance.sales.outlets');
        Route::post('attendance/sales/noo', [SalesVisitController::class, 'storeNoo'])->name('attendance.sales.noo');
        Route::post('attendance/sales/visit-in', [SalesVisitController::class, 'visitIn'])->name('attendance.sales.visit-in');
        Route::post('attendance/sales/{visit}/cancel', [SalesVisitController::class, 'cancelVisitIn'])->name('attendance.sales.cancel');
        Route::post('attendance/sales/{visit}/visit-out', [SalesVisitController::class, 'visitOut'])->name('attendance.sales.visit-out');
    });

    Route::middleware('role:HR_ADMIN')->group(function () {
        Route::inertia('admin/dashboard', 'Dashboard')->name('admin.dashboard');
    });

    Route::post('api/tokens/gps', [TokenController::class, 'issueGps'])->name('tokens.gps');
    Route::post('api/tokens/camera', [TokenController::class, 'issueCamera'])->name('tokens.camera');
});

Route::middleware(['auth', 'active', 'signed'])
    ->get('evidence/file', [EvidenceController::class, 'show'])
    ->name('evidence.show');

require __DIR__.'/settings.php';
