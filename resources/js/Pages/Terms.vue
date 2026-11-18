<script setup>
import { isIosApp } from '@/Composables/useNativeApp';
import { Head, Link } from '@inertiajs/vue3';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';
import { LEGAL } from '@/legal';

/**
 * Nutzungsbedingungen (/nutzungsbedingungen), öffentlich.
 *
 * Angefragt von Partnern (ROUVY verlangt eine Terms-of-Service-URL für die
 * OAuth-App). Was hier steht, ist eine Zusage an die Athleten — wer an
 * Preis, Haftung oder Kontoregeln etwas ändert, zieht die Seite und STAND nach.
 */
const STAND = '18. November 2026';
const MAIL = LEGAL.email;

// App Store (Guideline 2.2): in der iOS-App ist von keiner Beta die Rede.
const inApp = isIosApp();
</script>

<template>
    <Head title="Nutzungsbedingungen – Zone3" />

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
                <h1 class="text-[34px] font-bold leading-[41px] tracking-tight text-ink">Nutzungsbedingungen</h1>
                <p class="mt-2 text-[13px] text-ink-3">Stand: {{ STAND }}</p>
                <p class="mt-4">
                    Diese Bedingungen regeln, wie du Zone3 nutzen kannst — auf zone3.run, als Web-App und in
                    den Apps für iPhone und Android. Wie wir mit deinen Daten umgehen, steht in der
                    <Link :href="route('privacy')" class="text-accent">Datenschutzerklärung</Link>.
                </p>
            </header>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">1. Anbieter</h2>
                <p class="select-text">
                    Zone3 wird privat betrieben von {{ LEGAL.name }},
                    {{ LEGAL.address.filter(l => l !== 'Deutschland').join(', ') }}.
                    E-Mail: <a :href="`mailto:${MAIL}`" class="text-accent">{{ MAIL }}</a>.
                    Weitere Angaben im <Link :href="route('impressum')" class="text-accent">Impressum</Link>.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">2. Was Zone3 ist</h2>
                <p>
                    Zone3 ist ein Trainingscoach für Läuferinnen und Läufer. Zone3 erstellt Trainingspläne,
                    wertet deine Einheiten aus und gibt dir Rückmeldungen. Ein Teil davon wird mit
                    künstlicher Intelligenz erzeugt (siehe Abschnitt 4).
                </p>
                <p class="mt-3">
                    Zone3 ist {{ inApp ? 'derzeit kostenlos.' : 'derzeit eine kostenlose Beta.' }}
                    Es kann sein, dass Funktionen sich ändern, wegfallen oder zeitweise nicht erreichbar sind.
                    Sollte Zone3 später kostenpflichtig werden, erfährst du das vorher, und ein Abo kommt nur
                    zustande, wenn du es ausdrücklich abschließt. Dafür gelten dann eigene Bedingungen.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">3. Gesundheit und Eigenverantwortung</h2>
                <p>
                    Zone3 ist <strong class="text-ink">kein Medizinprodukt</strong> und ersetzt keine ärztliche
                    Beratung, Diagnose oder Behandlung. Pläne, Paces, Hinweise zu Erholung und Aussagen des
                    Coaches sind allgemeine Trainingsempfehlungen auf Grundlage deiner Angaben und Daten.
                </p>
                <p class="mt-3">
                    Du trainierst auf eigene Verantwortung. Lass dich vor intensivem Training ärztlich
                    untersuchen, wenn du gesundheitliche Beschwerden, Vorerkrankungen oder Zweifel hast.
                    Brich eine Einheit ab, wenn du Schmerzen, Schwindel oder Atemnot spürst, und richte dich
                    nach deinem Körper statt nach dem Plan. Achte im Straßenverkehr auf deine Sicherheit.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">4. Inhalte von der KI</h2>
                <p>
                    Trainingspläne, Auswertungen und Antworten des Coaches entstehen teilweise mit einem
                    KI-Modell. Solche Inhalte können unvollständig oder falsch sein. Prüfe sie mit gesundem
                    Menschenverstand, bevor du danach trainierst. Ob und wie deine Daten dafür verarbeitet
                    werden, entscheidest du mit deiner Einwilligung; Einzelheiten stehen in der
                    Datenschutzerklärung.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">5. Dein Konto</h2>
                <ul class="list-disc space-y-2 pl-5">
                    <li>Zone3 richtet sich an Personen ab 18 Jahren.</li>
                    <li>Gib bei der Anmeldung zutreffende Angaben an und halte deinen Zugang geheim.</li>
                    <li>Ein Konto gehört einer Person. Gib es nicht an andere weiter.</li>
                    <li>
                        Die Teilnahme kann über eine Warteliste oder Einladung laufen. Einen Anspruch auf ein Konto
                        gibt es nicht.
                    </li>
                    <li>
                        Du kannst dein Konto jederzeit unter <strong class="text-ink">Profil → Konto löschen</strong>
                        löschen. Damit endet die Nutzung.
                    </li>
                </ul>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">6. Verbindungen zu anderen Diensten</h2>
                <p>
                    Du kannst Zone3 mit anderen Diensten verbinden, etwa Uhren, Trainings-Apps oder Messengern.
                    Für diese Dienste gelten zusätzlich deren eigene Bedingungen. Zone3 ruft dort nur ab und
                    überträgt nur, was du bei der Verbindung freigibst. Du kannst jede Verbindung in Zone3
                    trennen oder die Freigabe beim jeweiligen Dienst widerrufen. Für die Verfügbarkeit und die
                    Daten dieser Dienste ist Zone3 nicht verantwortlich.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">7. Was du teilst</h2>
                <p>
                    Teilst du ein eigenes Workout mit anderen, darf Zone3 es den anderen Athleten anzeigen,
                    damit sie es in ihren Plan übernehmen können. Die Rechte daran bleiben bei dir. Du kannst
                    das Teilen jederzeit beenden. Teile nur Inhalte, die du teilen darfst, und nichts
                    Beleidigendes oder Rechtswidriges. Gemeldete Inhalte prüfen wir und können das Teilen
                    zurücknehmen.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">8. Faire Nutzung</h2>
                <p>Bitte nutze Zone3 nicht, um</p>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li>Zone3 oder andere Athleten anzugreifen, zu stören oder auszuspähen,</li>
                    <li>automatisiert Daten abzurufen oder die KI-Funktionen über das übliche Maß hinaus zu belasten,</li>
                    <li>Sicherheitsmaßnahmen oder Nutzungsgrenzen zu umgehen.</li>
                </ul>
                <p class="mt-3">
                    Bei einem Verstoß können wir dein Konto sperren oder löschen. Wir sagen dir vorher Bescheid,
                    außer es ist wegen der Schwere des Verstoßes nicht zumutbar.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">9. Haftung</h2>
                <p>
                    Zone3 ist unentgeltlich. Wir haften unbeschränkt für Vorsatz und grobe Fahrlässigkeit sowie
                    für Schäden an Leben, Körper oder Gesundheit, die auf einer vorsätzlichen oder fahrlässigen
                    Pflichtverletzung von uns beruhen. Im Übrigen ist die Haftung ausgeschlossen, soweit das
                    Gesetz es zulässt. Zwingende gesetzliche Haftung, etwa nach dem Produkthaftungsgesetz,
                    bleibt unberührt.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">10. Ende der Nutzung</h2>
                <p>
                    Du kannst jederzeit aufhören und dein Konto löschen. Wir können Zone3 oder dein Konto mit
                    einer Frist von 30 Tagen beenden; aus wichtigem Grund (Abschnitt 8) auch sofort. Endet
                    Zone3 als Ganzes, sagen wir rechtzeitig Bescheid, damit du deine Daten vorher sichern kannst.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">11. Änderungen</h2>
                <p>
                    Ändern sich diese Bedingungen, informieren wir dich vorher in der App oder per E-Mail.
                    Es gilt die jeweils hier veröffentlichte Fassung. Bist du mit einer Änderung nicht
                    einverstanden, kannst du dein Konto löschen.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">12. Recht</h2>
                <p>
                    Es gilt deutsches Recht. Bist du Verbraucher mit gewöhnlichem Aufenthalt in einem anderen
                    Land, bleiben die zwingenden Verbraucherschutzvorschriften dieses Landes unberührt.
                </p>
            </section>

            <section class="rounded-card bg-surface p-5">
                <h2 class="mb-2 text-[22px] font-bold text-ink">Fragen?</h2>
                <p>Schreib uns, wir antworten in der Regel innerhalb von 24 bis 48 Stunden.</p>
                <a :href="`mailto:${MAIL}`"
                    class="mt-4 inline-flex h-11 items-center rounded-full bg-accent px-5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-[0.98]">
                    {{ MAIL }}
                </a>
            </section>
        </main>

        <footer class="border-t border-line px-5 py-8">
            <div class="mx-auto flex max-w-3xl items-center justify-between text-[13px] text-ink-3">
                <div class="flex gap-4">
                    <Link :href="route('privacy')" class="transition hover:text-ink-2 active:opacity-60">Datenschutz</Link>
                    <Link :href="route('impressum')" class="transition hover:text-ink-2 active:opacity-60">Impressum</Link>
                </div>
                <Link href="/" class="transition hover:text-ink-2 active:opacity-60">Zur Startseite</Link>
            </div>
        </footer>
    </div>
</template>
