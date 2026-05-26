<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';
import { useCoachChat } from '@/Composables/useCoachChat';
import { useVersionCheck } from '@/Composables/useVersionCheck';
import UserAvatar from '@/Components/UserAvatar.vue';
import CoachSlideOver from '@/Components/CoachSlideOver.vue';

const { updateReady, startVersionPolling, reload } = useVersionCheck();
onMounted(() => startVersionPolling());

const page = usePage();
const user       = computed(() => page.props.auth.user);
const isAdmin    = computed(() => page.props.auth.isAdmin);
const activePlan = computed(() => page.props.activePlan);
const coach      = computed(() => page.props.coach);
const { isDark, toggle } = useDarkMode();
const { open: openChat } = useCoachChat();

const navItems = [
    {
        label: 'Dashboard',
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
        label: 'Profil',
        routeName: 'profile.edit',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />`,
    },
];

// Mobile bottom bar: max 5 tabs so each touch target stays ≥ 44 px wide.
// When a plan is active it replaces "Statistiken" to keep the most relevant items.
const mobileNavItems = computed(() => {
    const base = activePlan.value
        ? [
            navItems[0], // Dashboard
            navItems[1], // Aktivitäten
            { label: 'Plan', routeName: 'events.plan.show', planId: activePlan.value.event_id,
              icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />` },
            navItems[4], // Kalender
            navItems[6], // Profil
          ]
        : [
            navItems[0], // Dashboard
            navItems[1], // Aktivitäten
            navItems[4], // Kalender
            navItems[2], // Statistiken
            navItems[6], // Profil
          ];
    return base;
});
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-slate-950">

        <!-- ══════════════════════════════════════
             UPDATE BANNER
             ══════════════════════════════════════ -->
        <div
            v-if="updateReady"
            class="fixed top-0 inset-x-0 z-50 flex items-center justify-between gap-3 px-4 py-2.5 bg-indigo-600 text-white text-sm shadow-lg"
        >
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
                <span class="font-medium">Neue Version verfügbar</span>
            </div>
            <button
                @click="reload"
                class="shrink-0 rounded-lg bg-white/20 hover:bg-white/30 px-3 py-1 text-xs font-semibold transition-colors"
            >
                Jetzt aktualisieren
            </button>
        </div>

        <!-- ══════════════════════════════════════
             DESKTOP SIDEBAR (hidden on mobile)
             ══════════════════════════════════════ -->
        <aside class="hidden lg:flex lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:w-64 flex-col bg-white dark:bg-slate-900 border-r border-gray-100 dark:border-slate-800">
            <!-- Logo -->
            <div class="flex h-16 shrink-0 items-center gap-3 px-6 border-b border-gray-100 dark:border-slate-800">
                <div class="h-8 w-8 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-sm">
                    <span class="text-white text-sm font-bold">Z3</span>
                </div>
                <span class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Zone3</span>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto px-3 py-5 space-y-0.5">
                <p class="px-3 mb-3 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Navigation</p>
                <Link
                    v-for="item in navItems"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150"
                    :class="route().current(item.routeName)
                        ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400'
                        : 'text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                    {{ item.label }}
                    <span v-if="route().current(item.routeName)" class="ml-auto h-1.5 w-1.5 rounded-full bg-indigo-500 dark:bg-indigo-400" />
                </Link>

                <!-- Active plan link -->
                <div v-if="activePlan" class="mt-4 pt-4 border-t border-gray-100 dark:border-slate-800">
                    <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Aktiver Plan</p>
                    <Link
                        :href="route('events.plan.show', activePlan.event_id)"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150"
                        :class="route().current('events.plan.show')
                            ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400'
                            : 'text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-900 dark:hover:text-white'"
                    >
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                        </svg>
                        <span class="truncate">{{ activePlan.event_name }}</span>
                        <span v-if="route().current('events.plan.show')" class="ml-auto h-1.5 w-1.5 rounded-full bg-indigo-500 dark:bg-indigo-400 shrink-0" />
                    </Link>
                </div>

                <!-- Admin link -->
                <div v-if="isAdmin" class="mt-4 pt-4 border-t border-gray-100 dark:border-slate-800">
                    <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">System</p>
                    <Link
                        :href="route('admin.dashboard')"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10"
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
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10"
                >
                    <div
                        class="h-5 w-5 rounded-full flex items-center justify-center text-white text-[8px] font-bold shrink-0"
                        :style="`background-color: ${coach.avatar_color}`"
                    >
                        {{ coach.avatar_initials }}
                    </div>
                    Chat mit {{ coach.name }}
                    <span class="ml-auto h-2 w-2 rounded-full bg-green-400 shrink-0" title="Online" />
                </button>
            </div>

            <!-- User + Dark toggle -->
            <div class="shrink-0 border-t border-gray-100 dark:border-slate-800 p-4 space-y-1">
                <button
                    @click="toggle"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-900 dark:hover:text-white transition-colors"
                >
                    <svg v-if="isDark" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg v-else class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                    {{ isDark ? 'Helles Design' : 'Dunkles Design' }}
                </button>
                <div class="flex items-center gap-3 px-2 py-2">
                    <UserAvatar :user="user" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ user.name }}</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 truncate">{{ user.email }}</p>
                    </div>
                </div>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm text-gray-500 dark:text-slate-400 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                    Abmelden
                </Link>
            </div>
        </aside>

        <!-- ══════════════════════════════════════
             MOBILE TOP BAR (logo + dark toggle)
             ══════════════════════════════════════ -->
        <header class="lg:hidden fixed top-0 inset-x-0 z-20 h-mobile-header pt-safe flex items-end justify-between bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800 px-4 pb-2">
            <div class="flex items-center gap-2.5">
                <div class="h-7 w-7 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-sm">
                    <span class="text-white text-xs font-bold">Z3</span>
                </div>
                <span class="text-base font-bold text-gray-900 dark:text-white tracking-tight">Zone3</span>
            </div>
            <div class="flex items-center gap-1">
                <!-- Coach chat button (mobile) -->
                <button
                    v-if="coach"
                    @click="openChat"
                    class="h-9 w-9 flex items-center justify-center rounded-xl text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-colors relative"
                >
                    <div
                        class="h-6 w-6 rounded-full flex items-center justify-center text-white text-[8px] font-bold"
                        :style="`background-color: ${coach.avatar_color}`"
                    >
                        {{ coach.avatar_initials }}
                    </div>
                    <span class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-green-400" />
                </button>

                <!-- Dark mode toggle -->
                <button
                    @click="toggle"
                    class="h-9 w-9 flex items-center justify-center rounded-xl text-gray-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors"
                >
                    <svg v-if="isDark" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg v-else class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                </button>
                <!-- Abmelden -->
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="h-9 w-9 flex items-center justify-center rounded-xl text-gray-500 dark:text-slate-400 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                </Link>
            </div>
        </header>

        <!-- ══════════════════════════════════════
             MAIN CONTENT
             ══════════════════════════════════════ -->
        <div class="lg:pl-64 flex flex-col min-h-screen">
            <!-- Desktop page header slot -->
            <header v-if="$slots.header" class="hidden lg:block bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800">
                <div class="px-6 py-4">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page content — top padding for mobile header (safe area aware), bottom for tab bar -->
            <main class="flex-1 pt-mobile-header pb-mobile-tabbar lg:pt-0 lg:pb-0">
                <slot />
            </main>
        </div>

        <!-- Coach Chat Slide-Over -->
        <CoachSlideOver />

        <!-- ══════════════════════════════════════
             MOBILE BOTTOM TAB BAR (max 5 items)
             ══════════════════════════════════════ -->
        <nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white dark:bg-slate-900 border-t border-gray-100 dark:border-slate-800 pb-safe-tabbar">
            <div class="flex items-center h-16">
                <Link
                    v-for="item in mobileNavItems"
                    :key="item.routeName"
                    :href="item.planId ? route(item.routeName, item.planId) : route(item.routeName)"
                    class="flex-1 flex flex-col items-center justify-center gap-1 h-full min-w-0 transition-colors relative"
                    :class="(item.planId ? route().current(item.routeName) : route().current(item.routeName))
                        ? 'text-indigo-600 dark:text-indigo-400'
                        : 'text-gray-400 dark:text-slate-500'"
                >
                    <span
                        v-if="item.planId ? route().current(item.routeName) : route().current(item.routeName)"
                        class="absolute top-0 left-1/2 -translate-x-1/2 h-0.5 w-8 bg-indigo-500 dark:bg-indigo-400 rounded-b-full"
                    />
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                    <span class="text-[10px] font-medium leading-none truncate max-w-full px-0.5">{{ item.label }}</span>
                </Link>
            </div>
        </nav>

    </div>
</template>
