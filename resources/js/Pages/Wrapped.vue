<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

const props = defineProps({
    initialStats:     { type: Object, default: null },
    availablePeriods: { type: Object, default: () => ({ years: [], months: [] }) },
});

const page = usePage();
const coachName = computed(() => page.props.coach?.name ?? 'Dein Coach');
const userName  = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0] || 'Läufer');

const stats         = ref(props.initialStats);
const period        = ref(props.initialStats?.period ?? 'year');
const selectedYear  = ref(props.availablePeriods.years?.[0] ?? new Date().getFullYear());
const selectedMonth = ref(props.availablePeriods.months?.[0]?.value ?? null);
const index         = ref(0);
const reviewText    = ref(null);
const reviewLoading = ref(false);

const GRAD = [
    'bg-gradient-to-br from-accent to-info',
    'bg-gradient-to-br from-success to-success',
    'bg-gradient-to-br from-warn to-danger',
    'bg-gradient-to-br from-accent to-accent',
    'bg-gradient-to-br from-info to-info',
    'bg-gradient-to-br from-danger to-danger',
    'bg-gradient-to-br from-warn to-warn',
    'bg-gradient-to-br from-accent to-accent',
];

const nf = (n) => new Intl.NumberFormat('de-DE').format(n);

const slides = computed(() => {
    const s = stats.value;
    const out = [{ type: 'intro', emoji: '🏃', gradient: 'bg-gradient-to-br from-accent to-accent' }];
    if (!s || !s.has_data) return out;

    let i = 0;
    const g = () => GRAD[i++ % GRAD.length];

    out.push({ type: 'stat', emoji: '📏', big: nf(s.totals.km) + ' km', label: 'gelaufen', sub: s.fun?.marathons ? `Das sind ${s.fun.marathons} Marathons.` : null, gradient: g() });
    out.push({ type: 'stat', emoji: '⏱️', big: nf(s.totals.hours) + ' h', label: 'in Bewegung', sub: `an ${s.totals.active_days} aktiven Tagen`, gradient: g() });
    out.push({ type: 'stat', emoji: '🏃', big: nf(s.totals.runs), label: s.totals.runs === 1 ? 'Lauf' : 'Läufe', sub: `${nf(s.totals.elevation)} Höhenmeter erklommen`, gradient: g() });
    if (s.longest_run) out.push({ type: 'stat', emoji: '🦵', big: s.longest_run.km + ' km', label: 'längster Lauf', sub: `${s.longest_run.name} · ${s.longest_run.date}`, gradient: g() });
    if (s.fastest_run) out.push({ type: 'stat', emoji: '⚡', big: s.fastest_run.pace, label: 'schnellste Pace /km', sub: `${s.fastest_run.km} km · ${s.fastest_run.date}`, gradient: g() });
    if (s.favorite_weekday) out.push({ type: 'stat', emoji: '📅', big: s.favorite_weekday.label, label: 'dein Lauftag', sub: `${s.favorite_weekday.count}× gelaufen`, gradient: g() });
    if (s.favorite_daytime) out.push({ type: 'stat', emoji: '🕑', big: s.favorite_daytime.label, label: 'deine Lieblingszeit', sub: `${s.favorite_daytime.count} Läufe`, gradient: g() });
    if (s.busiest) out.push({ type: 'stat', emoji: '🔥', big: s.busiest.label, label: s.busiest.type === 'month' ? 'stärkster Monat' : 'stärkste Woche', sub: `${nf(s.busiest.km)} km`, gradient: g() });
    if (s.longest_streak > 1) out.push({ type: 'stat', emoji: '📆', big: s.longest_streak + ' Tage', label: 'längste Serie am Stück', gradient: g() });
    if (s.prs?.count > 0) out.push({ type: 'stat', emoji: '🏅', big: nf(s.prs.count), label: s.prs.count === 1 ? 'neuer Rekord' : 'neue Rekorde', sub: s.prs.distances.join(' · '), gradient: g() });
    if (s.vs_previous) out.push({ type: 'stat', emoji: s.vs_previous.delta_pct >= 0 ? '📈' : '📉', big: (s.vs_previous.delta_pct >= 0 ? '+' : '') + s.vs_previous.delta_pct + '%', label: `km vs. ${s.vs_previous.prev_label}`, gradient: g() });

    out.push({ type: 'review', emoji: '💬', gradient: 'bg-gradient-to-br from-danger to-danger' });
    out.push({ type: 'outro', emoji: '🎉', gradient: 'bg-gradient-to-br from-warn to-warn' });
    return out;
});

