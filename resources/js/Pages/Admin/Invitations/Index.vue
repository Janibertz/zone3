<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSection from '@/Components/Admin/AdminSection.vue';
import AdminSegmented from '@/Components/Admin/AdminSegmented.vue';
import { firstName } from '@/Composables/useAdminFormat';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    invitations: Array,
    validDays:   Number,
    mode:        String,
    waiting:     Array,
    sources:     { type: Array, default: () => [] },
    referrers:   { type: Array, default: () => [] },
});

const sourceRange = ref('recent');
const sourceRows = computed(() => props.sources
    .map((s) => ({ source: s.source, n: s[sourceRange.value] }))
    .filter((s) => s.n > 0)
    .sort((a, b) => (a.source === 'ohne Quelle') - (b.source === 'ohne Quelle') || b.n - a.n));
const sourceMax = computed(() => Math.max(1, ...sourceRows.value.map((s) => s.n)));

const showOld = ref(false);

function daysLeft(iso) {
    return Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000));
}
function shortName(name) {
    const parts = String(name).trim().split(/\s+/);
    return parts.length > 1 ? `${parts[0]} ${parts[parts.length - 1].charAt(0)}.` : parts[0];
}
async function share(inv) {
    if (navigator.share) {
        try { await navigator.share({ title: 'Zone3', text: 'Komm zu Zone3:', url: inv.url }); return; } catch { /* abgebrochen */ }
    }
    copy(inv);
}

