<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\SalesVisit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOrphanFilesCommand extends Command
{
    protected $signature = 'evidence:prune-orphans {--dry-run : List orphans without deleting them}';

    protected $description = 'Delete evidence files on private storage that no database row references any more (Sec 7A)';

    public function handle(): int
    {
        $disk = Storage::disk('local');

        $referenced = [];
        foreach (AttendanceLog::query()->get(['clock_in_photo_url', 'clock_out_photo_url']) as $log) {
            foreach ([$log->clock_in_photo_url, $log->clock_out_photo_url] as $path) {
                if ($path !== null) {
                    $referenced[$path] = true;
                }
            }
        }
        foreach (SalesVisit::query()->pluck('visit_in_photo_url') as $path) {
            if ($path !== null) {
                $referenced[$path] = true;
            }
        }
        foreach (LeaveRequest::query()->pluck('attachment_path') as $path) {
            if ($path !== null) {
                $referenced[$path] = true;
            }
        }
        foreach (User::query()->pluck('reference_photo_url') as $path) {
            if ($path !== null) {
                $referenced[$path] = true;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now('UTC')->subHour()->getTimestamp();
        $orphans = 0;

        foreach ($disk->allFiles('evidence') as $path) {
            if (isset($referenced[$path])) {
                continue;
            }

            // Skip fresh uploads: the row is written after the file, so a same-minute sweep could race.
            if ($disk->lastModified($path) > $cutoff) {
                continue;
            }

            $orphans++;

            if (! $dryRun) {
                $disk->delete($path);
            }
        }

        $this->info(($dryRun ? 'Would delete ' : 'Deleted ')."{$orphans} orphan evidence file(s).");

        return self::SUCCESS;
    }
}
