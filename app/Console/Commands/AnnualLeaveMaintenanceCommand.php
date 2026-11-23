<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeaveBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AnnualLeaveMaintenanceCommand extends Command
{
    protected $signature = 'leave:annual-maintenance';

    protected $description = 'Initialize/reset annual leave balances and process 1-year anniversary prorations (idempotent)';

    public function handle(LeaveBalanceService $balanceService): int
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $year = $today->year;

        $users = User::query()->where('is_active', true)->get();
        $processed = 0;

        foreach ($users as $user) {
            $balanceService->ensureBalanceForYear($user, $year, $today);
            $processed++;
        }

        $this->info("Processed annual leave balances for {$processed} active users ({$year}).");

        return self::SUCCESS;
    }
}
