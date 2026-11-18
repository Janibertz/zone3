<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSegmented from '@/Components/Admin/AdminSegmented.vue';
import { when } from '@/Composables/useAdminFormat';

/**
 * Push senden (Mockup „Push“, 18.11.2026): an alle, an eine Gruppe oder an
 * einen Athleten. Wer Telegram nutzt, bekommt es dort (Jan: Quelle 1).
 */
const props = defineProps({
    athletes: { type: Array, default: () => [] },
    groups:   { type: Array, default: () => [] },
    all:      { type: Object, required: true },
    targets:  { type: Array, default: () => [] },
    me:       { type: String, default: '' },
    preset:   { type: Number, default: null },
    history:  { type: Array, default: () => [] },
});

const audience = ref(props.preset ? 'user' : 'all');
const group    = ref(props.groups[0]?.key ?? null);
const userId   = ref(props.preset ?? props.athletes[0]?.id ?? null);
const title    = ref('');
const body     = ref('');
const url      = ref(props.targets[0]?.url ?? '/dashboard');
const sending  = ref(false);

const modes = [
    { value: 'all', label: 'Alle' },
    { value: 'group', label: 'Gruppe' },
    { value: 'user', label: 'Ein Athlet' },
];

const athlete = computed(() => props.athletes.find((a) => a.id === userId.value) ?? null);
const groupRow = computed(() => props.groups.find((g) => g.key === group.value) ?? null);

const viewer = computed(() => (audience.value === 'user' ? (athlete.value?.first ?? 'der Athlet') : 'ein Athlet'));

function reachLine(r, what) {
    const parts = [r.telegram ? `${r.telegram} über Telegram` : null, r.push ? `${r.push} per Push` : null].filter(Boolean);
    const reached = r.telegram + r.push;
    if (!reached) return `Erreicht niemand von ${what} — kein Telegram und kein Push-Gerät.`;
    return `Erreicht ${reached} von ${r.total}: ${parts.join(', ')}.`;
}

const reach = computed(() => {
    if (audience.value === 'user') {
        return athlete.value ? `Erreicht ${athlete.value.first} ${athlete.value.label}.` : '';
    }
    if (audience.value === 'group') {
        return groupRow.value ? reachLine(groupRow.value, `Gruppe ${groupRow.value.label}`) : '';
    }
    return reachLine(props.all, 'allen');
});

const canSend = computed(() => title.value.trim() && body.value.trim() && !sending.value
    && (audience.value !== 'user' || userId.value) && (audience.value !== 'group' || group.value));

function send(test = false) {
    if (!canSend.value) return;
    sending.value = true;
    router.post(route('admin.push.send'), {
        audience: test ? 'test' : audience.value,
        group: audience.value === 'group' ? group.value : null,
        user_id: audience.value === 'user' ? userId.value : null,
        title: title.value,
        body: body.value,
        url: url.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { if (!test) { title.value = ''; body.value = ''; } },
        onFinish: () => { sending.value = false; },
    });
}

const selectCls = 'max-w-[60%] appearance-none border-0 bg-transparent p-0 pr-1 text-right text-[16px] text-accent focus:ring-0';
</script>

<template>
    <Head title="Admin – Push senden" />

    <AdminLayout>
        <AdminPage title="Push senden" detail :back="route('admin.more')" back-label="Mehr">
            <AdminSegmented v-model="audience" :options="modes" />

            <div class="overflow-hidden rounded-[14px] bg-surface">
                <div v-if="audience !== 'all'" class="flex items-center justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-3">
                    <span class="text-[16px]">An</span>
                    <select v-if="audience === 'user'" v-model="userId" aria-label="Athlet" :class="selectCls">
                        <option v-for="a in athletes" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                    <select v-else v-model="group" aria-label="Gruppe" :class="selectCls">
                        <option v-for="g in groups" :key="g.key" :value="g.key">{{ g.label }} ({{ g.total }})</option>
                    </select>
                </div>
                <label class="block border-b-[0.5px] border-line px-3.5 py-3">
                    <span class="block text-[13px] text-ink-3">Titel</span>
                    <input v-model="title" type="text" maxlength="100" placeholder="Worum geht’s?"
                        class="w-full border-0 bg-transparent p-0 pt-0.5 text-[16px] outline-none placeholder:text-ink-3 focus:ring-0" />
                </label>
                <label class="block border-b-[0.5px] border-line px-3.5 py-3">
                    <span class="block text-[13px] text-ink-3">Nachricht</span>
                    <textarea v-model="body" rows="3" maxlength="500" placeholder="Ein, zwei Sätze."
                        class="w-full resize-none border-0 bg-transparent p-0 pt-0.5 text-[16px] leading-[22px] outline-none placeholder:text-ink-3 focus:ring-0" />
                </label>
                <div class="flex items-center justify-between gap-3 px-3.5 py-3">
                    <span class="text-[16px]">Öffnet</span>
                    <select v-model="url" aria-label="Öffnet" :class="selectCls" class="!text-ink-3">
                        <option v-for="t in targets" :key="t.url" :value="t.url">{{ t.label }}</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <div class="px-1 text-[13px] font-semibold uppercase text-ink-3">So sieht {{ viewer }} es</div>
                <div class="flex gap-2.5 rounded-[18px] border-[0.5px] border-line bg-surface/75 px-3.5 py-3">
                    <span class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[9px] bg-black font-extrabold text-white">3</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex justify-between gap-2">
                            <span class="truncate text-[15px] font-bold">{{ title || 'Titel' }}</span>
                            <span class="shrink-0 text-[13px] text-ink-3">jetzt</span>
                        </div>
                        <div class="whitespace-pre-line text-[15px] leading-5 text-ink-2">{{ body || 'Nachricht' }}</div>
                    </div>
                </div>
                <div class="px-1 text-[13px] text-ink-3">{{ reach }}</div>
            </div>

            <div class="flex gap-2">
                <button type="button" class="h-11 flex-1 rounded-xl bg-surface-2 text-[16px] font-semibold disabled:opacity-40" :disabled="!canSend" :title="`An dich ${me}`" @click="send(true)">Test an mich</button>
                <button type="button" class="h-11 flex-1 rounded-xl bg-accent text-[16px] font-semibold text-white disabled:opacity-40" :disabled="!canSend" @click="send(false)">Senden</button>
            </div>

            <div v-if="history.length" class="flex flex-col gap-1.5">
                <div class="px-1 text-[13px] font-semibold uppercase text-ink-3">Zuletzt gesendet</div>
                <div class="overflow-hidden rounded-[14px] bg-surface">
                    <div v-for="(h, i) in history" :key="h.id" class="px-3.5 py-[11px]" :class="i < history.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                        <div class="text-[15px] font-semibold">{{ h.title }}</div>
                        <div class="text-[13px] text-ink-3">{{ h.audience }} · {{ when(h.at) }} · {{ h.reached }} erreicht<template v-if="h.telegram"> ({{ h.telegram }} Telegram)</template></div>
                    </div>
                </div>
            </div>
        </AdminPage>
    </AdminLayout>
</template>
