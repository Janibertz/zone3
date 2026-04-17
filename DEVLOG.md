# Zone3 — Entwicklungs-Verlauf

## Abgeschlossen

### 2026-04-17 — Dashboard Prio 3: Visual Polish

- **Größere KPI-Zahlen** im Hero-Block: `text-3xl font-black` statt `text-xl`, `uppercase tracking-wider` Labels — Garmin-Connect-Stil mit deutlich mehr visuellem Gewicht
- **Activity-Type-Icons:** `activityTypeIcon(type)`-Funktion gibt typspezifische Emojis zurück (🏃 Laufen, 🚴 Radfahren, 🏊 Schwimmen, 🚶 Gehen, 🥾 Wandern, 💪 Workout etc.); Icon erscheint links neben Aktivitätsnamen in "Letzte Läufe"
- **Ring-Progress beim Event-Countdown:** Kreisförmiger SVG-Ring ersetzt den Priority-Badge-Block bei "Nächste Events"; Füllstand zeigt wie nah das Event ist (0–180-Tage-Skala); Farbverlauf Indigo → Amber → Orange → Rot je näher das Rennen rückt; Prioritätsbuchstabe (A/B/C) bleibt im Mittelpunkt des Rings

### 2026-04-17 — Dashboard Prio 2: Layout-Restrukturierung + Hero-Karte

- **"Heute"-Block nach oben** verschoben — wichtigste Info (heutige Trainingseinheit) erscheint direkt nach den Flash-Bannern, noch vor Hero + Kalender
- **"Kein aktiver Plan"-Banner** ebenfalls nach oben gezogen — direkt vor dem Heute-Block
- **Profil + Stats zu einer Hero-Karte gemergt** (`lg:col-span-8`):
  - Dunkles Gradient-Header: Avatar / Name / Strava-Status / Buttons in einer Zeile
  - Zeile 2: Wochenstats-KPIs (km, Läufe, Ø Pace) + 7-Tage-Balkendiagramm nebeneinander
  - Heller Footer: Letzte 6 Läufe im 2-Spalten-Grid
- **Kalender** bleibt bei `lg:col-span-4` rechts daneben

### 2026-04-16 — Dashboard Bugfixes: Bewertungen + Strava-Namen + Duplikate

- **Bewertete Sessions weiterhin angezeigt (Root Cause 1):** `TrainingPlanController::formatSession()` lieferte `rating`, `effort_perceived`, `feeling_notes` nicht mit → Plan.vue initialisierte alle auf 0/null → ungeschützter Save-Button resetzte Bewertung auf null beim Öffnen; Fix: Felder in `formatSession()` ergänzt, Save-Button in Plan.vue deaktiviert wenn alle Felder leer
- **Bewertete Sessions weiterhin angezeigt (Root Cause 2):** Plan-Neugenerierung erstellte Duplikate — `alreadyLinked`-Check war plan-scoped, fand abgetrennte Sessions (training_plan_id=null nach nullOnDelete) nicht; Fix: Check auf user-wide umgestellt, bestehende Session wird re-linked statt neu erstellt
- **Cleanup-Migration** `2026_04_16_200000`: bereinigt vorhandene Duplikate (pro user_id+activity_id Gruppe: bewertete/älteste behalten, Rest gelöscht)
- **"Ungeplante Einheit" → Strava-Name:** `title`-Feld in allen 4 Erstellungs-Stellen (StravaController webhook + sync je 2×, TrainingPlanController retroaktives Matching) auf `$activity->name` / `$run->name` geändert
- **Migration** `2026_04_16_210000`: benennt bestehende "Ungeplante Einheit"-Einträge per SQL-UPDATE auf den echten Strava-Namen um
- **Inline-Rating vom Dashboard:** "Noch zu bewerten"-Karte zeigt klappbare Bewertungs-UI direkt auf dem Dashboard — Sterne (1–5), RPE (1–10), Freitext; PATCH an `/training-sessions/{id}/rate`; optimistisches Update (bereits bewertete IDs local gefiltert ohne Reload)

### 2026-04-09 — Strava Auto-Import + Session-Bewertung + KI-Lernschleife

