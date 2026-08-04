<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

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

const BLOCK_COLOR_CLASSES = {
    sky:     { stripe: 'bg-info',     btn: 'bg-info-soft text-info-ink border-info/25' },
    amber:   { stripe: 'bg-warn',   btn: 'bg-warn-soft text-warn-ink border-warn/25' },
    orange:  { stripe: 'bg-warn',  btn: 'bg-warn-soft text-warn-ink border-warn/25' },
    red:     { stripe: 'bg-danger',     btn: 'bg-danger-soft text-danger-ink border-danger/25' },
    emerald: { stripe: 'bg-success', btn: 'bg-success-soft text-success-ink border-success/25' },
    gray:    { stripe: 'bg-surface-3', btn: 'bg-surface-2 text-ink-2 border-line' },
};

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
function fmtDist(m) { return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : m + ' m'; }
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
    return z.max_pace === '∞' ? `Z${zone} > ${z.min_pace}` : `Z${zone} ${z.min_pace}–${z.max_pace}`;
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
        const color = ZONE_COLORS[zone]?.bar ?? '#94a3b8';
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
                    addSeg(s.pace_zone ?? 0, s.duration_mode === 'distance' ? (s.distance_m || 0) : (s.duration_sec || 0));
                }
            }
        } else if (b.type === 'ramp_up' || b.type === 'ramp_down') {
            for (const step of b.steps ?? []) {
                addSeg(step.zone ?? 1, step.duration_mode === 'distance' ? (step.distance_m || 0) : (step.duration_sec ?? 120));
            }
        } else {
            addSeg(b.pace_zone ?? def.zone, b.duration_mode === 'distance' ? (b.distance_m || 0) : (b.duration_sec || 0));
        }
    }
    if (!totalW) return [];
    return segs.map(s => ({ ...s, pct: Math.round(s.weight / totalW * 100) })).filter(s => s.pct > 0);
});

