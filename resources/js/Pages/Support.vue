<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Support & Datenschutz – Zone3" />

    <div class="min-h-screen bg-[#0a0e1a] text-white">

        <!-- Nav -->
        <nav class="border-b border-white/5 px-5 h-16 flex items-center justify-between max-w-4xl mx-auto">
            <Link href="/" class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-indigo-500 flex items-center justify-center text-white font-bold text-sm">Z3</div>
                <span class="font-bold text-lg tracking-tight">Zone3</span>
            </Link>
            <Link href="/login" class="text-sm text-white/60 hover:text-white transition">Anmelden</Link>
        </nav>

        <div class="max-w-3xl mx-auto px-5 py-12 space-y-12">

            <!-- Hero -->
            <div>
                <h1 class="text-3xl font-black mb-2">Support & Datenschutz</h1>
                <p class="text-white/50">Letzte Aktualisierung: April 2026</p>
            </div>

            <!-- Kontakt -->
            <section class="bg-white/[0.04] border border-white/[0.08] rounded-2xl p-6">
                <h2 class="text-xl font-bold mb-4">Kontakt & Support</h2>
                <p class="text-white/60 mb-4">Bei Fragen, Problemen oder Feedback erreichst du uns per E-Mail:</p>
                <a href="mailto:jan.anders@zone3.run"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-500 hover:bg-indigo-400 text-sm font-semibold transition">
                    jan.anders@zone3.run
                </a>
                <p class="text-white/40 text-sm mt-4">Wir antworten in der Regel innerhalb von 24–48 Stunden.</p>
            </section>

            <!-- Was ist Zone3 -->
            <section>
                <h2 class="text-xl font-bold mb-3">Was ist Zone3?</h2>
                <p class="text-white/60 leading-relaxed">
                    Zone3 ist ein KI-gestützter Laufcoach der deine Strava-Aktivitäten analysiert und dir personalisierte Trainingsempfehlungen gibt. Die App berechnet deine individuellen Herzfrequenz- und Pace-Zonen auf Basis deiner echten Trainingsdaten.
                </p>
            </section>

            <!-- Strava Daten -->
            <section class="bg-white/[0.04] border border-white/[0.08] rounded-2xl p-6">
                <h2 class="text-xl font-bold mb-4">Nutzung von Strava-Daten</h2>
                <p class="text-white/60 mb-4">Zone3 verbindet sich mit deinem Strava-Account um folgende Daten zu lesen:</p>
                <ul class="space-y-2 text-white/60">
                    <li class="flex items-start gap-2"><span class="text-indigo-400 mt-0.5">→</span> Aktivitäten (Distanz, Dauer, Pace, Herzfrequenz, Höhenmeter)</li>
                    <li class="flex items-start gap-2"><span class="text-indigo-400 mt-0.5">→</span> Athletenprofil (Name, zur Anzeige)</li>
                </ul>
                <div class="mt-4 pt-4 border-t border-white/10">
                    <p class="text-white/60"><strong class="text-white">Wir verkaufen keine Strava-Daten</strong> und geben sie nicht an Dritte weiter. Daten werden ausschließlich genutzt um dir deinen persönlichen Trainingscoach zu betreiben.</p>
                </div>
            </section>

            <!-- Datenschutz -->
            <section>
                <h2 class="text-xl font-bold mb-4">Datenschutzerklärung</h2>

                <div class="space-y-6 text-white/60">
                    <div>
                        <h3 class="text-base font-semibold text-white mb-2">Verantwortlicher</h3>
                        <p>Jan Anders<br>Kontakt: <a href="mailto:jan.anders@zone3.run" class="text-indigo-400 hover:underline">jan.anders@zone3.run</a></p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-white mb-2">Erhobene Daten</h3>
                        <p>Wir speichern: E-Mail-Adresse, Name, Aktivitätsdaten von Strava, Wellbeing-Einträge (freiwillig), Trainingsprofile (Herzfrequenz-/Pace-Zonen).</p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-white mb-2">Zweck der Verarbeitung</h3>
                        <p>Bereitstellung des personalisierten Trainingscoachings, Berechnung von Trainingszonen, Generierung von KI-Empfehlungen.</p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-white mb-2">Drittanbieter</h3>
                        <ul class="space-y-1">
                            <li><strong class="text-white">Strava:</strong> OAuth-Authentifizierung und Aktivitätsimport</li>
                            <li><strong class="text-white">OpenAI:</strong> Generierung von KI-Trainingsempfehlungen (anonymisierte Trainingsdaten)</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-white mb-2">Deine Rechte</h3>
                        <p>Du hast das Recht auf Auskunft, Berichtigung und Löschung deiner Daten. Kontaktiere uns unter <a href="mailto:jan.anders@zone3.run" class="text-indigo-400 hover:underline">jan.anders@zone3.run</a>.</p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-white mb-2">Strava-Verbindung trennen</h3>
                        <p>Du kannst die Strava-Verbindung jederzeit in deinem <a href="/profile" class="text-indigo-400 hover:underline">Profil</a> trennen oder deinen Account vollständig löschen.</p>
                    </div>
                </div>
            </section>

            <!-- Strava Disconnect -->
            <section class="bg-white/[0.04] border border-white/[0.08] rounded-2xl p-6">
                <h2 class="text-xl font-bold mb-3">Strava-Verbindung trennen</h2>
                <p class="text-white/60 mb-4">
                    Um die Verbindung zwischen Zone3 und Strava zu trennen, gehe zu:<br>
                    <strong class="text-white">Strava → Einstellungen → Meine Apps → Zone3 → Zugriff widerrufen</strong>
                </p>
                <p class="text-white/40 text-sm">Alternativ kannst du in Zone3 unter Profil → Konto → "Strava trennen" klicken.</p>
            </section>

        </div>

        <!-- Footer -->
        <footer class="border-t border-white/[0.06] py-6 px-5 mt-8">
            <div class="max-w-4xl mx-auto flex items-center justify-between text-white/30 text-sm">
                <span>Zone3 · Dein KI Running Coach</span>
                <Link href="/" class="hover:text-white/60 transition">Zurück zur Startseite</Link>
            </div>
        </footer>
    </div>
</template>
