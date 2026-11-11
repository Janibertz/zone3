/**
 * Läuft die Seite in einer der Apps (Capacitor-Hülle um zone3.run, iOS oder
 * Android)?
 *
 * Capacitor spritzt seine Brücke auch in die geladene Live-Seite ein, darum
 * reicht `window.Capacitor`. Im Browser und in der Homescreen-App fehlt es.
 * Hier entscheidet sich, was in jeder App anders ist als im Browser: kein
 * „Zum Home-Bildschirm"-Hinweis, kein Web-Push.
 *
 * Alles, was es nur auf dem iPhone gibt (Apple Health, Apple Watch, Widget,
 * Mit Apple anmelden), fragt `isIosApp()` — `isNativeApp()` ist seit der
 * Android-App (11.11.2026) auch dort wahr.
 */
export function isNativeApp() {
    if (typeof window === 'undefined') return false;
    try {
        return !!window.Capacitor?.isNativePlatform?.();
    } catch {
        return false;
    }
}

/** 'ios' | 'android' | null (Browser und Homescreen-App). */
export function nativePlatform() {
    if (!isNativeApp()) return null;
    try {
        const platform = window.Capacitor.getPlatform?.();
        return platform === 'ios' || platform === 'android' ? platform : null;
    } catch {
        return null;
    }
}

export function isIosApp() {
    return nativePlatform() === 'ios';
}

export function isAndroidApp() {
    return nativePlatform() === 'android';
}

/**
 * Ein natives Plugin, falls dieser App-Build es mitbringt. Die Seite kommt
 * live von zone3.run und ist oft neuer als die installierte App — ein Build
 * ohne das Plugin bekommt null statt eines Fehlers.
 */
export function nativePlugin(name) {
    if (!isNativeApp()) return null;
    try {
        if (!window.Capacitor.isPluginAvailable?.(name)) return null;
        return window.Capacitor.Plugins?.[name] ?? null;
    } catch {
        return null;
    }
}

/**
 * Universal Links: kommt ein Rückweg (Garmin, COROS, Strava) über
 * https://zone3.run/...callback in der App an, lädt die App genau diese
 * Seite. Ohne das öffnete iOS zwar die App, die WebView bliebe aber, wo sie
 * war, und der Code aus dem Rückweg ginge verloren.
 *
 * Das App-Plugin hält das Ereignis fest, bis jemand zuhört — auch beim
 * Kaltstart. Geladen wird nur eine Adresse auf zone3.run, und als ganze
 * Seite, damit der Callback serverseitig läuft.
 */
export function listenForAppLinks() {
    const app = nativePlugin('App');
    if (!app || window.__zone3AppLinkListener) return;
    window.__zone3AppLinkListener = true;

    app.addListener('appUrlOpen', ({ url } = {}) => {
        let target;
        try {
            target = new URL(url);
        } catch {
            return;
        }
        if (target.protocol !== 'https:' || target.host !== window.location.host) return;
        window.location.assign(target.pathname + target.search + target.hash);
    });
}
