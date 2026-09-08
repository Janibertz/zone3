<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    workouts: Array,
    showAll:  Boolean,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const toUnpublish = ref(null);
const reason      = ref('');
const busy        = ref(false);

function confirmUnpublish() {
    if (!toUnpublish.value) return;
    busy.value = true;

    router.post(route('admin.workouts.unpublish', toUnpublish.value.id), { reason: reason.value }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; toUnpublish.value = null; reason.value = ''; },
    });
}

const typeLabels = {
    easy_run: 'Grundlage', tempo_run: 'Tempo', interval: 'Intervalle',
    long_run: 'Langer Lauf', progressive_run: 'Progressiv',
};

const shared = computed(() => props.workouts.filter((w) => w.is_public));
const pulled = computed(() => props.workouts.filter((w) => !w.is_public));

function when(value) {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
}
</script>

<template>
    <Head title="Workouts" />

    <AdminLayout>
        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-6">

            <div v-if="flash.success" class="px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                {{ flash.success }}
            </div>

            <!-- ── Freigegeben ───────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Freigegebene Workouts ({{ shared.length }})</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Von Athleten gebaut und geteilt — sie stehen allen als Vorlage zur Verfügung.
                        Die Paces rechnet jeder Läufer aus seiner eigenen Schwellenpace; geteilt wird
                        die Struktur, nicht die Geschwindigkeit. Ändern kann sie nur der Ersteller.
                    </p>
                </div>

                <p v-if="!shared.length" class="px-6 py-8 text-sm text-ink-3">
                    Noch hat niemand ein Workout freigegeben.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Workout</th>
                                <th class="px-3 py-2 text-left font-medium">Art</th>
                                <th class="px-3 py-2 text-left font-medium">Erstellt von</th>
                                <th class="px-3 py-2 text-left font-medium">Seit</th>
                                <th class="px-3 py-2 text-right font-medium">Genutzt</th>
                                <th class="px-6 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="w in shared" :key="w.id">
                                <td class="px-6 py-3">
                                    <p class="font-medium text-ink">{{ w.name }}</p>
                                    <p v-if="w.description" class="text-xs text-ink-3 max-w-md truncate">{{ w.description }}</p>
                                </td>
                                <td class="px-3 py-3 text-ink-3 whitespace-nowrap">{{ typeLabels[w.type] ?? w.type }}</td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <Link :href="route('admin.users.show', w.author_id)" class="text-ink hover:text-accent-ink">
                                        {{ w.author }}
                                    </Link>
                                </td>
                                <td class="px-3 py-3 text-ink-3 whitespace-nowrap">{{ when(w.published_at) }}</td>
                                <td class="px-3 py-3 text-right text-ink-3 tabular-nums">{{ w.times_used }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">
                                    <button
                                        class="px-2 py-1 rounded-field text-xs text-ink-3 hover:text-danger-ink"
                                        @click="toUnpublish = w">
                                        Freigabe zurücknehmen
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Zurückgezogen ─────────────────────────────────────── -->
            <div v-if="pulled.length" class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Zurückgezogen ({{ pulled.length }})</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Nicht mehr in der Auswahl der anderen — beim Ersteller bleiben sie bestehen.
                    </p>
                </div>
                <div class="divide-y divide-line">
                    <div v-for="w in pulled" :key="w.id" class="px-6 py-3 flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <span class="text-sm text-ink">{{ w.name }}</span>
                            <span class="ml-2 text-xs text-ink-3">{{ w.author }}</span>
                        </div>
                        <span v-if="w.reason" class="text-xs text-warn-ink">{{ w.reason }}</span>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmSheet
            :show="!!toUnpublish"
            title="Freigabe zurücknehmen"
            :message="toUnpublish
                ? `„${toUnpublish.name}“ von ${toUnpublish.author} verschwindet aus der Auswahl der anderen Athleten. Beim Ersteller bleibt es bestehen.`
                : ''"
            confirm-label="Zurücknehmen"
            :loading="busy"
            @confirm="confirmUnpublish"
            @close="toUnpublish = null; reason = ''">
            <template #default>
                <input
                    v-model="reason"
                    type="text"
                    maxlength="200"
                    placeholder="Grund (sieht der Ersteller)"
                    class="mt-3 w-full rounded-field border-line bg-canvas text-sm text-ink placeholder:text-ink-3" />
            </template>
        </ConfirmSheet>
    </AdminLayout>
</template>
