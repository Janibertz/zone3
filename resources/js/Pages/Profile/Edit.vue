<script setup>
import { zoneRange } from '@/Composables/usePaceZones';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import TrainingModelFields from '@/Components/Settings/TrainingModelFields.vue';
import BikeFields from '@/Components/Settings/BikeFields.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormRow from '@/Components/UI/FormRow.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

const props = defineProps({
    mustVerifyEmail:       Boolean,
    status:                String,
    runnerProfile:         Object,
    vdot:                  Object,
    // Eine Fahrt der letzten sechs Wochen lag über der FTP.
    ftpSuggestion:         Object,
    stravaConnected:       Boolean,
    stravaAccount:         Object,
    notificationSettings:  Object,
    vapidPublicKey:        String,
    athleteStats:          Object,
    coaches:               { type: Array, default: () => [] },
    activeCoach:           { type: Object, default: null },
    personalRecords:       { type: Array, default: () => [] },
    prHistory:             { type: Object, default: () => ({}) },
    telegram:              { type: Object, default: () => ({ available: false, linked: false }) },
    norwegianReadiness:    { type: Object, default: null },
    calendarFeed:          { type: Object, default: null },
    garminOfficial:        { type: Object, default: () => ({ available: false, connection: null }) },
    activitySource:        { type: Object, default: () => ({ current: 'strava', usable: [] }) },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
// Kommt der Athlet von Garmin zurück, landet er direkt bei den Verbindungen —
// sonst sähe er die Rückmeldung zum Verbinden gar nicht.
const backFromGarmin = !!props.garminOfficial?.status;
// „Mehr" verlinkt direkt in einen Abschnitt (/profile?section=connections).
const SECTION_KEYS = ['personal', 'coach', 'athlete', 'notifications', 'connections', 'security', 'account'];
const requestedSection = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('section')
    : null;
const startSection = backFromGarmin ? 'connections' : (SECTION_KEYS.includes(requestedSection) ? requestedSection : null);
const activeTab = ref(startSection ?? 'personal');

// Alle vier Zahlen beziehen sich auf Laeufe — deshalb steht hier "Läufe"
// und nicht "Aktivitäten": Radtouren und Schwimmen zaehlen nicht mit.
const heroStats = computed(() => [
    { label: 'Läufe',        value: props.athleteStats?.total_runs ?? 0 },
    { label: 'Kilometer',    value: Number(props.athleteStats?.total_km ?? 0).toLocaleString('de-DE', { maximumFractionDigits: 0 }) },
    { label: 'Längster',     value: Number(props.athleteStats?.longest_km ?? 0).toLocaleString('de-DE', { maximumFractionDigits: 1 }), unit: 'km' },
    { label: 'Ø Pace',       value: props.athleteStats?.avg_pace   ?? '–' },
]);

const tabs = [
    { key: 'personal',      label: 'Persönlich',         hint: () => user.value?.name },
    { key: 'coach',         label: 'Mein Coach',         short: 'Coach',         hint: () => savedCoachName.value },
    { key: 'athlete',       label: 'Athletenprofil',     short: 'Athlet',     hint: () => (props.runnerProfile?.threshold_speed ? 'Schwellenpace hinterlegt' : 'Noch unvollständig') },
    { key: 'notifications', label: 'Benachrichtigungen', short: 'Mitteilungen', hint: () => null },
    { key: 'connections',   label: 'Verbindungen',       hint: () => (props.stravaConnected ? 'Strava verbunden' : 'Strava nicht verbunden') },
    { key: 'security',      label: 'Sicherheit',         hint: () => null },
    { key: 'account',       label: 'Konto',              hint: () => null },
];

/**
 * Auf dem Telefon ist das Profil eine Einstellungsliste, kein Reiterband.
 *
 * Sieben Reiter ergaben eine Leiste von 802 Pixeln bei 343 sichtbaren:
 * zweieinhalb Reiter zu sehen, viereinhalb hinter dem rechten Rand.
 * Benachrichtigungen, Verbindungen, Sicherheit und Konto waren damit
 * praktisch versteckt. Ab lg bleibt es beim Reiterband — dort ist der Platz
 * da, und der Wechsel ohne Seitenwechsel ist bequemer.
 */
const sectionOpen = ref(!!startSection);

function openSection(key) {
    activeTab.value = key;
    sectionOpen.value = true;
    nextTick(() => window.scrollTo({ top: 0 }));
}

const activeTabLabel = computed(() => tabs.find(t => t.key === activeTab.value)?.label ?? '');

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
    orange: { bg: 'bg-warn', ring: 'ring-warn', light: 'bg-warn-soft', border: 'border-warn', badge: 'bg-warn-soft text-warn-ink' },
    blue:   { bg: 'bg-info',   ring: 'ring-info',   light: 'bg-info-soft',   border: 'border-info',   badge: 'bg-info-soft text-info-ink'   },
    green:  { bg: 'bg-success',  ring: 'ring-success',  light: 'bg-success-soft',  border: 'border-success',  badge: 'bg-success-soft text-success-ink'  },
    purple: { bg: 'bg-accent', ring: 'ring-accent', light: 'bg-accent-soft', border: 'border-accent', badge: 'bg-accent-soft text-accent-ink' },
};
const specialtyLabels = { motivator: 'Motivator', strategist: 'Stratege', companion: 'Begleiter' };

// Wie in den iOS-Einstellungen: antippen heißt auswählen, ohne Sichern-Knopf.
function chooseCoach(id) {
    if (id === selectedCoachId.value && savedCoachName.value) return;
    selectedCoachId.value = id;
    saveCoach();
}

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
    notify_monthly_review:   props.notificationSettings?.notify_monthly_review ?? true,
});
const notifSaving = ref(false);
const notifSaved  = ref(false);

// Newsletter opt-in/out
const newsletterOptIn  = ref(user.value?.newsletter_opt_in ?? true);
const newsletterSaving = ref(false);
const newsletterSaved  = ref(false);

async function toggleNewsletter() {
    newsletterSaving.value = true;
    try {
        await axios.post(route('newsletter.preference'), { newsletter_opt_in: newsletterOptIn.value });
        newsletterSaved.value = true;
        setTimeout(() => { newsletterSaved.value = false; }, 2500);
    } finally {
        newsletterSaving.value = false;
    }
}

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

