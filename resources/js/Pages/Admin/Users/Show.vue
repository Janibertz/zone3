<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSection from '@/Components/Admin/AdminSection.vue';
import Dot from '@/Components/Admin/Dot.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    user:             Object,
    activities:       Array,
    events:           Array,
    wellbeingEntries: Array,
    wellbeingChart:   Array,
    activityStats:    Object,
    aiLogs:           Array,
    aiStats:          Object,
    aiTodayUsed:      Number,
    aiTodayCost:      Number,
    aiBudgetDefault:  Number,
    aiBlockReason:    String,
    planDiagnostics:  Object,
    overview:         { type: Object, default: () => ({}) },
});

const o = computed(() => props.overview ?? {});
const first = computed(() => o.value.first ?? props.user.name.split(' ')[0]);

const showTools   = ref(false);
const allTimeline = ref(false);
const timelineShown = computed(() => (o.value.timeline ?? []).slice(0, allTimeline.value ? undefined : 5));
const deleteName  = ref('');

/** Werkzeuge schließen und dann handeln, damit das Sheet nicht über der Rückfrage liegt. */
function tool(fn) {
    showTools.value = false;
    fn();
}
function fillGaps() {
    router.post(route('admin.system.plan-gaps.fill', props.user.id), {}, { preserveScroll: true });
}
function openAiLimit() {
    activeTab.value = 'ai';
}

const toolGroups = computed(() => [
    { title: 'Ansehen', rows: [
        ...(props.user.is_admin ? [] : [{ label: `Als ${first.value} ansehen`, run: impersonate }]),
        { label: 'Plan-Verlauf', run: () => (activeTab.value = 'plan') },
    ] },
    { title: 'Plan und Coach', rows: [
        { label: 'Tagesvorschlag zurücksetzen', run: resetRecommendation },
        { label: 'Schwelle neu berechnen', run: recalculateThreshold },
        { label: 'Wochenrückblick erzeugen', run: confirmWeeklyReview },
        ...(o.value.gaps ? [{ label: 'Lücken mit Ruhetagen schließen', value: `${o.value.gaps} Tage`, run: fillGaps }] : []),
    ] },
    { title: 'Zugang', rows: [
        { label: props.user.strava_allowed ? 'Strava ausblenden' : 'Strava freischalten', value: props.user.strava_allowed ? 'freigeschaltet' : 'aus', run: toggleStrava },
        { label: 'KI-Grenze', value: `${props.user.ai_daily_limit ?? 20} Aufrufe · ${aiBudgetEffective.value > 0 ? euro(aiBudgetEffective.value) : 'unbegrenzt'}`, run: openAiLimit },
        { label: `Push an ${first.value}`, href: route('admin.push.index', { user: props.user.id }) },
        { label: 'Link zum Passwort schicken', run: sendPasswordReset },
    ] },
    { title: 'Vorsicht', rows: [
        { label: props.user.is_admin ? 'Admin-Rechte entziehen' : 'Zum Admin machen', run: toggleAdmin },
        { label: props.user.is_active ? 'Deaktivieren' : 'Wieder aktivieren', run: toggleActive },
        { label: `${first.value} löschen`, danger: true, run: () => (showDeleteModal.value = true) },
    ] },
]);

const activeTab          = ref('overview');
const showDeleteModal    = ref(false);
const showWeeklyModal    = ref(false);

const tabs = [
    { key: 'overview',  label: 'Übersicht' },
    { key: 'plan',      label: 'Plan' },
    { key: 'ai',        label: 'AI-Aktivität' },
    { key: 'wellbeing', label: 'Tagesform' },
];

const diag = computed(() => props.planDiagnostics ?? {});

