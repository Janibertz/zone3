<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage, Link } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { ref, computed, nextTick, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    mustVerifyEmail:       Boolean,
    status:                String,
    runnerProfile:         Object,
    stravaConnected:       Boolean,
    stravaAccount:         Object,
    notificationSettings:  Object,
    vapidPublicKey:        String,
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const activeTab = ref('personal');

const tabs = [
    {
        key: 'personal',
        label: 'Persönlich',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />'
    },
    {
        key: 'athlete',
        label: 'Athletenprofil',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />'
    },
    {
        key: 'security',
        label: 'Sicherheit',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />'
    },
    {
        key: 'account',
        label: 'Konto',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />'
    },
    {
        key: 'notifications',
        label: 'Benachrichtigungen',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />'
    },
];

// ── Push Notification state ─────────────────────────────────────────────────
const pushSupported    = ref('Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window);
const pushPermission   = ref(typeof Notification !== 'undefined' ? Notification.permission : 'default'); // 'default' | 'granted' | 'denied'
const pushSubscribed   = ref(false);
const pushLoading      = ref(false);
const pushError        = ref('');
const pushTestSent     = ref(false);

const notifSettings = ref({
    wellbeing_reminder_time: props.notificationSettings?.wellbeing_reminder_time ?? '08:00',
    notify_threshold_pace:   props.notificationSettings?.notify_threshold_pace ?? true,
    notify_plan_updated:     props.notificationSettings?.notify_plan_updated ?? true,
});
const notifSaving = ref(false);
const notifSaved  = ref(false);

// Check existing subscription on mount
onMounted(async () => {
    if (!pushSupported.value) return;
    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        pushSubscribed.value = !!sub;
    } catch {}
});

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

async function subscribePush() {
    pushLoading.value = true;
    pushError.value   = '';
    try {
        const permission = await Notification.requestPermission();
        pushPermission.value = permission;
        if (permission !== 'granted') {
            pushError.value = 'Benachrichtigungen wurden blockiert. Bitte erlaube sie in den Browser-Einstellungen.';
            return;
        }
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(props.vapidPublicKey),
        });
        const json = sub.toJSON();
        await axios.post(route('push.subscribe'), {
            endpoint:   json.endpoint,
            public_key: json.keys.p256dh,
            auth_token: json.keys.auth,
        });
        pushSubscribed.value = true;
    } catch (e) {
        pushError.value = 'Fehler beim Aktivieren: ' + (e?.message ?? 'Unbekannter Fehler');
    } finally {
        pushLoading.value = false;
    }
}

async function unsubscribePush() {
    pushLoading.value = true;
    pushError.value   = '';
    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            await axios.post(route('push.unsubscribe'), { endpoint: sub.endpoint });
            await sub.unsubscribe();
        }
        pushSubscribed.value = false;
    } catch (e) {
        pushError.value = 'Fehler beim Deaktivieren: ' + (e?.message ?? '');
    } finally {
        pushLoading.value = false;
    }
}

async function sendTestPush() {
    try {
        await axios.post(route('push.test'));
        pushTestSent.value = true;
        setTimeout(() => { pushTestSent.value = false; }, 3000);
    } catch (e) {
        pushError.value = e?.response?.data?.error ?? 'Test fehlgeschlagen.';
    }
}

async function saveNotifSettings() {
    notifSaving.value = true;
    try {
        await axios.patch(route('push.settings'), notifSettings.value);
        notifSaved.value = true;
        setTimeout(() => { notifSaved.value = false; }, 2500);
    } finally {
        notifSaving.value = false;
    }
}

// ── Personal info form ──────────────────────────────────────────────────────
const profileForm = useForm({
    name: user.value.name,
    email: user.value.email,
});

