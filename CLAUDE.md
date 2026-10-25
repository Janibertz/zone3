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

**Ein Test mit festen Daten braucht eine feste Gegenwart.** `LongRunLadderTest` rechnete mit `RACE_DAY = 2026-09-27` und reichte `TODAY` an den Dienst durch — die Prüfung „liegt das Rennen in der Zukunft" fragte aber weiter die echte Uhr. Am 02.10.2026 war der Renntag vorbei, `LongRunPlanService::forEvent()` lieferte `null`, und **elf Tests fielen auf einmal um, ohne dass jemand Code angefasst hatte**. `Carbon::setTestNow()` in `setUp()`, zurückgesetzt in `tearDown()`. Wer feste Daten verwendet und die Uhr laufen lässt, schreibt eine Zeitbombe mit Datumsstempel.

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

**One queue worker**, consuming `default,imports`. Everything that talks to OpenAI runs there — plan generation (30-70 s, sometimes minutes), reviews, threshold pace, predictions — with `--timeout=1800`. `imports` is in the list only to drain anything left over from the reverted webhook rework; nothing writes to it any more.

The queue worker and scheduler both run as background loops inside the same container process (not systemd/supervisor). The PHP dev server (`php artisan serve`) is the web process — **single-threaded by default**, so any slow synchronous request blocks the whole site. Heavy work (every OpenAI call) belongs in a queued job; the controller starts it and the frontend polls a status endpoint. `PHP_CLI_SERVER_WORKERS=8` in `startup.sh` is a safety net, not a substitute.

## Architecture

**Zone3** is an AI-powered endurance training platform: Laravel 13 + Vue 3 + Inertia.js. Users connect Strava, set race goals, and get AI-generated training plans. OpenAI drives plan generation, threshold pace calculation, coach chat, and daily messaging.

Strava delivers **all** activity types. Counting and display are multi-sport (fixed filter: Alle · Laufen · Rad · Schwimmen); **plan generation is running-only** — threshold pace, weekly volume and the long-run ladder all filter `type = 'Run'`. The stated direction is triathlon / backyard / running, but nothing in the planner supports swim or bike training yet.

**Mobile first.** Zone3 is used as a PWA from the iPhone home screen; desktop is explicitly secondary. Anything pinned to a screen edge needs the safe-area utilities in `resources/css/app.css` (`pt-safe-banner`, `pt-mobile-header`, `pb-safe-tabbar`, `pb-safe-sheet`). In standalone mode there is no browser chrome — `top-0` really does sit under the Dynamic Island.

### Die Plattformschicht — was eine Webseite von einer App unterscheidet

Am 09.10.2026 gegen Emil Kowalskis `mobile-native`-Regeln geprüft (`.agents/skills/`). Safe areas, `-webkit-tap-highlight-color`, `touch-action: manipulation` und 16px-Felder waren schon richtig. Fünf Lücken waren es nicht:

- **`future.hoverOnlyWhenSupported: true`** in `tailwind.config.js`. Berührung kennt kein Hover, also fälscht der Browser eines: der erste Tipp setzt `:hover` und **lässt es stehen**, bis woanders getippt wird — ein Knopf mit `hover:scale-105` bleibt nach dem Antippen vergrößert. Zone3 hatte **44 Hover-Utilities** (346 Verwendungen in 60 Dateien), alle ungegatet. Eine Zeile legt sie hinter `@media (hover:hover) and (pointer:fine)`. Tailwind setzt beide Bedingungen; `(pointer: fine)` schließt Stylus-Geräte aus, die Hover behaupten. Druckfeedback läuft unabhängig über `:active`.
- **Zoom ist wieder erlaubt.** Hier stand `maximum-scale=1, user-scalable=no` mit der Begründung „Zone3 ist eine App" plus dem Zusatz, iOS ignoriere es ohnehin. Der Zusatz stimmt — iOS Safari missachtet beides seit iOS 10 absichtlich, damit Pinch-Zoom als Bedienhilfe bleibt. Android Chrome hält sich daran. Die Zeile hatte also auf der Hauptplattform keine Wirkung und auf der anderen nur die, jemandem das Vergrößern zu verbieten. Die Ursache des Zoom-Sprungs ist unabhängig behoben (16px unter 640px).
- **`theme-color` war die Markenfarbe.** `#6366f1` Indigo, während die Seite `#f9fafb`/`#09090e` ist. `useDarkMode.js::apply()` korrigierte es — aber erst wenn Vue läuft, und beim Start vom Homescreen ist genau der erste Frame der, in dem man die Statusleiste ansieht. Das Theme-Skript steht jetzt **hinter** dem Meta-Tag und setzt es synchron mit der `dark`-Klasse. Drei Stellen müssen dieselben Farben benutzen: Blade, Skript, `useDarkMode.js`.
- **`user-select: none` + `-webkit-touch-callout: none` auf Bedienelementen.** Langdruck markierte die Beschriftung eines Knopfs; bei Links kam das Teilen-Menü. Nur auf Bedienelemente — Paces, Fehlermeldungen und Adressen muss man kopieren können, `user-select: none` auf `body` wäre ein Defekt.
- **`-webkit-text-size-adjust: 100%`** gegen Schriftvergrößerung im Querformat, und **`overscroll-behavior-y` auch auf `html`** (auf iOS kommt der Überzug vom Wurzelelement, nicht von `body`).

**Zwei Dinge bewusst NICHT angefasst:**

- **`overscroll-behavior` bleibt auf der y-Achse.** Die Regel legt `overscroll-behavior: none` nahe; auf x genommen verschwindet unter Android Chrome die Zurück-Wischgeste. Das Symptom betrifft Pull-to-Refresh und den senkrechten Überzug — dort gehört die Sperre hin, nicht weiter.
- **Die 19 × `min-h-screen` (= `100vh`) bleiben.** Die Regel sagt `100dvh` für eine App-Hülle, und das gilt, wenn unten etwas angeheftet ist. Zone3s Tabbar ist `fixed inset-x-0 bottom-0` mit `pb-safe-tabbar` — sie sitzt am Viewport, nicht an der Hülle, also kostet `100vh` hier höchstens etwas Scrollhöhe. 19 Stellen ohne Gerät zu ändern wäre Churn ohne nachweisbaren Gewinn.

**Was ein Gerät braucht:** Keine dieser Korrekturen lässt sich in Chromes Geräteemulation nachweisen — klebendes Hover, der Tap-Highlight, der Zoom-Sprung, Overscroll und die Statusleiste sind Verhalten echter Hardware. Verifiziert ist, dass die Deklarationen im Build und im gerenderten `<head>` ankommen. Ob das klebende Hover weg ist, sagt dir erst das iPhone.

### Optik — Apple-nativ (seit 15.10.2026)

Die enduco-Richtung (weiße Karten, Indigo, Ambient-Wash) ist nach zwei Monaten Benutzung durchgefallen: *wirkt nicht wie eine App, zu blass, die Zahlen gehen unter, jede Seite sieht anders aus*. Neue Richtung, vorher als Mockup abgenommen: **iOS Health/Fitness**.

- **Farben sind die iOS-Systemfarben** in `resources/css/app.css` (`--z-*`), wo sie Kontrast halten, sonst deren dunklere Varianten (Blau `#0071E3` statt `#007AFF`, weil Weiß auf `#007AFF` nur 4,0:1 hat). Grund `#F2F2F7` / `#000000`, Karten `#FFF` / `#1C1C1E`, **kein Schatten, kein Rahmen**. `form` (pink) und `fitness` (lila) sind Kennzahl-Farben wie in Health: die Farbe sitzt auf dem **Label**, die Zahl bleibt schwarz.
- **Systemschrift**, Figtree ist raus. Kennzahlen in `font-rounded` (SF Pro Rounded), groß, Einheit klein und grau daneben.
- **Typo-Skala nach iOS:** Large Title 34/41 für Hauptseiten (`PageHeader`, Datum als `eyebrow` darüber), Title 1 28 für Detailseiten, Abschnitte 22 fett (`SectionHeader`), Fließtext 17/15, Fußnoten 13. Keine gesperrten 10px-Versalien als Labels.
- **Aktionen neben Überschriften** sind `AppButton variant="plain"` — Akzent-Text, keine graue Pille. `primary` ist Akzent gefüllt, nicht mehr schwarz.
- **Leisten** oben und unten sind Material (`bg-*/80 backdrop-blur-xl`). Tabs: Heute · Plan · Kalender · Aktivitäten · Mehr. Profil ist der Avatar oben rechts, Abmelden und „Hilfe & Feedback" stehen im Mehr-Sheet — der schwebende Hilfe-Knopf ist weg (Android-Muster, lag auf Text).
- `theme-color` steht weiterhin an drei Stellen (Blade-Meta, Blade-Skript, `useDarkMode.js`) plus `public/manifest.json` — alle vier auf `#f2f2f7` / `#000000`.

