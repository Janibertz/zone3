<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage, Link } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

const props = defineProps({
    mustVerifyEmail:       Boolean,
    status:                String,
    runnerProfile:         Object,
    stravaConnected:       Boolean,
    stravaAccount:         Object,
    notificationSettings:  Object,
    vapidPublicKey:        String,
    athleteStats:          Object,
    coaches:               { type: Array, default: () => [] },
    activeCoach:           { type: Object, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const activeTab = ref('personal');

const tabs = [
    { key: 'personal',       label: 'Persönlich',         icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />' },
    { key: 'coach',          label: 'Mein Coach',         icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />' },
    { key: 'athlete',        label: 'Athletenprofil',     icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />' },
    { key: 'notifications',  label: 'Benachrichtigungen', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />' },
    { key: 'connections',    label: 'Verbindungen',       icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />' },
    { key: 'security',       label: 'Sicherheit',         icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />' },
    { key: 'account',        label: 'Konto',              icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />' },
];

const distanceOptions = ['5 km', '10 km', 'Halbmarathon', 'Marathon', 'Ultra'];

// ── Coach selection ────────────────────────────────────────────────────────
const selectedCoachId  = ref(props.activeCoach?.id ?? null);
const coachSaving      = ref(false);
const coachSaved       = ref(false);
const savedCoachName   = ref(props.activeCoach?.name ?? null);
const savedCoachColor  = ref(props.activeCoach?.avatar_color ?? null);
const savedCoachInitials = ref(props.activeCoach?.avatar_initials ?? null);

const selectedCoach = computed(() => props.coaches?.find(c => c.id === selectedCoachId.value));

const coachColors = {
    orange: { bg: 'bg-orange-500', ring: 'ring-orange-400', light: 'bg-orange-50 dark:bg-orange-500/10', border: 'border-orange-300 dark:border-orange-500/50', badge: 'bg-orange-100 dark:bg-orange-500/20 text-orange-700 dark:text-orange-300' },
    blue:   { bg: 'bg-blue-600',   ring: 'ring-blue-400',   light: 'bg-blue-50 dark:bg-blue-500/10',   border: 'border-blue-300 dark:border-blue-500/50',   badge: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300'   },
    green:  { bg: 'bg-green-600',  ring: 'ring-green-400',  light: 'bg-green-50 dark:bg-green-500/10',  border: 'border-green-300 dark:border-green-500/50',  badge: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300'  },
};
const specialtyLabels = { motivator: 'Motivator', strategist: 'Stratege', companion: 'Begleiter' };

async function saveCoach() {
    if (!selectedCoachId.value) return;
    coachSaving.value = true;
    coachSaved.value  = false;
    try {
        await axios.patch(route('profile.coach'), { coach_id: selectedCoachId.value });
        savedCoachName.value     = selectedCoach.value?.name ?? null;
        savedCoachColor.value    = selectedCoach.value?.avatar_color ?? null;
        savedCoachInitials.value = selectedCoach.value?.avatar_initials ?? null;
        coachSaved.value = true;
        setTimeout(() => coachSaved.value = false, 4000);
    } finally {
        coachSaving.value = false;
    }
}

// ── Avatar upload + crop ─────────────────────────────────────────────────────
const avatarInput    = ref(null);
const avatarPreview  = ref(null);
const avatarUploading = ref(false);
const avatarImgError  = ref(false);

// Crop modal state
const showCropModal  = ref(false);
const cropImageSrc   = ref('');
const cropperEl      = ref(null);   // the <img> inside the modal
let   cropperInstance = null;

function triggerAvatarUpload() {
    avatarInput.value?.click();
}

// When user selects a file → open crop modal instead of uploading directly
function onAvatarChange(e) {
    const file = e.target.files[0];
    if (!file) return;
    // Reset input so same file can be re-selected
    e.target.value = '';

    const reader = new FileReader();
    reader.onload = (ev) => {
        cropImageSrc.value = ev.target.result;
        showCropModal.value = true;
        nextTick(initCropper);
    };
    reader.readAsDataURL(file);
}

async function initCropper() {
    if (!cropperEl.value) return;
    // Lazy-load cropperjs
    const [Cropper, _css] = await Promise.all([
        import('cropperjs').then(m => m.default),
        import('cropperjs/dist/cropper.css'),
    ]);
    if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
    cropperInstance = new Cropper(cropperEl.value, {
        aspectRatio: 1,
        viewMode:    1,
        dragMode:    'move',
        autoCropArea: 0.85,
        cropBoxResizable: false,
        cropBoxMovable:   false,
        center: true,
        guides: false,
        background: false,
        highlight:  false,
    });
}

function closeCropModal() {
    if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
    showCropModal.value = false;
    cropImageSrc.value  = '';
}

async function confirmCrop() {
    if (!cropperInstance) return;
    avatarUploading.value = true;

    try {
        // Export cropped canvas at 400×400
        const canvas = cropperInstance.getCroppedCanvas({ width: 400, height: 400, imageSmoothingQuality: 'high' });
        const blob   = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));

        // Preview immediately
        avatarPreview.value  = canvas.toDataURL('image/jpeg', 0.9);
        avatarImgError.value = false;
        closeCropModal();

        // Upload
        const formData = new FormData();
        formData.append('avatar', blob, 'avatar.jpg');
        await axios.post(route('profile.avatar'), formData, { headers: { 'Content-Type': 'multipart/form-data' } });
    } catch {
        avatarPreview.value = null;
    } finally {
        avatarUploading.value = false;
    }
}

onBeforeUnmount(() => {
    if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
});

const avatarUrl = computed(() => {
    if (avatarPreview.value) return avatarPreview.value;
    if (user.value.avatar && !avatarImgError.value) return '/storage/' + user.value.avatar;
    return null;
});

const initials = computed(() => {
    const parts = (user.value.name ?? '').trim().split(' ');
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return (user.value.name ?? 'U').slice(0, 2).toUpperCase();
});

// ── Push Notification state ──────────────────────────────────────────────────
const pushSupported    = ref('Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window);
const pushPermission   = ref(typeof Notification !== 'undefined' ? Notification.permission : 'default');
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
    pushLoading.value = true; pushError.value = '';
    try {
        const permission = await Notification.requestPermission();
        pushPermission.value = permission;
        if (permission !== 'granted') { pushError.value = 'Benachrichtigungen wurden blockiert.'; return; }
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(props.vapidPublicKey) });
        const json = sub.toJSON();
        await axios.post(route('push.subscribe'), { endpoint: json.endpoint, public_key: json.keys.p256dh, auth_token: json.keys.auth });
        pushSubscribed.value = true;
    } catch (e) { pushError.value = 'Fehler: ' + (e?.message ?? 'Unbekannt'); }
    finally { pushLoading.value = false; }
}

async function unsubscribePush() {
    pushLoading.value = true; pushError.value = '';
    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) { await axios.post(route('push.unsubscribe'), { endpoint: sub.endpoint }); await sub.unsubscribe(); }
        pushSubscribed.value = false;
    } catch (e) { pushError.value = 'Fehler: ' + (e?.message ?? ''); }
    finally { pushLoading.value = false; }
}

async function sendTestPush() {
    try { await axios.post(route('push.test')); pushTestSent.value = true; setTimeout(() => { pushTestSent.value = false; }, 3000); }
    catch (e) { pushError.value = e?.response?.data?.error ?? 'Test fehlgeschlagen.'; }
}

async function saveNotifSettings() {
    notifSaving.value = true;
    try { await axios.patch(route('push.settings'), notifSettings.value); notifSaved.value = true; setTimeout(() => { notifSaved.value = false; }, 2500); }
    finally { notifSaving.value = false; }
}

// ── Personal info form ───────────────────────────────────────────────────────
const profileForm = useForm({
    name:              user.value.name,
    email:             user.value.email,
    bio:               user.value.bio ?? '',
    location:          user.value.location ?? '',
    birth_year:        user.value.birth_year ?? '',
    favorite_distance: user.value.favorite_distance ?? '',
});

// ── Password form ────────────────────────────────────────────────────────────
const passwordInput = ref(null);
const currentPasswordInput = ref(null);
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

const updatePassword = () => {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => {
            if (passwordForm.errors.password) { passwordForm.reset('password', 'password_confirmation'); passwordInput.value?.focus(); }
            if (passwordForm.errors.current_password) { passwordForm.reset('current_password'); currentPasswordInput.value?.focus(); }
        },
    });
};

// ── Athlete / Runner profile form ────────────────────────────────────────────
function formatPace(minutes) {
    if (!minutes) return '';
    const m = Math.floor(minutes);
    const s = Math.round((minutes - m) * 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}

const athleteForm = useForm({
    threshold_heart_rate: props.runnerProfile?.threshold_heart_rate ? String(props.runnerProfile.threshold_heart_rate) : '',
    max_heart_rate:       props.runnerProfile?.max_heart_rate ? String(props.runnerProfile.max_heart_rate) : '',
    threshold_speed:      props.runnerProfile?.threshold_speed ? formatPace(props.runnerProfile.threshold_speed) : '',
});

const submitAthlete = () => { athleteForm.post(route('runner.profile.store'), { preserveScroll: true }); };
const paceZones   = computed(() => props.runnerProfile?.pace_zones ?? []);
const athleteSaved = computed(() => props.status === 'athlete-saved');

// ── Weekly availability ──────────────────────────────────────────────────────
const availabilityDays = [
    { key: 'monday',    label: 'Mo', full: 'Montag' },
    { key: 'tuesday',   label: 'Di', full: 'Dienstag' },
    { key: 'wednesday', label: 'Mi', full: 'Mittwoch' },
    { key: 'thursday',  label: 'Do', full: 'Donnerstag' },
    { key: 'friday',    label: 'Fr', full: 'Freitag' },
    { key: 'saturday',  label: 'Sa', full: 'Samstag' },
    { key: 'sunday',    label: 'So', full: 'Sonntag' },
];
const durationOptions = [30, 45, 60, 90, 120, 180];

const defaultAvail = () => ({
    monday:    { available: true,  duration_min: 60 },
    tuesday:   { available: false, duration_min: 0  },
    wednesday: { available: true,  duration_min: 60 },
    thursday:  { available: false, duration_min: 0  },
    friday:    { available: true,  duration_min: 60 },
    saturday:  { available: true,  duration_min: 90 },
    sunday:    { available: false, duration_min: 0  },
});

const availability = ref(
    props.runnerProfile?.weekly_availability
        ? { ...defaultAvail(), ...props.runnerProfile.weekly_availability }
        : defaultAvail()
);
const availSaving = ref(false);
const availSaved  = ref(false);

function toggleAvailDay(key) {
    availability.value[key].available = !availability.value[key].available;
    if (!availability.value[key].available) availability.value[key].duration_min = 0;
    else if (!availability.value[key].duration_min) availability.value[key].duration_min = 60;
}

async function saveAvailability() {
    availSaving.value = true;
    try {
        await axios.post(route('onboarding.availability'), { availability: availability.value });
        availSaved.value = true;
        setTimeout(() => { availSaved.value = false; }, 2500);
    } finally {
        availSaving.value = false;
    }
}

const zoneColors = [
    'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-500/10 dark:border-blue-500/30 dark:text-blue-300',
    'bg-green-50 border-green-200 text-green-700 dark:bg-green-500/10 dark:border-green-500/30 dark:text-green-300',
    'bg-yellow-50 border-yellow-200 text-yellow-700 dark:bg-yellow-500/10 dark:border-yellow-500/30 dark:text-yellow-300',
    'bg-orange-50 border-orange-200 text-orange-700 dark:bg-orange-500/10 dark:border-orange-500/30 dark:text-orange-300',
    'bg-red-50 border-red-200 text-red-700 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-300',
];

// ── Delete account ───────────────────────────────────────────────────────────
const confirmingDeletion  = ref(false);
const deletePasswordInput = ref(null);
const deleteForm = useForm({ password: '' });

const confirmDeletion = () => { confirmingDeletion.value = true; nextTick(() => deletePasswordInput.value?.focus()); };
const deleteAccount = () => {
    deleteForm.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeDeleteModal(),
        onError:   () => deletePasswordInput.value?.focus(),
        onFinish:  () => deleteForm.reset(),
    });
};
const closeDeleteModal = () => { confirmingDeletion.value = false; deleteForm.clearErrors(); deleteForm.reset(); };

// ── Strava disconnect ────────────────────────────────────────────────────────
const confirmStravaDisconnect = ref(false);
const stravaDisconnectForm = useForm({});
const disconnectStrava = () => { stravaDisconnectForm.delete(route('strava.disconnect'), { onSuccess: () => { confirmStravaDisconnect.value = false; } }); };

// ── Garmin connection ─────────────────────────────────────────────────────────
const garminConnected   = computed(() => !!page.props.auth.garminConnected);
const garminSavedEmail  = computed(() => page.props.auth.garminEmail ?? '');
const garminEmail       = ref('');
const garminPassword    = ref('');
const garminConnecting  = ref(false);
const garminConnectError= ref('');
const garminConnectOk   = ref(false);
const garminDisconnecting = ref(false);
const showGarminForm    = ref(false);

async function connectGarmin() {
    if (!garminEmail.value || !garminPassword.value) return;
    garminConnecting.value   = true;
    garminConnectError.value = '';
    garminConnectOk.value    = false;
    try {
        await axios.post(route('profile.garmin-connect'), {
            email: garminEmail.value,
            password: garminPassword.value,
        });
        garminConnectOk.value  = true;
        showGarminForm.value   = false;
        garminPassword.value   = '';
        // Update shared Inertia props so the connected state reflects immediately
        page.props.auth.garminConnected = true;
        page.props.auth.garminEmail     = garminEmail.value;
    } catch (e) {
        const err = e.response?.data?.error ?? 'Verbindung fehlgeschlagen.';
        garminConnectError.value = err === 'login_failed'
            ? 'Falsche E-Mail oder Passwort.'
            : err === 'mfa_required'
                ? 'Zwei-Faktor-Authentifizierung aktiviert – derzeit nicht unterstützt.'
                : err;
    } finally {
        garminConnecting.value = false;
    }
}

async function disconnectGarmin() {
    garminDisconnecting.value = true;
    try {
        await axios.delete(route('garmin.disconnect'));
        page.props.auth.garminConnected = false;
        page.props.auth.garminEmail     = null;
        garminConnectOk.value           = false;
        garminEmail.value               = '';
    } catch { /* ignore */ } finally {
        garminDisconnecting.value = false;
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
const inputClass = 'block w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800 px-4 py-2.5 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-slate-500 focus:border-indigo-400 dark:focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 transition-colors';
</script>

<template>
    <Head title="Profil" />
    <AuthenticatedLayout>
        <div class="max-w-3xl mx-auto px-3 sm:px-6 py-4 sm:py-8 space-y-5">

            <!-- ══ PROFILE HERO ══ -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden">

                <!-- Cover gradient -->
                <div class="h-24 sm:h-32 bg-gradient-to-br from-indigo-500 via-indigo-600 to-purple-700" />

                <!-- Avatar + Info -->
                <div class="px-5 sm:px-7 pb-5 sm:pb-6">
                    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 -mt-10 sm:-mt-12">

                        <!-- Avatar -->
                        <div class="relative shrink-0">
                            <button
                                @click="triggerAvatarUpload"
                                class="group relative h-20 w-20 sm:h-24 sm:w-24 rounded-2xl overflow-hidden border-4 border-white dark:border-slate-900 shadow-lg bg-gradient-to-br from-indigo-400 to-indigo-600 flex items-center justify-center hover:ring-2 hover:ring-indigo-400 transition-all"
                            >
                                <img v-if="avatarUrl" :src="avatarUrl" alt="Profilbild" class="absolute inset-0 h-full w-full object-cover" @error="avatarImgError = true" />
                                <span v-else class="text-2xl sm:text-3xl font-bold text-white select-none">{{ initials }}</span>
                                <!-- Hover overlay -->
                                <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                    </svg>
                                </div>
                                <!-- Loading spinner -->
                                <div v-if="avatarUploading" class="absolute inset-0 bg-black/50 flex items-center justify-center">
                                    <svg class="h-6 w-6 text-white animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                </div>
                            </button>
                            <input ref="avatarInput" type="file" accept="image/*" class="hidden" @change="onAvatarChange" />
                        </div>

                        <!-- Name + meta -->
                        <div class="flex-1 min-w-0 pt-2 sm:pt-0 sm:pb-1">
                            <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white leading-tight">{{ user.name }}</h1>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1">
                                <span v-if="user.location" class="flex items-center gap-1 text-sm text-gray-500 dark:text-slate-400">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                    {{ user.location }}
                                </span>
                                <span v-if="user.favorite_distance" class="flex items-center gap-1 text-sm text-gray-500 dark:text-slate-400">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                                    {{ user.favorite_distance }}
                                </span>
                                <span v-if="user.birth_year" class="text-sm text-gray-500 dark:text-slate-400">
                                    {{ new Date().getFullYear() - user.birth_year }} Jahre
                                </span>
                            </div>
                            <p v-if="user.bio" class="mt-2 text-sm text-gray-600 dark:text-slate-400 leading-relaxed max-w-lg">{{ user.bio }}</p>
                        </div>

                        <!-- Edit button -->
                        <div class="shrink-0">
                            <button
                                @click="activeTab = 'personal'"
                                class="inline-flex items-center gap-2 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 px-4 py-2 text-sm font-semibold text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-500/20 transition-colors"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                </svg>
                                Bearbeiten
                            </button>
                        </div>
                    </div>

                    <!-- Stats row -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-5 pt-5 border-t border-gray-100 dark:border-slate-800">
                        <div class="text-center">
                            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">{{ athleteStats?.total_runs ?? 0 }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Aktivitäten</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">{{ athleteStats?.total_km ?? 0 }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">km gesamt</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">{{ athleteStats?.longest_km ?? 0 }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">km längste</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">{{ athleteStats?.avg_pace ?? '–' }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Ø Pace</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ TABS ══ -->
            <div class="flex gap-1 bg-gray-100 dark:bg-slate-800 rounded-2xl p-1 overflow-x-auto" style="-webkit-overflow-scrolling:touch;scrollbar-width:none;">
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

            <!-- ══ TAB CONTENT ══ -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden">

                <!-- ── PERSÖNLICH ── -->
                <template v-if="activeTab === 'personal'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Persönliche Daten</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Name, E-Mail und öffentliche Profilinformationen</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div v-if="props.status === 'profile-information-updated' || props.status === 'avatar-updated'" class="mb-5 flex items-center gap-2 rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30 px-4 py-3 text-sm text-green-700 dark:text-green-400">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Profil erfolgreich gespeichert.
                        </div>

                        <form @submit.prevent="profileForm.patch(route('profile.update'))" class="space-y-5">
                            <!-- Name + Email -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Name</label>
                                    <input v-model="profileForm.name" type="text" required autofocus autocomplete="name" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.name" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">E-Mail</label>
                                    <input v-model="profileForm.email" type="email" required autocomplete="username" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.email" />
                                </div>
                            </div>

                            <!-- Location + Birth year -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Standort</label>
                                    <input v-model="profileForm.location" type="text" placeholder="z.B. München" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.location" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Geburtsjahr</label>
                                    <input v-model="profileForm.birth_year" type="number" placeholder="z.B. 1992" min="1940" :max="new Date().getFullYear() - 10" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.birth_year" />
                                </div>
                            </div>

                            <!-- Favorite distance -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Lieblingsdistanz</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="d in distanceOptions"
                                        :key="d"
                                        type="button"
                                        @click="profileForm.favorite_distance = (profileForm.favorite_distance === d ? '' : d)"
                                        class="px-3 py-1.5 rounded-xl text-sm font-medium border transition-colors"
                                        :class="profileForm.favorite_distance === d
                                            ? 'bg-indigo-600 border-indigo-600 text-white'
                                            : 'bg-white dark:bg-slate-800 border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-300 hover:border-indigo-300 dark:hover:border-indigo-500'"
                                    >
                                        {{ d }}
                                    </button>
                                </div>
                            </div>

                            <!-- Bio -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                    Über mich
                                    <span class="font-normal text-gray-400 dark:text-slate-500 ml-1">{{ (profileForm.bio ?? '').length }}/300</span>
                                </label>
                                <textarea
                                    v-model="profileForm.bio"
                                    rows="3"
                                    maxlength="300"
                                    placeholder="Erzähl etwas über dich als Athlet..."
                                    :class="inputClass + ' resize-none'"
                                />
                                <InputError class="mt-1.5" :message="profileForm.errors.bio" />
                            </div>

                            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-700">
                                E-Mail noch nicht verifiziert.
                                <Link :href="route('verification.send')" method="post" as="button" class="underline ml-1">Erneut senden</Link>
                            </div>

                            <div class="flex items-center justify-end pt-2">
                                <button type="submit" :disabled="profileForm.processing" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm">
                                    <svg v-if="profileForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Speichern
                                </button>
                            </div>
                        </form>
                    </div>
                </template>

                <!-- ── MEIN COACH ── -->
                <template v-else-if="activeTab === 'coach'">
                    <div class="space-y-4">
                        <div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">Mein Coach</h3>
                            <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">Dein Coach beeinflusst wie Trainingsempfehlungen und Pläne kommuniziert werden.</p>
                        </div>

                        <div class="space-y-3">
                            <button
                                v-for="coach in coaches" :key="coach.id"
                                type="button"
                                @click="selectedCoachId = coach.id"
                                class="w-full flex items-start gap-4 p-5 rounded-2xl border-2 text-left transition-all duration-200"
                                :class="selectedCoachId === coach.id
                                    ? [coachColors[coach.avatar_color]?.light, coachColors[coach.avatar_color]?.border, 'shadow-sm']
                                    : 'bg-white dark:bg-slate-800/50 border-gray-200 dark:border-slate-700 hover:border-gray-300 dark:hover:border-slate-600'"
                            >
                                <div class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center text-white font-bold text-base"
                                    :class="[coachColors[coach.avatar_color]?.bg, selectedCoachId === coach.id ? 'ring-2 ring-offset-2 ' + coachColors[coach.avatar_color]?.ring : '']">
                                    {{ coach.avatar_initials }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-0.5">
                                        <span class="font-bold text-gray-900 dark:text-white">{{ coach.name }}</span>
                                        <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="coachColors[coach.avatar_color]?.badge">{{ specialtyLabels[coach.specialty] }}</span>
                                    </div>
                                    <p class="text-xs italic text-gray-500 dark:text-slate-400 mb-1">„{{ coach.tagline }}"</p>
                                    <p class="text-sm text-gray-600 dark:text-slate-300">{{ coach.description }}</p>
                                </div>
                                <div class="shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center mt-0.5"
                                    :class="selectedCoachId === coach.id ? [coachColors[coach.avatar_color]?.bg, 'border-transparent'] : 'border-gray-300 dark:border-slate-600'">
                                    <svg v-if="selectedCoachId === coach.id" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </div>
                            </button>
                        </div>

                        <div class="flex items-center gap-3 flex-wrap">
                            <button @click="saveCoach" :disabled="coachSaving || !selectedCoachId"
                                class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
                                {{ coachSaving ? 'Speichern…' : 'Coach speichern' }}
                            </button>
                            <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 translate-y-1" leave-active-class="transition-all duration-200" leave-to-class="opacity-0">
                                <div v-if="coachSaved && savedCoachName" class="flex items-center gap-2 rounded-xl px-3 py-2 border"
                                    :class="coachColors[savedCoachColor]?.light + ' ' + coachColors[savedCoachColor]?.border">
                                    <div class="h-6 w-6 rounded-lg flex items-center justify-center text-white text-xs font-bold shrink-0" :class="coachColors[savedCoachColor]?.bg">{{ savedCoachInitials }}</div>
                                    <p class="text-sm font-semibold" :class="coachColors[savedCoachColor]?.badge?.split(' ')[2]">{{ savedCoachName }} ist jetzt dein Coach!</p>
                                </div>
                            </Transition>
                        </div>
                    </div>
                </template>

                <!-- ── ATHLETENPROFIL ── -->
                <template v-else-if="activeTab === 'athlete'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Athletenprofil</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Herzfrequenz- und Schwellenwerte für deine Trainingszonen</p>
                    </div>
                    <div class="p-4 sm:p-6 space-y-5">
                        <div v-if="athleteSaved" class="flex items-center gap-2 rounded-xl bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Athletenprofil gespeichert. Zonen wurden neu berechnet.
                        </div>
                        <form @submit.prevent="submitAthlete" class="space-y-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Schwellen-HF <span class="text-gray-400 font-normal">(LTHR, bpm)</span></label>
                                    <input v-model="athleteForm.threshold_heart_rate" type="number" min="100" max="220" placeholder="z.B. 165" required :class="inputClass" />
                                    <InputError class="mt-1" :message="athleteForm.errors.threshold_heart_rate" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Maximale HF <span class="text-gray-400 font-normal">(bpm)</span></label>
                                    <input v-model="athleteForm.max_heart_rate" type="number" min="100" max="220" placeholder="z.B. 195" required :class="inputClass" />
                                    <InputError class="mt-1" :message="athleteForm.errors.max_heart_rate" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Schwellen-Pace <span class="text-gray-400 font-normal">(min:sek/km)</span></label>
                                <input v-model="athleteForm.threshold_speed" type="text" placeholder="z.B. 5:30" pattern="[0-9]{1,2}:[0-9]{2}" required :class="inputClass" />
                                <p class="mt-1 text-xs text-gray-400">Die Pace, die du für ~60 min halten kannst</p>
                                <InputError class="mt-1" :message="athleteForm.errors.threshold_speed" />
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" :disabled="athleteForm.processing" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm">
                                    <svg v-if="athleteForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Speichern & Zonen berechnen
                                </button>
                            </div>
                        </form>

                        <div v-if="paceZones.length > 0" class="border-t border-gray-100 dark:border-slate-800 pt-5">
                            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">Deine Laufzonen</h3>
                            <div class="space-y-2">
                                <div v-for="(zone, idx) in paceZones" :key="idx" class="flex items-center justify-between rounded-xl border px-4 py-3" :class="zoneColors[idx]">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-bold opacity-60">Z{{ idx + 1 }}</span>
                                        <span class="text-sm font-semibold">{{ zone.name }}</span>
                                    </div>
                                    <span class="text-sm font-mono tabular-nums">{{ zone.min_pace }} – {{ zone.max_pace }} min/km</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="border-t border-gray-100 dark:border-slate-800 pt-5">
                            <div class="rounded-xl bg-gray-50 dark:bg-slate-800 border border-dashed border-gray-200 dark:border-slate-600 px-5 py-6 text-center">
                                <p class="text-sm text-gray-400 dark:text-slate-500">Speichere dein Profil um deine Laufzonen zu berechnen.</p>
                            </div>
                        </div>

                        <!-- Weekly availability -->
                        <div class="border-t border-gray-100 dark:border-slate-800 pt-5 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Wöchentliche Verfügbarkeit</h3>
                                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">An welchen Tagen kannst du trainieren? Der KI-Plan respektiert diese Zeiten.</p>
                                </div>
                                <div v-if="availSaved" class="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400 font-medium">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    Gespeichert
                                </div>
                            </div>

                            <!-- Day toggles -->
                            <div class="grid grid-cols-7 gap-1.5">
                                <button
                                    v-for="day in availabilityDays"
                                    :key="day.key"
                                    type="button"
                                    @click="toggleAvailDay(day.key)"
                                    class="flex flex-col items-center gap-1 rounded-xl py-2.5 text-xs font-semibold transition-colors border"
                                    :class="availability[day.key].available
                                        ? 'bg-indigo-600 border-indigo-600 text-white'
                                        : 'bg-gray-50 dark:bg-slate-800 border-gray-200 dark:border-slate-700 text-gray-400 dark:text-slate-500'"
                                >
                                    {{ day.label }}
                                </button>
                            </div>

                            <!-- Duration for active days -->
                            <div class="space-y-2">
                                <div
                                    v-for="day in availabilityDays.filter(d => availability[d.key].available)"
                                    :key="day.key"
                                    class="flex items-center gap-3"
                                >
                                    <span class="w-24 text-sm text-gray-600 dark:text-slate-400 shrink-0">{{ day.full }}</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="dur in durationOptions"
                                            :key="dur"
                                            type="button"
                                            @click="availability[day.key].duration_min = dur"
                                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors border"
                                            :class="availability[day.key].duration_min === dur
                                                ? 'bg-indigo-600 border-indigo-600 text-white'
                                                : 'bg-gray-50 dark:bg-slate-800 border-gray-200 dark:border-slate-700 text-gray-500 dark:text-slate-400 hover:border-indigo-300'"
                                        >
                                            {{ dur }} min
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Summary + save -->
                            <div class="flex items-center justify-between pt-1">
                                <p class="text-xs text-gray-400 dark:text-slate-500">
                                    {{ availabilityDays.filter(d => availability[d.key].available).length }} Tage ·
                                    {{ availabilityDays.reduce((s, d) => s + (availability[d.key].available ? availability[d.key].duration_min : 0), 0) }} Min/Woche
                                </p>
                                <button
                                    type="button"
                                    @click="saveAvailability"
                                    :disabled="availSaving"
                                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm"
                                >
                                    <svg v-if="availSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Verfügbarkeit speichern
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ── BENACHRICHTIGUNGEN ── -->
                <template v-else-if="activeTab === 'notifications'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Web Push Benachrichtigungen</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Erhalte Hinweise direkt im Browser — auch wenn die App nicht geöffnet ist</p>
                    </div>
                    <div class="p-4 sm:p-6 space-y-5">
                        <div v-if="!pushSupported" class="rounded-xl bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-slate-700 px-4 py-3 text-sm text-gray-500">Dein Browser unterstützt keine Push-Benachrichtigungen.</div>
                        <template v-else>
                            <div v-if="pushPermission === 'denied'" class="rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-4 py-3 text-sm text-red-700 dark:text-red-400">Benachrichtigungen sind in deinem Browser blockiert.</div>
                            <div class="flex items-center justify-between gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-slate-800">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ pushSubscribed ? 'Push aktiv' : 'Push deaktiviert' }}</p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ pushSubscribed ? 'Du erhältst Benachrichtigungen auf diesem Gerät' : 'Noch keine Benachrichtigungen auf diesem Gerät' }}</p>
                                </div>
                                <button @click="pushSubscribed ? unsubscribePush() : subscribePush()" :disabled="pushLoading || pushPermission === 'denied'" class="shrink-0 inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition-colors disabled:opacity-50" :class="pushSubscribed ? 'bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-slate-300 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600' : 'bg-indigo-600 text-white hover:bg-indigo-700'">
                                    <svg v-if="pushLoading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    {{ pushSubscribed ? 'Deaktivieren' : 'Aktivieren' }}
                                </button>
                            </div>
                            <div v-if="pushSubscribed" class="flex items-center gap-3">
                                <button @click="sendTestPush" class="inline-flex items-center gap-2 rounded-xl bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 px-3 py-2 text-sm font-medium transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                                    Test-Benachrichtigung
                                </button>
                                <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                    <span v-if="pushTestSent" class="text-sm text-green-600 dark:text-green-400 font-medium">Gesendet</span>
                                </Transition>
                            </div>
                            <p v-if="pushError" class="text-sm text-red-600 dark:text-red-400">{{ pushError }}</p>
                        </template>
                    </div>

                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-t border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Einstellungen</h2>
                    </div>
                    <div class="p-4 sm:p-6 space-y-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Wellbeing-Erinnerung</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Uhrzeit für die tägliche Erinnerung</p>
                            </div>
                            <input v-model="notifSettings.wellbeing_reminder_time" type="time" class="shrink-0 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Schwellenpace aktualisiert</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Bei KI-Neuberechnung deiner Pace</p>
                            </div>
                            <button @click="notifSettings.notify_threshold_pace = !notifSettings.notify_threshold_pace" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none" :class="notifSettings.notify_threshold_pace ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-slate-700'">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200" :class="notifSettings.notify_threshold_pace ? 'translate-x-5' : 'translate-x-0'" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">KI-Plan aktualisiert</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Wenn dein Trainingsplan neu berechnet wurde</p>
                            </div>
                            <button @click="notifSettings.notify_plan_updated = !notifSettings.notify_plan_updated" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none" :class="notifSettings.notify_plan_updated ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-slate-700'">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200" :class="notifSettings.notify_plan_updated ? 'translate-x-5' : 'translate-x-0'" />
                            </button>
                        </div>
                        <div class="flex items-center gap-3 pt-2">
                            <button @click="saveNotifSettings" :disabled="notifSaving" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                <svg v-if="notifSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                Einstellungen speichern
                            </button>
                            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                <span v-if="notifSaved" class="text-sm text-green-600 dark:text-green-400 font-medium">Gespeichert</span>
                            </Transition>
                        </div>
                    </div>
                </template>

                <!-- ── VERBINDUNGEN ── -->
                <template v-else-if="activeTab === 'connections'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Verbindungen</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Drittanbieter-Dienste mit Zone3 verbinden</p>
                    </div>
                    <div class="p-4 sm:p-6 space-y-4">

                        <!-- Strava Card (umgezogen von Security) -->
                        <div class="rounded-2xl border border-gray-100 dark:border-slate-800 overflow-hidden">
                            <div class="flex items-center justify-between gap-4 px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-xl flex items-center justify-center shrink-0"
                                        :class="props.stravaConnected ? 'bg-orange-50 dark:bg-orange-500/10' : 'bg-gray-100 dark:bg-slate-800'">
                                        <svg class="h-6 w-6" :class="props.stravaConnected ? 'text-orange-500' : 'text-gray-400 dark:text-slate-500'" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-white">Strava</p>
                                        <p v-if="props.stravaConnected" class="text-xs text-green-600 dark:text-green-400 mt-0.5">Verbunden · {{ props.stravaAccount?.username }}</p>
                                        <p v-else class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Nicht verbunden</p>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <button v-if="props.stravaConnected" @click="confirmStravaDisconnect = true"
                                        class="rounded-xl border border-red-200 dark:border-red-500/30 px-3 py-1.5 text-xs font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors">
                                        Trennen
                                    </button>
                                    <Link v-else href="/strava/connect"
                                        class="rounded-xl bg-orange-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-600 transition-colors">
                                        Verbinden
                                    </Link>
                                </div>
                            </div>
                        </div>

                    </div>
                </template>

                <!-- ── SICHERHEIT ── -->
                <template v-else-if="activeTab === 'security'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Passwort ändern</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Nutze ein sicheres, einzigartiges Passwort</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <form @submit.prevent="updatePassword" class="space-y-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Aktuelles Passwort</label>
                                <input ref="currentPasswordInput" v-model="passwordForm.current_password" type="password" autocomplete="current-password" :class="inputClass" />
                                <InputError class="mt-1.5" :message="passwordForm.errors.current_password" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Neues Passwort</label>
                                <input ref="passwordInput" v-model="passwordForm.password" type="password" autocomplete="new-password" :class="inputClass" />
                                <InputError class="mt-1.5" :message="passwordForm.errors.password" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Passwort bestätigen</label>
                                <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" :class="inputClass" />
                                <InputError class="mt-1.5" :message="passwordForm.errors.password_confirmation" />
                            </div>
                            <div class="flex items-center gap-4 pt-1">
                                <button type="submit" :disabled="passwordForm.processing" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors shadow-sm">
                                    <svg v-if="passwordForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Passwort ändern
                                </button>
                                <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                    <span v-if="passwordForm.recentlySuccessful" class="text-sm text-green-600 dark:text-green-400">Passwort gespeichert.</span>
                                </Transition>
                            </div>
                        </form>
                    </div>
                </template>

                <!-- ── KONTO ── -->
                <template v-else-if="activeTab === 'account'">
                    <!-- Strava -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Strava Verbindung</h2>
                    </div>
                    <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-slate-800">
                        <div v-if="props.stravaConnected" class="flex items-start justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 rounded-xl bg-orange-100 dark:bg-orange-500/15 flex items-center justify-center text-xl shrink-0">🔗</div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">Verbunden als <span class="text-orange-600 dark:text-orange-400">{{ props.stravaAccount?.username }}</span></p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Zuletzt: {{ props.stravaAccount?.last_synced_at ?? 'Noch nie' }}</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 dark:bg-green-500/15 px-2.5 py-1 text-xs font-medium text-green-700 dark:text-green-400 shrink-0">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Aktiv
                            </span>
                        </div>
                        <div v-else class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-xl shrink-0">🔗</div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">Nicht verbunden</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">Verbinde Strava um deine Läufe zu importieren</p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <Link v-if="!props.stravaConnected" href="/strava/connect" class="inline-flex items-center gap-2 rounded-xl bg-orange-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-orange-600 transition-colors shadow-sm">Mit Strava verbinden</Link>
                            <template v-else>
                                <Link href="/strava/connect" class="inline-flex items-center gap-2 rounded-xl bg-gray-100 dark:bg-slate-800 px-5 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-200 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">Konto wechseln</Link>
                                <button type="button" @click="confirmStravaDisconnect = true" class="inline-flex items-center gap-2 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-5 py-2.5 text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-500/20 transition-colors">Strava trennen</button>
                            </template>
                        </div>
                        <p v-if="props.stravaConnected" class="mt-3 text-xs text-gray-400 dark:text-slate-500">Beim Trennen werden alle importierten Aktivitäten aus Zone3 gelöscht. Deine Strava-Daten bleiben erhalten.</p>
                    </div>

                    <!-- Onboarding reset -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Onboarding wiederholen</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Athletenprofil und Ziel erneut einrichten</p>
                    </div>
                    <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-slate-800">
                        <Link :href="route('onboarding.reset')" method="post" as="button" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors shadow-sm">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            Onboarding neu starten
                        </Link>
                    </div>

                    <!-- Account deletion -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 dark:border-slate-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Konto löschen</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Alle Daten werden unwiderruflich gelöscht</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div class="rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-5 py-4 mb-5">
                            <p class="text-sm text-red-700 dark:text-red-400">Sobald dein Konto gelöscht wird, werden alle Daten unwiderruflich entfernt.</p>
                        </div>
                        <button @click="confirmDeletion" class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700 transition-colors shadow-sm">Konto löschen</button>
                    </div>
                </template>

            </div>
        </div>

        <!-- Strava disconnect modal -->
        <Modal :show="confirmStravaDisconnect" @close="confirmStravaDisconnect = false">
            <div class="p-6 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Strava wirklich trennen?</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Die Verbindung wird entfernt und alle importierten Aktivitäten werden aus Zone3 gelöscht. Deine Daten auf Strava bleiben unverändert.</p>
                <div class="mt-5 flex gap-3 justify-end">
                    <button @click="confirmStravaDisconnect = false" class="rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">Abbrechen</button>
                    <button @click="disconnectStrava" :disabled="stravaDisconnectForm.processing" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 transition-colors">Ja, trennen</button>
                </div>
            </div>
        </Modal>

        <!-- Delete confirmation modal -->
        <Modal :show="confirmingDeletion" @close="closeDeleteModal">
            <div class="p-6 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Konto wirklich löschen?</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Diese Aktion kann nicht rückgängig gemacht werden. Gib dein Passwort ein um zu bestätigen.</p>
                <div class="mt-5">
                    <input ref="deletePasswordInput" v-model="deleteForm.password" type="password" placeholder="Passwort eingeben" @keyup.enter="deleteAccount" :class="inputClass.replace('focus:border-indigo-400', 'focus:border-red-400').replace('focus:ring-indigo-100', 'focus:ring-red-100')" />
                    <InputError :message="deleteForm.errors.password" class="mt-1.5" />
                </div>
                <div class="mt-5 flex gap-3 justify-end">
                    <button @click="closeDeleteModal" class="rounded-xl bg-gray-100 dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors">Abbrechen</button>
                    <button @click="deleteAccount" :disabled="deleteForm.processing" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 transition-colors">Ja, löschen</button>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>

    <!-- ══ CROP MODAL ══ -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="showCropModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl shadow-2xl overflow-hidden">

                    <!-- Header -->
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-slate-800">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Profilbild zuschneiden</h3>
                        <button @click="closeCropModal" class="h-8 w-8 rounded-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 hover:bg-gray-200 dark:hover:bg-slate-700 flex items-center justify-center transition-colors text-sm">✕</button>
                    </div>

                    <!-- Cropper area -->
                    <div class="relative bg-gray-950" style="height: 340px;">
                        <img
                            ref="cropperEl"
                            :src="cropImageSrc"
                            alt="Bild zuschneiden"
                            class="block max-w-full"
                            style="max-height: 340px;"
                        />
                    </div>

                    <!-- Hint -->
                    <p class="text-xs text-gray-400 dark:text-slate-500 text-center py-2">Verschieben und zoomen um den Ausschnitt anzupassen</p>

                    <!-- Actions -->
                    <div class="flex items-center gap-3 px-5 py-4 border-t border-gray-100 dark:border-slate-800">
                        <button
                            @click="closeCropModal"
                            class="flex-1 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                        >Abbrechen</button>
                        <button
                            @click="confirmCrop"
                            :disabled="avatarUploading"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                        >
                            <svg v-if="avatarUploading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            {{ avatarUploading ? 'Wird hochgeladen...' : 'Zuschneiden & Speichern' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
