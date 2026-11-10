import { ref, onMounted } from 'vue';
import axios from 'axios';
import { isNativeApp, nativePlugin } from '@/Composables/useNativeApp';

/**
 * iOS-App: Push über APNs. Das Plugin meldet das Gerätetoken über ein
 * Ereignis; einmal registrieren reicht, danach schickt jeder Aufruf von
 * register() dasselbe Token (oder ein erneuertes) erneut.
 */
function nativeToken(plugin) {
    return new Promise((resolve, reject) => {
        const handles = [];
        const done = (fn, value) => {
            handles.forEach(h => h.then?.(x => x.remove()));
            fn(value);
        };
        handles.push(plugin.addListener('registration', t => done(resolve, t.value)));
        handles.push(plugin.addListener('registrationError', e => done(reject, new Error(e?.error ?? 'Registrierung fehlgeschlagen'))));
        plugin.register().catch(e => done(reject, e));
    });
}

const NATIVE_TOKEN_KEY = 'apns_token';

// Das Layout wird bei jedem Seitenwechsel neu aufgebaut. Das Token reicht
// einmal pro Laden der Seite, nicht bei jedem Tipp auf einen Tab.
let nativeTokenRefreshed = false;

function useNativePush(plugin) {
    const supported  = ref(true);
    const permission = ref('default');
    const subscribed = ref(false);
    const loading    = ref(false);
    const error      = ref('');
    // Bis iOS geantwortet hat, ist die Erlaubnis unbekannt. Ohne das stand
    // sie kurz auf 'default', und die Frage „Mitteilungen einschalten?“
    // blitzte bei jedem Seitenwechsel auf.
    const checked    = ref(false);

    async function sendToken() {
        const token = await nativeToken(plugin);
        await axios.post(route('push.native'), { token });
        try { localStorage.setItem(NATIVE_TOKEN_KEY, token); } catch {}
        subscribed.value = true;
    }

    onMounted(async () => {
        try {
            const { receive } = await plugin.checkPermissions();
            permission.value = receive === 'granted' ? 'granted' : receive === 'denied' ? 'denied' : 'default';
            // Erlaubt und nicht ausdrücklich abgemeldet: Token auffrischen.
            checked.value = true;
            if (receive === 'granted' && !nativeTokenRefreshed && localStorage.getItem(NATIVE_TOKEN_KEY) !== 'off') {
                nativeTokenRefreshed = true;
                await sendToken();
            } else if (receive === 'granted' && localStorage.getItem(NATIVE_TOKEN_KEY) !== 'off') {
                subscribed.value = true;
            }
        } catch {}
    });

    async function subscribe() {
        loading.value = true; error.value = '';
        try {
            const { receive } = await plugin.requestPermissions();
            permission.value = receive === 'granted' ? 'granted' : 'denied';
            if (receive !== 'granted') {
                error.value = 'Mitteilungen sind für Zone3 aus. Einschalten unter Einstellungen → Mitteilungen → Zone3.';
                return false;
            }
            await sendToken();
            return true;
        } catch (e) {
            error.value = 'Fehler: ' + (e?.message ?? 'Unbekannt');
            return false;
        } finally {
            loading.value = false;
        }
    }

    async function unsubscribe() {
        loading.value = true; error.value = '';
        try {
            const token = localStorage.getItem(NATIVE_TOKEN_KEY);
            if (token && token !== 'off') await axios.post(route('push.unsubscribe'), { endpoint: token });
            await plugin.unregister().catch(() => {});
            try { localStorage.setItem(NATIVE_TOKEN_KEY, 'off'); } catch {}
            subscribed.value = false;
        } catch (e) {
            error.value = 'Fehler: ' + (e?.message ?? '');
        } finally {
            loading.value = false;
        }
    }

    return { supported, permission, subscribed, loading, error, subscribe, unsubscribe, checked, native: true };
}

/**
 * Web-Push auf diesem Gerät: Stand, einschalten, ausschalten.
 *
 * Eine Stelle für Profil, Onboarding und die Einsteiger-Checkliste. Auf dem
 * iPhone gibt es Web-Push nur aus der Homescreen-App; in Safari ist
 * `supported` false.
 */
export function usePush(vapidPublicKey) {
    const plugin = nativePlugin('PushNotifications');
    if (plugin) return useNativePush(plugin);

    // In einem App-Build ohne Push-Plugin gibt es auch keinen Web-Push.
    const supported  = ref(!isNativeApp() && typeof window !== 'undefined' && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window);
    const permission = ref(typeof Notification !== 'undefined' ? Notification.permission : 'default');
    const subscribed = ref(false);
    const loading    = ref(false);
    const error      = ref('');

    onMounted(async () => {
        if (!supported.value) return;
        try {
            const reg = await navigator.serviceWorker.ready;
            subscribed.value = !!(await reg.pushManager.getSubscription());
        } catch {}
    });

    function keyBytes(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from([...atob(base64)].map(c => c.charCodeAt(0)));
    }

    async function subscribe() {
        loading.value = true; error.value = '';
        try {
            const result = await Notification.requestPermission();
            permission.value = result;
            if (result !== 'granted') { error.value = 'Benachrichtigungen wurden blockiert.'; return false; }
            const reg  = await navigator.serviceWorker.ready;
            const sub  = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(vapidPublicKey) });
            const json = sub.toJSON();
            await axios.post(route('push.subscribe'), { endpoint: json.endpoint, public_key: json.keys.p256dh, auth_token: json.keys.auth });
            subscribed.value = true;
            return true;
        } catch (e) {
            error.value = 'Fehler: ' + (e?.message ?? 'Unbekannt');
            return false;
        } finally {
            loading.value = false;
        }
    }

    async function unsubscribe() {
        loading.value = true; error.value = '';
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (sub) { await axios.post(route('push.unsubscribe'), { endpoint: sub.endpoint }); await sub.unsubscribe(); }
            subscribed.value = false;
        } catch (e) {
            error.value = 'Fehler: ' + (e?.message ?? '');
        } finally {
            loading.value = false;
        }
    }

    return { supported, permission, subscribed, loading, error, subscribe, unsubscribe, native: false };
}
