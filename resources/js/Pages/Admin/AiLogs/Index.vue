<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    logs:        Object,
    kpis:        Object,
    callsPerDay: Array,
    byType:      Array,
    topUsers:    Array,
    callTypes:   Array,
    users:       Array,
    filters:     Object,
});

const filterUserId   = ref(props.filters?.user_id   ?? '');
const filterCallType = ref(props.filters?.call_type ?? '');
const filterStatus   = ref(props.filters?.status    ?? '');
const filterDateFrom = ref(props.filters?.date_from ?? '');
const filterDateTo   = ref(props.filters?.date_to   ?? '');

let debounceTimer = null;
function applyFilters() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(route('admin.ai-logs.index'), {
            user_id:   filterUserId.value   || undefined,
            call_type: filterCallType.value || undefined,
            status:    filterStatus.value   || undefined,
            date_from: filterDateFrom.value || undefined,
            date_to:   filterDateTo.value   || undefined,
        }, { preserveScroll: true, preserveState: true });
    }, 300);
}

watch([filterUserId, filterCallType, filterStatus, filterDateFrom, filterDateTo], applyFilters);

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function formatCost(eur) {
    const val = parseFloat(eur);
    if (!val) return '0,00 ct';
    if (val < 0.001) return (val * 100).toFixed(4) + ' ct';
    return val.toFixed(4) + ' €';
}

function formatTokens(n) {
    if (!n) return '0';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return n.toString();
}

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

