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

Deploy via **GitHub → Coolify** (push to `main` branch triggers deploy, ~2 minutes). No local dev server and no local database — `startup.sh` is the container entry point and handles migrations, seeding, cache, queue worker, scheduler, and PWA icon generation automatically.

The queue worker and scheduler both run as background loops inside the same container process (not systemd/supervisor). The PHP dev server (`php artisan serve`) is the web process — **single-threaded by default**, so any slow synchronous request blocks the whole site. Heavy work (every OpenAI call) belongs in a queued job; the controller starts it and the frontend polls a status endpoint. `PHP_CLI_SERVER_WORKERS=8` in `startup.sh` is a safety net, not a substitute.

## Architecture

**Zone3** is an AI-powered endurance training platform: Laravel 13 + Vue 3 + Inertia.js. Users connect Strava, set race goals, and get AI-generated training plans. OpenAI drives plan generation, threshold pace calculation, coach chat, and daily messaging.

Strava delivers **all** activity types. Counting and display are multi-sport (fixed filter: Alle · Laufen · Rad · Schwimmen); **plan generation is running-only** — threshold pace, weekly volume and the long-run ladder all filter `type = 'Run'`. The stated direction is triathlon / backyard / running, but nothing in the planner supports swim or bike training yet.

**Mobile first.** Zone3 is used as a PWA from the iPhone home screen; desktop is explicitly secondary. Anything pinned to a screen edge needs the safe-area utilities in `resources/css/app.css` (`pt-safe-banner`, `pt-mobile-header`, `pb-safe-tabbar`, `pb-safe-sheet`). In standalone mode there is no browser chrome — `top-0` really does sit under the Dynamic Island.

### Key Data Flow

1. User creates an **Event** (race date + distance + target time)
2. `GenerateEventTrainingPlanJob` builds a **TrainingPlan** → list of **TrainingSessions**
3. Strava activities are auto-imported via webhook → matched to planned sessions
4. Daily **Wellbeing** check-ins trigger `AdjustPlanForWellbeingJob` for today's session
5. **Dashboard** shows today's session, race prediction, weekly AI review

### Models & Relationships

```
User → RunnerProfile (threshold_speed, pace_zones JSON, heart_rate_zones JSON, coach_notes)
     → Events → TrainingPlans → TrainingSessions
     → Activities (from Strava) → BestEfforts
     → Coach (personality prompt for OpenAI)
     → WellbeingEntries · GarminDailyMetrics
     → PlanRevisions · PushSubscriptions
```

`TrainingSession.status`: `planned` → `completed` / `skipped`
`TrainingSession.pinned_at`: set by the athlete via coach chat — survives regeneration (see below).

Match sessions by date with `whereDate('planned_date', …)`, never `where(...)`. MySQL stores a bare `2026-08-27` for a `date` column, SQLite (the test connection) stores `2026-08-27 00:00:00` — an equality comparison silently matches nothing under the test suite while working in production.

## The plan pipeline

This is the core of the app and the part with the most hard-won rules. Do not treat it as one AI call.

```
PlanContextBuilder            gathers everything, in one place for both jobs
  ├─ ReturnToRunService::forPlan()   comeback step after illness/injury/exhaustion
  ├─ TrainingPaceService             pace table + target race pace + verdict vs. current form
  ├─ WeeklyVolumeService             6 weeks of volume + binding ceiling (+10 %/week)
  ├─ LongRunPlanService              the long-run ladder, computed backwards from race day
  ├─ GarminHealthSummary             7-day averages vs. 60-day baseline
  └─ WeeklyPatternService::build()   the weekly skeleton — which slot on which day
        ↓
TrainingPlanGenerator          fills the skeleton in; the skeleton itself is binding
        ↓
TrainingPlanValidator          enforces the rules against whatever the model returned
        ↓
PlanRevisionRecorder           stores diff + corrections → visible in the plan page's "Verlauf"
```

**Rules the validator enforces** (a model that violates them gets corrected, and the correction is recorded):

