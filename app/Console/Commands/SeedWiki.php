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
                $this->line("â­  Skipping '{$data['title']}' (already exists)");
                continue;
            }

            WikiPage::updateOrCreate(['slug' => $data['slug']], $data);
            $this->line("âœ“  " . ($exists ? 'Updated' : 'Created') . " '{$data['title']}'");
            $count++;
        }

        $this->info("Wiki seeded: {$count} page(s) written.");
    }

    private function pages(): array
    {
        return [
            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            //  ARCHITEKTUR
            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            [
                'slug'       => 'architecture-overview',
                'category'   => 'architecture',
                'title'      => 'Architektur-Ãœbersicht',
                'sort_order' => 1,
                'content'    => <<<'MD'
# Zone3 â€” Architektur-Ãœbersicht

Zone3 ist eine KI-gestÃ¼tzte Lauf-Trainingsplattform. Die Anwendung besteht aus zwei Hauptkomponenten: einem Laravel-Monolith und einem Python-Microservice.

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
| CI/CD | GitHub â†’ Coolify Webhook |

## Komponenten

### Laravel-Monolith (Hauptanwendung)
- **URL:** `APP_URL` (konfiguriert in .env)
- Verwaltet alle Nutzer, AktivitÃ¤ten, TrainingsplÃ¤ne, Events und KI-Empfehlungen
- Strava-Integration via OAuth und Webhook
- Garmin-Integration via Python-Service
- Admin-Bereich unter `/admin`

### Python FIT-Service (Microservice)
- **URL:** `FIT_SERVICE_URL` (Port 8001)
- FastAPI-Microservice fÃ¼r Garmin FIT-Datei-Generierung
- Garmin Connect API-Integration
- Endpunkte: `POST /generate-fit`, `POST /send-to-garmin`, `POST /garmin-login`, `GET /health`

## Datenbankstruktur (wichtigste Tabellen)

- `users` â€” Nutzer mit Garmin-Session (verschlÃ¼sselt)
- `runner_profiles` â€” Athletenprofil, Pace-Zonen, gecachte KI-Empfehlungen
- `events` â€” Wettkampf-Events mit PrioritÃ¤t (A/B/C)
- `training_plans` â€” KI-generierte TrainingsplÃ¤ne pro Event
- `training_sessions` â€” Einzelne Trainingseinheiten (geplant/abgeschlossen/Ã¼bersprungen)
- `activities` â€” Importierte Strava-AktivitÃ¤ten
- `wellbeing_entries` â€” TÃ¤gliche Wellbeing-Daten
- `coaches` â€” Coach-PersÃ¶nlichkeiten fÃ¼r KI-Prompts
- `ai_logs` â€” Logging aller OpenAI-API-Aufrufe
- `wiki_pages` / `wiki_changelogs` â€” Dieses Wiki
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

Zone3 hat kein lokales Dev-Environment. Alle Ã„nderungen werden direkt deployed:

1. **Code-Ã„nderung** â†’ Commit + Push zu GitHub (`main` Branch)
2. **GitHub** lÃ¶st automatisch Coolify-Webhook aus
3. **Coolify** baut Docker-Image neu und deployt
4. **startup.sh** lÃ¤uft automatisch beim Container-Start

## startup.sh (Startup-Sequenz)

```bash
set -e  # Fehler = Container stoppt
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
npm run build
php artisan queue:work --daemon
php artisan schedule:run
uvicorn app:app --host 0.0.0.0 --port 8001  # FIT-Service
```

**Wichtig:** `set -e` bedeutet jeder Fehler verhindert den Server-Start. PHP-Syntax-Fehler mÃ¼ssen vor dem Push behoben sein.

## Umgebungsvariablen

| Variable | Zweck |
|----------|-------|
| `APP_KEY` | Laravel Encryption (auch fÃ¼r garmin_session) |
| `OPENAI_API_KEY` | GPT-4o Zugriff |
| `STRAVA_CLIENT_ID` / `STRAVA_CLIENT_SECRET` | Strava OAuth |
| `FIT_SERVICE_URL` | Python Microservice URL |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | Web Push Notifications |
| `GITHUB_WEBHOOK_SECRET` | GitHub Webhook Signatur-Verifikation |

## Queue & Scheduler

- **Queue:** `database` Driver â€” Jobs werden in `jobs` Tabelle gespeichert
- **Scheduler lÃ¤uft tÃ¤glich:** `plan:auto-update` (05:00), `ai:weekly-review` (Mo 07:00)
- **Scheduler lÃ¤uft jede Minute:** `push:wellbeing-reminders`
MD,
            ],

            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            //  FEATURES
            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            [
                'slug'       => 'feature-ai-training',
                'category'   => 'features',
                'title'      => 'KI-Trainingsplan',
                'sort_order' => 1,
                'content'    => <<<'MD'
# KI-Trainingsplan

Das HerzstÃ¼ck von Zone3 â€” der Coach generiert automatisch personalisierte TrainingsplÃ¤ne fÃ¼r Events.

## Wie es funktioniert

1. Nutzer legt ein Event an (z.B. 10K-Rennen am 31.05.)
2. Klick auf "Plan erstellen" â†’ `POST /events/{id}/plan/generate`
3. `TrainingPlanController::generate()` ruft `OpenAIService::generateEventTrainingPlan()` auf
4. KI bekommt: Athletenprofil, letzte AktivitÃ¤ten, Wellbeing, VerfÃ¼gbarkeit, TSB-Wert
5. KI generiert JSON-Array mit **einem Eintrag pro Tag** bis zum Renntag
6. Sessions werden in `training_sessions` gespeichert

## Auto-Update (seit Mai 2026)

- **TÃ¤glich 05:00** lÃ¤uft `plan:auto-update` â€” erkennt LÃ¼cken und regeneriert automatisch
- **Session Ã¼berspringen** triggert sofortige Neuplanung (RegeneratePlanJob, userTriggered=true)
- **Nach Strava-Sync** wird Plan bei Bedarf neu berechnet
- Nach jeder Neuberechnung wird `today_recommendation` Cache geleert â†’ frischer Coach-Spruch

## Tapering-Logik

| Zeitraum | Strategie |
|----------|-----------|
| >30 Tage | Normaler Aufbau (Volumen + Tempo) |
| 10-30 Tage | Tapering einleiten (Volumen reduzieren) |
| <10 Tage | Starkes Tapering â€” nur leichte LÃ¤ufe und explizite Ruhetage |
| Renntag | `type="race_prep"` |

## Session-Typen

`rest`, `easy_run`, `tempo_run`, `interval`, `long_run`, `race_prep`
MD,
            ],

            [
                'slug'       => 'feature-garmin',
                'category'   => 'features',
                'title'      => 'Garmin Connect Integration',
                'sort_order' => 2,
                'content'    => <<<'MD'
# Garmin Connect Integration

Zone3 kann Trainingseinheiten direkt in Garmin Connect hochladen und im Kalender eintragen.

## Authentifizierung

Garmin verwendet kein OAuth â€” nur Email/Passwort mit `garth` Python-Library.

**Token-Speicherung (seit Mai 2026):**
- Login einmalig mit Email/Passwort
- `garth.dumps()` serialisiert Session-Tokens (keine PasswÃ¶rter!)
- Tokens werden AES-verschlÃ¼sselt in `users.garmin_session` gespeichert (Laravel `encrypted` Cast)
- NÃ¤chste Verbindung: Token wird wiederhergestellt via `garth.loads()` â€” kein erneuter Login

## Workout-Struktur

Garmin Connect erwartet einen speziellen JSON-Format:

```json
{
  "workoutName": "...",
  "sportType": { "sportTypeId": 1 },
  "workoutSegments": [{
    "workoutSteps": [
      { "type": "ExecutableStepDTO", ... },
      { "type": "RepeatGroupDTO", "numberOfIterations": 5, "workoutSteps": [...] }
    ]
  }]
}
```

**`RepeatGroupDTO`** gruppiert Intervall-Wiederholungen (Steps mit gleichem `repetitions`-Wert).

## FIT-Service Endpunkte

| Endpunkt | Funktion |
|----------|----------|
| `POST /garmin-login` | Authentifizierung, gibt garth-Session zurÃ¼ck |
| `POST /send-to-garmin` | Workout hochladen + Kalender-Eintrag |
| `POST /generate-fit` | BinÃ¤re .fit-Datei generieren |
| `GET /health` | Health-Check |

## Bekannte Limitierungen

- MFA (Zwei-Faktor) wird nicht unterstÃ¼tzt â†’ Fehler `mfa_required`
- Garmin API ist nicht Ã¶ffentlich dokumentiert â€” inoffizielle Endpunkte
MD,
            ],

            [
                'slug'       => 'feature-strava',
                'category'   => 'features',
                'title'      => 'Strava Integration',
                'sort_order' => 3,
                'content'    => <<<'MD'
# Strava Integration

Strava ist die primÃ¤re AktivitÃ¤ts-Datenquelle fÃ¼r Zone3.

## OAuth-Flow

1. Nutzer klickt "Mit Strava verbinden"
2. Redirect zu Strava OAuth (`/strava/authorize`)
3. Strava redirectet zurÃ¼ck mit Code
4. Token wird in `strava_accounts` gespeichert
5. AktivitÃ¤ten werden importiert und in `activities` gespeichert

## Webhook (Echtzeit-Updates)

Strava sendet Push-Events bei neuen AktivitÃ¤ten:
- `POST /strava/webhook` empfÃ¤ngt Event
- Neue AktivitÃ¤t wird importiert
- Plan wird auf `needs_plan_update = true` gesetzt
- `RegeneratePlanJob` wird nach 5 Minuten dispatcht (Batching mehrerer AktivitÃ¤ten)

## AktivitÃ¤ts-Matching

Nach Plan-Regenerierung werden abgeschlossene Strava-Runs automatisch mit geplanten Sessions verknÃ¼pft:
- Gleicher Tag â†’ Session wird als `completed` markiert
- Distanz + Dauer werden aus Strava Ã¼bernommen
- Ruhetage mit einem Lauf â†’ Rest-Session wird gelÃ¶scht, neue `easy_run` Session erstellt

## Schwellenpace-Berechnung

`CalculateThresholdPaceJob` berechnet automatisch die Laktatschwelle aus den letzten AktivitÃ¤ten und speichert sie im `runner_profiles`.
MD,
            ],

            [
                'slug'       => 'feature-wellbeing',
                'category'   => 'features',
                'title'      => 'Wellbeing & Coach-Dashboard',
                'sort_order' => 4,
                'content'    => <<<'MD'
# Wellbeing & Coach-Dashboard

## TÃ¤gliches Wellbeing

Nutzer tracken tÃ¤glich:
- **Energie** (1-10)
- **SchlafqualitÃ¤t** (1-10)
- **Muskelkater** (1-10)
- **Stress** (1-10)
- **Krank / Verletzt** (Boolean)

Wellbeing-Daten beeinflussen KI-Empfehlungen: schlechte Werte â†’ leichteres Training.

## Dashboard Coach-Spruch

Der Satz oben im Dashboard ist eine KI-Motivation die tÃ¤glich generiert wird:
- Gecacht in `runner_profiles.daily_message` (wird gelÃ¶scht wenn Plan neu berechnet wird)
- BerÃ¼cksichtigt: Wellbeing, nÃ¤chstes Event, letzte AktivitÃ¤ten, TSB-Wert

## Trainingsbelastung (CTL/ATL/TSB)

| Metrik | Bedeutung | Berechnung |
|--------|-----------|------------|
| CTL (Fitness) | 42-Tage EMA | Langzeit-Trainingsbelastung |
| ATL (ErmÃ¼dung) | 7-Tage EMA | Kurzzeit-Trainingsbelastung |
| TSB (Form) | CTL âˆ’ ATL | Positiv = frisch, Negativ = mÃ¼de |

## Push-Benachrichtigungen

- Web Push via VAPID-SchlÃ¼ssel
- Wellbeing-Reminder tÃ¤glich zur konfigurierten Zeit
- Plan-Update-Benachrichtigungen nach Neuberechnung
MD,
            ],

            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            //  API
            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            [
                'slug'       => 'api-openai',
                'category'   => 'api',
                'title'      => 'OpenAI Service',
                'sort_order' => 1,
                'content'    => <<<'MD'
# OpenAI Service (`app/Services/OpenAIService.php`)

Zentrale Klasse fÃ¼r alle KI-Aufrufe. Alle Calls werden in `ai_logs` protokolliert.

## Methoden

| Methode | Zweck | Call-Type |
|---------|-------|-----------|
| `generateRecommendation()` | Tages-Trainingsempfehlung | `recommendation` |
| `generateEventTrainingPlan()` | Kompletter Plan bis Renntag | `event_plan` |
| `generateSessionSteps()` | Schritt-fÃ¼r-Schritt-Workout | `session_steps` |
| `generateWeeklyReview()` | WochenrÃ¼ckblick | `weekly_review` |
| `generateDailyMessage()` | Coach-Spruch Dashboard | `daily_message` |
| `generatePrMessage()` | PR-GlÃ¼ckwunsch | `pr_message` |
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

## Coach-PersÃ¶nlichkeit

Jeder Nutzer kann einem Coach zugeordnet werden. Der `personality_prompt` des Coaches wird als System-Prompt vorangestellt:

```php
$openAI->withCoach($user->coach?->personality_prompt)->forUser($user->id);
```

## Fehlerbehandlung

- Alle Calls haben Timeout (Standard: 30s, Plan-Generation: 60s)
- Fehlgeschlagene Calls werden in `ai_logs` mit `error`-Spalte protokolliert
- Exceptions werden nach oben weitergegeben (kein Silent-Fail)
MD,
            ],

            [
                'slug'       => 'api-webhooks',
                'category'   => 'api',
                'title'      => 'Webhooks',
                'sort_order' => 2,
                'content'    => <<<'MD'
# Webhooks

Zone3 empfÃ¤ngt Webhooks von externen Diensten. Diese Endpunkte sind **ohne Auth-Middleware** und von CSRF ausgenommen.

## Strava Webhook

| Methode | Pfad | Zweck |
|---------|------|-------|
| `GET` | `/strava/webhook` | Webhook-Verifikation (Strava Challenge) |
| `POST` | `/strava/webhook` | Neue AktivitÃ¤t / Update |

**Ablauf:** Neue AktivitÃ¤t â†’ Import â†’ Plan auf `needs_plan_update=true` â†’ `RegeneratePlanJob` nach 5 Min

## GitHub Webhook (Wiki)

| Methode | Pfad | Zweck |
|---------|------|-------|
| `POST` | `/webhook/github` | Push-Events â†’ Changelog-Eintrag |

**Sicherheit:** HMAC-SHA256 Signatur mit `GITHUB_WEBHOOK_SECRET`

**Setup in GitHub:**
1. Repository â†’ Settings â†’ Webhooks â†’ Add webhook
2. Payload URL: `https://zone3.app/webhook/github`
3. Content type: `application/json`
4. Secret: Wert aus `.env GITHUB_WEBHOOK_SECRET`
5. Events: nur "Push events"

**Ablauf:** Push â†’ Signatur prÃ¼fen â†’ Commits extrahieren â†’ OpenAI Summary â†’ `wiki_changelogs` speichern
MD,
            ],

            [
                'slug'       => 'api-fit-service',
                'category'   => 'api',
                'title'      => 'FIT-Service (Python)',
                'sort_order' => 3,
                'content'    => <<<'MD'
# FIT-Service (Python Microservice)

FastAPI-Service fÃ¼r Garmin-Integration. LÃ¤uft auf `FIT_SERVICE_URL` (Port 8001).

## Endpunkte

### `POST /garmin-login`
Authentifizierung mit Email/Passwort.

**Request:**
```json
{ "garmin_email": "user@example.com", "garmin_password": "..." }
```

**Response:**
```json
{ "session": "garth-session-tokens-json-string" }
```

### `POST /send-to-garmin`
Workout zu Garmin Connect hochladen und im Kalender eintragen.

**Request:**
```json
{
  "garmin_session": "...",
  "name": "Tempolauf",
  "description": "...",
  "date": "2026-05-25",
  "sport": "running",
  "steps": [
    { "name": "AufwÃ¤rmen", "step_type": "warmup", "duration_sec": 600 },
    { "name": "Tempoblock", "step_type": "work", "meters": 1000, "speedMps": 4.2, "repetitions": 5 },
    { "name": "Pause", "step_type": "rest", "duration_sec": 120, "repetitions": 5 }
  ]
}
```

### `POST /generate-fit`
Gibt binÃ¤re `.fit`-Datei zurÃ¼ck (Download).

### `GET /health`
Health-Check â†’ `{"status": "ok"}`

## Step-Typen

| step_type | Garmin | Beschreibung |
|-----------|--------|--------------|
| `warmup` | AufwÃ¤rmen | Erster Step |
| `work` / `interval` | Intervall | Hauptbelastung |
| `rest` / `recovery` | Erholung | Pause zwischen Intervallen |
| `cooldown` | Auslaufen | Letzter Step |

## Garmin Session-Format

Die `garmin_session` ist ein JSON-String von `garth.dumps()`:
```json
{"oauth1_token": {...}, "oauth2_token": {...}}
```

Wird in Laravel AES-verschlÃ¼sselt in `users.garmin_session` gespeichert.
MD,
            ],

            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            //  DECISIONS
            // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
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

Nutzer mussten bei jeder Garmin-Connect-Aktion Email + Passwort neu eingeben. Das war inakzeptabel fÃ¼r UX.

## Entscheidung

Garmin-Session-Tokens (von `garth.dumps()`) werden verschlÃ¼sselt in der Datenbank gespeichert:

- `users.garmin_email` â€” E-Mail-Adresse (fÃ¼r Anzeige)
- `users.garmin_session` â€” garth-Session-Tokens (Laravel `encrypted` Cast â†’ AES-256)

**Nie gespeichert:** Das Passwort selbst.

## BegrÃ¼ndung

- garth-Tokens sind OAuth-Ã¤hnliche Langzeit-Tokens, kein Passwort
- Laravel `encrypted` Cast nutzt `APP_KEY` fÃ¼r AES-VerschlÃ¼sselung
- Bei abgelaufenem Token: `session_expired` Fehler â†’ User wird aufgefordert, sich neu einzuloggen
- Trennen-Button lÃ¶scht `garmin_session` und `garmin_email`

## Konsequenzen

- Nutzer muss sich nur einmal bei Garmin einloggen
- Bei `APP_KEY`-Ã„nderung werden alle Sessions ungÃ¼ltig (Nutzer mÃ¼ssen neu einloggen)
- MFA-fÃ¤hige Accounts kÃ¶nnen Zone3 nicht nutzen (Garmin MFA = kein Automatismus mÃ¶glich)
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

Der Coach war passiv â€” Nutzer mussten manuell "Plan aktualisieren" klicken. Das widerspricht der Idee eines echten Coaches.

## Entscheidung

Drei automatische Trigger fÃ¼r Plan-Neuberechnung:

### 1. Session Ã¼berspringen/abschlieÃŸen
`TrainingSessionController::skip()` und `complete()` rufen `triggerCoachReaction()` auf:
- `needs_plan_update = true` auf aktivem Plan
- `RegeneratePlanJob::dispatch($userId, userTriggered: true)` mit 10s Delay
- `userTriggered=true` bypasses das 6h-Debounce (sofortige Reaktion)
- `today_recommendation` + `daily_message` Cache wird geleert

### 2. TÃ¤glicher Gap-Check (05:00 Uhr)
`plan:auto-update` Artisan-Command:
- PrÃ¼ft alle PlÃ¤ne mit Rennen in den nÃ¤chsten 21 Tagen
- Wenn geplante Sessions < verbleibende Tage â†’ Neuberechnung
- 12h-Schutz gegen wiederholte Aufrufe

### 3. Nach Strava-Sync
Bestand schon vorher â€” Strava-Webhook setzt `needs_plan_update=true`.

## Coach-Cache-Invalidierung

Nach jeder Plan-Neuberechnung in `RegeneratePlanJob`:
```php
$user->runnerProfile?->update([
    'today_recommendation' => null,
    'recommendation_date'  => null,
    'daily_message'        => null,
    'daily_message_date'   => null,
]);
```

NÃ¤chstes Dashboard-Load generiert frischen Coach-Spruch mit neuem Plan-Kontext.

## BegrÃ¼ndung

Nutzer sollen nicht Ã¼ber technische HintergrÃ¼nde nachdenken mÃ¼ssen. Ein echter Coach reagiert sofort auf Ã„nderungen.
MD,
            ],

            [
                'slug'       => 'adr-plan-coverage',
                'category'   => 'decisions',
                'title'      => 'ADR: VollstÃ¤ndige Plan-Abdeckung bis Renntag',
                'sort_order' => 3,
                'content'    => <<<'MD'
# ADR: VollstÃ¤ndige Plan-Abdeckung bis Renntag

**Datum:** Mai 2026
**Status:** Implementiert

## Problem

Der Plan-Generator hatte ein hartes Limit von 10 Sessions. Bei Rennen in >10 Tagen fehlten die letzten Tage (insb. Tapering-Woche). Ruhetage wurden nicht explizit als Sessions eingetragen.

## LÃ¶sung

In `OpenAIService::generateEventTrainingPlan()`:

1. **Schleife erweitert:** `for ($i = 0; $i < 10; $i++)` â†’ `for ($i = 0; $i < min(21, $daysUntil + 1); $i++)`
2. **Prompt geÃ¤ndert:** "zwischen 1 und 10 Objekte" â†’ "GENAU {$totalDays} EintrÃ¤ge â€” einen pro Tag, Ruhetage als type='rest'"
3. **UI-Text:** "10-Tages-Trainingsplan" â†’ "Trainingsplan bis zum Rennen"

## Ergebnis

Bei 11 Tagen bis zum Rennen: KI generiert exakt 11 EintrÃ¤ge (10 Trainingstage + Renntag), inklusive expliziter Ruhetage fÃ¼r die Tapering-Woche.
MD,
            ],
        ];
    }
}

