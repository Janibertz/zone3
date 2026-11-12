<script setup>
import { isIosApp } from '@/Composables/useNativeApp';
import InputError from '@/Components/InputError.vue';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { appleSignInMode, signInWithAppleNative } from '@/Composables/useAppleSignIn';

// App Store (Guideline 2.2): in der iOS-App ist von keiner Beta die Rede.
const inApp = isIosApp();

/**
 * Startbildschirm (seit 09.11.2026, Muster aus einer anderen Lauf-App, von
 * Jan gewünscht): großes Foto, „Mit Apple fortfahren“ und der Link per Mail.
 * Der Passwort-Login bleibt für bestehende Konten dahinter.
 *
 * Das Foto (Unsplash-Lizenz) liegt in public/images/login/, damit beim
 * Öffnen keine Anfrage an Unsplash geht.
 */
const props = defineProps({
    canResetPassword: Boolean,
    status: String,
    appleWeb: Boolean,
    linkSent: String,
});

const page = usePage();
const appleError = computed(() => page.props.errors?.apple ?? null);

// ── Mit Apple ─────────────────────────────────────────────────────────
const appleMode = ref(null);
const appleBusy = ref(false);
const appleNativeError = ref(null);

onMounted(async () => {
    appleMode.value = await appleSignInMode(props.appleWeb);
});

async function apple() {
    appleNativeError.value = null;
    if (appleMode.value === 'web') {
        appleBusy.value = true;
        window.location.assign(route('auth.apple'));
        return;
    }
    appleBusy.value = true;
    try {
        const target = await signInWithAppleNative();
        // Ganze Seite: die Session ist neu, das CSRF-Token auch.
        if (target) window.location.assign(target);
        else appleBusy.value = false;
    } catch (e) {
        appleNativeError.value = e.message;
        appleBusy.value = false;
    }
}

// ── Link per Mail / Passwort ──────────────────────────────────────────
// Nach einem Fehler beim Passwort-Login steht das Formular wieder offen.
const panel = ref(page.props.errors?.password ? 'password' : (page.props.errors?.email || props.linkSent ? 'link' : null));

const linkForm = useForm({ email: '' });
function sendLink() {
    linkForm.post(route('login.link'), { preserveScroll: true });
}

