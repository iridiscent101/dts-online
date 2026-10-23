<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:prune-read')
    ->dailyAt('02:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onOneServer();

// Sign-in history is kept for three months.
Schedule::command('accounts:prune-activity')
    ->dailyAt('02:15')
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('system:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('system:notify-health')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->environments(['production']);

Schedule::command('system:backup')
    ->dailyAt((string) config('backup.schedule'))
    ->timezone('Asia/Manila')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->environments(['production']);