- **One running session per day.** A second run is removed, not downgraded. Strength/core/mobility may sit next to a run. This rule also holds in the coach chat.
- The skeleton's `type` per day is binding; a missing slot is restored, a wrong run type is *replaced* (never appended — that produced the second run).
- Per-slot duration caps (a comeback session capped at 30 min beats the day's 120-minute budget).
- The long run's distance comes from the ladder, with a 10 % tolerance for round numbers.
- Days outside the window, past the race, or already finalized are dropped.

**Where corrections show up:** `plan_revisions.corrections`, rendered in the plan page's "Verlauf" — the fastest way to judge whether prompt changes landed. `Log::info('Plan validator corrected the AI output')` carries the same list.

### When the plan may change (and when it may not)

A regeneration deletes every `planned` session and has the model invent them again. The model is not deterministic, so **every regeneration is a fresh roll of the dice** — rest days vanished, a threshold run became twenty easy minutes. Two of the seven triggers were "the athlete did what the plan said", the worst possible reason to redraw a plan.

`RegeneratePlanJob` now takes a **reason**, and the reason decides two things: whether the 6-hour debounce applies, and how far into the near future the run may reach.

| Reason | Debounce | Freeze | Dispatched from |
|---|---|---|---|
| `REASON_MANUAL` | bypassed | none | plan page button |
| `REASON_SKIP` | bypassed | none | session skipped |
| `REASON_AVAILABILITY` | bypassed | none | weekly availability answered |
| `REASON_WELLBEING` | bypassed | none | illness / exhaustion |
| `REASON_THRESHOLD` | applies | 3 days | threshold pace moved ≥ 1.5 % |
| `REASON_GAP` | applies | 3 days | `plan:auto-update` |
| `REASON_AUTO` | applies | 3 days | unplanned Strava activity |

**Freeze** (`FREEZE_DAYS = 3`) means sessions in that window survive the delete, get re-linked to the new plan, and are skipped when the new sessions are written. The athlete arranges their week around these days; an automatic run must not rewrite them. Anything the athlete asked for themselves freezes nothing.

**Completing a session dispatches nothing.** Neither does a Strava import that matches a planned session. What they add flows into the next scheduled regeneration through the context.

### Rest days are binding

Free days used to be written into the prompt as `type="rest" ODER lockere Einheit` — the model decided, differently on every run. `WeeklyPatternService::assignFreeDays()` decides now: the day after a hard session is rest, every week has at least one, everything else becomes an easy run. The validator enforces it. A test asserts that building the skeleton twice yields the same thing.

### Sport type

`training_sessions.sport_type` carries the Strava sport (`NULL` = running). Before that, everything that was neither a run nor strength was stored as `easy_run`: the coach reviewed a swim as a run, and a 1.5 km swim polluted the pace baseline and the weekly running volume. `isRun()` gates every pace/volume comparison; `sportLabel()` gives the German name.

### Pinned sessions

Both plan jobs delete `status = 'planned'` sessions before writing the new plan. Sessions the athlete set through the coach chat carry `pinned_at` and are **excluded from that cleanup**, then re-linked to the new plan (the plan page loads only sessions of the active plan — without re-linking they would silently vanish). Their day counts as taken for the skeleton, and the prompt names them so the model plans *around* them instead of next to them.

### Return to run

`ReturnToRunService` is the single source for "is this athlete coming back from something". `statusFor()` feeds the dashboard card, `forPlan()` feeds both the skeleton and the plan prompt. Before that they had separate detections and contradicted each other inside the same prompt — the skeleton demanded a tempo run on the day the safety rule demanded 30 easy minutes, and the model satisfied both by planning two runs.

### Race Prediction (Jack Daniels T-Pace)

**Source of truth: `RacePredictionService`.** Used by the dashboard route, `TrainingPlanController` and `GenerateRacePredictionJob` — the formula previously existed three times and the numbers drifted apart.

- threshold_speed (min/km float) × multiplier × distance = total time
- Multipliers: 5k = 0.90, 10k = 0.95, half = 1.03, marathon = 1.12
- Linear interpolation for custom distances between anchors

### Threshold Pace Calculation

`CalculateThresholdPaceJob` → `AthleteProfileService::calculateThresholdPaceWithAI()`:
- Sends recent Strava runs with pace, HR, distance, duration
- AI returns `{"threshold_pace":"M:SS"}` JSON
- Stored as `RunnerProfile.threshold_speed` (float minutes/km, e.g. 5.5 = 5:30/km)
- While calculating: `threshold_pace_calculating = true` flag shown in UI

## OpenAI Integration (`app/Services/AI/`)

`OpenAIService` no longer exists — it was 2786 lines of transport plus twenty-two prompt builders. Now:

| Class | Responsibility |
|---|---|
| `OpenAIClient` | transport only: model choice, timeout, `ai_logs` entry, error handling |
| `TrainingPlanGenerator` | `event_plan` — the big prompt, standard and backyard variants |
| `CoachChatService` | chat + the tools the coach may use on the plan |
| `SessionContentService` | session steps, nutrition tips, wellbeing adjustment |
| `CoachingTextService` | daily message, reviews, race texts |
| `AthleteProfileService` | threshold pace, pace zones, profile estimation |
| `TalksToOpenAI` | trait that injects the client |

**Two-model setup:**
- `OPENAI_MODEL` (default `gpt-5.5-2026-04-23`) — `$ai->main()`: event_plan, threshold_pace, profile_estimation, coach_chat
- `OPENAI_MODEL_MINI` (default `gpt-5.4-mini`) — `$ai->mini()`: everything short

**Critical quirks for gpt-5.5 (reasoning model):**
- `temperature` is NOT supported — do not pass it
- Uses `max_completion_tokens`; internal reasoning tokens eat into the budget, so use at least 700 even for short answers, and **≥ 16000 for a full plan** (too low returns an empty completion with `finish_reason: length`)
- All calls are logged to `ai_logs` (21 call types; `AiLog::$call_type_label` gives the German name, unlabelled types fall through to the raw key)

Calls go through `$this->ai->chat(string $callType, array $messages, float $temperature, int $maxTokens, int $timeout = 30, ?string $model = null)`, or `chatWithTools()` for the coach chat. `systemPrompt()` prepends the coaching philosophy and the selected coach's personality.

### Coach chat tools

The coach can really change the plan — `create_training_session`, `modify_training_session` (any day, not just today), `move_training_session`, `delete_training_session`, plus `skip_training_sessions`, `update_event_target`, `remember_user_fact`. Everything it writes gets `pinned_at`.

Two rules learned the hard way and encoded in the system prompt: **never claim a change without calling the tool** (with only a today-tool available, the model would describe the change instead of making it), and **one running session per day** also applies here. `applySessionFields()` reconciles distance/duration/pace so the numbers can't contradict the title — a model that changes the type without supplying new numbers would otherwise leave the old ones standing.

## Frontend (`resources/js/Pages/`)

Inertia.js — no API calls, all data passed as props from Laravel controllers.

- `Dashboard.vue` — hub with metrics, today's session, week block (sport filter), form, threshold history, calendar
- `Events/Plan.vue` — plan view, session list, revisions ("Verlauf"), race strategy/analysis
- `Calendar.vue` — month grid; on mobile the grid shows only markers and the day's content sits below it
- `Profile/Edit.vue` — settings list on mobile, tab bar from `lg`
- `Activities/Show.vue` — Leaflet map + pace zones per activity
- `Workouts/Builder.vue` — custom workout + Garmin FIT export
- `Onboarding.vue` — 5-step flow (coach → profile → availability → goal → complete)
- `Admin/` — user management, AI log, coach editor, settings

**Shared composables** — use these instead of copying tables around:
- `useActivityTypes.js` — Strava type → label/emoji/group/pill, plus `SPORT_FILTERS`. Mirrored in `StatisticsController::SPORT_TYPES`; the two must agree.
- `useSessionTypes.js` — session type → label/colour, and `paceWithUnit()` (the model writes "min/km" into `pace_target` itself; appending the unit produced "5:08–5:43 min/km /km")

## Strava Webhook

`POST /strava/webhook` — no auth token, verified via `STRAVA_WEBHOOK_VERIFY_TOKEN` query param. Imports activity → matches to planned sessions retroactively → records best efforts → sends push notification.

## Garmin

Two separate connection paths — do not confuse them:
1. **Profile → Verbindungen → Garmin** calls `/profile/garmin-connect` → fit-service `/garmin-login`. Login only, stores the garth token (the password is never stored), triggers a 60-day health backfill.
2. **Dashboard → "Zu Garmin Connect senden"** sends an actual workout to the watch and sets the token as a side effect on first use.

`SyncGarminHealthJob` / `garmin:sync-health` (daily 06:00) reads HRV, sleep, resting HR, body battery, stress, steps and training readiness into `garmin_daily_metrics` (all nullable — a missing value is "no data", never 0). `GarminHealthSummary::forUser()` gives 7-day averages against a 60-day baseline for the plan; `forDay()` gives today's values for the daily adjustment, because a weekly average is too slow to answer "what do I run today".

**FIT export** uses `@garmin/fitsdk` (npm) on the frontend; speed targets are stored as mm/s.

## Admin Panel

Routes in `routes/admin.php` — protected by `is_admin` on User. Manual threshold recalculation, AI log viewer (all OpenAI calls with tokens + duration), coach personality editor, push test sender, settings page showing both models.

## Push Notifications

VAPID-based Web Push (no Firebase). Subscriptions in `push_subscriptions`. Scheduler command `SendWellbeingReminders` runs every minute via polling loop. Expired subscriptions (HTTP 410) are auto-deleted.

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
