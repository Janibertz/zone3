<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import GaugeTile from '@/Components/UI/GaugeTile.vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const scrolled = ref(false);
function onScroll() { scrolled.value = window.scrollY > 40; }
onMounted(() => window.addEventListener('scroll', onScroll));
onUnmounted(() => window.removeEventListener('scroll', onScroll));

/**
 * Der Look der Instagram-Story (28.10.2026): große, fette Headlines mit
 * grauem Nachsatz, Kennzahlen groß und die Einheit klein daneben. Wer über
 * die Story kommt, soll hier dasselbe wiederfinden.
 *
 * Die Ausschnitte sind nachgebaute Oberfläche, keine Bildschirmfotos: auf
 * einer öffentlichen Seite stünden sonst echte Namen und Werte, Bilder
 * veralten still, und ein halbes Megabyte PNG kostet Ladezeit. Die Zahlen
 * sind Beispiele, keine echten Trainingsdaten.
 */

// TODO(Jan): Kontaktweg für „Schreib mir einfach" — Instagram-Profil und/oder E-Mail.
const KONTAKT = { href: 'https://instagram.com/', label: 'Auf Instagram schreiben' };

const heuteSchritte = [
    { name: 'Einlaufen',           wert: '15 min',    farbe: 'bg-success' },
    { name: '3 × 10 min Schwelle', wert: '4:09–4:15', farbe: 'bg-warn' },
    { name: 'Auslaufen',           wert: '10 min',    farbe: 'bg-success' },
];

// Dieselben Stufen wie in useRecoveryGauges.js, nur mit Beispielwerten.
const boegen = [
    { icon: 'sleep', label: 'Schlaf', mode: 'fill', value: 58, display: '58', min: 0, max: 100,
      zones: [{ from: 0, to: 60, tone: 'danger' }, { from: 60, to: 80, tone: 'warn' }, { from: 80, to: 90, tone: 'success' }, { from: 90, to: 100, tone: 'accent' }],
      status: { text: 'Schlecht', tone: 'danger' } },
    { icon: 'battery', label: 'Body Battery', mode: 'fill', value: 34, display: '34', min: 0, max: 100,
      zones: [{ from: 0, to: 25, tone: 'danger' }, { from: 25, to: 50, tone: 'warn' }, { from: 50, to: 75, tone: 'sky' }, { from: 75, to: 100, tone: 'accent' }],
      status: { text: 'Niedrig', tone: 'warn' } },
    { icon: 'hrv', label: 'HRV', mode: 'mark', value: 41, display: '41', unit: 'ms', min: 30, max: 80,
      zones: [{ from: 30, to: 48, tone: 'warn' }, { from: 48, to: 66, tone: 'success' }, { from: 66, to: 80, tone: 'warn' }],
      status: { text: 'Unter Normal', tone: 'warn' } },
    { icon: 'heart', label: 'Ruhepuls', mode: 'mark', value: 53, display: '53', unit: 'bpm', min: 38, max: 60,
      zones: [{ from: 38, to: 42, tone: 'warn' }, { from: 42, to: 49, tone: 'success' }, { from: 49, to: 60, tone: 'warn' }],
      status: { text: 'Erhöht', tone: 'warn' } },
];

const woche = [
    { tag: 'Mo', art: 'rest',  text: 'Ruhetag',               km: null },
    { tag: 'Di', art: 'tempo', text: 'Schwelle 3 × 10 min',   km: '10,5' },
    { tag: 'Mi', art: 'easy',  text: 'Locker',                km: '8', heute: true },
    { tag: 'Do', art: 'int',   text: 'Intervalle 5 × 1000 m', km: '11' },
    { tag: 'Fr', art: 'easy',  text: 'Locker + Steigerungen', km: '7' },
    { tag: 'Sa', art: 'easy',  text: 'Locker',                km: '6' },
    { tag: 'So', art: 'long',  text: 'Langer Lauf',           km: '19' },
];
const punkt = { rest: 'bg-ink-3', tempo: 'bg-warn', easy: 'bg-success', int: 'bg-danger', long: 'bg-info' };
</script>

