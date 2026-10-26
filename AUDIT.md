# Audit Zone3 — 26.10.2026

Branch `audit/fixes`, Stand `main` @ `1b12fe0`. **Nur Bericht, noch nichts geändert.**

Grundlage: alle Routen (`routes/*.php`), alle Controller, Middleware, Models
(Fillable/Hidden/Casts), Jobs, Scheduler, `Dockerfile`, `startup.sh`, Config,
die Inertia-Props der wichtigsten Seiten und alle `v-html`-Stellen im
Frontend. Tests, `composer audit`, `npm audit`, Pint und Build siehe
„Werkzeuge" am Ende.

**Ermittelt, nicht angenommen:** Laravel 13 (`laravel/framework ^13.0`),
Inertia 2, Vue 3, Vite 8, Tailwind 3, PHP `^8.4` (Docker `php:8.4-cli-bookworm`),
Sanctum installiert aber ohne API-Routen, kein Larastan/PHPStan, Pint als
Dev-Abhängigkeit vorhanden. Keine Policies — Autorisierung läuft überall als
`abort_if($model->user_id !== Auth::id(), 403)` im Controller.

**Was gut ist (damit die Liste nicht schiefer wirkt als die Lage):** Die
Besitzprüfung ist in allen 40+ Routen mit Model-Binding vorhanden, inklusive
`exists`-Fallen (PlanInterview prüft fremde Events nach). Die Coach-Werkzeuge
scopen jede Abfrage auf `user_id`. `HandleInertiaRequests` teilt eine Whitelist
statt des User-Models, `garmin_session` ist verschlüsselt, Telegram prüft das
Secret mit `hash_equals`, der fit-service verlangt seinen Token, Login ist
gedrosselt (5 Versuche), `APP_DEBUG` ist per Default aus.

Schwere: **Kritisch** = ausnutzbar und schadet sofort · **Hoch** = ausnutzbar
oder Datenverlust unter realen Bedingungen · **Mittel** = Schwachstelle mit
Voraussetzung oder spürbarer Betriebsfehler · **Niedrig** = Härtung, Hygiene.
Aufwand: S < 1 h · M ½ Tag · L > 1 Tag.

---

## Kritisch

Keine. Ich habe keinen Weg gefunden, mit dem Nutzer A ohne Zutun von B Daten
von B lesen oder ändern kann.

---

## Hoch

### H1 · Gespeichertes XSS im Coach-Chat — trifft den Admin bei „Als … ansehen"
- **Datei:** `resources/js/Components/CoachSlideOver.vue:428`
- **Problem:** `v-html="msg.role === 'assistant' && !msg.isError ? renderMarkdown(msg.content) : msg.content"` — Nachrichten des **Athleten** (und Fehlermeldungen) gehen **ungefiltert** als HTML in die Seite. Nur die Coach-Antworten laufen durch `renderMarkdown()`, das escaped.
- **Risiko:** Ein Athlet schreibt `<img src=x onerror="…">` in den Chat (Web oder Telegram — beides landet in `coach_messages.content`). Öffnet ein Admin per Impersonation den Chat, läuft das Skript in seinem Browser. Während der Übernahme hat die Sitzung zwar keine Admin-Rechte, aber das Skript kann `POST /impersonate/stop` auslösen (CSRF-Token liegt im `XSRF-TOKEN`-Cookie) und danach als Admin handeln: Rechte vergeben, Nutzer löschen, AI-Logs mit Gesundheitsdaten aller Athleten lesen.
- **Lösung:** Nutzertext mit `{{ }}` rendern (oder ebenfalls durch die Escape-Funktion), `v-html` nur für die escapte Markdown-Ausgabe. Test: Komponente rendert `<img onerror>` als Text.
- **Aufwand:** S

