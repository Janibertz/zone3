<script setup>
import { isIosApp } from '@/Composables/useNativeApp';
/**
 * Einsteiger-Checkliste, fest oben auf „Heute“ (Jan, 07.11.2026).
 *
 * Die Haken kommen vom Server ({@see App\Services\StarterChecklist}) — der
 * Athlet hakt nichts selbst ab. Zusammenklappen merkt sich das Gerät; ganz
 * schliessen geht erst, wenn alles erledigt ist und das Beta-Abzeichen da ist.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

// App Store (Guideline 2.2): in der iOS-App ist von keiner Beta die Rede.
const inApp = isIosApp();
const badge = inApp ? 'Pionier' : 'Beta';

const props = defineProps({
    checklist: { type: Object, required: true },
});

const emit = defineEmits(['action']);

const STORE_KEY = 'zone3.starter.collapsed';
function readCollapsed() {
    try { return localStorage.getItem(STORE_KEY) === '1'; } catch { return false; }
}
const collapsed = ref(readCollapsed());
function toggle() {
    collapsed.value = !collapsed.value;
    try { localStorage.setItem(STORE_KEY, collapsed.value ? '1' : '0'); } catch {}
}

/** Der nächste offene Punkt wird hervorgehoben — dort geht es weiter. */
const nextKey = computed(() => props.checklist.items.find(i => !i.done && !i.optional)?.key ?? null);

// Ring: r = 22 → Umfang 2πr.
const CIRC = 2 * Math.PI * 22;
const dash = computed(() => `${(props.checklist.percent / 100) * CIRC} ${CIRC}`);

const dismissing = ref(false);
function dismiss() {
    dismissing.value = true;
    router.post(route('starter-checklist.dismiss'), {}, {
        preserveScroll: true,
        onFinish: () => { dismissing.value = false; },
    });
}
</script>

<template>
    <section class="overflow-hidden rounded-card bg-surface" aria-label="Erste Schritte">
        <button type="button" class="flex w-full items-center gap-4 px-4 py-3.5 text-left transition-colors active:bg-surface-2"
            :aria-expanded="!collapsed" @click="toggle">
            <span class="relative h-14 w-14 shrink-0">
                <svg class="h-14 w-14 -rotate-90" viewBox="0 0 50 50" aria-hidden="true">
                    <circle cx="25" cy="25" r="22" fill="none" stroke-width="4" class="stroke-surface-3" />
                    <circle cx="25" cy="25" r="22" fill="none" stroke-width="4" stroke-linecap="round"
                        class="stroke-success transition-[stroke-dasharray] duration-500 ease-out-strong" :stroke-dasharray="dash" />
                </svg>
                <span class="absolute inset-0 flex items-center justify-center font-rounded text-[13px] font-bold tabular-nums text-ink">{{ checklist.percent }}%</span>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-[17px] font-semibold text-ink">{{ checklist.complete ? 'Alles erledigt' : 'Erste Schritte' }}</span>
                <span class="block text-[13px] text-ink-3">
                    {{ checklist.complete ? `Du hast dein ${badge}-Abzeichen` : `${checklist.done} von ${checklist.total} erledigt · am Ende wartet dein ${badge}-Abzeichen` }}
                </span>
            </span>
            <svg class="h-5 w-5 shrink-0 text-ink-3 transition-transform duration-200 ease-in-out-strong" :class="collapsed ? '' : 'rotate-180'"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>

        <div v-if="!collapsed" class="border-t border-line">
            <!-- Geschafft: das Abzeichen, dann schliessen. -->
            <div v-if="checklist.complete" class="flex flex-col items-center px-6 py-6 text-center">
                <span class="flex h-16 w-16 items-center justify-center rounded-full bg-accent text-white">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4l-5.2 2.7 1-5.8L3.5 9.2l5.9-.9L12 3z" /></svg>
                </span>
                <p class="mt-3 text-[20px] font-bold text-ink">{{ inApp ? 'Pionier' : 'Beta-Tester' }}</p>
                <p class="mt-1 max-w-xs text-[15px] leading-snug text-ink-3">Danke, dass du Zone3 als einer der Ersten {{ inApp ? 'nutzt' : 'testest' }}. Das Abzeichen steht ab jetzt unter „Mehr“.</p>
                <button type="button" :disabled="dismissing" class="mt-4 text-[17px] font-semibold text-accent active:opacity-60 disabled:opacity-40" @click="dismiss">Schließen</button>
            </div>

            <ul v-else class="z-group">
                <li v-for="item in checklist.items" :key="item.key">
                    <component :is="item.target.href ? Link : 'button'" :href="item.target.href" :type="item.target.href ? null : 'button'"
                        class="relative flex min-h-[52px] w-full items-center gap-3 px-4 py-2 text-left transition-colors active:bg-surface-2"
                        :class="item.key === nextKey ? 'bg-accent-soft/40' : ''"
                        @click="item.target.action && emit('action', item.target.action)">
                        <span v-if="item.key === nextKey" class="absolute inset-y-0 left-0 w-1 bg-accent" aria-hidden="true" />
                        <span class="min-w-0 flex-1 text-[17px]" :class="item.done ? 'text-ink-3' : (item.key === nextKey ? 'font-semibold text-ink' : 'text-ink')">
                            {{ item.label }}<span v-if="item.optional" class="text-ink-3"> (optional)</span>
                        </span>
                        <span v-if="item.done" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-success text-white" aria-label="erledigt">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </span>
                        <svg v-else class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </component>
                </li>
            </ul>
        </div>
    </section>
</template>
