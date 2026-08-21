<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const scrolled = ref(false);
function onScroll() { scrolled.value = window.scrollY > 40; }
onMounted(() => window.addEventListener('scroll', onScroll));
onUnmounted(() => window.removeEventListener('scroll', onScroll));

/**
 * Die Ausschnitte unten sind nachgebaute Oberfläche, keine Bildschirmfotos.
 *
 * Naheliegend wäre gewesen, Screenshots aus der App zu exportieren. Drei
 * Gründe sprechen dagegen. Auf einer öffentlichen Seite stünden echte Namen
 * und Werte. Bilder veralten still, sobald sich die Oberfläche ändert — und
 * sie ändert sich ständig. Und wir haben gerade erst 61 Vorablade-Verweise
 * entfernt, weil die Seite zu lange lud; ein halbes Megabyte PNG hätte das
 * wieder aufgefressen.
 *
 * Nachgebaut mit denselben Design-Tokens ist es dagegen scharf auf jedem
 * Bildschirm, wiegt nichts und sieht per Definition aus wie das Produkt.
 * Die Zahlen sind Beispiele, keine echten Trainingsdaten.
 */
const beispielWoche = [
    { tag: 'M', km: '7,6', aktiv: true },
    { tag: 'D', km: '13',  aktiv: true },
    { tag: 'M', km: null,  aktiv: false },
    { tag: 'D', km: null,  aktiv: false },
    { tag: 'F', km: null,  aktiv: false, heute: true },
    { tag: 'S', km: null,  aktiv: false },
    { tag: 'S', km: null,  aktiv: false },
];
</script>

