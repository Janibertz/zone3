/**
 * Läuft die Seite in der iOS-App (Capacitor-Hülle um zone3.run)?
 *
 * Capacitor spritzt seine Brücke auch in die geladene Live-Seite ein, darum
 * reicht `window.Capacitor`. Im Browser und in der Homescreen-App fehlt es.
 * Hier entscheidet sich alles, was in der App anders sein muss: kein
 * „Zum Home-Bildschirm"-Hinweis, kein Web-Push (WKWebView kennt keinen).
 */
export function isNativeApp() {
    if (typeof window === 'undefined') return false;
    try {
        return !!window.Capacitor?.isNativePlatform?.();
    } catch {
        return false;
    }
}