// Schalter wirken sofort, wie überall auf iOS.
function setNotif(key, value) {
    notifSettings.value[key] = value;
    saveNotifSettings();
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
// Der Accessor liefert die Zonen als Objekt (z1 … z5), nicht als Liste —
// `.length` war darum undefined, und das Profil zeigte trotz VDOT „Speichere
// dein Profil um deine Laufzonen zu berechnen" (gefunden 29.10.2026).
const paceZones   = computed(() => Object.values(props.runnerProfile?.pace_zones ?? {}));
const athleteSaved = computed(() => props.status === 'athlete-saved');

// ── Persönliche Rekorde ─────────────────────────────────────────────────────
const hasAnyPr = computed(() => (props.personalRecords ?? []).some(d => d.entries.length > 0));
const prDistances = computed(() => (props.personalRecords ?? []).filter(d => d.entries.length > 0));
const medal = (rank) => String(rank);

/**
 * Der Verlauf der Bestzeit einer Distanz.
 *
 * Vorher zeichnete die Linie jeden einzelnen Best-Effort, den Strava je
 * gemeldet hat — bei 89 Läufen also 89 Punkte, überwiegend lockere
 * Dauerläufe. Zwischen Minimum und Maximum normiert ergab das ein Zickzack,
 * in dem jeder ruhige Lauf ein Ausschlag war. Es stand neben einer Karte
 * mit der Überschrift „Persönliche Rekorde" und zeigte alles außer denen.
 *
 * Gezeichnet wird jetzt das laufende Minimum: die Treppe, auf der sich die
 * Bestzeit verbessert hat. Sie kann nur fallen, steht also nie im
 * Widerspruch zu den drei Zeiten darunter — und eine flache Linie heißt
 * schlicht: seit dem ersten Mal nicht mehr unterboten.
 */
function prTrend(key) {
    const pts = props.prHistory?.[key] ?? [];
    if (pts.length < 2) return null;

    let best = Infinity;
    const records = pts.map(p => {
        best = Math.min(best, p.elapsed_time);
        return { date: p.date, time: best };
    });

    const first = records[0].time;
    const last  = records[records.length - 1].time;
    const range = (first - last) || 1;

    const w = 96, h = 24, n = records.length;

    // Ohne jede Verbesserung ist die Treppe eine Gerade — dann zeigt eine
    // Linie auf halber Hoehe ehrlicher an, dass sich nichts getan hat, als
    // eine, die am oberen Rand klebt.
    const path = records.map((r, i) => {
        const x = (i / (n - 1)) * w;
        const y = first === last ? h / 2 : h - 2 - ((first - r.time) / range) * (h - 4);
        return (i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1);
    }).join(' ');

    const gained = Math.round(first - last);

    return {
        path,
        w, h,
        endX: w,
        endY: first === last ? h / 2 : 2,
        gained,
        label: gained > 0
            ? `−${formatGain(gained)} seit ${monthLabel(records[0].date)}`
            : `unverändert seit ${monthLabel(records[0].date)}`,
    };
}

/** „27 s" oder „1:12" — Sekunden bleiben Sekunden, ab einer Minute wird es Zeit. */
function formatGain(sec) {
    if (sec < 60) return `${sec} s`;
    return `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')} min`;
}

function monthLabel(iso) {
    return new Date(iso + 'T00:00:00').toLocaleDateString('de-DE', { month: 'short', year: '2-digit' });
}

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
    if (!availability.value[key].available) {
        availability.value[key].duration_min = 0;
        delete availability.value[key].fixed;   // ohne Tag kein Termin
        delete availability.value[key].second_min;
    } else if (!availability.value[key].duration_min) {
        availability.value[key].duration_min = 60;
    }
}

// ── Feste Wochentermine ──────────────────────────────────────────────
// Laufclub, Vereinstraining: der Tag ist belegt, aber der Inhalt wechselt
// und wird nicht geplant. Ihn als Ruhetag einzutragen waere falsch — das
// Geruest wuerde die Qualitaetseinheit dann ein zweites Mal auf einen
// anderen Tag legen.
const fixedTypes = [
    { value: 'interval',  label: 'Intervalle' },
    { value: 'tempo_run', label: 'Tempolauf' },
    { value: 'easy_run',  label: 'Lockerer Lauf' },
    { value: 'long_run',  label: 'Langer Lauf' },
];

function toggleFixed(key) {
    const day = availability.value[key];
    if (day.fixed) {
        delete day.fixed;
    } else {
        day.fixed = { type: 'interval', label: '' };
    }
}

const availError = ref('');

async function saveAvailability() {
    availSaving.value = true;
    availError.value  = '';
    try {
        await axios.post(route('onboarding.availability'), { availability: availability.value });
        availSaved.value = true;
        setTimeout(() => { availSaved.value = false; }, 2500);
    } catch (e) {
        // Ohne diesen Zweig lief ein abgelehntes Speichern voellig lautlos:
        // der Knopf hoerte auf zu laden, nichts war gesichert, und beim
        // naechsten Laden stand wieder der alte Stand da.
        availError.value = Object.values(e?.response?.data?.errors ?? {})[0]?.[0]
            ?? e?.response?.data?.message
            ?? 'Speichern fehlgeschlagen.';
    } finally {
        availSaving.value = false;
    }
}

// ── Trainingsmodell ──────────────────────────────────────────────────────────
// Dieselben Felder wie im Onboarding, derselbe Endpunkt. Gilt ab dem
// naechsten Wochenplan — eine laufende Woche wird nicht umgeworfen.
const modelForm = ref({
    // Ein offener Wechsel zeigt das gewählte Modell, nicht das noch gültige.
    training_model:           props.runnerProfile?.training_model_next ?? props.runnerProfile?.training_model ?? 'polarized',
    running_experience_years: props.runnerProfile?.running_experience_years ?? null,
    has_lactate_meter:        props.runnerProfile?.has_lactate_meter ?? false,
    hills_available:          props.runnerProfile?.hills_available ?? true,
});
const modelSaving = ref(false);
const modelSaved  = ref(false);
const modelError  = ref('');
const modelNextFrom = ref(props.runnerProfile?.training_model_next ? props.runnerProfile?.training_model_next_from : null);

const modelNextLabel = computed(() => {
    if (!modelNextFrom.value) return null;
    const d = new Date(String(modelNextFrom.value).slice(0, 10) + 'T00:00:00');
    return d.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });
});

async function saveModel() {
    modelSaving.value = true;
    modelError.value  = '';
    try {
        const { data } = await axios.post(route('onboarding.training-model'), { ...modelForm.value });
        modelNextFrom.value = data?.next ? data.next_from : null;
        modelSaved.value = true;
        setTimeout(() => { modelSaved.value = false; }, 2500);
    } catch (e) {
        modelError.value = Object.values(e?.response?.data?.errors ?? {})[0]?.[0] ?? 'Speichern fehlgeschlagen.';
    } finally {
        modelSaving.value = false;
    }
}

// Zweites Zeitfenster am Abend — nur fuer die Doppel-Schwelle gebraucht.
const secondOptions = [30, 45, 60];
function toggleSecond(key) {
    const day = availability.value[key];
    if (day.second_min) delete day.second_min;
    else day.second_min = 45;
}

// ── Rad / Zwift ──────────────────────────────────────────────────────────────
const bikeForm = ref({
    bike_enabled:        props.runnerProfile?.bike_enabled ?? false,
    bike_season:         props.runnerProfile?.bike_season ?? 'winter',
    bike_purpose:        props.runnerProfile?.bike_purpose ?? 'both',
    bike_max_per_week:   props.runnerProfile?.bike_max_per_week ?? 2,
    bike_preferred_days: [...(props.runnerProfile?.bike_preferred_days ?? [])],
    ftp:                 props.runnerProfile?.ftp ?? null,
});
const bikeSaving = ref(false);
const bikeSaved  = ref(false);
const bikeError  = ref('');

async function saveBike() {
    bikeSaving.value = true;
    bikeError.value  = '';
    try {
        await axios.post(route('onboarding.bike'), { ...bikeForm.value, ftp: bikeForm.value.ftp || null });
        bikeSaved.value = true;
        setTimeout(() => { bikeSaved.value = false; }, 2500);
    } catch (e) {
        bikeError.value = Object.values(e?.response?.data?.errors ?? {})[0]?.[0] ?? 'Speichern fehlgeschlagen.';
    } finally {
        bikeSaving.value = false;
    }
}

