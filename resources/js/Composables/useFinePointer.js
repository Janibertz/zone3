import { ref, onMounted } from 'vue';

/**
 * Maus statt Finger? Entscheidet, ob eine Wischgeste ueberhaupt erreichbar
 * ist — am Desktop gibt es kein Wischen, dort braucht dieselbe Aktion einen
 * sichtbaren Weg. Dieselbe Abfrage wie in `SwipeRow`.
 */
export function useFinePointer() {
    const fine = ref(false);
    onMounted(() => {
        fine.value = !(window.matchMedia?.('(pointer: coarse)').matches ?? ('ontouchstart' in window));
    });
    return fine;
}
