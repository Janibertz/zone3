<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    invitations: Array,
    validDays:   Number,
    mode:        String,
    waiting:     Array,
});

// ── Warteliste ──────────────────────────────────────────────────────
function setMode(mode) {
    if (mode === props.mode) return;
    router.post(route('admin.invitations.mode'), { mode }, { preserveScroll: true });
}

const approvingId = ref(null);
function approve(u) {
    approvingId.value = u.id;
    router.post(route('admin.waitlist.approve', u.id), {}, {
        preserveScroll: true,
        onFinish: () => { approvingId.value = null; },
    });
}

const toReject = ref(null);
function confirmReject() {
    if (!toReject.value) return;
    busy.value = true;
    router.delete(route('admin.waitlist.reject', toReject.value.id), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; toReject.value = null; },
    });
}

function since(value) {
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({ note: '', multi: false, max_uses: '', days: 14, source: '' });

function pickKind(multi) {
    form.multi = multi;
    form.days = multi ? 90 : 14;
}

function create() {
    form.post(route('admin.invitations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        transform: (d) => ({ ...d, max_uses: d.max_uses === '' ? null : Number(d.max_uses) }),
    });
}

const copiedId = ref(null);

async function copy(inv) {
    try {
        await navigator.clipboard.writeText(inv.url);
        copiedId.value = inv.id;
        setTimeout(() => { if (copiedId.value === inv.id) copiedId.value = null; }, 2000);
    } catch {
        window.prompt('Link kopieren:', inv.url);
    }
}

const toRevoke = ref(null);
const busy     = ref(false);

function confirmRevoke() {
    if (!toRevoke.value) return;
    busy.value = true;

    router.post(route('admin.invitations.revoke', toRevoke.value.id), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; toRevoke.value = null; },
    });
}

const open  = computed(() => props.invitations.filter((i) => i.status === 'open'));
const other = computed(() => props.invitations.filter((i) => i.status !== 'open'));

const statusLabels = {
    used:    { text: 'Eingelöst',     cls: 'text-success-ink' },
    expired: { text: 'Abgelaufen',    cls: 'text-ink-3' },
    revoked: { text: 'Zurückgezogen', cls: 'text-warn-ink' },
};

function when(value) {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
}
</script>