// Den Vorschlag übernehmen heißt: Feld setzen und gleich speichern — ein
// Vorschlag, der erst noch einen zweiten Knopf braucht, bleibt liegen.
const ftpTaken = ref(false);
async function takeFtpSuggestion() {
    bikeForm.value.ftp = props.ftpSuggestion.estimate;
    await saveBike();
    if (!bikeError.value) ftpTaken.value = true;
}

// ── Strength & core ──────────────────────────────────────────────────────────
const equipmentOptions = [
    { value: 'kettlebell', label: 'Kettlebell' },
    { value: 'dumbbells',  label: 'Kurzhanteln' },
    { value: 'gym',        label: 'Gym' },
    { value: 'bodyweight', label: 'Körpergewicht' },
    { value: 'band',       label: 'Band' },
];
const strength = ref({
    strength_enabled:       props.runnerProfile?.strength_enabled ?? false,
    strength_days_per_week: props.runnerProfile?.strength_days_per_week ?? 2,
    strength_equipment:     [...(props.runnerProfile?.strength_equipment ?? [])],
    strength_experience:    props.runnerProfile?.strength_experience ?? 'intermediate',
});
const strengthSaving = ref(false);
const strengthSaved  = ref(false);

function toggleStrengthEquipment(value) {
    const arr = strength.value.strength_equipment;
    const i = arr.indexOf(value);
    if (i === -1) arr.push(value); else arr.splice(i, 1);
}

async function saveStrength() {
    strengthSaving.value = true;
    try {
        await axios.post(route('onboarding.strength'), { ...strength.value });
        strengthSaved.value = true;
        setTimeout(() => { strengthSaved.value = false; }, 2500);
    } finally {
        strengthSaving.value = false;
    }
}

