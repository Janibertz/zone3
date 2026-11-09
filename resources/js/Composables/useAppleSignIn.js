import axios from 'axios';
import { isNativeApp, nativePlugin } from '@/Composables/useNativeApp';

/**
 * „Mit Apple fortfahren“ (seit 09.11.2026).
 *
 * In der iOS-App das native Sheet über das Plugin `AppleSignIn` aus dem
 * App-Target (ios/App/App/AppleSignInPlugin.swift), im Browser die
 * Weiterleitung über /auth/apple. Ein App-Build ohne das Plugin bekommt
 * den Knopf nicht — die Web-Variante würde dort Safari öffnen.
 */
let plugin;

function signInPlugin() {
    if (plugin !== undefined) return plugin;
    plugin = nativePlugin('AppleSignIn');
    if (!plugin && isNativeApp()) {
        try {
            plugin = window.Capacitor?.registerPlugin?.('AppleSignIn') ?? null;
        } catch {
            plugin = null;
        }
    }
    return plugin;
}

/** 'native' | 'web' | null */
export async function appleSignInMode(webEnabled) {
    if (isNativeApp()) {
        const p = signInPlugin();
        if (!p) return null;
        try {
            return (await p.isAvailable())?.available ? 'native' : null;
        } catch {
            plugin = null;
            return null;
        }
    }
    return webEnabled ? 'web' : null;
}

/**
 * Natives Sheet → Server. Gibt die Zieladresse zurück, null bei Abbruch;
 * wirft mit der Meldung des Servers.
 */
export async function signInWithAppleNative() {
    const { data: { nonce } } = await axios.get(route('auth.apple.nonce'));
    const result = await signInPlugin().authorize({ nonce });
    if (!result || result.cancelled) return null;

    try {
        const { data } = await axios.post(route('auth.apple.native'), {
            identity_token: result.identityToken,
            given_name: result.givenName || null,
            family_name: result.familyName || null,
        });
        return data.redirect;
    } catch (e) {
        throw new Error(e?.response?.data?.message ?? 'Die Anmeldung mit Apple hat nicht geklappt.');
    }
}