<template>
    <Head title="Zone3 – Dein KI Running Coach" />

    <!--
        `dark` erzwingt die dunklen Design-Tokens der App, unabhängig davon,
        was der Besucher eingestellt hat. Vorher stand hier ein eigenes
        Violett auf Dunkelblau — wer von der Seite in die App kam, landete
        in einem anderen Produkt.
    -->
    <div class="dark min-h-screen overflow-x-hidden bg-canvas text-ink">

        <!-- ══ KOPFZEILE ══════════════════════════════════════════ -->
        <nav :class="['fixed inset-x-0 top-0 z-50 transition-all duration-300',
                      scrolled ? 'border-b border-line bg-canvas/95 backdrop-blur' : '']">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-5">
                <div class="flex items-center gap-2">
                    <div class="flex h-8 w-8 items-center justify-center rounded-field bg-accent text-sm font-bold text-white">Z3</div>
                    <span class="text-lg font-bold tracking-tight">Zone3</span>
                </div>

                <!--
                    whitespace-nowrap: „Kostenlos starten" brach auf dem
                    Telefon auf drei Zeilen um und machte aus dem Knopf einen
                    56 Pixel hohen Klotz.
                -->
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    <Link v-if="$page.props.auth.user" :href="route('dashboard')"
                        class="whitespace-nowrap rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link v-if="canLogin" :href="route('login')"
                            class="whitespace-nowrap rounded-field px-3 py-2 text-sm font-medium text-ink-2 transition hover:text-ink sm:px-4">
                            Anmelden
                        </Link>
                        <Link v-if="canRegister" :href="route('register')"
                            class="whitespace-nowrap rounded-field bg-accent px-3 py-2 text-sm font-semibold text-white transition hover:opacity-90 sm:px-4">
                            <span class="sm:hidden">Starten</span>
                            <span class="hidden sm:inline">Kostenlos starten</span>
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <!-- ══ AUFMACHER ══════════════════════════════════════════ -->
        <section class="px-5 pb-16 pt-28 sm:pt-32">
            <div class="mx-auto max-w-5xl">
                <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <h1 class="text-[2.1rem] font-bold leading-[1.1] tracking-tight sm:text-5xl">
                            Ein Coach, der<br class="hidden sm:block" />
                            <span class="text-accent">nachfragt.</span>
                        </h1>
                        <p class="mt-5 max-w-md text-[17px] leading-relaxed text-ink-2">
                            Zone3 liest deine Läufe aus Strava, rechnet daraus deine Schwellenpace
                            und baut einen Plan bis zum Renntag. Ändert sich etwas — Krankheit,
                            eine schlechte Nacht, ein Ziel, das nicht mehr passt — merkt es
                            jemand. Und sagt es dir.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <Link v-if="canRegister" :href="route('register')"
                                class="inline-flex h-12 items-center justify-center rounded-full bg-accent px-7 font-semibold text-white transition hover:opacity-90 active:scale-[0.98]">
                                Kostenlos starten
                            </Link>
                            <a href="#so-arbeitet-es"
                                class="inline-flex h-12 items-center justify-center rounded-full bg-surface-2 px-7 font-semibold text-ink transition hover:bg-surface-3">
                                Wie es arbeitet
                            </a>
                        </div>

                        <p class="mt-4 text-[13px] text-ink-3">
                            Kostenlos · Verbindung zu Strava und Garmin · kein Abo nötig
                        </p>
                    </div>

                    <!-- Nachgebaute Wochenkarte aus dem Dashboard -->
                    <div class="rounded-card bg-surface p-5 shadow-card">
                        <div class="mb-4 flex items-baseline justify-between">
                            <p class="text-[15px] font-semibold text-ink">Deine Woche</p>
                            <span class="text-[13px] text-ink-3">KW 34</span>
                        </div>

                        <div class="grid grid-cols-7 gap-1.5">
                            <div v-for="(d, i) in beispielWoche" :key="i" class="flex flex-col items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full text-[13px] font-bold"
                                    :class="d.heute ? 'bg-warn text-white' : 'text-ink-3'">{{ d.tag }}</span>
                                <span class="flex h-8 w-full items-center justify-center rounded-full text-[12px] font-semibold tabular-nums"
                                    :class="d.aktiv ? 'bg-surface-2 text-ink' : 'text-ink-3'">
                                    {{ d.km ?? '·' }}
                                </span>
                                <span class="h-1.5 w-full rounded-full" :class="d.aktiv ? 'bg-success' : 'bg-surface-2'" />
                            </div>
                        </div>

                        <div class="mt-5 grid grid-cols-3 gap-4 border-t border-line pt-4">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Einheiten</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-ink">2</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Kilometer</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-ink">20,6</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Ø Pace</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-ink">5:14</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ══ SO ARBEITET ES ═════════════════════════════════════ -->
        <section id="so-arbeitet-es" class="scroll-mt-20 px-5 py-16">
            <div class="mx-auto max-w-5xl space-y-16">

                <!-- 1 — Der Plan -->
                <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
                    <div>
                        <p class="text-[13px] font-bold uppercase tracking-wider text-accent">Der Plan</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Gebaut aus deinen Zahlen, nicht aus einer Vorlage</h2>
                        <p class="mt-4 text-[15px] leading-relaxed text-ink-2">
                            Aus deinen letzten Läufen entsteht die Schwellenpace, daraus jedes
                            Tempo im Plan. Der lange Lauf wird vom Renntag rückwärts gerechnet —
                            nicht fortgeschrieben, bis die Woche voll ist. Wieviel Zeit du an
                            welchem Tag hast, steht in deinem Profil und wird eingehalten.
                        </p>
                    </div>

                    <div class="rounded-card bg-surface p-5 shadow-card">
                        <div class="flex items-start gap-2.5">
                            <span class="shrink-0 text-xl leading-none">🏃</span>
                            <h3 class="text-[17px] font-bold leading-tight text-ink">Langer Lauf mit Renntempo</h3>
                        </div>
                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[15px] text-ink-3">
                            <span class="tabular-nums">24 km</span>
                            <span class="tabular-nums">134 min</span>
                            <span class="tabular-nums">5:08–5:57 /km</span>
                        </p>
                        <p class="mt-2 text-[15px] leading-relaxed text-ink-3">
                            Locker beginnen, die letzten 7 km im Zielrenntempo 4:58 — auf müden
                            Beinen. Genau darum geht es.
                        </p>
                        <div class="mt-4 flex items-center gap-2 border-t border-line pt-3 text-[13px] text-ink-3">
                            <span class="h-1.5 w-1.5 rounded-full bg-accent" />
                            Woche 3 von 6 · danach beginnt der Taper
                        </div>
                    </div>
                </div>

                <!-- 2 — Der Coach -->
                <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
                    <div class="lg:order-2">
                        <p class="text-[13px] font-bold uppercase tracking-wider text-success">Der Coach</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Schreib ihm — er ändert den Plan wirklich</h2>
                        <p class="mt-4 text-[15px] leading-relaxed text-ink-2">
                            Kein Chatbot, der Ratschläge gibt und es dabei belässt. Sag ihm, dass
                            du am Sonntag 25 km laufen willst, und die Einheit steht danach im
                            Plan. Was du selbst gesetzt hast, überlebt jede Neuberechnung.
                        </p>
                    </div>

                    <div class="space-y-3 lg:order-1">
                        <div class="ml-auto max-w-[85%] rounded-card rounded-br-sm bg-accent px-4 py-3 text-[15px] text-white shadow-card">
                            Ich möchte am Sonntag einen Longrun über 25 km.
                        </div>
                        <div class="max-w-[90%] rounded-card rounded-bl-sm bg-surface px-4 py-3 shadow-card">
                            <p class="text-[15px] leading-relaxed text-ink">
                                Steht drin. Ich habe die Woche drumherum leichter gelegt — Samstag
                                jetzt Ruhetag statt Tempolauf.
                            </p>
                            <span class="mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-warn-soft px-2.5 py-1 text-[12px] font-medium text-warn-ink">
                                ✏️ Training angepasst (Sonntag, 23.08.)
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 3 — Die Zielprüfung -->
                <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
                    <div>
                        <p class="text-[13px] font-bold uppercase tracking-wider text-warn">Die Zielprüfung</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Jede Woche die Frage, die sonst niemand stellt</h2>
                        <p class="mt-4 text-[15px] leading-relaxed text-ink-2">
                            Ein Plan steht auf der Annahme, dass dein Ziel erreichbar ist. Zone3
                            rechnet das nach — Tempo und Umfang getrennt, denn beide können
                            Verschiedenes sagen. Passt es nicht mehr, wirst du gefragt statt
                            überrascht.
                        </p>
                    </div>

                    <div class="rounded-card bg-surface p-5 shadow-card">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 shrink-0 text-xl leading-none">🎯</span>
                            <div class="min-w-0">
                                <h3 class="text-[15px] font-semibold text-ink">Dein Tempo trägt den Marathon — dein Umfang noch nicht</h3>
                                <p class="mt-1 text-[13px] leading-relaxed text-ink-3">
                                    Die Schwellenpace trägt 3:26. Der Unterbau nicht: typische Woche
                                    36 km, nötig wären etwa 60.
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <div class="flex-1 rounded-field bg-surface-2 px-3 py-2">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Dein Ziel</p>
                                <p class="mt-0.5 text-lg font-bold tabular-nums text-ink">3:30</p>
                            </div>
                            <div class="flex-1 rounded-field bg-surface-2 px-3 py-2">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-ink-3">Deine Form</p>
                                <p class="mt-0.5 text-lg font-bold tabular-nums text-ink">3:26</p>
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <span class="flex-1 rounded-full bg-ink px-3 py-2 text-center text-[13px] font-semibold text-canvas">Auf 3:40 ändern</span>
                            <span class="flex-1 rounded-full bg-surface-2 px-3 py-2 text-center text-[13px] font-semibold text-ink">Ziel bleibt</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ══ WOHER DIE DATEN KOMMEN ═════════════════════════════ -->
        <section class="px-5 py-12">
            <div class="mx-auto max-w-5xl rounded-card bg-surface p-6 shadow-card sm:p-8">
                <h2 class="text-lg font-bold tracking-tight">Es rechnet mit dem, was du ohnehin aufzeichnest</h2>
                <div class="mt-5 grid gap-5 sm:grid-cols-3">
                    <div>
                        <p class="text-[15px] font-semibold text-ink">Strava</p>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-3">
                            Jede Aktivität kommt automatisch herein — Laufen, Rad, Schwimmen.
                            Absolvierte Einheiten werden dem Plan zugeordnet.
                        </p>
                    </div>
                    <div>
                        <p class="text-[15px] font-semibold text-ink">Garmin</p>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-3">
                            HRV, Ruhepuls und Schlaf gegen deine eigene Grundlinie. Miserabel
                            geschlafen? Das Training des Tages wird kürzer.
                        </p>
                    </div>
                    <div>
                        <p class="text-[15px] font-semibold text-ink">Deine Einschätzung</p>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-3">
                            Ein Check-in am Tag. Wenn Uhr und Gefühl sich widersprechen, gewinnt
                            das vorsichtigere von beiden.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ══ ABSCHLUSS ══════════════════════════════════════════ -->
        <section class="px-5 pb-24 pt-8">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Womit läufst du gerade?</h2>
                <p class="mx-auto mt-3 max-w-md text-[15px] leading-relaxed text-ink-2">
                    Strava verbinden, Renndatum eintragen, fertig. Den Rest rechnet Zone3 aus —
                    und meldet sich, wenn etwas nicht mehr passt.
                </p>
                <Link v-if="canRegister" :href="route('register')"
                    class="mt-7 inline-flex h-12 items-center justify-center rounded-full bg-accent px-8 font-semibold text-white transition hover:opacity-90 active:scale-[0.98]">
                    Kostenlos starten
                </Link>
            </div>
        </section>

        <footer class="border-t border-line px-5 py-8">
            <div class="mx-auto flex max-w-5xl flex-col items-center justify-between gap-3 text-[13px] text-ink-3 sm:flex-row">
                <span>Zone3</span>
                <div class="flex gap-5">
                    <Link :href="route('support')" class="transition hover:text-ink-2">Support</Link>
                    <Link v-if="canLogin" :href="route('login')" class="transition hover:text-ink-2">Anmelden</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