- **Strava Webhook Registrierung:** Neuer Artisan-Command `strava:subscribe-webhook` registriert automatisch die Webhook-Subscription bei Strava. Env-Variablen `STRAVA_WEBHOOK_CALLBACK_URL` und `STRAVA_WEBHOOK_VERIFY_TOKEN` ergänzt
- **Push Notification bei Aktivitätsimport:** Nach jedem automatischen Strava-Webhook-Import bekommt der Nutzer eine Push Notification ("Neue Aktivität importiert 🏃 · Name · X km") — wenn Push aktiviert
- **Session-Bewertung:** Abgeschlossene Trainingseinheiten können bewertet werden direkt im Detail-Modal:
  - **Sterne (1–5):** Wie gut ist die Einheit gelaufen?
  - **RPE-Slider (1–10):** Empfundene Anstrengung (Rate of Perceived Exertion)
  - **Freitext-Notiz** (max. 300 Zeichen)
  - PATCH `/training-sessions/{session}/rate` Endpoint
  - Migration: Felder `rating`, `effort_perceived`, `feeling_notes` auf `training_sessions`
- **KI lernt aus Bewertungen:** Beim Generieren eines neuen Trainingsplans bekommt die KI die letzten 30 bewerteten Sessions als Kontext. Durchschnittsbewertung + RPE wird berechnet; niedrige Bewertungen/hoher RPE bei bestimmten Einheitstypen → KI plant diese leichter oder seltener; hohe Bewertungen → mehr davon

### 2026-04-09 — KI-Verpflegungstipps + Workout-Detail

- **KI-Verpflegungsplan:** Beim Öffnen des Session-Detail-Modals ruft die App OpenAI auf und generiert personalisierte Verpflegungstipps in drei Sektionen:
  - 🕐 **Vor dem Training/Rennen** — Timing von Mahlzeiten, Carb-Loading bei langen Läufen
  - 🏃 **Während des Trainings** — Hydration-Intervalle, Gel-Strategie mit Zeitangaben
  - ✅ **Nach dem Training** — Recovery-Fenster mit Protein + Kohlenhydraten
  - Ergebnisse werden pro Session im Browser gecacht (kein doppelter API-Call)
- **Race Day Erkennung:** Sessions am Renntag (`type === 'race'` oder `planned_date === event_date`) zeigen keine Warmup/Cooldown-Struktur
- **FIT-Workout-Download:** TCX durch natives Garmin FIT-Binärformat ersetzt — vollständiger PHP-FIT-Encoder mit CRC-16, distance-basierten Steps, Speed-Targets in mm/s, Warmup/Cooldown-Intensität
- **Workout-Struktur Detail:** Warmup / Hauptteil / Cooldown mit geschätzter Zeit pro Phase

### 2026-04-09 — Web Push Notifications

