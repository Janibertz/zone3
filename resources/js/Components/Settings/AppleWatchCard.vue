<script setup>
/**
 * Apple Watch im Profil unter Verbindungen (nur iOS-App, ab Build 20).
 *
 * WorkoutKit legt Einheiten direkt in die Training-App der Watch. iOS fragt
 * einmal um Erlaubnis; danach plant die App beim Öffnen die nächsten sieben
 * Tage ein, solange der Schalter an ist (wie „Plan automatisch auf die Uhr“
 * bei Garmin). Mit Garmin erscheint die Karte nicht.
 */
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Toggle from '@/Components/UI/Toggle.vue';
import { clearWatch, requestWatchAuthorization, syncWatchWorkouts, watchAuthorization, watchAvailable } from '@/Composables/useWatchWorkouts';

const props = defineProps({
    state: { type: Object, required: true }, // { sync: bool }
});

const available = ref(null);
const auth = ref('unknown');
const busy = ref(false);
const note = ref(null);
const sync = ref(props.state?.sync ?? true);

onMounted(async () => {
    available.value = await watchAvailable();
    if (available.value) auth.value = await watchAuthorization();
});

async function runSync() {
    try {
        const res = await syncWatchWorkouts({ sync: true }, { force: true });
        if (res) {
            const total = (res.scheduled ?? 0) + (res.kept ?? 0);
            note.value = total
                ? `${total} ${total === 1 ? 'Einheit steht' : 'Einheiten stehen'} auf deiner Watch.`
                : 'In den nächsten sieben Tagen steht kein Lauf an.';
        }
    } catch (e) {
        note.value = 'Einplanen hat nicht geklappt. Zone3 versucht es beim nächsten Öffnen wieder.';
    }
}

async function allow() {
    busy.value = true;
    note.value = null;
    try {
        auth.value = await requestWatchAuthorization();
        if (auth.value === 'authorized' && sync.value) await runSync();
    } finally {
        busy.value = false;
    }
}

function toggleSync() {
    const enabled = !sync.value;
    sync.value = enabled;
    router.patch(route('apple-watch.sync'), { enabled }, {
        preserveScroll: true,
        onSuccess: async () => {
            note.value = null;
            if (enabled) {
                if (auth.value === 'authorized') await runSync();
            } else {
                await clearWatch();
                note.value = 'Die eingeplanten Einheiten sind von der Watch genommen.';
            }
        },
        onError: () => { sync.value = !enabled; },
    });
}
</script>

<template>
    <div>
        <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-ink text-canvas" aria-hidden="true">
                <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="6" width="12" height="12" rx="3" /><path d="M9 6l.6-3h4.8L15 6M9 18l.6 3h4.8l.6-3" /></svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-[17px] text-ink">Apple Watch</span>
                <span class="block text-[13px] leading-snug text-ink-3">
                    <template v-if="available === false">Erst mit der neuesten App aus TestFlight und iOS 17</template>
                    <template v-else-if="auth === 'authorized'">Trainings gehen direkt auf die Watch</template>
                    <template v-else-if="auth === 'denied'">In iOS nicht erlaubt</template>
                    <template v-else>Nicht freigegeben</template>
                </span>
            </span>
            <button v-if="available && auth !== 'authorized' && auth !== 'denied'" type="button" :disabled="busy"
                class="shrink-0 text-[17px] text-accent active:opacity-60 disabled:opacity-40" @click="allow">
                Freigeben
            </button>
        </div>

        <div v-if="available" class="space-y-2 px-4 pb-4 text-[13px] leading-snug text-ink-3">
            <p v-if="auth === 'denied'">Erlauben unter iPhone-Einstellungen → Zone3 → Trainings.</p>
            <template v-else-if="auth === 'authorized'">
                <Toggle :model-value="sync" label="Nächste 7 Tage automatisch" @update:model-value="toggleSync" />
                <p>{{ sync ? 'Beim Öffnen der App stehen deine Läufe der nächsten sieben Tage in der Training-App der Watch. Ändert sich der Plan, zieht die Watch beim nächsten Öffnen nach.' : 'Einzelne Einheiten schickst du mit „Auf die Apple Watch“ in den Details.' }}</p>
            </template>
            <p v-else>Zone3 legt deine Läufe mit Abschnitten und Pace-Alarm in die Training-App der Watch. Vereinstermine, Rennen und Radeinheiten nicht.</p>
            <p v-if="note" class="text-ink-2">{{ note }}</p>
        </div>
    </div>
</template>
