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

**Tests never talk to the network.** `Tests\TestCase::setUp()` calls `Http::preventStrayRequests()`, and it is there for a reason: `QUEUE_CONNECTION` is `sync` under test, so every `dispatch` runs immediately — a test that imported an activity ran `GenerateSessionReviewJob`, which calls OpenAI with the live key. `Http::fake()` with a URL pattern does **not** catch that; anything not matching the pattern goes out for real. The only visible sign was two tests taking four seconds instead of twenty milliseconds. A test that needs a call fakes it explicitly.

## Local development

Laragon's MySQL is the local database. Import a Coolify dump into `zone3`, point `.env` at it, and **null the live tokens immediately** — a production dump carries working Strava and Garmin credentials:

```sql
UPDATE strava_accounts SET access_token='', refresh_token='';
UPDATE users SET garmin_session=NULL, password='', remember_token=NULL;
UPDATE push_subscriptions SET public_key='', auth_token='';
```

Local and production run the same schema; `php artisan migrate` after importing an older dump.

Develop and verify against the local copy, then push to `main`. Two things only real data can answer: `plan_revisions.corrections` (what the validator keeps having to fix) and `ai_logs.full_prompt` / `full_response` (what the model actually returns for a given prompt). Reading the prompt tells you what *should* go wrong; these tell you what does.

`.env` holds a live `OPENAI_API_KEY`. Any command that reaches a generator spends real money — mock `OpenAIClient` when you only need the prompt.

## Deployment

Deploy via **GitHub → Coolify** (push to `main` branch triggers deploy, ~2 minutes). No local dev server and no local database — `startup.sh` is the container entry point and handles migrations, seeding, cache, queue worker, scheduler, and PWA icon generation automatically.

**Two queue workers, and the split matters.** `default` carries everything that talks to OpenAI — plan generation (30-70 s, sometimes minutes), reviews, threshold pace, predictions — on a single worker with `--timeout=1800`. `imports` carries `ImportStravaActivityJob` and has a worker of its own.

That second worker is not tidiness, it is a bug fix. Moving the Strava import out of the request put it in line behind the AI jobs, where it had never been: the run finished, and the activity appeared minutes later or not at all. An import waits only for other imports now, and those are short. A new job that must not wait behind plan generation belongs on `imports` too — `ImportStravaActivityJob::QUEUE`. Note that `Queueable` already declares `$queue`, so redeclaring the property is a composition error; call `$this->onQueue(...)` in the constructor.

The queue workers and scheduler all run as background loops inside the same container process (not systemd/supervisor). The PHP dev server (`php artisan serve`) is the web process — **single-threaded by default**, so any slow synchronous request blocks the whole site. Heavy work (every OpenAI call) belongs in a queued job; the controller starts it and the frontend polls a status endpoint. `PHP_CLI_SERVER_WORKERS=8` in `startup.sh` is a safety net, not a substitute.

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

**A local copy of production makes this measurable.** Laragon's MySQL is already installed; import a Coolify dump into `zone3` and point `.env` at it. Null the live tokens right after import (`strava_accounts.access_token/refresh_token`, `users.garmin_session`, `push_subscriptions.*`) so nothing can hit the real accounts. Then `plan_revisions.corrections` and `ai_logs.full_prompt/full_response` answer what no amount of reading the prompt can: what the model actually does. Two bugs were found that way in minutes — the revision diff reporting untouched days as deleted, and the ladder prescribing a long run longer than the day it lands on.

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
| `REASON_WEEKLY` | bypassed | none | `plan:write-week`, Sunday 19:00 |
| `REASON_THRESHOLD` | applies | 3 days | threshold pace moved ≥ 1.5 % |
| `REASON_GAP` | applies | 3 days | `plan:auto-update` |
| `REASON_AUTO` | applies | 3 days | unplanned Strava activity |

**Freeze** (`FREEZE_DAYS = 3`) means sessions in that window survive the delete, get re-linked to the new plan, and are skipped when the new sessions are written. The athlete arranges their week around these days; an automatic run must not rewrite them. Anything the athlete asked for themselves freezes nothing.

**Completing a session dispatches nothing.** Neither does a Strava import that matches a planned session. What they add flows into the next scheduled regeneration through the context.

