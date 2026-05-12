import { ref } from 'vue';

// Module-level singleton — shared across all components
const isOpen = ref(false);

export function useCoachChat() {
    return {
        isOpen,
        open:   () => { isOpen.value = true; },
        close:  () => { isOpen.value = false; },
        toggle: () => { isOpen.value = !isOpen.value; },
    };
}
