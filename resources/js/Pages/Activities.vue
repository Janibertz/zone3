<script setup>
import { ref, computed, nextTick } from 'vue';
import { router, Link, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { activityType, SPORT_FILTERS } from '@/Composables/useActivityTypes';
import { fmtNum, clock, speedOf, distanceOf } from '@/Composables/useFormat';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

/**
 * Aktivitäten als gruppierte Liste, Woche für Woche (Redesign 28.10.2026).
 *
 * Vorher: eine Filterkarte mit Suchfeld und zwei Auswahllisten über einem
 * Raster aus Karten, jede mit Pille, Ort, vier Zahlen und einem roten
 * Herz. Jetzt wie in Health: Sportart als Segment, darunter die Wochen mit
 * ihrer Summe, je Aktivität links Name und Tag, rechts die eine Zahl groß
 * und das Tempo darunter. Puls und Höhenmeter stehen in der Aktivität.
 */
const props = defineProps({
    activities: { type: Array, default: () => [] },
    weekTotals: { type: Object, default: () => ({}) },
    pagination: { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0 }) },
    filters:    { type: Object, default: () => ({}) },
});

const sport = ref(props.filters?.sport ?? 'all');
const search = ref(props.filters?.search ?? '');
const searchOpen = ref(!!search.value);
const searchInput = ref(null);

/** Ein neuer Filter fängt von vorn an — die angehängten Seiten gehören zum alten. */
function reload() {
    router.get(route('activities.index'), {
        sport: sport.value !== 'all' ? sport.value : undefined,
        search: search.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, reset: ['activities', 'weekTotals'] });
}

function setSport(value) {
    sport.value = value;
    reload();
}

async function toggleSearch() {
    searchOpen.value = !searchOpen.value;
    if (searchOpen.value) {
        await nextTick();
        searchInput.value?.focus();
    } else if (search.value) {
        search.value = '';
        reload();
    }
}

const loadingMore = ref(false);
const hasMore = computed(() => props.pagination.current_page < props.pagination.last_page);

function loadMore() {
    loadingMore.value = true;
    router.reload({
        data: { page: props.pagination.current_page + 1 },
        only: ['activities', 'weekTotals', 'pagination'],
        preserveScroll: true,
        preserveUrl: true,
        onFinish: () => { loadingMore.value = false; },
    });
}

/* ── Wochen ─────────────────────────────────────────────────────────── */

const pad = (n) => String(n).padStart(2, '0');

function mondayKey(date) {
    const d = new Date(date);
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

const thisMonday = mondayKey(new Date());
const lastMonday = (() => {
    const d = new Date(thisMonday + 'T00:00:00');
    d.setDate(d.getDate() - 7);
    return mondayKey(d);
})();

function weekTitle(key) {
    if (key === thisMonday) return 'Diese Woche';
    if (key === lastMonday) return 'Letzte Woche';

    const from = new Date(key + 'T00:00:00');
    const to = new Date(from);
    to.setDate(to.getDate() + 6);
    const sameYear = from.getFullYear() === new Date().getFullYear();
    const fmt = (d, withMonth) => d.toLocaleDateString('de-DE', withMonth
        ? { day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) }
        : { day: 'numeric' });

    return from.getMonth() === to.getMonth()
        ? `${fmt(from, false)}.–${fmt(to, true)}`
        : `${fmt(from, true)} – ${fmt(to, true)}`;
}

function weekSum(key) {
    const t = props.weekTotals?.[key];
    if (!t) return '';
    const n = `${t.count} ${t.count === 1 ? 'Einheit' : 'Einheiten'}`;

    return t.km > 0 ? `${n} · ${fmtNum(t.km)} km` : n;
}

const weeks = computed(() => {
    const out = [];
    for (const a of props.activities) {
        const key = mondayKey(a.start_date);
        let week = out[out.length - 1];
        if (!week || week.key !== key) {
            week = { key, rows: [] };
            out.push(week);
        }
        week.rows.push(a);
    }
    return out;
});

/* ── Zeile ──────────────────────────────────────────────────────────── */

function when(a) {
    const d = new Date(a.start_date);
    const day = d.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'short' });
    const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });

    return `${day} · ${time}`;
}

