<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminChips from '@/Components/Admin/AdminChips.vue';

/**
 * Athleten (Mockup 18.11.2026): alle Konten, Filter mit Zahlen, Suche im
 * Browser. Je Zeile: zuletzt aktiv, Plan, Uhr, Weg der Nachrichten, womit
 * Zone3 geöffnet wird.
 */
const props = defineProps({
    athletes: { type: Array, default: () => [] },
    filter:   { type: String, default: 'all' },
    search:   { type: String, default: '' },
});

const filter = ref(props.filter === 'waiting' ? 'waiting' : props.filter);
const q = ref(props.search);
const sort = ref('active');
const searchInput = ref(null);

onMounted(() => {
    if (new URLSearchParams(window.location.search).has('focus')) searchInput.value?.focus();
});

const FILTERS = {
    all:     () => true,
    active:  (a) => a.active,
    quiet:   (a) => a.quiet,
    noplan:  (a) => a.onboarded && !a.has_plan && !a.waiting,
    waiting: (a) => a.waiting,
    admin:   (a) => a.is_admin,
};

const chips = computed(() => [
    { value: 'all', label: 'Alle', count: props.athletes.length },
    { value: 'active', label: 'Aktiv', count: props.athletes.filter(FILTERS.active).length },
    { value: 'quiet', label: 'Still', count: props.athletes.filter(FILTERS.quiet).length, tone: 'warn' },
    { value: 'noplan', label: 'Ohne Plan', count: props.athletes.filter(FILTERS.noplan).length },
    { value: 'waiting', label: 'Warteliste', count: props.athletes.filter(FILTERS.waiting).length },
]);

const SORTS = {
    active: { label: 'zuletzt aktiv', fn: (a, b) => (b.last_at ?? '').localeCompare(a.last_at ?? '') },
    new:    { label: 'neueste', fn: (a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '') },
    name:   { label: 'Name', fn: (a, b) => a.name.localeCompare(b.name, 'de') },
};
function nextSort() {
    const keys = Object.keys(SORTS);
    sort.value = keys[(keys.indexOf(sort.value) + 1) % keys.length];
}

const shown = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return props.athletes
        .filter(FILTERS[filter.value] ?? FILTERS.all)
        .filter((a) => !needle || String(a.id) === needle || [a.name, a.email, a.source].some((v) => (v ?? '').toLowerCase().includes(needle)))
        .sort(SORTS[sort.value].fn);
});

const activeCount = computed(() => props.athletes.filter(FILTERS.active).length);

const toneText = { success: 'font-semibold text-success-ink', warn: 'font-semibold text-warn-ink', plain: 'text-ink-3' };
const chipTone = { plain: 'bg-surface-2 text-ink-2', warn: 'bg-warn-soft text-warn-ink', danger: 'bg-danger-soft text-danger-ink' };
</script>

<template>
    <Head title="Admin – Athleten" />

    <AdminLayout>
        <AdminPage title="Athleten" :eyebrow="`${athletes.length} Konten · ${activeCount} aktiv`">
            <template #action>
                <button type="button" class="h-[34px] px-1 text-[17px] font-semibold text-accent" @click="nextSort">Sortieren</button>
            </template>

            <label class="-mt-1 flex h-[38px] items-center gap-2 rounded-[10px] bg-surface-2 px-2.5 text-ink-3">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input ref="searchInput" v-model="q" type="search" aria-label="Athleten suchen" placeholder="Name, Mail, Quelle"
                    class="flex-1 border-0 bg-transparent p-0 text-[17px] text-ink outline-none placeholder:text-ink-3 focus:ring-0" />
            </label>

            <AdminChips v-model="filter" :options="chips" />

            <div class="-mb-2 px-1 pt-1.5 text-[13px] font-semibold uppercase text-ink-3">{{ SORTS[sort].label }}</div>
            <div class="overflow-hidden rounded-[14px] bg-surface">
                <p v-if="!shown.length" class="px-3.5 py-4 text-[15px] text-ink-3">Niemand passt.</p>
                <Link v-for="(a, i) in shown" :key="a.id" :href="route('admin.users.show', a.id)"
                    class="flex items-center gap-3 px-3.5 py-3 active:bg-surface-2"
                    :class="i < shown.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                    <span class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full text-[17px] font-bold text-white"
                        :style="{ background: a.quiet || (!a.active && !a.has_plan) ? '#8E8E93' : a.color }">{{ a.initial }}</span>
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div class="flex justify-between gap-2">
                            <span class="truncate text-[16px] font-semibold">{{ a.name }}</span>
                            <span class="shrink-0 text-[13px]" :class="toneText[a.last_tone]">{{ a.last_label }}</span>
                        </div>
                        <div class="truncate text-[13px] text-ink-3">{{ a.plan_line }}</div>
                        <div class="mt-[3px] flex flex-wrap gap-1.5">
                            <span v-for="c in a.chips" :key="c.label" class="rounded-md px-[7px] py-0.5 text-[12px] font-semibold" :class="chipTone[c.tone]">{{ c.label }}</span>
                        </div>
                    </div>
                </Link>
            </div>
        </AdminPage>
    </AdminLayout>
</template>
