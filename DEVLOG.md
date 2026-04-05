# Zone3 — Entwicklungs-Verlauf

## Abgeschlossen

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

- [ ] **Aktivitäten-Detailseite** — Kartenansicht (Polyline), Herzfrequenz-Verlauf, Zonenverteilung pro Aktivität
- [ ] **Wellbeing-Seite** — Verlaufsansicht der Wellbeing-Einträge, Wochenübersicht
- [ ] **Event im Onboarding → Events-System verknüpfen** — onboarding `saveGoal` direkt als Event speichern statt als Goal
- [ ] **Plan-Anpassung nach neuen Aktivitäten** — automatisch Plan-Refresh vorschlagen wenn neue Strava-Daten eingehen

### Mittlere Priorität

- [ ] **Push-Benachrichtigungen / E-Mail-Reminders** — Tägliche Trainingsempfehlung (Queue Worker bereits eingerichtet)
- [ ] **Mobile-Optimierung** — Bessere Touch-Gesten, Bottom-Navigation für Mobile
- [ ] **Dark Mode persistenz** — Einstellung im Nutzerprofil speichern statt nur localStorage
- [ ] **Mehrsprachigkeit** (DE/EN)

### Ideen / Backlog

- [ ] Garmin / Polar Integration als Alternative zu Strava
- [ ] Wochen- und Monatszusammenfassung per E-Mail
- [ ] Wettkampfkalender / Rennanmeldung verwalten
- [ ] Vergleich mit anderen Nutzern (optional/opt-in)
- [ ] Apple Health / Google Fit Sync
