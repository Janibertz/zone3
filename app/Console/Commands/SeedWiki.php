<?php

namespace App\Console\Commands;

use App\Models\WikiPage;
use Illuminate\Console\Command;

class SeedWiki extends Command
{
    protected $signature   = 'wiki:seed {--force : Overwrite existing pages}';
    protected $description = 'Seed the admin wiki with initial project documentation';

    public function handle(): void
    {
        $pages = $this->pages();
        $count = 0;

        foreach ($pages as $data) {
            $exists = WikiPage::where('slug', $data['slug'])->exists();

            if ($exists && ! $this->option('force')) {
                $this->line("  Skipping '{$data['title']}' (already exists)");
                continue;
            }

            WikiPage::updateOrCreate(['slug' => $data['slug']], $data);
            $this->line("  " . ($exists ? 'Updated' : 'Created') . " '{$data['title']}'");
            $count++;
        }

        $this->info("Wiki seeded: {$count} page(s) written.");
    }

    private function pages(): array
    {
        return [

            // ─────────────────────────────────────────
            //  ARCHITEKTUR
            // ─────────────────────────────────────────

            [
                'slug'       => 'architecture-overview',
                'category'   => 'architecture',
                'title'      => 'Architektur-Übersicht',
                'sort_order' => 1,
                'content'    => <<<'MD'
# Zone3 — Architektur-Übersicht

Zone3 ist eine KI-gestützte Lauf-Trainingsplattform. Die Anwendung besteht aus einem Laravel-Monolith und einem Python-Microservice.

## Tech Stack

| Schicht | Technologie |
|---------|-------------|
| Backend | Laravel 13 (PHP 8.3) |
| Frontend | Vue 3 + Inertia.js |
| CSS | Tailwind CSS v4 |
| Datenbank | MySQL / MariaDB |
| KI | OpenAI API (GPT-4o) |
| Auth | Strava OAuth + Garmin Connect |
| Deployment | Coolify (self-hosted) |
| CI/CD | GitHub → Coolify Webhook |

## Komponenten

### Laravel-Monolith (Hauptanwendung)
- Verwaltet alle Nutzer, Aktivitäten, Trainingspläne, Events und KI-Empfehlungen
- Strava-Integration via OAuth und Webhook
- Garmin-Integration via Python-Service
- Admin-Bereich unter `/admin`

### Python FIT-Service (Microservice)
- FastAPI-Service auf Port 8001 (`FIT_SERVICE_URL`)
- Garmin FIT-Datei-Generierung
- Garmin Connect API-Integration (garth Library)

## Datenbankstruktur (wichtigste Tabellen)

- `users` — Nutzer mit Garmin-Session (verschlüsselt)
- `runner_profiles` — Athletenprofil, Pace-Zonen, gecachte KI-Empfehlungen
- `events` — Wettkampf-Events mit Priorität (A/B/C)
- `training_plans` — KI-generierte Trainingspläne pro Event
- `training_sessions` — Einzelne Trainingseinheiten (geplant/abgeschlossen/übersprungen)
- `activities` — Importierte Strava-Aktivitäten
- `wellbeing_entries` — Tägliche Wellbeing-Daten
- `coaches` — Coach-Persönlichkeiten für KI-Prompts
- `ai_logs` — Logging aller OpenAI-API-Aufrufe
- `wiki_pages` / `wiki_changelogs` — Dieses Wiki
MD,
            ],

            [
                'slug'       => 'architecture-deployment',
                'category'   => 'architecture',
                'title'      => 'Deployment & Server',
                'sort_order' => 2,
                'content'    => <<<'MD'
# Deployment & Server

## Deployment-Workflow

Zone3 hat kein lokales Dev-Environment. Alle Änderungen werden direkt deployed:

1. **Code-Änderung** → Commit + Push zu GitHub (`main` Branch)
2. **GitHub** löst automatisch Coolify-Webhook aus
3. **Coolify** baut Docker-Image neu und deployt
4. **startup.sh** läuft beim Container-Start

## startup.sh — Startup-Sequenz

```bash
set -e  # Fehler = Container stoppt sofort
php artisan migrate --force
php artisan db:seed --class=CoachSeeder --force
php artisan wiki:seed --force  # Wiki-Dokumentation aktualisieren
php artisan storage:link --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Queue Worker (Restart-Loop im Hintergrund)
# Scheduler (jede Minute im Hintergrund)
exec php artisan serve --host=0.0.0.0 --port=8000
```

**Wichtig:** `set -e` bedeutet jeder Fehler verhindert den Server-Start.

## Umgebungsvariablen

| Variable | Zweck |
|----------|-------|
| `APP_KEY` | Laravel Encryption (auch für garmin_session) |
| `OPENAI_API_KEY` | GPT-4o Zugriff |
| `OPENAI_MODEL` | Modell (Standard: gpt-4o) |
| `STRAVA_CLIENT_ID` / `STRAVA_CLIENT_SECRET` | Strava OAuth |
| `FIT_SERVICE_URL` | Python Microservice URL |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | Web Push Notifications |
| `GITHUB_WEBHOOK_SECRET` | GitHub Webhook Signatur-Verifikation |

## Queue & Scheduler

- **Queue Driver:** `database` — Jobs werden in `jobs`-Tabelle gespeichert
- **Scheduler täglich 05:00:** `plan:auto-update`
- **Scheduler Mo 07:00:** `ai:weekly-review`
- **Scheduler täglich:** `push:wellbeing-reminders` (zur konfigurierten Uhrzeit)
MD,
            ],

            [
                'slug'       => 'architecture-models',
                'category'   => 'architecture',
                'title'      => 'Datenmodelle (Models)',
                'sort_order' => 3,
                'content'    => <<<'MD'
# Datenmodelle — Übersicht

Zone3 hat 16 Eloquent-Models.

## User
Fillable: `name`, `email`, `password`, `strava_id`, `garmin_email`, `garmin_session` (encrypted)

Beziehungen: `runnerProfile`, `events`, `activities`, `trainingPlans`, `wellbeingEntries`, `coach`, `goals`, `notifications`

## RunnerProfile
Fillable: `user_id`, `weekly_km_goal`, `threshold_pace`, `coach_id`, `pace_zone_1`–`5`, `today_recommendation`, `recommendation_date`, `daily_message`, `daily_message_date`

Zweck: Athletendaten + gecachte KI-Empfehlungen (Cache wird beim Plan-Update geleert)

## Event
Fillable: `user_id`, `name`, `date`, `distance_km`, `priority` (A/B/C), `location`

Beziehungen: `user`, `trainingPlan`

## TrainingPlan
Fillable: `user_id`, `event_id`, `needs_plan_update`, `created_at`

Beziehungen: `user`, `event`, `sessions`

## TrainingSession
Fillable: `training_plan_id`, `user_id`, `date`, `type`, `status` (planned/completed/skipped), `title`, `description`, `distance_km`, `duration_min`, `steps` (JSON), `notes`

Session-Typen: `rest`, `easy_run`, `tempo_run`, `interval`, `long_run`, `race_prep`

## Activity
Fillable: `user_id`, `strava_id`, `name`, `type`, `distance`, `moving_time`, `start_date`, `average_speed`, `max_speed`, `suffer_score`

Strava-importierte Lauf-Aktivitäten

## WellbeingEntry
Fillable: `user_id`, `date`, `energy`, `sleep_quality`, `muscle_soreness`, `stress`, `is_sick`, `is_injured`, `notes`

Skala 1-10 für alle Metriken außer Boolean-Flags

## Coach
Fillable: `name`, `personality_prompt`, `description`, `avatar_url`

Vordefinierte Coach-Persönlichkeiten; wird über CoachSeeder befüllt

## AiLog
Fillable: `user_id`, `call_type`, `prompt`, `response`, `tokens_used`, `duration_ms`, `error`

Logging aller OpenAI-Calls

## StravaAccount
Fillable: `user_id`, `strava_id`, `access_token`, `refresh_token`, `expires_at`

## Goal
Fillable: `user_id`, `title`, `target_value`, `current_value`, `unit`, `deadline`, `completed_at`

## WikiPage
Fillable: `slug`, `category` (architecture/features/api/decisions), `title`, `content`, `sort_order`, `updated_by`

`getRouteKeyName()` → `slug` (für URL-Routing)

## WikiChangelog
Casts: `commits` (array), `files_changed` (array), `pushed_at` (datetime)

Befüllt automatisch durch GitHub-Webhook
MD,
            ],

            [
                'slug'       => 'architecture-routes',
                'category'   => 'architecture',
                'title'      => 'Routen-Übersicht',
                'sort_order' => 4,
                'content'    => <<<'MD'
# Routen-Übersicht

## Öffentliche Routen

| Methode | Pfad | Controller |
|---------|------|------------|
| GET | `/` | WelcomeController |
| GET | `/login` | Auth |
| POST | `/strava/authorize` | StravaController |
| GET/POST | `/strava/webhook` | StravaController (Webhook) |
| POST | `/webhook/github` | WebhookController |

## Auth-geschützte Routen (Middleware: `auth`)

### Dashboard
| GET | `/dashboard` | DashboardController |

### Events
| GET | `/events` | EventController@index |
| POST | `/events` | EventController@store |
| GET | `/events/{id}` | EventController@show |
| PUT | `/events/{id}` | EventController@update |
| DELETE | `/events/{id}` | EventController@destroy |

### Trainingspläne
| POST | `/events/{id}/plan/generate` | TrainingPlanController@generate |
| GET | `/events/{id}/plan` | TrainingPlanController@show |

### Training Sessions
| PUT | `/sessions/{id}/complete` | TrainingSessionController@complete |
| PUT | `/sessions/{id}/skip` | TrainingSessionController@skip |
| POST | `/sessions/{id}/send-to-garmin` | TrainingSessionController@sendToGarmin |

### Aktivitäten
| GET | `/activities` | ActivityController@index |
| GET | `/activities/{id}` | ActivityController@show |

### Profil
| GET | `/profile` | ProfileController@show |
| PUT | `/profile` | ProfileController@update |
| POST | `/profile/garmin-login` | ProfileController@garminLogin |
| DELETE | `/profile/garmin-disconnect` | ProfileController@garminDisconnect |

### Wellbeing
| GET | `/wellbeing` | WellbeingController@index |
| POST | `/wellbeing` | WellbeingController@store |

### Statistics
| GET | `/statistics` | StatisticsController@index |

### Goals
| GET | `/goals` | GoalController@index |
| POST | `/goals` | GoalController@store |
| PUT | `/goals/{id}` | GoalController@update |
| DELETE | `/goals/{id}` | GoalController@destroy |

### KI / Coach
| GET | `/ai/recommendation` | AIController@recommendation |
| GET | `/ai/daily-message` | AIController@dailyMessage |
| POST | `/coach-chat` | CoachChatController@message |

## Admin-Routen (Middleware: `auth`, `admin`)

| Methode | Pfad | Zweck |
|---------|------|-------|
| GET | `/admin` | Admin Dashboard |
| GET | `/admin/users` | User-Verwaltung |
| GET | `/admin/coaches` | Coach-Verwaltung |
| GET | `/admin/wiki` | Wiki Index |
| GET | `/admin/wiki/changelog` | Wiki Changelog |
| POST | `/admin/wiki` | Neue Wiki-Seite |
| GET | `/admin/wiki/{slug}` | Wiki-Seite anzeigen |
| PUT | `/admin/wiki/{slug}` | Wiki-Seite bearbeiten |
| DELETE | `/admin/wiki/{slug}` | Wiki-Seite löschen |
MD,
            ],

            // ─────────────────────────────────────────
            //  FEATURES
            // ─────────────────────────────────────────

            [
                'slug'       => 'feature-ai-training',
                'category'   => 'features',
                'title'      => 'KI-Trainingsplan',
                'sort_order' => 1,
                'content'    => <<<'MD'
# KI-Trainingsplan

Das Herzstück von Zone3 — der Coach generiert automatisch personalisierte Trainingspläne für Events.

## Wie es funktioniert

1. Nutzer legt ein Event an (z.B. 10K-Rennen am 31.05.)
2. Klick auf "Plan erstellen" → `POST /events/{id}/plan/generate`
3. `TrainingPlanController::generate()` ruft `OpenAIService::generateEventTrainingPlan()` auf
4. KI bekommt: Athletenprofil, letzte Aktivitäten, Wellbeing, Verfügbarkeit, TSB-Wert
5. KI generiert JSON-Array mit **einem Eintrag pro Tag** bis zum Renntag
6. Sessions werden in `training_sessions` gespeichert

## Auto-Update (seit Mai 2026)

- **Täglich 05:00** läuft `plan:auto-update` — erkennt Lücken und regeneriert automatisch
- **Session überspringen/abschließen** triggert sofortige Neuplanung (`userTriggered=true`)
- **Nach Strava-Sync** wird Plan bei Bedarf neu berechnet
- Nach jeder Neuberechnung wird `today_recommendation` und `daily_message` Cache geleert

## Tapering-Logik

| Zeitraum | Strategie |
|----------|-----------|
| >30 Tage | Normaler Aufbau (Volumen + Tempo) |
| 10-30 Tage | Tapering einleiten (Volumen reduzieren) |
| <10 Tage | Starkes Tapering — nur leichte Läufe und explizite Ruhetage |
| Renntag | `type="race_prep"` |

## Session-Typen

| Typ | Bedeutung |
|-----|-----------|
| `rest` | Ruhetag (explizit geplant) |
| `easy_run` | Lockerer Lauf (Zone 2) |
| `tempo_run` | Tempolauf (Schwelle) |
| `interval` | Intervalltraining |
| `long_run` | Langer Lauf (Wochenende) |
| `race_prep` | Renntag-Vorbereitung / Rennen |

## Plan Coverage Fix (Mai 2026)

Ursprünglich war der Plan auf 10 Tage begrenzt. Jetzt: `min(21, $daysUntilRace + 1)` Tage, ein Eintrag pro Tag, Ruhetage explizit als `type='rest'`.
MD,
            ],

            [
                'slug'       => 'feature-garmin',
                'category'   => 'features',
                'title'      => 'Garmin Connect Integration',
                'sort_order' => 2,
                'content'    => <<<'MD'
# Garmin Connect Integration

Zone3 kann Trainingseinheiten direkt in Garmin Connect hochladen.

## Authentifizierung

Garmin verwendet kein OAuth — nur Email/Passwort mit `garth` Python-Library.

**Token-Speicherung (seit Mai 2026):**
- Login einmalig mit Email/Passwort
- `garth.dumps()` serialisiert Session-Tokens
- Tokens werden AES-verschlüsselt in `users.garmin_session` gespeichert (Laravel `encrypted` Cast)
- Nächste Verbindung: Token via `garth.loads()` wiederherstellen — kein erneuter Login

## Workout-Struktur (Garmin API)

Garmin Connect erwartet dieses JSON-Format:

```json
{
  "workoutName": "Tempolauf",
  "sportType": { "sportTypeId": 1 },
  "workoutSegments": [{
    "workoutSteps": [
      { "type": "ExecutableStepDTO", "stepOrder": 1, ... },
      { "type": "RepeatGroupDTO", "numberOfIterations": 5,
        "workoutSteps": [ ... ] }
    ]
  }]
}
```

`RepeatGroupDTO` gruppiert Intervall-Wiederholungen.

## FIT-Service Endpunkte

| Endpunkt | Funktion |
|----------|----------|
| `POST /garmin-login` | Authentifizierung, gibt garth-Session zurück |
| `POST /send-to-garmin` | Workout hochladen + Kalender-Eintrag |
| `POST /generate-fit` | Binäre .fit-Datei generieren |
| `GET /health` | Health-Check |

## Bekannte Limitierungen

- MFA (Zwei-Faktor) wird nicht unterstützt → Fehler `mfa_required`
- Garmin API ist nicht öffentlich dokumentiert — inoffizielle Endpunkte
- Bei abgelaufenem Token: `session_expired` → Nutzer muss sich neu einloggen
MD,
            ],

            [
                'slug'       => 'feature-strava',
                'category'   => 'features',
                'title'      => 'Strava Integration',
                'sort_order' => 3,
                'content'    => <<<'MD'
# Strava Integration

Strava ist die primäre Aktivitäts-Datenquelle für Zone3.

## OAuth-Flow

1. Nutzer klickt "Mit Strava verbinden"
2. Redirect zu Strava OAuth (`/strava/authorize`)
3. Strava redirectet zurück mit Code
4. Token wird in `strava_accounts` gespeichert
5. Aktivitäten werden importiert → `activities` Tabelle

## Webhook (Echtzeit-Updates)

Strava sendet Push-Events bei neuen Aktivitäten:
- `POST /strava/webhook` empfängt Event (CSRF ausgenommen)
- `GET /strava/webhook` — Verifikations-Challenge von Strava
- Neue Aktivität wird importiert
- Plan wird auf `needs_plan_update = true` gesetzt
- `RegeneratePlanJob` wird mit 5 Minuten Delay dispatcht (Batching mehrerer Aktivitäten)

## Aktivitäts-Matching

Nach Plan-Regenerierung werden abgeschlossene Strava-Runs automatisch mit geplanten Sessions verknüpft:
- Gleicher Tag → Session als `completed` markieren
- Distanz + Dauer aus Strava übernehmen
- Ruhetag mit Lauf → Rest-Session löschen, neue `easy_run` Session erstellen

## Schwellenpace-Berechnung

`CalculateThresholdPaceJob` berechnet automatisch die Laktatschwelle aus den letzten Aktivitäten und speichert sie im `runner_profiles`.
MD,
            ],

            [
                'slug'       => 'feature-wellbeing',
                'category'   => 'features',
                'title'      => 'Wellbeing & Coach-Dashboard',
                'sort_order' => 4,
                'content'    => <<<'MD'
# Wellbeing & Coach-Dashboard

## Tägliches Wellbeing

Nutzer tracken täglich:
- **Energie** (1-10)
- **Schlafqualität** (1-10)
- **Muskelkater** (1-10)
- **Stress** (1-10)
- **Krank** / **Verletzt** (Boolean)

Wellbeing-Daten beeinflussen KI-Empfehlungen: schlechte Werte → leichteres Training.

## Dashboard Coach-Spruch

KI-Motivation die täglich generiert wird:
- Gecacht in `runner_profiles.daily_message` (mit `daily_message_date`)
- Wird geleert bei Plan-Neuberechnung → frischer Spruch beim nächsten Load
- Berücksichtigt: Wellbeing, nächstes Event, letzte Aktivitäten, TSB-Wert

## Trainingsbelastung (CTL/ATL/TSB)

| Metrik | Bedeutung | Berechnung |
|--------|-----------|------------|
| CTL (Fitness) | 42-Tage EMA | Langzeit-Trainingsbelastung |
| ATL (Ermüdung) | 7-Tage EMA | Kurzzeit-Trainingsbelastung |
| TSB (Form) | CTL − ATL | Positiv = frisch, Negativ = müde |

## AdjustPlanForWellbeingJob

Bei sehr schlechten Wellbeing-Werten (Krank/Verletzt oder Score < Schwellenwert):
- Aktiver Plan wird auf `needs_plan_update=true` gesetzt
- `RegeneratePlanJob` wird dispatcht
- KI bekommt Wellbeing-Kontext → leichtere/angepasste Sessions

## Push-Benachrichtigungen

- Web Push via VAPID-Schlüssel
- Wellbeing-Reminder täglich zur konfigurierten Zeit
- Plan-Update-Benachrichtigungen nach Neuberechnung
MD,
            ],

            [
                'slug'       => 'feature-admin',
                'category'   => 'features',
                'title'      => 'Admin-Bereich',
                'sort_order' => 5,
                'content'    => <<<'MD'
# Admin-Bereich

Erreichbar unter `/admin` — nur für Nutzer mit `is_admin = true`.

## Dashboard (`/admin`)

- Nutzerstatistiken (Gesamt, aktiv, mit Plan)
- Aktivitätsgraph der letzten 30 Tage
- Letzte AI-Logs

## User-Verwaltung (`/admin/users`)

- Alle Nutzer auflisten mit Profil-Status
- Nutzer-Details anzeigen
- Admin-Status setzen/entfernen
- Nutzer-Impersonation für Support

## Coach-Verwaltung (`/admin/coaches`)

- Alle Coaches anzeigen
- Neuen Coach erstellen mit Name, Beschreibung, Persönlichkeits-Prompt
- Avatar-Upload
- Coach-Zuweisung zu Nutzern

## Wiki (`/admin/wiki`)

- Dokumentation in 4 Kategorien: Architektur, Features, API, Entscheidungen
- Inline Markdown-Editor mit Live-Vorschau
- Neue Seiten erstellen, bearbeiten, löschen

## Changelog (`/admin/wiki/changelog`)

- Automatisch nach jedem GitHub-Push befüllt
- KI-Zusammenfassung der Änderungen
- Commit-Details mit geänderten Dateien
- Timeline-Ansicht

## AI-Logs (`/admin/ai-logs`)

- Alle OpenAI-Calls mit Prompt, Response, Token-Verbrauch, Dauer
- Filter nach Call-Typ und Nutzer
MD,
            ],

            [
                'slug'       => 'feature-onboarding',
                'category'   => 'features',
                'title'      => 'Onboarding & Profil',
                'sort_order' => 6,
                'content'    => <<<'MD'
# Onboarding & Profil

## Onboarding-Flow

Neue Nutzer durchlaufen einen Onboarding-Wizard nach der Strava-Anmeldung:

1. **Schritt 1:** Persönliche Daten (Name, Geburtsdatum, Geschlecht, Gewicht)
2. **Schritt 2:** Lauf-Level (Anfänger / Fortgeschrittener / Experte)
3. **Schritt 3:** Wochenziel (km/Woche, verfügbare Tage)
4. **Schritt 4:** Coach auswählen
5. **Schritt 5:** Erstes Event anlegen (optional)

Nach dem Onboarding: `runner_profile` wird erstellt, Pace-Zonen werden berechnet.

## Profil (`/profile`)

- Persönliche Daten bearbeiten
- Coach wechseln
- Wochenziele anpassen
- Garmin Connect verbinden/trennen
- Strava-Status anzeigen
- Push-Benachrichtigungen konfigurieren

## Pace-Zonen

5 Pace-Zonen basierend auf der Schwellenpace:
- **Zone 1** (Regeneration): >1.45× Schwelle
- **Zone 2** (Aerob): 1.20–1.45× Schwelle
- **Zone 3** (Tempo): 1.05–1.20× Schwelle
- **Zone 4** (Schwelle): 0.95–1.05× Schwelle
- **Zone 5** (VO2max): <0.95× Schwelle

Werden automatisch aktualisiert von `CalculateThresholdPaceJob`.
MD,
            ],

            // ─────────────────────────────────────────
            //  API
            // ─────────────────────────────────────────

            [
                'slug'       => 'api-openai',
                'category'   => 'api',
                'title'      => 'OpenAI Service',
                'sort_order' => 1,
                'content'    => <<<'MD'
# OpenAI Service (`app/Services/OpenAIService.php`)

Zentrale Klasse für alle KI-Aufrufe. Alle Calls werden in `ai_logs` protokolliert.

## Methoden

| Methode | Zweck | Call-Typ |
|---------|-------|----------|
| `generateRecommendation()` | Tages-Trainingsempfehlung | `recommendation` |
| `generateEventTrainingPlan()` | Kompletter Plan bis Renntag | `event_plan` |
| `generateSessionSteps()` | Schritt-für-Schritt-Workout | `session_steps` |
| `generateWeeklyReview()` | Wochenrückblick | `weekly_review` |
| `generateDailyMessage()` | Coach-Spruch Dashboard | `daily_message` |
| `generatePrMessage()` | PR-Glückwunsch | `pr_message` |
| `generateChangelogSummary()` | Wiki Changelog-Zusammenfassung | `changelog_summary` |
| `calculatePaceZones()` | Pace-Zonen berechnen | `pace_zones` |

## Konfiguration

```php
// config/services.php
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
    'model'   => env('OPENAI_MODEL', 'gpt-4o'),
]
```

## Coach-Persönlichkeit

Jeder Nutzer kann einem Coach zugeordnet werden. Der `personality_prompt` wird als System-Prompt vorangestellt.

## Fehlerbehandlung

- Alle Calls haben Timeout (Standard: 30s, Plan-Generation: 60s)
- Fehlgeschlagene Calls werden in `ai_logs` mit `error`-Spalte protokolliert
- Exceptions werden weitergegeben (kein Silent-Fail)
MD,
            ],

            [
                'slug'       => 'api-controllers',
                'category'   => 'api',
                'title'      => 'Controller-Referenz',
                'sort_order' => 2,
                'content'    => <<<'MD'
# Controller-Referenz

## ActivityController
`app/Http/Controllers/ActivityController.php`

| Methode | Route | Funktion |
|---------|-------|----------|
| `index()` | GET /activities | Alle Aktivitäten des Nutzers paginiert |
| `show()` | GET /activities/{id} | Aktivitäts-Detail mit Karte |
| `sync()` | POST /activities/sync | Manuelle Strava-Synchronisation |

## AIController
`app/Http/Controllers/AIController.php`

| Methode | Route | Funktion |
|---------|-------|----------|
| `recommendation()` | GET /ai/recommendation | Tages-Trainingsempfehlung (gecacht) |
| `dailyMessage()` | GET /ai/daily-message | Coach-Spruch (gecacht) |
| `weeklyReview()` | GET /ai/weekly-review | Wochenrückblick generieren |

## CoachChatController
`app/Http/Controllers/CoachChatController.php`

| Methode | Route | Funktion |
|---------|-------|----------|
| `message()` | POST /coach-chat | Chat-Nachricht mit Coach-Kontext |
| `history()` | GET /coach-chat | Chat-Verlauf anzeigen |

## DashboardController
`app/Http/Controllers/DashboardController.php`

| Methode | Route | Funktion |
|---------|-------|----------|
| `index()` | GET /dashboard | Dashboard-Daten (TSB, nächste Session, Aktivitäten) |

## EventController
`app/Http/Controllers/EventController.php`

Vollständiges CRUD für Wettkampf-Events (index, show, store, update, destroy).

## GoalController
`app/Http/Controllers/GoalController.php`

Ziele (Goals) mit Fortschritts-Tracking. CRUD + `complete()`.

## OnboardingController
`app/Http/Controllers/OnboardingController.php`

Mehrstufiger Onboarding-Wizard. Steps 1-5, `finish()`.

## ProfileController
`app/Http/Controllers/ProfileController.php`

| Methode | Funktion |
|---------|----------|
| `show()` | Profil anzeigen |
| `update()` | Profil aktualisieren |
| `garminLogin()` | Garmin-Zugangsdaten prüfen + Session speichern |
| `garminDisconnect()` | Garmin-Session löschen |
| `updateNotifications()` | Push-Benachrichtigungs-Einstellungen |

## StatisticsController
`app/Http/Controllers/StatisticsController.php`

Aggregierte Statistiken: km/Woche, CTL/ATL/TSB-Verlauf, Pace-Entwicklung, Aktivitäts-Heatmap.

## TrainingPlanController
`app/Http/Controllers/TrainingPlanController.php`

| Methode | Funktion |
|---------|----------|
| `generate()` | Plan erstellen via OpenAI |
| `show()` | Plan anzeigen |
| `regenerate()` | Plan manuell neu berechnen |

## TrainingSessionController
`app/Http/Controllers/TrainingSessionController.php`

| Methode | Funktion |
|---------|----------|
| `complete()` | Session abschließen + triggerCoachReaction() |
| `skip()` | Session überspringen + triggerCoachReaction() |
| `sendToGarmin()` | Workout zu Garmin Connect senden |
| `generateSteps()` | KI-Workout-Steps generieren |

## WellbeingController
`app/Http/Controllers/WellbeingController.php`

Tägliches Wellbeing-Tracking (index, store, destroy). Triggert `AdjustPlanForWellbeingJob` bei schlechten Werten.

## WebhookController
`app/Http/Controllers/WebhookController.php`

| Methode | Route | Funktion |
|---------|-------|----------|
| `github()` | POST /webhook/github | GitHub Push → Changelog-Eintrag |
MD,
            ],

            [
                'slug'       => 'api-webhooks',
                'category'   => 'api',
                'title'      => 'Webhooks',
                'sort_order' => 3,
                'content'    => <<<'MD'
# Webhooks

Webhook-Endpunkte sind **ohne Auth-Middleware** und von CSRF ausgenommen (`bootstrap/app.php`).

## Strava Webhook

| Methode | Pfad | Zweck |
|---------|------|-------|
| `GET` | `/strava/webhook` | Webhook-Verifikation (Strava Challenge) |
| `POST` | `/strava/webhook` | Neue Aktivität / Update |

**Ablauf:** Neue Aktivität → Import → Plan `needs_plan_update=true` → `RegeneratePlanJob` nach 5 Min

## GitHub Webhook (Wiki Changelog)

| Methode | Pfad | Zweck |
|---------|------|-------|
| `POST` | `/webhook/github` | Push-Events → Changelog-Eintrag |

**Sicherheit:** HMAC-SHA256 Signatur mit `GITHUB_WEBHOOK_SECRET`

**Ablauf:** Push → Signatur prüfen → Commits extrahieren → OpenAI Summary → `wiki_changelogs` speichern

**Setup in GitHub:**
1. Repository → Settings → Webhooks → Add webhook
2. Payload URL: `https://deine-domain/webhook/github`
3. Content type: `application/json`
4. Secret: Wert aus `.env GITHUB_WEBHOOK_SECRET`
5. Events: nur "Push events"
MD,
            ],

            [
                'slug'       => 'api-fit-service',
                'category'   => 'api',
                'title'      => 'FIT-Service (Python)',
                'sort_order' => 4,
                'content'    => <<<'MD'
# FIT-Service (Python Microservice)

FastAPI-Service für Garmin-Integration. Läuft auf `FIT_SERVICE_URL` (Port 8001).

## Endpunkte

### `POST /garmin-login`
Authentifizierung mit Email/Passwort.

**Request:**
```json
{ "garmin_email": "user@example.com", "garmin_password": "..." }
```

**Response:**
```json
{ "session": "garth-dumps-json-string" }
```

### `POST /send-to-garmin`
Workout zu Garmin Connect hochladen + Kalender-Eintrag.

**Request:**
```json
{
  "garmin_session": "...",
  "name": "Tempolauf",
  "description": "...",
  "date": "2026-05-25",
  "sport": "running",
  "steps": [
    { "name": "Aufwärmen", "step_type": "warmup", "duration_sec": 600 },
    { "name": "Tempoblock", "step_type": "work", "meters": 1000, "speedMps": 4.2, "repetitions": 5 },
    { "name": "Pause", "step_type": "rest", "duration_sec": 120, "repetitions": 5 }
  ]
}
```

### `POST /generate-fit`
Gibt binäre `.fit`-Datei zurück (Download).

### `GET /health`
Health-Check → `{"status": "ok"}`

## Step-Typen

| step_type | Garmin | Beschreibung |
|-----------|--------|--------------|
| `warmup` | Aufwärmen | Erster Step |
| `work` / `interval` | Intervall | Hauptbelastung |
| `rest` / `recovery` | Erholung | Pause |
| `cooldown` | Auslaufen | Letzter Step |

## Garmin Session-Format

Die `garmin_session` ist ein JSON-String von `garth.dumps()`. Wird in Laravel AES-verschlüsselt gespeichert.
MD,
            ],

            [
                'slug'       => 'api-jobs',
                'category'   => 'api',
                'title'      => 'Background Jobs',
                'sort_order' => 5,
                'content'    => <<<'MD'
# Background Jobs

Alle Jobs laufen über die Laravel Queue (`database` Driver).

## RegeneratePlanJob
`app/Jobs/RegeneratePlanJob.php`

Regeneriert den Trainingsplan für einen Nutzer.

**Dispatch:** `RegeneratePlanJob::dispatch($userId, userTriggered: true/false)`

**Debounce:** 6h-Schutz gegen mehrfaches Ausführen — wird bypassed wenn `userTriggered=true`

**Nach Ausführung:** Coach-Cache geleert (`today_recommendation`, `daily_message` = null)

## AdjustPlanForWellbeingJob
`app/Jobs/AdjustPlanForWellbeingJob.php`

Bei sehr schlechten Wellbeing-Werten (krank/verletzt oder Score < Schwelle):
- Plan auf `needs_plan_update=true`
- `RegeneratePlanJob` dispatchen

**Trigger:** `WellbeingController::store()` nach Wellbeing-Eintrag

## CalculateThresholdPaceJob
`app/Jobs/CalculateThresholdPaceJob.php`

Berechnet Laktatschwellen-Pace aus den letzten Aktivitäten.
Speichert Ergebnis in `runner_profiles.threshold_pace` + aktualisiert alle 5 Pace-Zonen.

**Trigger:** Nach jedem Strava-Aktivitäts-Import

## ImportStravaActivityJob
`app/Jobs/ImportStravaActivityJob.php`

Importiert eine einzelne Strava-Aktivität per API-Call.
Matched automatisch mit geplanten Training Sessions (gleicher Tag).

## SendPushNotificationJob
`app/Jobs/SendPushNotificationJob.php`

Versendet Web-Push-Benachrichtigung an einen Nutzer via VAPID.

## GenerateWeeklyReviewJob
`app/Jobs/GenerateWeeklyReviewJob.php`

Generiert automatisch wöchentlichen Trainings-Rückblick via OpenAI.
Wird montags um 07:00 dispatcht.
MD,
            ],

            [
                'slug'       => 'api-commands',
                'category'   => 'api',
                'title'      => 'Artisan Commands',
                'sort_order' => 6,
                'content'    => <<<'MD'
# Artisan Commands

## plan:auto-update
`app/Console/Commands/AutoUpdatePlans.php`

Prüft täglich ob Trainingspläne Lücken haben.

```bash
php artisan plan:auto-update
php artisan plan:auto-update --user=5  # Nur ein User
```

**Logik:**
- Alle aktiven Pläne mit Events in den nächsten 21 Tagen
- Geplante Sessions zählen vs. erwartete Tage
- Wenn Lücke erkannt → `needs_plan_update=true` + `RegeneratePlanJob`
- 12h-Schutz gegen wiederholte Aufrufe
- **Cron:** täglich 05:00

## wiki:seed
`app/Console/Commands/SeedWiki.php`

Befüllt das Wiki mit initialer Projektdokumentation.

```bash
php artisan wiki:seed           # Nur neue Seiten
php artisan wiki:seed --force   # Alle Seiten überschreiben
```

**Läuft automatisch** beim Container-Start via `startup.sh --force`.

## db:seed --class=CoachSeeder
`database/seeders/CoachSeeder.php`

Befüllt die `coaches`-Tabelle mit vordefinierten Coach-Persönlichkeiten.
Läuft beim Container-Start.

## ai:weekly-review
Artisan-Command der wöchentlich um Mo 07:00 `GenerateWeeklyReviewJob` für alle aktiven Nutzer dispatcht.

## push:wellbeing-reminders
Versendet Wellbeing-Reminder Push-Benachrichtigungen zur konfigurierten Zeit.

## strava:refresh-tokens
Erneuert ablaufende Strava-OAuth-Tokens für alle Nutzer.
MD,
            ],

            [
                'slug'       => 'api-frontend',
                'category'   => 'api',
                'title'      => 'Frontend — Vue Pages',
                'sort_order' => 7,
                'content'    => <<<'MD'
# Frontend — Vue Pages

Zone3 nutzt Vue 3 + Inertia.js. Alle Pages sind unter `resources/js/Pages/` zu finden.

## Layouts

| Layout | Pfad | Zweck |
|--------|------|-------|
| `AppLayout` | `Layouts/AppLayout.vue` | Haupt-Layout für eingeloggte Nutzer |
| `AdminLayout` | `Layouts/AdminLayout.vue` | Admin-Bereich Layout mit Sidebar |
| `GuestLayout` | `Layouts/GuestLayout.vue` | Login/Register Layout |

## Haupt-Pages

| Page | Pfad | Zweck |
|------|------|-------|
| Dashboard | `Dashboard.vue` | Hauptseite mit Coach-Spruch, nächster Session, Aktivitäten |
| Events Index | `Events/Index.vue` | Event-Liste |
| Events Show | `Events/Show.vue` | Event-Detail mit Trainingsplan |
| Events Plan | `Events/Plan.vue` | Trainingsplan-Ansicht |
| Activities Index | `Activities/Index.vue` | Aktivitäten-Liste |
| Activities Show | `Activities/Show.vue` | Aktivitäts-Detail mit Karte (Leaflet) |
| Profile | `Profile/Show.vue` | Profil bearbeiten |
| Statistics | `Statistics/Index.vue` | CTL/ATL/TSB Charts |
| Wellbeing | `Wellbeing/Index.vue` | Wellbeing-Tracking |
| Goals | `Goals/Index.vue` | Ziele-Verwaltung |

## Onboarding Pages

`Onboarding/Step1.vue` bis `Step5.vue` — mehrstufiger Wizard.

## Admin Pages

| Page | Pfad |
|------|------|
| Admin Dashboard | `Admin/Dashboard.vue` |
| User-Liste | `Admin/Users/Index.vue` |
| Coach-Verwaltung | `Admin/Coaches/Index.vue` |
| Wiki Index | `Admin/Wiki/Index.vue` |
| Wiki Page | `Admin/Wiki/Page.vue` |
| Wiki Changelog | `Admin/Wiki/Changelog.vue` |

## Wichtige npm-Dependencies

| Package | Zweck |
|---------|-------|
| `@inertiajs/vue3` | Inertia.js Vue 3 Adapter |
| `leaflet` | Interaktive Karten für Aktivitäten |
| `cropperjs` | Avatar-Bild-Cropping |
| `ziggy-js` | Laravel-Routen in JavaScript |
| `@garmin/fitsdk` | FIT-Datei Unterstützung |
MD,
            ],

            // ─────────────────────────────────────────
            //  DECISIONS (ADRs)
            // ─────────────────────────────────────────

            [
                'slug'       => 'adr-garmin-token-storage',
                'category'   => 'decisions',
                'title'      => 'ADR: Garmin Token-Speicherung',
                'sort_order' => 1,
                'content'    => <<<'MD'
# ADR: Garmin Token-Speicherung statt Passwort

**Datum:** Mai 2026
**Status:** Implementiert

## Kontext

Nutzer mussten bei jeder Garmin-Aktion Email + Passwort neu eingeben. Das war inakzeptabel für UX.

## Entscheidung

Garmin-Session-Tokens (von `garth.dumps()`) werden verschlüsselt in der Datenbank gespeichert:

- `users.garmin_email` — E-Mail-Adresse (für Anzeige)
- `users.garmin_session` — garth-Session-Tokens (Laravel `encrypted` Cast → AES-256 mit APP_KEY)

**Nie gespeichert:** Das Passwort selbst.

## Begründung

- garth-Tokens sind OAuth-ähnliche Langzeit-Tokens, kein Passwort
- Laravel `encrypted` Cast nutzt `APP_KEY` für AES-Verschlüsselung
- Bei abgelaufenem Token: `session_expired` Fehler → User muss sich neu einloggen
- Trennen-Button löscht `garmin_session` und `garmin_email`

## Konsequenzen

- Nutzer muss sich nur einmal bei Garmin einloggen
- Bei `APP_KEY`-Änderung werden alle Sessions ungültig
- MFA-fähige Accounts können Zone3 nicht nutzen
MD,
            ],

            [
                'slug'       => 'adr-auto-coaching',
                'category'   => 'decisions',
                'title'      => 'ADR: Proaktives Auto-Coaching',
                'sort_order' => 2,
                'content'    => <<<'MD'
# ADR: Proaktives Auto-Coaching System

**Datum:** Mai 2026
**Status:** Implementiert

## Kontext

Der Coach war passiv — Nutzer mussten manuell "Plan aktualisieren" klicken. Das widerspricht der Idee eines echten Coaches.

## Entscheidung

Drei automatische Trigger für Plan-Neuberechnung:

### 1. Session überspringen / abschließen
`TrainingSessionController` → `triggerCoachReaction()`:
- `needs_plan_update = true` auf aktivem Plan
- `RegeneratePlanJob::dispatch($userId, userTriggered: true)` mit 10s Delay
- `userTriggered=true` bypasses das 6h-Debounce
- Coach-Cache geleert (`today_recommendation`, `daily_message`)

### 2. Täglicher Gap-Check (05:00 Uhr)
`plan:auto-update` Artisan-Command:
- Prüft alle Pläne mit Rennen in 21 Tagen
- Lücke erkannt → Neuberechnung
- 12h-Schutz gegen wiederholte Aufrufe

### 3. Nach Strava-Sync
Strava-Webhook setzt `needs_plan_update=true` → `RegeneratePlanJob` nach 5 Min.

## Coach-Cache-Invalidierung

Nach jeder Plan-Neuberechnung in `RegeneratePlanJob`:
- `today_recommendation` = null
- `recommendation_date` = null
- `daily_message` = null
- `daily_message_date` = null

Nächstes Dashboard-Load generiert frischen Coach-Spruch mit neuem Plan-Kontext.

## Begründung

Nutzer sollen nicht über technische Hintergründe nachdenken müssen. Ein echter Coach reagiert sofort auf Änderungen.
MD,
            ],

            [
                'slug'       => 'adr-plan-coverage',
                'category'   => 'decisions',
                'title'      => 'ADR: Vollständige Plan-Abdeckung bis Renntag',
                'sort_order' => 3,
                'content'    => <<<'MD'
# ADR: Vollständige Plan-Abdeckung bis Renntag

**Datum:** Mai 2026
**Status:** Implementiert

## Problem

Der Plan-Generator hatte ein hartes Limit von 10 Sessions. Bei Rennen in >10 Tagen fehlten die letzten Tage (insb. Tapering-Woche). Ruhetage wurden nicht explizit als Sessions eingetragen.

## Lösung

In `OpenAIService::generateEventTrainingPlan()`:

1. **Schleife erweitert:** `for ($i = 0; $i < 10)` → `for ($i = 0; $i < min(21, $daysUntil + 1))`
2. **Prompt geändert:** "zwischen 1 und 10 Objekte" → "GENAU N Einträge — einen pro Tag, Ruhetage als type='rest'"
3. **UI-Text:** "10-Tages-Trainingsplan" → "Trainingsplan bis zum Rennen"

## Ergebnis

Bei 11 Tagen bis zum Rennen: KI generiert exakt 11 Einträge (inkl. expliziter Ruhetage für die Tapering-Woche).
MD,
            ],

            [
                'slug'       => 'adr-wiki-system',
                'category'   => 'decisions',
                'title'      => 'ADR: Wiki & Changelog-System',
                'sort_order' => 4,
                'content'    => <<<'MD'
# ADR: Wiki & Changelog-System

**Datum:** Mai 2026
**Status:** Implementiert

## Kontext

Keine strukturierte Projektdokumentation vorhanden. Änderungen waren schwer nachzuvollziehen.

## Entscheidung

Zwei-Komponenten-System:

### 1. Wiki (statische Dokumentation)
- `wiki_pages` Tabelle mit 4 Kategorien: Architektur, Features, API, Entscheidungen (ADRs)
- Inline Markdown-Editor im Admin-Bereich
- `wiki:seed` Command befüllt initiale Dokumentation aus dem Code-Scan
- `startup.sh` führt `wiki:seed --force` bei jedem Deploy aus → immer aktuell

### 2. Changelog (automatisch)
- GitHub Webhook → `POST /webhook/github`
- HMAC-SHA256 Signatur-Verifikation (`GITHUB_WEBHOOK_SECRET`)
- OpenAI GPT-4o generiert deutschsprachige KI-Zusammenfassung der Änderungen
- `wiki_changelogs` Tabelle speichert Commits, geänderte Dateien, KI-Summary

## Implementierungs-Details

**PHP Nowdoc statt Heredoc** für Seed-Content:
- Heredoc (`<<<MD`) interpoliert PHP-Variablen → bricht Code-Beispiele mit `$user->...`
- Nowdoc (`<<<'MD'`) = keine Interpolation → sicher für Markdown mit PHP-Code

**Wichtig:** PHP-Nowdoc-Closing-Marker (`MD;`) muss an Spalte 0 stehen — keine Einrückung erlaubt.

## Begründung

Der Admin soll jederzeit den aktuellen Projektstand sehen können, ohne Code lesen zu müssen.
MD,
            ],

            [
                'slug'       => 'adr-inertia-vue',
                'category'   => 'decisions',
                'title'      => 'ADR: Inertia.js + Vue 3',
                'sort_order' => 5,
                'content'    => <<<'MD'
# ADR: Inertia.js + Vue 3 als Frontend-Stack

**Datum:** Projektstart
**Status:** Produktiv

## Kontext

Wahl des Frontend-Stacks: klassisches Blade + AJAX, API-only + SPA, oder Inertia.js.

## Entscheidung

**Inertia.js + Vue 3** als "Monolith SPA":
- Routen und Auth bleiben in Laravel
- Kein separates API nötig
- Vue-Komponenten für reaktive UI
- Keine JSON-API für Standard-Operationen (Props werden serverseitig übergeben)

## Begründung

- Einfachheit: Ein Codebase, kein CORS, kein Token-Management
- Laravel-Routen bleiben aktiv (route() Helper in Vue via Ziggy)
- Form-Handling über Inertia Forms (kein Axios boilerplate)
- SEO nicht relevant (Auth-only App)

## Konsequenzen

- Kein SPA-Routing ohne Server-Request (kein `vue-router`)
- Props müssen vom Controller mitgegeben werden
- Für Echtzeit-Updates: manuelle `router.reload()` oder Polling nötig
MD,
            ],

        ];
    }
}
