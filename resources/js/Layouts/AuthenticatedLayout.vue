<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';
import { useCoachChat } from '@/Composables/useCoachChat';
import { useVersionCheck } from '@/Composables/useVersionCheck';
import { useInstallPrompt } from '@/Composables/useInstallPrompt';
import { usePullToRefresh } from '@/Composables/usePullToRefresh';
import UserAvatar from '@/Components/UserAvatar.vue';
import CoachSlideOver from '@/Components/CoachSlideOver.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';

const { updateReady, startVersionPolling, reload } = useVersionCheck();
onMounted(() => startVersionPolling());

/*
 * Hier stand ein schwebender Hilfe-Knopf. Er lag in jeder Scrollposition
 * auf Text, und iOS kennt das Muster nicht — eine schwebende Aktion ist
 * Android. „Hilfe & Feedback" steht jetzt auf der Mehr-Seite und in der
 * Seitenleiste, wie eine Zeile in den Einstellungen.
 */

// Pull-to-refresh (touch devices only)
const { pullDistance, refreshing, threshold: ptrThreshold } = usePullToRefresh();

const { isInstallable, isIOSHint, installApp, dismiss: dismissInstall } = useInstallPrompt();

const page = usePage();
const user       = computed(() => page.props.auth.user);
const isAdmin    = computed(() => page.props.auth.isAdmin);
const activePlan = computed(() => page.props.activePlan);
const coach      = computed(() => page.props.coach);
const { theme, setTheme } = useDarkMode();
const { open: openChat } = useCoachChat();

const themeOptions = [
    { value: 'system', label: 'System' },
    { value: 'light',  label: 'Hell' },
    { value: 'dark',   label: 'Dunkel' },
];

const navItems = [
    {
        label: 'Heute',
        routeName: 'dashboard',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />`,
    },
    {
        label: 'Aktivitäten',
        routeName: 'activities.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />`,
    },
    {
        label: 'Statistiken',
        routeName: 'statistics.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" />`,
    },
    {
        label: 'Events',
        routeName: 'events.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />`,
    },
    {
        label: 'Kalender',
        routeName: 'calendar',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />`,
    },
    {
        label: 'Workouts',
        routeName: 'workouts.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122" />`,
    },
    {
        label: 'Live-Verfolgung',
        routeName: 'live.manage',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0v-6m0 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />`,
    },
    {
        label: 'Profil',
        routeName: 'profile.edit',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />`,
    },
];

// Tab-Leiste: Heute · Plan (bzw. Events) · Kalender · Aktivitäten · Mehr.
// Das Profil sitzt als Avatar oben rechts, wie in den Apple-Apps — es ist
// eine Einstellung, kein Ort, an den man täglich geht. Mit aktivem Plan
// fuehrt der zweite Tab direkt dorthin, sonst zur Event-Uebersicht.
const planTabIcon = `<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />`;

/** Nach Routennamen statt nach Index — sonst verschiebt jeder neue
 *  Menuepunkt die Tab-Leiste. */
const nav = (routeName) => navItems.find(i => i.routeName === routeName);

const mobileNavItems = computed(() => {
    const eventsOrPlan = activePlan.value
        ? { label: 'Plan', routeName: 'events.plan.show', planId: activePlan.value.event_id, icon: planTabIcon }
        : nav('events.index');
    return [
        nav('dashboard'),
        eventsOrPlan,
        nav('calendar'),
        nav('activities.index'),
    ];
});

/** Was über „Mehr" erreicht wird, lässt den Tab aktiv — wie in iOS. */
const MORE_ROUTES = ['more', 'profile.edit', 'workouts.*', 'statistics.index', 'live.*', 'wrapped.index', 'support.*', 'admin.*'];
const helpIcon = `<path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />`;

const moreActive = computed(() =>
    MORE_ROUTES.some(r => route().current(r)) || (!!activePlan.value && route().current('events.index')));
</script>

