<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats:                 Object,
    registrationsPerMonth: Array,
    activitiesPerMonth:    Array,
    aiCostsPerDay:         Array,
    coachDistribution:     Array,
    wellbeingTrend:        Array,
    recentUsers:           Array,
});

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatShortDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
}

function monthLabel(ym) {
    const [year, month] = ym.split('-');
    return new Date(year, month - 1).toLocaleDateString('de-DE', { month: 'short', year: '2-digit' });
}

function barHeight(val, max) {
    if (!max) return 0;
    return Math.max(4, Math.round((val / max) * 100));
}

function formatCost(eur) {
    const val = parseFloat(eur);
    if (!val) return '0,00 ct';
    if (val < 0.001) return (val * 100).toFixed(4) + ' ct';
    return val.toFixed(4) + ' €';
}

function wellbeingColor(score) {
    const s = parseFloat(score);
    if (s >= 7) return 'bg-success';
    if (s >= 5) return 'bg-warn';
    return 'bg-danger';
}

const coachBg = { orange: 'bg-warn', blue: 'bg-info', green: 'bg-success', purple: 'bg-accent' };

const maxReg      = computed(() => Math.max(...(props.registrationsPerMonth?.map(r => r.count)           ?? [0]), 1));
const maxAct      = computed(() => Math.max(...(props.activitiesPerMonth?.map(a => a.count)              ?? [0]), 1));
const maxAiCost   = computed(() => Math.max(...(props.aiCostsPerDay?.map(d => parseFloat(d.cost))        ?? [0]), 0.000001));
const maxCoach    = computed(() => Math.max(...(props.coachDistribution?.map(c => c.users_count)         ?? [0]), 1));
const maxWellbeing = computed(() => 10);
</script>