### H2 · Gefälschter Strava-Webhook löscht echte Aktivitäten
- **Datei:** `app/Http/Controllers/StravaController.php:425` → `webhookDelete()` (`:316`)
- **Problem:** Für `aspect_type = delete` wird dem Rumpf geglaubt. Es wird **nicht** bei Strava nachgefragt, ob die Aktivität wirklich weg ist. Strava signiert nicht, die Route hat weder Token noch Throttle (bewusst, siehe CLAUDE.md). `owner_id` (Athleten-ID) und `object_id` (Aktivitäts-ID) stehen in jeder öffentlichen Strava-URL.
- **Risiko:** Ein einziger anonymer `POST /strava/webhook` mit `{"object_type":"activity","aspect_type":"delete","owner_id":…,"object_id":…}` löscht die Aktivität, setzt die erledigte Einheit zurück, löscht Review und Bewertung und legt einen **Grabstein** an — der Lauf kommt auch über den 15-Minuten-Abgleich nie wieder. CLAUDE.md sagt „a forged call cannot inject invented data"; für `create`/`update` stimmt das, für `delete` nicht.
- **Lösung:** Vor dem Löschen `fetchActivity()` aufrufen und nur löschen, wenn Strava mit **404** antwortet (nicht bei 401/429/5xx). Dafür muss `fetchActivity()` den Status unterscheiden können (heute `null` für jeden Fehler). Zusätzlich `subscription_id` aus dem Rumpf gegen die bekannte Subscription prüfen (blockt keine echten Aufrufe, kostet nichts). Test: gefälschter Delete bei existierender Aktivität ändert nichts.
- **Aufwand:** S–M

### H3 · Plan-Neubau ohne Transaktion — ein Absturz hinterlässt keinen aktiven Plan und verwaiste Einheiten
- **Dateien:** `app/Jobs/RegeneratePlanJob.php:246–330`, `app/Jobs/GenerateEventTrainingPlanJob.php:110–145`
- **Problem:** Erst werden geplante Einheiten und **alle** Pläne des Events gelöscht (`training_plan_id` → `nullOnDelete`), dann ein neuer Plan angelegt, dann werden erhaltene/erledigte Einheiten umgehängt, dann neue geschrieben. Kein `DB::transaction`. Bricht der Job dazwischen ab (Exception, Worker-Timeout, Deploy-Neustart mitten im Lauf — `tries = 1`), bleibt der Athlet ohne aktiven Plan, und seine erledigten Einheiten hängen an `training_plan_id = NULL`.
- **Risiko:** Genau das Bild der „68 verwaisten Einheiten" vom 09.10. — die Migration hat die Symptome repariert, die Ursache steht noch. Bei jedem Coolify-Deploy, der in einen laufenden Plan-Job fällt (30–70 s, manchmal Minuten), kann es wieder passieren.
- **Lösung:** Den Block „Replace plan in DB" (nach dem Modellaufruf, vor den Push-Nachrichten) in `DB::transaction()` legen. Der Modellaufruf bleibt außerhalb, damit die Transaktion kurz ist. Test: Exception nach dem Löschen → alter Plan und Einheiten unverändert.
- **Aufwand:** M (zwei Jobs, viel Code dazwischen — sorgfältig, nicht schwierig)

### H4 · Scheduler verliert Minuten — Erinnerungen, Briefings und Tagesjobs fallen still aus
- **Datei:** `startup.sh:48–53`
- **Problem:** `while true; do php artisan schedule:run; sleep 60; done`. Jeder Durchlauf dauert 60 s **plus** die Laufzeit von `schedule:run` — und die ist nicht null: `push:wellbeing-reminders`, `telegram:briefing` und `livetrack:poll` laufen jede Minute im Vordergrund, `strava:sync` alle 15 Minuten mit HTTP-Aufrufen pro Konto. Die Startzeit wandert, und alle paar Dutzend Minuten wird **eine ganze Minute übersprungen**.
- **Risiko:** `push:wellbeing-reminders` und `telegram:briefing` vergleichen die Uhrzeit **minutengenau** (`TIME_FORMAT(...) = ?`, `SendTelegramBriefings.php:43`). Fällt die Minute aus, gibt es an dem Tag keine Erinnerung und kein Briefing. Dasselbe trifft `dailyAt`/`weeklyOn`: `plan:write-week` (So 19:00), `plan:auto-update` (05:00), `garmin:sync-health`, `perf:watch`. Kein Fehler, kein Log — es passiert einfach nichts. ⚠️ Berührt den anderen Thread, der gerade an einer Telegram-Tagesform-Erinnerung arbeitet.
- **Lösung:** `php artisan schedule:work` statt der Schleife (Laravel-eigen, läuft auf die Minute ausgerichtet, startet `schedule:run` als Unterprozess). Zusätzlich die langsamen Kommandos (`strava:sync`, `livetrack:poll`) mit `->runInBackground()`. Keine neue Abhängigkeit.
- **Aufwand:** S

