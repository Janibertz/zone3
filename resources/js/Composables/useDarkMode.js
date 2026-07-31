import { ref, computed, watch } from 'vue';

/**
 * Theme handling with three states: 'system' | 'light' | 'dark'.
 *
 * 'system' is the default and follows the OS setting live — that's what an
 * app is expected to do. An explicit choice is remembered in localStorage.
 *
 * The same key/logic runs inline in app.blade.php before paint to avoid a
 * flash of the wrong theme; keep both in sync.
 */

const STORAGE_KEY = 'zone3-theme';
const VALID       = ['system', 'light', 'dark'];

const media = typeof window !== 'undefined'
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;

function readStored() {
    if (typeof localStorage === 'undefined') return 'system';

    const stored = localStorage.getItem(STORAGE_KEY);
    if (VALID.includes(stored)) return stored;

    // Migration from the old boolean key.
    const legacy = localStorage.getItem('zone3-dark-mode');
    if (legacy === 'true')  return 'dark';
    if (legacy === 'false') return 'light';

    return 'system';
}

// Module-level singleton — every caller shares one source of truth.
const theme      = ref(readStored());
const systemDark = ref(media ? media.matches : false);

const isDark = computed(() =>
    theme.value === 'system' ? systemDark.value : theme.value === 'dark'
);

function apply() {
    if (typeof document === 'undefined') return;

    document.documentElement.classList.toggle('dark', isDark.value);

    // Keep the browser chrome (iOS status bar, Android address bar) in sync.
    document
        .querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', isDark.value ? '#09090e' : '#f9fafb');
}

if (media) {
    const onChange = (e) => { systemDark.value = e.matches; };
    // Safari < 14 only has the deprecated listener API.
    if (media.addEventListener) media.addEventListener('change', onChange);
    else media.addListener?.(onChange);
}

watch(isDark, apply, { immediate: typeof document !== 'undefined' });

watch(theme, (val) => {
    if (typeof localStorage === 'undefined') return;

    if (val === 'system') {
        localStorage.removeItem(STORAGE_KEY);
    } else {
        localStorage.setItem(STORAGE_KEY, val);
    }
    localStorage.removeItem('zone3-dark-mode'); // legacy key, no longer read
});

export function useDarkMode() {
    function setTheme(val) {
        if (VALID.includes(val)) theme.value = val;
    }

    /** Explicit light/dark switch — leaves 'system' behind. */
    function toggle() {
        theme.value = isDark.value ? 'light' : 'dark';
    }

    /** Cycles system → light → dark → system, for a three-way control. */
    function cycle() {
        const next = { system: 'light', light: 'dark', dark: 'system' };
        theme.value = next[theme.value] ?? 'system';
    }

    return { theme, isDark, systemDark, setTheme, toggle, cycle };
}
