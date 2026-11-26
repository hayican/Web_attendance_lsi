<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\AttendanceSource;
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
        $user->loadMissing('officeLocation');
        $timezone = $user->officeLocation->timezone;
        $nowUtc = CarbonImmutable::now('UTC');
        $nowLocal = $nowUtc->setTimezone($timezone)->startOfDay();
        $todayDate = $nowLocal->toDateString();

        $range = $request->query('range') === '30d' ? '30d' : 'month';
        $month = (string) $request->query('month', $nowLocal->format('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $month = $nowLocal->format('Y-m');
        }

        if ($range === '30d') {
            $startDate = $nowLocal->subDays(29)->toDateString();
            $endDate = $todayDate;
            $month = $nowLocal->format('Y-m');
        } else {
            $monthStart = CarbonImmutable::parse("{$month}-01", $timezone)->startOfDay();
            $startDate = $monthStart->toDateString();
            $endDate = $monthStart->endOfMonth()->toDateString();
        }

        if ($user->role === UserRole::EMPLOYEE_SALES) {
            $items = SalesVisit::query()
                ->with(['outletLocation:id,name,code,address,timezone', 'activityCategory:id,name'])
                ->where('user_id', $user->id)
                ->whereDate('visit_date', '>=', $startDate)
                ->whereDate('visit_date', '<=', $endDate)
                ->orderByDesc('visit_date')
                ->orderByDesc('visit_in_at')
                ->get()
                ->map(fn (SalesVisit $visit): array => [
                    'id' => $visit->id,
                    'date' => $visit->visit_date->toDateString(),
                    'outlet_name' => $visit->outletLocation->name,
                    'outlet_code' => $visit->outletLocation->code,
                    'outlet_address' => $visit->outletLocation->address,
                    'visit_in_at' => $visit->visit_in_at?->setTimezone($visit->outletLocation->timezone)->format('H:i'),
                    'visit_out_at' => $visit->visit_out_at?->setTimezone($visit->outletLocation->timezone)->format('H:i'),
                    'duration_minutes' => $visit->duration_minutes,
                    'category' => $visit->activityCategory?->name,
                    'visit_notes' => $visit->visit_notes,
                    'visit_in_notes' => $visit->visit_in_notes,
                    'visit_status' => $visit->visit_status->value,
                    'verification_status' => $visit->verification_status->value,
                    'anomaly_flags' => $visit->anomaly_flags ?? [],
                    'reject_reason' => $visit->reject_reason,
                    'source' => $visit->source->value,
                    'photo_url' => $visit->visit_in_photo_url !== null
                        ? $this->imageEvidence->signedUrl($visit->visit_in_photo_url)
                        : null,
                    'photo_expired_notice' => $visit->visit_in_photo_url === null
                        ? ($visit->source === AttendanceSource::MANUAL_HR
                            ? 'Tanpa foto (input manual HR)'
                            : 'Berkas melewati retensi 12 bulan')
                        : null,
                ]);

            return Inertia::render('sales/Riwayat', [
                'mode' => 'sales',
                'range' => $range,
                'month' => $month,
                'todayDate' => $todayDate,
                'items' => $items,
            ]);
        }

        $items = AttendanceLog::query()
            ->with(['location:id,name,timezone', 'shift:id,code'])
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->orderByDesc('work_date')
            ->orderByDesc('attempt_no')
            ->get()
            ->map(fn (AttendanceLog $log): array => [
                'id' => $log->id,
                'date' => $log->work_date->toDateString(),
                'attempt_no' => $log->attempt_no,
                'shift' => $log->shift !== null ? $log->shift->code : '-',
                'source' => $log->source->value,
                'clock_in_at' => $log->clock_in_at?->setTimezone($log->location->timezone)->format('H:i'),
                'clock_out_at' => $log->clock_out_at?->setTimezone($log->location->timezone)->format('H:i'),
                'clock_in_status' => $log->clock_in_status->value,
                'clock_out_status' => $log->clock_out_status?->value,
                'clock_in_flags' => $log->clock_in_anomaly_flags ?? [],
                'clock_out_flags' => $log->clock_out_anomaly_flags ?? [],
                'clock_in_reject_reason' => $log->clock_in_reject_reason,
                'clock_out_reject_reason' => $log->clock_out_reject_reason,
                'late_minutes' => $log->late_minutes,
                'early_leave_minutes' => $log->early_leave_minutes,
                'clock_in_notes' => $log->clock_in_notes,
                'clock_out_notes' => $log->clock_out_notes,
                'clock_in_photo_url' => $log->clock_in_photo_url !== null
                    ? $this->imageEvidence->signedUrl($log->clock_in_photo_url)
                    : null,
                'clock_out_photo_url' => $log->clock_out_photo_url !== null
                    ? $this->imageEvidence->signedUrl($log->clock_out_photo_url)
                    : null,
                'clock_in_photo_notice' => $log->clock_in_photo_url === null
                    ? ($log->source === AttendanceSource::MANUAL_HR
                        ? 'Tanpa foto (input manual HR)'
                        : 'Berkas melewati retensi 12 bulan')
                    : null,
                'clock_out_photo_notice' => $log->clock_out_at !== null && $log->clock_out_photo_url === null
                    ? ($log->source === AttendanceSource::MANUAL_HR
                        ? 'Tanpa foto (input manual HR)'
                        : 'Berkas melewati retensi 12 bulan')
                    : null,
            ]);

        $component = $user->role === UserRole::HR_ADMIN
            ? 'admin/RiwayatSaya'
            : 'office/Riwayat';

        return Inertia::render($component, [
            'mode' => 'office',
            'range' => $range,
            'month' => $month,
            'todayDate' => $todayDate,
            'items' => $items,
        ]);
    }
}
