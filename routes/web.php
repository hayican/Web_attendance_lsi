<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\HrDashboardController;
use App\Http\Controllers\Admin\LeaveApprovalController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Attendance\HistoryController;
use App\Http\Controllers\Attendance\OfficeAttendanceController;
use App\Http\Controllers\Attendance\SalesVisitController;
use App\Http\Controllers\Auth\ConsentController;
use App\Http\Controllers\Leave\LeaveController;
use App\Http\Controllers\Security\EvidenceController;
use App\Http\Controllers\Security\TokenController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'active', 'password.changed'])->group(function () {
    Route::get('consent', [ConsentController::class, 'show'])->name('consent.show');
    Route::post('consent/accept', [ConsentController::class, 'accept'])->name('consent.accept');
    Route::post('consent/decline', [ConsentController::class, 'decline'])->name('consent.decline');
});

Route::middleware(['auth', 'verified', 'active', 'password.changed', 'consent'])->group(function () {
    Route::post('notifications/read-all', function (Request $request) {
        $request->user()?->unreadNotifications()->update(['read_at' => now('UTC')]);

        return back();
    })->name('notifications.read-all');

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

    Route::get('attendance/history', [HistoryController::class, 'index'])->name('attendance.history');

    Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancelOwn'])->name('leave.cancel');

    Route::middleware('role:HR_ADMIN')->prefix('admin')->name('admin.')->group(function () {
        Route::get('dashboard', [HrDashboardController::class, 'index'])->name('dashboard');
        Route::get('absen-saya', [OfficeAttendanceController::class, 'adminIndex'])->name('absen-saya');
        Route::get('pengajuan-saya', [LeaveController::class, 'adminIndex'])->name('pengajuan-saya');
        Route::post('attendance/{attendanceLog}/review', [HrDashboardController::class, 'reviewAttendance'])->name('attendance.review');
        Route::post('attendance/{attendanceLog}/correct', [HrDashboardController::class, 'correctAttendanceStatus'])->name('attendance.correct');
        Route::post('visits/{salesVisit}/review', [HrDashboardController::class, 'reviewVisit'])->name('visits.review');
        Route::post('visits/{salesVisit}/correct', [HrDashboardController::class, 'correctVisitStatus'])->name('visits.correct');
        Route::post('reviews/bulk-approve', [HrDashboardController::class, 'bulkApprove'])->name('reviews.bulk-approve');
        Route::post('attendance/manual', [HrDashboardController::class, 'storeManualAttendance'])->name('attendance.manual');

        Route::get('exports/talenta-office', [ExportController::class, 'talentaOffice'])->name('exports.talenta-office');
        Route::get('exports/sales-visits', [ExportController::class, 'salesVisits'])->name('exports.sales-visits');
        Route::get('exports/audit', [ExportController::class, 'auditInternal'])->name('exports.audit');

        Route::get('leaves', [LeaveApprovalController::class, 'index'])->name('leaves.index');
        Route::post('leaves/{leaveRequest}/approve', [LeaveApprovalController::class, 'approve'])->name('leaves.approve');
        Route::post('leaves/{leaveRequest}/reject', [LeaveApprovalController::class, 'reject'])->name('leaves.reject');
        Route::post('leaves/{leaveRequest}/partial-cancel', [LeaveApprovalController::class, 'partialCancel'])->name('leaves.partial-cancel');

        Route::get('master-data', [MasterDataController::class, 'index'])->name('master-data');
        Route::post('users', [MasterDataController::class, 'storeUser'])->name('users.store');
        Route::patch('users/{user}', [MasterDataController::class, 'updateUser'])->name('users.update');
        Route::post('users/{user}/reset-password', [MasterDataController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/unlock', [MasterDataController::class, 'unlockUser'])->name('users.unlock');
        Route::post('users/{user}/leave-balance', [MasterDataController::class, 'adjustLeaveBalance'])->name('users.leave-balance');
        Route::post('users/leave-balances/import', [MasterDataController::class, 'importLeaveBalancesCsv'])->name('users.leave-balances.import');
        Route::post('users/{user}/schedule-override', [MasterDataController::class, 'overrideSchedule'])->name('users.schedule-override');
        Route::post('users/{user}/schedule-override/cancel', [MasterDataController::class, 'cancelScheduleOverride'])->name('users.schedule-override.cancel');

        Route::post('holidays', [MasterDataController::class, 'storeHoliday'])->name('holidays.store');
        Route::patch('holidays/{holiday}', [MasterDataController::class, 'updateHoliday'])->name('holidays.update');
        Route::delete('holidays/{holiday}', [MasterDataController::class, 'destroyHoliday'])->name('holidays.destroy');

        Route::post('locations', [MasterDataController::class, 'storeLocation'])->name('locations.store');
        Route::patch('locations/{location}', [MasterDataController::class, 'updateLocation'])->name('locations.update');
        Route::post('locations/outlets/import', [MasterDataController::class, 'importOutletsCsv'])->name('locations.outlets.import');
        Route::post('locations/{location}/verify-noo', [MasterDataController::class, 'verifyNooOutlet'])->name('locations.verify-noo');
        Route::post('locations/{location}/deactivate', [MasterDataController::class, 'deactivateLocation'])->name('locations.deactivate');

        Route::post('shifts', [MasterDataController::class, 'storeShift'])->name('shifts.store');
        Route::patch('shifts/{shift}', [MasterDataController::class, 'updateShift'])->name('shifts.update');
        Route::post('shifts/{shift}/deactivate', [MasterDataController::class, 'deactivateShift'])->name('shifts.deactivate');

        Route::post('shift-patterns', [MasterDataController::class, 'storeShiftPattern'])->name('shift-patterns.store');
        Route::patch('shift-patterns/{shiftPattern}', [MasterDataController::class, 'updateShiftPattern'])->name('shift-patterns.update');
        Route::post('shift-patterns/{shiftPattern}/deactivate', [MasterDataController::class, 'deactivateShiftPattern'])->name('shift-patterns.deactivate');

        Route::post('activity-categories', [MasterDataController::class, 'storeActivityCategory'])->name('activity-categories.store');
        Route::patch('activity-categories/{activityCategory}', [MasterDataController::class, 'updateActivityCategory'])->name('activity-categories.update');
        Route::post('activity-categories/{activityCategory}/deactivate', [MasterDataController::class, 'deactivateActivityCategory'])->name('activity-categories.deactivate');

        Route::get('talenta-mapping', [MasterDataController::class, 'talentaMappingIndex'])->name('talenta-mapping.index');
        Route::patch('talenta-mapping/{talentaMapping}', [MasterDataController::class, 'updateTalentaMapping'])->name('talenta-mapping.update');
    });

    Route::get('admin/audit-logs', [MasterDataController::class, 'auditLogsIndex'])->name('admin.audit-logs.index');

    Route::post('api/tokens/gps', [TokenController::class, 'issueGps'])->name('tokens.gps');
    Route::post('api/tokens/camera', [TokenController::class, 'issueCamera'])->name('tokens.camera');
});

Route::middleware(['auth', 'active', 'signed'])
    ->get('evidence/file', [EvidenceController::class, 'show'])
    ->name('evidence.show');

require __DIR__.'/settings.php';
