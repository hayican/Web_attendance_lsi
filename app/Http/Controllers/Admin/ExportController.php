<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\LeaveDayStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\SalesVisit;
use App\Models\TalentaMapping;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CsvSanitizer;
use App\Services\ScheduleResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CsvSanitizer $sanitizer,
        private readonly ScheduleResolverService $scheduleResolver,
    ) {}

    public function talentaOffice(Request $request): StreamedResponse|JsonResponse
    {
        [$start, $end, $actor] = $this->validateExportRange($request);

        if (! $request->boolean('confirm_pending')) {
            $pendingCount = LeaveRequest::query()
                ->where('status', LeaveStatus::PENDING)
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->count();

            if ($pendingCount > 0) {
                return response()->json([
                    'requires_pending_confirmation' => true,
                    'pending_count' => $pendingCount,
                    'message' => "Masih ada {$pendingCount} pengajuan Cuti/Izin/Sakit berstatus PENDING di periode ini. Hari tersebut akan diekspor sebagai Alpa / Belum Absen. Tetap export?",
                ], 409);
            }
        }

        $mappings = TalentaMapping::query()->pluck('mapping_value', 'mapping_key');
        $presentCode = (string) ($mappings['attendance_present'] ?? 'H');
        $absentCode = (string) ($mappings['attendance_absent'] ?? 'A');

        $officeUsers = User::query()
            ->with(['officeLocation'])
            ->where('is_active', true)
            ->whereIn('role', [UserRole::EMPLOYEE_OFFICE, UserRole::HR_ADMIN])
            ->orderBy('nik')
            ->get();

        $headers = [
            'Employee ID*', 'Full Name', 'Date*', 'Shift', 'Schedule In', 'Schedule Out',
            'Attendance Code', 'Check In', 'Check Out', 'Overtime Check In', 'Overtime Check Out',
            'Overtime Before', 'Overtime After', 'Overtime Break Before', 'Overtime Break After',
            'Time Off Code', 'Time Off Halfday', 'Time Off Schedule In', 'Time Off Schedule Out',
            'Branch', 'Organization', 'Job Position', 'Job Level', 'Employment Status',
            'Join Date', 'Lock Status',
        ];

        $rows = [];
        foreach ($officeUsers as $user) {
            $tz = $user->officeLocation->timezone;
            $todayLocal = CarbonImmutable::now('UTC')->setTimezone($tz)->toDateString();
            $cursor = $start;

            while ($cursor->lessThanOrEqualTo($end)) {
                $dateStr = $cursor->toDateString();
                $schedule = $this->scheduleResolver->resolveOrCreateSchedule($user, $dateStr);
                $shift = $schedule?->shift;
                $isHoliday = $this->scheduleResolver->isHoliday($dateStr, $user->office_location_id);
                $isDayOff = $isHoliday || ($schedule !== null && $schedule->is_day_off) || $shift === null;

                $approvedLeave = LeaveRequestDay::query()
                    ->with('leaveRequest:id,type')
                    ->where('user_id', $user->id)
                    ->whereDate('leave_date', $dateStr)
                    ->where('status', LeaveDayStatus::APPROVED)
                    ->first();

                $log = AttendanceLog::query()
                    ->with('shift:id,code,start_time,end_time')
                    ->where('user_id', $user->id)
                    ->whereDate('work_date', $dateStr)
                    ->where('is_active_attempt', 1)
                    ->first()
                    ?? AttendanceLog::query()
                        ->with('shift:id,code,start_time,end_time')
                        ->where('user_id', $user->id)
                        ->whereDate('work_date', $dateStr)
                        ->latest('attempt_no')
                        ->first();

                $logShift = $log?->shift;
                $shiftCode = $isDayOff ? 'dayoff' : ($logShift !== null ? $logShift->code : $shift->code);
                $schedIn = $isDayOff ? '00:00' : substr($logShift !== null ? $logShift->start_time : $shift->start_time, 0, 5);
                $schedOut = $isDayOff ? '00:00' : substr($logShift !== null ? $logShift->end_time : $shift->end_time, 0, 5);

                $attCode = '';
                $checkIn = '';
                $checkOut = '';
                $timeOffCode = '';

                if ($approvedLeave !== null && $approvedLeave->leaveRequest !== null && $approvedLeave->leaveRequest->type !== LeaveType::DINAS_LUAR) {
                    $timeOffCode = match ($approvedLeave->leaveRequest->type) {
                        LeaveType::CUTI => (string) ($mappings['timeoff_annual'] ?? 'CT'),
                        LeaveType::IZIN => (string) ($mappings['timeoff_izin'] ?? 'IZ'),
                        LeaveType::SAKIT => (string) ($mappings['timeoff_sakit'] ?? 'SK'),
                    };
                } elseif ($log !== null && $log->clock_in_status !== VerificationStatus::REJECTED) {
                    $attCode = $presentCode;
                    $checkIn = $log->clock_in_at?->setTimezone($tz)->format('H:i') ?? '';
                    $checkOut = $log->clock_out_status === VerificationStatus::REJECTED
                        ? ''
                        : ($log->clock_out_at?->setTimezone($tz)->format('H:i') ?? '');
                } elseif (! $isDayOff && $dateStr < $todayLocal) {
                    $attCode = $absentCode;
                }

                $rows[] = [
                    $user->nik, $user->name, $dateStr, $shiftCode, $schedIn, $schedOut,
                    $attCode, $checkIn, $checkOut, '', '',
                    '', '', '', '',
                    $timeOffCode, '', '', '',
                    $user->officeLocation->name, $user->organization ?? '', $user->job_position ?? '', $user->job_level ?? '', $user->employment_status ?? '',
                    $user->join_date->toDateString(), '0',
                ];

                $cursor = $cursor->addDay();
            }
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'attendance_logs',
            recordId: 0,
            action: AuditAction::EXPORT_ATTENDANCE,
            newValues: ['file' => 'talenta_office', 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
        );

        return $this->streamCsv('talenta-office-'.$start->toDateString().'.csv', $headers, $rows);
    }

    public function salesVisits(Request $request): StreamedResponse
    {
        [$start, $end, $actor] = $this->validateExportRange($request);

        $headers = [
            'Date', 'Check Time', 'Type', 'Employee ID', 'Full Name', 'Job Position',
            'Face recognition', 'Liveness validation', 'Shift Name', 'Shift Code', 'Shift Label',
            'Location Setting Name', 'Location GPS Name', 'Location Address', 'Location Coordinate',
            'Description', 'Mobile Flag', 'Status',
        ];

        $visits = SalesVisit::query()
            ->with(['user:id,nik,name,job_position', 'outletLocation'])
            ->whereBetween('visit_date', [$start->toDateString(), $end->toDateString()])
            ->where('visit_status', '!=', VisitStatus::CANCELLED)
            ->orderBy('visit_date')
            ->orderBy('visit_in_at')
            ->get();

        $rows = [];
        foreach ($visits as $visit) {
            $tz = $visit->outletLocation->timezone;
            $coord = ($visit->visit_in_lat !== null && $visit->visit_in_lng !== null)
                ? "{$visit->visit_in_lat},{$visit->visit_in_lng}"
                : '';
            $flag = $visit->source->value === 'MANUAL_HR' ? 'Manual HR' : 'Mobile App';
            $statusLabel = match ($visit->verification_status) {
                VerificationStatus::AUTO_APPROVED, VerificationStatus::APPROVED => 'Approved',
                VerificationStatus::PENDING_REVIEW => 'Pending',
                VerificationStatus::REJECTED => 'Rejected',
            };

            if ($visit->visit_in_at !== null) {
                $localIn = $visit->visit_in_at->setTimezone($tz);
                $rows[] = [
                    $localIn->format('Y-m-d'), $localIn->format('H:i:s'), 'Clock In',
                    $visit->user->nik, $visit->user->name, $visit->user->job_position ?? '',
                    '', '', 'MKT', 'MKT', 'MKT',
                    $visit->outletLocation->name, $visit->outletLocation->name, $visit->outletLocation->address,
                    $coord, $visit->outletLocation->name, $flag, $statusLabel,
                ];
            }

            if ($visit->visit_out_at !== null) {
                $localOut = $visit->visit_out_at->setTimezone($tz);
                $outCoord = ($visit->visit_out_lat !== null && $visit->visit_out_lng !== null)
                    ? "{$visit->visit_out_lat},{$visit->visit_out_lng}"
                    : '';
                $rows[] = [
                    $localOut->format('Y-m-d'), $localOut->format('H:i:s'), 'Clock Out',
                    $visit->user->nik, $visit->user->name, $visit->user->job_position ?? '',
                    '', '', 'MKT', 'MKT', 'MKT',
                    $visit->outletLocation->name, $visit->outletLocation->name, $visit->outletLocation->address,
                    $outCoord, trim($visit->outletLocation->name.' - '.($visit->visit_notes ?? '')), $flag, $statusLabel,
                ];
            }
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'sales_visits',
            recordId: 0,
            action: AuditAction::EXPORT_ATTENDANCE,
            newValues: ['file' => 'sales_visits', 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
        );

        return $this->streamCsv('sales-visits-'.$start->toDateString().'.csv', $headers, $rows);
    }

    public function auditInternal(Request $request): StreamedResponse
    {
        [$start, $end, $actor] = $this->validateExportRange($request);

        $headers = [
            'Tanggal', 'NIK', 'Nama', 'Role', 'Cabang', 'Sumber Data',
            'Jam Masuk/Visit In', 'Jam Pulang/Visit Out', 'Status Verifikasi',
            'Flag Anomali', 'Alasan/Catatan', 'Alasan Reject',
        ];

        $rows = [];
        $logs = AttendanceLog::query()
            ->with(['user.officeLocation'])
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('work_date')
            ->get();

        foreach ($logs as $log) {
            $tz = $log->user->officeLocation->timezone;
            $flags = array_values(array_unique([...($log->clock_in_anomaly_flags ?? []), ...($log->clock_out_anomaly_flags ?? [])]));
            $rows[] = [
                $log->work_date->toDateString(),
                $log->user->nik,
                $log->user->name,
                $log->user->role->value,
                $log->user->officeLocation->name,
                $log->source->value,
                $log->clock_in_at?->setTimezone($tz)->format('H:i:s') ?? '-',
                $log->clock_out_at?->setTimezone($tz)->format('H:i:s') ?? '-',
                $log->clock_in_status->value,
                implode('|', $flags),
                trim(($log->clock_in_notes ?? '').' | '.($log->clock_out_notes ?? '')),
                trim(($log->clock_in_reject_reason ?? '').' | '.($log->clock_out_reject_reason ?? '')),
            ];
        }

        $visits = SalesVisit::query()
            ->with(['user.officeLocation', 'outletLocation'])
            ->whereBetween('visit_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('visit_date')
            ->get();

        foreach ($visits as $visit) {
            $tz = $visit->outletLocation->timezone;
            $rows[] = [
                $visit->visit_date->toDateString(),
                $visit->user->nik,
                $visit->user->name,
                $visit->user->role->value,
                $visit->user->officeLocation->name,
                $visit->source->value,
                $visit->visit_in_at?->setTimezone($tz)->format('H:i:s') ?? '-',
                $visit->visit_out_at?->setTimezone($tz)->format('H:i:s') ?? '-',
                $visit->visit_status->value.' / '.$visit->verification_status->value,
                implode('|', $visit->anomaly_flags ?? []),
                $visit->cancel_reason ?? $visit->visit_in_notes ?? $visit->visit_notes ?? '',
                $visit->reject_reason ?? '',
            ];
        }

        $this->audit->log(
            actorId: $actor->id,
            entity: 'audit_logs',
            recordId: 0,
            action: AuditAction::EXPORT_ATTENDANCE,
            newValues: ['file' => 'audit_internal', 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
        );

        return $this->streamCsv('audit-internal-'.$start->toDateString().'.csv', $headers, $rows);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: User}
     */
    private function validateExportRange(Request $request): array
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $start = CarbonImmutable::parse((string) $validated['start_date'])->startOfDay();
        $end = CarbonImmutable::parse((string) $validated['end_date'])->startOfDay();

        if ($start->diffInDays($end) > 93) {
            throw ValidationException::withMessages([
                'end_date' => ['Rentang ekspor maksimal 93 hari (3 bulan) per unduhan.'],
            ]);
        }

        return [$start, $end, $actor];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $cell): string => $this->sanitizer->sanitizeCell($cell), $row));
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
