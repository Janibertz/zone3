# Zone3 — Entwicklungs-Verlauf

## Abgeschlossen

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
- [ ] **Ziele-Seite** — Dedizierte `/goals` UI-Seite mit Fortschrittsanzeige (Cards + Balken)
- [ ] **Wellbeing-Seite** — Verlaufsansicht der Wellbeing-Einträge, Wochenübersicht
- [ ] **Trainingsplan-Seite** — Wochenansicht des generierten AI-Trainingsplans

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
