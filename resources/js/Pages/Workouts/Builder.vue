<script setup>
import { zoneRange } from '@/Composables/usePaceZones';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import AppButton from '@/Components/UI/AppButton.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import { sessionType } from '@/Composables/useSessionTypes';
import { fmtNum } from '@/Composables/useFormat';

const props = defineProps({
    workout:   Object,
    paceZones: Object,
});

const isEdit = computed(() => !!props.workout);

const name        = ref(props.workout?.name        ?? '');
const type        = ref(props.workout?.type        ?? 'easy_run');
const description = ref(props.workout?.description ?? '');
const tags        = ref((props.workout?.tags ?? []).join(' '));
const saving      = ref(false);
const saveError   = ref('');

const blocks     = ref(props.workout?.blocks ? JSON.parse(JSON.stringify(props.workout.blocks)) : []);
const expandedId = ref(null);

let _idCounter = 1000;
function uid() { return ++_idCounter; }

const ZONE_COLORS = {
    0: { dot: 'bg-ink-3',    bar: '#94a3b8', badge: 'bg-surface-2 text-ink-3',              border: 'border-line' },
    1: { dot: 'bg-success', bar: '#34d399', badge: 'bg-success-soft text-success-ink',   border: 'border-success/25' },
    2: { dot: 'bg-info',     bar: '#38bdf8', badge: 'bg-info-soft text-info-ink',                  border: 'border-info/25' },
    3: { dot: 'bg-warn',   bar: '#fbbf24', badge: 'bg-warn-soft text-warn-ink',           border: 'border-warn/25' },
    4: { dot: 'bg-warn',  bar: '#f97316', badge: 'bg-warn-soft text-warn-ink',       border: 'border-warn/25' },
    5: { dot: 'bg-danger',     bar: '#ef4444', badge: 'bg-danger-soft text-danger-ink',                  border: 'border-danger/25' },
};

