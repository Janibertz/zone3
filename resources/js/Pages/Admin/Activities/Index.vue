<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    activities: Object,
    filters:    Object,
    users:      Array,
    types:      Array,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const search = ref(props.filters?.search ?? '');
const user   = ref(props.filters?.user ?? '');
const type   = ref(props.filters?.type ?? '');

const toDelete = ref(null);
const deleting = ref(false);

let timer = null;
watch([search, user, type], () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 250);
});

function apply() {
    router.get(route('admin.activities.index'), {
        search: search.value || undefined,
        user:   user.value   || undefined,
        type:   type.value   || undefined,
    }, { preserveState: true, replace: true });
}

function confirmDelete() {
    if (!toDelete.value) return;
    deleting.value = true;

    router.delete(route('admin.activities.destroy', toDelete.value.id), {
        preserveScroll: true,
        onFinish: () => { deleting.value = false; toDelete.value = null; },
    });
}

/**
 * Der Satz im Bestätigungsdialog hängt davon ab, was mit dranhängt.
 * Eine Aktivität ohne Einheit ist reines Aufräumen; hängt eine dran,
 * ändert sich der Trainingsplan mit.
 */
const deleteMessage = computed(() => {
    const a = toDelete.value;
    if (!a) return '';

    const base = `„${a.name}" von ${a.user} wird gelöscht.`;

    return a.sessions > 0
        ? `${base} An der Aktivität hängt ${a.sessions} Trainingseinheit — eine geplante wird auf „geplant" zurückgesetzt, eine ungeplante entfernt. Die Aktivität kommt beim nächsten Strava-Abgleich nicht zurück.`
        : `${base} Die Aktivität kommt beim nächsten Strava-Abgleich nicht zurück.`;
});

function when(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
}
</script>

<template>
    <Head title="Aktivitäten" />

    <AdminLayout>
        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-6">

            <div v-if="flash.success" class="px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="px-4 py-3 bg-danger-soft border border-danger/25 rounded-field text-sm text-danger-ink">
                {{ flash.error }}
            </div>

            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Aktivitäten ({{ activities.total }})</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Über alle Athleten. Gelöscht wird über den Löschdienst — die abgehakte Einheit,
                        die Bestzeiten und die Rennanalyse hängen daran, und ein Grabstein verhindert,
                        dass der nächste Strava-Abgleich sie zurückholt.
                    </p>
                </div>

                <!-- Filter -->
                <div class="px-6 py-3 border-b border-line grid gap-2 sm:grid-cols-3">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Name oder Strava-ID"
                        class="rounded-field border-line bg-canvas text-sm text-ink placeholder:text-ink-3" />

                    <select v-model="user" class="rounded-field border-line bg-canvas text-sm text-ink">
                        <option value="">Alle Athleten</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>

                    <select v-model="type" class="rounded-field border-line bg-canvas text-sm text-ink">
                        <option value="">Alle Sportarten</option>
                        <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>

                <p v-if="!activities.data.length" class="px-6 py-8 text-sm text-ink-3">
                    Keine Aktivität gefunden.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Datum</th>
                                <th class="px-3 py-2 text-left font-medium">Athlet</th>
                                <th class="px-3 py-2 text-left font-medium">Aktivität</th>
                                <th class="px-3 py-2 text-left font-medium">Sportart</th>
                                <th class="px-3 py-2 text-right font-medium">km</th>
                                <th class="px-3 py-2 text-right font-medium">min</th>
                                <th class="px-3 py-2 text-right font-medium">Einheit</th>
                                <th class="px-6 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="a in activities.data" :key="a.id">
                                <td class="px-6 py-3 text-ink-3 whitespace-nowrap">{{ when(a.start_date) }}</td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <Link :href="route('admin.users.show', a.user_id)" class="text-ink hover:text-accent-ink">
                                        {{ a.user }}
                                    </Link>
                                </td>
                                <td class="px-3 py-3 text-ink max-w-xs truncate" :title="a.name">{{ a.name }}</td>
                                <td class="px-3 py-3 text-ink-3 whitespace-nowrap">{{ a.type }}</td>
                                <td class="px-3 py-3 text-right text-ink-3 tabular-nums">{{ a.distance_km ?? '—' }}</td>
                                <td class="px-3 py-3 text-right text-ink-3 tabular-nums">{{ a.duration_min ?? '—' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    <span v-if="a.sessions" class="px-2 py-0.5 rounded-full text-xs bg-info-soft text-info-ink">
                                        {{ a.sessions }}
                                    </span>
                                    <span v-else class="text-ink-3">—</span>
                                </td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">
                                    <button
                                        class="px-2 py-1 rounded-field text-xs text-ink-3 hover:text-danger-ink"
                                        @click="toDelete = a">
                                        Löschen
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Seiten -->
                <div v-if="activities?.links?.length > 3" class="border-t border-line px-4 py-3 flex items-center justify-between">
                    <p class="text-xs text-ink-3">{{ activities.from }}–{{ activities.to }} von {{ activities.total }}</p>
                    <div class="flex gap-1">
                        <template v-for="link in activities.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                                :class="link.active ? 'bg-danger text-white' : 'text-ink-2 hover:bg-surface-2'"
                                v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmSheet
            :show="!!toDelete"
            title="Aktivität löschen"
            :message="deleteMessage"
            confirm-label="Löschen"
            :loading="deleting"
            @confirm="confirmDelete"
            @close="toDelete = null" />
    </AdminLayout>
</template>
