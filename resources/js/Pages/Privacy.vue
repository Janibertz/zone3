<script setup>
import { Head, Link } from '@inertiajs/vue3';

/**
 * Öffentliche Datenschutzerklärung (/privacy, vorher /support).
 *
 * Was hier steht, muss der Code wahr machen: wer einen Dienst ergänzt, der
 * personenbezogene Daten bekommt, trägt ihn in `empfaenger` ein. Die Seite
 * ist eine Zusage an Fremde, keine Beschreibung von Absichten.
 */
const STAND = '27. Oktober 2026';
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
        name: 'OpenAI, L.L.C.',
        ort: 'USA',
        zweck: 'Erzeugung von Trainingsplänen, Auswertungen und Antworten des Coachs. Übermittelt werden die dafür nötigen Trainingsdaten (Aktivitäten, Pace, Herzfrequenz, Ziele und Rennen, Trainingsprofil), deine Tagesform-Einträge, Coach-Notizen und deine Chat-Nachrichten sowie dein Name. E-Mail-Adresse, Passwort und Zugangsdaten werden nicht übermittelt.',
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
        name: 'OpenStreetMap Foundation',
        ort: 'Vereinigtes Königreich',
        zweck: 'Kartenkacheln für die Streckenkarten. Dein Browser lädt sie direkt, dabei wird deine IP-Adresse übertragen.',
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
                    <div class="flex h-8 w-8 items-center justify-center rounded-field bg-accent text-sm font-bold text-white">Z3</div>
                    <span class="text-lg font-bold tracking-tight">Zone3</span>
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
                    <li><strong class="text-ink">Konto:</strong> Name, E-Mail-Adresse, Passwort (nur als Hash gespeichert), optional ein Profilbild.</li>
                    <li><strong class="text-ink">Trainingsprofil:</strong> deine Angaben aus Onboarding und Profil, z. B. Erfahrung, verfügbare Trainingszeiten, Trainingsmodell, Schwellenpace, Herzfrequenz- und Pace-Zonen, FTP.</li>
                    <li><strong class="text-ink">Aktivitäten von Strava:</strong> Sportart, Datum, Distanz, Dauer, Pace, Herzfrequenz, Leistung, Höhenmeter, Runden, Bestzeiten und die GPS-Strecke.</li>
                    <li><strong class="text-ink">Ziele und Pläne:</strong> Rennen, Zielzeiten, Trainingspläne und -einheiten, deine Bewertungen und Notizen dazu, Antworten im Plan-Interview.</li>
                    <li><strong class="text-ink">Tagesform:</strong> freiwillige Selbsteinschätzung (Energie, Schlaf, Muskelkater, Stress, krank oder verletzt, Notiz).</li>
                    <li><strong class="text-ink">Coach:</strong> deine Nachrichten an den Coach, seine Antworten und Notizen, die er sich über dich merkt.</li>
                    <li><strong class="text-ink">Verbindungen:</strong> Zugangstoken für Strava, deine Telegram-Chat-ID, das Token deines Kalender-Abos, Push-Abonnements.</li>
                    <li><strong class="text-ink">Support:</strong> Inhalt deiner Support-Anfragen.</li>
                    <li><strong class="text-ink">Technische Daten:</strong> IP-Adresse, Zeitpunkt und aufgerufene Adresse in Server-Logs.</li>
                </ul>
                <p class="mt-3">
                    Herzfrequenz, Tagesform und Angaben zu Krankheit oder Verletzung sind Gesundheitsdaten im Sinne
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
                        Art. 9 Abs. 2 lit. a DSGVO, die du mit dem Verbinden von Strava und dem Ausfüllen der Tagesform
                        erteilst. Du kannst sie jederzeit widerrufen, indem du die Verbindung trennst, Einträge löschst
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
                <h2 class="mb-3 text-[22px] font-bold text-ink">6. Cookies und lokale Speicherung</h2>
                <p>
                    Zone3 setzt nur technisch notwendige Cookies: ein Sitzungs-Cookie, damit du angemeldet bleibst,
                    und ein Sicherheits-Cookie gegen gefälschte Anfragen. Im Browser werden außerdem Einstellungen
                    wie das Farbschema gespeichert. Dafür ist keine Einwilligung nötig (§ 25 Abs. 2 TDDDG).
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">7. Speicherdauer und Löschung</h2>
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
                <h2 class="mb-3 text-[22px] font-bold text-ink">8. Deine Rechte</h2>
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
                <h2 class="mb-3 text-[22px] font-bold text-ink">9. Mindestalter</h2>
                <p>Zone3 richtet sich an Personen ab 18 Jahren.</p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">10. Änderungen</h2>
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