const form = useForm({ email: '', password: '', remember: true });
function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Anmelden" />

    <div class="fixed inset-0 overflow-y-auto bg-black text-white">
        <!-- Foto -->
        <div
            class="pointer-events-none fixed inset-0 bg-cover bg-center"
            style="background-image: url('/images/login/hero.jpg'); background-color: #1c1c1e;"
            aria-hidden="true" />
        <div
            class="pointer-events-none fixed inset-0"
            style="background: linear-gradient(to bottom, rgba(0,0,0,0.35) 0%, rgba(0,0,0,0) 22%, rgba(0,0,0,0) 38%, rgba(0,0,0,0.75) 62%, #000 88%);"
            aria-hidden="true" />

        <div class="relative mx-auto flex min-h-full w-full max-w-md flex-col px-6 pt-safe-banner pb-safe-sheet">
            <Link href="/" class="mt-3 self-start" aria-label="Zone3">
                <Wordmark class="h-7 text-white" />
            </Link>

            <div class="flex-1 min-h-[28vh]" />

            <h1 class="text-[40px] font-extrabold leading-[1.05] tracking-[-0.035em]">
                Der Coach,<br>der mitläuft.
            </h1>
            <p class="mt-3 text-[17px] leading-snug text-white/75">
                Dein Trainingsplan, der sich nach dir und deiner Uhr richtet. {{ inApp ? 'Kostenlos.' : 'In der Beta kostenlos.' }}
            </p>

            <div v-if="status" class="mt-5 rounded-field bg-white/15 px-4 py-3 text-[15px] backdrop-blur">
                {{ status }}
            </div>
            <p v-if="appleError || appleNativeError" class="mt-5 rounded-field bg-danger/90 px-4 py-3 text-[15px]">
                {{ appleError || appleNativeError }}
            </p>

            <div class="mt-7 space-y-3">
                <!-- Apple verlangt Schwarz oder Weiß mit dem Apple-Zeichen. -->
                <button
                    v-if="appleMode"
                    type="button"
                    :disabled="appleBusy"
                    class="flex h-[54px] w-full items-center justify-center gap-2 rounded-full bg-white text-[19px] font-semibold text-black transition active:scale-[0.97] disabled:opacity-60"
                    @click="apple">
                    <svg class="-mt-0.5 h-[20px] w-[20px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/>
                    </svg>
                    Mit Apple fortfahren
                </button>

                <button
                    v-if="panel !== 'link'"
                    type="button"
                    class="flex h-[54px] w-full items-center justify-center gap-2 rounded-full text-[19px] font-semibold transition active:scale-[0.97]"
                    :class="appleMode ? 'bg-white/15 text-white backdrop-blur' : 'bg-white text-black'"
                    @click="panel = 'link'">
                    Mit E-Mail fortfahren
                </button>

                <!-- Link per Mail -->
                <div v-if="panel === 'link'" class="rounded-card bg-surface p-4 text-ink">
                    <template v-if="linkSent && !linkForm.isDirty">
                        <p class="text-[17px] font-semibold">Schau in dein Postfach.</p>
                        <p class="mt-1 text-[15px] text-ink-2">
                            Wir haben dir einen Link an <span class="font-medium text-ink">{{ linkSent }}</span> geschickt.
                            Er gilt 30 Minuten. Nichts angekommen? Schau im Spam-Ordner nach.
                        </p>
                        <button type="button" class="mt-3 text-[15px] font-medium text-accent active:opacity-60" @click="linkForm.email = linkSent; sendLink()">
                            Noch einmal schicken
                        </button>
                    </template>
                    <form v-else class="space-y-3" @submit.prevent="sendLink">
                        <div>
                            <label for="link-email" class="mb-1.5 block text-[15px] text-ink-2">Wir schicken dir einen Link zum Anmelden. Ohne Passwort.</label>
                            <input
                                id="link-email"
                                v-model="linkForm.email"
                                type="email"
                                required
                                autofocus
                                autocomplete="email"
                                inputmode="email"
                                placeholder="deine@email.de"
                                class="z-input" />
                            <InputError class="mt-1.5" :message="linkForm.errors.email || page.props.errors?.email" />
                        </div>
                        <button
                            type="submit"
                            :disabled="linkForm.processing"
                            class="flex h-[50px] w-full items-center justify-center rounded-full bg-accent text-[17px] font-semibold text-white transition active:scale-[0.97] disabled:opacity-40">
                            Link schicken
                        </button>
                    </form>
                </div>

                <!-- Passwort, für bestehende Konten -->
                <div v-if="panel === 'password'" class="rounded-card bg-surface p-4 text-ink">
                    <form class="space-y-3" @submit.prevent="submit">
                        <div>
                            <label for="email" class="mb-1.5 block text-[15px] text-ink-2">E-Mail</label>
                            <input id="email" v-model="form.email" type="email" required autocomplete="username" placeholder="deine@email.de" class="z-input" />
                            <InputError class="mt-1.5" :message="form.errors.email" />
                        </div>
                        <div>
                            <div class="mb-1.5 flex items-center justify-between">
                                <label for="password" class="block text-[15px] text-ink-2">Passwort</label>
                                <Link v-if="canResetPassword" :href="route('password.request')" class="text-[15px] text-accent active:opacity-60">Vergessen?</Link>
                            </div>
                            <input id="password" v-model="form.password" type="password" required autocomplete="current-password" placeholder="••••••••" class="z-input" />
                            <InputError class="mt-1.5" :message="form.errors.password" />
                        </div>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="flex h-[50px] w-full items-center justify-center rounded-full bg-accent text-[17px] font-semibold text-white transition active:scale-[0.97] disabled:opacity-40">
                            Anmelden
                        </button>
                    </form>
                </div>
            </div>

            <p class="mt-5 text-center text-[13px] leading-snug text-white/60">
                Mit der Anmeldung akzeptierst du unsere
                <Link :href="route('privacy')" class="underline underline-offset-2">Datenschutzerklärung</Link>.
                <Link :href="route('impressum')" class="ml-1 underline underline-offset-2">Impressum</Link>
            </p>
            <p v-if="panel !== 'password'" class="mt-3 text-center text-[15px] text-white/80">
                Schon ein Konto mit Passwort?
                <button type="button" class="font-semibold text-white underline underline-offset-2 active:opacity-60" @click="panel = 'password'">Anmelden</button>
            </p>
            <p v-else class="mt-3 text-center text-[15px] text-white/80">
                <button type="button" class="font-semibold text-white underline underline-offset-2 active:opacity-60" @click="panel = null">Ohne Passwort anmelden</button>
            </p>
        </div>
    </div>
</template>
