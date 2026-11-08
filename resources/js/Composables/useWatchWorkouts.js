import axios from 'axios';
import { isNativeApp, nativePlugin } from '@/Composables/useNativeApp';

/**
 * Einheiten per WorkoutKit auf die Apple Watch (seit 08.11.2026).
 *
 * Das Plugin `WatchWorkouts` liegt im App-Target (ios/App/App/
 * WatchWorkoutsPlugin.swift), nicht in npm. Es rechnet nichts: das JSON
 * kommt fertig von `/apple-watch/workouts` (`WorkoutKitPayload`).
 *
 * Ein App-Build ohne das Plugin (vor Build 20) bekommt null, und die Seite
 * bleibt beim .workout-Download.
 */
const SYNC_KEY = 'apple_watch_synced_at';

let plugin;

function watchPlugin() {
    if (plugin !== undefined) return plugin;
    plugin = nativePlugin('WatchWorkouts');
    // Plugins aus dem App-Target stehen nicht immer in der Liste, die
    // Capacitor beim Laden einspritzt; registerPlugin findet sie trotzdem.
    if (!plugin && isNativeApp()) {
        try {
            plugin = window.Capacitor?.registerPlugin?.('WatchWorkouts') ?? null;
        } catch {
            plugin = null;
        }
    }
    return plugin;
}

/** Kann dieses Gerät Trainings auf die Watch legen? (App-Build, iOS 17, Watch gekoppelt egal) */
export async function watchAvailable() {
    const p = watchPlugin();
    if (!p) return false;
    try {
        return !!(await p.isAvailable())?.available;
    } catch {
        // Alter Build: das Plugin gibt es nicht.
        plugin = null;
        return false;
    }
}

export async function watchAuthorization() {
    const p = watchPlugin();
    if (!p) return 'unavailable';
    try {
        return (await p.authorizationState())?.state ?? 'unknown';
    } catch {
        return 'unavailable';
    }
}

export async function requestWatchAuthorization() {
    const p = watchPlugin();
    if (!p) return 'unavailable';
    return (await p.requestAuthorization())?.state ?? 'unknown';
}

/**
 * Die nächsten sieben Tage auf die Watch — beim Öffnen und bei jeder
 * Rückkehr in die App, höchstens alle 15 Minuten. Ohne Erlaubnis in iOS
 * passiert nichts; gefragt wird erst, wenn der Athlet es im Profil oder am
 * Knopf will.
 */
export async function syncWatchWorkouts(state, { force = false } = {}) {
    if (!state?.sync || !(await watchAvailable())) return null;
    if ((await watchAuthorization()) !== 'authorized') return null;

    try {
        const last = parseInt(localStorage.getItem(SYNC_KEY) ?? '0', 10);
        if (!force && Date.now() - last < 15 * 60 * 1000) return null;
        localStorage.setItem(SYNC_KEY, String(Date.now()));
    } catch { /* privat */ }

    const { data } = await axios.get(route('apple-watch.workouts'));
    if (!data?.enabled) return null;

    return watchPlugin().sync({ workouts: data.workouts ?? [] });
}

/** Eine Einheit sofort auf die Watch (Knopf). Fragt beim ersten Mal nach der Erlaubnis. */
export async function scheduleOnWatch(sessionId) {
    const { data } = await axios.get(route('apple-watch.workout', sessionId));
    return watchPlugin().schedule({ workout: data.workout });
}

/** Alles, was Zone3 eingeplant hat, von der Watch nehmen. */
export async function clearWatch() {
    const p = watchPlugin();
    if (!p) return;
    try {
        await p.removeAll();
        localStorage.removeItem(SYNC_KEY);
    } catch { /* nichts da */ }
}