---

## Mittel

### M1 · KI-Tageslimit greift nur im Chat — alle anderen KI-Wege sind unbegrenzt
- **Dateien:** `app/Services/AI/OpenAIClient.php:68` (`isRateLimited()` wird nur in `CoachChatController:73` und `TelegramWebhookController:140` gefragt)
- **Problem:** `ai_daily_limit` (Default 20, im Admin einstellbar) bremst nur den Coach-Chat. Ungebremst und **synchron im Request**: `POST /api/ai/recommendation/adjust` (ohne Cache, nimmt ein beliebiges `current`-Array ungeprüft in den Prompt), `POST /training-sessions/{id}/adjust-intensity`, `/adjust`, `POST /onboarding/estimate-profile`, `GET /api/ai/recommendation/today` und `/api/ai/daily-message` (ohne Runner-Profil wird nie gecacht), `GET …/nutrition-tips`, `…/steps`, `race-strategy`, `race-analysis`, `/api/wrapped/review` (gecacht, aber bei leerer Antwort jedes Mal neu).
- **Risiko:** Ein Skript in einer angemeldeten Sitzung ruft `adjust` in Schleife: echte Kosten (bzw. Verbrauch der Gratis-Token) und — wichtiger — **der Webserver steht**, weil jeder Aufruf bis zu 30 s blockiert. Bei offener Registrierung (siehe M7) braucht es dafür kein bestehendes Konto. Mehrere davon sind GET-Routen, die Geld kosten — genau das, was CLAUDE.md als Konstruktionsfehler beschreibt.
- **Lösung:** (a) Laravel-RateLimiter `throttle:ai` (z. B. 10/min pro User) als Middleware auf alle KI-Routen, (b) `isRateLimited()` zentral in `OpenAIClient::chat()` prüfen, wenn ein `userId` gesetzt ist. Die Umstellung der GET-Routen auf Job + Polling ist größer und gehört in einen eigenen Schritt (Vorschlag, nicht Teil dieses Audits).
- **Aufwand:** S (Throttle) / M (zentrale Prüfung inkl. Rückgaben `null` sauber behandeln)

### M2 · SSRF über Web-Push-Endpunkt
- **Datei:** `app/Http/Controllers/PushSubscriptionController.php:14`
- **Problem:** `endpoint` wird nur als `string` validiert. Beim Senden schickt `minishlink/web-push` einen POST an genau diese URL — aus dem Container heraus.
- **Risiko:** Ein angemeldeter Nutzer trägt `http://fit-service:8000/…`, `http://169.254.169.254/…` oder einen anderen internen Coolify-Dienst ein und lässt den Server dorthin POSTen (Push-Test-Knopf). Die Antwort sieht er nicht (blind), aber interne Dienste ohne Auth werden erreichbar.
- **Lösung:** `endpoint` als `url:https` validieren und den Host gegen die bekannten Push-Dienste prüfen (`fcm.googleapis.com`, `*.push.apple.com`, `updates.push.services.mozilla.com`, `*.notify.windows.com`). Bestehende Abos mit anderem Host beim Senden überspringen.
- **Aufwand:** S

