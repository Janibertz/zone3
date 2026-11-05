<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * Kraft-Katalog: alle Übungen, die das Modell für Krafteinheiten wählen darf.
 * Bilder kommen aus wger.de (Suche im Sheet), gespeichert in unserer
 * Datenbank. Gelöscht wird nie — „Aus" nimmt eine Übung nur aus der Auswahl.
 */
const props = defineProps({
    exercises: Array,
    groups:    Object,
    equipment: Object,
    levels:    Object,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const query = ref('');
const group = ref('');

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    return props.exercises.filter((e) =>
        (!group.value || e.group === group.value)
        && (!q || e.name.toLowerCase().includes(q) || e.key.includes(q)));
});

const byGroup = computed(() => Object.entries(props.groups)
    .map(([key, label]) => ({ key, label, items: filtered.value.filter((e) => e.group === key) }))
    .filter((g) => g.items.length));

const stats = computed(() => ({
    total:  props.exercises.length,
    active: props.exercises.filter((e) => e.active).length,
    images: props.exercises.filter((e) => e.image_url).length,
}));

// ── Bearbeiten / Anlegen ───────────────────────────────────────────────
const editing = ref(null); // null = zu, {} = neu, Übung = bearbeiten
const busy    = ref(false);
const errors  = ref({});
const form    = reactive({});

function blank() {
    return {
        name: '', instructions: '', group: 'beine', equipment: ['bodyweight'], level: 'beginner',
        default_sets: 3, default_reps: '10', default_load: '', wger_id: null,
    };
}

function open(exercise = null) {
    Object.assign(form, blank(), exercise ? {
        name: exercise.name, instructions: exercise.instructions ?? '', group: exercise.group,
        equipment: [...exercise.equipment], level: exercise.level, default_sets: exercise.default_sets,
        default_reps: exercise.default_reps, default_load: exercise.default_load ?? '', wger_id: exercise.wger_id,
    } : {});
    errors.value = {};
    wgerQuery.value = '';
    wgerResults.value = [];
    wgerPicked.value = null;
    editing.value = exercise ?? {};
}

const isNew = computed(() => editing.value && !editing.value.id);

function toggleEquipment(key) {
    form.equipment = form.equipment.includes(key)
        ? form.equipment.filter((k) => k !== key)
        : [...form.equipment, key];
}

function save() {
    busy.value = true;
    const payload = { ...form, default_load: form.default_load || null };
    const opts = {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
        onError: (e) => { errors.value = e; },
        onFinish: () => { busy.value = false; },
    };
    isNew.value
        ? router.post(route('admin.strength-exercises.store'), payload, opts)
        : router.patch(route('admin.strength-exercises.update', editing.value.id), payload, opts);
}

function post(name, method = 'post') {
    busy.value = true;
    router[method](route(name, editing.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
        onFinish: () => { busy.value = false; },
    });
}

// ── wger-Suche ─────────────────────────────────────────────────────────
const wgerQuery   = ref('');
const wgerResults = ref([]);
const wgerBusy    = ref(false);
const wgerError   = ref('');
const wgerPicked  = ref(null);

async function searchWger() {
    if (wgerQuery.value.trim().length < 2) return;
    wgerBusy.value = true;
    wgerError.value = '';
    try {
        const { data } = await window.axios.get(route('admin.strength-exercises.wger'), { params: { q: wgerQuery.value } });
        wgerResults.value = data.results ?? [];
        if (!wgerResults.value.length) wgerError.value = 'Keine Treffer. wger kennt die Namen meist auf Englisch.';
    } catch (e) {
        wgerResults.value = [];
        wgerError.value = e.response?.data?.error ?? 'wger ist gerade nicht erreichbar.';
    } finally {
        wgerBusy.value = false;
    }
}

function pickWger(r) {
    form.wger_id = r.id;
    wgerPicked.value = r;
    if (!form.name) form.name = r.name;
    wgerResults.value = [];
}

const imageChanged = computed(() => editing.value?.id && form.wger_id !== editing.value.wger_id);

const fieldClass = 'w-full rounded-field border-line bg-canvas text-[16px] text-ink placeholder:text-ink-3';
</script>

