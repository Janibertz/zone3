import { ref, onMounted, onUnmounted } from 'vue';

const DISMISS_KEY = 'pwa_install_dismissed_at';
const DISMISS_DAYS = 30;

function getPWADisplayMode() {
    if (document.referrer.startsWith('android-app://')) return 'twa';
    if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return 'standalone';
    if (window.matchMedia('(display-mode: minimal-ui)').matches) return 'minimal-ui';
    if (window.matchMedia('(display-mode: fullscreen)').matches) return 'fullscreen';
    if (window.matchMedia('(display-mode: browser)').matches) return 'browser';
    return 'unknown';
}

function isIOSSafari() {
    const ua = navigator.userAgent;
    const isIOS = /iPhone|iPad|iPod/.test(ua) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPad OS 13+
    const isSafari = /WebKit/.test(ua) && !/CriOS|FxiOS|OPiOS|mercury/.test(ua);
    return isIOS && isSafari;
}

export function useInstallPrompt() {
    const deferredPrompt = ref(null);
    const isInstallable = ref(false);   // Android: native prompt available
    const isIOSHint = ref(false);       // iOS: show manual instructions
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
        isIOSHint.value = false;
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
        isIOSHint.value = false;
    }

    onMounted(() => {
        const mode = getPWADisplayMode();
        if (mode === 'standalone' || mode === 'twa' || mode === 'minimal-ui' || mode === 'fullscreen') {
            isInstalled.value = true;
            return;
        }

        // iOS: no beforeinstallprompt, show manual hint
        if (isIOSSafari() && !isDismissed()) {
            isIOSHint.value = true;
            return;
        }

        window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
        window.addEventListener('appinstalled', handleAppInstalled);
    });

    onUnmounted(() => {
        window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
        window.removeEventListener('appinstalled', handleAppInstalled);
    });

    return { isInstallable, isIOSHint, isInstalled, installApp, dismiss };
}