// ── Password form ───────────────────────────────────────────────────────────
const passwordInput = ref(null);
const currentPasswordInput = ref(null);
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => {
            if (passwordForm.errors.password) {
                passwordForm.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (passwordForm.errors.current_password) {
                passwordForm.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};

// ── Athlete / Runner profile form ───────────────────────────────────────────
function formatPace(minutes) {
    if (!minutes) return '';
    const m = Math.floor(minutes);
    const s = Math.round((minutes - m) * 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}

const athleteForm = useForm({
    threshold_heart_rate: props.runnerProfile?.threshold_heart_rate ? String(props.runnerProfile.threshold_heart_rate) : '',
    max_heart_rate: props.runnerProfile?.max_heart_rate ? String(props.runnerProfile.max_heart_rate) : '',
    threshold_speed: props.runnerProfile?.threshold_speed ? formatPace(props.runnerProfile.threshold_speed) : '',
});

const submitAthlete = () => {
    athleteForm.post(route('runner.profile.store'), { preserveScroll: true });
};

const paceZones = computed(() => props.runnerProfile?.pace_zones ?? []);
const athleteSaved = computed(() => props.status === 'athlete-saved');

const zoneColors = [
    'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-500/10 dark:border-blue-500/30 dark:text-blue-300',
    'bg-green-50 border-green-200 text-green-700 dark:bg-green-500/10 dark:border-green-500/30 dark:text-green-300',
    'bg-yellow-50 border-yellow-200 text-yellow-700 dark:bg-yellow-500/10 dark:border-yellow-500/30 dark:text-yellow-300',
    'bg-orange-50 border-orange-200 text-orange-700 dark:bg-orange-500/10 dark:border-orange-500/30 dark:text-orange-300',
    'bg-red-50 border-red-200 text-red-700 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-300',
];

// ── Delete account form ─────────────────────────────────────────────────────
const confirmingDeletion = ref(false);
const deletePasswordInput = ref(null);
const deleteForm = useForm({ password: '' });

const confirmDeletion = () => {
    confirmingDeletion.value = true;
    nextTick(() => deletePasswordInput.value?.focus());
};

const deleteAccount = () => {
    deleteForm.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeDeleteModal(),
        onError: () => deletePasswordInput.value?.focus(),
        onFinish: () => deleteForm.reset(),
    });
};

const closeDeleteModal = () => {
    confirmingDeletion.value = false;
    deleteForm.clearErrors();
    deleteForm.reset();
};

// ── Strava disconnect confirmation ──────────────────────────────────────────
const confirmStravaDisconnect = ref(false);
const stravaDisconnectForm = useForm({});

const disconnectStrava = () => {
    stravaDisconnectForm.delete(route('strava.disconnect'), {
        onSuccess: () => { confirmStravaDisconnect.value = false; },
    });
};
</script>

<template>
    <Head title="Profil" />
    <AuthenticatedLayout>
        <div class="max-w-3xl mx-auto px-3 sm:px-6 py-4 sm:py-8">

            <!-- Page header -->
            <div class="mb-5 sm:mb-7">
                <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">Mein Profil</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Persönliche Daten & Trainingseinstellungen</p>
            </div>

            <!-- Tab bar — scrollable on mobile -->
            <div class="flex gap-1 bg-gray-100 dark:bg-slate-800 rounded-2xl p-1 mb-4 sm:mb-6 overflow-x-auto" style="-webkit-overflow-scrolling:touch;scrollbar-width:none;">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    @click="activeTab = tab.key"
                    class="shrink-0 flex items-center gap-2 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150 whitespace-nowrap"
                    :class="activeTab === tab.key
                        ? 'bg-white dark:bg-slate-700 text-gray-900 dark:text-white shadow-sm'
                        : 'text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="tab.icon" />
                    {{ tab.label }}
                </button>
            </div>

            <!-- Tab content card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden">

                <!-- ══ PERSÖNLICH ══ -->
                <template v-if="activeTab === 'personal'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900">Persönliche Daten</h2>
                        <p class="mt-0.5 text-sm text-gray-500">Name und E-Mail-Adresse deines Kontos</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <!-- Success notice -->
                        <div v-if="props.status === 'profile-information-updated'" class="mb-5 flex items-center gap-2 rounded-xl bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Profil erfolgreich gespeichert.
                        </div>

                        <form @submit.prevent="profileForm.patch(route('profile.update'))" class="space-y-5">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Name</label>
                                <input
                                    id="name"
                                    v-model="profileForm.name"
                                    type="text"
                                    required
                                    autofocus
                                    autocomplete="name"
                                    class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                />
                                <InputError class="mt-1.5" :message="profileForm.errors.name" />
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">E-Mail</label>
                                <input
                                    id="email"
                                    v-model="profileForm.email"
                                    type="email"
                                    required
                                    autocomplete="username"
                                    class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                />
                                <InputError class="mt-1.5" :message="profileForm.errors.email" />
                            </div>

                            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-700">
                                E-Mail noch nicht verifiziert.
                                <Link :href="route('verification.send')" method="post" as="button" class="underline ml-1">Erneut senden</Link>
                                <span v-if="props.status === 'verification-link-sent'" class="block mt-1 text-green-600">Bestätigungslink wurde gesendet.</span>
                            </div>

                            <div class="flex items-center justify-end pt-2">
                                <button
                                    type="submit"
                                    :disabled="profileForm.processing"
                                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm"
                                >
                                    <svg v-if="profileForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Speichern
                                </button>
                            </div>
                        </form>
                    </div>
                </template>

                <!-- ══ ATHLETENPROFIL ══ -->
                <template v-else-if="activeTab === 'athlete'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900">Athletenprofil</h2>
                        <p class="mt-0.5 text-sm text-gray-500">Herzfrequenz- und Schwellenwerte für deine Trainingszonen</p>
                    </div>
                    <div class="p-4 sm:p-6 space-y-5 sm:space-y-6">
                        <!-- Success -->
                        <div v-if="athleteSaved" class="flex items-center gap-2 rounded-xl bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Athletenprofil erfolgreich gespeichert. Zonen wurden neu berechnet.
                        </div>

                        <form @submit.prevent="submitAthlete" class="space-y-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                        Schwellen-HF <span class="text-gray-400 font-normal">(LTHR, bpm)</span>
                                    </label>
                                    <input
                                        v-model="athleteForm.threshold_heart_rate"
                                        type="number"
                                        min="100" max="220"
                                        placeholder="z.B. 165"
                                        required
                                        class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                    />
                                    <p class="mt-1 text-xs text-gray-400">Lactate Threshold Heart Rate</p>
                                    <InputError class="mt-1" :message="athleteForm.errors.threshold_heart_rate" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                        Maximale HF <span class="text-gray-400 font-normal">(bpm)</span>
                                    </label>
                                    <input
                                        v-model="athleteForm.max_heart_rate"
                                        type="number"
                                        min="100" max="220"
                                        placeholder="z.B. 195"
                                        required
                                        class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                    />
                                    <p class="mt-1 text-xs text-gray-400">Maximale Herzfrequenz</p>
                                    <InputError class="mt-1" :message="athleteForm.errors.max_heart_rate" />
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                    Schwellen-Pace <span class="text-gray-400 font-normal">(min:sek/km)</span>
                                </label>
                                <input
                                    v-model="athleteForm.threshold_speed"
                                    type="text"
                                    placeholder="z.B. 5:30"
                                    pattern="[0-9]{1,2}:[0-9]{2}"
                                    required
                                    class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                />
                                <p class="mt-1 text-xs text-gray-400">Die Pace, die du für ~60 min halten kannst</p>
                                <InputError class="mt-1" :message="athleteForm.errors.threshold_speed" />
                            </div>

                            <div class="flex items-center justify-end pt-1">
                                <button
                                    type="submit"
                                    :disabled="athleteForm.processing"
                                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm"
                                >
                                    <svg v-if="athleteForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Speichern & Zonen berechnen
                                </button>
                            </div>
                        </form>

                        <!-- Pace zones display -->
                        <div v-if="paceZones.length > 0" class="border-t border-gray-100 pt-6">
                            <h3 class="text-sm font-semibold text-gray-700 mb-3">Deine Laufzonen</h3>
                            <div class="space-y-2">
                                <div
                                    v-for="(zone, idx) in paceZones"
                                    :key="idx"
                                    class="flex items-center justify-between rounded-xl border px-4 py-3"
                                    :class="zoneColors[idx] ?? 'bg-gray-50 border-gray-200 text-gray-700'"
                                >
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-bold opacity-60">Z{{ idx + 1 }}</span>
                                        <span class="text-sm font-semibold">{{ zone.name }}</span>
                                    </div>
                                    <span class="text-sm font-mono tabular-nums">{{ zone.min_pace }} – {{ zone.max_pace }} min/km</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="border-t border-gray-100 pt-6">
                            <div class="rounded-xl bg-gray-50 dark:bg-slate-800 border border-dashed border-gray-200 dark:border-slate-600 px-5 py-6 text-center">
                                <p class="text-sm text-gray-400 dark:text-slate-500">Speichere dein Profil um deine Laufzonen zu berechnen.</p>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ══ SICHERHEIT ══ -->
                <template v-else-if="activeTab === 'security'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900">Passwort ändern</h2>
                        <p class="mt-0.5 text-sm text-gray-500">Nutze ein sicheres, einzigartiges Passwort</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <form @submit.prevent="updatePassword" class="space-y-5">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Aktuelles Passwort</label>
                                <input
                                    id="current_password"
                                    ref="currentPasswordInput"
                                    v-model="passwordForm.current_password"
                                    type="password"
                                    autocomplete="current-password"
                                    class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                />
                                <InputError class="mt-1.5" :message="passwordForm.errors.current_password" />
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Neues Passwort</label>
                                <input
                                    id="password"
                                    ref="passwordInput"
                                    v-model="passwordForm.password"
                                    type="password"
                                    autocomplete="new-password"
                                    class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                />
                                <InputError class="mt-1.5" :message="passwordForm.errors.password" />
                            </div>

                            <div>
                                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Passwort bestätigen</label>
                                <input
                                    id="password_confirmation"
                                    v-model="passwordForm.password_confirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors"
                                />
                                <InputError class="mt-1.5" :message="passwordForm.errors.password_confirmation" />
                            </div>

                            <div class="flex items-center gap-4 pt-1">
                                <button
                                    type="submit"
                                    :disabled="passwordForm.processing"
                                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm"
                                >
                                    <svg v-if="passwordForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Passwort ändern
                                </button>
                                <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                                    <span v-if="passwordForm.recentlySuccessful" class="text-sm text-green-600">Passwort gespeichert.</span>
                                </Transition>
                            </div>
                        </form>
                    </div>
                </template>

                <!-- ══ KONTO ══ -->
                <template v-else-if="activeTab === 'account'">

                    <!-- Strava Verbindung -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Strava Verbindung</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Verwalte deine Strava-Integration</p>
                    </div>
                    <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-slate-800">
                        <!-- Verbunden -->
                        <div v-if="props.stravaConnected" class="flex items-start justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 rounded-xl bg-orange-100 dark:bg-orange-500/15 flex items-center justify-center text-xl flex-shrink-0">🔗</div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                        Verbunden als <span class="text-orange-600 dark:text-orange-400">{{ props.stravaAccount?.username }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                        Zuletzt synchronisiert: {{ props.stravaAccount?.last_synced_at ?? 'Noch nie' }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="inline-flex items-center gap-1 rounded-full bg-green-100 dark:bg-green-500/15 px-2.5 py-1 text-xs font-medium text-green-700 dark:text-green-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Aktiv
                                </span>
                            </div>
                        </div>
                        <!-- Nicht verbunden -->
                        <div v-else class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-xl flex-shrink-0">🔗</div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">Nicht verbunden</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Verbinde Strava um deine Läufe zu importieren</p>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <Link
                                v-if="!props.stravaConnected"
                                href="/strava/connect"
                                class="inline-flex items-center gap-2 rounded-xl bg-orange-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-orange-600 transition-colors shadow-sm"
                            >
                                Mit Strava verbinden
                            </Link>
                            <template v-else>
                                <Link
                                    href="/strava/connect"
                                    class="inline-flex items-center gap-2 rounded-xl bg-gray-100 dark:bg-slate-800 px-5 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-200 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors"
                                >
                                    Konto wechseln
                                </Link>
                                <button
                                    type="button"
                                    @click="confirmStravaDisconnect = true"
                                    class="inline-flex items-center gap-2 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-5 py-2.5 text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-500/20 transition-colors"
                                >
                                    Strava trennen
                                </button>
                            </template>
                        </div>

                        <!-- Hinweis was gelöscht wird -->
                        <p v-if="props.stravaConnected" class="mt-3 text-xs text-gray-400 dark:text-slate-500">
                            Beim Trennen werden die Verbindung und alle importierten Aktivitäten aus Zone3 gelöscht. Deine Strava-Daten bleiben auf Strava erhalten.
                        </p>
                    </div>

                    <!-- Onboarding reset -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Onboarding wiederholen</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Athletenprofil und Ziel erneut einrichten</p>
                    </div>
                    <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-slate-800">
                        <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">
                            Du kannst den Einrichtungs-Assistenten jederzeit erneut durchlaufen, um dein Athletenprofil oder dein Ziel anzupassen.
                        </p>
                        <Link
                            :href="route('onboarding.reset')"
                            method="post"
                            as="button"
                            class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors shadow-sm"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                            Onboarding neu starten
                        </Link>
                    </div>

                    <!-- Account deletion -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900">Konto löschen</h2>
                        <p class="mt-0.5 text-sm text-gray-500">Alle Daten werden unwiderruflich gelöscht</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div class="rounded-xl bg-red-50 border border-red-200 px-5 py-4 mb-6">
                            <p class="text-sm text-red-700">
                                Sobald dein Konto gelöscht wird, werden alle Daten unwiderruflich entfernt.
                                Sichere alle wichtigen Informationen bevor du fortfährst.
                            </p>
                        </div>
                        <button
                            @click="confirmDeletion"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700 transition-colors shadow-sm"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                            </svg>
                            Konto löschen
                        </button>
                    </div>
                </template>

                <!-- ── Benachrichtigungen ───────────────────────────────────── -->
                <template v-else-if="activeTab === 'notifications'">

                    <!-- Push aktivieren/deaktivieren -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Web Push Benachrichtigungen</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Erhalte Hinweise direkt im Browser — auch wenn die App nicht geöffnet ist</p>
                    </div>

                    <div class="p-4 sm:p-6 space-y-5">

                        <!-- Browser not supported -->
                        <div v-if="!pushSupported" class="rounded-xl bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-slate-700 px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                            Dein Browser unterstützt keine Push-Benachrichtigungen.
                        </div>

                        <template v-else>
                            <!-- Permission denied -->
                            <div v-if="pushPermission === 'denied'" class="rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                                Benachrichtigungen sind in deinem Browser blockiert. Bitte erlaube sie in den Browser-Einstellungen und lade die Seite neu.
                            </div>

                            <!-- Subscribe / Unsubscribe -->
                            <div class="flex items-center justify-between gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-slate-800">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ pushSubscribed ? 'Push aktiv ✓' : 'Push deaktiviert' }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                        {{ pushSubscribed ? 'Du erhältst Benachrichtigungen auf diesem Gerät' : 'Noch keine Benachrichtigungen auf diesem Gerät' }}
                                    </p>
                                </div>
                                <button
                                    @click="pushSubscribed ? unsubscribePush() : subscribePush()"
                                    :disabled="pushLoading || pushPermission === 'denied'"
                                    class="shrink-0 inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition-colors disabled:opacity-50"
                                    :class="pushSubscribed
                                        ? 'bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-slate-300 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400'
                                        : 'bg-indigo-600 text-white hover:bg-indigo-700'"
                                >
                                    <svg v-if="pushLoading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    {{ pushSubscribed ? 'Deaktivieren' : 'Aktivieren' }}
                                </button>
                            </div>

                            <!-- Test button -->
                            <div v-if="pushSubscribed" class="flex items-center gap-3">
                                <button @click="sendTestPush"
                                    class="inline-flex items-center gap-2 rounded-xl bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 px-3 py-2 text-sm font-medium transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                                    Test-Benachrichtigung senden
                                </button>
                                <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                    <span v-if="pushTestSent" class="text-sm text-green-600 dark:text-green-400 font-medium">Gesendet ✓</span>
                                </Transition>
                            </div>

                            <!-- Error -->
                            <p v-if="pushError" class="text-sm text-red-600 dark:text-red-400">{{ pushError }}</p>
                        </template>
                    </div>

                    <!-- Einstellungen -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-t border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Einstellungen</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Diese Einstellungen gelten sobald Push aktiviert ist</p>
                    </div>

                    <div class="p-4 sm:p-6 space-y-5">

                        <!-- Wellbeing Erinnerungszeit -->
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Wellbeing-Erinnerung</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Uhrzeit zu der du erinnert wirst, falls du das Wellbeing noch nicht eingetragen hast</p>
                            </div>
                            <input
                                v-model="notifSettings.wellbeing_reminder_time"
                                type="time"
                                class="shrink-0 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Schwellenpace -->
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Schwellenpace aktualisiert</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Benachrichtigung wenn die KI deine Schwellenpace neu berechnet hat</p>
                            </div>
                            <button
                                @click="notifSettings.notify_threshold_pace = !notifSettings.notify_threshold_pace"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                :class="notifSettings.notify_threshold_pace ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-slate-700'"
                            >
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200"
                                    :class="notifSettings.notify_threshold_pace ? 'translate-x-5' : 'translate-x-0'" />
                            </button>
                        </div>

                        <!-- KI-Plan -->
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">KI-Plan aktualisiert</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Benachrichtigung wenn dein Trainingsplan neu berechnet wurde</p>
                            </div>
                            <button
                                @click="notifSettings.notify_plan_updated = !notifSettings.notify_plan_updated"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                :class="notifSettings.notify_plan_updated ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-slate-700'"
                            >
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200"
                                    :class="notifSettings.notify_plan_updated ? 'translate-x-5' : 'translate-x-0'" />
                            </button>
                        </div>

                        <!-- Save button -->
                        <div class="flex items-center gap-3 pt-2">
                            <button @click="saveNotifSettings" :disabled="notifSaving"
                                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                <svg v-if="notifSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                Einstellungen speichern
                            </button>
                            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                <span v-if="notifSaved" class="text-sm text-green-600 dark:text-green-400 font-medium">Gespeichert ✓</span>
                            </Transition>
                        </div>
                    </div>

                </template>

            </div>
        </div>

        <!-- Strava disconnect confirmation modal -->
        <Modal :show="confirmStravaDisconnect" @close="confirmStravaDisconnect = false">
            <div class="p-6 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Strava wirklich trennen?</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">
                    Die Verbindung zu Strava wird entfernt und alle importierten Aktivitäten werden aus Zone3 gelöscht.
                    Deine Daten auf Strava bleiben unverändert.
                </p>
                <div class="mt-5 flex gap-3 justify-end">
                    <button @click="confirmStravaDisconnect = false" class="rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                        Abbrechen
                    </button>
                    <button
                        @click="disconnectStrava"
                        :disabled="stravaDisconnectForm.processing"
                        class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 transition-colors"
                    >
                        Ja, Strava trennen
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Delete confirmation modal -->
        <Modal :show="confirmingDeletion" @close="closeDeleteModal">
            <div class="p-6 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Konto wirklich löschen?</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">
                    Diese Aktion kann nicht rückgängig gemacht werden. Alle Daten werden permanent gelöscht.
                    Gib dein Passwort ein um zu bestätigen.
                </p>
                <div class="mt-5">
                    <input
                        ref="deletePasswordInput"
                        v-model="deleteForm.password"
                        type="password"
                        placeholder="Passwort eingeben"
                        @keyup.enter="deleteAccount"
                        class="block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100 dark:focus:ring-red-900/30 transition-colors"
                    />
                    <InputError :message="deleteForm.errors.password" class="mt-1.5" />
                </div>
                <div class="mt-5 flex gap-3 justify-end">
                    <button @click="closeDeleteModal" class="rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">
                        Abbrechen
                    </button>
                    <button
                        @click="deleteAccount"
                        :disabled="deleteForm.processing"
                        class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 transition-colors"
                    >
                        Ja, Konto löschen
                    </button>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>