<template>
    <Head title="Kraftübungen" />

    <AdminLayout>
        <template #header>
            <PageHeader title="Kraftübungen">
                <template #action>
                    <AppButton variant="plain" @click="open()">Neue Übung</AppButton>
                </template>
            </PageHeader>
        </template>

        <div class="px-4 py-4 lg:px-8 lg:py-6 space-y-5">
            <div v-if="flash.success" class="px-4 py-3 bg-success-soft rounded-field text-sm text-success-ink">{{ flash.success }}</div>
            <div v-if="flash.error" class="px-4 py-3 bg-danger-soft rounded-field text-sm text-danger-ink">{{ flash.error }}</div>

            <p class="text-[15px] text-ink-3 max-w-2xl">
                Aus diesem Katalog wählt der Coach die Übungen für Kraft-, Rumpf- und Mobilitätseinheiten —
                passend zum Equipment und zur Erfahrung des Athleten. {{ stats.active }} von {{ stats.total }} aktiv,
                {{ stats.images }} mit Bild von wger.de.
            </p>

            <div class="flex flex-wrap gap-2">
                <input v-model="query" type="search" placeholder="Suchen" :class="fieldClass" class="max-w-xs" />
                <select v-model="group" :class="fieldClass" class="max-w-[12rem]">
                    <option value="">Alle Gruppen</option>
                    <option v-for="(label, key) in groups" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>

            <section v-for="g in byGroup" :key="g.key" class="space-y-2">
                <h2 class="text-[22px] font-bold text-ink">{{ g.label }}</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                    <button
                        v-for="e in g.items" :key="e.id" type="button"
                        class="text-left bg-surface rounded-card overflow-hidden transition active:scale-[0.98]"
                        :class="e.active ? '' : 'opacity-50'"
                        @click="open(e)">
                        <div class="aspect-square bg-white flex items-center justify-center">
                            <img v-if="e.image_url" :src="e.image_url" :alt="e.name" loading="lazy" class="h-full w-full object-contain" />
                            <span v-else class="text-[13px] text-ink-3">kein Bild</span>
                        </div>
                        <div class="p-3">
                            <p class="text-[15px] font-semibold text-ink leading-tight">{{ e.name }}</p>
                            <p class="text-[13px] text-ink-3 mt-0.5">
                                {{ levels[e.level] }} · {{ e.default_sets }}×{{ e.default_reps }}
                            </p>
                            <p class="text-[13px] text-ink-3 truncate">{{ e.equipment.map((k) => equipment[k]).join(', ') }}</p>
                            <p v-if="!e.active" class="text-[13px] text-warn-ink mt-0.5">Aus</p>
                        </div>
                    </button>
                </div>
            </section>

            <p v-if="!byGroup.length" class="text-[15px] text-ink-3">Keine Übung gefunden.</p>
        </div>

        <AppSheet :show="!!editing" :title="isNew ? 'Neue Übung' : form.name" tall max-width="lg" @close="editing = null">
            <div v-if="editing" class="space-y-4">
                <div v-if="!isNew && editing.image_url && !imageChanged" class="bg-white rounded-card overflow-hidden">
                    <img :src="editing.image_url" :alt="editing.name" class="mx-auto max-h-64 object-contain" />
                    <p v-if="editing.credit" class="px-3 py-2 text-[13px] text-ink-3">{{ editing.credit }}</p>
                </div>

                <label class="block">
                    <span class="text-[13px] text-ink-3">Name</span>
                    <input v-model="form.name" type="text" maxlength="120" :class="fieldClass" />
                    <span v-if="errors.name" class="text-[13px] text-danger-ink">{{ errors.name }}</span>
                </label>

                <label class="block">
                    <span class="text-[13px] text-ink-3">Ausführung (eigener Text, steht unter dem Bild)</span>
                    <textarea v-model="form.instructions" rows="3" maxlength="1000" :class="fieldClass" />
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-[13px] text-ink-3">Gruppe</span>
                        <select v-model="form.group" :class="fieldClass">
                            <option v-for="(label, key) in groups" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-[13px] text-ink-3">Ab Erfahrung</span>
                        <select v-model="form.level" :class="fieldClass">
                            <option v-for="(label, key) in levels" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>
                </div>

                <div>
                    <span class="text-[13px] text-ink-3">Equipment (eines davon genügt)</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <button
                            v-for="(label, key) in equipment" :key="key" type="button"
                            class="px-3 h-9 rounded-full text-[15px] transition"
                            :class="form.equipment.includes(key) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-2'"
                            @click="toggleEquipment(key)">
                            {{ label }}
                        </button>
                    </div>
                    <span v-if="errors.equipment" class="text-[13px] text-danger-ink">{{ errors.equipment }}</span>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <label class="block">
                        <span class="text-[13px] text-ink-3">Sätze</span>
                        <input v-model.number="form.default_sets" type="number" min="1" max="10" :class="fieldClass" />
                    </label>
                    <label class="block">
                        <span class="text-[13px] text-ink-3">Wiederholungen</span>
                        <input v-model="form.default_reps" type="text" maxlength="30" placeholder="10 / 30 s" :class="fieldClass" />
                    </label>
                    <label class="block">
                        <span class="text-[13px] text-ink-3">Last</span>
                        <input v-model="form.default_load" type="text" maxlength="60" placeholder="optional" :class="fieldClass" />
                    </label>
                </div>
                <p class="text-[13px] text-ink-3 -mt-2">Vorgabe für das Grundprogramm. Im Plan legt der Coach Sätze und Wiederholungen selbst fest.</p>

                <!-- wger -->
                <div class="rounded-card bg-surface-2 p-3 space-y-2">
                    <p class="text-[15px] font-semibold text-ink">Bild von wger.de</p>
                    <p class="text-[13px] text-ink-3">
                        <template v-if="form.wger_id">
                            Verknüpft mit wger #{{ form.wger_id }}<template v-if="wgerPicked"> („{{ wgerPicked.name }}“)</template>.
                            <button type="button" class="text-accent" @click="form.wger_id = null; wgerPicked = null">Lösen</button>
                        </template>
                        <template v-else>Nicht verknüpft — suche die passende Übung (meist englische Namen).</template>
                    </p>
                    <form class="flex gap-2" @submit.prevent="searchWger">
                        <input v-model="wgerQuery" type="search" placeholder="z. B. lunge, plank" :class="fieldClass" />
                        <AppButton type="submit" variant="secondary" size="sm" :loading="wgerBusy">Suchen</AppButton>
                    </form>
                    <p v-if="wgerBusy" class="text-[13px] text-ink-3">Beim ersten Mal lädt Zone3 den ganzen wger-Katalog, das dauert einige Sekunden.</p>
                    <p v-if="wgerError" class="text-[13px] text-warn-ink">{{ wgerError }}</p>
                    <div v-if="wgerResults.length" class="max-h-72 overflow-y-auto divide-y divide-line bg-surface rounded-field">
                        <button
                            v-for="r in wgerResults" :key="r.id" type="button"
                            class="flex w-full items-center gap-3 p-2 text-left active:bg-surface-2"
                            @click="pickWger(r)">
                            <img v-if="r.image" :src="r.image.thumb" alt="" loading="lazy" class="h-12 w-12 shrink-0 rounded object-contain bg-white" />
                            <span v-else class="h-12 w-12 shrink-0 rounded bg-surface-2" />
                            <span class="min-w-0">
                                <span class="block text-[15px] text-ink truncate">{{ r.name }}</span>
                                <span class="block text-[13px] text-ink-3 truncate">
                                    #{{ r.id }} · {{ r.category }}<template v-if="r.image"> · {{ r.image.license }}<template v-if="r.image.ai"> · KI-Bild</template></template><template v-else> · ohne Bild</template>
                                </span>
                            </span>
                        </button>
                    </div>
                    <p v-if="isNew && form.wger_id" class="text-[13px] text-ink-3">Das Bild wird beim Anlegen übernommen.</p>
                    <p v-if="imageChanged" class="text-[13px] text-ink-3">Nach dem Sichern „Bild holen“ antippen.</p>
                </div>

                <div v-if="!isNew" class="flex flex-wrap gap-2 justify-center border-t border-line pt-3">
                    <AppButton v-if="editing?.wger_id && !imageChanged" variant="ghost" size="sm" :disabled="busy" @click="post('admin.strength-exercises.image')">
                        {{ editing.image_url ? 'Bild neu holen' : 'Bild holen' }}
                    </AppButton>
                    <AppButton v-if="editing?.image_url" variant="ghost" size="sm" :disabled="busy" @click="post('admin.strength-exercises.image.destroy', 'delete')">
                        Bild entfernen
                    </AppButton>
                    <AppButton variant="ghost" size="sm" :disabled="busy" @click="post('admin.strength-exercises.toggle', 'patch')">
                        {{ editing?.active ? 'Ausschalten' : 'Einschalten' }}
                    </AppButton>
                </div>
            </div>

            <template #footer>
                <AppButton block :loading="busy" @click="save">{{ isNew ? 'Anlegen' : 'Sichern' }}</AppButton>
            </template>
        </AppSheet>
    </AdminLayout>
</template>
