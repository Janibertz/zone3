<script setup>
/**
 * „Auf die Apple Watch“. In der iOS-App mit WorkoutKit (ab Build 20, iOS 17)
 * legt ein Tipp die Einheit direkt in die Training-App der Watch; sonst
 * bleibt es der Download der .workout-Datei (Fitness → Hinzufügen).
 *
 * Die Beschriftung kommt aus dem Slot; `done`/`busy` stehen im Slot zur
 * Verfügung, falls der Knopf nur ein Symbol zeigt.
 */
import { onMounted, ref } from 'vue';
import AppButton from '@/Components/UI/AppButton.vue';
import { scheduleOnWatch, watchAvailable } from '@/Composables/useWatchWorkouts';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    sessionId: { type: Number, required: true },
    /** Download-Link der .workout-Datei als Rückfall. */
    href:      { type: String, required: true },
});

const native = ref(false);
const busy = ref(false);
const done = ref(false);
const note = ref(null);

onMounted(async () => { native.value = await watchAvailable(); });

async function send() {
    busy.value = true;
    note.value = null;
    try {
        const res = await scheduleOnWatch(props.sessionId);
        if (res?.scheduled) {
            done.value = true;
            note.value = 'Steht in der Training-App deiner Watch.';
        } else if (res?.state === 'denied') {
            note.value = 'Erlauben unter iPhone-Einstellungen → Zone3 → Trainings.';
        } else {
            note.value = 'Die Watch hat nicht geantwortet.';
        }
    } catch (e) {
        note.value = e?.message || 'Hat nicht geklappt.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppButton v-if="native" v-bind="$attrs" :loading="busy" :aria-label="note || 'Auf die Apple Watch'" :title="note || 'Auf die Apple Watch'" @click="send">
        <slot :done="done" :busy="busy" :note="note" />
    </AppButton>
    <AppButton v-else v-bind="$attrs" :href="href" external download>
        <slot :done="false" :busy="false" :note="null" />
    </AppButton>
</template>
