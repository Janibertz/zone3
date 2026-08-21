import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Nach einem Deploy zeigte eine Seite eine weisse Flaeche.
 *
 * Die App laedt jede Seite als eigenen Chunk nach. Wer die Seite geoeffnet
 * hatte, waehrend Coolify die Dateien austauschte, hielt eine Huelle in der
 * Hand, die auf einen Dateinamen zeigt, den es in dem Moment nicht gab —
 * und der Service Worker reichte den Fehlschlag aus seinem Cache weiter.
 * Das Ergebnis war kein Hinweis, sondern gar nichts: "Failed to fetch
 * dynamically imported module", leerer Bildschirm, und ohne Neuladen von
 * Hand blieb es dabei.
 *
 * Schlaegt das Nachladen fehl, wird deshalb einmal neu geladen — das holt
 * die aktuelle Huelle mit den richtigen Dateinamen. Einmal, nicht in einer
 * Schleife: liegt es an etwas anderem als am Deploy, soll der Fehler
 * sichtbar bleiben statt die Seite endlos neu zu starten.
 */
const RELOAD_FLAG = 'zone3:chunk-reload';

async function resolveWithRetry(name) {
    try {
        const page = await resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        );
        sessionStorage.removeItem(RELOAD_FLAG);
        return page;
    } catch (error) {
        if (sessionStorage.getItem(RELOAD_FLAG)) {
            throw error;
        }

        sessionStorage.setItem(RELOAD_FLAG, '1');
        window.location.reload();

        // Das Neuladen laeuft; bis dahin nichts weiter versuchen.
        return new Promise(() => {});
    }
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: resolveWithRetry,
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
