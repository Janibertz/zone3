<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Das Konto für die Browser-Tests (seit 15.11.2026, tests/e2e).
 *
 * Bewusst getrennt vom Demo-Konto der App-Prüfung (`demo:create`, Jan): eigene
 * Adresse, eigener Name, und nur außerhalb der Produktion. Plan und Rennen
 * baut derselbe Weg wie beim Demo-Konto (Katalog, kein Modellaufruf); dazu
 * kommen ein paar erfundene Läufe und eine Coach-Nachricht, damit Aktivitäten,
 * Aktivitätsseite und Chat nicht leer sind.
 *
 *   php artisan testdev:create                   # testdev@zone3.test / testdev-passwort
 */
class CreateTestDevUser extends Command
{
    public const EMAIL    = 'testdev@zone3.test';
    public const PASSWORD = 'testdev-passwort';

    protected $signature = 'testdev:create {--force : Auch in der Produktion}';

    protected $description = 'Testkonto „Testdev“ für die Browser-Tests anlegen (nicht in der Produktion)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Das Testkonto gehört nicht in die Produktion.');

            return self::FAILURE;
        }

        $exists = User::where('email', self::EMAIL)->exists();
        $code   = Artisan::call('demo:create', [
            'email'      => self::EMAIL,
            '--password' => self::PASSWORD,
            '--reset'    => $exists,
        ]);
        if ($code !== self::SUCCESS) {
            $this->error(trim(Artisan::output()));

            return self::FAILURE;
        }

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $user->forceFill([
            'name'                => 'Testdev Läufer',
            'ai_coach_consent_at' => now(),
        ])->save();

        Event::where('user_id', $user->id)->update(['name' => 'Testdev-Halbmarathon']);

        Activity::where('user_id', $user->id)->delete();
        CoachMessage::where('user_id', $user->id)->delete();

        // Drei Läufe in den letzten Tagen. Quelle „Datei“: so kommt keine
        // Strava- oder Garmin-Logik ins Spiel.
        $runs = [
            [1, 'Lockerer Lauf', 8200, 2820, 142],
            [3, 'Schwelle', 10400, 3180, 161],
            [6, 'Langer Lauf', 16100, 5890, 146],
        ];
        foreach ($runs as [$daysAgo, $name, $meters, $seconds, $hr]) {
            $start = CarbonImmutable::today()->subDays($daysAgo)->setTime(7, 30);
            Activity::create([
                'user_id'           => $user->id,
                'source'            => Activity::SOURCE_FILE,
                'file_start'        => $start->getTimestamp(),
                'name'              => $name,
                'type'              => 'Run',
                'distance'          => $meters,
                'moving_time'       => $seconds,
                'elapsed_time'      => $seconds + 40,
                'average_speed'     => round($meters / $seconds, 3),
                'average_heartrate' => $hr,
                'start_date'        => $start,
            ]);
        }

        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'assistant',
            'content' => 'Willkommen im Testkonto. Diese Nachricht hat kein Modell geschrieben.',
        ]);

        $this->info('Testkonto bereit: '.self::EMAIL);

        return self::SUCCESS;
    }
}
