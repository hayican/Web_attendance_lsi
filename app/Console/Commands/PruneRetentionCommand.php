<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\SalesVisit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneRetentionCommand extends Command
{
    protected $signature = 'attendance:prune-retention';

    protected $description = 'Purge photos, SKD attachments, raw GPS coordinates, and raw IPs older than retention months (FR-6)';

    public function handle(): int
    {
        /** @var int|numeric-string $configured */
        $configured = config('attendance.retention_months', 12);
        $months = max(1, (int) $configured);
        $cutoff = now('UTC')->subMonths($months);
        $disk = Storage::disk('local');

        $logs = AttendanceLog::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($q): void {
                $q->whereNotNull('clock_in_photo_url')
                    ->orWhereNotNull('clock_out_photo_url')
                    ->orWhereNotNull('clock_in_lat')
                    ->orWhereNotNull('clock_in_ip');
            })
            ->get();

        foreach ($logs as $log) {
            if ($log->clock_in_photo_url !== null) {
                $disk->delete($log->clock_in_photo_url);
            }
            if ($log->clock_out_photo_url !== null) {
                $disk->delete($log->clock_out_photo_url);
            }
            $log->update([
                'clock_in_photo_url' => null,
                'clock_in_lat' => null,
                'clock_in_lng' => null,
                'clock_in_ip' => null,
                'clock_out_photo_url' => null,
                'clock_out_lat' => null,
                'clock_out_lng' => null,
                'clock_out_ip' => null,
            ]);
        }

        $visits = SalesVisit::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($q): void {
                $q->whereNotNull('visit_in_photo_url')
                    ->orWhereNotNull('visit_in_lat')
                    ->orWhereNotNull('visit_in_ip')
                    ->orWhereNotNull('visit_out_lat')
                    ->orWhereNotNull('visit_out_ip');
            })
            ->get();

        foreach ($visits as $visit) {
            if ($visit->visit_in_photo_url !== null) {
                $disk->delete($visit->visit_in_photo_url);
            }
            $visit->update([
                'visit_in_photo_url' => null,
                'visit_in_lat' => null,
                'visit_in_lng' => null,
                'visit_in_ip' => null,
                'visit_out_lat' => null,
                'visit_out_lng' => null,
                'visit_out_ip' => null,
            ]);
        }

        $leaves = LeaveRequest::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('attachment_path')
            ->get();

        foreach ($leaves as $leave) {
            if ($leave->attachment_path !== null) {
                $disk->delete($leave->attachment_path);
            }
            $leave->update(['attachment_path' => null]);
        }

        $inactiveUsers = User::query()
            ->where('is_active', false)
            ->whereNotNull('deactivated_at')
            ->where('deactivated_at', '<', $cutoff)
            ->whereNotNull('reference_photo_url')
            ->get();

        foreach ($inactiveUsers as $user) {
            $path = $user->reference_photo_url;
            if ($path === null) {
                continue;
            }

            $deleted = $disk->delete($path);
            if ($deleted || ! $disk->exists($path)) {
                $user->update(['reference_photo_url' => null]);
            } else {
                $this->warn("Gagal menghapus foto referensi user #{$user->id}: {$path}");
            }
        }

        $this->info('Retention cleanup completed.');

        return self::SUCCESS;
    }
}
