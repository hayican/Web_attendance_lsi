<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\SalesVisit;
use App\Models\User;
use App\Services\ImageEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function __construct(private readonly ImageEvidenceService $imageEvidence) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('officeLocation');
        $tz = $user->officeLocation->timezone;

        $month = (string) $request->query('month', CarbonImmutable::now($tz)->format('Y-m'));
        $start = CarbonImmutable::parse("{$month}-01", $tz)->startOfMonth()->toDateString();
        $end = CarbonImmutable::parse("{$month}-01", $tz)->endOfMonth()->toDateString();

        if ($user->role === UserRole::EMPLOYEE_SALES) {
            $items = SalesVisit::query()
                ->with(['outletLocation:id,name,timezone', 'activityCategory:id,name'])
                ->where('user_id', $user->id)
                ->whereBetween('visit_date', [$start, $end])
                ->orderByDesc('visit_date')
                ->orderByDesc('visit_in_at')
                ->get()
                ->map(fn (SalesVisit $v): array => [
                    'id' => $v->id,
                    'date' => $v->visit_date->toDateString(),
                    'outlet_name' => $v->outletLocation->name,
                    'visit_in_at' => $v->visit_in_at?->setTimezone($v->outletLocation->timezone)->format('H:i'),
                    'visit_out_at' => $v->visit_out_at?->setTimezone($v->outletLocation->timezone)->format('H:i'),
                    'duration_minutes' => $v->duration_minutes,
                    'category' => $v->activityCategory?->name,
                    'visit_notes' => $v->visit_notes,
                    'visit_status' => $v->visit_status->value,
                    'verification_status' => $v->verification_status->value,
                    'anomaly_flags' => $v->anomaly_flags ?? [],
                    'reject_reason' => $v->reject_reason,
                    'photo_url' => $v->visit_in_photo_url !== null ? $this->imageEvidence->signedUrl($v->visit_in_photo_url) : null,
                    'photo_expired_notice' => $v->visit_in_photo_url === null ? 'Berkas melewati retensi 12 bulan' : null,
                ]);

            return Inertia::render('attendance/History', [
                'mode' => 'sales',
                'month' => $month,
                'items' => $items,
            ]);
        }

        $items = AttendanceLog::query()
            ->with(['location:id,name,timezone', 'shift:id,code'])
            ->where('user_id', $user->id)
            ->whereBetween('work_date', [$start, $end])
            ->orderByDesc('work_date')
            ->orderByDesc('attempt_no')
            ->get()
            ->map(fn (AttendanceLog $log): array => [
                'id' => $log->id,
                'date' => $log->work_date->toDateString(),
                'attempt_no' => $log->attempt_no,
                'shift' => $log->shift !== null ? $log->shift->code : '-',
                'clock_in_at' => $log->clock_in_at?->setTimezone($log->location->timezone)->format('H:i'),
                'clock_out_at' => $log->clock_out_at?->setTimezone($log->location->timezone)->format('H:i'),
                'clock_in_status' => $log->clock_in_status->value,
                'clock_out_status' => $log->clock_out_status?->value,
                'clock_in_flags' => $log->clock_in_anomaly_flags ?? [],
                'clock_out_flags' => $log->clock_out_anomaly_flags ?? [],
                'clock_in_reject_reason' => $log->clock_in_reject_reason,
                'clock_out_reject_reason' => $log->clock_out_reject_reason,
                'clock_in_photo_url' => $log->clock_in_photo_url !== null ? $this->imageEvidence->signedUrl($log->clock_in_photo_url) : null,
                'clock_out_photo_url' => $log->clock_out_photo_url !== null ? $this->imageEvidence->signedUrl($log->clock_out_photo_url) : null,
            ]);

        return Inertia::render('attendance/History', [
            'mode' => 'office',
            'month' => $month,
            'items' => $items,
        ]);
    }
}
