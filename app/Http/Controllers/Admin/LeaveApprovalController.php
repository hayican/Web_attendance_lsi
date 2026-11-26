<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ImageEvidenceService;
use App\Services\LeaveBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeaveApprovalController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly AuditLogger $audit,
        private readonly ImageEvidenceService $imageEvidence,
    ) {}

    public function index(): Response
    {
        $requests = LeaveRequest::query()
            ->with(['user:id,nik,name,role,office_location_id', 'user.officeLocation:id,name,timezone', 'days', 'reviewer:id,name'])
            ->whereIn('status', [LeaveStatus::PENDING, LeaveStatus::APPROVED, LeaveStatus::PARTIALLY_CANCELLED])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(function (LeaveRequest $item): array {
                $arr = $item->toArray();
                $arr['attachment_url'] = $item->attachment_path !== null
                    ? $this->imageEvidence->signedUrl($item->attachment_path)
                    : null;
                $arr['hr_conflict_warning'] = $item->user->role === UserRole::HR_ADMIN
                    && in_array($item->type, [LeaveType::CUTI, LeaveType::IZIN, LeaveType::DINAS_LUAR], true)
                    && $this->hasHrConflict($item);

                return $arr;
            });

        return Inertia::render('admin/Leaves', [
            'requests' => $requests,
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($leaveRequest->user_id === $actor->id) {
            abort(403, 'Dilarang menyetujui pengajuan milik sendiri.');
        }

        if ($leaveRequest->status !== LeaveStatus::PENDING) {
            throw ValidationException::withMessages([
                'leave' => ['Pengajuan ini sudah diproses.'],
            ]);
        }

        $overrideConfirmed = (bool) $request->boolean('override_monthly_limit');
        $exceedsLimit = $this->checkExceedsMonthlyLimit($leaveRequest);

        if ($exceedsLimit && ! $overrideConfirmed) {
            return response()->json([
                'requires_override_confirmation' => true,
                'message' => 'Cuti 2 hari/bulan sudah terpakai. Tetap setujui pengajuan ini?',
            ], 409);
        }

        DB::transaction(function () use ($leaveRequest, $actor, $exceedsLimit): void {
            $pendingDays = $leaveRequest->days()
                ->where('status', LeaveDayStatus::PENDING)
                ->get();

            if ($leaveRequest->type === LeaveType::CUTI) {
                $leaveUser = $leaveRequest->user;
                foreach ($pendingDays->groupBy(fn (LeaveRequestDay $d): int => (int) $d->leave_date->year) as $year => $days) {
                    $sum = (float) $days->sum(fn (LeaveRequestDay $d): float => (float) $d->day_value);
                    if ($sum > 0.0) {
                        $bal = $this->balanceService->ensureBalanceForYear($leaveUser, (int) $year);
                        $bal->update([
                            'pending_days' => max(0.0, (float) $bal->pending_days - $sum),
                            'used_days' => (float) $bal->used_days + $sum,
                        ]);
                    }
                }
            }

            $leaveRequest->days()
                ->where('status', LeaveDayStatus::PENDING)
                ->update(['status' => LeaveDayStatus::APPROVED]);

            $leaveRequest->update([
                'status' => LeaveStatus::APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now('UTC'),
            ]);

            if ($exceedsLimit) {
                $this->audit->log(
                    actorId: $actor->id,
                    entity: 'leave_requests',
                    recordId: $leaveRequest->id,
                    action: AuditAction::OVERRIDE_LIMIT,
                    oldValues: ['status' => LeaveStatus::PENDING->value],
                    newValues: ['status' => LeaveStatus::APPROVED->value],
                    reason: 'Override batas cuti 2 hari/bulan oleh HR',
                );
            }
        });

        return response()->json(['leave_request' => $leaveRequest->fresh('days')]);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($leaveRequest->user_id === $actor->id) {
            abort(403, 'Dilarang menolak pengajuan milik sendiri.');
        }

        if ($leaveRequest->status !== LeaveStatus::PENDING) {
            throw ValidationException::withMessages([
                'leave' => ['Pengajuan ini sudah diproses.'],
            ]);
        }

        $validated = $request->validate([
            'reject_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        DB::transaction(function () use ($leaveRequest, $actor, $validated): void {
            $pendingDays = $leaveRequest->days()
                ->where('status', LeaveDayStatus::PENDING)
                ->get();

            if ($leaveRequest->type === LeaveType::CUTI) {
                foreach ($pendingDays->groupBy(fn (LeaveRequestDay $d): int => (int) $d->leave_date->year) as $year => $days) {
                    $sum = (float) $days->sum(fn (LeaveRequestDay $d): float => (float) $d->day_value);
                    $bal = LeaveBalance::query()
                        ->where('user_id', $leaveRequest->user_id)
                        ->where('year', (int) $year)
                        ->first();
                    if ($bal !== null && $sum > 0.0) {
                        $bal->update(['pending_days' => max(0.0, (float) $bal->pending_days - $sum)]);
                    }
                }
            }

            $leaveRequest->days()
                ->where('status', LeaveDayStatus::PENDING)
                ->update(['status' => LeaveDayStatus::REJECTED]);

            $leaveRequest->update([
                'status' => LeaveStatus::REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now('UTC'),
                'reject_reason' => trim((string) $validated['reject_reason']),
            ]);

            if ($leaveRequest->type === LeaveType::DINAS_LUAR) {
                $reason = trim((string) $validated['reject_reason']);
                $reviewedAt = now('UTC');

                AttendanceLog::query()
                    ->where('leave_request_id', $leaveRequest->id)
                    ->get()
                    ->each(function (AttendanceLog $log) use ($actor, $reason, $reviewedAt): void {
                        $updates = [
                            'clock_in_status' => VerificationStatus::REJECTED,
                            'is_active_attempt' => null,
                            'clock_in_reviewed_by' => $actor->id,
                            'clock_in_reviewed_at' => $reviewedAt,
                            'clock_in_reject_reason' => $reason,
                        ];

                        if ($log->clock_out_status !== null || $log->clock_out_at !== null) {
                            $updates['clock_out_status'] = VerificationStatus::REJECTED;
                            $updates['clock_out_reviewed_by'] = $actor->id;
                            $updates['clock_out_reviewed_at'] = $reviewedAt;
                            $updates['clock_out_reject_reason'] = $reason;
                        }

                        $log->update($updates);
                    });
            }
        });

        return response()->json(['leave_request' => $leaveRequest->fresh('days')]);
    }

    public function partialCancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($leaveRequest->user_id === $actor->id) {
            abort(403, 'Dilarang membatalkan pengajuan milik sendiri.');
        }

        if (! in_array($leaveRequest->status, [LeaveStatus::APPROVED, LeaveStatus::PARTIALLY_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'leave' => ['Hanya pengajuan APPROVED/PARTIALLY_CANCELLED yang dapat dibatalkan HR.'],
            ]);
        }

        $validated = $request->validate([
            'day_ids' => ['required', 'array', 'min:1'],
            'day_ids.*' => ['integer'],
            'cancel_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $timezone = $leaveRequest->user->officeLocation->timezone ?? 'Asia/Jakarta';
        $todayLocal = CarbonImmutable::now('UTC')->setTimezone($timezone)->toDateString();

        /** @var list<int> $dayIds */
        $dayIds = array_map('intval', $validated['day_ids']);
        $cancelReason = trim((string) $validated['cancel_reason']);

        DB::transaction(function () use ($leaveRequest, $actor, $dayIds, $todayLocal, $cancelReason): void {
            $days = LeaveRequestDay::query()
                ->where('leave_request_id', $leaveRequest->id)
                ->whereIn('id', $dayIds)
                ->where('status', LeaveDayStatus::APPROVED)
                ->get();

            if ($days->isEmpty()) {
                throw ValidationException::withMessages([
                    'day_ids' => ['Tidak ada tanggal APPROVED yang valid untuk dibatalkan.'],
                ]);
            }

            $returnedTotal = 0.0;
            foreach ($days as $day) {
                if ($day->leave_date->toDateString() < $todayLocal) {
                    throw ValidationException::withMessages([
                        'day_ids' => ["Tanggal {$day->leave_date->toDateString()} sudah lewat dan terkunci untuk integritas rekap."],
                    ]);
                }

                $day->update([
                    'status' => LeaveDayStatus::CANCELLED,
                    'cancelled_by' => $actor->id,
                    'cancelled_at' => now('UTC'),
                    'cancel_reason' => $cancelReason,
                ]);

                $val = (float) $day->day_value;
                $returnedTotal += $val;

                if ($leaveRequest->type === LeaveType::CUTI && $val > 0.0) {
                    $bal = LeaveBalance::query()
                        ->where('user_id', $leaveRequest->user_id)
                        ->where('year', (int) $day->leave_date->year)
                        ->first();
                    if ($bal !== null) {
                        $bal->update(['used_days' => max(0.0, (float) $bal->used_days - $val)]);
                    }
                }
            }

            $remainingApproved = $leaveRequest->days()
                ->where('status', LeaveDayStatus::APPROVED)
                ->exists();

            $oldStatus = $leaveRequest->status->value;
            $newStatus = $remainingApproved ? LeaveStatus::PARTIALLY_CANCELLED : LeaveStatus::CANCELLED;

            $leaveRequest->update([
                'total_days' => max(0.0, (float) $leaveRequest->total_days - $returnedTotal),
                'status' => $newStatus,
            ]);

            $this->audit->log(
                actorId: $actor->id,
                entity: 'leave_requests',
                recordId: $leaveRequest->id,
                action: AuditAction::CANCEL_LEAVE,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus->value, 'cancelled_day_ids' => $dayIds],
                reason: $cancelReason,
            );
        });

        return response()->json(['leave_request' => $leaveRequest->fresh('days')]);
    }

    private function hasHrConflict(LeaveRequest $leaveRequest): bool
    {
        $activeHrCount = User::query()
            ->where('role', UserRole::HR_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $leaveRequest->user_id)
            ->count();

        $otherHrOnLeave = LeaveRequestDay::query()
            ->where('user_id', '!=', $leaveRequest->user_id)
            ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
            ->whereDate('leave_date', '>=', $leaveRequest->start_date->toDateString())
            ->whereDate('leave_date', '<=', $leaveRequest->end_date->toDateString())
            ->whereHas('user', fn ($query) => $query->where('role', UserRole::HR_ADMIN)->where('is_active', true))
            ->distinct('user_id')
            ->count('user_id');

        return ($activeHrCount - $otherHrOnLeave) < 2;
    }

    private function checkExceedsMonthlyLimit(LeaveRequest $leaveRequest): bool
    {
        if ($leaveRequest->type !== LeaveType::CUTI) {
            return false;
        }

        $months = $leaveRequest->days()
            ->where('day_value', '>', 0)
            ->get()
            ->map(fn (LeaveRequestDay $d): string => $d->leave_date->format('Y-m'))
            ->unique();

        foreach ($months as $month) {
            $monthStart = "{$month}-01";
            $monthEnd = CarbonImmutable::parse($monthStart)->endOfMonth()->toDateString();

            $totalInMonth = (float) LeaveRequestDay::query()
                ->where('user_id', $leaveRequest->user_id)
                ->whereDate('leave_date', '>=', $monthStart)
                ->whereDate('leave_date', '<=', $monthEnd)
                ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
                ->whereHas('leaveRequest', fn ($q) => $q->where('type', LeaveType::CUTI))
                ->sum('day_value');

            if ($totalInMonth > 2.0) {
                return true;
            }
        }

        return false;
    }
}
