<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
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
            <h1 class="text-2xl font-bold tracking-tight text-ink lg:text-3xl">Nutzerverwaltung</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-6">

            <!-- Filter bar -->
            <div class="flex flex-col sm:flex-row gap-3">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Name oder E-Mail suchen…"
                    class="flex-1 px-4 py-2.5 rounded-field border border-line bg-surface text-ink text-sm focus:border-danger outline-none transition-colors"
                />
                <select
                    v-model="filter"
                    @change="applyFilters"
                    class="px-4 py-2.5 rounded-field border border-line bg-surface text-ink text-sm focus:border-danger outline-none transition-colors"
                >
                    <option v-for="opt in filterOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="bg-surface rounded-card overflow-hidden shadow-card">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-line">
                            <tr class="text-left">
                                <th class="px-6 py-3 text-xs font-semibold text-ink-3 uppercase tracking-wider">Nutzer</th>
                                <th class="px-4 py-3 text-xs font-semibold text-ink-3 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-xs font-semibold text-ink-3 uppercase tracking-wider text-right">Aktivitäten</th>
                                <th class="px-4 py-3 text-xs font-semibold text-ink-3 uppercase tracking-wider text-right">Ziele</th>
                                <th class="px-4 py-3 text-xs font-semibold text-ink-3 uppercase tracking-wider text-right">Registriert</th>
                                <th class="px-4 py-3 text-xs font-semibold text-ink-3 uppercase tracking-wider text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="u in users.data" :key="u.id" class="hover:bg-surface-2/50 transition-colors">
                                <!-- User -->
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-9 w-9 shrink-0 rounded-full flex items-center justify-center text-xs font-bold text-white shadow-card"
                                            :class="u.is_admin ? 'bg-danger' : 'bg-ink-3'"
                                        >
                                            {{ u.name.charAt(0).toUpperCase() }}
                                        </div>
                                        <div>
                                            <p class="font-medium text-ink">{{ u.name }}</p>
                                            <p class="text-xs text-ink-3">{{ u.email }}</p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Badges -->
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-if="u.is_admin"    class="badge bg-danger-soft text-danger-ink">Admin</span>
                                        <span v-if="!u.is_active"  class="badge bg-surface-2 text-ink-3">Inaktiv</span>
                                        <span v-if="u.email_verified_at"          class="badge bg-success-soft text-success-ink">✓ Mail</span>
                                        <span v-if="u.onboarding_completed_at"    class="badge bg-accent-soft text-accent-ink">Onboarded</span>
                                        <span v-if="u.strava_account"             class="badge bg-warn-soft text-warn-ink">Strava</span>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right text-ink-2">{{ u.activities_count }}</td>
                                <td class="px-4 py-3 text-right text-ink-2">{{ u.events_count }}</td>
                                <td class="px-4 py-3 text-right text-xs text-ink-3 whitespace-nowrap">{{ formatDate(u.created_at) }}</td>

                                <!-- Actions -->
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link
                                            :href="route('admin.users.show', u.id)"
                                            class="px-3 py-1.5 text-xs rounded-lg bg-surface-2 text-ink-2 hover:bg-surface-3 transition-colors"
                                        >
                                            Ansehen
                                        </Link>
                                        <button
                                            @click="toggleAdmin(u)"
                                            class="px-3 py-1.5 text-xs rounded-lg transition-colors"
                                            :class="u.is_admin
                                                ? 'bg-danger-soft text-danger-ink hover:opacity-90'
                                                : 'bg-surface-2 text-ink-2 hover:bg-surface-3'"
                                        >
                                            {{ u.is_admin ? 'Admin entziehen' : 'Admin machen' }}
                                        </button>
                                        <button
                                            @click="toggleActive(u)"
                                            class="px-3 py-1.5 text-xs rounded-lg transition-colors"
                                            :class="u.is_active
                                                ? 'bg-warn-soft text-warn-ink hover:opacity-90'
                                                : 'bg-success-soft text-success-ink hover:opacity-90'"
                                        >
                                            {{ u.is_active ? 'Deaktivieren' : 'Aktivieren' }}
                                        </button>
                                        <button
                                            @click="confirmDelete(u)"
                                            class="px-3 py-1.5 text-xs rounded-lg bg-danger-soft text-danger-ink hover:opacity-90 transition-colors"
                                        >
                                            Löschen
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!users.data.length">
                                <td colspan="6" class="px-6 py-12 text-center text-ink-3">
                                    Keine Nutzer gefunden.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="users.links?.length > 3" class="px-6 py-4 border-t border-line flex flex-wrap gap-1">
                    <Link
                        v-for="link in users.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="px-3 py-1.5 text-xs rounded-lg transition-colors"
                        :class="link.active
                            ? 'bg-danger text-white'
                            : link.url
                                ? 'bg-surface-2 text-ink-2 hover:bg-surface-3'
                                : 'bg-surface-2 text-ink-3 cursor-not-allowed'"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <!-- Delete confirmation -->
        <ConfirmSheet
            :show="!!deleteTarget"
            title="Nutzer löschen"
            :message="`Soll ${deleteTarget?.name} wirklich unwiderruflich gelöscht werden? Alle Daten dieses Nutzers werden entfernt.`"
            confirm-label="Ja, löschen"
            @confirm="deleteUser"
            @close="deleteTarget = null"
        />
    </AdminLayout>
</template>

<style scoped>
.badge {
    @apply inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium;
}
</style>
