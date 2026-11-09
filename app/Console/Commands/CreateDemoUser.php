<?php

namespace App\Console\Commands;

use App\Models\Coach;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Run\SessionStructure;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ein Demo-Konto für die App-Prüfung bei Apple (seit 09.11.2026). Der Prüfer
 * meldet sich mit Passwort an — mit Apple oder per Link landete er auf der
 * Warteliste. Das Konto ist freigeschaltet, hat ein Rennen und zwei Wochen
 * Plan aus dem Workout-Katalog. Kein Modellaufruf, keine Uhr, keine echten
 * Daten.
 *
 *   php artisan demo:create                      # demo@zone3.run, Passwort wird ausgegeben
 *   php artisan demo:create pruefung@zone3.run --password=…
 *   php artisan demo:create --reset              # Passwort neu, Plan neu ab heute
 */
class CreateDemoUser extends Command
{
    protected $signature = 'demo:create {email=demo@zone3.run} {--password= : Sonst wird eins erzeugt} {--reset : Bestehendes Demo-Konto neu aufsetzen}';

    protected $description = 'Demo-Konto mit Beispielplan für die App-Prüfung anlegen';

    /** Eine Woche Plan: Wochentag (1 = Mo) → Typ und Dauer. */
    private const WEEK = [
        1 => null,
        2 => ['easy_run', 45, 'Locker laufen'],
        3 => ['tempo_run', 55, 'Schwelle'],
        4 => ['easy_run', 40, 'Locker laufen'],
        5 => null,
        6 => ['easy_run', 35, 'Locker mit Steigerungen'],
        7 => ['long_run', 85, 'Langer Lauf'],
    ];

    public function handle(SessionStructure $structure): int
    {
        $email    = Str::lower($this->argument('email'));
        $password = $this->option('password') ?: Str::password(16, symbols: false);
        $existing = User::where('email', $email)->first();

        if ($existing && ! $this->option('reset')) {
            $this->error("{$email} gibt es schon. Mit --reset wird es neu aufgesetzt.");

            return self::FAILURE;
        }
        if ($existing && $existing->is_admin) {
            $this->error("{$email} ist ein Admin-Konto und wird nicht angefasst.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $password, $existing, $structure) {
            $user = $existing ?? new User(['email' => $email]);
            $user->fill([
                'name'                    => 'Demo Läufer',
                'password'                => $password,
                'onboarding_completed_at' => now(),
                'waitlisted_at'           => null,
                'coach_id'                => Coach::query()->value('id'),
            ]);
            $user->email_verified_at = now();
            $user->save();

            RunnerProfile::updateOrCreate(['user_id' => $user->id], [
                'threshold_speed'     => 4.75, // 4:45/km
                'max_heart_rate'      => 188,
                'threshold_heart_rate'=> 170,
                'has_completed_setup' => true,
                'weekly_availability' => collect([1 => 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                    ->mapWithKeys(fn ($day, $n) => [$day => ['available' => self::WEEK[$n] !== null, 'duration_min' => self::WEEK[$n][1] ?? 0]])
                    ->all(),
            ]);

            // Neu aufsetzen: alter Plan und altes Rennen weg.
            TrainingSession::where('user_id', $user->id)->delete();
            TrainingPlan::where('user_id', $user->id)->delete();
            Event::where('user_id', $user->id)->delete();

            $today = CarbonImmutable::today();
            $event = Event::create([
                'user_id'             => $user->id,
                'name'                => 'Demo-Halbmarathon',
                'event_date'          => $today->addWeeks(20)->next(CarbonImmutable::SUNDAY)->toDateString(),
                'race_distance'       => 'half_marathon',
                'priority'            => 'A',
                'target_time_hours'   => 1,
                'target_time_minutes' => 45,
            ]);
            $plan = TrainingPlan::create([
                'user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true,
                'name' => 'Plan: Demo-Halbmarathon', 'sessions' => '[]',
            ]);

            for ($d = $today; $d->lt($today->addDays(14)); $d = $d->addDay()) {
                $slot    = self::WEEK[$d->dayOfWeekIso];
                $session = new TrainingSession([
                    'user_id'          => $user->id,
                    'training_plan_id' => $plan->id,
                    'event_id'         => $event->id,
                    'planned_date'     => $d->toDateString(),
                    'type'             => $slot[0] ?? 'rest',
                    'title'            => $slot[2] ?? 'Ruhetag',
                    'description'      => $slot ? 'Beispiel-Einheit im Demo-Konto.' : 'Erholung gehört zum Training.',
                    'duration_min'     => $slot[1] ?? 0,
                    'intensity'        => ($slot[0] ?? null) === 'tempo_run' ? 'high' : 'low',
                    'status'           => 'planned',
                ]);
                $session->setRelation('user', $user);
                $structure->apply($session);
                $session->save();
            }
        });

        $this->info("Demo-Konto bereit: {$email}");
        $this->line("Passwort: {$password}");
        $this->line('Anmelden über „Schon ein Konto mit Passwort?“ auf /login.');

        return self::SUCCESS;
    }
}