function shortDate(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

function stamp(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

// ── Actions ──────────────────────────────────────────────────
function toggleAdmin()  { router.patch(route('admin.users.toggle-admin',  props.user.id), {}, { preserveScroll: true }); }

/**
 * Als dieser Athlet anmelden.
 *
 * Der schnellste Weg, eine Meldung zu verstehen, ist die Seite zu sehen,
 * die er sieht. Administratoren lassen sich nicht übernehmen — das prüft
 * der Controller.
 */
function impersonate() {
    router.post(route('admin.users.impersonate', props.user.id));
}
function toggleActive() { router.patch(route('admin.users.toggle-active', props.user.id), {}, { preserveScroll: true }); }
function toggleStrava() { router.patch(route('admin.users.toggle-strava', props.user.id), {}, { preserveScroll: true }); }
function deleteUser() {
    if (deleteName.value.trim().toLowerCase() !== first.value.toLowerCase()) return;
    router.delete(route('admin.users.destroy', props.user.id));
}

function resetRecommendation() {
    router.post(route('admin.users.reset-recommendation',  props.user.id), {}, { preserveScroll: true });
}
function confirmWeeklyReview() { showWeeklyModal.value = true; }
function triggerWeeklyReview() {
    showWeeklyModal.value = false;
    router.post(route('admin.users.trigger-weekly-review', props.user.id), {}, { preserveScroll: true });
}
function recalculateThreshold() {
    router.post(route('admin.users.recalculate-threshold', props.user.id), {}, { preserveScroll: true });
}
function sendPasswordReset() {
    router.post(route('admin.users.reset-password', props.user.id), {}, { preserveScroll: true });
}

const aiLimitInput = ref(props.user.ai_daily_limit ?? 20);
// Leer = Vorgabe aus der Umgebung, 0 = unbegrenzt.
const aiBudgetInput = ref(props.user.ai_daily_budget_eur ?? '');
const aiBudgetEffective = computed(() => {
    const own = props.user.ai_daily_budget_eur;
    return own === null || own === undefined ? props.aiBudgetDefault : Number(own);
});
function euro(v) {
    return Number(v ?? 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}
function saveAiLimit() {
    router.patch(route('admin.users.ai-limit', props.user.id), {
        ai_daily_limit: aiLimitInput.value,
        ai_daily_budget_eur: aiBudgetInput.value === '' ? null : aiBudgetInput.value,
    }, { preserveScroll: true });
}

// ── Formatters ────────────────────────────────────────────────
function formatDate(d, opts) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('de-DE', opts ?? { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function formatDateTime(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}
function formatDistance(meters) {
    if (!meters) return '—';
    return (meters / 1000).toFixed(2) + ' km';
}
function formatDuration(seconds) {
    if (!seconds) return '—';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0
        ? `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
        : `${m}:${s.toString().padStart(2, '0')}`;
}
function formatPaceFromSpeed(speedMps) {
    if (!speedMps) return '—';
    const secPerKm = 1000 / speedMps;
    const m = Math.floor(secPerKm / 60);
    const s = Math.round(secPerKm % 60);
    return `${m}:${s.toString().padStart(2, '0')} /km`;
}
function formatPaceFloat(minutes) {
    if (!minutes) return '—';
    const m = Math.floor(minutes);
    const s = Math.round((minutes - m) * 60);
    return `${m}:${s.toString().padStart(2, '0')} /km`;
}
function formatCost(eur) {
    const val = parseFloat(eur);
    if (!val) return '0,00 ct';
    if (val < 0.001) return (val * 100).toFixed(4) + ' ct';
    return val.toFixed(4) + ' €';
}
function formatTokens(n) {
    if (!n) return '0';
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return n.toString();
}

// ── AI helpers ────────────────────────────────────────────────
const callTypeLabel = (t) => ({
    recommendation:        'Tagesempfehlung',
    adjust_recommendation: 'Empfehlung anpassen',
    plan:                  'Trainingsplan',
    event_plan:            'Event-Plan',
    weekly_review:         'Wochenrückblick',
    pace_zones:            'Pace-Zonen',
    threshold_pace:        'Schwellenpace',
    nutrition:             'Ernährung',
    adjust_session:        'Session anpassen',
    goal_analysis:         'Ziel-Analyse',
    profile_estimation:    'Profil-Schätzung',
    suggestions:           'Vorschläge',
}[t] ?? t);

const maxAiTypeCalls = computed(() => Math.max(...(props.aiStats?.by_type?.map(t => t.count) ?? [1]), 1));
const maxWellbeingScore = computed(() => Math.max(...(props.wellbeingChart?.map(d => d.score) ?? [10]), 10));

const zoneColors = [
    'bg-info-soft text-info-ink',
    'bg-success-soft text-success-ink',
    'bg-warn-soft text-warn-ink',
    'bg-warn-soft text-warn-ink',
    'bg-danger-soft text-danger-ink',
];
</script>

<template>
    <Head :title="`Admin – ${user.name}`" />

    <AdminLayout>
        <AdminPage :title="user.name" detail width="wide" :back="route('admin.users.index')" back-label="Athleten">
            <template #nav>
                <button type="button" class="h-11 px-2.5 text-[17px] font-semibold text-accent" @click="showTools = true">Werkzeuge</button>
            </template>

            <!-- ── Kopf (Mockup „Athlet“) ───────────────────────── -->
            <div class="-mt-3 flex items-center gap-4">
                <span class="flex h-[76px] w-[76px] shrink-0 items-center justify-center rounded-full text-[30px] font-bold text-white"
                    :style="{ background: user.is_admin ? 'var(--z-danger, #D70015)' : (o.color ?? '#8E8E93') }">{{ o.initial ?? user.name.charAt(0) }}</span>
                <div class="flex min-w-0 flex-col gap-1">
                    <div class="text-[15px] text-ink-3">{{ o.meta }}</div>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-if="o.has_plan" class="rounded-md bg-success-soft px-[7px] py-0.5 text-[12px] font-semibold text-success-ink">Plan aktiv</span>
                        <span v-else class="rounded-md bg-warn-soft px-[7px] py-0.5 text-[12px] font-semibold text-warn-ink">Kein Plan</span>
                        <span v-if="o.checklist" class="rounded-md bg-surface-2 px-[7px] py-0.5 text-[12px] font-semibold text-ink-2">Checkliste {{ o.checklist }}</span>
                        <span v-if="o.way" class="rounded-md bg-surface-2 px-[7px] py-0.5 text-[12px] font-semibold text-ink-2">{{ o.way }}</span>
                        <span v-if="user.is_admin" class="rounded-md bg-danger-soft px-[7px] py-0.5 text-[12px] font-semibold text-danger-ink">Admin</span>
                        <span v-if="!user.is_active" class="rounded-md bg-surface-3 px-[7px] py-0.5 text-[12px] font-semibold text-ink-3">Deaktiviert</span>
                    </div>
                </div>
            </div>

            <!-- ── Kennzahlen ─────────────────────────────────── -->
            <div class="grid grid-cols-3 gap-2.5">
                <div class="flex flex-col gap-0.5 rounded-[14px] bg-surface p-3">
                    <span class="text-[13px] font-semibold text-form">Readiness</span>
                    <span class="font-rounded text-[26px] font-bold leading-8">{{ o.kpis?.readiness?.score ?? '–' }}</span>
                    <span class="truncate text-[13px] text-ink-3">{{ o.kpis?.readiness?.label ?? 'kein Wert heute' }}</span>
                </div>
                <div class="flex flex-col gap-0.5 rounded-[14px] bg-surface p-3">
                    <span class="text-[13px] font-semibold text-fitness">VDOT</span>
                    <span class="font-rounded text-[26px] font-bold leading-8">{{ o.kpis?.vdot != null ? o.kpis.vdot.toLocaleString('de-DE') : '–' }}</span>
                    <span class="truncate text-[13px] text-ink-3">{{ o.kpis?.threshold ? `Schwelle ${o.kpis.threshold}` : 'keine Schwelle' }}</span>
                </div>
                <div class="flex flex-col gap-0.5 rounded-[14px] bg-surface p-3">
                    <span class="text-[13px] font-semibold text-accent">Woche</span>
                    <span class="font-rounded text-[26px] font-bold leading-8">{{ (o.kpis?.week_km ?? 0).toLocaleString('de-DE') }}<span class="text-[15px] font-semibold text-ink-3"> km</span></span>
                    <span class="truncate text-[13px] text-ink-3">{{ o.kpis?.week_planned ? `von ${o.kpis.week_planned.toLocaleString('de-DE')} geplant` : 'nichts geplant' }}</span>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-[18px] lg:grid-cols-2">
                <div class="flex flex-col gap-[18px]">
                    <!-- Verbindungen -->
                    <AdminSection title="Verbindungen">
                        <div class="overflow-hidden rounded-[14px] bg-surface">
                            <p v-if="!o.connections?.length" class="px-3.5 py-3 text-[15px] text-ink-3">Nichts verbunden.</p>
                            <div v-for="(c, i) in o.connections" :key="c.name" class="flex items-center gap-2.5 px-3.5 py-[11px]"
                                :class="i < o.connections.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                                <Dot :tone="c.tone" />
                                <span class="flex-1 text-[16px]">{{ c.name }}</span>
                                <span class="text-right text-[15px]" :class="c.tone === 'warn' ? 'text-warn-ink' : 'text-ink-3'">{{ c.value }}</span>
                            </div>
                        </div>
                    </AdminSection>

                    <!-- Plan und KI -->
                    <AdminSection title="Plan und KI">
                        <div class="overflow-hidden rounded-[14px] bg-surface">
                            <div class="flex justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-[11px]">
                                <span class="text-[16px]">Plan</span>
                                <span class="min-w-0 truncate text-right text-[15px] text-ink-3">{{ o.plan_line || '–' }}</span>
                            </div>
                            <div class="flex justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-[11px]">
                                <span class="text-[16px]">Lücken</span>
                                <span class="text-[15px]" :class="o.gaps ? 'font-semibold text-warn-ink' : 'text-ink-3'">{{ o.gaps ? `${o.gaps} Tage ohne Einheit` : 'keine' }}</span>
                            </div>
                            <div class="flex justify-between gap-3 px-3.5 py-[11px]">
                                <span class="text-[16px]">KI heute</span>
                                <span class="text-right text-[15px]" :class="aiBlockReason ? 'font-semibold text-danger-ink' : 'text-ink-3'">
                                    {{ euro(aiTodayCost) }} von {{ aiBudgetEffective > 0 ? euro(aiBudgetEffective) : 'unbegrenzt' }} · {{ aiTodayUsed }} Aufrufe
                                </span>
                            </div>
                        </div>
                    </AdminSection>
                </div>

                <!-- Was passiert ist -->
                <AdminSection title="Was passiert ist">
                    <template v-if="(o.timeline?.length ?? 0) > 5" #aside>
                        <button type="button" class="text-[15px] font-semibold text-accent" @click="allTimeline = !allTimeline">{{ allTimeline ? 'Weniger' : 'Alles' }}</button>
                    </template>
                    <div class="overflow-hidden rounded-[14px] bg-surface">
                        <p v-if="!o.timeline?.length" class="px-3.5 py-3 text-[15px] text-ink-3">Noch nichts.</p>
                        <div v-for="(t, i) in timelineShown" :key="i" class="flex gap-3 px-3.5 py-[11px]"
                            :class="i < timelineShown.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                            <div class="w-[52px] shrink-0 pt-0.5 text-[13px] text-ink-3">{{ t.label }}</div>
                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="text-[15px] font-semibold" :class="{ 'text-warn-ink': t.tone === 'warn', 'text-danger-ink': t.tone === 'danger', 'text-success-ink': t.tone === 'success' }">{{ t.title }}</span>
                                <span v-if="t.meta" class="text-[13px] text-ink-3">{{ t.meta }}</span>
                            </div>
                        </div>
                    </div>
                </AdminSection>
            </div>

            <div class="px-1 pt-2 text-[13px] font-semibold uppercase text-ink-3">Details · {{ user.email }} · ID {{ user.id }}</div>

            <!-- ── Activity stats KPIs ──────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="stat in [
                    { label: 'Aktivitäten',       value: activityStats.total },
                    { label: 'Läufe',             value: activityStats.total_runs },
                    { label: 'Kilometer',         value: activityStats.total_km + ' km' },
                    { label: 'Letzte Aktivität',  value: formatDate(activityStats.last_activity) },
                ]" :key="stat.label"
                    class="bg-surface rounded-field p-4"
                >
                    <p class="text-xs text-ink-3">{{ stat.label }}</p>
                    <p class="mt-0.5 text-xl font-bold text-ink">{{ stat.value }}</p>
                </div>
            </div>

            <!-- ── Tabs ──────────────────────────────────────────── -->
            <div class="border-b border-line">
                <nav class="flex gap-1 -mb-px">
                    <button
                        v-for="tab in tabs" :key="tab.key"
                        @click="activeTab = tab.key"
                        class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === tab.key
                            ? 'border-danger text-danger-ink'
                            : 'border-transparent text-ink-3 hover:text-ink'"
                    >{{ tab.label }}</button>
                </nav>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TAB: ÜBERSICHT
                 ══════════════════════════════════════════════════ -->
            <template v-if="activeTab === 'overview'">

                <!-- Runner profile -->
                <div class="bg-surface rounded-card p-6 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Athletenprofil</h2>
                    <div v-if="user.runner_profile" class="space-y-4">
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-xs text-ink-3">Schwellentempo</p>
                                <p class="font-semibold text-ink">{{ formatPaceFloat(user.runner_profile.threshold_speed) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-ink-3">LTHR</p>
                                <p class="font-semibold text-ink">{{ user.runner_profile.threshold_heart_rate ?? '—' }} bpm</p>
                            </div>
                            <div>
                                <p class="text-xs text-ink-3">Max HF</p>
                                <p class="font-semibold text-ink">{{ user.runner_profile.max_heart_rate ?? '—' }} bpm</p>
                            </div>
                        </div>
                        <div v-if="user.runner_profile.pace_zones" class="flex gap-2 flex-wrap">
                            <div
                                v-for="(zone, i) in user.runner_profile.pace_zones" :key="i"
                                class="flex-1 min-w-[80px] rounded-field p-2 text-center text-xs"
                                :class="zoneColors[i]"
                            >
                                <p class="font-semibold">Z{{ zone.zone }}</p>
                                <p class="opacity-80">{{ zone.min_pace }} – {{ zone.max_pace }}</p>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-3">Kein Athletenprofil eingerichtet.</p>
                </div>

                <!-- Recent activities -->
                <div class="bg-surface rounded-card overflow-hidden shadow-card">
                    <div class="px-6 py-4 border-b border-line">
                        <h2 class="text-sm font-semibold text-ink-2">Letzte Aktivitäten</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-line">
                                <tr class="text-left text-xs text-ink-3">
                                    <th class="px-6 py-2.5 font-medium">Name</th>
                                    <th class="px-4 py-2.5 font-medium">Typ</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Distanz</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Dauer</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Tempo</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Datum</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="act in activities" :key="act.id" class="hover:bg-surface-2/50 transition-colors">
                                    <td class="px-6 py-2.5 text-ink">{{ act.name }}</td>
                                    <td class="px-4 py-2.5 text-ink-3">{{ act.type }}</td>
                                    <td class="px-4 py-2.5 text-right text-ink-2">{{ formatDistance(act.distance) }}</td>
                                    <td class="px-4 py-2.5 text-right text-ink-2">{{ formatDuration(act.moving_time) }}</td>
                                    <td class="px-4 py-2.5 text-right text-ink-2">{{ formatPaceFromSpeed(act.average_speed) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs text-ink-3 whitespace-nowrap">{{ formatDate(act.start_date) }}</td>
                                </tr>
                                <tr v-if="!activities.length">
                                    <td colspan="6" class="px-6 py-8 text-center text-ink-3">Keine Aktivitäten vorhanden.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Events / Race Goals -->
                <div class="bg-surface rounded-card p-6 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Rennen & Ziele ({{ events.length }})</h2>
                    <div v-if="events.length" class="space-y-3">
                        <div
                            v-for="event in events" :key="event.id"
                            class="flex items-start justify-between p-3 rounded-field bg-surface-2 gap-3"
                        >
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-ink">{{ event.name }}</p>
                                    <span class="text-xs px-1.5 py-0.5 rounded font-bold"
                                        :class="event.priority === 'A'
                                            ? 'bg-danger-soft text-danger-ink'
                                            : event.priority === 'B'
                                                ? 'bg-info-soft text-info-ink'
                                                : 'bg-surface-2 text-ink-2'"
                                    >Prio {{ event.priority }}</span>
                                </div>
                                <p class="text-xs text-ink-3 mt-0.5">
                                    {{ event.distance_label }}
                                    <span v-if="event.target_time_hours > 0 || event.target_time_minutes > 0">
                                        · Ziel: {{ event.target_time_hours > 0 ? event.target_time_hours + 'h ' : '' }}{{ event.target_time_minutes }}min
                                    </span>
                                    · {{ formatDate(event.event_date) }}
                                </p>
                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                                    <span v-if="event.training_plans?.some(p => p.is_active)"
                                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-success-soft text-success-ink font-medium">
                                        ✓ Aktiver Plan
                                    </span>
                                    <span v-else-if="event.training_plans?.length"
                                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-surface-2 text-ink-3 font-medium">
                                        {{ event.training_plans.length }} Plan(e)
                                    </span>
                                    <span v-else
                                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-warn-soft text-warn-ink font-medium">
                                        Kein Plan
                                    </span>
                                </div>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full shrink-0 mt-0.5"
                                :class="new Date(event.event_date) < new Date()
                                    ? 'bg-surface-3 text-ink-3'
                                    : 'bg-accent-soft text-accent-ink'"
                            >
                                {{ new Date(event.event_date) < new Date() ? 'Vergangen' : 'Geplant' }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-3">Keine Rennen / Ziele vorhanden.</p>
                </div>
            </template>

            <!-- ══════════════════════════════════════════════════
                 TAB: AI-AKTIVITÄT
                 ══════════════════════════════════════════════════ -->
            <template v-if="activeTab === 'ai'">

                <!-- AI Rate Limit -->
                <div class="bg-surface rounded-card px-5 py-4 shadow-card space-y-3">
                    <div>
                        <p class="text-sm font-semibold text-ink">KI-Tageslimit</p>
                        <p class="text-xs text-ink-3 mt-0.5">
                            Heute: <strong class="text-ink-2">{{ aiTodayUsed }}</strong> / {{ user.ai_daily_limit ?? 20 }} Anfragen ·
                            <strong class="text-ink-2">{{ euro(aiTodayCost) }}</strong> /
                            {{ aiBudgetEffective > 0 ? euro(aiBudgetEffective) : 'unbegrenzt' }}
                        </p>
                        <p v-if="aiBlockReason" class="text-xs font-semibold text-red-600 dark:text-red-400 mt-1">Coach gesperrt: {{ aiBlockReason }}</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="text-xs text-ink-3">
                            Anfragen
                            <input v-model.number="aiLimitInput" type="number" min="0" max="500"
                                class="mt-1 block w-20 rounded-field bg-surface px-3 py-1.5 text-sm text-ink text-center focus:outline-none focus:ring-2 focus:ring-accent/40" />
                        </label>
                        <label class="text-xs text-ink-3">
                            Budget € / Tag
                            <input v-model="aiBudgetInput" type="number" min="0" max="20" step="0.05"
                                :placeholder="aiBudgetDefault.toFixed(2)"
                                class="mt-1 block w-24 rounded-field bg-surface px-3 py-1.5 text-sm text-ink text-center focus:outline-none focus:ring-2 focus:ring-accent/40" />
                        </label>
                        <button @click="saveAiLimit" class="px-3 py-1.5 rounded-field bg-accent hover:opacity-90 active:opacity-80 text-xs font-semibold text-white transition-colors">Speichern</button>
                    </div>
                    <p class="text-xs text-ink-3">Budget leer = Vorgabe ({{ euro(aiBudgetDefault) }}), 0 = unbegrenzt. Das Budget für alle und die Notbremse gelten trotzdem.</p>
                </div>

                <!-- AI KPIs -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div v-for="kpi in [
                        { label: 'Gesamt Calls',  value: aiStats.total_calls },
                        { label: 'Tokens gesamt', value: formatTokens(aiStats.total_tokens) },
                        { label: 'Kosten gesamt', value: formatCost(aiStats.total_cost) },
                        { label: 'Häufigster Typ', value: aiStats.by_type?.[0] ? callTypeLabel(aiStats.by_type[0].call_type) : '—' },
                    ]" :key="kpi.label"
                        class="bg-surface rounded-field p-4"
                    >
                        <p class="text-xs text-ink-3">{{ kpi.label }}</p>
                        <p class="mt-0.5 text-xl font-bold text-ink truncate">{{ kpi.value }}</p>
                    </div>
                </div>

                <!-- By type -->
                <div v-if="aiStats.by_type?.length" class="bg-surface rounded-card p-5 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Aufteilung nach Typ</h2>
                    <div class="space-y-2.5">
                        <div v-for="t in aiStats.by_type" :key="t.call_type" class="flex items-center gap-3">
                            <span class="text-xs text-ink-2 font-medium w-36 shrink-0 truncate">{{ callTypeLabel(t.call_type) }}</span>
                            <div class="flex-1 bg-surface-2 rounded-full h-2 overflow-hidden">
                                <div
                                    class="h-2 rounded-full bg-info transition-[width]"
                                    :style="{ width: Math.round((t.count / maxAiTypeCalls) * 100) + '%' }"
                                />
                            </div>
                            <span class="text-xs text-ink-3 shrink-0 w-8 text-right tabular-nums">{{ t.count }}</span>
                            <span class="text-xs text-ink-3 shrink-0 w-20 text-right tabular-nums">{{ formatCost(t.cost) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Last 10 AI calls -->
                <div class="bg-surface rounded-card overflow-hidden shadow-card">
                    <div class="px-5 py-4 border-b border-line flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-ink-2">Letzte 10 AI-Calls</h2>
                        <Link :href="route('admin.ai-logs.index', { user_id: user.id })" class="text-xs text-danger hover:underline">
                            Alle anzeigen →
                        </Link>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-line">
                                <tr class="text-left text-xs text-ink-3">
                                    <th class="px-4 py-2.5 font-medium">Zeit</th>
                                    <th class="px-4 py-2.5 font-medium">Typ</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Tokens</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Kosten</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Dauer</th>
                                    <th class="px-4 py-2.5 font-medium text-center">Status</th>
                                    <th class="px-4 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="log in aiLogs" :key="log.id" class="hover:bg-surface-2/40 transition-colors">
                                    <td class="px-4 py-2.5 text-xs text-ink-3 whitespace-nowrap">{{ formatDateTime(log.created_at) }}</td>
                                    <td class="px-4 py-2.5 text-xs font-medium text-ink-2">{{ callTypeLabel(log.call_type) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs text-ink-2 tabular-nums">{{ formatTokens(log.total_tokens) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs font-medium text-ink tabular-nums">{{ formatCost(log.cost_eur) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs text-ink-3 tabular-nums">{{ log.duration_ms }} ms</td>
                                    <td class="px-4 py-2.5 text-center">
                                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                            :class="log.status === 'success'
                                                ? 'bg-success-soft text-success'
                                                : 'bg-danger-soft text-danger'"
                                        >{{ log.status === 'success' ? 'OK' : 'Fehler' }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <Link :href="route('admin.ai-logs.show', log.id)" class="text-xs text-ink-3 hover:text-danger">→</Link>
                                    </td>
                                </tr>
                                <tr v-if="!aiLogs?.length">
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-ink-3">Noch keine AI-Calls vorhanden.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- ══════════════════════════════════════════════════
                 TAB: WELLBEING
                 ══════════════════════════════════════════════════ -->
            <template v-if="activeTab === 'wellbeing'">

                <!-- 30-day chart -->
                <div class="bg-surface rounded-card p-5 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Tagesform-Score letzte 30 Einträge</h2>
                    <div v-if="wellbeingChart?.length" class="flex items-end gap-1 h-28">
                        <div
                            v-for="entry in wellbeingChart" :key="entry.date"
                            class="flex-1 flex flex-col items-center gap-0.5 group"
                        >
                            <div
                                class="w-full rounded-t transition-[height,opacity] relative"
                                :class="entry.score >= 7 ? 'bg-success group-hover:opacity-90' :
                                        entry.score >= 5 ? 'bg-warn group-hover:opacity-90' :
                                                          'bg-danger group-hover:opacity-90'"
                                :style="{ height: Math.max(4, Math.round((entry.score / maxWellbeingScore) * 96)) + 'px' }"
                            >
                                <div class="absolute -top-7 left-1/2 -translate-x-1/2 hidden group-hover:block bg-ink text-canvas text-[10px] rounded px-1.5 py-0.5 whitespace-nowrap z-10">
                                    {{ entry.date }} · {{ entry.score }}/10
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-3 text-center py-8">Noch keine Tagesform-Daten.</p>
                    <div class="flex gap-4 mt-3 text-xs text-ink-3">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-success inline-block"></span>≥ 7 gut</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-warn inline-block"></span>5–7 mittel</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-danger inline-block"></span>&lt; 5 schlecht</span>
                    </div>
                </div>

                <!-- Wellbeing table -->
                <div class="bg-surface rounded-card p-6 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Letzte 14 Einträge</h2>
                    <div v-if="wellbeingEntries.length" class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-ink-3 border-b border-line">
                                    <th class="pb-2 font-medium">Datum</th>
                                    <th class="pb-2 font-medium text-center">⚡ Energie</th>
                                    <th class="pb-2 font-medium text-center">😊 Stimmung</th>
                                    <th class="pb-2 font-medium text-center">💤 Schlaf</th>
                                    <th class="pb-2 font-medium text-center">💪 Muskelkater</th>
                                    <th class="pb-2 font-medium text-center">😤 Stress</th>
                                    <th class="pb-2 font-medium text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="wb in wellbeingEntries" :key="wb.id" class="hover:bg-surface-2/30">
                                    <td class="py-2 text-ink-3 whitespace-nowrap font-medium">{{ formatDate(wb.date) }}</td>
                                    <td class="py-2 text-center font-semibold text-ink-2">{{ wb.energy_level }}</td>
                                    <td class="py-2 text-center font-semibold text-ink-2">{{ wb.mood }}</td>
                                    <td class="py-2 text-center font-semibold text-ink-2">{{ wb.sleep_quality }}</td>
                                    <td class="py-2 text-center font-semibold text-ink-2">{{ wb.muscle_soreness }}</td>
                                    <td class="py-2 text-center font-semibold text-ink-2">{{ wb.stress_level }}</td>
                                    <td class="py-2 text-center">
                                        <span v-if="wb.is_sick || wb.is_injured" class="px-1.5 py-0.5 rounded-full bg-danger-soft text-danger-ink">
                                            {{ wb.is_sick ? 'Krank' : 'Verletzt' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-ink-3">Keine Tagesform-Einträge vorhanden.</p>
                </div>
            </template>

            <!-- ══════════════════════════════════════════════════
                 TAB: PLAN
                 ══════════════════════════════════════════════════ -->
            <template v-if="activeTab === 'plan'">

                <!-- Aktiver Plan -->
                <div class="bg-surface rounded-card p-6 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Aktiver Plan</h2>

                    <p v-if="!diag.plan" class="text-sm text-ink-3">Kein aktiver Plan.</p>

                    <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <p class="text-xs text-ink-3">Zeitraum</p>
                            <p class="text-sm font-medium text-ink">{{ shortDate(diag.plan.from) }} – {{ shortDate(diag.plan.to) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-ink-3">Geplant</p>
                            <p class="text-sm font-medium text-ink tabular-nums">{{ diag.counts.planned }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-ink-3">Absolviert</p>
                            <p class="text-sm font-medium text-success-ink tabular-nums">{{ diag.counts.completed }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-ink-3">Ausgelassen</p>
                            <p class="text-sm font-medium text-ink-3 tabular-nums">{{ diag.counts.skipped }}</p>
                        </div>
                    </div>
                </div>

                <!-- Lücken -->
                <div class="bg-surface rounded-card p-6 shadow-card">
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                        <div>
                            <h2 class="text-sm font-semibold text-ink-2">Lücken im Plan</h2>
                            <p class="text-xs text-ink-3 mt-0.5">
                                Tage innerhalb des Planzeitraums, an denen keine einzige Einheit steht.
                            </p>
                        </div>
                        <button
                            v-if="diag.gaps?.length"
                            class="px-3 py-1.5 rounded-field text-xs font-medium bg-warn-soft text-warn-ink"
                            @click="router.post(route('admin.system.plan-gaps.fill', user.id), {}, { preserveScroll: true })">
                            Mit Ruhetagen schliessen
                        </button>
                    </div>

                    <p v-if="!diag.gaps?.length" class="text-sm text-success-ink">Keine Lücken.</p>
                    <div v-else class="flex flex-wrap gap-2">
                        <span v-for="d in diag.gaps" :key="d"
                            class="px-2 py-1 rounded-full text-xs bg-warn-soft text-warn-ink">
                            {{ shortDate(d) }}
                        </span>
                    </div>
                </div>

                <!-- Einheiten ohne Plan -->
                <div class="bg-surface rounded-card p-6 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2">Einheiten ohne Plan ({{ diag.orphans?.length ?? 0 }})</h2>
                    <p class="text-xs text-ink-3 mt-0.5 mb-3">
                        Sie stehen in der Datenbank, aber der Athlet sieht sie nicht — die Planseite lädt
                        nur Einheiten des aktiven Plans.
                    </p>

                    <p v-if="!diag.orphans?.length" class="text-sm text-ink-3">Keine.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-line">
                                <tr v-for="o in diag.orphans" :key="o.id">
                                    <td class="py-2 pr-3 text-ink-3 whitespace-nowrap">{{ shortDate(o.date) }}</td>
                                    <td class="py-2 pr-3 text-ink">{{ o.title }}</td>
                                    <td class="py-2 pr-3 text-ink-3">{{ o.type }}</td>
                                    <td class="py-2 text-right text-ink-3">{{ o.status }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Neuberechnungen -->
                <div class="bg-surface rounded-card p-6 shadow-card">
                    <h2 class="text-sm font-semibold text-ink-2">Neuberechnungen</h2>
                    <p class="text-xs text-ink-3 mt-0.5 mb-3">
                        Der Anlass zählt: jede Neuberechnung ist ein neuer Wurf des Modells. Häufen sich
                        automatische Läufe, wird der Plan öfter umgeschrieben, als der Athlet erwartet.
                    </p>

                    <p v-if="!diag.revisions?.length" class="text-sm text-ink-3">Noch keine.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-ink-3 border-b border-line">
                                <tr>
                                    <th class="py-2 pr-3 text-left font-medium">Wann</th>
                                    <th class="py-2 pr-3 text-left font-medium">Anlass</th>
                                    <th class="py-2 pr-3 text-right font-medium">Änderungen</th>
                                    <th class="py-2 text-right font-medium">Korrekturen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="r in diag.revisions" :key="r.id">
                                    <td class="py-2 pr-3 text-ink-3 whitespace-nowrap">{{ stamp(r.at) }}</td>
                                    <td class="py-2 pr-3 text-ink">{{ r.label }}</td>
                                    <td class="py-2 pr-3 text-right text-ink-3 tabular-nums">{{ r.changes }}</td>
                                    <td class="py-2 text-right tabular-nums"
                                        :class="r.corrections ? 'text-warn-ink font-medium' : 'text-ink-3'">
                                        {{ r.corrections }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

        </AdminPage>

        <!-- ── Werkzeuge (Mockup „Werkzeuge“) ─────────────────── -->
        <AppSheet :show="showTools" :title="`Werkzeuge für ${first}`" @close="showTools = false">
            <div class="flex flex-col gap-[18px] pb-2">
                <div v-for="g in toolGroups" :key="g.title" class="flex flex-col gap-1.5">
                    <div class="px-1 text-[13px] font-semibold uppercase text-ink-3">{{ g.title }}</div>
                    <div class="overflow-hidden rounded-[14px] bg-surface-2/60 dark:bg-surface-2">
                        <component :is="r.href ? Link : 'button'" v-for="(r, i) in g.rows" :key="r.label" :href="r.href" type="button"
                            class="flex w-full items-center justify-between gap-3 px-3.5 py-3 text-left active:bg-surface-3"
                            :class="i < g.rows.length - 1 ? 'border-b-[0.5px] border-line' : ''"
                            @click="r.href ? (showTools = false) : tool(r.run)">
                            <span class="text-[16px]" :class="r.danger ? 'text-danger' : ''">{{ r.label }}</span>
                            <span v-if="r.value" class="truncate text-[15px] text-ink-3">{{ r.value }}</span>
                        </component>
                    </div>
                </div>
            </div>
        </AppSheet>

        <!-- ── Sheets ────────────────────────────────────────── -->
        <ConfirmSheet
            :show="showDeleteModal"
            :title="`${first} löschen?`"
            :message="`Alle Aktivitäten, Ziele und Daten von ${user.name} werden unwiderruflich entfernt. Tippe zur Bestätigung „${first}“.`"
            confirm-label="Endgültig löschen"
            @confirm="deleteUser"
            @close="showDeleteModal = false; deleteName = ''"
        >
            <input v-model="deleteName" type="text" autocomplete="off" :aria-label="`${first} eintippen`" :placeholder="first"
                class="mt-3 w-full rounded-[10px] border border-line-strong bg-transparent px-3 py-2 text-[16px] focus:border-danger focus:ring-0" />
        </ConfirmSheet>

        <ConfirmSheet
            :show="showWeeklyModal"
            title="Weekly Review triggern"
            :message="`Soll für ${user.name} der Wochenrückblick der letzten Woche manuell generiert werden? Ein bereits vorhandener Review dieser Woche wird überschrieben.`"
            confirm-label="Ja, generieren"
            tone="primary"
            @confirm="triggerWeeklyReview"
            @close="showWeeklyModal = false"
        />

    </AdminLayout>
</template>
