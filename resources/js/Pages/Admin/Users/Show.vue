<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';
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
    'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
    'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300',
    'bg-yellow-100 dark:bg-yellow-500/20 text-yellow-700 dark:text-yellow-300',
    'bg-orange-100 dark:bg-orange-500/20 text-orange-700 dark:text-orange-300',
    'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300',
];
</script>

<template>
    <Head :title="`Admin – ${user.name}`" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-slate-400">
                <Link :href="route('admin.users.index')" class="hover:text-gray-700 dark:hover:text-slate-200">Nutzer</Link>
                <span>/</span>
                <span class="text-gray-900 dark:text-white font-medium">{{ user.name }}</span>
            </div>
        </template>

        <div class="p-4 sm:p-6 space-y-5">

            <!-- ── Flash banner ──────────────────────────────────── -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 rounded-xl text-sm text-green-700 dark:text-green-300">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="flex items-center gap-3 px-4 py-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl text-sm text-red-700 dark:text-red-300">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
                {{ flash.error }}
            </div>

            <!-- ── User header ──────────────────────────────────── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
                    <div class="h-16 w-16 rounded-2xl flex items-center justify-center text-xl font-bold text-white shadow-sm shrink-0"
                        :class="user.is_admin ? 'bg-gradient-to-br from-red-400 to-red-600' : 'bg-gradient-to-br from-gray-300 to-gray-400 dark:from-slate-600 dark:to-slate-700'"
                    >
                        {{ user.name.charAt(0).toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ user.name }}</h1>
                            <span v-if="user.is_admin" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400">Admin</span>
                            <span v-if="!user.is_active" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400">Inaktiv</span>
                        </div>
                        <p class="text-gray-500 dark:text-slate-400">{{ user.email }}</p>
                        <div class="flex flex-wrap gap-3 mt-2 text-xs text-gray-400 dark:text-slate-500">
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
                            class="px-3 py-1.5 text-xs rounded-xl border transition-colors"
                            :class="user.is_admin
                                ? 'border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10'
                                : 'border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'"
                        >{{ user.is_admin ? 'Admin entziehen' : 'Admin machen' }}</button>
                        <button @click="toggleActive"
                            class="px-3 py-1.5 text-xs rounded-xl border transition-colors"
                            :class="user.is_active
                                ? 'border-yellow-200 dark:border-yellow-500/30 text-yellow-600 dark:text-yellow-400 hover:bg-yellow-50 dark:hover:bg-yellow-500/10'
                                : 'border-green-200 dark:border-green-500/30 text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-500/10'"
                        >{{ user.is_active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                        <button @click="showDeleteModal = true"
                            class="px-3 py-1.5 text-xs rounded-xl border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
                        >Löschen</button>
                    </div>
                </div>

                <!-- Admin-Aktionen (zweite Reihe) -->
                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-slate-800 flex flex-wrap gap-2">
                    <p class="text-xs text-gray-400 dark:text-slate-500 self-center mr-1">Admin-Aktionen:</p>
                    <button @click="resetRecommendation"
                        class="px-3 py-1.5 text-xs rounded-xl border border-orange-200 dark:border-orange-500/30 text-orange-600 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-500/10 transition-colors"
                    >Empfehlung zurücksetzen</button>
                    <button @click="confirmWeeklyReview"
                        class="px-3 py-1.5 text-xs rounded-xl border border-blue-200 dark:border-blue-500/30 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition-colors"
                    >Weekly Review triggern</button>
                    <button @click="recalculateThreshold"
                        class="px-3 py-1.5 text-xs rounded-xl border border-purple-200 dark:border-purple-500/30 text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-500/10 transition-colors"
                    >Schwellenpace berechnen</button>
                    <button @click="sendPasswordReset"
                        class="px-3 py-1.5 text-xs rounded-xl border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors"
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
                    class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-xl p-4"
                >
                    <p class="text-xs text-gray-500 dark:text-slate-400">{{ stat.label }}</p>
                    <p class="mt-0.5 text-xl font-bold text-gray-900 dark:text-white">{{ stat.value }}</p>
                </div>
            </div>

            <!-- ── Tabs ──────────────────────────────────────────── -->
            <div class="border-b border-gray-200 dark:border-slate-800">
                <nav class="flex gap-1 -mb-px">
                    <button
                        v-for="tab in tabs" :key="tab.key"
                        @click="activeTab = tab.key"
                        class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === tab.key
                            ? 'border-red-500 text-red-600 dark:text-red-400'
                            : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'"
                    >{{ tab.label }}</button>
                </nav>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TAB: ÜBERSICHT
                 ══════════════════════════════════════════════════ -->
            <template v-if="activeTab === 'overview'">

                <!-- Runner profile -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Athletenprofil</h2>
                    <div v-if="user.runner_profile" class="space-y-4">
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-xs text-gray-400 dark:text-slate-500">Schwellentempo</p>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ formatPaceFloat(user.runner_profile.threshold_speed) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400 dark:text-slate-500">LTHR</p>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ user.runner_profile.threshold_heart_rate ?? '—' }} bpm</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400 dark:text-slate-500">Max HF</p>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ user.runner_profile.max_heart_rate ?? '—' }} bpm</p>
                            </div>
                        </div>
                        <div v-if="user.runner_profile.pace_zones" class="flex gap-2 flex-wrap">
                            <div
                                v-for="(zone, i) in user.runner_profile.pace_zones" :key="i"
                                class="flex-1 min-w-[80px] rounded-xl p-2 text-center text-xs"
                                :class="zoneColors[i]"
                            >
                                <p class="font-semibold">Z{{ zone.zone }}</p>
                                <p class="opacity-80">{{ zone.min_pace }} – {{ zone.max_pace }}</p>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500">Kein Athletenprofil eingerichtet.</p>
                </div>

                <!-- Recent activities -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Letzte Aktivitäten</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-50 dark:border-slate-800">
                                <tr class="text-left text-xs text-gray-500 dark:text-slate-400">
                                    <th class="px-6 py-2.5 font-semibold uppercase tracking-wider">Name</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider">Typ</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Distanz</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Dauer</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Tempo</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Datum</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                                <tr v-for="act in activities" :key="act.id" class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                    <td class="px-6 py-2.5 text-gray-900 dark:text-white">{{ act.name }}</td>
                                    <td class="px-4 py-2.5 text-gray-500 dark:text-slate-400">{{ act.type }}</td>
                                    <td class="px-4 py-2.5 text-right text-gray-600 dark:text-slate-300">{{ formatDistance(act.distance) }}</td>
                                    <td class="px-4 py-2.5 text-right text-gray-600 dark:text-slate-300">{{ formatDuration(act.moving_time) }}</td>
                                    <td class="px-4 py-2.5 text-right text-gray-600 dark:text-slate-300">{{ formatPaceFromSpeed(act.average_speed) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs text-gray-400 dark:text-slate-500 whitespace-nowrap">{{ formatDate(act.start_date) }}</td>
                                </tr>
                                <tr v-if="!activities.length">
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-400 dark:text-slate-500">Keine Aktivitäten vorhanden.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Events / Race Goals -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Rennen & Ziele ({{ events.length }})</h2>
                    <div v-if="events.length" class="space-y-3">
                        <div
                            v-for="event in events" :key="event.id"
                            class="flex items-start justify-between p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 gap-3"
                        >
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ event.name }}</p>
                                    <span class="text-xs px-1.5 py-0.5 rounded font-bold"
                                        :class="event.priority === 'A'
                                            ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300'
                                            : event.priority === 'B'
                                                ? 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300'
                                                : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'"
                                    >Prio {{ event.priority }}</span>
                                </div>
                                <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                                    {{ event.distance_label }}
                                    <span v-if="event.target_time_hours > 0 || event.target_time_minutes > 0">
                                        · Ziel: {{ event.target_time_hours > 0 ? event.target_time_hours + 'h ' : '' }}{{ event.target_time_minutes }}min
                                    </span>
                                    · {{ formatDate(event.event_date) }}
                                </p>
                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                                    <span v-if="event.training_plans?.some(p => p.is_active)"
                                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 font-medium">
                                        ✓ Aktiver Plan
                                    </span>
                                    <span v-else-if="event.training_plans?.length"
                                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 font-medium">
                                        {{ event.training_plans.length }} Plan(e)
                                    </span>
                                    <span v-else
                                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 font-medium">
                                        Kein Plan
                                    </span>
                                </div>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full shrink-0 mt-0.5"
                                :class="new Date(event.event_date) < new Date()
                                    ? 'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
                                    : 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300'"
                            >
                                {{ new Date(event.event_date) < new Date() ? 'Vergangen' : 'Geplant' }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500">Keine Rennen / Ziele vorhanden.</p>
                </div>
            </template>

            <!-- ══════════════════════════════════════════════════
                 TAB: AI-AKTIVITÄT
                 ══════════════════════════════════════════════════ -->
            <template v-if="activeTab === 'ai'">

                <!-- AI Rate Limit -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl px-5 py-4 flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-gray-800 dark:text-slate-200">AI-Tageslimit</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">Heute genutzt: <strong class="text-gray-700 dark:text-slate-300">{{ aiTodayUsed }}</strong> / {{ user.ai_daily_limit ?? 20 }} Calls</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input v-model.number="aiLimitInput" type="number" min="0" max="500"
                            class="w-20 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm text-gray-900 dark:text-white text-center focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                        <button @click="saveAiLimit" class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-xs font-semibold text-white transition-colors">Speichern</button>
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
                        class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-xl p-4"
                    >
                        <p class="text-xs text-gray-500 dark:text-slate-400">{{ kpi.label }}</p>
                        <p class="mt-0.5 text-xl font-bold text-gray-900 dark:text-white truncate">{{ kpi.value }}</p>
                    </div>
                </div>

                <!-- By type -->
                <div v-if="aiStats.by_type?.length" class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Aufteilung nach Typ</h2>
                    <div class="space-y-2.5">
                        <div v-for="t in aiStats.by_type" :key="t.call_type" class="flex items-center gap-3">
                            <span class="text-xs text-gray-600 dark:text-slate-300 font-medium w-36 shrink-0 truncate">{{ callTypeLabel(t.call_type) }}</span>
                            <div class="flex-1 bg-gray-100 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                <div
                                    class="h-2 rounded-full bg-blue-400 dark:bg-blue-500 transition-all"
                                    :style="{ width: Math.round((t.count / maxAiTypeCalls) * 100) + '%' }"
                                />
                            </div>
                            <span class="text-xs text-gray-500 dark:text-slate-400 shrink-0 w-8 text-right tabular-nums">{{ t.count }}</span>
                            <span class="text-xs text-gray-400 dark:text-slate-500 shrink-0 w-20 text-right tabular-nums">{{ formatCost(t.cost) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Last 10 AI calls -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-slate-800 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Letzte 10 AI-Calls</h2>
                        <Link :href="route('admin.ai-logs.index', { user_id: user.id })" class="text-xs text-red-500 dark:text-red-400 hover:underline">
                            Alle anzeigen →
                        </Link>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-50 dark:border-slate-800">
                                <tr class="text-left text-xs text-gray-500 dark:text-slate-400">
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider">Zeit</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider">Typ</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Tokens</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Kosten</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-right">Dauer</th>
                                    <th class="px-4 py-2.5 font-semibold uppercase tracking-wider text-center">Status</th>
                                    <th class="px-4 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                                <tr v-for="log in aiLogs" :key="log.id" class="hover:bg-gray-50 dark:hover:bg-slate-800/40 transition-colors">
                                    <td class="px-4 py-2.5 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">{{ formatDateTime(log.created_at) }}</td>
                                    <td class="px-4 py-2.5 text-xs font-medium text-gray-700 dark:text-slate-300">{{ callTypeLabel(log.call_type) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs text-gray-600 dark:text-slate-300 tabular-nums">{{ formatTokens(log.total_tokens) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs font-medium text-gray-900 dark:text-white tabular-nums">{{ formatCost(log.cost_eur) }}</td>
                                    <td class="px-4 py-2.5 text-right text-xs text-gray-500 dark:text-slate-400 tabular-nums">{{ log.duration_ms }} ms</td>
                                    <td class="px-4 py-2.5 text-center">
                                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                            :class="log.status === 'success'
                                                ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300'
                                                : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'"
                                        >{{ log.status === 'success' ? 'OK' : 'Fehler' }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <Link :href="route('admin.ai-logs.show', log.id)" class="text-xs text-gray-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400">→</Link>
                                    </td>
                                </tr>
                                <tr v-if="!aiLogs?.length">
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">Noch keine AI-Calls vorhanden.</td>
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
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Wellbeing-Score letzte 30 Einträge</h2>
                    <div v-if="wellbeingChart?.length" class="flex items-end gap-1 h-28">
                        <div
                            v-for="entry in wellbeingChart" :key="entry.date"
                            class="flex-1 flex flex-col items-center gap-0.5 group"
                        >
                            <div
                                class="w-full rounded-t transition-all relative"
                                :class="entry.score >= 7 ? 'bg-green-400 dark:bg-green-500 group-hover:bg-green-600' :
                                        entry.score >= 5 ? 'bg-yellow-400 dark:bg-yellow-500 group-hover:bg-yellow-600' :
                                                          'bg-red-400 dark:bg-red-500 group-hover:bg-red-600'"
                                :style="{ height: Math.max(4, Math.round((entry.score / maxWellbeingScore) * 96)) + 'px' }"
                            >
                                <div class="absolute -top-7 left-1/2 -translate-x-1/2 hidden group-hover:block bg-gray-800 text-white text-[10px] rounded px-1.5 py-0.5 whitespace-nowrap z-10">
                                    {{ entry.date }} · {{ entry.score }}/10
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500 text-center py-8">Noch keine Wellbeing-Daten.</p>
                    <div class="flex gap-4 mt-3 text-xs text-gray-400 dark:text-slate-500">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-green-400 inline-block"></span>≥ 7 gut</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-yellow-400 inline-block"></span>5–7 mittel</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-400 inline-block"></span>&lt; 5 schlecht</span>
                    </div>
                </div>

                <!-- Wellbeing table -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Letzte 14 Einträge</h2>
                    <div v-if="wellbeingEntries.length" class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-400 dark:text-slate-500 border-b border-gray-100 dark:border-slate-800">
                                    <th class="pb-2 font-medium">Datum</th>
                                    <th class="pb-2 font-medium text-center">⚡ Energie</th>
                                    <th class="pb-2 font-medium text-center">😊 Stimmung</th>
                                    <th class="pb-2 font-medium text-center">💤 Schlaf</th>
                                    <th class="pb-2 font-medium text-center">💪 Muskelkater</th>
                                    <th class="pb-2 font-medium text-center">😤 Stress</th>
                                    <th class="pb-2 font-medium text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                                <tr v-for="wb in wellbeingEntries" :key="wb.id" class="hover:bg-gray-50 dark:hover:bg-slate-800/30">
                                    <td class="py-2 text-gray-500 dark:text-slate-400 whitespace-nowrap font-medium">{{ formatDate(wb.date) }}</td>
                                    <td class="py-2 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.energy_level }}</td>
                                    <td class="py-2 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.mood }}</td>
                                    <td class="py-2 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.sleep_quality }}</td>
                                    <td class="py-2 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.muscle_soreness }}</td>
                                    <td class="py-2 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.stress_level }}</td>
                                    <td class="py-2 text-center">
                                        <span v-if="wb.is_sick || wb.is_injured" class="px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-500/15 text-red-600 dark:text-red-400">
                                            {{ wb.is_sick ? 'Krank' : 'Verletzt' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500">Keine Wellbeing-Einträge vorhanden.</p>
                </div>
            </template>

        </div>

        <!-- ── Modals ────────────────────────────────────────── -->
        <Modal :show="showDeleteModal" @close="showDeleteModal = false">
            <div class="p-4 sm:p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Nutzer löschen</h3>
                <p class="text-gray-600 dark:text-slate-400">
                    Soll <strong class="text-gray-900 dark:text-white">{{ user.name }}</strong> wirklich unwiderruflich gelöscht werden? Alle Aktivitäten, Ziele und Daten werden entfernt.
                </p>
                <div class="flex justify-end gap-3">
                    <button @click="showDeleteModal = false" class="px-4 py-2 text-sm rounded-xl border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">Abbrechen</button>
                    <button @click="deleteUser" class="px-4 py-2 text-sm rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition-colors">Ja, löschen</button>
                </div>
            </div>
        </Modal>

        <Modal :show="showWeeklyModal" @close="showWeeklyModal = false">
            <div class="p-4 sm:p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Weekly Review triggern</h3>
                <p class="text-gray-600 dark:text-slate-400">
                    Soll für <strong class="text-gray-900 dark:text-white">{{ user.name }}</strong> der Wochenrückblick der letzten Woche manuell generiert werden? Ein bereits vorhandener Review dieser Woche wird überschrieben.
                </p>
                <div class="flex justify-end gap-3">
                    <button @click="showWeeklyModal = false" class="px-4 py-2 text-sm rounded-xl border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">Abbrechen</button>
                    <button @click="triggerWeeklyReview" class="px-4 py-2 text-sm rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold transition-colors">Ja, generieren</button>
                </div>
            </div>
        </Modal>

    </AdminLayout>
</template>
