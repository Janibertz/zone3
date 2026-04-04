<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    user: Object,
    activities: Array,
    goals: Array,
    wellbeingEntries: Array,
    activityStats: Object,
});

const showDeleteModal = ref(false);

function toggleAdmin() {
    router.patch(route('admin.users.toggle-admin', props.user.id), {}, { preserveScroll: true });
}

function toggleActive() {
    router.patch(route('admin.users.toggle-active', props.user.id), {}, { preserveScroll: true });
}

function deleteUser() {
    router.delete(route('admin.users.destroy', props.user.id));
}

function formatDate(d, opts) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('de-DE', opts ?? { day: '2-digit', month: '2-digit', year: 'numeric' });
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

        <div class="p-6 space-y-6">

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
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <button @click="toggleAdmin"
                            class="px-3 py-1.5 text-xs rounded-xl border transition-colors"
                            :class="user.is_admin
                                ? 'border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10'
                                : 'border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'"
                        >
                            {{ user.is_admin ? 'Admin entziehen' : 'Admin machen' }}
                        </button>
                        <button @click="toggleActive"
                            class="px-3 py-1.5 text-xs rounded-xl border transition-colors"
                            :class="user.is_active
                                ? 'border-yellow-200 dark:border-yellow-500/30 text-yellow-600 dark:text-yellow-400 hover:bg-yellow-50 dark:hover:bg-yellow-500/10'
                                : 'border-green-200 dark:border-green-500/30 text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-500/10'"
                        >
                            {{ user.is_active ? 'Deaktivieren' : 'Aktivieren' }}
                        </button>
                        <button @click="showDeleteModal = true"
                            class="px-3 py-1.5 text-xs rounded-xl border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
                        >
                            Löschen
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Activity stats ───────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="stat in [
                    { label: 'Aktivitäten',  value: activityStats.total },
                    { label: 'Läufe',        value: activityStats.total_runs },
                    { label: 'Kilometer',    value: activityStats.total_km + ' km' },
                    { label: 'Letzte Aktivität', value: formatDate(activityStats.last_activity) },
                ]" :key="stat.label"
                    class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-xl p-4"
                >
                    <p class="text-xs text-gray-500 dark:text-slate-400">{{ stat.label }}</p>
                    <p class="mt-0.5 text-xl font-bold text-gray-900 dark:text-white">{{ stat.value }}</p>
                </div>
            </div>

            <!-- ── Runner profile ───────────────────────────────── -->
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
                            v-for="(zone, i) in user.runner_profile.pace_zones"
                            :key="i"
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

            <!-- ── Recent activities ────────────────────────────── -->
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

            <!-- ── Goals + Wellbeing ────────────────────────────── -->
            <div class="grid lg:grid-cols-2 gap-6">

                <!-- Goals -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Ziele ({{ goals.length }})</h2>
                    <div v-if="goals.length" class="space-y-3">
                        <div
                            v-for="goal in goals"
                            :key="goal.id"
                            class="flex items-center justify-between p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50"
                        >
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ goal.name }}</p>
                                <p class="text-xs text-gray-400 dark:text-slate-500">{{ goal.target_value }} {{ goal.unit }} · {{ formatDate(goal.end_date) }}</p>
                            </div>
                            <span
                                class="text-xs px-2 py-0.5 rounded-full"
                                :class="goal.active ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' : 'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400'"
                            >
                                {{ goal.active ? 'Aktiv' : 'Inaktiv' }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500">Keine Ziele vorhanden.</p>
                </div>

                <!-- Wellbeing -->
                <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Wellbeing (letzte 14 Einträge)</h2>
                    <div v-if="wellbeingEntries.length" class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-400 dark:text-slate-500">
                                    <th class="pb-2 font-medium">Datum</th>
                                    <th class="pb-2 font-medium text-center">⚡</th>
                                    <th class="pb-2 font-medium text-center">😊</th>
                                    <th class="pb-2 font-medium text-center">💤</th>
                                    <th class="pb-2 font-medium text-center">💪</th>
                                    <th class="pb-2 font-medium text-center">😤</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                                <tr v-for="wb in wellbeingEntries" :key="wb.id">
                                    <td class="py-1.5 text-gray-500 dark:text-slate-400 whitespace-nowrap">{{ formatDate(wb.date) }}</td>
                                    <td class="py-1.5 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.energy_level }}</td>
                                    <td class="py-1.5 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.mood }}</td>
                                    <td class="py-1.5 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.sleep_quality }}</td>
                                    <td class="py-1.5 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.muscle_soreness }}</td>
                                    <td class="py-1.5 text-center font-semibold text-gray-700 dark:text-slate-300">{{ wb.stress_level }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500">Keine Wellbeing-Einträge vorhanden.</p>
                </div>
            </div>

        </div>

        <!-- Delete modal -->
        <Modal :show="showDeleteModal" @close="showDeleteModal = false">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Nutzer löschen</h3>
                <p class="text-gray-600 dark:text-slate-400">
                    Soll <strong class="text-gray-900 dark:text-white">{{ user.name }}</strong> wirklich unwiderruflich gelöscht werden? Alle Aktivitäten, Ziele und Daten dieses Nutzers werden entfernt.
                </p>
                <div class="flex justify-end gap-3">
                    <button @click="showDeleteModal = false" class="px-4 py-2 text-sm rounded-xl border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
                        Abbrechen
                    </button>
                    <button @click="deleteUser" class="px-4 py-2 text-sm rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition-colors">
                        Ja, löschen
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