- **VAPID-Infrastruktur:** `minishlink/web-push` Library, VAPID-Keys (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`), `push_subscriptions` Tabelle
- **Profil-Tab "Benachrichtigungen":** Push aktivieren/deaktivieren, Test-Notification senden, Uhrzeit für Wellbeing-Erinnerung, Toggle für Schwellenpace-Benachrichtigung, Toggle für Plan-Update-Benachrichtigung
- **`WebPushService`:** `sendToUser()` sendet an alle aktiven Subscriptions des Nutzers, löscht abgelaufene (410-Antworten) automatisch
- **Scheduler:** `push:wellbeing-reminders` Command läuft jede Minute via `startup.sh`-Loop; erinnert Nutzer wenn Wellbeing noch nicht ausgefüllt und Uhrzeit passt
- **Automatische Benachrichtigungen:**
  - Nach Schwellenpace-Neuberechnung (`CalculateThresholdPaceJob`)
  - Nach KI-Plan-Generierung (`TrainingPlanController::generate()`)
  - Nach Strava-Aktivitätsimport via Webhook
- **Service Worker** (`public/sw.js`): `push`-Event-Handler mit Notification-Display, `notificationclick`-Handler öffnet/fokussiert den passenden Tab

### 2026-04-09 — Aktivitäten-Detailseite

- **`/activities/{activity}` Seite** (`Activities/Show.vue`): Leaflet-Karte mit decodierter Google-Polyline (grüner Start-, roter Endmarker), Stats-Grid (Distanz, Zeit, Pace, Höhe), Herzfrequenz-Anzeige, Pace-Zonen-Balken
- **Polyline-Fix:** Strava List-API gibt `summary_polyline` zurück (nicht `polyline`) → `extractPolyline()` Helper mit Fallback in `StravaController`
- **Pace-Zonen-Matching Fix:** `∞` (Zone 1 max) und `0:00` (Zone 5 min) wurden nicht korrekt gematcht — `paceToSeconds()` gibt `Infinity` für `∞` zurück, Fallback `?? 0` / `?? Infinity`

### 2026-04-08 — Queue Worker Fix + Scheduler + APP_NAME

- **`startup.sh`:** Kombinierter Startprozess für Coolify (Nixpacks unterstützt nur `web:` Procfile-Eintrag):
  - Migrations + Cache-Befehle
  - Queue Worker in Restart-Loop (Background)
  - Scheduler-Loop alle 60 Sek. (Background)
  - PHP Dev-Server im Foreground
- **APP_NAME** auf `Zone3` gesetzt

### 2026-04-07 — Plan-Ansicht + Session-Detail + Wellbeing-Banner

- **Plan.vue zeigt nur zukünftige Sessions:** Filter auf `planned_date >= heute`
- **Wellbeing-Banner Dashboard:** Auffälliges gelbes Puls-Banner wenn Wellbeing heute noch nicht ausgefüllt; verschwindet nach dem Ausfüllen ohne Seitenreload
- **Session-Detail Modal:** Warmup/Hauptteil/Cooldown-Aufschlüsselung mit km, Pace und geschätzter Zeit pro Phase; konfigurierbar pro Session-Typ
- **Ein-aktiver-Plan-Regel:** Nur ein Plan gleichzeitig aktiv; Plan abbrechen-Modal mit Bestätigung

### 2026-04-07 — KI-Plan: Strava-Matching, Event-Cutoff, Pace-Daten

- **Strava-Aktivität ersetzt geplante Session:** Wenn ein Lauf aus Strava importiert wird, werden Distanz, Dauer und Pace der geplanten Session mit den echten Strava-Daten überschrieben (statt nur Status auf "completed" setzen)
- **Ruhetag-Handling:** War für einen Tag ein Ruhetag geplant und der Athlet ist trotzdem gelaufen → Ruhetag wird gelöscht, echte Einheit wird als "Ungeplante Einheit" (abgeschlossen) eingefügt
- **Plan endet am Renntag:** KI-Prompt enthält explizite Anweisung, keine Sessions nach dem Event-Datum zu planen; zusätzlicher serverseitiger Filter entfernt eventuell trotzdem generierte Post-Event-Sessions
- **Renntag ist letzter Tag:** Am Event-Datum selbst wird `race_prep`-Session mit dem Event-Namen generiert
- **`needs_plan_update` Flag:** Wird gesetzt wenn ein neuer Strava-Lauf einem Plan zugeordnet wird; Banner in `Plan.vue` informiert Nutzer und bietet direkt "Plan aktualisieren"-Button
- **Pace aus Strava:** `paceFromSpeed()` Helper berechnet aus Strava `average_speed` (m/s) den Pace-String (min:sec/km); wird bei Ungeplanten Einheiten und Retro-Matching gespeichert
- **Error Handling in `generate()`:** Try-catch-Blöcke mit spezifischen Fehlermeldungen (OpenAI-Fehler vs. Datenbankfehler) statt generischem Fallback
- **`DB::table()` für `is_active` + `needs_plan_update`:** Direkte DB-Schreiboperationen umgehen Fillable-Beschränkungen des Models
- **Retroaktives Matching bei Plan-Generierung:** Nach Erstellen aller AI-Sessions werden vorhandene Strava-Läufe im Plan-Zeitfenster automatisch den passenden Sessions zugeordnet

### 2026-04-07 — Strava Disconnect + Support-Seite

- **Strava trennen** im Profil (Tab: Konto): Button öffnet Bestätigungsmodal mit Hinweis dass alle importierten Aktivitäten gelöscht werden
- **`StravaController::disconnect()`:** Löscht `StravaAccount` + alle `activities` des Users; leitet zurück mit Status `strava-disconnected`
- **`ProfileController::edit()`:** Übergibt `stravaConnected` und `stravaAccount` (Username, letzter Sync) an die View
- **Route** `DELETE /strava/disconnect` → `strava.disconnect`
- **Support-Seite** `/support` — öffentlich ohne Login; Kontakt, Strava-Datenschutz, Datenschutzerklärung, Disconnect-Anleitung; Verantwortlicher: Jan Anders (jan.anders@me.com)
- **Footer** auf der Landingpage: "Support & Datenschutz" Link

### 2026-04-06 — Strava-Aktivitäten → Trainingsplan Matching

- **`TrainingSession.activity_id`:** Neue Migration + FK auf `activities`; verknüpft eine abgeschlossene Session mit der zugehörigen Strava-Aktivität
- **`StravaController::matchActivityToSession()`:** Wird nach jedem Run-Import aufgerufen (sync + webhook); sucht passende geplante Session am gleichen Tag → markiert als erledigt + verknüpft Aktivität; kein Match → erstellt "Ungeplante Einheit" als abgeschlossene Session im aktiven Plan
- **Duplikat-Schutz:** Prüft ob für diese `activity_id` bereits eine Session existiert bevor eine neue angelegt wird
- **Re-Sync sicher:** Matching läuft für alle Runs (nicht nur neue) — idempotent durch Duplikat-Guard
- **Plan.vue:** 🔗 Strava-Badge bei Sessions mit verknüpfter Aktivität; gelbes Warn-Banner bei "Ungeplante Einheit" mit Hinweis "Nicht im Plan – automatisch aus Strava importiert"
- **Nixpacks-Fix:** `nixpacks.toml` entfernt — Coolify's eigene Node 22 Konfiguration wird genutzt (Vite 8 erfordert Node ≥ 20.19)

### 2026-04-05 — Dashboard Stats-Box + Aktivitäten + Admin überarbeitet

- **Stats-Box** (Dashboard Mitte) komplett neu — ersetzt sinnlosen Donut + Buchstaben-Dots:
  - 3 KPI-Kacheln: km diese Woche / Läufe diese Woche / Ø Pace (farbig: indigo/grün/lila)
  - Balkendiagramm letzte 7 Tage mit echten km-Werten, Mo–So Labels, Heute hervorgehoben
  - Footer: Dieser Monat (km + Läufe) und Gesamt-km
- **Aktivitätsanzeige** (Dashboard oben links) überarbeitet:
  - Pill-Layout mit farbigen Badges: Distanz / Zeit / Pace / ❤️ HF / ↑ Höhenmeter
  - Pace farbkodiert: grün <4:30, blau <5:30, gelb <6:30, orange langsamer
  - Relativer Zeitstempel: "Heute", "Gestern", "vor 3 Tagen" etc.
- **Quick-Event-Widget** — "Ziel hinzufügen" ersetzt durch schnelles Event-Erstell-Formular:
  - Distanz-Buttons (5km/10km/HM/Marathon), Datum, Zielzeit (h+min), Priorität A/B/C
  - Speichert direkt über Events-API, Events-Liste wird nach Save neu geladen
- **Dashboard Crash-Fix** — `props.goals` war an 5+ Stellen noch referenziert nach Migration auf `props.events` → TypeError → weiße Seite; alle Stellen auf `props.events` umgestellt
- **Dark-Mode-Flash behoben** — Inline-Script in `app.blade.php` liest localStorage vor Vue-Mount; `<html class="dark">` als Fallback
- **Admin Dashboard** — "Aktive Ziele" → "Geplante Events", "Ziele gesamt" → "Events gesamt"; Backend nutzt `Event::count()` statt `Goal::count()`
- **`relativeDate()`** und **`paceColor()`** Hilfsfunktionen ergänzt
- **`weekStats`**, **`monthStats`**, **`last7DaysBars`**, **`last7DaysMax`** Computed Properties ergänzt

### 2026-04-05 — Ziele/Goals → Events gemergt + Kalender-Seite

- **Event-Model** — `training_phase` Accessor: berechnet automatisch Base/Build/Peak/Taper/Race Week anhand Wochen bis Event; `weeks_until` Accessor
- **Onboarding `saveGoal`** — speichert direkt als Event (Priorität A) statt als altes Goal
- **Dashboard Backend** — übergibt `events` statt `goals`; lädt die nächsten Events mit Training Phase
- **Dashboard Kalender** — Woche beginnt jetzt Montag (Mo–So); Monatsnavigation (‹ ›); Events werden orange im Kalender markiert; Tooltip mit Event-Name; Link zur Vollansicht
- **Dashboard Aktivitäten** — bessere Darstellung mit Pace (min/km), Herzfrequenz, "Alle anzeigen →" Link; `formatPaceFromSpeed()` Funktion ergänzt
- **Dashboard Events** — "Nächste Ziele" ersetzt durch "Nächste Events" mit Priority-Badge, Training-Phase-Badge und Countdown
- **Quick-Actions** — "Ziel hinzufügen" → "Event planen" (Link zu /events)
- **Kalender-Seite** `/calendar` — Vollansicht mit Monatsnavigation, Monday-first Grid, Aktivitäten und Events je Tag, Sidebar-Panel mit Details, Trainingsphase-Legende, mobile Detail-Panel
- **Navigation** — "Kalender" Menüpunkt ergänzt (Desktop + Mobile)

### 2026-04-05 — Event-System + KI-Trainingsplan
- **Migration** `events`-Tabelle: Name, Datum, Renndistanz, Priorität (A/B/C), Zielzeit, Notizen
- **Migration** `training_plans`-Tabelle: 10 Tages-Sessions als JSON + Kontext-Snapshot
- **Event-Model** mit Accessors für `distance_label`, `target_time_formatted`, `days_until`
- **TrainingPlan-Model** + Beziehungen zu User und Event
- **EventController** — CRUD (index, store, update, destroy), Prio-Sortierung A→B→C
- **TrainingPlanController** — `show()` Planansicht + `generate()` KI-Plan erstellen
- **OpenAIService::generateEventTrainingPlan()** — analysiert Aktivitäten (4 Wochen), Wellbeing (14 Tage) + Athletenprofil; gibt strukturiertes JSON-Array mit 10 Sessions zurück; passt Intensität automatisch an Tage bis zum Rennen an (Tapering)
- **Events/Index.vue** — Eventliste mit A/B/C Prioritäts-Filter, farbige Priority-Badges, Tage-bis-Event Anzeige, Erstellen/Bearbeiten-Modal, Löschen mit Bestätigung
- **Events/Plan.vue** — 10-Tages-Plan Ansicht mit Sessiontyp-Icons (Ruhetag, Easy, Tempo, Intervall, Langer Lauf, Rennvorbereitung), Distanz/Dauer/Pace/Zone pro Session, "Heute"-Highlight, Plan aktualisieren-Button
- **Navigation** — "Events" Menüpunkt in Sidebar/Mobile-Nav ergänzt
- **Profil** — "Onboarding neu starten" Button im Konto-Tab ergänzt (`POST /onboarding/reset`)

### 2026-04-04 — Admin-Bereich
- **Migration** `is_admin` (boolean) und `is_active` (boolean) auf der `users`-Tabelle
- **Middleware** `EnsureUserIsAdmin` — 403 Abort wenn kein Admin
- **Artisan Command** `php artisan admin:make {email}` — ersten Admin vergeben
- **Routes** `/admin/*` als eigene `routes/admin.php`, Middleware-Stack `web + auth + admin`
- **AdminDashboardController** — Statistiken, Registrierungen/Aktivitäten pro Monat, letzte Nutzer
- **AdminUserController** — Liste (Suche + Filter), Detailansicht, Admin/Aktiv-Toggle, Löschen
- **AdminLayout.vue** — eigenständiges Layout mit rotem Akzent, "Zurück zur App"-Link
- **Admin/Dashboard.vue** — Stat-Cards, CSS-Balkendiagramme (ohne externe Lib), Nutzertabelle
- **Admin/Users/Index.vue** — paginierte Nutzerliste, Filter, Aktionen mit Bestätigungsmodal
- **Admin/Users/Show.vue** — vollständige Nutzerdetails (Profil, Aktivitäten, Ziele, Wellbeing)
- **AuthenticatedLayout.vue** — Admin-Link am Ende der Navigation (nur für Admins sichtbar)
- `isAdmin` in Inertia Shared Props für alle Seiten verfügbar

### 2026-04-04 — Onboarding überarbeitet (KI-Profil + Wettkampfziel)
- **Schritt 2 komplett überarbeitet**: Auswahl zwischen "Ich kenne meine Werte" und "KI berechnen lassen"
  - KI-Modus fragt Alter, beste Wettkampfzeit + Wochenlaufumfang → OpenAI schätzt LTHR, Max HF, Schwellentempo
  - Ergebnis wird zur Bestätigung angezeigt vor dem Speichern (kein blindes Übernehmen)
- **Schritt 3 komplett überarbeitet**: Wettkampf-orientiertes Ziel mit Zielzeit
  - Renndistanz wählen (5km, 10km, Halbmarathon, Marathon, Eigene Distanz)
  - Datum des Rennens + Stunden:Minuten Zielzeit
  - Vorschau-Karte "Halbmarathon in 1 Std. 40 Min. am 15. Juni 2026"
  - Name wird automatisch aus Distanz + Datum vorgeschlagen
- `OpenAIService::estimateProfileFromRaceData()` — neuer KI-Endpunkt für Zonenberechnung
- `OnboardingController::estimateProfile()` — neuer API-Endpunkt `/onboarding/estimate-profile`
- Ziel-Validierung angepasst: `race_date` statt separatem start/end_date, race_distance als Metadaten

### 2026-04-04 — Onboarding-Flow für neue Nutzer
- **Migration** `onboarding_completed_at` auf der `users`-Tabelle
- **Middleware** `EnsureOnboardingComplete` — leitet unangemeldete Nutzer auf `/onboarding` um
- **Controller** `OnboardingController` mit Endpunkten für Profil, Ziel, Abschluss und Strava-Weiterleitung
- **Vue-Seite** `Onboarding.vue` — 4-Schritt-Wizard (Willkommen → Athletenprofil → Erstes Ziel → Strava)
- Registrierung leitet nach `/onboarding` statt direkt zum Dashboard
- Middleware-Alias `onboarding` auf Dashboard- und Auth-Routen registriert

### 2026-03-xx — Landing Page Redesign
- Modernes Dark-Hero-Layout für die Startseite

### 2026-03-xx — Infrastruktur & Deployment
- Coolify/Nixpacks: Procfile für Queue Worker
- HTTPS hinter Reverse Proxy (Trust all proxies)
- Datenbank-Migration von SQLite auf MySQL

### 2026-03-20 — Initiales Setup
- Laravel + Vue 3 + Inertia.js
- Strava-Integration (OAuth, Aktivitäten-Sync, Webhook)
- Aktivitäts-Tracking
- Ziel-Verwaltung (Distanz, Zeit, Häufigkeit)
- Wellbeing-Tracking (Energie, Schlaf, Muskelkater, Stress)
- Athletenprofil mit Herzfrequenz- und Tempo-Zonen (5 Zonen)
- AI-Trainingsempfehlungen via OpenAI (Tagesempfehlung, Ziel-Analyse, Trainingsplan)
- Runner Profile mit Schwellentempoberechnung und Race Predictions
- Statistiken-Dashboard

---

## Geplant

### Nächste Schritte (hoch priorisiert)

- [ ] **Wellbeing-Verlaufsseite** — Grafische Auswertung der Wellbeing-Einträge über Zeit
- [ ] **Plan-Wochensicht** — Kalenderansicht des Plans statt reine Listendarstellung
- [ ] **Strava Extended Access** — Beantragung ausstehend (Support-Seite + Privacy-Seite erstellt)
- [ ] **Garmin Connect direkt hochladen** — OAuth-Integration statt manueller FIT-Download
- [ ] **Session-Bewertung in Aktivitäten-Ansicht** — Rating auch direkt aus der Aktivitäten-Liste heraus

### Mittlere Priorität

- [ ] **Mobile-Optimierung** — Bessere Touch-Gesten, Bottom-Navigation für Mobile
- [ ] **Dark Mode persistenz** — Einstellung im Nutzerprofil speichern statt nur localStorage
- [ ] **Mehrsprachigkeit** (DE/EN)
- [ ] **Wochen- und Monatszusammenfassung** per Push / E-Mail

### Ideen / Backlog

- [ ] Garmin / Polar Integration als Alternative zu Strava
- [ ] Wettkampfkalender / Rennanmeldung verwalten
- [ ] Vergleich mit anderen Nutzern (optional/opt-in)
- [ ] Apple Health / Google Fit Sync
- [ ] Langzeit-Lernkurve: Schwellenpace-Verlauf als Chart im Profil
