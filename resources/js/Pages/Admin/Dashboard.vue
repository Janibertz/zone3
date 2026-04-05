<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    stats: Object,
    registrationsPerMonth: Array,
    activitiesPerMonth: Array,
    recentUsers: Array,
});

function formatDate(dateString) {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function monthLabel(ym) {
    const [year, month] = ym.split('-');
    return new Date(year, month - 1).toLocaleDateString('de-DE', { month: 'short', year: '2-digit' });
}

function barHeight(count, max) {
    if (!max) return 0;
    return Math.max(4, Math.round((count / max) * 100));
}

const maxReg = Math.max(...(props.registrationsPerMonth?.map(r => r.count) ?? [1]), 1);
const maxAct = Math.max(...(props.activitiesPerMonth?.map(a => a.count) ?? [1]), 1);
</script>

<template>
    <Head title="Admin – Übersicht" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Übersicht</h1>
        </template>

        <div class="p-6 space-y-8">

            <!-- ── Stat cards ──────────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="card in [
                    { label: 'Nutzer gesamt',    value: stats.total_users,      color: 'indigo' },
                    { label: 'Verifiziert',       value: stats.verified_users,   color: 'green'  },
                    { label: 'Aktivitäten',       value: stats.total_activities, color: 'blue'   },
                    { label: 'Geplante Events',   value: stats.upcoming_events,  color: 'orange' },
                ]" :key="card.label"
                    class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5"
                >
                    <p class="text-sm text-gray-500 dark:text-slate-400">{{ card.label }}</p>
                    <p class="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{{ card.value }}</p>
                </div>
            </div>

            <!-- ── Secondary stats ────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <div v-for="card in [
                    { label: 'Onboarded',   value: stats.onboarded_users },
                    { label: 'Admins',      value: stats.admin_users     },
                    { label: 'Strava-Verbunden', value: stats.strava_users },
                    { label: 'Events gesamt', value: stats.total_events   },
                    { label: 'Wellbeing-Einträge', value: stats.total_wellbeing },
                ]" :key="card.label"
                    class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-xl p-4"
                >
                    <p class="text-xs text-gray-500 dark:text-slate-400">{{ card.label }}</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900 dark:text-white">{{ card.value }}</p>
                </div>
            </div>

            <!-- ── Charts row ──────────────────────────────────── -->
            <div class="grid lg:grid-cols-2 gap-6">

                <!-- Registrations chart -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Neue Registrierungen (letzte 6 Monate)</h2>
                    <div v-if="registrationsPerMonth.length" class="flex items-end gap-2 h-32">
                        <div
                            v-for="item in registrationsPerMonth"
                            :key="item.month"
                            class="flex-1 flex flex-col items-center gap-1"
                        >
                            <span class="text-xs text-gray-500 dark:text-slate-400">{{ item.count }}</span>
                            <div
                                class="w-full rounded-t-md bg-indigo-400 dark:bg-indigo-500 transition-all"
                                :style="{ height: barHeight(item.count, maxReg) + 'px' }"
                            ></div>
                            <span class="text-[10px] text-gray-400 dark:text-slate-500">{{ monthLabel(item.month) }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">Keine Daten vorhanden</p>
                </div>

                <!-- Activities chart -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Aktivitäten (letzte 6 Monate)</h2>
                    <div v-if="activitiesPerMonth.length" class="flex items-end gap-2 h-32">
                        <div
                            v-for="item in activitiesPerMonth"
                            :key="item.month"
                            class="flex-1 flex flex-col items-center gap-1"
                        >
                            <span class="text-xs text-gray-500 dark:text-slate-400">{{ item.count }}</span>
                            <div
                                class="w-full rounded-t-md bg-blue-400 dark:bg-blue-500 transition-all"
                                :style="{ height: barHeight(item.count, maxAct) + 'px' }"
                            ></div>
                            <span class="text-[10px] text-gray-400 dark:text-slate-500">{{ monthLabel(item.month) }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">Keine Daten vorhanden</p>
                </div>
            </div>

            <!-- ── Recent users ────────────────────────────────── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-800">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Zuletzt registriert</h2>
                    <Link :href="route('admin.users.index')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                        Alle Nutzer →
                    </Link>
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                        <tr v-for="u in recentUsers" :key="u.id" class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-gradient-to-br from-gray-300 to-gray-400 dark:from-slate-600 dark:to-slate-700 flex items-center justify-center shrink-0">
                                        <span class="text-xs font-bold text-white">{{ u.name.charAt(0).toUpperCase() }}</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ u.name }}</p>
                                        <p class="text-xs text-gray-400 dark:text-slate-500">{{ u.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <span v-if="u.is_admin" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400 mr-2">Admin</span>
                                <span class="text-xs text-gray-400 dark:text-slate-500">{{ formatDate(u.created_at) }}</span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <Link :href="route('admin.users.show', u.id)" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                    Ansehen →
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </AdminLayout>
</template>
