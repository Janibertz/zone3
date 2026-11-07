import { ref, onMounted } from 'vue';
import axios from 'axios';

/**
 * Web-Push auf diesem Gerät: Stand, einschalten, ausschalten.
 *
 * Eine Stelle für Profil, Onboarding und die Einsteiger-Checkliste. Auf dem
 * iPhone gibt es Push nur aus der Homescreen-App; in Safari ist
 * `supported` false.
 */
export function usePush(vapidPublicKey) {
    const supported  = ref(typeof window !== 'undefined' && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window);
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

    return { supported, permission, subscribed, loading, error, subscribe, unsubscribe };
}