const current = computed(() => slides.value[Math.min(index.value, slides.value.length - 1)] ?? slides.value[0]);

const next = () => { if (index.value < slides.value.length - 1) index.value++; };
const prev = () => { if (index.value > 0) index.value--; };
const restart = () => { index.value = 0; };

function setPeriod(p) { if (period.value !== p) { period.value = p; reload(); } }

function periodParams() {
    const params = { period: period.value };
    if (period.value === 'year') {
        params.year = selectedYear.value;
    } else if (selectedMonth.value) {
        const [y, m] = selectedMonth.value.split('-');
        params.year = +y; params.month = +m;
    }
    return params;
}

async function reload() {
    reviewText.value = null;
    const params = periodParams();
    try {
        const { data } = await axios.get(route('wrapped.stats'), { params });
        stats.value = data.stats;
        index.value = 0;
        fetchReview(params);
    } catch { /* ignore */ }
}

async function fetchReview(params) {
    if (!stats.value?.has_data) return;
    reviewLoading.value = true;
    try {
        const { data } = await axios.get(route('wrapped.review'), { params });
        reviewText.value = data.text;
    } catch { /* ignore */ } finally {
        reviewLoading.value = false;
    }
}

function onKey(e) {
    if (e.key === 'ArrowRight') next();
    else if (e.key === 'ArrowLeft') prev();
}