const BLOCK_DEFS = [
    {
        type: 'warmup',    label: 'Aufwärmen',     zone: 2, color: 'sky',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />`,
    },
    {
        type: 'ramp_up',   label: 'Progression ↑', zone: 3, color: 'amber', hasRampSteps: true,
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />`,
    },
    {
        type: 'active',    label: 'Laufen',         zone: 4, color: 'orange',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />`,
    },
    {
        type: 'repeat',    label: 'Intervalle',     zone: 4, color: 'red', hasRepeatSteps: true,
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />`,
    },
    {
        type: 'ramp_down', label: 'Progression ↓', zone: 2, color: 'sky', hasRampSteps: true,
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898m0 0 3.182-5.511m-3.182 5.51-5.511-3.181" />`,
    },
    {
        type: 'recovery',  label: 'Erholung',       zone: 1, color: 'emerald',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />`,
    },
    {
        type: 'cooldown',  label: 'Auslaufen',      zone: 1, color: 'emerald',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="m9 9 10.5-3m0 6.553v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 1 1-.99-3.467l2.31-.66a2.25 2.25 0 0 0 1.632-2.163Zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 0 1-.99-3.467l2.31-.66A2.25 2.25 0 0 0 9 15.553Z" />`,
    },
    {
        type: 'rest',      label: 'Pause',          zone: 0, color: 'gray',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />`,
    },
];


function defFor(t) { return BLOCK_DEFS.find(d => d.type === t) ?? BLOCK_DEFS[2]; }

function addBlock(def) {
    const id = uid();
    const block = {
        _id: id, type: def.type, label: def.label,
        duration_mode: 'time', duration_sec: def.type === 'rest' ? 120 : 600,
        distance_m: null, pace_zone: def.zone, pace: '',
        ...(def.type === 'warmup' || def.type === 'cooldown' ? { lap_button: false } : {}),
    };
    if (def.hasRampSteps) {
        block.steps = def.type === 'ramp_down'
            ? [4,3,2,1].map(z => ({ zone: z, duration_mode: 'time', duration_sec: 120, distance_m: null }))
            : [1,2,3,4].map(z => ({ zone: z, duration_mode: 'time', duration_sec: 120, distance_m: null }));
        delete block.duration_sec; delete block.distance_m; delete block.duration_mode; delete block.pace_zone; delete block.pace;
    }
    if (def.hasRepeatSteps) {
        block.repetitions = 4;
        block.steps = [
            { _id: uid(), type: 'work', label: 'Intervall', duration_mode: 'distance', distance_m: 1000, duration_sec: null, pace_zone: 4, pace: '' },
            { _id: uid(), type: 'rest', label: 'Pause',     duration_mode: 'distance', distance_m:  200, duration_sec: null, pace_zone: 1, pace: '' },
        ];
        delete block.duration_sec; delete block.distance_m; delete block.duration_mode; delete block.pace_zone; delete block.pace;
    }
    blocks.value.push(block);
    expandedId.value = id;
}

function removeBlock(idx)    { blocks.value.splice(idx, 1); }
function moveUp(idx)         { if (idx > 0) blocks.value.splice(idx - 1, 0, blocks.value.splice(idx, 1)[0]); }
function moveDown(idx)       { if (idx < blocks.value.length - 1) blocks.value.splice(idx + 1, 0, blocks.value.splice(idx, 1)[0]); }
function duplicateBlock(idx) { const c = JSON.parse(JSON.stringify(blocks.value[idx])); c._id = uid(); blocks.value.splice(idx + 1, 0, c); }
function toggleExpand(id)    { expandedId.value = expandedId.value === id ? null : id; }

function addRampStep(block) {
    const lastZone = block.steps[block.steps.length - 1]?.zone ?? 1;
    block.steps.push({ zone: Math.min(5, lastZone + 1), duration_mode: 'time', duration_sec: 120, distance_m: null });
}
function removeRampStep(block, idx)   { if (block.steps.length > 2) block.steps.splice(idx, 1); }
function addRepeatStep(block)         { block.steps.push({ _id: uid(), type: 'rest', label: 'Schritt', duration_mode: 'distance', distance_m: 200, duration_sec: null, pace_zone: 1, pace: '' }); }
function removeRepeatStep(block, idx) { if (block.steps.length > 2) block.steps.splice(idx, 1); }

function fmtDur(sec) {
    if (!sec) return '0:00';
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return h > 0 ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}` : `${m}:${String(s).padStart(2,'0')}`;
}
function fmtDist(m) { return m >= 1000 ? `${fmtNum(m / 1000, 2)} km` : `${fmtNum(m, 0)} m`; }
function secToHms(sec) {
    if (!sec) return '00:00';
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return h > 0 ? `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}` : `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}
function hmsToSec(val) {
    const parts = String(val).split(':').map(Number);
    if (parts.length === 3) return parts[0]*3600 + parts[1]*60 + parts[2];
    if (parts.length === 2) return parts[0]*60 + parts[1];
    return Number(val) * 60;
}
function zonePaceLabel(zone) {
    if (!zone) return null;
    const z = props.paceZones?.['z' + zone];
    if (!z) return `Zone ${zone}`;
    return `Z${zone} ${zoneRange(z)}`;
}

const totalDistM = computed(() => {
    let d = 0;
    for (const b of blocks.value) {
        if (b.duration_mode === 'distance' && b.distance_m) { d += Number(b.distance_m); continue; }
        const reps = b.repetitions ?? 1;
        for (const s of b.steps ?? []) {
            if (s.duration_mode === 'distance' && s.distance_m) d += Number(s.distance_m) * reps;
        }
    }
    return d;
});

const totalDurSec = computed(() => {
    let s = 0;
    for (const b of blocks.value) {
        if ((b.duration_mode ?? 'time') === 'time' && b.duration_sec) { s += Number(b.duration_sec); continue; }
        const reps = b.repetitions ?? 1;
        for (const step of b.steps ?? []) {
            if ((step.duration_mode ?? 'time') === 'time' && step.duration_sec) s += Number(step.duration_sec) * reps;
        }
    }
    return s;
});

const barSegments = computed(() => {
    const segs = [];
    let totalW = 0;
    function addSeg(zone, w) {
        if (w <= 0) return;
        const color = ZONE_BG[zone] ?? ZONE_BG[0];
        if (segs.length && segs.at(-1).color === color) { segs.at(-1).weight += w; }
        else segs.push({ color, weight: w });
        totalW += w;
    }
    for (const b of blocks.value) {
        const def = defFor(b.type);
        if (b.type === 'repeat') {
            const reps = b.repetitions ?? 1;
            for (let i = 0; i < reps; i++) {
                for (const s of b.steps ?? []) {
                    addSeg(s.pace_zone ?? 0, s.duration_mode === 'distance' ? (s.distance_m || 0) * 0.3 : (s.duration_sec || 0));
                }
            }
        } else if (b.type === 'ramp_up' || b.type === 'ramp_down') {
            for (const step of b.steps ?? []) {
                addSeg(step.zone ?? 1, step.duration_mode === 'distance' ? (step.distance_m || 0) * 0.3 : (step.duration_sec ?? 120));
            }
        } else {
            addSeg(b.pace_zone ?? def.zone, b.duration_mode === 'distance' ? (b.distance_m || 0) * 0.3 : (b.duration_sec || 0));
        }
    }
    if (!totalW) return [];
    // Meter zählen als Sekunden bei 5:00/km, sonst wiegen Strecken das Zehnfache von Zeiten.
    return segs;
});

// ── Redesign (Paket 3, 28.10.2026): Zeilen statt Kacheln ──────────────────

/** Dieselben Zonenfarben wie die Runden einer Aktivität und das Band in der Bibliothek. */
const ZONE_BG = { 0: 'bg-ink-3/40', 1: 'bg-ink-3/70', 2: 'bg-success', 3: 'bg-accent', 4: 'bg-warn', 5: 'bg-danger' };

const TEXT = { success: 'text-success', warn: 'text-warn', danger: 'text-danger', info: 'text-info', accent: 'text-accent' };
const typeText = computed(() => TEXT[sessionType(type.value).accent] ?? 'text-ink-3');

const paletteOpen = ref(false);

const BLOCK_HINT = {
    warmup:    'Locker einlaufen, Zone 2',
    ramp_up:   'Stufenweise schneller',
    active:    'Ein Abschnitt in einer Zone',
    repeat:    'Belastung und Pause im Wechsel',
    ramp_down: 'Stufenweise langsamer',
    recovery:  'Traben zwischen zwei Blöcken',
    cooldown:  'Locker auslaufen',
    rest:      'Stehen oder gehen',
};

function amountOf(x) {
    return (x.duration_mode ?? 'time') === 'distance' && x.distance_m ? fmtDist(x.distance_m) : (x.duration_sec ? fmtDur(x.duration_sec) : '');
}

function rowColor(b) {
    if (b.type === 'repeat') {
        const work = b.steps?.find(st => st.type === 'work') ?? b.steps?.[0];
        return ZONE_BG[work?.pace_zone ?? 4];
    }
    if (b.type === 'ramp_up' || b.type === 'ramp_down') {
        return ZONE_BG[Math.max(...(b.steps ?? []).map(st => st.zone ?? 1))];
    }
    return ZONE_BG[b.pace_zone ?? defFor(b.type).zone] ?? ZONE_BG[0];
}

/** „800 m · Zone 5 + 400 m · Zone 1" — was ein Durchgang ist. */
function rowFoot(b) {
    if (b.type === 'repeat') {
        return (b.steps ?? []).map(st => [amountOf(st), st.pace_zone ? `Zone ${st.pace_zone}` : null].filter(Boolean).join(' · ')).join(' + ');
    }
    if (b.type === 'ramp_up' || b.type === 'ramp_down') {
        const zones = (b.steps ?? []).map(st => st.zone);
        return `${b.steps?.length ?? 0} Stufen · Zone ${zones[0]} bis ${zones[zones.length - 1]}`;
    }
    const zone = b.pace_zone ?? defFor(b.type).zone;
    return zone > 0 ? (zonePaceLabel(zone) ?? `Zone ${zone}`) : 'ohne Zone';
}

function rowAmount(b) {
    if (b.type === 'repeat') return `${b.repetitions ?? 1} ×`;
    if (b.type === 'ramp_up' || b.type === 'ramp_down') {
        const sec = (b.steps ?? []).reduce((a, st) => a + ((st.duration_mode ?? 'time') !== 'distance' ? (st.duration_sec ?? 0) : 0), 0);
        const m = (b.steps ?? []).reduce((a, st) => a + (st.duration_mode === 'distance' ? (st.distance_m ?? 0) : 0), 0);
        return m ? fmtDist(m) : fmtDur(sec);
    }
    return amountOf(b);
}

async function save() {
    if (!name.value.trim()) { saveError.value = 'Name ist erforderlich.'; return; }
    if (!blocks.value.length) { saveError.value = 'Mindestens einen Block hinzufügen.'; return; }
    saving.value = true; saveError.value = '';
    try {
        const payload = { name: name.value.trim(), type: type.value, description: description.value, blocks: blocks.value, tags: tags.value.trim().split(/\s+/).filter(Boolean) };
        if (isEdit.value) { await axios.put(route('workouts.update', props.workout.id), payload); }
        else              { await axios.post(route('workouts.store'), payload); }
        router.visit(route('workouts.index'));
    } catch(e) {
        saveError.value = e.response?.data?.message ?? 'Fehler beim Speichern.';
    } finally { saving.value = false; }
}
</script>

<template>
    <Head :title="isEdit ? 'Workout bearbeiten' : 'Neues Workout'" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <!-- Navigationsleiste wie ein iOS-Editor: Abbrechen · Titel · Sichern -->
            <div class="mx-auto flex h-[52px] max-w-2xl items-center justify-between gap-3 px-4 lg:mt-4 lg:px-8">
                <Link :href="route('workouts.index')" class="text-[17px] text-accent active:opacity-60">Abbrechen</Link>
                <span class="truncate text-[17px] font-semibold text-ink">{{ isEdit ? 'Workout bearbeiten' : 'Neues Workout' }}</span>
                <button type="button" class="text-[17px] font-semibold text-accent active:opacity-60 disabled:opacity-40" :disabled="saving" @click="save">
                    {{ saving ? 'Sichert…' : 'Sichern' }}
                </button>
            </div>

            <div class="mx-auto max-w-2xl space-y-6 px-4 pb-10 pt-2 lg:px-8">
                <div v-if="saveError" class="rounded-card bg-danger-soft px-4 py-3 text-[15px] text-danger-ink">{{ saveError }}</div>

                <div>
                    <label for="wname" class="mx-1 mb-1.5 block text-[13px] text-ink-3">Name</label>
                    <input id="wname" v-model="name" type="text" placeholder="z. B. 6 × 800 m"
                        class="h-[54px] w-full rounded-card border-0 bg-surface px-4 text-[22px] font-bold text-ink placeholder:font-normal placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
                </div>

                <!-- Zusammenfassung: Typ, drei Zahlen, das Band -->
                <section class="rounded-card bg-surface p-5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-1.5 text-[15px] font-semibold" :class="typeText">
                            <span class="h-2 w-2 rounded-full" :class="sessionType(type).dot" />{{ sessionType(type).label }}
                        </span>
                        <label class="relative text-[15px] text-accent">
                            Typ ändern
                            <select v-model="type" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Typ">
                                <option value="easy_run">Lockerer Lauf</option>
                                <option value="tempo_run">Tempolauf</option>
                                <option value="interval">Intervall</option>
                                <option value="long_run">Langer Lauf</option>
                            </select>
                        </label>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <div>
                            <p class="text-[13px] font-semibold text-ink-3">Distanz</p>
                            <p class="font-rounded text-[28px] font-bold leading-tight tabular-nums text-ink">{{ totalDistM ? fmtNum(totalDistM / 1000) : '–' }}<span class="ml-1 text-[15px] font-semibold text-ink-3">km</span></p>
                        </div>
                        <div>
                            <p class="text-[13px] font-semibold text-ink-3">Dauer</p>
                            <p class="font-rounded text-[28px] font-bold leading-tight tabular-nums text-ink">{{ totalDurSec ? fmtDur(totalDurSec) : '–' }}</p>
                        </div>
                        <div>
                            <p class="text-[13px] font-semibold text-ink-3">Abschnitte</p>
                            <p class="font-rounded text-[28px] font-bold leading-tight tabular-nums text-ink">{{ blocks.length }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex h-3 gap-[2px] overflow-hidden rounded-full bg-surface-2">
                        <i v-for="(seg, i) in barSegments" :key="i" class="block h-full transition-[flex-grow] duration-300" :class="seg.color" :style="{ flex: seg.weight }" />
                    </div>
                    <p v-if="totalDistM && totalDurSec" class="mt-2 text-[13px] text-ink-3">Strecke und Zeit zählen getrennt: Abschnitte nach Strecke stecken in der Distanz, Abschnitte nach Zeit in der Dauer.</p>
                </section>

                <section>
                    <h2 class="mx-1 mb-2 text-[22px] font-bold text-ink">Abschnitte</h2>
                    <p v-if="blocks.length === 0" class="rounded-card bg-surface px-5 py-8 text-center text-[15px] text-ink-3">
                        Noch leer. Füg unten den ersten Abschnitt hinzu, zum Beispiel „Aufwärmen“.
                    </p>

                    <!-- Block list -->
                    <div v-if="blocks.length" class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                        <div v-for="(block, idx) in blocks" :key="block._id ?? idx">

                            <!-- Zeile: Farbbalken, Name, Zone, Umfang. Ein Tipp klappt den Editor auf. -->
                            <button type="button" class="grid w-full grid-cols-[4px_1fr_auto_16px] items-center gap-3 px-4 py-3 text-left transition-colors active:bg-surface-2"
                                @click="toggleExpand(block._id ?? idx)">
                                <i class="block h-10 w-1 rounded-full" :class="rowColor(block)" />
                                <span class="min-w-0">
                                    <span class="block truncate text-[17px] text-ink">{{ block.label }}<span v-if="block.lap_button" class="ml-2 text-[13px] font-semibold text-info">LAP</span></span>
                                    <span class="block truncate text-[13px] text-ink-3">{{ rowFoot(block) }}</span>
                                </span>
                                <span class="font-rounded text-[17px] font-bold tabular-nums text-ink">{{ rowAmount(block) }}</span>
                                <svg class="h-4 w-4 text-ink-3/60 transition-transform duration-200 ease-in-out-strong" :class="expandedId === (block._id ?? idx) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </button>

                            <!-- Expanded editor -->
                            <div v-if="expandedId === (block._id ?? idx)"
                                class="space-y-3 bg-surface-2/40 px-4 py-4">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="h-9 rounded-full bg-surface px-3.5 text-[15px] text-accent disabled:opacity-30" :disabled="idx === 0" @click="moveUp(idx)">Nach oben</button>
                                    <button type="button" class="h-9 rounded-full bg-surface px-3.5 text-[15px] text-accent disabled:opacity-30" :disabled="idx === blocks.length - 1" @click="moveDown(idx)">Nach unten</button>
                                    <button type="button" class="h-9 rounded-full bg-surface px-3.5 text-[15px] text-accent" @click="duplicateBlock(idx)">Duplizieren</button>
                                    <button type="button" class="h-9 rounded-full bg-surface px-3.5 text-[15px] text-danger" @click="removeBlock(idx)">Löschen</button>
                                </div>

                                <!-- Label -->
                                <div>
                                    <label class="block text-[13px] font-semibold text-ink-3 mb-1">Bezeichnung</label>
                                    <input v-model="block.label" type="text"
                                        class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                                </div>

                                <!-- ── Simple blocks ── -->
                                <template v-if="!block.steps">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[13px] font-semibold text-ink-3 mb-1">Modus</label>
                                            <select v-model="block.duration_mode"
                                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                                                <option value="time">Zeit</option>
                                                <option value="distance">Distanz</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[13px] font-semibold text-ink-3 mb-1">
                                                {{ block.duration_mode === 'distance' ? 'Distanz (m)' : 'Dauer (MM:SS)' }}
                                            </label>
                                            <input v-if="block.duration_mode === 'distance'"
                                                v-model.number="block.distance_m" type="number" min="0" step="100" placeholder="1000"
                                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                                            <input v-else
                                                :value="secToHms(block.duration_sec)"
                                                @change="block.duration_sec = hmsToSec($event.target.value)"
                                                type="text" placeholder="10:00"
                                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                                        </div>
                                    </div>
                                    <div v-if="block.type !== 'rest'" class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[13px] font-semibold text-ink-3 mb-1">Zone</label>
                                            <select v-model.number="block.pace_zone"
                                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                                                <option :value="0">— keine —</option>
                                                <option v-for="z in [1,2,3,4,5]" :key="z" :value="z">Zone {{ z }}{{ paceZones?.['z'+z] ? ' · ' + zonePaceLabel(z) : '' }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[13px] font-semibold text-ink-3 mb-1">Pace-Ziel (MM:SS)</label>
                                            <input v-model="block.pace" type="text" placeholder="z.B. 5:30"
                                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                                        </div>
                                    </div>

                                    <!-- Lap-Taste (nur Aufwärmen + Auslaufen) -->
                                    <div v-if="block.type === 'warmup' || block.type === 'cooldown'"
                                        class="flex items-center gap-3 rounded-field bg-info-soft border border-info/25 px-3 py-3">
                                        <button type="button" @click="block.lap_button = !block.lap_button"
                                            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-info focus:ring-offset-2"
                                            :class="block.lap_button ? 'bg-info' : 'bg-surface-3'">
                                            <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-surface shadow transform transition duration-200 ease-in-out"
                                                :class="block.lap_button ? 'translate-x-5' : 'translate-x-0'" />
                                        </button>
                                        <div>
                                            <p class="text-xs font-semibold text-info-ink">Lap-Taste aktivieren</p>
                                            <p class="text-[11px] text-info-ink/70 mt-0.5 leading-snug">
                                                Block frühzeitig per Lap-Taste beenden und in den nächsten springen.
                                            </p>
                                        </div>
                                    </div>
                                </template>

                                <!-- ── Ramp blocks ── -->
                                <template v-else-if="block.type === 'ramp_up' || block.type === 'ramp_down'">
                                    <div class="space-y-2">
                                        <div v-for="(step, si) in block.steps" :key="si"
                                            class="rounded-field border border-line p-2.5 space-y-2">
                                            <div class="flex items-center gap-2">
                                                <span class="h-2 w-2 rounded-full shrink-0" :class="ZONE_COLORS[step.zone]?.dot ?? 'bg-ink-3'" />
                                                <select v-model.number="step.zone"
                                                    class="flex-1 rounded-field border border-line bg-surface-2 px-2 py-1.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                                                    <option v-for="z in [1,2,3,4,5]" :key="z" :value="z">Zone {{ z }}{{ paceZones?.['z'+z] ? ' · ' + zonePaceLabel(z) : '' }}</option>
                                                </select>
                                                <button @click="removeRampStep(block, si)" :disabled="block.steps.length <= 2"
                                                    class="p-1 rounded-lg text-ink-3 hover:text-danger disabled:opacity-20 transition-colors">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                                </button>
                                            </div>
                                            <div class="flex gap-2">
                                                <select v-model="step.duration_mode"
                                                    class="rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                                                    <option value="time">Zeit</option>
                                                    <option value="distance">Distanz</option>
                                                </select>
                                                <input v-if="step.duration_mode === 'distance'"
                                                    v-model.number="step.distance_m" type="number" min="0" step="100" placeholder="400 m"
                                                    class="flex-1 rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                                                <input v-else
                                                    :value="secToHms(step.duration_sec)"
                                                    @change="step.duration_sec = hmsToSec($event.target.value)"
                                                    type="text" placeholder="03:00"
                                                    class="flex-1 rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                                            </div>
                                        </div>
                                    </div>
                                    <button @click="addRampStep(block)"
                                        class="inline-flex items-center gap-1 text-xs text-accent-ink hover:underline">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        Stufe hinzufügen
                                    </button>
                                </template>

                                <!-- ── Intervalle (Repeat) blocks ── -->
                                <template v-else-if="block.type === 'repeat'">
                                    <div class="flex items-center gap-3">
                                        <label class="text-[13px] font-semibold text-ink-3">Wiederholungen</label>
                                        <div class="flex items-center gap-1">
                                            <button @click="block.repetitions = Math.max(1, (block.repetitions ?? 1) - 1)"
                                                class="h-7 w-7 rounded-lg border border-line bg-surface-2 text-sm font-bold text-ink-2 hover:bg-surface-2 transition-colors flex items-center justify-center">−</button>
                                            <span class="w-8 text-center text-sm font-bold text-ink">{{ block.repetitions ?? 1 }}</span>
                                            <button @click="block.repetitions = Math.min(20, (block.repetitions ?? 1) + 1)"
                                                class="h-7 w-7 rounded-lg border border-line bg-surface-2 text-sm font-bold text-ink-2 hover:bg-surface-2 transition-colors flex items-center justify-center">+</button>
                                        </div>
                                        <span class="text-xs text-ink-3">× wiederholen</span>
                                    </div>

                                    <div class="space-y-2">
                                        <div v-for="(step, si) in block.steps" :key="step._id ?? si"
                                            class="rounded-field border-2 overflow-hidden"
                                            :class="step.type === 'work' ? 'border-danger/25' : step.type === 'rest' ? 'border-success/25' : 'border-info/25'">
                                            <!-- Step type header -->
                                            <div class="px-3 py-1.5 flex items-center gap-2"
                                                :class="step.type === 'work' ? 'bg-danger-soft' : step.type === 'rest' ? 'bg-success-soft' : 'bg-info-soft'">
                                                <select v-model="step.type"
                                                    class="rounded-lg border-0 bg-transparent text-[13px] font-bold focus:outline-none cursor-pointer"
                                                    :class="step.type === 'work' ? 'text-danger-ink' : step.type === 'rest' ? 'text-success-ink' : 'text-info-ink'">
                                                    <option value="work">ARBEIT</option>
                                                    <option value="rest">PAUSE</option>
                                                    <option value="float">FLOAT</option>
                                                </select>
                                                <input v-model="step.label" type="text" placeholder="Bezeichnung"
                                                    class="flex-1 rounded-lg border-0 bg-transparent text-xs text-ink-2 focus:outline-none placeholder-ink-3" />
                                                <button @click="removeRepeatStep(block, si)" :disabled="block.steps.length <= 2"
                                                    class="p-0.5 rounded text-ink-3 hover:text-danger disabled:opacity-20 transition-colors">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                                </button>
                                            </div>
                                            <!-- Step inputs -->
                                            <div class="px-3 py-2.5 grid grid-cols-2 gap-2 bg-surface">
                                                <select v-model="step.duration_mode"
                                                    class="rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none">
                                                    <option value="time">Zeit</option>
                                                    <option value="distance">Distanz</option>
                                                </select>
                                                <input v-if="step.duration_mode === 'distance'"
                                                    v-model.number="step.distance_m" type="number" min="0" step="100" placeholder="Meter"
                                                    class="rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none" />
                                                <input v-else
                                                    :value="secToHms(step.duration_sec)"
                                                    @change="step.duration_sec = hmsToSec($event.target.value)"
                                                    type="text" placeholder="MM:SS"
                                                    class="rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none" />
                                                <select v-model.number="step.pace_zone"
                                                    class="rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none">
                                                    <option :value="0">— keine Zone —</option>
                                                    <option v-for="z in [1,2,3,4,5]" :key="z" :value="z">Z{{ z }}{{ paceZones?.['z'+z] ? ' · ' + zonePaceLabel(z) : '' }}</option>
                                                </select>
                                                <input v-model="step.pace" type="text" placeholder="Pace (opt.)"
                                                    class="rounded-field border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink focus:outline-none" />
                                            </div>
                                        </div>
                                    </div>
                                    <button @click="addRepeatStep(block)"
                                        class="inline-flex items-center gap-1 text-xs text-accent-ink hover:underline">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        Schritt hinzufügen
                                    </button>
                                </template>

                            </div>
                        </div>
                    </div>
                </section>

                <AppButton variant="secondary" block @click="paletteOpen = true">
                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    Abschnitt hinzufügen
                </AppButton>

                <!-- Notiz und Tags -->
                <section class="space-y-3">
                    <div>
                        <label for="wdesc" class="mx-1 mb-1.5 block text-[13px] text-ink-3">Notiz</label>
                        <textarea id="wdesc" v-model="description" rows="2" placeholder="Optional"
                            class="w-full resize-none rounded-card border-0 bg-surface px-4 py-3 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
                    </div>
                    <div>
                        <label for="wtags" class="mx-1 mb-1.5 block text-[13px] text-ink-3">Tags, mit Leerzeichen getrennt</label>
                        <input id="wtags" v-model="tags" type="text" placeholder="#intervall #bahn"
                            class="h-11 w-full rounded-card border-0 bg-surface px-4 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent" />
                    </div>
                </section>
            </div>
        </div>

        <!-- Abschnitt wählen: eine Liste statt acht bunter Kacheln -->
        <AppSheet :show="paletteOpen" title="Abschnitt hinzufügen" @close="paletteOpen = false">
            <div class="divide-y divide-line overflow-hidden rounded-card bg-surface-2/60">
                <button v-for="def in BLOCK_DEFS" :key="def.type" type="button"
                    class="flex min-h-[52px] w-full items-center gap-3 px-4 py-2 text-left transition-colors active:bg-surface-3"
                    @click="addBlock(def); paletteOpen = false">
                    <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] text-white" :class="ZONE_BG[def.zone] ?? 'bg-ink-3'">
                        <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" v-html="def.icon" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[17px] text-ink">{{ def.label }}</span>
                        <span class="block text-[13px] text-ink-3">{{ BLOCK_HINT[def.type] }}</span>
                    </span>
                </button>
            </div>
        </AppSheet>
    </AuthenticatedLayout>
</template>
