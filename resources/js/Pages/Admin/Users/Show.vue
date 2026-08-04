<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
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
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const activeTab          = ref('overview');
const showDeleteModal    = ref(false);
const showWeeklyModal    = ref(false);

const tabs = [
    { key: 'overview',  label: 'Übersicht' },
    { key: 'ai',        label: 'AI-Aktivität' },
    { key: 'wellbeing', label: 'Wellbeing' },
];

// ── Actions ──────────────────────────────────────────────────
function toggleAdmin()  { router.patch(route('admin.users.toggle-admin',  props.user.id), {}, { preserveScroll: true }); }
function toggleActive() { router.patch(route('admin.users.toggle-active', props.user.id), {}, { preserveScroll: true }); }
function deleteUser()   { router.delete(route('admin.users.destroy',      props.user.id)); }

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
function saveAiLimit() {
    router.patch(route('admin.users.ai-limit', props.user.id), { ai_daily_limit: aiLimitInput.value }, { preserveScroll: true });
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
        <template #header>
            <div class="flex items-center gap-2 text-sm text-ink-3">
                <Link :href="route('admin.users.index')" class="hover:text-ink">Nutzer</Link>
                <span>/</span>
                <span class="text-ink font-medium">{{ user.name }}</span>
            </div>
        </template>

        <div class="p-4 sm:p-6 space-y-5">

            <!-- ── Flash banner ──────────────────────────────────── -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="flex items-center gap-3 px-4 py-3 bg-danger-soft border border-danger/25 rounded-field text-sm text-danger-ink">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
                {{ flash.error }}
            </div>

            <!-- ── User header ──────────────────────────────────── -->
            <div class="bg-surface rounded-card p-6 shadow-card">
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
                    <div class="h-16 w-16 rounded-card flex items-center justify-center text-xl font-bold text-white shadow-card shrink-0"
                        :class="user.is_admin ? 'bg-danger' : 'bg-ink-3'"
                    >
                        {{ user.name.charAt(0).toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-xl font-bold text-ink">{{ user.name }}</h1>
                            <span v-if="user.is_admin" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-danger-soft text-danger-ink">Admin</span>
                            <span v-if="!user.is_active" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-3 text-ink-3">Inaktiv</span>
                        </div>
                        <p class="text-ink-3">{{ user.email }}</p>
                        <div class="flex flex-wrap gap-3 mt-2 text-xs text-ink-3">
                            <span>Registriert: {{ formatDate(user.created_at) }}</span>
                            <span v-if="user.email_verified_at">✓ E-Mail verifiziert</span>
                            <span v-if="user.onboarding_completed_at">✓ Onboarding abgeschlossen</span>
                            <span v-if="user.strava_account">🔗 Strava: {{ user.strava_account.username }}</span>
                            <span v-if="user.coach">🤖 Coach: {{ user.coach.name }}</span>
                        </div>
                    </div>
                    <!-- Konto-Aktionen -->
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <button @click="toggleAdmin"
                            class="px-3 py-1.5 text-xs rounded-field border transition-colors"
                            :class="user.is_admin
                                ? 'border-danger/25 text-danger-ink hover:bg-danger-soft'
                                : 'border-line text-ink-2 hover:bg-surface-2'"
                        >{{ user.is_admin ? 'Admin entziehen' : 'Admin machen' }}</button>
                        <button @click="toggleActive"
                            class="px-3 py-1.5 text-xs rounded-field border transition-colors"
                            :class="user.is_active
                                ? 'border-warn/25 text-warn-ink hover:bg-warn-soft'
                                : 'border-success/25 text-success-ink hover:bg-success-soft'"
                        >{{ user.is_active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                        <button @click="showDeleteModal = true"
                            class="px-3 py-1.5 text-xs rounded-field border border-danger/25 text-danger-ink hover:bg-danger-soft transition-colors"
                        >Löschen</button>
                    </div>
                </div>

                <!-- Admin-Aktionen (zweite Reihe) -->
                <div class="mt-4 pt-4 border-t border-line flex flex-wrap gap-2">
                    <p class="text-xs text-ink-3 self-center mr-1">Admin-Aktionen:</p>
                    <button @click="resetRecommendation"
                        class="px-3 py-1.5 text-xs rounded-field border border-warn/25 text-warn-ink hover:bg-warn-soft transition-colors"
                    >Empfehlung zurücksetzen</button>
                    <button @click="confirmWeeklyReview"
                        class="px-3 py-1.5 text-xs rounded-field border border-info/25 text-info-ink hover:bg-info-soft transition-colors"
                    >Weekly Review triggern</button>
                    <button @click="recalculateThreshold"
                        class="px-3 py-1.5 text-xs rounded-field border border-accent/25 text-accent-ink hover:bg-accent-soft transition-colors"
                    >Schwellenpace berechnen</button>
                    <button @click="sendPasswordReset"
                        class="px-3 py-1.5 text-xs rounded-field border border-line text-ink-2 hover:bg-surface-2 transition-colors"
                    >Passwort-Reset senden</button>
                </div>
            </div>

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
                                    <th class="px-6 py-2.5 font-semibold uppercase tracking-wider">Name</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider">Typ</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Distanz</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Dauer</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Tempo</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Datum</th>
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
                <div class="bg-surface rounded-card px-5 py-4 flex items-center justify-between gap-4 shadow-card">
                    <div>
                        <p class="text-sm font-semibold text-ink">AI-Tageslimit</p>
                        <p class="text-xs text-ink-3 mt-0.5">Heute genutzt: <strong class="text-ink-2">{{ aiTodayUsed }}</strong> / {{ user.ai_daily_limit ?? 20 }} Calls</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input v-model.number="aiLimitInput" type="number" min="0" max="500"
                            class="w-20 rounded-field bg-surface px-3 py-1.5 text-sm text-ink text-center focus:outline-none focus:ring-2 focus:ring-accent/40" />
                        <button @click="saveAiLimit" class="px-3 py-1.5 rounded-field bg-accent hover:opacity-90 text-xs font-semibold text-white transition-colors">Speichern</button>
                    </div>
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
                                    class="h-2 rounded-full bg-info transition-all"
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
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider">Zeit</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider">Typ</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Tokens</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Kosten</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Dauer</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-center">Status</th>
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
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Wellbeing-Score letzte 30 Einträge</h2>
                    <div v-if="wellbeingChart?.length" class="flex items-end gap-1 h-28">
                        <div
                            v-for="entry in wellbeingChart" :key="entry.date"
                            class="flex-1 flex flex-col items-center gap-0.5 group"
                        >
                            <div
                                class="w-full rounded-t transition-all relative"
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
                    <p v-else class="text-sm text-ink-3 text-center py-8">Noch keine Wellbeing-Daten.</p>
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
                    <p v-else class="text-sm text-ink-3">Keine Wellbeing-Einträge vorhanden.</p>
                </div>
            </template>

        </div>

        <!-- ── Sheets ────────────────────────────────────────── -->
        <ConfirmSheet
            :show="showDeleteModal"
            title="Nutzer löschen"
            :message="`Soll ${user.name} wirklich unwiderruflich gelöscht werden? Alle Aktivitäten, Ziele und Daten werden entfernt.`"
            confirm-label="Ja, löschen"
            @confirm="deleteUser"
            @close="showDeleteModal = false"
        />

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