const typeColor = (t) => ({
    recommendation:        'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    adjust_recommendation: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    plan:                  'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    event_plan:            'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
    weekly_review:         'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    pace_zones:            'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
    threshold_pace:        'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    nutrition:             'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
    adjust_session:        'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    goal_analysis:         'bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300',
    profile_estimation:    'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
}[t] ?? 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300');

// Chart helpers
const maxDayCalls = computed(() => Math.max(...(props.callsPerDay?.map(d => d.calls) ?? [1]), 1));
const maxTypeCalls = computed(() => Math.max(...(props.byType?.map(t => t.count) ?? [1]), 1));
</script>

<template>
    <Head title="Admin – AI Logs" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">AI Logs</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-6">

            <!-- ── KPI Row ── -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div v-for="kpi in [
                    { label: 'Calls heute',   value: kpis.calls_today,                color: 'blue'   },
                    { label: 'Tokens heute',  value: formatTokens(kpis.tokens_today), color: 'violet' },
                    { label: 'Kosten heute',  value: formatCost(kpis.cost_today),     color: 'green'  },
                    { label: 'Ø Dauer (ms)',  value: kpis.avg_duration + ' ms',       color: 'orange' },
                    { label: 'Fehler heute',  value: kpis.errors_today,               color: 'red'    },
                    { label: 'Kosten gesamt', value: formatCost(kpis.cost_total),     color: 'indigo' },
                ]" :key="kpi.label"
                    class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-4"
                >
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-1">{{ kpi.label }}</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white">{{ kpi.value }}</p>
                </div>
            </div>

            <!-- ── Charts Row ── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                <!-- Calls per day -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Calls letzte 30 Tage</h2>
                    <div v-if="callsPerDay?.length" class="flex items-end gap-1 h-28">
                        <div
                            v-for="day in callsPerDay" :key="day.date"
                            class="flex-1 flex flex-col items-center gap-0.5 group"
                        >
                            <div
                                class="w-full rounded-t bg-blue-400 dark:bg-blue-500 transition-all group-hover:bg-blue-600 dark:group-hover:bg-blue-400 relative"
                                :style="{ height: Math.max(4, Math.round((day.calls / maxDayCalls) * 96)) + 'px' }"
                            >
                                <div class="absolute -top-7 left-1/2 -translate-x-1/2 hidden group-hover:block bg-gray-800 text-white text-[10px] rounded px-1.5 py-0.5 whitespace-nowrap z-10">
                                    {{ day.calls }} Calls · {{ formatCost(day.cost) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500 text-center py-8">Noch keine Daten</p>
                </div>

                <!-- By type -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Aufteilung nach Typ</h2>
                    <div class="space-y-2">
                        <div v-for="t in byType" :key="t.call_type" class="flex items-center gap-3">
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" :class="typeColor(t.call_type)">
                                {{ callTypeLabel(t.call_type) }}
                            </span>
                            <div class="flex-1 bg-gray-100 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                <div
                                    class="h-2 rounded-full bg-blue-400 dark:bg-blue-500"
                                    :style="{ width: Math.round((t.count / maxTypeCalls) * 100) + '%' }"
                                />
                            </div>
                            <span class="text-xs text-gray-500 dark:text-slate-400 shrink-0 w-8 text-right">{{ t.count }}</span>
                            <span class="text-xs text-gray-400 dark:text-slate-500 shrink-0 w-16 text-right">{{ formatCost(t.cost) }}</span>
                        </div>
                        <p v-if="!byType?.length" class="text-sm text-gray-400 dark:text-slate-500 text-center py-4">Noch keine Daten</p>
                    </div>
                </div>
            </div>

            <!-- ── Top Users ── -->
            <div v-if="topUsers?.length" class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Top 10 Nutzer nach Kosten</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-slate-800 text-left">
                                <th class="pb-2 text-xs font-medium text-gray-500 dark:text-slate-400">Nutzer</th>
                                <th class="pb-2 text-xs font-medium text-gray-500 dark:text-slate-400 text-right">Calls</th>
                                <th class="pb-2 text-xs font-medium text-gray-500 dark:text-slate-400 text-right">Tokens</th>
                                <th class="pb-2 text-xs font-medium text-gray-500 dark:text-slate-400 text-right">Kosten</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in topUsers" :key="u.user_id" class="border-b border-gray-50 dark:border-slate-800/60 hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                <td class="py-2">
                                    <Link :href="route('admin.users.show', u.user_id)" class="font-medium text-gray-900 dark:text-white hover:text-red-500 dark:hover:text-red-400">
                                        {{ u.user?.name ?? '–' }}
                                    </Link>
                                    <p class="text-xs text-gray-400 dark:text-slate-500">{{ u.user?.email }}</p>
                                </td>
                                <td class="py-2 text-right text-gray-700 dark:text-slate-300">{{ u.calls }}</td>
                                <td class="py-2 text-right text-gray-700 dark:text-slate-300">{{ formatTokens(u.tokens) }}</td>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">{{ formatCost(u.cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Filters ── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5">
                <div class="flex flex-wrap gap-3">
                    <select v-model="filterUserId" class="text-sm border border-gray-200 dark:border-slate-700 rounded-xl px-3 py-2 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-red-400">
                        <option value="">Alle Nutzer</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="filterCallType" class="text-sm border border-gray-200 dark:border-slate-700 rounded-xl px-3 py-2 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-red-400">
                        <option value="">Alle Typen</option>
                        <option v-for="t in callTypes" :key="t" :value="t">{{ callTypeLabel(t) }}</option>
                    </select>
                    <select v-model="filterStatus" class="text-sm border border-gray-200 dark:border-slate-700 rounded-xl px-3 py-2 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-red-400">
                        <option value="">Alle Status</option>
                        <option value="success">Erfolgreich</option>
                        <option value="error">Fehler</option>
                    </select>
                    <input v-model="filterDateFrom" type="date" placeholder="Von" class="text-sm border border-gray-200 dark:border-slate-700 rounded-xl px-3 py-2 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-red-400" />
                    <input v-model="filterDateTo" type="date" placeholder="Bis" class="text-sm border border-gray-200 dark:border-slate-700 rounded-xl px-3 py-2 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-red-400" />
                </div>
            </div>

            <!-- ── Log Table ── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 dark:border-slate-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Zeit</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Nutzer</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Typ</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Tokens</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Kosten</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Dauer</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 dark:divide-slate-800/60">
                            <tr v-if="!logs?.data?.length">
                                <td colspan="8" class="px-4 py-12 text-center text-sm text-gray-400 dark:text-slate-500">
                                    Noch keine AI-Calls geloggt.
                                </td>
                            </tr>
                            <tr
                                v-for="log in logs.data" :key="log.id"
                                class="hover:bg-gray-50 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">
                                    {{ formatDate(log.created_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Link v-if="log.user" :href="route('admin.users.show', log.user.id)" class="text-sm font-medium text-gray-800 dark:text-slate-200 hover:text-red-500 dark:hover:text-red-400">
                                        {{ log.user.name }}
                                    </Link>
                                    <span v-else class="text-sm text-gray-400 dark:text-slate-500">System</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="typeColor(log.call_type)">
                                        {{ callTypeLabel(log.call_type) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-slate-300 tabular-nums">
                                    {{ formatTokens(log.total_tokens) }}
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white tabular-nums">
                                    {{ formatCost(log.cost_eur) }}
                                </td>
                                <td class="px-4 py-3 text-right text-xs text-gray-500 dark:text-slate-400 tabular-nums">
                                    {{ log.duration_ms }} ms
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="text-xs px-2 py-0.5 rounded-full font-medium"
                                        :class="log.status === 'success'
                                            ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300'
                                            : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'"
                                    >
                                        {{ log.status === 'success' ? 'OK' : 'Fehler' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('admin.ai-logs.show', log.id)"
                                        class="text-xs text-gray-500 dark:text-slate-400 hover:text-red-500 dark:hover:text-red-400 font-medium"
                                    >
                                        Detail →
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="logs?.links?.length > 3" class="border-t border-gray-100 dark:border-slate-800 px-4 py-3 flex items-center justify-between">
                    <p class="text-xs text-gray-500 dark:text-slate-400">
                        {{ logs.from }}–{{ logs.to }} von {{ logs.total }}
                    </p>
                    <div class="flex gap-1">
                        <template v-for="link in logs.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                                :class="link.active
                                    ? 'bg-red-500 text-white'
                                    : 'text-gray-600 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800'"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