### The weekly rhythm

`plan:write-week` runs **Sunday 19:00** — an hour after `push:week-check` asks for availability. Whoever answered gets their week built on that answer; whoever didn't gets it from the profile grid. Then the week stands until the athlete changes something. `--force` runs it off-schedule; without it the command only fires on Sunday or Monday.

`REASON_WEEKLY` freezes nothing: this is the agreed moment at which the week *may* change, and a freeze would block exactly the days it exists to write. It stays cheap because partial regeneration skips the model entirely when nothing is stale, and its push says "Deine Woche steht" rather than "wurde neu berechnet".

### Partial regeneration

The skeleton is deterministic — it follows from availability, race distance, comeback step and the long-run ladder, none of which drift on their own. `PlanDeltaService::split()` compares it against the sessions already in the database and returns `keep` / `stale`:

- day carries the slot types the skeleton wants (optional second slots may be missing) → **keep**
- skeleton says rest and a rest sits there → **keep**
- long run more than 10 % off the ladder's target → **stale**
- day empty, wrong type, or something extra → **stale**

Frozen days always count as kept. **If `stale` is empty the job returns without calling the model at all** — previously it ran even when the answer had to be the same.

Otherwise kept days are marked `kept` in the skeleton (dropped from the day list, ignored by the validator's `dropUnknownDates` / `fillMissingDays` / slot restoration) and passed to the prompt as `keptSessions`: "steht bereits und bleibt unverändert — nicht zurückgeben". The model sees the whole week but only writes the open days, and only those get deleted and recreated.

### No day may end up empty

Twice a day in the plan held nothing at all — 31 Aug and 3 Sep. The app showed a hole mid-week. Both days were in the skeleton, and for both the revision history dutifully reported a change.

**The history could not catch it, because the history is written from the model's output and before the sessions are persisted.** `PlanRevisionRecorder::record()` diffs `sessionsBefore` against `$aiSessions`, and it is called above the creation loop — it describes the intention, not the result. It reported an easy run for a day that never got one. Treat "Verlauf" as what was asked for, not as what is in the database.

Several switches can drop a day, each defensible on its own: the model may omit it, the validator only fills a missing day when it is neither `finalized` nor `kept`, and the creation loop skips dates it considers taken. Rather than harden each switch, `RegeneratePlanJob::sealGaps()` looks at what actually landed: any skeleton day with **no session at all** (any plan, any status) gets a rest day and a `Log::warning`. A rest day is the honest filler — an invented workout would be worse than the admission that nothing is planned.

It queries by range with `whereDate`, not `whereIn` with date strings: under SQLite the latter matches nothing, and the guard would have papered the whole week with rest days. The test caught exactly that.

Worth knowing: when the model omits a day the **validator already restores the skeleton's slot with the right type** — `sealGaps` is the last line of defence for the `finalized` / `kept` paths it skips, not the normal route.

**A day can also be emptied outside a regeneration.** The reported case: the athlete skipped Wednesday for lack of time, the regeneration moved that session to Thursday, then he told the coach he could manage Wednesday after all — and the coach moved it back. `move_training_session` sets `planned_date` and saves; nobody looked at what stayed behind, and Thursday became a hole. `sealGaps()` cannot catch this, because no regeneration runs. `CoachChatService::sealVacatedDay()` closes the day where it is emptied — after a move and after a delete — and only when nothing else is left on it.

### The weekly volume drives the skeleton

This was the most consequential contradiction in the prompt. The skeleton filled days by **availability** and wrote "max. 120 min" per day; the model read that as an instruction. Two sections above, the volume block said, as binding: "der Wochenumfang darf 35,2 km NICHT überschreiten". For a real case that meant five sessions totalling 383 minutes — about 71 km — against a 35.2 km ceiling. No answer satisfies both. The model had to break one, and which one it broke it decided anew every time.

`WeeklyPatternService::applyVolumeBudget()` now runs after the long-run ladder:

- the long run's km come from the ladder and are subtracted first
- a fixed appointment consumes the time it actually takes (the athlete goes either way)
- what remains is shared across the other run slots, each with a **minimum** (`MIN_MINUTES_PER_TYPE`: 45 min for quality, 30 for easy)
- if the budget cannot carry them all, the least important is dropped (reverse `PRIORITIES`) — three sessions with substance beat five without
- a day that loses its only slot becomes `rest`, never an open day
- availability stays a **ceiling**, never a target

Each run slot then carries `target_km` / `target_min`, and the prompt says `— Ziel 8.1 km (~45 min)` next to `hoechstens max. 120 min`. The validator pulls a session back when it deviates more than 30 % (10 % for the long run, which comes from the ladder).

The ceiling grows by `WeeklyVolumeService::MAX_PROGRESSION_PCT` per week across the window; flat would starve week two, whose long run is longer.

### One verdict on the goal, not two

`TrainingPaceService` judged the goal from threshold pace ("Das Ziel passt zur heutigen Form"); `LongRunPlanService` judged it from the ladder ("Mit dieser Vorbereitung ist die Zielzeit unwahrscheinlich"). Both landed in the same prompt as fact, with no precedence, and the model picked one at random for the description.

`TrainingPaceService::combinedVerdict()` is now the only place that answers it, and it takes the ladder into account: speed and endurance are two preconditions, and a ladder that cannot reach the required peak overrides a flattering pace comparison. `LongRunPlanService` states the km shortfall as a finding and says nothing about the goal.

**The weekly overview is derived from the days**, not from `planWeek`'s return — that runs before the budget drops slots, so the overview used to name sessions the day list no longer had.

### Rest days are binding

Free days used to be written into the prompt as `type="rest" ODER lockere Einheit` — the model decided, differently on every run. `WeeklyPatternService::assignFreeDays()` decides now: the day after a hard session is rest, every week has at least one, everything else becomes an easy run. The validator enforces it. A test asserts that building the skeleton twice yields the same thing.

### Sports are separated on two fields

Everything that was neither a run nor strength used to be stored as `type = 'easy_run'`. The coach reviewed a swim as a run, a 1.5 km swim polluted the pace baseline and the weekly running volume, and — worst — the load calculation rated every activity by *running pace*.

Separation now hangs on **two** fields, deliberately:

| Field | Meaning |
|---|---|
| `type` | what kind of training — `easy_run`, `interval`, `strength`, **`cross_training`** |
| `sport_type` | what it was done with — Strava's sport, `NULL` = running |

`isRun()` requires both to agree (`RUN_TYPES` ∧ `RUN_SPORTS`), so a legacy row with `easy_run` + `Ride` still fails the check. `runsOnly()` is the query scope. `sportLabel()` gives the German name; controllers expose it as `sport_label` (null for runs).

A cross-training session still gets a review — it just gets the right one: the sport is named, running pace and zones are off limits, swim distance is reported in metres, and the framing is "Alternativtraining, KEINE Abweichung vom Laufplan" rather than "ungeplant".

**Reviews are written once and then stand.** A migration repairs the session, never the text that was already written about it. `review:rewrite` clears `coach_review`/`reviewed_at` and re-dispatches the job (`--user`, `--session`, `--cross`, `--days`, `--yes`) — it costs one model call per session and posts a fresh chat message, so it is deliberately manual.

**`TrainingLoadService` computes TSS per sport.** Pace-based rTSS is running-only. A bike moves faster than a human runs, so the intensity factor pinned at its 1.5 ceiling: a 25-minute ride scored 94 TSS against 64 for a 10 km run, and 40 minutes of swimming scored 6. CTL/ATL/TSB — and with them the "Form" line in the plan prompt — were wrong for anyone who cross-trains. Other sports use heart rate (comparable across sports; speed is not), falling back to duration.

### Pinned sessions

Both plan jobs delete `status = 'planned'` sessions before writing the new plan. Sessions the athlete set through the coach chat carry `pinned_at` and are **excluded from that cleanup**, then re-linked to the new plan (the plan page loads only sessions of the active plan — without re-linking they would silently vanish). Their day counts as taken for the skeleton, and the prompt names them so the model plans *around* them instead of next to them.

### Return to run

`ReturnToRunService` is the single source for "is this athlete coming back from something". `statusFor()` feeds the dashboard card, `forPlan()` feeds both the skeleton and the plan prompt. Before that they had separate detections and contradicted each other inside the same prompt — the skeleton demanded a tempo run on the day the safety rule demanded 30 easy minutes, and the model satisfied both by planning two runs.

### Pace formatting

**`App\Services\PaceFormat` is the only place that turns a number into `M:SS`.** It used to exist in twenty-three copies across controllers, jobs and services, and they did not agree: most truncated, the coach review rounded. The same run read 5:59 on one page and 6:00 on another.

| Method | Input | Rounding |
|---|---|---|
| `fromSeconds()` | seconds per km | round — the canonical one |
| `fromSpeed()` | m/s (Strava) | round |
| `fromMinutes()` | decimal minutes (`threshold_speed`, 5.5 = 5:30) | round |
| `target()` | seconds per km of a **goal** | **floor** |
| `hms()` | seconds | — |

`target()` is not cosmetic. 3:30 over 42.195 km is 298.6 s/km: rounded that reads 4:59, and running it lands on 3:30:22 — the goal missed. Floored it reads 4:58 → 3:29:36. A measured pace describes, a target pace instructs.

Always round once on whole seconds, then split. Truncating the minute while rounding the second produced "5:00" for a six-minute pace.

Total times (`3:26:21`, `1:35 Std`) stay out of this class — different quantity, different presentation.

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

### Deleting an activity

`DELETE /activities/{activity}` → `ActivityDeletionService`. A bare `$activity->delete()` is not enough:

- `training_sessions.activity_id` is `nullOnDelete`, so a session the import completed would stay on `completed` with the run's numbers but no evidence behind them. A session the import *created* (`was_unplanned`) is deleted with it; a **planned** session is restored from `planned_snapshot` and goes back to `planned`, review and rating cleared. Without a snapshot the numbers stay — inventing them would be worse.
- `race_analysis_text` pointing at it is cleared; `best_efforts` cascade away.
- **A tombstone is required.** The manual sync runs `Activity::updateOrCreate` over Strava's recent activities and would re-create the deleted one on the next run. `ignored_strava_activities` holds (user_id, strava_id); both the sync and the webhook check it. The webhook alone would not have brought it back — it only handles `aspect_type = create` — but the sync would.

## Strava Webhook

`POST /strava/webhook` → `ImportStravaActivityJob`. The handler takes the event and hands it on; that is all it does in the request.

It used to do the whole import inline: fetch the activity from Strava (two HTTP calls when the token had expired), store it, match it to the planned session, write best efforts, dispatch reviews, send a push — HTTP again. The web process is single-threaded, so the site stood still for the duration. And Strava **redelivers an event when the response is slow**, so the slowness fed itself.

The job runs on the **`imports` queue**, which has its own worker — see Deployment. On the shared `default` queue it sat behind plan generation and arrived minutes late or never.

The job does the work: `StravaImportService` (the import and matching logic, shared with the manual sync) → best efforts → `GenerateSessionReviewJob` per completed session → push. It retries three times — Strava sometimes sends the event before the activity is served by the API, and a silent `return` would lose the run — and `ShouldBeUnique` keeps a redelivery from sending a second push for the same activity. Everything in it is repeatable: `updateOrCreate` on (strava_id, user_id), the `activity_id` check, `reviewed_at`.

**Strava does not sign its webhooks.** There is no signature to verify, unlike GitHub — an earlier note in this file claiming the POST checks `STRAVA_WEBHOOK_VERIFY_TOKEN` was wrong; that token only ever guarded the GET handshake. The protection is elsewhere:

- only `owner_id` and `object_id` are taken from the body, and the activity is then **fetched from the API** with the account's token — a forged call cannot inject invented data
- `throttle:60,1` on the route: the endpoint triggers work, and Strava's own quota is 200 calls per 15 minutes, so an unthrottled flood would exhaust it and break the real import
- optionally a secret in the URL. `callbackTokenMatches()` reads `token=…` out of `services.strava.webhook_callback_url` — Strava calls exactly the URL you registered, query string included. No token registered (today's state) means nothing is demanded; append `?token=…` to `STRAVA_WEBHOOK_CALLBACK_URL` and re-run `strava:subscribe-webhook`, and both POST and handshake start enforcing it. Both sides read the same URL, so this cannot lock you out.

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
