/**
 * Läuft die Seite in der iOS-App (Capacitor-Hülle um zone3.run)?
 *
 * Capacitor spritzt seine Brücke auch in die geladene Live-Seite ein, darum
 * reicht `window.Capacitor`. Im Browser und in der Homescreen-App fehlt es.
 * Hier entscheidet sich alles, was in der App anders sein muss: kein
 * „Zum Home-Bildschirm"-Hinweis, Push über APNs statt Web-Push.
 */
export function isNativeApp() {
    if (typeof window === 'undefined') return false;
    try {
        return !!window.Capacitor?.isNativePlatform?.();
    } catch {
        return false;
    }
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
