import { ref, watch, onMounted } from 'vue';

const isDark = ref(false);

function apply(dark) {
    if (dark) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

export function useDarkMode() {
    onMounted(() => {
        const stored = localStorage.getItem('zone3-dark-mode');
        if (stored !== null) {
            isDark.value = stored === 'true';
        } else {
            isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        apply(isDark.value);
    });

    watch(isDark, (val) => {
        apply(val);
        localStorage.setItem('zone3-dark-mode', String(val));
    });

    function toggle() {
        isDark.value = !isDark.value;
    }

    return { isDark, toggle };
}
