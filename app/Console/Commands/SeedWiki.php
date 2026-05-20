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
                $this->line("⏭  Skipping '{$data['title']}' (already exists)");
                continue;
            }

            WikiPage::updateOrCreate(['slug' => $data['slug']], $data);
            $this->line("✓  " . ($exists ? 'Updated' : 'Created') . " '{$data['title']}'");
            $count++;
        }

        $this->info("Wiki seeded: {$count} page(s) written.");
    }

    private function pages(): array
    {
        return [
            // ══════════════════════════════════════
            //  ARCHITEKTUR
            // ══════════════════════════════════════
            [
                'slug'       => 'architecture-overview',
                'category'   => 'architecture',
                'title'      => 'Architektur-Übersicht',
                'sort_order' => 1,
                'content'    => <<<MD
# Zone3 — Architektur-Übersicht

Zone3 ist eine KI-gestützte Lauf-Trainingsplattform. Die Anwendung besteht aus zwei Hauptkomponenten: einem Laravel-Monolith und einem Python-Microservice.

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
- **URL:** `APP_URL` (konfiguriert in .env)
- Verwaltet alle Nutzer, Aktivitäten, Trainingspläne, Events und KI-Empfehlungen
- Strava-Integration via OAuth und Webhook
- Garmin-Integration via Python-Service
- Admin-Bereich unter `/admin`

### Python FIT-Service (Microservice)
- **URL:** `FIT_SERVICE_URL` (Port 8001)
- FastAPI-Microservice für Garmin FIT-Datei-Generierung
- Garmin Connect API-Integration
- Endpunkte: `POST /generate-fit`, `POST /send-to-garmin`, `POST /garmin-login`, `GET /health`

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
                'content'    => <<<MD
# Deployment & Server

## Deployment-Workflow

Zone3 hat kein lokales Dev-Environment. Alle Änderungen werden direkt deployed:

1. **Code-Änderung** → Commit + Push zu GitHub (`main` Branch)
2. **GitHub** löst automatisch Coolify-Webhook aus
3. **Coolify** baut Docker-Image neu und deployt
4. **startup.sh** läuft automatisch beim Container-Start

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

**Wichtig:** `set -e` bedeutet jeder Fehler verhindert den Server-Start. PHP-Syntax-Fehler müssen vor dem Push behoben sein.

## Umgebungsvariablen

| Variable | Zweck |
|----------|-------|
| `APP_KEY` | Laravel Encryption (auch für garmin_session) |
| `OPENAI_API_KEY` | GPT-4o Zugriff |
| `STRAVA_CLIENT_ID` / `STRAVA_CLIENT_SECRET` | Strava OAuth |
| `FIT_SERVICE_URL` | Python Microservice URL |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | Web Push Notifications |
| `GITHUB_WEBHOOK_SECRET` | GitHub Webhook Signatur-Verifikation |

## Queue & Scheduler

- **Queue:** `database` Driver — Jobs werden in `jobs` Tabelle gespeichert
- **Scheduler läuft täglich:** `plan:auto-update` (05:00), `ai:weekly-review` (Mo 07:00)
- **Scheduler läuft jede Minute:** `push:wellbeing-reminders`
MD,
            ],

            // ══════════════════════════════════════
            //  FEATURES
            // ══════════════════════════════════════
            [
                'slug'       => 'feature-ai-training',
                'category'   => 'features',
                'title'      => 'KI-Trainingsplan',
                'sort_order' => 1,
                'content'    => <<<MD
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
- **Session überspringen** triggert sofortige Neuplanung (RegeneratePlanJob, userTriggered=true)
- **Nach Strava-Sync** wird Plan bei Bedarf neu berechnet
- Nach jeder Neuberechnung wird `today_recommendation` Cache geleert → frischer Coach-Spruch

## Tapering-Logik

| Zeitraum | Strategie |
|----------|-----------|
| >30 Tage | Normaler Aufbau (Volumen + Tempo) |
| 10-30 Tage | Tapering einleiten (Volumen reduzieren) |
| <10 Tage | Starkes Tapering — nur leichte Läufe und explizite Ruhetage |
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
                'content'    => <<<MD
# Garmin Connect Integration

Zone3 kann Trainingseinheiten direkt in Garmin Connect hochladen und im Kalender eintragen.

## Authentifizierung

Garmin verwendet kein OAuth — nur Email/Passwort mit `garth` Python-Library.

**Token-Speicherung (seit Mai 2026):**
- Login einmalig mit Email/Passwort
- `garth.dumps()` serialisiert Session-Tokens (keine Passwörter!)
- Tokens werden AES-verschlüsselt in `users.garmin_session` gespeichert (Laravel `encrypted` Cast)
- Nächste Verbindung: Token wird wiederhergestellt via `garth.loads()` — kein erneuter Login

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
| `POST /garmin-login` | Authentifizierung, gibt garth-Session zurück |
| `POST /send-to-garmin` | Workout hochladen + Kalender-Eintrag |
| `POST /generate-fit` | Binäre .fit-Datei generieren |
| `GET /health` | Health-Check |

## Bekannte Limitierungen

- MFA (Zwei-Faktor) wird nicht unterstützt → Fehler `mfa_required`
- Garmin API ist nicht öffentlich dokumentiert — inoffizielle Endpunkte
MD,
            ],

            [
                'slug'       => 'feature-strava',
                'category'   => 'features',
                'title'      => 'Strava Integration',
                'sort_order' => 3,
                'content'    => <<<MD
# Strava Integration

Strava ist die primäre Aktivitäts-Datenquelle für Zone3.

## OAuth-Flow

1. Nutzer klickt "Mit Strava verbinden"
2. Redirect zu Strava OAuth (`/strava/authorize`)
3. Strava redirectet zurück mit Code
4. Token wird in `strava_accounts` gespeichert
5. Aktivitäten werden importiert und in `activities` gespeichert

## Webhook (Echtzeit-Updates)

Strava sendet Push-Events bei neuen Aktivitäten:
- `POST /strava/webhook` empfängt Event
- Neue Aktivität wird importiert
- Plan wird auf `needs_plan_update = true` gesetzt
- `RegeneratePlanJob` wird nach 5 Minuten dispatcht (Batching mehrerer Aktivitäten)

## Aktivitäts-Matching

Nach Plan-Regenerierung werden abgeschlossene Strava-Runs automatisch mit geplanten Sessions verknüpft:
- Gleicher Tag → Session wird als `completed` markiert
- Distanz + Dauer werden aus Strava übernommen
- Ruhetage mit einem Lauf → Rest-Session wird gelöscht, neue `easy_run` Session erstellt

## Schwellenpace-Berechnung

`CalculateThresholdPaceJob` berechnet automatisch die Laktatschwelle aus den letzten Aktivitäten und speichert sie im `runner_profiles`.
MD,
            ],

            [
                'slug'       => 'feature-wellbeing',
                'category'   => 'features',
                'title'      => 'Wellbeing & Coach-Dashboard',
                'sort_order' => 4,
                'content'    => <<<MD
# Wellbeing & Coach-Dashboard

## Tägliches Wellbeing

Nutzer tracken täglich:
- **Energie** (1-10)
- **Schlafqualität** (1-10)
- **Muskelkater** (1-10)
- **Stress** (1-10)
- **Krank / Verletzt** (Boolean)

Wellbeing-Daten beeinflussen KI-Empfehlungen: schlechte Werte → leichteres Training.

## Dashboard Coach-Spruch

Der Satz oben im Dashboard ist eine KI-Motivation die täglich generiert wird:
- Gecacht in `runner_profiles.daily_message` (wird gelöscht wenn Plan neu berechnet wird)
- Berücksichtigt: Wellbeing, nächstes Event, letzte Aktivitäten, TSB-Wert

## Trainingsbelastung (CTL/ATL/TSB)

| Metrik | Bedeutung | Berechnung |
|--------|-----------|------------|
| CTL (Fitness) | 42-Tage EMA | Langzeit-Trainingsbelastung |
| ATL (Ermüdung) | 7-Tage EMA | Kurzzeit-Trainingsbelastung |
| TSB (Form) | CTL − ATL | Positiv = frisch, Negativ = müde |

## Push-Benachrichtigungen

- Web Push via VAPID-Schlüssel
- Wellbeing-Reminder täglich zur konfigurierten Zeit
- Plan-Update-Benachrichtigungen nach Neuberechnung
MD,
            ],

            // ══════════════════════════════════════
            //  API
            // ══════════════════════════════════════
            [
                'slug'       => 'api-openai',
                'category'   => 'api',
                'title'      => 'OpenAI Service',
                'sort_order' => 1,
                'content'    => <<<MD
# OpenAI Service (`app/Services/OpenAIService.php`)

Zentrale Klasse für alle KI-Aufrufe. Alle Calls werden in `ai_logs` protokolliert.

## Methoden

| Methode | Zweck | Call-Type |
|---------|-------|-----------|
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
                'content'    => <<<MD
# Webhooks

Zone3 empfängt Webhooks von externen Diensten. Diese Endpunkte sind **ohne Auth-Middleware** und von CSRF ausgenommen.

## Strava Webhook

| Methode | Pfad | Zweck |
|---------|------|-------|
| `GET` | `/strava/webhook` | Webhook-Verifikation (Strava Challenge) |
| `POST` | `/strava/webhook` | Neue Aktivität / Update |

**Ablauf:** Neue Aktivität → Import → Plan auf `needs_plan_update=true` → `RegeneratePlanJob` nach 5 Min

## GitHub Webhook (Wiki)

| Methode | Pfad | Zweck |
|---------|------|-------|
| `POST` | `/webhook/github` | Push-Events → Changelog-Eintrag |

**Sicherheit:** HMAC-SHA256 Signatur mit `GITHUB_WEBHOOK_SECRET`

**Setup in GitHub:**
1. Repository → Settings → Webhooks → Add webhook
2. Payload URL: `https://zone3.app/webhook/github`
3. Content type: `application/json`
4. Secret: Wert aus `.env GITHUB_WEBHOOK_SECRET`
5. Events: nur "Push events"

**Ablauf:** Push → Signatur prüfen → Commits extrahieren → OpenAI Summary → `wiki_changelogs` speichern
MD,
            ],

            [
                'slug'       => 'api-fit-service',
                'category'   => 'api',
                'title'      => 'FIT-Service (Python)',
                'sort_order' => 3,
                'content'    => <<<MD
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
| `rest` / `recovery` | Erholung | Pause zwischen Intervallen |
| `cooldown` | Auslaufen | Letzter Step |

## Garmin Session-Format

Die `garmin_session` ist ein JSON-String von `garth.dumps()`:
```json
{"oauth1_token": {...}, "oauth2_token": {...}}
```

Wird in Laravel AES-verschlüsselt in `users.garmin_session` gespeichert.
MD,
            ],

            // ══════════════════════════════════════
            //  DECISIONS
            // ══════════════════════════════════════
            [
                'slug'       => 'adr-garmin-token-storage',
                'category'   => 'decisions',
                'title'      => 'ADR: Garmin Token-Speicherung',
                'sort_order' => 1,
                'content'    => <<<MD
# ADR: Garmin Token-Speicherung statt Passwort

**Datum:** Mai 2026
**Status:** Implementiert

## Kontext

Nutzer mussten bei jeder Garmin-Connect-Aktion Email + Passwort neu eingeben. Das war inakzeptabel für UX.

## Entscheidung

Garmin-Session-Tokens (von `garth.dumps()`) werden verschlüsselt in der Datenbank gespeichert:

- `users.garmin_email` — E-Mail-Adresse (für Anzeige)
- `users.garmin_session` — garth-Session-Tokens (Laravel `encrypted` Cast → AES-256)

**Nie gespeichert:** Das Passwort selbst.

## Begründung

- garth-Tokens sind OAuth-ähnliche Langzeit-Tokens, kein Passwort
- Laravel `encrypted` Cast nutzt `APP_KEY` für AES-Verschlüsselung
- Bei abgelaufenem Token: `session_expired` Fehler → User wird aufgefordert, sich neu einzuloggen
- Trennen-Button löscht `garmin_session` und `garmin_email`

## Konsequenzen

- Nutzer muss sich nur einmal bei Garmin einloggen
- Bei `APP_KEY`-Änderung werden alle Sessions ungültig (Nutzer müssen neu einloggen)
- MFA-fähige Accounts können Zone3 nicht nutzen (Garmin MFA = kein Automatismus möglich)
MD,
            ],

            [
                'slug'       => 'adr-auto-coaching',
                'category'   => 'decisions',
                'title'      => 'ADR: Proaktives Auto-Coaching',
                'sort_order' => 2,
                'content'    => <<<MD
# ADR: Proaktives Auto-Coaching System

**Datum:** Mai 2026
**Status:** Implementiert

## Kontext

Der Coach war passiv — Nutzer mussten manuell "Plan aktualisieren" klicken. Das widerspricht der Idee eines echten Coaches.

## Entscheidung

Drei automatische Trigger für Plan-Neuberechnung:

### 1. Session überspringen/abschließen
`TrainingSessionController::skip()` und `complete()` rufen `triggerCoachReaction()` auf:
- `needs_plan_update = true` auf aktivem Plan
- `RegeneratePlanJob::dispatch($userId, userTriggered: true)` mit 10s Delay
- `userTriggered=true` bypasses das 6h-Debounce (sofortige Reaktion)
- `today_recommendation` + `daily_message` Cache wird geleert

### 2. Täglicher Gap-Check (05:00 Uhr)
`plan:auto-update` Artisan-Command:
- Prüft alle Pläne mit Rennen in den nächsten 21 Tagen
- Wenn geplante Sessions < verbleibende Tage → Neuberechnung
- 12h-Schutz gegen wiederholte Aufrufe

### 3. Nach Strava-Sync
Bestand schon vorher — Strava-Webhook setzt `needs_plan_update=true`.

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
                'content'    => <<<MD
# ADR: Vollständige Plan-Abdeckung bis Renntag

**Datum:** Mai 2026
**Status:** Implementiert

## Problem

Der Plan-Generator hatte ein hartes Limit von 10 Sessions. Bei Rennen in >10 Tagen fehlten die letzten Tage (insb. Tapering-Woche). Ruhetage wurden nicht explizit als Sessions eingetragen.

## Lösung

In `OpenAIService::generateEventTrainingPlan()`:

1. **Schleife erweitert:** `for ($i = 0; $i < 10; $i++)` → `for ($i = 0; $i < min(21, $daysUntil + 1); $i++)`
2. **Prompt geändert:** "zwischen 1 und 10 Objekte" → "GENAU {$totalDays} Einträge — einen pro Tag, Ruhetage als type='rest'"
3. **UI-Text:** "10-Tages-Trainingsplan" → "Trainingsplan bis zum Rennen"

## Ergebnis

Bei 11 Tagen bis zum Rennen: KI generiert exakt 11 Einträge (10 Trainingstage + Renntag), inklusive expliziter Ruhetage für die Tapering-Woche.
MD,
            ],
        ];
    }
}
