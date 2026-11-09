import axios from 'axios';
import { isNativeApp } from '@/Composables/useNativeApp';

/**
 * Meldet einmal am Tag, worin Zone3 gerade läuft — für den Admin
 * (09.11.2026). Kein Gerät, keine Adresse, nur der Weg.
 */
export function usageVia() {
    if (isNativeApp()) return 'app';
    try {
        if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return 'homescreen';
    } catch { /* alter Browser */ }
    return 'browser';
}

export function reportUsage() {
    const via = usageVia();
    const today = new Date().toISOString().slice(0, 10);
    const key = `zone3_seen_${via}`;
    try {
        if (localStorage.getItem(key) === today) return;
    } catch { /* privat */ }

    axios.post(route('usage.seen'), { via })
        .then(() => { try { localStorage.setItem(key, today); } catch { /* privat */ } })
        .catch(() => { /* nicht wichtig genug für eine Meldung */ });
}
