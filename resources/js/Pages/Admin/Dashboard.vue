<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatTile from '@/Components/UI/StatTile.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
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
    systemHealth:          Object,
});

/**
 * Was auf /admin/system rot wäre — hier als eine Zeile.
 *
 * Eine Statusseite, die man aktiv aufrufen muss, wird genau dann nicht
 * aufgerufen, wenn sie gebraucht wird: beim Ausbleiben des Strava-Imports
 * hat tagelang niemand nachgesehen, weil niemand wusste, dass es etwas
 * nachzusehen gab.
 */
const trouble = computed(() => props.systemHealth?.issues ?? []);

/** Eine stehende Queue ist ein Ausfall, alles andere eine Auffälligkeit. */
const troubleIsSevere = computed(() => props.systemHealth?.severe === true);

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

/** Anteil an der Skala in Prozent — die Balkenhöhe folgt dem Container. */
function share(val, max) {
    if (!max) return 0;
    return Math.max(3, Math.round((val / max) * 100));
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

const maxReg    = computed(() => Math.max(...(props.registrationsPerMonth?.map(r => r.count)    ?? [0]), 1));
const maxAct    = computed(() => Math.max(...(props.activitiesPerMonth?.map(a => a.count)       ?? [0]), 1));
const maxAiCost = computed(() => Math.max(...(props.aiCostsPerDay?.map(d => parseFloat(d.cost)) ?? [0]), 0.000001));
const maxCoach  = computed(() => Math.max(...(props.coachDistribution?.map(c => c.users_count)  ?? [0]), 1));
</script>

<template>
    <Head title="Admin – Übersicht" />

    <AdminLayout>
        <template #header>
            <h1 class="text-2xl font-bold tracking-tight text-ink lg:text-3xl">Übersicht</h1>
        </template>

        <div class="space-y-8 px-4 py-4 sm:px-6 lg:py-6">

            <!-- ── Systemstatus ──────────────────────────────────── -->
            <Link
                v-if="trouble.length"
                :href="route('admin.system.index')"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-field border px-4 py-3 text-sm font-medium transition hover:opacity-90"
                :class="troubleIsSevere
                    ? 'bg-danger-soft border-danger/25 text-danger-ink'
                    : 'bg-warn-soft border-warn/25 text-warn-ink'">
                <span class="font-semibold">System</span>
                <span v-for="(t, i) in trouble" :key="i" class="opacity-90">
                    {{ t }}<span v-if="i < trouble.length - 1"> ·</span>
                </span>
                <span class="ml-auto text-xs underline">ansehen</span>
            </Link>

            <!-- ── Plattform ─────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile label="Nutzer gesamt"   :value="stats.total_users"      :hint="`${stats.onboarded_users} onboarded`" />
                <StatTile label="Aktivitäten"     :value="stats.total_activities" hint="gesamt" />
                <StatTile label="Aktive Pläne"    :value="stats.active_plans"     hint="Trainingspläne" />
                <StatTile label="Geplante Events" :value="stats.upcoming_events"  :hint="`von ${stats.total_events} gesamt`" />
            </div>

            <!-- ── KI-Nutzung ────────────────────────────────────── -->
            <section>
                <SectionHeader title="KI-Nutzung" />

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <div class="grid grid-cols-2 gap-3 xl:content-start">
                        <StatTile label="Calls heute"   :value="stats.ai_calls_today" />
                        <StatTile label="Kosten heute"  :value="formatCost(stats.ai_cost_today)" tone="accent" />
                        <StatTile label="Calls gesamt"  :value="stats.ai_calls_total" />
                        <StatTile label="Kosten gesamt" :value="formatCost(stats.ai_cost_total)" tone="accent" />
                    </div>

                    <AppCard title="Kosten pro Tag" subtitle="letzte 30 Tage" class="xl:col-span-2">
                        <div v-if="aiCostsPerDay.length">
                            <div class="flex h-32 items-end gap-1">
                                <div
                                    v-for="item in aiCostsPerDay"
                                    :key="item.day"
                                    class="group flex h-full min-w-0 flex-1 flex-col justify-end"
                                    :title="`${formatShortDate(item.day)}: ${formatCost(item.cost)} (${item.calls} Calls)`"
                                >
                                    <span
                                        class="w-full rounded-t bg-accent/70 transition-colors group-hover:bg-accent"
                                        :style="{ height: share(parseFloat(item.cost), maxAiCost) + '%' }"
                                    />
                                </div>
                            </div>
                            <div class="mt-2 flex justify-between text-[10px] text-ink-3">
                                <span>{{ formatShortDate(aiCostsPerDay[0]?.day) }}</span>
                                <span>{{ formatShortDate(aiCostsPerDay[aiCostsPerDay.length - 1]?.day) }}</span>
                            </div>
                        </div>
                        <p v-else class="py-10 text-center text-sm text-ink-3">Noch keine KI-Calls</p>
                    </AppCard>
                </div>
            </section>

            <!-- ── Wachstum ──────────────────────────────────────── -->
            <section>
                <SectionHeader title="Wachstum" />

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <AppCard title="Neue Registrierungen" subtitle="letzte 6 Monate">
                        <div v-if="registrationsPerMonth.length" class="flex h-36 items-end gap-2">
                            <div v-for="item in registrationsPerMonth" :key="item.month"
                                class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1.5">
                                <span class="text-center text-xs font-semibold tabular-nums text-ink">{{ item.count }}</span>
                                <span class="w-full rounded-t-lg bg-accent" :style="{ height: share(item.count, maxReg) + '%' }" />
                                <span class="truncate text-center text-[10px] text-ink-3">{{ monthLabel(item.month) }}</span>
                            </div>
                        </div>
                        <p v-else class="py-10 text-center text-sm text-ink-3">Keine Daten vorhanden</p>
                    </AppCard>

                    <AppCard title="Aktivitäten" subtitle="letzte 6 Monate">
                        <div v-if="activitiesPerMonth.length" class="flex h-36 items-end gap-2">
                            <div v-for="item in activitiesPerMonth" :key="item.month"
                                class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1.5">
                                <span class="text-center text-xs font-semibold tabular-nums text-ink">{{ item.count }}</span>
                                <span class="w-full rounded-t-lg bg-info" :style="{ height: share(item.count, maxAct) + '%' }" />
                                <span class="truncate text-center text-[10px] text-ink-3">{{ monthLabel(item.month) }}</span>
                            </div>
                        </div>
                        <p v-else class="py-10 text-center text-sm text-ink-3">Keine Daten vorhanden</p>
                    </AppCard>
                </div>
            </section>

            <!-- ── Athleten ──────────────────────────────────────── -->
            <section>
                <SectionHeader title="Athleten" />

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <AppCard class="xl:col-span-2">
                        <template #header>
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <div>
                                    <h3 class="text-[15px] font-semibold text-ink">Wellbeing-Trend</h3>
                                    <p class="mt-0.5 text-[13px] text-ink-3">letzte 14 Tage, Plattform-Ø</p>
                                </div>
                                <div class="flex items-center gap-3 text-[11px] text-ink-3">
                                    <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm bg-success" />≥ 7</span>
                                    <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm bg-warn" />5–7</span>
                                    <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm bg-danger" />&lt; 5</span>
                                </div>
                            </div>
                        </template>

                        <div v-if="wellbeingTrend.length" class="flex h-36 items-end gap-1.5">
                            <div
                                v-for="item in wellbeingTrend"
                                :key="item.date"
                                class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1.5"
                                :title="`${formatShortDate(item.date)}: Ø ${item.avg_score} (${item.entries} Einträge)`"
                            >
                                <span class="text-center text-[11px] font-semibold tabular-nums text-ink">{{ item.avg_score }}</span>
                                <span class="w-full rounded-t-lg" :class="wellbeingColor(item.avg_score)"
                                    :style="{ height: share(parseFloat(item.avg_score), 10) + '%' }" />
                                <span class="truncate text-center text-[10px] text-ink-3">{{ formatShortDate(item.date) }}</span>
                            </div>
                        </div>
                        <p v-else class="py-10 text-center text-sm text-ink-3">Keine Wellbeing-Einträge in den letzten 14 Tagen</p>
                    </AppCard>

                    <AppCard title="Coach-Verteilung">
                        <div v-if="coachDistribution.length" class="space-y-4">
                            <div v-for="coach in coachDistribution" :key="coach.id" class="flex items-center gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-field text-xs font-bold text-white"
                                    :class="coachBg[coach.avatar_color] ?? 'bg-ink-3'">
                                    {{ coach.avatar_initials }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1.5 flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-ink">{{ coach.name }}</span>
                                        <span class="shrink-0 text-xs tabular-nums text-ink-3">{{ coach.users_count }}</span>
                                    </div>
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-surface-2">
                                        <div class="h-full rounded-full transition-all"
                                            :class="coachBg[coach.avatar_color] ?? 'bg-ink-3'"
                                            :style="{ width: share(coach.users_count, maxCoach) + '%' }" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="py-10 text-center text-sm text-ink-3">Keine Coaches vorhanden</p>
                    </AppCard>
                </div>
            </section>

            <!-- ── Bestand ───────────────────────────────────────── -->
            <section>
                <SectionHeader title="Bestand" />

                <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatTile label="Onboarded" :value="stats.onboarded_users" />
                    <StatTile label="Admins"    :value="stats.admin_users" />
                    <StatTile label="Strava"    :value="stats.strava_users" hint="verbunden" />
                    <StatTile label="Events"    :value="stats.total_events" />
                    <StatTile label="Wellbeing" :value="stats.total_wellbeing" hint="Einträge"
                        class="col-span-2 lg:col-span-1" />
                </div>
            </section>

            <!-- ── Zuletzt registriert ───────────────────────────── -->
            <section>
                <SectionHeader title="Zuletzt registriert">
                    <template #action>
                        <Link :href="route('admin.users.index')"
                            class="text-sm font-medium text-accent-ink hover:underline">
                            Alle Nutzer
                        </Link>
                    </template>
                </SectionHeader>

                <AppCard flush>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-line">
                                <tr v-for="u in recentUsers" :key="u.id" class="transition-colors hover:bg-surface-2/50">
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-ink-3 text-xs font-bold text-white">
                                                {{ u.name.charAt(0).toUpperCase() }}
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate font-medium text-ink">{{ u.name }}</p>
                                                <p class="truncate text-xs text-ink-3">{{ u.email }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right">
                                        <span v-if="u.is_admin"
                                            class="mr-2 inline-flex items-center rounded-full bg-danger-soft px-2 py-0.5 text-xs font-medium text-danger-ink">
                                            Admin
                                        </span>
                                        <span class="text-xs text-ink-3">{{ formatDate(u.created_at) }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right">
                                        <Link :href="route('admin.users.show', u.id)"
                                            class="text-xs font-medium text-accent-ink hover:underline">
                                            Ansehen
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </AppCard>
            </section>

        </div>
    </AdminLayout>
</template>