// ── Tap- + Wisch-Gesten ───────────────────────────────────────────────────────
// Ein Overlay vereint Tap (linkes Drittel = zurück, sonst weiter) und horizontales
// Wischen, damit ein Swipe nicht zusätzlich einen Tap auslöst (Doppel-Navigation).
let pStartX = 0, pStartY = 0, pStartT = 0;
function onPointerDown(e) {
    pStartX = e.clientX; pStartY = e.clientY; pStartT = Date.now();
}
function onPointerUp(e) {
    const dx = e.clientX - pStartX;
    const dy = e.clientY - pStartY;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
        dx < 0 ? next() : prev();
        return;
    }
    if (Date.now() - pStartT < 500 && Math.abs(dx) < 12 && Math.abs(dy) < 12) {
        const rect = e.currentTarget.getBoundingClientRect();
        (e.clientX - rect.left) < rect.width / 3 ? prev() : next();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    fetchReview(periodParams());
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Head title="Rückblick" />
    <AuthenticatedLayout>
        <div class="py-4 lg:py-6">
            <div class="relative mx-auto max-w-md px-3">
                <div class="relative h-[78vh] rounded-3xl overflow-hidden text-white shadow-xl select-none transition-colors duration-300" :class="current.gradient">
                    <!-- Fortschritts-Segmente -->
                    <div class="absolute top-0 inset-x-0 z-30 flex gap-1 p-3">
                        <div v-for="(s, i) in slides" :key="i" class="h-1 flex-1 rounded-full" :class="i <= index ? 'bg-surface' : 'bg-surface/30'"></div>
                    </div>

                    <!-- Tap-Zonen + Wisch-Gesten (Tap links/rechts, horizontal wischen) -->
                    <div class="absolute inset-0 z-10 touch-pan-y" @pointerdown="onPointerDown" @pointerup="onPointerUp"></div>

                    <!-- Inhalt -->
                    <div class="absolute inset-0 z-20 flex flex-col items-center justify-center text-center px-8 pointer-events-none">
                        <div class="text-5xl mb-4">{{ current.emoji }}</div>

                        <!-- Intro / Umschalter -->
                        <template v-if="current.type === 'intro'">
                            <p class="text-xs uppercase tracking-[0.2em] text-white/70">Dein Rückblick</p>
                            <p class="text-4xl font-black mt-2">{{ stats?.period_label }}</p>

                            <div class="mt-6 pointer-events-auto flex flex-col items-center gap-3" @click.stop>
                                <div class="flex gap-1 bg-surface/15 rounded-field p-1">
                                    <button @click="setPeriod('year')" class="px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors" :class="period === 'year' ? 'bg-surface text-ink' : 'text-white/90'">Jahr</button>
                                    <button @click="setPeriod('month')" class="px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors" :class="period === 'month' ? 'bg-surface text-ink' : 'text-white/90'">Monat</button>
                                </div>
                                <select v-if="period === 'year'" v-model="selectedYear" @change="reload" class="rounded-lg bg-surface/15 border-0 px-3 py-1.5 text-sm text-white focus:ring-2 focus:ring-white/50">
                                    <option v-for="y in availablePeriods.years" :key="y" :value="y" class="text-ink">{{ y }}</option>
                                </select>
                                <select v-else v-model="selectedMonth" @change="reload" class="rounded-lg bg-surface/15 border-0 px-3 py-1.5 text-sm text-white focus:ring-2 focus:ring-white/50">
                                    <option v-for="m in availablePeriods.months" :key="m.value" :value="m.value" class="text-ink">{{ m.label }}</option>
                                </select>

                                <p v-if="stats?.has_data" class="text-xs text-white/60 mt-1">Tippe rechts, um zu starten →</p>
                                <p v-else class="text-sm text-white/80 mt-1">Keine Läufe in diesem Zeitraum.</p>
                            </div>
                        </template>

                        <!-- KI-Coach-Rückblick -->
                        <template v-else-if="current.type === 'review'">
                            <p class="text-xs uppercase tracking-[0.2em] text-white/70">{{ coachName }} über dich</p>
                            <div class="mt-4 max-w-sm min-h-[6rem] flex items-center justify-center">
                                <p v-if="reviewLoading" class="text-white/80 animate-pulse">{{ coachName }} schreibt deinen Rückblick…</p>
                                <p v-else-if="reviewText" class="text-lg leading-relaxed font-medium">„{{ reviewText }}"</p>
                                <p v-else class="text-white/70">Kein Rückblick verfügbar.</p>
                            </div>
                        </template>

                        <!-- Outro -->
                        <template v-else-if="current.type === 'outro'">
                            <p class="text-3xl font-black">Stark, {{ userName }}!</p>
                            <p class="mt-3 text-white/80">Auf zum nächsten Kapitel. 🎉</p>
                            <div class="mt-6 pointer-events-auto" @click.stop>
                                <button @click="restart" class="rounded-field bg-surface/20 hover:bg-surface/30 px-4 py-2 text-sm font-semibold transition-colors">Nochmal ansehen</button>
                            </div>
                        </template>

                        <!-- Stat -->
                        <template v-else>
                            <p class="text-6xl font-black tabular-nums leading-none">{{ current.big }}</p>
                            <p class="mt-3 text-xl font-semibold">{{ current.label }}</p>
                            <p v-if="current.sub" class="mt-3 text-sm text-white/75 max-w-xs">{{ current.sub }}</p>
                        </template>
                    </div>
                </div>

                <!-- Navigation unter der Karte -->
                <div class="flex items-center justify-between mt-3 px-1">
                    <button @click="prev" :disabled="index === 0" class="text-sm font-medium text-ink-3 disabled:opacity-30">‹ Zurück</button>
                    <span class="text-xs text-ink-3">{{ index + 1 }} / {{ slides.length }}</span>
                    <button @click="next" :disabled="index === slides.length - 1" class="text-sm font-medium text-ink-3 disabled:opacity-30">Weiter ›</button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
