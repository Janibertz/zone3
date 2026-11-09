<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';

/**
 * Öffentliche Datenschutzerklärung (/privacy, vorher /support).
 *
 * Was hier steht, muss der Code wahr machen: wer einen Dienst ergänzt, der
 * personenbezogene Daten bekommt, trägt ihn in `empfaenger` ein. Die Seite
 * ist eine Zusage an Fremde, keine Beschreibung von Absichten.
 */
const STAND = '9. November 2026';
const MAIL = 'jan.anders@zone3.run';

const empfaenger = [
    {
        name: 'netcup GmbH',
        ort: 'Deutschland',
        zweck: 'Hosting. Server, Datenbank und Anwendung laufen auf einem Server von netcup in Deutschland.',
    },
    {
        name: 'Cloudflare, Inc.',
        ort: 'USA',
        zweck: 'Auslieferung und Schutz der Website (DNS, TLS, Abwehr von Angriffen). Cloudflare verarbeitet dabei deine IP-Adresse und technische Verbindungsdaten.',
    },
    {
        name: 'Strava, Inc.',
        ort: 'USA',
        zweck: 'Anmeldung per OAuth und Import deiner Aktivitäten. Zone3 liest nur die Daten, die du bei der Verbindung freigibst.',
    },
    {
        name: 'Garmin Ltd. und Tochtergesellschaften',
        ort: 'Schweiz, USA',
        zweck: 'Nur wenn du Garmin verbindest: Abruf deiner Erholungswerte und Aktivitäten aus Garmin Connect und Übertragung geplanter Einheiten auf deine Uhr, wenn du das antippst. Garmin erfährt dabei, welche Einheit du senden lässt.',
    },
    {
        name: 'COROS Wearables Inc.',
        ort: 'USA',
        zweck: 'Nur wenn du COROS verbindest: Übertragung deiner geplanten Läufe von heute und morgen in deinen COROS-Trainingskalender; mit deiner Einwilligung Abruf deiner Aktivitäten (samt FIT-Datei mit Strecke und Herzfrequenz) und Erholungswerte (Schlaf, HRV, Ruhepuls, Stress, Schritte, VO2max).',
    },
    {
        name: 'OpenAI, L.L.C.',
        ort: 'USA',
        zweck: 'Erzeugung von Trainingsplänen, Auswertungen und Antworten des Coachs. Übermittelt werden die dafür nötigen Trainingsdaten (Aktivitäten, Pace, Herzfrequenz, Ziele und Rennen, Trainingsprofil), deine Tagesform-Einträge, Coach-Notizen und deine Chat-Nachrichten sowie dein Name. Hast du Garmin verbunden oder bei COROS oder Apple Health in die Datenübernahme eingewilligt, außerdem Wochenmittel und Tageswerte deiner Erholung (Schlaf, HRV, Ruhepuls, Stress, Body Battery, Blutsauerstoff, Atemfrequenz). Nicht zum Training von KI-Modellen, siehe Abschnitt 7. E-Mail-Adresse, Passwort und Zugangsdaten werden nicht übermittelt.',
    },
    {
        name: 'Telegram',
        ort: 'außerhalb der EU',
        zweck: 'Nur wenn du Telegram in deinem Profil verbindest: Zustellung von Coach-Nachrichten, Tagesbriefing und Vorschlägen an deinen Telegram-Chat.',
    },
    {
        name: 'Open-Meteo',
        ort: 'Schweiz',
        zweck: 'Wetterdaten zu Trainings und Rennen. Der Server übermittelt dabei nur Koordinaten bzw. einen Ortsnamen, keine Angaben zu deiner Person.',
    },
    {
        name: 'Hyperknot Software Kft. (OpenFreeMap)',
        ort: 'Ungarn',
        zweck: 'Kartendaten und Schrift für die Streckenkarte einer Aktivität. Dein Browser lädt sie direkt, dabei wird deine IP-Adresse übertragen; deine Strecke selbst wird nicht übertragen. Laut Betreiber stehen in den Zugriffsprotokollen keine IP-Adressen, in Fehlerprotokollen höchstens 7 Tage.',
    },
    {
        name: 'OpenStreetMap Foundation',
        ort: 'Vereinigtes Königreich',
        zweck: 'Kartenkacheln für die Karte der Live-Verfolgung. Dein Browser lädt sie direkt, dabei wird deine IP-Adresse übertragen.',
    },
    {
        name: 'Google Ireland Limited (YouTube)',
        ort: 'Irland, USA',
        zweck: 'Nur wenn du ein Übungsvideo antippst: Erst dann lädt dein Browser den Player von youtube-nocookie.com. Dabei werden deine IP-Adresse und technische Angaben deines Geräts an Google übertragen, und YouTube kann eigene Cookies oder lokale Speicherung setzen. Vorher wird nichts von YouTube geladen; das Vorschaubild kommt von Zone3.',
    },
    {
        name: 'Apple Inc. (Mit Apple anmelden)',
        ort: 'USA',
        zweck: 'Nur wenn du „Mit Apple fortfahren“ wählst: Apple bestätigt dich und schickt uns eine Kennung, deine E-Mail-Adresse (oder eine Weiterleitungsadresse von Apple) und beim ersten Mal deinen Namen. Ein Passwort von Apple bekommen wir nie.',
    },
    {
        name: 'Apple, Google, Mozilla',
        ort: 'je nach Browser',
        zweck: 'Nur wenn du Push-Mitteilungen erlaubst: Zustellung über den Push-Dienst deines Browsers. Der Inhalt ist Ende-zu-Ende verschlüsselt.',
    },
];
</script>

