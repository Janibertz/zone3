<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    invitations: Array,
    validDays:   Number,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({ note: '' });

function create() {
    form.post(route('admin.invitations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
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
    <Head title="Einladungen" />

    <AdminLayout>
        <template #header>
            <PageHeader title="Einladungen" />
        </template>
        <div class="px-4 py-4 lg:px-8 lg:py-6 space-y-6">

            <div v-if="flash.success" class="px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                {{ flash.success }}
            </div>

            <!-- ── Neuer Link ────────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Einladungslink erstellen</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Registrieren geht nur mit einem Link von hier. Er gilt für jeden, der ihn hat,
                        aber nur einmal und höchstens {{ validDays }} Tage. Die Notiz ist nur für dich.
                    </p>
                </div>
                <form class="px-6 py-4 flex flex-col gap-3 sm:flex-row" @submit.prevent="create">
                    <input
                        v-model="form.note"
                        type="text"
                        maxlength="120"
                        placeholder="Notiz, z. B. „für Christopher“"
                        class="flex-1 rounded-field border-line bg-canvas text-base sm:text-sm text-ink placeholder:text-ink-3" />
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
                            <span class="text-sm font-medium text-ink">{{ inv.note || 'Ohne Notiz' }}</span>
                            <span class="text-xs text-ink-3">gültig bis {{ when(inv.expires_at) }}</span>
                        </div>
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
                            <template v-if="inv.redeemer">
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
    </AdminLayout>
</template>
