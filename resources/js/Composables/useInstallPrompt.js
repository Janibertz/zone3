import { ref, onMounted, onUnmounted } from 'vue';

const DISMISS_KEY = 'pwa_install_dismissed_at';
const DISMISS_DAYS = 30;

export function useInstallPrompt() {
    const deferredPrompt = ref(null);
    const isInstallable = ref(false);
    const isInstalled = ref(false);

    function isDismissed() {
        const ts = localStorage.getItem(DISMISS_KEY);
        if (!ts) return false;
        return Date.now() - parseInt(ts, 10) < DISMISS_DAYS * 86400000;
    }

    function handleBeforeInstallPrompt(e) {
        e.preventDefault();
        deferredPrompt.value = e;
        if (!isDismissed()) {
            isInstallable.value = true;
        }
    }

    function handleAppInstalled() {
        deferredPrompt.value = null;
        isInstallable.value = false;
        isInstalled.value = true;
    }

    async function installApp() {
        if (!deferredPrompt.value) return;
        deferredPrompt.value.prompt();
        const { outcome } = await deferredPrompt.value.userChoice;
        deferredPrompt.value = null;
        isInstallable.value = false;
        if (outcome === 'accepted') isInstalled.value = true;
    }

    function dismiss() {
        localStorage.setItem(DISMISS_KEY, String(Date.now()));
        isInstallable.value = false;
    }

    onMounted(() => {
        if (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone
        ) {
            isInstalled.value = true;
            return;
        }
        window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
        window.addEventListener('appinstalled', handleAppInstalled);
    });

    onUnmounted(() => {
        window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
        window.removeEventListener('appinstalled', handleAppInstalled);
    });

    return { isInstallable, isInstalled, installApp, dismiss };
}
