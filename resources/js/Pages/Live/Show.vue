<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({
    track:   { type: Object,  required: true },
    isCrew:  { type: Boolean, default: false },
    crewKey: { type: String,  default: null },
});

const data = ref({ ...props.track });

// ── Uhr ──────────────────────────────────────────────────────────────────────
// Tickt lokal jede Sekunde. Die Yard-Rechnung haengt allein an der Startzeit,
// nicht an Garmin — faellt LiveTrack aus, laeuft die Uhr trotzdem weiter.
const now = ref(Date.now());
let clockTimer = null;
let pollTimer  = null;

const startMs   = computed(() => new Date(data.value.startsAt).getTime());
const elapsedMs = computed(() => now.value - startMs.value);
const HOUR      = 3600_000;

const hasStarted = computed(() => elapsedMs.value >= 0);

/** Endstand gesetzt? Dann friert die Seite dort ein. */
const isOver = computed(() => data.value.stoppedAtYard != null);

/** Laufender Yard, 1-basiert. Vor dem Start 0. */
const currentYard = computed(() => {
    if (isOver.value) return data.value.stoppedAtYard ?? 0;
    return hasStarted.value ? Math.floor(elapsedMs.value / HOUR) + 1 : 0;
});

/** Sekunden bis zur naechsten vollen Stunde — der Glocke. */
const secondsToBell = computed(() => {
    if (!hasStarted.value) return Math.max(0, Math.round(-elapsedMs.value / 1000));
    return Math.round((HOUR - (elapsedMs.value % HOUR)) / 1000);
});

/** Anteil des laufenden Yards, der schon vorbei ist. */
const yardProgress = computed(() => {
    if (!hasStarted.value) return 0;
    return ((elapsedMs.value % HOUR) / HOUR) * 100;
});

/**
 * Abgeschlossene Runden. Bei einem Backyard startet jede Runde zur vollen
 * Stunde — wer in Runde N ist, hat N-1 hinter sich. Das braucht keine
 * Verbindung zu Garmin.
 */
const completedYards = computed(() => {
    if (isOver.value) return data.value.stoppedAtYard ?? 0;
    return Math.max(0, currentYard.value - 1);
});

/** Gesicherte Distanz: abgeschlossene Runden mal Rundenlaenge. */
const confirmedKm = computed(() => completedYards.value * (data.value.yardKm ?? 0));

/**
 * Hochrechnung innerhalb der laufenden Runde. Sie zaehlt weich hoch,
 * damit die Seite lebt — die Zahl springt sonst nur einmal pro Stunde.
 *
 * Das ist ausdruecklich KEINE Messung: es gibt keine Livedaten von der
 * Uhr, deshalb wird auch kein Zufallsrauschen daraufgelegt, das eine
 * vortaeuschen wuerde. Die Beschriftung sagt, dass es geschaetzt ist,
 * und mehr als eine Runde pro Stunde kann es nie werden.
 */
const estimatedKm = computed(() => {
    if (isOver.value || !hasStarted.value) return confirmedKm.value;

    const paceSec = data.value.assumedPaceSec || 420;
    const secondsIntoYard = (elapsedMs.value % HOUR) / 1000;
    const inYard = Math.min(data.value.yardKm ?? 0, secondsIntoYard / paceSec);

    return confirmedKm.value + inYard;
});

/** Garmins Wert gewinnt, falls er je ankommt. */
const distanceKm = computed(() =>
    Math.max(estimatedKm.value, data.value.distanceKm ?? 0).toFixed(2)
);

/** Solange die Runde laeuft, ist die Zahl eine Hochrechnung. */
const distanceIsEstimate = computed(() =>
    !isOver.value && hasStarted.value && !data.value.distanceKm
);

const assumedPaceLabel = computed(() => {
    const p = data.value.assumedPaceSec || 420;
    return `${Math.floor(p / 60)}:${String(p % 60).padStart(2, '0')}`;
});

// ── Formatierung ─────────────────────────────────────────────────────────────
function clock(totalSeconds) {
    if (totalSeconds == null || totalSeconds < 0) return '–';
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = Math.floor(totalSeconds % 60);
    return h > 0
        ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
        : `${m}:${String(s).padStart(2, '0')}`;
}