// ── Warteliste ──────────────────────────────────────────────────────
function setMode(mode) {
    if (mode === props.mode) return;
    router.post(route('admin.invitations.mode'), { mode }, { preserveScroll: true });
}

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
const otherShown = computed(() => other.value.slice(0, showOld.value ? undefined : 5));

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
    <Head title="Admin – Zugang" />

    <AdminLayout>
        <AdminPage title="Zugang" detail :back="route('admin.more')" back-label="Mehr">
            <!-- Warteliste an/aus (Mockup „Einladungen“) -->
            <div class="flex flex-col gap-1.5">
                <button type="button" role="switch" :aria-checked="mode === 'waitlist'"
                    class="flex w-full items-center justify-between gap-3 rounded-[14px] bg-surface px-3.5 py-3 text-left"
                    @click="setMode(mode === 'waitlist' ? 'open' : 'waitlist')">
                    <span class="flex flex-col">
                        <span class="text-[16px]">Neue Konten auf die Warteliste</span>
                        <span class="text-[13px] text-ink-3">Mit Apple und Link per Mail</span>
                    </span>
                    <span class="relative h-[31px] w-[51px] shrink-0 rounded-full transition-colors duration-200" :class="mode === 'waitlist' ? 'bg-success' : 'bg-surface-3'">
                        <span class="absolute top-0.5 h-[27px] w-[27px] rounded-full bg-white shadow transition-transform duration-200 ease-out-strong" :class="mode === 'waitlist' ? 'translate-x-[22px]' : 'translate-x-0.5'" />
                    </span>
                </button>
                <div class="px-1 text-[13px] text-ink-3">
                    Wer über einen Link kommt, überspringt die Warteliste.
                    <Link v-if="waiting.length" :href="route('admin.inbox.index')" class="font-semibold text-accent">{{ waiting.length }} {{ waiting.length === 1 ? 'wartet' : 'warten' }} im Postfach</Link>
                </div>
            </div>

            <!-- Neuer Link -->
            <AdminSection title="Neuer Link">
                <form class="flex flex-col gap-3 rounded-[14px] bg-surface p-3.5" @submit.prevent="create">
                    <AdminSegmented :model-value="form.multi ? 'multi' : 'single'"
                        :options="[{ value: 'single', label: 'Für eine Person' }, { value: 'multi', label: 'Für mehrere' }]"
                        @update:model-value="(v) => pickKind(v === 'multi')" />
                    <input v-model="form.note" type="text" maxlength="120" aria-label="Notiz"
                        :placeholder="form.multi ? 'Notiz, z. B. „Freunde per DM“' : 'Notiz, z. B. „Kollege vom Verein“'"
                        class="rounded-[10px] border-0 bg-surface-2 px-3 py-2 text-[16px] placeholder:text-ink-3 focus:ring-2 focus:ring-accent/40" />
                    <div v-if="form.multi" class="grid grid-cols-3 gap-2">
                        <label class="flex flex-col gap-1 text-[13px] text-ink-3">Höchstens
                            <input v-model="form.max_uses" type="number" min="2" inputmode="numeric" placeholder="beliebig"
                                class="rounded-[10px] border-0 bg-surface-2 px-3 py-2 text-[16px] text-ink focus:ring-2 focus:ring-accent/40" />
                        </label>
                        <label class="flex flex-col gap-1 text-[13px] text-ink-3">Tage gültig
                            <input v-model.number="form.days" type="number" min="1" max="365" inputmode="numeric"
                                class="rounded-[10px] border-0 bg-surface-2 px-3 py-2 text-[16px] text-ink focus:ring-2 focus:ring-accent/40" />
                        </label>
                        <label class="flex flex-col gap-1 text-[13px] text-ink-3">Quelle
                            <input v-model="form.source" type="text" maxlength="40" placeholder="freunde"
                                class="rounded-[10px] border-0 bg-surface-2 px-3 py-2 text-[16px] text-ink focus:ring-2 focus:ring-accent/40" />
                        </label>
                    </div>
                    <p v-for="(msg, key) in form.errors" :key="key" class="m-0 text-[13px] text-danger-ink">{{ msg }}</p>
                    <button type="submit" :disabled="form.processing" class="h-11 rounded-xl bg-accent text-[16px] font-semibold text-white disabled:opacity-40">Link erstellen</button>
                    <p class="m-0 text-[13px] text-ink-3">{{ form.multi ? 'Gilt für mehrere Konten, bis die Plätze voll sind.' : `Einmal einlösbar, ${validDays} Tage gültig.` }}</p>
                </form>
            </AdminSection>

            <!-- Offene Links -->
            <AdminSection id="links" title="Offene Links" :aside="open.length" class="scroll-mt-4">
                <div class="overflow-hidden rounded-[14px] bg-surface">
                    <p v-if="!open.length" class="px-3.5 py-3 text-[15px] text-ink-3">Kein offener Link.</p>
                    <div v-for="(inv, i) in open" :key="inv.id" class="flex flex-col gap-2 px-3.5 py-3" :class="i < open.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="truncate text-[16px] font-semibold">{{ inv.note || (inv.multi ? 'Für mehrere' : 'Für eine Person') }}</span>
                            <span class="shrink-0 text-[15px] tabular-nums text-ink-3">
                                {{ inv.multi ? (inv.max_uses ? `${inv.uses_count} von ${inv.max_uses}` : `${inv.uses_count} Konten`) : `noch ${daysLeft(inv.expires_at)} ${daysLeft(inv.expires_at) === 1 ? 'Tag' : 'Tage'}` }}
                            </span>
                        </div>
                        <div v-if="inv.multi && inv.max_uses" class="h-1.5 overflow-hidden rounded bg-surface-2">
                            <div class="h-full rounded bg-accent" :style="{ width: Math.min(100, (inv.uses_count / inv.max_uses) * 100) + '%' }" />
                        </div>
                        <div class="flex items-center justify-between gap-3 text-[13px] text-ink-3">
                            <span class="truncate">
                                <template v-if="inv.multi">{{ inv.source ? `Quelle ${inv.source} · ` : '' }}bis {{ when(inv.expires_at) }}</template>
                                <template v-else>bis {{ when(inv.expires_at) }}{{ inv.creator ? ` · von ${firstName(inv.creator)}` : '' }}</template>
                            </span>
                            <span class="flex shrink-0 gap-3">
                                <button type="button" class="font-semibold text-accent" @click="share(inv)">{{ copiedId === inv.id ? 'Kopiert' : 'Teilen' }}</button>
                                <button type="button" class="font-semibold text-danger" @click="toRevoke = inv">Zurückziehen</button>
                            </span>
                        </div>
                        <div v-if="inv.redeemers.length" class="text-[13px] text-ink-3">
                            <template v-for="(r, idx) in inv.redeemers" :key="r.id">
                                <Link :href="route('admin.users.show', r.id)" class="text-ink-2">{{ firstName(r.name) }}</Link><span v-if="idx < inv.redeemers.length - 1">, </span>
                            </template>
                        </div>
                    </div>
                </div>
            </AdminSection>

            <!-- Tester laden ein -->
            <AdminSection v-if="referrers.length" title="Tester laden ein" :aside="`${referrers.reduce((n, r) => n + r.used, 0)} Freunde`">
                <div class="overflow-hidden rounded-[14px] bg-surface">
                    <div v-for="(r, i) in referrers" :key="r.name" class="flex justify-between gap-3 px-3.5 py-[11px]" :class="i < referrers.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                        <span class="text-[16px]">{{ shortName(r.name) }}</span>
                        <span class="text-[15px] tabular-nums text-ink-3">{{ r.used }} von {{ r.max }}</span>
                    </div>
                </div>
            </AdminSection>

            <!-- Woher Anmeldungen kommen -->
            <AdminSection id="quellen" title="Woher Anmeldungen kommen" class="scroll-mt-4">
                <div class="flex flex-col gap-2.5 rounded-[14px] bg-surface p-3.5">
                    <AdminSegmented v-model="sourceRange" :options="[{ value: 'recent', label: '30 Tage' }, { value: 'total', label: 'Gesamt' }]" />
                    <p v-if="!sourceRows.length" class="m-0 text-[15px] text-ink-3">Noch keine Anmeldung.</p>
                    <div v-else class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_34px] items-center gap-2.5 text-[15px]">
                        <template v-for="s in sourceRows" :key="s.source">
                            <span class="truncate" :class="s.source === 'ohne Quelle' ? 'text-ink-3' : ''">{{ s.source }}</span>
                            <div class="h-2 rounded" :class="s.source === 'ohne Quelle' ? 'bg-line-strong' : 'bg-accent'" :style="{ width: Math.max(4, (s.n / sourceMax) * 100) + '%' }" />
                            <span class="text-right font-bold tabular-nums">{{ s.n }}</span>
                        </template>
                    </div>
                    <p class="m-0 text-[13px] text-ink-3">Gezählt ab 10.11.2026, über <span class="font-mono">?ref=</span> im ersten Link.</p>
                </div>
            </AdminSection>

            <!-- Verlauf -->
            <AdminSection v-if="other.length" title="Verlauf">
                <template #aside>
                    <button type="button" class="text-[15px] font-semibold text-accent" @click="showOld = !showOld">{{ showOld ? 'Weniger' : `Alle ${other.length}` }}</button>
                </template>
                <div class="overflow-hidden rounded-[14px] bg-surface">
                    <div v-for="(inv, i) in otherShown" :key="inv.id" class="flex items-baseline justify-between gap-3 px-3.5 py-[11px]" :class="i < otherShown.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                        <span class="truncate text-[15px]">{{ inv.note || (inv.multi ? 'Für mehrere' : 'Für eine Person') }}</span>
                        <span class="shrink-0 text-[13px]" :class="statusLabels[inv.status]?.cls">
                            {{ statusLabels[inv.status]?.text }}<template v-if="inv.multi"> · {{ inv.uses_count }}</template><template v-else-if="inv.redeemer"> · {{ firstName(inv.redeemer.name) }}</template>
                        </span>
                    </div>
                </div>
            </AdminSection>
        </AdminPage>

        <ConfirmSheet
            :show="!!toRevoke"
            title="Link zurückziehen?"
            :message="toRevoke ? `Der Link ${toRevoke.note ? '„' + toRevoke.note + '“ ' : ''}funktioniert danach nicht mehr. Wer schon drin ist, bleibt.` : ''"
            confirm-label="Zurückziehen"
            :loading="busy"
            @confirm="confirmRevoke"
            @close="toRevoke = null" />
    </AdminLayout>
</template>
