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
const activeTab = ref(backFromGarmin ? 'connections' : 'personal');

// Alle vier Zahlen beziehen sich auf Laeufe — deshalb steht hier "Läufe"
// und nicht "Aktivitäten": Radtouren und Schwimmen zaehlen nicht mit.
const heroStats = computed(() => [
    { label: 'Läufe',        value: props.athleteStats?.total_runs ?? 0 },
    { label: 'Kilometer',    value: props.athleteStats?.total_km   ?? 0 },
    { label: 'Längster',     value: props.athleteStats?.longest_km ?? 0, unit: 'km' },
    { label: 'Ø Pace',       value: props.athleteStats?.avg_pace   ?? '–' },
]);

const tabs = [
    { key: 'personal',      label: 'Persönlich',         hint: () => user.value?.name },
    { key: 'coach',         label: 'Mein Coach',         hint: () => savedCoachName.value },
    { key: 'athlete',       label: 'Athletenprofil',     hint: () => (props.runnerProfile?.threshold_speed ? 'Schwellenpace hinterlegt' : 'Noch unvollständig') },
    { key: 'notifications', label: 'Benachrichtigungen', hint: () => null },
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
const sectionOpen = ref(backFromGarmin);

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

// ── Offizielle Garmin-Verbindung (OAuth) ─────────────────────────────────────
// Läuft neben dem alten Login, bis Erholungswerte und Workouts darüber gehen.
// Die Einwilligung ist Pflicht: Garmin verlangt sie für die KI-Auswertung.
const garminConsent        = ref(false);
const garminOAuthBusy      = ref(false);

const garminOfficialConnection = computed(() => props.garminOfficial?.connection ?? null);
const garminLinked = computed(() => props.garminOfficial?.available ? !!garminOfficialConnection.value : garminConnected.value);
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
// Die Formularfelder der App liegen in app.css (.z-input) — hier nur der Verweis.
const inputClass = 'z-input';
</script>

<template>
    <Head title="Profil" />
    <AuthenticatedLayout>
        <!--
            flex-col statt space-y: auf dem Telefon stand die Abschnittsliste
            hinter rund 1200 Pixeln Bestzeiten. Die Liste ist aber die
            Navigation dieser Seite — man scrollte an allem vorbei, um
            ueberhaupt irgendwohin zu kommen. Per order steht sie jetzt oben;
            ab lg bleibt die bisherige Reihenfolge.
        -->
        <div class="flex flex-col gap-5 px-4 py-4 lg:px-6 lg:py-6">

            <!-- ══ PROFILE HERO ══ -->
            <div class="bg-surface rounded-card shadow-card p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                    <div class="flex min-w-0 flex-1 items-center gap-4">

                        <!-- Avatar -->
                        <div class="relative shrink-0">
                            <button
                                @click="triggerAvatarUpload"
                                class="group relative h-20 w-20 sm:h-24 sm:w-24 rounded-full overflow-hidden bg-accent flex items-center justify-center transition hover:ring-2 hover:ring-accent hover:ring-offset-2 hover:ring-offset-surface"
                            >
                                <img v-if="avatarUrl" :src="avatarUrl" alt="Profilbild" class="absolute inset-0 h-full w-full object-cover" @error="avatarImgError = true" />
                                <span v-else class="text-2xl sm:text-3xl font-bold text-white select-none">{{ initials }}</span>
                                <!--
                                    Auf dem Telefon gibt es kein Hover: dort war das
                                    Kamerasymbol unsichtbar und mit ihm der Hinweis,
                                    dass man das Bild ueberhaupt wechseln kann. Es
                                    steht jetzt dauerhaft da und blendet sich erst am
                                    Desktop auf Hover ein.
                                -->
                                <div class="absolute inset-0 bg-black/40 flex items-center justify-center transition-opacity sm:opacity-0 sm:group-hover:opacity-100">
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
                        <div class="flex-1 min-w-0">
                            <h1 class="text-[28px] font-bold leading-[34px] tracking-[-0.4px] text-ink">{{ user.name }}</h1>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5">
                                <span v-if="user.location" class="flex items-center gap-1 text-sm text-ink-3">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                    {{ user.location }}
                                </span>
                                <span v-if="user.favorite_distance" class="flex items-center gap-1 text-sm text-ink-3">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                                    {{ user.favorite_distance }}
                                </span>
                                <span v-if="user.birth_year" class="text-sm text-ink-3">
                                    {{ new Date().getFullYear() - user.birth_year }} Jahre
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Edit button -->
                    <AppButton variant="secondary" size="sm" class="shrink-0 self-start sm:self-auto" @click="openSection('personal')">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                        </svg>
                        Bearbeiten
                    </AppButton>
                </div>

                <p v-if="user.bio" class="mt-4 max-w-2xl text-[15px] leading-relaxed text-ink-2">{{ user.bio }}</p>

                <!-- Stats row -->
                <div class="mt-5 grid grid-cols-2 gap-y-4 border-t border-line pt-5 sm:grid-cols-4">
                    <div v-for="stat in heroStats" :key="stat.label" class="min-w-0">
                        <p class="text-2xl font-bold tabular-nums tracking-tight text-ink">
                            {{ stat.value }}<span v-if="stat.unit" class="ml-0.5 text-sm font-medium text-ink-3">{{ stat.unit }}</span>
                        </p>
                        <p class="mt-0.5 truncate text-[13px] font-medium text-ink-3">{{ stat.label }}</p>
                    </div>
                </div>
            </div>

            <!-- ══ PERSÖNLICHE REKORDE ══ -->
            <!-- Waehrend ein Abschnitt offen ist, haben die Rekorde darunter nichts zu suchen. -->
            <div v-if="hasAnyPr" class="order-3 rounded-card bg-surface p-5 shadow-card sm:p-6 lg:order-none"
                :class="sectionOpen ? 'hidden lg:block' : ''">
                <div class="flex items-center gap-3">
                    <svg class="h-5 w-5 shrink-0 text-warn" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" /></svg>
                    <div>
                        <h2 class="text-[15px] font-semibold text-ink">Persönliche Rekorde</h2>
                        <p class="mt-0.5 text-[13px] text-ink-3">Deine schnellsten Zeiten je Distanz – aus deinen Läufen</p>
                    </div>
                </div>
                <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="dist in prDistances"
                        :key="dist.key"
                        class="rounded-card bg-surface-2 p-4"
                    >
                        <!--
                            Die Linie braucht eine Beschriftung, sonst ist sie
                            Dekoration: darunter steht, wie viel die Bestzeit
                            seit wann gewonnen hat. preserveAspectRatio="none"
                            ist raus — es zog die Kurve in die Breite.
                        -->
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <h3 class="text-sm font-bold text-ink">{{ dist.label }}</h3>
                            <div v-if="prTrend(dist.key)" class="shrink-0 text-right">
                                <svg :viewBox="`0 0 ${prTrend(dist.key).w} ${prTrend(dist.key).h}`"
                                    class="h-6 w-24 text-accent" role="img"
                                    :aria-label="`Verlauf der Bestzeit: ${prTrend(dist.key).label}`">
                                    <path :d="prTrend(dist.key).path" fill="none" stroke="currentColor"
                                        stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle :cx="prTrend(dist.key).endX - 2" :cy="prTrend(dist.key).endY"
                                        r="2.25" fill="currentColor" />
                                </svg>
                                <p class="mt-0.5 text-[11px] tabular-nums"
                                    :class="prTrend(dist.key).gained > 0 ? 'text-success' : 'text-ink-3'">
                                    {{ prTrend(dist.key).label }}
                                </p>
                            </div>
                        </div>
                        <ul class="space-y-1">
                            <li v-for="entry in dist.entries" :key="entry.rank">
                                <Link
                                    :href="`/activities/${entry.activity_id}`"
                                    class="flex items-center gap-3 rounded-field px-2 py-2 transition-colors hover:bg-surface"
                                >
                                    <!-- Rang als Ziffer: Platz 1 traegt die Betonung, der Rest ordnet sich unter. -->
                                    <span class="w-6 shrink-0 text-center font-rounded text-[15px] tabular-nums"
                                        :class="entry.rank === 1 ? 'font-bold text-ink' : 'text-ink-3'">{{ medal(entry.rank) }}</span>
                                    <span class="font-bold tabular-nums text-ink">{{ entry.time_formatted }}</span>
                                    <span class="text-xs text-ink-3">{{ entry.pace }}/km</span>
                                    <span class="ml-auto text-xs text-ink-3 shrink-0">{{ entry.date }}</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- ══ ABSCHNITTE — Telefon: Liste ══ -->
            <div v-if="!sectionOpen" class="order-2 overflow-hidden rounded-card bg-surface shadow-card lg:order-none lg:hidden">
                <button
                    v-for="(tab, i) in tabs"
                    :key="tab.key"
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-4 text-left transition-colors active:bg-surface-2"
                    :class="i < tabs.length - 1 ? 'border-b border-line' : ''"
                    @click="openSection(tab.key)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-[15px] font-medium text-ink">{{ tab.label }}</span>
                        <span v-if="tab.hint()" class="mt-0.5 block truncate text-[13px] text-ink-3">{{ tab.hint() }}</span>
                    </span>
                    <svg class="h-4 w-4 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
            </div>

            <!-- Zurück aus einem Abschnitt — nur auf dem Telefon nötig -->
            <button
                v-if="sectionOpen"
                type="button"
                class="order-1 -ml-2 inline-flex h-11 items-center gap-1 rounded-field px-2 text-sm font-medium text-ink-3 transition-colors active:text-ink lg:order-none lg:hidden"
                @click="sectionOpen = false"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
                Profil
            </button>

            <!-- ══ REITER — ab lg ══ -->
            <div class="order-2 hidden gap-1 rounded-full bg-surface-2 p-1 lg:order-none lg:flex" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    @click="activeTab = tab.key"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition duration-150 active:scale-[0.98]"
                    :class="activeTab === tab.key
                        ? 'bg-surface text-ink shadow-card'
                        : 'text-ink-3 hover:text-ink-2'"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- ══ TAB CONTENT ══ -->
            <div class="order-2 overflow-hidden rounded-card bg-surface shadow-card lg:order-none"
                :class="sectionOpen ? 'block' : 'hidden lg:block'">

                <!-- ── PERSÖNLICH ── -->
                <template v-if="activeTab === 'personal'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Persönliche Daten</h2>
                        <p class="mt-0.5 text-sm text-ink-3">Name, E-Mail und öffentliche Profilinformationen</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div v-if="props.status === 'profile-information-updated' || props.status === 'avatar-updated'" class="mb-5 flex items-center gap-2 rounded-field bg-success-soft border border-success/25 px-4 py-3 text-sm text-success-ink">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Profil erfolgreich gespeichert.
                        </div>

                        <form @submit.prevent="profileForm.patch(route('profile.update'))" class="space-y-5">
                            <!-- Name + Email -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-ink-2 mb-1.5">Name</label>
                                    <input v-model="profileForm.name" type="text" required autofocus autocomplete="name" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.name" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-ink-2 mb-1.5">E-Mail</label>
                                    <input v-model="profileForm.email" type="email" required autocomplete="username" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.email" />
                                </div>
                            </div>

                            <!-- Location + Birth year -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-ink-2 mb-1.5">Standort</label>
                                    <input v-model="profileForm.location" type="text" placeholder="z.B. München" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.location" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-ink-2 mb-1.5">Geburtsjahr</label>
                                    <input v-model="profileForm.birth_year" type="number" placeholder="z.B. 1992" min="1940" :max="new Date().getFullYear() - 10" :class="inputClass" />
                                    <InputError class="mt-1.5" :message="profileForm.errors.birth_year" />
                                </div>
                            </div>

                            <!-- Favorite distance -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Lieblingsdistanz</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="d in distanceOptions"
                                        :key="d"
                                        type="button"
                                        @click="profileForm.favorite_distance = (profileForm.favorite_distance === d ? '' : d)"
                                        class="px-3 py-1.5 rounded-field text-sm font-medium border transition-colors"
                                        :class="profileForm.favorite_distance === d
                                            ? 'bg-accent border-accent text-white'
                                            : 'bg-surface border-line text-ink-2 hover:border-accent'"
                                    >
                                        {{ d }}
                                    </button>
                                </div>
                            </div>

                            <!-- Bio -->
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">
                                    Über mich
                                    <span class="font-normal text-ink-3 ml-1">{{ (profileForm.bio ?? '').length }}/300</span>
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

                            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-field bg-warn-soft border border-warn/25 px-4 py-3 text-sm text-warn">
                                E-Mail noch nicht verifiziert.
                                <Link :href="route('verification.send')" method="post" as="button" class="underline ml-1">Erneut senden</Link>
                            </div>

                            <div class="flex items-center justify-end pt-2">
                                <button type="submit" :disabled="profileForm.processing" class="inline-flex items-center gap-2 rounded-field bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-card">
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
                            <h3 class="text-base font-bold text-ink">Mein Coach</h3>
                            <p class="text-sm text-ink-3 mt-1">Dein Coach beeinflusst wie Trainingsempfehlungen und Pläne kommuniziert werden.</p>
                        </div>

                        <div class="space-y-3">
                            <button
                                v-for="coach in coaches" :key="coach.id"
                                type="button"
                                @click="selectedCoachId = coach.id"
                                class="w-full flex items-start gap-4 p-5 rounded-card border-2 text-left transition duration-200"
                                :class="selectedCoachId === coach.id
                                    ? [coachColors[coach.avatar_color]?.light, coachColors[coach.avatar_color]?.border, 'shadow-card']
                                    : 'bg-surface/50 border-line hover:border-line-strong'"
                            >
                                <div class="shrink-0 w-12 h-12 rounded-field flex items-center justify-center text-white font-bold text-base"
                                    :class="[coachColors[coach.avatar_color]?.bg, selectedCoachId === coach.id ? 'ring-2 ring-offset-2 ' + coachColors[coach.avatar_color]?.ring : '']">
                                    {{ coach.avatar_initials }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-0.5">
                                        <span class="font-bold text-ink">{{ coach.name }}</span>
                                        <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="coachColors[coach.avatar_color]?.badge">{{ specialtyLabels[coach.specialty] }}</span>
                                    </div>
                                    <p class="text-xs italic text-ink-3 mb-1">„{{ coach.tagline }}"</p>
                                    <p class="text-sm text-ink-2">{{ coach.description }}</p>
                                </div>
                                <div class="shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center mt-0.5"
                                    :class="selectedCoachId === coach.id ? [coachColors[coach.avatar_color]?.bg, 'border-transparent'] : 'border-line-strong'">
                                    <svg v-if="selectedCoachId === coach.id" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </div>
                            </button>
                        </div>

                        <div class="flex items-center gap-3 flex-wrap">
                            <button @click="saveCoach" :disabled="coachSaving || !selectedCoachId"
                                class="px-5 py-2.5 bg-accent hover:opacity-90 disabled:opacity-50 text-white text-sm font-semibold rounded-field transition-colors">
                                {{ coachSaving ? 'Speichern…' : 'Coach speichern' }}
                            </button>
                            <Transition enter-active-class="transition duration-300 ease-out-strong" enter-from-class="opacity-0 translate-y-1" leave-active-class="transition duration-200" leave-to-class="opacity-0">
                                <div v-if="coachSaved && savedCoachName" class="flex items-center gap-2 rounded-field px-3 py-2 border"
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
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Athletenprofil</h2>
                        <p class="mt-0.5 text-sm text-ink-3">Herzfrequenz- und Schwellenwerte für deine Trainingszonen</p>
                    </div>
                    <div class="p-4 sm:p-6 space-y-5">
                        <div v-if="athleteSaved" class="flex items-center gap-2 rounded-field bg-success-soft border border-success/25 px-4 py-3 text-sm text-success">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Athletenprofil gespeichert. Zonen wurden neu berechnet.
                        </div>
                        <form @submit.prevent="submitAthlete" class="space-y-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-ink-2 mb-1.5">Schwellen-HF <span class="text-ink-3 font-normal">(LTHR, bpm)</span></label>
                                    <input v-model="athleteForm.threshold_heart_rate" type="number" min="100" max="220" placeholder="z.B. 165" required :class="inputClass" />
                                    <InputError class="mt-1" :message="athleteForm.errors.threshold_heart_rate" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-ink-2 mb-1.5">Maximale HF <span class="text-ink-3 font-normal">(bpm)</span></label>
                                    <input v-model="athleteForm.max_heart_rate" type="number" min="100" max="220" placeholder="z.B. 195" required :class="inputClass" />
                                    <InputError class="mt-1" :message="athleteForm.errors.max_heart_rate" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Schwellen-Pace <span class="text-ink-3 font-normal">(min:sek/km)</span></label>
                                <input v-model="athleteForm.threshold_speed" type="text" placeholder="z.B. 5:30" pattern="[0-9]{1,2}:[0-9]{2}" required :class="inputClass" />
                                <p class="mt-1 text-xs text-ink-3">Die Pace, die du für ~60 min halten kannst</p>
                                <InputError class="mt-1" :message="athleteForm.errors.threshold_speed" />
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" :disabled="athleteForm.processing" class="inline-flex items-center gap-2 rounded-field bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-card">
                                    <svg v-if="athleteForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Speichern & Zonen berechnen
                                </button>
                            </div>
                        </form>

                        <div v-if="paceZones.length > 0" class="border-t border-line pt-5">
                            <h3 class="text-sm font-semibold text-ink-2">Deine Laufzonen</h3>
                            <p v-if="vdot" class="mt-0.5 mb-3 text-[13px] text-ink-3">
                                Nach Daniels aus VDOT <span class="font-semibold text-ink tabular-nums">{{ vdot.value.toFixed(1).replace('.', ',') }}</span>, {{ vdot.label }}
                            </p>
                            <div v-else class="mb-3" />
                            <div class="space-y-2">
                                <div v-for="(zone, idx) in paceZones" :key="idx" class="flex items-center justify-between rounded-field border px-4 py-3" :class="zoneColors[idx]">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-bold opacity-60">Z{{ idx + 1 }}</span>
                                        <span class="text-sm font-semibold">{{ zone.name }}</span>
                                    </div>
                                    <span class="text-sm font-mono tabular-nums">{{ zoneRange(zone) }} min/km</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="border-t border-line pt-5">
                            <div class="rounded-field bg-surface-2 border border-dashed border-line px-5 py-6 text-center">
                                <p class="text-sm text-ink-3">Speichere dein Profil um deine Laufzonen zu berechnen.</p>
                            </div>
                        </div>

                        <!-- Trainingsmodell -->
                        <div class="border-t border-line pt-5 space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold text-ink-2">Trainingsmodell</h3>
                                <p class="text-xs text-ink-3 mt-0.5">Gilt ab dem nächsten Wochenplan (Sonntagabend).</p>
                            </div>
                            <TrainingModelFields v-model="modelForm" :readiness="props.norwegianReadiness" />
                            <p v-if="modelNextLabel" class="rounded-field bg-accent-soft px-4 py-3 text-[13px] leading-relaxed text-accent-ink">
                                Der Wechsel gilt ab {{ modelNextLabel }}. Bis dahin plant Zone3 die laufende Woche mit dem bisherigen Modell weiter.
                            </p>
                            <p v-if="modelError" class="z-error">{{ modelError }}</p>
                            <div class="flex items-center justify-end gap-3">
                                <span v-if="modelSaved" class="inline-flex items-center gap-1 text-[13px] text-success-ink"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>Gespeichert</span>
                                <button type="button" @click="saveModel" :disabled="modelSaving"
                                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 active:opacity-80 disabled:opacity-50 transition-colors">
                                    <svg v-if="modelSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Modell speichern
                                </button>
                            </div>
                        </div>

                        <!-- Weekly availability -->
                        <div class="border-t border-line pt-5 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-ink-2">Wöchentliche Verfügbarkeit</h3>
                                    <p class="text-xs text-ink-3 mt-0.5">An welchen Tagen kannst du trainieren? Der KI-Plan respektiert diese Zeiten.</p>
                                </div>
                                <div v-if="availSaved" class="flex items-center gap-1.5 text-sm text-success-ink font-medium">
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
                                    class="flex flex-col items-center gap-1 rounded-field py-2.5 text-xs font-semibold transition-colors border"
                                    :class="availability[day.key].available
                                        ? 'bg-accent border-accent text-white'
                                        : 'bg-surface-2 border-line text-ink-3'"
                                >
                                    {{ day.label }}
                                </button>
                            </div>

                            <!-- Duration for active days -->
                            <div class="space-y-2">
                                <div
                                    v-for="day in availabilityDays.filter(d => availability[d.key].available)"
                                    :key="day.key"
                                    class="space-y-2"
                                >
                                    <div class="flex items-center gap-3">
                                    <span class="w-24 text-sm text-ink-2 shrink-0">{{ day.full }}</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="dur in durationOptions"
                                            :key="dur"
                                            type="button"
                                            @click="availability[day.key].duration_min = dur"
                                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors border"
                                            :class="availability[day.key].duration_min === dur
                                                ? 'bg-accent border-accent text-white'
                                                : 'bg-surface-2 border-line text-ink-3 hover:border-accent'"
                                        >
                                            {{ dur }} min
                                        </button>
                                    </div>

                                    <button type="button" @click="toggleFixed(day.key)"
                                        class="ml-auto shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors"
                                        :class="availability[day.key].fixed
                                            ? 'bg-accent-soft text-accent-ink'
                                            : 'bg-surface-2 text-ink-3 hover:text-ink-2'">
                                        {{ availability[day.key].fixed ? 'Fester Termin' : '+ Fester Termin' }}
                                    </button>
                                    </div>

                                    <!-- Zweites Zeitfenster: nur im norwegischen Modell -->
                                    <div v-if="modelForm.training_model === 'norwegian'" class="ml-24 flex flex-wrap items-center gap-1.5">
                                        <button type="button" @click="toggleSecond(day.key)"
                                            class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                                            :class="availability[day.key].second_min ? 'bg-accent-soft text-accent-ink' : 'bg-surface-2 text-ink-3'">
                                            {{ availability[day.key].second_min ? 'Abends' : '+ Abends' }}
                                        </button>
                                        <template v-if="availability[day.key].second_min">
                                            <button v-for="m in secondOptions" :key="m" type="button"
                                                @click="availability[day.key].second_min = m"
                                                class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors border"
                                                :class="availability[day.key].second_min === m
                                                    ? 'bg-accent border-accent text-white'
                                                    : 'bg-surface-2 border-line text-ink-3'">
                                                {{ m }} min
                                            </button>
                                        </template>
                                    </div>

                                    <!-- Fester Termin: der Tag ist belegt, der Inhalt kommt von außen -->
                                    <div v-if="availability[day.key].fixed"
                                        class="ml-24 flex flex-wrap items-center gap-2 rounded-field bg-surface-2 p-3">
                                        <input
                                            v-model="availability[day.key].fixed.label"
                                            type="text"
                                            maxlength="40"
                                            placeholder="z.B. Laufclub"
                                            class="z-input h-9 w-40 py-0 text-xs"
                                        />
                                        <select v-model="availability[day.key].fixed.type" class="z-input h-9 w-40 py-0 text-xs">
                                            <option v-for="t in fixedTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                        </select>
                                        <button type="button" class="text-xs font-medium text-danger hover:underline"
                                            @click="toggleFixed(day.key)">entfernen</button>
                                        <p class="w-full text-[11px] leading-relaxed text-ink-3">
                                            Dieser Tag wird nicht verplant. Die Einheit zählt als deine wöchentliche
                                            Qualitätseinheit — der Coach legt dann keine zweite daneben.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <p v-if="availError" class="z-error">{{ availError }}</p>

                            <!-- Summary + save -->
                            <div class="flex items-center justify-between pt-1">
                                <p class="text-xs text-ink-3">
                                    {{ availabilityDays.filter(d => availability[d.key].available).length }} Tage ·
                                    {{ availabilityDays.reduce((s, d) => s + (availability[d.key].available ? availability[d.key].duration_min : 0), 0) }} Min/Woche
                                </p>
                                <button
                                    type="button"
                                    @click="saveAvailability"
                                    :disabled="availSaving"
                                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-card"
                                >
                                    <svg v-if="availSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Verfügbarkeit speichern
                                </button>
                            </div>
                        </div>

                        <!-- Strength & core -->
                        <div class="border-t border-line pt-5">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-ink-2">Kraft & Core</h3>
                                    <p class="text-xs text-ink-3 mt-0.5">Ergänzendes Kraft-/Rumpftraining im Plan</p>
                                </div>
                                <button type="button" @click="strength.strength_enabled = !strength.strength_enabled"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0"
                                    :class="strength.strength_enabled ? 'bg-danger' : 'bg-surface-3'">
                                    <span class="inline-block h-4 w-4 transform rounded-full bg-surface transition-transform"
                                        :class="strength.strength_enabled ? 'translate-x-6' : 'translate-x-1'"></span>
                                </button>
                            </div>

                            <template v-if="strength.strength_enabled">
                                <label class="block text-xs font-medium text-ink-3 mb-1.5">Equipment <span class="text-ink-3 font-normal">(Mehrfachauswahl)</span></label>
                                <div class="grid grid-cols-3 sm:grid-cols-5 gap-2 mb-4">
                                    <button v-for="opt in equipmentOptions" :key="opt.value" type="button" @click="toggleStrengthEquipment(opt.value)"
                                        class="py-2.5 px-2 rounded-field border-2 transition text-center"
                                        :class="strength.strength_equipment.includes(opt.value)
                                            ? 'bg-accent-soft border-danger text-danger-ink'
                                            : 'border-line text-ink-2 hover:border-danger'">
                                        <div class="text-lg mb-0.5">{{ opt.icon }}</div>
                                        <div class="text-[11px] font-semibold">{{ opt.label }}</div>
                                    </button>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div>
                                        <label class="block text-xs font-medium text-ink-3 mb-1.5">Einheiten / Woche</label>
                                        <select v-model.number="strength.strength_days_per_week" :class="inputClass">
                                            <option v-for="n in 4" :key="n" :value="n">{{ n }}×</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-ink-3 mb-1.5">Erfahrung</label>
                                        <select v-model="strength.strength_experience" :class="inputClass">
                                            <option value="beginner">Anfänger</option>
                                            <option value="intermediate">Mittel</option>
                                            <option value="advanced">Fortgeschritten</option>
                                        </select>
                                    </div>
                                </div>
                            </template>

                            <div class="flex items-center justify-end gap-3">
                                <span v-if="strengthSaved" class="inline-flex items-center gap-1 text-[13px] text-success-ink"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>Gespeichert</span>
                                <button type="button" @click="saveStrength" :disabled="strengthSaving"
                                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-card">
                                    <svg v-if="strengthSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Kraft speichern
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ── BENACHRICHTIGUNGEN ── -->
                <template v-else-if="activeTab === 'notifications'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Web Push Benachrichtigungen</h2>
                        <p class="mt-0.5 text-sm text-ink-3">Erhalte Hinweise direkt im Browser — auch wenn die App nicht geöffnet ist</p>
                    </div>
                    <div class="p-4 sm:p-6 space-y-5">
                        <div v-if="!pushSupported" class="rounded-field bg-surface-2 px-4 py-3 text-sm text-ink-3">Dein Browser unterstützt keine Push-Benachrichtigungen.</div>
                        <template v-else>
                            <div v-if="pushPermission === 'denied'" class="rounded-field bg-danger-soft border border-danger/25 px-4 py-3 text-sm text-danger-ink">Benachrichtigungen sind in deinem Browser blockiert.</div>
                            <div class="flex items-center justify-between gap-4 p-4 rounded-card bg-surface-2">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ pushSubscribed ? 'Push aktiv' : 'Push deaktiviert' }}</p>
                                    <p class="text-xs text-ink-3 mt-0.5">{{ pushSubscribed ? 'Du erhältst Benachrichtigungen auf diesem Gerät' : 'Noch keine Benachrichtigungen auf diesem Gerät' }}</p>
                                </div>
                                <button @click="pushSubscribed ? unsubscribePush() : subscribePush()" :disabled="pushLoading || pushPermission === 'denied'" class="shrink-0 inline-flex items-center gap-2 rounded-field px-4 py-2 text-sm font-semibold transition-colors disabled:opacity-50" :class="pushSubscribed ? 'bg-surface-3 text-ink-2 hover:bg-danger-soft hover:text-danger' : 'bg-accent text-white hover:opacity-90'">
                                    <svg v-if="pushLoading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    {{ pushSubscribed ? 'Deaktivieren' : 'Aktivieren' }}
                                </button>
                            </div>
                            <div v-if="pushSubscribed" class="flex items-center gap-3">
                                <button @click="sendTestPush" class="inline-flex items-center gap-2 rounded-field bg-surface-2 text-ink-2 hover:bg-surface-3 px-3 py-2 text-sm font-medium transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                                    Test-Benachrichtigung
                                </button>
                                <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                    <span v-if="pushTestSent" class="text-sm text-success-ink font-medium">Gesendet</span>
                                </Transition>
                            </div>
                            <p v-if="pushError" class="text-sm text-danger-ink">{{ pushError }}</p>
                        </template>
                    </div>

                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-t border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Einstellungen</h2>
                    </div>
                    <div class="p-4 sm:p-6 space-y-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-ink">Tagesform-Erinnerung</p>
                                <p class="text-xs text-ink-3 mt-0.5">Uhrzeit für die tägliche Erinnerung</p>
                            </div>
                            <input v-model="notifSettings.wellbeing_reminder_time" type="time" class="shrink-0 rounded-field bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent/40" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-ink">Schwellenpace aktualisiert</p>
                                <p class="text-xs text-ink-3 mt-0.5">Bei KI-Neuberechnung deiner Pace</p>
                            </div>
                            <button @click="notifSettings.notify_threshold_pace = !notifSettings.notify_threshold_pace" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none" :class="notifSettings.notify_threshold_pace ? 'bg-accent' : 'bg-surface-3'">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-surface shadow transition duration-200" :class="notifSettings.notify_threshold_pace ? 'translate-x-5' : 'translate-x-0'" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-ink">KI-Plan aktualisiert</p>
                                <p class="text-xs text-ink-3 mt-0.5">Wenn dein Trainingsplan neu berechnet wurde</p>
                            </div>
                            <button @click="notifSettings.notify_plan_updated = !notifSettings.notify_plan_updated" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none" :class="notifSettings.notify_plan_updated ? 'bg-accent' : 'bg-surface-3'">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-surface shadow transition duration-200" :class="notifSettings.notify_plan_updated ? 'translate-x-5' : 'translate-x-0'" />
                            </button>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-ink">Monatsrückblick</p>
                                <p class="text-xs text-ink-3 mt-0.5">Am Monatsanfang per Push &amp; E-Mail mit deinen Fakten</p>
                            </div>
                            <button @click="notifSettings.notify_monthly_review = !notifSettings.notify_monthly_review" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none" :class="notifSettings.notify_monthly_review ? 'bg-accent' : 'bg-surface-3'">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-surface shadow transition duration-200" :class="notifSettings.notify_monthly_review ? 'translate-x-5' : 'translate-x-0'" />
                            </button>
                        </div>
                        <div class="flex items-center gap-3 pt-2">
                            <button @click="saveNotifSettings" :disabled="notifSaving" class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors">
                                <svg v-if="notifSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                Einstellungen speichern
                            </button>
                            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                <span v-if="notifSaved" class="text-sm text-success-ink font-medium">Gespeichert</span>
                            </Transition>
                        </div>
                    </div>

                    <!-- Newsletter -->
                    <div class="px-4 sm:px-6 pt-5 pb-5 border-t border-line space-y-3">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">Newsletter</h3>
                            <p class="text-xs text-ink-3 mt-0.5">
                                Gelegentliche Updates, Trainingstipps und Zone3-Neuigkeiten per E-Mail.
                            </p>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-ink">Newsletter abonnieren</p>
                                <p class="text-xs text-ink-3 mt-0.5">
                                    An <span class="font-medium">{{ user.email }}</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                    <span v-if="newsletterSaved" class="text-xs text-success-ink font-medium">Gespeichert</span>
                                </Transition>
                                <button
                                    @click="newsletterOptIn = !newsletterOptIn; toggleNewsletter()"
                                    :disabled="newsletterSaving"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none disabled:opacity-50"
                                    :class="newsletterOptIn ? 'bg-accent' : 'bg-surface-3'"
                                >
                                    <span class="inline-block h-5 w-5 transform rounded-full bg-surface shadow transition duration-200"
                                        :class="newsletterOptIn ? 'translate-x-5' : 'translate-x-0'" />
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ── VERBINDUNGEN ── -->
                <template v-else-if="activeTab === 'connections'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Verbindungen</h2>
                        <p class="mt-0.5 text-sm text-ink-3">Drittanbieter-Dienste mit Zone3 verbinden</p>
                    </div>
                    <div class="p-4 sm:p-6 space-y-4">

                        <!-- Strava Card (umgezogen von Security) -->
                        <div class="rounded-card bg-surface-2 overflow-hidden">
                            <div class="flex items-center justify-between gap-4 px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-field flex items-center justify-center shrink-0"
                                        :class="props.stravaConnected ? 'bg-warn-soft' : 'bg-surface-2'">
                                        <svg class="h-6 w-6" :class="props.stravaConnected ? 'text-warn' : 'text-ink-3'" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-ink">Strava</p>
                                        <p v-if="props.stravaConnected" class="text-xs text-success-ink mt-0.5">Verbunden · {{ props.stravaAccount?.username }}</p>
                                        <p v-else class="text-xs text-ink-3 mt-0.5">Nicht verbunden</p>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <button v-if="props.stravaConnected" @click="confirmStravaDisconnect = true"
                                        class="rounded-field border border-danger/25 px-3 py-1.5 text-xs font-semibold text-danger-ink hover:bg-danger-soft transition-colors">
                                        Trennen
                                    </button>
                                    <Link v-else href="/strava/connect"
                                        class="rounded-field bg-warn px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 transition-colors">
                                        Verbinden
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- Garmin Connect Card -->
                        <div class="rounded-card bg-surface-2 overflow-hidden">
                            <div class="flex items-center justify-between gap-4 px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-field flex items-center justify-center shrink-0"
                                        :class="garminLinked ? 'bg-info-soft' : 'bg-surface-2'">
                                        <svg class="h-6 w-6" :class="garminLinked ? 'text-info' : 'text-ink-3'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <rect x="7" y="6" width="10" height="12" rx="2" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l.5-2.5h5L15 6M9 18l.5 2.5h5L15 18" />
                                            <circle cx="12" cy="12" r="2.2" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-ink">Garmin Connect</p>
                                        <template v-if="props.garminOfficial.available">
                                            <p v-if="garminOfficialConnection && garminGranted" class="text-xs text-success-ink mt-0.5">Verbunden · {{ garminGranted }}</p>
                                            <p v-else-if="garminOfficialConnection" class="text-xs text-warn-ink mt-0.5">Verbunden · keine Daten freigegeben</p>
                                            <p v-else class="text-xs text-ink-3 mt-0.5">Nicht verbunden</p>
                                        </template>
                                        <template v-else>
                                            <p v-if="garminConnected" class="text-xs text-success-ink mt-0.5">Verbunden · {{ garminSavedEmail }}</p>
                                            <p v-else class="text-xs text-ink-3 mt-0.5">Nicht verbunden</p>
                                        </template>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <template v-if="props.garminOfficial.available">
                                        <button v-if="garminOfficialConnection" @click="disconnectGarminOfficial" :disabled="garminOAuthBusy"
                                            class="rounded-field border border-danger/25 px-3 py-1.5 text-xs font-semibold text-danger-ink hover:bg-danger-soft active:opacity-60 transition-colors disabled:opacity-50">
                                            {{ garminOAuthBusy ? 'Trenne…' : 'Trennen' }}
                                        </button>
                                        <button v-else @click="showGarminForm = !showGarminForm"
                                            class="rounded-field bg-info px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 active:opacity-60 transition-colors">
                                            Verbinden
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button v-if="garminConnected" @click="disconnectGarmin" :disabled="garminDisconnecting"
                                            class="rounded-field border border-danger/25 px-3 py-1.5 text-xs font-semibold text-danger-ink hover:bg-danger-soft transition-colors disabled:opacity-50">
                                            {{ garminDisconnecting ? 'Trenne…' : 'Trennen' }}
                                        </button>
                                        <button v-else @click="showGarminForm = !showGarminForm"
                                            class="rounded-field bg-info px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 transition-colors">
                                            Verbinden
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <!-- Offizielle Verbindung: Einwilligung, dann weiter zu Garmin -->
                            <template v-if="props.garminOfficial.available">
                                <div v-if="showGarminForm && !garminOfficialConnection" class="border-t border-line p-4 space-y-3">
                                    <label class="flex items-start gap-3 text-xs leading-relaxed text-ink-2">
                                        <input v-model="garminConsent" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-line text-info focus:ring-info/40" />
                                        <span>
                                            Ich willige ein, dass Zone3 meine Garmin-Daten für Plan, Auswertungen und Coach
                                            an OpenAI übermittelt. Die Daten werden nicht zum Training von KI-Modellen verwendet.
                                            Widerruf jederzeit durch Trennen.
                                            <Link :href="route('privacy')" class="text-accent">Datenschutz</Link>
                                        </span>
                                    </label>
                                    <div class="flex items-center gap-3">
                                        <button @click="connectGarminOfficial" :disabled="!garminConsent || garminOAuthBusy"
                                            class="rounded-field bg-info px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 active:scale-[0.98] disabled:opacity-50 transition">
                                            Weiter zu Garmin
                                        </button>
                                        <button @click="showGarminForm = false" class="text-xs font-medium text-ink-3 hover:underline active:opacity-60">Abbrechen</button>
                                    </div>
                                    <p class="text-xs text-ink-3 leading-relaxed">
                                        Die Anmeldung läuft direkt bei Garmin, Zone3 sieht dein Passwort nie.
                                        Aktiviere dort alle Freigaben, sonst kommen keine Daten an.
                                    </p>
                                </div>

                                <div v-if="garminOfficialConnection?.workouts" class="border-t border-line px-4 py-3 flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-medium text-ink">Plan automatisch auf die Uhr</p>
                                        <p class="text-xs text-ink-3 mt-0.5 leading-relaxed">
                                            Die Läufe der nächsten 7 Tage stehen im Garmin-Kalender und ziehen nach, wenn sich der Plan ändert.
                                            Vereinstermine und Radeinheiten nicht.
                                        </p>
                                    </div>
                                    <button @click="toggleGarminWorkoutSync" role="switch" :aria-checked="garminWorkoutSync"
                                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none"
                                        :class="garminWorkoutSync ? 'bg-accent' : 'bg-surface-3'">
                                        <span class="inline-block h-5 w-5 transform rounded-full bg-surface shadow transition duration-200" :class="garminWorkoutSync ? 'translate-x-5' : 'translate-x-0'" />
                                    </button>
                                </div>

                                <div v-if="garminOfficialConnection && (!garminGranted || garminOfficialConnection.refresh_error)" class="border-t border-line px-4 py-3">
                                    <p v-if="garminOfficialConnection.refresh_error" class="text-xs text-danger-ink">
                                        Garmin nimmt die Verbindung nicht mehr an. Bitte trennen und neu verbinden.
                                    </p>
                                    <p v-else class="text-xs text-ink-3 leading-relaxed">
                                        Garmin hat Zone3 keine Daten freigegeben. In der Garmin Connect App unter
                                        Einstellungen → Verbundene Apps → Zone3 die Schalter einschalten, oder hier trennen
                                        und beim erneuten Verbinden alle Freigaben aktivieren.
                                    </p>
                                </div>

                                <div v-if="props.garminOfficial.status" class="border-t border-line px-4 py-3">
                                    <p class="text-xs" :class="props.garminOfficial.status.type === 'error' ? 'text-danger-ink' : 'text-success-ink'">
                                        {{ props.garminOfficial.status.message }}
                                    </p>
                                </div>
                            </template>

                            <!-- Alter Login, nur solange keine offizielle App konfiguriert ist (kein Passwort wird gespeichert) -->
                            <template v-else>
                                <div v-if="showGarminForm && !garminConnected" class="border-t border-line p-4 space-y-3">
                                    <div>
                                        <label class="block text-xs font-medium text-ink-2 mb-1">Garmin Connect E-Mail</label>
                                        <input v-model="garminEmail" type="email" autocomplete="username"
                                            class="w-full rounded-field bg-surface px-3 py-2.5 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-info/40"
                                            placeholder="deine@email.de" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-ink-2 mb-1">Passwort</label>
                                        <input v-model="garminPassword" type="password" autocomplete="current-password" @keyup.enter="connectGarmin"
                                            class="w-full rounded-field bg-surface px-3 py-2.5 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-info/40"
                                            placeholder="••••••••" />
                                    </div>
                                    <p v-if="garminConnectError" class="text-xs text-danger-ink">{{ garminConnectError }}</p>
                                    <div class="flex items-center gap-3 pt-1">
                                        <button @click="connectGarmin" :disabled="garminConnecting || !garminEmail || !garminPassword"
                                            class="inline-flex items-center gap-2 rounded-field bg-info px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors">
                                            <svg v-if="garminConnecting" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                            {{ garminConnecting ? 'Verbinde…' : 'Einmalig anmelden' }}
                                        </button>
                                        <button @click="showGarminForm = false" class="text-xs font-medium text-ink-3 hover:underline">Abbrechen</button>
                                    </div>
                                    <p class="text-xs text-ink-3 leading-relaxed">
                                        Dein Passwort wird <strong class="text-ink-3">nicht gespeichert</strong>. Es dient nur der einmaligen Anmeldung — danach speichert Zone3 ausschließlich einen verschlüsselten Login-Token (hält ca. ein Jahr). Bei aktiver Zwei-Faktor-Authentifizierung ist der Login derzeit nicht möglich.
                                    </p>
                                </div>

                                <div v-if="garminConnectOk && garminConnected" class="border-t border-line px-4 py-3">
                                    <p class="text-xs text-success-ink">Verbunden! Die letzten 60 Tage Erholungsdaten werden im Hintergrund geladen und erscheinen dann im Dashboard.</p>
                                </div>
                            </template>
                        </div>

                        <!-- Aktivitäten kommen von: nur wenn beide liefern können -->
                        <div v-if="activitySourceChoosable" class="rounded-card bg-surface-2 px-4 py-4 space-y-2">
                            <p class="text-sm font-semibold text-ink">Aktivitäten kommen von</p>
                            <SegmentedControl v-model="activitySourceValue" :options="activitySourceOptions" />
                            <p class="text-[13px] text-ink-3 leading-relaxed">
                                Neue Läufe kommen nur aus dieser Quelle, damit nichts doppelt im Plan steht.
                                Lädt die Uhr zu beiden hoch, wird dieselbe Einheit zusammengeführt.
                                Bisherige Aktivitäten bleiben, wie sie sind.
                            </p>
                        </div>

                        <!-- Telegram: der Coach meldet sich dort -->
                        <div v-if="props.telegram.available" class="rounded-card bg-surface-2 overflow-hidden">
                            <div class="flex items-center justify-between gap-4 px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-field flex items-center justify-center shrink-0"
                                        :class="props.telegram.linked ? 'bg-info-soft' : 'bg-surface-2'">
                                        <svg class="h-6 w-6" :class="props.telegram.linked ? 'text-info' : 'text-ink-3'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 4L3 11l6 2.5M21 4l-3.5 16-8.5-6.5M21 4L9 13.5v5.5l3-3.5" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-ink">Telegram</p>
                                        <p v-if="props.telegram.linked" class="text-xs text-success-ink mt-0.5">Verbunden · dein Coach schreibt dir dort</p>
                                        <p v-else class="text-xs text-ink-3 mt-0.5">Briefing, Reviews und Vorschläge direkt im Chat</p>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <button v-if="props.telegram.linked" @click="unlinkTelegram" :disabled="telegramForm.processing"
                                        class="rounded-field border border-danger/25 px-3 py-1.5 text-xs font-semibold text-danger-ink hover:bg-danger-soft active:bg-danger-soft transition-colors disabled:opacity-50">
                                        Trennen
                                    </button>
                                    <button v-else @click="linkTelegram" :disabled="telegramForm.processing"
                                        class="rounded-field bg-info px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 active:opacity-80 transition-colors disabled:opacity-50">
                                        Verbinden
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Kalender-Abo: Plan und Rennen im iPhone-/Google-Kalender -->
                        <div class="rounded-card bg-surface-2 overflow-hidden">
                            <div class="flex items-center justify-between gap-4 px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-field flex items-center justify-center shrink-0"
                                        :class="props.calendarFeed ? 'bg-info-soft' : 'bg-surface-2'">
                                        <svg class="h-6 w-6" :class="props.calendarFeed ? 'text-info' : 'text-ink-3'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-ink">Kalender</p>
                                        <p v-if="props.calendarFeed" class="text-xs text-success-ink mt-0.5">Abo aktiv · Einheiten und Rennen</p>
                                        <p v-else class="text-xs text-ink-3 mt-0.5">Plan und Rennen im iPhone- oder Google-Kalender</p>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <button v-if="props.calendarFeed" @click="removeCalendarFeed" :disabled="calendarForm.processing"
                                        class="rounded-field border border-danger/25 px-3 py-1.5 text-xs font-semibold text-danger-ink hover:bg-danger-soft active:bg-danger-soft transition-colors disabled:opacity-50">
                                        Abschalten
                                    </button>
                                    <button v-else @click="createCalendarFeed" :disabled="calendarForm.processing"
                                        class="rounded-field bg-info px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 active:opacity-80 transition-colors disabled:opacity-50">
                                        Einrichten
                                    </button>
                                </div>
                            </div>
                            <div v-if="props.calendarFeed" class="border-t border-line px-4 py-3 space-y-3">
                                <div class="flex flex-wrap gap-2">
                                    <a :href="props.calendarFeed.webcal"
                                        class="rounded-field bg-info px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 active:opacity-80 transition-colors">
                                        Im Kalender abonnieren
                                    </a>
                                    <button type="button" @click="copyCalendarUrl"
                                        class="rounded-field bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:opacity-90 active:opacity-70 transition-colors">
                                        {{ calendarCopied ? 'Kopiert' : 'Adresse kopieren' }}
                                    </button>
                                </div>
                                <p class="text-xs text-ink-3">
                                    Ganztägige Termine, ohne Ruhetage. Google Kalender: „Andere Kalender → Per URL" und die Adresse einfügen; Google aktualisiert nur alle paar Stunden.
                                    Wer die Adresse hat, sieht deinen Plan.
                                </p>
                                <div class="text-xs">
                                    <button v-if="!confirmCalendarRenew" type="button" @click="confirmCalendarRenew = true"
                                        class="font-semibold text-accent active:opacity-60">Neue Adresse erzeugen</button>
                                    <span v-else class="inline-flex flex-wrap items-center gap-3">
                                        <span class="text-ink-2">Die alte Adresse funktioniert danach nicht mehr.</span>
                                        <button type="button" @click="createCalendarFeed" :disabled="calendarForm.processing"
                                            class="font-semibold text-danger-ink active:opacity-60 disabled:opacity-50">Erzeugen</button>
                                        <button type="button" @click="confirmCalendarRenew = false"
                                            class="font-semibold text-ink-3 active:opacity-60">Abbrechen</button>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Rad / Zwift: lockere Läufe ersetzen, eigene Radintervalle -->
                        <div class="rounded-card bg-surface-2 p-4 space-y-4">
                            <div>
                                <p class="text-sm font-semibold text-ink">Rad / Zwift</p>
                                <p class="text-xs text-ink-3 mt-0.5">Zwift-Fahrten kommen über Strava. Hier legst du fest, ob der Plan Radeinheiten vorsieht.</p>
                            </div>
                            <BikeFields v-model="bikeForm" />
                            <div v-if="ftpSuggestion && !ftpTaken" class="rounded-card bg-warn-soft p-4">
                                <p class="text-[15px] font-semibold text-warn-ink">FTP wirkt zu niedrig</p>
                                <p class="mt-1 text-[13px] text-ink-2">
                                    „{{ ftpSuggestion.activity_name }}“ am {{ ftpSuggestion.date }} spricht für mindestens
                                    <span class="font-semibold tabular-nums">{{ ftpSuggestion.estimate }} W</span><template v-if="ftpSuggestion.current">, eingetragen sind {{ ftpSuggestion.current }} W</template>.
                                </p>
                                <button type="button" @click="takeFtpSuggestion" :disabled="bikeSaving"
                                    class="mt-2 text-[13px] font-semibold text-accent active:opacity-60 disabled:opacity-50">
                                    {{ ftpSuggestion.estimate }} W übernehmen
                                </button>
                            </div>
                            <p v-if="bikeError" class="z-error">{{ bikeError }}</p>
                            <div class="flex items-center justify-end gap-3">
                                <span v-if="bikeSaved" class="inline-flex items-center gap-1 text-[13px] text-success-ink"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>Gespeichert</span>
                                <button type="button" @click="saveBike" :disabled="bikeSaving"
                                    class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 active:opacity-80 disabled:opacity-50 transition-colors">
                                    <svg v-if="bikeSaving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Rad speichern
                                </button>
                            </div>
                        </div>

                    </div>
                </template>

                <!-- ── SICHERHEIT ── -->
                <template v-else-if="activeTab === 'security'">
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Passwort ändern</h2>
                        <p class="mt-0.5 text-sm text-ink-3">Nutze ein sicheres, einzigartiges Passwort</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <form @submit.prevent="updatePassword" class="space-y-5">
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Aktuelles Passwort</label>
                                <input ref="currentPasswordInput" v-model="passwordForm.current_password" type="password" autocomplete="current-password" :class="inputClass" />
                                <InputError class="mt-1.5" :message="passwordForm.errors.current_password" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Neues Passwort</label>
                                <input ref="passwordInput" v-model="passwordForm.password" type="password" autocomplete="new-password" :class="inputClass" />
                                <InputError class="mt-1.5" :message="passwordForm.errors.password" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-ink-2 mb-1.5">Passwort bestätigen</label>
                                <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" :class="inputClass" />
                                <InputError class="mt-1.5" :message="passwordForm.errors.password_confirmation" />
                            </div>
                            <div class="flex items-center gap-4 pt-1">
                                <button type="submit" :disabled="passwordForm.processing" class="inline-flex items-center gap-2 rounded-field bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 transition-colors shadow-card">
                                    <svg v-if="passwordForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                    Passwort ändern
                                </button>
                                <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                    <span v-if="passwordForm.recentlySuccessful" class="text-sm text-success-ink">Passwort gespeichert.</span>
                                </Transition>
                            </div>
                        </form>
                    </div>
                </template>

                <!-- ── KONTO ── -->
                <template v-else-if="activeTab === 'account'">
                    <!-- Strava -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Strava Verbindung</h2>
                    </div>
                    <div class="p-4 sm:p-6 border-b border-line">
                        <div v-if="props.stravaConnected" class="flex items-start justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 rounded-field bg-warn-soft flex items-center justify-center text-warn shrink-0"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" /></svg></div>
                                <div>
                                    <p class="text-sm font-semibold text-ink">Verbunden als <span class="text-warn-ink">{{ props.stravaAccount?.username }}</span></p>
                                    <p class="text-xs text-ink-3 mt-0.5">Zuletzt: {{ props.stravaAccount?.last_synced_at ?? 'Noch nie' }}</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 text-xs font-medium text-success-ink shrink-0">
                                <span class="h-1.5 w-1.5 rounded-full bg-success"></span> Aktiv
                            </span>
                        </div>
                        <div v-else class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-field bg-surface-2 flex items-center justify-center text-ink-3 shrink-0"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3ZM3 3l18 18" /></svg></div>
                            <div>
                                <p class="text-sm font-semibold text-ink">Nicht verbunden</p>
                                <p class="text-xs text-ink-3 mt-0.5">Verbinde Strava um deine Aktivitäten zu importieren</p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <Link v-if="!props.stravaConnected" href="/strava/connect" class="inline-flex items-center gap-2 rounded-field bg-warn px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-colors shadow-card">Mit Strava verbinden</Link>
                            <template v-else>
                                <Link href="/strava/connect" class="inline-flex items-center gap-2 rounded-field bg-surface-2 px-5 py-2.5 text-sm font-semibold text-ink-2 hover:bg-surface-3 transition-colors">Konto wechseln</Link>
                                <button type="button" @click="confirmStravaDisconnect = true" class="inline-flex items-center gap-2 rounded-field bg-danger-soft border border-danger/25 px-5 py-2.5 text-sm font-semibold text-danger-ink hover:opacity-90 transition-colors">Strava trennen</button>
                            </template>
                        </div>
                        <p v-if="props.stravaConnected" class="mt-3 text-xs text-ink-3">Beim Trennen bleiben deine bisherigen Aktivitäten in Zone3 erhalten. Neue kommen erst wieder, wenn du neu verbindest.</p>
                    </div>

                    <!-- Onboarding reset -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Onboarding wiederholen</h2>
                        <p class="mt-0.5 text-sm text-ink-3">Athletenprofil und Ziel erneut einrichten</p>
                    </div>
                    <div class="p-4 sm:p-6 border-b border-line">
                        <Link :href="route('onboarding.reset')" method="post" as="button" class="inline-flex items-center gap-2 rounded-field bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-colors shadow-card">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            Onboarding neu starten
                        </Link>
                    </div>

                    <!-- Account deletion -->
                    <div class="px-4 sm:px-6 py-4 sm:py-5 border-b border-line">
                        <h2 class="text-base font-semibold text-ink">Konto löschen</h2>
                        <p class="mt-0.5 text-sm text-ink-3">Alle Daten werden unwiderruflich gelöscht</p>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div class="rounded-field bg-danger-soft border border-danger/25 px-5 py-4 mb-5">
                            <p class="text-sm text-danger-ink">Sobald dein Konto gelöscht wird, werden alle Daten unwiderruflich entfernt.</p>
                        </div>
                        <button @click="confirmDeletion" class="inline-flex items-center gap-2 rounded-field bg-danger px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-colors shadow-card">Konto löschen</button>
                    </div>
                </template>

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
        <p class="py-3 text-center text-xs text-ink-3">Verschieben und zoomen um den Ausschnitt anzupassen</p>

        <template #footer>
            <div class="flex gap-3">
                <AppButton variant="secondary" block @click="closeCropModal">Abbrechen</AppButton>
                <AppButton block :loading="avatarUploading" @click="confirmCrop">
                    {{ avatarUploading ? 'Wird hochgeladen…' : 'Zuschneiden & Speichern' }}
                </AppButton>
            </div>
        </template>
    </AppSheet>
</template>