### Bewegung — drei Kurven, und wann welche gilt

Am 09.10.2026 gegen `emil-design-eng` geprüft. Hier geht es um die Schicht über dem visuellen System.

Die eingebauten CSS-Kurven sind zu weich — ihnen fehlt der Anschub, der eine Bewegung absichtlich wirken lässt. In `tailwind.config.js` stehen jetzt drei, und die Wahl ist eine Entscheidungsfrage, keine Geschmacksfrage:

| Kurve | Klasse | Wann |
|---|---|---|
| `cubic-bezier(0.32, 0.72, 0, 1)` | `ease-sheet` | Sheets und Panels, die aus dem Bildrand kommen (iOS-Drawer-Kurve) |
| `cubic-bezier(0.23, 1, 0.32, 1)` | `ease-out-strong` | Alles, was **auftritt oder verschwindet** — und alles, womit das System auf eine Eingabe **antwortet** (Zurückschnappen nach einem Wischer) |
| `cubic-bezier(0.77, 0, 0.175, 1)` | `ease-in-out-strong` | Elemente, die sich **auf** dem Schirm bewegen oder umformen (aufklappende Chevrons) |

**`ease-in` gehört nie in eine UI-Animation**, und das war der häufigste Fehler: 8 Austritts-Übergänge (AppSheet, Dropdown, CoachSlideOver, beide Layouts, Live/Show) liefen darauf. `ease-in` verzögert den Anfang — genau den Augenblick, auf den gesehen wird — und lässt dieselben 200 ms langsamer wirken. Austritte sind jetzt `ease-out-strong`, Eintritte ebenfalls (die eingebauten `ease-out` waren zu weich).

**47 × `transition-all` ersetzt**, und zwar in zwei Sorten:

- **9 Balken und Säulen**, bei denen die Größe *der Zweck* ist, bekommen genau ihre Eigenschaft: `transition-[width]`, `transition-[height,opacity]`, `transition-[transform,opacity]`. Hier ist das Animieren von `width`/`height` richtig, nicht versehentlich.
- **38 Knöpfe, Navigationseinträge und Vue-Transitions** bekommen das blanke `transition`. Tailwinds blankes `transition` deckt genau die Menge ab, die gebraucht wird — Farbe, Hintergrund, Rahmen, `opacity`, `box-shadow`, `transform`, `filter` — und lässt Layout-Eigenschaften **außen**. Das ist der lesbare Weg zu „keine Layout-Animation" ohne eine Klammerliste pro Knopf.

**`prefers-reduced-motion` war gar nicht behandelt.** Jetzt ja, und zwar nach der Regel „weniger und sanfter, nicht keine": `transition-property` wird auf Deckkraft, Farbe und Schatten zurückgeschnitten, Keyframes werden **einzeln** entschieden (`animate-spin` 36× bleibt — ein Ladeanzeiger muss sich drehen, sonst sieht die App eingefroren aus; `animate-pulse` arbeitet über Deckkraft; `animate-bounce` ist Dekoration und geht).

**Der naheliegende Fehler, den du hier nicht machen darfst:** `transform: none !important` im Reduced-Motion-Block. `AppSheet` versteckt sich mit `translate-y-full`, Zentrierungen laufen über `-translate-x-1/2` — das Sheet wäre dauerhaft sichtbar und mittige Elemente verrutschen. Richtig ist, `transform` nur aus der *Transition* zu nehmen: dann springt das Element auf seinen Endwert, kein Weg, keine Bewegung, aber der Zustand stimmt.

**Druckfeedback**, zwei Lücken geschlossen: der Zurück-Link in `PageHeader` und „Trennen" in `GarminSendSheet` hatten nur `hover:` — und Hover liegt seit demselben Tag hinter `@media (hover: hover)`, auf dem iPhone blieb dort also **gar nichts** übrig. Wer keine Rückmeldung bekommt, tippt ein zweites Mal.

**Nicht gemacht und warum:** kein Stagger auf den Dashboard-Listen. Die Regel knüpft Animation an die Häufigkeit — was man zehnmal am Tag sieht, wird reduziert, nicht verziert. Das Dashboard ist der Einstieg und wird am häufigsten geöffnet; eine Kaskade würde es langsamer *fühlen* lassen. Dauern über 300 ms sind ebenfalls geblieben, wo sie berechtigt sind: Ring-Füllung (`duration-500` auf `stroke-dasharray`), Live-Fortschrittsbalken (`duration-1000 ease-linear` — konstante Bewegung gehört auf `linear`).

### Key Data Flow

1. User creates an **Event** (race date + distance + target time)
2. `GenerateEventTrainingPlanJob` builds a **TrainingPlan** → list of **TrainingSessions**
3. Strava activities are auto-imported via webhook → matched to planned sessions
4. Daily **Wellbeing** check-ins trigger `RecommendForWellbeingJob` — the coach *proposes* a change to today's session; the athlete accepts or declines
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

### The coach proposes, the athlete decides

`AdjustPlanForWellbeingJob` used to rewrite today's session outright — type, title, description, distance, duration, pace, zone, intensity. Reported: *"Der Coach passt die Trainingseinheit automatisch an das Wellbeing an … der Athlet darf selber entscheiden ob die Empfehlung des Coaches angenommen oder abgelehnt werden soll."* Someone who had geared up for a threshold session and found twenty easy minutes instead experienced their plan as something that happened *to* them.

`RecommendForWellbeingJob` now writes a `SessionRecommendation` instead: `before`, `after`, a one-sentence `reason`, status `pending`. The dashboard shows it with both sides and two buttons. Nothing touches the plan until the athlete says so.

- **Accepting** applies `after` and sets `pinned_at` — the same mechanism the coach chat uses, so a regeneration cannot quietly discard the athlete's decision. `steps` and `nutrition_tips` are cleared; they described the old session.
- **Declining** leaves the plan untouched and closes the recommendation.
- **No card when nothing really changes.** The model rewrites the description almost every time; comparing only type, intensity, zone and the numbers (5 % tolerance) keeps a daily "nothing to see here" card off the dashboard. A warning that always shows is one nobody reads.
- A second check-in the same day **expires** the open recommendation — two open decisions cannot both be answered.

**The Garmin sync on save now always runs** when Garmin is connected, guarded only by a three-minute lock. It used to skip whenever *any* metric existed for today, which missed the common case: check in at breakfast, sync the watch afterwards, and the night's values never arrived — so the coach proposed on half the data.

### Krank ist krank, Pause ist Pause

Gemeldet am 25.10.2026: Wellbeing „krank", Notiz „Weisheitszahn-OP" — die Tagesempfehlung lautete „Sehr lockerer Wiedereinstieg, 3,5 km". Im Coach-Chat „zwei Wochen Laufpause" gesagt; der Coach verstand es, merkte es sich als Notiz, und die Empfehlung blieb.

Drei Ursachen an drei Stellen: `generateTodayRecommendation()` bekam aus dem Wellbeing nur Energie, Schlaf, Muskelkater und Stress — **weder `is_sick` noch die Notiz**. `ReturnToRunService::statusFor()` sah „krank, seitdem kein Lauf" und meldete am Tag der OP **Wiedereinstieg Stufe 1**, und der Prompt sagte dazu „MUSS easy_run". Und eine Notiz (`remember_user_fact`) **wirkt auf nichts**: sie steht im Chat-Kontext, nicht im Plan.