<template>
    <Head title="Datenschutz – Zone3" />

    <div class="dark min-h-screen overflow-x-hidden bg-canvas text-ink">

        <nav class="border-b border-line">
            <div class="mx-auto flex h-16 max-w-3xl items-center justify-between px-5">
                <Link href="/" class="flex items-center gap-2">
                    <ZoneIcon class="h-8 w-8" />
                    <Wordmark class="h-[19px] text-ink" />
                </Link>
                <Link href="/login" class="text-sm text-ink-2 transition hover:text-ink active:opacity-60">Anmelden</Link>
            </div>
        </nav>

        <main class="mx-auto max-w-3xl space-y-10 px-5 py-10 text-[15px] leading-relaxed text-ink-2">

            <header>
                <h1 class="text-[34px] font-bold leading-[41px] tracking-tight text-ink">Datenschutz</h1>
                <p class="mt-2 text-[13px] text-ink-3">Stand: {{ STAND }}</p>
                <p class="mt-4">
                    Zone3 ist ein Trainingscoach für Läuferinnen und Läufer. Damit er funktioniert, verarbeitet
                    Zone3 Trainingsdaten, die sehr persönlich sind. Hier steht, welche Daten das sind, wofür
                    sie genutzt werden, wer sie bekommt und wie du sie wieder loswirst.
                </p>
                <p class="mt-3 font-semibold text-ink">
                    Wir verkaufen keine Daten, zeigen keine Werbung und setzen keine Tracking- oder Analysedienste ein.
                </p>
            </header>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">1. Verantwortlicher</h2>
                <p>
                    Jan Anders<br>
                    Hunaeusstr. 7<br>
                    30177 Hannover<br>
                    Deutschland<br>
                    E-Mail: <a :href="`mailto:${MAIL}`" class="text-accent">{{ MAIL }}</a>
                </p>
                <p class="mt-3">Ein Datenschutzbeauftragter ist nicht bestellt und gesetzlich nicht vorgeschrieben.</p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">2. Welche Daten wir verarbeiten</h2>
                <ul class="list-disc space-y-2 pl-5">
                    <li><strong class="text-ink">Konto:</strong> Name, E-Mail-Adresse, Passwort (nur als Hash gespeichert), optional ein Profilbild. Meldest du dich mit Apple an, dazu Apples Kennung für dich; meldest du dich mit einem Link per Mail an, schicken wir dir dafür eine Mail.</li>
                    <li><strong class="text-ink">Trainingsprofil:</strong> deine Angaben aus Onboarding und Profil, z. B. Erfahrung, verfügbare Trainingszeiten, Trainingsmodell, Schwellenpace, Herzfrequenz- und Pace-Zonen, FTP.</li>
                    <li><strong class="text-ink">Aktivitäten von Strava oder Garmin:</strong> Sportart, Datum, Distanz, Dauer, Pace, Herzfrequenz, Leistung, Höhenmeter, Runden, Bestzeiten und die GPS-Strecke.</li>
                    <li><strong class="text-ink">Erholungswerte von Garmin:</strong> nur wenn du Garmin verbindest. Tageswerte für Schlafdauer und Schlafscore, HRV, Ruhepuls, Stress, Body Battery, Schritte und VO2max.</li>
                    <li><strong class="text-ink">Ziele und Pläne:</strong> Rennen, Zielzeiten, Trainingspläne und -einheiten, deine Bewertungen und Notizen dazu, Antworten im Plan-Interview.</li>
                    <li><strong class="text-ink">Tagesform:</strong> freiwillige Selbsteinschätzung (Energie, Schlaf, Muskelkater, Stress, krank oder verletzt, Notiz).</li>
                    <li><strong class="text-ink">Coach:</strong> deine Nachrichten an den Coach, seine Antworten und Notizen, die er sich über dich merkt.</li>
                    <li><strong class="text-ink">Verbindungen:</strong> Zugangstoken für Strava, Garmin und COROS, deine Telegram-Chat-ID, das Token deines Kalender-Abos, Push-Abonnements.</li>
                    <li><strong class="text-ink">Support:</strong> Inhalt deiner Support-Anfragen.</li>
                    <li><strong class="text-ink">Technische Daten:</strong> IP-Adresse, Zeitpunkt und aufgerufene Adresse in Server-Logs.</li>
                </ul>
                <p class="mt-3">
                    Herzfrequenz, Erholungswerte, Tagesform und Angaben zu Krankheit oder Verletzung sind Gesundheitsdaten im Sinne
                    von Art. 9 DSGVO. Wir verarbeiten sie nur, weil und soweit du sie Zone3 selbst gibst.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">3. Zwecke und Rechtsgrundlagen</h2>
                <ul class="list-disc space-y-2 pl-5">
                    <li>
                        <strong class="text-ink">Betrieb des Coachings</strong> (Konto, Trainingspläne, Auswertungen,
                        Prognosen, Coach-Chat): Art. 6 Abs. 1 lit. b DSGVO, weil das die Leistung ist, die du nutzt.
                    </li>
                    <li>
                        <strong class="text-ink">Gesundheitsdaten:</strong> deine ausdrückliche Einwilligung nach
                        Art. 9 Abs. 2 lit. a DSGVO, die du mit dem Verbinden von Strava oder Garmin und dem Ausfüllen der
                        Tagesform erteilst. Du kannst sie jederzeit widerrufen, indem du die Verbindung trennst, Einträge löschst
                        oder dein Konto löschst.
                    </li>
                    <li>
                        <strong class="text-ink">Sicherheit und Fehlersuche</strong> (Server-Logs, Schutz vor Angriffen):
                        Art. 6 Abs. 1 lit. f DSGVO, berechtigtes Interesse an einem sicheren Betrieb.
                    </li>
                    <li>
                        <strong class="text-ink">Newsletter:</strong> nur mit deiner Einwilligung, Art. 6 Abs. 1 lit. a
                        DSGVO. Abmelden geht über den Link in jeder Mail oder im Profil.
                    </li>
                </ul>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">4. Wer deine Daten bekommt</h2>
                <p class="mb-4">
                    Wir geben Daten nur an Dienste weiter, die für den Betrieb nötig sind, und nur in dem Umfang,
                    den der jeweilige Zweck verlangt. Übermittlungen in Länder außerhalb der EU erfolgen auf
                    Grundlage eines Angemessenheitsbeschlusses (z. B. EU-US Data Privacy Framework) oder der
                    EU-Standardvertragsklauseln.
                </p>
                <div class="space-y-3">
                    <div v-for="e in empfaenger" :key="e.name" class="rounded-card bg-surface p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h3 class="font-semibold text-ink">{{ e.name }}</h3>
                            <span class="text-[13px] text-ink-3">{{ e.ort }}</span>
                        </div>
                        <p class="mt-1">{{ e.zweck }}</p>
                    </div>
                </div>
                <p class="mt-4">
                    Teilst du einen Live-Tracking-Link oder ein Workout öffentlich, sieht jeder mit dem Link bzw.
                    jeder Zone3-Nutzer die freigegebenen Inhalte. Beides tust nur du selbst und kannst es
                    jederzeit zurücknehmen.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">5. Strava</h2>
                <p>
                    Zone3 nutzt die Strava-API ausschließlich, um deine eigenen Aktivitäten in Zone3 anzuzeigen und
                    daraus dein Training zu steuern. Strava-Daten werden nicht verkauft, nicht für Werbung genutzt
                    und nicht anderen Nutzern gezeigt. Löschst du eine Aktivität bei Strava, wird sie auch in Zone3
                    gelöscht.
                </p>
                <p class="mt-3">
                    Trennen kannst du die Verbindung in Zone3 unter <strong class="text-ink">Profil → Verbindungen</strong>
                    oder bei Strava unter <strong class="text-ink">Einstellungen → Meine Apps → Zone3 → Zugriff widerrufen</strong>.
                    Danach ruft Zone3 keine neuen Daten mehr ab.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">6. Garmin, COROS und Apple Health</h2>
                <p>
                    Verbindest du dein Garmin-Konto, liest Zone3 die Daten, die du dabei freigibst: deine
                    Erholungswerte und, wenn du Garmin als Quelle für Aktivitäten wählst, deine Aktivitäten.
                    Zone3 nutzt sie nur, um dir deinen Trainingsplan, dein Dashboard und die Auswertungen des
                    Coachs zu liefern, und schickt geplante Einheiten auf deine Uhr, wenn du das antippst.
                </p>
                <p class="mt-3">
                    Garmin-Daten und alles, was Zone3 daraus berechnet, werden <strong class="text-ink">nicht verkauft</strong>,
                    nicht für Werbung genutzt, nicht anderen Nutzern gezeigt und nicht zum Training, zur Bewertung
                    oder Verbesserung von KI-Modellen verwendet.
                </p>
                <p class="mt-3">
                    Trennen kannst du die Verbindung in Zone3 unter <strong class="text-ink">Profil → Verbindungen</strong>
                    oder in Garmin Connect in den Einstellungen unter den verbundenen Apps. Beim Trennen löscht Zone3
                    deinen Garmin-Zugang und alle gespeicherten Erholungswerte. Bereits übernommene Aktivitäten bleiben
                    als Teil deiner Trainingshistorie stehen, weil erledigte Einheiten und deine Bewertungen daran
                    hängen. Du kannst sie einzeln löschen oder mit dem Konto.
                </p>
                <p class="mt-3">
                    Verbindest du dein COROS-Konto, schreibt Zone3 deine geplanten Läufe für heute und morgen in
                    deinen COROS-Trainingskalender. Dafür speichert Zone3 den Zugang, den du bei COROS freigibst, und
                    welche Einheit an welchem Tag dort steht. Nur wenn du einwilligst (beim Verbinden oder im Profil
                    unter „Daten übernehmen“), ruft Zone3 alle 15 Minuten deine Aktivitäten (mit Strecke, Runden und
                    Herzfrequenz aus der FIT-Datei) und stündlich deine Erholungswerte von COROS ab: Schlafdauer und
                    -wert, HRV, Ruhepuls, Stress, Schritte und VO2max. Sie werden wie die Garmin-Daten für Plan,
                    Auswertungen und Coach verwendet und dafür an OpenAI übermittelt (Abschnitt 7). Zyklusdaten ruft
                    Zone3 nie ab. Ausschalten widerruft die Einwilligung und löscht die übernommenen Aktivitäten und Werte.
                    Beim Trennen widerruft Zone3 den Zugang bei COROS und löscht alles, was von COROS kam.
                </p>
                <p class="mt-3">
                    In der iOS-App kannst du Zone3 erlauben, Daten aus Apple Health zu lesen. Gelesen werden nur
                    Schlafdauer, HRV, Ruhepuls, VO2max, Blutsauerstoff, Atemfrequenz und deine Trainings mit
                    Herzfrequenz und Strecke; welche davon, entscheidest du im Dialog von iOS. Zyklusdaten, Gewicht
                    und alle anderen Arten liest Zone3 nie. Solange du nicht zusätzlich einwilligst, bleiben die Werte
                    auf deinem iPhone und werden nur in der App angezeigt. Mit deiner Einwilligung (unter
                    <strong class="text-ink">Profil → Verbindungen → Apple Health</strong>) schickt die App sie beim
                    Öffnen an Zone3; sie werden wie die Garmin-Daten für Plan, Auswertungen und Coach verwendet und
                    dafür an OpenAI übermittelt (Abschnitt 7). Trainings übernimmt Zone3 nur, wenn Apple Health deine
                    Quelle für Aktivitäten ist. Daten aus Apple Health werden nicht für Werbung genutzt, nicht verkauft
                    und nicht zum Training von KI-Modellen verwendet. „Übertragung beenden“ widerruft die Einwilligung
                    und löscht die übernommenen Erholungswerte; übernommene Trainings bleiben als Teil deiner
                    Trainingshistorie stehen. Den Lesezugriff selbst nimmst du in den iPhone-Einstellungen unter
                    Health → Datenzugriff &amp; Geräte → Zone3 zurück.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">7. KI-Verarbeitung</h2>
                <p>
                    Trainingspläne, Auswertungen deiner Einheiten, Vorschläge zur Tagesform und die Antworten des
                    Coachs schreibt ein KI-Modell von OpenAI. Dafür schickt Zone3 bei jeder Anfrage den Ausschnitt
                    deiner eigenen Daten, den die Aufgabe braucht, über die OpenAI-API (welche Daten das sind, steht
                    in Abschnitt 4). Was das Modell antwortet, prüft Zone3 gegen feste Regeln, bevor es in deinen
                    Plan kommt, und Änderungen an der heutigen Einheit schlägt der Coach nur vor: du entscheidest.
                </p>
                <p class="mt-3">
                    Deine Daten werden nicht zum Training von KI-Modellen verwendet, weder von uns noch von OpenAI.
                    Zone3 hat das Teilen von Daten mit OpenAI zu Trainingszwecken abgeschaltet.
                </p>
                <p class="mt-3">
                    Daten von Garmin gehen nur mit deiner <strong class="text-ink">ausdrücklichen Einwilligung</strong>
                    an OpenAI, die du beim Verbinden von Garmin erteilst. Du kannst sie jederzeit widerrufen, indem du
                    Garmin unter <strong class="text-ink">Profil → Verbindungen</strong> trennst oder eine formlose
                    E-Mail an <a :href="`mailto:${MAIL}`" class="text-accent">{{ MAIL }}</a> schreibst. Danach gehen
                    keine Garmin-Daten mehr an OpenAI.
                </p>
                <p class="mt-3">
                    Für Daten von COROS gilt dasselbe: sie gehen nur mit deiner Einwilligung an OpenAI, die du beim
                    Verbinden oder unter <strong class="text-ink">Profil → Verbindungen → COROS → Daten übernehmen</strong>
                    erteilst. Ausschalten widerruft sie und löscht die übernommenen Aktivitäten und Werte.
                </p>
                <p class="mt-3">
                    Daten aus Apple Health gehen ebenfalls nur mit deiner gesonderten Einwilligung an OpenAI
                    (<strong class="text-ink">Profil → Verbindungen → Apple Health</strong>). „Übertragung beenden“ widerruft sie.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">8. Cookies und lokale Speicherung</h2>
                <p>
                    Zone3 setzt nur technisch notwendige Cookies: ein Sitzungs-Cookie, damit du angemeldet bleibst,
                    und ein Sicherheits-Cookie gegen gefälschte Anfragen. Im Browser werden außerdem Einstellungen
                    wie das Farbschema gespeichert. Dafür ist keine Einwilligung nötig (§ 25 Abs. 2 TDDDG).
                </p>
                <p class="mt-3">
                    Ein Übungsvideo von YouTube lädt erst, wenn du es antippst (siehe Abschnitt 4). Mit dem
                    Antippen willigst du ein, dass YouTube dabei Cookies oder lokale Speicherung nutzen kann
                    (§ 25 Abs. 1 TDDDG); ohne Antippen passiert nichts davon.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">9. Speicherdauer und Löschung</h2>
                <p>
                    Deine Daten bleiben gespeichert, solange dein Konto besteht. Einzelne Aktivitäten, Rennen,
                    Coach-Nachrichten und Coach-Notizen kannst du selbst löschen.
                </p>
                <p class="mt-3">
                    Löschst du dein Konto unter <strong class="text-ink">Profil → Konto löschen</strong>, werden alle
                    zugehörigen Daten sofort und endgültig gelöscht, einschließlich der Protokolle der KI-Anfragen.
                    Server-Logs bewahren wir nur kurz zur Fehlersuche auf.
                </p>
                <p class="mt-3">
                    Kannst du dich nicht mehr anmelden, schreib an <a :href="`mailto:${MAIL}`" class="text-accent">{{ MAIL }}</a>,
                    dann löschen wir das Konto für dich.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">10. Deine Rechte</h2>
                <p>Du hast jederzeit das Recht auf</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Auskunft über deine gespeicherten Daten (Art. 15 DSGVO),</li>
                    <li>Berichtigung (Art. 16) und Löschung (Art. 17),</li>
                    <li>Einschränkung der Verarbeitung (Art. 18),</li>
                    <li>Datenübertragbarkeit (Art. 20),</li>
                    <li>Widerspruch gegen Verarbeitungen auf Grundlage berechtigter Interessen (Art. 21),</li>
                    <li>Widerruf einer Einwilligung mit Wirkung für die Zukunft (Art. 7 Abs. 3).</li>
                </ul>
                <p class="mt-3">
                    Eine formlose E-Mail an <a :href="`mailto:${MAIL}`" class="text-accent">{{ MAIL }}</a> genügt.
                    Außerdem kannst du dich bei einer Datenschutz-Aufsichtsbehörde beschweren, zum Beispiel bei der
                    für uns zuständigen: Die Landesbeauftragte für den Datenschutz Niedersachsen, Prinzenstraße 5,
                    30159 Hannover.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">11. Mindestalter</h2>
                <p>Zone3 richtet sich an Personen ab 18 Jahren.</p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">12. Änderungen</h2>
                <p>
                    Ändert sich, welche Daten Zone3 verarbeitet oder wer sie bekommt, passen wir diese Seite an.
                    Es gilt die jeweils hier veröffentlichte Fassung.
                </p>
            </section>

            <section class="rounded-card bg-surface p-5">
                <h2 class="mb-2 text-[22px] font-bold text-ink">Kontakt und Support</h2>
                <p>Fragen, Probleme oder Feedback? Schreib uns, wir antworten in der Regel innerhalb von 24 bis 48 Stunden.</p>
                <a :href="`mailto:${MAIL}`"
                    class="mt-4 inline-flex h-11 items-center rounded-full bg-accent px-5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-[0.98]">
                    {{ MAIL }}
                </a>
            </section>
        </main>

        <footer class="border-t border-line px-5 py-8">
            <div class="mx-auto flex max-w-3xl items-center justify-between text-[13px] text-ink-3">
                <span>Zone3</span>
                <Link href="/" class="transition hover:text-ink-2 active:opacity-60">Zur Startseite</Link>
            </div>
        </footer>
    </div>
</template>
