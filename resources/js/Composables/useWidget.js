import axios from 'axios';
import { isIosApp, nativePlugin } from '@/Composables/useNativeApp';

/**
 * iOS-Widget (11.11.2026, Mockup „Zone3 Widget“).
 *
 * Das Widget läuft ohne die Session der App und holt seine Daten über
 * `/widget/{token}.json`. Die Seite holt beim Öffnen die Adresse
 * (`POST /widget/token`) und gibt sie dem Plugin `ZoneWidget` aus dem
 * App-Target (ios/App/App/ZoneWidgetPlugin.swift), das sie im geteilten
 * Schlüsselbund ablegt und das Widget neu laden lässt. Ein App-Build ohne
 * das Plugin bekommt null, und es passiert nichts.
 */
const URL_KEY = 'zone3_widget_url';

let plugin;

function widgetPlugin() {
    if (plugin !== undefined) return plugin;
    plugin = nativePlugin('ZoneWidget');
    if (!plugin && isIosApp()) {
        try {
            plugin = window.Capacitor?.registerPlugin?.('ZoneWidget') ?? null;
        } catch {
            plugin = null;
        }
    }
    return plugin;
}

function storedUrl(userId) {
    try {
        const saved = JSON.parse(localStorage.getItem(URL_KEY) || 'null');
        return saved?.user === userId ? saved.url : null;
    } catch {
        return null;
    }
}

/** Beim Öffnen und bei jeder Rückkehr in die App. */
export async function syncWidget(userId) {
    const p = widgetPlugin();
    if (!p || !userId) return;

    let url = storedUrl(userId);
    if (!url) {
        const { data } = await axios.post('/widget/token');
        url = data?.url;
        if (!url) return;
        try { localStorage.setItem(URL_KEY, JSON.stringify({ user: userId, url })); } catch { /* privat */ }
    }

    try {
        await p.setFeed({ url });
    } catch {
        // Alter Build ohne Plugin.
        plugin = null;
    }
}

/** Nach einer Änderung an heute: das Widget holt sich die Daten neu. */
export function reloadWidget() {
    widgetPlugin()?.reload?.().catch(() => {});
}

/** Beim Abmelden: das Widget zeigt niemandem mehr die Einheiten. */
export function clearWidget() {
    try { localStorage.removeItem(URL_KEY); } catch { /* privat */ }
    widgetPlugin()?.clear?.().catch(() => {});
}
