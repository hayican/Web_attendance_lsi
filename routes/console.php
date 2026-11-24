<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:close-missed')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('attendance:generate-schedules')->monthlyOn(1, '00:10')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('leave:annual-maintenance')->dailyAt('00:05')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('attendance:prune-retention')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping();
