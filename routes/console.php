<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check every minute: send wellbeing reminder to eligible users
Schedule::command('push:wellbeing-reminders')->everyMinute();

// Every Monday at 07:00: generate weekly AI review for all athletes
Schedule::command('ai:weekly-review')->weeklyOn(1, '07:00');

// Every day at 05:00: detect plan gaps and queue regeneration for athletes with upcoming races
Schedule::command('plan:auto-update')->dailyAt('05:00');

// 1st of each month at 09:00: send the previous month's running review (push + email)
Schedule::command('review:monthly')->monthlyOn(1, '09:00');