<template>
    <Head title="Zone3 – Dein KI Running Coach" />

    <!--
        `dark` erzwingt die dunklen Design-Tokens der App, unabhängig davon,
        was der Besucher eingestellt hat — genau wie die Story.
    -->
    <div class="dark min-h-screen overflow-x-hidden bg-canvas text-ink">

        <!-- ══ KOPFZEILE ══════════════════════════════════════════ -->
        <nav :class="['fixed inset-x-0 top-0 z-50 pt-[env(safe-area-inset-top)] transition duration-300',
                      scrolled ? 'bg-canvas/80 backdrop-blur-xl' : '']">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-5">
                <div class="flex items-center gap-2.5">
                    <!-- Das Logo aus der Story: drei steigende Balken -->
                    <div class="flex h-8 w-8 items-end justify-center gap-[3px] rounded-[8px] bg-[#0071e3] pb-[8px]" aria-hidden="true">
                        <i class="block h-[7px] w-[5px] rounded-full bg-white" />
                        <i class="block h-[11px] w-[5px] rounded-full bg-white" />
                        <i class="block h-[15px] w-[5px] rounded-full bg-white" />
                    </div>
                    <span class="text-lg font-bold tracking-tight">Zone3</span>
                </div>

                <!-- whitespace-nowrap: sonst bricht der Knopftext auf dem Telefon um. -->
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    <Link v-if="$page.props.auth.user" :href="route('dashboard')"
                        class="whitespace-nowrap rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 active:opacity-80">
                        Dashboard
                    </Link>
                    <!-- Registrieren nur per Einladungslink — kein offener Einstieg. -->
                    <Link v-else-if="canLogin" :href="route('login')"
                        class="whitespace-nowrap rounded-full bg-surface-2 px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-3 active:bg-surface-3">
                        Anmelden
                    </Link>
                </div>
            </div>
        </nav>

        <!-- ══ AUFMACHER ══════════════════════════════════════════ -->
        <section class="px-5 pb-20 pt-28 sm:pt-36">
            <div class="mx-auto grid max-w-5xl items-center gap-12 lg:grid-cols-2 lg:gap-16">
                <div>
                    <p class="text-[17px] font-semibold text-ink-3">Mein Ziel</p>
                    <!--
                        Zwei Blöcke statt <br>: Vue faltet den Zeilenumbruch im
                        Quelltext weg, und ein ausgeblendetes <br> klebte Wörter
                        aneinander.
                    -->
                    <h1 class="mt-2 text-[3.4rem] font-extrabold leading-[0.98] tracking-[-0.04em] sm:text-7xl">
                        <span class="block">Halbmarathon</span>
                        <span class="block">unter <span class="text-accent">90.</span></span>
                    </h1>
                    <p class="mt-6 max-w-md text-[20px] font-medium leading-snug text-ink-2">
                        Den Coach dafür habe ich mir selbst gebaut.
                    </p>
                    <p class="mt-3 max-w-md text-[15px] leading-relaxed text-ink-3">
                        Zone3 liest deine Läufe aus Strava oder Garmin, rechnet daraus deine
                        Tempi und baut einen Plan bis zum Renntag. Ändert sich etwas, merkt
                        es jemand — und sagt es dir.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="#mitmachen"
                            class="inline-flex h-12 items-center justify-center rounded-full bg-accent px-7 font-semibold text-white transition hover:opacity-90 active:scale-[0.98]">
                            Mittesten
                        </a>
                        <a href="#so-arbeitet-es"
                            class="inline-flex h-12 items-center justify-center rounded-full bg-surface-2 px-7 font-semibold text-ink transition hover:bg-surface-3 active:scale-[0.98]">
                            Wie es arbeitet
                        </a>
                    </div>
                    <p class="mt-4 text-[13px] text-ink-3">Beta · kostenlos · auf Einladung</p>
                </div>

                <!-- Die Heute-Karte aus dem Dashboard -->
                <div class="rounded-[28px] bg-surface p-6 sm:p-7">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-[17px] font-semibold text-warn-ink">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                            </svg>
                            Tempolauf
                        </span>
                        <span class="rounded-full bg-accent-soft px-3 py-1 text-[13px] font-semibold text-accent-ink">Heute</span>
                    </div>
                    <h3 class="mt-2 text-[26px] font-bold tracking-tight">Schwelle mit Kontrolle</h3>

                    <div class="mt-5 grid grid-cols-[1fr_1fr_1.2fr] gap-3">
                        <div>
                            <p class="text-[13px] text-ink-3">Distanz</p>
                            <p><span class="font-rounded text-[30px] font-bold tabular-nums tracking-[-0.02em] sm:text-[34px]">10,5</span><span class="ml-1 text-[15px] font-semibold text-ink-3">km</span></p>
                        </div>
                        <div>
                            <p class="text-[13px] text-ink-3">Dauer</p>
                            <p><span class="font-rounded text-[30px] font-bold tabular-nums tracking-[-0.02em] sm:text-[34px]">52</span><span class="ml-1 text-[15px] font-semibold text-ink-3">min</span></p>
                        </div>
                        <div>
                            <p class="text-[13px] text-ink-3">Tempo</p>
                            <p><span class="font-rounded text-[30px] font-bold tabular-nums tracking-[-0.02em] sm:text-[34px]">4:12</span><span class="ml-1 text-[15px] font-semibold text-ink-3">/km</span></p>
                        </div>
                    </div>

                    <div class="mt-5 border-t border-line">
                        <div v-for="s in heuteSchritte" :key="s.name" class="flex items-center gap-3 pt-3 text-[15px]">
                            <i class="h-5 w-1 shrink-0 rounded-full" :class="s.farbe" />
                            <span class="flex-1 text-ink-2">{{ s.name }}</span>
                            <b class="font-semibold tabular-nums">{{ s.wert }}</b>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ══ SO ARBEITET ES ═════════════════════════════════════ -->
        <section id="so-arbeitet-es" class="scroll-mt-20 px-5 py-16">
            <div class="mx-auto max-w-5xl space-y-24">

                <!-- 1 — Körper -->
                <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <p class="text-[17px] font-semibold text-ink-3">Heute, 06:42</p>
                        <h2 class="mt-2 text-[2.5rem] font-extrabold leading-[1.02] tracking-[-0.035em] sm:text-5xl">
                            <span class="block">Schlecht geschlafen?</span>
                            <span class="block text-ink-3">Er sieht es.</span>
                        </h2>
                        <p class="mt-5 max-w-md text-[15px] leading-relaxed text-ink-2">
                            Schlaf, HRV und Ruhepuls von deiner Garmin-Uhr, gemessen gegen deine
                            eigene Grundlinie. Passt die geplante Einheit nicht zur Nacht, kommt
                            ein Vorschlag.
                        </p>
                        <p class="mt-6 text-[22px] font-bold tracking-tight">
                            Er schlägt vor. <span class="text-ink-3">Du entscheidest.</span>
                        </p>
                    </div>

                    <div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <GaugeTile v-for="g in boegen" :key="g.label" v-bind="g" />
                        </div>

                        <div class="mt-2 rounded-[28px] bg-surface p-6">
                            <span class="flex items-center gap-2 text-[15px] font-semibold text-accent-ink">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3M12 7.5c.6 2.4 2.1 3.9 4.5 4.5-2.4.6-3.9 2.1-4.5 4.5-.6-2.4-2.1-3.9-4.5-4.5 2.4-.6 3.9-2.1 4.5-4.5Z" />
                                </svg>
                                Vorschlag vom Coach
                            </span>
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 text-[20px]">
                                <span class="text-ink-3 line-through decoration-2">6 × 800 m</span>
                                <span class="text-ink-3" aria-hidden="true">→</span>
                                <span class="font-bold">40 min locker</span>
                            </div>
                            <p class="mt-2 text-[15px] leading-relaxed text-ink-2">
                                HRV unter deinem Normalbereich, nur 5:40 h Schlaf. Die Intervalle
                                schieben wir auf Freitag.
                            </p>
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <span class="flex h-11 items-center justify-center rounded-[14px] bg-surface-2 text-[15px] font-semibold">Ablehnen</span>
                                <span class="flex h-11 items-center justify-center rounded-[14px] bg-[#0071e3] text-[15px] font-semibold text-white">Annehmen</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2 — Die Woche -->
                <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                    <div class="lg:order-2">
                        <p class="text-[17px] font-semibold text-ink-3">Diese Woche</p>
                        <h2 class="mt-2 text-[2.5rem] font-extrabold leading-[1.02] tracking-[-0.035em] sm:text-5xl">
                            <span class="block">80 % locker.</span>
                            <span class="block text-ink-3">20 % hart.</span>
                        </h2>
                        <p class="mt-5 max-w-md text-[15px] leading-relaxed text-ink-2">
                            Gezählt in Minuten, nicht nur in Einheiten. Der lange Lauf wird vom
                            Renntag rückwärts gerechnet, jede dritte Woche ist leichter, und
                            wie viel Zeit du an welchem Tag hast, wird eingehalten.
                        </p>
                    </div>

                    <div class="lg:order-1">
                        <div class="rounded-[28px] bg-surface px-6 py-1">
                            <div v-for="(w, i) in woche" :key="w.tag"
                                class="flex items-center gap-4 py-3" :class="i ? 'border-t border-line' : ''">
                                <span class="w-7 text-[15px] font-semibold" :class="w.heute ? 'text-accent-ink' : 'text-ink-3'">{{ w.tag }}</span>
                                <i class="h-2 w-2 shrink-0 rounded-full" :class="punkt[w.art]" />
                                <span class="flex-1 truncate text-[15px]" :class="w.art === 'rest' ? 'text-ink-3' : 'font-semibold'">{{ w.text }}</span>
                                <span v-if="w.km" class="font-rounded text-[17px] font-semibold tabular-nums">{{ w.km }}<span class="ml-1 text-[13px] text-ink-3">km</span></span>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="flex h-2.5 gap-1 overflow-hidden rounded-full">
                                <div class="bg-success" style="flex: 81" />
                                <div class="bg-warn" style="flex: 19" />
                            </div>
                            <div class="mt-2 flex justify-between text-[13px] text-ink-3">
                                <span><b class="font-rounded tabular-nums text-ink">81 %</b> locker</span>
                                <span><b class="font-rounded tabular-nums text-ink">19 %</b> hart</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3 — Der Coach -->
                <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <p class="text-[17px] font-semibold text-ink-3">Im Chat oder auf Telegram</p>
                        <h2 class="mt-2 text-[2.5rem] font-extrabold leading-[1.02] tracking-[-0.035em] sm:text-5xl">
                            <span class="block">Schreib ihm.</span>
                            <span class="block text-ink-3">Er ändert den Plan.</span>
                        </h2>
                        <p class="mt-5 max-w-md text-[15px] leading-relaxed text-ink-2">
                            Kein Chatbot, der Ratschläge gibt und es dabei belässt. Was du mit ihm
                            ausmachst, steht danach im Plan — und überlebt jede Neuberechnung.
                        </p>
                    </div>

                    <div class="space-y-2">
                        <div class="ml-auto max-w-[85%] rounded-[22px] rounded-br-md bg-[#0071e3] px-4 py-3 text-[15px] text-white">
                            Ich möchte am Sonntag einen Longrun über 25 km.
                        </div>
                        <div class="max-w-[90%] rounded-[22px] rounded-bl-md bg-surface px-4 py-3">
                            <p class="text-[15px] leading-relaxed">
                                Steht drin. Ich habe die Woche drumherum leichter gelegt — Samstag
                                jetzt Ruhetag statt Tempolauf.
                            </p>
                            <span class="mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-warn-soft px-2.5 py-1 text-[12px] font-semibold text-warn-ink">
                                Training angepasst · Sonntag
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ══ MITMACHEN ══════════════════════════════════════════ -->
        <section id="mitmachen" class="scroll-mt-20 px-5 pb-24 pt-16">
            <div class="mx-auto max-w-5xl">
                <span class="inline-block rounded-full bg-accent-soft px-3.5 py-1.5 text-[13px] font-semibold text-accent-ink">
                    Beta · kostenlos · auf Einladung
                </span>
                <h2 class="mt-5 text-[2.6rem] font-extrabold leading-[1.04] tracking-[-0.035em] sm:text-6xl">
                    <span class="block">Lust mitzutesten?</span>
                    <span class="block">Schreib mir einfach.</span>
                </h2>
                <p class="mt-4 text-[17px] text-ink-3">Web-App fürs Handy, ohne App Store.</p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a :href="KONTAKT.href" target="_blank" rel="noopener"
                        class="inline-flex h-12 items-center justify-center rounded-full bg-accent px-8 font-semibold text-white transition hover:opacity-90 active:scale-[0.98]">
                        {{ KONTAKT.label }}
                    </a>
                    <Link v-if="canLogin && !$page.props.auth.user" :href="route('login')"
                        class="inline-flex h-12 items-center justify-center rounded-full bg-surface-2 px-8 font-semibold text-ink transition hover:bg-surface-3 active:scale-[0.98]">
                        Ich habe schon ein Konto
                    </Link>
                </div>

                <p class="mt-14 text-[15px] font-semibold text-ink-3">Garmin · Strava · Apple Watch · Zwift</p>
            </div>
        </section>

        <footer class="border-t border-line px-5 pt-8 pb-[calc(2rem+env(safe-area-inset-bottom))]">
            <div class="mx-auto flex max-w-5xl flex-col items-center justify-between gap-3 text-[13px] text-ink-3 sm:flex-row">
                <span>Zone3 · zone3.run</span>
                <div class="flex gap-5">
                    <Link :href="route('privacy')" class="transition hover:text-ink-2">Datenschutz</Link>
                    <Link v-if="canLogin" :href="route('login')" class="transition hover:text-ink-2">Anmelden</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
