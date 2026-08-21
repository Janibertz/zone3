import { ref } from 'vue';

// Module-level singleton — shared across all components
const isOpen = ref(false);

/**
 * Eine Frage, mit der der Chat aufgeht.
 *
 * `open()` öffnete bisher nur das Panel. Für „Erklär mir das" aus der
 * Zielprüfung reicht das nicht: der Athlet käme in einen leeren Chat und
 * müsste die Frage selbst noch einmal formulieren, die die Karte ihm gerade
 * gestellt hat. Wer hier etwas übergibt, schickt es direkt ab.
 */
const pendingMessage = ref(null);

export function useCoachChat() {
    return {
        isOpen,
        pendingMessage,
        open: (message = null) => {
            if (message) pendingMessage.value = message;
            isOpen.value = true;
        },
        close:  () => { isOpen.value = false; },
        toggle: () => { isOpen.value = !isOpen.value; },
        /** Vom Chat aufgerufen, sobald er die Frage übernommen hat. */
        takePending: () => {
            const m = pendingMessage.value;
            pendingMessage.value = null;
            return m;
        },
    };
}