<template>
    <div class="min-h-screen bg-canvas">

        <!-- ══════════════════════════════════════
             ÜBERNAHME LÄUFT
             ═══════════════════════════════════════
             Wer als jemand anderes unterwegs ist, muss das auf JEDER Seite
             sehen. Sonst hält man fremde Daten für die eigenen und ändert
             im Zweifel etwas am falschen Account. -->
        <div
            v-if="$page.props.auth?.impersonating"
            class="sticky top-0 z-50 flex items-center justify-center gap-3 bg-warn px-4 py-2 text-xs font-semibold text-warn-ink pt-safe-banner"
        >
            <span>Du siehst Zone3 als {{ $page.props.auth.user?.name }}</span>
            <button
                class="rounded-field bg-warn-ink/15 px-2 py-1 underline"
                @click="router.post(route('impersonate.stop'))"
            >Zurück zu meinem Account</button>
        </div>

        <!-- ══════════════════════════════════════
             PULL-TO-REFRESH INDICATOR (mobile)
             ══════════════════════════════════════ -->
        <div
            v-if="pullDistance > 0 || refreshing"
            class="pointer-events-none fixed inset-x-0 z-40 flex justify-center lg:hidden"
            :style="{ top: 'calc(3.5rem + env(safe-area-inset-top) - 1.5rem)', transform: `translateY(${Math.min(pullDistance, ptrThreshold) + 8}px)` }"
        >
            <div class="flex h-9 w-9 items-center justify-center rounded-full border border-line bg-surface shadow-card">
                <svg
                    class="h-5 w-5 text-accent"
                    :class="refreshing ? 'animate-spin' : ''"
                    :style="refreshing ? '' : { transform: `rotate(${pullDistance / ptrThreshold * 270}deg)` }"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
            </div>
        </div>

        <!-- ══════════════════════════════════════
             UPDATE BANNER
             ══════════════════════════════════════ -->
        <!--
            Der ganze Banner ist die Schaltflaeche, nicht nur der Knopf darin.
            Auf dem iPhone lag er unter der Dynamic Island und war nicht
            antippbar; ein 24 Pixel hoher Knopf am rechten Rand war auch ohne
            Insel schon knapp fuer einen Daumen.
        -->
        <button
            v-if="updateReady"
            type="button"
            @click="reload"
            class="fixed inset-x-0 top-0 z-50 flex w-full items-center justify-between gap-3 bg-accent px-4 pb-3 pt-safe-banner text-left text-sm text-white shadow-card transition-colors active:bg-accent/80"
        >
            <span class="flex items-center gap-2">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
                <span class="font-medium">Neue Version verfügbar</span>
            </span>
            <span class="shrink-0 rounded-field bg-surface/20 px-3 py-2 text-xs font-semibold">
                Jetzt aktualisieren
            </span>
        </button>

        <!-- ══════════════════════════════════════
             PWA INSTALL BANNER (mobile, dismissable)
             ══════════════════════════════════════ -->
        <Transition
            enter-active-class="transition duration-300 ease-out-strong"
            enter-from-class="-translate-y-full opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-out-strong"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="-translate-y-full opacity-0"
        >
            <!-- Android: native install prompt -->
            <div
                v-if="isInstallable"
                class="fixed inset-x-0 top-0 z-50 flex items-center gap-3 bg-accent px-4 pb-3 pt-safe-banner text-white shadow-card lg:hidden"
            >
                <button @click="dismissInstall" class="shrink-0 text-white/70 transition-colors hover:text-white" aria-label="Schließen">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-field bg-surface/20">
                    <span class="text-xs font-bold text-white">Z3</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold leading-tight">Zone3 installieren</p>
                    <p class="text-xs leading-tight text-white/75">Kostenlos · direkt auf dem Homescreen</p>
                </div>
                <button
                    @click="installApp"
                    class="shrink-0 rounded-full bg-surface px-4 py-1.5 text-xs font-bold text-accent-ink transition-colors hover:bg-surface/90"
                >
                    Installieren
                </button>
            </div>

            <!-- iOS Safari: manual share-sheet instructions -->
            <div
                v-else-if="isIOSHint"
                class="fixed inset-x-0 top-0 z-50 bg-accent px-4 pb-3 pt-safe-banner text-white shadow-card lg:hidden"
            >
                <div class="flex items-start gap-3">
                    <button @click="dismissInstall" class="mt-0.5 shrink-0 text-white/70 transition-colors hover:text-white" aria-label="Schließen">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-field bg-surface/20">
                        <span class="text-xs font-bold text-white">Z3</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold leading-tight">Zone3 als App installieren</p>
                        <p class="mt-1 text-xs leading-snug text-white/80">
                            Tippe auf
                            <svg class="mx-0.5 -mt-0.5 inline h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15m0-3-3-3m0 0-3 3m3-3V15" />
                            </svg>
                            und dann <strong>„Zum Home-Bildschirm"</strong>
                        </p>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════
             DESKTOP SIDEBAR (hidden on mobile)
             ══════════════════════════════════════ -->
        <!--
            Seitenleiste wie auf dem iPad: grauer Grund, die aktive Zeile in
            der Akzentfarbe gefuellt, Abschnittsnamen klein und grau statt
            gesperrter Versalien.
        -->
        <aside class="hidden flex-col border-r border-line bg-canvas lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-64">
            <!-- Logo -->
            <div class="flex h-16 shrink-0 items-center gap-3 px-5">
                <ZoneIcon class="h-8 w-8" />
                <Wordmark class="h-5 text-ink" />
            </div>

            <!-- Navigation -->
            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-5 pt-2">
                <Link
                    v-for="item in navItems"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="flex items-center gap-3 rounded-[10px] px-3 py-2 text-[15px] transition duration-150 ease-out-strong"
                    :class="route().current(item.routeName)
                        ? 'bg-accent font-semibold text-white'
                        : 'text-ink hover:bg-surface-2'"
                >
                    <svg class="h-5 w-5 shrink-0" :class="route().current(item.routeName) ? '' : 'text-accent'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                    {{ item.label }}
                </Link>

                <!-- Active plan link -->
                <div v-if="activePlan" class="pt-5">
                    <p class="mb-1 px-3 text-[13px] font-semibold text-ink-3">Aktiver Plan</p>
                    <Link
                        :href="route('events.plan.show', activePlan.event_id)"
                        class="flex items-center gap-3 rounded-[10px] px-3 py-2 text-[15px] transition duration-150 ease-out-strong"
                        :class="route().current('events.plan.show')
                            ? 'bg-accent font-semibold text-white'
                            : 'text-ink hover:bg-surface-2'"
                    >
                        <svg class="h-5 w-5 shrink-0" :class="route().current('events.plan.show') ? '' : 'text-accent'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="planTabIcon" />
                        <span class="truncate">{{ activePlan.event_name }}</span>
                    </Link>
                </div>

                <div class="pt-5">
                    <p class="mb-1 px-3 text-[13px] font-semibold text-ink-3">Hilfe</p>
                    <Link
                        :href="route('support.tickets.index')"
                        class="flex items-center gap-3 rounded-[10px] px-3 py-2 text-[15px] text-ink transition duration-150 ease-out-strong hover:bg-surface-2"
                    >
                        <svg class="h-5 w-5 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="helpIcon" />
                        Hilfe &amp; Feedback
                    </Link>
                </div>

                <!-- Admin link -->
                <div v-if="isAdmin" class="pt-5">
                    <p class="mb-1 px-3 text-[13px] font-semibold text-ink-3">System</p>
                    <Link
                        :href="route('admin.dashboard')"
                        class="flex items-center gap-3 rounded-[10px] px-3 py-2 text-[15px] text-danger transition duration-150 ease-out-strong hover:bg-danger-soft"
                    >
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>
                        Admin-Bereich
                    </Link>
                </div>
            </nav>

            <!-- Coach Chat Button -->
            <div v-if="coach" class="shrink-0 px-3 pb-3">
                <button
                    @click="openChat"
                    class="flex w-full items-center gap-3 rounded-[10px] px-3 py-2 text-[15px] font-medium text-accent transition duration-150 ease-out-strong hover:bg-accent-soft active:scale-[0.98]"
                >
                    <div
                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[8px] font-bold text-white"
                        :style="`background-color: ${coach.avatar_color}`"
                    >
                        {{ coach.avatar_initials }}
                    </div>
                    Chat mit {{ coach.name }}
                    <span class="ml-auto h-2 w-2 shrink-0 rounded-full bg-success" title="Online" />
                </button>
            </div>

            <!-- Theme + user -->
            <div class="shrink-0 space-y-3 border-t border-line p-4">
                <div>
                    <p class="mb-1.5 text-[13px] font-semibold text-ink-3">Darstellung</p>
                    <SegmentedControl :model-value="theme" :options="themeOptions" @update:model-value="setTheme" />
                </div>
                <Link :href="route('profile.edit')" class="flex items-center gap-3 rounded-[10px] px-2 py-1.5 transition-colors hover:bg-surface-2">
                    <UserAvatar :user="user" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink">{{ user.name }}</p>
                        <p class="truncate text-xs text-ink-3">{{ user.email }}</p>
                    </div>
                </Link>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="flex w-full items-center gap-2.5 rounded-field px-3 py-2 text-sm text-ink-3 transition-colors hover:bg-danger-soft hover:text-danger"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                    Abmelden
                </Link>
            </div>
        </aside>

        <!-- ══════════════════════════════════════
             MOBILE TOP BAR
             ══════════════════════════════════════ -->
        <!--
            Kopfleiste als iOS-Material: durchscheinend und unscharf, der
            Inhalt scrollt sichtbar darunter weg. Kein Logo-Kachel-Branding —
            der grosse Seitentitel darunter sagt, wo man ist. Rechts Coach
            und Profil; Abmelden ist ins Mehr-Sheet gewandert, wo es nicht
            aus Versehen getroffen wird.
        -->
        <header class="fixed inset-x-0 top-0 z-20 flex h-mobile-header items-end justify-between border-b border-line/70 bg-canvas/80 px-4 pb-2 pt-safe backdrop-blur-xl backdrop-saturate-150 lg:hidden">
            <Wordmark class="mb-2 h-[17px] text-ink" />
            <div class="flex items-center gap-2">
                <!-- Coach chat button (mobile) -->
                <button
                    v-if="coach"
                    @click="openChat"
                    :aria-label="`Chat mit ${coach.name}`"
                    class="relative flex h-10 w-10 items-center justify-center rounded-full transition-transform duration-150 ease-out-strong active:scale-[0.94]"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full text-[11px] font-bold text-white"
                        :style="`background-color: ${coach.avatar_color}`"
                    >
                        {{ coach.avatar_initials }}
                    </div>
                    <span class="absolute right-0.5 top-0.5 h-2.5 w-2.5 rounded-full border-2 border-canvas bg-success" />
                </button>

                <!-- Profil -->
                <Link
                    :href="route('profile.edit')"
                    aria-label="Profil"
                    class="flex h-10 w-10 items-center justify-center rounded-full transition-transform duration-150 ease-out-strong active:scale-[0.94]"
                >
                    <UserAvatar :user="user" size="md" />
                </Link>
            </div>
        </header>

        <!-- ══════════════════════════════════════
             MAIN CONTENT
             ══════════════════════════════════════ -->
        <div class="flex min-h-screen flex-col lg:pl-64">
            <!-- Desktop page header slot -->
            <header v-if="$slots.header" class="hidden border-b border-line bg-surface lg:block">
                <div class="px-6 py-4">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page content — top padding for mobile header (safe area aware), bottom for tab bar.
                 overflow-x-clip faengt ein einzelnes zu breites Element ab, statt die ganze
                 Seite seitlich scrollen zu lassen (clip statt hidden, damit sticky weiter geht). -->
            <!--
                Lesebreite. Ohne Deckel verteilten sich Distanz, Dauer und Tempo
                auf breiten Schirmen ueber 1300px, und der Wochenrueckblick brach
                rechts mitten im Satz ab. 1280px ist weit genug fuer die
                dreispaltigen Bloecke und schmal genug, dass eine Zeile noch
                lesbar bleibt. Auf dem Telefon aendert sich dadurch nichts.
            -->
            <main class="mx-auto w-full min-w-0 max-w-[1280px] flex-1 overflow-x-clip pb-mobile-tabbar pt-mobile-header lg:pb-0 lg:pt-0">
                <slot />
            </main>
        </div>

        <!-- Coach Chat Slide-Over -->
        <CoachSlideOver />

        <!-- ══════════════════════════════════════
             MOBILE BOTTOM TAB BAR
             ══════════════════════════════════════ -->
        <!--
            Tab-Leiste wie in iOS: Material statt Flaeche, Haarlinie oben,
            Symbol 24 Punkt ueber 10-Punkt-Beschriftung, der aktive Tab nur
            ueber die Akzentfarbe — kein zusaetzlicher Strich.
        -->
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line/70 bg-surface/85 pb-safe-tabbar backdrop-blur-xl backdrop-saturate-150 lg:hidden" aria-label="Hauptnavigation">
            <div class="flex h-[52px] items-center">
                <Link
                    v-for="item in mobileNavItems"
                    :key="item.routeName"
                    :href="item.planId ? route(item.routeName, item.planId) : route(item.routeName)"
                    :aria-current="route().current(item.routeName) ? 'page' : undefined"
                    class="flex h-full min-w-0 flex-1 flex-col items-center justify-center gap-0.5 transition-colors"
                    :class="route().current(item.routeName) ? 'text-accent' : 'text-ink-3'"
                >
                    <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" :stroke-width="route().current(item.routeName) ? 2.1 : 1.7" v-html="item.icon" />
                    <span class="max-w-full truncate px-0.5 text-[10px] font-semibold leading-none">{{ item.label }}</span>
                </Link>

                <!-- Mehr: eine eigene Seite, aktiv auch auf allem, was von dort aus erreicht wird -->
                <Link
                    :href="route('more')"
                    :aria-current="moreActive ? 'page' : undefined"
                    class="flex h-full min-w-0 flex-1 flex-col items-center justify-center gap-0.5 transition-colors"
                    :class="moreActive ? 'text-accent' : 'text-ink-3'"
                >
                    <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" :stroke-width="moreActive ? 2.1 : 1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span class="text-[10px] font-semibold leading-none">Mehr</span>
                </Link>
            </div>
        </nav>


    </div>
</template>
