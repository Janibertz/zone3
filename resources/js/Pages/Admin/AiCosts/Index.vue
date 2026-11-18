<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSection from '@/Components/Admin/AdminSection.vue';
import { eur } from '@/Composables/useAdminFormat';

/**
 * KI-Kosten (Mockup „KI“, 18.11.2026): Monat bis heute gegen das Limit auf
 * der Plattform, Tage als Säulen, wofür und für wen.
 */
const props = defineProps({ costs: { type: Object, required: true } });
const c = computed(() => props.costs);

const pct = (v) => Math.max(0, Math.min(100, c.value.limit_eur > 0 ? (v / c.value.limit_eur) * 100 : 0));
const spentPct = computed(() => pct(c.value.month));
const forecastPct = computed(() => Math.max(0, pct(c.value.forecast) - spentPct.value));

const verdict = computed(() => {
    if (c.value.blocked) return { text: 'Notbremse aktiv', cls: 'text-danger-ink' };
    if (c.value.limit_eur > 0 && c.value.forecast > c.value.limit_eur) return { text: 'Prognose über dem Limit', cls: 'text-warn-ink' };
    return { text: 'im Rahmen', cls: 'text-success-ink' };
});

const maxDay = computed(() => Math.max(0.0001, ...(c.value.days ?? []).map((d) => d.cost)));
const peakDay = computed(() => {
    const d = c.value.days ?? [];
    return d.length ? d.reduce((a, b) => (b.cost > a.cost ? b : a)).day : null;
});
const today = new Date().getDate();
const euro = (v) => `${eur(v)} €`;

const maxType = computed(() => Math.max(0.0001, ...(c.value.by_type ?? []).map((t) => t.cost)));
const capital = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

function short(name) {
    const parts = String(name).trim().split(/\s+/);
    return parts.length > 1 ? `${parts[0]} ${parts[parts.length - 1].charAt(0)}.` : parts[0];
}
</script>

<template>
    <Head title="Admin – KI-Kosten" />

    <AdminLayout>
        <AdminPage title="KI-Kosten" detail :back="route('admin.dashboard')" back-label="Heute">
            <div class="flex flex-col gap-3 rounded-[14px] bg-surface p-4">
                <div class="flex items-baseline justify-between">
                    <span class="text-[13px] font-semibold text-ink-3">{{ capital(c.month_label) }} bis heute</span>
                    <span class="text-[13px] font-semibold" :class="verdict.cls">{{ verdict.text }}</span>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="font-rounded text-[44px] font-extrabold leading-none tracking-[-0.035em]">{{ eur(c.month) }}</span>
                    <span class="text-[17px] text-ink-3">€</span>
                </div>
                <div class="relative h-2.5 rounded-[5px] bg-surface-2">
                    <div class="absolute inset-y-0 left-0 rounded-[5px] bg-accent" :style="{ width: spentPct + '%' }" />
                    <div class="absolute inset-y-0 bg-accent/35" :style="{ left: spentPct + '%', width: forecastPct + '%' }" />
                    <div class="absolute -top-1 -ml-0.5 h-[18px] w-0.5 bg-danger" style="left: 100%" />
                </div>
                <div class="flex justify-between gap-3 text-[13px] text-ink-3">
                    <span>Prognose Monatsende <b class="text-ink">{{ euro(c.forecast) }}</b></span>
                    <span>Limit {{ euro(c.limit_eur) }} ({{ c.limit_usd }} $)</span>
                </div>
                <div class="flex h-14 items-end gap-[3px] border-t-[0.5px] border-line pt-1.5">
                    <div v-for="d in c.days" :key="d.day" class="flex-1 rounded-t-[2px]"
                        :class="d.day === today ? 'bg-accent/35' : d.day === peakDay && d.cost > 0 ? 'bg-warn' : 'bg-accent'"
                        :style="{ height: Math.max(2, (d.cost / maxDay) * 100) + '%' }"
                        :title="`${d.day}.: ${euro(d.cost)}`" />
                </div>
                <div v-if="c.peak" class="text-[13px] text-ink-3">Spitze am {{ c.peak.date }}: {{ euro(c.peak.cost) }} · meist {{ c.peak.type }} ({{ c.peak.calls }}×)</div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-[14px] bg-surface px-3.5 py-3">
                    <div class="text-[13px] font-semibold text-ink-3">Notbremse</div>
                    <div class="mt-0.5 text-[17px] font-bold" :class="c.blocked ? 'text-danger-ink' : ''">{{ c.blocked ? 'an' : 'aus' }}</div>
                    <div class="text-[12px] text-ink-3">greift bei leerem Guthaben</div>
                </div>
                <Link :href="route('admin.ai-logs.index', { status: 'skipped' })" class="rounded-[14px] bg-surface px-3.5 py-3 active:bg-surface-2">
                    <div class="text-[13px] font-semibold text-ink-3">Gebremst, 24 Std.</div>
                    <div class="mt-0.5 text-[17px] font-bold tabular-nums" :class="c.skipped_24h ? 'text-warn-ink' : ''">{{ c.skipped_24h }} {{ c.skipped_24h === 1 ? 'Aufruf' : 'Aufrufe' }}</div>
                    <div class="text-[12px] text-ink-3">Tages- oder Athletenbudget<template v-if="c.errors_24h"> · {{ c.errors_24h }} Fehler</template></div>
                </Link>
            </div>

            <AdminSection title="Wofür">
                <div class="grid grid-cols-[1fr_90px_64px] items-center gap-2.5 rounded-[14px] bg-surface p-3.5 text-[15px]">
                    <p v-if="!c.by_type?.length" class="col-span-3 m-0 text-ink-3">Diesen Monat noch nichts.</p>
                    <template v-for="t in c.by_type" :key="t.type">
                        <span class="truncate">{{ t.label }}</span>
                        <div class="h-2 rounded bg-accent" :style="{ width: Math.max(4, (t.cost / maxType) * 100) + '%' }" />
                        <span class="text-right tabular-nums">{{ euro(t.cost) }}</span>
                    </template>
                    <template v-if="c.rest">
                        <span class="text-ink-3">Rest ({{ c.rest.types }} Arten)</span>
                        <div class="h-2 rounded bg-line-strong" :style="{ width: Math.max(4, (c.rest.cost / maxType) * 100) + '%' }" />
                        <span class="text-right tabular-nums text-ink-3">{{ euro(c.rest.cost) }}</span>
                    </template>
                </div>
            </AdminSection>

            <AdminSection title="Wer">
                <div class="overflow-hidden rounded-[14px] bg-surface">
                    <p v-if="!c.by_user?.length" class="px-3.5 py-3 text-[15px] text-ink-3">Noch niemand.</p>
                    <Link v-for="(u, i) in c.by_user" :key="u.id" :href="route('admin.users.show', u.id)"
                        class="flex justify-between gap-3 px-3.5 py-[11px] active:bg-surface-2" :class="i < c.by_user.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                        <span class="text-[16px]">{{ short(u.name) }}</span>
                        <span class="text-[15px] tabular-nums text-ink-3">{{ euro(u.cost) }} · {{ u.calls }}{{ i === 0 ? ' Aufrufe' : '' }}</span>
                    </Link>
                </div>
            </AdminSection>

            <Link :href="route('admin.ai-logs.index')" class="px-1 text-[15px] font-semibold text-accent">Alle KI-Aufrufe ansehen</Link>
        </AdminPage>
    </AdminLayout>
</template>