### M3 · Strava-OAuth ohne `state` (Login-CSRF)
- **Dateien:** `app/Http/Controllers/StravaController.php:26–50`, `app/Services/StravaService.php:23`
- **Problem:** `connect()` ruft `getAuthorizationUrl()` ohne `state`, `callback()` prüft keinen.
- **Risiko:** Ein Angreifer schickt einem angemeldeten Athleten einen Link `zone3.run/strava/callback?code=<Code seines eigenen Strava-Kontos>` → das Zone3-Konto des Opfers ist mit dem Strava des Angreifers verbunden. Folge: fremde Aktivitäten im Plan, Schwellenpace und Umfang falsch. Kein Datenabfluss, aber Manipulation.
- **Lösung:** `state = Str::random(40)` in die Session, im Callback mit `hash_equals` prüfen. Test dazu.
- **Aufwand:** S

### M4 · Deaktivierte Konten können sich weiter anmelden
- **Dateien:** `app/Http/Controllers/Admin/AdminUserController.php:134`, `app/Http/Requests/Auth/LoginRequest.php:44`
- **Problem:** „Konto deaktivieren" setzt `is_active = false`, aber weder Login noch eine Middleware prüfen das Feld. Nur der Kalender-Feed tut es. Bestehende Sitzungen laufen ebenfalls weiter, Telegram-Chat auch.
- **Risiko:** Der Admin glaubt, er hätte jemanden ausgesperrt.
- **Lösung:** `Auth::attempt([... , 'is_active' => true])` bzw. Prüfung nach dem Login, plus eine kleine Middleware im `web`-Stack, die eine laufende Sitzung eines deaktivierten Kontos beendet. Telegram-Webhook ebenfalls prüfen.
- **Aufwand:** S

### M5 · GitHub-Webhook ist offen, wenn das Secret fehlt
- **Datei:** `app/Http/Controllers/WebhookController.php:14`
- **Problem:** `if ($secret) { …prüfen… }` — ohne `GITHUB_WEBHOOK_SECRET` wird jeder Aufruf angenommen. Jeder Push-Event löst einen OpenAI-Aufruf aus (`generateChangelogSummary`) und schreibt in `wiki_changelogs`.
- **Risiko:** Anonymes Auslösen von KI-Aufrufen und Fälschen des Admin-Changelogs. Ob das Secret in Coolify gesetzt ist, kann ich nicht sehen → **Frage 1**.
- **Lösung:** Fail closed: ohne Secret 503/404.
- **Aufwand:** S

### M6 · Strava-Tokens liegen im Klartext in der Datenbank
- **Datei:** `app/Models/StravaAccount.php`
- **Problem:** `access_token`/`refresh_token` ohne `encrypted`-Cast, ohne `$hidden`. `garmin_session` daneben ist verschlüsselt — das Muster gibt es also schon.
- **Risiko:** Jeder DB-Dump (Coolify-Backup, lokaler Import — CLAUDE.md beschreibt genau diesen Ablauf) enthält funktionierende Tokens mit `activity:read_all`. Ein `toArray()` auf dem Model würde sie ans Frontend geben (heute passiert das nirgends).
- **Lösung:** Cast `encrypted` + `$hidden`. **Braucht eine neue Migration** (Spalten auf `text`, `encrypted` passt nicht in `varchar(255)`) und eine einmalige Verschlüsselung der Bestandszeilen → **Rückfrage vor Umsetzung**, siehe Frage 2. Danach ist ein DB-Dump ohne passenden `APP_KEY` für Strava wertlos; die SQL-Zeilen zum Nullen in CLAUDE.md bleiben trotzdem sinnvoll.
- **Aufwand:** M

### M7 · Offene Registrierung ohne Drosselung und ohne E-Mail-Bestätigung
- **Dateien:** `routes/auth.php:15`, `app/Models/User.php` (`MustVerifyEmail` auskommentiert)
- **Problem:** `POST /register` ist öffentlich, ungedrosselt, und `verified` auf den Routen ist wirkungslos, weil `User` `MustVerifyEmail` nicht implementiert.
- **Risiko:** Massen-Konten, die alle KI-Wege aus M1 nutzen können. Jedes neue Konto bekommt 20 Chat-Aufrufe/Tag.
- **Lösung:** Je nach Absicht (**Frage 3**): Registrierung schließen bzw. per Einladung, oder `throttle:5,60` auf `register`/`forgot-password` und `MustVerifyEmail` aktivieren (Mailversand muss dann funktionieren).
- **Aufwand:** S

