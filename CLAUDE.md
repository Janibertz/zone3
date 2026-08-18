# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Development (runs Laravel + queue + logs + Vite concurrently)
composer dev

# Build frontend
npm run build

# Run tests
composer test          # clears config cache, then PHPUnit
php artisan test --filter TestName   # single test

# Generate PWA icons (runs automatically on deploy)
php artisan pwa:icons
```

## Deployment

Deploy via **GitHub → Coolify** (push to `main` branch triggers deploy). No local dev server needed for production testing — `startup.sh` is the container entry point and handles migrations, seeding, cache, queue worker, scheduler, and PWA icon generation automatically.

The queue worker and scheduler both run as background loops inside the same container process (not systemd/supervisor). The PHP dev server (`php artisan serve`) is the web process.

## Architecture

**Zone3** is an AI-powered running training platform: Laravel 13 + Vue 3 + Inertia.js. Users connect Strava, set race goals, and get AI-generated training plans. OpenAI drives plan generation, threshold pace calculation, coach chat, and daily messaging.

### Key Data Flow

1. User creates an **Event** (race date + distance + target time)
2. System generates a **TrainingPlan** (OpenAI) → list of **TrainingSessions**
3. Strava activities are auto-imported via webhook → matched to planned sessions
4. Daily **Wellbeing** check-ins (sleep/stress/fatigue) can trigger plan adjustments
5. **Dashboard** shows today's session, race prediction, weekly AI review

### Models & Relationships

```
User → RunnerProfile (threshold_speed, pace_zones JSON, heart_rate_zones JSON)
     → Events → TrainingPlans → TrainingSessions
     → Activities (from Strava)
     → Coach (personality prompt for OpenAI)
     → WellbeingEntries
     → PushSubscriptions
```

`TrainingSession.status`: `planned` → `completed` / `skipped`

### OpenAI Integration (`app/Services/OpenAIService.php`)

**Two-model setup:**
- `OPENAI_MODEL` (default: `gpt-5.5-2026-04-23`) — complex calls: event_plan, threshold_pace, profile_estimation, coach_chat
- `OPENAI_MODEL_MINI` (default: `gpt-5.4-mini`) — all other 13 call types

**Critical quirks for gpt-5.5 (reasoning model):**
- `temperature` parameter is NOT supported — do not pass it
- Uses `max_completion_tokens` (not `max_tokens`) — internal reasoning tokens eat into the budget, so minimum 700 tokens even for short responses
- All calls are logged to `ai_logs` table via `AiLog` model

All OpenAI calls go through `callOpenAI(string $type, string $system, string $user, int $maxTokens, int $timeout = 30, ?string $model = null)`.

### Race Prediction (Jack Daniels T-Pace)

Used on both Dashboard and Plan page — must stay in sync:
- threshold_speed (min/km float) × multiplier × distance = total time
- Multipliers: 5k=0.90, 10k=0.95, half=1.03, marathon=1.12
- Linear interpolation for custom distances between anchors
- Source of truth: `TrainingPlanController::calcThresholdPrediction()`

### Threshold Pace Calculation

`CalculateThresholdPaceJob` → `OpenAIService::calculateThresholdPaceWithAI()`:
- Sends recent Strava runs with pace, HR, distance, duration
- AI returns `{"threshold_pace":"M:SS"}` JSON
- Stored as `RunnerProfile.threshold_speed` (float minutes/km, e.g. 5.5 = 5:30/km)
- While calculating: `threshold_pace_calculating = true` flag shown in UI

### Frontend (`resources/js/Pages/`)

Inertia.js — no API calls, all data passed as props from Laravel controllers. Key pages:
- `Dashboard.vue` — hub with race predictions, today's session, chart of threshold history
- `Events/Plan.vue` — training plan view with session list, completion tracking
- `Activities/Show.vue` — Leaflet map + pace zones per activity
- `Workouts/Builder.vue` — custom workout with warmup/interval/cooldown + Garmin FIT export
- `Onboarding.vue` — 5-step flow (coach → profile → availability → goal → complete)
- `Admin/` — user management, AI log, coach editor, settings

### Strava Webhook

`POST /strava/webhook` — no auth token, verified via `STRAVA_WEBHOOK_VERIFY_TOKEN` query param. Imports activity → matches to planned sessions retroactively → sends push notification.

### Garmin FIT Export

Uses `@garmin/fitsdk` (npm) on the frontend to generate binary FIT files. Speed targets stored as mm/s in FIT format. Download via `Content-Disposition: attachment`.

### Admin Panel

Routes in `routes/admin.php` — protected by `is_admin` flag on User. Includes:
- Manual threshold recalculation per user
- AI log viewer (all OpenAI calls with tokens + duration)
- Coach personality prompt editor
- Push notification test sender
- Settings page shows both models (Hauptmodell violet, Mini-Modell blue)

### Push Notifications

VAPID-based Web Push (no Firebase). Subscriptions in `push_subscriptions` table. Scheduler command `SendWellbeingReminders` runs every minute via polling loop. Expired subscriptions (HTTP 410) are auto-deleted.

## Environment Variables

```
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.5-2026-04-23
OPENAI_MODEL_MINI=gpt-5.4-mini

STRAVA_CLIENT_ID=
STRAVA_CLIENT_SECRET=
STRAVA_WEBHOOK_VERIFY_TOKEN=

# fit-service (Garmin). Der Token muss in BEIDEN Coolify-Diensten identisch
# gesetzt sein — der fit-service weist ohne ihn jeden Aufruf mit 401 ab und
# antwortet mit 503, solange er selbst keinen konfiguriert hat.
FIT_SERVICE_URL=
FIT_SERVICE_TOKEN=

VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:...
```