/** Rechts groß: die Strecke, ohne Strecke die Zeit. */
function primary(a) {
    const d = distanceOf(a);
    return d ? `${d.value} ${d.unit}` : clock(a.moving_time);
}

/** Darunter: das Tempo, sonst die Zeit. */
function secondary(a) {
    if (!distanceOf(a)) return null;
    const s = speedOf(a);
    return s ? `${s.value} ${s.unit}` : clock(a.moving_time);
}

/** Icon-Grund je Sportart — Laufen in Akzent, Rad wie die Radeinheiten im Plan. */
const ICON_BG = {
    run: 'bg-accent', ride: 'bg-info', swim: 'bg-success', walk: 'bg-warn', strength: 'bg-warn', other: 'bg-ink-3',
};
const iconBg = (a) => ICON_BG[activityType(a.type).group] ?? 'bg-ink-3';

const subtitle = computed(() => {
    const n = props.pagination.total ?? 0;
    return `${fmtNum(n, 0)} ${n === 1 ? 'Einheit' : 'Einheiten'}`;
});

const filtered = computed(() => sport.value !== 'all' || !!search.value);
</script>

<template>
    <Head title="Aktivitäten" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-3xl" title="Aktivitäten" :eyebrow="subtitle">
                <template #action>
                    <button type="button" :aria-label="searchOpen ? 'Suche schließen' : 'Suchen'"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-accent transition-transform active:scale-90"
                        @click="toggleSearch">
                        <svg v-if="!searchOpen" class="h-[22px] w-[22px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg>
                        <span v-else class="text-[17px]">Fertig</span>
                    </button>
                </template>
            </PageHeader>

            <div class="mx-auto max-w-3xl space-y-6 px-4 pb-8 pt-3 lg:px-8">
                <div v-if="searchOpen" class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg>
                    <label for="activity-search" class="sr-only">Aktivität suchen</label>
                    <input id="activity-search" ref="searchInput" v-model="search" type="search" enterkeyhint="search"
                        placeholder="Suchen"
                        class="h-10 w-full rounded-[10px] border-0 bg-surface-2 pl-9 pr-3 text-[17px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent"
                        @keyup.enter="reload" />
                </div>

                <SegmentedControl :model-value="sport" :options="SPORT_FILTERS" @update:model-value="setSport" />

                <AppCard v-if="activities.length === 0">
                    <EmptyState
                        title="Keine Aktivitäten"
                        :description="filtered
                            ? 'Mit diesem Filter gibt es nichts.'
                            : 'Verbinde Strava oder Garmin, dann erscheinen deine Einheiten hier.'"
                    >
                        <AppButton v-if="filtered" variant="secondary" @click="search = ''; searchOpen = false; setSport('all')">Alle zeigen</AppButton>
                        <AppButton v-else href="/profile">Verbinden</AppButton>
                    </EmptyState>
                </AppCard>

                <section v-for="week in weeks" :key="week.key">
                    <div class="mx-1 mb-2 flex items-baseline justify-between gap-3">
                        <h2 class="text-[17px] font-bold text-ink">{{ weekTitle(week.key) }}</h2>
                        <span class="text-[13px] tabular-nums text-ink-3">{{ weekSum(week.key) }}</span>
                    </div>
                    <div class="divide-y divide-line overflow-hidden rounded-card bg-surface">
                        <Link v-for="a in week.rows" :key="a.id" :href="route('activities.show', a.id)"
                            class="flex min-w-0 items-center gap-3 px-4 py-3 transition-colors duration-150 active:bg-surface-2">
                            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] text-white" :class="iconBg(a)">
                                <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true" v-html="activityType(a.type).icon" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[17px] text-ink">{{ a.name }}</span>
                                <span class="block truncate text-[13px] text-ink-3">{{ when(a) }}</span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block font-rounded text-[17px] font-bold tabular-nums text-ink">{{ primary(a) }}</span>
                                <span v-if="secondary(a)" class="block text-[13px] tabular-nums text-ink-3">{{ secondary(a) }}</span>
                            </span>
                        </Link>
                    </div>
                </section>

                <div v-if="hasMore" class="flex justify-center">
                    <AppButton variant="plain" :loading="loadingMore" @click="loadMore">Ältere laden</AppButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