### M8 · `retry_after` (90 s) liegt unter der Job-Laufzeit (bis 1800 s)
- **Dateien:** `config/queue.php:43`, `startup.sh:38`
- **Problem:** Laravel verlangt `retry_after` > längster `--timeout`. Heute hält es nur, weil genau **ein** Worker läuft — der zweite Worker (oder ein überlappender beim Deploy) holt einen Plan-Job nach 90 s erneut, und er läuft doppelt (doppelter Modellaufruf, Löschen/Anlegen gegeneinander, siehe H3).
- **Lösung:** `DB_QUEUE_RETRY_AFTER=1900` in Coolify, oder den Default in `config/queue.php` anheben. Keine Migration.
- **Aufwand:** S

### M9 · Strava-Zeitstempel werden in UTC gespeichert und als Berliner Zeit gelesen — **bestätigt**
- **Dateien:** `app/Services/StravaImportService.php:65`, `StravaController.php:114`
- **Problem:** `start_date` kommt von Strava als `2026-10-26T05:30:00Z`. Eloquent formatiert es in der Zeitzone des Strings (UTC) und schreibt `05:30:00`; gelesen wird es als `Europe/Berlin` (`config/app.php` Default). Die Aktivität steht dann 1–2 h zu früh.
- **Risiko:** Ein Lauf zwischen 0 und 2 Uhr landet am **Vortag** — falsche Zuordnung zur geplanten Einheit, falscher Tag im Wochenumfang. Anzeigezeiten sind um die Sommer-/Winterzeit verschoben.
- **Lösung:** `Carbon::parse($data['start_date'])->setTimezone(config('app.timezone'))` beim Import, oder Strava `start_date_local` verwenden. Bestandsdaten bräuchten eine Korrektur → Rückfrage.
- **Nachgewiesen:** `start_date = 2026-10-25T23:30:00Z` (= 26.10. 00:30 Berlin) landet als `2026-10-25 23:30:00` in der Datenbank, `toDateString()` liefert den **25.10.**
- **Aufwand:** S (Code) / M (mit Bestandskorrektur)

