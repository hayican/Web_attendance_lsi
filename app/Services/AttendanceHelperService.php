<?php

namespace App\Services;

use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\LeaveBalance;
use App\Models\LeaveRequestDay;
use Illuminate\Http\Request;

class AttendanceHelperService
{
    public function resolveDeviceId(Request $request): string
    {
        $attr = $request->attributes->get(EnsureDeviceCookie::COOKIE_NAME);
        if (is_string($attr) && $attr !== '') {
            return $attr;
        }

        $cookie = $request->cookie(EnsureDeviceCookie::COOKIE_NAME);

        return is_string($cookie) ? $cookie : '';
    }

    public function cancelPendingLeaveDayOnPresence(int $userId, string $localDate): void
    {
        $pendingDays = LeaveRequestDay::query()
            ->with('leaveRequest')
            ->where('user_id', $userId)
            ->whereDate('leave_date', $localDate)
            ->where('status', LeaveDayStatus::PENDING)
            ->whereHas('leaveRequest', function ($query): void {
                $query->where('type', '!=', LeaveType::DINAS_LUAR);
            })
            ->get();

        foreach ($pendingDays as $day) {
            $day->update([
                'status' => LeaveDayStatus::CANCELLED,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
                'cancel_reason' => 'Batal otomatis karena karyawan hadir',
            ]);

            $request = $day->leaveRequest;
            if ($request === null) {
                continue;
            }

            $dayValue = (float) $day->day_value;
            if ($dayValue > 0.0) {
                $newTotal = max(0.0, (float) $request->total_days - $dayValue);
                $request->update(['total_days' => $newTotal]);

                if ($request->type === LeaveType::CUTI) {
                    $year = (int) $day->leave_date->year;
                    $balance = LeaveBalance::query()
                        ->where('user_id', $userId)
                        ->where('year', $year)
                        ->first();

                    if ($balance !== null) {
                        $balance->update([
                            'pending_days' => max(0.0, (float) $balance->pending_days - $dayValue),
                        ]);
                    }
                }
            }

            $hasRemaining = $request->days()
                ->whereIn('status', [LeaveDayStatus::PENDING, LeaveDayStatus::APPROVED])
                ->exists();

            if (! $hasRemaining) {
                $request->update(['status' => LeaveStatus::CANCELLED]);
            }
        }
    }
}