- **Krank/verletzt oder pausiert → Ruhetag ohne KI.** `AIController::restRecommendation()` greift vor dem Cache und vor dem Modell. Eine Entscheidung, die feststeht, gehört nicht in einen Prompt.
- **Die Pause ist ein Datum, keine Notiz:** `runner_profiles.training_paused_until` + `training_pause_reason`, gesetzt über das Coach-Werkzeug `pause_training` (und `end_training_pause`). Es überspringt die geplanten Einheiten bis dahin und leert die Caches — auch wenn nichts übersprungen wurde, denn ohne Plan blieb die gespeicherte Laufempfehlung sonst stehen.
- **Das Gerüst kennt die Pause:** `PlanContextBuilder::build()` trägt die Pausentage als `available = false` in die Overrides ein. Sonst schrieb spätestens `plan:write-week` am Sonntag Einheiten in die Pause.
- **Kein Wiedereinstieg während der Pause oder am Kranktag** — `statusFor()` liefert dann null. Danach beginnt er von selbst über die Lauflücke.
- Die **Wellbeing-Notiz** steht jetzt in beiden Prompts (Tagesempfehlung und Vorschlag zur Planeinheit).

### Shared workouts — the athlete swaps today's session

Reported: *"Ich hatte 30 Min Zone2 auf dem Plan, ich habe aber Bock auf Intervalle."* The plan says what is sensible; the day's form sometimes says otherwise, and the only answer used to be "do it or don't".

**Dashboard → Einheit tauschen** lists workouts other athletes have shared, grouped by run type. Picking one opens a **detail view first** — full description, every section with repetitions, duration, pace and zone, and the totals — and only then offers to take it. Choosing a session you cannot read is a blind buy, and it lands in the plan. From there it applies to today's session. The session is replaced, `steps` come straight from the workout, and it gets `pinned_at` — the athlete chose it, so a regeneration must not discard it.

This deliberately reuses the existing workout builder instead of adding a curated template table beside it. The reason it works at all is a decision already made in the builder: **its blocks carry `pace_zone`, not fixed paces.** A shared workout is therefore athlete-independent by construction — `WorkoutPaceResolver` turns zones into seconds from *this* runner's VDOT (`Vdot::ZONE_TARGET` — zone 4 is T, zone 5 is I). A test asserts two athletes get different paces from the same workout.

**Eine Strecke bleibt eine Strecke.** `WorkoutPaceResolver` rechnete
distanzbasierte Abschnitte in Minuten um und verwarf die Meter danach — der
Schritt-Vertrag hatte kein Streckenfeld. Aus „500 m schnell" wurde „2 min",
also aus einer Ansage eine Schätzung. Die Schritte tragen jetzt `distance_m`,
und überall, wo ein Abschnitt angezeigt wird, steht die Strecke vorn und die
geschätzte Dauer daneben (`stepAmount()` in `useSessionTypes.js` ist die
einzige Stelle, die das formatiert). Auch die Uhr bekommt bei einer
Streckenvorgabe `meters` statt `duration_sec`, sonst endet die Runde nach der
geschätzten Zeit statt nach 500 m. Die Gesamtstrecke kommt aus den Metern
selbst und nicht mehr über die schon gerundeten Minuten zurück — 5×1000 m
ergaben so 5,2 km.

