<script setup>
/**
 * Der Planbau Schritt für Schritt statt eines Ladekreisels (Jan,
 * 10.11.2026, Idee E aus dem Kotcha-Onboarding).
 *
 * Jede Zeile ist eine echte Station des Jobs (`PlanBuildProgress`), die
 * Zahlen kommen aus dem Kontext, mit dem der Plan gebaut wird. Was noch
 * nicht gemeldet ist, steht grau da — nichts läuft nach der Uhr weiter.
 */
import { computed } from 'vue';
import { fmtNum } from '@/Composables/useFormat';

const props = defineProps({
    progress:  { type: Object, default: null },
    coachName: { type: String, default: 'Dein Coach' },
});

const ORDER = ['start', 'form', 'weeks', 'model', 'check', 'save'];

const stage = computed(() => props.progress?.stage ?? 'start');
const f = computed(() => props.progress?.facts ?? {});

const steps = computed(() => {
    const at = ORDER.indexOf(stage.value);
    const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

    const formDetail = [
        f.value.vdot ? `VDOT ${fmtNum(f.value.vdot)}` : null,
        f.value.predicted ? `Prognose ${f.value.predicted}` : null,
        f.value.target ? `Ziel ${f.value.target.replace(' Std', '')}` : null,
    ].filter(Boolean).join(' · ');

    const weekDetail = f.value.days ? [
        `${f.value.days} Tage`,
        plural(f.value.runs ?? 0, 'Lauf', 'Läufe'),
        f.value.hard ? plural(f.value.hard, 'harte', 'harte') : null,
        f.value.phase,
    ].filter(Boolean).join(' · ') : '';

    const checkDetail = f.value.corrections === undefined ? ''
        : f.value.corrections === 0 ? 'Alles passt zu deinen Regeln'
        : `${plural(f.value.corrections, 'Stelle', 'Stellen')} angepasst`;

    const list = [
        { key: 'form',  title: 'Form einschätzen',  detail: formDetail || 'Aktivitäten, Bestzeiten und Schwelle' },
        { key: 'weeks', title: 'Wochen legen',      detail: weekDetail || 'Verfügbarkeit, Umfang und lange Läufe' },
        { key: 'model', title: 'Einheiten wählen',  detail: `${props.coachName} wählt die Bausteine und schreibt die Titel` },
        { key: 'check', title: 'Regeln prüfen',     detail: checkDetail || '80/20, Ruhetage, ein Lauf pro Tag' },
        { key: 'save',  title: 'Plan speichern',    detail: f.value.sessions ? plural(f.value.sessions, 'Einheit', 'Einheiten') : '' },
    ];

    return list.map((s) => {
        const i = ORDER.indexOf(s.key);
        // Die aktuelle Station läuft noch, außer sie ist sofort fertig
        // gemeldet worden (Form und Wochen kommen im selben Augenblick).
        const state = i < at ? 'done' : i === at ? 'active' : 'pending';
        return { ...s, state };
    });
});
</script>

<template>
    <div class="px-5 py-6">
        <p class="text-[17px] font-semibold text-ink">{{ coachName }} baut deinen Plan</p>
        <p class="mt-0.5 text-[13px] text-ink-3">Dauert meist ein bis zwei Minuten. Du kannst die Seite verlassen, wir sagen Bescheid.</p>

        <ol class="mt-5 space-y-0">
            <li v-for="(s, i) in steps" :key="s.key" class="relative flex gap-3 pb-5 last:pb-0">
                <!-- Verbindungslinie zur nächsten Station -->
                <span v-if="i < steps.length - 1" class="absolute left-[11px] top-7 h-[calc(100%-1.5rem)] w-0.5 rounded-full"
                    :class="s.state === 'done' ? 'bg-success' : 'bg-line-strong'" aria-hidden="true" />

                <span class="relative z-[1] flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                    :class="s.state === 'done' ? 'bg-success text-white'
                        : s.state === 'active' ? 'bg-accent/15 text-accent' : 'bg-surface-3 text-ink-3'">
                    <svg v-if="s.state === 'done'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                    <svg v-else-if="s.state === 'active'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    </svg>
                    <span v-else class="h-1.5 w-1.5 rounded-full bg-current" />
                </span>

                <span class="min-w-0 pt-0.5">
                    <span class="block text-[15px] font-semibold" :class="s.state === 'pending' ? 'text-ink-3' : 'text-ink'">{{ s.title }}</span>
                    <span v-if="s.detail" class="mt-0.5 block text-[13px] tabular-nums" :class="s.state === 'done' ? 'text-ink-2' : 'text-ink-3'">{{ s.detail }}</span>
                </span>
            </li>
        </ol>
    </div>
</template>