const elapsedTotal = computed(() =>
    hasStarted.value ? Math.floor(elapsedMs.value / 1000) : null
);

const startLabel = computed(() =>
    new Date(data.value.startsAt).toLocaleString('de-DE', {
        weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    })
);

// Die Glocke wird in der letzten Minute dringlich.
const bellUrgent = computed(() => hasStarted.value && secondsToBell.value <= 60);

// ── Karte ────────────────────────────────────────────────────────────────────
// Zwei Wege: eigene Leaflet-Karte, wenn Positionen ankommen (dann steht kein
// Garmin-Token in der Seite), sonst Garmins eigene Seite im iframe.
const mapEl = ref(null);
let map = null, trackLine = null, marker = null, L = null;

async function initMap() {
    if (map || !mapEl.value || !data.value.path?.length) return;

    L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');

    map = L.map(mapEl.value, { zoomControl: true, attributionControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    drawMap();
}

function drawMap() {
    if (!map || !L) return;

    const path = data.value.path ?? [];
    if (!path.length) return;

    if (trackLine) trackLine.remove();
    trackLine = L.polyline(path, { color: '#4f46e5', weight: 4, opacity: 0.8 }).addTo(map);

    const pos = data.value.position ?? path[path.length - 1];
    if (marker) marker.remove();
    marker = L.circleMarker(pos, {
        radius: 8, color: '#fff', weight: 3, fillColor: '#ef4444', fillOpacity: 1,
    }).addTo(map);

    map.fitBounds(trackLine.getBounds(), { padding: [24, 24] });
}

// ── Crew-Steuerung ───────────────────────────────────────────────────────────
// Sichtbar nur mit gueltigem Schluessel. Der oeffentliche Link allein
// reicht nicht — sonst koennte jeder Zuschauer das Rennen "beenden".
const crewBusy = ref(false);
const noteInput = ref('');
const yardInput = ref(null);

async function crewPost(payload) {
    crewBusy.value = true;
    try {
        const { data: fresh } = await axios.post(
            `${window.location.pathname}/crew`,
            { crew: props.crewKey, ...payload }
        );
        data.value = fresh;
    } finally {
        crewBusy.value = false;
    }
}

function crewFinish() {
    crewPost({ stopped_at_yard: yardInput.value ?? completedYards.value });
}

function crewResume() {
    crewPost({ stopped_at_yard: null });
}

function crewSaveNote() {
    if (!noteInput.value.trim()) return;
    crewPost({ note: noteInput.value });
    noteInput.value = '';
}

/** „vor 12 Min" statt Uhrzeit — bei einem 24-Stunden-Lauf lesbarer. */
function agoLabel(iso) {
    const mins = Math.round((now.value - new Date(iso).getTime()) / 60000);
    if (mins < 1)  return 'gerade eben';
    if (mins < 60) return `vor ${mins} Min`;
    const h = Math.floor(mins / 60);
    return `vor ${h} Std`;
}

// ── Nachladen ────────────────────────────────────────────────────────────────
const fetchFailed = ref(false);

async function refresh() {
    try {
        const { data: fresh } = await axios.get(`${window.location.pathname}/data`,
            props.crewKey ? { params: { crew: props.crewKey } } : undefined);
        data.value = fresh;
        fetchFailed.value = false;
        if (map) drawMap(); else initMap();
    } catch {
        fetchFailed.value = true;
    }
}

onMounted(() => {
    clockTimer = setInterval(() => { now.value = Date.now(); }, 1000);
    pollTimer  = setInterval(refresh, 45_000);
    initMap();
});

onUnmounted(() => {
    clearInterval(clockTimer);
    clearInterval(pollTimer);
    map?.remove();
});
</script>

<template>
    <Head :title="data.title" />

    <div class="min-h-screen bg-canvas">
        <div class="mx-auto max-w-3xl space-y-4 px-4 py-5">

            <!-- Kopf — bewusst ohne Name und Foto -->
            <header class="px-1">
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ data.title }}</h1>
                <p class="mt-0.5 text-[13px] text-ink-3">Start: {{ startLabel }}</p>
            </header>

            <!-- ══════════════════════════════════════════════════
                 DIE GLOCKE — das Wichtigste auf der Seite
                 ══════════════════════════════════════════════════ -->
            <section class="rounded-card bg-surface p-6 text-center shadow-card">
                <template v-if="isOver">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-ink-3">Endstand</p>
                    <p class="mt-2 text-6xl font-bold tabular-nums tracking-tight text-ink">{{ completedYards }}</p>
                    <p class="mt-1 text-[15px] text-ink-3">
                        {{ completedYards === 1 ? 'Runde' : 'Runden' }} · {{ distanceKm }} km
                    </p>
                </template>

                <template v-else-if="!hasStarted">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-ink-3">Start in</p>
                    <p class="mt-2 text-5xl font-bold tabular-nums tracking-tight text-ink">{{ clock(secondsToBell) }}</p>
                </template>

                <template v-else>
                    <p class="text-[11px] font-bold uppercase tracking-widest text-ink-3">Yard {{ currentYard }}</p>
                    <p class="mt-2 text-6xl font-bold tabular-nums tracking-tight"
                        :class="bellUrgent ? 'text-danger' : 'text-ink'">
                        {{ clock(secondsToBell) }}
                    </p>
                    <p class="mt-1 text-[13px] text-ink-3">bis zur nächsten Glocke</p>

                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full rounded-full transition-[width] duration-1000 ease-linear"
                            :class="bellUrgent ? 'bg-danger' : 'bg-success'"
                            :style="{ width: yardProgress + '%' }" />
                    </div>
                </template>
            </section>

            <!-- ══════════════════════════════════════════════════
                 FORTSCHRITT
                 ══════════════════════════════════════════════════ -->
            <section class="grid grid-cols-2 gap-3">
                <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Runden</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink">
                        {{ completedYards }}<span v-if="data.targetYards" class="text-[15px] font-medium text-ink-3"> / {{ data.targetYards }}</span>
                    </p>
                </div>
                <div class="min-w-0 rounded-card bg-surface p-4 shadow-card">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-ink-3">Distanz</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink">
                        {{ distanceKm }}<span class="text-[15px] font-medium text-ink-3"> km</span>
                    </p>
                    <p class="mt-0.5 text-[11px] text-ink-3">
                        <template v-if="distanceIsEstimate">geschätzt · {{ assumedPaceLabel }} /km</template>
                        <template v-else-if="isOver">Endstand</template>
                        <template v-else>aus {{ completedYards }} Runden</template>
                    </p>
                </div>
            </section>

            <!-- Gesamtzeit -->
            <section v-if="hasStarted" class="rounded-card bg-surface px-5 py-4 shadow-card">
                <div class="flex items-baseline justify-between">
                    <span class="text-[13px] text-ink-3">Unterwegs seit</span>
                    <span class="text-xl font-bold tabular-nums text-ink">{{ clock(elapsedTotal) }}</span>
                </div>
            </section>

            <!-- ══════════════════════════════════════════════════
                 KARTE — selbst gezeichnet, damit der Garmin-Link
                 (der den Token enthält) nicht öffentlich wird
                 ══════════════════════════════════════════════════ -->
            <section v-if="data.path?.length" class="overflow-hidden rounded-card bg-surface shadow-card">
                <div class="px-5 py-3.5">
                    <h2 class="text-[15px] font-semibold text-ink">Strecke</h2>
                </div>
                <div ref="mapEl" class="h-[360px] w-full" />
            </section>

            <!-- Ohne eigene Positionen: Garmins Seite einbetten -->
            <section v-else-if="data.mapUrl" class="overflow-hidden rounded-card bg-surface shadow-card">
                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <h2 class="text-[15px] font-semibold text-ink">Live-Position</h2>
                    <a :href="data.mapUrl" target="_blank" rel="noopener"
                        class="text-[13px] font-semibold text-accent hover:underline">Bei Garmin öffnen</a>
                </div>
                <iframe
                    :src="data.mapUrl"
                    class="h-[440px] w-full border-0"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    title="Garmin LiveTrack"
                />
            </section>

            <!-- ══════════════════════════════════════════════════
                 NEWSTICKER — von der Crew geschrieben
                 ══════════════════════════════════════════════════ -->
            <section v-if="data.notes?.length" class="rounded-card bg-surface p-5 shadow-card">
                <h2 class="mb-4 text-[15px] font-semibold text-ink">Neues von der Strecke</h2>
                <ol class="space-y-4">
                    <li v-for="(n, i) in data.notes" :key="n.id" class="flex gap-3">
                        <div class="flex flex-col items-center pt-1">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full"
                                :class="i === 0 ? 'bg-success' : 'bg-surface-3'" />
                            <span v-if="i < data.notes.length - 1" class="mt-1 w-px flex-1 bg-line" />
                        </div>
                        <div class="min-w-0 flex-1 pb-1">
                            <p class="text-[15px] leading-relaxed text-ink">{{ n.text }}</p>
                            <p class="mt-1 flex items-center gap-2 text-[12px] text-ink-3">
                                {{ agoLabel(n.at) }}
                                <button v-if="isCrew" class="font-semibold text-danger hover:underline"
                                    @click="crewPost({ delete_note: n.id })">löschen</button>
                            </p>
                        </div>
                    </li>
                </ol>
            </section>

            <!-- ══════════════════════════════════════════════════
                 CREW — nur mit gueltigem Schluessel
                 ══════════════════════════════════════════════════ -->
            <section v-if="isCrew" class="rounded-card bg-surface p-5 shadow-card">
                <div class="mb-4 flex items-center gap-2">
                    <span class="rounded-full bg-warn-soft px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-warn-ink">Crew</span>
                    <p class="text-[13px] text-ink-3">Nur du und deine Crew seht diesen Abschnitt.</p>
                </div>

                <!-- Newsticker -->
                <label class="z-label">Meldung für den Ticker</label>
                <div class="flex gap-2">
                    <input v-model="noteInput" type="text" maxlength="200" class="z-input"
                        placeholder="z.B. Runde 12 geschafft, alles gut" @keyup.enter="crewSaveNote" />
                    <AppButton :loading="crewBusy" @click="crewSaveNote">Senden</AppButton>
                </div>
                <p class="z-hint">Erscheint sofort für alle Zuschauer, neueste Meldung oben.</p>

                <!-- Rennende -->
                <div class="mt-5 border-t border-line pt-4">
                    <template v-if="isOver">
                        <p class="text-[15px] font-semibold text-ink">
                            Endstand: {{ data.stoppedAtYard }} {{ data.stoppedAtYard === 1 ? 'Runde' : 'Runden' }}
                        </p>
                        <AppButton variant="secondary" size="sm" class="mt-3" :loading="crewBusy" @click="crewResume">
                            Doch noch unterwegs
                        </AppButton>
                    </template>

                    <template v-else>
                        <label class="z-label">
                            Endgültige Rundenzahl
                            <span class="font-normal text-ink-3">(leer = aktueller Stand: {{ completedYards }})</span>
                        </label>
                        <input v-model.number="yardInput" type="number" min="0" class="z-input" :placeholder="String(completedYards)" />
                        <AppButton block class="mt-3" :loading="crewBusy" @click="crewFinish">
                            Rennen beenden
                        </AppButton>
                    </template>
                </div>
            </section>

            <!-- Hinweis, wenn die Daten alt sind -->
            <p v-if="(data.stale || fetchFailed) && !isOver" class="px-1 text-[13px] text-ink-3">
                Die Livewerte kommen gerade nicht durch — Funkloch oder Handy aus.
                Die Uhr oben läuft trotzdem korrekt weiter.
            </p>

            <footer class="px-1 pb-4 pt-2 text-[12px] text-ink-3">
                Rundenzähler und Uhr laufen nach der Startzeit. Die Distanz innerhalb der
                laufenden Runde ist eine Hochrechnung bei {{ assumedPaceLabel }} /km, keine Messung.
            </footer>
        </div>
    </div>
</template>