Denselben Tausch gibt es zweimal: auf dem Dashboard („Einheit tauschen") und
auf der Planseite („Eigenes Workout"). Die Planseite setzte `steps => null`
und liess die Struktur vom Modell neu erfinden. Beide Wege benutzen jetzt den
Resolver, pinnen die Einheit und löschen die Verpflegungstipps der alten.

The rules around sharing:

- **Only the creator publishes** (`workouts.toggle-public`), and only the creator can edit. Nobody edits someone else's workout.
- **An admin can only withdraw a sharing** (`/admin/workouts`), never change or delete it. The workout disappears from everyone else's picker and stays with its creator, with the reason shown next to it — a silent disappearance would be worse than a "no".
- **Re-publishing clears the admin's note**, or it would stand there permanently after it stopped applying.

### Am Renntag wird gelaufen, nicht trainiert

Gemeldet: *"Ich habe nächste Woche am Freitag ein 5k Rennen und am Sonntag ein 24km Wattlauf … ich soll aber an beiden Tagen Trainings laufen … es wird an dem jeweiligen Tag gar nicht berücksichtigt."* Im Plan stand am 5-km-Renntag ein **Tempolauf 8,2 km**, am Tag des 24,6-km-Wattlaufs ein **Langer Lauf 24 km**. Der Donnerstag davor war korrekt ein Ruhetag mit der Begründung "Ruhe vor Rennwochenende" — das Modell wusste also Bescheid.

**Wieder eine doppelte Wahrheit über denselben Tag.** `PlanContextBuilder::otherEvents()` gab die anderen Rennen **nur in den Prompt** ("an diesen Tagen KEIN Training — type=rest"); `WeeklyPatternService::build()` bekam sie nie. Das Modell hielt sich an den Prompt und lieferte einen Ruhetag — und `TrainingPlanValidator::restoreMissingSlots()` sah, dass die Einheit aus dem Gerüst fehlt, und **ersetzte den Ruhetag durch sie** ("Ein Ruhetag an einem belegten Tag wird ersetzt"). Das Gerüst ist bindend, und es kannte das Rennen nicht.

Nachweisbar ohne einen einzigen Modellaufruf: Gerüst bauen, eine Modellantwort mit `rest` an den Renntagen durch den echten Validator schicken, Korrekturen lesen — `2026-09-18: tempo_run fehlte (stand als Ruhetag) → eingesetzt`.

**Ein Rennen ist jetzt ein fester Termin im Gerüst.** Das ist kein neuer Mechanismus, sondern der vorhandene: `fixed` gab es schon für den Laufclub — zuerst gelegt, verbraucht die Zeit die er dauert, nichts wird danebengeplant.

- Der Renntag bekommt `type = race_prep` mit dem **Rennnamen** als Label — dieselbe Konvention, die der Renntag des Zielrennens schon hatte.
- Er **schlägt das Wochenraster** (wer läuft, hat an dem Tag Zeit) und einen **Vereinstermin** (der fällt aus).
- Er zählt **immer als hart**, auch ein langes Rennen.
- Ab `RACE_COUNTS_AS_LONG_RUN_KM` (15 km) **ersetzt er den langen Lauf der Woche** — sonst plante das Gerüst neben dem 24-km-Rennen noch einen Longrun.
- Seine **echten Kilometer** gehen ins Wochenbudget, nicht die Minuten aus dem Wochenraster: 24,6 km, nicht "die 60 Minuten, die zufällig im Profil stehen".
- Der Validator setzt an einem Renntag als Notnagel **das Rennen** ein, keine Ersatzeinheit.
- Im Wiedereinstieg wird ein Rennen **nicht entschärft** — die Leiter kann eine Einheit abschwächen, aber kein Rennen.

`Event::race_km` liefert die Distanz als Zahl (`distance_label` liest sich gut, rechnet aber nicht) und holt die Standarddistanzen aus `RacePredictionService::ANCHORS` — dort stehen sie schon, und genau an dieser Sorte Zweitkopie ist die Rennprognose einmal auseinandergelaufen.

Sechs der acht Tests in `RaceDayInPlanTest` fallen mit dem alten Code um; zwei sind Wächter ("ohne Rennen ändert sich nichts") und bestehen erwartungsgemäß beidseitig.

**Nachtrag — das Zielrennen war ausgenommen, und die Zielzeit fehlte.** Gemeldet: *"Ich habe den Sportcheck lauf mit ziel 20 min angeben im plan steht aber 27 min … Jetzt steht für den Berlin Marathon ein Langer lauf von 9km an obwohl dort mein Rennen stattfindet."* Drei Fehler an derselben Stelle:

1. **Das Zielrennen des Plans fehlte im Gerüst.** Die Rennen kamen aus `otherEvents()`, und die Methode schliesst per `id != $event->id` genau das eine Rennen aus, um das der Plan gebaut wird. Am Marathon-Renntag stand deshalb weiter „Langer Lauf, 9 km" — während der ganze Taper davor darauf zulief. Es gibt jetzt `PlanContextBuilder::races()`, das **alle** Rennen im Fenster liefert; `otherEvents()` bleibt daneben stehen, weil es einen Prompt-Abschnitt über die *anderen* Rennen füttert.
2. **Die eingetragene Zielzeit wurde von einer Schätzung übersteuert.** Am 5-km-Renntag stand „27 min" — 5 km in der Zonenpace. Schlimmer als die Zahl war die Folge: das Modell pacte den 5er im Marathon-Renntempo (5:19 statt 4:00). `Event::target_minutes` und `target_pace_seconds` liefern die Vorgabe als Zahl; das Gerüst nimmt sie vor jeder Schätzung, und ohne Eintrag (Wattlauf) bleibt es bei der Schätzung.
3. **Der Validator kürzte den Marathon auf das Tagesbudget.** Sonntags stehen 180 Minuten im Wochenraster, also machte `enforceSlotCaps()` aus 42,195 km brav „33,8 km in 180 min". Ein Zeitbudget ist eine Aussage über *Trainings*tage — ein Rennen dauert, was es dauert. `enforceSlotCaps()` und `enforceDailyBudget()` überspringen Renntage.

Merkhilfe für den nächsten Fix an dieser Stelle: **wer „Rennen" sagt, muss auch das eigene meinen.**

### `/plan-interview` — das Gespräch vor dem nächsten Block

Gewünscht: *"Vielleicht wäre ein kurzes Interview nicht schlecht damit sich der Plan perfekt einstellen kann."*

**Der Entwurf entscheidet sich daran, was NICHT gefragt wird.** Zone3 kennt 132 Aktivitäten, 150 Wellbeing-Einträge, 95 Garmin-Tage, 128 bewertete Einheiten, Schwellenpace und Zonen. Eine Frage nach dem Wochenumfang wäre nicht nur überflüssig — sie baut eine zweite Wahrheit neben die Daten, und genau daran hat dieses Projekt mehrfach Tage verloren. Gefragt wird nur, was die Datenbank nicht hergibt:

| Schritt | Warum die Daten es nicht beantworten |
|---|---|
| **Rückblick** auf das letzte Rennen | Von acht vergangenen Rennen hatte **keines** ein Ergebnis |
| **Warum Einheiten ausfielen** | Die App sieht `skipped`, nie den Grund — bei 34 % Quote |
| **Zeit ab jetzt** | Das Wochenraster steht seit dem Onboarding |
| **Ausrichtung** | Rennen, Grundlage, Wiedereinstieg oder Form halten |
| **Freitext** | Alles, wofür es kein Feld gibt |

Jeder Schritt zeigt zuerst die Antwort der Datenbank, der Athlet korrigiert nur. Beim Rückblick schlägt die Strava-Aktivität vom Renntag die Zeit vor — bestätigen muss er sie trotzdem, denn eine Trainingsrunde am selben Tag sähe genauso aus.

**Die Antworten sind keine Prompt-Dekoration.** `PlanInterviewService::apply()` schreibt sie dorthin, wo sie wirken:

- **Wochenraster** ← Tage und Minuten. Der stärkste Hebel aufs Gerüst; `rebuildGrid()` streicht den kürzesten Tag zuerst und lässt längere Tage (den Sonntag) in Ruhe.
- **Rennbilanz** ← Zeit und Bewertung landen auf dem `TrainingPlan`. Damit läuft `pastPlanResults()` zum ersten Mal nicht leer: die Methode filtert auf `overall_rating`, und das wurde nie gesetzt. Der Mechanismus war immer da, nur ungefüttert.
- **Umfangsdeckel** ← `volumeFactor()` (Körper 0,80 · Zeit 0,85 · Motivation 0,90). `WeeklyVolumeService` schreibt die letzten Wochen fort — richtig, solange sie gelaufen *wurden*. Wer sagt „ich hatte keine Zeit", bekommt sonst denselben Umfang noch einmal, der schon beim letzten Mal nicht in die Woche passte.
- **Coach-Notizen** ← nur, was sich nicht strukturieren lässt.

Die Umfangskorrektur sitzt in `PlanContextBuilder::applyInterviewToVolume()` und nicht im Gerüst, weil derselbe `$volume` danach an **zwei** Stellen geht: ins Gerüst (bindend) und in den Prompt-Abschnitt (erklärend). Zwei getrennte Korrekturen wären wieder die doppelte Wahrheit.

**Der Einstieg ist „Plan erstellen" unter Events.** Der Knopf führt für ein Event ohne Plan auf `/plan-interview?event=X`; danach geht es zur Planseite, wo der Plan gebaut wird. Zwei Abkürzungen sind eingebaut, weil ein Interview eine Einladung sein soll und kein Schlagbaum: wer **kürzlich schon geantwortet hat** (dieselbe Acht-Wochen-Grenze) wird serverseitig durchgewunken, und steht das **Rennen bereits fest**, entfällt die Frage nach dem Ziel — aus fünf Schritten werden vier.

**`flash` wurde nie geteilt.** Beim Prüfen der Quittung fiel auf, dass `HandleInertiaRequests::share()` kein `flash` enthält — während **20 Controller** `->with('success', …)` setzen und **14 Vue-Seiten** `props.flash` lesen. Jede Bestätigung der ganzen Anwendung ist seit jeher still verschluckt worden: „Freigabe zurückgenommen", „Plan-Lücken geschlossen", „Messwerte verworfen". Gemeldet hat es niemand, weil eine fehlende Meldung wie eine Aktion ohne Nachricht aussieht und nicht wie ein Fehler. Jetzt als Closure geteilt, damit die Session erst beim Rendern gelesen wird.

**Ein Interview altert.** Nach acht Wochen liefert `forPlan()` nichts mehr — es beschreibt dann einen anderen Athleten, und ab da zählen die Daten allein. Jedes Feld ist optional: ein halb beantwortetes Interview ist mehr wert als eines, das an einem Pflichtfeld abgebrochen wurde.

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

**The history could not catch it, because it was written before the sessions were persisted.** `PlanRevisionRecorder::record()` diffed `sessionsBefore` against `$aiSessions` — note that this is the *validated* plan, not the raw model answer, so it was closer to the truth than it first appears. What it could not see is everything that happens **after**: the creation loop skips dates it considers taken, and `sealGaps()` adds rest days. That is where "Ruhetag → Lockerer Lauf" came from for a day that never held a session.

Both plan jobs now record **after** writing and diff against what is actually in the database (`RegeneratePlanJob::writtenPlan()`). This removes the second source rather than adding another rule for it. `diff()` still accepts `untouchedDates`, but neither job passes it any more: with the real state on both sides, kept days match by themselves — and a kept day whose session vanished now shows up as removed, which is exactly the signal that was missing.

Worth knowing for anyone writing a test here: the divergence is narrow and hard to construct. Two attempts at a regression test passed with the old code too, because the validator had already reconciled the difference. The change is right by construction, not because a test proves the old behaviour wrong.

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

### 80/20 gilt im Gerüst, nicht nur im Prompt

Gemeldet im Sub-90-Check: in einer knappen Woche strich `budgetWeek()` beim Halbmarathon zuerst den **lockeren Lauf** — `easy_run` stand in `PRIORITIES` hinten. Übrig blieben langer Lauf, Tempolauf und Intervalle, während der Prompt 80/20 verlangte.

- `planWeek()` erlaubt eine **zweite harte Einheit erst ab fünf Einheiten** (`SECOND_HARD_FROM_SESSIONS`). Bei vier Läufen wären zwei harte die Hälfte der Woche; der freie Platz geht an einen lockeren Lauf.
- `leastImportant()` streicht den **letzten lockeren Lauf nie, solange eine harte Einheit offen ist**. Die Zielreihenfolge gilt sonst weiter.
- Der lange Lauf zählt dabei nicht als hart, obwohl er beim HM ein Renntempo-Finish hat.

**Die Phase hängt an der Distanz.** `Event::training_phase` sagte für jedes Rennen „≤ 14 Tage Race Week" — beim Halbmarathon setzt die Leiter genau dort den längsten Lauf. Jetzt: Race Week = letzte 7 Tage, Taper = `LongRunPlanService::taperDays()` (die einzige Quelle), davor je 6 Wochen Peak und Build. In der Rennwoche bleibt ein kurzer Renntempo-Reiz erlaubt — Taper heißt Umfang runter, Intensität halten.

**Intervall-Pace** kommt seit dem 25.10.2026 aus dem VDOT (I = 97,5–100 %, R = 105 %, siehe unten). Vorher reichte der Intervallbereich bis T + 4 s, also langsamer als die Schwelle.

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

### VDOT — eine Zahl für Tempi, Zonen und Prognose (seit 25.10.2026)

Vorher hingen alle Tempos an festen Faktoren auf die Schwellenpace (5 km = T × 0,90, Marathon = T × 1,12, locker = T + 46 bis 81 s). Bei T = 4:15 sagte Zone3 für 5 km 19:08 voraus (Daniels: 19:56) und für den Marathon 3:20:51 (3:10:39). Ein fester Sekundenabstand bedeutet ausserdem für einen 3:30-Läufer etwas anderes als für einen 6:00-Läufer.

Drei Klassen, eine Richtung:

| Klasse | Aufgabe |
|---|---|
| `Vdot` | reine Mathematik: Daniels/Gilbert-Formeln, Zonen- und Trainingsintensitäten als Anteil am VO2max |
| `VdotService::forProfile()` | **woher** der VDOT kommt — die einzige Stelle, die das entscheidet |
| `RunnerProfile::vdot()` / `vdotInfo()` | der Zugang für alle anderen, pro Instanz gemerkt |

**Quelle (Entscheidung von Jan):** Schwellenpace und Strava-Bestzeiten der letzten 6 Wochen (5 km, 10 km, HM, Marathon — nicht 1 km, die Formel gilt ab ~1,5 km) werden je in einen VDOT umgerechnet, **der höhere gilt**. Eine Bestzeit ist eine Untergrenze der Form; eine langsame Teilstrecke aus einem lockeren Lauf drückt deshalb nichts nach unten.

Daran hängt alles, was ein Tempo nennt: `RacePredictionService::forVdot()` (Dashboard, Planseite, Prognose-Job, `GoalCheckService`), `TrainingPaceService` (E 59–74 %, T 88 %, I 97,5–100 %, R 105 %, M aus der Marathonprognose), die Pace-Zonen und `WorkoutPaceResolver` (Zonenziel aus `Vdot::ZONE_TARGET`: Zone 4 = T, Zone 5 = I — nicht die Bandmitte).

**`pace_zones` wird nicht mehr gelesen, sondern berechnet.** Ein Accessor auf `RunnerProfile` liefert die Zonen aus dem VDOT; die Spalte zählt nur noch ohne VDOT. Gespeichert wurde an drei Stellen, gelesen an fünf — und mit den Bestzeiten als zweiter Quelle wäre der gespeicherte Wert nach jedem schnellen Lauf veraltet. Dabei fiel auf, dass der Coach-Chat die Zonen über `min`/`max` las, die es nie gab: er sah seit jeher leere Zonen.

`RacePredictionConsistencyTest` prüft gegen Daniels' Tabelle (VDOT 40–70, 0,2 % Toleranz). Der Wert bleibt ungerundet, bis er angezeigt wird — aus einem auf eine Stelle gerundeten VDOT käme T um eine Sekunde neben der gemessenen Schwelle heraus.

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
- `OPENAI_MODEL` (default `gpt-5.6-sol`) — `$ai->main()`: event_plan, threshold_pace, profile_estimation, coach_chat
- `OPENAI_MODEL_MINI` (default `gpt-5.6-luna`) — `$ai->mini()`: everything short

Vorher liefen `gpt-5.5-2026-04-23` und `gpt-5.4-mini`. Listenpreise je 1M Token (Stand 09/2026): Astra 10/50 $, **Sol 4/20 $**, Terra 2/12 $, **Luna 0,20/1,20 $**. Bei unserem Verbrauch — 762 Requests und 1,275 Mio. Token in 30 Tagen — waeren das rund 10–12 $ im Monat auf Sol. Kosten sind damit **nicht** das Auswahlkriterium; der Plan-Prompt ist die schwerste Aufgabe der App, und jede Modellschwaeche landet als Validator-Korrektur.

**Die Rechnung ist trotzdem fast null — und der Grund gehoert gewusst.** Das Konto ist im Programm *complimentary daily tokens*: unter Data controls ist „Share inputs and outputs with OpenAI" fuer alle Projekte aktiv, Zone3 bezahlt also mit Daten statt mit Geld (0,05 $ Verbrauch seit Maerz 2026). In den Prompts stehen Schwellenpace, Umfaenge, Wellbeing, Coach-Notizen und die **Garmin-Gesundheitsdaten** (HRV, Schlaf, Ruhepuls, Body Battery, Stress) — und zwar aller Athleten, nicht nur des Betreibers. Nach DSGVO ist das besondere Kategorie (Art. 9). Wer das Sharing abschaltet, zahlt ab dann echtes Geld: der Credit Grant steht bei 4,95 $ und Auto-Reload ist AUS, und bei 0 $ hoert die API auf zu antworten — dann steht jede KI-Funktion still.

**`AiLog::calculateCost()` kannte lange nur GPT-4o-Preise** und fiel fuer alles andere still darauf zurueck. Gelaufen ist seit Monaten gpt-5.5 — jede Euro-Zahl im AI-Log war mit den Preisen eines Modells gerechnet, das gar nicht im Einsatz war. Ausloeser war der exakte Schluesselvergleich: konfiguriert war `gpt-5.5-2026-04-23`, in der Tabelle stand nicht einmal `gpt-5.5`. Die Suche streift jetzt einen Datumsanhang ab, und ein wirklich unbekanntes Modell kostet 0 **und schreibt eine Warnung** — eine sichtbare Luecke statt einer erfundenen Zahl.

**Critical quirks for gpt-5.5 (reasoning model):**
- `temperature` is NOT supported — do not pass it
- Uses `max_completion_tokens`; internal reasoning tokens eat into the budget, so use at least 700 even for short answers, and **≥ 16000 for a full plan** (too low returns an empty completion with `finish_reason: length`)
- All calls are logged to `ai_logs` (21 call types; `AiLog::$call_type_label` gives the German name, unlabelled types fall through to the raw key)

Calls go through `$this->ai->chat(string $callType, array $messages, float $temperature, int $maxTokens, int $timeout = 30, ?string $model = null)`, or `chatWithTools()` for the coach chat. `systemPrompt()` prepends the coaching philosophy and the selected coach's personality.

**Function-Tools und Reasoning gehen in Chat Completions nicht zusammen.** Nach dem Wechsel auf gpt-5.6 antwortete der Coach-Chat mit HTTP 400:

```
Function tools with reasoning_effort are not supported for gpt-5.6-sol in
/v1/chat/completions. To use function tools, use /v1/responses or set
reasoning_effort to 'none'.
```

Das Modell bringt ein Standard-Reasoning mit, und der Tool-Pfad vertraegt das nicht. `OpenAIClient::chatWithTools()` sendet deshalb `reasoning_effort => 'none'`; `chat()` ohne Tools bleibt unveraendert und darf denken. Der saubere Weg waere `/v1/responses`, wo beides zusammen geht — anderes Request- und Antwortformat, also ein eigener Umbau.

Aufgefallen ist das **nur, weil der Modellwechsel von Hand nachgeprueft wurde**. Der Fehler betrifft ausschliesslich den einen Pfad mit Tools: ein einfacher Testaufruf lief durch, und ohne den Klick in den Coach-Chat waere es nachts um 05:00 im Plan-Job passiert. `OpenAIClientRequestTest` haelt die Zeile jetzt fest.

### Coach chat tools

The coach can really change the plan — `create_training_session`, `modify_training_session` (any day, not just today), `move_training_session`, `delete_training_session`, plus `skip_training_sessions`, `update_event_target`, `remember_user_fact`. Everything it writes gets `pinned_at`.

Two rules learned the hard way and encoded in the system prompt: **never claim a change without calling the tool** (with only a today-tool available, the model would describe the change instead of making it), and **one running session per day** also applies here. `applySessionFields()` reconciles distance/duration/pace so the numbers can't contradict the title — a model that changes the type without supplying new numbers would otherwise leave the old ones standing.

## Was am 09.10.2026 abgerissen wurde

Eine vollständige Messung ergab 70.400 Zeilen für **zwei aktive Athleten** (Jan: 153 von 177 Coach-Nachrichten, 150 von 157 Wellbeing-Einträgen; Christopher der einzige zweite). Nicht der Kern war überengineert — der ist schwierig — sondern der Rand. Phase 1 nahm die Leichen, Phase 2 meinen eigenen Anbau.

**Gelöscht, weil scharf und unerreichbar.** Jede dieser Routen war aus dem Frontend nicht aufrufbar, geprüft gegen Routennamen *und* literale URL-Pfade:

| Weg | Warum er weg musste |
|---|---|
| `POST /plans/generate` → `PlanController` + `TrainingPlanService` | Ein Fossil aus der ersten Version: erzeugte `"Workout #1"` / `"Easy training run"` auf Englisch, ohne Tests, am Gerüst und Validator vorbei — und rief dabei die echte Strava-API. |
| `GET /ai/analyze/{goal}` · `/ai/plan/{goal}` · `/ai/suggestions` | Drei **GET**-Routen, die OpenAI aufrufen. Eine GET-Route, die Geld kostet, ist ein Konstruktionsfehler: ein Prefetch genügt. `/ai/plan` war zusätzlich **kaputt** — es rief `TrainingPlanGenerator::generateTrainingPlan()`, und die Methode liegt in `SessionContentService`; die Klassen erben nicht voneinander. Der Aufruftyp `goal_analysis` kommt in 1.994 `ai_logs`-Zeilen nicht ein einziges Mal vor. |
| `POST /api/profile/zones` · `GET /api/profile/runner` | `previewZones()` rief für eine Vorschau OpenAI auf. `runner.profile.store` **bleibt** — `Profile/Edit.vue:331` benutzt es. |
| `PATCH /events/{event}/plan/availability` | Doppelt zu `WeekAvailabilityController@store`. Dessen eigener Docblock sagt, warum er existiert: „Es gab zwar `availability_overrides` je Datum, aber keine Oberfläche." Der alte Pfad blieb danach stehen. |

Mit den Aufrufern fielen die Methoden, die nur sie benutzten: `CoachingTextService::analyzeTraining()` + `buildAnalysisPrompt()` + drei Formatierer (−120 Zeilen) und `SessionContentService::generateTrainingPlan()` (−30).

**Merkhilfe:** eine Route, die Geld ausgibt, gehört nie hinter `GET`, und ein zweiter Schreibpfad auf dasselbe Feld ist immer einer zu viel.

### Was ausdrücklich NICHT abgerissen wurde

- **`ProgressService`** sah in der Analyse tot aus (2 Referenzen, 0 Tests), ist es aber nicht: `routes/web.php` ruft `generateTrainingSuggestions()` in der Dashboard-Closure, und `AIController::recommendToday()` ruft `calculateProgress()`. Die Analyse war hier zu grob — beim Löschen prüfen, nicht beim Zählen.
- **`runner.profile.show`** ist eine einzeilige Weiterleitung auf `profile.edit`. Technisch unerreichbar, aber ein alter Lesezeichen-Link kostet sonst einen 404 statt nichts.
- **Wrapped** und **Impersonation** bleiben: klein, harmlos, im Betrieb kostenlos.
- **Das Plan-Interview bleibt** (Entscheidung vom 09.10.2026). Es hat noch 0 Datensätze; Jan will es behalten und beim nächsten Block einmal führen.

### Die 68 verwaisten Einheiten — angehängt statt weggeworfen

Die Anweisung lautete „hau die verwaisten Einheiten weg". Beim Nachsehen war die Menge aber **nicht Müll, sondern Trainingshistorie**: 51 erledigt, 49 an echten Strava-Aktivitäten, und **45 mit der eigenen Bewertung des Athleten** — von 128 bewerteten Einheiten überhaupt. Diese Bewertungen füttern den Prompt-Abschnitt „Bisherige Einheitsbewertungen"; ein Löschen hätte ein Drittel des Feedbacks gekostet, aus dem der Plan lernt.

63 der 68 trugen eine `event_id`, und zu jedem dieser Events gibt es den Plan noch. **Der Defekt war nie „diese Zeilen sind überflüssig", sondern „die Verknüpfung ist verloren gegangen"** — wohl beim Beenden oder Neuberechnen eines Plans.

`2026_10_09_140000_adopt_or_remove_orphaned_sessions` räumt deshalb nach **Regel, nicht nach Anzahl** (die lokale Kopie war vom 08.09., Produktion hat mehr Zeilen — eine Migration, die „die 68" löscht, löscht dort etwas anderes):

1. Mit `event_id` → an den neuesten Plan dieses Events anhängen.
2. Ohne Plan **und ohne jeden Inhalt** (keine Aktivität, keine Bewertung, keine Notiz, keine Rezension, nicht erledigt, Termin vorbei) → weg.
3. Alles andere bleibt stehen und wird als Warnung geloggt. Was Inhalt hat und sich nicht zuordnen lässt, entscheidet ein Mensch.

Ergebnis lokal: `{"vorher":68,"angehaengt":63,"geloescht":5,"bleibt_offen":0}` — Bewertungen 128 → 128, Einheiten 380 → 375.

`OrphanedSessionCleanupTest` hält die Grenze fest, an der das Aufräumen aufhört. Der wichtigste Test ist `test_a_rated_orphan_is_never_deleted`: **was Inhalt hat, wird nicht gelöscht, sondern sichtbar stehengelassen.** Die Tests bauen die Regeln nach statt die Migration aufzurufen — unter `RefreshDatabase` läuft sie schon beim Aufbau der Datenbank und ist danach nicht wiederholbar.

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

`POST /strava/webhook` — **the import runs in the request.** Fetch the activity from the Strava API, store it, match it to the planned session, write best efforts, dispatch the review job, send the push.

It ran as a queued job for a while, and the reasoning was sound: the web process is single-threaded, and an import makes two outbound HTTP calls. But after that change activities stopped arriving, and a second attempt — a dedicated `imports` queue with its own worker — did not bring them back either. A core feature that does not work outweighs a request that takes a few seconds, so it went back to what worked. The job class and the second worker are gone.

What did **not** go back: the import and matching logic stays in `StravaImportService`, shared with the manual sync. That extraction changed no behaviour and was not the problem.

What is new and deliberate: `Log::info('Strava-Webhook empfangen', …)` fires **before any filtering**, and a warning fires when the athlete is unknown or the activity is not retrievable. Diagnosing this cost two long sessions because nothing recorded whether Strava called at all.

The route carries **no throttle and no token check**. Both arrived with the rework, and both can reject a genuine Strava call; while the import is not reliably working, nothing stands in its way. `callbackTokenMatches()` is gone.

**Strava leads; Zone3 follows.** All three aspect types are handled, because an athlete who edits something over there does it on purpose.

- **`delete`** runs `ActivityDeletionService` — a planned session goes back to `planned` from its snapshot, an unplanned one disappears with it. Otherwise a run removed on Strava kept counting towards weekly volume, load and threshold pace.
- **`update`** re-fetches the activity (the `updates` payload carries only what changed; the importer wants the whole record) and then splits on one question: **did the sport change?**
  - *Yes* → the match is no longer valid. `ActivityDeletionService::unlink()` undoes it and `matchActivityToSession()` runs again, so a "run" that turns out to be a ride releases the planned run session and lands as cross-training instead. A fresh review is dispatched, because the old one described something else.
  - *No* → the match stands and only the numbers follow. The title of an **unplanned** session tracks the activity name; a **planned** session keeps the coach's title ("Schwelle mit Kontrolle" must not become "Morning Run"), and `planned_snapshot` is never touched — it records what was *planned*, which a Strava edit does not change.

No push on an update: the athlete made the change themselves. Best efforts are re-synced for runs but without `flagPendingPr` — a correction is not a reason to celebrate.

An `update` for an activity that was never imported does nothing; importing it is `create`'s job.

**Strava does not sign its webhooks** — there is no signature to verify, unlike GitHub. The protection is that only `owner_id` and `object_id` are taken from the body, and the activity is then fetched from the API with the account's token, so a forged call cannot inject invented data.

### The safety net: `strava:sync` every fifteen minutes

The webhook used to be the **only** automatic path, which meant a core feature hung on something we do not control: Strava has to deliver, and when it stops, nobody notices. That is exactly what happened — six days without an import while the subscription was valid, the endpoint answered 200 in 0.35 s, and the athlete kept running.

`Schedule::command('strava:sync')->everyFifteenMinutes()` fetches for every connected account. The webhook stays the fast path (seconds); this turns "sometimes broken" into "sometimes a quarter of an hour late". It ran every five minutes while the webhook was down, when it was the only path; with delivery working again it is pure insurance and may run rarely. Do not go below five minutes: one list call per account per run is 1152 a day for four athletes, against Strava's daily ceiling of 2000.

It only touches what is genuinely new: an activity the webhook already imported is skipped entirely — no second match, no second review, no second push. Accounts without a refresh token are skipped with a log line, and one failing account cannot stop the others. Cost is one list call plus one detail call per new activity; with four athletes that is 4 per 15 minutes against Strava's limit of 200, and 384 a day.

### Cloudflare sits in front of zone3.run — and it broke the webhook

**The outage that cost several sessions had nothing to do with this code.** Cloudflare's **Bot Fight Mode** was blocking Strava's servers. The endpoint answered every request from a normal network (200 in 0.35 s, correct challenge echo), so from the inside everything looked healthy — and the app cannot see that a request never arrived.

What finally proved it: re-registering the subscription failed with Strava's own verdict.

```
{"field":"callback url","code":"GET to callback URL does not return 200"}
```

The identical GET from a laptop returned `{"hub.challenge":"…"}` and 200. Same URL, same parameters, different source IP.

If webhook deliveries ever stop again, check the edge before the code:

- `curl -sD- https://zone3.run/strava/webhook` — a `Server: cloudflare` / `CF-RAY` header means the edge can reject before Laravel ever runs
- Bot Fight Mode ignores WAF skip rules; it has to be **off** (Security → Bots)
- A WAF custom rule with action **Skip** on `URI Path equals /strava/webhook` covers the managed rules, Browser Integrity Check and Security Level

**Never delete the subscription before confirming a new one can be created** — Strava allows exactly one, and a failed re-create leaves none at all. Test with a create against the same callback URL first, or accept that the scheduled sync is the only path until it works.

**Checking the subscription** (read-only, answers "is Strava even calling us?"):

```bash
curl -sG https://www.strava.com/api/v3/push_subscriptions \
  -d client_id=… -d client_secret=…
```

And `strava_webhook_events` answers it from the other side: every call from Strava is recorded **before any filtering**, with its outcome, and shown on `/admin/system`. It lives in the database on purpose — production logs to the container stream, not to a file, so `/admin/system/logs` finds nothing there.

An empty array means no subscription exists and no event will ever arrive — re-register with `php artisan strava:subscribe-webhook`.

## Garmin

Two separate connection paths — do not confuse them:
1. **Profile → Verbindungen → Garmin** calls `/profile/garmin-connect` → fit-service `/garmin-login`. Login only, stores the garth token (the password is never stored), triggers a 60-day health backfill.
2. **Dashboard → "Zu Garmin Connect senden"** sends an actual workout to the watch and sets the token as a side effect on first use.

`SyncGarminHealthJob` / `garmin:sync-health` (daily 06:00) reads HRV, sleep, resting HR, body battery, stress, steps and training readiness into `garmin_daily_metrics` (all nullable — a missing value is "no data", never 0). `GarminHealthSummary::forUser()` gives 7-day averages against a 60-day baseline for the plan; `forDay()` gives today's values for the daily adjustment, because a weekly average is too slow to answer "what do I run today".

**FIT export** uses `@garmin/fitsdk` (npm) on the frontend; speed targets are stored as mm/s.

## Admin Panel

Routes in `routes/admin.php` — protected by `is_admin` on User. Manual threshold recalculation, AI log viewer (all OpenAI calls with tokens + duration), coach personality editor, push test sender, settings page showing both models.

### Impersonation — `Als … ansehen`

`POST /admin/users/{user}/impersonate` logs the admin in as that athlete; `POST /impersonate/stop` comes back. The fastest way to understand a report is to see the page the athlete sees — "there is a gap in my plan today" cost hours of database queries that one click would have answered.

Three rules, all load-bearing:

- **An admin cannot be impersonated.** Otherwise it is a quiet way to act administratively under someone else's name.
- **The stop route lives in `web.php`, not the admin group.** During impersonation you *are* the athlete, and the athlete is not an admin — behind the admin middleware you could not get out again. It checks the session instead.
- **An impersonated session carries no admin rights.** A test asserts `/admin/*` returns 403 while impersonating; that is the assertion that matters most in the file.

A warn-coloured banner sits on every page while it runs (`auth.impersonating` is shared from `HandleInertiaRequests`) — without it you mistake someone else's data for your own. Start and stop are both logged.

### `/admin/activities` — activities across all athletes

From a report: "ich habe keine Möglichkeit eine Aktivität zu löschen … noch nicht mal im Admin Bereich." The athlete can now delete their own; an admin still saw nobody else's. Filter by athlete, sport or name; the list shows how many training sessions hang on each activity, because deleting one with a session attached changes the plan rather than just tidying up. Deletion goes through `ActivityDeletionService` — never a bare `delete()`, or the next Strava sync brings it back.

### `/admin/system/logs` — the application log, without a container

Three debugging sessions in one week stalled because the answer was in the log and nobody could reach it. "Is Strava even calling us?" cost two long sessions and was one line away the whole time.

**Production must actually write a file, and at a level that lets the lines through.** The view stayed empty even though `LOG_CHANNEL=stack` / `LOG_STACK=single` were set correctly — because `LOG_LEVEL` was `error`. Every `Log::info` and `Log::warning` was discarded before it reached a file, so no file was ever created. Note the failure shape: had the path been unwritable, Laravel would have thrown and the request would have 500'd. It returned 200, which means the lines were *filtered*, not *rejected* — that distinction is what identified it.

For the log view to be useful, Coolify needs `LOG_LEVEL=info` and ideally `LOG_STACK=daily` (rotating, `LOG_DAILY_DAYS=14`); `single` grows without bound. `/admin/system` shows channel, stack **and level** in the environment block, and colours the level when it is above `info` — the setting is now visible instead of guessed.

`storage/` is ephemeral in the container: every deploy starts with an empty log. For history that survives deploys, mount a volume on `storage/logs`.

`LogReader` tails the newest `storage/logs/laravel*.log`. Two things matter when reading a log file, and both are encoded:

- **Never read the whole thing.** The file grows without bound; a `file_get_contents` on 200 MB would block the web process — the same class of mistake as the synchronous webhook. A 512 KB window from the end, and the page says when it truncated.
- **An entry is not a line.** A stack trace brings a hundred continuation lines; they belong to the entry above, not beside it. Otherwise the view is noise.

Full-text search covers message *and* context (`owner_id`, `user_id`, `strava_id` live there), plus a level filter and one-click shortcuts for the questions actually asked — Strava webhook, Strava import, plan, push.

### `/admin/system` — does the machine run

The rest of the admin answers "how is the product doing": users, activities, AI cost, coach split. Twice in one week the question was "is the system working", and there was no way to ask it — the Strava import stopped and nothing showed whether the job ran or why it failed; two days vanished from a plan while the revision history reported a change for exactly those days.

`AdminSystemController` reads the database and never the intention:

- **Queues** — pending per queue, and the age of the oldest waiting job. Age is the signal, not volume: a job waiting ten minutes means that queue's worker is not running. `default` and `imports` are always listed, empty or not.
- **Failed jobs** — grouped by class (a class appearing four times is a bug, not luck), with the first line of the exception, plus retry / forget / retry-all / flush.
- **Plan health** — a gap is a date *inside a plan's own span* with no session at all; no skeleton and no model call needed. Plus sessions with `training_plan_id = NULL` (present in the database, invisible to the athlete) and generations stuck past `plan_generating_at`.
- **Integrations** — per athlete, when Strava last delivered. The date is the honest signal; the connection badge is not, and it does **not** show the access token's expiry. Strava's access token lasts six hours and `StravaService::fetchActivity()` renews it on the next call, so "expired" is the normal state for anyone who has not uploaded today — a warning colour on a healthy system, which teaches you to ignore warnings. The badge reports whether a **refresh token** exists; without one nobody can obtain a new access token and the athlete has to reconnect.
- **Environment** — PHP, Laravel, drivers, DB size, both models, and whether debug is on.

"Mit Ruhetagen schliessen" fills a plan's gaps **without an AI call** — cheap and honest. Real repair with real sessions is a regeneration.

`information_schema` is queried only when the driver is MySQL; under SQLite the tests would otherwise error rather than return nothing.

### `perf:watch` — das System meldet sich selbst

**Hier stand einmal `/admin/performance`**: eine Anfrage-Messung mit drei Tabellen, einer Middleware, vier Diensten, eigener Konfiguration und eigener Admin-Seite — 2.310 Zeilen. In einem Monat Betrieb kamen **51 Messpunkte** zusammen. Bei zwei aktiven Athleten gibt es keine Lastprobleme zu messen; die Seite hat nie eine Frage beantwortet, die nicht auch im Log stand, und einmal habe ich mich an ihrer `migrate`-Zeile sogar über den Zustand eines Deploys getäuscht. Sie ist am 09.10.2026 abgebaut worden.

Geblieben ist die Achse, auf der Zone3 **wirklich** kaputtging: sechs Tage ohne Strava-Import, bemerkt vom Athleten selbst. Dafür braucht es keine Messung, sondern jemanden, der sich meldet.

```
ObservabilityServiceProvider   hört nur noch CommandStarting/CommandFinished
  → Recorder::command()        schreibt EINE Zeile pro Kommando fort
      → perf_command_runs      letzter Lauf, Dauer, Exit-Code, Zähler
  → Watchdog::findings()       vier Fragen, ohne Nebenwirkung
      → perf_alerts            Gedächtnis für die Abklingzeit
```

`Schedule::command('perf:watch')->dailyAt('07:30')` prüft und schickt den Admins eine Web-Push-Nachricht. **Vier Prüfungen**, nicht mehr fünf: fehlgeschlagene Jobs, eine stauende Queue, überfällige Kommandos, und — vorsichtig formuliert, als Frage — 48 h ohne Strava-Webhook. Die 5xx-Prüfung fiel mit `perf_events`; wer Serverfehler sucht, findet sie in `/admin/system/logs`.

**Täglich statt alle 15 Minuten.** Die Befunde ändern sich nicht im Viertelstundentakt: eine stauende Queue und ein ausgefallener Import sind Zustände von Stunden. 96 Läufe am Tag erzeugten Last und beantworteten dieselbe Frage.

**Kommandos werden fortgeschrieben, nicht angehängt.** `push:wellbeing-reminders` läuft jede Minute; als Ereignisstrom wären das 43.000 Zeilen im Monat für eine Frage, die eine Zeile beantwortet. `last_query_count` ist weggefallen — der Zähler dahinter war ein `DB::listen` auf **jeder** Query, und `isOverdue()` hat ihn nie gelesen.

`perf_alerts` ist das Gedächtnis: **Abklingzeit 6 h**, sonst käme derselbe Satz bei jedem Lauf erneut. Löst sich ein Befund auf, wird die Sperre zurückgesetzt — ein echter Rückfall ist eine neue Nachricht wert.

**Die Befunde stehen auf `/admin/system`**, wohin auch die Push-Nachricht zeigt. Eine zweite Admin-Seite neben der Systemseite war genau die Sorte Doppelung, die dieses Projekt teuer bezahlt hat. `findings()` ist ohne Nebenwirkung — Ansehen verbraucht keine Nachricht, `run()` schreibt die Abklingzeit fort.

**Der Wächter kann seinen eigenen Ausfall nicht melden**, denn er läuft selbst im Scheduler. Stirbt der Scheduler, stirbt er mit. Deshalb steht `schedule:run` in `expected_every_minutes` und wird beim **Seitenaufruf** ausgewertet, wo die Antwort ankommt — nicht in einer Push-Nachricht, die nie abgeschickt würde. Dieselbe Grenze gilt für Cloudflare: was den Edge nicht passiert, sieht Laravel nicht, und keine Messung im Container ändert daran etwas.

`config/observability.php` hält noch drei Dinge: `OBSERVABILITY_ENABLED` als Schalter, die Alarmschwellen, und die erwartete Taktung je Kommando.

## Telegram — der Coach im Messenger

Gewünscht: *"Der Coach Chat ist zu wenig interaktiv. Es sollte WhatsApp, Telegram oder ähnliches dabei sein."* Entscheidung vom 25.10.2026: erst nur Telegram, als Webhook in derselben Laravel-App.

**Der Coach antwortet nicht mehr im Request.** `CoachChatController::send` rief OpenAI samt Werkzeugen synchron auf — bei einem Einzelprozess-Webserver stand währenddessen die ganze Seite. Jetzt legt `send` nur die Frage an und startet `CoachReplyJob`; der Web-Chat fragt `coach.reply` ab, bis die Antwort da ist. Telegram braucht es ohnehin so: der Webhook muss sofort 200 liefern, sonst stellt Telegram das Update erneut zu.

**Ein Zustellweg, nicht zwei.** Jede Antwort ist eine `CoachMessage` mit `channel` (`web` / `telegram` / `NULL` = vom System, z. B. ein Review) und `reply_to_id`. `CoachMessageObserver` entscheidet: hat der Athlet Telegram verbunden und kam die Nachricht nicht aus dem Web-Chat → Telegram, sonst Web-Push. Wer Telegram hat, bekommt dieselbe Nachricht nicht zusätzlich als Push, und die Wellbeing-Erinnerung entfällt für ihn — das Morgen-Briefing ersetzt sie.

**Ein Entscheidungsweg, nicht zwei.** `SessionRecommendation::accept()` / `reject()` enthalten, was vorher im Controller stand. Dashboard und Telegram-Knöpfe (`rec:accept:{id}`) rufen dieselben Methoden. `SessionRecommendationObserver::created()` schickt den Vorschlag mit den Knöpfen — und brauchte dafür `$attributes['status']`: der Datenbank-Standard `pending` steht nach `create()` nicht im Modell, `isOpen()` war false und der Vorschlag ging nie raus.

Was der Bot schickt:

- **Morgen-Briefing** (`telegram:briefing`, jede Minute, feuert zur `wellbeing_reminder_time`, einmal pro Tag per Cache-Sperre) — die Einheit des Tages **ohne KI-Aufruf** aus `TelegramBriefing`. `/heute` zeigt dasselbe.
- **Reviews** nach jedem Lauf — über den Observer, ohne eigenen Code.
- **Wellbeing-Vorschläge** mit Annehmen/Ablehnen.
- Jede andere Nachricht geht an den Coach, mit denselben Werkzeugen und demselben Tageskontingent wie im Web.

Regeln, alle getestet in `TelegramCoachTest`:

- **Telegram signiert**, anders als Strava: jedes Update trägt `X-Telegram-Bot-Api-Secret-Token`. Ohne passendes Geheimnis gibt es 403. Ist `TELEGRAM_WEBHOOK_SECRET` nicht gesetzt, wird es aus `APP_KEY` abgeleitet.
- **Verknüpfung nur über einen Einmal-Code**: Profil → Verbindungen → Telegram öffnet `t.me/<bot>?start=<code>`. Ein fremder Chat bekommt nur die Anleitung, nie Daten, und erreicht keinen Coach.
- **Nur Einzelchats.** In einer Gruppe läsen andere mit, was der Coach über Ruhepuls und Schlaf schreibt.
- `update_id` wird einen Tag gemerkt — dasselbe Update wirkt nur einmal.

`startup.sh` registriert den Webhook bei jedem Deploy (`telegram:set-webhook`). **Cloudflare:** dieselbe Falle wie beim Strava-Webhook — `/telegram/webhook` braucht eine WAF-Skip-Regel, sonst blockt Bot Fight Mode Telegrams Server, und von innen sieht alles gesund aus. Prüfen mit `getWebhookInfo` (`last_error_message`).

## Push Notifications

VAPID-based Web Push (no Firebase). Subscriptions in `push_subscriptions`. Scheduler command `SendWellbeingReminders` runs every minute via polling loop. Expired subscriptions (HTTP 410) are auto-deleted.

## Environment Variables

```
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.6-sol
OPENAI_MODEL_MINI=gpt-5.6-luna

STRAVA_CLIENT_ID=
STRAVA_CLIENT_SECRET=
STRAVA_WEBHOOK_VERIFY_TOKEN=

# fit-service (Garmin). Der Token muss in BEIDEN Coolify-Diensten identisch
# gesetzt sein — der fit-service weist ohne ihn jeden Aufruf mit 401 ab und
# antwortet mit 503, solange er selbst keinen konfiguriert hat.
FIT_SERVICE_URL=
FIT_SERVICE_TOKEN=

# Telegram-Bot (@BotFather). Das Geheimnis ist optional — ohne wird es aus APP_KEY abgeleitet.
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=

VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:...

# Waechter (perf:watch, Befunde auf /admin/system). Alle optional — die
# Vorgaben in config/observability.php passen fuer zwei Athleten.
OBSERVABILITY_ENABLED=true
OBSERVABILITY_ALERT_COOLDOWN_HOURS=6
OBSERVABILITY_QUEUE_STALLED_MINUTES=15
OBSERVABILITY_WEBHOOK_SILENCE_HOURS=48
```

Die Variablen `OBSERVABILITY_SLOW_QUERY_MS`, `_SLOW_REQUEST_MS`, `_MANY_QUERIES`, `_SAMPLE_RATE`, `_RETENTION_DAYS` und `_SERVER_ERROR_BURST` sind mit der Anfrage-Messung weggefallen und können in Coolify gelöscht werden — sie werden nirgends mehr gelesen.
