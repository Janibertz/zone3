import { computed, unref } from 'vue';
import { isIosApp } from '@/Composables/useNativeApp';

/**
 * Stand der Geräte & Apps aus `MoreController::devices()`, für die Kacheln
 * (`More/Devices.vue`) und die Zeile auf Mehr.
 *
 * Apple gibt es nur in der iOS-App (HealthKit, WorkoutKit) — im Browser nur,
 * solange eine Health-Einwilligung steht. Watch und Health sind eine Kachel.
 */
export function useDeviceStatus(devices) {
    const nativeApp = isIosApp();

    return computed(() => {
        const d = unref(devices) ?? {};
        const watchOn = nativeApp && d.apple_watch === true;
        const apple = (nativeApp || d.apple_health) ? (d.apple_health || watchOn) : null;
        const appleDetail = d.apple_health && watchOn ? 'Plan & Health'
            : watchOn ? 'Plan auf der Uhr'
            : d.apple_health ? 'Health verbunden' : '';

        const tiles = { garmin: !!d.garmin, coros: d.coros ?? null, polar: d.polar ?? null, strava: d.strava ?? null, apple, appleDetail };
        const connected = [tiles.garmin, tiles.coros, tiles.polar, tiles.strava, tiles.apple].filter(v => v === true).length;

        return { tiles, connected };
    });
}