<template>
    <Head title="Admin – Übersicht" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-ink">Übersicht</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-8">

            <!-- ── Primäre KPIs ──────────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="card in [
                    { label: 'Nutzer gesamt',    value: stats.total_users,      sub: stats.onboarded_users + ' onboarded',   color: 'text-accent-ink' },
                    { label: 'Aktivitäten',      value: stats.total_activities, sub: 'gesamt',                               color: 'text-info-ink'   },
                    { label: 'Aktive Pläne',     value: stats.active_plans,     sub: 'Trainingspläne',                       color: 'text-accent-ink' },
                    { label: 'Geplante Events',  value: stats.upcoming_events,  sub: 'von ' + stats.total_events + ' gesamt', color: 'text-warn-ink' },
                ]" :key="card.label"
                    class="bg-surface rounded-card p-5"
                >
                    <p class="text-sm text-ink-3">{{ card.label }}</p>
                    <p class="mt-1 text-3xl font-bold text-ink">{{ card.value }}</p>
                    <p class="mt-1 text-xs" :class="card.color">{{ card.sub }}</p>
                </div>
            </div>

            <!-- ── AI KPIs ──────────────────────────────────────── -->
            <div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-ink-3 mb-3">KI-Nutzung</h2>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div v-for="card in [
                        { label: 'Calls heute',    value: stats.ai_calls_today,             display: String(stats.ai_calls_today) },
                        { label: 'Kosten heute',   value: stats.ai_cost_today,              display: formatCost(stats.ai_cost_today) },
                        { label: 'Calls gesamt',   value: stats.ai_calls_total,             display: String(stats.ai_calls_total) },
                        { label: 'Kosten gesamt',  value: stats.ai_cost_total,              display: formatCost(stats.ai_cost_total) },
                    ]" :key="card.label"
                        class="bg-surface rounded-field p-4"
                    >
                        <p class="text-xs text-ink-3">{{ card.label }}</p>
                        <p class="mt-0.5 text-2xl font-bold text-ink">{{ card.display }}</p>
                    </div>
                </div>
            </div>

            <!-- ── Registrierungen + Aktivitäten ────────────────── -->
            <div class="grid lg:grid-cols-2 gap-6">

                <div class="bg-surface rounded-card p-6">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Neue Registrierungen (letzte 6 Monate)</h2>
                    <div v-if="registrationsPerMonth.length" class="flex items-end gap-2 h-32">
                        <div v-for="item in registrationsPerMonth" :key="item.month" class="flex-1 flex flex-col items-center gap-1">
                            <span class="text-xs text-ink-3">{{ item.count }}</span>
                            <div class="w-full rounded-t-md bg-accent transition-all"
                                :style="{ height: barHeight(item.count, maxReg) + 'px' }"></div>
                            <span class="text-[10px] text-ink-3">{{ monthLabel(item.month) }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-3 py-8 text-center">Keine Daten vorhanden</p>
                </div>

                <div class="bg-surface rounded-card p-6">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Aktivitäten (letzte 6 Monate)</h2>
                    <div v-if="activitiesPerMonth.length" class="flex items-end gap-2 h-32">
                        <div v-for="item in activitiesPerMonth" :key="item.month" class="flex-1 flex flex-col items-center gap-1">
                            <span class="text-xs text-ink-3">{{ item.count }}</span>
                            <div class="w-full rounded-t-md bg-info transition-all"
                                :style="{ height: barHeight(item.count, maxAct) + 'px' }"></div>
                            <span class="text-[10px] text-ink-3">{{ monthLabel(item.month) }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-3 py-8 text-center">Keine Daten vorhanden</p>
                </div>
            </div>

            <!-- ── AI Kosten/Tag + Coach-Verteilung ─────────────── -->
            <div class="grid lg:grid-cols-2 gap-6">

                <!-- AI Kosten letzte 30 Tage -->
                <div class="bg-surface rounded-card p-6">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">AI-Kosten pro Tag (letzte 30 Tage)</h2>
                    <div v-if="aiCostsPerDay.length" class="w-full overflow-x-auto">
                        <div class="flex items-end gap-1 h-32 w-max min-w-full">
                            <div v-for="item in aiCostsPerDay" :key="item.day" class="w-5 shrink-0 flex flex-col items-center gap-1 group relative">
                                <div class="w-full rounded-t bg-accent transition-all cursor-default"
                                    :style="{ height: barHeight(parseFloat(item.cost), maxAiCost) + 'px' }">
                                </div>
                                <!-- Tooltip -->
                                <div class="absolute bottom-full mb-1 left-1/2 -translate-x-1/2 hidden group-hover:block z-10 bg-ink text-canvas text-[10px] rounded px-2 py-1 whitespace-nowrap pointer-events-none">
                                    {{ formatShortDate(item.day) }}: {{ formatCost(item.cost) }} ({{ item.calls }} Calls)
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-3 py-8 text-center">Noch keine KI-Calls</p>
                    <div v-if="aiCostsPerDay.length" class="flex justify-between mt-1">
                        <span class="text-[10px] text-ink-3">{{ formatShortDate(aiCostsPerDay[0]?.day) }}</span>
                        <span class="text-[10px] text-ink-3">{{ formatShortDate(aiCostsPerDay[aiCostsPerDay.length - 1]?.day) }}</span>
                    </div>
                </div>

                <!-- Coach-Verteilung -->
                <div class="bg-surface rounded-card p-6">
                    <h2 class="text-sm font-semibold text-ink-2 mb-4">Coach-Verteilung</h2>
                    <div v-if="coachDistribution.length" class="space-y-3">
                        <div v-for="coach in coachDistribution" :key="coach.id" class="flex items-center gap-3">
                            <div class="h-7 w-7 rounded-lg flex items-center justify-center text-xs font-bold text-white shrink-0"
                                :class="coachBg[coach.avatar_color] ?? 'bg-ink-3'">
                                {{ coach.avatar_initials }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-ink truncate">{{ coach.name }}</span>
                                    <span class="text-xs text-ink-3 ml-2 shrink-0">{{ coach.users_count }} Athleten</span>
                                </div>
                                <div class="h-2 w-full bg-surface-2 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all"
                                        :class="coachBg[coach.avatar_color] ?? 'bg-ink-3'"
                                        :style="{ width: barHeight(coach.users_count, maxCoach) + '%' }">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-3 py-8 text-center">Keine Coaches vorhanden</p>
                </div>
            </div>

            <!-- ── Wellbeing-Trend ───────────────────────────────── -->
            <div class="bg-surface rounded-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-ink-2">Wellbeing-Trend (letzte 14 Tage, Plattform-Ø)</h2>
                    <div class="flex items-center gap-3 text-xs text-ink-3">
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-success inline-block"></span>≥ 7</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-warn inline-block"></span>5–7</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-danger inline-block"></span>&lt; 5</span>
                    </div>
                </div>
                <div v-if="wellbeingTrend.length" class="w-full overflow-x-auto">
                <div class="flex items-end gap-1.5 h-28 w-max min-w-full">
                    <div v-for="item in wellbeingTrend" :key="item.date" class="w-8 shrink-0 flex flex-col items-center gap-1 group relative">
                        <span class="text-xs text-ink-3">{{ item.avg_score }}</span>
                        <div class="w-full rounded-t-md transition-all"
                            :class="wellbeingColor(item.avg_score)"
                            :style="{ height: barHeight(parseFloat(item.avg_score), maxWellbeing) + 'px' }">
                        </div>
                        <!-- Tooltip -->
                        <div class="absolute bottom-full mb-1 left-1/2 -translate-x-1/2 hidden group-hover:block z-10 bg-ink text-canvas text-[10px] rounded px-2 py-1 whitespace-nowrap pointer-events-none">
                            {{ formatShortDate(item.date) }}: Ø {{ item.avg_score }} ({{ item.entries }} Einträge)
                        </div>
                        <span class="text-[10px] text-ink-3">{{ formatShortDate(item.date) }}</span>
                    </div>
                </div>
                </div>
                <p v-else class="text-sm text-ink-3 py-8 text-center">Keine Wellbeing-Einträge in den letzten 14 Tagen</p>
            </div>

            <!-- ── Sekundäre Stats ───────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <div v-for="card in [
                    { label: 'Onboarded',              value: stats.onboarded_users },
                    { label: 'Admins',                 value: stats.admin_users     },
                    { label: 'Strava-Verbunden',       value: stats.strava_users    },
                    { label: 'Events gesamt',          value: stats.total_events    },
                    { label: 'Wellbeing-Einträge',     value: stats.total_wellbeing },
                ]" :key="card.label"
                    class="bg-surface rounded-field p-4"
                >
                    <p class="text-xs text-ink-3">{{ card.label }}</p>
                    <p class="mt-0.5 text-2xl font-bold text-ink">{{ card.value }}</p>
                </div>
            </div>

            <!-- ── Zuletzt registriert ───────────────────────────── -->
            <div class="bg-surface rounded-card overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Zuletzt registriert</h2>
                    <Link :href="route('admin.users.index')" class="text-xs text-accent-ink hover:underline">
                        Alle Nutzer →
                    </Link>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="u in recentUsers" :key="u.id" class="hover:bg-surface-2/50 transition-colors">
                            <td class="px-6 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-gradient-to-br from-ink-3 to-ink-3 flex items-center justify-center shrink-0">
                                        <span class="text-xs font-bold text-white">{{ u.name.charAt(0).toUpperCase() }}</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-ink">{{ u.name }}</p>
                                        <p class="text-xs text-ink-3">{{ u.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <span v-if="u.is_admin" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-danger-soft text-danger-ink mr-2">Admin</span>
                                <span class="text-xs text-ink-3">{{ formatDate(u.created_at) }}</span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <Link :href="route('admin.users.show', u.id)" class="text-xs text-accent-ink hover:underline">
                                    Ansehen →
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
