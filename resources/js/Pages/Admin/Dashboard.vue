<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSection from '@/Components/Admin/AdminSection.vue';
import Dot from '@/Components/Admin/Dot.vue';
import { eur } from '@/Composables/useAdminFormat';

/**
 * „Heute“ im Admin (Mockup „Admin-Bereich aufräumen“, 18.11.2026): was
 * dich braucht, das Tester-Ziel, KI-Kosten und wer still geworden ist.
 */
const props = defineProps({
    needsYou:     { type: Array, default: () => [] },
    goal:         { type: Object, required: true },
    withoutPlan:  { type: Number, default: 0 },
    aiCosts:      { type: Object, required: true },
    quiet:        { type: Array, default: () => [] },
    providers:    { type: Array, default: () => [] },
    systemHealth: { type: Object, default: () => ({ issues: [] }) },
    failure:      { type: Object, default: null },
});

const today = new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });

const pct = (v, of) => (of > 0 ? Math.min(100, Math.round((v / of) * 100)) : 0);

const funnelMax = computed(() => Math.max(1, props.goal.funnel[0]?.count ?? 1));

const statusLine = computed(() => {
    if (props.failure) {
        const since = new Date(props.failure.since).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
        return { tone: 'warn', text: `${props.failure.count} ${props.failure.count === 1 ? 'Fehler' : 'Fehler'} seit ${since} · Rest läuft` };
    }
    const issues = props.systemHealth?.issues ?? [];
    if (issues.length) return { tone: props.systemHealth.severe ? 'danger' : 'warn', text: issues[0] };
    return { tone: 'success', text: 'Alles läuft' };
});