### M10 · Admin-Änderungen an Coaches werden bei jedem Deploy überschrieben
- **Dateien:** `startup.sh:7`, `database/seeders/CoachSeeder.php:56`
- **Problem:** `CoachSeeder` macht `updateOrCreate(['slug'], $data)` inkl. `personality_prompt`, und läuft bei jedem Start. Der Coach-Editor unter `/admin/coaches` speichert in dieselben Spalten.
- **Risiko:** Jede Änderung im Admin hält genau bis zum nächsten Push auf `main`. → **Frage 4**: Ist der Seeder die Wahrheit (dann den Editor auf „nur ansehen"), oder der Editor (dann `firstOrCreate`)?
- **Aufwand:** S

### M11 · Telegram-Nachrichten umgehen das Tageslimit, solange die Antworten noch ausstehen
- **Datei:** `app/Http/Controllers/TelegramWebhookController.php:138–156`
- **Problem:** Das Limit zählt `ai_logs` mit `status = success` — die entstehen erst, wenn der Job gelaufen ist. 50 Nachrichten in einer Minute passieren alle die Prüfung und erzeugen 50 Jobs, die nacheinander die einzige Queue belegen (Plan-Bau wartet dahinter).
- **Lösung:** Zusätzlich zählen, wie viele Fragen offen sind (`coach_messages` ohne Antwort), oder `RateLimiter::attempt` pro Chat.
- **Aufwand:** S

### M12 · `php artisan serve` als Produktions-Webserver
- **Datei:** `startup.sh:57`
- **Problem:** Der PHP-Entwicklungsserver ist laut PHP-Doku nicht für Produktion gedacht; `PHP_CLI_SERVER_WORKERS` macht ihn mehrprozessig, aber ohne Request-Timeout, ohne Keep-Alive-Steuerung, ohne statisches Caching. In CLAUDE.md bewusst so beschrieben.
- **Risiko:** Ein hängender Upstream (OpenAI, Strava ohne Timeout, siehe N4) bindet einen der 8 Worker unbegrenzt; acht davon und die Seite steht.
- **Lösung:** Vorschlag, keine Umsetzung ohne dich: FrankenPHP (ein Binary, kein Paket in composer nötig) oder nginx + php-fpm im Dockerfile. Größerer Umbau → eigenes Thema.
- **Aufwand:** L

### M13 · Bekannte Lücken in Abhängigkeiten — `composer.lock` steht auf Laravel 13.3.0
- **Datei:** `composer.lock`, `package-lock.json`
- **Problem:** `composer audit` meldet 46 Advisories in 10 Paketen, `npm audit` 18 (2 kritisch, 12 hoch). Ursache fast immer: der Lockfile ist alt, die Constraints in `composer.json`/`package.json` erlauben die Fixes längst.
- **Was davon Zone3 wirklich trifft:**
  - `laravel/framework` 13.3.0 → **13.30+**: *Kritisch* gemeldet ist ein TokenGuard-Bypass — Zone3 nutzt nur den `session`-Guard, also **nicht betroffen**. Relevant sind CRLF-Injection in der `email`-Validierung (Registrierung, Profil) und „relationship inclusion can trigger data destruction".
  - `guzzlehttp/guzzle` < 7.15.2 / `psr7`: Host-Prüfung umgehbar — trifft alle ausgehenden Aufrufe (Strava, OpenAI, Telegram, Web-Push).
  - `symfony/mime`/`mailer`: Header-Injection in Mails (Passwort-Reset, Newsletter).
  - `web-token/jwt-library` (über `minishlink/web-push`, VAPID): die gemeldeten Fehler betreffen Entschlüsseln/Verifizieren, Zone3 signiert nur — gering, Update trotzdem frei.
  - `league/commonmark`: kommt nur über Laravel, Zone3 rendert serverseitig kein Markdown — nicht ausnutzbar.
  - npm: `vite` 8.0.1 (Dev-Server-Lücken — nur lokal relevant, Produktion liefert gebaute Dateien), `vue` 3.5.30, `postcss`, `concurrently`/`shell-quote` (nur `composer dev`), **`axios` 1.13.6** (Prototype-Pollution-Gadgets, landet im Browser-Bundle).
  - **`@inertiajs/inertia` 0.11.1** ist das Inertia-Paket von **v0** und zieht ein eigenes `axios` 0.x mit. Benutzt wird es an genau einer Stelle (`resources/js/Pages/Dashboard.vue:13`, `import { Inertia }`) — `router` aus `@inertiajs/vue3` macht dasselbe. Das ist ein Paket **weniger**, kein neues.
- **Lösung:** `composer update` innerhalb der bestehenden Constraints, `npm update` + `npm audit fix` (ohne `--force`), danach `@inertiajs/inertia` entfernen. Kein neues Paket, aber der Lockfile ändert sich deutlich → **Frage 8**. Danach volle Testsuite und ein Klick durch Dashboard, Plan, Chat.
- **Aufwand:** M (wegen Nachprüfen, nicht wegen der Befehle)

---

## Niedrig

| # | Datei:Zeile | Problem | Lösung | Aufwand |
|---|---|---|---|---|
| N1 | `bootstrap/app.php:22` | `trustProxies(at: '*')` vertraut nur dem direkten Peer (Traefik). Hinter Cloudflare ist `$request->ip()` dann die **Cloudflare-IP**, nicht die des Nutzers — Login-Drossel und Logs zählen pro Cloudflare-Knoten. *Hängt von der Traefik-Konfiguration ab, nicht verifiziert.* | Cloudflare-IP-Bereiche als Proxies eintragen oder `CF-Connecting-IP` auswerten | S |
| N2 | `routes/web.php:42–48` | Startseite (öffentlich) liefert `laravelVersion` und `phpVersion` als Props aus | Props entfernen (Dashboard ebenso) | S |
| N3 | `RunnerProfileController.php:15,74` | Loggt `$request->all()` bei jedem Speichern, gibt `$e->getMessage()` an den Nutzer zurück | Logzeilen entfernen, generische Meldung | S |
| N4 | `app/Services/StravaService.php:47–126` | Strava-Aufrufe ohne eigenen Timeout (Laravel-Default 30 s), laufen im Webhook-Request | `Http::timeout(10)` | S |
| N5 | `StravaService::refreshToken()` | Webhook und `strava:sync` können gleichzeitig refreshen | `Cache::lock("strava-refresh:{id}")` | S |
| N6 | `StravaController.php:38` / Migration `strava_accounts` | `strava_id` nicht eindeutig; ein Neuverbinden mit anderem Strava-Konto legt eine **zweite** Zeile an, `hasOne` nimmt eine beliebige | `updateOrCreate(['user_id' => …])`; Unique-Index nur per neuer Migration (Rückfrage) | S |
| N7 | `StravaController.php:55–64` | Trennen löscht Aktivitäten per Bulk-`delete()` — erledigte Einheiten bleiben „completed" ohne Beleg (das, wovor `ActivityDeletionService` schützt); Strava-Zugriff wird nicht widerrufen (`/oauth/deauthorize`) | Über den Service löschen, Deauthorize aufrufen | M |
| N8 | `routes/web.php:327` | Dashboard dispatcht `GeneratePrMessageJob` bei **jedem** Aufruf, solange die Nachricht fehlt — scheitert die Generierung, wiederholt jeder Seitenaufruf den KI-Aufruf | `ShouldBeUnique` + Merker für Fehlschlag | S |
| N9 | `WellbeingController.php:142` | `/api/wellbeing/latest/{count}` ohne Obergrenze | `min((int) $count, 60)` | S |
| N10 | Migration `wellbeing_entries` | Kein Unique `(user_id, date)` — Doppeltipp kann zwei Einträge für denselben Tag erzeugen | Neue Migration (Rückfrage) oder `updateOrCreate` mit Lock | S |
| N11 | `TelegramLinkController.php:24` | Verknüpfungscode verfällt nie | Ablaufzeit (z. B. 30 min) | S |
| N12 | — | Keine Bereinigung für `ai_logs` (enthält `full_prompt` mit Gesundheitsdaten, bleibt nach Kontolöschung mit `user_id = NULL` stehen), `strava_webhook_events`, `failed_jobs` | `Prunable` + `model:prune` im Scheduler; Aufbewahrungsdauer → **Frage 5** | M |
| N13 | `routes/web.php:508` | Kalenderseite lädt **alle** Aktivitäten des Athleten bei jedem Aufruf | Auf das sichtbare Fenster begrenzen | S |
| N14 | `AiLog::todayCountForUser` | `whereDate('created_at')` nutzt den Index nicht | `whereBetween(created_at, [today, tomorrow))` | S |
| N15 | `Dockerfile` | Container läuft als root; `mlocati/php-extension-installer` ohne Versions-Tag; kein `HEALTHCHECK` (Route `/up` existiert) — ob Coolify einen eigenen Healthcheck hat → **Frage 6** | `USER www-data` + `chown storage bootstrap/cache`, Tag pinnen, Healthcheck auf `/up` | S |
| N16 | `fit-service/requirements.txt` | `garth`, `garmin-fit-sdk` ungepinnt | Versionen festschreiben | S |
| N17 | `config/services.php:43` | `STRAVA_WEBHOOK_VERIFY_TOKEN` fällt auf `zone3_webhook` zurück | Ohne Wert Verifikation verweigern | S |
| N18 | `.env.example:25` | `APP_DEBUG=true`, `LOG_LEVEL=debug` als Vorlage — CLAUDE.md verlangt in Produktion `LOG_LEVEL=info` | Vorlage auf sichere Werte | S |
| N19 | `Admin/Wiki/Page.vue:75` | Wiki-Markdown erlaubt `javascript:`-Links (nur Admins schreiben, deshalb niedrig) | Schema `https?:` erzwingen | S |
| N20 | `RegeneratePlanJob.php:257` | `whereIn('planned_date', [...Datumsstrings])` — unter SQLite (Tests) trifft das nichts; in MySQL korrekt. Dieselbe Falle, vor der CLAUDE.md warnt | Auf `whereDate`/Bereich umstellen | S |
| N21 | `StravaImportService.php:52` | Nutzt `type` statt `sport_type` (Strava führt `type` als veraltet; `TrailRun` kommt dort als `Run`) | Beobachten, aktuell kein Fehler | — |

---

## Fragen an dich, bevor ich etwas ändere

1. **GitHub-Webhook:** Ist `GITHUB_WEBHOOK_SECRET` in Coolify gesetzt? Wird `/webhook/github` überhaupt noch benutzt, oder kann die Route weg?
2. **Strava-Tokens verschlüsseln (M6):** Darf ich dafür eine neue Migration anlegen (Spalten → `text`, Bestand einmalig verschlüsseln)? Folge: Ein Dump ist ohne `APP_KEY` für Strava wertlos; wer den `APP_KEY` rotiert, muss Strava neu verbinden.
3. **Registrierung (M7):** Soll sich jeder registrieren können, oder nur Leute, die du einlädst?
4. **Coaches (M10):** Wer ist die Wahrheit — der Seeder im Code oder der Editor im Admin?
5. **Aufbewahrung (N12):** Wie lange sollen `ai_logs` (mit vollen Prompts) bleiben? Vorschlag 90 Tage.
6. **Healthcheck (N15):** Hat Coolify einen Healthcheck für den Container konfiguriert? Und läuft Traefik direkt hinter Cloudflare (für N1)?
7. **Zeitzonen (M9):** Der Fehler ist bestätigt. Sollen Bestandsaktivitäten per Migration korrigiert werden, oder nur neue Importe?

8. **Abhängigkeiten (M13):** Darf ich `composer update` / `npm update` innerhalb der bestehenden Versionsgrenzen laufen lassen und `@inertiajs/inertia` entfernen? Das ändert beide Lockfiles; neue Pakete kommen nicht dazu.

## Vorgeschlagene Reihenfolge nach Freigabe

H1 → H2 → H3 → H4 → M1–M5, M8, M11 (ohne Rückfragen umsetzbar) → M6, M7, M9, M10, M13 (nach deinen Antworten) → Niedrig nach Wahl. M12 nicht in diesem Branch.
Je Fix ein Commit, für H1–H3 und M3 vorher ein Test, der den Fehler reproduziert.

---

## Werkzeuge

Gelaufen im Cloud-Container mit **PHP 8.3.6** (`composer install --ignore-platform-req=php`), weil kein 8.4 verfügbar ist. Produktion läuft auf 8.4 — vor dem Merge bitte einmal lokal in Laragon.

| Werkzeug | Ergebnis |
|---|---|
| `php artisan test` | **784 bestanden**, 0 fehlgeschlagen (2823 Assertions, 61 s) |
| `npm run build` | grün (4,5 s) |
| `composer audit` | 46 Advisories in 10 Paketen → M13 |
| `npm audit` | 18 (2 kritisch, 12 hoch, 4 mittel) → M13 |
| `vendor/bin/pint --test` | 239 von 391 PHP-Dateien weichen vom Laravel-Preset ab — reine Formatierung (Leerzeichen, Klammern, Imports), kein Befund. **Vorschlag:** nicht in diesem Branch formatieren; ein eigener Commit nur mit `pint`, sonst gehen die echten Fixes im Diff unter. |
| Larastan/PHPStan | nicht installiert. Vorschlag: `larastan/larastan` auf Level 5 — wäre ein neues Paket, also nur mit deiner Zustimmung. |
| `php artisan route:list` | 198 Routen; jede mit Model-Binding geprüft (siehe Einleitung) |

PHP-8.4-Deprecations: In der Testsuite unter 8.3 nicht sichtbar. Gesucht habe ich nach den 8.4-typischen Mustern (implizit nullable Parameter `Type $x = null`); `grep` fand keine in `app/`.