// Dieselben Farben wie die Runden auf der Aktivitätsseite.
const zoneDots  = ['bg-ink-3', 'bg-success', 'bg-accent', 'bg-warn', 'bg-danger'];
const zoneNames = ['Zone 1 · Erholung', 'Zone 2 · Locker', 'Zone 3 · Tempo', 'Zone 4 · Schwelle', 'Zone 5 · VO2max'];
const zoneColors = [
    'bg-info-soft border-info/25 text-info',
    'bg-success-soft border-success/25 text-success',
    'bg-warn-soft border-warn/25 text-warn-ink',
    'bg-warn-soft border-warn/25 text-warn',
    'bg-danger-soft border-danger/25 text-danger',
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

// ── Telegram ─────────────────────────────────────────────────────────────────
// Verbinden öffnet den Bot mit einem Einmal-Code (Inertia::location auf t.me).
const telegramForm = useForm({});
const linkTelegram   = () => telegramForm.post(route('telegram.link'));
const unlinkTelegram = () => telegramForm.delete(route('telegram.unlink'), { preserveScroll: true });

// ── Kalender-Abo ─────────────────────────────────────────────────────────────
// Die URL ist geheim: wer sie hat, sieht den Plan. Neu erzeugen macht die alte tot.
const calendarForm   = useForm({});
const calendarCopied = ref(false);
const confirmCalendarRenew = ref(false);
const createCalendarFeed = () => calendarForm.post(route('calendar-feed.store'), {
    preserveScroll: true,
    onSuccess: () => { confirmCalendarRenew.value = false; },
});
const removeCalendarFeed = () => calendarForm.delete(route('calendar-feed.destroy'), { preserveScroll: true });
const copyCalendarUrl = async () => {
    try {
        await navigator.clipboard.writeText(props.calendarFeed.url);
        calendarCopied.value = true;
        setTimeout(() => { calendarCopied.value = false; }, 2000);
    } catch {
        window.prompt('Adresse kopieren:', props.calendarFeed.url);
    }
};

// ── Garmin-Verbindung (OAuth) ────────────────────────────────────────────────
const showGarminForm = ref(false);

// Die Einwilligung ist Pflicht: Garmin verlangt sie für die KI-Auswertung.
const garminConsent        = ref(false);
const garminOAuthBusy      = ref(false);

const garminOfficialConnection = computed(() => props.garminOfficial?.connection ?? null);
const garminLinked = computed(() => !!garminOfficialConnection.value);
const garminGranted = computed(() => {
    const c = garminOfficialConnection.value;
    if (!c) return '';
    return [c.activities && 'Aktivitäten', c.health && 'Erholungswerte', c.workouts && 'Workouts'].filter(Boolean).join(', ');
});

function connectGarminOfficial() {
    if (!garminConsent.value) return;
    garminOAuthBusy.value = true;
    router.post(route('garmin.connect'), { ai_consent: true }, {
        onFinish: () => { garminOAuthBusy.value = false; },
    });
}

// Plan automatisch auf die Uhr (Training API, die nächsten 7 Tage).
const garminWorkoutSync = ref(props.garminOfficial?.workoutSync ?? true);
function toggleGarminWorkoutSync() {
    const enabled = !garminWorkoutSync.value;
    garminWorkoutSync.value = enabled;
    router.patch(route('profile.garmin-workout-sync'), { enabled }, {
        preserveScroll: true,
        onError: () => { garminWorkoutSync.value = !enabled; },
    });
}

function disconnectGarminOfficial() {
    garminOAuthBusy.value = true;
    router.delete(route('garmin.connection.destroy'), {
        preserveScroll: true,
        onFinish: () => { garminOAuthBusy.value = false; },
    });
}

// ── Aktivitäten kommen von ───────────────────────────────────────────────────
// Eine Quelle pro Athlet. Wählbar nur, wenn Strava verbunden ist UND Garmin
// Aktivitäten freigegeben hat — sonst gibt es nichts zu wählen.
const activitySourceOptions = [{ value: 'strava', label: 'Strava' }, { value: 'garmin', label: 'Garmin' }];
const activitySourceChoosable = computed(() => (props.activitySource?.usable ?? []).length > 1);
const activitySourceValue = ref(props.activitySource?.current ?? 'strava');
watch(activitySourceValue, (source, previous) => {
    if (source === props.activitySource?.current) return;
    router.patch(route('profile.activity-source'), { source }, {
        preserveScroll: true,
        onError: () => { activitySourceValue.value = previous; },
    });
});

// ── Helpers ──────────────────────────────────────────────────────────────────
// Die Formularfelder der App liegen in app.css (.z-input) — hier nur der Verweis.
const inputClass = 'z-input';
</script>

<template>
    <Head title="Profil" />
    <AuthenticatedLayout>
        <!--
            Redesign 29.10.2026: das Profil war die letzte Seite in der alten
            Optik — Karten mit eigener Kopfzeile, graue Kästen darin, kleine
            Schrift, selbstgebaute Schalter. Jetzt gruppierte Listen wie in den
            iOS-Einstellungen (FormGroup/FormRow/Toggle), dieselben wie auf „Mehr“.
            Auf dem Telefon ist ein Abschnitt eine Unterseite; ab lg wechselt
            eine Segmentleiste zwischen den Abschnitten.
        -->
        <div class="min-h-screen bg-canvas">
            <!-- Kopf: Telefon im Abschnitt mit Zurück, sonst „Profil" -->
            <header v-if="sectionOpen" class="px-4 pb-2 pt-2 lg:hidden">
                <button type="button"
                    class="-ml-2 mb-1 inline-flex items-center gap-0.5 rounded-field px-2 py-1 text-[17px] text-accent transition-[opacity,transform] duration-150 ease-out-strong active:scale-[0.97]"
                    @click="requestedSection ? router.visit(route('more')) : (sectionOpen = false)">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    {{ requestedSection ? 'Mehr' : 'Profil' }}
                </button>
                <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em] text-ink">{{ activeTabLabel }}</h1>
            </header>
            <PageHeader width="max-w-3xl" title="Profil" :class="sectionOpen ? 'hidden lg:block' : ''" />

            <div class="mx-auto max-w-3xl space-y-7 px-4 pb-10 pt-3 lg:px-8">

                <!-- ══ Athlet ══ -->
                <section :class="sectionOpen ? 'hidden lg:block' : ''">
                    <div class="rounded-card bg-surface p-4">
                        <div class="flex items-center gap-4">
                            <button type="button" @click="triggerAvatarUpload"
                                class="group relative flex h-[72px] w-[72px] shrink-0 items-center justify-center overflow-hidden rounded-full bg-accent transition active:scale-[0.97]"
                                aria-label="Profilbild ändern">
                                <img v-if="avatarUrl" :src="avatarUrl" alt="" class="absolute inset-0 h-full w-full object-cover" @error="avatarImgError = true" />
                                <span v-else class="select-none text-[26px] font-bold text-white">{{ initials }}</span>
                                <span v-if="avatarUploading" class="absolute inset-0 flex items-center justify-center bg-black/50">
                                    <svg class="h-6 w-6 animate-spin text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                </span>
                            </button>
                            <input ref="avatarInput" type="file" accept="image/*" class="hidden" @change="onAvatarChange" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[22px] font-bold leading-7 text-ink">{{ user.name }}</p>
                                <p class="truncate text-[15px] text-ink-3">
                                    {{ [user.location, user.favorite_distance, user.birth_year ? `${new Date().getFullYear() - user.birth_year} Jahre` : null].filter(Boolean).join(' · ') || user.email }}
                                </p>
                                <button type="button" class="mt-0.5 text-[15px] text-accent active:opacity-60" @click="triggerAvatarUpload">Foto ändern</button>
                            </div>
                        </div>
                        <p v-if="user.bio" class="mt-3 text-[15px] leading-snug text-ink-2">{{ user.bio }}</p>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div v-for="stat in heroStats" :key="stat.label" class="rounded-card bg-surface px-4 py-3">
                            <p class="text-[13px] text-ink-3">{{ stat.label }}</p>
                            <p class="mt-0.5 font-rounded text-[28px] font-bold leading-8 tracking-[-0.02em] text-ink">
                                {{ stat.value }}<span v-if="stat.unit" class="ml-1 text-[15px] font-semibold text-ink-3">{{ stat.unit }}</span>
                            </p>
                        </div>
                    </div>
                </section>

                <!-- ══ Bestzeiten ══ -->
                <FormGroup v-if="hasAnyPr" title="Bestzeiten" footer="Aus deinen Läufen. Tippe auf eine Zeit für den Lauf."
                    :class="sectionOpen ? 'hidden lg:block' : ''">
                    <Link v-for="dist in prDistances" :key="dist.key"
                        :href="`/activities/${dist.entries[0].activity_id}`"
                        class="flex items-center gap-3 px-4 py-3 transition-colors active:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[17px] text-ink">{{ dist.label }}</span>
                            <span class="block truncate text-[13px] text-ink-3">
                                {{ dist.entries[0].pace }} /km · {{ dist.entries[0].date }}<template v-if="dist.entries.length > 1"> · danach {{ dist.entries.slice(1).map(e => e.time_formatted).join(', ') }}</template>
                            </span>
                        </span>
                        <span v-if="prTrend(dist.key)" class="hidden shrink-0 text-right sm:block">
                            <svg :viewBox="`0 0 ${prTrend(dist.key).w} ${prTrend(dist.key).h}`" class="h-6 w-20 text-accent" role="img"
                                :aria-label="`Verlauf der Bestzeit: ${prTrend(dist.key).label}`">
                                <path :d="prTrend(dist.key).path" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
                                <circle :cx="prTrend(dist.key).endX - 2" :cy="prTrend(dist.key).endY" r="2.25" fill="currentColor" />
                            </svg>
                            <span class="block text-[11px] tabular-nums" :class="prTrend(dist.key).gained > 0 ? 'text-success' : 'text-ink-3'">{{ prTrend(dist.key).label }}</span>
                        </span>
                        <span class="shrink-0 font-rounded text-[22px] font-bold text-ink">{{ dist.entries[0].time_formatted }}</span>
                        <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </Link>
                </FormGroup>

                <!-- ══ Abschnitte — Telefon: Liste ══ -->
                <FormGroup v-if="!sectionOpen" title="Einstellungen" class="lg:hidden">
                    <button v-for="tab in tabs" :key="tab.key" type="button"
                        class="flex min-h-[48px] w-full items-center gap-3 px-4 py-2 text-left transition-colors active:bg-surface-2"
                        @click="openSection(tab.key)">
                        <span class="min-w-0 flex-1 truncate text-[17px] text-ink">{{ tab.label }}</span>
                        <span v-if="tab.hint()" class="max-w-[45%] shrink-0 truncate text-[15px] text-ink-3">{{ tab.hint() }}</span>
                        <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </button>
                </FormGroup>

                <!-- ══ Abschnitte — ab lg: Segmentleiste ══ -->
                <div class="hidden lg:block">
                    <SegmentedControl v-model="activeTab" :options="tabs.map(t => ({ value: t.key, label: t.short ?? t.label }))" />
                </div>

                <!-- ══ Inhalt des Abschnitts ══ -->
                <div class="space-y-7" :class="sectionOpen ? 'block' : 'hidden lg:block'">

                    <!-- ── PERSÖNLICH ── -->
                    <form v-if="activeTab === 'personal'" class="space-y-7" @submit.prevent="profileForm.patch(route('profile.update'), { preserveScroll: true })">
                        <p v-if="props.status === 'profile-information-updated' || props.status === 'avatar-updated'"
                            class="rounded-card bg-success-soft px-4 py-3 text-[15px] text-success-ink">Profil gespeichert.</p>

                        <FormGroup>
                            <FormRow as="label" label="Name">
                                <input v-model="profileForm.name" type="text" required autocomplete="name" class="z-inline w-1/2" />
                            </FormRow>
                            <FormRow as="label" label="E-Mail">
                                <input v-model="profileForm.email" type="email" required autocomplete="username" class="z-inline w-2/3" />
                            </FormRow>
                            <FormRow as="label" label="Wohnort">
                                <input v-model="profileForm.location" type="text" placeholder="München" class="z-inline w-1/2" />
                            </FormRow>
                            <FormRow as="label" label="Geburtsjahr">
                                <input v-model="profileForm.birth_year" type="number" inputmode="numeric" placeholder="1992" min="1940" :max="new Date().getFullYear() - 10" class="z-inline w-24" />
                            </FormRow>
                            <FormRow as="label" label="Lieblingsdistanz">
                                <select v-model="profileForm.favorite_distance" class="z-inline appearance-none text-ink-3">
                                    <option value="">Keine</option>
                                    <option v-for="d in distanceOptions" :key="d" :value="d">{{ d }}</option>
                                </select>
                            </FormRow>
                            <template #footer>
                                <InputError :message="profileForm.errors.name || profileForm.errors.email || profileForm.errors.location || profileForm.errors.birth_year" />
                            </template>
                        </FormGroup>

                        <FormGroup title="Über mich" :footer="`${(profileForm.bio ?? '').length} von 300 Zeichen`">
                            <textarea v-model="profileForm.bio" rows="3" maxlength="300" placeholder="Erzähl etwas über dich als Athlet …"
                                class="block w-full resize-none border-0 bg-transparent px-4 py-3 text-[17px] text-ink placeholder-ink-3 focus:outline-none focus:ring-0" />
                        </FormGroup>
                        <InputError :message="profileForm.errors.bio" />

                        <p v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-card bg-warn-soft px-4 py-3 text-[15px] text-warn-ink">
                            E-Mail noch nicht bestätigt.
                            <Link :href="route('verification.send')" method="post" as="button" class="font-semibold underline">Erneut senden</Link>
                        </p>

                        <AppButton type="submit" block :loading="profileForm.processing">Sichern</AppButton>
                    </form>

                    <!-- ── MEIN COACH ── -->
                    <template v-else-if="activeTab === 'coach'">
                        <FormGroup title="Dein Coach" :footer="selectedCoach?.description">
                            <button v-for="coach in coaches" :key="coach.id" type="button" role="radio"
                                :aria-checked="selectedCoachId === coach.id" :disabled="coachSaving"
                                class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors active:bg-surface-2"
                                @click="chooseCoach(coach.id)">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[15px] font-bold text-white"
                                    :class="coachColors[coach.avatar_color]?.bg ?? 'bg-accent'">{{ coach.avatar_initials }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[17px] text-ink">{{ coach.name }} <span class="text-ink-3">· {{ specialtyLabels[coach.specialty] ?? coach.specialty }}</span></span>
                                    <span class="block text-[13px] leading-snug text-ink-3">„{{ coach.tagline }}“</span>
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-accent" :class="selectedCoachId === coach.id ? '' : 'invisible'"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            </button>
                        </FormGroup>
                        <p v-if="coachSaved && savedCoachName" class="mx-4 -mt-5 text-[13px] text-success-ink">{{ savedCoachName }} ist jetzt dein Coach.</p>
                    </template>

                    <!-- ── ATHLETENPROFIL ── -->
                    <template v-else-if="activeTab === 'athlete'">
                        <form class="space-y-3" @submit.prevent="submitAthlete">
                            <FormGroup title="Schwelle und Puls" footer="Schwellenpace: das Tempo, das du etwa 60 Minuten halten kannst.">
                                <FormRow as="label" label="Schwellen-HF">
                                    <input v-model="athleteForm.threshold_heart_rate" type="number" inputmode="numeric" min="100" max="220" placeholder="165" required class="z-inline w-16" />
                                    <span class="text-[17px] text-ink-3">bpm</span>
                                </FormRow>
                                <FormRow as="label" label="Maximale HF">
                                    <input v-model="athleteForm.max_heart_rate" type="number" inputmode="numeric" min="100" max="220" placeholder="195" required class="z-inline w-16" />
                                    <span class="text-[17px] text-ink-3">bpm</span>
                                </FormRow>
                                <FormRow as="label" label="Schwellenpace">
                                    <input v-model="athleteForm.threshold_speed" type="text" inputmode="numeric" placeholder="5:30" pattern="[0-9]{1,2}:[0-9]{2}" required class="z-inline w-16" />
                                    <span class="text-[17px] text-ink-3">/km</span>
                                </FormRow>
                            </FormGroup>
                            <InputError :message="athleteForm.errors.threshold_heart_rate || athleteForm.errors.max_heart_rate || athleteForm.errors.threshold_speed" />
                            <p v-if="athleteSaved" class="mx-4 text-[13px] text-success-ink">Gespeichert, Zonen neu berechnet.</p>
                            <AppButton type="submit" block :loading="athleteForm.processing">Sichern und Zonen berechnen</AppButton>
                        </form>

                        <FormGroup v-if="paceZones.length > 0" title="Laufzonen">
                            <div v-for="(zone, idx) in paceZones" :key="idx" class="flex min-h-[48px] items-center gap-3 px-4 py-2">
                                <span class="h-3 w-3 shrink-0 rounded-full" :class="zoneDots[idx]" />
                                <span class="min-w-0 flex-1 truncate text-[17px] text-ink">{{ zoneNames[idx] }}</span>
                                <span class="shrink-0 text-[17px] tabular-nums text-ink-3">{{ zoneRange(zone) }}</span>
                            </div>
                            <template v-if="vdot" #footer>
                                Nach Daniels aus VDOT {{ vdot.value.toFixed(1).replace('.', ',') }}, {{ vdot.label }}. Pace in min/km.
                            </template>
                        </FormGroup>
                        <FormGroup v-else title="Laufzonen" plain>
                            <p class="text-[15px] text-ink-3">Sichere Puls und Schwellenpace, dann berechnet Zone3 deine Zonen.</p>
                        </FormGroup>

                        <section class="space-y-3">
                            <h2 class="mx-4 -mb-1.5 text-[13px] text-ink-3">Trainingsmodell · gilt ab dem nächsten Wochenplan</h2>
                            <TrainingModelFields v-model="modelForm" :readiness="props.norwegianReadiness" />
                            <p v-if="modelNextLabel" class="rounded-card bg-accent-soft px-4 py-3 text-[15px] leading-snug text-accent-ink">
                                Der Wechsel gilt ab {{ modelNextLabel }}. Bis dahin plant Zone3 die laufende Woche mit dem bisherigen Modell weiter.
                            </p>
                            <p v-if="modelError" class="z-error mx-4">{{ modelError }}</p>
                            <p v-if="modelSaved" class="mx-4 text-[13px] text-success-ink">Gespeichert.</p>
                            <AppButton block :loading="modelSaving" @click="saveModel">Modell sichern</AppButton>
                        </section>

                        <!-- Wochenraster -->
                        <section class="space-y-3">
                            <FormGroup title="Wann du trainieren kannst" :footer="`${availabilityDays.filter(d => availability[d.key].available).length} Tage · ${availabilityDays.reduce((s, d) => s + (availability[d.key].available ? (Number(availability[d.key].duration_min) || 0) : 0), 0)} min pro Woche. Der Plan hält sich an diese Zeiten.`">
                                <template v-for="day in availabilityDays" :key="day.key">
                                    <div class="px-4 py-2">
                                        <div class="flex min-h-[32px] items-center gap-3">
                                            <span class="flex-1 text-[17px]" :class="availability[day.key].available ? 'text-ink' : 'text-ink-3'">{{ day.full }}</span>
                                            <span v-if="availability[day.key].fixed" class="truncate text-[15px] text-accent">{{ availability[day.key].fixed.label || 'Fester Termin' }}</span>
                                            <Toggle :model-value="availability[day.key].available" :label="`${day.full} verfügbar`" @update:model-value="toggleAvailDay(day.key)" />
                                        </div>
                                        <div v-if="availability[day.key].available" class="mt-2 space-y-2 pb-1">
                                            <SegmentedControl v-model="availability[day.key].duration_min"
                                                :options="durationOptions.map(d => ({ value: d, label: d < 60 ? `${d}′` : `${d / 60 % 1 ? (d / 60).toLocaleString('de-DE') : d / 60} h` }))" />
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" @click="toggleFixed(day.key)"
                                                    class="h-8 rounded-full px-3 text-[13px] font-semibold transition active:scale-[0.97]"
                                                    :class="availability[day.key].fixed ? 'bg-accent-soft text-accent-ink' : 'bg-surface-2 text-ink-2'">
                                                    {{ availability[day.key].fixed ? 'Fester Termin ✓' : '+ Fester Termin' }}
                                                </button>
                                                <button v-if="modelForm.training_model === 'norwegian'" type="button" @click="toggleSecond(day.key)"
                                                    class="h-8 rounded-full px-3 text-[13px] font-semibold transition active:scale-[0.97]"
                                                    :class="availability[day.key].second_min ? 'bg-accent-soft text-accent-ink' : 'bg-surface-2 text-ink-2'">
                                                    {{ availability[day.key].second_min ? 'Abends ✓' : '+ Abends' }}
                                                </button>
                                            </div>
                                            <SegmentedControl v-if="modelForm.training_model === 'norwegian' && availability[day.key].second_min"
                                                v-model="availability[day.key].second_min"
                                                :options="secondOptions.map(m => ({ value: m, label: `abends ${m}′` }))" />
                                            <div v-if="availability[day.key].fixed" class="space-y-2 rounded-field bg-surface-2 p-3">
                                                <div class="flex gap-2">
                                                    <input v-model="availability[day.key].fixed.label" type="text" maxlength="40" placeholder="z. B. Laufclub"
                                                        class="z-input min-w-0 flex-1 bg-surface py-2" />
                                                    <select v-model="availability[day.key].fixed.type" class="z-input w-36 bg-surface py-2">
                                                        <option v-for="t in fixedTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                                    </select>
                                                </div>
                                                <p class="text-[13px] leading-snug text-ink-3">
                                                    Dieser Tag wird nicht verplant. Die Einheit zählt als deine Qualitätseinheit der Woche, der Coach legt keine zweite daneben.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </FormGroup>
                            <p v-if="availError" class="z-error mx-4">{{ availError }}</p>
                            <p v-if="availSaved" class="mx-4 text-[13px] text-success-ink">Gespeichert.</p>
                            <AppButton block :loading="availSaving" @click="saveAvailability">Zeiten sichern</AppButton>
                        </section>

                        <!-- Kraft & Core -->
                        <section class="space-y-3">
                            <FormGroup title="Kraft und Rumpf">
                                <FormRow label="Im Plan" hint="Ergänzendes Kraft- und Rumpftraining">
                                    <Toggle v-model="strength.strength_enabled" label="Kraft und Rumpf im Plan" />
                                </FormRow>
                                <template v-if="strength.strength_enabled">
                                    <FormRow as="label" label="Pro Woche">
                                        <select v-model.number="strength.strength_days_per_week" class="z-inline appearance-none text-ink-3">
                                            <option v-for="n in 4" :key="n" :value="n">{{ n }}×</option>
                                        </select>
                                    </FormRow>
                                    <FormRow as="label" label="Erfahrung">
                                        <select v-model="strength.strength_experience" class="z-inline appearance-none text-ink-3">
                                            <option value="beginner">Anfänger</option>
                                            <option value="intermediate">Mittel</option>
                                            <option value="advanced">Fortgeschritten</option>
                                        </select>
                                    </FormRow>
                                    <FormRow label="Ausrüstung" stacked>
                                        <div class="flex flex-wrap gap-2">
                                            <button v-for="opt in equipmentOptions" :key="opt.value" type="button" @click="toggleStrengthEquipment(opt.value)"
                                                class="h-8 rounded-full px-3 text-[15px] font-semibold transition active:scale-[0.97]"
                                                :class="strength.strength_equipment.includes(opt.value) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-2'">
                                                {{ opt.label }}
                                            </button>
                                        </div>
                                    </FormRow>
                                </template>
                            </FormGroup>
                            <p v-if="strengthSaved" class="mx-4 text-[13px] text-success-ink">Gespeichert.</p>
                            <AppButton block :loading="strengthSaving" @click="saveStrength">Kraft sichern</AppButton>
                        </section>
                    </template>

                    <!-- ── BENACHRICHTIGUNGEN ── -->
                    <template v-else-if="activeTab === 'notifications'">
                        <FormGroup title="Auf diesem Gerät">
                            <FormRow v-if="!pushSupported" label="Push" hint="Dieser Browser unterstützt keine Mitteilungen. Auf dem iPhone geht es nur aus der Homescreen-App." />
                            <template v-else>
                                <FormRow label="Mitteilungen" :hint="pushPermission === 'denied' ? 'Im Browser blockiert' : (pushSubscribed ? 'Aktiv auf diesem Gerät' : 'Aus')">
                                    <svg v-if="pushLoading" class="h-5 w-5 animate-spin text-ink-3" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    <Toggle :model-value="pushSubscribed" label="Mitteilungen auf diesem Gerät" :disabled="pushLoading || pushPermission === 'denied'"
                                        @update:model-value="v => v ? subscribePush() : unsubscribePush()" />
                                </FormRow>
                                <button v-if="pushSubscribed" type="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2" @click="sendTestPush">
                                    {{ pushTestSent ? 'Gesendet' : 'Test-Mitteilung senden' }}
                                </button>
                            </template>
                            <template v-if="pushError" #footer><span class="text-danger">{{ pushError }}</span></template>
                        </FormGroup>

                        <FormGroup title="Was Zone3 meldet" :footer="notifSaved ? 'Gespeichert.' : null">
                            <FormRow as="label" label="Tagesform-Erinnerung">
                                <input v-model="notifSettings.wellbeing_reminder_time" type="time" class="z-inline w-28" @change="saveNotifSettings" />
                            </FormRow>
                            <FormRow label="Schwellenpace neu berechnet">
                                <Toggle :model-value="notifSettings.notify_threshold_pace" label="Schwellenpace neu berechnet" @update:model-value="v => setNotif('notify_threshold_pace', v)" />
                            </FormRow>
                            <FormRow label="Plan aktualisiert">
                                <Toggle :model-value="notifSettings.notify_plan_updated" label="Plan aktualisiert" @update:model-value="v => setNotif('notify_plan_updated', v)" />
                            </FormRow>
                            <FormRow label="Monatsrückblick" hint="Am Monatsanfang per Push und E-Mail">
                                <Toggle :model-value="notifSettings.notify_monthly_review" label="Monatsrückblick" @update:model-value="v => setNotif('notify_monthly_review', v)" />
                            </FormRow>
                        </FormGroup>

                        <FormGroup title="Newsletter" :footer="newsletterSaved ? 'Gespeichert.' : `Gelegentlich Neuigkeiten und Trainingstipps an ${user.email}.`">
                            <FormRow label="Newsletter">
                                <Toggle :model-value="newsletterOptIn" label="Newsletter" :disabled="newsletterSaving"
                                    @update:model-value="v => { newsletterOptIn = v; toggleNewsletter(); }" />
                            </FormRow>
                        </FormGroup>
                    </template>

                    <!-- ── VERBINDUNGEN ── -->
                    <template v-else-if="activeTab === 'connections'">
                        <FormGroup title="Dienste">
                            <!-- Strava -->
                            <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-warn text-white">
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[17px] text-ink">Strava</span>
                                    <span class="block truncate text-[13px] text-ink-3">{{ props.stravaConnected ? `Verbunden als ${props.stravaAccount?.username ?? '–'}` : 'Nicht verbunden' }}</span>
                                </span>
                                <button v-if="props.stravaConnected" type="button" class="shrink-0 text-[17px] text-danger active:opacity-60" @click="confirmStravaDisconnect = true">Trennen</button>
                                <Link v-else href="/strava/connect" class="shrink-0 text-[17px] text-accent active:opacity-60">Verbinden</Link>
                            </div>

                            <!-- Garmin -->
                            <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-ink text-canvas">
                                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="7" y="6" width="10" height="12" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l.5-2.5h5L15 6M9 18l.5 2.5h5L15 18" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[17px] text-ink">Garmin Connect</span>
                                    <span class="block text-[13px] leading-snug"
                                        :class="garminOfficialConnection && !garminGranted ? 'text-warn-ink' : 'text-ink-3'">
                                        <template v-if="!props.garminOfficial.available">Auf diesem Server nicht eingerichtet</template>
                                        <template v-else-if="garminOfficialConnection && garminGranted">Verbunden · {{ garminGranted }}</template>
                                        <template v-else-if="garminOfficialConnection">Verbunden, aber keine Daten freigegeben</template>
                                        <template v-else>Nicht verbunden</template>
                                    </span>
                                </span>
                                <template v-if="props.garminOfficial.available">
                                    <button v-if="garminOfficialConnection" type="button" :disabled="garminOAuthBusy" class="shrink-0 text-[17px] text-danger active:opacity-60 disabled:opacity-40" @click="disconnectGarminOfficial">
                                        {{ garminOAuthBusy ? 'Trenne …' : 'Trennen' }}
                                    </button>
                                    <button v-else type="button" class="shrink-0 text-[17px] text-accent active:opacity-60" @click="showGarminForm = !showGarminForm">Verbinden</button>
                                </template>
                            </div>
                            <div v-if="props.garminOfficial.available && showGarminForm && !garminOfficialConnection" class="space-y-3 px-4 py-3">
                                <label class="flex items-start gap-3 text-[15px] leading-snug text-ink-2">
                                    <input v-model="garminConsent" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-line-strong text-accent focus:ring-accent/40" />
                                    <span>
                                        Ich willige ein, dass Zone3 meine Garmin-Daten für Plan, Auswertungen und Coach an OpenAI übermittelt.
                                        Die Daten werden nicht zum Training von KI-Modellen verwendet. Widerruf jederzeit durch Trennen.
                                        <Link :href="route('privacy')" class="text-accent">Datenschutz</Link>
                                    </span>
                                </label>
                                <AppButton block :disabled="!garminConsent" :loading="garminOAuthBusy" @click="connectGarminOfficial">Weiter zu Garmin</AppButton>
                                <p class="text-[13px] leading-snug text-ink-3">
                                    Die Anmeldung läuft direkt bei Garmin, Zone3 sieht dein Passwort nie. Aktiviere dort alle Freigaben, sonst kommen keine Daten an.
                                </p>
                            </div>
                            <FormRow v-if="garminOfficialConnection?.workouts" label="Plan auf die Uhr"
                                hint="Die Läufe der nächsten 7 Tage stehen im Garmin-Kalender und ziehen nach, wenn sich der Plan ändert. Vereinstermine und Rad nicht.">
                                <Toggle :model-value="garminWorkoutSync" label="Plan automatisch auf die Uhr" @update:model-value="toggleGarminWorkoutSync" />
                            </FormRow>
                            <p v-if="garminOfficialConnection && (!garminGranted || garminOfficialConnection.refresh_error)" class="px-4 py-3 text-[13px] leading-snug"
                                :class="garminOfficialConnection.refresh_error ? 'text-danger' : 'text-ink-3'">
                                <template v-if="garminOfficialConnection.refresh_error">Garmin nimmt die Verbindung nicht mehr an. Bitte trennen und neu verbinden.</template>
                                <template v-else>Garmin hat Zone3 keine Daten freigegeben. In der Garmin Connect App unter Einstellungen → Verbundene Apps → Zone3 die Schalter einschalten, oder hier trennen und beim erneuten Verbinden alle Freigaben aktivieren.</template>
                            </p>
                            <p v-if="props.garminOfficial.status" class="px-4 py-3 text-[13px]"
                                :class="props.garminOfficial.status.type === 'error' ? 'text-danger' : 'text-success-ink'">{{ props.garminOfficial.status.message }}</p>

                            <!-- Telegram -->
                            <div v-if="props.telegram.available" class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-info text-white">
                                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 4L3 11l6 2.5M21 4l-3.5 16-8.5-6.5M21 4L9 13.5v5.5l3-3.5" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[17px] text-ink">Telegram</span>
                                    <span class="block text-[13px] leading-snug text-ink-3">{{ props.telegram.linked ? 'Verbunden · dein Coach schreibt dir dort' : 'Briefing, Reviews und Vorschläge im Chat' }}</span>
                                </span>
                                <button v-if="props.telegram.linked" type="button" :disabled="telegramForm.processing" class="shrink-0 text-[17px] text-danger active:opacity-60 disabled:opacity-40" @click="unlinkTelegram">Trennen</button>
                                <button v-else type="button" :disabled="telegramForm.processing" class="shrink-0 text-[17px] text-accent active:opacity-60 disabled:opacity-40" @click="linkTelegram">Verbinden</button>
                            </div>

                            <!-- Kalender-Abo -->
                            <div class="flex min-h-[60px] items-center gap-3 px-4 py-2">
                                <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-danger text-white">
                                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v14H4zM4 10h16M9 3v4M15 3v4" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[17px] text-ink">Kalender-Abo</span>
                                    <span class="block text-[13px] leading-snug text-ink-3">{{ props.calendarFeed ? 'Aktiv · Einheiten und Rennen' : 'Plan und Rennen im iPhone- oder Google-Kalender' }}</span>
                                </span>
                                <button v-if="props.calendarFeed" type="button" :disabled="calendarForm.processing" class="shrink-0 text-[17px] text-danger active:opacity-60 disabled:opacity-40" @click="removeCalendarFeed">Aus</button>
                                <button v-else type="button" :disabled="calendarForm.processing" class="shrink-0 text-[17px] text-accent active:opacity-60 disabled:opacity-40" @click="createCalendarFeed">Einrichten</button>
                            </div>
                            <template v-if="props.calendarFeed">
                                <a :href="props.calendarFeed.webcal" class="flex min-h-[48px] items-center px-4 text-[17px] text-accent active:bg-surface-2">Im Kalender abonnieren</a>
                                <button type="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2" @click="copyCalendarUrl">{{ calendarCopied ? 'Kopiert' : 'Adresse kopieren' }}</button>
                                <button v-if="!confirmCalendarRenew" type="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2" @click="confirmCalendarRenew = true">Neue Adresse erzeugen</button>
                                <div v-else class="flex min-h-[48px] flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 text-[15px]">
                                    <span class="flex-1 text-ink-2">Die alte Adresse geht danach nicht mehr.</span>
                                    <button type="button" :disabled="calendarForm.processing" class="font-semibold text-danger active:opacity-60" @click="createCalendarFeed">Erzeugen</button>
                                    <button type="button" class="text-ink-3 active:opacity-60" @click="confirmCalendarRenew = false">Abbrechen</button>
                                </div>
                            </template>
                            <template v-if="props.calendarFeed" #footer>
                                Ganztägige Termine ohne Ruhetage. Google Kalender: „Andere Kalender → Per URL“ und die Adresse einfügen; Google aktualisiert nur alle paar Stunden. Wer die Adresse hat, sieht deinen Plan.
                            </template>
                        </FormGroup>

                        <FormGroup v-if="activitySourceChoosable" title="Aktivitäten kommen von" plain
                            footer="Neue Läufe kommen nur aus dieser Quelle, damit nichts doppelt im Plan steht. Lädt die Uhr zu beiden hoch, wird dieselbe Einheit zusammengeführt.">
                            <SegmentedControl v-model="activitySourceValue" :options="activitySourceOptions" />
                        </FormGroup>

                        <section class="space-y-3">
                            <h2 class="mx-4 -mb-1.5 text-[13px] text-ink-3">Rad / Zwift · Fahrten kommen über Strava</h2>
                            <BikeFields v-model="bikeForm" />
                            <div v-if="ftpSuggestion && !ftpTaken" class="rounded-card bg-warn-soft px-4 py-3">
                                <p class="text-[17px] font-semibold text-warn-ink">FTP wirkt zu niedrig</p>
                                <p class="mt-1 text-[15px] leading-snug text-ink-2">
                                    „{{ ftpSuggestion.activity_name }}“ am {{ ftpSuggestion.date }} spricht für mindestens
                                    <span class="font-semibold tabular-nums">{{ ftpSuggestion.estimate }} W</span><template v-if="ftpSuggestion.current">, eingetragen sind {{ ftpSuggestion.current }} W</template>.
                                </p>
                                <button type="button" :disabled="bikeSaving" class="mt-2 text-[15px] font-semibold text-accent active:opacity-60 disabled:opacity-50" @click="takeFtpSuggestion">
                                    {{ ftpSuggestion.estimate }} W übernehmen
                                </button>
                            </div>
                            <p v-if="bikeError" class="z-error mx-4">{{ bikeError }}</p>
                            <p v-if="bikeSaved" class="mx-4 text-[13px] text-success-ink">Gespeichert.</p>
                            <AppButton block :loading="bikeSaving" @click="saveBike">Rad sichern</AppButton>
                        </section>
                    </template>

                    <!-- ── SICHERHEIT ── -->
                    <form v-else-if="activeTab === 'security'" class="space-y-3" @submit.prevent="updatePassword">
                        <FormGroup title="Passwort ändern" footer="Nutze ein sicheres Passwort, das du nirgends sonst verwendest.">
                            <FormRow as="label" label="Aktuell">
                                <input ref="currentPasswordInput" v-model="passwordForm.current_password" type="password" autocomplete="current-password" placeholder="erforderlich" class="z-inline w-1/2" />
                            </FormRow>
                            <FormRow as="label" label="Neu">
                                <input ref="passwordInput" v-model="passwordForm.password" type="password" autocomplete="new-password" placeholder="mindestens 8 Zeichen" class="z-inline w-1/2" />
                            </FormRow>
                            <FormRow as="label" label="Bestätigen">
                                <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" placeholder="wiederholen" class="z-inline w-1/2" />
                            </FormRow>
                        </FormGroup>
                        <InputError class="mx-4" :message="passwordForm.errors.current_password || passwordForm.errors.password || passwordForm.errors.password_confirmation" />
                        <p v-if="passwordForm.recentlySuccessful" class="mx-4 text-[13px] text-success-ink">Passwort gespeichert.</p>
                        <AppButton type="submit" block :loading="passwordForm.processing">Passwort ändern</AppButton>
                    </form>

                    <!-- ── KONTO ── -->
                    <template v-else-if="activeTab === 'account'">
                        <FormGroup title="Strava" :footer="props.stravaConnected ? 'Beim Trennen bleiben deine bisherigen Aktivitäten in Zone3. Neue kommen erst wieder, wenn du neu verbindest.' : 'Verbinde Strava, um deine Aktivitäten zu übernehmen.'">
                            <FormRow v-if="props.stravaConnected" :label="props.stravaAccount?.username ?? 'Verbunden'" :hint="`Zuletzt abgeglichen: ${props.stravaAccount?.last_synced_at ?? 'noch nie'}`">
                                <span class="text-[15px] text-success-ink">Aktiv</span>
                            </FormRow>
                            <Link href="/strava/connect" class="flex min-h-[48px] items-center px-4 text-[17px] text-accent active:bg-surface-2">
                                {{ props.stravaConnected ? 'Konto wechseln' : 'Mit Strava verbinden' }}
                            </Link>
                            <button v-if="props.stravaConnected" type="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-danger active:bg-surface-2" @click="confirmStravaDisconnect = true">Strava trennen</button>
                        </FormGroup>

                        <FormGroup footer="Athletenprofil und Ziel noch einmal Schritt für Schritt einrichten.">
                            <Link :href="route('onboarding.reset')" method="post" as="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2">
                                Einrichtung neu starten
                            </Link>
                        </FormGroup>

                        <FormGroup footer="Alle Daten werden unwiderruflich gelöscht.">
                            <button type="button" class="flex min-h-[48px] w-full items-center justify-center px-4 text-[17px] text-danger active:bg-surface-2" @click="confirmDeletion">Konto löschen</button>
                        </FormGroup>
                    </template>
                </div>
            </div>
        </div>

        <!-- Strava trennen -->
        <ConfirmSheet
            :show="confirmStravaDisconnect"
            title="Strava wirklich trennen?"
            message="Die Verbindung wird entfernt. Deine bisherigen Aktivitäten bleiben in Zone3, deine Daten auf Strava bleiben unverändert."
            confirm-label="Ja, trennen"
            :loading="stravaDisconnectForm.processing"
            @confirm="disconnectStrava"
            @close="confirmStravaDisconnect = false"
        />

        <!-- Konto löschen -->
        <ConfirmSheet
            :show="confirmingDeletion"
            title="Konto wirklich löschen?"
            message="Diese Aktion kann nicht rückgängig gemacht werden. Gib dein Passwort ein um zu bestätigen."
            confirm-label="Ja, löschen"
            :loading="deleteForm.processing"
            @confirm="deleteAccount"
            @close="closeDeleteModal"
        >
            <div class="mt-4">
                <input
                    ref="deletePasswordInput"
                    v-model="deleteForm.password"
                    type="password"
                    placeholder="Passwort eingeben"
                    class="z-input focus:border-danger focus:ring-danger/20"
                    @keyup.enter="deleteAccount"
                />
                <InputError :message="deleteForm.errors.password" class="mt-1.5" />
            </div>
        </ConfirmSheet>

    </AuthenticatedLayout>

    <!-- ══ PROFILBILD ZUSCHNEIDEN ══ -->
    <AppSheet :show="showCropModal" title="Profilbild zuschneiden" @close="closeCropModal">
        <div class="-mx-5 bg-black" style="height: 340px;">
            <img
                ref="cropperEl"
                :src="cropImageSrc"
                alt="Bild zuschneiden"
                class="block max-w-full"
                style="max-height: 340px;"
            />
        </div>
        <p class="py-3 text-center text-[13px] text-ink-3">Verschieben und zoomen, um den Ausschnitt anzupassen</p>

        <template #footer>
            <div class="flex gap-3">
                <AppButton variant="secondary" block @click="closeCropModal">Abbrechen</AppButton>
                <AppButton block :loading="avatarUploading" @click="confirmCrop">
                    {{ avatarUploading ? 'Wird hochgeladen …' : 'Sichern' }}
                </AppButton>
            </div>
        </template>
    </AppSheet>
</template>