const busy = ref(null);
function approve(id) {
    busy.value = id;
    router.post(route('admin.waitlist.approve', id), {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
}

function rowHref(r) {
    if (r.kind === 'ticket') return route('admin.support.show', r.id);
    if (r.kind === 'failure') return route('admin.system.index');
    return route('admin.inbox.index');
}

const rowIcon = {
    waitlist: { bg: 'bg-accent', path: '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6M19 8v6M16 11h6"/>' },
    ticket:   { bg: 'bg-success', path: '<path d="M4 5h16v11H8l-4 4z"/>' },
    contact:  { bg: 'bg-success', path: '<path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/>' },
    report:   { bg: 'bg-warn', path: '<path d="M5 21V4h11l-1 4 4 0-2 6H5"/>' },
    failure:  { bg: 'bg-danger', path: '<path d="M12 8v5M12 17h.01M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>' },
};

const search = ref('');
function doSearch() {
    router.get(route('admin.users.index'), { search: search.value || undefined });
}
</script>

<template>
    <Head title="Admin – Heute" />

    <AdminLayout>
        <AdminPage title="Heute" :eyebrow="today" badge width="wide">
            <template #action>
                <Link :href="route('admin.users.index', { focus: 1 })" aria-label="Athleten suchen" class="flex h-10 w-10 items-center justify-center rounded-full bg-surface-2 lg:hidden">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                </Link>
                <form class="hidden h-[38px] w-[340px] items-center gap-2 rounded-[10px] bg-surface-2 px-3 text-ink-3 lg:flex" @submit.prevent="doSearch">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input v-model="search" aria-label="Athleten suchen" placeholder="Athlet, Mail, Quelle …" class="flex-1 border-0 bg-transparent p-0 text-[15px] text-ink outline-none focus:ring-0" />
                </form>
            </template>

            <Link :href="route('admin.system.index')"
                class="flex items-center gap-2.5 rounded-[14px] px-3.5 py-3"
                :class="{ warn: 'bg-warn-soft text-warn-ink', danger: 'bg-danger-soft text-danger-ink', success: 'bg-success-soft text-success-ink' }[statusLine.tone]">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="{ warn: 'bg-warn', danger: 'bg-danger', success: 'bg-success' }[statusLine.tone]" />
                <span class="flex-1 text-[15px] font-semibold">{{ statusLine.text }}</span>
                <svg width="8" height="14" viewBox="0 0 8 14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m1 1 6 6-6 6" /></svg>
            </Link>

            <div class="grid grid-cols-1 gap-[22px] lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)_minmax(0,1fr)] lg:items-start lg:gap-5">
                <!-- Braucht dich -->
                <AdminSection title="Braucht dich" :aside="needsYou.length">
                    <div class="overflow-hidden rounded-[14px] bg-surface">
                        <p v-if="!needsYou.length" class="px-3.5 py-3 text-[15px] text-ink-3">Nichts offen. Schön.</p>
                        <component
                            :is="r.kind === 'waitlist' ? 'div' : Link"
                            v-for="(r, i) in needsYou"
                            :key="r.kind + (r.id ?? r.user_id ?? r.title)"
                            :href="r.kind === 'waitlist' ? undefined : rowHref(r)"
                            class="flex items-center gap-3 px-3.5 py-3"
                            :class="i < needsYou.length - 1 ? 'border-b-[0.5px] border-line' : ''"
                        >
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="rowIcon[r.kind].bg">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="rowIcon[r.kind].path" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-[16px] font-semibold">{{ r.title }}</div>
                                <div class="truncate text-[13px] text-ink-3">{{ r.meta }}</div>
                            </div>
                            <button v-if="r.kind === 'waitlist'" type="button" :disabled="busy === r.user_id"
                                class="h-8 shrink-0 rounded-2xl bg-accent px-3.5 text-[14px] font-semibold text-white active:opacity-80 disabled:opacity-50"
                                @click="approve(r.user_id)">Freigeben</button>
                            <svg v-else width="8" height="14" viewBox="0 0 8 14" fill="none" class="shrink-0 stroke-line-strong" stroke-width="2" aria-hidden="true"><path d="m1 1 6 6-6 6" /></svg>
                        </component>
                    </div>
                </AdminSection>

                <div class="flex flex-col gap-[22px]">
                    <!-- Ziel bis Weihnachten -->
                    <AdminSection title="Ziel bis Weihnachten">
                        <div class="flex flex-col gap-3.5 rounded-[14px] bg-surface p-4">
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-[44px] font-extrabold leading-none tracking-[-0.035em] tabular-nums">{{ goal.count }}</span>
                                <span class="text-[17px] text-ink-3">von {{ goal.goal }} Testern</span>
                                <span v-if="goal.this_week" class="ml-auto text-[13px] font-semibold text-success-ink">+{{ goal.this_week }} diese Woche</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded bg-surface-2"><div class="h-full rounded bg-accent" :style="{ width: pct(goal.count, goal.goal) + '%' }" /></div>
                            <div class="text-[13px] text-ink-3">
                                <template v-if="goal.count >= goal.goal">Ziel erreicht.</template>
                                <template v-else>{{ goal.days_left }} Tage übrig · nötig sind {{ goal.per_week }} pro Woche</template>
                            </div>
                            <div class="flex flex-col gap-2 border-t-[0.5px] border-line pt-1">
                                <div class="pt-2.5 text-[13px] font-semibold text-ink-2">Wie weit sie kommen</div>
                                <div class="grid grid-cols-[112px_1fr_28px] items-center gap-2 text-[14px]">
                                    <template v-for="f in goal.funnel" :key="f.key">
                                        <span>{{ f.label }}</span>
                                        <div class="h-3.5 rounded-[3px]" :class="f.key === 'active' ? 'bg-success' : 'bg-accent'" :style="{ width: Math.max(2, pct(f.count, funnelMax)) + '%' }" />
                                        <span class="text-right tabular-nums">{{ f.count }}</span>
                                    </template>
                                </div>
                                <Link v-if="withoutPlan" :href="route('admin.users.index', { filter: 'noplan' })" class="text-[13px] text-warn-ink">
                                    {{ withoutPlan }} {{ withoutPlan === 1 ? 'hängt' : 'hängen' }} nach dem Onboarding ohne Plan · ansehen
                                </Link>
                            </div>
                        </div>
                    </AdminSection>

                    <!-- KI-Kosten -->
                    <AdminSection title="KI-Kosten">
                        <Link :href="route('admin.ai-costs')" class="grid grid-cols-2 gap-3.5 rounded-[14px] bg-surface p-4">
                            <div class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-semibold text-ink-3">Heute</span>
                                <span class="text-[28px] font-extrabold tracking-[-0.03em] tabular-nums">{{ eur(aiCosts.today) }} <span class="text-[15px] font-medium text-ink-3">€</span></span>
                                <div class="h-1.5 rounded-[3px] bg-surface-2"><div class="h-full rounded-[3px]" :class="aiCosts.today > aiCosts.today_budget * 0.8 ? 'bg-warn' : 'bg-success'" :style="{ width: pct(aiCosts.today, aiCosts.today_budget) + '%' }" /></div>
                                <span class="text-[12px] text-ink-3">von {{ eur(aiCosts.today_budget) }} € Tagesbremse</span>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-semibold capitalize text-ink-3">{{ aiCosts.month_label }}</span>
                                <span class="text-[28px] font-extrabold tracking-[-0.03em] tabular-nums">{{ eur(aiCosts.month) }} <span class="text-[15px] font-medium text-ink-3">€</span></span>
                                <div class="h-1.5 rounded-[3px] bg-surface-2"><div class="h-full rounded-[3px]" :class="aiCosts.forecast > aiCosts.limit_eur ? 'bg-danger' : 'bg-accent'" :style="{ width: pct(aiCosts.month, aiCosts.limit_eur) + '%' }" /></div>
                                <span class="text-[12px] text-ink-3">Prognose {{ eur(aiCosts.forecast) }} € von {{ eur(aiCosts.limit_eur) }} €</span>
                            </div>
                        </Link>
                    </AdminSection>
                </div>

                <div class="flex flex-col gap-[22px]">
                    <!-- Still geworden -->
                    <AdminSection title="Still geworden">
                        <div class="overflow-hidden rounded-[14px] bg-surface">
                            <p v-if="!quiet.length" class="px-3.5 py-3 text-[15px] text-ink-3">Alle sind dabei.</p>
                            <Link v-for="(q, i) in quiet" :key="q.id" :href="route('admin.users.show', q.id)"
                                class="flex items-center gap-3 px-3.5 py-3" :class="i < quiet.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#8E8E93] font-bold text-white">{{ q.initial }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-[16px] font-semibold">{{ q.name }}</div>
                                    <div class="truncate text-[13px] text-ink-3">{{ q.meta }}</div>
                                </div>
                                <svg width="8" height="14" viewBox="0 0 8 14" fill="none" class="shrink-0 stroke-line-strong" stroke-width="2" aria-hidden="true"><path d="m1 1 6 6-6 6" /></svg>
                            </Link>
                        </div>
                    </AdminSection>

                    <!-- Anbindungen (am Desktop; am Telefon unter System) -->
                    <AdminSection title="Anbindungen" class="hidden lg:flex">
                        <Link :href="route('admin.system.index')" class="overflow-hidden rounded-[14px] bg-surface">
                            <div v-for="(p, i) in providers.filter(p => p.count > 0)" :key="p.key"
                                class="flex items-center gap-2.5 px-3.5 py-[11px]"
                                :class="i < providers.filter(p => p.count > 0).length - 1 ? 'border-b-[0.5px] border-line' : ''">
                                <Dot :tone="p.tone" />
                                <span class="flex-1 text-[16px]">{{ p.name }}</span>
                                <span class="text-[15px]" :class="p.tone === 'warn' ? 'text-warn-ink' : 'text-ink-3'">{{ p.value }}</span>
                            </div>
                        </Link>
                    </AdminSection>
                </div>
            </div>
        </AdminPage>
    </AdminLayout>
</template>