const previewSteps = computed(() => blocks.value.map((b, idx) => {
    const def = defFor(b.type);
    if (b.type === 'repeat') {
        const reps = b.repetitions ?? 1;
        const w = b.steps?.find(s => s.type === 'work') ?? b.steps?.[0];
        const r = b.steps?.find(s => s.type === 'rest') ?? b.steps?.[1];
        const wLabel = w ? (w.duration_mode === 'distance' ? fmtDist(w.distance_m) : fmtDur(w.duration_sec)) + ` Z${w.pace_zone}` : '?';
        const rLabel = r ? (r.duration_mode === 'distance' ? fmtDist(r.distance_m) : fmtDur(r.duration_sec)) + ` Z${r.pace_zone}` : '?';
        return { key: b._id ?? idx, label: b.label, sublabel: `${reps}× · ${wLabel} + ${rLabel}`, dot: ZONE_COLORS[4].dot, isRepeat: true, reps };
    }
    if (b.type === 'ramp_up' || b.type === 'ramp_down') {
        const zones = (b.steps ?? []).map(s => s.zone);
        const zMin = Math.min(...zones), zMax = Math.max(...zones);
        const totalSec  = (b.steps ?? []).reduce((a, s) => a + (s.duration_mode !== 'distance' ? (s.duration_sec ?? 120) : 0), 0);
        const totalDist = (b.steps ?? []).reduce((a, s) => a + (s.duration_mode === 'distance' ? (s.distance_m ?? 0) : 0), 0);
        const durLabel = totalDist ? fmtDist(totalDist) : (totalSec ? fmtDur(totalSec) : '');
        return { key: b._id ?? idx, label: b.label, sublabel: `Z${zMin}→Z${zMax} · ${b.steps?.length} Stufen` + (durLabel ? ` · ${durLabel}` : ''), dot: ZONE_COLORS[def.zone]?.dot ?? 'bg-warn', isRamp: true };
    }
    const zone = b.pace_zone ?? def.zone;
    let durLabel = '';
    if (b.duration_mode === 'distance' && b.distance_m) durLabel = fmtDist(b.distance_m);
    else if (b.duration_sec) durLabel = fmtDur(b.duration_sec);
    const zoneStr = zone > 0 ? zonePaceLabel(zone) : null;
    return { key: b._id ?? idx, label: b.label, sublabel: [durLabel, zoneStr].filter(Boolean).join(' · '), dot: ZONE_COLORS[zone]?.dot ?? 'bg-ink-3', lapButton: !!b.lap_button };
}));

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
        <div class="px-4 sm:px-6 py-6">

            <!-- Header -->
            <div class="flex items-center gap-3 mb-6 flex-wrap">
                <Link :href="route('workouts.index')" class="text-ink-3 hover:text-ink-2 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                </Link>
                <h1 class="text-xl font-bold text-ink flex-1">{{ isEdit ? 'Workout bearbeiten' : 'Neues Workout' }}</h1>
                <button @click="save" :disabled="saving"
                    class="inline-flex items-center gap-2 rounded-field bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-card">
                    <svg v-if="saving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    {{ saving ? 'Speichern…' : 'Speichern' }}
                </button>
            </div>

            <div v-if="saveError" class="mb-4 rounded-field bg-danger-soft border border-danger/25 px-4 py-3 text-sm text-danger-ink">{{ saveError }}</div>

            <div class="grid lg:grid-cols-[280px_1fr] gap-5 items-start">

                <!-- ── LEFT: Metadata + Palette ───────────────────────── -->
                <div class="space-y-4">

                    <!-- Metadata -->
                    <div class="bg-surface rounded-card border border-line p-4 space-y-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Name</label>
                            <input v-model="name" type="text" placeholder="z.B. 5×1km Intervall"
                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Typ</label>
                            <select v-model="type"
                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                                <option value="easy_run">Lockerer Lauf</option>
                                <option value="tempo_run">Tempolauf</option>
                                <option value="interval">Intervall</option>
                                <option value="long_run">Langer Lauf</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Notiz</label>
                            <textarea v-model="description" rows="2" placeholder="Optional…"
                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40 resize-none" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Tags <span class="font-normal normal-case">(mit Leerzeichen trennen)</span></label>
                            <input v-model="tags" type="text" placeholder="#intervall #tempo"
                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                        </div>
                    </div>

                    <!-- Palette (2-col) -->
                    <div class="bg-surface rounded-card border border-line p-4">
                        <p class="text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-3">Segment hinzufügen</p>
                        <div class="grid grid-cols-2 gap-2">
                            <button v-for="def in BLOCK_DEFS" :key="def.type"
                                @click="addBlock(def)"
                                class="flex items-center gap-2 rounded-field border px-2.5 py-2 text-xs font-semibold transition-colors hover:shadow-card text-left"
                                :class="BLOCK_COLOR_CLASSES[def.color].btn">
                                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="def.icon" />
                                <span class="truncate">{{ def.label }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── RIGHT: Live Preview + Block Editor ──────────────── -->
                <div class="space-y-4 min-w-0">

                    <!-- Live Preview -->
                    <div class="bg-surface rounded-card border border-line overflow-hidden">
                        <!-- Big colored bar -->
                        <div class="h-10 flex bg-surface-2">
                            <template v-if="barSegments.length">
                                <div v-for="(seg, i) in barSegments" :key="i"
                                    :style="{ width: seg.pct + '%', backgroundColor: seg.color }"
                                    class="transition-all duration-300" />
                            </template>
                            <div v-else class="w-full flex items-center justify-center">
                                <span class="text-[11px] text-ink-3">Noch keine Segmente</span>
                            </div>
                        </div>

                        <!-- Step list -->
                        <div class="p-4">
                            <div v-if="previewSteps.length === 0" class="text-xs text-ink-3 text-center py-1">
                                Segmente links hinzufügen
                            </div>
                            <div v-else class="space-y-2.5">
                                <div v-for="step in previewSteps" :key="step.key" class="flex items-center gap-3">
                                    <span class="h-2.5 w-2.5 rounded-full shrink-0" :class="step.dot" />
                                    <div class="flex-1 min-w-0">
                                        <span class="text-xs font-semibold text-ink uppercase tracking-wide">{{ step.label }}</span>
                                        <span v-if="step.sublabel" class="text-xs text-ink-3 ml-2">{{ step.sublabel }}</span>
                                    </div>
                                    <span v-if="step.isRepeat"
                                        class="shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-danger-soft text-danger-ink">
                                        {{ step.reps }}×
                                    </span>
                                    <span v-if="step.lapButton"
                                        class="shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-info-soft text-info-ink tracking-wide">
                                        LAP
                                    </span>
                                </div>
                            </div>

                            <!-- Totals -->
                            <div v-if="previewSteps.length > 0" class="mt-3 pt-3 border-t border-line flex gap-5">
                                <div>
                                    <span class="text-base font-bold text-ink">{{ totalDistM ? fmtDist(totalDistM) : '—' }}</span>
                                    <span class="text-[11px] text-ink-3 ml-1">gesamt</span>
                                </div>
                                <div>
                                    <span class="text-base font-bold text-ink">{{ totalDurSec ? fmtDur(totalDurSec) : '—' }}</span>
                                    <span class="text-[11px] text-ink-3 ml-1">Zeit</span>
                                </div>
                                <div class="ml-auto self-center text-[11px] text-ink-3">{{ blocks.length }} Segment{{ blocks.length !== 1 ? 'e' : '' }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="blocks.length === 0"
                        class="bg-surface rounded-card border border-dashed border-line p-10 text-center">
                        <p class="text-sm text-ink-3">Klicke links auf ein Segment, um es hinzuzufügen</p>
                    </div>

                    <!-- Block list -->
                    <div class="space-y-2">
                        <div v-for="(block, idx) in blocks" :key="block._id ?? idx"
                            class="bg-surface rounded-card border overflow-hidden transition-all"
                            :class="ZONE_COLORS[block.pace_zone ?? defFor(block.type).zone]?.border ?? 'border-line'">

                            <!-- Block header (click to expand) -->
                            <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer select-none"
                                @click="toggleExpand(block._id ?? idx)">
                                <div class="h-8 w-1 rounded-full shrink-0" :class="BLOCK_COLOR_CLASSES[defFor(block.type).color]?.stripe ?? 'bg-surface-3'" />
                                <div class="h-7 w-7 rounded-lg flex items-center justify-center shrink-0"
                                    :class="BLOCK_COLOR_CLASSES[defFor(block.type).color]?.btn ?? ''">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="defFor(block.type).icon" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-semibold text-ink">{{ block.label }}</span>
                                    <span v-if="block.lap_button"
                                        class="text-[10px] font-bold ml-2 px-1.5 py-0.5 rounded-full bg-info-soft text-info-ink tracking-wide">
                                        LAP
                                    </span>
                                    <span class="text-xs text-ink-3 ml-2">
                                        <template v-if="block.type === 'repeat'">{{ block.repetitions }}× · {{ block.steps?.length }} Schritte</template>
                                        <template v-else-if="block.type === 'ramp_up' || block.type === 'ramp_down'">{{ block.steps?.length }} Stufen</template>
                                        <template v-else-if="block.duration_mode === 'distance' && block.distance_m">{{ fmtDist(block.distance_m) }}</template>
                                        <template v-else-if="block.duration_sec">{{ fmtDur(block.duration_sec) }}</template>
                                    </span>
                                    <span v-if="(block.pace_zone ?? 0) > 0" class="text-[11px] ml-2 px-1.5 py-0.5 rounded-full"
                                        :class="ZONE_COLORS[block.pace_zone]?.badge ?? ''">
                                        {{ zonePaceLabel(block.pace_zone) }}
                                    </span>
                                </div>
                                <div class="flex gap-0.5 shrink-0" @click.stop>
                                    <button @click="moveUp(idx)" :disabled="idx === 0" title="Nach oben"
                                        class="p-1 rounded text-ink-3 hover:text-ink-3 disabled:opacity-20 transition-colors">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                    </button>
                                    <button @click="moveDown(idx)" :disabled="idx === blocks.length - 1" title="Nach unten"
                                        class="p-1 rounded text-ink-3 hover:text-ink-3 disabled:opacity-20 transition-colors">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                    </button>
                                    <button @click="duplicateBlock(idx)" title="Duplizieren"
                                        class="p-1 rounded text-ink-3 hover:text-ink-3 transition-colors">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                                    </button>
                                    <button @click="removeBlock(idx)" title="Löschen"
                                        class="p-1 rounded text-ink-3 hover:text-danger transition-colors">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                    <svg class="h-4 w-4 text-ink-3 ml-0.5 transition-transform"
                                        :class="expandedId === (block._id ?? idx) ? 'rotate-180' : ''"
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>

                            <!-- Expanded editor -->
                            <div v-if="expandedId === (block._id ?? idx)"
                                class="border-t border-line px-4 py-4 space-y-3">

                                <!-- Label -->
                                <div>
                                    <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Bezeichnung</label>
                                    <input v-model="block.label" type="text"
                                        class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                                </div>

                                <!-- ── Simple blocks ── -->
                                <template v-if="!block.steps">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Modus</label>
                                            <select v-model="block.duration_mode"
                                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                                                <option value="time">Zeit</option>
                                                <option value="distance">Distanz</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">
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
                                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Zone</label>
                                            <select v-model.number="block.pace_zone"
                                                class="w-full rounded-field border border-line bg-surface-2 px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40">
                                                <option :value="0">— keine —</option>
                                                <option v-for="z in [1,2,3,4,5]" :key="z" :value="z">Zone {{ z }}{{ paceZones?.['z'+z] ? ' · ' + zonePaceLabel(z) : '' }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-ink-3 uppercase tracking-wide mb-1">Pace-Ziel (MM:SS)</label>
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
                                        <label class="text-[11px] font-semibold text-ink-3 uppercase tracking-wide">Wiederholungen</label>
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
                                                    class="rounded-lg border-0 bg-transparent text-xs font-bold uppercase tracking-wide focus:outline-none cursor-pointer"
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
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
