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

// Garmin-Erholungsdaten. Zwei Laeufe, weil die Uhr ihre Nachtwerte erst zu
// Garmin Connect schickt, wenn der Nutzer morgens sein Handy benutzt — um
// 06:00 sind sie dort in der Regel noch nicht angekommen.
//   06:00 — voller Abgleich der letzten sieben Tage (schliesst Luecken)
//   09:00 — kurzer Nachschlag fuer die Nacht
// Wer seinen Check-in macht, loest zusaetzlich sofort einen Abruf aus
// (WellbeingController::refreshGarminIfStale).
Schedule::command('garmin:sync-health --days=7')->dailyAt('06:00');
Schedule::command('garmin:sync-health --days=2')->dailyAt('09:00');

// Jede Minute: laufende LiveTrack-Sitzungen abfragen. Der Command prueft
// selbst, ob ueberhaupt eine Sitzung im Zeitfenster liegt.
Schedule::command('livetrack:poll')->everyMinute()->withoutOverlapping();
