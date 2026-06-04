<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    users: Object,
    filters: Object,
});

const search    = ref(props.filters?.search ?? '');
const filter    = ref(props.filters?.filter ?? '');
const deleteTarget = ref(null);

let searchTimeout = null;
watch(search, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 400);
});

function applyFilters() {
    router.get(route('admin.users.index'), { search: search.value, filter: filter.value }, {
        preserveState: true,
        replace: true,
    });
}

function toggleAdmin(user) {
    router.patch(route('admin.users.toggle-admin', user.id), {}, { preserveScroll: true });
}

function toggleActive(user) {
    router.patch(route('admin.users.toggle-active', user.id), {}, { preserveScroll: true });
}

function confirmDelete(user) {
    deleteTarget.value = user;
}

function deleteUser() {
    if (!deleteTarget.value) return;
    router.delete(route('admin.users.destroy', deleteTarget.value.id), {
        onFinish: () => { deleteTarget.value = null; },
    });
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatPace(speedMps) {
    if (!speedMps) return '—';
    const secPerKm = 1000 / speedMps;
    const m = Math.floor(secPerKm / 60);
    const s = Math.round(secPerKm % 60);
    return `${m}:${s.toString().padStart(2, '0')} /km`;
}

const filterOptions = [
    { value: '',          label: 'Alle' },
    { value: 'admin',     label: 'Admins' },
    { value: 'inactive',  label: 'Inaktiv' },
    { value: 'verified',  label: 'Verifiziert' },
    { value: 'onboarded', label: 'Onboarded' },
    { value: 'strava',    label: 'Strava' },
];
</script>

<template>
    <Head title="Admin – Nutzer" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Nutzerverwaltung</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-6">

            <!-- Filter bar -->
            <div class="flex flex-col sm:flex-row gap-3">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Name oder E-Mail suchen…"
                    class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:border-red-400 dark:focus:border-red-500 outline-none transition-colors"
                />
                <select
                    v-model="filter"
                    @change="applyFilters"
                    class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:border-red-400 outline-none transition-colors"
                >
                    <option v-for="opt in filterOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 dark:border-slate-800">
                            <tr class="text-left">
                                <th class="px-6 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">Nutzer</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider text-right">Aktivitäten</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider text-right">Ziele</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider text-right">Registriert</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                            <tr v-for="u in users.data" :key="u.id" class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                <!-- User -->
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-9 w-9 shrink-0 rounded-full flex items-center justify-center text-xs font-bold text-white shadow-sm"
                                            :class="u.is_admin ? 'bg-gradient-to-br from-red-400 to-red-600' : 'bg-gradient-to-br from-gray-300 to-gray-400 dark:from-slate-600 dark:to-slate-700'"
                                        >
                                            {{ u.name.charAt(0).toUpperCase() }}
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-white">{{ u.name }}</p>
                                            <p class="text-xs text-gray-400 dark:text-slate-500">{{ u.email }}</p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Badges -->
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-if="u.is_admin"    class="badge bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400">Admin</span>
                                        <span v-if="!u.is_active"  class="badge bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400">Inaktiv</span>
                                        <span v-if="u.email_verified_at"          class="badge bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400">✓ Mail</span>
                                        <span v-if="u.onboarding_completed_at"    class="badge bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-400">Onboarded</span>
                                        <span v-if="u.strava_account"             class="badge bg-orange-100 dark:bg-orange-500/20 text-orange-700 dark:text-orange-400">Strava</span>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right text-gray-600 dark:text-slate-300">{{ u.activities_count }}</td>
                                <td class="px-4 py-3 text-right text-gray-600 dark:text-slate-300">{{ u.events_count }}</td>
                                <td class="px-4 py-3 text-right text-xs text-gray-400 dark:text-slate-500 whitespace-nowrap">{{ formatDate(u.created_at) }}</td>

                                <!-- Actions -->
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link
                                            :href="route('admin.users.show', u.id)"
                                            class="px-3 py-1.5 text-xs rounded-lg bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors"
                                        >
                                            Ansehen
                                        </Link>
                                        <button
                                            @click="toggleAdmin(u)"
                                            class="px-3 py-1.5 text-xs rounded-lg transition-colors"
                                            :class="u.is_admin
                                                ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-500/30'
                                                : 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400 hover:bg-gray-200 dark:hover:bg-slate-700'"
                                        >
                                            {{ u.is_admin ? 'Admin entziehen' : 'Admin machen' }}
                                        </button>
                                        <button
                                            @click="toggleActive(u)"
                                            class="px-3 py-1.5 text-xs rounded-lg transition-colors"
                                            :class="u.is_active
                                                ? 'bg-yellow-100 dark:bg-yellow-500/20 text-yellow-700 dark:text-yellow-400 hover:bg-yellow-200 dark:hover:bg-yellow-500/30'
                                                : 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-500/30'"
                                        >
                                            {{ u.is_active ? 'Deaktivieren' : 'Aktivieren' }}
                                        </button>
                                        <button
                                            @click="confirmDelete(u)"
                                            class="px-3 py-1.5 text-xs rounded-lg bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-500/30 transition-colors"
                                        >
                                            Löschen
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!users.data.length">
                                <td colspan="6" class="px-6 py-12 text-center text-gray-400 dark:text-slate-500">
                                    Keine Nutzer gefunden.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="users.links?.length > 3" class="px-6 py-4 border-t border-gray-100 dark:border-slate-800 flex flex-wrap gap-1">
                    <Link
                        v-for="link in users.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="px-3 py-1.5 text-xs rounded-lg transition-colors"
                        :class="link.active
                            ? 'bg-red-600 text-white'
                            : link.url
                                ? 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700'
                                : 'bg-gray-50 dark:bg-slate-900 text-gray-300 dark:text-slate-600 cursor-not-allowed'"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <!-- Delete confirmation modal -->
        <Modal :show="!!deleteTarget" @close="deleteTarget = null">
            <div class="p-4 sm:p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Nutzer löschen</h3>
                <p class="text-gray-600 dark:text-slate-400">
                    Soll <strong class="text-gray-900 dark:text-white">{{ deleteTarget?.name }}</strong> wirklich unwiderruflich gelöscht werden? Alle Daten dieses Nutzers werden entfernt.
                </p>
                <div class="flex justify-end gap-3">
                    <button @click="deleteTarget = null" class="px-4 py-2 text-sm rounded-xl border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
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

<style scoped>
.badge {
    @apply inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium;
}
</style>