<template>
    <Head title="Zugang" />

    <AdminLayout>
        <template #header>
            <PageHeader title="Zugang" />
        </template>
        <div class="px-4 py-4 lg:px-8 lg:py-6 space-y-6">

            <div v-if="flash.success" class="px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                {{ flash.success }}
            </div>

            <!-- ── Zugang: Warteliste oder offen ───────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Neue Konten über Apple oder Link per Mail</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Bei jeder neuen Anmeldung bekommst du einen Push. Wer über einen Einladungslink kommt, ist immer sofort drin.
                    </p>
                </div>
                <div class="px-6 py-4 grid grid-cols-2 gap-2">
                    <button
                        v-for="opt in [{ key: 'waitlist', label: 'Warteliste', hint: 'Du schaltest frei' }, { key: 'open', label: 'Offen', hint: 'Sofort drin' }]"
                        :key="opt.key"
                        type="button"
                        class="rounded-field px-4 py-3 text-left transition active:scale-[0.98]"
                        :class="mode === opt.key ? 'bg-accent text-white' : 'bg-surface-2 text-ink'"
                        @click="setMode(opt.key)">
                        <span class="block text-sm font-semibold">{{ opt.label }}</span>
                        <span class="block text-xs" :class="mode === opt.key ? 'text-white/80' : 'text-ink-3'">{{ opt.hint }}</span>
                    </button>
                </div>

                <div class="border-t border-line">
                    <p v-if="!waiting.length" class="px-6 py-6 text-sm text-ink-3">
                        Niemand wartet.
                    </p>
                    <div v-else class="divide-y divide-line">
                        <div v-for="u in waiting" :key="u.id" class="px-6 py-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink truncate">{{ u.name }}</p>
                                <p class="text-xs text-ink-3 truncate">{{ u.email }} · {{ u.via }} · seit {{ since(u.since) }}</p>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    class="rounded-field bg-accent px-3 py-1.5 text-xs font-semibold text-white active:opacity-80 disabled:opacity-50"
                                    :disabled="approvingId === u.id"
                                    @click="approve(u)">
                                    Freischalten
                                </button>
                                <button
                                    class="rounded-field px-3 py-1.5 text-xs text-ink-3 hover:text-danger-ink active:opacity-60"
                                    @click="toReject = u">
                                    Ablehnen
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Neuer Link ────────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Einladungslink erstellen</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Wer über diesen Link kommt, ist sofort drin, an der Warteliste vorbei. Er gilt für jeden, der ihn hat.
                        Für eine Person einmal und {{ validDays }} Tage; für mehrere so oft und so lange, wie du einstellst.
                        Die Notiz ist nur für dich, die Quelle steht später am Konto.
                    </p>
                </div>
                <form class="px-6 py-4 space-y-3" @submit.prevent="create">
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="opt in [{ multi: false, label: 'Für eine Person', hint: 'Einmal, 14 Tage' }, { multi: true, label: 'Für mehrere', hint: 'z. B. Freunde, Lauftreff' }]"
                            :key="opt.label"
                            type="button"
                            class="rounded-field px-4 py-3 text-left transition active:scale-[0.98]"
                            :class="form.multi === opt.multi ? 'bg-accent text-white' : 'bg-surface-2 text-ink'"
                            @click="pickKind(opt.multi)">
                            <span class="block text-sm font-semibold">{{ opt.label }}</span>
                            <span class="block text-xs" :class="form.multi === opt.multi ? 'text-white/80' : 'text-ink-3'">{{ opt.hint }}</span>
                        </button>
                    </div>
                    <input
                        v-model="form.note"
                        type="text"
                        maxlength="120"
                        :placeholder="form.multi ? 'Notiz, z. B. „Freunde per DM“' : 'Notiz, z. B. „für Christopher“'"
                        class="w-full rounded-field border-line bg-canvas text-base sm:text-sm text-ink placeholder:text-ink-3" />
                    <div v-if="form.multi" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="block text-xs text-ink-3 mb-1">Höchstens Konten</span>
                            <input v-model="form.max_uses" type="number" min="2" inputmode="numeric" placeholder="beliebig viele"
                                class="w-full rounded-field border-line bg-canvas text-base sm:text-sm text-ink placeholder:text-ink-3" />
                        </label>
                        <label class="block">
                            <span class="block text-xs text-ink-3 mb-1">Gültig (Tage)</span>
                            <input v-model.number="form.days" type="number" min="1" max="365" inputmode="numeric"
                                class="w-full rounded-field border-line bg-canvas text-base sm:text-sm text-ink" />
                        </label>
                        <label class="block">
                            <span class="block text-xs text-ink-3 mb-1">Quelle</span>
                            <input v-model="form.source" type="text" maxlength="40" placeholder="z. B. freunde"
                                class="w-full rounded-field border-line bg-canvas text-base sm:text-sm text-ink placeholder:text-ink-3" />
                        </label>
                    </div>
                    <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-danger-ink">{{ msg }}</p>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white transition active:opacity-80 disabled:opacity-50">
                        Link erstellen
                    </button>
                </form>
            </div>

            <!-- ── Offen ─────────────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Offene Links ({{ open.length }})</h2>
                </div>

                <p v-if="!open.length" class="px-6 py-8 text-sm text-ink-3">
                    Kein offener Link.
                </p>

                <div v-else class="divide-y divide-line">
                    <div v-for="inv in open" :key="inv.id" class="px-6 py-3 space-y-2">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm font-medium text-ink">
                                {{ inv.note || 'Ohne Notiz' }}
                                <span v-if="inv.multi" class="ml-1 text-xs font-normal text-accent-ink">für mehrere</span>
                            </span>
                            <span class="text-xs text-ink-3">gültig bis {{ when(inv.expires_at) }}</span>
                        </div>
                        <p v-if="inv.multi" class="text-xs text-ink-3">
                            {{ inv.uses_count }} {{ inv.max_uses ? 'von ' + inv.max_uses : '' }} {{ inv.uses_count === 1 ? 'Konto' : 'Konten' }}
                            <template v-if="inv.source"> · Quelle „{{ inv.source }}“</template>
                            <template v-if="inv.redeemers.length">
                                ·
                                <template v-for="(r, idx) in inv.redeemers" :key="r.id">
                                    <Link :href="route('admin.users.show', r.id)" class="hover:text-accent-ink">{{ r.name }}</Link><span v-if="idx < inv.redeemers.length - 1">, </span>
                                </template>
                            </template>
                        </p>
                        <p class="text-xs text-ink-3 font-mono break-all select-all">{{ inv.url }}</p>
                        <div class="flex gap-2">
                            <button
                                class="px-2 py-1 rounded-field text-xs font-medium text-accent-ink active:opacity-60"
                                @click="copy(inv)">
                                {{ copiedId === inv.id ? 'Kopiert ✓' : 'Link kopieren' }}
                            </button>
                            <button
                                class="px-2 py-1 rounded-field text-xs text-ink-3 hover:text-danger-ink active:opacity-60"
                                @click="toRevoke = inv">
                                Zurückziehen
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Verlauf ───────────────────────────────────────────── -->
            <div v-if="other.length" class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Verlauf</h2>
                </div>
                <div class="divide-y divide-line">
                    <div v-for="inv in other" :key="inv.id" class="px-6 py-3 flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <span class="text-sm text-ink">{{ inv.note || 'Ohne Notiz' }}</span>
                            <span class="ml-2 text-xs text-ink-3">erstellt {{ when(inv.created_at) }}</span>
                        </div>
                        <span class="text-xs" :class="statusLabels[inv.status]?.cls">
                            {{ statusLabels[inv.status]?.text }}
                            <template v-if="inv.multi">
                                · {{ inv.uses_count }} {{ inv.uses_count === 1 ? 'Konto' : 'Konten' }}
                            </template>
                            <template v-else-if="inv.redeemer">
                                von
                                <Link :href="route('admin.users.show', inv.redeemer.id)" class="hover:text-accent-ink">
                                    {{ inv.redeemer.name }}
                                </Link>
                                am {{ when(inv.used_at) }}
                            </template>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmSheet
            :show="!!toRevoke"
            title="Einladung zurückziehen"
            :message="toRevoke ? `Der Link ${toRevoke.note ? '„' + toRevoke.note + '“ ' : ''}funktioniert danach nicht mehr.` : ''"
            confirm-label="Zurückziehen"
            :loading="busy"
            @confirm="confirmRevoke"
            @close="toRevoke = null" />

        <ConfirmSheet
            :show="!!toReject"
            title="Anmeldung ablehnen"
            :message="toReject ? `Das Konto von ${toReject.name} (${toReject.email}) wird gelöscht.` : ''"
            confirm-label="Ablehnen"
            :loading="busy"
            @confirm="confirmReject"
            @close="toReject = null" />
    </AdminLayout>
</template>
