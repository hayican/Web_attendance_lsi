<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ShiftScheduleGeneratorService;
use Illuminate\Console\Command;

class GenerateShiftSchedulesCommand extends Command
{
    protected $signature = 'attendance:generate-schedules';

    protected $description = 'Generate 90-day user_shift_schedules from default_shift_pattern_id (preserves MANUAL_OVERRIDE)';

    public function handle(ShiftScheduleGeneratorService $generator): int
    {
        $users = User::query()
            ->with('officeLocation')
            ->where('is_active', true)
            ->whereNotNull('default_shift_pattern_id')
            ->get();

        $total = 0;
        foreach ($users as $user) {
            $total += $generator->generateForUser($user);
        }

        $this->info("Generated/updated {$total} schedule rows.");

        return self::SUCCESS;
    }
}
