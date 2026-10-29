<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { useDarkMode } from '@/Composables/useDarkMode';
import { useInstallPrompt } from '@/Composables/useInstallPrompt';
import { fmtNum } from '@/Composables/useFormat';

/**
 * „Mehr" als iOS-Einstellungsliste (Redesign Paket 3, 28.10.2026).
 *
 * Vorher ein Sheet mit fünf Links, und was eingerichtet ist (Garmin,
 * Telegram, Erinnerung), sah man erst eine Ebene tiefer im Profil. Jetzt
 * steht alles an einer Stelle, rechts grau der Stand. Die Zeilen führen in
 * den passenden Abschnitt des Profils (`?section=`).
 */
const props = defineProps({
    summary: { type: Object, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const isAdmin = computed(() => page.props.auth.isAdmin);
const s = computed(() => props.summary);

const { theme, setTheme } = useDarkMode();
const themeOptions = [
    { value: 'system', label: 'Automatisch' },
    { value: 'light',  label: 'Hell' },
    { value: 'dark',   label: 'Dunkel' },
];

const { isInstallable, isIOSHint, installApp, dismiss: dismissInstall } = useInstallPrompt();

const profile = (section) => route('profile.edit', { section });
const on = (v) => (v ? 'verbunden' : 'aus');

/** Symbolpfade im Stil der Tab-Leiste: 24er Raster, runde Enden. */
const P = {
    flag:  'M5 21V4h11l-2 4 2 4H5',
    lib:   'M5 4h4v16H5zM11 4h4v16h-4zM17 6l3 13',
    chart: 'M5 20v-8M12 20V5M19 20v-11',
    zones: 'M4 18h4v-8H4zM10 18h4V6h-4zM16 18h4v-5h-4z',
    coach: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
    watch: 'M7 7h10v10H7zM9 7l1-4h4l1 4M9 17l1 4h4l1-4',
    link:  'M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1',
    send:  'M21 3 3 11l7 3 3 7z',
    cal:   'M4 6h16v14H4zM4 10h16M9 3v4M15 3v4',
    pin:   'M12 21s-7-6.1-7-11a7 7 0 0 1 14 0c0 4.9-7 11-7 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
    layout:'M4 5h16M4 12h16M4 19h10',
    bell:  'M6 16v-5a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
    story: 'M12 3a9 9 0 1 0 9 9M12 7v5l3 2',
    user:  'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
    lock:  'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
    gear:  'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 12l2-1-1-3-2 .5-1.5-1.5.5-2-3-1-1 2h-2l-1-2-3 1 .5 2L6 7.5 4 7l-1 3 2 1v2l-2 1 1 3 2-.5L7.5 18 7 20l3 1 1-2h2l1 2 3-1-.5-2 1.5-1.5 2 .5 1-3-2-1z',
    help:  'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17v.1',
    shield:'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z',
};

const groups = computed(() => [
    { title: 'Training', rows: [
        { name: 'Rennen',          href: route('events.index'),     icon: P.flag,  tint: 'bg-danger',  value: s.value.events ? String(s.value.events) : '' },
        { name: 'Workouts',        href: route('workouts.index'),   icon: P.lib,   tint: 'bg-warn',    value: s.value.workouts ? String(s.value.workouts) : '' },
        { name: 'Statistik',       href: route('statistics.index'), icon: P.chart, tint: 'bg-fitness', value: '' },
        { name: 'Athletenprofil',  href: profile('athlete'),        icon: P.zones, tint: 'bg-info',
          value: s.value.vdot ? `VDOT ${fmtNum(s.value.vdot)}` : 'unvollständig',
          foot: [s.value.model, s.value.days ? `${s.value.days} Tage` : null, s.value.bike ? 'Rad' : null].filter(Boolean).join(' · ') },
        { name: 'Coach',           href: profile('coach'),          icon: P.coach, tint: 'bg-success', value: s.value.coach ?? '' },
    ] },
    { title: 'Verbindungen', rows: [
        { name: 'Garmin',       href: profile('connections'), icon: P.watch, tint: 'bg-ink-3',  value: on(s.value.garmin) },
        { name: 'Strava',       href: profile('connections'), icon: P.link,  tint: 'bg-warn',   value: on(s.value.strava) },
        { name: 'Telegram',     href: profile('connections'), icon: P.send,  tint: 'bg-info',   value: on(s.value.telegram) },
        { name: 'Kalender-Abo', href: profile('connections'), icon: P.cal,   tint: 'bg-danger', value: s.value.calendar ? 'an' : 'aus' },
    ] },
    { title: 'App', rows: [
        { name: 'Übersicht anpassen', href: route('dashboard', { layout: 1 }), icon: P.layout, tint: 'bg-accent', value: '' },
        { name: 'Mitteilungen',       href: profile('notifications'),          icon: P.bell,   tint: 'bg-danger',
          value: s.value.push ? (s.value.reminder ?? 'an') : 'aus' },
        { name: 'Live-Verfolgung',    href: route('live.manage'),              icon: P.pin,    tint: 'bg-success', value: '' },
        { name: 'Rückblick',          href: route('wrapped.index'),            icon: P.story,  tint: 'bg-fitness', value: String(new Date().getFullYear()) },
    ] },
    { title: 'Konto', rows: [
        { name: 'Persönliche Daten', href: profile('personal'), icon: P.user, tint: 'bg-ink-3', value: '' },
        { name: 'Sicherheit',        href: profile('security'), icon: P.lock, tint: 'bg-ink-3', value: '' },
        { name: 'Konto',             href: profile('account'),  icon: P.gear, tint: 'bg-ink-3', value: '' },
    ] },
    { title: 'Hilfe', rows: [
        { name: 'Hilfe & Feedback', href: route('support.tickets.index'), icon: P.help, tint: 'bg-accent', value: '' },
        ...(isAdmin.value ? [{ name: 'Admin-Bereich', href: route('admin.dashboard'), icon: P.shield, tint: 'bg-danger', value: '' }] : []),
    ] },
]);
</script>

<template>
    <Head title="Mehr" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-2xl" title="Mehr" />

            <div class="mx-auto max-w-2xl space-y-7 px-4 pb-8 pt-3 lg:px-8">
                <!-- Profil -->
                <Link :href="profile('personal')"
                    class="flex min-h-[76px] items-center gap-3 rounded-card bg-surface px-4 py-3 transition-colors active:bg-surface-2">
                    <UserAvatar :user="user" size="lg" class="!h-14 !w-14 !text-[20px]" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[20px] font-bold text-ink">{{ user.name }}</span>
                        <span class="block truncate text-[13px] text-ink-3">
                            <template v-if="s.vdot">VDOT {{ fmtNum(s.vdot) }}</template><template v-if="s.vdot && s.threshold"> · </template><template v-if="s.threshold">Schwelle {{ s.threshold }} /km</template>
                            <template v-if="!s.vdot && !s.threshold">{{ user.email }}</template>
                        </span>
                    </span>
                    <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </Link>

                <!-- App installieren -->
                <div v-if="isInstallable || isIOSHint" class="rounded-card bg-accent px-4 py-4 text-white">
                    <p class="text-[17px] font-semibold">Zone3 als App</p>
                    <p v-if="isInstallable" class="mt-1 text-[15px] text-white/80">Auf den Homescreen, ohne App Store.</p>
                    <p v-else class="mt-1 text-[15px] text-white/80">Tippe in Safari auf „Teilen“ und dann auf „Zum Home-Bildschirm“.</p>
                    <div class="mt-3 flex justify-end gap-4">
                        <button type="button" class="text-[15px] text-white/75" @click="dismissInstall">{{ isInstallable ? 'Nicht jetzt' : 'Verstanden' }}</button>
                        <button v-if="isInstallable" type="button" class="rounded-full bg-white px-4 py-1.5 text-[15px] font-semibold text-accent" @click="installApp">Installieren</button>
                    </div>
                </div>

                <section v-for="g in groups" :key="g.title">
                    <h2 class="mx-4 mb-1.5 text-[13px] text-ink-3">{{ g.title }}</h2>
                    <nav class="overflow-hidden rounded-card bg-surface">
                        <Link v-for="(r, i) in g.rows" :key="r.name" :href="r.href"
                            class="flex min-h-[48px] items-center gap-3 pl-4 transition-colors active:bg-surface-2">
                            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] text-white" :class="r.tint">
                                <svg class="h-[17px] w-[17px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="r.icon" /></svg>
                            </span>
                            <span class="flex min-h-[48px] min-w-0 flex-1 items-center gap-2 py-2 pr-4" :class="i > 0 ? 'border-t border-line' : ''">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[17px] text-ink">{{ r.name }}</span>
                                    <span v-if="r.foot" class="block truncate text-[13px] text-ink-3">{{ r.foot }}</span>
                                </span>
                                <span v-if="r.value" class="shrink-0 text-[15px] text-ink-3">{{ r.value }}</span>
                                <svg class="h-4 w-4 shrink-0 text-ink-3/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </span>
                        </Link>
                    </nav>
                </section>

                <section>
                    <h2 class="mx-4 mb-1.5 text-[13px] text-ink-3">Darstellung</h2>
                    <SegmentedControl :model-value="theme" :options="themeOptions" @update:model-value="setTheme" />
                    <p class="mx-4 mt-1.5 text-[13px] text-ink-3">„Automatisch“ folgt der Einstellung deines iPhones.</p>
                </section>

                <div class="overflow-hidden rounded-card bg-surface">
                    <Link :href="route('logout')" method="post" as="button"
                        class="flex min-h-[48px] w-full items-center justify-center text-[17px] text-danger transition-colors active:bg-surface-2">
                        Abmelden
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
